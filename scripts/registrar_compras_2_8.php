<?php
declare(strict_types=1);

/** Executar SOMENTE por PHP CLI na raiz da instalação; não executa SQL. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente CLI.'); }
$root = dirname(__DIR__);
$registry = $root . '/config/schema_updater.php';
$sql = $root . '/db/20261015_compras_recebimentos_v2_8.sql';
$id = '20261015_compras_recebimentos_v2_8';
$expected = 'e97710b904d463d306b64cdaeaee6b6ed1ab0645e4f35b4251371c8255d165cf';
if (!is_file($registry) || !is_file($sql)) { fwrite(STDERR, "Falta config/schema_updater.php ou db/$id.sql\n"); exit(1); }
if (!hash_equals($expected, hash_file('sha256', $sql))) { fwrite(STDERR, "Checksum do SQL divergente; nenhuma alteração realizada.\n"); exit(1); }
$handle = fopen($registry, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) { fwrite(STDERR, "Não foi possível bloquear o registro.\n"); exit(1); }
try {
    $source = file_get_contents($registry);
    if ($source === false) throw new RuntimeException('Não foi possível ler o arquivo.');
    if (str_contains($source, "'id' => '$id'")) { echo "Migration já registrada. Nenhuma alteração necessária.\n"; exit(0); }
    $pattern = '/(function\s+nexa_schema_updater_registry\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[)/';
    if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) throw new RuntimeException('Estrutura do catálogo diferente da esperada. Nenhuma alteração realizada.');
    $entry = "\n      [\n" .
      "        'id' => '$id',\n" .
      "        'module' => 'compras_pedidos_recebimentos',\n" .
      "        'version' => 20261015280,\n" .
      "        'label' => 'Compras Fase 2.8 — Recebimentos Parciais',\n" .
      "        'description' => 'Registro administrativo de recebimentos parciais por item e empresa, sem estoque, financeiro ou fiscal.',\n" .
      "        'file' => __DIR__ . '/../db/$id.sql',\n" .
      "        'sha256' => '$expected',\n" .
      "        'order' => 20261015280,\n" .
      "      ],";
    $offset = $match[0][1] + strlen($match[0][0]);
    $updated = substr($source, 0, $offset) . $entry . substr($source, $offset);
    $backup = $registry . '.bak_compras_2_8_' . date('Ymd_His');
    if (!copy($registry, $backup)) throw new RuntimeException('Falha ao criar backup; nenhuma alteração realizada.');
    $temp = tempnam(dirname($registry), '.schema_compras_');
    if ($temp === false) throw new RuntimeException('Falha ao criar arquivo temporário.');
    try {
       if (file_put_contents($temp, $updated) === false) throw new RuntimeException('Falha na escrita temporária.');
       chmod($temp, fileperms($registry) & 0777);
       $out = []; $code = 1;
       exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1', $out, $code);
       if ($code !== 0) throw new RuntimeException('Falha na validação PHP: ' . implode(' ', $out));
       if (!rename($temp, $registry)) throw new RuntimeException('Falha ao aplicar registro.');
    } finally { if (is_file($temp)) unlink($temp); }
    echo "Migration registrada com sucesso. Backup: $backup\n";
    echo "Acesse /admin/atualizador_banco.php como platform_admin e aplique explicitamente a atualização.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
finally { flock($handle, LOCK_UN); fclose($handle); }
