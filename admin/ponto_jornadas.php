<?php
declare(strict_types=1);

// Ponto Eletrônico > Jornadas: horários previstos por dia da semana (ou "todos os dias",
// para escalas de ciclo como 12x36), com tolerâncias. Carga diária/semanal calculadas.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$cid = ponto_guard($pdo, 'ponto_jornadas');
$status = in_array((string)($_GET['s'] ?? ''), ['ativos', 'inativos', 'todos'], true) ? (string)$_GET['s'] : 'ativos';
$back = 'ponto_jornadas.php' . ($status !== 'ativos' ? '?s=' . $status : '');

ponto_post_handle($back, function () use ($pdo, $cid) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save') {
    $isNew = (int)($_POST['id'] ?? 0) === 0;
    $periodos = [];
    foreach ((array)($_POST['p_dia'] ?? []) as $i => $dia) {
      $periodos[] = ['dia' => (int)$dia, 'ini' => (string)($_POST['p_ini'][$i] ?? ''), 'fim' => (string)($_POST['p_fim'][$i] ?? '')];
    }
    ponto_jornada_save($pdo, $cid, $_POST + ['periodos' => $periodos]);
    return $isNew ? 'Jornada cadastrada.' : 'Jornada alterada.';
  }
  if ($action === 'status') {
    $on = (string)($_POST['on'] ?? '') === '1';
    ponto_simple_status($pdo, $cid, 'jornadas', (int)($_POST['id'] ?? 0), $on);
    return $on ? 'Jornada reativada.' : 'Jornada inativada.';
  }
  throw new RuntimeException('Ação inválida.');
});

$rows = ponto_jornada_list($pdo, $cid, $status);
$resumo = static function (array $per): string {
  if (isset($per[0])) return 'Todos os dias: ' . implode('  ', array_map(static fn($p) => $p['ini'] . '–' . $p['fim'], $per[0]));
  // Agrupa dias seguidos com o mesmo horário: "Seg–Sex 08:00–12:00 13:00–17:48 · Sáb 08:00–12:00".
  $groups = [];
  for ($d = 1; $d <= 7; $d++) {
    if (empty($per[$d])) continue;
    $sig = implode(' ', array_map(static fn($p) => $p['ini'] . '–' . $p['fim'], $per[$d]));
    $last = count($groups) - 1;
    if ($last >= 0 && $groups[$last]['sig'] === $sig && $groups[$last]['to'] === $d - 1) $groups[$last]['to'] = $d;
    else $groups[] = ['from' => $d, 'to' => $d, 'sig' => $sig];
  }
  return implode(' · ', array_map(static fn($g) => PONTO_DIAS_CURTOS[$g['from']] . ($g['to'] > $g['from'] ? '–' . PONTO_DIAS_CURTOS[$g['to']] : '') . ' ' . str_replace(' ', '  ', $g['sig']), $groups));
};

