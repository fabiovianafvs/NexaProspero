<?php
require dirname(__DIR__).'/config/nexa_st_recipient.php';
function assert_eq($got, $want, $name) {
  if ($got !== $want) { throw new RuntimeException($name.': '.var_export($got,true).' diferente de '.var_export($want,true)); }
}
$p = ['cfop'=>'5403','cst'=>'30','csosn'=>'202'];
assert_eq(nexa_st_nfe_validate_codes($p,'3')['cfop'],'5403','CFOP comercial');
assert_eq(nexa_st_nfe_validate_codes($p,'1')['csosn'],'202','CSOSN CRT1');
assert_eq(nexa_st_nfe_validate_codes($p,'3')['cst'],'30','CST CRT normal');
$invalid = [
  [['cfop'=>'5102','cst'=>'30','csosn'=>'202'],'3'],
  [['cfop'=>'5405','cst'=>'30','csosn'=>'202'],'3'],
  [['cfop'=>'5403','cst'=>'10','csosn'=>'202'],'3'],
  [['cfop'=>'5403','cst'=>'30','csosn'=>'500'],'1'],
  [['cfop'=>'6403','cst'=>'30','csosn'=>'202'],'3'],
];
foreach ($invalid as [$v,$crt]) {
  try { nexa_st_nfe_validate_codes($v,$crt); throw new RuntimeException('FALHOU: aceitou perfil inválido'); }
  catch (RuntimeException $e) { if ($e->getMessage()==='FALHOU: aceitou perfil inválido') throw $e; }
}
echo "OK - seleção de CFOP/CST/CSOSN restrita a cenários homologados\n";
