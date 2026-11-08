<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_crm.php';

$cid = crm_company_id();
$uid = crm_user_id();
$statuses = crm_statuses($pdo, $cid, true);
$types = crm_types($pdo, $cid, true);
$users = crm_users($pdo, $cid, true);
$firstStatus = (int)($statuses[0]['id'] ?? 0);
$firstType = (int)($types[0]['id'] ?? 0);
$err = '';
$errorStep = 1;
$isNew = isset($_GET['new']) || $_SERVER['REQUEST_METHOD'] === 'POST';

$form = [
  'person_id' => 0,
  'customer_name' => '',
  'phone' => '',
  'document' => '',
  'email' => '',
  'address' => '',
  'status_id' => $firstStatus,
  'service_type_id' => $firstType,
  'request_text' => '',
  'lead_temperature' => 20,
  'responsible_user_id' => $uid,
  'next_contact_date' => crm_next_contact_default(),
  'pending_reason' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $isNew = true;
  try {
    crm_csrf_check((string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, ['save_pending', 'complete'], true)) {
      throw new RuntimeException('Ação inválida.');
    }

    $form = array_merge($form, [
      'person_id' => max(0, (int)($_POST['person_id'] ?? 0)),
      'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
      'phone' => trim((string)($_POST['phone'] ?? '')),
      'document' => trim((string)($_POST['document'] ?? '')),
      'email' => trim((string)($_POST['email'] ?? '')),
      'address' => trim((string)($_POST['address'] ?? '')),
      'status_id' => max(0, (int)($_POST['status_id'] ?? 0)),
      'service_type_id' => max(0, (int)($_POST['service_type_id'] ?? 0)),
      'request_text' => trim((string)($_POST['request_text'] ?? '')),
      'lead_temperature' => crm_temperature_clamp($_POST['lead_temperature'] ?? 20),
      'responsible_user_id' => max(0, (int)($_POST['responsible_user_id'] ?? 0)),
      'next_contact_date' => trim((string)($_POST['next_contact_date'] ?? '')),
      'pending_reason' => trim((string)($_POST['pending_reason'] ?? '')),
    ]);

    if ($form['request_text'] === '') {
      throw new RuntimeException('Informe a Solicitação do atendimento.');
    }
    if ($action === 'save_pending' && $form['pending_reason'] === '') {
      throw new RuntimeException('Informe o motivo da pendência para salvar como pendente.');
    }
    if ($form['next_contact_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['next_contact_date'])) {
      throw new RuntimeException('Data do próximo contato inválida.');
    }

    if ((int)$form['person_id'] > 0) {
      $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 LIMIT 1');
      $st->execute([$cid, (int)$form['person_id']]);
      if (!(int)$st->fetchColumn()) throw new RuntimeException('Cliente selecionado não pertence a esta empresa.');
    }
    if ((int)$form['status_id'] > 0) {
      $st = $pdo->prepare('SELECT id FROM crm_statuses WHERE company_id=? AND id=? AND active=1 LIMIT 1');
      $st->execute([$cid, (int)$form['status_id']]);
      if (!(int)$st->fetchColumn()) throw new RuntimeException('Status do atendimento inválido.');
    }
    if ((int)$form['service_type_id'] > 0) {
      $st = $pdo->prepare('SELECT id FROM crm_service_types WHERE company_id=? AND id=? AND active=1 LIMIT 1');
      $st->execute([$cid, (int)$form['service_type_id']]);
      if (!(int)$st->fetchColumn()) throw new RuntimeException('Tipo de atendimento inválido.');
    }
    $st = $pdo->prepare('SELECT id FROM users WHERE company_id=? AND id=? AND is_active=1 LIMIT 1');
    $st->execute([$cid, (int)$form['responsible_user_id']]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('Responsável inválido.');

    $state = $action === 'complete' ? 'CONCLUIDO' : 'PENDENTE';
    $pendingReason = $state === 'PENDENTE' ? (string)$form['pending_reason'] : null;
    $nextDate = (string)$form['next_contact_date'] !== '' ? (string)$form['next_contact_date'] : null;

    $pdo->beginTransaction();
    $sql = "INSERT INTO crm_attendances(company_id,person_id,customer_name,phone,document,email,address,status_id,service_type_id,request_text,lead_temperature,state,pending_reason,next_contact_date,responsible_user_id,created_by,closed_by,closed_at,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
    $pdo->prepare($sql)->execute([
      $cid,
      (int)$form['person_id'] ?: null,
      (string)$form['customer_name'] !== '' ? $form['customer_name'] : null,
      (string)$form['phone'] !== '' ? $form['phone'] : null,
      (string)$form['document'] !== '' ? $form['document'] : null,
      (string)$form['email'] !== '' ? $form['email'] : null,
      (string)$form['address'] !== '' ? $form['address'] : null,
      (int)$form['status_id'] ?: null,
      (int)$form['service_type_id'] ?: null,
      $form['request_text'],
      (int)$form['lead_temperature'],
      $state,
      $pendingReason,
      $nextDate,
      (int)$form['responsible_user_id'],
      $uid,
      $state === 'CONCLUIDO' ? $uid : null,
      $state === 'CONCLUIDO' ? date('Y-m-d H:i:s') : null,
    ]);
    $attendanceId = (int)$pdo->lastInsertId();
    $moveText = 'Atendimento criado pelo Atendimento Mobile.';
    $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,pending_reason,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')
      ->execute([$cid, $attendanceId, $moveText, (int)$form['lead_temperature'], $state, $pendingReason, $nextDate, (int)$form['responsible_user_id'], $uid]);
    $pdo->commit();

    header('Location: crm_atendimento_mobile_lista.php?saved=1');
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
    if (stripos($err, 'Solicitação') !== false || stripos($err, 'Status') !== false || stripos($err, 'Tipo de atendimento') !== false || stripos($err, 'Responsável') !== false) $errorStep = 2;
    elseif (stripos($err, 'pendência') !== false || stripos($err, 'próximo contato') !== false) $errorStep = 3;
  }
}

$kpiStmt = $pdo->prepare("SELECT SUM(CASE WHEN state='PENDENTE' THEN 1 ELSE 0 END) pendentes, SUM(CASE WHEN state='CONCLUIDO' THEN 1 ELSE 0 END) concluidos FROM crm_attendances WHERE company_id=?");
$kpiStmt->execute([$cid]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$q = trim((string)($_GET['q'] ?? ''));
$state = strtoupper(trim((string)($_GET['state'] ?? '')));
if (!in_array($state, ['', 'PENDENTE', 'CONCLUIDO'], true)) $state = '';
$statusId = max(0, (int)($_GET['status_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$nextFrom = trim((string)($_GET['next_from'] ?? ''));
$nextTo = trim((string)($_GET['next_to'] ?? ''));
$responsibleId = max(0, (int)($_GET['responsible_user_id'] ?? 0));
$showResults = false;
$rows = [];

crm_page_start('CRM - Atendimento Mobile', 'crm_atendimento_mobile');
?>
<style>
.crmm-shell{max-width:980px;margin:0 auto;padding-bottom:92px}.crmm-title{display:flex;align-items:center;gap:10px;margin-bottom:12px}.crmm-title h1{font-size:22px;margin:0}.crmm-kpis{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}.crmm-kpi{background:#fff;border:1px solid #dbe3ed;border-radius:12px;padding:10px 12px}.crmm-kpi small{display:block;color:#64748b;font-size:11px;font-weight:800}.crmm-kpi strong{font-size:23px}.crmm-filter-card{background:#fff;border:1px solid #dbe3ed;border-radius:14px;padding:12px;margin-bottom:12px}.crmm-filter-grid{display:grid;grid-template-columns:minmax(220px,1.6fr) repeat(2,minmax(130px,.7fr));gap:8px}.crmm-filter-grid .full{grid-column:1/-1}.crmm-filter-grid label,.crmm-panel label{display:block;font-size:11px;font-weight:900;color:#475569;margin-bottom:4px}.crmm-filter-grid input,.crmm-filter-grid select,.crmm-panel input,.crmm-panel select,.crmm-panel textarea{width:100%;box-sizing:border-box;border:1px solid #b9c6d5;border-radius:10px;background:#fff;padding:10px 11px;font-size:14px}.crmm-panel textarea{min-height:120px;resize:vertical}.crmm-filter-actions{display:flex;gap:8px;align-items:end}.crmm-list{display:grid;gap:8px}.crmm-att-card{display:grid;grid-template-columns:78px minmax(0,1fr) auto;gap:10px;align-items:center;background:#fff;border:1px solid #dbe3ed;border-radius:13px;padding:9px 10px;box-shadow:0 2px 8px rgba(15,23,42,.035)}.crmm-date{border-right:1px solid #e5edf6;padding-right:8px;text-align:center}.crmm-date strong{font-size:15px;display:block}.crmm-date small{font-size:10px;color:#64748b}.crmm-main{min-width:0}.crmm-name{font-size:14px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crmm-line{font-size:11px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}.crmm-request{font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px}.crmm-side{text-align:right;display:grid;gap:5px;justify-items:end}.crmm-mini-actions{display:flex;gap:5px}.crmm-mini-actions .crm-btn{padding:5px 7px;font-size:10px;border-radius:7px}.crmm-empty{padding:24px 14px;text-align:center;color:#64748b;background:#fff;border:1px dashed #cbd5e1;border-radius:12px}.crmm-fab{position:fixed;right:22px;bottom:22px;z-index:1100;border:0;border-radius:999px;background:#1769aa;color:#fff;text-decoration:none;padding:14px 18px;font-weight:900;box-shadow:0 12px 30px rgba(23,105,170,.32);display:inline-flex;align-items:center;gap:7px}.crmm-wizard{max-width:760px;margin:0 auto}.crmm-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:4px 0 12px}.crmm-step-pill{font-size:11px;font-weight:900;text-align:center;padding:8px 5px;border-radius:999px;background:#e7edf4;color:#64748b}.crmm-step-pill.active{background:#1769aa;color:#fff}.crmm-panel{display:none;background:#fff;border:1px solid #dbe3ed;border-radius:15px;padding:14px}.crmm-panel.active{display:block}.crmm-panel h2{font-size:18px;margin:0 0 4px}.crmm-panel p{font-size:12px;color:#64748b;margin:0 0 14px}.crmm-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.crmm-span2{grid-column:1/-1}.crmm-search-row{display:flex;gap:7px}.crmm-search-row input{flex:1}.crmm-floating-bar{position:fixed;left:50%;transform:translateX(-50%);bottom:14px;z-index:1100;width:min(740px,calc(100vw - 24px));background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1px solid #dbe3ed;border-radius:16px;padding:8px;box-shadow:0 14px 36px rgba(15,23,42,.18);display:flex;gap:7px;justify-content:flex-end}.crmm-floating-bar .crm-btn{flex:0 0 auto}.crmm-summary{background:#f8fbff;border:1px solid #dbe7f4;border-radius:11px;padding:10px;margin-bottom:10px;font-size:12px}.crmm-modal{width:min(720px,calc(100vw - 22px))}.crmm-modal .crm-modal-body{padding:11px}.crmm-modal-search{display:flex;gap:6px}.crmm-modal-search input{flex:1;border:1px solid #b9c6d5;border-radius:9px;padding:10px}.crmm-result{padding:10px;border-bottom:1px solid #edf2f7;cursor:pointer}.crmm-result:last-child{border-bottom:0}.crmm-result.sel,.crmm-result:active{background:#eef4ff}.crmm-result strong{display:block;font-size:13px}.crmm-result small{display:block;color:#64748b;font-size:10px;margin-top:2px}.crmm-saved{margin-bottom:10px}
@media(max-width:720px){.crm-wrap{padding:8px}.crmm-shell{padding-bottom:88px}.crmm-filter-grid{grid-template-columns:1fr 1fr}.crmm-filter-grid .search,.crmm-filter-grid .full{grid-column:1/-1}.crmm-filter-actions{grid-column:1/-1}.crmm-filter-actions .crm-btn{flex:1}.crmm-att-card{grid-template-columns:65px minmax(0,1fr);padding:8px}.crmm-side{grid-column:2;display:flex;align-items:center;justify-content:space-between;text-align:left}.crmm-mini-actions{margin-left:auto}.crmm-date strong{font-size:13px}.crmm-form-grid{grid-template-columns:1fr}.crmm-span2{grid-column:auto}.crmm-search-row{align-items:stretch}.crmm-search-row .crm-btn{padding:8px 9px}.crmm-floating-bar{justify-content:stretch}.crmm-floating-bar .crm-btn{flex:1;padding:10px 7px;font-size:12px}.crmm-fab{right:14px;bottom:14px;padding:13px 16px}}
</style>
<div class="crmm-shell">
<?php if (!$isNew): ?>
  <div class="crmm-title"><h1>Atendimento Mobile</h1><div class="crm-spacer"></div><a class="crm-btn sm" href="crm_atendimentos.php">Versão completa</a></div>
  <div class="crmm-kpis">
    <div class="crmm-kpi"><small>Pendentes</small><strong><?=number_format((int)($kpi['pendentes'] ?? 0),0,',','.')?></strong></div>
    <div class="crmm-kpi"><small>Fechados</small><strong><?=number_format((int)($kpi['concluidos'] ?? 0),0,',','.')?></strong></div>
  </div>
  <div class="crmm-filter-card">
    <form method="get" action="crm_atendimento_mobile_lista.php" class="crmm-filter-grid" autocomplete="off">
      <div class="search"><label>Pesquisar</label><input name="q" value="<?=crm_e($q)?>" placeholder="Nome, telefone, CNPJ, e-mail, endereço..."></div>
      <div><label>Status</label><select name="status_id"><option value="0">Todos</option><?php foreach($statuses as $s):?><option value="<?=(int)$s['id']?>" <?=$statusId===(int)$s['id']?'selected':''?>><?=crm_e($s['name'])?></option><?php endforeach;?></select></div>
      <div><label>Situação</label><select name="state"><option value="">Todas</option><option value="PENDENTE" <?=$state==='PENDENTE'?'selected':''?>>Pendente</option><option value="CONCLUIDO" <?=$state==='CONCLUIDO'?'selected':''?>>Concluído</option></select></div>
      <div><label>Atendimento inicial</label><input type="date" name="date_from" value="<?=crm_e($dateFrom)?>"></div>
      <div><label>Atendimento final</label><input type="date" name="date_to" value="<?=crm_e($dateTo)?>"></div>
      <div><label>Próximo contato inicial</label><input type="date" name="next_from" value="<?=crm_e($nextFrom)?>"></div>
      <div><label>Próximo contato final</label><input type="date" name="next_to" value="<?=crm_e($nextTo)?>"></div>
      <div class="full"><label>Responsável</label><select name="responsible_user_id"><option value="0">Todos</option><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>" <?=$responsibleId===(int)$u['id']?'selected':''?>><?=crm_e($u['name'])?></option><?php endforeach;?></select></div>
      <div class="crmm-filter-actions full"><button class="crm-btn primary" type="submit">🔎 Filtrar</button><a class="crm-btn" href="crm_atendimento_mobile.php">Limpar</a></div>
    </form>
  </div>
  <div class="crmm-empty">Informe os filtros desejados e toque em <strong>Filtrar</strong>. Os atendimentos serão mostrados em uma tela separada.</div>
  <a class="crmm-fab" href="crm_atendimento_mobile.php?new=1" aria-label="Novo atendimento">＋ Novo atendimento</a>
<?php else: ?>
  <div class="crmm-wizard">
    <div class="crmm-title"><a class="crm-btn sm" href="crm_atendimento_mobile.php">← Voltar</a><h1>Novo Atendimento</h1></div>
    <?php if ($err !== ''): ?><div class="crm-alert error"><?=crm_e($err)?></div><?php endif; ?>
    <div class="crmm-steps">
      <div class="crmm-step-pill active" data-step-pill="1">1 · Lead / Cliente</div>
      <div class="crmm-step-pill" data-step-pill="2">2 · Atendimento</div>
      <div class="crmm-step-pill" data-step-pill="3">3 · Pendência</div>
    </div>
    <form method="post" id="crmmForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=crm_e(crm_csrf_token())?>">
      <input type="hidden" id="crmmPersonId" name="person_id" value="<?=(int)$form['person_id']?>">

      <section class="crmm-panel active" data-step-panel="1">
        <h2>Lead / Cliente</h2><p>Selecione um cliente cadastrado ou informe somente os dados que você tiver. Nenhum dado avulso é obrigatório.</p>
        <div class="crmm-form-grid">
          <div class="crmm-span2"><label>Nome / Cliente</label><div class="crmm-search-row"><input id="crmmCustomerName" name="customer_name" value="<?=crm_e($form['customer_name'])?>" placeholder="Nome do lead ou cliente" autofocus><button class="crm-btn primary" type="button" id="crmmPeopleOpen">🔎 Buscar</button></div></div>
          <div><label>Telefone</label><input id="crmmPhone" name="phone" value="<?=crm_e($form['phone'])?>" inputmode="tel"></div>
          <div><label>CNPJ / CPF</label><input id="crmmDocument" name="document" value="<?=crm_e($form['document'])?>"></div>
          <div><label>E-mail</label><input id="crmmEmail" type="email" name="email" value="<?=crm_e($form['email'])?>"></div>
          <div><label>Endereço</label><input id="crmmAddress" name="address" value="<?=crm_e($form['address'])?>"></div>
          <div class="crmm-span2"><button class="crm-btn sm" type="button" id="crmmPeopleClear">Limpar vínculo com cadastro</button></div>
        </div>
      </section>

      <section class="crmm-panel" data-step-panel="2">
        <h2>Atendimento</h2><p>Registre o tipo, status, responsável e a solicitação recebida.</p>
        <div class="crmm-form-grid">
          <div><label>Status do atendimento</label><select name="status_id"><option value="0">Sem status</option><?php foreach($statuses as $s):?><option value="<?=(int)$s['id']?>" <?=(int)$form['status_id']===(int)$s['id']?'selected':''?>><?=crm_e($s['name'])?></option><?php endforeach;?></select></div>
          <div><label>Tipo de atendimento</label><select name="service_type_id"><option value="0">Não informado</option><?php foreach($types as $t):?><option value="<?=(int)$t['id']?>" <?=(int)$form['service_type_id']===(int)$t['id']?'selected':''?>><?=crm_e($t['name'])?></option><?php endforeach;?></select></div>
          <div class="crmm-span2"><label>Responsável</label><select name="responsible_user_id"><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>" <?=(int)$form['responsible_user_id']===(int)$u['id']?'selected':''?>><?=crm_e($u['name'])?></option><?php endforeach;?></select></div>
          <div class="crmm-span2"><label>Temperatura do Lead</label><?php crm_temperature_control('lead_temperature',$form['lead_temperature']??20,'crmmLeadTemperature'); ?></div>
          <div class="crmm-span2"><label>Solicitação *</label><textarea id="crmmRequest" name="request_text" required placeholder="Descreva o que o cliente/lead solicitou..."><?=crm_e($form['request_text'])?></textarea></div>
        </div>
      </section>

      <section class="crmm-panel" data-step-panel="3">
        <h2>Pendência / Finalização</h2><p>Defina o próximo contato e, se ficar pendente, registre o motivo.</p>
        <div class="crmm-summary"><strong id="crmmSummaryName"><?=crm_e($form['customer_name'] ?: 'Contato sem nome')?></strong><div id="crmmSummaryRequest"><?=crm_e($form['request_text'] ?: 'Solicitação ainda não informada')?></div></div>
        <div class="crmm-form-grid">
          <div><label>Data do próximo contato</label><input type="date" name="next_contact_date" value="<?=crm_e($form['next_contact_date'])?>"></div>
          <div><label>Motivo da pendência</label><input name="pending_reason" value="<?=crm_e($form['pending_reason'])?>" placeholder="Obrigatório ao salvar pendente"></div>
        </div>
      </section>

      <div class="crmm-floating-bar">
        <a class="crm-btn" href="crm_atendimento_mobile.php" id="crmmCancel">Cancelar</a>
        <button class="crm-btn" type="button" id="crmmBack" hidden>← Voltar</button>
        <button class="crm-btn primary" type="button" id="crmmNext">Avançar →</button>
        <button class="crm-btn primary" type="submit" name="action" value="save_pending" id="crmmPending" hidden>Salvar pendência</button>
        <button class="crm-btn success" type="submit" name="action" value="complete" id="crmmComplete" hidden>Concluir</button>
      </div>
    </form>
  </div>

  <div id="crmmPeopleModal" class="crm-modal-bg" aria-hidden="true"><div class="crm-modal crmm-modal"><div class="crm-modal-head"><strong>Buscar cliente</strong><div class="crm-spacer"></div><button class="crm-btn sm" type="button" id="crmmPeopleClose">Fechar</button></div><div class="crm-modal-body"><div class="crmm-modal-search"><input id="crmmPeopleSearch" placeholder="Nome, telefone, CNPJ, e-mail ou endereço" autocomplete="off"><button class="crm-btn primary" type="button" id="crmmPeopleSearchBtn">Pesquisar</button></div><div class="crm-muted" style="font-size:11px;margin:7px 0">Digite e pressione Enter. ↑/↓ navegam e Enter seleciona.</div><div id="crmmPeopleResults" class="crm-results"><div class="crmm-result crm-muted">Digite a pesquisa e pressione Enter.</div></div></div></div></div>
<?php endif; ?>
</div>
<?php if ($isNew): ?>
<script>window.nexaMaskDoc=window.nexaMaskDoc||function(v){v=String(v==null?'':v);var d=v.replace(/\D/g,'');if(d.length<7)return v;var i=0,n=d.length;return v.replace(/\d/g,function(c){var k=i++;return(k<3||k>=n-3)?c:'*'});};</script>
<script>
(()=>{
  let step=<?=json_encode($err!==''?$errorStep:1)?>;
  const panels=[...document.querySelectorAll('[data-step-panel]')],pills=[...document.querySelectorAll('[data-step-pill]')],next=document.getElementById('crmmNext'),back=document.getElementById('crmmBack'),pending=document.getElementById('crmmPending'),complete=document.getElementById('crmmComplete');
  const show=n=>{step=Math.max(1,Math.min(3,n));panels.forEach(x=>x.classList.toggle('active',Number(x.dataset.stepPanel)===step));pills.forEach(x=>x.classList.toggle('active',Number(x.dataset.stepPill)===step));back.hidden=step===1;next.hidden=step===3;pending.hidden=step!==3;complete.hidden=step!==3;if(step===1)setTimeout(()=>document.getElementById('crmmCustomerName')?.focus(),30);if(step===2)setTimeout(()=>document.getElementById('crmmRequest')?.focus(),30);if(step===3){document.getElementById('crmmSummaryName').textContent=document.getElementById('crmmCustomerName').value.trim()||'Contato sem nome';document.getElementById('crmmSummaryRequest').textContent=document.getElementById('crmmRequest').value.trim()||'Solicitação ainda não informada';}};
  next.addEventListener('click',()=>{if(step===2&&!document.getElementById('crmmRequest').value.trim()){document.getElementById('crmmRequest').focus();return;}show(step+1)});back.addEventListener('click',()=>show(step-1));show(step);

  const modal=document.getElementById('crmmPeopleModal'),searchInput=document.getElementById('crmmPeopleSearch'),results=document.getElementById('crmmPeopleResults'),searchBtn=document.getElementById('crmmPeopleSearchBtn');let rows=[],idx=-1,last='__dirty__';
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  function open(){modal.classList.add('open');modal.setAttribute('aria-hidden','false');searchInput.value='';rows=[];idx=-1;last='__dirty__';results.innerHTML='<div class="crmm-result crm-muted">Digite a pesquisa e pressione Enter.</div>';setTimeout(()=>searchInput.focus(),30)}
  function close(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true')}
  function mark(){results.querySelectorAll('[data-i]').forEach((el,i)=>el.classList.toggle('sel',i===idx));results.querySelector('[data-i="'+idx+'"]')?.scrollIntoView({block:'nearest'})}
  function choose(i){const r=rows[i];if(!r)return;document.getElementById('crmmPersonId').value=r.id||'';document.getElementById('crmmCustomerName').value=r.name||r.fantasy_name||'';document.getElementById('crmmPhone').value=r.whatsapp||r.phone||'';document.getElementById('crmmDocument').value=r.document||'';document.getElementById('crmmEmail').value=r.email||'';document.getElementById('crmmAddress').value=r.address_full||'';close();setTimeout(()=>document.getElementById('crmmCustomerName').focus(),20)}
  function draw(data){rows=Array.isArray(data)?data:[];idx=rows.length?0:-1;results.innerHTML=rows.map((r,i)=>`<div class="crmm-result ${i===0?'sel':''}" data-i="${i}"><strong>${esc(r.name||r.fantasy_name||'')}</strong><small>${esc([(window.nexaMaskDoc||String)(r.document||''),r.whatsapp||r.phone,r.email,r.address_full].filter(Boolean).join(' · '))}</small></div>`).join('')||'<div class="crmm-result crm-muted">Nenhum cliente encontrado.</div>';results.querySelectorAll('[data-i]').forEach(el=>el.addEventListener('click',()=>choose(Number(el.dataset.i))))}
  async function search(){searchBtn.disabled=true;results.innerHTML='<div class="crmm-result crm-muted">Pesquisando...</div>';try{const r=await fetch('crm_ajax.php?action=people&q='+encodeURIComponent(searchInput.value.trim()),{headers:{Accept:'application/json'}}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Falha na pesquisa');draw(j.rows||[]);last=searchInput.value}catch(e){rows=[];idx=-1;results.innerHTML='<div class="crmm-result crm-muted">'+esc(e.message||'Falha na pesquisa')+'</div>'}finally{searchBtn.disabled=false;searchInput.focus()}}
  document.getElementById('crmmPeopleOpen').addEventListener('click',open);document.getElementById('crmmPeopleClose').addEventListener('click',close);searchBtn.addEventListener('click',search);document.getElementById('crmmPeopleClear').addEventListener('click',()=>{document.getElementById('crmmPersonId').value='';document.getElementById('crmmCustomerName').focus()});searchInput.addEventListener('input',()=>{last='__dirty__';rows=[];idx=-1});searchInput.addEventListener('keydown',e=>{if(e.key==='ArrowDown'&&rows.length){e.preventDefault();idx=Math.min(rows.length-1,idx+1);mark()}else if(e.key==='ArrowUp'&&rows.length){e.preventDefault();idx=Math.max(0,idx-1);mark()}else if(e.key==='Enter'){e.preventDefault();if(rows.length&&idx>=0&&last===searchInput.value)choose(idx);else search()}});document.addEventListener('keydown',e=>{if(e.key==='F8'){e.preventDefault();open()}else if(e.key==='Escape'&&modal.classList.contains('open')){e.preventDefault();close()}});modal.addEventListener('mousedown',e=>{if(e.target===modal)close()});
})();
</script>
<?php endif; ?>
<?php crm_page_end(); ?>
