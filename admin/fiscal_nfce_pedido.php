<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_nfce_homologation')
  : (int)COMPANY_ID;
$nfcePedidoCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_nfce_homologation');
$nfceContextUrl = static function(string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$nfceMcCsrf = $nfcePedidoCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$nfceMcCtx = $nfcePedidoCrossContext && is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? (string)($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '') : '';
if ($nfcePedidoCrossContext && function_exists('fiscal_multi_company_nfce_homologation_assert')) {
  try { fiscal_multi_company_nfce_homologation_assert($pdo, $cid, 'consultar'); }
  catch (Throwable $e) {
    http_response_code(409);
    admin_topbar('NFC-e em Homologação', 'fiscal_documentos');
    echo '<div class="msg err" style="margin:24px"><strong>V11.35 · NFC-e Multiempresa protegida.</strong><br>' . fiscal_h($e->getMessage()) . '<div style="margin-top:12px"><a class="btn" href="' . fiscal_h($nfceContextUrl('fiscal_configuracoes.php')) . '">Abrir Configurações Fiscais</a></div></div>';
    admin_footer();
    exit;
  }
}
if (!function_exists('nfce_pedido_assert_cross_doc')) {
  function nfce_pedido_assert_cross_doc(?array $doc, bool $cross): void {
    if (!$cross || !$doc) return;
    global $pdo;
    if ((string)($doc['model'] ?? '') !== '65') {
      throw new RuntimeException('V11.35 Multiempresa: somente NFC-e modelo 65 pode ser operada pelo F11.');
    }
    if (!($pdo instanceof PDO && function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, (int)($GLOBALS['cid'] ?? 0), (string)($doc['environment'] ?? '')))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a NFC-e') : 'V11.35 Multiempresa: documento fiscal fora de HOMOLOGAÇÃO permanece bloqueado no F11.');
    }
  }
}
$pedidoId = (int)($_GET['pedido_id'] ?? $_POST['pedido_id'] ?? 0);
if ($pedidoId <= 0) { header('Location: ' . $nfceContextUrl('pedidos.php')); exit; }
$err = '';

try { fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }

