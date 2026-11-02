<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/resellers.php';

if (isset($_GET['edit'])) {
  $editId = (int)$_GET['edit'];
  header('Location: /saas/modulo_form.php' . ($editId > 0 ? '?id=' . $editId : ''));
  exit;
}

function saas_modules_money(float $value): string {
  return 'R$ ' . number_format($value, 2, ',', '.');
}

function saas_module_diagnostics(PDO $pdo, array $errors = []): array {
  $tables = ['plans','companies','saas_modules','saas_plan_modules','saas_company_modules','saas_reseller_module_prices','saas_reseller_plan_prices'];
  $requiredColumns = [
    'plans' => ['id','name','price','original_price','monthly_cost','setup_cost','setup_price','max_orders','is_active'],
    'companies' => ['id','commercial_plan_id','is_reseller','reseller_company_id'],
    'saas_modules' => ['id','code','name','description','item_type','monthly_cost','monthly_price','setup_cost','setup_price','allow_quantity','quantity_label','quantity_min','quantity_max','quantity_default','is_public_sale','is_active','sort_order','created_at'],
    'saas_plan_modules' => ['plan_id','module_id','created_at'],
    'saas_company_modules' => ['company_id','module_id','enabled','quantity','expires_at','note','created_at','updated_at'],
    'saas_reseller_module_prices' => ['reseller_company_id','module_id','sale_price','setup_price','is_active'],
    'saas_reseller_plan_prices' => ['reseller_company_id','plan_id','sale_price','setup_price','is_active'],
  ];
  $checks = [];
  $missing = [];
  foreach ($tables as $table) {
    $exists = saas_module_table_exists($pdo, $table);
    $row = ['table' => $table, 'exists' => $exists, 'columns' => [], 'missing' => []];
    if ($exists) {
      foreach ($requiredColumns[$table] as $column) {
        $ok = saas_module_column_exists($pdo, $table, $column);
        $row['columns'][$column] = $ok;
        if (!$ok) { $row['missing'][] = $column; $missing[] = $table . '.' . $column; }
      }
    } else {
      $missing[] = $table;
    }
    $checks[] = $row;
  }
  $counts = ['total'=>0,'active'=>0,'public'=>0,'services'=>0,'modules'=>0];
  if (saas_module_table_exists($pdo, 'saas_modules')) {
    try {
      $counts['total'] = (int)$pdo->query('SELECT COUNT(*) FROM saas_modules')->fetchColumn();
      $counts['active'] = (int)$pdo->query('SELECT COUNT(*) FROM saas_modules WHERE is_active=1')->fetchColumn();
      $counts['public'] = (int)$pdo->query('SELECT COUNT(*) FROM saas_modules WHERE is_active=1 AND is_public_sale=1')->fetchColumn();
      $counts['services'] = (int)$pdo->query("SELECT COUNT(*) FROM saas_modules WHERE item_type='service'")->fetchColumn();
      $counts['modules'] = (int)$pdo->query("SELECT COUNT(*) FROM saas_modules WHERE COALESCE(item_type,'module')<>'service'")->fetchColumn();
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
  }
  return ['ok'=>!$missing && !$errors,'checks'=>$checks,'missing'=>$missing,'errors'=>array_values(array_unique(array_filter($errors))),'counts'=>$counts];
}

$msg = '';
$erro = '';
$schema = ['ok' => false, 'errors' => []];
$showDiagnostics = isset($_GET['instalar']) || isset($_GET['diagnostico']);

try {
  $schema = saas_ensure_modules_schema($pdo);
  $resellerSchema = reseller_ensure_schema($pdo);
  $pricingSchema = reseller_ensure_pricing_schema($pdo);
  $schema['ok'] = !empty($schema['ok']) && !empty($resellerSchema['ok']) && !empty($pricingSchema['ok']);
  $schema['errors'] = array_merge((array)($schema['errors'] ?? []), (array)($resellerSchema['errors'] ?? []), (array)($pricingSchema['errors'] ?? []));
  if (isset($_GET['instalar'])) {
    $msg = !empty($schema['ok']) ? 'Estrutura recriada e validada. O diagnóstico completo está exibido abaixo.' : 'A validação encontrou pendências. Confira o diagnóstico e os detalhes técnicos abaixo.';
  }
} catch (Throwable $e) {
  $schema['errors'][] = $e->getMessage();
}

if (isset($_GET['saved'])) {
  $tipo = (string)($_GET['saved'] ?? '');
  $msg = $tipo === 'created' ? 'Módulo ou serviço criado com sucesso e já disponível conforme as opções Ativo/Público.' : 'Módulo ou serviço atualizado com sucesso.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $schema = saas_ensure_modules_schema($pdo);
    reseller_ensure_pricing_schema($pdo);
    if (empty($schema['ok'])) throw new RuntimeException('Não foi possível confirmar as tabelas de módulos.');
    if ((string)($_POST['action'] ?? '') === 'seed_defaults') {
      saas_seed_default_modules($pdo);
      $msg = 'Módulos padrão aplicados sem remover os módulos ou serviços criados manualmente.';
    }
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

$modules = saas_all_modules($pdo, false);
$diagnostics = saas_module_diagnostics($pdo, (array)($schema['errors'] ?? []));
if (!empty($diagnostics['missing'])) $schema['ok'] = false;

saas_layout_start('Módulos e serviços • Central SaaS', 'Módulos e serviços', 'Cadastre itens comercializáveis e acompanhe exatamente o estado da estrutura no banco.');
?>
<style>
  .modules-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.modules-card-head h3{margin:0}.modules-count{color:var(--muted);font-size:13px;font-weight:900}.modules-table{min-width:1120px;table-layout:fixed}.modules-table th:nth-child(1),.modules-table td:nth-child(1){width:24%}.modules-table th:nth-child(2),.modules-table td:nth-child(2){width:10%}.modules-table th:nth-child(3),.modules-table td:nth-child(3){width:11%}.modules-table th:nth-child(4),.modules-table td:nth-child(4){width:13%}.modules-table th:nth-child(5),.modules-table td:nth-child(5){width:11%}.modules-table th:nth-child(6),.modules-table td:nth-child(6){width:11%}.modules-table th:nth-child(7),.modules-table td:nth-child(7){width:10%}.modules-table th:nth-child(8),.modules-table td:nth-child(8){width:7%}.modules-table th:nth-child(9),.modules-table td:nth-child(9){width:5%}.module-title strong{display:block;line-height:1.25}.module-title .muted{display:block;font-size:12px;line-height:1.35;margin-top:2px}.modules-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.modules-empty{padding:22px!important;text-align:center;color:var(--muted);font-weight:800}.diag-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:14px 0}.diag-stat{padding:13px;border:1px solid #dbe7f6;border-radius:16px;background:#f8fbff}.diag-stat span{display:block;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:900}.diag-stat strong{display:block;font-size:25px;margin-top:4px;color:var(--primary-dark)}.diag-table{display:grid;gap:9px}.diag-row{padding:12px 14px;border:1px solid #dbe7f6;border-radius:15px;background:#fff}.diag-row-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.diag-columns{font-size:12px;color:var(--muted);margin-top:7px;line-height:1.5;word-break:break-word}.diag-errors{margin-top:12px;padding:13px;border-radius:15px;background:#fff1f2;border:1px solid #fecdd3;color:#991b1b}.status-stack{display:flex;flex-direction:column;gap:5px;align-items:flex-start}@media(max-width:900px){.diag-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.diag-grid{grid-template-columns:1fr}}
</style>

<div class="top-actions"><a class="btn2 btn2-primary" href="/saas/modulo_form.php">+ Novo módulo ou serviço</a><form method="post" style="display:inline"><input type="hidden" name="action" value="seed_defaults"><button class="btn2 btn2-ghost" type="submit">Aplicar módulos padrão</button></form><a class="btn2 btn2-ghost" href="/saas/modulos.php?instalar=1">Recriar/validar estrutura</a><a class="btn2 btn2-ghost" href="/saas/modulos.php?diagnostico=1">Ver diagnóstico</a><a class="btn2 btn2-ghost" href="/saas/planos.php">Configurar planos</a><a class="btn2 btn2-ghost" href="/saas/empresas.php">Empresas</a></div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<?php if (empty($schema['ok'])): ?><div class="warn">A estrutura comercial possui pendências e pode impedir o cadastro de empresas pelo configurador. Clique em <strong>Recriar/validar estrutura</strong> e veja abaixo exatamente quais tabelas ou colunas não foram confirmadas.</div><?php else: ?><div class="alert-ok">Estrutura confirmada: <?= (int)$diagnostics['counts']['total'] ?> item(ns) cadastrado(s), <?= (int)$diagnostics['counts']['public'] ?> visível(is) no configurador.</div><?php endif; ?>

<?php if ($showDiagnostics || empty($schema['ok'])): ?>
<div class="panel" style="margin-top:16px"><h3>Diagnóstico da estrutura</h3><p class="muted">Este quadro mostra o que foi realmente localizado no banco após a validação.</p><div class="diag-grid"><div class="diag-stat"><span>Total cadastrado</span><strong><?= (int)$diagnostics['counts']['total'] ?></strong></div><div class="diag-stat"><span>Ativos</span><strong><?= (int)$diagnostics['counts']['active'] ?></strong></div><div class="diag-stat"><span>No configurador</span><strong><?= (int)$diagnostics['counts']['public'] ?></strong></div><div class="diag-stat"><span>Módulos</span><strong><?= (int)$diagnostics['counts']['modules'] ?></strong></div><div class="diag-stat"><span>Serviços</span><strong><?= (int)$diagnostics['counts']['services'] ?></strong></div></div><div class="diag-table"><?php foreach ($diagnostics['checks'] as $check): ?><div class="diag-row"><div class="diag-row-head"><strong><?= e((string)$check['table']) ?></strong><span class="pill" style="<?= !empty($check['exists']) && empty($check['missing']) ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;' ?>"><?= !empty($check['exists']) ? (empty($check['missing']) ? 'OK' : 'Colunas pendentes') : 'Tabela ausente' ?></span></div><div class="diag-columns"><?php if (!empty($check['missing'])): ?><strong>Faltando:</strong> <?= e(implode(', ', $check['missing'])) ?><?php else: ?>Todas as colunas obrigatórias foram confirmadas.<?php endif; ?></div></div><?php endforeach; ?></div><?php if (!empty($diagnostics['errors'])): ?><div class="diag-errors"><strong>Detalhes técnicos:</strong><br><?= nl2br(e(implode("\n", array_slice($diagnostics['errors'], 0, 12)))) ?></div><?php endif; ?></div>
<?php endif; ?>

<div class="panel" style="margin-top:16px"><div class="modules-card-head"><h3>Itens cadastrados</h3><span class="modules-count"><?= count($modules) ?> item(ns)</span></div><div class="table-wrap"><table class="modules-table"><thead><tr><th>Nome</th><th>Tipo</th><th>Código</th><th>Custo revenda</th><th>Venda mensal</th><th>Inicial/serviço</th><th>Quantidade</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php if (!$modules): ?><tr><td colspan="9" class="modules-empty">Nenhum módulo ou serviço cadastrado.</td></tr><?php endif; ?><?php foreach ($modules as $m): ?><tr id="module-<?= (int)$m['id'] ?>"><td class="module-title"><strong><?= e((string)$m['name']) ?></strong><?php if (!empty($m['description'])): ?><span class="muted"><?= e((string)$m['description']) ?></span><?php endif; ?></td><td><span class="pill" style="<?= (($m['item_type'] ?? 'module') === 'service') ? 'background:#fff7ed;color:#9a3412;border:1px solid #fdba74;' : 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;' ?>"><?= (($m['item_type'] ?? 'module') === 'service') ? 'Serviço' : 'Módulo' ?></span></td><td><code><?= e((string)$m['code']) ?></code></td><td><?= saas_modules_money((float)($m['monthly_cost'] ?? 0)) ?><br><span class="muted">Inicial: <?= saas_modules_money((float)($m['setup_cost'] ?? 0)) ?></span></td><td><?= saas_modules_money((float)($m['monthly_price'] ?? 0)) ?></td><td><?= saas_modules_money((float)($m['setup_price'] ?? 0)) ?></td><td><?php if (!empty($m['allow_quantity'])): ?><span class="pill" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">Por <?= e((string)($m['quantity_label'] ?: 'unidade')) ?></span><br><span class="muted"><?= (int)($m['quantity_min'] ?? 1) ?> a <?= (int)($m['quantity_max'] ?? 99) ?> · padrão <?= (int)($m['quantity_default'] ?? 1) ?></span><?php else: ?><span class="muted">Quantidade fixa</span><?php endif; ?></td><td><div class="status-stack"><?= !empty($m['is_active']) ? '<span class="pill" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">Ativo</span>' : '<span class="pill" style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;">Inativo</span>' ?><?= !empty($m['is_public_sale']) ? '<span class="pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">No configurador</span>' : '<span class="muted">Não público</span>' ?></div></td><td><div class="modules-actions"><a class="mini mini-primary" href="/saas/modulo_form.php?id=<?= (int)$m['id'] ?>">Editar</a></div></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="panel" style="margin-top:16px"><h3>Como publicar um novo serviço</h3><p class="muted">Cadastre o item, mantenha <strong>Ativo</strong> e <strong>Exibir no configurador</strong> marcados. Para um serviço cobrado uma única vez, deixe a mensalidade em zero e informe o valor no campo de implantação/serviço. Ele aparecerá automaticamente tanto na página principal quanto nas páginas das revendas.</p></div>
<?php saas_layout_end(); ?>
