<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/resellers.php';

function saas_plan_form_has_column(PDO $pdo, string $table, string $column): bool {
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

function saas_plan_form_slugify(string $s): string {
  $s = trim(function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s));
  $s = preg_replace('/[^\pL\pN]+/u', '-', $s);
  $s = trim((string)$s, '-');
  return $s !== '' ? (string)$s : 'plano';
}

function saas_plan_form_payload_from_post(array $extra): array {
  $name = trim((string)($_POST['name'] ?? ''));
  return [
    'name' => $name,
    'price' => reseller_money_value($_POST['price'] ?? 0),
    'monthly_cost' => reseller_money_value($_POST['monthly_cost'] ?? 0),
    'setup_cost' => reseller_money_value($_POST['setup_cost'] ?? 0),
    'setup_price' => reseller_money_value($_POST['setup_price'] ?? 0),
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
    'slug' => trim((string)($_POST['slug'] ?? '')) ?: saas_plan_form_slugify($name),
    'headline' => trim((string)($_POST['headline'] ?? '')) ?: $name,
    'summary' => trim((string)($_POST['summary'] ?? '')),
    'original_price' => ($_POST['original_price'] ?? '') !== '' ? reseller_money_value($_POST['original_price']) : null,
    'max_orders' => ($_POST['max_orders'] ?? '') !== '' && (int)$_POST['max_orders'] > 0 ? (int)$_POST['max_orders'] : null,
    'trial_days' => ($_POST['trial_days'] ?? '') !== '' ? (int)$_POST['trial_days'] : 0,
    'billing_days' => ($_POST['billing_days'] ?? '') !== '' ? (int)$_POST['billing_days'] : 30,
    'badge_text' => trim((string)($_POST['badge_text'] ?? '')),
    'button_label' => trim((string)($_POST['button_label'] ?? '')),
    'sort_order' => ($_POST['sort_order'] ?? '') !== '' ? (int)$_POST['sort_order'] : 0,
    'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
    'is_public' => isset($_POST['is_public']) ? 1 : 0,
    'is_internal' => isset($_POST['is_internal']) ? 1 : 0,
    'internal_note' => trim((string)($_POST['internal_note'] ?? '')),
  ];
}

$schema = ['ok' => false, 'errors' => []];
try { $schema = saas_ensure_modules_schema($pdo); } catch (Throwable $e) { $schema['errors'][] = $e->getMessage(); }
try { $pricingSchema = reseller_ensure_pricing_schema($pdo); if (empty($pricingSchema['ok'])) { $schema['ok'] = false; $schema['errors'] = array_merge((array)$schema['errors'], (array)($pricingSchema['errors'] ?? [])); } } catch (Throwable $e) { $schema['ok'] = false; $schema['errors'][] = $e->getMessage(); }

$extra = [
  'slug' => saas_plan_form_has_column($pdo, 'plans', 'slug'),
  'headline' => saas_plan_form_has_column($pdo, 'plans', 'headline'),
  'summary' => saas_plan_form_has_column($pdo, 'plans', 'summary'),
  'monthly_cost' => saas_plan_form_has_column($pdo, 'plans', 'monthly_cost'),
  'original_price' => saas_plan_form_has_column($pdo, 'plans', 'original_price'),
  'setup_cost' => saas_plan_form_has_column($pdo, 'plans', 'setup_cost'),
  'setup_price' => saas_plan_form_has_column($pdo, 'plans', 'setup_price'),
  'max_orders' => saas_plan_form_has_column($pdo, 'plans', 'max_orders'),
  'trial_days' => saas_plan_form_has_column($pdo, 'plans', 'trial_days'),
  'billing_days' => saas_plan_form_has_column($pdo, 'plans', 'billing_days'),
  'badge_text' => saas_plan_form_has_column($pdo, 'plans', 'badge_text'),
  'button_label' => saas_plan_form_has_column($pdo, 'plans', 'button_label'),
  'sort_order' => saas_plan_form_has_column($pdo, 'plans', 'sort_order'),
  'is_featured' => saas_plan_form_has_column($pdo, 'plans', 'is_featured'),
  'is_public' => saas_plan_form_has_column($pdo, 'plans', 'is_public'),
  'is_internal' => saas_plan_form_has_column($pdo, 'plans', 'is_internal'),
  'internal_note' => saas_plan_form_has_column($pdo, 'plans', 'internal_note'),
];

