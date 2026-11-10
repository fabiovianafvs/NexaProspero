<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/saas_modules.php';

if (!function_exists('access_table_exists')) {
  function access_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . strtolower($table);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    try { $st = $pdo->prepare('SHOW TABLES LIKE ?'); $st->execute([$table]); if ($st->fetchColumn() !== false) return $cache[$cacheKey] = true; } catch (Throwable $e) {}
    try { $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'); $st->execute([$table]); return $cache[$cacheKey] = (bool)$st->fetchColumn(); } catch (Throwable $e) { return $cache[$cacheKey] = false; }
  }
}

if (!function_exists('access_column_exists')) {
  function access_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . strtolower($table) . ':' . strtolower($column);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    try { $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?'); $st->execute([$column]); if ($st->fetch(PDO::FETCH_ASSOC)) return $cache[$cacheKey] = true; } catch (Throwable $e) {}
    try { $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'); $st->execute([$table, $column]); return $cache[$cacheKey] = (bool)$st->fetchColumn(); } catch (Throwable $e) { return $cache[$cacheKey] = false; }
  }
}

if (!function_exists('access_try_exec')) {
  function access_try_exec(PDO $pdo, string $sql, array &$errors = []): void {
    try { $pdo->exec($sql); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
  }
}

if (!function_exists('access_add_column_if_missing')) {
  function access_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition, array &$errors = []): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return;
    if (access_column_exists($pdo, $table, $column)) return;
    access_try_exec($pdo, 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition, $errors);
  }
}

