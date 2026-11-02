<?php
declare(strict_types=1);
require __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../config/stock_recalculation.php';
$company=0; foreach ($argv as $a) if (str_starts_with($a,'--company=')) $company=(int)substr($a,10);
if ($company<=0) { fwrite(STDERR,"Use --company=ID\n"); exit(1); }
$p=nexa_stock_recalc_preview($pdo,$company);
echo "NEXA v2.11.0 - AUDITORIA DE ESTOQUE\n";
echo "Empresa: {$company}\n";
echo "Pedidos cancelados sem devolução completa: ".count($p['missing_cancellations'])."\n";
echo "Produtos com saldo divergente: ".count($p['discrepancies'])."\n";
foreach ($p['missing_cancellations'] as $r) echo "Pedido #{$r['numero_pedido']} | saída {$r['saida_original']} | devolvido {$r['devolvido']}\n";
