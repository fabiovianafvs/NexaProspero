<?php
declare(strict_types=1);

/** V11.45 - Importação auditável de Contas a Receber por planilha. */
require_once __DIR__ . '/customer_import_module.php';
require_once __DIR__ . '/finance_module.php';

if (!function_exists('receivable_import_schema_ready')) {
  function receivable_import_schema_ready(PDO $pdo): bool {
    foreach (['nexa_person_source_map','nexa_finance_import_batches','nexa_finance_import_receivable_map'] as $table) {
      try{$st=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');$st->execute([$table]);if(!$st->fetchColumn())return false;}catch(Throwable $e){return false;}
    }
    return true;
  }
}

if (!function_exists('receivable_import_storage_root')) {
  function receivable_import_storage_root(int $companyId): string {
    $base=dirname(__DIR__).'/storage/importacoes_receber';
    if(!is_dir($base)&&!@mkdir($base,0775,true)&&!is_dir($base))throw new RuntimeException('Não foi possível criar a pasta segura da importação de Contas a Receber.');
    $deny=$base.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\nDeny from all\n");
    $dir=$base.'/'.max(1,$companyId);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível preparar a pasta da empresa.');
    return $dir;
  }
}

if (!function_exists('receivable_import_cleanup')) {
  function receivable_import_cleanup(int $companyId): void {
    $dir=receivable_import_storage_root($companyId);$limit=time()-172800;
    foreach((array)glob($dir.'/*') as $file)if(is_file($file)&&@filemtime($file)<$limit)@unlink($file);
  }
}

if (!function_exists('receivable_import_aliases')) {
  function receivable_import_aliases(): array {
    return [
      'source_code'=>['PESSOA_CODIGO','CODIGO_CLIENTE','COD_CLIENTE','CLIENTE_CODIGO','CODIGO'],
      'source_receivable_id'=>['ID_TITULO_ORIGEM','CODCR','CODIGO_TITULO_ORIGEM'],
      'issue_date'=>['DATA_DE_EMISSAO','DATA_EMISSAO','EMISSAO'],
      'due_date'=>['DATA_DE_VENCIMENTO','DATA_VENCIMENTO','VENCIMENTO'],
      'document_number'=>['NUMERO_DO_DOCUMENTO','NUMERO_DOCUMENTO','DOCUMENTO','NUM_DOC'],
      'history'=>['HISTORICO','DESCRICAO','CLIENTE','NOME'],
      'installment'=>['PARCELA','NUMERO_PARCELA'],
      'nature'=>['NATUREZA_DE_LANCAMENTO_NOME','NATUREZA_LANCAMENTO_NOME','NATUREZA'],
      'amount'=>['VALOR','VALOR_ORIGINAL','VALOR_TITULO'],
      'open_amount'=>['VALOR_EM_ABERTO','SALDO','SALDO_ABERTO','VALOR_ABERTO'],
      'notes'=>['OBSERVACOES','OBSERVACAO','OBS','NOTAS'],
    ];
  }
}

if (!function_exists('receivable_import_find_header')) {
  function receivable_import_find_header(array $matrix): array {
    $aliases=receivable_import_aliases();$best=null;
    foreach(array_slice($matrix,0,30,true) as $idx=>$row){$normalized=[];foreach((array)$row as $col=>$value)$normalized[(int)$col]=customer_import_normalize_key($value);$score=0;foreach($aliases as $names)foreach($normalized as $v)if($v!==''&&in_array($v,$names,true)){$score++;break;}if($best===null||$score>$best['score'])$best=['index'=>(int)$idx,'score'=>$score,'headers'=>(array)$row];}
    if(!$best||$best['score']<4)throw new RuntimeException('Não foi possível identificar o cabeçalho do Contas a Receber. Confira as colunas Pessoa_Codigo, datas, Valor e Valor Em Aberto.');
    return ['header_index'=>$best['index'],'header_row'=>$best['index']+1,'headers'=>$best['headers']];
  }
}

if (!function_exists('receivable_import_suggest_mapping')) {
  function receivable_import_suggest_mapping(array $headers): array {
    $out=[];$aliases=receivable_import_aliases();
    foreach($aliases as $field=>$names){foreach($headers as $idx=>$header){$key=customer_import_normalize_key($header);if($key!==''&&in_array($key,$names,true)){$out[$field]=(int)$idx;break;}}}
    return $out;
  }
}

if (!function_exists('receivable_import_store_upload')) {
  function receivable_import_store_upload(int $companyId,int $userId,array $file,string $sourceSystem='PROSPERO_EXCEL'): array {
    if (!in_array($sourceSystem,['PROSPERO_EXCEL','CPLUS4'],true)) throw new RuntimeException('Origem da planilha financeira inválida.');
    receivable_import_cleanup($companyId);$error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error!==UPLOAD_ERR_OK)throw new RuntimeException($error===UPLOAD_ERR_INI_SIZE||$error===UPLOAD_ERR_FORM_SIZE?'O arquivo excede o limite de upload.':'Selecione uma planilha válida.');
    $size=(int)($file['size']??0);if($size<=0)throw new RuntimeException('O arquivo está vazio.');if($size>20*1024*1024)throw new RuntimeException('A planilha excede 20 MB.');
    $original=trim((string)($file['name']??'contas_receber.xlsx'))?:'contas_receber.xlsx';$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));if(!in_array($ext,['xlsx','xlsm','csv'],true))throw new RuntimeException('Formato não permitido. Envie XLSX, XLSM ou CSV.');
    $token=bin2hex(random_bytes(16));$dir=receivable_import_storage_root($companyId);$stored=$dir.'/'.$token.'.'.$ext;$tmp=(string)($file['tmp_name']??'');
    if($tmp===''||(!is_uploaded_file($tmp)&&!is_file($tmp)))throw new RuntimeException('Upload inválido.');
    if(is_uploaded_file($tmp)){if(!@move_uploaded_file($tmp,$stored))throw new RuntimeException('Não foi possível armazenar a planilha.');}elseif(!@copy($tmp,$stored))throw new RuntimeException('Não foi possível armazenar a planilha.');
    try{$matrix=customer_import_matrix($stored,$ext);$header=receivable_import_find_header((array)$matrix['rows']);$meta=['token'=>$token,'company_id'=>$companyId,'user_id'=>$userId,'source_system'=>$sourceSystem,'original_name'=>$original,'extension'=>$ext,'stored_path'=>$stored,'sheet'=>(string)($matrix['sheet']??'Planilha 1'),'header_row'=>(int)$header['header_row'],'sha256'=>hash_file('sha256',$stored),'created_at'=>date('c')];if(@file_put_contents($dir.'/'.$token.'.json',json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))===false)throw new RuntimeException('Não foi possível registrar o upload.');return $meta;}catch(Throwable $e){@unlink($stored);@unlink($dir.'/'.$token.'.json');throw $e;}
  }
}

