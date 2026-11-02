<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/_os_ui.php';

$pdo = os_db();
$companyId = os_company_id();
os_require_enabled($pdo, $companyId);
os_ensure_defaults($pdo, $companyId);

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
if ($id <= 0) {
    header('Location: os_mobile_monitor.php');
    exit;
}

$stmt = $pdo->prepare("SELECT o.*,s.name status_name,s.color status_color FROM os_orders o LEFT JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id WHERE o.company_id=? AND o.id=? LIMIT 1");
$stmt->execute(array((int)$companyId, $id));
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    os_page_start('O.S. não encontrada', 'mobile');
    echo '<div style="max-width:760px;margin:0 auto"><div class="alert alert-error">O.S. não encontrada.</div><a class="btn btn-secondary" href="os_mobile_monitor.php">← Voltar ao Monitor</a></div>';
    os_page_end();
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM os_settings WHERE company_id=? LIMIT 1");
$stmt->execute(array((int)$companyId));
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();

$stmt = $pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? ORDER BY item_type,id");
$stmt->execute(array((int)$companyId, $id));
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM os_order_payments WHERE company_id=? AND order_id=? ORDER BY id");
$stmt->execute(array((int)$companyId, $id));
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT f.id,f.label,f.input_type,v.value_text FROM os_custom_fields f LEFT JOIN os_order_field_values v ON v.company_id=f.company_id AND v.field_id=f.id AND v.order_id=? WHERE f.company_id=? AND f.active=1 ORDER BY f.sort_order,f.id");
$stmt->execute(array($id, (int)$companyId));
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

$optionLabels = array();
$stmt = $pdo->prepare("SELECT field_id,option_value,label FROM os_custom_field_options WHERE company_id=?");
$stmt->execute(array((int)$companyId));
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
    $optionLabels[$option['field_id'].'|'.$option['option_value']] = $option['label'];
}

$damagePoints = os_vehicle_damage_enabled((array)$settings) ? os_vehicle_damage_load($pdo, $companyId, $id) : array();
$damageMapSrc=os_vehicle_damage_asset_web_path((array)$settings);$damageMapAlt=os_vehicle_damage_alt((array)$settings);$damageTitle=os_vehicle_damage_title((array)$settings);
$damageTypes = os_vehicle_damage_types();

function os_mobile_view_field_value(array $field, array $labels)
{
    $value = (string)$field['value_text'];
    if ($field['input_type'] === 'checkbox') return $value === '1' ? 'Sim' : 'Não';
    $key = $field['id'].'|'.$value;
    return isset($labels[$key]) ? $labels[$key] : $value;
}

$billingLabels = array(
    'PENDENTE' => 'Pendente',
    'ENVIADA_PDV' => 'Fluxo anterior / PDV',
    'FATURADA' => 'Faturada',
    'CANCELADA' => 'Cancelada',
);
$billingText = isset($billingLabels[$order['billing_status']]) ? $billingLabels[$order['billing_status']] : (string)$order['billing_status'];
$statusColor = os_ui_hex_color(isset($order['status_color']) ? $order['status_color'] : '', '#64748b');
$canEditMobile = !in_array((string)$order['billing_status'], array('FATURADA','ENVIADA_PDV'), true);

