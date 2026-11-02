<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';

$companyId = 0;
foreach ($argv ?? [] as $arg) if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
try {
  fiscal_nfce_ensure_schema($pdo);
  echo 'NEXA FISCAL v2.6.4 - AUDITORIA DE CONTINGENCIA' . PHP_EOL;
  echo str_repeat('=', 96) . PHP_EOL;
  $where = $companyId > 0 ? 'WHERE c.company_id=' . $companyId : '';
  $sql = "SELECT c.company_id, COALESCE(cp.name, CONCAT('Empresa ',c.company_id)) AS company_name,
                 c.environment,c.contingency_enabled,c.nfce_offline_contingency_enabled,c.nfe_svc_contingency_enabled,
                 c.nfe_svc_type,c.contingency_login_alert_enabled,c.contingency_status_check_enabled,
                 SUM(CASE WHEN d.model='65' AND UPPER(d.status) IN ('CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE') THEN 1 ELSE 0 END) AS pending_nfce,
                 SUM(CASE WHEN UPPER(d.status)='PENDENTE_RETORNO' THEN 1 ELSE 0 END) AS pending_return
          FROM fiscal_company_config c
          LEFT JOIN companies cp ON cp.id=c.company_id
          LEFT JOIN fiscal_documents d ON d.company_id=c.company_id AND d.direction='SAIDA'
          $where
          GROUP BY c.company_id,cp.name,c.environment,c.contingency_enabled,c.nfce_offline_contingency_enabled,c.nfe_svc_contingency_enabled,c.nfe_svc_type,c.contingency_login_alert_enabled,c.contingency_status_check_enabled
          ORDER BY c.company_id";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  printf("%-6s %-28s %-11s %-6s %-8s %-8s %-8s %-7s %-7s %-8s %-8s\n", 'ID','Empresa','Ambiente','Geral','NFC-off','NF-SVC','SVC','Login','Status','Pend.NFC','Retorno');
  foreach ($rows as $r) {
    printf("%-6d %-28s %-11s %-6s %-8s %-8s %-8s %-7s %-7s %-8d %-8d\n",
      (int)$r['company_id'], substr((string)$r['company_name'],0,28), (string)$r['environment'],
      !empty($r['contingency_enabled'])?'SIM':'NAO', !empty($r['nfce_offline_contingency_enabled'])?'SIM':'NAO',
      !empty($r['nfe_svc_contingency_enabled'])?'SIM':'NAO', (string)$r['nfe_svc_type'],
      !empty($r['contingency_login_alert_enabled'])?'SIM':'NAO', !empty($r['contingency_status_check_enabled'])?'SIM':'NAO',
      (int)$r['pending_nfce'], (int)$r['pending_return']);
  }
  echo str_repeat('-', 96) . PHP_EOL;
  $params = [];
  $whereDoc = "direction='SAIDA' AND (UPPER(status) IN ('CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','PENDENTE_RETORNO'))";
  if ($companyId > 0) { $whereDoc .= ' AND company_id=?'; $params[]=$companyId; }
  $st=$pdo->prepare("SELECT company_id,id,model,series,number,emission_type,status,contingency_started_at,contingency_transmission_attempts,sefaz_status_code,sefaz_status_message FROM fiscal_documents WHERE $whereDoc ORDER BY company_id,issue_datetime,id");
  $st->execute($params);
  $docs=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo 'Documentos pendentes/conciliacao: ' . count($docs) . PHP_EOL;
  foreach ($docs as $d) {
    echo sprintf('Empresa %d | doc %d | mod %s %s/%s | %s | %s | tentativas %d | %s %s',
      (int)$d['company_id'],(int)$d['id'],(string)$d['model'],(string)$d['series'],(string)$d['number'],
      (string)$d['emission_type'],(string)$d['status'],(int)$d['contingency_transmission_attempts'],
      (string)($d['sefaz_status_code'] ?? ''),(string)($d['sefaz_status_message'] ?? '')) . PHP_EOL;
  }
  echo '[OK] Auditoria concluída.' . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
