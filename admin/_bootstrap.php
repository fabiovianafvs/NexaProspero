<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/access_control.php';
// Schemas aplicados por migração; nenhuma DDL no caminho normal.

require_company_admin(COMPANY_ID);

$page = basename($_SERVER['PHP_SELF'] ?? '');
$pageLower = strtolower($page);

// Utilitarios: ativacao fiscal sensivel, exclusivamente pela empresa do login.
// Nao permitir perfil comum ou empresa selecionada pelo F11.
if ($pageLower === 'utilitarios_regularizacao_nfe.php') {
  $cross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
  if ($cross || !function_exists('access_is_company_master') || !access_is_company_master()) {
    access_render_denied('Esta configuracao fiscal e exclusiva do administrador da empresa de entrada e nao pode ser alterada pelo F11.');
  }
  return;
}

// V11.8: o Atualizador do Banco é uma função global da plataforma.
// Ele não herda permissões da empresa nem do contexto F11 e somente o
// platform_admin pode acessá-lo. Nenhuma migration é executada neste bootstrap.
if (in_array($pageLower, ['atualizador_banco.php', 'backup_banco.php'], true)) {
  require_platform_admin();
  return;
}

// V11-V11.35 Multiempresa: somente páginas explicitamente homologadas podem substituir a
// validação do perfil legado pela permissão do perfil efetivo da empresa do F11.
// Todas as demais páginas continuam exatamente no fluxo anterior.
$multiCompanyPermission = null;
$multiCompanyFiscalPrintSource = strtolower(trim((string)($_GET['source'] ?? '')));
$isCrossMultiCompany = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;

// V11.36.2: Pessoas é uma função-base do ERP e fica habilitada por padrão
// para qualquer perfil. O bloqueio passa a ser explícito pelo token interno
// __deny_pessoas, preservando perfis antigos sem exigir recadastro.
if (!function_exists('nexa_people_permission_allowed')) {
  function nexa_people_permission_allowed(PDO $pdo, ?int $companyId = null): bool {
    if (function_exists('access_is_company_master') && access_is_company_master()) return true;
    $denyToken = '__deny_pessoas';
    $entryCompanyId = defined('COMPANY_ID') ? (int)COMPANY_ID : (int)($companyId ?? 0);
    $targetCompanyId = (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
      && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID > 0)
      ? (int)MULTI_COMPANY_CONTEXT_ID : (int)($companyId ?? $entryCompanyId);

    if ($targetCompanyId !== $entryCompanyId && function_exists('multi_company_context_profile')) {
      $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      if ($uid <= 0) return false;
      $profile = multi_company_context_profile($pdo, $uid, $entryCompanyId, $targetCompanyId);
      if (!$profile) return false;
      $perms = json_decode((string)($profile['permissions_json'] ?? '[]'), true);
      $perms = is_array($perms) ? array_values(array_map('strval', $perms)) : [];
      return !in_array($denyToken, $perms, true);
    }

    $user = function_exists('access_current_user') ? access_current_user($pdo) : null;
    if (!$user || (int)($user['is_active'] ?? 0) !== 1) return false;
    if ($entryCompanyId > 0 && (int)($user['company_id'] ?? 0) !== $entryCompanyId) return false;
    $profileId = (int)($user['profile_id'] ?? 0);
    if ($profileId <= 0) return true;
    $perms = function_exists('access_profile_permissions') ? access_profile_permissions($pdo, $entryCompanyId, $profileId) : [];
    return !in_array($denyToken, $perms, true);
  }
}

$isDefaultPeoplePage = in_array($pageLower, ['gestao_clientes.php','gestao_clientes_importar.php'], true)
  || ($pageLower === 'financeiro_pessoas.php'
    && !( !empty($_REQUEST['mc_finance']) && strtolower(trim((string)($_REQUEST['role'] ?? ''))) === 'supplier' ));