if (!function_exists('receivable_import_get_upload')) {
  function receivable_import_get_upload(int $companyId,string $token): array {
    if(!preg_match('/^[a-f0-9]{32}$/',$token))throw new RuntimeException('Arquivo de importação inválido.');$dir=receivable_import_storage_root($companyId);$metaFile=$dir.'/'.$token.'.json';if(!is_file($metaFile))throw new RuntimeException('A planilha expirou ou não foi localizada. Envie novamente.');$meta=json_decode((string)@file_get_contents($metaFile),true);if(!is_array($meta)||(int)($meta['company_id']??0)!==$companyId)throw new RuntimeException('Planilha inválida para esta empresa.');$path=(string)($meta['stored_path']??'');if($path===''||!is_file($path))throw new RuntimeException('Arquivo temporário não localizado.');return $meta;
  }
}

if (!function_exists('receivable_import_analysis')) {
  function receivable_import_analysis(int $companyId,string $token): array {
    $meta=receivable_import_get_upload($companyId,$token);$matrix=customer_import_matrix((string)$meta['stored_path'],(string)$meta['extension']);$header=receivable_import_find_header((array)$matrix['rows']);$headers=(array)$header['headers'];return ['upload'=>$meta,'matrix'=>$matrix,'header_index'=>(int)$header['header_index'],'header_row'=>(int)$header['header_row'],'headers'=>$headers,'mapping'=>receivable_import_suggest_mapping($headers),'data_count'=>max(0,count((array)$matrix['rows'])-(int)$header['header_index']-1)];
  }
}

if (!function_exists('receivable_import_value')) {
  function receivable_import_value(array $row,array $mapping,string $field): string {if(!array_key_exists($field,$mapping))return '';return customer_import_clean_text($row[(int)$mapping[$field]]??'');}
}

if (!function_exists('receivable_import_money')) {
  function receivable_import_money($value): float {return round((float)product_import_decimal($value),2);}
}

