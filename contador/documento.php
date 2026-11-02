<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$accountantId = accountant_require_login();
$companyId = (int)($_GET['company'] ?? $_SESSION['accountant_company_id'] ?? 0);
$documentId = (int)($_GET['id'] ?? 0);

$allowed = [];
foreach (accounting_linked_companies($pdo, $accountantId) as $linkedCompany) {
  $allowed[(int)$linkedCompany['id']] = $linkedCompany;
}
if ($companyId <= 0 || !isset($allowed[$companyId])) {
  http_response_code(403);
  exit('Você não possui acesso a esta empresa.');
}
$_SESSION['accountant_company_id'] = $companyId;
$company = $allowed[$companyId];
$source = strtolower(trim((string)($_GET['source'] ?? 'document')));
if ($source === 'xml') {
  require __DIR__ . '/documento_entrada_view.php';
  exit;
}
$detail = accounting_document_detail($pdo, $companyId, $documentId);
if (!$detail) {
  http_response_code(404);
  exit('Documento fiscal não encontrado.');
}
$doc = $detail['document'];
$participants = $detail['participants'];
$items = $detail['items'];
$payments = $detail['payments'];
$references = $detail['references'];
$events = $detail['events'];

$e = static fn($value): string => accountant_e($value);
$money = static fn($value, int $decimals=2): string => 'R$ ' . number_format((float)$value, $decimals, ',', '.');
$decimal = static fn($value, int $decimals=4): string => number_format((float)$value, $decimals, ',', '.');
$dateTime = static function($value): string {
  $value = trim((string)$value);
  if ($value === '') return '—';
  $ts = strtotime($value);
  return $ts ? date('d/m/Y H:i:s', $ts) : $value;
};
$dateOnly = static function($value): string {
  $value = trim((string)$value);
  if ($value === '') return '—';
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
};
$digitsDoc = static function($value): string {
  $digits = preg_replace('/\D+/', '', (string)$value) ?: '';
  if (strlen($digits) === 14) return substr($digits,0,2).'.'.substr($digits,2,3).'.'.substr($digits,5,3).'/'.substr($digits,8,4).'-'.substr($digits,12,2);
  if (strlen($digits) === 11) return substr($digits,0,3).'.'.substr($digits,3,3).'.'.substr($digits,6,3).'-'.substr($digits,9,2);
  return (string)$value;
};
$taxExtra = static function(array $tax): array {
  $raw = trim((string)($tax['extra_json'] ?? ''));
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
};
$ibsCbsSummary = static function(array $taxes) use ($taxExtra): ?array {
  foreach ($taxes as $tax) {
    $group = strtoupper(trim((string)($tax['tax_group'] ?? '')));
    $tag = strtoupper(trim((string)($tax['tax_tag'] ?? '')));
    if ($group !== 'IBSCBS' && $tag !== 'IBSCBS') continue;

    $extra = $taxExtra($tax);
    $number = static function(array $keys, float $default=0.0) use ($extra): float {
      foreach ($keys as $key) {
        if (array_key_exists($key, $extra) && $extra[$key] !== '' && $extra[$key] !== null) {
          return (float)$extra[$key];
        }
      }
      return $default;
    };
    $text = static function(array $keys, string $default='') use ($extra): string {
      foreach ($keys as $key) {
        if (array_key_exists($key, $extra) && trim((string)$extra[$key]) !== '') {
          return trim((string)$extra[$key]);
        }
      }
      return $default;
    };

    $base = $number(['base_calc'], (float)($tax['base_calc'] ?? 0));
    $ibsUfRate = $number(['ibs_uf_effective_rate', 'ibs_uf_rate']);
    $ibsMunRate = $number(['ibs_mun_effective_rate', 'ibs_mun_rate']);
    $cbsRate = $number(['cbs_effective_rate', 'cbs_rate']);
    $ibsUfAmount = $number(['ibs_uf_amount']);
    $ibsMunAmount = $number(['ibs_mun_amount']);
    $ibsAmount = $number(['ibs_amount'], $ibsUfAmount + $ibsMunAmount);
    $cbsAmount = $number(['cbs_amount']);

    return [
      'cst' => $text(['CST'], trim((string)($tax['cst'] ?? ''))),
      'class' => $text(['cClassTrib'], trim((string)($tax['class_code'] ?? ''))),
      'base' => $base,
      'ibs_uf_rate' => $ibsUfRate,
      'ibs_uf_amount' => $ibsUfAmount,
      'ibs_mun_rate' => $ibsMunRate,
      'ibs_mun_amount' => $ibsMunAmount,
      'ibs_rate' => $ibsUfRate + $ibsMunRate,
      'ibs_amount' => $ibsAmount,
      'cbs_rate' => $cbsRate,
      'cbs_amount' => $cbsAmount,
      'total_rate' => $ibsUfRate + $ibsMunRate + $cbsRate,
      'total_amount' => $ibsAmount + $cbsAmount,
      'rate_mode' => $text(['rate_mode']),
      'rate_source' => $text(['rate_source']),
    ];
  }
  return null;
};
$flatten = static function(array $data, string $prefix='') use (&$flatten): array {
  $out=[];
  foreach($data as $key=>$value){
    $name=$prefix!==''?$prefix.'.'.$key:(string)$key;
    if(is_array($value)) $out += $flatten($value,$name);
    elseif(is_bool($value)) $out[$name]=$value?'Sim':'Não';
    elseif($value!==null && $value!=='') $out[$name]=(string)$value;
  }
  return $out;
};
$status = strtoupper((string)($doc['status'] ?? ''));
$modelLabel = (string)($doc['model'] ?? '') === '55' ? 'NF-e' : 'NFC-e';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Detalhes <?= $e($modelLabel) ?> <?= $e((string)($doc['number'] ?? '')) ?> - Central do Contador</title>
<style>
:root{--p:#0b1f5b;--s:#2454d6;--line:#dbe4f0;--muted:#64748b;--bg:#f4f7fb;--ok:#047857}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",system-ui,Arial,sans-serif;background:var(--bg);color:#0f172a}.top{position:sticky;top:0;z-index:20;background:linear-gradient(135deg,var(--p),var(--s));color:#fff;padding:14px 22px;box-shadow:0 12px 34px rgba(11,31,91,.18)}.topin{max-width:1450px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{display:flex;align-items:center;gap:11px}.brand img{width:45px;height:45px;object-fit:contain;background:#fff;border-radius:14px;padding:5px}.brand strong{display:block;font-size:18px}.brand span{display:block;font-size:12px;color:#dff6ff}.top a{color:#fff;text-decoration:none;font-weight:800}.page{max-width:1450px;margin:auto;padding:22px}.head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.head h1{margin:0;color:var(--p);font-size:30px;letter-spacing:-.035em}.head p{margin:6px 0 0;color:var(--muted)}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 14px;border-radius:12px;border:1px solid var(--line);background:#fff;color:var(--p);text-decoration:none;font-weight:850}.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:0 10px 30px rgba(15,23,42,.04);margin-bottom:14px}.card h2{margin:0 0 14px;color:var(--p);font-size:20px}.card h3{margin:0;color:var(--p);font-size:16px}.kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:14px}.kpi{background:#fff;border:1px solid var(--line);border-radius:17px;padding:14px;min-width:0}.kpi span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:850}.kpi strong{display:block;margin-top:5px;color:var(--p);font-size:18px;overflow-wrap:anywhere}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field{border:1px solid #e5eaf2;border-radius:14px;padding:11px;min-width:0;background:#fbfdff}.field span{display:block;font-size:11px;color:var(--muted);font-weight:800;margin-bottom:4px}.field strong,.field div{overflow-wrap:anywhere}.participants{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:12px}.participant{border:1px solid var(--line);border-radius:16px;padding:14px;background:#fbfdff}.participant .role{display:inline-flex;padding:5px 8px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-size:11px;font-weight:900;margin-bottom:8px}.participant p{margin:4px 0;color:#475569;font-size:13px}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:16px}.table{width:100%;border-collapse:collapse;min-width:1000px}.table th,.table td{padding:10px 11px;border-bottom:1px solid #e5eaf2;text-align:left;vertical-align:top;font-size:13px}.table th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.03em;position:sticky;top:0}.table tr:last-child td{border-bottom:0}.right{text-align:right!important;white-space:nowrap}.muted{color:var(--muted)}.key{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere;font-size:12px}.badge{display:inline-flex;padding:6px 9px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:900}.badge.ok{background:#dcfce7;color:#15803d}.badge.err{background:#fee2e2;color:#b91c1c}.item{border:1px solid var(--line);border-radius:18px;margin-bottom:12px;overflow:hidden;background:#fff}.item-head{display:grid;grid-template-columns:minmax(260px,1.7fr) repeat(5,minmax(90px,.55fr));gap:10px;align-items:center;padding:14px;background:#fbfdff}.item-name strong{display:block;color:var(--p)}.item-name small{display:block;margin-top:4px;color:var(--muted)}.item-value span{display:block;color:var(--muted);font-size:10px;font-weight:850;text-transform:uppercase}.item-value strong{display:block;margin-top:3px}.rtc-main{margin:0 14px 14px;border:1px solid #bfd4ff;border-radius:16px;overflow:hidden;background:linear-gradient(135deg,#f7fbff,#f4f7ff)}.rtc-main-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;border-bottom:1px solid #dbe7ff;background:#edf4ff}.rtc-main-head strong{color:#0b1f5b}.rtc-main-head span{color:#476083;font-size:11px;font-weight:800}.rtc-main-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px;padding:10px}.rtc-card{border:1px solid #dbe4f0;border-radius:12px;padding:9px 10px;background:#fff;min-width:0}.rtc-card>span{display:block;font-size:10px;color:var(--muted);font-weight:850;text-transform:uppercase;letter-spacing:.025em}.rtc-card>strong{display:block;margin-top:4px;color:var(--p);font-size:15px;overflow-wrap:anywhere}.rtc-card>small{display:block;margin-top:3px;color:#475569;font-size:11px;overflow-wrap:anywhere}.rtc-card.ibs{border-left:4px solid #2454d6}.rtc-card.cbs{border-left:4px solid #047857}.rtc-card.total{border-left:4px solid #7c3aed}.taxes{padding:0 14px 14px}.taxes details{border-top:1px solid #e5eaf2;padding-top:12px}.taxes summary{cursor:pointer;color:#1e3a8a;font-weight:850}.tax-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:9px;margin-top:12px}.tax-field{border:1px solid #e5eaf2;border-radius:12px;padding:9px;background:#fff}.tax-field span{display:block;font-size:10px;color:var(--muted);font-weight:800}.tax-field strong{display:block;margin-top:3px;overflow-wrap:anywhere}.tax-group{margin-top:14px;padding-top:12px;border-top:1px dashed #cbd5e1}.tax-group:first-child{margin-top:0;padding-top:0;border-top:0}.extra{margin-top:8px;border-radius:12px;background:#f8fafc;padding:10px}.extra-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:7px}.extra div{font-size:12px;overflow-wrap:anywhere}.extra b{color:#475569}.empty{padding:24px;text-align:center;color:var(--muted)}@media(max-width:1100px){.kpis{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:repeat(2,1fr)}.item-head{grid-template-columns:1fr repeat(2,minmax(100px,.5fr))}.item-value:nth-of-type(n+4){display:none}}@media(max-width:680px){.rtc-main-head{display:block}.rtc-main-head span{display:block;margin-top:3px}.rtc-main-grid{grid-template-columns:1fr 1fr}.page{padding:14px}.head{display:block}.head .btn{margin-top:12px}.kpis,.grid{grid-template-columns:1fr 1fr}.topin{align-items:flex-start}.item-head{grid-template-columns:1fr}.item-value:nth-of-type(n){display:block}.table{min-width:850px}}@media print{.top,.head .btn{display:none}.page{max-width:none;padding:0}.card,.kpi,.item{box-shadow:none;break-inside:avoid}.taxes details{display:block}.taxes details>*{display:block}}
</style>
</head>
<body>
<header class="top"><div class="topin"><div class="brand"><img src="/assets/logo-nexa-prospero.jpg" alt="Próspero"><div><strong>Central do Contador Próspero</strong><span><?= $e((string)$company['name']) ?></span></div></div><div><a href="index.php?company=<?= $companyId ?>&view=documents">Documentos fiscais</a> &nbsp; <a href="logout.php">Sair</a></div></div></header>
<main class="page">
  <div class="head"><div><h1><?= $e($modelLabel) ?> <?= $e((string)($doc['series'] ?? '')) ?>/<?= $e((string)($doc['number'] ?? '')) ?></h1><p>Conferência completa do documento fiscal, participantes, produtos, tributação, IBS/CBS, pagamentos e eventos.</p></div><a class="btn" href="index.php?company=<?= $companyId ?>&view=documents">← Voltar aos documentos</a></div>

  <div class="kpis">
    <div class="kpi"><span>Situação</span><strong><span class="badge <?= (str_starts_with($status,'AUTORIZ')||str_starts_with($status,'IMPORTAD'))?'ok':(in_array($status,['CANCELADA','REJEITADA','ERRO_AUTORIZACAO'],true)?'err':'') ?>"><?= $e($status ?: '—') ?></span></strong></div>
    <div class="kpi"><span>Total documento</span><strong><?= $money($doc['total_document'] ?? 0) ?></strong></div>
    <div class="kpi"><span>Total produtos</span><strong><?= $money($doc['total_products'] ?? 0) ?></strong></div>
    <div class="kpi"><span>ICMS</span><strong><?= $money($doc['total_icms'] ?? 0) ?></strong></div>
    <div class="kpi"><span>PIS/COFINS</span><strong><?= $money(((float)($doc['total_pis']??0))+((float)($doc['total_cofins']??0))) ?></strong></div>
    <div class="kpi"><span>IBS/CBS</span><strong><?= $money(((float)($doc['total_ibs_uf']??0))+((float)($doc['total_ibs_mun']??0))+((float)($doc['total_cbs']??0))) ?></strong></div>
  </div>

  <section class="card"><h2>Dados do documento fiscal</h2><div class="grid">
    <?php foreach([
      'Movimento'=>strtoupper((string)($doc['direction']??''))==='ENTRADA'?'Entrada':'Saída',
      'Modelo'=>$modelLabel . ' (' . (string)($doc['model']??'') . ')',
      'Número / série'=>(string)($doc['number']??'') . ' / ' . (string)($doc['series']??''),
      'Emissão'=>$dateTime($doc['issue_datetime']??''),
      'Entrada/saída'=>$dateTime($doc['exit_entry_datetime']??''),
      'Competência'=>$dateOnly($doc['competence_date']??''),
      'Ambiente'=>(string)($doc['environment']??''),
      'Natureza da operação'=>(string)($doc['operation_nature']??''),
      'Chave de acesso'=>(string)($doc['access_key']??''),
      'Protocolo'=>(string)($doc['protocol_number']??''),
      'Código SEFAZ'=>(string)($doc['sefaz_status_code']??''),
      'Mensagem SEFAZ'=>(string)($doc['sefaz_status_message']??''),
      'Origem do registro'=>(string)($doc['source_type']??''),
      'Observações'=>(string)($doc['notes']??''),
    ] as $label=>$value): ?><div class="field"><span><?= $e($label) ?></span><strong class="<?= $label==='Chave de acesso'?'key':'' ?>"><?= $e($value!==''?$value:'—') ?></strong></div><?php endforeach; ?>
  </div></section>

  <section class="card"><h2>Participantes</h2><?php if(!$participants): ?><div class="empty">Nenhum participante gravado para este documento.</div><?php else: ?><div class="participants"><?php foreach($participants as $person): ?><article class="participant"><span class="role"><?= $e(strtoupper((string)($person['role']??'PARTICIPANTE'))) ?></span><h3><?= $e((string)($person['name']??'—')) ?></h3><p><b>CPF/CNPJ:</b> <?= $e($digitsDoc($person['document']??'')) ?> &nbsp; <b>IE:</b> <?= $e((string)($person['state_registration']??'')) ?></p><p><b>Endereço:</b> <?= $e(trim((string)($person['address']??'').' '.(string)($person['number']??'').' '.(string)($person['complement']??''))) ?></p><p><?= $e((string)($person['district']??'')) ?> · <?= $e((string)($person['city']??'')) ?>/<?= $e((string)($person['state']??'')) ?> · IBGE <?= $e((string)($person['city_ibge']??'')) ?> · CEP <?= $e((string)($person['zip_code']??'')) ?></p><p><b>Telefone:</b> <?= $e((string)($person['phone']??'')) ?> &nbsp; <b>E-mail:</b> <?= $e((string)($person['email']??'')) ?></p></article><?php endforeach; ?></div><?php endif; ?></section>

  <section class="card"><h2>Totais e impostos do documento</h2><div class="grid">
  <?php foreach([
    'Produtos'=>$doc['total_products']??0,'Desconto'=>$doc['total_discount']??0,'Frete'=>$doc['total_freight']??0,'Outras despesas'=>$doc['total_other']??0,
    'ICMS'=>$doc['total_icms']??0,'ICMS ST'=>$doc['total_icms_st']??0,'FCP'=>$doc['total_fcp']??0,'IPI'=>$doc['total_ipi']??0,
    'PIS'=>$doc['total_pis']??0,'COFINS'=>$doc['total_cofins']??0,'ISS'=>$doc['total_iss']??0,'IBS UF'=>$doc['total_ibs_uf']??0,
    'IBS Município'=>$doc['total_ibs_mun']??0,'CBS'=>$doc['total_cbs']??0,'Total IBS/CBS'=>((float)($doc['total_ibs_uf']??0))+((float)($doc['total_ibs_mun']??0))+((float)($doc['total_cbs']??0)),'Total da nota'=>$doc['total_document']??0,
  ] as $label=>$value): ?><div class="field"><span><?= $e($label) ?></span><strong><?= $money($value) ?></strong></div><?php endforeach; ?>
  </div></section>

  <section class="card"><h2>Produtos e tributação</h2><?php if(!$items): ?><div class="empty">Nenhum produto gravado para este documento.</div><?php endif; ?>
  <?php foreach($items as $item): $itemTaxes=$item['taxes']??[]; $rtc=$ibsCbsSummary($itemTaxes); ?>
    <article class="item">
      <div class="item-head">
        <div class="item-name"><strong><?= $e((string)($item['item_number']??'')) ?>. <?= $e((string)($item['description']??'')) ?></strong><small>Código <?= $e((string)($item['product_code']?:$item['supplier_product_code'])) ?> · EAN <?= $e((string)($item['ean']??'')) ?> · NCM <?= $e((string)($item['ncm']??'')) ?> · CEST <?= $e((string)($item['cest']??'')) ?> · CFOP <?= $e((string)($item['cfop']??'')) ?></small></div>
        <div class="item-value"><span>Quantidade</span><strong><?= $decimal($item['quantity']??0,4) ?> <?= $e((string)($item['unidade']??'')) ?></strong></div>
        <div class="item-value"><span>Valor unitário</span><strong><?= $money($item['unit_value']??0,4) ?></strong></div>
        <div class="item-value"><span>Desconto</span><strong><?= $money($item['discount_value']??0) ?></strong></div>
        <div class="item-value"><span>Frete/outros</span><strong><?= $money(((float)($item['freight_value']??0))+((float)($item['insurance_value']??0))+((float)($item['other_value']??0))) ?></strong></div>
        <div class="item-value"><span>Total</span><strong><?= $money(((float)($item['total_value']??0))-((float)($item['discount_value']??0))+((float)($item['freight_value']??0))+((float)($item['insurance_value']??0))+((float)($item['other_value']??0))) ?></strong></div>
      </div>
      <?php if($rtc): ?>
      <section class="rtc-main" aria-label="Resumo de IBS e CBS do item">
        <div class="rtc-main-head"><strong>IBS/CBS — campos principais do item</strong><span>Alíquotas e valores separados conforme o XML fiscal</span></div>
        <div class="rtc-main-grid">
          <div class="rtc-card"><span>Base IBS/CBS</span><strong><?= $money($rtc['base']) ?></strong><small>CST <?= $e($rtc['cst'] ?: '—') ?> · cClassTrib <?= $e($rtc['class'] ?: '—') ?></small></div>
          <div class="rtc-card ibs"><span>IBS UF — alíquota</span><strong><?= $decimal($rtc['ibs_uf_rate'],4) ?> %</strong><small>Valor: <?= $money($rtc['ibs_uf_amount']) ?></small></div>
          <div class="rtc-card ibs"><span>IBS Município — alíquota</span><strong><?= $decimal($rtc['ibs_mun_rate'],4) ?> %</strong><small>Valor: <?= $money($rtc['ibs_mun_amount']) ?></small></div>
          <div class="rtc-card ibs"><span>IBS total</span><strong><?= $money($rtc['ibs_amount']) ?></strong><small>Alíquota nominal: <?= $decimal($rtc['ibs_rate'],4) ?> %</small></div>
          <div class="rtc-card cbs"><span>CBS — alíquota</span><strong><?= $decimal($rtc['cbs_rate'],4) ?> %</strong><small>Valor: <?= $money($rtc['cbs_amount']) ?></small></div>
          <div class="rtc-card total"><span>Total IBS/CBS</span><strong><?= $money($rtc['total_amount']) ?></strong><small>Alíquota nominal total: <?= $decimal($rtc['total_rate'],4) ?> %</small></div>
          <div class="rtc-card"><span>Origem das alíquotas</span><strong><?= $e($rtc['rate_mode'] ?: '—') ?></strong><small><?= $e($rtc['rate_source'] ?: 'Origem não informada') ?></small></div>
        </div>
      </section>
      <?php endif; ?>
      <div class="taxes"><details open><summary>Tributação do item (<?= count($itemTaxes) ?> grupo(s))</summary><?php if(!$itemTaxes): ?><div class="empty">Nenhum imposto detalhado foi gravado para este item.</div><?php endif; ?><?php foreach($itemTaxes as $tax): $extraRaw=$taxExtra($tax); $extra=$flatten($extraRaw); $taxGroup=strtoupper(trim((string)($tax['tax_group']??''))); $isRtc=$taxGroup==='IBSCBS'; $taxFields=['Origem'=>$tax['origin_code']??'','CST'=>$tax['cst']??'','CSOSN'=>$tax['csosn']??'','Classificação'=>$tax['class_code']??'','Base'=>$money($tax['base_calc']??0),'Base ST'=>$money($tax['base_calc_st']??0),($isRtc?'Alíquota total nominal':'Alíquota')=>$decimal($tax['rate']??0,4).' %','Redução'=>$decimal($tax['reduction_rate']??0,4).' %','MVA'=>$decimal($tax['mva_rate']??0,4).' %',($isRtc?'Total IBS/CBS':'Valor')=>$money($tax['amount']??0)]; if($isRtc && $rtc){$taxFields['Classificação']=$rtc['class']; $taxFields['Base']=$money($rtc['base']); $taxFields['Alíquota total nominal']=$decimal($rtc['total_rate'],4).' %'; $taxFields['Total IBS/CBS']=$money($rtc['total_amount']);} ?><div class="tax-group"><h3><?= $e((string)($tax['tax_group']??'IMPOSTO')) ?><?= !empty($tax['tax_tag'])?' · '.$e((string)$tax['tax_tag']):'' ?></h3><div class="tax-grid">
        <?php foreach($taxFields as $label=>$value): ?><div class="tax-field"><span><?= $e($label) ?></span><strong><?= $e($value!==''?$value:'—') ?></strong></div><?php endforeach; ?>
        </div><?php if($extra): ?><div class="extra"><div class="extra-grid"><?php foreach($extra as $key=>$value): ?><div><b><?= $e($key) ?>:</b> <?= $e($value) ?></div><?php endforeach; ?></div></div><?php endif; ?></div><?php endforeach; ?></details></div>
    </article>
  <?php endforeach; ?></section>

  <?php if($payments): ?><section class="card"><h2>Pagamentos</h2><div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Descrição</th><th>Indicador</th><th>Bandeira</th><th>Autorização</th><th class="right">Valor</th></tr></thead><tbody><?php foreach($payments as $payment): ?><tr><td><?= $e((string)($payment['payment_type']??'')) ?></td><td><?= $e((string)($payment['payment_description']??'')) ?></td><td><?= $e((string)($payment['payment_indicator']??'')) ?></td><td><?= $e((string)($payment['card_brand']??'')) ?></td><td><?= $e((string)($payment['authorization_code']??'')) ?></td><td class="right"><strong><?= $money($payment['amount']??0) ?></strong></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>

  <?php if($references): ?><section class="card"><h2>Documentos referenciados</h2><div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Chave</th><th>Modelo</th><th>Série</th><th>Número</th><th>Mês de emissão</th></tr></thead><tbody><?php foreach($references as $ref): ?><tr><td><?= $e((string)($ref['reference_type']??'')) ?></td><td class="key"><?= $e((string)($ref['reference_key']??'')) ?></td><td><?= $e((string)($ref['model']??'')) ?></td><td><?= $e((string)($ref['series']??'')) ?></td><td><?= $e((string)($ref['number']??'')) ?></td><td><?= $e((string)($ref['issue_month']??'')) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>

  <?php if($events): ?><section class="card"><h2>Eventos e retornos fiscais</h2><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Evento</th><th>Sequência</th><th>Protocolo</th><th>Código</th><th>Mensagem</th></tr></thead><tbody><?php foreach($events as $event): ?><tr><td><?= $e($dateTime($event['event_datetime']??$event['created_at']??'')) ?></td><td><?= $e((string)($event['event_type']??'')) ?></td><td><?= (int)($event['event_sequence']??0) ?></td><td><?= $e((string)($event['protocol_number']??'')) ?></td><td><?= $e((string)($event['status_code']??'')) ?></td><td><?= $e((string)($event['status_message']??'')) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
</main>
</body>
</html>
