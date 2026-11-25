<?php
declare(strict_types=1);

// Trilha de Implantação: cadastros de etapas, status (com cor), tarefas, trilhas e o aviso padrão.
// Cadastros não são excluídos (só inativados), para não quebrar implantações já abertas.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_implantacao.php';
$cid = impl_guard($pdo, 'crm_implantacao_cadastros');
$abas = ['etapas' => 'Etapas', 'status' => 'Status do cliente', 'tarefas' => 'Tarefas', 'trilhas' => 'Trilhas', 'config' => 'Configurações'];
$aba = (string)($_GET['aba'] ?? 'etapas');
if (!isset($abas[$aba])) $aba = 'etapas';

if (impl_ready($pdo)) {
  impl_post_handle('implantacao_cadastros.php?aba=' . $aba, function () use ($pdo, $cid) {
    $a = (string)($_POST['action'] ?? '');
    switch ($a) {
      case 'modelo': impl_seed_modelo($pdo, $cid); return ['Modelo carregado: 4 etapas, as tarefas e 2 trilhas. Ajuste como preferir.', 'implantacao_cadastros.php?aba=trilhas'];
      case 'etapa': impl_etapa_save($pdo, $cid, $_POST); return 'Etapa salva.';
      case 'status': impl_status_save($pdo, $cid, $_POST); return 'Status salvo.';
      case 'tarefa': impl_tarefa_save($pdo, $cid, $_POST); return 'Tarefa salva.';
      case 'trilha': impl_trilha_save($pdo, $cid, $_POST); return 'Trilha salva. Implantações já abertas não mudam; a trilha nova vale para as próximas.';
      case 'config': impl_config_save($pdo, $cid, $_POST); return 'Configurações salvas.';
    }
    throw new RuntimeException('Ação inválida.');
  });
}

$ready = impl_ready($pdo);
$etapas = $ready ? impl_etapas($pdo, $cid) : [];
$status = $ready ? impl_status_list($pdo, $cid) : [];
$tarefas = $ready ? impl_tarefas($pdo, $cid) : [];
$trilhas = $ready ? impl_trilhas($pdo, $cid) : [];
$novo = ['etapas' => ['mEtapa', '+ Nova etapa'], 'status' => ['mStatus', '+ Novo status'], 'tarefas' => ['mTarefa', '+ Nova tarefa'], 'trilhas' => ['mTrilha', '+ Nova trilha']][$aba] ?? null;
impl_start($pdo, 'cadastros', 'Cadastros da implantação', 'Etapas, status do cliente, tarefas e trilhas que cada tipo de cliente segue.',
  $novo ? '<button class="im-btn pri" type="button" data-new="' . $novo[0] . '">' . $novo[1] . '</button>' : '');
$js = static fn($v) => impl_h(json_encode($v, JSON_UNESCAPED_UNICODE));
?>
<nav class="im-tabs" style="margin-top:-4px"><?php foreach ($abas as $k => $l): ?><a class="<?= $k === $aba ? 'on' : '' ?>" href="?aba=<?= $k ?>"><?= impl_h($l) ?></a><?php endforeach; ?></nav>

<?php if (!$etapas && $aba !== 'config'): ?>
  <div class="im-msg warn" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
    <span>Ainda não há etapas. Você pode cadastrar do zero ou carregar o modelo sugerido (Primeiros Passos, Cadastros e Operações, Controle e Conferência, Finalização, com as tarefas e as trilhas "Novo cliente" e "Migração de Sistema").</span>
    <form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="modelo"><button class="im-btn pri" type="submit">Carregar modelo sugerido</button></form>
  </div>
<?php endif; ?>

<div class="im-card">
<?php if ($aba === 'etapas'): ?>
  <?php if (!$etapas): ?><div class="im-empty">Nenhuma etapa.</div><?php else: ?>
  <table class="im-table cards"><thead><tr><th>Ordem</th><th>Etapa</th><th>Tarefas</th><th>Situação</th><th></th></tr></thead><tbody>
  <?php foreach ($etapas as $e): ?><tr class="<?= $e['ativo'] ? '' : 'off' ?>"><td data-l="Ordem"><?= (int)$e['ordem'] ?></td><td data-l="Etapa"><span class="im-swatch" style="background:<?= impl_h(impl_cor($e['cor'])) ?>"></span> <strong><?= impl_h($e['nome']) ?></strong></td><td data-l="Tarefas"><?= (int)$e['tarefas'] ?></td><td data-l="Situação"><?= $e['ativo'] ? '<span class="im-badge g">Ativa</span>' : '<span class="im-badge n">Inativa</span>' ?></td>
    <td class="act"><button class="im-btn sm" type="button" data-edit="mEtapa" data-row="<?= $js($e) ?>">Editar</button></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>

