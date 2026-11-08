<?php
declare(strict_types=1);
/**
 * Nexa Próspero — Análise de inadimplência (Contas a Receber).
 * Somente leitura, sempre na empresa de login (sem F11).
 *
 * - Gráfico anual por mês de VENCIMENTO: recebido em dia, recebido com atraso,
 *   em aberto vencido (inadimplente) e a vencer.
 * - Indicadores do ano, faixas de atraso do que está vencido hoje, clientes com
 *   maior valor em atraso e clientes mais pontuais (últimos 12 meses).
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = (int)COMPANY_ID;
if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $cid) {
  access_render_denied('A Análise de inadimplência não está disponível no contexto Multiempresa (F11). Entre diretamente na empresa.');
  exit;
}
if (!function_exists('access_has_permission') || !access_has_permission($pdo, $cid, 'relatorio_inadimplencia')) {
  access_render_denied('Seu perfil não possui autorização para a Análise de inadimplência.');
  exit;
}

function rin_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function rin_short($v): string {
  $v = (float)$v;
  if (abs($v) >= 1000000) return 'R$ ' . number_format($v / 1000000, 1, ',', '.') . ' mi';
  if (abs($v) >= 1000) return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
  return 'R$ ' . number_format($v, 0, ',', '.');
}
function rin_pct($v): string { return number_format((float)$v, 1, ',', '.') . '%'; }
function rin_num($v, int $d = 0): string { return number_format((float)$v, $d, ',', '.'); }
function rin_rows(PDO $pdo, string $sql, array $args, string $label, array &$warnings): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('[Nexa Inadimplência] ' . $label . ': ' . $e->getMessage());
    $warnings[] = 'Não foi possível calcular ' . $label . '.';
    return [];
  }
}

$today = new DateTimeImmutable('today');
$currentYear = (int)$today->format('Y');
$year = (int)($_GET['ano'] ?? $currentYear);
if ($year < 2000 || $year > $currentYear + 1) $year = $currentYear;
$yearFrom = sprintf('%04d-01-01', $year);
$yearTo = sprintf('%04d-12-31', $year);
$todayIso = $today->format('Y-m-d');
$warnings = [];

$settleActive = finance_column_exists($pdo, 'finance_settlements', 'status') ? " AND COALESCE(s.status,'ATIVO')='ATIVO'" : '';
$settleActiveNoAlias = str_replace('s.status', 'status', $settleActive);

// 1) Títulos por mês de vencimento.
$monthTitles = [];
foreach (rin_rows($pdo, "SELECT MONTH(r.due_date) m, COUNT(*) qtd, SUM(r.amount) total, SUM(r.open_amount) aberto,
    SUM(CASE WHEN r.open_amount > 0.009 AND r.due_date < ? THEN r.open_amount ELSE 0 END) vencido_aberto,
    SUM(CASE WHEN r.open_amount > 0.009 AND r.due_date >= ? THEN r.open_amount ELSE 0 END) a_vencer,
    COUNT(DISTINCT CASE WHEN r.open_amount > 0.009 AND r.due_date < ? THEN r.person_id END) clientes_inad,
    SUM(CASE WHEN r.due_date < ? THEN r.amount ELSE 0 END) total_vencido
  FROM finance_receivables r
  WHERE r.company_id = ? AND r.status <> 'CANCELADO' AND r.due_date BETWEEN ? AND ?
  GROUP BY MONTH(r.due_date)", [$todayIso, $todayIso, $todayIso, $todayIso, $cid, $yearFrom, $yearTo], 'os títulos do ano', $warnings) as $r) {
  $monthTitles[(int)$r['m']] = $r;
}

// 2) Recebimentos desses títulos (em dia x com atraso), pela data de vencimento.
$monthPaid = [];
foreach (rin_rows($pdo, "SELECT MONTH(r.due_date) m, SUM(s.amount) recebido,
    SUM(CASE WHEN s.settlement_date <= r.due_date THEN s.amount ELSE 0 END) em_dia,
    SUM(CASE WHEN s.settlement_date > r.due_date THEN s.amount ELSE 0 END) atrasado,
    SUM(CASE WHEN s.settlement_date > r.due_date THEN s.amount * DATEDIFF(s.settlement_date, r.due_date) ELSE 0 END) atraso_ponderado
  FROM finance_settlements s
  INNER JOIN finance_receivables r ON r.id = s.origin_id AND r.company_id = s.company_id
  WHERE s.company_id = ? AND s.origin_type = 'RECEBER'{$settleActive} AND r.status <> 'CANCELADO' AND r.due_date BETWEEN ? AND ?
  GROUP BY MONTH(r.due_date)", [$cid, $yearFrom, $yearTo], 'os recebimentos do ano', $warnings) as $r) {
  $monthPaid[(int)$r['m']] = $r;
}

$monthNames = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
$months = [];
$yearTotals = ['total'=>0.0,'total_vencido'=>0.0,'recebido'=>0.0,'em_dia'=>0.0,'atrasado'=>0.0,'vencido_aberto'=>0.0,'a_vencer'=>0.0,'atraso_ponderado'=>0.0,'qtd'=>0];
foreach ($monthNames as $m => $label) {
  $t = $monthTitles[$m] ?? [];
  $p = $monthPaid[$m] ?? [];
  $row = [
    'label' => $label,
    'qtd' => (int)($t['qtd'] ?? 0),
    'total' => (float)($t['total'] ?? 0),
    'total_vencido' => (float)($t['total_vencido'] ?? 0),
    'vencido_aberto' => (float)($t['vencido_aberto'] ?? 0),
    'a_vencer' => (float)($t['a_vencer'] ?? 0),
    'clientes_inad' => (int)($t['clientes_inad'] ?? 0),
    'recebido' => (float)($p['recebido'] ?? 0),
    'em_dia' => (float)($p['em_dia'] ?? 0),
    'atrasado' => (float)($p['atrasado'] ?? 0),
    'atraso_ponderado' => (float)($p['atraso_ponderado'] ?? 0),
  ];
  $row['inad_pct'] = $row['total_vencido'] > 0.009 ? $row['vencido_aberto'] / $row['total_vencido'] * 100 : null;
  $row['media_atraso'] = $row['atrasado'] > 0.009 ? $row['atraso_ponderado'] / $row['atrasado'] : null;
  $months[$m] = $row;
  foreach ($yearTotals as $k => $_) $yearTotals[$k] += $row[$k];
}
$yearInadPct = $yearTotals['total_vencido'] > 0.009 ? $yearTotals['vencido_aberto'] / $yearTotals['total_vencido'] * 100 : 0.0;
$yearOnTimePct = $yearTotals['recebido'] > 0.009 ? $yearTotals['em_dia'] / $yearTotals['recebido'] * 100 : 0.0;
$yearAvgDelay = $yearTotals['atrasado'] > 0.009 ? $yearTotals['atraso_ponderado'] / $yearTotals['atrasado'] : 0.0;

// 3) Vencido em aberto HOJE (todos os anos), por faixa de atraso.
$agingLabels = [1=>'1 a 30 dias',2=>'31 a 60 dias',3=>'61 a 90 dias',4=>'91 a 180 dias',5=>'Mais de 180 dias'];
$aging = array_fill_keys(array_keys($agingLabels), ['qtd'=>0,'valor'=>0.0,'clientes'=>0]);
$agingTotal = ['qtd'=>0,'valor'=>0.0,'clientes'=>0];
foreach (rin_rows($pdo, "SELECT CASE WHEN DATEDIFF(?, due_date) <= 30 THEN 1 WHEN DATEDIFF(?, due_date) <= 60 THEN 2 WHEN DATEDIFF(?, due_date) <= 90 THEN 3 WHEN DATEDIFF(?, due_date) <= 180 THEN 4 ELSE 5 END faixa,
    COUNT(*) qtd, SUM(open_amount) valor, COUNT(DISTINCT person_id) clientes
  FROM finance_receivables
  WHERE company_id = ? AND status IN ('ABERTO','PARCIAL') AND open_amount > 0.009 AND due_date < ?
  GROUP BY faixa", [$todayIso, $todayIso, $todayIso, $todayIso, $cid, $todayIso], 'as faixas de atraso', $warnings) as $r) {
  $aging[(int)$r['faixa']] = ['qtd'=>(int)$r['qtd'], 'valor'=>(float)$r['valor'], 'clientes'=>(int)$r['clientes']];
}
foreach (rin_rows($pdo, "SELECT COUNT(*) qtd, COALESCE(SUM(open_amount),0) valor, COUNT(DISTINCT person_id) clientes
  FROM finance_receivables WHERE company_id = ? AND status IN ('ABERTO','PARCIAL') AND open_amount > 0.009 AND due_date < ?", [$cid, $todayIso], 'o total vencido', $warnings) as $r) {
  $agingTotal = ['qtd'=>(int)$r['qtd'], 'valor'=>(float)$r['valor'], 'clientes'=>(int)$r['clientes']];
}

// 4) Pontualidade por cliente: títulos quitados nos últimos 12 meses.
$since12 = $today->modify('-12 months')->format('Y-m-d');
$punctual = [];
foreach (rin_rows($pdo, "SELECT r.person_id, COUNT(*) titulos,
    SUM(CASE WHEN t.last_date <= r.due_date THEN 1 ELSE 0 END) em_dia,
    AVG(CASE WHEN t.last_date > r.due_date THEN DATEDIFF(t.last_date, r.due_date) END) media_atraso,
    SUM(t.valor) recebido
  FROM (SELECT origin_id, MAX(settlement_date) last_date, SUM(amount) valor FROM finance_settlements
        WHERE company_id = ? AND origin_type = 'RECEBER'{$settleActiveNoAlias} GROUP BY origin_id) t
  INNER JOIN finance_receivables r ON r.id = t.origin_id AND r.company_id = ?
  WHERE r.status = 'PAGO' AND t.last_date >= ?
  GROUP BY r.person_id", [$cid, $cid, $since12], 'a pontualidade dos clientes', $warnings) as $r) {
  $punctual[(int)$r['person_id']] = $r;
}
$p12 = ['titulos'=>0,'em_dia'=>0];
foreach ($punctual as $r) { $p12['titulos'] += (int)$r['titulos']; $p12['em_dia'] += (int)$r['em_dia']; }
$p12Pct = $p12['titulos'] > 0 ? $p12['em_dia'] / $p12['titulos'] * 100 : 0.0;

// 5) Clientes com maior valor vencido em aberto.
$debtors = rin_rows($pdo, "SELECT r.person_id, COALESCE(p.name, 'Sem cliente') name, COUNT(*) titulos, SUM(r.open_amount) aberto,
    MAX(DATEDIFF(?, r.due_date)) maior_atraso, MIN(r.due_date) mais_antigo
  FROM finance_receivables r
  LEFT JOIN finance_people p ON p.id = r.person_id AND p.company_id = r.company_id
  WHERE r.company_id = ? AND r.status IN ('ABERTO','PARCIAL') AND r.open_amount > 0.009 AND r.due_date < ?
  GROUP BY r.person_id, p.name ORDER BY aberto DESC LIMIT 15", [$todayIso, $cid, $todayIso], 'os clientes inadimplentes', $warnings);

// 6) Clientes mais pontuais (mín. 3 títulos quitados em 12 meses).
$best = [];
foreach ($punctual as $pid => $r) {
  if ($pid <= 0 || (int)$r['titulos'] < 3) continue;
  $best[] = ['person_id'=>$pid, 'titulos'=>(int)$r['titulos'], 'em_dia'=>(int)$r['em_dia'], 'pct'=>(int)$r['em_dia'] / max(1, (int)$r['titulos']) * 100, 'recebido'=>(float)$r['recebido'], 'media_atraso'=>$r['media_atraso'] !== null ? (float)$r['media_atraso'] : null];
}
usort($best, static fn($a, $b) => [$b['pct'], $b['titulos'], $b['recebido']] <=> [$a['pct'], $a['titulos'], $a['recebido']]);
$best = array_slice($best, 0, 10);
$bestNames = [];
if ($best) {
  $ids = array_column($best, 'person_id');
  foreach (rin_rows($pdo, 'SELECT id, name FROM finance_people WHERE company_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$cid], $ids), 'os nomes dos clientes', $warnings) as $r) {
    $bestNames[(int)$r['id']] = (string)$r['name'];
  }
}

// Gráfico (SVG montado no servidor, sem bibliotecas externas).
$plotL = 92.0; $plotR = 1140.0; $plotT = 34.0; $plotB = 292.0;
$maxBar = 1.0;
foreach ($months as $m) $maxBar = max($maxBar, $m['em_dia'] + $m['atrasado'] + $m['vencido_aberto'] + $m['a_vencer']);
$slot = ($plotR - $plotL) / 12;
$barW = min(46.0, $slot * 0.56);
$scale = static fn(float $v): float => $v / $maxBar * ($plotB - $plotT);
$fmt = static fn(float $n): string => number_format($n, 1, '.', '');

finance_admin_start('Análise de inadimplência', 'relatorio_inadimplencia');
?>
<style>
.rin-filter{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.rin-filter select{min-width:110px}
.rin-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}
.rin-kpi{background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:8px 14px;min-width:0}
.rin-kpi small{display:block;color:#64748b;font-weight:800;font-size:12px}
.rin-kpi strong{display:block;font-size:clamp(16px,1.5vw,20px);color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rin-kpi.bad strong{color:#b91c1c}.rin-kpi.good strong{color:#047857}
.rin-kpi em{display:block;font-style:normal;font-size:11px;color:#64748b;margin-top:1px}
.rin-chart{width:100%;overflow:hidden}
.rin-chart svg{width:100%;height:auto;display:block}
.rin-grid-line{stroke:#e2e8f0;stroke-width:1}
.rin-axis{fill:#64748b;font-size:12px;font-weight:700}
.rin-month{fill:#334155;font-size:13px;font-weight:800}
.rin-pct{font-size:12px;font-weight:900}
.rin-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;font-weight:800;color:#334155;margin:4px 0 6px}
.rin-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.rin-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.rin-two>.finance-card{min-width:0}.rin-table .btn{min-height:0!important;height:auto!important;padding:4px 10px!important;font-size:11.5px!important;line-height:1.3}
.rin-table{width:100%;min-width:0!important;border-collapse:collapse;font-size:12.5px}
.rin-table th{background:#eff5ff;color:#102856;text-align:left;padding:7px 8px;border-bottom:1px solid #cbd5e1;font-weight:800;white-space:nowrap}
.rin-table td{padding:6px 8px;border-bottom:1px solid #e5edf6;white-space:nowrap}
.rin-table td.num,.rin-table th.num{text-align:right}
.rin-table tr.total td{font-weight:900;background:#f8fbff}
.rin-bar{height:8px;border-radius:99px;background:#f1f5f9;overflow:hidden;min-width:50px}.rin-bar span{display:block;height:100%;border-radius:99px;background:#dc2626}
.rin-bar.good span{background:#16a34a}
.rin-red{color:#b91c1c;font-weight:800}.rin-amber{color:#b45309;font-weight:800}.rin-green{color:#047857;font-weight:800}.rin-muted{color:#64748b}
.rin-scroll{overflow:auto}
.rin-note{font-size:11.5px;color:#64748b;margin:6px 0 0;line-height:1.45}
@media(max-width:1200px){.rin-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.rin-two{grid-template-columns:1fr}}
@media(max-width:680px){.rin-kpis{grid-template-columns:1fr 1fr}}
</style>
<?php finance_page_header('Análise de inadimplência', ''); ?>
<?php foreach ($warnings as $w): ?><div class="msg err"><?= finance_h($w) ?></div><?php endforeach; ?>

<div class="finance-card">
  <div class="finance-card-head" style="align-items:center">
    <div><h2 class="finance-subtitle">Contas a receber de <?= (int)$year ?></h2><div class="finance-muted">Por mês de vencimento · situação em <?= $today->format('d/m/Y') ?></div></div>
    <form method="get" class="rin-filter">
      <label>Ano <select name="ano" onchange="this.form.submit()"><?php for ($y = $currentYear + 1; $y >= $currentYear - 6; $y--): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label>
      <noscript><button class="btn">Ver</button></noscript>
    </form>
  </div>

  <div class="rin-kpis">
    <div class="rin-kpi"><small>Vencido no ano</small><strong><?= rin_money($yearTotals['total_vencido']) ?></strong><em>títulos com vencimento até hoje</em></div>
    <div class="rin-kpi good"><small>Recebido</small><strong><?= rin_money($yearTotals['recebido']) ?></strong><em><?= rin_pct($yearOnTimePct) ?> recebido em dia</em></div>
    <div class="rin-kpi bad"><small>Em aberto vencido</small><strong><?= rin_money($yearTotals['vencido_aberto']) ?></strong><em>inadimplência do ano</em></div>
    <div class="rin-kpi bad"><small>Índice de inadimplência</small><strong><?= rin_pct($yearInadPct) ?></strong><em>em aberto ÷ vencido</em></div>
    <div class="rin-kpi"><small>Atraso médio</small><strong><?= rin_num($yearAvgDelay, 1) ?> dias</strong><em>nos recebimentos com atraso</em></div>
    <div class="rin-kpi"><small>A vencer</small><strong><?= rin_money($yearTotals['a_vencer']) ?></strong><em>ainda dentro do prazo</em></div>
  </div>

  <div class="rin-legend">
    <span><i style="background:#2563eb"></i>Recebido em dia</span>
    <span><i style="background:#93c5fd"></i>Recebido com atraso</span>
    <span><i style="background:#dc2626"></i>Em aberto vencido</span>
    <span><i style="background:#cbd5e1"></i>A vencer</span>
    <span style="color:#b91c1c">% = índice de inadimplência do mês</span>
  </div>
  <div class="rin-chart">
    <svg viewBox="0 0 1165 340" role="img" aria-label="Contas a receber de <?= (int)$year ?> por mês: recebido em dia, com atraso, em aberto vencido e a vencer">
      <?php for ($step = 0; $step <= 4; $step++): $y = $plotB - ($plotB - $plotT) * $step / 4; ?>
        <line class="rin-grid-line" x1="<?= $plotL ?>" x2="<?= $plotR ?>" y1="<?= $fmt($y) ?>" y2="<?= $fmt($y) ?>"/>
        <text class="rin-axis" text-anchor="end" x="<?= $plotL - 10 ?>" y="<?= $fmt($y + 4) ?>"><?= finance_h(rin_short($maxBar * $step / 4)) ?></text>
      <?php endfor; ?>
      <?php foreach ($months as $mi => $m):
        $cx = $plotL + $slot * ($mi - 0.5);
        $x = $cx - $barW / 2;
        $yCur = $plotB;
        $parts = [['em_dia','#2563eb','Recebido em dia'],['atrasado','#93c5fd','Recebido com atraso'],['vencido_aberto','#dc2626','Em aberto vencido'],['a_vencer','#cbd5e1','A vencer']];
        $tip = $m['label'] . '/' . $year . ' · Recebido em dia: ' . rin_money($m['em_dia']) . ' · Com atraso: ' . rin_money($m['atrasado']) . ' · Em aberto vencido: ' . rin_money($m['vencido_aberto']) . ' · A vencer: ' . rin_money($m['a_vencer']);
      ?>
        <g><title><?= finance_h($tip) ?></title>
        <?php foreach ($parts as [$key, $color, $name]): $h = $scale((float)$m[$key]); if ($h < 0.5) continue; $yCur -= $h; ?>
          <rect x="<?= $fmt($x) ?>" y="<?= $fmt($yCur) ?>" width="<?= $fmt($barW) ?>" height="<?= $fmt($h) ?>" fill="<?= $color ?>" rx="2"/>
        <?php endforeach; ?>
        <rect x="<?= $fmt($cx - $slot / 2) ?>" y="<?= $plotT ?>" width="<?= $fmt($slot) ?>" height="<?= $fmt($plotB - $plotT) ?>" fill="transparent"/>
        </g>
        <?php if ($m['inad_pct'] !== null): ?>
          <text class="rin-pct" text-anchor="middle" x="<?= $fmt($cx) ?>" y="<?= $fmt(max($plotT - 8, $yCur - 7)) ?>" fill="<?= $m['inad_pct'] > 0.05 ? '#b91c1c' : '#047857' ?>"><?= finance_h(rin_pct($m['inad_pct'])) ?></text>
        <?php endif; ?>
        <text class="rin-month" text-anchor="middle" x="<?= $fmt($cx) ?>" y="316"><?= finance_h($m['label']) ?></text>
      <?php endforeach; ?>
    </svg>
  </div>
  <p class="rin-note">Cada barra mostra os títulos com vencimento no mês. Passe o mouse sobre a barra para ver os valores. Títulos cancelados não entram.</p>

  <details style="margin-top:8px"><summary style="cursor:pointer;font-weight:800;color:#1e3a8a">Ver valores mês a mês</summary>
    <div class="rin-scroll" style="margin-top:8px">
      <table class="rin-table">
        <thead><tr><th>Mês</th><th class="num">Títulos</th><th class="num">Total</th><th class="num">Recebido em dia</th><th class="num">Recebido c/ atraso</th><th class="num">Atraso médio</th><th class="num">Em aberto vencido</th><th class="num">A vencer</th><th class="num">Inadimplência</th><th class="num">Clientes em atraso</th></tr></thead>
        <tbody>
        <?php foreach ($months as $m): ?>
          <tr>
            <td><strong><?= finance_h($m['label']) ?>/<?= (int)$year ?></strong></td>
            <td class="num"><?= rin_num($m['qtd']) ?></td>
            <td class="num"><?= rin_money($m['total']) ?></td>
            <td class="num"><?= rin_money($m['em_dia']) ?></td>
            <td class="num"><?= rin_money($m['atrasado']) ?></td>
            <td class="num"><?= $m['media_atraso'] !== null ? rin_num($m['media_atraso'], 1) . ' dias' : '—' ?></td>
            <td class="num <?= $m['vencido_aberto'] > 0.009 ? 'rin-red' : '' ?>"><?= rin_money($m['vencido_aberto']) ?></td>
            <td class="num"><?= rin_money($m['a_vencer']) ?></td>
            <td class="num"><?= $m['inad_pct'] !== null ? rin_pct($m['inad_pct']) : '—' ?></td>
            <td class="num"><?= rin_num($m['clientes_inad']) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="total"><td>Total</td><td class="num"><?= rin_num($yearTotals['qtd']) ?></td><td class="num"><?= rin_money($yearTotals['total']) ?></td><td class="num"><?= rin_money($yearTotals['em_dia']) ?></td><td class="num"><?= rin_money($yearTotals['atrasado']) ?></td><td class="num"><?= rin_num($yearAvgDelay, 1) ?> dias</td><td class="num rin-red"><?= rin_money($yearTotals['vencido_aberto']) ?></td><td class="num"><?= rin_money($yearTotals['a_vencer']) ?></td><td class="num"><?= rin_pct($yearInadPct) ?></td><td class="num">—</td></tr>
        </tbody>
      </table>
    </div>
  </details>
</div>

<div class="rin-two">
  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Vencido em aberto hoje, por tempo de atraso</h2><div class="finance-muted">Todos os anos · <?= rin_num($agingTotal['clientes']) ?> cliente(s) · <?= rin_num($agingTotal['qtd']) ?> título(s)</div></div><strong class="rin-red"><?= rin_money($agingTotal['valor']) ?></strong></div>
    <div class="rin-scroll"><table class="rin-table">
      <thead><tr><th>Faixa de atraso</th><th class="num">Títulos</th><th class="num">Clientes</th><th class="num">Valor</th><th style="width:22%">Participação</th></tr></thead>
      <tbody>
      <?php foreach ($agingLabels as $k => $label): $a = $aging[$k]; $share = $agingTotal['valor'] > 0.009 ? $a['valor'] / $agingTotal['valor'] * 100 : 0; ?>
        <tr><td><?= finance_h($label) ?></td><td class="num"><?= rin_num($a['qtd']) ?></td><td class="num"><?= rin_num($a['clientes']) ?></td><td class="num"><?= rin_money($a['valor']) ?></td><td><div class="rin-bar" title="<?= rin_pct($share) ?>"><span style="width:<?= $fmt(max(0, min(100, $share))) ?>%"></span></div></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="rin-note">Quanto mais tempo em atraso, menor a chance de recebimento: priorize a cobrança das faixas mais antigas.</p>
  </div>

  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Pontualidade dos clientes</h2><div class="finance-muted">Títulos quitados nos últimos 12 meses</div></div><strong class="<?= $p12Pct >= 80 ? 'rin-green' : 'rin-red' ?>"><?= rin_pct($p12Pct) ?> em dia</strong></div>
    <?php if (!$best): ?><div class="finance-empty">Ainda não há clientes com 3 ou mais títulos quitados nos últimos 12 meses.</div><?php else: ?>
    <div class="rin-scroll"><table class="rin-table">
      <thead><tr><th>Clientes mais pontuais</th><th class="num">Títulos</th><th class="num">Em dia</th><th class="num">Atraso médio*</th><th class="num">Recebido</th></tr></thead>
      <tbody>
      <?php foreach ($best as $b): ?>
        <tr><td title="<?= finance_h($bestNames[$b['person_id']] ?? '') ?>"><?= finance_h(mb_strimwidth($bestNames[$b['person_id']] ?? ('Cliente #' . $b['person_id']), 0, 38, '…')) ?></td><td class="num"><?= rin_num($b['titulos']) ?></td><td class="num <?= $b['pct'] >= 80 ? 'rin-green' : 'rin-amber' ?>"><?= rin_pct($b['pct']) ?></td><td class="num"><?= $b['media_atraso'] !== null ? rin_num($b['media_atraso'], 1) . ' dias' : '—' ?></td><td class="num"><?= rin_money($b['recebido']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="rin-note">* Atraso médio só dos títulos pagos depois do vencimento.</p>
    <?php endif; ?>
  </div>
</div>

<div class="finance-card">
  <div class="finance-card-head"><div><h2 class="finance-subtitle">Clientes com maior valor em atraso</h2><div class="finance-muted">Vencido em aberto hoje · os 15 maiores</div></div></div>
  <?php if (!$debtors): ?><div class="finance-empty">Nenhum cliente com título vencido em aberto. 🎉</div><?php else: ?>
  <div class="rin-scroll">
    <table class="rin-table">
      <thead><tr><th>Cliente</th><th class="num">Títulos vencidos</th><th class="num">Valor em atraso</th><th>Vencimento mais antigo</th><th class="num">Maior atraso</th><th class="num">Pontualidade (12 meses)</th><th class="num">Atraso médio (12 meses)*</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($debtors as $d): $pid = (int)$d['person_id']; $pp = $punctual[$pid] ?? null; $ppPct = $pp && (int)$pp['titulos'] > 0 ? (int)$pp['em_dia'] / (int)$pp['titulos'] * 100 : null; ?>
        <tr>
          <td title="<?= finance_h($d['name']) ?>"><strong><?= finance_h(mb_strimwidth((string)$d['name'], 0, 44, '…')) ?></strong></td>
          <td class="num"><?= rin_num($d['titulos']) ?></td>
          <td class="num rin-red"><?= rin_money($d['aberto']) ?></td>
          <td><?= finance_date_br($d['mais_antigo']) ?></td>
          <td class="num"><?= rin_num($d['maior_atraso']) ?> dias</td>
          <td class="num"><?= $ppPct !== null ? '<span class="' . ($ppPct >= 80 ? 'rin-green' : 'rin-red') . '">' . rin_pct($ppPct) . '</span> <span class="rin-muted">(' . (int)$pp['titulos'] . ')</span>' : '<span class="rin-muted">—</span>' ?></td>
          <td class="num"><?= $pp && $pp['media_atraso'] !== null ? rin_num($pp['media_atraso'], 1) . ' dias' : '—' ?></td>
          <td><?php if ($pid > 0): ?><a class="btn btn-sm" href="financeiro_contas_receber.php?filtrar=1&amp;status=ABERTO&amp;person_id=<?= $pid ?>&amp;due_fim=<?= finance_h($today->modify('-1 day')->format('Y-m-d')) ?>">Ver títulos</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="rin-note">Pontualidade = títulos quitados até o vencimento ÷ títulos quitados nos últimos 12 meses (entre parênteses, a quantidade). * Atraso médio só dos títulos pagos depois do vencimento.</p>
  <?php endif; ?>
</div>
<?php finance_admin_end(); ?>
