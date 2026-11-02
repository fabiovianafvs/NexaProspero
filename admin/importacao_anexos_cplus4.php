<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require __DIR__.'/_chrome.php';
if(!in_array(auth_role(),['company_admin','platform_admin'],true)){
    http_response_code(403);exit('Acesso restrito ao administrador.');
}
if(defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID
    && (int)MULTI_COMPANY_CONTEXT_ID!==(int)COMPANY_ID){
    http_response_code(403);exit('Retorne à empresa de entrada antes de importar anexos. O F11 não é usado neste importador.');
}
if(session_status()!==PHP_SESSION_ACTIVE)@session_start();
if(empty($_SESSION['ic4ui_csrf']))$_SESSION['ic4ui_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['ic4ui_csrf'];
admin_topbar('Importação de anexos CPlus4','importacao');
?>
<style>
.ic4-wrap{max-width:1140px;margin:22px auto;padding:0 18px 25px;display:grid;gap:15px}.ic4-card{padding:20px;border:1px solid #dbe4f0;border-radius:16px;background:#fff;box-shadow:0 6px 20px #0b1f5b08}.ic4-card h2{margin:0 0 10px;color:#0b1f5b;font-size:20px}.ic4-muted{color:#64748b;line-height:1.55}.ic4-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.ic4-field{display:flex;flex-direction:column;gap:6px}.ic4-field label{font-weight:700;color:#334155}.ic4-field input,.ic4-field select{padding:11px;border:1px solid #cbd5e1;border-radius:10px;max-width:100%}.ic4-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.ic4-btn{padding:10px 16px;border:1px solid #b6c4d5;border-radius:10px;background:#fff;color:#0b1f5b;font-weight:800;cursor:pointer;text-decoration:none}.ic4-btn.primary{background:#0b1f5b;color:#fff;border-color:#0b1f5b}.ic4-btn:disabled{opacity:.4;cursor:not-allowed}.ic4-kpi{border:1px solid #dbe4f0;border-radius:12px;padding:14px;background:#f8fafc}.ic4-kpi strong{display:block;font-size:22px;color:#0b1f5b}.ic4-bar{height:13px;background:#e2e8f0;border-radius:99px;overflow:hidden}.ic4-bar>div{height:100%;background:#2454d6;width:0;transition:width .2s}.ic4-note{background:#f3f7ff;border:1px solid #c9d8f5;border-radius:12px;padding:12px 15px;line-height:1.55}.ic4-error{color:#991b1b;font-weight:700}.ic4-ok{color:#047857;font-weight:700}@media(max-width:700px){.ic4-grid{grid-template-columns:1fr}}
</style>
<div class="ic4-wrap">
  <div><a class="ic4-btn" href="importacao.php">← Voltar à Central de Importações</a></div>
  <section class="ic4-card"><h2>Importar anexos de clientes/pessoas — CPlus4</h2>
    <p class="ic4-muted">Primeiro importe os clientes e seus códigos de origem CPlus4. Os anexos serão vinculados apenas aos clientes desta empresa. O ZIP de origem deve conter <strong>manifesto.csv</strong> e os arquivos referenciados. Não são importados anexos de outros tipos de entidade nesta fase.</p>
    <div class="ic4-note"><strong>Segurança:</strong> envio pelo navegador em partes de até 2 MiB; importação por worker PHP CLI na VPS em lotes de 20, 50, 100 ou 150 anexos, limite nominal de 50 MiB de dados originais por lote e pausa de 3 segundos entre lotes. <strong>O worker precisa estar habilitado no cron</strong> para executar automaticamente, mesmo com o navegador fechado.</div>
  </section>
  <section class="ic4-card"><h2>1. Enviar o ZIP</h2>
    <div class="ic4-field"><label for="ic4file">Arquivo de anexos CPlus4 (.zip)</label><input type="file" id="ic4file" accept=".zip,application/zip"><small class="ic4-muted">Se o envio cair, selecione novamente o mesmo arquivo e clique em Enviar/Continuar. Não exceda 4 GiB por ZIP; confira o espaço disponível na VPS.</small></div>
    <div class="ic4-actions"><button id="ic4upload" type="button" class="ic4-btn primary">Enviar / Continuar envio</button></div>
    <p class="ic4-muted" id="ic4uploadText">Nenhum arquivo selecionado.</p><div class="ic4-bar"><div id="ic4uploadBar"></div></div>
  </section>
  <section class="ic4-card"><h2>2. Importar em lotes</h2>
    <div class="ic4-grid"><div class="ic4-field"><label for="ic4batch">Tamanho do lote</label><select id="ic4batch"><option value="20">20 — muito conservador</option><option value="50" selected>50 — recomendado para iniciar</option><option value="100">100 — intermediário</option><option value="150">150 — máximo</option></select></div>
    <div class="ic4-kpi"><small>Processados</small><strong id="ic4processed">0</strong></div>
    <div class="ic4-kpi"><small>Situação</small><strong style="font-size:17px" id="ic4stage">Sem envio</strong></div></div>
    <div class="ic4-actions"><button class="ic4-btn primary" type="button" id="ic4start" disabled>Iniciar importação</button><button class="ic4-btn" type="button" id="ic4pause" disabled>Pausar após o lote</button><button class="ic4-btn" type="button" id="ic4resume" disabled>Continuar importação</button></div>
    <p class="ic4-muted" id="ic4message">O progresso é salvo na VPS. Você poderá fechar esta tela e retornar depois.</p>
    <div class="ic4-bar"><div id="ic4progressBar"></div></div>
  </section>
  <section class="ic4-card"><h2>3. Resultado acumulado</h2>
    <div class="ic4-grid"><div class="ic4-kpi"><small>Importados</small><strong id="ic4imported">0</strong></div><div class="ic4-kpi"><small>Ignorados / já existentes</small><strong id="ic4ignored">0</strong></div><div class="ic4-kpi"><small>Erros ignorados</small><strong id="ic4errors">0</strong></div></div>
    <div class="ic4-actions"><button class="ic4-btn" id="ic4report" type="button" disabled>Baixar relatório de inconsistências</button></div>
    <p class="ic4-muted">Anexos ausentes, ilegíveis, incompatíveis ou com SHA inválido são registrados e ignorados individualmente. Erros de vínculo de cliente, banco ou infraestrutura interrompem a tarefa. O relatório registra código CPlus4 e nome do anexo para conferência posterior. Nunca apague os checkpoints manualmente.</p>
  </section>
  <p id="ic4alert" role="status"></p>
</div>
<script>
(()=>{
'use strict';
const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const endpoint='importacao_anexos_cplus4_api.php';const CHUNK=2*1024*1024;
const $=s=>document.getElementById(s);let job=null;let busy=false;
const statuses={uploading:'Recebendo ZIP',uploaded:'ZIP recebido',queued:'Na fila',running:'Importando',paused:'Pausada',completed:'Concluída',error:'Interrompida'};
const fmt=n=>Number(n||0).toLocaleString('pt-BR');
const size=n=>(Number(n||0)/1024/1024).toLocaleString('pt-BR',{maximumFractionDigits:1})+' MiB';
function alertText(t,err=false){$('ic4alert').textContent=t;$('ic4alert').className=err?'ic4-error':'ic4-ok';}
async function request(action,fields={},file=null){
  const body=new FormData();body.append('csrf',csrf);body.append('action',action);
  for(const [k,v] of Object.entries(fields))body.append(k,String(v));
  if(file)body.append('part',file,'part.bin');
  const r=await fetch(endpoint,{method:'POST',body,credentials:'same-origin',cache:'no-store'});
  const data=await r.json().catch(()=>({error:'Resposta inesperada do servidor.'}));
  if(!r.ok||!data.ok)throw new Error(data.error||'Falha HTTP '+r.status);
  return data.job;
}
async function refresh(){if(busy)return;try{
  const r=await fetch(endpoint+'?action=status',{credentials:'same-origin',cache:'no-store'});
  const data=await r.json();if(!data.ok)throw new Error(data.error||'Falha ao consultar status.');
  job=data.job?.exists?data.job:null;render();
 }catch(e){alertText(e.message,true);}}
function render(){
 const j=job||{};const stage=j.stage||'';const receiving=stage==='uploading';
 $('ic4stage').textContent=statuses[stage]||'Sem envio';
 $('ic4processed').textContent=fmt(j.processed)+(j.total_rows!==null&&j.total_rows!==undefined?' / '+fmt(j.total_rows):'');
 $('ic4imported').textContent=fmt(j.imported);$('ic4ignored').textContent=fmt(j.ignored);$('ic4errors').textContent=fmt(j.errors);
 $('ic4report').disabled=busy||Number(j.errors||0)===0;
 $('ic4stage').textContent=stage==='completed'&&Number(j.errors||0)>0?'Concluída com ressalvas':(statuses[stage]||'Sem envio');
 const receivePercent=j.size?Math.max(0,Math.min(100,Number(j.received||0)*100/Number(j.size))):0;
 $('ic4uploadBar').style.width=receivePercent+'%';$('ic4uploadText').textContent=j.size?`${j.name}: ${size(j.received)} de ${size(j.size)} (${receivePercent.toFixed(1)}%)`:'Selecione um arquivo ZIP.';
 $('ic4progressBar').style.width=j.total_rows?Math.max(0,Math.min(100,100*Number(j.processed||0)/Number(j.total_rows)))+'%':(stage==='completed'?'100%':'0%');
 $('ic4message').textContent=j.last_message||'Aguardando envio.';
 $('ic4start').disabled=busy||stage!=='uploaded';$('ic4pause').disabled=busy||!['running','queued'].includes(stage);
 $('ic4resume').disabled=busy||!['paused','error'].includes(stage);
 $('ic4upload').disabled=busy||['queued','running','paused'].includes(stage);
 if(j.batch) $('ic4batch').value=String(j.batch);
 $('ic4batch').disabled=busy||stage!=='uploaded';
}
async function act(action,data={}){if(busy)return;busy=true;render();try{job=await request(action,data);render();alertText(action==='pause'?'Pausa solicitada.':'Solicitação registrada.');}catch(e){alertText(e.message,true);}finally{busy=false;render();}}
$('ic4start').addEventListener('click',()=>{if(!confirm('Confirma importar os anexos para a empresa atual? Os clientes devem estar importados.'))return;act('start',{batch:$('ic4batch').value});});
$('ic4report').addEventListener('click',async()=>{
 if(busy)return;busy=true;render();
 try{
  const body=new FormData();body.append('csrf',csrf);body.append('action','report');
  const response=await fetch(endpoint,{method:'POST',body,credentials:'same-origin',cache:'no-store'});
  if(!response.ok){const msg=await response.json().catch(()=>({}));throw new Error(msg.error||'Falha ao consultar relatorio.');}
  const blob=await response.blob();
  const url=URL.createObjectURL(blob);const a=document.createElement('a');
  a.href=url;a.download='nexa_cplus4_erros_empresa.txt';document.body.appendChild(a);a.click();a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),10000);
 }catch(e){alertText(e.message,true);}finally{busy=false;render();}
});
$('ic4pause').addEventListener('click',()=>act('pause'));
$('ic4resume').addEventListener('click',()=>act('resume'));
$('ic4upload').addEventListener('click',async()=>{
 if(busy)return;const file=$('ic4file').files[0];if(!file){alertText('Selecione o ZIP.',true);return;}
 if(!/\.zip$/i.test(file.name)||file.size<=0||file.size>4*1024*1024*1024){alertText('Selecione um ZIP de até 4 GiB.',true);return;}
 let replace=0;
 if(job && !(job.stage==='uploading' && job.name===file.name && Number(job.size)===file.size)){
   if(!confirm('Substituir o ZIP anterior? Isso NÃO apaga anexos já importados, mas descarta o envio anterior.'))return;
   replace=1;
 }
 busy=true;render();
 try {
   job=await request('init',{name:file.name,size:file.size,replace});render();
   for(let offset=Number(job.received);offset<file.size;offset+=CHUNK){
     let part=file.slice(offset,Math.min(file.size,offset+CHUNK));
     job=await request('chunk',{index:Math.floor(offset/CHUNK)},part);render();
   }
   job=await request('finish');render();alertText('ZIP recebido. Confira os clientes e clique em Iniciar importação.');
 }catch(e){alertText('Envio interrompido: '+e.message+'. É possível retomar selecionando o mesmo arquivo.',true);}
 finally{busy=false;render();}
});
refresh();setInterval(refresh,5000);
})();
</script>
<?php admin_footer(); ?>