if ($isCrossMultiCompany) {
  $financePersonRole = strtolower(trim((string)($_REQUEST['role'] ?? '')));
  $financePersonMode = !empty($_REQUEST['mc_finance']) && $financePersonRole === 'supplier';
  $multiCompanyPermission = match ($pageLower) {
    'produtos.php', 'produto_form.php', 'produto_gtin_lookup.php', 'estoque_consulta.php', 'fiscal_lookup.php' => 'produtos',
    'estoque_conferencias.php', 'estoque_conferencia_form.php', 'estoque_conferencia_processar.php' => 'conferencia_estoque',
    'fiscal_radar_xml.php' => 'fiscal_radar',
    'fiscal_entrada_notas.php' => 'fiscal_entrada_notas',
    'fiscal_importar_xml.php' => 'fiscal_importar_xml',
    'fiscal_nota_manual.php' => 'fiscal_nota_manual',
    'fiscal_devolucoes.php' => 'fiscal_devolucoes',
    'fiscal_devolucao_cliente.php' => 'fiscal_devolucao_cliente',
    'fiscal_saidas.php' => 'fiscal_saidas',
    'fiscal_documentos.php' => 'fiscal_documentos',
    'fiscal_configuracoes.php' => 'fiscal_configuracoes',
    // V11.50: NFS-e em homologação passa a operar pela empresa selecionada.
    'fiscal_nfse_nacional.php', 'fiscal_nfse_acao.php', 'fiscal_nfse_imprimir.php' => 'fiscal_documentos',
    // V11.49: inutilização e seu relatório passam a operar na empresa selecionada.
    'fiscal_inutilizacao.php' => 'fiscal_inutilizacao',
    'fiscal_relatorio_inutilizacoes.php' => 'fiscal_relatorios',
    // V11.35/V11.46: emissão protegida de NFC-e 65 e NF-e 55, exclusivamente em HOMOLOGAÇÃO.
    'fiscal_nfce_pedido.php', 'fiscal_nfce_acao.php' => 'fiscal_documentos',
    // V11.46.1: quando a impressão é retorno imediato de uma venda, preserva a
    // permissão do PDV que já foi revalidada para a empresa selecionada pelo F11.
    'fiscal_nfce_imprimir.php' => $multiCompanyFiscalPrintSource === 'pdv_order'
      ? 'gestao_pdv'
      : ($multiCompanyFiscalPrintSource === 'pdv_checkout'
          ? 'gestao_pdv_checkout'
          : ($multiCompanyFiscalPrintSource === 'pdv_summary' ? 'gestao_pdv_resumido' : 'fiscal_documentos')),
    // V11.24/V11.25: Dashboard de Vendas em leitura e Pedido PDV protegido na empresa selecionada.
    'gestao.php' => 'gestao_dashboard',
    'gestao_pdv.php' => 'gestao_pdv',
    'gestao_pdv_checkout.php' => 'gestao_pdv_checkout',
    'commercial_credit_authorize.php' => strtolower(trim((string)($_REQUEST['sales_channel'] ?? ''))) === 'summary' ? 'gestao_pdv_resumido' : 'gestao_pdv',
    // V11.28: Venda Mobile, PDV Resumido e Caixa usam as permissões efetivas da empresa selecionada,
    // sem depender das permissões legadas da empresa de entrada.
    'gestao_pdv_mobile.php' => 'gestao_pdv_mobile',
    'gestao_pdv_resumido.php' => 'gestao_pdv_resumido',
    // V11.29: Comandas e Comanda Mobile usam as permissões efetivas da empresa selecionada.
    'gestao_comandas.php' => 'gestao_comandas',
    'gestao_comandas_checkout.php' => 'gestao_comandas_checkout',
    'gestao_comandas_mobile.php' => 'gestao_comandas_mobile',
    // V11.30: Pedido Detalhado usa o perfil efetivo da empresa selecionada.
    'gestao_pedido_detalhado.php' => 'gestao_pedido_detalhado',
    // V11.31/V11.32: impressão, cadastro/configuração, QR Codes e agentes de Comandas
    // usam a permissão efetiva da empresa selecionada.
    'gestao_comandas_impressao.php', 'gestao_comandas_print_api.php', 'gestao_comandas_config.php',
    'gestao_comandas_qrcode.php', 'gestao_comandas_agentes.php' => 'gestao_comandas',
    'gestao_caixa.php' => 'gestao_caixa',
    // V11.25.2: cadastro operacional das formas de pagamento da empresa selecionada.
    'gestao_formas_pagamento.php' => 'gestao_pagamentos',
    'financeiro.php' => 'financeiro_dashboard',
    'financeiro_contas_receber.php', 'financeiro_cartoes.php' => 'financeiro_receber',
    'financeiro_contas_receber_importar.php' => 'financeiro_receber',
    'financeiro_contas_pagar.php' => 'financeiro_pagar',
    'financeiro_despesas_fixas.php' => 'financeiro_despesas_fixas',
    'financeiro_movimentos.php' => 'financeiro_movimentos',
    'financeiro_vales.php' => 'financeiro_vales',
    'financeiro_vales_ajax.php' => match (strtolower(trim((string)($_REQUEST['scope'] ?? '')))) {
      'pdv' => 'gestao_pdv',
      'pdv_resumido' => 'gestao_pdv_resumido',
      'pdv_mobile' => 'gestao_pdv_mobile',
      'pdv_checkout' => 'gestao_pdv_checkout',
      'comanda' => 'gestao_comandas',
      'comanda_mobile' => 'gestao_comandas_mobile',
      'comanda_checkout' => 'gestao_comandas_checkout',
      'detailed_order' => 'gestao_pedido_detalhado',
      default => 'financeiro_vales',
    },
    'financeiro_resultado.php' => 'financeiro_resultado',
    'financeiro_caixas_bancos.php', 'financeiro_itau.php' => 'financeiro_caixas',
    'financeiro_plano_contas.php' => 'financeiro_plano',
    'financeiro_centros_custo.php' => 'financeiro_centro',
    'financeiro_formas_pagamento.php' => 'gestao_pagamentos',
    'financeiro_recibo_baixa.php', 'financeiro_recibo_whatsapp.php' => 'financeiro_receber',
    'financeiro_boletos.php', 'financeiro_boleto_itau.php', 'financeiro_boleto_imprimir.php', 'financeiro_boleto_whatsapp.php' => 'financeiro_boletos',
    'financeiro_conciliacao.php' => 'financeiro_conciliacao',
    'gestao_clientes.php', 'gestao_clientes_importar.php' => 'clientes',
    'financeiro_pessoas.php' => $financePersonMode ? 'financeiro_pessoas' : 'clientes',
    'fiscal_tributacoes.php' => 'fiscal_tributacoes',
    'fiscal_operacoes.php' => 'fiscal_operacoes',
    // V11.36.3: utilitários em lote já usam company_id operacional e transação.
    'utilitarios_manutencao_categorias.php', 'utilitarios_manutencao_tributacoes.php' => 'manutencao_produtos',
    // V11.36: catálogos oficiais permanecem compartilhados. A permissão continua
    // sendo a genérica fiscal_tabelas; o F11 apenas permite consulta contextual,
    // sem transformar estes catálogos em dados isolados por empresa.
    'fiscal_cfops.php', 'fiscal_ncms.php', 'fiscal_cests.php', 'fiscal_cst.php',
    'fiscal_ibscbs.php', 'fiscal_importacoes.php', 'fiscal_tabelas.php', 'fiscal_lookup.php', 'fiscal_st_uf.php' => 'fiscal_tabelas',
    // V11.36: Usuários permite administrar o vínculo multiempresa; Perfis pode ser
    // editado diretamente quando a empresa/grupo opera em modo isolado.
    'usuarios.php', 'multiempresa_acessos.php' => 'usuarios',
    'usuarios_perfis.php' => 'usuarios_perfis',
    // V11.22.1: Central WhatsApp e catálogo de modelos operam na empresa selecionada.
    'whatsapp.php', 'whatsapp_api.php', 'whatsapp_modelos.php' => 'whatsapp',
    default => null,
  };

  // V11.23: o endpoint genérico de e-mail herda o perfil da empresa do F11
  // para BOLETO e RECIBO. Outros documentos continuam fora deste ciclo.
  if ($pageLower === 'email_documento.php') {
    $emailDocType = strtolower(trim((string)($_REQUEST['type'] ?? '')));
    if ($emailDocType === 'boleto') $multiCompanyPermission = 'financeiro_boletos';
    elseif ($emailDocType === 'recibo') $multiCompanyPermission = 'financeiro_receber';
  }

  // fiscal_xml_entrada.php atende tanto XML quanto lançamento manual. Em contexto F11,
  // identifica a origem real do registro antes de escolher a permissão, impedindo que
  // um parâmetro de URL seja usado para contornar o controle de acesso.
  if ($pageLower === 'fiscal_xml_entrada.php') {
    $multiCompanyPermission = 'fiscal_importar_xml';
    $entryId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($entryId > 0 && function_exists('multi_company_operational_company_id')) {
      try {
        $entryCompanyId = (int)multi_company_operational_company_id($pdo, 'goods_entries');
        $stManualEntry = $pdo->prepare('SELECT is_manual FROM fiscal_xml_imports WHERE company_id=? AND id=? LIMIT 1');
        $stManualEntry->execute([$entryCompanyId, $entryId]);
        if ((int)($stManualEntry->fetchColumn() ?: 0) === 1) {
          $multiCompanyPermission = 'fiscal_nota_manual';
        }
      } catch (Throwable $e) {
        // Em caso de tabela ainda não preparada ou falha de leitura, mantém a permissão
        // mais restritiva do fluxo XML; a própria página fará a validação do registro.
      }
    }
  }
}


