<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/customer_import_module.php';
require_once __DIR__ . '/../config/company_sequences.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'customers_write')
  : (int)COMPANY_ID;
$crossContext = $companyId !== (int)COMPANY_ID;
$companyRow = function_exists('multi_company_context_company_row') ? multi_company_context_company_row($pdo,$companyId) : null;
$companyName = trim((string)($companyRow['name'] ?? '')) ?: ('Empresa #' . $companyId);
if ($crossContext && function_exists('nexa_people_permission_allowed') && !nexa_people_permission_allowed($pdo,$companyId)) {
  access_render_denied('O acesso ao cadastro de Pessoas foi desabilitado para este perfil.');
}
company_sequence_ensure_schema($pdo, false);
$userId=(int)(auth_user_id() ?? 0);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['customer_import_csrf'])) $_SESSION['customer_import_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['customer_import_csrf'];

$msg='';$err='';$token=trim((string)($_POST['upload_token'] ?? $_GET['upload_token'] ?? ''));
$analysis=null;$mapping=[];$typeStats=[];$typeMap=[];$preview=[];$duplicateDocs=['count'=>0,'occurrences'=>0,'items'=>[]];$result=null;
$unknownAction=strtoupper(trim((string)($_POST['unknown_type_action'] ?? 'AUTO_DOCUMENTO')));
$ignoreSourceCodes=trim((string)($_POST['ignore_source_codes'] ?? ''));
$sourceMapReady=customer_import_source_map_ready($pdo);
if(!in_array($unknownAction,['AUTO_DOCUMENTO','FISICA','JURIDICA','IGNORAR'],true))$unknownAction='AUTO_DOCUMENTO';

try{
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $postedCsrf=(string)($_POST['csrf']??'');
    if($postedCsrf===''||!hash_equals($csrf,$postedCsrf))throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action=(string)($_POST['action']??'');
    if($action==='upload'){
      $upload=customer_import_store_upload($companyId,$userId,(array)($_FILES['customer_file']??[]),(string)($_POST['source_system']??'PROSPERO_EXCEL'));
      $token=(string)$upload['token'];
      $msg='Planilha carregada. Agora confira o vínculo das colunas e o tipo de pessoa antes de importar.';
    }
    if($token!==''){
      $analysis=customer_import_analysis($companyId,$token);
      $mapping=customer_import_mapping_from_post($_POST,(array)$analysis['suggested_mapping']);
      $typeStats=customer_import_type_stats($analysis,$mapping);
      foreach($typeStats as $stat){$typeMap[(string)$stat['key']]=(string)$stat['suggestion'];}
      foreach((array)($_POST['type_map']??[]) as $key=>$value){
        $v=strtoupper(trim((string)$value));if(in_array($v,['AUTO','FISICA','JURIDICA','IGNORAR'],true))$typeMap[(string)$key]=$v;
      }
      $preview=customer_import_preview($pdo,$companyId,$analysis,$mapping,$typeMap,$unknownAction,30);
      $duplicateDocs=customer_import_duplicate_documents($analysis,$mapping);
    }
    if($action==='preview')$msg='Prévia atualizada com o mapeamento informado.';
    if($action==='import_batch'){
      if(empty($_POST['confirm_import']))throw new RuntimeException('Marque a confirmação antes de importar os clientes.');
      if(!$analysis)throw new RuntimeException('Envie a planilha novamente.');
      $batchOffset=max(0,(int)($_POST['batch_offset']??0));
      $batchSize=max(20,min(250,(int)($_POST['batch_size']??150)));
      $batchSeconds=max(5.0,min(25.0,(float)($_POST['batch_seconds']??12)));
      $result=customer_import_execute($pdo,$companyId,$analysis,$mapping,$typeMap,[
        'create_new'=>!empty($_POST['create_new']),
        'update_existing'=>!empty($_POST['update_existing']),
        'use_source_number'=>!empty($_POST['use_source_number']),
        'unknown_type_action'=>$unknownAction,
        'ignore_source_codes'=>$ignoreSourceCodes,
        'offset'=>$batchOffset,
        'max_rows'=>$batchSize,
        'max_seconds'=>$batchSeconds,
      ]);
      if(!headers_sent())header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      exit;
    }
    if($action==='import'){
      if(empty($_POST['confirm_import']))throw new RuntimeException('Marque a confirmação antes de importar os clientes.');
      if(!$analysis)throw new RuntimeException('Envie a planilha novamente.');
      $result=customer_import_execute($pdo,$companyId,$analysis,$mapping,$typeMap,[
        'create_new'=>!empty($_POST['create_new']),
        'update_existing'=>!empty($_POST['update_existing']),
        'use_source_number'=>!empty($_POST['use_source_number']),
        'unknown_type_action'=>$unknownAction,
        'ignore_source_codes'=>$ignoreSourceCodes,
      ]);
      $msg='Importação concluída: '.(int)$result['inserted'].' incluído(s), '.(int)$result['updated'].' atualizado(s), '.(int)$result['skipped'].' ignorado(s) e '.(int)$result['errors'].' erro(s).';
    }
  }elseif($token!==''){
    $analysis=customer_import_analysis($companyId,$token);
    $mapping=(array)$analysis['suggested_mapping'];
    $typeStats=customer_import_type_stats($analysis,$mapping);
    foreach($typeStats as $stat)$typeMap[(string)$stat['key']]=(string)$stat['suggestion'];
    $preview=customer_import_preview($pdo,$companyId,$analysis,$mapping,$typeMap,$unknownAction,30);
    $duplicateDocs=customer_import_duplicate_documents($analysis,$mapping);
  }
}catch(Throwable $e){
  if(($_SERVER['REQUEST_METHOD']??'')==='POST' && (string)($_POST['action']??'')==='import_batch'){
    if(!headers_sent())header('Content-Type: application/json; charset=UTF-8');
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }
  $err=$e->getMessage();if($token!==''&&$analysis===null){try{$analysis=customer_import_analysis($companyId,$token);$mapping=customer_import_mapping_from_post($_POST,(array)$analysis['suggested_mapping']);$typeStats=customer_import_type_stats($analysis,$mapping);foreach($typeStats as $stat)$typeMap[(string)$stat['key']]=(string)$stat['suggestion'];foreach((array)($_POST['type_map']??[]) as $key=>$value)$typeMap[(string)$key]=strtoupper((string)$value);$preview=customer_import_preview($pdo,$companyId,$analysis,$mapping,$typeMap,$unknownAction,30);$duplicateDocs=customer_import_duplicate_documents($analysis,$mapping);}catch(Throwable $ignored){}}}

