<?php
declare(strict_types=1);

// Ponto Eletrônico > Escalas: qual jornada vale em cada dia. Base SEMANA (5x2, 6x1: por dia da
// semana) ou CICLO (12x36, revezamento: dia 1..N a partir do início do ciclo do colaborador).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$cid = ponto_guard($pdo, 'ponto_escalas');
$status = in_array((string)($_GET['s'] ?? ''), ['ativos', 'inativos', 'todos'], true) ? (string)$_GET['s'] : 'ativos';
$back = 'ponto_escalas.php' . ($status !== 'ativos' ? '?s=' . $status : '');

ponto_post_handle($back, function () use ($pdo, $cid) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save') {
    $isNew = (int)($_POST['id'] ?? 0) === 0;
    ponto_escala_save($pdo, $cid, $_POST);
    return $isNew ? 'Escala cadastrada.' : 'Escala alterada. Vale para todos os colaboradores que estão nela.';
  }
  if ($action === 'status') {
    $on = (string)($_POST['on'] ?? '') === '1';
    ponto_simple_status($pdo, $cid, 'escalas', (int)($_POST['id'] ?? 0), $on);
    return $on ? 'Escala reativada.' : 'Escala inativada.';
  }
  throw new RuntimeException('Ação inválida.');
});

$rows = ponto_escala_list($pdo, $cid, $status);
$jornadas = ponto_options($pdo, $cid, 'jornadas');
$jornadasAll = ponto_options($pdo, $cid, 'jornadas', false);
$tipos = ponto_escala_tipos();
$usage = [];
$st = $pdo->prepare('SELECT escala_id, COUNT(DISTINCT colaborador_id) n FROM ponto_colaborador_escalas WHERE company_id = ? AND vigencia_inicio <= CURRENT_DATE AND (vigencia_fim IS NULL OR vigencia_fim >= CURRENT_DATE) GROUP BY escala_id');
$st->execute([$cid]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $usage[(int)$u['escala_id']] = (int)$u['n'];
$resumo = static function (array $e) use ($jornadasAll): string {
  $parts = [];
  for ($d = 1; $d <= (int)$e['ciclo_dias']; $d++) {
    $j = $e['dias'][$d] ?? null;
    $name = $e['base'] === 'SEMANA' ? PONTO_DIAS_CURTOS[$d] : 'Dia ' . $d;
    $parts[] = $name . ': ' . ($j ? ($jornadasAll[$j] ?? '#' . $j) : 'folga');
  }
  return implode(' · ', $parts);
};

ponto_start($pdo, 'ponto_escalas', 'Escalas', 'Define em quais dias cada jornada vale. Para colocar um colaborador numa escala, abra o cadastro dele na aba Escala.', $jornadas ? '<button class="pt-btn pri" type="button" onclick="peNew()">+ Cadastrar</button>' : '');
?>
<style>
.pe-days{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.pe-days label{display:grid;gap:4px;font-size:12px;font-weight:800;color:#334155;margin:0!important}
.pe-prev{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-top:8px}
.pe-prev div{border:1px solid #e2e8f0;border-radius:10px;padding:6px;font-size:11.5px;text-align:center;line-height:1.3;min-height:52px}
.pe-prev div.t{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;font-weight:800}.pe-prev b{display:block;color:#0b1f5b}
@media(max-width:760px){.pe-prev{grid-template-columns:repeat(4,minmax(0,1fr))}}
</style>
<section class="pt-card">
  <?php if (!$jornadas): ?><div class="pt-msg warn">Cadastre as jornadas primeiro: cada dia de trabalho da escala usa uma jornada. <a href="ponto_jornadas.php">Ir para Jornadas</a></div><?php endif; ?>
  <form method="get" class="pt-search">
    <label class="f" style="width:170px">Situação<select name="s" onchange="this.form.submit()"><option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativas</option><option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativas</option><option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todas</option></select></label>
    <?php if ($jornadas): ?><button class="pt-btn pri" type="button" onclick="peNew()">Cadastrar</button><?php endif; ?>
  </form>
  <?php if (!$rows): ?><div class="pt-empty">Nenhuma escala cadastrada. Quem trabalha sempre nos mesmos dias pode ficar só com a jornada padrão, sem escala.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th style="width:22%">Escala</th><th>Tipo</th><th>Dias</th><th>Colaboradores</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $on = (int)$r['status'] === 1; ?>
      <tr class="<?= $on ? '' : 'off' ?>">
        <td><b><?= ponto_h($r['descricao']) ?></b><?= $on ? '' : ' <span class="pt-badge n">Inativa</span>' ?></td>
        <td data-l="Tipo"><?= ponto_h(explode(' (', $tipos[$r['tipo']]['label'] ?? $r['tipo'])[0]) ?><?= $r['base'] === 'CICLO' ? ' <span class="pt-hint">ciclo de ' . (int)$r['ciclo_dias'] . ' dias</span>' : '' ?></td>
        <td data-l="Dias" style="font-size:12.5px"><?= ponto_h($resumo($r)) ?></td>
        <td data-l="Colaboradores"><?= (int)($usage[(int)$r['id']] ?? 0) ?></td>
        <td class="act"><button class="pt-btn sm" type="button" onclick="peEdit(<?= (int)$r['id'] ?>)">Editar</button>
          <form method="post" style="display:inline" data-confirm="<?= $on ? 'Inativar esta escala? Quem já está nela continua; ela só deixa de aparecer para novas trocas.' : '' ?>"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>"><button class="pt-btn sm <?= $on ? 'red' : '' ?>" type="submit"><?= $on ? 'Inativar' : 'Reativar' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<dialog class="pt-modal wide" id="peModal">
  <form method="post" id="peForm">
    <div class="pt-mh"><h3 id="peTitle">Escala</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
      <div class="pt-grid three">
        <label class="f">Descrição *<input type="text" name="descricao" maxlength="80" required placeholder="Ex.: Plantão 12x36"></label>
        <label class="f">Tipo<select name="tipo" id="peTipo"><?php foreach ($tipos as $k => $t): ?><option value="<?= $k ?>"><?= ponto_h($t['label']) ?></option><?php endforeach; ?></select></label>
        <label class="f" id="peBaseBox">Contagem<select name="base" id="peBase"><option value="SEMANA">Por dia da semana</option><option value="CICLO">Ciclo de N dias</option></select></label>
        <label class="f" id="peCicloBox">Dias do ciclo<input type="number" name="ciclo_dias" id="peCiclo" min="2" max="60" value="4"></label>
        <label class="f">Jornada dos dias de trabalho<select id="peFillJ"><?php foreach ($jornadas as $v => $lb): ?><option value="<?= (int)$v ?>"><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="pt-hint" id="peHelp"></p>
      <div class="pe-days" id="peDays"></div>
      <h4 style="margin:14px 0 4px;color:#0b1f5b">Como fica (exemplo a partir de hoje)</h4>
      <div class="pe-prev" id="pePrev"></div>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Salvar</button></div>
  </form>
</dialog>
<script>
(function(){
  const rows=<?= json_encode(array_column($rows, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const jornadas=<?= json_encode((object)$jornadas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tipos=<?= json_encode($tipos, JSON_UNESCAPED_UNICODE) ?>;
  const wd=['','Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
  const form=document.getElementById('peForm'), tipo=document.getElementById('peTipo'), base=document.getElementById('peBase'), ciclo=document.getElementById('peCiclo'), days=document.getElementById('peDays');
  let map={};
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function cfg(){const t=tipos[tipo.value];const b=t.base||base.value;const n=b==='SEMANA'?7:(t.ciclo||Math.max(2,Math.min(60,+ciclo.value||2)));return {b,n};}
  function render(){
    const t=tipos[tipo.value];document.getElementById('peBaseBox').hidden=!!t.base;document.getElementById('peCicloBox').hidden=(t.base||base.value)!=='CICLO'||!!t.ciclo;
    const {b,n}=cfg();
    document.getElementById('peHelp').textContent=b==='SEMANA'?'Escolha a jornada de cada dia da semana; "Folga" nos dias de descanso.':'O dia 1 é o primeiro dia do ciclo do colaborador (informado ao colocá-lo na escala). Depois do último dia, o ciclo recomeça.';
    const order=b==='SEMANA'?[2,3,4,5,6,7,1]:Array.from({length:n},(_,i)=>i+1);
    days.innerHTML=order.map(d=>'<label>'+(b==='SEMANA'?['','Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'][d]:'Dia '+d)+'<select name="dias['+d+']"><option value="">Folga</option>'+Object.entries(jornadas).map(([id,lb])=>'<option value="'+id+'"'+(String(map[d]||'')===id?' selected':'')+'>'+esc(lb)+'</option>').join('')+'</select></label>').join('');
    preview();
  }
  function preview(){
    const {b,n}=cfg();const out=[];const today=new Date();today.setHours(12,0,0,0);
    for(let i=0;i<14;i++){const d=new Date(today.getTime()+i*86400000);const key=b==='SEMANA'?d.getDay()+1:(i%n)+1;const j=map[key];
      out.push('<div class="'+(j?'t':'')+'"><b>'+wd[d.getDay()+1]+' '+String(d.getDate()).padStart(2,'0')+'</b>'+(j?esc(jornadas[j]||'Trabalho'):'Folga')+'</div>');}
    document.getElementById('pePrev').innerHTML=out.join('');
  }
  days.addEventListener('change',e=>{const m=e.target.name.match(/dias\[(\d+)\]/);if(m){map[m[1]]=e.target.value;preview();}});
  function template(){
    const j=document.getElementById('peFillJ').value;map={};
    if(tipo.value==='5X2')[2,3,4,5,6].forEach(d=>map[d]=j);
    else if(tipo.value==='6X1')[2,3,4,5,6,7].forEach(d=>map[d]=j);
    else if(tipo.value==='12X36')map[1]=j;
    render();
  }
  tipo.addEventListener('change',template);base.addEventListener('change',render);ciclo.addEventListener('input',render);
  document.getElementById('peFillJ').addEventListener('change',()=>{const j=document.getElementById('peFillJ').value;Object.keys(map).forEach(k=>{if(map[k])map[k]=j;});render();});
  window.peNew=function(){form.querySelector('[name=id]').value=0;form.querySelector('[name=descricao]').value='';tipo.value='5X2';base.value='SEMANA';ciclo.value=4;document.getElementById('peTitle').textContent='Cadastrar escala';template();ptOpen('peModal');};
  window.peEdit=function(id){const r=rows[id];if(!r)return;form.querySelector('[name=id]').value=r.id;form.querySelector('[name=descricao]').value=r.descricao;tipo.value=r.tipo;base.value=r.base;ciclo.value=r.ciclo_dias;
    map={};Object.entries(r.dias||{}).forEach(([d,j])=>{if(j)map[d]=String(j);});document.getElementById('peTitle').textContent='Editar escala';render();ptOpen('peModal');};
})();
</script>
<?php ponto_end(); ?>
