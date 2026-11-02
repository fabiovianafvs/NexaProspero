<?php
declare(strict_types=1);

require_once __DIR__ . '/os_print_layout.php';

if (!function_exists('nexa_os_dual_pdf_fit')) {
  function nexa_os_dual_pdf_fit($pdf,string $text,float $width): string {
    $text=trim(preg_replace('/\s+/u',' ',$text)??$text);if($text==='')return '';
    $encoded=nexa_customer_pdf_text($text);if($pdf->GetStringWidth($encoded)<=$width)return $encoded;
    $suffix='...';$limit=max(1,function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text));
    while($limit>1){$limit--; $try=os_print_substr($text,0,$limit).$suffix;$enc=nexa_customer_pdf_text($try);if($pdf->GetStringWidth($enc)<=$width)return $enc;}
    return nexa_customer_pdf_text(os_print_substr($text,0,1));
  }
}

if (!function_exists('nexa_os_dual_pdf_text')) {
  function nexa_os_dual_pdf_text($pdf,float $x,float $y,float $w,float $h,string $text,float $font=5.4,string $style='',string $align='L'): void {
    if($w<=0||$h<=0)return;$pdf->SetXY($x+.5,$y+.15);$pdf->SetFont('Arial',$style,max(3.5,$font));$pdf->SetTextColor(0,0,0);$pdf->Cell(max(.1,$w-1),max(2.4,min($h-.3,3.3)),nexa_os_dual_pdf_fit($pdf,$text,max(.1,$w-1.2)),0,0,$align);
  }
}

if (!function_exists('nexa_os_dual_pdf_label_value')) {
  function nexa_os_dual_pdf_label_value($pdf,float $x,float $y,float $w,float $h,string $label,string $value,float $font=5.2,bool $bold=false): void {
    $labelH=min(2.3,$h*.38);$pdf->SetFont('Arial','',max(3.5,$font-.6));$pdf->SetXY($x+.5,$y+.1);$pdf->Cell(max(.1,$w-1),$labelH,nexa_os_dual_pdf_fit($pdf,$label,max(.1,$w-1.2)),0,0,'L');
    $pdf->SetFont('Arial',$bold?'B':'',max(3.5,$font));$pdf->SetXY($x+.5,$y+$labelH-.1);$pdf->Cell(max(.1,$w-1),max(2.2,$h-$labelH),nexa_os_dual_pdf_fit($pdf,$value,max(.1,$w-1.2)),0,0,'L');
  }
}

if (!function_exists('nexa_os_dual_pdf_rect')) {
  function nexa_os_dual_pdf_rect($pdf,float $x,float $y,float $w,float $h,bool $fill=false): void {$pdf->SetDrawColor(25,25,25);$pdf->SetLineWidth(.18);if($fill){$pdf->SetFillColor(220,220,220);$pdf->Rect($x,$y,$w,$h,'DF');}else{$pdf->Rect($x,$y,$w,$h);}}
}

if (!function_exists('nexa_os_dual_pdf_section_title')) {
  function nexa_os_dual_pdf_section_title($pdf,float $x,float $y,float $w,string $title,float $font): float {$h=4.0;$pdf->SetFillColor(215,215,215);$pdf->Rect($x,$y,$w,$h,'DF');$pdf->SetFont('Arial','B',max(3.8,$font));$pdf->SetXY($x+.6,$y+.15);$pdf->Cell($w-1.2,3.5,nexa_os_dual_pdf_fit($pdf,$title,$w-1.5),0,0,'L');return $h;}
}

