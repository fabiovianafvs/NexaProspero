<?php
declare(strict_types=1);

/**
 * Integração Firebird / Próspero.
 * Ajuste via Configurações no painel quando necessário.
 */
return [
  'enabled' => (getenv('FIREBIRD_ENABLED') ?: '0') === '1',

  'host' => getenv('FIREBIRD_HOST') ?: '127.0.0.1',
  'port' => (int)(getenv('FIREBIRD_PORT') ?: 3050),
  'database' => getenv('FIREBIRD_DATABASE') ?: '',
  'user' => getenv('FIREBIRD_USER') ?: 'SYSDBA',
  'pass' => getenv('FIREBIRD_PASS') ?: 'masterkey',
  'charset' => getenv('FIREBIRD_CHARSET') ?: 'UTF8',

  // Quando ativo, o pedido é enviado ao Firebird automaticamente após ser gravado no Nexa Próspero.
  'auto_export_on_order' => (getenv('FIREBIRD_AUTO_EXPORT_ON_ORDER') ?: '0') === '1',

  // códigos fixos por tenant no Próspero
  'empresa_codigo' => (int)(getenv('FIREBIRD_EMPRESA_CODIGO') ?: 1),
  'cliente_padrao' => (int)(getenv('FIREBIRD_CLIENTE_PADRAO') ?: 1),

  'tables' => [
    'orders' => 'ORCAMENTO',
    'items'  => 'ORCAMENTO_ITENS',
    'products' => 'CAD_PRODUTOS',
  ],

  'fields' => [
    'orders' => [
      'empresa' => 'EMPRESA',
      'codigo'  => 'CODIGO',
      'status'  => 'STATUS',
      'cliente' => 'CLIENTE',
      'data'    => 'DATA',
      'frete'   => 'VLR_FRETE',
      'total'   => 'VLR_TOTAL',
      'obs'      => 'OBSERVACAO',
      'tipo'     => 'TIPO',
      'operacao' => 'OPERACAO',
      'vendedor' => 'VENDEDOR',
      'vlr_produtos' => 'VLR_PRODUTOS',
      'situacao' => 'SITUACAO',
      'plano_contas' => 'PLANO_CONTAS',
      'transportadora' => 'TRANSPORTADORA',
      'cfop' => 'CFOP',
      'data_entrega' => 'DATA_ENTREGA',
      'usuario_cadastro' => 'USUARIO_CADASTRO',
      'centro_custos' => 'CENTRO_CUSTOS',
      'status_id' => 'STATUS_ID',
    ],
    'items' => [
      'idx'               => 'IDX',
      'codigo_orcamento'  => 'CODIGO',
      'empresa'           => 'EMPRESA',
      'cliente'           => 'CLIENTE',
      'referencia'        => 'REFERENCIA',
      'descricao'         => 'DESCRICAO',
      'qtde'              => 'QTDE',
      'vlr_unit'          => 'VLR_UNIT',
      'vlr_total'         => 'VLR_TOTAL',
      'frete_rateio'      => 'FRETE_RATEIO',
      'total_com_rateios' => 'TOTAL_COM_RATEIOS',

      // campos extras que podem existir no schema do Próspero
      'und'               => 'UND',
      'tipo'              => 'TIPO',
      'data'              => 'DATA',
      'data_vendedor'     => 'DATA_VENDEDOR',
      'codprod'           => 'CODPROD',
      'prc_venda'         => 'PRC_VENDA',
      'transportadora'     => 'TRANSPORTADORA',
      'cfop'               => 'CFOP',
      'acrescimos'        => 'ACRESCIMOS',
      'vlr_acrescimo'     => 'VLR_ACRESCIMO',
      'acrescimo_rateio'  => 'ACRESCIMO_RATEIO',
      'desconto'          => 'DESCONTO',
      'vlr_desconto'      => 'VLR_DESCONTO',
      'desconto_rateio'   => 'DESCONTO_RATEIO',
      'vlr_impostos'      => 'VLR_IMPOSTOS',
      'qtde_liberada'     => 'QTDE_LIBERADA',
      'qtde_faturada'     => 'QTDE_FATURADA',
      'qtde_cancelada'    => 'QTDE_CANCELADA',
      'cst'               => 'CST',
      'csosn'             => 'CSOSN',
      'origem'            => 'ORIGEM',
    ],
    'products' => [
      'codigo'            => 'CODIGO',
      'cfop'              => 'CFOPDENTROESTADO',
      'cst'               => 'CST',
      'csosn'             => 'CSOSN',
      'origem'            => 'ORIGEM',
    ],
  ],

  // valores padrão para colunas obrigatórias do ERP
  'defaults' => [
    'orders' => [
      'tipo'              => 'ORCAMENTO',
      'operacao'          => 7,
      'vendedor'          => 2,
      'situacao'          => 1,
      'plano_contas'      => '1.1',
      'transportadora'    => 3,
      'cfop'              => '5102',
      'usuario_cadastro'  => 1,
      'centro_custos'     => 1,
      'status_id'         => 1,
    ],
    'items' => [
      'und'               => 'UN',
      'tipo'              => 'V',
      'transportadora'    => 3,
      'cfop'              => '5102',
      'acrescimos'        => 0,
      'vlr_acrescimo'     => 0,
      'acrescimo_rateio'  => 0,
      'desconto'          => 0,
      'vlr_desconto'      => 0,
      'desconto_rateio'   => 0,
      'vlr_impostos'      => 0,
      'qtde_liberada'     => 0,
      'qtde_faturada'     => 0,
      'qtde_cancelada'    => 0,
      'cst'               => '',
      'csosn'             => '',
      'origem'            => '',
    ],
  ],
];
