<?php
declare(strict_types=1);
/**
 * Nexa Prospero - importacao CPlus4 de anexos de Pessoas (CLI somente).
 * V2: lotes de no maximo 150, checkpoint por linha, lock por empresa e retomada.
 * Le somente manifesto.csv (cache privado) e um anexo por vez, sem extrair o ZIP.
 * A importacao padrao e SIMULACAO; --commit efetiva e grava o progresso.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class IC4RowException extends RuntimeException {}
/** Problema atribuivel ao arquivo da origem; pode ser registrado e pulado com seguranca. */
class IC4InvalidFileException extends IC4RowException {}

function ic4_usage(): never {
    fwrite(STDERR, "Uso: php tools/importar_anexos_cplus4.php ID_EMPRESA /caminho/05_ANEXOS.zip [--commit] [--batch=150] [--all] [--pause=2] [--max-seconds=0] [--max-mib=50] [--continue-on-error] [--skip-invalid-files] [--restart]\n"
        . "Por padrao: simulacao de ate 150 linhas a partir do checkpoint.\n"
        . "--commit: importa ate 150 registros por chamada, com retomada automatica.\n"
        . "--all: repete os lotes de ate 150 (CLI) com pausa entre eles; requer --commit.\n"
        . "--batch=N (ou --limit=N): 1 a 150 linhas por lote.\n"
        . "--pause=N: 1 a 60 segundos entre lotes no modo --all (padrao 2).\n"
        . "--max-seconds=N: para antes do proximo registro apos N segundos (0=sem limite).\n"
        . "--max-mib=N: limite sugerido de bytes originais por lote (1 a 200 MiB; padrao 50); arquivo individual maior e permitido.\n"
        . "--continue-on-error: avanca em qualquer falha de DADOS (uso manual; nao para falhas de sistema).\n"
        . "--skip-invalid-files: avanca SOMENTE em anexos ausentes, corrompidos, invalidos ou de tipo nao aceito; grava relatorio.\n"
        . "--restart: reinicia leitura do manifesto (anexos ja importados sao ignorados), requer --commit e nao aceita --all.\n");
    exit(2);
}
function ic4_parse(array $argv): array {
    if (count($argv)<3 || !ctype_digit((string)$argv[1]) || (int)$argv[1]<1 || !is_file((string)$argv[2])) ic4_usage();
    $opts=['company'=>(int)$argv[1], 'zip'=>realpath((string)$argv[2]), 'commit'=>false,
        'all'=>false,'batch'=>150,'pause'=>2,'max_seconds'=>0,'max_mib'=>50,'continue'=>false,'skip_invalid_files'=>false,'restart'=>false];
    if (!$opts['zip']) ic4_usage();
    $seen=[];
    foreach (array_slice($argv,3) as $arg) {
        if (in_array($arg,['--commit','--all','--continue-on-error','--skip-invalid-files','--restart'],true)) {
            if (isset($seen[$arg])) ic4_usage();
            $seen[$arg]=true;
            $key=['--commit'=>'commit','--all'=>'all','--continue-on-error'=>'continue','--skip-invalid-files'=>'skip_invalid_files','--restart'=>'restart'][$arg];
            $opts[$key]=true;
            continue;
        }
        if (!preg_match('/^--(batch|limit|pause|max-seconds|max-mib)=(\d+)$/D',$arg,$m)) ic4_usage();
        $k=['batch'=>'batch','limit'=>'batch','pause'=>'pause','max-seconds'=>'max_seconds','max-mib'=>'max_mib'][$m[1]];
        if (isset($seen[$k])) ic4_usage();
        $seen[$k]=true;
        $v=filter_var($m[2],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
        if ($v===false) ic4_usage();
        $opts[$k]=(int)$v;
    }
    if ($opts['batch']<1 || $opts['batch']>150 || $opts['pause']<1 || $opts['pause']>60 || $opts['max_mib']<1 || $opts['max_mib']>200 ||
        ($opts['all'] && !$opts['commit']) || ($opts['restart'] && (!$opts['commit'] || $opts['all']))) ic4_usage();
    return $opts;
}
/** Gravacao atomica, usando a pasta privada ja verificada pelo pa_company_dir(). */
function ic4_write_json(string $path, array $data): void {
    if (is_link($path)) throw new RuntimeException('Checkpoint nao pode ser link simbolico.');
    $encoded=json_encode($data,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
    $fh=@fopen($tmp,'xb');
    if (!$fh) throw new RuntimeException('Falha ao criar checkpoint temporario.');
    try {
        @chmod($tmp,0600);
        $size=strlen($encoded);$offset=0;
        while($offset<$size) {
            $n=fwrite($fh,substr($encoded,$offset));
            if ($n===false || $n===0) throw new RuntimeException('Falha gravando checkpoint.');
            $offset+=$n;
        }
        if (!fflush($fh)) throw new RuntimeException('Falha confirmando checkpoint.');
    } catch (Throwable $e) {
        fclose($fh);@unlink($tmp);throw $e;
    }
    fclose($fh);
    if (!@rename($tmp,$path)) { @unlink($tmp);throw new RuntimeException('Falha publicando checkpoint.'); }
}
function ic4_log_error(string $log, int $row, string $ref, string $customer, string $name, string $error): void {
    if (is_link($log)) throw new RuntimeException('Log de erros invalido.');
    $fh=@fopen($log,'ab');
    if (!$fh) throw new RuntimeException('Nao foi possivel registrar erro no log.');
    try {
        @chmod($log,0600);
        $clean=static fn(string $v,int $n):string => str_replace(["\r","\n","\t"],' ',substr($v,0,$n));
        $line=date('c')." | linha={$row} | ID_ANEXO=".$clean($ref,100)
            ." | CLIENTE_CODIGO=".$clean($customer,80)
            ." | NOME_ORIGINAL=".$clean($name,180)
            ." | motivo=".$clean($error,400)."\n";
        if (fwrite($fh,$line)!==strlen($line) || !fflush($fh)) throw new RuntimeException('Falha gravando log de erros.');
    } finally { fclose($fh); }
}
/** Cria copia privada so do manifesto; arquivo CSV permite fseek com offset salvo. */
function ic4_manifest(ZipArchive $zip, string $path, int $expectedSize): void {
    if (is_link($path)) throw new RuntimeException('Manifesto privado invalido.');
    if (is_file($path) && filesize($path)===$expectedSize) return;
    if (is_file($path)) @unlink($path);
    $input=$zip->getStream('manifesto.csv');
    if (!$input) throw new RuntimeException('manifesto.csv nao encontrado ou ilegivel no ZIP.');
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
    $out=@fopen($tmp,'xb');
    if (!$out) { fclose($input);throw new RuntimeException('Falha criando cache do manifesto.'); }
    $count=0;
    try {
        @chmod($tmp,0600);
        while(!feof($input)) {
            $buffer=fread($input,1024*1024);
            if ($buffer===false || ($buffer==='' && !feof($input))) throw new RuntimeException('Erro ao ler manifesto do ZIP.');
            $n=strlen($buffer);$count+=$n;
            if ($count>$expectedSize || $count>128*1024*1024) throw new RuntimeException('Manifesto excedeu tamanho declarado.');
            $pos=0;
            while($pos<$n) {
                $written=fwrite($out,substr($buffer,$pos));
                if ($written===false || $written===0) throw new RuntimeException('Erro gravando manifesto privado.');
                $pos+=$written;
            }
        }
        if ($count!==$expectedSize || !fflush($out)) throw new RuntimeException('Manifesto incompleto.');
    } catch (Throwable $e) {
        fclose($input);fclose($out);@unlink($tmp);throw $e;
    }
    fclose($input);fclose($out);
    if (!@rename($tmp,$path)) { @unlink($tmp);throw new RuntimeException('Erro publicando manifesto.'); }
}
/** Grava um unico anexo em temporario por streaming (ate 100 MiB) e verifica SHA256. */
function ic4_extract_one(ZipArchive $zip,string $member,int $expectedSize,string $expectedSha): string {
    $file=$zip->getStream($member);
    if (!$file) throw new RuntimeException('Falha lendo arquivo do ZIP.');
    $tmp=tempnam(sys_get_temp_dir(),'nexa_cplus_');
    $out=$tmp ? @fopen($tmp,'wb') : false;
    if (!$out) { fclose($file);if($tmp)@unlink($tmp);throw new RuntimeException('Falha criando temporario.'); }
    $read=0;
    try {
        while(!feof($file)) {
            $chunk=fread($file,1024*1024);
            if ($chunk===false || ($chunk==='' && !feof($file))) throw new RuntimeException('Erro lendo conteudo do ZIP.');
            $n=strlen($chunk);$read+=$n;
            if ($read>100*1024*1024 || $read>$expectedSize) throw new RuntimeException('Arquivo excede limite.');
            $offset=0;
            while($offset<$n) {
                $written=fwrite($out,substr($chunk,$offset));
                if ($written===false || $written===0) throw new RuntimeException('Erro gravando temporario.');
                $offset+=$written;
            }
        }
        if (!fflush($out) || $read!==$expectedSize) throw new RuntimeException('Tamanho do arquivo nao confere.');
    } catch (Throwable $e) {
        fclose($file);fclose($out);@unlink($tmp);throw $e;
    }
    fclose($file);fclose($out);
    if ($expectedSha!=='' && !hash_equals(strtolower($expectedSha),hash_file('sha256',$tmp))) {
        @unlink($tmp);throw new RuntimeException('SHA256 do arquivo nao confere.');
    }
    return $tmp;
}
function ic4_record(PDO $pdo,ZipArchive $zip,int $cid,array $data,PDOStatement $person,PDOStatement $exists,bool $commit): array {
    $ref=trim((string)$data['ID_ANEXO']);
    $entity=strtoupper(trim((string)$data['NOMEENTIDADE']));
    $code=trim((string)$data['CLIENTE_CODIGO']);
    $member=(string)$data['ARQUIVO'];
    $name=pa_clean_filename((string)$data['NOME_ORIGINAL']);
    $sha=strtolower(trim((string)$data['SHA256']));
    $refKey='CPLUS4_'.$ref;
    if ($entity!=='CLIENTE' || $code==='' || $ref==='' || strlen($refKey)>100 ||
        !preg_match('#^arquivos/[A-Za-z0-9._-]{1,240}$#D',$member) || strpos($member,'..')!==false) {
        return ['status'=>'ignored','reason'=>'Sem vinculo validavel no manifesto'];
    }
    if ($sha!=='' && !preg_match('/^[0-9a-f]{64}$/D',$sha)) throw new IC4InvalidFileException('SHA256 invalido no manifesto.');
    $person->execute([$cid,'CPLUS4',$code]);
    $personId=(int)($person->fetchColumn()?:0);
    $person->closeCursor();
    if ($personId<=0) throw new IC4RowException('Cliente nao encontrado no Nexa para codigo '.$code.'. Importe primeiro os clientes.');
    $exists->execute([$cid,'CPLUS4',$refKey]);
    $mapped=(int)($exists->fetchColumn()?:0);
    $exists->closeCursor();
    if ($mapped>0) {
        if ($mapped!==$personId) throw new IC4RowException('Referencia ja pertence a outro cliente; nao importado.');
        return ['status'=>'ignored','reason'=>'Ja importado (origem deduplicada)'];
    }
    $index=$zip->locateName($member,0);
    if ($index===false) throw new IC4InvalidFileException('Arquivo nao encontrado no ZIP: '.$member);
    $meta=$zip->statIndex($index);
    $size=(int)($meta['size']??0);
    if ($size<1 || $size>100*1024*1024) throw new IC4InvalidFileException('Arquivo vazio ou excede 100 MiB.');
    if (!$commit) return ['status'=>'imported','original_bytes'=>$size,'stored_bytes'=>0];
    try {
        $tmp=ic4_extract_one($zip,$member,$size,$sha);
    } catch (RuntimeException $e) {
        // Falhas em temporario/disco nunca sao tratadas como arquivo invalido.
        if (in_array($e->getMessage(),[
            'Falha lendo arquivo do ZIP.','Erro lendo conteudo do ZIP.',
            'Tamanho do arquivo nao confere.','SHA256 do arquivo nao confere.',
            'Arquivo excede limite.'
        ],true)) throw new IC4InvalidFileException($e->getMessage(),0,$e);
        throw $e;
    }
    try {
        // O tipo e detectado pelo CONTEUDO, nao pela extensao. ZIP disfarçado de JPG nao entra.
        try { pa_mime_file($tmp,$name); }
        catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(),'Tipo de arquivo não aceito:'))
                throw new IC4InvalidFileException($e->getMessage(),0,$e);
            throw $e;
        }
        $result=pa_save($pdo,$cid,$personId,$name,$tmp,0,'CPLUS4',$refKey);
        return ['status'=>'imported','original_bytes'=>(int)$result['original_bytes'],
            'stored_bytes'=>(int)$result['stored_bytes']];
    } finally { @unlink($tmp); }
}

