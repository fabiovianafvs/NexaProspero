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

try {
    fiscal_ensure_dfe_distribution_schema($pdo);

    // Estados criados pela preparação antiga eram ACBrMonitor por padrão.
    // Quando a empresa já usa NFePHP e possui certificado A1 ativo, migra o
    // provedor do RADAR para conexão direta. Configurações ACBr reais podem
    // ser restauradas pela tela Configurações Fiscais.
    $changed = 0;
    try {
        $sql = "UPDATE fiscal_dfe_sync_state s
                INNER JOIN fiscal_company_config c ON c.company_id=s.company_id
                INNER JOIN fiscal_certificates cert ON cert.company_id=s.company_id
                  AND cert.is_active=1 AND UPPER(cert.certificate_type)='A1'
                SET s.provider='NFEPHP_DIRECT', s.updated_at=NOW()
                WHERE UPPER(COALESCE(c.emission_adapter,'NFEPHP'))='NFEPHP'
                  AND UPPER(COALESCE(s.provider,'')) IN ('','OUTRO','ACBRMONITOR')";
        $changed = (int)$pdo->exec($sql);
    } catch (Throwable $e) {
        echo '[AVISO] Não foi possível migrar automaticamente o provedor: ' . $e->getMessage() . PHP_EOL;
    }

    echo '[OK] Estrutura do RADAR NF-e verificada.' . PHP_EOL;
    echo '[OK] Estados migrados para NFePHP direto: ' . $changed . PHP_EOL;
    echo '[OK] Nenhuma manifestação foi enviada automaticamente.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
