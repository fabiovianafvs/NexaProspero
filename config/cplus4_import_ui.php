<?php
declare(strict_types=1);
/** Fila privada de importação CPlus4. Nenhum ZIP nem estado pode estar no webroot. */
require_once __DIR__.'/person_attachments.php';

const IC4UI_CHUNK_BYTES = 2 * 1024 * 1024;   // 2MiB/requisição, abaixo de post_max_size habitual
const IC4UI_MAX_ZIP_BYTES = 4 * 1024 * 1024 * 1024; // tamanho global, sujeito a espaço disponível

function ic4ui_dir(int $companyId): string {
    $root=pa_company_dir($companyId);
    $dir=$root.'/.cplus4-ui';
    if (is_link($dir)) throw new RuntimeException('Diretório de importação inválido.');
    if (!is_dir($dir) && !@mkdir($dir,0700)) throw new RuntimeException('Não foi possível preparar importação privada.');
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Pasta de importação não disponível.');
    @chmod($dir,0700);
    return $dir;
}
function ic4ui_path(int $cid): string { return ic4ui_dir($cid).'/job.json'; }
function ic4ui_read(int $cid): ?array {
    $path=ic4ui_path($cid);
    if (is_link($path)) throw new RuntimeException('Estado inválido.');
    if (!is_file($path)) return null;
    $data=json_decode((string)file_get_contents($path),true);
    if (!is_array($data) || !preg_match('/^[a-f0-9]{32}$/D',(string)($data['id']??''))) throw new RuntimeException('Estado de importação inconsistente.');
    if ((int)($data['company_id']??0)!==$cid) throw new RuntimeException('Empresa inconsistente no estado.');
    return $data;
}
function ic4ui_save(int $cid,array $job): void {
    $job['updated_at']=gmdate('c');
    $path=ic4ui_path($cid);
    if (is_link($path)) throw new RuntimeException('Estado simbólico inválido.');
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(5));
    $json=json_encode($job,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $fd=@fopen($tmp,'xb');
    if (!$fd) throw new RuntimeException('Não foi possível gravar estado.');
    try {
        @chmod($tmp,0600);
        if(fwrite($fd,$json)!==strlen($json) || !fflush($fd)) throw new RuntimeException('Falha na gravação do estado.');
    } finally { fclose($fd); }
    if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Falha publicando estado.');}
}
/** Lock por empresa para impedir chamadas POST concorrentes e alterações concorrentes com o worker. */
function ic4ui_lock(int $cid,callable $fn): mixed {
    $path=ic4ui_dir($cid).'/.job.lock';
    if(is_link($path))throw new RuntimeException('Bloqueio inválido.');
    $fd=@fopen($path,'c+');
    if(!$fd)throw new RuntimeException('Não foi possível bloquear importação.');
    try {
        @chmod($path,0600);
        if(!flock($fd,LOCK_EX))throw new RuntimeException('Falha ao obter bloqueio.');
        return $fn();
    } finally { flock($fd,LOCK_UN);fclose($fd); }
}
/** Apenas nomes derivados do ID aleatório armazenado no estado podem virar caminho. */
function ic4ui_zip(int $cid,array $job,bool $partial=false): string {
    $id=(string)($job['id']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Identificador inválido.');
    return ic4ui_dir($cid).'/source-'.$id.($partial?'.part':'.zip');
}
function ic4ui_public(?array $job): array {
    if(!$job)return ['exists'=>false];
    $fields=['id','name','size','received','next_chunk','stage','processed','imported','ignored','errors','total_rows','last_message','updated_at','created_at','batch'];
    $out=['exists'=>true];
    foreach($fields as $f)$out[$f]=$job[$f]??null;
    return $out;
}
/** Atualiza progresso sem confiar no output livre da CLI. */
function ic4ui_checkpoint(int $cid,array $job): array {
    $zipPath=ic4ui_zip($cid,$job);
    if (!is_file($zipPath)) return $job;
    $zip=new ZipArchive();
    if($zip->open($zipPath)!==true)return $job;
    try {
        $meta=$zip->statName('manifesto.csv');
        if(!$meta)return $job;
        $id=hash('sha256',implode('|',[$zipPath,(string)filesize($zipPath),(string)filemtime($zipPath),
            (string)($meta['size']??0),(string)($meta['crc']??0)]));
        $state=pa_company_dir($cid).'/.importacoes-cplus4/'.$id.'.state.json';
        if(is_link($state)||!is_file($state))return $job;
        $record=json_decode((string)file_get_contents($state),true);
        if(!is_array($record)||($record['zip_id']??'')!==$id)return $job;
        foreach(['processed','imported','ignored','errors'] as $field)$job[$field]=(int)($record[$field]??0);
        $job['completed_manifest']=!empty($record['done']);
        return $job;
    } finally {$zip->close();}
}

/** Relatorio privado de inconsistencias do ZIP ATUAL da fila; nunca confiar em caminho do navegador. */
function ic4ui_errors_path(int $cid,array $job): ?string {
    $path=ic4ui_zip($cid,$job);
    if(is_link($path) || !is_file($path))return null;
    $zip=new ZipArchive();
    if($zip->open($path)!==true)return null;
    try {
        $meta=$zip->statName('manifesto.csv');
        if(!$meta)return null;
        $id=hash('sha256',implode('|',[$path,(string)filesize($path),(string)filemtime($path),
            (string)($meta['size']??0),(string)($meta['crc']??0)]));
        $log=pa_company_dir($cid).'/.importacoes-cplus4/'.$id.'.errors.log';
        return is_link($log)?null:$log;
    } finally {$zip->close();}
}
