<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/navigation_return.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_nfse_homologation')
  : (int)COMPANY_ID;
$nfseF11 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && $cid !== (int)COMPANY_ID;
if ($nfseF11) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
$nfseMcCsrf = $nfseF11 && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$nfseMcCtxToken = $nfseF11 && is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null)
  ? trim((string)(($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? ''))) : '';
$nfseUrl = static function(string $url) use ($nfseF11): string {
  return $nfseF11 && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$requestedOrderNumber = max(0, (int)($_GET['pedido_numero'] ?? 0));
$selectedOrderId = max(0, (int)($_GET['pedido_id'] ?? 0));
$selectedOrderNumber = 0;
$orderLookupError = '';

if ($requestedOrderNumber > 0) {
  try {
    $hasOrderNumber = company_sequence_has_column($pdo, 'pedidos');
    if ($hasOrderNumber) {
      $stOrderLookup = $pdo->prepare('SELECT id, numero_pedido FROM pedidos WHERE company_id=? AND numero_pedido=? LIMIT 1');
      $stOrderLookup->execute([$cid, $requestedOrderNumber]);
    } else {
      $stOrderLookup = $pdo->prepare('SELECT id FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $stOrderLookup->execute([$cid, $requestedOrderNumber]);
    }
    $orderLookup = $stOrderLookup->fetch(PDO::FETCH_ASSOC);
    if ($orderLookup) {
      $selectedOrderId = (int)$orderLookup['id'];
      $selectedOrderNumber = company_sequence_display($orderLookup, 'pedidos');
    } else {
      $selectedOrderId = 0;
      $orderLookupError = 'Pedido nº ' . $requestedOrderNumber . ' não encontrado para esta empresa.';
    }
  } catch (Throwable $e) {
    $selectedOrderId = 0;
    $orderLookupError = 'Não foi possível localizar o pedido informado: ' . $e->getMessage();
  }
}

$selectedOrder = null;
$selectedOrderItems = [];
$selectedOrderIssues = [];
$selectedServiceCount = 0;
$selectedProductCount = 0;
$selectedContext = null;
$existingDocument = null;
$err = '';
$schemaIssues = [];
$providerCode = nfse_provider_company_code($pdo, $cid);
$providerLabel = nfse_provider_label($providerCode);
$runtimeIssues = nfse_emitter_runtime_issues(true);
$xsdPath = $providerCode === 'PRONIM' ? nfse_pronim_schema_path() : trim((string)(getenv('NFSE_DPS_XSD_PATH') ?: ''));
$xsdReady = $xsdPath !== '' && is_file($xsdPath) && is_readable($xsdPath);

try {
  $readiness = nfse_provider_readiness($pdo, $cid);
} catch (Throwable $e) {
  $err = $e->getMessage();
  $readiness = [
    'checks'=>[], 'ok_count'=>0, 'check_count'=>0, 'cadaster_ready'=>false,
    'products'=>[], 'incomplete_products'=>[], 'profiles'=>[], 'documentation'=>nfse_nacional_document_version('HOMOLOGACAO'),
    'environment'=>'HOMOLOGACAO', 'series'=>null, 'certificate'=>null,
  ];
}

try {
  $schemaIssues = nfse_provider_schema_issues($pdo, $cid);
} catch (Throwable $e) {
  $schemaIssues[] = $e->getMessage();
}

if ($selectedOrderId > 0) {
  try {
    $selectedContext = nfse_emitter_order_context($pdo, $cid, $selectedOrderId);
    $selectedOrder = $selectedContext['order'];
    $selectedOrderNumber = company_sequence_display($selectedOrder, 'pedidos');
    $selectedOrderItems = $selectedContext['items'];
    $selectedOrderIssues = $selectedContext['issues'];
    foreach ($selectedOrderItems as $item) {
      if (fiscal_order_item_is_service($item['is_service'] ?? 0, $item['item_type'] ?? 'PRODUCT')) $selectedServiceCount++;
      else $selectedProductCount++;
    }

    $requiredCompanyChecks = ['nfse_enabled','national_standard','company_identity','municipality','municipal_registration','certificate','series','profiles'];
    if ($providerCode === 'PRONIM') $requiredCompanyChecks = array_merge($requiredCompanyChecks, ['provider_pronim','pronim_endpoint','pronim_series','pronim_profile']);
    foreach (($readiness['checks'] ?? []) as $check) {
      if (in_array((string)($check['key'] ?? ''), $requiredCompanyChecks, true) && empty($check['ok'])) {
        $selectedOrderIssues[] = (string)($check['label'] ?? 'Configuração fiscal') . ': ' . (string)($check['detail'] ?? 'pendente') . '.';
      }
    }
    $selectedOrderIssues = array_values(array_unique(array_filter(array_map('trim', $selectedOrderIssues))));

    if (!$schemaIssues) $existingDocument = nfse_emitter_existing_document($pdo, $cid, $selectedOrderId);
  } catch (Throwable $e) {
    $selectedOrderIssues[] = 'Não foi possível validar o pedido: ' . $e->getMessage();
  }
}

function nfse_page_status_label(string $status): string {
  return match (strtoupper(trim($status))) {
    'PREPARADA' => 'DPS reservada',
    'ASSINADA' => 'DPS assinada',
    'TRANSMITINDO' => 'Transmissão iniciada',
    'AUTORIZADA' => 'NFS-e autorizada',
    'REJEITADA' => 'DPS rejeitada',
    'ERRO_COMUNICACAO' => 'Comunicação incerta',
    'CANCELADA' => 'NFS-e cancelada',
    default => $status !== '' ? $status : 'Não iniciada',
  };
}

function nfse_page_status_class(string $status): string {
  return in_array(strtoupper(trim($status)), ['AUTORIZADA'], true) ? '' : 'off';
}

$okCount = (int)($readiness['ok_count'] ?? 0);
$checkCount = (int)($readiness['check_count'] ?? 0);
$products = is_array($readiness['products'] ?? null) ? $readiness['products'] : [];
$incomplete = is_array($readiness['incomplete_products'] ?? null) ? $readiness['incomplete_products'] : [];
$profiles = is_array($readiness['profiles'] ?? null) ? $readiness['profiles'] : [];
$docVersion = is_array($readiness['documentation'] ?? null) ? $readiness['documentation'] : [];
$environment = strtoupper((string)($readiness['environment'] ?? 'HOMOLOGACAO'));
$productionRequiresConfirmation = !$nfseF11 && $environment === 'PRODUCAO' && $providerCode === 'PRONIM';
$productionLocked = $nfseF11
  ? $environment !== 'HOMOLOGACAO'
  : ($environment === 'PRODUCAO' && $providerCode !== 'PRONIM' && !nfse_provider_production_enabled($pdo, $cid));
$csrf = nfse_emitter_csrf_token('nfse_emitter');
$originInput = trim((string)($_GET['return_to'] ?? ''));
$nfseOrigin = $originInput !== '' ? nexa_navigation_safe_return($originInput, 'pedidos.php') : '';
$selfQuery = 'fiscal_nfse_nacional.php' . ($selectedOrderId > 0 ? ('?pedido_id=' . $selectedOrderId . '&pedido_numero=' . $selectedOrderNumber) : '');
if ($nfseOrigin !== '') $selfQuery .= (str_contains($selfQuery, '?') ? '&' : '?') . 'return_to=' . rawurlencode($nfseOrigin);
$returnTo = $nfseUrl($selfQuery);
$nfseActionUrl = $nfseUrl('fiscal_nfse_acao.php');
require_once __DIR__ . '/../config/os_nfse_return.php';
$nfseFromOs = $selectedOrderId > 0 && os_nfse_is_os_origin($pdo, $cid, $selectedOrderId);
$nfseBackUrl = $nfseOrigin !== '' ? $nfseUrl($nfseOrigin) : $nfseUrl($nfseFromOs ? 'os_fiscal_documentos.php' : 'pedidos.php');
$nfseBackLabel = $nfseOrigin !== '' ? 'Voltar à tela de origem' : ($nfseFromOs ? 'Voltar aos Documentos de O.S.' : 'Voltar aos pedidos');

fiscal_admin_start('Fiscal - NFS-e', 'fiscal_nfse_nacional');
fiscal_page_header('NFS-e · ' . $providerLabel, $providerCode === 'PRONIM' ? 'DPS 1.01 pelo ISS Digital/Pronim, isolada do emissor Nacional.' : 'Geração, assinatura, transmissão e recuperação da DPS no Ambiente Nacional.', [
  ['label'=>'Consultar DPS existente', 'href'=>$nfseUrl('fiscal_nfse_consultar_dps.php'), 'class'=>'btn'],
  ['label'=>'Configurações fiscais', 'href'=>$nfseUrl('fiscal_configuracoes.php?edit_config=1'), 'class'=>'btn'],
  ['label'=>'Nova regra de serviço', 'href'=>$nfseUrl('fiscal_tributacoes.php?new=1'), 'class'=>'btn'],
  ['label'=>'+ Cadastrar serviço', 'href'=>$nfseUrl('produto_form.php'), 'class'=>'btn btn-primary'],
  ['label'=>$nfseBackLabel, 'href'=>$nfseBackUrl, 'class'=>'btn'],
]);
fiscal_tabs('fiscal_nfse_nacional');
fiscal_flash();
if ($orderLookupError !== '') echo '<div class="msg err">' . fiscal_h($orderLookupError) . '</div>';
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>

<div class="fiscal-card">
  <form method="get" action="<?= fiscal_h($nfseUrl('fiscal_nfse_nacional.php')) ?>" class="nfse-order-selector">
    <?php if ($nfseMcCtxToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($nfseMcCtxToken) ?>"><?php endif; ?>
    <?php if ($nfseOrigin !== ''): ?><input type="hidden" name="return_to" value="<?= fiscal_h($nfseOrigin) ?>"><?php endif; ?>
    <div>
      <label for="pedido_numero">Número do pedido para emissão</label>
      <input id="pedido_numero" name="pedido_numero" type="number" min="1" step="1" value="<?= $selectedOrderNumber > 0 ? $selectedOrderNumber : ($requestedOrderNumber > 0 ? $requestedOrderNumber : '') ?>" placeholder="Informe o número sequencial da empresa" required>
      <small class="fiscal-muted">Use o número visível do pedido, sequenciado separadamente para cada empresa.</small>
    </div>
    <button class="btn btn-primary" type="submit">Carregar pedido</button>
    <?php if ($selectedOrderId > 0): ?><a class="btn" href="<?= fiscal_h($nfseUrl('fiscal_nfse_nacional.php')) ?>">Limpar</a><?php endif; ?>
  </form>
</div>

<?php if ($schemaIssues): ?>
  <div class="msg err nfse-schema-warning">
    <b>Emissor ainda não instalado no banco.</b>
    <?= $providerCode === 'PRONIM' ? 'Aplique a migration V11.41 pelo Atualizador do Banco.' : 'Execute o patch do emissor Nacional antes de gerar a DPS.' ?>
    <ul><?php foreach ($schemaIssues as $issue): ?><li><?= fiscal_h($issue) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($runtimeIssues): ?>
  <div class="msg err"><b>Servidor sem requisitos para emitir.</b><ul><?php foreach ($runtimeIssues as $issue): ?><li><?= fiscal_h($issue) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$xsdReady): ?>
  <div class="msg">
    <b>Validação XSD local não configurada.</b>
    A estrutura mínima e a assinatura serão validadas no Nexa, mas a validação completa ficará a cargo da API selecionada.
    <?= $providerCode === 'PRONIM' ? 'A V11.41 inclui os XSDs 1.01 usados para o perfil Pronim.' : 'Para validar antes do envio, instale o conjunto oficial de esquemas e defina NFSE_DPS_XSD_PATH.' ?>
  </div>
<?php endif; ?>

<?php if ($nfseF11): ?>
  <div class="msg"><b>NFS-e Multiempresa V11.51.</b> Empresa operacional #<?= (int)$cid ?> · Provider <?= fiscal_h($providerLabel) ?> · Ambiente <?= fiscal_h($environment) ?>. Pelo F11 esta etapa permite apenas HOMOLOGAÇÃO: preparar/assinar DPS, transmitir, recuperar, consultar, imprimir e baixar XML. Cancelamento é liberado somente para NFS-e Pronim/GOVBR autorizada e originada de Pedido.</div>
<?php endif; ?>

<?php if ($productionRequiresConfirmation): ?>
  <div class="msg err"><b>AMBIENTE DE PRODUÇÃO · Pronim/GOVBR.</b> A DPS pode ser gerada e assinada normalmente, mas a transmissão cria uma NFS-e com validade fiscal. Para transmitir, confirme a emissão pelo botão. Não repita a transmissão se houver comunicação incerta. Em caso de timeout ou comunicação incerta, não retransmita: use a recuperação/consulta.</div>
<?php elseif ($productionLocked): ?>
  <div class="msg err"><b>Produção bloqueada por segurança.</b> Retorne a empresa para HOMOLOGAÇÃO. <?= $nfseF11 ? 'A V11.51 não libera NFS-e em Produção pelo F11.' : 'A produção somente é liberada com a configuração controlada do servidor, depois da homologação formal.' ?></div>
<?php endif; ?>

<?php if ($selectedOrderId > 0): ?>
<div class="fiscal-card nfse-selected-order">
  <div class="fiscal-list-head">
    <div>
      <h2>Pedido #<?= (int)$selectedOrderNumber ?> selecionado para NFS-e</h2>
      <p class="fiscal-muted" style="margin:4px 0 0"><?= fiscal_h($selectedOrder['nome_cliente'] ?? 'Cliente não informado') ?> · <?= $selectedServiceCount ?> serviço(s) · <?= $selectedProductCount ?> mercadoria(s)</p>
    </div>
    <?php $orderReady = !$selectedOrderIssues && $selectedServiceCount > 0 && !$schemaIssues && !$runtimeIssues && !$productionLocked; ?>
    <span class="fiscal-badge <?= $orderReady ? '' : 'off' ?>"><?= $orderReady ? 'Apto para emissão' : 'Com pendências' ?></span>
  </div>

  <?php if ($selectedOrderIssues): ?>
    <div class="msg err"><b>O pedido ainda não pode seguir para a NFS-e:</b><ul><?php foreach ($selectedOrderIssues as $issue): ?><li><?= fiscal_h($issue) ?></li><?php endforeach; ?></ul></div>
  <?php elseif (!$schemaIssues && !$productionLocked): ?>
    <div class="msg">O pedido está apto para gerar, assinar e transmitir a DPS por <b><?= fiscal_h($providerLabel) ?></b> no ambiente <b><?= fiscal_h($environment) ?></b>.</div>
  <?php endif; ?>

  <?php if ($existingDocument):
    $status = strtoupper((string)($existingDocument['status'] ?? 'PREPARADA'));
    $docId = (int)$existingDocument['id'];
    $dpsNumber = (int)($existingDocument['dps_number'] ?? $existingDocument['number'] ?? 0);
    $ambiguous = in_array($status, ['TRANSMITINDO','ERRO_COMUNICACAO'], true);
    $existingDocEnvironment = strtoupper(trim((string)($existingDocument['environment'] ?? '')));
    $documentEnvironmentMismatch = $existingDocEnvironment !== '' && $existingDocEnvironment !== $environment;
    $existingDocProvider = nfse_provider_document_code($pdo, $cid, $docId);
    $nfseCancelF11Allowed = $nfseF11
      && function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_cancel_homologation')
      && function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'fiscal_documentos')
      && (!function_exists('saas_company_has_module') || saas_company_has_module($pdo, $cid, 'fiscal'))
      && $existingDocEnvironment === 'HOMOLOGACAO'
      && strtoupper(trim((string)($existingDocument['source_type'] ?? ''))) === 'PEDIDO'
      && (int)($existingDocument['source_id'] ?? 0) > 0
      && $existingDocProvider === 'PRONIM';
  ?>
    <div class="nfse-emission-panel">
      <div class="nfse-document-head">
        <div>
          <span class="fiscal-muted">Documento fiscal #<?= $docId ?> · DPS <?= fiscal_h((string)($existingDocument['dps_series'] ?? $existingDocument['series'] ?? '')) ?>/<?= $dpsNumber ?></span>
          <h3><?= fiscal_h(nfse_page_status_label($status)) ?></h3>
        </div>
        <span class="fiscal-badge <?= nfse_page_status_class($status) ?>"><?= fiscal_h($status) ?></span>
      </div>

      <div class="nfse-document-grid">
        <div><span>Ambiente</span><strong><?= fiscal_h((string)($existingDocument['environment'] ?? $environment)) ?></strong></div>
        <div><span>ID da DPS</span><strong><?= fiscal_h((string)($existingDocument['dps_id'] ?? 'Será criado na assinatura')) ?></strong></div>
        <div><span>Tentativas</span><strong><?= (int)($existingDocument['attempts'] ?? 0) ?></strong></div>
        <div><span>Último HTTP</span><strong><?= (int)($existingDocument['api_http_status'] ?? 0) ?: '-' ?></strong></div>
        <?php if (!empty($existingDocument['nfse_number'])): ?><div><span>Número NFS-e</span><strong><?= fiscal_h((string)$existingDocument['nfse_number']) ?></strong></div><?php endif; ?>
        <?php if (!empty($existingDocument['access_key'])): ?><div class="wide"><span>Chave de acesso</span><strong><?= fiscal_h((string)$existingDocument['access_key']) ?></strong></div><?php endif; ?>
      </div>

      <?php if (!empty($existingDocument['api_error_message'])): ?>
        <div class="msg err"><b><?= fiscal_h((string)($existingDocument['api_error_code'] ?? 'Retorno')) ?>:</b> <?= fiscal_h((string)$existingDocument['api_error_message']) ?></div>
      <?php endif; ?>

      <?php if ($ambiguous): ?>
        <div class="msg err"><b>Não transmita outra DPS agora.</b> A comunicação anterior pode ter sido processada. Use a recuperação pelo ID da DPS para evitar emissão duplicada.</div>
      <?php endif; ?>
      <?php if ($documentEnvironmentMismatch): ?>
        <div class="msg err"><b>Ambiente diferente.</b> Esta DPS foi criada em <b><?= fiscal_h($existingDocEnvironment) ?></b>, mas a empresa está configurada agora em <b><?= fiscal_h($environment) ?></b>. Não transmita este documento. Use um novo pedido/DPS no ambiente atual.</div>
      <?php endif; ?>
      <?php if ($status === 'REJEITADA' && !$ambiguous): ?>
        <div class="msg info"><b>Correção fiscal:</b> ao retransmitir, o Nexa irá reler o cadastro/regra fiscal atual do serviço, reconstruir e reassinar a <b>mesma DPS <?= $dpsNumber ?></b>. A numeração não será avançada.</div>
      <?php endif; ?>

      <div class="nfse-actions">
        <?php if (in_array($status, ['PREPARADA'], true) && $orderReady): ?>
          <form method="post" action="<?= fiscal_h($nfseActionUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
            <?php if ($nfseF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfseMcCsrf) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="prepare">
            <input type="hidden" name="pedido_id" value="<?= $selectedOrderId ?>">
            <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
            <button class="btn btn-primary" type="submit">Gerar e assinar DPS</button>
          </form>
        <?php endif; ?>

        <?php if (in_array($status, ['ASSINADA','REJEITADA'], true) && $orderReady && !$documentEnvironmentMismatch): ?>
          <form method="post" action="<?= fiscal_h($nfseActionUrl) ?>" onsubmit="return confirm('<?= $productionRequiresConfirmation ? 'ATENÇÃO: esta transmissão será em PRODUÇÃO e poderá gerar uma NFS-e com validade fiscal. Confirmar envio da DPS ' : 'Transmitir a DPS ' ?><?= $dpsNumber ?> por <?= fiscal_h($providerLabel) ?> no ambiente <?= fiscal_h($environment) ?>?');">
            <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
            <?php if ($nfseF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfseMcCsrf) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="emit">
            <input type="hidden" name="doc_id" value="<?= $docId ?>">
            <input type="hidden" name="pedido_id" value="<?= $selectedOrderId ?>">
            <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
            <button class="btn btn-primary" type="submit"><?= $status === 'REJEITADA' ? 'Regerar com cadastro atual e retransmitir a mesma DPS' : ($productionRequiresConfirmation ? 'Transmitir NFS-e em PRODUÇÃO' : ('Transmitir por ' . $providerLabel)) ?></button>
          </form>
        <?php endif; ?>

        <?php if ($ambiguous): ?>
          <form method="post" action="<?= fiscal_h($nfseActionUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
            <?php if ($nfseF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfseMcCsrf) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="recover">
            <input type="hidden" name="doc_id" value="<?= $docId ?>">
            <input type="hidden" name="pedido_id" value="<?= $selectedOrderId ?>">
            <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
            <button class="btn btn-primary" type="submit">Recuperar por ID da DPS</button>
          </form>
        <?php endif; ?>

        <?php if ($status === 'AUTORIZADA'): ?>
          <form method="post" action="<?= fiscal_h($nfseActionUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
            <?php if ($nfseF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfseMcCsrf) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="query">
            <input type="hidden" name="doc_id" value="<?= $docId ?>">
            <input type="hidden" name="pedido_id" value="<?= $selectedOrderId ?>">
            <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
            <button class="btn" type="submit">Consultar novamente</button>
          </form>
        <?php endif; ?>


        <?php if ($status === 'AUTORIZADA' && ((!$nfseF11 && $providerCode === 'PRONIM') || $nfseCancelF11Allowed)): ?>
          <details style="width:100%;margin-top:8px">
            <summary class="btn" style="display:inline-flex;cursor:pointer"><?= $nfseF11 ? 'Cancelar NFS-e · Homologação' : 'Cancelar NFS-e' ?></summary>
            <form method="post" action="<?= fiscal_h($nfseActionUrl) ?>" style="margin-top:12px;display:grid;grid-template-columns:minmax(180px,260px) minmax(300px,1fr);gap:10px;align-items:end" onsubmit="return confirm('ATENÇÃO: o cancelamento será enviado ao ISS Digital e terá efeito fiscal. Confirma o cancelamento da NFS-e <?= fiscal_h((string)($existingDocument['nfse_number'] ?? '')) ?>?');">
              <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
            <?php if ($nfseF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfseMcCsrf) ?>"><?php endif; ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="doc_id" value="<?= $docId ?>">
              <input type="hidden" name="pedido_id" value="<?= $selectedOrderId ?>">
              <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
              <label style="display:flex;flex-direction:column;gap:6px"><span><b>Motivo fiscal</b></span><select name="cancel_reason_code" required><option value="1">1 - Erro na emissão</option><option value="2">2 - Serviço não prestado</option><option value="9">9 - Outros</option></select></label>
              <label style="display:flex;flex-direction:column;gap:6px"><span><b>Justificativa</b> · 15 a 255 caracteres</span><input type="text" name="cancel_reason" minlength="15" maxlength="255" required placeholder="Ex.: Teste controlado de cancelamento da integração Nexa"></label>
              <?php if ($productionRequiresConfirmation): ?>
                <label style="display:flex;flex-direction:column;gap:6px"><span><b>Confirmação de produção</b> · digite CANCELAR</span><input type="text" name="cancel_confirmation" autocomplete="off" required pattern="CANCELAR" placeholder="CANCELAR" style="text-transform:uppercase"></label>
              <?php endif; ?>
              <div><button class="btn btn-primary" type="submit">Enviar evento de cancelamento</button></div>
            </form>
          </details>
        <?php endif; ?>

        <?php if (in_array($status, ['AUTORIZADA','CANCELADA'], true) && (!empty($existingDocument['response_xml']) || !empty($existingDocument['xml_raw']))): ?>
          <a class="btn btn-primary" href="<?= fiscal_h($nfseUrl('fiscal_nfse_imprimir.php?doc_id=' . $docId . '&return_to=' . rawurlencode($nfseOrigin !== '' ? $nfseBackUrl : $returnTo))) ?>">Imprimir NFS-e</a>
          <?php if (!$nfseF11): ?>
            <a class="btn" href="fiscal_documento_whatsapp.php?doc_id=<?= $docId ?>&return_to=<?= rawurlencode($nfseOrigin !== '' ? $nfseBackUrl : $returnTo) ?>">WhatsApp</a>
            <a class="btn" href="email_documento.php?type=fiscal&doc_id=<?= $docId ?>&return_to=<?= rawurlencode($nfseOrigin !== '' ? $nfseBackUrl : $returnTo) ?>">E-mail</a>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($existingDocument['signed_dps_xml'])): ?><a class="btn" href="<?= fiscal_h($nfseUrl('fiscal_nfse_acao.php?action=download_dps&doc_id=' . $docId . '&pedido_id=' . $selectedOrderId)) ?>">Baixar DPS assinada</a><?php endif; ?>
        <?php if (in_array($status, ['AUTORIZADA','CANCELADA'], true) && (!empty($existingDocument['response_xml']) || !empty($existingDocument['xml_raw']))): ?><a class="btn" href="<?= fiscal_h($nfseUrl('fiscal_nfse_acao.php?action=download_nfse&doc_id=' . $docId . '&pedido_id=' . $selectedOrderId)) ?>">Baixar XML autorizado</a><?php endif; ?>
        <?php if ($status === 'CANCELADA' && (!$nfseF11 || $nfseCancelF11Allowed)): ?><a class="btn" href="<?= fiscal_h($nfseUrl('fiscal_nfse_acao.php?action=download_cancel_event&doc_id=' . $docId . '&pedido_id=' . $selectedOrderId)) ?>">Baixar evento de cancelamento</a><?php endif; ?>
      </div>
    </div>
  <?php elseif ($orderReady): ?>
    <div class="nfse-emission-panel">
      <h3>Emitir NFS-e</h3>
      <p class="fiscal-muted">O Nexa preparará a DPS e solicitará a autorização em uma única operação. Em caso de comunicação incerta, consulte/recupere antes de tentar novamente.</p>
      <form method="post" action="<?= fiscal_h($nfseActionUrl) ?>" onsubmit="return confirm('Confirmar emissão de NFS-e? Em produção esta ação pode gerar documento com validade fiscal. Não repita se a comunicação ficar incerta.');">
        <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
            <?php if ($nfseF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($nfseMcCsrf) ?>"><?php endif; ?>
        <input type="hidden" name="action" value="emit_quick">
        <input type="hidden" name="pedido_id" value="<?= $selectedOrderId ?>">
        <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
        <button class="btn btn-primary" type="submit">Gerar e emitir NFS-e</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="fiscal-grid-4 nfse-kpis">
  <div class="fiscal-kpi"><span>Checklist cadastral</span><strong><?= $okCount ?>/<?= $checkCount ?></strong><small><?= !empty($readiness['cadaster_ready']) ? 'Cadastro fiscal completo' : 'Existem pendências' ?></small></div>
  <div class="fiscal-kpi"><span>Ambiente</span><strong><?= fiscal_h($environment) ?></strong><small><?= $environment === 'HOMOLOGACAO' ? 'Ambiente sem validade fiscal.' : ($providerCode === 'PRONIM' ? 'Emissão em produção mediante confirmação no botão.' : 'Produção requer liberação no servidor.') ?></small></div>
  <div class="fiscal-kpi"><span>Regras de serviço</span><strong><?= count($profiles) ?></strong><small>Ativas e classificadas como Serviço.</small></div>
  <div class="fiscal-kpi"><span>Serviços cadastrados no sistema</span><strong><?= count($products) ?></strong><small><?= count($incomplete) ?> com pendência fiscal.</small></div>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head">
    <div>
      <h2>Checklist para emissão</h2>
      <p class="fiscal-muted" style="margin:4px 0 0">Empresa, certificado, série e serviços usados na DPS.</p>
    </div>
    <span class="fiscal-badge <?= !empty($readiness['cadaster_ready']) ? '' : 'off' ?>"><?= !empty($readiness['cadaster_ready']) ? 'Cadastro pronto' : 'Cadastro pendente' ?></span>
  </div>
  <div class="nfse-check-grid">
    <?php foreach (($readiness['checks'] ?? []) as $check): ?>
      <a class="nfse-check <?= !empty($check['ok']) ? 'ok' : 'pending' ?>" href="<?= fiscal_h($check['href'] ?? '#') ?>">
        <span class="nfse-check-icon"><?= !empty($check['ok']) ? '✓' : '!' ?></span>
        <span><strong><?= fiscal_h($check['label'] ?? '') ?></strong><small><?= fiscal_h($check['detail'] ?? '') ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head">
    <div><h2>Serviços e regras vinculadas</h2><p class="fiscal-muted" style="margin:4px 0 0">O retrato fiscal do serviço é usado na montagem da DPS.</p></div>
    <a class="btn btn-sm" href="produtos.php">Abrir produtos</a>
  </div>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table nfse-products-table">
    <thead><tr><th>Serviço</th><th>Regra</th><th>NBS / LC 116</th><th>Cód. nacional</th><th>IBS/CBS</th><th>Status</th><th class="fiscal-actions-head">Ação</th></tr></thead>
    <tbody>
    <?php if (!$products): ?><tr><td colspan="7"><div class="fiscal-empty-state">Nenhum produto foi marcado como Serviço.</div></td></tr><?php endif; ?>
    <?php foreach ($products as $row): $issues = $providerCode === 'PRONIM' ? nfse_pronim_service_product_issues($row) : nfse_nacional_service_product_issues($row); ?>
      <tr>
        <td data-label="Serviço" class="primary"><b>#<?= (int)($row['numero_cadastro'] ?? $row['id'] ?? 0) ?> · <?= fiscal_h($row['nome'] ?? '') ?></b><br><span class="fiscal-muted"><?= !empty($row['ativo']) ? 'Ativo' : 'Inativo' ?></span></td>
        <td data-label="Regra"><?= fiscal_h($row['tax_profile_name'] ?? 'Não vinculada') ?></td>
        <td data-label="NBS / LC 116"><b><?= fiscal_h($row['nbs_code'] ?? '') ?></b><br><span class="fiscal-muted">LC <?= fiscal_h($row['lc116_service_code'] ?? '') ?></span></td>
        <td data-label="Cód. nacional"><?= fiscal_h($row['nfse_national_tax_code'] ?? '') ?><br><span class="fiscal-muted">Ind. <?= fiscal_h($row['nfse_national_operation_indicator'] ?? '') ?></span></td>
        <td data-label="IBS/CBS"><?= fiscal_h($row['ibs_cbs_cst'] ?? '') ?> · <?= fiscal_h($row['ibs_cbs_classification_code'] ?? '') ?></td>
        <td data-label="Status"><?php if (!$issues): ?><span class="fiscal-badge">Completo</span><?php else: ?><span class="fiscal-badge off" title="<?= fiscal_h(implode(' | ', $issues)) ?>"><?= count($issues) ?> pendência(s)</span><?php endif; ?></td>
        <td data-label="Ação" class="fiscal-actions-col"><a class="btn btn-sm" href="produto_form.php?id=<?= (int)($row['id'] ?? 0) ?>">Editar</a></td>
      </tr>
      <?php if ($issues): ?><tr class="nfse-issues-row"><td colspan="7"><b>Pendências:</b> <?= fiscal_h(implode(' · ', $issues)) ?></td></tr><?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="fiscal-card nfse-tech-card">
  <h2>Base técnica adotada</h2>
  <div class="nfse-tech-grid">
    <div><span>Esquemas XSD</span><strong><?= fiscal_h($docVersion['schema'] ?? '') ?></strong></div>
    <div><span>Leiaute DPS/NFS-e</span><strong><?= fiscal_h($docVersion['layout'] ?? '') ?></strong></div>
    <div><span>Lista nacional / NBS</span><strong><?= fiscal_h($docVersion['nbs'] ?? '') ?></strong></div>
    <div><span>Indicadores IBS/CBS</span><strong><?= fiscal_h($docVersion['operation_indicator'] ?? '') ?></strong></div>
    <div><span>DANFSe</span><strong><?= fiscal_h($docVersion['danfse'] ?? '') ?></strong></div>
  </div>
  <div class="msg" style="margin-top:14px">A emissão em homologação está disponível para preparação, assinatura, transmissão síncrona, consulta e recuperação por DPS. Cancelamento, substituição e o DANFSe oficial ficam para a próxima etapa.</div>
</div>

<style>
.nfse-order-selector{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.nfse-order-selector>div{flex:1 1 260px}.nfse-order-selector label{display:block;font-size:12px;font-weight:900;margin-bottom:6px}.nfse-order-selector small{display:block;margin-top:5px}.nfse-order-selector input{width:100%;min-height:44px;border:1px solid #dbe4f0;border-radius:14px;padding:10px 12px;box-sizing:border-box;font:inherit}
.nfse-schema-warning ul,.nfse-selected-order .msg ul{margin:8px 0 0 18px;padding:0}.nfse-schema-warning li,.nfse-selected-order .msg li{margin:4px 0}.nfse-kpis{margin-bottom:14px}.nfse-kpis .fiscal-kpi small{display:block;margin-top:7px;color:#64748b;font-weight:750;line-height:1.35}.nfse-kpis .fiscal-kpi:nth-child(2) strong{font-size:18px}.nfse-check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.nfse-check{display:flex;gap:11px;align-items:flex-start;text-decoration:none;border:1px solid #dbe4f0;border-radius:16px;padding:12px;color:#0f172a;background:#fff}.nfse-check:hover{background:#f8fbff}.nfse-check-icon{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;font-weight:950;flex:0 0 auto}.nfse-check.ok .nfse-check-icon{background:#dcfce7;color:#047857}.nfse-check.pending .nfse-check-icon{background:#fff7ed;color:#c2410c}.nfse-check strong,.nfse-check small{display:block}.nfse-check small{margin-top:3px;color:#64748b;line-height:1.3}.nfse-products-table{min-width:980px}.nfse-issues-row td{background:#fff7ed;color:#9a3412;font-size:12px;padding:8px 12px}.nfse-tech-card .msg{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a}.nfse-tech-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.nfse-tech-grid div{border:1px solid #e2e8f0;border-radius:14px;padding:11px;background:#f8fafc}.nfse-tech-grid span,.nfse-tech-grid strong{display:block}.nfse-tech-grid span{font-size:11px;color:#64748b;font-weight:900;text-transform:uppercase}.nfse-tech-grid strong{margin-top:4px;color:#0f172a;font-size:13px;word-break:break-word}.nfse-emission-panel{margin-top:14px;border:1px solid #cbd5e1;border-radius:18px;padding:16px;background:#f8fafc}.nfse-emission-panel h3{margin:3px 0 8px}.nfse-document-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.nfse-document-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0}.nfse-document-grid div{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#fff;min-width:0}.nfse-document-grid .wide{grid-column:span 2}.nfse-document-grid span,.nfse-document-grid strong{display:block}.nfse-document-grid span{font-size:10px;text-transform:uppercase;font-weight:900;color:#64748b}.nfse-document-grid strong{margin-top:4px;word-break:break-all}.nfse-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}.nfse-actions form{margin:0}.nfse-actions button{white-space:nowrap}@media(max-width:860px){.nfse-check-grid,.nfse-tech-grid,.nfse-document-grid{grid-template-columns:1fr}.nfse-document-grid .wide{grid-column:span 1}.nfse-document-head{display:block}.nfse-document-head .fiscal-badge{margin-top:8px}}
</style>
<?php fiscal_admin_end(); ?>
