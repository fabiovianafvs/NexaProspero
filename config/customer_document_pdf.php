<?php
declare(strict_types=1);

/**
 * Nexa Próspero V11.45.19.3 - PDFs compartilháveis alinhados aos impressos HTML.
 * Orçamento e Ordem de Serviço enviados por WhatsApp/E-mail seguem o mesmo
 * modelo selecionado na tela de impressão (conteúdo, hierarquia e formato).
 */

require_once __DIR__ . '/os_print_layout.php';
require_once __DIR__ . '/os_print_dual_pdf.php';

if (!function_exists('nexa_customer_pdf_bootstrap')) {
  function nexa_customer_pdf_bootstrap(): void {
    if (class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) return;
    $fpdf = __DIR__ . '/../vendor/nfephp-org/sped-da/src/Legacy/FPDF/Fpdf.php';
    if (is_file($fpdf)) require_once $fpdf;
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      $autoload = __DIR__ . '/../vendor/autoload.php';
      if (is_file($autoload)) require_once $autoload;
    }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      throw new RuntimeException('Biblioteca de PDF não disponível.');
    }
  }
}
if (!function_exists('nexa_customer_pdf_text')) {
  function nexa_customer_pdf_text(string $value): string {
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $out !== false ? $out : (preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value);
  }
}
if (!function_exists('nexa_customer_pdf_money')) {
  function nexa_customer_pdf_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
}
if (!function_exists('nexa_customer_pdf_qty')) {
  function nexa_customer_pdf_qty($value): string { $v=number_format((float)$value,4,',','.'); return rtrim(rtrim($v,'0'),','); }
}
if (!function_exists('nexa_customer_pdf_date')) {
  function nexa_customer_pdf_date($value, bool $time=false): string {
    $raw=trim((string)$value); if($raw==='') return '';
    $ts=strtotime($raw); return $ts ? date($time?'d/m/Y H:i':'d/m/Y',$ts) : $raw;
  }
}
if (!function_exists('nexa_customer_pdf_company')) {
  function nexa_customer_pdf_company(PDO $pdo, int $companyId): array {
    $st=$pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
    $st->execute([$companyId]); return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}
if (!function_exists('nexa_customer_pdf_person_contact')) {
  function nexa_customer_pdf_person_contact(PDO $pdo,int $companyId,int $personId): array {
    if($personId<=0) return [];
    try{
      $st=$pdo->prepare('SELECT id,name,document,email,phone,whatsapp,zip_code,address,number,complement,district,city,state FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId,$personId]); return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }catch(Throwable $e){ return []; }
  }
}
if (!function_exists('nexa_quote_document_load')) {
  function nexa_quote_document_load(PDO $pdo,int $companyId,int $quoteId): array {
    $st=$pdo->prepare('SELECT * FROM orcamentos WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$quoteId]);$q=$st->fetch(PDO::FETCH_ASSOC);
    if(!$q) throw new RuntimeException('Orçamento não encontrado.');
    $st=$pdo->prepare('SELECT * FROM orcamento_items WHERE company_id=? AND quote_id=? ORDER BY id');$st->execute([$companyId,$quoteId]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $st=$pdo->prepare('SELECT * FROM orcamento_payments WHERE company_id=? AND quote_id=? ORDER BY due_date,id');$st->execute([$companyId,$quoteId]);$payments=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    return ['quote'=>$q,'items'=>$items,'payments'=>$payments,'company'=>nexa_customer_pdf_company($pdo,$companyId),'person'=>nexa_customer_pdf_person_contact($pdo,$companyId,(int)($q['person_id']??0))];
  }
}
if (!function_exists('nexa_os_document_load')) {
  function nexa_os_document_load(PDO $pdo,int $companyId,int $orderId): array {
    $st=$pdo->prepare('SELECT o.*,s.name status_name FROM os_orders o LEFT JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id WHERE o.company_id=? AND o.id=? LIMIT 1');$st->execute([$companyId,$orderId]);$o=$st->fetch(PDO::FETCH_ASSOC);
    if(!$o) throw new RuntimeException('Ordem de Serviço não encontrada.');
    $st=$pdo->prepare('SELECT * FROM os_order_items WHERE company_id=? AND order_id=? ORDER BY item_type,id');$st->execute([$companyId,$orderId]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$items=os_print_enrich_items($pdo,$companyId,$items);
    $st=$pdo->prepare('SELECT * FROM os_order_payments WHERE company_id=? AND order_id=? ORDER BY id');$st->execute([$companyId,$orderId]);$payments=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $fields=[];$optionLabels=[];
    try{$fieldExtra=(os_print_has_column($pdo,'os_custom_fields','field_key')?',f.field_key':",'' AS field_key").(os_print_has_column($pdo,'os_custom_fields','semantic_role')?',f.semantic_role':",'' AS semantic_role");$st=$pdo->prepare('SELECT f.id,f.label,f.input_type'.$fieldExtra.',v.value_text FROM os_custom_fields f LEFT JOIN os_order_field_values v ON v.company_id=f.company_id AND v.field_id=f.id AND v.order_id=? WHERE f.company_id=? AND f.active=1 AND f.show_on_print=1 ORDER BY f.sort_order,f.id');$st->execute([$orderId,$companyId]);$fields=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
    try{$st=$pdo->prepare('SELECT field_id,option_value,label FROM os_custom_field_options WHERE company_id=?');$st->execute([$companyId]);foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $op)$optionLabels[(int)$op['field_id'].'|'.(string)$op['option_value']]=(string)$op['label'];}catch(Throwable $e){}
    $settings=[];try{$st=$pdo->prepare('SELECT * FROM os_settings WHERE company_id=? LIMIT 1');$st->execute([$companyId]);$settings=$st->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
    $damages=[];try{$st=$pdo->prepare('SELECT * FROM os_vehicle_damage_points WHERE company_id=? AND order_id=? ORDER BY sort_order,id');$st->execute([$companyId,$orderId]);$damages=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
    $openingUser='';if((int)($o['created_by']??0)>0){try{$st=$pdo->prepare('SELECT name FROM users WHERE id=? AND company_id=? LIMIT 1');$st->execute([(int)$o['created_by'],$companyId]);$openingUser=trim((string)($st->fetchColumn()?:''));}catch(Throwable $e){}}
    return ['order'=>$o,'items'=>$items,'payments'=>$payments,'fields'=>$fields,'option_labels'=>$optionLabels,'settings'=>$settings,'damages'=>$damages,'company'=>nexa_customer_pdf_company($pdo,$companyId),'person'=>nexa_customer_pdf_person_contact($pdo,$companyId,(int)($o['person_id']??0)),'opening_user'=>$openingUser,'dual_layout'=>os_print_dual_layout_load($pdo,$companyId)];
  }
}

if (!function_exists('nexa_customer_pdf_company_doc')) {
  function nexa_customer_pdf_company_doc(array $company): string {
    $cnpj=trim((string)($company['cnpj']??'')); if($cnpj!=='') return $cnpj;
    return trim((string)($company['cpf']??''));
  }
}
if (!function_exists('nexa_customer_pdf_company_address')) {
  function nexa_customer_pdf_company_address(array $company): string {
    $city=trim((string)($company['cidade']??''));$state=trim((string)($company['estado']??''));
    if($city!==''&&$state!=='')$city.='/'.$state;
    $parts=array_filter([
      trim((string)($company['endereco']??'')),trim((string)($company['numero']??'')),trim((string)($company['bairro']??'')),$city
    ],static fn($v)=>$v!=='');
    return implode(' - ',array_values($parts));
  }
}
if (!function_exists('nexa_customer_pdf_person_address')) {
  function nexa_customer_pdf_person_address(array $person): string {
    $address=trim((string)($person['address']??''));$number=trim((string)($person['number']??''));
    if($number!=='')$address.=($address!==''?', ':'').$number;
    $city=trim((string)($person['city']??''));$state=trim((string)($person['state']??''));if($city!==''&&$state!=='')$city.='/'.$state;
    $parts=array_filter([trim((string)($person['complement']??'')),trim((string)($person['district']??'')),$city,trim((string)($person['zip_code']??''))],static fn($v)=>$v!=='');
    if($parts)$address.=($address!==''?' - ':'').implode(' - ',array_values($parts));
    return trim($address);
  }
}
if (!function_exists('nexa_customer_pdf_quote_delivery_address')) {
  function nexa_customer_pdf_quote_delivery_address(array $q): string {
    $address=trim((string)($q['delivery_address']??''));$number=trim((string)($q['delivery_number']??''));if($number!=='')$address.=($address!==''?', ':'').$number;
    $city=trim((string)($q['delivery_city']??''));$state=trim((string)($q['delivery_state']??''));if($city!==''&&$state!=='')$city.='/'.$state;
    $parts=array_filter([trim((string)($q['delivery_complement']??'')),trim((string)($q['delivery_district']??'')),$city,trim((string)($q['delivery_zip_code']??''))],static fn($v)=>$v!=='');
    if($parts)$address.=($address!==''?' - ':'').implode(' - ',array_values($parts));
    return trim($address);
  }
}
if (!function_exists('nexa_customer_pdf_logo_path')) {
  function nexa_customer_pdf_logo_path(int $companyId): string {
    if($companyId<=0)return '';
    try{
      if(!function_exists('load_business_config')){
        $f=__DIR__.'/load_config.php'; if(is_file($f))require_once $f;
      }
      if(function_exists('load_business_config')){
        $biz=(array)load_business_config($companyId);$logo=(array)($biz['logo']??[]);
        if(array_key_exists('enabled',$logo)&&empty($logo['enabled']))return '';
        $file=basename(trim((string)($logo['file']??'')));
        if($file!==''){
          $path=__DIR__.'/../uploads/logos/'.$file;$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
          if(is_file($path)&&in_array($ext,['png','jpg','jpeg'],true))return $path;
        }
      }
    }catch(Throwable $e){}
    return '';
  }
}
if (!function_exists('nexa_customer_pdf_local_image_path')) {
  function nexa_customer_pdf_local_image_path(string $value): string {
    $value=trim($value);if($value==='')return '';
    if(is_file($value))return $value;
    $parsed=parse_url($value,PHP_URL_PATH);$path=is_string($parsed)&&$parsed!==''?$parsed:$value;
    $path=ltrim($path,'/');
    foreach([__DIR__.'/../'.$path,__DIR__.'/../admin/'.$path] as $candidate){if(is_file($candidate))return $candidate;}
    return '';
  }
}
if (!function_exists('nexa_customer_pdf_wrap_count')) {
  function nexa_customer_pdf_wrap_count($pdf,string $text,float $width,float $fontSize,string $style=''): int {
    $text=str_replace(["\r\n","\r"],"\n",trim($text));if($text==='')return 0;
    $pdf->SetFont('Arial',$style,$fontSize);$count=0;
    foreach(explode("\n",$text) as $paragraph){
      if(trim($paragraph)===''){$count++;continue;}$line='';
      foreach(preg_split('/\s+/u',trim($paragraph))?:[] as $word){
        $candidate=$line===''?$word:$line.' '.$word;
        if($pdf->GetStringWidth(nexa_customer_pdf_text($candidate))<=$width)$line=$candidate;
        else{if($line!=='')$count++;$line=$word;}
      }
      if($line!=='')$count++;
    }
    return max(1,$count);
  }
}
if (!function_exists('nexa_customer_pdf_new')) {
  function nexa_customer_pdf_new(string $model,float $estimated72=420.0): array {
    nexa_customer_pdf_bootstrap();$model=strtolower($model);
    if($model==='72mm'){$size=[72,max(180,min(3000,$estimated72))];$margin=2.5;$font=7.2;$contentWidth=67.0;$auto=false;}
    elseif($model==='a4_half'){$size='A5';$margin=8.0;$font=8.5;$contentWidth=132.0;$auto=true;}
    else{$size='A4';$margin=11.0;$font=9.0;$contentWidth=188.0;$auto=true;}
    $pdf=new \NFePHP\DA\Legacy\FPDF\Fpdf('P','mm',$size);$pdf->SetMargins($margin,$margin,$margin);$pdf->SetAutoPageBreak($auto,$margin+3);$pdf->AddPage();$pdf->SetTextColor(17,24,39);
    return [$pdf,$font,$contentWidth,$margin];
  }
}
if (!function_exists('nexa_customer_pdf_header_modern')) {
  function nexa_customer_pdf_header_modern($pdf,array $company,string $title,string $date,string $status,float $font,float $contentWidth,float $margin,string $model): void {
    $blue=[11,31,91];$text=[17,24,39];$muted=[100,116,139];$line=[203,213,225];$tx=static fn($v)=>nexa_customer_pdf_text((string)$v);
    $companyId=(int)($company['id']??0);$logo=nexa_customer_pdf_logo_path($companyId);$top=$pdf->GetY();
    $rightW=$model==='72mm'?30.0:min(72.0,$contentWidth*.40);$leftW=$contentWidth-$rightW-3.0;$companyX=$margin;
    $logoW=$model==='72mm'?14.0:24.0;$logoH=$model==='72mm'?10.0:18.0;
    if($logo!==''){try{$pdf->Image($logo,$margin,$top,$logoW,$logoH);$companyX+=$logoW+2.0;$leftW-=$logoW+2.0;}catch(Throwable $e){}}
    $name=trim((string)($company['name']??''))?:'Nexa Próspero';$doc=nexa_customer_pdf_company_doc($company);$addr=nexa_customer_pdf_company_address($company);$phone=trim((string)($company['whatsapp']??''));
    $pdf->SetXY($companyX,$top);$pdf->SetTextColor(...$blue);$pdf->SetFont('Arial','B',$model==='72mm'?10:$font+5);$pdf->MultiCell($leftW,$model==='72mm'?3.8:5.0,$tx($name),0,'L');
    $pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$model==='72mm'?6.4:$font-.5);
    if($doc!==''){$pdf->SetX($companyX);$pdf->MultiCell($leftW,$model==='72mm'?2.8:3.8,$tx($doc),0,'L');}
    $pdf->SetTextColor(...$muted);
    if($addr!==''){$pdf->SetX($companyX);$pdf->MultiCell($leftW,$model==='72mm'?2.8:3.8,$tx($addr),0,'L');}
    if($phone!==''){$pdf->SetX($companyX);$pdf->MultiCell($leftW,$model==='72mm'?2.8:3.8,$tx($phone),0,'L');}
    $leftBottom=max($top+$logoH,$pdf->GetY());
    $rightX=$margin+$contentWidth-$rightW;$pdf->SetXY($rightX,$top);$pdf->SetTextColor(...$blue);$pdf->SetFont('Arial','B',$model==='72mm'?13:$font+10);$pdf->MultiCell($rightW,$model==='72mm'?5.2:7.0,$tx($title),0,'R');
    $pdf->SetX($rightX);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);if($date!=='')$pdf->Cell($rightW,$model==='72mm'?3.5:4.5,$tx($date),0,1,'R');
    if($status!==''){$statusW=min($rightW,max($model==='72mm'?13.0:20.0,$pdf->GetStringWidth($tx($status))+6));$pdf->SetX($rightX+$rightW-$statusW);$pdf->SetDrawColor(...$line);$pdf->SetFont('Arial','B',$model==='72mm'?6.8:$font-.2);$pdf->Cell($statusW,$model==='72mm'?4:5,$tx($status),1,1,'C');}
    $bottom=max($leftBottom,$pdf->GetY())+1.5;$pdf->SetDrawColor(...$blue);$pdf->SetLineWidth(.55);$pdf->Line($margin,$bottom,$margin+$contentWidth,$bottom);$pdf->SetLineWidth(.2);$pdf->SetY($bottom+2);
  }
}
if (!function_exists('nexa_customer_pdf_section_title')) {
  function nexa_customer_pdf_section_title($pdf,string $title,float $font,float $margin,float $contentWidth,string $model): void {
    $blue=[11,31,91];$line=[203,213,225];$pdf->SetTextColor(...$blue);$pdf->SetFont('Arial','B',$model==='72mm'?8:$font);$pdf->Cell($contentWidth,$model==='72mm'?4:5,nexa_customer_pdf_text(strtoupper($title)),0,1,'L');$y=$pdf->GetY();$pdf->SetDrawColor(...$line);$pdf->Line($margin,$y,$margin+$contentWidth,$y);$pdf->Ln($model==='72mm'?1.0:1.3);
  }
}
if (!function_exists('nexa_customer_pdf_fields_grid')) {
  function nexa_customer_pdf_fields_grid($pdf,array $fields,float $font,float $margin,float $contentWidth,string $model): void {
    $text=[17,24,39];$label=[82,97,115];$clean=[];
    foreach($fields as $f){$lab=trim((string)($f[0]??''));$val=trim((string)($f[1]??''));if($val!=='')$clean[]=[$lab,$val];}
    $lineH=$model==='72mm'?3.2:4.0;
    $draw=static function($pdf,float $x,float $y,float $w,string $lab,string $val) use($model,$font,$lineH,$text,$label): float {
      $pdf->SetXY($x,$y);$pdf->SetTextColor(...$label);$pdf->SetFont('Arial','B',$model==='72mm'?6.5:$font-.8);$pdf->Cell($w,3,nexa_customer_pdf_text($lab),0,1,'L');
      $pdf->SetX($x);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);$pdf->MultiCell($w,$lineH,nexa_customer_pdf_text($val),0,'L');return $pdf->GetY();
    };
    if($model==='72mm'){
      foreach($clean as $idx=>$f){if($idx>0)$pdf->Ln(1.0);$draw($pdf,$margin,$pdf->GetY(),$contentWidth,$f[0],$f[1]);}
      return;
    }
    $gap=6.0;$colW=($contentWidth-$gap)/2;
    for($i=0;$i<count($clean);$i+=2){$y=$pdf->GetY();$bottom1=$draw($pdf,$margin,$y,$colW,$clean[$i][0],$clean[$i][1]);$bottom2=$y;if(isset($clean[$i+1]))$bottom2=$draw($pdf,$margin+$colW+$gap,$y,$colW,$clean[$i+1][0],$clean[$i+1][1]);$pdf->SetY(max($bottom1,$bottom2)+1.8);}
  }
}
if (!function_exists('nexa_customer_pdf_item_table')) {
  function nexa_customer_pdf_item_table($pdf,array $items,string $model,float $font,float $margin,float $contentWidth,bool $showCode=true,bool $showUnit=true): void {
    $text=[17,24,39];$muted=[100,116,139];$line=[219,228,238];$fill=[248,250,252];$tx=static fn($v)=>nexa_customer_pdf_text((string)$v);
    if($model==='72mm'){$codeW=0;$qtyW=10;$totalW=19;$descW=$contentWidth-$qtyW-$totalW;$showCode=false;$showUnit=false;$fs=6.9;$lh=3.1;}
    else{$codeW=$showCode?20:0;$qtyW=22;$totalW=29;$descW=$contentWidth-$codeW-$qtyW-$totalW;$fs=$font-.4;$lh=3.8;}
    $headerH=$model==='72mm'?4.0:5.0;$pdf->SetFillColor(...$fill);$pdf->SetTextColor(71,85,105);$pdf->SetFont('Arial','B',$model==='72mm'?6.3:$font-.8);
    if($showCode)$pdf->Cell($codeW,$headerH,$tx('CÓDIGO'),0,0,'L',true);$pdf->Cell($descW,$headerH,$tx('DESCRIÇÃO'),0,0,'L',true);$pdf->Cell($qtyW,$headerH,$tx('QTD.'),0,0,'R',true);$pdf->Cell($totalW,$headerH,$tx('TOTAL'),0,1,'R',true);
    foreach($items as $it){
      $code=trim((string)($it['product_code_snapshot']??''));if($code==='')$code=(string)($it['produto_id']??$it['product_id']??'');$desc=trim((string)($it['description_snapshot']??''));$notes=trim((string)($it['notes']??''));$unit=trim((string)($it['unidade_snapshot']??'UN'));$unitLine=$showUnit?nexa_customer_pdf_money($it['unit_price']??0).' / '.($unit!==''?$unit:'UN'):'';
      $lines=nexa_customer_pdf_wrap_count($pdf,$desc,$descW-2,$fs,'B');if($notes!=='')$lines+=nexa_customer_pdf_wrap_count($pdf,$notes,$descW-2,$fs-.8,'');if($unitLine!=='')$lines++;
      $rowH=max($model==='72mm'?5.2:6.5,$lines*$lh+1.2);if($model!=='72mm'&&$pdf->GetY()+$rowH>($pdf->h-15)){$pdf->AddPage();}
      $x=$margin;$y=$pdf->GetY();$pdf->SetDrawColor(...$line);$pdf->Line($margin,$y+$rowH,$margin+$contentWidth,$y+$rowH);
      if($showCode){$pdf->SetXY($x,$y+1);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$fs);$pdf->MultiCell($codeW,$lh,$tx($code),0,'L');$x+=$codeW;}
      $pdf->SetXY($x,$y+1);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','B',$fs);$pdf->MultiCell($descW-1,$lh,$tx($desc),0,'L');$dy=$pdf->GetY();
      if($notes!==''){$pdf->SetX($x);$pdf->SetTextColor(...$muted);$pdf->SetFont('Arial','',$fs-.8);$pdf->MultiCell($descW-1,$lh-0.2,$tx($notes),0,'L');$dy=$pdf->GetY();}
      if($unitLine!==''){$pdf->SetX($x);$pdf->SetTextColor(...$muted);$pdf->SetFont('Arial','',$fs-.8);$pdf->Cell($descW-1,$lh,$tx($unitLine),0,1,'L');}
      $xQty=$margin+($showCode?$codeW:0)+$descW;$pdf->SetXY($xQty,$y+1);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$fs);$pdf->Cell($qtyW,$lh,$tx(nexa_customer_pdf_qty($it['quantity']??0)),0,0,'R');$pdf->SetXY($xQty+$qtyW,$y+1);$pdf->SetFont('Arial','B',$fs);$pdf->Cell($totalW,$lh,$tx(nexa_customer_pdf_money($it['total']??0)),0,0,'R');$pdf->SetY($y+$rowH);
    }
  }
}
if (!function_exists('nexa_customer_pdf_totals')) {
  function nexa_customer_pdf_totals($pdf,array $rows,string $model,float $font,float $margin,float $contentWidth): void {
    $blue=[11,31,91];$text=[17,24,39];$width=$model==='72mm'?$contentWidth:min(78.0,$contentWidth);$x=$margin+$contentWidth-$width;$pdf->Ln($model==='72mm'?1:2);$pdf->SetX($x);
    foreach($rows as $idx=>$row){$isGrand=!empty($row[2]);if($isGrand){$y=$pdf->GetY();$pdf->SetDrawColor(...$blue);$pdf->SetLineWidth(.5);$pdf->Line($x,$y,$x+$width,$y);$pdf->SetLineWidth(.2);$pdf->Ln($model==='72mm'?1:1.5);}$pdf->SetX($x);$pdf->SetTextColor(...($isGrand?$blue:$text));$pdf->SetFont('Arial',$isGrand?'B':'',$isGrand?($model==='72mm'?10.5:$font+3):($model==='72mm'?7.4:$font));$h=$isGrand?($model==='72mm'?5:6):($model==='72mm'?3.5:4.5);$pdf->Cell($width*.57,$h,nexa_customer_pdf_text((string)$row[0]),0,0,'L');$pdf->Cell($width*.43,$h,nexa_customer_pdf_text((string)$row[1]),0,1,'R');}
  }
}
if (!function_exists('nexa_customer_pdf_footer')) {
  function nexa_customer_pdf_footer($pdf,string $text,string $model,float $font,float $margin,float $contentWidth): void {
    $pdf->Ln($model==='72mm'?3:6);$y=$pdf->GetY();$pdf->SetDrawColor(148,163,184);$pdf->Line($margin,$y,$margin+$contentWidth,$y);$pdf->Ln($model==='72mm'?1.8:2.5);$pdf->SetTextColor(100,116,139);$pdf->SetFont('Arial','',$model==='72mm'?6.3:$font-.8);$pdf->MultiCell($contentWidth,$model==='72mm'?2.8:3.7,nexa_customer_pdf_text($text),0,'C');
  }
}

if (!function_exists('nexa_quote_document_pdf_build')) {
  function nexa_quote_document_pdf_build(array $data,string $model='a4'): array {
    if(strtolower($model)==='proposta'&&isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO){
      // Modelo Proposta/Orçamento (A4 com quadros).
      require_once __DIR__.'/orcamento_proposta.php';
      return orc_proposta_pdf_build($GLOBALS['pdo'],(int)(($data['company']['id']??0)?:($data['quote']['company_id']??0)),$data);
    }
    $q=(array)$data['quote'];$items=(array)$data['items'];$payments=(array)$data['payments'];$company=(array)$data['company'];$person=(array)$data['person'];
    $model=in_array(strtolower($model),['72mm','a4'],true)?strtolower($model):'a4';
    $estimated=210+count($items)*12+count($payments)*7+(trim((string)($q['notes']??''))!==''?40:0);[$pdf,$font,$cw,$margin]=nexa_customer_pdf_new($model,$estimated);
    $number=str_pad((string)(int)($q['quote_number']??0),6,'0',STR_PAD_LEFT);$status=function_exists('orc_status_label')?orc_status_label((string)($q['status']??'')):strtoupper((string)($q['status']??''));
    nexa_customer_pdf_header_modern($pdf,$company,'ORÇAMENTO #'.$number,nexa_customer_pdf_date($q['quote_date']??''),$status,$font,$cw,$margin,$model);

    nexa_customer_pdf_section_title($pdf,'Cliente',$font,$margin,$cw,$model);
    $customerName=trim((string)($q['person_name_snapshot']??''))?:'Cliente não informado';$customerPhone=trim((string)($q['person_phone_snapshot']??''));if($customerPhone==='')$customerPhone=trim((string)($person['whatsapp']??$person['phone']??''));
    nexa_customer_pdf_fields_grid($pdf,[['Nome / Razão social',$customerName],['CPF/CNPJ',(string)($q['person_document_snapshot']??'')],['Telefone / WhatsApp',$customerPhone],['Endereço',nexa_customer_pdf_person_address($person)]],$font,$margin,$cw,$model);

    if(trim((string)($q['seller_name_snapshot']??''))!==''||trim((string)($q['professional_name_snapshot']??''))!==''){
      $pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,'Atendimento',$font,$margin,$cw,$model);nexa_customer_pdf_fields_grid($pdf,[['Vendedor',(string)($q['seller_name_snapshot']??'')],['Profissional',(string)($q['professional_name_snapshot']??'')]],$font,$margin,$cw,$model);
    }

    $pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,'Produtos / Serviços',$font,$margin,$cw,$model);nexa_customer_pdf_item_table($pdf,$items,$model,$font,$margin,$cw,true,true);
    $rows=[['Produtos',nexa_customer_pdf_money($q['total_products']??0),false],['Serviços',nexa_customer_pdf_money($q['total_services']??0),false]];
    if((float)($q['discount_amount']??0)>0)$rows[]=['Desconto'.((float)($q['discount_percent']??0)>0?' ('.number_format((float)$q['discount_percent'],2,',','.').'%)':''),'- '.nexa_customer_pdf_money($q['discount_amount']),false];
    if((float)($q['addition_amount']??0)>0)$rows[]=['Acréscimo'.((float)($q['addition_percent']??0)>0?' ('.number_format((float)$q['addition_percent'],2,',','.').'%)':''),'+ '.nexa_customer_pdf_money($q['addition_amount']),false];
    if((float)($q['freight_amount']??0)>0)$rows[]=['Frete','+ '.nexa_customer_pdf_money($q['freight_amount']),false];$rows[]=['Total',nexa_customer_pdf_money($q['total_general']??0),true];nexa_customer_pdf_totals($pdf,$rows,$model,$font,$margin,$cw);

    if($payments){$pdf->Ln($model==='72mm'?2:3);nexa_customer_pdf_section_title($pdf,'Condição de pagamento',$font,$margin,$cw,$model);foreach($payments as $p){$left=trim((string)($p['payment_label']??''));if(!empty($p['due_date']))$left.=' · '.nexa_customer_pdf_date($p['due_date']);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);$pdf->Cell($cw*.68,$model==='72mm'?3.8:4.5,nexa_customer_pdf_text($left),0,0,'L');$pdf->SetFont('Arial','B',$model==='72mm'?7:$font);$pdf->Cell($cw*.32,$model==='72mm'?3.8:4.5,nexa_customer_pdf_text(nexa_customer_pdf_money($p['amount']??0)),0,1,'R');}}

    $deliveryAddress=nexa_customer_pdf_quote_delivery_address($q);if($deliveryAddress!==''||!empty($q['delivery_date'])||trim((string)($q['carrier_name_snapshot']??''))!==''||trim((string)($q['delivery_reference']??''))!==''){
      $pdf->Ln($model==='72mm'?2:3);nexa_customer_pdf_section_title($pdf,'Entrega',$font,$margin,$cw,$model);$dt='';if(!empty($q['delivery_date'])){$dt=nexa_customer_pdf_date($q['delivery_date']);if(trim((string)($q['delivery_time']??''))!=='')$dt.=' '.substr((string)$q['delivery_time'],0,5);}nexa_customer_pdf_fields_grid($pdf,[['Data / hora',$dt],['Transportadora',(string)($q['carrier_name_snapshot']??'')],['Endereço',$deliveryAddress],['Referência',(string)($q['delivery_reference']??'')]],$font,$margin,$cw,$model);
    }
    if(trim((string)($q['notes']??''))!==''){$pdf->Ln($model==='72mm'?2:3);nexa_customer_pdf_section_title($pdf,'Observações',$font,$margin,$cw,$model);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);$pdf->MultiCell($cw,$model==='72mm'?3.2:4.2,nexa_customer_pdf_text((string)$q['notes']),0,'L');}
    nexa_customer_pdf_footer($pdf,'Orçamento emitido pelo Nexa Próspero. Valores e condições sujeitos à confirmação conforme negociação comercial.',$model,$font,$margin,$cw);
    $content=$pdf->Output('','S');return ['filename'=>'orcamento-'.$number.'.pdf','mimetype'=>'application/pdf','content'=>$content];
  }
}

