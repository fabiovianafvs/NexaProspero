<?php
declare(strict_types=1);

// WhatsApp > Painel do Atendimento: resultados dos atendentes por dia, assuntos mais
// pedidos no robô, atendimentos por período, por hora e por dia da semana, espera e
// duração médias. Dados de config/whatsapp_painel.php (histórico de atendimentos).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_chatbot.php';

$cid = (int)COMPANY_ID;
if (!(access_is_company_master() || access_has_permission($pdo, $cid, 'whatsapp_painel'))) {
  if (function_exists('access_render_denied')) access_render_denied('Seu usuário não possui permissão para ver o Painel do Atendimento.');
  http_response_code(403); exit('Acesso restrito.');
}
function wap_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function wap_n($v): string { return number_format((float)$v, 0, ',', '.'); }

// Período: atalhos ou datas escolhidas (máximo 1 ano).
$today = date('Y-m-d');
$preset = (string)($_GET['p'] ?? '30');
$presets = ['hoje' => 'Hoje', '7' => '7 dias', '30' => '30 dias', 'mes' => 'Este mês', 'mes_ant' => 'Mês passado', 'livre' => 'Escolher datas'];
if (!isset($presets[$preset])) $preset = '30';
$validDate = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
switch ($preset) {
  case 'hoje': $from = $to = $today; break;
  case '7': $from = date('Y-m-d', strtotime('-6 days')); $to = $today; break;
  case 'mes': $from = date('Y-m-01'); $to = $today; break;
  case 'mes_ant': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month')); break;
  case 'livre':
    $from = $validDate($_GET['de'] ?? null) ? (string)$_GET['de'] : date('Y-m-d', strtotime('-29 days'));
    $to = $validDate($_GET['ate'] ?? null) ? (string)$_GET['ate'] : $today;
    if ($from > $to) [$from, $to] = [$to, $from];
    if (strtotime($to) - strtotime($from) > 366 * 86400) $from = date('Y-m-d', strtotime($to . ' -366 days'));
    break;
  default: $from = date('Y-m-d', strtotime('-29 days')); $to = $today;
}
$deptFilter = max(0, (int)($_GET['setor'] ?? 0));
$userFilter = max(0, (int)($_GET['atendente'] ?? 0));

$ready = whatsapp_bot_ready($pdo) && whatsapp_painel_ready($pdo);
$data = null; $err = '';
$userNames = []; $deptNames = [];
if ($ready) {
  try {
    whatsapp_painel_backfill($pdo, $cid); // uma vez: histórico anterior ao painel
    $userNames = whatsapp_painel_user_names($pdo, $cid);
    foreach (whatsapp_departments($pdo, $cid) as $d) $deptNames[(int)$d['id']] = (string)$d['name'];
    $data = whatsapp_painel_data($pdo, $cid, $from, $to, $deptFilter, $userFilter);
  } catch (Throwable $e) {
    $err = 'Não foi possível carregar o painel: ' . $e->getMessage();
  }
}
$userName = static fn(int $id) => $id > 0 ? ($userNames[$id] ?? ('Usuário #' . $id)) : 'Pelo celular';
$deptName = static fn(int $id) => $id > 0 ? ($deptNames[$id] ?? ('Setor #' . $id)) : 'Sem setor';
$weekdayNames = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
$fmtDay = static fn(string $d) => date('d/m', strtotime($d));

// Cores das séries (paleta categórica validada; ordem fixa, "Outros" em cinza).
$palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
$otherColor = '#9aa3ae';

/**
 * Gráfico de colunas (uma escala só). $series: [['name','color','values'=>[..]]];
 * $stacked empilha; $line = série desenhada como linha sobre as colunas (mesma unidade).
 * As colunas são SVG esticado na área do gráfico; os textos dos eixos são HTML, para
 * não deformarem em telas estreitas. Cada coluna tem uma área com data-tip (balão).
 */
