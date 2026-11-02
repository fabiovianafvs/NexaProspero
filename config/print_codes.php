<?php
declare(strict_types=1);

/* Utilitários de códigos para impressos do Nexa.
 * Não dependem de GD/Imagick para SVG e o PNG do QR é montado em PHP puro,
 * permitindo uso também no FPDF do servidor. */

if (!function_exists('nexa_print_code128_svg')) {
  function nexa_print_code128_svg(string $text, bool $showText = true): string {
    $text = trim($text);
    if ($text === '') return '';
    $patterns = [
      '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'
    ];
    $values = []; $safe = '';
    $len = strlen($text);
    for ($i=0; $i<$len; $i++) {
      $ch = $text[$i]; $ord = ord($ch);
      if ($ord < 32 || $ord > 126) { $ch='?'; $ord=63; }
      $safe .= $ch; $values[] = $ord - 32;
    }
    $codes=[104]; $checksum=104;
    foreach($values as $i=>$v){$codes[]=$v;$checksum+=$v*($i+1);} $codes[]=$checksum%103;$codes[]=106;
    $modules=20; foreach($codes as $c)$modules+=array_sum(array_map('intval',str_split($patterns[$c])));
    $barH=$showText?72:96; $x=10; $rects='';
    foreach($codes as $c){$p=$patterns[$c];$bar=true;foreach(str_split($p) as $w){$ww=(int)$w;if($bar)$rects.='<rect x="'.$x.'" y="2" width="'.$ww.'" height="'.$barH.'" fill="#000"/>';$x+=$ww;$bar=!$bar;}}
    $label=$showText?'<text x="'.($modules/2).'" y="96" text-anchor="middle" font-family="Arial,sans-serif" font-size="12">'.htmlspecialchars($safe,ENT_QUOTES,'UTF-8').'</text>':'';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$modules.' 100" preserveAspectRatio="none" role="img" aria-label="Código de barras">'.$rects.$label.'</svg>';
  }
}

if (!function_exists('nexa_print_barcode_bootstrap')) {
  function nexa_print_barcode_bootstrap(): bool {
    if (class_exists('Com\\Tecnick\\Barcode\\Barcode')) return true;
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    return class_exists('Com\\Tecnick\\Barcode\\Barcode');
  }
}

if (!function_exists('nexa_print_qr_svg')) {
  function nexa_print_qr_svg(string $text): string {
    $text=trim($text); if($text==='' || !nexa_print_barcode_bootstrap()) return '';
    try {
      $barcode=new \Com\Tecnick\Barcode\Barcode();
      $obj=$barcode->getBarcodeObj('QRCODE,M',$text,-4,-4,'black',[-4,-4,-4,-4])->setBackgroundColor('white');
      return method_exists($obj,'getSvgCode') ? (string)$obj->getSvgCode() : '';
    } catch(Throwable $e){ return ''; }
  }
}

if (!function_exists('nexa_print_svg_data_uri')) {
  function nexa_print_svg_data_uri(string $svg): string {
    $svg=trim($svg); return $svg!=='' ? 'data:image/svg+xml;base64,'.base64_encode($svg) : '';
  }
}

if (!function_exists('nexa_print_png_chunk')) {
  function nexa_print_png_chunk(string $type,string $data): string {
    $crc=crc32($type.$data); if($crc<0)$crc+=4294967296;
    return pack('N',strlen($data)).$type.$data.pack('N',$crc);
  }
}

if (!function_exists('nexa_print_qr_png_data')) {
  function nexa_print_qr_png_data(string $text,int $scale=4,int $quiet=4): string {
    $text=trim($text); if($text==='' || !nexa_print_barcode_bootstrap()) return '';
    try {
      $barcode=new \Com\Tecnick\Barcode\Barcode();
      $obj=$barcode->getBarcodeObj('QRCODE,M',$text,-1,-1,'black',[0,0,0,0]);
      $a=(array)$obj->getArray(); $cols=(int)($a['ncols']??0); $rows=(int)($a['nrows']??0); $bars=(array)($a['bars']??[]);
      if($cols<=0||$rows<=0)return '';
      $scale=max(1,min(10,$scale));$quiet=max(0,min(10,$quiet));$w=($cols+$quiet*2)*$scale;$h=($rows+$quiet*2)*$scale;
      $black=[];
      foreach($bars as $bar){if(!is_array($bar)||count($bar)<4)continue;[$x,$y,$bw,$bh]=array_map('intval',array_slice($bar,0,4));for($yy=$y;$yy<$y+$bh;$yy++)for($xx=$x;$xx<$x+$bw;$xx++)$black[$yy*$cols+$xx]=1;}
      $raw='';
      for($py=0;$py<$h;$py++){
        $raw.="\x00";$my=intdiv($py,$scale)-$quiet;
        for($px=0;$px<$w;$px++){
          $mx=intdiv($px,$scale)-$quiet;$isBlack=($mx>=0&&$mx<$cols&&$my>=0&&$my<$rows&&!empty($black[$my*$cols+$mx]));
          $raw.=$isBlack?"\x00":"\xFF";
        }
      }
      $png="\x89PNG\r\n\x1a\n";
      $png.=nexa_print_png_chunk('IHDR',pack('NNCCCCC',$w,$h,8,0,0,0,0));
      $png.=nexa_print_png_chunk('IDAT',gzcompress($raw,9));
      $png.=nexa_print_png_chunk('IEND','');
      return $png;
    } catch(Throwable $e){return '';}
  }
}
