<?php
declare(strict_types=1);

// Trilha de Implantação: relatório de atendimento técnico local (novo/editar/ver).
// Confirmar = as tarefas marcadas ficam executadas na trilha do cliente.
// Assinatura do cliente: no celular do técnico (quadro abaixo) ou por link (WhatsApp).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_implantacao.php';
$cid = impl_guard($pdo, 'crm_implantacao_relatorios');
$uid = (int)(auth_user_id() ?? 0);
$id = (int)($_GET['id'] ?? 0);

if (impl_ready($pdo)) {
  impl_post_handle('implantacao_relatorio.php' . ($id > 0 ? '?id=' . $id : ''), function () use ($pdo, $cid, $uid, $id) {
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'excluir') {
      impl_relatorio_excluir($pdo, $cid, $id);
      return ['Relatório excluído.', 'implantacao_relatorios.php'];
    }
    if (!in_array($a, ['salvar', 'confirmar'], true)) throw new RuntimeException('Ação inválida.');
    $atual = $id > 0 ? impl_relatorio($pdo, $cid, $id) : null;
    if ($a === 'confirmar' && $atual && !empty($atual['assinatura_arquivo'])) {
      // Já assinado pelo cliente: o conteúdo está travado, só confirma.
      impl_relatorio_confirmar($pdo, $cid, $id, $uid);
      return ['Relatório confirmado. Os processos marcados ficaram como executados na trilha do cliente.', 'implantacao_relatorio.php?id=' . $id];
    }
    $in = $_POST; $in['id'] = $id;
    $newId = impl_relatorio_salvar($pdo, $cid, $in, $uid);
    if ($a === 'confirmar') {
      try { impl_relatorio_confirmar($pdo, $cid, $newId, $uid); }
      catch (RuntimeException $e) { impl_flash('err', 'Relatório salvo como rascunho, mas não foi confirmado: ' . $e->getMessage()); impl_redirect('implantacao_relatorio.php?id=' . $newId); }
      return ['Relatório confirmado. Os processos marcados ficaram como executados na trilha do cliente.', 'implantacao_relatorio.php?id=' . $newId];
    }
    return ['Relatório salvo (rascunho).', 'implantacao_relatorio.php?id=' . $newId];
  });
}

$ready = impl_ready($pdo);
$rel = ($ready && $id > 0) ? impl_relatorio($pdo, $cid, $id) : null;
if ($id > 0 && $ready && !$rel) { impl_flash('err', 'Relatório não encontrado.'); impl_redirect('implantacao_relatorios.php'); }
$implId = $rel ? (int)$rel['implantacao_id'] : (int)($_GET['impl'] ?? 0);
$impl = ($ready && $implId > 0) ? impl_implantacao($pdo, $cid, $implId) : null;
$confirmado = $rel && $rel['status'] === 'CONFIRMADO';
$assinado = $rel && !empty($rel['assinatura_arquivo']);
$travado = $confirmado || $assinado; // depois da assinatura o conteúdo não muda mais
$users = impl_users($pdo, $cid);
$lista = $ready && !$rel ? impl_implantacoes($pdo, $cid) : [];
$etapas = $impl ? impl_impl_tarefas($pdo, $cid, $implId) : [];
$marcadas = $rel ? array_flip($rel['tarefas']) : [];

