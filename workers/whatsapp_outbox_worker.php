<?php
declare(strict_types=1);

/**
 * Envia as mensagens da fila do WhatsApp (tabela whatsapp_outbox).
 *
 * Roda separado do site (serviço systemd nexa-whatsapp-outbox), então um
 * WhatsApp travado só atrasa as mensagens; nunca trava vendas, pedidos ou notas.
 *
 * Regras:
 *  - até 5 tentativas por mensagem: 1, 2, 5 e 15 minutos de intervalo;
 *  - mensagem com mais de 6 horas sem conseguir enviar é descartada (FALHOU),
 *    para o cliente não receber avisos velhos;
 *  - com o disjuntor aberto (WhatsApp com problemas), espera sem gastar tentativas.
 *
 * Uso:
 *   php workers/whatsapp_outbox_worker.php          # processa a fila uma vez
 *   php workers/whatsapp_outbox_worker.php --loop   # fica rodando (systemd)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/whatsapp_service.php';

$loop = in_array('--loop', $argv, true);
$lock = fopen(sys_get_temp_dir() . '/nexa_whatsapp_outbox.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!whatsapp_outbox_ready($pdo)) {
    fwrite(STDERR, "Fila do WhatsApp não instalada (aplique 'WhatsApp - fila de envio' no Atualizador do Banco).\n");
    if ($loop) sleep(300);
    exit(0);
}

const WA_OUTBOX_MAX_ATTEMPTS = 5;
const WA_OUTBOX_MAX_AGE_HOURS = 6;
const WA_OUTBOX_BACKOFF_MIN = [1 => 1, 2 => 2, 3 => 5, 4 => 15];

function wa_outbox_cycle(PDO $pdo): int {
    // Descarta mensagens velhas demais.
    $pdo->prepare("UPDATE whatsapp_outbox SET status='FALHOU', last_error=COALESCE(last_error,'Expirou sem conseguir enviar.') WHERE status='PENDENTE' AND created_at < (NOW() - INTERVAL " . (int)WA_OUTBOX_MAX_AGE_HOURS . " HOUR)")->execute();
    // Recupera mensagens presas em ENVIANDO (processo interrompido no meio).
    $pdo->prepare("UPDATE whatsapp_outbox SET status='PENDENTE' WHERE status='ENVIANDO' AND next_attempt_at < (NOW() - INTERVAL 10 MINUTE)")->execute();

    $rows = $pdo->query("SELECT id, company_id, pedido_id, tipo, destino, mensagem, attempts FROM whatsapp_outbox WHERE status='PENDENTE' AND next_attempt_at <= NOW() ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $done = 0;
    $cfgCache = [];
    foreach ($rows as $r) {
        $cid = (int)$r['company_id'];
        $cfg = $cfgCache[$cid] ?? ($cfgCache[$cid] = whatsapp_load_config($pdo, $cid));
        $cfg['timeout_seconds'] = 25;
        if (whatsapp_breaker_status($cfg)['open']) break; // WhatsApp com problemas: tenta no próximo ciclo.

        $claim = $pdo->prepare("UPDATE whatsapp_outbox SET status='ENVIANDO', attempts=attempts+1, next_attempt_at=NOW() WHERE id=? AND status='PENDENTE'");
        $claim->execute([(int)$r['id']]);
        if ($claim->rowCount() !== 1) continue;
        $attempt = (int)$r['attempts'] + 1;

        $ok = whatsapp_send_message($pdo, $cid, (string)$r['destino'], (string)$r['mensagem'], (string)$r['tipo'], (int)$r['pedido_id'] ?: null, $cfg);
        if ($ok) {
            $pdo->prepare("UPDATE whatsapp_outbox SET status='ENVIADO', sent_at=NOW(), last_error=NULL WHERE id=?")->execute([(int)$r['id']]);
            $done++;
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
    return $done;
}

$startedAt = time();
do {
    try {
        wa_outbox_cycle($pdo);
        // Limpeza: guarda 30 dias de histórico da fila.
        if (random_int(1, 200) === 1) $pdo->exec("DELETE FROM whatsapp_outbox WHERE status IN ('ENVIADO','FALHOU') AND created_at < (NOW() - INTERVAL 30 DAY)");
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . "\n");
        // Erro (ex.: conexão com o banco caiu): sai e o systemd reinicia limpo.
        if ($loop) { sleep(15); exit(1); }
    }
    if (!$loop) break;
    sleep(3);
    // Reinicia a cada 1 hora (o systemd sobe de novo) para liberar memória e
    // pegar código novo após o git pull.
} while (time() - $startedAt < 3600);
