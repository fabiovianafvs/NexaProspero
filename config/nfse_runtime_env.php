<?php
declare(strict_types=1);

/**
 * Carrega somente variáveis NFSE_* para o processo PHP atual.
 *
 * Segurança: este arquivo nunca importa DB_*, REDIS_* ou outras variáveis,
 * evitando que a instalação dos XSDs altere o banco usado pelo Nexa Próspero.
 */
if (!function_exists('nfse_load_runtime_env')) {
  function nfse_load_runtime_env(?string $file = null): array {
    static $loaded = [];

    $candidates = [];
    if ($file !== null && trim($file) !== '') $candidates[] = trim($file);
    $custom = trim((string)(getenv('NEXA_NFSE_ENV_FILE') ?: ''));
    if ($custom !== '') $candidates[] = $custom;
    $candidates[] = '/etc/nexa/nfse.env';
    $candidates[] = '/etc/nexa/nexa-brain.env'; // legado: lê apenas NFSE_*

    $result = ['file' => '', 'loaded' => [], 'skipped' => []];
    foreach (array_values(array_unique($candidates)) as $candidate) {
      if ($candidate === '' || isset($loaded[$candidate])) continue;
      if (!is_file($candidate) || !is_readable($candidate)) continue;

      $loaded[$candidate] = true;
      $lines = @file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!is_array($lines)) continue;
      $result['file'] = $candidate;

      foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $position = strpos($line, '=');
        if ($position === false) continue;

        $name = trim(substr($line, 0, $position));
        if (!preg_match('/^NFSE_[A-Z0-9_]+$/', $name)) continue;
        if (getenv($name) !== false) {
          $result['skipped'][] = $name;
          continue;
        }

        $value = trim(substr($line, $position + 1));
        if (strlen($value) >= 2) {
          $first = $value[0];
          $last = $value[strlen($value) - 1];
          if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
            if ($first === '"') $value = stripcslashes($value);
          }
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        $result['loaded'][] = $name;
      }
      break;
    }

    return $result;
  }
}

nfse_load_runtime_env();
