<?php
declare(strict_types=1);

// Ponto Eletrônico > Painel do Ponto: situação dos cadastros, primeiros passos e, com a
// marcação (V2) instalada, a presença do dia (quem chegou, intervalo, atrasos, fora da área).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$cid = ponto_guard($pdo, 'ponto_painel');
$hoje = date('Y-m-d');
$dia = ponto_date_safe($_GET['dia'] ?? '', $hoje);

require_once __DIR__ . '/../config/ponto_marcacao.php';
require_once __DIR__ . '/../config/ponto_apuracao.php';
$v2 = ponto_v2_ready($pdo);
ponto_start($pdo, 'ponto_painel', 'Painel do Ponto', $v2 ? 'Presença do dia pelas marcações de ponto e pela escala/jornada de cada colaborador. Atualize a página para ver os registros mais recentes.' : 'Resumo do cadastro e quem deveria trabalhar no dia.', $v2 ? '<a class="pt-btn ok" href="ponto_bater.php">Bater meu ponto</a>' : '');

$count = static function (string $sql, array $p) use ($pdo): int { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
$tot = [];
foreach (['ATIVO', 'AFASTADO', 'DESLIGADO'] as $s) $tot[$s] = $count('SELECT COUNT(*) FROM ponto_colaboradores WHERE company_id = ? AND status = ?', [$cid, $s]);
$cols = ponto_colaborador_list($pdo, $cid, ['status' => 'ATIVO']);
$pend = ['sem_jornada' => [], 'sem_foto' => [], 'sem_estab' => []];
foreach ($cols as $c) {
  if (!(int)$c['controla_ponto']) continue;
  if (!$c['jornada'] && !$c['escala']) $pend['sem_jornada'][] = $c;
  if (empty($c['foto_hash'])) $pend['sem_foto'][] = $c;
  if (empty($c['estabelecimento_id'])) $pend['sem_estab'][] = $c;
}
$steps = [
  ['Estabelecimento (matriz)', $count('SELECT COUNT(*) FROM ponto_estabelecimentos WHERE company_id = ? AND status = 1', [$cid]), 'ponto_estabelecimentos.php', 'ponto_estabelecimentos'],
  ['Departamentos e cargos', $count('SELECT (SELECT COUNT(*) FROM ponto_departamentos WHERE company_id = ? AND status = 1) + (SELECT COUNT(*) FROM ponto_cargos WHERE company_id = ? AND status = 1)', [$cid, $cid]), 'ponto_departamentos.php', 'ponto_departamentos'],
  ['Jornadas', $count('SELECT COUNT(*) FROM ponto_jornadas WHERE company_id = ? AND status = 1', [$cid]), 'ponto_jornadas.php', 'ponto_jornadas'],
  ['Escalas (se houver revezamento ou 12x36)', $count('SELECT COUNT(*) FROM ponto_escalas WHERE company_id = ? AND status = 1', [$cid]), 'ponto_escalas.php', 'ponto_escalas'],
  ['Locais de trabalho com mapa', $count('SELECT COUNT(*) FROM ponto_locais WHERE company_id = ? AND status = 1 AND latitude IS NOT NULL', [$cid]), 'ponto_locais.php', 'ponto_locais'],
  ['Feriados do ano', $count('SELECT COUNT(*) FROM ponto_feriados WHERE company_id = ? AND status = 1 AND (recorrente = 1 OR YEAR(`data`) = ?)', [$cid, (int)date('Y')]), 'ponto_feriados.php', 'ponto_feriados'],
  ['Colaboradores', $tot['ATIVO'] + $tot['AFASTADO'], 'ponto_colaboradores.php', 'ponto_colaboradores'],
];
$done = count(array_filter($steps, static fn($s) => $s[1] > 0));

// Previsto para o dia escolhido.
$prev = [];
foreach ($cols as $c) {
  if (!(int)$c['controla_ponto']) continue;
  if ($c['data_admissao'] > $dia || ($c['data_demissao'] && $c['data_demissao'] < $dia)) continue;
  $p = ponto_previsto_do_dia($pdo, $cid, $c, $dia);
  $prev[] = ['c' => $c, 'p' => $p, 'ini' => $p['periodos'][0]['ini'] ?? '99:99'];
}
usort($prev, static fn($a, $b) => [!$a['p']['trabalha'], $a['ini'], $a['c']['nome']] <=> [!$b['p']['trabalha'], $b['ini'], $b['c']['nome']]);
// Presença (V2): situação pelas marcações do dia.
$pres = []; $presCount = ['TRABALHANDO' => 0, 'INTERVALO' => 0, 'NAO_CHEGOU' => 0, 'ATRASO' => 0, 'FORA' => 0, 'SAIU' => 0];
$clock = null;
$alertaFora = !empty(ponto_config($pdo, $cid)['alertar_fora_area']);
if ($v2) {
  foreach (ponto_presenca_dia($pdo, $cid, $dia) as $x) {
    $pres[(int)$x['c']['id']] = $x;
    if (isset($presCount[$x['situacao']])) $presCount[$x['situacao']]++;
    if ($x['atraso_min'] > 0 && in_array($x['situacao'], ['NAO_CHEGOU', 'TRABALHANDO', 'INTERVALO', 'SAIU'], true)) $presCount['ATRASO']++;
    if ($x['fora_area'] > 0) $presCount['FORA']++;
  }
  // Relógio: mostra a última conferência; se passou de 10 minutos, confere de novo depois
  // de entregar a página (a tela não espera pela internet).
  $clock = ponto_clock_last($pdo);
  if (!$clock || strtotime((string)$clock['checked_at']) < time() - 600) {
    register_shutdown_function(static function () use ($pdo): void {
      if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
      ponto_clock_check($pdo, true);
    });
  }
}
$sitBadge = ['TRABALHANDO' => ['Trabalhando', 'g'], 'INTERVALO' => ['Intervalo', 'y'], 'SAIU' => ['Saiu', 'n'], 'NAO_CHEGOU' => ['Ainda não chegou', 'r'],
  'FOLGA' => ['Folga', 'n'], 'FERIADO' => ['Feriado', 'n'], 'AFASTADO' => ['Afastado', 'y'], 'SEM_JORNADA' => ['Sem jornada', 'r']];
if ($dia < $hoje) $sitBadge['NAO_CHEGOU'] = ['Sem registro', 'r'];
$trabalham = count(array_filter($prev, static fn($x) => $x['p']['trabalha']));
$feriadoDia = null;
foreach ($prev as $x) if ($x['p']['feriado']) { $feriadoDia = $x['p']['feriado']; break; }
?>
<style>
.pp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:12px}
.pp-kpi{background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:12px 14px}.pp-kpi span{display:block;font-size:12px;color:#64748b;font-weight:800}.pp-kpi b{display:block;font-size:26px;color:#0b1f5b;line-height:1.2}
.pp-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:12px;align-items:start}
.pp-step{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eef2f7;font-size:13.5px}.pp-step:last-child{border-bottom:0}
.pp-step i{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-style:normal;font-weight:900;font-size:12px;flex:none;background:#e2e8f0;color:#475569}.pp-step i.ok{background:#16a34a;color:#fff}
.pp-step a{margin-left:auto;font-weight:800;font-size:12.5px;color:#2454d6;text-decoration:none;white-space:nowrap}
.pp-list{display:grid;gap:6px}
.pp-p{display:flex;gap:10px;align-items:center;padding:7px 8px;border:1px solid #eef2f7;border-radius:12px}
.pp-p.off{opacity:.65}
.pp-ph{width:38px;height:38px;border-radius:50%;object-fit:cover;background:#e2e8f0;display:grid;place-items:center;font-weight:900;color:#475569;flex:none}
.pp-p small{display:block;color:#64748b;font-size:12px}
.pp-marks{display:flex;flex-wrap:wrap;gap:4px;margin-left:auto}.pp-marks span{font-variant-numeric:tabular-nums;font-weight:800;font-size:12.5px;border:1px solid #dbe4f0;border-radius:8px;padding:2px 6px;background:#f8fbff}.pp-marks span.fora{border-color:#fecaca;background:#fef2f2;color:#b91c1c}
.pp-marks+.h{margin-left:10px}.pp-p .h{margin-left:auto;text-align:right;font-size:12.5px;font-weight:800;color:#0b1f5b;white-space:nowrap}
@media(max-width:900px){.pp-two{grid-template-columns:1fr}}
</style>
<?php
$v4 = $v2 && ponto_v4_ready($pdo);
if ($v4) {
  $pp = ponto_solicitacoes_pendentes($pdo, $cid);
  $nPed = count($pp['inclusoes']) + count($pp['ocorrencias']);
  if ($nPed && ponto_can($pdo, 'ponto_ocorrencias')) echo '<div class="pt-msg warn">' . $nPed . ' pedido(s) do Meu Ponto aguardando aprovação. <a href="ponto_ocorrencias.php">Ver pedidos →</a></div>';
}
?>
<?php if ($v2): ?>
<div class="pp-kpis">
  <div class="pp-kpi"><span>Trabalhando agora</span><b style="color:#15803d"><?= $presCount['TRABALHANDO'] ?></b></div>
  <div class="pp-kpi"><span>Em intervalo</span><b style="color:#a16207"><?= $presCount['INTERVALO'] ?></b></div>
  <div class="pp-kpi"><span>Ainda não chegaram</span><b style="color:<?= $presCount['NAO_CHEGOU'] ? '#b91c1c' : '#0b1f5b' ?>"><?= $presCount['NAO_CHEGOU'] ?></b></div>
  <div class="pp-kpi"><span>Com atraso</span><b><?= $presCount['ATRASO'] ?></b></div>
  <?php if ($alertaFora): ?><div class="pp-kpi"><span>Ponto fora da área</span><b style="color:<?= $presCount['FORA'] ? '#b91c1c' : '#0b1f5b' ?>"><?= $presCount['FORA'] ?></b></div><?php endif; ?>
</div>
<?php endif; ?>
<div class="pp-kpis">
  <div class="pp-kpi"><span>Colaboradores ativos</span><b><?= $tot['ATIVO'] ?></b></div>
  <div class="pp-kpi"><span>Afastados</span><b><?= $tot['AFASTADO'] ?></b></div>
  <div class="pp-kpi"><span>Previstos para trabalhar <?= $dia === $hoje ? 'hoje' : 'em ' . date('d/m', strtotime($dia)) ?></span><b><?= $trabalham ?></b></div>
  <div class="pp-kpi"><span>Sem jornada definida</span><b style="color:<?= $pend['sem_jornada'] ? '#b91c1c' : '#0b1f5b' ?>"><?= count($pend['sem_jornada']) ?></b></div>
  <div class="pp-kpi"><span>Sem foto</span><b><?= count($pend['sem_foto']) ?></b></div>
</div>
<div class="pp-two">
  <section class="pt-card">
    <h2>Primeiros passos</h2>
    <p class="pt-sub"><?= $done ?> de <?= count($steps) ?> concluídos.</p>
    <?php foreach ($steps as $i => [$label, $n, $page, $perm]): ?>
      <div class="pp-step"><i class="<?= $n > 0 ? 'ok' : '' ?>"><?= $n > 0 ? '✓' : $i + 1 ?></i><span><?= ponto_h($label) ?><?= $n > 0 ? ' <span class="pt-hint">(' . $n . ')</span>' : '' ?></span><?php if (ponto_can($pdo, $perm)): ?><a href="<?= ponto_h($page) ?>"><?= $n > 0 ? 'Ver' : 'Cadastrar' ?> →</a><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($pend['sem_jornada']): ?>
      <div class="pt-msg warn" style="margin:10px 0 0">Sem jornada nem escala: <?= ponto_h(implode(', ', array_map(static fn($c) => $c['nome'], array_slice($pend['sem_jornada'], 0, 8)))) ?><?= count($pend['sem_jornada']) > 8 ? ' e mais ' . (count($pend['sem_jornada']) - 8) : '' ?>.</div>
    <?php endif; ?>
    <?php if ($v2): ?>
      <div class="pp-step" style="margin-top:8px;border-top:1px solid #eef2f7"><i class="<?= $clock && $clock['status'] === 'OK' ? 'ok' : '' ?>">⏱</i><span>Relógio do servidor:
        <?php if (!$clock): ?>conferência com a Hora Legal (NTP.br) em andamento — atualize a página em instantes.
        <?php elseif ($clock['status'] === 'OK'): ?>certo (diferença de <?= abs((int)$clock['offset_ms']) ?> ms para a Hora Legal Brasileira).
        <?php elseif ($clock['status'] === 'ATENCAO'): ?><b style="color:#b91c1c">diferença de <?= ponto_h(number_format(abs((int)$clock['offset_ms']) / 1000, 1, ',', '.')) ?> s</b> para a Hora Legal. Avise o suporte.
        <?php else: ?><b style="color:#a16207">não foi possível conferir</b>. <?= ponto_h((string)$clock['detalhe']) ?><?php endif; ?>
        <?php if ($clock): ?><span class="pt-hint">(<?= ponto_h(date('d/m H:i', strtotime((string)$clock['checked_at']))) ?>)</span><?php endif; ?></span></div>
    <?php endif; ?>
    <?php if ($pend['sem_estab'] && $steps[0][1] > 0): ?>
      <div class="pt-msg warn" style="margin:8px 0 0"><?= count($pend['sem_estab']) ?> colaborador(es) sem estabelecimento. O estabelecimento será obrigatório para registrar o ponto.</div>
    <?php endif; ?>
  </section>
  <section class="pt-card">
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px">
      <div style="margin-right:auto"><h2><?= $v2 ? 'Presença do dia' : 'Previsto para o dia' ?></h2><p class="pt-sub" style="margin:0"><?= $feriadoDia ? '🎉 ' . ponto_h($feriadoDia) : 'Pela escala ou jornada de cada colaborador.' ?></p></div>
      <label class="f">Dia<input type="date" name="dia" value="<?= ponto_h($dia) ?>" onchange="this.form.submit()"></label>
    </form>
    <?php if (!$prev): ?><div class="pt-empty">Nenhum colaborador ativo que controla ponto.</div><?php else: ?>
    <div class="pp-list">
      <?php foreach ($prev as $x): $c = $x['c']; $p = $x['p']; ?>
        <div class="pp-p <?= $p['trabalha'] ? '' : 'off' ?>">
          <?php if (!empty($c['foto_hash'])): ?><img class="pp-ph" src="ponto_colaborador_foto.php?id=<?= (int)$c['id'] ?>&v=<?= ponto_h(substr((string)$c['foto_hash'], 0, 10)) ?>" alt="" loading="lazy"><?php else: ?><span class="pp-ph"><?= ponto_h(mb_strtoupper(mb_substr((string)$c['nome'], 0, 1))) ?></span><?php endif; ?>
          <div><b><?= ponto_h($c['nome']) ?></b><small><?= ponto_h(implode(' · ', array_filter([$c['departamento'], $p['escala'] ?: $c['jornada']]))) ?></small></div>
          <?php $px = $pres[(int)$c['id']] ?? null; if ($px): [$bl, $bc] = $sitBadge[$px['situacao']] ?? [$px['situacao'], 'n']; ?>
            <div class="pp-marks"><?php foreach ($px['marcacoes'] as $mk): ?><?php $fx = $alertaFora && $mk['geofence'] === 'FORA_DA_AREA'; ?><span class="<?= $fx ? 'fora' : '' ?>" title="<?= ponto_h(PONTO_INTENCOES[(string)$mk['intencao']] ?? '') ?><?= $fx ? ' - fora da área' : '' ?>"><?= ponto_h(substr((string)$mk['data_hora'], 11, 5)) ?></span><?php endforeach; ?></div>
          <?php endif; ?>
          <div class="h"><?php if ($px): ?><span class="pt-badge <?= $bc ?>"><?= ponto_h($bl) ?></span><?php if ($px['atraso_min'] > 0): ?><br><span class="pt-hint" style="color:#b91c1c">atraso <?= ponto_h(ponto_minutes_label((int)$px['atraso_min'])) ?></span><?php endif; ?><br><?php endif; ?><?= $p['trabalha'] ? '<span class="pt-hint">' . ponto_h(implode('  ', array_map(static fn($q) => $q['ini'] . '–' . $q['fim'], $p['periodos']))) . '</span>' : ($px ? '' : ($p['origem'] === 'sem_jornada' ? '<span class="pt-badge r">Sem jornada</span>' : '<span class="pt-badge n">' . ($p['feriado'] ? 'Feriado' : 'Folga') . '</span>')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php ponto_end(); ?>