try {
    $opt=ic4_parse($argv);
    if (!class_exists('ZipArchive')) throw new RuntimeException('Extensao PHP ZIP nao disponivel.');
    require_once __DIR__.'/../config/pdo.php';
    require_once __DIR__.'/../config/person_attachments.php';
    $cid=$opt['company'];
    $zip=new ZipArchive();
    if ($zip->open($opt['zip'])!==true) throw new RuntimeException('ZIP invalido.');
    $meta=$zip->statName('manifesto.csv');
    if (!$meta) throw new RuntimeException('manifesto.csv nao encontrado no ZIP.');
    $manifestSize=(int)($meta['size']??0);
    if ($manifestSize<10 || $manifestSize>128*1024*1024) throw new RuntimeException('Tamanho invalido de manifesto.csv.');
    $identity=hash('sha256',implode('|',[$opt['zip'],(string)filesize($opt['zip']),
        (string)filemtime($opt['zip']),(string)$manifestSize,(string)($meta['crc']??0)]));
    $root=pa_company_dir($cid);
    $dir=$root.'/.importacoes-cplus4';
    if (!is_dir($dir) && !@mkdir($dir,0700)) throw new RuntimeException('Nao foi possivel criar diretorio privado de controle.');
    if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Diretorio privado de controle invalido.');
    @chmod($dir,0700);
    $lockPath=$dir.'/.company.lock';
    if (is_link($lockPath)) throw new RuntimeException('Lock invalido.');
    $lock=@fopen($lockPath,'c+');
    if (!$lock) throw new RuntimeException('Falha criando lock.');
    @chmod($lockPath,0600);
    if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Ja existe importacao CPlus4 para esta empresa.');
    try {
        $base=$dir.'/'.$identity;
        $manifestPath=$base.'.manifest.csv';
        $statePath=$base.'.state.json';
        $errorLog=$base.'.errors.log';
        ic4_manifest($zip,$manifestPath,$manifestSize);
        $csv=@fopen($manifestPath,'rb');
        if (!$csv) throw new RuntimeException('Falha abrindo manifesto privado.');
        try {
            $headers=fgetcsv($csv,0,';','"','');
            if (!is_array($headers)) throw new RuntimeException('Manifesto vazio.');
            $headers=array_map(static fn($v)=>trim((string)preg_replace('/^\xEF\xBB\xBF/','',(string)$v)),$headers);
            $fields=['ID_ANEXO','NOMEENTIDADE','CLIENTE_CODIGO','NOME_ORIGINAL','ARQUIVO','SHA256','SITUACAO_VINCULO'];
            foreach($fields as $field) if(!in_array($field,$headers,true)) throw new RuntimeException('Campo ausente do manifesto: '.$field);
            $idx=array_flip($headers);
            $headOffset=ftell($csv);
            $blank=['version'=>2,'zip_id'=>$identity,'next_offset'=>$headOffset,'next_row'=>1,
                'processed'=>0,'imported'=>0,'ignored'=>0,'errors'=>0,
                'bytes_in'=>0,'bytes_out'=>0,'done'=>false,'updated_at'=>null];
            $state=$blank;
            if (is_link($statePath)) throw new RuntimeException('Checkpoint invalido.');
            if (is_file($statePath)) {
                $decoded=json_decode((string)file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || ($decoded['version']??0)!==2 || ($decoded['zip_id']??'')!==$identity ||
                    !isset($decoded['next_offset'],$decoded['next_row']) ||
                    !is_int($decoded['next_offset']) || !is_int($decoded['next_row']) ||
                    $decoded['next_offset']<$headOffset || $decoded['next_offset']>$manifestSize || $decoded['next_row']<1) {
                    throw new RuntimeException('Checkpoint inconsistente. Interrompido para evitar reprocessamento incorreto.');
                }
                $state=array_replace($blank,$decoded);
            }
            if ($opt['restart']) {
                // Voltar ao inicio e seguro: UNIQUE(company_id,source_system,source_reference) e checagem previa.
                $state=$blank;
                ic4_write_json($statePath,$state);
                echo "REINICIO EXPLICITO: leitura reiniciada; anexos ja existentes serao ignorados.\n";
            }
            $startRow=$state['next_row'];
            if (fseek($csv,$state['next_offset'],SEEK_SET)!==0) throw new RuntimeException('Falha posicionando leitura no checkpoint.');
            echo ($opt['commit']?'IMPORTACAO EFETIVA':'SIMULACAO (DRY-RUN)')." | empresa={$cid} | lote={$opt['batch']} | proxima_linha={$startRow} | modo=".($opt['all']?'automatico':'manual')."\n";
            echo "Controle privado: {$statePath}\n";
            if ($state['done']) { echo "IMPORTACAO JA CONCLUIDA. Use --restart --commit para reexaminar o ZIP (idempotente).\n";exit(0); }
            $person=$pdo->prepare('SELECT m.person_id FROM nexa_person_source_map m JOIN finance_people p ON p.id=m.person_id AND p.company_id=m.company_id WHERE m.company_id=? AND m.source_system=? AND m.source_code=? LIMIT 1');
            $exists=$pdo->prepare('SELECT person_id FROM nexa_person_attachments WHERE company_id=? AND source_system=? AND source_reference=? LIMIT 1');
            $start=microtime(true);
            $total=0;$added=0;$ignored=0;$errors=0;$bytesIn=0;$bytesOut=0;$batchCount=0;$batchIndex=1;
            $endReached=false;$timedOut=false;$haltError=false;$byteLimit=false;$batchBytes=0;
            while(true) {
                if ($opt['max_seconds']>0 && $total>0 && microtime(true)-$start >= $opt['max_seconds']) { $timedOut=true;break; }
                if ($batchCount===$opt['batch']) {
                    echo "LOTE {$batchIndex} concluido ({$batchCount} linhas); proxima_linha={$state['next_row']}\n";
                    if (!$opt['all']) break;
                    $batchIndex++;$batchCount=0;$batchBytes=0;
                    echo "Pausa de {$opt['pause']}s entre lotes...\n";
                    sleep($opt['pause']);
                    continue;
                }
                $rowStart=ftell($csv);
                if ($rowStart===false) throw new RuntimeException('Offset do manifesto indisponivel.');
                $row=fgetcsv($csv,0,';','"','');
                if ($row===false) { $endReached=true;break; }
                $currentRow=(int)$state['next_row'];
                $nextOffset=ftell($csv);
                if ($nextOffset===false) throw new RuntimeException('Nao foi possivel medir offset do manifesto.');
                $state['next_row']=$currentRow+1;
                $state['next_offset']=$nextOffset;
                if (!array_filter($row,static fn($x)=>trim((string)$x)!=='')) {
                    if ($opt['commit']) { $state['updated_at']=date('c');ic4_write_json($statePath,$state); }
                    continue;
                }
                $data=[];foreach($idx as $key=>$position)$data[$key]=(string)($row[$position]??'');
                // Limite adicional por volume descompactado. O checkpoint NÃO avança na linha adiada.
                $entryName=(string)($data['ARQUIVO']??'');
                $entryStat=$zip->statName($entryName);
                $entryBytes=$entryStat?(int)($entryStat['size']??0):0;
                if($batchCount>0 && $batchBytes+max(0,$entryBytes)>$opt['max_mib']*1048576){
                    $state['next_row']=$currentRow;
                    $state['next_offset']=$rowStart;
                    if(fseek($csv,$rowStart,SEEK_SET)!==0)throw new RuntimeException('Falha voltando a linha não processada.');
                    if(!$opt['all']){$byteLimit=true;break;}
                    echo "LOTE {$batchIndex} atingiu limite de bytes; proxima_linha={$state['next_row']}\n";
                    $batchIndex++;$batchCount=0;$batchBytes=0;
                    sleep($opt['pause']);
                    continue;
                }
                try {
                    if (count($row)!==count($headers)) throw new IC4RowException('Linha do manifesto com numero incorreto de colunas.');
                    $result=ic4_record($pdo,$zip,$cid,$data,$person,$exists,$opt['commit']);
                    if ($result['status']==='imported') {
                        $added++;$bytesIn+=(int)$result['original_bytes'];$bytesOut+=(int)$result['stored_bytes'];
                        $state['imported']++;$state['bytes_in']+=(int)$result['original_bytes'];$state['bytes_out']+=(int)$result['stored_bytes'];
                    } else {
                        $ignored++;$state['ignored']++;
                        if (($result['reason']??'')!=='Ja importado (origem deduplicada)')
                            echo "IGNORADO linha={$currentRow}: {$result['reason']}\n";
                    }
                } catch (Throwable $e) {
                    $errors++;
                    $message=str_replace(["\r","\n"],' ',substr($e->getMessage(),0,350));
                    echo "ERRO linha={$currentRow}: {$message}\n";
                    $canSkip = ($opt['continue'] && $e instanceof IC4RowException)
                        || ($opt['skip_invalid_files'] && $e instanceof IC4InvalidFileException);
                    if (!$canSkip) {
                        // Erros de banco, disco, armazenamento, cliente ausente ou referencia divergente NUNCA sao ignorados no modo automatico.
                        $state['next_row']=$currentRow;
                        $state['next_offset']=$rowStart;
                        $haltError=true;
                        break;
                    }
                    ic4_log_error($errorLog,$currentRow,(string)($data['ID_ANEXO']??''),
                        (string)($data['CLIENTE_CODIGO']??''),(string)($data['NOME_ORIGINAL']??''),$message);
                    $state['errors']++;
                }
                $total++;$batchCount++;$batchBytes+=max(0,$entryBytes);$state['processed']++;
                if ($opt['commit']) { $state['updated_at']=date('c');ic4_write_json($statePath,$state); }
            }
            if ($opt['commit'] && !$haltError && $endReached) {
                $state['done']=true;$state['updated_at']=date('c');ic4_write_json($statePath,$state);
            }
            echo "RESULTADO LOTE | linhas={$total} ".($opt['commit']?'importados':'aptos')."={$added} ignorados={$ignored} erros={$errors} bytes_origem={$bytesIn} bytes_gravados={$bytesOut}\n";
            if ($opt['commit']) {
                echo "ACUMULADO | linhas={$state['processed']} importados={$state['imported']} ignorados={$state['ignored']} erros_ignorados={$state['errors']} bytes_origem={$state['bytes_in']} bytes_gravados={$state['bytes_out']}\n";
                echo $endReached?"CONCLUIDO: manifesto completamente percorrido.\n":"PARCIAL: para continuar, repita o mesmo comando. Proxima linha={$state['next_row']}\n";
            } else echo "SIMULACAO: nenhum anexo nem checkpoint foi alterado. Execute --commit apos conferir.\n";
            if ($byteLimit) echo "PARADA POR VOLUME: limite de {$opt['max_mib']} MiB por lote atingido; retomada preservada.\n";
            if ($timedOut) echo "PARADA POR TEMPO: limite --max-seconds atingido; retomada preservada.\n";
            if ($haltError) echo "PARADA SEGURA: corrija o erro e repita o comando. Em caso de arquivo invalido, use --skip-invalid-files.\n";
            if ($errors>0 && !$haltError) echo "RESSALVAS: {$errors} anexo(s) rejeitado(s) neste lote e registrado(s) no relatorio privado.\n";
            // Nao converter skips autorizados em falha da fila: sistema/infra continuam bloqueantes.
            if ($haltError) exit(1);
        } finally { fclose($csv); }
    } finally { flock($lock,LOCK_UN);fclose($lock);$zip->close(); }
} catch(Throwable $e) {
    fwrite(STDERR,"FALHA: ".$e->getMessage()."\n");
    exit(2);
}
