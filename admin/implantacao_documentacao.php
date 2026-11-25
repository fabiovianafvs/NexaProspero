<?php
declare(strict_types=1);

// Trilha de Implantação: Documentação — relatório de conclusão (status do tipo Concluído),
// fotos dos equipamentos instalados e o aviso sobre serviços fora do suporte.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_implantacao.php';
$cid = impl_guard($pdo, 'crm_implantacao_documentacao');
$uid = (int)(auth_user_id() ?? 0);
$fotosId = (int)($_GET['fotos'] ?? 0);

if (impl_ready($pdo)) {
  impl_post_handle('implantacao_documentacao.php?fotos=' . $fotosId, function () use ($pdo, $cid, $uid, $fotosId) {
    if ($fotosId <= 0 || !impl_implantacao($pdo, $cid, $fotosId)) throw new RuntimeException('Implantação não encontrada.');
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'foto_add') {
      $files = $_FILES['fotos'] ?? null;
      if (!$files || !is_array($files['name'] ?? null)) throw new RuntimeException('Selecione as fotos.');
      $n = 0; $erros = [];
      foreach ($files['name'] as $i => $nm) {
        if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $one = ['name' => $nm, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? 0, 'size' => $files['size'][$i] ?? 0];
        try { impl_foto_add($pdo, $cid, $fotosId, $one, (string)($_POST['legenda'] ?? ''), $uid); $n++; }
        catch (RuntimeException $e) { $erros[] = (string)$nm . ': ' . $e->getMessage(); }
      }
      if (!$n && !$erros) throw new RuntimeException('Selecione as fotos.');
      if ($erros) impl_flash('warn', ($n ? $n . ' foto(s) enviada(s). ' : '') . "Não enviadas:\n" . implode("\n", $erros));
      return $erros ? '' : $n . ' foto(s) enviada(s).';
    }
    if ($a === 'foto_del') { impl_foto_del($pdo, $cid, $fotosId, (int)($_POST['foto_id'] ?? 0)); return 'Foto retirada.'; }
    if ($a === 'aviso') { impl_aviso_salvar($pdo, $cid, $fotosId, (string)($_POST['aviso'] ?? '')); return 'Aviso salvo para esta implantação.'; }
    throw new RuntimeException('Ação inválida.');
  });
}

$ready = impl_ready($pdo);
$statusList = $ready ? impl_status_list($pdo, $cid, true) : [];
$users = impl_users($pdo, $cid);
$clientes = [];
if ($ready) foreach (impl_implantacoes($pdo, $cid) as $i) $clientes[(int)$i['person_id']] = (string)$i['cliente'];
asort($clientes, SORT_NATURAL | SORT_FLAG_CASE);
$sel = array_map('intval', (array)($_GET['st'] ?? []));
$implantador = (int)($_GET['implantador'] ?? 0);
$person = (int)($_GET['cliente'] ?? 0);
$rows = $ready ? impl_implantacoes($pdo, $cid, ['status_ids' => $sel, 'implantador' => $implantador, 'person_id' => $person]) : [];
$nFotos = [];
if ($rows) {
  $ids = array_map(static fn($r) => (int)$r['id'], $rows);
  $st = $pdo->prepare('SELECT implantacao_id, COUNT(*) FROM impl_fotos WHERE company_id=? AND implantacao_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY implantacao_id');
  $st->execute(array_merge([$cid], $ids));
  foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as [$iid, $c]) $nFotos[(int)$iid] = (int)$c;
}
$fimpl = ($ready && $fotosId > 0) ? impl_implantacao($pdo, $cid, $fotosId) : null;

