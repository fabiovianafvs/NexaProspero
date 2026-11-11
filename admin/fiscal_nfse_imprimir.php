<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/_print_brand.php';
require_once __DIR__ . '/../config/nfse_document_pdf.php';
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
*{box-sizing:border-box}body{margin:0;background:#edf1f5;color:#111;font-family:Arial,Helvetica,sans-serif}.toolbar{max-width:210mm;margin:12px auto 7px;display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap}.btn{border:1px solid #c4cbd4;background:#fff;color:#111;border-radius:8px;padding:8px 11px;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}.btn.primary{background:#233b9b;color:#fff;border-color:#233b9b}.btn.wa{background:#25D366;color:#fff;border-color:#20bd5a}.danfse{width:210mm;min-height:287mm;margin:0 auto 18px;background:#fff;border:1px solid #222;padding:2.4mm;box-shadow:0 10px 30px rgba(15,23,42,.10);font-size:7.8pt;line-height:1.12}.top{display:grid;grid-template-columns:45mm 1fr 48mm;border:1px solid #111}.top>div{min-height:21mm;padding:1.7mm;border-right:1px solid #111}.top>div:last-child{border-right:0}.nfse-mark{display:flex;align-items:center;gap:2mm}.nfse-symbol{font-size:23pt;line-height:1;font-weight:900;color:#247f57;letter-spacing:-1.5px}.nfse-symbol small{display:block;font-size:6pt;color:#64748b;letter-spacing:0;margin-top:1mm}.top-title{text-align:center;display:flex;justify-content:center;align-items:center;flex-direction:column}.top-title strong{font-size:10.5pt}.top-title span{font-size:9pt;font-weight:800;margin-top:1mm}.top-env-inline{font-size:5.9pt;line-height:1.18;margin-top:1.5mm;color:#333}.company-brand{display:flex;align-items:center;justify-content:flex-end;text-align:right;padding:1.2mm 1.7mm!important}.company-brand img{max-width:42mm;max-height:17mm;object-fit:contain;display:block;margin-left:auto}.company-brand strong{font-size:8pt;line-height:1.15;max-width:43mm;display:block;margin-left:auto}.access-row{display:grid;grid-template-columns:1fr 32mm;border:1px solid #111;border-top:0;min-height:31mm}.access-key{padding:1.5mm}.access-key .label,.field .label{font-size:6.3pt;font-weight:900;text-transform:uppercase;color:#222}.access-key .key{font-size:8pt;font-weight:900;letter-spacing:.1mm;word-break:break-all;margin-top:1mm}.access-note{font-size:6.3pt;margin-top:4mm;max-width:120mm}.qr{border-left:1px solid #111;display:flex;align-items:center;justify-content:center;padding:1mm}.qr img{width:27mm;height:27mm;object-fit:contain}.qr-empty{font-size:6pt;text-align:center;color:#64748b}.section-title{border:1px solid #111;border-top:0;background:#e9e9e9;padding:1mm 1.4mm;font-size:7pt;font-weight:900;text-transform:uppercase;letter-spacing:.02em}.cells{display:grid;border-left:1px solid #111;border-bottom:1px solid #111}.cells.c2{grid-template-columns:1fr 1fr}.cells.c3{grid-template-columns:repeat(3,1fr)}.cells.c4{grid-template-columns:repeat(4,1fr)}.field{padding:1mm 1.4mm;min-height:10mm;border-right:1px solid #111;border-top:1px solid #111}.cells>.field:nth-child(-n+4){ }.field .value{font-size:7.7pt;font-weight:700;margin-top:.5mm;overflow-wrap:anywhere}.field .value.normal{font-weight:400}.span2{grid-column:span 2}.span3{grid-column:span 3}.span4{grid-column:1/-1}.center{text-align:center}.right{text-align:right}.note-line{border:1px solid #111;border-top:0;padding:1mm;text-align:center;font-size:6.4pt;font-weight:700}.service-desc{min-height:14mm;white-space:pre-wrap}.totals .value{font-size:8.2pt}.totals .emph .value{font-size:9.3pt;font-weight:900}.additional{border:1px solid #111;border-top:0;min-height:23mm;padding:1.3mm;white-space:pre-wrap}.bottom{display:grid;grid-template-columns:1fr 1fr 2fr;border:1px solid #111;border-top:0;margin-top:0}.bottom>div{min-height:9mm;padding:1mm;border-right:1px solid #111}.bottom>div:last-child{border-right:0}.muted{color:#4b5563}.homolog{border:2px solid #111;text-align:center;font-size:9pt;font-weight:900;padding:1.3mm;margin-bottom:1mm;background:#fff7ed}.cancel{border:2px solid #991b1b;color:#991b1b;text-align:center;font-size:10pt;font-weight:900;padding:1.3mm;margin-bottom:1mm}
@media print{@page{size:A4 portrait;margin:5mm}body{background:#fff}.toolbar{display:none}.danfse{width:auto;min-height:0;margin:0;border:0;padding:0;box-shadow:none;font-size:7.6pt}.top{break-inside:avoid}.section-title,.cells,.note-line,.additional,.bottom{break-inside:avoid}}
@media print{
  @page{size:A4 portrait;margin:3mm}
  html,body{margin:0!important;padding:0!important;height:auto!important}
  .danfse{width:100%!important;max-width:none!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;box-shadow:none!important;font-size:7pt!important;line-height:1.04!important}
  .top>div{min-height:16mm;padding:.8mm!important}.company-brand img{max-height:12mm}
  .access-row{min-height:23mm;grid-template-columns:1fr 25mm}.access-note{margin-top:1.5mm}
  .qr img{width:21mm;height:21mm}.section-title{padding:.45mm 1mm;font-size:6.6pt}
  .field{padding:.6mm 1mm;min-height:7mm}.field .value{font-size:7pt;margin-top:.3mm}
  .service-desc{min-height:8mm}.additional{min-height:8mm;padding:.7mm 1mm}
  .bottom>div{min-height:5.5mm;padding:.5mm 1mm}
  .note-line{padding:.5mm}.homolog,.cancel{padding:.7mm}
}
</style></head><body>
<div class="toolbar"><?php if(!$nfseF11):?><a class="btn wa" href="fiscal_documento_whatsapp.php?doc_id=<?=$docId?>&return_to=<?=rawurlencode($back)?>">WhatsApp</a><a class="btn" href="email_documento.php?type=fiscal&doc_id=<?=$docId?>&return_to=<?=rawurlencode($back)?>">E-mail</a><?php endif;?><button class="btn primary" onclick="window.print()">Imprimir</button><a class="btn" href="<?=nfsip_h($xmlUrl)?>">XML</a><a class="btn" href="<?=nfsip_h($back)?>">Sair</a></div>
<main class="danfse">
<?php if(strtoupper((string)$data['environment'])!=='PRODUCAO' && (($meta['environment_type']??'')!=='Produção')):?><div class="homolog">AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL</div><?php endif;?>
<?php if($data['status']==='CANCELADA'):?><div class="cancel">NFS-e CANCELADA</div><?php endif;?>
<header class="top">
  <div class="nfse-mark"><div class="nfse-symbol">NFS-e<small>Nota Fiscal de Serviço eletrônica</small></div></div>
  <div class="top-title"><strong>DANFSe</strong><span>Documento Auxiliar da NFS-e</span><div class="top-env-inline">Município: <?=nfsip_h(nfsip_val($data['city_issue']))?> · Gerador: <?=nfsip_h(nfsip_val($meta['generator']??''))?> · <?=nfsip_h(nfsip_val($meta['environment_type']??''))?></div></div>
  <div class="company-brand"><?php if($companyLogo!==''):?><img src="<?=nfsip_h($companyLogo)?>" alt="Logo da empresa"><?php else:?><strong><?=nfsip_h(nfsip_val($companyBrand,'Empresa'))?></strong><?php endif;?></div>
</header>
<div class="access-row"><div class="access-key"><div class="label">Chave de acesso da NFS-e</div><div class="key"><?=nfsip_h(nfsip_val($data['access_key']))?></div><div class="access-note">A chave identifica este documento. Quando disponível, use o QR ao lado para leitura rápida da chave e validação no ambiente oficial do município/provedor.</div></div><div class="qr"><?php if($qrUri!==''):?><img src="<?=nfsip_h($qrUri)?>" alt="QR da chave de acesso"><?php else:?><div class="qr-empty">QR indisponível</div><?php endif;?></div></div>
<div class="cells c3">
  <div class="field"><div class="label">Número da NFS-e</div><div class="value"><?=nfsip_h(nfsip_val($data['nfse_number']))?></div></div>
  <div class="field"><div class="label">Competência da NFS-e</div><div class="value"><?=nfsip_h(nfse_document_human_date($data['competence'],false)?:'—')?></div></div>
  <div class="field"><div class="label">Data e hora da emissão da NFS-e</div><div class="value"><?=nfsip_h(nfse_document_human_date($data['issue_datetime'])?:'—')?></div></div>
  <div class="field"><div class="label">Número da DPS</div><div class="value"><?=nfsip_h(nfsip_val($data['dps_number']))?></div></div>
  <div class="field"><div class="label">Série da DPS</div><div class="value"><?=nfsip_h(nfsip_val($data['dps_series']))?></div></div>
  <div class="field"><div class="label">Data e hora da emissão da DPS</div><div class="value"><?=nfsip_h(nfse_document_human_date($meta['dps_issue_datetime']??'')?:'—')?></div></div>
  <div class="field"><div class="label">Emitente da NFS-e</div><div class="value">Prestador</div></div>
  <div class="field"><div class="label">Situação da NFS-e</div><div class="value"><?=nfsip_h(nfsip_val($data['status']))?></div></div>
  <div class="field"><div class="label">Finalidade</div><div class="value"><?=nfsip_h(nfsip_val($meta['finality']??''))?></div></div>
</div>
<div class="section-title">Prestador / Fornecedor</div>
<div class="cells c4">
  <div class="field"><div class="label">CNPJ / CPF / NIF</div><div class="value"><?=nfsip_h(nfsip_party_doc($data['issuer']))?></div></div>
  <div class="field"><div class="label">Inscrição Municipal</div><div class="value"><?=nfsip_h(nfsip_val($data['issuer']['municipal_registration']))?></div></div>
  <div class="field"><div class="label">Telefone</div><div class="value"><?=nfsip_h(nfsip_val($data['issuer']['phone']))?></div></div>
  <div class="field"><div class="label">Município / UF · Cód. IBGE / CEP</div><div class="value"><?=nfsip_h(nfsip_city_zip($data['issuer']))?></div></div>
  <div class="field span2"><div class="label">Nome / Nome Empresarial</div><div class="value"><?=nfsip_h(nfsip_val($data['issuer']['name']))?></div></div>
  <div class="field span2"><div class="label">E-mail</div><div class="value normal"><?=nfsip_h(nfsip_val($data['issuer']['email']))?></div></div>
  <div class="field span4"><div class="label">Endereço</div><div class="value"><?=nfsip_h(nfsip_val($issuerAddr))?></div></div>
  <div class="field span2"><div class="label">Simples Nacional na data de competência</div><div class="value"><?=nfsip_h(nfsip_val($meta['simple_national']??''))?></div></div>
  <div class="field span2"><div class="label">Regime de apuração tributária pelo SN</div><div class="value"><?=nfsip_h(nfsip_reg_ap((string)($meta['simple_tax_regime']??'')))?></div></div>
</div>
<div class="section-title">Tomador / Adquirente</div>
<div class="cells c4">
  <div class="field"><div class="label">CNPJ / CPF / NIF</div><div class="value"><?=nfsip_h(nfsip_party_doc($data['customer']))?></div></div>
  <div class="field"><div class="label">Inscrição Municipal</div><div class="value"><?=nfsip_h(nfsip_val($data['customer']['municipal_registration']))?></div></div>
  <div class="field"><div class="label">Telefone</div><div class="value"><?=nfsip_h(nfsip_val($data['customer']['phone']))?></div></div>
  <div class="field"><div class="label">Município / UF · Cód. IBGE / CEP</div><div class="value"><?=nfsip_h(nfsip_city_zip($data['customer']))?></div></div>
  <div class="field span2"><div class="label">Nome / Nome Empresarial</div><div class="value"><?=nfsip_h(nfsip_val($data['customer']['name']))?></div></div>
  <div class="field span2"><div class="label">E-mail</div><div class="value normal"><?=nfsip_h(nfsip_val($data['customer']['email']))?></div></div>
  <div class="field span4"><div class="label">Endereço</div><div class="value"><?=nfsip_h(nfsip_val($customerAddr))?></div></div>
</div>
<div class="section-title">Serviço Prestado</div>
<div class="cells c4">
  <div class="field"><div class="label">Código Tributação Nacional</div><div class="value"><?=nfsip_h(nfsip_val($data['service']['national_code']))?></div></div>
  <div class="field"><div class="label">Código Tributação Municipal</div><div class="value"><?=nfsip_h(nfsip_val($data['service']['municipal_code']))?></div></div>
  <div class="field"><div class="label">Código NBS</div><div class="value"><?=nfsip_h(nfsip_val($data['service']['nbs']))?></div></div>
  <div class="field"><div class="label">Local da Prestação</div><div class="value"><?=nfsip_h(nfsip_val($data['city_service']))?></div></div>
  <div class="field span4 service-desc"><div class="label">Descrição do Serviço</div><div class="value normal"><?=nl2br(nfsip_h(nfsip_val($data['service']['description'])))?></div></div>
</div>
<div class="section-title">Tributação Municipal (ISSQN)</div>
<div class="cells c4">
  <div class="field"><div class="label">Tipo de Tributação do ISSQN</div><div class="value"><?=nfsip_h(nfsip_tax_type((string)($iss['type']??'')))?></div></div>
  <div class="field"><div class="label">Município de Incidência</div><div class="value"><?=nfsip_h(nfsip_val($data['city_service']))?></div></div>
  <div class="field"><div class="label">Regime Especial de Tributação</div><div class="value"><?=nfsip_h(nfsip_reg_esp((string)($meta['special_tax_regime']??'')))?></div></div>
  <div class="field"><div class="label">Retenção do ISSQN</div><div class="value"><?=nfsip_h(nfsip_iss_ret((string)($iss['retention']??'')))?></div></div>
  <div class="field"><div class="label">Base de Cálculo ISSQN</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['values']['base']))?></div></div>
  <div class="field"><div class="label">Alíquota Aplicada</div><div class="value"><?=nfsip_h(nfsip_pct($iss['rate']??$data['values']['iss_rate']))?></div></div>
  <div class="field span2"><div class="label">ISSQN Apurado</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['values']['iss']))?></div></div>
