<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/billing_module.php';

billing_ensure_schema($pdo);
echo "[OK] Estrutura Itaú Boletos V3 preparada.\n";
echo "[OK] Campo itau_webhook_oauth_scope verificado.\n";
