<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/config/fiscal_operational.php';
$cfg=['uf'=>'MG','company_cnpj'=>'12412652000112'];
$normal=fiscal_nfce_make_access_key($cfg,'65','99',178,'2026-07-26 19:39:58','1');
$offline=fiscal_nfce_make_access_key($cfg,'65','99',178,'2026-07-26 19:39:58','9');
$checks=[
  strlen($normal)===44,
  strlen($offline)===44,
  substr($normal,34,1)==='1',
  substr($offline,34,1)==='9',
  $normal!==$offline,
  fiscal_nfce_mod11(substr($offline,0,43))===substr($offline,43,1),
];
if (in_array(false,$checks,true)) {
  fwrite(STDERR,"[ERRO] Teste de chave de contingência falhou.\nNormal: $normal\nOffline: $offline\n");
  exit(1);
}
echo '[OK] Chave normal:  ' . $normal . PHP_EOL;
echo '[OK] Chave offline: ' . $offline . PHP_EOL;
echo '[OK] tpEmis 9 e dígito verificador validados.' . PHP_EOL;
