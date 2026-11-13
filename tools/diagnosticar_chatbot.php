<?php
declare(strict_types=1);

/**
 * Diagnóstico do chatbot/atendimento do WhatsApp de uma empresa.
 * Uso (VPS): php /var/www/nexa/tools/diagnosticar_chatbot.php [id_da_empresa]
 * Sem id, mostra as empresas com o módulo liberado e a conversa mais recente.
 * Só lê dados; não altera nada.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Execute somente pelo terminal.\n"); }

$root = dirname(__DIR__);
require_once $root . '/config/pdo.php';
require_once $root . '/config/helpers.php';
require_once $root . '/config/whatsapp_chatbot.php';
app_apply_company_timezone($pdo, 'America/Sao_Paulo');

$line = static function (string $t = ''): void { echo $t . "\n"; };
$cut = static fn($v, int $n = 90): string => mb_strimwidth(preg_replace('/\s+/', ' ', (string)$v) ?? '', 0, $n, '…');

$line('== Diagnóstico do chatbot WhatsApp (' . date('d/m/Y H:i:s') . ') ==');
$line('Tabelas do chatbot/atendimento: ' . (whatsapp_bot_ready($pdo) ? 'OK' : 'FALTANDO (aplique as migrações WhatsApp no Atualizador do Banco)'));
$line('Tabelas de fluxos: ' . (whatsapp_bot_flows_ready($pdo) ? 'OK' : 'faltando (opcional)'));
$engine = whatsapp_engine_config($pdo);
$line('Motor configurado: ' . (whatsapp_engine_ready($engine) ? 'sim (' . ($engine['url'] ?? '') . ')' : 'NÃO'));
try {
  $health = whatsapp_engine_request($engine, 'GET', '/health', [], 6);
  $line('Motor respondendo: versão ' . ($health['service_version'] ?? '?'));
} catch (Throwable $e) {
  $line('Motor respondendo: NÃO (' . $e->getMessage() . ')');
}
if (!whatsapp_bot_ready($pdo)) exit(1);

$cid = (int)($argv[1] ?? 0);
if ($cid <= 0) {
  $st = $pdo->query('SELECT company_id, MAX(last_message_at) AS ult FROM whatsapp_bot_sessions GROUP BY company_id ORDER BY ult DESC LIMIT 1');
  $cid = (int)($st->fetchColumn() ?: 0);
  if ($cid <= 0) { $line('Nenhuma conversa registrada ainda. Informe o id da empresa: php tools/diagnosticar_chatbot.php ID'); exit(0); }
}
$line();
$line('--- Empresa #' . $cid . ' (' . whatsapp_bot_company_name($pdo, $cid) . ') ---');
$line('Módulo Chatbot liberado no SaaS: ' . (whatsapp_bot_module_enabled($pdo, $cid) ? 'sim' : 'NÃO'));
$cfg = whatsapp_bot_config($pdo, $cid);
$line('Robô ligado: ' . ($cfg['enabled'] ? 'sim' : 'NÃO'));
$line('2ª via com o Grupo Empresarial: ' . (!empty($cfg['group_receivables']) ? 'sim (empresas: ' . implode(', ', array_map(static fn($i) => '#' . $i . ' ' . whatsapp_bot_company_name($pdo, (int)$i), whatsapp_bot_group_company_ids($pdo, $cid, $cfg))) . ')' : 'não'));
$wa = whatsapp_load_config($pdo, $cid, false, true);
$sid = whatsapp_service_company_id($wa, $cid);
$line('WhatsApp no motor novo: ' . (whatsapp_engine_is_url($engine, (string)$wa['service_url']) ? 'sim' : 'NÃO (' . $wa['service_url'] . ')') . ' · sessão ' . $sid);
try {
  $sess = whatsapp_engine_request($engine, 'GET', '/admin/sessions', [], 8);
  foreach ((array)($sess['items'] ?? []) as $it) {
    if ((string)($it['company_id'] ?? $it['id'] ?? '') !== (string)$sid) continue;
    $line('Sessão no motor: ' . json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }
} catch (Throwable $e) {
  $line('Sessão no motor: não consultada (' . $e->getMessage() . ')');
}
$line('Opções do menu inicial:');
foreach (whatsapp_bot_nodes($pdo, $cid, null) as $i => $n) {
  $line('  ' . ($i + 1) . ' - ' . $n['label'] . ' [' . $n['action'] . ']');
}

$line();
$line('Conversas mais recentes:');
$st = $pdo->prepare('SELECT * FROM whatsapp_bot_sessions WHERE company_id=? ORDER BY last_message_at DESC, id DESC LIMIT 5');
$st->execute([$cid]);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($sessions as $s) {
  $line(sprintf('  #%d %s (%s) status=%s etapa=%s atendente=%s celular_em=%s ultima=%s dados=%s',
    $s['id'], $s['push_name'] ?: '-', $s['phone'] ?: $s['contact'], $s['status'], $s['state'], $s['user_id'] ?: '-', $s['human_at'] ?: '-', $s['last_message_at'] ?: '-', $cut($s['data_json'] ?? '', 40)));
}
if ($sessions) {
  $s = $sessions[0];
  $line();
  $line('Últimas mensagens da conversa #' . $s['id'] . ':');
  $st = $pdo->prepare('SELECT * FROM (SELECT * FROM whatsapp_bot_messages WHERE company_id=? AND session_id=? ORDER BY id DESC LIMIT 20) t ORDER BY id');
  $st->execute([$cid, (int)$s['id']]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
    $line(sprintf('  %s %-3s %-9s %s%s', substr((string)$m['created_at'], 11, 8), $m['direction'], $m['sender'] ?: '-', ((int)($m['send_ok'] ?? 1) ? '' : '[NÃO ENVIADA] '), $cut($m['body'])));
  }
}
$line();
$line('Erros recentes do webhook (log do Apache):');
foreach (['/var/log/apache2/error.log', '/var/log/php8.3-fpm.log'] as $log) {
  if (!is_readable($log)) continue;
  $lines = @file($log, FILE_IGNORE_NEW_LINES) ?: [];
  $hits = array_slice(array_values(array_filter($lines, static fn($l) => stripos($l, 'whatsapp') !== false)), -8);
  foreach ($hits as $h) $line('  ' . $cut($h, 220));
}
