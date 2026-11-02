<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/pix_checkout.php';
require_once __DIR__ . '/../config/bank_integrations.php';
require_once __DIR__ . '/../config/card_control.php';
require_once __DIR__ . '/../config/payment_methods_transfer.php';

// V11.25.2: o cadastro operacional de Formas de Pagamento passa a usar a empresa
// selecionada pelo F11. COMPANY_ID continua imutável; somente este escopo homologado
// recebe o company_id operacional.
$paymentScope = 'sales_payment_methods';
$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, $paymentScope)
  : (int)COMPANY_ID;
$paymentCrossContext = function_exists('multi_company_scope_is_cross_company')
  ? multi_company_scope_is_cross_company($pdo, $paymentScope)
  : false;

if ($paymentCrossContext) {
  if (!function_exists('multi_company_context_permission_allowed')
      || !multi_company_context_permission_allowed($pdo, 'gestao_pagamentos')) {
    access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para Formas de Pagamento.');
  }
  if (!function_exists('finance_table_exists') || !finance_table_exists($pdo, 'finance_payment_methods')) {
    access_render_denied('A estrutura de Formas de Pagamento ainda não está disponível. Atualize o banco pela empresa principal antes de usar esta função pelo F11.');
  }
} else {
  // Mantém exatamente o comportamento legado na empresa de entrada. Em contexto F11
  // não executamos ensure_schema/DDL no caminho normal.
  try { fiscal_nfce_ensure_schema($pdo); } catch (Throwable $ignored) {}
}

$paymentMcCsrf = $paymentCrossContext && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
$paymentCtx = is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? $GLOBALS['MULTI_COMPANY_CONTEXT'] : [];
$paymentMcToken = $paymentCrossContext ? trim((string)($paymentCtx['token'] ?? '')) : '';
$pixCheckoutSchemaReady = pix_checkout_ensure_schema($pdo, !$paymentCrossContext);
$bankIntegrationReady = bank_integration_ensure_schema($pdo, !$paymentCrossContext);
$cardControlSchemaReady = card_control_schema_ready($pdo);
$paymentUrl = static function (string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};

$editId = (int)($_GET['edit'] ?? 0);
$newMode = isset($_GET['new']) && (string)$_GET['new'] === '1';
$searchName = trim((string)($_GET['q'] ?? ''));
$erro = '';

// Transferência de parâmetros operacionais portáteis: somente a empresa efetiva.
// Não inclui IDs bancários nem chaves/credenciais Pix de outros clientes.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['nexa_payment_transfer_csrf'])) {
  $_SESSION['nexa_payment_transfer_csrf'] = bin2hex(random_bytes(24));
}
$paymentTransferCsrf = (string)$_SESSION['nexa_payment_transfer_csrf'];