$erro = '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$modules = saas_all_modules($pdo, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $data = saas_plan_form_payload_from_post($extra);
    if ($data['name'] === '') throw new RuntimeException('Informe o nome do plano.');

    $cols = ['name','price','is_active'];
    $vals = [$data['name'],$data['price'],$data['is_active']];
    foreach ($extra as $col => $enabled) {
      if ($enabled) { $cols[] = $col; $vals[] = $data[$col]; }
    }

    $moduleIds = $_POST['module_ids'] ?? [];
    $moduleIds = is_array($moduleIds) ? $moduleIds : [];

    if ($id > 0) {
      $set = implode(', ', array_map(fn($c) => $c . ' = ?', $cols));
      $vals[] = $id;
      $st = $pdo->prepare('UPDATE plans SET ' . $set . ' WHERE id = ?');
      $st->execute($vals);
      saas_save_plan_modules($pdo, $id, $moduleIds);
      header('Location: /saas/planos.php?saved=updated');
      exit;
    }

    $st = $pdo->prepare('INSERT INTO plans (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
    $st->execute($vals);
    $newPlanId = (int)$pdo->lastInsertId();
    saas_save_plan_modules($pdo, $newPlanId, $moduleIds);
    header('Location: /saas/planos.php?saved=created');
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$planCols = ['id','name','price','is_active'];
foreach ($extra as $col=>$enabled) if($enabled) $planCols[] = $col;
$edit = null;
if ($id > 0) {
  $st = $pdo->prepare('SELECT ' . implode(', ', $planCols) . ' FROM plans WHERE id = ? LIMIT 1');
  $st->execute([$id]);
  $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$edit && !$erro) $erro = 'Plano não encontrado.';
}

$editModuleIds = $edit ? array_fill_keys(saas_plan_module_ids($pdo, (int)$edit['id']), true) : [];

saas_layout_start(
  $edit ? 'Editar plano • Central SaaS' : 'Novo plano • Central SaaS',
  $edit ? 'Editar plano' : 'Novo plano',
  'Cadastre as faixas do Delivery com custo Nexa, valor de venda e implantação. Estes valores alimentam o configurador principal.'
);
?>
<style>
  .plan-form-panel{margin-top:16px;max-width:1160px}
  .plan-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
  .plan-form-grid .full{grid-column:1/-1}
  .plan-checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:12px}
  .plan-checks label,.plan-modules label{margin:0}
  .plan-modules{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:12px}
  .plan-module-option{display:flex;gap:8px;align-items:flex-start;font-weight:800;color:#0b1f5b}
  .plan-module-option input{margin-top:4px}
  .form-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px}
  @media(max-width:760px){.plan-form-grid{grid-template-columns:1fr}.form-actions .btn2{width:100%}}
</style>

<div class="top-actions">
  <a class="btn2 btn2-primary" href="/saas/planos.php">Voltar para lista</a>
  <a class="btn2 btn2-ghost" href="/saas/modulos.php">Módulos</a>
  <?php if ($edit): ?><a class="btn2 btn2-ghost" href="/saas/planos_form.php">+ Novo plano</a><?php endif; ?>
</div>

<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<?php if (empty($schema['ok'])): ?><div class="warn">A estrutura de módulos ainda não foi confirmada. Acesse <strong>Módulos</strong> e clique em <strong>Recriar/validar estrutura</strong>.</div><?php endif; ?>

