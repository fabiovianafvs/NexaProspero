<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/performance_monitor.php';
nexa_performance_monitor_start('gestao_comandas');

require __DIR__ . '/_bootstrap.php';
nexa_performance_monitor_mark('bootstrap');

require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/product_half_pizza.php';
require_once __DIR__ . '/../config/pix_sales_flow.php';
nexa_performance_monitor_mark('modulos');

// V11.29: Comandas reutiliza o mesmo motor da empresa principal, porém a empresa
// operacional passa a ser a selecionada no F11. O COMPANY_ID legado permanece intacto.
$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_comandas')
  : (int)COMPANY_ID;
$comandaCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_comandas');
$comandaMobileMode = !empty($_GET['mobile']) || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'gestao_comandas_mobile.php';
$comandaCheckoutMode = defined('NEXA_COMANDA_CHECKOUT_MODE') && NEXA_COMANDA_CHECKOUT_MODE === true;
if ($comandaCrossContext) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  $requiredPermission = $comandaMobileMode ? 'gestao_comandas_mobile' : ($comandaCheckoutMode ? 'gestao_comandas_checkout' : 'gestao_comandas');
  if (!function_exists('multi_company_context_permission_allowed')
      || !multi_company_context_permission_allowed($pdo, $requiredPermission)) {
    http_response_code(403);
    admin_topbar($comandaMobileMode ? 'Comanda Mobile' : 'Comandas', 'gestao');
    echo '<div class="msg err" style="margin:24px">Seu perfil não possui permissão para operar Comandas na empresa selecionada pelo F11.</div>';
    admin_footer();
    exit;
  }
}

saas_require_company_module($pdo, $companyId, 'comandas', 'Menu Comanda');
comanda_ensure_schema($pdo);
nexa_performance_monitor_mark('schema_runtime');

$userId = (int)(auth_user_id() ?? 0);
$saleFinalizationBlocked = $comandaCrossContext
  ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'nao_finalizar_venda'))
  : (function_exists('access_sale_finalization_blocked') ? access_sale_finalization_blocked($pdo, $companyId) : false);
$comandaBaseUrl = $comandaMobileMode ? 'gestao_comandas_mobile.php' : ($comandaCheckoutMode ? 'gestao_comandas_checkout.php' : 'gestao_comandas.php');
$comandaContextUrl = static function(string $url) use ($comandaCrossContext): string {
  return $comandaCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$comandaHomeUrl = $comandaContextUrl($comandaBaseUrl);
$comandaMcCsrf = $comandaCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$comandaMcCtx = $comandaCrossContext && is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? (string)($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '') : '';
$pixSalesReady = pix_sales_flow_ready($pdo, !$comandaCrossContext);
pix_sales_flow_handle_ajax($pdo, $companyId, $userId, $comandaCrossContext, $pixSalesReady);
$err = '';
$selectedId = (int)($_GET['id'] ?? 0);
$selectedCode = trim((string)($_GET['c'] ?? ''));
$step = (string)($_GET['step'] ?? 'inicio');
$checkoutFlow = !empty($_GET['checkout']);
$validSteps = ['inicio','abrir','produtos','identificacao','pagamento','transferencia'];
if (!in_array($step, $validSteps, true)) $step = 'inicio';

$requestedAction = (string)($_POST['action'] ?? '');
$needsProductExperience = $step === 'produtos' || $requestedAction === 'process_products';
$needsFiscalRuntime = !$comandaCrossContext && ($step === 'pagamento' || $requestedAction === 'finalize_comanda');
$comandaHalfPizzaReady = $needsProductExperience ? product_half_pizza_ensure_schema($pdo, !$comandaCrossContext) : product_half_pizza_column_exists($pdo);

if ($needsProductExperience) {
  require_once __DIR__ . '/../config/product_options.php';
  require_once __DIR__ . '/../config/balanca_module.php';
}
if ($needsFiscalRuntime) {
  require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/nfse_quick_emit.php';
}
nexa_performance_monitor_mark('dependencias_etapa');

$scaleCfg = ($needsProductExperience && function_exists('balanca_config')) ? balanca_config($pdo, $companyId) : [];

if (!function_exists('cmd_transfer_find_existing')) {
  function cmd_transfer_find_existing(PDO $pdo, int $companyId, string $codigo, string $label = 'comanda'): array {
    $codigo = trim($codigo);
    if ($codigo === '') throw new RuntimeException('Informe o número da ' . $label . '.');
    $row = comanda_find_by_code_or_matricula($pdo, $companyId, $codigo);
    if (!$row) throw new RuntimeException('A ' . $label . ' ' . $codigo . ' não foi encontrada. Confira o número digitado.');
    return $row;
  }
}

if (!function_exists('cmd_transfer_parse_source_codes')) {
  function cmd_transfer_parse_source_codes(PDO $pdo, int $companyId, string $codigos, int $destinationId): array {
    $parts = preg_split('/[\s,;|]+/u', trim($codigos), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) throw new RuntimeException('Digite ao menos uma comanda de origem. Para várias, separe os números por vírgula.');
    $ids = [];
    foreach (array_values(array_unique($parts)) as $codigo) {
      $row = cmd_transfer_find_existing($pdo, $companyId, (string)$codigo, 'comanda de origem');
      $id = (int)$row['id'];
      if ($id === $destinationId) throw new RuntimeException('A comanda ' . $codigo . ' não pode ser origem e destino ao mesmo tempo.');
      $open = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $id])['qtd'] ?? 0);
      if ($open <= 0) throw new RuntimeException('A comanda ' . $codigo . ' não possui itens em aberto para transferir.');
      $ids[] = $id;
    }
    return array_values(array_unique($ids));
  }
}


if (!function_exists('cmd_elapsed_label')) {
  function cmd_elapsed_label(int $seconds): string {
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    if ($minutes < 1) return 'menos de 1 min';
    if ($minutes < 60) return $minutes . ' min';
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    if ($hours < 24) return $hours . 'h' . ($remainingMinutes > 0 ? ' ' . str_pad((string)$remainingMinutes, 2, '0', STR_PAD_LEFT) . 'min' : '');
    $days = intdiv($hours, 24);
    $remainingHours = $hours % 24;
    return $days . 'd' . ($remainingHours > 0 ? ' ' . $remainingHours . 'h' : '');
  }
}


