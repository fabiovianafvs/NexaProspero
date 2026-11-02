<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$accountantId=accountant_require_login();
$companyId=(int)($_GET['company']??$_SESSION['accountant_company_id']??0);
$allowed=[];
foreach(accounting_linked_companies($pdo,$accountantId) as $company)$allowed[(int)$company['id']]=$company;
if($companyId<=0 || !isset($allowed[$companyId])){http_response_code(403);exit('Você não possui acesso a esta empresa.');}
$ini=accounting_validate_date((string)($_GET['ini']??date('Y-m-01')),date('Y-m-01'));
$fim=accounting_validate_date((string)($_GET['fim']??date('Y-m-d')),date('Y-m-d'));
if($ini>$fim)[$ini,$fim]=[$fim,$ini];
$model=trim((string)($_GET['model']??''));if(!in_array($model,['','55','65'],true))$model='';
$direction=strtoupper(trim((string)($_GET['direction']??'')));if(!in_array($direction,['','ENTRADA','SAIDA'],true))$direction='';
$filters=[
 'ini'=>$ini,'fim'=>$fim,'model'=>$model,'direction'=>$direction,'q'=>trim((string)($_GET['product_q']??'')),
 'ncm'=>preg_replace('/\D+/','',(string)($_GET['ncm']??''))?:'','cest'=>preg_replace('/\D+/','',(string)($_GET['cest']??''))?:'','cfop'=>preg_replace('/\D+/','',(string)($_GET['cfop']??''))?:'',
 'icms_cst'=>trim((string)($_GET['icms_cst']??'')),'pis_cst'=>trim((string)($_GET['pis_cst']??'')),'cofins_cst'=>trim((string)($_GET['cofins_cst']??'')),'ibscbs_cst'=>trim((string)($_GET['ibscbs_cst']??'')),
];
$rows=accounting_export_product_items($pdo,$companyId,$filters,50000);
$content=accounting_product_rows_csv($rows);
$companyPart=preg_replace('/[^A-Za-z0-9_-]+/','_',strtolower((string)($allowed[$companyId]['name']??'empresa')))?:'empresa';
$file='produtos_fiscais_'.$companyPart.'_'.$ini.'_a_'.$fim.'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$file.'"');
header('Content-Length: '.strlen($content));
echo $content;