<div class="panel plan-form-panel">
  <h3><?= $edit ? 'Dados do plano' : 'Cadastrar plano' ?></h3>
  <form method="post" class="plan-form-grid">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <label>Nome da faixa / plano
      <input class="field" name="name" value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="Delivery Start" required>
    </label>
    <label>Preço mensal de venda
      <input class="field" name="price" value="<?= isset($edit['price']) ? e(number_format((float)$edit['price'], 2, ',', '.')) : '' ?>" placeholder="59,90" required>
      <span class="muted">Valor usado pela empresa principal no configurador.</span>
    </label>

    <?php if ($extra['monthly_cost']): ?><label>Custo mensal para a revenda
      <input class="field" name="monthly_cost" value="<?= e(number_format((float)($edit['monthly_cost'] ?? 0), 2, ',', '.')) ?>" placeholder="0,00">
      <span class="muted">Valor que a Nexa cobrará da revenda por cliente ativo nesta faixa.</span>
    </label><?php endif; ?>
    <?php if ($extra['setup_price']): ?><label>Preço de implantação
      <input class="field" name="setup_price" value="<?= e(number_format((float)($edit['setup_price'] ?? 0), 2, ',', '.')) ?>" placeholder="350,00">
      <span class="muted">Valor mostrado no configurador da empresa principal.</span>
    </label><?php endif; ?>
    <?php if ($extra['setup_cost']): ?><label>Custo de implantação para a revenda
      <input class="field" name="setup_cost" value="<?= e(number_format((float)($edit['setup_cost'] ?? 0), 2, ',', '.')) ?>" placeholder="0,00">
      <span class="muted">Cobrado da revenda apenas na primeira ativação da faixa.</span>
    </label><?php endif; ?>

    <?php if ($extra['slug']): ?><label>Slug<input class="field" name="slug" value="<?= e((string)($edit['slug'] ?? '')) ?>" placeholder="essencial"></label><?php endif; ?>
    <?php if ($extra['headline']): ?><label>Título da landing<input class="field" name="headline" value="<?= e((string)($edit['headline'] ?? '')) ?>" placeholder="Essencial"></label><?php endif; ?>
    <?php if ($extra['summary']): ?><label class="full">Resumo<textarea class="field" name="summary" rows="3"><?= e((string)($edit['summary'] ?? '')) ?></textarea></label><?php endif; ?>
    <?php if ($extra['original_price']): ?><label>Preço original<input class="field" name="original_price" value="<?= e((string)($edit['original_price'] ?? '')) ?>" placeholder="79.90"></label><?php endif; ?>
    <?php if ($extra['max_orders']): ?><label>Limite máximo de pedidos por mês
      <input class="field" type="number" min="1" step="1" name="max_orders" value="<?= e((string)($edit['max_orders'] ?? '')) ?>" placeholder="Ex.: 100">
      <span class="muted">Informe 100 para o Start, 500 para o Pro e 1000 para o Premium. Deixe vazio somente para plano sem limite.</span>
    </label><?php else: ?><div class="full warn">O campo de limite ainda não existe no banco. Acesse <strong>Módulos e serviços → Recriar/validar estrutura</strong> ou execute o SQL da atualização v5.7.</div><?php endif; ?>
    <?php if ($extra['trial_days']): ?><label>Dias de trial<input class="field" name="trial_days" value="<?= e((string)($edit['trial_days'] ?? '0')) ?>"></label><?php endif; ?>
    <?php if ($extra['billing_days']): ?><label>Ciclo cobrança (dias)<input class="field" name="billing_days" value="<?= e((string)($edit['billing_days'] ?? '30')) ?>"></label><?php endif; ?>
    <?php if ($extra['badge_text']): ?><label>Selo<input class="field" name="badge_text" value="<?= e((string)($edit['badge_text'] ?? '')) ?>" placeholder="Mais vendido"></label><?php endif; ?>
    <?php if ($extra['button_label']): ?><label>Texto do botão<input class="field" name="button_label" value="<?= e((string)($edit['button_label'] ?? '')) ?>" placeholder="Assinar plano Essencial"></label><?php endif; ?>
    <?php if ($extra['sort_order']): ?><label>Ordem<input class="field" name="sort_order" value="<?= e((string)($edit['sort_order'] ?? '0')) ?>"></label><?php endif; ?>

    <div class="full">
      <label style="display:block;margin-bottom:8px;font-weight:900;color:#0b1f5b;">Configurações</label>
      <div class="plan-checks">
        <label><input type="checkbox" name="is_active" <?= !isset($edit['is_active']) || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>> Ativo</label>
        <?php if ($extra['is_public']): ?><label><input type="checkbox" name="is_public" <?= !isset($edit['is_public']) || (int)$edit['is_public'] === 1 ? 'checked' : '' ?>> Exibir na vitrine/site</label><?php endif; ?>
        <?php if ($extra['is_internal']): ?><label><input type="checkbox" name="is_internal" <?= !empty($edit['is_internal']) ? 'checked' : '' ?>> Plano interno/bonificação</label><?php endif; ?>
        <?php if ($extra['is_featured']): ?><label><input type="checkbox" name="is_featured" <?= !empty($edit['is_featured']) ? 'checked' : '' ?>> Destacar na landing</label><?php endif; ?>
      </div>
    </div>

    <?php if ($extra['internal_note']): ?><label class="full">Observação interna<textarea class="field" name="internal_note" rows="3" placeholder="Ex.: plano usado para bonificação de 3, 6 ou 12 meses."><?= e((string)($edit['internal_note'] ?? '')) ?></textarea></label><?php endif; ?>

    <div class="full">
      <label style="display:block;margin-bottom:8px;font-weight:900;color:#0b1f5b;">Módulos liberados neste plano</label>
      <div class="plan-modules">
        <?php foreach ($modules as $m): ?>
          <?php $checked = $edit ? isset($editModuleIds[(int)$m['id']]) : ((string)$m['code'] === 'delivery'); ?>
          <label class="plan-module-option">
            <input type="checkbox" name="module_ids[]" value="<?= (int)$m['id'] ?>" <?= $checked ? 'checked' : '' ?>>
            <span><strong><?= e((string)$m['name']) ?></strong><br><span class="muted"><?= e((string)($m['description'] ?? '')) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="full form-actions">
      <a class="btn2 btn2-ghost" href="/saas/planos.php">Cancelar</a>
      <button class="btn2 btn2-dark" type="submit"><?= $edit ? 'Atualizar plano' : 'Salvar plano' ?></button>
    </div>
  </form>
</div>

<?php saas_layout_end(); ?>