if (($_GET['action'] ?? '') === 'export_payments') {
  try {
    if ($paymentCrossContext && (!function_exists('multi_company_context_permission_allowed')
        || !multi_company_context_permission_allowed($pdo, 'gestao_pagamentos'))) {
      throw new RuntimeException('Sem permissão para exportar formas de pagamento da empresa selecionada.');
    }
    $document = nexa_payment_transfer_export($pdo, $companyId);
    $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="nexa_formas_pagamento_' . $companyId . '_' . date('Ymd_His') . '.json"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $json;
  } catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não foi possível exportar: ' . $e->getMessage();
  }
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'import_payments') {
      if (!hash_equals($paymentTransferCsrf, (string)($_POST['transfer_csrf'] ?? ''))) {
        throw new RuntimeException('Sessão de importação expirada. Atualize a tela e tente novamente.');
      }
      if ($paymentCrossContext && (!function_exists('multi_company_context_csrf_valid')
          || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
        throw new RuntimeException('Sessão Multiempresa expirada. Reabra a empresa pelo F11.');
      }
      if (empty($_POST['confirm_import'])) throw new RuntimeException('Confirme a importação e a atualização de códigos existentes.');
      $upload = $_FILES['payments_file'] ?? null;
      if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Selecione um arquivo JSON válido para importar.');
      }
      $tmp = (string)($upload['tmp_name'] ?? '');
      if ((int)($upload['size'] ?? 0) > 1048576 || (int)($upload['size'] ?? 0) <= 0
          || !is_uploaded_file($tmp) || strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'json') {
        throw new RuntimeException('Envie somente um arquivo .json do Nexa, até 1 MB.');
      }
      $text = file_get_contents($tmp);
      if ($text === false) throw new RuntimeException('Não foi possível abrir o arquivo enviado.');
      $importDoc = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
      if (!is_array($importDoc)) throw new RuntimeException('Arquivo de importação inválido.');
      $result = nexa_payment_transfer_import($pdo, $companyId, $importDoc);
      header('Location: ' . $paymentUrl('gestao_formas_pagamento.php?ok=' . rawurlencode(
        'Formas de pagamento importadas: ' . $result['created'] . ' nova(s), ' . $result['updated'] . ' atualizada(s).
        Confira vínculos bancários e configurações Pix/cartões antes de usar.')));
      exit;
    }
    if ($action === 'save_payment') {
      if ($paymentCrossContext && (!function_exists('multi_company_context_csrf_valid')
          || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
        throw new RuntimeException('Sessão Multiempresa expirada. Reabra a empresa pelo F11 e tente novamente.');
      }
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0 && !gestao_payment_method($pdo, $companyId, $id)) {
        throw new RuntimeException('Forma de pagamento inválida para a empresa selecionada.');
      }
      $name = trim((string)($_POST['name'] ?? ''));
      $code = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $name ?: 'FORMA')));
      if (trim((string)($_POST['code'] ?? '')) !== '') $code = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', (string)$_POST['code']));
      if ($name === '') throw new RuntimeException('Informe o nome da forma de pagamento.');
      $type = (string)($_POST['type'] ?? 'OUTRO');
      if (!in_array($type, ['DINHEIRO','PIX','CARTAO','BOLETO','PRAZO','VALE','OUTRO'], true)) $type = 'OUTRO';
      $bankId = (int)($_POST['default_bank_account_id'] ?? 0) ?: null;
      $autoRegisterBoleto = ($type === 'BOLETO' && !empty($_POST['auto_register_boleto'])) ? 1 : 0;
      $chartId = (int)($_POST['default_chart_account_id'] ?? 0) ?: null;
      $costId = (int)($_POST['default_cost_center_id'] ?? 0) ?: null;
      $pixAutoQr = ($type === 'PIX' && !empty($_POST['pix_auto_qr_enabled'])) ? 1 : 0;
      $pixProvider = $pixAutoQr ? strtoupper(trim((string)($_POST['pix_provider'] ?? 'BANK'))) : '';
      $pixProvidersAllowed = array_merge(['BANK'], array_keys(bank_integration_providers()));
      if ($pixAutoQr && !in_array($pixProvider, $pixProvidersAllowed, true)) $pixProvider = 'BANK';
      $pixKey = trim((string)($_POST['pix_key'] ?? ''));
      $pixExpiration = max(60, min(86400, (int)($_POST['pix_expiration_seconds'] ?? 300)));
      $pixApiBaseUrl = trim((string)($_POST['pix_api_base_url'] ?? ''));
      if ($type === 'VALE') {
        $code = 'VALE';
        $bankId = null; $chartId = null; $costId = null;
        $autoRegisterBoleto = 0; $pixAutoQr = 0; $pixProvider = '';
        $pixKey = ''; $pixApiBaseUrl = '';
      }
      if ($pixAutoQr && !$pixCheckoutSchemaReady) throw new RuntimeException('A estrutura do Pix automático ainda não está instalada. Acesse esta tela pela empresa principal para atualizar o banco.');
      if ($pixAutoQr && !$bankId) throw new RuntimeException('Selecione o Banco/Caixa padrão que possui a integração bancária do Pix.');
      if ($pixAutoQr && $pixProvider === 'BANK' && $bankIntegrationReady && $bankId) {
        $pixServiceCfg = bank_integration_service_config($pdo, $companyId, (int)$bankId, 'PIX_RECEBIMENTO');
        if (!$pixServiceCfg || empty($pixServiceCfg['enabled'])) throw new RuntimeException('Configure e habilite o serviço Pix Recebimentos em Financeiro → Caixas e Bancos → Integrações para a conta selecionada.');
      }
      if ($pixAutoQr && $pixKey === '') throw new RuntimeException('Informe a Chave Pix para gerar o QR Code dinâmico.');
      if ($pixAutoQr && $pixApiBaseUrl !== '' && filter_var($pixApiBaseUrl, FILTER_VALIDATE_URL) === false) throw new RuntimeException('A URL da API Pix é inválida.');

      // Nunca aceita IDs pertencentes a outra empresa, mesmo em POST manipulado.
      if ($bankId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1');
        $st->execute([$companyId, $bankId]);
        if ((int)$st->fetchColumn() < 1) throw new RuntimeException('Banco/Caixa padrão inválido para a empresa selecionada.');
      }
      if ($chartId) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 AND movement_type IN ('CREDITO','AMBOS')");
        $st->execute([$companyId, $chartId]);
        if ((int)$st->fetchColumn() < 1) throw new RuntimeException('Plano de contas padrão inválido para a empresa selecionada.');
      }
      if ($costId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_cost_centers WHERE company_id=? AND id=? AND active=1');
        $st->execute([$companyId, $costId]);
        if ((int)$st->fetchColumn() < 1) throw new RuntimeException('Centro de custo padrão inválido para a empresa selecionada.');
      }
      $nfcePaymentType = str_pad(substr(preg_replace('/\D+/', '', (string)($_POST['nfce_payment_type'] ?? '')), 0, 2), 2, '0', STR_PAD_LEFT);
      if ($nfcePaymentType === '00') $nfcePaymentType = '';
      if ($pixAutoQr && $nfcePaymentType === '') $nfcePaymentType = '17';
      if ($type === 'VALE') $nfcePaymentType = '21';
      $nfcePaymentIndicator = in_array((string)($_POST['nfce_payment_indicator'] ?? '0'), ['0','1'], true) ? (string)$_POST['nfce_payment_indicator'] : '0';
      $nfceCardIntegration = in_array((string)($_POST['nfce_card_integration'] ?? ''), ['','1','2'], true) ? (string)$_POST['nfce_card_integration'] : '';
      $nfceCardBrand = str_pad(substr(preg_replace('/\D+/', '', (string)($_POST['nfce_card_brand'] ?? '')), 0, 2), 2, '0', STR_PAD_LEFT);
      if ($nfceCardBrand === '00') $nfceCardBrand = '';
      $nfceCardCnpj = substr(preg_replace('/\D+/', '', (string)($_POST['nfce_card_cnpj'] ?? '')), 0, 14);
      $generateReceivable = $type === 'VALE' ? 0 : (!empty($_POST['generate_receivable']) ? 1 : 0);
      $allowInstallments = $type === 'VALE' ? 0 : (!empty($_POST['allow_installments']) ? 1 : 0);
      $affectsCash = $type === 'VALE' ? 0 : (!empty($_POST['affects_cash']) ? 1 : 0);
      $requireOpenCash = $type === 'VALE' ? 0 : (!empty($_POST['require_open_cash']) ? 1 : 0);
      $allowChange = $type === 'VALE' ? 0 : (!empty($_POST['allow_change']) ? 1 : 0);
      $cardControlEnabled = ($type === 'CARTAO' && !empty($_POST['card_control_enabled'])) ? 1 : 0;
      $cardAcquirerName = $cardControlEnabled ? trim((string)($_POST['card_acquirer_name'] ?? '')) : '';
      $cardFeeChartId = $cardControlEnabled ? ((int)($_POST['card_fee_chart_account_id'] ?? 0) ?: null) : null;
      if ($cardControlEnabled) {
        if (!$cardControlSchemaReady) throw new RuntimeException('A estrutura do Controle de Cartões ainda não foi instalada. Execute o Atualizador do Banco.');
        if (!$bankId) throw new RuntimeException('Selecione o Banco/Caixa padrão que receberá as liquidações do cartão.');
        if (!$chartId) throw new RuntimeException('Selecione o Plano de Contas padrão da venda com cartão.');
        if ($cardFeeChartId) {
          $st = $pdo->prepare("SELECT COUNT(*) FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 AND movement_type IN ('DEBITO','AMBOS')");
          $st->execute([$companyId,$cardFeeChartId]);
          if ((int)$st->fetchColumn() < 1) throw new RuntimeException('Plano de Contas das taxas inválido para a empresa selecionada.');
        }
        // O cartão controlado possui agenda própria; não cria Contas a Receber comum nem movimento na venda.
        $generateReceivable = 0;
        $allowInstallments = 0;
        $affectsCash = 0;
      }
      if ($type === 'VALE') { $nfcePaymentIndicator = '0'; $nfceCardIntegration = ''; $nfceCardBrand = ''; $nfceCardCnpj = ''; }
      $data = [
        $companyId, $code, $name, $type, $bankId, $chartId, $costId,
        $generateReceivable,
        $autoRegisterBoleto,
        $allowInstallments,
        max(1, min(120, (int)($_POST['default_installments'] ?? 1))),
        max(0, min(365, (int)($_POST['first_due_days'] ?? 30))),
        !empty($_POST['keep_due_day']) ? 1 : 0,
        $affectsCash,
        $requireOpenCash,
        $allowChange,
        !empty($_POST['is_active']) ? 1 : 0,
        (int)($_POST['sort_order'] ?? 0),
        trim((string)($_POST['notes'] ?? '')) ?: null,
      ];
      if ($id > 0) {
        $data[] = $id; $data[] = $companyId;
        $pdo->prepare('UPDATE finance_payment_methods SET code=?, name=?, type=?, default_bank_account_id=?, default_chart_account_id=?, default_cost_center_id=?, generate_receivable=?, auto_register_boleto=?, allow_installments=?, default_installments=?, first_due_days=?, keep_due_day=?, affects_cash=?, require_open_cash=?, allow_change=?, is_active=?, sort_order=?, notes=?, updated_at=NOW() WHERE id=? AND company_id=?')
          ->execute(array_slice($data, 1));
        $savedId = $id;
      } else {
        $pdo->prepare('INSERT INTO finance_payment_methods (company_id, code, name, type, default_bank_account_id, default_chart_account_id, default_cost_center_id, generate_receivable, auto_register_boleto, allow_installments, default_installments, first_due_days, keep_due_day, affects_cash, require_open_cash, allow_change, is_active, sort_order, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute($data);
        $savedId = (int)$pdo->lastInsertId();
      }
      if ($savedId > 0 && fiscal_column_exists($pdo, 'finance_payment_methods', 'nfce_payment_type')) {
        $pdo->prepare('UPDATE finance_payment_methods SET nfce_payment_type=?, nfce_payment_indicator=?, nfce_card_integration=?, nfce_card_brand=?, nfce_card_cnpj=?, updated_at=NOW() WHERE id=? AND company_id=?')
          ->execute([$nfcePaymentType ?: null, $nfcePaymentIndicator, $nfceCardIntegration ?: null, $nfceCardBrand ?: null, $nfceCardCnpj ?: null, $savedId, $companyId]);
      }
      if ($savedId > 0 && $pixCheckoutSchemaReady && pix_checkout_column_exists($pdo, 'finance_payment_methods', 'pix_auto_qr_enabled')) {
        $pdo->prepare('UPDATE finance_payment_methods SET pix_auto_qr_enabled=?, pix_provider=?, pix_key=?, pix_expiration_seconds=?, pix_api_base_url=?, updated_at=NOW() WHERE id=? AND company_id=?')
          ->execute([$pixAutoQr, $pixProvider !== '' ? $pixProvider : null, $pixKey !== '' ? $pixKey : null, $pixExpiration, $pixApiBaseUrl !== '' ? rtrim($pixApiBaseUrl, '/') : null, $savedId, $companyId]);
      }
      if ($savedId > 0 && $cardControlSchemaReady) {
        $pdo->prepare('UPDATE finance_payment_methods SET card_control_enabled=?, card_acquirer_name=?, card_fee_chart_account_id=?, updated_at=NOW() WHERE id=? AND company_id=?')
          ->execute([$cardControlEnabled, $cardAcquirerName !== '' ? $cardAcquirerName : null, $cardFeeChartId, $savedId, $companyId]);
        if ($cardControlEnabled) card_control_replace_payment_plans($pdo, $companyId, $savedId, $_POST);
        else $pdo->prepare('UPDATE finance_card_payment_plans SET active=0,updated_at=NOW() WHERE company_id=? AND payment_method_id=?')->execute([$companyId,$savedId]);
      }
      header('Location: ' . $paymentUrl('gestao_formas_pagamento.php?ok=' . rawurlencode('Forma de pagamento salva.')));
      exit;
    }
  }
} catch (Throwable $e) { $erro = $e->getMessage(); }

