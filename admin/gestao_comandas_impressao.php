<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
if ($printCross && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'gestao_comandas')) {
  http_response_code(403); echo 'Sem permissão para o Monitor de Impressão na empresa selecionada pelo F11.'; exit;
}
saas_require_company_module($pdo, $companyId, 'comandas', 'Monitor de impressão');
if (!$printCross) comanda_ensure_schema($pdo);
$printApiBase = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_print_api.php') : 'gestao_comandas_print_api.php';
$printMcCtx = function_exists('multi_company_context_request_token') ? multi_company_context_request_token() : '';
$printCsrf = $printCross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$comandasBackUrl = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas.php') : 'gestao_comandas.php';
$groups = comanda_printer_groups($pdo, $companyId, true);
// Também mostra setores que já possuem trabalhos na fila, mesmo antes de serem cadastrados nas configurações.
try {
  $known = [];
  foreach ($groups as $g) $known[strtolower(trim((string)($g['name'] ?? '')))] = true;
  $stQueueGroups = $pdo->prepare("SELECT group_name AS name, MAX(COALESCE(printer_name,'')) AS printer_name FROM comanda_print_jobs WHERE company_id=? AND group_name<>'' GROUP BY group_name ORDER BY group_name");
  $stQueueGroups->execute([$companyId]);
  foreach (($stQueueGroups->fetchAll(PDO::FETCH_ASSOC) ?: []) as $g) {
    $key = strtolower(trim((string)($g['name'] ?? '')));
    if ($key !== '' && !isset($known[$key])) { $groups[] = $g; $known[$key] = true; }
  }
  usort($groups, static fn(array $a, array $b): int => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
} catch (Throwable $e) {}
$selectedGroup = trim((string)($_GET['group'] ?? ''));
if ($selectedGroup === '' && count($groups) === 1) $selectedGroup = (string)$groups[0]['name'];
$validNames = array_map(static fn(array $g): string => (string)$g['name'], $groups);
if ($selectedGroup !== '' && $selectedGroup !== '__all__' && !in_array($selectedGroup, $validNames, true)) $selectedGroup = '';
$autoStart = !empty($_GET['start']);
$todayFilter = date('Y-m-d');
$recentFrom = trim((string)($_GET['recent_from'] ?? $todayFilter));
$recentTo = trim((string)($_GET['recent_to'] ?? $todayFilter));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recentFrom)) $recentFrom = $todayFilter;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recentTo)) $recentTo = $todayFilter;
if ($recentFrom > $recentTo) [$recentFrom, $recentTo] = [$recentTo, $recentFrom];

gestao_admin_start('Monitor de Impressão', 'gestao_comandas_impressao');
$monitorActions = $printCross
  ? [['label'=>'Voltar para Comandas','href'=>$comandasBackUrl,'class'=>'btn btn-primary']]
  : [
      ['label'=>'Agentes de Impressão','href'=>'gestao_comandas_agentes.php','class'=>'btn btn-primary'],
      ['label'=>'QR Codes das Mesas','href'=>'gestao_comandas_qrcode.php','class'=>'btn'],
      ['label'=>'Configurar Setores','href'=>'parametros.php#configuracao-comandas','class'=>'btn'],
    ];
