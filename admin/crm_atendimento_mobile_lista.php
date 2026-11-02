<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_crm.php';

$cid = crm_company_id();
$statuses = crm_statuses($pdo, $cid, true);
$users = crm_users($pdo, $cid, true);

$q = trim((string)($_GET['q'] ?? ''));
$state = strtoupper(trim((string)($_GET['state'] ?? '')));
if (!in_array($state, ['', 'PENDENTE', 'CONCLUIDO'], true)) $state = '';
$statusId = max(0, (int)($_GET['status_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$nextFrom = trim((string)($_GET['next_from'] ?? ''));
$nextTo = trim((string)($_GET['next_to'] ?? ''));
$responsibleId = max(0, (int)($_GET['responsible_user_id'] ?? 0));

$where = ['a.company_id=?'];
$params = [$cid];
if ($q !== '') {
  foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $token) {
    $like = nexa_search_like($token);
    $where[] = '(a.customer_name LIKE ? OR a.phone LIKE ? OR a.document LIKE ? OR a.email LIKE ? OR a.address LIKE ? OR a.request_text LIKE ? OR a.pending_reason LIKE ?)';
    for ($i = 0; $i < 7; $i++) $params[] = $like;
  }
}
if ($state !== '') { $where[] = 'a.state=?'; $params[] = $state; }
if ($statusId > 0) { $where[] = 'a.status_id=?'; $params[] = $statusId; }
if ($dateFrom !== '') { $where[] = 'DATE(a.created_at)>=?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(a.created_at)<=?'; $params[] = $dateTo; }
if ($nextFrom !== '') { $where[] = 'a.next_contact_date>=?'; $params[] = $nextFrom; }
if ($nextTo !== '') { $where[] = 'a.next_contact_date<=?'; $params[] = $nextTo; }
if ($responsibleId > 0) { $where[] = 'a.responsible_user_id=?'; $params[] = $responsibleId; }

$sql = "SELECT a.*,s.name status_name,s.color status_color,t.name service_type_name,u.name responsible_name
        FROM crm_attendances a
        LEFT JOIN crm_statuses s ON s.company_id=a.company_id AND s.id=a.status_id
        LEFT JOIN crm_service_types t ON t.company_id=a.company_id AND t.id=a.service_type_id
        LEFT JOIN users u ON u.company_id=a.company_id AND u.id=a.responsible_user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY CASE WHEN a.state='PENDENTE' THEN 0 ELSE 1 END,
                 CASE WHEN a.next_contact_date IS NULL THEN 1 ELSE 0 END,
                 a.next_contact_date ASC,a.updated_at DESC,a.id DESC LIMIT 300";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$backParams = [];
foreach (['q','state','status_id','date_from','date_to','next_from','next_to','responsible_user_id'] as $key) {
  if (!isset($_GET[$key])) continue;
  $val = trim((string)$_GET[$key]);
  if ($val !== '' && $val !== '0') $backParams[$key] = $val;
}
$filterBackUrl = 'crm_atendimento_mobile.php' . ($backParams ? ('?' . http_build_query($backParams)) : '');
$currentListUrl = 'crm_atendimento_mobile_lista.php' . ($backParams ? ('?' . http_build_query($backParams)) : '');
$returnToken = rawurlencode($currentListUrl);

crm_page_start('CRM - Atendimentos Mobile', 'crm_atendimento_mobile');
?>
<style>
.crml-shell{max-width:980px;margin:0 auto;padding-bottom:92px}.crml-head{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:8px;margin-bottom:10px}.crml-head>.crm-btn{min-height:46px;padding:11px 15px;font-size:12px;display:inline-flex;align-items:center;justify-content:center}.crml-title{text-align:center;min-width:0}.crml-title strong{display:block;font-size:18px}.crml-title small{display:block;color:#64748b;font-size:10px;font-weight:800;margin-top:1px}.crml-list{display:grid;gap:8px}.crml-card{display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:10px;align-items:center;background:#fff;border:1px solid #dbe3ed;border-radius:12px;padding:9px 10px;box-shadow:0 3px 10px rgba(15,23,42,.03);cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease}.crml-card:hover,.crml-card:focus{border-color:#93b4df;box-shadow:0 8px 20px rgba(15,23,42,.08);outline:none}.crml-card:active{transform:scale(.997)}.crml-date{text-align:center;border-right:1px solid #e8eef5;padding-right:8px}.crml-date strong{display:block;font-size:14px}.crml-date small{display:block;color:#64748b;font-size:10px;font-weight:800}.crml-main{min-width:0}.crml-name{font-size:13px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crml-line{font-size:10px;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crml-request{font-size:11px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crml-side{min-width:150px;text-align:right;display:grid;gap:5px;justify-items:end}.crml-actions{display:flex;gap:5px}.crml-actions .crm-btn{padding:7px 12px;font-size:10px;border-radius:8px;min-width:108px;justify-content:center}.crml-empty{padding:28px 14px;text-align:center;color:#64748b;background:#fff;border:1px dashed #cbd5e1;border-radius:12px}.crml-fab{position:fixed;right:22px;bottom:22px;z-index:1100;border:0;border-radius:999px;background:#1769aa;color:#fff;text-decoration:none;padding:14px 18px;font-weight:900;box-shadow:0 12px 30px rgba(23,105,170,.32);display:inline-flex;align-items:center;gap:7px}.crml-saved{margin-bottom:10px}
@media(max-width:720px){.crm-wrap{padding:8px}.crml-shell{padding-bottom:88px}.crml-head{position:sticky;top:0;z-index:25;background:#f5f8fc;padding:4px 0 8px}.crml-title strong{font-size:16px}.crml-card{grid-template-columns:60px minmax(0,1fr);padding:8px}.crml-side{grid-column:2;min-width:0;display:flex;align-items:center;gap:5px;justify-content:flex-start;text-align:left;overflow:auto}.crml-actions{margin-left:auto;flex:0 0 auto}.crml-date strong{font-size:13px}.crml-fab{right:14px;bottom:14px;padding:13px 16px}}
</style>
<div class="crml-shell">
  <div class="crml-head">
    <a class="crm-btn sm" href="<?=crm_e($filterBackUrl)?>">← Voltar</a>
    <div class="crml-title"><strong>Atendimentos</strong><small><?=number_format(count($rows),0,',','.')?> encontrado(s)</small></div>
    <a class="crm-btn primary sm" href="crm_atendimento_mobile.php?new=1">＋ Novo</a>
  </div>

  <?php if (isset($_GET['saved'])): ?><div class="crm-alert ok crml-saved">Atendimento salvo com sucesso.</div><?php endif; ?>

  <?php if (!$rows): ?>
    <div class="crml-empty">Nenhum atendimento encontrado para os filtros informados.</div>
  <?php else: ?>
    <div class="crml-list">
      <?php foreach($rows as $r):
        $createdTs = strtotime((string)$r['created_at']);
        $color = crm_clean_color((string)($r['status_color'] ?? '#2563EB'));
        $name = trim((string)($r['customer_name'] ?? '')) ?: 'Contato sem nome';
      ?>
      <article class="crml-card" role="link" tabindex="0" data-detail-href="crm_atendimento.php?id=<?=(int)$r['id']?>&amp;view=1&amp;from=mobile&amp;return_to=<?=$returnToken?>">
        <div class="crml-date"><strong><?=crm_e($createdTs?date('d/m',$createdTs):'—')?></strong><small><?=crm_e($createdTs?date('H:i',$createdTs):'')?></small></div>
        <div class="crml-main">
          <div class="crml-name"><?=crm_e($name)?></div>
          <div class="crml-line"><?=crm_e(trim((string)($r['phone'] ?? '')) ?: 'Sem telefone')?> · Próx.: <strong><?=crm_e(crm_date($r['next_contact_date']))?></strong></div>
          <div class="crml-line">Resp.: <?=crm_e(trim((string)($r['responsible_name'] ?? '')) ?: '—')?><?php if(trim((string)($r['service_type_name'] ?? ''))!==''):?> · <?=crm_e($r['service_type_name'])?><?php endif;?></div>
          <div class="crml-request"><?=crm_e((string)$r['request_text'])?></div>
        </div>
        <div class="crml-side">
          <?=crm_temperature_badge($r['lead_temperature']??20)?>
          <span class="crm-badge" style="background:<?=crm_e($color)?>18;color:<?=crm_e($color)?>;border:1px solid <?=crm_e($color)?>55"><?=crm_e(trim((string)($r['status_name'] ?? '')) ?: crm_state_label((string)$r['state']))?></span>
          <span class="crm-badge <?=crm_e(crm_state_class((string)$r['state']))?>"><?=crm_e(crm_state_label((string)$r['state']))?></span>
          <div class="crml-actions"><a class="crm-btn primary" href="crm_tramitar.php?id=<?=(int)$r['id']?>&amp;from=mobile&amp;return_to=<?=$returnToken?>">Tramitar</a></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <a class="crml-fab" href="crm_atendimento_mobile.php?new=1">＋ Novo atendimento</a>
</div>
<script>
(()=>{document.querySelectorAll('.crml-card[data-detail-href]').forEach(card=>{const go=()=>{const href=card.getAttribute('data-detail-href');if(href)window.location.href=href;};card.addEventListener('click',e=>{if(e.target.closest('a,button,input,select,textarea,label,form'))return;go();});card.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&!e.target.closest('a,button,input,select,textarea')){e.preventDefault();go();}});});})();
</script>
<?php crm_page_end(); ?>
