<?php
declare(strict_types=1);

/**
 * V11.45 - Importação de Clientes/Pessoas por planilha com mapa de códigos de origem.
 *
 * Arquivos temporários ficam em storage/importacoes_clientes
 * por até 48 horas. A leitura XLSX/CSV reaproveita as rotinas já homologadas
 * da Central de Importação de Produtos, mas o mapeamento e a persistência são
 * próprios do cadastro unificado finance_people.
 */

require_once __DIR__ . '/product_import_module.php';
require_once __DIR__ . '/company_sequences.php';
require_once __DIR__ . '/gestao_module.php';
require_once __DIR__ . '/finance_module.php';

if (!function_exists('customer_import_h')) {
  function customer_import_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('customer_import_substr')) {
  function customer_import_substr($value, int $max): string {
    $value = (string)$value;
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
  }
}

if (!function_exists('customer_import_digits')) {
  function customer_import_digits($value, int $max = 40): string {
    $digits = preg_replace('/\D+/', '', product_import_excel_identifier($value));
    return substr((string)($digits ?? ''), 0, $max);
  }
}

if (!function_exists('customer_import_clean_text')) {
  function customer_import_clean_text($value, int $max = 0): string {
    $text = trim(product_import_clean_utf8($value));
    $text = str_replace(['_x000D_', '_x000A_'], ["\n", "\n"], $text);
    $text = preg_replace("/\r\n?|\n/u", "\n", $text) ?? $text;
    if ($max > 0) $text = customer_import_substr($text, $max);
    return $text;
  }
}

if (!function_exists('customer_import_normalize_key')) {
  function customer_import_normalize_key($value): string {
    return product_import_normalize_header($value);
  }
}

if (!function_exists('customer_import_aliases')) {
  function customer_import_aliases(): array {
    return [
      'source_code' => ['CODIGO','COD_CLIENTE','CODIGO_CLIENTE','ID','ID_CLIENTE','NUMERO','NUMERO_CADASTRO','COD_PESSOA'],
      'name' => ['NOME','RAZAO_SOCIAL','NOME_RAZAO_SOCIAL','CLIENTE','NOME_CLIENTE','NOME_PESSOA'],
      'fantasy_name' => ['NOME_FANTASIA','FANTASIA','APELIDO'],
      'document' => ['CNPJ_CPF','CPF_CNPJ','CNPJCPF','CPFCNPJ','CPF','CNPJ','DOCUMENTO','DOCUMENTO_FISCAL'],
      'identity_document' => ['RG','IDENTIDADE','DOCUMENTO_IDENTIDADE','DOC_IDENTIDADE'],
      'identity_issuer' => ['ORGAO_EXPEDIDOR','EMISSOR_RG','ORGAO_RG'],
      'identity_state' => ['UF_RG','ESTADO_RG'],
      'state_registration' => ['INSCRICAO_ESTADUAL','INSC_ESTADUAL','IE'],
      'municipal_registration' => ['INSCRICAO_MUNICIPAL','INSC_MUNICIPAL','IM'],
      'person_type' => ['TIPO_DE_PESSOA','TIPO_PESSOA','TIPO','PESSOA_TIPO','FISICA_JURIDICA','PF_PJ','TIPO_CLIENTE'],
      'address' => ['ENDERECO_LOGRADOURO','ENDERECO','LOGRADOURO','RUA'],
      'number' => ['ENDERECO_NUMERO_LOGRADOURO','ENDERECO_NUMERO','NUMERO_LOGRADOURO','NUMERO_ENDERECO','NRO','NUMERO'],
      'complement' => ['ENDERECO_COMPLEMENTO_LOGRADOURO','ENDERECO_COMPLEMENTO','COMPLEMENTO'],
      'district' => ['ENDERECO_BAIRRO','BAIRRO'],
      'city' => ['ENDERECO_NOME_MUNICIPIO','ENDERECO_MUNICIPIO','MUNICIPIO','CIDADE'],
      'city_ibge' => ['CODIGO_IBGE','COD_IBGE','IBGE','MUNICIPIO_IBGE','CIDADE_IBGE'],
      'state' => ['ENDERECO_UF','UF','ESTADO'],
      'zip_code' => ['ENDERECO_CEP','CEP'],
      'phone' => ['TELEFONE_PRINCIPAL','TELEFONE','FONE','FONE_PRINCIPAL'],
      'whatsapp' => ['CELULAR_PRINCIPAL','CELULAR','WHATSAPP','WHATS','TELEFONE_CELULAR'],
      'email' => ['EMAIL_PRINCIPAL','EMAIL','E_MAIL'],
      'created_at' => ['DATA_DO_CADASTRO','DATA_CADASTRO','CADASTRADO_EM','DT_CADASTRO'],
      'birth_opening_date' => ['DATA_DE_NASCIMENTO','DATA_NASCIMENTO','NASCIMENTO','DATA_ABERTURA','DATA_DE_ABERTURA','DT_NASCIMENTO'],
      'notes' => ['OBSERVACAO','OBSERVACOES','OBS','NOTAS','COMENTARIO','COMENTARIOS'],
    ];
  }
}

