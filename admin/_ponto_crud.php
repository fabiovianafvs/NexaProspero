<?php
declare(strict_types=1);

// Cadastro simples do Ponto (lista + modal). Quem inclui define $PONTO_ENTITY antes do require.
// Usado por: ponto_departamentos.php, ponto_cargos.php, ponto_estabelecimentos.php,
// ponto_feriados.php e ponto_locais.php (este com mapa OpenStreetMap/Leaflet).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$ent = ponto_entity((string)$PONTO_ENTITY);
$key = (string)$ent['key'];
$cid = ponto_guard($pdo, (string)$ent['perm']);
$page = (string)$ent['page'];
$q = trim((string)($_GET['q'] ?? ''));
$status = in_array((string)($_GET['s'] ?? ''), ['ativos', 'inativos', 'todos'], true) ? (string)$_GET['s'] : 'ativos';
$back = $page . '?' . http_build_query(array_filter(['q' => $q, 's' => $status !== 'ativos' ? $status : null]));

ponto_post_handle($back, function () use ($pdo, $cid, $key, $ent) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save') {
    $isNew = (int)($_POST['id'] ?? 0) === 0;
    ponto_entity_save($pdo, $cid, $key, $_POST);
    return $ent['label'] . ($isNew ? ' cadastrado.' : ' alterado.');
  }
  if ($action === 'status') {
    $on = (string)($_POST['on'] ?? '') === '1';
    ponto_entity_status($pdo, $cid, $key, (int)($_POST['id'] ?? 0), $on);
    return $ent['label'] . ($on ? ' reativado.' : ' inativado.');
  }
  if ($action === 'importar_feriados' && $key === 'feriados') {
    $n = ponto_feriados_importar($pdo, $cid, (int)($_POST['ano'] ?? 0), !empty($_POST['facultativos']));
    return $n > 0 ? $n . ' feriado(s) cadastrado(s).' : 'Os feriados deste ano já estavam cadastrados.';
  }
  throw new RuntimeException('Ação inválida.');
});

$rows = ponto_entity_list($pdo, $cid, $key, $q, $status);
$fkOptions = [];
foreach ($ent['fields'] as $col => $f) if ($f['type'] === 'fk') $fkOptions[$col] = ponto_options($pdo, $cid, (string)$f['ref'], false);
$isMap = $key === 'locais';

$cell = static function (array $r, string $col) use ($ent, $fkOptions): string {
  $f = $ent['fields'][$col] ?? ['type' => 'text'];
  $v = $r[$col] ?? null;
  if ($v === null || $v === '') return '<span class="pt-hint">—</span>';
  switch ($f['type']) {
    case 'bool': return (int)$v ? '<span class="pt-badge n">Sim</span>' : '<span class="pt-hint">Não</span>';
    case 'date': return ponto_h(date('d/m/Y', strtotime((string)$v)) . (!empty($r['recorrente']) ? '' : ''));
    case 'enum': return ponto_h($f['options'][$v] ?? $v);
    case 'fk': return ponto_h($fkOptions[$col][(int)$v] ?? ('#' . $v));
    case 'doc': return ponto_h(ponto_fmt_doc((string)$v));
    case 'int': return $col === 'raio_metros' ? ponto_h($v . ' m') : ponto_h($v);
    default: return ponto_h($v);
  }
};

