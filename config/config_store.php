<?php
declare(strict_types=1);

/**
 * Armazena/recupera configurações no MySQL.
 * v30: suporte multiempresa via company_id.
 */

function config_store_init(PDO $pdo): void {
  static $checked = false;
  if ($checked) return;

  // A tabela é criada pelos scripts SQL do projeto.
  // Este fallback só deve rodar fora de transações, porque CREATE TABLE em MySQL/MariaDB
  // pode efetivar commit implícito e quebrar o fluxo do cadastro SaaS.
  if ($pdo->inTransaction()) {
    return;
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS app_config (
    company_id INT UNSIGNED NOT NULL,
    cfg_key VARCHAR(64) NOT NULL,
    cfg_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id, cfg_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $checked = true;
}

function config_store_get(PDO $pdo, int $companyId, string $key): ?array {
  config_store_init($pdo);
  $st = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id = ? AND cfg_key = ? LIMIT 1');
  $st->execute([$companyId, $key]);
  $json = (string)($st->fetchColumn() ?: '');
  if ($json === '') return null;
  $data = json_decode($json, true);
  return is_array($data) ? $data : null;
}

function config_store_set(PDO $pdo, int $companyId, string $key, array $value): void {
  config_store_init($pdo);
  $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $st = $pdo->prepare('REPLACE INTO app_config (company_id, cfg_key, cfg_json) VALUES (?, ?, ?)');
  $st->execute([$companyId, $key, $json]);
}

/** Merge recursivo "seguro" (sobrescreve apenas chaves presentes em $over). */
function config_deep_merge(array $base, array $over): array {
  foreach ($over as $k => $v) {
    if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
      $base[$k] = config_deep_merge($base[$k], $v);
    } else {
      $base[$k] = $v;
    }
  }
  return $base;
}