<?php elseif ($aba === 'status'): ?>
  <p class="im-sub">O <b>tipo</b> define o comportamento: <b>Agendado</b> passa sozinho para o primeiro status "Em andamento" quando a 1ª tarefa é executada; <b>Concluído</b> grava a data de conclusão e libera o relatório de conclusão na Documentação.</p>
  <table class="im-table cards"><thead><tr><th>Ordem</th><th>Status</th><th>Tipo</th><th>Situação</th><th></th></tr></thead><tbody>
  <?php foreach ($status as $s): ?><tr class="<?= $s['ativo'] ? '' : 'off' ?>"><td data-l="Ordem"><?= (int)$s['ordem'] ?></td><td data-l="Status"><?= impl_pill($s['nome'], $s['cor']) ?></td><td data-l="Tipo"><?= impl_h(impl_status_tipos()[$s['tipo']] ?? $s['tipo']) ?></td><td data-l="Situação"><?= $s['ativo'] ? '<span class="im-badge g">Ativo</span>' : '<span class="im-badge n">Inativo</span>' ?></td>
    <td class="act"><button class="im-btn sm" type="button" data-edit="mStatus" data-row="<?= $js($s) ?>">Editar</button></td></tr><?php endforeach; ?>
  </tbody></table>

<?php elseif ($aba === 'tarefas'): ?>
  <?php if (!$tarefas): ?><div class="im-empty">Nenhuma tarefa. Cadastre as etapas primeiro.</div><?php else: ?>
  <table class="im-table cards"><thead><tr><th>Etapa</th><th>Ordem</th><th>Tarefa</th><th>Situação</th><th></th></tr></thead><tbody>
  <?php foreach ($tarefas as $t): ?><tr class="<?= $t['ativo'] ? '' : 'off' ?>"><td data-l="Etapa"><span class="im-swatch" style="background:<?= impl_h(impl_cor($t['etapa_cor'])) ?>"></span> <?= impl_h($t['etapa_nome']) ?></td><td data-l="Ordem"><?= (int)$t['ordem'] ?></td><td data-l="Tarefa"><strong><?= impl_h($t['nome']) ?></strong><?php if ($t['descricao']): ?><div class="im-hint"><?= impl_h($t['descricao']) ?></div><?php endif; ?></td><td data-l="Situação"><?= $t['ativo'] ? '<span class="im-badge g">Ativa</span>' : '<span class="im-badge n">Inativa</span>' ?></td>
    <td class="act"><button class="im-btn sm" type="button" data-edit="mTarefa" data-row="<?= $js($t) ?>">Editar</button></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>

<?php elseif ($aba === 'trilhas'): ?>
  <?php if (!$trilhas): ?><div class="im-empty">Nenhuma trilha.</div><?php else: ?>
  <table class="im-table cards"><thead><tr><th>Trilha</th><th>Tarefas</th><th>Situação</th><th></th></tr></thead><tbody>
  <?php foreach ($trilhas as $t): $row = $t + ['tarefas_ids' => impl_trilha_tarefa_ids($pdo, $cid, (int)$t['id'])]; ?><tr class="<?= $t['ativo'] ? '' : 'off' ?>"><td data-l="Trilha"><strong><?= impl_h($t['nome']) ?></strong><?php if ($t['descricao']): ?><div class="im-hint"><?= impl_h($t['descricao']) ?></div><?php endif; ?></td><td data-l="Tarefas"><?= (int)$t['tarefas'] ?></td><td data-l="Situação"><?= $t['ativo'] ? '<span class="im-badge g">Ativa</span>' : '<span class="im-badge n">Inativa</span>' ?></td>
    <td class="act"><button class="im-btn sm" type="button" data-edit="mTrilha" data-row="<?= $js($row) ?>">Editar</button></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>

<?php else: $cfg = $ready ? impl_config($pdo, $cid) : impl_config_defaults(); ?>
  <form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="config">
    <label class="f">Aviso padrão do relatório de conclusão (aparece junto das fotos dos equipamentos instalados)<textarea name="aviso_equipamentos" rows="5"><?= impl_h($cfg['aviso_equipamentos']) ?></textarea></label>
    <p class="im-hint">Cada implantação pode ter um texto próprio, na Documentação &gt; Equipamentos.</p>
    <div style="display:flex;justify-content:flex-end"><button class="im-btn pri" type="submit">Salvar</button></div>
  </form>
<?php endif; ?>
</div>

<dialog class="im-modal" id="mEtapa"><form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="etapa"><input type="hidden" name="id">
  <div class="im-mh"><h3>Etapa</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
  <div class="im-mb"><div class="im-grid">
    <label class="f s3">Nome *<input type="text" name="nome" maxlength="80" required placeholder="Ex.: Primeiros Passos"></label>
    <label class="f">Cor<input type="color" name="cor" value="#2563eb"></label>
    <label class="f">Ordem<input type="number" name="ordem" value="0"></label>
    <label class="ck s3"><input type="checkbox" name="ativo" value="1" checked> Ativa</label>
  </div></div>
  <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><button class="im-btn pri" type="submit">Salvar</button></div>
</form></dialog>