os_page_start('O.S. #' . str_pad((string)$order['os_number'],6,'0',STR_PAD_LEFT), 'mobile');
?>
<style>
.mobile-view-shell{max-width:820px;margin:0 auto}.mobile-view-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.mobile-view-actions .btn{flex:1 1 130px;min-height:42px}.mobile-view-hero{background:linear-gradient(135deg,var(--os-primary),var(--os-secondary));color:var(--os-on-primary);border-radius:16px;padding:17px;margin-bottom:13px;box-shadow:0 10px 28px rgba(15,23,42,.12)}.mobile-view-hero-top{display:flex;align-items:flex-start;gap:10px}.mobile-view-number{font-size:24px;font-weight:950}.mobile-view-date{font-size:11px;opacity:.82;margin-top:4px}.mobile-view-status{margin-left:auto;border-radius:999px;padding:6px 10px;background:rgba(255,255,255,.16);font-size:10px;font-weight:900;white-space:nowrap}.mobile-view-client{font-size:16px;font-weight:900;margin-top:15px}.mobile-view-client small{display:block;font-size:11px;font-weight:600;opacity:.82;margin-top:3px}.mobile-view-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.mobile-view-card{background:#fff;border:1px solid var(--os-border);border-radius:13px;padding:13px;margin-bottom:12px;box-shadow:0 3px 10px rgba(15,23,42,.035)}.mobile-view-card h2{margin:0 0 10px;color:var(--os-primary);font-size:15px}.mobile-kv{display:grid;grid-template-columns:1fr 1fr;gap:8px}.mobile-kv div{background:#f8fafc;border-radius:9px;padding:8px}.mobile-kv span{display:block;font-size:9px;text-transform:uppercase;font-weight:850;color:var(--os-muted)}.mobile-kv b,.mobile-kv strong{display:block;margin-top:3px;font-size:12px;color:#334155;word-break:break-word}.mobile-notes{white-space:pre-wrap;font-size:13px;line-height:1.5;color:#334155}.mobile-item-list{display:grid;gap:8px}.mobile-view-item{border:1px solid var(--os-border-soft);border-radius:10px;padding:10px;background:#fbfdff}.mobile-view-item-head{display:flex;gap:8px;align-items:flex-start}.mobile-view-item-head b{font-size:13px;color:var(--os-primary)}.mobile-view-pill{margin-left:auto;border-radius:999px;background:#eef2f7;color:#475569;padding:4px 7px;font-size:9px;font-weight:900}.mobile-item-meta{font-size:11px;color:var(--os-muted);line-height:1.45;margin-top:4px}.mobile-item-values{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}.mobile-item-values div{background:#f1f5f9;border-radius:8px;padding:7px}.mobile-item-values span{display:block;font-size:8px;text-transform:uppercase;color:var(--os-muted);font-weight:850}.mobile-item-values b{display:block;font-size:11px;margin-top:2px;color:#334155}.mobile-total-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.mobile-total-grid div{border:1px solid var(--os-border);background:#f8fafc;border-radius:10px;padding:9px;text-align:center}.mobile-total-grid span{display:block;font-size:8px;text-transform:uppercase;color:var(--os-muted);font-weight:850}.mobile-total-grid b{display:block;margin-top:3px;color:var(--os-primary);font-size:14px}.mobile-total-grid .grand{grid-column:1/-1;background:color-mix(in srgb,var(--os-secondary) 6%,#fff);border-color:color-mix(in srgb,var(--os-secondary) 24%,var(--os-border))}.mobile-total-grid .grand b{font-size:20px}.mobile-pay-row{display:flex;gap:10px;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--os-border-soft);font-size:12px}.mobile-pay-row:last-child{border-bottom:0}.mobile-pay-row b{color:var(--os-primary)}.mobile-damage-map{position:relative;border:1px solid var(--os-border);border-radius:10px;overflow:hidden;background:#fff}.mobile-damage-map img{display:block;width:100%;height:auto}.mobile-damage-marker{position:absolute;transform:translate(-50%,-50%);width:23px;height:23px;border-radius:50%;display:grid;place-items:center;background:#d11b1b;color:#fff;border:2px solid #fff;font-size:10px;font-weight:950;box-shadow:0 2px 6px rgba(0,0,0,.25)}.mobile-damage-list{display:grid;gap:8px;margin-top:9px}.mobile-damage-row{display:grid;grid-template-columns:30px 1fr;gap:8px;border:1px solid var(--os-border-soft);border-radius:9px;padding:8px}.mobile-damage-index{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#d11b1b;color:#fff;font-weight:900;font-size:11px}.mobile-damage-row b{font-size:12px;color:#334155}.mobile-damage-row p{font-size:11px;color:var(--os-muted);margin:3px 0 0}.mobile-damage-photo{display:block;margin-top:7px;max-width:160px;max-height:130px;border-radius:8px;border:1px solid var(--os-border);object-fit:cover}.mobile-empty-detail{color:var(--os-muted);font-size:12px}
@media(max-width:640px){.os-wrap{padding:10px}.os-nav{display:none}.mobile-view-grid,.mobile-kv{grid-template-columns:1fr}.mobile-total-grid{grid-template-columns:1fr 1fr}.mobile-total-grid .grand{grid-column:1/-1}.mobile-item-values{grid-template-columns:1fr 1fr 1fr}.mobile-view-actions .btn{flex:1 1 42%}}
</style>
<div class="mobile-view-shell">
  <div class="mobile-view-actions">
    <a class="btn btn-secondary" href="os_mobile_monitor.php">← Monitor</a>
    <a class="btn btn-outline" href="os_mobile.php">O.S. Mobile</a>
    <?php if ($canEditMobile): ?><a class="btn btn-primary" href="os_mobile.php?action=edit&id=<?php echo (int)$id; ?>">Editar no Mobile</a><?php endif; ?>
    <a class="btn btn-outline" href="os_imprimir.php?id=<?php echo (int)$id; ?>&amp;origem=mobile">🖨 Imprimir / Enviar</a>
  </div>

  <div class="mobile-view-hero">
    <div class="mobile-view-hero-top">
      <div><div class="mobile-view-number">O.S. #<?php echo str_pad((string)$order['os_number'],6,'0',STR_PAD_LEFT); ?></div><div class="mobile-view-date">Aberta em <?php echo os_e(date('d/m/Y H:i',strtotime($order['opened_at']))); ?><?php echo $order['expected_at'] ? ' • Previsão '.os_e(date('d/m/Y H:i',strtotime($order['expected_at']))) : ''; ?></div></div>
      <span class="mobile-view-status"><?php echo os_e($order['status_name'] ?: 'Sem status'); ?></span>
    </div>
    <div class="mobile-view-client"><?php echo os_e($order['person_name_snapshot'] ?: ($order['contact_name'] ?: 'Consumidor final')); ?><small><?php echo os_e($order['person_document_snapshot'] ?: 'Sem CPF/CNPJ informado'); ?></small></div>
  </div>

  <div class="mobile-view-grid">
    <div class="mobile-view-card">
      <h2>Cliente e contato</h2>
      <div class="mobile-kv">
        <div><span>Cliente</span><b><?php echo os_e($order['person_name_snapshot'] ?: 'Consumidor final'); ?></b></div>
        <div><span>CPF/CNPJ</span><b><?php echo os_e($order['person_document_snapshot'] ?: '—'); ?></b></div>
        <div><span>Contato</span><b><?php echo os_e($order['contact_name'] ?: ($order['person_name_snapshot'] ?: '—')); ?></b></div>
        <div><span>Telefone / WhatsApp</span><b><?php echo os_e($order['contact_phone'] ?: ($order['person_phone_snapshot'] ?: '—')); ?></b></div>
      </div>
    </div>
    <div class="mobile-view-card">
      <h2>Situação</h2>
      <div class="mobile-kv">
        <div><span>Status</span><strong style="color:<?php echo os_e($statusColor); ?>"><?php echo os_e($order['status_name'] ?: 'Sem status'); ?></strong></div>
        <div><span>Faturamento</span><b><?php echo os_e($billingText); ?></b></div>
        <div><span>Abertura</span><b><?php echo os_e(date('d/m/Y H:i',strtotime($order['opened_at']))); ?></b></div>
        <div><span>Conclusão</span><b><?php echo $order['closed_at'] ? os_e(date('d/m/Y H:i',strtotime($order['closed_at']))) : '—'; ?></b></div>
      </div>
    </div>
  </div>

  <?php if (!empty($order['reported_problem'])): ?><div class="mobile-view-card"><h2>Solicitação / problema relatado</h2><div class="mobile-notes"><?php echo os_e($order['reported_problem']); ?></div></div><?php endif; ?>

  <?php
  $visibleFields = array();
  foreach ($fields as $field) {
      $value = os_mobile_view_field_value($field, $optionLabels);
      if ($value === '') continue;
      $field['_display_value'] = $value;
      $visibleFields[] = $field;
  }
  ?>
  <?php if ($visibleFields): ?>
    <div class="mobile-view-card"><h2>Dados da O.S.</h2><div class="mobile-kv">
      <?php foreach ($visibleFields as $field): ?><div><span><?php echo os_e($field['label']); ?></span><b><?php echo $field['input_type']==='password' ? '••••••' : os_e($field['_display_value']); ?></b></div><?php endforeach; ?>
    </div></div>
  <?php endif; ?>

  <?php if ($damagePoints): ?>
    <div class="mobile-view-card">
      <h2>Avarias do veículo</h2>
      <div class="mobile-damage-map"><img src="<?php echo os_e($damageMapSrc); ?>" alt="<?php echo os_e($damageMapAlt); ?>"><?php foreach($damagePoints as $idx=>$point): ?><span class="mobile-damage-marker" style="left:<?php echo number_format((float)$point['position_x'],4,'.',''); ?>%;top:<?php echo number_format((float)$point['position_y'],4,'.',''); ?>%"><?php echo $idx+1; ?></span><?php endforeach; ?></div>
      <div class="mobile-damage-list"><?php foreach($damagePoints as $idx=>$point): ?><div class="mobile-damage-row"><div class="mobile-damage-index"><?php echo $idx+1; ?></div><div><b><?php echo os_e(isset($damageTypes[$point['damage_type']]) ? $damageTypes[$point['damage_type']] : $point['damage_type']); ?></b><?php if (!empty($point['description'])): ?><p><?php echo os_e($point['description']); ?></p><?php endif; ?><?php if (!empty($point['photo_url'])): ?><img class="mobile-damage-photo" src="<?php echo os_e($point['photo_url']); ?>" alt="Foto da avaria <?php echo $idx+1; ?>"><?php endif; ?></div></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <div class="mobile-view-card">
    <h2>Produtos e serviços</h2>
    <div class="mobile-item-list">
      <?php if (!$items): ?><div class="mobile-empty-detail">Nenhum produto ou serviço informado.</div><?php endif; ?>
      <?php foreach($items as $item): $respName=os_item_responsible_name($item); ?>
        <div class="mobile-view-item">
          <div class="mobile-view-item-head"><b><?php echo os_e($item['description_snapshot']); ?></b><span class="mobile-view-pill"><?php echo os_e($item['item_type']); ?></span></div>
          <div class="mobile-item-meta"><?php if($respName!==''): ?>Vendedor/Técnico: <?php echo os_e($respName); ?><?php endif; ?><?php if(!empty($item['notes'])): ?><?php echo $respName!==''?' • ':''; ?><?php echo os_e($item['notes']); ?><?php endif; ?></div>
          <div class="mobile-item-values"><div><span>Quantidade</span><b><?php echo number_format((float)$item['quantity'],4,',','.'); ?></b></div><div><span>Unitário</span><b><?php echo os_money($item['unit_price']); ?></b></div><div><span>Total</span><b><?php echo os_money($item['total']); ?></b></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="mobile-view-card">
    <h2>Totais</h2>
    <div class="mobile-total-grid">
      <div><span>Produtos</span><b><?php echo os_money($order['total_products']); ?></b></div>
      <div><span>Serviços</span><b><?php echo os_money($order['total_services']); ?></b></div>
      <div><span>Desconto</span><b><?php echo os_money($order['discount_amount']); ?></b></div>
      <div><span>Acréscimo</span><b><?php echo os_money($order['addition_amount']); ?></b></div>
      <div class="grand"><span>Total geral</span><b><?php echo os_money($order['total_general']); ?></b></div>
    </div>
  </div>

  <?php if ($payments): ?>
    <div class="mobile-view-card"><h2>Formas de pagamento previstas</h2><?php foreach($payments as $payment): ?><div class="mobile-pay-row"><span><?php echo os_e($payment['payment_label'] ?: 'Forma de pagamento'); ?><?php echo $payment['due_date'] ? '<br><span class="muted">Venc. '.os_e(date('d/m/Y',strtotime($payment['due_date']))).'</span>' : ''; ?></span><b><?php echo os_money($payment['amount']); ?></b></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if (!empty($order['diagnosis'])): ?><div class="mobile-view-card"><h2>Diagnóstico</h2><div class="mobile-notes"><?php echo os_e($order['diagnosis']); ?></div></div><?php endif; ?>
  <?php if (!empty($order['technical_notes'])): ?><div class="mobile-view-card"><h2>Observações técnicas</h2><div class="mobile-notes"><?php echo os_e($order['technical_notes']); ?></div></div><?php endif; ?>
  <?php if (!empty($order['customer_notes'])): ?><div class="mobile-view-card"><h2>Observações ao cliente</h2><div class="mobile-notes"><?php echo os_e($order['customer_notes']); ?></div></div><?php endif; ?>

  <div class="mobile-view-actions">
    <a class="btn btn-secondary" href="os_mobile_monitor.php">← Voltar ao Monitor</a>
    <a class="btn btn-primary" href="os_mobile.php?action=new">+ Nova O.S.</a>
  </div>
</div>
<?php os_page_end(); ?>
