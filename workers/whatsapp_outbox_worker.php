<?php
declare(strict_types=1);

/**
 * Envia as mensagens da fila do WhatsApp (tabela whatsapp_outbox).
 *
 * SEM VERIFICAÇÃO PERIÓDICA: este script não fica rodando à toa.
 *  - Quando o sistema coloca uma mensagem na fila, cria um aviso em
 *    /var/lib/nexa-whatsapp/sinais/; o systemd (nexa-whatsapp-outbox.path)
 *    percebe na hora e inicia este script. Se sobrar aviso quando o script
 *    terminar, o systemd o inicia de novo (nenhuma mensagem fica esquecida).
 *  - O script envia o que houver. Se sobrar mensagem para tentar de novo
 *    (1, 2, 5 e 15 min), ele dorme até a hora exata da próxima tentativa
 *    (dormir não consome processamento) e acorda antes se chegar mensagem nova.
 *  - Fila vazia: o script termina. Nada fica rodando.
 *
 * Regras: até 5 tentativas por mensagem; mais de 6 horas sem conseguir enviar
 * = descartada (FALHOU), para o cliente não receber aviso velho.
 *
 * Uso manual: php workers/whatsapp_outbox_worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/whatsapp_service.php';

$lock = fopen(sys_get_temp_dir() . '/nexa_whatsapp_outbox.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!whatsapp_outbox_ready($pdo)) {
    fwrite(STDERR, "Fila do WhatsApp não instalada (aplique 'WhatsApp - fila de envio' no Atualizador do Banco).\n");
    exit(0);
}

const WA_OUTBOX_MAX_ATTEMPTS = 5;
const WA_OUTBOX_MAX_AGE_HOURS = 6;
const WA_OUTBOX_BACKOFF_MIN = [1 => 1, 2 => 2, 3 => 5, 4 => 15];
const WA_OUTBOX_MAX_RUNTIME = 7 * 3600;

/** Envia as mensagens vencidas. Retorna segundos até a próxima tentativa (null = fila vazia). */
function wa_outbox_cycle(PDO $pdo): ?int {
    // Descarta mensagens velhas demais e recupera as presas em ENVIANDO.
    $pdo->exec("UPDATE whatsapp_outbox SET status='FALHOU', last_error=COALESCE(last_error,'Expirou sem conseguir enviar.') WHERE status='PENDENTE' AND created_at < (NOW() - INTERVAL " . (int)WA_OUTBOX_MAX_AGE_HOURS . " HOUR)");
    $pdo->exec("UPDATE whatsapp_outbox SET status='PENDENTE' WHERE status='ENVIANDO' AND next_attempt_at < (NOW() - INTERVAL 10 MINUTE)");

    $cfgCache = [];
    while (true) {
        $rows = $pdo->query("SELECT id, company_id, pedido_id, tipo, destino, mensagem, attempts FROM whatsapp_outbox WHERE status='PENDENTE' AND next_attempt_at <= NOW() ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) break;
        foreach ($rows as $r) {
            $cid = (int)$r['company_id'];
            $cfg = $cfgCache[$cid] ?? ($cfgCache[$cid] = whatsapp_load_config($pdo, $cid));
            $cfg['timeout_seconds'] = 25;
            $breaker = whatsapp_breaker_status($cfg);
            if ($breaker['open']) return max(5, (int)$breaker['seconds_left']); // WhatsApp com problemas: espera a pausa.

            $claim = $pdo->prepare("UPDATE whatsapp_outbox SET status='ENVIANDO', attempts=attempts+1, next_attempt_at=NOW() WHERE id=? AND status='PENDENTE'");
            $claim->execute([(int)$r['id']]);
            if ($claim->rowCount() !== 1) continue;
            $attempt = (int)$r['attempts'] + 1;

            if (whatsapp_send_message($pdo, $cid, (string)$r['destino'], (string)$r['mensagem'], (string)$r['tipo'], (int)$r['pedido_id'] ?: null, $cfg)) {
                $pdo->prepare("UPDATE whatsapp_outbox SET status='ENVIADO', sent_at=NOW(), last_error=NULL WHERE id=?")->execute([(int)$r['id']]);
                continue;
            }
            $err = 'Falha no envio (ver Logs do WhatsApp).';
            try {
                $st = $pdo->prepare("SELECT erro FROM whatsapp_logs WHERE company_id=? AND destino=? ORDER BY id DESC LIMIT 1");
                $st->execute([$cid, (string)$r['destino']]);
                $err = (string)($st->fetchColumn() ?: $err);
            } catch (Throwable $e) {}
            if ($attempt >= WA_OUTBOX_MAX_ATTEMPTS || stripos($err, 'desativada') !== false || stripos($err, 'vazia') !== false) {
                $pdo->prepare("UPDATE whatsapp_outbox SET status='FALHOU', last_error=? WHERE id=?")->execute([mb_substr($err, 0, 255), (int)$r['id']]);
            } else {
                $wait = WA_OUTBOX_BACKOFF_MIN[$attempt] ?? 15;
                $pdo->prepare("UPDATE whatsapp_outbox SET status='PENDENTE', last_error=?, next_attempt_at=(NOW() + INTERVAL " . (int)$wait . " MINUTE) WHERE id=?")->execute([mb_substr($err, 0, 255), (int)$r['id']]);
            }
        }
    }
    $next = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(next_attempt_at)) FROM whatsapp_outbox WHERE status='PENDENTE'")->fetchColumn();
    return $next === null || $next === false ? null : max(1, (int)$next);
}

/** Apaga os avisos já vistos (as mensagens deles serão processadas agora). */
function wa_outbox_take_signals(): void {
    foreach (glob(whatsapp_outbox_signal_dir() . '/*.sinal') ?: [] as $f) @unlink($f);
}
function wa_outbox_has_signal(): bool {
    return (bool)(glob(whatsapp_outbox_signal_dir() . '/*.sinal') ?: []);
}

$startedAt = time();
try {
    while (time() - $startedAt < WA_OUTBOX_MAX_RUNTIME) {
        wa_outbox_take_signals();
        $wait = wa_outbox_cycle($pdo);
        if ($wait === null) {
            // Fila vazia: termina. Aviso que chegar depois daqui faz o systemd
            // iniciar o script de novo.
            if (!wa_outbox_has_signal()) break;
            continue;
        }
        // Dorme até a próxima tentativa; acorda antes se chegar mensagem nova
        // (só olha a pasta de avisos, sem consultar banco nem rede).
        $until = time() + min($wait, 900);
        while (time() < $until && !wa_outbox_has_signal()) sleep(1);
    }
    // Limpeza ocasional: guarda 30 dias de histórico da fila.
    if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM whatsapp_outbox WHERE status IN ('ENVIADO','FALHOU') AND created_at < (NOW() - INTERVAL 30 DAY)");
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . "\n");
    exit(1);
}
