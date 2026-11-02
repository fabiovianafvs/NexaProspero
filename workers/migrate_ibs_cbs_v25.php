<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_module.php';

$errors = [];

try {
    fiscal_ensure_schema($pdo);
    fiscal_ensure_aux_schema($pdo);
    echo "[OK] Estrutura fiscal verificada.\n";
} catch (Throwable $e) {
    $errors[] = 'Estrutura fiscal: ' . $e->getMessage();
}

try {
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'ibs_cbs_rate_mode', "VARCHAR(12) NOT NULL DEFAULT 'AUTO'");
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'ibs_cbs_rate_mode', "VARCHAR(12) NOT NULL DEFAULT 'AUTO'");

    // Preserva cadastros que já possuíam alíquotas informadas: eles passam a ser MANUAL.
    $pdo->exec("UPDATE fiscal_product_tax SET ibs_cbs_rate_mode='MANUAL' WHERE COALESCE(cbs_rate,0)>0 OR COALESCE(ibs_uf_rate,0)>0 OR COALESCE(ibs_mun_rate,0)>0");
    $pdo->exec("UPDATE fiscal_tax_profiles SET ibs_cbs_rate_mode='MANUAL' WHERE COALESCE(cbs_rate,0)>0 OR COALESCE(ibs_uf_rate,0)>0 OR COALESCE(ibs_mun_rate,0)>0");
    $pdo->exec("UPDATE fiscal_product_tax SET ibs_cbs_rate_mode='AUTO' WHERE ibs_cbs_rate_mode IS NULL OR ibs_cbs_rate_mode NOT IN ('AUTO','MANUAL','DESATIVADO')");
    $pdo->exec("UPDATE fiscal_tax_profiles SET ibs_cbs_rate_mode='AUTO' WHERE ibs_cbs_rate_mode IS NULL OR ibs_cbs_rate_mode NOT IN ('AUTO','MANUAL','DESATIVADO')");
    echo "[OK] Modo automático/manual preparado.\n";
} catch (Throwable $e) {
    $errors[] = 'Modo de alíquota: ' . $e->getMessage();
}

try {
    // Alíquotas-padrão oficiais do exercício de 2026.
    $stCbs = $pdo->prepare("INSERT INTO fiscal_cbs_rates (company_id,code,validity_start,validity_end,rate,is_active,notes,updated_at)
        VALUES (0,'PADRAO_2026','2026-01-01','2026-12-31',0.9000,1,'Alíquota-padrão CBS 2026 - IT RTC vigente.',NOW())
        ON DUPLICATE KEY UPDATE rate=VALUES(rate),is_active=1,notes=VALUES(notes),updated_at=NOW()");
    $stCbs->execute();

    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    $stUf = $pdo->prepare("INSERT INTO fiscal_ibs_uf_rates (company_id,uf,validity_start,validity_end,rate,is_active,notes,updated_at)
        VALUES (0,?,'2026-01-01','2026-12-31',0.1000,1,'Alíquota-padrão IBS UF 2026 - IT RTC vigente.',NOW())
        ON DUPLICATE KEY UPDATE rate=VALUES(rate),is_active=1,notes=VALUES(notes),updated_at=NOW()");
    foreach ($ufs as $uf) $stUf->execute([$uf]);

    $stMun = $pdo->prepare("INSERT INTO fiscal_ibs_mun_rates (company_id,ibge_code,city_name,uf,validity_start,validity_end,rate,is_active,notes,updated_at)
        VALUES (0,'0000000','TODOS OS MUNICÍPIOS',NULL,'2026-01-01','2026-12-31',0.0000,1,'Alíquota-padrão IBS Município 2026 - IT RTC vigente.',NOW())
        ON DUPLICATE KEY UPDATE rate=VALUES(rate),is_active=1,notes=VALUES(notes),updated_at=NOW()");
    $stMun->execute();
    echo "[OK] Alíquotas-padrão de 2026 gravadas.\n";
} catch (Throwable $e) {
    $errors[] = 'Alíquotas 2026: ' . $e->getMessage();
}

try {
    // Tributação integral: registro mínimo seguro para o caso comum.
    $st = $pdo->prepare("INSERT INTO fiscal_ibs_cbs_classifications
        (company_id,classification_code,description,cst_code,rate_type,ibs_reduction_rate,cbs_reduction_rate,validity_start,validity_end,can_nfe,can_nfce,is_active,notes,updated_at)
        VALUES (0,'000001','Situações tributadas integralmente pelo IBS e CBS','000','PADRAO',0,0,'2026-01-01',NULL,1,1,1,'Tabela oficial RTC. Confirmar enquadramento fiscal do produto.',NOW())
        ON DUPLICATE KEY UPDATE description=VALUES(description),cst_code='000',rate_type='PADRAO',ibs_reduction_rate=0,cbs_reduction_rate=0,can_nfe=1,can_nfce=1,is_active=1,notes=VALUES(notes),updated_at=NOW()");
    $st->execute();
    echo "[OK] cClassTrib 000001 atualizado.\n";
} catch (Throwable $e) {
    $errors[] = 'Classificação 000001: ' . $e->getMessage();
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_versions (
        module_name VARCHAR(80) NOT NULL,
        version_number BIGINT NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes VARCHAR(255) NULL,
        PRIMARY KEY (module_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $st = $pdo->prepare("INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
        VALUES ('fiscal_ibs_cbs',2026072602,NOW(),'Nexa Fiscal v2.5 - cálculo automático IBS/CBS 2026, vItem e vNFTot no XML RTC vigente')
        ON DUPLICATE KEY UPDATE version_number=VALUES(version_number),applied_at=NOW(),notes=VALUES(notes)");
    $st->execute();
} catch (Throwable $e) {
    $errors[] = 'Versão: ' . $e->getMessage();
}

if ($errors) {
    fwrite(STDERR, "A migração terminou com avisos/erros:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "[OK] Migração Nexa Fiscal v2.5 concluída.\n";