gestao_page_header('Monitor de Impressão — Cozinha/Bar', 'Acompanhe a fila e os agentes automáticos da empresa operacional selecionada.', $monitorActions);
if ($printCross) echo '<div class="msg ok" style="margin-bottom:12px"><strong>V11.31 · Impressão Multiempresa</strong> — fila, histórico, reimpressão e contingência isolados na empresa selecionada pelo F11. Cadastro de agentes/QR/configuração de setores permanece protegido.</div>';
gestao_tabs('gestao_comandas_impressao');
?>
<style>
.printmon-grid{display:grid;grid-template-columns:380px minmax(0,1fr);gap:14px;align-items:start}.printmon-panel{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px}.printmon-panel h2{margin:0 0 8px}.printmon-note{color:#64748b;line-height:1.5;font-size:13px}.printmon-select{display:grid;gap:7px;margin-top:14px}.printmon-select select{width:100%;padding:12px;border:1px solid #dbe4f0;border-radius:15px;font:inherit}.printmon-status{display:flex;align-items:center;gap:10px;margin:16px 0;padding:13px;border-radius:17px;background:#f8fafc;border:1px solid #e2e8f0}.status-dot{width:14px;height:14px;border-radius:50%;background:#94a3b8;box-shadow:0 0 0 5px #e2e8f0}.printmon-status.on .status-dot{background:#16a34a;box-shadow:0 0 0 5px #dcfce7;animation:pulse 1.4s infinite}.printmon-status.err .status-dot{background:#dc2626;box-shadow:0 0 0 5px #fee2e2}@keyframes pulse{50%{transform:scale(.75)}}.printmon-actions{display:flex;gap:9px}.printmon-actions .btn{flex:1;justify-content:center}.printmon-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.printmon-kpi{border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:14px}.printmon-kpi span{display:block;color:#64748b;font-size:12px;font-weight:850}.printmon-kpi strong{display:block;font-size:26px;margin-top:4px}.printmon-current{min-height:170px;display:grid;place-items:center;text-align:center;border:2px dashed #cbd5e1;border-radius:22px;background:#f8fafc;padding:20px}.printmon-current.busy{border-style:solid;border-color:#93c5fd;background:#eff6ff}.printmon-current h3{margin:6px 0}.printmon-current p{margin:4px;color:#64748b}.printmon-table{width:100%;border-collapse:collapse}.printmon-table th,.printmon-table td{padding:10px;border-bottom:1px solid #e5edf6;text-align:left;font-size:13px}.printmon-row-actions{display:flex;flex-wrap:wrap;gap:6px;min-width:190px}.printmon-history-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;margin-bottom:12px}.printmon-history-head h2{margin:0}.printmon-history-filter{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap}.printmon-history-filter label{display:grid;gap:4px;color:#64748b;font-size:11px;font-weight:900}.printmon-history-filter input{padding:8px 10px;border:1px solid #dbe4f0;border-radius:11px;font:inherit}.badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:900;background:#f1f5f9;color:#475569}.badge.PENDENTE{background:#fff7ed;color:#9a3412}.badge.PROCESSANDO{background:#eff6ff;color:#1d4ed8}.badge.IMPRESSO{background:#ecfdf5;color:#047857}.badge.ERRO{background:#fef2f2;color:#b91c1c}.agent-mini{display:grid;gap:8px;margin:12px 0}.agent-mini-row{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px;border:1px solid #dbe4f0;border-radius:14px;background:#f8fafc}.agent-mini-row strong{display:block}.agent-online{color:#047857;font-weight:900}.agent-offline{color:#b91c1c;font-weight:900}.group-summary{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}.group-chip{background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:8px 11px;font-size:12px}.group-chip strong{display:block;color:#0f2a6b}.print-frame{position:fixed;width:1px;height:1px;border:0;opacity:.01;pointer-events:none;left:-10px;top:-10px}@media(max-width:980px){.printmon-grid{grid-template-columns:1fr}.printmon-kpis{grid-template-columns:repeat(2,1fr)}.printmon-history-head{align-items:stretch;flex-direction:column}.printmon-history-filter{display:grid;grid-template-columns:1fr 1fr}.printmon-history-filter .btn{width:100%;justify-content:center}}
</style>
<div class="printmon-grid">
  <aside class="printmon-panel">
    <h2>Agentes automáticos</h2>
    <p class="printmon-note">O agente envia o pedido diretamente para impressoras USB/rede do Windows ou para impressoras Ethernet por IP.</p>
    <div class="agent-mini" id="agentList"><div class="agent-mini-row"><span>Carregando agentes...</span></div></div>
    <?php if (!$printCross): ?><a class="btn btn-primary" style="width:100%;justify-content:center" href="gestao_comandas_agentes.php">Configurar agentes</a><?php endif; ?>
    <button class="btn" id="rebuildQueueBtn" style="width:100%;justify-content:center;margin-top:8px">Reconstruir fila por setores</button>
    <hr style="border:0;border-top:1px solid #e2e8f0;margin:18px 0">
    <h2>Contingência pelo navegador</h2>
    <p class="printmon-note">Use apenas quando o agente estiver desligado. Esta opção ainda depende da janela de impressão do navegador.</p>
    <label class="printmon-select"><strong>Grupo/setor</strong><select id="groupSelect"><option value="">Selecione o setor</option><option value="__all__" <?= $selectedGroup==='__all__'?'selected':'' ?>>Todos os grupos (uma única impressora)</option><?php foreach ($groups as $g): ?><option value="<?= comanda_h($g['name']) ?>" <?= $selectedGroup===(string)$g['name']?'selected':'' ?>><?= comanda_h($g['name']) ?><?= trim((string)$g['printer_name'])!=='' ? ' — '.comanda_h($g['printer_name']) : '' ?></option><?php endforeach; ?></select></label>
    <div class="printmon-status" id="monitorStatus"><span class="status-dot"></span><div><strong id="monitorTitle">Contingência parada</strong><div class="printmon-note" id="monitorText">Selecione um setor e clique em Iniciar contingência.</div></div></div>
    <div class="printmon-actions"><button class="btn" id="startBtn">Iniciar contingência</button><button class="btn" id="stopBtn" disabled>Parar</button></div>
  </aside>
  <main>
    <div class="printmon-kpis"><div class="printmon-kpi"><span>Pendentes</span><strong id="kPending">0</strong></div><div class="printmon-kpi"><span>Processando</span><strong id="kProcessing">0</strong></div><div class="printmon-kpi"><span>Impressos</span><strong id="kPrinted">0</strong></div><div class="printmon-kpi"><span>Erros</span><strong id="kError">0</strong></div></div>
    <div class="group-summary" id="groupSummary"></div>
    <section class="printmon-panel"><div class="printmon-current" id="currentBox"><div><div style="font-size:38px">🖨️</div><h3>Aguardando pedidos</h3><p>Quando um cliente enviar o pedido pelo QR Code, a impressão será preparada aqui.</p></div></div></section>
    <section class="printmon-panel" style="margin-top:14px">
      <div class="printmon-history-head">
        <div><h2>Últimas impressões</h2><div class="printmon-note">Por padrão são mostradas somente as impressões de hoje.</div></div>
        <div class="printmon-history-filter">
          <label>De<input type="date" id="recentFrom" value="<?= comanda_h($recentFrom) ?>"></label>
          <label>Até<input type="date" id="recentTo" value="<?= comanda_h($recentTo) ?>"></label>
          <button type="button" class="btn btn-sm" id="todayHistoryBtn">Hoje</button>
          <button type="button" class="btn btn-sm btn-primary" id="filterHistoryBtn">Filtrar</button>
        </div>
      </div>
      <div class="table-wrap"><table class="printmon-table"><thead><tr><th>Data</th><th>Mesa</th><th>Cliente</th><th>Setor</th><th>Impressora</th><th>Status</th><th></th></tr></thead><tbody id="recentRows"><tr><td colspan="7">Carregando...</td></tr></tbody></table></div>
    </section>
  </main>
</div>
<iframe class="print-frame" id="printFrame" title="Impressão automática"></iframe>
<script>
const initialGroup=<?= json_encode($selectedGroup, JSON_UNESCAPED_UNICODE) ?>, autoStart=<?= $autoStart?'true':'false' ?>, todayFilter=<?= json_encode($todayFilter) ?>;
const printApiBase=<?= json_encode($printApiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, printMcCtx=<?= json_encode($printMcCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, printCsrf=<?= json_encode($printCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let running=false,busy=false,timer=null,currentJob=null;
const $=id=>document.getElementById(id), esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function apiUrl(action, params={}){const u=new URL(printApiBase,window.location.href);u.searchParams.set('action',action);Object.entries(params).forEach(([k,v])=>u.searchParams.set(k,String(v??'')));return u.pathname+u.search}
function queueForm(){const fd=new FormData();if(printCsrf)fd.append('mc_csrf',printCsrf);return fd}
function group(){return $('groupSelect').value}
function setStatus(mode,title,text){$('monitorStatus').className='printmon-status '+mode;$('monitorTitle').textContent=title;$('monitorText').textContent=text}
async function api(url,opts){const r=await fetch(url,opts);const j=await r.json();if(!j.ok)throw new Error(j.error||'Falha na comunicação.');return j}
async function refresh(){try{const historyUrl=apiUrl('status',{group:group(),recent_from:$('recentFrom')?.value||todayFilter,recent_to:$('recentTo')?.value||todayFilter});const j=await api(historyUrl);const c=j.counts||{};$('kPending').textContent=c.PENDENTE||0;$('kProcessing').textContent=c.PROCESSANDO||0;$('kPrinted').textContent=c.IMPRESSO||0;$('kError').textContent=c.ERRO||0;const gc=j.group_counts||{};$('groupSummary').innerHTML=Object.keys(gc).sort((a,b)=>a.localeCompare(b,'pt-BR')).map(g=>`<div class="group-chip"><strong>${esc(g)}</strong>Pendentes: ${gc[g].PENDENTE||0} · Processando: ${gc[g].PROCESSANDO||0} · Impressos: ${gc[g].IMPRESSO||0}</div>`).join('');const agents=j.agents||[];$('agentList').innerHTML=agents.length?agents.map(a=>`<div class="agent-mini-row"><div><strong>${esc(a.name)}</strong><span class="printmon-note">${esc((a.groups||[]).join(', ')||'Sem setores')}</span><span class="printmon-note">${esc(a.machine||'-')} · v${esc(a.version||'-')} · ${esc(a.last_seen||'sem contato')}</span></div><span class="${a.online?'agent-online':'agent-offline'}">${a.online?'Online':'Offline'}</span></div>`).join(''):'<div class="agent-mini-row"><span>Nenhum agente cadastrado.</span></div>';const rows=j.recent||[];$('recentRows').innerHTML=rows.length?rows.map(r=>{const canMark=r.status!=='IMPRESSO';return `<tr><td>${esc((r.event_at||r.created_at||'').replace(/^\d{4}-(\d{2})-(\d{2})/,'$2/$1'))}</td><td>${esc(r.comanda_codigo)}</td><td>${esc(r.customer_name||'-')}</td><td>${esc(r.group_name)}</td><td>${esc(r.printer_name||'-')}</td><td><span class="badge ${esc(r.status)}">${esc(r.status)}</span></td><td><div class="printmon-row-actions">${canMark?`<button class="btn btn-sm" data-mark="${r.id}">Marcar impresso</button>`:''}<button class="btn btn-sm" data-retry="${r.id}">Reimprimir</button></div></td></tr>`}).join(''):'<tr><td colspan="7">Nenhuma impressão registrada.</td></tr>';document.querySelectorAll('[data-mark]').forEach(b=>b.onclick=async()=>{if(!confirm('Confirma que este pedido já foi impresso?'))return;try{b.disabled=true;const fd=queueForm();fd.append('job_id',b.dataset.mark);fd.append('status','IMPRESSO');await api(apiUrl('mark'),{method:'POST',body:fd});await refresh()}catch(e){alert(e.message)}finally{b.disabled=false}});document.querySelectorAll('[data-retry]').forEach(b=>b.onclick=async()=>{if(!confirm('Colocar este pedido novamente na fila de impressão?'))return;try{b.disabled=true;const fd=queueForm();fd.append('job_id',b.dataset.retry);await api(apiUrl('retry'),{method:'POST',body:fd});await refresh()}catch(e){alert(e.message)}finally{b.disabled=false}})}catch(e){setStatus('err','Falha no monitor',e.message)}}
async function poll(){if(!running)return;await refresh();if(busy)return;try{const j=await api(apiUrl('next',{group:group(),mc_csrf:printCsrf}));if(!j.job){if(j.agent_online)setStatus('on','Agente automático ativo','O agente está recebendo as impressões deste setor. A contingência não retirará trabalhos da fila.');return;}busy=true;currentJob=j.job;$('currentBox').classList.add('busy');$('currentBox').innerHTML=`<div><div style="font-size:38px">🖨️</div><h3>Imprimindo ${esc(currentJob.group)}</h3><p>Mesa/Comanda ${esc(currentJob.comanda)}${currentJob.customer?' — '+esc(currentJob.customer):''}</p><p>${currentJob.printer?'Impressora configurada: '+esc(currentJob.printer):'Usando a impressora padrão do navegador'}</p></div>`;$('printFrame').src=currentJob.url+'&_='+Date.now();setTimeout(()=>{if(busy&&currentJob)finishJob('ERRO','A impressão não confirmou a conclusão dentro do tempo esperado. Verifique a impressora e tente novamente.')},60000)}catch(e){setStatus('err','Falha ao buscar impressão',e.message)}}
async function finishJob(status,error=''){if(!currentJob)return;const job=currentJob;currentJob=null;busy=false;const fd=queueForm();fd.append('job_id',job.id);fd.append('status',status);fd.append('error',error);try{await api(apiUrl('mark'),{method:'POST',body:fd})}catch(e){}$('currentBox').classList.remove('busy');$('currentBox').innerHTML='<div><div style="font-size:38px">🖨️</div><h3>Aguardando pedidos</h3><p>Monitor ativo para novos pedidos.</p></div>';setTimeout(poll,600)}
window.addEventListener('message',e=>{if(!e.data||e.data.type!=='comanda-print-finished')return;if(currentJob&&Number(e.data.jobId)===Number(currentJob.id))finishJob(e.data.ok===false?'ERRO':'IMPRESSO',e.data.error||'')});
function start(){if(!group())return alert('Selecione o grupo/setor da impressora.');running=true;$('startBtn').disabled=true;$('stopBtn').disabled=false;$('groupSelect').disabled=true;setStatus('on','Contingência ativa','Aguardando pedidos para '+(group()==='__all__'?'todos os grupos':group())+'.');poll();timer=setInterval(poll,3000)}
function stop(){running=false;clearInterval(timer);timer=null;$('startBtn').disabled=false;$('stopBtn').disabled=true;$('groupSelect').disabled=false;setStatus('','Contingência parada','Clique em Iniciar contingência para usar o navegador.')}
$('rebuildQueueBtn').onclick=async()=>{if(!confirm('Reconstruir os trabalhos de impressão a partir dos itens dos pedidos recentes? Os trabalhos já impressos serão preservados.'))return;const b=$('rebuildQueueBtn');try{b.disabled=true;b.textContent='Reconstruindo...';const fd=queueForm();await api(apiUrl('rebuild'),{method:'POST',body:fd});await refresh();alert('Fila reconstruída por todos os setores encontrados nos produtos.')}catch(e){alert(e.message)}finally{b.disabled=false;b.textContent='Reconstruir fila por setores'}};
function syncMonitorUrl(){const url=new URL(window.location.href);url.search='';if(printMcCtx)url.searchParams.set('mc_ctx',printMcCtx);const g=group();if(g)url.searchParams.set('group',g);const from=$('recentFrom')?.value||todayFilter,to=$('recentTo')?.value||todayFilter;if(from!==todayFilter||to!==todayFilter){url.searchParams.set('recent_from',from);url.searchParams.set('recent_to',to)}history.replaceState(null,'',url.pathname+url.search)}
$('filterHistoryBtn').onclick=()=>{syncMonitorUrl();refresh()};$('todayHistoryBtn').onclick=()=>{$('recentFrom').value=todayFilter;$('recentTo').value=todayFilter;syncMonitorUrl();refresh()};$('startBtn').onclick=start;$('stopBtn').onclick=stop;$('groupSelect').onchange=()=>{syncMonitorUrl();refresh()};refresh();if(initialGroup&&autoStart)setTimeout(start,300);
</script>
<?php gestao_admin_end(); ?>
