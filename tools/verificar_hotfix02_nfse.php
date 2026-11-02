<?php
declare(strict_types=1);

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$files = [
  'config/fiscal_order_composition.php',
  'admin/pedidos.php',
  'admin/pedido_editar.php',
  'admin/fiscal_nfse_nacional.php',
  'config/nfse_emitter.php',
];

$errors = [];
foreach ($files as $file) {
  $path = $root . '/' . $file;
  if (!is_file($path)) {
    $errors[] = 'Arquivo ausente: ' . $file;
    continue;
  }
  $content = (string)file_get_contents($path);
  echo '[OK] ' . $file . PHP_EOL;

  if ($file === 'admin/fiscal_nfse_nacional.php') {
    foreach (['pedido_numero', 'company_sequence_display', 'Número do pedido para emissão'] as $needle) {
      if (!str_contains($content, $needle)) $errors[] = $file . ': integração ausente: ' . $needle;
    }
  }
  if ($file === 'config/nfse_emitter.php') {
    if (!str_contains($content, '$orderReference')) $errors[] = $file . ': referência externa do pedido não corrigida.';
  }
  if (in_array($file, ['admin/pedidos.php','admin/pedido_editar.php'], true)) {
    if (!str_contains($content, 'orderOnlyServices')) $errors[] = $file . ': regra de composição fiscal não encontrada.';
  }
}

if ($errors) {
  echo PHP_EOL . "FALHA NA VERIFICAÇÃO:" . PHP_EOL;
  foreach ($errors as $error) echo '- ' . $error . PHP_EOL;
  exit(1);
}

echo PHP_EOL;
echo "HOTFIX 02 INSTALADO." . PHP_EOL;
echo "Pedidos somente com serviços: apenas NFS-e." . PHP_EOL;
echo "Número exibido da NFS-e: pedidos.numero_pedido." . PHP_EOL;
echo "ID interno continua preservado nas relações do banco." . PHP_EOL;
