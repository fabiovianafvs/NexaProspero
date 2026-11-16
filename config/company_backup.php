<?php
declare(strict_types=1);

/*
 * Nexa Próspero - Backup da Empresa (módulo do SaaS "backup_empresa").
 *
 * Gera um .sql.gz só com os dados da empresa: todas as tabelas que têm a coluna
 * company_id (filtradas por ela) e a linha da própria empresa em companies.
 * Por segurança NÃO entram senhas, tokens, chaves e certificados (colunas com
 * esses nomes vão como NULL) nem a tabela app_config (credenciais de bancos,
 * WhatsApp, IA etc.): qualquer usuário com a permissão pode baixar o arquivo.
 * O arquivo só tem INSERTs (sem DROP/CREATE): é cópia de segurança dos dados,
 * não um script para sobrescrever o banco compartilhado.
 * Arquivos em <pasta de backup>/empresas/<company_id>/, guardando os 10 mais recentes.
 */

require_once __DIR__ . '/database_backup.php';

const COMPANY_BACKUP_KEEP = 10;

if (!function_exists('company_backup_dir')) {
  function company_backup_dir(int $companyId): string {
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
    $dir = nexa_db_backup_root() . '/empresas/' . $companyId;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar a pasta de backup da empresa.');
    return $dir;
  }
}

if (!function_exists('company_backup_skip_tables')) {
  /** Tabelas que não entram: credenciais e controles internos da plataforma. */
  function company_backup_skip_tables(): array {
    return ['app_config', 'nexa_schema_versions', 'nexa_schema_migration_log'];
  }
}

if (!function_exists('company_backup_secret_column')) {
  function company_backup_secret_column(string $column): bool {
    return (bool)preg_match('/(password|passwd|(^|_)pass(_|$)|senha|token|secret|segredo|api_?key|private_?key|chave_privada|client_?secret|certificad|certificate|cert_(content|data|file|pass)|(^|_)(pfx|p12|pem|otp|totp|hmac|2fa)(_|$))/i', $column);
  }
}

if (!function_exists('company_backup_tables')) {
  /** [tabela => [colunas => tipo]] das tabelas com company_id (e companies). */
  function company_backup_tables(PDO $pdo): array {
    $st = $pdo->query("SELECT c.table_name, c.column_name, c.data_type FROM information_schema.columns c
      INNER JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
      WHERE c.table_schema = DATABASE() ORDER BY c.table_name, c.ordinal_position");
    $all = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as [$table, $column, $type]) $all[(string)$table][(string)$column] = strtolower((string)$type);
    $out = [];
    foreach ($all as $table => $cols) {
      if (in_array($table, company_backup_skip_tables(), true)) continue;
      if (isset($cols['company_id']) || ($table === 'companies' && isset($cols['id']))) $out[$table] = $cols;
    }
    ksort($out);
    return $out;
  }
}

if (!function_exists('company_backup_value')) {
  function company_backup_value(PDO $pdo, $value, string $type): string {
    if ($value === null) return 'NULL';
    if (in_array($type, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit', 'geometry', 'point', 'polygon', 'linestring'], true)) {
      return $value === '' ? "''" : '0x' . bin2hex((string)$value);
    }
    if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true) && is_numeric($value)) return (string)$value;
    return $pdo->quote((string)$value);
  }
}

if (!function_exists('company_backup_slug')) {
  function company_backup_slug(string $name): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name) ?? '', '-'));
    return substr($s !== '' ? $s : 'empresa', 0, 40);
  }
}

