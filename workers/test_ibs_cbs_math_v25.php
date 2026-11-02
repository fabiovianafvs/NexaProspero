<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
$root=dirname(__DIR__);
require_once $root.'/config/fiscal_module.php';

$standard=['ibs_uf_rate'=>0.1,'ibs_mun_rate'=>0.0,'cbs_rate'=>0.9,'ibs_reduction_rate'=>0.0,'cbs_reduction_rate'=>0.0];
$full=fiscal_ibs_cbs_calculate(100.00,$standard,[]);
$excluded=fiscal_ibs_cbs_calculate(100.00,$standard,['icms'=>18.00,'pis'=>1.65,'cofins'=>7.60]);
$reduced=fiscal_ibs_cbs_calculate(100.00,array_merge($standard,['ibs_reduction_rate'=>60.0,'cbs_reduction_rate'=>60.0]),[]);

$checks=[
  'base integral'=>abs($full['base']-100.00)<0.0001,
  'IBS UF integral'=>abs($full['ibs_uf_amount']-0.10)<0.0001,
  'CBS integral'=>abs($full['cbs_amount']-0.90)<0.0001,
  'base com exclusões'=>abs($excluded['base']-72.75)<0.0001,
  'IBS UF com exclusões'=>abs($excluded['ibs_uf_amount']-0.07)<0.0001,
  'CBS com exclusões'=>abs($excluded['cbs_amount']-0.65)<0.0001,
  'IBS UF com redução 60%'=>abs($reduced['ibs_uf_amount']-0.04)<0.0001,
  'CBS com redução 60%'=>abs($reduced['cbs_amount']-0.36)<0.0001,
];
$failed=array_keys(array_filter($checks,static fn(bool $ok):bool=>!$ok));
if($failed){fwrite(STDERR,"[ERRO] Testes matemáticos falharam:\n- ".implode("\n- ",$failed)."\n");exit(1);}
echo "[OK] Cálculos IBS/CBS validados.\n";
echo json_encode(['integral'=>$full,'com_exclusoes'=>$excluded,'reducao_60'=>$reduced],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
