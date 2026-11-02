<?php
declare(strict_types=1);
/**
 * Anexos de Pessoas - armazenamento privado por empresa.
 * SEM DDL em requests; banco recebe apenas metadados.
 * Nunca disponibilizar a pasta de arquivos por HTTP/Nginx.
 */
function pa_base_dir(): string {
    $configured = trim((string)(getenv('NEXA_PERSON_ATTACHMENTS_DIR') ?: '/var/lib/nexa/anexos-pessoas'));
    if ($configured === '' || $configured[0] !== '/') throw new RuntimeException('Configure caminho absoluto NEXA_PERSON_ATTACHMENTS_DIR.');
    $root = realpath($configured);
    if ($root === false || !is_dir($root) || is_link($configured)) throw new RuntimeException('Pasta privada de anexos não provisionada. Consulte o manual de instalação.');
    $appRoot = realpath(dirname(__DIR__));
    $webRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    foreach ([$appRoot, $webRoot] as $public) {
        if ($public && ($root === $public || str_starts_with($root, $public.'/'))) {
            throw new RuntimeException('A pasta de anexos deve ficar fora dos arquivos públicos da aplicação.');
        }
    }
    if (!is_writable($root) || !is_readable($root)) throw new RuntimeException('Pasta privada sem acesso de leitura/gravação.');
    return $root;
}
function pa_company_dir(int $companyId): string {
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
    $root = pa_base_dir();
    $dir = $root.'/company-'.$companyId;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Falha ao criar pasta privada da empresa.');
    $resolved = realpath($dir);
    if (!$resolved || is_link($dir) || !str_starts_with($resolved, $root.'/') || !is_writable($resolved)) throw new RuntimeException('Pasta da empresa inválida.');
    return $resolved;
}
function pa_file_path(int $companyId, string $sha256, bool $create = false): string {
    if (!preg_match('/^[0-9a-f]{64}$/D', $sha256)) throw new RuntimeException('Identificador de anexo inválido.');
    $company = pa_company_dir($companyId);
    $dir = $company.'/'.substr($sha256,0,2);
    if ($create && !is_dir($dir) && !@mkdir($dir,0700,true)) throw new RuntimeException('Falha ao criar pasta privada.');
    if (is_link($dir)) throw new RuntimeException('Diretório de anexo não pode ser link simbólico.');
    return $dir.'/'.$sha256.'.dat';
}
function pa_clean_filename(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = trim((string)(preg_replace('/[\x00-\x1f\x7f]/u','',$name) ?? ''));
    if ($name === '' || $name === '.' || $name === '..') $name = 'anexo';
    return function_exists('mb_substr') ? mb_substr($name,0,240,'UTF-8') : substr($name,0,240);
}
function pa_mime_file(string $path, string $name): string {
    $mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allow = ['image/jpeg','image/png','image/webp','image/bmp','image/x-ms-bmp',
        'application/pdf','application/msword','application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/plain'];
    if ($mime === 'application/zip' && $ext === 'docx') return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    if ($mime === 'application/zip' && $ext === 'xlsx') return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    if ($mime === 'application/octet-stream' && $ext === 'bmp' && @getimagesize($path)) return 'image/bmp';
    if (!in_array($mime, $allow, true)) throw new RuntimeException('Tipo de arquivo não aceito: '.$mime.'. Permitidos: imagens, PDF, DOC/DOCX, XLS/XLSX e TXT.');
    return $mime;
}
/**
 * Otimização conservadora de imagens para novos anexos.
 * PDF, Office, TXT e WebP permanecem byte a byte inalterados.
 * JPEG sem EXIF/ICC: recodificar apenas quando reduzir no mínimo 15%.
 * PNG simples: recomprimir sem perda de pixels e aceitar apenas economia >= 5%.
 * BMP: converter para JPEG apenas quando for menor.
 */
