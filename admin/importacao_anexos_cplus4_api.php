<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/cplus4_import_ui.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
function ic4api_reply(array $data,int $code=200): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$cid=(int)COMPANY_ID;
if(!in_array(auth_role(),['platform_admin','company_admin'],true))ic4api_reply(['ok'=>false,'error'=>'Somente administrador pode importar anexos.'],403);
if(defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID && (int)MULTI_COMPANY_CONTEXT_ID!==$cid)
    ic4api_reply(['ok'=>false,'error'=>'Volte à empresa de entrada para importar anexos. O contexto F11 não é aceito nesta operação.'],403);
if(session_status()!==PHP_SESSION_ACTIVE)@session_start();
$action=(string)($_POST['action']??$_GET['action']??'status');
try {
    if($action==='status')ic4api_reply(['ok'=>true,'job'=>ic4ui_public(ic4ui_read($cid))]);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')ic4api_reply(['ok'=>false,'error'=>'Método não permitido.'],405);
    $csrf=(string)($_SESSION['ic4ui_csrf']??'');
    if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf']??'')))
        ic4api_reply(['ok'=>false,'error'=>'Sessão expirada. Atualize a página.'],403);
    if($action==='report'){
        // Download PRIVADO, autenticado, protegido por CSRF e restrito a empresa do login.
        $j=ic4ui_read($cid);
        if(!$j)throw new RuntimeException('Nenhuma importacao disponivel.');
        $report=ic4ui_errors_path($cid,$j);
        if(!$report || !is_file($report) || !is_readable($report))
            throw new RuntimeException('Relatorio ainda nao disponivel.');
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="nexa_cplus4_erros_empresa_'.$cid.'.txt"');
        header('X-Content-Type-Options: nosniff');
        readfile($report);
        exit;
    }
    if($action==='init'){
        $size=filter_var($_POST['size']??null,FILTER_VALIDATE_INT);
        $name=trim((string)($_POST['name']??''));
        if($size===false||$size<1||$size>IC4UI_MAX_ZIP_BYTES || !preg_match('/\.zip$/i',$name))throw new RuntimeException('Selecione um ZIP de até 4 GiB.');
        if(strlen($name)>220)$name=substr($name,-220);
        $job=ic4ui_lock($cid,static function()use($cid,$size,$name){
            $previous=ic4ui_read($cid);
            if($previous && in_array($previous['stage']??'', ['queued','running','paused'],true))
                throw new RuntimeException('Há uma importação ativa ou pausada. Conclua ou retome antes de enviar outro ZIP.');
            if($previous && in_array($previous['stage']??'', ['uploading','uploaded'],true)
                && (int)($previous['size']??0)===$size && (string)($previous['name']??'')===$name && empty($_POST['replace']))return $previous;
            if($previous && empty($_POST['replace']))throw new RuntimeException('Já existe um envio. Confirme o novo ZIP para substituir o envio anterior.');
            $free=@disk_free_space(ic4ui_dir($cid));
            if($free!==false && $free<$size+128*1024*1024)throw new RuntimeException('Espaço insuficiente na VPS para receber o ZIP.');
            if($previous){
                // Limpa apenas ZIP anterior da fila, nunca anexos importados nem checkpoints.
                foreach([false,true] as $partial){$old=ic4ui_zip($cid,$previous,$partial);if(is_file($old)&&!is_link($old))@unlink($old);}
            }
            $job=['id'=>bin2hex(random_bytes(16)),'company_id'=>$cid,'user_id'=>(int)(auth_user_id()??0),
                'name'=>$name,'size'=>$size,'received'=>0,'next_chunk'=>0,'stage'=>'uploading','batch'=>50,
                'processed'=>0,'imported'=>0,'ignored'=>0,'errors'=>0,'total_rows'=>null,
                'last_message'=>'Aguardando envio do ZIP.','created_at'=>gmdate('c')];
            ic4ui_save($cid,$job);
            return $job;
        });
        ic4api_reply(['ok'=>true,'job'=>ic4ui_public($job)]);
    }
    if($action==='chunk'){
        $job=ic4ui_lock($cid,static function()use($cid){
            $job=ic4ui_read($cid);
            if(!$job||$job['stage']!=='uploading')throw new RuntimeException('Nenhum envio ativo.');
            $idx=filter_var($_POST['index']??null,FILTER_VALIDATE_INT);
            if($idx===false||$idx<0)throw new RuntimeException('Índice inválido.');
            if($idx<(int)$job['next_chunk'])return $job; // retry idempotente, não duplica bytes
            if($idx!==(int)$job['next_chunk'])throw new RuntimeException('Envio fora da sequência.');
            $upload=$_FILES['part']??null;
            if(!is_array($upload)||($upload['error']??1)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??'')))
                throw new RuntimeException('Falha no envio da parte. Verifique limites de upload no PHP.');
            $received=(int)$job['received'];$size=(int)$job['size'];
            $expected=min(IC4UI_CHUNK_BYTES,$size-$received);
            if($expected<1|| (int)$upload['size']!==$expected)throw new RuntimeException('Tamanho da parte não confere.');
            $path=ic4ui_zip($cid,$job,true);
            if(is_link($path))throw new RuntimeException('Arquivo de envio inválido.');
            $fd=@fopen($path,'c+b');
            if(!$fd)throw new RuntimeException('Falha ao gravar parte.');
            try {
                @chmod($path,0600);
                if(!flock($fd,LOCK_EX))throw new RuntimeException('Arquivo de envio ocupado.');
                if(fseek($fd,0,SEEK_END)!==0)throw new RuntimeException('Falha posicionando o ZIP parcial.');
                $actual=ftell($fd);
                // Uma requisição interrompida pode ter escrito bytes antes de atualizar o checkpoint.
                // Recuamos somente a parte ainda não confirmada, preservando todos os chunks validados.
                if($actual!==$received){
                    if($actual!==false && $actual>$received && $actual<=$received+$expected){
                        if(!ftruncate($fd,$received)||fseek($fd,0,SEEK_END)!==0)throw new RuntimeException('Falha recuperando upload parcial.');
                    }else throw new RuntimeException('Arquivo parcial inconsistente.');
                }
                $in=fopen($upload['tmp_name'],'rb');
                if(!$in)throw new RuntimeException('Parte temporária indisponível.');
                try {$written=stream_copy_to_stream($in,$fd,$expected);}finally{fclose($in);}
                if($written!==$expected || !fflush($fd)){
                    ftruncate($fd,$received);throw new RuntimeException('Falha gravando parte.');
                }
            } finally {flock($fd,LOCK_UN);fclose($fd);}
            $job['received']=$received+$expected;
            $job['next_chunk']=$idx+1;
            $job['last_message']='Enviando arquivo em blocos de até 2 MiB.';
            ic4ui_save($cid,$job);
            return $job;
        });
        ic4api_reply(['ok'=>true,'job'=>ic4ui_public($job)]);
    }
    if($action==='finish'){
        $job=ic4ui_lock($cid,static function()use($cid){
            $job=ic4ui_read($cid);
            if(!$job||$job['stage']!=='uploading')throw new RuntimeException('Nenhum envio ativo.');
            if((int)$job['received']!==(int)$job['size'])throw new RuntimeException('O ZIP ainda não foi enviado por completo.');
            $part=ic4ui_zip($cid,$job,true);
            if(is_link($part)||!is_file($part)||filesize($part)!==(int)$job['size'])throw new RuntimeException('Arquivo parcial inconsistente.');
            $zip=new ZipArchive();
            if($zip->open($part)!==true)throw new RuntimeException('O arquivo recebido não é um ZIP válido.');
            try {
                $info=$zip->statName('manifesto.csv');
                if(!$info|| (int)($info['size']??0)<10 || (int)$info['size']>128*1024*1024)
                    throw new RuntimeException('ZIP incompatível: manifesto.csv ausente ou inválido.');
            }finally{$zip->close();}
            if(!rename($part,ic4ui_zip($cid,$job)))throw new RuntimeException('Falha ao finalizar ZIP.');
            $job['stage']='uploaded';$job['last_message']='ZIP recebido e validado. Inicie a importação quando desejar.';
            ic4ui_save($cid,$job);
            return $job;
        });
        ic4api_reply(['ok'=>true,'job'=>ic4ui_public($job)]);
    }
    if(in_array($action,['start','pause','resume'],true)){
        $job=ic4ui_lock($cid,static function()use($cid,$action){
            $job=ic4ui_read($cid);
            if(!$job)throw new RuntimeException('Envie o ZIP primeiro.');
            if($action==='start'){
                if($job['stage']!=='uploaded')throw new RuntimeException('Envie e valide o ZIP antes de iniciar.');
                $batch=(int)($_POST['batch']??50);
                if(!in_array($batch,[20,50,100,150],true))throw new RuntimeException('Lote inválido.');
                $job['batch']=$batch;$job['stage']='queued';
                $job['last_message']='Aguardando o worker CLI da VPS (cron).';
            }elseif($action==='pause'){
                if(!in_array($job['stage'],['queued','running'],true))throw new RuntimeException('Não existe importação em execução.');
                $job['stage']='paused';$job['last_message']='Pausa solicitada; será aplicada após o lote atual.';
            }else{
                if(!in_array($job['stage'],['paused','error'],true))throw new RuntimeException('A importação não está pausada ou com erro.');
                $job['stage']='queued';$job['last_message']='Retomada solicitada. Aguardando worker.';
            }
            ic4ui_save($cid,$job);
            return $job;
        });
        ic4api_reply(['ok'=>true,'job'=>ic4ui_public($job)]);
    }
    ic4api_reply(['ok'=>false,'error'=>'Operação não encontrada.'],404);
} catch(Throwable $e){ic4api_reply(['ok'=>false,'error'=>$e->getMessage()],422);}
