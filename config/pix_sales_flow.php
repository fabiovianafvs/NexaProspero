<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Pix imediato reutilizável nos fluxos de venda.
 *
 * Reaproveita o conector homologado do PDV Checkout (gestao_pix_charges),
 * mas deixa cada tela manter sua própria regra de finalização. O pedido só
 * pode ser gravado quando toda forma marcada como Pix automático estiver PAGA.
 */
require_once __DIR__ . '/pix_checkout.php';

if (!function_exists('pix_sales_flow_ready')) {
  function pix_sales_flow_ready(PDO $pdo, bool $allowDdl = true): bool {
    try {
      return pix_checkout_ensure_schema($pdo, $allowDdl);
    } catch (Throwable $e) {
      error_log('[Nexa V11.52.6] Pix vendas indisponível: ' . $e->getMessage());
      return false;
    }
  }
}

if (!function_exists('pix_sales_flow_handle_ajax')) {
  function pix_sales_flow_handle_ajax(
    PDO $pdo,
    int $companyId,
    int $userId,
    bool $crossContext,
    bool $ready,
    string $createAction = 'pix_sales_create',
    string $statusAction = 'pix_sales_status'
  ): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if (!in_array($action, [$createAction, $statusAction], true)) return;

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
      if ($crossContext) {
        if (!function_exists('multi_company_context_csrf_valid')
            || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
          throw new RuntimeException('Contexto Multiempresa expirou. Atualize a tela e tente novamente.');
        }
      }
      if (!$ready) {
        throw new RuntimeException('O Pix automático ainda não está preparado nesta empresa. Confira a forma de pagamento Pix e a integração bancária.');
      }

      if ($action === $createAction) {
        $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
        $amount = pix_checkout_parse_money($_POST['amount'] ?? '0');
        if ($paymentMethodId <= 0) throw new RuntimeException('Selecione a forma de pagamento Pix.');
        if ($amount <= 0) throw new RuntimeException('Informe um valor válido para o Pix.');
        $method = pix_checkout_method_config($pdo, $companyId, $paymentMethodId);
        if (!$method || empty($method['pix_auto_qr_enabled'])) {
          throw new RuntimeException('A forma de pagamento selecionada não está habilitada para QR Code Pix automático.');
        }
        $openCash = function_exists('gestao_open_cash_session')
          ? gestao_open_cash_session($pdo, $companyId, $userId)
          : null;
        $charge = pix_checkout_create_charge(
          $pdo,
          $companyId,
          $userId,
          $paymentMethodId,
          $amount,
          (int)($openCash['id'] ?? 0) ?: null
        );
        echo json_encode(['ok'=>true, 'charge'=>pix_checkout_public_charge($charge)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
      }

      $chargeId = (int)($_POST['charge_id'] ?? 0);
      if ($chargeId <= 0) throw new RuntimeException('Cobrança Pix não informada.');
      $charge = pix_checkout_sync_charge($pdo, $companyId, $chargeId);
      echo json_encode(['ok'=>true, 'charge'=>pix_checkout_public_charge($charge)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
      http_response_code(422);
      echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    exit;
  }
}

if (!function_exists('pix_sales_flow_validate_before_finalize')) {
  function pix_sales_flow_validate_before_finalize(PDO $pdo, int $companyId, array $post, bool $ready): array {
    $usesAutoPix = false;
    foreach ((array)($post['payment_method_id'] ?? []) as $rawMethodId) {
      $methodId = (int)$rawMethodId;
      if ($methodId <= 0) continue;
      $method = pix_checkout_method_config($pdo, $companyId, $methodId);
      if ($method && !empty($method['pix_auto_qr_enabled'])) {
        $usesAutoPix = true;
        break;
      }
    }
    if (!$usesAutoPix) return [];
    if (!$ready) {
      throw new RuntimeException('O Pix automático está selecionado, mas a estrutura de confirmação bancária não está disponível nesta empresa.');
    }
    return pix_checkout_validate_post_payments($pdo, $companyId, $post);
  }
}

if (!function_exists('pix_sales_flow_link_order')) {
  function pix_sales_flow_link_order(PDO $pdo, int $companyId, int $orderId, array $chargeIds): void {
    if ($orderId <= 0 || !$chargeIds) return;
    pix_checkout_link_order($pdo, $companyId, $orderId, $chargeIds);
  }
}