if (!function_exists('nexa_os_dual_pdf_draw_copy')) {
  function nexa_os_dual_pdf_draw_copy($pdf,float $ox,float $oy,array $data,array $layout,string $content='completa',float $scale=1.0): void {
    $o=(array)$data['order'];$items=(array)$data['items'];$payments=(array)$data['payments'];$fields=(array)$data['fields'];$optionLabels=(array)($data['option_labels']??[]);$company=(array)$data['company'];$person=(array)$data['person'];$openingUser=trim((string)($data['opening_user']??''));$b=(array)$layout['blocks'];$txt=(array)$layout['texts'];
    $block=static fn(string $key)=>[(float)$b[$key]['x']*$scale+$ox,(float)$b[$key]['y']*$scale+$oy,(float)$b[$key]['w']*$scale,(float)$b[$key]['h']*$scale,(float)$b[$key]['font']*$scale,!empty($b[$key]['visible'])];
    $line=static function($pdf,float $x1,float $y1,float $x2,float $y2):void{$pdf->SetDrawColor(70,70,70);$pdf->SetLineWidth(.15);$pdf->Line($x1,$y1,$x2,$y2);};
    $logo=nexa_customer_pdf_logo_path((int)($company['id']??0));
    $number=str_pad((string)(int)($o['os_number']??0),10,'0',STR_PAD_LEFT);
    $openedDate='';$openedTime='';if(!empty($o['opened_at'])&&($ts=strtotime((string)$o['opened_at']))){$openedDate=date('d/m/Y',$ts);$openedTime=date('H:i:s',$ts);} $sched='';if(!empty($o['expected_at'])&&($ts=strtotime((string)$o['expected_at'])))$sched=date('d/m/Y',$ts).' às '.date('H:i:s',$ts);
    $customer=trim((string)($o['person_name_snapshot']??''))?:trim((string)($person['name']??''));$doc=trim((string)($o['person_document_snapshot']??''))?:trim((string)($person['document']??''));$phone=trim((string)($o['contact_phone']??''))?:trim((string)($person['whatsapp']??''));if($phone==='')$phone=trim((string)($person['phone']??''));
    $address=trim((string)($person['address']??''));if(trim((string)($person['number']??''))!=='')$address.=($address!==''?', ':'').trim((string)$person['number']);$district=trim((string)($person['district']??''));$zip=trim((string)($person['zip_code']??''));$email=trim((string)($person['email']??''));$city=trim((string)($person['city']??''));$state=trim((string)($person['state']??''));
    $reference=os_print_custom_value($fields,$optionLabels,['referencia_do_local','referencia_local','referencia local','local_referencia']);$responsible=os_print_custom_value($fields,$optionLabels,['responsavel_os','responsavel da os','responsavel ordem']);if($responsible==='')$responsible=os_print_responsible_name($items);
    $arrival=os_print_custom_value($fields,$optionLabels,['hora_chegada','hora de chegada','chegada']);$departure=os_print_custom_value($fields,$optionLabels,['hora_saida','hora de saida','saida']);$execution=os_print_custom_value($fields,$optionLabels,['data_execucao','data da execucao','execucao']);if($execution===''&&!empty($o['closed_at']))$execution=nexa_customer_pdf_date($o['closed_at']);

    // Logo
    [$x,$y,$w,$h,$fs,$vis]=$block('logo');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);if($logo!==''){try{$maxW=$w-3;$maxH=$h-3;$pdf->Image($logo,$x+1.5,$y+1.5,$maxW,$maxH);}catch(Throwable $e){}}}
    // Título
    [$x,$y,$w,$h,$fs,$vis]=$block('heading');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h,true);$pdf->SetFont('Arial','B',$fs);$pdf->SetXY($x+.8,$y+.2);$title=nexa_customer_pdf_text((string)$txt['document_title']);$pdf->Cell($w*.70,$h-.3,nexa_os_dual_pdf_fit($pdf,(string)$txt['document_title'],$w*.69),0,0,'L');$pdf->SetXY($x+$w*.70,$y+.2);$pdf->Cell($w*.29,$h-.3,nexa_os_dual_pdf_fit($pdf,$number,$w*.28),0,0,'R');}
    // Empresa
    [$x,$y,$w,$h,$fs,$vis]=$block('company');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$companyAddr=nexa_customer_pdf_company_address($company);$rows=[trim((string)($company['name']??'')),'Endereço: '.$companyAddr,'Telefone: '.trim((string)($company['whatsapp']??''))];$docCompany=nexa_customer_pdf_company_doc($company);if($docCompany!=='')$rows[]='CPF/CNPJ: '.$docCompany;$rh=$h/max(1,count($rows));foreach($rows as $i=>$row)nexa_os_dual_pdf_text($pdf,$x,$y+$i*$rh,$w,$rh,$row,$fs,$i===0?'B':'');}
    // Aviso
    [$x,$y,$w,$h,$fs,$vis]=$block('warning');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);nexa_os_dual_pdf_text($pdf,$x,$y,$w,$h,(string)$txt['warning'],$fs,'B','C');}
    // Cliente
    [$x,$y,$w,$h,$fs,$vis]=$block('customer');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$th=nexa_os_dual_pdf_section_title($pdf,$x,$y,$w,(string)$txt['customer_title'],$fs);$rh=($h-$th)/5;$yy=$y+$th;$splits=[[.70,.30],[.70,.30],[.38,.17,.45],[.55,.33,.12],[.58,.42]];$rows=[[['Cliente:',$customer,true],['Contato:',trim((string)($o['contact_name']??'')),false]],[['Endereço:',$address,true],['Telefone:',$phone,false]],[['Bairro:',$district,true],['CEP:',$zip,false],['CPF/CNPJ:',$doc,false]],[['Email:',$email,false],['Cidade:',$city,false],['UF:',$state,false]],[['Referência do Local:',$reference,false],['Responsável OS:',$responsible,false]]];foreach($rows as $ri=>$cells){$xx=$x;foreach($cells as $ci=>$cell){$cw=$w*$splits[$ri][$ci];if($ci>0)$line($pdf,$xx,$yy,$xx,$yy+$rh);nexa_os_dual_pdf_label_value($pdf,$xx,$yy,$cw,$rh,(string)$cell[0],(string)$cell[1],$fs,!empty($cell[2]));$xx+=$cw;}$yy+=$rh;if($ri<4)$line($pdf,$x,$yy,$x+$w,$yy);}}
    // OS
    [$x,$y,$w,$h,$fs,$vis]=$block('os');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$th=nexa_os_dual_pdf_section_title($pdf,$x,$y,$w,(string)$txt['os_title'],$fs);$rh=($h-$th)/2;$yy=$y+$th;$cols=[.42,.35,.23];$vals=[['Data/Hora da Abertura:',trim($openedDate.' '.$openedTime)],['Usuário Abertura:',$openingUser],['Status OS:',trim((string)($o['status_name']??''))]];$xx=$x;foreach($vals as $i=>$v){$cw=$w*$cols[$i];if($i>0)$line($pdf,$xx,$yy,$xx,$yy+$rh);nexa_os_dual_pdf_label_value($pdf,$xx,$yy,$cw,$rh,$v[0],$v[1],$fs,$i===2);$xx+=$cw;}$line($pdf,$x,$yy+$rh,$x+$w,$yy+$rh);$yy+=$rh;$cw=$w*.62;nexa_os_dual_pdf_label_value($pdf,$x,$yy,$cw,$rh,'Modalidade:','(   ) Executado     (   ) Não Autorizado',$fs,true);$line($pdf,$x+$cw,$yy,$x+$cw,$yy+$rh);nexa_os_dual_pdf_label_value($pdf,$x+$cw,$yy,$w-$cw,$rh,'Agendamento:',$sched,$fs,false);}
    // Ocorrência
    [$x,$y,$w,$h,$fs,$vis]=$block('occurrence');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$th=nexa_os_dual_pdf_section_title($pdf,$x,$y,$w,(string)$txt['occurrence_title'],$fs);$pdf->SetXY($x+1,$y+$th+1);$pdf->SetFont('Arial','B',$fs);$pdf->MultiCell($w-2,max(2.2,$fs*.52),nexa_customer_pdf_text((string)($o['reported_problem']??'')),0,'L');}
    // Execução
    [$x,$y,$w,$h,$fs,$vis]=$block('execution');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$cols=[.29,.29,.42];$vals=[['Hora de Chegada:',$arrival!==''?$arrival:'_____:_____'],['Hora de Saída:',$departure!==''?$departure:'_____:_____'],['Data da Execução:',$execution!==''?$execution:'____/____/_______']];$xx=$x;foreach($vals as $i=>$v){$cw=$w*$cols[$i];if($i>0)$line($pdf,$xx,$y,$xx,$y+$h);nexa_os_dual_pdf_label_value($pdf,$xx,$y,$cw,$h,$v[0],$v[1],$fs,true);$xx+=$cw;}}
    // Itens
    [$x,$y,$w,$h,$fs,$vis]=$block('items');if($vis){
      nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);
      $hidePrices=$content!=='completa';$blankItems=$content==='sem_produtos';
      $cols=$content==='sem_preco'?[.13,.76,.11]:[.13,.40,.11,.18,.18];
      $heads=$content==='sem_preco'?['Código','Produto / Serviço','Qtde']:['Código','Produto / Serviço','Qtde','Vlr Unit','Vlr Total'];
      $hh=4.0*$scale;$rowH=($blankItems?6.1:3.6)*$scale;
      $xx=$x;$pdf->SetFillColor(220,220,220);
      foreach($heads as $i=>$head){$cw=$w*$cols[$i];$pdf->Rect($xx,$y,$cw,$hh,'DF');$pdf->SetFont('Arial','B',$fs);$pdf->SetXY($xx+.3,$y+.1);$pdf->Cell(max(.1,$cw-.6),max(1.5,$hh-.2),nexa_os_dual_pdf_fit($pdf,$head,max(.1,$cw-.8)),0,0,$i>=2?'R':'L');$xx+=$cw;}
      $capacity=max(1,(int)floor(($h-$hh)/$rowH));
      $shown=$blankItems?array_fill(0,$capacity,[]):array_slice($items,0,$capacity);
      $more=$blankItems?0:max(0,count($items)-$capacity);
      if($more>0&&$capacity>0){$shown=array_slice($items,0,max(0,$capacity-1));$shown[]=['display_code'=>'','description_snapshot'=>'+ '.$more.' item(ns)','quantity'=>''];}
      if(!$blankItems){$minBlank=max(0,min((int)($layout['options']['blank_item_rows']??0),$capacity-count($shown)));$shown=array_merge($shown,array_fill(0,$minBlank,[]));}
      $yy=$y+$hh;
      foreach($shown as $it){$xx=$x;
        $vals=[(string)($it['display_code']??''),(string)($it['description_snapshot']??''),isset($it['quantity'])&&$it['quantity']!==''?nexa_customer_pdf_qty($it['quantity']):''];
        if(!$hidePrices){$vals[]=isset($it['unit_price'])&&$it['unit_price']!==''?nexa_customer_pdf_money($it['unit_price']):'';$vals[]=isset($it['total'])&&$it['total']!==''?nexa_customer_pdf_money($it['total']):'';}
        elseif($blankItems){$vals[]='';$vals[]='';}
        foreach($vals as $i=>$val){$cw=$w*$cols[$i];$pdf->Rect($xx,$yy,$cw,$rowH);if($val!==''){$pdf->SetFont('Arial',(!$hidePrices&&$i===4)?'B':'',$fs);$pdf->SetXY($xx+.3,$yy+.05);$pdf->Cell(max(.1,$cw-.6),max(1.5,$rowH-.1),nexa_os_dual_pdf_fit($pdf,$val,max(.1,$cw-.8)),0,0,$i>=2?'R':'L');}$xx+=$cw;}
        $yy+=$rowH;if($yy+$rowH>$y+$h+.05)break;
      }
    }
    // Pagamentos
    [$x,$y,$w,$h,$fs,$vis]=$block('payments');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$pdf->SetFont('Arial','B',$fs);$pdf->SetXY($x+.6,$y+.2);$pdf->Cell($w*.6,3,nexa_os_dual_pdf_fit($pdf,(string)$txt['payment_title'],$w*.58),0,0,'L');$pdf->SetXY($x+$w*.6,$y+.2);$pdf->Cell($w*.39,3,nexa_customer_pdf_text($content==='completa'?'Vlr Total: '.nexa_customer_pdf_money($o['total_general']??0):($content==='sem_produtos'?'Vlr Total: __________':'')),0,0,'R');$paymentOpts=[];foreach($payments as $p){$label=trim((string)($p['payment_label']??''));if($label==='')continue;if(!empty($p['due_date']))$label.=' - '.nexa_customer_pdf_date($p['due_date']);$paymentOpts[]=$label;if(count($paymentOpts)>=4)break;}if(!$paymentOpts)$paymentOpts=(array)($layout['options']['payment_options']??[]);$yy=$y+3.1;for($i=0;$i<min(4,count($paymentOpts));$i++){$col=$i%2;$row=intdiv($i,2);$cw=$w/2;$pdf->SetFont('Arial','B',$fs);$pdf->SetXY($x+$col*$cw+.6,$yy+$row*2.7);$pdf->Cell($cw-1.2,2.5,nexa_os_dual_pdf_fit($pdf,'[ ] '.(string)$paymentOpts[$i],$cw-1.5),0,0,'L');}$pdf->SetFont('Arial','',$fs-.3);$pdf->SetXY($x+.6,$y+$h-4.3);$pdf->MultiCell($w-1.2,2.0,nexa_customer_pdf_text((string)$txt['payment_note']),0,'L');}
    // Executado
    [$x,$y,$w,$h,$fs,$vis]=$block('executed_by');if($vis){nexa_os_dual_pdf_rect($pdf,$x,$y,$w,$h);$exec=os_print_responsible_name($items);nexa_os_dual_pdf_text($pdf,$x,$y,$w,$h,(string)$txt['executed_by'].' '.$exec,$fs,'B','L');}
    // Assinatura
    [$x,$y,$w,$h,$fs,$vis]=$block('signature');if($vis){$line($pdf,$x,$y+.4,$x+$w,$y+.4);nexa_os_dual_pdf_text($pdf,$x,$y+.6,$w,$h-.6,(string)$txt['signature'],$fs,'B','C');}
  }
}

