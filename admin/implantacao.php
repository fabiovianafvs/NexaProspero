<?php
declare(strict_types=1);

// Trilha de Implantação: quadro por etapas do cliente (marcar o que já foi executado),
// lista das implantações (?ver=lista) e cadastro de nova implantação.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_implantacao.php';
$cid = impl_guard($pdo, 'crm_implantacao');
$uid = (int)(auth_user_id() ?? 0);
$ver = (string)($_GET['ver'] ?? '') === 'lista' ? 'lista' : 'quadro';

if (impl_ready($pdo)) {
  impl_post_handle('implantacao.php?ver=lista', function () use ($pdo, $cid, $uid) {
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'criar') {
      $id = impl_implantacao_criar($pdo, $cid, $_POST, $uid);
      return ['Implantação criada. Marque as tarefas conforme forem executadas.', 'implantacao.php?impl=' . $id];
    }
    if ($a === 'editar') {
      $id = (int)($_POST['id'] ?? 0);
      impl_implantacao_editar($pdo, $cid, $id, $_POST);
      return ['Implantação atualizada.', (string)($_POST['back'] ?? '') === 'quadro' ? 'implantacao.php?impl=' . $id : 'implantacao.php?ver=lista'];
    }
    throw new RuntimeException('Ação inválida.');
  });
}

$canRel = impl_can($pdo, 'crm_implantacao_relatorios');
$canCad = impl_can($pdo, 'crm_implantacao_cadastros');
$ready = impl_ready($pdo);
$statusList = $ready ? impl_status_list($pdo, $cid, true) : [];
$trilhas = $ready ? impl_trilhas($pdo, $cid, true) : [];
$users = impl_users($pdo, $cid);

$periodo = (string)($_GET['periodo'] ?? 'todos');
$desdeMap = ['30' => '-30 days', '90' => '-90 days', '365' => '-365 days'];
$desde = isset($desdeMap[$periodo]) ? date('Y-m-d', strtotime($desdeMap[$periodo])) : '';
$lista = $ready ? impl_implantacoes($pdo, $cid, ['desde' => $desde, 'q' => $ver === 'lista' ? (string)($_GET['q'] ?? '') : '']) : [];

$implId = (int)($_GET['impl'] ?? 0);
if ($ver === 'quadro' && $implId <= 0) {
  foreach ($lista as $l) { if (($l['status_tipo'] ?? '') !== 'CONCLUIDO') { $implId = (int)$l['id']; break; } }
  if ($implId <= 0 && $lista) $implId = (int)$lista[0]['id'];
}
$impl = ($ready && $implId > 0) ? impl_implantacao($pdo, $cid, $implId) : null;
$etapas = $impl ? impl_impl_tarefas($pdo, $cid, $implId) : [];
$tot = 0; $feitas = 0;
foreach ($etapas as $e) foreach ($e['tarefas'] as $t) { $tot++; if ($t['done_at'] !== null) $feitas++; }

$actions = '';
if ($ver === 'quadro') {
  $actions .= '<a class="im-btn" href="implantacao.php?ver=lista">☰ Lista</a>';
  if ($impl) {
    $actions .= '<span id="imStatusPill">' . impl_pill($impl['status_nome'], $impl['status_cor']) . '</span>';
    $actions .= '<select id="imStatusSel" style="width:auto;min-width:190px" title="Status do cliente"><option value="">Status do cliente…</option>';
    foreach ($statusList as $s) $actions .= '<option value="' . (int)$s['id'] . '"' . ((int)$s['id'] === (int)$impl['status_id'] ? ' selected' : '') . '>' . impl_h($s['nome']) . '</option>';
    $actions .= '</select>';
    if ($canRel) $actions .= '<a class="im-btn" href="implantacao_relatorio.php?impl=' . (int)$impl['id'] . '">+ Relatório técnico</a>';
    $actions .= '<button class="im-btn pri" type="button" id="imConcluir"' . (($impl['status_tipo'] ?? '') === 'CONCLUIDO' ? ' disabled title="Implantação já concluída"' : '') . '>✓ Concluir treinamento</button>';
  }
} else {
  $actions .= '<a class="im-btn" href="implantacao.php">▦ Quadro</a><button class="im-btn pri" type="button" onclick="imOpen(\'mNova\')">+ Nova implantação</button>';
}
impl_start($pdo, 'trilha', 'Trilha de Implantação', $ver === 'lista' ? 'Clientes em implantação, a trilha que cada um segue e o andamento.' : 'Acompanhe o cliente etapa por etapa e marque o que já foi executado.', $actions);

