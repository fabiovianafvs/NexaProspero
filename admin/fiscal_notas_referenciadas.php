<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/gestao_module.php';

$cid = (int)COMPANY_ID;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_recipient_customer') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $payload = [
      'name' => trim((string)($_POST['name'] ?? '')),
      'fantasy_name' => trim((string)($_POST['fantasy_name'] ?? '')),
      'document' => (string)($_POST['document'] ?? ''),
      'person_type' => (string)($_POST['person_type'] ?? 'FISICA'),
      'email' => trim((string)($_POST['email'] ?? '')),
      'phone' => (string)($_POST['phone'] ?? ''),
      'whatsapp' => (string)($_POST['phone'] ?? ''),
      'zip_code' => (string)($_POST['zip_code'] ?? ''),
      'address' => trim((string)($_POST['address'] ?? '')),
      'number' => trim((string)($_POST['number'] ?? '')),
      'complement' => trim((string)($_POST['complement'] ?? '')),
      'district' => trim((string)($_POST['district'] ?? '')),
      'city' => trim((string)($_POST['city'] ?? '')),
      'state' => trim((string)($_POST['state'] ?? '')),
    ];
    $result = gestao_create_customer($pdo, $cid, $payload);
    $personId = (int)($result['customer']['id'] ?? 0);
    if ($personId <= 0) throw new RuntimeException('Não foi possível identificar o cliente cadastrado.');

    $extraSets = [];
    $extraParams = [];
    if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'finance_people', 'state_registration')) {
      $extraSets[] = 'state_registration = ?';
      $extraParams[] = trim((string)($_POST['state_registration'] ?? '')) ?: null;
    }
    if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'finance_people', 'city_ibge')) {
      $extraSets[] = 'city_ibge = ?';
      $extraParams[] = preg_replace('/\D+/', '', (string)($_POST['city_ibge'] ?? '')) ?: null;
    }
    if ($extraSets) {
      $extraParams[] = $cid;
      $extraParams[] = $personId;
      $pdo->prepare('UPDATE finance_people SET ' . implode(', ', $extraSets) . ', updated_at = NOW() WHERE company_id = ? AND id = ?')->execute($extraParams);
    }
    $customer = finance_query_one($pdo, 'SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$cid, $personId]) ?: $result['customer'];
    echo json_encode(['ok' => true, 'customer' => $customer], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}
$q = trim((string)($_GET['q'] ?? ''));
$today = date('Y-m-d');
$dtIni = array_key_exists('dt_ini', $_GET) ? trim((string)$_GET['dt_ini']) : $today;
$dtFim = array_key_exists('dt_fim', $_GET) ? trim((string)$_GET['dt_fim']) : $today;
if ($dtIni !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtIni)) $dtIni = $today;
if ($dtFim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtFim)) $dtFim = $today;
if ($dtIni !== '' && $dtFim !== '' && $dtIni > $dtFim) [$dtIni, $dtFim] = [$dtFim, $dtIni];
$err = '';
$rows = [];
$referencedMap = [];
$devolutionMap = [];
$clients = [];
try {
  fiscal_nfce_ensure_schema($pdo);
  $rows = fiscal_nfe_referenced_nfce_candidates($pdo, $cid, $q, $dtIni, $dtFim, 250);
  $docIds = array_map(static fn($r) => (int)($r['id'] ?? 0), $rows);
  if (function_exists('fiscal_nfe_referenced_nfce_status_map')) $referencedMap = fiscal_nfe_referenced_nfce_status_map($pdo, $cid, $docIds);
  if (function_exists('fiscal_devolution_status_for_source_documents')) $devolutionMap = fiscal_devolution_status_for_source_documents($pdo, $cid, $docIds);
  $clients = fiscal_finance_people_options($pdo, $cid, 'customer', '', 500);
} catch (Throwable $e) { $err = $e->getMessage(); }

function ref_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ref_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function ref_date($v): string { $ts = strtotime((string)$v); return $ts ? date('d/m/Y H:i', $ts) : '-'; }