<dialog class="im-modal" id="mStatus"><form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id">
  <div class="im-mh"><h3>Status do cliente</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
  <div class="im-mb"><div class="im-grid">
    <label class="f s3">Nome *<input type="text" name="nome" maxlength="60" required placeholder="Ex.: Em andamento"></label>
    <label class="f">Tipo<select name="tipo"><?php foreach (impl_status_tipos() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="f">Cor<input type="color" name="cor" value="#16a34a"></label>
    <label class="f">Ordem<input type="number" name="ordem" value="0"></label>
    <label class="ck"><input type="checkbox" name="ativo" value="1" checked> Ativo</label>
  </div></div>
  <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><button class="im-btn pri" type="submit">Salvar</button></div>
</form></dialog>

<dialog class="im-modal" id="mTarefa"><form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="tarefa"><input type="hidden" name="id">
  <div class="im-mh"><h3>Tarefa</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
  <div class="im-mb"><div class="im-grid">
    <label class="f s3">Nome *<input type="text" name="nome" maxlength="160" required placeholder="Ex.: Emissão de NFC-e"></label>
    <label class="f">Etapa *<select name="etapa_id" required><option value="">Selecione…</option><?php foreach ($etapas as $e): ?><option value="<?= (int)$e['id'] ?>"><?= impl_h($e['nome']) ?><?= $e['ativo'] ? '' : ' (inativa)' ?></option><?php endforeach; ?></select></label>
    <label class="f">Ordem na etapa<input type="number" name="ordem" value="0"></label>
    <label class="f s3">Descrição / roteiro (opcional)<textarea name="descricao" rows="3"></textarea></label>
    <label class="ck s3"><input type="checkbox" name="ativo" value="1" checked> Ativa</label>
  </div></div>
  <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><button class="im-btn pri" type="submit">Salvar</button></div>
</form></dialog>

<dialog class="im-modal wide" id="mTrilha"><form method="post"><?= impl_csrf_field() ?><input type="hidden" name="action" value="trilha"><input type="hidden" name="id">
  <div class="im-mh"><h3>Trilha</h3><button class="im-x" type="button" onclick="imClose(this)">×</button></div>
  <div class="im-mb"><div class="im-grid">
    <label class="f s3">Nome *<input type="text" name="nome" maxlength="120" required placeholder="Ex.: Novo cliente | Nova base de dados"></label>
    <label class="f s3">Descrição (opcional)<input type="text" name="descricao"></label>
    <label class="ck s3"><input type="checkbox" name="ativo" value="1" checked> Ativa</label>
    <div class="sec">Tarefas da trilha <span class="im-hint">(seguem a ordem das etapas e das tarefas do cadastro)</span> <button class="im-btn sm" type="button" id="imTodas">Marcar todas</button></div>
    <div class="s3 im-cols">
      <?php $porEtapa = []; foreach ($tarefas as $t) if ($t['ativo']) $porEtapa[(string)$t['etapa_nome']][] = $t;
      foreach ($porEtapa as $en => $ts): ?>
        <div><div style="font-weight:900;color:#0b1f5b;margin-bottom:4px"><span class="im-swatch" style="width:10px;height:10px;border-radius:50%;background:<?= impl_h(impl_cor($ts[0]['etapa_cor'])) ?>"></span> <?= impl_h($en) ?></div>
          <ul class="im-checks"><?php foreach ($ts as $t): ?><li><label class="ck" style="font-weight:600"><input type="checkbox" name="tarefas[]" value="<?= (int)$t['id'] ?>"> <?= impl_h($t['nome']) ?></label></li><?php endforeach; ?></ul></div>
      <?php endforeach; if (!$porEtapa): ?><div class="im-empty">Cadastre as tarefas antes de montar a trilha.</div><?php endif; ?>
    </div>
  </div></div>
  <div class="im-mf"><button class="im-btn" type="button" onclick="imClose(this)">Cancelar</button><button class="im-btn pri" type="submit">Salvar</button></div>
</form></dialog>

<script>
(function(){
  function fill(dlg,row){
    const f=dlg.querySelector('form');f.reset();
    f.querySelectorAll('input[name="tarefas[]"]').forEach(c=>c.checked=false);
    if(!row){f.elements.id.value='';const a=f.elements.ativo;if(a)a.checked=true;return;}
    Object.entries(row).forEach(([k,v])=>{const el=f.elements[k];if(!el||el.length&&!el.tagName)return;if(el.type==='checkbox')el.checked=String(v)==='1';else if(v!==null)el.value=v;});
    (row.tarefas_ids||[]).forEach(id=>{const c=f.querySelector('input[name="tarefas[]"][value="'+id+'"]');if(c)c.checked=true;});
  }
  document.addEventListener('click',e=>{
    const n=e.target.closest('[data-new]');if(n){const d=document.getElementById(n.dataset.new);fill(d,null);imOpen(n.dataset.new);return;}
    const b=e.target.closest('[data-edit]');if(b){const d=document.getElementById(b.dataset.edit);fill(d,JSON.parse(b.dataset.row));imOpen(b.dataset.edit);}
  });
  document.getElementById('imTodas')?.addEventListener('click',()=>document.querySelectorAll('#mTrilha input[name="tarefas[]"]').forEach(c=>c.checked=true));
})();
</script>
<?php impl_end();
