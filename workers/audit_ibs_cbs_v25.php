<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_module.php';

$companyId = 0;
foreach ($argv as $arg) if (preg_match('/^--company=(\d+)$/', $arg, $m)) $companyId=(int)$m[1];
$where = $companyId > 0 ? 'WHERE p.company_id=?' : '';
$sql = "SELECT p.company_id,p.product_id,p.ibs_cbs_rate_mode,p.ibs_cbs_classification_code,p.cbs_rate,p.ibs_uf_rate,p.ibs_mun_rate,
               pr.nome AS product_name, cfg.crt
        FROM fiscal_product_tax p
        LEFT JOIN produtos pr ON pr.company_id=p.company_id AND pr.id=p.product_id
        LEFT JOIN fiscal_company_config cfg ON cfg.company_id=p.company_id
        {$where}
        ORDER BY p.company_id,pr.nome,p.product_id";
$st=$pdo->prepare($sql); $st->execute($companyId>0?[$companyId]:[]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
$issues=0;
printf("%-8s %-8s %-12s %-8s %-32s %s\n",'EMPRESA','PRODUTO','MODO','CRT','NOME','DIAGNÓSTICO');
foreach ($rows as $r) {
    $mode=fiscal_ibs_cbs_rate_mode($r);
    $class=substr(preg_replace('/\D+/','',(string)($r['ibs_cbs_classification_code']??''))?:'',0,6);
    $cst=$class!==''?substr($class,0,3):'';
    $crt=preg_replace('/\D+/','',(string)($r['crt']??''))?:'';
    $msg='OK';
    if ($mode==='DESATIVADO') $msg='Grupo IBS/CBS desativado.';
    elseif ($class==='') $msg='FALTA cClassTrib; não haverá cálculo/XML.';
    else {
        $classRow=fiscal_ibs_cbs_classification_direct($pdo,(int)$r['company_id'],$class,date('Y-m-d'));
        if (!$classRow) $msg="cClassTrib {$class} não encontrado na tabela vigente.";
        elseif (!empty($classRow['validity_start']) && (string)$classRow['validity_start'] > date('Y-m-d')) $msg="cClassTrib {$class} ainda não vigente.";
        elseif (!empty($classRow['validity_end']) && (string)$classRow['validity_end'] < date('Y-m-d')) $msg="cClassTrib {$class} fora da vigência.";
        elseif ((int)($classRow['can_nfe']??0)!==1 && (int)($classRow['can_nfce']??0)!==1) $msg="cClassTrib {$class} não habilitado para NF-e/NFC-e.";
        elseif (!in_array($cst,['000','200'],true)) $msg="CST {$cst} exige grupo especial ainda não automatizado.";
    }
    if ($msg==='OK' && $mode==='AUTO' && in_array($crt,['1','2','4'],true) && (int)date('Y')===2026) $msg="AUTO suspenso para CRT {$crt} em 2026; orientação específica pendente.";
    if ($msg==='OK' && $mode==='MANUAL' && (float)$r['cbs_rate']==0.0 && (float)$r['ibs_uf_rate']==0.0 && (float)$r['ibs_mun_rate']==0.0) $msg='MANUAL com todas as alíquotas zeradas.';
    if ($msg!=='OK') $issues++;
    printf("%-8d %-8d %-12s %-8s %-32s %s\n",(int)$r['company_id'],(int)$r['product_id'],$mode,$crt,mb_substr((string)($r['product_name']??''),0,32),$msg);
}
echo "\nProdutos verificados: ".count($rows)." | Alertas: {$issues}\n";
