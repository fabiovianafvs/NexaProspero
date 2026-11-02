<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/_print_brand.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
saas_require_company_module($pdo, $companyId, 'comandas', 'Conferência de conta');
if (!$printCross) comanda_ensure_schema($pdo);
if ($printCross && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'gestao_comandas')) {
  http_response_code(403); echo 'Sem permissão para imprimir conferência de Comandas na empresa selecionada pelo F11.'; exit;
}
$comandaId = (int)($_GET['comanda_id'] ?? $_GET['id'] ?? 0);
$auto = !empty($_GET['auto']);
$printLogoUrl = nexa_print_logo_url($companyId);
if ($comandaId <= 0) { http_response_code(400); echo 'Informe a comanda.'; exit; }
$cmd = comanda_find($pdo, $companyId, $comandaId);
if (!$cmd) { http_response_code(404); echo 'Comanda não encontrada.'; exit; }
$items = comanda_items($pdo, $companyId, $comandaId, true);
$totals = comanda_totals($items);
$cliente = '';
if (!empty($cmd['cliente_id'])) {
  try {
    $row = finance_query_one($pdo, 'SELECT name FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, (int)$cmd['cliente_id']]);
    $cliente = trim((string)($row['name'] ?? ''));
  } catch (Throwable $e) {}
}
if ($cliente === '') $cliente = trim((string)($cmd['nome'] ?? ''));
function pcconf_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pcconf_qty($v): string { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
function pcconf_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Conferência — Comanda <?= pcconf_h($cmd['codigo']) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f8fafc;color:#0f172a}.toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 18px;background:#fff;border-bottom:1px solid #e2e8f0;position:sticky;top:0}.btn{display:inline-flex;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:9px 13px;text-decoration:none;color:#0f172a;font-weight:800;cursor:pointer}.print-wrap{width:min(420px,100%);margin:18px auto;padding:0 12px}.ticket{background:#fff;border:1px solid #cbd5e1;border-radius:18px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.ticket-head{position:relative;text-align:center;border-bottom:2px dashed #94a3b8;padding-bottom:8px;margin-bottom:7px}.ticket-head.has-logo{padding-left:13mm;padding-right:13mm}.print-logo{position:absolute;left:0;top:0;width:11mm;height:8mm;object-fit:contain}.ticket-head h1{margin:0;font-size:20px}.muted{color:#64748b}.line{display:grid;grid-template-columns:52px minmax(0,1fr) 76px;gap:6px;border-bottom:1px dashed #cbd5e1;padding:6px 0;align-items:start}.line strong{font-size:12px}.obs{grid-column:2 / -1;color:#b45309;font-size:10px;white-space:pre-wrap}.total{display:flex;justify-content:space-between;gap:8px;border-top:2px dashed #94a3b8;margin-top:8px;padding-top:9px;font-size:18px;font-weight:950}.footer{text-align:center;color:#64748b;margin-top:10px;font-size:10px}@media print{@page{margin:0}html,body{width:72mm;background:#fff;font-size:10.5px}.toolbar{display:none}.print-wrap{margin:0 auto;padding:0;width:72mm;max-width:72mm}.ticket{width:72mm;max-width:72mm;box-sizing:border-box;box-shadow:none;border:0;border-radius:0;padding:2mm 1.5mm}.ticket-head{padding-bottom:1.5mm;margin-bottom:1.5mm}.ticket-head.has-logo{padding-left:12mm;padding-right:12mm}.print-logo{width:10mm;height:8mm}.ticket-head h1{font-size:15px}.line{grid-template-columns:10mm minmax(0,1fr) 18mm;gap:1.5mm;padding:1.3mm 0;line-height:1.18}.line strong{font-size:10.5px}.line .muted{font-size:9px}.obs{font-size:9px;line-height:1.15}.total{margin-top:1.5mm;padding-top:1.8mm;font-size:15px}.footer{margin-top:2mm;font-size:8.5px}}
</style></head><body>
<div class="toolbar"><strong>Conferência — Comanda <?= pcconf_h($cmd['codigo']) ?></strong><div><button class="btn" onclick="window.print()">Imprimir</button><a class="btn" href="javascript:window.close()">Fechar</a></div></div>
<div class="print-wrap">
  <section class="ticket">
    <div class="ticket-head<?= $printLogoUrl !== '' ? ' has-logo' : '' ?>">
      <?php if ($printLogoUrl !== ''): ?><img class="print-logo" src="<?= pcconf_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?>
      <h1>CONFERÊNCIA DE CONTA</h1>
      <div>Comanda <?= pcconf_h($cmd['codigo']) ?></div>
      <?php if ($cliente !== ''): ?><div class="muted"><?= pcconf_h($cliente) ?></div><?php endif; ?>
      <div class="muted"><?= date('d/m/Y H:i') ?></div>
    </div>
    <?php if (!$items): ?>
      <div class="muted" style="text-align:center;padding:16px 0">Nenhum item em aberto.</div>
    <?php else: ?>
      <?php foreach ($items as $it): ?>
        <div class="line">
          <strong><?= pcconf_qty($it['qtd']) ?>x</strong>
          <div><?= pcconf_h($it['nome']) ?><br><span class="muted"><?= pcconf_money($it['preco_unit']) ?></span></div>
          <strong style="text-align:right"><?= pcconf_money($it['total']) ?></strong>
          <?php if (trim((string)($it['observacao'] ?? '')) !== ''): ?><div class="obs"><?= pcconf_h($it['observacao']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="total"><span>TOTAL</span><strong><?= pcconf_money($totals['total']) ?></strong></div>
    <?php endif; ?>
    <div class="footer">Documento para conferência. Não é documento fiscal.</div>
  </section>
</div>
<?php if ($auto): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},250);});</script><?php endif; ?>
</body></html>
