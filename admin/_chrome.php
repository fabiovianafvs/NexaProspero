<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/access_control.php';
require_once __DIR__ . '/../config/resellers.php';
require_once __DIR__ . '/../config/brain_realtime.php';
require_once __DIR__ . '/../config/schema_updater.php';
require_once __DIR__ . '/../config/fiscal_certificate_alert.php';

function admin_hex_color(?string $value, string $fallback): string {
  $value = strtoupper(trim((string)$value));
  return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
}

function admin_theme_config(): array {
  $theme = [
    'primary' => '#0B1F5B',
    'secondary' => '#2454D6',
    'accent' => '#00B8FF',
    'gradient' => '#06122E',
    'text' => '#FFFFFF',
    'flow_primary' => '#143A9C',
    'flow_secondary' => '#2563EB',
    'flow_text' => '#FFFFFF',
  ];

  try {
    if (defined('COMPANY_ID') && function_exists('load_business_config')) {
      $biz = load_business_config((int)COMPANY_ID);
      $admin = (array)($biz['admin_theme'] ?? []);
      $flow = (array)($biz['admin_flow_theme'] ?? []);

      if (!empty($admin['enabled'])) {
        $theme['primary'] = admin_hex_color($admin['primary_color'] ?? null, $theme['primary']);
        $theme['secondary'] = admin_hex_color($admin['secondary_color'] ?? null, $theme['secondary']);
        $theme['accent'] = admin_hex_color($admin['accent_color'] ?? null, $theme['accent']);
        $theme['gradient'] = admin_hex_color($admin['gradient_color'] ?? null, $theme['gradient']);
        $theme['text'] = admin_hex_color($admin['text_color'] ?? null, $theme['text']);
      }

      if (!empty($flow['enabled'])) {
        $theme['flow_primary'] = admin_hex_color($flow['primary_color'] ?? null, $theme['flow_primary']);
        $theme['flow_secondary'] = admin_hex_color($flow['secondary_color'] ?? null, $theme['flow_secondary']);
        $theme['flow_text'] = admin_hex_color($flow['text_color'] ?? null, $theme['flow_text']);
      }
    }
  } catch (Throwable $e) {
  }

  return $theme;
}



function admin_side_menu_enabled(): bool {
  // A área de trabalho inicial sempre apresenta o menu lateral.
  if (!empty($GLOBALS['nexa_workspace_force_sidebar'])) return true;
  try {
    if (defined('COMPANY_ID') && function_exists('load_business_config')) {
      $biz = load_business_config((int)COMPANY_ID);
      return !empty($biz['admin_ui']['side_menu_enabled']);
    }
  } catch (Throwable $e) {
  }
  return false;
}

function admin_nav_item_active(string $key, array $item, string $active): bool {
  if ($key === $active) return true;
  if ($key === 'clientes' && in_array($active, ['gestao_clientes','fin_pessoas'], true)) return true;
  $children = isset($item[2]) && is_array($item[2]) ? $item[2] : [];
  foreach ($children as $childKey => $child) {
    if ((string)$childKey === $active) return true;
  }
  return false;
}


/**
 * Remove subtítulos órfãos dos menus filtrados por plano/permissão.
 * Ex.: uma empresa Delivery Pro pode ver Usuários/Perfis sem carregar cabeçalhos vazios
 * de Gestão ou Fiscal no submenu Tabelas.
 */
function admin_nav_clean_children(array $children): array {
  $clean = [];
  $pendingHeader = null;
  $pendingHeaderKey = null;

  foreach ($children as $key => $child) {
    if (str_ends_with((string)$key, '_header')) {
      $pendingHeader = $child;
      $pendingHeaderKey = (string)$key;
      continue;
    }

    if ($pendingHeader !== null && $pendingHeaderKey !== null) {
      $clean[$pendingHeaderKey] = $pendingHeader;
      $pendingHeader = null;
      $pendingHeaderKey = null;
    }
    $clean[$key] = $child;
  }

  return $clean;
}

