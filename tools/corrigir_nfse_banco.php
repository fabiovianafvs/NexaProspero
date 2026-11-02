<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute somente pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
$db = require $root . '/config/db.php';

$targetDb = trim((string)($argv[1] ?? 'u807353405_nexadelivery'));
if ($targetDb === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $targetDb)) {
    fwrite(STDERR, "Nome de banco inválido.\n");
    exit(2);
}
$db['name'] = $targetDb;

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha ao conectar ao banco {$targetDb}: {$e->getMessage()}\n");
    exit(3);
}

$currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Banco conectado: {$currentDb}\n";
echo "Servidor MySQL: " . (string)$db['host'] . ':' . (string)$db['port'] . "\n";
echo "Usuário MySQL: " . (string)$db['user'] . "\n\n";

$table = 'fiscal_nfse_details';
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
$st->execute([$table]);
if ((int)$st->fetchColumn() !== 1) {
    fwrite(STDERR, "A tabela {$table} não existe no banco {$currentDb}. Instale primeiro o módulo Fiscal operacional.\n");
    exit(4);
}

$columns = [
    'pedido_id' => 'BIGINT NULL AFTER `company_id`',
    'status' => "VARCHAR(30) NOT NULL DEFAULT 'PREPARADA' AFTER `pedido_id`",
    'environment' => "VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO' AFTER `status`",
    'dps_xml' => 'LONGTEXT NULL',
    'signed_dps_xml' => 'LONGTEXT NULL',
    'response_xml' => 'LONGTEXT NULL',
    'request_payload' => 'LONGTEXT NULL',
    'response_payload' => 'LONGTEXT NULL',
    'request_hash' => 'VARCHAR(64) NULL',
    'api_http_status' => 'INT NULL',
    'api_error_code' => 'VARCHAR(40) NULL',
    'api_error_message' => 'TEXT NULL',
    'attempts' => 'INT NOT NULL DEFAULT 0',
    'last_attempt_at' => 'DATETIME NULL',
    'authorized_at' => 'DATETIME NULL',
    'consulted_at' => 'DATETIME NULL',
    'updated_at' => 'DATETIME NULL',
];

$columnExists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
);

foreach ($columns as $name => $definition) {
    $columnExists->execute([$table, $name]);
    if ((int)$columnExists->fetchColumn() > 0) {
        echo "[OK] coluna {$name} já existe\n";
        continue;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
    echo "[CRIADA] coluna {$name}\n";
}

// Atualiza registros antigos quando o documento fiscal já está ligado a um pedido.
try {
    $pdo->exec("UPDATE fiscal_nfse_details nd
        INNER JOIN fiscal_documents d
                ON d.company_id = nd.company_id
               AND d.id = nd.fiscal_document_id
           SET nd.pedido_id = d.source_id,
               nd.environment = COALESCE(NULLIF(d.environment, ''), nd.environment, 'HOMOLOGACAO'),
               nd.status = COALESCE(NULLIF(d.status, ''), nd.status, 'PREPARADA')
         WHERE nd.pedido_id IS NULL
           AND d.source_type = 'PEDIDO'");
    echo "[OK] vínculos antigos revisados\n";
} catch (Throwable $e) {
    echo "[AVISO] não foi possível revisar vínculos antigos: {$e->getMessage()}\n";
}

$indexExists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
);

$indexes = [
    'ux_nfse_details_order' => [
        'sql' => 'ALTER TABLE `fiscal_nfse_details` ADD UNIQUE KEY `ux_nfse_details_order` (`company_id`,`pedido_id`,`environment`)',
        'duplicates' => "SELECT COUNT(*) FROM (
            SELECT company_id,pedido_id,environment,COUNT(*) qtd
              FROM fiscal_nfse_details
             WHERE pedido_id IS NOT NULL
             GROUP BY company_id,pedido_id,environment
            HAVING COUNT(*) > 1
        ) x",
    ],
    'ux_nfse_details_dps' => [
        'sql' => 'ALTER TABLE `fiscal_nfse_details` ADD UNIQUE KEY `ux_nfse_details_dps` (`company_id`,`environment`,`dps_series`,`dps_number`)',
        'duplicates' => "SELECT COUNT(*) FROM (
            SELECT company_id,environment,dps_series,dps_number,COUNT(*) qtd
              FROM fiscal_nfse_details
             WHERE dps_series IS NOT NULL AND dps_number IS NOT NULL
             GROUP BY company_id,environment,dps_series,dps_number
            HAVING COUNT(*) > 1
        ) x",
    ],
    'idx_nfse_details_dps_id' => [
        'sql' => 'ALTER TABLE `fiscal_nfse_details` ADD KEY `idx_nfse_details_dps_id` (`company_id`,`dps_id`)',
        'duplicates' => null,
    ],
    'idx_nfse_details_status' => [
        'sql' => 'ALTER TABLE `fiscal_nfse_details` ADD KEY `idx_nfse_details_status` (`company_id`,`environment`,`status`,`last_attempt_at`)',
        'duplicates' => null,
    ],
];

foreach ($indexes as $name => $cfg) {
    $indexExists->execute([$table, $name]);
    if ((int)$indexExists->fetchColumn() > 0) {
        echo "[OK] índice {$name} já existe\n";
        continue;
    }
    if (is_string($cfg['duplicates']) && (int)$pdo->query($cfg['duplicates'])->fetchColumn() > 0) {
        echo "[AVISO] índice {$name} não criado porque há registros duplicados\n";
        continue;
    }
    $pdo->exec($cfg['sql']);
    echo "[CRIADO] índice {$name}\n";
}

$placeholders = implode(',', array_fill(0, count($columns), '?'));
$verify = $pdo->prepare(
    "SELECT column_name FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = ?
        AND column_name IN ({$placeholders})
      ORDER BY ordinal_position"
);
$verify->execute(array_merge([$table], array_keys($columns)));
$found = $verify->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff(array_keys($columns), $found));

echo "\nTotal de colunas do emissor encontradas: " . count($found) . '/' . count($columns) . "\n";
if ($missing) {
    echo "Colunas ainda ausentes: " . implode(', ', $missing) . "\n";
    exit(5);
}

echo "BANCO NFS-e CORRIGIDO COM SUCESSO.\n";