$actions = '<button class="pt-btn pri" type="button" onclick="ptNew()">+ Cadastrar</button>';
if ($key === 'feriados') $actions = '<button class="pt-btn" type="button" onclick="ptOpen(\'ptImport\')">Importar feriados nacionais</button>' . $actions;
ponto_start($pdo, (string)$ent['perm'], (string)$ent['plural'], (string)$ent['hint'], $actions);
if ($isMap) echo '<link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">';
?>
<section class="pt-card">
  <form method="get" class="pt-search">
    <label class="f grow"><span>Pesquisar <span class="pt-kbd" title="Tecla F8 vai direto para a pesquisa">F8</span></span><input type="text" name="q" value="<?= ponto_h($q) ?>" data-pt-search placeholder="Digite e tecle Enter"></label>
    <label class="f" style="width:150px">Situação<select name="s" onchange="this.form.submit()"><option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option><option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option><option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option></select></label>
    <button class="pt-btn" type="submit">Pesquisar</button>
    <button class="pt-btn pri" type="button" onclick="ptNew()">Cadastrar</button>
  </form>
  <?php if (!$rows): ?>
    <div class="pt-empty"><?= $q !== '' ? 'Nada encontrado para "' . ponto_h($q) . '".' : 'Nenhum ' . ponto_h(mb_strtolower((string)$ent['label'])) . ' cadastrado ainda.' ?></div>
  <?php else: ?>
  <table class="pt-table cards">
    <thead><tr><?php foreach ($ent['list'] as $col): ?><th><?= ponto_h($ent['fields'][$col]['label']) ?></th><?php endforeach; ?><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $on = (int)$r['status'] === 1; ?>
      <tr class="<?= $on ? '' : 'off' ?>">
        <?php foreach ($ent['list'] as $i => $col): ?><td <?= $i > 0 ? 'data-l="' . ponto_h($ent['fields'][$col]['label']) . '"' : '' ?>><?= $i === 0 ? '<b>' . $cell($r, $col) . '</b>' : $cell($r, $col) ?></td><?php endforeach; ?>
        <td data-l="Situação"><?= $on ? '<span class="pt-badge g">Ativo</span>' : '<span class="pt-badge n">Inativo</span>' ?></td>
        <td class="act">
          <button class="pt-btn sm" type="button" onclick='ptEdit(<?= json_encode((int)$r['id']) ?>)'>Editar</button>
          <form method="post" style="display:inline" data-confirm="<?= $on ? 'Inativar este cadastro? Ele continua no histórico.' : '' ?>"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>"><button class="pt-btn sm <?= $on ? 'red' : '' ?>" type="submit"><?= $on ? 'Inativar' : 'Reativar' ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<dialog class="pt-modal <?= $isMap ? 'wide' : '' ?>" id="ptForm">
  <form method="post" id="ptFormEl">
    <div class="pt-mh"><h3 id="ptFormTitle"><?= ponto_h($ent['label']) ?></h3><button class="pt-x" type="button" onclick="ptClose(this)" aria-label="Fechar">×</button></div>
    <div class="pt-mb">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
      <?php if ($key === 'estabelecimentos'): ?>
        <p style="margin:0 0 10px"><button class="pt-btn sm" type="button" onclick="ptFillCompany()">Preencher com os dados da empresa</button></p>
      <?php endif; ?>
      <div class="pt-grid">
      <?php foreach ($ent['fields'] as $col => $f):
        $span = !empty($f['span']) ? ' s2' : '';
        if ($f['type'] === 'bool'): ?>
          <label class="ck s3"><input type="checkbox" name="<?= ponto_h($col) ?>" value="1" <?= !empty($f['default']) ? 'data-default="1"' : '' ?>> <?= ponto_h($f['label']) ?></label>
        <?php elseif ($f['type'] === 'enum' || $f['type'] === 'fk'):
          $opts = $f['type'] === 'enum' ? $f['options'] : $fkOptions[$col]; ?>
          <label class="f<?= $span ?>"><?= ponto_h($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?>
            <select name="<?= ponto_h($col) ?>" <?= isset($f['default']) ? 'data-default="' . ponto_h($f['default']) . '"' : '' ?>>
              <?php if (empty($f['required'])): ?><option value=""><?= ponto_h($f['empty'] ?? '—') ?></option><?php endif; ?>
              <?php foreach ($opts as $v => $lb): ?><option value="<?= ponto_h($v) ?>"><?= ponto_h($lb) ?></option><?php endforeach; ?>
            </select></label>
        <?php else:
          $type = $f['type'] === 'date' ? 'date' : ($f['type'] === 'int' ? 'number' : 'text');
          $mode = in_array($f['type'], ['digits', 'doc', 'int'], true) ? 'numeric' : ($f['type'] === 'decimal' ? 'decimal' : 'text'); ?>
          <label class="f<?= $span ?>"><?= ponto_h($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?>
            <input type="<?= $type ?>" name="<?= ponto_h($col) ?>" inputmode="<?= $mode ?>" <?= isset($f['max']) && $f['type'] === 'text' ? 'maxlength="' . (int)$f['max'] . '"' : '' ?> <?= isset($f['default']) ? 'data-default="' . ponto_h($f['default']) . '"' : '' ?> <?= isset($f['placeholder']) ? 'placeholder="' . ponto_h($f['placeholder']) . '"' : '' ?> <?= $type === 'number' && isset($f['min']) ? 'min="' . (int)$f['min'] . '" max="' . (int)$f['max'] . '"' : '' ?>></label>
        <?php endif; ?>
      <?php endforeach; ?>
      </div>
      <?php if ($isMap): ?>
        <div style="margin-top:12px">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px">
            <label class="f" style="flex:1 1 260px">Procurar endereço no mapa<input type="text" id="ptGeoQ" placeholder="Rua, número, cidade"></label>
            <button class="pt-btn sm" type="button" onclick="ptGeoSearch()" data-keep>Procurar</button>
            <button class="pt-btn sm" type="button" onclick="ptGeoMe()" data-keep>Usar minha localização atual</button>
          </div>
          <div id="ptMap" style="height:340px;border-radius:14px;border:1px solid #cbd5e1"></div>
          <p class="pt-hint" id="ptGeoMsg" style="margin:6px 0 0">Clique no mapa para marcar o local; o círculo mostra o raio permitido. Mapa: © colaboradores do OpenStreetMap.</p>
        </div>
      <?php endif; ?>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Salvar</button></div>
  </form>
</dialog>

<?php if ($key === 'feriados'): ?>
<dialog class="pt-modal" id="ptImport">
  <form method="post">
    <div class="pt-mh"><h3>Importar feriados nacionais</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="importar_feriados">
      <div class="pt-grid"><label class="f">Ano<input type="number" name="ano" value="<?= (int)date('Y') ?>" min="2000" max="2100"></label></div>
      <label class="ck" style="margin-top:12px!important"><input type="checkbox" name="facultativos" value="1"> Incluir Carnaval e Corpus Christi (pontos facultativos, como Institucional)</label>
      <p class="pt-hint">Cadastra os feriados nacionais que ainda não existem: Confraternização Universal, Paixão de Cristo, Tiradentes, Dia do Trabalho, Independência, Nossa Senhora Aparecida, Finados, Proclamação da República, Consciência Negra e Natal. Os de data fixa ficam como "repete todo ano". Feriados estaduais e municipais você cadastra pelo botão Cadastrar.</p>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Importar</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($isMap): ?><script src="../assets/vendor/leaflet/leaflet.js"></script><?php endif; ?>
<script>
(function(){
  const rows=<?= json_encode(array_column($rows, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const company=<?= json_encode($key === 'estabelecimentos' ? ponto_estabelecimento_da_empresa($pdo, $cid) : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const label=<?= json_encode((string)$ent['label'], JSON_UNESCAPED_UNICODE) ?>;
  const form=document.getElementById('ptFormEl');
  function fill(data){
    form.querySelectorAll('[name]').forEach(el=>{
      if(['csrf','action'].includes(el.name))return;
      const v=data[el.name];
      if(el.type==='checkbox'){el.checked=v===undefined?el.dataset.default==='1':String(v)==='1';return;}
      if(el.name==='id'){el.value=v||0;return;}
      if(v===undefined||v===null){el.value=el.dataset.default||'';return;}
      el.value=(el.type==='date')?String(v).slice(0,10):String(v);
      if(el.name==='latitude'||el.name==='longitude')el.value=v===''?'':String(Number(v));
    });
  }
  window.ptNew=function(){fill({});window.ptLookupReset&&ptLookupReset();document.getElementById('ptFormTitle').textContent='Cadastrar '+label.toLowerCase();ptOpen('ptForm');window.ptMapSync&&setTimeout(ptMapSync,60);};
  window.ptEdit=function(id){fill(rows[id]||{});window.ptLookupReset&&ptLookupReset();document.getElementById('ptFormTitle').textContent='Editar '+label.toLowerCase();ptOpen('ptForm');window.ptMapSync&&setTimeout(ptMapSync,60);};
  window.ptFillCompany=function(){Object.entries(company).forEach(([k,v])=>{const el=form.querySelector('[name="'+k+'"]');if(el&&v)el.value=v;});};
  // Estabelecimento: CNPJ e CEP buscam os dados sozinhos (mesma consulta do cadastro de clientes).
  const doc=form.querySelector('[name=documento]'), cep=form.querySelector('[name=cep]'), tipo=form.querySelector('[name=tipo_documento]');
  if(doc&&cep){
    const status=document.createElement('p');status.className='pt-hint';status.style.margin='6px 0 0';doc.closest('label').after(status);
    const put=(name,val,force)=>{const el=form.querySelector('[name="'+name+'"]');if(el&&val&&(force||!el.value.trim()))el.value=val;};
    let lastDoc='', lastCep='';
    doc.addEventListener('input',()=>{const d=doc.value.replace(/\D/g,'');if(tipo.value==='CNPJ'&&d.length===14&&d!==lastDoc)lookupCnpj(d);});
    doc.addEventListener('blur',()=>{const d=doc.value.replace(/\D/g,'');if(tipo.value==='CNPJ'&&d.length===14&&d!==lastDoc)lookupCnpj(d);});
    function lookupCnpj(d){
      lastDoc=d;status.textContent='Consultando o CNPJ...';
      fetch('pessoa_cnpj_lookup.php?cnpj='+d,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
        if(!j.ok){status.textContent=j.error||'CNPJ não encontrado. Preencha os dados manualmente.';return;}
        const x=j.data, novo=form.querySelector('[name=id]').value==='0';
        put('razao_social',x.razao_social,novo);put('nome_fantasia',x.nome_fantasia,novo);
        put('cep',x.cep,novo);put('endereco',x.logradouro,novo);put('numero',x.numero,novo);put('complemento',x.complemento,novo);
        put('bairro',x.bairro,novo);put('cidade',x.municipio,novo);put('uf',x.uf,novo);
        lastCep=(x.cep||'');
        status.textContent='Dados da Receita carregados'+(x.situacao_cadastral?' (situação: '+x.situacao_cadastral+')':'')+'. Confira antes de salvar.';
      }).catch(()=>{status.textContent='Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.';});
    }
    const cepGo=()=>{const d=cep.value.replace(/\D/g,'');if(d.length!==8||d===lastCep)return;lastCep=d;status.textContent='Consultando o CEP...';
      fetch('pessoa_cep_lookup.php?cep='+d,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
        if(!j.ok){status.textContent=j.error||'CEP não encontrado.';return;}
        const x=j.data;put('endereco',x.logradouro,true);put('bairro',x.bairro,true);put('cidade',x.cidade,true);put('uf',x.uf,true);if(x.complemento)put('complemento',x.complemento,false);
        status.textContent='Endereço do CEP carregado. Falta o número.';const n=form.querySelector('[name=numero]');if(n&&!n.value)n.focus();
      }).catch(()=>{status.textContent='Não foi possível consultar o CEP agora.';});};
    cep.addEventListener('input',()=>{if(cep.value.replace(/\D/g,'').length===8)cepGo();});
    cep.addEventListener('blur',cepGo);
    window.ptLookupReset=function(){lastDoc=doc.value.replace(/\D/g,'');lastCep=cep.value.replace(/\D/g,'');status.textContent='';};
  }
})();
<?php if ($isMap): ?>
(function(){
  const form=document.getElementById('ptFormEl'), lat=form.querySelector('[name=latitude]'), lng=form.querySelector('[name=longitude]'), raio=form.querySelector('[name=raio_metros]'), msg=document.getElementById('ptGeoMsg');
  let map=null, marker=null, circle=null;
  function ensure(){
    if(map||typeof L==='undefined')return;
    map=L.map('ptMap').setView([-15.78,-47.93],4);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
    map.on('click',e=>set(e.latlng.lat,e.latlng.lng,false));
  }
  function set(a,b,center){
    a=Number(a);b=Number(b);if(!isFinite(a)||!isFinite(b))return;
    lat.value=a.toFixed(7);lng.value=b.toFixed(7);draw(center);
  }
  function draw(center){
    ensure();if(!map)return;
    const a=parseFloat(String(lat.value).replace(',','.')), b=parseFloat(String(lng.value).replace(',','.')), r=Math.max(10,Number(raio.value)||100);
    if(!isFinite(a)||!isFinite(b)){if(marker){map.removeLayer(marker);map.removeLayer(circle);marker=circle=null;}return;}
    if(!marker){marker=L.marker([a,b],{draggable:true}).addTo(map);marker.on('dragend',()=>{const p=marker.getLatLng();set(p.lat,p.lng,false);});circle=L.circle([a,b],{radius:r,color:'#2454d6',weight:2,fillOpacity:.12}).addTo(map);}
    marker.setLatLng([a,b]);circle.setLatLng([a,b]);circle.setRadius(r);
    if(center)map.setView([a,b],Math.max(map.getZoom(),17));
  }
  window.ptMapSync=function(){ensure();if(!map)return;map.invalidateSize();if(lat.value&&lng.value)draw(true);else{if(marker){map.removeLayer(marker);map.removeLayer(circle);marker=circle=null;}map.setView([-15.78,-47.93],4);}};
  [lat,lng,raio].forEach(el=>el.addEventListener('input',()=>draw(false)));
  window.ptGeoMe=function(){
    if(!navigator.geolocation){msg.textContent='Este navegador não informa a localização.';return;}
    msg.textContent='Obtendo a localização...';
    navigator.geolocation.getCurrentPosition(p=>{set(p.coords.latitude,p.coords.longitude,true);msg.textContent='Localização obtida. Precisão aproximada: '+Math.round(p.coords.accuracy)+' metros. Confira o ponto no mapa e ajuste se precisar.';},
      e=>{msg.textContent=e.code===1?'O navegador não autorizou o uso da localização.':'Não foi possível obter a localização agora.';},{enableHighAccuracy:true,timeout:15000,maximumAge:0});
  };
  window.ptGeoSearch=function(){
    const q=document.getElementById('ptGeoQ').value.trim();if(!q)return;
    msg.textContent='Procurando...';
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q='+encodeURIComponent(q),{headers:{'Accept-Language':'pt-BR'}})
      .then(r=>r.json()).then(j=>{if(!j.length){msg.textContent='Endereço não encontrado. Tente com rua e cidade, ou clique no mapa.';return;}set(j[0].lat,j[0].lon,true);const end=form.querySelector('[name=endereco]');if(end&&!end.value)end.value=j[0].display_name.slice(0,200);msg.textContent='Encontrado: '+j[0].display_name+'. Confira e ajuste o ponto se precisar.';})
      .catch(()=>{msg.textContent='Não foi possível procurar agora. Clique no mapa para marcar o local.';});
  };
  document.getElementById('ptGeoQ').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();ptGeoSearch();}});
})();
<?php endif; ?>
</script>
<?php ponto_end(); ?>