if (!function_exists('customer_import_field_labels')) {
  function customer_import_field_labels(): array {
    return [
      'source_code'=>'Código / nº de origem',
      'name'=>'Nome / Razão social *',
      'fantasy_name'=>'Nome fantasia',
      'document'=>'CPF / CNPJ',
      'person_type'=>'Tipo de pessoa',
      'identity_document'=>'RG / Identidade',
      'identity_issuer'=>'Órgão expedidor',
      'identity_state'=>'UF do RG',
      'state_registration'=>'Inscrição Estadual',
      'municipal_registration'=>'Inscrição Municipal',
      'birth_opening_date'=>'Nascimento / Abertura',
      'email'=>'E-mail',
      'phone'=>'Telefone',
      'whatsapp'=>'Celular / WhatsApp',
      'zip_code'=>'CEP',
      'address'=>'Logradouro',
      'number'=>'Número',
      'complement'=>'Complemento',
      'district'=>'Bairro',
      'city'=>'Município',
      'city_ibge'=>'Código IBGE',
      'state'=>'UF',
      'created_at'=>'Data do cadastro',
      'notes'=>'Observação',
    ];
  }
}

if (!function_exists('customer_import_storage_root')) {
  function customer_import_storage_root(int $companyId): string {
    $base = dirname(__DIR__) . '/storage/importacoes_clientes';
    if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) throw new RuntimeException('Não foi possível criar a pasta segura de importação de clientes.');
    $deny = $base . '/.htaccess';
    if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
    $dir = $base . '/' . max(1, $companyId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar a pasta da empresa para importação.');
    return $dir;
  }
}

if (!function_exists('customer_import_matrix_cache_path')) {
  function customer_import_matrix_cache_path(int $companyId, string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/',$token)) throw new RuntimeException('Arquivo de importação inválido.');
    return customer_import_storage_root($companyId) . '/' . $token . '.matrix.json';
  }
}

if (!function_exists('customer_import_matrix_cache_write')) {
  function customer_import_matrix_cache_write(int $companyId, string $token, array $matrix): void {
    $json=json_encode($matrix,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Não foi possível preparar o cache da planilha de clientes.');
    if(@file_put_contents(customer_import_matrix_cache_path($companyId,$token),$json,LOCK_EX)===false)throw new RuntimeException('Não foi possível gravar o cache da planilha de clientes.');
  }
}

if (!function_exists('customer_import_matrix_cached')) {
  function customer_import_matrix_cached(int $companyId, array $meta): array {
    $token=(string)($meta['token']??'');
    $cache=customer_import_matrix_cache_path($companyId,$token);
    if(is_file($cache)){
      $matrix=json_decode((string)@file_get_contents($cache),true);
      if(is_array($matrix) && isset($matrix['rows']) && is_array($matrix['rows']))return $matrix;
    }
    $matrix=customer_import_matrix((string)$meta['stored_path'],(string)$meta['extension']);
    customer_import_matrix_cache_write($companyId,$token,$matrix);
    return $matrix;
  }
}

if (!function_exists('customer_import_cleanup')) {
  function customer_import_cleanup(int $companyId): void {
    $dir = customer_import_storage_root($companyId);
    $limit = time() - 172800;
    foreach ((array)glob($dir . '/*') as $file) {
      if (is_file($file) && @filemtime($file) < $limit) @unlink($file);
    }
  }
}

if (!function_exists('customer_import_xlsx_matrix')) {
  function customer_import_xlsx_matrix(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão PHP ZipArchive não está instalada. Instale php-zip ou envie um arquivo CSV.');
    if (!function_exists('simplexml_load_string')) throw new RuntimeException('A extensão PHP SimpleXML não está instalada. Instale php-xml.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Não foi possível abrir a planilha XLSX.');
    try {
      $shared = [];
      $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
      if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
          $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
          foreach (($sx->xpath('//m:si') ?: []) as $item) {
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $text = '';
            foreach (($item->xpath('.//m:t') ?: []) as $part) $text .= (string)$part;
            $shared[] = $text;
          }
        }
      }
      $sheets = product_import_xlsx_sheet_map($zip);
      $selected = $sheets[0];
      foreach ($sheets as $sheet) {
        $normalized = customer_import_normalize_key($sheet['name']);
        if (str_contains($normalized, 'CLIENT') || str_contains($normalized, 'PESSOA')) { $selected = $sheet; break; }
      }
      $sheetXml = $zip->getFromName((string)$selected['target']);
      if ($sheetXml === false) throw new RuntimeException('Não foi possível ler a aba de clientes da planilha.');
      $xml = @simplexml_load_string($sheetXml);
      if (!$xml) throw new RuntimeException('O XML interno da planilha está inválido.');
      $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
      $rows = [];
      foreach (($xml->xpath('//m:sheetData/m:row') ?: []) as $rowNode) {
        $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $row = [];
        foreach (($rowNode->xpath('./m:c') ?: []) as $cell) {
          $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
          preg_match('/^([A-Z]+)/i', (string)$cell['r'], $m);
          $col = product_import_excel_column_index($m[1] ?? 'A');
          $type = (string)$cell['t'];
          $value = '';
          if ($type === 'inlineStr') {
            foreach (($cell->xpath('./m:is//m:t') ?: []) as $part) $value .= (string)$part;
          } else {
            $values = $cell->xpath('./m:v');
            $raw = isset($values[0]) ? (string)$values[0] : '';
            if ($type === 's') $value = $shared[(int)$raw] ?? '';
            elseif ($type === 'b') $value = $raw === '1' ? '1' : '0';
            else $value = $raw;
          }
          $row[$col] = trim(product_import_clean_utf8($value));
        }
        if ($row) {
          ksort($row);
          $max = max(array_keys($row));
          $dense = [];
          for ($i=0; $i<=$max; $i++) $dense[] = $row[$i] ?? '';
          $rows[] = $dense;
        }
        if (count($rows) > 100001) throw new RuntimeException('A planilha excede o limite de 100.000 clientes. Divida o arquivo.');
      }
      return ['rows'=>$rows,'sheet'=>(string)$selected['name'],'sheets'=>array_column($sheets,'name')];
    } finally { $zip->close(); }
  }
}