// V11.24-V11.32: Vendas Multiempresa por etapas.
// Dashboard/Central de Pedidos, Pedido PDV, Caixa, Venda Mobile, PDV Resumido, Comandas, Pedido Detalhado
// as impressões operacionais não fiscais e a configuração/QR/agentes de Comandas já possuem escopos protegidos.
// Edição/status posterior, envios do pedido e emissão fiscal continuam fora do contexto cruzado.
$salesReadPages = ['gestao.php','pedidos.php','pedido_print.php'];
$salesPrintingPages = [
  'pedido_print_conferencia.php','pedido_print_producao.php','pedido_print_cozinha.php','pedido_print_evento.php',
  'pedido_relatorio_produtos.php','gestao_comandas_impressao.php','gestao_comandas_print_api.php',
];
$salesBlockedPages = [
  'pedido.php','pedido_editar.php','pedido_regularizacao.php','pedido_status.php','pedido_whatsapp.php','pedido_finalizado.php',
  'pedidos_contratos_lote.php',
];
if ($isCrossMultiCompany && in_array($pageLower, $salesBlockedPages, true)) {
  access_render_denied('V11.32 Multiempresa: esta função de Vendas ainda não foi homologada no contexto F11. Já estão liberados Dashboard, Consulta de Pedidos, Pedido PDV, Venda Mobile, PDV Resumido, Caixa, Comandas, Pedido Detalhado, impressões operacionais e configuração/QR/agentes de Comandas. Edição/status posterior, envios do pedido e emissão fiscal continuam protegidos.');
}
if ($isCrossMultiCompany && in_array($pageLower, ['pedidos.php','pedido_print.php'], true)) {
  $salesReadAllowed = false;
  if (function_exists('multi_company_context_permission_allowed')) {
    $salesReadAllowed = multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
      || multi_company_context_permission_allowed($pdo, 'pedidos');
    if ($pageLower === 'pedido_print.php') {
      $salesReadAllowed = $salesReadAllowed
        || multi_company_context_permission_allowed($pdo, 'gestao_pdv_checkout')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas_checkout')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas_mobile');
    }
  }
  if (!$salesReadAllowed) {
    access_render_denied($pageLower === 'pedido_print.php'
      ? 'Seu perfil na empresa selecionada pelo F11 não possui permissão para imprimir este pedido.'
      : 'Seu perfil na empresa selecionada pelo F11 não possui permissão para consultar pedidos.');
  }
}
if ($isCrossMultiCompany && in_array($pageLower, $salesPrintingPages, true)) {
  $salesPrintAllowed = false;
  if (function_exists('multi_company_context_permission_allowed')) {
    if (in_array($pageLower, ['pedido_print_conferencia.php','gestao_comandas_impressao.php','gestao_comandas_print_api.php'], true)) {
      $salesPrintAllowed = multi_company_context_permission_allowed($pdo, 'gestao_comandas')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas_checkout')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas_mobile');
    } elseif ($pageLower === 'pedido_print_evento.php') {
      $salesPrintAllowed = multi_company_context_permission_allowed($pdo, 'gestao_pdv')
        || multi_company_context_permission_allowed($pdo, 'gestao_pdv_resumido')
        || multi_company_context_permission_allowed($pdo, 'gestao_pdv_checkout')
        || multi_company_context_permission_allowed($pdo, 'gestao_pdv_mobile')
        || multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
        || multi_company_context_permission_allowed($pdo, 'pedidos');
    } elseif ($pageLower === 'pedido_print_cozinha.php') {
      $salesPrintAllowed = multi_company_context_permission_allowed($pdo, 'gestao_comandas')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas_checkout')
        || multi_company_context_permission_allowed($pdo, 'gestao_comandas_mobile')
        || multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
        || multi_company_context_permission_allowed($pdo, 'pedidos');
    } else {
      $salesPrintAllowed = multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
        || multi_company_context_permission_allowed($pdo, 'pedidos');
    }
  }
  if (!$salesPrintAllowed) {
    access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para esta impressão/produção.');
  }
}
// V11.31: mutações da fila de impressão no F11 exigem CSRF Multiempresa e ficam restritas
// às ações já auditadas. A API repete a mesma validação.
if ($isCrossMultiCompany && $pageLower === 'gestao_comandas_print_api.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $printQueueAction = strtolower(trim((string)($_REQUEST['action'] ?? '')));
  if (!in_array($printQueueAction, ['mark','retry','rebuild'], true)) {
    access_render_denied('V11.31 Multiempresa: esta ação da fila de impressão não está homologada no F11.');
  }
  if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
    access_render_denied('Contexto Multiempresa expirado para operação da fila de impressão. Atualize a página e tente novamente.');
  }
}

