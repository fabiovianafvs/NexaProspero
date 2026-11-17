<?php
declare(strict_types=1);

// Ponto Eletrônico > Colaboradores: cadastro com foto (arquivo ou câmera, com recorte),
// vínculo opcional com usuário do Nexa, cartão RFID e histórico de escalas.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_marcacao.php';

$cid = ponto_guard($pdo, 'ponto_colaboradores');
$f = [
  'q' => trim((string)($_GET['q'] ?? '')),
  'status' => in_array((string)($_GET['status'] ?? ''), ['ATIVOS', 'ATIVO', 'AFASTADO', 'DESLIGADO', 'TODOS'], true) ? (string)$_GET['status'] : 'ATIVOS',
  'departamento_id' => (int)($_GET['dep'] ?? 0),
  'estabelecimento_id' => (int)($_GET['est'] ?? 0),
];
$back = 'ponto_colaboradores.php?' . http_build_query(array_filter(['q' => $f['q'], 'status' => $f['status'] !== 'ATIVOS' ? $f['status'] : null, 'dep' => $f['departamento_id'] ?: null, 'est' => $f['estabelecimento_id'] ?: null]));

// Próximos dias previstos (aba Escala) — JSON.
if (isset($_GET['previsto'])) {
  $col = ponto_colaborador_get($pdo, $cid, (int)$_GET['previsto']);
  if (!$col) ponto_json(['ok' => false, 'error' => 'Colaborador não encontrado.'], 404);
  $start = ponto_date_safe($_GET['de'] ?? '', date('Y-m-d'));
  $out = [];
  for ($i = 0; $i < 14; $i++) {
    $d = date('Y-m-d', strtotime($start . ' +' . $i . ' days'));
    $p = ponto_previsto_do_dia($pdo, $cid, $col, $d);
    $out[] = ['data' => $d, 'dia' => PONTO_DIAS_CURTOS[(int)date('w', strtotime($d)) + 1], 'trabalha' => $p['trabalha'],
      'horario' => implode('  ', array_map(static fn($x) => $x['ini'] . '–' . $x['fim'], $p['periodos'])), 'total' => $p['minutos'] ? ponto_minutes_label($p['minutos']) : '',
      'feriado' => $p['feriado'], 'escala' => $p['escala'], 'origem' => $p['origem']];
  }
  ponto_json(['ok' => true, 'dias' => $out, 'historico' => ponto_colaborador_escalas($pdo, $cid, (int)$col['id'])]);
}

$withOpen = static function (int $id, string $aba) use ($back): string {
  return $back . (str_ends_with($back, '?') ? '' : '&') . 'abrir=' . $id . '&aba=' . $aba;
};
ponto_post_handle($back, function () use ($pdo, $cid, $withOpen) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save') {
    $isNew = (int)($_POST['id'] ?? 0) === 0;
    // PIN do terminal (V2): confere antes de gravar o cadastro; grava só o hash.
    $pinV2 = ponto_v2_ready($pdo);
    $pin = trim((string)($_POST['pin'] ?? ''));
    if ($pinV2 && $pin !== '' && empty($_POST['pin_remover'])) ponto_pin_validate($pin);
    $id = ponto_colaborador_save($pdo, $cid, $_POST);
    if ($pinV2 && !empty($_POST['pin_remover'])) ponto_pin_set($pdo, $cid, $id, '');
    elseif ($pinV2 && $pin !== '') ponto_pin_set($pdo, $cid, $id, $pin);
    // Cadastro novo: reabre na aba Foto para já tirar/enviar a foto.
    return $isNew ? ['Colaborador cadastrado. Agora você pode incluir a foto.', $withOpen($id, 'foto')] : 'Cadastro alterado.';
  }
  if ($action === 'escala') {
    $id = (int)($_POST['id'] ?? 0);
    if (trim((string)($_POST['escala_id'] ?? '')) === '') throw new RuntimeException('Escolha a escala (ou "Encerrar escala atual").');
    ponto_colaborador_escala_set($pdo, $cid, $id, (int)($_POST['escala_id'] ?? 0), (string)($_POST['inicio'] ?? ''), (string)($_POST['ciclo_inicio'] ?? ''), (string)($_POST['observacao'] ?? ''));
    $msg = (int)($_POST['escala_id'] ?? 0) > 0 ? 'Escala definida. A anterior ficou no histórico.' : 'Escala encerrada. O colaborador volta a seguir a jornada padrão.';
    return [$msg, $withOpen($id, 'escala')];
  }
  throw new RuntimeException('Ação inválida.');
});

