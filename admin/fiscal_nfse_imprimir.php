<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/_print_brand.php';
require_once __DIR__ . '/../config/nfse_document_pdf.php';
require_once __DIR__ . '/../config/nfse_danfse.php';
require_once __DIR__ . '/../config/print_codes.php';
require_once __DIR__ . '/../config/navigation_return.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_nfse_homologation')
  : (int)COMPANY_ID;
$nfseF11 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && $cid !== (int)COMPANY_ID;
if ($nfseF11) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
$nfsePrintUrl = static function(string $url) use ($nfseF11): string {
  return $nfseF11 && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$docId=max(0,(int)($_GET['doc_id']??$_GET['id']??0));
if($docId<=0){http_response_code(400);echo 'Documento inválido.';exit;}
$sql="SELECT d.*,n.provider_code,n.dps_series,n.dps_number,n.nfse_number,n.verification_code,n.response_xml,n.status nfse_status,n.authorized_at,n.pedido_id AS nfse_pedido_id
        FROM fiscal_documents d
        LEFT JOIN fiscal_nfse_details n ON n.company_id=d.company_id AND n.fiscal_document_id=d.id
        WHERE d.company_id=? AND d.id=? AND d.model='NFSE' LIMIT 1";
$st=$pdo->prepare($sql);$st->execute([$cid,$docId]);$doc=$st->fetch(PDO::FETCH_ASSOC);
if(!$doc){http_response_code(404);echo 'NFS-e não encontrada.';exit;}
if ($nfseF11) {
  if (!function_exists('multi_company_scope_is_cross_company') || !multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_homologation')) { http_response_code(403); echo 'Contexto Multiempresa inválido para NFS-e.'; exit; }
  if (function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'fiscal_documentos')) { http_response_code(403); echo 'Sem permissão para Documentos fiscais na empresa selecionada.'; exit; }
  if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $cid, 'fiscal')) { http_response_code(403); echo 'Módulo Fiscal não está ativo na empresa selecionada.'; exit; }
  if (!(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $cid, (string)($doc['environment'] ?? '')))) { http_response_code(403); echo htmlspecialchars(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a impressão da NFS-e') : 'V11.50 Multiempresa: impressão NFS-e pelo F11 está liberada somente em HOMOLOGAÇÃO.', ENT_QUOTES, 'UTF-8'); exit; }
  if (strtoupper(trim((string)($doc['source_type'] ?? ''))) !== 'PEDIDO' || (int)($doc['source_id'] ?? 0) <= 0) { http_response_code(403); echo 'V11.50 Multiempresa: somente NFS-e originada de PEDIDO está homologada.'; exit; }
}
try{$prest=fiscal_nfce_document_participant($pdo,$cid,$docId,'PRESTADOR');$tom=fiscal_nfce_document_participant($pdo,$cid,$docId,'TOMADOR');}catch(Throwable $e){$prest=[];$tom=[];}
if(function_exists('fiscal_company_report_identity'))$doc['_issuer_identity']=fiscal_company_report_identity($pdo,$cid);
$doc['_prestador']=$prest;$doc['_tomador']=$tom;
$data=nfse_document_view_data($doc);
// Layout DANFSe v2.0 (o mesmo do PDF enviado por WhatsApp/e-mail).
$danfse=nfse_danfse_layout($doc);
function nfsip_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function nfsip_val($v,string $fallback='—'):string{$s=trim((string)$v);return $s!==''?$s:$fallback;}
function nfsip_pct($v):string{return number_format((float)$v,2,',','.').' %';}
function nfsip_tax_type(string $v):string{return $v==='1'?'Operação tributável':($v!==''?'Código '.$v:'—');}
function nfsip_iss_ret(string $v):string{return match($v){'1'=>'Não retido','2'=>'Retido pelo tomador','3'=>'Retido pelo intermediário',default=>$v!==''?'Código '.$v:'—'};}
function nfsip_reg_ap(string $v):string{return $v==='1'?'Regime de apuração pelo Simples Nacional':($v!==''?'Indicador '.$v:'—');}
function nfsip_reg_esp(string $v):string{return $v==='0'?'Nenhum':($v!==''?'Código '.$v:'—');}
function nfsip_party_doc(array $p):string{return nfse_document_format_document((string)($p['document']??''));}
function nfsip_city_zip(array $p):string{$a=(array)($p['address']??[]);$city=trim((string)($a['city']??''));$uf=trim((string)($a['uf']??''));$cep=nfse_document_format_zip((string)($a['zip']??''));$code=trim((string)($p['city_code']??''));$left=trim($city.($uf!==''?' / '.$uf:''));$right=trim($code.($cep!==''?' / '.$cep:''));return trim($left.($right!==''?' · '.$right:''));}
$self=$nfsePrintUrl('fiscal_nfse_imprimir.php?doc_id='.$docId);
$sourceType = strtoupper(trim((string)($doc['source_type'] ?? '')));
// O source_id pode apontar para O.S.; o pedido comercial é identificado separadamente.
$pedidoId = (int)($doc['nfse_pedido_id'] ?? 0);
if ($pedidoId <= 0 && $sourceType === 'PEDIDO') $pedidoId = (int)($doc['source_id'] ?? 0);
require_once __DIR__ . '/../config/os_nfse_return.php';
$isOsNfse = os_nfse_is_os_origin($pdo, $cid, $pedidoId, $sourceType === 'ORDEM_SERVICO' ? (int)$doc['source_id'] : 0);
$back = $nfsePrintUrl($isOsNfse
  ? 'os_fiscal_documentos.php'
  : ($pedidoId > 0 ? 'fiscal_nfse_nacional.php?pedido_id=' . $pedidoId : 'fiscal_documentos.php'));
$back = $nfsePrintUrl(nexa_navigation_safe_return((string)($_GET['return_to'] ?? ''), $isOsNfse ? 'os_fiscal_documentos.php' : ($pedidoId > 0 ? 'fiscal_nfse_nacional.php?pedido_id=' . $pedidoId : 'fiscal_documentos.php')));
$xmlUrl=$nfsePrintUrl('fiscal_nfse_acao.php?action=download_nfse&doc_id='.$docId.'&pedido_id='.$pedidoId);
$qrSvg=nexa_print_qr_svg((string)$data['access_key']);$qrUri=nexa_print_svg_data_uri($qrSvg);
$issuerAddr=nfse_document_address_line($data['issuer']);$customerAddr=nfse_document_address_line($data['customer']);
$ibs=(array)($data['tax']['ibscbs']??[]);$iss=(array)($data['tax']['iss']??[]);$meta=(array)($data['meta']??[]);
$totalIbsCbs=(float)($data['values']['ibs']??0)+(float)($data['values']['cbs']??0);
$companyLogo=nexa_print_logo_url($cid);
$companyBrand=nexa_print_brand_name($cid,(string)($data['issuer']['name']??''));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DANFSe NFS-e <?=nfsip_h($data['nfse_number'])?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#edf1f5;color:#111;font-family:Arial,Helvetica,sans-serif}.toolbar{max-width:210mm;margin:12px auto 7px;display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap}.btn{border:1px solid #c4cbd4;background:#fff;color:#111;border-radius:8px;padding:8px 11px;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}.btn.primary{background:#233b9b;color:#fff;border-color:#233b9b}.btn.wa{background:#25D366;color:#fff;border-color:#20bd5a}
<?=nfse_danfse_css()?>
@media print{body{background:#fff}.toolbar{display:none}}
</style></head><body>
<div class="toolbar"><?php if(!$nfseF11):?><a class="btn wa" href="fiscal_documento_whatsapp.php?doc_id=<?=$docId?>&return_to=<?=rawurlencode($back)?>">WhatsApp</a><a class="btn" href="email_documento.php?type=fiscal&doc_id=<?=$docId?>&return_to=<?=rawurlencode($back)?>">E-mail</a><?php endif;?><button class="btn primary" onclick="window.print()">Imprimir</button><a class="btn" href="<?=nfsip_h($xmlUrl)?>">XML</a><a class="btn" href="<?=nfsip_h($back)?>">Sair</a></div>
<?=nfse_danfse_html($danfse)?>
</body></html>