$titulo = $rel ? 'Relatório de atendimento nº ' . (int)$rel['numero'] : 'Novo relatório de atendimento';
$acoes = '<a class="im-btn" href="implantacao_relatorios.php">← Relatórios</a>';
if ($rel) $acoes .= '<a class="im-btn" target="_blank" href="implantacao_relatorio_imprimir.php?id=' . (int)$rel['id'] . '">Imprimir</a>';
if ($impl) $acoes .= '<a class="im-btn" href="implantacao.php?impl=' . (int)$impl['id'] . '">Ver trilha</a>';
impl_start($pdo, 'relatorios', $titulo, $confirmado ? 'Relatório confirmado em ' . date('d/m/Y H:i', strtotime((string)$rel['confirmado_em'])) . '. Os processos abaixo já constam como executados.' : 'Preencha durante ou ao final do atendimento. Ao confirmar, os processos marcados ficam executados na trilha do cliente.', $acoes);
$v = static fn(string $k, $def = '') => $rel ? ($rel[$k] ?? $def) : $def;
?>
<form method="post" id="imRelForm"><?= impl_csrf_field() ?><input type="hidden" name="action" id="imRelAction" value="salvar">
<div class="im-cols">
  <div class="im-card">
    <h2>Atendimento</h2>
    <div class="im-grid">
      <label class="f s3">Cliente (implantação) *
        <?php if ($rel || $confirmado): ?>
          <input type="text" value="<?= impl_h(($impl['cliente'] ?? '') . ' — ' . ($impl['trilha_nome'] ?? '')) ?>" disabled>
        <?php else: ?>
          <select name="implantacao_id" required onchange="location.href='implantacao_relatorio.php?impl='+this.value">
            <option value="">Selecione o cliente…</option>
            <?php foreach ($lista as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $implId ? 'selected' : '' ?>><?= impl_h($l['cliente'] . ' — ' . ($l['trilha_nome'] ?: 'Trilha') . (($l['status_tipo'] ?? '') === 'CONCLUIDO' ? ' (concluída)' : '')) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
      </label>
      <label class="f">Data *<input type="date" name="data" value="<?= impl_h($v('data', date('Y-m-d'))) ?>" required <?= $travado ? 'disabled' : '' ?>></label>
      <label class="f">Implantador<select name="implantador_user_id" <?= $travado ? 'disabled' : '' ?>><option value="0">—</option><?php $cur = (int)$v('implantador_user_id', $uid); foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $cur ? 'selected' : '' ?>><?= impl_h($u['name']) ?></option><?php endforeach; ?></select></label>
      <label class="f">Hora inicial *<input type="time" name="hora_inicio" value="<?= impl_h(substr((string)$v('hora_inicio', ''), 0, 5)) ?>" required <?= $travado ? 'disabled' : '' ?>></label>
      <label class="f">Hora final *<input type="time" name="hora_fim" value="<?= impl_h(substr((string)$v('hora_fim', ''), 0, 5)) ?>" required <?= $travado ? 'disabled' : '' ?>></label>
      <label class="f s3">Participantes do treinamento * <span class="im-hint">(um nome por linha)</span><textarea name="participantes" rows="4" placeholder="Maria Souza&#10;João Lima" <?= $travado ? 'disabled' : '' ?>><?= impl_h($v('participantes', '')) ?></textarea></label>
      <label class="f s3">Observações<textarea name="observacoes" rows="4" placeholder="O que foi feito, pendências, combinados com o cliente…" <?= $travado ? 'disabled' : '' ?>><?= impl_h($v('observacoes', '')) ?></textarea></label>
    </div>
  </div>

  <div class="im-card">
    <h2>Processos implantados</h2>
    <p class="im-sub"><?= $confirmado ? 'Processos deste atendimento.' : 'Marque o que foi implantado neste atendimento. Os que já foram feitos antes aparecem marcados e travados.' ?></p>
    <?php if (!$impl): ?><div class="im-empty">Escolha o cliente para ver os processos da trilha dele.</div>
    <?php else: foreach ($etapas as $e): ?>
      <div style="margin:0 0 10px">
        <div style="font-weight:900;color:#0b1f5b;font-size:13.5px;margin-bottom:4px"><span class="im-swatch" style="width:10px;height:10px;border-radius:50%;background:<?= impl_h(impl_cor($e['cor'])) ?>"></span> <?= impl_h($e['nome']) ?></div>
        <ul class="im-checks">
          <?php foreach ($e['tarefas'] as $t):
            $neste = isset($marcadas[(int)$t['id']]);
            $outro = $t['done_at'] !== null && (int)($t['relatorio_id'] ?? 0) !== (int)($rel['id'] ?? -1);
            if ($confirmado && !$neste) continue; ?>
            <li><label class="ck" style="font-weight:600">
              <input type="checkbox" name="tarefas[]" value="<?= (int)$t['id'] ?>" <?= ($neste || $outro) ? 'checked' : '' ?> <?= ($travado || $outro) ? 'disabled' : '' ?>>
              <span><?= impl_h($t['nome']) ?><?php if ($outro): ?> <small class="im-hint">· já feito em <?= impl_h(date('d/m/Y', strtotime((string)$t['done_at']))) ?></small><?php endif; ?></span>
            </label></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php if (!$confirmado): ?>
  <div class="im-card" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:center">
    <?php if ($assinado): ?>
      <span class="im-hint" style="margin-right:auto">O cliente já assinou; o relatório não pode mais ser alterado.</span>
      <button class="im-btn ok" type="submit" data-keep onclick="document.getElementById('imRelAction').value='confirmar'" id="imRelConfirm">✓ Confirmar relatório</button>
    <?php else: ?>
      <?php if ($rel): ?><button class="im-btn red" type="button" id="imRelDel" data-keep>Excluir rascunho</button><?php endif; ?>
      <button class="im-btn" type="submit" data-keep onclick="document.getElementById('imRelAction').value='salvar'">Salvar rascunho</button>
      <button class="im-btn ok" type="submit" data-keep onclick="document.getElementById('imRelAction').value='confirmar'" id="imRelConfirm">✓ Salvar e confirmar</button>
    <?php endif; ?>
  </div>
<?php endif; ?>
</form>
<?php if ($rel && !$confirmado && !$assinado): ?><form method="post" id="imRelDelForm" data-confirm="Excluir este rascunho de relatório?"><?= impl_csrf_field() ?><input type="hidden" name="action" value="excluir"></form><?php endif; ?>

<?php if ($rel): ?>
<div class="im-card" style="margin-top:12px">
  <h2>Assinatura do cliente</h2>
  <?php if ($rel['assinatura_arquivo']): ?>
    <p class="im-sub">Assinado por <strong><?= impl_h($rel['assinatura_nome']) ?></strong> em <?= impl_h(date('d/m/Y H:i', strtotime((string)$rel['assinatura_em']))) ?> (<?= $rel['assinatura_origem'] === 'LINK' ? 'pelo link, no celular do cliente' : 'no celular do técnico' ?>).</p>
    <img src="implantacao_arquivo.php?f=<?= rawurlencode((string)$rel['assinatura_arquivo']) ?>" alt="Assinatura" style="max-width:420px;width:100%;border:1px solid #e2e8f0;border-radius:12px;background:#fff">
  <?php else: ?>
    <div class="im-mtabs"><button type="button" class="on" data-pane="pAqui">Assinar aqui (celular do técnico)</button><button type="button" data-pane="pLink">Enviar link ao cliente</button></div>
    <div class="im-pane on" id="pAqui">
      <p class="im-sub">Entregue o celular ao cliente para assinar com o dedo no quadro.<?= $confirmado ? '' : ' Depois da assinatura o relatório não pode mais ser alterado: salve o que precisar antes.' ?></p>
      <canvas class="im-sig" id="imSig"></canvas>
      <div class="im-search" style="margin-top:8px">
        <label class="f grow">Nome de quem assina *<input type="text" id="imSigNome" placeholder="Nome completo" autocomplete="name"></label>
        <button class="im-btn" type="button" id="imSigLimpar">Limpar</button>
        <button class="im-btn pri" type="button" id="imSigSalvar">Salvar assinatura</button>
      </div>
    </div>
    <div class="im-pane" id="pLink">
      <p class="im-sub">Gera um link (vale 7 dias) para o cliente abrir no celular dele, conferir o relatório e assinar.</p>
      <button class="im-btn pri" type="button" id="imLinkGerar"><?= $rel['link_hash'] ? 'Gerar novo link' : 'Gerar link' ?></button>
      <div id="imLinkBox" hidden style="margin-top:10px">
        <label class="f">Link para assinatura<input type="text" id="imLinkUrl" readonly></label>
        <div class="im-actions" style="margin-top:8px"><button class="im-btn" type="button" id="imLinkCopiar">Copiar link</button><a class="im-btn ok" id="imLinkWa" target="_blank" rel="noopener">Enviar pelo WhatsApp</a></div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script src="/assets/implantacao/assinatura.js?v=1"></script>
<script>
(function(){
  const CSRF=<?= json_encode(impl_csrf()) ?>, REL=<?= (int)($rel['id'] ?? 0) ?>;
  const FONE=<?= json_encode(preg_replace('/\D+/', '', (string)(($rel['cliente_whatsapp'] ?? '') ?: ($rel['cliente_phone'] ?? '')))) ?>;
  const CLI=<?= json_encode((string)($rel['cliente'] ?? '')) ?>, NUM=<?= (int)($rel['numero'] ?? 0) ?>;
  async function post(data){
    const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf',CSRF);
    const r=await fetch('implantacao_api.php',{method:'POST',body:fd,credentials:'same-origin'});
    const j=await r.json().catch(()=>({ok:false,error:'Falha de comunicação.'}));
    if(!j.ok)throw new Error(j.error||'Não foi possível concluir.');return j;
  }
  document.getElementById('imRelConfirm')?.addEventListener('click',e=>{
    if(!confirm('Confirmar o relatório?\n\nOs processos marcados ficam como executados na trilha do cliente e o relatório não poderá mais ser alterado (a assinatura pode ser colhida depois).'))e.preventDefault();
  });
  document.getElementById('imRelDel')?.addEventListener('click',()=>{const f=document.getElementById('imRelDelForm');if(confirm(f.dataset.confirm))f.submit();});
  const cv=document.getElementById('imSig');
  if(cv){
    const pad=NexaAssinatura(cv);
    document.getElementById('imSigLimpar').addEventListener('click',()=>pad.clear());
    document.getElementById('imSigSalvar').addEventListener('click',async e=>{
      const nome=document.getElementById('imSigNome').value.trim();
      if(pad.isEmpty()){alert('Peça ao cliente para assinar no quadro.');return;}
      if(nome.length<3){alert('Informe o nome de quem está assinando.');return;}
      e.target.disabled=true;
      try{await post({action:'assinar',rel:REL,nome,assinatura:pad.toDataURL()});location.reload();}catch(err){alert(err.message);e.target.disabled=false;}
    });
  }
  document.getElementById('imLinkGerar')?.addEventListener('click',async e=>{
    e.target.disabled=true;
    try{
      const j=await post({action:'link',rel:REL});
      document.getElementById('imLinkBox').hidden=false;document.getElementById('imLinkUrl').value=j.url;
      const txt='Olá! Segue o relatório do atendimento técnico nº '+NUM+(CLI?' ('+CLI+')':'')+' para conferir e assinar: '+j.url;
      const wa=document.getElementById('imLinkWa');wa.href='https://wa.me/'+(FONE?(FONE.length<=11?'55'+FONE:FONE):'')+'?text='+encodeURIComponent(txt);
    }catch(err){alert(err.message);}finally{e.target.disabled=false;}
  });
  document.getElementById('imLinkCopiar')?.addEventListener('click',async()=>{const i=document.getElementById('imLinkUrl');try{await navigator.clipboard.writeText(i.value);alert('Link copiado.');}catch(e){i.select();document.execCommand('copy');}});
})();
</script>
<?php impl_end();
