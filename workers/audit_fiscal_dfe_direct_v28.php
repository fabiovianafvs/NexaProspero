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

$options = getopt('', ['company::']);
$companyId = max(0, (int)($options['company'] ?? 0));
fiscal_ensure_dfe_distribution_schema($pdo);

$where = $companyId > 0 ? 'WHERE s.company_id=' . $companyId : '';
$sql = "SELECT s.company_id, s.cnpj, s.uf, s.environment, s.provider,
               s.auto_check_enabled, s.last_nsu, s.max_nsu, s.last_status_code,
               s.last_status_message, s.next_allowed_at,
               CASE WHEN cert.id IS NULL THEN 'NAO' ELSE 'SIM' END AS certificado_a1,
               COALESCE(c.emission_adapter,'-') AS emission_adapter
        FROM fiscal_dfe_sync_state s
        LEFT JOIN fiscal_company_config c ON c.company_id=s.company_id
        LEFT JOIN fiscal_certificates cert ON cert.company_id=s.company_id
          AND cert.is_active=1 AND UPPER(cert.certificate_type)='A1'
        $where
        ORDER BY s.company_id";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$rows) {
    echo "Nenhuma empresa configurada no RADAR NF-e.\n";
    exit(0);
}

$headers = ['Empresa','CNPJ','UF','Ambiente','Provedor','Automático','A1','NSU','Retorno','Próxima busca'];
echo implode(' | ', $headers) . PHP_EOL;
echo str_repeat('-', 180) . PHP_EOL;
foreach ($rows as $r) {
    echo implode(' | ', [
        (string)$r['company_id'],
        (string)$r['cnpj'],
        (string)$r['uf'],
        (string)$r['environment'],
        (string)$r['provider'],
        !empty($r['auto_check_enabled']) ? 'SIM' : 'NAO',
        (string)$r['certificado_a1'],
        (string)$r['last_nsu'] . '/' . (string)$r['max_nsu'],
        trim((string)$r['last_status_code'] . ' ' . (string)$r['last_status_message']),
        (string)($r['next_allowed_at'] ?? '-'),
    ]) . PHP_EOL;
}
