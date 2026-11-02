<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/receivable_import_module.php';

if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID) {
  access_render_denied('Por segurança, a importação de Contas a Receber deve ser executada diretamente na empresa/tenant de destino, sem contexto F11.');
}
$cid=(int)COMPANY_ID;$userId=(int)(auth_user_id()??0);
if(session_status()!==PHP_SESSION_ACTIVE)@session_start();
if(empty($_SESSION['receivable_import_csrf']))$_SESSION['receivable_import_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['receivable_import_csrf'];
$msg='';$err='';$token=trim((string)($_POST['upload_token']??$_GET['upload_token']??''));$analysis=null;$preflight=null;$result=null;
$chartAccountId=(int)($_POST['chart_account_id']??0);$costCenterId=(int)($_POST['cost_center_id']??0);
$schemaReady=receivable_import_schema_ready($pdo);

// Requisições AJAX em lotes: responde JSON ANTES de carregar novamente a planilha.
if($_SERVER['REQUEST_METHOD']==='POST' && in_array((string)($_POST['action']??''),['chunk_start','chunk_step','chunk_state','chunk_cancel'],true)) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, private');
  try {
    $posted=(string)($_POST['csrf']??'');
    if($posted===''||!hash_equals($csrf,$posted))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    if(!$schemaReady)throw new RuntimeException('A migration V11.45 está pendente.');
    $action=(string)$_POST['action'];
    $token=(string)($_POST['upload_token']??'');
    if(!preg_match('/^[a-f0-9]{32}$/',$token))throw new RuntimeException('Token do arquivo inválido.');
    if($action==='chunk_start') {
      if(strtoupper(trim((string)($_POST['confirmation']??'')))!=='IMPORTAR')throw new RuntimeException('Digite IMPORTAR para confirmar.');
      $analysis=receivable_import_analysis($cid,$token);
      $result=receivable_import_chunk_start($pdo,$cid,$analysis,(int)($_POST['chart_account_id']??0),(int)($_POST['cost_center_id']??0),$userId);
    } elseif($action==='chunk_state') {
      $result=receivable_import_chunk_latest($pdo,$cid,$token,$userId);
    } elseif($action==='chunk_step') {
      $result=receivable_import_chunk_process($pdo,$cid,(int)($_POST['batch_id']??0),$token,$userId);
    } else {
      $result=receivable_import_chunk_cancel($pdo,$cid,(int)($_POST['batch_id']??0),$token,$userId);
    }
    echo json_encode(['ok'=>true,'progress'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  } catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  }
  exit;
}

try{
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');if($posted===''||!hash_equals($csrf,$posted))throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action=(string)($_POST['action']??'');
    if($action==='upload'){$up=receivable_import_store_upload($cid,$userId,(array)($_FILES['receivable_file']??[]),(string)($_POST['source_system']??'PROSPERO_EXCEL'));$token=(string)$up['token'];$msg='Planilha carregada. Confira os vínculos, totais e clientes antes de importar.';}
    if($token!==''){$analysis=receivable_import_analysis($cid,$token);if($schemaReady)$preflight=receivable_import_preflight($pdo,$cid,$analysis,'PROSPERO_EXCEL',40);}
    if($action==='preview')$msg='Pré-validação atualizada.';
  }elseif($token!==''){$analysis=receivable_import_analysis($cid,$token);if($schemaReady)$preflight=receivable_import_preflight($pdo,$cid,$analysis,'PROSPERO_EXCEL',40);}
}catch(Throwable $e){$err=$e->getMessage();if($token!==''&&$analysis===null){try{$analysis=receivable_import_analysis($cid,$token);if($schemaReady)$preflight=receivable_import_preflight($pdo,$cid,$analysis,'PROSPERO_EXCEL',40);}catch(Throwable $ignored){}}}