function wap_bars(array $labels, array $series, bool $stacked, ?array $line, array $tips, int $height = 230): string {
  $n = count($labels);
  if ($n === 0) return '';
  $W = 1000.0; $H = (float)$height - 24; // área das colunas (24px embaixo para as datas)
  $max = 0.0;
  for ($i = 0; $i < $n; $i++) {
    $sum = 0.0; $big = 0.0;
    foreach ($series as $s) { $v = (float)($s['values'][$i] ?? 0); $sum += $v; $big = max($big, $v); }
    $max = max($max, $stacked ? $sum : $big, $line ? (float)($line['values'][$i] ?? 0) : 0);
  }
  // Escala "redonda": 1, 2, 5 x 10^n, com 4 linhas de grade.
  $step = 1.0;
  if ($max > 0) { $raw = $max / 4; $mag = 10 ** floor(log10($raw)); foreach ([1, 2, 5, 10] as $m) { if ($m * $mag >= $raw) { $step = $m * $mag; break; } } }
  $step = max(1.0, $step);
  $top = $step * max(1, (int)ceil($max / $step));
  $T = 8.0; $ph = $H - $T;
  $slot = $W / $n; $bw = max(2.0, min(46.0, $slot * 0.62));
  $y = static fn(float $v) => $T + $ph - ($v / $top) * $ph;
  $f = static fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
  $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" class="wap-svg" style="height:' . (int)$H . 'px" aria-hidden="true">';
  $yl = '';
  for ($g = 0; $g <= $top + 0.001; $g += $step) {
    $gy = $y($g);
    $svg .= '<line x1="0" x2="' . $W . '" y1="' . $f($gy) . '" y2="' . $f($gy) . '" class="wap-grid"/>';
    $yl .= '<span class="wap-yl" style="top:' . $f($gy / $height * 100) . '%">' . number_format($g, 0, ',', '.') . '</span>';
  }
  $every = max(1, (int)ceil($n / 16));
  $xl = '';
  for ($i = 0; $i < $n; $i++) {
    $cx = $slot * $i + $slot / 2; $x0 = $cx - $bw / 2;
    $base = 0.0; $segs = [];
    foreach ($series as $s) {
      $v = (float)($s['values'][$i] ?? 0);
      if ($v <= 0) continue;
      if ($stacked) { $segs[] = [$base, $base + $v, $s['color']]; $base += $v; }
      else $segs[] = [0.0, $v, $s['color']];
    }
    $last = count($segs) - 1;
    foreach ($segs as $k => [$a, $b, $color]) {
      $ya = $y($a); $yb = $y($b);
      if ($k > 0) $ya -= 2; // 2px de separação entre segmentos empilhados
      $h = $ya - $yb;
      if ($h <= 0) continue;
      $r = $k === $last ? min(4.0, $h, $bw / 2) : 0.0; // ponta arredondada só em cima
      $svg .= '<path fill="' . $color . '" d="M' . $f($x0) . ',' . $f($ya) . 'V' . $f($yb + $r)
        . ($r > 0 ? 'Q' . $f($x0) . ',' . $f($yb) . ' ' . $f($x0 + $r) . ',' . $f($yb) . 'H' . $f($x0 + $bw - $r) . 'Q' . $f($x0 + $bw) . ',' . $f($yb) . ' ' . $f($x0 + $bw) . ',' . $f($yb + $r) : 'H' . $f($x0 + $bw))
        . 'V' . $f($ya) . 'Z"/>';
    }
    if ($i % $every === 0) {
      $xl .= '<span class="wap-xl' . (($i / $every) % 2 ? ' alt' : '') . '" style="left:' . $f($cx / $W * 100) . '%">' . htmlspecialchars((string)$labels[$i], ENT_QUOTES, 'UTF-8') . '</span>';
    }
  }
  if ($line) {
    $pts = [];
    for ($i = 0; $i < $n; $i++) $pts[] = $f($slot * $i + $slot / 2) . ',' . $f($y((float)($line['values'][$i] ?? 0)));
    $svg .= '<polyline fill="none" stroke="' . $line['color'] . '" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"/>';
  }
  for ($i = 0; $i < $n; $i++) {
    $svg .= '<rect class="wap-hit" x="' . $f($slot * $i) . '" y="0" width="' . $f($slot) . '" height="' . $H . '" data-tip="' . htmlspecialchars((string)($tips[$i] ?? ''), ENT_QUOTES, 'UTF-8') . '"/>';
  }
  $svg .= '</svg>';
  return '<div class="wap-chart" style="height:' . $height . 'px"><div class="wap-yax">' . $yl . '</div><div class="wap-plot">' . $svg . '<div class="wap-xax">' . $xl . '</div></div></div>';
}