fiscal_admin_start('Emitir Notas Referenciadas', 'fiscal_documentos');
fiscal_page_header('Emitir Notas Referenciadas', 'Selecione uma ou mais NFC-e autorizadas do mesmo cliente. Antes de gerar a pré-NF-e, confira ou informe os dados do destinatário.', [
  ['label'=>'Documentos fiscais', 'href'=>'fiscal_documentos.php', 'class'=>'btn'],
  ['label'=>'Pedidos', 'href'=>'pedidos.php', 'class'=>'btn'],
]);
fiscal_tabs('fiscal_documentos');
fiscal_flash();
if ($err !== '') echo '<div class="msg err">' . ref_h($err) . '</div>';
?>
<style>
  .ref-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.ref-step{border:1px solid #dbe4f0;border-radius:18px;padding:13px;background:#fff}.ref-step b{display:block;color:var(--admin-primary);margin-bottom:5px}.ref-step span{color:#64748b;font-size:13px}.ref-table{width:100%;border-collapse:collapse;min-width:980px}.ref-table th,.ref-table td{padding:10px;border-bottom:1px solid #e5edf6;vertical-align:middle}.ref-table th{background:#f8fbff;color:#334155;font-size:12px}.ref-key{display:block;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;font-size:12px}.ref-check{width:20px;height:20px}.ref-check:disabled{opacity:.35;cursor:not-allowed}.ref-status-stack{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.ref-row-disabled{opacity:.78;background:#fafafa}.ref-row-disabled td{color:#64748b}.ref-list-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.ref-modal{position:fixed;inset:0;z-index:380;display:none;align-items:flex-start;justify-content:center;padding:24px;background:rgba(15,23,42,.6);backdrop-filter:blur(3px);overflow:auto}.ref-modal.open{display:flex}.ref-modal-dialog{width:min(980px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:26px;padding:18px;box-shadow:0 28px 80px rgba(2,6,23,.38)}.ref-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.ref-modal-head h2{margin:0;color:var(--admin-primary)}.ref-modal-close{width:42px;height:42px;border:1px solid #dbe4f0;border-radius:14px;background:#fff;font-size:22px;cursor:pointer}.ref-recipient-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ref-recipient-grid label{display:grid;gap:5px;color:#334155;font-size:12px;font-weight:850}.ref-recipient-grid input,.ref-recipient-grid select{width:100%;padding:10px;border:1px solid #dbe4f0;border-radius:12px;font:inherit;background:#fff}.ref-recipient-grid .span-2{grid-column:span 2}.ref-recipient-grid .span-3{grid-column:span 3}.ref-selection-summary{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:16px;padding:11px 13px;margin-bottom:12px;font-size:13px}.ref-modal-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding-top:13px;margin-top:14px}@media(max-width:900px){.ref-flow{grid-template-columns:1fr}.ref-table{min-width:900px}.ref-recipient-grid{grid-template-columns:1fr 1fr}.ref-recipient-grid .span-3{grid-column:span 2}.fiscal-list-head{align-items:stretch;flex-direction:column}.ref-list-actions{justify-content:flex-start}}@media(max-width:620px){.ref-recipient-grid{grid-template-columns:1fr}.ref-recipient-grid .span-2,.ref-recipient-grid .span-3{grid-column:span 1}.ref-modal{padding:8px}.ref-modal-dialog{border-radius:20px}}
.ref-client-picker{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:end;margin-bottom:12px}.ref-client-picker input{width:100%;padding:10px;border:1px solid #dbe4f0;border-radius:12px;background:#f8fafc}.ref-client-results{display:grid;gap:8px;max-height:430px;overflow:auto}.ref-client-result{width:100%;display:flex;justify-content:space-between;gap:12px;align-items:center;text-align:left;border:1px solid #dbe4f0;border-radius:16px;background:#fff;padding:12px;cursor:pointer}.ref-client-result:hover{border-color:#2563eb;background:#eff6ff}.ref-client-result strong,.ref-client-result small{display:block}.ref-client-result small{color:#64748b;margin-top:3px}.ref-client-result b{color:#1d4ed8}.ref-search-toolbar{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;align-items:end;margin-bottom:10px}.ref-inline-note{margin:0 0 10px;color:#64748b;font-size:12px}.ref-save-status{min-height:20px;color:#b91c1c;font-size:12px;font-weight:800}@media(max-width:700px){.ref-client-picker,.ref-search-toolbar{grid-template-columns:1fr}.ref-client-picker .btn,.ref-search-toolbar .btn{width:100%}}
</style>
<div class="ref-flow">
  <div class="ref-step"><b>1. Filtre</b><span>A tela abre somente com as NFC-e do dia atual. Altere o período quando necessário.</span></div>
  <div class="ref-step"><b>2. Selecione</b><span>Marque NFC-e do mesmo cliente/destinatário.</span></div>
  <div class="ref-step"><b>3. Confira o cliente</b><span>Revise CPF/CNPJ, nome e endereço antes de gerar.</span></div>
  <div class="ref-step"><b>4. Confira e transmita</b><span>Revise itens, CFOP e tributação na pré-NF-e.</span></div>
</div>

<div class="fiscal-card">
  <form method="get" class="fiscal-form">
    <div class="fiscal-filter-actions">
      <label style="flex:1;min-width:260px">Cliente, pedido, número ou chave
        <input name="q" value="<?= ref_h($q) ?>" placeholder="Ex.: cliente, CPF/CNPJ, nº da NFC-e ou pedido">
      </label>
      <label>De<input type="date" name="dt_ini" value="<?= ref_h($dtIni) ?>"></label>
      <label>Até<input type="date" name="dt_fim" value="<?= ref_h($dtFim) ?>"></label>
      <button class="btn btn-primary" type="submit">Pesquisar</button>
      <a class="btn" href="fiscal_notas_referenciadas.php">Hoje</a>
    </div>
  </form>
</div>

<form method="post" action="fiscal_nfce_acao.php" id="refForm">
  <input type="hidden" name="return_to" value="fiscal_notas_referenciadas.php">
  <div class="fiscal-card">
    <div class="fiscal-list-head">
      <h2>NFC-e autorizadas disponíveis</h2>
      <div class="ref-list-actions"><button class="btn btn-primary" id="openRecipientModal" type="button">Gerar pré-NF-e para conferência</button><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div>
    </div>
    <div class="table-wrap"><table class="ref-table">
      <thead><tr><th style="width:42px"><input type="checkbox" id="checkAll"></th><th>NFC-e</th><th>Cliente</th><th>Emissão</th><th>Total</th><th>Origem</th><th>Status fiscal</th><th>Controle</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="8"><div class="fiscal-empty-state">Nenhuma NFC-e autorizada encontrada para o período e filtros informados.</div></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $docId = (int)($r['id'] ?? 0);
            $refInfo = $referencedMap[$docId] ?? null;
            $devInfo = $devolutionMap[$docId] ?? null;
            $isAlreadyReferenced = (bool)$refInfo;
            $controlBadges = [];
            if ($refInfo) $controlBadges[] = '<span class="fiscal-badge" title="' . ref_h($refInfo['message'] ?? '') . '">' . ref_h($refInfo['label'] ?? 'Referenciada') . '</span>';
            if ($devInfo) $controlBadges[] = '<span class="fiscal-badge off" title="' . ref_h('Devolvido R$ ' . number_format((float)($devInfo['returned_total'] ?? 0), 2, ',', '.') . ' de R$ ' . number_format((float)($devInfo['original_total'] ?? 0), 2, ',', '.')) . '">' . ref_h($devInfo['label'] ?? 'Devolução') . '</span>';
          ?>
          <tr class="<?= $isAlreadyReferenced ? 'ref-row-disabled' : '' ?>">
            <td><input class="ref-check" type="checkbox" name="doc_ids[]" value="<?= $docId ?>"
              data-doc="<?= ref_h($r['participant_document'] ?? '') ?>" data-name="<?= ref_h($r['participant_name'] ?? '') ?>"
              data-person-id="<?= (int)($r['participant_person_id'] ?? 0) ?>" data-ie="<?= ref_h($r['participant_ie'] ?? '') ?>"
              data-email="<?= ref_h($r['participant_email'] ?? '') ?>" data-phone="<?= ref_h($r['participant_phone'] ?? '') ?>"
              data-zip="<?= ref_h($r['participant_zip'] ?? '') ?>" data-address="<?= ref_h($r['participant_address'] ?? '') ?>"
              data-number="<?= ref_h($r['participant_number'] ?? '') ?>" data-complement="<?= ref_h($r['participant_complement'] ?? '') ?>"
              data-district="<?= ref_h($r['participant_district'] ?? '') ?>" data-city="<?= ref_h($r['participant_city'] ?? '') ?>"
              data-city-ibge="<?= ref_h($r['participant_city_ibge'] ?? '') ?>" data-state="<?= ref_h($r['participant_state'] ?? '') ?>"
              data-total="<?= ref_h((string)($r['total_document'] ?? 0)) ?>" data-label="<?= ref_h('NFC-e ' . ($r['series'] ?? '') . '/' . ($r['number'] ?? '')) ?>"
              <?= $isAlreadyReferenced ? 'disabled' : '' ?>></td>
            <td><b>65 <?= ref_h(($r['series'] ?? '') . '/' . ($r['number'] ?? '')) ?></b><span class="ref-key" title="<?= ref_h($r['access_key'] ?? '') ?>"><?= ref_h($r['access_key'] ?? '') ?></span></td>
            <td><b><?= ref_h($r['participant_name'] ?: 'Consumidor não identificado') ?></b><br><span class="fiscal-muted"><?= ref_h($r['participant_document'] ?? '') ?></span></td>
            <td><?= ref_h(ref_date($r['issue_datetime'] ?? '')) ?></td>
            <td><b><?= ref_money($r['total_document'] ?? 0) ?></b></td>
            <td><?= ref_h(($r['source_type'] ?? '-') . ' #' . ($r['source_id'] ?? '-')) ?></td>
            <td><span class="fiscal-badge">Autorizada</span></td>
            <td><div class="ref-status-stack"><?= $controlBadges ? implode('', $controlBadges) : '<span class="fiscal-muted">Disponível</span>' ?></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="ref-modal" id="recipientModal" role="dialog" aria-modal="true" aria-labelledby="recipientModalTitle">
    <div class="ref-modal-dialog">
      <div class="ref-modal-head"><div><h2 id="recipientModalTitle">Conferir destinatário da NF-e</h2><p class="fiscal-muted" style="margin:5px 0 0">Os dados abaixo serão usados na pré-NF-e. Corrija ou complete antes de continuar.</p></div><button type="button" class="ref-modal-close" id="closeRecipientModal" aria-label="Fechar">×</button></div>
      <div class="ref-selection-summary" id="refSelectionSummary"></div>
      <input type="hidden" name="destination_person_id" id="destination_person_id">
      <div class="ref-client-picker">
        <label>Cliente cadastrado<input type="text" id="recipientCustomerPick" placeholder="Clique em pesquisar para selecionar um cliente" readonly></label>
        <button type="button" class="btn" id="openRecipientCustomerSearch">🔎 Pesquisar</button>
        <button type="button" class="btn" id="openRecipientCustomerCreate">+ Cadastrar cliente</button>
      </div>
      <div class="ref-recipient-grid">
        <label class="span-2">Nome/Razão social<input name="destination_name" id="destination_name" required></label>
        <label>CPF/CNPJ<input name="destination_document" id="destination_document" required inputmode="numeric"></label>
        <label>IE<input name="destination_ie" id="destination_ie"></label>
        <label>E-mail<input name="destination_email" id="destination_email" type="email"></label>
        <label>Telefone<input name="destination_phone" id="destination_phone"></label>
        <label>CEP<input name="destination_zip" id="destination_zip" required inputmode="numeric"></label>
        <label>UF<input name="destination_state" id="destination_state" maxlength="2" required></label>
        <label class="span-2">Endereço<input name="destination_address" id="destination_address" required></label>
        <label>Número<input name="destination_number" id="destination_number" required></label>
        <label>Complemento<input name="destination_complement" id="destination_complement"></label>
        <label>Bairro<input name="destination_district" id="destination_district" required></label>
        <label>Cidade<input name="destination_city" id="destination_city" required></label>
        <label>Cód. IBGE cidade<input name="destination_city_ibge" id="destination_city_ibge" required inputmode="numeric"></label>
      </div>
      <div class="ref-modal-actions"><button class="btn" type="button" id="cancelRecipientModal">Cancelar</button><button class="btn btn-primary" name="action" value="gerar_nfe_referenciada" type="submit">Gerar pré-NF-e</button></div>
    </div>
  </div>
</form>

<div class="ref-modal" id="recipientCustomerSearchModal" role="dialog" aria-modal="true" aria-labelledby="recipientCustomerSearchTitle">
  <div class="ref-modal-dialog">
    <div class="ref-modal-head"><div><h2 id="recipientCustomerSearchTitle">Pesquisar cliente</h2><p class="fiscal-muted" style="margin:5px 0 0">Busque por nome, CPF/CNPJ, telefone ou cidade e selecione o destinatário.</p></div><button type="button" class="ref-modal-close" id="closeRecipientCustomerSearch" aria-label="Fechar">×</button></div>
    <div class="ref-search-toolbar"><label>Pesquisar cliente<input type="search" id="recipientCustomerSearchInput" placeholder="Nome, CPF/CNPJ, telefone ou cidade" autocomplete="off"></label><button type="button" class="btn btn-primary" id="createCustomerFromSearch">+ Novo cliente</button></div>
    <div class="ref-client-results" id="recipientCustomerSearchResults"></div>
  </div>
</div>

<div class="ref-modal" id="recipientCustomerCreateModal" role="dialog" aria-modal="true" aria-labelledby="recipientCustomerCreateTitle">
  <div class="ref-modal-dialog">
    <div class="ref-modal-head"><div><h2 id="recipientCustomerCreateTitle">Cadastrar cliente</h2><p class="fiscal-muted" style="margin:5px 0 0">Cadastre o destinatário sem sair da emissão da nota referenciada.</p></div><button type="button" class="ref-modal-close" id="closeRecipientCustomerCreate" aria-label="Fechar">×</button></div>
    <form id="recipientCustomerCreateForm">
      <input type="hidden" name="action" value="create_recipient_customer">
      <div class="ref-recipient-grid">
        <label>Tipo<select name="person_type" id="new_customer_person_type"><option value="FISICA">Pessoa física</option><option value="JURIDICA">Pessoa jurídica</option></select></label>
        <label class="span-2">Nome/Razão social<input name="name" id="new_customer_name" required></label>
        <label>CPF/CNPJ<input name="document" id="new_customer_document" inputmode="numeric"></label>
        <label class="span-2">Nome fantasia<input name="fantasy_name" id="new_customer_fantasy_name"></label>
        <label>IE<input name="state_registration" id="new_customer_ie"></label>
        <label>E-mail<input name="email" id="new_customer_email" type="email"></label>
        <label>Telefone<input name="phone" id="new_customer_phone"></label>
        <label>CEP<input name="zip_code" id="new_customer_zip" inputmode="numeric"></label>
        <label>UF<input name="state" id="new_customer_state" maxlength="2"></label>
        <label class="span-2">Endereço<input name="address" id="new_customer_address"></label>
        <label>Número<input name="number" id="new_customer_number"></label>
        <label>Complemento<input name="complement" id="new_customer_complement"></label>
        <label>Bairro<input name="district" id="new_customer_district"></label>
        <label>Cidade<input name="city" id="new_customer_city"></label>
        <label>Cód. IBGE cidade<input name="city_ibge" id="new_customer_city_ibge" inputmode="numeric"></label>
      </div>
      <p class="ref-inline-note">Ao informar um CEP válido, endereço, bairro, cidade, UF e código IBGE serão preenchidos automaticamente.</p>
      <div class="ref-save-status" id="recipientCustomerSaveStatus"></div>
      <div class="ref-modal-actions"><button class="btn" type="button" id="cancelRecipientCustomerCreate">Cancelar</button><button class="btn btn-primary" type="submit">Salvar e selecionar</button></div>
    </form>
  </div>
</div>
<script>
(function(){
  let clients = <?= json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?>;
  const all = document.getElementById('checkAll');
  const form = document.getElementById('refForm');
  const modal = document.getElementById('recipientModal');
  const clientSearchModal = document.getElementById('recipientCustomerSearchModal');
  const clientCreateModal = document.getElementById('recipientCustomerCreateModal');
  const digits = value => String(value || '').replace(/\D+/g,'');
  const normalize = value => String(value || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const money = value => 'R$ ' + Number(value || 0).toFixed(2).replace('.', ',');
  const setValue = (id, value) => { const el=document.getElementById(id); if(el) el.value=value || ''; };
  function cleanAddress(address, district){
    let value=String(address||'').replace(/\s+/g,' ').trim();
    const d=String(district||'').trim();
    if(d){ const escaped=d.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); value=value.replace(new RegExp('\\s*[,;\\-]?\\s*Bairro\\s*:\\s*'+escaped+'\\s*$','i'),''); }
    return value.replace(/\s*[,;\-]?\s*Bairro\s*:\s*[^,;]+$/i,'').replace(/^[,;\-\s]+|[,;\-\s]+$/g,'').trim();
  }
  const selected = () => Array.from(document.querySelectorAll('.ref-check:checked:not(:disabled)'));
  function customerMatches(c, term){
    const q=normalize(term); const d=digits(term);
    if(!q && !d) return true;
    const text=normalize([c.name,c.fantasy_name,c.document,c.phone,c.whatsapp,c.email,c.city,c.state,c.district].join(' '));
    const allDigits=digits([c.document,c.phone,c.whatsapp].join(' '));
    return (q && text.includes(q)) || (d && allDigits.includes(d));
  }
  function renderCustomerResults(term=''){
    const box=document.getElementById('recipientCustomerSearchResults'); if(!box) return;
    const rows=clients.filter(c=>customerMatches(c,term)).sort((a,b)=>String(a.name||'').localeCompare(String(b.name||''),'pt-BR')).slice(0,100);
    if(!rows.length){ box.innerHTML='<div class="fiscal-empty-state">Nenhum cliente encontrado. Use “Novo cliente” para cadastrar.</div>'; return; }
    box.innerHTML=rows.map(c=>{ const contact=c.whatsapp||c.phone||''; const detail=[c.document?'CPF/CNPJ: '+c.document:'',contact?'Contato: '+contact:'',[c.city||'',c.state||''].filter(Boolean).join('/')].filter(Boolean).join(' • '); return `<button type="button" class="ref-client-result" data-client-id="${Number(c.id||0)}"><span><strong>${escapeHtml(c.name||'Cliente')}</strong><small>${escapeHtml(detail||'Cliente cadastrado')}</small></span><b>Selecionar</b></button>`; }).join('');
  }
  function selectCustomer(c){
    if(!c) return;
    const district=c.district||c.bairro||'';
    setValue('destination_person_id',c.id||'');
    setValue('destination_name',c.name||'');
    setValue('destination_document',c.document||'');
    setValue('destination_ie',c.state_registration||c.ie||'');
    setValue('destination_email',c.email||'');
    setValue('destination_phone',c.whatsapp||c.phone||'');
    setValue('destination_zip',c.zip_code||c.cep||'');
    setValue('destination_address',cleanAddress(c.address||'',district));
    setValue('destination_number',c.number||'');
    setValue('destination_complement',c.complement||'');
    setValue('destination_district',district);
    setValue('destination_city',c.city||c.cidade||'');
    setValue('destination_city_ibge',c.city_ibge||c.codigo_ibge||'');
    setValue('destination_state',c.state||c.uf||'');
    setValue('recipientCustomerPick',(c.name||'Cliente')+(c.document?' · '+c.document:''));
    clientSearchModal?.classList.remove('open');
    clientCreateModal?.classList.remove('open');
  }
  function openCustomerSearch(){
    const input=document.getElementById('recipientCustomerSearchInput');
    if(input) input.value=''; renderCustomerResults(''); clientSearchModal?.classList.add('open');
    setTimeout(()=>input?.focus(),60);
  }
  function prefillCustomerCreate(){
    const formEl=document.getElementById('recipientCustomerCreateForm'); formEl?.reset();
    const doc=digits(document.getElementById('destination_document')?.value||'');
    setValue('new_customer_person_type',doc.length===14?'JURIDICA':'FISICA');
    setValue('new_customer_name',document.getElementById('destination_name')?.value||'');
    setValue('new_customer_document',doc);
    setValue('new_customer_ie',document.getElementById('destination_ie')?.value||'');
    setValue('new_customer_email',document.getElementById('destination_email')?.value||'');
    setValue('new_customer_phone',document.getElementById('destination_phone')?.value||'');
    setValue('new_customer_zip',document.getElementById('destination_zip')?.value||'');
    setValue('new_customer_address',document.getElementById('destination_address')?.value||'');
    setValue('new_customer_number',document.getElementById('destination_number')?.value||'');
    setValue('new_customer_complement',document.getElementById('destination_complement')?.value||'');
    setValue('new_customer_district',document.getElementById('destination_district')?.value||'');
    setValue('new_customer_city',document.getElementById('destination_city')?.value||'');
    setValue('new_customer_city_ibge',document.getElementById('destination_city_ibge')?.value||'');
    setValue('new_customer_state',document.getElementById('destination_state')?.value||'');
    setValue('recipientCustomerSaveStatus','');
    clientCreateModal?.classList.add('open');
    setTimeout(()=>document.getElementById('new_customer_name')?.focus(),60);
  }
  async function fillCep(target){
    const ids=target==='new' ? {zip:'new_customer_zip',address:'new_customer_address',district:'new_customer_district',city:'new_customer_city',state:'new_customer_state',ibge:'new_customer_city_ibge'} : {zip:'destination_zip',address:'destination_address',district:'destination_district',city:'destination_city',state:'destination_state',ibge:'destination_city_ibge'};
    const zip=digits(document.getElementById(ids.zip)?.value||''); if(zip.length!==8) return;
    try{
      const response=await fetch('https://viacep.com.br/ws/'+zip+'/json/'); const data=await response.json(); if(data.erro) throw new Error('CEP não encontrado.');
      setValue(ids.address,data.logradouro||''); setValue(ids.district,data.bairro||''); setValue(ids.city,data.localidade||''); setValue(ids.state,data.uf||''); setValue(ids.ibge,data.ibge||'');
      if(target==='new') document.getElementById('new_customer_number')?.focus(); else document.getElementById('destination_number')?.focus();
    }catch(err){ if(target==='new') setValue('recipientCustomerSaveStatus',err.message||'Não foi possível consultar o CEP.'); }
  }
  function validateSelection(showAlert=true){
    const checked=selected();
    if(!checked.length){ if(showAlert) alert('Selecione ao menos uma NFC-e autorizada.'); return null; }
    const firstDoc=digits(checked[0].dataset.doc);
    for(const c of checked){ const doc=digits(c.dataset.doc); if(firstDoc && doc && doc!==firstDoc){ if(showAlert) alert('Selecione NFC-e do mesmo cliente/destinatário para gerar uma única NF-e.'); return null; } }
    return checked;
  }
  function closeModal(){ modal?.classList.remove('open'); }
  function openModal(){
    const checked=validateSelection(); if(!checked) return;
    const first=checked.find(c=>[11,14].includes(digits(c.dataset.doc).length)) || checked[0];
    const generic=/^(consumidor( final)?|consumidor não identificado)$/i.test(String(first.dataset.name||'').trim());
    setValue('destination_person_id', first.dataset.personId);
    setValue('destination_name', generic ? '' : first.dataset.name);
    setValue('destination_document', first.dataset.doc);
    setValue('destination_ie', first.dataset.ie);
    setValue('destination_email', first.dataset.email);
    setValue('destination_phone', first.dataset.phone);
    setValue('destination_zip', first.dataset.zip);
    setValue('destination_address', first.dataset.address);
    setValue('destination_number', first.dataset.number);
    setValue('destination_complement', first.dataset.complement);
    setValue('destination_district', first.dataset.district);
    setValue('destination_city', first.dataset.city);
    setValue('destination_city_ibge', first.dataset.cityIbge);
    setValue('destination_state', first.dataset.state);
    setValue('recipientCustomerPick', first.dataset.personId && !generic ? ((first.dataset.name||'Cliente')+(first.dataset.doc?' · '+first.dataset.doc:'')) : '');
    const total=checked.reduce((sum,c)=>sum+Number(c.dataset.total||0),0);
    document.getElementById('refSelectionSummary').textContent=checked.length+' NFC-e selecionada(s) · Total '+money(total)+' · '+checked.map(c=>c.dataset.label).join(', ');
    modal?.classList.add('open');
    setTimeout(()=>document.getElementById(digits(first.dataset.doc).length ? 'destination_name' : 'destination_document')?.focus(),50);
  }
  all?.addEventListener('change', function(){ document.querySelectorAll('.ref-check:not(:disabled)').forEach(c=>c.checked=all.checked); });
  document.getElementById('openRecipientModal')?.addEventListener('click', openModal);
  document.getElementById('closeRecipientModal')?.addEventListener('click', closeModal);
  document.getElementById('cancelRecipientModal')?.addEventListener('click', closeModal);
  document.getElementById('openRecipientCustomerSearch')?.addEventListener('click', openCustomerSearch);
  document.getElementById('openRecipientCustomerCreate')?.addEventListener('click', prefillCustomerCreate);
  document.getElementById('createCustomerFromSearch')?.addEventListener('click', prefillCustomerCreate);
  document.getElementById('closeRecipientCustomerSearch')?.addEventListener('click',()=>clientSearchModal?.classList.remove('open'));
  document.getElementById('closeRecipientCustomerCreate')?.addEventListener('click',()=>clientCreateModal?.classList.remove('open'));
  document.getElementById('cancelRecipientCustomerCreate')?.addEventListener('click',()=>clientCreateModal?.classList.remove('open'));
  document.getElementById('recipientCustomerSearchInput')?.addEventListener('input',ev=>renderCustomerResults(ev.target.value||''));
  document.getElementById('recipientCustomerSearchResults')?.addEventListener('click',ev=>{ const btn=ev.target.closest('[data-client-id]'); if(!btn) return; selectCustomer(clients.find(c=>Number(c.id)===Number(btn.dataset.clientId))||null); });
  document.getElementById('destination_zip')?.addEventListener('blur',()=>fillCep('destination'));
  document.getElementById('new_customer_zip')?.addEventListener('blur',()=>fillCep('new'));
  document.getElementById('new_customer_document')?.addEventListener('input',ev=>{ const doc=digits(ev.target.value); if(doc.length===14) setValue('new_customer_person_type','JURIDICA'); else if(doc.length===11) setValue('new_customer_person_type','FISICA'); });
  document.getElementById('recipientCustomerCreateForm')?.addEventListener('submit',async function(ev){
    ev.preventDefault(); setValue('recipientCustomerSaveStatus','Salvando cliente...');
    try{
      const formData=new FormData(this);
      const response=await fetch('fiscal_notas_referenciadas.php',{method:'POST',body:formData,headers:{'Accept':'application/json'}});
      const data=await response.json(); if(!data.ok) throw new Error(data.error||'Falha ao cadastrar cliente.');
      const c={...(data.customer||{})};
      if(!c.state_registration) c.state_registration=String(formData.get('state_registration')||'');
      if(!c.city_ibge) c.city_ibge=String(formData.get('city_ibge')||'');
      const idx=clients.findIndex(row=>Number(row.id)===Number(c.id)); if(idx>=0) clients[idx]=c; else clients.push(c);
      selectCustomer(c); setValue('recipientCustomerSaveStatus','');
    }catch(err){ setValue('recipientCustomerSaveStatus',err.message||'Falha ao cadastrar cliente.'); }
  });
  modal?.addEventListener('click', ev=>{ if(ev.target===modal) closeModal(); });
  clientSearchModal?.addEventListener('click',ev=>{ if(ev.target===clientSearchModal) clientSearchModal.classList.remove('open'); });
  clientCreateModal?.addEventListener('click',ev=>{ if(ev.target===clientCreateModal) clientCreateModal.classList.remove('open'); });
  document.addEventListener('keydown', ev=>{ if(ev.key!=='Escape') return; if(clientCreateModal?.classList.contains('open')){clientCreateModal.classList.remove('open');return;} if(clientSearchModal?.classList.contains('open')){clientSearchModal.classList.remove('open');return;} closeModal(); });
  form?.addEventListener('submit', function(ev){
    if(!validateSelection()){ ev.preventDefault(); return; }
    const doc=digits(document.getElementById('destination_document')?.value);
    if(![11,14].includes(doc.length)){ ev.preventDefault(); alert('Informe um CPF ou CNPJ completo para o destinatário.'); document.getElementById('destination_document')?.focus(); return; }
    if(!(document.getElementById('destination_name')?.value||'').trim()){ ev.preventDefault(); alert('Informe o nome ou razão social do destinatário.'); document.getElementById('destination_name')?.focus(); }
  });
})();
</script>
<?php fiscal_admin_end(); ?>
