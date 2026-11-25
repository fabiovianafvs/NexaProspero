<?php
declare(strict_types=1);

// Trilha de Implantação: progresso (%) de cada cliente e os próximos passos.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_implantacao.php';
$cid = impl_guard($pdo, 'crm_implantacao');
$ready = impl_ready($pdo);
$statusList = $ready ? impl_status_list($pdo, $cid, true) : [];
$users = impl_users($pdo, $cid);

// Sem filtro enviado: mostra Agendado + Em andamento (o que ainda precisa de atenção).
$filtrou = isset($_GET['f']);
$sel = $filtrou ? array_map('intval', (array)($_GET['st'] ?? [])) : array_map(static fn($s) => (int)$s['id'], array_filter($statusList, static fn($s) => $s['tipo'] !== 'CONCLUIDO'));
$implantador = (int)($_GET['implantador'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$rows = $ready ? impl_implantacoes($pdo, $cid, ['status_ids' => $sel, 'implantador' => $implantador, 'q' => $q]) : [];

$k = ['AGENDADO' => 0, 'ANDAMENTO' => 0, 'CONCLUIDO' => 0]; $soma = 0; $n = 0;
foreach ($rows as $r) {
  $t = (string)($r['status_tipo'] ?? 'ANDAMENTO'); if (isset($k[$t])) $k[$t]++;
  if ($t !== 'CONCLUIDO') { $soma += (int)$r['progresso']; $n++; }
}

impl_start($pdo, 'acompanhamento', 'Acompanhamento', 'Progresso de cada cliente na trilha de implantação e os próximos passos.');
?>
<form class="im-card im-search" method="get" style="margin-bottom:12px"><input type="hidden" name="f" value="1">
  <label class="f">Implantador<select name="implantador"><option value="0">Todos os implantadores</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $implantador ? 'selected' : '' ?>><?= impl_h($u['name']) ?></option><?php endforeach; ?></select></label>
  <div class="f"><span style="font-size:12px;font-weight:800;color:#334155">Status</span><div class="im-stchecks">
    <?php foreach ($statusList as $s): ?><label class="ck"><input type="checkbox" name="st[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $sel, true) ? 'checked' : '' ?>> <?= impl_h($s['nome']) ?></label><?php endforeach; ?>
  </div></div>
  <label class="f grow">Cliente<input type="text" name="q" value="<?= impl_h($q) ?>" placeholder="Nome do cliente"></label>
  <button class="im-btn pri" type="submit">Pesquisar</button><a class="im-btn" href="implantacao_acompanhamento.php">Limpar</a>
</form>

<div class="im-kpis">
  <div class="im-kpi"><small>Em andamento</small><strong><?= $k['ANDAMENTO'] ?></strong></div>
  <div class="im-kpi"><small>Agendadas</small><strong><?= $k['AGENDADO'] ?></strong></div>
  <div class="im-kpi"><small>Concluídas (no filtro)</small><strong><?= $k['CONCLUIDO'] ?></strong></div>
  <div class="im-kpi"><small>Progresso médio (abertas)</small><strong><?= $n ? round($soma / $n) : 0 ?>%</strong></div>
</div>

<div class="im-card">
  <?php if (!$rows): ?><div class="im-empty">Nenhum cliente no filtro.</div><?php else: ?>
  <table class="im-table cards"><thead><tr><th>Cliente</th><th>Status</th><th>Implantador</th><th>Início</th><th style="min-width:180px">Progresso</th><th>Próximos passos</th><th>Último atendimento</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $dias = (int)floor((time() - strtotime((string)$r['data_inicio'])) / 86400); ?>
      <tr>
        <td data-l="Cliente"><a href="implantacao.php?impl=<?= (int)$r['id'] ?>"><strong><?= impl_h($r['cliente']) ?></strong></a><div class="im-hint"><?= impl_h($r['trilha_nome'] ?: '') ?></div></td>
        <td data-l="Status"><?= impl_pill($r['status_nome'], $r['status_cor']) ?></td>
        <td data-l="Implantador"><?= impl_h($r['implantador'] ?: '-') ?></td>
        <td data-l="Início" class="nw"><?= impl_data_br($r['data_inicio']) ?><?php if (($r['status_tipo'] ?? '') !== 'CONCLUIDO' && $dias >= 0): ?><div class="im-hint">há <?= $dias ?> dia(s)</div><?php endif; ?></td>
        <td data-l="Progresso"><div style="display:flex;gap:8px;align-items:center"><div class="im-bar" style="flex:1"><b style="width:<?= (int)$r['progresso'] ?>%"></b></div><b style="color:#0b1f5b"><?= (int)$r['progresso'] ?>%</b></div><div class="im-hint"><?= (int)$r['feitas'] ?> de <?= (int)$r['total'] ?> tarefas</div></td>
        <td data-l="Próximos passos">
          <?php if (!$r['proximos']): ?><span class="im-badge g">Todas as tarefas feitas</span>
          <?php else: ?><ul class="im-steps"><?php foreach ($r['proximos'] as $p): ?><li><span class="im-swatch" style="width:9px;height:9px;border-radius:50%;background:<?= impl_h(impl_cor($p['etapa_cor'])) ?>"></span> <?= impl_h($p['nome']) ?> <span class="im-hint">(<?= impl_h($p['etapa_nome']) ?>)</span></li><?php endforeach; ?></ul><?php endif; ?>
        </td>
        <td data-l="Último atendimento" class="nw"><?= impl_data_br($r['ultimo_atendimento']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php impl_end();