try {
  if (isset($_GET['download']) && $_GET['download'] === 'xml') {
    $doc = fiscal_nfce_order_document($pdo, $cid, $pedidoId);
    nfce_pedido_assert_cross_doc($doc, $nfcePedidoCrossContext);
    if (!$doc || trim((string)($doc['xml_raw'] ?? '')) === '') throw new RuntimeException('XML preliminar ainda não foi gerado.');
    $prefix = (($doc['status'] ?? '') === 'AUTORIZADA') ? 'nfce-autorizada' : 'nfce-atual';
    $name = $prefix . '-pedido-' . $pedidoId . '-doc-' . (int)$doc['id'] . '.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo (string)$doc['xml_raw'];
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($nfcePedidoCrossContext) {
      if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
        throw new RuntimeException('Contexto Multiempresa expirado para NFC-e. Atualize a página e tente novamente.');
      }
      if (!in_array($action, ['create_preview','authorize_now','consult_status','recover_authorization'], true)) {
        throw new RuntimeException('V11.35 Multiempresa: ação não homologada para NFC-e pelo F11.');
      }
    }
    if ($action === 'create_preview') {
      $doc = fiscal_nfce_create_or_update_from_order($pdo, $cid, $pedidoId);
      header('Location: ' . $nfceContextUrl('fiscal_nfce_pedido.php?pedido_id=' . $pedidoId . '&ok=' . rawurlencode('Pré-NFC-e gerada em homologação.')));
      exit;
    }
    if ($action === 'authorize_now') {
      $currentDoc = fiscal_nfce_order_document($pdo, $cid, $pedidoId);
      nfce_pedido_assert_cross_doc($currentDoc, $nfcePedidoCrossContext);
      $result = $currentDoc
        ? fiscal_nfce_authorize_document($pdo, $cid, (int)$currentDoc['id'])
        : fiscal_nfce_authorize_order($pdo, $cid, $pedidoId);
      $msg = !empty($result['ok'])
        ? ('NFC-e autorizada em homologação. Protocolo: ' . ($result['protocol'] ?? '-'))
        : ('Retorno da SEFAZ: ' . ($result['code'] ?? '-') . ' - ' . ($result['message'] ?? 'NFC-e não autorizada.'));
      header('Location: ' . $nfceContextUrl('fiscal_nfce_pedido.php?pedido_id=' . $pedidoId . '&ok=' . rawurlencode($msg)));
      exit;
    }
    if ($action === 'consult_status') {
      $currentDoc = fiscal_nfce_order_document($pdo, $cid, $pedidoId);
      nfce_pedido_assert_cross_doc($currentDoc, $nfcePedidoCrossContext);
      if (!$currentDoc) throw new RuntimeException('Nenhuma pré-NFC-e encontrada para consultar.');
      $result = fiscal_nfce_consult_document_status($pdo, $cid, (int)$currentDoc['id']);
      $msg = 'Consulta SEFAZ: ' . ($result['code'] ?? '-') . ' - ' . ($result['message'] ?? 'Consulta concluída.');
      if (!empty($result['protocol'])) $msg .= ' Protocolo: ' . $result['protocol'];
      header('Location: ' . $nfceContextUrl('fiscal_nfce_pedido.php?pedido_id=' . $pedidoId . '&ok=' . rawurlencode($msg)));
      exit;
    }
    if ($action === 'recover_authorization') {
      $currentDoc = fiscal_nfce_order_document($pdo, $cid, $pedidoId);
      nfce_pedido_assert_cross_doc($currentDoc, $nfcePedidoCrossContext);
      if (!$currentDoc) throw new RuntimeException('Nenhuma pré-NFC-e encontrada para recuperar.');
      $result = fiscal_nfce_recover_document_authorization($pdo, $cid, (int)$currentDoc['id']);
      $msg = 'NFC-e recuperada pela chave de acesso. Protocolo: ' . ($result['protocol'] ?? '-');
      header('Location: ' . $nfceContextUrl('fiscal_nfce_pedido.php?pedido_id=' . $pedidoId . '&ok=' . rawurlencode($msg)));
      exit;
    }
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

try { $orderData = fiscal_nfce_order_data($pdo, $cid, $pedidoId); } catch (Throwable $e) { $orderData = ['order'=>[], 'items'=>[]]; $err = $err ?: $e->getMessage(); }
$order = $orderData['order'] ?? [];
$orderItems = $orderData['items'] ?? [];
$doc = null; $nfeDoc = null; $docItems = []; $taxes = []; $payments = []; $cfgProblems = []; $cfgNfeProblems = [];
try {
  $cfgProblems = fiscal_nfce_config_problems($pdo, $cid);
  $cfgNfeProblems = $nfcePedidoCrossContext
    ? ['V11.35 Multiempresa: NF-e continua bloqueada no F11.']
    : (function_exists('fiscal_nfe_config_problems') ? fiscal_nfe_config_problems($pdo, $cid) : ['NF-e ainda não disponível neste pacote.']);
  $doc = fiscal_nfce_order_document($pdo, $cid, $pedidoId);
  nfce_pedido_assert_cross_doc($doc, $nfcePedidoCrossContext);
  $nfeDoc = $nfcePedidoCrossContext ? null : (function_exists('fiscal_nfe_order_document') ? fiscal_nfe_order_document($pdo, $cid, $pedidoId) : null);
  if ($doc) {
    $docItems = fiscal_nfce_document_items($pdo, $cid, (int)$doc['id']);
    $taxes = fiscal_nfce_document_taxes($pdo, $cid, (int)$doc['id']);
    $payments = fiscal_nfce_document_payments($pdo, $cid, (int)$doc['id']);
  }
} catch (Throwable $e) { $err = $err ?: $e->getMessage(); }
$installStatus = [];
try { $installStatus = fiscal_nfce_installation_status($pdo, $cid); } catch (Throwable $e) { $installStatus = []; }

function nfce_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function nfce_qty($v): string { return rtrim(rtrim(number_format((float)$v, 4, ',', '.'), '0'), ','); }
function nfce_btn_disabled(array $cfgProblems, array $installStatus): string {
  return ($cfgProblems || (!empty($installStatus) && (empty($installStatus['vendor_installed']) || !empty($installStatus['missing_extensions']) || empty($installStatus['has_active_certificate']))))
    ? 'disabled title="Resolva os pré-requisitos antes de autorizar/consultar"'
    : '';
}
function nfce_doc_may_be_duplicate(array $doc): bool {
  $code = trim((string)($doc['sefaz_status_code'] ?? ''));
  $msg = function_exists('mb_strtolower') ? mb_strtolower((string)($doc['sefaz_status_message'] ?? '')) : strtolower((string)($doc['sefaz_status_message'] ?? ''));
  return strtoupper((string)($doc['status'] ?? '')) === 'DUPLICIDADE' || in_array($code, ['204','539'], true) || str_contains($msg, 'duplicidade');
}

fiscal_admin_start('Pré-NFC-e do pedido #' . $pedidoId, 'fiscal_nfce');
fiscal_page_header('Pré-NFC-e do pedido #' . $pedidoId, $nfcePedidoCrossContext
  ? 'V11.35 · NFC-e Multiempresa liberada exclusivamente em HOMOLOGAÇÃO para a empresa selecionada pelo F11.'
  : 'Geração e autorização da NFC-e em homologação a partir do pedido, mantendo o fluxo atual do delivery.', [
  ['label'=>'Consulta de pedidos', 'href'=>$nfceContextUrl('pedidos.php'), 'class'=>'btn'],
  ['label'=>'Documentos fiscais', 'href'=>$nfceContextUrl('fiscal_documentos.php'), 'class'=>'btn'],
  ['label'=>'Configurações fiscais', 'href'=>$nfceContextUrl('fiscal_configuracoes.php'), 'class'=>'btn'],
]);
fiscal_tabs('fiscal_documentos');
fiscal_flash();
if ($nfcePedidoCrossContext) echo '<div class="msg ok"><b>V11.35 · NFC-e Multiempresa em HOMOLOGAÇÃO.</b> Somente modelo 65 pode ser gerado, transmitido, consultado e impresso pelo F11. Produção, NF-e, NFS-e, cancelamento, CC-e, inutilização e lote permanecem bloqueados. O estoque já foi movimentado na venda e não será baixado novamente.</div>';
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>

<style>
  .fiscal-doc-menu{position:relative;display:inline-flex;align-items:center;}
  .fiscal-doc-menu-list{display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:40;min-width:170px;background:#fff;border:1px solid #dbe4f0;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.16);padding:7px;gap:6px;}
  .fiscal-doc-menu:hover .fiscal-doc-menu-list,.fiscal-doc-menu:focus-within .fiscal-doc-menu-list{display:grid;}
  .fiscal-doc-menu-list form{margin:0;display:block;width:100%;}
  .fiscal-doc-menu-list .btn{width:100%;justify-content:flex-start;}
</style>

<div class="fiscal-grid-3">
  <div class="fiscal-kpi"><span>Pedido</span><strong>#<?= (int)$pedidoId ?></strong></div>
  <div class="fiscal-kpi"><span>Total do pedido</span><strong><?= nfce_money($order['total'] ?? 0) ?></strong></div>
  <div class="fiscal-kpi"><span>Status fiscal</span><strong><?= fiscal_h($doc['status'] ?? 'Não gerada') ?></strong></div>
</div>

<?php if ($cfgProblems): ?>
  <div class="fiscal-card" style="border-color:#fecaca;background:#fff7ed">
    <h3>Pendências para NFC-e</h3>
    <ul style="margin:0;padding-left:22px;color:#9a3412;font-weight:800;line-height:1.7">
      <?php foreach ($cfgProblems as $p): ?><li><?= fiscal_h($p) ?></li><?php endforeach; ?>
    </ul>
    <div class="fiscal-inline-actions" style="margin-top:12px"><a class="btn btn-primary" href="<?= fiscal_h($nfceContextUrl('fiscal_configuracoes.php?modal=config')) ?>">Ajustar configurações</a><a class="btn" href="<?= fiscal_h($nfceContextUrl('fiscal_configuracoes.php?new_series=1')) ?>">Criar série NFC-e</a></div>
  </div>
<?php endif; ?>

<?php if ($installStatus && (empty($installStatus['vendor_installed']) || !empty($installStatus['missing_extensions']) || empty($installStatus['has_active_certificate']))): ?>
  <div class="fiscal-card" style="border-color:#bfdbfe;background:#eff6ff">
    <h3>Pré-requisitos da autorização NFC-e</h3>
    <ul style="margin:0;padding-left:22px;color:#1e3a8a;font-weight:800;line-height:1.7">
      <li>NFePHP instalado: <?= !empty($installStatus['vendor_installed']) ? 'Sim' : 'Não encontrado em ' . fiscal_h($installStatus['autoload_path'] ?? '') ?></li>
      <li>Extensões PHP: <?= empty($installStatus['missing_extensions']) ? 'OK' : 'Faltando ' . fiscal_h(implode(', ', $installStatus['missing_extensions'])) ?></li>
      <li>Certificado A1 ativo: <?= !empty($installStatus['has_active_certificate']) ? 'OK' : fiscal_h($installStatus['certificate_message'] ?? 'Não encontrado') ?></li>
    </ul>
    <div class="fiscal-mini-help" style="margin-top:10px">Enquanto os pré-requisitos não estiverem prontos, será possível gerar a pré-NFC-e, mas a transmissão para a SEFAZ não será concluída.</div>
  </div>
<?php endif; ?>

<div class="fiscal-card">
  <div class="fiscal-list-head">
    <div><h2 style="margin-bottom:4px">Pedido de origem</h2><div class="fiscal-muted">Cliente: <b><?= fiscal_h($order['nome_cliente'] ?? '-') ?></b> · Pagamento: <b><?= fiscal_h($order['forma_pagamento'] ?? '-') ?></b> · Tipo: <b><?= fiscal_h($order['tipo_entrega'] ?? '-') ?></b></div></div>
    <div class="fiscal-inline-actions" style="justify-content:flex-end">
      <?php if (!$nfcePedidoCrossContext): ?><a class="btn" href="pedido_editar.php?id=<?= (int)$pedidoId ?>">Editar pedido</a><?php endif; ?>
      <?php
        $nfceAutorizadaPedidoFiscal = $doc && strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA';
        $nfeAutorizadaPedidoFiscal = $nfeDoc && strtoupper((string)($nfeDoc['status'] ?? '')) === 'AUTORIZADA';
      ?>
      <?php if (!$nfcePedidoCrossContext && $nfceAutorizadaPedidoFiscal && !$nfeAutorizadaPedidoFiscal): ?>
        <a class="btn btn-primary" href="fiscal_notas_referenciadas.php?q=<?= (int)$pedidoId ?>">Emitir NF-e referenciada</a>
      <?php endif; ?>
      <?php if ((!$nfceAutorizadaPedidoFiscal && !$nfeAutorizadaPedidoFiscal)): ?>
      <div class="fiscal-doc-menu">
        <button class="btn btn-primary" type="button">⚡ Emitir Doc Fiscal ▾</button>
        <div class="fiscal-doc-menu-list">
          <?php if (!$nfceAutorizadaPedidoFiscal && !$nfeAutorizadaPedidoFiscal): ?>
          <form method="post" action="<?= fiscal_h($nfceContextUrl('fiscal_nfce_acao.php')) ?>">
            <?php if ($nfcePedidoCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfceMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= fiscal_h($nfceMcCtx) ?>"><?php endif; ?>
            <input type="hidden" name="pedido_id" value="<?= (int)$pedidoId ?>">
            <input type="hidden" name="action" value="emitir_nfce_rapido">
            <input type="hidden" name="return_to" value="fiscal_nfce_pedido.php?pedido_id=<?= (int)$pedidoId ?>">
            <button class="btn" type="submit" <?= $cfgProblems ? 'disabled title="Resolva as pendências da NFC-e antes de emitir"' : '' ?>>Emitir NFC-e</button>
          </form>
          <?php endif; ?>
          <?php if (!$nfcePedidoCrossContext && !$nfeAutorizadaPedidoFiscal && !$nfceAutorizadaPedidoFiscal): ?>
          <form method="post" action="<?= fiscal_h($nfceContextUrl('fiscal_nfce_acao.php')) ?>">
            <?php if ($nfcePedidoCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfceMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= fiscal_h($nfceMcCtx) ?>"><?php endif; ?>
            <input type="hidden" name="pedido_id" value="<?= (int)$pedidoId ?>">
            <input type="hidden" name="action" value="emitir_nfe_rapido">
            <input type="hidden" name="return_to" value="fiscal_nfce_pedido.php?pedido_id=<?= (int)$pedidoId ?>">
            <button class="btn" type="submit" <?= $cfgNfeProblems ? 'disabled title="Resolva as pendências da NF-e antes de emitir"' : '' ?>>Emitir NF-e</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap"><table class="fiscal-table">
    <thead><tr><th>#</th><th>Produto</th><th>Un.</th><th>Qtd</th><th>Unitário</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($orderItems as $i => $it): ?><tr>
        <td data-label="#"><?= $i+1 ?></td>
        <td data-label="Produto" class="primary"><b><?= fiscal_h($it['product_name'] ?? ('Produto #' . ($it['produto_id'] ?? ''))) ?></b><br><span class="fiscal-muted">Código do produto: <?= fiscal_h(((int)($it['numero_cadastro'] ?? 0)>0) ? (int)$it['numero_cadastro'] : ($it['codigo_firebird'] ?: ($it['produto_id'] ?? ''))) ?></span></td>
        <td data-label="Un."><?= fiscal_h($it['unidade_estoque'] ?? 'UN') ?></td>
        <td data-label="Qtd"><?= nfce_qty($it['qtd'] ?? 0) ?></td>
        <td data-label="Unitário"><?= nfce_money($it['preco_unit'] ?? 0) ?></td>
        <td data-label="Total"><b><?= nfce_money($it['total'] ?? 0) ?></b></td>
      </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($doc): ?>
<div class="fiscal-card">
  <div class="fiscal-list-head">
    <div><h2 style="margin-bottom:4px">Documento fiscal gerado</h2><div class="fiscal-muted">Modelo <?= fiscal_h($doc['model'] ?? '65') ?> · Série <?= fiscal_h($doc['series'] ?? '-') ?> · Número <?= fiscal_h($doc['number'] ?? '-') ?> · Ambiente <?= fiscal_h($doc['environment'] ?? '-') ?></div></div>
    <div class="fiscal-inline-actions">
      <?php if (!$nfcePedidoCrossContext && ($doc['status'] ?? '') !== 'AUTORIZADA'): ?><a class="btn" href="fiscal_nfce_editar.php?doc_id=<?= (int)$doc['id'] ?>">Editar dados fiscais</a><?php endif; ?>
      <a class="btn" data-no-processing href="<?= fiscal_h($nfceContextUrl('fiscal_nfce_acao.php?action=download_xml&doc_id=' . (int)$doc['id'])) ?>"><?= (($doc['status'] ?? '') === 'AUTORIZADA') ? 'Baixar XML autorizado' : 'Baixar XML atual' ?></a>
      <a class="btn" data-no-processing href="<?= fiscal_h($nfceContextUrl('fiscal_nfce_imprimir.php?doc_id=' . (int)$doc['id'] . '&return_to=' . rawurlencode($nfceContextUrl('fiscal_nfce_pedido.php?pedido_id=' . (int)$pedidoId)))) ?>">Imprimir NFC-e</a>
      <form method="post" style="display:inline">
        <?php if ($nfcePedidoCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfceMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= fiscal_h($nfceMcCtx) ?>"><?php endif; ?>
        <input type="hidden" name="pedido_id" value="<?= (int)$pedidoId ?>">
        <input type="hidden" name="action" value="consult_status">
        <button class="btn" type="submit" <?= nfce_btn_disabled($cfgProblems, $installStatus) ?>>Consultar situação</button>
      </form>
      <?php if (($doc['status'] ?? '') !== 'AUTORIZADA'): ?>
      <form method="post" style="display:inline">
        <?php if ($nfcePedidoCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfceMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= fiscal_h($nfceMcCtx) ?>"><?php endif; ?>
        <input type="hidden" name="pedido_id" value="<?= (int)$pedidoId ?>">
        <input type="hidden" name="action" value="recover_authorization">
        <button class="btn" type="submit" <?= nfce_btn_disabled($cfgProblems, $installStatus) ?> title="Consulta a chave na SEFAZ e anexa o protocolo quando a NFC-e já estiver autorizada">Recuperar NFC-e</button>
      </form>
      <form method="post" style="display:inline">
        <?php if ($nfcePedidoCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfceMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= fiscal_h($nfceMcCtx) ?>"><?php endif; ?>
        <input type="hidden" name="pedido_id" value="<?= (int)$pedidoId ?>">
        <input type="hidden" name="action" value="authorize_now">
        <button class="btn btn-primary" type="submit" <?= nfce_btn_disabled($cfgProblems, $installStatus) ?>>Reenviar NFC-e</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="fiscal-grid-4">
    <div class="fiscal-kpi"><span>Chave</span><strong style="font-size:13px;word-break:break-all;color:#0f172a"><?= fiscal_h($doc['access_key'] ?? '-') ?></strong></div>
    <div class="fiscal-kpi"><span>Status SEFAZ</span><strong><?= fiscal_h(trim((string)($doc['sefaz_status_code'] ?? '')) !== '' ? (($doc['sefaz_status_code'] ?? '') . ' - ' . ($doc['sefaz_status_message'] ?? '')) : ($doc['status'] ?? '-')) ?></strong></div>
    <div class="fiscal-kpi"><span>Protocolo</span><strong style="font-size:14px;color:#0f172a"><?= fiscal_h($doc['protocol_number'] ?? '-') ?></strong></div>
    <div class="fiscal-kpi"><span>Total NFC-e</span><strong><?= nfce_money($doc['total_document'] ?? 0) ?></strong></div>
  </div>
  <?php if (($doc['status'] ?? '') !== 'AUTORIZADA'): ?>
    <div class="fiscal-mini-help" style="margin-top:12px">
      Use <b>Reenviar NFC-e</b> para nova tentativa com o XML atual, <b>Consultar situação</b> para verificar a chave na SEFAZ e <b>Recuperar NFC-e</b> quando houver duplicidade ou quando a autorização já existir na SEFAZ mas o protocolo ainda não estiver gravado no sistema.
    </div>
  <?php endif; ?>
</div>

<div class="fiscal-card">
  <h2>Itens fiscais da pré-NFC-e</h2>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table">
    <thead><tr><th>#</th><th>Produto fiscal</th><th>CFOP</th><th>NCM</th><th>Un.</th><th>Qtd</th><th>Total</th><th>Tributação saída</th></tr></thead>
    <tbody>
      <?php foreach ($docItems as $it): $itemTaxes = $taxes[(int)$it['id']] ?? []; ?>
        <tr>
          <td data-label="#"><?= (int)$it['item_number'] ?></td>
          <td data-label="Produto" class="primary"><b><?= fiscal_h($it['description'] ?? '') ?></b><br><span class="fiscal-muted">Cód.: <?= fiscal_h($it['product_code'] ?? $it['product_id'] ?? '') ?></span></td>
          <td data-label="CFOP"><?= fiscal_h($it['cfop'] ?? '') ?></td>
          <td data-label="NCM"><?= fiscal_h($it['ncm'] ?? '') ?></td>
          <td data-label="Un."><?= fiscal_h($it['unidade'] ?? 'UN') ?></td>
          <td data-label="Qtd"><?= nfce_qty($it['quantity'] ?? 0) ?></td>
          <td data-label="Total"><b><?= nfce_money($it['total_value'] ?? 0) ?></b></td>
          <td data-label="Tributação">
            <?php foreach ($itemTaxes as $tx): ?>
              <span class="fiscal-badge" style="margin:2px 2px 2px 0"><?= fiscal_h($tx['tax_group']) ?> <?= fiscal_h(($tx['csosn'] ?: $tx['cst']) ?? '') ?> <?= (float)$tx['rate'] > 0 ? fiscal_h(number_format((float)$tx['rate'],2,',','.').'%') : '' ?></span>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="fiscal-card">
  <h2>Pagamentos</h2>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Tipo</th><th>Descrição</th><th>Indicador</th><th>Cartão/TEF</th><th>Valor</th></tr></thead><tbody>
  <?php foreach ($payments as $pay): ?><tr><td><?= fiscal_h($pay['payment_type'] ?? '') ?></td><td><?= fiscal_h($pay['payment_description'] ?? '') ?></td><td><?= fiscal_h(($pay['payment_indicator'] ?? '0') === '1' ? 'A prazo' : 'À vista') ?></td><td><?= fiscal_h($pay['card_integration'] ?? '') ?> <?= fiscal_h($pay['card_brand'] ?? '') ?></td><td><b><?= nfce_money($pay['amount'] ?? 0) ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php else: ?>
  <div class="fiscal-card"><div class="fiscal-empty-state">Nenhuma pré-NFC-e gerada para este pedido ainda.</div></div>
<?php endif; ?>

<?php fiscal_admin_end(); ?>
