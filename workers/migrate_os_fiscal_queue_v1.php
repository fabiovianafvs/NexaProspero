<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root=dirname(__DIR__);
require_once $root.'/config/nexa_env.php';
require $root.'/config/pdo.php';
require_once $root.'/config/os_fiscal_queue.php';

echo "Nexa Próspero - Migração fila fiscal exclusiva da O.S. v1.9\n";
try {
    os_fiscal_queue_ensure_schema($pdo);
    echo "[OK] os_fiscal_queue criada/verificada.\n";
    echo "[OK] Schema: ".os_fiscal_schema_version()."\n";
    echo "Migração concluída com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR,"[ERRO] ".$e->getMessage()."\n");
    exit(1);
}
