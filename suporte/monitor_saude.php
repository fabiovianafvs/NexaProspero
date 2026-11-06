<?php
declare(strict_types=1);
require_once __DIR__ . '/monitor_saude_auth.php';
define('NEXA_HEALTH_OPS_ALLOWED', true);
require_once __DIR__ . '/monitor_saude_acoes.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function health_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function health_percent($v): string { return is_numeric($v) ? number_format((float)$v,1,',','.') . '%' : '—'; }
function health_gib($v): string { return is_numeric($v) ? number_format((float)$v/1073741824,2,',','.') . ' GB' : '—'; }
function health_label($state): string {
  return match((string)$state) { 'normal','ativo','online' => 'ok', 'crítico','inativo','offline' => 'bad', default => 'warn' };
}
function health_json(string $p): ?array {
  if (!is_readable($p) || filesize($p) > 5*1024*1024) return null;
  $data = json_decode((string)file_get_contents($p), true);
  return is_array($data) ? $data : null;
}
function health_sparkline(array $items, string $field, string $stroke): string {
  $values=[];
  foreach ($items as $item) {
    $val=$item[$field] ?? null;
    if (!is_numeric($val)) continue;
    $values[]=max(0.0,min(100.0,(float)$val));
  }
  if (count($values)<2) return '<span class="muted">Aguardando histórico suficiente.</span>';
  $n=count($values);
  $points=[];
  foreach ($values as $i=>$v) $points[]=number_format($i*690/max(1,$n-1),1,'.','') . ',' . number_format(85-$v*0.75,1,'.','');
  return '<svg class="chart" viewBox="0 0 700 100" role="img" aria-label="Evolução de '.health_e($field).' nas últimas 24 horas"><line x1="0" y1="85" x2="700" y2="85" stroke="#d9e5f6"/><line x1="0" y1="47" x2="700" y2="47" stroke="#e6edf7"/><polyline fill="none" stroke="'.health_e($stroke).'" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" points="'.implode(' ',$points).'"/></svg>';
}
// Sem cron, sem GET de monitoramento, sem coleta em segundo plano.
// Coleta sempre por POST autenticado e protegido por CSRF.
if (!isset($_SESSION['nexa_health_manual_token']) || !is_string($_SESSION['nexa_health_manual_token'])) {
    $_SESSION['nexa_health_manual_token'] = bin2hex(random_bytes(24));
}
$manualToken = (string)$_SESSION['nexa_health_manual_token'];
$message = (string)($_SESSION['nexa_health_notice'] ?? '');
unset($_SESSION['nexa_health_notice']);
$health = isset($_SESSION['nexa_health_manual_snapshot']) && is_array($_SESSION['nexa_health_manual_snapshot'])
    ? $_SESSION['nexa_health_manual_snapshot'] : null;
