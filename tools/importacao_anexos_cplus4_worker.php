<?php
declare(strict_types=1);
/** Worker privado: instalar cron (uma vez por minuto). Não expor por HTTP. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/cplus4_import_ui.php';
if(!function_exists('proc_open')){fwrite(STDERR,"proc_open indisponível no PHP CLI.\n");exit(2);}
try {
    $root=pa_base_dir();
    $global=$root.'/.cplus4-import-worker.lock';
    if(is_link($global))throw new RuntimeException('Lock global inválido.');
    $lock=fopen($global,'c+');
    if(!$lock)throw new RuntimeException('Lock global indisponível.');
    @chmod($global,0600);
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);exit(0);} // outra execução ainda processando
    try {
        $started=microtime(true);
        $companies=glob($root.'/company-*',GLOB_ONLYDIR)?:[];
        foreach($companies as $companyDir){
            if(microtime(true)-$started>540)break;
            if(is_link($companyDir)||!preg_match('~/company-([1-9][0-9]*)$~D',$companyDir,$m))continue;
            $cid=(int)$m[1];
            if(!is_file($companyDir.'/.cplus4-ui/job.json'))continue;
            try {ic4ui_run_company($cid,$started);}catch(Throwable $e){
                fwrite(STDERR,"Empresa {$cid}: ".$e->getMessage()."\n");
                try{ic4ui_lock($cid,static function()use($cid,$e){
                    $j=ic4ui_read($cid);
                    if($j && $j['stage']==='running'){
                        $j['stage']='error';
                        $j['last_message']='Falha na preparação ou execução da importação. Consulte o log da VPS.';
                        ic4ui_save($cid,$j);
                    }
                });}catch(Throwable $ignored){}
            }
        }
    } finally {flock($lock,LOCK_UN);fclose($lock);}
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}

function ic4ui_run_company(int $cid,float $started): void {
    $job=ic4ui_lock($cid,static function()use($cid){
        $job=ic4ui_read($cid);
        if(!$job||!in_array($job['stage'],['queued','running'],true))return null;
        if(!is_file(ic4ui_zip($cid,$job)))throw new RuntimeException('ZIP não encontrado na pasta privada.');
        $job['stage']='running';$job['last_message']='Processando anexos na VPS.';
        ic4ui_save($cid,$job);
        return $job;
    });
    if(!$job)return;
    $jobId=$job['id'];
    // Contagem do manifesto acontece uma vez no worker, nunca no request HTTP.
    if($job['total_rows']===null){
        $total=ic4ui_count_manifest(ic4ui_zip($cid,$job));
        $job=ic4ui_lock($cid,static function()use($cid,$jobId,$total){
            $current=ic4ui_read($cid);
            if(!$current||$current['id']!==$jobId)return null;
            $current['total_rows']=$total;
            ic4ui_save($cid,$current);
            return $current;
        });
        if(!$job||$job['stage']!=='running')return;
    }
    $log=ic4ui_dir($cid).'/worker-'.$jobId.'.log';
    $zip=ic4ui_zip($cid,$job);
    $script=__DIR__.'/importar_anexos_cplus4.php';
    $batch=max(1,min(150,(int)($job['batch']??50)));
    $lastProcessed=(int)($job['processed']??0);
    while(microtime(true)-$started<540){
        $job=ic4ui_read($cid);
        if(!$job||$job['id']!==$jobId||$job['stage']!=='running')return;
        if(is_link($log))throw new RuntimeException('Log inválido.');
        if(is_file($log) && filesize($log)>1024*1024){
            // Mantém o log anterior para auditoria, sem crescimento ilimitado no job atual.
            @rename($log,$log.'.prev');
        }
        // Argumentos fixados pelo sistema, nunca originados de caminhos digitados no browser.
        $args=[PHP_BINARY,$script,(string)$cid,$zip,'--commit','--batch='.$batch,'--max-seconds=45','--skip-invalid-files'];
        $spec=[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']];
        $process=proc_open($args,$spec,$pipes,__DIR__.'/..');
        if(!is_resource($process))throw new RuntimeException('Falha iniciando importador CLI.');
        fclose($pipes[0]);
        $code=proc_close($process);
        $fresh=ic4ui_lock($cid,static function()use($cid,$jobId,$code,$log,$lastProcessed){
            $j=ic4ui_read($cid);
            if(!$j||$j['id']!==$jobId)return null;
            $j=ic4ui_checkpoint($cid,$j);
            if((int)$j['processed']<$lastProcessed)throw new RuntimeException('Checkpoint regrediu.');
            $last=ic4ui_log_tail($log);
            if($code!==0){
                $j['stage']='error';$j['last_message']='Importação interrompida por erro. '.$last;
            }elseif(!empty($j['completed_manifest'])){
                $j['stage']='completed';
                $issues=(int)($j['errors']??0);
                $j['last_message']=$issues>0
                    ?"Concluido com ressalvas: {$issues} anexo(s) invalido(s)/ausente(s) nao foram importados. Baixe o relatorio de inconsistencias."
                    :'Concluido. Confira importados e ignorados.';
            }elseif($j['stage']==='paused'){
                $j['last_message']='Pausado após o lote atual.';
            }else{
                $j['stage']='running';$j['last_message']=$last?:'Lote processado; aguardando próximo.';
            }
            ic4ui_save($cid,$j);
            return $j;
        });
        if(!$fresh||$fresh['stage']!=='running')return;
        if((int)$fresh['processed']===$lastProcessed){
            // Impede repetição infinita quando não for possível avançar.
            ic4ui_lock($cid,static function()use($cid){
                $j=ic4ui_read($cid);if(!$j||$j['stage']!=='running')return;
                $j['stage']='error';$j['last_message']='Importador não avançou. Consulte o log privado.';ic4ui_save($cid,$j);
            });
            return;
        }
        $lastProcessed=(int)$fresh['processed'];
        sleep(3);
    }
}
function ic4ui_log_tail(string $path): string {
    if(!is_file($path))return '';
    $f=@fopen($path,'rb');if(!$f)return '';
    try {
        $size=filesize($path);
        fseek($f,max(0,$size-2500));
        $buf=(string)stream_get_contents($f);
        $lines=preg_split('/\r?\n/',$buf);
        foreach(array_reverse($lines?:[]) as $line){
            if(str_contains($line,'RESULTADO LOTE')||str_contains($line,'PARADA SEGURA')||str_contains($line,'FALHA:')||str_contains($line,'ERRO linha='))
                return substr(trim($line),0,300);
        }
        return 'Lote finalizado.';
    } finally{fclose($f);}
}

/** Conta somente as linhas nao vazias do manifesto, sem extrair os anexos. */
function ic4ui_count_manifest(string $path): int {
    $zip=new ZipArchive();
    if($zip->open($path)!==true)throw new RuntimeException('ZIP inválido durante contagem.');
    try{
        $fp=$zip->getStream('manifesto.csv');
        if(!$fp)throw new RuntimeException('Manifesto não encontrado.');
        try{
            fgetcsv($fp,0,';','"',''); // cabeçalho
            $count=0;
            while(($row=fgetcsv($fp,0,';','"',''))!==false){
                if(array_filter($row,static fn($v)=>trim((string)$v)!==''))$count++;
                if($count>2000000)throw new RuntimeException('Manifesto grande demais.');
            }
            return $count;
        }finally{fclose($fp);}
    }finally{$zip->close();}
}
