<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/accounting_module.php';

$cid = (int)COMPANY_ID;
$err = '';
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
if (!in_array($action, ['list','new','view'], true)) $action = 'list';
$viewId = (int)($_GET['id'] ?? 0);

try {
  accounting_ensure_schema($pdo);
  inventory_ensure_schema($pdo);
} catch (Throwable $e) {
  $err = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
  try {
    accounting_csrf_check((string)($_POST['csrf'] ?? ''), 'admin_inventory');
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'generate') {
      @set_time_limit(0);
      $inventoryId = inventory_generate($pdo, $cid, [
        'inventory_date' => (string)($_POST['inventory_date'] ?? ''),
        'stock_filter' => (string)($_POST['stock_filter'] ?? 'positivos'),
        'cost_method' => (string)($_POST['cost_method'] ?? 'average'),
        'book_number' => max(1, (int)($_POST['book_number'] ?? 1)),
        'initial_page' => max(1, (int)($_POST['initial_page'] ?? 1)),
        'initial_sheet' => max(1, (int)($_POST['initial_sheet'] ?? ($_POST['initial_page'] ?? 1))),
        'excluded_groups' => (array)($_POST['excluded_groups'] ?? []),
        'notes' => trim((string)($_POST['notes'] ?? '')),
      ], function_exists('auth_user_id') ? auth_user_id() : null);
      header('Location: fiscal_relatorio_inventario.php?action=view&id=' . $inventoryId . '&ok=' . rawurlencode('Inventário gerado e gravado com sucesso. Os valores deste inventário não serão alterados pelas movimentações futuras.'));
      exit;
    }

    if ($postAction === 'delete') {
      $inventoryId = (int)($_POST['id'] ?? 0);
      if ($inventoryId <= 0) throw new RuntimeException('Inventário inválido.');
      if (fiscal_table_exists($pdo, 'accounting_closures') && fiscal_column_exists($pdo, 'accounting_closures', 'inventory_id')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM accounting_closures WHERE company_id=? AND inventory_id=?');
        $st->execute([$cid, $inventoryId]);
        if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Este inventário está vinculado a um fechamento contábil e não pode ser removido.');
      }
      inventory_delete($pdo, $cid, $inventoryId);
      header('Location: fiscal_relatorio_inventario.php?ok=' . rawurlencode('Inventário removido.'));
      exit;
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
    if ((string)($_POST['action'] ?? '') === 'generate') $action = 'new';
  }
}

$groups = [];
$inventories = [];
$inventory = null;
$items = [];
try {
  if ($err === '') {
    $groups = inventory_product_groups($pdo, $cid);
    if ($action === 'view') {
      if ($viewId <= 0) throw new RuntimeException('Inventário inválido.');
      $inventory = inventory_get($pdo, $cid, $viewId);
      if (!$inventory) throw new RuntimeException('Inventário não encontrado.');
      $items = inventory_items($pdo, $cid, $viewId);
    } else {
      $inventories = inventory_list($pdo, $cid, 150);
    }
  }
} catch (Throwable $e) {
  $err = $err ?: $e->getMessage();
}

$defaultDate = (string)($_POST['inventory_date'] ?? date('Y-m-t', strtotime('first day of last month')));
$defaultStock = (string)($_POST['stock_filter'] ?? 'positivos');
$defaultCost = (string)($_POST['cost_method'] ?? 'average');
$defaultBook = max(1, (int)($_POST['book_number'] ?? 1));
$defaultPage = max(1, (int)($_POST['initial_page'] ?? 1));
$defaultSheet = max(1, (int)($_POST['initial_sheet'] ?? $defaultPage));
$defaultExcluded = array_map('strval', (array)($_POST['excluded_groups'] ?? []));
$defaultNotes = (string)($_POST['notes'] ?? '');

$stockLabels = ['todos'=>'Todo o estoque','positivos'=>'Somente estoque positivo','negativos'=>'Somente estoque negativo'];
$costLabels = ['average'=>'Custo médio','last'=>'Último custo'];

