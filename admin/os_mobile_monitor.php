<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_customer_communication.php';
require_once __DIR__ . '/_os_ui.php';

$pdo = os_db();
$companyId = os_company_id();
os_require_enabled($pdo, $companyId);
os_ensure_defaults($pdo, $companyId);

$photoCleanupCount = 0;
try {
    $photoCleanupCount = os_vehicle_damage_cleanup_expired_photos($pdo, $companyId, 48, 250);
    os_vehicle_damage_cleanup_tmp_photos($companyId, 72);
} catch (Throwable $cleanupError) {
    error_log('[Nexa O.S. Mobile] Limpeza de fotos de avarias: ' . $cleanupError->getMessage());
}

$notifyOrderId=max(0,(int)($_GET['notify_status']??0));
$notifyOrder=null;
if($notifyOrderId>0){
    $noticeSt=$pdo->prepare('SELECT o.id,o.os_number,s.name status_name,o.status_id FROM os_orders o JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id WHERE o.company_id=? AND o.id=? LIMIT 1');
    $noticeSt->execute([(int)$companyId,$notifyOrderId]);
    $notice=$noticeSt->fetch(PDO::FETCH_ASSOC);
    if($notice && os_status_notify_enabled($pdo,(int)$companyId,(int)$notice['status_id']))$notifyOrder=$notice;
}
$hasFiltered = isset($_GET['filtrar']) && (string)$_GET['filtrar'] === '1';

$q = trim(isset($_GET['q']) ? (string)$_GET['q'] : '');
$statusId = (int)(isset($_GET['status_id']) ? $_GET['status_id'] : 0);
$billing = trim(isset($_GET['billing_status']) ? (string)$_GET['billing_status'] : '');
$dateFrom = trim(isset($_GET['date_from']) ? (string)$_GET['date_from'] : '');
$dateTo = trim(isset($_GET['date_to']) ? (string)$_GET['date_to'] : '');

$where = array('o.company_id=?');
$params = array((int)$companyId);

if ($statusId > 0) {
    $where[] = 'o.status_id=?';
    $params[] = $statusId;
}
if ($billing !== '') {
    $where[] = 'o.billing_status=?';
    $params[] = $billing;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(o.opened_at)>=?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(o.opened_at)<=?';
    $params[] = $dateTo;
}
if ($q !== '') {
    $tokens = preg_split('/\s+/u', $q);
    foreach ($tokens as $token) {
        if ($token === '') continue;
        $where[] = "(CAST(o.os_number AS CHAR) LIKE ? OR o.person_name_snapshot LIKE ? OR o.person_document_snapshot LIKE ? OR o.contact_name LIKE ? OR o.contact_phone LIKE ? OR o.reported_problem LIKE ? OR EXISTS (SELECT 1 FROM os_order_field_values fv JOIN os_custom_fields cf ON cf.id=fv.field_id AND cf.company_id=fv.company_id WHERE fv.company_id=o.company_id AND fv.order_id=o.id AND (fv.value_text LIKE ? OR cf.label LIKE ?)))";
        for ($i = 0; $i < 8; $i++) $params[] = nexa_search_like($token);
    }
}

$sql = "SELECT o.*,s.name status_name,s.color status_color,
               (SELECT COUNT(*) FROM os_order_items x WHERE x.company_id=o.company_id AND x.order_id=o.id) item_count,
               (SELECT TRIM(fv.value_text) FROM os_order_field_values fv
                INNER JOIN os_custom_fields cf ON cf.id=fv.field_id AND cf.company_id=fv.company_id
                WHERE fv.company_id=o.company_id AND fv.order_id=o.id
                  AND cf.semantic_role='vehicle_plate' AND TRIM(COALESCE(fv.value_text,''))<>''
                ORDER BY cf.sort_order,cf.id LIMIT 1) vehicle_plate
        FROM os_orders o
        LEFT JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.opened_at DESC,o.id DESC
        LIMIT 500";