if($analysis!==null && !$mapping){$mapping=(array)$analysis['suggested_mapping'];$typeStats=customer_import_type_stats($analysis,$mapping);foreach($typeStats as $stat)$typeMap[(string)$stat['key']]=(string)$stat['suggestion'];$preview=customer_import_preview($pdo,$companyId,$analysis,$mapping,$typeMap,$unknownAction,30);$duplicateDocs=customer_import_duplicate_documents($analysis,$mapping);}

gestao_admin_start('Importar clientes','gestao_clientes');
gestao_page_header('Importação de clientes por planilha','Importe clientes de XLSX, XLSM ou CSV com vínculo flexível de colunas e de Pessoa Física/Jurídica.',[
  ['href'=>'gestao_clientes.php','label'=>'Voltar para Pessoas','class'=>'btn'],
]);
gestao_tabs('gestao_clientes');
?>
<style>
.ci-wrap{display:grid;gap:14px}.ci-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:18px}.ci-card h2,.ci-card h3{margin:0 0 8px;color:#0b1f5b}.ci-muted{color:#64748b;line-height:1.5}.ci-msg{padding:12px 14px;border-radius:12px;margin-bottom:14px}.ci-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.ci-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.ci-msg.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.ci-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ci-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.ci-field{display:flex;flex-direction:column;gap:6px}.ci-field label{font-weight:800;color:#334155}.ci-field input,.ci-field select{padding:10px 11px;border:1px solid #cbd5e1;border-radius:11px;background:#fff}.ci-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}.ci-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:13px}.ci-table{width:100%;border-collapse:collapse;min-width:950px}.ci-table th,.ci-table td{padding:9px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.ci-table th{background:#f8fafc;color:#334155;font-size:12px}.ci-map{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.ci-map-item{border:1px solid #e2e8f0;background:#f8fafc;border-radius:12px;padding:10px}.ci-map-item label{display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:5px}.ci-map-item select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.ci-check{display:flex;gap:9px;align-items:flex-start;border:1px solid #dbe4f0;background:#f8fafc;border-radius:12px;padding:11px}.ci-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ci-kpi{border:1px solid #dbe4f0;border-radius:12px;padding:11px;background:#f8fafc}.ci-kpi strong{display:block;color:#0b1f5b;font-size:18px}.ci-type-row{display:grid;grid-template-columns:minmax(160px,1fr) 110px 110px 110px minmax(180px,1fr);gap:8px;align-items:center;padding:9px 0;border-bottom:1px solid #eef2f7}.ci-type-row select{padding:8px;border:1px solid #cbd5e1;border-radius:9px}.ci-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#e2e8f0;color:#334155}@media(max-width:900px){.ci-grid,.ci-grid.three,.ci-map,.ci-kpis{grid-template-columns:1fr}.ci-type-row{grid-template-columns:1fr 1fr}.ci-type-row>*:first-child{grid-column:1/-1}}
</style>
<div class="ci-wrap">
<?php if($msg!==''):?><div class="ci-msg ok"><?=customer_import_h($msg)?></div><?php endif;?>
<?php if($err!==''):?><div class="ci-msg err"><?=customer_import_h($err)?></div><?php endif;?>
<?php if($crossContext):?><div class="ci-msg warn"><strong>Empresa selecionada pelo F11:</strong> <?=customer_import_h($companyName)?>. A importação será gravada somente nesta empresa.</div><?php endif;?>
<?php if(!$sourceMapReady):?><div class="ci-msg err"><strong>Migration V11.45 pendente:</strong> aplique a atualização pelo Atualizador do Banco antes de executar a importação final. A prévia continua disponível, mas o mapa de códigos antigos precisa existir para vincular corretamente o Contas a Receber.</div><?php endif;?>

<section class="ci-card">
  <h2>1. Enviar planilha</h2>
  <p class="ci-muted">Aceita XLSX, XLSM e CSV. O Nexa tenta reconhecer automaticamente as colunas, mas você poderá alterar todos os vínculos antes da importação.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=customer_import_h($csrf)?>"><input type="hidden" name="action" value="upload">
    <div class="ci-grid"><div class="ci-field"><label>Origem da planilha</label><select name="source_system"><option value="PROSPERO_EXCEL">Próspero / Planilha tradicional</option><option value="CPLUS4">CPlus 4</option></select></div><div class="ci-field"><label>Arquivo de clientes</label><input type="file" name="customer_file" accept=".xlsx,.xlsm,.csv" required></div></div>
    <div class="ci-actions"><button class="btn btn-primary" type="submit">Ler planilha</button></div>
  </form>
  <?php if($analysis):?><div class="ci-kpis" style="margin-top:14px"><div class="ci-kpi"><small>Arquivo</small><strong style="font-size:13px"><?=customer_import_h($analysis['upload']['original_name']??'')?></strong></div><div class="ci-kpi"><small>Origem</small><strong><?=customer_import_h((string)($analysis['upload']['source_system']??'PROSPERO_EXCEL'))?></strong></div><div class="ci-kpi"><small>Aba</small><strong style="font-size:15px"><?=customer_import_h($analysis['matrix']['sheet']??'')?></strong></div><div class="ci-kpi"><small>Cabeçalho</small><strong>Linha <?= (int)$analysis['header_row']?></strong></div><div class="ci-kpi"><small>Linhas após cabeçalho</small><strong><?= (int)$analysis['data_count']?></strong></div></div><?php endif;?>
  <?php if($analysis && (int)($duplicateDocs['count']??0)>0):?><div class="ci-msg warn" style="margin-top:12px;margin-bottom:0"><strong>Atenção:</strong> foram encontrados <?= (int)$duplicateDocs['count']?> CPF/CNPJ repetido(s) na própria planilha (<?= (int)$duplicateDocs['occurrences']?> ocorrência(s) adicional(is)). O Nexa não criará clientes duplicados: na importação, o primeiro registro cria/localiza o cliente e os seguintes atualizam o mesmo cadastro quando <strong>Atualizar existentes</strong> estiver marcado.</div><?php endif;?>
</section>

<?php if($analysis):?>
<form method="post" id="customerImportForm">
<input type="hidden" name="csrf" value="<?=customer_import_h($csrf)?>"><input type="hidden" name="upload_token" value="<?=customer_import_h($token)?>">
<section class="ci-card">
  <h2>2. Vincular colunas</h2>
  <p class="ci-muted">O vínculo automático foi montado pelos nomes do cabeçalho. Confira principalmente <strong>Nome/Razão social</strong>, <strong>CPF/CNPJ</strong> e <strong>Tipo de pessoa</strong>.</p>
  <div class="ci-map">
  <?php foreach(customer_import_field_labels() as $field=>$label): $selected=$mapping[$field]??-1;?>
    <div class="ci-map-item"><label><?=customer_import_h($label)?></label><select name="column_map[<?=customer_import_h($field)?>]"><option value="-1">— Não importar —</option><?php foreach((array)$analysis['headers'] as $idx=>$header):?><option value="<?=(int)$idx?>" <?= (int)$selected===(int)$idx?'selected':''?>><?=customer_import_h(($idx+1).' · '.$header)?></option><?php endforeach;?></select></div>
  <?php endforeach;?>
  </div>
</section>

<section class="ci-card">
  <h2>3. Pessoa Física / Jurídica</h2>
  <p class="ci-muted">Aqui você pode “ligar” qualquer valor da planilha ao tipo correto no Nexa. Ex.: <strong>1 → Física</strong>, <strong>2 → Jurídica</strong>, <strong>PF → Física</strong>, <strong>PJ → Jurídica</strong>. A sugestão também considera o tamanho do CPF/CNPJ encontrado nas mesmas linhas.</p>
  <?php if(!$typeStats):?>
    <div class="ci-msg warn">Nenhuma coluna de tipo está vinculada. O Nexa identificará automaticamente por CPF (11 dígitos) ou CNPJ (14 dígitos). Se não conseguir, usará a regra de fallback abaixo.</div>
  <?php else:?>
    <div class="ci-type-row" style="font-weight:800;color:#475569"><span>Valor na planilha</span><span>Linhas</span><span>CPF</span><span>CNPJ</span><span>Interpretar como</span></div>
    <?php foreach($typeStats as $stat): $key=(string)$stat['key'];$value=strtoupper((string)($typeMap[$key]??$stat['suggestion']??'AUTO'));?>
    <div class="ci-type-row"><div><strong><?=customer_import_h($stat['raw']===''?'(vazio)':$stat['raw'])?></strong><?php if(($stat['suggestion']??'AUTO')!=='AUTO'):?><br><span class="ci-badge">Sugestão: <?=($stat['suggestion']==='JURIDICA'?'Jurídica':'Física')?></span><?php endif;?></div><span><?= (int)$stat['count']?></span><span><?= (int)$stat['cpf']?></span><span><?= (int)$stat['cnpj']?></span><select name="type_map[<?=customer_import_h($key)?>]"><option value="AUTO" <?=$value==='AUTO'?'selected':''?>>Automático</option><option value="FISICA" <?=$value==='FISICA'?'selected':''?>>Pessoa Física</option><option value="JURIDICA" <?=$value==='JURIDICA'?'selected':''?>>Pessoa Jurídica</option><option value="IGNORAR" <?=$value==='IGNORAR'?'selected':''?>>Ignorar estas linhas</option></select></div>
    <?php endforeach;?>
  <?php endif;?>
  <div class="ci-grid" style="margin-top:12px"><div class="ci-field"><label>Se o tipo não puder ser identificado</label><select name="unknown_type_action"><option value="AUTO_DOCUMENTO" <?=$unknownAction==='AUTO_DOCUMENTO'?'selected':''?>>Tentar CPF/CNPJ e, se não identificar, ignorar</option><option value="FISICA" <?=$unknownAction==='FISICA'?'selected':''?>>Assumir Pessoa Física</option><option value="JURIDICA" <?=$unknownAction==='JURIDICA'?'selected':''?>>Assumir Pessoa Jurídica</option><option value="IGNORAR" <?=$unknownAction==='IGNORAR'?'selected':''?>>Ignorar a linha</option></select></div></div>
  <div class="ci-actions"><button class="btn" type="submit" name="action" value="preview">Atualizar prévia</button></div>
</section>

<section class="ci-card">
  <h2>4. Prévia</h2>
  <p class="ci-muted">Primeiras linhas após aplicar o vínculo atual. O código IBGE é preenchido automaticamente quando o município/UF existe no catálogo fiscal do Nexa.</p>
  <div class="ci-scroll"><table class="ci-table"><thead><tr><th>Linha</th><th>Código</th><th>Nome / Razão</th><th>Fantasia</th><th>CPF/CNPJ</th><th>Tipo</th><th>Telefone</th><th>WhatsApp</th><th>Cidade/UF</th><th>IBGE</th></tr></thead><tbody>
  <?php if(!$preview):?><tr><td colspan="10">Nenhuma linha válida para prévia com o vínculo atual.</td></tr><?php endif;?>
  <?php foreach($preview as $row):?><tr><td><?= (int)$row['line']?></td><td><?=customer_import_h($row['source_code'])?></td><td><strong><?=customer_import_h($row['name'])?></strong></td><td><?=customer_import_h($row['fantasy_name'])?></td><td><?=customer_import_h($row['document'])?></td><td><?=customer_import_h($row['person_type']==='JURIDICA'?'Jurídica':($row['person_type']==='FISICA'?'Física':'Ignorar'))?></td><td><?=customer_import_h($row['phone'])?></td><td><?=customer_import_h($row['whatsapp'])?></td><td><?=customer_import_h(trim($row['city'].' / '.$row['state'],' /'))?></td><td><?=customer_import_h($row['city_ibge'])?></td></tr><?php endforeach;?>
  </tbody></table></div>
</section>

<section class="ci-card">
  <h2>5. Importar</h2>
  <div class="ci-grid three">
    <label class="ci-check"><input type="checkbox" name="create_new" value="1" <?= !isset($_POST['create_new'])||!empty($_POST['create_new'])?'checked':''?>><span><strong>Criar clientes novos</strong><br><small class="ci-muted">Inclui documentos/códigos ainda inexistentes.</small></span></label>
    <label class="ci-check"><input type="checkbox" name="update_existing" value="1" <?= !isset($_POST['update_existing'])||!empty($_POST['update_existing'])?'checked':''?>><span><strong>Atualizar existentes</strong><br><small class="ci-muted">Localiza primeiro por CPF/CNPJ e depois pelo código/nº de cadastro.</small></span></label>
    <label class="ci-check"><input type="checkbox" name="use_source_number" value="1" <?= !isset($_POST['use_source_number'])||!empty($_POST['use_source_number'])?'checked':''?>><span><strong>Usar Código como nº do cadastro</strong><br><small class="ci-muted">Quando o número estiver livre. O código original com zeros fica preservado no mapa de origem; o nº visível ainda é inteiro.</small></span></label>
  </div>
  <div class="ci-grid" style="margin-top:12px"><div class="ci-field"><label>Códigos de origem a ignorar</label><input type="text" name="ignore_source_codes" value="<?=customer_import_h($ignoreSourceCodes)?>" placeholder="Ex.: 000002, 000015"><small class="ci-muted">Opcional. Informe códigos separados por vírgula, espaço ou ponto e vírgula. Eles não serão criados nem atualizados e também não entrarão no mapa de códigos antigos.</small></div></div>
  <label class="ci-check" style="margin-top:12px"><input type="checkbox" name="confirm_import" value="1"><span><strong>Confirmo a importação para <?=customer_import_h($companyName)?></strong><br><small class="ci-muted">Clientes existentes serão atualizados somente nos campos que vierem preenchidos na planilha. Cada Código de origem será gravado no mapa da empresa para permitir a importação do Contas a Receber.</small></span></label>
  <div class="ci-actions"><button class="btn btn-primary" type="button" id="customerImportStart" <?=!$sourceMapReady?'disabled':''?>>Importar clientes em lotes</button><a class="btn" href="gestao_clientes.php">Voltar sem importar</a></div>
  <div id="customerImportProgress" class="ci-msg ok" style="display:none;margin-top:14px;margin-bottom:0">
    <strong id="customerImportProgressTitle">Preparando importação...</strong>
    <div style="height:12px;background:#dbe4f0;border-radius:999px;overflow:hidden;margin:10px 0 7px"><div id="customerImportProgressBar" style="height:100%;width:0%;background:#0b1f5b;transition:width .2s ease"></div></div>
    <small id="customerImportProgressText">0%</small>
  </div>
  <div id="customerImportResult" style="display:none;margin-top:14px;margin-bottom:0"></div>
  <?php if($result):?><div class="ci-msg <?= (int)$result['errors']>0?'warn':'ok'?>" style="margin-top:14px;margin-bottom:0"><strong>Resultado:</strong> <?= (int)$result['inserted']?> incluído(s), <?= (int)$result['updated']?> atualizado(s), <?= (int)$result['skipped']?> ignorado(s), <?= (int)($result['mapped']??0)?> código(s) de origem mapeado(s), <?= (int)($result['ignored_code']??0)?> código(s) excluído(s), <?= (int)$result['errors']?> erro(s). Tipos processados: <?= (int)$result['fisica']?> Física(s) e <?= (int)$result['juridica']?> Jurídica(s).<?php if(!empty($result['messages'])):?><br><small><?=customer_import_h(implode(' | ',array_slice((array)$result['messages'],0,8)))?></small><?php endif;?></div><?php endif;?>
</section>
</form>
<?php endif;?>
</div>
<?php if($analysis):?>
<script>
(function(){
  const form=document.getElementById('customerImportForm');
  const start=document.getElementById('customerImportStart');
  if(!form||!start)return;
  const progress=document.getElementById('customerImportProgress');
  const title=document.getElementById('customerImportProgressTitle');
  const text=document.getElementById('customerImportProgressText');
  const bar=document.getElementById('customerImportProgressBar');
  const resultBox=document.getElementById('customerImportResult');
  let running=false;
  const totals={inserted:0,updated:0,skipped:0,ignored_code:0,mapped:0,errors:0,fisica:0,juridica:0};
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  function update(r){
    const total=Math.max(1,Number(r.data_total||1));
    const done=Math.min(total,Number(r.next_offset||0));
    const pct=Math.min(100,Math.round(done*100/total));
    bar.style.width=pct+'%';
    title.textContent='Importando clientes... '+done+' de '+total;
    text.textContent=pct+'% · último lote: '+Number(r.processed||0)+' linha(s) em '+Number(r.elapsed_seconds||0).toFixed(1)+'s';
  }
  async function requestBatch(offset,retry){
    const fd=new FormData(form);
    fd.set('action','import_batch');
    fd.set('batch_offset',String(offset));
    fd.set('batch_size','150');
    fd.set('batch_seconds','12');
    try{
      const response=await fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
      const raw=await response.text();
      let data=null;try{data=JSON.parse(raw);}catch(e){throw new Error('O servidor não retornou uma resposta válida. A conexão pode ter sido interrompida.');}
      if(!response.ok||!data||data.ok!==true)throw new Error((data&&data.error)?data.error:'Falha ao importar o lote.');
      return data.result;
    }catch(e){
      if(retry<3){
        title.textContent='Conexão interrompida. Repetindo o mesmo lote com segurança...';
        await new Promise(resolve=>setTimeout(resolve,1500*(retry+1)));
        return requestBatch(offset,retry+1);
      }
      throw e;
    }
  }
  start.addEventListener('click',async function(){
    if(running)return;
    const confirmBox=form.querySelector('input[name="confirm_import"]');
    if(!confirmBox||!confirmBox.checked){alert('Marque a confirmação antes de importar os clientes.');return;}
    if(!window.confirm('Confirma a importação dos clientes para esta empresa? O processamento será feito em pequenos lotes para evitar timeout do Cloudflare.'))return;
    running=true;start.disabled=true;progress.style.display='block';resultBox.style.display='none';bar.style.width='0%';
    let offset=0;
    try{
      while(true){
        const r=await requestBatch(offset,0);
        for(const k of Object.keys(totals))totals[k]+=Number(r[k]||0);
        update(r);
        offset=Number(r.next_offset||offset);
        if(r.done)break;
        if(Number(r.processed||0)<=0)throw new Error('O lote não avançou. A importação foi interrompida para evitar repetição infinita.');
        await new Promise(resolve=>setTimeout(resolve,120));
      }
      title.textContent='Importação concluída.';bar.style.width='100%';text.textContent='100% · todos os lotes processados.';
      resultBox.className='ci-msg '+(totals.errors>0?'warn':'ok');
      resultBox.innerHTML='<strong>Resultado acumulado:</strong> '+totals.inserted+' incluído(s), '+totals.updated+' atualizado(s), '+totals.skipped+' ignorado(s), '+totals.mapped+' código(s) mapeado(s), '+totals.ignored_code+' código(s) excluído(s) e '+totals.errors+' erro(s).';
      resultBox.style.display='block';
    }catch(e){
      progress.className='ci-msg err';title.textContent='Importação interrompida.';text.textContent=e&&e.message?e.message:String(e);
      resultBox.className='ci-msg warn';resultBox.innerHTML='<strong>Até a interrupção:</strong> '+totals.inserted+' incluído(s), '+totals.updated+' atualizado(s), '+totals.mapped+' código(s) mapeado(s). Você pode clicar novamente em Importar: os lotes já gravados serão reconhecidos e não criarão clientes duplicados.';resultBox.style.display='block';
    }finally{running=false;start.disabled=false;}
  });
})();
</script>
<?php endif;?>
<?php gestao_admin_end(); ?>
