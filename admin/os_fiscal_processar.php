<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';
require_once __DIR__ . '/_os_ui.php';

$pdo=os_db();$companyId=os_company_id();os_require_enabled($pdo,$companyId);os_ensure_defaults($pdo,$companyId);os_fiscal_queue_ensure_schema($pdo);
$osId=(int)($_GET['os_id'] ?? 0);
$rawJobs=trim((string)($_GET['jobs'] ?? ''));
$ids=array();
foreach(preg_split('/[^0-9]+/',$rawJobs) as $v){$n=(int)$v;if($n>0&&!in_array($n,$ids,true))$ids[]=$n;}
$ids=array_slice($ids,0,3);
if($osId<=0 || !$ids){http_response_code(400);die('Nenhum documento fiscal informado para processamento.');}

$ph=implode(',',array_fill(0,count($ids),'?'));
$st=$pdo->prepare("SELECT q.id,q.os_order_id,q.document_type,q.status,o.os_number,o.pedido_id FROM os_fiscal_queue q JOIN os_orders o ON o.company_id=q.company_id AND o.id=q.os_order_id WHERE q.company_id=? AND q.os_order_id=? AND q.id IN ($ph) ORDER BY q.id");
$st->execute(array_merge(array($companyId,$osId),$ids));
$jobs=$st->fetchAll(PDO::FETCH_ASSOC) ?: array();
if(!$jobs){http_response_code(404);die('Documentos fiscais da O.S. não encontrados.');}
$csrf=os_fiscal_csrf_token();
$osNumber=(int)$jobs[0]['os_number'];
$pedidoId=(int)$jobs[0]['pedido_id'];
$pedidoNumber=os_pedido_display_number($pdo,$companyId,$pedidoId);
$osOnlyNfse = count($jobs) > 0 && count(array_filter($jobs, static function($job) {
    return os_fiscal_type((string)$job['document_type']) === 'NFSE';
})) === count($jobs);
$osFinishReturn = $osOnlyNfse ? 'os_fiscal_documentos.php' : 'os_monitor.php';
$osFinishLabel = $osOnlyNfse ? 'Voltar aos Documentos de O.S.' : 'Voltar para Lista de O.S.';

