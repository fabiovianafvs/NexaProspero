<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';

$options = getopt('', ['company::', 'max-companies::', 'max-batches::', 'force']);
$companyId = max(0, (int)($options['company'] ?? 0));
$maxCompanies = max(1, min(500, (int)($options['max-companies'] ?? 100)));
$maxBatches = max(1, min(30, (int)($options['max-batches'] ?? 10)));
$force = array_key_exists('force', $options);

fiscal_ensure_dfe_distribution_schema($pdo);

if ($companyId > 0) {
    $companyIds = [$companyId];
} else {
    $sql = "SELECT DISTINCT company_id
            FROM fiscal_dfe_sync_state
            WHERE auto_check_enabled=1
              AND (next_allowed_at IS NULL OR next_allowed_at<=NOW())
            ORDER BY COALESCE(next_allowed_at,'1000-01-01 00:00:00'), company_id
            LIMIT " . $maxCompanies;
    $companyIds = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

if (!$companyIds) {
    echo '[' . date('Y-m-d H:i:s') . "] Nenhuma empresa liberada para sincronização.\n";
    exit(0);
}

$errors = 0;
foreach ($companyIds as $cid) {
    $lockName = 'nexa_dfe_company_' . $cid;
    $stLock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stLock->execute([$lockName]);
    if ((int)$stLock->fetchColumn() !== 1) {
        echo '[PULADO] Empresa ' . $cid . " já está em sincronização.\n";
        continue;
    }

    try {
        $result = fiscal_dfe_download_from_sefaz($pdo, $cid, $force, $maxBatches);
        echo '[OK] Empresa ' . $cid
            . ' | provedor=' . ($result['provider'] ?? '-')
            . ' | retorno=' . trim((string)($result['status_code'] ?? '') . ' ' . (string)($result['status_message'] ?? ''))
            . ' | documentos=' . (int)($result['documents_found'] ?? 0)
            . ' | xml=' . (int)($result['xmls_stored'] ?? 0)
            . ' | resumos=' . (int)($result['summaries_stored'] ?? 0)
            . ' | nsu=' . (string)($result['last_nsu'] ?? '0') . '/' . (string)($result['max_nsu'] ?? '0')
            . PHP_EOL;
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, '[ERRO] Empresa ' . $cid . ' | ' . $e->getMessage() . PHP_EOL);
    } finally {
        try {
            $stRelease = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stRelease->execute([$lockName]);
        } catch (Throwable $e) {
        }
    }
}

exit($errors > 0 ? 1 : 0);
