<?php
declare(strict_types=1);
/**
 * Otimiza imagens antigas do repositório privado de Pessoas.
 * A execução padrão é SIMULAÇÃO; --commit habilita atualização de metadados.
 * Guarda os blobs antigos, sem excluí-los: recuperação sempre possível.
 * Execute apenas pelo terminal (PHP CLI), usando o usuário do PHP-FPM.
 *
 * Uso: php tools/otimizar_anexos_pessoas.php --company=1 --limit=100 [--commit]
 */
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$args=$argv;$companyId=0;$limit=100;$commit=false;$after='';
foreach ($args as $a) {
    if (preg_match('/^--company=(\d+)$/D',$a,$m)) $companyId=(int)$m[1];
    elseif (preg_match('/^--limit=(\d+)$/D',$a,$m)) $limit=min(500,max(1,(int)$m[1]));
    elseif (preg_match('/^--after=([0-9a-f]{64})$/D',$a,$m)) $after=$m[1];
    elseif ($a==='--commit') $commit=true;
}
if ($companyId<=0) {
    fwrite(STDERR,"Uso: php tools/otimizar_anexos_pessoas.php --company=ID --limit=100 [--after=SHA256] [--commit]\n");exit(2);
}
require_once __DIR__.'/../config/pdo.php';
require_once __DIR__.'/../config/person_attachments.php';
$st=$pdo->prepare("SELECT storage_sha256, MIN(mime_type) mime_type, MAX(size_stored) stored_size,
  COUNT(*) refs, COUNT(DISTINCT mime_type) mime_variants, COUNT(DISTINCT size_stored) size_variants
  FROM nexa_person_attachments WHERE company_id=? AND storage_sha256 > ?
  AND mime_type IN ('image/jpeg','image/png','image/bmp','image/x-ms-bmp')
  GROUP BY storage_sha256 ORDER BY storage_sha256 LIMIT ".(int)$limit);
$st->execute([$companyId,$after]);
$entries=$st->fetchAll(PDO::FETCH_ASSOC);
$lastSha=$after;
$scanned=0;$eligible=0;$saved=0;$changed=0;$skipped=0;$errors=0;
echo ($commit?'EXECUÇÃO AUTORIZADA':'SIMULAÇÃO / DRY RUN')." — empresa {$companyId} | até {$limit} blobs distintos\n";
foreach ($entries as $r) {
    $scanned++;
    $sha=(string)$r['storage_sha256'];
    $lastSha=$sha;
    $mime=(string)$r['mime_type'];
    $storedSize=(int)$r['stored_size'];
    $temp='';$generated=false;
    try {
        if ((int)$r['mime_variants']!==1 || (int)$r['size_variants']!==1 || $storedSize<=0
          || $storedSize>100*1024*1024 || !preg_match('/^[0-9a-f]{64}$/D',$sha)) {
            $skipped++;continue;
        }
        $path=pa_file_path($companyId,$sha,false);
        if (!is_file($path) || is_link($path) || (int)filesize($path)!==$storedSize
            || !hash_equals($sha,hash_file('sha256',$path))) {
            throw new RuntimeException('Blob atual ausente ou divergente do SHA/size do catálogo.');
        }
        [$temp,$generated,$newMime,$originalSha,$oldSize]=pa_prepare_storage($path,$mime);
        if (!$generated) { $skipped++;continue; }
        $eligible++;
        $newSize=(int)filesize($temp);
        $newSha=hash_file('sha256',$temp);
        if ($newSize>= $oldSize || !preg_match('/^[0-9a-f]{64}$/D',$newSha)) {
            $skipped++;continue;
        }
        $saved+=$oldSize-$newSize;
        if (!$commit) {
            echo "  Elegível: blob #{$scanned} (vínculos={$r['refs']}, redução=".($oldSize-$newSize)." bytes)\n";
            continue;
        }
        $target=pa_file_path($companyId,$newSha,true);
        if (is_link($target)) throw new RuntimeException('Destino inesperado.');
        if (!is_file($target)) {
            $input=@fopen($temp,'rb');
            $output=@fopen($target,'x+b');
            if (!$input || !$output) {
                if ($input) fclose($input);
                if ($output) fclose($output);
                throw new RuntimeException('Falha ao abrir blob privado de destino.');
            }
            try {
                $copied=stream_copy_to_stream($input,$output);
                if ($copied!==$newSize || !fflush($output)) throw new RuntimeException('Erro na escrita do blob novo.');
                @chmod($target,0600);
            } catch(Throwable $e) {
                fclose($input);fclose($output);@unlink($target);throw $e;
            }
            fclose($input);fclose($output);
        }
        if (!is_file($target) || is_link($target) || (int)filesize($target)!==$newSize
            || !hash_equals($newSha,hash_file('sha256',$target))) {
            throw new RuntimeException('Verificação de integridade do blob otimizado falhou.');
        }
        $pdo->beginTransaction();
        try {
            // Atualiza todos os cadastros que apontam para o mesmo conteúdo,
            // mantendo intactos original_sha256 e size_original.
            $st2=$pdo->prepare('SELECT id,original_name FROM nexa_person_attachments WHERE company_id=? AND storage_sha256=? FOR UPDATE');
            $st2->execute([$companyId,$sha]);
            $refs=$st2->fetchAll(PDO::FETCH_ASSOC);
            if (count($refs)!==(int)$r['refs']) throw new RuntimeException('Quantidade de vínculos mudou durante a otimização.');
            $upd=$pdo->prepare('UPDATE nexa_person_attachments SET storage_sha256=?,size_stored=?,mime_type=?,original_name=? WHERE id=? AND company_id=? AND storage_sha256=?');
            foreach ($refs as $link) {
                $name=(string)$link['original_name'];
                if ($newMime==='image/jpeg' && in_array($mime,['image/bmp','image/x-ms-bmp'],true)) {
                    $name=pa_clean_filename(pathinfo($name,PATHINFO_FILENAME).'.jpg');
                }
                $upd->execute([$newSha,$newSize,$newMime,$name,(int)$link['id'],$companyId,$sha]);
                if ($upd->rowCount()!==1) throw new RuntimeException('Falha na atualização de um vínculo.');
            }
            $pdo->commit();
            $changed++;
            echo "  Atualizado blob #{$scanned}: ".count($refs)." vínculo(s), redução lógica ".($oldSize-$newSize)." bytes\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR,"  ERRO no blob #{$scanned}: {$e->getMessage()}\n");
    } finally {
        if ($generated && $temp!=='' && is_file($temp)) @unlink($temp);
    }
}
echo "RESULTADO: lidos={$scanned} aptos={$eligible} atualizados={$changed} ignorados={$skipped} erros={$errors} redução lógica potencial={$saved} bytes\n";
if ($scanned>0) echo "PRÓXIMO LOTE: --after={$lastSha} (o último SHA lido; avançar mesmo quando não houver economia)\n";
if (!$commit) echo "Nada foi alterado. Para gravar os novos blobs e atualizar os vínculos, use --commit APÓS backup.\n";
else echo "Os blobs antigos foram PRESERVADOS. A economia física depende de uma limpeza futura autorizada e auditada.\n";
exit($errors?1:0);
