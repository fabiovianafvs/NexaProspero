<?php
declare(strict_types=1);
// Somente incluído por monitor_saude.php após autenticação e POST CSRF.
if (!defined('NEXA_HEALTH_MANUAL_ALLOWED') || NEXA_HEALTH_MANUAL_ALLOWED !== true) {
    http_response_code(403);
    exit;
}

function nexa_health_proc_cpu(): ?array {
    $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
    if (!is_array($line) || !isset($line[0]) || !preg_match('/^cpu\\s+(.+)$/', $line[0], $m)) return null;
    $parts = preg_split('/\\s+/', trim($m[1]));
    if (!is_array($parts) || count($parts) < 5) return null;
    $nums = array_map('intval', $parts);
    return [array_sum($nums), ($nums[3] ?? 0)+($nums[4] ?? 0)];
}
function nexa_health_cpu_now(): ?float {
    $a = nexa_health_proc_cpu();
    if ($a === null) return null;
    usleep(200000); // Amostra única de 200ms, só quando o usuário clicar.
    $b = nexa_health_proc_cpu();
    if ($b === null) return null;
    $total=$b[0]-$a[0]; $idle=$b[1]-$a[1];
    return $total>0 ? round(max(0.0,min(100.0,100.0*(1.0-$idle/$total))),1) : null;
}
function nexa_health_memory(): array {
    $entries=[];
    foreach ((@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: []) as $line) {
        if (preg_match('/^([A-Za-z_()]+):\\s+(\\d+)\\s+kB/', $line, $m)) $entries[$m[1]]=(int)$m[2]*1024;
    }
    $total=$entries['MemTotal']??0; $available=$entries['MemAvailable']??0;
    return ['total_bytes'=>$total,'available_bytes'=>$available,
            'used_pct'=>$total>0 ? round((1-$available/$total)*100,1) : null,
            'swap_total_bytes'=>$entries['SwapTotal']??0];
}
function nexa_health_disk(): array {
    $total=@disk_total_space('/'); $free=@disk_free_space('/');
    $used=($total!==false && $free!==false && $total>0) ? round(100*(1-$free/$total),1) : null;
    return ['total_bytes'=>$total?:null,'free_bytes'=>$free?:null,'used_pct'=>$used];
}
function nexa_health_processes(): array {
    $processes=['apache2'=>0,'mysqld'=>0,'mariadbd'=>0,'chrome'=>0,'whatsapp_node'=>0];
    $sessions=[]; $rssKb=0;
    foreach ((glob('/proc/[0-9]*/comm') ?: []) as $commPath) {
        $comm=trim((string)@file_get_contents($commPath));
        if ($comm==='apache2') $processes['apache2']++;
        if ($comm==='mysqld') $processes['mysqld']++;
        if ($comm==='mariadbd') $processes['mariadbd']++;
        if (!in_array($comm,['chrome','chromium','node','nodejs'],true)) continue;
        $dir=dirname($commPath);
        $cmd=@file_get_contents($dir.'/cmdline');
        if ($cmd === false || !str_contains($cmd, '/opt/nexa-whatsapp-service/')) continue;
        if (in_array($comm,['node','nodejs'],true)) { $processes['whatsapp_node']++; continue; }
        $processes['chrome']++;
        if (preg_match('/session-company-[0-9]+/', $cmd, $matches)) $sessions[$matches[0]]=true;
        $status=@file($dir.'/status', FILE_IGNORE_NEW_LINES);
        if (is_array($status)) foreach ($status as $line) {
            if (preg_match('/^VmRSS:\\s+(\\d+)\\s+kB/', $line,$m)) { $rssKb+=(int)$m[1]; break; }
        }
    }
    return [
        'servicos'=>['apache2'=>$processes['apache2']>0 ? 'ativo' : 'não detectado',
                    'mysql'=>$processes['mysqld']+$processes['mariadbd']>0 ? 'ativo' : 'não detectado',
                    'nexa-whatsapp-service (processos)'=>$processes['whatsapp_node']>0 ? 'detectado' : 'não verificado'],
        'whatsapp'=>['chrome_processos'=>$processes['chrome'],
                    'sessoes_detectadas'=>count($sessions),
                    'rss_mib_somada'=>round($rssKb/1024,1)],
    ];
}
function nexa_health_check(string $label,string $url,?string $host=null): array {
    if (!function_exists('curl_init')) {
        return ['nome'=>$label,'http'=>null,'ms'=>null,'ok'=>false,'erro'=>'cURL não disponível'];
    }
    $ch=curl_init($url);
    if ($ch===false) return ['nome'=>$label,'http'=>null,'ms'=>null,'ok'=>false,'erro'=>'Falha no cURL'];
    $headers=$host!==null ? ['Host: '.$host] : [];
    curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'NexaSaudeManual/1.0']);
    $start=microtime(true);
    $result=curl_exec($ch);
    $code=$result===false ? null : (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $error=$result===false ? 'Tempo esgotado / conexão indisponível' : null;
    curl_close($ch);
    return ['nome'=>$label,'http'=>$code,'ms'=>(int)round((microtime(true)-$start)*1000),
            'ok'=>$code!==null && $code>=200 && $code<400,'erro'=>$error];
}
function nexa_health_collect_manual(): array {
    $cpu=nexa_health_cpu_now();
    $ram=nexa_health_memory();
    $disk=nexa_health_disk();
    $proc=nexa_health_processes();
    // Destinos fixos, sem parametros fornecidos por navegador: protege de SSRF.
    $checks=[
      nexa_health_check('Portal do Suporte (público)','https://app.nexaprospero.com.br/suporte/login.php'),
      nexa_health_check('ERP (rota sem autenticação)','https://deposito-jn.nexaprospero.com.br/admin/pedidos.php'),
      nexa_health_check('Apache local / suporte','http://127.0.0.1/suporte/login.php','app.nexaprospero.com.br')
    ];
    $alerts=[];
    if (($ram['used_pct']??0)>=85) $alerts[]='Memória disponível baixa';
    if (($disk['used_pct']??0)>=90) $alerts[]='Uso de disco acima de 90%';
    if ($cpu!==null && $cpu>=90) $alerts[]='CPU elevada nesta amostra de 200ms';
    if (($proc['servicos']['apache2']??'')==='não detectado') $alerts[]='Processo Apache não detectado';
    if (($proc['servicos']['mysql']??'')==='não detectado') $alerts[]='Processo MySQL/MariaDB não detectado';
    if (array_filter($checks,static fn($v) => ($v['http']??null)!==null && empty($v['ok']))) $alerts[]='Uma rota HTTP respondeu com erro';
    if (array_filter($checks,static fn($v) => ($v['http']??null)===null)) $alerts[]='Uma verificação HTTP não foi concluída';
    $status=$alerts ? 'atenção' : 'normal';
    if (($proc['servicos']['apache2']??'')==='não detectado' || ($proc['servicos']['mysql']??'')==='não detectado') $status='crítico';
    return [
      'coletado_em_utc'=>gmdate('c'),'status'=>$status,'alertas'=>$alerts,
      'cpu_percent'=>$cpu,'load_1m'=>function_exists('sys_getloadavg') ? round((float)((sys_getloadavg()[0]??0)),2) : null,
      'ram'=>$ram,'disco'=>$disk,'servicos'=>$proc['servicos'],
      'http'=>$checks,'whatsapp'=>$proc['whatsapp'],'oom_24h'=>null,
    ];
}
