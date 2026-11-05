<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Atualizador controlado de banco (V11.52; base V11.8)
 *
 * Regras:
 * - nenhuma migration é executada automaticamente no login;
 * - somente platform_admin pode aplicar;
 * - migrations são explicitamente cadastradas nesta lista;
 * - arquivo precisa bater com SHA-256 esperado;
 * - execução usa GET_LOCK global para evitar concorrência;
 * - DDL potencialmente destrutiva/administrativa é recusada pelo executor web;
 * - o status de versão continua centralizado em nexa_schema_versions.
 */

require_once __DIR__ . '/schema_runtime.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('nexa_schema_updater_registry')) {
  function nexa_schema_updater_registry(): array {
    return [
      [
        'id' => '20261104_saas_status_bonificada_v1',
        'module' => 'saas_status_complimentary',
        'version' => 20261104002,
        'label' => 'Assinaturas SaaS - status Bonificada',
        'description' => 'Acrescenta o status Bonificada: empresa ativa, sem vencimento, sem contrato e sem cobrança (uso próprio, cortesia ou permuta).',
        'file' => __DIR__ . '/../db/20261104_saas_status_bonificada_v1.sql',
        'sha256' => '034f511752fa08755a305db37e34636048339a967e5585d8583c9a38aced985f',
        'order' => 20261104002,
      ],
      [
        'id' => '20261104_saas_valor_negociado_v1',
        'module' => 'saas_negotiated_price',
        'version' => 20261104001,
        'label' => 'Assinaturas SaaS - valor mensal negociado por módulo',
        'description' => 'Guarda o valor unitário/mês combinado de cada módulo ao ativar a assinatura. Vazio usa o preço do Serviço.',
        'file' => __DIR__ . '/../db/20261104_saas_valor_negociado_v1.sql',
        'sha256' => '4edfdd033ff5da7db08e5d5e6b149a5a9719521bb889232e144e30eea085e06f',
        'order' => 20261104001,
      ],
      [
        'id' => '20261102_senha_link_seguro_v1',
        'module' => 'password_setup',
        'version' => 20261102001,
        'label' => 'Segurança - link seguro para criar senha',
        'description' => 'Cria os links de uso único para o cliente definir a própria senha e apaga as cópias de senha em texto aberto.',
        'file' => __DIR__ . '/../db/20261102_senha_link_seguro_v1.sql',
        'sha256' => '14e24199f74871ae266f7344ee5ab0b17930c31618f8bc358779e81238edb745',
        'order' => 20261102001,
      ],
      [
        'id' => '20261101_pessoas_anexos_privados_v1',
        'module' => 'person_attachments',
        'version' => 20261101001,
        'label' => 'Pessoas - anexos privados em disco',
        'description' => 'Apenas metadados dos anexos, com arquivos separados dos fontes e isolados por empresa.',
        'file' => __DIR__ . '/../db/20261101_pessoas_anexos_privados_v1.sql',
        'sha256' => '9278084cdab93283a68e87970d03623ec1f19197b93e9e474e292379e3875389',
        'order' => 20261101001,
      ],
      [
        'id' => '20261030_caixa_operador_baixas_v11_61',
        'module' => 'finance_settlements',
        'version' => 20261030061,
        'label' => 'Caixa - identificação do usuário responsável pela baixa',
        'description' => 'Adiciona identificação opcional de operador em baixas futuras; histórico antigo permanece sem operador.',
        'file' => __DIR__ . '/../db/20261030_caixa_operador_baixas_v11_61.sql',
        'sha256' => '7a5e4175453ee22c6a62e2061cb70691ff1b2ee87c5afb437e2124af051050e5',
        'order' => 20261030061,
      ],
      [
        'id' => '20261030_saas_aviso_usuario_v11_56',
        'module' => 'saas_user_contract_notice',
        'version' => 20261030001,
        'label' => 'SaaS - Aviso de contrato por usuário',
        'description' => 'Habilita o aviso por usuário com padrão marcado, inclusive administradores criados no cadastro SaaS. Sem execução no login.',
        'file' => __DIR__ . '/../db/20261030_saas_aviso_usuario_v11_56.sql',
        'sha256' => '39b44d4ed75f8b9adfbac592c90ad47dadd2f2ea1a2ba359f155d7c83d528907',
        'order' => 20261030001,
      ],
      [
        'id' => '20261027_caixa_recebimentos_cr_v2',
        'module' => 'gestao_cash_receivables',
        'version' => 20261027002,
        'label' => 'Caixa - recebimentos C/R habilitados por padrao',
        'description' => 'Ativa por padrao novas empresas sem sobrescrever configuracoes explicitas; resumo financeiro separado por origem no PHP.',
        'file' => __DIR__ . '/../db/20261027_caixa_recebimentos_cr_v2.sql',
        'sha256' => '610639036c74011b6c5f0f52bd23854ef63fe46d277c7224738ea361b66cd474',
        'order' => 20261027002,
      ],
      [
        'id' => '20261027_caixa_recebimentos_cr_v1',
        'module' => 'gestao_cash_receivables',
        'version' => 20261027001,
        'label' => 'Caixa — Recebimentos do Contas a Receber separados das vendas',
        'description' => 'Cria configuracao por empresa desligada inicialmente e vinculos de baixa/pagamento/caixa auditaveis, sem alterar o PDV.',
        'file' => __DIR__ . '/../db/20261027_caixa_recebimentos_cr_v1.sql',
        'sha256' => '50e11d81632b33cc8261d25b296dfd1626102cc939ad00cae4c80c67f4b73467',
        'order' => 20261027001,
      ],
      [
        'id' => '20261026_fiscal_st_cfop_nfe_v3',
        'module' => 'fiscal_st_nfe_codes',
        'version' => 20261026003,
        'label' => 'Fiscal — CFOP/CST/CSOSN ST por empresa para NF-e',
        'description' => 'Vincula códigos próprios da NF-e aos cadastros globais de MVA, isolados por empresa e sem alterar NFC-e.',
        'file' => __DIR__ . '/../db/20261026_fiscal_st_cfop_nfe_v3.sql',
        'sha256' => '5a32a30bf763fb4927bc632fda9e3051d829472edd382ee23e7d669363d30d6a',
        'order' => 20261026003,
      ],
      [
        'id' => '20261026_fiscal_st_cliente_v1',
        'module' => 'fiscal_st_recipient',
        'version' => 20261026001,
        'label' => 'Fiscal — Regime da Pessoa Jurídica e ICMS-ST controlado por NF-e',
        'description' => 'Regime no cliente, alíquotas globais por UF/vigência e MVA por NCM/CEST/UF. Motor desligado por padrão, somente NF-e modelo 55 em homologação.',
        'file' => __DIR__ . '/../db/20261026_fiscal_st_cliente_v1.sql',
        'sha256' => '8de71100af611a923e5f256713df7783d1547d02c06c7f8bc8b61d71c3412686',
        'order' => 20261026001,
      ],
      [
        'id' => '20261023_crm_propostas_anexos',
        'module' => 'crm_commercial',
        'version' => 20261023002,
        'label' => 'CRM — Kanban, Propostas e Anexos',
        'description' => 'Tabelas isoladas para propostas comerciais, itens e anexos privados do CRM. Sem impacto fiscal ou financeiro.',
        'file' => __DIR__ . '/../db/20261023_crm_propostas_anexos.sql',
        'sha256' => 'f24ff687e208f847a79703ce7dc94475977d7df7eec929db0273225c0d578505',
        'order' => 20261023002,
      ],
      [
        'id' => '20261023_produto_ocultar_catalogo_pedidos',
        'module' => 'produtos_visibilidade_pedidos',
        'version' => 20261023001,
        'label' => 'Produtos — Ocultação individual no catálogo de pedidos',
        'description' => 'Novo checkbox por produto; não afeta a venda no administrativo nem a visibilidade das demais categorias.',
        'file' => __DIR__ . '/../db/20261023_produto_ocultar_catalogo_pedidos.sql',
        'sha256' => '7e63b6e9c2ebb7254db4b88dff5f6c63534eec5ecf101757035bca7e657d3417',
        'order' => 20261023001,
      ],
      [
        'id' => '20261020_os_mapas_inspecao_custom',
        'module' => 'os_damage_map_custom',
        'version' => 20261020050,
        'label' => 'O.S. — Modelos personalizados de avarias / inspeção',
        'description' => 'Mapas de carro, moto, computador e desenhos personalizados por empresa, com categoria, seleção e duplicação; sem alterar O.S. existentes.',
        'file' => __DIR__ . '/../db/20261020_os_mapas_inspecao_custom.sql',
        'sha256' => 'c5e884b9fa20a07e596eecd46e379e08521e07c5af805389f3327ff7b2186d71',
        'order' => 20261020050,
      ],
      [
        'id' => '20261019_os_etapas_opcionais_v1',
        'module' => 'os_wizard_optional_steps',
        'version' => 20261019900,
        'label' => 'O.S. — Exibição opcional de Veículo e Avarias',
        'description' => 'Configura por empresa a exibição de dados do veículo e avarias, tanto no Desktop quanto no Mobile; sem alterar dados já lançados.',
        'file' => __DIR__ . '/../db/20261019_os_etapas_opcionais_v1.sql',
        'sha256' => 'ef1683fb6c62773429886fb8fee6630fce956ed86b2e5b291fc621bd47f9998d',
        'order' => 20261019900,
      ],
      [
        'id' => '20261015_os_opcoes_contato_impressao',
        'module' => 'os_contact_options',
        'version' => 20261015900,
        'label' => 'O.S. — Configurações de Contato Desktop e Mobile',
        'description' => 'Permite desativar preenchimento automático e exigir contato e telefone por empresa, sem modificar pedidos, estoque ou fiscal.',
        'file' => __DIR__ . '/../db/20261015_os_opcoes_contato_impressao.sql',
        'sha256' => '91255af0645378fd9ae8a1818cbf868efb7d21cea76412a8035deb06fa90ba1b',
        'order' => 20261015900,
      ],
      [
        'id' => '20261015_compras_ranking_pesos_v3_7',
        'module' => 'compras_ranking',
        'version' => 20261015370,
        'label' => 'Compras Fase 3.7 — Pesos do Ranking de Fornecedores',
        'description' => 'Configuração de pesos por empresa; não movimenta estoque, fiscal ou financeiro.',
        'file' => __DIR__ . '/../db/20261015_compras_ranking_pesos_v3_7.sql',
        'sha256' => '14f21327dbf08390c26f2109be480a9d6ddd6a90c58fe07bc8a980aefeaae4b7',
        'order' => 20261015370,
      ],
      [
        'id' => '20261015_compras_previsao_entrega_v3_5',
        'module' => 'compras_pedidos_previsao',
        'version' => 20261015350,
        'label' => 'Compras Fase 3.5 — Previsão de Entrega',
        'description' => 'Adiciona a data prevista de entrega nos pedidos de compra, sem alteração de estoque, financeiro ou fiscal.',
        'file' => __DIR__ . '/../db/20261015_compras_previsao_entrega_v3_5.sql',
        'sha256' => 'a318009a1cf32019681791a00292f722e4c9d710b3386d82d8f5902a6396b442',
        'order' => 20261015350,
      ],
      [
        'id' => '20261015_compras_pedidos_aprovacao_v2_7',
        'module' => 'compras_pedidos_aprovacao',
        'version' => 20261015270,
        'label' => 'Compras Fase 2.7 — Aprovação de Pedidos',
        'description' => 'Auditoria de aprovações de pedidos.',
        'file' => __DIR__ . '/../db/20261015_compras_pedidos_aprovacao_v2_7.sql',
        'sha256' => 'bc13a487b5df5ba9762c847bb439bdd20a15559325d60acd6e33cb0823122832',
        'order' => 20261015270,
      ],
      [
        'id' => '20261015_compras_recebimentos_v2_8',
        'module' => 'compras_pedidos_recebimentos',
        'version' => 20261015280,
        'label' => 'Compras Fase 2.8 — Recebimentos Parciais',
        'description' => 'Registro administrativo de entregas.',
        'file' => __DIR__ . '/../db/20261015_compras_recebimentos_v2_8.sql',
        'sha256' => 'e97710b904d463d306b64cdaeaee6b6ed1ab0645e4f35b4251371c8255d165cf',
        'order' => 20261015280,
      ],
      [
        'id' => '20261015_compras_nfe_v3_0',
        'module' => 'compras_nfe_vinculos',
        'version' => 20261015300,
        'label' => 'Compras Fase 3.0 — Vínculo documental NF-e',
        'description' => 'Referência e auditoria de NF-e por pedido sem efeitos fiscais ou de estoque.',
        'file' => __DIR__ . '/../db/20261015_compras_nfe_v3_0.sql',
        'sha256' => '8280193b7b468223abc81f782739d2714cd12b0382c19b97bcc8f24049f98e8c',
        'order' => 20261015300,
      ],
      [
        'id' => '20261015_compras_pedidos_aprovacao_v2_7',
        'module' => 'compras_pedidos_aprovacao',
        'version' => 20261015270,
        'label' => 'Compras Fase 2.7 — Aprovação de Pedidos',
        'description' => 'Auditoria de aprovações de pedidos.',
        'file' => __DIR__ . '/../db/20261015_compras_pedidos_aprovacao_v2_7.sql',
        'sha256' => 'bc13a487b5df5ba9762c847bb439bdd20a15559325d60acd6e33cb0823122832',
        'order' => 20261015270,
      ],
      [
        'id' => '20261015_compras_recebimentos_v2_8',
        'module' => 'compras_pedidos_recebimentos',
        'version' => 20261015280,
        'label' => 'Compras Fase 2.8 — Recebimentos Parciais',
        'description' => 'Registro administrativo de entregas.',
        'file' => __DIR__ . '/../db/20261015_compras_recebimentos_v2_8.sql',
        'sha256' => 'e97710b904d463d306b64cdaeaee6b6ed1ab0645e4f35b4251371c8255d165cf',
        'order' => 20261015280,
      ],
      [
        'id' => '20261015_compras_nfe_v3_0',
        'module' => 'compras_nfe_vinculos',
        'version' => 20261015300,
        'label' => 'Compras Fase 3.0 — Vínculo documental NF-e',
        'description' => 'Referência e auditoria de NF-e por pedido sem efeitos fiscais ou de estoque.',
        'file' => __DIR__ . '/../db/20261015_compras_nfe_v3_0.sql',
        'sha256' => '8280193b7b468223abc81f782739d2714cd12b0382c19b97bcc8f24049f98e8c',
        'order' => 20261015300,
      ],
      [
        'id' => '20261014_compras_matriz_pedidos_v2_0',
        'module' => 'compras_matriz_pedidos',
        'version' => 20261014200,
        'label' => 'Compras Fase 2.0 — Matriz de cotação e pedidos rascunho',
        'description' => 'Fornecedores cadastrados por cotação, escolhas de propostas e pedidos de compra em rascunho; sem estoque, financeiro ou fiscal.',
        'file' => __DIR__ . '/../db/20261014_compras_matriz_pedidos_v2_0.sql',
        'sha256' => '77a6dc03cac33e67eb3dd66cbbf028baaf8287c46b2acfa2fd7b391ef10472b1',
        'order' => 20261014200,
      ],
      [
        'id' => '20261014_compras_cotacoes_v1_9',
        'module' => 'compras_cotacoes',
        'version' => 20261014190,
        'label' => 'Compras Fase 1.9 — Cotações por fornecedor',
        'description' => 'Cria cotacoes por lista e ofertas por fornecedor para comparacao; sem movimentacao de estoque ou fiscal.',
        'file' => __DIR__ . '/../db/20261014_compras_cotacoes_v1_9.sql',
        'sha256' => '2dfc513754739b804b6525236f3af0d8866d745495d25d3662a1acdf988a1581',
        'order' => 20261014190,
      ],
      [
        'id' => '20261014_compras_listas_v1_8',
        'module' => 'compras_listas',
        'version' => 20261014180,
        'label' => 'Compras Fase 1.8 — Listas persistentes',
        'description' => 'Cria listas de compras em rascunho por empresa, isoladas de estoque, PDV e fiscal.',
        'file' => __DIR__ . '/../db/20261014_compras_listas_v1_8.sql',
        'sha256' => '5a76ab81aeb764f8411a06412df0d217182995977c72b699e8dd652a94f739a9',
        'order' => 20261014180,
      ],
      [
        'id' => '20261013_contract_item_adjustments',
        'module' => 'contract_adjustments',
        'version' => 20261013530,
        'label' => 'Reajustes anuais de contratos — Histórico',
        'description' => 'Cria tabela de auditoria dos reajustes individuais dos itens dos contratos.',
        'file' => __DIR__ . '/../db/20261013_contract_item_adjustments.sql',
        'sha256' => 'fcdab7386474fd8aee72e33941c2a71731a1de8c68e1d07de57e24a6089442e3',
        'order' => 20261013530,
      ],
      [
        'id' => '20260917_atualizador_banco_v11_8',
        'module' => 'schema_updater',
        'version' => 2026091780,
        'label' => 'V11.8 — Atualizador controlado de banco',
        'description' => 'Cria o histórico de migrations e habilita atualização explícita pelo painel, com checksum, trava global e auditoria.',
        'file' => __DIR__ . '/../db/20260917_atualizador_banco_v11_8.sql',
        'sha256' => '4df78886a24b0e81b48aaab6c1ba32704a8aa023b7c865fd939da09da7f02a3f',
        'order' => 2026091780,
      ],
      [
        'id' => '20260928_controle_entregas_v11_39',
        'module' => 'delivery_control',
        'version' => 20260928390,
        'label' => 'V11.39 — Controle de Entregas',
        'description' => 'Cria o módulo isolado de entregas futuras, ordens de entrega, romaneios, veículos, reservas e fila fiscal própria.',
        'file' => __DIR__ . '/../db/20260928_controle_entregas_v11_39.sql',
        'sha256' => 'f8caac56c4ef75493f6b98a7132f63d7ecd5dd7efa3258c63ed1b3013d0e0045',
        'order' => 20260928390,
      ],
      [
        'id' => '20260928_orcamento_os_comunicacao_v11_40',
        'module' => 'budget_os_communication',
        'version' => 20260928400,
        'label' => 'V11.40 — Orçamentos, O.S. e Comunicação',
        'description' => 'Vincula Orçamento e O.S. com transferência segura de reservas e habilita a evolução de comunicação com o cliente.',
        'file' => __DIR__ . '/../db/20260928_orcamento_os_comunicacao_v11_40.sql',
        'sha256' => '2ff4daee95eb5e81d1391b0708bedaaa63163f31016a824e1bd325e41d8306d7',
        'order' => 20260928400,
      ],
      [
        'id' => '20260928_nfse_pronim_itabira_v11_41',
        'module' => 'nfse_pronim',
        'version' => 20260928410,
        'label' => 'V11.41 — NFS-e Pronim/GOVBR · Itabira',
        'description' => 'Adiciona provider NFS-e Pronim/GOVBR isolado do Ambiente Nacional, usando DPS 1.01 e perfil municipal inicial de Itabira/MG.',
        'file' => __DIR__ . '/../db/20260928_nfse_pronim_itabira_v11_41.sql',
        'sha256' => 'c2cb8413b279be12dd3247668b87992a8c20156cda632fbaf74f2dda68289455',
        'order' => 20260928410,
      ],
      [
        'id' => '20260921_user_company_fiscal_series_v11_35_1',
        'module' => 'fiscal_user_company_series',
        'version' => 20260921351,
        'label' => 'V11.35.1 — Série fiscal por usuário e empresa',
        'description' => 'Cria a preferência de série fiscal por usuário, empresa, tipo de documento e ambiente, preservando os campos legados como compatibilidade.',
        'file' => __DIR__ . '/../db/20260921_user_company_fiscal_series_v11_35_1.sql',
        'sha256' => 'a656eec1b0fe2f9e01a80e0fd101dfd2e2bf4eb2224dbaa57817f655d3722c95',
        'order' => 20260921351,
      ],
      [
        'id' => '20260930_importacao_clientes_receber_v11_45',
        'module' => 'data_import_v11_45',
        'version' => 20260930450,
        'label' => 'V11.45 — Clientes e Contas a Receber',
        'description' => 'Cria o mapa de códigos históricos de clientes por empresa e a rastreabilidade idempotente dos lotes de importação do Contas a Receber.',
        'file' => __DIR__ . '/../db/20260930_importacao_clientes_receber_v11_45.sql',
        'sha256' => 'ac31d0f4ffb2039bb1d139d3944afd90248e3ada05df050515ef066ab3d7821b',
        'order' => 20260930450,
      ],
      [
        'id' => '20260930_produto_referencia_v11_45_3',
        'module' => 'product_reference',
        'version' => 20260930453,
        'label' => 'V11.45.3 — Referência de produtos',
        'description' => 'Adiciona a referência comercial do produto, com índice por empresa, suporte à planilha de importação e pesquisa nas telas operacionais.',
        'file' => __DIR__ . '/../db/20260930_produto_referencia_v11_45_3.sql',
        'sha256' => 'dfec4b4563556a93d5196caee25ffbb19cc3ffff4ad8855f7813cd951578aa71',
        'order' => 20260930453,
      ],
      [
        'id' => '20261001_codigo_operacional_produto_v11_45_5',
        'module' => 'product_company_code',
        'version' => 20261001455,
        'label' => 'V11.45.5 — Código operacional do produto',
        'description' => 'Corrige o código visível do produto por empresa, preservando exatamente o código importado, inclusive zeros à esquerda e códigos longos, sem usar o ID interno do banco.',
        'file' => __DIR__ . '/../db/20261001_codigo_operacional_produto_v11_45_5.sql',
        'sha256' => '81b6db42c3e1e92753e204de8d610b31321536dd594f413f81cab81774940e9d',
        'order' => 20261001455,
      ],
      [
        'id' => '20261001_pedido_profissional_v11_45_14',
        'module' => 'order_professional',
        'version' => 202610014514,
        'label' => 'V11.45.14 — Profissional no Pedido Detalhado',
        'description' => 'Adiciona o vínculo de profissional ao cabeçalho do pedido, separado do vendedor, com seleção F8 no Pedido Detalhado.',
        'file' => __DIR__ . '/../db/20261001_pedido_profissional_v11_45_14.sql',
        'sha256' => '3ae8d684be39c9ec10fa14d8472d9de674af2ac3a9e43fc6ac6730ccaca79d43',
        'order' => 202610014514,
      ],
      [
        'id' => '20261001_vales_orcamento_checkout_v11_45_15',
        'module' => 'vouchers_quote_checkout',
        'version' => 202610014515,
        'label' => 'V11.45.15 — Vales, orçamento consumido e permissões de Checkout',
        'description' => 'Cria o controle de vales com uso parcial/múltiplo, impede reutilização de orçamento já convertido em venda e separa as permissões de PDV Checkout e Comanda Checkout.',
        'file' => __DIR__ . '/../db/20261001_vales_orcamento_checkout_v11_45_15.sql',
        'sha256' => '15bd94d0281cce56b38235f084c88dcf87276d20112c956cf7e74586b6de617f',
        'order' => 202610014515,
      ],
      [
        'id' => '20261001_orcamento_margens_grid_v11_45_17',
        'module' => 'budget_margin_negotiation',
        'version' => 202610014517,
        'label' => 'V11.45.17 — Margens e negociação do Orçamento',
        'description' => 'Adiciona snapshot de custo aos itens, margem mínima sobre venda, faixa de atenção e suporte ao grid avançado/painel de negociação dos orçamentos.',
        'file' => __DIR__ . '/../db/20261001_orcamento_margens_grid_v11_45_17.sql',
        'sha256' => '127e7047045950c2c99e8a7f0a929b6b36231d8bda1fe3f17ce96c0986035b8e',
        'order' => 202610014517,
      ],
      [
        'id' => '20261001_pedido_impresso_moderno_v11_45_18',
        'module' => 'order_modern_print',
        'version' => 202610014518,
        'label' => 'V11.45.18 — Pedido A4/72 mm e privacidade de digitação',
        'description' => 'Habilita opcionalmente os novos impressos de Pedido A4 e 72 mm, preservando o modelo legado por padrão, e desativa o histórico/autocomplete do navegador nas telas operacionais.',
        'file' => __DIR__ . '/../db/20261001_pedido_impresso_moderno_v11_45_18.sql',
        'sha256' => '7110c17193de872d131693df9192d81bae3fe416e1f559a16871c86f8d6d94ca',
        'order' => 202610014518,
      ],
      [
        'id' => '20261001_orcamento_totais_teclado_v11_45_19',
        'module' => 'budget_totals_keyboard',
        'version' => 202610014519,
        'label' => 'V11.45.19 — Totais e fluxo rápido do Orçamento',
        'description' => 'Adiciona desconto/acréscimo percentuais e em valor, frete, validação do desconto máximo por usuário e fluxo rápido por Enter/F10 no Orçamento.',
        'file' => __DIR__ . '/../db/20261001_orcamento_totais_teclado_v11_45_19.sql',
        'sha256' => 'cfc049297dca12114d82eb6225fc42e9d84a3865661bf172ad7cb8d306b3404c',
        'order' => 202610014519,
      ],
      [
        'id' => '20261002_crm_atendimento_v11_45_19_15',
        'module' => 'crm_atendimento',
        'version' => 202610021915,
        'label' => 'V11.45.19.15 — CRM / Atendimento',
        'description' => 'Cria o módulo CRM com status e tipos configuráveis, atendimentos, próximos contatos, responsáveis, tramitações e histórico.',
        'file' => __DIR__ . '/../db/20261002_crm_atendimento_v11_45_19_15.sql',
        'sha256' => 'a474b6da35e75fe2ff9d40c2d52d93fb4f3c9a8e42b10ecd9af02938084bc639',
        'order' => 202610021915,
      ],
      [
        'id' => '20261003_fiscal_cancelamento_multiempresa_v11_47',
        'module' => 'fiscal_multi_company_cancel',
        'version' => 20261003470,
        'label' => 'V11.47.2 — Cancelamento fiscal Multiempresa (correção cursor MySQL)',
        'description' => 'Formaliza a estrutura de estorno e libera cancelamento protegido de NF-e/NFC-e pelo F11 em HOMOLOGAÇÃO, com migration idempotente compatível com MySQL 8.0 e execução sem cursores pendentes.',
        'file' => __DIR__ . '/../db/20261003_fiscal_cancelamento_multiempresa_v11_47.sql',
        'sha256' => '43c4a1aab1375ca5e3a087dc06308bdb650a15c320b80ddaa1b5f24306a6e981',
        'order' => 20261003470,
      ],
      [
        'id' => '20261006_saas_assinaturas_fundacao_v11_52',
        'module' => 'saas_subscription_bridge',
        'version' => 20261006520,
        'label' => 'V11.52 — Fundação das Assinaturas SaaS',
        'description' => 'Cria a ponte controlada entre Central SaaS e a empresa faturadora do Admin, com vínculo Empresa/Pessoa/Contrato, mapa de módulos para serviços, Trial, histórico de mudanças e eventos idempotentes.',
        'file' => __DIR__ . '/../db/20261006_saas_assinaturas_fundacao_v11_52.sql',
        'sha256' => '1dda067f6e65d69b7256548cac95c80e4b0b1e0399f344c81428149dd77764da',
        'order' => 20261006520,
      ],
      [
        'id' => '20261007_saas_cortesia_modulos_v11_52_2',
        'module' => 'saas_subscription_bridge',
        'version' => 20261007522,
        'label' => 'V11.52.2 — Módulos faturados e cortesia comercial',
        'description' => 'Adiciona política por assinatura/módulo para manter módulos ativos no SaaS como faturáveis ou cortesia, sem alterar cobranças já geradas.',
        'file' => __DIR__ . '/../db/20261007_saas_cortesia_modulos_v11_52_2.sql',
        'sha256' => '04644c73922381bf20a36efdc5456319f6a5883a268688fb22eebf837ebf8f5b',
        'order' => 20261007522,
      ],
      [
        'id' => '20261007_saas_quantidades_periodos_v11_52_3',
        'module' => 'saas_subscription_bridge',
        'version' => 20261007523,
        'label' => 'V11.52.3 — Quantidades e períodos de renovação SaaS',
        'description' => 'Adiciona quantidade faturável por módulo e prepara ciclos mensais, trimestrais, semestrais e anuais pagos antecipadamente, com desconto configurável e sem gerar cobrança na vinculação.',
        'file' => __DIR__ . '/../db/20261007_saas_quantidades_periodos_v11_52_3.sql',
        'sha256' => 'ca514a3d7cf4c6678487dcadeefbdeb41591a9d2cb8817fa684486ff2e7ff611',
        'order' => 20261007523,
      ],
      [
        'id' => '20261007_contract_billing_mode_v11_52_5',
        'module' => 'contracts_billing_mode',
        'version' => 20261007525,
        'label' => 'V11.52.5 — Status de cobrança dos contratos',
        'description' => 'Separa contratos de cobrança Manual e Automática. Contratos existentes permanecem manuais; contratos vinculados ao SaaS passam a automáticos e ficam fora do Gerar Contratos manual.',
        'file' => __DIR__ . '/../db/20261007_contract_billing_mode_v11_52_5.sql',
        'sha256' => 'e58796b1596e45a5f0473ac82140f0c779d6edea961eea36bed523660fa2f4d3',
        'order' => 20261007525,
      ],
      [
        'id' => '20261007_controle_cartoes_v11_52_7',
        'module' => 'finance_card_control',
        'version' => 20261007527,
        'label' => 'V11.52.7 — Controle de Cartões',
        'description' => 'Cria agenda de recebimento de cartões por parcelas, dias e taxas, registra vendas/parcelas e prepara campos TEF/adquirente para futura conciliação.',
        'file' => __DIR__ . '/../db/20261007_controle_cartoes_v11_52_7.sql',
        'sha256' => 'd59ad96104c9634e7cc8dfeae809460e984882106504d623cafc7a03e115c0fb',
        'order' => 20261007527,
      ],
      [
        'id' => '20261008_crm_temperatura_mensagens_v11_52_8_1',
        'module' => 'crm_temperature_messages',
        'version' => 202610085281,
        'label' => 'V11.52.8.1 — CRM: Temperatura e Mensagens (registro corrigido)',
        'description' => 'Aplica de forma independente a estrutura de temperatura do lead e auditoria de mensagens do CRM, sem depender da versão-base do módulo crm_atendimento.',
        'file' => __DIR__ . '/../db/20261008_crm_temperatura_mensagens_v11_52_8_1.sql',
        'sha256' => '73a4aa4aa2e9d04dc3f9165599a38a2f71c60c3bf00c37e09bdfea180221e0f4',
        'order' => 202610085281,
      ],
      [
        'id' => '20261008_saas_vinculo_reajuste_v11_52_9',
        'module' => 'saas_subscription_adjustment',
        'version' => 202610085290,
        'label' => 'V11.52.9 — Vinculação completa e próximo reajuste',
        'description' => 'Permite criar/reaproveitar a Pessoa durante a migração, criar o contrato mestre sem cobrança e registrar o próximo reajuste anual dos módulos SaaS.',
        'file' => __DIR__ . '/../db/20261008_saas_vinculo_reajuste_v11_52_9.sql',
        'sha256' => 'b9b9ce2242a3f15c4c95f7d7deaef68a3d1580708c80f93ce20d706c53446612',
        'order' => 202610085290,
      ],
    ];
  }
}

