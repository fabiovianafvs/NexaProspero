<?php
declare(strict_types=1);

return [
  'site_name' => 'Sistema de Gestão Nexa Próspero',
  'base_url'  => '',
  'uploads_url' => 'uploads/products',
  // WhatsApp da loja no formato E.164 (apenas números). Ex: 5531999999999
  // Pode ser sobrescrito por variável de ambiente WHATSAPP_LOJA.
  'whatsapp_loja' => getenv('WHATSAPP_LOJA') ?: '5531988552002',

  // Checkout
  // Taxa fixa de entrega (em reais). Pode ser sobrescrita por TAXA_ENTREGA.
  'taxa_entrega' => (float)(getenv('TAXA_ENTREGA') ?: 0),
  // Texto exibido para retirada
  'retirada_label' => getenv('RETIRADA_LABEL') ?: 'Retirada no balcão',
  // Senha do admin (central) - use variável de ambiente ADMIN_PASSWORD.
  'admin_password' => getenv('ADMIN_PASSWORD') ?: '123456',
  // Impressão térmica 80mm (Epson TM-T20 / Bematech MP-4200)
  'print_cols' => (int)(getenv('PRINT_COLS') ?: 48),
  'print_paper' => getenv('PRINT_PAPER') ?: '80mm',
];
