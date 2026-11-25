<?php
declare(strict_types=1);

// Trilha de Implantação: relatórios de atendimento técnico local (lista e filtros).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_implantacao.php';
$cid = impl_guard($pdo, 'crm_implantacao_relatorios');
$ready = impl_ready($pdo);
$statusList = $ready ? impl_status_list($pdo, $cid, true) : [];
$users = impl_users($pdo, $cid);
$clientes = [];
if ($ready) foreach (impl_implantacoes($pdo, $cid) as $i) $clientes[(int)$i['person_id']] = (string)$i['cliente'];
asort($clientes, SORT_NATURAL | SORT_FLAG_CASE);

$sel = array_map('intval', (array)($_GET['st'] ?? []));
$implantador = (int)($_GET['implantador'] ?? 0);
$person = (int)($_GET['cliente'] ?? 0);
$de = (string)($_GET['de'] ?? ''); $ate = (string)($_GET['ate'] ?? '');
$rows = $ready ? impl_relatorios($pdo, $cid, ['status_ids' => $sel, 'implantador' => $implantador, 'person_id' => $person, 'de' => $de, 'ate' => $ate]) : [];

impl_start($pdo, 'relatorios', 'Relatórios', 'Relatórios de atendimento técnico local: horário, processos implantados, quem participou e a assinatura do cliente.',
  '<a class="im-btn pri" href="implantacao_relatorio.php">+ Novo relatório</a>');
?>
<form class="im-card im-search" method="get" style="margin-bottom:12px">
  <label class="f">Implantador<select name="implantador"><option value="0">Todos os implantadores</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $implantador ? 'selected' : '' ?>><?= impl_h($u['name']) ?></option><?php endforeach; ?></select></label>
  <div class="f"><span style="font-size:12px;font-weight:800;color:#334155">Status do cliente</span><div class="im-stchecks">
    <?php foreach ($statusList as $s): ?><label class="ck"><input type="checkbox" name="st[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $sel, true) ? 'checked' : '' ?>> <?= impl_h($s['nome']) ?></label><?php endforeach; ?>
  </div></div>
  <label class="f">Empresa<select name="cliente"><option value="0">Todas as empresas</option><?php foreach ($clientes as $pid => $nm): ?><option value="<?= $pid ?>" <?= $pid === $person ? 'selected' : '' ?>><?= impl_h($nm) ?></option><?php endforeach; ?></select></label>
  <label class="f">De<input type="date" name="de" value="<?= impl_h($de) ?>"></label>
  <label class="f">Até<input type="date" name="ate" value="<?= impl_h($ate) ?>"></label>
  <button class="im-btn pri" type="submit">Pesquisar</button><a class="im-btn" href="implantacao_relatorios.php">Limpar</a>
</form>

<div class="im-card">
  <?php if (!$rows): ?><div class="im-empty">Nenhum relatório no filtro. Clique em "+ Novo relatório" para registrar um atendimento.</div><?php else: ?>
  <table class="im-table cards"><thead><tr><th>Nº</th><th>Data</th><th>Empresa</th><th>Status</th><th>Horário</th><th>Implantador</th><th>Treinandos</th><th>Processos</th><th>Assinatura</th><th>Situação</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $min = impl_minutos((string)$r['hora_inicio'], (string)$r['hora_fim']); ?>
      <tr>
        <td data-l="Nº"><strong><?= (int)$r['numero'] ?></strong></td>
        <td data-l="Data" class="nw"><?= impl_data_br($r['data']) ?></td>
        <td data-l="Empresa"><?= impl_h($r['cliente']) ?></td>
        <td data-l="Status"><?= impl_pill($r['status_nome'], $r['status_cor']) ?></td>
        <td data-l="Horário"><?= impl_h(substr((string)$r['hora_inicio'], 0, 5) . '–' . substr((string)$r['hora_fim'], 0, 5)) ?> <span class="im-hint">(<?= impl_horas_txt($min) ?>)</span></td>
        <td data-l="Implantador"><?= impl_h($r['implantador'] ?: '-') ?></td>
        <td data-l="Treinandos"><?= impl_h(implode(', ', impl_participantes((string)$r['participantes'])) ?: '-') ?></td>
        <td data-l="Processos"><?= (int)$r['processos'] ?></td>
        <td data-l="Assinatura"><?= $r['assinatura_arquivo'] ? '<span class="im-badge g">Assinado</span>' : ($r['link_hash'] ? '<span class="im-badge y">Link enviado</span>' : '<span class="im-badge n">Pendente</span>') ?></td>
        <td data-l="Situação"><?= $r['status'] === 'CONFIRMADO' ? '<span class="im-badge g">Confirmado</span>' : '<span class="im-badge y">Rascunho</span>' ?></td>
        <td class="act"><a class="im-btn sm" href="implantacao_relatorio.php?id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'CONFIRMADO' ? 'Abrir' : 'Editar' ?></a> <a class="im-btn sm" target="_blank" href="implantacao_relatorio_imprimir.php?id=<?= (int)$r['id'] ?>">Imprimir</a></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php impl_end();