</div>
<div class="section-title">Tributação IBS / CBS</div>
<div class="cells c4">
  <div class="field"><div class="label">CST / cClassTrib</div><div class="value"><?=nfsip_h(nfsip_val(($ibs['cst']??'').(($ibs['class']??'')!==''?' / '.$ibs['class']:'')))?></div></div>
  <div class="field span2"><div class="label">Indicador da Operação / Código IBGE / Município Incidência</div><div class="value"><?=nfsip_h(nfsip_val(trim(($ibs['operation_indicator']??'').' / '.($ibs['incidence_code']??'').' / '.($ibs['incidence_name']??''),' /')))?></div></div>
  <div class="field"><div class="label">Base de Cálculo IBS/CBS</div><div class="value"><?=nfsip_h(nfse_document_human_money($ibs['base']??0))?></div></div>
  <div class="field"><div class="label">Alíquota IBS UF / Mun.</div><div class="value"><?=nfsip_h(nfsip_pct($ibs['ibs_uf_rate']??0).' / '.nfsip_pct($ibs['ibs_mun_rate']??0))?></div></div>
  <div class="field"><div class="label">Valor IBS UF / Mun.</div><div class="value"><?=nfsip_h(nfse_document_human_money($ibs['ibs_uf']??0).' / '.nfse_document_human_money($ibs['ibs_mun']??0))?></div></div>
  <div class="field"><div class="label">Alíquota CBS</div><div class="value"><?=nfsip_h(nfsip_pct($ibs['cbs_rate']??0))?></div></div>
  <div class="field"><div class="label">Valor CBS</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['values']['cbs']))?></div></div>