if (!$trilhas) {
  echo '<div class="im-msg warn">Ainda não há trilhas cadastradas. ' . ($canCad ? '<a href="implantacao_cadastros.php">Abra Cadastros</a> para criar as etapas, tarefas e trilhas (ou carregar o modelo sugerido).' : 'Peça ao responsável para cadastrar as etapas, tarefas e trilhas.') . '</div>';
}
?>

<?php if ($ver === 'quadro'): ?>
  <form class="im-card im-search" method="get" style="margin-bottom:12px">
    <label class="f grow">Cliente
      <select name="impl" onchange="this.form.submit()">
        <option value="">Selecione um cliente…</option>
        <?php foreach ($lista as $l): ?>
          <option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $implId ? 'selected' : '' ?>><?= impl_h($l['cliente'] . ' — ' . ($l['trilha_nome'] ?: 'Trilha') . ' (' . $l['progresso'] . '%)') ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="f">Período (início)
      <select name="periodo">
        <?php foreach (['todos' => 'Todos', '30' => 'Últimos 30 dias', '90' => 'Últimos 90 dias', '365' => 'Último ano'] as $k => $v): ?><option value="<?= $k ?>" <?= $periodo === (string)$k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </label>
    <button class="im-btn pri" type="submit">Filtrar</button>
    <a class="im-btn" href="implantacao.php">Limpar</a>
  </form>

  <?php if (!$impl): ?>
    <div class="im-empty">Nenhuma implantação ainda. <a href="implantacao.php?ver=lista">Abra a lista</a> e clique em "+ Nova implantação".</div>
  <?php else: ?>
    <div class="im-card" style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between">
      <div>
        <strong style="font-size:17px;color:#0b1f5b"><?= impl_h($impl['cliente']) ?></strong>
        <div class="im-hint">Trilha: <?= impl_h($impl['trilha_nome'] ?: '-') ?> · Início <?= impl_data_br($impl['data_inicio']) ?><?= $impl['implantador'] ? ' · Implantador: ' . impl_h($impl['implantador']) : '' ?><?= $impl['data_conclusao'] ? ' · Concluído em ' . impl_data_br($impl['data_conclusao']) : '' ?></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;min-width:260px;flex:0 1 380px">
        <div class="im-bar" style="flex:1"><b id="imBar" style="width:<?= $tot ? floor($feitas * 100 / $tot) : 0 ?>%"></b></div>
        <strong id="imPct" style="color:#0b1f5b;white-space:nowrap"><?= $tot ? floor($feitas * 100 / $tot) : 0 ?>% · <?= $feitas ?>/<?= $tot ?></strong>
        <button class="im-btn sm" type="button" onclick="imOpen('mAdd')" title="Acrescentar uma tarefa do cadastro só para este cliente">+ Tarefa</button>
        <button class="im-btn sm" type="button" onclick="imOpen('mEdit')">Editar</button>
      </div>
    </div>
    <div class="im-board" id="imBoard">
      <?php foreach ($etapas as $e): $ef = count(array_filter($e['tarefas'], static fn($t) => $t['done_at'] !== null)); ?>
        <section class="im-col" style="--c:<?= impl_h(impl_cor($e['cor'])) ?>">
          <div class="im-col-h"><i></i><?= impl_h($e['nome']) ?><span class="n" data-n><?= $ef ?>/<?= count($e['tarefas']) ?></span></div>
          <ul>
            <?php foreach ($e['tarefas'] as $t): $done = $t['done_at'] !== null; ?>
              <li class="<?= $done ? 'done' : '' ?>">
                <input type="checkbox" data-it="<?= (int)$t['id'] ?>" <?= $done ? 'checked' : '' ?> aria-label="<?= impl_h($t['nome']) ?>">
                <span class="t"><span class="nm"><?= impl_h($t['nome']) ?></span>
                  <small data-info><?= $done ? 'Feito em ' . impl_h(date('d/m/Y', strtotime((string)$t['done_at']))) . ($t['feito_por'] ? ' por ' . impl_h($t['feito_por']) : '') . ($t['relatorio_numero'] ? ' · Relatório nº ' . (int)$t['relatorio_numero'] : '') : '' ?></small>
                </span>
                <?php if (!$done): ?><button class="rm" type="button" data-rm="<?= (int)$t['id'] ?>" title="Retirar esta tarefa da trilha do cliente">×</button><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    </div>

    <dialog class="im-modal" id="mAdd"><div class="im-mh"><h3>Acrescentar tarefa</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
      <?php $ja = []; foreach ($etapas as $e) foreach ($e['tarefas'] as $t) $ja[(int)$t['tarefa_id']] = true;
        $livres = array_values(array_filter(impl_tarefas($pdo, $cid, true), static fn($t) => !isset($ja[(int)$t['id']]))); ?>
      <div class="im-mb"><?php if ($livres): ?><label class="f">Tarefa do cadastro<select id="imAddSel">
        <?php foreach ($livres as $t): ?><option value="<?= (int)$t['id'] ?>"><?= impl_h($t['etapa_nome'] . ' › ' . $t['nome']) ?></option><?php endforeach; ?></select></label>
        <p class="im-hint">A tarefa entra só na trilha deste cliente; a trilha cadastrada não muda.</p>
        <?php else: ?><div class="im-empty">Todas as tarefas ativas do cadastro já estão na trilha deste cliente.<?= $canCad ? ' Para criar uma nova, abra <a href="implantacao_cadastros.php?aba=tarefas">Cadastros &gt; Tarefas</a>.' : '' ?></div><?php endif; ?></div>
      <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><?php if ($livres): ?><button class="im-btn pri" type="button" id="imAddGo">Acrescentar</button><?php endif; ?></div>
    </dialog>

    <dialog class="im-modal" id="mEdit"><form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="editar"><input type="hidden" name="id" value="<?= (int)$impl['id'] ?>"><input type="hidden" name="back" value="quadro">
      <div class="im-mh"><h3>Editar implantação</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
      <div class="im-mb"><div class="im-grid">
        <label class="f">Status<select name="status_id"><?php foreach ($statusList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$impl['status_id'] ? 'selected' : '' ?>><?= impl_h($s['nome']) ?></option><?php endforeach; ?></select></label>
        <label class="f">Implantador<select name="implantador_user_id"><option value="0">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$impl['implantador_user_id'] ? 'selected' : '' ?>><?= impl_h($u['name']) ?></option><?php endforeach; ?></select></label>
        <label class="f">Data de início<input type="date" name="data_inicio" value="<?= impl_h($impl['data_inicio']) ?>" required></label>
        <label class="f s3">Observações<textarea name="observacoes"><?= impl_h($impl['observacoes'] ?? '') ?></textarea></label>
      </div></div>
      <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><button class="im-btn pri" type="submit">Salvar</button></div>
    </form></dialog>
  <?php endif; ?>

<?php else: ?>
  <form class="im-card im-search" method="get" style="margin-bottom:12px"><input type="hidden" name="ver" value="lista">
    <label class="f grow">Cliente<input type="text" name="q" value="<?= impl_h($_GET['q'] ?? '') ?>" placeholder="Nome do cliente"></label>
    <label class="f">Período (início)<select name="periodo"><?php foreach (['todos' => 'Todos', '30' => 'Últimos 30 dias', '90' => 'Últimos 90 dias', '365' => 'Último ano'] as $k => $v): ?><option value="<?= $k ?>" <?= $periodo === (string)$k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
    <button class="im-btn pri" type="submit">Pesquisar</button><a class="im-btn" href="implantacao.php?ver=lista">Limpar</a>
  </form>
  <div class="im-card">
    <?php if (!$lista): ?><div class="im-empty">Nenhuma implantação encontrada.</div><?php else: ?>
    <table class="im-table cards"><thead><tr><th>Cliente</th><th>Trilha</th><th>Status</th><th>Implantador</th><th>Início</th><th>Progresso</th><th>Conclusão</th><th></th></tr></thead><tbody>
      <?php foreach ($lista as $l): ?>
        <tr>
          <td data-l="Cliente"><strong><?= impl_h($l['cliente']) ?></strong></td>
          <td data-l="Trilha"><?= impl_h($l['trilha_nome'] ?: '-') ?></td>
          <td data-l="Status"><?= impl_pill($l['status_nome'], $l['status_cor']) ?></td>
          <td data-l="Implantador"><?= impl_h($l['implantador'] ?: '-') ?></td>
          <td data-l="Início"><?= impl_data_br($l['data_inicio']) ?></td>
          <td data-l="Progresso"><div style="display:flex;gap:8px;align-items:center"><div class="im-bar" style="flex:1"><b style="width:<?= (int)$l['progresso'] ?>%"></b></div><b><?= (int)$l['progresso'] ?>%</b></div></td>
          <td data-l="Conclusão"><?= impl_data_br($l['data_conclusao']) ?></td>
          <td class="act"><a class="im-btn sm pri" href="implantacao.php?impl=<?= (int)$l['id'] ?>">Abrir trilha</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<dialog class="im-modal" id="mNova"><form method="post" id="imNovaForm"><?= impl_csrf_field() ?><input type="hidden" name="action" value="criar"><input type="hidden" name="person_id" id="imPerson">
  <div class="im-mh"><h3>Nova implantação</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
  <div class="im-mb"><div class="im-grid">
    <label class="f s3">Cliente (cadastro de Pessoas) *<input type="text" id="imPersonQ" placeholder="Digite o nome ou CPF/CNPJ" autocomplete="off"></label>
    <div class="s3 im-hint" id="imPersonRes"></div>
    <label class="f">Trilha *<select name="trilha_id" required><option value="">Selecione…</option><?php foreach ($trilhas as $t): ?><option value="<?= (int)$t['id'] ?>"><?= impl_h($t['nome'] . ' (' . (int)$t['tarefas'] . ' tarefas)') ?></option><?php endforeach; ?></select></label>
    <label class="f">Status<select name="status_id"><?php foreach ($statusList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $s['tipo'] === 'AGENDADO' ? 'selected' : '' ?>><?= impl_h($s['nome']) ?></option><?php endforeach; ?></select></label>
    <label class="f">Implantador<select name="implantador_user_id"><option value="0">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $uid ? 'selected' : '' ?>><?= impl_h($u['name']) ?></option><?php endforeach; ?></select></label>
    <label class="f">Data de início<input type="date" name="data_inicio" value="<?= date('Y-m-d') ?>"></label>
    <label class="f s3">Observações<textarea name="observacoes" placeholder="Opcional"></textarea></label>
  </div><p class="im-hint">As tarefas da trilha são copiadas para o cliente. Depois dá para acrescentar ou retirar tarefas só dele.</p></div>
  <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><button class="im-btn pri" type="submit">Criar implantação</button></div>
</form></dialog>

<script>
(function(){
  const CSRF=<?= json_encode(impl_csrf()) ?>, IMPL=<?= (int)($impl['id'] ?? 0) ?>;
  async function post(data){
    const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf',CSRF);
    const r=await fetch('implantacao_api.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
    const j=await r.json().catch(()=>({ok:false,error:'Falha de comunicação.'}));
    if(!j.ok)throw new Error(j.error||'Não foi possível concluir.');return j;
  }
  const board=document.getElementById('imBoard');
  if(board){
    board.addEventListener('change',async e=>{
      const cb=e.target.closest('input[data-it]');if(!cb)return;
      const li=cb.closest('li');cb.disabled=true;
      try{
        const j=await post({action:'toggle',impl:IMPL,it:cb.dataset.it,feito:cb.checked?1:''});
        li.classList.toggle('done',cb.checked);
        li.querySelector('[data-info]').textContent=cb.checked?'Feito agora':'';
        const rm=li.querySelector('[data-rm]');if(rm)rm.hidden=cb.checked;
        const col=li.closest('.im-col'),all=col.querySelectorAll('input[data-it]'),on=col.querySelectorAll('input[data-it]:checked');
        col.querySelector('[data-n]').textContent=on.length+'/'+all.length;
        const pct=j.total?Math.floor(j.feitas*100/j.total):0;
        document.getElementById('imBar').style.width=pct+'%';document.getElementById('imPct').textContent=pct+'% · '+j.feitas+'/'+j.total;
        if(j.status&&j.status.nome){const c=j.status.cor||'#2563eb';document.getElementById('imStatusPill').innerHTML='<span class="im-pill" style="color:'+c+';border-color:'+c+'55;background:'+c+'14"><i style="background:'+c+'"></i>'+j.status.nome.replace(/[<>&]/g,'')+'</span>';const s=document.getElementById('imStatusSel');if(s)s.value=String(j.status.id);}
      }catch(err){cb.checked=!cb.checked;alert(err.message);}
      finally{cb.disabled=false;}
    });
    board.addEventListener('click',async e=>{
      const b=e.target.closest('[data-rm]');if(!b)return;
      if(!confirm('Retirar esta tarefa da trilha deste cliente?'))return;
      try{await post({action:'rm_tarefa',impl:IMPL,it:b.dataset.rm});location.reload();}catch(err){alert(err.message);}
    });
  }
  document.getElementById('imStatusSel')?.addEventListener('change',async e=>{
    if(!e.target.value)return;
    try{await post({action:'status',impl:IMPL,status_id:e.target.value});location.reload();}catch(err){alert(err.message);}
  });
  document.getElementById('imConcluir')?.addEventListener('click',async()=>{
    const pend=document.querySelectorAll('#imBoard input[data-it]:not(:checked)').length;
    if(!confirm((pend?'Ainda há '+pend+' tarefa(s) sem marcar.\n\n':'')+'Concluir o treinamento deste cliente? O status passa para Concluído e o relatório de conclusão fica disponível na Documentação.'))return;
    try{await post({action:'concluir',impl:IMPL});location.reload();}catch(err){alert(err.message);}
  });
  document.getElementById('imAddGo')?.addEventListener('click',async()=>{
    const v=document.getElementById('imAddSel').value;if(!v){alert('Não há outras tarefas no cadastro.');return;}
    try{await post({action:'add_tarefa',impl:IMPL,tarefa_id:v});location.reload();}catch(err){alert(err.message);}
  });
  // Nova implantação: busca do cliente em Pessoas.
  const q=document.getElementById('imPersonQ'),res=document.getElementById('imPersonRes'),hid=document.getElementById('imPerson');let tm=null;
  q?.addEventListener('input',()=>{hid.value='';clearTimeout(tm);tm=setTimeout(async()=>{
    const v=q.value.trim();if(v.length<2){res.innerHTML='';return;}
    try{const r=await fetch('implantacao_api.php?action=pessoas&q='+encodeURIComponent(v),{credentials:'same-origin'});const j=await r.json();
      res.innerHTML=(j.items||[]).map(p=>'<button type="button" class="im-btn sm" style="margin:2px" data-pid="'+p.id+'" data-pn="'+String(p.name).replace(/"/g,'&quot;')+'">'+String(p.name).replace(/[<>&]/g,'')+(p.city?' · '+String(p.city).replace(/[<>&]/g,''):'')+'</button>').join('')||'<span class="im-hint">Nenhum cliente encontrado em Pessoas.</span>';
    }catch(e){res.innerHTML='';}
  },250);});
  res?.addEventListener('click',e=>{const b=e.target.closest('[data-pid]');if(!b)return;hid.value=b.dataset.pid;q.value=b.dataset.pn;res.innerHTML='<span class="im-badge g">Cliente escolhido</span>';});
  document.getElementById('imNovaForm')?.addEventListener('submit',e=>{if(!hid.value){e.preventDefault();alert('Escolha o cliente na lista que aparece ao digitar o nome.');setTimeout(()=>{const b=e.target.querySelector('button[type=submit]');if(b)b.disabled=false;},10);}});
})();
</script>
<?php impl_end();