if (!function_exists('cmd_elapsed_clock')) {
  function cmd_elapsed_clock(int $seconds): string {
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    return str_pad((string)$hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$remainingMinutes, 2, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('cmd_short_person_name')) {
  function cmd_short_person_name(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return 'Sem nome';
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) return 'Sem nome';
    if (count($parts) === 1) return $parts[0];
    return $parts[0] . ' ' . $parts[1];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_customer_comanda') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($comandaCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize Comandas e tente novamente.');
    }
    $result = gestao_create_customer($pdo, $companyId, $_POST);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_customer_comanda') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($comandaCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize Comandas e tente novamente.');
    }
    $cid = (int)($_POST['comanda_id'] ?? 0);
    $clienteId = max(0, (int)($_POST['cliente_id'] ?? 0));
    if ($cid <= 0 || !comanda_find($pdo, $companyId, $cid)) throw new RuntimeException('Comanda não encontrada.');
    $customerCfg = comanda_settings($pdo, $companyId);
    if (empty($customerCfg['allow_customer'])) throw new RuntimeException('Seleção de cliente desabilitada nas configurações de Comandas.');
    if ($clienteId > 0) {
      $person = finance_query_one($pdo, 'SELECT id, name FROM finance_people WHERE company_id=? AND id=? LIMIT 1', [$companyId, $clienteId]);
      if (!$person) throw new RuntimeException('Cliente não encontrado para esta empresa.');
    }
    $pdo->prepare('UPDATE comandas SET cliente_id=NULLIF(?,0), updated_at=NOW() WHERE company_id=? AND id=?')->execute([$clienteId, $companyId, $cid]);
    echo json_encode(['ok'=>true, 'cliente_id'=>$clienteId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($comandaCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize Comandas e tente novamente.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'read_comanda') {
      $cmd = comanda_get_or_create($pdo, $companyId, (string)($_POST['codigo'] ?? ''));
      header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?id=' . (int)$cmd['id'] . '&step=produtos')); exit;
    }

    $cid = (int)($_POST['comanda_id'] ?? 0);

    if ($action === 'process_products') {
      // Preferencialmente recebe os itens no mesmo padrão do PDV:
      // item_product_id / item_qty / item_price / item_obs. Isso preserva meia pizza,
      // adicionais, preço calculado e observações configuradas no modal do produto.
      $productIds = (array)($_POST['item_product_id'] ?? $_POST['produto_id'] ?? []);
      $afterProcessStep = trim((string)($_POST['after_process_step'] ?? ''));
      if (!in_array($afterProcessStep, ['abrir', 'produtos', 'identificacao', 'identificacao_pagamento', 'pagamento'], true)) $afterProcessStep = '';
      $qtys = (array)($_POST['item_qty'] ?? $_POST['qtd'] ?? []);
      $prices = (array)($_POST['item_price'] ?? []);
      $obs = (array)($_POST['item_obs'] ?? $_POST['observacao'] ?? []);
      $batchRows = [];
      foreach ($productIds as $idx => $pidRaw) {
        $pid = (int)$pidRaw;
        if ($pid <= 0) continue;
        $qty = comanda_money_in($qtys[$idx] ?? 1);
        if ($qty <= 0) $qty = 1;
        $priceOverride = array_key_exists($idx, $prices) && trim((string)$prices[$idx]) !== '' ? comanda_money_in($prices[$idx]) : null;
        $batchRows[] = ['product_id'=>$pid,'qty'=>$qty,'obs'=>(string)($obs[$idx] ?? ''),'price_override'=>$priceOverride];
      }
      $printSettings = comanda_settings($pdo, $companyId);
      // V11.32: configuração e agentes já são isolados por company_id no F11.
      // Portanto a mesma impressão automática/direta da empresa principal pode ser reutilizada
      // com os grupos e agentes da empresa operacional.
      $promptFirstName = !empty($printSettings['prompt_name_first_launch']);
      $firstLaunchName = trim((string)($_POST['first_launch_name'] ?? ''));

      $ownTransaction = !$pdo->inTransaction();
      if ($ownTransaction) $pdo->beginTransaction();
      try {
        // Bloqueia a comanda durante a conferência do primeiro lançamento.
        // Assim, dois terminais não conseguem identificar a mesma comanda ao mesmo tempo.
        $lock = $pdo->prepare('SELECT id FROM comandas WHERE company_id=? AND id=? LIMIT 1 FOR UPDATE');
        $lock->execute([$companyId, $cid]);
        if (!(int)($lock->fetchColumn() ?: 0)) throw new RuntimeException('Comanda não encontrada.');

        $openBefore = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $cid])['qtd'] ?? 0);
        $isFirstLaunch = $openBefore <= 0 && !empty($batchRows);
        if ($promptFirstName && $isFirstLaunch && $firstLaunchName === '') {
          throw new RuntimeException('Informe o nome da pessoa/comanda para concluir o primeiro lançamento.');
        }

        $batchResult = comanda_add_items_batch($pdo, $companyId, $cid, $batchRows);
        $added = (int)($batchResult['added'] ?? 0);
        if ($promptFirstName && $isFirstLaunch && $added > 0) {
          comanda_update_first_launch_name($pdo, $companyId, $cid, $firstLaunchName);
        }
        if ($ownTransaction) $pdo->commit();
      } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }

      $added = (int)($batchResult['added'] ?? 0);
      $kitchenItemIds = array_values(array_map('intval', (array)($batchResult['kitchen_item_ids'] ?? [])));
      $msg = $added > 0 ? $added . ' item(ns) lançado(s) na comanda.' : 'Nenhum produto foi selecionado.';
      if ($promptFirstName && !empty($isFirstLaunch) && $added > 0) $msg .= ' Nome identificado: ' . $firstLaunchName . '.';
      // F9 mantém o comportamento tradicional e volta ao quadro geral. Os atalhos
      // F3/F4/F5 podem processar os itens e continuar diretamente na etapa escolhida.
      // O fluxo identificacao_pagamento é usado pelo botão Faturar comanda: grava os
      // produtos que acabaram de ser selecionados, preserva os itens já abertos e segue
      // para o cliente; ao confirmar o cliente, abre o pagamento automaticamente.
      if ($afterProcessStep === 'identificacao_pagamento') {
        $openForBilling = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $cid])['qtd'] ?? 0);
        if ($openForBilling <= 0) throw new RuntimeException('A comanda não possui itens para faturar.');
        $productsReturnUrl = comanda_step_href($cid, 'identificacao') . '&checkout=1';
      } else {
        $productsReturnUrl = $afterProcessStep !== ''
          ? comanda_step_href($cid, $afterProcessStep)
          : $comandaHomeUrl;
      }
      $productsReturnSeparator = str_contains($productsReturnUrl, '?') ? '&' : '?';
      if ($kitchenItemIds && !empty($printSettings['auto_print_kitchen'])) {
        if (!empty($printSettings['direct_print_kitchen'])) {
          $jobs = comanda_print_queue_create_for_items($pdo, $companyId, $cid, $kitchenItemIds);
          $directMsg = $jobs > 0
            ? ' Impressão enviada diretamente para ' . $jobs . ' setor(es).'
            : ' Nenhum setor de impressão foi encontrado para os itens.';
          header('Location: ' . $productsReturnUrl . $productsReturnSeparator . 'ok=' . rawurlencode($msg . $directMsg)); exit;
        }
        $printUrl = $comandaContextUrl('pedido_print_cozinha.php?comanda_id=' . $cid . '&auto=1&items=' . rawurlencode(implode(',', $kitchenItemIds)));
        $backUrl = $productsReturnUrl . $productsReturnSeparator . 'ok=' . rawurlencode($msg . ' Impressão de cozinha enviada.') . '&print_url=' . rawurlencode($printUrl);
        header('Location: ' . $backUrl); exit;
      }
      header('Location: ' . $productsReturnUrl . $productsReturnSeparator . 'ok=' . rawurlencode($msg)); exit;
    }

    if ($action === 'update_header') {
      comanda_update_header($pdo, $companyId, $cid, (string)($_POST['nome'] ?? ''), (int)($_POST['cliente_id'] ?? 0), (string)($_POST['notes'] ?? ''));
      $afterUpdateStep = trim((string)($_POST['after_update_step'] ?? ''));
      if ($comandaMobileMode || $afterUpdateStep === 'pagamento') {
        header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?id=' . $cid . '&step=pagamento&ok=' . rawurlencode('Cliente/nome confirmado. Informe o pagamento.'))); exit;
      }
      header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?ok=' . rawurlencode('Dados da comanda atualizados.'))); exit;
    }

    if ($action === 'cancel_item') {
      comanda_cancel_item($pdo, $companyId, (int)($_POST['item_id'] ?? 0));
      comanda_refresh_status($pdo, $companyId, $cid);
      header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?id=' . $cid . '&step=abrir&ok=' . rawurlencode('Item removido da comanda.'))); exit;
    }

    if ($action === 'transfer_all') {
      $transferCfg = comanda_settings($pdo, $companyId);
      if (empty($transferCfg['allow_transfer'])) throw new RuntimeException('A transferência de comandas está desabilitada nas configurações.');
      $to = cmd_transfer_find_existing($pdo, $companyId, (string)($_POST['to_codigo'] ?? ''), 'comanda de destino');
      $moved = comanda_transfer_all($pdo, $companyId, $cid, (int)$to['id']);
      header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?id=' . (int)$to['id'] . '&step=' . ($comandaCheckoutMode ? 'produtos' : 'abrir') . '&ok=' . rawurlencode($moved . ' item(ns) transferido(s) com sucesso.'))); exit;
    }

    if ($action === 'transfer_multiple') {
      $transferCfg = comanda_settings($pdo, $companyId);
      if (empty($transferCfg['allow_transfer']) || empty($transferCfg['allow_multi_transfer'])) throw new RuntimeException('A junção de várias comandas está desabilitada nas configurações.');
      $to = cmd_transfer_find_existing($pdo, $companyId, (string)($_POST['to_codigo'] ?? ''), 'comanda de destino');
      $toId = (int)$to['id'];
      $fromIds = cmd_transfer_parse_source_codes($pdo, $companyId, (string)($_POST['from_codigos'] ?? ''), $toId);
      $moved = comanda_transfer_multiple($pdo, $companyId, $fromIds, $toId);
      header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?id=' . $toId . '&step=' . ($comandaCheckoutMode ? 'produtos' : 'abrir') . '&ok=' . rawurlencode($moved . ' item(ns) reunido(s) na comanda de destino.'))); exit;
    }

    if ($action === 'transfer_item') {
      $transferCfg = comanda_settings($pdo, $companyId);
      if (empty($transferCfg['allow_transfer']) || empty($transferCfg['allow_item_transfer'])) throw new RuntimeException('A transferência de itens está desabilitada nas configurações.');
      $to = cmd_transfer_find_existing($pdo, $companyId, (string)($_POST['to_codigo'] ?? ''), 'comanda de destino');
      comanda_transfer_item($pdo, $companyId, (int)($_POST['item_id'] ?? 0), (int)$to['id']);
      header('Location: ' . $comandaContextUrl($comandaBaseUrl . '?id=' . (int)$to['id'] . '&step=' . ($comandaCheckoutMode ? 'produtos' : 'abrir') . '&ok=' . rawurlencode('Item transferido com sucesso.'))); exit;
    }

    if ($action === 'finalize_comanda') {
      if ($saleFinalizationBlocked) {
        throw new RuntimeException('Este perfil está configurado para não permitir finalizar a venda. O usuário pode lançar produtos e conferir a conta.');
      }
      $requestedCheckoutOption = strtolower(trim((string)($_POST['emit_doc_after'] ?? 'none')));
      if ($comandaCrossContext) {
        if (!in_array($requestedCheckoutOption, ['', 'none'], true)) {
          throw new RuntimeException('V11.31 Multiempresa: emissão fiscal pela Comanda no F11 ainda está bloqueada. Grave somente o Pedido.');
        }
        $requestedCheckoutOption = 'none';
        $_POST['emit_doc_after'] = 'none';
        $fiscalCfgCmdPost = ['order_auto_print_enabled'=>1, 'order_print_disabled'=>0];
      } else {
        $fiscalCfgCmdPost = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
        if (!function_exists('fiscal_checkout_option_enabled') || !fiscal_checkout_option_enabled($fiscalCfgCmdPost, $requestedCheckoutOption)) {
          throw new RuntimeException('A opção de finalização selecionada não está habilitada nas Configurações Fiscais.');
        }
      }
      $verifiedPixCharges = pix_sales_flow_validate_before_finalize($pdo, $companyId, $_POST, $pixSalesReady);
      $orderId = comanda_finalize_to_order($pdo, $companyId, $userId, $cid, $_POST);
      try { pix_sales_flow_link_order($pdo, $companyId, $orderId, $verifiedPixCharges); } catch (Throwable $pixLinkError) { error_log('Nexa Pix Comanda link: ' . $pixLinkError->getMessage()); }
      $autoPrintPedidoCmdPost = (!array_key_exists('order_auto_print_enabled', $fiscalCfgCmdPost) || !empty($fiscalCfgCmdPost['order_auto_print_enabled'])) ? '1' : '0';
      $orderPrintDisabledCmdPost = !empty($fiscalCfgCmdPost['order_print_disabled']);
      $orderRowCmdPost = finance_query_one($pdo, 'SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $orderId]) ?: ['id' => $orderId];
      $orderNumberCmdPost = function_exists('company_sequence_display') ? company_sequence_display($orderRowCmdPost, 'pedidos') : $orderId;
      $pedidoPrintUrlCmdPost = $comandaContextUrl('pedido_print.php?id=' . $orderId . '&return_to=' . rawurlencode($comandaHomeUrl) . '&autoprint=' . $autoPrintPedidoCmdPost);
      $pedidoDoneUrlCmdPost = $comandaContextUrl($comandaBaseUrl . '?ok=' . rawurlencode('Pedido #' . $orderNumberCmdPost . ' salvo com sucesso.'));
      $emitAfter = !$comandaCrossContext && in_array($requestedCheckoutOption, ['nfce','nfe','nfse'], true) ? $requestedCheckoutOption : '';
      if ($emitAfter === 'nfse') {
        try {
          $nfseResult = nfse_quick_authorize_order($pdo, $companyId, $orderId);
          $nfseDocId = nfse_quick_document_id($pdo, $companyId, $orderId, $nfseResult);
          if ($nfseDocId <= 0) throw new RuntimeException('A NFS-e foi processada, mas o documento fiscal não pôde ser localizado.');
          $nfseNumber = trim((string)($nfseResult['nfse_number'] ?? $nfseResult['document']['nfse_number'] ?? ''));
          $nfseMessage = 'Pedido #' . $orderNumberCmdPost . ' salvo e NFS-e autorizada' . ($nfseNumber !== '' ? ' nº ' . $nfseNumber : '') . '.';
          header('Location: ' . $comandaContextUrl('fiscal_nfse_nacional.php?pedido_id=' . $orderId . '&source=comanda&ok=' . rawurlencode($nfseMessage)));
          exit;
        } catch (Throwable $fiscalError) {
          $target = $orderPrintDisabledCmdPost ? $pedidoDoneUrlCmdPost : $pedidoPrintUrlCmdPost;
          $sep = str_contains($target, '?') ? '&' : '?';
          header('Location: ' . $target . $sep . 'err=' . rawurlencode('Pedido #' . $orderNumberCmdPost . ' salvo, mas a emissão da NFS-e retornou: ' . $fiscalError->getMessage()));
          exit;
        }
      }
      if ($emitAfter !== '') {
        try {
          $result = $emitAfter === 'nfe'
            ? fiscal_nfe_authorize_order($pdo, $companyId, $orderId)
            : fiscal_nfce_authorize_order($pdo, $companyId, $orderId);
          $docId = (int)($result['doc_id'] ?? 0);
          if ($docId <= 0) {
            $doc = $emitAfter === 'nfe'
              ? fiscal_document_order_document($pdo, $companyId, $orderId, '55')
              : fiscal_document_order_document($pdo, $companyId, $orderId, '65');
            $docId = (int)($doc['id'] ?? 0);
          }
          if ($docId > 0) {
            $returnTo = $orderPrintDisabledCmdPost ? $comandaBaseUrl : ('pedido_print.php?id=' . $orderId . '&return_to=' . rawurlencode($comandaBaseUrl) . '&autoprint=0');
            header('Location: fiscal_nfce_imprimir.php?doc_id=' . $docId . '&source=checkout&return_to=' . rawurlencode($returnTo)); exit;
          }
        } catch (Throwable $fiscalError) {
          header('Location: ' . ($orderPrintDisabledCmdPost ? ($comandaBaseUrl . '?err=' . rawurlencode('Pedido #' . $orderNumberCmdPost . ' salvo, mas a emissão fiscal retornou: ' . $fiscalError->getMessage())) : ($pedidoPrintUrlCmdPost . '&err=' . rawurlencode($fiscalError->getMessage())))); exit;
        }
      }
      header('Location: ' . ($orderPrintDisabledCmdPost ? $pedidoDoneUrlCmdPost : $pedidoPrintUrlCmdPost)); exit;
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
  $selectedId = (int)($_POST['comanda_id'] ?? $selectedId);
  if ($selectedId > 0 && $step === 'inicio') $step = 'abrir';
}

$settings = comanda_settings($pdo, $companyId);
$inactiveAlertMinutes = max(0, min(1440, (int)($settings['inactive_alert_minutes'] ?? 10)));
$needsCheckoutData = $step === 'pagamento';
if ($comandaCrossContext && $needsCheckoutData) {
  $fiscalCfgCmd = [];
  $cmdCheckoutOptions = ['nfce'=>false,'nfe'=>false,'nfse'=>false,'none'=>true];
} else {
  $fiscalCfgCmd = ($needsCheckoutData && function_exists('fiscal_company_config')) ? fiscal_company_config($pdo, $companyId) : [];
  $cmdCheckoutOptions = ($needsCheckoutData && function_exists('fiscal_checkout_options')) ? fiscal_checkout_options($fiscalCfgCmd) : ['nfce'=>false,'nfe'=>false,'nfse'=>false,'none'=>true];
}
$cmdCanEmitNfce = !empty($cmdCheckoutOptions['nfce']);
$cmdCanEmitNfe = !empty($cmdCheckoutOptions['nfe']);
$cmdCanEmitNfse = !empty($cmdCheckoutOptions['nfse']);
$cmdCanSaveOrder = !empty($cmdCheckoutOptions['none']);
$fiscalEmissionEnabledCmd = !$saleFinalizationBlocked && ($cmdCanEmitNfce || $cmdCanEmitNfe || $cmdCanEmitNfse || $cmdCanSaveOrder);
$comandas = comanda_rows($pdo, $companyId, !empty($settings['show_only_occupied']));
$transferComandas = (in_array($step, ['inicio', 'transferencia'], true) && !empty($settings['allow_transfer'])) ? comanda_rows($pdo, $companyId, false) : [];
$transferOccupied = array_values(array_filter($transferComandas, static fn(array $c): bool => (int)($c['itens_abertos'] ?? 0) > 0));
$selected = $selectedId > 0 ? comanda_find($pdo, $companyId, $selectedId) : ($selectedCode !== '' ? comanda_find_by_code_or_matricula($pdo, $companyId, $selectedCode) : null);
if ($step === 'transferencia' && $selectedCode !== '' && !$selected && $err === '') $err = 'A comanda ' . $selectedCode . ' não foi encontrada. Confira o número digitado.';
$selectedId = $selected ? (int)$selected['id'] : 0;
if ($selectedId <= 0 && !in_array($step, ['inicio','transferencia'], true)) $step = 'inicio';
$items = $selectedId > 0 ? comanda_items($pdo, $companyId, $selectedId, true) : [];
$promptNameFirstLaunch = $selectedId > 0 && $step === 'produtos' && !$items && !empty($settings['prompt_name_first_launch']);
$totals = comanda_totals($items);
$needsProductCatalog = $selected && $step === 'produtos';
$needsCustomerCatalog = $selected && ($step === 'identificacao' || ($comandaCheckoutMode && $step === 'produtos'));
$products = $needsProductCatalog ? comanda_products($pdo, $companyId) : [];
$customers = $needsCustomerCatalog ? gestao_customer_rows($pdo, $companyId) : [];
$payments = $needsCheckoutData ? gestao_payment_methods($pdo, $companyId, true) : [];
$commercialUserRules = commercial_user_rules($pdo, $companyId, $userId);
$defaultSellerId = (int)($commercialUserRules['default_seller_id'] ?? 0);
$allowSellerChange = !empty($commercialUserRules['allow_seller_change']);
$maxDiscountPercent = $commercialUserRules['max_discount_percent'];
$sellers = $needsCheckoutData ? finance_people_options($pdo, $companyId, 'seller') : [];
$defaultSellerName = 'Sem vendedor';
foreach ($sellers as $sellerRow) {
  if ((int)($sellerRow['id'] ?? 0) !== $defaultSellerId) continue;
  $sellerCode = trim((string)($sellerRow['code'] ?? ''));
  $sellerName = trim((string)($sellerRow['name'] ?? ''));
  $defaultSellerName = trim(($sellerCode !== '' ? $sellerCode . ' - ' : '') . $sellerName);
  if ($defaultSellerName === '') $defaultSellerName = 'Sem vendedor';
  break;
}
$selectedCreditLimit = null; $selectedCreditOpen = 0.0;
if ($needsCheckoutData && $selected && (int)($selected['cliente_id'] ?? 0) > 0) { try { $pc=finance_query_one($pdo, 'SELECT credit_limit FROM finance_people WHERE company_id=? AND id=? LIMIT 1', [$companyId,(int)$selected['cliente_id']]); $selectedCreditLimit = array_key_exists('credit_limit',(array)$pc) ? $pc['credit_limit'] : null; $selectedCreditOpen = commercial_open_credit($pdo,$companyId,(int)$selected['cliente_id'],false); } catch(Throwable $e) {} }
$paymentMap = [];
foreach ($payments as $p) {
  $paymentMap[(int)$p['id']] = [
    'name' => (string)$p['name'],
    'type' => (string)$p['type'],
    'receivable' => (!empty($p['card_control_enabled']) ? 0 : (int)($p['generate_receivable'] ?? 0)),
    'allowInstallments' => (int)($p['allow_installments'] ?? 0),
    'defaultInstallments' => (int)($p['default_installments'] ?? 1),
    'firstDueDays' => (int)($p['first_due_days'] ?? 30),
    'keepDueDay' => (int)($p['keep_due_day'] ?? 1),
    'creRulesEnabled' => (int)($p['cre_rules_enabled'] ?? 0),
    'periodDays' => (int)($p['cre_installment_period_days'] ?? 30),
    'fixedDueDay' => isset($p['cre_fixed_due_day']) ? (int)$p['cre_fixed_due_day'] : 0,
    'requiresCash' => (int)($p['require_open_cash'] ?? 0),
    'allowChange' => (int)($p['allow_change'] ?? 0),
  ];
}
$paymentShortcuts = [];
foreach (array_values($payments) as $paymentIndex => $paymentRow) {
  $paymentShortcuts[] = [
    'key' => (string)($paymentIndex + 1),
    'id' => (int)$paymentRow['id'],
    'name' => (string)$paymentRow['name'],
  ];
}
$biz = $needsProductExperience ? load_business_config($companyId) : [];
$productOptionsCfg = (array)($biz['product_options'] ?? []);
$productOptionDisplay = [
  'showAdditionalUnitTotal' => !empty($productOptionsCfg['show_additional_unit_total']),
  'showItemTotalWithAdditionals' => !empty($productOptionsCfg['show_item_total_with_additionals']),
  'itemNoteInstruction' => trim((string)($productOptionsCfg['item_note_instruction'] ?? 'Ex.: sem cebola, bem passada, retirar ingrediente...')),
];
$categories = ['Todos'];
foreach ($products as $p) {
  $c = trim((string)($p['categoria'] ?? ''));
  if ($c !== '' && !in_array($c, $categories, true)) $categories[] = $c;
}
$productOptionMap = [];
try {
  $groupsByProduct = function_exists('product_options_load_map') ? product_options_load_map($pdo, $companyId, array_column($products, 'id'), true) : [];
  foreach ($groupsByProduct as $pidOpt => $groupsOpt) {
    if (!$groupsOpt) continue;
    $productOptionMap[(int)$pidOpt] = array_map(static function(array $g): array {
      return [
        'id' => (int)$g['id'],
        'title' => (string)$g['titulo'],
        'type' => (string)($g['tipo'] ?? 'single'),
        'required' => (int)($g['obrigatorio'] ?? 0),
        'min' => max(0, (int)($g['min_escolhas'] ?? 0)),
        'max' => max(0, (int)($g['max_escolhas'] ?? 0)),
        'items' => array_map(static function(array $it): array {
          return ['id'=>(int)$it['id'], 'name'=>(string)$it['nome'], 'price'=>(float)($it['preco'] ?? 0)];
        }, (array)($g['items'] ?? [])),
      ];
    }, $groupsOpt);
  }
} catch (Throwable $e) {}
$halfPizzaCategories = $comandaHalfPizzaReady ? product_half_pizza_enabled_categories($pdo, $companyId) : [];
$productJson = json_encode(array_map(static function(array $p) use ($productOptionMap, $halfPizzaCategories): array {
  $img = trim((string)($p['imagem'] ?? ''));
  $pid = (int)$p['id'];
  $barcode = '';
  foreach (['codigo_barras','ean','gtin','barcode'] as $bcCol) { $barcode = trim((string)($p[$bcCol] ?? '')); if ($barcode !== '') break; }
  $categoryName = (string)($p['categoria'] ?? '');
  $isPizza = product_half_pizza_category_enabled($halfPizzaCategories, $categoryName);
  return [
    'id' => $pid,
    'name' => (string)$p['nome'],
    'category' => (string)($p['categoria'] ?? ''),
    'price' => (float)($p['preco'] ?? 0),
    'image' => $img !== '' ? '/uploads/products/' . rawurlencode($img) : '/assets/no-image.svg',
    'barcode' => $barcode,
    'code' => (($n=trim((string)($p['numero_cadastro'] ?? ''))) !== '' && $n !== '0') ? $n : (trim((string)($p['codigo_firebird'] ?? '')) !== '' ? (string)$p['codigo_firebird'] : (string)$pid),
    'menuCode' => trim((string)($p['codigo_cardapio'] ?? '')),
    'reference' => trim((string)($p['referencia'] ?? '')),
    'description' => (string)($p['descricao'] ?? ''),
    'hasOptions' => !empty($productOptionMap[$pid]),
    'isPizza' => $isPizza,
    'pesavel' => ((int)($p['produto_pesavel'] ?? 0) === 1),
    'printKitchen' => ((int)($p['imprimir_cozinha'] ?? 1) === 1),
  ];
}, $products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pizzaJson = json_encode(array_values(array_filter(array_map(static function(array $p) use ($halfPizzaCategories): ?array {
  $categoryName = (string)($p['categoria'] ?? '');
  if (!product_half_pizza_category_enabled($halfPizzaCategories, $categoryName)) return null;
  return ['id'=>(int)$p['id'], 'name'=>(string)$p['nome'], 'category'=>$categoryName, 'price'=>(float)($p['preco'] ?? 0)];
}, $products))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$productOptionsJson = json_encode($productOptionMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$scaleJson = function_exists('balanca_public_config') ? json_encode(balanca_public_config($scaleCfg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
$paymentJson = json_encode($paymentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$paymentShortcutsJson = json_encode($paymentShortcuts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sellerJson = json_encode(array_map(static function(array $s): array {
  return [
    'id' => (int)($s['id'] ?? 0),
    'code' => trim((string)($s['code'] ?? '')),
    'name' => trim((string)($s['name'] ?? '')),
    'document' => preg_replace('/\D+/', '', (string)($s['document'] ?? '')),
  ];
}, $sellers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$productOptionDisplayJson = json_encode($productOptionDisplay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$customerJson = json_encode(array_map(static function(array $c): array {
  return [
    'id' => (int)$c['id'],
    'name' => (string)$c['name'],
    'document' => preg_replace('/\D+/', '', (string)($c['document'] ?? '')),
    'phone' => preg_replace('/\D+/', '', (string)($c['phone'] ?? '')),
    'whatsapp' => preg_replace('/\D+/', '', (string)($c['whatsapp'] ?? '')),
  ];
}, $customers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$customerName = '';
if ($selected && !empty($selected['cliente_id'])) {
  foreach ($customers as $c) if ((int)$c['id'] === (int)$selected['cliente_id']) { $customerName = (string)$c['name']; break; }
  if ($customerName === '') {
    try {
      $rowCustomerName = finance_query_one($pdo, 'SELECT name FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, (int)$selected['cliente_id']]);
      $customerName = trim((string)($rowCustomerName['name'] ?? ''));
    } catch (Throwable $e) {}
  }
}

nexa_performance_monitor_mark('dados');

function comanda_step_active(string $current, string $step): string { return $current === $step ? 'active' : ''; }
function comanda_step_href(int $id, string $step): string {
  $base = (string)($GLOBALS['comandaBaseUrl'] ?? 'gestao_comandas.php');
  $url = $id > 0 ? $base . '?id=' . $id . '&step=' . rawurlencode($step) : $base;
  return !empty($GLOBALS['comandaCrossContext']) && function_exists('multi_company_context_url')
    ? multi_company_context_url($url)
    : $url;
}
function comanda_multi_company_hidden(bool $includeCsrf = true): void {
  if (empty($GLOBALS['comandaCrossContext'])) return;
  $ctx = (string)($GLOBALS['comandaMcCtx'] ?? '');
  $csrf = (string)($GLOBALS['comandaMcCsrf'] ?? '');
  if ($ctx !== '') echo '<input type="hidden" name="mc_ctx" value="' . comanda_h($ctx) . '">';
  if ($includeCsrf && $csrf !== '') echo '<input type="hidden" name="mc_csrf" value="' . comanda_h($csrf) . '">';
}

if ($comandaMobileMode) {
  $GLOBALS['admin_compact_mobile_mode'] = true;
  $GLOBALS['admin_compact_mobile_show_menu'] = true;
}
gestao_admin_start($comandaMobileMode ? 'Comanda Mobile' : ($comandaCheckoutMode ? 'Comandas Checkout' : 'Gestão - Comandas'), $comandaMobileMode ? 'gestao_comandas_mobile' : ($comandaCheckoutMode ? 'gestao_comandas_checkout' : 'gestao_comandas'));
// A tela de comandas inicia diretamente no fluxo guiado (1 - Ler Comanda).
gestao_flash();
if ($comandaCrossContext) {
  echo '<div class="msg ok" style="margin-bottom:12px"><strong>V11.32 · Comandas/Impressão Multiempresa</strong> — operação, QR Code e impressão manual/automática usam a empresa selecionada pelo F11. Emissão NF-e/NFC-e/NFS-e permanece protegida; o fechamento grava somente o Pedido.</div>';
}
if ($err) echo '<div class="msg err">' . comanda_h($err) . '</div>';
$autoKitchenPrintUrl = trim((string)($_GET['print_url'] ?? ''));
?>
<?php if ($autoKitchenPrintUrl !== ''): ?>
  <div class="msg ok">Impressão de cozinha preparada. Se o navegador não abrir automaticamente, <a target="_blank" href="<?= comanda_h($autoKitchenPrintUrl) ?>">clique aqui para imprimir</a>.</div>
<?php endif; ?>
<div class="finance-modal" id="cmdOptionModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2 id="cmdOptProductTitle">Configurar produto</h2><p class="cmd-muted" style="margin:4px 0 0;">Escolha meia pizza, adicionais e observações do item como no PDV.</p></div>
      <button type="button" class="finance-modal-close" id="cmdOptCancelTop">×</button>
    </div>
    <div class="mg-form-grid">
      <label>Quantidade<input id="cmdOptQty" value="1" inputmode="decimal"></label>
      <label>Adicionais selecionados<input id="cmdOptTotal" readonly value="R$ 0,00"></label>
      <label class="span-2">Observação do item<input id="cmdOptNote" placeholder="<?= comanda_h($productOptionDisplay['itemNoteInstruction'] ?: 'Ex.: sem cebola, bem passada, retirar ingrediente...') ?>"></label>
    </div>
    <div id="cmdScaleBox" class="scale-control-box" style="display:none;margin-top:10px;"></div>
    <div id="cmdOptHalfBox" class="cmd-half-box" style="display:none;"></div>
    <div id="cmdOptGroups" style="margin-top:12px"></div>
    <div class="cmd-actions" style="margin-top:14px"><button type="button" class="btn" id="cmdOptCancel">Cancelar</button><button type="button" class="btn btn-primary" id="cmdOptConfirm">Adicionar/Aplicar</button></div>
  </div>
</div>

<?php if ($selected && $step === 'produtos' && !$comandaMobileMode): ?>
<div class="finance-modal" id="cmdProductSearchModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Pesquisar produto</h2><p class="cmd-muted" style="margin:4px 0 0;">Digite a pesquisa e pressione Enter. Use as setas ↑/↓ e Enter para selecionar.</p></div>
      <button type="button" class="finance-modal-close" id="cmdProductSearchClose">×</button>
    </div>
    <div class="cmd-product-modal-searchbar">
      <label>Pesquisar por
        <select id="cmdProductModalSearchMode" class="field">
          <option value="instant">Pesquisa instantânea</option>
          <option value="menu">Código do cardápio</option>
          <option value="description">Descrição</option>
          <option value="barcode">Código de barras</option>
        </select>
      </label>
      <label>Produto
        <input type="search" id="cmdProductModalSearch" placeholder="Digite o código, nome ou código de barras" autocomplete="off">
      </label>
      <button type="button" class="btn btn-primary" id="cmdProductModalSearchBtn">Pesquisar</button>
      <a class="btn" href="produto_form.php" target="_blank" rel="noopener">+ Cadastrar produto</a>
    </div>
    <div class="cmd-product-modal-results" id="cmdProductModalResults">
      <div class="cmd-product-modal-empty">Digite um termo para pesquisar. A lista começa vazia.</div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="finance-modal" id="cmdCustomerModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Pesquisar cliente</h2><p class="cmd-muted" style="margin:4px 0 0;">Digite nome, CPF/CNPJ, telefone ou WhatsApp. A lista só aparece após pesquisar.</p></div>
      <button type="button" class="finance-modal-close" id="cmdCustomerModalClose">×</button>
    </div>
    <div class="cmd-customer-toolbar">
      <label>Cliente<input id="cmdCustomerModalSearch" type="search" placeholder="Nome, CPF/CNPJ ou telefone" autocomplete="off"></label>
      <button type="button" class="btn btn-primary" id="cmdCustomerModalBtn">Pesquisar</button>
      <button type="button" class="btn" id="cmdCustomerModalNew">+ Cadastrar</button>
      <button type="button" class="btn" id="cmdCustomerModalFinal">Consumidor final</button>
    </div>
    <div class="cmd-customer-list" id="cmdCustomerModalList" style="margin-top:12px"><div class="cmd-empty">Informe um termo e clique em Pesquisar.</div></div>
  </div>
</div>

<?php if ($comandaCheckoutMode && $selected && $step === 'pagamento' && $allowSellerChange): ?>
<div class="finance-modal" id="cmdSellerModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Pesquisar vendedor</h2><p class="cmd-muted" style="margin:4px 0 0;">Digite código, nome ou documento. Use ↑/↓ para navegar e Enter para selecionar.</p></div>
      <button type="button" class="finance-modal-close" id="cmdSellerModalClose">×</button>
    </div>
    <div class="cmd-seller-modal-toolbar">
      <label>Vendedor<input id="cmdSellerModalSearch" type="search" placeholder="Código ou nome do vendedor" autocomplete="off"></label>
      <button type="button" class="btn btn-primary" id="cmdSellerModalBtn">Pesquisar</button>
      <a class="btn" href="gestao_vendedores.php" target="_blank" rel="noopener">+ Cadastrar vendedor</a>
      <button type="button" class="btn" id="cmdSellerModalNone">Sem vendedor</button>
    </div>
    <div class="cmd-seller-modal-results" id="cmdSellerModalResults"><div class="cmd-empty">Digite um termo para pesquisar. A lista começa vazia.</div></div>
  </div>
</div>
<?php endif; ?>

<div class="finance-modal" id="cmdCustomerQuickModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Cadastrar cliente</h2><p class="cmd-muted" style="margin:4px 0 0;">Cadastro rápido para selecionar o cliente nesta comanda.</p></div>
      <button type="button" class="finance-modal-close" id="cmdCustomerQuickClose">×</button>
    </div>
    <form id="cmdCustomerQuickForm">
      <?php comanda_multi_company_hidden(true); ?>
      <input type="hidden" name="action" value="create_customer_comanda">
      <div class="mg-form-grid three">
        <label>Tipo de pessoa<select name="person_type" id="cmdQuickPersonType"><option value="FISICA">Pessoa Física</option><option value="JURIDICA">Pessoa Jurídica</option></select></label>
        <label id="cmdQuickDocumentLabel">CPF<input name="document" id="cmdQuickDocument" inputmode="numeric"></label>
        <label>Situação da IE<select name="state_registration_status" id="cmdQuickIeStatus"><option value="AUTO">Automática</option><option value="CONTRIBUINTE">Contribuinte de ICMS</option><option value="ISENTO">Isento</option><option value="NAO_CONTRIBUINTE">Não contribuinte</option></select></label>
        <label>Inscrição Estadual<input name="state_registration" id="cmdQuickStateRegistration" maxlength="30" autocomplete="off" placeholder="Número da IE"></label>
        <label>Inscrição Municipal<input name="municipal_registration" id="cmdQuickMunicipalRegistration" maxlength="30" autocomplete="off" placeholder="Inscrição municipal"></label>
        <label>WhatsApp<input name="whatsapp" id="cmdQuickWhatsapp" inputmode="numeric"></label>
        <label class="span-2">Nome/Razão social<input name="name" id="cmdQuickName" required></label>
        <label>Nome fantasia<input name="fantasy_name" id="cmdQuickFantasy"></label>
        <label>E-mail<input name="email" id="cmdQuickEmail" type="email"></label>
        <label>Telefone<input name="phone" id="cmdQuickPhone" inputmode="numeric"></label>
        <label>CEP<input name="zip_code" id="cmdQuickZip" inputmode="numeric"></label>
        <label class="span-2">Endereço<input name="address" id="cmdQuickAddress"></label>
        <label>Número<input name="number" id="cmdQuickNumber"></label>
        <label>Bairro<input name="district" id="cmdQuickDistrict"></label>
        <label>Cidade<input name="city" id="cmdQuickCity"></label>
        <label>UF<input name="state" id="cmdQuickState" maxlength="2"></label>
        <label class="span-2">Complemento<input name="complement" id="cmdQuickComplement"></label>
      </div>
      <div class="mg-form-actions"><button type="button" class="btn" id="cmdCustomerQuickCancel">Cancelar</button><button type="submit" class="btn btn-primary">Salvar e selecionar</button></div>
    </form>
  </div>
</div>

<div class="finance-modal" id="cmdCartReviewModal" style="display:none;">
  <div class="finance-modal-dialog cmd-cart-review-dialog">
    <div class="finance-modal-head">
      <div><h2>Conferir itens adicionados</h2><p class="cmd-muted" style="margin:4px 0 0;">Revise quantidades, observações e valores antes de lançar na comanda.</p></div>
      <button type="button" class="finance-modal-close" id="cmdCartReviewClose">×</button>
    </div>
    <div class="cmd-cart-review-list" id="cmdCartReviewList"><div class="cmd-empty">Nenhum item adicionado.</div></div>
    <div class="cmd-cart-review-summary"><div><span>Quantidade</span><strong id="cmdReviewCount">0</strong></div><div><span>Total</span><strong id="cmdReviewTotal">R$ 0,00</strong></div></div>
    <div class="mg-form-actions cmd-cart-review-actions"><button type="button" class="btn" id="cmdCartReviewContinue">Continuar adicionando</button><button type="button" class="btn btn-primary" id="cmdCartReviewConfirm">Confirmar e processar</button></div>
  </div>
</div>

<?php if ($promptNameFirstLaunch): ?>
<div class="finance-modal" id="cmdFirstNameModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="cmdFirstNameTitle">
  <div class="finance-modal-dialog cmd-first-name-dialog">
    <div class="finance-modal-head">
      <div>
        <h2 id="cmdFirstNameTitle">Identificar a comanda <?= comanda_h($selected['codigo'] ?? '') ?></h2>
        <p class="cmd-muted force-show" style="margin:4px 0 0;">Este é o primeiro lançamento. Informe o nome da pessoa ou uma identificação para localizar a comanda com facilidade.</p>
      </div>
    </div>
    <form id="cmdFirstNameForm" class="mg-form" autocomplete="off">
      <label>Nome da pessoa/comanda
        <input type="text" id="cmdFirstNameInput" maxlength="120" required placeholder="Ex.: João, Família Silva, Aniversário">
      </label>
      <div class="cmd-first-name-summary"><span>Comanda</span><strong><?= comanda_h($selected['codigo'] ?? '') ?></strong></div>
      <div class="mg-form-actions cmd-first-name-actions">
        <button type="button" class="btn" id="cmdFirstNameCancel">Voltar aos produtos</button>
        <button type="submit" class="btn btn-primary">Processar comanda</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<style>
.cmd-first-name-dialog{width:min(560px,100%);margin:auto}.cmd-first-name-summary{margin-top:12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:16px;padding:12px;display:flex;align-items:center;justify-content:space-between;gap:12px}.cmd-first-name-summary span{font-size:12px;font-weight:850;color:#64748b}.cmd-first-name-summary strong{font-size:20px;color:#0b1f5b}.cmd-first-name-actions{justify-content:flex-end;margin-top:14px}@media(max-width:640px){.cmd-first-name-actions{display:grid;grid-template-columns:1fr}.cmd-first-name-actions .btn{width:100%;justify-content:center}}
.cmd-flow{display:grid;gap:14px}.cmd-stepper{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.cmd-step{border:1px solid #dbe4f0;background:#fff;border-radius:20px;padding:12px;text-align:left;text-decoration:none;color:#0f172a;display:grid;grid-template-columns:42px 1fr;gap:8px;align-items:center;box-shadow:0 10px 26px rgba(15,23,42,.04)}.cmd-step span{grid-row:span 2;width:42px;height:42px;border-radius:15px;display:flex;align-items:center;justify-content:center;background:#eff6ff;color:#1d4ed8;font-weight:950}.cmd-step strong{color:#0b1f5b;line-height:1.1}.cmd-step small{color:#64748b;font-size:12px}.cmd-step.active{border-color:#2563eb;background:linear-gradient(135deg,#143a9c,#2563eb);box-shadow:0 18px 42px rgba(37,99,235,.22)}.cmd-step.active span{background:rgba(255,255,255,.18);color:#fff}.cmd-step.active strong,.cmd-step.active small{color:#fff}.cmd-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.cmd-panel-head h2{font-size:26px;color:var(--admin-primary);margin:0 0 4px}.cmd-panel-head p{margin:0}.cmd-read-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}.cmd-read-card{background:linear-gradient(135deg,#eff6ff,#fff);border:1px solid #bfdbfe}.cmd-comandas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}.cmd-tile{aspect-ratio:1/1;border:1px solid #dbe4f0;border-radius:20px;background:#fff;text-decoration:none;color:#0f172a;padding:10px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 10px 24px rgba(15,23,42,.05);min-height:112px}.cmd-tile strong{font-size:22px;color:#0b1f5b}.cmd-tile span{font-size:12px;color:#64748b}.cmd-tile em{font-style:normal;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900;align-self:flex-start;background:#f1f5f9;color:#475569}.cmd-tile.ocupada{border-color:#86efac;background:#f0fdf4}.cmd-tile.ocupada em{background:#dcfce7;color:#15803d}.cmd-tile.bloqueada{border-color:#fecaca;background:#fef2f2}.cmd-tile.bloqueada em{background:#fee2e2;color:#b91c1c}.cmd-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.cmd-summary div{border:1px solid #e5edf6;background:#fff;border-radius:18px;padding:14px}.cmd-summary span{display:block;color:#64748b;font-size:12px;font-weight:850}.cmd-summary strong{display:block;color:#0b1f5b;font-size:20px;margin-top:4px}.cmd-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}.cmd-search{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:10px}.cmd-cats{display:flex;gap:8px;overflow-x:auto;padding:8px 0 12px}.cmd-cat{border:1px solid #dbe4f0;background:#fff;border-radius:999px;padding:9px 13px;font-weight:850;cursor:pointer;white-space:nowrap}.cmd-cat.active{background:#143a9c;color:#fff;border-color:#143a9c}.cmd-products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-height:calc(100vh - 330px);overflow:auto;padding-right:4px}.cmd-product-top-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center}.cmd-product-top-actions .btn{white-space:nowrap}.cmd-prod{display:grid;grid-template-columns:62px 1fr;gap:10px;align-items:center;border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:10px;text-align:left;cursor:pointer;box-shadow:0 10px 24px rgba(15,23,42,.05);transition:.15s}.cmd-prod:hover{transform:translateY(-1px);box-shadow:0 12px 28px rgba(2,6,23,.08)}.cmd-prod img{width:62px;height:62px;object-fit:cover;border-radius:16px;background:#f1f5f9}.cmd-prod strong{display:block;color:#0b1f5b;line-height:1.15;margin:0;min-height:0}.cmd-prod span{display:block;color:#64748b;font-size:12px;margin-top:4px}.cmd-prod b{display:block;color:#047857;font-size:16px;margin-top:6px}.cmd-prod.in-cart{border-color:#2563eb;background:#eff6ff}.cmd-product-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:14px;align-items:start}.cmd-cart{display:grid;gap:8px;max-height:calc(100vh - 330px);overflow:auto}.cmd-cart-item{border:1px solid #e5edf6;border-radius:18px;background:#fff;padding:10px}.cmd-cart-line{display:flex;justify-content:space-between;gap:10px;align-items:center}.cmd-qty{display:grid;grid-template-columns:36px 1fr 36px 38px;gap:6px;margin-top:8px}.cmd-qty button{border:1px solid #cbd5e1;background:#fff;border-radius:12px;font-weight:950;cursor:pointer}.cmd-qty input{width:100%;min-width:0;text-align:center}.cmd-item-note{width:100%;box-sizing:border-box;margin-top:8px}.cmd-items{display:grid;gap:8px}.cmd-item{display:grid;grid-template-columns:minmax(0,1fr) 105px auto;gap:10px;align-items:center;border:1px solid #e5edf6;background:#fff;border-radius:16px;padding:10px}.cmd-item-title strong{display:block;color:#0b1f5b}.cmd-item-title span{display:block;color:#64748b;font-size:12px}.cmd-total-box{border:1px solid #bfdbfe;background:#eff6ff;border-radius:18px;padding:14px;display:flex;justify-content:space-between;gap:12px;align-items:center}.cmd-total-box strong{font-size:26px;color:#047857}.cmd-ident-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.cmd-customer-list{display:grid;gap:6px;max-height:360px;overflow:auto;padding-right:4px}.cmd-customer-option{border:1px solid #e5edf6;background:#fff;border-radius:14px;padding:9px;cursor:pointer;text-align:left}.cmd-customer-option.active{border-color:#2563eb;background:#eff6ff}.cmd-customer-toolbar{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;align-items:end}.cmd-selected-customer{border:1px solid #dbe4f0;background:#f8fbff;border-radius:18px;padding:12px;margin-top:10px;display:flex;justify-content:space-between;gap:10px;align-items:center}.cmd-selected-customer strong{color:#0b1f5b}.cmd-selected-customer span{display:block;color:#64748b;font-size:12px;margin-top:2px}.finance-modal{position:fixed;inset:0;z-index:300;display:flex;align-items:flex-start;justify-content:center;padding:22px;background:rgba(15,23,42,.56);backdrop-filter:blur(3px);overflow:auto}.finance-modal-dialog{width:min(980px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 26px 80px rgba(2,6,23,.34);padding:18px}.finance-modal-wide{width:min(1120px,100%)}.finance-modal-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:14px}.finance-modal-head h2{margin:0;color:var(--admin-primary);font-size:21px}.finance-modal-close{display:inline-flex;width:42px;height:42px;align-items:center;justify-content:center;border-radius:14px;border:1px solid #dbe4f0;background:#fff;text-decoration:none;color:#0f172a;font-size:22px;font-weight:900;cursor:pointer}.pdv-fiscal-choice{border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:16px;text-align:left;cursor:pointer;min-height:104px}.pdv-fiscal-choice:hover{border-color:#2563eb;background:#eff6ff}.pdv-fiscal-choice strong{display:block;color:var(--admin-primary);font-size:16px}.pdv-fiscal-choice small{display:block;color:#64748b;margin-top:6px;line-height:1.35}.cmd-half-box{border:1px solid #bfdbfe;border-radius:18px;background:#eff6ff;padding:14px;margin:12px 0}.cmd-half-box strong{display:block;color:var(--admin-primary);margin-bottom:6px}.cmd-opt-group{border:1px solid #dbeafe;border-radius:18px;padding:14px;margin:0 0 12px;background:#fff}.cmd-opt-group legend{display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;color:var(--admin-primary);font-weight:950}.cmd-opt-group legend small{color:#64748b}.cmd-opt-items{display:grid;gap:8px;margin-top:10px}.cmd-opt-item{display:flex;gap:10px;align-items:flex-start;border:1px solid #e5edf6;border-radius:15px;padding:11px;cursor:pointer}.cmd-opt-item:hover{border-color:#60a5fa;background:#f8fbff}.cmd-opt-item span{display:flex;justify-content:space-between;gap:10px;width:100%}.cmd-opt-item small{white-space:nowrap;color:#1d4ed8;font-weight:850}.cmd-opt-price{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2}.cmd-opt-final{font-size:11px;color:#64748b;font-style:normal;font-weight:750;margin-top:2px}.scale-control-box{border:1px solid #bfdbfe;border-radius:18px;background:#eff6ff;padding:13px;display:grid;gap:8px}.scale-control-box strong{display:block;color:var(--admin-primary);font-weight:950}.scale-control-box small{display:block;color:#64748b;font-weight:750}.scale-actions,.scale-control-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.scale-actions .scale-status,.scale-control-actions .scale-status{font-size:12px;color:#475569;font-weight:850}.scale-actions button,.scale-control-actions button{min-height:38px}.scale-status.ok{color:#047857}.scale-status.err{color:#b91c1c}.cmd-pay-grid{display:grid;grid-template-columns:1fr 150px 150px;gap:8px;align-items:end}.cmd-payment-step-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:14px;align-items:start}.cmd-payments-box{border:1px solid #dbe4f0;border-radius:20px;background:#f8fbff;padding:13px;overflow:hidden}.cmd-payments-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}.cmd-payments-head strong{display:block;color:var(--admin-primary);font-size:18px}.cmd-payments-head small{display:block;color:#64748b;margin-top:2px;line-height:1.35}.cmd-payment-lines{display:grid;gap:9px}.cmd-payment-line{display:grid;grid-template-columns:minmax(220px,1.2fr) minmax(140px,.8fr) 42px;gap:8px;align-items:end;border:1px solid #e5edf6;background:#fff;border-radius:18px;padding:10px}.cmd-payment-line label{min-width:0}.cmd-payment-line select,.cmd-payment-line input{width:100%;min-width:0}.cmd-payment-line .pay-received-label{grid-column:1 / -2}.cmd-payment-line.show-received{grid-template-columns:minmax(210px,1.1fr) minmax(130px,.7fr) minmax(130px,.7fr) 42px}.cmd-payment-line.show-received .pay-received-label{grid-column:auto}.cmd-payment-line .pay-remove{min-height:42px;color:#b91c1c}.cmd-payment-summary{border:1px solid #dbe4f0;border-radius:22px;background:#f8fbff;padding:16px;position:sticky;top:88px}.cmd-total-row{display:flex;justify-content:space-between;gap:12px;align-items:center;border-bottom:1px solid #e2e8f0;padding:8px 0}.cmd-total-row span{font-size:12px;color:#64748b;font-weight:850}.cmd-total-row strong{font-size:16px;color:#0b1f5b}.cmd-total-row.final{border-bottom:0;margin-top:6px}.cmd-total-row.final strong{font-size:24px;color:#047857}.cmd-finish-button{width:100%;min-height:54px;font-size:17px;margin-top:14px}.cmd-transfer-grid{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.cmd-read-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.cmd-read-actions.has-three{grid-template-columns:repeat(3,minmax(0,1fr))}.cmd-read-grid.has-merge{grid-template-columns:minmax(260px,.72fr) minmax(620px,1.28fr)}.cmd-read-actions .btn{min-height:44px;display:flex;align-items:center;justify-content:center;text-align:center}.cmd-transfer-source{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;align-items:end}.cmd-transfer-sections{display:grid;gap:12px}.cmd-transfer-card{border:1px solid #dbe4f0;border-radius:20px;background:#fff;padding:14px}.cmd-transfer-card h3{margin:0 0 4px;font-size:18px}.cmd-transfer-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:8px;margin:10px 0}.cmd-transfer-check{display:flex;gap:9px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:14px;padding:10px;background:#f8fbff;cursor:pointer}.cmd-transfer-check input{margin-top:3px}.cmd-transfer-check strong,.cmd-transfer-check span{display:block}.cmd-transfer-check span{font-size:11px;color:#64748b;margin-top:2px}.cmd-transfer-item-card{border:1px solid #e5edf6;border-radius:17px;padding:11px;background:#fff;display:grid;gap:9px}.cmd-transfer-item-head{display:flex;justify-content:space-between;gap:10px}.cmd-transfer-item-head strong:first-child{color:#0b1f5b}.cmd-transfer-item-head small{display:block;color:#64748b;margin-top:3px}.cmd-muted{color:#64748b;font-size:12px}.cmd-empty{border:1px dashed #cbd5e1;border-radius:18px;padding:18px;text-align:center;color:#64748b;background:#fff}.cmd-installments{grid-column:1 / -1;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;padding:10px;display:none;max-width:100%;box-sizing:border-box;overflow:hidden}.cmd-installments.show{display:block}.cmd-installments-head{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;justify-content:space-between}.cmd-installments-head>div{min-width:180px;flex:1}.cmd-installments-head label{max-width:130px}.cmd-installments small{color:#64748b;font-weight:750}.cmd-installments input{width:100%;min-width:0;box-sizing:border-box}.cmd-installments-list{display:grid;gap:6px;margin-top:8px}.cmd-installment-row{display:grid;grid-template-columns:74px minmax(130px,1fr) minmax(110px,1fr);gap:8px;align-items:center;border-bottom:1px solid #e2e8f0;padding:6px 0}.cmd-installment-row.head{font-size:12px;text-transform:uppercase;color:#475569;font-weight:950;background:#f8fafc;border-bottom:1px solid #cbd5e1}.cmd-installment-row input{height:38px;padding:7px 8px}.cmd-installment-label{font-weight:900;color:#0b1f5b}@media(max-width:760px){.cmd-installments-head{display:grid;grid-template-columns:1fr}.cmd-installments-head label{max-width:none}.cmd-installments-head .btn{width:100%}.cmd-installment-row{grid-template-columns:1fr}.cmd-installment-row.head{display:none}.cmd-installment-row .cmd-installment-label:before{content:'Parcela: ';color:#64748b}.cmd-installment-row .cmd-installment-due-wrap:before{content:'Vencimento';display:block;font-size:12px;font-weight:900;color:#64748b;margin-bottom:3px}.cmd-installment-row .cmd-installment-amount-wrap:before{content:'Valor';display:block;font-size:12px;font-weight:900;color:#64748b;margin-bottom:3px}}@media(max-width:1100px){.cmd-read-grid.has-merge{grid-template-columns:1fr}.cmd-read-actions.has-three{grid-template-columns:repeat(3,minmax(0,1fr))}.cmd-transfer-source{grid-template-columns:1fr}.cmd-stepper,.cmd-summary,.cmd-product-layout,.cmd-ident-grid,.cmd-pay-grid,.cmd-payment-step-grid,.cmd-read-grid,.cmd-search,.cmd-transfer-grid,.cmd-customer-toolbar{grid-template-columns:1fr}.cmd-products{max-height:none}.cmd-item{grid-template-columns:1fr}.cmd-payment-summary{position:static}.cmd-payment-line,.cmd-payment-line.show-received{grid-template-columns:1fr 42px}.cmd-payment-line label:first-child,.cmd-payment-line .pay-value,.cmd-payment-line .pay-received-label{grid-column:1 / -1}.cmd-payment-line .pay-remove{grid-column:2}.cmd-actions{justify-content:flex-start}}

.cmd-mobile-mode{--cmd-mobile-blue:#143a9c;--cmd-mobile-blue2:#2563eb;--cmd-mobile-bg:#f3f7ff;padding-bottom:86px}.cmd-mobile-mode .cmd-stepper{position:sticky;top:0;z-index:35;background:linear-gradient(135deg,#0b1f5b,#2563eb);margin:-12px -12px 12px;padding:10px;border-radius:0 0 22px 22px;grid-template-columns:repeat(4,minmax(58px,1fr));gap:6px;box-shadow:0 12px 28px rgba(15,23,42,.16)}.cmd-mobile-mode .cmd-step{min-width:0;grid-template-columns:1fr;text-align:center;padding:7px 4px;border-radius:15px;background:rgba(255,255,255,.13);border-color:rgba(255,255,255,.18);box-shadow:none;color:#fff}.cmd-mobile-mode .cmd-step span{width:28px;height:28px;border-radius:10px;margin:0 auto;background:rgba(255,255,255,.22);font-size:13px;color:#fff}.cmd-mobile-mode .cmd-step strong{font-size:11px;color:#eaf2ff}.cmd-mobile-mode .cmd-step small,.cmd-mobile-mode .cmd-panel-head p,.cmd-mobile-mode .cmd-prod span,.cmd-mobile-mode .cmd-muted:not(.force-show){display:none!important}.cmd-mobile-mode .cmd-step.active{background:#fff;border-color:#fff}.cmd-mobile-mode .cmd-step.active span{background:linear-gradient(135deg,var(--cmd-mobile-blue),var(--cmd-mobile-blue2));color:#fff}.cmd-mobile-mode .cmd-step.active strong{color:#0b1f5b}.cmd-mobile-mode .mg-card{border-radius:25px;padding:13px;box-shadow:0 15px 36px rgba(15,23,42,.08)}.cmd-mobile-mode .cmd-panel-head{align-items:center;margin-bottom:10px}.cmd-mobile-mode .cmd-panel-head h2{font-size:19px;margin:0}.cmd-mobile-mode .cmd-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%}.cmd-mobile-mode .cmd-actions .btn{min-height:42px;border-radius:16px}.cmd-mobile-mode .cmd-read-grid{grid-template-columns:1fr}.cmd-mobile-mode .cmd-read-grid input,.cmd-mobile-mode .cmd-search input{height:54px;border-radius:19px;font-size:17px;font-weight:800}.cmd-mobile-mode .cmd-read-grid label,.cmd-mobile-mode .cmd-search label{font-size:0}.cmd-mobile-mode .cmd-comandas-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.cmd-mobile-mode .cmd-tile{min-height:92px;aspect-ratio:auto;border-radius:18px;padding:9px}.cmd-mobile-mode .cmd-tile strong{font-size:19px}.cmd-mobile-mode .cmd-summary{grid-template-columns:1fr 1fr;gap:8px}.cmd-mobile-mode .cmd-summary div{padding:10px;border-radius:16px}.cmd-mobile-mode .cmd-summary span{font-size:11px}.cmd-mobile-mode .cmd-summary strong{font-size:16px}.cmd-mobile-mode .cmd-items{gap:7px}.cmd-mobile-mode .cmd-item{grid-template-columns:minmax(0,1fr) auto;border-radius:16px}.cmd-mobile-mode .cmd-item form{grid-column:1 / -1}.cmd-mobile-mode .cmd-product-layout{display:block}.cmd-mobile-mode .cmd-search{grid-template-columns:1fr}.cmd-mobile-mode .cmd-search .cmd-total-box{position:fixed;left:12px;right:12px;bottom:12px;z-index:30;background:#0f172a;color:#fff;border-color:#1e293b;box-shadow:0 16px 42px rgba(2,6,23,.28)}.cmd-mobile-mode .cmd-search .cmd-total-box strong{color:#fff;font-size:22px}.cmd-mobile-mode .cmd-cats{padding:7px 0 9px}.cmd-mobile-mode .cmd-cat{padding:9px 13px;border-radius:15px}.cmd-mobile-mode .cmd-products{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;max-height:none;overflow:visible;padding:0 0 88px}.cmd-mobile-mode .cmd-prod{display:flex;flex-direction:column;align-items:stretch;gap:8px;min-height:146px;padding:9px;border-radius:21px}.cmd-mobile-mode .cmd-prod img{width:100%;height:72px;border-radius:16px}.cmd-mobile-mode .cmd-prod strong{font-size:13px;line-height:1.2;min-height:32px}.cmd-mobile-mode .cmd-prod b{font-size:16px;margin-top:3px}.cmd-mobile-mode aside .cmd-total-box{display:none}.cmd-mobile-mode .cmd-cart{max-height:260px;margin-bottom:10px}.cmd-mobile-mode .cmd-cart-item{border-radius:17px}.cmd-mobile-mode .cmd-qty{grid-template-columns:38px 1fr 38px 38px}.cmd-mobile-mode .cmd-product-layout aside{margin-top:10px;background:#f8fbff;border:1px solid #dbe4f0;border-radius:22px;padding:10px}.cmd-mobile-mode .cmd-product-layout aside .cmd-actions{grid-template-columns:1fr}.cmd-mobile-mode .cmd-product-layout aside .btn-primary{min-height:56px;font-size:17px;text-transform:uppercase;letter-spacing:.02em}.cmd-mobile-mode .cmd-ident-grid,.cmd-mobile-mode .cmd-payment-step-grid{grid-template-columns:1fr}.cmd-mobile-mode .cmd-payment-summary{background:#0f172a;color:#fff;border-color:#1e293b;box-shadow:0 16px 40px rgba(2,6,23,.24)}.cmd-mobile-mode .cmd-payment-summary span,.cmd-mobile-mode .cmd-payment-summary strong{color:#fff!important}.cmd-mobile-mode .cmd-finish-button{min-height:58px;border-radius:20px;font-size:17px;text-transform:uppercase}.cmd-mobile-toast{position:fixed;left:50%;bottom:86px;z-index:9999;transform:translate(-50%,16px);background:#0f172a;color:#fff;border-radius:999px;padding:12px 18px;font-weight:950;box-shadow:0 18px 45px rgba(2,6,23,.28);opacity:0;pointer-events:none;transition:.18s}.cmd-mobile-toast.show{opacity:1;transform:translate(-50%,0)}
.cmd-tile-wrap{display:grid;gap:7px}.cmd-tile-wrap .cmd-tile{width:100%;box-sizing:border-box}.cmd-mobile-tile-pay{display:none;text-align:center;text-decoration:none;border:0;border-radius:16px;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;padding:10px 12px;font-weight:950;box-shadow:0 10px 24px rgba(34,197,94,.22)}.cmd-mobile-product-footer{display:none}.cmd-mobile-mode .cmd-mobile-tile-pay{display:block}.cmd-mobile-mode .cmd-mobile-product-footer{position:fixed;left:14px;right:14px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:170;background:#071326;color:#fff;border-radius:25px;padding:12px 12px 12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 20px 50px rgba(2,6,23,.38)}.cmd-mobile-footer-info{display:grid;gap:2px}.cmd-mobile-footer-info span{font-size:11px;font-weight:850;color:#cbd5e1;text-transform:uppercase;letter-spacing:.05em}.cmd-mobile-footer-info strong{font-size:22px;line-height:1;color:#fff}.cmd-mobile-process-btn{border:0;border-radius:18px;background:linear-gradient(135deg,#143a9c,#2563eb);color:#fff;min-height:52px;padding:0 20px;font-weight:950;font-size:15px;box-shadow:0 10px 28px rgba(37,99,235,.35)}.cmd-mobile-mode .cmd-products{padding-bottom:110px}.cmd-mobile-mode .cmd-product-layout aside{display:none}.cmd-mobile-mode .cmd-search .cmd-total-box{display:none}.cmd-mobile-mode .cmd-prod{box-shadow:0 12px 30px rgba(11,31,91,.08);border-color:#dbeafe}.cmd-mobile-mode .cmd-prod.in-cart:after{content:'Adicionado';position:absolute;right:9px;top:9px;background:#047857;color:#fff;border-radius:999px;padding:4px 7px;font-size:10px;font-weight:950}.cmd-mobile-mode .cmd-prod{position:relative}.cmd-mobile-mode .cmd-panel-head .cmd-actions{grid-template-columns:1fr 1fr}.cmd-mobile-mode .cmd-panel-head .cmd-actions .btn{font-size:13px}.cmd-mobile-mode .cmd-tile-wrap{min-width:0}.cmd-mobile-mode .cmd-tile{border-radius:21px;box-shadow:0 13px 32px rgba(11,31,91,.08)}
@media(max-width:520px){.cmd-mobile-mode .cmd-comandas-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cmd-mobile-mode .cmd-actions{grid-template-columns:1fr}.cmd-mobile-mode .cmd-summary{grid-template-columns:1fr}.cmd-mobile-mode .pdv-fiscal-choice-grid{grid-template-columns:1fr!important}}

/* Ajuste V10: Comanda Mobile em formato aplicativo, sem aparência de desktop */
body.admin-compact-mobile .mg-page{margin:0 auto;padding:0;max-width:540px;}
.cmd-mobile-mode{max-width:540px;margin:0 auto;gap:10px;padding:0 0 98px;}
.cmd-mobile-mode .mg-card{padding:12px;border-radius:24px;box-shadow:0 12px 30px rgba(15,23,42,.07);}
.cmd-mobile-mode .cmd-read-card{border-radius:0 0 26px 26px;margin:0 -2px 10px;padding-top:14px;}
.cmd-mobile-mode .cmd-panel-head{margin-bottom:9px;gap:8px;}
.cmd-mobile-mode .cmd-panel-head h2{font-size:18px;letter-spacing:-.02em;}
.cmd-mobile-mode .cmd-panel-head > a.btn[href*="gestao_comandas_config"]{display:none!important;}
.cmd-mobile-mode .cmd-stepper{margin:0 -10px 10px;padding:9px 10px;border-radius:0 0 22px 22px;}
.cmd-mobile-mode .cmd-step strong{font-size:10px;}
.cmd-mobile-mode .cmd-step span{width:26px;height:26px;border-radius:9px;}
.cmd-mobile-mode .cmd-read-grid input,.cmd-mobile-mode .cmd-search input{height:52px;border-radius:18px;font-size:16px;}
.cmd-mobile-mode .cmd-comandas-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
.cmd-mobile-mode .cmd-tile{min-height:86px;border-radius:19px;padding:9px;}
.cmd-mobile-mode .cmd-tile strong{font-size:20px;}
.cmd-mobile-mode .cmd-mobile-tile-pay{min-height:40px;border-radius:15px;display:flex;align-items:center;justify-content:center;}
.cmd-mobile-mode .cmd-product-layout{display:block;}
.cmd-mobile-mode .cmd-search{position:sticky;top:0;z-index:42;background:#f3f7ff;margin:-2px -2px 8px;padding:2px 2px 8px;}
.cmd-mobile-mode .cmd-cats{gap:7px;margin:0 -2px 8px;padding:2px 2px 8px;}
.cmd-mobile-mode .cmd-cat{min-height:40px;padding:9px 12px;border-radius:999px;font-size:11px;font-weight:950;text-transform:uppercase;color:#1685d9;border-color:#dbeafe;}
.cmd-mobile-mode .cmd-products{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:calc(100dvh - 244px);overflow:auto;padding:2px 2px 112px;}
.cmd-mobile-mode .cmd-prod{min-height:132px;border-radius:22px;padding:8px;background:#fff;border:1px solid #dbeafe;box-shadow:0 9px 22px rgba(15,23,42,.06);display:grid;grid-template-columns:1fr;gap:6px;align-items:stretch;}
.cmd-mobile-mode .cmd-prod img{width:100%;height:58px;border-radius:16px;object-fit:cover;}
.cmd-mobile-mode .cmd-prod strong{font-size:12.5px;line-height:1.12;min-height:28px;text-transform:uppercase;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.cmd-mobile-mode .cmd-prod b{font-size:15px;margin-top:0;color:#047857;}
.cmd-mobile-mode .cmd-prod.in-cart:after{right:7px;top:7px;font-size:9px;padding:3px 6px;}
.cmd-mobile-mode .cmd-mobile-product-footer{left:10px;right:10px;bottom:calc(10px + env(safe-area-inset-bottom));border-radius:23px;padding:11px 11px 11px 14px;}
.cmd-mobile-mode .cmd-mobile-process-btn{min-height:50px;border-radius:17px;padding:0 17px;}
.cmd-mobile-mode .finance-modal{align-items:flex-end;padding:0;}
.cmd-mobile-mode .finance-modal-dialog{border-radius:28px 28px 0 0;max-height:88dvh;overflow:auto;padding:14px;}
@media(max-width:380px){body.admin-compact-mobile .container{padding-left:6px;padding-right:6px}.cmd-mobile-mode .cmd-products{gap:7px;max-height:calc(100dvh - 236px)}.cmd-mobile-mode .cmd-prod{min-height:122px;padding:7px;border-radius:20px}.cmd-mobile-mode .cmd-prod img{height:52px}.cmd-mobile-mode .cmd-prod strong{font-size:11.5px;min-height:26px}.cmd-mobile-mode .cmd-prod b{font-size:14px}.cmd-mobile-mode .cmd-cat{padding:8px 10px;font-size:10px}}
@media(min-width:620px){body.admin-compact-mobile .container{max-width:540px}.cmd-mobile-mode .cmd-products{grid-template-columns:repeat(2,minmax(0,1fr));}.cmd-mobile-mode .cmd-prod{min-height:136px}.cmd-mobile-mode .cmd-prod img{height:62px}}

/* Ajuste V11: responsividade real da tela Produtos no Comanda Mobile */
body.admin-compact-mobile,
body:has(.cmd-mobile-mode){
  margin:0!important;
  width:100%!important;
  max-width:100%!important;
  overflow-x:hidden!important;
  background:#f3f7ff!important;
}
body.admin-compact-mobile .container,
body:has(.cmd-mobile-mode) .container{
  width:100%!important;
  max-width:460px!important;
  min-width:0!important;
  margin:0 auto!important;
  padding:0 8px 104px!important;
  overflow-x:hidden!important;
}
.cmd-mobile-mode,
.cmd-mobile-mode *{box-sizing:border-box;}
.cmd-mobile-mode{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  margin:0 auto!important;
  overflow-x:hidden!important;
}
.cmd-mobile-mode .mg-card{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  overflow:hidden;
}
.cmd-mobile-mode .cmd-products-card{
  min-height:100dvh;
  margin:0 -8px!important;
  padding:8px 8px 118px!important;
  border:0!important;
  border-radius:0!important;
  box-shadow:none!important;
  background:#f3f7ff!important;
  overflow:visible!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-panel-head{
  display:grid!important;
  grid-template-columns:1fr!important;
  gap:8px!important;
  margin:0 0 8px!important;
  align-items:stretch!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-panel-head h2{
  font-size:17px!important;
  line-height:1.15!important;
  margin:0!important;
  white-space:normal!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-panel-head p{display:none!important;}
.cmd-mobile-mode .cmd-products-card .cmd-panel-head .cmd-actions{
  display:grid!important;
  grid-template-columns:1fr 1fr!important;
  gap:7px!important;
  width:100%!important;
  justify-content:stretch!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-panel-head .cmd-actions .btn{
  width:100%!important;
  min-width:0!important;
  min-height:38px!important;
  padding:8px 7px!important;
  border-radius:14px!important;
  font-size:12px!important;
  line-height:1.1!important;
  white-space:normal!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-panel-head .cmd-actions .btn:nth-child(n+3){display:none!important;}
.cmd-mobile-mode .cmd-products-card .cmd-product-layout{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-product-layout > div{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-search{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  position:sticky!important;
  top:0!important;
  z-index:70!important;
  margin:0!important;
  padding:8px 0 6px!important;
  background:#f3f7ff!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-search label{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  font-size:0!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-search input{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:54px!important;
  border-radius:19px!important;
  font-size:16px!important;
  font-weight:850!important;
  padding:0 14px!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-cats{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  overflow-x:auto!important;
  overflow-y:hidden!important;
  -webkit-overflow-scrolling:touch!important;
  gap:7px!important;
  margin:0!important;
  padding:2px 0 8px!important;
  scrollbar-width:none;
}
.cmd-mobile-mode .cmd-products-card .cmd-cats::-webkit-scrollbar{display:none;}
.cmd-mobile-mode .cmd-products-card .cmd-cat{
  flex:0 0 auto!important;
  min-height:40px!important;
  min-width:auto!important;
  padding:9px 12px!important;
  font-size:11px!important;
  border-radius:999px!important;
  white-space:nowrap!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-products{
  display:grid!important;
  grid-template-columns:repeat(2,minmax(0,1fr))!important;
  gap:8px!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:calc(100dvh - 218px)!important;
  min-height:280px!important;
  max-height:none!important;
  overflow-y:auto!important;
  overflow-x:hidden!important;
  -webkit-overflow-scrolling:touch!important;
  padding:2px 0 132px!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-products .cmd-empty{
  grid-column:1/-1!important;
  min-width:0!important;
  border-radius:22px!important;
  padding:24px 14px!important;
  text-align:center!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-prod{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  min-height:136px!important;
  padding:8px!important;
  border-radius:22px!important;
  display:grid!important;
  grid-template-columns:1fr!important;
  grid-template-rows:auto minmax(0,1fr)!important;
  gap:6px!important;
  align-items:stretch!important;
  text-align:left!important;
  overflow:hidden!important;
  touch-action:manipulation;
}
.cmd-mobile-mode .cmd-products-card .cmd-prod img{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:58px!important;
  object-fit:cover!important;
  border-radius:16px!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-prod > div{
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  display:grid!important;
  gap:3px!important;
  align-content:start!important;
}
.cmd-mobile-mode .cmd-products-card .cmd-prod strong{
  min-width:0!important;
  min-height:28px!important;
  max-height:31px!important;
  display:-webkit-box!important;
  -webkit-line-clamp:2;
  -webkit-box-orient:vertical;
  overflow:hidden!important;
  font-size:12px!important;
  line-height:1.15!important;
  word-break:break-word!important;
  text-transform:uppercase;
}
.cmd-mobile-mode .cmd-products-card .cmd-prod span{display:none!important;}
.cmd-mobile-mode .cmd-products-card .cmd-prod b{
  font-size:15px!important;
  line-height:1.1!important;
  margin-top:0!important;
  white-space:nowrap!important;
}
.cmd-mobile-mode .cmd-products-card aside{display:none!important;}
.cmd-mobile-mode .cmd-mobile-product-footer{
  left:8px!important;
  right:8px!important;
  bottom:calc(8px + env(safe-area-inset-bottom))!important;
  max-width:444px!important;
  margin:0 auto!important;
  border-radius:22px!important;
  padding:10px 10px 10px 13px!important;
  min-width:0!important;
}
.cmd-mobile-mode .cmd-mobile-process-btn{
  min-width:112px!important;
  min-height:50px!important;
  padding:0 15px!important;
  border-radius:16px!important;
  font-size:14px!important;
}
@media(max-width:380px){
  body.admin-compact-mobile .container,
  body:has(.cmd-mobile-mode) .container{padding-left:6px!important;padding-right:6px!important;}
  .cmd-mobile-mode .cmd-products-card{margin-left:-6px!important;margin-right:-6px!important;padding-left:6px!important;padding-right:6px!important;}
  .cmd-mobile-mode .cmd-products-card .cmd-products{gap:7px!important;height:calc(100dvh - 212px)!important;}
  .cmd-mobile-mode .cmd-products-card .cmd-prod{min-height:126px!important;border-radius:20px!important;padding:7px!important;}
  .cmd-mobile-mode .cmd-products-card .cmd-prod img{height:52px!important;border-radius:15px!important;}
  .cmd-mobile-mode .cmd-products-card .cmd-prod strong{font-size:11.2px!important;min-height:26px!important;}
  .cmd-mobile-mode .cmd-products-card .cmd-prod b{font-size:14px!important;}
  .cmd-mobile-mode .cmd-mobile-product-footer{left:6px!important;right:6px!important;}
}
@media(min-width:520px){
  body.admin-compact-mobile .container,
  body:has(.cmd-mobile-mode) .container{max-width:480px!important;}
  .cmd-mobile-mode .cmd-products-card .cmd-products{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
}

/* V12 - Comanda Mobile: passo de produtos com disposição igual ao Venda Mobile.
   Este bloco é propositalmente mais específico que o layout desktop para evitar que
   cards, grids e containers do módulo Comandas sejam herdados em tamanho de desktop. */
.cmd-mobile-products-like-pdv{
  --cmd-app-blue:#143a9c;
  --cmd-app-blue2:#2563eb;
  --cmd-app-dark:#071326;
  --cmd-app-border:#dbe8f8;
  --cmd-app-bg:#f3f7ff;
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  padding-bottom:96px!important;
  overflow-x:hidden!important;
}
.cmd-mobile-products-like-pdv .cmd-stepper{display:none!important;}
.cmd-mobile-products-like-pdv .cmd-products-card{
  min-height:100dvh!important;
  margin:0!important;
  padding:14px 14px 104px!important;
  border:0!important;
  border-radius:0!important;
  box-shadow:none!important;
  background:var(--cmd-app-bg)!important;
  overflow:hidden!important;
}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head{
  display:grid!important;
  grid-template-columns:1fr!important;
  gap:10px!important;
  align-items:stretch!important;
  margin:0 0 10px!important;
}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head h2{
  font-size:19px!important;
  line-height:1.15!important;
  margin:0!important;
  color:#0b1f5b!important;
  letter-spacing:-.02em!important;
}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head h2::before{content:'Produtos';}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head h2{font-size:0!important;}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head h2::before{font-size:19px!important;}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head p{display:none!important;}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head .cmd-actions{
  display:grid!important;
  grid-template-columns:1fr 1fr!important;
  gap:8px!important;
  width:100%!important;
}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head .cmd-actions .btn{
  width:100%!important;
  min-width:0!important;
  min-height:44px!important;
  border-radius:17px!important;
  padding:9px 10px!important;
  font-size:13px!important;
  line-height:1.05!important;
  white-space:normal!important;
}
.cmd-mobile-products-like-pdv .cmd-products-card .cmd-panel-head .cmd-actions .btn:nth-child(n+3){display:none!important;}
.cmd-mobile-products-like-pdv .cmd-product-layout,
.cmd-mobile-products-like-pdv .cmd-product-layout > div{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
}
.cmd-mobile-products-like-pdv .cmd-search{
  display:grid!important;
  grid-template-columns:1fr!important;
  gap:8px!important;
  position:sticky!important;
  top:0!important;
  z-index:80!important;
  margin:0 0 8px!important;
  padding:0 0 8px!important;
  background:var(--cmd-app-bg)!important;
}
.cmd-mobile-products-like-pdv .cmd-search label{font-size:0!important;margin:0!important;}
.cmd-mobile-products-like-pdv .cmd-search input{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:56px!important;
  border-radius:20px!important;
  border:2px solid var(--cmd-app-border)!important;
  background:#fff!important;
  box-shadow:0 10px 26px rgba(15,23,42,.05)!important;
  font-size:16px!important;
  font-weight:750!important;
  color:#0f172a!important;
  padding:0 16px!important;
}
.cmd-mobile-products-like-pdv .cmd-search input:focus{
  border-color:#2563eb!important;
  box-shadow:0 0 0 4px rgba(37,99,235,.12)!important;
}
.cmd-mobile-products-like-pdv .cmd-search .cmd-total-box{display:none!important;}
.cmd-mobile-products-like-pdv .cmd-cats{
  display:flex!important;
  gap:8px!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  overflow-x:auto!important;
  overflow-y:hidden!important;
  -webkit-overflow-scrolling:touch!important;
  margin:0 -2px 10px!important;
  padding:2px 2px 8px!important;
  scrollbar-width:none!important;
}
.cmd-mobile-products-like-pdv .cmd-cats::-webkit-scrollbar{display:none!important;}
.cmd-mobile-products-like-pdv .cmd-cat{
  flex:0 0 auto!important;
  min-width:auto!important;
  min-height:44px!important;
  border-radius:999px!important;
  padding:10px 14px!important;
  font-size:12px!important;
  font-weight:950!important;
  text-transform:uppercase!important;
  color:#1685d9!important;
  border-color:#dbeafe!important;
  background:#fff!important;
  white-space:nowrap!important;
}
.cmd-mobile-products-like-pdv .cmd-cat.active{
  background:linear-gradient(135deg,#0b1f5b,#2563eb)!important;
  color:#fff!important;
  border-color:#143a9c!important;
}
.cmd-mobile-products-like-pdv .cmd-products{
  display:grid!important;
  grid-template-columns:repeat(2,minmax(0,1fr))!important;
  gap:10px!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:calc(100dvh - 216px)!important;
  min-height:280px!important;
  max-height:none!important;
  overflow-y:auto!important;
  overflow-x:hidden!important;
  -webkit-overflow-scrolling:touch!important;
  padding:2px 2px 98px!important;
}
.cmd-mobile-products-like-pdv .cmd-products .cmd-empty{
  grid-column:1 / -1!important;
  min-height:160px!important;
  display:flex!important;
  align-items:center!important;
  justify-content:center!important;
  text-align:center!important;
  color:#94a3b8!important;
  background:#fff!important;
  border:1px dashed #dbe4f0!important;
  border-radius:24px!important;
  padding:18px!important;
}
.cmd-mobile-products-like-pdv .cmd-prod{
  display:grid!important;
  grid-template-columns:1fr!important;
  grid-template-rows:auto minmax(0,1fr)!important;
  gap:7px!important;
  align-items:stretch!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  min-height:142px!important;
  padding:10px!important;
  border-radius:24px!important;
  background:#fff!important;
  border:1px solid #dbeafe!important;
  box-shadow:0 10px 24px rgba(15,23,42,.07)!important;
  text-align:left!important;
  overflow:hidden!important;
  touch-action:manipulation!important;
}
.cmd-mobile-products-like-pdv .cmd-prod:hover{transform:none!important;}
.cmd-mobile-products-like-pdv .cmd-prod img{
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  min-width:0!important;
  height:68px!important;
  object-fit:cover!important;
  border-radius:18px!important;
  background:#f1f5f9!important;
}
.cmd-mobile-products-like-pdv .cmd-prod > div{
  width:100%!important;
  min-width:0!important;
  display:grid!important;
  gap:4px!important;
  align-content:start!important;
}
.cmd-mobile-products-like-pdv .cmd-prod strong{
  min-width:0!important;
  min-height:30px!important;
  max-height:32px!important;
  display:-webkit-box!important;
  -webkit-line-clamp:2;
  -webkit-box-orient:vertical;
  overflow:hidden!important;
  color:#0b1f5b!important;
  font-size:13px!important;
  line-height:1.15!important;
  text-transform:uppercase!important;
  word-break:break-word!important;
}
.cmd-mobile-products-like-pdv .cmd-prod span{display:none!important;}
.cmd-mobile-products-like-pdv .cmd-prod b{
  font-size:16px!important;
  line-height:1.1!important;
  margin:0!important;
  color:#047857!important;
  white-space:nowrap!important;
}
.cmd-mobile-products-like-pdv .cmd-prod.in-cart{background:#eff6ff!important;border-color:#2563eb!important;}
.cmd-mobile-products-like-pdv .cmd-prod.in-cart:after{
  content:'Adicionado';
  position:absolute;
  right:8px;
  top:8px;
  background:#047857;
  color:#fff;
  border-radius:999px;
  padding:3px 7px;
  font-size:9px;
  font-weight:950;
}
.cmd-mobile-products-like-pdv aside{display:none!important;}
.cmd-mobile-products-like-pdv .cmd-mobile-product-footer{
  position:fixed!important;
  left:14px!important;
  right:14px!important;
  bottom:calc(12px + env(safe-area-inset-bottom))!important;
  z-index:170!important;
  max-width:432px!important;
  margin:0 auto!important;
  background:var(--cmd-app-dark)!important;
  color:#fff!important;
  border-radius:25px!important;
  padding:12px 12px 12px 16px!important;
  display:flex!important;
  align-items:center!important;
  justify-content:space-between!important;
  gap:12px!important;
  box-shadow:0 20px 50px rgba(2,6,23,.35)!important;
}
.cmd-mobile-products-like-pdv .cmd-mobile-process-btn{
  border:0!important;
  border-radius:18px!important;
  background:linear-gradient(135deg,#143a9c,#2563eb)!important;
  color:#fff!important;
  min-height:52px!important;
  padding:0 20px!important;
  font-weight:950!important;
  font-size:15px!important;
  box-shadow:0 10px 28px rgba(37,99,235,.35)!important;
}
@media(max-width:380px){
  .cmd-mobile-products-like-pdv .cmd-products-card{padding-left:10px!important;padding-right:10px!important;}
  .cmd-mobile-products-like-pdv .cmd-products{gap:8px!important;height:calc(100dvh - 208px)!important;}
  .cmd-mobile-products-like-pdv .cmd-prod{min-height:132px!important;padding:8px!important;border-radius:22px!important;}
  .cmd-mobile-products-like-pdv .cmd-prod img{height:58px!important;border-radius:16px!important;}
  .cmd-mobile-products-like-pdv .cmd-prod strong{font-size:12px!important;min-height:28px!important;max-height:30px!important;}
  .cmd-mobile-products-like-pdv .cmd-prod b{font-size:15px!important;}
  .cmd-mobile-products-like-pdv .cmd-mobile-product-footer{left:10px!important;right:10px!important;}
}
@media(min-width:1px){
  .cmd-mobile-products-like-pdv .cmd-products{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
}

/* Ajuste V12: conferência e navegação flutuante do Comanda Mobile */
.cmd-mobile-mode .cmd-mobile-nav-footer{
  position:fixed;
  left:10px;
  right:10px;
  bottom:calc(10px + env(safe-area-inset-bottom));
  z-index:190;
  display:grid;
  grid-template-columns:1fr 1.45fr;
  gap:9px;
  padding:10px;
  border-radius:22px;
  background:#071326;
  box-shadow:0 20px 50px rgba(2,6,23,.38);
}
.cmd-mobile-mode .cmd-mobile-nav-footer.single{grid-template-columns:1fr;}
.cmd-mobile-mode .cmd-mobile-nav-footer .btn{
  min-height:52px;
  border-radius:17px;
  font-size:15px;
  font-weight:950;
  border-color:#dbeafe;
}
.cmd-mobile-mode .cmd-mobile-nav-footer .btn:not(.btn-primary){background:#fff;color:#0f172a;}
.cmd-mobile-mode .cmd-mobile-review-list{display:grid;gap:8px;margin-top:10px;padding-bottom:4px;}
.cmd-mobile-mode .cmd-mobile-review-item{
  position:relative;
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  gap:4px 10px;
  padding:12px;
  border:1px solid #dbeafe;
  border-radius:17px;
  background:#fff;
  box-shadow:0 8px 22px rgba(15,23,42,.05);
}
.cmd-mobile-mode .cmd-mobile-review-item .cmd-mobile-review-name{font-size:13px;line-height:1.18;font-weight:950;text-transform:uppercase;}
.cmd-mobile-mode .cmd-mobile-review-item .cmd-mobile-review-price{font-size:13px;font-weight:950;white-space:nowrap;text-align:right;}
.cmd-mobile-mode .cmd-mobile-review-item .cmd-mobile-review-detail{font-size:11px;font-weight:850;color:#475569;}
.cmd-mobile-mode .cmd-mobile-review-item .cmd-mobile-review-obs{grid-column:1 / -1;font-size:11px;line-height:1.25;font-weight:850;border-left:3px solid #0B1F5B;padding-left:7px;white-space:pre-wrap;overflow-wrap:anywhere;}
.cmd-mobile-mode .cmd-mobile-review-total{display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding:12px 14px;border-radius:17px;background:#eff6ff;border:1px solid #bfdbfe;font-weight:950;}
.cmd-mobile-mode .cmd-mobile-review-total strong{font-size:20px;color:#0B1F5B;}
.cmd-mobile-mode.cmd-mobile-review .mg-card,
.cmd-mobile-mode.cmd-mobile-customer .mg-card,
.cmd-mobile-mode.cmd-mobile-payment .mg-card{padding-bottom:18px;}
.cmd-mobile-mode.cmd-mobile-payment .cmd-payment-step-grid{display:block;}
.cmd-mobile-mode.cmd-mobile-payment .cmd-payments-box{border:0;padding:0;background:transparent;}
.cmd-mobile-mode.cmd-mobile-payment .cmd-payments-head{align-items:center;}
.cmd-mobile-mode.cmd-mobile-payment .cmd-payments-head small{display:none;}
.cmd-mobile-mode.cmd-mobile-payment .cmd-payment-line{border-radius:17px;}
.cmd-mobile-mode.cmd-mobile-payment .cmd-payment-summary{display:none!important;}
body.admin-compact-mobile-menu .cmd-mobile-products-like-pdv .cmd-products{height:calc(100dvh - 274px)!important;}
@media(max-width:380px){
  body.admin-compact-mobile-menu .cmd-mobile-products-like-pdv .cmd-products{height:calc(100dvh - 268px)!important;}
  .cmd-mobile-mode .cmd-mobile-nav-footer{left:7px;right:7px;gap:7px;padding:8px;}
  .cmd-mobile-mode .cmd-mobile-nav-footer .btn{min-height:48px;font-size:13px;padding:8px;}
}


/* V13 - Cadastro rápido, conferência do carrinho e ajustes financeiros */
.cmd-selected-customer-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.cmd-cart-review-dialog{width:min(720px,100%)}
.cmd-cart-review-list{display:grid;gap:9px;max-height:55dvh;overflow:auto;padding-right:3px}
.cmd-cart-review-list .cmd-cart-item{background:#f8fbff;border-color:#dbe8f8}
.cmd-cart-review-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.cmd-cart-review-summary>div{border:1px solid #dbe8f8;background:#eff6ff;border-radius:18px;padding:12px}
.cmd-cart-review-summary span{display:block;color:#64748b;font-size:12px;font-weight:850}
.cmd-cart-review-summary strong{display:block;margin-top:4px;color:#0b1f5b;font-size:21px}
.cmd-adjustments-card{border:1px solid #dbe8f8;background:#f8fbff;border-radius:22px;padding:13px;margin-bottom:12px}
.cmd-adjustments-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.cmd-adjustments-head strong{color:#0b1f5b;font-size:17px}.cmd-adjustments-head small{display:block;color:#64748b;margin-top:2px}
.cmd-adjustments-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.cmd-adjustments-grid input{font-weight:850}
.cmd-checkout-mode .cmd-checkout-adjustments-grid{grid-template-columns:minmax(145px,1.45fr) 96px 82px minmax(96px,.9fr) 82px minmax(96px,.9fr) minmax(96px,.9fr)!important;gap:7px!important;align-items:end!important}
.cmd-checkout-mode .cmd-checkout-adjustments-grid>label{min-width:0;margin:0;font-size:11px;line-height:1.1}
.cmd-checkout-mode .cmd-checkout-adjustments-grid input,.cmd-checkout-mode .cmd-checkout-adjustments-grid select,.cmd-checkout-mode .cmd-checkout-adjustments-grid .btn{width:100%;min-width:0;height:42px;min-height:42px;box-sizing:border-box;padding:7px 8px}
.cmd-checkout-mode .cmd-checkout-adjustments-grid .cmd-seller-search-action{align-self:end}
.cmd-checkout-mode .cmd-checkout-adjustments-grid .cmd-seller-search-action .btn{font-size:12px;font-weight:900;white-space:nowrap}
.cmd-checkout-mode .cmd-adjustments-head{margin-bottom:8px}
.cmd-checkout-mode .cmd-adjustments-card{padding:10px 11px}
.cmd-seller-modal-toolbar{display:grid;grid-template-columns:minmax(260px,1fr) auto auto auto;gap:9px;align-items:end;margin-bottom:12px}
.cmd-seller-modal-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:900;color:#475569}
.cmd-seller-modal-toolbar input,.cmd-seller-modal-toolbar .btn{height:44px;min-height:44px;box-sizing:border-box}
.cmd-seller-modal-results{border:1px solid #dbe4f0;border-radius:16px;overflow:auto;max-height:52vh;background:#fff}
.cmd-seller-row{width:100%;border:0;border-bottom:1px solid #edf2f7;background:#fff;padding:11px 13px;display:grid;grid-template-columns:120px minmax(0,1fr) 160px;gap:10px;align-items:center;text-align:left;cursor:pointer;color:#1e293b}
.cmd-seller-row:last-child{border-bottom:0}.cmd-seller-row:hover,.cmd-seller-row.is-active{background:#eaf2ff;box-shadow:inset 4px 0 0 #2563eb}.cmd-seller-row strong{color:#0b1f5b}.cmd-seller-row small{color:#64748b}.cmd-seller-row code{font-family:inherit;font-weight:900;color:#174f8b;background:transparent}
@media(max-width:1100px){.cmd-seller-modal-toolbar{grid-template-columns:1fr 1fr}.cmd-seller-row{grid-template-columns:90px minmax(0,1fr)}.cmd-seller-row small{grid-column:2}}
.cmd-adjusted-total{display:flex;justify-content:space-between;gap:12px;align-items:center;border-top:1px solid #dbe8f8;margin-top:11px;padding-top:11px}
.cmd-adjusted-total span{color:#64748b;font-weight:850}.cmd-adjusted-total strong{font-size:24px;color:#047857}
.cmd-mobile-footer-info{appearance:none;border:0;background:transparent;color:inherit;text-align:left;padding:0;cursor:pointer;min-width:0}
.cmd-mobile-footer-info span,.cmd-mobile-footer-info strong{display:block}.cmd-mobile-footer-info small{display:block;color:#93c5fd;font-size:10px;font-weight:850;margin-top:3px}

/* V6 - Indicador global de processamento para smartphone e tablet */
.cmd-processing-overlay{position:fixed;inset:0;z-index:12000;display:flex;align-items:center;justify-content:center;padding:22px;background:rgba(2,6,23,.58);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .16s ease,visibility .16s ease}
.cmd-processing-overlay.show{opacity:1;visibility:visible;pointer-events:auto}
.cmd-processing-card{width:min(290px,86vw);display:grid;justify-items:center;gap:10px;padding:24px 20px;border-radius:25px;background:#fff;color:#0f172a;text-align:center;box-shadow:0 24px 65px rgba(2,6,23,.38)}
.cmd-processing-spinner{width:48px;height:48px;border:5px solid #dbeafe;border-top-color:#2563eb;border-radius:50%;animation:cmd-processing-spin .72s linear infinite}
.cmd-processing-card strong{font-size:17px;font-weight:950;line-height:1.2}
.cmd-processing-card small{font-size:12px;font-weight:800;color:#64748b}
body.cmd-processing-lock{overflow:hidden!important;touch-action:none}
@keyframes cmd-processing-spin{to{transform:rotate(360deg)}}
@media(prefers-reduced-motion:reduce){.cmd-processing-spinner{animation-duration:1.5s}}
@media(max-width:760px){
  .cmd-selected-customer{align-items:stretch;flex-direction:column}.cmd-selected-customer-actions{display:grid;grid-template-columns:1fr 1fr;width:100%}.cmd-selected-customer-actions .btn{width:100%}
  .cmd-adjustments-grid{grid-template-columns:1fr}.cmd-adjustments-card{padding:12px;border-radius:20px}
  .cmd-cart-review-actions{display:grid;grid-template-columns:1fr 1fr}.cmd-cart-review-actions .btn{width:100%;min-width:0;white-space:normal}
  .cmd-cart-review-dialog{max-height:92dvh!important}.cmd-cart-review-list{max-height:52dvh}
}


.cmd-tile.ocupada.inativa{border-color:#facc15;background:#fef9c3}
.cmd-tile.ocupada.inativa em{background:#fde68a;color:#92400e}
.cmd-tile .cmd-tile-time{display:block;font-size:18px;line-height:1;font-weight:950;color:#0f172a;letter-spacing:.02em;margin-top:2px}
.cmd-tile .cmd-tile-name{display:block;font-size:11px;line-height:1.2;font-weight:800;color:#64748b;min-height:26px;margin-top:4px;word-break:break-word}
.cmd-tile .cmd-tile-meta{display:block;font-size:11px;line-height:1.15;color:#475569;margin-top:2px}
.cmd-tile .cmd-tile-meta strong{font-size:inherit;line-height:inherit;font-weight:900;color:#0f172a}
.cmd-tile.ocupada.inativa .cmd-tile-time{color:#92400e;font-weight:950}
.cmd-mobile-mode .cmd-tile .cmd-tile-time{display:block!important;font-size:16px;line-height:1}
.cmd-mobile-mode .cmd-tile .cmd-tile-name,.cmd-mobile-mode .cmd-tile .cmd-tile-meta{font-size:10px}

.cmd-hotkey{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:24px;padding:0 7px;margin-left:6px;border-radius:8px;background:#0b1f5b;color:#fff;font:900 11px/1 system-ui;vertical-align:middle;box-shadow:inset 0 -1px 0 rgba(255,255,255,.18)}
.btn-primary .cmd-hotkey{background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.18)}
.cmd-shortcut-bar{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 12px;padding:9px;border:1px dashed #bfdbfe;border-radius:15px;background:#eff6ff}
.cmd-shortcut-hint{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:5px 9px;border:1px solid #dbe4f0;border-radius:11px;background:#fff;color:#334155;font-size:12px;font-weight:850}
.cmd-shortcut-hint kbd,.cmd-payment-shortcut kbd,.cmd-payment-operation kbd{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 6px;border-radius:7px;background:#0b1f5b;color:#fff;font:900 12px/1 system-ui}
.cmd-payment-shortcuts{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 11px;padding:9px;border:1px dashed #bfdbfe;border-radius:15px;background:#eff6ff}
.cmd-payment-shortcut,.cmd-payment-operation{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:5px 9px;border:1px solid #dbe4f0;border-radius:11px;background:#fff;color:#334155;font-size:12px;font-weight:850}
.cmd-payment-shortcut{cursor:pointer}.cmd-payment-shortcut:hover{border-color:#2563eb;background:#f8fbff}.cmd-payment-line.is-active{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
.cmd-merge-dialog{width:min(760px,100%)}
.cmd-merge-flow{display:grid;gap:12px}.cmd-merge-reader{border:1px solid #dbe4f0;border-radius:20px;background:#f8fbff;padding:14px;display:grid;grid-template-columns:46px minmax(0,1fr);gap:12px;align-items:start}.cmd-merge-reader.active{border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.08)}.cmd-merge-reader.done{border-color:#86efac;background:#f0fdf4}.cmd-merge-number{width:46px;height:46px;border-radius:15px;background:#0b1f5b;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:950;font-size:18px}.cmd-merge-reader strong{display:block;color:#0b1f5b;margin-bottom:4px}.cmd-merge-reader small{display:block;color:#64748b;margin-top:5px}.cmd-merge-reader input{width:100%;height:54px;font-size:20px;font-weight:900;letter-spacing:.03em}.cmd-merge-summary{border:1px solid #dbe4f0;border-radius:18px;padding:12px;background:#fff}.cmd-merge-summary-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:9px}.cmd-merge-summary-head strong{color:#0b1f5b}.cmd-merge-chips{display:flex;flex-wrap:wrap;gap:7px;min-height:42px;align-items:center}.cmd-merge-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #bfdbfe;background:#eff6ff;color:#0b1f5b;border-radius:999px;padding:7px 10px;font-weight:900}.cmd-merge-chip button{border:0;background:transparent;color:#b91c1c;font-size:17px;font-weight:950;cursor:pointer;padding:0;line-height:1}.cmd-merge-empty{color:#64748b;font-size:13px}.cmd-merge-status{min-height:22px;color:#475569;font-weight:850}.cmd-merge-status.error{color:#b91c1c}.cmd-merge-status.ok{color:#047857}.cmd-merge-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.cmd-mobile-mode .cmd-payment-shortcuts{gap:6px;padding:8px}.cmd-mobile-mode .cmd-payment-shortcut,.cmd-mobile-mode .cmd-payment-operation{font-size:11px;padding:5px 7px}
@media(max-width:760px){.cmd-read-actions.has-three{grid-template-columns:1fr}.cmd-merge-reader{grid-template-columns:40px minmax(0,1fr);padding:11px}.cmd-merge-number{width:40px;height:40px}.cmd-merge-actions{display:grid;grid-template-columns:1fr}.cmd-merge-actions .btn{width:100%}.cmd-shortcut-bar{display:none}}
@media(min-width:1101px){
  .cmd-tile{padding:8px;justify-content:flex-start;gap:2px;overflow:hidden}
  .cmd-tile em{padding:3px 6px;font-size:9px;line-height:1.05;margin-bottom:1px}
  .cmd-tile>strong{font-size:18px;line-height:1;margin:0}
  .cmd-tile .cmd-tile-time{font-size:15px;line-height:1;margin-top:1px}
  .cmd-tile .cmd-tile-name{font-size:10px;line-height:1.1;min-height:11px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cmd-tile .cmd-tile-meta{font-size:10px;line-height:1.05;margin-top:1px;white-space:nowrap}
}

.cmd-search-advanced{grid-template-columns:220px minmax(0,1fr) auto 220px;align-items:end}
.cmd-search-mode-label{display:grid;gap:6px;font-size:12px;font-weight:900;color:#475569}
.cmd-search-mode-label select{min-height:46px}
.cmd-product-entry{display:flex;gap:8px;align-items:end;min-width:0}
.cmd-product-entry label{display:grid;gap:6px;min-width:0;flex:1}
.cmd-product-entry label input{height:46px;min-height:46px;box-sizing:border-box;margin:0}
.cmd-product-search-button{height:46px;min-height:46px;box-sizing:border-box;align-self:end;margin:0;white-space:nowrap;padding-inline:18px;display:inline-flex;align-items:center;justify-content:center}
.cmd-prepared-qty{min-width:72px;height:46px;border:0;border-radius:14px;background:#0b1f5b;color:#fff;font-weight:950;font-size:17px;cursor:pointer;box-shadow:0 8px 20px rgba(11,31,91,.18)}
.cmd-product-modal-searchbar{display:grid;grid-template-columns:210px minmax(0,1fr) auto auto;gap:10px;align-items:end;margin-bottom:14px}
.cmd-product-modal-searchbar label{display:grid;gap:6px;color:#475569;font-size:12px;font-weight:900;min-width:0}
.cmd-product-modal-searchbar select,.cmd-product-modal-searchbar input,.cmd-product-modal-searchbar .btn{height:46px;min-height:46px;box-sizing:border-box}
.cmd-product-modal-results{border:1px solid #dbe4f0;border-radius:18px;overflow:auto;max-height:55vh;background:#fff}
.cmd-product-modal-table{width:100%;border-collapse:collapse;min-width:650px}
.cmd-product-modal-table th{position:sticky;top:0;z-index:1;background:#f8fafc;color:#475569;font-size:12px;text-align:left;padding:11px 12px;border-bottom:1px solid #dbe4f0}
.cmd-product-modal-table td{padding:11px 12px;border-bottom:1px solid #edf2f7;color:#1e293b;vertical-align:middle}
.cmd-product-modal-table tr:last-child td{border-bottom:0}
.cmd-product-modal-table tbody tr{cursor:pointer;transition:background .12s ease,box-shadow .12s ease}
.cmd-product-modal-table tbody tr:hover{background:#f8fbff}
.cmd-product-modal-table tbody tr.is-active{background:#eaf2ff;box-shadow:inset 4px 0 0 #2563eb}
.cmd-product-modal-code{font-weight:900;color:#0b1f5b;white-space:nowrap}
.cmd-product-modal-code small,.cmd-product-modal-name small{display:block;color:#64748b;font-size:11px;font-weight:700;margin-top:3px}
.cmd-product-modal-name strong{display:block;color:#0b1f5b}
.cmd-product-modal-price{font-weight:950;color:#047857;white-space:nowrap}
.cmd-product-modal-select{white-space:nowrap}
.cmd-product-modal-empty{padding:30px 16px;text-align:center;color:#64748b}
@media(max-width:760px){.cmd-product-modal-searchbar{grid-template-columns:1fr}.cmd-product-modal-results{max-height:48vh}}
.cmd-prepared-qty[hidden]{display:none!important}
.cmd-mobile-mode .cmd-search-advanced{grid-template-columns:1fr!important}
.cmd-mobile-mode .cmd-search-mode-label{font-size:11px}
.cmd-mobile-mode .cmd-search-mode-label select{height:50px;border-radius:18px}
.cmd-mobile-mode .cmd-product-entry{align-items:stretch}
.cmd-mobile-mode .cmd-prepared-qty{height:52px;min-width:74px;border-radius:17px}
@media(max-width:900px){.cmd-search-advanced{grid-template-columns:1fr}.cmd-search-advanced .cmd-total-box{display:none}}

/* Ajuste 04/08/2026: leitura da comanda compacta, com as três ações na mesma linha. */
.cmd-read-grid.has-merge{grid-template-columns:minmax(240px,.62fr) minmax(700px,1.38fr);align-items:end}
.cmd-read-actions.has-three{grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch}
.cmd-read-actions.has-three .btn{white-space:nowrap;padding-left:13px;padding-right:13px}
@media(min-width:900px) and (max-width:1100px){
  .cmd-read-grid.has-merge{grid-template-columns:minmax(235px,.60fr) minmax(590px,1.40fr)}
  .cmd-read-actions.has-three{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:899px){
  .cmd-read-grid.has-merge{grid-template-columns:1fr}
  .cmd-read-actions.has-three{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:760px){.cmd-read-actions.has-three{grid-template-columns:1fr}}

</style>


<style id="nexa-admin-flow-theme">
/* Cores independentes para o passo a passo das telas Comandas. */
.cmd-step span{
  background:color-mix(in srgb,var(--admin-flow-primary) 11%,#fff)!important;
  color:var(--admin-flow-primary)!important;
}
.cmd-step.active{
  border-color:var(--admin-flow-secondary)!important;
  background:linear-gradient(135deg,var(--admin-flow-primary),var(--admin-flow-secondary))!important;
  box-shadow:0 18px 42px color-mix(in srgb,var(--admin-flow-secondary) 24%,transparent)!important;
}
.cmd-step.active span{background:rgba(255,255,255,.18)!important;color:var(--admin-flow-text)!important;}
.cmd-step.active strong,.cmd-step.active small{color:var(--admin-flow-text)!important;}
.cmd-mobile-mode .cmd-stepper{
  background:linear-gradient(135deg,var(--admin-flow-primary),var(--admin-flow-secondary))!important;
}
.cmd-mobile-mode .cmd-step,
.cmd-mobile-mode .cmd-step span,
.cmd-mobile-mode .cmd-step strong{color:var(--admin-flow-text)!important;}
.cmd-mobile-mode .cmd-step.active span{
  background:linear-gradient(135deg,var(--admin-flow-primary),var(--admin-flow-secondary))!important;
  color:var(--admin-flow-text)!important;
}
.cmd-mobile-mode .cmd-step.active strong{color:var(--admin-flow-primary)!important;}
</style>

<div class="cmd-flow<?= $comandaMobileMode ? ' cmd-mobile-mode' : '' ?><?= $comandaCheckoutMode ? ' cmd-checkout-mode' : '' ?><?= ($comandaCheckoutMode && $step === 'produtos') ? ' cmd-checkout-products-step' : '' ?><?= ($comandaMobileMode && $step === 'produtos') ? ' cmd-mobile-products-like-pdv' : '' ?><?= ($comandaMobileMode && $step === 'abrir') ? ' cmd-mobile-review' : '' ?><?= ($comandaMobileMode && $step === 'identificacao') ? ' cmd-mobile-customer' : '' ?><?= ($comandaMobileMode && $step === 'pagamento') ? ' cmd-mobile-payment' : '' ?>">
  <?php if ($comandaCheckoutMode): ?>
    <div class="cmd-checkout-topbar">
      <div class="cmd-checkout-brand"><span class="cmd-checkout-icon">▣</span><strong>COMANDAS CHECKOUT</strong></div>
      <div class="cmd-checkout-session">
        <?php if ($selected): ?>
          <span>Comanda <b><?= comanda_h((string)$selected['codigo']) ?></b></span><i></i>
          <span><?= comanda_h(trim((string)($selected['nome'] ?? '')) !== '' ? (string)$selected['nome'] : 'Sem nome') ?></span><i></i>
          <span><b><?= (int)count($items) ?></b> item(ns)</span><i></i>
          <span>Total <b><?= comanda_money((float)($totals['total'] ?? 0)) ?></b></span>
        <?php else: ?>
          <span>Mapa de comandas</span><i></i><span>Selecione, leia ou una comandas para iniciar</span>
        <?php endif; ?>
      </div>
      <div class="cmd-checkout-top-actions"><a class="btn" href="<?= comanda_h($comandaHomeUrl) ?>">Mapa de Comandas</a></div>
    </div>
  <?php endif; ?>
  <?php if ($selectedId > 0 && $step !== 'transferencia'): ?>
    <div class="cmd-stepper" role="tablist" aria-label="Etapas da comanda">
      <a class="cmd-step" href="<?= comanda_h($comandaHomeUrl) ?>"><span>1</span><strong>Comandas</strong><small>F2 · Voltar à lista</small></a>
      <a class="cmd-step <?= comanda_step_active($step, 'produtos') ?>" href="<?= comanda_h(comanda_step_href($selectedId, 'produtos')) ?>"><span>2</span><strong>Produtos</strong><small>Lançar itens</small></a>
      <a class="cmd-step <?= comanda_step_active($step, 'identificacao') ?>" href="<?= comanda_h(comanda_step_href($selectedId, 'identificacao')) ?>"><span>3</span><strong>Cliente</strong><small>F4 · Identificar</small></a>
      <a class="cmd-step <?= comanda_step_active($step, 'pagamento') ?>" href="<?= comanda_h(comanda_step_href($selectedId, 'pagamento')) ?>"><span>4</span><strong><?= $saleFinalizationBlocked ? 'Conferência' : 'Pagamento' ?></strong><small><?= $saleFinalizationBlocked ? 'F5 · Conferir' : 'F5 · Fechar conta' ?></small></a>
    </div>
  <?php endif; ?>

  <?php if ($step === 'inicio'): ?>
    <div class="mg-card cmd-read-card">
      <div class="cmd-panel-head">
        <div><h2>1. Ler comanda</h2><p class="cmd-muted">Digite o código da comanda ou passe o cartão RFID. Ao confirmar, o sistema abre direto a seleção de produtos.</p></div>
      </div>
      <?php $showHomeMergeButton = !$comandaMobileMode && !empty($settings['allow_transfer']) && !empty($settings['allow_multi_transfer']); ?>
      <form method="post" class="mg-form cmd-read-grid<?= $showHomeMergeButton ? ' has-merge' : '' ?>"><?php comanda_multi_company_hidden(true); ?>
        <input type="hidden" name="action" value="read_comanda"><?php if ($comandaMobileMode): ?><input type="hidden" name="mobile" value="1"><?php endif; ?>
        <label>Código da comanda ou matrícula/RFID<input name="codigo" autofocus placeholder="Ex.: 01, 25, MESA 4 ou cartão RFID"></label>
        <div class="cmd-read-actions<?= $showHomeMergeButton ? ' has-three' : '' ?>">
          <button class="btn btn-primary" type="submit">Abrir produtos</button>
          <?php if ($showHomeMergeButton): ?><button class="btn" type="button" data-open-merge>Unir Comandas <span class="cmd-hotkey">F7</span></button><?php endif; ?>
          <?php if (!empty($settings['allow_transfer'])): ?><a class="btn" href="<?= comanda_h($comandaContextUrl($comandaBaseUrl . '?step=transferencia')) ?>">Transferir comandas</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="mg-card">
      <div class="cmd-panel-head">
        <div><h2>Comandas <?= !empty($settings['show_only_occupied']) ? 'ocupadas' : 'cadastradas' ?></h2><p class="cmd-muted">Toque em uma comanda ocupada para conferir, imprimir, transferir ou pagar.</p></div>
      </div>
      <?php if (!$comandas): ?>
        <div class="cmd-empty">Nenhuma comanda para exibir. Cadastre comandas ou leia um novo código.</div>
      <?php else: ?>
        <div class="cmd-comandas-grid">
          <?php foreach ($comandas as $c):
            $status = (string)($c['status'] ?? 'LIVRE');
            $occupied = $status === 'OCUPADA';
            $blocked = $status === 'BLOQUEADA';
            $openedAtTs = !empty($c['opened_at']) ? (int)strtotime((string)$c['opened_at']) : 0;
            $lastActivityTs = !empty($c['last_activity_at']) ? (int)strtotime((string)$c['last_activity_at']) : $openedAtTs;
            if ($lastActivityTs <= 0) $lastActivityTs = $openedAtTs;
            $isInactive = $occupied && $inactiveAlertMinutes > 0 && $lastActivityTs > 0 && (time() - $lastActivityTs) >= ($inactiveAlertMinutes * 60);
            $tileStep = $comandaCheckoutMode ? 'produtos' : ($comandaMobileMode ? 'produtos' : ($occupied ? 'abrir' : 'produtos'));
            $tileHref = comanda_step_href((int)$c['id'], $tileStep);
            $reviewHref = comanda_step_href((int)$c['id'], 'abrir');
          ?>
            <div class="cmd-tile-wrap">
              <a class="cmd-tile <?= $occupied ? 'ocupada' : '' ?> <?= $blocked ? 'bloqueada' : '' ?> <?= $isInactive ? 'inativa' : '' ?>"
                 href="<?= comanda_h($tileHref) ?>"
                 data-occupied="<?= $occupied ? '1' : '0' ?>"
                 data-opened-ts="<?= $openedAtTs ?>"
                 data-last-activity-ts="<?= $lastActivityTs ?>"
                 data-inactive-limit="<?= $inactiveAlertMinutes ?>">
                <em data-status-label><?= comanda_h($isInactive ? 'INATIVA' : $status) ?></em>
                <strong><?= comanda_h(str_pad((string)$c['codigo'], 2, '0', STR_PAD_LEFT)) ?></strong>
                <?php if ($occupied && $openedAtTs > 0): ?><span class="cmd-tile-time cmd-open-time"><?= comanda_h(cmd_elapsed_clock(time() - $openedAtTs)) ?></span><?php endif; ?>
                <span class="cmd-tile-name"><?= comanda_h(cmd_short_person_name((string)($c['nome'] ?: ($c['cliente_nome'] ?? 'Sem nome')))) ?></span>
                <span class="cmd-tile-meta cmd-tile-items"><strong><?= (int)($c['itens_abertos'] ?? 0) ?></strong> item(ns)</span>
                <span class="cmd-tile-meta cmd-tile-value"><strong><?= comanda_money((float)($c['total_aberto'] ?? 0)) ?></strong></span>
              </a>
              <?php if ($comandaMobileMode && $occupied): ?><a class="cmd-mobile-tile-pay" href="<?= comanda_h($reviewHref) ?>">Conferir Comanda</a><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($selected && $step === 'abrir'): ?>
    <?php if ($comandaMobileMode): ?>
      <div class="mg-card">
        <div class="cmd-panel-head">
          <div><h2>Conferir Comanda <?= comanda_h($selected['codigo']) ?></h2><p class="cmd-muted">Confira todos os itens antes de avançar para o cliente.</p></div>
          <a class="btn btn-primary" target="_blank" href="<?= comanda_h($comandaContextUrl('pedido_print_conferencia.php?comanda_id=' . $selectedId . '&auto=1')) ?>">Imprimir Conferência</a>
        </div>
        <?php if (!$items): ?>
          <div class="cmd-empty">Nenhum item em aberto nesta comanda.</div>
        <?php else: ?>
          <div class="cmd-mobile-review-list">
            <?php foreach ($items as $it): ?>
              <div class="cmd-mobile-review-item">
                <div class="cmd-mobile-review-name"><?= comanda_h($it['nome']) ?></div>
                <div class="cmd-mobile-review-price"><?= comanda_money((float)$it['total']) ?></div>
                <div class="cmd-mobile-review-detail"><?= number_format((float)$it['qtd'], 3, ',', '.') ?> × <?= comanda_money((float)$it['preco_unit']) ?></div>
                <?php if (trim((string)($it['observacao'] ?? '')) !== ''): ?><div class="cmd-mobile-review-obs"><?= nl2br(comanda_h((string)$it['observacao'])) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="cmd-mobile-review-total"><span>Total da comanda</span><strong><?= comanda_money((float)$totals['total']) ?></strong></div>
          <div class="cmd-mobile-nav-footer single">
            <a class="btn btn-primary" href="<?= comanda_h(comanda_step_href($selectedId, 'identificacao')) ?>">Avançar</a>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="mg-card">
        <div class="cmd-panel-head">
          <div><h2>Comanda <?= comanda_h($selected['codigo']) ?></h2><p class="cmd-muted">Abra os produtos para lançar mais itens, imprima a conferência de conta<?= $saleFinalizationBlocked ? '.' : ' ou finalize no pagamento.' ?></p></div>
          <div class="cmd-actions">
            <a class="btn btn-primary" href="<?= comanda_h(comanda_step_href($selectedId, 'produtos')) ?>">Adicionar produtos</a>
            <a class="btn" href="<?= comanda_h(comanda_step_href($selectedId, 'identificacao')) ?>">Nome/Cliente</a>
            <a class="btn" target="_blank" href="<?= comanda_h($comandaContextUrl('pedido_print_conferencia.php?comanda_id=' . $selectedId . '&auto=1')) ?>">Imprimir conferência</a>
            <a class="btn" target="_blank" href="<?= comanda_h($comandaContextUrl('pedido_print_cozinha.php?comanda_id=' . $selectedId . '&auto=1')) ?>">Imprimir cozinha</a>
            <?php if (!empty($settings['allow_transfer'])): ?><a class="btn" href="<?= comanda_h(comanda_step_href($selectedId, 'transferencia')) ?>">Transferir</a><?php endif; ?>
            <a class="btn btn-primary" href="<?= comanda_h(comanda_step_href($selectedId, 'pagamento')) ?>"><?= $saleFinalizationBlocked ? 'Conferir conta' : 'Pagar' ?></a>
          </div>
        </div>
        <div class="cmd-summary">
          <div><span>Status</span><strong><?= comanda_h($selected['status'] ?? 'LIVRE') ?></strong></div>
          <div><span>Nome</span><strong><?= comanda_h($selected['nome'] ?: 'Sem nome') ?></strong></div>
          <div><span>Cliente</span><strong><?= comanda_h($customerName ?: 'Opcional') ?></strong></div>
          <div><span>Total</span><strong><?= comanda_money((float)$totals['total']) ?></strong></div>
        </div>
      </div>

      <div class="mg-card">
        <div class="cmd-panel-head"><div><h2>Itens em aberto</h2><p class="cmd-muted">A conferência aparece apenas na impressão, conforme solicitado.</p></div></div>
        <?php if (!$items): ?><div class="cmd-empty">Nenhum item em aberto nesta comanda.</div><?php else: ?>
          <div class="cmd-items">
            <?php foreach ($items as $it): ?>
              <div class="cmd-item">
                <div class="cmd-item-title"><strong><?= comanda_h($it['nome']) ?></strong><span><?= number_format((float)$it['qtd'], 3, ',', '.') ?> × <?= comanda_money((float)$it['preco_unit']) ?> • <?= comanda_h($it['grupo_impressao'] ?: 'Cozinha') ?><?= trim((string)($it['observacao'] ?? '')) !== '' ? ' • ' . comanda_h($it['observacao']) : '' ?></span></div>
                <strong><?= comanda_money((float)$it['total']) ?></strong>
                <form method="post" onsubmit="return confirm('Remover este item da comanda?')"><?php comanda_multi_company_hidden(true); ?><input type="hidden" name="action" value="cancel_item"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn btn-sm" type="submit">Remover</button></form>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="cmd-total-box" style="margin-top:12px"><span>Total em aberto</span><strong><?= comanda_money((float)$totals['total']) ?></strong></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($selected && $step === 'produtos' && $comandaCheckoutMode): ?>
    <div class="cmd-checkout-sale-shell">
      <form method="post" id="cmdProductsForm" class="cmd-checkout-sale-form"><?php comanda_multi_company_hidden(true); ?>
        <input type="hidden" name="action" value="process_products"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>"><input type="hidden" name="first_launch_name" id="cmdFirstLaunchName" value=""><input type="hidden" name="after_process_step" id="cmdAfterProcessStep" value="">
        <div id="cmdHiddenItems"></div>
        <section class="cmd-checkout-catalog-panel">
          <div class="cmd-checkout-panel-title"><div><strong>Produtos</strong><small>Selecione a categoria e toque no produto para lançar.</small></div><span>Comanda <?= comanda_h((string)$selected['codigo']) ?></span></div>
          <div class="cmd-checkout-catalog-body">
            <nav class="cmd-checkout-categories" id="cmdCats" aria-label="Categorias">
              <?php foreach ($categories as $c): ?><button class="cmd-cat<?= $c === 'Todos' ? ' active' : '' ?>" type="button" data-cat="<?= comanda_h($c) ?>"><?= comanda_h($c) ?></button><?php endforeach; ?>
            </nav>
            <div class="cmd-checkout-products-wrap"><div class="cmd-products" id="cmdProducts"></div></div>
          </div>
        </section>
        <section class="cmd-checkout-launch-panel">
          <div class="cmd-checkout-reader">
            <label class="cmd-checkout-filter-label">FILTRO DE PESQUISA
              <select id="cmdProductSearchMode" class="field cmd-checkout-search-mode" aria-label="Modo de pesquisa">
                <option value="instant">Pesquisa instantânea</option><option value="menu">Código cardápio</option><option value="description">Descrição</option><option value="barcode">Código de barras</option>
              </select>
            </label>
            <label class="cmd-checkout-product-label">LEITOR / CÓDIGO DO PRODUTO
              <div class="cmd-checkout-reader-line">
                <button type="button" id="cmdPreparedQty" class="cmd-prepared-qty" hidden title="Clique para voltar à quantidade 1">1 ×</button>
                <input id="cmdProductSearch" type="search" placeholder="Leia o código de barras ou digite produto/referência" autocomplete="off">
                <button type="button" class="btn btn-primary cmd-product-search-button" id="cmdProductSearchBtn" title="Pesquisar produtos (F8)">Buscar <span class="cmd-hotkey">F8</span></button>
              </div>
            </label>
          </div>
          <div class="cmd-checkout-launch-head"><span>ITENS DA COMANDA</span><div><b><?= (int)count($items) ?></b> gravado(s) + <b id="cmdCartCount">0</b> novo(s)</div></div>
          <div class="cmd-checkout-launch-scroll" id="cmdCheckoutLaunchScroll">
            <div class="cmd-checkout-existing-list">
              <?php if (!$items): ?>
                <div class="cmd-empty">A comanda ainda não possui itens gravados.</div>
              <?php else: foreach ($items as $it): ?>
                <div class="cmd-checkout-existing-item">
                  <div><strong><?= comanda_h((string)$it['nome']) ?></strong><small><?= number_format((float)$it['qtd'], 3, ',', '.') ?> × <?= comanda_money((float)$it['preco_unit']) ?><?= trim((string)($it['observacao'] ?? '')) !== '' ? ' · ' . comanda_h((string)$it['observacao']) : '' ?></small></div>
                  <b><?= comanda_money((float)$it['total']) ?></b>
                </div>
              <?php endforeach; endif; ?>
            </div>
            <div class="cmd-checkout-pending-title">NOVOS ITENS A PROCESSAR</div>
            <div class="cmd-cart" id="cmdCart"><div class="cmd-empty">Leia ou selecione um produto para adicionar.</div></div>
          </div>
          <div class="cmd-checkout-totals">
            <div><span>Total atual da comanda</span><strong><?= comanda_money((float)($totals['total'] ?? 0)) ?></strong></div>
            <div><span>Novos itens</span><strong id="cmdCartTotal">R$ 0,00</strong></div>
          </div>
        </section>
        <div class="cmd-checkout-actionbar">
          <a class="cmd-checkout-action" href="<?= comanda_h($comandaHomeUrl) ?>"><kbd>F2</kbd><span>Mapa de Comandas</span></a>
          <button class="cmd-checkout-action" id="cmdViewComandaBtn" type="button"><kbd>F3</kbd><span>Conferir</span></button>
          <button class="cmd-checkout-action" id="cmdBillComandaBtn" type="button"><kbd>F4</kbd><span>Cliente<small id="cmdCheckoutCustomerName"><?= comanda_h($customerName ?: 'Consumidor final') ?></small></span></button>
          <a class="cmd-checkout-action cmd-checkout-action-conference" id="cmdAccountConferenceBtn" target="_blank" rel="noopener" href="<?= comanda_h($comandaContextUrl('pedido_print_conferencia.php?comanda_id=' . $selectedId . '&auto=1')) ?>"><kbd>F6</kbd><span>Conferência de conta</span></a>
          <button class="cmd-checkout-action cmd-checkout-action-pay" id="cmdGoPaymentBtn" type="button"><kbd>F5</kbd><span>Pagamento</span></button>
          <button class="cmd-checkout-action cmd-checkout-action-process" id="cmdProductsSubmit" type="submit"><kbd>F9</kbd><span>Processar Comanda</span></button>
        </div>
      </form>
    </div>
  <?php elseif ($selected && $step === 'produtos'): ?>
    <div class="mg-card cmd-products-card">
      <div class="cmd-panel-head">
        <div><h2>2. Produtos — Comanda <?= comanda_h($selected['codigo']) ?></h2></div>
        <div class="cmd-product-top-actions">
          <?php if (!$comandaMobileMode): ?><button class="btn" id="cmdViewComandaBtn" type="button">F3 - Ver comanda</button><?php endif; ?>
          <?php if (!$comandaMobileMode): ?><button class="btn btn-primary" id="cmdBillComandaBtn" type="button">F4 - Faturar/Cliente</button><?php endif; ?>
          <?php if (!$comandaMobileMode): ?><button class="btn" id="cmdGoPaymentBtn" type="button">F5 - Pagamento</button><?php endif; ?>
          <?php if ($comandaMobileMode && $items): ?><a class="btn btn-primary" href="<?= comanda_h(comanda_step_href($selectedId, 'abrir')) ?>">Conferir</a><?php endif; ?>
          <a class="btn" href="<?= comanda_h($comandaHomeUrl) ?>">Cancelar</a>
        </div>
      </div>
      <form method="post" id="cmdProductsForm"><?php comanda_multi_company_hidden(true); ?>
        <input type="hidden" name="action" value="process_products"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>"><input type="hidden" name="first_launch_name" id="cmdFirstLaunchName" value=""><input type="hidden" name="after_process_step" id="cmdAfterProcessStep" value="">
        <div id="cmdHiddenItems"></div>
        <div class="cmd-product-layout">
          <div>
            <div class="cmd-search cmd-search-advanced">
              <label class="cmd-search-mode-label">Pesquisar por
                <select id="cmdProductSearchMode" class="field">
                  <option value="instant">Pesquisa instantânea</option>
                  <option value="menu">Código do cardápio</option>
                  <option value="description">Descrição</option>
                  <option value="barcode">Código de barras</option>
                </select>
              </label>
              <div class="cmd-product-entry">
                <button type="button" id="cmdPreparedQty" class="cmd-prepared-qty" hidden title="Clique para voltar à quantidade 1">1 ×</button>
                <label>Buscar produto<input id="cmdProductSearch" type="search" placeholder="Digite produto, código ou referência" autocomplete="off"></label>
              </div>
              <?php if (!$comandaMobileMode): ?><button type="button" class="btn cmd-product-search-button" id="cmdProductSearchBtn" title="Pesquisar produtos (F8)">Buscar <span class="cmd-hotkey">F8</span></button><?php endif; ?>
              <div class="cmd-total-box"><span>Total selecionado</span><strong id="cmdCartTotal">R$ 0,00</strong></div>
            </div>
            <div class="cmd-cats" id="cmdCats"><?php foreach ($categories as $c): ?><button class="cmd-cat" type="button" data-cat="<?= comanda_h($c) ?>"><?= comanda_h($c) ?></button><?php endforeach; ?></div>
            <div class="cmd-products" id="cmdProducts"></div>
          </div>
          <aside>
            <div class="cmd-total-box"><span>Itens selecionados</span><strong id="cmdCartCount">0</strong></div>
            <div class="cmd-cart" id="cmdCart" style="margin-top:10px"><div class="cmd-empty">Toque em um produto para adicionar.</div></div>
            <div class="cmd-actions" style="margin-top:12px"><button class="btn btn-primary" id="cmdProductsSubmit" type="submit">Processar <span class="cmd-hotkey">F9</span></button></div>
          </aside>
        </div>
        <?php if ($comandaMobileMode): ?>
          <div class="cmd-mobile-product-footer" id="cmdMobileProductFooter">
            <button class="cmd-mobile-footer-info" type="button" id="cmdOpenCartReview" aria-label="Conferir itens adicionados"><span id="cmdMobileFooterCount">Nenhum item</span><strong id="cmdMobileFooterTotal">R$ 0,00</strong><small>Toque para conferir</small></button>
            <button class="cmd-mobile-process-btn" type="button" id="cmdMobileReviewProcess">Conferir</button>
          </div>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($selected && $step === 'identificacao'): ?>
    <div class="mg-card">
      <div class="cmd-panel-head"><div><h2>3. Nome ou cliente — Comanda <?= comanda_h($selected['codigo']) ?></h2><p class="cmd-muted"><?= $checkoutFlow ? 'Confirme o cliente e siga diretamente para o pagamento.' : 'Informe um nome simples para chamar a conta ou vincule um cliente cadastrado.' ?></p></div></div>
      <form method="post" class="mg-form" id="cmdCustomerForm"><?php comanda_multi_company_hidden(true); ?>
        <input type="hidden" name="action" value="update_header"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>"><input type="hidden" name="cliente_id" id="cmdClienteId" value="<?= (int)($selected['cliente_id'] ?? 0) ?>"><input type="hidden" name="after_update_step" id="cmdAfterUpdateStep" value="<?= $checkoutFlow ? 'pagamento' : '' ?>">
        <div class="cmd-ident-grid">
          <div>
            <?php if (!empty($settings['allow_named_comanda'])): ?><label>Nome da pessoa/comanda<input name="nome" value="<?= comanda_h($selected['nome'] ?? '') ?>" placeholder="Ex.: João, Mesa Silva, Aniversário"></label><?php endif; ?>
            <label style="margin-top:10px">Observação<input name="notes" value="<?= comanda_h($selected['notes'] ?? '') ?>" placeholder="Ex.: mesa externa, prioridade..."></label>
          </div>
          <div>
            <?php if (!empty($settings['allow_customer'])): ?>
              <label>Cliente vinculado</label>
              <div class="cmd-selected-customer" id="cmdSelectedCustomerBox">
                <div>
                  <strong id="cmdSelectedCustomerName"><?= comanda_h($customerName ?: 'Consumidor final') ?></strong>
                  <span id="cmdSelectedCustomerDoc"><?= $customerName ? 'Cliente selecionado' : 'Nenhum cliente cadastrado selecionado' ?></span>
                </div>
                <div class="cmd-selected-customer-actions">
                  <button type="button" class="btn" id="cmdOpenCustomerModal">Pesquisar</button>
                  <button type="button" class="btn btn-primary" id="cmdOpenCustomerQuickModal">+ Cadastrar</button>
                </div>
              </div>
              <div class="cmd-muted" style="margin-top:8px">A listagem de clientes fica oculta. Ela só aparece na pesquisa, no mesmo comportamento do PDV.</div>
            <?php else: ?>
              <div class="cmd-empty">Seleção de cliente desabilitada nas configurações do módulo.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!$comandaMobileMode): ?><div class="cmd-actions" style="margin-top:14px"><a class="btn" href="<?= comanda_h($checkoutFlow ? comanda_step_href($selectedId, 'produtos') : $comandaHomeUrl) ?>">Cancelar</a><?php if (!$checkoutFlow): ?><button class="btn" type="submit" id="cmdCustomerGoPaymentBtn">Ir para Pagamento</button><?php endif; ?><button class="btn btn-primary" type="submit" id="cmdCustomerProcessBtn"><?= $checkoutFlow ? 'Ir para pagamento' : 'Processar' ?> <span class="cmd-hotkey">F9</span></button></div><?php endif; ?>
        <?php if ($comandaMobileMode): ?><div class="cmd-mobile-nav-footer single"><button class="btn btn-primary" type="submit">Avançar para Pagamento</button></div><?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($step === 'transferencia' && !empty($settings['allow_transfer'])): ?>
    <div class="mg-card">
      <div class="cmd-panel-head">
        <div><h2>Transferir comandas</h2><p class="cmd-muted">Digite os números das comandas para localizar ou use o leitor para unir várias comandas sem tocar no mouse.</p></div>
        <div class="cmd-actions">
          <?php if (!empty($settings['allow_multi_transfer']) && count($transferOccupied) >= 1): ?><button class="btn btn-primary" type="button" id="cmdOpenMergeModal">Unir Comandas</button><?php endif; ?>
          <a class="btn" href="<?= comanda_h($comandaHomeUrl) ?>">Voltar</a>
        </div>
      </div>

      <?php if (!$transferOccupied): ?>
        <div class="cmd-empty">Não existem comandas com itens em aberto para transferir.</div>
      <?php else: ?>
        <div class="cmd-transfer-sections">
          <section class="cmd-transfer-card">
            <h3>1. Digite a comanda de origem</h3>
            <p class="cmd-muted">Informe o número exato da comanda que contém os itens. Os zeros à esquerda são preservados, por exemplo: 01 ou 002.</p>
            <form method="get" class="mg-form cmd-transfer-source"><?php comanda_multi_company_hidden(false); ?>
              <input type="hidden" name="step" value="transferencia">
              <label>Número da comanda de origem
                <input type="text" name="c" value="<?= comanda_h($selected ? (string)$selected['codigo'] : $selectedCode) ?>" inputmode="numeric" autocomplete="off" autocapitalize="off" placeholder="Ex.: 01" required autofocus>
              </label>
              <button class="btn btn-primary" type="submit">Carregar itens</button>
            </form>
          </section>

          <?php if ($selected && $items): ?>
            <section class="cmd-transfer-card">
              <h3>2. Transferir a comanda <?= comanda_h($selected['codigo']) ?> inteira</h3>
              <p class="cmd-muted">Digite o número do destino. Se ele já estiver ocupado, os itens serão acrescentados à comanda existente.</p>
              <form method="post" class="mg-form cmd-transfer-source" onsubmit="return confirm('Transferir todos os itens da comanda <?= comanda_h($selected['codigo']) ?> para a comanda digitada?')"><?php comanda_multi_company_hidden(true); ?>
                <input type="hidden" name="action" value="transfer_all"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>">
                <label>Número da comanda de destino
                  <input type="text" name="to_codigo" inputmode="numeric" autocomplete="off" autocapitalize="off" placeholder="Ex.: 02" required>
                </label>
                <button class="btn btn-primary" type="submit">Transferir tudo</button>
              </form>
            </section>

            <?php if (!empty($settings['allow_item_transfer'])): ?>
              <section class="cmd-transfer-card">
                <h3>3. Transferir somente um item</h3>
                <p class="cmd-muted">Digite o destino no item desejado. Os demais produtos permanecem na comanda <?= comanda_h($selected['codigo']) ?>.</p>
                <div class="cmd-transfer-sections" style="margin-top:10px">
                  <?php foreach ($items as $it): ?>
                    <form method="post" class="cmd-transfer-item-card" onsubmit="return confirm('Transferir este item para a comanda digitada?')"><?php comanda_multi_company_hidden(true); ?>
                      <input type="hidden" name="action" value="transfer_item"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <div class="cmd-transfer-item-head">
                        <div><strong><?= comanda_h($it['nome']) ?></strong><small><?= number_format((float)$it['qtd'], 3, ',', '.') ?> × <?= comanda_money((float)$it['preco_unit']) ?><?= trim((string)($it['observacao'] ?? '')) !== '' ? ' • ' . comanda_h((string)$it['observacao']) : '' ?></small></div>
                        <strong><?= comanda_money((float)$it['total']) ?></strong>
                      </div>
                      <div class="cmd-transfer-source">
                        <label>Número da comanda de destino
                          <input type="text" name="to_codigo" inputmode="numeric" autocomplete="off" autocapitalize="off" placeholder="Ex.: 02" required>
                        </label>
                        <button class="btn" type="submit">Transferir item</button>
                      </div>
                    </form>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endif; ?>
          <?php elseif ($selected): ?>
            <div class="cmd-empty">A comanda <?= comanda_h($selected['codigo']) ?> não possui itens em aberto para transferir.</div>
          <?php endif; ?>

          <?php if (!empty($settings['allow_multi_transfer']) && count($transferOccupied) >= 1): ?>
            <section class="cmd-transfer-card">
              <h3><?= $selected && $items ? '4' : '2' ?>. União rápida com leitor</h3>
              <p class="cmd-muted">Clique em <strong>Unir Comandas</strong>. Leia primeiro a comanda principal e depois leia, uma após a outra, as comandas que serão transferidas. O foco permanece no leitor e <strong>F9</strong> conclui.</p>
              <button class="btn btn-primary" type="button" data-open-merge>Unir Comandas</button>
            </section>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($selected && $step === 'pagamento'): ?>
    <div class="mg-card">
      <div class="cmd-panel-head"><div><h2>4. <?= $saleFinalizationBlocked ? 'Conferência' : 'Pagamento' ?> — Comanda <?= comanda_h($selected['codigo']) ?></h2><p class="cmd-muted"><?= $saleFinalizationBlocked ? 'Confira a conta. Este perfil não possui permissão para finalizar a venda.' : 'Selecione a forma de pagamento no mesmo padrão do fechamento do PDV.' ?></p></div><?php if (!$comandaMobileMode): ?><a class="btn" target="_blank" href="<?= comanda_h($comandaContextUrl('pedido_print_conferencia.php?comanda_id=' . $selectedId . '&auto=1')) ?>">Imprimir conferência</a><?php endif; ?></div>
      <?php if (!$items): ?>
        <div class="cmd-empty">Esta comanda não possui itens em aberto para pagamento.</div>
      <?php else: ?>
        <?php if ($saleFinalizationBlocked): ?>
          <div class="msg" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;margin-bottom:12px;">Este perfil está configurado com <strong>Não permitir finalizar a venda</strong>. É possível lançar produtos, conferir valores e imprimir a conferência, mas o fechamento da comanda está bloqueado.</div>
          <div class="cmd-summary" style="margin-bottom:12px">
            <div><span>Cliente</span><strong><?= comanda_h($customerName ?: ($selected['nome'] ?: 'Consumidor final')) ?></strong></div>
            <div><span>Itens</span><strong><?= (int)$totals['count'] ?></strong></div>
            <div><span>Quantidade</span><strong><?= number_format((float)$totals['qty'], 3, ',', '.') ?></strong></div>
            <div><span>Total da conta</span><strong><?= comanda_money((float)$totals['total']) ?></strong></div>
          </div>
          <div class="cmd-items">
            <?php foreach ($items as $it): ?>
              <div class="cmd-item" style="grid-template-columns:minmax(0,1fr) auto"><div class="cmd-item-title"><strong><?= comanda_h($it['nome']) ?></strong><span><?= number_format((float)$it['qtd'], 3, ',', '.') ?> × <?= comanda_money((float)$it['preco_unit']) ?></span></div><strong><?= comanda_money((float)$it['total']) ?></strong></div>
            <?php endforeach; ?>
          </div>
          <div class="cmd-actions" style="margin-top:14px">
            <a class="btn" href="<?= comanda_h(comanda_step_href($selectedId, 'abrir')) ?>">Voltar</a>
            <a class="btn btn-primary" target="_blank" href="<?= comanda_h($comandaContextUrl('pedido_print_conferencia.php?comanda_id=' . $selectedId . '&auto=1')) ?>">Imprimir conferência</a>
          </div>
        <?php else: ?>
        <form method="post" class="mg-form" id="cmdPaymentForm"><?php comanda_multi_company_hidden(true); ?>
          <input type="hidden" name="action" value="finalize_comanda"><input type="hidden" name="comanda_id" value="<?= $selectedId ?>"><input type="hidden" name="emit_doc_after" id="cmdEmitDocAfter" value="">
          <input type="hidden" name="customer_id" value="<?= (int)($selected['cliente_id'] ?? 0) ?>">
          <input type="hidden" name="credit_override_user_code" id="cmdCreditOverrideUserCode" value=""><input type="hidden" name="credit_override_password" id="cmdCreditOverridePassword" value="">
          <input type="hidden" name="customer_name" value="<?= comanda_h($customerName ?: ($selected['nome'] ?: 'Consumidor final')) ?>">
          <?php if (!$comandaMobileMode): ?>
            <div class="cmd-summary" style="margin-bottom:12px">
              <div><span>Cliente</span><strong><?= comanda_h($customerName ?: ($selected['nome'] ?: 'Consumidor final')) ?></strong></div>
              <div><span>Itens</span><strong><?= (int)$totals['count'] ?></strong></div>
              <div><span>Quantidade</span><strong><?= number_format((float)$totals['qty'], 3, ',', '.') ?></strong></div>
              <div><span>Total</span><strong><?= comanda_money((float)$totals['total']) ?></strong></div>
            </div>
          <?php endif; ?>
          <div class="cmd-payment-step-grid">
            <div>
              <section class="cmd-adjustments-card">
                <div class="cmd-adjustments-head"><div><strong><?= $comandaCheckoutMode ? 'Vendedor e ajustes da venda' : 'Desconto, acréscimo e frete' ?></strong><small>Os valores atualizam o total do pagamento automaticamente.</small></div></div>
                <?php if ($comandaCheckoutMode && !$comandaMobileMode): ?>
                <div class="cmd-adjustments-grid cmd-checkout-adjustments-grid">
                  <label>Vendedor<input type="hidden" name="seller_id" id="cmdSellerId" value="<?= $defaultSellerId > 0 ? (int)$defaultSellerId : '' ?>"><input id="cmdSellerName" value="<?= comanda_h($defaultSellerName) ?>" readonly title="Vendedor selecionado"></label>
                  <div class="cmd-seller-search-action"><button type="button" class="btn" id="cmdSellerSearchBtn" <?= !$allowSellerChange ? 'disabled' : '' ?> title="Pesquisar vendedor (F8)">Buscar <span class="cmd-hotkey">F8</span></button></div>
                  <label>% Acréscimo<input id="cmdAdditionPercent" inputmode="decimal" value="0,00" autocomplete="off"></label>
                  <label>Acréscimo<input name="addition_amount" id="cmdAdditionAmount" inputmode="decimal" value="0,00"></label>
                  <label>% Desconto<input id="cmdDiscountPercent" inputmode="decimal" value="0,00" autocomplete="off"<?= $maxDiscountPercent !== null ? ' title="Máximo permitido: ' . number_format((float)$maxDiscountPercent,2,',','.') . '%"' : '' ?>></label>
                  <label>Desconto<input name="discount_amount" id="cmdDiscountAmount" inputmode="decimal" value="0,00"<?= $maxDiscountPercent !== null ? ' title="Máximo permitido: ' . number_format((float)$maxDiscountPercent,2,',','.') . '%"' : '' ?>></label>
                  <label>Frete<input name="delivery_fee" id="cmdDeliveryFee" inputmode="decimal" value="0,00"></label>
                </div>
                <?php else: ?>
                <div class="cmd-adjustments-grid">
                  <label>Vendedor<?php if (!$allowSellerChange): ?><input type="hidden" name="seller_id" value="<?= (int)$defaultSellerId ?>"><?php endif; ?><select name="seller_id" <?= !$allowSellerChange ? 'disabled' : '' ?>><?php gestao_select_options($sellers, $defaultSellerId ?: null, 'Sem vendedor', false); ?></select><?php if (!$allowSellerChange): ?><small class="cmd-muted force-show">Definido pelo usuário.</small><?php endif; ?></label>
                  <label>Desconto<input name="discount_amount" id="cmdDiscountAmount" inputmode="decimal" value="0,00"><?php if ($maxDiscountPercent !== null): ?><small class="cmd-muted force-show">Máx. <?= number_format((float)$maxDiscountPercent,2,',','.') ?>%</small><?php endif; ?></label>
                  <label>Acréscimo<input name="addition_amount" id="cmdAdditionAmount" inputmode="decimal" value="0,00"></label>
                  <label>Frete/Taxa<input name="delivery_fee" id="cmdDeliveryFee" inputmode="decimal" value="0,00"></label>
                </div>
                <?php endif; ?>
                <div class="cmd-adjusted-total"><span>Total a pagar</span><strong id="cmdAdjustedTotalView"><?= comanda_money((float)$totals['total']) ?></strong></div>
              </section>
              <div class="cmd-payments-box">
              <div class="cmd-payments-head">
                <div>
                  <strong>Formas de pagamento</strong>
                  <small>Permite dividir: dinheiro + cartão, pix + dinheiro, etc., igual ao PDV.</small>
                </div>
                <button class="btn" type="button" id="cmdAddPaymentBtn">+ Pagamento</button>
              </div>
              <div class="cmd-payment-shortcuts" id="cmdPaymentShortcuts" aria-label="Atalhos das formas de pagamento">
                <?php foreach ($paymentShortcuts as $paymentShortcut): ?>
                  <button type="button" class="cmd-payment-shortcut" data-payment-shortcut="<?= (int)$paymentShortcut['id'] ?>"><kbd><?= comanda_h($paymentShortcut['key']) ?></kbd><span><?= comanda_h($paymentShortcut['name']) ?></span></button>
                <?php endforeach; ?>
                <span class="cmd-payment-operation"><kbd>+</kbd> Adicionar</span>
                <span class="cmd-payment-operation"><kbd>-</kbd> Remover</span>
                <span class="cmd-payment-operation"><kbd>F10</kbd> Finalizar</span>
              </div>
              <div id="cmdPaymentLines" class="cmd-payment-lines"></div>
              <label id="cmdDueDateWrap" style="display:none;margin-top:10px;">Vencimento da conta a receber<input type="date" name="due_date" value="<?= comanda_h(date('Y-m-d')) ?>"></label>
              <?php if (!$comandaMobileMode): ?><label style="margin-top:10px">Observações do pedido<input name="notes" value="Comanda <?= comanda_h($selected['codigo']) ?>"></label><?php else: ?><input type="hidden" name="notes" value="Comanda <?= comanda_h($selected['codigo']) ?>"><?php endif; ?>
              </div>
            </div>
            <?php if (!$comandaMobileMode): ?>
              <aside class="cmd-payment-summary">
                <div class="cmd-total-row"><span>Total da comanda</span><strong id="cmdPaymentOrderTotalView"><?= comanda_money((float)$totals['total']) ?></strong></div>
                <div class="cmd-total-row"><span>Total informado</span><strong id="cmdPaymentsTotalView">R$ 0,00</strong></div>
                <div class="cmd-total-row"><span>Diferença</span><strong id="cmdPaymentsDiffView">R$ 0,00</strong></div>
                <div class="cmd-total-row" id="cmdChangeRow" style="display:none;"><span>Troco</span><strong id="cmdChangeView">R$ 0,00</strong></div>
                <div class="cmd-total-row final"><span>Total</span><strong id="cmdPaymentFinalTotalView"><?= comanda_money((float)$totals['total']) ?></strong></div>
                <div class="cmd-actions" style="margin-top:14px"><a class="btn" href="<?= comanda_h(comanda_step_href($selectedId, 'abrir')) ?>">Voltar</a><button class="btn btn-primary cmd-finish-button" type="submit">Pagar e fechar <span class="cmd-hotkey">F10</span></button></div>
              </aside>
            <?php endif; ?>
          </div>
          <?php if ($comandaMobileMode): ?>
            <div class="cmd-mobile-nav-footer">
              <a class="btn" href="<?= comanda_h(comanda_step_href($selectedId, 'identificacao')) ?>">Voltar</a>
              <button class="btn btn-primary cmd-finish-button" type="submit">Pagar e Fechar <span class="cmd-hotkey">F10</span></button>
            </div>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php if ($comandaMobileMode || $comandaCheckoutMode): ?><div class="cmd-mobile-toast" id="cmdMobileToast" aria-live="polite">Item adicionado</div><?php endif; ?>
<?php if ($comandaMobileMode): ?>
<div class="cmd-processing-overlay" id="cmdProcessingOverlay" role="status" aria-live="assertive" aria-hidden="true">
  <div class="cmd-processing-card">
    <span class="cmd-processing-spinner" aria-hidden="true"></span>
    <strong id="cmdProcessingMessage">Processando...</strong>
    <small>Aguarde um instante. Não toque novamente.</small>
  </div>
</div>
<?php endif; ?>

<?php if (in_array($step, ['inicio', 'transferencia'], true) && !empty($settings['allow_transfer']) && !empty($settings['allow_multi_transfer'])): ?>
<div class="finance-modal" id="cmdMergeModal" style="display:none;">
  <div class="finance-modal-dialog cmd-merge-dialog">
    <div class="finance-modal-head">
      <div><h2>Unir Comandas</h2><p class="cmd-muted" style="margin:4px 0 0;">Leia primeiro a comanda que receberá os itens. Depois leia todas as comandas de origem, uma por vez.</p></div>
      <button type="button" class="finance-modal-close" id="cmdMergeClose" aria-label="Fechar">×</button>
    </div>
    <form method="post" id="cmdMergeForm" class="cmd-merge-flow"><?php comanda_multi_company_hidden(true); ?>
      <input type="hidden" name="action" value="transfer_multiple"><input type="hidden" name="comanda_id" value="0"><input type="hidden" name="from_codigos" id="cmdMergeSourcesValue" value="">
      <div class="cmd-merge-reader active" id="cmdMergeDestinationStep">
        <span class="cmd-merge-number">1</span>
        <label><strong>Comanda principal — destino</strong><input type="text" name="to_codigo" id="cmdMergeDestination" inputmode="numeric" autocomplete="off" autocapitalize="off" placeholder="Passe o leitor na comanda principal" required><small>Após a leitura, pressione Enter. O cursor irá automaticamente para as comandas de origem.</small></label>
      </div>
      <div class="cmd-merge-reader" id="cmdMergeSourceStep">
        <span class="cmd-merge-number">2</span>
        <label><strong>Comandas que serão transferidas</strong><input type="text" id="cmdMergeSourceReader" inputmode="numeric" autocomplete="off" autocapitalize="off" placeholder="Passe o leitor na próxima comanda" disabled><small>Leia quantas comandas forem necessárias. A cada Enter, o código entra na lista e o cursor permanece neste campo.</small></label>
      </div>
      <div class="cmd-merge-summary">
        <div class="cmd-merge-summary-head"><strong>Comandas de origem adicionadas</strong><button class="btn" type="button" id="cmdMergeClear">Limpar lista</button></div>
        <div class="cmd-merge-chips" id="cmdMergeSourceList"><span class="cmd-merge-empty">Nenhuma comanda lida.</span></div>
      </div>
      <div class="cmd-merge-status" id="cmdMergeStatus" aria-live="polite"></div>
      <div class="cmd-merge-actions"><button class="btn" type="button" id="cmdMergeCancel">Cancelar</button><button class="btn btn-primary" type="submit">Concluir união <span class="cmd-hotkey">F9</span></button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($fiscalEmissionEnabledCmd): ?>
<div class="finance-modal" id="cmdCreditOverrideModal" style="display:none;">
  <div class="finance-modal-dialog" style="width:min(560px,100%)"><div class="finance-modal-head"><div><h2>Limite de crédito excedido</h2><p class="cmd-muted force-show" id="cmdCreditOverrideMessage" style="margin:5px 0 0"></p></div><button type="button" class="finance-modal-close" id="cmdCreditOverrideClose">×</button></div><div class="cmd-ident-grid"><label>Código do usuário<input id="cmdCreditOverrideCodeInput" autocomplete="username" placeholder="Código ou e-mail"></label><label>Senha<input id="cmdCreditOverridePasswordInput" type="password" autocomplete="current-password"></label></div><div class="cmd-actions" style="margin-top:14px"><button type="button" class="btn" id="cmdCreditOverrideCancel">Cancelar</button><button type="button" class="btn btn-primary" id="cmdCreditOverrideConfirm">Liberar venda</button></div></div>
</div>

<div class="finance-modal" id="cmdFiscalModal" style="display:none;">
  <div class="finance-modal-dialog" style="width:min(620px,100%);">
    <div class="finance-modal-head">
      <div><h2><?= $comandaCrossContext ? 'Finalizar comanda' : 'Documento fiscal' ?></h2><p class="cmd-muted" style="margin:4px 0 0;"><?= $comandaCrossContext ? 'No F11, a V11.31 permite somente gravar o pedido, sem emissão fiscal.' : 'Escolha se deseja emitir agora ou apenas gravar o pedido da comanda.' ?></p></div>
      <button type="button" class="finance-modal-close" data-cmd-fiscal="close">×</button>
    </div>
    <div class="pdv-fiscal-choice-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
      <?php if ($cmdCanEmitNfce): ?><button type="button" class="pdv-fiscal-choice" data-cmd-fiscal="nfce"><strong>1 - Emitir NFC-e</strong><small>Cupom fiscal ao consumidor.</small></button><?php endif; ?>
      <?php if ($cmdCanEmitNfe): ?><button type="button" class="pdv-fiscal-choice" data-cmd-fiscal="nfe"><strong>2 - Emitir NF-e</strong><small>Nota fiscal modelo 55.</small></button><?php endif; ?>
      <?php if ($cmdCanEmitNfse): ?><button type="button" class="pdv-fiscal-choice" data-cmd-fiscal="nfse"><strong>3 - Emitir NFS-e</strong><small>Salva o pedido, transmite a NFS-e e abre o documento autorizado.</small></button><?php endif; ?>
      <?php if ($cmdCanSaveOrder): ?><button type="button" class="pdv-fiscal-choice" data-cmd-fiscal="none"><strong>4 - Somente gravar o pedido</strong></button><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($comandaCheckoutMode): ?>
<style id="cmd-checkout-theme">
body:has(.cmd-checkout-mode){background:#edf3fb!important}
body:has(.cmd-checkout-mode) .container{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;background:#edf3fb!important}
body:has(.cmd-checkout-mode) .admin-footer{display:none!important}
@media(min-width:921px){body:has(.cmd-checkout-mode).admin-side-menu-enabled .topbar{margin-left:0!important}body:has(.cmd-checkout-mode).admin-side-menu-enabled.admin-sidebar-collapsed .container,body:has(.cmd-checkout-mode).admin-side-menu-enabled .container{width:100%!important;max-width:none!important;margin-left:0!important;margin-right:0!important;padding:0!important}body:has(.cmd-checkout-mode).admin-side-menu-enabled.admin-sidebar-collapsed .admin-sidebar{transform:translateX(-106%)}}
body:has(.cmd-checkout-products-step){overflow:hidden!important}
.cmd-checkout-body .cmd-customer-toolbar{grid-template-columns:minmax(0,1fr) auto auto auto}
.cmd-checkout-mode{gap:10px;background:#edf3fb;margin:0;padding:0 8px 8px;min-width:0;width:100%;box-sizing:border-box}
.cmd-checkout-mode>.cmd-stepper{display:none!important}
.cmd-checkout-topbar{min-height:66px;margin:0 -8px;display:grid;grid-template-columns:minmax(230px,.8fr) minmax(480px,1.7fr) auto;gap:16px;align-items:center;padding:8px 20px;background:linear-gradient(110deg,#073d86,#045bb9 58%,#063f88);color:#fff;box-shadow:0 5px 18px rgba(3,61,133,.22)}
.cmd-checkout-brand{display:flex;align-items:center;gap:10px;font-size:22px;letter-spacing:.01em}.cmd-checkout-icon{font-size:27px}.cmd-checkout-session{display:flex;align-items:center;justify-content:center;gap:12px;font-size:13px;white-space:nowrap;overflow:hidden}.cmd-checkout-session i{width:1px;height:24px;background:rgba(255,255,255,.35)}.cmd-checkout-session b{font-weight:950}.cmd-checkout-top-actions{display:flex;justify-content:flex-end}.cmd-checkout-top-actions .btn{background:#fff;color:#0b4f97;border-color:#fff;white-space:nowrap}
.cmd-checkout-mode .cmd-read-card,.cmd-checkout-mode>.mg-card{border-radius:13px;box-shadow:0 7px 22px rgba(15,54,104,.08)}
.cmd-checkout-mode .cmd-comandas-grid{grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:9px}.cmd-checkout-mode .cmd-tile{border-radius:15px;min-height:118px}.cmd-checkout-mode .cmd-tile.ocupada{box-shadow:0 8px 20px rgba(31,150,73,.09)}
.cmd-checkout-sale-shell{min-height:0;height:calc(100vh - 142px);overflow:hidden}
.cmd-checkout-sale-form{height:100%;display:grid;grid-template-columns:minmax(620px,1.14fr) minmax(440px,.86fr);grid-template-rows:minmax(0,1fr);gap:10px;min-width:0;padding-bottom:76px;box-sizing:border-box}
.cmd-checkout-catalog-panel,.cmd-checkout-launch-panel{min-height:0;background:#fff;border:1px solid #c9d8eb;border-radius:13px;box-shadow:0 7px 22px rgba(15,54,104,.08);overflow:hidden}.cmd-checkout-catalog-panel{grid-column:1;grid-row:1}.cmd-checkout-launch-panel{grid-column:2;grid-row:1}
.cmd-checkout-catalog-panel{display:grid;grid-template-rows:auto minmax(0,1fr);padding:10px}.cmd-checkout-panel-title{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:2px 4px 9px}.cmd-checkout-panel-title strong{display:block;color:#0b1f5b;font-size:20px}.cmd-checkout-panel-title small{display:block;color:#64748b;margin-top:2px}.cmd-checkout-panel-title>span{font-weight:950;color:#0b65bd;background:#eaf4ff;border-radius:999px;padding:6px 10px;white-space:nowrap}
.cmd-checkout-catalog-body{display:grid;grid-template-columns:170px minmax(0,1fr);gap:9px;min-height:0}.cmd-checkout-categories{display:flex;flex-direction:column;gap:7px;overflow:auto;min-height:0;padding-right:3px;border-right:1px solid #dbe5f0}.cmd-checkout-categories .cmd-cat{width:100%;text-align:left;border-radius:10px;padding:10px 11px;white-space:normal;line-height:1.18}.cmd-checkout-categories .cmd-cat.active{background:linear-gradient(135deg,#0b65bd,#084f99);box-shadow:0 5px 13px rgba(11,101,189,.18)}
.cmd-checkout-products-wrap{min-height:0;overflow:hidden}.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-products{height:100%;max-height:none;overflow:auto;grid-template-columns:repeat(auto-fill,minmax(205px,1fr));gap:8px;padding:2px 4px 4px 1px;align-content:start}.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod{grid-template-columns:58px minmax(0,1fr);border-radius:12px;padding:8px;box-shadow:none;min-height:76px}.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod img{width:58px;height:58px;border-radius:11px}.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod strong{font-size:13px}.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod b{font-size:15px}
.cmd-checkout-launch-panel{display:grid;grid-template-rows:auto auto minmax(0,1fr) auto;padding:10px}.cmd-checkout-reader{display:grid;grid-template-columns:165px minmax(0,1fr);gap:8px;align-items:end;padding-bottom:9px}.cmd-checkout-reader>label{display:grid;grid-template-rows:auto 54px;gap:5px;min-width:0;margin:0;font-size:12px;font-weight:950;color:#0b4f97;letter-spacing:.02em;line-height:1.15}.cmd-checkout-reader-line{display:flex;gap:7px;align-items:stretch;margin-top:0;min-width:0;height:54px}.cmd-checkout-reader-line input{height:54px;flex:1;min-width:0;border:2px solid #1684e8;border-radius:10px;background:#fff;font-size:17px;font-weight:850;color:#0d2550;padding:0 12px;box-shadow:0 0 0 4px rgba(22,132,232,.07)}.cmd-checkout-reader-line .btn{height:54px;min-width:92px;border-radius:10px}.cmd-checkout-reader-line .cmd-prepared-qty{height:54px;min-width:70px;border-radius:10px}.cmd-checkout-search-mode{height:54px!important;min-height:54px!important;width:100%;margin:0!important;border-radius:10px;align-self:stretch}
.cmd-checkout-launch-head{display:flex;justify-content:space-between;gap:10px;align-items:center;min-height:38px;padding:0 10px;background:linear-gradient(180deg,#e8f2ff,#d8e7f8);border:1px solid #c9d8eb;border-radius:10px 10px 0 0;color:#174f8b;font-size:12px;font-weight:950}.cmd-checkout-launch-scroll{min-height:0;overflow:auto;border:1px solid #c9d8eb;border-top:0;border-radius:0 0 10px 10px;background:#fff;scrollbar-gutter:stable}.cmd-checkout-existing-list{display:grid}.cmd-checkout-existing-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:8px 10px;border-bottom:1px solid #e1e9f2;background:#fff}.cmd-checkout-existing-item strong{display:block;color:#07184d;font-size:13px;line-height:1.15}.cmd-checkout-existing-item small{display:block;color:#54708f;font-size:11px;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cmd-checkout-existing-item>b{font-size:15px;color:#07184d;white-space:nowrap}.cmd-checkout-pending-title{position:sticky;top:0;z-index:2;padding:6px 10px;background:#fff7dd;border-top:1px solid #ead9a6;border-bottom:1px solid #ead9a6;color:#8a5a00;font-size:10px;font-weight:950;letter-spacing:.04em}.cmd-checkout-mode .cmd-checkout-launch-scroll .cmd-cart{max-height:none;overflow:visible;display:grid;gap:0}.cmd-checkout-mode .cmd-checkout-launch-scroll .cmd-cart-item{border:0;border-bottom:1px solid #e1e9f2;border-radius:0;padding:8px 10px;background:#f8fff3}.cmd-checkout-mode .cmd-checkout-launch-scroll .cmd-cart-item:last-child{border-bottom:0}.cmd-checkout-mode .cmd-checkout-launch-scroll .cmd-qty{grid-template-columns:30px minmax(54px,80px) 30px 32px;max-width:210px}.cmd-checkout-mode .cmd-checkout-launch-scroll .cmd-item-note{height:30px;padding:5px 7px;font-size:11px}.cmd-checkout-totals{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding-top:8px}.cmd-checkout-totals>div{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 10px;border:1px solid #c9d8eb;border-radius:10px;background:#f7fbff}.cmd-checkout-totals span{font-size:11px;font-weight:900;color:#64748b}.cmd-checkout-totals strong{font-size:17px;color:#0b1f5b}.cmd-checkout-totals>div:last-child{background:#e9ffdf;border-color:#b8e99d}.cmd-checkout-totals>div:last-child strong{color:#08751a}
.cmd-checkout-actionbar{position:fixed;left:8px;right:8px;bottom:8px;z-index:160;display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:8px;padding:6px 0 0;background:#edf3fb;box-shadow:0 -8px 18px rgba(15,54,104,.08)}.cmd-checkout-action span{display:grid;gap:2px}.cmd-checkout-action span small{display:block;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:9px;font-weight:800;color:inherit;opacity:.72}.cmd-checkout-action{min-height:64px;border:2px solid #6aa6df;border-radius:10px;background:linear-gradient(180deg,#f5fbff,#e8f4ff);display:flex;align-items:center;justify-content:center;gap:10px;cursor:pointer;font-weight:950;text-decoration:none;box-shadow:0 4px 12px rgba(20,69,120,.06);color:#0b2e70}.cmd-checkout-action kbd{font:950 20px/1 system-ui;color:inherit;background:transparent;padding:0}.cmd-checkout-action span{font-size:14px;color:inherit}.cmd-checkout-action-process{background:linear-gradient(180deg,#0875cf,#0058b3);border-color:#0058b3;color:#fff}.cmd-checkout-action-pay{background:linear-gradient(180deg,#ebfff0,#d8ffe1);border-color:#43bf70;color:#09742d}.cmd-checkout-action-conference{background:linear-gradient(180deg,#fff9e8,#fff1bd);border-color:#e1b84a;color:#7a5700}.cmd-checkout-action:hover{filter:brightness(.985)}
.cmd-checkout-mode>.mg-card .cmd-panel-head h2{font-size:22px}.cmd-checkout-mode .cmd-payment-step-grid{grid-template-columns:minmax(0,1fr) 360px}
@media(max-width:1280px){.cmd-checkout-sale-form{grid-template-columns:minmax(540px,1.12fr) minmax(390px,.88fr)}.cmd-checkout-catalog-body{grid-template-columns:145px minmax(0,1fr)}.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-products{grid-template-columns:repeat(2,minmax(0,1fr))}.cmd-checkout-topbar{grid-template-columns:minmax(220px,.8fr) minmax(420px,1.5fr) auto}.cmd-checkout-session{gap:8px;font-size:12px}}
@media(max-width:1000px){.cmd-checkout-sale-shell{height:auto;overflow:visible}.cmd-checkout-sale-form{grid-template-columns:1fr;grid-template-rows:auto auto;padding-bottom:0}.cmd-checkout-catalog-panel{grid-column:1;grid-row:1;min-height:520px}.cmd-checkout-launch-panel{grid-column:1;grid-row:2;min-height:520px}.cmd-checkout-actionbar{position:static;grid-template-columns:repeat(2,minmax(0,1fr));box-shadow:none;padding-top:0}.cmd-checkout-topbar{grid-template-columns:1fr auto}.cmd-checkout-session{grid-column:1/-1;grid-row:2;justify-content:flex-start;overflow:auto;padding-bottom:2px}.cmd-checkout-catalog-body{grid-template-columns:140px minmax(0,1fr)}}
/* V11.45.19.24 — Comandas Checkout: cards com conteúdo protegido.
   Descrições maiores ficam limitadas a duas linhas e o preço permanece sempre visível. */
.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod{overflow:hidden!important;align-items:center!important}
.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod>div{
  min-width:0!important;
  height:58px!important;
  display:grid!important;
  grid-template-rows:30px 13px 18px!important;
  align-content:stretch!important;
  overflow:hidden!important;
  gap:0!important;
}
.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod strong{
  min-width:0!important;
  max-height:30px!important;
  overflow:hidden!important;
  display:-webkit-box!important;
  -webkit-box-orient:vertical!important;
  -webkit-line-clamp:2!important;
  line-clamp:2!important;
  line-height:1.13!important;
  word-break:break-word!important;
  margin:0!important;
}
.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod span{
  min-width:0!important;
  margin:0!important;
  font-size:10.5px!important;
  line-height:13px!important;
  white-space:nowrap!important;
  overflow:hidden!important;
  text-overflow:ellipsis!important;
}
.cmd-checkout-mode .cmd-checkout-products-wrap .cmd-prod b{
  min-width:0!important;
  margin:0!important;
  align-self:end!important;
  line-height:18px!important;
  white-space:nowrap!important;
  overflow:hidden!important;
  text-overflow:ellipsis!important;
}
</style>
<?php endif; ?>
<script defer src="/assets/balanca.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/balanca.js') ?>"></script>
<script>
(function(){
  if (document.querySelector('.cmd-mobile-mode')) { document.body.classList.add('admin-compact-mobile','cmd-mobile-body'); }
  if (document.querySelector('.cmd-checkout-mode')) {
    document.body.classList.add('cmd-checkout-body');
    const collapseCheckoutMenu = () => document.body.classList.add('admin-sidebar-collapsed');
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', collapseCheckoutMenu, {once:true}); else collapseCheckoutMenu();
  }
  const products = <?= $productJson ?: '[]' ?>;
  const productOptions = <?= $productOptionsJson ?: '{}' ?>;
  const scaleCfg = <?= $scaleJson ?: '{}' ?>;
  const productOptionDisplay = <?= $productOptionDisplayJson ?: '{}' ?>;
  const cmdFiscalEmissionEnabled = <?= $fiscalEmissionEnabledCmd ? 'true' : 'false' ?>;
  let cmdFiscalChoiceConfirmed = !cmdFiscalEmissionEnabled;
  const pizzas = <?= $pizzaJson ?: '[]' ?>;
  let customers = <?= $customerJson ?: '[]' ?>;
  const paymentMap = <?= $paymentJson ?: '{}' ?>;
  const cmdSellers = <?= $sellerJson ?: '[]' ?> || [];
  const cmdAllowSellerChange = <?= $allowSellerChange ? 'true' : 'false' ?>;
  const cmdCommercialMaxDiscountPercent = <?= $maxDiscountPercent === null ? 'null' : json_encode((float)$maxDiscountPercent) ?>;
  const cmdCustomerCreditLimit = <?= $selectedCreditLimit === null ? 'null' : json_encode((float)$selectedCreditLimit) ?>;
  const cmdCustomerCreditOpen = <?= json_encode((float)$selectedCreditOpen) ?>;
  let cmdCreditOverrideConfirmed = false;
  const paymentShortcuts = <?= $paymentShortcutsJson ?: '[]' ?> || [];
  const paymentShortcutByKey = Object.fromEntries(paymentShortcuts.map(item => [String(item.key), String(item.id)]));
  const paymentOptionsHtml = `<?php foreach ($payments as $p): ?><option value="<?= (int)$p['id'] ?>" data-payment-type="<?= comanda_h(strtoupper((string)($p['type'] ?? ''))) ?>"><?= comanda_h($p['name']) ?></option><?php endforeach; ?>`;
  const cmdCurrentStep = <?= json_encode($step, JSON_UNESCAPED_SLASHES) ?>;
  const cmdComandaMobileMode = <?= $comandaMobileMode ? 'true' : 'false' ?>;
  const cmdComandaCheckoutMode = <?= $comandaCheckoutMode ? 'true' : 'false' ?>;
  const cmdSelectedId = <?= (int)$selectedId ?>;
  const cmdExistingOpenItems = <?= (int)count($items) ?>;
  const cmdShortcutUrls = {
    abrir: <?= json_encode($selectedId > 0 ? comanda_step_href($selectedId, 'abrir') : $comandaHomeUrl, JSON_UNESCAPED_SLASHES) ?>,
    identificacao: <?= json_encode($selectedId > 0 ? comanda_step_href($selectedId, 'identificacao') : $comandaHomeUrl, JSON_UNESCAPED_SLASHES) ?>,
    pagamento: <?= json_encode($selectedId > 0 ? comanda_step_href($selectedId, 'pagamento') : $comandaHomeUrl, JSON_UNESCAPED_SLASHES) ?>
  };
  const comandaItemsSubtotal = <?= json_encode((float)($totals['total'] ?? 0)) ?>;
  let comandaPaymentTotal = comandaItemsSubtotal;
  let selectedCustomerId = <?= (int)($selected['cliente_id'] ?? 0) ?>;
  const cmdMcCsrf = <?= json_encode($comandaMcCsrf, JSON_UNESCAPED_SLASHES) ?>;
  const autoKitchenPrintUrl = <?= json_encode($autoKitchenPrintUrl, JSON_UNESCAPED_SLASHES) ?>;
  const todayIso = <?= json_encode(date('Y-m-d')) ?>;
  const cmdMobileMode = <?= $comandaMobileMode ? 'true' : 'false' ?>;
  let cmdMobileToastTimer = null;
  function cmdShowToast(message){
    if(!cmdMobileMode && !cmdComandaCheckoutMode) return;
    const el = document.getElementById('cmdMobileToast');
    if(!el) return;
    el.textContent = message || 'Item adicionado';
    el.classList.add('show');
    clearTimeout(cmdMobileToastTimer);
    cmdMobileToastTimer = setTimeout(()=>el.classList.remove('show'), 1400);
  }
  let cmdProcessingFallbackTimer = null;
  function cmdProcessingText(element, fallback='Processando...'){
    const explicit = String(element?.dataset?.processingText || '').trim();
    if(explicit) return explicit;
    const text = String(element?.textContent || element?.value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if(text.includes('pagar') || text.includes('fechar')) return 'Finalizando pagamento...';
    if(text.includes('cliente') && (text.includes('cadastrar') || text.includes('salvar'))) return 'Salvando cliente...';
    if(text.includes('transfer')) return 'Transferindo comanda...';
    if(text.includes('reunir')) return 'Reunindo comandas...';
    if(text.includes('process')) return 'Processando itens...';
    if(text.includes('avanç') || text.includes('pagamento')) return 'Avançando...';
    if(text.includes('abrir')) return 'Abrindo...';
    if(text.includes('conferir')) return 'Carregando conferência...';
    if(text.includes('voltar')) return 'Voltando...';
    return fallback;
  }
  function cmdShowProcessing(message='Processando...'){
    if(!cmdMobileMode) return;
    const overlay = document.getElementById('cmdProcessingOverlay');
    const messageEl = document.getElementById('cmdProcessingMessage');
    if(!overlay) return;
    if(messageEl) messageEl.textContent = message || 'Processando...';
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('cmd-processing-lock');
    clearTimeout(cmdProcessingFallbackTimer);
    cmdProcessingFallbackTimer = setTimeout(cmdHideProcessing, 45000);
  }
  function cmdHideProcessing(){
    const overlay = document.getElementById('cmdProcessingOverlay');
    if(overlay){ overlay.classList.remove('show'); overlay.setAttribute('aria-hidden', 'true'); }
    document.body.classList.remove('cmd-processing-lock');
    clearTimeout(cmdProcessingFallbackTimer);
    cmdProcessingFallbackTimer = null;
  }
  let cmdPaymentLineSeq = 0;
  let cmdActivePaymentLine = null;
  let cmdPaymentShortcutBuffer = '';
  let cmdPaymentShortcutTimer = null;
  const money = (v)=> 'R$ ' + Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  if(autoKitchenPrintUrl){ setTimeout(() => { try { window.open(autoKitchenPrintUrl, '_blank', 'noopener'); } catch(e) {} }, 350); }
  const escapeHtml = (s)=> String(s??'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const norm = (s)=> String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const parseMoney = (v)=> { v=String(v||'0').replace(/\./g,'').replace(',', '.').replace(/[^0-9.-]/g,''); const n=Number(v); return isNaN(n)?0:n; };
  const fmtInput = (v)=> Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const isoAddDays = (dateStr, days)=> { const d = new Date(String(dateStr || todayIso) + 'T12:00:00'); d.setDate(d.getDate() + Number(days || 0)); return d.toISOString().slice(0,10); };
  const isoAddMonthsKeepDay = (dateStr, months, day)=> { const base = new Date(String(dateStr || todayIso) + 'T12:00:00'); const first = new Date(base.getFullYear(), base.getMonth() + Number(months || 0), 1, 12, 0, 0); const last = new Date(first.getFullYear(), first.getMonth()+1, 0).getDate(); first.setDate(Math.min(Math.max(1, Number(day || base.getDate())), last)); return first.toISOString().slice(0,10); };
  const isoNextFixedDay = (dateStr, day)=> { const base=new Date(String(dateStr||todayIso)+'T12:00:00'); const wanted=Math.max(1,Math.min(31,Number(day||1))); for(let i=0;i<24;i++){ const first=new Date(base.getFullYear(),base.getMonth()+i,1,12,0,0); const last=new Date(first.getFullYear(),first.getMonth()+1,0).getDate(); first.setDate(Math.min(wanted,last)); if(first>base) return first.toISOString().slice(0,10); } return isoAddDays(dateStr,30); };
  const splitInstallmentAmounts = (total, count)=> { const cents = Math.round(Number(total || 0) * 100); count = Math.max(1, Number(count || 1)); const base = Math.floor(cents / count); const rem = cents - (base * count); return Array.from({length:count}, (_,i) => (base + (i === count-1 ? rem : 0)) / 100); };
  const openModal = (id)=> { const el=document.getElementById(id); if(el) el.style.display='flex'; };
  const closeModal = (id)=> { const el=document.getElementById(id); if(el) el.style.display='none'; };

  function prepareScaleControls(product, inputId, boxId){
    const box = document.getElementById(boxId);
    const input = document.getElementById(inputId);
    if(!box || !input) return;
    box.innerHTML = '';
    box.style.display = 'none';
    if(!product || !product.pesavel) return;
    box.style.display = '';
    if(window.NexaScale && Number(scaleCfg?.enabled || 0)){
      window.NexaScale.buildControls(scaleCfg, input, {label:'Produto pesável: leia o peso da balança'}).forEach(el => box.appendChild(el));
    }else{
      box.innerHTML = '<strong>Produto pesável</strong><small>Integração com balança desativada. Informe o peso manualmente em kg.</small>';
    }
  }

  const prodWrap = document.getElementById('cmdProducts');
  const catWrap = document.getElementById('cmdCats');
  const search = document.getElementById('cmdProductSearch');
  const searchMode = document.getElementById('cmdProductSearchMode');
  const preparedQty = document.getElementById('cmdPreparedQty');
  const searchModeStorageKey = 'nexa:product-search-mode:<?= (int)$companyId ?>:<?= (int)$userId ?>';
  const validSearchModes = ['instant','menu','description','barcode'];
  const cartBox = document.getElementById('cmdCart');
  const reviewBox = document.getElementById('cmdCartReviewList');
  const hidden = document.getElementById('cmdHiddenItems');
  const productForm = document.getElementById('cmdProductsForm');
  const requireFirstLaunchName = <?= $promptNameFirstLaunch ? 'true' : 'false' ?>;
  let currentCat = '';
  let pendingMultiplier = 1;
  const cart = new Map();
  let weightedCartLineSeq = 0;
  let currentProductForOptions = null;
  let autoProcessAfterOption = false;
  let cmdModalProducts = [];
  let cmdModalProductActiveIndex = -1;
  let cmdModalProductLastQuery = '';

  function cartTotal(){ let v=0; cart.forEach(it => v += Number(it.qty||0) * Number(it.price||0)); return v; }
  function cartCount(){ let n=0; cart.forEach(it => n += Number(it.qty)||0); return n; }

  function parseProductEntry(raw){
    const value = String(raw || '').trim();
    const match = value.match(/^([0-9]+(?:[.,][0-9]+)?)\s*\*\s*(.*)$/);
    if(!match) return {multiplier:pendingMultiplier, query:value, hasMultiplier:false};
    const multiplier = Math.max(0.001, Number(String(match[1]).replace(',', '.')) || 1);
    return {multiplier, query:String(match[2] || '').trim(), hasMultiplier:true};
  }

  function updatePreparedQuantity(){
    if(!preparedQty) return;
    const active = Math.abs(Number(pendingMultiplier || 1) - 1) > 0.000001;
    preparedQty.hidden = !active;
    preparedQty.textContent = String(Number(pendingMultiplier || 1)).replace('.', ',') + ' ×';
  }

  function resetProductEntry(clearSearch=true, resetMultiplier=true){
    if(resetMultiplier) pendingMultiplier = 1;
    if(clearSearch && search) search.value = '';
    updatePreparedQuantity();
  }

  function armMultiplierFromInput(){
    if(!search) return false;
    const raw = String(search.value || '').trim();
    const match = raw.match(/^([0-9]+(?:[.,][0-9]+)?)\s*\*$/);
    if(!match) return false;
    pendingMultiplier = Math.max(0.001, Number(String(match[1]).replace(',', '.')) || 1);
    search.value = '';
    updatePreparedQuantity();
    cmdShowToast('Quantidade ' + String(pendingMultiplier).replace('.', ',') + '× preparada.');
    setTimeout(() => search.focus(), 0);
    return true;
  }

  function activeProductEntry(){
    const parsed = parseProductEntry(search?.value || '');
    if(parsed.hasMultiplier) {
      pendingMultiplier = parsed.multiplier;
      updatePreparedQuantity();
    }
    return {...parsed, multiplier:parsed.hasMultiplier ? parsed.multiplier : pendingMultiplier};
  }

  function currentProductSearchMode(){
    const mode = String(searchMode?.value || 'instant');
    return validSearchModes.includes(mode) ? mode : 'instant';
  }

  function updateProductSearchPlaceholder(){
    if(!search) return;
    const placeholders = {
      instant:'Nome, código, cardápio ou barras',
      menu:'Digite o código do cardápio',
      description:'Digite a descrição do produto',
      barcode:'Passe ou digite o código de barras'
    };
    search.placeholder = placeholders[currentProductSearchMode()] || placeholders.instant;
  }

  function productMatchesSearch(p, query, mode=currentProductSearchMode()){
    const q = norm(query);
    if(!q) return true;
    if(mode === 'menu') return norm(p.menuCode || '').includes(q);
    if(mode === 'description') return norm([p.name,p.description||''].join(' ')).includes(q);
    if(mode === 'barcode') {
      const rawDigits = onlyDigits(query);
      const barcodeDigits = onlyDigits(p.barcode || '');
      return rawDigits ? barcodeDigits.includes(rawDigits) : norm(p.barcode || '').includes(q);
    }
    return norm([p.name,p.description||'',p.category,p.id,p.code||'',p.reference||'',p.menuCode||'',p.barcode||''].join(' ')).includes(q);
  }

  function cmdProductModalMode(){
    const modalMode = document.getElementById('cmdProductModalSearchMode');
    const mode = String(modalMode?.value || currentProductSearchMode());
    return validSearchModes.includes(mode) ? mode : 'instant';
  }

  function resetCmdProductSearchModal(){
    cmdModalProducts = [];
    cmdModalProductActiveIndex = -1;
    cmdModalProductLastQuery = '';
    const results = document.getElementById('cmdProductModalResults');
    if(results) results.innerHTML = '<div class="cmd-product-modal-empty">Digite um termo para pesquisar. A lista começa vazia.</div>';
  }

  function renderCmdProductModalResults(){
    const results = document.getElementById('cmdProductModalResults');
    if(!results) return;
    if(!cmdModalProducts.length){
      results.innerHTML = '<div class="cmd-product-modal-empty">Nenhum produto encontrado.</div>';
      return;
    }
    results.innerHTML = `<table class="cmd-product-modal-table">
      <thead><tr><th>Código</th><th>Produto</th><th>Preço de venda</th><th>Ação</th></tr></thead>
      <tbody>${cmdModalProducts.map((p, index) => {
        const code = String(p.code || p.id || '');
        const codeDetails = [p.reference ? 'Referência: ' + p.reference : '', p.menuCode ? 'Cardápio: ' + p.menuCode : '', p.barcode ? 'Barras: ' + p.barcode : ''].filter(Boolean).join(' · ');
        const nameDetails = String(p.category || '').trim();
        const activeClass = index === cmdModalProductActiveIndex ? ' class="is-active"' : '';
        return `<tr${activeClass} data-cmd-product-modal-row="${index}" data-cmd-product-modal-id="${Number(p.id)}">
          <td class="cmd-product-modal-code">${escapeHtml(code)}${codeDetails ? `<small>${escapeHtml(codeDetails)}</small>` : ''}</td>
          <td class="cmd-product-modal-name"><strong>${escapeHtml(p.name || '')}</strong>${nameDetails ? `<small>${escapeHtml(nameDetails)}</small>` : ''}</td>
          <td class="cmd-product-modal-price">${money(p.price)}</td>
          <td class="cmd-product-modal-select"><button type="button" class="btn btn-primary" data-cmd-product-modal-id="${Number(p.id)}">Selecionar</button></td>
        </tr>`;
      }).join('')}</tbody>
    </table>`;
  }

  function updateCmdProductModalActiveRow(nextIndex, options={}){
    const total = cmdModalProducts.length;
    if(!total){ cmdModalProductActiveIndex = -1; return; }
    const wrap = options.wrap !== false;
    let index = Number(nextIndex);
    if(!Number.isFinite(index)) index = 0;
    if(wrap) index = (index % total + total) % total;
    else index = Math.max(0, Math.min(total - 1, index));
    cmdModalProductActiveIndex = index;
    const rows = document.querySelectorAll('#cmdProductModalResults [data-cmd-product-modal-row]');
    rows.forEach((row, rowIndex) => row.classList.toggle('is-active', rowIndex === index));
    const active = rows[index];
    if(active && options.scroll !== false) active.scrollIntoView({block:'nearest'});
  }

  async function selectCmdProductFromModal(productId){
    const product = cmdModalProducts.find(item => Number(item.id) === Number(productId));
    if(!product) return;
    const qty = Math.max(0.001, Number(pendingMultiplier || 1));
    resetProductEntry(true);
    currentCat = '';
    catWrap?.querySelectorAll('.cmd-cat').forEach(b => b.classList.remove('active'));
    closeModal('cmdProductSearchModal');
    await openOptions(Number(product.id), qty);
    const optionModal = document.getElementById('cmdOptionModal');
    if(!optionModal || getComputedStyle(optionModal).display === 'none') keepProductSearchReady();
  }

  function selectActiveCmdProductFromModal(){
    if(cmdModalProductActiveIndex < 0 || !cmdModalProducts[cmdModalProductActiveIndex]) return false;
    selectCmdProductFromModal(Number(cmdModalProducts[cmdModalProductActiveIndex].id));
    return true;
  }

  function searchCmdProductsInModal(){
    const input = document.getElementById('cmdProductModalSearch');
    const results = document.getElementById('cmdProductModalResults');
    const q = String(input?.value || '').trim();
    if(!results) return;
    if(q === ''){
      resetCmdProductSearchModal();
      input?.focus();
      return;
    }
    const mode = cmdProductModalMode();
    cmdModalProductLastQuery = q;
    cmdModalProducts = products.filter(p => productMatchesSearch(p, q, mode));
    cmdModalProductActiveIndex = cmdModalProducts.length ? 0 : -1;
    renderCmdProductModalResults();
    if(cmdModalProducts.length) updateCmdProductModalActiveRow(0, {scroll:false, wrap:false});
    input?.focus();
  }

  function openCmdProductSearchModal(){
    const modal = document.getElementById('cmdProductSearchModal');
    if(!modal) return;
    const modalMode = document.getElementById('cmdProductModalSearchMode');
    const modalInput = document.getElementById('cmdProductModalSearch');
    if(modalMode) modalMode.value = currentProductSearchMode();
    if(modalInput) modalInput.value = '';
    resetCmdProductSearchModal();
    openModal('cmdProductSearchModal');
    setTimeout(() => modalInput?.focus(), 80);
  }

  function closeCmdProductSearchModal(){
    closeModal('cmdProductSearchModal');
    resetCmdProductSearchModal();
    setTimeout(() => search?.focus(), 0);
  }

  function renderProducts(){
    if(!prodWrap) return;
    const entry = activeProductEntry();
    const q = norm(entry.query);
    if(!q && !currentCat){
      prodWrap.innerHTML = '<div class="cmd-empty">Pesquise um produto ou clique em uma categoria para exibir os itens.</div>';
      return;
    }
    const rows = products.filter(p => {
      const catOk = currentCat === 'Todos' || (currentCat !== '' && String(p.category||'') === currentCat);
      const qOk = !q || productMatchesSearch(p, entry.query);
      return (currentCat ? catOk : true) && qOk;
    });
    const quantityHint = Math.abs(Number(pendingMultiplier || 1) - 1) > 0.000001 ? `<div class="cmd-empty" style="grid-column:1/-1;padding:10px 14px">Quantidade preparada: <strong>${String(pendingMultiplier).replace('.',',')}×</strong>. Informe ou selecione o produto.</div>` : '';
    prodWrap.innerHTML = quantityHint + (rows.map(p => `<button type="button" class="cmd-prod ${cart.has(String(p.id))?'in-cart':''}" data-id="${Number(p.id)}"><img src="${escapeHtml(p.image)}" alt=""><div><strong>${escapeHtml(p.name)}</strong><span>${escapeHtml((p.category||'Sem grupo') + (p.reference ? ' • Ref. ' + p.reference : '') + (p.menuCode ? ' • Cardápio ' + p.menuCode : '') + (p.code ? ' • Cód. ' + p.code : '') + (p.barcode ? ' • EAN ' + p.barcode : ''))}${p.hasOptions?' • adicionais':''}${p.isPizza?' • pizza':''}${p.pesavel?' • pesável':''}${p.printKitchen?'':' • sem cozinha'}</span><b>${money(p.price)}</b></div></button>`).join('') || '<div class="cmd-empty">Nenhum produto localizado.</div>');
  }

  function addProduct(id, cfg={}){
    const p = products.find(x => Number(x.id) === Number(id)); if(!p) return;
    const baseKey = cfg.key || String(id);
    // Produto pesável representa uma pesagem física individual. Nunca acumular
    // automaticamente com uma pesagem anterior do mesmo produto.
    const key = p.pesavel ? `${baseKey}__peso_${Date.now()}_${++weightedCartLineSeq}` : baseKey;
    const it = cart.get(key) || {id:p.id, name:cfg.displayName || p.name, qty:0, price:Number(cfg.basePrice ?? p.price) + Number(cfg.extra||0), obs:cfg.note || '', details:cfg.summary || '', splitItems: cfg.splitItems || null};
    it.qty += Number(cfg.qty || 1);
    if (cfg.splitItems) it.splitItems = cfg.splitItems;
    cart.set(key, it);
    renderCart();
    cmdShowToast('Item adicionado');
  }

  function submitProductsNow(){
    renderCart();
    if(!productForm) return;
    if (typeof productForm.requestSubmit === 'function') productForm.requestSubmit();
    else {
      const submitEvent = new Event('submit', {bubbles:true, cancelable:true});
      if(productForm.dispatchEvent(submitEvent)) HTMLFormElement.prototype.submit.call(productForm);
    }
  }

  function keepProductSearchReady(){
    if(!search) return;
    setTimeout(() => {
      try { search.focus(); search.select(); } catch(e) {}
    }, 60);
  }

  function onlyDigits(v){ return String(v || '').replace(/\D+/g, ''); }
  function findProductByCode(raw){
    const value = String(raw || '').trim();
    if(!value) return null;
    const digits = onlyDigits(value);
    const valueNorm = norm(value);
    const mode = currentProductSearchMode();
    let found = null;

    if(mode === 'menu'){
      found = products.find(p => norm(String(p.menuCode || '').trim()) === valueNorm);
    }else if(mode === 'barcode'){
      found = products.find(p => String(p.barcode || '').trim() === value);
      if(!found && digits) found = products.find(p => onlyDigits(p.barcode || '') === digits);
    }else if(mode === 'description'){
      found = products.find(p => norm(String(p.name || '').trim()) === valueNorm || norm(String(p.description || '').trim()) === valueNorm);
      if(!found){
        const matches = products.filter(p => productMatchesSearch(p, value, mode));
        if(matches.length === 1) found = matches[0];
      }
    }else{
      found = products.find(p => String(p.barcode || '').trim() === value || String(p.reference || '').trim() === value || String(p.menuCode || '').trim() === value || String(p.code || '').trim() === value || String(p.id || '').trim() === value);
      if(!found && digits){
        found = products.find(p => onlyDigits(p.barcode || '') === digits || onlyDigits(p.reference || '') === digits || onlyDigits(p.menuCode || '') === digits || onlyDigits(p.code || '') === digits || String(p.id || '') === digits);
      }
      if(!found){
        const matches = products.filter(p => productMatchesSearch(p, value, mode));
        if(matches.length === 1) found = matches[0];
      }
    }
    return found || null;
  }
  async function handleProductSearchEnter(){
    const entry = activeProductEntry();
    if(entry.hasMultiplier && entry.query === ''){
      pendingMultiplier = entry.multiplier;
      if(search) search.value = '';
      updatePreparedQuantity();
      renderProducts();
      cmdShowToast('Quantidade ' + String(entry.multiplier).replace('.', ',') + '× preparada. Informe o produto.');
      keepProductSearchReady();
      return true;
    }
    const p = findProductByCode(entry.query);
    if(!p) return false;
    const qty = entry.multiplier;
    resetProductEntry(true);
    currentCat = '';
    catWrap?.querySelectorAll('.cmd-cat').forEach(b=>b.classList.remove('active'));
    if(p.hasOptions || p.isPizza || p.pesavel){ await openOptions(Number(p.id), qty); return true; }
    addProduct(p.id, {qty});
    keepProductSearchReady();
    return true;
  }

  function formatKg(v){
    return Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:3, maximumFractionDigits:3}) + ' kg';
  }

  function prepareOptionModal(product, groups, manualReason, requestedQty=1){
    currentProductForOptions = product;
    const title = document.getElementById('cmdOptProductTitle');
    if(title) title.textContent = product.name + (product.pesavel ? ' (produto pesável)' : '');
    const qty = document.getElementById('cmdOptQty'); if(qty) qty.value = product.pesavel ? '' : String(Math.max(0.001, Number(requestedQty || 1))).replace('.', ',');
    const note = document.getElementById('cmdOptNote'); if(note) note.value = '';
    const confirmBtn = document.getElementById('cmdOptConfirm');
    if(confirmBtn) confirmBtn.textContent = autoProcessAfterOption ? 'Processar peso' : 'Adicionar/Aplicar';
    const scaleBox = document.getElementById('cmdScaleBox');
    if(manualReason && scaleBox){
      scaleBox.style.display = '';
      scaleBox.innerHTML = '<strong>Informar peso manualmente</strong><small>' + escapeHtml(manualReason) + '</small>';
    }else{
      prepareScaleControls(product, 'cmdOptQty', 'cmdScaleBox');
    }

    const half = document.getElementById('cmdOptHalfBox');
    if(half){
      const sameCategoryPizzas = pizzas.filter(x => String(x.category || '').trim().toLocaleLowerCase('pt-BR') === String(product.category || '').trim().toLocaleLowerCase('pt-BR'));
      if(product.isPizza && sameCategoryPizzas.length > 1){
        half.style.display = '';
        half.innerHTML = `<strong>Pizza meio a meio</strong><label style="display:flex;gap:8px;align-items:center;margin:6px 0 10px;"><input type="checkbox" id="cmdHalfEnabled"> Montar meio a meio</label><div class="mg-form-grid"><label>1º sabor<input readonly value="${escapeHtml(product.name)}"></label><label>2º sabor<select id="cmdHalfFlavor"><option value="">Selecione o segundo sabor</option>${sameCategoryPizzas.filter(x=>Number(x.id)!==Number(product.id)).map(x=>`<option value="${Number(x.id)}" data-price="${Number(x.price||0)}">${escapeHtml(x.name)} - ${money(x.price)}</option>`).join('')}</select></label></div><small class="cmd-muted">Somente produtos da mesma categoria. Regra de preço: prevalece o maior valor entre os dois sabores.</small>`;
      } else { half.style.display = 'none'; half.innerHTML = ''; }
    }

    const groupsEl = document.getElementById('cmdOptGroups');
    if(groupsEl){
      groupsEl.innerHTML = groups.map(g => {
        const inputType = (g.type === 'multiple' || Number(g.max) > 1) ? 'checkbox' : 'radio';
        return `<fieldset class="cmd-opt-group" data-group="${Number(g.id)}" data-required="${Number(g.required||0)}" data-min="${Number(g.min||0)}" data-max="${Number(g.max||0)}"><legend><span>${escapeHtml(g.title)}</span><small>${Number(g.required||0)===1?'Obrigatório':'Opcional'}${Number(g.max||0)>0?` • até ${Number(g.max)}`:''}</small></legend><div class="cmd-opt-items">${(g.items||[]).map(it=>`<label class="cmd-opt-item"><input type="${inputType}" name="cmd_opt_group_${Number(g.id)}" value="${Number(it.id)}" data-name="${escapeHtml(it.name)}" data-price="${Number(it.price||0)}"><span><b>${escapeHtml(it.name)}</b><small class="cmd-opt-price"><span>${Number(it.price||0)>0?('+'+money(it.price)):'sem acréscimo'}</span><em class="cmd-opt-final" hidden></em></small></span></label>`).join('')}</div></fieldset>`;
      }).join('') || '<div class="cmd-empty">Informe a quantidade em kg e confirme para lançar a comanda.</div>';
    }
    updateOptionsTotal();
  }

  async function processWeightedProduct(product){
    try{
      if(!window.NexaScale || !Number(scaleCfg?.enabled || 0) || !window.NexaScale.isSupported()) throw new Error('Sem conexão automática com a balança. Digite o peso em kg.');
      const weight = await window.NexaScale.readWeight(scaleCfg, {fresh:true});
      if(Number(weight || 0) <= 0) throw new Error('Peso não localizado. Digite o peso em kg.');
      addProduct(product.id, {qty:Number(weight), key:'peso_'+Number(product.id)+'_'+Date.now(), summary:'Peso balança: ' + formatKg(weight)});
      keepProductSearchReady();
    }catch(e){
      autoProcessAfterOption = false;
      prepareOptionModal(product, [], e && e.message ? e.message : 'Não foi possível ler a balança. Digite o peso em kg.');
      openModal('cmdOptionModal');
      setTimeout(()=>document.getElementById('cmdOptQty')?.focus(), 80);
    }
  }

  async function openOptions(id, requestedQty=1){
    const p = products.find(x => Number(x.id) === Number(id)); if(!p) return;
    const groups = productOptions[p.id] || [];
    autoProcessAfterOption = false;
    if(p.pesavel && !groups.length && !p.isPizza){ await processWeightedProduct(p); return; }
    if(!groups.length && !p.isPizza && !p.pesavel){ addProduct(p.id, {qty:requestedQty}); return; }
    prepareOptionModal(p, groups, '', requestedQty);
    openModal('cmdOptionModal');
    if(p.pesavel && window.NexaScale && Number(scaleCfg?.enabled || 0) && window.NexaScale.isSupported()){
      const qty = document.getElementById('cmdOptQty');
      const box = document.getElementById('cmdScaleBox');
      const status = box?.querySelector('[data-scale-status]');
      try{
        if(status) status.textContent = 'Lendo peso automaticamente...';
        const w = await window.NexaScale.readWeight(scaleCfg, {fresh:true});
        window.NexaScale.applyToInput(qty, w);
        if(status) status.textContent = 'Peso aplicado: ' + formatKg(w);
      }catch(e){
        if(status) status.textContent = e && e.message ? e.message : 'Digite o peso manualmente.';
      }
    }
  }

  function closeOptions(){ const box=document.getElementById('cmdScaleBox'); if(box){ box.innerHTML=''; box.style.display='none'; } const confirmBtn=document.getElementById('cmdOptConfirm'); if(confirmBtn) confirmBtn.textContent='Adicionar/Aplicar'; closeModal('cmdOptionModal'); currentProductForOptions = null; autoProcessAfterOption = false; }

  function updateOptionsTotal(){
    if(!currentProductForOptions) return;
    let extra = 0;
    let base = Number(currentProductForOptions.price || 0);
    document.querySelectorAll('#cmdOptGroups input').forEach(i => {
      const price = Number(i.dataset.price || 0);
      const finalEl = i.closest('.cmd-opt-item')?.querySelector('.cmd-opt-final');
      if(finalEl){
        finalEl.textContent = 'Total: ' + money(base + price);
        finalEl.hidden = !(productOptionDisplay.showAdditionalUnitTotal && i.checked);
      }
      if(i.checked) extra += price;
    });
    const halfEnabled = document.getElementById('cmdHalfEnabled');
    const halfFlavor = document.getElementById('cmdHalfFlavor');
    if(halfEnabled && halfEnabled.checked && halfFlavor && halfFlavor.value){
      const opt = halfFlavor.options[halfFlavor.selectedIndex];
      base = Math.max(base, Number(opt?.dataset.price || 0));
    }
    document.querySelectorAll('#cmdOptGroups input').forEach(i => {
      const price = Number(i.dataset.price || 0);
      const finalEl = i.closest('.cmd-opt-item')?.querySelector('.cmd-opt-final');
      if(finalEl){
        finalEl.textContent = 'Total: ' + money(base + price);
        finalEl.hidden = !(productOptionDisplay.showAdditionalUnitTotal && i.checked);
      }
    });
    const total = document.getElementById('cmdOptTotal');
    if(total) total.value = productOptionDisplay.showItemTotalWithAdditionals ? ('+ ' + money(extra) + ' | Item: ' + money(base + extra)) : ('+ ' + money(extra));
  }

  function confirmOptions(){
    if(!currentProductForOptions) return;
    const groups = Array.from(document.querySelectorAll('#cmdOptGroups .cmd-opt-group'));
    let extra = 0, lines = [], selectedIds = [];
    for(const g of groups){
      const checked = Array.from(g.querySelectorAll('input:checked'));
      const required = Number(g.dataset.required || 0) === 1;
      const min = Number(g.dataset.min || 0);
      const max = Number(g.dataset.max || 0);
      const needed = min || (required ? 1 : 0);
      if(checked.length < needed){ alert('Selecione as opções obrigatórias.'); return; }
      if(max > 0 && checked.length > max){ alert('Este grupo permite no máximo ' + max + ' opção(ões).'); return; }
      const groupTitle = g.querySelector('legend span')?.textContent.trim() || '';
      const groupLines = [];
      checked.forEach(i => {
        extra += Number(i.dataset.price || 0);
        selectedIds.push(i.value);
        groupLines.push(`${i.dataset.name}${Number(i.dataset.price||0)>0 ? ' +' + money(i.dataset.price) : ''}`);
      });
      if(groupLines.length) lines.push((groupTitle ? groupTitle + ': ' : '') + groupLines.join(', '));
    }
    let basePrice = Number(currentProductForOptions.price || 0);
    let displayName = currentProductForOptions.name;
    const halfEnabled = document.getElementById('cmdHalfEnabled');
    const halfFlavor = document.getElementById('cmdHalfFlavor');
    let splitItems = null;
    if(halfEnabled && halfEnabled.checked){
      if(!halfFlavor || !halfFlavor.value){ alert('Selecione o segundo sabor da pizza.'); return; }
      const opt = halfFlavor.options[halfFlavor.selectedIndex];
      const secondFlavorId = Number(halfFlavor.value || 0);
      basePrice = Math.max(basePrice, Number(opt?.dataset.price || 0));
      const secondFlavorName = (opt.textContent || '').replace(/ - R\$.*/, '').trim();
      displayName = 'Pizza meio a meio';
      lines.unshift('1/2 ' + currentProductForOptions.name + ' +');
      lines.unshift('Pizza meio a meio:');
      lines.splice(2, 0, '1/2 ' + secondFlavorName);
      splitItems = [
        {id: Number(currentProductForOptions.id), qtyFactor: 0.5, obs: 'full'},
        {id: secondFlavorId, qtyFactor: 0.5, obs: ''}
      ];
      selectedIds.push('half_'+halfFlavor.value);
    }
    const qtyRaw = parseMoney(document.getElementById('cmdOptQty')?.value || '0');
    if(currentProductForOptions.pesavel && qtyRaw <= 0){ alert('Leia o peso da balança ou informe a quantidade em kg.'); return; }
    const qty = Math.max(0.001, qtyRaw || 1);
    const note = (document.getElementById('cmdOptNote')?.value || '').trim();
    const signature = [currentProductForOptions.id, selectedIds.sort().join('-'), note.toLowerCase(), displayName].join('|');
    let key = 'cfg_' + btoa(unescape(encodeURIComponent(signature))).replace(/=/g,'').slice(0,24);
    addProduct(currentProductForOptions.id, {extra, qty, note, summary: lines.join('\n'), basePrice, displayName, splitItems, key});
    closeOptions();
    keepProductSearchReady();
  }

  function renderCart(){
    if(!cartBox || !hidden) return;
    const rows = [];
    cart.forEach((it,key)=>{
      rows.push(`<div class="cmd-cart-item"><div class="cmd-cart-line"><strong>${escapeHtml(it.name)}</strong><b>${money(it.qty*it.price)}</b></div>${it.details?`<small class="cmd-muted" style="display:block;white-space:pre-line;margin-top:4px;">${escapeHtml(it.details)}</small>`:''}<div class="cmd-qty"><button type="button" data-dec="${escapeHtml(key)}">−</button><input data-qty="${escapeHtml(key)}" value="${String(it.qty).replace('.',',')}"><button type="button" data-inc="${escapeHtml(key)}">+</button><button type="button" data-del="${escapeHtml(key)}">×</button></div><input class="cmd-item-note" data-obs="${escapeHtml(key)}" value="${escapeHtml(it.obs||'')}" placeholder="${escapeHtml(productOptionDisplay.itemNoteInstruction || 'Observação do item')}"></div>`);
    });
    const cartHtml = rows.join('') || '<div class="cmd-empty">Toque em um produto para adicionar.</div>';
    cartBox.innerHTML = cartHtml;
    if(reviewBox) reviewBox.innerHTML = cartHtml;
    hidden.innerHTML = Array.from(cart.values()).map(it=>{
      const itemObs = (it.details ? it.details + '\n' : '') + (it.obs || '');
      if (it.splitItems && Array.isArray(it.splitItems) && it.splitItems.length) {
        return it.splitItems.map((sp, idx) => {
          const obs = (sp.obs === 'full' || idx === 0) ? itemObs : '';
          const qtd = Number(it.qty || 0) * Number(sp.qtyFactor || 1);
          return `<input type="hidden" name="item_product_id[]" value="${Number(sp.id||it.id)}"><input type="hidden" name="item_qty[]" value="${qtd}"><input type="hidden" name="item_price[]" value="${Number(it.price||0)}"><textarea name="item_obs[]" style="display:none">${escapeHtml(obs)}</textarea>`;
        }).join('');
      }
      return `<input type="hidden" name="item_product_id[]" value="${Number(it.id)}"><input type="hidden" name="item_qty[]" value="${Number(it.qty)}"><input type="hidden" name="item_price[]" value="${Number(it.price||0)}"><textarea name="item_obs[]" style="display:none">${escapeHtml(itemObs)}</textarea>`;
    }).join('');
    const countEl = document.getElementById('cmdCartCount'); if(countEl) countEl.textContent = String(cartCount()).replace('.', ',');
    const totalEl = document.getElementById('cmdCartTotal'); if(totalEl) totalEl.textContent = money(cartTotal());
    const mobileCountEl = document.getElementById('cmdMobileFooterCount'); if(mobileCountEl) mobileCountEl.textContent = cartCount() > 0 ? (String(cartCount()).replace('.', ',') + ' item(ns)') : 'Nenhum item';
    const mobileTotalEl = document.getElementById('cmdMobileFooterTotal'); if(mobileTotalEl) mobileTotalEl.textContent = money(cartTotal());
    const reviewCountEl = document.getElementById('cmdReviewCount'); if(reviewCountEl) reviewCountEl.textContent = String(cartCount()).replace('.', ',');
    const reviewTotalEl = document.getElementById('cmdReviewTotal'); if(reviewTotalEl) reviewTotalEl.textContent = money(cartTotal());
    renderProducts();
    if(cmdComandaCheckoutMode){
      const scroller = document.getElementById('cmdCheckoutLaunchScroll');
      if(scroller && cart.size > 0){ requestAnimationFrame(() => { scroller.scrollTop = scroller.scrollHeight; }); }
    }
  }

  prodWrap?.addEventListener('click', async e => {
    const btn = e.target.closest('[data-id]'); if(!btn) return;
    const entry = activeProductEntry();
    const qty = entry.hasMultiplier ? entry.multiplier : pendingMultiplier;
    resetProductEntry(true);
    await openOptions(Number(btn.dataset.id), qty);
    renderProducts();
  });
  catWrap?.addEventListener('click', e => { const btn = e.target.closest('[data-cat]'); if(!btn) return; currentCat = btn.dataset.cat || 'Todos'; catWrap.querySelectorAll('.cmd-cat').forEach(b=>b.classList.toggle('active', b===btn)); renderProducts(); });
  search?.addEventListener('input', () => {
    if(armMultiplierFromInput()){ renderProducts(); return; }
    renderProducts();
  });
  search?.addEventListener('keydown', async e => { if(e.key === 'Enter'){ e.preventDefault(); const ok = await handleProductSearchEnter(); if(!ok) alert('Produto não localizado para o critério informado.'); }});
  document.getElementById('cmdProductSearchBtn')?.addEventListener('click', openCmdProductSearchModal);
  document.getElementById('cmdProductModalSearchBtn')?.addEventListener('click', searchCmdProductsInModal);
  document.getElementById('cmdProductModalSearch')?.addEventListener('input', () => {
    cmdModalProductActiveIndex = -1;
    document.querySelectorAll('#cmdProductModalResults [data-cmd-product-modal-row]').forEach(row => row.classList.remove('is-active'));
  });
  document.getElementById('cmdProductModalSearch')?.addEventListener('keydown', e => {
    if(e.key === 'ArrowDown' && cmdModalProducts.length){
      e.preventDefault();
      updateCmdProductModalActiveRow(cmdModalProductActiveIndex < 0 ? 0 : cmdModalProductActiveIndex + 1);
      return;
    }
    if(e.key === 'ArrowUp' && cmdModalProducts.length){
      e.preventDefault();
      updateCmdProductModalActiveRow(cmdModalProductActiveIndex < 0 ? cmdModalProducts.length - 1 : cmdModalProductActiveIndex - 1);
      return;
    }
    if(e.key === 'Enter'){
      e.preventDefault();
      const currentQuery = String(e.currentTarget.value || '').trim();
      if(cmdModalProducts.length && cmdModalProductActiveIndex >= 0 && currentQuery === cmdModalProductLastQuery){
        selectActiveCmdProductFromModal();
      }else{
        searchCmdProductsInModal();
      }
      return;
    }
    if(e.key === 'Escape'){
      e.preventDefault();
      closeCmdProductSearchModal();
    }
  });
  document.getElementById('cmdProductModalSearchMode')?.addEventListener('change', e => {
    const mode = String(e.target.value || 'instant');
    if(searchMode && validSearchModes.includes(mode)) searchMode.value = mode;
    try { localStorage.setItem(searchModeStorageKey, mode); } catch(err) {}
    updateProductSearchPlaceholder();
    const modalInput = document.getElementById('cmdProductModalSearch');
    if(modalInput) modalInput.value = '';
    resetCmdProductSearchModal();
    setTimeout(() => modalInput?.focus(), 0);
  });
  document.getElementById('cmdProductModalResults')?.addEventListener('mousemove', e => {
    const row = e.target.closest('[data-cmd-product-modal-row]');
    if(row) updateCmdProductModalActiveRow(Number(row.dataset.cmdProductModalRow), {scroll:false, wrap:false});
  });
  document.getElementById('cmdProductModalResults')?.addEventListener('click', e => {
    const target = e.target.closest('[data-cmd-product-modal-id]');
    if(target) selectCmdProductFromModal(Number(target.dataset.cmdProductModalId));
  });
  document.getElementById('cmdProductSearchClose')?.addEventListener('click', closeCmdProductSearchModal);
  document.getElementById('cmdProductSearchModal')?.addEventListener('click', e => { if(e.target.id === 'cmdProductSearchModal') closeCmdProductSearchModal(); });
  preparedQty?.addEventListener('click', () => { pendingMultiplier = 1; updatePreparedQuantity(); keepProductSearchReady(); renderProducts(); });
  searchMode?.addEventListener('change', () => {
    const mode = currentProductSearchMode();
    try { localStorage.setItem(searchModeStorageKey, mode); } catch(e) {}
    updateProductSearchPlaceholder();
    if(search) search.value = '';
    currentCat = '';
    catWrap?.querySelectorAll('.cmd-cat').forEach(b=>b.classList.remove('active'));
    renderProducts();
    keepProductSearchReady();
  });
  function handleCartClick(e){
    const dec=e.target.closest('[data-dec]'), inc=e.target.closest('[data-inc]'), del=e.target.closest('[data-del]');
    const k = dec?.dataset.dec || inc?.dataset.inc || del?.dataset.del;
    if(!k || !cart.has(k)) return;
    const it=cart.get(k);
    if(inc) it.qty += 1;
    if(dec) it.qty = Math.max(0, it.qty-1);
    if(del || it.qty<=0) cart.delete(k); else cart.set(k,it);
    renderCart();
  }
  function handleCartInput(e){
    const q=e.target.closest('[data-qty]'), o=e.target.closest('[data-obs]');
    if(q){ const k=q.dataset.qty; if(cart.has(k)){ const it=cart.get(k); it.qty=Math.max(0, parseMoney(q.value)); if(it.qty<=0) cart.delete(k); else cart.set(k,it); renderCart(); }}
    if(o){ const k=o.dataset.obs; if(cart.has(k)){ const it=cart.get(k); it.obs=o.value; cart.set(k,it); renderCart(); }}
  }
  cartBox?.addEventListener('click', handleCartClick);
  cartBox?.addEventListener('input', handleCartInput);
  reviewBox?.addEventListener('click', handleCartClick);
  reviewBox?.addEventListener('input', handleCartInput);
  function openCartReview(){
    if(cart.size <= 0){ alert('Selecione pelo menos um produto.'); return; }
    renderCart();
    openModal('cmdCartReviewModal');
  }
  document.getElementById('cmdOpenCartReview')?.addEventListener('click', openCartReview);
  document.getElementById('cmdMobileReviewProcess')?.addEventListener('click', openCartReview);
  document.getElementById('cmdCartReviewClose')?.addEventListener('click', () => closeModal('cmdCartReviewModal'));
  document.getElementById('cmdCartReviewContinue')?.addEventListener('click', () => { closeModal('cmdCartReviewModal'); keepProductSearchReady(); });
  document.getElementById('cmdCartReviewConfirm')?.addEventListener('click', () => {
    if(!productForm || cart.size <= 0) return;
    productForm.dataset.reviewConfirmed = '1';
    productForm.dataset.processingText = 'Processando...';
    closeModal('cmdCartReviewModal');
    submitProductsNow();
  });
  document.getElementById('cmdCartReviewModal')?.addEventListener('click', e => { if(e.target.id === 'cmdCartReviewModal') closeModal('cmdCartReviewModal'); });

  function openFirstLaunchName(){
    closeModal('cmdCartReviewModal');
    openModal('cmdFirstNameModal');
    const input = document.getElementById('cmdFirstNameInput');
    setTimeout(() => { try { input?.focus(); input?.select(); } catch(e) {} }, 60);
  }
  document.getElementById('cmdFirstNameCancel')?.addEventListener('click', () => {
    closeModal('cmdFirstNameModal');
    keepProductSearchReady();
  });
  document.getElementById('cmdFirstNameForm')?.addEventListener('submit', e => {
    e.preventDefault();
    if(!productForm || cart.size <= 0) return;
    const input = document.getElementById('cmdFirstNameInput');
    const name = String(input?.value || '').replace(/\s+/g, ' ').trim();
    if(!name){ alert('Informe o nome da pessoa/comanda.'); input?.focus(); return; }
    const hiddenName = document.getElementById('cmdFirstLaunchName');
    if(hiddenName) hiddenName.value = name;
    productForm.dataset.firstNameConfirmed = '1';
    productForm.dataset.processingText = 'Identificando e processando comanda...';
    closeModal('cmdFirstNameModal');
    submitProductsNow();
  });
  document.getElementById('cmdProductsSubmit')?.addEventListener('click', () => { const next=document.getElementById('cmdAfterProcessStep'); if(next) next.value=''; });
  document.getElementById('cmdViewComandaBtn')?.addEventListener('click', () => { cmdNavigateShortcut('abrir'); });
  document.getElementById('cmdGoPaymentBtn')?.addEventListener('click', () => { cmdNavigateShortcut('pagamento'); });
  document.getElementById('cmdBillComandaBtn')?.addEventListener('click', () => {
    if(cmdComandaCheckoutMode && cmdCurrentStep === 'produtos'){
      openModal('cmdCustomerModal');
      setTimeout(()=>document.getElementById('cmdCustomerModalSearch')?.focus(), 60);
      return;
    }
    const next = document.getElementById('cmdAfterProcessStep');
    if(next) next.value = 'identificacao_pagamento';
    if(productForm) productForm.dataset.processingText = 'Preparando faturamento da comanda...';
    submitProductsNow();
  });
  document.getElementById('cmdProductsForm')?.addEventListener('submit', e => {
    const requestedNext = String(document.getElementById('cmdAfterProcessStep')?.value || '');
    const billingFlow = requestedNext === 'identificacao_pagamento';
    if(cart.size <= 0 && !(billingFlow && cmdExistingOpenItems > 0)){
      e.preventDefault();
      alert(billingFlow ? 'A comanda não possui itens para faturar. Selecione pelo menos um produto.' : 'Selecione pelo menos um produto.');
      return;
    }
    if(cmdMobileMode && productForm?.dataset.reviewConfirmed !== '1'){ e.preventDefault(); openCartReview(); return; }
    if(requireFirstLaunchName && cart.size > 0 && productForm?.dataset.firstNameConfirmed !== '1'){
      e.preventDefault();
      openFirstLaunchName();
    }
  });
  document.getElementById('cmdOptGroups')?.addEventListener('change', updateOptionsTotal);
  document.getElementById('cmdOptHalfBox')?.addEventListener('change', updateOptionsTotal);
  document.getElementById('cmdOptCancel')?.addEventListener('click', closeOptions);
  document.getElementById('cmdOptCancelTop')?.addEventListener('click', closeOptions);
  document.getElementById('cmdOptConfirm')?.addEventListener('click', confirmOptions);
  document.getElementById('cmdOptionModal')?.addEventListener('click', e => { if(e.target.id === 'cmdOptionModal') closeOptions(); });
  renderProducts();
  if(searchMode){
    try {
      const storedMode = localStorage.getItem(searchModeStorageKey);
      if(storedMode && validSearchModes.includes(storedMode)) searchMode.value = storedMode;
    } catch(e) {}
  }
  updateProductSearchPlaceholder();
  updatePreparedQuantity();
  if(search){ setTimeout(() => { try { search.focus(); search.select(); } catch(e) {} }, 80); }

  function cmdRenderInstallments(line, force=false){
    const methodId = line.querySelector('.pay-method')?.value || '';
    const p = paymentMap[methodId] || {};
    const box = line.querySelector('.cmd-installments');
    if(!box) return;
    const rowsBox = box.querySelector('.cmd-installments-rows');
    if(Number(p.receivable || 0) !== 1){ box.classList.remove('show'); if(rowsBox) rowsBox.innerHTML=''; return; }
    box.classList.add('show');
    if(!rowsBox) return;
    const amount = parseMoney(line.querySelector('.pay-amount')?.value || '0');
    const countInput = box.querySelector('.installment-count');
    countInput.disabled = Number(p.allowInstallments || 0) !== 1;
    const defaultCount = Math.max(1, Number(p.defaultInstallments || 1));
    if(!countInput.value || Number(countInput.value) < 1) countInput.value = String(Number(p.allowInstallments || 0) === 1 ? defaultCount : 1);
    const desiredCount = Number(p.allowInstallments || 0) === 1 ? Math.max(1, Math.min(120, Number(countInput.value || defaultCount))) : 1;
    const amountKey = fmtInput(amount);
    if(!force && rowsBox.querySelectorAll('.cmd-installment-row').length === desiredCount && rowsBox.querySelectorAll('input').length && box.dataset.amount === amountKey) return;
    const fixedDueDay = Number(p.fixedDueDay || 0);
    const firstDue = (Number(p.creRulesEnabled||0)===1 && Number(p.keepDueDay||0)===1 && fixedDueDay>=1) ? isoNextFixedDay(todayIso, fixedDueDay) : isoAddDays(todayIso, Number(p.firstDueDays || 30));
    const dueDay = fixedDueDay>=1 ? fixedDueDay : Number(firstDue.slice(8,10));
    const periodDays = Math.max(1, Number(p.periodDays || 30));
    const values = splitInstallmentAmounts(amount, desiredCount);
    const key = line.querySelector('.pay-line-key')?.value || '0';
    box.dataset.amount = amountKey;
    rowsBox.innerHTML = '';
    for(let i=0;i<desiredCount;i++){
      const due = i === 0 ? firstDue : (Number(p.keepDueDay||0)===1 ? isoAddMonthsKeepDay(firstDue, i, dueDay) : isoAddDays(firstDue, periodDays * i));
      const row = document.createElement('div');
      row.className = 'cmd-installment-row';
      row.innerHTML = `<div class="cmd-installment-label">${String(i+1).padStart(2,'0')}/${String(desiredCount).padStart(2,'0')}</div><div class="cmd-installment-due-wrap"><input class="installment-due" type="date" name="installment_due[${key}][]" value="${due}" required></div><div class="cmd-installment-amount-wrap"><input class="installment-amount" name="installment_amount[${key}][]" inputmode="decimal" value="${fmtInput(values[i])}" required></div>`;
      rowsBox.appendChild(row);
    }
  }

  const cmdSellerModal = document.getElementById('cmdSellerModal');
  const cmdSellerModalSearch = document.getElementById('cmdSellerModalSearch');
  const cmdSellerModalResults = document.getElementById('cmdSellerModalResults');
  let cmdSellerFiltered = [];
  let cmdSellerActiveIndex = -1;
  function cmdSellerLabel(s){
    if(!s) return 'Sem vendedor';
    const code=String(s.code||'').trim(), name=String(s.name||'').trim();
    return (code ? code+' - ' : '') + (name || 'Vendedor');
  }
  function cmdRenderSellerRows(){
    if(!cmdSellerModalResults) return;
    if(!cmdSellerFiltered.length){ cmdSellerModalResults.innerHTML='<div class="cmd-empty">Nenhum vendedor localizado.</div>'; return; }
    cmdSellerModalResults.innerHTML=cmdSellerFiltered.map((s,index)=>`<button type="button" class="cmd-seller-row${index===cmdSellerActiveIndex?' is-active':''}" data-cmd-seller-index="${index}"><code>${escapeHtml(String(s.code||s.id||''))}</code><strong>${escapeHtml(String(s.name||''))}</strong><small>${escapeHtml(String(s.document||''))}</small></button>`).join('');
    const active=cmdSellerModalResults.querySelector('.cmd-seller-row.is-active');
    active?.scrollIntoView?.({block:'nearest'});
  }
  function cmdSearchSellers(){
    if(!cmdSellerModalResults) return;
    const raw=String(cmdSellerModalSearch?.value||'').trim();
    const q=norm(raw), digits=raw.replace(/\D/g,'');
    if(!q && !digits){ cmdSellerFiltered=[]; cmdSellerActiveIndex=-1; cmdSellerModalResults.innerHTML='<div class="cmd-empty">Digite um termo para pesquisar. A lista começa vazia.</div>'; return; }
    cmdSellerFiltered=cmdSellers.filter(s=>norm(`${s.code||''} ${s.name||''}`).includes(q) || (digits && String(s.document||'').includes(digits))).slice(0,100);
    cmdSellerActiveIndex=cmdSellerFiltered.length ? 0 : -1;
    cmdRenderSellerRows();
  }
  function cmdSelectSeller(s){
    const id=document.getElementById('cmdSellerId'), name=document.getElementById('cmdSellerName');
    if(id) id.value=s ? String(s.id||0) : '';
    if(name) name.value=cmdSellerLabel(s);
    closeModal('cmdSellerModal');
  }
  function openCmdSellerModal(){
    if(!cmdAllowSellerChange || !cmdSellerModal) return;
    if(cmdSellerModalSearch) cmdSellerModalSearch.value='';
    cmdSellerFiltered=[]; cmdSellerActiveIndex=-1;
    if(cmdSellerModalResults) cmdSellerModalResults.innerHTML='<div class="cmd-empty">Digite um termo para pesquisar. A lista começa vazia.</div>';
    openModal('cmdSellerModal');
    setTimeout(()=>cmdSellerModalSearch?.focus(),60);
  }
  document.getElementById('cmdSellerSearchBtn')?.addEventListener('click', openCmdSellerModal);
  document.getElementById('cmdSellerModalBtn')?.addEventListener('click', cmdSearchSellers);
  document.getElementById('cmdSellerModalClose')?.addEventListener('click', ()=>closeModal('cmdSellerModal'));
  document.getElementById('cmdSellerModalNone')?.addEventListener('click', ()=>cmdSelectSeller(null));
  cmdSellerModalResults?.addEventListener('click', e=>{ const row=e.target.closest('[data-cmd-seller-index]'); if(!row) return; const s=cmdSellerFiltered[Number(row.dataset.cmdSellerIndex)]; if(s) cmdSelectSeller(s); });
  cmdSellerModal?.addEventListener('click', e=>{ if(e.target===cmdSellerModal) closeModal('cmdSellerModal'); });
  cmdSellerModalSearch?.addEventListener('input', ()=>{
    cmdSellerFiltered=[]; cmdSellerActiveIndex=-1;
    if(cmdSellerModalResults) cmdSellerModalResults.innerHTML='<div class="cmd-empty">Pressione Enter ou clique em Pesquisar.</div>';
  });
  cmdSellerModalSearch?.addEventListener('keydown', e=>{
    if(e.key==='ArrowDown' || e.key==='ArrowUp'){
      e.preventDefault(); e.stopPropagation();
      if(!cmdSellerFiltered.length){ cmdSearchSellers(); return; }
      const delta=e.key==='ArrowDown'?1:-1;
      cmdSellerActiveIndex=Math.max(0,Math.min(cmdSellerFiltered.length-1,(cmdSellerActiveIndex<0?0:cmdSellerActiveIndex)+delta));
      cmdRenderSellerRows(); return;
    }
    if(e.key==='Enter'){
      e.preventDefault(); e.stopPropagation();
      if(cmdSellerFiltered.length && cmdSellerActiveIndex>=0){ const s=cmdSellerFiltered[cmdSellerActiveIndex]; if(s) cmdSelectSeller(s); } else cmdSearchSellers();
      return;
    }
    if(e.key==='Escape'){ e.preventDefault(); e.stopPropagation(); closeModal('cmdSellerModal'); }
  });

  let cmdAdjustmentSyncLock=false;
  function cmdSyncAdjustmentFromPercent(kind){
    if(cmdAdjustmentSyncLock) return;
    const percentEl=document.getElementById(kind==='discount'?'cmdDiscountPercent':'cmdAdditionPercent');
    const amountEl=document.getElementById(kind==='discount'?'cmdDiscountAmount':'cmdAdditionAmount');
    if(!percentEl || !amountEl) return;
    cmdAdjustmentSyncLock=true;
    const pct=Math.max(0,parseMoney(percentEl.value||'0'));
    const amount=Math.max(0,Number(comandaItemsSubtotal||0)*pct/100);
    amountEl.value=fmtInput(amount);
    cmdAdjustmentSyncLock=false;
    cmdRecalculateOrderTotal();
  }
  function cmdSyncAdjustmentFromAmount(kind){
    if(cmdAdjustmentSyncLock) return;
    const percentEl=document.getElementById(kind==='discount'?'cmdDiscountPercent':'cmdAdditionPercent');
    const amountEl=document.getElementById(kind==='discount'?'cmdDiscountAmount':'cmdAdditionAmount');
    if(!percentEl || !amountEl) return;
    cmdAdjustmentSyncLock=true;
    const base=Math.max(0,Number(comandaItemsSubtotal||0));
    const amount=Math.max(0,parseMoney(amountEl.value||'0'));
    percentEl.value=fmtInput(base>0 ? amount*100/base : 0);
    cmdAdjustmentSyncLock=false;
    cmdRecalculateOrderTotal();
  }
  document.getElementById('cmdAdditionPercent')?.addEventListener('input',()=>cmdSyncAdjustmentFromPercent('addition'));
  document.getElementById('cmdDiscountPercent')?.addEventListener('input',()=>cmdSyncAdjustmentFromPercent('discount'));

  function cmdRecalculateOrderTotal(){
    const discount = Math.max(0, parseMoney(document.getElementById('cmdDiscountAmount')?.value || '0'));
    const addition = Math.max(0, parseMoney(document.getElementById('cmdAdditionAmount')?.value || '0'));
    const delivery = Math.max(0, parseMoney(document.getElementById('cmdDeliveryFee')?.value || '0'));
    comandaPaymentTotal = Math.max(0, Number(comandaItemsSubtotal || 0) - discount + addition + delivery);
    ['cmdAdjustedTotalView','cmdPaymentOrderTotalView','cmdPaymentFinalTotalView'].forEach(id => { const el=document.getElementById(id); if(el) el.textContent=money(comandaPaymentTotal); });
    const paymentLinesNow = Array.from(document.querySelectorAll('#cmdPaymentLines .cmd-payment-line'));
    if(paymentLinesNow.length === 1){
      const singleAuto = paymentLinesNow[0].querySelector('.pay-amount[data-auto-sync="1"]');
      if(singleAuto) singleAuto.value = fmtInput(comandaPaymentTotal);
    }
    cmdUpdatePayments();
  }

  function cmdPaymentRemaining(){
    let sum = 0;
    document.querySelectorAll('#cmdPaymentLines .pay-amount').forEach(i => sum += parseMoney(i.value));
    return Math.max(0, Number(comandaPaymentTotal || 0) - sum);
  }
  function cmdSetActivePaymentLine(line){
    document.querySelectorAll('#cmdPaymentLines .cmd-payment-line.is-active').forEach(item => item.classList.remove('is-active'));
    cmdActivePaymentLine = line && document.body.contains(line) ? line : null;
    if(cmdActivePaymentLine) cmdActivePaymentLine.classList.add('is-active');
    return cmdActivePaymentLine;
  }
  function cmdCurrentPaymentLine(){
    const focused = document.activeElement?.closest?.('#cmdPaymentLines .cmd-payment-line');
    if(focused) return cmdSetActivePaymentLine(focused);
    if(cmdActivePaymentLine && document.body.contains(cmdActivePaymentLine)) return cmdActivePaymentLine;
    const lines = Array.from(document.querySelectorAll('#cmdPaymentLines .cmd-payment-line'));
    return lines.length ? cmdSetActivePaymentLine(lines[lines.length - 1]) : null;
  }
  function cmdFocusPaymentAmount(line){
    if(!line) return;
    cmdSetActivePaymentLine(line);
    const amount = line.querySelector('.pay-amount');
    if(amount) setTimeout(() => { amount.focus(); amount.select?.(); }, 0);
  }
  function cmdAddPaymentLine(amount, autoSync=false, focusMethod=false){
    const box = document.getElementById('cmdPaymentLines');
    if(!box) return null;
    const paymentValue = amount ?? cmdPaymentRemaining();
    const div = document.createElement('div');
    div.className = 'cmd-payment-line';
    const key = 'c' + (++cmdPaymentLineSeq);
    div.innerHTML = `<input type="hidden" name="payment_line_key[]" class="pay-line-key" value="${key}"><label>Forma<select name="payment_method_id[]" class="pay-method" required><option value="">Selecione pelo número</option>${paymentOptionsHtml}</select></label><label class="pay-value">Valor<input name="payment_amount[]" class="pay-amount" inputmode="decimal" value="${fmtInput(paymentValue)}" data-auto-sync="${autoSync ? '1':'0'}"></label><label class="pay-received-label">Recebido<input name="payment_received[]" class="pay-received" inputmode="decimal" value="${fmtInput(paymentValue)}"></label><button type="button" class="btn pay-remove">×</button><div class="cmd-installments"><div class="cmd-installments-head"><div><strong>Parcelas da conta a receber</strong><small>Edite vencimentos e valores.</small></div><label>Parcelas<input type="number" min="1" max="120" class="installment-count" value="1"></label><button type="button" class="btn pay-installments-generate">Gerar</button></div><div class="cmd-installments-list"><div class="cmd-installment-row head"><span>Parcela</span><span>Vencimento</span><span>Valor</span></div><div class="cmd-installments-rows"></div></div></div>`;
    box.appendChild(div);
    cmdSetActivePaymentLine(div);
    cmdUpdatePayments();
    if(focusMethod) setTimeout(() => div.querySelector('.pay-method')?.focus(), 0);
    return div;
  }
  function cmdSelectPaymentMethod(methodId, line=null){
    line = line || cmdCurrentPaymentLine();
    if(!line) line = cmdAddPaymentLine(undefined, false, false);
    const select = line?.querySelector('.pay-method');
    if(!select || !paymentMap[String(methodId)]) return false;
    select.value = String(methodId);
    select.dispatchEvent(new Event('change', {bubbles:true}));
    cmdFocusPaymentAmount(line);
    return true;
  }
  function cmdRemovePaymentLine(line=null){
    line = line || cmdCurrentPaymentLine();
    if(!line) return;
    const lines = Array.from(document.querySelectorAll('#cmdPaymentLines .cmd-payment-line'));
    const index = lines.indexOf(line);
    line.remove();
    const remaining = Array.from(document.querySelectorAll('#cmdPaymentLines .cmd-payment-line'));
    if(!remaining.length){ cmdAddPaymentLine(comandaPaymentTotal, true, true); }
    else cmdSetActivePaymentLine(remaining[Math.max(0, Math.min(index, remaining.length - 1))]);
    cmdUpdatePayments();
  }
  function cmdChoosePaymentShortcutDigit(digit){
    clearTimeout(cmdPaymentShortcutTimer);
    const attempt = cmdPaymentShortcutBuffer + String(digit);
    const keys = Object.keys(paymentShortcutByKey);
    const exact = paymentShortcutByKey[attempt] || '';
    const hasPrefix = keys.some(key => key.startsWith(attempt));
    const hasLonger = keys.some(key => key.startsWith(attempt) && key !== attempt);
    if(!hasPrefix){
      cmdPaymentShortcutBuffer = String(digit);
      const single = paymentShortcutByKey[cmdPaymentShortcutBuffer] || '';
      if(single) cmdSelectPaymentMethod(single);
      cmdPaymentShortcutBuffer = '';
      return;
    }
    cmdPaymentShortcutBuffer = attempt;
    if(exact && !hasLonger){ cmdSelectPaymentMethod(exact); cmdPaymentShortcutBuffer = ''; return; }
    cmdPaymentShortcutTimer = setTimeout(() => {
      const id = paymentShortcutByKey[cmdPaymentShortcutBuffer] || '';
      if(id) cmdSelectPaymentMethod(id);
      cmdPaymentShortcutBuffer = '';
    }, 520);
  }
  function cmdUpdatePayments(){
    const lines = Array.from(document.querySelectorAll('#cmdPaymentLines .cmd-payment-line'));
    const total = Number(comandaPaymentTotal || 0);
    if(lines.length === 1){
      const amountInput = lines[0].querySelector('.pay-amount');
      if(amountInput && (amountInput.dataset.autoSync === '1' || parseMoney(amountInput.value) <= 0)){
        amountInput.value = fmtInput(total);
        amountInput.dataset.autoSync = '1';
      }
    }
    let sum = 0, change = 0, hasReceivable = false;
    lines.forEach(line => {
      const methodId = line.querySelector('.pay-method')?.value || '';
      const p = paymentMap[methodId] || {};
      cmdRenderInstallments(line, false);
      const amount = parseMoney(line.querySelector('.pay-amount')?.value || '0');
      sum += amount;
      const receivedInp = line.querySelector('.pay-received');
      const receivedLabel = receivedInp?.closest('label');
      const allowChange = Number(p.allowChange || 0) === 1;
      if(receivedLabel) receivedLabel.style.display = allowChange ? '' : 'none';
      line.classList.toggle('show-received', allowChange);
      if(allowChange && receivedInp) change += Math.max(0, parseMoney(receivedInp.value) - amount);
      if(Number(p.receivable || 0) === 1) hasReceivable = true;
    });
    const diff = total - sum;
    const paymentsTotalView = document.getElementById('cmdPaymentsTotalView');
    const diffView = document.getElementById('cmdPaymentsDiffView');
    const changeRow = document.getElementById('cmdChangeRow');
    const changeView = document.getElementById('cmdChangeView');
    const dueDateWrap = document.getElementById('cmdDueDateWrap');
    if(paymentsTotalView) paymentsTotalView.textContent = money(sum);
    if(diffView){
      diffView.textContent = money(diff);
      diffView.style.color = Math.abs(diff) <= 0.01 ? '#047857' : '#b91c1c';
    }
    if(changeRow) changeRow.style.display = change > 0 ? '' : 'none';
    if(changeView) changeView.textContent = money(change);
    if(dueDateWrap) dueDateWrap.style.display = 'none';
  }
  const cmdPaymentLines = document.getElementById('cmdPaymentLines');
  document.getElementById('cmdDiscountAmount')?.addEventListener('input', () => document.getElementById('cmdDiscountPercent') ? cmdSyncAdjustmentFromAmount('discount') : cmdRecalculateOrderTotal());
  document.getElementById('cmdAdditionAmount')?.addEventListener('input', () => document.getElementById('cmdAdditionPercent') ? cmdSyncAdjustmentFromAmount('addition') : cmdRecalculateOrderTotal());
  document.getElementById('cmdDeliveryFee')?.addEventListener('input', cmdRecalculateOrderTotal);
  if(document.getElementById('cmdDiscountPercent')) cmdSyncAdjustmentFromAmount('discount');
  if(document.getElementById('cmdAdditionPercent')) cmdSyncAdjustmentFromAmount('addition');
  document.getElementById('cmdAddPaymentBtn')?.addEventListener('click', () => cmdAddPaymentLine(undefined, false, true));
  document.getElementById('cmdPaymentShortcuts')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-payment-shortcut]');
    if(btn) cmdSelectPaymentMethod(btn.dataset.paymentShortcut || '');
  });
  cmdPaymentLines?.addEventListener('focusin', e => {
    const line = e.target.closest('.cmd-payment-line');
    if(line) cmdSetActivePaymentLine(line);
  });
  cmdPaymentLines?.addEventListener('click', e => {
    const gen = e.target.closest('.pay-installments-generate');
    if(gen){ e.preventDefault(); const line = gen.closest('.cmd-payment-line'); if(line){ cmdRenderInstallments(line, true); cmdUpdatePayments(); } return; }
    const b = e.target.closest('.pay-remove');
    if(b){ e.preventDefault(); cmdRemovePaymentLine(b.closest('.cmd-payment-line')); }
  });
  cmdPaymentLines?.addEventListener('input', e => {
    if(e.target && e.target.classList.contains('pay-amount')){ e.target.dataset.autoSync = '0'; cmdRenderInstallments(e.target.closest('.cmd-payment-line'), true); }
    cmdUpdatePayments();
  });
  cmdPaymentLines?.addEventListener('change', e => { if(e.target && (e.target.classList.contains('pay-method') || e.target.classList.contains('installment-count'))){ const line = e.target.closest('.cmd-payment-line'); if(e.target.classList.contains('pay-method')){ const cnt = line?.querySelector('.installment-count'); if(cnt) cnt.value = ''; if(e.target.value) cmdFocusPaymentAmount(line); } cmdRenderInstallments(line, true); } cmdUpdatePayments(); });
  function cmdCreditSaleAmount(){let total=0;document.querySelectorAll('#cmdPaymentLines .cmd-payment-line').forEach(line=>{const id=Number(line.querySelector('.pay-method')?.value||0);if(id&&paymentMap[id]?.receivable)total+=parseMoney(line.querySelector('.pay-amount')?.value||'0');});return Math.round(total*100)/100;}
  function cmdCreditNeedsAuthorization(){const amount=cmdCreditSaleAmount();if(amount<=0||cmdCustomerCreditLimit===null)return null;const projected=Number(cmdCustomerCreditOpen)+amount;if(projected<=Number(cmdCustomerCreditLimit)+.009)return null;return{limit:Number(cmdCustomerCreditLimit),open:Number(cmdCustomerCreditOpen),amount,available:Math.max(0,Number(cmdCustomerCreditLimit)-Number(cmdCustomerCreditOpen))};}
  function cmdCloseCreditOverride(){const m=document.getElementById('cmdCreditOverrideModal');if(m)m.style.display='none';}
  function cmdShowCreditOverride(info){const m=document.getElementById('cmdCreditOverrideModal');if(!m)return;document.getElementById('cmdCreditOverrideMessage').textContent=`Limite: ${money(info.limit)} · Em aberto: ${money(info.open)} · Disponível: ${money(info.available)} · Nova compra a prazo: ${money(info.amount)}.`;m.style.display='flex';setTimeout(()=>document.getElementById('cmdCreditOverrideCodeInput')?.focus(),60);}
  document.getElementById('cmdCreditOverrideClose')?.addEventListener('click',cmdCloseCreditOverride);document.getElementById('cmdCreditOverrideCancel')?.addEventListener('click',cmdCloseCreditOverride);document.getElementById('cmdCreditOverrideConfirm')?.addEventListener('click',async()=>{const c=document.getElementById('cmdCreditOverrideCodeInput')?.value.trim()||'',p=document.getElementById('cmdCreditOverridePasswordInput')?.value||'';if(!c||!p){alert('Informe o código do usuário e a senha.');return;}const b=document.getElementById('cmdCreditOverrideConfirm');if(b){b.disabled=true;b.textContent='Validando...';}try{const fd=new FormData();fd.append('code',c);fd.append('password',p);const r=await fetch('commercial_credit_authorize.php',{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw new Error(j.error||'Não foi possível validar a autorização.');const c1=document.getElementById('cmdCreditOverrideUserCode'),p1=document.getElementById('cmdCreditOverridePassword');if(c1)c1.value=c;if(p1)p1.value=p;cmdCreditOverrideConfirmed=true;cmdCloseCreditOverride();document.getElementById('cmdPaymentForm')?.requestSubmit();}catch(err){alert(err.message||'Falha ao validar autorização.');}finally{if(b){b.disabled=false;b.textContent='Liberar venda';}}});
  document.getElementById('cmdPaymentForm')?.addEventListener('submit', e => {
    const lines = Array.from(document.querySelectorAll('#cmdPaymentLines .cmd-payment-line'));
    let hasMethod = true, sum = 0, installmentOk = true;
    lines.forEach(line => {
      if(!line.querySelector('.pay-method')?.value) hasMethod = false;
      const lineAmount = parseMoney(line.querySelector('.pay-amount')?.value || '0');
      sum += lineAmount;
      if(line.querySelector('.cmd-installments.show')){
        let parcSum = 0, missingDue = false;
        line.querySelectorAll('.cmd-installments .cmd-installment-row:not(.head)').forEach(tr => { const due = tr.querySelector('.installment-due')?.value || ''; const val = parseMoney(tr.querySelector('.installment-amount')?.value || '0'); if(!due) missingDue = true; parcSum += val; });
        if(missingDue || Math.abs(parcSum - lineAmount) > 0.01) installmentOk = false;
      }
    });
    if(Number(comandaPaymentTotal || 0) <= 0){ e.preventDefault(); alert('O total a pagar precisa ser maior que zero. Confira desconto, acréscimo e frete.'); return; }
    if(!lines.length || !hasMethod){ e.preventDefault(); alert('Selecione todas as formas de pagamento.'); return; }
    if(!installmentOk){ e.preventDefault(); alert('Confira as parcelas: vencimentos obrigatórios e soma dos valores igual ao valor da forma de pagamento.'); return; }
    if(Math.abs(Number(comandaPaymentTotal || 0) - sum) > 0.01){ e.preventDefault(); alert('A soma das formas de pagamento precisa fechar o total da comanda.'); return; }
    if(cmdCommercialMaxDiscountPercent !== null){const base=Number(comandaItemsSubtotal||0);const disc=parseMoney(document.getElementById('cmdDiscountAmount')?.value||'0');const max=base*Number(cmdCommercialMaxDiscountPercent)/100;if(disc>max+.009){e.preventDefault();alert(`Desconto acima do limite do usuário (${Number(cmdCommercialMaxDiscountPercent).toFixed(2).replace('.',',')}%). Máximo: ${money(max)}.`);return;}}
    const cinfo=cmdCreditNeedsAuthorization();if(cinfo&&!cmdCreditOverrideConfirmed){e.preventDefault();cmdShowCreditOverride(cinfo);return;}
    if(cmdFiscalEmissionEnabled && !cmdFiscalChoiceConfirmed){ e.preventDefault(); const m=document.getElementById('cmdFiscalModal'); if(m) m.style.display='flex'; return; }
    if(!cmdFiscalEmissionEnabled && !confirm('Fechar esta comanda e gerar o pedido/venda?')) e.preventDefault();
  });
  function cmdFinishFiscalChoice(choice){
    const form = document.getElementById('cmdPaymentForm');
    const inp = document.getElementById('cmdEmitDocAfter');
    if(inp) inp.value = ['nfce','nfe','nfse'].includes(choice) ? choice : 'none';
    cmdFiscalChoiceConfirmed = true;
    const m = document.getElementById('cmdFiscalModal');
    if(m) m.style.display='none';
    if(form){ if(form.requestSubmit) form.requestSubmit(); else form.submit(); }
  }
  document.querySelectorAll('[data-cmd-fiscal]').forEach(btn => btn.addEventListener('click', () => {
    const v = btn.dataset.cmdFiscal || '';
    if(v === 'close'){ const m=document.getElementById('cmdFiscalModal'); if(m) m.style.display='none'; return; }
    cmdFinishFiscalChoice(v);
  }));
  document.getElementById('cmdFiscalModal')?.addEventListener('click', e => { if(e.target.id === 'cmdFiscalModal') e.currentTarget.style.display='none'; });
  if(cmdPaymentLines){ cmdAddPaymentLine(comandaPaymentTotal, true, false); cmdRecalculateOrderTotal(); }

  const cmdMergeModal = document.getElementById('cmdMergeModal');
  const cmdMergeForm = document.getElementById('cmdMergeForm');
  const cmdMergeDestination = document.getElementById('cmdMergeDestination');
  const cmdMergeSourceReader = document.getElementById('cmdMergeSourceReader');
  const cmdMergeSourcesValue = document.getElementById('cmdMergeSourcesValue');
  const cmdMergeSourceList = document.getElementById('cmdMergeSourceList');
  const cmdMergeStatus = document.getElementById('cmdMergeStatus');
  const cmdMergeDestinationStep = document.getElementById('cmdMergeDestinationStep');
  const cmdMergeSourceStep = document.getElementById('cmdMergeSourceStep');
  let cmdMergeSources = [];
  function cmdNormalizeComandaCode(value){ return String(value || '').replace(/[\r\n\t]+/g, '').trim(); }
  function cmdSetMergeStatus(message='', type=''){
    if(!cmdMergeStatus) return;
    cmdMergeStatus.textContent = message;
    cmdMergeStatus.className = 'cmd-merge-status' + (type ? ' ' + type : '');
  }
  function cmdRenderMergeSources(){
    if(cmdMergeSourcesValue) cmdMergeSourcesValue.value = cmdMergeSources.join('\n');
    if(!cmdMergeSourceList) return;
    if(!cmdMergeSources.length){ cmdMergeSourceList.innerHTML = '<span class="cmd-merge-empty">Nenhuma comanda lida.</span>'; return; }
    cmdMergeSourceList.innerHTML = cmdMergeSources.map((code, index) => `<span class="cmd-merge-chip">${escapeHtml(code)}<button type="button" data-remove-merge="${index}" aria-label="Remover ${escapeHtml(code)}">×</button></span>`).join('');
  }
  function cmdResetMerge(){
    cmdMergeSources = [];
    if(cmdMergeDestination) cmdMergeDestination.value = '';
    if(cmdMergeSourceReader){ cmdMergeSourceReader.value = ''; cmdMergeSourceReader.disabled = true; }
    cmdMergeDestinationStep?.classList.add('active'); cmdMergeDestinationStep?.classList.remove('done');
    cmdMergeSourceStep?.classList.remove('active','done');
    cmdSetMergeStatus('Leia a comanda principal para começar.');
    cmdRenderMergeSources();
  }
  function cmdOpenMerge(){
    if(!cmdMergeModal) return;
    cmdResetMerge();
    cmdMergeModal.style.display = 'flex';
    setTimeout(() => { cmdMergeDestination?.focus(); cmdMergeDestination?.select?.(); }, 60);
  }
  function cmdCloseMerge(){ if(cmdMergeModal) cmdMergeModal.style.display='none'; cmdResetMerge(); }
  function cmdAcceptMergeDestination(){
    const code = cmdNormalizeComandaCode(cmdMergeDestination?.value);
    if(!code){ cmdSetMergeStatus('Leia ou digite a comanda principal.', 'error'); cmdMergeDestination?.focus(); return false; }
    if(cmdMergeDestination) cmdMergeDestination.value = code;
    if(cmdMergeSourceReader) cmdMergeSourceReader.disabled = false;
    cmdMergeDestinationStep?.classList.remove('active'); cmdMergeDestinationStep?.classList.add('done');
    cmdMergeSourceStep?.classList.add('active');
    cmdSetMergeStatus(`Principal ${code} definida. Leia agora as comandas de origem.`, 'ok');
    setTimeout(() => { cmdMergeSourceReader?.focus(); cmdMergeSourceReader?.select?.(); }, 20);
    return true;
  }
  function cmdAddMergeSource(){
    if(!cmdAcceptMergeDestination()) return false;
    const code = cmdNormalizeComandaCode(cmdMergeSourceReader?.value);
    if(!code){ cmdMergeSourceReader?.focus(); return false; }
    const destination = cmdNormalizeComandaCode(cmdMergeDestination?.value);
    if(code === destination){ cmdSetMergeStatus(`A comanda ${code} já é a principal e não pode ser origem.`, 'error'); if(cmdMergeSourceReader) cmdMergeSourceReader.value=''; cmdMergeSourceReader?.focus(); return false; }
    if(cmdMergeSources.includes(code)){ cmdSetMergeStatus(`A comanda ${code} já foi adicionada.`, 'error'); if(cmdMergeSourceReader) cmdMergeSourceReader.value=''; cmdMergeSourceReader?.focus(); return false; }
    cmdMergeSources.push(code);
    if(cmdMergeSourceReader) cmdMergeSourceReader.value='';
    cmdMergeSourceStep?.classList.add('done');
    cmdSetMergeStatus(`Comanda ${code} adicionada. Leia a próxima ou pressione F9 para concluir.`, 'ok');
    cmdRenderMergeSources();
    setTimeout(() => cmdMergeSourceReader?.focus(), 10);
    return true;
  }
  document.getElementById('cmdOpenMergeModal')?.addEventListener('click', cmdOpenMerge);
  document.querySelectorAll('[data-open-merge]').forEach(btn => btn.addEventListener('click', cmdOpenMerge));
  document.getElementById('cmdMergeClose')?.addEventListener('click', cmdCloseMerge);
  document.getElementById('cmdMergeCancel')?.addEventListener('click', cmdCloseMerge);
  document.getElementById('cmdMergeClear')?.addEventListener('click', () => { cmdMergeSources=[]; cmdRenderMergeSources(); cmdSetMergeStatus('Lista limpa. Leia novamente as comandas de origem.'); cmdMergeSourceReader?.focus(); });
  cmdMergeDestination?.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === 'Tab'){ e.preventDefault(); cmdAcceptMergeDestination(); } });
  cmdMergeSourceReader?.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === 'Tab'){ e.preventDefault(); cmdAddMergeSource(); } });
  cmdMergeSourceList?.addEventListener('click', e => { const btn=e.target.closest('[data-remove-merge]'); if(!btn) return; const index=Number(btn.dataset.removeMerge); if(Number.isInteger(index) && index >= 0){ cmdMergeSources.splice(index,1); cmdRenderMergeSources(); cmdMergeSourceReader?.focus(); } });
  cmdMergeForm?.addEventListener('submit', e => {
    if(!cmdAcceptMergeDestination()){ e.preventDefault(); return; }
    const pending = cmdNormalizeComandaCode(cmdMergeSourceReader?.value);
    if(pending) cmdAddMergeSource();
    if(!cmdMergeSources.length){ e.preventDefault(); cmdSetMergeStatus('Leia pelo menos uma comanda de origem.', 'error'); cmdMergeSourceReader?.focus(); return; }
    if(!confirm(`Unir ${cmdMergeSources.length} comanda(s) na principal ${cmdNormalizeComandaCode(cmdMergeDestination?.value)}?`)){ e.preventDefault(); cmdMergeSourceReader?.focus(); return; }
    cmdSetMergeStatus('Processando união das comandas...', 'ok');
  });
  cmdMergeModal?.addEventListener('click', e => { if(e.target === cmdMergeModal) cmdCloseMerge(); });

  function cmdVisibleModal(){
    return ['cmdMergeModal','cmdFiscalModal','cmdProductSearchModal','cmdOptionModal','cmdCartReviewModal','cmdFirstNameModal','cmdCustomerModal','cmdCustomerQuickModal','cmdSellerModal'].some(id => { const el=document.getElementById(id); return !!(el && getComputedStyle(el).display !== 'none'); });
  }
  function cmdNavigateShortcut(stepName){
    if(!cmdSelectedId || !cmdShortcutUrls[stepName]) return;
    const productFormNow = document.getElementById('cmdProductsForm');
    if(cmdCurrentStep === 'produtos' && productFormNow && typeof cart !== 'undefined' && cart.size > 0){
      const next = document.getElementById('cmdAfterProcessStep');
      if(next) next.value = stepName;
      if(productFormNow.requestSubmit) productFormNow.requestSubmit(); else productFormNow.submit();
      return;
    }
    window.location.href = cmdShortcutUrls[stepName];
  }
  document.addEventListener('keydown', e => {
    const mergeOpen = !!(cmdMergeModal && getComputedStyle(cmdMergeModal).display !== 'none');
    if(mergeOpen){
      if(e.key === 'F9'){ e.preventDefault(); if(cmdMergeForm?.requestSubmit) cmdMergeForm.requestSubmit(); else cmdMergeForm?.submit(); return; }
      if(e.key === 'Escape'){ e.preventDefault(); cmdCloseMerge(); return; }
      return;
    }
    const fiscalOpen = !!(document.getElementById('cmdFiscalModal') && getComputedStyle(document.getElementById('cmdFiscalModal')).display !== 'none');
    if(fiscalOpen){
      if(e.key === '1' && document.querySelector('[data-cmd-fiscal="nfce"]')){ e.preventDefault(); cmdFinishFiscalChoice('nfce'); return; }
      if(e.key === '2' && document.querySelector('[data-cmd-fiscal="nfe"]')){ e.preventDefault(); cmdFinishFiscalChoice('nfe'); return; }
      if(e.key === '3' && document.querySelector('[data-cmd-fiscal="nfse"]')){ e.preventDefault(); cmdFinishFiscalChoice('nfse'); return; }
      if(e.key === '4' && document.querySelector('[data-cmd-fiscal="none"]')){ e.preventDefault(); cmdFinishFiscalChoice('none'); return; }
      if(e.key === 'Escape'){ e.preventDefault(); const m=document.getElementById('cmdFiscalModal'); if(m) m.style.display='none'; return; }
      return;
    }
    if(cmdCurrentStep === 'pagamento' && cmdPaymentLines){
      const target = e.target;
      const isValueEntry = !!target?.matches?.('input, textarea, [contenteditable="true"]');
      if(e.key === '+' || e.code === 'NumpadAdd'){ e.preventDefault(); cmdAddPaymentLine(undefined, false, true); return; }
      if(e.key === '-' || e.code === 'NumpadSubtract'){ e.preventDefault(); cmdRemovePaymentLine(); return; }
      if(/^\d$/.test(e.key) && !isValueEntry){ e.preventDefault(); cmdChoosePaymentShortcutDigit(e.key); return; }
      if(e.key === 'F10'){ e.preventDefault(); const form=document.getElementById('cmdPaymentForm'); if(form?.requestSubmit) form.requestSubmit(); else form?.submit(); return; }
    }
    if(e.key === 'F8' && cmdCurrentStep === 'pagamento' && cmdComandaCheckoutMode && !cmdComandaMobileMode){
      e.preventDefault();
      if(!cmdAllowSellerChange) return;
      const sellerModal = document.getElementById('cmdSellerModal');
      if(sellerModal && getComputedStyle(sellerModal).display !== 'none'){ document.getElementById('cmdSellerModalSearch')?.focus(); return; }
      if(!cmdVisibleModal()) openCmdSellerModal();
      return;
    }
    if(e.key === 'F8' && cmdCurrentStep === 'produtos' && !cmdComandaMobileMode){
      e.preventDefault();
      const productSearchModal = document.getElementById('cmdProductSearchModal');
      if(productSearchModal && getComputedStyle(productSearchModal).display !== 'none'){
        document.getElementById('cmdProductModalSearch')?.focus();
        return;
      }
      if(!cmdVisibleModal()) openCmdProductSearchModal();
      return;
    }
    if(e.key === 'F9'){
      const firstNameModal = document.getElementById('cmdFirstNameModal');
      if(firstNameModal && getComputedStyle(firstNameModal).display !== 'none'){ e.preventDefault(); const form=document.getElementById('cmdFirstNameForm'); if(form?.requestSubmit) form.requestSubmit(); else form?.submit(); return; }
      const reviewModal = document.getElementById('cmdCartReviewModal');
      if(reviewModal && getComputedStyle(reviewModal).display !== 'none'){ e.preventDefault(); document.getElementById('cmdCartReviewConfirm')?.click(); return; }
      if(cmdVisibleModal()) return;
      if(cmdCurrentStep === 'produtos'){
        e.preventDefault(); const after=document.getElementById('cmdAfterProcessStep'); if(after) after.value=''; const form=document.getElementById('cmdProductsForm'); if(form?.requestSubmit) form.requestSubmit(); else form?.submit(); return;
      }
      if(cmdCurrentStep === 'identificacao'){
        e.preventDefault(); const form=document.getElementById('cmdCustomerForm'); if(form?.requestSubmit) form.requestSubmit(); else form?.submit(); return;
      }
    }
    if(cmdVisibleModal()) return;
    if(e.key === 'F7'){
      const mergeButton = document.querySelector('[data-open-merge]');
      if(mergeButton){ e.preventDefault(); mergeButton.click(); return; }
    }
    if(e.key === 'F2'){ e.preventDefault(); window.location.href = <?= json_encode($comandaHomeUrl) ?>; return; }
    if(e.key === 'F3'){ e.preventDefault(); cmdNavigateShortcut('abrir'); return; }
    if(e.key === 'F4'){
      e.preventDefault();
      if(cmdCurrentStep === 'produtos'){ document.getElementById('cmdBillComandaBtn')?.click(); return; }
      cmdNavigateShortcut('identificacao');
      return;
    }
    if(e.key === 'F5'){ e.preventDefault(); cmdNavigateShortcut('pagamento'); return; }
    if(e.key === 'F6' && cmdComandaCheckoutMode && cmdCurrentStep === 'produtos'){ e.preventDefault(); document.getElementById('cmdAccountConferenceBtn')?.click(); return; }
  });

  const cmdAfterUpdateStep = document.getElementById('cmdAfterUpdateStep');
  document.getElementById('cmdCustomerGoPaymentBtn')?.addEventListener('click', () => {
    if(cmdAfterUpdateStep) cmdAfterUpdateStep.value = 'pagamento';
  });
  document.getElementById('cmdCustomerProcessBtn')?.addEventListener('click', () => {
    if(cmdAfterUpdateStep && !<?= $checkoutFlow ? 'true' : 'false' ?>) cmdAfterUpdateStep.value = '';
  });

  const clienteId = document.getElementById('cmdClienteId');
  const selectedName = document.getElementById('cmdSelectedCustomerName');
  const selectedDoc = document.getElementById('cmdSelectedCustomerDoc');
  const modal = document.getElementById('cmdCustomerModal');
  const modalSearch = document.getElementById('cmdCustomerModalSearch');
  const modalList = document.getElementById('cmdCustomerModalList');
  function customerSubtitle(c){ return c ? (c.document || c.phone || c.whatsapp || 'Cliente cadastrado') : 'Nenhum cliente cadastrado selecionado'; }
  function setCustomer(c){
    selectedCustomerId = c ? Number(c.id || 0) : 0;
    if(clienteId) clienteId.value = c ? String(c.id) : '0';
    if(selectedName) selectedName.textContent = c ? c.name : 'Consumidor final';
    if(selectedDoc) selectedDoc.textContent = customerSubtitle(c);
    const checkoutCustomerName = document.getElementById('cmdCheckoutCustomerName');
    if(checkoutCustomerName) checkoutCustomerName.textContent = c ? c.name : 'Consumidor final';
  }
  async function saveCustomerToComanda(c){
    if(!(cmdComandaCheckoutMode && cmdCurrentStep === 'produtos' && cmdSelectedId > 0)){ setCustomer(c); return true; }
    const fd = new FormData();
    fd.append('action','set_customer_comanda');
    fd.append('comanda_id',String(cmdSelectedId));
    fd.append('cliente_id',c ? String(c.id) : '0');
    if(cmdMcCsrf) fd.append('mc_csrf', cmdMcCsrf);
    try{
      const r = await fetch(location.href, {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
      const data = await r.json().catch(()=>({}));
      if(!r.ok || !data.ok) throw new Error(data.error || 'Não foi possível vincular o cliente à comanda.');
      setCustomer(c);
      cmdShowToast(c ? 'Cliente vinculado à comanda.' : 'Comanda definida como consumidor final.');
      return true;
    }catch(err){ alert(err.message || err); return false; }
  }
  function prepareCustomerQuickModal(){
    const form = document.getElementById('cmdCustomerQuickForm');
    form?.reset();
    const raw = modalSearch?.value || '';
    const digits = String(raw).replace(/\D/g,'');
    const type = digits.length > 11 ? 'JURIDICA' : 'FISICA';
    const typeEl = document.getElementById('cmdQuickPersonType'); if(typeEl) typeEl.value = type;
    const label = document.getElementById('cmdQuickDocumentLabel'); if(label && label.childNodes[0]) label.childNodes[0].nodeValue = type === 'JURIDICA' ? 'CNPJ' : 'CPF';
    const ieStatus = document.getElementById('cmdQuickIeStatus'); if(ieStatus) ieStatus.value = type === 'JURIDICA' ? 'AUTO' : 'NAO_CONTRIBUINTE';
    syncCmdQuickIeFields();
    if(digits.length >= 11){ const d=document.getElementById('cmdQuickDocument'); if(d) d.value=digits; }
    else if(digits){ const w=document.getElementById('cmdQuickWhatsapp'); if(w) w.value=digits; }
    else if(raw.trim()){ const n=document.getElementById('cmdQuickName'); if(n) n.value=raw.trim(); }
    closeModal('cmdCustomerModal');
    openModal('cmdCustomerQuickModal');
    setTimeout(()=>document.getElementById('cmdQuickName')?.focus(), 70);
  }
  function syncCmdQuickIeFields(){
    const status=document.getElementById('cmdQuickIeStatus'), ie=document.getElementById('cmdQuickStateRegistration');
    if(!status || !ie) return;
    if(status.value === 'ISENTO'){ ie.value='ISENTO'; ie.disabled=true; }
    else if(status.value === 'NAO_CONTRIBUINTE'){ ie.value=''; ie.disabled=true; }
    else { ie.disabled=false; if(ie.value.toUpperCase()==='ISENTO') ie.value=''; }
  }
  async function cmdQuickFillCnpj(){
    const doc=String(document.getElementById('cmdQuickDocument')?.value||'').replace(/\D/g,''); if(doc.length!==14) return;
    try{
      const r=await fetch('pessoa_cnpj_lookup.php?cnpj='+encodeURIComponent(doc), {headers:{'Accept':'application/json'}});
      const payload=await r.json(); if(!r.ok || !payload.ok) return; const d=payload.data||{};
      const vals={cmdQuickName:d.razao_social||'',cmdQuickFantasy:d.nome_fantasia||'',cmdQuickEmail:d.email||'',cmdQuickPhone:String(d.telefone||'').replace(/\D/g,''),cmdQuickZip:String(d.cep||'').replace(/\D/g,''),cmdQuickAddress:d.logradouro||'',cmdQuickDistrict:d.bairro||'',cmdQuickCity:d.municipio||'',cmdQuickState:d.uf||'',cmdQuickMunicipalRegistration:d.municipal_registration||''};
      Object.entries(vals).forEach(([id,v])=>{const el=document.getElementById(id);if(el&&v)el.value=v;});
      const ie=String(d.state_registration||'').trim(); if(ie){const el=document.getElementById('cmdQuickStateRegistration');if(el)el.value=ie;const st=document.getElementById('cmdQuickIeStatus');if(st)st.value='CONTRIBUINTE';}
      syncCmdQuickIeFields();
    }catch(e){}
  }
  async function cmdQuickFillCep(){
    const zip = String(document.getElementById('cmdQuickZip')?.value || '').replace(/\D/g,'');
    if(zip.length !== 8) return;
    try{
      const r = await fetch('https://viacep.com.br/ws/'+zip+'/json/');
      const d = await r.json(); if(d.erro) return;
      const vals = {cmdQuickAddress:d.logradouro||'', cmdQuickDistrict:d.bairro||'', cmdQuickCity:d.localidade||'', cmdQuickState:d.uf||''};
      Object.entries(vals).forEach(([id,v])=>{ const el=document.getElementById(id); if(el && !el.value) el.value=v; });
    }catch(e){}
  }
  function searchCustomers(){
    if(!modalList) return;
    const qRaw = modalSearch?.value || '';
    const q = norm(qRaw);
    const digits = String(qRaw).replace(/\D/g,'');
    if(!q && !digits){ modalList.innerHTML = '<div class="cmd-empty">Informe um termo e clique em Pesquisar.</div>'; return; }
    const rows = customers.filter(c => norm(c.name).includes(q) || (digits && (String(c.document||'').includes(digits) || String(c.phone||'').includes(digits) || String(c.whatsapp||'').includes(digits)))).slice(0,80);
    modalList.innerHTML = rows.map(c => `<button type="button" class="cmd-customer-option ${Number(clienteId?.value || selectedCustomerId || 0)===Number(c.id)?'active':''}" data-customer="${Number(c.id)}"><strong>${escapeHtml(c.name)}</strong><br><span class="cmd-muted">${escapeHtml(customerSubtitle(c))}</span></button>`).join('') || '<div class="cmd-empty">Nenhum cliente localizado.</div>';
  }
  document.getElementById('cmdOpenCustomerModal')?.addEventListener('click', () => { openModal('cmdCustomerModal'); setTimeout(()=>modalSearch?.focus(), 60); });
  document.getElementById('cmdOpenCustomerQuickModal')?.addEventListener('click', prepareCustomerQuickModal);
  document.getElementById('cmdCustomerModalNew')?.addEventListener('click', prepareCustomerQuickModal);
  document.getElementById('cmdCustomerModalClose')?.addEventListener('click', () => closeModal('cmdCustomerModal'));
  document.getElementById('cmdCustomerQuickClose')?.addEventListener('click', () => closeModal('cmdCustomerQuickModal'));
  document.getElementById('cmdCustomerQuickCancel')?.addEventListener('click', () => closeModal('cmdCustomerQuickModal'));
  document.getElementById('cmdQuickPersonType')?.addEventListener('change', e => {
    const l=document.getElementById('cmdQuickDocumentLabel'); if(l && l.childNodes[0]) l.childNodes[0].nodeValue = e.target.value === 'JURIDICA' ? 'CNPJ' : 'CPF';
    const st=document.getElementById('cmdQuickIeStatus'), ie=document.getElementById('cmdQuickStateRegistration');
    if(st && ie && !ie.value){st.value=e.target.value === 'JURIDICA' ? 'AUTO' : 'NAO_CONTRIBUINTE';syncCmdQuickIeFields();}
  });
  document.getElementById('cmdQuickIeStatus')?.addEventListener('change', syncCmdQuickIeFields);
  document.getElementById('cmdQuickDocument')?.addEventListener('blur', cmdQuickFillCnpj);
  document.getElementById('cmdQuickZip')?.addEventListener('blur', cmdQuickFillCep);
  document.getElementById('cmdCustomerQuickForm')?.addEventListener('submit', async function(e){
    e.preventDefault();
    const submit = this.querySelector('button[type="submit"]');
    if(submit){ submit.disabled = true; submit.setAttribute('aria-busy', 'true'); }
    cmdShowProcessing(cmdProcessingText(submit, 'Salvando cliente...'));
    try{
      const r = await fetch(location.href, {method:'POST', body:new FormData(this)});
      const data = await r.json();
      if(!data.ok) throw new Error(data.error || 'Não foi possível cadastrar o cliente.');
      const c = data.customer || {};
      const idx = customers.findIndex(x => Number(x.id) === Number(c.id));
      if(idx >= 0) customers[idx] = c; else customers.push(c);
      const saved = await saveCustomerToComanda(c);
      if(saved) closeModal('cmdCustomerQuickModal');
    }catch(err){ alert(err.message || err); }
    finally{
      cmdHideProcessing();
      if(submit){ submit.disabled = false; submit.removeAttribute('aria-busy'); }
    }
  });
  document.getElementById('cmdCustomerModalBtn')?.addEventListener('click', searchCustomers);
  document.getElementById('cmdCustomerModalFinal')?.addEventListener('click', async () => { if(await saveCustomerToComanda(null)) closeModal('cmdCustomerModal'); });
  modalSearch?.addEventListener('keydown', e => { if(e.key === 'Enter'){ e.preventDefault(); searchCustomers(); }});
  modalList?.addEventListener('click', async e => { const btn=e.target.closest('[data-customer]'); if(!btn) return; const c = customers.find(x => Number(x.id) === Number(btn.dataset.customer)); if(c && await saveCustomerToComanda(c)) closeModal('cmdCustomerModal'); });
  modal?.addEventListener('click', e => { if(e.target.id === 'cmdCustomerModal') closeModal('cmdCustomerModal'); });
  document.getElementById('cmdCustomerQuickModal')?.addEventListener('click', e => { if(e.target.id === 'cmdCustomerQuickModal') closeModal('cmdCustomerQuickModal'); });
  if(selectedCustomerId > 0){ const c = customers.find(x => Number(x.id) === selectedCustomerId); if(c) setCustomer(c); }

  /*
   * Exibe o processamento somente depois que as validações e confirmações permitirem a ação.
   * O envio real é adiado por alguns milissegundos para o navegador conseguir desenhar o
   * overlay e a animação antes de iniciar a navegação/requisição.
   */
  document.addEventListener('submit', e => {
    if(!cmdMobileMode || e.defaultPrevented) return;
    const form = e.target;
    if(!(form instanceof HTMLFormElement)) return;
    if(form.dataset.processingSubmitting === '1') return;

    const submitter = e.submitter || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    const formProcessingText = String(form.dataset.processingText || '').trim();

    e.preventDefault();
    form.dataset.processingSubmitting = '1';

    /* form.submit() não envia automaticamente o name/value do botão clicado. */
    if((submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) && submitter.name){
      const submitterValue = document.createElement('input');
      submitterValue.type = 'hidden';
      submitterValue.name = submitter.name;
      submitterValue.value = submitter.value || '';
      submitterValue.dataset.processingSubmitterValue = '1';
      form.appendChild(submitterValue);
    }

    cmdShowProcessing(formProcessingText || cmdProcessingText(submitter));
    if(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement){
      submitter.disabled = true;
      submitter.setAttribute('aria-busy', 'true');
    }

    /* Força o cálculo visual e aguarda um quadro para tornar a roda perceptível. */
    const overlay = document.getElementById('cmdProcessingOverlay');
    if(overlay) void overlay.offsetWidth;
    window.requestAnimationFrame(() => {
      window.setTimeout(() => {
        try{
          HTMLFormElement.prototype.submit.call(form);
        }catch(err){
          form.dataset.processingSubmitting = '0';
          form.querySelectorAll('[data-processing-submitter-value="1"]').forEach(el => el.remove());
          if(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement){
            submitter.disabled = false;
            submitter.removeAttribute('aria-busy');
          }
          cmdHideProcessing();
          alert('Não foi possível processar a solicitação. Tente novamente.');
        }
      }, 90);
    });
  });
  document.addEventListener('click', e => {
    if(!cmdMobileMode || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const link = e.target.closest('a[href]');
    if(!link || link.hasAttribute('download') || link.dataset.noProcessing === '1') return;
    const target = String(link.getAttribute('target') || '').toLowerCase();
    if(target && target !== '_self') return;
    const rawHref = String(link.getAttribute('href') || '').trim();
    if(!rawHref || rawHref === '#' || rawHref.startsWith('#') || /^(javascript:|mailto:|tel:)/i.test(rawHref)) return;
    cmdShowProcessing(cmdProcessingText(link, 'Carregando...'));
  });
  window.addEventListener('pageshow', cmdHideProcessing);
})();
</script>

<script>
(function(){
  function elapsedClock(totalSeconds){
    var seconds=Math.max(0,Math.floor(Number(totalSeconds)||0));
    var minutes=Math.floor(seconds/60);
    var hours=Math.floor(minutes/60);
    var restMinutes=minutes%60;
    return String(hours).padStart(2,'0') + ':' + String(restMinutes).padStart(2,'0');
  }
  function updateCommandaTimes(){
    var now=Math.floor(Date.now()/1000);
    document.querySelectorAll('.cmd-tile[data-occupied="1"]').forEach(function(tile){
      var opened=Number(tile.dataset.openedTs||0);
      var lastActivity=Number(tile.dataset.lastActivityTs||opened||0);
      var limit=Number(tile.dataset.inactiveLimit||0);
      var openLabel=tile.querySelector('.cmd-open-time');
      var inactive=limit>0&&lastActivity>0&&(now-lastActivity)>=limit*60;
      if(openLabel&&opened>0){
        openLabel.textContent=elapsedClock(now-opened);
      }
      tile.classList.toggle('inativa',inactive);
      var status=tile.querySelector('[data-status-label]');
      if(status)status.textContent=inactive?'INATIVA':'OCUPADA';
    });
  }
  updateCommandaTimes();
  window.setInterval(updateCommandaTimes,30000);
})();
</script>
<?php if ($selectedId > 0 && $step === 'pagamento'):
  $pixSalesWidget = [
    'form_id' => 'cmdPaymentForm',
    'line_selector' => '.cmd-payment-line',
    'method_selector' => '.pay-method',
    'amount_selector' => '.pay-amount',
    'payments' => $payments,
    'self_url' => $comandaContextUrl($comandaBaseUrl . '?id=' . $selectedId . '&step=pagamento'),
    'mc_csrf' => $comandaMcCsrf,
    'mc_ctx' => $comandaMcCtx,
    'label' => ($comandaMobileMode ? 'Comanda Mobile' : ($comandaCheckoutMode ? 'Comanda Checkout' : 'Comandas')) . ' · Pagamento Pix',
  ];
  require __DIR__ . '/_pix_sales_payment.php';
require __DIR__ . '/_card_installment_picker.php';
endif; ?>
<?php $voucherPaymentScope = $comandaCheckoutMode ? 'comanda_checkout' : ($comandaMobileMode ? 'comanda_mobile' : 'comanda'); require __DIR__ . '/_voucher_payment_selector.php'; ?>
<?php gestao_admin_end(); ?>
<?php nexa_performance_monitor_mark('renderizacao'); ?>