if (!function_exists('receivable_import_prepare_rows')) {
  function receivable_import_prepare_rows(array $analysis): array {
    $mapping=(array)($analysis['mapping']??[]);foreach(['source_code','issue_date','due_date','document_number','amount','open_amount'] as $required)if(!isset($mapping[$required]))throw new RuntimeException('Coluna obrigatória não encontrada: '.$required.'.');
    $sourceSystem=(string)($analysis['upload']['source_system']??'PROSPERO_EXCEL');
    $useStableKey=$sourceSystem==='CPLUS4';
    if($useStableKey&&!isset($mapping['source_receivable_id']))throw new RuntimeException('No CPlus 4, a coluna ID_TITULO_ORIGEM (CODCR) é obrigatória para impedir títulos duplicados.');
    $rows=(array)($analysis['matrix']['rows']??[]);$start=(int)$analysis['header_index']+1;$prepared=[];$occ=[];$errors=[];$seenOriginIds=[];
    for($i=$start,$total=count($rows);$i<$total;$i++){
      $r=(array)$rows[$i];$sourceReceivableId=trim(receivable_import_value($r,$mapping,'source_receivable_id'));$code=customer_import_source_code(receivable_import_value($r,$mapping,'source_code'));$amount=receivable_import_money(receivable_import_value($r,$mapping,'amount'));$open=receivable_import_money(receivable_import_value($r,$mapping,'open_amount'));
      $doc=customer_import_substr(receivable_import_value($r,$mapping,'document_number'),60);$issue=customer_import_excel_datetime(receivable_import_value($r,$mapping,'issue_date'),true);$due=customer_import_excel_datetime(receivable_import_value($r,$mapping,'due_date'),true);
      $history=customer_import_substr(receivable_import_value($r,$mapping,'history'),220);$installment=customer_import_substr(receivable_import_value($r,$mapping,'installment'),20);$nature=customer_import_substr(receivable_import_value($r,$mapping,'nature'),180);$notes=customer_import_clean_text(receivable_import_value($r,$mapping,'notes'));
      if($code===''&&$doc===''&&abs($amount)<0.0001&&abs($open)<0.0001)continue;
      $line=$i+1;$rowErrors=[];if($code==='')$rowErrors[]='código do cliente vazio';if(!$due)$rowErrors[]='vencimento inválido';if($amount<=0)$rowErrors[]='valor deve ser maior que zero';if($open<0)$rowErrors[]='valor em aberto negativo';if($open-$amount>0.01)$rowErrors[]='valor em aberto maior que o valor original';if($useStableKey){
        if($sourceReceivableId==='')$rowErrors[]='ID_TITULO_ORIGEM vazio';
        elseif(isset($seenOriginIds[$sourceReceivableId]))$rowErrors[]='ID_TITULO_ORIGEM repetido (linha '.$seenOriginIds[$sourceReceivableId].')';
        else $seenOriginIds[$sourceReceivableId]=$line;
      }
      if($rowErrors){$errors[]='Linha '.$line.': '.implode(', ',$rowErrors).'.';continue;}
      $paid=max(0,round($amount-$open,2));$base=hash('sha256',json_encode([$code,$issue,$due,$doc,$history,$installment,$nature,number_format($amount,2,'.',''),number_format($open,2,'.',''),$notes],JSON_UNESCAPED_UNICODE));$n=($occ[$base]??0)+1;$occ[$base]=$n;
      // CPlus 4: o CODCR permanece estável mesmo após alteração do saldo/observação.
      $key=$useStableKey?hash('sha256','CPLUS4|CODCR|'.$sourceReceivableId):hash('sha256',$base.'#'.$n);
      $prepared[]=['line'=>$line,'source_code'=>$code,'issue_date'=>$issue,'due_date'=>$due,'document_number'=>$doc,'history'=>$history,'installment'=>$installment,'nature'=>$nature,'amount'=>$amount,'open_amount'=>$open,'paid_amount'=>$paid,'notes'=>$notes,'row_digest'=>$base,'occurrence'=>$n,'source_key'=>$key,'source_receivable_id'=>$sourceReceivableId];
    }
    return ['rows'=>$prepared,'errors'=>$errors];
  }
}

if (!function_exists('receivable_import_prefetch_people')) {
  function receivable_import_prefetch_people(PDO $pdo,int $companyId,string $sourceSystem,array $codes): array {
    $codes=array_values(array_unique(array_filter(array_map('strval',$codes))));$out=[];foreach(array_chunk($codes,800) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$st=$pdo->prepare("SELECT m.source_code,m.person_id,p.name FROM nexa_person_source_map m INNER JOIN finance_people p ON p.company_id=m.company_id AND p.id=m.person_id WHERE m.company_id=? AND m.source_system=? AND m.source_code IN ($ph)");$st->execute(array_merge([$companyId,$sourceSystem],$chunk));foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r)$out[(string)$r['source_code']]=$r;}return $out;
  }
}

if (!function_exists('receivable_import_existing_keys')) {
  function receivable_import_existing_keys(PDO $pdo,int $companyId,string $sourceSystem,array $keys): array {
    $keys=array_values(array_unique($keys));$out=[];foreach(array_chunk($keys,800) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$st=$pdo->prepare("SELECT source_key,receivable_id FROM nexa_finance_import_receivable_map WHERE company_id=? AND source_system=? AND source_key IN ($ph)");$st->execute(array_merge([$companyId,$sourceSystem],$chunk));foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r)$out[(string)$r['source_key']]=(int)$r['receivable_id'];}return $out;
  }
}

