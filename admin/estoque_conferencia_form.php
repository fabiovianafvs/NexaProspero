<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/stock_conference.php';

stock_conference_ensure_schema($pdo);
$conferenceCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'stock_conference')
  : (int)COMPANY_ID;
$conferenceCrossCompany = $conferenceCompanyId > 0 && $conferenceCompanyId !== (int)COMPANY_ID;
$conferenceCompanyName = 'Empresa ' . $conferenceCompanyId;
try {
  $stConferenceCompany = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
  $stConferenceCompany->execute([$conferenceCompanyId]);
  $conferenceCompanyName = (string)($stConferenceCompany->fetchColumn() ?: $conferenceCompanyName);
} catch (Throwable $ignored) {}
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  try {
    $ajax = (string)$_GET['ajax'];
    if ($ajax === 'lookup_product') {
      $code = trim((string)($_GET['code'] ?? ''));
      $product = $code !== '' ? stock_conference_find_product($pdo, $conferenceCompanyId, $code) : null;
      echo json_encode([
        'ok' => true,
        'found' => $product !== null,
        'product' => $product ? [
          'id' => (int)$product['id'],
          'code' => (string)$product['product_code'],
          'name' => (string)$product['nome'],
          'barcode' => (string)($product['codigo_barras'] ?? ''),
          'unit' => (string)($product['unidade'] ?? 'UN'),
        ] : null,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    if ($ajax === 'search_products') {
      $query = trim((string)($_GET['q'] ?? ''));
      $rows = stock_conference_search_products($pdo, $conferenceCompanyId, $query, 30);
      $products = array_map(static function(array $row): array {
        return [
          'id' => (int)$row['id'],
          'code' => (string)$row['product_code'],
          'name' => (string)$row['nome'],
          'description' => (string)($row['descricao'] ?? ''),
          'barcode' => (string)($row['codigo_barras'] ?? ''),
          'unit' => (string)($row['unidade'] ?? 'UN'),
        ];
      }, $rows);
      echo json_encode(['ok' => true, 'products' => $products], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    throw new RuntimeException('Consulta inválida.');
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

$msg = trim((string)($_GET['msg'] ?? ''));
$erro = '';
$postedCode = trim((string)($_POST['product_code'] ?? ''));
$postedQty = (string)($_POST['counted_qty'] ?? '1');
$importReport = null;
if (isset($_SESSION['stock_conference_import_report']) && is_array($_SESSION['stock_conference_import_report'])) {
  $flash = $_SESSION['stock_conference_import_report'];
  unset($_SESSION['stock_conference_import_report']);
  if ((int)($flash['conference_id'] ?? 0) === $id && isset($flash['report']) && is_array($flash['report'])) {
    $importReport = $flash['report'];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'save_header') {
      $id = stock_conference_save_header($pdo,$conferenceCompanyId,$id,(string)($_POST['name'] ?? ''),(string)($_POST['counted_at'] ?? ''),(string)($_POST['notes'] ?? ''));
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode('Conferência gravada. Digite os produtos contados ou importe o arquivo do coletor.')) : ('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode('Conferência gravada. Digite os produtos contados ou importe o arquivo do coletor.'))));
      exit;
    }
    if ($action === 'add_item') {
      if ($id <= 0) throw new RuntimeException('Grave os dados da conferência antes de lançar a contagem.');
      $product = stock_conference_add_item($pdo,$conferenceCompanyId,$id,$postedCode,$postedQty);
      $addedQty = stock_qty_br((float)($product['added_qty'] ?? 0));
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode('Quantidade ' . $addedQty . ' acrescentada ao produto ' . (string)$product['nome'] . '.')) : ('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode('Quantidade ' . $addedQty . ' acrescentada ao produto ' . (string)$product['nome'] . '.'))));
      exit;
    }
    if ($action === 'import_txt') {
      if ($id <= 0) throw new RuntimeException('Grave a conferência antes de importar o arquivo do coletor.');
      $upload = $_FILES['collector_file'] ?? null;
      if (!is_array($upload)) throw new RuntimeException('Selecione o arquivo TXT do coletor.');
      $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($uploadError !== UPLOAD_ERR_OK) {
        $messages = [
          UPLOAD_ERR_INI_SIZE => 'O arquivo ultrapassou o limite permitido pelo servidor.',
          UPLOAD_ERR_FORM_SIZE => 'O arquivo ultrapassou o limite de 5 MB.',
          UPLOAD_ERR_PARTIAL => 'O envio do arquivo foi interrompido. Tente novamente.',
          UPLOAD_ERR_NO_FILE => 'Selecione o arquivo TXT do coletor.',
        ];
        throw new RuntimeException($messages[$uploadError] ?? 'Não foi possível receber o arquivo TXT.');
      }
      $originalName = trim((string)($upload['name'] ?? ''));
      if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'txt') throw new RuntimeException('O arquivo deve possuir a extensão .txt.');
      $report = stock_conference_import_collector_txt($pdo, $conferenceCompanyId, $id, (string)($upload['tmp_name'] ?? ''));
      $_SESSION['stock_conference_import_report'] = ['conference_id' => $id, 'report' => $report];
      $message = (int)$report['imported_products'] > 0
        ? 'Arquivo importado. ' . (int)$report['imported_products'] . ' produto(s) tiveram suas quantidades acrescentadas à conferência.'
        : 'Arquivo analisado, mas nenhum produto foi localizado para importação.';
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode($message)) : ('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode($message))));
      exit;
    }
    if ($action === 'remove_item') {
      stock_conference_remove_item($pdo,$conferenceCompanyId,$id,(int)($_POST['item_id'] ?? 0));
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode('Produto removido da conferência.')) : ('estoque_conferencia_form.php?id=' . $id . '&msg=' . urlencode('Produto removido da conferência.'))));
      exit;
    }
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

$conference = $id > 0 ? stock_conference_get($pdo,$conferenceCompanyId,$id) : null;
if ($id > 0 && !$conference) { http_response_code(404); die('Conferência não encontrada.'); }
$items = $conference ? stock_conference_items($pdo,$conferenceCompanyId,$id) : [];
$isProcessed = $conference && (string)$conference['status'] === 'PROCESSED';
$defaultDate = $conference ? date('Y-m-d\TH:i',strtotime((string)$conference['counted_at'])) : date('Y-m-d\TH:i');
admin_topbar('Entradas - Digitação da Conferência','estoque_conferencias');
?>
<style>
.scf-wrap{max-width:1150px;margin:0 auto;padding:22px}.scf-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:16px}.scf-head h1{margin:0;color:#0b1f5b}.scf-muted{color:#64748b;line-height:1.5}.scf-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.scf-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.06)}.scf-card.full{grid-column:1/-1}.scf-fields{display:grid;grid-template-columns:2fr 1fr;gap:12px}.scf-field{display:flex;flex-direction:column;gap:6px}.scf-field.full{grid-column:1/-1}.scf-field label{font-weight:850;color:#334155}.scf-field input,.scf-field textarea{padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px;width:100%;font:inherit}.scf-code-row{display:grid;grid-template-columns:1fr auto;gap:8px}.scf-product-info{min-height:42px;margin-top:2px;padding:10px 12px;border-radius:12px;background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b}.scf-product-info.found{background:#ecfdf5;border-color:#86efac;color:#166534}.scf-product-info.not-found{background:#fff7ed;border-color:#fdba74;color:#9a3412}.scf-product-info.loading{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}.scf-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:850;cursor:pointer;font:inherit}.scf-btn.primary{background:#0b1f5b;border-color:#0b1f5b;color:#fff}.scf-btn.secondary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}.scf-btn.danger{color:#991b1b;border-color:#fecaca}.scf-btn:disabled{opacity:.55;cursor:not-allowed}.scf-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.scf-msg{padding:13px 15px;border-radius:14px;margin-bottom:14px}.scf-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.scf-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.scf-table{width:100%;border-collapse:collapse;min-width:650px}.scf-table th,.scf-table td{padding:11px 12px;border-bottom:1px solid #e2e8f0;text-align:left}.scf-table th{background:#f8fafc;color:#334155}.scf-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:14px}.scf-count{font-size:19px;font-weight:900;color:#0b1f5b}.scf-alert{padding:13px;border-radius:14px;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;margin-bottom:14px}.scf-status{display:inline-flex;padding:6px 10px;border-radius:999px;background:#ecfdf5;color:#065f46;font-size:12px;font-weight:900}.scf-report{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}.scf-metric{padding:14px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0}.scf-metric strong{display:block;font-size:24px;color:#0b1f5b}.scf-detail-list{margin:8px 0 0;padding-left:20px;max-height:180px;overflow:auto}.scf-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.58)}.scf-modal.open{display:flex}.scf-modal-box{width:min(920px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;box-shadow:0 30px 80px rgba(15,23,42,.35)}.scf-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:18px 20px;border-bottom:1px solid #e2e8f0}.scf-modal-head h2{margin:0;color:#0b1f5b}.scf-modal-body{padding:20px}.scf-modal-close{border:0;background:#f1f5f9;border-radius:10px;width:38px;height:38px;font-size:22px;cursor:pointer}.scf-search-row{display:grid;grid-template-columns:1fr auto;gap:8px}.scf-search-state{padding:22px;text-align:center;color:#64748b}.scf-code-sample{padding:12px;border-radius:12px;background:#0f172a;color:#f8fafc;overflow:auto;white-space:pre;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.scf-file{padding:14px;border:1px dashed #94a3b8;border-radius:14px;background:#f8fafc}.scf-file input{width:100%}@media(max-width:760px){.scf-wrap{padding:14px}.scf-grid,.scf-fields,.scf-report{grid-template-columns:1fr}.scf-card.full,.scf-field.full{grid-column:auto}.scf-head{flex-direction:column}.scf-code-row,.scf-search-row{grid-template-columns:1fr}.scf-modal{padding:8px}.scf-modal-box{max-height:96vh;border-radius:14px}}
</style>
<div class="scf-wrap">
  <?php if ($conferenceCrossCompany): ?><div class="scf-msg ok"><strong>V9.2 · Conferência Multiempresa — <?= stock_conference_h($conferenceCompanyName) ?></strong><br>Contagem, busca/importação de produtos e gravações desta conferência usam exclusivamente a empresa selecionada pelo F11.</div><?php endif; ?>
  <?php if ($msg !== ''): ?><div class="scf-msg ok"><?= stock_conference_h($msg) ?></div><?php endif; ?>
  <?php if ($erro !== ''): ?><div class="scf-msg err"><?= stock_conference_h($erro) ?></div><?php endif; ?>
  <div class="scf-head">
    <div><h1><?= $conference?'Conferência #'.(int)$id:'Nova conferência de estoque' ?> · <?= stock_conference_h($conferenceCompanyName) ?></h1><p class="scf-muted">Durante a contagem, o saldo atual não é mostrado para não influenciar a conferência física.</p></div>
    <div class="scf-actions" style="margin-top:0">
      <?php if ($conference && !$isProcessed): ?><button class="scf-btn secondary" type="button" data-open-modal="importModal">Importar TXT do coletor</button><?php endif; ?>
      <a class="scf-btn" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencias.php') : 'estoque_conferencias.php') ?>">Voltar à lista</a>
    </div>
  </div>

  <?php if ($isProcessed): ?><div class="scf-alert">Esta conferência já foi processada e está disponível somente para consulta. <a href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_processar.php?id=' . (int)$id) : ('estoque_conferencia_processar.php?id=' . (int)$id)) ?>">Visualizar o resultado completo</a>.</div><?php endif; ?>

  <?php if ($importReport): ?>
    <section class="scf-card" style="margin-bottom:16px">
      <h2 style="margin-top:0;color:#0b1f5b">Resultado da importação do coletor</h2>
      <div class="scf-report">
        <div class="scf-metric"><strong><?= (int)$importReport['imported_products'] ?></strong>produto(s) importado(s)</div>
        <div class="scf-metric"><strong><?= stock_qty_br((float)$importReport['imported_quantity']) ?></strong>quantidade acrescentada</div>
        <div class="scf-metric"><strong><?= (int)$importReport['not_found_count'] ?></strong>código(s) não localizado(s)</div>
        <div class="scf-metric"><strong><?= (int)$importReport['invalid_count'] ?></strong>linha(s) inválida(s)</div>
      </div>
      <p class="scf-muted">Foram lidas <?= (int)$importReport['total_lines'] ?> linha(s), sendo <?= (int)$importReport['valid_lines'] ?> válida(s) e <?= (int)$importReport['blank_lines'] ?> em branco. Códigos repetidos no arquivo e produtos já existentes na conferência tiveram suas quantidades somadas.</p>
      <?php if (!empty($importReport['not_found_codes'])): ?>
        <div class="scf-alert"><strong>Códigos não localizados:</strong> <?= stock_conference_h(implode(', ', $importReport['not_found_codes'])) ?></div>
      <?php endif; ?>
      <?php if (!empty($importReport['invalid_lines'])): ?>
        <details><summary><strong>Ver linhas inválidas</strong></summary><ul class="scf-detail-list">
          <?php foreach ($importReport['invalid_lines'] as $invalid): ?><li>Linha <?= (int)$invalid['line'] ?>: <?= stock_conference_h($invalid['message']) ?> <code><?= stock_conference_h($invalid['content']) ?></code></li><?php endforeach; ?>
        </ul></details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="scf-grid">
    <section class="scf-card full">
      <h2 style="margin-top:0;color:#0b1f5b">Dados da conferência <?php if ($isProcessed): ?><span class="scf-status">Processada</span><?php endif; ?></h2>
      <form method="post">
        <input type="hidden" name="action" value="save_header"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="scf-fields">
          <div class="scf-field"><label>Nome da conferência</label><input name="name" required maxlength="180" value="<?= stock_conference_h($conference['name'] ?? '') ?>" placeholder="Ex.: Contagem geral - Loja Centro" <?= $isProcessed?'readonly':'' ?>></div>
          <div class="scf-field"><label>Data/hora da contagem</label><input type="datetime-local" name="counted_at" required value="<?= stock_conference_h($defaultDate) ?>" <?= $isProcessed?'readonly':'' ?>></div>
          <div class="scf-field full"><label>Observação</label><textarea name="notes" rows="2" <?= $isProcessed?'readonly':'' ?>><?= stock_conference_h($conference['notes'] ?? '') ?></textarea></div>
        </div>
        <?php if (!$isProcessed): ?><div class="scf-actions"><button class="scf-btn primary" type="submit">Gravar conferência</button></div><?php endif; ?>
      </form>
    </section>

    <?php if ($conference && !$isProcessed): ?>
    <section class="scf-card full">
      <h2 style="margin-top:0;color:#0b1f5b">Lançar contagem</h2>
      <p class="scf-muted">Digite o número do produto ou o código de barras. Quando o mesmo produto for lançado novamente, a nova quantidade será <strong>somada</strong> à quantidade já registrada.</p>
      <form method="post" id="countForm">
        <input type="hidden" name="action" value="add_item"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="scf-fields">
          <div class="scf-field">
            <label>Código do produto ou código de barras</label>
            <div class="scf-code-row"><input id="productCode" name="product_code" required autocomplete="off" value="<?= stock_conference_h($erro !== '' ? $postedCode : '') ?>" placeholder="Digite ou leia o código"><button class="scf-btn" type="button" data-open-modal="searchModal">Buscar</button></div>
            <div id="productDescription" class="scf-product-info">Digite um código para visualizar a descrição do produto.</div>
          </div>
          <div class="scf-field"><label>Quantidade a acrescentar</label><input id="countedQty" name="counted_qty" required inputmode="decimal" value="<?= stock_conference_h($erro !== '' ? $postedQty : '1') ?>" placeholder="0,0000"></div>
        </div>
        <div class="scf-actions"><button class="scf-btn primary" type="submit">Gravar contagem</button><button class="scf-btn secondary" type="button" data-open-modal="importModal">Importar TXT</button><a class="scf-btn" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_processar.php?id=' . (int)$id) : ('estoque_conferencia_processar.php?id=' . (int)$id)) ?>">Conferir e processar</a></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($conference): ?>
    <section class="scf-card full">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><div><h2 style="margin:0;color:#0b1f5b">Produtos contados</h2><p class="scf-muted" style="margin-bottom:0"><?= count($items) ?> produto(s) digitado(s).</p></div><?php if (!$isProcessed && $items): ?><a class="scf-btn primary" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_processar.php?id=' . (int)$id) : ('estoque_conferencia_processar.php?id=' . (int)$id)) ?>">Processar conferência</a><?php endif; ?></div>
      <div class="scf-scroll" style="margin-top:14px"><table class="scf-table"><thead><tr><th>Código</th><th>Produto</th><th>Unidade</th><th>Quantidade contada</th><?php if (!$isProcessed): ?><th>Ação</th><?php endif; ?></tr></thead><tbody>
      <?php if (!$items): ?><tr><td colspan="5" class="scf-muted">Nenhum produto lançado.</td></tr><?php endif; ?>
      <?php foreach ($items as $item): ?><tr><td><strong><?= stock_conference_h($item['product_code']) ?></strong></td><td><?= stock_conference_h($item['nome']) ?></td><td><?= stock_conference_h($item['unidade']) ?></td><td class="scf-count"><?= stock_qty_br($item['counted_qty']) ?></td><?php if (!$isProcessed): ?><td><form method="post" onsubmit="return confirm('Remover este produto da conferência?')"><input type="hidden" name="action" value="remove_item"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><button class="scf-btn danger" type="submit">Remover</button></form></td><?php endif; ?></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($conference && !$isProcessed): ?>
<div class="scf-modal" id="importModal" aria-hidden="true">
  <div class="scf-modal-box" role="dialog" aria-modal="true" aria-labelledby="importModalTitle">
    <div class="scf-modal-head"><div><h2 id="importModalTitle">Importar arquivo do coletor</h2><p class="scf-muted" style="margin:5px 0 0">As quantidades do arquivo serão somadas às quantidades já digitadas.</p></div><button class="scf-modal-close" type="button" data-close-modal aria-label="Fechar">×</button></div>
    <div class="scf-modal-body">
      <p><strong>Padrão de cada linha:</strong> 20 posições para o código, preenchidas com espaços à esquerda, depois um espaço e a quantidade.</p>
      <div class="scf-code-sample">              000020 1</div>
      <p class="scf-muted">No exemplo acima, o código é <strong>000020</strong> e a quantidade é <strong>1</strong>. O arquivo pode conter códigos repetidos; as quantidades serão acumuladas.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_txt"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="MAX_FILE_SIZE" value="5242880">
        <div class="scf-file"><label for="collectorFile"><strong>Arquivo .txt do coletor</strong></label><br><br><input id="collectorFile" type="file" name="collector_file" accept=".txt,text/plain" required></div>
        <div class="scf-actions"><button class="scf-btn secondary" type="submit">Importar arquivo</button><button class="scf-btn" type="button" data-close-modal>Cancelar</button></div>
      </form>
    </div>
  </div>
</div>

<div class="scf-modal" id="searchModal" aria-hidden="true">
  <div class="scf-modal-box" role="dialog" aria-modal="true" aria-labelledby="searchModalTitle">
    <div class="scf-modal-head"><div><h2 id="searchModalTitle">Buscar produto</h2><p class="scf-muted" style="margin:5px 0 0">Pesquise pela descrição, código do produto ou código de barras.</p></div><button class="scf-modal-close" type="button" data-close-modal aria-label="Fechar">×</button></div>
    <div class="scf-modal-body">
      <div class="scf-search-row"><input id="productSearchQuery" type="search" autocomplete="off" placeholder="Digite a descrição, o código ou o código de barras"><button id="productSearchButton" class="scf-btn primary" type="button">Buscar</button></div>
      <div id="productSearchState" class="scf-search-state">A lista começa vazia. Digite um termo e clique em Buscar.</div>
      <div id="productSearchResults" class="scf-scroll" style="display:none;margin-top:14px"><table class="scf-table"><thead><tr><th>Código</th><th>Produto</th><th>Código de barras</th><th>Un.</th><th></th></tr></thead><tbody id="productSearchBody"></tbody></table></div>
    </div>
  </div>
</div>

<script>
(() => {
  const mcCtx = <?= json_encode(function_exists('multi_company_context_request_token') ? multi_company_context_request_token() : '', JSON_UNESCAPED_SLASHES) ?>;
  function withMultiCompanyContext(raw) {
    const url = new URL(raw, window.location.href);
    if (mcCtx) url.searchParams.set('mc_ctx', mcCtx);
    return url.toString();
  }
  const productCode = document.getElementById('productCode');
  const countedQty = document.getElementById('countedQty');
  const productDescription = document.getElementById('productDescription');
  const searchModal = document.getElementById('searchModal');
  const importModal = document.getElementById('importModal');
  const searchQuery = document.getElementById('productSearchQuery');
  const searchButton = document.getElementById('productSearchButton');
  const searchState = document.getElementById('productSearchState');
  const searchResults = document.getElementById('productSearchResults');
  const searchBody = document.getElementById('productSearchBody');
  let lookupTimer = null;
  let lookupSequence = 0;

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    if (modal === searchModal) {
      searchQuery.value = '';
      searchBody.textContent = '';
      searchResults.style.display = 'none';
      searchState.style.display = 'block';
      searchState.textContent = 'A lista começa vazia. Digite um termo e clique em Buscar.';
      setTimeout(() => searchQuery.focus(), 30);
    }
    if (modal === importModal) setTimeout(() => document.getElementById('collectorFile')?.focus(), 30);
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.scf-modal.open')) document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => openModal(document.getElementById(button.dataset.openModal))));
  document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => closeModal(button.closest('.scf-modal'))));
  document.querySelectorAll('.scf-modal').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal); }));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.scf-modal.open').forEach(closeModal); });

  function setProductInfo(text, state = '') {
    productDescription.textContent = text;
    productDescription.className = 'scf-product-info' + (state ? ' ' + state : '');
  }

  async function lookupProduct() {
    const code = productCode.value.trim();
    const sequence = ++lookupSequence;
    if (!code) {
      setProductInfo('Digite um código para visualizar a descrição do produto.');
      return;
    }
    setProductInfo('Localizando produto...', 'loading');
    try {
      const response = await fetch(withMultiCompanyContext('estoque_conferencia_form.php?ajax=lookup_product&code=' + encodeURIComponent(code)), {headers:{'Accept':'application/json'}, cache:'no-store'});
      const data = await response.json();
      if (sequence !== lookupSequence) return;
      if (!response.ok || !data.ok) throw new Error(data.message || 'Falha ao consultar o produto.');
      if (!data.found) {
        setProductInfo('Produto não localizado por esse código ou código de barras.', 'not-found');
        return;
      }
      const barcode = data.product.barcode ? ' • Barras: ' + data.product.barcode : '';
      setProductInfo(data.product.name + ' • Un.: ' + data.product.unit + barcode, 'found');
    } catch (error) {
      if (sequence !== lookupSequence) return;
      setProductInfo(error.message || 'Não foi possível consultar o produto.', 'not-found');
    }
  }

  productCode.addEventListener('input', () => {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(lookupProduct, 320);
  });
  productCode.addEventListener('blur', () => { if (productCode.value.trim()) lookupProduct(); });

  function addCell(row, text) {
    const cell = document.createElement('td');
    cell.textContent = text || '—';
    row.appendChild(cell);
    return cell;
  }

  async function searchProducts() {
    const query = searchQuery.value.trim();
    searchBody.textContent = '';
    searchResults.style.display = 'none';
    searchState.style.display = 'block';
    if (!query) {
      searchState.textContent = 'Digite uma descrição, código do produto ou código de barras.';
      searchQuery.focus();
      return;
    }
    searchButton.disabled = true;
    searchState.textContent = 'Pesquisando produtos...';
    try {
      const response = await fetch(withMultiCompanyContext('estoque_conferencia_form.php?ajax=search_products&q=' + encodeURIComponent(query)), {headers:{'Accept':'application/json'}, cache:'no-store'});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Falha ao pesquisar produtos.');
      if (!data.products.length) {
        searchState.textContent = 'Nenhum produto encontrado.';
        return;
      }
      data.products.forEach(product => {
        const row = document.createElement('tr');
        addCell(row, product.code);
        const nameCell = addCell(row, product.name);
        if (product.description && product.description !== product.name) {
          const detail = document.createElement('small');
          detail.className = 'scf-muted';
          detail.style.display = 'block';
          detail.textContent = product.description;
          nameCell.appendChild(detail);
        }
        addCell(row, product.barcode);
        addCell(row, product.unit);
        const actionCell = document.createElement('td');
        const selectButton = document.createElement('button');
        selectButton.type = 'button';
        selectButton.className = 'scf-btn primary';
        selectButton.textContent = 'Selecionar';
        selectButton.addEventListener('click', () => {
          productCode.value = product.code;
          setProductInfo(product.name + ' • Un.: ' + product.unit + (product.barcode ? ' • Barras: ' + product.barcode : ''), 'found');
          closeModal(searchModal);
          countedQty.focus();
          countedQty.select();
        });
        actionCell.appendChild(selectButton);
        row.appendChild(actionCell);
        searchBody.appendChild(row);
      });
      searchState.style.display = 'none';
      searchResults.style.display = 'block';
    } catch (error) {
      searchState.textContent = error.message || 'Não foi possível pesquisar os produtos.';
    } finally {
      searchButton.disabled = false;
    }
  }

  searchButton.addEventListener('click', searchProducts);
  searchQuery.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchProducts(); } });

  window.addEventListener('load', () => {
    productCode.focus();
    productCode.select();
    if (productCode.value.trim()) lookupProduct();
  });
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>