ponto_start($pdo, 'ponto_jornadas', 'Jornadas', 'Horário contratual de cada dia. Use "Todos os dias" para plantões de escalas em ciclo (ex.: 12x36). Horário que passa da meia-noite (ex.: 22:00–05:00) é aceito.', '<button class="pt-btn pri" type="button" onclick="pjNew()">+ Cadastrar</button>');
?>
<style>
.pj-day{display:grid;grid-template-columns:96px minmax(0,1fr) 70px;gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid #eef2f7}
.pj-day b{font-size:13.5px;color:#0b1f5b}.pj-per{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.pj-p{display:flex;gap:4px;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:4px 6px}
.pj-p input{width:104px!important;min-height:34px!important;padding:0 6px!important}
.pj-p button{border:0;background:none;color:#b91c1c;font-weight:900;cursor:pointer;font-size:16px}
.pj-tot{text-align:right;font-weight:900;color:#0b1f5b;font-variant-numeric:tabular-nums}
.pj-off{color:#94a3b8;font-size:13px}
.pj-sum{display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;font-weight:800;color:#0b1f5b}
@media(max-width:760px){.pj-day{grid-template-columns:1fr}.pj-tot{text-align:left}}
</style>
<section class="pt-card">
  <form method="get" class="pt-search">
    <label class="f" style="width:170px">Situação<select name="s" onchange="this.form.submit()"><option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativas</option><option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativas</option><option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todas</option></select></label>
    <button class="pt-btn pri" type="button" onclick="pjNew()">Cadastrar</button>
  </form>
  <?php if (!$rows): ?><div class="pt-empty">Nenhuma jornada cadastrada. Exemplo: "Administrativa" — segunda a sexta, 08:00–12:00 e 13:00–17:48 (44 horas semanais).</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th style="width:22%">Jornada</th><th>Horários</th><th>Dia / semana</th><th>Tolerância</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $on = (int)$r['status'] === 1; ?>
      <tr class="<?= $on ? '' : 'off' ?>">
        <td><b><?= ponto_h($r['descricao']) ?></b><?= $on ? '' : ' <span class="pt-badge n">Inativa</span>' ?></td>
        <td data-l="Horários"><?= ponto_h($resumo($r['periodos'])) ?></td>
        <td data-l="Carga"><?= ponto_h(ponto_minutes_label((int)$r['carga_diaria_minutos'])) ?><?= (int)$r['carga_semanal_minutos'] ? ' / ' . ponto_h(ponto_minutes_label((int)$r['carga_semanal_minutos'])) : '' ?></td>
        <td data-l="Tolerância"><?= (int)$r['tolerancia_entrada'] ?>/<?= (int)$r['tolerancia_saida'] ?> min</td>
        <td class="act"><button class="pt-btn sm" type="button" onclick="pjEdit(<?= (int)$r['id'] ?>)">Editar</button>
          <form method="post" style="display:inline" data-confirm="<?= $on ? 'Inativar esta jornada? Quem já usa continua com ela; ela só deixa de aparecer para novos cadastros.' : '' ?>"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>"><button class="pt-btn sm <?= $on ? 'red' : '' ?>" type="submit"><?= $on ? 'Inativar' : 'Reativar' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<dialog class="pt-modal wide" id="pjModal">
  <form method="post" id="pjForm">
    <div class="pt-mh"><h3 id="pjTitle">Jornada</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
      <div class="pt-grid three">
        <label class="f s2">Descrição *<input type="text" name="descricao" maxlength="80" required placeholder="Ex.: Administrativa"></label>
        <label class="f">Horários<select id="pjMode"><option value="semana">Por dia da semana</option><option value="todos">Mesmo horário todos os dias (escalas em ciclo)</option></select></label>
        <label class="f">Tolerância entrada (min)<input type="number" name="tolerancia_entrada" min="0" max="60" value="5"></label>
        <label class="f">Tolerância saída (min)<input type="number" name="tolerancia_saida" min="0" max="60" value="5"></label>
        <label class="f">Tolerância intervalo (min)<input type="number" name="tolerancia_intervalo" min="0" max="60" value="0"></label>
      </div>
      <p class="pt-hint">Pela CLT, variações de até 5 minutos por marcação (máximo de 10 no dia) não contam como atraso nem hora extra. As tolerâncias serão usadas na apuração do ponto.</p>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin:6px 0" id="pjQuick">
        <button class="pt-btn sm" type="button" onclick="pjCopy([3,4,5,6])">Copiar segunda para terça a sexta</button>
        <button class="pt-btn sm" type="button" onclick="pjCopy([3,4,5,6,7])">Copiar segunda para terça a sábado</button>
      </div>
      <div id="pjDays"></div>
      <div class="pj-sum"><span id="pjDaily"></span><span id="pjWeekly"></span></div>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Salvar</button></div>
  </form>
</dialog>
<script>
(function(){
  const rows=<?= json_encode(array_column($rows, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const names={0:'Todos os dias',1:'Domingo',2:'Segunda',3:'Terça',4:'Quarta',5:'Quinta',6:'Sexta',7:'Sábado'};
  const form=document.getElementById('pjForm'), box=document.getElementById('pjDays'), mode=document.getElementById('pjMode');
  let data={};
  const mins=(a,b)=>{const x=a.split(':'),y=b.split(':');if(x.length<2||y.length<2)return 0;const s=+x[0]*60+ +x[1], e=+y[0]*60+ +y[1];return e>s?e-s:(e===s?0:1440-s+e);};
  const lab=m=>m?Math.floor(m/60)+'h'+(m%60?String(m%60).padStart(2,'0'):''):'—';
  function render(){
    const days=mode.value==='todos'?[0]:[2,3,4,5,6,7,1];
    document.getElementById('pjQuick').hidden=mode.value==='todos';
    box.innerHTML=days.map(d=>{const ps=data[d]||[];return '<div class="pj-day" data-d="'+d+'"><b>'+names[d]+'</b><div class="pj-per">'+
      (ps.length?ps.map((p,i)=>'<span class="pj-p"><input type="time" value="'+p.ini+'" data-i="'+i+'" data-k="ini"> às <input type="time" value="'+p.fim+'" data-i="'+i+'" data-k="fim"><button type="button" data-del="'+i+'" title="Remover período">×</button></span>').join(''):'<span class="pj-off">Folga</span>')+
      (ps.length<4?' <button class="pt-btn sm" type="button" data-add>+ período</button>':'')+'</div><span class="pj-tot"></span></div>';}).join('');
    totals();
  }
  function totals(){
    let week=0, max=0;
    box.querySelectorAll('.pj-day').forEach(row=>{const d=+row.dataset.d;const t=(data[d]||[]).reduce((s,p)=>s+mins(p.ini||'',p.fim||''),0);row.querySelector('.pj-tot').textContent=t?lab(t):'';if(d>0)week+=t;max=Math.max(max,t);});
    document.getElementById('pjDaily').textContent='Maior dia: '+lab(max);
    document.getElementById('pjWeekly').textContent=mode.value==='todos'?'':'Semana: '+lab(week);
  }
  box.addEventListener('input',e=>{const el=e.target;if(!el.dataset.k)return;const d=+el.closest('.pj-day').dataset.d;data[d][+el.dataset.i][el.dataset.k]=el.value;totals();});
  box.addEventListener('click',e=>{const row=e.target.closest('.pj-day');if(!row)return;const d=+row.dataset.d;
    if(e.target.hasAttribute('data-add')){data[d]=data[d]||[];const last=data[d][data[d].length-1];data[d].push(last?{ini:'13:00',fim:'17:48'}:{ini:'08:00',fim:'12:00'});render();}
    if(e.target.dataset.del!==undefined){data[d].splice(+e.target.dataset.del,1);if(!data[d].length)delete data[d];render();}});
  mode.addEventListener('change',()=>{if(mode.value==='todos'){data={0:data[0]||[{ini:'07:00',fim:'19:00'}]};}else{const t=data[0]||[];data={};[2,3,4,5,6].forEach(d=>{if(t.length)data[d]=JSON.parse(JSON.stringify(t));});}render();});
  window.pjCopy=function(targets){const src=data[2];if(!src||!src.length){alert('Preencha a segunda-feira primeiro.');return;}targets.forEach(d=>data[d]=JSON.parse(JSON.stringify(src)));render();};
  form.addEventListener('submit',()=>{form.querySelectorAll('.pj-hidden').forEach(x=>x.remove());Object.entries(data).forEach(([d,ps])=>ps.forEach(p=>{[['p_dia[]',d],['p_ini[]',p.ini],['p_fim[]',p.fim]].forEach(([n,v])=>{const i=document.createElement('input');i.type='hidden';i.className='pj-hidden';i.name=n;i.value=v;form.appendChild(i);});}));});
  function open(r){
    form.querySelector('[name=id]').value=r.id||0;form.querySelector('[name=descricao]').value=r.descricao||'';
    ['tolerancia_entrada','tolerancia_saida','tolerancia_intervalo'].forEach(k=>form.querySelector('[name='+k+']').value=r[k]!==undefined?r[k]:(k==='tolerancia_intervalo'?0:5));
    data=JSON.parse(JSON.stringify(r.periodos||{}));if(Array.isArray(data))data=Object.assign({},data);
    mode.value=data[0]?'todos':'semana';render();ptOpen('pjModal');
  }
  window.pjNew=function(){document.getElementById('pjTitle').textContent='Cadastrar jornada';open({periodos:{2:[{ini:'08:00',fim:'12:00'},{ini:'13:00',fim:'17:48'}]}});};
  window.pjEdit=function(id){document.getElementById('pjTitle').textContent='Editar jornada';open(rows[id]||{});};
})();
</script>
<?php ponto_end(); ?>
