<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execute somente pelo terminal.\n");
}

$root = rtrim((string)($argv[1] ?? dirname(__DIR__)), '/\\');
if (!is_dir($root . '/admin') || !is_dir($root . '/config')) {
  fwrite(STDERR, "Raiz do Nexa inválida: {$root}\n");
  exit(2);
}

function patch_file(string $file, callable $patch): void {
  if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
    throw new RuntimeException("Arquivo não pode ser alterado: {$file}");
  }
  $content = (string)file_get_contents($file);
  $updated = $patch($content);
  if ($updated === $content) {
    echo "[OK] sem alteração: {$file}\n";
    return;
  }
  $backup = $file . '.bak-nfse-' . date('Ymd-His');
  if (!copy($file, $backup)) throw new RuntimeException("Falha ao criar backup: {$backup}");
  if (file_put_contents($file, $updated, LOCK_EX) === false) throw new RuntimeException("Falha ao gravar: {$file}");
  echo "[ALTERADO] {$file}\n[BACKUP] {$backup}\n";
}

try {
  patch_file($root . '/config/saas_modules.php', static function (string $s): string {
    if (str_contains($s, "'fiscal_nfse_nacional.php'")) return $s;
    $anchor = "'fiscal_documentos.php', 'fiscal_nfce_pedido.php',";
    if (!str_contains($s, $anchor)) throw new RuntimeException('Âncora fiscal não encontrada em saas_modules.php.');
    return str_replace($anchor, "'fiscal_documentos.php', 'fiscal_nfce_pedido.php', 'fiscal_nfse_nacional.php', 'fiscal_nfse_acao.php',", $s);
  });

  patch_file($root . '/config/access_control.php', static function (string $s): string {
    if (str_contains($s, "'fiscal_nfse_nacional.php'")) return $s;
    $anchor = "'fiscal_nfce_imprimir.php','fiscal_nfce_acao.php','fiscal_lote_contingencia.php'";
    if (!str_contains($s, $anchor)) throw new RuntimeException('Âncora fiscal não encontrada em access_control.php.');
    return str_replace($anchor, "'fiscal_nfce_imprimir.php','fiscal_nfce_acao.php','fiscal_nfse_nacional.php','fiscal_nfse_acao.php','fiscal_lote_contingencia.php'", $s);
  });

  patch_file($root . '/admin/_fiscal.php', static function (string $s): string {
    if (str_contains($s, "'fiscal_nfse_nacional' => ['NFS-e Nacional'")) return $s;
    $anchor = "'fiscal_documentos' => ['Documentos fiscais', 'fiscal_documentos.php'],";
    if (!str_contains($s, $anchor)) throw new RuntimeException('Âncora de abas não encontrada em admin/_fiscal.php.');
    return str_replace($anchor, $anchor . "\n      'fiscal_nfse_nacional' => ['NFS-e Nacional', 'fiscal_nfse_nacional.php'],", $s);
  });

  echo "INTEGRAÇÃO VISUAL E DE PERMISSÕES APLICADA COM SUCESSO.\n";
} catch (Throwable $e) {
  fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
  exit(3);
}