if (!function_exists('receivable_import_preflight')) {
  function receivable_import_preflight(PDO $pdo,int $companyId,array $analysis,string $sourceSystem='PROSPERO_EXCEL',int $previewLimit=40): array {
    $sourceSystem=(string)($analysis['upload']['source_system'] ?? 'PROSPERO_EXCEL');
    if(!in_array($sourceSystem,['PROSPERO_EXCEL','CPLUS4'],true))throw new RuntimeException('Origem financeira inválida.');
    if(!receivable_import_schema_ready($pdo))throw new RuntimeException('A migration V11.45 ainda não foi aplicada. Execute o Atualizador do Banco antes de validar o Contas a Receber.');
    $prepared=receivable_import_prepare_rows($analysis);$rows=(array)$prepared['rows'];$people=receivable_import_prefetch_people($pdo,$companyId,$sourceSystem,array_column($rows,'source_code'));$existing=receivable_import_existing_keys($pdo,$companyId,$sourceSystem,array_column($rows,'source_key'));
    $stats=['rows_total'=>count($rows)+count((array)$prepared['errors']),'rows_valid'=>count($rows),'rows_errors'=>count((array)$prepared['errors']),'unresolved'=>0,'already_imported'=>0,'new_rows'=>0,'customers'=>0,'amount_total'=>0.0,'paid_total'=>0.0,'open_total'=>0.0];$customerSet=[];$preview=[];$unresolved=[];
    foreach($rows as $row){$stats['amount_total']+=($row['amount']??0);$stats['paid_total']+=($row['paid_amount']??0);$stats['open_total']+=($row['open_amount']??0);$customerSet[$row['source_code']]=true;$person=$people[$row['source_code']]??null;if(!$person){$stats['unresolved']++;if(count($unresolved)<50)$unresolved[]=['line'=>$row['line'],'source_code'=>$row['source_code'],'document_number'=>$row['document_number'],'history'=>$row['history']];}if(isset($existing[$row['source_key']]))$stats['already_imported']++;else$stats['new_rows']++;if(count($preview)<$previewLimit)$preview[]=$row+['person_id'=>(int)($person['person_id']??0),'person_name'=>(string)($person['name']??''),'already_imported'=>isset($existing[$row['source_key']])];}
    $stats['customers']=count($customerSet);foreach(['amount_total','paid_total','open_total'] as $k)$stats[$k]=round((float)$stats[$k],2);
    return ['stats'=>$stats,'preview'=>$preview,'unresolved'=>$unresolved,'errors'=>array_slice((array)$prepared['errors'],0,50),'rows'=>$rows,'people'=>$people,'existing'=>$existing,'ready'=>$stats['rows_errors']===0&&$stats['unresolved']===0&&$stats['rows_valid']>0];
  }
}

