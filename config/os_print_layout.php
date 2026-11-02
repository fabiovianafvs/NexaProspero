<?php
declare(strict_types=1);

require_once __DIR__ . '/config_store.php';


if (!function_exists('os_print_substr')) {
  function os_print_substr(string $value,int $start,int $length): string {
    return function_exists('mb_substr') ? (string)mb_substr($value,$start,$length,'UTF-8') : (string)substr($value,$start,$length);
  }
}

if (!function_exists('os_print_dual_layout_key')) {
  function os_print_dual_layout_key(): string { return 'os_print_dual_layout_v1'; }
}

if (!function_exists('os_print_dual_layout_default')) {
  function os_print_dual_layout_default(): array {
    return [
      'version' => 4,
      'page' => [
        'width' => 297.0,
        'height' => 210.0,
        'margin_x' => 5.0,
        'margin_y' => 5.0,
        'gap' => 5.0,
        'copy_width' => 141.0,
        'copy_height' => 200.0,
      ],
      'blocks' => [
        // A4 paisagem: 2 vias, 5 mm de margem, cada via 141 x 200 mm.
        // Cabeçalho compacto, modalidade sem espaço ocioso e assinaturas independentes.
        'logo'        => ['x'=>0.0,  'y'=>0.0,   'w'=>47.0,  'h'=>23.0, 'font'=>6.0, 'visible'=>1],
        'heading'     => ['x'=>47.0, 'y'=>0.0,   'w'=>94.0,  'h'=>6.5,  'font'=>8.0, 'visible'=>1],
        'company'     => ['x'=>47.0, 'y'=>6.5,   'w'=>94.0,  'h'=>16.5, 'font'=>6.5, 'visible'=>1],
        'warning'     => ['x'=>0.0,  'y'=>23.0,  'w'=>141.0, 'h'=>6.0,  'font'=>7.0, 'visible'=>1],
        'customer'    => ['x'=>0.0,  'y'=>29.0,  'w'=>141.0, 'h'=>36.0, 'font'=>6.7, 'visible'=>1],
        'os'          => ['x'=>0.0,  'y'=>65.0,  'w'=>141.0, 'h'=>18.0, 'font'=>6.7, 'visible'=>1],
        'occurrence'  => ['x'=>0.0,  'y'=>83.0,  'w'=>141.0, 'h'=>25.0, 'font'=>6.7, 'visible'=>1],
        'execution'   => ['x'=>0.0,  'y'=>108.0, 'w'=>141.0, 'h'=>9.0,  'font'=>6.5, 'visible'=>1],
        'items'       => ['x'=>0.0,  'y'=>117.0, 'w'=>141.0, 'h'=>54.0, 'font'=>6.3, 'visible'=>1],
        'payments'    => ['x'=>0.0,  'y'=>171.0, 'w'=>141.0, 'h'=>17.5, 'font'=>6.1, 'visible'=>1],
        'executed_by' => ['x'=>0.0,  'y'=>188.5, 'w'=>68.5,  'h'=>11.5, 'font'=>6.1, 'visible'=>1],
        'signature'   => ['x'=>72.5, 'y'=>188.5, 'w'=>68.5,  'h'=>11.5, 'font'=>5.9, 'visible'=>1],
      ],
      'texts' => [
        'document_title' => 'REQUISIÇÃO ORÇAMENTO Nº O.S.:',
        'warning' => 'NÃO É DOCUMENTO FISCAL. NÃO É VÁLIDO COMO GARANTIA DE MERCADORIA',
        'customer_title' => 'Dados do Cliente / Execução',
        'os_title' => 'Dados OS',
        'occurrence_title' => 'Ocorrência (Solicitação do Cliente)',
        'payment_title' => 'Formas de Pagamento:',
        'payment_note' => 'A escolha da forma de pagamento já autoriza a emissão da Nota Fiscal com boleto. Gentileza confirmar e-mail para envio da Nota Fiscal.',
        'executed_by' => 'Executado por:',
        'signature' => 'ASSINATURA DO CLIENTE POR EXTENSO/ AUTORIZAÇÃO',
      ],
      'options' => [
        'blank_item_rows' => 8,
        'payment_options' => ['De 7 a 10 dias à vista','15 dias c/ acréscimo 5%','20 dias c/ acréscimo 7%','30 dias c/ acréscimo 10%'],
      ],
    ];
  }
}

