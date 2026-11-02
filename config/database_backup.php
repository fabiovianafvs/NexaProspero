<?php
declare(strict_types=1);

/** V11.45 - Backup geral do banco pelo painel, exclusivo para platform_admin. */

if (!function_exists('nexa_db_backup_h')) {
  function nexa_db_backup_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
}

if (!function_exists('nexa_db_backup_root')) {
  function nexa_db_backup_root(): string {
    $configured=trim((string)(getenv('NEXA_DB_BACKUP_DIR')?:''));
    $candidates=[];if($configured!=='')$candidates[]=$configured;$candidates[]=dirname(__DIR__).'/storage/db_backups';
    foreach($candidates as $dir){if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))continue;if(!is_writable($dir))continue;@chmod($dir,0700);$deny=$dir.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\nDeny from all\n");return rtrim($dir,'/');}
    throw new RuntimeException('Não foi possível preparar uma pasta segura e gravável para os backups. Defina NEXA_DB_BACKUP_DIR ou ajuste as permissões de storage/db_backups.');
  }
}

if (!function_exists('nexa_db_backup_config')) {
  function nexa_db_backup_config(): array {
    $db=require __DIR__.'/db.php';
    foreach(['host','port','name','user','pass'] as $key)if(!array_key_exists($key,$db))throw new RuntimeException('Configuração do banco incompleta para gerar o backup.');
    return $db;
  }
}

if (!function_exists('nexa_db_backup_binary')) {
  function nexa_db_backup_binary(): string {
    foreach(['/usr/bin/mysqldump','/usr/local/bin/mysqldump'] as $candidate)if(is_file($candidate)&&is_executable($candidate))return $candidate;
    return 'mysqldump';
  }
}