if (!function_exists('receivable_import_assert_chart_account')) {
  function receivable_import_assert_chart_account(PDO $pdo,int $companyId,int $id): array {
    $st=$pdo->prepare('SELECT id,code,name FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 AND is_analytic=1 LIMIT 1');$st->execute([$companyId,$id]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Selecione uma conta analítica ativa do Plano de Contas para os títulos importados.');return $row;
  }
}

if (!function_exists('receivable_import_execute')) {
  function receivable_import_execute(PDO $pdo,int $companyId,array $analysis,int $chartAccountId,int $costCenterId,int $userId,string $sourceSystem='PROSPERO_EXCEL'): array {
    $sourceSystem=(string)($analysis['upload']['source_system'] ?? 'PROSPERO_EXCEL');
    if(!in_array($sourceSystem,['PROSPERO_EXCEL','CPLUS4'],true))throw new RuntimeException('Origem financeira inválida.');
    if(!receivable_import_schema_ready($pdo))throw new RuntimeException('A migration V11.45 ainda não foi aplicada.');receivable_import_assert_chart_account($pdo,$companyId,$chartAccountId);$pre=receivable_import_preflight($pdo,$companyId,$analysis,$sourceSystem,10);if(!$pre['ready'])throw new RuntimeException('A importação foi bloqueada: existem linhas inválidas ou códigos de clientes sem relacionamento. Corrija a prévia primeiro.');
    $meta=(array)($analysis['upload']??[]);$stats=(array)$pre['stats'];$st=$pdo->prepare("INSERT INTO nexa_finance_import_batches(company_id,import_type,source_system,original_file_name,source_file_sha256,status,rows_total,rows_valid,amount_total,paid_total,open_total,created_by_user_id,started_at) VALUES(?, 'RECEIVABLES', ?, ?, ?, 'RUNNING', ?, ?, ?, ?, ?, ?, NOW())");$st->execute([$companyId,$sourceSystem,(string)($meta['original_name']??''),(string)($meta['sha256']??''),(int)$stats['rows_total'],(int)$stats['rows_valid'],(float)$stats['amount_total'],(float)$stats['paid_total'],(float)$stats['open_total'],$userId?:null]);$batchId=(int)$pdo->lastInsertId();
    $imported=0;$skipped=0;
    try{$pdo->beginTransaction();
      $ins=$pdo->prepare("INSERT INTO finance_receivables(company_id,person_id,chart_account_id,cost_center_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,fine_amount,interest_amount,discount_amount,paid_amount,open_amount,status,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,NULL,?,0,0,0,?,?,?, ?,NOW())");
      $insSet=$pdo->prepare("INSERT INTO finance_settlements(company_id,origin_type,origin_id,settlement_date,payment_method,bank_account_id,amount,fine_amount,interest_amount,discount_amount,notes,movement_id,created_at) VALUES(?,'RECEBER',?,?,'Importação histórica',NULL,?,0,0,0,?,NULL,NOW())");
      $insMap=$pdo->prepare('INSERT INTO nexa_finance_import_receivable_map(company_id,source_system,source_key,source_row_digest,source_occurrence,source_code,receivable_id,batch_id,source_document_number,source_installment,source_issue_date,source_due_date,source_amount,source_open_amount,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      foreach((array)$pre['rows'] as $row){if(isset($pre['existing'][$row['source_key']])){$skipped++;continue;}$person=$pre['people'][$row['source_code']]??null;if(!$person)throw new RuntimeException('Código de cliente sem vínculo durante a execução: '.$row['source_code'].'.');$desc=$row['history']!==''?$row['history']:('Conta a receber '.$row['document_number']);$extra=[];if($row['nature']!=='')$extra[]='Natureza origem: '.$row['nature'];if($row['notes']!=='')$extra[]=$row['notes'];$extra[]='Importado de '.$sourceSystem.' · código cliente '.$row['source_code'].' · linha '.$row['line'];$notes=implode("\n",$extra);$status=$row['paid_amount']<=0.009?'ABERTO':($row['open_amount']<=0.009?'BAIXADO':'PARCIAL');
        $ins->execute([$companyId,(int)$person['person_id'],$chartAccountId,$costCenterId>0?$costCenterId:null,customer_import_substr($desc,220),$row['document_number']?:null,$row['installment']?:null,$row['issue_date'],$row['due_date'],$row['amount'],$row['paid_amount'],$row['open_amount'],$status,$notes]);$rid=(int)$pdo->lastInsertId();
        if($row['paid_amount']>0.009){$technicalDate=$row['issue_date']?:($row['due_date']?:date('Y-m-d'));$histNote='Baixa histórica criada somente para preservar o saldo importado. A planilha de origem não informa a data real do recebimento; data técnica utilizada: '.$technicalDate.'. Não foi criado movimento bancário.';$insSet->execute([$companyId,$rid,$technicalDate,$row['paid_amount'],$histNote]);}
        $insMap->execute([$companyId,$sourceSystem,$row['source_key'],$row['row_digest'],$row['occurrence'],$row['source_code'],$rid,$batchId,$row['document_number']?:null,$row['installment']?:null,$row['issue_date'],$row['due_date'],$row['amount'],$row['open_amount']]);$imported++;
      }
      $pdo->commit();$pdo->prepare("UPDATE nexa_finance_import_batches SET status='SUCCESS',rows_imported=?,rows_skipped=?,rows_errors=0,finished_at=NOW(),notes=? WHERE id=?")->execute([$imported,$skipped,'Importação concluída sem linhas órfãs.',$batchId]);return ['batch_id'=>$batchId,'imported'=>$imported,'skipped'=>$skipped,'stats'=>$stats];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();try{$pdo->prepare("UPDATE nexa_finance_import_batches SET status='FAILED',rows_imported=0,rows_skipped=0,rows_errors=1,finished_at=NOW(),notes=? WHERE id=?")->execute([customer_import_substr($e->getMessage(),1000),$batchId]);}catch(Throwable $ignored){}throw $e;}
  }
}


/**
 * Importação financeira fracionada v1.1.
 * O cursor e os contadores são gravados na MESMA transação MySQL dos títulos,
 * preservando a retomada mesmo se a resposta HTTP for perdida.
 * Nenhuma migration adicional: usa notes da tabela V11.45.
 */
if (!function_exists('receivable_import_chunk_limit')) {
  function receivable_import_chunk_limit(): int { return 150; }
}

if (!function_exists('receivable_import_chunk_cache_path')) {
  function receivable_import_chunk_cache_path(int $companyId, string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new RuntimeException('Token da importação inválido.');
    return receivable_import_storage_root($companyId) . '/' . $token . '.rows.jsonl';
  }
}

if (!function_exists('receivable_import_chunk_write_cache')) {
  function receivable_import_chunk_write_cache(int $companyId, string $token, array $rows): void {
    $path=receivable_import_chunk_cache_path($companyId,$token);
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    $handle=@fopen($tmp,'xb');
    if(!$handle) throw new RuntimeException('Não foi possível criar o arquivo temporário dos lotes.');
    @chmod($tmp,0600);
    try {
      foreach($rows as $row) {
        $line=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(fwrite($handle,$line)!==strlen($line)) throw new RuntimeException('Falha de escrita no cache financeiro.');
      }
      if(!fflush($handle))throw new RuntimeException('Falha ao concluir a gravação do cache.');
    } catch(Throwable $e) {
      fclose($handle);@unlink($tmp);throw $e;
    }
    fclose($handle);
    if(!@rename($tmp,$path)) {@unlink($tmp);throw new RuntimeException('Não foi possível finalizar o cache dos lotes.');}
  }
}

if (!function_exists('receivable_import_chunk_read_rows')) {
  function receivable_import_chunk_read_rows(int $companyId,string $token,int $offset,int $count): array {
    if($offset<0||$count<1||$count>receivable_import_chunk_limit()) throw new RuntimeException('Intervalo de lote inválido.');
    $path=receivable_import_chunk_cache_path($companyId,$token);
    if(!is_file($path)) throw new RuntimeException('Arquivo de trabalho expirado. Envie novamente a planilha para iniciar um novo processo.');
    $file=new SplFileObject($path,'r');$file->seek($offset);
    $out=[];
    for($i=0;$i<$count;$i++) {
      $line=$file->fgets();
      if($line===false||trim($line)==='') throw new RuntimeException('O arquivo de trabalho foi interrompido na linha '.($offset+$i+1).'.');
      $row=json_decode($line,true,512,JSON_THROW_ON_ERROR);
      if(!is_array($row)||empty($row['source_key'])||!array_key_exists('source_code',$row)) throw new RuntimeException('Linha de trabalho financeira inválida.');
      $out[]=$row;
    }
    return $out;
  }
}

if (!function_exists('receivable_import_chunk_note')) {
  function receivable_import_chunk_note(array $state): string {
    return json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  }
}

if (!function_exists('receivable_import_chunk_state')) {
  function receivable_import_chunk_state(array $batch,string $token,int $userId): array {
    $note=json_decode((string)($batch['notes']??''),true);
    if(!is_array($note)||($note['type']??'')!=='NEXA_RECEIVABLE_CHUNKS_V1'
       ||!hash_equals((string)($note['token']??''),$token)) {
      throw new RuntimeException('Este lote não pertence à planilha selecionada.');
    }
    if((int)($batch['created_by_user_id']??0)!==$userId || (int)($note['user_id']??-1)!==$userId) {
      throw new RuntimeException('O lote pertence a outro usuário.');
    }
    return $note;
  }
}

if (!function_exists('receivable_import_chunk_progress')) {
  function receivable_import_chunk_progress(array $batch,array $state): array {
    $total=(int)$batch['rows_valid'];$cursor=(int)($state['cursor']??0);
    return ['batch_id'=>(int)$batch['id'],'status'=>(string)$batch['status'],
      'processed'=>$cursor,'total'=>$total,'imported'=>(int)$batch['rows_imported'],
      'skipped'=>(int)$batch['rows_skipped'],'remaining'=>max(0,$total-$cursor),
      'percent'=>$total>0?round(100*$cursor/$total,1):100,
      'done'=>(string)$batch['status']==='SUCCESS','chunk_size'=>receivable_import_chunk_limit()];
  }
}

if (!function_exists('receivable_import_chunk_get_batch')) {
  function receivable_import_chunk_get_batch(PDO $pdo,int $companyId,int $batchId,string $token,int $userId,bool $lock=false): array {
    $sql="SELECT * FROM nexa_finance_import_batches WHERE company_id=? AND import_type='RECEIVABLES' AND id=? LIMIT 1";
    if($lock)$sql.=' FOR UPDATE';
    $st=$pdo->prepare($sql);$st->execute([$companyId,$batchId]);$batch=$st->fetch(PDO::FETCH_ASSOC);
    if(!$batch)throw new RuntimeException('Lote financeiro não encontrado nesta empresa.');
    $state=receivable_import_chunk_state($batch,$token,$userId);
    return [$batch,$state];
  }
}

if (!function_exists('receivable_import_chunk_latest')) {
  function receivable_import_chunk_latest(PDO $pdo,int $companyId,string $token,int $userId): ?array {
    $meta=receivable_import_get_upload($companyId,$token);
    $st=$pdo->prepare("SELECT * FROM nexa_finance_import_batches WHERE company_id=? AND import_type='RECEIVABLES' AND source_system=? AND source_file_sha256=? ORDER BY id DESC LIMIT 30");
    $st->execute([$companyId,(string)$meta['source_system'],(string)$meta['sha256']]);
    foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $batch) {
      try {$state=receivable_import_chunk_state($batch,$token,$userId);return receivable_import_chunk_progress($batch,$state);}
      catch(RuntimeException $ignored) {}
    }
    return null;
  }
}

