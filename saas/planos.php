<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/resellers.php';

if (isset($_GET['edit'])) {
  $editId = (int)$_GET['edit'];
  header('Location: /saas/planos_form.php' . ($editId > 0 ? '?id=' . $editId : ''));
  exit;
}

function saas_plan_list_has_column(PDO $pdo, string $table, string $column): bool {
  if (function_exists('saas_module_column_exists')) {
    return saas_module_column_exists($pdo, $table, $column);
  }
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

$schema = ['ok' => false, 'errors' => []];
try { $schema = saas_ensure_modules_schema($pdo); } catch (Throwable $e) { $schema['errors'][] = $e->getMessage(); }
try { $pricingSchema = reseller_ensure_pricing_schema($pdo); if (empty($pricingSchema['ok'])) { $schema['ok'] = false; $schema['errors'] = array_merge((array)$schema['errors'], (array)($pricingSchema['errors'] ?? [])); } } catch (Throwable $e) { $schema['ok'] = false; $schema['errors'][] = $e->getMessage(); }

$extra = [
  'slug' => saas_plan_list_has_column($pdo, 'plans', 'slug'),
  'headline' => saas_plan_list_has_column($pdo, 'plans', 'headline'),
  'summary' => saas_plan_list_has_column($pdo, 'plans', 'summary'),
  'monthly_cost' => saas_plan_list_has_column($pdo, 'plans', 'monthly_cost'),
  'original_price' => saas_plan_list_has_column($pdo, 'plans', 'original_price'),
  'setup_cost' => saas_plan_list_has_column($pdo, 'plans', 'setup_cost'),
  'setup_price' => saas_plan_list_has_column($pdo, 'plans', 'setup_price'),
  'max_orders' => saas_plan_list_has_column($pdo, 'plans', 'max_orders'),
  'trial_days' => saas_plan_list_has_column($pdo, 'plans', 'trial_days'),
  'billing_days' => saas_plan_list_has_column($pdo, 'plans', 'billing_days'),
  'badge_text' => saas_plan_list_has_column($pdo, 'plans', 'badge_text'),
  'button_label' => saas_plan_list_has_column($pdo, 'plans', 'button_label'),
  'sort_order' => saas_plan_list_has_column($pdo, 'plans', 'sort_order'),
  'is_featured' => saas_plan_list_has_column($pdo, 'plans', 'is_featured'),
  'is_public' => saas_plan_list_has_column($pdo, 'plans', 'is_public'),
  'is_internal' => saas_plan_list_has_column($pdo, 'plans', 'is_internal'),
  'internal_note' => saas_plan_list_has_column($pdo, 'plans', 'internal_note'),
];

$msg = '';
$erro = '';
if (isset($_GET['saved'])) {
  $tipo = (string)($_GET['saved'] ?? '');
  $msg = $tipo === 'created' ? 'Plano criado com sucesso.' : 'Plano atualizado com sucesso.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'seed_defaults') {
      $schema = saas_ensure_modules_schema($pdo);
      reseller_ensure_pricing_schema($pdo);
      $defaults = [
        [
          'name'=>'Trial','price'=>0.00,'monthly_cost'=>0.00,'setup_cost'=>0.00,'setup_price'=>0.00,'is_active'=>1,
          'slug'=>'trial','headline'=>'Start Free','summary'=>'Teste grátis por 7 dias','original_price'=>null,'max_orders'=>30,
          'trial_days'=>7,'billing_days'=>30,'badge_text'=>'Teste','button_label'=>'Quero testar grátis','sort_order'=>0,
          'is_featured'=>0,'is_public'=>0,'is_internal'=>1,'internal_note'=>'Plano técnico de teste. Não aparece como faixa comercial do Delivery.'
        ],
        [
          'name'=>'Delivery Start','price'=>59.90,'monthly_cost'=>0.00,'setup_cost'=>0.00,'setup_price'=>350.00,'is_active'=>1,
          'slug'=>'essencial','headline'=>'Delivery Start','summary'=>'Até 100 pedidos por mês','original_price'=>79.90,'max_orders'=>100,
          'trial_days'=>0,'billing_days'=>30,'badge_text'=>'Oferta','button_label'=>'Continuar para o cadastro','sort_order'=>1,
          'is_featured'=>1,'is_public'=>1,'is_internal'=>0,'internal_note'=>''
        ],
        [
          'name'=>'Delivery Pro','price'=>139.90,'monthly_cost'=>0.00,'setup_cost'=>0.00,'setup_price'=>350.00,'is_active'=>1,
          'slug'=>'profissional','headline'=>'Delivery Pro','summary'=>'De 101 a 500 pedidos por mês','original_price'=>159.90,'max_orders'=>500,
          'trial_days'=>0,'billing_days'=>30,'badge_text'=>'','button_label'=>'Continuar para o cadastro','sort_order'=>2,
          'is_featured'=>0,'is_public'=>1,'is_internal'=>0,'internal_note'=>''
        ],
        [
          'name'=>'Delivery Premium','price'=>199.00,'monthly_cost'=>0.00,'setup_cost'=>0.00,'setup_price'=>350.00,'is_active'=>1,
          'slug'=>'enterprise','headline'=>'Delivery Premium','summary'=>'De 501 a 1000 pedidos por mês','original_price'=>229.90,'max_orders'=>1000,
          'trial_days'=>0,'billing_days'=>30,'badge_text'=>'','button_label'=>'Continuar para o cadastro','sort_order'=>3,
          'is_featured'=>0,'is_public'=>1,'is_internal'=>0,'internal_note'=>''
        ],
      ];

      foreach ($defaults as $data) {
        $cols = ['name','price','is_active'];
        $vals = [$data['name'],$data['price'],$data['is_active']];
        foreach ($extra as $col=>$enabled) {
          if ($enabled) { $cols[]=$col; $vals[]=$data[$col] ?? null; }
        }
        if ($extra['slug']) {
          $check = $pdo->prepare('SELECT id FROM plans WHERE name = ? OR slug = ? LIMIT 1');
          $check->execute([$data['name'], $data['slug']]);
        } else {
          $check = $pdo->prepare('SELECT id FROM plans WHERE name = ? LIMIT 1');
          $check->execute([$data['name']]);
        }
        $existing = (int)($check->fetchColumn() ?: 0);
        if ($existing > 0) {
          // Reaplicar as faixas não apaga os custos já definidos pelo administrador.
          $updateCols = [];
          $updateVals = [];
          foreach ($cols as $index => $column) {
            if (in_array($column, ['monthly_cost','setup_cost'], true)) continue;
            $updateCols[] = $column;
            $updateVals[] = $vals[$index];
          }
          $set = implode(', ', array_map(fn($c) => $c . ' = ?', $updateCols));
          $updateVals[] = $existing;
          $pdo->prepare('UPDATE plans SET ' . $set . ' WHERE id = ?')->execute($updateVals);
        } else {
          $pdo->prepare('INSERT INTO plans (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0,count($cols),'?')) . ')')->execute($vals);
        }
      }

      try {
        $deliveryId = saas_module_id_by_code($pdo, 'delivery');
        if ($deliveryId > 0) {
          $stPlans = $pdo->query("SELECT id FROM plans WHERE LOWER(COALESCE(slug,'')) IN ('essencial','profissional','enterprise')");
          foreach (($stPlans ? $stPlans->fetchAll(PDO::FETCH_COLUMN) : []) as $pid) {
            saas_save_plan_modules($pdo, (int)$pid, [$deliveryId]);
          }
        }
      } catch (Throwable $e) {}

      $msg = 'Faixas padrão do Delivery aplicadas. Informe agora os custos Nexa de cada faixa.';
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$modules = saas_all_modules($pdo, true);
$moduleMap = [];
foreach ($modules as $m) {
  $moduleMap[(int)$m['id']] = (string)$m['name'];
}
$commercialModules = array_values(array_filter($modules, static fn(array $m): bool => (string)($m['code'] ?? '') !== 'delivery' && !empty($m['is_public_sale'])));

$planCols = ['id','name','price','is_active'];
foreach ($extra as $col=>$enabled) if($enabled) $planCols[] = $col;
$sql = 'SELECT ' . implode(', ', $planCols) . ' FROM plans ORDER BY ' . ($extra['sort_order'] ? 'sort_order ASC, ' : '') . 'price ASC, id ASC';
$plans = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

saas_layout_start('Preços e módulos • Central SaaS', 'Preços do configurador', 'A empresa principal usa estes valores no configurador. Cada revenda poderá substituir apenas os preços de venda e implantação.');
?>
<style>
  .plans-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .plans-card-head h3{margin:0}
  .plans-count{color:var(--muted);font-size:13px;font-weight:900}
  .plans-table{min-width:1240px;table-layout:fixed}
  .plans-table th:nth-child(1),.plans-table td:nth-child(1){width:19%}
  .plans-table th:nth-child(2),.plans-table td:nth-child(2){width:11%}
  .plans-table th:nth-child(3),.plans-table td:nth-child(3){width:11%}
  .plans-table th:nth-child(4),.plans-table td:nth-child(4){width:14%}
  .plans-table th:nth-child(5),.plans-table td:nth-child(5){width:13%}
  .plans-table th:nth-child(6),.plans-table td:nth-child(6){width:13%}
  .plans-table th:nth-child(7),.plans-table td:nth-child(7){width:12%}
  .plans-table th:nth-child(8),.plans-table td:nth-child(8){width:7%}
  .plans-title strong{display:block;line-height:1.25}
  .plans-title .muted{display:block;font-size:12px;line-height:1.35;margin-top:2px}
  .plans-modules{display:flex;flex-wrap:wrap;gap:6px}
  .plans-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
  .plans-empty{padding:22px!important;text-align:center;color:var(--muted);font-weight:800}
  @media(max-width:760px){.plans-table{min-width:820px}}
</style>

<div class="top-actions">
  <a class="btn2 btn2-primary" href="/saas/planos_form.php">+ Novo plano</a>
  <form method="post" style="display:inline;">
    <input type="hidden" name="action" value="seed_defaults">
    <button class="btn2 btn2-ghost" type="submit">Aplicar faixas padrão</button>
  </form>
  <a class="btn2 btn2-ghost" href="/saas/modulos.php">Módulos</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<?php if (empty($schema['ok'])): ?><div class="warn">A estrutura de módulos ainda não foi confirmada. Acesse <strong>Módulos</strong> e clique em <strong>Recriar/validar estrutura</strong>.</div><?php endif; ?>

<div class="panel" style="margin-top:16px;background:#f8fbff;">
  <strong>Onde definir o limite de pedidos?</strong>
  <p class="muted" style="margin:6px 0 0;">Clique em <strong>Editar</strong> na faixa desejada e preencha o campo <strong>Limite máximo de pedidos por mês</strong>. Exemplos: Start = 100, Pro = 500 e Premium = 1000.</p>
</div>

<div class="panel" style="margin-top:16px">
  <div class="plans-card-head">
    <h3>Planos cadastrados</h3>
    <span class="plans-count"><?= count($plans) ?> plano(s)</span>
  </div>
  <div class="table-wrap">
    <table class="plans-table">
      <thead>
        <tr>
          <th>Faixa / plano</th>
          <th>Custo mensal</th>
          <th>Venda mensal</th>
          <th>Implantação</th>
          <th>Limite / ciclo</th>
          <th>Visibilidade</th>
          <th>Módulos</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$plans): ?>
          <tr><td colspan="8" class="plans-empty">Nenhum plano cadastrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($plans as $p): ?>
          <?php
            $planModuleIds = saas_plan_module_ids($pdo, (int)$p['id']);
            $visibleModules = [];
            foreach ($planModuleIds as $mid) {
              if (isset($moduleMap[$mid])) $visibleModules[] = $moduleMap[$mid];
            }
          ?>
          <tr>
            <td class="plans-title">
              <strong><?= e((string)$p['name']) ?></strong>
              <?php if (!empty($p['headline'])): ?><span class="muted"><?= e((string)$p['headline']) ?></span><?php endif; ?>
              <?php if ($extra['slug'] && !empty($p['slug'])): ?><span class="muted">Slug: <?= e((string)$p['slug']) ?></span><?php endif; ?>
            </td>
            <td><strong><?= 'R$ ' . number_format((float)($p['monthly_cost'] ?? 0),2,',','.') ?></strong><span class="muted">Nexa → revenda</span></td>
            <td><strong><?= 'R$ ' . number_format((float)$p['price'],2,',','.') ?></strong><span class="muted">Venda direta</span></td>
            <td>
              <strong><?= 'R$ ' . number_format((float)($p['setup_price'] ?? 0),2,',','.') ?></strong>
              <span class="muted">Custo: <?= 'R$ ' . number_format((float)($p['setup_cost'] ?? 0),2,',','.') ?></span>
            </td>
            <td>
              <?php if ($extra['max_orders']): ?><span class="muted">Pedidos: <?= $p['max_orders'] !== null && $p['max_orders'] !== '' ? (int)$p['max_orders'] : 'Ilimitado' ?></span><?php endif; ?>
              <?php if ($extra['trial_days'] && (int)($p['trial_days'] ?? 0) > 0): ?><span class="muted">Trial: <?= (int)$p['trial_days'] ?> dia(s)</span><?php endif; ?>
              <?php if ($extra['billing_days']): ?><span class="muted">Cobrança: <?= (int)($p['billing_days'] ?? 30) ?> dia(s)</span><?php endif; ?>
            </td>
            <td>
              <span class="pill" style="<?= !empty($p['is_active']) ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' : 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;' ?>"><?= !empty($p['is_active']) ? 'Ativo' : 'Inativo' ?></span>
              <?php if ($extra['is_public']): ?><span class="pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;margin-top:4px;"><?= !empty($p['is_public']) ? 'Público' : 'Oculto' ?></span><?php endif; ?>
              <?php if (!empty($p['is_internal'])): ?><span class="pill" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74;margin-top:4px;">Interno</span><?php endif; ?>
              <?php if (!empty($p['is_featured'])): ?><span class="pill" style="background:#fdf2f8;color:#9d174d;border:1px solid #fbcfe8;margin-top:4px;">Destaque</span><?php endif; ?>
            </td>
            <td>
              <div class="plans-modules">
                <?php if ($visibleModules): ?>
                  <?php foreach ($visibleModules as $name): ?>
                    <span class="pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;"><?= e($name) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="muted">Nenhum módulo vinculado</span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="plans-actions">
                <a class="mini mini-primary" href="/saas/planos_form.php?id=<?= (int)$p['id'] ?>">Editar</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" style="margin-top:18px">
  <div class="plans-card-head">
    <div><h3>Módulos e serviços do configurador</h3><span class="plans-count"><?= count($commercialModules) ?> item(ns) público(s)</span></div>
    <a class="btn2 btn2-ghost" href="/saas/modulos.php">Gerenciar todos os módulos</a>
  </div>
  <div class="table-wrap">
    <table style="min-width:900px">
      <thead><tr><th>Item</th><th>Custo mensal</th><th>Venda mensal</th><th>Implantação/serviço</th><th>Exibição</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($commercialModules as $module): ?>
        <tr>
          <td><strong><?= e((string)$module['name']) ?></strong> <span class="pill"><?= (($module['item_type'] ?? 'module') === 'service') ? 'Serviço' : 'Módulo' ?></span><br><span class="muted"><?= e((string)($module['description'] ?? '')) ?></span></td>
          <td><strong><?= 'R$ ' . number_format((float)($module['monthly_cost'] ?? 0),2,',','.') ?></strong><br><span class="muted">Impl. custo: <?= 'R$ ' . number_format((float)($module['setup_cost'] ?? 0),2,',','.') ?></span></td>
          <td><strong><?= 'R$ ' . number_format((float)($module['monthly_price'] ?? 0),2,',','.') ?></strong></td>
          <td><strong><?= 'R$ ' . number_format((float)($module['setup_price'] ?? 0),2,',','.') ?></strong></td>
          <td><?= !empty($module['is_public_sale']) ? '<span class="pill" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">No configurador</span>' : '<span class="pill">Oculto</span>' ?></td>
          <td><a class="mini mini-primary" href="/saas/modulo_form.php?id=<?= (int)$module['id'] ?>">Editar</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$commercialModules): ?><tr><td colspan="6" class="plans-empty">Nenhum módulo ou serviço público está disponível. Cadastre o item no menu Módulos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php saas_layout_end(); ?>