// Carrega os status antes da consulta, para oferecer "Aberto" como padrão.
$stmt = $pdo->prepare("SELECT id,name,color FROM os_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id");
$stmt->execute(array((int)$companyId));
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Só define o padrão na abertura inicial; respeita escolhas explícitas, inclusive "Todos".
if (!array_key_exists('status_id', $_GET)) {
    foreach ($statuses as $status) {
        if (strtolower(trim((string)$status['name'])) === 'aberto') {
            $statusId = (int)$status['id'];
            break;
        }
    }
}
// Reaplica o filtro na consulta após identificar o status padrão da empresa.
if ($statusId > 0 && !isset($_GET['status_id'])) {
    $where[] = 'o.status_id=?';
    $params[] = $statusId;
    $sql = "SELECT o.*,s.name status_name,s.color status_color,
               (SELECT COUNT(*) FROM os_order_items x WHERE x.company_id=o.company_id AND x.order_id=o.id) item_count,
               (SELECT TRIM(fv.value_text) FROM os_order_field_values fv
                INNER JOIN os_custom_fields cf ON cf.id=fv.field_id AND cf.company_id=fv.company_id
                WHERE fv.company_id=o.company_id AND fv.order_id=o.id
                  AND cf.semantic_role='vehicle_plate' AND TRIM(COALESCE(fv.value_text,''))<>''
                ORDER BY cf.sort_order,cf.id LIMIT 1) vehicle_plate
        FROM os_orders o
        LEFT JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.opened_at DESC,o.id DESC LIMIT 500";
}
$rows = array();
if ($hasFiltered) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sumP = 0.0;
$sumS = 0.0;
$sumG = 0.0;
foreach ($rows as $row) {
    $sumP += (float)$row['total_products'];
    $sumS += (float)$row['total_services'];
    $sumG += (float)$row['total_general'];
}

$billingLabels = array(
    'PENDENTE' => 'Pendente',
    'ENVIADA_PDV' => 'Fluxo anterior / PDV',
    'FATURADA' => 'Faturada',
    'CANCELADA' => 'Cancelada',
);