// V11.32: Cadastro/Configuração, QR Codes e Agentes de Comandas no F11.
// O bootstrap limita as ações antes de a página tocar qualquer registro; cada tela repete CSRF/company_id.
if ($isCrossMultiCompany && in_array($pageLower, ['gestao_comandas_config.php','gestao_comandas_qrcode.php','gestao_comandas_agentes.php'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $setupAction = strtolower(trim((string)($_POST['action'] ?? '')));
  $allowedSetupActions = match ($pageLower) {
    'gestao_comandas_config.php' => ['create_comanda','update_comanda','add_commandas','save_settings'],
    'gestao_comandas_qrcode.php' => ['regenerate_token'],
    'gestao_comandas_agentes.php' => ['create','update','regenerate','toggle','delete'],
    default => [],
  };
  if (!in_array($setupAction, $allowedSetupActions, true)) {
    access_render_denied('V11.32 Multiempresa: esta ação de configuração de Comandas não está homologada no F11.');
  }
  if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
    access_render_denied('Contexto Multiempresa expirado para configuração de Comandas. Atualize a página e tente novamente.');
  }
}

// V11.25: no Pedido PDV cruzado somente as três gravações homologadas podem chegar à página.
// A própria tela repete CSRF, company_id operacional e bloqueio absoluto de emissão fiscal.
if ($isCrossMultiCompany && in_array($pageLower, ['gestao_pdv.php','gestao_pdv_checkout.php','gestao_pdv_mobile.php','gestao_pdv_resumido.php'], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $salesPdvAction = strtolower(trim((string)($_POST['action'] ?? '')));
  if (!in_array($salesPdvAction, ['finalize_pdv','create_customer_pdv','create_customer_address_pdv','pix_sales_create','pix_sales_status'], true)) {
    access_render_denied('V11.28 Multiempresa: esta ação de venda ainda não foi homologada para a empresa selecionada pelo F11.');
  }
}

// V11.29: Comandas cruzadas aceitam somente as operações já auditadas no mesmo motor
// da empresa principal. A própria tela repete CSRF, escopo company_id e bloqueio fiscal.
if ($isCrossMultiCompany && in_array($pageLower, ['gestao_comandas.php','gestao_comandas_checkout.php','gestao_comandas_mobile.php'], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $comandaAction = strtolower(trim((string)($_POST['action'] ?? '')));
  $comandaAllowedActions = ['read_comanda','create_customer_comanda','process_products','update_header','cancel_item','transfer_all','transfer_multiple','transfer_item','finalize_comanda','pix_sales_create','pix_sales_status'];
  if (!in_array($comandaAction, $comandaAllowedActions, true)) {
    access_render_denied('V11.29 Multiempresa: esta ação de Comandas ainda não foi homologada para a empresa selecionada pelo F11.');
  }
}

// V11.30: Pedido Detalhado cruzado aceita somente a gravação protegida do pedido.
// Emissão fiscal é recusada novamente dentro da própria página.
if ($isCrossMultiCompany && $pageLower === 'gestao_pedido_detalhado.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $detailedAction = strtolower(trim((string)($_POST['action'] ?? '')));
  if (!in_array($detailedAction, ['finalize_detailed_order','pix_sales_create','pix_sales_status'], true)) {
    access_render_denied('V11.52.6 Multiempresa: esta ação do Pedido Detalhado não está homologada no F11.');
  }
}

// V11.25.2: libera exclusivamente o cadastro operacional de Formas de Pagamento
// na empresa selecionada. A própria página repete CSRF, company_id e valida todos
// os vínculos financeiros antes de salvar.
if ($isCrossMultiCompany && $pageLower === 'gestao_formas_pagamento.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $paymentAction = strtolower(trim((string)($_POST['action'] ?? '')));
  if ($paymentAction !== 'save_payment') {
    access_render_denied('V11.25.2 Multiempresa: somente o cadastro protegido de Forma de Pagamento está homologado nesta tela.');
  }
}


// V11.35.1/V11.46/V11.47/V11.47.3/V11.48/V11.49/V11.50/V11.51: Documentos fiscais não grava diretamente na própria página; Configurações fiscais aceitam
// exclusivamente manutenção protegida de parâmetros, séries e certificados. Consulta/impressão/cancelamento homologado
// partem de endpoints fiscais protegidos. DDL runtime permanece proibida.
if ($isCrossMultiCompany && in_array($pageLower, ['fiscal_documentos.php','fiscal_configuracoes.php'], true)) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($pageLower === 'fiscal_documentos.php') {
      access_render_denied('V11.51 Multiempresa: a tela Documentos fiscais não aceita gravação direta. NFC-e/NF-e e NFS-e usam fluxos fiscais protegidos em HOMOLOGAÇÃO; Produção e ações ainda não homologadas permanecem bloqueadas.');
    }
    $fiscalSettingsAction = strtolower(trim((string)($_POST['action'] ?? '')));
    $fiscalSettingsAllowed = ['save_config','save_series','toggle_series','save_certificate','activate_certificate','delete_certificate','save_user_series_preferences'];
    if (!in_array($fiscalSettingsAction, $fiscalSettingsAllowed, true)) {
      access_render_denied('V11.35.1 Multiempresa: somente parâmetros fiscais, séries, preferências de série por usuário e certificados podem ser alterados no F11. Busca DF-e e operações SEFAZ continuam bloqueadas.');
    }
    if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
      access_render_denied('Contexto Multiempresa expirado para Configurações fiscais. Atualize a página e tente novamente.');
    }
  }
}

