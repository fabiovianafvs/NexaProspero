<?php
declare(strict_types=1);

$root = rtrim((string)($argv[1] ?? dirname(__DIR__)), '/');
$checks = [
  'admin/pedidos.php' => [
    'Emitir NFC-e',
    'Emitir NF-e',
    'Emitir NFS-e',
    'Emitir Doc Fiscal ▾',
    '$orderOnlyProducts || $orderMixed',
    '$orderOnlyServices || $orderMixed',
  ],
  'admin/pedido_editar.php' => [
    'Emitir NFC-e',
    'Emitir NF-e',
    'Emitir NFS-e',
    'Emitir Doc Fiscal ▾',
  ],
  'admin/fiscal_nfse_nacional.php' => [
    'fiscal_order_item_is_service',
    '$selectedServiceCount > 0',
  ],
  'config/fiscal_operational.php' => [
    'fiscal_order_composition.php',
    '$allOrderItems',
    '$isMixedFiscalOrder',
    'Este pedido não possui mercadorias para emissão',
  ],
  'config/nfse_emitter.php' => [
    '$serviceItems',
    '$isMixedOrder',
    'O pedido não possui itens de serviço para emissão da NFS-e.',
  ],
  'config/fiscal_order_composition.php' => [
    'NF-e/NFC-e usam mercadorias e NFS-e usa serviços',
  ],
];

$forbidden = [
  'admin/pedidos.php' => [
    'Pedido misto — emissão fiscal separada por tipo de item',
    'Pedido misto — emissão separada por item',
  ],
  'admin/pedido_editar.php' => [
    'Pedido misto — emissão fiscal separada por tipo de item',
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

foreach ($forbidden as $file => $needles) {
  $path = $root . '/' . $file;
  if (!is_file($path)) continue;
  $content = (string)file_get_contents($path);
  foreach ($needles as $needle) {
    if (str_contains($content, $needle)) {
      $errors[] = "{$file}: texto antigo ainda presente: {$needle}";
    }
  }
}

if ($errors) {
  echo "\nFALHA:\n";
  foreach ($errors as $error) echo "- {$error}\n";
  exit(1);
}

echo "\nAJUSTE DE PEDIDO MISTO VALIDADO.\n";
echo "NF-e/NFC-e => somente mercadorias.\n";
echo "NFS-e => somente serviços.\n";
echo "Tela de Pedidos => botão único Emitir Doc Fiscal com NFC-e, NF-e e NFS-e no menu.\n";
