<?php
declare(strict_types=1);
/** Nexa - auditoria fiscal CLI estritamente somente leitura. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$companyId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$docId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if (!$companyId || !$docId || $companyId < 1 || $docId < 1) {
    fwrite(STDERR, "Uso: php config/nfse_auditoria_cli.php <empresa> <documento>\n"); exit(2);
}
try {
    $config = require __DIR__ . '/db.php';
    if (!is_array($config)) throw new RuntimeException('config/db.php não retornou configuração em array.');
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['name'], $config['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $exists = static function(string $table) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $st->execute([$table]); $r = (int)$st->fetchColumn(); $st->closeCursor(); return $r > 0;
    };
    $cols = static function(string $table) use ($pdo): array {
        $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
        $st->execute([$table]); $out = $st->fetchAll(PDO::FETCH_COLUMN, 0); $st->closeCursor();
        return array_map('strtolower', $out);
    };
    $specs = [
        'fiscal_documents' => ['id','company_id','model','source_type','source_id','status','access_key','protocol_number','sefaz_status_code','sefaz_status_message','created_at','updated_at'],
        'fiscal_nfse_details' => ['fiscal_document_id','pedido_id','status','environment','dps_series','dps_number','dps_id','attempts','last_attempt_at','api_http_status','api_error_code','api_error_message','consulted_at','authorized_at','request_hash','nfse_number','nfse_id'],
        'fiscal_document_events' => ['id','fiscal_document_id','event_type','event_sequence','event_datetime','protocol_number','status_code','status_message','notes','created_at'],
    ];
    echo "AUDITORIA NFS-e (SOMENTE LEITURA)\nEmpresa: {$companyId} | Documento interno: {$docId}\n";
    foreach ($specs as $table => $wanted) {
        echo "\n== {$table} ==\n";
        if (!$exists($table)) { echo "Tabela ausente.\n"; continue; }
        $available = array_values(array_intersect($wanted, $cols($table)));
        if (!$available) { echo "Nenhuma coluna prevista encontrada.\n"; continue; }
        $present = $cols($table);
        if (!in_array('company_id', $present, true)) { echo "Sem coluna company_id; consulta não executada por segurança.\n"; continue; }
        $foreignKey = $table === 'fiscal_documents' ? 'id' : 'fiscal_document_id';
        if (!in_array($foreignKey, $present, true)) { echo "Sem coluna de vínculo {$foreignKey}; consulta não executada.\n"; continue; }
        $names = implode(',', array_map(static fn(string $s): string => '`'.$s.'`', $available));
        $st = $pdo->prepare("SELECT {$names} FROM `{$table}` WHERE `company_id`=? AND `{$foreignKey}`=? LIMIT 30");
        $st->execute([$companyId, $docId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$rows) { echo "Nenhum registro encontrado.\n"; continue; }
        foreach ($rows as $row) {
            if (!is_array($row)) { echo "Linha em formato inesperado; dados omitidos.\n"; continue; }
            foreach ($row as $key => $val) {
                if (in_array($key, ['access_key','request_hash','nfse_id','dps_id'], true) && is_string($val) && strlen($val) > 12) {
                    $row[$key] = substr($val,0,6).'…'.substr($val,-4);
                }
                if (in_array($key,['sefaz_status_message','api_error_message','notes'],true) && is_string($val)) {
                    $row[$key] = mb_substr($val,0,250);
                }
            }
            echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        }
    }
    $pdo->rollBack();
    echo "\nNenhuma atualização ou transmissão executada.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Falha de auditoria (sem alterações): ".$e->getMessage()."\n");
    exit(1);
}