if (!function_exists('customer_import_matrix')) {
  function customer_import_matrix(string $path, string $extension): array {
    $extension = strtolower($extension);
    if (in_array($extension, ['xlsx','xlsm'], true)) return customer_import_xlsx_matrix($path);
    if ($extension === 'csv') return product_import_csv_matrix($path);
    if ($extension === 'xls') throw new RuntimeException('O formato XLS antigo não é aceito. Salve novamente como XLSX.');
    throw new RuntimeException('Formato não permitido. Envie XLSX, XLSM ou CSV.');
  }
}

if (!function_exists('customer_import_find_header')) {
  function customer_import_find_header(array $matrix): array {
    $rows = (array)($matrix['rows'] ?? []);
    if (!$rows) throw new RuntimeException('A planilha está vazia.');
    $aliases = customer_import_aliases();
    $aliasLookup = [];
    foreach ($aliases as $field=>$names) foreach ($names as $name) $aliasLookup[$name] = $field;
    $bestIndex = 0; $bestScore = -1;
    $limit = min(20, count($rows));
    for ($i=0; $i<$limit; $i++) {
      $score=0; $seen=[];
      foreach ((array)$rows[$i] as $cell) {
        $key = customer_import_normalize_key($cell);
        if ($key !== '' && isset($aliasLookup[$key]) && empty($seen[$aliasLookup[$key]])) { $score++; $seen[$aliasLookup[$key]]=1; }
      }
      if ($score > $bestScore) { $bestScore=$score; $bestIndex=$i; }
    }
    $headers = [];
    foreach ((array)$rows[$bestIndex] as $idx=>$cell) {
      $label = trim(product_import_clean_utf8($cell));
      $headers[(int)$idx] = $label !== '' ? $label : ('Coluna ' . ($idx+1));
    }
    if (!$headers) throw new RuntimeException('Não foi possível identificar as colunas da planilha.');
    return ['header_index'=>$bestIndex,'header_row'=>$bestIndex+1,'headers'=>$headers,'score'=>$bestScore];
  }
}

if (!function_exists('customer_import_suggest_mapping')) {
  function customer_import_suggest_mapping(array $headers): array {
    $aliases = customer_import_aliases();
    $normHeaders=[];
    foreach ($headers as $idx=>$header) $normHeaders[(int)$idx]=customer_import_normalize_key($header);
    $out=[];
    foreach ($aliases as $field=>$names) {
      foreach ($normHeaders as $idx=>$normalized) {
        if ($normalized !== '' && in_array($normalized,$names,true)) { $out[$field]=(int)$idx; break; }
      }
    }
    return $out;
  }
}

if (!function_exists('customer_import_store_upload')) {
  function customer_import_store_upload(int $companyId, int $userId, array $file, string $sourceSystem = 'PROSPERO_EXCEL'): array {
    if (!in_array($sourceSystem, ['PROSPERO_EXCEL','CPLUS4'], true)) throw new RuntimeException('Origem da planilha de clientes inválida.');
    customer_import_cleanup($companyId);
    $error=(int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException($error===UPLOAD_ERR_INI_SIZE||$error===UPLOAD_ERR_FORM_SIZE?'O arquivo excede o limite de upload.':'Selecione uma planilha válida.');
    $size=(int)($file['size'] ?? 0);
    if ($size<=0) throw new RuntimeException('O arquivo está vazio.');
    if ($size>20*1024*1024) throw new RuntimeException('A planilha excede 20 MB.');
    $original=trim((string)($file['name'] ?? 'clientes.xlsx')) ?: 'clientes.xlsx';
    $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    if (!in_array($extension,['xlsx','xlsm','csv'],true)) throw new RuntimeException('Formato não permitido. Envie XLSX, XLSM ou CSV.');
    $token=bin2hex(random_bytes(16));
    $dir=customer_import_storage_root($companyId);
    $stored=$dir.'/'.$token.'.'.$extension;
    $tmp=(string)($file['tmp_name'] ?? '');
    if ($tmp==='' || (!is_uploaded_file($tmp) && !is_file($tmp))) throw new RuntimeException('Upload inválido.');
    if (is_uploaded_file($tmp)) { if (!@move_uploaded_file($tmp,$stored)) throw new RuntimeException('Não foi possível armazenar a planilha.'); }
    else { if (!@copy($tmp,$stored)) throw new RuntimeException('Não foi possível armazenar a planilha.'); }
    try {
      $matrix=customer_import_matrix($stored,$extension);
      $header=customer_import_find_header($matrix);
      $meta=['token'=>$token,'company_id'=>$companyId,'user_id'=>$userId,'source_system'=>$sourceSystem,'original_name'=>$original,'extension'=>$extension,'stored_path'=>$stored,'sheet'=>(string)($matrix['sheet']??'Planilha 1'),'header_row'=>(int)$header['header_row'],'created_at'=>date('c')];
      if (@file_put_contents($dir.'/'.$token.'.json',json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))===false) throw new RuntimeException('Não foi possível registrar o upload.');
      customer_import_matrix_cache_write($companyId,$token,$matrix);
      return $meta;
    } catch(Throwable $e) { @unlink($stored); @unlink($dir.'/'.$token.'.json'); @unlink($dir.'/'.$token.'.matrix.json'); throw $e; }
  }
}