// V11.50/V11.51: NFS-e no F11, exclusivamente em HOMOLOGAÇÃO.
// Preparar/assinar DPS, emitir, recuperar, consultar, imprimir e baixar XMLs usam fiscal_nfse_homologation.
// V11.51 acrescenta cancelamento e download do evento somente para Pronim/GOVBR pelo escopo específico
// fiscal_nfse_cancel_homologation. Produção, Ambiente Nacional sem motor de cancelamento e demais ações ficam bloqueados.
if ($isCrossMultiCompany && in_array($pageLower, ['fiscal_nfse_nacional.php','fiscal_nfse_acao.php','fiscal_nfse_imprimir.php'], true)) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if ($pageLower === 'fiscal_nfse_acao.php') {
    $nfseAction = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
    $nfseAllowed = ['prepare','emit','recover','query','download_dps','download_nfse','cancel','download_cancel_event'];
    if (!in_array($nfseAction, $nfseAllowed, true)) {
      access_render_denied('V11.51 Multiempresa: NFS-e está liberada no F11 somente em HOMOLOGAÇÃO para preparar/assinar DPS, transmitir, recuperar, consultar, imprimir, baixar XML e cancelar NFS-e Pronim/GOVBR. Produção e demais ações continuam bloqueadas.');
    }
    if (in_array($nfseAction, ['cancel','download_cancel_event'], true)) {
      if (!function_exists('multi_company_scope_is_cross_company') || !multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_cancel_homologation')) {
        access_render_denied('V11.51 Multiempresa: cancelamento de NFS-e não está homologado neste contexto F11.');
      }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
        access_render_denied('Contexto Multiempresa expirado para operação NFS-e. Atualize a página e tente novamente.');
      }
    }
  }
}

// V11.49: inutilização de NF-e/NFC-e no F11, exclusivamente em HOMOLOGAÇÃO.
// A página usa a empresa operacional, exige CSRF Multiempresa no POST e não executa DDL em runtime.
if ($isCrossMultiCompany && in_array($pageLower, ['fiscal_inutilizacao.php','fiscal_relatorio_inutilizacoes.php'], true)) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if ($pageLower === 'fiscal_inutilizacao.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
      access_render_denied('Contexto Multiempresa expirado para inutilização fiscal. Atualize a página e tente novamente.');
    }
  }
}