$headerActions = [['label'=>'Relatórios fiscais','href'=>'fiscal_relatorios.php','class'=>'btn']];
if ($action === 'list') {
  $headerActions[] = ['label'=>'Novo inventário','href'=>'fiscal_relatorio_inventario.php?action=new','class'=>'btn btn-primary'];
} elseif ($action === 'new') {
  $headerActions[] = ['label'=>'Voltar à lista','href'=>'fiscal_relatorio_inventario.php','class'=>'btn'];
} elseif ($action === 'view' && $inventory) {
  $headerActions[] = ['label'=>'Baixar PDF','href'=>'fiscal_inventario_pdf.php?id=' . (int)$inventory['id'] . '&download=1','class'=>'btn btn-primary'];
  $headerActions[] = ['label'=>'Novo inventário','href'=>'fiscal_relatorio_inventario.php?action=new','class'=>'btn'];
  $headerActions[] = ['label'=>'Voltar à lista','href'=>'fiscal_relatorio_inventario.php','class'=>'btn'];
}

fiscal_admin_start('Relatório Fiscal - Inventário', 'fiscal_inventario');
fiscal_page_header(
  'Inventário de Estoque',
  'Gere e preserve inventários mensais com saldo, último custo e custo médio da data informada. Cada geração fica gravada para consultas futuras.',
  $headerActions
);
fiscal_report_tabs('fiscal_inventario');
fiscal_flash();
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>