$qs = static function (array $over) use ($preset, $from, $to, $deptFilter, $userFilter): string {
  $base = ['p' => $preset, 'setor' => $deptFilter ?: null, 'atendente' => $userFilter ?: null];
  if ($preset === 'livre') { $base['de'] = $from; $base['ate'] = $to; }
  return 'whatsapp_painel.php?' . http_build_query(array_filter(array_merge($base, $over), static fn($v) => $v !== null && $v !== ''));
};

admin_topbar('Painel do Atendimento', 'whatsapp_painel');
?>
<style>
.wap{max-width:1240px;margin:14px auto 0;display:grid;gap:14px;color:#0b1f5b}
.wap *{box-sizing:border-box}
.wap-head{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-radius:22px;padding:16px 18px;display:grid;gap:12px;box-shadow:0 18px 48px rgba(11,31,91,.16)}
.wap-head h1{margin:0;font-size:22px}.wap-head p{margin:2px 0 0;color:#dbeafe;font-size:13px}
.wap-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.wap-chips{display:flex;flex-wrap:wrap;gap:6px}
.wap-chip{border:1px solid rgba(255,255,255,.35);color:#fff;text-decoration:none;border-radius:999px;padding:7px 12px;font-weight:800;font-size:13px;white-space:nowrap}
.wap-chip.on{background:#fff;color:#0b1f5b;border-color:#fff}
.wap-filters label{display:grid;gap:3px;font-size:11px;font-weight:800;color:#bfdbfe;margin:0!important;min-height:0!important}
.wap-filters input,.wap-filters select{height:38px;border:0;border-radius:10px;padding:0 10px;font:inherit;font-size:13px;color:#0b1f5b;min-width:0}
.wap-filters button{height:38px;border:0;border-radius:10px;padding:0 16px;background:#22c55e;color:#052e16;font-weight:900;cursor:pointer}
.wap-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.wap-kpi{background:#fff;border:1px solid #dfe7f3;border-radius:16px;padding:12px 14px;box-shadow:0 10px 28px rgba(15,23,42,.04)}
.wap-kpi span{display:block;font-size:12px;color:#64748b;font-weight:800}
.wap-kpi b{display:block;font-size:24px;line-height:1.15;margin-top:2px;color:#0b1f5b;font-variant-numeric:tabular-nums}
.wap-kpi small{display:block;color:#64748b;font-size:12px;margin-top:2px}
.wap-kpi.live{border-color:#bbf7d0;background:#f0fdf4}
.wap-card{background:#fff;border:1px solid #dfe7f3;border-radius:20px;padding:14px 16px;box-shadow:0 12px 34px rgba(15,23,42,.05);min-width:0}
.wap-card h2{margin:0 0 2px;font-size:16px}.wap-sub{color:#64748b;font-size:12.5px;margin:0 0 8px}
.wap-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.wap-chart{position:relative;display:grid;grid-template-columns:38px minmax(0,1fr);overflow:hidden}
.wap-yax{position:relative}
.wap-yl{position:absolute;right:6px;transform:translateY(-50%);font-size:11.5px;color:#64748b;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.wap-plot{position:relative;min-width:0}
.wap-svg{display:block;width:100%}
.wap-xax{position:relative;height:24px}
.wap-xl{position:absolute;top:6px;transform:translateX(-50%);font-size:11.5px;color:#64748b;font-weight:700;white-space:nowrap}
.wap-grid{stroke:#e5edf8;stroke-width:1;vector-effect:non-scaling-stroke}
.wap-hit{fill:transparent;cursor:default}.wap-hit:hover{fill:rgba(11,31,91,.05)}
.wap-legend{display:flex;flex-wrap:wrap;gap:6px 14px;margin:2px 0 6px;font-size:12.5px;color:#334155;font-weight:700}
.wap-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.wap-legend i.ln{height:3px;border-radius:2px;vertical-align:3px}
.wap-tip{position:fixed;z-index:9999;pointer-events:none;background:#0b1f5b;color:#fff;border-radius:10px;padding:8px 10px;font-size:12.5px;line-height:1.45;white-space:pre-line;box-shadow:0 10px 30px rgba(0,0,0,.25);display:none;max-width:280px}
.wap-table{width:100%;border-collapse:collapse;table-layout:fixed}
.wap-table th{text-align:right;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.02em;padding:8px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.wap-table td{padding:8px;border-bottom:1px solid #eef2f7;font-size:13px;text-align:right;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.wap-table th:first-child,.wap-table td:first-child{text-align:left}
.wap-table td:first-child{font-weight:800}
.wap-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:0}
.wap-rows{display:grid;gap:6px}
.wap-row{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) auto;gap:10px;align-items:center;font-size:13px}
.wap-row .lb{font-weight:800;overflow-wrap:anywhere}
.wap-row .bar{height:10px;background:#eef2f7;border-radius:999px;overflow:hidden}.wap-row .bar i{display:block;height:100%;background:#2a78d6;border-radius:999px}
.wap-row .vl{font-weight:900;white-space:nowrap;font-variant-numeric:tabular-nums}.wap-row .vl small{color:#64748b;font-weight:700}
.wap-empty{padding:18px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:14px}
.wap-msg{padding:12px 14px;border-radius:14px;font-weight:700;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.wap-note{color:#64748b;font-size:12px}
details.wap-more summary{cursor:pointer;font-weight:800;font-size:13px;color:#2454d6;margin-top:6px}
@media(max-width:900px){.wap-two{grid-template-columns:1fr}}
@media(max-width:700px){
  .wap-xl.alt{display:none}.wap-filters label{flex:1 1 140px}.wap-filters button{flex:1 1 100%}
  .wap-table.cards thead{display:none}
  .wap-table.cards,.wap-table.cards tbody,.wap-table.cards tr,.wap-table.cards td{display:block;width:auto}
  .wap-table.cards tr{border-bottom:1px solid #e2e8f0;padding:6px 0}
  .wap-table.cards td{border:0;padding:2px 4px;text-align:left;display:flex;justify-content:space-between;gap:10px}
  .wap-table.cards td::before{content:attr(data-l);color:#64748b;font-weight:700;font-size:12px}
  .wap-table.cards td:first-child{display:block;font-size:14px;padding-bottom:4px}
  .wap-table.cards td:first-child::before{content:none}
}
</style>
<div class="wap">
  <section class="wap-head">
    <div><h1>Painel do Atendimento</h1><p><?= wap_h(date('d/m/Y', strtotime($from))) ?><?= $from !== $to ? ' a ' . wap_h(date('d/m/Y', strtotime($to))) : '' ?> · atendimentos do WhatsApp pela tela de Atendimento e pelo celular</p></div>
    <form method="get" class="wap-filters">
      <div class="wap-chips">
        <?php foreach ($presets as $k => $label): if ($k === 'livre') continue; ?>
          <a class="wap-chip <?= $preset === $k ? 'on' : '' ?>" href="<?= wap_h($qs(['p' => $k, 'de' => null, 'ate' => null])) ?>"><?= wap_h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="p" value="livre">
      <label>De<input type="date" name="de" value="<?= wap_h($from) ?>" max="<?= wap_h($today) ?>"></label>
      <label>Até<input type="date" name="ate" value="<?= wap_h($to) ?>" max="<?= wap_h($today) ?>"></label>
      <label>Setor<select name="setor"><option value="0">Todos</option><?php foreach ($deptNames as $id => $nm): ?><option value="<?= (int)$id ?>" <?= $deptFilter === $id ? 'selected' : '' ?>><?= wap_h($nm) ?></option><?php endforeach; ?></select></label>
      <label>Atendente<select name="atendente"><option value="0">Todos</option><?php foreach ($userNames as $id => $nm): ?><option value="<?= (int)$id ?>" <?= $userFilter === $id ? 'selected' : '' ?>><?= wap_h($nm) ?></option><?php endforeach; ?></select></label>
      <button type="submit">Filtrar</button>
    </form>
  </section>

<?php if (!$ready): ?>
  <div class="wap-msg">O painel ainda não está disponível nesta empresa. Peça ao administrador da plataforma para aplicar a atualização "WhatsApp - Painel do Atendimento" no Atualizador do Banco.</div>
<?php elseif ($err !== ''): ?>
  <div class="wap-msg"><?= wap_h($err) ?></div>
<?php else:
  $k = $data['kpi'];
  $days = $data['days'];
  $dayKeys = array_keys($days);
  $dayLabels = array_map($fmtDay, $dayKeys);
  $accRate = $k['total'] > 0 ? round($k['accepted'] / $k['total'] * 100) : 0;
?>
  <section class="wap-kpis">
    <div class="wap-kpi"><span>Atendimentos</span><b><?= wap_n($k['total']) ?></b><small>média de <?= number_format($k['per_day'], 1, ',', '.') ?> por dia</small></div>
    <div class="wap-kpi"><span>Clientes atendidos</span><b><?= wap_n($k['contacts']) ?></b><small><?= wap_n($k['transfers']) ?> transferência(s)</small></div>
    <div class="wap-kpi"><span>Espera média na fila</span><b><?= wap_h(whatsapp_painel_duration($k['avg_wait'])) ?></b><small>da fila até o atendente aceitar</small></div>
    <div class="wap-kpi"><span>Duração média</span><b><?= wap_h(whatsapp_painel_duration($k['avg_duration'])) ?></b><small>do aceite até finalizar</small></div>
    <div class="wap-kpi"><span>Sem atendimento</span><b><?= wap_n($k['unattended']) ?></b><small><?= $accRate ?>% foram atendidos</small></div>
    <div class="wap-kpi"><span>Conversas no WhatsApp</span><b><?= wap_n($k['chats']) ?></b><small><?= wap_n($k['chat_msgs']) ?> mensagens recebidas (robô + equipe)</small></div>
    <div class="wap-kpi live"><span>Agora</span><b><?= wap_n($k['queue_now']) ?> <small style="display:inline;font-size:13px">na fila</small></b><small><?= wap_n($k['attending_now']) ?> em atendimento</small></div>
  </section>

  <section class="wap-card">
    <h2>Atendimentos por dia</h2>
    <p class="wap-sub">Colunas: atendimentos com a equipe. Linha: todas as conversas do WhatsApp no dia (inclui as resolvidas só pelo robô).</p>
    <div class="wap-legend"><span><i style="background:<?= $palette[0] ?>"></i>Atendimentos</span><span><i class="ln" style="background:<?= $palette[1] ?>"></i>Conversas no WhatsApp</span></div>
    <?php
      $tips = [];
      foreach ($days as $d => $r) $tips[] = date('d/m/Y', strtotime($d)) . ' (' . $weekdayNames[(int)date('w', strtotime($d)) + 1] . ")\nAtendimentos: " . $r['total'] . "\nAtendidos: " . $r['accepted'] . "\nEspera média: " . whatsapp_painel_duration($r['avg_wait']) . "\nConversas no WhatsApp: " . $r['chats'];
      echo wap_bars($dayLabels, [['name' => 'Atendimentos', 'color' => $palette[0], 'values' => array_column(array_values($days), 'total')]], false,
        ['name' => 'Conversas', 'color' => $palette[1], 'values' => array_column(array_values($days), 'chats')], $tips);
    ?>
  </section>

  <?php
    // Atendentes: os 6 com mais atendimentos ganham cor própria; o resto vira "Outros".
    $users = $data['users'];
    $topUsers = array_slice(array_map(static fn($u) => (int)$u['uid'], $users), 0, 6);
    $stackSeries = [];
    foreach ($topUsers as $i => $uidTop) {
      $stackSeries[] = ['name' => $userName($uidTop), 'color' => $palette[$i], 'values' => array_map(static fn($d) => (int)($data['user_days'][$uidTop][$d] ?? 0), $dayKeys)];
    }
    $others = array_diff(array_keys($data['user_days']), $topUsers);
    if ($others) {
      $stackSeries[] = ['name' => 'Outros', 'color' => $otherColor, 'values' => array_map(static function ($d) use ($others, $data) { $s = 0; foreach ($others as $o) $s += (int)($data['user_days'][$o][$d] ?? 0); return $s; }, $dayKeys)];
    }
    $stackTips = [];
    foreach ($dayKeys as $i => $d) {
      $lines = [date('d/m/Y', strtotime($d))];
      $tot = 0;
      foreach ($stackSeries as $s) { if ($s['values'][$i] > 0) { $lines[] = $s['name'] . ': ' . $s['values'][$i]; $tot += $s['values'][$i]; } }
      $lines[] = 'Total: ' . $tot;
      $stackTips[] = implode("\n", $lines);
    }
  ?>
  <section class="wap-card">
    <h2>Resultados dos atendentes por dia</h2>
    <p class="wap-sub">Atendimentos aceitos por cada atendente em cada dia.</p>
    <?php if (!$users): ?><div class="wap-empty">Nenhum atendimento aceito no período.</div><?php else: ?>
      <div class="wap-legend"><?php foreach ($stackSeries as $s): ?><span><i style="background:<?= $s['color'] ?>"></i><?= wap_h($s['name']) ?></span><?php endforeach; ?></div>
      <?= wap_bars($dayLabels, $stackSeries, true, null, $stackTips) ?>
      <details class="wap-more">
        <summary>Ver em tabela (por dia)</summary>
        <table class="wap-table cards" style="margin-top:8px">
          <thead><tr><th>Dia</th><?php foreach ($stackSeries as $s): ?><th><span class="wap-dot" style="background:<?= $s['color'] ?>"></span><?= wap_h($s['name']) ?></th><?php endforeach; ?><th>Total</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($dayKeys, true) as $i => $d): $tot = 0; foreach ($stackSeries as $s) $tot += $s['values'][$i]; if ($tot === 0) continue; ?>
            <tr><td><?= wap_h(date('d/m/Y', strtotime($d))) ?></td><?php foreach ($stackSeries as $s): ?><td data-l="<?= wap_h($s['name']) ?>"><?= wap_n($s['values'][$i]) ?></td><?php endforeach; ?><td data-l="Total"><b><?= wap_n($tot) ?></b></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>
  </section>

  <section class="wap-card">
    <h2>Resultado por atendente no período</h2>
    <?php if (!$users): ?><div class="wap-empty">Nenhum atendimento aceito no período.</div><?php else: ?>
    <table class="wap-table cards">
      <thead><tr><th style="width:24%">Atendente</th><th>Atendimentos</th><th>Clientes</th><th>Finalizados</th><th>Mensagens enviadas</th><th>Espera média</th><th>Duração média</th><th>Transferências</th></tr></thead>
      <tbody>
      <?php foreach ($users as $i => $u): $id = (int)$u['uid']; $ci = array_search($id, $topUsers, true); ?>
        <tr>
          <td><span class="wap-dot" style="background:<?= $ci !== false ? $palette[$ci] : $otherColor ?>"></span><?= wap_h($userName($id)) ?></td>
          <td data-l="Atendimentos"><?= wap_n($u['total']) ?></td>
          <td data-l="Clientes"><?= wap_n($u['contacts']) ?></td>
          <td data-l="Finalizados"><?= wap_n($u['closed']) ?></td>
          <td data-l="Mensagens enviadas"><?= wap_n($u['msgs_out']) ?></td>
          <td data-l="Espera média"><?= wap_h(whatsapp_painel_duration(isset($u['avg_wait']) ? (float)$u['avg_wait'] : null)) ?></td>
          <td data-l="Duração média"><?= wap_h(whatsapp_painel_duration(isset($u['avg_duration']) ? (float)$u['avg_duration'] : null)) ?></td>
          <td data-l="Transferências"><?= wap_n($u['transfers']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="wap-note">"Pelo celular" são conversas respondidas direto no aparelho, sem usar a tela de Atendimento.</p>
    <?php endif; ?>
  </section>

  <div class="wap-two">
    <section class="wap-card">
      <h2>Assuntos mais solicitados</h2>
      <p class="wap-sub">Opções escolhidas no menu do robô e fluxos iniciados (<?= wap_n($data['topics_total']) ?> no período).</p>
      <?php if (!$data['topics']): ?><div class="wap-empty">Nenhum assunto registrado no período. Os assuntos passam a ser contados a partir desta versão.</div><?php else:
        $maxT = max(array_map(static fn($t) => (int)$t['total'], $data['topics'])); ?>
        <div class="wap-rows">
        <?php foreach ($data['topics'] as $t): $pct = $data['topics_total'] > 0 ? round((int)$t['total'] / $data['topics_total'] * 100) : 0; ?>
          <div class="wap-row"><span class="lb"><?= wap_h($t['label']) ?></span><span class="bar"><i style="width:<?= max(2, round((int)$t['total'] / max(1, $maxT) * 100)) ?>%"></i></span><span class="vl"><?= wap_n($t['total']) ?> <small><?= $pct ?>%</small></span></div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <section class="wap-card">
      <h2>Atendimentos por setor</h2>
      <p class="wap-sub">Setor para onde a conversa foi encaminhada, com a espera média.</p>
      <?php if (!$data['departments']): ?><div class="wap-empty">Nenhum atendimento no período.</div><?php else:
        $maxD = max(array_map(static fn($d) => (int)$d['total'], $data['departments'])); ?>
        <div class="wap-rows">
        <?php foreach ($data['departments'] as $d): ?>
          <div class="wap-row"><span class="lb"><?= wap_h($deptName((int)$d['dept'])) ?></span><span class="bar"><i style="width:<?= max(2, round((int)$d['total'] / max(1, $maxD) * 100)) ?>%"></i></span><span class="vl"><?= wap_n($d['total']) ?> <small>espera <?= wap_h(whatsapp_painel_duration(isset($d['avg_wait']) ? (float)$d['avg_wait'] : null)) ?></small></span></div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="wap-two">
    <section class="wap-card">
      <h2>Horários de pico</h2>
      <p class="wap-sub">Atendimentos iniciados em cada hora do dia.</p>
      <?php
        $hTips = [];
        foreach ($data['hours'] as $h => $v) $hTips[] = sprintf('%02dh às %02dh', $h, ($h + 1) % 24) . "\nAtendimentos: " . $v;
        echo wap_bars(array_map(static fn($h) => sprintf('%02dh', $h), array_keys($data['hours'])), [['name' => 'Atendimentos', 'color' => $palette[0], 'values' => array_values($data['hours'])]], false, null, $hTips, 180);
      ?>
    </section>
    <section class="wap-card">
      <h2>Por dia da semana</h2>
      <p class="wap-sub">Total de atendimentos em cada dia da semana no período.</p>
      <?php
        $wTips = [];
        foreach ($data['weekdays'] as $wd => $v) $wTips[] = $weekdayNames[$wd] . "\nAtendimentos: " . $v;
        echo wap_bars(array_values($weekdayNames), [['name' => 'Atendimentos', 'color' => $palette[0], 'values' => array_values($data['weekdays'])]], false, null, $wTips, 180);
      ?>
    </section>
  </div>
<?php endif; ?>
</div>
<div class="wap-tip" id="wapTip"></div>
<script>
(function(){
  const tip=document.getElementById('wapTip');if(!tip)return;
  document.addEventListener('mousemove',e=>{
    const t=e.target.closest&&e.target.closest('.wap-hit');
    if(!t||!t.dataset.tip){tip.style.display='none';return;}
    tip.textContent=t.dataset.tip;tip.style.display='block';
    const w=tip.offsetWidth,h=tip.offsetHeight;
    let x=e.clientX+14,y=e.clientY-h-10;
    if(x+w>window.innerWidth-8)x=e.clientX-w-14;
    if(y<8)y=e.clientY+16;
    tip.style.left=x+'px';tip.style.top=y+'px';
  });
  // Toque (celular): mostra o balão da coluna tocada.
  document.addEventListener('touchstart',e=>{
    const t=e.target.closest&&e.target.closest('.wap-hit');
    if(!t){tip.style.display='none';return;}
    const r=t.getBoundingClientRect();tip.textContent=t.dataset.tip;tip.style.display='block';
    tip.style.left=Math.max(8,Math.min(window.innerWidth-tip.offsetWidth-8,r.left))+'px';tip.style.top=Math.max(8,r.top-tip.offsetHeight-6)+'px';
  },{passive:true});
})();
</script>
<?php admin_footer(); ?>