function admin_nav_render_horizontal(array $links, string $active): void {
  foreach($links as $k=>$it){
    $label = (string)($it[0] ?? '');
    $href = (string)($it[1] ?? '#');
    $children = isset($it[2]) && is_array($it[2]) ? $it[2] : [];
    $cls = admin_nav_item_active((string)$k, $it, $active) ? 'active' : '';
    $externalAttrs = preg_match('~^https?://~i', $href) ? ' target="_blank" rel="noopener noreferrer"' : '';
    if ($children) { ?>
      <span class="nav-dropdown">
        <a class="<?= $cls ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="true" aria-expanded="false"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <span class="nav-submenu">
          <?php foreach ($children as $ck => $child) {
            if (str_ends_with((string)$ck, '_header')) { echo '<span class="nav-subtitle">' . htmlspecialchars((string)$child[0], ENT_QUOTES, 'UTF-8') . '</span>'; continue; }
            $ccls = ((string)$ck === $active) ? 'active' : '';
            ?>
            <a class="<?= $ccls ?>" href="<?= htmlspecialchars((string)$child[1], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$child[0], ENT_QUOTES, 'UTF-8') ?></a>
          <?php } ?>
        </span>
      </span>
    <?php } else { ?>
      <a class="<?= $cls ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php }
  }
}

function admin_nav_render_sidebar(array $links, string $active): void {
  ?>
  <div class="admin-sidebar-head">
    <a class="admin-sidebar-brand" href="area_trabalho.php" aria-label="Nexa Próspero - Início">
      <span class="admin-sidebar-logo"><img src="/assets/logo-nexa-v6-2.png" alt="Nexa Próspero"></span>
      <span><strong>Nexa Próspero</strong><small>Painel administrativo</small></span>
    </a>
    <button class="admin-sidebar-close" type="button" aria-label="Fechar menu" data-admin-menu-close>×</button>
  </div>
  <nav class="admin-sidebar-nav" aria-label="Menu lateral administrativo">
    <?php foreach($links as $k=>$it){
      $label = (string)($it[0] ?? '');
      $href = (string)($it[1] ?? '#');
      $children = isset($it[2]) && is_array($it[2]) ? $it[2] : [];
      $cls = admin_nav_item_active((string)$k, $it, $active) ? 'active' : '';
      $externalAttrs = preg_match('~^https?://~i', $href) ? ' target="_blank" rel="noopener noreferrer"' : '';
      if ($children) { ?>
        <section class="admin-side-group <?= trim($cls . ' ' . ($cls ? 'is-open' : '')) ?>">
          <a class="admin-side-main <?= $cls ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?> data-admin-side-parent aria-expanded="<?= $cls ? 'true' : 'false' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
          <div class="admin-side-children">
            <?php foreach ($children as $ck => $child) {
              if (str_ends_with((string)$ck, '_header')) { echo '<span class="admin-side-subtitle">' . htmlspecialchars((string)$child[0], ENT_QUOTES, 'UTF-8') . '</span>'; continue; }
              $ccls = ((string)$ck === $active) ? 'active' : '';
              ?>
              <a class="<?= $ccls ?>" href="<?= htmlspecialchars((string)$child[1], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$child[0], ENT_QUOTES, 'UTF-8') ?></a>
            <?php } ?>
          </div>
        </section>
      <?php } else { ?>
        <a class="admin-side-link <?= $cls ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php }
    } ?>
    <a class="admin-side-link admin-side-logout" href="logout.php">Sair</a>
  </nav>
  <?php
}

/**
 * Monta os itens usados somente pelo atalho de pesquisa do menu.
 * O menu original não é alterado: a pesquisa apenas reaproveita a lista
 * já filtrada por plano/permissões dentro de admin_topbar().
 */
function admin_nav_search_items(array $links): array {
  $items = [];

  foreach ($links as $key => $it) {
    $label = trim((string)($it[0] ?? ''));
    $href = trim((string)($it[1] ?? '#'));
    $children = isset($it[2]) && is_array($it[2]) ? $it[2] : [];

    if ($label !== '' && $href !== '' && $href !== '#') {
      $items[] = [
        'label' => $label,
        'href' => $href,
        'group' => '',
        'search' => $label,
      ];
    }

    foreach ($children as $childKey => $child) {
      if (str_ends_with((string)$childKey, '_header')) continue;
      $childLabel = trim((string)($child[0] ?? ''));
      $childHref = trim((string)($child[1] ?? '#'));
      if ($childLabel === '' || $childHref === '' || $childHref === '#') continue;

      $items[] = [
        'label' => $childLabel,
        'href' => $childHref,
        'group' => $label,
        'search' => trim($label . ' ' . $childLabel),
      ];
    }
  }

  return $items;
}

function admin_nav_render_search(array $links): void {
  $items = admin_nav_search_items($links);
  if (!$items) return;
  ?>
  <div class="admin-menu-search" data-admin-menu-search>
    <div class="admin-menu-search-field">
      <svg class="admin-menu-search-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <input type="search" class="admin-menu-search-input" placeholder="Pesquisar menu..." aria-label="Pesquisar menu do sistema" aria-autocomplete="list" aria-expanded="false" autocomplete="off" spellcheck="false" data-admin-menu-search-input>
    </div>
    <div class="admin-menu-search-results" role="listbox" aria-label="Resultados da pesquisa de menus" hidden data-admin-menu-search-results>
      <?php foreach ($items as $idx => $item):
        $externalAttrs = preg_match('~^https?://~i', (string)$item['href']) ? ' target="_blank" rel="noopener noreferrer"' : '';
      ?>
        <a href="<?= htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>
           role="option"
           class="admin-menu-search-item"
           data-admin-menu-search-item
           data-menu-label="<?= htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') ?>"
           data-menu-group="<?= htmlspecialchars((string)$item['group'], ENT_QUOTES, 'UTF-8') ?>"
           data-menu-search="<?= htmlspecialchars((string)$item['search'], ENT_QUOTES, 'UTF-8') ?>"
           data-menu-order="<?= (int)$idx ?>"
           hidden>
          <span class="admin-menu-search-item-label"><?= htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ((string)$item['group'] !== ''): ?>
            <small><?= htmlspecialchars((string)$item['group'], ENT_QUOTES, 'UTF-8') ?></small>
          <?php else: ?>
            <small>Menu principal</small>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <div class="admin-menu-search-empty" data-admin-menu-search-empty hidden>Nenhum menu encontrado.</div>
      <div class="admin-menu-search-hint">↑ ↓ navegar &nbsp; Enter abrir &nbsp; Esc fechar</div>
    </div>
  </div>
  <?php
}

function admin_topbar(string $title, string $active=''): void {
  $links = [
    'inicio'     => ['Início','area_trabalho.php'],
    'dashboard'  => ['Dashboard','dashboard.php'],
    'brain'      => ['Brain','brain.php'],
    'pedidos'    => ['Pedidos','pedidos.php'],
    'produtos'   => ['Produtos','produtos.php'],
    'clientes'   => ['Pessoas','financeiro_pessoas.php'],
    'export'     => ['Exportação','exportacao.php'],
    'api'        => ['API','api.php'],
    'whatsapp'   => ['WhatsApp','whatsapp.php', [
      'whatsapp' => ['WhatsApp', 'whatsapp.php'],
      'whatsapp_modelos' => ['Modelo de Mensagens', 'whatsapp_modelos.php'],
      'whatsapp_fila' => ['Fila de Envio', 'whatsapp_fila.php'],
    ]],
    'orcamento' => ['Orçamento','orcamentos.php', [
      'orcamentos' => ['Lista de Orçamentos', 'orcamentos.php'],
      'orcamento_novo' => ['Novo Orçamento', 'orcamento_editar.php'],
    ]],
    'crm' => ['CRM','crm_atendimentos.php', [
      'crm_atendimentos' => ['Atendimento', 'crm_atendimentos.php'],
      'crm_kanban' => ['Kanban', 'crm_kanban.php'],
      'crm_atendimento_mobile' => ['Atendimento Mobile', 'crm_atendimento_mobile.php'],
    ]],
    'controle_entregas' => ['Entregas','delivery_control.php', [
      'delivery_control' => ['Controle de Entregas', 'delivery_control.php'],
      'delivery_orders' => ['Ordens de Entrega', 'delivery_orders.php'],
      'delivery_manifest' => ['Romaneios', 'delivery_manifest.php'],
      'delivery_vehicles' => ['Veículos', 'delivery_vehicles.php'],
      'delivery_fiscal_queue' => ['Fila Fiscal', 'delivery_fiscal_queue.php'],
    ]],
    'contratos' => ['Contratos','contratos.php', [
      'contratos_monitor' => ['Monitor de Contratos', 'contratos.php'],
      'contratos_gerar' => ['Gerar Contratos', 'contratos_gerar.php'],
      'contratos_reajustes' => ['Reajustes', 'contratos_reajustes.php'],
    ]],
    'compras' => ['Compras', 'compras_listas.php', [
      'compras_assistente' => ['Assistente de Compras', 'compras_assistente.php'],
      'compras_listas' => ['Listas de Compras', 'compras_listas.php'],
      'compras_cotacoes' => ['Cotações de Compras', 'compras_cotacoes.php'],
      'compras_pedidos' => ['Pedidos de Compra', 'compras_pedidos.php'],
      'compras_painel' => ['Painel de Compras', 'compras_painel.php'],
      'compras_alertas' => ['Alertas de Compras', 'compras_alertas.php'],
      'compras_fornecedores_avaliacao' => ['Avaliação de Fornecedores', 'compras_fornecedores_avaliacao.php'],
      'compras_fornecedores_ranking' => ['Ranking de Fornecedores', 'compras_fornecedores_ranking.php'],
      'compras_historico_precos' => ['Histórico de Preços', 'compras_historico_precos.php'],
    ]],
    'ordem_servico' => ['Ordem de Serviço','os_monitor.php', [
      'os_monitor' => ['Monitor de O.S.', 'os_monitor.php'],
      'os_mobile' => ['O.S. Mobile', 'os_mobile.php'],
      'os_faturas' => ['Faturamento em Lote', 'os_faturas.php'],
    ]],
    'producao' => ['Produção','producao_monitor.php', [
      'producao_ficha' => ['Ficha Técnica', 'producao_ficha_tecnica.php'],
      'producao_monitor' => ['Monitor de Produção', 'producao_monitor.php'],
    ]],
    'gestao'     => ['Vendas','gestao.php', [
      'gestao_dashboard' => ['Dashboard vendas', 'gestao.php'],
      'gestao_pdv_resumido' => ['PDV Resumido', 'gestao_pdv_resumido.php'],
      'gestao_pdv_checkout' => ['PDV Checkout', 'gestao_pdv_checkout.php'],
      'gestao_pdv' => ['Pedido PDV', 'gestao_pdv.php'],
      'gestao_pdv_mobile' => ['Venda Mobile', 'gestao_pdv_mobile.php'],
      'gestao_comandas' => ['Comandas', 'gestao_comandas.php'],
      'gestao_comandas_checkout' => ['Comandas Checkout', 'gestao_comandas_checkout.php'],
      'gestao_comandas_mobile' => ['Comanda Mobile', 'gestao_comandas_mobile.php'],
      'gestao_pedido_detalhado' => ['Pedido Detalhado', 'gestao_pedido_detalhado.php'],
      'gestao_pedidos' => ['Consulta de pedidos', 'pedidos.php'],
      'gestao_caixa' => ['Caixa', 'gestao_caixa.php'],
    ]],
    'financeiro' => ['Financeiro','financeiro.php', [
      'fin_dashboard' => ['Dashboard financeiro', 'financeiro.php'],
      'fin_pessoas' => ['Pessoas', 'financeiro_pessoas.php'],
      'fin_receber' => ['Contas a receber', 'financeiro_contas_receber.php'],
      'fin_cartoes' => ['Controle de Cartões', 'financeiro_cartoes.php'],
      'fin_pagar' => ['Contas a pagar', 'financeiro_contas_pagar.php'],
      'fin_fixas' => ['Despesas fixas', 'financeiro_despesas_fixas.php'],
      'fin_mov' => ['Movimento financeiro', 'financeiro_movimentos.php'],
      'fin_vales' => ['Controle de Vales', 'financeiro_vales.php'],
      'fin_boletos' => ['Cobranças/Boletos', 'financeiro_boletos.php'],
      'fin_conciliacao' => ['Conciliação Bancária', 'financeiro_conciliacao.php'],
      'fin_resultado' => ['Resultado por plano', 'financeiro_resultado.php'],
      'fin_aux_header' => ['Tabelas Auxiliares', '#'],
      'fin_plano' => ['Plano de Contas', 'financeiro_plano_contas.php'],
      'fin_centro' => ['Centro de Custo', 'financeiro_centros_custo.php'],
      'fin_caixas' => ['Caixas e Bancos', 'financeiro_caixas_bancos.php'],
    ]],
    'entradas'   => ['Entradas','fiscal_radar_xml.php', [
      'fiscal_radar_xml' => ['RADAR NF-e', 'fiscal_radar_xml.php'],
      'fiscal_entrada_notas' => ['Entrada de Notas', 'fiscal_entrada_notas.php'],
      'fiscal_devolucoes' => ['Devolução / Outras Entradas', 'fiscal_devolucoes.php'],
      'fiscal_devolucao_cliente' => ['Nova devolução de cliente', 'fiscal_devolucao_cliente.php'],
      'fiscal_importar_xml' => ['Importar XML', 'fiscal_importar_xml.php'],
      'fiscal_nota_manual' => ['Entrada manual', 'fiscal_nota_manual.php'],
      'estoque_conferencias' => ['Conferência de estoque', 'estoque_conferencias.php'],
    ]],
    'fiscal'     => ['Fiscal','fiscal_documentos.php', [
      'fiscal_documentos' => ['Documentos fiscais', 'fiscal_documentos.php'],
      'os_fiscal_documentos' => ['Documentos fiscais da O.S.', 'os_fiscal_documentos.php'],
      'fiscal_inutilizacao' => ['Inutilização de numeração', 'fiscal_inutilizacao.php'],
      'fiscal_saidas' => ['Saídas fiscais', 'fiscal_saidas.php'],
      'fiscal_contadores' => ['Cadastro de contadores', 'fiscal_contadores.php'],
      'fiscal_xml_conversoes' => ['Conversões XML', 'fiscal_xml_conversoes.php'],
    ]],
    'tabelas'    => ['Tabelas','#', [
      'gestao_header' => ['Cadastros de Gestão', '#'],
      'gestao_vendedores' => ['Cadastro de Vendedores', 'gestao_vendedores.php'],
      'gestao_comandas_config' => ['Cadastro / Configuração de Comandas', 'gestao_comandas_config.php'],
      'gestao_comandas_qrcode' => ['QR Codes das Mesas', 'gestao_comandas_qrcode.php'],
      'gestao_comandas_impressao' => ['Monitor de Impressão', 'gestao_comandas_impressao.php'],
      'gestao_comandas_agentes' => ['Agentes de Impressão', 'gestao_comandas_agentes.php'],
      'gestao_pagamentos' => ['Formas de Pagamento', 'gestao_formas_pagamento.php'],
      'fiscal_header' => ['Tabelas Fiscais', '#'],
      'fiscal_operacoes' => ['Operações fiscais', 'fiscal_operacoes.php'],
      'fiscal_tributacoes' => ['Tributações', 'fiscal_tributacoes.php'],
      'fiscal_cfops' => ['CFOP', 'fiscal_cfops.php'],
      'fiscal_ncms' => ['NCM', 'fiscal_ncms.php'],
      'fiscal_cests' => ['CEST', 'fiscal_cests.php'],
      'fiscal_cst' => ['CST/CSOSN', 'fiscal_cst.php'],
      'fiscal_ibscbs' => ['IBS/CBS', 'fiscal_ibscbs.php'],
      'fiscal_importacoes' => ['Tabelas fiscais', 'fiscal_importacoes.php'],
      'usuarios' => ['Usuários', 'usuarios.php'],
      'usuarios_perfis' => ['Perfis de acesso', 'usuarios_perfis.php'],
    ]],
    'relatorios' => ['Relatórios','relatorio_analise_dashboard.php', [
      'relatorio_analise_dashboard' => ['Análise Dashboard', 'relatorio_analise_dashboard.php'],
      'relatorio_comissao_vendedores' => ['Comissão de vendedores', 'relatorio_comissao_vendedores.php'],
      'relatorio_etiquetas_produtos' => ['Etiqueta de produtos', 'relatorio_etiquetas_produtos.php'],
      'relatorio_clientes' => ['Clientes', 'relatorio_clientes.php'],
      'relatorio_movimentos_caixa' => ['Movimentações de caixa', 'relatorio_movimentos_caixa.php'],
      'relatorio_inadimplencia' => ['Análise de inadimplência', 'relatorio_inadimplencia.php'],
      'relatorio_fluxo_caixa' => ['Fluxo de Caixa', 'relatorio_fluxo_caixa.php'],
    ]],
    'fiscal_relatorios' => ['Relatórios Fiscais','fiscal_relatorio_documentos.php', [
      'fiscal_relatorios' => ['Visão Geral', 'fiscal_relatorios.php'],
      'fiscal_relatorios_documentos' => ['Documentos Fiscais', 'fiscal_relatorio_documentos.php'],
      'fiscal_sintegra' => ['Gerar Sintegra', 'fiscal_relatorio_sintegra.php'],
      'fiscal_sped_icms' => ['Gerar SPED ICMS/IPI', 'fiscal_relatorio_sped_icms.php'],
      'fiscal_sped_contrib' => ['Gerar SPED Contribuições', 'fiscal_relatorio_sped_contrib.php'],
      'fiscal_exportar_xml' => ['Exportar XMLs', 'fiscal_exportar_xml.php'],
      'fiscal_inventario' => ['Inventário', 'fiscal_relatorio_inventario.php'],
      'fiscal_contabilidade' => ['Documentos para Contabilidade', 'fiscal_contabilidade.php'],
    ]],
    'utilitarios' => ['Utilitários','utilitarios_manutencao_produtos.php', [
      'manutencao_produtos' => ['Manutenção de Produtos', 'utilitarios_manutencao_produtos.php'],
      'manutencao_categorias' => ['Manutenção de Categorias', 'utilitarios_manutencao_categorias.php'],
      'manutencao_tributacoes' => ['Manutenção de Tributações', 'utilitarios_manutencao_tributacoes.php'],
      'recuperar_vendedores' => ['Recuperar Vendedores de Vendas', 'utilitarios_recuperar_vendedores.php'],
      'regularizacao_nfe_rejeitada' => ['Regularização de NF-e rejeitada', 'utilitarios_regularizacao_nfe.php'],
    ]],
    'params'     => ['Configurações','parametros.php', [
      'params' => ['Configurações gerais', 'parametros.php'],
      'fiscal_configuracoes' => ['Configurações fiscais', 'fiscal_configuracoes.php'],
      'orcamento_configuracoes' => ['Configurações do Orçamento', 'orcamento_configuracoes.php'],
      'os_configuracoes' => ['Configurações da O.S.', 'os_configuracoes.php'],
      'producao_configuracoes' => ['Configurações de O.P.', 'producao_configuracoes.php'],
      'configuracoes_cre' => ['Configurações CRE', 'configuracoes_cre.php'],
      'configuracoes_cpa' => ['Configurações CPA', 'configuracoes_cpa.php'],
      'configuracoes_caixa' => ['Configurações de caixa', 'configuracoes_caixa.php'],
      'configuracoes_desempenho' => ['Desempenho e períodos', 'configuracoes_desempenho.php'],
    ]],
    'suporte'    => ['Suporte','https://wa.me/5531985732002?text=Gostaria%20de%20suporte%20para%20o%20Nexa%20Pr%C3%B3spero.'],
    'importacao' => ['Importação','importacao.php'],
  ];

  if (defined('COMPANY_ID')) {
    $pdoAdmin = $GLOBALS['pdo'] ?? null;
    if ($pdoAdmin instanceof PDO) {
      $filtered = [];
      foreach ($links as $key => $it) {
        // Início é apenas a área de trabalho: sempre presente após autenticação,
        // mesmo em perfis sem nenhum dashboard liberado.
        if ((string)$key === 'inicio') {
          $filtered[$key] = $it;
          continue;
        }
        $childrenSource = isset($it[2]) && is_array($it[2]) ? $it[2] : [];

        if ($childrenSource) {
          $children = [];
          $isCrossV11_25 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
            && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;

          // V11.30: o submenu Vendas no F11 reflete somente as rotinas já homologadas
          // (Dashboard, Pedido PDV, Venda Mobile, PDV Resumido, Comandas, Comanda Mobile, Pedido Detalhado, Consulta de Pedidos e Caixa)
          // e as permissões efetivas da empresa selecionada. Não usa o perfil legado da empresa de entrada.
          if ($isCrossV11_25 && (string)$key === 'gestao' && function_exists('multi_company_context_permission_allowed')) {
            foreach ($childrenSource as $childKey => $child) {
              $childKey = (string)$childKey;
              $allowed = false;
              if ($childKey === 'gestao_dashboard') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_dashboard');
              elseif ($childKey === 'gestao_pdv') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pdv');
              elseif ($childKey === 'gestao_pdv_checkout') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pdv_checkout');
              elseif ($childKey === 'gestao_pdv_mobile') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pdv_mobile');
              elseif ($childKey === 'gestao_pdv_resumido') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pdv_resumido');
              elseif ($childKey === 'gestao_comandas') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_comandas');
              elseif ($childKey === 'gestao_comandas_checkout') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_comandas');
              elseif ($childKey === 'gestao_comandas_mobile') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_comandas_mobile');
              elseif ($childKey === 'gestao_pedido_detalhado') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pedido_detalhado');
              elseif ($childKey === 'gestao_caixa') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_caixa');
              elseif ($childKey === 'gestao_pedidos') {
                $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pedidos')
                  || multi_company_context_permission_allowed($pdoAdmin, 'pedidos');
              }
              if (!$allowed) continue;
              if (function_exists('multi_company_context_url') && isset($child[1])) {
                $child[1] = multi_company_context_url((string)$child[1]);
              }
              $children[$childKey] = $child;
            }
          } elseif ($isCrossV11_25 && (string)$key === 'fiscal' && function_exists('multi_company_context_permission_allowed')) {
            // V11.33: primeira etapa do Fiscal no F11 é somente consulta.
            foreach ($childrenSource as $childKey => $child) {
              $childKey = (string)$childKey;
              $allowed = false;
              if ($childKey === 'fiscal_documentos') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'fiscal_documentos');
              elseif ($childKey === 'fiscal_configuracoes') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'fiscal_configuracoes');
              if (!$allowed) continue;
              if (function_exists('multi_company_context_url') && isset($child[1])) $child[1] = multi_company_context_url((string)$child[1]);
              $children[$childKey] = $child;
            }
          } elseif ((string)$key === 'utilitarios') {
            // V11.36.3: novas manutenções em lote reutilizam a permissão já existente
            // Manutenção de Produtos. No F11 somente as páginas novas, já homologadas
            // com company_id operacional, são expostas; a manutenção antiga permanece
            // restrita à empresa de entrada até ser auditada separadamente.
            foreach ($childrenSource as $childKey => $child) {
              $childKey = (string)$childKey;
              $allowed = false;
              // Disponibilizar apenas ao administrador da propria empresa, sem F11.
              if ($childKey === 'regularizacao_nfe_rejeitada') {
                $allowed = !$isCrossV11_25 && function_exists('access_is_company_master') && access_is_company_master();
                if ($allowed) $children[$childKey] = $child;
                continue;
              }
              if ($isCrossV11_25 && function_exists('multi_company_context_permission_allowed')) {
                $baseAllowed = (function_exists('access_is_company_master') && access_is_company_master())
                  || multi_company_context_permission_allowed($pdoAdmin, 'manutencao_produtos');
                $productsAllowed = (function_exists('access_is_company_master') && access_is_company_master())
                  || multi_company_context_permission_allowed($pdoAdmin, 'produtos');
                if ($childKey === 'manutencao_categorias') {
                  $allowed = $baseAllowed && $productsAllowed;
                } elseif ($childKey === 'manutencao_tributacoes') {
                  $fiscalAllowed = (function_exists('access_is_company_master') && access_is_company_master())
                    || multi_company_context_permission_allowed($pdoAdmin, 'fiscal_tributacoes');
                  $allowed = $baseAllowed && $productsAllowed && $fiscalAllowed;
                }
                if ($allowed && function_exists('multi_company_context_url') && isset($child[1])) {
                  $child[1] = multi_company_context_url((string)$child[1]);
                }
              } else {
                if ($childKey === 'manutencao_produtos') {
                  $allowed = saas_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, $childKey)
                    && (!function_exists('access_admin_nav_allowed') || access_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, $childKey));
                } elseif ($childKey === 'recuperar_vendedores') {
                  // Ferramenta histórica limitada à empresa de login (não operacional no F11).
                  $allowed = (function_exists('access_is_company_master') && access_is_company_master())
                    || ((function_exists('access_has_permission') && access_has_permission($pdoAdmin, (int)COMPANY_ID, 'manutencao_produtos'))
                      && (!function_exists('saas_admin_nav_allowed') || saas_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, 'utilitarios')));
                } elseif (in_array($childKey, ['manutencao_categorias','manutencao_tributacoes'], true)) {
                  $baseAllowed = function_exists('access_has_permission') && access_has_permission($pdoAdmin, (int)COMPANY_ID, 'manutencao_produtos');
                  $productsAllowed = function_exists('access_has_permission') && access_has_permission($pdoAdmin, (int)COMPANY_ID, 'produtos');
                  $allowed = $baseAllowed && $productsAllowed;
                  if ($childKey === 'manutencao_tributacoes') {
                    $allowed = $allowed && function_exists('access_has_permission') && access_has_permission($pdoAdmin, (int)COMPANY_ID, 'fiscal_tributacoes');
                  }
                }
              }
              if ($allowed) $children[$childKey] = $child;
            }
          } elseif ($isCrossV11_25 && (string)$key === 'tabelas' && function_exists('multi_company_context_permission_allowed')) {
            // V11.36: Tabelas no F11 usa empresa/perfil efetivos. Operações e Tributações
            // continuam isoladas por empresa; catálogos oficiais permanecem globais/compartilhados;
            // Usuários administra vínculos e Perfis permite CRUD quando o modo é isolado.
            foreach ($childrenSource as $childKey => $child) {
              $childKey = (string)$childKey;
              if (str_ends_with($childKey, '_header')) {
                $children[$childKey] = $child;
                continue;
              }
              $allowed = false;
              if ($childKey === 'gestao_pagamentos') $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_pagamentos');
              elseif (in_array($childKey, ['gestao_comandas_config','gestao_comandas_qrcode','gestao_comandas_impressao','gestao_comandas_agentes'], true)) {
                $companyOfContext = defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID > 0
                  ? (int)MULTI_COMPANY_CONTEXT_ID : (int)COMPANY_ID;
                $allowed = multi_company_context_permission_allowed($pdoAdmin, 'gestao_comandas')
                  && saas_company_has_module($pdoAdmin, $companyOfContext, 'comandas');
              } elseif ($childKey === 'fiscal_operacoes') {
                $allowed = multi_company_context_permission_allowed($pdoAdmin, 'fiscal_operacoes');
              } elseif ($childKey === 'fiscal_tributacoes') {
                $allowed = multi_company_context_permission_allowed($pdoAdmin, 'fiscal_tributacoes');
              } elseif (in_array($childKey, ['fiscal_cfops','fiscal_ncms','fiscal_cests','fiscal_cst','fiscal_ibscbs','fiscal_importacoes'], true)) {
                // Catálogos compartilhados: aparecem em qualquer empresa que possua o módulo
                // e a permissão fiscal_tabelas. O POST cruzado continua centralizado na empresa padrão.
                $allowed = multi_company_context_permission_allowed($pdoAdmin, 'fiscal_tabelas');
              } elseif ($childKey === 'usuarios') {
                $allowed = (function_exists('access_is_company_master') && access_is_company_master())
                  || multi_company_context_permission_allowed($pdoAdmin, 'usuarios');
              } elseif ($childKey === 'usuarios_perfis') {
                $allowed = (function_exists('access_is_company_master') && access_is_company_master())
                  || multi_company_context_permission_allowed($pdoAdmin, 'usuarios_perfis');
              }
              if (!$allowed) continue;
              if (function_exists('multi_company_context_url') && isset($child[1])) $child[1] = multi_company_context_url((string)$child[1]);
              $children[$childKey] = $child;
            }
          } else {
            foreach ($childrenSource as $childKey => $child) {
              if (str_ends_with((string)$childKey, '_header')) {
                $children[$childKey] = $child;
                continue;
              }
              // V11.45.2: novas telas reaproveitam permissões já existentes,
              // sem criar novos tokens nem exigir migração de perfis.
              if ((string)$key === 'relatorios' && (string)$childKey === 'relatorio_movimentos_caixa' && $isCrossV11_25) continue;
              if ((string)$key === 'relatorios' && (string)$childKey === 'relatorio_inadimplencia' && $isCrossV11_25) continue;
              if ((string)$key === 'relatorios' && (string)$childKey === 'relatorio_fluxo_caixa' && $isCrossV11_25) continue;
              if ((string)$key === 'relatorios' && (string)$childKey === 'relatorio_clientes') {
                if (function_exists('nexa_people_permission_allowed') && nexa_people_permission_allowed($pdoAdmin, (int)COMPANY_ID)) $children[$childKey] = $child;
                continue;
              }
              if ((string)$key === 'params' && (string)$childKey === 'configuracoes_desempenho') {
                $perfAllowed = (function_exists('access_is_company_master') && access_is_company_master())
                  || (function_exists('access_has_permission') && access_has_permission($pdoAdmin, (int)COMPANY_ID, 'configuracoes'));
                if ($perfAllowed) $children[$childKey] = $child;
                continue;
              }
              $permissionChildKey = ((string)$key === 'gestao' && (string)$childKey === 'gestao_comandas_checkout') ? 'gestao_comandas' : (((string)$key === 'crm' && (string)$childKey === 'crm_atendimento_mobile') ? 'crm_atendimentos' : (string)$childKey);
              if (!saas_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, $permissionChildKey)) continue;
              if (function_exists('access_admin_nav_allowed') && !access_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, $permissionChildKey)) continue;
              $children[$childKey] = $child;
            }
          }

          // Pessoas é opt-out em todo o sistema. Se o perfil bloquear
          // explicitamente Pessoas, remove também o atalho que possa existir
          // dentro do submenu Financeiro, independentemente das permissões
          // financeiras legadas.
          if (isset($children['fin_pessoas']) && function_exists('nexa_people_permission_allowed')) {
            $peopleMenuCompanyId = ($isCrossV11_25 && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID > 0)
              ? (int)MULTI_COMPANY_CONTEXT_ID : (int)COMPANY_ID;
            if (!nexa_people_permission_allowed($pdoAdmin, $peopleMenuCompanyId)) unset($children['fin_pessoas']);
          }

          $children = admin_nav_clean_children($children);
          if (!$children) continue;

          $it[2] = $children;
          // O link do menu pai sempre aponta para a primeira opção realmente
          // liberada ao perfil. Assim, um usuário com apenas Comanda Mobile,
          // Contas a Receber ou outra função isolada não cai em tela bloqueada.
          foreach ($children as $childKey => $child) {
            if (str_ends_with((string)$childKey, '_header')) continue;
            $it[1] = (string)($child[1] ?? '#');
            break;
          }

          $filtered[$key] = $it;
          continue;
        }

        // V11.36.2: Pessoas é um cadastro transversal e aparece por padrão
        // independentemente do módulo contratado. Perfis antigos permanecem com
        // acesso; somente o bloqueio explícito no perfil remove este menu.
        if ((string)$key === 'clientes' && function_exists('nexa_people_permission_allowed')) {
          $peopleCompanyId = (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
            && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID > 0)
            ? (int)MULTI_COMPANY_CONTEXT_ID : (int)COMPANY_ID;
          if (!nexa_people_permission_allowed($pdoAdmin, $peopleCompanyId)) continue;
          if (function_exists('multi_company_context_url') && isset($it[1])) $it[1] = multi_company_context_url((string)$it[1]);
          $filtered[$key] = $it;
          continue;
        }

        // V7: Produtos foi o primeiro cadastro operacional a refletir o perfil
        // efetivo da empresa selecionada no F11. Pessoas agora usa a regra padrão
        // transversal acima.
        if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
            && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID
            && (string)$key === 'produtos'
            && function_exists('multi_company_context_permission_allowed')) {
          if (!multi_company_context_permission_allowed($pdoAdmin, 'produtos')) continue;
          $filtered[$key] = $it;
          continue;
        }

        if (!saas_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, (string)$key)) continue;
        if (function_exists('access_admin_nav_allowed') && !access_admin_nav_allowed($pdoAdmin, (int)COMPANY_ID, (string)$key)) continue;
        $filtered[$key] = $it;
      }
      $links = $filtered;
      try {
        $companyReseller = reseller_company_by_id($pdoAdmin, (int)COMPANY_ID);
        if ($companyReseller && reseller_is_active_company($companyReseller) && access_is_company_master()) {
          $links['revenda'] = ['Painel da Revenda', '/revenda/'];
        }
      } catch (Throwable $e) {
      }
    }
  }

  // V11.45: ferramentas globais de manutenção aparecem somente para o
  // administrador da plataforma e depois dos filtros de empresa/plano.
  if (function_exists('auth_role') && auth_role() === 'platform_admin') {
    if (isset($links['params'][2]) && is_array($links['params'][2])) {
      $links['params'][2]['atualizador_banco'] = ['Atualizador do Banco', 'atualizador_banco.php'];
      $links['params'][2]['backup_banco'] = ['Backup do Banco', 'backup_banco.php'];
    } else {
      $links['atualizador_banco'] = ['Atualizador do Banco', 'atualizador_banco.php'];
      $links['backup_banco'] = ['Backup do Banco', 'backup_banco.php'];
    }
  }

  // Importação, quando habilitada, fica imediatamente antes de Sair.
  $importacaoLink = $links['importacao'] ?? null;
  unset($links['importacao']);
  if (is_array($importacaoLink)) $links['importacao'] = $importacaoLink;

  // V4 Multiempresa: prepara somente a interface F11 e a identificação do
  // contexto paralelo. COMPANY_ID e as permissões operacionais continuam legados.
  $multiCompanyUi = [
    'enabled' => false,
    'can_switch' => false,
    'entry_company_id' => defined('COMPANY_ID') ? (int)COMPANY_ID : 0,
    'context_company_id' => defined('MULTI_COMPANY_CONTEXT_ID') ? (int)MULTI_COMPANY_CONTEXT_ID : (defined('COMPANY_ID') ? (int)COMPANY_ID : 0),
    'entry_company_name' => '',
    'context_company_name' => '',
    'token' => '',
    'csrf' => '',
    'companies' => [],
    'operation_mode' => '',
  ];
  try {
    $pdoMulti = $GLOBALS['pdo'] ?? null;
    $entryIdMulti = (int)$multiCompanyUi['entry_company_id'];
    $userIdMulti = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $roleMulti = function_exists('auth_role') ? (string)auth_role() : '';
    $authCompanyMulti = function_exists('auth_company_id') ? (int)(auth_company_id() ?? 0) : 0;
    if ($pdoMulti instanceof PDO && $entryIdMulti > 0 && $userIdMulti > 0
        && in_array($roleMulti, ['company_admin','company_user'], true)
        && $authCompanyMulti === $entryIdMulti
        && function_exists('multi_company_feature_enabled')
        && multi_company_feature_enabled($pdoMulti, $entryIdMulti)) {
      $availableMulti = multi_company_context_available_companies($pdoMulti, $userIdMulti, $entryIdMulti);
      $entryRowMulti = multi_company_context_company_row($pdoMulti, $entryIdMulti);
      $contextIdMulti = (int)$multiCompanyUi['context_company_id'];
      $contextRowMulti = multi_company_context_company_row($pdoMulti, $contextIdMulti);
      $ctxGlobalMulti = is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? $GLOBALS['MULTI_COMPANY_CONTEXT'] : [];
      $multiCompanyUi['enabled'] = true;
      $multiCompanyUi['can_switch'] = count($availableMulti) > 1;
      $multiCompanyUi['entry_company_name'] = (string)($entryRowMulti['name'] ?? ('Empresa ' . $entryIdMulti));
      $multiCompanyUi['context_company_name'] = (string)($contextRowMulti['name'] ?? ('Empresa ' . $contextIdMulti));
      $multiCompanyUi['token'] = !empty($ctxGlobalMulti['valid']) ? (string)($ctxGlobalMulti['token'] ?? '') : '';
      $multiCompanyUi['csrf'] = function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
      $multiCompanyUi['operation_mode'] = (string)($ctxGlobalMulti['operation_mode'] ?? '');
      $multiCompanyUi['companies'] = array_map(static function(array $row): array {
        $validation = is_array($row['context_validation'] ?? null) ? $row['context_validation'] : [];
        return [
          'id' => (int)($row['company_id'] ?? 0),
          'name' => (string)($row['company_name'] ?? ''),
          'is_default' => !empty($validation['is_default']),
          'operation_mode' => (string)($validation['operation_mode'] ?? ''),
        ];
      }, $availableMulti);
    }
  } catch (Throwable $e) {
    // Fail closed: qualquer inconsistência deixa o F11 invisível e o Nexa legado.
    $multiCompanyUi['enabled'] = false;
    $multiCompanyUi['can_switch'] = false;
    $multiCompanyUi['companies'] = [];
  }

  $dbUpdaterPending = 0;
  try {
    if (function_exists('auth_role') && auth_role() === 'platform_admin'
        && function_exists('nexa_schema_updater_pending_count')) {
      $pdoUpdater = $GLOBALS['pdo'] ?? null;
      if ($pdoUpdater instanceof PDO) $dbUpdaterPending = nexa_schema_updater_pending_count($pdoUpdater);
    }
  } catch (Throwable $e) {
    $dbUpdaterPending = 0;
  }

  $theme = admin_theme_config();
  $adminSideMenu = admin_side_menu_enabled();
  $adminCompactMobile = !empty($GLOBALS['admin_compact_mobile_mode']);
  $adminCompactMobileShowMenu = $adminCompactMobile && !empty($GLOBALS['admin_compact_mobile_show_menu']);
  $adminCompactSideMenu = $adminCompactMobileShowMenu;
  $adminSideMenuLayout = $adminSideMenu && !$adminCompactSideMenu;
  $bodyClassValue = trim(($adminSideMenuLayout ? 'admin-side-menu-enabled' : '') . ($adminCompactMobile ? ' admin-compact-mobile' : '') . ($adminCompactMobileShowMenu ? ' admin-compact-mobile-menu' : '') . ($adminCompactSideMenu ? ' admin-compact-side-menu' : ''));
  $bodyClass = $bodyClassValue !== '' ? ' class="' . htmlspecialchars($bodyClassValue, ENT_QUOTES, 'UTF-8') . '"' : '';
  ?>