$chartAccounts=[];$costCenters=[];$batches=[];
try{$st=$pdo->prepare('SELECT id,code,name FROM finance_chart_accounts WHERE company_id=? AND active=1 AND is_analytic=1 ORDER BY code,name,id');$st->execute([$cid]);$chartAccounts=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}
try{$st=$pdo->prepare('SELECT id,code,name FROM finance_cost_centers WHERE company_id=? AND active=1 ORDER BY code,name,id');$st->execute([$cid]);$costCenters=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}
if($schemaReady){try{$st=$pdo->prepare("SELECT * FROM nexa_finance_import_batches WHERE company_id=? AND import_type='RECEIVABLES' ORDER BY id DESC LIMIT 20");$st->execute([$cid]);$batches=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}

admin_topbar('Importar Contas a Receber','fin_receber');
$stats=(array)($preflight['stats']??[]);
?>
<style>
.ri-wrap{max-width:1280px;margin:0 auto;padding:22px;display:grid;gap:16px}.ri-card{background:#fff;border:1px solid #dbe4f0;border-radius:20px;padding:19px;box-shadow:0 10px 28px rgba(15,23,42,.05)}.ri-card h2{margin:0 0 8px;color:#0b1f5b}.ri-muted{color:#64748b;line-height:1.5}.ri-msg{padding:12px 14px;border-radius:13px}.ri-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.ri-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.ri-msg.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.ri-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}.ri-kpi{border:1px solid #dbe4f0;background:#f8fafc;border-radius:14px;padding:12px}.ri-kpi small{display:block;color:#64748b}.ri-kpi strong{display:block;font-size:20px;color:#0b1f5b;margin-top:3px}.ri-field{display:flex;flex-direction:column;gap:6px}.ri-field label{font-weight:800;color:#334155}.ri-field input,.ri-field select{padding:11px;border:1px solid #cbd5e1;border-radius:11px;background:#fff}.ri-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}.ri-btn{border:1px solid #cbd5e1;border-radius:11px;padding:10px 14px;background:#fff;color:#0f172a;font-weight:800;text-decoration:none;cursor:pointer}.ri-btn.primary{background:#0b1f5b;border-color:#0b1f5b;color:#fff}.ri-btn.danger{background:#991b1b;border-color:#991b1b;color:#fff}.ri-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:13px}.ri-table{width:100%;border-collapse:collapse;min-width:900px}.ri-table th,.ri-table td{padding:9px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;font-size:13px}.ri-table th{background:#f8fafc;color:#475569}.ri-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-weight:800;font-size:11px;background:#e2e8f0}.ri-badge.ok{background:#dcfce7;color:#166534}.ri-badge.warn{background:#fef3c7;color:#92400e}@media(max-width:900px){.ri-grid{grid-template-columns:1fr}.ri-wrap{padding:14px}}
</style>
<div class="ri-wrap">
  <?php if($msg!==''):?><div class="ri-msg ok"><?=customer_import_h($msg)?></div><?php endif;?>
  <?php if($err!==''):?><div class="ri-msg err"><?=customer_import_h($err)?></div><?php endif;?>
  <?php if(!$schemaReady):?><div class="ri-msg err"><strong>Migration V11.45 pendente.</strong> Abra Configurações → Atualizador do Banco e aplique a migration antes de validar/importar os títulos.</div><?php endif;?>

  <section class="ri-card"><h2>1. Enviar planilha de Contas a Receber</h2><p class="ri-muted">O importador usa o <strong>Pessoa_Codigo</strong> para localizar o cliente no mapa criado pela importação de Pessoas. O código nunca é gravado diretamente como <code>person_id</code>. Para CPlus 4, inclua também <strong>ID_TITULO_ORIGEM</strong> com o CODCR original; essa chave evita duplicidade após mudanças de saldo.</p>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=customer_import_h($csrf)?>"><input type="hidden" name="action" value="upload"><div class="ri-field"><label>Sistema de origem</label><select name="source_system"><option value="PROSPERO_EXCEL">Próspero / Planilha tradicional</option><option value="CPLUS4">CPlus 4</option></select></div><div class="ri-field"><label>Arquivo XLSX, XLSM ou CSV</label><input type="file" name="receivable_file" accept=".xlsx,.xlsm,.csv" required></div><div class="ri-actions"><button class="ri-btn primary" type="submit">Ler e validar planilha</button><a class="ri-btn" href="gestao_clientes_importar.php">Importar / remapear clientes</a><a class="ri-btn" href="financeiro_contas_receber.php">Voltar ao Contas a Receber</a></div></form>
    <?php if($analysis):?><div class="ri-grid" style="margin-top:14px"><div class="ri-kpi"><small>Arquivo</small><strong style="font-size:14px"><?=customer_import_h($analysis['upload']['original_name']??'')?></strong></div><div class="ri-kpi"><small>Origem</small><strong><?=customer_import_h((string)($analysis['upload']['source_system']??'PROSPERO_EXCEL'))?></strong></div><div class="ri-kpi"><small>Aba</small><strong style="font-size:16px"><?=customer_import_h($analysis['matrix']['sheet']??'')?></strong></div><div class="ri-kpi"><small>Linhas após cabeçalho</small><strong><?= (int)$analysis['data_count']?></strong></div></div><?php endif;?>
  </section>

  <?php if($preflight):?>
  <section class="ri-card"><h2>2. Conferência obrigatória</h2><p class="ri-muted">A importação só é liberada quando não existe linha inválida nem código de cliente sem relacionamento.</p>
    <div class="ri-grid">
      <div class="ri-kpi"><small>Títulos válidos</small><strong><?= (int)($stats['rows_valid']??0)?></strong></div><div class="ri-kpi"><small>Clientes distintos</small><strong><?= (int)($stats['customers']??0)?></strong></div><div class="ri-kpi"><small>Clientes sem vínculo</small><strong><?= (int)($stats['unresolved']??0)?></strong></div>
      <div class="ri-kpi"><small>Valor original</small><strong>R$ <?=number_format((float)($stats['amount_total']??0),2,',','.')?></strong></div><div class="ri-kpi"><small>Já recebido</small><strong>R$ <?=number_format((float)($stats['paid_total']??0),2,',','.')?></strong></div><div class="ri-kpi"><small>Saldo em aberto</small><strong>R$ <?=number_format((float)($stats['open_total']??0),2,',','.')?></strong></div>
      <div class="ri-kpi"><small>Novos títulos</small><strong><?= (int)($stats['new_rows']??0)?></strong></div><div class="ri-kpi"><small>Já importados</small><strong><?= (int)($stats['already_imported']??0)?></strong></div><div class="ri-kpi"><small>Linhas inválidas</small><strong><?= (int)($stats['rows_errors']??0)?></strong></div>
    </div>
    <?php if(!empty($preflight['ready'])):?><div class="ri-msg ok" style="margin-top:12px"><strong>Pré-validação aprovada.</strong> Todos os códigos possuem cliente vinculado e os totais estão prontos para conferência.</div><?php else:?><div class="ri-msg err" style="margin-top:12px"><strong>Importação bloqueada.</strong> Corrija os itens abaixo antes de continuar.</div><?php endif;?>
    <?php if(!empty($preflight['unresolved'])):?><div class="ri-scroll" style="margin-top:12px"><table class="ri-table"><thead><tr><th>Linha</th><th>Código</th><th>Documento</th><th>Histórico</th></tr></thead><tbody><?php foreach($preflight['unresolved'] as $u):?><tr><td><?= (int)$u['line']?></td><td><strong><?=customer_import_h($u['source_code'])?></strong></td><td><?=customer_import_h($u['document_number'])?></td><td><?=customer_import_h($u['history'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    <?php foreach((array)($preflight['errors']??[]) as $e):?><div class="ri-msg err" style="margin-top:8px"><?=customer_import_h($e)?></div><?php endforeach;?>
  </section>

  <section class="ri-card"><h2>3. Prévia dos títulos</h2><div class="ri-scroll"><table class="ri-table"><thead><tr><th>Linha</th><th>Cód. cliente</th><th>Cliente Nexa</th><th>Documento</th><th>Emissão</th><th>Vencimento</th><th>Valor</th><th>Aberto</th><th>Status da importação</th></tr></thead><tbody><?php foreach((array)$preflight['preview'] as $r):?><tr><td><?= (int)$r['line']?></td><td><?=customer_import_h($r['source_code'])?></td><td><?=customer_import_h($r['person_name']?:'SEM VÍNCULO')?></td><td><?=customer_import_h($r['document_number'])?></td><td><?=customer_import_h($r['issue_date'])?></td><td><?=customer_import_h($r['due_date'])?></td><td>R$ <?=number_format((float)$r['amount'],2,',','.')?></td><td>R$ <?=number_format((float)$r['open_amount'],2,',','.')?></td><td><?php if($r['already_imported']):?><span class="ri-badge warn">Já importado</span><?php else:?><span class="ri-badge ok">Novo</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>

  <section class="ri-card"><h2>4. Importar em lotes de 150</h2><p class="ri-muted">O navegador processará até <strong>150 títulos por chamada</strong>, registrando o andamento no banco. Caso a conexão caia, recarregue esta página e retome a importação com o mesmo arquivo. O Plano de Contas é obrigatório porque <code>finance_receivables.chart_account_id</code> é obrigatório. Títulos com saldo parcialmente recebido preservam valor original, valor pago e saldo; uma baixa histórica sem movimento bancário é criada apenas para manter o saldo operacional correto.</p>
    <form method="post" id="ri-import-form"><input type="hidden" name="csrf" value="<?=customer_import_h($csrf)?>"><input type="hidden" name="upload_token" value="<?=customer_import_h($token)?>"><div class="ri-grid"><div class="ri-field"><label>Plano de Contas *</label><select name="chart_account_id" required><option value="0">— Selecione —</option><?php foreach($chartAccounts as $a):?><option value="<?= (int)$a['id']?>" <?= $chartAccountId===(int)$a['id']?'selected':''?>><?=customer_import_h(trim((string)$a['code'].' - '.(string)$a['name']))?></option><?php endforeach;?></select></div><div class="ri-field"><label>Centro de Custo</label><select name="cost_center_id"><option value="0">— Sem centro de custo —</option><?php foreach($costCenters as $c):?><option value="<?= (int)$c['id']?>" <?= $costCenterId===(int)$c['id']?'selected':''?>><?=customer_import_h(trim((string)$c['code'].' - '.(string)$c['name']))?></option><?php endforeach;?></select></div><div class="ri-field"><label>Confirmação</label><input name="confirmation" autocomplete="off" placeholder="Digite IMPORTAR" required></div></div><div class="ri-actions"><button id="ri-run" class="ri-btn danger" type="button" <?=empty($preflight['ready'])?'disabled':''?>>Iniciar / retomar importação</button><button id="ri-pause" class="ri-btn" type="button" disabled>Pausar após o lote atual</button><button id="ri-cancel" class="ri-btn" type="button" style="display:none">Encerrar processo (não desfaz importados)</button></div></form>
    <div id="ri-progress-area" style="display:none;margin-top:14px" aria-live="polite">
      <strong id="ri-progress-label">Aguardando...</strong>
      <div style="background:#e2e8f0;border-radius:999px;height:14px;margin-top:9px;overflow:hidden"><div id="ri-progress-fill" style="width:0%;height:100%;background:#0b1f5b;transition:width .2s"></div></div>
      <div id="ri-progress-detail" class="ri-muted" style="margin-top:8px"></div>
    </div>
  </section>
  <?php endif;?>

  <?php if($schemaReady):?><section class="ri-card"><h2>Histórico dos lotes</h2><div class="ri-scroll"><table class="ri-table"><thead><tr><th>Lote</th><th>Data</th><th>Arquivo</th><th>Status</th><th>Válidos</th><th>Importados</th><th>Ignorados</th><th>Valor</th><th>Aberto</th></tr></thead><tbody><?php if(!$batches):?><tr><td colspan="9">Nenhum lote importado.</td></tr><?php endif;?><?php foreach($batches as $b):?><tr><td>#<?= (int)$b['id']?></td><td><?=customer_import_h((string)$b['started_at'])?></td><td><?=customer_import_h((string)$b['original_file_name'])?></td><td><?=customer_import_h((string)$b['status'])?></td><td><?= (int)$b['rows_valid']?></td><td><?= (int)$b['rows_imported']?></td><td><?= (int)$b['rows_skipped']?></td><td>R$ <?=number_format((float)$b['amount_total'],2,',','.')?></td><td>R$ <?=number_format((float)$b['open_total'],2,',','.')?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
</div>
<?php if($preflight && $schemaReady): ?>
<script>
(function(){
  const form=document.getElementById('ri-import-form'), start=document.getElementById('ri-run');
  if(!form||!start)return;
  const pause=document.getElementById('ri-pause'), cancel=document.getElementById('ri-cancel');
  const area=document.getElementById('ri-progress-area'), fill=document.getElementById('ri-progress-fill');
  const label=document.getElementById('ri-progress-label'), detail=document.getElementById('ri-progress-detail');
  let current=null, running=false, paused=false;
  function paint(p){
    if(!p)return;current=p;area.style.display='block';
    const pct=Math.max(0,Math.min(100,Number(p.percent)||0));fill.style.width=pct+'%';
    label.textContent=(p.status==='SUCCESS'?'Importação concluída':p.status==='CANCELLED'?'Importação encerrada':'Importação em andamento')+' — '+pct.toFixed(1)+'%';
    detail.textContent='Lote #'+p.batch_id+' | '+p.processed+' de '+p.total+' processados | '+p.imported+' importados | '+p.skipped+' ignorados (já existentes)';
    cancel.style.display=p.status==='RUNNING'?'inline-block':'none';
    start.textContent=p.status==='RUNNING'?'Retomar importação':(p.status==='SUCCESS'?'Importação concluída':'Iniciar nova importação');
    start.disabled=running || p.status==='SUCCESS' || p.status==='CANCELLED';
    pause.disabled=!running;
  }
  async function request(action,extra={}) {
    const data=new FormData(form);data.set('action',action);
    for(const [k,v] of Object.entries(extra))data.set(k,String(v));
    const response=await fetch(window.location.href.split('#')[0],{method:'POST',body:data,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
    const payload=await response.json().catch(()=>{throw new Error('Servidor não retornou JSON. Verifique sessão e logs do PHP.');});
    if(!response.ok||!payload.ok)throw new Error(payload.message||'Falha ao processar lote.');
    return payload.progress;
  }
  async function run(){
    if(running)return;
    if(!current){
      if((form.elements.confirmation.value||'').trim().toUpperCase()!=='IMPORTAR'){
        alert('Digite IMPORTAR para iniciar.');return;
      }
      if(!form.elements.chart_account_id.value||form.elements.chart_account_id.value==='0'){
        alert('Selecione o Plano de Contas.');return;
      }
      if(!confirm('Confirma a importação financeira em lotes? Os lotes concluídos não serão desfeitos ao pausar.'))return;
    }
    running=true;paused=false;let problem='';pause.textContent='Pausar após o lote atual';pause.disabled=false;start.disabled=true;
    try{
      if(!current)paint(await request('chunk_start'));
      while(current && current.status==='RUNNING' && !paused){
        paint(await request('chunk_step',{batch_id:current.batch_id}));
      }
      if(current&&current.status==='SUCCESS'){
        detail.textContent+=' — Todos os lotes foram confirmados no banco.';
      }
    }catch(e){
      paused=true;problem=e.message;
    }finally{
      running=false;pause.disabled=true;
      if(current)paint(current);
      if(problem){label.textContent='Erro no lote: '+problem;detail.textContent='Os lotes anteriores permanecem confirmados. Confira o erro e clique em Retomar.';}
      else if(paused && current && current.status==='RUNNING')label.textContent='Importação pausada após o último lote confirmado';
      if(current && current.status==='RUNNING')start.disabled=false;
    }
  }
  start.addEventListener('click',run);
  pause.addEventListener('click',()=>{paused=true;pause.disabled=true;pause.textContent='Pausando...';});
  cancel.addEventListener('click',async()=>{
    if(!current||current.status!=='RUNNING'||running)return;
    if(!confirm('Encerrar definitivamente o processo? Os títulos dos lotes já confirmados NÃO serão apagados.'))return;
    try{paint(await request('chunk_cancel',{batch_id:current.batch_id}));}
    catch(e){alert(e.message);}
  });
  request('chunk_state').then(p=>{if(p)paint(p);}).catch(()=>{});
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>