if (!function_exists('os_print_dual_block_labels')) {
  function os_print_dual_block_labels(): array {
    return [
      'logo'=>'Logo da empresa','heading'=>'Título / número da O.S.','company'=>'Dados da empresa','warning'=>'Aviso não fiscal',
      'customer'=>'Dados do cliente / execução','os'=>'Dados da O.S.','occurrence'=>'Ocorrência / solicitação','execution'=>'Chegada / saída / execução',
      'items'=>'Grade de produtos e serviços','payments'=>'Formas de pagamento / total','executed_by'=>'Executado por','signature'=>'Assinatura do cliente',
    ];
  }
}

if (!function_exists('os_print_dual_layout_sanitize')) {
  function os_print_dual_layout_sanitize(array $raw): array {
    $base = os_print_dual_layout_default();
    $out = $base;
    $cw = (float)$base['page']['copy_width'];
    $ch = (float)$base['page']['copy_height'];
    // Converter automaticamente somente os presets originais das versões anteriores.
    // Layouts com blocos deslocados manualmente permanecem intactos para evitar sobreposições.
    $oldPresets = [
      1 => [
        'logo'=>[0,0,49,28,6], 'heading'=>[49,0,92,7,7.4], 'company'=>[49,7,92,21,5.8],
        'warning'=>[0,28,141,7,6.2], 'customer'=>[0,35,141,37,5.4], 'os'=>[0,72,141,22,5.4],
        'occurrence'=>[0,94,141,27,5.4], 'execution'=>[0,121,141,9,5.2],
        'items'=>[0,130,141,46,5.0], 'payments'=>[0,176,141,15,4.9],
        'executed_by'=>[0,191,141,5,5.2], 'signature'=>[7,196,127,4,5.2],
      ],
      2 => [
        'logo'=>[0,0,47,27,6], 'heading'=>[47,0,89,7,8], 'company'=>[47,7,89,20,6.7],
        'warning'=>[0,27,136,7,7], 'customer'=>[0,34,136,36,6.7], 'os'=>[0,70,136,21,6.7],
        'occurrence'=>[0,91,136,26,6.7], 'execution'=>[0,117,136,9,6.5],
        'items'=>[0,126,136,43,6.3], 'payments'=>[0,169,136,14,6.1],
        'executed_by'=>[0,183,136,4,6.3], 'signature'=>[7,187,122,3,6.1],
      ],
      // Modelo refinado v3 (10 mm): atualizar automaticamente somente quando
      // os blocos continuarem exatamente nos valores padrão anteriores.
      3 => [
        'logo'=>[0,0,47,23,6], 'heading'=>[47,0,89,6.5,8], 'company'=>[47,6.5,89,16.5,6.5],
        'warning'=>[0,23,136,6,7], 'customer'=>[0,29,136,36,6.7], 'os'=>[0,65,136,18,6.7],
        'occurrence'=>[0,83,136,25,6.7], 'execution'=>[0,108,136,9,6.5],
        'items'=>[0,117,136,44,6.3], 'payments'=>[0,161,136,17.5,6.1],
        'executed_by'=>[0,178.5,66,11.5,6.1], 'signature'=>[70,178.5,66,11.5,5.9],
      ],
    ];
    $savedVersion=(int)($raw['version']??1);
    $preset=$oldPresets[$savedVersion]??null;
    $useRefinedPreset=$preset!==null;
    if ($useRefinedPreset) {
      foreach ($preset as $key=>$old) {
        $source=(array)($raw['blocks'][$key]??[]);
        foreach (['x','y','w','h','font'] as $idx=>$field) {
          if (!isset($source[$field]) || abs((float)$source[$field]-(float)$old[$idx])>0.06) {
            $useRefinedPreset=false;
            break 2;
          }
        }
      }
    }
    if ($useRefinedPreset) {
      foreach ($base['blocks'] as $key=>$def) {
        $def['visible']=!empty($raw['blocks'][$key]['visible'])?1:0;
        $out['blocks'][$key]=$def;
      }
      // Textos, opções e visibilidade continuam sendo carregados abaixo.
    }
    // Layouts salvos no modelo antigo (5 mm/141 x 200 mm) são migrados em memória.
    // Assim, empresas que personalizaram os blocos não perdem suas configurações.
    $legacy = $savedVersion < 2 || ($savedVersion < 3 && (float)($raw['page']['copy_width']??$cw)>139.0);
    $oldW = max(1.0,(float)($raw['page']['copy_width']??141.0));
    $oldH = max(1.0,(float)($raw['page']['copy_height']??200.0));
    $oldFonts = ['heading'=>7.4,'company'=>5.8,'warning'=>6.2,'customer'=>5.4,'os'=>5.4,
      'occurrence'=>5.4,'execution'=>5.2,'items'=>5.0,'payments'=>4.9,'executed_by'=>5.2,'signature'=>5.2];
    foreach ($base['blocks'] as $key => $def) {
      $src = isset($raw['blocks'][$key]) && is_array($raw['blocks'][$key]) ? $raw['blocks'][$key] : [];
      if ($useRefinedPreset) continue;
      $x = (float)($src['x']??$def['x']);
      $y = (float)($src['y']??$def['y']);
      $w = (float)($src['w']??$def['w']);
      $h = (float)($src['h']??$def['h']);
      $font = (float)($src['font']??$def['font']);
      if ($legacy && $src) {
        if (isset($src['x'])) $x *= $cw/$oldW;
        if (isset($src['y'])) $y *= $ch/$oldH;
        if (isset($src['w'])) $w *= $cw/$oldW;
        if (isset($src['h'])) $h *= $ch/$oldH;
        // Aumentar apenas as fontes que ainda estão no valor padrão anterior.
        if (isset($oldFonts[$key]) && abs($font-$oldFonts[$key])<0.01) $font=(float)$def['font'];
      }
      $x = max(0.0,min($cw-1.0,$x));
      $y = max(0.0,min($ch-1.0,$y));
      $w = max(1.0,min($cw-$x,$w));
      $h = max(1.0,min($ch-$y,$h));
      $font = max(3.5,min(14.0,$font));
      $out['blocks'][$key] = ['x'=>round($x,2),'y'=>round($y,2),'w'=>round($w,2),'h'=>round($h,2),'font'=>round($font,2),'visible'=>empty($src) ? (int)$def['visible'] : (!empty($src['visible'])?1:0)];
    }
    foreach ($base['texts'] as $key=>$def) {
      $val = trim((string)($raw['texts'][$key]??$def));
      $out['texts'][$key] = os_print_substr($val!==''?$val:$def,0,$key==='payment_note'?700:220);
    }
    $rows=(int)($raw['options']['blank_item_rows']??$base['options']['blank_item_rows']);
    $out['options']['blank_item_rows']=max(0,min(20,$rows));
    $opts=[];
    foreach((array)($raw['options']['payment_options']??$base['options']['payment_options']) as $v){$v=trim((string)$v);if($v!=='')$opts[]=os_print_substr($v,0,120);if(count($opts)>=6)break;}
    $out['options']['payment_options']=$opts ?: $base['options']['payment_options'];
    return $out;
  }
}

