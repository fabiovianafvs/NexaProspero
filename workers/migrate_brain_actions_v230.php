<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/schema_runtime.php';

$options = getopt('', ['no-evaluate','company::','help']);
if (isset($options['help'])) {
  echo "Nexa Brain v2.3.0 - Migração da Central de Ações\n";
  echo "php workers/migrate_brain_actions_v230.php [--no-evaluate] [--company=1]\n";
  exit(0);
}

function brain230_log(string $message): void { echo $message . PHP_EOL; }

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS brain_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL DEFAULT 0,
  rule_code VARCHAR(80) NOT NULL,
  module_code VARCHAR(40) NOT NULL DEFAULT 'brain',
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  default_severity ENUM('info','opportunity','warning','high','critical') NOT NULL DEFAULT 'warning',
  default_settings JSON NOT NULL,
  action_url VARCHAR(255) NULL,
  recommendation TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_brain_rules_company_code (company_id,rule_code),
  KEY idx_brain_rules_active (company_id,is_active,module_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS brain_company_rule_settings (
  company_id INT UNSIGNED NOT NULL,
  rule_code VARCHAR(80) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  severity_override ENUM('info','opportunity','warning','high','critical') NULL,
  settings JSON NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id,rule_code),
  KEY idx_brain_company_rules_enabled (company_id,is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS brain_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  rule_code VARCHAR(80) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  module_code VARCHAR(40) NOT NULL DEFAULT 'brain',
  severity ENUM('info','opportunity','warning','high','critical') NOT NULL DEFAULT 'warning',
  entity_type VARCHAR(60) NOT NULL DEFAULT 'company',
  entity_id BIGINT NULL,
  reference_key VARCHAR(120) NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  recommendation TEXT NULL,
  action_url VARCHAR(255) NULL,
  metric_value DECIMAL(18,4) NULL,
  financial_impact DECIMAL(18,2) NULL,
  metadata JSON NULL,
  status ENUM('open','snoozed','resolved','ignored') NOT NULL DEFAULT 'open',
  first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  snoozed_until DATETIME NULL,
  last_action_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_brain_alert_company_fingerprint (company_id,fingerprint),
  KEY idx_brain_alert_open (company_id,status,severity,last_detected_at),
  KEY idx_brain_alert_rule (company_id,rule_code,status),
  KEY idx_brain_alert_entity (company_id,entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS brain_alert_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id BIGINT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  action_code VARCHAR(30) NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NULL,
  note VARCHAR(500) NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_brain_alert_history_alert (alert_id,created_at),
  KEY idx_brain_alert_history_company (company_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS brain_processing_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  trigger_source VARCHAR(40) NOT NULL DEFAULT 'manual',
  status ENUM('processing','success','warning','error') NOT NULL DEFAULT 'processing',
  rules_evaluated INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_detected INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_created INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_updated INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_resolved INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  details JSON NULL,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_brain_processing_company (company_id,started_at),
  KEY idx_brain_processing_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$rules = [
  ['NEGATIVE_MARGIN_PRODUCT','rentabilidade','Produto com margem negativa','Localiza produtos que acumularam prejuízo bruto nas vendas finalizadas.','critical',['days'=>30,'minimum_loss'=>0.01,'max_alerts'=>50],'pedidos.php','Revise custo, preço, descontos, adicionais e ficha técnica.'],
  ['PRODUCT_WITHOUT_COST','estoque','Produto com estoque e sem custo','Identifica produtos com saldo disponível, mas sem custo de compra ou custo médio.','critical',['minimum_stock'=>0.001,'max_alerts'=>50],'produtos.php','Cadastre o custo antes de usar os indicadores de lucro e valorização do estoque.'],
  ['LOW_STOCK','estoque','Risco de ruptura','Identifica produtos no estoque mínimo ou abaixo dele.','high',['max_alerts'=>50],'produtos.php','Priorize a reposição conforme giro e margem.'],
  ['EXCESS_STOCK','estoque','Estoque acima do máximo','Identifica capital imobilizado acima do estoque máximo configurado.','warning',['max_alerts'=>50],'produtos.php','Reduza a reposição ou crie uma ação comercial sem comprometer a margem.'],
  ['OVERDUE_RECEIVABLES','financeiro','Contas vencidas a receber','Soma os títulos vencidos e ainda em aberto.','high',['minimum_amount'=>0.01],'financeiro_contas_receber.php','Priorize a cobrança dos maiores valores.'],
  ['CASH_PRESSURE_7D','financeiro','Pressão de caixa em 7 dias','Compara pagamentos e recebimentos previstos para os próximos sete dias.','critical',['minimum_gap'=>0.01],'financeiro.php','Revise vencimentos, antecipe recebimentos e controle novas saídas.'],
  ['SALES_DROP_TODAY','vendas','Queda relevante nas vendas','Compara o faturamento reconhecido de hoje com o dia anterior.','warning',['drop_pct'=>15.0,'minimum_reference_sales'=>100.00],'pedidos.php','Compare canais, horários, ticket e produtos líderes.'],
  ['LOW_MARGIN_TODAY','rentabilidade','Margem do dia abaixo da meta','Compara a margem sobre venda do dia com a meta da empresa.','high',['min_margin_pct'=>20.0,'minimum_sales'=>100.00],'pedidos.php','Revise descontos, custos e mix vendido.'],
  ['DELAYED_OPEN_ORDER','delivery','Pedido em aberto acima do tempo','Identifica pedidos operacionais sem conclusão dentro do tempo configurado.','high',['minutes'=>45,'max_alerts'=>30],'pedidos.php','Atualize o status e verifique produção, retirada ou entrega.'],
  ['RADAR_NFE_PENDING','fiscal','NF-e recebida aguardando conferência','Identifica notas de entrada do RADAR ainda não importadas.','warning',['pending_days'=>1,'max_alerts'=>20],'fiscal_radar_xml.php','Confira produtos, vínculos e custos antes de gerar a entrada.'],
];

$seed = $pdo->prepare("INSERT INTO brain_rules
  (company_id,rule_code,module_code,name,description,default_severity,default_settings,action_url,recommendation,is_active,created_at,updated_at)
  VALUES (0,?,?,?,?,?,?,?,?,1,NOW(),NOW())
  ON DUPLICATE KEY UPDATE module_code=VALUES(module_code),name=VALUES(name),description=VALUES(description),default_severity=VALUES(default_severity),default_settings=VALUES(default_settings),action_url=VALUES(action_url),recommendation=VALUES(recommendation),is_active=1,updated_at=NOW()");
foreach ($rules as $rule) {
  [$code,$module,$name,$description,$severity,$settings,$url,$recommendation] = $rule;
  $seed->execute([$code,$module,$name,$description,$severity,json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$url,$recommendation]);
}

nexa_schema_version_mark($pdo,'brain_actions',2026072701,'Nexa Brain v2.3.0 - Central de Ações Inteligentes');
if (!nexa_schema_version_ready($pdo,'brain_actions',2026072701)) {
  throw new RuntimeException('Não foi possível registrar a versão brain_actions 2026072701 em nexa_schema_versions.');
}
brain230_log('[OK] Estrutura da Central de Ações criada/verificada.');
brain230_log('[OK] ' . count($rules) . ' regras globais instaladas/atualizadas.');

if (!isset($options['no-evaluate'])) {
  require_once $root . '/config/brain_actions.php';
  $companyFilter = max(0,(int)($options['company'] ?? 0));
  $params = [];
  $sql = "SELECT id,name FROM companies WHERE COALESCE(status,'active') IN ('active','trial')";
  if ($companyFilter > 0) { $sql .= ' AND id=?'; $params[] = $companyFilter; }
  $sql .= ' ORDER BY id';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $companies = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($companies as $company) {
    $result = brain_actions_evaluate_company($pdo,(int)$company['id'],'migration');
    $line = '[OK] Empresa ' . (int)$company['id'] . ' - ' . (string)$company['name']
      . ': ' . (int)$result['alerts_detected'] . ' detectado(s), '
      . (int)$result['created'] . ' criado(s), ' . (int)$result['resolved'] . ' resolvido(s).';
    if (!empty($result['errors'])) $line .= ' Avisos: ' . implode(' | ',(array)$result['errors']);
    brain230_log($line);
  }
}

brain230_log('[OK] Migração Nexa Brain v2.3.0 concluída.');
