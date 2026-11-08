<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/finance_module.php';

$cid = function_exists('fiscal_v10_1_manual_company_id') ? fiscal_v10_1_manual_company_id($pdo) : (int)COMPANY_ID;
$manualCrossContext = function_exists('fiscal_v10_1_manual_is_cross_context') ? fiscal_v10_1_manual_is_cross_context($pdo) : ($cid !== (int)COMPANY_ID);
$manualCompanyName = function_exists('fiscal_v10_entry_company_name') ? fiscal_v10_entry_company_name($pdo, $cid) : ('Empresa #' . $cid);
$manualContextUrl = static function(string $url): string {
  return function_exists('fiscal_v10_1_manual_context_url') ? fiscal_v10_1_manual_context_url($url) : $url;
};
$manualAjaxBase = $manualContextUrl('fiscal_nota_manual.php');
fiscal_xml_ensure_schema($pdo);
finance_ensure_schema($pdo);
$err = '';

function fem_json(array $data, int $status=200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

if (isset($_GET['ajax'])) {
  try {
    $ajax=(string)$_GET['ajax'];
    if ($ajax==='suppliers') {
      $q=trim((string)($_GET['q']??'')); $sql='SELECT id,name,fantasy_name,document,state_registration,email,phone,whatsapp,zip_code,address,number,complement,district,city,city_ibge,state FROM finance_people WHERE company_id=? AND active=1 AND is_supplier=1'; $pa=[$cid];
      if($q!==''){ $like=nexa_search_like($q); $sql.=' AND (name LIKE ? OR fantasy_name LIKE ? OR document LIKE ? OR state_registration LIKE ? OR phone LIKE ? OR whatsapp LIKE ?)'; array_push($pa,$like,$like,$like,$like,$like,$like); }
      $sql.=' ORDER BY name ASC LIMIT 60'; $st=$pdo->prepare($sql);$st->execute($pa);fem_json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)?:[]]);
    }
    if ($ajax==='create_supplier' && $_SERVER['REQUEST_METHOD']==='POST') {
      $name=trim((string)($_POST['name']??'')); if($name==='') throw new RuntimeException('Informe o nome/razão social.');
      $doc=preg_replace('/\D+/','',(string)($_POST['document']??'')); $ie=trim((string)($_POST['state_registration']??''));
      if($doc!==''){ $st=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND document=? LIMIT 1');$st->execute([$cid,$doc]);$existing=(int)$st->fetchColumn(); if($existing>0){$pdo->prepare('UPDATE finance_people SET is_supplier=1,active=1,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$cid,$existing]);$st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=?');$st->execute([$cid,$existing]);fem_json(['ok'=>true,'row'=>$st->fetch(PDO::FETCH_ASSOC)]);}}
      $ptype=strlen($doc)>11?'JURIDICA':'FISICA';
      $st=$pdo->prepare('INSERT INTO finance_people (company_id,name,document,state_registration,person_type,email,phone,whatsapp,zip_code,address,number,complement,district,city,city_ibge,state,is_supplier,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,NOW(),NOW())');
      $st->execute([$cid,$name,$doc?:null,$ie?:null,$ptype,trim((string)($_POST['email']??''))?:null,trim((string)($_POST['phone']??''))?:null,trim((string)($_POST['whatsapp']??''))?:null,preg_replace('/\D+/','',(string)($_POST['zip_code']??''))?:null,trim((string)($_POST['address']??''))?:null,trim((string)($_POST['number']??''))?:null,trim((string)($_POST['complement']??''))?:null,trim((string)($_POST['district']??''))?:null,trim((string)($_POST['city']??''))?:null,preg_replace('/\D+/','',(string)($_POST['city_ibge']??''))?:null,strtoupper(substr(trim((string)($_POST['state']??'')),0,2))?:null]);
      $id=(int)$pdo->lastInsertId();$st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=?');$st->execute([$cid,$id]);fem_json(['ok'=>true,'row'=>$st->fetch(PDO::FETCH_ASSOC)]);
    }
    if ($ajax==='products') {
      $q=trim((string)($_GET['q']??'')); $rows=fiscal_xml_products_options($pdo,$cid,$q,60); $out=[];
      foreach($rows as $p){$tax=fiscal_product_tax($pdo,$cid,(int)$p['id']);$barcode='';foreach(['codigo_barras','ean','gtin','barcode'] as $c){if(!empty($p[$c])){$barcode=(string)$p[$c];break;}}
        $out[]=['id'=>(int)$p['id'],'name'=>(string)($p['nome']??''),'code'=>(trim((string)($p['numero_cadastro']??''))!==''&&trim((string)($p['numero_cadastro']??''))!=='0'?trim((string)$p['numero_cadastro']):(trim((string)($p['codigo_firebird']??''))!==''?(string)$p['codigo_firebird']:(string)$p['id'])),'barcode'=>$barcode,'reference'=>trim((string)($p['referencia']??'')),'unit'=>(string)($p['unidade_estoque']??'UN'),'cost'=>(float)($p['custo']??0),'price'=>(float)($p['preco']??0),'category'=>(string)($p['categoria']??''),'tax'=>$tax];}
      fem_json(['ok'=>true,'rows'=>$out]);
    }
    fem_json(['ok'=>false,'message'=>'Ação inválida.'],400);
  } catch(Throwable $e){fem_json(['ok'=>false,'message'=>$e->getMessage()],500);}
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // V10.1: no contexto cruzado, não confia nos IDs de produto recebidos do navegador.
    // Todos os itens precisam existir na empresa efetiva selecionada pelo F11.
    if ($manualCrossContext) {
      $postedProductIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['item_product_id'] ?? [])), static fn($v) => $v > 0)));
      if ($postedProductIds) {
        $ph = implode(',', array_fill(0, count($postedProductIds), '?'));
        $stValidProducts = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND id IN (' . $ph . ')');
        $stValidProducts->execute(array_merge([$cid], $postedProductIds));
        $validProductIds = array_map('intval', $stValidProducts->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($validProductIds) !== count($postedProductIds)) {
          throw new RuntimeException('V10.1 Multiempresa: foi detectado produto que não pertence à empresa selecionada pelo F11. A entrada manual foi bloqueada.');
        }
      }
    }

    $id = fiscal_xml_create_manual_entry($pdo, $cid, $_POST);
    header('Location: ' . $manualContextUrl('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode('Nota/pedido manual cadastrado. Confira os itens e a tributação antes de gerar a entrada.')));
    exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$today=date('Y-m-d');
fiscal_admin_start('Fiscal - Entrada manual', 'fiscal_nota_manual');
fiscal_page_header('Nota / pedido manual · ' . $manualCompanyName, 'Entrada manual compacta com fornecedor, itens, tributação e parcelas.', [
  ['label'=>'Voltar para Entradas','href'=>$manualContextUrl('fiscal_entrada_notas.php'),'class'=>'btn'],
  ['label'=>'+ Importar XML','href'=>$manualContextUrl('fiscal_importar_xml.php'),'class'=>'btn btn-primary'],
]);
fiscal_flash();
if ($manualCrossContext) echo '<div class="msg ok"><strong>V10.1 Multiempresa:</strong> esta entrada manual será criada em <strong>' . fiscal_h($manualCompanyName) . '</strong>. Produtos, fornecedor e estoque serão isolados nesta empresa. O Contas a Pagar continua bloqueado pelo F11 nesta etapa.</div>';
if($err) echo '<div class="msg err">'.fiscal_h($err).'</div>';
?>
<style>
.manual-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;margin-bottom:12px}.manual-head{display:grid;grid-template-columns:160px 90px 150px 150px minmax(240px,1fr);gap:10px;align-items:end}.manual-head label,.manual-field label{display:block;font-size:11px;font-weight:900;color:#334155;margin-bottom:5px}.manual-head input,.manual-head textarea,.manual-field input{width:100%;box-sizing:border-box}.manual-supplier-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:end;margin-top:12px}.manual-supplier-card{border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:9px 11px;min-height:42px}.manual-supplier-card strong{color:#0b1f5b}.manual-supplier-card small{display:block;color:#64748b;margin-top:2px}.manual-add-head{display:flex;align-items:center;gap:9px;font-weight:950;color:#0b1f5b;margin-bottom:10px}.manual-add-head .dot{width:22px;height:22px;border-radius:999px;background:#0b1f5b;color:#fff;display:inline-flex;align-items:center;justify-content:center}.manual-launch{display:grid;grid-template-columns:minmax(300px,2.2fr) 95px 120px 120px;gap:8px;align-items:end}.manual-search-line{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px}.manual-item{border:1px solid #dbe4f0;border-radius:17px;overflow:hidden;margin-top:9px}.manual-item-main{display:grid;grid-template-columns:minmax(260px,2fr) 90px 95px 115px 115px 44px;gap:8px;align-items:end;padding:10px}.manual-item-main label{font-size:11px;font-weight:900;color:#334155}.manual-item-main input{width:100%;box-sizing:border-box;margin-top:4px}.manual-item-title strong{display:block;color:#0b1f5b}.manual-item-title small{display:block;color:#64748b;margin-top:2px}.manual-tax{border-top:1px solid #e5edf6;background:#f8fbff;padding:9px}.manual-tax summary{cursor:pointer;font-weight:950;color:#1e3a8a}.manual-tax-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:7px;margin-top:8px}.manual-tax-grid label{font-size:10px;font-weight:900;color:#475569}.manual-tax-grid input{width:100%;box-sizing:border-box;min-height:34px}.manual-remove{height:40px;border-radius:12px;border:1px solid #fecaca;background:#fff;color:#b91c1c;font-weight:950}.manual-total{display:flex;justify-content:flex-end;margin-top:10px;font-size:18px;font-weight:950;color:#0b1f5b}.installments-head{display:flex;justify-content:space-between;align-items:center;gap:10px}.install-row{display:grid;grid-template-columns:90px 160px 160px 44px;gap:8px;align-items:end;margin-top:8px}.install-row label{font-size:11px;font-weight:900}.install-row input{width:100%;box-sizing:border-box}.fem-modal{position:fixed;inset:0;z-index:900;display:none;align-items:flex-start;justify-content:center;padding:5vh 16px;background:rgba(15,23,42,.55);overflow:auto}.fem-modal.open{display:flex}.fem-dialog{width:min(900px,100%);background:#fff;border-radius:22px;padding:16px;box-shadow:0 30px 80px rgba(2,6,23,.35)}.fem-modal-head{display:flex;justify-content:space-between;align-items:center;gap:10px}.fem-results{display:grid;gap:7px;margin-top:10px;max-height:420px;overflow:auto}.fem-result{border:1px solid #dbe4f0;border-radius:13px;padding:9px 10px;cursor:pointer}.fem-result.active,.fem-result:hover{background:#eff6ff;outline:2px solid #2563eb}.fem-create-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}.fem-create-grid input{width:100%;box-sizing:border-box}.fem-create-grid .span-3{grid-column:1/-1}@media(max-width:1000px){.manual-head{grid-template-columns:repeat(2,minmax(0,1fr))}.manual-launch,.manual-item-main{grid-template-columns:repeat(3,minmax(0,1fr))}.manual-item-title{grid-column:1/-1}.manual-tax-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.manual-head,.manual-supplier-row,.manual-launch,.manual-item-main,.manual-tax-grid,.install-row,.fem-create-grid{grid-template-columns:1fr}.fem-create-grid .span-3{grid-column:auto}}
</style>
<form method="post" action="<?= fiscal_h($manualContextUrl('fiscal_nota_manual.php')) ?>" id="manualFiscalForm" autocomplete="off">
<div class="manual-card"><div class="manual-head">
  <div><label>Número da nota/pedido *</label><input name="number" required value="<?= fiscal_h($_POST['number']??'') ?>"></div>
  <div><label>Série</label><input name="series" value="<?= fiscal_h($_POST['series']??'M') ?>"></div>
  <div><label>Data de emissão *</label><input type="date" name="issue_date" required value="<?= fiscal_h($_POST['issue_date']??$today) ?>"></div>
  <div><label>Data de entrada *</label><input type="date" name="entry_date" id="entryDate" required value="<?= fiscal_h($_POST['entry_date']??$today) ?>"></div>
  <div><label>Observações internas</label><input name="notes" value="<?= fiscal_h($_POST['notes']??'') ?>" placeholder="Observação opcional"></div>
</div>
<div class="manual-supplier-row"><div><label style="font-size:11px;font-weight:900">Fornecedor *</label><div class="manual-supplier-card" id="supplierCard"><strong id="supplierCardName">Nenhum fornecedor selecionado</strong><small id="supplierCardMeta">Use F8 ou Buscar.</small></div></div><button class="btn" type="button" id="btnSupplier">Buscar (F8)</button><button class="btn" type="button" id="btnNewSupplier">Cadastrar</button></div>
<input type="hidden" name="supplier_name" id="supplierName"><input type="hidden" name="supplier_doc" id="supplierDoc"><input type="hidden" name="supplier_ie" id="supplierIe"><input type="hidden" name="supplier_uf" id="supplierUf"><input type="hidden" name="supplier_city" id="supplierCity"><input type="hidden" name="supplier_city_ibge" id="supplierCityIbge"><input type="hidden" name="supplier_address" id="supplierAddress"><input type="hidden" name="supplier_number" id="supplierNumber"><input type="hidden" name="supplier_district" id="supplierDistrict"><input type="hidden" name="supplier_zip" id="supplierZip">
</div>

<div class="manual-card"><div class="manual-add-head"><span class="dot">−</span>Adicionar produtos / serviços</div><div class="manual-launch">
  <div><label style="font-size:11px;font-weight:900">Produto / Serviço</label><div class="manual-search-line"><input id="productSearch" placeholder="Digite nome, código, referência ou barras e Enter"><button class="btn" type="button" id="btnProduct">Buscar (F8)</button></div></div>
  <div><label style="font-size:11px;font-weight:900">Quantidade</label><input id="draftQty" value="1,00" readonly></div>
  <div><label style="font-size:11px;font-weight:900">Custo unitário</label><input id="draftPrice" value="0,00" readonly></div>
  <div><label style="font-size:11px;font-weight:900">Valor Total</label><input id="draftTotal" value="0,00" readonly></div>
</div><div id="itemsWrap"></div><div class="manual-total">Total dos itens:&nbsp;<span id="manualTotal">R$ 0,00</span></div></div>

<div class="manual-card"><div class="installments-head"><div><h2 style="margin:0"><?= $manualCrossContext ? 'Parcelas de referência da entrada' : 'Parcelas para o Contas a Pagar' ?></h2><div class="fiscal-muted"><?= $manualCrossContext ? 'Informe os vencimentos e valores para compor o lançamento. Na V10.1 pelo F11, estas parcelas não gerarão Contas a Pagar.' : 'Informe os vencimentos e valores. A soma deve fechar com o total dos itens.' ?></div></div><button class="btn" type="button" id="addInstallment">+ Parcela</button></div><div id="installmentsWrap"></div></div>
<div class="fiscal-form-actions"><button class="btn btn-primary" type="submit">Salvar entrada manual</button></div>
</form>

<div class="fem-modal" id="productModal"><div class="fem-dialog"><div class="fem-modal-head"><div><h2 style="margin:0">Buscar produto</h2><div class="fiscal-muted">Digite e pressione Enter. Use ↑/↓ e Enter para selecionar.</div></div><button class="btn" type="button" data-close="productModal">×</button></div><div class="manual-search-line" style="margin-top:10px"><input id="productQuery" placeholder="Nome, código, referência ou código de barras"><button class="btn" type="button" id="productSearchGo">Pesquisar</button></div><div class="fem-results" id="productResults"></div><div style="display:flex;justify-content:flex-end;margin-top:10px"><a class="btn btn-primary" href="<?= fiscal_h($manualContextUrl('produto_form.php')) ?>" target="_blank" rel="noopener">+ Cadastrar novo produto</a></div></div></div>

<div class="fem-modal" id="supplierModal"><div class="fem-dialog"><div class="fem-modal-head"><div><h2 style="margin:0">Buscar fornecedor</h2><div class="fiscal-muted">Pesquisa vazia ao abrir. Enter pesquisa, ↑/↓ navegam e Enter seleciona.</div></div><button class="btn" type="button" data-close="supplierModal">×</button></div><div class="manual-search-line" style="margin-top:10px"><input id="supplierQuery" placeholder="Nome, CPF/CNPJ, IE ou telefone"><button class="btn" type="button" id="supplierSearchGo">Pesquisar</button></div><div class="fem-results" id="supplierResults"></div><div style="display:flex;justify-content:flex-end;margin-top:10px"><button class="btn btn-primary" type="button" id="supplierCreateOpen">+ Cadastrar fornecedor</button></div></div></div>

<div class="fem-modal" id="supplierCreateModal"><div class="fem-dialog"><div class="fem-modal-head"><h2 style="margin:0">Cadastrar fornecedor</h2><button class="btn" type="button" data-close="supplierCreateModal">×</button></div><div class="fem-create-grid"><div class="span-3"><label>Nome/Razão social *</label><input id="newSupplierName"></div><div><label>CPF/CNPJ</label><input id="newSupplierDoc"></div><div><label>Inscrição estadual</label><input id="newSupplierIe"></div><div><label>Telefone</label><input id="newSupplierPhone"></div><div><label>CEP</label><input id="newSupplierZip"></div><div><label>Endereço</label><input id="newSupplierAddress"></div><div><label>Número</label><input id="newSupplierNumber"></div><div><label>Bairro</label><input id="newSupplierDistrict"></div><div><label>Cidade</label><input id="newSupplierCity"></div><div><label>UF</label><input id="newSupplierState" maxlength="2"></div><div><label>IBGE</label><input id="newSupplierIbge"></div></div><div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-primary" type="button" id="saveSupplier">Salvar fornecedor</button></div></div></div>

<script>
(()=>{
const $=s=>document.querySelector(s), $$=s=>Array.from(document.querySelectorAll(s));
const manualEndpoint=<?= json_encode($manualAjaxBase, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const manualUrl=params=>manualEndpoint+(manualEndpoint.includes('?')?'&':'?')+params;
const num=v=>{v=String(v??'').replace(/R\$|\s/g,'');if(v.includes(','))v=v.replace(/\./g,'').replace(',','.');return Number(v.replace(/[^0-9.-]/g,''))||0};
const br=v=>Number(v||0).toFixed(2).replace('.',','); const money=v=>'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function jget(url){let r=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});return r.json()}
async function jpost(url,data){let r=await fetch(url,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)});return r.json()}
function open(id){$('#'+id).classList.add('open')}function close(id){$('#'+id).classList.remove('open')}$$('[data-close]').forEach(b=>b.onclick=()=>close(b.dataset.close));

let supplierRows=[],supplierActive=-1; function setSupplier(p){$('#supplierName').value=p.name||'';$('#supplierDoc').value=p.document||'';$('#supplierIe').value=p.state_registration||'';$('#supplierUf').value=p.state||'';$('#supplierCity').value=p.city||'';$('#supplierCityIbge').value=p.city_ibge||'';$('#supplierAddress').value=p.address||'';$('#supplierNumber').value=p.number||'';$('#supplierDistrict').value=p.district||'';$('#supplierZip').value=p.zip_code||'';$('#supplierCardName').textContent=p.name||'';$('#supplierCardMeta').textContent=[p.document||'',p.state_registration?'IE '+p.state_registration:'',[p.city,p.state].filter(Boolean).join('/')].filter(Boolean).join(' · ');close('supplierModal');close('supplierCreateModal')}
async function searchSupplier(){let d=await jget(manualUrl('ajax=suppliers&q='+encodeURIComponent($('#supplierQuery').value.trim())));supplierRows=d.rows||[];supplierActive=-1;let w=$('#supplierResults');w.innerHTML='';supplierRows.forEach((p,i)=>{let e=document.createElement('div');e.className='fem-result';e.tabIndex=0;e.innerHTML='<strong>'+esc(p.name)+'</strong><div class="fiscal-muted">'+esc((window.nexaMaskDoc||String)(p.document||''))+' · '+esc(p.state_registration||'')+' · '+esc((p.city||'')+'/'+(p.state||''))+'</div>';e.onclick=()=>setSupplier(p);e.onkeydown=ev=>resultKeys(ev,'supplier',i);w.appendChild(e)})}
function focusResult(type,i){let rows=$$('#'+type+'Results .fem-result');if(!rows.length)return;let max=rows.length-1;let idx=Math.max(0,Math.min(i,max));if(type==='supplier')supplierActive=idx;else productActive=idx;rows.forEach((r,k)=>r.classList.toggle('active',k===idx));rows[idx].focus();rows[idx].scrollIntoView({block:'nearest'})}
function resultKeys(ev,type,i){if(ev.key==='ArrowDown'){ev.preventDefault();focusResult(type,i+1)}else if(ev.key==='ArrowUp'){ev.preventDefault();if(i===0){$('#'+type+'Query').focus()}else focusResult(type,i-1)}else if(ev.key==='Enter'){ev.preventDefault();if(type==='supplier')setSupplier(supplierRows[i]);else addProduct(productRows[i])}}
function openSupplier(){open('supplierModal');$('#supplierQuery').value='';$('#supplierResults').innerHTML='';setTimeout(()=>$('#supplierQuery').focus(),50)}
$('#btnSupplier').onclick=openSupplier;$('#supplierSearchGo').onclick=searchSupplier;$('#supplierQuery').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();searchSupplier()}else if(e.key==='ArrowDown'){e.preventDefault();focusResult('supplier',0)}};
$('#btnNewSupplier').onclick=()=>{open('supplierCreateModal');setTimeout(()=>$('#newSupplierName').focus(),50)};$('#supplierCreateOpen').onclick=()=>{close('supplierModal');open('supplierCreateModal');setTimeout(()=>$('#newSupplierName').focus(),50)};
$('#saveSupplier').onclick=async()=>{let d=await jpost(manualUrl('ajax=create_supplier'),{name:$('#newSupplierName').value,document:$('#newSupplierDoc').value,state_registration:$('#newSupplierIe').value,phone:$('#newSupplierPhone').value,zip_code:$('#newSupplierZip').value,address:$('#newSupplierAddress').value,number:$('#newSupplierNumber').value,district:$('#newSupplierDistrict').value,city:$('#newSupplierCity').value,state:$('#newSupplierState').value,city_ibge:$('#newSupplierIbge').value});if(!d.ok){alert(d.message||'Não foi possível cadastrar.');return}setSupplier(d.row)};

let productRows=[],productActive=-1,itemSeq=0; async function searchProduct(){let q=$('#productQuery').value.trim();let d=await jget(manualUrl('ajax=products&q='+encodeURIComponent(q)));productRows=d.rows||[];productActive=-1;let w=$('#productResults');w.innerHTML='';productRows.forEach((p,i)=>{let e=document.createElement('div');e.className='fem-result';e.tabIndex=0;e.innerHTML='<strong>'+esc(p.name)+'</strong><div class="fiscal-muted">Cód. '+esc(p.code||p.id)+(p.reference?' · Ref. '+esc(p.reference):'')+(p.barcode?' · '+esc(p.barcode):'')+' · '+esc(p.unit||'UN')+' · Custo '+money(p.cost||p.price||0)+'</div>';e.onclick=()=>addProduct(p);e.onkeydown=ev=>resultKeys(ev,'product',i);w.appendChild(e)})}
function openProduct(q=''){open('productModal');$('#productQuery').value=q||'';$('#productResults').innerHTML='';setTimeout(()=>{$('#productQuery').focus();if(q)searchProduct()},50)}
$('#btnProduct').onclick=()=>openProduct($('#productSearch').value.trim());$('#productSearch').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();openProduct($('#productSearch').value.trim())}};$('#productSearchGo').onclick=searchProduct;$('#productQuery').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();searchProduct()}else if(e.key==='ArrowDown'){e.preventDefault();focusResult('product',0)}};
function taxField(label,name,val=''){return `<label>${label}<input name="${name}[]" value="${esc(val??'')}"></label>`}
function addProduct(p){close('productModal');$('#productSearch').value='';itemSeq++;let t=p.tax||{},cost=Number(p.cost||p.price||0),cfop=t.cfop_entry_state||t.cfop_entry_interstate||'';let r=document.createElement('div');r.className='manual-item';r.innerHTML=`<div class="manual-item-main"><div class="manual-item-title"><strong>${esc(p.name)}</strong><small>Cód. ${esc(p.code||p.id)}${p.reference?' · Ref. '+esc(p.reference):''} · ${esc(p.category||'')} · NCM ${esc(t.ncm||'-')}</small><input type="hidden" name="item_product_id[]" value="${p.id}"><input type="hidden" name="item_name[]" value="${esc(p.name)}"><input type="hidden" name="item_code[]" value="${esc(p.code||p.id)}"></div><label>Qtd.<input name="item_quantity[]" value="1,00" inputmode="decimal"></label><label>Un.<input name="item_unit[]" value="${esc(p.unit||'UN')}"></label><label>Custo unit.<input name="item_unit_value[]" value="${br(cost)}" inputmode="decimal"></label><label>Total<input class="manual-line-total" value="${br(cost)}" readonly></label><button class="manual-remove" type="button">×</button></div><details class="manual-tax" open><summary>Tributação do item — clique para recolher/expandir</summary><div class="manual-tax-grid">${taxField('NCM','item_ncm',t.ncm)}${taxField('CEST','item_cest',t.cest)}${taxField('CFOP entrada','item_cfop',cfop)}${taxField('Origem','item_origem',t.origem||'0')}${taxField('ICMS CST','item_icms_cst',t.icms_cst)}${taxField('CSOSN','item_icms_csosn',t.icms_csosn)}${taxField('BC ICMS','item_icms_base','0,00')}${taxField('ICMS %','item_icms_rate',t.icms_rate||0)}${taxField('Valor ICMS','item_icms_amount','0,00')}${taxField('BC ICMS ST','item_icms_st_base','0,00')}${taxField('ST %','item_icms_st_rate',t.icms_st_rate||0)}${taxField('Valor ST','item_icms_st_amount','0,00')}${taxField('IPI CST','item_ipi_cst',t.ipi_cst)}${taxField('BC IPI','item_ipi_base','0,00')}${taxField('IPI %','item_ipi_rate',t.ipi_rate||0)}${taxField('Valor IPI','item_ipi_amount','0,00')}${taxField('PIS CST','item_pis_cst',t.pis_cst)}${taxField('BC PIS','item_pis_base','0,00')}${taxField('PIS %','item_pis_rate',t.pis_rate||0)}${taxField('Valor PIS','item_pis_amount','0,00')}${taxField('COFINS CST','item_cofins_cst',t.cofins_cst)}${taxField('BC COFINS','item_cofins_base','0,00')}${taxField('COFINS %','item_cofins_rate',t.cofins_rate||0)}${taxField('Valor COFINS','item_cofins_amount','0,00')}</div></details>`;$('#itemsWrap').appendChild(r);r.querySelector('.manual-remove').onclick=()=>{r.remove();recalc()};r.querySelectorAll('input[name="item_quantity[]"],input[name="item_unit_value[]"]').forEach(i=>i.oninput=()=>lineCalc(r));$('#draftQty').value='1,00';$('#draftPrice').value=br(cost);$('#draftTotal').value=br(cost);lineCalc(r)}
function lineCalc(r){let q=num(r.querySelector('input[name="item_quantity[]"]').value),u=num(r.querySelector('input[name="item_unit_value[]"]').value),tot=q*u;r.querySelector('.manual-line-total').value=br(tot);recalc()}
function recalc(){let total=0;$$('.manual-line-total').forEach(i=>total+=num(i.value));$('#manualTotal').textContent=money(total);syncInstallment(total)}
let instSeq=0;function addInstallment(amount=0,date=''){instSeq++;let r=document.createElement('div');r.className='install-row';r.innerHTML=`<label>Parcela<input name="installment_number[]" value="${String(instSeq).padStart(3,'0')}"></label><label>Vencimento<input type="date" name="installment_due_date[]" value="${date||$('#entryDate').value}"></label><label>Valor<input class="install-amount" name="installment_amount[]" value="${br(amount)}" inputmode="decimal"></label><button type="button" class="manual-remove">×</button>`;$('#installmentsWrap').appendChild(r);r.querySelector('.manual-remove').onclick=()=>{r.remove();if(!$$('.install-row').length)addInstallment(currentTotal(),$('#entryDate').value)};r.querySelector('.install-amount').oninput=()=>r.dataset.touched='1'}
function currentTotal(){let t=0;$$('.manual-line-total').forEach(i=>t+=num(i.value));return t}function syncInstallment(total){let rows=$$('.install-row');if(!rows.length){addInstallment(total,$('#entryDate').value);return}if(rows.length===1&&!rows[0].dataset.touched)rows[0].querySelector('.install-amount').value=br(total)}$('#addInstallment').onclick=()=>{let total=currentTotal(),used=$$('.install-amount').reduce((s,i)=>s+num(i.value),0);addInstallment(Math.max(0,total-used),$('#entryDate').value)};

document.addEventListener('keydown',e=>{if(e.key==='F8'){e.preventDefault();if($('#productModal').classList.contains('open'))return;if(document.activeElement===$('#productSearch')||$('#itemsWrap').contains(document.activeElement)){openProduct($('#productSearch').value.trim())}else openSupplier()}if(e.key==='Escape')$$('.fem-modal.open').forEach(m=>m.classList.remove('open'))});
$('#manualFiscalForm').onsubmit=e=>{if(!$('#supplierName').value){e.preventDefault();alert('Selecione ou cadastre o fornecedor.');openSupplier();return}if(!$$('.manual-item').length){e.preventDefault();alert('Inclua pelo menos um produto.');$('#productSearch').focus();return}let total=currentTotal(),par=$$('.install-amount').reduce((s,i)=>s+num(i.value),0);if(Math.abs(total-par)>0.02){e.preventDefault();alert('O total das parcelas precisa fechar com o total dos itens.');return}};
addInstallment(0,$('#entryDate').value);recalc();
})();
</script>
<?php fiscal_admin_end(); ?>
