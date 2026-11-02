<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Nexa Próspero - Etiquetas de Produtos (V1)
 *
 * Módulo de relatórios, isolado de estoque/vendas.
 * Mantém modelos por empresa e permite montar etiquetas a partir do cadastro
 * de produtos ou de uma Nota de Entrada já vinculada aos produtos do Nexa.
 */

if (!function_exists('product_labels_table_exists')) {
  function product_labels_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('product_labels_column_exists')) {
  function product_labels_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
      $st->execute([$table,$column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('product_labels_ensure_schema')) {
  function product_labels_ensure_schema(PDO $pdo, int $companyId = 0): bool {
    if (!product_labels_table_exists($pdo, 'product_label_templates')) {
      if ($pdo->inTransaction()) return false;
      try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_label_templates (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          company_id BIGINT UNSIGNED NOT NULL,
          name VARCHAR(120) NOT NULL,
          paper_type VARCHAR(20) NOT NULL DEFAULT 'ROLL',
          page_width_mm DECIMAL(8,2) NOT NULL DEFAULT 100.00,
          page_height_mm DECIMAL(8,2) NULL,
          columns_count INT NOT NULL DEFAULT 1,
          label_width_mm DECIMAL(8,2) NOT NULL DEFAULT 100.00,
          label_height_mm DECIMAL(8,2) NOT NULL DEFAULT 30.00,
          gap_x_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
          gap_y_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
          margin_left_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
          margin_top_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
          margin_right_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
          margin_bottom_mm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
          code_source VARCHAR(40) NOT NULL DEFAULT 'numero_cadastro',
          barcode_source VARCHAR(40) NOT NULL DEFAULT 'numero_cadastro',
          layout_json MEDIUMTEXT NULL,
          is_default TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL,
          PRIMARY KEY (id),
          KEY idx_product_label_templates_company (company_id,is_active,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      } catch (Throwable $e) { return false; }
    }
    if ($companyId > 0) product_labels_seed_defaults($pdo, $companyId);
    return product_labels_table_exists($pdo, 'product_label_templates');
  }
}

if (!function_exists('product_labels_default_layout')) {
  function product_labels_default_layout(string $kind): array {
    $kind = strtolower(trim($kind));
    if ($kind === 'gondola') {
      return [
        'company'=>['visible'=>1,'x'=>3,'y'=>1.5,'w'=>94,'h'=>4,'font'=>3.2,'bold'=>1,'align'=>'center'],
        'name'=>['visible'=>1,'x'=>3,'y'=>6,'w'=>94,'h'=>7,'font'=>5.2,'bold'=>1,'align'=>'center'],
        'code'=>['visible'=>1,'x'=>3,'y'=>14,'w'=>28,'h'=>4,'font'=>3.1,'bold'=>0,'align'=>'left'],
        'price'=>['visible'=>1,'x'=>60,'y'=>13,'w'=>37,'h'=>8,'font'=>7.0,'bold'=>1,'align'=>'right'],
        'barcode'=>['visible'=>1,'x'=>3,'y'=>20,'w'=>94,'h'=>8,'font'=>2.5,'bold'=>0,'align'=>'center','show_text'=>1],
      ];
    }
    if ($kind === 'pimaco') {
      return [
        'company'=>['visible'=>1,'x'=>2,'y'=>1.2,'w'=>62.7,'h'=>3.5,'font'=>2.7,'bold'=>1,'align'=>'center'],
        'name'=>['visible'=>1,'x'=>2,'y'=>5,'w'=>62.7,'h'=>5.5,'font'=>3.7,'bold'=>1,'align'=>'center'],
        'code'=>['visible'=>1,'x'=>2,'y'=>11,'w'=>22,'h'=>3,'font'=>2.5,'bold'=>0,'align'=>'left'],
        'price'=>['visible'=>1,'x'=>43,'y'=>10.5,'w'=>21.7,'h'=>5,'font'=>4.8,'bold'=>1,'align'=>'right'],
        'barcode'=>['visible'=>1,'x'=>2,'y'=>16,'w'=>62.7,'h'=>8,'font'=>2.3,'bold'=>0,'align'=>'center','show_text'=>1],
      ];
    }
    return [
      'company'=>['visible'=>1,'x'=>1,'y'=>0.7,'w'=>31,'h'=>2.8,'font'=>2.1,'bold'=>1,'align'=>'center'],
      'name'=>['visible'=>1,'x'=>1,'y'=>3.6,'w'=>31,'h'=>4.8,'font'=>2.8,'bold'=>1,'align'=>'center'],
      'code'=>['visible'=>1,'x'=>1,'y'=>8.8,'w'=>11,'h'=>2.7,'font'=>2.1,'bold'=>0,'align'=>'left'],
      'price'=>['visible'=>1,'x'=>17,'y'=>8.4,'w'=>15,'h'=>3.8,'font'=>3.5,'bold'=>1,'align'=>'right'],
      'barcode'=>['visible'=>1,'x'=>1,'y'=>12.5,'w'=>31,'h'=>8.3,'font'=>1.9,'bold'=>0,'align'=>'center','show_text'=>1],
    ];
  }
}

if (!function_exists('product_labels_seed_defaults')) {
  function product_labels_seed_defaults(PDO $pdo, int $companyId): void {
    if ($companyId <= 0 || !product_labels_table_exists($pdo, 'product_label_templates')) return;
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM product_label_templates WHERE company_id=?');
      $st->execute([$companyId]);
      if ((int)$st->fetchColumn() > 0) return;

      $rows = [
        [
          'name'=>'3 colunas - 33 x 22 mm', 'paper'=>'ROLL', 'pw'=>100.0, 'ph'=>null, 'cols'=>3,
          'lw'=>33.0, 'lh'=>22.0, 'gx'=>0.0, 'gy'=>0.0, 'ml'=>0.5, 'mt'=>0.0, 'mr'=>0.5, 'mb'=>0.0,
          'kind'=>'three', 'default'=>1,
        ],
        [
          'name'=>'Etiqueta de Gôndola - 100 x 30 mm', 'paper'=>'ROLL', 'pw'=>100.0, 'ph'=>null, 'cols'=>1,
          'lw'=>100.0, 'lh'=>30.0, 'gx'=>0.0, 'gy'=>0.0, 'ml'=>0.0, 'mt'=>0.0, 'mr'=>0.0, 'mb'=>0.0,
          'kind'=>'gondola', 'default'=>0,
        ],
        [
          'name'=>'Pimaco - folha A4 (configurável)', 'paper'=>'A4', 'pw'=>210.0, 'ph'=>297.0, 'cols'=>3,
          'lw'=>64.7, 'lh'=>25.4, 'gx'=>1.0, 'gy'=>2.0, 'ml'=>6.95, 'mt'=>8.5, 'mr'=>6.95, 'mb'=>8.5,
          'kind'=>'pimaco', 'default'=>0,
        ],
      ];
      $ins = $pdo->prepare('INSERT INTO product_label_templates (company_id,name,paper_type,page_width_mm,page_height_mm,columns_count,label_width_mm,label_height_mm,gap_x_mm,gap_y_mm,margin_left_mm,margin_top_mm,margin_right_mm,margin_bottom_mm,code_source,barcode_source,layout_json,is_default,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
      foreach ($rows as $r) {
        $ins->execute([
          $companyId,$r['name'],$r['paper'],$r['pw'],$r['ph'],$r['cols'],$r['lw'],$r['lh'],$r['gx'],$r['gy'],$r['ml'],$r['mt'],$r['mr'],$r['mb'],
          'numero_cadastro','numero_cadastro',json_encode(product_labels_default_layout($r['kind']), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$r['default']
        ]);
      }
    } catch (Throwable $e) {}
  }
}

if (!function_exists('product_labels_templates')) {
  function product_labels_templates(PDO $pdo, int $companyId): array {
    product_labels_ensure_schema($pdo,$companyId);
    $st=$pdo->prepare('SELECT * FROM product_label_templates WHERE company_id=? AND is_active=1 ORDER BY is_default DESC,id ASC');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('product_labels_template')) {
  function product_labels_template(PDO $pdo, int $companyId, int $id): ?array {
    product_labels_ensure_schema($pdo,$companyId);
    $st=$pdo->prepare('SELECT * FROM product_label_templates WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$id]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}


if (!function_exists('product_labels_roll_pitch')) {
  /**
   * Passo físico vertical da mídia: distância do início de uma linha de
   * etiquetas ao início da próxima. Em etiquetas com GAP este valor deve
   * incluir a altura imprimível + o espaço físico entre as linhas.
   */
  function product_labels_roll_pitch(array $tpl, array $layout): float {
    $lh=max(0.1,(float)($tpl['label_height_mm']??0));
    $gy=max(0.0,(float)($tpl['gap_y_mm']??0));
    $explicit=max(0.0,(float)($layout['_print']['pitch_y_mm']??0));
    if($explicit>0.0)return max($lh,$explicit);

    // Compatibilidade com modelos antigos cujo nome já trazia o passo físico
    // (ex.: "3 colunas - 33 x 22 mm"), mesmo quando a área imprimível foi
    // calibrada para 21 mm.
    $name=(string)($tpl['name']??'');
    if(preg_match('/x\s*([0-9]+(?:[\.,][0-9]+)?)\s*mm/i',$name,$m)){
      $candidate=(float)str_replace(',','.',$m[1]);
      if($candidate >= $lh && $candidate <= ($lh+12.0))return $candidate;
    }
    return max($lh,$lh+$gy);
  }
}

if (!function_exists('product_labels_has_product_column')) {
  function product_labels_has_product_column(PDO $pdo, string $column): bool {
    return product_labels_column_exists($pdo,'produtos',$column);
  }
}

if (!function_exists('product_labels_product_select')) {
  function product_labels_product_select(PDO $pdo): string {
    $cols=['p.id','p.nome','p.preco','p.categoria'];
    foreach (['numero_cadastro','referencia','codigo_barras','codigo_firebird','codigo_cardapio','ean','gtin','barcode'] as $c) {
      if (product_labels_has_product_column($pdo,$c)) $cols[]='p.`'.str_replace('`','``',$c).'`';
    }
    return implode(',',$cols);
  }
}

if (!function_exists('product_labels_product_normalize')) {
  function product_labels_product_normalize(array $p): array {
    $barcode='';
    foreach (['codigo_barras','ean','gtin','barcode'] as $c) {
      $v=trim((string)($p[$c] ?? ''));
      if ($v!=='' && strtoupper($v)!=='SEM GTIN') { $barcode=$v; break; }
    }
    return [
      'id'=>(int)($p['id'] ?? 0),
      'nome'=>(string)($p['nome'] ?? ''),
      'preco'=>(float)($p['preco'] ?? 0),
      'categoria'=>(string)($p['categoria'] ?? ''),
      'numero_cadastro'=>trim((string)($p['numero_cadastro'] ?? '')),
      'codigo_barras'=>$barcode,
      'codigo_firebird'=>trim((string)($p['codigo_firebird'] ?? '')),
      'referencia'=>trim((string)($p['referencia'] ?? '')),
      'codigo_cardapio'=>trim((string)($p['codigo_cardapio'] ?? '')),
    ];
  }
}

if (!function_exists('product_labels_search_products')) {
  function product_labels_search_products(PDO $pdo, int $companyId, string $term, int $limit=50): array {
    $term=trim($term);$where=['p.company_id=?','COALESCE(p.ativo,1)=1'];$params=[$companyId];
    if ($term!=='') {
      $like=nexa_search_like($term);$parts=['p.nome LIKE ?','p.categoria LIKE ?','CAST(p.id AS CHAR) LIKE ?'];
      $params[]=$like;$params[]=$like;$params[]=$like;
      foreach (['numero_cadastro','referencia','codigo_barras','codigo_firebird','codigo_cardapio','ean','gtin','barcode'] as $c) {
        if (!product_labels_has_product_column($pdo,$c)) continue;
        $parts[]='CAST(p.`'.str_replace('`','``',$c).'` AS CHAR) LIKE ?';$params[]=$like;
      }
      $where[]='('.implode(' OR ',$parts).')';
    }
    $sql='SELECT '.product_labels_product_select($pdo).' FROM produtos p WHERE '.implode(' AND ',$where).' ORDER BY p.nome ASC LIMIT '.max(1,min(150,$limit));
    $st=$pdo->prepare($sql);$st->execute($params);$out=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[]=product_labels_product_normalize($r);
    return $out;
  }
}

if (!function_exists('product_labels_products_by_ids')) {
  function product_labels_products_by_ids(PDO $pdo, int $companyId, array $ids): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));
    if (!$ids) return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare('SELECT '.product_labels_product_select($pdo).' FROM produtos p WHERE p.company_id=? AND p.id IN ('.$ph.')');
    $st->execute(array_merge([$companyId],$ids));$out=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['id']]=product_labels_product_normalize($r);
    return $out;
  }
}

if (!function_exists('product_labels_recent_entries')) {
  function product_labels_recent_entries(PDO $pdo, int $companyId, string $term='', int $limit=60): array {
    if (!product_labels_table_exists($pdo,'fiscal_xml_imports')) return [];
    $where=['company_id=?'];$params=[$companyId];$term=trim($term);
    if ($term!=='') {
      $like=nexa_search_like($term);$where[]='(supplier_name LIKE ? OR supplier_doc LIKE ? OR CAST(number AS CHAR) LIKE ? OR access_key LIKE ?)';
      array_push($params,$like,$like,$like,$like);
    }
    $sql="SELECT id,number,series,issue_date,entry_date,supplier_name,supplier_doc,total_invoice,status FROM fiscal_xml_imports WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(entry_date,issue_date) DESC,id DESC LIMIT ".max(1,min(150,$limit));
    $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('product_labels_entry_items')) {
  function product_labels_entry_items(PDO $pdo, int $companyId, int $entryId): array {
    if ($entryId<=0 || !product_labels_table_exists($pdo,'fiscal_xml_items')) return ['items'=>[],'unlinked'=>0,'entry'=>null];
    $st=$pdo->prepare('SELECT id,number,series,issue_date,entry_date,supplier_name,status FROM fiscal_xml_imports WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$entryId]);$entry=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$entry) return ['items'=>[],'unlinked'=>0,'entry'=>null];

    $qtyExpr=product_labels_column_exists($pdo,'fiscal_xml_items','stock_quantity')
      ? 'CASE WHEN COALESCE(i.stock_quantity,0)>0 THEN i.stock_quantity ELSE (COALESCE(i.quantity,0)*CASE WHEN COALESCE(i.conversion_factor,0)>0 THEN i.conversion_factor ELSE 1 END) END'
      : 'COALESCE(i.quantity,0)';
    $sql='SELECT i.product_id,SUM('.$qtyExpr.') AS label_qty,COUNT(*) AS item_count FROM fiscal_xml_items i WHERE i.company_id=? AND i.xml_import_id=? AND i.product_id IS NOT NULL GROUP BY i.product_id';
    $st=$pdo->prepare($sql);$st->execute([$companyId,$entryId]);$agg=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];$items=[];
    $productMap=product_labels_products_by_ids($pdo,$companyId,array_map(static fn($r)=>(int)($r['product_id']??0),$agg));
    foreach ($agg as $r) {
      $pid=(int)($r['product_id']??0);if($pid<=0||!isset($productMap[$pid]))continue;
      $p=$productMap[$pid];$p['note_quantity']=(float)($r['label_qty'] ?? 0);$items[]=$p;
    }
    $u=$pdo->prepare('SELECT COUNT(*) FROM fiscal_xml_items WHERE company_id=? AND xml_import_id=? AND product_id IS NULL');$u->execute([$companyId,$entryId]);
    return ['items'=>$items,'unlinked'=>(int)$u->fetchColumn(),'entry'=>$entry];
  }
}

if (!function_exists('product_labels_company_name')) {
  function product_labels_company_name(PDO $pdo, int $companyId): string {
    try {$st=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');$st->execute([$companyId]);$v=trim((string)$st->fetchColumn());return $v!==''?$v:'Empresa';}catch(Throwable $e){return 'Empresa';}
  }
}

if (!function_exists('product_labels_source_value')) {
  function product_labels_source_value(array $p, string $source): string {
    $source=strtolower(trim($source));
    $v='';
    if ($source==='id') $v=(string)($p['id'] ?? '');
    elseif ($source==='codigo_barras') $v=(string)($p['codigo_barras'] ?? '');
    elseif ($source==='codigo_firebird') $v=(string)($p['codigo_firebird'] ?? '');
    elseif ($source==='codigo_cardapio') $v=(string)($p['codigo_cardapio'] ?? '');
    else $v=(string)($p['numero_cadastro'] ?? '');
    if (trim($v)==='' && $source==='numero_cadastro') $v=(string)($p['id'] ?? '');
    return trim($v);
  }
}

if (!function_exists('product_labels_code128_svg')) {
  function product_labels_code128_svg(string $text, bool $showText=true): string {
    $text=trim($text);
    if ($text==='') return '';
    $patterns=[
      '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'
    ];
    $values=[];$safe='';
    $len=strlen($text);
    for($i=0;$i<$len;$i++){
      $ch=$text[$i];$ord=ord($ch);
      if($ord<32||$ord>126){$ch='?';$ord=63;}
      $safe.=$ch;$values[]=$ord-32;
    }
    $codes=[104];$checksum=104;
    foreach($values as $i=>$v){$codes[]=$v;$checksum+=$v*($i+1);} $codes[]=$checksum%103;$codes[]=106;
    $modules=20;foreach($codes as $c)$modules+=array_sum(array_map('intval',str_split($patterns[$c])));
    $barH=$showText?72:92;$x=10;$rects='';
    foreach($codes as $c){$p=$patterns[$c];$bar=true;foreach(str_split($p) as $w){$ww=(int)$w;if($bar)$rects.='<rect x="'.$x.'" y="2" width="'.$ww.'" height="'.$barH.'" fill="#000"/>';$x+=$ww;$bar=!$bar;}}
    $label=$showText?'<text x="'.($modules/2).'" y="96" text-anchor="middle" font-family="Arial,sans-serif" font-size="12">'.htmlspecialchars($safe,ENT_QUOTES,'UTF-8').'</text>':'';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$modules.' 100" preserveAspectRatio="none" role="img" aria-label="Código de barras">'.$rects.$label.'</svg>';
  }
}

if (!function_exists('product_labels_sanitize_layout')) {
  function product_labels_sanitize_layout($raw): array {
    if (is_string($raw)) $raw=json_decode($raw,true);
    if (!is_array($raw)) $raw=[];
    $allowed=['company','name','code','barcode','price'];$out=[];
    foreach($allowed as $k){$v=is_array($raw[$k]??null)?$raw[$k]:[];$out[$k]=[
      'visible'=>!empty($v['visible'])?1:0,
      'x'=>max(0,min(500,(float)($v['x']??0))), 'y'=>max(0,min(500,(float)($v['y']??0))),
      'w'=>max(.5,min(500,(float)($v['w']??10))), 'h'=>max(.5,min(500,(float)($v['h']??3))),
      'font'=>max(1,min(30,(float)($v['font']??2.5))), 'bold'=>!empty($v['bold'])?1:0,
      'align'=>in_array(($v['align']??''),['left','center','right'],true)?$v['align']:'left',
    ];if($k==='barcode')$out[$k]['show_text']=!empty($v['show_text'])?1:0;}
    $print=is_array($raw['_print']??null)?$raw['_print']:[];
    $mode=strtoupper(trim((string)($print['media_mode']??'GAP')));
    if(!in_array($mode,['GAP','CONTINUOUS'],true))$mode='GAP';
    $out['_print']=[
      'media_mode'=>$mode,
      'pitch_y_mm'=>max(0,min(500,(float)($print['pitch_y_mm']??0))),
    ];
    return $out;
  }
}
