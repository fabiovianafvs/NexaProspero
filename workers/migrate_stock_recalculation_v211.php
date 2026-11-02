<?php
declare(strict_types=1);
require __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../config/stock_recalculation.php';
nexa_stock_recalc_ensure_schema($pdo);
echo "[OK] Histórico de recálculo de estoque criado/verificado.\n";
echo "[OK] Nexa v2.11.0 concluído.\n";