// V11.35/V11.46/V11.47/V11.47.3/V11.48/V11.49/V11.50/V11.51: emissão e eventos fiscais protegidos no F11.
// NFC-e modelo 65 e NF-e modelo 55 podem operar exclusivamente em HOMOLOGAÇÃO.
// V11.47 libera o evento 110111 de cancelamento e V11.48 libera o evento 110110 de CC-e somente para NF-e 55;
// Produção, lote e edição fiscal permanecem bloqueados; V11.49 libera inutilização e V11.50/V11.51 liberam NFS-e protegida e cancelamento Pronim em tela própria.
// Nenhuma DDL runtime é permitida.
if ($isCrossMultiCompany && in_array($pageLower, ['fiscal_nfce_pedido.php','fiscal_nfce_acao.php','fiscal_nfce_imprimir.php'], true)) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if ($pageLower === 'fiscal_nfce_acao.php') {
    $fiscalNfceAction = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
    $fiscalNfceAllowed = ['emitir_rapido','emitir_nfce_rapido','emitir_nfe_rapido','create_preview','create_preview_nfe','authorize_now','consult_status','recover_authorization','download_xml','cancel_document','cce_document'];
    if (!in_array($fiscalNfceAction, $fiscalNfceAllowed, true)) {
      access_render_denied('V11.51 Multiempresa: emissão/consulta de NFC-e/NF-e, cancelamento protegido, CC-e, inutilização e NFS-e protegida (incluindo cancelamento Pronim/GOVBR) estão liberados em HOMOLOGAÇÃO. Produção e lote continuam bloqueados.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
        access_render_denied('Contexto Multiempresa expirado para operação fiscal. Atualize a página e tente novamente.');
      }
    }
  }
}