<?php if ($action === 'new'): ?>
  <div class="fiscal-card inventory-intro">
    <h2>Novo inventário</h2>
    <p class="fiscal-muted">A data precisa ser o último dia do mês. Ao gerar, o sistema grava uma fotografia permanente das quantidades e dos custos encontrados para essa data.</p>
    <form method="post" class="fiscal-form" data-no-processing>
      <input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_inventory')) ?>">
      <input type="hidden" name="action" value="generate">
      <div class="fiscal-form-grid">
        <div>
          <label>Data do inventário</label>
          <input type="date" name="inventory_date" value="<?= fiscal_h($defaultDate) ?>" required>
          <small>Informe o último dia do mês.</small>
        </div>
        <div>
          <label>Estoque a considerar</label>
          <select name="stock_filter">
            <?php foreach ($stockLabels as $key=>$label): ?><option value="<?= fiscal_h($key) ?>" <?= $defaultStock===$key?'selected':'' ?>><?= fiscal_h($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Critério de valorização</label>
          <select name="cost_method">
            <?php foreach ($costLabels as $key=>$label): ?><option value="<?= fiscal_h($key) ?>" <?= $defaultCost===$key?'selected':'' ?>><?= fiscal_h($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Número do livro</label>
          <input type="number" name="book_number" min="1" value="<?= $defaultBook ?>">
        </div>
        <div>
          <label>Página inicial</label>
          <input type="number" name="initial_page" min="1" value="<?= $defaultPage ?>">
          <small>A página final será calculada automaticamente.</small>
        </div>
        <div>
          <label>Folha inicial</label>
          <input type="number" name="initial_sheet" min="1" value="<?= $defaultSheet ?>">
          <small>A folha final será calculada automaticamente.</small>
        </div>
        <div class="span-3">
          <label>Observações</label>
          <textarea name="notes" placeholder="Ex.: inventário anual, conferência realizada, critérios especiais..."><?= fiscal_h($defaultNotes) ?></textarea>
        </div>
        <div class="span-3">
          <div class="inventory-group-head">
            <div><label>Grupos que não devem sair no inventário</label><small>Marque somente os grupos que deseja excluir desta geração.</small></div>
            <?php if ($groups): ?><button type="button" class="btn btn-sm" id="inventory-clear-groups">Desmarcar todos</button><?php endif; ?>
          </div>
          <?php if (!$groups): ?>
            <div class="fiscal-empty compact"><span>Nenhum grupo de produto cadastrado.</span></div>
          <?php else: ?>
            <div class="inventory-groups">
              <?php foreach ($groups as $group): $checked=in_array($group,$defaultExcluded,true); ?>
                <label><input type="checkbox" name="excluded_groups[]" value="<?= fiscal_h($group) ?>" <?= $checked?'checked':'' ?>><span><?= fiscal_h($group) ?></span></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="span-3 fiscal-form-actions">
          <a class="btn" href="fiscal_relatorio_inventario.php">Cancelar</a>
          <button class="btn btn-primary" type="submit">Gerar e gravar inventário</button>
        </div>
      </div>
    </form>
  </div>
  <div class="msg inventory-warning"><strong>Importante:</strong> quando o histórico de estoque ou custo não estiver disponível para algum produto, o sistema gravará o valor atual disponível e mostrará um aviso no inventário gerado.</div>

<?php elseif ($action === 'view' && $inventory):
  $excluded = json_decode((string)($inventory['excluded_groups_json'] ?? '[]'), true);
  if (!is_array($excluded)) $excluded=[];
?>
  <div class="fiscal-grid-4 inventory-kpis">
    <div class="fiscal-kpi"><span>Data do inventário</span><strong><?= fiscal_h(fiscal_date_br((string)$inventory['inventory_date'])) ?></strong></div>
    <div class="fiscal-kpi"><span>Produtos gravados</span><strong><?= (int)$inventory['item_count'] ?></strong></div>
    <div class="fiscal-kpi"><span>Quantidade total</span><strong><?= number_format((float)$inventory['total_quantity'],4,',','.') ?></strong></div>
    <div class="fiscal-kpi"><span>Valor total</span><strong>R$ <?= number_format((float)$inventory['total_value'],2,',','.') ?></strong></div>
  </div>

  <?php if (!empty($inventory['warning_message'])): ?><div class="msg inventory-warning"><strong>Atenção ao histórico:</strong> <?= fiscal_h((string)$inventory['warning_message']) ?></div><?php endif; ?>

  <div class="fiscal-card">
    <div class="fiscal-list-head">
      <div><h2>Inventário gravado #<?= (int)$inventory['id'] ?></h2><p class="fiscal-muted">Gerado em <?= date('d/m/Y H:i', strtotime((string)$inventory['generated_at'])) ?>. Estes valores são permanentes.</p></div>
      <div class="fiscal-inline-actions">
        <a class="btn btn-primary" target="_blank" href="fiscal_inventario_pdf.php?id=<?= (int)$inventory['id'] ?>">Abrir PDF</a>
        <a class="btn" href="fiscal_inventario_pdf.php?id=<?= (int)$inventory['id'] ?>&download=1">Baixar PDF</a>
      </div>
    </div>
    <div class="inventory-meta">
      <div><span>Estoque</span><strong><?= fiscal_h($stockLabels[(string)$inventory['stock_filter']] ?? (string)$inventory['stock_filter']) ?></strong></div>
      <div><span>Valorização</span><strong><?= fiscal_h($costLabels[(string)$inventory['cost_method']] ?? (string)$inventory['cost_method']) ?></strong></div>
      <div><span>Livro</span><strong><?= (int)$inventory['book_number'] ?></strong></div>
      <div><span>Páginas</span><strong><?= (int)$inventory['initial_page'] ?> a <?= (int)($inventory['final_page'] ?: $inventory['initial_page']) ?></strong></div>
      <div><span>Folhas</span><strong><?= (int)($inventory['initial_sheet'] ?: $inventory['initial_page']) ?> a <?= (int)($inventory['final_sheet'] ?: ($inventory['initial_sheet'] ?: $inventory['initial_page'])) ?></strong></div>
      <div><span>Grupos excluídos</span><strong><?= $excluded ? fiscal_h(implode(', ', array_map('strval',$excluded))) : 'Nenhum' ?></strong></div>
    </div>
    <?php if (!empty($inventory['notes'])): ?><div class="accounting-note"><?= nl2br(fiscal_h((string)$inventory['notes'])) ?></div><?php endif; ?>
  </div>

  <div class="fiscal-card">
    <div class="fiscal-list-head"><h2>Itens gravados</h2><span class="fiscal-list-count"><?= count($items) ?> item(ns)</span></div>
    <div class="table-wrap inventory-table-wrap">
      <table class="fiscal-table fiscal-grid-table inventory-items-table">
        <thead><tr><th>Código</th><th>Produto</th><th>Grupo</th><th>Un.</th><th class="right">Quantidade</th><th class="right">Último custo</th><th class="right">Custo médio</th><th class="right">Custo usado</th><th class="right">Total</th><th>Origem</th></tr></thead>
        <tbody>
        <?php if (!$items): ?><tr><td colspan="10">Nenhum item gravado neste inventário.</td></tr><?php endif; ?>
        <?php foreach ($items as $item): ?>
          <tr>
            <td data-label="Código"><?= fiscal_h((string)$item['product_code']) ?></td>
            <td data-label="Produto" class="primary"><strong><?= fiscal_h((string)$item['product_name']) ?></strong><small><?= fiscal_h(trim((string)$item['ncm'])!==''?'NCM '.(string)$item['ncm']:'') ?></small></td>
            <td data-label="Grupo"><?= fiscal_h((string)$item['product_group']) ?></td>
            <td data-label="Un."><?= fiscal_h((string)$item['unit']) ?></td>
            <td data-label="Quantidade" class="right"><?= number_format((float)$item['quantity'],4,',','.') ?></td>
            <td data-label="Último custo" class="right">R$ <?= number_format((float)$item['last_cost'],4,',','.') ?></td>
            <td data-label="Custo médio" class="right">R$ <?= number_format((float)$item['average_cost'],4,',','.') ?></td>
            <td data-label="Custo usado" class="right"><strong>R$ <?= number_format((float)$item['valuation_cost'],4,',','.') ?></strong></td>
            <td data-label="Total" class="right"><strong>R$ <?= number_format((float)$item['total_value'],2,',','.') ?></strong></td>
            <td data-label="Origem"><span class="fiscal-badge <?= ((string)$item['stock_source']==='ATUAL'||(string)$item['cost_source']==='ATUAL')?'off':'' ?>"><?= fiscal_h((string)$item['stock_source']) ?>/<?= fiscal_h((string)$item['cost_source']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="inventory-delete" onsubmit="return confirm('Remover este inventário gravado? Esta ação não poderá ser desfeita.')">
      <input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_inventory')) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$inventory['id'] ?>">
      <button class="btn btn-sm" type="submit">Remover inventário</button>
    </form>
  </div>

<?php else: ?>
  <div class="fiscal-card">
    <div class="fiscal-list-head"><div><h2>Inventários gravados</h2><p class="fiscal-muted">Consulte novamente qualquer inventário sem recalcular os custos atuais.</p></div><span class="fiscal-list-count"><?= count($inventories) ?> inventário(s)</span></div>
    <?php if (!$inventories): ?>
      <div class="fiscal-empty"><strong>Nenhum inventário foi gravado.</strong><span>Use “Novo inventário” para registrar a primeira fotografia mensal do estoque.</span></div>
    <?php else: ?>
      <div class="inventory-list">
        <?php foreach ($inventories as $row):
          $excluded = json_decode((string)($row['excluded_groups_json'] ?? '[]'),true); if(!is_array($excluded))$excluded=[];
        ?>
          <article class="inventory-list-item <?= !empty($row['warning_message'])?'has-warning':'' ?>">
            <div class="inventory-list-main">
              <span class="fiscal-badge">Gravado</span>
              <h3><?= fiscal_h(fiscal_date_br((string)$row['inventory_date'])) ?></h3>
              <p>Gerado em <?= date('d/m/Y H:i',strtotime((string)$row['generated_at'])) ?> · <?= fiscal_h($costLabels[(string)$row['cost_method']] ?? (string)$row['cost_method']) ?> · <?= fiscal_h($stockLabels[(string)$row['stock_filter']] ?? (string)$row['stock_filter']) ?></p>
              <small>Livro <?= (int)$row['book_number'] ?> · páginas <?= (int)$row['initial_page'] ?> a <?= (int)($row['final_page'] ?: $row['initial_page']) ?> · folhas <?= (int)($row['initial_sheet'] ?: $row['initial_page']) ?> a <?= (int)($row['final_sheet'] ?: ($row['initial_sheet'] ?: $row['initial_page'])) ?></small>
              <?php if ($excluded): ?><small>Grupos excluídos: <?= fiscal_h(implode(', ',array_map('strval',$excluded))) ?></small><?php endif; ?>
              <?php if (!empty($row['warning_message'])): ?><small class="inventory-list-warning">Possui aviso de histórico.</small><?php endif; ?>
            </div>
            <div class="inventory-list-values">
              <span><strong><?= (int)$row['item_count'] ?></strong> produtos</span>
              <span><strong><?= number_format((float)$row['total_quantity'],4,',','.') ?></strong> quantidade</span>
              <span><strong>R$ <?= number_format((float)$row['total_value'],2,',','.') ?></strong> total</span>
            </div>
            <div class="inventory-list-actions">
              <a class="btn btn-sm" href="fiscal_relatorio_inventario.php?action=view&id=<?= (int)$row['id'] ?>">Consultar</a>
              <a class="btn btn-sm btn-primary" target="_blank" href="fiscal_inventario_pdf.php?id=<?= (int)$row['id'] ?>">PDF</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<style>
.inventory-intro small,.inventory-group-head small,.inventory-items-table small{display:block;color:#64748b;margin-top:4px}.inventory-group-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:10px}.inventory-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;max-height:290px;overflow:auto;padding:10px;border:1px solid #dbe4f0;border-radius:16px;background:#f8fafc}.inventory-groups label{display:flex;gap:9px;align-items:center;padding:9px 10px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;cursor:pointer;font-weight:750}.inventory-groups input{width:auto;min-height:0}.fiscal-empty.compact{padding:16px}.inventory-warning{border-color:#fcd34d;background:#fffbeb;color:#92400e}.inventory-kpis{margin-bottom:14px}.inventory-kpis .fiscal-kpi strong{font-size:20px}.inventory-meta{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.inventory-meta>div{padding:12px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc}.inventory-meta span,.inventory-meta strong{display:block}.inventory-meta span{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:850}.inventory-meta strong{margin-top:4px;color:#0f172a}.accounting-note{margin-top:12px;padding:11px 13px;border-left:4px solid var(--admin-accent);background:#f8fafc;border-radius:0 12px 12px 0;color:#475569}.inventory-table-wrap{overflow:auto}.inventory-items-table{min-width:1180px}.inventory-items-table th.right,.inventory-items-table td.right{text-align:right}.inventory-delete{display:flex;justify-content:flex-end;margin-top:14px;padding-top:12px;border-top:1px solid #eef2f7}.inventory-list{display:grid;gap:10px}.inventory-list-item{display:grid;grid-template-columns:minmax(280px,1fr) auto auto;gap:16px;align-items:center;padding:15px;border:1px solid #dbe4f0;border-radius:18px;background:#fff}.inventory-list-item.has-warning{border-left:5px solid #f59e0b}.inventory-list-main h3{margin:7px 0 3px;font-size:20px}.inventory-list-main p,.inventory-list-main small{display:block;margin:0;color:#64748b}.inventory-list-main small{margin-top:5px}.inventory-list-warning{color:#b45309!important;font-weight:850}.inventory-list-values{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.inventory-list-values span{padding:9px 11px;border-radius:13px;background:#f1f5f9;color:#475569;font-size:12px;white-space:nowrap}.inventory-list-values strong{color:#0f172a}.inventory-list-actions{display:flex;gap:7px}@media(max-width:980px){.inventory-meta{grid-template-columns:1fr 1fr}.inventory-list-item{grid-template-columns:1fr}.inventory-list-values{justify-content:flex-start}.inventory-list-actions{justify-content:flex-start}}@media(max-width:620px){.inventory-meta{grid-template-columns:1fr}.inventory-group-head{align-items:flex-start;flex-direction:column}.inventory-groups{grid-template-columns:1fr}}
</style>
<script>
(function(){
  var clear=document.getElementById('inventory-clear-groups');
  if(clear)clear.addEventListener('click',function(){document.querySelectorAll('input[name="excluded_groups[]"]').forEach(function(el){el.checked=false;});});
})();
</script>
<?php fiscal_admin_end(); ?>