if (!function_exists('nexa_schema_updater_current_version')) {
  function nexa_schema_updater_current_version(PDO $pdo, string $module): int {
    if ($module === '') return 0;
    try {
      $st = $pdo->prepare('SELECT version_number FROM nexa_schema_versions WHERE module_name=? LIMIT 1');
      $st->execute([$module]);
      $value = $st->fetchColumn();
      $st->closeCursor();
      return $value === false ? 0 : (int)$value;
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('nexa_schema_updater_file_state')) {
  function nexa_schema_updater_file_state(array $migration): array {
    $file = (string)($migration['file'] ?? '');
    $expected = strtolower((string)($migration['sha256'] ?? ''));
    if ($file === '' || !is_file($file) || !is_readable($file)) {
      return ['ok' => false, 'hash' => '', 'message' => 'Arquivo SQL não encontrado ou sem permissão de leitura.'];
    }
    $hash = strtolower((string)hash_file('sha256', $file));
    if ($expected === '' || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
      return ['ok' => false, 'hash' => $hash, 'message' => 'Checksum esperado inválido no registro da migration.'];
    }
    if (!hash_equals($expected, $hash)) {
      return ['ok' => false, 'hash' => $hash, 'message' => 'Checksum do arquivo SQL não confere com o pacote homologado.'];
    }
    return ['ok' => true, 'hash' => $hash, 'message' => 'Arquivo íntegro.'];
  }
}

if (!function_exists('nexa_schema_updater_status')) {
  function nexa_schema_updater_status(PDO $pdo): array {
    $rows = nexa_schema_updater_registry();
    usort($rows, static fn(array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
    $out = [];
    foreach ($rows as $migration) {
      $current = nexa_schema_updater_current_version($pdo, (string)$migration['module']);
      $required = (int)$migration['version'];
      $fileState = nexa_schema_updater_file_state($migration);
      $out[] = $migration + [
        'current_version' => $current,
        'applied' => $current >= $required,
        'pending' => $current < $required,
        'file_ok' => !empty($fileState['ok']),
        'file_hash' => (string)($fileState['hash'] ?? ''),
        'file_message' => (string)($fileState['message'] ?? ''),
      ];
    }
    return $out;
  }
}

if (!function_exists('nexa_schema_updater_pending_count')) {
  function nexa_schema_updater_pending_count(PDO $pdo, bool $refresh = false): int {
    if (function_exists('auth_role') && auth_role() !== 'platform_admin') return 0;
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('auth_start')) auth_start();
    $cacheKey = 'nexa_schema_updater_pending_v1';
    if (!$refresh && isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
      $age = time() - (int)($_SESSION[$cacheKey]['at'] ?? 0);
      if ($age >= 0 && $age < 60) return (int)($_SESSION[$cacheKey]['count'] ?? 0);
    }
    $count = 0;
    foreach (nexa_schema_updater_status($pdo) as $row) {
      if (!empty($row['pending'])) $count++;
    }
    $_SESSION[$cacheKey] = ['at' => time(), 'count' => $count];
    return $count;
  }
}

if (!function_exists('nexa_schema_updater_cache_clear')) {
  function nexa_schema_updater_cache_clear(): void {
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('auth_start')) auth_start();
    unset($_SESSION['nexa_schema_updater_pending_v1']);
  }
}

if (!function_exists('nexa_schema_updater_csrf_token')) {
  function nexa_schema_updater_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('auth_start')) auth_start();
    if (empty($_SESSION['nexa_schema_updater_csrf']) || !is_string($_SESSION['nexa_schema_updater_csrf'])) {
      $_SESSION['nexa_schema_updater_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['nexa_schema_updater_csrf'];
  }
}

if (!function_exists('nexa_schema_updater_csrf_check')) {
  function nexa_schema_updater_csrf_check(string $token): void {
    $expected = nexa_schema_updater_csrf_token();
    if ($token === '' || !hash_equals($expected, $token)) {
      throw new RuntimeException('Sessão expirada ou token de segurança inválido. Atualize a página e tente novamente.');
    }
  }
}

if (!function_exists('nexa_schema_updater_log_table_exists')) {
  function nexa_schema_updater_log_table_exists(PDO $pdo): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute(['nexa_schema_migration_log']);
      $exists = (bool)$st->fetchColumn();
      $st->closeCursor();
      return $exists;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('nexa_schema_updater_log_ensure')) {
  function nexa_schema_updater_log_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_migration_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      migration_id VARCHAR(120) NOT NULL,
      module_name VARCHAR(80) NOT NULL,
      version_number BIGINT UNSIGNED NOT NULL,
      status VARCHAR(20) NOT NULL,
      started_at DATETIME NOT NULL,
      finished_at DATETIME NULL,
      executed_by_user_id INT NULL,
      database_name VARCHAR(128) NULL,
      checksum_sha256 CHAR(64) NULL,
      error_message VARCHAR(1000) NULL,
      PRIMARY KEY (id),
      KEY idx_nexa_migration_log_version (module_name,version_number),
      KEY idx_nexa_migration_log_status (status,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('nexa_schema_updater_sql_split')) {
  function nexa_schema_updater_sql_split(string $sql): array {
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $len; $i++) {
      $ch = $sql[$i];
      $next = $i + 1 < $len ? $sql[$i + 1] : '';

      if ($lineComment) {
        if ($ch === "\n") {
          $lineComment = false;
          $buf .= $ch;
        }
        continue;
      }
      if ($blockComment) {
        if ($ch === '*' && $next === '/') {
          $blockComment = false;
          $i++;
        }
        continue;
      }

      if ($quote === null) {
        if ($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
          $lineComment = true;
          $i++;
          continue;
        }
        if ($ch === '#') {
          $lineComment = true;
          continue;
        }
        if ($ch === '/' && $next === '*') {
          $blockComment = true;
          $i++;
          continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
          $quote = $ch;
          $buf .= $ch;
          continue;
        }
        if ($ch === ';') {
          $statement = trim($buf);
          if ($statement !== '') $statements[] = $statement;
          $buf = '';
          continue;
        }
        $buf .= $ch;
        continue;
      }

      $buf .= $ch;
      if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
        $buf .= $sql[++$i];
        continue;
      }
      if ($ch === $quote) {
        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
          $buf .= $sql[++$i];
          continue;
        }
        $quote = null;
      }
    }

    if ($quote !== null || $blockComment) {
      throw new RuntimeException('Arquivo SQL incompleto: aspas ou comentário de bloco não finalizado.');
    }
    $tail = trim($buf);
    if ($tail !== '') $statements[] = $tail;
    return $statements;
  }
}

