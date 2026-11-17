<?php
declare(strict_types=1);

// Ponto Eletrônico > Tratamento do Ponto: apuração do mês por colaborador (trabalhado, extra,
// débito, noturno) e o tratamento das pendências — incluir marcação esquecida, desconsiderar
// marcação indevida, mudar o tipo, lançar ocorrência/abono. O registro original nunca muda.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_apuracao.php';

$cid = ponto_guard($pdo, 'ponto_tratamento');
$canOc = ponto_can($pdo, 'ponto_ocorrencias');
[$de, $ate, $compLabel, $comp] = ponto_competencia((string)($_GET['c'] ?? ''));
$colId = (int)($_GET['col'] ?? 0);
$so = !empty($_GET['so']);
$back = 'ponto_tratamento.php?' . http_build_query(array_filter(['c' => $comp, 'col' => $colId ?: null, 'so' => $so ? 1 : null]));

ponto_post_handle($back, function () use ($pdo, $cid, $canOc, $back, $de, $ate) {
  $a = (string)($_POST['action'] ?? '');
  $col = (int)($_POST['colaborador_id'] ?? 0);
  $dia = ponto_date_safe($_POST['data'] ?? '', '');
  $ret = $back . ($dia ? '&dia=' . $dia : '');
  $base = ['colaborador_id' => $col, 'data' => $dia, 'motivo' => (string)($_POST['motivo'] ?? ''), 'tipo_ocorrencia_id' => (int)($_POST['tipo_ocorrencia_id'] ?? 0)];
  if ($a === 'incluir') {
    ponto_tratamento_save($pdo, $cid, $base + ['tipo' => 'INCLUSAO', 'hora' => (string)($_POST['hora'] ?? ''), 'proximo_dia' => !empty($_POST['proximo_dia']), 'intencao' => (string)($_POST['intencao'] ?? '')]);
    return ['Marcação incluída. A original não foi alterada.', $ret];
  }
  if ($a === 'desconsiderar') {
    ponto_tratamento_save($pdo, $cid, $base + ['tipo' => 'DESCONSIDERACAO', 'marcacao_id' => (int)($_POST['marcacao_id'] ?? 0)]);
    return ['Marcação desconsiderada na apuração (continua guardada no registro original).', $ret];
  }
  if ($a === 'reclassificar') {
    ponto_tratamento_save($pdo, $cid, $base + ['tipo' => 'AJUSTE_CLASSIFICACAO', 'marcacao_id' => (int)($_POST['marcacao_id'] ?? 0), 'intencao' => (string)($_POST['intencao'] ?? '')]);
    return ['Tipo da marcação ajustado.', $ret];
  }
  if ($a === 'cancelar_tratamento') {
    ponto_tratamento_decidir($pdo, $cid, (int)($_POST['tratamento_id'] ?? 0), 'cancelar', (string)($_POST['motivo'] ?? ''));
    return ['Tratamento desfeito (fica no histórico).', $ret];
  }
  if ($a === 'ocorrencia') {
    if (!$canOc) throw new RuntimeException('Sem permissão para lançar ocorrências.');
    $f = $_FILES['documento'] ?? null;
    $file = $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name']) ? ['tmp' => (string)$f['tmp_name'], 'name' => (string)$f['name']] : null;
    ponto_ocorrencia_save($pdo, $cid, ['colaborador_id' => $col, 'tipo_ocorrencia_id' => (int)($_POST['tipo_ocorrencia_id'] ?? 0), 'data_inicio' => $dia,
      'data_fim' => (string)($_POST['data_fim'] ?? '') ?: $dia, 'hora_inicio' => (string)($_POST['hora_inicio'] ?? ''), 'hora_fim' => (string)($_POST['hora_fim'] ?? ''), 'descricao' => (string)($_POST['descricao'] ?? '')], false, $file);
    return ['Ocorrência lançada.', $ret];
  }
  if ($a === 'apurar_todos') {
    $n = 0;
    foreach (ponto_colaborador_list($pdo, $cid, ['status' => 'ATIVOS']) as $c) {
      if (!(int)$c['controla_ponto']) continue;
      ponto_apurar_periodo($pdo, $cid, (int)$c['id'], $de, min($ate, date('Y-m-d')));
      $n++;
    }
    return $n . ' colaborador(es) apurado(s) no mês.';
  }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_tratamento', 'Tratamento do Ponto', 'Horas do mês de cada colaborador e correção das pendências. Nada apaga ou altera a marcação original: inclusões, desconsiderações e abonos ficam registrados à parte, com motivo e responsável.');
ponto_require_v4($pdo);

$cols = [];
foreach (ponto_colaborador_list($pdo, $cid, ['status' => '']) as $c) if ((int)$c['controla_ponto']) $cols[(int)$c['id']] = $c;
$tiposOc = ponto_tipos_ocorrencia($pdo, $cid);
$pend = ponto_solicitacoes_pendentes($pdo, $cid);
$nPend = count($pend['inclusoes']) + count($pend['ocorrencias']);
$users = ponto_company_users($pdo, $cid);
$dias = []; $tot = null; $hist = [];
if ($colId && isset($cols[$colId])) {
  $dias = ponto_apurar_periodo($pdo, $cid, $colId, $de, $ate);
  $tot = ponto_apuracao_totais($dias);
  $st = $pdo->prepare('SELECT * FROM ponto_tratamentos WHERE company_id = ? AND colaborador_id = ? AND `data` BETWEEN ? AND ? ORDER BY id');
  $st->execute([$cid, $colId, $de, $ate]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tr) $hist[(string)$tr['data']][] = $tr;
} else {
  $colId = 0;
}
$wd = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$intLabel = static fn($i) => PONTO_INTENCOES[(string)$i] ?? '';
?>
<style>
.ptt-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:8px;margin-bottom:12px}
.ptt-k div{background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:9px 11px}.ptt-k span{display:block;font-size:11.5px;color:#64748b;font-weight:800}.ptt-k b{font-size:20px;color:#0b1f5b;font-variant-numeric:tabular-nums}
.ptt-m{display:inline-flex;align-items:center;gap:3px;font-variant-numeric:tabular-nums;font-weight:800;font-size:12.5px;border:1px solid #dbe4f0;border-radius:8px;padding:2px 6px;background:#f8fbff;margin:1px}
.ptt-m.x{text-decoration:line-through;color:#94a3b8;background:#f8fafc}.ptt-m.i{border-color:#86efac;background:#f0fdf4;color:#15803d}.ptt-m.p{border-color:#fde68a;background:#fffbeb;color:#a16207;border-style:dashed}
.ptt-m sup{font-size:9px;font-weight:900}
.ptt-num{font-variant-numeric:tabular-nums;white-space:nowrap!important;text-align:right}
.pt-table tr.fds td{background:#fafbfd}.pt-table tr.bad td{background:#fff7f7}
.ptt-al{display:block;color:#b91c1c;font-size:11.5px;font-weight:700}
.ptt-oc{display:block;color:#1d4ed8;font-size:11.5px;font-weight:700}
.ptt-dm{display:grid;gap:6px}.ptt-dm .row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;border:1px solid #eef2f7;border-radius:12px;padding:8px 10px}
.ptt-dm .row b{font-variant-numeric:tabular-nums;font-size:16px;min-width:52px}
.ptt-dm .row .sp{flex:1}
.ptt-act{display:none;width:100%;gap:6px;flex-wrap:wrap;align-items:flex-end;margin-top:6px}.ptt-act.on{display:flex}
.ptt-act input[type=text]{flex:1 1 220px}
.ptt-h{font-size:12.5px;color:#475569;border-left:3px solid #cbd5e1;padding:4px 8px;margin:4px 0}
.ptt-h.CANCELADO,.ptt-h.REJEITADO{opacity:.6}
.ptt-sec{margin:14px 0 6px;font-size:14px;color:#0b1f5b}
</style>

<form method="get" class="pt-card pt-search">
  <label class="f grow">Colaborador<select name="col" onchange="this.form.submit()"><option value="">— Todos (ver pendências do mês) —</option>
    <?php foreach ($cols as $id => $c): ?><option value="<?= $id ?>" <?= $id === $colId ? 'selected' : '' ?>><?= ponto_h($c['nome']) ?> (<?= ponto_h($c['matricula']) ?>)<?= $c['status'] === 'DESLIGADO' ? ' - desligado' : '' ?></option><?php endforeach; ?>
  </select></label>
  <label class="f">Mês<input type="month" name="c" value="<?= ponto_h($comp) ?>" onchange="this.form.submit()"></label>
  <?php if ($colId): ?><label class="ck" style="min-height:40px!important"><input type="checkbox" name="so" value="1" <?= $so ? 'checked' : '' ?> onchange="this.form.submit()"> Só dias com pendência</label><?php endif; ?>
  <noscript><button class="pt-btn pri" type="submit">Ver</button></noscript>
</form>

<?php if ($nPend): ?>
  <div class="pt-msg warn"><?= $nPend ?> pedido(s) do Meu Ponto aguardando aprovação (marcações esquecidas e justificativas). <?php if ($canOc): ?><a href="ponto_ocorrencias.php">Ver pedidos →</a><?php endif; ?></div>
<?php endif; ?>

<?php if (!$colId): ?>
  <?php $inc = ponto_inconsistencias($pdo, $cid, $de, min($ate, date('Y-m-d'))); ?>
  <section class="pt-card">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <div style="margin-right:auto"><h2>Pendências de <?= ponto_h($compLabel) ?></h2><p class="pt-sub" style="margin:0">Faltas sem justificativa, marcações incompletas e alertas da CLT (intervalo, descanso entre jornadas), pela última apuração.</p></div>
      <form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="apurar_todos"><button class="pt-btn" type="submit">Recalcular o mês de todos</button></form>
    </div>
    <?php if (!$inc): ?><div class="pt-empty">Nenhuma pendência apurada neste mês. Se acabou de instalar, clique em "Recalcular o mês de todos".</div><?php else: ?>
    <table class="pt-table cards">
      <thead><tr><th>Dia</th><th>Colaborador</th><th>Situação</th><th>Detalhe</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($inc as $r): $det = json_decode((string)$r['detalhe_json'], true) ?: []; [$sl, $sc] = PONTO_SITUACOES[$r['situacao']] ?? [$r['situacao'], 'n']; ?>
        <tr>
          <td data-l="Dia"><b><?= ponto_h(date('d/m', strtotime((string)$r['data']))) ?></b> <?= $wd[(int)date('w', strtotime((string)$r['data']))] ?></td>
          <td data-l="Colaborador"><?= ponto_h($r['nome']) ?></td>
          <td data-l="Situação"><span class="pt-badge <?= $sc ?>"><?= ponto_h($sl) ?></span></td>
          <td data-l="Detalhe"><?= ponto_h(implode(' ', (array)($det['alertas'] ?? []))) ?: ($r['situacao'] === 'FALTA' ? 'Sem marcações e sem justificativa.' : '') ?></td>
          <td class="act"><a class="pt-btn sm" href="ponto_tratamento.php?<?= ponto_h(http_build_query(['c' => $comp, 'col' => (int)$r['colaborador_id'], 'dia' => (string)$r['data']])) ?>">Tratar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
<?php else: $c = $cols[$colId]; $mesFechado = ponto_competencia_fechada($pdo, $cid, $colId, $de); ?>
  <?php if ($mesFechado): ?><div class="pt-msg warn"><?= ponto_h($compLabel) ?> está fechado: nenhuma alteração é aceita. Para corrigir, reabra o mês em <a href="ponto_fechamento.php?c=<?= ponto_h($comp) ?>&e=<?= (int)$c['estabelecimento_id'] ?>">Fechamento</a> (com o motivo).</div><?php endif; ?>
  <div class="ptt-k">
    <div><span>Previsto</span><b><?= ponto_h(ponto_hm($tot['previstos'], false)) ?></b></div>
    <div><span>Trabalhado</span><b><?= ponto_h(ponto_hm($tot['trabalhados'], false)) ?></b></div>
    <div><span>Horas extras</span><b style="color:#15803d"><?= ponto_h(ponto_hm($tot['extra'], false)) ?></b></div>
    <div><span>Débito (atrasos/faltas)</span><b style="color:#b91c1c"><?= ponto_h(ponto_hm($tot['falta'], false)) ?></b></div>
    <div><span>Saldo do mês</span><b style="color:<?= $tot['saldo'] < 0 ? '#b91c1c' : '#15803d' ?>"><?= ponto_h(ponto_hm($tot['saldo'], false)) ?></b></div>
    <div><span>Noturno (22h–5h)</span><b><?= ponto_h(ponto_hm($tot['noturnos'], false)) ?></b></div>
    <div><span>Abonado</span><b><?= ponto_h(ponto_hm($tot['abonados'], false)) ?></b></div>
    <div><span>Faltas / pendências</span><b style="color:<?= $tot['inconsistencias'] ? '#b91c1c' : '#0b1f5b' ?>"><?= (int)$tot['faltas_dias'] ?> / <?= (int)$tot['inconsistencias'] ?></b></div>
  </div>
  <section class="pt-card">
    <p class="pt-sub"><?= ponto_h($c['nome']) ?> · <?= ponto_h($compLabel) ?> · <span class="ptt-m">08:00</span> original · <span class="ptt-m i">08:00<sup>I</sup></span> incluída no tratamento · <span class="ptt-m x">08:00</span> desconsiderada · <span class="ptt-m p">08:00</span> pedido aguardando aprovação</p>
    <table class="pt-table cards">
      <thead><tr><th>Dia</th><th>Previsto</th><th>Marcações</th><th class="ptt-num">Trab.</th><th class="ptt-num">Extra</th><th class="ptt-num">Débito</th><th class="ptt-num">Noturno</th><th>Situação</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($dias as $d => $r):
        $bad = $r['inconsistente'] || $r['alertas'];
        if ($so && !$bad) continue;
        [$sl, $sc] = PONTO_SITUACOES[$r['situacao']] ?? [$r['situacao'], 'n'];
        $w = (int)date('w', strtotime($d)); ?>
        <tr class="<?= $bad ? 'bad' : (($w === 0 || $w === 6) ? 'fds' : '') ?>">
          <td data-l="Dia"><b><?= date('d/m', strtotime($d)) ?></b> <?= $wd[$w] ?></td>
          <td data-l="Previsto"><?= $r['previsto']['trabalha'] ? ponto_h(implode(' ', array_map(static fn($q) => $q['ini'] . '–' . $q['fim'], $r['previsto']['periodos']))) : '<span class="pt-hint">' . ponto_h($r['previsto']['feriado'] ?: ($r['previsto']['origem'] === 'sem_jornada' ? 'sem jornada' : 'folga')) . '</span>' ?></td>
          <td data-l="Marcações"><?php foreach ($r['marcacoes'] as $m): $cls = $m['desconsiderada'] ? 'x' : ($m['pendente'] ? 'p' : ($m['origem'] === 'INCLUIDA' ? 'i' : '')); ?><span class="ptt-m <?= $cls ?>" title="<?= ponto_h($intLabel($m['intencao']) . ($m['origem'] === 'INCLUIDA' ? ' (incluída: ' . ($m['motivo'] ?? '') . ')' : '') . ($m['desconsiderada'] ? ' (desconsiderada: ' . ($m['motivo'] ?? '') . ')' : '')) ?>"><?= date('H:i', $m['ts']) ?><?= $m['origem'] === 'INCLUIDA' ? '<sup>I</sup>' : '' ?><?= substr($m['data_hora'], 0, 10) !== $d ? '<sup>+1</sup>' : '' ?></span><?php endforeach; ?></td>
          <td data-l="Trabalhado" class="ptt-num"><?= ponto_h(ponto_hm($r['trabalhados'])) ?></td>
          <td data-l="Extra" class="ptt-num" style="color:#15803d"><?= ponto_h(ponto_hm($r['extra'])) ?></td>
          <td data-l="Débito" class="ptt-num" style="color:#b91c1c"><?= ponto_h(ponto_hm($r['falta'])) ?></td>
          <td data-l="Noturno" class="ptt-num"><?= ponto_h(ponto_hm($r['noturnos'])) ?></td>
          <td data-l="Situação"><span class="pt-badge <?= $sc ?>"><?= ponto_h($sl) ?></span>
            <?php if ($r['atraso']): ?><span class="ptt-al">atraso <?= ponto_h(ponto_hm($r['atraso'])) ?></span><?php endif; ?>
            <?php foreach ($r['alertas'] as $al): ?><span class="ptt-al"><?= ponto_h($al) ?></span><?php endforeach; ?>
            <?php foreach ($r['ocorrencias'] as $o): ?><span class="ptt-oc"><?= ponto_h($o['tipo'] . ($o['horas'] ? ' ' . $o['horas'] : '') . ($o['abona'] ? ' (abona)' : '')) ?></span><?php endforeach; ?>
            <?php if ($r['abonados']): ?><span class="ptt-oc">abonado <?= ponto_h(ponto_hm($r['abonados'])) ?></span><?php endif; ?>
          </td>
          <td class="act"><?php if (!$mesFechado && !in_array($r['situacao'], ['FUTURO', 'FORA_CONTRATO'], true)): ?><button class="pt-btn sm" type="button" onclick="pttDia('<?= $d ?>')">Tratar</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="pt-hint" style="margin:10px 0 0">Débito = atrasos, saídas antecipadas e faltas que não foram abonadas. Tolerância da CLT (art. 58, § 1º): até 5 minutos por marcação e 10 no dia não contam. Noturno em horas de relógio (a hora noturna reduzida entra no fechamento).</p>
  </section>

  <dialog class="pt-modal wide" id="pttModal">
    <div class="pt-mh"><h3 id="pttTitle">Tratar dia</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <div id="pttResumo" class="pt-sub"></div>
      <h4 class="ptt-sec">Marcações do dia</h4>
      <div class="ptt-dm" id="pttMarks"></div>
      <h4 class="ptt-sec">Incluir marcação esquecida</h4>
      <form method="post" class="pt-grid three">
        <?= ponto_csrf_field() ?><input type="hidden" name="action" value="incluir"><input type="hidden" name="colaborador_id" value="<?= $colId ?>"><input type="hidden" name="data" class="pttData">
        <label class="f">Horário<input type="time" name="hora" required></label>
        <label class="f">Tipo<select name="intencao"><option value="">—</option><?php foreach (PONTO_INTENCOES as $k => $v): ?><option value="<?= $k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="ck" style="align-self:end;min-height:40px!important"><input type="checkbox" name="proximo_dia" value="1"> É do dia seguinte (jornada noturna)</label>
        <label class="f">Ocorrência<select name="tipo_ocorrencia_id"><option value="">—</option><?php foreach ($tiposOc as $id => $tp): ?><option value="<?= $id ?>" <?= $tp['descricao'] === 'Esquecimento de marcação' ? 'data-def' : '' ?>><?= ponto_h($tp['descricao']) ?></option><?php endforeach; ?></select></label>
        <label class="f s2">Motivo (fica no histórico)<input type="text" name="motivo" maxlength="300" required placeholder="Ex.: esqueceu de registrar a volta do almoço"></label>
        <div class="s3" style="display:flex;justify-content:flex-end"><button class="pt-btn pri" type="submit">Incluir marcação</button></div>
      </form>
      <?php if ($canOc): ?>
      <h4 class="ptt-sec">Lançar ocorrência / abono</h4>
      <form method="post" enctype="multipart/form-data" class="pt-grid three">
        <?= ponto_csrf_field() ?><input type="hidden" name="action" value="ocorrencia"><input type="hidden" name="colaborador_id" value="<?= $colId ?>"><input type="hidden" name="data" class="pttData">
        <label class="f">Tipo<select name="tipo_ocorrencia_id" required><option value="">—</option><?php foreach ($tiposOc as $id => $tp): ?><option value="<?= $id ?>"><?= ponto_h($tp['descricao'] . ($tp['abona'] ? ' (abona)' : '')) ?></option><?php endforeach; ?></select></label>
        <label class="f">Até o dia (opcional)<input type="date" name="data_fim"></label>
        <label class="f">Documento (PDF/foto)<input type="file" name="documento" accept="application/pdf,image/jpeg,image/png"></label>
        <label class="f">Das (só parte do dia)<input type="time" name="hora_inicio"></label>
        <label class="f">Às<input type="time" name="hora_fim"></label>
        <label class="f">Descrição<input type="text" name="descricao" maxlength="500"></label>
        <div class="s3" style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><span class="pt-hint">Sem horário = dia inteiro. Tipo que abona não desconta as horas.</span><button class="pt-btn pri" type="submit">Lançar ocorrência</button></div>
      </form>
      <?php endif; ?>
      <h4 class="ptt-sec">Histórico do tratamento</h4>
      <div id="pttHist" class="pt-hint">Nada tratado neste dia.</div>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Fechar</button></div>
  </dialog>
  <?php
  $js = [];
  foreach ($dias as $d => $r) {
    $js[$d] = ['titulo' => date('d/m/Y', strtotime($d)) . ' (' . $wd[(int)date('w', strtotime($d))] . ')',
      'resumo' => (PONTO_SITUACOES[$r['situacao']][0] ?? $r['situacao']) . ' · previsto ' . ponto_hm($r['previstos'], false) . ' · trabalhado ' . ponto_hm($r['trabalhados'], false) . ($r['extra'] ? ' · extra ' . ponto_hm($r['extra']) : '') . ($r['falta'] ? ' · débito ' . ponto_hm($r['falta']) : '') . ($r['alertas'] ? ' · ' . implode(' ', $r['alertas']) : ''),
      'marks' => array_map(static fn($m) => ['id' => $m['id'], 'origem' => $m['origem'], 'hora' => date('H:i', $m['ts']) . (substr($m['data_hora'], 0, 10) !== $d ? ' (' . date('d/m', $m['ts']) . ')' : ''),
        'tipo' => $intLabel($m['intencao']), 'des' => $m['desconsiderada'], 'pend' => $m['pendente'], 'trat' => $m['tratamento_id'] ?? null, 'ajuste' => $m['ajuste_id'] ?? null, 'motivo' => $m['motivo'] ?? '', 'nsr' => $m['nsr']], $r['marcacoes']),
      'hist' => array_map(static fn($t) => ['tipo' => PONTO_TRATAMENTO_TIPOS[$t['tipo']] ?? $t['tipo'], 'hora' => $t['data_hora'] ? substr((string)$t['data_hora'], 11, 5) : '', 'motivo' => $t['motivo'], 'status' => $t['status'],
        'por' => (string)($users[(int)$t['created_by']] ?? ($t['pelo_colaborador'] ? 'colaborador' : '')), 'em' => date('d/m/Y H:i', strtotime((string)$t['created_at'])), 'dec' => $t['motivo_decisao']], $hist[$d] ?? [])];
  }
  ?>
  <script>
  (function(){
    const D = <?= json_encode($js, JSON_UNESCAPED_UNICODE) ?>, csrf = <?= json_encode(ponto_csrf()) ?>, col = <?= $colId ?>;
    const INT = <?= json_encode(PONTO_INTENCOES, JSON_UNESCAPED_UNICODE) ?>;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const form = (action, dia, extra, label, cls) => `<form method="post" class="ptt-act" data-a="${action}"><input type="hidden" name="csrf" value="${esc(csrf)}"><input type="hidden" name="action" value="${action}"><input type="hidden" name="colaborador_id" value="${col}"><input type="hidden" name="data" value="${dia}">${extra}<input type="text" name="motivo" maxlength="300" required placeholder="Motivo (obrigatório)"><button class="pt-btn sm ${cls||'pri'}" type="submit">${label}</button></form>`;
    window.pttDia = function(dia){
      const x = D[dia]; if (!x) return;
      document.getElementById('pttTitle').textContent = 'Tratar ' + x.titulo;
      document.getElementById('pttResumo').textContent = x.resumo;
      document.querySelectorAll('.pttData').forEach(i => i.value = dia);
      const sel = document.querySelector('#pttModal select[name=tipo_ocorrencia_id] option[data-def]'); if (sel) sel.selected = true;
      document.getElementById('pttMarks').innerHTML = x.marks.length ? x.marks.map((m, i) => {
        let tag = m.origem === 'INCLUIDA' ? (m.pend ? '<span class="pt-badge y">Pedido pendente</span>' : '<span class="pt-badge g">Incluída</span>') : '<span class="pt-badge n">Original · NSR ' + m.nsr + '</span>';
        if (m.des) tag += ' <span class="pt-badge r">Desconsiderada</span>';
        let btns = '', forms = '';
        if (m.origem === 'ORIGINAL' && !m.des) {
          btns += `<button class="pt-btn sm" type="button" data-t="d${i}">Desconsiderar</button><button class="pt-btn sm" type="button" data-t="r${i}">Mudar tipo</button>`;
          forms += `<div id="d${i}">${form('desconsiderar', dia, `<input type="hidden" name="marcacao_id" value="${m.id}">`, 'Desconsiderar', 'red')}</div>`;
          forms += `<div id="r${i}">${form('reclassificar', dia, `<input type="hidden" name="marcacao_id" value="${m.id}"><select name="intencao" required style="width:auto">${Object.keys(INT).map(k => `<option value="${k}">${esc(INT[k])}</option>`).join('')}</select>`, 'Salvar tipo')}</div>`;
        }
        const desfazer = m.des ? m.trat : (m.origem === 'INCLUIDA' && !m.pend ? m.trat : null);
        if (desfazer) { btns += `<button class="pt-btn sm" type="button" data-t="c${i}">Desfazer</button>`; forms += `<div id="c${i}">${form('cancelar_tratamento', dia, `<input type="hidden" name="tratamento_id" value="${desfazer}">`, 'Desfazer tratamento', 'red')}</div>`; }
        return `<div class="row"><b style="${m.des ? 'text-decoration:line-through;color:#94a3b8' : ''}">${esc(m.hora)}</b><span>${esc(m.tipo || '—')}</span>${tag}${m.motivo ? `<span class="pt-hint">${esc(m.motivo)}</span>` : ''}<span class="sp"></span>${btns}${forms}</div>`;
      }).join('') : '<div class="pt-empty">Nenhuma marcação neste dia.</div>';
      document.getElementById('pttHist').innerHTML = x.hist.length ? x.hist.map(h => `<div class="ptt-h ${esc(h.status)}"><b>${esc(h.tipo)}</b>${h.hora ? ' ' + esc(h.hora) : ''} · ${esc(h.motivo)} · ${esc(h.por)} em ${esc(h.em)} · <b>${esc({ATIVO:'em vigor',CANCELADO:'desfeito',PENDENTE:'aguardando',REJEITADO:'rejeitado'}[h.status] || h.status)}</b>${h.dec ? ' (' + esc(h.dec) + ')' : ''}</div>`).join('') : 'Nada tratado neste dia.';
      ptOpen('pttModal');
    };
    document.getElementById('pttMarks').addEventListener('click', e => {
      const b = e.target.closest('button[data-t]'); if (!b) return;
      const box = document.getElementById(b.dataset.t); if (!box) return;
      const f = box.querySelector('.ptt-act'); const on = !f.classList.contains('on');
      b.closest('.row').querySelectorAll('.ptt-act').forEach(x => x.classList.remove('on'));
      if (on) { f.classList.add('on'); const t = f.querySelector('input[name=motivo]'); if (t) t.focus(); }
    });
    <?php $abrir = ponto_date_safe($_GET['dia'] ?? '', ''); if ($abrir && isset($js[$abrir])): ?>window.addEventListener('DOMContentLoaded', () => pttDia(<?= json_encode($abrir) ?>));<?php endif; ?>
  })();
  </script>
<?php endif; ?>
<?php ponto_end(); ?>
