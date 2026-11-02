<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/resellers.php';

function saas_module_form_code_clean(string $code): string {
  $code = trim(mb_strtolower($code, 'UTF-8'));
  $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code);
  if (is_string($ascii) && $ascii !== '') $code = $ascii;
  $code = preg_replace('/[^a-z0-9_-]+/', '-', $code) ?? '';
  return trim($code, '-_');
}

$msg = '';
$erro = '';
$schema = ['ok' => false, 'errors' => []];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

try {
  $schema = saas_ensure_modules_schema($pdo);
  $pricingSchema = reseller_ensure_pricing_schema($pdo);
  if (empty($pricingSchema['ok'])) {
    $schema['ok'] = false;
    $schema['errors'] = array_merge((array)($schema['errors'] ?? []), (array)($pricingSchema['errors'] ?? []));
  }
} catch (Throwable $e) {
  $schema['errors'][] = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $schema = saas_ensure_modules_schema($pdo);
    $pricingSchema = reseller_ensure_pricing_schema($pdo);
    if (empty($schema['ok']) || empty($pricingSchema['ok'])) {
      throw new RuntimeException('Não foi possível confirmar a estrutura comercial de módulos. Use “Recriar/validar estrutura” e confira o diagnóstico apresentado.');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe o nome do módulo ou serviço.');

    $code = saas_module_form_code_clean((string)($_POST['code'] ?? ''));
    if ($code === '') $code = saas_module_form_code_clean($name);
    if ($code === '') throw new RuntimeException('Não foi possível gerar o código interno. Informe um código usando letras e números.');

    $itemType = strtolower(trim((string)($_POST['item_type'] ?? 'module')));
    if (!in_array($itemType, ['module','service'], true)) $itemType = 'module';
    $description = trim((string)($_POST['description'] ?? ''));
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $monthlyCost = reseller_money_value($_POST['monthly_cost'] ?? 0);
    $monthlyPrice = reseller_money_value($_POST['monthly_price'] ?? 0);
    $setupCost = reseller_money_value($_POST['setup_cost'] ?? 0);
    $setupPrice = reseller_money_value($_POST['setup_price'] ?? 0);
    $allowQuantity = isset($_POST['allow_quantity']) ? 1 : 0;
    $quantityLabel = trim((string)($_POST['quantity_label'] ?? ''));
    $quantityMin = max(1, (int)($_POST['quantity_min'] ?? 1));
    $quantityMax = max($quantityMin, min(9999, (int)($_POST['quantity_max'] ?? 99)));
    $quantityDefault = max($quantityMin, min($quantityMax, (int)($_POST['quantity_default'] ?? 1)));
    if (!$allowQuantity) {
      $quantityLabel = '';
      $quantityMin = 1;
      $quantityMax = 99;
      $quantityDefault = 1;
    }
    $isPublicSale = isset($_POST['is_public_sale']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($monthlyPrice <= 0 && $setupPrice <= 0 && $isPublicSale) {
      throw new RuntimeException('Para exibir no configurador, informe um valor mensal ou um valor de implantação/serviço.');
    }

    $duplicate = $pdo->prepare('SELECT id FROM saas_modules WHERE code = ? AND id <> ? LIMIT 1');
    $duplicate->execute([$code, $id]);
    if ($duplicate->fetchColumn()) throw new RuntimeException('Já existe outro módulo ou serviço com este código interno.');

    if ($id > 0) {
      $st = $pdo->prepare('UPDATE saas_modules SET code = ?, name = ?, description = ?, item_type = ?, monthly_cost = ?, monthly_price = ?, setup_cost = ?, setup_price = ?, allow_quantity = ?, quantity_label = ?, quantity_min = ?, quantity_max = ?, quantity_default = ?, is_public_sale = ?, sort_order = ?, is_active = ? WHERE id = ?');
      $st->execute([$code, $name, $description, $itemType, $monthlyCost, $monthlyPrice, $setupCost, $setupPrice, $allowQuantity, $quantityLabel !== '' ? $quantityLabel : null, $quantityMin, $quantityMax, $quantityDefault, $isPublicSale, $sortOrder, $isActive, $id]);
      if ($st->rowCount() === 0) {
        $verify = $pdo->prepare('SELECT id FROM saas_modules WHERE id = ? LIMIT 1');
        $verify->execute([$id]);
        if (!$verify->fetchColumn()) throw new RuntimeException('O item que seria atualizado não foi encontrado.');
      }
      header('Location: /saas/modulos.php?saved=updated&module=' . $id);
      exit;
    }

    $st = $pdo->prepare('INSERT INTO saas_modules (code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price, allow_quantity, quantity_label, quantity_min, quantity_max, quantity_default, is_public_sale, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$code, $name, $description, $itemType, $monthlyCost, $monthlyPrice, $setupCost, $setupPrice, $allowQuantity, $quantityLabel !== '' ? $quantityLabel : null, $quantityMin, $quantityMax, $quantityDefault, $isPublicSale, $sortOrder, $isActive]);
    $newId = (int)$pdo->lastInsertId();
    if ($newId <= 0) throw new RuntimeException('O banco não confirmou a inclusão do módulo ou serviço.');
    $verify = $pdo->prepare('SELECT id FROM saas_modules WHERE id = ? LIMIT 1');
    $verify->execute([$newId]);
    if (!$verify->fetchColumn()) throw new RuntimeException('O item foi enviado ao banco, mas não pôde ser localizado após a gravação.');
    header('Location: /saas/modulos.php?saved=created&module=' . $newId);
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$edit = null;
if ($id > 0 && saas_module_table_exists($pdo, 'saas_modules')) {
  $st = $pdo->prepare('SELECT * FROM saas_modules WHERE id = ? LIMIT 1');
  $st->execute([$id]);
  $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$edit && !$erro) $erro = 'Módulo ou serviço não encontrado.';
}

$isNew = !$edit;
saas_layout_start(
  $edit ? 'Editar item • Central SaaS' : 'Novo módulo ou serviço • Central SaaS',
  $edit ? 'Editar módulo ou serviço' : 'Novo módulo ou serviço',
  'Cadastre itens comercializáveis que podem aparecer automaticamente no configurador da principal e das revendas.'
);
?>
<style>
  .module-form-panel{margin-top:16px;max-width:940px}.module-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.module-form-grid .full{grid-column:1/-1}.form-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px}.field-help{margin-top:6px;font-size:12px;color:var(--muted);line-height:1.45}.visibility-box{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px;border:1px solid #dbe7f6;border-radius:18px;background:#f8fbff}.check-line{display:flex;align-items:flex-start;gap:9px;font-weight:850;color:var(--primary-dark)}.check-line input{width:auto;margin-top:3px}.service-tip{background:#eef8ff;border:1px solid #cfe8ff;border-radius:16px;padding:13px 15px;color:#1e3a8a;line-height:1.5}.service-tip strong{color:#0b1f5b}.quantity-box{padding:15px;border:1px solid #cfe0ff;border-radius:18px;background:#f7fbff}.quantity-box-head{display:flex;gap:10px;align-items:flex-start}.quantity-box-head input{width:auto;margin-top:3px}.quantity-fields{display:grid;grid-template-columns:2fr repeat(3,1fr);gap:12px;margin-top:12px}.quantity-fields[hidden]{display:none!important}@media(max-width:760px){.module-form-grid,.visibility-box,.quantity-fields{grid-template-columns:1fr}.form-actions .btn2{width:100%}}
</style>

<div class="top-actions"><a class="btn2 btn2-primary" href="/saas/modulos.php">Voltar para lista</a><a class="btn2 btn2-ghost" href="/saas/planos.php">Configurar planos</a><?php if ($edit): ?><a class="btn2 btn2-ghost" href="/saas/modulo_form.php">+ Novo item</a><?php endif; ?></div>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<?php if (empty($schema['ok'])): ?><div class="warn">A estrutura comercial ainda não foi confirmada. Volte para <strong>Módulos</strong>, clique em <strong>Recriar/validar estrutura</strong> e confira quais tabelas ou colunas estão pendentes.<?php if (!empty($schema['errors'])): ?><br><br><strong>Detalhe:</strong> <?= e(implode(' | ', array_slice(array_unique($schema['errors']), 0, 5))) ?><?php endif; ?></div><?php endif; ?>

<div class="panel module-form-panel">
  <h3><?= $edit ? 'Dados do item' : 'Cadastrar módulo ou serviço' ?></h3>
  <form method="post" class="module-form-grid" id="moduleForm">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Tipo do item<select class="field" name="item_type" id="item_type"><option value="module" <?= (($edit['item_type'] ?? 'module') === 'module') ? 'selected' : '' ?>>Módulo do sistema</option><option value="service" <?= (($edit['item_type'] ?? '') === 'service') ? 'selected' : '' ?>>Serviço adicional</option></select><div class="field-help">Serviços também aparecem no configurador e podem ter somente valor de implantação.</div></label>
    <label>Ordem de exibição<input class="field" name="sort_order" type="number" value="<?= e((string)($edit['sort_order'] ?? 50)) ?>"><div class="field-help">Itens com menor ordem aparecem primeiro.</div></label>
    <label>Nome<input class="field" name="name" id="module_name" value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="Digitação do cardápio" required></label>
    <label>Código interno<input class="field" name="code" id="module_code" value="<?= e((string)($edit['code'] ?? '')) ?>" placeholder="digitacao-cardapio"><div class="field-help">É gerado automaticamente pelo nome e deve ser único.</div></label>
    <label class="full">Descrição<textarea class="field" name="description" rows="4" placeholder="Explique o que será entregue ou liberado para o cliente."><?= e((string)($edit['description'] ?? '')) ?></textarea></label>

    <label>Custo mensal para a revenda<input class="field" name="monthly_cost" inputmode="decimal" value="<?= e(number_format((float)($edit['monthly_cost'] ?? 0), 2, ',', '.')) ?>" placeholder="0,00"></label>
    <label>Preço mensal padrão de venda<input class="field" name="monthly_price" inputmode="decimal" value="<?= e(number_format((float)($edit['monthly_price'] ?? 0), 2, ',', '.')) ?>" placeholder="0,00"></label>
    <label>Custo inicial para a revenda<input class="field" name="setup_cost" inputmode="decimal" value="<?= e(number_format((float)($edit['setup_cost'] ?? 0), 2, ',', '.')) ?>" placeholder="0,00"></label>
    <label>Preço de implantação ou serviço<input class="field" name="setup_price" inputmode="decimal" value="<?= e(number_format((float)($edit['setup_price'] ?? 0), 2, ',', '.')) ?>" placeholder="0,00"></label>
    <div class="full service-tip"><strong>Exemplo: Digitação do cardápio.</strong> Informe mensalidade <strong>R$ 0,00</strong> e coloque o valor do serviço em <strong>Preço de implantação ou serviço</strong>. Ao ser marcado no configurador, esse valor será somado ao total inicial.</div>

    <div class="full quantity-box">
      <label class="quantity-box-head" style="margin-top:0"><input type="checkbox" name="allow_quantity" id="allow_quantity" value="1" <?= !empty($edit['allow_quantity']) ? 'checked' : '' ?>><span>Permitir informar quantidade no “Monte sua proposta”<small class="field-help" style="display:block">Use para serviços ou módulos vendidos por unidade, terminal, usuário, cardápio, integração ou outro volume.</small></span></label>
      <div class="quantity-fields" id="quantityFields" <?= empty($edit['allow_quantity']) ? 'hidden' : '' ?>>
        <label>Nome da unidade<input class="field" name="quantity_label" value="<?= e((string)($edit['quantity_label'] ?? 'unidade')) ?>" placeholder="Ex.: cardápio, terminal, usuário"><div class="field-help">Será exibido ao lado do seletor de quantidade.</div></label>
        <label>Quantidade mínima<input class="field" name="quantity_min" type="number" min="1" max="9999" value="<?= (int)($edit['quantity_min'] ?? 1) ?>"></label>
        <label>Quantidade padrão<input class="field" name="quantity_default" type="number" min="1" max="9999" value="<?= (int)($edit['quantity_default'] ?? 1) ?>"></label>
        <label>Quantidade máxima<input class="field" name="quantity_max" type="number" min="1" max="9999" value="<?= (int)($edit['quantity_max'] ?? 99) ?>"></label>
      </div>
    </div>

    <div class="full visibility-box">
      <label class="check-line"><input type="checkbox" name="is_public_sale" <?= ($isNew || !empty($edit['is_public_sale'])) ? 'checked' : '' ?>><span>Exibir no configurador “Monte sua proposta”<small class="field-help" style="display:block">Aparecerá na principal e nas revendas, respeitando os preços de cada uma.</small></span></label>
      <label class="check-line"><input type="checkbox" name="is_active" <?= ($isNew || (int)($edit['is_active'] ?? 0) === 1) ? 'checked' : '' ?>><span>Item ativo<small class="field-help" style="display:block">Itens inativos ficam preservados, mas não podem ser vendidos ou liberados.</small></span></label>
    </div>

    <div class="full form-actions"><a class="btn2 btn2-ghost" href="/saas/modulos.php">Cancelar</a><button class="btn2 btn2-dark" type="submit"><?= $edit ? 'Atualizar item' : 'Salvar e publicar item' ?></button></div>
  </form>
</div>
<script>
(() => {
  const name = document.getElementById('module_name');
  const code = document.getElementById('module_code');
  let codeEdited = code.value.trim() !== '';
  code.addEventListener('input', () => { codeEdited = code.value.trim() !== ''; });
  name.addEventListener('input', () => {
    if (codeEdited) return;
    code.value = name.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  });
  const allowQuantity = document.getElementById('allow_quantity');
  const quantityFields = document.getElementById('quantityFields');
  const toggleQuantity = () => { if (quantityFields) quantityFields.hidden = !allowQuantity?.checked; };
  allowQuantity?.addEventListener('change', toggleQuantity);
  toggleQuantity();
})();
</script>
<?php saas_layout_end(); ?>