if (!function_exists('nexa_schema_updater_assert_statement_safe')) {
  function nexa_schema_updater_assert_statement_safe(string $statement): void {
    $normalized = strtoupper(preg_replace('/\s+/', ' ', trim($statement)) ?? '');
    if ($normalized === '') return;
    $blocked = [
      '/\bDROP\s+DATABASE\b/',
      '/\bCREATE\s+DATABASE\b/',
      '/\bGRANT\b/',
      '/\bREVOKE\b/',
      '/\bCREATE\s+USER\b/',
      '/\bALTER\s+USER\b/',
      '/\bDROP\s+USER\b/',
      '/\bINTO\s+OUTFILE\b/',
      '/\bLOAD\s+DATA\b/',
      '/\bLOAD_FILE\s*\(/',
      '/\bDELIMITER\b/',
      '/^SOURCE\b/',
    ];
    foreach ($blocked as $pattern) {
      if (preg_match($pattern, $normalized)) {
        throw new RuntimeException('A migration contém um comando não permitido pelo atualizador web. Execute esta migration por procedimento administrativo controlado.');
      }
    }
  }
}

if (!function_exists('nexa_schema_updater_exec_statement')) {
  /**
   * Executa uma instrução da migration e drena qualquer result set que ela possa produzir.
   * Isto é essencial para EXECUTE/CALL/SELECT no MySQL quando PDO opera com cursores não-bufferizados.
   */
  function nexa_schema_updater_exec_statement(PDO $pdo, string $statement): void {
    $trimmed = ltrim($statement);
    $head = strtoupper((string)strtok($trimmed, " \t\r\n"));
    $mayReturnRows = in_array($head, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'CALL', 'EXECUTE'], true);

    if (!$mayReturnRows) {
      $pdo->exec($statement);
      return;
    }

    $st = $pdo->query($statement);
    if (!($st instanceof PDOStatement)) return;
    try {
      do {
        if ($st->columnCount() > 0) {
          $st->fetchAll(PDO::FETCH_NUM);
        }
      } while ($st->nextRowset());
    } catch (Throwable $ignored) {
      // Alguns drivers não expõem nextRowset() quando há apenas um resultado.
    } finally {
      try { $st->closeCursor(); } catch (Throwable $ignored) {}
    }
  }
}

