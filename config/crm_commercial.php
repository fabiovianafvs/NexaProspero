<?php
declare(strict_types=1);
/** CRM — funções comuns comerciais. Não cria tabelas e não executa DDL. */
if (!function_exists('crm_commercial_money')) {
function crm_commercial_money($v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function crm_commercial_decimal($v): float {
  $s=trim((string)$v);
  if ($s==='') return 0.0;
  $s=preg_replace('/[^\d.,-]/','',$s)??'';
  if (str_contains($s,',') && str_contains($s,'.')) $s=str_replace('.','', $s);
  if (str_contains($s,',')) $s=str_replace(',','.', $s);
  if (!is_numeric($s)) throw new RuntimeException('Valor numérico inválido.');
  $n=(float)$s;
  if (!is_finite($n) || $n<0 || $n>100000000) throw new RuntimeException('Valor fora do limite.');
  return $n;
}
function crm_commercial_e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function crm_commercial_proposal(PDO $pdo,int $companyId,int $attendanceId,int $proposalId): array {
  $s=$pdo->prepare('SELECT * FROM crm_proposals WHERE company_id=? AND attendance_id=? AND id=? LIMIT 1');
  $s->execute([$companyId,$attendanceId,$proposalId]);return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function crm_commercial_items(PDO $pdo,int $companyId,int $proposalId): array {
  $s=$pdo->prepare('SELECT * FROM crm_proposal_items WHERE company_id=? AND proposal_id=? ORDER BY sort_order,id');
  $s->execute([$companyId,$proposalId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function crm_commercial_attachments(PDO $pdo,int $companyId,int $attendanceId): array {
  $s=$pdo->prepare('SELECT a.*,u.name user_name FROM crm_attachments a LEFT JOIN users u ON u.id=a.created_by AND u.company_id=a.company_id WHERE a.company_id=? AND a.attendance_id=? ORDER BY a.id DESC');
  $s->execute([$companyId,$attendanceId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function crm_commercial_upload_dir(int $companyId): string {
  $base=trim((string)(getenv('NEXA_CRM_UPLOAD_DIR')?:''));
  if ($base==='') $base=dirname(__DIR__,2).'/nexa_private/crm_attachments';
  if ($base[0]!=='/') throw new RuntimeException('Pasta de anexos deve ser um caminho absoluto fora do diretório público.');
  $root=realpath($base);
  if ($root===false || !is_dir($root) || !is_writable($root)) throw new RuntimeException('Configure a pasta privada de anexos conforme o LEIA-ME (diretório inacessível).');
  $appRoot=realpath(dirname(__DIR__));
  if ($appRoot && ($root===$appRoot || str_starts_with($root,$appRoot.'/'))) throw new RuntimeException('Pasta de anexos não pode ficar dentro da raiz pública da aplicação.');
  $dir=$root.'/'.$companyId;
  if(!is_dir($dir) && !@mkdir($dir,0700)) throw new RuntimeException('Não foi possível criar a pasta privada da empresa.');
  $real=realpath($dir);
  if(!$real || !str_starts_with($real,$root.'/') || !is_writable($real)) throw new RuntimeException('Pasta privada sem permissão de gravação.');
  return $real;
}
function crm_commercial_uploaded_files(array $input): array {
  if(!isset($input['name']))return [];
  if(!is_array($input['name']))return [$input];
  $files=[];
  foreach($input['name'] as $i=>$name){$files[]=['name'=>$name,'tmp_name'=>$input['tmp_name'][$i]??'','size'=>$input['size'][$i]??0,'error'=>$input['error'][$i]??UPLOAD_ERR_NO_FILE];}
  return $files;
}
function crm_commercial_save_uploads(PDO $pdo,int $cid,int $attendanceId,int $moveId,int $uid,array $input): int {
  $files=crm_commercial_uploaded_files($input);$count=0;
  $types=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
  $newPaths=[];
  try{
    foreach($files as $f){
      $error=(int)($f['error']??UPLOAD_ERR_NO_FILE);
      if($error===UPLOAD_ERR_NO_FILE)continue;
      if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Falha ao receber um arquivo (código '.$error.').');
      $size=(int)($f['size']??0);
      if($size<1 || $size>12*1024*1024)throw new RuntimeException('Cada arquivo deve ter no máximo 12 MB.');
      if($count>=8)throw new RuntimeException('Limite de 8 anexos por tramitação.');
      $tmp=(string)($f['tmp_name']??'');
      if(!is_uploaded_file($tmp))throw new RuntimeException('Arquivo recebido de origem inválida.');
      $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';
      if(!isset($types[$mime]))throw new RuntimeException('Tipo de anexo não permitido. Utilize PDF, imagem, DOCX ou XLSX.');
      $original=basename(str_replace('\\','/',(string)($f['name']??'arquivo')));
      $original=trim(preg_replace('/[\x00-\x1F\x7F]/u','',$original)??'');
      $original=mb_substr($original,0,240,'UTF-8');
      if($original==='')$original='Anexo.'.$types[$mime];
      $storage=bin2hex(random_bytes(24)).'.'.$types[$mime];
      $path=crm_commercial_upload_dir($cid).'/'.$storage;
      if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Não foi possível guardar o arquivo no diretório privado.');
      chmod($path,0600);$newPaths[]=$path;
      $pdo->prepare('INSERT INTO crm_attachments (company_id,attendance_id,move_id,original_name,storage_name,mime_type,size_bytes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
        ->execute([$cid,$attendanceId,$moveId?:null,$original,$storage,$mime,$size,$uid]);
      $count++;
    }
    return $count;
  }catch(Throwable $e){foreach($newPaths as $p)@unlink($p);throw $e;}
}
function crm_commercial_phone(string $text): string {
  $digits=preg_replace('/\D+/','',$text)??'';
  if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;
  return strlen($digits)>=12 && strlen($digits)<=15?$digits:'';
}
}
