<?php
declare(strict_types=1);
/**
 * Uso de armazenamento por empresa - somente consultas de leitura, executadas manualmente.
 * Não identifica por empresa os arquivos públicos sem vínculo confiável.
 * Nunca acessa conteúdo ou devolve nomes de anexos ao Portal do Suporte.
 */
function nss_human(int|float|null $bytes): string {
    if ($bytes === null) return 'Não disponível';
    $b = max(0, (float)$bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return number_format($b, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
}
function nss_table_exists(PDO $pdo, string $name): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE' LIMIT 1");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}
function nss_column_exists(PDO $pdo, string $table, string $column): bool {
    $st=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $st->execute([$table,$column]);
    return (bool)$st->fetchColumn();
}
function nss_attachments(PDO $pdo, int $companyId): array {
    $result = ['available'=>false,'references'=>0,'files'=>0,'original'=>0,'stored'=>0,'physical'=>0,'missing'=>0,'issue'=>''];
    if (!nss_table_exists($pdo,'nexa_person_attachments')) {
        $result['issue']='Tabela de anexos de pessoas ainda não instalada.';
        return $result;
    }
    $result['available']=true;
    $st=$pdo->prepare('SELECT storage_sha256, MAX(size_stored) AS stored_size, SUM(size_original) AS original_size, COUNT(*) AS refs FROM nexa_person_attachments WHERE company_id=? GROUP BY storage_sha256');
    $st->execute([$companyId]);
    $files=[];
    while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
        $sha=(string)$r['storage_sha256'];
        if (!preg_match('/^[0-9a-f]{64}$/D', $sha)) continue;
        $result['files']++;
        $result['references']+=(int)$r['refs'];
        $result['original']+=(int)$r['original_size'];
        $result['stored']+=(int)$r['stored_size'];
        $files[$sha]=(int)$r['stored_size'];
    }
    // Conferência física somente no diagnóstico explícito, sem criar pastas nem ler arquivos.
    $base = trim((string)(getenv('NEXA_PERSON_ATTACHMENTS_DIR') ?: '/var/lib/nexa/anexos-pessoas'));
    $root = realpath($base);
    if (!$root || !is_dir($root) || is_link($base)) {
        $result['physical']=$result['files']===0 ? 0 : null;
        $result['issue']='Pasta privada de pessoas inacessível nesta instalação; exibindo metadados.';
        return $result;
    }
    $dir=$root.'/company-'.$companyId;
    if (!is_dir($dir)) {
        $result['physical']=$result['files']===0 ? 0 : null;
        $result['missing']=$result['files'];
        if ($result['files'] > 0) $result['issue']='Pasta privada desta empresa não encontrada.';
        return $result;
    }
    if (is_link($dir) || !is_readable($dir)) {
        $result['physical']=null;
        $result['issue']='Sem permissão para conferir arquivos privados desta empresa.';
        return $result;
    }
    foreach ($files as $sha=>$expected) {
        $file=$dir.'/'.substr($sha,0,2).'/'.$sha.'.dat';
        if (is_link($file) || !is_file($file) || !is_readable($file)) { $result['missing']++; continue; }
        $size=@filesize($file);
        if ($size===false || (int)$size!==$expected) { $result['missing']++; continue; }
        $result['physical']+=(int)$size;
    }
    if ($result['missing']>0) $result['issue']='Há '.$result['missing'].' arquivo(s) ausente(s) ou com tamanho diferente do catálogo. Não execute limpeza.';
    return $result;
}
/**
 * Soma somente arquivos regulares. Não segue links simbólicos e limita carga na VPS.
 */
function nss_walk_dir(string $dir, int $maxFiles=10000, float $maxSeconds=2.0): array {
    $out=['bytes'=>0,'files'=>0,'available'=>true,'partial'=>false];
    if (!file_exists($dir)) return $out;
    if (!is_dir($dir) || is_link($dir) || !is_readable($dir)) {
        $out['available']=false;
        return $out;
    }
    $stack=[$dir];$started=microtime(true);
    while ($stack) {
        if ($out['files'] >= $maxFiles || microtime(true)-$started > $maxSeconds) {
            $out['partial']=true;break;
        }
        $next=array_pop($stack);
        try { $iter=new DirectoryIterator($next); }
        catch (Throwable $e) { $out['available']=false;break; }
        foreach ($iter as $file) {
            if ($file->isDot() || $file->isLink()) continue;
            if ($file->isDir()) $stack[]=$file->getPathname();
            elseif ($file->isFile()) {
                $size=$file->getSize();
                $out['bytes']+=max(0,(int)$size);$out['files']++;
            }
            if ($out['files'] >= $maxFiles || microtime(true)-$started > $maxSeconds) {
                $out['partial']=true;break;
            }
        }
    }
    return $out;
}
function nss_product_images(PDO $pdo, int $companyId, string $appRoot): array {
    $out=['bytes'=>0,'files'=>0,'available'=>true,'partial'=>false,'unmapped'=>0];
    if (!nss_table_exists($pdo,'produtos') || !nss_column_exists($pdo,'produtos','imagem') || !nss_column_exists($pdo,'produtos','company_id')) {
        $out['available']=false;return $out;
    }
    $st=$pdo->prepare('SELECT DISTINCT imagem FROM produtos WHERE company_id=? AND imagem IS NOT NULL AND imagem<>\'\' LIMIT 10001');
    $st->execute([$companyId]);
    $rows=$st->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows)>10000) { $out['partial']=true; $rows=array_slice($rows,0,10000); }
    foreach ($rows as $file) {
        $file=trim((string)$file);
        if ($file==='' || basename($file)!==$file || !preg_match('/^[a-zA-Z0-9._ -]{1,255}$/D',$file)) {
            $out['unmapped']++;continue;
        }
        $path=$appRoot.'/uploads/products/'.$file;
        if (is_link($path) || !is_file($path)) { $out['unmapped']++;continue; }
        $size=@filesize($path);
        if ($size===false) { $out['unmapped']++;continue; }
        $out['files']++;$out['bytes']+=(int)$size;
    }
    return $out;
}
function nss_file_categories(PDO $pdo, int $companyId): array {
    $root=dirname(__DIR__);
    // Pastas que já têm segregação explícita por ID de empresa.
    $paths=[
        'XML fiscais importados' => 'uploads/fiscal_xml/'.$companyId,
        'NFS-e (arquivos fiscais)' => 'storage/fiscal/nfse/'.$companyId,
        'Pacotes e relatórios contábeis' => 'storage/accounting/empresa_'.$companyId,
        'Fotos e mapas de danos (O.S.)' => 'storage/os_damage_maps/company_'.$companyId,
        'Certificados digitais (arquivos)' => 'uploads/certificados/'.$companyId,
    ];
    $out=[];
    foreach ($paths as $title=>$relative) $out[$title]=nss_walk_dir($root.'/'.$relative);
    $out['Imagens de produtos vinculadas']=nss_product_images($pdo,$companyId,$root);
    return $out;
}
/**
 * Estimativa de ocupação de linhas SQL (InnoDB): COUNT indexado por company_id
 * multiplicado pelo tamanho médio global da tabela, incluindo índices.
 * Não equivale a bytes físicos reservados por empresa no banco compartilhado.
 */
