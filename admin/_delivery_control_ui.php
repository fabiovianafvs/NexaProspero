<?php
declare(strict_types=1);
require_once __DIR__ . '/_chrome.php';

if (!function_exists('dc_e')) { function dc_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('dc_money')) { function dc_money($v): string { return 'R$ '.number_format((float)$v,2,',','.'); } }
if (!function_exists('dc_qty')) { function dc_qty($v): string { $n=number_format((float)$v,4,',','.'); return rtrim(rtrim($n,'0'),','); } }
if (!function_exists('dc_date')) { function dc_date($v): string { $s=(string)$v; return $s!=='' ? date('d/m/Y',strtotime($s)) : '—'; } }
if (!function_exists('dc_datetime')) { function dc_datetime($v): string { $s=(string)$v; return $s!=='' ? date('d/m/Y H:i',strtotime($s)) : '—'; } }
if (!function_exists('dc_user_id')) { function dc_user_id(): int { return function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id']??0); } }
if (!function_exists('dc_csrf_token')) { function dc_csrf_token(): string { if(session_status()!==PHP_SESSION_ACTIVE)@session_start(); if(empty($_SESSION['csrf_delivery_control']))$_SESSION['csrf_delivery_control']=bin2hex(random_bytes(24)); return (string)$_SESSION['csrf_delivery_control']; } }
if (!function_exists('dc_csrf_check')) { function dc_csrf_check(string $token): void { if($token==='' || !hash_equals(dc_csrf_token(),$token)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.'); } }
if (!function_exists('dc_status_label')) { function dc_status_label(string $s): string { return match(strtoupper($s)){'PENDENTE'=>'Pendente','PROGRAMADA'=>'Programada','ENTREGA_PARCIAL'=>'Entrega parcial','CONCLUIDA'=>'Concluída','GERADA'=>'Gerada','EM_ROMANEIO'=>'Em romaneio','SEPARADA'=>'Separada','SAIU_PARA_ENTREGA'=>'Saiu para entrega','ENTREGUE'=>'Entregue','CANCELADA'=>'Cancelada','ABERTO'=>'Aberto','FECHADO'=>'Fechado','EM_ROTA'=>'Em rota','PREPARADA'=>'Preparada','PROCESSANDO'=>'Processando','AUTORIZADA'=>'Autorizada','ERRO'=>'Erro',default=>$s}; } }
if (!function_exists('dc_status_class')) { function dc_status_class(string $s): string { return match(strtoupper($s)){'CONCLUIDA','ENTREGUE','AUTORIZADA'=>'ok','CANCELADA','ERRO'=>'bad','ENTREGA_PARCIAL','PROGRAMADA','EM_ROMANEIO','SEPARADA','SAIU_PARA_ENTREGA','FECHADO','EM_ROTA','PREPARADA','PROCESSANDO'=>'warn',default=>'info'}; } }

/* Preferências dos grids do módulo de Entregas.
 * São gravadas em app_config por empresa + usuário para acompanharem o usuário
 * em qualquer navegador/máquina, sem depender de localStorage. */
if (!function_exists('dc_grid_key')) {
function dc_grid_key(string $gridId,int $userId): string {
    $gridId=preg_replace('/[^a-z0-9_\-]/i','_',strtolower(trim($gridId))) ?: 'grid';
    return $userId>0 ? 'ui_delivery_grid_'.$gridId.'_user_'.$userId : '';
}}
if (!function_exists('dc_grid_preferences_normalize')) {
function dc_grid_preferences_normalize(array $prefs,array $allowed): array {
    $allowed=array_values(array_unique(array_map('strval',$allowed)));
    $visible=[];foreach((array)($prefs['visible']??[]) as $c){$c=(string)$c;if(in_array($c,$allowed,true)&&!in_array($c,$visible,true))$visible[]=$c;}
    $order=[];foreach((array)($prefs['order']??[]) as $c){$c=(string)$c;if(in_array($c,$allowed,true)&&!in_array($c,$order,true))$order[]=$c;}
    $widths=[];foreach((array)($prefs['widths']??[]) as $c=>$w){$c=(string)$c;$w=(int)$w;if(in_array($c,$allowed,true)&&$w>=50&&$w<=900)$widths[$c]=$w;}
    return ['visible'=>$visible,'order'=>$order,'widths'=>$widths];
}}
if (!function_exists('dc_grid_preferences_load')) {
function dc_grid_preferences_load(PDO $pdo,int $companyId,string $gridId,array $allowed): array {
    $userId=dc_user_id();$key=dc_grid_key($gridId,$userId);$defaults=['visible'=>$allowed,'order'=>$allowed,'widths'=>[],'saved'=>false];
    if($companyId<=0||$userId<=0||$key==='')return $defaults;
    try{$st=$pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');$st->execute([$companyId,$key]);$raw=$st->fetchColumn();if(!is_string($raw)||trim($raw)==='')return $defaults;$d=json_decode($raw,true);if(!is_array($d))return $defaults;$n=dc_grid_preferences_normalize($d,$allowed);if(!$n['visible'])$n['visible']=$allowed;if(!$n['order'])$n['order']=$allowed;$n['saved']=true;return $n;}catch(Throwable $e){return $defaults;}
}}
if (!function_exists('dc_grid_toolbar')) {
function dc_grid_toolbar(string $gridId,array $columns,array $prefs): void {
    $allowed=array_keys($columns);$visible=(array)($prefs['visible']??$allowed);$gid=dc_e($gridId); ?>
    <div class="dc-grid-toolbar no-print">
      <span class="dc-grid-layout-hint">Arraste o cabeçalho para mover • arraste a borda para ajustar</span>
      <span class="dc-grid-pref-status" id="dcGridStatus-<?=$gid?>"><?=!empty($prefs['saved'])?'Layout salvo no usuário':''?></span>
      <details class="dc-column-picker">
        <summary class="dc-btn sm">☷ Colunas</summary>
        <div class="dc-column-picker-menu">
          <strong>Colunas visíveis</strong>
          <?php foreach($columns as $key=>$label): if($key==='acoes')continue;?><label><input type="checkbox" data-dc-column-toggle="<?=$gid?>" value="<?=dc_e($key)?>" <?=in_array($key,$visible,true)?'checked':''?>> <span><?=dc_e($label)?></span></label><?php endforeach;?>
          <button class="dc-btn sm" type="button" data-dc-grid-reset="<?=$gid?>">Restaurar padrão</button>
        </div>
      </details>
    </div>
<?php }}
if (!function_exists('dc_grid_script')) {
function dc_grid_script(string $gridId,array $columns,array $prefs,array $defaultWidths=[]): void {
    $gridDom='dcGrid-'.$gridId;$allowed=array_keys($columns);$csrf=dc_csrf_token(); ?>
<script>
(function(){
 const grid=document.getElementById(<?=json_encode($gridDom)?>);if(!grid)return;
 const gridId=<?=json_encode($gridId)?>,csrf=<?=json_encode($csrf)?>;
 const available=<?=json_encode(array_values($allowed),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const server=<?=json_encode($prefs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>||{};
 const defaults=<?=json_encode($defaultWidths,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>||{};
 const actions='acoes';const draggable=available.filter(c=>c!==actions);
 let visible=Array.isArray(server.visible)&&server.visible.length?available.filter(c=>server.visible.includes(c)):available.slice();
 if(!visible.length)visible=available.slice();
 let order=draggable.slice();if(Array.isArray(server.order)){const valid=server.order.filter(c=>draggable.includes(c));order=valid.concat(draggable.filter(c=>!valid.includes(c)));}
 let widths=(server.widths&&typeof server.widths==='object'&&!Array.isArray(server.widths))?Object.assign({},server.widths):{};
 const toggles=Array.from(document.querySelectorAll('[data-dc-column-toggle="'+CSS.escape(gridId)+'"]'));
 const status=document.getElementById('dcGridStatus-'+gridId);const wrap=grid.closest('.dc-advanced-wrap');let timer=null,seq=0;
 function setStatus(t,c){if(!status)return;status.textContent=t||'';status.className='dc-grid-pref-status'+(c?' '+c:'');}
 function payload(){const w={};Object.keys(widths).forEach(c=>{const n=Number(widths[c]);if(available.includes(c)&&Number.isFinite(n))w[c]=Math.round(n);});return{visible:visible.filter(c=>available.includes(c)),order:order.filter(c=>draggable.includes(c)),widths:w};}
 async function save(){const my=++seq;setStatus('Salvando…','saving');try{const body=new URLSearchParams();body.set('csrf',csrf);body.set('grid_id',gridId);body.set('allowed',JSON.stringify(available));body.set('preferences',JSON.stringify(payload()));const r=await fetch('delivery_grid_preferences.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:body.toString()});const d=await r.json().catch(()=>({}));if(!r.ok||!d.ok)throw new Error(d.message||'Falha ao salvar');if(my===seq){setStatus('Salvo no usuário','saved');setTimeout(()=>{if(my===seq)setStatus('','');},1800);}}catch(e){if(my===seq)setStatus('Não foi possível salvar','error');}}
 function schedule(ms){if(timer)clearTimeout(timer);timer=setTimeout(save,typeof ms==='number'?ms:180);}
 function fullOrder(){return order.concat(available.includes(actions)?[actions]:[]);}
 function applyOrder(){const ord=fullOrder();grid.querySelectorAll('tr').forEach(row=>{const by={};Array.from(row.children).forEach(cell=>{const c=cell.getAttribute&&cell.getAttribute('data-col');if(c)by[c]=cell;});if(!Object.keys(by).length)return;ord.forEach(c=>{if(by[c])row.appendChild(by[c]);});});}
 function widthFor(c){const n=Number(widths[c]);if(Number.isFinite(n)&&n>0)return n;const d=Number(defaults[c]);return Number.isFinite(d)&&d>0?d:(c===actions?190:125);}
 function refreshWidth(){if(!wrap)return;let total=0;fullOrder().forEach(c=>{if(c!==actions&&!visible.includes(c))return;total+=widthFor(c);});grid.style.width=Math.max(wrap.clientWidth||0,total)+'px';}
 function applyWidth(c,px,persist){const min=c===actions?145:70,max=c==='cliente'?700:560,v=Math.max(min,Math.min(max,Math.round(px)));if(persist){widths[c]=v;schedule(160);}grid.querySelectorAll('[data-col="'+CSS.escape(c)+'"]').forEach(el=>{el.style.width=v+'px';el.style.minWidth=v+'px';el.style.maxWidth=v+'px';});refreshWidth();}
 function applyWidths(){fullOrder().forEach(c=>applyWidth(c,widthFor(c),false));refreshWidth();}
 function applyVisible(){toggles.forEach(cb=>cb.checked=visible.includes(cb.value));available.forEach(c=>{if(c===actions)return;grid.querySelectorAll('[data-col="'+CSS.escape(c)+'"]').forEach(el=>el.classList.toggle('dc-col-hidden',!visible.includes(c)));});refreshWidth();}
 toggles.forEach(cb=>cb.addEventListener('change',function(){if(this.checked){if(!visible.includes(this.value))visible.push(this.value);}else{const next=visible.filter(v=>v!==this.value);if(next.filter(v=>v!==actions).length===0){this.checked=true;return;}visible=next;}applyVisible();schedule(100);}));
 let dragging=null,dropAfter=false;function clearMarks(){grid.querySelectorAll('thead th').forEach(th=>th.classList.remove('dc-col-drop-before','dc-col-drop-after','dc-col-dragging'));}
 draggable.forEach(c=>{const th=grid.querySelector('thead th[data-col="'+CSS.escape(c)+'"]');if(!th)return;const host=th.querySelector('.dc-grid-th-inner')||th;const h=document.createElement('span');h.className='dc-col-drag-handle';h.textContent='⋮⋮';h.title='Arrastar coluna';h.draggable=true;host.insertBefore(h,host.firstChild);h.addEventListener('dragstart',e=>{dragging=c;th.classList.add('dc-col-dragging');if(e.dataTransfer){e.dataTransfer.effectAllowed='move';try{e.dataTransfer.setData('text/plain',c);}catch(x){}}});h.addEventListener('dragend',()=>{dragging=null;clearMarks();});});
 grid.querySelectorAll('thead th[data-col]').forEach(th=>{const target=th.dataset.col||'';if(!target||target===actions)return;th.addEventListener('dragover',e=>{if(!dragging||dragging===target)return;e.preventDefault();const r=th.getBoundingClientRect();dropAfter=e.clientX>r.left+r.width/2;clearMarks();th.classList.add(dropAfter?'dc-col-drop-after':'dc-col-drop-before');const src=grid.querySelector('thead th[data-col="'+CSS.escape(dragging)+'"]');if(src)src.classList.add('dc-col-dragging');});th.addEventListener('drop',e=>{if(!dragging||dragging===target)return;e.preventDefault();const next=order.filter(c=>c!==dragging);let idx=next.indexOf(target);if(idx<0)idx=next.length;if(dropAfter)idx++;next.splice(idx,0,dragging);order=next;applyOrder();applyWidths();schedule(100);dragging=null;clearMarks();});});
 draggable.forEach(c=>{const th=grid.querySelector('thead th[data-col="'+CSS.escape(c)+'"]');if(!th)return;const r=document.createElement('span');r.className='dc-col-resizer';r.title='Arraste para ajustar a largura';th.appendChild(r);r.addEventListener('pointerdown',e=>{if(e.button!==0)return;e.preventDefault();e.stopPropagation();const x=e.clientX,w=th.getBoundingClientRect().width||widthFor(c);grid.classList.add('dc-resizing-columns');document.body.style.userSelect='none';const move=ev=>applyWidth(c,w+(ev.clientX-x),false);const up=ev=>{document.removeEventListener('pointermove',move);grid.classList.remove('dc-resizing-columns');document.body.style.userSelect='';applyWidth(c,w+(ev.clientX-x),true);};document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true});});});
 const reset=document.querySelector('[data-dc-grid-reset="'+CSS.escape(gridId)+'"]');if(reset)reset.addEventListener('click',()=>{visible=available.slice();order=draggable.slice();widths={};applyOrder();applyVisible();applyWidths();schedule(20);});
 applyOrder();applyVisible();applyWidths();window.addEventListener('resize',refreshWidth);
})();
</script>
<?php }}

if (!function_exists('dc_page_start')) {
function dc_page_start(string $title,string $active='controle_entregas'): void { admin_topbar($title,$active); ?>
<style>
.dc-wrap{max-width:1480px;margin:0 auto;padding:18px}.dc-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}.dc-head h1{font-size:24px;margin:0}.dc-spacer{flex:1}.dc-card{background:#fff;border:1px solid #dbe3ed;border-radius:12px;margin-bottom:16px;box-shadow:0 2px 8px rgba(15,23,42,.04)}.dc-card-head{padding:13px 16px;border-bottom:1px solid #e7edf4;font-weight:800}.dc-card-head.with-grid-tools{display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap}.dc-card-body{padding:16px}.dc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.dc-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.dc-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.dc-delivery-filter{display:grid;grid-template-columns:minmax(260px,1.45fr) minmax(145px,.65fr) minmax(132px,.52fr) minmax(132px,.52fr) minmax(132px,.52fr) auto;gap:10px;align-items:end}.dc-field label{display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:5px}.dc-field input,.dc-field select,.dc-field textarea{width:100%;border:1px solid #b9c6d5;border-radius:8px;padding:9px 10px;background:#fff;box-sizing:border-box}.dc-field textarea{min-height:74px;resize:vertical}.dc-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #b8c5d3;background:#fff;color:#1e293b;text-decoration:none;border-radius:8px;padding:8px 12px;font-weight:700;cursor:pointer}.dc-btn.primary{background:#1769aa;color:#fff;border-color:#1769aa}.dc-btn.danger{background:#fff1f2;color:#b42318;border-color:#fecdd3}.dc-btn.sm{padding:5px 9px;font-size:12px}.dc-table-wrap{overflow:auto}.dc-table{width:100%;border-collapse:collapse;font-size:13px}.dc-table th,.dc-table td{padding:10px;border-bottom:1px solid #e8edf3;text-align:left;vertical-align:top}.dc-table th{font-size:11px;text-transform:uppercase;color:#64748b;white-space:nowrap}.dc-table .num{text-align:right;white-space:nowrap}.dc-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#e2e8f0;color:#334155}.dc-badge.ok{background:#dcfce7;color:#166534}.dc-badge.warn{background:#fef3c7;color:#92400e}.dc-badge.bad{background:#fee2e2;color:#991b1b}.dc-badge.info{background:#dbeafe;color:#1e40af}.dc-muted{color:#64748b}.dc-alert{padding:11px 13px;border-radius:8px;margin-bottom:12px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a}.dc-alert.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.dc-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.dc-kpi{background:#fff;border:1px solid #dbe3ed;border-radius:12px;padding:14px}.dc-kpi b{display:block;font-size:24px;margin-top:4px}.dc-actions{display:flex;gap:7px;flex-wrap:wrap}.dc-filter-actions{display:flex;gap:7px;align-items:end;white-space:nowrap}.dc-route{font-weight:900;font-size:16px}.dc-address{line-height:1.4}.dc-print-only{display:none}
/* Grid avançado compartilhado do módulo de Entregas */
.dc-advanced-wrap{overflow-x:auto;overflow-y:visible;border:1px solid #e2e8f0;border-radius:12px}.dc-data-grid{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;font-size:12px}.dc-data-grid th,.dc-data-grid td{padding:9px 10px;border-bottom:1px solid #e9eff8;border-right:1px solid #f1f5f9;vertical-align:middle;text-align:left;overflow:hidden;text-overflow:ellipsis}.dc-data-grid th:last-child,.dc-data-grid td:last-child{border-right:0}.dc-data-grid thead th{position:sticky;top:0;z-index:15;background:#f7faff;color:#243b70;font-size:10px;text-transform:uppercase;letter-spacing:.025em;font-weight:900}.dc-data-grid tbody tr:nth-child(odd) td{background:#fff}.dc-data-grid tbody tr:nth-child(even) td{background:#eef3f9}.dc-data-grid tbody tr:hover td{background:#dbe7f5}.dc-data-grid .num{text-align:right;white-space:nowrap}.dc-grid-th-inner{display:flex;align-items:center;gap:5px;min-height:22px}.dc-col-drag-handle{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:14px;height:22px;color:#94a3b8;cursor:grab;border-radius:4px;font-size:12px;line-height:1;letter-spacing:-2px}.dc-col-drag-handle:hover{color:#3558c9;background:#e8efff}.dc-col-resizer{position:absolute;top:0;right:-3px;z-index:110;width:7px;height:100%;cursor:col-resize;touch-action:none}.dc-col-resizer:after{content:"";position:absolute;top:20%;bottom:20%;left:3px;width:1px;background:#c8d4e8;opacity:.65}.dc-col-resizer:hover:after,.dc-resizing-columns .dc-col-resizer:after{background:#3558c9;opacity:1;width:2px}.dc-resizing-columns,.dc-resizing-columns *{cursor:col-resize!important}.dc-col-drop-before{box-shadow:inset 3px 0 0 #3558c9}.dc-col-drop-after{box-shadow:inset -3px 0 0 #3558c9}.dc-col-dragging{opacity:.58}.dc-col-hidden{display:none!important}.dc-grid-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-left:auto}.dc-grid-layout-hint{font-size:11px;color:#64748b;font-weight:600}.dc-grid-pref-status{font-size:11px;color:#64748b;font-weight:800;min-width:100px;text-align:right}.dc-grid-pref-status.saving{color:#a16207}.dc-grid-pref-status.saved{color:#047857}.dc-grid-pref-status.error{color:#b91c1c}.dc-column-picker{position:relative}.dc-column-picker>summary{list-style:none}.dc-column-picker>summary::-webkit-details-marker{display:none}.dc-column-picker-menu{position:absolute;right:0;top:calc(100% + 7px);z-index:130;min-width:230px;max-height:390px;overflow:auto;padding:12px;background:#fff;border:1px solid #d9e3f2;border-radius:12px;box-shadow:0 16px 38px rgba(15,35,80,.22)}.dc-column-picker-menu strong{display:block;color:#18336f;margin-bottom:8px}.dc-column-picker-menu label{display:flex;gap:8px;align-items:center;padding:6px 2px;font-size:12px;color:#334155;cursor:pointer}.dc-column-picker-menu label input{width:16px;height:16px}.dc-column-picker-menu .dc-btn{width:100%;margin-top:8px}.dc-data-grid .dc-actions-col{position:sticky;right:0;z-index:10;box-shadow:-5px 0 10px rgba(15,35,80,.05)}.dc-data-grid thead .dc-actions-col{background:#f7faff!important}.dc-data-grid tbody tr:nth-child(odd) .dc-actions-col{background:#fff!important}.dc-data-grid tbody tr:nth-child(even) .dc-actions-col{background:#eef3f9!important}.dc-data-grid tbody tr:hover .dc-actions-col{background:#dbe7f5!important}.dc-empty-grid{text-align:center!important;color:#64748b;padding:28px!important;background:#fbfdff!important}
@media(max-width:1120px){.dc-delivery-filter{grid-template-columns:repeat(3,minmax(0,1fr))}.dc-delivery-filter .dc-filter-actions{grid-column:span 3;justify-content:flex-end}}
@media(max-width:900px){.dc-grid,.dc-grid.three,.dc-grid.two,.dc-kpis,.dc-delivery-filter{grid-template-columns:1fr}.dc-delivery-filter .dc-filter-actions{grid-column:auto;justify-content:flex-start}.dc-wrap{padding:10px}.dc-table{min-width:780px}.dc-grid-layout-hint{display:none}}
@media print{.no-print,header,nav,.admin-topbar,.sidebar{display:none!important}.dc-wrap{max-width:none;padding:0}.dc-card{box-shadow:none}.dc-print-only{display:block}}
</style><main class="dc-wrap">
<?php }}
if (!function_exists('dc_page_end')) { function dc_page_end(): void { echo '</main>'; admin_footer(); } }