if (!function_exists('nexa_customer_pdf_os_field_value')) {
  function nexa_customer_pdf_os_field_value(array $f,array $labels): string {
    $v=(string)($f['value_text']??'');$type=(string)($f['input_type']??'');if($type==='password')return '••••••';if($type==='checkbox')return $v==='1'?'Sim':'Não';$key=(int)($f['id']??0).'|'.$v;return isset($labels[$key])?(string)$labels[$key]:$v;
  }
}
if (!function_exists('nexa_customer_pdf_os_responsible_name')) {
  function nexa_customer_pdf_os_responsible_name(array $item): string {
    $seller=trim((string)($item['seller_name_snapshot']??''));if($seller!=='')return $seller;return trim((string)($item['technician_name_snapshot']??''));
  }
}
if (!function_exists('nexa_customer_pdf_os_damage_types')) {
  function nexa_customer_pdf_os_damage_types(): array {
    if(function_exists('os_vehicle_damage_types'))return (array)os_vehicle_damage_types();
    return ['AVARIA'=>'Avaria','RISCO'=>'Risco / arranhão','AMASSADO'=>'Amassado','QUEBRADO'=>'Quebrado / trincado','PINTURA'=>'Pintura','OUTRO'=>'Outro'];
  }
}
if (!function_exists('nexa_customer_pdf_os_items_table')) {
  function nexa_customer_pdf_os_items_table($pdf,array $items,string $model,float $font,float $margin,float $cw,string $content="completa"): void {
    $muted=[100,116,139];$text=[17,24,39];$fill=[248,250,252];$line=[221,221,221];$tx=static fn($v)=>nexa_customer_pdf_text((string)$v);
    $hidePrices=$content!=='completa';
    if($model==='72mm'){$qtyW=10;$totalW=$hidePrices?0:19;$descW=$cw-$qtyW-$totalW;$unitW=0;$fs=6.8;$lh=3.0;}else{$qtyW=18;$unitW=$hidePrices?0:28;$totalW=$hidePrices?0:30;$descW=$cw-$qtyW-$unitW-$totalW;$fs=$font-.4;$lh=3.7;}
    $h=$model==='72mm'?4:5;$pdf->SetFillColor(...$fill);$pdf->SetTextColor(71,85,105);$pdf->SetFont('Arial','B',$model==='72mm'?6.2:$font-.8);$pdf->Cell($descW,$h,$tx('DESCRIÇÃO'),0,0,'L',true);$pdf->Cell($qtyW,$h,$tx('QTD.'),0,0,'R',true);if($model!=='72mm'&&!$hidePrices)$pdf->Cell($unitW,$h,$tx('UNIT.'),0,0,'R',true);if(!$hidePrices)$pdf->Cell($totalW,$h,$tx('TOTAL'),0,1,'R',true);else $pdf->Ln($h);
    foreach($items as $it){$desc=trim((string)($it['description_snapshot']??''));$resp=nexa_customer_pdf_os_responsible_name($it);$notes=trim((string)($it['notes']??''));$lines=nexa_customer_pdf_wrap_count($pdf,$desc,$descW-2,$fs,'');if($resp!=='')$lines+=nexa_customer_pdf_wrap_count($pdf,'Vendedor/Técnico: '.$resp,$descW-2,$fs-.8,'');if($notes!=='')$lines+=nexa_customer_pdf_wrap_count($pdf,$notes,$descW-2,$fs-.8,'');$rowH=max($model==='72mm'?5.0:6.5,$lines*$lh+1.2);if($model!=='72mm'&&$pdf->GetY()+$rowH>$pdf->h-15)$pdf->AddPage();$y=$pdf->GetY();$pdf->SetDrawColor(...$line);$pdf->Line($margin,$y+$rowH,$margin+$cw,$y+$rowH);$pdf->SetXY($margin,$y+1);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$fs);$pdf->MultiCell($descW-1,$lh,$tx($desc),0,'L');if($resp!==''){$pdf->SetX($margin);$pdf->SetTextColor(...$muted);$pdf->SetFont('Arial','',$fs-.8);$pdf->MultiCell($descW-1,$lh-.2,$tx('Vendedor/Técnico: '.$resp),0,'L');}if($notes!==''){$pdf->SetX($margin);$pdf->SetTextColor(...$muted);$pdf->SetFont('Arial','',$fs-.8);$pdf->MultiCell($descW-1,$lh-.2,$tx($notes),0,'L');}$x=$margin+$descW;$pdf->SetXY($x,$y+1);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',$fs);$pdf->Cell($qtyW,$lh,$tx(number_format((float)($it['quantity']??0),2,',','.')),0,0,'R');$x+=$qtyW;if(!$hidePrices){if($model!=='72mm'){$pdf->SetXY($x,$y+1);$pdf->Cell($unitW,$lh,$tx(nexa_customer_pdf_money($it['unit_price']??0)),0,0,'R');$x+=$unitW;}$pdf->SetXY($x,$y+1);$pdf->SetFont('Arial','B',$fs);$pdf->Cell($totalW,$lh,$tx(nexa_customer_pdf_money($it['total']??0)),0,0,'R');}$pdf->SetY($y+$rowH);}
  }
}
if (!function_exists('nexa_customer_pdf_os_damage_section')) {
  function nexa_customer_pdf_os_damage_section($pdf,array $damages,array $settings,string $model,float $font,float $margin,float $cw): void {
    if(!$damages)return;$types=nexa_customer_pdf_os_damage_types();$map=function_exists('os_vehicle_damage_asset_file_path')?os_vehicle_damage_asset_file_path($settings):(__DIR__.'/../admin/assets/os_vehicle_damage_map.png');
    $ratio = 143/208;
    if (is_file($map)) {
      $info = @getimagesize($map);
      if (!empty($info[0]) && !empty($info[1])) $ratio = ((float)$info[1]) / max(1,(float)$info[0]);
    }
    if(is_file($map)){
      if($model==='72mm'){$mapW=min($cw,60.0);$mapH=$mapW*$ratio;$x=$margin+($cw-$mapW)/2;$y=$pdf->GetY();try{$pdf->Image($map,$x,$y,$mapW,$mapH);}catch(Throwable $e){}foreach($damages as $idx=>$d){$px=(float)($d['position_x']??0);$py=(float)($d['position_y']??0);if($px>=0&&$px<=100&&$py>=0&&$py<=100){$pdf->SetXY($x+$mapW*$px/100-2,$y+$mapH*$py/100-2);$pdf->SetFillColor(200,30,30);$pdf->SetTextColor(255,255,255);$pdf->SetFont('Arial','B',6);$pdf->Cell(4,4,(string)($idx+1),0,0,'C',true);}}$pdf->SetY($y+$mapH+2);
      }else{$mapW=min(58.0,$cw*.38);$mapH=$mapW*$ratio;$x=$margin;$y=$pdf->GetY();try{$pdf->Image($map,$x,$y,$mapW,$mapH);}catch(Throwable $e){}foreach($damages as $idx=>$d){$px=(float)($d['position_x']??0);$py=(float)($d['position_y']??0);if($px>=0&&$px<=100&&$py>=0&&$py<=100){$pdf->SetXY($x+$mapW*$px/100-2,$y+$mapH*$py/100-2);$pdf->SetFillColor(200,30,30);$pdf->SetTextColor(255,255,255);$pdf->SetFont('Arial','B',6);$pdf->Cell(4,4,(string)($idx+1),0,0,'C',true);}}$legendX=$x+$mapW+5;$legendW=$cw-$mapW-5;$pdf->SetXY($legendX,$y);foreach($damages as $idx=>$d){$label=(string)($types[(string)($d['damage_type']??'')]??($d['damage_type']??'Avaria'));$desc=trim((string)($d['description']??''));$line=($idx+1).'. '.$label.($desc!==''?' - '.$desc:'');$pdf->SetX($legendX);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$font-.8);$pdf->MultiCell($legendW,3.8,nexa_customer_pdf_text($line),0,'L');}$pdf->SetY(max($y+$mapH+2,$pdf->GetY()));}
    }
    if($model==='72mm'){foreach($damages as $idx=>$d){$label=(string)($types[(string)($d['damage_type']??'')]??($d['damage_type']??'Avaria'));$desc=trim((string)($d['description']??''));$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$font-.6);$pdf->MultiCell($cw,3.2,nexa_customer_pdf_text(($idx+1).'. '.$label.($desc!==''?' - '.$desc:'')),0,'L');}}
    $pdf->SetTextColor(100,116,139);$pdf->SetFont('Arial','',$model==='72mm'?6.0:$font-1.1);$pdf->MultiCell($cw,$model==='72mm'?2.8:3.4,nexa_customer_pdf_text('Fotos de avarias são evidências temporárias e podem ser removidas automaticamente 48 horas após a conclusão da O.S.'),0,'L');
  }
}
if (!function_exists('nexa_os_document_pdf_build')) {
  function nexa_os_document_pdf_build(array $data,string $model='a4_half',string $content='completa',string $paper='a4',string $profile='padrao'): array {
    $o=(array)$data['order'];$items=(array)$data['items'];$payments=(array)$data['payments'];$fields=(array)$data['fields'];$optionLabels=(array)($data['option_labels']??[]);$settings=(array)$data['settings'];$damages=(function_exists('os_vehicle_damage_enabled') && os_vehicle_damage_enabled($settings))?(array)$data['damages']:[];$company=(array)$data['company'];
    $content=in_array($content,['completa','sem_preco','sem_produtos'],true)?$content:'completa';
    if(strtolower($model)==='proposta'&&isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO){
      // Modelo Proposta/Orçamento (A4 com quadros), o mesmo do Orçamento.
      require_once __DIR__.'/orcamento_proposta.php';
      $cid=(int)(($company['id']??0)?:($o['company_id']??0));
      $d=os_proposta_data($GLOBALS['pdo'],$cid,$o,$items,$payments,$fields,$optionLabels,['hide_prices'=>$content!=='completa','blank_items'=>$content==='sem_produtos']);
      return orc_proposta_pdf_render($cid,$d);
    }
    $model=strtolower($model);if(!in_array($model,['72mm','a4_half','a4_full','a4_landscape_dual'],true))$model='a4_half';if($model==='a4_landscape_dual')return nexa_os_document_pdf_build_dual($data,$content,$paper,$profile);$estimated=260+count($items)*12+count($fields)*7+count($damages)*8+count($payments)*6;[$pdf,$font,$cw,$margin]=nexa_customer_pdf_new($model,$estimated);
    $number=str_pad((string)(int)($o['os_number']??0),6,'0',STR_PAD_LEFT);nexa_customer_pdf_header_modern($pdf,$company,'O.S. #'.$number,nexa_customer_pdf_date($o['opened_at']??'',true),trim((string)($o['status_name']??'')),$font,$cw,$margin,$model);

    nexa_customer_pdf_section_title($pdf,'Cliente e contato',$font,$margin,$cw,$model);nexa_customer_pdf_fields_grid($pdf,[['Cliente / cadastro',trim((string)($o['person_name_snapshot']??''))?:'Consumidor final'],['CPF/CNPJ',(string)($o['person_document_snapshot']??'')],['Contato',trim((string)($o['contact_name']??''))?:trim((string)($o['person_name_snapshot']??''))],['Telefone / WhatsApp',trim((string)($o['contact_phone']??''))?:trim((string)($o['person_phone_snapshot']??''))],['Previsão',!empty($o['expected_at'])?nexa_customer_pdf_date($o['expected_at'],true):'']],$font,$margin,$cw,$model);
    if(trim((string)($o['reported_problem']??''))!==''){$pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,'Solicitação',$font,$margin,$cw,$model);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);$pdf->MultiCell($cw,$model==='72mm'?3.2:4.2,nexa_customer_pdf_text((string)$o['reported_problem']),0,'L');}
    $fieldRows=[];foreach($fields as $f){$value=nexa_customer_pdf_os_field_value($f,$optionLabels);if(trim($value)!=='')$fieldRows[]=[(string)($f['label']??''),$value];}if($fieldRows){$pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,'Dados da O.S.',$font,$margin,$cw,$model);nexa_customer_pdf_fields_grid($pdf,$fieldRows,$font,$margin,$cw,$model);}
    if($damages){$pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,function_exists('os_vehicle_damage_title')?os_vehicle_damage_title($settings):'Mapa de avarias / inspeção',$font,$margin,$cw,$model);nexa_customer_pdf_os_damage_section($pdf,$damages,$settings,$model,$font,$margin,$cw);}

    $products=[];$services=[];foreach($items as $it){if((string)($it['item_type']??'PRODUTO')==='SERVICO')$services[]=$it;else$products[]=$it;}
    if($content==='sem_produtos'){$products=[];$services=[];}
    if($products){$pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,'Produtos',$font,$margin,$cw,$model);nexa_customer_pdf_os_items_table($pdf,$products,$model,$font,$margin,$cw,$content);}
    if($services){$pdf->Ln($model==='72mm'?1:2);nexa_customer_pdf_section_title($pdf,'Serviços',$font,$margin,$cw,$model);nexa_customer_pdf_os_items_table($pdf,$services,$model,$font,$margin,$cw,$content);}
    if($content==='sem_produtos'){
      // Papel para anotações: tabela física em branco, sem carregar nomes e valores dos itens.
      $pdf->Ln($model==='72mm'?1:2);
      nexa_customer_pdf_section_title($pdf,'Produtos / Serviços',$font,$margin,$cw,$model);
      $rowH=$model==='72mm'?7.2:10.0;
      for($i=0;$i<7;$i++){
        if($model!=='72mm' && $pdf->GetY()+$rowH>$pdf->h-25)$pdf->AddPage();
        $yy=$pdf->GetY();$pdf->SetDrawColor(135,145,156);
        $pdf->Rect($margin,$yy,$cw*.68,$rowH);
        $pdf->Rect($margin+$cw*.68,$yy,$cw*.16,$rowH);
        $pdf->Rect($margin+$cw*.84,$yy,$cw*.16,$rowH);
        $pdf->SetY($yy+$rowH);
      }
    }
    if($content==='completa'){
    $rows=[['Total produtos',nexa_customer_pdf_money($o['total_products']??0),false],['Total serviços',nexa_customer_pdf_money($o['total_services']??0),false]];if((float)($o['discount_amount']??0)>0)$rows[]=['Desconto','- '.nexa_customer_pdf_money($o['discount_amount']),false];if((float)($o['addition_amount']??0)>0)$rows[]=['Acréscimo',nexa_customer_pdf_money($o['addition_amount']),false];$rows[]=['Total geral',nexa_customer_pdf_money($o['total_general']??0),true];nexa_customer_pdf_totals($pdf,$rows,$model,$font,$margin,$cw);}
    if($payments){$pdf->Ln($model==='72mm'?2:3);nexa_customer_pdf_section_title($pdf,'Formas de pagamento previstas',$font,$margin,$cw,$model);foreach($payments as $p){$line=trim((string)($p['payment_label']??''));if($content==='completa')$line.=' — '.nexa_customer_pdf_money($p['amount']??0);if(!empty($p['due_date']))$line.=' — '.nexa_customer_pdf_date($p['due_date']);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);$pdf->MultiCell($cw,$model==='72mm'?3.2:4.2,nexa_customer_pdf_text($line),0,'L');}}
    foreach([['Diagnóstico','diagnosis'],['Observações','customer_notes']] as $sec){if(trim((string)($o[$sec[1]]??''))!==''){$pdf->Ln($model==='72mm'?2:3);nexa_customer_pdf_section_title($pdf,$sec[0],$font,$margin,$cw,$model);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','',$model==='72mm'?7:$font);$pdf->MultiCell($cw,$model==='72mm'?3.2:4.2,nexa_customer_pdf_text((string)$o[$sec[1]]),0,'L');}}
    if(trim((string)($settings['terms_text']??''))!==''){$pdf->Ln($model==='72mm'?2:3);$pdf->SetTextColor(17,24,39);$pdf->SetFont('Arial','I',$model==='72mm'?6.5:$font-.7);$pdf->MultiCell($cw,$model==='72mm'?3:3.8,nexa_customer_pdf_text((string)$settings['terms_text']),0,'L');}
    if(!empty($settings['print_customer_signature'])||!empty($settings['print_responsible_signature'])){
      $gap=$model==='72mm'?6:12;$w=($cw-$gap)/2;$y=$pdf->GetY()+($model==='72mm'?8:9);if($model!=='72mm'&&$y+7>$pdf->h-7){$pdf->AddPage();$y=$margin+10;}$pdf->SetDrawColor(51,51,51);$pdf->SetFont('Arial','',$model==='72mm'?6.5:$font-.5);
      if(!empty($settings['print_customer_signature'])){$x=$margin;$pdf->Line($x,$y,$x+$w,$y);$pdf->SetXY($x,$y+1);$pdf->Cell($w,4,nexa_customer_pdf_text('Cliente'),0,0,'C');}
      if(!empty($settings['print_responsible_signature'])){$x=$margin+$w+$gap;$pdf->Line($x,$y,$x+$w,$y);$pdf->SetXY($x,$y+1);$pdf->Cell($w,4,nexa_customer_pdf_text('Responsável'),0,0,'C');}
      $pdf->SetY($y+6);
    }
    $contentBytes=$pdf->Output('','S');return ['filename'=>'ordem-servico-'.$number.($content==='completa'?'':'-'.$content).'.pdf','mimetype'=>'application/pdf','content'=>$contentBytes];
  }
}
