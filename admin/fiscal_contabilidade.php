<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/accounting_module.php';

$cid=(int)COMPANY_ID;

// Consulta leve, sem gerar relatorios. A requisicao de acompanhamento
// consegue responder mesmo enquanto outra requisicao gera os arquivos.
if (isset($_GET['closure_status'])) {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: private, no-store, max-age=0');
  $id=max(0,(int)($_GET['id'] ?? 0));
  try {
    $st=$pdo->prepare("SELECT id,status,COALESCE(error_message,'') AS message,updated_at,generated_at FROM accounting_closures WHERE company_id=? AND id=? LIMIT 1");
    $st->execute([$cid,$id]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Fechamento não encontrado.']); }
    else {
      echo json_encode(['ok'=>true,'id'=>(int)$row['id'],'status'=>(string)$row['status'],
        'message'=>(string)$row['message'],'updated_at'=>(string)($row['updated_at'] ?? ''),
        'generated_at'=>(string)($row['generated_at'] ?? '')],JSON_UNESCAPED_UNICODE);
    }
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Não foi possível consultar o status. Verifique a conexão com o banco.'],JSON_UNESCAPED_UNICODE);
  }
  exit;
}

$err='';
try { accounting_ensure_schema($pdo); fiscal_nfce_ensure_schema($pdo); inventory_ensure_schema($pdo); } catch (Throwable $e) { $err=$e->getMessage(); }

if ($_SERVER['REQUEST_METHOD']==='POST' && $err==='') {
  try {
    accounting_csrf_check((string)($_POST['csrf'] ?? ''),'admin_accounting');
    $action=(string)($_POST['action'] ?? '');
    if ($action==='generate') {
      $ini=accounting_validate_date((string)($_POST['ini'] ?? ''),date('Y-m-01'));
      $fim=accounting_validate_date((string)($_POST['fim'] ?? ''),date('Y-m-d'));
      $model=(string)($_POST['model'] ?? '');
      $series=trim((string)($_POST['series'] ?? ''));
      $notes=trim((string)($_POST['notes'] ?? ''));
      $options=[
        'include_inventory'=>isset($_POST['include_inventory']),
        'inventory_date'=>(string)($_POST['inventory_date'] ?? inventory_month_end($fim)),
        'inventory_stock_filter'=>(string)($_POST['inventory_stock_filter'] ?? 'positivos'),
        'inventory_cost_method'=>(string)($_POST['inventory_cost_method'] ?? 'average'),
        'inventory_book_number'=>max(1,(int)($_POST['inventory_book_number'] ?? 1)),
        'inventory_initial_page'=>max(1,(int)($_POST['inventory_initial_page'] ?? 1)),
        'inventory_excluded_groups'=>(array)($_POST['inventory_excluded_groups'] ?? []),
      ];
      $uid=function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      $id=accounting_reserve_closure($pdo,$cid,$ini,$fim,$model,$series,$notes,$uid,!empty($options['include_inventory']));
      if (session_status() !== PHP_SESSION_ACTIVE) throw new RuntimeException('Sessão de usuário indisponível para gerar o fechamento.');
      if (!isset($_SESSION['nexa_accounting_queue']) || !is_array($_SESSION['nexa_accounting_queue'])) $_SESSION['nexa_accounting_queue']=[];
      $_SESSION['nexa_accounting_queue'][$id]=['company_id'=>$cid,'user_id'=>$uid,'ini'=>$ini,'fim'=>$fim,
        'model'=>in_array($model,['55','65'],true)?$model:'',
        'series'=>$series,'notes'=>$notes,'options'=>$options];
      header('Location: fiscal_contabilidade.php?processing=' . $id);
      exit;
    }
    if ($action==='run_closure') {
      header('Content-Type: application/json; charset=UTF-8');
      header('Cache-Control: private, no-store, max-age=0');
      $runId=max(0,(int)($_POST['id'] ?? 0));
      $uid=function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      $queued = $_SESSION['nexa_accounting_queue'][$runId] ?? null;
      if (!is_array($queued) || (int)($queued['company_id'] ?? 0)!==$cid || (int)($queued['user_id'] ?? -1)!==$uid) {
        throw new RuntimeException('Sessão do fechamento não encontrada ou já utilizada. Volte à lista e consulte o status.');
      }
      unset($_SESSION['nexa_accounting_queue'][$runId]);
      // Libera o lock da sessão: o navegador pode consultar o progresso
      // enquanto esta chamada executa os relatórios no servidor.
      if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
      @set_time_limit(0);
      @ignore_user_abort(true);
      $opts=(array)($queued['options'] ?? []);
      $opts['reserved_closure_id']=$runId;
      $result=accounting_generate_closure($pdo,$cid,(string)$queued['ini'],(string)$queued['fim'],
        (string)$queued['model'],(string)$queued['series'],(string)$queued['notes'],$uid,$opts);
      echo json_encode(['ok'=>true,'id'=>$result,'status'=>'CONCLUIDO'],JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($action==='delete') {
      $id=(int)($_POST['id'] ?? 0);
      if ($id<=0) throw new RuntimeException('Fechamento inválido.');
      $check=$pdo->prepare('SELECT status FROM accounting_closures WHERE company_id=? AND id=? LIMIT 1');
      $check->execute([$cid,$id]);
      if (in_array((string)$check->fetchColumn(),['AGUARDANDO','PROCESSANDO'],true)) {
        throw new RuntimeException('Este fechamento está em andamento. Aguarde a conclusão antes de removê-lo.');
      }
      accounting_delete_closure($pdo,$cid,$id);
      header('Location: fiscal_contabilidade.php?ok=' . rawurlencode('Fechamento removido.'));
      exit;
    }
  } catch (Throwable $e) {
    $err=$e->getMessage();
    if (($_POST['action'] ?? '')==='run_closure') {
      $runId=max(0,(int)($_POST['id'] ?? 0));
      try {
        $pdo->prepare("UPDATE accounting_closures SET status='ERRO',error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='AGUARDANDO'")
          ->execute([substr($err,0,3500),$cid,$runId]);
      } catch (Throwable $ignored) {}
      http_response_code(500);
      header('Content-Type: application/json; charset=UTF-8');
      header('Cache-Control: private, no-store, max-age=0');
      echo json_encode(['ok'=>false,'error'=>$err],JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

$progressId=max(0,(int)($_GET['processing'] ?? 0));
$progressClosure=null;
if ($progressId>0) {
  try {
    $st=$pdo->prepare('SELECT id,status,period_start,period_end,error_message FROM accounting_closures WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$cid,$progressId]);
    $progressClosure=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$progressClosure) $err=$err ?: 'Fechamento não encontrado nesta empresa.';
  } catch (Throwable $e) { $err=$err ?: $e->getMessage(); }
}

$seriesRows=[];
try {
  $st=$pdo->prepare("SELECT DISTINCT series FROM fiscal_documents WHERE company_id=? AND model IN ('55','65') AND COALESCE(series,'')<>'' ORDER BY CAST(series AS UNSIGNED),series");
  $st->execute([$cid]);
  $seriesRows=$st->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

$inventoryGroups=[];
try { $inventoryGroups=inventory_product_groups($pdo,$cid); } catch (Throwable $e) {}

$closures=[];$filesByClosure=[];
try {
  $closures=accounting_closures($pdo,$cid,80);
  $filesByClosure=accounting_files_by_closure($pdo,$cid,array_column($closures,'id'));
} catch (Throwable $e) { $err=$err ?: $e->getMessage(); }

$iniDefault=(string)($_POST['ini'] ?? date('Y-m-01'));
$fimDefault=(string)($_POST['fim'] ?? date('Y-m-d'));
$modelDefault=(string)($_POST['model'] ?? '');
$seriesDefault=(string)($_POST['series'] ?? '');
$includeInventoryDefault=isset($_POST['include_inventory']);
$inventoryDateDefault=(string)($_POST['inventory_date'] ?? inventory_month_end($fimDefault));
$inventoryStockDefault=(string)($_POST['inventory_stock_filter'] ?? 'positivos');
$inventoryCostDefault=(string)($_POST['inventory_cost_method'] ?? 'average');
$inventoryBookDefault=max(1,(int)($_POST['inventory_book_number'] ?? 1));
$inventoryPageDefault=max(1,(int)($_POST['inventory_initial_page'] ?? 1));
$inventoryExcludedDefault=array_map('strval',(array)($_POST['inventory_excluded_groups'] ?? []));

fiscal_admin_start('Documentos para Contabilidade','fiscal_contabilidade');
fiscal_page_header('Documentos para Contabilidade','Gere e disponibilize um fechamento contábil e fiscal por período para a Central do Contador Próspero.',[
  ['label'=>'Cadastrar contadores','href'=>'fiscal_contadores.php','class'=>'btn'],
  ['label'=>'Abrir Central do Contador','href'=>accounting_public_central_url(),'class'=>'btn'],
  ['label'=>'Documentos fiscais','href'=>'fiscal_relatorio_documentos.php','class'=>'btn'],
]);
fiscal_report_tabs('fiscal_contabilidade');
fiscal_flash();
if ($err!=='') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<?php if ($progressClosure): ?>
<div class="fiscal-card" id="accounting-progress" data-id="<?= (int)$progressClosure['id'] ?>" data-status="<?= fiscal_h((string)$progressClosure['status']) ?>" style="margin-bottom:14px">
  <div class="fiscal-list-head">
    <div><h2>Acompanhamento do fechamento #<?= (int)$progressClosure['id'] ?></h2>
      <p class="fiscal-muted"><?= date('d/m/Y',strtotime((string)$progressClosure['period_start'])) ?> a <?= date('d/m/Y',strtotime((string)$progressClosure['period_end'])) ?></p></div>
    <span class="fiscal-badge" id="accounting-progress-badge"><?= fiscal_h((string)$progressClosure['status']) ?></span>
  </div>
  <p id="accounting-progress-message" role="status" aria-live="polite"><?= fiscal_h((string)($progressClosure['error_message'] ?: 'Preparando fechamento...')) ?></p>
  <div id="accounting-progress-alert" class="msg" style="display:none;margin-top:10px" role="alert"></div>
  <div class="fiscal-form-actions" style="margin-top:12px">
    <a class="btn" href="fiscal_contabilidade.php">Voltar ao novo fechamento e consultar a lista</a>
    <button type="button" class="btn btn-sm" id="accounting-progress-check">Atualizar status</button>
  </div>
  <small class="fiscal-muted">A publicação é feita no servidor. Acompanhe as etapas ou volte à lista: não é necessário gerar outro fechamento enquanto este estiver processando.</small>
</div>
<?php endif; ?>
<div class="fiscal-grid accounting-top-grid" style="align-items:start">
  <div class="fiscal-card">
    <h2>Novo fechamento</h2>
    <p class="fiscal-muted">Os relatórios e os XMLs são gerados separadamente por modelo e série. O SINTEGRA é gerado em um único arquivo para todo o período. O inventário só será incluído quando você marcar a opção.</p>
    <form method="post" class="fiscal-form" data-no-processing>
      <input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_accounting')) ?>">
      <input type="hidden" name="action" value="generate">
      <div class="fiscal-form-grid">
        <div><label>Data inicial</label><input type="date" name="ini" value="<?= fiscal_h($iniDefault) ?>" required></div>
        <div><label>Data final</label><input type="date" name="fim" id="accounting-period-end" value="<?= fiscal_h($fimDefault) ?>" required></div>
        <div><label>Tipo de documento</label><select name="model">
          <option value="" <?= $modelDefault===''?'selected':'' ?>>NF-e e NFC-e</option>
          <option value="65" <?= $modelDefault==='65'?'selected':'' ?>>Somente NFC-e</option>
          <option value="55" <?= $modelDefault==='55'?'selected':'' ?>>Somente NF-e</option>
        </select></div>
        <div><label>Série</label><select name="series"><option value="">Todas as séries</option>
          <?php foreach ($seriesRows as $serie): $serie=(string)$serie; ?><option value="<?= fiscal_h($serie) ?>" <?= $seriesDefault===$serie?'selected':'' ?>>Série <?= fiscal_h($serie) ?></option><?php endforeach; ?>
        </select></div>
        <div class="span-2"><label>Observações para o contador</label><textarea name="notes" placeholder="Ex.: fechamento mensal, documentos complementares, orientações..."><?= fiscal_h((string)($_POST['notes'] ?? '')) ?></textarea></div>

        <div class="span-3 accounting-inventory-toggle">
          <label class="accounting-checkline"><input type="checkbox" name="include_inventory" id="include-inventory" value="1" <?= $includeInventoryDefault?'checked':'' ?>><span>Incluir inventário de estoque neste fechamento</span></label>
          <small>Desmarcado por padrão. Ao incluir, o inventário será gravado permanentemente no menu Inventário.</small>
        </div>

        <div class="span-3 accounting-inventory-options" id="inventory-options" <?= $includeInventoryDefault?'':'hidden' ?>>
          <h3>Opções do inventário</h3>
          <div class="fiscal-form-grid">
            <div><label>Data do inventário</label><input type="date" name="inventory_date" id="inventory-date" value="<?= fiscal_h($inventoryDateDefault) ?>"><small>Deve ser o último dia do mês.</small></div>
            <div><label>Estoque a considerar</label><select name="inventory_stock_filter">
              <option value="positivos" <?= $inventoryStockDefault==='positivos'?'selected':'' ?>>Somente positivos</option>
              <option value="todos" <?= $inventoryStockDefault==='todos'?'selected':'' ?>>Todo o estoque</option>
              <option value="negativos" <?= $inventoryStockDefault==='negativos'?'selected':'' ?>>Somente negativos</option>
            </select></div>
            <div><label>Critério de valorização</label><select name="inventory_cost_method">
              <option value="average" <?= $inventoryCostDefault==='average'?'selected':'' ?>>Custo médio</option>
              <option value="last" <?= $inventoryCostDefault==='last'?'selected':'' ?>>Último custo</option>
            </select></div>
            <div><label>Número do livro</label><input type="number" min="1" name="inventory_book_number" value="<?= $inventoryBookDefault ?>"></div>
            <div><label>Folha inicial</label><input type="number" min="1" name="inventory_initial_page" value="<?= $inventoryPageDefault ?>"></div>
            <div class="span-3">
              <div class="accounting-group-head"><div><label>Grupos que não devem sair no inventário</label><small>Marque os grupos a excluir somente deste inventário.</small></div><?php if($inventoryGroups):?><button class="btn btn-sm" type="button" id="accounting-clear-groups">Desmarcar todos</button><?php endif;?></div>
              <?php if (!$inventoryGroups): ?><div class="fiscal-empty compact"><span>Nenhum grupo de produto cadastrado.</span></div><?php else: ?>
                <div class="accounting-group-grid">
                <?php foreach ($inventoryGroups as $group): ?><label><input type="checkbox" name="inventory_excluded_groups[]" value="<?= fiscal_h($group) ?>" <?= in_array($group,$inventoryExcludedDefault,true)?'checked':'' ?>><span><?= fiscal_h($group) ?></span></label><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="span-3 fiscal-form-actions"><button class="btn btn-primary" type="submit">Gerar fechamento e publicar</button></div>
      </div>
    </form>
  </div>
  <div class="fiscal-card">
    <h2>Conteúdo gerado</h2>
    <div class="accounting-checklist">
      <div><strong>Pacote contábil completo</strong><span>ZIP único com todos os arquivos gerados.</span></div>
      <div><strong>Relação de documentos fiscais</strong><span>PDF separado por NF-e/NFC-e e por série.</span></div>
      <div><strong>Documentos agrupados por CFOP</strong><span>PDF separado por modelo e série.</span></div>
      <div><strong>CFOP por forma de pagamento</strong><span>PDF separado por modelo e série.</span></div>
      <div><strong>SINTEGRA</strong><span>Um único arquivo texto consolidando todos os documentos fiscais do período conforme os filtros do fechamento.</span></div>
      <div><strong>XMLs fiscais</strong><span>ZIP separado por modelo e série, incluindo os XMLs encontrados.</span></div>
      <div><strong>Inventário opcional</strong><span>PDF e fotografia permanente do estoque somente quando marcado.</span></div>
    </div>
    <div class="msg" style="margin-top:14px">Ao selecionar “todas as séries”, os relatórios em PDF e os ZIPs de XML são separados por série, enquanto o SINTEGRA permanece único e consolidado para o período. O inventário incluído também aparecerá na lista do menu Inventário.</div>
  </div>
</div>

<div class="fiscal-card" style="margin-top:14px">
  <div class="fiscal-list-head"><h2>Fechamentos publicados</h2><span class="fiscal-list-count"><?= count($closures) ?> fechamento(s)</span></div>
  <?php if (!$closures): ?><div class="fiscal-empty"><strong>Nenhum fechamento gerado.</strong><span>Use o formulário acima para preparar o primeiro pacote da contabilidade.</span></div><?php endif; ?>
  <?php foreach ($closures as $closure): $closureId=(int)$closure['id']; $status=(string)$closure['status']; ?>
    <article id="fechamento-<?= $closureId ?>" class="accounting-closure <?= $status==='ERRO'?'has-error':'' ?>">
      <div class="accounting-closure-head">
        <div>
          <span class="fiscal-badge <?= $status==='CONCLUIDO'?'ok':($status==='ERRO'?'err':'') ?>"><?= fiscal_h($status) ?></span>
          <?php if(!empty($closure['include_inventory'])):?><span class="fiscal-badge inventory-badge">Com inventário</span><?php endif;?>
          <h3><?= date('d/m/Y',strtotime((string)$closure['period_start'])) ?> a <?= date('d/m/Y',strtotime((string)$closure['period_end'])) ?></h3>
          <p><?= fiscal_h(accounting_model_label((string)$closure['document_model'])) ?> · Série <?= fiscal_h((string)$closure['series'] !== '' ? (string)$closure['series'] : 'todas') ?> · Gerado em <?= !empty($closure['generated_at']) ? date('d/m/Y H:i',strtotime((string)$closure['generated_at'])) : date('d/m/Y H:i',strtotime((string)$closure['created_at'])) ?></p>
        </div>
        <div class="accounting-kpis">
          <span><strong><?= (int)$closure['documents_count'] ?></strong> documentos</span>
          <span><strong>R$ <?= number_format((float)$closure['documents_total'],2,',','.') ?></strong> total</span>
          <span><strong><?= (int)$closure['xml_files_count'] ?></strong> XMLs</span>
          <?php if(!empty($closure['include_inventory'])):?><span><strong><?= (int)$closure['inventory_items_count'] ?></strong> itens no inventário</span><?php endif;?>
        </div>
      </div>
      <?php if (in_array($status,['AGUARDANDO','PROCESSANDO'],true)): ?>
        <div class="msg"><a href="fiscal_contabilidade.php?processing=<?= $closureId ?>">Acompanhar este fechamento</a>. Não gere outro pacote enquanto este estiver em andamento.</div>
      <?php endif; ?>
      <?php if (!empty($closure['notes'])): ?><div class="accounting-note"><?= nl2br(fiscal_h((string)$closure['notes'])) ?></div><?php endif; ?>
      <?php if (!empty($closure['error_message'])): ?><div class="msg <?= $status==='ERRO'?'err':'' ?>"><?= fiscal_h((string)$closure['error_message']) ?></div><?php endif; ?>
      <div class="accounting-files">
        <?php foreach (($filesByClosure[$closureId] ?? []) as $file): ?>
          <a class="accounting-file <?= (string)$file['file_type']==='PACOTE'?'primary':'' ?>" data-no-processing="1" download="<?= fiscal_h((string)$file['file_name']) ?>" href="fiscal_contabilidade_download.php?id=<?= (int)$file['id'] ?>">
            <strong><?= fiscal_h((string)$file['display_name']) ?></strong><span><?= fiscal_h((string)$file['file_name']) ?> · <?= fiscal_h(accounting_human_size((int)$file['file_size'])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if(!empty($closure['inventory_id'])):?><div class="accounting-inventory-link"><a class="btn btn-sm" href="fiscal_relatorio_inventario.php?action=view&id=<?= (int)$closure['inventory_id'] ?>">Consultar inventário gravado</a></div><?php endif;?>
      <?php if (!in_array($status,['AGUARDANDO','PROCESSANDO'],true)): ?>
      <form method="post" class="accounting-delete" onsubmit="return confirm('Remover este fechamento e todos os arquivos publicados? O inventário gravado, quando houver, será preservado no menu Inventário.')">
        <input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_accounting')) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $closureId ?>"><button class="btn btn-sm" type="submit">Remover fechamento</button>
      </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
<style>
.accounting-top-grid{grid-template-columns:minmax(0,1.05fr) minmax(360px,.95fr)}.accounting-checklist{display:grid;gap:10px}.accounting-checklist>div{padding:12px 14px;border:1px solid #dbe4f0;border-radius:16px;background:#f8fafc}.accounting-checklist strong,.accounting-checklist span{display:block}.accounting-checklist span{margin-top:3px;color:#64748b;font-size:13px}.accounting-inventory-toggle{padding:13px 14px;border:1px solid #c7d2fe;border-radius:16px;background:#eef2ff}.accounting-checkline{display:flex!important;align-items:center;gap:10px!important;margin:0!important;font-size:14px!important;cursor:pointer}.accounting-checkline input{width:auto!important;min-height:0!important}.accounting-inventory-toggle small,.accounting-inventory-options small,.accounting-group-head small{display:block;color:#64748b;margin-top:4px}.accounting-inventory-options{padding:14px;border:1px solid #dbe4f0;border-radius:18px;background:#fbfdff}.accounting-inventory-options[hidden]{display:none}.accounting-inventory-options h3{margin:0 0 12px}.accounting-group-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:9px}.accounting-group-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:7px;max-height:225px;overflow:auto;padding:9px;border:1px solid #dbe4f0;border-radius:15px;background:#f8fafc}.accounting-group-grid label{display:flex;gap:8px;align-items:center;padding:8px 9px;border:1px solid #e2e8f0;border-radius:11px;background:#fff;cursor:pointer}.accounting-group-grid input{width:auto!important;min-height:0!important}.fiscal-empty.compact{padding:14px}.accounting-closure{padding:18px 0;border-top:1px solid #e5eaf2}.accounting-closure:first-of-type{border-top:0}.accounting-closure-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.accounting-closure h3{margin:7px 0 3px;color:#0f172a;font-size:19px}.accounting-closure p{margin:0;color:#64748b}.accounting-kpis{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.accounting-kpis span{padding:9px 11px;border-radius:13px;background:#f1f5f9;color:#475569;font-size:12px}.accounting-kpis strong{color:#0f172a}.accounting-note{margin-top:12px;padding:11px 13px;border-left:4px solid var(--admin-accent);background:#f8fafc;border-radius:0 12px 12px 0;color:#475569}.accounting-files{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:9px;margin-top:14px}.accounting-file{display:block;padding:12px 13px;border:1px solid #dbe4f0;border-radius:15px;background:#fff;text-decoration:none;color:#0f172a}.accounting-file:hover{border-color:var(--admin-secondary);background:#f8fbff}.accounting-file.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.accounting-file strong,.accounting-file span{display:block}.accounting-file span{font-size:11px;margin-top:4px;opacity:.78;word-break:break-word}.accounting-inventory-link{display:flex;justify-content:flex-end;margin-top:10px}.accounting-delete{display:flex;justify-content:flex-end;margin-top:10px}.fiscal-badge.ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.fiscal-badge.err{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.inventory-badge{margin-left:5px;background:#eef2ff;color:#3730a3;border-color:#c7d2fe}@media(max-width:980px){.accounting-top-grid{grid-template-columns:1fr}}@media(max-width:760px){.accounting-closure-head{display:block}.accounting-kpis{justify-content:flex-start;margin-top:10px}.accounting-group-head{align-items:flex-start;flex-direction:column}.accounting-group-grid{grid-template-columns:1fr}}
</style>
<script>
(function(){
  var progress=document.getElementById('accounting-progress');
  if (progress) {
    var id=Number(progress.dataset.id)||0;
    var initial=progress.dataset.status||'';
    var label=document.getElementById('accounting-progress-badge');
    var message=document.getElementById('accounting-progress-message');
    var alertBox=document.getElementById('accounting-progress-alert');
    var check=document.getElementById('accounting-progress-check');
    var done=false,started=Date.now(),pollTimer=null;
    var url='fiscal_contabilidade.php?closure_status=1&id='+encodeURIComponent(id);
    function showAlert(txt){
      if(alertBox){alertBox.textContent=txt;alertBox.style.display='block';}
    }
    async function poll(){
      if(done)return;
      try {
        var response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
        var result=await response.json();
        if(!response.ok||!result.ok)throw new Error(result.error||'Falha ao consultar o andamento.');
        var status=String(result.status||'');
        if(label)label.textContent=status;
        if(message)message.textContent=String(result.message|| (status==='AGUARDANDO'?'Aguardando início...':'Processando arquivos fiscais...'));
        if(status==='CONCLUIDO'){
          done=true;if(pollTimer)clearInterval(pollTimer);
          window.location.href='fiscal_contabilidade.php?ok='+encodeURIComponent('Fechamento publicado com sucesso.')+'&id='+id+'#fechamento-'+id;
        } else if(status==='ERRO'){
          done=true;if(pollTimer)clearInterval(pollTimer);
          showAlert('Não foi possível concluir o fechamento. Consulte a mensagem acima e volte à tela de novo fechamento.');
        } else if(Date.now()-started>90000){
          showAlert('Este fechamento está demorando mais que o normal. Você pode voltar ao formulário e acompanhar o status na lista. Não inicie outro fechamento enquanto este estiver processando.');
        }
      } catch(e){
        showAlert('Não foi possível consultar o progresso agora. A geração pode continuar no servidor. Use “Atualizar status” ou volte à listagem.');
      }
    }
    if(check)check.addEventListener('click',poll);
    poll();
    pollTimer=setInterval(poll,3500);
    if(initial==='AGUARDANDO'){
      var data=new FormData();
      data.set('csrf',<?= json_encode(accounting_csrf_token('admin_accounting'), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP) ?>);
      data.set('action','run_closure');
      data.set('id',String(id));
      fetch('fiscal_contabilidade.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
        .then(async function(resp){
          var json=await resp.json();
          if(!resp.ok||!json.ok)throw new Error(json.error||'Falha na geração.');
          poll();
        }).catch(function(e){
          showAlert('A conexão da geração foi interrompida ou houve erro: '+String(e.message||e)+'. Confira o status antes de tentar novamente.');
          poll();
        });
    }
  }
  var toggle=document.getElementById('include-inventory');
  var options=document.getElementById('inventory-options');
  var periodEnd=document.getElementById('accounting-period-end');
  var inventoryDate=document.getElementById('inventory-date');
  function setVisibility(){if(options)options.hidden=!(toggle&&toggle.checked);}
  function monthEnd(value){
    if(!/^\d{4}-\d{2}-\d{2}$/.test(value||''))return '';
    var parts=value.split('-'),y=parseInt(parts[0],10),m=parseInt(parts[1],10);
    var last=new Date(y,m,0).getDate();
    return parts[0]+'-'+parts[1]+'-'+String(last).padStart(2,'0');
  }
  if(toggle){toggle.addEventListener('change',setVisibility);setVisibility();}
  if(periodEnd&&inventoryDate)periodEnd.addEventListener('change',function(){inventoryDate.value=monthEnd(periodEnd.value);});
  var clear=document.getElementById('accounting-clear-groups');
  if(clear)clear.addEventListener('click',function(){document.querySelectorAll('input[name="inventory_excluded_groups[]"]').forEach(function(el){el.checked=false;});});
})();
</script>
<?php fiscal_admin_end(); ?>