if (!function_exists('customer_import_get_upload')) {
  function customer_import_get_upload(int $companyId, string $token): array {
    if (!preg_match('/^[a-f0-9]{32}$/',$token)) throw new RuntimeException('Arquivo de importação inválido.');
    $dir=customer_import_storage_root($companyId);
    $metaFile=$dir.'/'.$token.'.json';
    if (!is_file($metaFile)) throw new RuntimeException('A planilha expirou ou não foi localizada. Envie novamente.');
    $meta=json_decode((string)@file_get_contents($metaFile),true);
    if (!is_array($meta) || (int)($meta['company_id']??0)!==$companyId) throw new RuntimeException('Planilha inválida para esta empresa.');
    $path=(string)($meta['stored_path']??'');
    if ($path==='' || !is_file($path)) throw new RuntimeException('O arquivo temporário da planilha não foi localizado.');
    return $meta;
  }
}

if (!function_exists('customer_import_analysis')) {
  function customer_import_analysis(int $companyId,string $token): array {
    $meta=customer_import_get_upload($companyId,$token);
    $matrix=customer_import_matrix_cached($companyId,$meta);
    $header=customer_import_find_header($matrix);
    $headers=(array)$header['headers'];
    $suggested=customer_import_suggest_mapping($headers);
    return ['upload'=>$meta,'matrix'=>$matrix,'header_index'=>(int)$header['header_index'],'header_row'=>(int)$header['header_row'],'headers'=>$headers,'suggested_mapping'=>$suggested,'data_count'=>max(0,count((array)$matrix['rows'])-(int)$header['header_index']-1)];
  }
}

if (!function_exists('customer_import_mapping_from_post')) {
  function customer_import_mapping_from_post(array $post,array $suggested=[]): array {
    $mapping=[];
    $raw=(array)($post['column_map'] ?? []);
    foreach (customer_import_field_labels() as $field=>$_label) {
      $v=$raw[$field] ?? ($suggested[$field] ?? '');
      if ($v === '' || $v === null || (string)$v === '-1') continue;
      if (is_numeric($v) && (int)$v>=0) $mapping[$field]=(int)$v;
    }
    return $mapping;
  }
}

if (!function_exists('customer_import_value')) {
  function customer_import_value(array $row,array $mapping,string $field): string {
    if (!array_key_exists($field,$mapping)) return '';
    return customer_import_clean_text($row[(int)$mapping[$field]] ?? '');
  }
}

if (!function_exists('customer_import_type_key')) {
  function customer_import_type_key($value): string {
    $key=customer_import_normalize_key($value);
    return $key!==''?$key:'VAZIO';
  }
}

if (!function_exists('customer_import_common_type')) {
  function customer_import_common_type(string $raw): string {
    $key=customer_import_type_key($raw);
    if (in_array($key,['F','PF','FISICA','PESSOA_FISICA','PESSOAFISICA','FISICO'],true)) return 'FISICA';
    if (in_array($key,['J','PJ','JURIDICA','PESSOA_JURIDICA','PESSOAJURIDICA','JURIDICO'],true)) return 'JURIDICA';
    return '';
  }
}

if (!function_exists('customer_import_resolve_type')) {
  function customer_import_resolve_type(string $raw,string $document,array $typeMap,string $unknownAction='AUTO_DOCUMENTO'): string {
    $key=customer_import_type_key($raw);
    $mapped=strtoupper(trim((string)($typeMap[$key]??'AUTO')));
    if (in_array($mapped,['FISICA','JURIDICA','IGNORAR'],true)) return $mapped;
    $common=customer_import_common_type($raw);
    if ($common!=='') return $common;
    $digits=customer_import_digits($document,20);
    if (strlen($digits)===11) return 'FISICA';
    if (strlen($digits)===14) return 'JURIDICA';
    $unknownAction=strtoupper($unknownAction);
    return in_array($unknownAction,['FISICA','JURIDICA'],true)?$unknownAction:'IGNORAR';
  }
}

if (!function_exists('customer_import_type_stats')) {
  function customer_import_type_stats(array $analysis,array $mapping,int $maxValues=30): array {
    if (!isset($mapping['person_type'])) return [];
    $rows=(array)($analysis['matrix']['rows']??[]); $start=(int)$analysis['header_index']+1;
    $stats=[];
    for($i=$start,$total=count($rows);$i<$total;$i++){
      $row=(array)$rows[$i];
      $raw=customer_import_value($row,$mapping,'person_type');
      $key=customer_import_type_key($raw);
      if(!isset($stats[$key]))$stats[$key]=['key'=>$key,'raw'=>$raw,'count'=>0,'cpf'=>0,'cnpj'=>0,'other'=>0,'suggestion'=>'AUTO'];
      $stats[$key]['count']++;
      $doc=customer_import_digits(customer_import_value($row,$mapping,'document'),20);
      if(strlen($doc)===11)$stats[$key]['cpf']++;elseif(strlen($doc)===14)$stats[$key]['cnpj']++;else $stats[$key]['other']++;
    }
    foreach($stats as &$s){
      $common=customer_import_common_type((string)$s['raw']);
      if($common!=='')$s['suggestion']=$common;
      elseif((int)$s['cnpj']>(int)$s['cpf'] && (int)$s['cnpj']>=max(1,(int)ceil((int)$s['count']*.6)))$s['suggestion']='JURIDICA';
      elseif((int)$s['cpf']>(int)$s['cnpj'] && (int)$s['cpf']>=max(1,(int)ceil((int)$s['count']*.6)))$s['suggestion']='FISICA';
    }
    unset($s);
    uasort($stats,static fn($a,$b)=>(int)$b['count']<=>(int)$a['count']);
    return array_slice(array_values($stats),0,$maxValues);
  }
}

