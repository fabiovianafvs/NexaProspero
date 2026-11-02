<?php
declare(strict_types=1);

/**
 * Carrega variáveis simples de um arquivo EnvironmentFile também no PHP-FPM.
 * Variáveis já definidas pelo servidor nunca são sobrescritas.
 */
if (!function_exists('nexa_load_environment_file')) {
  function nexa_load_environment_file(?string $file = null): void {
    static $loaded = [];
    $file = $file ?: (string)(getenv('NEXA_ENV_FILE') ?: '/etc/nexa/nexa-brain.env');
    if ($file === '' || isset($loaded[$file])) return;
    $loaded[$file] = true;
    if (!is_file($file) || !is_readable($file)) return;

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || str_starts_with($line, '#')) continue;
      if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
      $position = strpos($line, '=');
      if ($position === false) continue;
      $name = trim(substr($line, 0, $position));
      if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) continue;
      if (getenv($name) !== false) continue;
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
    }
  }
}

nexa_load_environment_file();
