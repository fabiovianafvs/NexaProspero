<?php
declare(strict_types=1);
// Operação protegida: cron local da VPS, nunca por URL pública.
if (PHP_SAPI!=='cli') { http_response_code(403);exit('Execução somente CLI.'); }
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/saas_trial_cycle.php';
require_once __DIR__ . '/../config/saas_renewal_portal.php';
require_once __DIR__ . '/log_helper.php';
$summary=['checked'=>0,'notices'=>0,'prepared'=>0,'existing'=>0,'renewed'=>0,'blocked'=>0,'reactivated'=>0,'failures'=>0];
// Evita execução simultânea (ex.: cron e execução administrativa).
$st=$pdo->query("SELECT GET_LOCK('nexa_saas_trial_billing_cron',0)");
if ((int)$st->fetchColumn()!==1) {fwrite(STDERR,"Outro ciclo em andamento\n");exit(1);}
try {
  $sql="SELECT c.id,c.name,c.status AS company_status,s.status AS sub_status,s.ends_at,s.id AS sub_id
        FROM companies c JOIN subscriptions s ON s.company_id=c.id
        WHERE s.id=(SELECT MAX(si.id) FROM subscriptions si WHERE si.company_id=c.id) ORDER BY c.id";
  foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id=(int)$r['id']; $summary['checked']++;
    $status=strtolower((string)$r['sub_status']);
    $companyStatus=strtolower((string)$r['company_status']);
    if ($companyStatus==='suspended'||in_array($status,['canceled','cancelled','pending_payment'],true)) continue;
    try {
      $cycle=nexa_saas_cycle_state(['status'=>$status,'ends_at'=>$r['ends_at']]);
      if (in_array($cycle['phase'],['warning','last_day','grace'],true)) $summary['notices']++;
      // Retry de cadastro que falhou por instabilidade: nunca duplica contrato se há vínculo.
      if ($status==='trial' && saas_sub_bridge_enabled($pdo) && !saas_sub_bridge_link($pdo,$id)) {
        $sync=saas_sub_bridge_sync_signup_safe($pdo,$id);
        if (empty($sync['ok'])) throw new RuntimeException('Sincronização cadastro: '.($sync['message']??'não configurado'));
      }
      // A baixa bancária só renova a assinatura se título e liquidação forem confirmados.
      if (saas_renewal_reconcile($pdo,$id)) { $summary['renewed']++;continue; }
      if (in_array($cycle['phase'],['warning','last_day','grace','blocked'],true)
          && saas_sub_bridge_enabled($pdo)) {
        $ctx=saas_renewal_context($pdo,$id);
        if (!empty($ctx['eligible'])) {
          $due=(string)($ctx['due_date']??'');
          if ($due!=='' && date('Y-m-d')>=(new DateTimeImmutable((string)($cycle['due_date']?:$due)))->modify('-3 days')->format('Y-m-d')) {
            $g=saas_renewal_generate($pdo,$id,null);
            $summary[!empty($g['existing'])?'existing':'prepared']++;
          }
        } elseif($status==='trial') throw new RuntimeException('Integração pendente: '.($ctx['error']??''));
      }
      if ($companyStatus==='active' && !empty($cycle['blocked'])) {
        $pdo->prepare("UPDATE companies SET status='blocked' WHERE id=? AND status='active'")->execute([$id]);
        $summary['blocked']++;
      }
      // Não desativa bloqueios manuais desconhecidos; empresa é liberada somente
      // via quitação confirmada pela rotina de renovação.
    } catch(Throwable $e) {
      $summary['failures']++;
      error_log('[Nexa SaaS cron][empresa '.$id.'] '.$e->getMessage());
    }
  }
}finally{$pdo->query("SELECT RELEASE_LOCK('nexa_saas_trial_billing_cron')");}
foreach($summary as $k=>$v)echo strtoupper($k).': '.$v.PHP_EOL;