if (!function_exists('access_permissions_catalog')) {
  function access_permissions_catalog(): array {
    return [
      // Menus gerais
      'dashboard' => ['label' => 'Dashboard', 'group' => 'Geral', 'module' => '*', 'pages' => ['dashboard.php'], 'nav' => ['dashboard']],
      'relatorio_movimentos_caixa' => ['label' => 'Movimentações de Caixa (todos os operadores)', 'group' => 'Relatórios', 'module' => 'gestao', 'pages' => ['relatorio_movimentos_caixa.php'], 'nav' => ['relatorio_movimentos_caixa']],
      'relatorio_estoque' => ['label' => 'Relatório de estoque', 'group' => 'Relatórios', 'module' => '*', 'pages' => ['relatorio_estoque.php'], 'nav' => ['relatorio_estoque']],
      'relatorio_fluxo_caixa' => ['label' => 'Fluxo de Caixa', 'group' => 'Relatórios', 'module' => 'financeiro', 'pages' => ['relatorio_fluxo_caixa.php'], 'nav' => ['relatorio_fluxo_caixa']],
      'relatorio_inadimplencia' => ['label' => 'Análise de inadimplência', 'group' => 'Relatórios', 'module' => 'financeiro', 'pages' => ['relatorio_inadimplencia.php'], 'nav' => ['relatorio_inadimplencia']],
      'relatorio_analise_dashboard' => ['label' => 'Análise Dashboard por período', 'group' => 'Relatórios', 'module' => '*', 'pages' => ['relatorio_analise_dashboard.php'], 'nav' => ['relatorio_analise_dashboard'], 'description' => 'Disponível para todos os módulos. Desmarque para bloquear este relatório neste perfil.'],
      'brain' => ['label' => 'Nexa Brain', 'group' => 'Geral', 'module' => '*', 'pages' => ['brain.php','brain_alertas.php','brain_alert_action.php','brain_api.php','brain_live_api.php'], 'nav' => ['brain']],
      'pedido_finalizado_alterar_vendedor' => ['label' => 'Alterar vendedor de pedido finalizado sem documento fiscal', 'group' => 'Vendas', 'module' => '*', 'pages' => [], 'nav' => []],
      'pedidos' => ['label' => 'Pedidos', 'group' => 'Geral', 'module' => ['delivery','gestao','fiscal','financeiro'], 'pages' => ['pedidos.php','pedido.php','pedido_editar.php','pedido_regularizacao.php','pedido_status.php','pedido_print.php','pedido_print_conferencia.php','pedido_print_producao.php','pedido_relatorio_produtos.php','pedido_whatsapp.php'], 'nav' => ['pedidos']],
      'produtos' => ['label' => 'Produtos', 'group' => 'Geral', 'module' => '*', 'pages' => ['produtos.php','produto_form.php','estoque_consulta.php'], 'nav' => ['produtos']],
      'clientes' => ['label' => 'Clientes', 'group' => 'Geral', 'module' => 'delivery', 'pages' => ['gestao_clientes.php','gestao_clientes_importar.php'], 'nav' => ['clientes','gestao_clientes']],
      // Aba Anexos do cadastro de Pessoas (ações dentro da tela; o administrador e a senha master têm todas).
      'pessoas_anexos_ver' => ['label' => 'Pessoas · Anexos: ver, abrir e baixar', 'group' => 'Geral', 'module' => ['financeiro','delivery','orcamento'], 'pages' => [], 'nav' => []],
      'pessoas_anexos_incluir' => ['label' => 'Pessoas · Anexos: incluir', 'group' => 'Geral', 'module' => ['financeiro','delivery','orcamento'], 'pages' => [], 'nav' => []],
      'pessoas_anexos_remover' => ['label' => 'Pessoas · Anexos: remover', 'group' => 'Geral', 'module' => ['financeiro','delivery','orcamento'], 'pages' => [], 'nav' => []],
      'exportacao' => ['label' => 'Exportação', 'group' => 'Integrações', 'module' => 'exportacao', 'pages' => ['exportacao.php','firebird.php','firebird_run.php'], 'nav' => ['export']],
      'api' => ['label' => 'API', 'group' => 'Integrações', 'module' => 'api', 'pages' => ['api.php'], 'nav' => ['api']],
      'suporte' => ['label' => 'Suporte', 'group' => 'Geral', 'module' => '*', 'pages' => [], 'nav' => ['suporte']],
      'importacao_produtos' => ['label' => 'Importação', 'group' => 'Integrações', 'module' => 'importacao_produtos', 'pages' => ['importacao.php'], 'nav' => ['importacao']],
      'manutencao_produtos' => ['label' => 'Manutenção de Produtos', 'group' => 'Utilitários', 'module' => '*', 'pages' => ['utilitarios_manutencao_produtos.php'], 'nav' => ['manutencao_produtos']],

      // WhatsApp
      'whatsapp' => ['label' => 'WhatsApp', 'group' => 'WhatsApp', 'module' => '*', 'pages' => ['whatsapp.php','whatsapp_api.php'], 'nav' => ['whatsapp']],
      'whatsapp_modelos' => ['label' => 'Modelo de Mensagens', 'group' => 'WhatsApp', 'module' => '*', 'pages' => ['whatsapp_modelos.php'], 'nav' => ['whatsapp_modelos']],
      'whatsapp_fila' => ['label' => 'Fila de Envio', 'group' => 'WhatsApp', 'module' => '*', 'pages' => ['whatsapp_fila.php'], 'nav' => ['whatsapp_fila']],
      'whatsapp_atendimento' => ['label' => 'Atendimento (conversas)', 'group' => 'WhatsApp', 'module' => 'whatsapp_chatbot', 'pages' => ['whatsapp_atendimento.php', 'whatsapp_atendimento_api.php', 'whatsapp_midia.php'], 'nav' => ['whatsapp_atendimento']],
      'whatsapp_chatbot' => ['label' => 'Chatbot (configurar fluxo e setores; vê todas as conversas)', 'group' => 'WhatsApp', 'module' => 'whatsapp_chatbot', 'pages' => ['whatsapp_chatbot.php', 'whatsapp_chatbot_fluxo.php'], 'nav' => ['whatsapp_chatbot']],

      // Compras: autorização individual por submenu e URL (sem liberação implícita).
      'compras_assistente' => ['label' => 'Assistente de Compras', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_assistente.php'], 'nav' => ['compras_assistente']],
      'compras_listas' => ['label' => 'Listas de Compras', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_listas.php','compras_lista_editar.php'], 'nav' => ['compras_listas']],
      'compras_cotacoes' => ['label' => 'Cotações de Compras', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_cotacoes.php','compras_cotacoes_matriz.php'], 'nav' => ['compras_cotacoes']],
      'compras_pedidos' => ['label' => 'Pedidos de Compra', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_pedidos.php','compras_pedido_enviar.php'], 'nav' => ['compras_pedidos']],
      'compras_painel' => ['label' => 'Painel de Compras', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_painel.php'], 'nav' => ['compras_painel']],
      'compras_alertas' => ['label' => 'Alertas de Compras', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_alertas.php'], 'nav' => ['compras_alertas']],
      'compras_fornecedores_avaliacao' => ['label' => 'Avaliação de Fornecedores', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_fornecedores_avaliacao.php'], 'nav' => ['compras_fornecedores_avaliacao']],
      'compras_fornecedores_ranking' => ['label' => 'Ranking de Fornecedores', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_fornecedores_ranking.php'], 'nav' => ['compras_fornecedores_ranking']],
      'compras_historico_precos' => ['label' => 'Histórico de Preços', 'group' => 'Compras', 'module' => 'compras', 'pages' => ['compras_historico_precos.php'], 'nav' => ['compras_historico_precos']],
      'compras_fornecedores_ranking_config' => ['label' => 'Configurar Pesos do Ranking', 'group' => 'Compras', 'module' => 'compras', 'pages' => [], 'nav' => []],

      // Orçamento
      'orcamentos' => ['label' => 'Lista de Orçamentos', 'group' => 'Orçamento', 'module' => 'orcamento', 'pages' => ['orcamentos.php','orcamento_editar.php','orcamento_ajax.php','orcamento_imprimir.php','os_importar_orcamento.php','documento_whatsapp.php'], 'nav' => ['orcamentos']],
      'orcamento_novo' => ['label' => 'Novo Orçamento', 'group' => 'Orçamento', 'module' => 'orcamento', 'pages' => ['orcamento_editar.php','orcamento_ajax.php'], 'nav' => ['orcamento_novo']],
      'orcamento_configuracoes' => ['label' => 'Configurações do Orçamento', 'group' => 'Configurações', 'module' => 'orcamento', 'pages' => ['orcamento_configuracoes.php'], 'nav' => ['orcamento_configuracoes']],
      // CRM
      'crm_atendimentos' => ['label' => 'Atendimento', 'group' => 'CRM', 'module' => 'crm', 'pages' => ['crm_atendimentos.php','crm_atendimento.php','crm_atendimento_mobile.php','crm_atendimento_mobile_lista.php','crm_tramitar.php','crm_ajax.php','crm_grid_preferences.php','crm_kanban.php','crm_proposta.php','crm_proposta_imprimir.php','crm_anexo.php'], 'nav' => ['crm_atendimentos','crm_kanban','crm_atendimento_mobile']],
      'crm_configuracoes' => ['label' => 'Status e tipos de atendimento', 'group' => 'CRM', 'module' => 'crm', 'pages' => ['crm_configuracoes.php'], 'nav' => []],

      // Controle de Entregas
      'delivery_control' => ['label' => 'Controle de Entregas', 'group' => 'Entregas', 'module' => 'controle_entregas', 'pages' => ['delivery_control.php','delivery_control_view.php'], 'nav' => ['controle_entregas','delivery_control']],
      'delivery_orders' => ['label' => 'Ordens de Entrega', 'group' => 'Entregas', 'module' => 'controle_entregas', 'pages' => ['delivery_orders.php'], 'nav' => ['delivery_orders']],
      'delivery_manifest' => ['label' => 'Romaneios', 'group' => 'Entregas', 'module' => 'controle_entregas', 'pages' => ['delivery_manifest.php','delivery_manifest_view.php','delivery_separation_print.php'], 'nav' => ['delivery_manifest']],
      'delivery_vehicles' => ['label' => 'Veículos', 'group' => 'Entregas', 'module' => 'controle_entregas', 'pages' => ['delivery_vehicles.php'], 'nav' => ['delivery_vehicles']],
      'delivery_fiscal_queue' => ['label' => 'Fila Fiscal das Entregas', 'group' => 'Entregas', 'module' => 'controle_entregas', 'pages' => ['delivery_fiscal_queue.php'], 'nav' => ['delivery_fiscal_queue']],


      // Contratos
      'contratos' => ['label' => 'Monitor de Contratos', 'group' => 'Contratos', 'module' => 'contratos', 'pages' => ['contratos.php','contrato_editar.php','contratos_ajax.php'], 'nav' => ['contratos_monitor']],
      'contratos_gerar' => ['label' => 'Gerar Contratos', 'group' => 'Contratos', 'module' => 'contratos', 'pages' => ['contratos_gerar.php'], 'nav' => ['contratos_gerar']],

      // Ordem de Serviço
      'os_monitor' => ['label' => 'Monitor de O.S.', 'group' => 'Ordem de Serviço', 'module' => 'ordem_servico', 'pages' => ['os_monitor.php','os_editar.php','os_ajax.php','os_imprimir.php','os_faturar.php','os_damage_photo.php','os_damage_photo_upload.php','os_pdv_hook.php','os_importar_orcamento.php','os_status_comunicar.php','documento_whatsapp.php'], 'nav' => ['os_monitor']],
      'os_mobile' => ['label' => 'O.S. Mobile', 'group' => 'Ordem de Serviço', 'module' => 'ordem_servico', 'pages' => ['os_mobile.php','os_mobile_monitor.php','os_mobile_view.php','os_damage_photo.php','os_damage_photo_upload.php'], 'nav' => ['os_mobile']],
      'os_faturas' => ['label' => 'Faturamento em Lote', 'group' => 'Ordem de Serviço', 'module' => 'ordem_servico', 'pages' => ['os_faturas.php','os_fatura_imprimir.php'], 'nav' => ['os_faturas']],
      'os_fiscal_documentos' => ['label' => 'Documentos fiscais da O.S.', 'group' => 'Fiscal', 'module' => 'ordem_servico', 'pages' => ['os_fiscal_documentos.php','os_fiscal_acao.php','os_fiscal_processar.php','os_fiscal_processar_checkout.php','os_fiscal_processar_ajax.php'], 'nav' => ['os_fiscal_documentos']],
      'os_configuracoes' => ['label' => 'Configurações da O.S.', 'group' => 'Configurações', 'module' => 'ordem_servico', 'pages' => ['os_configuracoes.php','os_layout_modelo.php'], 'nav' => ['os_configuracoes']],

      // Produção
      'producao_ficha' => ['label' => 'Ficha Técnica', 'group' => 'Produção', 'module' => 'ordem_producao', 'pages' => ['producao_ficha_tecnica.php'], 'nav' => ['producao_ficha']],
      'producao_monitor' => ['label' => 'Monitor de Produção', 'group' => 'Produção', 'module' => 'ordem_producao', 'pages' => ['producao_monitor.php','producao_ordem.php'], 'nav' => ['producao_monitor']],
      'producao_configuracoes' => ['label' => 'Configurações de O.P.', 'group' => 'Configurações', 'module' => 'ordem_producao', 'pages' => ['producao_configuracoes.php'], 'nav' => ['producao_configuracoes']],

      // Vendas / Gestão
      'gestao_dashboard' => ['label' => 'Dashboard vendas', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao.php'], 'nav' => ['gestao_dashboard']],
      'gestao_pdv_resumido' => ['label' => 'PDV Resumido', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao_pdv_resumido.php','pedido_print_evento.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_pdv_resumido']],
      'gestao_pdv' => ['label' => 'Pedido PDV', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao_pdv.php','pedido_print_evento.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_pdv']],
      'gestao_pdv_checkout' => ['label' => 'PDV Checkout', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao_pdv_checkout.php','pedido_print.php','pedido_print_evento.php','orcamento_pedido_ajax.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_pdv_checkout']],
      'gestao_pdv_mobile' => ['label' => 'Venda Mobile', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao_pdv_mobile.php','pedido_print_evento.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_pdv_mobile']],
      'gestao_comandas' => ['label' => 'Comandas', 'group' => 'Vendas', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas.php','pedido_print.php','pedido_print_conferencia.php','pedido_print_cozinha.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_comandas']],
      'gestao_comandas_checkout' => ['label' => 'Comanda Checkout', 'group' => 'Vendas', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas_checkout.php','pedido_print.php','pedido_print_conferencia.php','pedido_print_cozinha.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_comandas_checkout']],
      'gestao_comandas_mobile' => ['label' => 'Comanda Mobile', 'group' => 'Vendas', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas_mobile.php','pedido_print.php','pedido_print_conferencia.php','pedido_print_cozinha.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_comandas_mobile']],
      'gestao_pedido_detalhado' => ['label' => 'Pedido Detalhado', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao_pedido_detalhado.php','fiscal_nfce_pedido.php','fiscal_nfce_acao.php','fiscal_nfce_imprimir.php','fiscal_nfse_nacional.php','fiscal_nfse_acao.php','financeiro_vales_ajax.php'], 'nav' => ['gestao_pedido_detalhado']],
      'gestao_pedidos' => ['label' => 'Consulta de pedidos', 'group' => 'Vendas', 'module' => ['delivery','gestao','fiscal','financeiro'], 'pages' => ['gestao_pedidos.php','pedidos.php','pedido.php','pedido_editar.php','pedido_regularizacao.php','pedido_status.php','pedido_print.php','pedido_print_conferencia.php','pedido_print_producao.php','pedido_relatorio_produtos.php','pedido_whatsapp.php'], 'nav' => ['gestao_pedidos']],
      'gestao_caixa' => ['label' => 'Caixa', 'group' => 'Vendas', 'module' => 'gestao', 'pages' => ['gestao_caixa.php'], 'nav' => ['gestao_caixa']],

      // Financeiro
      'financeiro_dashboard' => ['label' => 'Dashboard financeiro', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro.php'], 'nav' => ['fin_dashboard']],
      'financeiro_pessoas' => ['label' => 'Pessoas', 'group' => 'Financeiro', 'module' => ['financeiro','delivery','orcamento'], 'pages' => ['financeiro_pessoas.php','pessoa_aniversario_whatsapp.php'], 'nav' => ['fin_pessoas']],
      'financeiro_receber' => ['label' => 'Contas a receber / Controle de Cartões', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_contas_receber.php','financeiro_cartoes.php','financeiro_recibo_baixa.php','financeiro_recibo_whatsapp.php'], 'nav' => ['fin_receber','fin_cartoes']],
      'financeiro_pagar' => ['label' => 'Contas a pagar', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_contas_pagar.php'], 'nav' => ['fin_pagar']],
      'financeiro_despesas_fixas' => ['label' => 'Despesas fixas', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_despesas_fixas.php'], 'nav' => ['fin_fixas']],
      'financeiro_vales' => ['label' => 'Vales', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_vales.php','financeiro_vales_ajax.php'], 'nav' => ['fin_vales']],
      'financeiro_movimentos' => ['label' => 'Movimento financeiro', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_movimentos.php'], 'nav' => ['fin_mov']],
      'financeiro_boletos' => ['label' => 'Cobranças/Boletos', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_boletos.php','financeiro_boleto_itau.php','financeiro_boleto_imprimir.php','financeiro_boleto_whatsapp.php'], 'nav' => ['fin_boletos']],
      'financeiro_conciliacao' => ['label' => 'Conciliação Bancária', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_conciliacao.php'], 'nav' => ['fin_conciliacao']],
      'financeiro_resultado' => ['label' => 'Resultado por plano', 'group' => 'Financeiro', 'module' => 'financeiro', 'pages' => ['financeiro_resultado.php'], 'nav' => ['fin_resultado']],
      'financeiro_plano' => ['label' => 'Plano de Contas', 'group' => 'Financeiro - Tabelas Auxiliares', 'module' => 'financeiro', 'pages' => ['financeiro_plano_contas.php'], 'nav' => ['fin_plano']],
      'financeiro_centro' => ['label' => 'Centro de Custo', 'group' => 'Financeiro - Tabelas Auxiliares', 'module' => 'financeiro', 'pages' => ['financeiro_centros_custo.php'], 'nav' => ['fin_centro']],
      'financeiro_caixas' => ['label' => 'Caixas e Bancos', 'group' => 'Financeiro - Tabelas Auxiliares', 'module' => 'financeiro', 'pages' => ['financeiro_caixas_bancos.php','financeiro_itau.php'], 'nav' => ['fin_caixas']],
      'configuracoes_cre' => ['label' => 'Configurações CRE', 'group' => 'Configurações', 'module' => 'financeiro', 'pages' => ['configuracoes_cre.php'], 'nav' => ['configuracoes_cre']],
      'configuracoes_cpa' => ['label' => 'Configurações CPA', 'group' => 'Configurações', 'module' => 'financeiro', 'pages' => ['configuracoes_cpa.php'], 'nav' => ['configuracoes_cpa']],
      'configuracoes_caixa' => ['label' => 'Configurações de caixa', 'group' => 'Configurações', 'module' => 'financeiro', 'pages' => ['configuracoes_caixa.php'], 'nav' => ['configuracoes_caixa']],

      // Entradas
      'fiscal_radar' => ['label' => 'RADAR NF-e', 'group' => 'Entradas', 'module' => 'fiscal', 'pages' => ['fiscal.php','fiscal_radar_xml.php'], 'nav' => ['fiscal_radar_xml']],
      'fiscal_entrada_notas' => ['label' => 'Entrada de Notas', 'group' => 'Entradas', 'module' => 'fiscal', 'pages' => ['fiscal_entrada_notas.php'], 'nav' => ['fiscal_entrada_notas']],
      'fiscal_devolucoes' => ['label' => 'Devolução / Outras Entradas', 'group' => 'Entradas', 'module' => 'fiscal', 'pages' => ['fiscal_devolucoes.php'], 'nav' => ['fiscal_devolucoes']],
      'fiscal_devolucao_cliente' => ['label' => 'Nova devolução de cliente', 'group' => 'Entradas', 'module' => 'fiscal', 'pages' => ['fiscal_devolucao_cliente.php'], 'nav' => ['fiscal_devolucao_cliente']],
      'fiscal_importar_xml' => ['label' => 'Importar XML', 'group' => 'Entradas', 'module' => 'fiscal', 'pages' => ['fiscal_importar_xml.php','fiscal_xml_entrada.php'], 'nav' => ['fiscal_importar_xml']],
      'fiscal_nota_manual' => ['label' => 'Entrada manual', 'group' => 'Entradas', 'module' => 'fiscal', 'pages' => ['fiscal_nota_manual.php'], 'nav' => ['fiscal_nota_manual']],
      'conferencia_estoque' => ['label' => 'Conferência de estoque', 'group' => 'Entradas', 'module' => 'conferencia_estoque', 'pages' => ['estoque_conferencias.php','estoque_conferencia_form.php','estoque_conferencia_processar.php'], 'nav' => ['estoque_conferencias']],

      // Fiscal
      'fiscal_documentos' => ['label' => 'Documentos fiscais', 'group' => 'Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_documentos.php','fiscal_nfse_cancelar.php','fiscal_nfce_pedido.php','fiscal_nfce_imprimir.php','fiscal_nfce_acao.php','fiscal_nfse_nacional.php','fiscal_nfse_acao.php','fiscal_emissao_lote.php','fiscal_lote_contingencia.php'], 'nav' => ['fiscal_documentos']],
      'fiscal_inutilizacao' => ['label' => 'Inutilização de numeração', 'group' => 'Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_inutilizacao.php'], 'nav' => ['fiscal_inutilizacao']],
      'fiscal_saidas' => ['label' => 'Saídas fiscais', 'group' => 'Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_saidas.php'], 'nav' => ['fiscal_saidas']],
      'fiscal_contadores' => ['label' => 'Cadastro de contadores', 'group' => 'Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_contadores.php'], 'nav' => ['fiscal_contadores']],
      'fiscal_conversoes' => ['label' => 'Conversões XML', 'group' => 'Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_xml_conversoes.php'], 'nav' => ['fiscal_xml_conversoes']],
      'fiscal_configuracoes' => ['label' => 'Configurações fiscais', 'group' => 'Configurações', 'module' => 'fiscal', 'pages' => ['fiscal_configuracoes.php'], 'nav' => ['fiscal_configuracoes']],

      // Tabelas / Cadastros
      'gestao_vendedores' => ['label' => 'Cadastro de Vendedores', 'group' => 'Tabelas - Gestão', 'module' => 'gestao', 'pages' => ['gestao_vendedores.php'], 'nav' => ['gestao_vendedores']],
      'gestao_comandas_config' => ['label' => 'Cadastro de Comandas', 'group' => 'Tabelas - Gestão', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas_config.php'], 'nav' => ['gestao_comandas_config']],
      'gestao_comandas_qrcode' => ['label' => 'QR Codes das Mesas', 'group' => 'Tabelas - Gestão', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas_qrcode.php'], 'nav' => ['gestao_comandas_qrcode']],
      'gestao_comandas_impressao' => ['label' => 'Monitor de Impressão', 'group' => 'Tabelas - Gestão', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas_impressao.php','gestao_comandas_print_api.php'], 'nav' => ['gestao_comandas_impressao']],
      'gestao_comandas_agentes' => ['label' => 'Agentes de Impressão', 'group' => 'Tabelas - Gestão', 'module' => ['comandas','gestao'], 'pages' => ['gestao_comandas_agentes.php'], 'nav' => ['gestao_comandas_agentes']],
      'gestao_pagamentos' => ['label' => 'Formas de Pagamento', 'group' => 'Tabelas - Gestão', 'module' => ['gestao','financeiro'], 'pages' => ['gestao_formas_pagamento.php'], 'nav' => ['gestao_pagamentos']],
      'fiscal_operacoes' => ['label' => 'Operações fiscais', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_operacoes.php','fiscal_lookup.php'], 'nav' => ['fiscal_operacoes']],
      'fiscal_tributacoes' => ['label' => 'Tributações', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_tributacoes.php','fiscal_lookup.php'], 'nav' => ['fiscal_tributacoes']],
      'fiscal_cfops' => ['label' => 'CFOP', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_cfops.php','fiscal_lookup.php'], 'nav' => ['fiscal_cfops']],
      'fiscal_ncms' => ['label' => 'NCM', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_ncms.php','fiscal_lookup.php'], 'nav' => ['fiscal_ncms']],
      'fiscal_cests' => ['label' => 'CEST', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_cests.php','fiscal_lookup.php'], 'nav' => ['fiscal_cests']],
      'fiscal_cst' => ['label' => 'CST/CSOSN', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_cst.php','fiscal_lookup.php'], 'nav' => ['fiscal_cst']],
      'fiscal_ibscbs' => ['label' => 'IBS/CBS', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_ibscbs.php','fiscal_lookup.php'], 'nav' => ['fiscal_ibscbs']],
      'fiscal_importacoes' => ['label' => 'Tabelas fiscais', 'group' => 'Tabelas - Fiscal', 'module' => 'fiscal', 'pages' => ['fiscal_importacoes.php','fiscal_tabelas.php','fiscal_lookup.php'], 'nav' => ['fiscal_importacoes']],
      'usuarios' => ['label' => 'Usuários', 'group' => 'Tabelas - Administração', 'module' => '*', 'pages' => ['usuarios.php','usuario_form.php','usuario_email_teste.php'], 'nav' => ['usuarios'], 'description' => 'Permite cadastrar, editar, ativar e inativar usuários da empresa.'],
      'usuarios_perfis' => ['label' => 'Perfis de acesso', 'group' => 'Tabelas - Administração', 'module' => '*', 'pages' => ['usuarios_perfis.php','usuario_perfil_form.php'], 'nav' => ['usuarios_perfis'], 'description' => 'Permissão administrativa elevada: permite alterar os acessos dos demais usuários.'],

      // Relatórios
      'relatorio_comissao_vendedores' => ['label' => 'Comissão de vendedores', 'group' => 'Relatórios', 'module' => 'gestao', 'pages' => ['relatorio_comissao_vendedores.php'], 'nav' => ['relatorio_comissao_vendedores']],
      'relatorio_etiquetas_produtos' => ['label' => 'Etiqueta de produtos', 'group' => 'Relatórios', 'module' => '*', 'pages' => ['relatorio_etiquetas_produtos.php','relatorio_etiquetas_modelo.php','relatorio_etiquetas_imprimir.php'], 'nav' => ['relatorio_etiquetas_produtos']],
      'fiscal_relatorios' => ['label' => 'Visão Geral', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_relatorios.php','fiscal_relatorio_inutilizacoes.php'], 'nav' => ['fiscal_relatorios']],
      'fiscal_relatorios_documentos' => ['label' => 'Documentos Fiscais', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_relatorio_documentos.php'], 'nav' => ['fiscal_relatorios_documentos']],
      'fiscal_sintegra' => ['label' => 'Gerar Sintegra', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_relatorio_sintegra.php'], 'nav' => ['fiscal_sintegra']],
      'fiscal_sped_icms' => ['label' => 'Gerar SPED ICMS/IPI', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_relatorio_sped_icms.php'], 'nav' => ['fiscal_sped_icms']],
      'fiscal_sped_contrib' => ['label' => 'Gerar SPED Contribuições', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_relatorio_sped_contrib.php'], 'nav' => ['fiscal_sped_contrib']],
      'fiscal_exportar_xml' => ['label' => 'Exportar XMLs', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_exportar_xml.php'], 'nav' => ['fiscal_exportar_xml']],
      'fiscal_inventario' => ['label' => 'Inventário', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_relatorio_inventario.php','fiscal_inventario_pdf.php'], 'nav' => ['fiscal_inventario']],
      'fiscal_contabilidade' => ['label' => 'Documentos para Contabilidade', 'group' => 'Relatórios Fiscais', 'module' => 'fiscal', 'pages' => ['fiscal_contabilidade.php','fiscal_contabilidade_download.php'], 'nav' => ['fiscal_contabilidade']],

      // Configurações gerais
      'configuracoes' => ['label' => 'Configurações gerais', 'group' => 'Configurações', 'module' => '*', 'pages' => ['parametros.php'], 'nav' => ['params']],

      // Funções sem item de menu próprio
      'financeiro_baixar_receber' => ['label' => 'Baixar contas a receber', 'group' => 'Funções financeiras', 'module' => 'financeiro'],
      'financeiro_baixar_pagar' => ['label' => 'Baixar contas a pagar', 'group' => 'Funções financeiras', 'module' => 'financeiro'],
      'financeiro_cancelar_titulos' => ['label' => 'Cancelar títulos', 'group' => 'Funções financeiras', 'module' => 'financeiro'],
      'financeiro_movimento_manual' => ['label' => 'Lançar movimento manual', 'group' => 'Funções financeiras', 'module' => 'financeiro'],
      'nao_finalizar_venda' => ['label' => 'Não permitir finalizar a venda', 'group' => 'Restrições operacionais', 'module' => '*', 'restriction' => true, 'description' => 'O usuário poderá lançar produtos e conferir a conta, mas não poderá fechar a comanda, concluir o PDV, gerar pedido ou emitir documento fiscal.'],
      'desativa_preco_custo' => ['label' => 'Desativa preço de custo', 'group' => 'Restrições operacionais', 'module' => '*', 'restriction' => true, 'description' => 'Oculta o preço de custo no cadastro, na grade e nas pesquisas de produtos para este perfil.'],
      'desativa_margem_orcamento' => ['label' => 'Desativa margens do orçamento', 'group' => 'Restrições operacionais', 'module' => 'orcamento', 'restriction' => true, 'description' => 'Oculta as margens sobre custo/venda e o painel de negociação dos orçamentos para este perfil.'],
    ];
  }
}

if (!function_exists('access_page_permissions')) {
  function access_page_permissions(string $page): array {
    $page = strtolower(basename($page));
    $permissions = [];
    foreach (access_permissions_catalog() as $key => $perm) {
      foreach ((array)($perm['pages'] ?? []) as $p) {
        if (strtolower((string)$p) === $page) {
          $permissions[] = (string)$key;
          break;
        }
      }
    }
    return array_values(array_unique($permissions));
  }
}

if (!function_exists('access_page_to_permission')) {
  function access_page_to_permission(string $page): string {
    return (string)(access_page_permissions($page)[0] ?? '');
  }
}

if (!function_exists('access_nav_to_permission')) {
  function access_nav_to_permission(string $navKey): string {
    foreach (access_permissions_catalog() as $key => $perm) {
      foreach ((array)($perm['nav'] ?? []) as $n) if ((string)$n === $navKey) return (string)$key;
    }
    return '';
  }
}

if (!function_exists('access_module_enabled')) {
  function access_module_enabled(PDO $pdo, int $companyId, $module): bool {
    if (is_array($module)) {
      foreach ($module as $code) {
        $code = trim((string)$code);
        if ($code === '') continue;
        if ($code === '*' ? saas_company_has_any_module($pdo, $companyId) : saas_company_has_module($pdo, $companyId, $code)) return true;
      }
      return false;
    }
    $module = trim((string)$module);
    if ($module === '' || $module === '*') return saas_company_has_any_module($pdo, $companyId);
    return saas_company_has_module($pdo, $companyId, $module);
  }
}


if (!function_exists('access_ensure_schema')) {
  function access_ensure_schema(PDO $pdo): array {
    static $results = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($results[$cacheKey])) return $results[$cacheKey];

    try {
      $stVersion = $pdo->query("SELECT version_number FROM nexa_schema_versions WHERE module_name='runtime_performance' LIMIT 1");
      if ((int)($stVersion ? $stVersion->fetchColumn() : 0) >= 2026072601) {
        return $results[$cacheKey] = ['ok' => true, 'errors' => []];
      }
    } catch (Throwable $e) {}

    $errors = [];
    access_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS user_profiles (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        description TEXT NULL,
        permissions_json LONGTEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_user_profiles_company (company_id, is_active),
        UNIQUE KEY uk_user_profiles_company_name (company_id, name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);
    access_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS user_cash_accounts (
        company_id INT NOT NULL,
        user_id INT NOT NULL,
        bank_account_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, user_id, bank_account_id),
        KEY idx_user_cash_user (user_id),
        KEY idx_user_cash_bank (bank_account_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);
    if (access_table_exists($pdo, 'users')) {
      access_add_column_if_missing($pdo, 'users', 'profile_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'default_cash_bank_account_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'default_pdv_cash_bank_account_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'default_comanda_cash_bank_account_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'default_nfe_series_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'default_nfce_series_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'default_service_series_id', 'INT NULL', $errors);
      access_add_column_if_missing($pdo, 'users', 'allow_os_mobile', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
      access_add_column_if_missing($pdo, 'users', 'updated_at', 'DATETIME NULL', $errors);
      access_try_exec($pdo, 'ALTER TABLE users ADD KEY idx_users_profile (profile_id)', $errors);
      access_try_exec($pdo, 'ALTER TABLE users ADD KEY idx_users_nfe_series (default_nfe_series_id)', $errors);
      access_try_exec($pdo, 'ALTER TABLE users ADD KEY idx_users_nfce_series (default_nfce_series_id)', $errors);
    }
    $errors = array_values(array_filter($errors, static function($m){
      return stripos((string)$m, 'Duplicate') === false && stripos((string)$m, 'Multiple primary') === false;
    }));
    return $results[$cacheKey] = ['ok' => access_table_exists($pdo, 'user_profiles') && access_table_exists($pdo, 'user_cash_accounts'), 'errors' => $errors];
  }
}

if (!function_exists('access_current_user')) {
  function access_current_user(PDO $pdo): ?array {
    $uid = auth_user_id();
    if (!$uid) return null;
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . (int)$uid;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    try {
      $cols = 'id, company_id, name, email, role, is_active';
      foreach (['profile_id','default_cash_bank_account_id','default_pdv_cash_bank_account_id','default_comanda_cash_bank_account_id','allow_os_mobile'] as $c) {
        if (access_column_exists($pdo, 'users', $c)) $cols .= ', ' . $c;
      }
      $st = $pdo->prepare('SELECT ' . $cols . ' FROM users WHERE id = ? LIMIT 1');
      $st->execute([(int)$uid]);
      $u = $st->fetch(PDO::FETCH_ASSOC);
      return $cache[$cacheKey] = ($u ?: null);
    } catch (Throwable $e) { return $cache[$cacheKey] = null; }
  }
}

if (!function_exists('access_is_company_master')) {
  function access_is_company_master(): bool {
    return in_array(auth_role(), ['company_admin', 'platform_admin'], true);
  }
}

if (!function_exists('access_user_os_mobile_enabled')) {
  function access_user_os_mobile_enabled(PDO $pdo, int $companyId, ?int $userId = null): bool {
    // O administrador principal da empresa sempre mantém acesso à O.S. Mobile.
    if (access_is_company_master()) return true;

    $userId = $userId ?: (int)(auth_user_id() ?? 0);
    if ($userId <= 0) return false;

    // Compatibilidade segura: antes da migração da coluna, preserva o comportamento atual.
    if (!access_column_exists($pdo, 'users', 'allow_os_mobile')) return true;

    try {
      if ($userId === (int)(auth_user_id() ?? 0)) {
        $u = access_current_user($pdo);
        if (!$u || (int)($u['company_id'] ?? 0) !== $companyId || (int)($u['is_active'] ?? 0) !== 1) return false;
        return (int)($u['allow_os_mobile'] ?? 1) === 1;
      }

      $st = $pdo->prepare('SELECT allow_os_mobile FROM users WHERE company_id = ? AND id = ? AND is_active = 1 LIMIT 1');
      $st->execute([$companyId, $userId]);
      $value = $st->fetchColumn();
      if ($value === false) return false;
      return (int)$value === 1;
    } catch (Throwable $e) {
      // Em caso de falha transitória de leitura, não derruba o restante do ERP.
      return true;
    }
  }
}

if (!function_exists('access_profile_permissions')) {
  function access_profile_permissions(PDO $pdo, int $companyId, int $profileId): array {
    if ($profileId <= 0 || !access_table_exists($pdo, 'user_profiles')) return [];
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $companyId . ':' . $profileId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
      $st = $pdo->prepare('SELECT permissions_json FROM user_profiles WHERE company_id = ? AND id = ? AND is_active = 1 LIMIT 1');
      $st->execute([$companyId, $profileId]);
      $arr = json_decode((string)($st->fetchColumn() ?: ''), true);
      return $cache[$cacheKey] = (is_array($arr) ? array_values(array_unique(array_filter(array_map('strval', $arr)))) : []);
    } catch (Throwable $e) { return $cache[$cacheKey] = []; }
  }
}

if (!function_exists('access_user_permissions')) {
  function access_user_permissions(PDO $pdo, int $companyId, ?int $userId = null): array {
    if (access_is_company_master()) return array_keys(access_permissions_catalog());
    $userId = $userId ?: (int)(auth_user_id() ?? 0);
    if ($userId <= 0) return [];
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $companyId . ':' . $userId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $u = access_current_user($pdo);
    if (!$u || (int)($u['company_id'] ?? 0) !== $companyId || (int)($u['is_active'] ?? 0) !== 1) return $cache[$cacheKey] = [];
    return $cache[$cacheKey] = access_profile_permissions($pdo, $companyId, (int)($u['profile_id'] ?? 0));
  }
}

if (!function_exists('access_profile_flag_enabled')) {
  function access_profile_flag_enabled(PDO $pdo, int $companyId, string $flag): bool {
    // Flags de restrição não se aplicam ao administrador principal da empresa.
    if (access_is_company_master()) return false;
    return in_array($flag, access_user_permissions($pdo, $companyId), true);
  }
}

if (!function_exists('access_sale_finalization_blocked')) {
  function access_sale_finalization_blocked(PDO $pdo, int $companyId): bool {
    return access_profile_flag_enabled($pdo, $companyId, 'nao_finalizar_venda');
  }
}

if (!function_exists('access_product_cost_hidden')) {
  function access_product_cost_hidden(PDO $pdo, int $companyId): bool {
    return access_profile_flag_enabled($pdo, $companyId, 'desativa_preco_custo');
  }
}

if (!function_exists('access_quote_margin_hidden')) {
  function access_quote_margin_hidden(PDO $pdo, int $companyId): bool {
    return access_profile_flag_enabled($pdo, $companyId, 'desativa_margem_orcamento');
  }
}

if (!function_exists('access_user_has_sales_channel')) {
  function access_user_has_sales_channel(PDO $pdo, int $companyId): bool {
    if (access_is_company_master()) return true;
    foreach (['gestao_pdv_resumido','gestao_pdv','gestao_pdv_checkout','gestao_pdv_mobile','gestao_comandas','gestao_comandas_checkout','gestao_comandas_mobile','gestao_pedido_detalhado'] as $permission) {
      if (access_has_permission($pdo, $companyId, $permission)) return true;
    }
    return false;
  }
}

if (!function_exists('access_has_permission')) {
  function access_has_permission(PDO $pdo, int $companyId, string $permission): bool {
    if (access_is_company_master()) return true;
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $companyId . ':' . $permission;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    $catalog = access_permissions_catalog();
    if (!isset($catalog[$permission])) return $cache[$cacheKey] = false;
    if (!access_module_enabled($pdo, $companyId, $catalog[$permission]['module'] ?? '*')) return $cache[$cacheKey] = false;
    $permissions = access_user_permissions($pdo, $companyId);
    // Análise Dashboard: só pode visualizar se a permissão estiver marcada.
    // Respeita também bloqueios explícitos legados em perfis já existentes.
    if ($permission === 'relatorio_analise_dashboard'
        && in_array('__deny_relatorio_analise_dashboard', $permissions, true)) {
      return $cache[$cacheKey] = false;
    }
    return $cache[$cacheKey] = in_array($permission, $permissions, true);
  }
}

if (!function_exists('access_admin_nav_allowed')) {
  function access_admin_nav_allowed(PDO $pdo, int $companyId, string $navKey): bool {
    if (access_is_company_master()) return true;

    if ($navKey === 'os_mobile' && !access_user_os_mobile_enabled($pdo, $companyId)) return false;

    $parentPermissions = [
      'whatsapp' => ['whatsapp','whatsapp_modelos','whatsapp_fila','whatsapp_atendimento','whatsapp_chatbot'],
      'orcamento' => ['orcamentos','orcamento_novo'],
      'contratos' => ['contratos','contratos_gerar'],
      'compras' => ['compras_assistente','compras_listas','compras_cotacoes','compras_pedidos','compras_painel','compras_alertas','compras_fornecedores_avaliacao','compras_fornecedores_ranking','compras_fornecedores_ranking_config','compras_historico_precos'],
      'ordem_servico' => ['os_monitor','os_mobile','os_faturas'],
      'producao' => ['producao_ficha','producao_monitor'],
      'controle_entregas' => ['delivery_control','delivery_orders','delivery_manifest','delivery_vehicles','delivery_fiscal_queue'],
      'gestao' => ['gestao_dashboard','gestao_pdv_resumido','gestao_pdv','gestao_pdv_checkout','gestao_pdv_mobile','gestao_comandas','gestao_comandas_checkout','gestao_comandas_mobile','gestao_pedido_detalhado','gestao_pedidos','pedidos','gestao_caixa'],
      'financeiro' => ['financeiro_dashboard','financeiro_pessoas','financeiro_receber','financeiro_pagar','financeiro_despesas_fixas','financeiro_vales','financeiro_movimentos','financeiro_boletos','financeiro_conciliacao','financeiro_resultado','financeiro_plano','financeiro_centro','financeiro_caixas','configuracoes_cre','configuracoes_cpa','configuracoes_caixa'],
      'entradas' => ['fiscal_radar','fiscal_entrada_notas','fiscal_devolucoes','fiscal_devolucao_cliente','fiscal_importar_xml','fiscal_nota_manual','conferencia_estoque'],
      'fiscal' => ['fiscal_documentos','os_fiscal_documentos','fiscal_inutilizacao','fiscal_saidas','fiscal_contadores','fiscal_configuracoes','fiscal_conversoes'],
      'relatorios' => ['relatorio_analise_dashboard','relatorio_comissao_vendedores','relatorio_etiquetas_produtos','relatorio_movimentos_caixa','relatorio_inadimplencia','relatorio_fluxo_caixa','relatorio_estoque'],
      'utilitarios' => ['manutencao_produtos'],
      'tabelas' => ['gestao_vendedores','gestao_comandas_config','gestao_comandas_qrcode','gestao_comandas_impressao','gestao_comandas_agentes','gestao_pagamentos','fiscal_operacoes','fiscal_tributacoes','fiscal_cfops','fiscal_ncms','fiscal_cests','fiscal_cst','fiscal_ibscbs','fiscal_importacoes','usuarios','usuarios_perfis'],
      'fiscal_relatorios' => ['fiscal_relatorios','fiscal_relatorios_documentos','fiscal_sintegra','fiscal_sped_icms','fiscal_sped_contrib','fiscal_exportar_xml','fiscal_inventario','fiscal_contabilidade'],
    ];

    if (isset($parentPermissions[$navKey])) {
      foreach ($parentPermissions[$navKey] as $permission) {
        if (access_has_permission($pdo, $companyId, $permission)) return true;
      }
      return false;
    }

    $perm = access_nav_to_permission($navKey);
    if ($perm === '') return false;
    return access_has_permission($pdo, $companyId, $perm);
  }
}

if (!function_exists('access_can_page')) {
  function access_can_page(PDO $pdo, int $companyId, string $page): bool {
    $page = strtolower(basename($page));
    if (in_array($page, ['login.php','logout.php'], true)) return true;
    if (access_is_company_master()) return true;

    // A O.S. Mobile possui uma liberação individual por usuário, além da permissão
    // normal do módulo de Ordem de Serviço.
    if (in_array($page, ['os_mobile.php','os_mobile_monitor.php','os_mobile_view.php'], true)) {
      if (!access_user_os_mobile_enabled($pdo, $companyId)) return false;
      return access_has_permission($pdo, $companyId, 'os_mobile');
    }

    // Operadores de PDV/Comanda que podem finalizar a venda também precisam abrir
    // a impressão fiscal e o fluxo da NFS-e, mesmo sem acesso ao menu Fiscal.
    if (in_array($page, ['fiscal_nfce_imprimir.php','fiscal_nfse_nacional.php','fiscal_nfse_acao.php'], true)
        && !access_sale_finalization_blocked($pdo, $companyId)
        && access_user_has_sales_channel($pdo, $companyId)) {
      return true;
    }

    $permissions = access_page_permissions($page);
    if (!$permissions) return true;
    foreach ($permissions as $permission) {
      if (access_has_permission($pdo, $companyId, $permission)) return true;
    }
    return false;
  }
}

if (!function_exists('access_render_denied')) {
  function access_render_denied(string $message = ''): void {
    http_response_code(403);
    $message = $message !== '' ? $message : 'Seu usuário não possui permissão para acessar esta função.';
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acesso restrito</title>
    <style>body{margin:0;background:#f5f7fb;color:#0f172a;font-family:Arial,Helvetica,sans-serif;min-height:100vh;display:grid;place-items:center;padding:20px}.box{width:min(92vw,640px);background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:28px;box-shadow:0 20px 55px rgba(11,31,91,.13)}h1{margin:0 0 10px;color:#0b1f5b}.btn{display:inline-flex;margin-top:15px;background:#0b1f5b;color:#fff;text-decoration:none;border-radius:999px;padding:12px 18px;font-weight:900}</style></head><body><div class="box"><h1>Acesso restrito</h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><a class="btn" href="area_trabalho.php">Voltar ao início</a></div></body></html>
    <?php exit;
  }
}

if (!function_exists('access_require_page')) {
  function access_require_page(PDO $pdo, int $companyId, string $page): void {
    if (!access_can_page($pdo, $companyId, $page)) access_render_denied();
  }
}

if (!function_exists('access_admin_default_url')) {
  function access_admin_default_url(PDO $pdo, int $companyId): string {
    // Página institucional comum a qualquer usuário autenticado, sem depender
    // dos módulos contratados ou das permissões do perfil.
    return 'area_trabalho.php';
  }
}

if (!function_exists('access_bank_ids_all')) {
  function access_bank_ids_all(PDO $pdo, int $companyId): array {
    try { $st = $pdo->prepare('SELECT id FROM finance_bank_accounts WHERE company_id = ? AND active = 1'); $st->execute([$companyId]); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('access_user_cash_account_ids')) {
  function access_user_cash_account_ids(PDO $pdo, int $companyId, ?int $userId = null): array {
    if (access_is_company_master()) return access_bank_ids_all($pdo, $companyId);
    $userId = $userId ?: (int)(auth_user_id() ?? 0);
    if ($userId <= 0 || !access_table_exists($pdo, 'user_cash_accounts')) return [];
    $ids = [];
    try {
      $st = $pdo->prepare('SELECT bank_account_id FROM user_cash_accounts WHERE company_id = ? AND user_id = ?');
      $st->execute([$companyId, $userId]);
      $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
    try {
      $cols = [];
      foreach (['default_cash_bank_account_id','default_pdv_cash_bank_account_id','default_comanda_cash_bank_account_id'] as $c) if (access_column_exists($pdo, 'users', $c)) $cols[] = $c;
      if ($cols) {
        $st = $pdo->prepare('SELECT ' . implode(',', $cols) . ' FROM users WHERE company_id = ? AND id = ? LIMIT 1');
        $st->execute([$companyId, $userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($cols as $c) { $id = (int)($u[$c] ?? 0); if ($id > 0) $ids[] = $id; }
      }
    } catch (Throwable $e) {}
    $all = array_fill_keys(access_bank_ids_all($pdo, $companyId), true);
    return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0 && isset($all[$id]))));
  }
}

if (!function_exists('access_filter_cash_accounts')) {
  function access_filter_cash_accounts(PDO $pdo, int $companyId, array $rows, ?int $userId = null): array {
    if (access_is_company_master()) return $rows;
    $allowed = array_fill_keys(access_user_cash_account_ids($pdo, $companyId, $userId), true);
    if (!$allowed) return [];
    return array_values(array_filter($rows, static fn($r) => isset($allowed[(int)($r['id'] ?? 0)])));
  }
}

if (!function_exists('access_can_use_cash_account')) {
  function access_can_use_cash_account(PDO $pdo, int $companyId, ?int $bankId, ?int $userId = null): bool {
    $bankId = (int)($bankId ?? 0);
    if ($bankId <= 0) return true;
    if (access_is_company_master()) return true;
    return in_array($bankId, access_user_cash_account_ids($pdo, $companyId, $userId), true);
  }
}

if (!function_exists('access_assert_cash_account')) {
  function access_assert_cash_account(PDO $pdo, int $companyId, ?int $bankId, string $message = ''): void {
    if (!access_can_use_cash_account($pdo, $companyId, $bankId)) {
      throw new RuntimeException($message !== '' ? $message : 'Este usuário não possui permissão para trabalhar neste caixa/banco.');
    }
  }
}

if (!function_exists('access_user_default_cash_account')) {
  function access_user_default_cash_account(PDO $pdo, int $companyId, int $userId, string $context = 'financeiro'): int {
    $context = strtolower($context);
    $col = match ($context) {
      'pdv' => 'default_pdv_cash_bank_account_id',
      'comanda', 'comandas' => 'default_comanda_cash_bank_account_id',
      default => 'default_cash_bank_account_id',
    };
    try {
      if (access_column_exists($pdo, 'users', $col)) {
        $st = $pdo->prepare('SELECT `' . $col . '` FROM users WHERE company_id = ? AND id = ? LIMIT 1');
        $st->execute([$companyId, $userId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0 && access_can_use_cash_account($pdo, $companyId, $id, $userId)) return $id;
      }
      if ($col !== 'default_cash_bank_account_id' && access_column_exists($pdo, 'users', 'default_cash_bank_account_id')) {
        $st = $pdo->prepare('SELECT default_cash_bank_account_id FROM users WHERE company_id = ? AND id = ? LIMIT 1');
        $st->execute([$companyId, $userId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0 && access_can_use_cash_account($pdo, $companyId, $id, $userId)) return $id;
      }
      $ids = access_user_cash_account_ids($pdo, $companyId, $userId);
      return (int)($ids[0] ?? 0);
    } catch (Throwable $e) { return 0; }
  }
}
