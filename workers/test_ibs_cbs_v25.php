<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
$root=dirname(__DIR__);
require_once $root.'/config/nexa_env.php';
require $root.'/config/pdo.php';
require_once $root.'/config/fiscal_module.php';
$companyId=1; $base=100.00; $class='000001'; $mode='AUTO'; $model='65'; $date='2026-07-01'; $crtOverride=''; $ufOverride=''; $cityOverride='';
foreach ($argv as $arg) {
  if (preg_match('/^--company=(\d+)$/',$arg,$m)) $companyId=(int)$m[1];
  elseif (preg_match('/^--base=([0-9.,]+)$/',$arg,$m)) $base=fiscal_decimal($m[1]);
  elseif (preg_match('/^--class=(\d{6})$/',$arg,$m)) $class=$m[1];
  elseif (preg_match('/^--mode=(AUTO|MANUAL|DESATIVADO)$/i',$arg,$m)) $mode=strtoupper($m[1]);
  elseif (preg_match('/^--model=(55|65)$/',$arg,$m)) $model=$m[1];
  elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/',$arg,$m)) $date=$m[1];
  elseif (preg_match('/^--crt=([1-4])$/',$arg,$m)) $crtOverride=$m[1];
  elseif (preg_match('/^--uf=([A-Za-z]{2})$/',$arg,$m)) $ufOverride=strtoupper($m[1]);
  elseif (preg_match('/^--city=(\d{7})$/',$arg,$m)) $cityOverride=$m[1];
}
$cfg=function_exists('fiscal_company_config')?fiscal_company_config($pdo,$companyId):['crt'=>'3','uf'=>'MG','city_ibge'=>'3131703'];
if ($crtOverride!=='') $cfg['crt']=$crtOverride;
if ($ufOverride!=='') $cfg['uf']=$ufOverride;
if ($cityOverride!=='') $cfg['city_ibge']=$cityOverride;
$tax=['ibs_cbs_rate_mode'=>$mode,'ibs_cbs_classification_code'=>$class,'cbs_rate'=>0,'ibs_uf_rate'=>0,'ibs_mun_rate'=>0];
$r=fiscal_ibs_cbs_resolve($pdo,$companyId,$tax,$cfg,$model,$date,(string)($cfg['uf']??''),(string)($cfg['city_ibge']??''));
$c=!empty($r['enabled'])?fiscal_ibs_cbs_calculate($base,$r,[]):[];
echo json_encode(['resolved'=>$r,'calculation'=>$c],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