function pa_png_simple(string $path): bool {
    $fp = @fopen($path, 'rb');
    if (!$fp) return false;
    try {
        if (fread($fp, 8) !== "\x89PNG\r\n\x1a\n") return false;
        $chunks = 0;
        while (!feof($fp) && ++$chunks <= 10000) {
            $header = fread($fp, 8);
            if (strlen((string)$header) !== 8) return false;
            $unpack = unpack('Nlen', substr($header, 0, 4));
            $len = (int)($unpack['len'] ?? 0);
            $type = substr($header, 4, 4);
            if (!in_array($type, ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS'], true)) return false;
            if ($len < 0 || $len > 100 * 1024 * 1024) return false;
            if (fseek($fp, $len + 4, SEEK_CUR) !== 0) return false;
            if ($type === 'IEND') return true;
        }
        return false;
    } finally { fclose($fp); }
}
/** Bloqueia recodificação de JPEG contendo metadados de orientação/cor/impressão. */
function pa_jpeg_simple(string $path): bool {
    $fp=@fopen($path,'rb');
    if (!$fp) return false;
    try {
        if (fread($fp,2)!=="\xff\xd8") return false;
        for ($i=0;$i<1024;$i++) {
            $markerStart=fread($fp,1);
            if ($markerStart!=="\xff") return false;
            do { $m=fread($fp,1); } while ($m==="\xff");
            if ($m===false || $m==='') return false;
            $marker=ord($m);
            if ($marker===0xda) return true; // Start of Scan
            if ($marker===0xd9) return false;
            if ($marker===0xd8 || $marker===0x01 || ($marker>=0xd0 && $marker<=0xd7)) continue;
            // APP1/APP2/APP13/APP14: EXIF, ICC, IPTC e Adobe.
            if (in_array($marker,[0xe1,0xe2,0xed,0xee],true)) return false;
            $lenBytes=fread($fp,2);
            if (strlen((string)$lenBytes)!==2) return false;
            $v=unpack('nlen',$lenBytes);
            $len=(int)$v['len'];
            if ($len<2 || fseek($fp,$len-2,SEEK_CUR)!==0) return false;
        }
        return false;
    } finally {fclose($fp);}
}
function pa_prepare_storage(string $tmp, string $mime): array {
    $sourceSha = hash_file('sha256', $tmp);
    $originalSize = (int)filesize($tmp);
    $storageTmp = $tmp;
    $generated = false;
    if (!in_array($mime, ['image/bmp', 'image/x-ms-bmp', 'image/jpeg', 'image/png'], true)
        || $originalSize < 64 * 1024 || !function_exists('getimagesize')) {
        return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    }
    $dimensions = @getimagesize($tmp);
    $w = (int)($dimensions[0] ?? 0);
    $h = (int)($dimensions[1] ?? 0);
    if ($w < 1 || $h < 1 || $w * $h > 20000000 || (($dimensions['bits'] ?? 8) > 8)) {
        return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    }
    $bmp = in_array($mime, ['image/bmp', 'image/x-ms-bmp'], true);
    $jpeg = $mime === 'image/jpeg';
    $png = $mime === 'image/png';
    if ($bmp && ((string)(getenv('NEXA_PERSON_COMPRESS_BMP') ?: '1') === '0'
        || !function_exists('imagecreatefrombmp'))) return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    if ($jpeg && ((string)(getenv('NEXA_PERSON_COMPRESS_JPEG') ?: '1') === '0'
        || $originalSize < 256 * 1024 || !function_exists('imagecreatefromjpeg'))) return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    if ($png && ((string)(getenv('NEXA_PERSON_COMPRESS_PNG') ?: '1') === '0'
        || !function_exists('imagecreatefrompng') || !function_exists('imagepng') || !pa_png_simple($tmp))) {
        return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    }
    if (($bmp || $jpeg) && !function_exists('imagejpeg')) return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    // Preserva integralmente imagens JPEG com metadados EXIF/ICC/IPTC/Adobe.
    if ($jpeg && !pa_jpeg_simple($tmp)) {
        return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    }
    $image = $bmp ? @imagecreatefrombmp($tmp) : ($jpeg ? @imagecreatefromjpeg($tmp) : @imagecreatefrompng($tmp));
    if (!$image) return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
    $candidate = tempnam(sys_get_temp_dir(), 'nexa_img_');
    try {
        if ($candidate === false) return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
        if ($bmp) {
            $white = imagecreatetruecolor($w, $h);
            if (!$white) return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
            imagefill($white, 0, 0, imagecolorallocate($white, 255, 255, 255));
            imagecopy($white, $image, 0, 0, 0, 0, $w, $h);
            $ok = @imagejpeg($white, $candidate, 88);
            imagedestroy($white);
        } else {
            $ok = $jpeg ? @imagejpeg($image, $candidate, 88) : @imagepng($image, $candidate, 9);
        }
        $size = $ok ? (int)@filesize($candidate) : 0;
        $savingFraction = $jpeg ? .15 : ($png ? .05 : .0);
        if ($size > 0 && $size < $originalSize * (1 - $savingFraction)) {
            $storageTmp = $candidate;
            $generated = true;
            if ($bmp) $mime = 'image/jpeg';
            $candidate = false; // arquivo temporário fica sob responsabilidade do chamador
        }
    } finally {
        if ($candidate !== false) @unlink($candidate);
        imagedestroy($image);
    }
    return [$storageTmp, $generated, $mime, $sourceSha, $originalSize];
}
function pa_person_exists(PDO $pdo,int $companyId,int $personId): bool {
    $st=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$personId]);
    return (bool)$st->fetchColumn();
}
/** Entrada tmp sempre já criada por upload confiável ou stream do CLI importador. */
function pa_save(PDO $pdo,int $companyId,int $personId,string $name,string $tmp,int $userId,
                 ?string $sourceSystem=null,?string $sourceReference=null): array {
    if (!pa_person_exists($pdo,$companyId,$personId)) throw new RuntimeException('Pessoa não encontrada na empresa.');
    if (!is_file($tmp) || is_link($tmp)) throw new RuntimeException('Arquivo de origem indisponível.');
    $bytes = filesize($tmp);
    if ($bytes === false || $bytes < 1 || $bytes > 100*1024*1024) throw new RuntimeException('Arquivo vazio ou maior que 100 MB.');
    $name = pa_clean_filename($name);
    $mime = pa_mime_file($tmp,$name);
    [$temp,$generated,$mime,$sourceSha,$originalSize] = pa_prepare_storage($tmp,$mime);
    // Arquivo convertido para JPEG precisa ter extensão compatível no download.
    if ($generated && in_array($mime, ['image/jpeg'], true) && in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['bmp'],true)) {
        $baseName = pathinfo($name,PATHINFO_FILENAME);
        $name = pa_clean_filename($baseName.'.jpg');
    } else {
        $extension = strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $expected = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
            'image/bmp'=>'bmp','image/x-ms-bmp'=>'bmp','application/pdf'=>'pdf','text/plain'=>'txt'];
        if (isset($expected[$mime])) {
            $validExtensions = $mime === 'image/jpeg' ? ['jpg','jpeg'] : [$expected[$mime]];
            if (!in_array($extension,$validExtensions,true)) {
                $base = $extension === '' ? $name : pathinfo($name,PATHINFO_FILENAME);
                $name = pa_clean_filename($base.'.'.$expected[$mime]);
            }
        }
    }
    try {
        $storedSha = hash_file('sha256',$temp);
        $storedSize = (int)filesize($temp);
        $target=pa_file_path($companyId,$storedSha,true);
        if (is_link($target)) throw new RuntimeException('Arquivo privado inesperado.');
        if (!is_file($target)) {
            $input=fopen($temp,'rb');
            $output=@fopen($target,'x+b');
            if (!$input) throw new RuntimeException('Falha ao ler anexo.');
            if (!$output) { fclose($input); if (!is_file($target)) throw new RuntimeException('Falha ao criar anexo privado.'); }
            else {
                try {
                    $copied=stream_copy_to_stream($input,$output);
                    if ($copied !== $storedSize) throw new RuntimeException('Falha ao copiar arquivo privado.');
                    fflush($output); chmod($target,0600);
                } catch (Throwable $e) { fclose($input); fclose($output); @unlink($target); throw $e; }
                fclose($input); fclose($output);
            }
        }
        if (!is_file($target) || (int)filesize($target) !== $storedSize) throw new RuntimeException('Anexo privado não íntegro.');
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $st=$pdo->prepare('INSERT INTO nexa_person_attachments(company_id,person_id,storage_sha256,original_sha256,original_name,mime_type,size_original,size_stored,source_system,source_reference,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $st->execute([$companyId,$personId,$storedSha,$sourceSha,$name,$mime,$originalSize,$storedSize,$sourceSystem,$sourceReference,$userId]);
            $newId=(int)$pdo->lastInsertId();
            pa_audit($pdo,$companyId,$personId,$newId,$userId,$sourceSystem?'IMPORT':'UPLOAD');
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id'=>$newId,'original_bytes'=>$originalSize,'stored_bytes'=>$storedSize,'compressed'=>$generated,'sha256'=>$storedSha];
    } finally { if ($generated) @unlink($temp); }
}
function pa_list(PDO $pdo,int $companyId,int $personId): array {
    $st=$pdo->prepare('SELECT id, original_name, mime_type, size_original, size_stored, created_at, source_system FROM nexa_person_attachments WHERE company_id=? AND person_id=? ORDER BY id DESC LIMIT 500');
    $st->execute([$companyId,$personId]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function pa_get(PDO $pdo,int $companyId,int $personId,int $id): ?array {
    $st=$pdo->prepare('SELECT * FROM nexa_person_attachments WHERE company_id=? AND person_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$personId,$id]);return $st->fetch(PDO::FETCH_ASSOC)?:null;
}
function pa_delete(PDO $pdo,int $companyId,int $personId,int $id): void {
    $st=$pdo->prepare('DELETE FROM nexa_person_attachments WHERE company_id=? AND person_id=? AND id=?');
    $st->execute([$companyId,$personId,$id]);
    // Blob é mantido para recuperação/auditoria. Limpeza em rotina separada de manutenção.
}

function pa_audit(PDO $pdo,int $companyId,int $personId,int $attachmentId,int $userId,string $action): void {
    if (!in_array($action,['UPLOAD','DOWNLOAD','DELETE','IMPORT'],true)) throw new RuntimeException('Ação de auditoria inválida.');
    $st=$pdo->prepare('INSERT INTO nexa_person_attachment_audit(company_id,person_id,attachment_id,user_id,action_name,created_at) VALUES(?,?,?,?,?,NOW())');
    $st->execute([$companyId,$personId,$attachmentId,$userId,$action]);
}
