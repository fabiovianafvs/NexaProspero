<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/gestao_module.php';

$GESTAO_MULTI_COMPANY_CROSS = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && defined('COMPANY_ID')
  && (int)MULTI_COMPANY_CONTEXT_ID > 0 && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
$GESTAO_MULTI_COMPANY_PAGE = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// V11.32: em contexto F11, somente páginas de Gestão explicitamente homologadas podem abrir.
// Além do operacional já homologado, Cadastro/Configuração de Comandas, QR Codes e Agentes de Impressão
// passam a usar a empresa operacional. Emissão fiscal e rotinas ainda não auditadas permanecem protegidas.
if ($GESTAO_MULTI_COMPANY_CROSS) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  $allowedCrossPages = ['gestao.php', 'gestao_clientes.php', 'gestao_pdv.php', 'gestao_pdv_checkout.php', 'gestao_pdv_mobile.php', 'gestao_pdv_resumido.php', 'gestao_comandas.php', 'gestao_comandas_checkout.php', 'gestao_comandas_mobile.php', 'gestao_pedido_detalhado.php', 'gestao_comandas_impressao.php', 'gestao_comandas_config.php', 'gestao_comandas_qrcode.php', 'gestao_comandas_agentes.php', 'gestao_formas_pagamento.php', 'gestao_caixa.php'];
  if (!in_array($GESTAO_MULTI_COMPANY_PAGE, $allowedCrossPages, true)) {
    http_response_code(409);
    if (function_exists('admin_topbar')) admin_topbar('Vendas Multiempresa', 'gestao');
    $dashboardUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao.php') : 'gestao.php';
    $ordersUrl = function_exists('multi_company_context_url') ? multi_company_context_url('pedidos.php') : 'pedidos.php';
    $paymentsUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_formas_pagamento.php') : 'gestao_formas_pagamento.php';
    $cashUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_caixa.php') : 'gestao_caixa.php';
    $mobileUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_mobile.php') : 'gestao_pdv_mobile.php';
    $summaryUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_resumido.php') : 'gestao_pdv_resumido.php';
    $checkoutUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_checkout.php') : 'gestao_pdv_checkout.php';
    $comandasUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas.php') : 'gestao_comandas.php';
    $comandasCheckoutUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_checkout.php') : 'gestao_comandas_checkout.php';
    $comandasMobileUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_mobile.php') : 'gestao_comandas_mobile.php';
    $detailedUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pedido_detalhado.php') : 'gestao_pedido_detalhado.php';
    echo '<div style="max-width:900px;margin:28px auto;padding:22px;border:1px solid #bfdbfe;border-radius:18px;background:#eff6ff;color:#0f172a">';
    $pdvUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv.php') : 'gestao_pdv.php';
    echo '<h2 style="margin:0 0 10px;color:#0b1f5b">V11.32 · Operação protegida no F11</h2>';
    echo '<p style="line-height:1.6;margin:0 0 14px">Nesta etapa, o <strong>Dashboard de Vendas</strong>, a <strong>Consulta de Pedidos</strong>, o <strong>Pedido PDV</strong>, o <strong>PDV Checkout</strong>, a <strong>Venda Mobile</strong>, o <strong>PDV Resumido</strong>, <strong>Comandas</strong>, <strong>Comanda Mobile</strong>, <strong>Formas de Pagamento</strong> e o <strong>Caixa operacional</strong> usam a empresa selecionada. O <strong>Pedido Detalhado</strong> também usa a empresa selecionada, porém sempre em Somente gravar pedido. Impressões operacionais, Cadastro/Configuração de Comandas, QR Codes e Agentes de Impressão também estão liberados. Emissão fiscal continua bloqueada até homologação específica.</p>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn btn-primary" href="' . htmlspecialchars($pdvUrl, ENT_QUOTES, 'UTF-8') . '">Pedido PDV</a><a class="btn" href="' . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') . '">PDV Checkout</a><a class="btn" href="' . htmlspecialchars($mobileUrl, ENT_QUOTES, 'UTF-8') . '">Venda Mobile</a><a class="btn" href="' . htmlspecialchars($summaryUrl, ENT_QUOTES, 'UTF-8') . '">PDV Resumido</a><a class="btn" href="' . htmlspecialchars($comandasUrl, ENT_QUOTES, 'UTF-8') . '">Comandas</a><a class="btn" href="' . htmlspecialchars($comandasMobileUrl, ENT_QUOTES, 'UTF-8') . '">Comanda Mobile</a><a class="btn" href="' . htmlspecialchars($detailedUrl, ENT_QUOTES, 'UTF-8') . '">Pedido Detalhado</a><a class="btn" href="' . htmlspecialchars($cashUrl, ENT_QUOTES, 'UTF-8') . '">Caixa</a><a class="btn" href="' . htmlspecialchars($paymentsUrl, ENT_QUOTES, 'UTF-8') . '">Formas de Pagamento</a><a class="btn" href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '">Dashboard</a><a class="btn" href="' . htmlspecialchars($ordersUrl, ENT_QUOTES, 'UTF-8') . '">Consulta de Pedidos</a></div>';
    echo '</div>';
    if (function_exists('admin_footer')) admin_footer();
    exit;
  }
}