if (!function_exists('customer_import_duplicate_documents')) {
  function customer_import_duplicate_documents(array $analysis,array $mapping,int $limit=20): array {
    if (!isset($mapping['document'])) return ['count'=>0,'occurrences'=>0,'items'=>[]];
    $rows=(array)($analysis['matrix']['rows']??[]);$start=(int)$analysis['header_index']+1;$docs=[];
    for($i=$start,$total=count($rows);$i<$total;$i++){
      $doc=customer_import_digits(customer_import_value((array)$rows[$i],$mapping,'document'),20);
      if($doc==='')continue;
      if(!isset($docs[$doc]))$docs[$doc]=['document'=>$doc,'count'=>0,'lines'=>[]];
      $docs[$doc]['count']++;
      if(count($docs[$doc]['lines'])<8)$docs[$doc]['lines'][]=$i+1;
    }
    $dups=array_values(array_filter($docs,static fn($d)=>(int)$d['count']>1));
    usort($dups,static fn($a,$b)=>(int)$b['count']<=>(int)$a['count']);
    $occ=0;foreach($dups as $d)$occ+=(int)$d['count']-1;
    return ['count'=>count($dups),'occurrences'=>$occ,'items'=>array_slice($dups,0,max(1,$limit))];
  }
}

if (!function_exists('customer_import_excel_datetime')) {
  function customer_import_excel_datetime($value,bool $dateOnly=false): ?string {
    $text=trim((string)$value); if($text==='')return null;
    if(is_numeric($text)){
      $serial=(float)$text;
      if($serial>0 && $serial<100000){
        $seconds=(int)round($serial*86400);
        $ts=strtotime('1899-12-30 00:00:00 UTC')+$seconds;
        return gmdate($dateOnly?'Y-m-d':'Y-m-d H:i:s',$ts);
      }
    }
    $formats=['d/m/Y H:i:s','d/m/Y H:i','d/m/Y','Y-m-d H:i:s','Y-m-d','d-m-Y','m/d/Y'];
    foreach($formats as $format){$d=DateTime::createFromFormat('!'.$format,$text);if($d instanceof DateTime)return $d->format($dateOnly?'Y-m-d':'Y-m-d H:i:s');}
    $ts=strtotime($text); return $ts?date($dateOnly?'Y-m-d':'Y-m-d H:i:s',$ts):null;
  }
}

if (!function_exists('customer_import_city_ibge')) {
  function customer_import_city_ibge(PDO $pdo,string $city,string $uf,string $explicit=''): string {
    $explicit=customer_import_digits($explicit,7); if(strlen($explicit)===7)return $explicit;
    $city=trim($city);$uf=strtoupper(substr(trim($uf),0,2)); if($city===''||$uf==='')return '';
    try{
      $st=$pdo->prepare("SELECT ibge_code FROM fiscal_cities WHERE company_id=0 AND is_active=1 AND uf=? AND name=? LIMIT 1");
      $st->execute([$uf,$city]);$v=customer_import_digits($st->fetchColumn()?:'',7);return strlen($v)===7?$v:'';
    }catch(Throwable $e){return '';}
  }
}

if (!function_exists('customer_import_source_number')) {
  function customer_import_source_number(string $value): int {
    $text=product_import_excel_identifier($value);
    if($text===''||!ctype_digit($text))return 0;
    $n=(int)$text; return ($n>0&&$n<=2147483647)?$n:0;
  }
}

if (!function_exists('customer_import_source_code')) {
  function customer_import_source_code($value): string {
    $code=trim(product_import_excel_identifier($value));
    return customer_import_substr($code,60);
  }
}