$rows = ponto_colaborador_list($pdo, $cid, $f + ['status' => $f['status'] === 'TODOS' ? '' : $f['status']]);
$opt = [];
foreach (['estabelecimentos', 'departamentos', 'cargos', 'jornadas', 'escalas', 'locais'] as $r) $opt[$r] = ponto_options($pdo, $cid, $r);
$users = ponto_company_users($pdo, $cid);
$escalaBase = [];
foreach (ponto_escala_list($pdo, $cid) as $e) $escalaBase[(int)$e['id']] = $e['base'];
$statusBadge = ['ATIVO' => '<span class="pt-badge g">Ativo</span>', 'AFASTADO' => '<span class="pt-badge y">Afastado</span>', 'DESLIGADO' => '<span class="pt-badge n">Desligado</span>'];
$byId = [];
foreach ($rows as $r) {
  $r['cpf_fmt'] = ponto_fmt_doc((string)$r['cpf']);
  $r['pin_set'] = !empty($r['pin_hash']) ? 1 : 0;
  unset($r['rfid_hash'], $r['foto_path'], $r['pin_hash']);
  $byId[(int)$r['id']] = $r;
}
$openId = (int)($_GET['abrir'] ?? 0);
if ($openId && !isset($byId[$openId])) {
  $one = ponto_colaborador_get($pdo, $cid, $openId);
  if ($one) { $one['cpf_fmt'] = ponto_fmt_doc((string)$one['cpf']); $one['pin_set'] = !empty($one['pin_hash']) ? 1 : 0; unset($one['rfid_hash'], $one['foto_path'], $one['pin_hash']); $byId[$openId] = $one; }
}
$sel = static function (string $name, array $options, string $empty = '—'): string {
  $h = '<select name="' . ponto_h($name) . '"><option value="">' . ponto_h($empty) . '</option>';
  foreach ($options as $v => $lb) $h .= '<option value="' . ponto_h($v) . '">' . ponto_h($lb) . '</option>';
  return $h . '</select>';
};