$history = isset($_SESSION['nexa_health_manual_history']) && is_array($_SESSION['nexa_health_manual_history'])
    ? $_SESSION['nexa_health_manual_history'] : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($manualToken, (string)($_POST['nexa_health_token'] ?? ''))) {
        http_response_code(403);
        $message = 'Sessão inválida para atualizar. Reabra a página e tente novamente.';
    } elseif (isset($_POST['nexa_ops_action'])) {
        // Botões de manutenção: exigem a senha master do Nexa Próspero.
        $opsAction = (string)$_POST['nexa_ops_action'];
        $fails = array_values(array_filter((array)($_SESSION['nexa_ops_fails'] ?? []), static fn($t) => (int)$t > time() - 900));
        if (!nexa_ops_available()) {
            $message = nexa_ops_unavailable_reason();
        } elseif (!isset(NEXA_OPS_ACTIONS[$opsAction])) {
            $message = 'Ação inválida.';
        } elseif (count($fails) >= 5) {
            $message = 'Muitas tentativas com senha incorreta. Aguarde 15 minutos.';
        } elseif ((time() - (int)($_SESSION['nexa_ops_last'] ?? 0)) < 60) {
            $message = 'Aguarde 1 minuto entre ações de manutenção.';
        } else {
            $master = nexa_ops_check_master_password($pdo, (string)($_POST['master_password'] ?? ''));
            if (!$master) {
                $fails[] = time();
                $message = 'Senha master incorreta. Nenhuma ação foi executada.';
                error_log('[Nexa][Saúde] Senha master incorreta para "' . $opsAction . '" pelo suporte ' . (string)($user['email'] ?? ''));
            } else {
                try {
                    $reqId = nexa_ops_request($opsAction, (string)($user['email'] ?? '') . ' / master ' . (string)$master['email']);
                    $_SESSION['nexa_ops_last'] = time();
                    $_SESSION['nexa_ops_last_id'] = $reqId;
                    error_log('[Nexa][Saúde] Ação "' . $opsAction . '" pedida por ' . (string)($user['email'] ?? '') . ' (master ' . (string)$master['email'] . '), pedido ' . $reqId);
                    $message = $opsAction === 'reiniciar_vps'
                        ? 'Pedido de reinício enviado. A VPS reinicia em alguns segundos e o sistema fica fora do ar por 1 a 3 minutos. Depois, atualize esta página.'
                        : 'Pedido enviado. Em alguns segundos o relatório aparece em "Manutenção da VPS" (atualize a página).';
                } catch (Throwable $err) {
                    $message = $err->getMessage();
                }
            }
        }
        $_SESSION['nexa_ops_fails'] = $fails;
    } elseif ((time() - (int)($_SESSION['nexa_health_last_request'] ?? 0)) < 15) {
        $message = 'Aguarde pelo menos 15 segundos entre consultas manuais.';
    } else {
        $_SESSION['nexa_health_last_request'] = time();
        define('NEXA_HEALTH_MANUAL_ALLOWED', true);
        require_once __DIR__ . '/monitor_saude_coleta_manual.php';
        try {
            $health = nexa_health_collect_manual();
            $_SESSION['nexa_health_manual_snapshot'] = $health;
            $history[] = [
                'em' => $health['coletado_em_utc'],
                'status' => $health['status'],
                'cpu_percent' => $health['cpu_percent'],
                'ram_percent' => $health['ram']['used_pct'],
                'disco_percent' => $health['disco']['used_pct'],
                'falhas_http' => count(array_filter($health['http'], static fn($x) => empty($x['ok']) && !empty($x['http']))),
            ];
            $_SESSION['nexa_health_manual_history'] = array_slice($history, -30);
            $history = $_SESSION['nexa_health_manual_history'];
        } catch (Throwable $err) {
            error_log('Nexa Health on demand: ' . get_class($err) . ': ' . $err->getMessage());
            $message = 'Não foi possível concluir a consulta manual. Confira o log PHP.';
        }
    }
    // PRG: evitar repeticao da consulta no Refresh do navegador.
    if (http_response_code() !== 403) {
        $_SESSION['nexa_health_notice'] = $message;
        header('Location: /suporte/monitor_saude.php', true, 303);
        exit;
    }
}
$last='Nenhuma consulta manual realizada';
$age=null;
if (is_array($health) && isset($health['coletado_em_utc'])) {
    try {
        $dt=new DateTimeImmutable((string)$health['coletado_em_utc']);
        $age=time()-$dt->getTimestamp();
        $last=$dt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s');
    } catch(Throwable $err) { $age=null; }
}
$hasSnapshot=is_array($health);
$fresh=$hasSnapshot; // Exibe a última leitura manual, com horário e aviso de possível desatualização.
$status=$hasSnapshot ? ($health['status'] ?? 'sem dados') : 'aguardando consulta';
$rows=array_slice(array_reverse($history),0,20);
$failed=0;
foreach ($history as $item) if (($item['falhas_http'] ?? 0)>0) $failed++;
$alerts=$hasSnapshot ? ($health['alertas'] ?? []) : [];
if (!is_array($alerts)) $alerts=[];
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- Atualizacao automatica desativada: somente sob demanda. -->
<title>Saúde do Sistema — Portal do Suporte | Nexa Próspero</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0b1f5b;--muted:#5b6475;--line:#dce7f5;--primary:#2454d6;--accent:#00b8ff;--danger:#b91c1c;--success:#15803d;--shadow:0 18px 44px rgba(11,31,91,.09)}*{box-sizing:border-box}html,body{margin:0;padding:0;background:radial-gradient(circle at 12% 10%,rgba(0,184,255,.10),transparent 28%),var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif}a{color:inherit}.shell{max-width:1360px;margin:0 auto;padding:22px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;border-radius:24px;background:linear-gradient(135deg,#0b1f5b,#2454d6 70%,#00b8ff 140%);color:#fff;box-shadow:0 20px 50px rgba(11,31,91,.18)}.brand{display:flex;align-items:center;gap:12px}.brand img{width:52px;height:52px;object-fit:contain;background:#fff;padding:5px;border-radius:15px}.brand strong{display:block;font-size:20px}.brand span{display:block;color:#dff6ff;font-size:12px;font-weight:700;margin-top:3px}.user{text-align:right;font-size:13px;color:#dff6ff}.user strong{display:block;font-size:14px;color:#fff}.user a{color:#fff;font-weight:850}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.nav a{color:#eaf8ff;text-decoration:none;font-weight:850;padding:7px 10px;border-radius:999px;font-size:14px}.nav a.active,.nav a:hover{background:rgba(255,255,255,.16);color:#fff}.hero{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:28px 4px 16px}.hero h1{font-size:32px;margin:0 0 8px;letter-spacing:-.04em}.hero p{margin:0;color:var(--muted);line-height:1.55}.status{display:inline-flex;align-items:center;gap:6px;padding:8px 13px;border-radius:999px;font-size:13px;font-weight:900}.status.ok{background:#dcfce7;color:#166534}.status.bad{background:#fee2e2;color:#991b1b}.status.warn{background:#fff3d8;color:#92400e}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:14px 0}.card,.panel{padding:20px;background:var(--card);border:1px solid rgba(36,84,214,.12);border-radius:22px;box-shadow:var(--shadow)}.cap{font-size:13px;color:var(--muted);font-weight:800}.metric{font-size:30px;font-weight:900;letter-spacing:-.04em;margin:8px 0 5px}.detail{font-size:12px;color:var(--muted)}.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.panel{margin-bottom:14px;min-width:0}.panel h2{font-size:18px;margin:0 0 14px}.chart{display:block;width:100%;height:auto;max-height:130px;margin-top:8px}.alert{padding:12px 15px;border-radius:14px;margin:0 0 12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px;font-weight:700}.muted{color:var(--muted)}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 7px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{color:var(--muted)}tr:last-child td{border-bottom:0}.table-scroll{overflow-x:auto}.chip{font-size:12px;font-weight:900;border-radius:999px;padding:5px 9px;display:inline-block;white-space:nowrap}.chip.ok{background:#dcfce7;color:#166534}.chip.bad{background:#fee2e2;color:#991b1b}.chip.warn{background:#fff3d8;color:#92400e}.notice{margin-top:16px;color:var(--muted);font-size:12px}.nowrap{white-space:nowrap}@media(max-width:990px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.two{grid-template-columns:1fr}}@media(max-width:580px){.shell{padding:12px}.grid{grid-template-columns:1fr}.topbar{align-items:flex-start}.hero h1{font-size:27px}.brand img{width:44px;height:44px}}
 .manual-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}.manual-button{appearance:none;border:0;cursor:pointer;background:#2454d6;color:#fff;padding:11px 18px;font-size:14px;font-weight:800;border-radius:12px;box-shadow:0 6px 18px rgba(36,84,214,.16)}.manual-button:hover{background:#153da7}.manual-button:focus-visible{outline:3px solid #00a7cc;outline-offset:3px}.help-text{font-size:12px;color:#5b6475}.status.secondary{background:#e7effa;color:#244e84}
</style>
</head>
<body><div class="shell">
<header class="topbar">
 <div class="brand"><img src="/assets/logo-nexa-v6-2.png" alt="N Nexa"><div><strong>Portal do Suporte</strong><span>Nexa Próspero</span><nav class="nav" aria-label="Menu do suporte"><a href="/suporte/index.php">Empresas</a><a href="/suporte/monitor_fiscal.php">Monitor Fiscal</a><a href="/suporte/whatsapp.php">WhatsApp</a><a class="active" href="/suporte/monitor_saude.php">Saúde do Sistema</a><a href="/suporte/armazenamento.php">Armazenamento</a></nav></div></div>
 <div class="user"><strong><?= health_e($user['name'] ?? '') ?></strong><?= health_e($user['email'] ?? '') ?> · <a href="/suporte/logout.php">Sair</a></div>
</header>
<section class="hero"><div><h1>Saúde do Sistema</h1><p>Infraestrutura, aplicação e recursos WhatsApp, consultados apenas quando você solicitar.</p><div class="detail" style="margin-top:8px">Última consulta manual: <?= health_e($last) ?> (horário de Brasília). Sem cron e sem atualização automática.</div><form class="manual-form" method="post" action="/suporte/monitor_saude.php"><input type="hidden" name="nexa_health_token" value="<?= health_e($manualToken) ?>"><button class="manual-button" type="submit">Atualizar agora</button><span class="help-text">Uma única coleta por clique; mínimo de 15 segundos entre consultas.</span></form></div><span class="status <?= health_e(health_label($status)) ?>"><?= health_e(ucfirst((string)$status)) ?></span></section>
<?php if ($message !== ''): ?><div class="alert"><?= health_e($message) ?></div><?php endif; ?>
<?php if (!$hasSnapshot): ?><div class="alert">Nenhuma coleta automática será realizada. Clique em <strong>Atualizar agora</strong> para consultar a VPS.</div><?php elseif ($age !== null && $age > 300): ?><div class="alert">Os dados abaixo são de uma consulta anterior e podem estar desatualizados. Clique em Atualizar agora para obter uma nova leitura.</div><?php endif; ?>
<?php foreach ($alerts as $alert): ?><div class="alert"><?= health_e($alert) ?></div><?php endforeach; ?>
<section class="grid" aria-label="Indicadores gerais">
 <div class="card"><div class="cap">Uso da CPU</div><div class="metric"><?= health_percent($fresh ? ($health['cpu_percent']??null) : null) ?></div><div class="detail">Load médio (1 min): <?= health_e($fresh ? ($health['load_1m']??'—') : '—') ?></div></div>
 <div class="card"><div class="cap">Memória utilizada</div><div class="metric"><?= health_percent($fresh ? ($health['ram']['used_pct']??null) : null) ?></div><div class="detail">Disponível: <?= health_gib($fresh ? ($health['ram']['available_bytes']??null) : null) ?></div></div>
 <div class="card"><div class="cap">Disco raiz utilizado</div><div class="metric"><?= health_percent($fresh ? ($health['disco']['used_pct']??null) : null) ?></div><div class="detail">Livre: <?= health_gib($fresh ? ($health['disco']['free_bytes']??null) : null) ?></div></div>
 <div class="card"><div class="cap">OOM nas últimas 24h (sem acesso privilegiado)</div><div class="metric"><?= health_e($fresh ? ($health['oom_24h']??'—') : '—') ?></div><div class="detail">Swap: <?= health_gib($fresh ? ($health['ram']['swap_total_bytes']??null) : null) ?></div></div>
</section>
<div class="two">
<section class="panel"><h2>Uso da CPU — histórico</h2><?= health_sparkline($history,'cpu_percent','#2454d6') ?><p class="detail">Amostras somente dos cliques manuais, não representam picos entre consultas.</p></section>
<section class="panel"><h2>Uso da memória — histórico</h2><?= health_sparkline($history,'ram_percent','#00a7cc') ?><p class="detail">Somente consultas manuais nesta sessão; uso estimado a partir de MemAvailable.</p></section>
</div>
<div class="two">
<section class="panel"><h2>Serviços da VPS</h2><div class="table-scroll"><table><thead><tr><th>Serviço</th><th>Situação</th></tr></thead><tbody><?php foreach (($fresh ? ($health['servicos']??[]) : []) as $service=>$state): ?><tr><td><?= health_e($service) ?></td><td><span class="chip <?= health_e(health_label($state)) ?>"><?= health_e($state) ?></span></td></tr><?php endforeach; ?></tbody></table></div><p class="detail">Estado inferido de processos; não substitui diagnóstico dos serviços pelo SSH.</p></section>
<section class="panel"><h2>Disponibilidade das rotas</h2><div class="table-scroll"><table><thead><tr><th>Verificação</th><th>HTTP</th><th>Tempo</th></tr></thead><tbody><?php foreach (($fresh ? ($health['http']??[]) : []) as $site): ?><tr><td><?= health_e($site['nome']??'—') ?></td><td><span class="chip <?= !empty($site['ok']) ? 'ok':'bad' ?>"><?= health_e($site['http'] ?? ($site['erro']??'—')) ?></span></td><td class="nowrap"><?= health_e($site['ms']??'—') ?> ms</td></tr><?php endforeach; ?></tbody></table></div><p class="detail">Testes sem autenticação. HTTP 302 indica redirecionamento, não valida operação interna do ERP.</p></section>
</div>
<div class="two">
<section class="panel"><h2>WhatsApp — somente recursos</h2><div class="table-scroll"><table><tbody><tr><th>Processos Chrome</th><td><?= health_e($fresh ? ($health['whatsapp']['chrome_processos']??'—') : '—') ?></td></tr><tr><th>Sessões Chrome distintas detectadas</th><td><?= health_e($fresh ? ($health['whatsapp']['sessoes_detectadas']??'—') : '—') ?></td></tr><tr><th>Memória RSS somada (estimativa)</th><td><?= health_e($fresh ? ($health['whatsapp']['rss_mib_somada']??'—') : '—') ?> MiB</td></tr></tbody></table></div><p class="detail">Contagem por processos; a soma RSS pode duplicar memória compartilhada. Não substitui nem altera o <a href="/suporte/whatsapp.php">Monitor do WhatsApp</a>.</p></section>
<section class="panel"><h2>Últimas verificações</h2><p class="detail"><?= count($history) ?> consultas manuais nesta sessão (máximo 30) · <?= $failed ?> com falhas HTTP.</p><div class="table-scroll"><table><thead><tr><th>Horário UTC</th><th>Estado</th><th>Falhas HTTP</th></tr></thead><tbody><?php foreach ($rows as $item): ?><tr><td class="nowrap"><?= health_e($item['em']??'—') ?></td><td><span class="chip <?= health_e(health_label($item['status']??'')) ?>"><?= health_e($item['status']??'—') ?></span></td><td><?= health_e($item['falhas_http']??'—') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<?php $opsOk = nexa_ops_available(); $opsResults = $opsOk ? nexa_ops_recent_results(5) : []; $opsPending = $opsOk ? nexa_ops_pending() : 0; ?>
<section class="panel" id="manutencao" style="margin-top:18px"><h2>Manutenção da VPS</h2>
<?php if (!$opsOk): ?><div class="alert"><?= health_e(nexa_ops_unavailable_reason()) ?></div><?php else: ?>
<p class="detail">As duas ações pedem a <strong>senha master do Nexa Próspero</strong> (usuário administrador da plataforma). Use primeiro <strong>Resolver sem reiniciar</strong>: ele reinicia apenas os serviços que estiverem parados, recarrega o PHP e o Apache sem derrubar quem está usando e limpa logs antigos se o disco estiver acima de 90%. <strong>Reiniciar a VPS</strong> deixa todas as empresas fora do ar por 1 a 3 minutos.</p>
<div class="two">
 <form method="post" action="/suporte/monitor_saude.php#manutencao" onsubmit="return confirm('Executar \'Resolver sem reiniciar\' na VPS?');"><input type="hidden" name="nexa_health_token" value="<?= health_e($manualToken) ?>"><input type="hidden" name="nexa_ops_action" value="resolver"><div class="manual-form"><input type="password" name="master_password" placeholder="Senha master" autocomplete="current-password" required style="padding:10px 12px;border:1px solid #c9d6ea;border-radius:12px;min-width:200px"><button class="manual-button" type="submit">Resolver sem reiniciar</button></div></form>
 <form method="post" action="/suporte/monitor_saude.php#manutencao" onsubmit="return confirm('ATENÇÃO: reiniciar a VPS deixa TODAS as empresas fora do ar por 1 a 3 minutos. Confirmar o reinício?');"><input type="hidden" name="nexa_health_token" value="<?= health_e($manualToken) ?>"><input type="hidden" name="nexa_ops_action" value="reiniciar_vps"><div class="manual-form"><input type="password" name="master_password" placeholder="Senha master" autocomplete="current-password" required style="padding:10px 12px;border:1px solid #c9d6ea;border-radius:12px;min-width:200px"><button class="manual-button" type="submit" style="background:#b91c1c">Reiniciar a VPS</button></div></form>
</div>
<?php if ($opsPending > 0): ?><div class="alert">Pedido em andamento. <a href="/suporte/monitor_saude.php#manutencao">Atualize a página</a> em alguns segundos para ver o relatório.</div><?php endif; ?>
<h2 style="margin-top:14px">Últimas ações</h2>
<?php if (!$opsResults): ?><p class="detail">Nenhuma ação executada ainda.</p><?php endif; ?>
<?php foreach ($opsResults as $r): ?><details <?= ($r['id'] === ($_SESSION['nexa_ops_last_id'] ?? '')) ? 'open' : '' ?> style="margin:8px 0"><summary><strong><?= health_e($r['when']) ?></strong> (horário de Brasília)</summary><pre style="white-space:pre-wrap;background:#f4f7fb;border:1px solid #dce7f5;border-radius:12px;padding:10px;font-size:12px;margin:8px 0 0"><?= health_e($r['text']) ?></pre></details><?php endforeach; ?>
<?php endif; ?>
</section>
<p class="notice">Consulta sob demanda, sem agendamentos, sem tarefas de fundo; reinícios só pelos botões de Manutenção, com senha master. As amostras ficam somente na sessão autenticada e são apagadas no logout. Sem privilégios administrativos, o histórico OOM não é consultado automaticamente (indicado por —). Para detectar indisponibilidade total da VPS, seria necessário monitoramento externo, não ativado aqui.</p>
</div></body></html>