os_page_start('Processando documentos fiscais','fiscal');
echo '<div class="os-title-row"><h1>Faturamento fiscal da O.S. #'.str_pad((string)$osNumber,6,'0',STR_PAD_LEFT).'</h1><div class="spacer"></div><a class="btn btn-secondary" href="'.os_e($osFinishReturn).'">'.os_e($osFinishLabel).'</a></div>';
echo '<div class="card"><div class="card-head">Autorização dos documentos</div><div class="card-body">';
echo '<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a"><b>Processamento imediato.</b> O Pedido #'.$pedidoNumber.' já foi gravado. Agora o Nexa prepara e autoriza somente os documentos fiscais desta O.S. A fila exclusiva permanece como proteção caso a página seja fechada/interrompida.</div>';
echo '<div id="fiscalJobs" style="display:grid;gap:12px">';
foreach($jobs as $j){
    $type=os_fiscal_type($j['document_type']);
    echo '<div class="fiscal-live-job" id="job-'.(int)$j['id'].'" data-id="'.(int)$j['id'].'" data-type="'.os_e($type).'" style="border:1px solid var(--os-border);border-radius:11px;padding:14px;background:#fff">';
    echo '<div style="display:flex;gap:10px;align-items:center"><div class="fiscal-spinner" style="width:24px;height:24px;border:3px solid #dbe4ee;border-top-color:var(--os-secondary);border-radius:50%;animation:osspin .8s linear infinite"></div><div><b style="color:var(--os-primary);font-size:15px">'.os_e(os_fiscal_label($type)).'</b><div class="small muted fiscal-step">Aguardando processamento...</div></div></div>';
    echo '<div class="fiscal-result" style="display:none;margin-top:10px"></div></div>';
}
echo '</div><div id="fiscalFinal" style="display:none;margin-top:14px"></div></div></div>';
echo '<style>@keyframes osspin{to{transform:rotate(360deg)}}.fiscal-live-job.done .fiscal-spinner{animation:none;border:0;width:26px;height:26px;display:grid;place-items:center;font-size:20px;font-weight:900}.fiscal-live-job.ok .fiscal-spinner{color:#08783e}.fiscal-live-job.error .fiscal-spinner{color:#b42318}</style>';
?>
<script>
(function(){
 const csrf=<?php echo json_encode($csrf); ?>;
 const osId=<?php echo (int)$osId; ?>;
 const finishReturn=<?php echo json_encode($osFinishReturn, JSON_UNESCAPED_SLASHES); ?>;
 const finishLabel=<?php echo json_encode($osFinishLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
 const jobs=Array.from(document.querySelectorAll('.fiscal-live-job'));
 const finalBox=document.getElementById('fiscalFinal');
 function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
 function steps(type){
   if(type==='NFE')return ['Conferindo dados da O.S. e destinatário...','Montando XML da NF-e...','Assinando documento fiscal...','Transmitindo para a SEFAZ...','Aguardando autorização...','Registrando retorno fiscal...'];
   if(type==='NFCE')return ['Conferindo dados da O.S....','Montando XML da NFC-e...','Assinando cupom fiscal eletrônico...','Transmitindo para a SEFAZ...','Aguardando autorização...','Registrando retorno fiscal...'];
   return ['Conferindo serviços da O.S....','Preparando DPS da NFS-e...','Assinando documento...','Transmitindo para o ambiente da NFS-e...','Aguardando retorno...','Registrando NFS-e...'];
 }
 async function processJob(box){
   const type=box.dataset.type,id=box.dataset.id,stepEl=box.querySelector('.fiscal-step'),resultEl=box.querySelector('.fiscal-result'),spin=box.querySelector('.fiscal-spinner');
   const list=steps(type);let idx=0;stepEl.textContent=list[0];
   const timer=setInterval(()=>{idx=Math.min(idx+1,list.length-1);stepEl.textContent=list[idx];},1100);
   try{
     const fd=new FormData();fd.append('csrf',csrf);fd.append('queue_id',id);
     const res=await fetch('os_fiscal_processar_ajax.php',{method:'POST',body:fd,credentials:'same-origin'});
     const text=await res.text();let data;try{data=JSON.parse(text)}catch(e){throw new Error('Resposta inválida do servidor. HTTP '+res.status);}
     clearInterval(timer);box.classList.add('done');resultEl.style.display='block';
     if(data.ok){
       box.classList.add('ok');spin.textContent='✓';stepEl.textContent=(data.document_status_label||'Concluído');
       let html='<div class="alert alert-ok" style="margin:0"><b>'+esc(data.type_label||type)+' concluída.</b>';
       if(data.number)html+='<div class="small" style="margin-top:4px">Documento '+esc(data.series||'')+'/'+esc(data.number)+'</div>';
       if(data.message)html+='<div class="small" style="margin-top:4px">'+esc(data.message)+'</div>';
       if(data.print_url)html+='<div style="margin-top:9px"><a class="btn btn-outline btn-sm" target="_blank" href="'+esc(data.print_url)+'">Imprimir documento</a></div>';
       html+='</div>';resultEl.innerHTML=html;return true;
     }
     box.classList.add('error');spin.textContent='!';stepEl.textContent=data.document_status_label||'Não autorizado';
     resultEl.innerHTML='<div class="alert alert-error" style="margin:0"><b>Não foi possível concluir.</b><div class="small" style="margin-top:4px">'+esc(data.message||'Falha fiscal.')+'</div></div>';
     return false;
   }catch(err){
     clearInterval(timer);box.classList.add('done','error');spin.textContent='!';stepEl.textContent='Erro de comunicação com o Nexa';resultEl.style.display='block';resultEl.innerHTML='<div class="alert alert-error" style="margin:0">'+esc(err.message||'Falha ao processar documento.')+'</div>';return false;
   }
 }
 window.osFiscalFinished=false;
 document.addEventListener('keydown',function(e){
   if(e.key==='Escape' && window.osFiscalFinished){e.preventDefault();window.location.href=finishReturn;}
 });
 (async function(){
   let okAll=true;
   for(const job of jobs){const ok=await processJob(job);if(!ok)okAll=false;}
   finalBox.style.display='block';
   finalBox.innerHTML=(okAll?'<div class="alert alert-ok"><b>Processamento concluído.</b> Os documentos selecionados foram processados.</div>':'<div class="alert alert-error"><b>Processamento concluído com pendência.</b> Corrija o motivo exibido e use “Tentar novamente”. Não haverá retransmissão automática.</div>')+'<div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-primary" href="'+esc(finishReturn)+'">'+esc(finishLabel)+'</a><a class="btn btn-outline" href="os_fiscal_documentos.php?os_id='+osId+'">Documentos fiscais desta O.S.</a></div>';
   window.osFiscalFinished=true;
 })();
})();
</script>
<?php os_page_end(); ?>