$financeSupportedPages = [
  'financeiro.php',
  'financeiro_contas_receber.php',
  'financeiro_cartoes.php',
  'financeiro_contas_pagar.php',
  'financeiro_despesas_fixas.php',
  'financeiro_movimentos.php',
  'financeiro_vales.php',
  'financeiro_vales_ajax.php',
  'financeiro_resultado.php',
  'financeiro_caixas_bancos.php',
  'financeiro_itau.php',
  'financeiro_plano_contas.php',
  'financeiro_centros_custo.php',
  'financeiro_formas_pagamento.php',
  'financeiro_recibo_baixa.php',
  'financeiro_recibo_whatsapp.php',
  'financeiro_boletos.php',
  'financeiro_boleto_itau.php',
  'financeiro_boleto_imprimir.php',
  'financeiro_boleto_whatsapp.php',
  'financeiro_conciliacao.php',
];
$financeTitleWritePages = ['financeiro_contas_receber.php','financeiro_contas_pagar.php'];
if ($isCrossMultiCompany && in_array($pageLower, $financeSupportedPages, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $financeAction = strtolower(trim((string)($_POST['action'] ?? 'save')));
  $financePostAllowed = false;

  if (in_array($pageLower, $financeTitleWritePages, true)) {
    $financePostAllowed = in_array($financeAction, ['save','settle','bulk_settle','reverse_settlement','cancel'], true)
      || ($pageLower === 'financeiro_contas_receber.php' && in_array($financeAction, ['waiver_auth','sync_itau_liquidations_period'], true));
  } elseif ($pageLower === 'financeiro_cartoes.php') {
    // V11.52.7: baixa de parcelas do Controle de Cartões, sempre na empresa operacional do F11.
    $financePostAllowed = in_array($financeAction, ['settle'], true);
  } elseif ($pageLower === 'financeiro_caixas_bancos.php') {
    // V11.15: cadastro de Caixa/Banco e configuração básica de Cobrança/Itaú por empresa.
    $financePostAllowed = in_array($financeAction, ['save','delete','itau_validate','itau_test_token','bank_credential_save','bank_credential_disable','bank_service_save','bank_import_legacy','bank_test_itau_oauth'], true);
  } elseif ($pageLower === 'financeiro_itau.php') {
    // V11.20: além da configuração/diagnóstico, o guardião permite chegar às ações
    // de retorno/liquidação. A própria página exige o escopo dedicado, permissões
    // efetivas, CSRF e configuração pertencente à empresa selecionada pelo F11.
    $financePostAllowed = in_array($financeAction, [
      'save_environment','save','enroll_certificate','test_token','prepare_webhook',
      'register_webhook','consult_webhook','reprocess_webhook_logs','save_bank_fee_chart',
      'sync_liquidations','consult_francesa','consult_movements','consult_movements_summary'
    ], true);
  } elseif ($pageLower === 'financeiro_plano_contas.php') {
    $financePostAllowed = in_array($financeAction, ['save','delete','import_model'], true);
  } elseif ($pageLower === 'financeiro_centros_custo.php') {
    $financePostAllowed = in_array($financeAction, ['save','delete'], true);
  } elseif ($pageLower === 'financeiro_despesas_fixas.php') {
    // V11.5: regras recorrentes e recomposição do horizonte, sempre dentro da empresa do F11.
    $financePostAllowed = in_array($financeAction, ['save','toggle','replenish','replenish_all'], true);
  } elseif ($pageLower === 'financeiro_movimentos.php') {
    // V11.54: ajuste de data financeira no F11 permanece sob feature flag desativada por padrao;
    // a propria tela verifica escopo, permissao efetiva, CSRF, status e vinculos financeiros.
    $financePostAllowed = in_array($financeAction, ['save','delete','cancel','adjust_date'], true);
  } elseif ($pageLower === 'financeiro_formas_pagamento.php') {
    // V11.6: somente sobreposição financeira; não altera o cadastro operacional do PDV/NFC-e.
    $financePostAllowed = in_array($financeAction, ['save_defaults','reset_defaults'], true);
  } elseif ($pageLower === 'financeiro_boletos.php') {
    // V11.21: além de Simular/Registrar/Reprocessar/Consultar, libera somente o mesmo
    // cancelamento LOCAL pré-registro já existente na empresa principal. Cobrança já
    // enviada/registrada no banco continua bloqueada pela própria regra do módulo.
    $financePostAllowed = in_array($financeAction, ['simulate_itau','register_itau','reprocess_itau','consult_itau','cancel_local'], true);
  } elseif ($pageLower === 'financeiro_boleto_whatsapp.php') {
    // V11.22: envio externo de boleto pelo WhatsApp, protegido por escopo,
    // permissão efetiva, CSRF e company_id na própria página.
    $financePostAllowed = $financeAction === 'send_boleto_whatsapp';
  } elseif ($pageLower === 'financeiro_recibo_whatsapp.php') {
    // V11.23: envio de recibo da baixa pelo WhatsApp usa o batch já vinculado
    // à empresa do F11, permissão de Receber e CSRF Multiempresa.
    $financePostAllowed = $financeAction === 'send_receipt_whatsapp';
  } elseif ($pageLower === 'financeiro_conciliacao.php') {
    // V11.19: além da importação OFX, conciliação com título existente, Ignorar/Reabrir
    // e Criar título + baixar + conciliar, libera o movimento financeiro direto protegido.
    $financePostAllowed = in_array($financeAction, ['import','reconcile_title','ignore','reopen','create_title','create_movement'], true);
  }

  if (!$financePostAllowed) {
    access_render_denied('V11.23 Multiempresa: esta gravação financeira não está homologada para troca de empresa pelo F11. Conciliação, liquidações Itaú, cancelamento local pré-registro e envio de boleto/recibo por WhatsApp/E-mail já possuem fluxos protegidos; cancelamento bancário de boleto já registrado e demais ações não homologadas continuam separadas.');
  }
}

// Em contexto cruzado, impede que páginas financeiras ainda não homologadas operem
// silenciosamente no COMPANY_ID legado enquanto o cabeçalho exibe outra empresa.
if ($isCrossMultiCompany
    && (str_starts_with($pageLower, 'financeiro_') || $pageLower === 'financeiro.php')
    && !in_array($pageLower, array_merge($financeSupportedPages, ['financeiro_pessoas.php']), true)) {
  access_render_denied('V11.23 Multiempresa: esta função financeira ainda não foi homologada no contexto F11. Configuração Itaú, liquidações, Conciliação, cancelamento local pré-registro e envio de boleto/recibo por WhatsApp/E-mail já possuem escopos protegidos; cancelamento bancário de boleto já registrado e o cadastro operacional de Formas de Pagamento permanecem separados.');
}

// V11.35/V11.46: fail-closed para qualquer página fiscal ainda não homologada no F11.
// As páginas fiscais liberadas em ciclos anteriores possuem permissão explícita acima;
// Documentos permanecem em leitura e Configurações aceitam somente o subconjunto protegido V11.34.
// Qualquer outro endpoint fiscal permanece bloqueado
// quando a aba está mostrando uma empresa diferente da empresa de entrada.
if ($isCrossMultiCompany && str_starts_with($pageLower, 'fiscal_') && $multiCompanyPermission === null) {
  access_render_denied('V11.51 Multiempresa: esta função fiscal ainda não foi homologada no contexto F11. Em HOMOLOGAÇÃO já estão liberados documentos/configurações protegidos, emissão/consulta de NFC-e/NF-e, cancelamento, CC-e, inutilização e NFS-e protegida com cancelamento Pronim/GOVBR.');
}

// V11.36: identidade/cadastro-base do usuário continua pertencendo à empresa de origem.
// Pelo F11 administramos o vínculo/perfil da empresa selecionada; criar/editar a
// identidade global diretamente continua bloqueado para evitar duplicidade ou alteração cruzada.
if ($isCrossMultiCompany && $pageLower === 'usuario_form.php') {
  access_render_denied('V11.36 Multiempresa: pelo F11 altere o acesso/perfil do usuário em Usuários > Editar acesso. A identidade-base (nome, e-mail, senha e empresa de origem) continua sendo mantida na empresa de origem.');
}

// V11.36.3: as manutenções em lote exigem, além da permissão-base de
// Manutenção de Produtos, acesso ao cadastro operacional correspondente.
if ($isCrossMultiCompany && in_array($pageLower, ['utilitarios_manutencao_categorias.php','utilitarios_manutencao_tributacoes.php'], true)) {
  $isMaster = function_exists('access_is_company_master') && access_is_company_master();
  $baseAllowed = $isMaster || (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'manutencao_produtos'));
  $productsAllowed = $isMaster || (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'produtos'));
  $fiscalAllowed = $pageLower !== 'utilitarios_manutencao_tributacoes.php'
    || $isMaster
    || (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'fiscal_tributacoes'));
  if (!$baseAllowed || !$productsAllowed || !$fiscalAllowed) {
    access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui todas as permissões necessárias para esta manutenção em lote.');
  }
}