$methods = gestao_payment_methods($pdo, $companyId, false);
if ($searchName !== '') {
  $normalizeSearch = static function (string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) $value = $ascii;
    return strtolower($value);
  };
  $needle = $normalizeSearch($searchName);
  $methods = array_values(array_filter($methods, static function (array $method) use ($needle, $normalizeSearch): bool {
    return strpos($normalizeSearch((string)($method['name'] ?? '')), $needle) !== false;
  }));
}
// No F11 não reutiliza user_cash_accounts da empresa de entrada: a permissão efetiva
// gestao_pagamentos já foi validada e a lista deve refletir os Caixa/Bancos ativos da
// própria empresa operacional.
$banks = $paymentCrossContext
  ? finance_query_all($pdo, 'SELECT id, name, type FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name ASC', [$companyId])
  : finance_bank_options($pdo, $companyId);
$charts = finance_chart_options($pdo, $companyId, 'CREDITO');
$costs = finance_cost_center_options($pdo, $companyId);
$debitCharts = finance_chart_options($pdo, $companyId, 'DEBITO');
$edit = $editId ? gestao_payment_method($pdo, $companyId, $editId) : null;
$cardPlans = ($edit && $cardControlSchemaReady) ? card_control_payment_plans($pdo, $companyId, (int)$edit['id'], false) : [];
if ($editId > 0 && !$edit) {
  $erro = $erro ?: 'Forma de pagamento não encontrada.';
}
$showForm = $newMode || ($editId > 0 && $edit);

