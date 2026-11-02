<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/product_maintenance.php';
require_once __DIR__ . '/../config/products_repository.php';

$companyId = (int)COMPANY_ID;
$error = '';
$success = '';
$diagTerm = trim((string)($_GET['produto'] ?? ''));

try {
  $schema = product_maintenance_ensure_schema($pdo);
  if (empty($schema['ok'])) {
    throw new RuntimeException(implode(' ', (array)($schema['errors'] ?? ['Não foi possível preparar a manutenção de produtos.'])));
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    product_maintenance_csrf_check((string)($_POST['csrf'] ?? ''));
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'normalize_visibility') {
      if (empty($_POST['confirm'])) throw new RuntimeException('Marque a confirmação antes de executar a correção.');
      $result = product_maintenance_apply_visibility($pdo, $companyId);
      $updated = (int)($result['updated'] ?? 0);
      $success = $updated > 0
        ? $updated . ' produto(s) importado(s) tiveram a visibilidade normalizada com sucesso.'
        : 'A análise dos produtos importados foi concluída e não havia registros para corrigir.';
    } elseif ($action === 'normalize_category_visibility') {
      if (empty($_POST['confirm'])) throw new RuntimeException('Marque a confirmação antes de executar a correção.');
      $result = product_maintenance_apply_category_visibility($pdo, $companyId);
      $updated = (int)($result['updated'] ?? 0);
      $success = $updated > 0
        ? $updated . ' produto(s) tiveram a configuração de categoria uniformizada com sucesso.'
        : 'Nenhuma categoria com configuração de visibilidade mista foi encontrada.';
    } elseif ($action === 'hide_all_catalog_categories') {
      if (empty($_POST['confirm'])) throw new RuntimeException('Marque a confirmação antes de executar a rotina.');
      $result = product_maintenance_hide_all_catalog_categories($pdo, $companyId);
      $updated = (int)($result['updated'] ?? 0);
      $success = $updated > 0
        ? $updated . ' produto(s) da empresa atual foram marcados para ocultar suas categorias nos dois catálogos.'
        : 'Todas as categorias da empresa atual já estavam ocultas nos dois catálogos.';
    }
  }

  $preview = product_maintenance_visibility_preview($pdo, $companyId, false, 100);
  $categoryPreview = product_maintenance_category_visibility_preview($pdo, $companyId, false, 100);
  $hideCatalogPreview = product_maintenance_hide_catalogs_preview($pdo, $companyId, 100);
  $diagnostics = product_maintenance_diagnose_products($pdo, $companyId, $diagTerm, 30);

  $deliveryListIds = [];
  $deliverySearchIds = [];
  $publicListIds = [];
  $publicSearchIds = [];
  if ($diagTerm !== '') {
    $deliveryListIds = array_map('intval', array_column(products_filter_visible_catalog_categories_db(products_all_db(), 'order'), 'id'));
    $deliverySearchIds = array_map('intval', array_column(products_filter_visible_catalog_categories_db(products_search_db($diagTerm), 'order'), 'id'));
    $publicListIds = array_map('intval', array_column(products_filter_visible_catalog_categories_db(products_all_db(), 'product'), 'id'));
    $publicSearchIds = array_map('intval', array_column(products_filter_visible_catalog_categories_db(products_search_db($diagTerm), 'product'), 'id'));
    foreach ($diagnostics as &$diagRow) {
      $diagId = (int)($diagRow['id'] ?? 0);
      $diagRow['runtime_delivery_list'] = in_array($diagId, $deliveryListIds, true) ? 1 : 0;
      $diagRow['runtime_delivery_search'] = in_array($diagId, $deliverySearchIds, true) ? 1 : 0;
      $diagRow['runtime_public_list'] = in_array($diagId, $publicListIds, true) ? 1 : 0;
      $diagRow['runtime_public_search'] = in_array($diagId, $publicSearchIds, true) ? 1 : 0;
    }
    unset($diagRow);
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
  $preview = [
    'imported_count' => 0, 'inactive_count' => 0, 'affected_count' => 0,
    'mixed_categories' => 0, 'categories_count' => 0, 'samples' => [], 'message' => '',
  ];
  $categoryPreview = ['mixed_categories' => 0, 'affected_count' => 0, 'samples' => []];
  $hideCatalogPreview = ['company_id' => $companyId, 'categories_count' => 0, 'categories_to_change' => 0, 'products_count' => 0, 'active_products_count' => 0, 'records_to_change' => 0, 'samples' => []];
  $diagnostics = [];
  $deliveryListIds = $deliverySearchIds = $publicListIds = $publicSearchIds = [];
}