// Na empresa normal, as páginas novas reaproveitam a permissão já existente
// manutencao_produtos, evitando migration apenas para adicionar novas chaves de menu.
if (!$isCrossMultiCompany && in_array($pageLower, ['utilitarios_manutencao_categorias.php','utilitarios_manutencao_tributacoes.php'], true)) {
  $baseAllowed = function_exists('access_has_permission') && access_has_permission($pdo, (int)COMPANY_ID, 'manutencao_produtos');
  $productsAllowed = function_exists('access_has_permission') && access_has_permission($pdo, (int)COMPANY_ID, 'produtos');
  $fiscalAllowed = $pageLower !== 'utilitarios_manutencao_tributacoes.php'
    || (function_exists('access_has_permission') && access_has_permission($pdo, (int)COMPANY_ID, 'fiscal_tributacoes'));
  if (!$baseAllowed || !$productsAllowed || !$fiscalAllowed) {
    access_render_denied('Seu perfil não possui todas as permissões necessárias para esta manutenção em lote.');
  }
}

// V11.36: os catálogos abaixo são compartilhados por todas as empresas. No F11
// eles podem ser consultados normalmente, mas a manutenção permanece centralizada
// no cadastro padrão para impedir que um POST legado crie uma exceção no tenant de entrada.
$globalFiscalCatalogPages = ['fiscal_cfops.php','fiscal_ncms.php','fiscal_cests.php','fiscal_cst.php','fiscal_ibscbs.php','fiscal_importacoes.php','fiscal_tabelas.php','fiscal_st_uf.php'];
if ($isCrossMultiCompany && in_array($pageLower, $globalFiscalCatalogPages, true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  access_render_denied('V11.36 Multiempresa: este é um catálogo fiscal compartilhado por todas as empresas. Faça inclusões/alterações pela empresa padrão; a consulta permanece disponível em qualquer empresa pelo F11.');
}

// V11.23: no endpoint genérico de e-mail BOLETO e RECIBO estão homologados no F11.
// Pedido, fiscal e outros documentos continuam usando o fluxo legado da empresa de entrada.
if ($isCrossMultiCompany && $pageLower === 'email_documento.php' && !in_array(strtolower(trim((string)($_REQUEST['type'] ?? ''))), ['boleto','recibo'], true)) {
  access_render_denied('V11.23 Multiempresa: neste ciclo somente Boleto e Recibo podem ser enviados por E-mail no contexto F11. Os demais tipos de documento ainda devem ser enviados acessando diretamente a empresa.');
}

if ($isCrossMultiCompany && in_array($pageLower, array_merge(['pedidos.php','pedido_print.php'], $salesPrintingPages ?? []), true)) {
  // Permissão efetiva já validada acima; a página fará também o isolamento por company_id.
} elseif ($isCrossMultiCompany && in_array($pageLower, ['usuarios.php','usuarios_perfis.php','multiempresa_acessos.php'], true) && function_exists('access_is_company_master') && access_is_company_master()) {
  // V11.36: o administrador principal pode administrar vínculos e, no modo isolado,
  // os perfis da empresa selecionada. Cada tela ainda valida seu próprio escopo de gravação.
} elseif ($isDefaultPeoplePage) {
  // Pessoas é transversal a todos os módulos. Só é bloqueada quando o perfil
  // foi explicitamente configurado com a opção Pessoas desabilitada.
  if (!nexa_people_permission_allowed($pdo, $isCrossMultiCompany && defined('MULTI_COMPANY_CONTEXT_ID') ? (int)MULTI_COMPANY_CONTEXT_ID : (int)COMPANY_ID)) {
    access_render_denied('O acesso ao cadastro de Pessoas foi desabilitado para este perfil.');
  }
} elseif ($multiCompanyPermission !== null) {
  if (!function_exists('multi_company_context_permission_allowed')
      || !multi_company_context_permission_allowed($pdo, $multiCompanyPermission)) {
    access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para esta função.');
  }
} elseif ($isCrossMultiCompany && $pageLower === 'fiscal_nfce_editar.php') {
  access_render_denied('V11.46 Multiempresa: edição manual do documento fiscal continua bloqueada no F11. NFC-e modelo 65 e NF-e modelo 55 em HOMOLOGAÇÃO devem usar exclusivamente os fluxos protegidos de emissão, consulta e impressão.');
} else {
  saas_require_admin_page_module($pdo, (int)COMPANY_ID, $page);
  access_require_page($pdo, (int)COMPANY_ID, $page);
}