gestao_admin_start('Formas de Pagamento', 'gestao_pagamentos');
gestao_page_header('Formas de Pagamento', 'Configure banco/caixa, plano de contas e comportamento financeiro para Pix, dinheiro, cartões e prazo.', [
  ['label'=>'+ Nova forma', 'href'=>$paymentUrl('gestao_formas_pagamento.php?new=1#form'), 'class'=>'btn btn-primary'],
]);
gestao_tabs('gestao_pagamentos', false);
if ($erro) echo '<div class="msg err">' . gestao_h($erro) . '</div>';
gestao_flash();
$creSettings = function_exists('finance_settings') ? finance_settings($pdo, $companyId) : [];
?>
<?php if ($paymentCrossContext): ?>
<div class="msg ok" style="margin-bottom:14px"><strong>V11.25.2 · Formas de Pagamento Multiempresa.</strong> As alterações abaixo serão gravadas somente na empresa selecionada pelo F11. Nesta etapa você já pode ajustar <strong>Exige caixa aberto</strong> para testar o Pedido PDV da Empresa 2; o Caixa operacional Multiempresa será homologado separadamente.</div>
<?php endif; ?>
<?php if (!empty($creSettings['cre_rules_enabled'])): ?>
<div class="msg ok" style="margin-bottom:14px"><strong>Configurações CRE ativas.</strong> Para formas que geram Contas a Receber, os vencimentos globais da empresa selecionada prevalecem sobre “Primeiro vencimento em dias” e “Manter o dia do vencimento”.<?= !$paymentCrossContext ? ' <a href="configuracoes_cre.php"><strong>Abrir Configurações CRE</strong></a>' : '' ?></div>
<?php endif; ?>
<?php if (!$showForm): ?>
  <div class="mg-card" style="margin-bottom:14px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
      <strong>Importação e exportação</strong>
      <a class="btn" data-no-processing download href="<?= gestao_h($paymentUrl('gestao_formas_pagamento.php?action=export_payments')) ?>">Exportar formas de pagamento</a>
    </div>
    <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-top:10px">
      <input type="hidden" name="action" value="import_payments">
      <input type="hidden" name="transfer_csrf" value="<?= gestao_h($paymentTransferCsrf) ?>">
      <?php if ($paymentCrossContext): ?>
        <input type="hidden" name="mc_ctx" value="<?= gestao_h($paymentMcToken) ?>">
        <input type="hidden" name="mc_csrf" value="<?= gestao_h($paymentMcCsrf) ?>">
      <?php endif; ?>
      <label style="flex:1;min-width:230px">Arquivo JSON do Nexa
        <input type="file" name="payments_file" accept=".json,application/json" required>
      </label>
      <label style="flex-basis:100%;font-size:12px;color:#475569"><input type="checkbox" name="confirm_import" value="1" required> Confirmo que códigos existentes serão atualizados nesta empresa. Vínculos bancários, Pix automático e controle de cartões não são copiados.</label>
      <button class="btn btn-primary" type="submit">Importar formas de pagamento</button>
    </form>
  </div>