if (!function_exists('nexa_os_document_pdf_build_dual')) {
  function nexa_os_document_pdf_build_dual(array $data,string $content='completa',string $paper='a4',string $profile='padrao'): array {
    nexa_customer_pdf_bootstrap();
    $layout=is_array($data['dual_layout']??null)?os_print_dual_layout_sanitize((array)$data['dual_layout']):os_print_dual_layout_default();
    $content=in_array($content,['completa','sem_preco','sem_produtos'],true)?$content:'completa';
    $paper=($paper==='carta')?'carta':'a4';$laser=$profile==='hp_p1005';
    $paperW=$paper==='carta'?279.4:297.0;$paperH=$paper==='carta'?215.9:210.0;
    $pdf=new \NFePHP\DA\Legacy\FPDF\Fpdf('L','mm',[$paperH,$paperW]);
    $pdf->SetMargins(0,0,0);$pdf->SetAutoPageBreak(false);$pdf->AddPage();
    $cw=(float)$layout['page']['copy_width'];$ch=(float)$layout['page']['copy_height'];$gap=(float)$layout['page']['gap'];
    // Mesmo conteúdo, enquadrado nas margens seguras de A4/Carta e HP/laser.
    $safe=$laser?12.0:($paper==='carta'?7.5:5.0);
    $scale=min(1.0,($paperW-2*$safe)/(2*$cw+$gap),($paperH-2*$safe)/$ch);
    $mx=($paperW-(2*$cw+$gap)*$scale)/2.0;
    $my=($paperH-$ch*$scale)/2.0;
    nexa_os_dual_pdf_draw_copy($pdf,$mx,$my,$data,$layout,$content,$scale);
    nexa_os_dual_pdf_draw_copy($pdf,$mx+($cw+$gap)*$scale,$my,$data,$layout,$content,$scale);
    $number=str_pad((string)(int)($data['order']['os_number']??0),6,'0',STR_PAD_LEFT);
    $pdfContent=$pdf->Output('','S');return ['filename'=>'ordem-servico-'.$number.'-2-vias'.($content==='completa'?'':'-'.$content).'.pdf','mimetype'=>'application/pdf','content'=>$pdfContent];
  }
}