if (!function_exists('nexa_db_backup_ini_value')) {
  function nexa_db_backup_ini_value(string $value): string { return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"'; }
}

if (!function_exists('nexa_db_backup_create')) {
  function nexa_db_backup_create(PDO $pdo,int $userId): array {
    if(!function_exists('proc_open'))throw new RuntimeException('A função proc_open está desabilitada no PHP. Ela é necessária para executar o mysqldump com segurança.');
    if(!function_exists('gzopen'))throw new RuntimeException('A extensão zlib do PHP não está disponível para compactar o backup.');
    if(function_exists('set_time_limit'))@set_time_limit(0);

    $root=nexa_db_backup_root();
    $lockPath=$root.'/.backup.lock';$lock=@fopen($lockPath,'c');
    if(!$lock||!@flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))@fclose($lock);throw new RuntimeException('Já existe outro backup em execução. Aguarde a conclusão antes de iniciar um novo.');}

    $db=nexa_db_backup_config();$database=(string)$db['name'];
    try{$actual=(string)($pdo->query('SELECT DATABASE()')->fetchColumn()?:'');if($actual!=='')$database=$actual;}catch(Throwable $ignored){}
    $safeDb=preg_replace('/[^a-zA-Z0-9_.-]+/','_',basename($database))?:'database';$stamp=date('Ymd_His');$rand=bin2hex(random_bytes(3));$base=$safeDb.'_'.$stamp.'_'.$rand;
    $out=$root.'/'.$base.'.sql.gz';$metaFile=$root.'/'.$base.'.json';$errFile=$root.'/'.$base.'.stderr.log';$cnf=$root.'/.'.$base.'.cnf';
    $proc=null;$pipes=[];$gz=null;$bytes=0;$exit=-1;
    try{
      $cnfText="[client]\n".'host='.nexa_db_backup_ini_value((string)$db['host'])."\n".'port='.(int)$db['port']."\n".'user='.nexa_db_backup_ini_value((string)$db['user'])."\n".'password='.nexa_db_backup_ini_value((string)$db['pass'])."\n".'default-character-set='.nexa_db_backup_ini_value((string)($db['charset']??'utf8mb4'))."\n";
      if(@file_put_contents($cnf,$cnfText,LOCK_EX)===false)throw new RuntimeException('Não foi possível criar o arquivo temporário protegido para o mysqldump.');@chmod($cnf,0600);
      $gz=@gzopen($out,'wb6');if(!$gz)throw new RuntimeException('Não foi possível criar o arquivo compactado do backup.');@chmod($out,0600);
      $cmd=[nexa_db_backup_binary(),'--defaults-extra-file='.$cnf,'--single-transaction','--quick','--routines','--triggers','--events','--hex-blob','--no-tablespaces','--default-character-set='.((string)($db['charset']??'utf8mb4')),'--skip-comments',$database];
      $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$errFile,'w']];
      $proc=@proc_open($cmd,$descriptors,$pipes,null,null,['bypass_shell'=>true]);
      if(!is_resource($proc))throw new RuntimeException('Não foi possível iniciar o mysqldump. Verifique se o utilitário está instalado e disponível para o PHP-FPM.');
      fclose($pipes[0]);unset($pipes[0]);
      while(!feof($pipes[1])){
        $chunk=fread($pipes[1],1024*1024);if($chunk===false)throw new RuntimeException('Falha ao ler a saída do mysqldump.');if($chunk==='')continue;
        $written=gzwrite($gz,$chunk);if($written===false||$written!==strlen($chunk))throw new RuntimeException('Falha ao gravar o backup compactado.');$bytes+=$written;
      }
      fclose($pipes[1]);unset($pipes[1]);gzclose($gz);$gz=null;$exit=proc_close($proc);$proc=null;
      $stderr=is_file($errFile)?trim((string)@file_get_contents($errFile)):'';
      if($exit!==0||$bytes<=0||!is_file($out)||(int)filesize($out)<=0)throw new RuntimeException('O mysqldump terminou com erro'.($stderr!==''?': '.substr($stderr,0,800):'.'));
      $meta=['file'=>basename($out),'database'=>$database,'created_at'=>date('c'),'created_by_user_id'=>$userId,'compressed_bytes'=>(int)filesize($out),'sql_bytes'=>$bytes,'sha256'=>hash_file('sha256',$out),'mysqldump_exit'=>$exit];
      if(@file_put_contents($metaFile,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('O backup foi gerado, mas não foi possível gravar seus metadados de auditoria.');@chmod($metaFile,0600);
      return $meta;
    }catch(Throwable $e){
      if(isset($pipes[0])&&is_resource($pipes[0]))@fclose($pipes[0]);if(isset($pipes[1])&&is_resource($pipes[1]))@fclose($pipes[1]);if($gz!==null)@gzclose($gz);
      if(is_resource($proc))@proc_terminate($proc);if(is_resource($proc))@proc_close($proc);
      @unlink($out);@unlink($metaFile);
      throw $e;
    }finally{
      @unlink($cnf);@unlink($errFile);@flock($lock,LOCK_UN);@fclose($lock);
    }
  }
}

if (!function_exists('nexa_db_backup_list')) {
  function nexa_db_backup_list(): array {
    $root=nexa_db_backup_root();$rows=[];foreach((array)glob($root.'/*.sql.gz') as $file){$base=basename($file,'.sql.gz');$metaFile=$root.'/'.$base.'.json';$meta=is_file($metaFile)?json_decode((string)@file_get_contents($metaFile),true):[];if(!is_array($meta))$meta=[];$rows[]=$meta+['file'=>basename($file),'created_at'=>date('c',(int)@filemtime($file)),'compressed_bytes'=>(int)@filesize($file),'sha256'=>hash_file('sha256',$file)];}usort($rows,static fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return $rows;
  }
}

if (!function_exists('nexa_db_backup_resolve_file')) {
  function nexa_db_backup_resolve_file(string $name): string {
    if(!preg_match('/^[A-Za-z0-9_.-]+\.sql\.gz$/',$name))throw new RuntimeException('Nome de backup inválido.');$root=nexa_db_backup_root();$path=$root.'/'.$name;$real=realpath($path);$rootReal=realpath($root);if($real===false||$rootReal===false||!str_starts_with($real,$rootReal.DIRECTORY_SEPARATOR)||!is_file($real))throw new RuntimeException('Backup não localizado.');return $real;
  }
}

if (!function_exists('nexa_db_backup_delete')) {
  function nexa_db_backup_delete(string $name): void {
    $path=nexa_db_backup_resolve_file($name);$base=substr($path,0,-7);if(!@unlink($path))throw new RuntimeException('Não foi possível excluir o arquivo de backup.');@unlink($base.'.json');
  }
}