os_page_start('Monitor Mobile de O.S.', 'mobile');
?>
<style>
.mobile-monitor-shell{max-width:940px;margin:0 auto}.mobile-monitor-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}.mobile-monitor-head h1{margin:0;color:var(--os-primary);font-size:22px}.mobile-monitor-head .spacer{flex:1}.mobile-monitor-head .btn{min-height:40px}.home-short{display:none}.mobile-results-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap}.mobile-results-actions .count{font-size:12px;color:var(--os-muted);font-weight:750}.mobile-filter-card{background:#fff;border:1px solid #b9c5d1;border-radius:14px;padding:14px;margin-bottom:14px;box-shadow:0 4px 14px rgba(15,23,42,.04)}.mobile-filter-title{font-weight:900;color:var(--os-primary);margin-bottom:10px}.mobile-filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}.mobile-filter-grid .span-all{grid-column:1/-1}.mobile-filter-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.mobile-filter-actions .btn{min-width:110px}.mobile-filter-actions .count{font-size:12px;color:var(--os-muted);font-weight:750}.mobile-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}.mobile-summary div{background:#fff;border:1px solid #b9c5d1;border-radius:12px;padding:10px;text-align:center}.mobile-summary span{display:block;font-size:9px;font-weight:850;text-transform:uppercase;color:var(--os-muted)}.mobile-summary b{display:block;margin-top:3px;color:var(--os-primary);font-size:16px}.mobile-list-panel{background:#fff;border:1px solid #b9c5d1;border-radius:14px;overflow:hidden}.mobile-list-title{padding:12px 14px;border-bottom:1px solid #cbd5e1;font-weight:900;color:var(--os-primary);display:flex;justify-content:space-between;gap:10px}.mobile-os-list{display:grid}.mobile-os-card{display:block;position:relative;padding:14px 14px 14px 17px;text-decoration:none;color:inherit;border-bottom:1px solid #cbd5e1;background:#fff;transition:background .15s ease,border-color .15s ease}.mobile-os-card.status-card{background:color-mix(in srgb,var(--mobile-status) 7%,#fff);border-bottom-color:color-mix(in srgb,var(--mobile-status) 30%,#cbd5e1)}.mobile-os-card.status-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--mobile-status)}.mobile-os-card:last-child{border-bottom:0}.mobile-os-card:hover,.mobile-os-card:active{background:color-mix(in srgb,var(--os-secondary) 5%,#fff)}.mobile-os-card.status-card:hover,.mobile-os-card.status-card:active{background:color-mix(in srgb,var(--mobile-status) 12%,#fff)}.mobile-os-card-top{display:flex;align-items:flex-start;gap:9px}.mobile-os-number{font-size:17px;font-weight:950;color:var(--os-primary)}.mobile-os-date{font-size:11px;color:var(--os-muted);margin-top:3px}.mobile-os-status{margin-left:auto;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:900;white-space:nowrap;border:1px solid color-mix(in srgb,var(--mobile-status) 38%,#cbd5e1);background:color-mix(in srgb,var(--mobile-status) 13%,#fff);color:var(--mobile-status)}.mobile-os-customer{font-size:14px;font-weight:900;margin-top:10px;color:#1e293b}.mobile-os-doc{font-size:11px;color:var(--os-muted);margin-top:2px}.mobile-os-problem{font-size:12px;color:#334155;margin-top:8px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.mobile-os-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:10px}.mobile-os-meta div{background:rgba(255,255,255,.72);border:1px solid #cbd5e1;border-radius:9px;padding:7px}.mobile-os-meta span{display:block;font-size:9px;color:var(--os-muted);font-weight:800;text-transform:uppercase}.mobile-os-meta b{display:block;margin-top:2px;font-size:12px;color:#334155}.mobile-os-meta .total b{color:var(--os-primary);font-size:14px}.mobile-list-empty{text-align:center;padding:34px 14px;color:var(--os-muted)}
@media(max-width:760px){.os-wrap{padding:10px}.os-nav{display:none}.home-short{display:inline}.home-label{display:none}.mobile-filter-grid{grid-template-columns:1fr 1fr}.mobile-filter-grid .search{grid-column:1/-1}.mobile-filter-grid .span-all{grid-column:1/-1}.mobile-monitor-head .home-label{display:none}.mobile-summary{grid-template-columns:1fr 1fr 1fr}.mobile-summary b{font-size:13px}}
@media(max-width:480px){.mobile-filter-grid{grid-template-columns:1fr}.mobile-filter-grid .search,.mobile-filter-grid .span-all{grid-column:auto}.mobile-filter-actions .btn{flex:1}.mobile-filter-actions .count{width:100%}.mobile-os-meta{grid-template-columns:1fr 1fr 1fr}.mobile-summary span{font-size:8px}.mobile-summary b{font-size:12px}}

/* Pesquisa mobile: conteúdo compacto e ações acessíveis sem rolagem horizontal. */
.mobile-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
.mobile-head-actions .btn{white-space:nowrap}
.mobile-filter-grid .search input{min-height:78px;width:100%;border:3px solid #1f61b5;border-radius:13px;background:#edf5ff;padding:19px 16px;font-size:18px;font-weight:800;color:#102f60;caret-color:#125bb0;box-shadow:0 0 0 4px rgba(31,97,181,.12),0 3px 10px rgba(21,78,147,.10);transition:border-color .15s,box-shadow .15s,background .15s}
.mobile-filter-grid .search input:focus{outline:none;border-color:#0b4eaa;box-shadow:0 0 0 5px rgba(30,99,198,.22),0 4px 13px rgba(21,78,147,.14);background:#fff}
.mobile-filter-grid .search label{color:#113c80;font-weight:900;font-size:14px}.mobile-filter-grid .search input::placeholder{color:#54729c;font-weight:500;opacity:1}
.mobile-filter-actions{justify-content:space-between;flex-wrap:nowrap}
.mobile-filter-actions .btn{flex:0 1 48%;min-height:45px;text-align:center;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
@media(max-width:760px){
 .mobile-monitor-shell{width:100%;max-width:none}
 .mobile-monitor-head{margin-bottom:9px;gap:7px;flex-wrap:nowrap}
 .mobile-monitor-head h1{font-size:18px;white-space:nowrap}
 .mobile-monitor-head .spacer{display:none}
 .mobile-head-actions{margin-left:auto;gap:6px}
 .mobile-head-actions .btn{padding:8px 10px;min-height:38px;font-size:12px}
 .mobile-filter-card{padding:12px;margin-bottom:8px}
 .mobile-filter-title{margin-bottom:7px}
 .mobile-filter-grid{gap:8px;grid-template-columns:1fr 1fr}
 .mobile-filter-grid .search{grid-column:1/-1}
 .mobile-filter-grid .span-all{grid-column:1/-1}
 .mobile-filter-grid label{margin-bottom:3px}
 .mobile-filter-grid select,.mobile-filter-grid input[type=date]{min-height:41px}
}
@media(max-width:480px){
 .mobile-monitor-head{flex-wrap:wrap}
 .mobile-monitor-head h1{flex:1 0 auto;font-size:17px}
 .mobile-head-actions{flex:0 0 auto}
 .mobile-head-actions .btn{padding:7px 8px;font-size:11px}
 .mobile-filter-grid{grid-template-columns:1fr 1fr;gap:8px}
 .mobile-filter-grid .search,.mobile-filter-grid .span-all{grid-column:1/-1}
 .mobile-filter-grid>div:not(.search):not(.span-all){min-width:0}
 .mobile-filter-grid select,.mobile-filter-grid input[type=date]{width:100%;font-size:13px;padding:7px 4px}
 .mobile-filter-actions .btn{flex:0 1 48%;min-width:0}
}
@media(max-width:360px){.mobile-monitor-head h1{width:100%}.mobile-head-actions{margin-left:0;width:100%}.mobile-head-actions .btn{flex:1}}
/* Botão flutuante fixo nas telas de filtros e de resultados. */
.mobile-monitor-shell{padding-bottom:92px}
.mobile-os-fab{position:fixed;right:20px;bottom:calc(20px + env(safe-area-inset-bottom,0px));z-index:1100;display:inline-flex;align-items:center;justify-content:center;min-height:55px;padding:13px 19px;border-radius:999px;background:#165ca8;color:#fff!important;text-decoration:none;font-size:15px;font-weight:900;box-shadow:0 10px 24px rgba(15,65,130,.30);border:2px solid #fff;white-space:nowrap}
.mobile-os-fab:hover,.mobile-os-fab:focus{background:#0c488c;outline:3px solid rgba(22,92,168,.22)}
/* O.S. e placa têm destaque equivalente, com placa estilo identificação veicular. */
.mobile-os-identity{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;min-width:0}
.mobile-os-number{font-size:20px;font-weight:950;line-height:1.25}
.mobile-os-plate{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:118px;min-height:45px;padding:4px 12px;border-radius:8px;background:#101010;color:#fff;border:1px solid #000;box-shadow:0 3px 7px rgba(0,0,0,.23)}
.mobile-os-plate span{font-size:9px;font-weight:800;letter-spacing:1.1px;line-height:1.1;color:#e7e7e7}
.mobile-os-plate strong{font-size:20px;font-weight:950;line-height:1.25;letter-spacing:1px;color:#fff;white-space:nowrap}
@media(max-width:480px){.mobile-os-fab{right:14px;bottom:calc(14px + env(safe-area-inset-bottom,0px));min-height:56px;padding:13px 17px}.mobile-os-card-top{flex-wrap:wrap}.mobile-os-status{margin-left:auto}}
/* Ajuste de espaço da lista e ações superiores. */
.mobile-monitor-head .home-label,.mobile-monitor-head .home-short{font-weight:850}
.mobile-monitor-head .mobile-head-actions .btn{min-height:44px;padding:10px 16px;font-size:14px}
.mobile-back-filters{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;min-height:42px;padding:9px 12px;font-size:13px;font-weight:850}
.mobile-os-list{display:grid;gap:5px;padding:5px;background:#e9eef5}
.mobile-os-card,.mobile-os-card:last-child{border:1px solid #cfdae6;border-radius:9px;overflow:hidden}
.mobile-os-card{padding-top:11px;padding-bottom:11px}
.mobile-results-actions{justify-content:flex-end;margin-bottom:4px}
@media(max-width:760px){.mobile-monitor-head .mobile-head-actions .btn{min-height:43px;padding:9px 13px;font-size:13px}.mobile-back-filters{min-height:40px}}
@media(max-width:480px){.mobile-monitor-head{flex-wrap:nowrap;gap:6px}.mobile-monitor-head h1{font-size:15px;flex:1 1 auto;min-width:0;white-space:normal}.mobile-head-actions{flex:0 0 auto;margin-left:auto}.mobile-monitor-head .mobile-head-actions .btn{padding:8px 12px;font-size:13px;min-height:42px}.mobile-back-filters{font-size:12px;padding:8px 10px;min-height:40px}.mobile-os-list{gap:4px;padding:4px}}

/* Cabeçalho enxuto e simétrico da tela de resultados. */
.mobile-monitor-head-results{display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;gap:10px;margin-bottom:7px}
.mobile-monitor-head-results .spacer{display:none}
.mobile-monitor-head-results .mobile-head-actions{margin:0;display:flex;align-items:center}
.mobile-monitor-head-results .mobile-back-filters,
.mobile-monitor-head-results .mobile-head-actions .btn{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:116px;min-width:116px;max-width:116px;height:46px;min-height:46px;padding:0 10px;margin:0;border-radius:10px;border:1px solid #0f4b95;background:#165ca8;color:#fff!important;font-size:14px;font-weight:900;line-height:1.2;text-align:center;text-decoration:none;box-shadow:0 3px 8px rgba(21,78,147,.17)}
.mobile-monitor-head-results .mobile-back-filters:hover,
.mobile-monitor-head-results .mobile-head-actions .btn:hover,
.mobile-monitor-head-results .mobile-back-filters:focus-visible,
.mobile-monitor-head-results .mobile-head-actions .btn:focus-visible{background:#0c488c;color:#fff!important}
.mobile-monitor-head-results .mobile-head-actions .home-label{display:none}
.mobile-monitor-head-results .mobile-head-actions .home-short{display:inline;color:#fff!important}
@media(max-width:480px){
 .mobile-monitor-head-results{flex-wrap:nowrap;justify-content:space-between;gap:8px}
 .mobile-monitor-head-results .mobile-back-filters,
 .mobile-monitor-head-results .mobile-head-actions .btn{width:112px;min-width:112px;max-width:112px;height:45px;min-height:45px;padding:0 8px;font-size:14px}
}
/* Padrão visual de navegação O.S. Mobile: duas extremidades, mesmas dimensões. */
.mobile-monitor-head:not(.mobile-monitor-head-results){display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;gap:10px;margin-bottom:7px}
.mobile-monitor-head:not(.mobile-monitor-head-results) .spacer{display:none}
.mobile-filter-head-label,.mobile-monitor-head:not(.mobile-monitor-head-results) .mobile-head-actions .btn{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:116px;min-width:116px;max-width:116px;height:46px;min-height:46px;border:1px solid #0f4b95;border-radius:10px;background:#165ca8;color:#fff!important;font-size:14px;font-weight:900;line-height:1.2;text-decoration:none;text-align:center;box-shadow:0 3px 8px rgba(21,78,147,.17);padding:0 10px;margin:0}
.mobile-monitor-head:not(.mobile-monitor-head-results) .mobile-head-actions{margin:0}
.mobile-monitor-head:not(.mobile-monitor-head-results) .mobile-head-actions .home-label{display:none}
.mobile-monitor-head:not(.mobile-monitor-head-results) .mobile-head-actions .home-short{display:inline;color:#fff!important}
.mobile-filter-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:nowrap}
.mobile-filter-actions .btn{box-sizing:border-box;flex:0 0 116px!important;width:116px!important;min-width:116px!important;max-width:116px!important;height:46px!important;min-height:46px!important;border-radius:10px!important;border:1px solid #0f4b95!important;background:#165ca8!important;color:#fff!important;font-size:14px!important;font-weight:900!important;padding:0 10px!important;box-shadow:0 3px 8px rgba(21,78,147,.17);text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.mobile-filter-actions .btn:hover,.mobile-filter-actions .btn:focus-visible,.mobile-monitor-head:not(.mobile-monitor-head-results) .mobile-head-actions .btn:hover{background:#0c488c!important;color:#fff!important}
@media(max-width:480px){.mobile-filter-head-label,.mobile-monitor-head:not(.mobile-monitor-head-results) .mobile-head-actions .btn,.mobile-filter-actions .btn{width:112px!important;min-width:112px!important;max-width:112px!important;height:45px!important;min-height:45px!important;font-size:14px!important}}
/* Pesquisa por O.S. e placa - campo mais alto e borda destacada. */
.mobile-filter-grid .search input[name="q"]{min-height:54px;height:54px;border:2px solid #245a9b;border-radius:10px;font-size:16px;font-weight:700;box-shadow:0 0 0 1px rgba(36,90,155,.08)}
.mobile-filter-grid .search input[name="q"]:focus{outline:3px solid rgba(36,90,155,.16);border-color:#0f4b95}
</style>
<div class="mobile-monitor-shell">
<?php if($notifyOrder): ?>
  <div style="padding:13px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;margin-bottom:12px">
    <strong>Status alterado para <?php echo os_e($notifyOrder['status_name']); ?>.</strong>
    <div style="margin:6px 0 10px">Deseja avisar o cliente por WhatsApp?</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-success" href="os_status_comunicar.php?id=<?php echo (int)$notifyOrderId; ?>&amp;channel=whatsapp&amp;return_to=<?php echo rawurlencode('os_mobile_monitor.php'); ?>">Sim, enviar WhatsApp</a><a class="btn btn-secondary" href="os_mobile_monitor.php">Agora não</a></div>
  </div>
<?php endif; ?>
  <div class="mobile-monitor-head<?php echo $hasFiltered ? ' mobile-monitor-head-results' : ''; ?>">
    <?php if ($hasFiltered): ?>
    <a class="btn btn-secondary mobile-back-filters" href="os_mobile_monitor.php?<?php echo os_e(http_build_query(array_filter(array('q'=>$q,'status_id'=>$statusId,'billing_status'=>$billing,'date_from'=>$dateFrom,'date_to'=>$dateTo), static function($v){ return $v !== '' && $v !== 0; }))); ?>" aria-label="Voltar aos filtros">← Filtros</a>
    <?php endif; ?>
    <?php if (!$hasFiltered): ?><span class="mobile-filter-head-label">Filtros</span><?php endif; ?>
    <div class="spacer"></div>
    <div class="mobile-head-actions"><a class="btn btn-secondary" href="os_mobile.php"><span class="home-label">← O.S. Mobile</span><span class="home-short">Início</span></a>
    </div>
  </div>

  <?php if ($photoCleanupCount > 0): ?><div class="alert alert-ok"><?php echo (int)$photoCleanupCount; ?> foto(s) de avaria de O.S. concluída(s) há mais de 48 horas foram removidas automaticamente. As marcações e observações foram preservadas.</div><?php endif; ?>

  <?php if (!$hasFiltered): ?>
  <div class="mobile-filter-card">
    
    <form method="get" action="os_mobile_monitor.php">
      <input type="hidden" name="filtrar" value="1">
      <div class="mobile-filter-grid">
        <div class="search"><label>Pesquisar</label><input name="q" value="<?php echo os_e($q); ?>" placeholder="Placa, nº O.S., cliente ou documento"></div>
        <div><label>Status</label><select name="status_id"><option value="0">Todos</option><?php foreach($statuses as $status): ?><option value="<?php echo (int)$status['id']; ?>" <?php echo $statusId===(int)$status['id']?'selected':''; ?>><?php echo os_e($status['name']); ?></option><?php endforeach; ?></select></div>
        <div><label>Faturamento</label><select name="billing_status"><option value="">Todos</option><?php foreach($billingLabels as $key=>$label): ?><option value="<?php echo os_e($key); ?>" <?php echo $billing===$key?'selected':''; ?>><?php echo os_e($label); ?></option><?php endforeach; ?></select></div>
        <div><label>Data inicial</label><input type="date" name="date_from" value="<?php echo os_e($dateFrom); ?>"></div>
        <div><label>Data final</label><input type="date" name="date_to" value="<?php echo os_e($dateTo); ?>"></div>
        <div class="span-all mobile-filter-actions"><a class="btn btn-secondary" href="os_mobile_monitor.php">Limpar</a><button class="btn btn-primary" type="submit">Filtrar →</button></div>
      </div>
    </form>
  </div>

  <?php else: ?>
  <div class="mobile-results-actions"><span class="count"><?php echo count($rows); ?> O.S. encontrada(s)</span></div>
  <div class="mobile-list-panel">
    <div class="mobile-list-title"><span>Ordens de Serviço</span><span class="muted small">Toque para abrir</span></div>
    <div class="mobile-os-list">
      <?php if (!$rows): ?>
        <div class="mobile-list-empty">Nenhuma O.S. encontrada para os filtros informados.</div>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        $statusColor = os_ui_hex_color(isset($row['status_color']) ? $row['status_color'] : '', '#64748b');
        $billingText = isset($billingLabels[$row['billing_status']]) ? $billingLabels[$row['billing_status']] : (string)$row['billing_status'];
      ?>
        <a class="mobile-os-card status-card" style="--mobile-status:<?php echo os_e($statusColor); ?>" href="<?php echo in_array((string)$row['billing_status'], array('FATURADA', 'ENVIADA_PDV'), true) ? 'os_mobile_view.php?id=' . (int)$row['id'] : 'os_mobile.php?action=edit&amp;id=' . (int)$row['id']; ?>">
          <div class="mobile-os-card-top">
            <div class="mobile-os-identity"><div><div class="mobile-os-number">#<?php echo str_pad((string)$row['os_number'],6,'0',STR_PAD_LEFT); ?></div><div class="mobile-os-date"><?php echo os_e(date('d/m/Y H:i',strtotime($row['opened_at']))); ?></div></div><?php if (!empty($row['vehicle_plate'])): ?><div class="mobile-os-plate" aria-label="Placa <?php echo os_e($row['vehicle_plate']); ?>"><span>PLACA</span><strong><?php echo os_e(strtoupper(trim($row['vehicle_plate']))); ?></strong></div><?php endif; ?></div>
            <span class="mobile-os-status"><?php echo os_e($row['status_name'] ?: 'Sem status'); ?></span>
          </div>
          <div class="mobile-os-customer"><?php echo os_e($row['person_name_snapshot'] ?: ($row['contact_name'] ?: 'Consumidor final')); ?></div>
          <?php if (!empty($row['person_document_snapshot'])): ?><div class="mobile-os-doc"><?php echo os_e($row['person_document_snapshot']); ?></div><?php endif; ?>
          <?php if (!empty($row['reported_problem'])): ?><div class="mobile-os-problem"><?php echo os_e($row['reported_problem']); ?></div><?php endif; ?>
          <div class="mobile-os-meta">
            <div><span>Itens</span><b><?php echo (int)$row['item_count']; ?></b></div>
            <div><span>Faturamento</span><b><?php echo os_e($billingText); ?></b></div>
            <div class="total"><span>Total</span><b><?php echo os_money($row['total_general']); ?></b></div>
          </div>
        </a>
        <div style="display:flex;justify-content:flex-end;margin:2px 0 12px"><a class="btn btn-outline" style="font-size:12px" href="os_imprimir.php?id=<?php echo (int)$row['id']; ?>&amp;origem=mobile">🖨 Imprimir / Enviar</a></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="mobile-summary" style="margin-top:12px">
    <div><span>Produtos</span><b><?php echo os_money($sumP); ?></b></div>
    <div><span>Serviços</span><b><?php echo os_money($sumS); ?></b></div>
    <div><span>Total</span><b><?php echo os_money($sumG); ?></b></div>
  </div>
  <?php endif; ?>
</div>
<a class="mobile-os-fab" href="os_mobile.php?action=new" aria-label="Criar nova Ordem de Serviço">+ Nova O.S.</a>
<?php os_page_end(); ?>
