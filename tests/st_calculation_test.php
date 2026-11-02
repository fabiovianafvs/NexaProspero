<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/nexa_st_recipient.php';

function ok(bool $condition, string $label): void {
  if (!$condition) throw new RuntimeException('Falha: '.$label);
  echo "OK: ".$label.PHP_EOL;
}
$x=nexa_st_calculate(10.00, 70.00, 18.00, 18.00);
ok($x['st_base']===17.00, 'BC ST 17,00');
ok($x['presumed_total']===3.06, 'ICMS presumido 3,06');
ok($x['reference_own_amount']===1.80, 'ICMS próprio de referência 1,80');
ok($x['st_amount']===1.26, 'ICMS-ST efetivo 1,26');
ok($x['st_mva']===70.0, 'MVA preservada');
$y=nexa_st_calculate(10.00,70.00,18.00,12.00);
ok($y['st_amount']===1.86, 'Alíquota própria distinta');
$z=nexa_st_calculate(10.00,0.00,10.00,18.00);
ok($z['st_amount']===0.0, 'Sem ICMS-ST negativo');
try { nexa_st_calculate(-1,70,18,18); throw new RuntimeException('Não lançou erro'); }
catch (InvalidArgumentException $e) { echo "OK: valida base negativa".PHP_EOL; }
echo "TODOS OS TESTES DE CÁLCULO PASSARAM".PHP_EOL;
