<?php
declare(strict_types=1);

return [
  'timezone' => getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo',

  'brand' => [
    'catalog_title' => '',
    'catalog_subtitle' => 'Catálogo • carrinho • pedidos',
    'primary_color' => '#0f766e',
    'secondary_color' => '#14b8a6',
    'accent_color' => '#f59e0b',
    'text_on_primary' => '#ffffff',
    'follow_logo_colors' => false,
  ],

  'domain' => [
    'subdomain' => '',
    'custom_domain' => '',
  ],

  // Configurações visuais do catálogo/pedido.
  'catalog_ui' => [
    'horizontal_category_menu' => false,
  ],

  // Tema visual do painel administrativo.
  // Por padrão, o admin usa o azul institucional da Nexa Próspero.
  'admin_theme' => [
    'enabled' => false,
    'primary_color' => '#0B1F5B',
    'secondary_color' => '#2454D6',
    'accent_color' => '#00B8FF',
    // Cor final usada no degradê da barra superior, menu lateral e tela de login.
    'gradient_color' => '#06122E',
    'text_color' => '#FFFFFF',
  ],

  // Tema independente para os indicadores de etapas/passo a passo das telas de venda.
  'admin_flow_theme' => [
    'enabled' => false,
    'primary_color' => '#143A9C',
    'secondary_color' => '#2563EB',
    'text_color' => '#FFFFFF',
  ],


  // Preferências de navegação do painel administrativo.
  'admin_ui' => [
    'side_menu_enabled' => false,
  ],

  'cover' => [
    'file' => '',
    'enabled' => true,
  ],

  // Banner promocional (aparece no topo do catálogo)
  'banner' => [
    'enabled' => (getenv('BANNER_ENABLED') ?: '1') === '1',
    'text'    => getenv('BANNER_TEXT') ?: '🔥 Promo: 10% OFF no Pix hoje!',
    'hint'    => getenv('BANNER_HINT') ?: 'Válido por tempo limitado.',
    'link'    => getenv('BANNER_LINK') ?: '',
  ],

  'hours' => [
    'mon' => [['11:00','14:00'], ['18:00','23:00']],
    'tue' => [['11:00','14:00'], ['18:00','23:00']],
    'wed' => [['11:00','14:00'], ['18:00','23:00']],
    'thu' => [['11:00','14:00'], ['18:00','23:00']],
    'fri' => [['11:00','14:00'], ['18:00','23:30']],
    'sat' => [['11:00','15:00'], ['18:00','23:30']],
    'sun' => [['18:00','23:00']],
  ],

  'delivery_scheduling' => [
    'enabled' => false,
    'label' => 'Data/Hora Prevista',
    'required' => false,
    'only_business_hours' => false,
    'min_minutes' => 0,
  ],

  'product_options' => [
    'show_additional_unit_total' => false,
    'show_item_total_with_additionals' => false,
    'item_note_instruction' => 'Ex.: sem cebola, bem passada, retirar ingrediente...',
  ],

  'checkout' => [
    'customer_name_label' => 'Nome/Razão social',
    'customer_name_placeholder' => 'Nome completo ou razão social',
  ],

  // Venda Mobile em eventos: imprime primeiro as vias de produção e, por último,
  // o pedido completo do cliente, sempre no perfil térmico de 58 mm.
  'event_sales' => [
    'enabled' => false,
  ],

  'order_types' => [
    // Chaves legadas; load_business_config cria também os grupos independentes checkout e pdv.
    'entrega' => true,
    'retirada' => true,
    'balcao' => true,
  ],

  'order_reports' => [
    'enabled' => true,
  ],

  'delivery_fees' => [
    'default' => (float)(getenv('TAXA_ENTREGA_DEFAULT') ?: 0),
    'bairros' => [
      'Centro' => 5.00,
      'Bairro A' => 6.00,
      'Bairro B' => 7.00,
    ],
  ],

  'logo' => [
    'file' => '',
    'enabled' => false,
  ],

  'whatsapp_auto_open' => (getenv('WHATSAPP_AUTO_OPEN') ?: '0') === '1',
  'whatsapp_pdv_auto_send' => (getenv('WHATSAPP_PDV_AUTO_SEND') ?: '0') === '1',
];