if (!function_exists('os_print_dual_layout_load')) {
  function os_print_dual_layout_load(PDO $pdo,int $companyId): array {
    $base=os_print_dual_layout_default();
    try{$saved=config_store_get($pdo,$companyId,os_print_dual_layout_key());if(is_array($saved))return os_print_dual_layout_sanitize(config_deep_merge($base,$saved));}catch(Throwable $e){}
    return $base;
  }
}

if (!function_exists('os_print_dual_layout_save')) {
  function os_print_dual_layout_save(PDO $pdo,int $companyId,array $layout): void {
    config_store_set($pdo,$companyId,os_print_dual_layout_key(),os_print_dual_layout_sanitize($layout));
  }
}

if (!function_exists('os_print_has_column')) {
  function os_print_has_column(PDO $pdo,string $table,string $column): bool {
    static $cache=[];$key=$table.'.'.$column;if(array_key_exists($key,$cache))return $cache[$key];
    try{$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$st->execute([$table,$column]);return $cache[$key]=((int)$st->fetchColumn()>0);}catch(Throwable $e){return $cache[$key]=false;}
  }
}

if (!function_exists('os_print_enrich_items')) {
  function os_print_enrich_items(PDO $pdo,int $companyId,array $items): array {
    $ids=[];foreach($items as $it){$pid=(int)($it['produto_id']??0);if($pid>0)$ids[$pid]=true;}
    $map=[];
    if($ids){
      $select=['id'];
      $select[]=os_print_has_column($pdo,'produtos','numero_cadastro')?'numero_cadastro':'NULL AS numero_cadastro';
      $select[]=os_print_has_column($pdo,'produtos','codigo_firebird')?'codigo_firebird':'NULL AS codigo_firebird';
      $ph=implode(',',array_fill(0,count($ids),'?'));
      try{$st=$pdo->prepare('SELECT '.implode(',',$select).' FROM produtos WHERE company_id=? AND id IN ('.$ph.')');$st->execute(array_merge([$companyId],array_keys($ids)));foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $p)$map[(int)$p['id']]=$p;}catch(Throwable $e){}
    }
    foreach($items as &$it){
      $pid=(int)($it['produto_id']??0);$p=$map[$pid]??[];$n=trim((string)($p['numero_cadastro']??''));$f=trim((string)($p['codigo_firebird']??''));
      $it['display_code']=$n!==''&&$n!=='0'?$n:($f!==''?$f:($pid>0?(string)$pid:''));
    }unset($it);
    return $items;
  }
}

