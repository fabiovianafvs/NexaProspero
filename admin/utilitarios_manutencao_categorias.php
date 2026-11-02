<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/maintenance_bulk.php';

$isCross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'products_write')
  : (int)COMPANY_ID;
$mcCsrf = $isCross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$mcToken = $isCross ? trim((string)(($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? ''))) : '';
$error = '';
$flash = nexa_bulk_flash_get();

try {
  nexa_bulk_require_product_schema($pdo);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nexa_bulk_csrf_check((string)($_POST['csrf'] ?? ''));
    if ($isCross && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('O contexto F11 expirou. Selecione novamente a empresa e repita a operação.');
    }
    if (strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'ALTERAR') {
      throw new RuntimeException('Digite ALTERAR no campo de confirmação.');
    }
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'rename_category') {
      $from = trim((string)($_POST['category_from'] ?? ''));
      $to = trim((string)($_POST['category_to'] ?? ''));
      $before = nexa_bulk_category_count($pdo, $companyId, $from);
      $updated = nexa_bulk_rename_category($pdo, $companyId, $from, $to);
      nexa_bulk_flash_set('ok', 'Categoria atualizada para "' . $to . '" em ' . max($before, $updated) . ' produto(s).');
    } elseif ($action === 'category_visibility') {
      $category = trim((string)($_POST['category_visibility'] ?? ''));
      $orderRaw = (string)($_POST['order_visibility'] ?? 'keep');
      $catalogRaw = (string)($_POST['catalog_visibility'] ?? 'keep');
      $order = $orderRaw === 'keep' ? null : (int)$orderRaw;
      $catalog = $catalogRaw === 'keep' ? null : (int)$catalogRaw;
      $total = nexa_bulk_category_count($pdo, $companyId, $category);
      nexa_bulk_category_visibility($pdo, $companyId, $category, $order, $catalog);
      nexa_bulk_flash_set('ok', 'Visibilidade da categoria "' . $category . '" aplicada a ' . $total . ' produto(s).');
    } else {
      throw new RuntimeException('Operação de manutenção inválida.');
    }
    $url = function_exists('multi_company_context_url') ? multi_company_context_url('utilitarios_manutencao_categorias.php') : 'utilitarios_manutencao_categorias.php';
    header('Location: ' . $url);
    exit;
  }
  $categories = nexa_bulk_categories($pdo, $companyId);
} catch (Throwable $e) {
  $error = $e->getMessage();
  try { $categories = nexa_bulk_categories($pdo, $companyId); } catch (Throwable $ignored) { $categories = $categories ?? []; }
}