if (!function_exists('receivable_import_chunk_start')) {
  function receivable_import_chunk_start(PDO $pdo,int $companyId,array $analysis,int $chartAccountId,int $costCenterId,int $userId): array {
    if($userId<=0)throw new RuntimeException('É necessário um usuário autenticado para importar.');
    if(!receivable_import_schema_ready($pdo))throw new RuntimeException('Migration financeira V11.45 pendente.');
    $meta=(array)($analysis['upload']??[]);$token=(string)($meta['token']??'');
    $sourceSystem=(string)($meta['source_system']??'PROSPERO_EXCEL');
    if(!in_array($sourceSystem,['PROSPERO_EXCEL','CPLUS4'],true))throw new RuntimeException('Origem inválida.');
    if((int)($meta['company_id']??0)!==$companyId||(int)($meta['user_id']??0)!==$userId)
      throw new RuntimeException('A planilha foi enviada por outro usuário ou empresa.');
    receivable_import_assert_chart_account($pdo,$companyId,$chartAccountId);
    if($costCenterId>0){$cc=$pdo->prepare('SELECT id FROM finance_cost_centers WHERE company_id=? AND id=? AND active=1 LIMIT 1');$cc->execute([$companyId,$costCenterId]);if(!$cc->fetchColumn())throw new RuntimeException('Centro de custo inválido para esta empresa.');}
    $lockName='nexa_fin_rec_start_'.(string)$companyId;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Existe outra inicialização financeira em andamento. Tente novamente.');
    try {
      $existing=receivable_import_chunk_latest($pdo,$companyId,$token,$userId);
      if($existing!==null){
        if($existing['status']==='RUNNING')return $existing;
        if($existing['status']==='SUCCESS')return $existing;
        throw new RuntimeException('Esse upload já possui um processo encerrado. Envie a planilha novamente.');
      }
      $pre=receivable_import_preflight($pdo,$companyId,$analysis,$sourceSystem,10);
      if(!$pre['ready'])throw new RuntimeException('Importação bloqueada: há linhas inválidas ou clientes sem vínculo. Revise a prévia.');
      $rows=(array)$pre['rows'];$stats=(array)$pre['stats'];
      receivable_import_chunk_write_cache($companyId,$token,$rows);
      $state=['type'=>'NEXA_RECEIVABLE_CHUNKS_V1','token'=>$token,'user_id'=>$userId,
        'cursor'=>0,'chart_account_id'=>$chartAccountId,'cost_center_id'=>$costCenterId,
        'created_at'=>date('c')];
      $st=$pdo->prepare("INSERT INTO nexa_finance_import_batches(company_id,import_type,source_system,original_file_name,source_file_sha256,status,rows_total,rows_valid,amount_total,paid_total,open_total,created_by_user_id,started_at,notes) VALUES(?, 'RECEIVABLES', ?, ?, ?, 'RUNNING', ?, ?, ?, ?, ?, ?, NOW(), ?)");
      $st->execute([$companyId,$sourceSystem,(string)$meta['original_name'],(string)$meta['sha256'],
        (int)$stats['rows_total'],(int)$stats['rows_valid'],(float)$stats['amount_total'],
        (float)$stats['paid_total'],(float)$stats['open_total'],$userId,receivable_import_chunk_note($state)]);
      $batchId=(int)$pdo->lastInsertId();
      $stateResult=['id'=>$batchId,'status'=>'RUNNING','rows_valid'=>(int)$stats['rows_valid'],'rows_imported'=>0,'rows_skipped'=>0];
      return receivable_import_chunk_progress($stateResult,$state);
    } finally {
      try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){}
    }
  }
}

