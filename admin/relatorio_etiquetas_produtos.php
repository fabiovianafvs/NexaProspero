<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/product_labels_module.php';

$cid=(int)COMPANY_ID;
product_labels_ensure_schema($pdo,$cid);

function lep_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function lep_money($v):string{return 'R$ '.number_format((float)$v,2,',','.');}

$ajax=trim((string)($_GET['ajax']??''));
if($ajax!==''){
  header('Content-Type: application/json; charset=utf-8');
  try{
    if($ajax==='products'){
      echo json_encode(['ok'=>true,'items'=>product_labels_search_products($pdo,$cid,(string)($_GET['q']??''),60)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($ajax==='notes'){
      $rows=product_labels_recent_entries($pdo,$cid,(string)($_GET['q']??''),70);
      echo json_encode(['ok'=>true,'items'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($ajax==='note_items'){
      $data=product_labels_entry_items($pdo,$cid,(int)($_GET['id']??0));
      echo json_encode(['ok'=>true]+$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['ok'=>false,'error'=>'Ação inválida.']);exit;
  }catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
}

$templates=product_labels_templates($pdo,$cid);
admin_topbar('Etiqueta de produtos','relatorio_etiquetas_produtos');
?>
<style>
.lep-page{display:grid;gap:14px}.lep-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.lep-head h1{margin:0;color:var(--admin-primary)}.lep-muted{color:#64748b}.lep-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.75fr);gap:14px}.lep-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.lep-card h2{margin:0 0 4px;color:#0b1f5b}.lep-search-block{margin-top:12px}.lep-search-block>label{display:block;margin:0 0 6px}.lep-search-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:stretch}.lep-search-row input,.lep-model-row select{height:44px;min-height:44px;border-radius:14px;margin:0!important}.lep-search-row>.btn,.lep-note-search>.btn,.lep-model-row>.btn{height:44px;min-height:44px;margin:0!important;align-self:stretch;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}.lep-results{position:relative}.lep-result-box{position:absolute;z-index:20;left:0;right:0;top:6px;background:#fff;border:1px solid #cbd5e1;border-radius:14px;box-shadow:0 18px 45px rgba(15,23,42,.18);max-height:330px;overflow:auto;display:none}.lep-result{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;padding:10px 12px;border-bottom:1px solid #eef2f7;align-items:center}.lep-result:last-child{border-bottom:0}.lep-result:hover{background:#f8fbff}.lep-result strong{color:#0b1f5b}.lep-result small{display:block;color:#64748b;margin-top:2px}.lep-selected{margin-top:14px;overflow:auto}.lep-table{width:100%;border-collapse:collapse;min-width:720px}.lep-table th,.lep-table td{padding:10px;border-bottom:1px solid #e5edf6;text-align:left}.lep-table th{background:#f8fbff;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.02em}.lep-table .num{text-align:right}.lep-qty{width:92px;min-height:38px;border-radius:12px;text-align:center}.lep-model-block{margin-top:12px}.lep-model-block>label{display:block;margin:0 0 6px}.lep-model-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:stretch}.lep-model-row select{width:100%}.lep-model-summary{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.lep-summary-box{border:1px solid #e2e8f0;border-radius:14px;padding:10px;background:#f8fbff}.lep-summary-box span{display:block;font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase}.lep-summary-box strong{display:block;color:#0b1f5b;margin-top:3px}.lep-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.lep-actions .btn{min-height:42px}.lep-empty{text-align:center;color:#64748b;padding:24px}.lep-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:850;background:#eef6ff;color:#1d4ed8}.lep-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.58);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999}.lep-modal{width:min(980px,96vw);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;border:1px solid #dbe4f0;box-shadow:0 28px 80px rgba(15,23,42,.28)}.lep-modal-head{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:16px;border-bottom:1px solid #e5edf6;position:sticky;top:0;background:#fff;z-index:3}.lep-modal-head h2{margin:0}.lep-modal-body{padding:16px}.lep-product-modal-search{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:end}.lep-product-modal-search label{margin:0}.lep-product-modal-search input{min-height:44px;border-radius:14px}.lep-product-modal-search>.btn{height:44px;min-height:44px;align-self:end;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}.lep-product-modal-results{margin-top:12px;border:1px solid #e2e8f0;border-radius:16px;overflow:auto;max-height:52vh}.lep-product-modal-table{width:100%;border-collapse:collapse;min-width:720px}.lep-product-modal-table th,.lep-product-modal-table td{padding:10px 12px;border-bottom:1px solid #e5edf6;text-align:left}.lep-product-modal-table th{position:sticky;top:0;background:#f8fbff;color:#475569;font-size:12px;text-transform:uppercase;z-index:1}.lep-product-modal-table tr:last-child td{border-bottom:0}.lep-product-modal-table tbody tr{cursor:pointer}.lep-product-modal-table tbody tr:hover,.lep-product-modal-table tbody tr.is-active{background:#eff6ff}.lep-product-modal-table strong{color:#0b1f5b}.lep-product-modal-table small{display:block;color:#64748b;margin-top:2px}.lep-product-modal-price{font-weight:850;color:#047857;white-space:nowrap}.lep-product-modal-select{text-align:right}.lep-note-search{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.lep-note-search input{min-height:44px;border-radius:14px}.lep-note-list{margin-top:12px;display:grid;gap:8px}.lep-note{border:1px solid #e2e8f0;border-radius:16px;padding:12px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center}.lep-note-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.lep-note-title{font-weight:900;color:#0b1f5b}.lep-note-meta{color:#64748b;font-size:12px;margin-top:3px}.lep-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.lep-toast{position:fixed;right:18px;bottom:18px;z-index:12000;background:#0f172a;color:#fff;border-radius:14px;padding:11px 14px;box-shadow:0 15px 40px rgba(15,23,42,.25);display:none;max-width:420px}
@media(max-width:980px){.lep-grid{grid-template-columns:1fr}.lep-model-summary{grid-template-columns:1fr 1fr}}@media(max-width:640px){.lep-search-row,.lep-model-row,.lep-note-search,.lep-product-modal-search{grid-template-columns:1fr}.lep-model-summary{grid-template-columns:1fr}.lep-note{grid-template-columns:1fr}.lep-note-actions{justify-content:flex-start}.lep-search-row>.btn,.lep-note-search>.btn,.lep-model-row>.btn,.lep-product-modal-search>.btn{width:100%}}
</style>
<div class="lep-page">
  <div class="lep-card">
    <div class="lep-head">
      <div><h1>Etiqueta de produtos</h1><p class="lep-muted" style="margin:5px 0 0">Selecione os produtos, informe a quantidade de etiquetas e escolha o modelo de impressão.</p></div>
      <div class="lep-toolbar"><button type="button" class="btn" id="btnImportNote">Importar da Nota de Entrada</button></div>
    </div>
  </div>

  <div class="lep-grid">
    <div class="lep-card">
      <h2>Produtos</h2><div class="lep-muted">Pesquise por nome, categoria, código do cadastro, código Próspero ou código de barras.</div>
      <div class="lep-search-block">
        <label for="productSearch">Pesquisar produto</label>
        <div class="lep-search-row">
          <input type="search" id="productSearch" placeholder="Digite o nome ou código do produto" autocomplete="off">
          <button class="btn" type="button" id="btnProductModalSearch">Buscar F8</button>
          <button class="btn btn-primary" type="button" id="btnProductSearch">Pesquisar</button>
        </div>
      </div>
      <div class="lep-results"><div class="lep-result-box" id="productResults"></div></div>

      <div class="lep-selected">
        <table class="lep-table"><thead><tr><th>Produto</th><th>Código</th><th class="num">Preço</th><th class="num">Qtd. etiquetas</th><th></th></tr></thead><tbody id="selectedBody"><tr id="emptyRow"><td colspan="5" class="lep-empty">Nenhum produto selecionado.</td></tr></tbody></table>
      </div>
    </div>

    <div class="lep-card">
      <h2>Modelo da etiqueta</h2><div class="lep-muted">Escolha o layout e edite as medidas conforme a sua impressora.</div>
      <div class="lep-model-block">
        <label for="templateSelect">Modelo</label>
        <div class="lep-model-row">
          <select id="templateSelect">
            <?php foreach($templates as $t): ?><option value="<?=(int)$t['id']?>"
              data-paper="<?=lep_h($t['paper_type'])?>" data-pw="<?=lep_h($t['page_width_mm'])?>" data-ph="<?=lep_h($t['page_height_mm'])?>" data-cols="<?=(int)$t['columns_count']?>" data-lw="<?=lep_h($t['label_width_mm'])?>" data-lh="<?=lep_h($t['label_height_mm'])?>"><?=lep_h($t['name'])?></option><?php endforeach; ?>
          </select>
          <a class="btn" id="editTemplateBtn" href="relatorio_etiquetas_modelo.php<?= $templates ? '?id='.(int)$templates[0]['id'] : '' ?>">Editar modelo</a>
        </div>
      </div>
      <div class="lep-model-summary">
        <div class="lep-summary-box"><span>Etiqueta</span><strong id="sumLabel">—</strong></div>
        <div class="lep-summary-box"><span>Colunas</span><strong id="sumCols">—</strong></div>
        <div class="lep-summary-box"><span>Papel</span><strong id="sumPaper">—</strong></div>
        <div class="lep-summary-box"><span>Total</span><strong id="sumTotal">0 etiqueta(s)</strong></div>
      </div>
      <div class="lep-actions">
        <button class="btn btn-primary" type="button" id="printBtn">Imprimir etiquetas</button>
        <button class="btn" type="button" id="clearBtn">Limpar seleção</button>
      </div>
      <div class="lep-muted" style="font-size:12px;margin-top:12px">A impressão abre em uma nova aba para conferência. Use escala 100% / tamanho real no diálogo da impressora.</div>
    </div>
  </div>
</div>

<div class="lep-modal-backdrop" id="productModal" aria-hidden="true">
  <div class="lep-modal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
    <div class="lep-modal-head"><div><h2 id="productModalTitle">Pesquisar produto</h2><div class="lep-muted">Digite a pesquisa e pressione Enter. Use as setas ↑/↓ e Enter para selecionar.</div></div><button class="btn" type="button" id="closeProductModal">Fechar</button></div>
    <div class="lep-modal-body">
      <div class="lep-product-modal-search">
        <label>Produto<input type="search" id="productModalSearch" placeholder="Digite nome, categoria, código ou código de barras" autocomplete="off"></label>
        <button class="btn btn-primary" type="button" id="btnProductModalDoSearch">Pesquisar</button>
        <a class="btn" href="produto_form.php" target="_blank" rel="noopener">+ Cadastrar produto</a>
      </div>
      <div class="lep-product-modal-results" id="productModalResults"><div class="lep-empty">Digite um termo para pesquisar. A lista começa vazia.</div></div>
    </div>
  </div>
</div>

<div class="lep-modal-backdrop" id="noteModal" aria-hidden="true">
  <div class="lep-modal" role="dialog" aria-modal="true" aria-labelledby="noteTitle">
    <div class="lep-modal-head"><div><h2 id="noteTitle">Importar produtos da Nota de Entrada</h2><div class="lep-muted">Escolha a nota e como deseja preencher a quantidade de etiquetas.</div></div><button class="btn" type="button" id="closeNote">Fechar</button></div>
    <div class="lep-modal-body">
      <div class="lep-note-search"><input type="search" id="noteSearch" placeholder="Número da nota, fornecedor, CNPJ/CPF ou chave"><button class="btn btn-primary" type="button" id="btnNoteSearch">Pesquisar</button></div>
      <div class="lep-note-list" id="noteList"><div class="lep-empty">Carregando notas...</div></div>
      <div class="lep-muted" style="font-size:12px;margin-top:12px"><strong>Quantidade da nota:</strong> usa a quantidade recebida/gerada em estoque. <strong>1 por produto:</strong> adiciona uma etiqueta para cada produto da nota, ideal para vitrine ou gôndola.</div>
    </div>
  </div>
</div>
<div class="lep-toast" id="toast"></div>

<script>
(function(){
  const selected=new Map();
  const $=id=>document.getElementById(id);
  const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const money=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0));
  let searchTimer=null;
  function toast(msg){const el=$('toast');el.textContent=msg;el.style.display='block';clearTimeout(el._t);el._t=setTimeout(()=>el.style.display='none',3600);}
  function productCode(p){return p.numero_cadastro||p.codigo_firebird||p.codigo_barras||String(p.id||'');}
  function addProduct(p,qty=1,merge=true){
    const id=Number(p.id||0);if(!id)return;
    qty=Math.max(1,Math.round(Number(qty||1)));
    if(selected.has(id)){
      const cur=selected.get(id);cur.qty=merge?Math.max(1,Number(cur.qty||0)+qty):qty;selected.set(id,cur);
    }else selected.set(id,{...p,qty});
    renderSelected();
  }
  function renderSelected(){
    const body=$('selectedBody');body.innerHTML='';
    if(!selected.size){body.innerHTML='<tr><td colspan="5" class="lep-empty">Nenhum produto selecionado.</td></tr>';updateTotal();return;}
    [...selected.values()].sort((a,b)=>String(a.nome).localeCompare(String(b.nome),'pt-BR')).forEach(p=>{
      const tr=document.createElement('tr');tr.dataset.id=p.id;
      tr.innerHTML=`<td><strong>${esc(p.nome)}</strong><div class="lep-muted" style="font-size:12px">${esc(p.categoria||'')}</div></td><td>${esc(productCode(p))}</td><td class="num">${money(p.preco)}</td><td class="num"><input class="lep-qty" type="number" min="1" step="1" value="${Math.max(1,Math.round(Number(p.qty||1)))}" data-qty="${p.id}"></td><td><button class="btn btn-sm" type="button" data-remove="${p.id}">Remover</button></td>`;
      body.appendChild(tr);
    });
    body.querySelectorAll('[data-qty]').forEach(el=>{const syncQty=()=>{const id=Number(el.dataset.qty),p=selected.get(id);if(p){p.qty=Math.max(1,Math.round(Number(el.value||1)));el.value=p.qty;selected.set(id,p);updateTotal();}};el.addEventListener('input',syncQty);el.addEventListener('change',syncQty);});
    body.querySelectorAll('[data-remove]').forEach(el=>el.addEventListener('click',()=>{selected.delete(Number(el.dataset.remove));renderSelected();}));
    updateTotal();
  }
  function updateTotal(){let total=0;selected.forEach(p=>total+=Math.max(1,Math.round(Number(p.qty||1))));$('sumTotal').textContent=total+' etiqueta(s)';}
  async function searchProducts(){
    const q=$('productSearch').value.trim(),box=$('productResults');box.style.display='block';box.innerHTML='<div class="lep-empty">Pesquisando...</div>';
    try{const r=await fetch(`relatorio_etiquetas_produtos.php?ajax=products&q=${encodeURIComponent(q)}`,{cache:'no-store'});const j=await r.json();if(!j.ok)throw new Error(j.error||'Falha ao pesquisar.');
      if(!j.items.length){box.innerHTML='<div class="lep-empty">Nenhum produto encontrado.</div>';return;}
      box.innerHTML=j.items.map(p=>`<div class="lep-result"><div><strong>${esc(p.nome)}</strong><small>${esc(p.categoria||'')} · Código ${esc(productCode(p))} · ${money(p.preco)}</small></div><button class="btn btn-sm btn-primary" type="button" data-add-product="${p.id}">Adicionar</button></div>`).join('');
      box.querySelectorAll('[data-add-product]').forEach(btn=>btn.addEventListener('click',()=>{const p=j.items.find(x=>Number(x.id)===Number(btn.dataset.addProduct));if(p)addProduct(p,1,true);box.style.display='none';$('productSearch').focus();}));
    }catch(e){box.innerHTML=`<div class="lep-empty">${esc(e.message||'Falha ao pesquisar.')}</div>`;}
  }
  $('btnProductSearch').addEventListener('click',searchProducts);
  $('productSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchProducts();}});
  $('productSearch').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{if($('productSearch').value.trim().length>=2)searchProducts();},300);});
  document.addEventListener('click',e=>{if(!e.target.closest('.lep-search-row')&&!e.target.closest('.lep-results'))$('productResults').style.display='none';});

  let productModalItems=[];
  let productModalActiveIndex=-1;
  let productModalLastQuery='';
  const productModal=$('productModal');
  function resetProductModalResults(){
    productModalItems=[];
    productModalActiveIndex=-1;
    productModalLastQuery='';
    $('productModalResults').innerHTML='<div class="lep-empty">Digite um termo para pesquisar. A lista começa vazia.</div>';
  }
  function renderProductModalResults(){
    const box=$('productModalResults');
    if(!productModalItems.length){box.innerHTML='<div class="lep-empty">Nenhum produto encontrado.</div>';return;}
    box.innerHTML=`<table class="lep-product-modal-table"><thead><tr><th>Código</th><th>Produto</th><th>Preço</th><th>Ação</th></tr></thead><tbody>${productModalItems.map((p,index)=>`<tr ${index===productModalActiveIndex?'class="is-active"':''} data-product-modal-row="${index}" data-product-modal-id="${Number(p.id)}"><td>${esc(productCode(p))}<small>${p.codigo_barras?'Barras: '+esc(p.codigo_barras):''}</small></td><td><strong>${esc(p.nome)}</strong><small>${esc(p.categoria||'')}</small></td><td class="lep-product-modal-price">${money(p.preco)}</td><td class="lep-product-modal-select"><button class="btn btn-sm btn-primary" type="button" data-product-modal-id="${Number(p.id)}">Selecionar</button></td></tr>`).join('')}</tbody></table>`;
  }
  function setProductModalActive(index,scroll=true){
    if(!productModalItems.length){productModalActiveIndex=-1;return;}
    productModalActiveIndex=(Number(index)%productModalItems.length+productModalItems.length)%productModalItems.length;
    const rows=$('productModalResults').querySelectorAll('[data-product-modal-row]');
    rows.forEach((row,i)=>row.classList.toggle('is-active',i===productModalActiveIndex));
    if(scroll&&rows[productModalActiveIndex])rows[productModalActiveIndex].scrollIntoView({block:'nearest'});
  }
  function focusSelectedQuantity(id){
    setTimeout(()=>{const qty=document.querySelector(`[data-qty="${Number(id)}"]`);if(qty){qty.focus();qty.select();}},60);
  }
  function selectProductFromModal(id){
    const p=productModalItems.find(x=>Number(x.id)===Number(id));
    if(!p)return;
    addProduct(p,1,true);
    closeProductModal();
    focusSelectedQuantity(p.id);
  }
  async function searchProductsInModal(){
    const input=$('productModalSearch'),q=input.value.trim(),box=$('productModalResults');
    if(!q){resetProductModalResults();input.focus();return;}
    box.innerHTML='<div class="lep-empty">Pesquisando...</div>';
    try{
      const r=await fetch(`relatorio_etiquetas_produtos.php?ajax=products&q=${encodeURIComponent(q)}`,{cache:'no-store'});
      const j=await r.json();if(!j.ok)throw new Error(j.error||'Falha ao pesquisar.');
      productModalItems=Array.isArray(j.items)?j.items:[];
      productModalLastQuery=q;
      productModalActiveIndex=productModalItems.length?0:-1;
      renderProductModalResults();
      if(productModalItems.length)setProductModalActive(0,false);
      input.focus();
    }catch(e){productModalItems=[];productModalActiveIndex=-1;productModalLastQuery=q;box.innerHTML=`<div class="lep-empty">${esc(e.message||'Falha ao pesquisar.')}</div>`;}
  }
  function openProductModal(){
    if(!productModal)return;
    $('productResults').style.display='none';
    $('productModalSearch').value='';
    resetProductModalResults();
    productModal.style.display='flex';
    productModal.setAttribute('aria-hidden','false');
    setTimeout(()=>$('productModalSearch').focus(),80);
  }
  function closeProductModal(){
    if(!productModal)return;
    productModal.style.display='none';
    productModal.setAttribute('aria-hidden','true');
    resetProductModalResults();
  }
  $('btnProductModalSearch').addEventListener('click',openProductModal);
  $('closeProductModal').addEventListener('click',closeProductModal);
  $('btnProductModalDoSearch').addEventListener('click',searchProductsInModal);
  productModal.addEventListener('click',e=>{if(e.target===productModal)closeProductModal();});
  $('productModalSearch').addEventListener('input',()=>{productModalActiveIndex=-1;$('productModalResults').querySelectorAll('[data-product-modal-row]').forEach(row=>row.classList.remove('is-active'));});
  $('productModalSearch').addEventListener('keydown',e=>{
    if(e.key==='ArrowDown'&&productModalItems.length){e.preventDefault();setProductModalActive(productModalActiveIndex<0?0:productModalActiveIndex+1);return;}
    if(e.key==='ArrowUp'&&productModalItems.length){e.preventDefault();setProductModalActive(productModalActiveIndex<0?productModalItems.length-1:productModalActiveIndex-1);return;}
    if(e.key==='Enter'){e.preventDefault();const q=e.currentTarget.value.trim();if(productModalItems.length&&productModalActiveIndex>=0&&q===productModalLastQuery)selectProductFromModal(productModalItems[productModalActiveIndex].id);else searchProductsInModal();return;}
    if(e.key==='Escape'){e.preventDefault();closeProductModal();}
  });
  $('productModalResults').addEventListener('mousemove',e=>{const row=e.target.closest('[data-product-modal-row]');if(row)setProductModalActive(Number(row.dataset.productModalRow),false);});
  $('productModalResults').addEventListener('click',e=>{const target=e.target.closest('[data-product-modal-id]');if(target)selectProductFromModal(Number(target.dataset.productModalId));});

  function updateTemplateSummary(){const o=$('templateSelect').selectedOptions[0];if(!o)return;$('sumLabel').textContent=`${Number(o.dataset.lw).toLocaleString('pt-BR')} × ${Number(o.dataset.lh).toLocaleString('pt-BR')} mm`;$('sumCols').textContent=o.dataset.cols;$('sumPaper').textContent=(o.dataset.paper==='A4'?'Folha A4':`${Number(o.dataset.pw).toLocaleString('pt-BR')} mm`);$('editTemplateBtn').href='relatorio_etiquetas_modelo.php?id='+encodeURIComponent(o.value);}
  $('templateSelect').addEventListener('change',updateTemplateSummary);updateTemplateSummary();
  $('clearBtn').addEventListener('click',()=>{if(selected.size&&!confirm('Limpar todos os produtos selecionados?'))return;selected.clear();renderSelected();});

  $('printBtn').addEventListener('click',()=>{
    // Leia novamente os campos visíveis no instante do clique. Isso evita imprimir
    // uma quantidade anterior quando o usuário digita e clica em Imprimir sem
    // sair do campo primeiro.
    document.querySelectorAll('[data-qty]').forEach(el=>{const id=Number(el.dataset.qty),p=selected.get(id);if(p){p.qty=Math.max(1,Math.round(Number(el.value||1)));el.value=p.qty;selected.set(id,p);}});
    updateTotal();
    if(!selected.size){toast('Selecione pelo menos um produto.');return;}
    const previewName='nexaEtiquetaPreview';
    const preview=window.open('about:blank',previewName,'noopener=no');
    if(!preview){toast('O navegador bloqueou a janela de visualização. Libere pop-ups para o Nexa e tente novamente.');return;}
    const form=document.createElement('form');form.method='post';form.action='relatorio_etiquetas_imprimir.php';form.target=previewName;
    const t=document.createElement('input');t.type='hidden';t.name='template_id';t.value=$('templateSelect').value;form.appendChild(t);
    selected.forEach(p=>{const i=document.createElement('input');i.type='hidden';i.name=`qty[${p.id}]`;i.value=Math.max(1,Math.round(Number(p.qty||1)));form.appendChild(i);});
    document.body.appendChild(form);form.submit();form.remove();
    try{preview.focus();}catch(_e){}
  });

  const modal=$('noteModal');
  function openNote(){modal.style.display='flex';modal.setAttribute('aria-hidden','false');$('noteSearch').value='';loadNotes();setTimeout(()=>$('noteSearch').focus(),80);}
  function closeNote(){modal.style.display='none';modal.setAttribute('aria-hidden','true');}
  $('btnImportNote').addEventListener('click',openNote);$('closeNote').addEventListener('click',closeNote);modal.addEventListener('click',e=>{if(e.target===modal)closeNote();});
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'&&modal.style.display==='flex'){e.preventDefault();closeNote();return;}
    if(e.key==='F8'){
      e.preventDefault();
      if(productModal&&productModal.style.display==='flex'){$('productModalSearch').focus();return;}
      if(modal.style.display==='flex')return;
      openProductModal();
    }
  });
  $('btnNoteSearch').addEventListener('click',loadNotes);$('noteSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadNotes();}});
  async function loadNotes(){const list=$('noteList');list.innerHTML='<div class="lep-empty">Carregando notas...</div>';try{const r=await fetch(`relatorio_etiquetas_produtos.php?ajax=notes&q=${encodeURIComponent($('noteSearch').value.trim())}`,{cache:'no-store'});const j=await r.json();if(!j.ok)throw new Error(j.error||'Falha ao consultar notas.');if(!j.items.length){list.innerHTML='<div class="lep-empty">Nenhuma Nota de Entrada encontrada.</div>';return;}
    list.innerHTML=j.items.map(n=>`<div class="lep-note"><div><div class="lep-note-title">NF-e ${esc(n.number||'-')} · Série ${esc(n.series||'-')}</div><div class="lep-note-meta">${esc(n.supplier_name||'Fornecedor não informado')} · ${esc(n.entry_date||n.issue_date||'')} · ${money(n.total_invoice)}</div><span class="lep-badge" style="margin-top:6px">${esc(n.status||'')}</span></div><div class="lep-note-actions"><button class="btn btn-sm btn-primary" type="button" data-note-total="${n.id}">Quantidade da nota</button><button class="btn btn-sm" type="button" data-note-one="${n.id}">1 por produto</button></div></div>`).join('');
    list.querySelectorAll('[data-note-total]').forEach(b=>b.addEventListener('click',()=>importNote(Number(b.dataset.noteTotal),'total')));list.querySelectorAll('[data-note-one]').forEach(b=>b.addEventListener('click',()=>importNote(Number(b.dataset.noteOne),'one')));
  }catch(e){list.innerHTML=`<div class="lep-empty">${esc(e.message||'Falha ao consultar notas.')}</div>`;}}
  async function importNote(id,mode){try{const r=await fetch(`relatorio_etiquetas_produtos.php?ajax=note_items&id=${encodeURIComponent(id)}`,{cache:'no-store'});const j=await r.json();if(!j.ok)throw new Error(j.error||'Falha ao carregar itens da nota.');if(!j.items.length){throw new Error('A nota não possui produtos vinculados ao cadastro do Nexa.');}j.items.forEach(p=>addProduct(p,mode==='one'?1:Math.max(1,Math.round(Number(p.note_quantity||1))),true));closeNote();let msg=`${j.items.length} produto(s) importado(s) da nota.`;if(Number(j.unlinked||0)>0)msg+=` ${j.unlinked} item(ns) sem vínculo foram ignorados.`;toast(msg);}catch(e){toast(e.message||'Falha ao importar a nota.');}}
  renderSelected();
})();
</script>
<?php admin_footer(); ?>