$companyLabel = $isCross && defined('MULTI_COMPANY_CONTEXT_NAME') ? (string)MULTI_COMPANY_CONTEXT_NAME : '';
admin_topbar('Utilitários - Manutenção de Categorias', 'manutencao_categorias');
?>
<style>
.bulk-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin:4px 0 18px}.bulk-head h1{margin:0 0 6px;font-size:28px}.bulk-head p{margin:0;color:#64748b;line-height:1.5}.bulk-badge{padding:7px 10px;border-radius:999px;background:#ecfdf5;color:#166534;font-size:12px;font-weight:800;white-space:nowrap}
.bulk-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:0 8px 22px rgba(15,23,42,.05)}.bulk-card h2{margin:0 0 5px;font-size:20px}.bulk-muted{color:#64748b;font-size:13px;line-height:1.5}.bulk-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.bulk-row label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:5px}.bulk-field{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:10px 11px;background:#fff}.bulk-span2{grid-column:span 2}.bulk-confirm{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:12px;margin-top:14px}.bulk-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.bulk-msg-ok,.bulk-msg-err{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:750}.bulk-msg-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.bulk-msg-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.bulk-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:14px}.bulk-table{width:100%;border-collapse:collapse;min-width:760px}.bulk-table th,.bulk-table td{padding:10px 12px;border-bottom:1px solid #eef2f7;font-size:12px;text-align:left}.bulk-table th{background:#f8fafc;color:#475569}.bulk-table tr:last-child td{border-bottom:0}.status-ok{color:#166534;font-weight:800}.status-off{color:#9a3412;font-weight:800}
@media(max-width:700px){.bulk-grid{grid-template-columns:1fr}.bulk-span2{grid-column:auto}.bulk-head{display:block}.bulk-badge{display:inline-block;margin-top:8px}}
</style>
<div class="bulk-head"><div><h1>Manutenção de Categorias</h1><p>Renomeie categorias e altere a visibilidade nos catálogos em lote, sempre dentro da empresa operacional.</p></div><?php if ($isCross): ?><span class="bulk-badge">F11 · <?= nexa_bulk_h($companyLabel !== '' ? $companyLabel : ('Empresa #' . $companyId)) ?></span><?php endif; ?></div>
<?php if (($flash['type'] ?? '') === 'ok'): ?><div class="bulk-msg-ok"><?= nexa_bulk_h($flash['message'] ?? '') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="bulk-msg-err"><?= nl2br(nexa_bulk_h($error)) ?></div><?php endif; ?>

<div class="bulk-card">
  <h2>Substituir nome da categoria</h2>
  <div class="bulk-muted">Exemplo: <strong>Hamburguer</strong> → <strong>HAMBURGUER</strong>. A comparação é exata, inclusive para permitir mudanças somente de maiúsculas/minúsculas.</div>
  <form method="post" onsubmit="return confirm('Confirma a substituição do nome da categoria em todos os produtos desta empresa?');">
    <input type="hidden" name="csrf" value="<?= nexa_bulk_h(nexa_bulk_csrf_token()) ?>"><?php if ($isCross): ?><input type="hidden" name="mc_csrf" value="<?= nexa_bulk_h($mcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= nexa_bulk_h($mcToken) ?>"><?php endif; ?><input type="hidden" name="action" value="rename_category">
    <div class="bulk-grid">
      <div class="bulk-row"><label>Categoria atual</label><select class="bulk-field" name="category_from" required><option value="">Selecione...</option><?php foreach ($categories as $cat): ?><option value="<?= nexa_bulk_h($cat['categoria']) ?>"><?= nexa_bulk_h($cat['categoria']) ?> (<?= (int)$cat['total'] ?>)</option><?php endforeach; ?></select></div>
      <div class="bulk-row"><label>Novo nome</label><input class="bulk-field" name="category_to" maxlength="150" required placeholder="Ex.: HAMBURGUER"></div>
      <div class="bulk-row bulk-span2"><div class="bulk-confirm"><strong>Confirmação de segurança:</strong> digite <strong>ALTERAR</strong> para liberar a execução.<input class="bulk-field" style="margin-top:8px;max-width:220px" name="confirm_text" autocomplete="off" required></div></div>
    </div><div class="bulk-actions"><button class="btn btn-primary" type="submit">Substituir categoria</button></div>
  </form>
</div>

<div class="bulk-card">
  <h2>Visibilidade por categoria</h2>
  <div class="bulk-muted">Oculte ou volte a exibir todos os produtos da categoria no Catálogo de pedidos/Delivery e/ou no Catálogo de produtos. “Sem alteração” preserva o valor atual daquele catálogo.</div>
  <form method="post" onsubmit="return confirm('Confirma a alteração de visibilidade em todos os produtos desta categoria?');">
    <input type="hidden" name="csrf" value="<?= nexa_bulk_h(nexa_bulk_csrf_token()) ?>"><?php if ($isCross): ?><input type="hidden" name="mc_csrf" value="<?= nexa_bulk_h($mcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= nexa_bulk_h($mcToken) ?>"><?php endif; ?><input type="hidden" name="action" value="category_visibility">
    <div class="bulk-grid">
      <div class="bulk-row bulk-span2"><label>Categoria</label><select class="bulk-field" name="category_visibility" required><option value="">Selecione...</option><?php foreach ($categories as $cat): ?><option value="<?= nexa_bulk_h($cat['categoria']) ?>"><?= nexa_bulk_h($cat['categoria']) ?> (<?= (int)$cat['total'] ?> produtos)</option><?php endforeach; ?></select></div>
      <div class="bulk-row"><label>Catálogo de pedidos / Delivery</label><select class="bulk-field" name="order_visibility"><option value="keep">Sem alteração</option><option value="0">Visível</option><option value="1">Oculto</option></select></div>
      <div class="bulk-row"><label>Catálogo de produtos</label><select class="bulk-field" name="catalog_visibility"><option value="keep">Sem alteração</option><option value="0">Visível</option><option value="1">Oculto</option></select></div>
      <div class="bulk-row bulk-span2"><div class="bulk-confirm"><strong>Confirmação de segurança:</strong> digite <strong>ALTERAR</strong> para liberar a execução.<input class="bulk-field" style="margin-top:8px;max-width:220px" name="confirm_text" autocomplete="off" required></div></div>
    </div><div class="bulk-actions"><button class="btn btn-primary" type="submit">Aplicar visibilidade</button></div>
  </form>
</div>

<div class="bulk-card"><h2>Categorias atuais</h2><div class="bulk-muted" style="margin-bottom:12px">Resumo da empresa operacional antes da próxima alteração.</div><div class="bulk-table-wrap"><table class="bulk-table"><thead><tr><th>Categoria</th><th>Produtos</th><th>Ativos</th><th>Pedidos ocultos</th><th>Catálogo ocultos</th></tr></thead><tbody><?php if (!$categories): ?><tr><td colspan="5">Nenhuma categoria encontrada.</td></tr><?php endif; ?><?php foreach ($categories as $cat): ?><tr><td><strong><?= nexa_bulk_h($cat['categoria']) ?></strong></td><td><?= (int)$cat['total'] ?></td><td><?= (int)$cat['ativos'] ?></td><td class="<?= (int)$cat['ocultos_pedidos']>0?'status-off':'status-ok' ?>"><?= (int)$cat['ocultos_pedidos'] ?></td><td class="<?= (int)$cat['ocultos_catalogo']>0?'status-off':'status-ok' ?>"><?= (int)$cat['ocultos_catalogo'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php admin_footer(); ?>