ponto_start($pdo, 'ponto_colaboradores', 'Colaboradores', 'Quem tem a jornada controlada. O colaborador não precisa ter usuário no Nexa; quando tiver, ligue o usuário para ele bater o próprio ponto em "Bater Ponto" (celular ou computador).', '<button class="pt-btn pri" type="button" onclick="pcNew()">+ Cadastrar</button>');
?>
<style>
.pc-ph{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#e2e8f0;display:inline-grid;place-items:center;font-weight:900;color:#475569;font-size:14px;vertical-align:middle;flex:none}
.pc-name{display:flex;gap:10px;align-items:center}.pc-name small{display:block;color:#64748b;font-weight:600;font-size:12px}
.pc-photo{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start}
.pc-cur{width:200px;height:200px;border-radius:20px;background:#f1f5f9;border:1px solid #e2e8f0;display:grid;place-items:center;overflow:hidden;color:#94a3b8;font-weight:800}
.pc-cur img{width:100%;height:100%;object-fit:cover}
.pc-crop{position:relative;width:min(320px,80vw);height:min(320px,80vw);border-radius:18px;overflow:hidden;background:#0f172a;touch-action:none;cursor:grab;margin:0 auto}
.pc-crop canvas{display:block;width:100%;height:100%}
.pc-crop::after{content:'';position:absolute;inset:0;border-radius:50%;box-shadow:0 0 0 999px rgba(15,23,42,.45);pointer-events:none;outline:2px solid rgba(255,255,255,.8)}
.pc-cam video{width:min(420px,100%);border-radius:16px;background:#0f172a;display:block;margin:0 auto}
.pc-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}
.pc-day{border:1px solid #e2e8f0;border-radius:12px;padding:7px;font-size:12px;min-height:74px;background:#fff}
.pc-day b{display:block;font-size:12px;color:#0b1f5b}.pc-day.off{background:#f8fafc;color:#94a3b8}.pc-day.fer{background:#fffbeb;border-color:#fde68a}
.pc-day span{display:block;margin-top:3px;line-height:1.3;overflow-wrap:anywhere}
@media(max-width:760px){.pc-photo{grid-template-columns:1fr}.pc-cur{margin:0 auto}.pc-days{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<section class="pt-card">
  <form method="get" class="pt-search">
    <label class="f grow"><span>Pesquisar <span class="pt-kbd" title="Tecla F8 vai direto para a pesquisa">F8</span></span><input type="text" name="q" value="<?= ponto_h($f['q']) ?>" data-pt-search placeholder="Nome, matrícula, CPF ou e-mail"></label>
    <label class="f" style="width:150px">Situação<select name="status" onchange="this.form.submit()"><?php foreach (['ATIVOS' => 'Ativos e afastados', 'ATIVO' => 'Ativos', 'AFASTADO' => 'Afastados', 'DESLIGADO' => 'Desligados', 'TODOS' => 'Todos'] as $v => $lb): ?><option value="<?= $v ?>" <?= $f['status'] === $v ? 'selected' : '' ?>><?= $lb ?></option><?php endforeach; ?></select></label>
    <?php if ($opt['departamentos']): ?><label class="f" style="width:170px">Departamento<select name="dep" onchange="this.form.submit()"><option value="0">Todos</option><?php foreach ($opt['departamentos'] as $v => $lb): ?><option value="<?= (int)$v ?>" <?= $f['departamento_id'] === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <?php if (count($opt['estabelecimentos']) > 1): ?><label class="f" style="width:170px">Estabelecimento<select name="est" onchange="this.form.submit()"><option value="0">Todos</option><?php foreach ($opt['estabelecimentos'] as $v => $lb): ?><option value="<?= (int)$v ?>" <?= $f['estabelecimento_id'] === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <button class="pt-btn" type="submit">Pesquisar</button>
    <button class="pt-btn pri" type="button" onclick="pcNew()">Cadastrar</button>
  </form>
  <?php if (!$rows): ?>
    <div class="pt-empty"><?= $f['q'] !== '' ? 'Ninguém encontrado para "' . ponto_h($f['q']) . '".' : 'Nenhum colaborador cadastrado ainda. Antes, cadastre o estabelecimento e as jornadas.' ?></div>
  <?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th style="width:30%">Colaborador</th><th>Departamento / cargo</th><th>Jornada / escala</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $ini = mb_strtoupper(mb_substr(trim((string)$r['nome']), 0, 1)); ?>
      <tr class="<?= $r['status'] === 'DESLIGADO' ? 'off' : '' ?>">
        <td><div class="pc-name"><?php if (!empty($r['foto_hash'])): ?><img class="pc-ph" src="ponto_colaborador_foto.php?id=<?= (int)$r['id'] ?>&v=<?= ponto_h(substr((string)$r['foto_hash'], 0, 10)) ?>" alt="" loading="lazy"><?php else: ?><span class="pc-ph"><?= ponto_h($ini) ?></span><?php endif; ?><div><b><?= ponto_h($r['nome']) ?></b><small>Matr. <?= ponto_h($r['matricula']) ?> · CPF <?= ponto_h(ponto_fmt_doc((string)$r['cpf'])) ?><?= (int)$r['controla_ponto'] ? '' : ' · não controla ponto' ?></small></div></div></td>
        <td data-l="Departamento"><?= ponto_h($r['departamento'] ?? '—') ?><br><small class="pt-hint"><?= ponto_h($r['cargo'] ?? '') ?></small></td>
        <td data-l="Jornada"><?= $r['escala'] ? '<span class="pt-badge">' . ponto_h($r['escala']) . '</span>' : ($r['jornada'] ? ponto_h($r['jornada']) : '<span class="pt-badge r">Sem jornada</span>') ?></td>
        <td data-l="Situação"><?= $statusBadge[$r['status']] ?? ponto_h($r['status']) ?></td>
        <td class="act"><button class="pt-btn sm" type="button" onclick="pcEdit(<?= (int)$r['id'] ?>)">Abrir</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="pt-hint" style="margin:8px 2px 0"><?= count($rows) ?> colaborador(es). Ninguém é excluído: quem sai fica como "Desligado" e o histórico continua disponível.</p>
  <?php endif; ?>
</section>

<dialog class="pt-modal wide" id="pcModal">
  <div class="pt-mh"><h3 id="pcTitle">Colaborador</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
  <div class="pt-mb">
    <div class="pt-mtabs">
      <button type="button" data-pane="pcDados" class="on">Dados</button>
      <button type="button" data-pane="pcTrab">Trabalho</button>
      <button type="button" data-pane="pcPonto">Ponto e identificação</button>
      <button type="button" data-pane="pcFoto" data-needs-id>Foto</button>
      <button type="button" data-pane="pcEscala" data-needs-id>Escala</button>
    </div>
    <form method="post" id="pcForm">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
      <div class="pt-pane on" id="pcDados"><div class="pt-grid three">
        <label class="f s2">Nome completo *<input type="text" name="nome" maxlength="120" required></label>
        <label class="f">Matrícula<input type="text" name="matricula" maxlength="20" placeholder="automática"></label>
        <label class="f">CPF *<input type="text" name="cpf" inputmode="numeric" maxlength="14" required placeholder="000.000.000-00"></label>
        <label class="f">Nascimento<input type="date" name="data_nascimento"></label>
        <label class="f">Telefone<input type="tel" name="telefone" inputmode="tel" maxlength="20"></label>
        <label class="f s2">E-mail<input type="email" name="email" maxlength="150"></label>
        <label class="f">Situação<select name="status"><option value="ATIVO">Ativo</option><option value="AFASTADO">Afastado</option><option value="DESLIGADO">Desligado</option></select></label>
      </div></div>
      <div class="pt-pane" id="pcTrab"><div class="pt-grid three">
        <label class="f">Admissão *<input type="date" name="data_admissao" required></label>
        <label class="f">Desligamento<input type="date" name="data_demissao"></label>
        <label class="f">Estabelecimento<?= $sel('estabelecimento_id', $opt['estabelecimentos']) ?></label>
        <label class="f">Departamento<?= $sel('departamento_id', $opt['departamentos']) ?></label>
        <label class="f">Cargo/função<?= $sel('cargo_id', $opt['cargos']) ?></label>
        <label class="f">Jornada padrão<?= $sel('jornada_id', $opt['jornadas'], 'Sem jornada') ?></label>
        <label class="f s2">Local de trabalho principal<?= $sel('local_id', $opt['locais']) ?></label>
      </div>
      <p class="pt-hint">A jornada padrão vale quando o colaborador não está em uma escala. Escalas (5x2, 12x36...) são definidas na aba Escala, com data de início.</p></div>
      <div class="pt-pane" id="pcPonto"><div class="pt-grid">
        <label class="ck s3"><input type="checkbox" name="controla_ponto" value="1"> Controla ponto (tem a jornada registrada)</label>
        <label class="ck s3"><input type="checkbox" name="permite_ponto_mobile" value="1"> Pode bater o ponto pelo celular</label>
        <label class="ck s3"><input type="checkbox" name="permite_ponto_externo" value="1"> Pode bater o ponto fora dos locais de trabalho (serviço externo)</label>
        <label class="f s2">Usuário do Nexa (opcional)<?= $sel('usuario_id', $users, 'Sem usuário') ?></label>
        <label class="f">Código interno<input type="text" name="codigo_interno" maxlength="30"></label>
        <label class="f">Cartão RFID <span class="pt-hint" id="pcRfidNow"></span><input type="text" name="rfid" maxlength="40" autocomplete="off" placeholder="Aproxime o cartão do leitor"></label>
      </div>
      <?php if (ponto_v2_ready($pdo)): ?>
      <div class="pt-grid" style="margin-top:10px">
        <label class="f">PIN do terminal <span class="pt-hint" id="pcPinNow"></span><input type="password" name="pin" inputmode="numeric" maxlength="6" autocomplete="new-password" placeholder="4 a 6 números"></label>
        <label class="ck" style="align-self:end;min-height:40px!important"><input type="checkbox" name="pin_remover" value="1"> Remover o PIN</label>
      </div>
      <?php endif; ?>
      <p class="pt-hint">O número do cartão não fica gravado: o sistema guarda só um código de conferência e os 4 últimos dígitos. Deixe em branco para manter; digite <b>-</b> para remover o cartão. O PIN (usado no terminal com a matrícula ou o CPF) também fica só em código: ninguém consegue ver o PIN depois; para trocar, digite um novo. Deixe em branco para manter.</p></div>
    </form>
    <div class="pt-pane" id="pcFoto">
      <div class="pc-photo">
        <div><div class="pc-cur" id="pcCur">Sem foto</div><p class="pt-hint" id="pcFotoInfo" style="text-align:center"></p></div>
        <div>
          <div id="pcFotoStart">
            <p class="pt-sub">A foto identifica o colaborador nas telas do ponto. Não é biometria: não é usada para reconhecimento facial.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <label class="pt-btn">Enviar arquivo<input type="file" accept="image/jpeg,image/png,image/webp" id="pcFile" hidden></label>
              <button class="pt-btn" type="button" onclick="pcCamOpen()">Tirar foto pela câmera</button>
              <button class="pt-btn red" type="button" id="pcRemove" onclick="pcRemove()">Remover foto</button>
            </div>
          </div>
          <div class="pc-cam" id="pcCam" hidden>
            <video id="pcVideo" autoplay playsinline muted></video>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:8px"><button class="pt-btn pri" type="button" onclick="pcCamShot()">Capturar</button><button class="pt-btn" type="button" onclick="pcCamStop()">Cancelar</button></div>
          </div>
          <div id="pcCropBox" hidden>
            <div class="pc-crop" id="pcCrop"><canvas id="pcCanvas" width="640" height="640"></canvas></div>
            <label class="f" style="max-width:320px;margin:10px auto 0!important">Zoom<input type="range" id="pcZoom" min="1" max="4" step="0.01" value="1"></label>
            <p class="pt-hint" style="text-align:center">Arraste a imagem para centralizar o rosto.</p>
            <div style="display:flex;gap:8px;justify-content:center"><button class="pt-btn ok" type="button" onclick="pcUpload()">Salvar foto</button><button class="pt-btn" type="button" onclick="pcCropCancel()">Cancelar</button></div>
          </div>
          <p class="pt-hint" id="pcFotoMsg"></p>
        </div>
      </div>
    </div>
    <div class="pt-pane" id="pcEscala">
      <form method="post" id="pcEscForm" class="pt-grid three" style="align-items:end">
        <?= ponto_csrf_field() ?><input type="hidden" name="action" value="escala"><input type="hidden" name="id" value="0">
        <label class="f">Nova escala<select name="escala_id" id="pcEscSel"><option value="">— escolha —</option><?php foreach ($opt['escalas'] as $v => $lb): ?><option value="<?= (int)$v ?>" data-base="<?= ponto_h($escalaBase[$v] ?? 'SEMANA') ?>"><?= ponto_h($lb) ?></option><?php endforeach; ?><option value="0">Encerrar escala atual (voltar à jornada padrão)</option></select></label>
        <label class="f">A partir de<input type="date" name="inicio" value="<?= date('Y-m-d') ?>"></label>
        <label class="f" id="pcCiclo" hidden>Primeiro dia do ciclo<input type="date" name="ciclo_inicio"></label>
        <label class="f s2">Observação<input type="text" name="observacao" maxlength="200" placeholder="Motivo da troca (opcional)"></label>
        <div><button class="pt-btn pri" type="submit">Aplicar</button></div>
      </form>
      <p class="pt-hint">Trocar de escala não apaga a anterior: ela termina no dia anterior ao início da nova e fica no histórico.</p>
      <h4 style="margin:14px 0 6px;color:#0b1f5b">Próximos 14 dias</h4>
      <div class="pc-days" id="pcDays"></div>
      <h4 style="margin:14px 0 6px;color:#0b1f5b">Histórico de escalas</h4>
      <div id="pcHist" class="pt-hint">—</div>
    </div>
  </div>
  <div class="pt-mf"><span class="pt-hint" id="pcSaveHint" style="margin-right:auto"></span><button class="pt-btn" type="button" onclick="ptClose(this)">Fechar</button><button class="pt-btn pri" type="submit" form="pcForm" id="pcSaveBtn">Salvar cadastro</button></div>
</dialog>
<dialog class="pt-modal" id="pcRemoveDlg">
  <div class="pt-mh"><h3>Remover foto</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
  <div class="pt-mb"><label class="f">Motivo *<input type="text" id="pcRemoveWhy" maxlength="200" placeholder="Ex.: foto desatualizada, pedido do colaborador"></label><p class="pt-hint">A foto sai do cadastro, mas fica guardada no histórico e a remoção é registrada na auditoria.</p></div>
  <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn red" type="button" onclick="pcRemoveGo()">Remover</button></div>
</dialog>
<script>
(function(){
  const rows=<?= json_encode($byId, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrf=<?= json_encode(ponto_csrf()) ?>;
  const dlg=document.getElementById('pcModal'), form=document.getElementById('pcForm');
  let cur=null;
  function tab(id){dlg.querySelectorAll('.pt-mtabs button').forEach(b=>b.classList.toggle('on',b.dataset.pane===id));dlg.querySelectorAll('.pt-pane').forEach(p=>p.classList.toggle('on',p.id===id));document.getElementById('pcSaveBtn').hidden=!['pcDados','pcTrab','pcPonto'].includes(id);if(id==='pcEscala')loadDays();}
  dlg.querySelector('.pt-mtabs').addEventListener('click',e=>{const b=e.target.closest('button[data-pane]');if(!b)return;e.stopPropagation();if(b.hasAttribute('data-needs-id')&&!cur){document.getElementById('pcSaveHint').textContent='Salve o cadastro primeiro.';return;}tab(b.dataset.pane);},true);
  function fill(r){
    form.querySelectorAll('[name]').forEach(el=>{if(['csrf','action'].includes(el.name))return;const v=r[el.name];
      if(el.type==='checkbox'){el.checked=v===undefined?(el.name==='controla_ponto'):String(v)==='1';return;}
      if(el.name==='rfid'||el.name==='pin'){el.value='';return;}
      if(el.name==='cpf'){el.value=r.cpf_fmt||'';return;}
      el.value=v===undefined||v===null?(el.name==='id'?0:(el.name==='status'?'ATIVO':'')):(el.type==='date'?String(v).slice(0,10):String(v));});
    document.getElementById('pcRfidNow').textContent=r.rfid_final?'(cadastrado: final '+r.rfid_final+')':'';
    const pinNow=document.getElementById('pcPinNow');if(pinNow)pinNow.textContent=String(r.pin_set)==='1'?'(cadastrado)':'(sem PIN)';
    document.getElementById('pcEscForm').querySelector('[name=id]').value=r.id||0;
    photo(r);
  }
  function photo(r){
    const box=document.getElementById('pcCur');
    box.innerHTML=r.foto_hash?'<img alt="" src="ponto_colaborador_foto.php?id='+r.id+'&v='+String(r.foto_hash).slice(0,10)+'">':'Sem foto';
    document.getElementById('pcRemove').hidden=!r.foto_hash;
    document.getElementById('pcFotoInfo').textContent=r.foto_updated_at?'Atualizada em '+new Date(r.foto_updated_at.replace(' ','T')).toLocaleString('pt-BR'):'';
  }
  window.pcNew=function(){cur=null;fill({});document.getElementById('pcTitle').textContent='Cadastrar colaborador';document.getElementById('pcSaveHint').textContent='';tab('pcDados');ptOpen('pcModal');};
  window.pcEdit=function(id,pane){cur=rows[id]||null;if(!cur)return;fill(cur);document.getElementById('pcTitle').textContent=cur.nome;document.getElementById('pcSaveHint').textContent='';tab(pane||'pcDados');ptOpen('pcModal');};
  const cpf=form.querySelector('[name=cpf]');cpf.addEventListener('input',()=>{let d=cpf.value.replace(/\D/g,'').slice(0,11);cpf.value=d.replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2');});
  // Leitor RFID em modo teclado digita e tecla Enter: não deixa o Enter enviar o formulário.
  form.querySelector('[name=rfid]').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();e.target.blur();}});

  /* ---------- Escala ---------- */
  const escSel=document.getElementById('pcEscSel');
  escSel.addEventListener('change',()=>{const o=escSel.selectedOptions[0];document.getElementById('pcCiclo').hidden=!(o&&o.dataset.base==='CICLO');});
  function loadDays(){
    if(!cur)return;const box=document.getElementById('pcDays');box.innerHTML='<span class="pt-hint">Carregando...</span>';
    fetch('ponto_colaboradores.php?previsto='+cur.id,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(!j.ok){box.textContent=j.error||'Erro';return;}
      box.innerHTML=j.dias.map(d=>{const dt=d.data.split('-');const cls=d.feriado?'fer':(d.trabalha?'':'off');
        return '<div class="pc-day '+cls+'"><b>'+d.dia+' '+dt[2]+'/'+dt[1]+'</b><span>'+(d.trabalha?esc(d.horario)+'<br><b>'+d.total+'</b>':(d.origem==='sem_jornada'?'Sem jornada':'Folga'))+'</span>'+(d.feriado?'<span>🎉 '+esc(d.feriado)+'</span>':'')+'</div>';}).join('');
      const h=j.historico;document.getElementById('pcHist').innerHTML=!h.length?'Nunca esteve em escala (segue a jornada padrão).':'<table class="pt-table"><thead><tr><th>Escala</th><th>De</th><th>Até</th><th>Observação</th></tr></thead><tbody>'+h.map(x=>'<tr><td><b>'+esc(x.escala||'#'+x.escala_id)+'</b></td><td>'+br(x.vigencia_inicio)+'</td><td>'+(x.vigencia_fim?br(x.vigencia_fim):'<span class="pt-badge g">atual</span>')+'</td><td>'+esc(x.observacao||'')+'</td></tr>').join('')+'</tbody></table>';
    }).catch(()=>{box.textContent='Não foi possível carregar.';});
  }
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const br=d=>d?d.slice(8,10)+'/'+d.slice(5,7)+'/'+d.slice(0,4):'';

  /* ---------- Foto: arquivo/câmera -> recorte quadrado -> envio ---------- */
  const cv=document.getElementById('pcCanvas'), ctx=cv.getContext('2d'), zoom=document.getElementById('pcZoom'), msg=document.getElementById('pcFotoMsg');
  let img=null, base=1, scale=1, ox=0, oy=0, stream=null;
  function show(which){document.getElementById('pcFotoStart').hidden=which!=='start';document.getElementById('pcCam').hidden=which!=='cam';document.getElementById('pcCropBox').hidden=which!=='crop';}
  function draw(){if(!img)return;ctx.fillStyle='#fff';ctx.fillRect(0,0,640,640);const s=base*scale, w=img.width*s, h=img.height*s;
    ox=Math.min(0,Math.max(640-w,ox));oy=Math.min(0,Math.max(640-h,oy));ctx.drawImage(img,ox,oy,w,h);}
  function startCrop(source){img=source;base=Math.max(640/img.width,640/img.height);scale=1;zoom.value=1;ox=(640-img.width*base)/2;oy=(640-img.height*base)/2;show('crop');draw();msg.textContent='';}
  zoom.addEventListener('input',()=>{const c=320, old=base*scale;scale=Number(zoom.value);const nw=base*scale;ox=c-(c-ox)*nw/old;oy=c-(c-oy)*nw/old;draw();});
  let drag=null;const crop=document.getElementById('pcCrop');
  crop.addEventListener('pointerdown',e=>{drag={x:e.clientX,y:e.clientY,ox,oy};crop.setPointerCapture(e.pointerId);});
  crop.addEventListener('pointermove',e=>{if(!drag)return;const k=640/crop.clientWidth;ox=drag.ox+(e.clientX-drag.x)*k;oy=drag.oy+(e.clientY-drag.y)*k;draw();});
  crop.addEventListener('pointerup',()=>{drag=null;});
  document.getElementById('pcFile').addEventListener('change',e=>{const file=e.target.files[0];e.target.value='';if(!file)return;
    if(!/^image\/(jpeg|png|webp)$/.test(file.type)){msg.textContent='Envie uma imagem JPG, PNG ou WEBP.';return;}
    const url=URL.createObjectURL(file);const im=new Image();im.onload=()=>{URL.revokeObjectURL(url);startCrop(im);};im.onerror=()=>{msg.textContent='Não foi possível abrir a imagem.';};im.src=url;});
  window.pcCamOpen=function(){
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){msg.textContent='Este navegador não permite usar a câmera (é preciso abrir o Nexa em https).';return;}
    navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:1280},height:{ideal:720}},audio:false}).then(s=>{stream=s;const v=document.getElementById('pcVideo');v.srcObject=s;show('cam');msg.textContent='';})
      .catch(e=>{msg.textContent=e.name==='NotAllowedError'?'O navegador não autorizou o uso da câmera.':'Nenhuma câmera disponível.';});
  };
  window.pcCamStop=function(){if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}show('start');};
  window.pcCamShot=function(){const v=document.getElementById('pcVideo');if(!v.videoWidth)return;const c=document.createElement('canvas');c.width=v.videoWidth;c.height=v.videoHeight;c.getContext('2d').drawImage(v,0,0);pcCamStop();startCrop(c);};
  window.pcCropCancel=function(){img=null;show('start');};
  window.pcUpload=function(){
    if(!cur)return;msg.textContent='Enviando...';
    cv.toBlob(b=>{const fd=new FormData();fd.append('csrf',csrf);fd.append('action','salvar');fd.append('id',cur.id);fd.append('foto',b,'foto.jpg');
      fetch('ponto_colaborador_foto.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
        if(!j.ok){msg.textContent=j.error||'Não foi possível salvar.';return;}
        cur.foto_hash=j.foto_hash;cur.foto_updated_at=j.foto_updated_at;photo(cur);show('start');img=null;msg.textContent='Foto salva.';
      }).catch(()=>{msg.textContent='Falha de conexão ao enviar a foto.';});},'image/jpeg',0.9);
  };
  window.pcRemove=function(){document.getElementById('pcRemoveWhy').value='';ptOpen('pcRemoveDlg');};
  window.pcRemoveGo=function(){const why=document.getElementById('pcRemoveWhy').value.trim();if(!why){document.getElementById('pcRemoveWhy').focus();return;}
    const fd=new FormData();fd.append('csrf',csrf);fd.append('action','remover');fd.append('id',cur.id);fd.append('motivo',why);
    fetch('ponto_colaborador_foto.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{ptClose('pcRemoveDlg');if(!j.ok){msg.textContent=j.error||'Erro';return;}cur.foto_hash=null;cur.foto_updated_at=null;photo(cur);msg.textContent='Foto removida.';});};
  dlg.addEventListener('close',()=>{pcCamStop();});
  <?php if ($openId && isset($byId[$openId])): ?>window.addEventListener('DOMContentLoaded',()=>pcEdit(<?= $openId ?>, <?= json_encode((string)($_GET['aba'] ?? '') === 'escala' ? 'pcEscala' : ((string)($_GET['aba'] ?? '') === 'foto' ? 'pcFoto' : 'pcDados')) ?>));<?php endif; ?>
})();
</script>
<?php ponto_end(); ?>