// No F11 cruzado a área de Gestão não executa DDL automático.
if (!$GESTAO_MULTI_COMPANY_CROSS) {
  try { gestao_ensure_schema($pdo); } catch (Throwable $e) {}
}

function gestao_admin_start(string $title, string $active = 'gestao'): void {
  $topActive = in_array($active, ['gestao_comandas_config','gestao_comandas_qrcode','gestao_comandas_impressao','gestao_comandas_agentes','gestao_vendedores','gestao_pagamentos','gestao_entrega_interno','usuarios','usuarios_perfis'], true) ? 'tabelas' : $active;
  admin_topbar($title, $topActive);
  ?>
  <style>
    .mg-page{--mg-border:#dbe4f0;--mg-muted:#64748b;--mg-radius:24px;--mg-bg:#f8fbff;}
    .mg-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin:8px 0 14px}.mg-hero h1{margin:0;color:var(--admin-primary);font-size:clamp(24px,3vw,34px);letter-spacing:-.04em}.mg-hero p{margin:6px 0 0;color:#64748b;line-height:1.45}.mg-actions{display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end;margin-left:auto}.mg-actions .btn{min-height:42px;display:inline-flex;align-items:center;justify-content:center}
    .mg-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px}.mg-tabs a,.mg-tabs summary{display:inline-flex;align-items:center;padding:9px 12px;border:1px solid var(--mg-border);border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:850;font-size:13px;cursor:pointer;min-height:38px}.mg-tabs a.active,.mg-tabs summary.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:var(--admin-text);border-color:transparent}.mg-tab-menu{position:relative;display:inline-block}.mg-tab-menu summary{list-style:none}.mg-tab-menu summary::-webkit-details-marker{display:none}.mg-tab-menu summary:after{content:' ▾';font-size:11px;opacity:.75}.mg-tab-panel{position:absolute;z-index:50;right:0;top:calc(100% + 6px);min-width:240px;background:#fff;border:1px solid var(--mg-border);border-radius:18px;padding:8px;box-shadow:0 20px 55px rgba(11,31,91,.16)}.mg-tab-panel a{display:flex;border-radius:12px;border:0;background:#fff;white-space:nowrap}.mg-tab-panel a:hover{background:#f1f5f9}
    .mg-card{background:#fff;border:1px solid var(--mg-border);border-radius:var(--mg-radius);box-shadow:0 18px 45px rgba(11,31,91,.08);padding:18px}.mg-card h2,.mg-card h3{margin:0 0 12px;color:var(--admin-primary)}.mg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.mg-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.mg-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.mg-kpi{background:#fff;border:1px solid var(--mg-border);border-radius:20px;padding:16px}.mg-kpi span{display:block;color:#64748b;font-weight:850;font-size:13px}.mg-kpi strong{display:block;margin-top:8px;font-size:25px;color:#047857}.mg-kpi.red strong{color:#b91c1c}
    .mg-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.mg-filter .wide{grid-column:span 2}.mg-filter-actions{display:flex;gap:8px;align-items:end;justify-content:flex-end}.mg-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.mg-form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.mg-form-grid .span-2{grid-column:span 2}.mg-form-grid .span-3{grid-column:span 3}.mg-form-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;border-top:1px solid #eef2f7;margin-top:14px;padding-top:14px}
    .mg-table{width:100%;border-collapse:separate;border-spacing:0}.mg-table th{background:#f8fbff;color:#334155;font-size:13px;text-align:left;padding:11px 12px;border-top:1px solid var(--mg-border);border-bottom:1px solid var(--mg-border)}.mg-table th:first-child{border-left:1px solid var(--mg-border);border-radius:14px 0 0 0}.mg-table th:last-child{border-right:1px solid var(--mg-border);border-radius:0 14px 0 0}.mg-table td{padding:12px;border-bottom:1px solid #e5edf6;vertical-align:middle}.mg-value{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.mg-muted{color:#64748b}.mg-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:900}.mg-badge.green{border-color:#a7f3d0;background:#ecfdf5;color:#047857}.mg-badge.red{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.mg-badge.gray{border-color:#e2e8f0;background:#f8fafc;color:#475569}.mg-inline-actions{display:flex;gap:8px;flex-wrap:wrap}.mg-empty{text-align:center;color:#64748b;padding:18px!important}
    .pdv-layout{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:14px;align-items:start}.pdv-search{display:grid;grid-template-columns:1fr 190px;gap:10px;margin-bottom:12px}.pdv-cats{display:flex;gap:8px;overflow:auto;padding-bottom:8px;margin-bottom:10px}.pdv-cat{border:1px solid var(--mg-border);border-radius:999px;background:#fff;padding:8px 11px;font-weight:850;white-space:nowrap;cursor:pointer}.pdv-cat.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.pdv-products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-height:calc(100vh - 310px);overflow:auto;padding-right:4px}.pdv-product{display:grid;grid-template-columns:62px 1fr;gap:10px;align-items:center;border:1px solid var(--mg-border);border-radius:18px;background:#fff;padding:10px;cursor:pointer;transition:.15s}.pdv-product:hover{transform:translateY(-1px);box-shadow:0 12px 28px rgba(2,6,23,.08)}.pdv-img{width:62px;height:62px;border-radius:16px;background:#f1f5f9;object-fit:cover}.pdv-product strong{display:block;color:#0f172a;line-height:1.15}.pdv-product small{display:block;margin-top:4px;color:#64748b}.pdv-product b{display:block;margin-top:6px;color:#047857}.pdv-cart{position:sticky;top:92px}.pdv-cart-items{display:grid;gap:8px;max-height:330px;overflow:auto;padding-right:4px}.pdv-cart-item{border:1px solid #e2e8f0;border-radius:16px;padding:10px;background:#f8fbff}.pdv-cart-line{display:flex;justify-content:space-between;gap:10px}.pdv-qty{display:flex;align-items:center;gap:6px;margin-top:8px}.pdv-qty button{width:34px;height:34px;border-radius:12px;border:1px solid #dbe4f0;background:#fff;font-weight:900}.pdv-qty input{width:74px;text-align:center}.pdv-total-row{display:flex;justify-content:space-between;gap:12px;padding:7px 0}.pdv-total-row.final{font-size:22px;font-weight:950;color:var(--admin-primary);border-top:1px solid #e2e8f0;margin-top:8px;padding-top:12px}.pdv-bottom{position:sticky;bottom:0;background:#fff;padding-top:10px}
    .cash-menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.cash-action{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px;border:1px solid #dbe4f0;border-radius:18px;background:#f8fbff;text-decoration:none;color:#0f172a;font-weight:950}.cash-action:hover{background:#eff6ff}.cash-key{display:inline-flex;width:44px;height:34px;border-radius:10px;align-items:center;justify-content:center;background:#fff;border:1px solid #dbe4f0;color:var(--admin-primary);font-weight:950}
    @media(max-width:1150px){.pdv-layout{grid-template-columns:1fr}.pdv-cart{position:static}.pdv-products{max-height:none}.mg-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.mg-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.mg-filter .wide{grid-column:span 2}}
    @media(max-width:760px){.mg-grid,.mg-grid-3,.mg-grid-4,.mg-form-grid,.mg-form-grid.three,.mg-filter,.pdv-search,.cash-menu{grid-template-columns:1fr}.mg-form-grid .span-2,.mg-form-grid .span-3,.mg-filter .wide{grid-column:auto}.mg-actions,.mg-filter-actions{width:100%;justify-content:stretch}.mg-actions .btn,.mg-filter-actions .btn{flex:1}.mg-tabs{overflow:auto;flex-wrap:nowrap;padding-bottom:4px}.mg-tabs a,.mg-tabs summary{white-space:nowrap}.mg-tab-panel{position:fixed;left:14px;right:14px;min-width:0}.pdv-products{grid-template-columns:1fr}.pdv-cart-items{max-height:none}.mg-card{border-radius:20px;padding:15px}}
    @media(max-width:680px){.table-wrap{overflow:visible}.mg-table thead{display:none}.mg-table tr{display:block;border:1px solid #dbe4f0;border-radius:18px;background:#fff;margin-bottom:10px;padding:10px}.mg-table td{display:flex;justify-content:space-between;gap:12px;border:0!important;padding:7px 4px!important;text-align:right}.mg-table td:before{content:attr(data-label);font-weight:900;color:#64748b;text-align:left}.mg-table td.primary{display:block;text-align:left}.mg-table td.primary:before{display:none}}
  </style>
  <div class="mg-page">
  <?php
}

function gestao_admin_end(): void { echo '</div>'; admin_footer(); }

function gestao_tabs(string $active, bool $openAuxActive = true): void {
  $items = [
    'gestao' => ['Dashboard vendas', 'gestao.php'],
    'gestao_pdv_resumido' => ['PDV Resumido', 'gestao_pdv_resumido.php'],
    'gestao_pdv_checkout' => ['PDV Checkout', 'gestao_pdv_checkout.php'],
    'gestao_pdv' => ['Pedido PDV', 'gestao_pdv.php'],
    'gestao_comandas' => ['Comandas', 'gestao_comandas.php'],
    'gestao_comandas_checkout' => ['Comandas Checkout', 'gestao_comandas_checkout.php'],
    'gestao_comandas_mobile' => ['Comanda Mobile', 'gestao_comandas_mobile.php'],
    'gestao_pedido_detalhado' => ['Pedido Detalhado', 'gestao_pedido_detalhado.php'],
    'gestao_pedidos' => ['Consulta de pedidos', 'pedidos.php'],
    'gestao_caixa' => ['Caixa', 'gestao_caixa.php'],
  ];
  if (!empty($GLOBALS['GESTAO_MULTI_COMPANY_CROSS'])) {
    $items = [
      'gestao' => ['Dashboard vendas', function_exists('multi_company_context_url') ? multi_company_context_url('gestao.php') : 'gestao.php'],
      'gestao_pdv_resumido' => ['PDV Resumido', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_resumido.php') : 'gestao_pdv_resumido.php'],
      'gestao_pdv_checkout' => ['PDV Checkout', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_checkout.php') : 'gestao_pdv_checkout.php'],
      'gestao_pdv' => ['Pedido PDV', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv.php') : 'gestao_pdv.php'],
      'gestao_pdv_mobile' => ['Venda Mobile', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_mobile.php') : 'gestao_pdv_mobile.php'],
      'gestao_comandas' => ['Comandas', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas.php') : 'gestao_comandas.php'],
      'gestao_comandas_checkout' => ['Comandas Checkout', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_checkout.php') : 'gestao_comandas_checkout.php'],
      'gestao_comandas_mobile' => ['Comanda Mobile', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_mobile.php') : 'gestao_comandas_mobile.php'],
      'gestao_pedido_detalhado' => ['Pedido Detalhado', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pedido_detalhado.php') : 'gestao_pedido_detalhado.php'],
      'gestao_pedidos' => ['Consulta de pedidos', function_exists('multi_company_context_url') ? multi_company_context_url('pedidos.php') : 'pedidos.php'],
      'gestao_caixa' => ['Caixa', function_exists('multi_company_context_url') ? multi_company_context_url('gestao_caixa.php') : 'gestao_caixa.php'],
    ];
  }
  $aux = [
    'gestao_vendedores' => ['Cadastro de Vendedores', 'gestao_vendedores.php'],
    'gestao_comandas_config' => ['Cadastro / Configuração de Comandas', 'gestao_comandas_config.php'],
    'gestao_comandas_qrcode' => ['QR Codes das Mesas', 'gestao_comandas_qrcode.php'],
    'gestao_comandas_impressao' => ['Monitor de Impressão', 'gestao_comandas_impressao.php'],
    'gestao_comandas_agentes' => ['Agentes de Impressão', 'gestao_comandas_agentes.php'],
    'gestao_pagamentos' => ['Formas de Pagamento', 'gestao_formas_pagamento.php'],
    'gestao_entrega_interno' => ['Entrega Interno', 'gestao_entrega_interno.php'],
    'usuarios' => ['Usuários', 'usuarios.php'],
    'usuarios_perfis' => ['Perfis de acesso', 'usuarios_perfis.php']
  ];
  if (!empty($GLOBALS['GESTAO_MULTI_COMPANY_CROSS']) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    // Em F11, o menu deve refletir o perfil efetivo da empresa selecionada — não o
    // perfil legado do tenant de entrada. V11.25.3 também expõe Formas de Pagamento,
    // já homologada na V11.25.2, sem liberar as demais tabelas operacionais.
    $pdoTabs = $GLOBALS['pdo'];
    $companyForTabs = defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID > 0
      ? (int)MULTI_COMPANY_CONTEXT_ID : (defined('COMPANY_ID') ? (int)COMPANY_ID : 0);
    $items = array_filter($items, static function($it, $key) use ($pdoTabs, $companyForTabs): bool {
      if (!function_exists('multi_company_context_permission_allowed')) return false;
      if (in_array((string)$key, ['gestao_comandas','gestao_comandas_checkout','gestao_comandas_mobile'], true)
          && !saas_company_has_module($pdoTabs, $companyForTabs, 'comandas')) return false;
      if ((string)$key === 'gestao') return multi_company_context_permission_allowed($pdoTabs, 'gestao_dashboard');
      if ((string)$key === 'gestao_pdv') return multi_company_context_permission_allowed($pdoTabs, 'gestao_pdv');
      if ((string)$key === 'gestao_pdv_checkout') return multi_company_context_permission_allowed($pdoTabs, 'gestao_pdv_checkout');
      if ((string)$key === 'gestao_pdv_mobile') return multi_company_context_permission_allowed($pdoTabs, 'gestao_pdv_mobile');
      if ((string)$key === 'gestao_pdv_resumido') return multi_company_context_permission_allowed($pdoTabs, 'gestao_pdv_resumido');
      if ((string)$key === 'gestao_comandas') return multi_company_context_permission_allowed($pdoTabs, 'gestao_comandas');
      if ((string)$key === 'gestao_comandas_checkout') return multi_company_context_permission_allowed($pdoTabs, 'gestao_comandas');
      if ((string)$key === 'gestao_comandas_mobile') return multi_company_context_permission_allowed($pdoTabs, 'gestao_comandas_mobile');
      if ((string)$key === 'gestao_pedido_detalhado') return multi_company_context_permission_allowed($pdoTabs, 'gestao_pedido_detalhado');
      if ((string)$key === 'gestao_pedidos') {
        return multi_company_context_permission_allowed($pdoTabs, 'gestao_pedidos')
          || multi_company_context_permission_allowed($pdoTabs, 'pedidos');
      }
      if ((string)$key === 'gestao_caixa') return multi_company_context_permission_allowed($pdoTabs, 'gestao_caixa');
      return false;
    }, ARRAY_FILTER_USE_BOTH);
    $aux = [];
    if (function_exists('multi_company_context_permission_allowed')
        && multi_company_context_permission_allowed($pdoTabs, 'gestao_pagamentos')) {
      $aux['gestao_pagamentos'] = [
        'Formas de Pagamento',
        function_exists('multi_company_context_url') ? multi_company_context_url('gestao_formas_pagamento.php') : 'gestao_formas_pagamento.php'
      ];
    }
    $effectiveCompanyId = (defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID > 0)
      ? (int)MULTI_COMPANY_CONTEXT_ID : (defined('COMPANY_ID') ? (int)COMPANY_ID : 0);
    if (function_exists('multi_company_context_permission_allowed')
        && multi_company_context_permission_allowed($pdoTabs, 'gestao_comandas')
        && saas_company_has_module($pdoTabs, $effectiveCompanyId, 'comandas')) {
      $aux['gestao_comandas_config'] = [
        'Cadastro / Configuração de Comandas',
        function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_config.php') : 'gestao_comandas_config.php'
      ];
      $aux['gestao_comandas_qrcode'] = [
        'QR Codes das Mesas',
        function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_qrcode.php') : 'gestao_comandas_qrcode.php'
      ];
      $aux['gestao_comandas_impressao'] = [
        'Monitor de Impressão',
        function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_impressao.php') : 'gestao_comandas_impressao.php'
      ];
      $aux['gestao_comandas_agentes'] = [
        'Agentes de Impressão',
        function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_agentes.php') : 'gestao_comandas_agentes.php'
      ];
    }
  } elseif (function_exists('saas_admin_nav_allowed') && defined('COMPANY_ID') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdoTabs = $GLOBALS['pdo'];
    $items = array_filter($items, fn($it, $key) => saas_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key) && (!function_exists('access_admin_nav_allowed') || access_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key)), ARRAY_FILTER_USE_BOTH);
    $aux = array_filter($aux, fn($it, $key) => saas_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key) && (!function_exists('access_admin_nav_allowed') || access_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key)), ARRAY_FILTER_USE_BOTH);
  }
  $auxActive = array_key_exists($active, $aux);
  echo '<div class="mg-tabs">';
  foreach ($items as $key => $it) echo '<a class="' . ($key===$active?'active':'') . '" href="' . gestao_h($it[1]) . '">' . gestao_h($it[0]) . '</a>';
  if ($aux) {
    echo '<details class="mg-tab-menu"' . (($auxActive && $openAuxActive) ? ' open' : '') . '><summary class="' . ($auxActive?'active':'') . '">Tabelas</summary><div class="mg-tab-panel">';
    foreach ($aux as $key => $it) echo '<a class="' . ($key===$active?'active':'') . '" href="' . gestao_h($it[1]) . '">' . gestao_h($it[0]) . '</a>';
    echo '</div></details>';
  }
  echo '</div>';
}

function gestao_page_header(string $title, string $description, array $actions = []): void {
  echo '<div class="mg-hero"><div><h1>' . gestao_h($title) . '</h1>' . (trim($description) !== '' ? '<p>' . gestao_h($description) . '</p>' : '') . '</div>';
  if ($actions) { echo '<div class="mg-actions">'; foreach ($actions as $a) echo '<a class="' . gestao_h($a['class'] ?? 'btn') . '" href="' . gestao_h($a['href'] ?? '#') . '">' . gestao_h($a['label'] ?? 'Ação') . '</a>'; echo '</div>'; }
  echo '</div>';
}

function gestao_flash(): void {
  if (!empty($_GET['ok'])) echo '<div class="msg ok">' . gestao_h($_GET['ok']) . '</div>';
  if (!empty($_GET['err'])) echo '<div class="msg err">' . gestao_h($_GET['err']) . '</div>';
}

function gestao_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function gestao_date($v): string { return finance_date_br((string)$v); }

function gestao_select_options(array $rows, $selected = null, string $empty = 'Selecione', bool $disableSynthetic = true): void {
  echo '<option value="">' . gestao_h($empty) . '</option>';
  foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    $label = trim(((string)($r['code'] ?? '') !== '' ? (string)$r['code'] . ' - ' : '') . (string)($r['name'] ?? ''));
    if (isset($r['document']) && trim((string)$r['document']) !== '') $label .= ' (' . (string)$r['document'] . ')';
    $disabled = ($disableSynthetic && isset($r['is_analytic']) && (int)$r['is_analytic'] !== 1) ? ' disabled' : '';
    echo '<option value="' . $id . '"' . ((string)$selected === (string)$id ? ' selected' : '') . $disabled . '>' . str_repeat('— ', max(0, (int)($r['level'] ?? 1)-1)) . gestao_h($label) . '</option>';
  }
}