if (!function_exists('company_backup_create')) {
  function company_backup_create(PDO $pdo, int $companyId, int $userId, string $companyName = ''): array {
    if (!function_exists('gzopen')) throw new RuntimeException('A extensão zlib do PHP não está disponível para compactar o backup.');
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $dir = company_backup_dir($companyId);
    $lock = @fopen($dir . '/.backup.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) @fclose($lock); throw new RuntimeException('Já existe um backup desta empresa em andamento. Aguarde a conclusão.'); }
    $base = 'backup_' . company_backup_slug($companyName) . '_' . date('Y-m-d_H-i-s');
    $out = $dir . '/' . $base . '.sql.gz';
    $gz = null;
    try {
      $gz = @gzopen($out, 'wb6');
      if (!$gz) throw new RuntimeException('Não foi possível criar o arquivo do backup.');
      @chmod($out, 0600);
      $w = static function (string $s) use (&$gz): void { if (gzwrite($gz, $s) === false) throw new RuntimeException('Falha ao gravar o backup.'); };
      $w("-- Nexa Próspero - Backup da empresa #{$companyId} (" . $companyName . ")\n-- Gerado em " . date('d/m/Y H:i:s') . "\n"
        . "-- Somente dados desta empresa (INSERTs). Senhas, tokens, chaves, certificados e configurações de integrações não são incluídos.\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
      $tables = company_backup_tables($pdo);
      $rowsTotal = 0;
      $tablesWithData = 0;
      foreach ($tables as $table => $cols) {
        $key = $table === 'companies' ? 'id' : 'company_id';
        $colList = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($cols)));
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $hasId = isset($cols['id']) && $table !== 'companies';
        $lastId = null;
        $offset = 0;
        $tableRows = 0;
        while (true) {
          if ($hasId) {
            $sql = "SELECT $colList FROM $quotedTable WHERE `$key` = ?" . ($lastId !== null ? ' AND `id` > ?' : '') . ' ORDER BY `id` LIMIT 1000';
            $st = $pdo->prepare($sql);
            $st->execute($lastId !== null ? [$companyId, $lastId] : [$companyId]);
          } else {
            $st = $pdo->prepare("SELECT $colList FROM $quotedTable WHERE `$key` = ? LIMIT 1000 OFFSET " . $offset);
            $st->execute([$companyId]);
          }
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          if (!$rows) break;
          if ($tableRows === 0) $w("-- $table\n");
          foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
              $vals = [];
              foreach ($cols as $c => $type) $vals[] = company_backup_secret_column($c) ? 'NULL' : company_backup_value($pdo, $r[$c] ?? null, $type);
              $values[] = '(' . implode(',', $vals) . ')';
            }
            $w("INSERT INTO $quotedTable ($colList) VALUES\n" . implode(",\n", $values) . ";\n");
          }
          $tableRows += count($rows);
          if ($hasId) $lastId = end($rows)['id']; else $offset += count($rows);
          if (count($rows) < 1000) break;
        }
        if ($tableRows > 0) { $w("\n"); $tablesWithData++; }
        $rowsTotal += $tableRows;
      }
      $w("SET FOREIGN_KEY_CHECKS=1;\n-- Fim do backup: $tablesWithData tabela(s), $rowsTotal registro(s).\n");
      gzclose($gz);
      $gz = null;
      $meta = ['file' => basename($out), 'company_id' => $companyId, 'company_name' => $companyName, 'created_at' => date('c'), 'created_by_user_id' => $userId,
        'tables' => $tablesWithData, 'rows' => $rowsTotal, 'compressed_bytes' => (int)filesize($out), 'sha256' => hash_file('sha256', $out)];
      @file_put_contents($dir . '/' . $base . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
      @chmod($dir . '/' . $base . '.json', 0600);
      // Guarda só os mais recentes para não encher o disco.
      foreach (array_slice(company_backup_list($companyId), COMPANY_BACKUP_KEEP) as $old) {
        try { company_backup_delete($companyId, (string)$old['file']); } catch (Throwable $e) {}
      }
      return $meta;
    } catch (Throwable $e) {
      if ($gz !== null) @gzclose($gz);
      @unlink($out);
      throw $e;
    } finally {
      @flock($lock, LOCK_UN);
      @fclose($lock);
    }
  }
}

if (!function_exists('company_backup_list')) {
  function company_backup_list(int $companyId): array {
    $dir = company_backup_dir($companyId);
    $rows = [];
    foreach ((array)glob($dir . '/*.sql.gz') as $file) {
      $base = basename($file, '.sql.gz');
      $meta = is_file($dir . '/' . $base . '.json') ? json_decode((string)@file_get_contents($dir . '/' . $base . '.json'), true) : [];
      if (!is_array($meta)) $meta = [];
      $rows[] = ['file' => basename($file), 'created_at' => (string)($meta['created_at'] ?? date('c', (int)@filemtime($file))), 'compressed_bytes' => (int)@filesize($file),
        'created_by_user_id' => (int)($meta['created_by_user_id'] ?? 0), 'rows' => (int)($meta['rows'] ?? 0), 'tables' => (int)($meta['tables'] ?? 0)];
    }
    usort($rows, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $rows;
  }
}

if (!function_exists('company_backup_resolve')) {
  function company_backup_resolve(int $companyId, string $name): string {
    if (!preg_match('/^[A-Za-z0-9_.-]+\.sql\.gz$/', $name)) throw new RuntimeException('Nome de backup inválido.');
    $dir = realpath(company_backup_dir($companyId));
    $real = realpath(company_backup_dir($companyId) . '/' . $name);
    if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR) || !is_file($real)) throw new RuntimeException('Backup não localizado.');
    return $real;
  }
}

if (!function_exists('company_backup_delete')) {
  function company_backup_delete(int $companyId, string $name): void {
    $path = company_backup_resolve($companyId, $name);
    if (!@unlink($path)) throw new RuntimeException('Não foi possível excluir o backup.');
    @unlink(substr($path, 0, -7) . '.json');
  }
}