<!doctype html><html lang="pt-br"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/admin.css?v=27">
<script defer src="/assets/admin.js?v=16"></script>
<!-- LGPD: nas buscas, CPF/CNPJ aparece só com os 3 primeiros e os 3 últimos dígitos. -->
<script>window.nexaMaskDoc=function(v){v=String(v==null?'':v);var d=v.replace(/\D/g,'');if(d.length<7)return v;var i=0,n=d.length;return v.replace(/\d/g,function(c){var k=i++;return(k<3||k>=n-3)?c:'*'});};</script>
<!-- Nexa: desabilita o historico de entradas em campos de digitacao, inclusive campos criados por modais. -->
<script defer src="/assets/nexa-no-form-history.js?v=20261028-1"></script>
<!-- Ícones Nexa: recursos isolados, sem alterar os scripts do PDV ou a navegação existente. -->
<link rel="stylesheet" href="/assets/nexa-menu-icons.css?v=20261020-04">
<script defer src="/assets/nexa-menu-icons.js?v=20261020-04"></script>
<?php if (!empty($multiCompanyUi['enabled'])): ?>
<script>window.NEXA_MULTI_COMPANY_CONTEXT = <?= json_encode([
  'enabled' => true,
  'canSwitch' => !empty($multiCompanyUi['can_switch']),
  'entryCompanyId' => (int)$multiCompanyUi['entry_company_id'],
  'contextCompanyId' => (int)$multiCompanyUi['context_company_id'],
  'token' => (string)$multiCompanyUi['token'],
  'csrf' => (string)$multiCompanyUi['csrf'],
  'endpoint' => '/admin/multiempresa_context_switch.php',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script defer src="/assets/multi-company-context.js?v=20260916-4"></script>
<?php endif; ?>
<style>
:root{
  --admin-primary: <?= htmlspecialchars($theme['primary'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-secondary: <?= htmlspecialchars($theme['secondary'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-accent: <?= htmlspecialchars($theme['accent'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-gradient: <?= htmlspecialchars($theme['gradient'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-text: <?= htmlspecialchars($theme['text'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-flow-primary: <?= htmlspecialchars($theme['flow_primary'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-flow-secondary: <?= htmlspecialchars($theme['flow_secondary'], ENT_QUOTES, 'UTF-8') ?>;
  --admin-flow-text: <?= htmlspecialchars($theme['flow_text'], ENT_QUOTES, 'UTF-8') ?>;
  --c: var(--admin-primary);
}
body{
  padding:0 !important;
  min-height:100vh;
  background:#f6f8fc;
  -webkit-font-smoothing:antialiased;
}
.topbar{
  position:sticky;
  top:0;
  z-index:80;
  min-height:76px;
  padding:14px 18px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:14px;
  background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary) 62%,var(--admin-gradient)) !important;
  color:var(--admin-text) !important;
  box-shadow:0 14px 34px rgba(11,31,91,.14);
}
.admin-brand{
  min-width:0;
  display:flex;
  align-items:center;
  gap:12px;
  font-weight:950;
  letter-spacing:-.02em;
}
.admin-brand-logo{
  width:42px;
  height:42px;
  border-radius:14px;
  display:grid;
  place-items:center;
  flex:0 0 auto;
  background:#fff;
  border:1px solid rgba(255,255,255,.22);
  box-shadow:0 10px 24px rgba(0,0,0,.14), inset 0 1px 0 rgba(255,255,255,.18);
  overflow:hidden;
}
.admin-brand-logo img{
  width:100%;
  height:100%;
  object-fit:contain;
  padding:3px;
  display:block;
}
.admin-brand-title{
  display:block;
  max-width:420px;
  overflow:hidden;
  white-space:nowrap;
  text-overflow:ellipsis;
}
.navlinks{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
  justify-content:flex-end;
}
.navlinks a{
  color:var(--admin-text);
  text-decoration:none;
  opacity:.94;
  padding:9px 12px;
  border-radius:14px;
  font-weight:820;
  transition:background .16s ease, transform .16s ease, opacity .16s ease;
}
.navlinks a:hover{
  opacity:1;
  background:rgba(255,255,255,.12);
  text-decoration:none;
}
.navlinks a.active{
  background:rgba(255,255,255,.18)!important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.14);
}

.nav-dropdown{position:relative;display:inline-flex;align-items:center}
.nav-dropdown > a::after{content:' ▾';font-size:11px;opacity:.85}
.nav-submenu{
  display:none;
  position:absolute;
  top:100%;
  right:0;
  min-width:260px;
  padding:8px;
  border-radius:18px;
  border:1px solid rgba(219,232,247,.96);
  background:rgba(255,255,255,.98);
  box-shadow:0 22px 60px rgba(11,31,91,.22);
  z-index:120;
}
.nav-dropdown.is-open .nav-submenu{display:block}
.nav-submenu a{display:block;color:#0f172a!important;background:#fff;opacity:1;border-radius:12px;padding:10px 12px;white-space:nowrap}
.nav-submenu a:hover{background:#f1f5f9;text-decoration:none}
.nav-submenu a.active{color:var(--admin-primary)!important;background:#eff6ff!important}
.nav-submenu .nav-subtitle{padding:9px 12px 5px;color:#64748b;font-size:11px;text-transform:uppercase;font-weight:950;letter-spacing:.06em}
.admin-menu-toggle{
  display:none;
  width:44px;
  height:44px;
  border:1px solid rgba(255,255,255,.22);
  border-radius:16px;
  background:rgba(255,255,255,.12);
  color:var(--admin-text);
  font-size:24px;
  line-height:1;
  cursor:pointer;
}
.admin-company-switch{position:relative;display:flex;align-items:center;flex:0 0 auto;z-index:146}
.admin-company-switch-btn{display:flex;align-items:center;gap:10px;max-width:390px;min-width:330px;height:44px;padding:6px 10px 6px 12px;border:1px solid rgba(255,255,255,.34);border-radius:15px;background:rgba(255,255,255,.13);color:var(--admin-text);cursor:pointer;box-shadow:0 8px 24px rgba(6,18,46,.10)}
.admin-company-switch-btn:hover{background:rgba(255,255,255,.20)}
.admin-company-switch-copy{min-width:0;text-align:left;line-height:1.08}
.admin-company-switch-copy small{display:block;font-size:9px;font-weight:850;opacity:.72;text-transform:uppercase;letter-spacing:.055em}
.admin-company-switch-copy strong{display:block;max-width:270px;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;font-weight:950}
.admin-company-switch-key{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:27px;padding:0 7px;border-radius:9px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.24);font-size:10px;font-weight:950}
.admin-company-switch-preop{display:inline-flex;align-items:center;margin-left:1px;padding:3px 6px;border-radius:999px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:8px;font-weight:950;white-space:nowrap}
.admin-company-modal[hidden]{display:none!important}.admin-company-modal{position:fixed;inset:0;z-index:300;display:grid;place-items:center;padding:18px}.admin-company-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.58);backdrop-filter:blur(3px)}
.admin-company-modal-card{position:relative;z-index:1;width:min(560px,calc(100vw - 28px));max-height:min(720px,calc(100vh - 32px));overflow:auto;border:1px solid #dbe4f0;border-radius:24px;background:#fff;box-shadow:0 28px 90px rgba(2,6,23,.32);color:#0f172a}
.admin-company-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:18px 18px 14px;border-bottom:1px solid #e2e8f0}.admin-company-modal-head h2{margin:0;font-size:19px;color:#0b1f5b}.admin-company-modal-head p{margin:5px 0 0;color:#64748b;font-size:12px;line-height:1.45}.admin-company-modal-close{width:36px;height:36px;border:1px solid #dbe4f0;border-radius:12px;background:#fff;color:#0f172a;font-size:22px;cursor:pointer}
.admin-company-modal-list{display:grid;gap:8px;padding:14px 18px}.admin-company-choice{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;width:100%;padding:13px 14px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;color:#0f172a;text-align:left;cursor:pointer}.admin-company-choice:hover,.admin-company-choice.is-current{background:#eff6ff;border-color:#bfdbfe}.admin-company-choice strong{display:block;font-size:13px}.admin-company-choice small{display:block;margin-top:4px;color:#64748b;font-size:11px}.admin-company-choice-badge{padding:5px 8px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-size:9px;font-weight:950;white-space:nowrap}.admin-company-choice.is-current .admin-company-choice-badge{background:#dcfce7;color:#166534}
.admin-company-modal-status{min-height:20px;padding:0 18px 10px;color:#b45309;font-size:12px;font-weight:800}.admin-company-modal-foot{padding:12px 18px 16px;border-top:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:11px;line-height:1.45;border-radius:0 0 24px 24px}
body.admin-company-modal-open{overflow:hidden}
@media(max-width:1180px){.admin-company-switch-btn{min-width:260px;max-width:320px}.admin-company-switch-copy strong{max-width:200px}.admin-company-switch-preop{display:none}}
@media(max-width:920px){.admin-company-switch-btn{min-width:0;max-width:230px}.admin-company-switch-copy small{display:none}.admin-company-switch-copy strong{max-width:150px}.admin-company-switch-key{min-width:32px}}
@media(max-width:620px){.admin-company-switch{display:none}}

.admin-menu-search{
  display:none;
  position:relative;
  width:clamp(240px,28vw,390px);
  margin-left:auto;
  flex:0 1 390px;
  z-index:145;
}
body.admin-side-menu-enabled .admin-menu-search{display:block;}
.admin-menu-search-field{position:relative;display:flex;align-items:center;}
.admin-menu-search-icon{
  position:absolute;left:15px;top:50%;transform:translateY(-50%);z-index:2;width:18px;height:18px;pointer-events:none;
  color:rgba(15,23,42,.62);
}
.admin-menu-search-field .admin-menu-search-input{
  width:100%;height:44px;min-height:44px!important;padding:0 18px 0 48px!important;border:1px solid rgba(255,255,255,.40)!important;
  border-radius:15px;background:rgba(255,255,255,.96);color:#0f172a;font:inherit;font-size:14px;font-weight:760;
  outline:none;box-shadow:0 8px 24px rgba(6,18,46,.12);transition:border-color .15s ease,box-shadow .15s ease,background .15s ease;
}
.admin-menu-search-input::placeholder{color:#64748b;font-weight:650;}
.admin-menu-search-input:focus{
  background:#fff;border-color:#bfdbfe!important;box-shadow:0 0 0 4px rgba(255,255,255,.18),0 12px 30px rgba(6,18,46,.18)!important;
}
.admin-menu-search-results{
  position:absolute;top:calc(100% + 8px);right:0;width:min(440px,calc(100vw - 28px));max-height:min(440px,calc(100vh - 105px));
  overflow:auto;padding:7px;border:1px solid #dbe4f0;border-radius:17px;background:#fff;box-shadow:0 22px 60px rgba(6,18,46,.24);color:#0f172a;
}
.admin-menu-search-item{
  display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 12px;border-radius:12px;color:#0f172a!important;
  text-decoration:none!important;border:1px solid transparent;background:#fff;cursor:pointer;
}
.admin-menu-search-item[hidden]{display:none!important;}
.admin-menu-search-item:hover,.admin-menu-search-item.is-active{
  background:#eff6ff;border-color:#bfdbfe;color:var(--admin-primary)!important;
}
.admin-menu-search-item-label{font-size:13px;font-weight:900;line-height:1.25;}
.admin-menu-search-item small{max-width:42%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;font-size:10.5px;font-weight:750;text-align:right;}
.admin-menu-search-empty{padding:15px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:750;}
.admin-menu-search-hint{padding:7px 9px 3px;border-top:1px solid #edf2f7;margin-top:5px;color:#94a3b8;font-size:9.5px;font-weight:750;text-align:center;}
.admin-backdrop{
  display:none;
  position:fixed;
  inset:0;
  z-index:70;
  background:rgba(15,23,42,.46);
  backdrop-filter:blur(2px);
}
.container{
  max-width:1240px;
  width:100%;
  margin:0 auto;
  padding:18px;
}
.btn-primary{
  background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary)) !important;
  border-color:transparent !important;
  color:var(--admin-text) !important;
}
a.idlink{color:var(--admin-primary)}
input:focus,textarea:focus,select:focus{
  border-color:var(--admin-secondary) !important;
  box-shadow:0 0 0 4px color-mix(in srgb, var(--admin-secondary) 16%, transparent) !important;
}
.cardhead strong,.cardhead [style*="font-weight:900"], h2, h3, h4{color:var(--admin-primary)}

.nav-dropdown::before{content:"";position:absolute;left:0;right:0;top:100%;height:10px;display:block}
body.admin-side-menu-enabled .topbar{border-radius:0;}
.admin-sidebar{display:none;}
body.admin-side-menu-enabled .navlinks{display:none;}
body.admin-side-menu-enabled .admin-menu-toggle{display:grid;place-items:center;}
body.admin-side-menu-enabled .admin-sidebar{
  display:flex;
  flex-direction:column;
  position:fixed;
  inset:0 auto 0 0;
  width:292px;
  z-index:110;
  background:linear-gradient(180deg,var(--admin-primary),var(--admin-secondary) 48%,var(--admin-gradient));
  color:var(--admin-text);
  box-shadow:18px 0 54px rgba(15,23,42,.18);
  overflow:hidden;
  transition:transform .22s ease;
}
.admin-sidebar-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 16px 14px;border-bottom:1px solid rgba(255,255,255,.12)}
.admin-sidebar-brand{display:flex;align-items:center;gap:12px;color:inherit;text-decoration:none;min-width:0}
.admin-sidebar-logo{width:46px;height:46px;border-radius:16px;background:#fff;display:grid;place-items:center;overflow:hidden;box-shadow:0 12px 28px rgba(0,0,0,.18)}
.admin-sidebar-logo img{width:100%;height:100%;object-fit:contain;padding:3px;display:block}
.admin-sidebar-brand strong{display:block;font-size:15px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-sidebar-brand small{display:block;margin-top:3px;font-size:11px;color:rgba(255,255,255,.72);font-weight:700}
.admin-sidebar-close{display:none;width:38px;height:38px;border:1px solid rgba(255,255,255,.18);border-radius:14px;background:rgba(255,255,255,.10);color:var(--admin-text);font-size:24px;line-height:1;cursor:pointer}
.admin-sidebar-nav{padding:12px 10px 20px;overflow:auto;display:flex;flex-direction:column;gap:6px}
.admin-side-link,.admin-side-main,.admin-side-children a{display:flex;align-items:center;gap:8px;text-decoration:none;border-radius:14px;font-weight:850;line-height:1.2}
.admin-side-link,.admin-side-main{color:rgba(255,255,255,.92);padding:11px 12px;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.08)}
.admin-side-main::after{content:'▾';margin-left:auto;font-size:11px;opacity:.82;transition:transform .18s ease}
.admin-side-group.is-open > .admin-side-main::after{transform:rotate(180deg)}
.admin-side-link:hover,.admin-side-main:hover{background:rgba(255,255,255,.12);color:#fff;text-decoration:none}
.admin-side-link.active,.admin-side-main.active{background:rgba(255,255,255,.18);color:#fff;box-shadow:inset 4px 0 0 var(--admin-accent)}
.admin-side-group{display:block;border-radius:18px;padding:0}
.admin-side-children{display:none;flex-direction:column;gap:4px;margin:6px 0 8px 12px;padding-left:10px;border-left:1px solid rgba(255,255,255,.16)}
.admin-side-group.is-open > .admin-side-children{display:flex}
.admin-side-children a{color:rgba(255,255,255,.78);padding:8px 10px;font-size:13px;background:transparent}
.admin-side-children a:hover{color:#fff;background:rgba(255,255,255,.10);text-decoration:none}
.admin-side-children a.active{color:#fff;background:rgba(255,255,255,.16);font-weight:950}
.admin-side-subtitle{display:block;padding:9px 10px 4px;color:rgba(255,255,255,.52);font-size:10px;text-transform:uppercase;font-weight:950;letter-spacing:.07em}
.admin-side-logout{margin-top:8px;color:#fee2e2!important;background:rgba(239,68,68,.13)!important;border-color:rgba(254,202,202,.20)!important}
@media(min-width:921px){
  body.admin-side-menu-enabled .topbar{margin-left:292px;min-height:68px;padding:12px 18px;transition:margin-left .22s ease;}
  body.admin-side-menu-enabled .admin-brand-logo{display:none;}
  body.admin-side-menu-enabled .admin-menu-toggle{display:grid;place-items:center;}
  body.admin-side-menu-enabled .container{max-width:none;margin-left:292px;padding:18px 22px;width:auto;transition:margin-left .22s ease;}
  body.admin-side-menu-enabled .admin-footer{max-width:none;margin-left:292px;transition:margin-left .22s ease;}
  body.admin-side-menu-enabled.admin-sidebar-collapsed .admin-sidebar{transform:translateX(-106%);}
  body.admin-side-menu-enabled.admin-sidebar-collapsed .topbar{margin-left:0;}
  body.admin-side-menu-enabled.admin-sidebar-collapsed .container{max-width:1240px;width:100%;margin-left:auto;margin-right:auto;padding:18px;}
  body.admin-side-menu-enabled.admin-sidebar-collapsed .admin-footer{max-width:1240px;margin-left:auto;margin-right:auto;}
  body.admin-side-menu-enabled.admin-sidebar-collapsed .admin-brand-logo{display:grid;}
}

@media(max-width:920px){
  .topbar{
    min-height:68px;
    padding:12px 14px;
  }
  .admin-menu-search{display:block;width:clamp(120px,30vw,240px);flex:0 1 240px;}
  .admin-menu-search-field .admin-menu-search-input{height:42px;min-height:42px!important;padding:0 14px 0 42px!important;font-size:13px;}
  .admin-menu-search-icon{left:14px;width:17px;height:17px;}
  .admin-brand-title{
    max-width:calc(100vw - 120px);
    font-size:15px;
  }
  .admin-menu-toggle{
    display:grid;
    place-items:center;
  }
  .navlinks{
    position:fixed;
    z-index:90;
    top:78px;
    left:12px;
    right:12px;
    max-height:calc(100vh - 96px);
    overflow:auto;
    display:flex;
    flex-direction:column;
    align-items:stretch;
    gap:8px;
    padding:12px;
    border-radius:22px;
    border:1px solid rgba(219,232,247,.96);
    background:rgba(255,255,255,.98);
    box-shadow:0 24px 72px rgba(11,31,91,.24);
    transform:translateY(-10px) scale(.985);
    opacity:0;
    pointer-events:none;
    transition:opacity .18s ease, transform .18s ease;
  }
  .navlinks a{
    color:#0f172a;
    opacity:1;
    padding:13px 14px;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.8);
    background:#f8fbff;
  }
  .navlinks a.active{
    color:var(--admin-primary);
    background:color-mix(in srgb, var(--admin-secondary) 10%, #fff) !important;
    border-color:color-mix(in srgb, var(--admin-secondary) 28%, #fff);
  }

  .nav-dropdown{display:block;width:100%}
  .nav-dropdown > a{display:block;width:100%}
  .nav-dropdown > a::after{content:' ▾'}
  .nav-submenu{position:static;display:none;min-width:0;margin:6px 0 0;padding:6px;border-radius:16px;box-shadow:none;background:#fff;border:1px solid rgba(226,232,240,.9)}
  .nav-dropdown.is-open .nav-submenu{display:block}
  .nav-submenu a{color:#0f172a!important;background:#f8fbff;margin-bottom:5px}
  body.admin-menu-open .navlinks{
    transform:translateY(0) scale(1);
    opacity:1;
    pointer-events:auto;
  }
  body.admin-menu-open .admin-backdrop{
    display:block;
  }
  .container{
    padding:14px;
  }

  body.admin-side-menu-enabled .navlinks{display:none!important;}
  body.admin-side-menu-enabled .admin-sidebar{
    width:min(86vw,326px);
    transform:translateX(-106%);
    transition:transform .22s ease;
  }
  body.admin-side-menu-enabled.admin-menu-open .admin-sidebar{transform:translateX(0);}
  body.admin-side-menu-enabled .admin-sidebar-close{display:grid;place-items:center;}
  body.admin-side-menu-enabled .admin-backdrop{z-index:100;}
  body.admin-side-menu-enabled.admin-menu-open .admin-backdrop{display:block;}

}

@media(max-width:520px){
  .topbar{border-radius:0 0 22px 22px}
  .admin-brand-logo{width:36px;height:36px;border-radius:12px}
  .admin-brand-title{font-size:14px}
  .navlinks{top:72px}
}

.admin-db-update-alert{
  display:inline-flex;align-items:center;gap:7px;padding:9px 11px;border-radius:13px;
  border:1px solid #fcd34d;background:#fffbeb;color:#92400e!important;text-decoration:none!important;
  font-size:12px;font-weight:950;white-space:nowrap;box-shadow:0 8px 22px rgba(146,64,14,.08);
}
.admin-db-update-alert::before{content:'!';display:grid;place-items:center;width:19px;height:19px;border-radius:999px;background:#f59e0b;color:#fff;font-size:11px}
.admin-db-update-alert:hover{background:#fef3c7}
@media(max-width:1120px){.admin-db-update-alert span{display:none}.admin-db-update-alert{padding:9px}}

.admin-footer{
  max-width:1240px;
  margin:18px auto 0;
  padding:18px;
  text-align:center;
  color:#64748b;
  font-size:13px;
}
.admin-footer a{
  color:var(--admin-primary);
  font-weight:900;
  text-decoration:none;
}
.admin-footer a:hover{text-decoration:underline;}
@media(max-width:920px){.admin-footer{padding:16px 14px 22px}}
body.admin-compact-mobile{background:#f3f7ff;overscroll-behavior-y:contain;}
body.admin-compact-mobile .container{max-width:540px;width:100%;margin:0 auto;padding:0 10px 96px;}
body.admin-compact-mobile .admin-footer{display:none!important;}
@media(max-width:560px){body.admin-compact-mobile .container{max-width:none;padding:0 8px 96px;}}
body.admin-compact-mobile-menu .container{padding-top:58px;}
.admin-compact-menu-toggle{display:none;}
body.admin-compact-mobile-menu .admin-compact-menu-toggle{
  position:fixed;right:10px;left:auto;top:9px;z-index:115;display:grid;place-items:center;width:44px;height:44px;
  border:1px solid rgba(255,255,255,.34);border-radius:15px;background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));
  color:#fff;font-size:23px;font-weight:950;box-shadow:0 12px 30px rgba(11,31,91,.28);cursor:pointer;
}
body.admin-compact-mobile-menu .admin-compact-nav{
  position:fixed;z-index:110;top:61px;left:8px;right:8px;width:auto;max-width:520px;margin:0 auto;
  max-height:calc(100dvh - 74px);overflow:auto;display:flex;flex-direction:column;align-items:stretch;gap:7px;
  padding:10px;border-radius:22px;border:1px solid #dbe4f0;background:rgba(255,255,255,.99);
  box-shadow:0 24px 72px rgba(11,31,91,.28);transform:translateY(-10px) scale(.985);opacity:0;pointer-events:none;
  transition:opacity .18s ease,transform .18s ease;
}
body.admin-compact-mobile-menu.admin-menu-open .admin-compact-nav{transform:none;opacity:1;pointer-events:auto;}
body.admin-compact-mobile-menu .admin-compact-nav>a,
body.admin-compact-mobile-menu .admin-compact-nav>.nav-dropdown>a{display:block;color:#0f172a!important;opacity:1;padding:12px 13px;border-radius:15px;border:1px solid #e2e8f0;background:#f8fbff;}
body.admin-compact-mobile-menu .admin-compact-nav a.active{color:var(--admin-primary)!important;background:#eff6ff!important;border-color:#bfdbfe;}
body.admin-compact-mobile-menu .admin-compact-nav .nav-dropdown{display:block;width:100%;}
body.admin-compact-mobile-menu .admin-compact-nav .nav-submenu{position:static;display:none;min-width:0;margin:6px 0 0;padding:6px;border-radius:15px;box-shadow:none;background:#fff;border:1px solid #e2e8f0;}
body.admin-compact-mobile-menu .admin-compact-nav .nav-dropdown.is-open .nav-submenu{display:block;}
body.admin-compact-mobile-menu .admin-compact-nav .nav-submenu a{display:block;color:#0f172a!important;background:#f8fbff;margin-bottom:5px;}
body.admin-compact-mobile-menu.admin-menu-open .admin-backdrop{display:block;z-index:105;}

body.admin-compact-side-menu .admin-sidebar{
  display:flex;flex-direction:column;position:fixed;inset:0 auto 0 0;width:min(86vw,326px);z-index:110;
  background:linear-gradient(180deg,var(--admin-primary),var(--admin-secondary) 48%,var(--admin-gradient));color:var(--admin-text);
  box-shadow:18px 0 54px rgba(15,23,42,.24);overflow:hidden;transform:translateX(-106%);transition:transform .22s ease;
}
body.admin-compact-side-menu.admin-menu-open .admin-sidebar{transform:translateX(0);}
body.admin-compact-side-menu .admin-sidebar-close{display:grid;place-items:center;}
body.admin-compact-side-menu.admin-menu-open .admin-backdrop{display:block;z-index:105;}
body.admin-compact-side-menu .container{margin-left:auto!important;margin-right:auto!important;width:100%!important;}
body.admin-compact-side-menu .admin-compact-nav{display:none!important;}
@media(min-width:921px){
  body.admin-compact-side-menu .admin-sidebar{width:min(390px,42vw);}
}

</style>
</head><body<?= $bodyClass ?>>
<?php if (!$adminCompactMobile || $adminCompactMobileShowMenu): ?>
<div class="admin-backdrop" data-admin-menu-close></div>
<?php if ($adminSideMenuLayout || $adminCompactSideMenu): ?>
  <aside class="admin-sidebar" data-admin-sidebar>
    <?php admin_nav_render_sidebar($links, $active); ?>
  </aside>
<?php endif; ?>
<?php if ($adminCompactMobileShowMenu): ?>
  <button class="admin-compact-menu-toggle" type="button" aria-label="Abrir menu do sistema" aria-expanded="false" data-admin-menu-toggle>☰</button>
  <?php if (!$adminCompactSideMenu): ?>
    <nav class="navlinks admin-compact-nav" aria-label="Menu administrativo">
      <?php admin_nav_render_horizontal($links, $active); ?>
      <a href="logout.php">Sair</a>
    </nav>
  <?php endif; ?>
<?php else: ?>
<header class="topbar">
  <a class="admin-brand" href="area_trabalho.php" aria-label="Nexa Próspero - Início" style="color:inherit;text-decoration:none;">
    <span class="admin-brand-logo">
      <img src="/assets/logo-nexa-v6-2.png" alt="Nexa Próspero">
    </span>
    <strong class="admin-brand-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></strong>
  </a>

  <?php if (!empty($multiCompanyUi['can_switch'])): ?>
  <div class="admin-company-switch">
    <button class="admin-company-switch-btn" type="button" aria-haspopup="dialog" aria-expanded="false" data-mc-switch-open title="Trocar contexto de empresa nesta aba (F11)">
      <span class="admin-company-switch-copy">
        <small>Empresa · Multiempresa</small>
        <strong><?= htmlspecialchars((string)$multiCompanyUi['context_company_name'], ENT_QUOTES, 'UTF-8') ?></strong>
      </span>
      <span class="admin-company-switch-preop">pré-operacional</span>
      <span class="admin-company-switch-key">F11</span>
    </button>
  </div>
  <?php endif; ?>

  <?php if ($dbUpdaterPending > 0): ?>
    <a class="admin-db-update-alert" href="atualizador_banco.php" title="Há atualização de banco pendente"><span>Banco: <?= (int)$dbUpdaterPending ?> pendente<?= $dbUpdaterPending === 1 ? '' : 's' ?></span></a>
  <?php endif; ?>

  <?php admin_nav_render_search($links); ?>

  <button class="admin-menu-toggle" type="button" aria-label="Abrir menu" aria-expanded="false" data-admin-menu-toggle>☰</button>

  <?php if (!$adminSideMenuLayout): ?>
    <nav class="navlinks" aria-label="Menu administrativo">
      <?php admin_nav_render_horizontal($links, $active); ?>
      <a href="logout.php">Sair</a>
    </nav>
  <?php endif; ?>
</header>
<?php endif; ?>
<?php endif; ?>
<?php if (!empty($multiCompanyUi['can_switch'])): ?>
<div class="admin-company-modal" role="dialog" aria-modal="true" aria-labelledby="adminCompanyModalTitle" hidden data-mc-switch-modal>
  <div class="admin-company-modal-backdrop" data-mc-switch-close></div>
  <div class="admin-company-modal-card">
    <div class="admin-company-modal-head">
      <div>
        <h2 id="adminCompanyModalTitle">Trocar empresa desta aba</h2>
        <p>Selecione uma empresa autorizada. A tecla F11 abre esta janela.</p>
      </div>
      <button class="admin-company-modal-close" type="button" aria-label="Fechar" data-mc-switch-close>×</button>
    </div>
    <div class="admin-company-modal-list">
      <?php foreach ((array)$multiCompanyUi['companies'] as $mcCompany):
        $mcCompanyId = (int)($mcCompany['id'] ?? 0);
        $mcIsCurrent = $mcCompanyId === (int)$multiCompanyUi['context_company_id'];
        $mcMode = (string)($mcCompany['operation_mode'] ?? '');
      ?>
      <button class="admin-company-choice<?= $mcIsCurrent ? ' is-current' : '' ?>" type="button" data-mc-company-choice data-company-id="<?= $mcCompanyId ?>" data-company-name="<?= htmlspecialchars((string)($mcCompany['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <span>
          <strong><?= htmlspecialchars((string)($mcCompany['name'] ?? ('Empresa ' . $mcCompanyId)), ENT_QUOTES, 'UTF-8') ?></strong>
          <small>ID <?= $mcCompanyId ?> · <?= $mcMode === 'integrated' ? 'Grupo Integrado' : 'Grupo Isolado' ?><?= !empty($mcCompany['is_default']) ? ' · Empresa padrão' : '' ?></small>
        </span>
        <span class="admin-company-choice-badge"><?= $mcIsCurrent ? 'Atual' : 'Selecionar' ?></span>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="admin-company-modal-status" data-mc-switch-status></div>
    <div class="admin-company-modal-foot"><strong>Ativação progressiva:</strong> Dashboard, Cadastros, Estoque e Financeiro já usam a empresa desta aba. Em Vendas já estão liberados Dashboard, Consulta de Pedidos, Pedido PDV, Venda Mobile, PDV Resumido, Caixa, Comandas e Pedido Detalhado; produção, O.S. e emissão fiscal continuam protegidos até homologação própria.</div>
  </div>
</div>
<?php endif; ?>
<div class="container">
<?php }

function admin_footer(): void {
  global $pdo;
  $nexaRealtimeConfig = ['enabled' => false, 'url' => '', 'token' => '', 'companyId' => 0];
  $deliveryOrderAlertEnabled = false;
  $certificateAlert = null;
  $certificateAlertSettings = null;
  $certificateAlertConfigToken = '';
  $isParametrosPage = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? ''))) === 'parametros.php';

  try {
    if (isset($pdo) && $pdo instanceof PDO && defined('COMPANY_ID') && (int)COMPANY_ID > 0 && function_exists('nexa_fiscal_certificate_alert_context')) {
      $certCompanyId = (int)COMPANY_ID;
      if (function_exists('multi_company_operational_company_id')) {
        $certCompanyId = (int)multi_company_operational_company_id($pdo, 'fiscal_settings_read');
      } elseif (function_exists('multi_company_context_id')) {
        $certCompanyId = (int)multi_company_context_id();
      }
      $certificateAlert = nexa_fiscal_certificate_alert_context($pdo, $certCompanyId);
    }
  } catch (Throwable $e) {
    $certificateAlert = null;
  }

  try {
    if (defined('COMPANY_ID') && (int)COMPANY_ID > 0) {
      if (function_exists('load_business_config')) {
        $deliveryAlertBiz = load_business_config((int)COMPANY_ID);
        $deliveryOrderAlertEnabled = !empty($deliveryAlertBiz['delivery_alerts']['new_order_enabled']);
      }
      if ($deliveryOrderAlertEnabled && function_exists('nexa_realtime_browser_config')) {
        $userId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
        $nexaRealtimeConfig = nexa_realtime_browser_config((int)COMPANY_ID, $userId);
      }
    }
  } catch (Throwable $e) {
    $deliveryOrderAlertEnabled = false;
    $nexaRealtimeConfig = ['enabled' => false, 'url' => '', 'token' => '', 'companyId' => defined('COMPANY_ID') ? (int)COMPANY_ID : 0];
  }
  $nexaRealtimeConfig['features'] = [
    'deliveryNewOrder' => $deliveryOrderAlertEnabled,
  ];

  if ($isParametrosPage && isset($pdo) && $pdo instanceof PDO && defined('COMPANY_ID') && (int)COMPANY_ID > 0 && function_exists('nexa_cert_alert_settings')) {
    try { $certificateAlertSettings = nexa_cert_alert_settings($pdo, (int)COMPANY_ID); } catch (Throwable $e) { $certificateAlertSettings = null; }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (session_status() === PHP_SESSION_ACTIVE) {
      if (empty($_SESSION['nexa_cert_alert_config_csrf']) || !is_string($_SESSION['nexa_cert_alert_config_csrf'])) {
        try { $_SESSION['nexa_cert_alert_config_csrf'] = bin2hex(random_bytes(24)); }
        catch (Throwable $e) { $_SESSION['nexa_cert_alert_config_csrf'] = hash('sha256', session_id() . '|nexa-cert-alert'); }
      }
      $certificateAlertConfigToken = (string)$_SESSION['nexa_cert_alert_config_csrf'];
    }
  }
  ?>
<?php if ($isParametrosPage && is_array($certificateAlertSettings)): ?>
<div class="card card-pad" style="margin-top:18px;">
  <div class="cardhead">
    <div>
      <div style="font-weight:900;">Aviso de vencimento do certificado digital</div>
      <div class="hint">Define com quantos dias de antecedência o sistema deve avisar e o link usado no botão para aquisição/contato.</div>
    </div>
  </div>
  <?php if (!empty($_GET['cert_alert_saved'])): ?><div class="msg ok">Configuração do aviso de certificado atualizada.</div><?php endif; ?>
  <?php if (!empty($_GET['cert_alert_error'])): ?><div class="msg err">Não foi possível salvar o aviso do certificado. Confira o link informado e tente novamente.</div><?php endif; ?>
  <form method="post" action="fiscal_certificado_alerta_config.php" style="margin-top:12px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($certificateAlertConfigToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="grid">
      <div>
        <label>Dias antes do vencimento</label>
        <input class="field" type="number" min="1" max="90" name="warning_days" value="<?= (int)($certificateAlertSettings['warning_days'] ?? 10) ?>">
        <div class="hint">Padrão: 10 dias. O alerta volta a aparecer diariamente até o certificado ser renovado.</div>
      </div>
      <div>
        <label>Link WhatsApp / certificado online</label>
        <input class="field" type="url" name="purchase_url" value="<?= htmlspecialchars((string)($certificateAlertSettings['purchase_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://wa.me/55...">
        <div class="hint">Este endereço será usado no botão “Adquira seu novo certificado online agora”.</div>
      </div>
    </div>
    <div class="checkline" style="margin-top:12px;">
      <input id="fiscal_certificate_alert_enabled" type="checkbox" name="enabled" value="1" <?= !empty($certificateAlertSettings['enabled']) ? 'checked' : '' ?>>
      <label for="fiscal_certificate_alert_enabled" style="margin:0;">Exibir aviso preventivo de vencimento do certificado</label>
    </div>
    <div class="actions right" style="margin-top:14px;"><div class="actions-right"><button class="btn btn-primary" type="submit">Salvar aviso do certificado</button></div></div>
  </form>
</div>
<?php endif; ?>
</div>
<footer class="admin-footer">
  Desenvolvido por
  <a href="https://www.nexaprospero.com.br" target="_blank" rel="noopener noreferrer">Nexa Próspero</a>
</footer>
<?php if ($deliveryOrderAlertEnabled): ?>
<script>window.NEXA_REALTIME_CONFIG = <?= json_encode($nexaRealtimeConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/assets/nexa-realtime.js?v=20260911-4"></script>
<?php endif; ?>
<?php if (is_array($certificateAlert)):
  $certAlertExpired = !empty($certificateAlert['expired']);
  $certAlertToday = !empty($certificateAlert['today']);
  $certAlertDays = (int)($certificateAlert['days_remaining'] ?? 0);
  if ($certAlertExpired) {
    $certAlertTitle = 'Certificado digital vencido';
    $certAlertLead = 'Seu certificado digital está vencido.';
    $certAlertBlock = 'Você não poderá emitir documentos fiscais até cadastrar e ativar um novo certificado válido.';
  } elseif ($certAlertToday) {
    $certAlertTitle = 'Seu certificado vence hoje';
    $certAlertLead = 'Seu certificado digital vence hoje.';
    $certAlertBlock = 'Após o horário de vencimento você não poderá emitir documentos fiscais.';
  } elseif ($certAlertDays === 1) {
    $certAlertTitle = 'Certificado digital vence amanhã';
    $certAlertLead = 'Seu certificado digital vence amanhã.';
    $certAlertBlock = 'Providencie a renovação antes do vencimento para não interromper a emissão fiscal.';
  } else {
    $certAlertTitle = 'Certificado digital próximo do vencimento';
    $certAlertLead = 'Seu certificado digital vence em ' . max(0, $certAlertDays) . ' dias.';
    $certAlertBlock = 'Providencie a renovação antes do vencimento para não interromper a emissão fiscal.';
  }
  $certCompanyLabel = trim((string)($certificateAlert['company_name'] ?? ''));
  $certPurchaseUrl = trim((string)($certificateAlert['purchase_url'] ?? ''));
  $certAckKey = (string)($certificateAlert['ack_key'] ?? '');
?>
<style>
.nexa-cert-alert[hidden]{display:none!important}.nexa-cert-alert{position:fixed;inset:0;z-index:99995;display:grid;place-items:center;padding:20px}.nexa-cert-alert-bg{position:absolute;inset:0;background:rgba(15,23,42,.62);backdrop-filter:blur(3px)}.nexa-cert-alert-card{position:relative;width:min(620px,96vw);border-radius:24px;background:#fff;border:1px solid #dbe4f0;box-shadow:0 32px 90px rgba(15,23,42,.38);overflow:hidden}.nexa-cert-alert-head{padding:22px 24px 16px;background:linear-gradient(135deg,#fff7ed,#fffbeb);border-bottom:1px solid #fed7aa}.nexa-cert-alert.is-expired .nexa-cert-alert-head{background:linear-gradient(135deg,#fff1f2,#fff7ed);border-bottom-color:#fecdd3}.nexa-cert-alert-head h2{margin:0;font-size:23px;color:#7c2d12}.nexa-cert-alert.is-expired .nexa-cert-alert-head h2{color:#991b1b}.nexa-cert-alert-body{padding:22px 24px;color:#334155}.nexa-cert-alert-lead{font-size:18px;font-weight:900;color:#0f172a;margin-bottom:10px}.nexa-cert-alert-date{padding:12px 14px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:800;margin:12px 0}.nexa-cert-alert-note{line-height:1.5}.nexa-cert-alert-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:0 24px 22px}.nexa-cert-alert-actions .btn{min-height:42px}.nexa-cert-alert-buy{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff!important;border-color:transparent!important;text-decoration:none}.nexa-cert-alert-company{font-size:12px;color:#64748b;margin-top:4px}
</style>
<div class="nexa-cert-alert<?= $certAlertExpired ? ' is-expired' : '' ?>" hidden data-nexa-cert-alert data-ack-key="<?= htmlspecialchars($certAckKey, ENT_QUOTES, 'UTF-8') ?>">
  <div class="nexa-cert-alert-bg"></div>
  <section class="nexa-cert-alert-card" role="dialog" aria-modal="true" aria-labelledby="nexaCertAlertTitle">
    <div class="nexa-cert-alert-head">
      <h2 id="nexaCertAlertTitle"><?= htmlspecialchars($certAlertTitle, ENT_QUOTES, 'UTF-8') ?></h2>
      <?php if ($certCompanyLabel !== ''): ?><div class="nexa-cert-alert-company"><?= htmlspecialchars($certCompanyLabel, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    </div>
    <div class="nexa-cert-alert-body">
      <div class="nexa-cert-alert-lead"><?= htmlspecialchars($certAlertLead, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="nexa-cert-alert-date">Validade: <?= htmlspecialchars((string)($certificateAlert['expires_br'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="nexa-cert-alert-note"><?= htmlspecialchars($certAlertBlock, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="nexa-cert-alert-note" style="margin-top:10px;">Entre em contato com seu contador ou utilize o botão abaixo para adquirir e fazer seu certificado digital online.</div>
    </div>
    <div class="nexa-cert-alert-actions">
      <button class="btn" type="button" data-nexa-cert-aware>Estou ciente</button>
      <?php if ($certPurchaseUrl !== ''): ?><a class="btn nexa-cert-alert-buy" href="<?= htmlspecialchars($certPurchaseUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Adquira seu novo certificado online agora</a><?php endif; ?>
    </div>
  </section>
</div>
<script>
(function(){
  var box=document.querySelector('[data-nexa-cert-alert]');
  if(!box) return;
  var key=box.getAttribute('data-ack-key')||'';
  var acknowledged=false;
  try{ acknowledged=key!=='' && localStorage.getItem(key)==='1'; }catch(e){}
  if(!acknowledged) box.hidden=false;
  var aware=box.querySelector('[data-nexa-cert-aware]');
  if(aware) aware.addEventListener('click', function(){ try{ if(key) localStorage.setItem(key,'1'); }catch(e){} box.hidden=true; });
})();
</script>
<?php endif; ?>
</body></html>
<?php }