function maintenance_status_label(int $value): string {
  return $value === 1 ? 'Oculto' : 'Visível';
}
function maintenance_yes_no(int $value): string {
  return $value === 1 ? 'Sim' : 'Não';
}

$catalogRuntimeVersion = function_exists('products_catalog_runtime_version') ? products_catalog_runtime_version() : 'indisponível';
$deliveryBaseUrl = company_catalog_url($company);

admin_topbar('Utilitários - Manutenção de Produtos', 'manutencao_produtos');
?>
<style>
.maint-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin:4px 0 18px}
.maint-page-head h1{margin:0 0 6px;font-size:28px}.maint-page-head p{margin:0;color:#64748b;line-height:1.5}
.maint-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.maint-stat{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;box-shadow:0 8px 22px rgba(15,23,42,.05)}
.maint-stat span{display:block;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.maint-stat strong{display:block;margin-top:7px;font-size:26px;color:#0f172a}
.maint-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:0 8px 22px rgba(15,23,42,.05)}
.maint-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px}.maint-card h2{margin:0 0 5px;font-size:20px}.maint-muted{color:#64748b;line-height:1.5;font-size:13px}
.maint-info{padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;color:#1e3a8a;font-size:13px;line-height:1.5;margin:12px 0}
.maint-warning{padding:12px 14px;border:1px solid #fde68a;background:#fffbeb;border-radius:14px;color:#92400e;font-size:13px;line-height:1.5;margin:12px 0}
.maint-message-ok{padding:12px 14px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:14px;color:#166534;font-weight:750;margin-bottom:14px}
.maint-message-err{padding:12px 14px;border:1px solid #fecaca;background:#fef2f2;border-radius:14px;color:#991b1b;font-weight:750;margin-bottom:14px}
.maint-confirm{display:flex;align-items:flex-start;gap:9px;margin:15px 0;font-size:13px;color:#334155;font-weight:700}.maint-confirm input{margin-top:2px}
.maint-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;margin-top:14px}.maint-table{width:100%;border-collapse:collapse;min-width:760px}.maint-table th,.maint-table td{padding:10px 12px;border-bottom:1px solid #eef2f7;text-align:left;font-size:12px;vertical-align:top}.maint-table th{background:#f8fafc;color:#475569;font-weight:900}.maint-table tr:last-child td{border-bottom:0}.maint-arrow{color:#64748b;font-weight:900}.maint-visible{color:#166534;font-weight:850}.maint-hidden{color:#9a3412;font-weight:850}.maint-bad{color:#b91c1c;font-weight:900}.maint-ok{color:#166534;font-weight:900}
.maint-empty{padding:20px;text-align:center;color:#64748b}.maint-future{border-style:dashed;background:#fbfdff}
.maint-search{display:grid;grid-template-columns:minmax(260px,1fr) auto auto;gap:10px;align-items:end;max-width:900px}.maint-search label{display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:6px}.maint-search input{width:100%;height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:0 12px;background:#fff}.maint-search .btn{height:42px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
.maint-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#475569}.maint-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.maint-actions a{font-size:11px;font-weight:850}.maint-chip{display:inline-flex;padding:3px 8px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:800;margin:2px 4px 2px 0}
@media(max-width:900px){.maint-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.maint-page-head{display:block}.maint-search{grid-template-columns:1fr}}
@media(max-width:520px){.maint-grid{grid-template-columns:1fr}.maint-card{padding:14px}}
</style>

<div class="maint-page-head">
  <div>
    <h1>Manutenção de Produtos</h1>
    <p>Central de utilitários para diagnóstico e correções controladas do cadastro de produtos.</p>
  </div>
</div>

<?php if ($success !== ''): ?><div class="maint-message-ok"><?= product_maintenance_h($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="maint-message-err"><?= product_maintenance_h($error) ?></div><?php endif; ?>

<div class="maint-grid">
  <div class="maint-stat"><span>Importados do Próspero</span><strong><?= (int)($preview['imported_count'] ?? 0) ?></strong></div>
  <div class="maint-stat"><span>Importados a corrigir</span><strong><?= (int)($preview['affected_count'] ?? 0) ?></strong></div>
  <div class="maint-stat"><span>Categorias mistas - geral</span><strong><?= (int)($categoryPreview['mixed_categories'] ?? 0) ?></strong></div>
  <div class="maint-stat"><span>Importados inativos</span><strong><?= (int)($preview['inactive_count'] ?? 0) ?></strong></div>
</div>

<div class="maint-card">
  <div class="maint-card-head">
    <div>
      <h2>Ocultar categorias nos catálogos desta empresa</h2>
      <div class="maint-muted">Rotina de teste para ocultar, de uma só vez, todas as categorias no Catálogo de Pedidos e no Catálogo de Produtos da empresa atualmente logada.</div>
    </div>
  </div>

  <div class="maint-warning">
    <strong>Escopo protegido:</strong> esta operação usa obrigatoriamente o <strong>company_id <?= (int)$companyId ?></strong> e não altera produtos de nenhuma outra empresa.
    Ela modifica somente os campos <strong>Ocultar categoria no catálogo de pedidos</strong> e <strong>Ocultar categoria no catálogo de produtos</strong>.
  </div>

  <div class="maint-grid" style="margin-top:14px">
    <div class="maint-stat"><span>Categorias da empresa</span><strong><?= (int)($hideCatalogPreview['categories_count'] ?? 0) ?></strong></div>
    <div class="maint-stat"><span>Categorias a ocultar</span><strong><?= (int)($hideCatalogPreview['categories_to_change'] ?? 0) ?></strong></div>
    <div class="maint-stat"><span>Produtos da empresa</span><strong><?= (int)($hideCatalogPreview['products_count'] ?? 0) ?></strong></div>
    <div class="maint-stat"><span>Registros a alterar</span><strong><?= (int)($hideCatalogPreview['records_to_change'] ?? 0) ?></strong></div>
  </div>

  <?php if ((int)($hideCatalogPreview['categories_count'] ?? 0) <= 0): ?>
    <div class="maint-empty">Nenhuma categoria/produto foi encontrada para a empresa atual.</div>
  <?php else: ?>
    <div class="maint-info"><strong>Objetivo desta rotina:</strong> depois de ocultar tudo, você poderá voltar ao cadastro e habilitar as categorias uma a uma para testar o catálogo de forma controlada.</div>

    <div class="maint-table-wrap">
      <table class="maint-table">
        <thead><tr><th>Categoria</th><th>Produtos</th><th>Ativos</th><th>Visíveis em Pedidos hoje</th><th>Visíveis em Produtos hoje</th><th>Registros a alterar</th></tr></thead>
        <tbody>
        <?php foreach ((array)($hideCatalogPreview['samples'] ?? []) as $row): ?>
          <tr>
            <td><strong><?= product_maintenance_h($row['categoria'] ?? '') ?></strong></td>
            <td><?= (int)($row['total'] ?? 0) ?></td>
            <td><?= (int)($row['active_total'] ?? 0) ?></td>
            <td><?= (int)($row['order_visible_records'] ?? 0) ?></td>
            <td><?= (int)($row['product_visible_records'] ?? 0) ?></td>
            <td><?= (int)($row['records_to_change'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ((int)($hideCatalogPreview['records_to_change'] ?? 0) <= 0): ?>
      <div class="maint-empty">Todas as categorias desta empresa já estão ocultas nos dois catálogos.</div>
    <?php else: ?>
      <form method="post" style="margin-top:16px" onsubmit="return confirm('Confirma ocultar TODAS as categorias desta empresa no Catálogo de Pedidos e no Catálogo de Produtos? Esta ação não afetará outras empresas.');">
        <input type="hidden" name="csrf" value="<?= product_maintenance_h(product_maintenance_csrf_token()) ?>">
        <input type="hidden" name="action" value="hide_all_catalog_categories">
        <label class="maint-confirm"><input type="checkbox" name="confirm" value="1" required> <span>Confirmo que desejo ocultar todas as categorias <strong>somente da empresa atual</strong> nos dois catálogos.</span></label>
        <button class="btn btn-primary" type="submit">Ocultar categorias nos dois catálogos</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="maint-card">
  <div class="maint-card-head">
    <div>
      <h2>Diagnóstico de produto no catálogo</h2>
      <div class="maint-muted">Pesquise por nome, ID, código do cadastro, código Próspero, código do cardápio ou código de barras.</div>
      <div class="maint-code" style="margin-top:5px">Motor do catálogo: <?= product_maintenance_h($catalogRuntimeVersion) ?></div>
    </div>
  </div>
  <form method="get" class="maint-search">
    <div>
      <label for="diagProduto">Produto</label>
      <input id="diagProduto" name="produto" value="<?= product_maintenance_h($diagTerm) ?>" placeholder="Ex.: 454 ou MARMITEX MENOR" autocomplete="off">
    </div>
    <button class="btn btn-primary" type="submit">Diagnosticar</button>
    <?php if ($diagTerm !== ''): ?><a class="btn" href="utilitarios_manutencao_produtos.php">Limpar</a><?php endif; ?>
  </form>

  <?php if ($diagTerm !== '' && !$diagnostics): ?>
    <div class="maint-warning">Nenhum produto foi encontrado para <strong><?= product_maintenance_h($diagTerm) ?></strong> nesta empresa.</div>
  <?php elseif ($diagnostics): ?>
    <div class="maint-table-wrap">
      <table class="maint-table">
        <thead><tr><th>Produto</th><th>Cadastro</th><th>Categoria</th><th>Delivery / Pedidos</th><th>Catálogo de produtos</th><th>Consulta real</th><th>Diagnóstico</th></tr></thead>
        <tbody>
        <?php foreach ($diagnostics as $row):
          $orderVisible = !empty($row['order_catalog_visible']);
          $productVisible = !empty($row['product_catalog_visible']);
          $mixed = !empty($row['category_mixed_order']) || !empty($row['category_mixed_product']);
          $runtimeDeliveryList = !empty($row['runtime_delivery_list']);
          $runtimeDeliverySearch = !empty($row['runtime_delivery_search']);
          $runtimePublicList = !empty($row['runtime_public_list']);
          $runtimePublicSearch = !empty($row['runtime_public_search']);
          $searchValue = trim((string)($row['numero_cadastro'] ?? ''));
          if ($searchValue === '') $searchValue = trim((string)($row['codigo_firebird'] ?? ''));
          if ($searchValue === '') $searchValue = trim((string)($row['nome'] ?? ''));
          $deliverySearchUrl = $deliveryBaseUrl . (str_contains($deliveryBaseUrl, '?') ? '&' : '?') . http_build_query(['q' => $searchValue, '_nexa' => time()]);
          $deliveryDetailUrl = company_catalog_url($company, 'produto.php?id=' . (int)$row['id'] . '&_nexa=' . time());
        ?>
          <tr>
            <td>
              <strong>#<?= (int)$row['id'] ?> — <?= product_maintenance_h($row['nome'] ?? '') ?></strong><br>
              <a href="produto_form.php?id=<?= (int)$row['id'] ?>">Editar produto</a>
              <div class="maint-actions">
                <a href="<?= product_maintenance_h($deliverySearchUrl) ?>" target="_blank" rel="noopener">Abrir no Delivery</a>
                <a href="<?= product_maintenance_h($deliveryDetailUrl) ?>" target="_blank" rel="noopener">Abrir detalhe</a>
              </div>
            </td>
            <td>
              <span class="maint-chip">Ativo: <?= maintenance_yes_no((int)($row['ativo'] ?? 0)) ?></span>
              <?php if (isset($row['numero_cadastro']) && trim((string)$row['numero_cadastro']) !== ''): ?><div class="maint-code">Cadastro: <?= product_maintenance_h($row['numero_cadastro']) ?></div><?php endif; ?>
              <?php if (isset($row['codigo_firebird']) && trim((string)$row['codigo_firebird']) !== ''): ?><div class="maint-code">Próspero: <?= product_maintenance_h($row['codigo_firebird']) ?></div><?php endif; ?>
              <?php if (isset($row['codigo_cardapio']) && trim((string)$row['codigo_cardapio']) !== ''): ?><div class="maint-code">Cardápio: <?= product_maintenance_h($row['codigo_cardapio']) ?></div><?php endif; ?>
            </td>
            <td>
              <strong><?= product_maintenance_h(($row['categoria'] ?? '') !== '' ? $row['categoria'] : 'Sem categoria') ?></strong><br>
              <span class="maint-code">Ativos: <?= (int)($row['category_active_total'] ?? 0) ?></span>
            </td>
            <td>
              <span class="<?= $orderVisible ? 'maint-ok' : 'maint-bad' ?>"><?= $orderVisible ? 'Deve aparecer' : 'Não deve aparecer' ?></span><br>
              <span class="maint-code">Flag deste registro: <?= maintenance_status_label((int)($row['raw_hide_order'] ?? 0)) ?></span><br>
              <span class="maint-code">Categoria: <?= !empty($row['category_order_hidden']) ? 'Oculta' : 'Visível' ?></span>
            </td>
            <td>
              <span class="<?= $productVisible ? 'maint-ok' : 'maint-bad' ?>"><?= $productVisible ? 'Deve aparecer' : 'Não deve aparecer' ?></span><br>
              <span class="maint-code">Flag deste registro: <?= maintenance_status_label((int)($row['raw_hide_product'] ?? 0)) ?></span><br>
              <span class="maint-code">Categoria: <?= !empty($row['category_product_hidden']) ? 'Oculta' : 'Visível' ?></span>
            </td>
            <td>
              <div class="maint-code">Lista Delivery: <strong class="<?= $runtimeDeliveryList ? 'maint-ok' : 'maint-bad' ?>"><?= $runtimeDeliveryList ? 'Sim' : 'Não' ?></strong></div>
              <div class="maint-code">Pesquisa Delivery: <strong class="<?= $runtimeDeliverySearch ? 'maint-ok' : 'maint-bad' ?>"><?= $runtimeDeliverySearch ? 'Sim' : 'Não' ?></strong></div>
              <div class="maint-code">Lista catálogo: <strong class="<?= $runtimePublicList ? 'maint-ok' : 'maint-bad' ?>"><?= $runtimePublicList ? 'Sim' : 'Não' ?></strong></div>
              <div class="maint-code">Pesquisa catálogo: <strong class="<?= $runtimePublicSearch ? 'maint-ok' : 'maint-bad' ?>"><?= $runtimePublicSearch ? 'Sim' : 'Não' ?></strong></div>
            </td>
            <td>
              <?php if ((int)($row['ativo'] ?? 0) !== 1): ?>
                <span class="maint-bad">Produto inativo.</span>
              <?php elseif ($mixed): ?>
                <span class="maint-bad">Categoria com valores de visibilidade misturados.</span><br><span class="maint-muted">Use a rotina “Coerência da visibilidade por categoria” abaixo.</span>
              <?php elseif ($orderVisible && $runtimeDeliveryList): ?>
                <span class="maint-ok">O produto está retornando pela mesma consulta usada pelo Delivery.</span><br>
                <span class="maint-muted">Se ainda não aparecer na página pública, use “Abrir no Delivery”. O link inclui um parâmetro novo para evitar resposta antiga em cache.</span>
              <?php elseif ($orderVisible): ?>
                <span class="maint-bad">Os dados permitem exibição, mas a consulta real do Delivery não retornou este produto.</span>
              <?php else: ?>
                <span class="maint-bad">A categoria está configurada como oculta.</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="maint-card">
  <div class="maint-card-head">
    <div>
      <h2>Coerência da visibilidade por categoria</h2>
      <div class="maint-muted">Verifica todos os produtos da empresa, inclusive cadastrados manualmente, e identifica categorias em que alguns registros estão visíveis e outros ocultos.</div>
    </div>
  </div>
  <div class="maint-info"><strong>Nova regra:</strong> “Ocultar categoria” é tratado como configuração da categoria inteira. Uma categoria só fica oculta quando todos os produtos ativos dela estão marcados como ocultos. Categorias mistas são normalizadas como visíveis, evitando que apenas alguns produtos desapareçam.</div>

  <?php if ((int)($categoryPreview['mixed_categories'] ?? 0) <= 0): ?>
    <div class="maint-empty">Nenhuma categoria com configuração de visibilidade mista foi encontrada.</div>
  <?php else: ?>
    <div class="maint-warning"><strong><?= (int)$categoryPreview['mixed_categories'] ?> categoria(s)</strong> possuem registros misturados. A correção afetará <?= (int)$categoryPreview['affected_count'] ?> produto(s) e não altera estoque, preço ou tributação.</div>
    <div class="maint-table-wrap">
      <table class="maint-table">
        <thead><tr><th>Categoria</th><th>Produtos ativos</th><th>Delivery/Pedidos ocultos</th><th>Catálogo produtos ocultos</th><th>Registros a ajustar</th></tr></thead>
        <tbody>
        <?php foreach ((array)($categoryPreview['samples'] ?? []) as $row): ?>
          <tr>
            <td><strong><?= product_maintenance_h($row['categoria'] ?? '') ?></strong></td>
            <td><?= (int)($row['active_total'] ?? 0) ?></td>
            <td><?= (int)($row['hide_order'] ?? 0) ?> → <span class="maint-visible">Visível</span></td>
            <td><?= (int)($row['hide_product'] ?? 0) ?> → <span class="maint-visible">Visível</span></td>
            <td><?= (int)($row['affected'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px" onsubmit="return confirm('Confirma a correção das categorias com visibilidade mista? Categorias totalmente ocultas continuarão ocultas.');">
      <input type="hidden" name="csrf" value="<?= product_maintenance_h(product_maintenance_csrf_token()) ?>">
      <input type="hidden" name="action" value="normalize_category_visibility">
      <label class="maint-confirm"><input type="checkbox" name="confirm" value="1" required> <span>Confirmo que desejo uniformizar somente as categorias com valores misturados. Categorias totalmente ocultas serão preservadas.</span></label>
      <button class="btn btn-primary" type="submit">Corrigir categorias mistas</button>
    </form>
  <?php endif; ?>
</div>

<div class="maint-card">
  <div class="maint-card-head">
    <div>
      <h2>Visibilidade dos produtos importados</h2>
      <div class="maint-muted">Mantém a rotina específica para inconsistências originadas na importação do Próspero.</div>
    </div>
  </div>
  <div class="maint-info"><strong>Regra de segurança:</strong> somente produtos identificados como importados do Próspero são alterados. Produtos inativos não são reativados.</div>

  <?php if ((int)($preview['inactive_count'] ?? 0) > 0): ?>
    <div class="maint-warning"><strong>Atenção:</strong> existem <?= (int)$preview['inactive_count'] ?> produto(s) importado(s) inativo(s). Esta rotina não os reativa.</div>
  <?php endif; ?>
  <?php if (!empty($preview['message'])): ?><div class="maint-warning"><?= product_maintenance_h((string)$preview['message']) ?></div><?php endif; ?>

  <?php if ((int)($preview['affected_count'] ?? 0) <= 0): ?>
    <div class="maint-empty">Nenhuma divergência específica da importação foi encontrada.</div>
  <?php else: ?>
    <div class="maint-muted">Prévia dos primeiros <?= min(100, (int)$preview['affected_count']) ?> produto(s) que serão ajustados:</div>
    <div class="maint-table-wrap">
      <table class="maint-table">
        <thead><tr><th>Produto</th><th>Categoria</th><th>Catálogo de pedidos</th><th>Catálogo de produtos</th></tr></thead>
        <tbody>
        <?php foreach ((array)($preview['samples'] ?? []) as $row): ?>
          <tr>
            <td><strong>#<?= (int)$row['id'] ?> — <?= product_maintenance_h($row['nome'] ?? '') ?></strong></td>
            <td><?= product_maintenance_h($row['categoria'] ?? '') ?></td>
            <td><span class="<?= !empty($row['order_before']) ? 'maint-hidden' : 'maint-visible' ?>"><?= maintenance_status_label((int)$row['order_before']) ?></span><span class="maint-arrow"> → </span><span class="<?= !empty($row['order_after']) ? 'maint-hidden' : 'maint-visible' ?>"><?= maintenance_status_label((int)$row['order_after']) ?></span></td>
            <td><span class="<?= !empty($row['product_before']) ? 'maint-hidden' : 'maint-visible' ?>"><?= maintenance_status_label((int)$row['product_before']) ?></span><span class="maint-arrow"> → </span><span class="<?= !empty($row['product_after']) ? 'maint-hidden' : 'maint-visible' ?>"><?= maintenance_status_label((int)$row['product_after']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px" onsubmit="return confirm('Confirma a normalização da visibilidade dos produtos importados do Próspero?');">
      <input type="hidden" name="csrf" value="<?= product_maintenance_h(product_maintenance_csrf_token()) ?>">
      <input type="hidden" name="action" value="normalize_visibility">
      <label class="maint-confirm"><input type="checkbox" name="confirm" value="1" required> <span>Confirmo que desejo corrigir somente a visibilidade dos produtos importados listados pela análise.</span></label>
      <button class="btn btn-primary" type="submit">Corrigir visibilidade dos importados</button>
    </form>
  <?php endif; ?>
</div>

<div class="maint-card maint-future">
  <h2>Central preparada para novas manutenções</h2>
  <div class="maint-muted">Outras correções de produtos podem ser adicionadas nesta mesma tela como rotinas independentes, com diagnóstico, prévia e confirmação.</div>
</div>

<?php admin_footer(); ?>
