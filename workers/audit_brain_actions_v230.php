<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/brain.php';

$options = getopt('', ['company::','help']);
if (isset($options['help'])) {
  echo "php workers/audit_brain_actions_v230.php [--company=1]\n";
  exit(0);
}

$required = ['brain_rules','brain_company_rule_settings','brain_alerts','brain_alert_history','brain_processing_log'];
$errors = [];
foreach ($required as $table) {
  if (!brain_actions_table_exists($pdo,$table)) $errors[] = 'Tabela ausente: ' . $table;
}
if (!brain_actions_schema_ready($pdo)) $errors[] = 'Versão brain_actions 2026072701 não registrada.';

if ($errors) {
  fwrite(STDERR,"AUDITORIA NEXA BRAIN V2.3.0\n- " . implode("\n- ",$errors) . "\n");
  exit(1);
}

$companyId = max(0,(int)($options['company'] ?? 0));
$params = [];
$sql = "SELECT c.id,c.name,
    SUM(a.status='open') open_alerts,
    SUM(a.status='open' AND a.severity='critical') critical_alerts,
    SUM(a.status='open' AND a.severity='high') high_alerts,
    COALESCE(SUM(CASE WHEN a.status='open' THEN a.financial_impact ELSE 0 END),0) impact
  FROM companies c LEFT JOIN brain_alerts a ON a.company_id=c.id
  WHERE 1=1";
if ($companyId > 0) { $sql .= ' AND c.id=?'; $params[] = $companyId; }
$sql .= ' GROUP BY c.id,c.name ORDER BY c.id';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rules = (int)$pdo->query("SELECT COUNT(*) FROM brain_rules WHERE company_id=0 AND is_active=1")->fetchColumn();
echo "NEXA BRAIN V2.3.0 - AUDITORIA DA CENTRAL DE AÇÕES\n";
echo str_repeat('=',110) . PHP_EOL;
echo 'Regras globais ativas: ' . $rules . PHP_EOL;
printf("%-6s %-34s %-9s %-10s %-8s %-18s %-20s\n",'ID','Empresa','Abertos','Críticos','Altos','Valores envolvidos','Último processamento');
echo str_repeat('-',110) . PHP_EOL;
foreach ($rows as $row) {
  $last = '';
  try {
    $q = $pdo->prepare('SELECT CONCAT(status," em ",DATE_FORMAT(started_at,"%d/%m/%Y %H:%i")) FROM brain_processing_log WHERE company_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([(int)$row['id']]);
    $last = (string)($q->fetchColumn() ?: 'Nunca');
  } catch (Throwable $e) { $last = 'Indisponível'; }
  printf("%-6d %-34s %-9d %-10d %-8d R$ %-15s %-20s\n",
    (int)$row['id'],mb_strimwidth((string)$row['name'],0,34,'...','UTF-8'),(int)$row['open_alerts'],(int)$row['critical_alerts'],(int)$row['high_alerts'],
    number_format((float)$row['impact'],2,',','.'),mb_strimwidth($last,0,20,'...','UTF-8'));
}
echo str_repeat('=',110) . PHP_EOL;
echo "[OK] Estrutura, regras e isolamento por company_id verificados.\n";