function nss_database_estimate(PDO $pdo, int $companyId): array {
    $out=['bytes'=>0,'rows'=>0,'tables'=>0,'excluded'=>0,'partial'=>false,'detail'=>[]];
    $sql="SELECT t.TABLE_NAME table_name, t.TABLE_ROWS table_rows,
        (COALESCE(t.DATA_LENGTH,0)+COALESCE(t.INDEX_LENGTH,0)) table_bytes,
        EXISTS(SELECT 1 FROM information_schema.STATISTICS s
           WHERE s.TABLE_SCHEMA=t.TABLE_SCHEMA AND s.TABLE_NAME=t.TABLE_NAME
           AND s.COLUMN_NAME='company_id' AND s.SEQ_IN_INDEX=1) indexed
        FROM information_schema.TABLES t
        JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=t.TABLE_SCHEMA
          AND c.TABLE_NAME=t.TABLE_NAME AND c.COLUMN_NAME='company_id'
        WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' ORDER BY t.TABLE_NAME";
    $tables=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $started=microtime(true);
    foreach ($tables as $row) {
        if (!isset($row['table_name']) || !preg_match('/^[a-zA-Z0-9_]+$/D',(string)$row['table_name'])) continue;
        if (!(int)$row['indexed']) { $out['excluded']++;continue; }
        if (microtime(true)-$started>12 || $out['tables']>=200) { $out['partial']=true;break; }
        $name=(string)$row['table_name'];
        try {
            $st=$pdo->prepare('SELECT COUNT(*) FROM `'.$name.'` WHERE company_id=?');
            $st->execute([$companyId]);
            $count=(int)$st->fetchColumn();
        } catch (Throwable $e) { $out['excluded']++;continue; }
        $estimated=max(0,(int)$row['table_bytes']);
        $tableRows=max(1,(int)$row['table_rows'],$count);
        $perRow=$estimated/$tableRows;
        $bytes=(int)round($perRow*$count);
        $out['bytes']+=$bytes;$out['rows']+=$count;$out['tables']++;
        if ($count>0) $out['detail'][]=['table'=>$name,'rows'=>$count,'bytes'=>$bytes];
    }
    usort($out['detail'],static fn(array $a,array $b)=>$b['bytes']<=>$a['bytes']);
    return $out;
}
function nss_report(PDO $pdo, int $companyId): array {
    $report=['company_id'=>$companyId,'collected_at'=>gmdate('c')];
    $report['attachments']=nss_attachments($pdo,$companyId);
    $report['files']=nss_file_categories($pdo,$companyId);
    $report['db']=nss_database_estimate($pdo,$companyId);
    $other=0;
    $unavailable=[];
    foreach ($report['files'] as $label=>$value) {
        $other+=(int)$value['bytes'];
        if (!$value['available'] || $value['partial']) $unavailable[]=$label;
    }
    $report['other_bytes']=$other;
    $report['partial_sections']=$unavailable;
    $physical=$report['attachments']['physical'];
    $report['total_estimate']=$physical===null ? null : $other+(int)$physical+(int)$report['db']['bytes'];
    return $report;
}