<?php endif; ?>
<?php if ($showForm): ?>
  <form method="post" class="mg-card" id="form">
    <input type="hidden" name="action" value="save_payment">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <?php if ($paymentCrossContext): ?>
      <input type="hidden" name="mc_ctx" value="<?= gestao_h($paymentMcToken) ?>">
      <input type="hidden" name="mc_csrf" value="<?= gestao_h($paymentMcCsrf) ?>">
    <?php endif; ?>
    <h2><?= $edit ? 'Editar forma' : 'Nova forma' ?></h2>
    <div class="mg-form-grid">
      <label>Nome *<input name="name" required value="<?= gestao_h($edit['name'] ?? '') ?>" placeholder="Ex.: Pix, Dinheiro, Cartão"></label>
      <label>Código interno<input name="code" value="<?= gestao_h($edit['code'] ?? '') ?>" placeholder="Ex.: PIX"></label>
      <label>Tipo
        <select name="type" id="paymentType">
          <?php foreach (['DINHEIRO'=>'Dinheiro','PIX'=>'Pix','CARTAO'=>'Cartão','BOLETO'=>'Boleto','PRAZO'=>'A prazo','VALE'=>'Vale / crédito do cliente','OUTRO'=>'Outro'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= (($edit['type'] ?? '')===$k)?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2" id="voucherTypeHelp" style="display:none;border:1px solid #bbf7d0;border-radius:16px;background:#f0fdf4;padding:12px;color:#166534"><strong>Vale / crédito do cliente.</strong> Esta forma não gera nova entrada de caixa nem conta a receber. O saldo vem dos vales do cliente e pode ser usado total ou parcialmente.</div>
      <label>Ordem<input type="number" name="sort_order" value="<?= gestao_h($edit['sort_order'] ?? '0') ?>"></label>
      <label>Meio de pagamento NFC-e
        <select name="nfce_payment_type">
          <?php $nfceType = (string)($edit['nfce_payment_type'] ?? ''); foreach ([
            ''=>'Automático pelo tipo/nome', '01'=>'01 - Dinheiro', '02'=>'02 - Cheque', '03'=>'03 - Cartão de crédito', '04'=>'04 - Cartão de débito', '05'=>'05 - Cartão da loja / crediário', '10'=>'10 - Vale alimentação', '11'=>'11 - Vale refeição', '12'=>'12 - Vale presente', '13'=>'13 - Vale combustível', '14'=>'14 - Duplicata mercantil', '15'=>'15 - Boleto bancário', '16'=>'16 - Depósito bancário', '17'=>'17 - PIX dinâmico', '18'=>'18 - TED (Transferência Eletrônica Disponível)', '19'=>'19 - Fidelidade/cashback/crédito virtual', '20'=>'20 - PIX estático', '21'=>'21 - Crédito em loja', '22'=>'22 - Pagamento eletrônico não informado', '23'=>'23 - PIX automático', '24'=>'24 - TEF - Book Transfer', '90'=>'90 - Sem pagamento', '91'=>'91 - Pagamento posterior', '99'=>'99 - Outros'
          ] as $k=>$v): ?>
            <option value="<?= gestao_h($k) ?>" <?= $nfceType===(string)$k?'selected':'' ?>><?= gestao_h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Indicador NFC-e
        <select name="nfce_payment_indicator">
          <?php $nfceInd = (string)($edit['nfce_payment_indicator'] ?? '0'); ?>
          <option value="0" <?= $nfceInd==='0'?'selected':'' ?>>0 - À vista</option>
          <option value="1" <?= $nfceInd==='1'?'selected':'' ?>>1 - A prazo</option>
        </select>
      </label>
      <label>Cartão / TEF
        <select name="nfce_card_integration">
          <?php $nfceCardInt = (string)($edit['nfce_card_integration'] ?? ''); ?>
          <option value="" <?= $nfceCardInt===''?'selected':'' ?>>Não se aplica</option>
          <option value="1" <?= $nfceCardInt==='1'?'selected':'' ?>>1 - Integrado ao sistema</option>
          <option value="2" <?= $nfceCardInt==='2'?'selected':'' ?>>2 - POS / não integrado</option>
        </select>
      </label>
      <label>Bandeira cartão
        <select name="nfce_card_brand">
          <?php $nfceBrand = (string)($edit['nfce_card_brand'] ?? ''); foreach ([
            ''=>'Não se aplica', '01'=>'01 - Visa', '02'=>'02 - Mastercard', '03'=>'03 - American Express', '04'=>'04 - Sorocred', '05'=>'05 - Diners Club', '06'=>'06 - Elo', '07'=>'07 - Hipercard', '08'=>'08 - Aura', '09'=>'09 - Cabal', '99'=>'99 - Outros'
          ] as $k=>$v): ?>
            <option value="<?= gestao_h($k) ?>" <?= $nfceBrand===(string)$k?'selected':'' ?>><?= gestao_h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>CNPJ credenciadora<input name="nfce_card_cnpj" value="<?= gestao_h($edit['nfce_card_cnpj'] ?? '') ?>" placeholder="Somente números, se necessário"></label>
      <div class="span-2" id="cardControlBox" style="border:1px solid #c7d7f2;border-radius:18px;background:#f8fbff;padding:14px;display:none;">
        <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px;">
          <div><strong style="color:#0b1f5b;font-size:16px;">Controle de Cartões / parcelas da adquirente</strong><div class="mg-muted" style="margin-top:3px;">Cria uma agenda própria de recebimento por parcela, com dias, taxa e valor líquido. Não gera Contas a Receber comum nem entrada financeira antes da baixa.</div></div>
          <label style="margin:0;white-space:nowrap;"><input type="checkbox" name="card_control_enabled" id="cardControlEnabled" value="1" <?= !empty($edit['card_control_enabled'])?'checked':'' ?>> Habilitar controle</label>
        </div>
        <?php if (!$cardControlSchemaReady): ?><div class="msg err" style="margin:0 0 10px;">Migration V11.52.7 pendente. Aplique pelo Atualizador do Banco antes de habilitar o Controle de Cartões.</div><?php endif; ?>
        <div id="cardControlFields">
          <div class="mg-form-grid" style="margin:0 0 12px;">
            <label>Operadora / adquirente<input name="card_acquirer_name" value="<?= gestao_h($edit['card_acquirer_name'] ?? '') ?>" placeholder="Ex.: Rede, Stone, Cielo, Getnet"></label>
            <label>Plano de Contas das taxas
              <select name="card_fee_chart_account_id"><?php gestao_select_options($debitCharts, $edit['card_fee_chart_account_id'] ?? null, 'Opcional - registrar somente líquido', true); ?></select>
              <span class="mg-muted">Se informado, a baixa registra o bruto como crédito e a taxa como débito. Sem ele, registra somente o líquido recebido.</span>
            </label>
          </div>
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;"><div><strong>Convênio × Parcelas</strong><div class="mg-muted">Configure 1x para débito/crédito à vista e 2x, 3x... para crédito parcelado.</div></div><button type="button" class="btn btn-sm" id="addCardPlanBtn">+ Parcela</button></div>
          <div style="overflow:auto;border:1px solid #dbe4f0;border-radius:14px;background:#fff;">
            <table class="mg-table" style="min-width:700px;margin:0;"><thead><tr><th>Nº parcelas</th><th>1º recebimento (dias)</th><th>Intervalo (dias)</th><th>Taxa %</th><th style="width:80px">Ação</th></tr></thead><tbody id="cardPlansBody">
            <?php $displayPlans=$cardPlans ?: [['installments'=>1,'first_receipt_days'=>30,'interval_days'=>30,'fee_percent'=>0]]; foreach($displayPlans as $cp): ?>
              <tr class="card-plan-row"><td><input type="number" min="1" max="48" name="card_plan_installments[]" value="<?= (int)($cp['installments']??1) ?>" required></td><td><input type="number" min="0" max="365" name="card_plan_first_days[]" value="<?= (int)($cp['first_receipt_days']??30) ?>" required></td><td><input type="number" min="0" max="365" name="card_plan_interval_days[]" value="<?= (int)($cp['interval_days']??30) ?>" required></td><td><input inputmode="decimal" name="card_plan_fee_percent[]" value="<?= gestao_h(number_format((float)($cp['fee_percent']??0),4,',','.')) ?>" required></td><td><button type="button" class="btn btn-sm card-plan-remove">Excluir</button></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          </div>
        </div>
      </div>
      <label>Banco/Caixa padrão
        <select name="default_bank_account_id"><?php gestao_select_options($banks, $edit['default_bank_account_id'] ?? null, 'Sem banco padrão', false); ?></select>
      </label>
      <div class="span-2" id="pixAutoBox" style="border:1px solid #bfdbfe;border-radius:18px;background:#f8fbff;padding:14px;">
        <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px;">
          <div><strong style="color:#0b1f5b;font-size:16px;">QR Code Pix automático no PDV Checkout</strong><div class="mg-muted" style="margin-top:3px;">Gera cobrança Pix dinâmica, mostra o QR Code no PDV e aguarda a confirmação do banco antes de liberar a finalização fiscal.</div></div>
          <label style="margin:0;white-space:nowrap;"><input type="checkbox" name="pix_auto_qr_enabled" id="pixAutoQrEnabled" value="1" <?= !empty($edit['pix_auto_qr_enabled'])?'checked':'' ?>> Habilitar</label>
        </div>
        <?php if (!$pixCheckoutSchemaReady): ?><div class="msg err" style="margin:0 0 10px;">Estrutura Pix não disponível neste contexto Multiempresa. Atualize pela empresa principal primeiro.</div><?php endif; ?>
        <div class="mg-form-grid" id="pixAutoFields" style="margin:0;">
          <label>Provedor Pix
            <?php $pixProviderEdit = strtoupper((string)($edit['pix_provider'] ?? 'BANK')); if($pixProviderEdit==='')$pixProviderEdit='BANK'; ?>
            <select name="pix_provider" id="pixProvider">
              <option value="BANK" <?= $pixProviderEdit==='BANK'?'selected':'' ?>>Usar configuração do Banco/Caixa (recomendado)</option>
              <?php foreach(bank_integration_providers() as $pk=>$pv): ?><option value="<?= gestao_h($pk) ?>" <?= $pixProviderEdit===$pk?'selected':'' ?>><?= gestao_h($pv) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>Expiração do QR Code (segundos)<input type="number" min="60" max="86400" name="pix_expiration_seconds" value="<?= gestao_h($edit['pix_expiration_seconds'] ?? '300') ?>"></label>
          <label class="span-2">Chave Pix<input name="pix_key" value="<?= gestao_h($edit['pix_key'] ?? '') ?>" placeholder="Chave cadastrada no Itaú"></label>
          <label class="span-2">URL da API Pix Recebimentos<input name="pix_api_base_url" value="<?= gestao_h($edit['pix_api_base_url'] ?? '') ?>" placeholder="Produção: https://secure.api.itau/pix_recebimentos/v2"></label>
          <div class="span-2 mg-muted" style="line-height:1.5;">As credenciais ficam no <strong>Hub de Integrações</strong> da conta selecionada em Banco/Caixa padrão. Para novas configurações, prefira “Usar configuração do Banco/Caixa”: o PDV descobre o provedor e o perfil vinculados ao serviço <strong>Pix Recebimentos</strong>. O conector operacional desta versão está homologado para Itaú; Sicoob, Caixa, Bradesco, Santander, Inter e Cora já podem ser cadastrados no Hub para receber os adaptadores nas próximas integrações.</div>
        </div>
      </div>
      <label>Plano de contas padrão
        <select name="default_chart_account_id"><?php gestao_select_options($charts, $edit['default_chart_account_id'] ?? null, 'Selecione', true); ?></select>
      </label>
      <label class="span-2">Centro de custo padrão
        <select name="default_cost_center_id"><?php gestao_select_options($costs, $edit['default_cost_center_id'] ?? null, 'Sem centro', true); ?></select>
      </label>
      <label><input type="checkbox" name="generate_receivable" value="1" <?= !empty($edit['generate_receivable'])?'checked':'' ?>> Gera conta a receber</label>
      <label id="autoRegisterBoletoRow"><input type="checkbox" name="auto_register_boleto" value="1" <?= !empty($edit['auto_register_boleto'])?'checked':'' ?>> Registra boleto automaticamente<div class="mg-muted" style="margin:5px 0 0 24px;font-weight:400">Ao finalizar uma venda ou gerar contratos em lote, registra automaticamente o boleto na API bancária configurada. Atualmente aplicado ao Itaú/API.</div></label>
      <label><input type="checkbox" name="allow_installments" value="1" <?= !empty($edit['allow_installments'])?'checked':'' ?>> Permite parcelamento no PDV/Comanda</label>
      <label>Parcelas padrão<input type="number" min="1" max="120" name="default_installments" value="<?= gestao_h($edit['default_installments'] ?? '1') ?>"></label>
      <label>Primeiro vencimento em dias<input type="number" min="0" max="365" name="first_due_days" value="<?= gestao_h($edit['first_due_days'] ?? '30') ?>"></label>
      <label><input type="checkbox" name="keep_due_day" value="1" <?= ($edit ? !empty($edit['keep_due_day']) : true)?'checked':'' ?>> Manter o dia do vencimento nas próximas parcelas</label>
      <label><input type="checkbox" name="affects_cash" value="1" <?= ($edit ? !empty($edit['affects_cash']) : true)?'checked':'' ?>> Entra no resumo do caixa</label>
      <label><input type="checkbox" name="require_open_cash" value="1" <?= !empty($edit['require_open_cash'])?'checked':'' ?>> Exige caixa aberto</label>
      <label><input type="checkbox" name="allow_change" value="1" <?= !empty($edit['allow_change'])?'checked':'' ?>> Permite troco</label>
      <label><input type="checkbox" name="is_active" value="1" <?= ($edit ? !empty($edit['is_active']) : true)?'checked':'' ?>> Ativa</label>
      <label class="span-2">Observações<textarea name="notes" rows="3"><?= gestao_h($edit['notes'] ?? '') ?></textarea></label>
    </div>
    <div class="mg-form-actions">
      <a class="btn" href="<?= gestao_h($paymentUrl('gestao_formas_pagamento.php')) ?>">Voltar</a>
      <button class="btn btn-primary" type="submit">Salvar</button>
    </div>
  </form>
<?php else: ?>
  <form method="get" class="mg-card" style="margin-bottom:14px;">
    <?php if ($paymentCrossContext): ?><input type="hidden" name="mc_ctx" value="<?= gestao_h($paymentMcToken) ?>"><?php endif; ?>
    <div style="display:grid;grid-template-columns:minmax(240px,520px) auto;gap:12px;align-items:end;">
      <label>Nome da forma
        <input type="text" name="q" value="<?= gestao_h($searchName) ?>" placeholder="Digite o nome da forma de pagamento" autofocus>
      </label>
      <div class="mg-form-actions" style="margin:0;justify-content:flex-start;">
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <a class="btn" href="<?= gestao_h($paymentUrl('gestao_formas_pagamento.php')) ?>">Limpar</a>
      </div>
    </div>
  </form>

  <div class="mg-card">
    <h2>Formas Cadastradas</h2>
    <div class="table-wrap"><table class="mg-table">
      <thead><tr><th>Forma</th><th>Tipo</th><th>Padrões</th><th>Status</th><th>Ações</th></tr></thead>
      <tbody>
      <?php if (!$methods): ?><tr><td colspan="5" class="mg-empty"><?= $searchName !== '' ? 'Nenhuma forma encontrada para a pesquisa informada.' : 'Nenhuma forma cadastrada.' ?></td></tr><?php endif; ?>
      <?php foreach ($methods as $m): ?>
        <tr>
          <td data-label="Forma" class="primary"><strong><?= gestao_h($m['name']) ?></strong><div class="mg-muted"><?= gestao_h($m['code']) ?></div></td>
          <td data-label="Tipo"><?= gestao_h($m['type']) ?><br><span class="mg-muted">NFC-e: <?= gestao_h($m['nfce_payment_type'] ?? 'auto') ?></span></td>
          <td data-label="Padrões" class="mg-muted">
            Banco: <?= (int)($m['default_bank_account_id'] ?? 0) ?: '—' ?><br>
            Plano: <?= (int)($m['default_chart_account_id'] ?? 0) ?: '—' ?><br>
            Parcelas: <?= !empty($m['allow_installments']) ? ((int)($m['default_installments'] ?? 1) . 'x / 1º venc. +' . (int)($m['first_due_days'] ?? 30) . ' dia(s)') : '—' ?>
            <?php if (($m['type'] ?? '') === 'BOLETO'): ?><br>Registro boleto: <?= !empty($m['auto_register_boleto']) ? '<strong>Automático</strong>' : 'Manual' ?><?php endif; ?>
            <?php if (($m['type'] ?? '') === 'PIX'): ?><br>QR automático: <?= !empty($m['pix_auto_qr_enabled']) ? '<strong>Itaú / ativo</strong>' : 'Não' ?><?php endif; ?>
            <?php if (($m['type'] ?? '') === 'CARTAO'): ?><br>Controle cartões: <?= !empty($m['card_control_enabled']) ? '<strong>Ativo</strong>' : 'Não' ?><?php endif; ?>
          </td>
          <td data-label="Status"><span class="mg-badge <?= !empty($m['is_active'])?'green':'red' ?>"><?= !empty($m['is_active'])?'Ativa':'Inativa' ?></span></td>
          <td data-label="Ações"><a class="btn btn-sm" href="<?= gestao_h($paymentUrl('gestao_formas_pagamento.php?edit=' . (int)$m['id'] . '#form')) ?>">Editar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<script>
(function(){
  const type=document.getElementById('paymentType');
  const boletoRow=document.getElementById('autoRegisterBoletoRow');
  const pixBox=document.getElementById('pixAutoBox');
  const pixEnabled=document.getElementById('pixAutoQrEnabled');
  const pixFields=document.getElementById('pixAutoFields');
  const cardBox=document.getElementById('cardControlBox');
  const cardEnabled=document.getElementById('cardControlEnabled');
  const cardFields=document.getElementById('cardControlFields');
  if(!type)return;
  const sync=()=>{
    const boleto=type.value==='BOLETO';
    const pix=type.value==='PIX';
    const card=type.value==='CARTAO';
    const voucher=type.value==='VALE';
    if(boletoRow){ boletoRow.style.display=boleto?'block':'none'; const cb=boletoRow.querySelector('input[type="checkbox"]'); if(cb&&!boleto) cb.checked=false; }
    if(pixBox) pixBox.style.display=pix?'block':'none';
    if(pixEnabled && !pix) pixEnabled.checked=false;
    if(pixFields) pixFields.style.opacity=(pixEnabled?.checked?'1':'.55');
    if(cardBox) cardBox.style.display=card?'block':'none';
    if(cardEnabled && !card) cardEnabled.checked=false;
    if(cardFields) cardFields.style.opacity=(cardEnabled?.checked?'1':'.55');
    if(card && cardEnabled?.checked){
      ['generate_receivable','allow_installments','affects_cash'].forEach(name=>{const el=document.querySelector('[name="'+name+'"]');if(el){el.checked=false;el.disabled=true;}});
    }else if(!voucher){
      ['generate_receivable','allow_installments','affects_cash'].forEach(name=>{const el=document.querySelector('[name="'+name+'"]');if(el)el.disabled=false;});
    }
    const voucherHelp=document.getElementById('voucherTypeHelp'); if(voucherHelp) voucherHelp.style.display=voucher?'block':'none';
    ['default_bank_account_id','default_chart_account_id','default_cost_center_id'].forEach(name=>{const el=document.querySelector('[name="'+name+'"]'); if(el){el.disabled=voucher; if(voucher) el.value='';}});
    ['generate_receivable','allow_installments','affects_cash','require_open_cash','allow_change'].forEach(name=>{const el=document.querySelector('[name="'+name+'"]'); if(el){el.disabled=voucher; if(voucher) el.checked=false;}});
    const code=document.querySelector('[name="code"]'); if(code){code.readOnly=voucher; if(voucher) code.value='VALE';}
    const nfce=document.querySelector('[name="nfce_payment_type"]'); if(nfce && voucher) nfce.value='21';
  };
  type.addEventListener('change',sync);
  pixEnabled?.addEventListener('change',sync);
  cardEnabled?.addEventListener('change',sync);
  const body=document.getElementById('cardPlansBody');
  const addBtn=document.getElementById('addCardPlanBtn');
  const bindRemove=()=>body?.querySelectorAll('.card-plan-remove').forEach(btn=>{btn.onclick=()=>{const rows=body.querySelectorAll('.card-plan-row');if(rows.length<=1){alert('Mantenha pelo menos uma regra de cartão.');return;}btn.closest('tr')?.remove();};});
  addBtn?.addEventListener('click',()=>{if(!body)return;const rows=body.querySelectorAll('.card-plan-row');let next=1;rows.forEach(r=>{next=Math.max(next,Number(r.querySelector('[name="card_plan_installments[]"]')?.value||0)+1)});const tr=document.createElement('tr');tr.className='card-plan-row';tr.innerHTML='<td><input type="number" min="1" max="48" name="card_plan_installments[]" value="'+next+'" required></td><td><input type="number" min="0" max="365" name="card_plan_first_days[]" value="30" required></td><td><input type="number" min="0" max="365" name="card_plan_interval_days[]" value="30" required></td><td><input inputmode="decimal" name="card_plan_fee_percent[]" value="0,0000" required></td><td><button type="button" class="btn btn-sm card-plan-remove">Excluir</button></td>';body.appendChild(tr);bindRemove();});
  bindRemove();
  sync();
})();
</script>
<?php gestao_admin_end(); ?>