</div>
<div class="section-title">Valor Total da NFS-e</div>
<div class="cells c4 totals">
  <div class="field"><div class="label">Valor da Operação / Serviço</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['values']['service']))?></div></div>
  <div class="field"><div class="label">Desconto Incondicionado</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['discounts']['unconditional']??0))?></div></div>
  <div class="field"><div class="label">Desconto Condicionado</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['discounts']['conditional']??0))?></div></div>
  <div class="field"><div class="label">Total IBS/CBS</div><div class="value"><?=nfsip_h(nfse_document_human_money($totalIbsCbs))?></div></div>
  <div class="field span4 emph"><div class="label">Valor Líquido da NFS-e</div><div class="value"><?=nfsip_h(nfse_document_human_money($data['values']['liquid']))?></div></div>
</div>
<div class="section-title">Informações Complementares</div>
<div class="additional"><?=nl2br(nfsip_h(nfsip_val($data['additional_info']??'','Sem informações complementares.')))?></div>
<div class="bottom"><div><div class="label">Data Cientificação</div></div><div><div class="label">Identificação e Assinatura</div></div><div><div class="label">Nº NFS-e / Chave NFS-e</div><strong><?=nfsip_h(nfsip_val($data['nfse_number']))?> / <?=nfsip_h(nfsip_val($data['access_key']))?></strong></div></div>
</main></body></html>