if (!function_exists('nexa_schema_updater_find')) {
  function nexa_schema_updater_find(string $id): ?array {
    foreach (nexa_schema_updater_registry() as $migration) {
      if (hash_equals((string)$migration['id'], $id)) return $migration;
    }
    return null;
  }
}

if (!function_exists('nexa_schema_updater_next_pending')) {
  function nexa_schema_updater_next_pending(PDO $pdo): ?array {
    foreach (nexa_schema_updater_status($pdo) as $row) {
      if (!empty($row['pending'])) return $row;
    }
    return null;
  }
}

if (!function_exists('nexa_schema_updater_apply')) {
  function nexa_schema_updater_apply(PDO $pdo, string $migrationId, int $userId): array {
    if (function_exists('auth_role') && auth_role() !== 'platform_admin') {
      throw new RuntimeException('Somente o administrador da plataforma pode executar migrations do banco.');
    }

    $migration = nexa_schema_updater_find($migrationId);
    if (!$migration) throw new RuntimeException('Migration não registrada no pacote atual.');

    $next = nexa_schema_updater_next_pending($pdo);
    if (!$next || (string)$next['id'] !== $migrationId) {
      throw new RuntimeException('Somente a próxima migration pendente pode ser executada. Atualize a página e confira a ordem.');
    }

    $fileState = nexa_schema_updater_file_state($migration);
    if (empty($fileState['ok'])) throw new RuntimeException((string)$fileState['message']);

    $file = (string)$migration['file'];
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') throw new RuntimeException('Arquivo SQL vazio ou ilegível.');

    $statements = nexa_schema_updater_sql_split($raw);
    if (!$statements) throw new RuntimeException('Nenhum comando SQL válido encontrado na migration.');
    foreach ($statements as $statement) nexa_schema_updater_assert_statement_safe($statement);

    $lockName = 'nexa_schema_updater_global_v1';
    $lockSt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockSt->execute([$lockName]);
    $lockOk = (int)$lockSt->fetchColumn();
    $lockSt->closeCursor();
    if ($lockOk !== 1) {
      throw new RuntimeException('Outra atualização de banco está em andamento. Aguarde alguns segundos e tente novamente.');
    }

    $logId = 0;
    $dbName = '';
    try {
      $dbSt = $pdo->query('SELECT DATABASE()');
      $dbName = (string)($dbSt->fetchColumn() ?: '');
      $dbSt->closeCursor();
      $current = nexa_schema_updater_current_version($pdo, (string)$migration['module']);
      if ($current >= (int)$migration['version']) {
        nexa_schema_updater_cache_clear();
        return ['ok' => true, 'message' => 'A migration já estava aplicada.', 'statements' => 0];
      }

      // DDL somente após ação explícita do administrador.
      nexa_schema_updater_log_ensure($pdo);
      $stLog = $pdo->prepare('INSERT INTO nexa_schema_migration_log
        (migration_id,module_name,version_number,status,started_at,executed_by_user_id,database_name,checksum_sha256)
        VALUES (?,?,?,\'RUNNING\',NOW(),?,?,?)');
      $stLog->execute([
        (string)$migration['id'],
        (string)$migration['module'],
        (int)$migration['version'],
        $userId > 0 ? $userId : null,
        $dbName !== '' ? $dbName : null,
        (string)$fileState['hash'],
      ]);
      $logId = (int)$pdo->lastInsertId();

      $executed = 0;
      foreach ($statements as $statement) {
        nexa_schema_updater_exec_statement($pdo, $statement);
        $executed++;
      }

      // Só marca a versão depois de todos os comandos concluírem.
      nexa_schema_version_mark(
        $pdo,
        (string)$migration['module'],
        (int)$migration['version'],
        (string)$migration['label']
      );

      $verified = nexa_schema_updater_current_version($pdo, (string)$migration['module']);
      if ($verified < (int)$migration['version']) {
        throw new RuntimeException('Os comandos terminaram, mas a versão do schema não foi registrada corretamente.');
      }

      if ($logId > 0) {
        $pdo->prepare("UPDATE nexa_schema_migration_log SET status='SUCCESS',finished_at=NOW(),error_message=NULL WHERE id=?")
          ->execute([$logId]);
      }
      nexa_schema_updater_cache_clear();
      return ['ok' => true, 'message' => 'Atualização aplicada com sucesso.', 'statements' => $executed];
    } catch (Throwable $e) {
      if ($logId > 0) {
        try {
          $pdo->prepare("UPDATE nexa_schema_migration_log SET status='FAILED',finished_at=NOW(),error_message=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), $logId]);
        } catch (Throwable $ignored) {
        }
      }
      error_log('[NEXA DB UPDATER] Migration ' . $migrationId . ' falhou: ' . $e->getMessage());
      throw $e;
    } finally {
      try {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
        $release->fetchColumn();
        $release->closeCursor();
      } catch (Throwable $ignored) {
      }
    }
  }
}

if (!function_exists('nexa_schema_updater_history')) {
  function nexa_schema_updater_history(PDO $pdo, int $limit = 30): array {
    if (!nexa_schema_updater_log_table_exists($pdo)) return [];
    $limit = max(1, min(100, $limit));
    try {
      return $pdo->query('SELECT id,migration_id,module_name,version_number,status,started_at,finished_at,executed_by_user_id,database_name,checksum_sha256,error_message
        FROM nexa_schema_migration_log ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return [];
    }
  }
}