impl_start($pdo, 'documentacao', 'Documentação', 'Relatório de treinamentos realizados. Com o status Concluído, o relatório de conclusão da implantação fica disponível.');
?>
<?php if ($fimpl): $fotos = impl_fotos($pdo, $cid, $fotosId); $aviso = trim((string)($fimpl['aviso_equipamentos'] ?? '')) ?: impl_config($pdo, $cid)['aviso_equipamentos']; ?>
  <div class="im-card" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
      <div><h2>Equipamentos instalados — <?= impl_h($fimpl['cliente']) ?></h2><p class="im-sub">Fotos de impressoras, computadores, hardwares e periféricos instalados. Entram no relatório de conclusão.</p></div>
      <a class="im-btn" href="implantacao_documentacao.php">Fechar</a>
    </div>
    <form method="post" enctype="multipart/form-data" class="im-search"><?= impl_csrf_field() ?><input type="hidden" name="action" value="foto_add">
      <label class="f">Fotos<input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
      <label class="f grow">Legenda (opcional)<input type="text" name="legenda" maxlength="200" placeholder="Ex.: Impressora térmica do caixa 1"></label>
      <button class="im-btn pri" type="submit">Enviar fotos</button>
    </form>
    <?php if (!$fotos): ?><div class="im-empty">Nenhuma foto ainda.</div><?php else: ?>
      <div class="im-photos">
        <?php foreach ($fotos as $f): ?>
          <div class="im-photo"><a href="implantacao_arquivo.php?f=<?= rawurlencode((string)$f['arquivo']) ?>" target="_blank"><img src="implantacao_arquivo.php?f=<?= rawurlencode((string)$f['arquivo']) ?>" alt="" loading="lazy"></a>
            <div><span><?= impl_h($f['legenda'] ?: 'Sem legenda') ?></span>
              <form method="post" data-confirm="Retirar esta foto?"><?= impl_csrf_field() ?><input type="hidden" name="action" value="foto_del"><input type="hidden" name="foto_id" value="<?= (int)$f['id'] ?>"><button class="im-btn sm red" type="submit">×</button></form>
            </div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" style="margin-top:14px"><?= impl_csrf_field() ?><input type="hidden" name="action" value="aviso">
      <label class="f">Aviso no relatório de conclusão<textarea name="aviso" rows="4"><?= impl_h($aviso) ?></textarea></label>
      <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="im-btn" type="submit">Salvar aviso</button></div>
      <p class="im-hint">O texto padrão de todas as implantações fica em Cadastros &gt; Configurações.</p>
    </form>
  </div>
<?php endif; ?>

<form class="im-card im-search" method="get" style="margin-bottom:12px">
  <label class="f">Implantador<select name="implantador"><option value="0">Todos os implantadores</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $implantador ? 'selected' : '' ?>><?= impl_h($u['name']) ?></option><?php endforeach; ?></select></label>
  <div class="f"><span style="font-size:12px;font-weight:800;color:#334155">Status</span><div class="im-stchecks">
    <?php foreach ($statusList as $s): ?><label class="ck"><input type="checkbox" name="st[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $sel, true) ? 'checked' : '' ?>> <?= impl_h($s['nome']) ?></label><?php endforeach; ?>
  </div></div>
  <label class="f">Empresa<select name="cliente"><option value="0">Todas as empresas</option><?php foreach ($clientes as $pid => $nm): ?><option value="<?= $pid ?>" <?= $pid === $person ? 'selected' : '' ?>><?= impl_h($nm) ?></option><?php endforeach; ?></select></label>
  <button class="im-btn pri" type="submit">Pesquisar</button><a class="im-btn" href="implantacao_documentacao.php">Limpar</a>
</form>

<div class="im-card">
  <?php if (!$rows): ?><div class="im-empty">Nenhuma implantação no filtro.</div><?php else: ?>
  <table class="im-table cards"><thead><tr><th>Empresa</th><th>Status</th><th>Implantação</th><th>Treinandos</th><th>Conclusão</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $concl = ($r['status_tipo'] ?? '') === 'CONCLUIDO'; ?>
      <tr>
        <td data-l="Empresa"><strong><?= impl_h($r['cliente']) ?></strong><div class="im-hint"><?= impl_h($r['trilha_nome'] ?: '') ?> · <?= (int)$r['progresso'] ?>%</div></td>
        <td data-l="Status"><?= impl_pill($r['status_nome'], $r['status_cor']) ?></td>
        <td data-l="Implantação" class="nw"><?= impl_data_br($r['data_inicio']) ?></td>
        <td data-l="Treinandos"><?= impl_h(implode(', ', $r['treinandos']) ?: '-') ?></td>
        <td data-l="Conclusão" class="nw"><?= impl_data_br($r['data_conclusao']) ?></td>
        <td class="act">
          <?php if ($concl): ?><a class="im-btn sm pri" target="_blank" href="implantacao_conclusao.php?id=<?= (int)$r['id'] ?>">🖨 Relatório de conclusão</a>
          <?php else: ?><button class="im-btn sm" type="button" disabled title="Disponível quando o status for Concluído">🖨 Relatório de conclusão</button><?php endif; ?>
          <a class="im-btn sm" href="implantacao_documentacao.php?fotos=<?= (int)$r['id'] ?>">📷 Equipamentos (<?= (int)($nFotos[(int)$r['id']] ?? 0) ?>)</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php impl_end();
