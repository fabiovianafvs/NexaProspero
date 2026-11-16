<?php
declare(strict_types=1);

// Ponto Eletrônico > Painel do Ponto (V1): situação dos cadastros, primeiros passos e quem
// está previsto para trabalhar hoje. Presença em tempo real entra com a marcação (V2).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$cid = ponto_guard($pdo, 'ponto_painel');
$hoje = date('Y-m-d');
$dia = ponto_date_safe($_GET['dia'] ?? '', $hoje);

ponto_start($pdo, 'ponto_painel', 'Painel do Ponto', 'Resumo do cadastro e quem deveria trabalhar no dia. A presença em tempo real (quem chegou, intervalo, atrasos) aparece aqui quando a marcação de ponto for liberada.');

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
.pp-p small{display:block;color:#64748b;font-size:12px}.pp-p .h{margin-left:auto;text-align:right;font-size:12.5px;font-weight:800;color:#0b1f5b;white-space:nowrap}
@media(max-width:900px){.pp-two{grid-template-columns:1fr}}
</style>
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
    <?php if ($pend['sem_estab'] && $steps[0][1] > 0): ?>
      <div class="pt-msg warn" style="margin:8px 0 0"><?= count($pend['sem_estab']) ?> colaborador(es) sem estabelecimento. O estabelecimento será obrigatório para registrar o ponto.</div>
    <?php endif; ?>
  </section>
  <section class="pt-card">
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px">
      <div style="margin-right:auto"><h2>Previsto para o dia</h2><p class="pt-sub" style="margin:0"><?= $feriadoDia ? '🎉 ' . ponto_h($feriadoDia) : 'Pela escala ou jornada de cada colaborador.' ?></p></div>
      <label class="f">Dia<input type="date" name="dia" value="<?= ponto_h($dia) ?>" onchange="this.form.submit()"></label>
    </form>
    <?php if (!$prev): ?><div class="pt-empty">Nenhum colaborador ativo que controla ponto.</div><?php else: ?>
    <div class="pp-list">
      <?php foreach ($prev as $x): $c = $x['c']; $p = $x['p']; ?>
        <div class="pp-p <?= $p['trabalha'] ? '' : 'off' ?>">
          <?php if (!empty($c['foto_hash'])): ?><img class="pp-ph" src="ponto_colaborador_foto.php?id=<?= (int)$c['id'] ?>&v=<?= ponto_h(substr((string)$c['foto_hash'], 0, 10)) ?>" alt="" loading="lazy"><?php else: ?><span class="pp-ph"><?= ponto_h(mb_strtoupper(mb_substr((string)$c['nome'], 0, 1))) ?></span><?php endif; ?>
          <div><b><?= ponto_h($c['nome']) ?></b><small><?= ponto_h(implode(' · ', array_filter([$c['departamento'], $p['escala'] ?: $c['jornada']]))) ?></small></div>
          <div class="h"><?= $p['trabalha'] ? ponto_h(implode('  ', array_map(static fn($q) => $q['ini'] . '–' . $q['fim'], $p['periodos']))) . '<br><span class="pt-hint">' . ponto_h(ponto_minutes_label((int)$p['minutos'])) . '</span>' : ($p['origem'] === 'sem_jornada' ? '<span class="pt-badge r">Sem jornada</span>' : '<span class="pt-badge n">' . ($p['feriado'] ? 'Feriado' : 'Folga') . '</span>') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php ponto_end(); ?>
