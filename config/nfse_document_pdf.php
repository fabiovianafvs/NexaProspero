<?php
declare(strict_types=1);
require_once __DIR__ . '/print_codes.php';

if (!function_exists('nfse_document_human_money')) {
  function nfse_document_human_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
}
if (!function_exists('nfse_document_human_date')) {
  function nfse_document_human_date($value, bool $withTime = true): string {
    $raw = trim((string)$value); if ($raw === '') return '';
    $ts = strtotime($raw); return $ts ? date($withTime ? 'd/m/Y H:i:s' : 'd/m/Y', $ts) : $raw;
  }
}
if (!function_exists('nfse_document_digits')) {
  function nfse_document_digits($value): string { return preg_replace('/\D+/', '', (string)$value) ?: ''; }
}
if (!function_exists('nfse_document_format_document')) {
  function nfse_document_format_document(string $value): string {
    $d = nfse_document_digits($value);
    if (strlen($d) === 14) return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
    if (strlen($d) === 11) return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
    return $value;
  }
}
if (!function_exists('nfse_document_format_zip')) {
  function nfse_document_format_zip(string $value): string {
    $d = nfse_document_digits($value); return strlen($d) === 8 ? substr($d,0,5).'-'.substr($d,5,3) : $value;
  }
}
if (!function_exists('nfse_document_xpath_text')) {
  function nfse_document_xpath_text(DOMXPath $xp, string $query, ?DOMNode $ctx = null): string {
    try { $n = $xp->query($query, $ctx)->item(0); return $n ? trim((string)$n->textContent) : ''; }
    catch (Throwable $e) { return ''; }
  }
}
if (!function_exists('nfse_document_view_data')) {
  function nfse_document_view_data(array $document): array {
    $xml = trim((string)($document['response_xml'] ?? $document['xml_raw'] ?? ''));
    if ($xml === '') throw new RuntimeException('O XML autorizado da NFS-e não está armazenado no sistema.');
    if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) throw new RuntimeException('A extensão PHP DOM/XML não está habilitada.');
    $dom = new DOMDocument(); libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml)) throw new RuntimeException('O XML autorizado da NFS-e não pôde ser lido.');
    $xp = new DOMXPath($dom);
    $inf = $xp->query('//*[local-name()="infNFSe"]')->item(0);
    if (!$inf) throw new RuntimeException('O XML não contém o grupo infNFSe esperado.');
    $dps = $xp->query('.//*[local-name()="DPS"]/*[local-name()="infDPS"]', $inf)->item(0);
    $emit = $xp->query('./*[local-name()="emit"]', $inf)->item(0);
    $tom = $dps ? $xp->query('./*[local-name()="toma"]', $dps)->item(0) : null;
    $cServ = $dps ? $xp->query('./*[local-name()="serv"]/*[local-name()="cServ"]', $dps)->item(0) : null;
    $vals = $xp->query('./*[local-name()="valores"]', $inf)->item(0);
    $ibs = $xp->query('./*[local-name()="IBSCBS"]', $inf)->item(0);

    $addr = static function(?DOMNode $node, string $prefix = './*[local-name()="enderNac"]') use ($xp): array {
      if (!$node) return ['address'=>'','number'=>'','complement'=>'','district'=>'','city'=>'','uf'=>'','zip'=>''];
      $root = $xp->query($prefix, $node)->item(0);
      if (!$root && str_contains($prefix, 'enderNac')) $root = $xp->query('./*[local-name()="end"]', $node)->item(0);
      if (!$root) return ['address'=>'','number'=>'','complement'=>'','district'=>'','city'=>'','uf'=>'','zip'=>''];
      return [
        'address'=>nfse_document_xpath_text($xp,'.//*[local-name()="xLgr"]',$root),
        'number'=>nfse_document_xpath_text($xp,'.//*[local-name()="nro"]',$root),
        'complement'=>nfse_document_xpath_text($xp,'.//*[local-name()="xCpl"]',$root),
        'district'=>nfse_document_xpath_text($xp,'.//*[local-name()="xBairro"]',$root),
        'city'=>nfse_document_xpath_text($xp,'.//*[local-name()="xMun"]',$root),
        'uf'=>nfse_document_xpath_text($xp,'.//*[local-name()="UF"]',$root),
        'zip'=>nfse_document_xpath_text($xp,'.//*[local-name()="CEP"]',$root),
      ];
    };

    $issuerAddr = $addr($emit);
    $customerAddr = $addr($tom, './*[local-name()="end"]');
    $issuerFallback = (array)($document['_issuer_identity'] ?? []);
    $prestFallback = (array)($document['_prestador'] ?? []);
    $tomFallback = (array)($document['_tomador'] ?? []);
    foreach (['address'=>'address','number'=>'number','complement'=>'complement','district'=>'district','city'=>'city','uf'=>'state','zip'=>'zip_code'] as $k=>$fk) {
      if ($issuerAddr[$k] === '') $issuerAddr[$k] = trim((string)($prestFallback[$fk] ?? $issuerFallback[$fk] ?? ''));
    }
    foreach (['address','number','complement','district','city'] as $k) if ($customerAddr[$k] === '') $customerAddr[$k] = trim((string)($tomFallback[$k] ?? ''));
    if ($customerAddr['uf'] === '') $customerAddr['uf'] = trim((string)($tomFallback['state'] ?? ''));
    if ($customerAddr['zip'] === '') $customerAddr['zip'] = trim((string)($tomFallback['zip_code'] ?? ''));

    $idAttr = $inf instanceof DOMElement ? trim((string)$inf->getAttribute('Id')) : '';
    $key = nfse_document_digits($document['access_key'] ?? '');
    if ($key === '' && str_starts_with($idAttr, 'NFS')) $key = nfse_document_digits(substr($idAttr, 3));
    $nfseNumber = trim((string)($document['nfse_number'] ?? '')) ?: nfse_document_xpath_text($xp,'./*[local-name()="nNFSe"]',$inf);
    $dpsNumber = trim((string)($document['dps_number'] ?? '')) ?: ($dps ? nfse_document_xpath_text($xp,'./*[local-name()="nDPS"]',$dps) : '');
    $dpsSeries = trim((string)($document['dps_series'] ?? '')) ?: ($dps ? nfse_document_xpath_text($xp,'./*[local-name()="serie"]',$dps) : '');
    $issue = nfse_document_xpath_text($xp,'./*[local-name()="dhProc"]',$inf);
    if ($issue === '' && $dps) $issue = nfse_document_xpath_text($xp,'./*[local-name()="dhEmi"]',$dps);
    if ($issue === '') $issue = trim((string)($document['authorization_datetime'] ?? $document['issue_datetime'] ?? ''));
    $competence = $dps ? nfse_document_xpath_text($xp,'./*[local-name()="dCompet"]',$dps) : '';
    if ($competence === '') $competence = trim((string)($document['competence_date'] ?? ''));

    $serviceValue = $dps ? nfse_document_xpath_text($xp,'./*[local-name()="valores"]/*[local-name()="vServPrest"]/*[local-name()="vServ"]',$dps) : '';
    $base = $vals ? nfse_document_xpath_text($xp,'./*[local-name()="vBC"]',$vals) : '';
    $aliq = $vals ? nfse_document_xpath_text($xp,'./*[local-name()="pAliqAplic"]',$vals) : '';
    $iss = $vals ? nfse_document_xpath_text($xp,'./*[local-name()="vISSQN"]',$vals) : '';
    $liquid = $vals ? nfse_document_xpath_text($xp,'./*[local-name()="vLiq"]',$vals) : '';
    $ibsTotal = $ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="totCIBS"]/*[local-name()="gIBS"]/*[local-name()="vIBSTot"]',$ibs) : '';
    if ($ibsTotal === '' && $ibs) {
      $u=(float)(nfse_document_xpath_text($xp,'.//*[local-name()="vIBSUF"]',$ibs) ?: 0); $m=(float)(nfse_document_xpath_text($xp,'.//*[local-name()="vIBSMun"]',$ibs) ?: 0); $ibsTotal=(string)($u+$m);
    }
    $cbsTotal = $ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="totCIBS"]/*[local-name()="gCBS"]/*[local-name()="vCBS"]',$ibs) : '';
    $issuerDoc = $emit ? (nfse_document_xpath_text($xp,'./*[local-name()="CNPJ"]',$emit) ?: nfse_document_xpath_text($xp,'./*[local-name()="CPF"]',$emit)) : '';
    $customerDoc = $tom ? (nfse_document_xpath_text($xp,'./*[local-name()="CNPJ"]',$tom) ?: nfse_document_xpath_text($xp,'./*[local-name()="CPF"]',$tom)) : '';

    $tpAmb = $dps ? nfse_document_xpath_text($xp,'./*[local-name()="tpAmb"]',$dps) : '';
    if($tpAmb==='') $tpAmb=nfse_document_xpath_text($xp,'./*[local-name()="tpAmb"]',$inf);
    $ambGer=nfse_document_xpath_text($xp,'./*[local-name()="ambGer"]',$inf);
    $dpsIssue=$dps ? nfse_document_xpath_text($xp,'./*[local-name()="dhEmi"]',$dps) : '';
    $finNFSe=$dps ? nfse_document_xpath_text($xp,'./*[local-name()="finNFSe"]',$dps) : '';
    $opSimp=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="opSimpNac"]',$dps) : '';
    $regAp=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="regApTribSN"]',$dps) : '';
    $regEsp=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="regEspTrib"]',$dps) : '';
    $tribIss=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="tribISSQN"]',$dps) : '';
    $retIss=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="tpRetISSQN"]',$dps) : '';
    $aliqDps=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="pAliq"]',$dps) : '';
    $issuerCityCode=$emit ? nfse_document_xpath_text($xp,'.//*[local-name()="cMun"]',$emit) : '';
    $customerCityCode=$tom ? nfse_document_xpath_text($xp,'.//*[local-name()="cMun"]',$tom) : '';
    $ibsCst=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="IBSCBS"]//*[local-name()="CST"]',$dps) : '';
    $ibsClass=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="IBSCBS"]//*[local-name()="cClassTrib"]',$dps) : '';
    $operationIndicator=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="IBSCBS"]//*[local-name()="cIndOp"]',$dps) : '';
    $incidenceCode=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="cLocalidadeIncid"]',$ibs) : '';
    $incidenceName=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="xLocalidadeIncid"]',$ibs) : '';
    $ibsBase=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="gIBSCBS"]/*[local-name()="vBC"]',$ibs) : '';
    if($ibsBase==='') $ibsBase=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="vBC"]',$ibs) : '';
    $ibsUfRate=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="pIBSUF"]',$ibs) : '';
    $ibsMunRate=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="pIBSMun"]',$ibs) : '';
    $cbsRate=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="pCBS"]',$ibs) : '';
    $ibsUf=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="vIBSUF"]',$ibs) : '';
    $ibsMun=$ibs ? nfse_document_xpath_text($xp,'.//*[local-name()="vIBSMun"]',$ibs) : '';
    $discountUncond=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="vDescIncond"]',$dps) : '';
    $discountCond=$dps ? nfse_document_xpath_text($xp,'.//*[local-name()="vDescCond"]',$dps) : '';
    $additionalInfo=$dps ? (nfse_document_xpath_text($xp,'.//*[local-name()="xInfComp"]',$dps) ?: nfse_document_xpath_text($xp,'.//*[local-name()="infCpl"]',$dps)) : '';
    $simpleLabel=match($opSimp){'1'=>'Não optante','2'=>'Optante - MEI','3'=>'Optante - ME/EPP',default=>$opSimp!==''?'Indicador '.$opSimp:'—'};
    $environmentLabel=match($tpAmb){'1'=>'Produção','2'=>'Homologação',default=>$tpAmb!==''?'Ambiente '.$tpAmb:(strtoupper(trim((string)($document['environment']??'')))==='PRODUCAO'?'Produção':'Homologação')};
    $generatorLabel=match($ambGer){'1'=>'Prefeitura','2'=>'Ambiente nacional',default=>$ambGer!==''?'Código '.$ambGer:'—'};
    $finalityLabel=match($finNFSe){'0'=>'NFS-e regular',default=>$finNFSe!==''?'Código '.$finNFSe:'NFS-e regular'};

    return [
      'xml'=>$xml,
      'status'=>strtoupper(trim((string)($document['status'] ?? $document['nfse_status'] ?? 'AUTORIZADA'))),
      'environment'=>strtoupper(trim((string)($document['environment'] ?? ''))),
      'provider'=>trim((string)($document['provider_code'] ?? '')),
      'nfse_number'=>$nfseNumber,
      'dps_number'=>$dpsNumber,
      'dps_series'=>ltrim($dpsSeries,'0') !== '' ? ltrim($dpsSeries,'0') : $dpsSeries,
      'issue_datetime'=>$issue,
      'competence'=>$competence,
      'access_key'=>$key,
      'verification_code'=>trim((string)($document['verification_code'] ?? '')),
      'city_issue'=>nfse_document_xpath_text($xp,'./*[local-name()="xLocEmi"]',$inf),
      'city_service'=>nfse_document_xpath_text($xp,'./*[local-name()="xLocPrestacao"]',$inf),
      'national_service_label'=>nfse_document_xpath_text($xp,'./*[local-name()="xTribNac"]',$inf),
      'municipal_service_label'=>nfse_document_xpath_text($xp,'./*[local-name()="xTribMun"]',$inf),
      'nbs_label'=>nfse_document_xpath_text($xp,'./*[local-name()="xNBS"]',$inf),
      'meta'=>[
        'environment_type'=>$environmentLabel,
        'generator'=>$generatorLabel,
        'dps_issue_datetime'=>$dpsIssue,
        'finality'=>$finalityLabel,
        'simple_national'=>$simpleLabel,
        'simple_national_code'=>$opSimp,
        'simple_tax_regime'=>$regAp,
        'special_tax_regime'=>$regEsp,
      ],
      'issuer'=>[
        'name'=>($emit ? nfse_document_xpath_text($xp,'./*[local-name()="xNome"]',$emit) : '') ?: trim((string)($prestFallback['name'] ?? $issuerFallback['name'] ?? '')),
        'document'=>$issuerDoc ?: trim((string)($prestFallback['document'] ?? $issuerFallback['document'] ?? '')),
        'municipal_registration'=>($emit ? nfse_document_xpath_text($xp,'./*[local-name()="IM"]',$emit) : '') ?: trim((string)($prestFallback['municipal_registration'] ?? '')),
        'state_registration'=>trim((string)($prestFallback['state_registration'] ?? $issuerFallback['ie'] ?? '')),
        'phone'=>trim((string)($prestFallback['phone'] ?? $issuerFallback['phone'] ?? '')),
        'email'=>trim((string)($prestFallback['email'] ?? '')),
        'city_code'=>$issuerCityCode,
        'address'=>$issuerAddr,
      ],
      'customer'=>[
        'name'=>($tom ? nfse_document_xpath_text($xp,'./*[local-name()="xNome"]',$tom) : '') ?: trim((string)($tomFallback['name'] ?? '')),
        'document'=>$customerDoc ?: trim((string)($tomFallback['document'] ?? '')),
        'municipal_registration'=>($tom ? nfse_document_xpath_text($xp,'./*[local-name()="IM"]',$tom) : '') ?: trim((string)($tomFallback['municipal_registration'] ?? '')),
        'phone'=>($tom ? nfse_document_xpath_text($xp,'./*[local-name()="fone"]',$tom) : '') ?: trim((string)($tomFallback['phone'] ?? '')),
        'email'=>($tom ? nfse_document_xpath_text($xp,'./*[local-name()="email"]',$tom) : '') ?: trim((string)($tomFallback['email'] ?? '')),
        'city_code'=>$customerCityCode,
        'address'=>$customerAddr,
      ],
      'service'=>[
        'national_code'=>$cServ ? nfse_document_xpath_text($xp,'./*[local-name()="cTribNac"]',$cServ) : '',
        'municipal_code'=>$cServ ? nfse_document_xpath_text($xp,'./*[local-name()="cTribMun"]',$cServ) : '',
        'internal_code'=>$cServ ? nfse_document_xpath_text($xp,'./*[local-name()="cIntContrib"]',$cServ) : '',
        'nbs'=>$cServ ? nfse_document_xpath_text($xp,'./*[local-name()="cNBS"]',$cServ) : '',
        'description'=>$cServ ? nfse_document_xpath_text($xp,'./*[local-name()="xDescServ"]',$cServ) : '',
      ],
      'tax'=>[
        'iss'=>[
          'type'=>$tribIss,
          'retention'=>$retIss,
          'rate'=>(float)($aliqDps!==''?$aliqDps:($aliq!==''?$aliq:0)),
        ],
        'ibscbs'=>[
          'cst'=>$ibsCst,'class'=>$ibsClass,'operation_indicator'=>$operationIndicator,
          'incidence_code'=>$incidenceCode,'incidence_name'=>$incidenceName,
          'base'=>(float)($ibsBase!==''?$ibsBase:0),'ibs_uf_rate'=>(float)($ibsUfRate!==''?$ibsUfRate:0),
          'ibs_mun_rate'=>(float)($ibsMunRate!==''?$ibsMunRate:0),'cbs_rate'=>(float)($cbsRate!==''?$cbsRate:0),
          'ibs_uf'=>(float)($ibsUf!==''?$ibsUf:0),'ibs_mun'=>(float)($ibsMun!==''?$ibsMun:0),
        ],
      ],
      'discounts'=>[
        'unconditional'=>(float)($discountUncond!==''?$discountUncond:0),
        'conditional'=>(float)($discountCond!==''?$discountCond:0),
      ],
      'additional_info'=>$additionalInfo,
      'values'=>[
        'service'=>(float)($serviceValue !== '' ? $serviceValue : ($document['total_document'] ?? 0)),
        'base'=>(float)($base !== '' ? $base : 0),
        'iss_rate'=>(float)($aliq !== '' ? $aliq : 0),
        'iss'=>(float)($iss !== '' ? $iss : ($document['total_iss'] ?? 0)),
        'liquid'=>(float)($liquid !== '' ? $liquid : ($document['total_document'] ?? 0)),
        'ibs'=>(float)($ibsTotal !== '' ? $ibsTotal : 0),
        'cbs'=>(float)($cbsTotal !== '' ? $cbsTotal : 0),
      ],
    ];
  }
}
if (!function_exists('nfse_document_address_line')) {
  function nfse_document_address_line(array $p): string {
    $a=(array)($p['address']??[]); $parts=[];
    $street=trim((string)($a['address']??'')); $num=trim((string)($a['number']??''));
    if($street!=='')$parts[]=$street.($num!==''?', '.$num:'');
    if(trim((string)($a['complement']??''))!=='')$parts[]=trim((string)$a['complement']);
    if(trim((string)($a['district']??''))!=='')$parts[]=trim((string)$a['district']);
    $city=trim((string)($a['city']??''));$uf=trim((string)($a['uf']??''));if($city!==''||$uf!=='')$parts[]=trim($city.($uf!==''?' - '.$uf:''));
    $zip=nfse_document_format_zip((string)($a['zip']??''));if($zip!=='')$parts[]='CEP '.$zip;
    return implode(' · ',array_filter($parts,static fn($v)=>trim((string)$v)!==''));
  }
}
if (!function_exists('nfse_document_pdf_bootstrap')) {
  function nfse_document_pdf_bootstrap(): void {
    if (class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) return;
    $fpdf=__DIR__.'/../vendor/nfephp-org/sped-da/src/Legacy/FPDF/Fpdf.php'; if(is_file($fpdf))require_once $fpdf;
    if(!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)){ $autoload=__DIR__.'/../vendor/autoload.php';if(is_file($autoload))require_once $autoload; }
    if(!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class))throw new RuntimeException('Biblioteca de PDF não disponível.');
  }
}
if (!function_exists('nfse_document_pdf_text')) {
  function nfse_document_pdf_text(string $v): string { $o=@iconv('UTF-8','Windows-1252//TRANSLIT',$v);return $o!==false?$o:(preg_replace('/[^\x20-\x7E]/','?',$v)??$v); }
}
if (!function_exists('nfse_document_pdf_section')) {
  function nfse_document_pdf_section($pdf,string $title): void { $pdf->SetFillColor(239,244,255);$pdf->SetTextColor(11,31,91);$pdf->SetFont('Arial','B',8.6);$pdf->Cell(0,6,nfse_document_pdf_text($title),1,1,'L',true);$pdf->SetTextColor(17,24,39); }
}
if (!function_exists('nfse_document_pdf_pair')) {
  function nfse_document_pdf_pair($pdf,string $label,string $value,float $labelW=34): void { if(trim($value)==='')return;$pdf->SetFont('Arial','B',8);$pdf->Cell($labelW,4.6,nfse_document_pdf_text($label),0,0);$pdf->SetFont('Arial','',8);$pdf->MultiCell(0,4.6,nfse_document_pdf_text($value),0,'L'); }
}
if (!function_exists('nfse_document_company_logo_path')) {
  function nfse_document_company_logo_path(array $document): string {
    $companyId=(int)($document['company_id']??0); if($companyId<=0)return '';
    try{
      require_once __DIR__.'/load_config.php';
      if(!function_exists('load_business_config'))return '';
      $biz=(array)load_business_config($companyId);$logo=(array)($biz['logo']??[]);
      if(array_key_exists('enabled',$logo)&&empty($logo['enabled']))return '';
      $file=basename(trim((string)($logo['file']??'')));if($file==='')return '';
      $path=dirname(__DIR__).'/uploads/logos/'.$file;return is_file($path)?$path:'';
    }catch(Throwable $e){return '';}
  }
}

if (!function_exists('nfse_document_pdf_build')) {
  function nfse_document_pdf_build(array $document): array {
    nfse_document_pdf_bootstrap();
    $d=nfse_document_view_data($document); $tx='nfse_document_pdf_text';
    $pdf=new \NFePHP\DA\Legacy\FPDF\Fpdf('P','mm','A4');
    $pdf->SetMargins(7,7,7);$pdf->SetAutoPageBreak(false);$pdf->AddPage();$pdf->SetTextColor(17,17,17);$pdf->SetDrawColor(20,20,20);$pdf->SetLineWidth(.18);
    $left=7.0;$pageW=196.0;$y=7.0;
    $meta=(array)($d['meta']??[]);$iss=(array)($d['tax']['iss']??[]);$ibs=(array)($d['tax']['ibscbs']??[]);$companyLogoPath=nfse_document_company_logo_path($document);$companyBrand=trim((string)($d['issuer']['name']??''));
    $simple=(string)($meta['simple_national']??'—');$regAp=(string)($meta['simple_tax_regime']??'');$regAp=$regAp==='1'?'Regime de apuração pelo Simples Nacional':($regAp!==''?'Indicador '.$regAp:'—');
    $regEsp=(string)($meta['special_tax_regime']??'');$regEsp=$regEsp==='0'?'Nenhum':($regEsp!==''?'Código '.$regEsp:'—');
    $issType=(string)($iss['type']??'');$issType=$issType==='1'?'Operação tributável':($issType!==''?'Código '.$issType:'—');
    $issRet=(string)($iss['retention']??'');$issRet=match($issRet){'1'=>'Não retido','2'=>'Retido pelo tomador','3'=>'Retido pelo intermediário',default=>$issRet!==''?'Código '.$issRet:'—'};
    $partyDoc=static fn(array $p):string=>nfse_document_format_document((string)($p['document']??''));
    $cityZip=static function(array $p):string{$a=(array)($p['address']??[]);$city=trim((string)($a['city']??''));$uf=trim((string)($a['uf']??''));$cep=nfse_document_format_zip((string)($a['zip']??''));$code=trim((string)($p['city_code']??''));$l=trim($city.($uf!==''?' / '.$uf:''));$r=trim($code.($cep!==''?' / '.$cep:''));return trim($l.($r!==''?' · '.$r:''));};
    $pct=static fn($v):string=>number_format((float)$v,2,',','.').' %';
    $safe=static fn($v,string $fallback='—'):string=>trim((string)$v)!==''?(string)$v:$fallback;
    $upper=static fn(string $v):string=>function_exists('mb_strtoupper')?mb_strtoupper($v,'UTF-8'):strtoupper($v);

    $wrap=function(string $text,float $width,float $size=7.1,string $style='') use($pdf):array{
      $pdf->SetFont('Arial',$style,$size);$max=max(4.0,$width-2.4);$out=[];
      foreach(preg_split('/\R/u',$text)?:[''] as $para){$words=preg_split('/\s+/u',trim((string)$para))?:[];$line='';if(!$words){$out[]='';continue;}foreach($words as $word){$test=$line===''?$word:$line.' '.$word;if($pdf->GetStringWidth(nfse_document_pdf_text($test))<=$max){$line=$test;continue;}if($line!=='')$out[]=$line;if($pdf->GetStringWidth(nfse_document_pdf_text($word))>$max){$chunk='';foreach(preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)?:[] as $ch){$t=$chunk.$ch;if($pdf->GetStringWidth(nfse_document_pdf_text($t))>$max&&$chunk!==''){$out[]=$chunk;$chunk=$ch;}else{$chunk=$t;}}$line=$chunk;}else{$line=$word;}}if($line!==''||!$out)$out[]=$line;}
      return $out?:[''];
    };
    $fieldHeight=function(string $label,string $value,float $width,bool $bold=false) use($wrap):float{$lines=$wrap($value,$width,7.0,$bold?'B':'');return max(8.5,4.1+count($lines)*3.4);};
    $drawField=function(float $x,float $yy,float $w,float $h,string $label,string $value,bool $bold=false) use($pdf,$tx,$wrap,$upper):void{
      $pdf->Rect($x,$yy,$w,$h);$pdf->SetXY($x+1.1,$yy+.8);$pdf->SetFont('Arial','B',5.8);$pdf->Cell($w-2.2,2.8,$tx($upper($label)),0,1,'L');
      $pdf->SetXY($x+1.1,$yy+3.7);$pdf->SetFont('Arial',$bold?'B':'',7.0);$lines=$wrap($value,$w,7.0,$bold?'B':'');foreach($lines as $line){$pdf->SetX($x+1.1);$pdf->Cell($w-2.2,3.25,$tx($line),0,1,'L');}
    };
    $row=function(array $fields,array $widths) use(&$y,$left,$fieldHeight,$drawField):float{
      $h=8.5;foreach($fields as $i=>$f){$w=(float)$widths[$i];$h=max($h,$fieldHeight((string)$f[0],(string)$f[1],$w,(bool)($f[2]??false)));}$x=$left;foreach($fields as $i=>$f){$w=(float)$widths[$i];$drawField($x,$y,$w,$h,(string)$f[0],(string)$f[1],(bool)($f[2]??false));$x+=$w;}$y+=$h;return $h;
    };
    $section=function(string $title) use(&$y,$left,$pageW,$pdf,$tx,$upper):void{$pdf->SetFillColor(232,232,232);$pdf->SetFont('Arial','B',6.5);$pdf->SetXY($left,$y);$pdf->Cell($pageW,4.7,$tx($upper($title)),1,1,'L',true);$y+=4.7;};
    $ensure=function(float $need) use(&$y,$pdf):void{if($y+$need<=288)return;$pdf->AddPage();$y=7.0;};

    if(strtoupper((string)($d['environment']??''))!=='PRODUCAO' && (($meta['environment_type']??'')!=='Produção')){$pdf->SetFont('Arial','B',9);$pdf->Cell($pageW,6,$tx('AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL'),1,1,'C');$y+=6;}
    if($d['status']==='CANCELADA'){$pdf->SetTextColor(153,27,27);$pdf->SetFont('Arial','B',10);$pdf->Cell($pageW,6,$tx('NFS-e CANCELADA'),1,1,'C');$pdf->SetTextColor(17,17,17);$y+=6;}

    // Cabeçalho no padrão visual do DANFSe: NFS-e à esquerda, título no centro e logo da empresa à direita.
    $h=20;$w1=44;$w2=102;$w3=50;
    $pdf->Rect($left,$y,$w1,$h);$pdf->Rect($left+$w1,$y,$w2,$h);$pdf->Rect($left+$w1+$w2,$y,$w3,$h);
    $pdf->SetXY($left+2,$y+4);$pdf->SetTextColor(36,124,86);$pdf->SetFont('Arial','B',20);$pdf->Cell($w1-4,7,$tx('NFS-e'),0,1,'L');$pdf->SetTextColor(17,17,17);$pdf->SetXY($left+2,$y+12);$pdf->SetFont('Arial','',5.8);$pdf->Cell($w1-4,3,$tx('Nota Fiscal de Serviço eletrônica'),0,0,'L');
    $pdf->SetXY($left+$w1,$y+3);$pdf->SetFont('Arial','B',10);$pdf->Cell($w2,4,$tx('DANFSe'),0,1,'C');$pdf->SetX($left+$w1);$pdf->SetFont('Arial','B',8.5);$pdf->Cell($w2,4,$tx('Documento Auxiliar da NFS-e'),0,1,'C');$pdf->SetX($left+$w1);$pdf->SetFont('Arial','',5.5);$envLine='Município: '.$safe($d['city_issue']).' | Gerador: '.$safe($meta['generator']??'').' | '.$safe($meta['environment_type']??'');$pdf->Cell($w2,3.4,$tx($envLine),0,0,'C');
    $logoDrawn=false;if($companyLogoPath!==''){try{$ext=strtolower(pathinfo($companyLogoPath,PATHINFO_EXTENSION));if(in_array($ext,['png','jpg','jpeg','gif'],true)){$pdf->Image($companyLogoPath,$left+$w1+$w2+4,$y+2,$w3-8,$h-4);$logoDrawn=true;}}catch(Throwable $e){$logoDrawn=false;}}
    if(!$logoDrawn){$pdf->SetXY($left+$w1+$w2+2,$y+6);$pdf->SetFont('Arial','B',7.2);$pdf->MultiCell($w3-4,3.5,$tx($companyBrand!==''?$companyBrand:'Empresa'),0,'R');}$y+=$h;

    // Chave + QR.
    $accessH=29;$qrW=32;$pdf->Rect($left,$y,$pageW-$qrW,$accessH);$pdf->Rect($left+$pageW-$qrW,$y,$qrW,$accessH);$pdf->SetXY($left+1.2,$y+1);$pdf->SetFont('Arial','B',6);$pdf->Cell($pageW-$qrW-2.4,3,$tx('CHAVE DE ACESSO DA NFS-e'),0,1);$pdf->SetX($left+1.2);$pdf->SetFont('Courier','B',7.2);$pdf->MultiCell($pageW-$qrW-2.4,3.7,$tx($safe($d['access_key'])),0,'L');$pdf->SetXY($left+1.2,$y+17);$pdf->SetFont('Arial','',5.5);$pdf->MultiCell($pageW-$qrW-4,3,$tx('A chave identifica este documento. O QR contém a chave para leitura rápida e conferência no ambiente oficial.'),0,'L');
    $qrData=nexa_print_qr_png_data((string)$d['access_key'],3,4);$tmp='';if($qrData!==''){try{$tmp=tempnam(sys_get_temp_dir(),'nexa_nfse_qr_');if($tmp!==false){file_put_contents($tmp,$qrData);$pdf->Image($tmp,$left+$pageW-$qrW+3,$y+2,26,26,'PNG');}}catch(Throwable $e){$tmp='';}}if($tmp&&is_file($tmp))@unlink($tmp);$y+=$accessH;

    $row([['Número da NFS-e',$safe($d['nfse_number']),true],['Competência da NFS-e',nfse_document_human_date($d['competence'],false)?:'—',true],['Data e hora da emissão',nfse_document_human_date($d['issue_datetime'])?:'—',true]],[$pageW/3,$pageW/3,$pageW/3]);
    $row([['Número da DPS',$safe($d['dps_number']),true],['Série da DPS',$safe($d['dps_series']),true],['Data e hora da emissão da DPS',nfse_document_human_date($meta['dps_issue_datetime']??'')?:'—',true]],[$pageW/3,$pageW/3,$pageW/3]);
    $row([['Emitente da NFS-e','Prestador',true],['Situação da NFS-e',$safe($d['status']),true],['Finalidade',$safe($meta['finality']??''),true]],[$pageW/3,$pageW/3,$pageW/3]);

    $ensure(42);$section('Prestador / Fornecedor');
    $row([['CNPJ / CPF / NIF',$partyDoc($d['issuer']),true],['Inscrição Municipal',$safe($d['issuer']['municipal_registration'])],['Telefone',$safe($d['issuer']['phone'])],['Município / UF · Cód. IBGE / CEP',$cityZip($d['issuer'])]],[$pageW*.22,$pageW*.18,$pageW*.18,$pageW*.42]);
    $row([['Nome / Nome Empresarial',$safe($d['issuer']['name']),true],['E-mail',$safe($d['issuer']['email'])]],[$pageW*.55,$pageW*.45]);
    $row([['Endereço',nfse_document_address_line($d['issuer']),true]],[$pageW]);
    $row([['Simples Nacional na competência',$simple],['Regime de apuração tributária pelo SN',$regAp]],[$pageW*.42,$pageW*.58]);

    $ensure(35);$section('Tomador / Adquirente');
    $row([['CNPJ / CPF / NIF',$partyDoc($d['customer']),true],['Inscrição Municipal',$safe($d['customer']['municipal_registration'])],['Telefone',$safe($d['customer']['phone'])],['Município / UF · Cód. IBGE / CEP',$cityZip($d['customer'])]],[$pageW*.22,$pageW*.18,$pageW*.18,$pageW*.42]);
    $row([['Nome / Nome Empresarial',$safe($d['customer']['name']),true],['E-mail',$safe($d['customer']['email'])]],[$pageW*.55,$pageW*.45]);
    $row([['Endereço',nfse_document_address_line($d['customer']),true]],[$pageW]);

    $ensure(35);$section('Serviço Prestado');
    $row([['Código Tributação Nacional',$safe($d['service']['national_code'])],['Código Tributação Municipal',$safe($d['service']['municipal_code'])],['Código NBS',$safe($d['service']['nbs'])],['Local da Prestação',$safe($d['city_service'])]],[$pageW*.24,$pageW*.24,$pageW*.24,$pageW*.28]);
    $row([['Descrição do Serviço',$safe($d['service']['description'])]],[$pageW]);

    $ensure(27);$section('Tributação Municipal (ISSQN)');
    $row([['Tipo de Tributação',$issType],['Município de Incidência',$safe($d['city_service'])],['Regime Especial',$regEsp],['Retenção do ISSQN',$issRet]],[$pageW/4,$pageW/4,$pageW/4,$pageW/4]);
    $row([['BC ISSQN',nfse_document_human_money($d['values']['base']),true],['Alíquota Aplicada',$pct($iss['rate']??$d['values']['iss_rate']),true],['ISSQN Apurado',nfse_document_human_money($d['values']['iss']),true]],[$pageW/3,$pageW/3,$pageW/3]);

    $ensure(27);$section('Tributação IBS / CBS');
    $row([['CST / cClassTrib',$safe(trim((string)($ibs['cst']??'').(($ibs['class']??'')!==''?' / '.$ibs['class']:'')))],['Indicador Operação / IBGE / Município',$safe(trim((string)($ibs['operation_indicator']??'').' / '.(string)($ibs['incidence_code']??'').' / '.(string)($ibs['incidence_name']??''),' /'))],['Base de Cálculo',nfse_document_human_money($ibs['base']??0)]],[$pageW*.24,$pageW*.48,$pageW*.28]);
    $row([['Alíquota IBS UF / Mun.',$pct($ibs['ibs_uf_rate']??0).' / '.$pct($ibs['ibs_mun_rate']??0)],['Valor IBS UF / Mun.',nfse_document_human_money($ibs['ibs_uf']??0).' / '.nfse_document_human_money($ibs['ibs_mun']??0)],['Alíquota CBS',$pct($ibs['cbs_rate']??0)],['Valor CBS',nfse_document_human_money($d['values']['cbs'])]],[$pageW/4,$pageW/4,$pageW/4,$pageW/4]);

    $ensure(27);$section('Valor Total da NFS-e');$totalIbsCbs=(float)$d['values']['ibs']+(float)$d['values']['cbs'];
    $row([['Valor da Operação / Serviço',nfse_document_human_money($d['values']['service']),true],['Desconto Incondicionado',nfse_document_human_money($d['discounts']['unconditional']??0)],['Desconto Condicionado',nfse_document_human_money($d['discounts']['conditional']??0)],['Total IBS/CBS',nfse_document_human_money($totalIbsCbs)]],[$pageW/4,$pageW/4,$pageW/4,$pageW/4]);
    $row([['VALOR LÍQUIDO DA NFS-e',nfse_document_human_money($d['values']['liquid']),true]],[$pageW]);

    $ensure(28);$section('Informações Complementares');$info=$safe($d['additional_info']??'','Sem informações complementares.');$infoH=max(14.0,$fieldHeight('Informações',$info,$pageW,false));$drawField($left,$y,$pageW,$infoH,'Informações',$info,false);$y+=$infoH;
    $row([['Data Cientificação',''],['Identificação e Assinatura',''],['Nº NFS-e / Chave NFS-e',$safe($d['nfse_number']).' / '.$safe($d['access_key']),true]],[$pageW*.25,$pageW*.25,$pageW*.50]);

    $content=$pdf->Output('', 'S');if(!is_string($content)||$content===''||!str_starts_with($content,'%PDF'))throw new RuntimeException('Não foi possível gerar o PDF da NFS-e.');
    $id=$d['access_key']!==''?$d['access_key']:($d['nfse_number']!==''?$d['nfse_number']:(string)($document['id']??date('YmdHis')));$filename='DANFSE-NFSE-'.preg_replace('/[^A-Za-z0-9._-]+/','-',$id).'.pdf';
    return ['content'=>$content,'filename'=>$filename,'mimetype'=>'application/pdf','size'=>strlen($content),'label'=>'NFS-e'];
  }
}