if (!function_exists('os_print_field_value')) {
  function os_print_field_value(array $field,array $optionLabels=[]): string {
    $v=(string)($field['value_text']??'');
    if((string)($field['input_type']??'')==='checkbox')return $v==='1'?'Sim':'Não';
    $key=(int)($field['id']??0).'|'.$v;return isset($optionLabels[$key])?(string)$optionLabels[$key]:$v;
  }
}

if (!function_exists('os_print_norm')) {
  function os_print_norm(string $v): string {
    $v=function_exists('mb_strtolower')?mb_strtolower(trim($v),'UTF-8'):strtolower(trim($v));$t=@iconv('UTF-8','ASCII//TRANSLIT',$v);if($t!==false)$v=$t;return preg_replace('/[^a-z0-9]+/','_',trim($v))??'';
  }
}

if (!function_exists('os_print_custom_value')) {
  function os_print_custom_value(array $fields,array $optionLabels,array $needles): string {
    $needles=array_map('os_print_norm',$needles);
    foreach($fields as $f){
      $hay=os_print_norm(implode(' ',[(string)($f['field_key']??''),(string)($f['semantic_role']??''),(string)($f['label']??'')]));
      foreach($needles as $needle){if($needle!==''&&str_contains($hay,$needle)){return trim(os_print_field_value($f,$optionLabels));}}
    }
    return '';
  }
}

if (!function_exists('os_print_responsible_name')) {
  function os_print_responsible_name(array $items): string {
    foreach($items as $it){foreach(['seller_name_snapshot','technician_name_snapshot','responsible_name'] as $k){$v=trim((string)($it[$k]??''));if($v!=='')return $v;}}
    return '';
  }
}