if (!function_exists('customer_import_source_map_ready')) {
  function customer_import_source_map_ready(PDO $pdo): bool {
    try{$st=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='nexa_person_source_map' LIMIT 1");$st->execute();return (bool)$st->fetchColumn();}catch(Throwable $e){return false;}
  }
}

if (!function_exists('customer_import_source_map_person')) {
  function customer_import_source_map_person(PDO $pdo,int $companyId,string $sourceSystem,string $sourceCode): ?array {
    if($sourceCode===''||!customer_import_source_map_ready($pdo))return null;
    $st=$pdo->prepare('SELECT p.* FROM nexa_person_source_map m INNER JOIN finance_people p ON p.company_id=m.company_id AND p.id=m.person_id WHERE m.company_id=? AND m.source_system=? AND m.source_code=? LIMIT 1');
    $st->execute([$companyId,$sourceSystem,$sourceCode]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
  }
}

if (!function_exists('customer_import_source_map_upsert')) {
  function customer_import_source_map_upsert(PDO $pdo,int $companyId,string $sourceSystem,string $sourceCode,int $personId,string $sourceDocument='',string $sourceName=''): void {
    if($sourceCode===''||$personId<=0)return;
    if(!customer_import_source_map_ready($pdo))throw new RuntimeException('A estrutura de relacionamento de códigos de origem ainda não foi aplicada. Execute a migration V11.45 pelo Atualizador do Banco.');
    $st=$pdo->prepare('INSERT INTO nexa_person_source_map(company_id,source_system,source_code,person_id,source_document,source_name,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE person_id=VALUES(person_id),source_document=VALUES(source_document),source_name=VALUES(source_name),updated_at=NOW()');
    $st->execute([$companyId,$sourceSystem,$sourceCode,$personId,$sourceDocument?:null,$sourceName?:null]);
  }
}

if (!function_exists('customer_import_ignored_codes')) {
  function customer_import_ignored_codes($value): array {
    $parts=preg_split('/[\s,;|]+/',trim((string)$value))?:[];$out=[];
    foreach($parts as $part){$code=customer_import_source_code($part);if($code==='')continue;$out[$code]=true;$canon=ltrim($code,'0');if($canon==='')$canon='0';$out['#'.$canon]=true;}
    return $out;
  }
}

if (!function_exists('customer_import_code_is_ignored')) {
  function customer_import_code_is_ignored(string $code,array $ignored): bool {
    if($code==='')return false;if(isset($ignored[$code]))return true;$canon=ltrim($code,'0');if($canon==='')$canon='0';return isset($ignored['#'.$canon]);
  }
}

if (!function_exists('customer_import_preview')) {
  function customer_import_preview(PDO $pdo,int $companyId,array $analysis,array $mapping,array $typeMap,string $unknownAction='AUTO_DOCUMENTO',int $limit=30): array {
    $rows=(array)($analysis['matrix']['rows']??[]);$start=(int)$analysis['header_index']+1;$out=[];
    for($i=$start,$total=count($rows);$i<$total&&count($out)<$limit;$i++){
      $source=(array)$rows[$i];
      $name=customer_import_value($source,$mapping,'name');$docRaw=customer_import_value($source,$mapping,'document');
      if($name===''&&$docRaw==='')continue;
      $doc=customer_import_digits($docRaw,30);
      $type=customer_import_resolve_type(customer_import_value($source,$mapping,'person_type'),$doc,$typeMap,$unknownAction);
      $city=customer_import_value($source,$mapping,'city');$uf=strtoupper(substr(customer_import_value($source,$mapping,'state'),0,2));
      $out[]=[
        'line'=>$i+1,'source_code'=>customer_import_value($source,$mapping,'source_code'),'name'=>$name,'fantasy_name'=>customer_import_value($source,$mapping,'fantasy_name'),
        'document'=>$doc,'person_type'=>$type,'phone'=>customer_import_digits(customer_import_value($source,$mapping,'phone'),30),'whatsapp'=>customer_import_digits(customer_import_value($source,$mapping,'whatsapp'),30),
        'city'=>$city,'state'=>$uf,'city_ibge'=>customer_import_city_ibge($pdo,$city,$uf,customer_import_value($source,$mapping,'city_ibge')),
      ];
    }
    return $out;
  }
}

if (!function_exists('customer_import_execute')) {
  function customer_import_execute(PDO $pdo,int $companyId,array $analysis,array $mapping,array $typeMap,array $options=[]): array {
    if(!isset($mapping['name']))throw new RuntimeException('Vincule a coluna Nome / Razão social antes de importar.');
    $createNew=!empty($options['create_new']);$updateExisting=!empty($options['update_existing']);$useSourceNumber=!empty($options['use_source_number']);
    $sourceSystem=(string)($analysis['upload']['source_system'] ?? 'PROSPERO_EXCEL');
    if (!in_array($sourceSystem, ['PROSPERO_EXCEL','CPLUS4'], true)) throw new RuntimeException('Origem de clientes inválida no arquivo enviado.');
    $ignoredCodes=customer_import_ignored_codes($options['ignore_source_codes']??'');
    $unknownAction=(string)($options['unknown_type_action']??'AUTO_DOCUMENTO');
    $hasNumero=company_sequence_has_column($pdo,'pessoas');
    $rows=(array)($analysis['matrix']['rows']??[]);$dataStart=(int)$analysis['header_index']+1;$total=count($rows);$dataTotal=max(0,$total-$dataStart);
    $offset=max(0,(int)($options['offset']??0));if($offset>$dataTotal)$offset=$dataTotal;
    $maxRows=max(0,(int)($options['max_rows']??0));$maxSeconds=max(0.0,(float)($options['max_seconds']??0));$startedAt=microtime(true);$processed=0;
    $start=$dataStart+$offset;
    $result=['inserted'=>0,'updated'=>0,'skipped'=>0,'ignored_code'=>0,'mapped'=>0,'errors'=>0,'messages'=>[],'fisica'=>0,'juridica'=>0,'offset'=>$offset,'processed'=>0,'next_offset'=>$offset,'data_total'=>$dataTotal,'done'=>false];
    for($i=$start;$i<$total;$i++){
      if($maxRows>0 && $processed>=$maxRows)break;
      if($maxSeconds>0 && $processed>0 && (microtime(true)-$startedAt)>=$maxSeconds)break;
      $processed++;
      $source=(array)$rows[$i];
      $name=customer_import_clean_text(customer_import_value($source,$mapping,'name'),160);
      $docRaw=customer_import_value($source,$mapping,'document');$document=customer_import_digits($docRaw,30);
      if($name===''&&$document===''){continue;}
      if($name===''){ $result['errors']++;$result['messages'][]='Linha '.($i+1).': nome/razão social vazio.';continue; }
      $personType=customer_import_resolve_type(customer_import_value($source,$mapping,'person_type'),$document,$typeMap,$unknownAction);
      if($personType==='IGNORAR'){ $result['skipped']++;continue; }
      $sourceCode=customer_import_source_code(customer_import_value($source,$mapping,'source_code'));
      if(customer_import_code_is_ignored($sourceCode,$ignoredCodes)){$result['skipped']++;$result['ignored_code']++;continue;}
      $sourceNumber=customer_import_source_number($sourceCode);
      $matchOrigin='';
      $existing=customer_import_source_map_person($pdo,$companyId,$sourceSystem,$sourceCode);
      if($existing)$matchOrigin='MAPA';
      if(!$existing && $document!==''){
        $st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND document=? ORDER BY active DESC,id ASC LIMIT 1');$st->execute([$companyId,$document]);$existing=$st->fetch(PDO::FETCH_ASSOC)?:null;if($existing)$matchOrigin='DOCUMENTO';
      }
      if(!$existing && $sourceSystem!=='CPLUS4' && $hasNumero && $sourceNumber>0){$st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND numero_cadastro=? LIMIT 1');$st->execute([$companyId,$sourceNumber]);$existing=$st->fetch(PDO::FETCH_ASSOC)?:null;if($existing)$matchOrigin='NUMERO';}
      if($existing){
        $existingDoc=customer_import_digits((string)($existing['document']??''),30);
        if($document!=='' && $existingDoc!=='' && $existingDoc!==$document)throw new RuntimeException('Linha '.($i+1).': o código de origem '.$sourceCode.' já está ligado a uma pessoa com CPF/CNPJ diferente. Importação bloqueada para evitar vínculo incorreto.');
        if($document!==''){
          $st=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND document=? ORDER BY active DESC,id ASC LIMIT 1');$st->execute([$companyId,$document]);$docOwner=(int)($st->fetchColumn()?:0);
          if($docOwner>0 && $docOwner!==(int)$existing['id'])throw new RuntimeException('Linha '.($i+1).': o código de origem e o CPF/CNPJ apontam para pessoas diferentes. Revise antes de importar.');
        }
        if($matchOrigin==='NUMERO' && $existingDoc===''){
          $existingName=customer_import_normalize_key((string)($existing['name']??''));$sourceName=customer_import_normalize_key($name);
          if($existingName!==''&&$sourceName!==''&&$existingName!==$sourceName)throw new RuntimeException('Linha '.($i+1).': o nº de cadastro '.$sourceNumber.' já pertence a "'.(string)($existing['name']??'').'" e o cadastro existente não possui CPF/CNPJ para confirmar que é a mesma pessoa. Revise antes de importar.');
        }
      }
      if($existing && !$updateExisting){
        try{
          if($sourceCode!==''){
            if(!$pdo->inTransaction())$pdo->beginTransaction();
            customer_import_source_map_upsert($pdo,$companyId,$sourceSystem,$sourceCode,(int)$existing['id'],$document,$name);
            $result['mapped']++;
            if($pdo->inTransaction())$pdo->commit();
          }
          $result['skipped']++;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$result['errors']++;$result['messages'][]='Linha '.($i+1).': '.$e->getMessage();}
        continue;
      }
      if(!$existing && !$createNew){$result['skipped']++;continue;}
      $fantasy=$personType==='JURIDICA'?customer_import_clean_text(customer_import_value($source,$mapping,'fantasy_name'),160):'';
      $stateReg=strtoupper(customer_import_clean_text(customer_import_value($source,$mapping,'state_registration'),30));
      if($stateReg!==''&&$stateReg!=='ISENTO')$stateReg=(string)(preg_replace('/[^0-9A-Z]+/','',$stateReg)??'');
      $municipal=customer_import_clean_text(customer_import_value($source,$mapping,'municipal_registration'),30);
      $identity=customer_import_clean_text(customer_import_value($source,$mapping,'identity_document'),40);
      $issuer=customer_import_clean_text(customer_import_value($source,$mapping,'identity_issuer'),40);
      $identityState=strtoupper(substr(customer_import_clean_text(customer_import_value($source,$mapping,'identity_state'),2),0,2));
      $birth=customer_import_excel_datetime(customer_import_value($source,$mapping,'birth_opening_date'),true);
      $email=strtolower(customer_import_clean_text(customer_import_value($source,$mapping,'email'),160));
      $phone=customer_import_digits(customer_import_value($source,$mapping,'phone'),40);
      $whatsapp=customer_import_digits(customer_import_value($source,$mapping,'whatsapp'),40); if($whatsapp===''&&$phone!=='')$whatsapp=$phone;
      $zip=customer_import_digits(customer_import_value($source,$mapping,'zip_code'),20);
      $address=customer_import_clean_text(customer_import_value($source,$mapping,'address'),160);
      $number=customer_import_clean_text(customer_import_value($source,$mapping,'number'),30);
      $complement=customer_import_clean_text(customer_import_value($source,$mapping,'complement'),120);
      $district=customer_import_clean_text(customer_import_value($source,$mapping,'district'),100);
      $city=customer_import_clean_text(customer_import_value($source,$mapping,'city'),100);
      $state=strtoupper(substr(customer_import_clean_text(customer_import_value($source,$mapping,'state'),2),0,2));
      $cityIbge=customer_import_city_ibge($pdo,$city,$state,customer_import_value($source,$mapping,'city_ibge'));
      $notes=customer_import_clean_text(customer_import_value($source,$mapping,'notes'));
      $createdAt=customer_import_excel_datetime(customer_import_value($source,$mapping,'created_at'),false);
      try{
        if(!$pdo->inTransaction())$pdo->beginTransaction();
        if($existing){
          $sets=['is_customer=1','active=1','person_type=?','updated_at=NOW()'];$params=[$personType];
          $fields=['name'=>$name,'fantasy_name'=>$fantasy,'document'=>$document,'state_registration'=>$stateReg,'municipal_registration'=>$municipal,'identity_document'=>$identity,'identity_issuer'=>$issuer,'identity_state'=>$identityState,'birth_opening_date'=>$birth,'email'=>$email,'phone'=>$phone,'whatsapp'=>$whatsapp,'zip_code'=>$zip,'address'=>$address,'number'=>$number,'complement'=>$complement,'district'=>$district,'city'=>$city,'city_ibge'=>$cityIbge,'state'=>$state,'notes'=>$notes];
          foreach($fields as $col=>$val){if($val===null||$val==='')continue;$sets[]="`{$col}`=?";$params[]=$val;}
          $params[]=$companyId;$params[]=(int)$existing['id'];
          $pdo->prepare('UPDATE finance_people SET '.implode(',',$sets).' WHERE company_id=? AND id=?')->execute($params);
          $personId=(int)$existing['id'];$result['updated']++;
        }else{
          $cols=['company_id'];$vals=['?'];$params=[$companyId];
          if($hasNumero){
            $numberCandidate=0;
            if($useSourceNumber&&$sourceNumber>0){$st=$pdo->prepare('SELECT 1 FROM finance_people WHERE company_id=? AND numero_cadastro=? LIMIT 1');$st->execute([$companyId,$sourceNumber]);if(!$st->fetchColumn())$numberCandidate=$sourceNumber;}
            if($numberCandidate<=0)$numberCandidate=company_sequence_next($pdo,$companyId,'pessoas');
            $cols[]='numero_cadastro';$vals[]='?';$params[]=$numberCandidate;
          }
          $insert=[
            'name'=>$name,'fantasy_name'=>$fantasy,'document'=>$document?:null,'state_registration'=>$stateReg?:null,'municipal_registration'=>$municipal?:null,
            'identity_document'=>$identity?:null,'identity_issuer'=>$issuer?:null,'identity_state'=>$identityState?:null,'person_type'=>$personType,'birth_opening_date'=>$birth,
            'email'=>$email?:null,'phone'=>$phone?:null,'whatsapp'=>$whatsapp?:null,'zip_code'=>$zip?:null,'address'=>$address?:null,'number'=>$number?:null,'complement'=>$complement?:null,
            'district'=>$district?:null,'city'=>$city?:null,'city_ibge'=>$cityIbge?:null,'state'=>$state?:null,'is_customer'=>1,'is_supplier'=>0,'is_seller'=>0,'is_employee'=>0,'is_professional'=>0,'is_carrier'=>0,'active'=>1,'notes'=>$notes?:null,
          ];
          foreach($insert as $col=>$val){$cols[]=$col;$vals[]='?';$params[]=$val;}
          if($createdAt!==null){$cols[]='created_at';$vals[]='?';$params[]=$createdAt;}
          $sql='INSERT INTO finance_people ('.implode(',',$cols).') VALUES ('.implode(',',$vals).')';
          $pdo->prepare($sql)->execute($params);$personId=(int)$pdo->lastInsertId();$result['inserted']++;
        }
        if($sourceCode!==''){customer_import_source_map_upsert($pdo,$companyId,$sourceSystem,$sourceCode,$personId,$document,$name);$result['mapped']++;}
        // Mantém o endereço principal disponível também no seletor de endereços, quando a estrutura já existir.
        try{
          if($personId>0 && ($address!==''||$district!=='') && function_exists('finance_table_exists') && finance_table_exists($pdo,'gestao_customer_addresses')){
            $st=$pdo->prepare('SELECT id FROM gestao_customer_addresses WHERE company_id=? AND person_id=? AND active=1 AND is_default=1 LIMIT 1');$st->execute([$companyId,$personId]);$addrId=(int)($st->fetchColumn()?:0);
            if($addrId>0){$pdo->prepare('UPDATE gestao_customer_addresses SET label="Principal",zip_code=?,address=?,number=?,complement=?,district=?,city=?,city_ibge=?,state=?,active=1,is_default=1,updated_at=NOW() WHERE company_id=? AND person_id=? AND id=?')->execute([$zip?:null,$address?:null,$number?:null,$complement?:null,$district?:null,$city?:null,$cityIbge?:null,$state?:null,$companyId,$personId,$addrId]);}
            else{$pdo->prepare('INSERT INTO gestao_customer_addresses(company_id,person_id,label,zip_code,address,number,complement,district,city,city_ibge,state,is_default,active,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,1,NOW())')->execute([$companyId,$personId,'Principal',$zip?:null,$address?:null,$number?:null,$complement?:null,$district?:null,$city?:null,$cityIbge?:null,$state?:null]);}
          }
        }catch(Throwable $ignored){}
        $pdo->commit();
        if($personType==='JURIDICA')$result['juridica']++;else$result['fisica']++;
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$result['errors']++;if(count($result['messages'])<30)$result['messages'][]='Linha '.($i+1).': '.$e->getMessage();}
    }
    $result['processed']=$processed;
    $result['next_offset']=min($dataTotal,$offset+$processed);
    $result['done']=$result['next_offset']>=$dataTotal;
    $result['elapsed_seconds']=round(microtime(true)-$startedAt,3);
    return $result;
  }
}
