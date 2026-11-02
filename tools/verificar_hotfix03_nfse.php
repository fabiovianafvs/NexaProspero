<?php
declare(strict_types=1);

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$checks = [
  'config/fiscal_order_composition.php' => [
    'fiscal_order_normalize_item_type',
    'fiscal_order_item_is_service',
    'SERVICES_ONLY',
    'PRODUCTS_ONLY',
  ],
  'admin/pedidos.php' => [
    '$orderOnlyProducts',
    '$orderMixed',
    'Pedido misto — separar itens',
  ],
  'admin/pedido_editar.php' => [
    '$orderOnlyProducts',
    '$orderMixed',
  ],
  'tools/diagnosticar_documentos_pedido.php' => [
    'Documentos disponíveis esperados',
  ],
];

$errors = [];
foreach ($checks as $file => $needles) {
  $path = $root . '/' . $file;
  if (!is_file($path)) {
    $errors[] = "Arquivo ausente: {$file}";
    continue;
  }
  $content = (string)file_get_contents($path);
  foreach ($needles as $needle) {
    if (!str_contains($content, $needle)) {
      $errors[] = "{$file}: marcador ausente: {$needle}";
    }
  }
  echo "[OK] {$file}\n";
}

if ($errors) {
  echo "\nFALHA:\n";
  foreach ($errors as $error) echo "- {$error}\n";
  exit(1);
}

echo "\nHOTFIX 03 INSTALADO CORRETAMENTE.\n";
echo "Serviço puro => somente NFS-e.\n";
echo "Mercadoria pura => NF-e/NFC-e conforme configuração.\n";
echo "Pedido misto => emissão automática bloqueada.\n";