if (!function_exists('receivable_import_chunk_process')) {
  function receivable_import_chunk_process(PDO $pdo,int $companyId,int $batchId,string $token,int $userId): array {
    if(!receivable_import_schema_ready($pdo))throw new RuntimeException('Migration V11.45 pendente.');
    $meta=receivable_import_get_upload($companyId,$token);
    $requestStarted=microtime(true);
    $pdo->beginTransaction();
    try {
      [$batch,$state]=receivable_import_chunk_get_batch($pdo,$companyId,$batchId,$token,$userId,true);
      if(!hash_equals((string)$batch['source_file_sha256'],(string)$meta['sha256'])
         ||(string)$batch['source_system']!==(string)$meta['source_system'])throw new RuntimeException('Arquivo de origem diferente do lote.');
      if((string)$batch['status']==='SUCCESS'){$pdo->commit();return receivable_import_chunk_progress($batch,$state);}
      if((string)$batch['status']!=='RUNNING')throw new RuntimeException('Este lote não está ativo.');
      $offset=(int)$state['cursor'];$total=(int)$batch['rows_valid'];
      if($offset<0||$offset>$total)throw new RuntimeException('Cursor do lote financeiro inválido.');
      $count=min(receivable_import_chunk_limit(),$total-$offset);
      if($count===0)throw new RuntimeException('Lote sem registros pendentes; reabra a página para conferir o status.');
      $rows=receivable_import_chunk_read_rows($companyId,$token,$offset,$count);
      $sourceSystem=(string)$batch['source_system'];
      $people=receivable_import_prefetch_people($pdo,$companyId,$sourceSystem,array_column($rows,'source_code'));
      $existing=receivable_import_existing_keys($pdo,$companyId,$sourceSystem,array_column($rows,'source_key'));
      $chart=(int)$state['chart_account_id'];$cost=(int)$state['cost_center_id'];
      receivable_import_assert_chart_account($pdo,$companyId,$chart);
      $ins=$pdo->prepare("INSERT INTO finance_receivables(company_id,person_id,chart_account_id,cost_center_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,fine_amount,interest_amount,discount_amount,paid_amount,open_amount,status,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,NULL,?,0,0,0,?,?,?, ?,NOW())");
      $insSet=$pdo->prepare("INSERT INTO finance_settlements(company_id,origin_type,origin_id,settlement_date,payment_method,bank_account_id,amount,fine_amount,interest_amount,discount_amount,notes,movement_id,created_at) VALUES(?,'RECEBER',?,?,'Importação histórica',NULL,?,0,0,0,?,NULL,NOW())");
      $insMap=$pdo->prepare('INSERT INTO nexa_finance_import_receivable_map(company_id,source_system,source_key,source_row_digest,source_occurrence,source_code,receivable_id,batch_id,source_document_number,source_installment,source_issue_date,source_due_date,source_amount,source_open_amount,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $imported=0;$skipped=0;$processed=0;
      foreach($rows as $row){
        // Respeita o limite de até 150 e encerra antes de exceder o orçamento de tempo.
        if($processed>0 && microtime(true)-$requestStarted>=10.0)break;
        $processed++;
        if(isset($existing[$row['source_key']])){$skipped++;continue;}
        $person=$people[$row['source_code']]??null;
        if(!$person)throw new RuntimeException('Cliente sem vínculo na linha '.(int)$row['line'].': '.$row['source_code']);
        $desc=$row['history']!==''?$row['history']:('Conta a receber '.$row['document_number']);
        $extra=[];if($row['nature']!=='')$extra[]='Natureza origem: '.$row['nature'];
        if($row['notes']!=='')$extra[]=$row['notes'];
        $extra[]='Importado de '.$sourceSystem.' · código cliente '.$row['source_code'].' · linha '.$row['line'];
        $notes=implode("\n",$extra);
        $status=$row['paid_amount']<=0.009?'ABERTO':($row['open_amount']<=0.009?'BAIXADO':'PARCIAL');
        $ins->execute([$companyId,(int)$person['person_id'],$chart,$cost>0?$cost:null,customer_import_substr($desc,220),
          $row['document_number']?:null,$row['installment']?:null,$row['issue_date'],$row['due_date'],
          $row['amount'],$row['paid_amount'],$row['open_amount'],$status,$notes]);
        $rid=(int)$pdo->lastInsertId();
        if($row['paid_amount']>0.009){
          $technicalDate=$row['issue_date']?:($row['due_date']?:date('Y-m-d'));
          $histNote='Baixa histórica criada somente para preservar o saldo importado. A planilha de origem não informa a data real do recebimento; data técnica utilizada: '.$technicalDate.'. Não foi criado movimento bancário.';
          $insSet->execute([$companyId,$rid,$technicalDate,$row['paid_amount'],$histNote]);
        }
        $insMap->execute([$companyId,$sourceSystem,$row['source_key'],$row['row_digest'],$row['occurrence'],
          $row['source_code'],$rid,$batchId,$row['document_number']?:null,$row['installment']?:null,
          $row['issue_date'],$row['due_date'],$row['amount'],$row['open_amount']]);
        $imported++;
      }
      $state['cursor']=$offset+$processed;
      $done=$state['cursor']===$total;
      $st=$pdo->prepare("UPDATE nexa_finance_import_batches SET rows_imported=rows_imported+?, rows_skipped=rows_skipped+?, status=?, finished_at=IF(?='SUCCESS',NOW(),NULL), notes=? WHERE company_id=? AND id=?");
      $st->execute([$imported,$skipped,$done?'SUCCESS':'RUNNING',$done?'SUCCESS':'RUNNING',receivable_import_chunk_note($state),$companyId,$batchId]);
      $pdo->commit();
      $batch['rows_imported']=(int)$batch['rows_imported']+$imported;
      $batch['rows_skipped']=(int)$batch['rows_skipped']+$skipped;
      $batch['status']=$done?'SUCCESS':'RUNNING';
      return receivable_import_chunk_progress($batch,$state);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('receivable_import_chunk_cancel')) {
  function receivable_import_chunk_cancel(PDO $pdo,int $companyId,int $batchId,string $token,int $userId): array {
    $pdo->beginTransaction();
    try{
      [$batch,$state]=receivable_import_chunk_get_batch($pdo,$companyId,$batchId,$token,$userId,true);
      if((string)$batch['status']==='RUNNING') {
        $pdo->prepare("UPDATE nexa_finance_import_batches SET status='CANCELLED',finished_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$batchId]);
        $batch['status']='CANCELLED';
      }
      $pdo->commit();return receivable_import_chunk_progress($batch,$state);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}
