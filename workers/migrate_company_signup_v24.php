<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Somente CLI.\n");
}

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/company_identity.php';

function v24_column_exists(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
  $st->execute([$table, $column]);
  return (bool)$st->fetchColumn();
}

function v24_index_exists(PDO $pdo, string $table, string $index): bool {
  $st = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
  $st->execute([$table, $index]);
  return (bool)$st->fetchColumn();
}

function v24_add_column(PDO $pdo, string $column, string $definition): void {
  if (v24_column_exists($pdo, 'companies', $column)) {
    echo "[OK] companies.{$column} já existe.\n";
    return;
  }
  $pdo->exec("ALTER TABLE companies ADD COLUMN `{$column}` {$definition}");
  echo "[OK] companies.{$column} criada.\n";
}

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_versions (
    module_name VARCHAR(80) NOT NULL,
    version_number BIGINT NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(255) NULL,
    PRIMARY KEY (module_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  v24_add_column($pdo, 'subdomain', "VARCHAR(100) NULL AFTER slug");
  v24_add_column($pdo, 'custom_domain', "VARCHAR(190) NULL AFTER subdomain");
  v24_add_column($pdo, 'person_type', "ENUM('pj','pf') NULL AFTER whatsapp");
  v24_add_column($pdo, 'cpf', "VARCHAR(20) NULL AFTER person_type");
  v24_add_column($pdo, 'cnpj', "VARCHAR(20) NULL AFTER cpf");
  v24_add_column($pdo, 'nome_fantasia', "VARCHAR(180) NULL AFTER cnpj");
  v24_add_column($pdo, 'razao_social', "VARCHAR(180) NULL AFTER nome_fantasia");
  v24_add_column($pdo, 'celular', "VARCHAR(30) NULL AFTER razao_social");
  v24_add_column($pdo, 'cep', "VARCHAR(12) NULL AFTER celular");
  v24_add_column($pdo, 'endereco', "VARCHAR(180) NULL AFTER cep");
  v24_add_column($pdo, 'numero', "VARCHAR(20) NULL AFTER endereco");
  v24_add_column($pdo, 'complemento', "VARCHAR(120) NULL AFTER numero");
  v24_add_column($pdo, 'bairro', "VARCHAR(120) NULL AFTER complemento");
  v24_add_column($pdo, 'cidade', "VARCHAR(120) NULL AFTER bairro");
  v24_add_column($pdo, 'estado', "CHAR(2) NULL AFTER cidade");

  $pdo->exec("UPDATE companies SET nome_fantasia = name WHERE nome_fantasia IS NULL OR TRIM(nome_fantasia) = ''");
  $pdo->exec("UPDATE companies SET person_type = 'pf' WHERE (person_type IS NULL OR person_type = '') AND COALESCE(cpf,'') <> '' AND COALESCE(cnpj,'') = ''");
  $pdo->exec("UPDATE companies SET person_type = 'pj' WHERE (person_type IS NULL OR person_type = '') AND COALESCE(cnpj,'') <> ''");
  $pdo->exec("UPDATE companies SET razao_social = name WHERE person_type = 'pj' AND (razao_social IS NULL OR TRIM(razao_social) = '')");

  if (!v24_index_exists($pdo, 'companies', 'uk_companies_slug')) {
    $pdo->exec('ALTER TABLE companies ADD UNIQUE KEY uk_companies_slug (slug)');
    echo "[OK] Índice único uk_companies_slug criado.\n";
  } else {
    echo "[OK] Índice único uk_companies_slug já existe.\n";
  }

  if (!v24_index_exists($pdo, 'companies', 'uk_companies_subdomain')) {
    $pdo->exec('ALTER TABLE companies ADD UNIQUE KEY uk_companies_subdomain (subdomain)');
    echo "[OK] Índice único uk_companies_subdomain criado.\n";
  } else {
    echo "[OK] Índice único uk_companies_subdomain já existe.\n";
  }

  $st = $pdo->prepare("INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
    VALUES ('company_signup',2026072601,NOW(),'Cadastro CPF/CNPJ e subdomínio DNS seguro')
    ON DUPLICATE KEY UPDATE version_number=VALUES(version_number),applied_at=VALUES(applied_at),notes=VALUES(notes)");
  $st->execute();

  echo "[OK] Migração do cadastro de empresas v2.4 concluída.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
