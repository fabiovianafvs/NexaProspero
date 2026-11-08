<?php
declare(strict_types=1);
/**
 * Nexa Próspero — Fluxo de Caixa (previsão).
 * Somente leitura, sempre na empresa de login (sem F11).
 *
 * Saldo inicial: digitado pelo usuário ou buscado automaticamente (saldo inicial
 * das contas ativas + movimentos ativos até hoje). Créditos = Contas a Receber em
 * aberto + parcelas de cartão a receber (líquidas). Débitos = Contas a Pagar em
 * aberto. Agrupamento diário, mensal ou anual; projeção em 30/60/90/120 dias.
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = (int)COMPANY_ID;
if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $cid) {
  access_render_denied('O Fluxo de Caixa não está disponível no contexto Multiempresa (F11). Entre diretamente na empresa.');
  exit;
}
if (!function_exists('access_has_permission') || !access_has_permission($pdo, $cid, 'relatorio_fluxo_caixa')) {
  access_render_denied('Seu perfil não possui autorização para o Fluxo de Caixa.');
  exit;
}

function rfc_money($v): string { return ($v < 0 ? '- ' : '') . 'R$ ' . number_format(abs((float)$v), 2, ',', '.'); }
function rfc_short($v): string {
  $v = (float)$v; $s = $v < 0 ? '-' : ''; $a = abs($v);
  if ($a >= 1000000) return $s . 'R$ ' . number_format($a / 1000000, 1, ',', '.') . ' mi';
  if ($a >= 1000) return $s . 'R$ ' . number_format($a / 1000, 1, ',', '.') . ' mil';
  return $s . 'R$ ' . number_format($a, 0, ',', '.');
}
function rfc_date(string $s): ?DateTimeImmutable {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s ? $d : null;
}
function rfc_rows(PDO $pdo, string $sql, array $args, string $label, array &$warnings): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('[Nexa Fluxo de Caixa] ' . $label . ': ' . $e->getMessage());
    $warnings[] = 'Não foi possível consultar ' . $label . '.';
    return [];
  }
}

$warnings = [];
$today = new DateTimeImmutable('today');
$realTime = (string)($_GET['buscar'] ?? '') === '1';
$ini = rfc_date((string)($_GET['ini'] ?? '')) ?? $today;
$fim = rfc_date((string)($_GET['fim'] ?? '')) ?? $today->modify('+119 days');
if ($realTime) {
  // Buscar: recomeça hoje, com o saldo de agora e os títulos em aberto de agora.
  $span = (int)$ini->diff($fim)->format('%r%a');
  $ini = $today;
  $fim = $today->modify('+' . max(0, $span) . ' days');
}
if ($fim < $ini) $fim = $ini;
if ((int)$ini->diff($fim)->format('%a') > 1830) $fim = $ini->modify('+1830 days');
$grp = in_array((string)($_GET['grp'] ?? ''), ['dia', 'mes', 'ano'], true) ? (string)$_GET['grp'] : 'dia';
if ($grp === 'dia' && (int)$ini->diff($fim)->format('%a') > 400) {
  $grp = 'mes';
  $warnings[] = 'Período longo: o fluxo foi agrupado por mês.';
}
$formSent = isset($_GET['f']);
$withOverdue = $formSent ? !empty($_GET['vencidos']) : true;
$manualRaw = trim((string)($_GET['saldo'] ?? ''));
$manual = (!$realTime && $manualRaw !== '');
$iniIso = $ini->format('Y-m-d');
$fimIso = $fim->format('Y-m-d');
$horizonEnd = $fim > $ini->modify('+120 days') ? $fim : $ini->modify('+120 days');
$horizonIso = $horizonEnd->format('Y-m-d');

// Saldo inicial.
$autoBalance = 0.0;
$autoBalanceParts = ['contas' => 0, 'inicial' => 0.0, 'movimentos' => 0.0];
foreach (rfc_rows($pdo, 'SELECT COUNT(*) qtd, COALESCE(SUM(initial_balance),0) total FROM finance_bank_accounts WHERE company_id=? AND active=1', [$cid], 'as contas', $warnings) as $r) {
  $autoBalanceParts['contas'] = (int)$r['qtd'];
  $autoBalanceParts['inicial'] = (float)$r['total'];
}
foreach (rfc_rows($pdo, "SELECT COALESCE(SUM(CASE WHEN m.type='CREDITO' THEN m.amount WHEN m.type='DEBITO' THEN -m.amount ELSE 0 END),0) total
    FROM finance_movements m INNER JOIN finance_bank_accounts b ON b.id = m.bank_account_id AND b.company_id = m.company_id AND b.active = 1
    WHERE m.company_id=? AND m.movement_date <= ? AND (m.status IS NULL OR m.status='' OR m.status='ATIVO')", [$cid, $today->format('Y-m-d')], 'os movimentos', $warnings) as $r) {
  $autoBalanceParts['movimentos'] = (float)$r['total'];
}
$autoBalance = round($autoBalanceParts['inicial'] + $autoBalanceParts['movimentos'], 2);
$startBalance = $manual ? (float)finance_money_in($manualRaw) : $autoBalance;

// Créditos e débitos em aberto, por data de vencimento.
$daily = []; // 'Y-m-d' => ['rec'=>, 'card'=>, 'pay'=>]
$overdue = ['rec' => 0.0, 'card' => 0.0, 'pay' => 0.0];
$add = static function (array $rows, string $key) use (&$daily, &$overdue, $iniIso): void {
  foreach ($rows as $r) {
    $d = substr((string)$r['d'], 0, 10);
    $v = (float)$r['v'];
    if ($d < $iniIso) { $overdue[$key] += $v; continue; }
    $daily[$d] = ($daily[$d] ?? ['rec' => 0.0, 'card' => 0.0, 'pay' => 0.0]);
    $daily[$d][$key] += $v;
  }
};
$add(rfc_rows($pdo, "SELECT due_date d, SUM(open_amount) v FROM finance_receivables
    WHERE company_id=? AND status IN ('ABERTO','PARCIAL') AND open_amount > 0.009 AND due_date IS NOT NULL AND due_date <= ? GROUP BY due_date", [$cid, $horizonIso], 'as contas a receber', $warnings), 'rec');
if (finance_table_exists($pdo, 'finance_card_installments')) {
  $add(rfc_rows($pdo, "SELECT due_date d, SUM(net_amount) v FROM finance_card_installments
      WHERE company_id=? AND status='ABERTO' AND due_date <= ? GROUP BY due_date", [$cid, $horizonIso], 'os cartões a receber', $warnings), 'card');
}
$add(rfc_rows($pdo, "SELECT due_date d, SUM(open_amount) v FROM finance_payables
    WHERE company_id=? AND status IN ('ABERTO','PARCIAL') AND open_amount > 0.009 AND due_date IS NOT NULL AND due_date <= ? GROUP BY due_date", [$cid, $horizonIso], 'as contas a pagar', $warnings), 'pay');
ksort($daily);

// Agrupamento do período.
$groupKey = static function (string $d) use ($grp): string { return $grp === 'mes' ? substr($d, 0, 7) : ($grp === 'ano' ? substr($d, 0, 4) : $d); };
$monthNames = ['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];
$groupLabel = static function (string $k) use ($grp, $monthNames): string {
  if ($grp === 'mes') return $monthNames[substr($k, 5, 2)] . '/' . substr($k, 0, 4);
  if ($grp === 'ano') return $k;
  return date('d/m/Y', strtotime($k));
};
$weekDays = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];

$rows = [];
$balance = $startBalance;
if ($withOverdue && ($overdue['rec'] + $overdue['card'] + $overdue['pay']) > 0.009) {
  $credits = $overdue['rec'] + $overdue['card'];
  $balance += $credits - $overdue['pay'];
  $rows[] = ['key' => '', 'label' => 'Vencidos antes de ' . $ini->format('d/m/Y'), 'sub' => 'em atraso', 'rec' => $overdue['rec'], 'card' => $overdue['card'], 'credits' => $credits, 'pay' => $overdue['pay'], 'result' => $credits - $overdue['pay'], 'balance' => $balance, 'overdue' => true];
}
$groups = [];
foreach ($daily as $d => $v) {
  if ($d > $fimIso) break;
  $k = $groupKey($d);
  $groups[$k] = $groups[$k] ?? ['rec' => 0.0, 'card' => 0.0, 'pay' => 0.0];
  foreach (['rec', 'card', 'pay'] as $f) $groups[$k][$f] += $v[$f];
}
$totals = ['rec' => 0.0, 'card' => 0.0, 'pay' => 0.0];
foreach ($groups as $k => $g) {
  $credits = $g['rec'] + $g['card'];
  $balance += $credits - $g['pay'];
  foreach ($totals as $f => $_) $totals[$f] += $g[$f];
  $rows[] = ['key' => $k, 'label' => $groupLabel($k), 'sub' => $grp === 'dia' ? $weekDays[(int)date('w', strtotime($k))] : '', 'rec' => $g['rec'], 'card' => $g['card'], 'credits' => $credits, 'pay' => $g['pay'], 'result' => $credits - $g['pay'], 'balance' => $balance, 'overdue' => false];
}
$endBalance = $balance;
$minRow = null;
foreach ($rows as $r) if ($minRow === null || $r['balance'] < $minRow['balance']) $minRow = $r;
$firstNegative = null;
foreach ($rows as $r) if ($r['balance'] < -0.009) { $firstNegative = $r; break; }

// Projeção em 30/60/90/120 dias a partir da data inicial.
$horizons = [];
foreach ([30, 60, 90, 120] as $days) {
  $limit = $ini->modify('+' . $days . ' days')->format('Y-m-d');
  $h = ['rec' => 0.0, 'card' => 0.0, 'pay' => 0.0];
  if ($withOverdue) foreach ($h as $f => $_) $h[$f] += $overdue[$f];
  foreach ($daily as $d => $v) { if ($d > $limit) break; foreach ($h as $f => $_) $h[$f] += $v[$f]; }
  $horizons[$days] = ['date' => $limit, 'credits' => $h['rec'] + $h['card'], 'pay' => $h['pay'], 'balance' => $startBalance + $h['rec'] + $h['card'] - $h['pay']];
}

// Gráfico (SVG montado no servidor).
$chartRows = array_values(array_filter($rows, static fn($r) => !$r['overdue']));
$cW = 1165.0; $pL = 96.0; $pR = 1140.0; $pT = 24.0; $pB = 286.0;
$vals = [0.0, $startBalance];
foreach ($chartRows as $r) { $vals[] = $r['credits']; $vals[] = -$r['pay']; $vals[] = $r['balance']; }
$vMax = max($vals); $vMin = min($vals);
if ($vMax - $vMin < 1) $vMax = $vMin + 1;
$pad = ($vMax - $vMin) * 0.06; $vMax += $pad; if ($vMin < 0) $vMin -= $pad;
$yOf = static fn(float $v): float => $pB - ($v - $vMin) / ($vMax - $vMin) * ($pB - $pT);
$n = max(1, count($chartRows));
$slot = ($pR - $pL) / $n;
$barW = max(2.0, min(26.0, $slot * 0.36));
$fmt = static fn(float $x): string => number_format($x, 1, '.', '');
$labelEvery = max(1, (int)ceil($n / 16));
$linePts = [];
foreach ($chartRows as $i => $r) $linePts[] = $fmt($pL + $slot * ($i + 0.5)) . ',' . $fmt($yOf($r['balance']));

$base = 'relatorio_fluxo_caixa.php';
finance_admin_start('Fluxo de Caixa', 'relatorio_fluxo_caixa');
?>
<style>
.rfc-filter{display:grid;grid-template-columns:150px 150px 210px 150px auto;gap:10px;align-items:end}
.rfc-filter label{display:grid;gap:4px;font-size:12px;font-weight:800;color:#334155}
.rfc-filter .rfc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.rfc-filter .rfc-check{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:700;color:#334155}
.rfc-filter .rfc-check input{width:auto;margin:0}
.rfc-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:10px}
.rfc-kpi{background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:8px 14px;min-width:0}
.rfc-kpi small{display:block;color:#64748b;font-weight:800;font-size:12px}
.rfc-kpi strong{display:block;font-size:clamp(16px,1.5vw,20px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#0f172a}
.rfc-kpi em{display:block;font-style:normal;font-size:11px;color:#64748b}
.rfc-kpi.pos strong{color:#1d4ed8}.rfc-kpi.neg strong{color:#b91c1c}.rfc-kpi.good strong{color:#047857}
.rfc-horizon .rfc-kpi{border-style:dashed}
.rfc-tabs{display:flex;gap:6px;margin:4px 0 10px}
.rfc-tab{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:7px 14px;font-weight:800;font-size:13px;color:#1e3a8a;cursor:pointer}
.rfc-tab.is-active{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
.rfc-panel[hidden]{display:none}
.rfc-chart svg{width:100%;height:auto;display:block}
.rfc-grid-line{stroke:#e2e8f0}.rfc-zero{stroke:#94a3b8;stroke-width:1.2}
.rfc-axis{fill:#64748b;font-size:12px;font-weight:700}
.rfc-xl{fill:#334155;font-size:11.5px;font-weight:700}
.rfc-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;font-weight:800;color:#334155;margin-bottom:4px}
.rfc-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.rfc-table td.rfc-neg{color:#b91c1c;font-weight:900}
.rfc-table td.rfc-pos{color:#1d4ed8}
.rfc-table tr.rfc-row-neg td{background:#fff5f5}
.rfc-table tr.rfc-row-overdue td{background:#fffbeb}
.rfc-table td small{color:#64748b;margin-left:6px;font-size:11px}
.rfc-note{font-size:11.5px;color:#64748b;margin:6px 0 0;line-height:1.45}
@media(max-width:1100px){.rfc-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.rfc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php finance_page_header('Fluxo de Caixa', ''); ?>
<?php foreach ($warnings as $w): ?><div class="msg err"><?= finance_h($w) ?></div><?php endforeach; ?>

<div class="finance-card">
  <form method="get" class="rfc-filter" action="<?= $base ?>">
    <input type="hidden" name="f" value="1">
    <label>De<input type="date" name="ini" value="<?= finance_h($iniIso) ?>"></label>
    <label>Até<input type="date" name="fim" value="<?= finance_h($fimIso) ?>"></label>
    <label>Saldo inicial<input name="saldo" inputmode="decimal" value="<?= $manual ? finance_h(finance_money_br($startBalance)) : '' ?>" placeholder="Automático: <?= finance_h(finance_money_br($autoBalance)) ?>"></label>
    <label>Ver por<select name="grp"><option value="dia" <?= $grp === 'dia' ? 'selected' : '' ?>>Diário</option><option value="mes" <?= $grp === 'mes' ? 'selected' : '' ?>>Mensal</option><option value="ano" <?= $grp === 'ano' ? 'selected' : '' ?>>Anual</option></select></label>
    <div class="rfc-actions">
      <label class="rfc-check"><input type="checkbox" name="vencidos" value="1" <?= $withOverdue ? 'checked' : '' ?>> Incluir vencidos</label>
      <button class="btn btn-primary" type="submit">Gerar fluxo</button>
      <button class="btn" type="submit" name="buscar" value="1" title="Recomeça hoje com o saldo atual das contas e os títulos em aberto de agora">Buscar (tempo real)</button>
    </div>
  </form>
  <p class="rfc-note">Saldo inicial em branco = automático: soma do saldo inicial das <?= (int)$autoBalanceParts['contas'] ?> conta(s)/caixa(s) ativos com os movimentos até hoje (<?= finance_h(finance_money_br($autoBalance)) ?>). Créditos = contas a receber em aberto + cartões a receber (valor líquido). Débitos = contas a pagar em aberto.</p>
</div>

<div class="rfc-kpis">
  <div class="rfc-kpi"><small>Saldo inicial · <?= $manual ? 'informado' : 'automático' ?></small><strong><?= rfc_money($startBalance) ?></strong><em>em <?= $ini->format('d/m/Y') ?></em></div>
  <div class="rfc-kpi pos"><small>Créditos previstos</small><strong><?= rfc_money($totals['rec'] + $totals['card'] + ($withOverdue ? $overdue['rec'] + $overdue['card'] : 0)) ?></strong><em>a receber <?= rfc_money($totals['rec'] + ($withOverdue ? $overdue['rec'] : 0)) ?> · cartões <?= rfc_money($totals['card'] + ($withOverdue ? $overdue['card'] : 0)) ?></em></div>
  <div class="rfc-kpi neg"><small>Débitos previstos</small><strong><?= rfc_money($totals['pay'] + ($withOverdue ? $overdue['pay'] : 0)) ?></strong><em>contas a pagar em aberto</em></div>
  <div class="rfc-kpi <?= $endBalance < 0 ? 'neg' : 'good' ?>"><small>Saldo final previsto</small><strong><?= rfc_money($endBalance) ?></strong><em>em <?= $fim->format('d/m/Y') ?><?= $minRow ? ' · menor saldo ' . rfc_money($minRow['balance']) . ' (' . finance_h($minRow['label']) . ')' : '' ?></em></div>
</div>
<div class="rfc-kpis rfc-horizon">
  <?php foreach ($horizons as $days => $h): ?>
    <div class="rfc-kpi <?= $h['balance'] < 0 ? 'neg' : 'good' ?>"><small>Daqui a <?= (int)$days ?> dias</small><strong><?= rfc_money($h['balance']) ?></strong><em><?= date('d/m/Y', strtotime($h['date'])) ?> · +<?= rfc_short($h['credits']) ?> / -<?= rfc_short($h['pay']) ?></em></div>
  <?php endforeach; ?>
</div>
<?php if ($firstNegative): ?><div class="msg err">Atenção: o saldo previsto fica negativo a partir de <strong><?= finance_h($firstNegative['label']) ?></strong> (<?= rfc_money($firstNegative['balance']) ?>).</div><?php endif; ?>

<div class="finance-card">
  <div class="rfc-tabs" role="tablist">
    <button type="button" class="rfc-tab is-active" data-tab="grid" role="tab">Fluxo</button>
    <button type="button" class="rfc-tab" data-tab="chart" role="tab">Gráfico</button>
  </div>
  <div class="rfc-panel" data-panel="grid">
    <?php require_once __DIR__ . '/../config/grid_preferences.php'; echo nexa_grid_assets(); ?>
    <table class="rfc-table"<?= nexa_grid_auto_attrs($pdo, 'auto_fluxo_caixa') ?>>
      <thead><tr><th><?= $grp === 'dia' ? 'Data' : ($grp === 'mes' ? 'Mês' : 'Ano') ?></th><th class="num">A receber</th><th class="num">Cartões a receber</th><th class="num">Créditos</th><th class="num">Débitos (a pagar)</th><th class="num">Resultado</th><th class="num">Saldo</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7">Nenhuma conta a receber ou a pagar em aberto no período.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['overdue'] ? 'rfc-row-overdue' : ($r['balance'] < -0.009 ? 'rfc-row-neg' : '') ?>">
          <td data-sort="<?= finance_h($r['key']) ?>"><strong><?= finance_h($r['label']) ?></strong><?php if ($r['sub'] !== ''): ?><small><?= finance_h($r['sub']) ?></small><?php endif; ?></td>
          <td class="num" data-sort="<?= number_format($r['rec'], 2, '.', '') ?>"><?= $r['rec'] > 0.009 ? rfc_money($r['rec']) : '—' ?></td>
          <td class="num" data-sort="<?= number_format($r['card'], 2, '.', '') ?>"><?= $r['card'] > 0.009 ? rfc_money($r['card']) : '—' ?></td>
          <td class="num rfc-pos" data-sort="<?= number_format($r['credits'], 2, '.', '') ?>"><?= rfc_money($r['credits']) ?></td>
          <td class="num rfc-neg" data-sort="<?= number_format($r['pay'], 2, '.', '') ?>"><?= $r['pay'] > 0.009 ? rfc_money(-$r['pay']) : '—' ?></td>
          <td class="num <?= $r['result'] < 0 ? 'rfc-neg' : 'rfc-pos' ?>" data-sort="<?= number_format($r['result'], 2, '.', '') ?>"><?= rfc_money($r['result']) ?></td>
          <td class="num <?= $r['balance'] < 0 ? 'rfc-neg' : '' ?>" data-sort="<?= number_format($r['balance'], 2, '.', '') ?>"><strong><?= rfc_money($r['balance']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="rfc-note">O saldo de cada linha já soma as linhas anteriores. Títulos sem data de vencimento não entram. Linhas em vermelho: saldo previsto negativo.</p>
  </div>

  <div class="rfc-panel" data-panel="chart" hidden>
    <div class="rfc-legend"><span><i style="background:#2563eb"></i>Receitas (créditos)</span><span><i style="background:#dc2626"></i>Despesas (débitos)</span><span><i style="background:#0f172a;height:3px;vertical-align:3px"></i>Saldo previsto</span></div>
    <?php if (!$chartRows): ?><div class="finance-empty">Sem créditos ou débitos no período.</div><?php else: ?>
    <div class="rfc-chart">
      <svg viewBox="0 0 <?= (int)$cW ?> 330" role="img" aria-label="Receitas, despesas e saldo previsto">
        <?php for ($s = 0; $s <= 4; $s++): $v = $vMin + ($vMax - $vMin) * $s / 4; $y = $yOf($v); ?>
          <line class="rfc-grid-line" x1="<?= $pL ?>" x2="<?= $pR ?>" y1="<?= $fmt($y) ?>" y2="<?= $fmt($y) ?>"/>
          <text class="rfc-axis" text-anchor="end" x="<?= $pL - 10 ?>" y="<?= $fmt($y + 4) ?>"><?= finance_h(rfc_short($v)) ?></text>
        <?php endfor; ?>
        <?php if ($vMin < 0): ?><line class="rfc-zero" x1="<?= $pL ?>" x2="<?= $pR ?>" y1="<?= $fmt($yOf(0)) ?>" y2="<?= $fmt($yOf(0)) ?>"/><?php endif; ?>
        <?php foreach ($chartRows as $i => $r): $cx = $pL + $slot * ($i + 0.5); $y0 = $yOf(0); $yc = $yOf($r['credits']); $yd = $yOf(-$r['pay']); ?>
          <g><title><?= finance_h($r['label'] . ' · Receitas: ' . rfc_money($r['credits']) . ' · Despesas: ' . rfc_money($r['pay']) . ' · Saldo: ' . rfc_money($r['balance'])) ?></title>
            <?php if ($r['credits'] > 0.009): ?><rect x="<?= $fmt($cx - $barW) ?>" y="<?= $fmt($yc) ?>" width="<?= $fmt($barW) ?>" height="<?= $fmt(max(0.5, $y0 - $yc)) ?>" fill="#2563eb" rx="2"/><?php endif; ?>
            <?php if ($r['pay'] > 0.009): ?><rect x="<?= $fmt($cx) ?>" y="<?= $fmt($y0) ?>" width="<?= $fmt($barW) ?>" height="<?= $fmt(max(0.5, $yd - $y0)) ?>" fill="#dc2626" rx="2"/><?php endif; ?>
            <rect x="<?= $fmt($cx - $slot / 2) ?>" y="<?= $pT ?>" width="<?= $fmt($slot) ?>" height="<?= $fmt($pB - $pT) ?>" fill="transparent"/>
          </g>
          <?php if ($i % $labelEvery === 0): ?><text class="rfc-xl" text-anchor="middle" x="<?= $fmt($cx) ?>" y="306"><?= finance_h($grp === 'dia' ? substr($r['label'], 0, 5) : $r['label']) ?></text><?php endif; ?>
        <?php endforeach; ?>
        <?php if (count($linePts) > 1): ?><polyline points="<?= finance_h(implode(' ', $linePts)) ?>" fill="none" stroke="#0f172a" stroke-width="2.4" stroke-linejoin="round"/><?php endif; ?>
        <?php foreach ($chartRows as $i => $r): ?><circle cx="<?= $fmt($pL + $slot * ($i + 0.5)) ?>" cy="<?= $fmt($yOf($r['balance'])) ?>" r="<?= $n > 60 ? 1.8 : 3.5 ?>" fill="<?= $r['balance'] < 0 ? '#b91c1c' : '#0f172a' ?>"><title><?= finance_h($r['label'] . ' · Saldo: ' . rfc_money($r['balance'])) ?></title></circle><?php endforeach; ?>
      </svg>
    </div>
    <p class="rfc-note">Receitas para cima, despesas para baixo; a linha é o saldo previsto ao fim de cada <?= $grp === 'dia' ? 'dia' : ($grp === 'mes' ? 'mês' : 'ano') ?><?= $withOverdue && ($overdue['rec'] + $overdue['card'] + $overdue['pay']) > 0.009 ? ' (já com os vencidos)' : '' ?>. Passe o mouse para ver os valores.</p>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  var tabs=document.querySelectorAll('.rfc-tab'),panels=document.querySelectorAll('.rfc-panel');
  function show(name){tabs.forEach(function(t){t.classList.toggle('is-active',t.dataset.tab===name)});panels.forEach(function(p){p.hidden=p.dataset.panel!==name});try{history.replaceState(null,'',name==='chart'?'#grafico':location.pathname+location.search)}catch(e){}}
  tabs.forEach(function(t){t.addEventListener('click',function(){show(t.dataset.tab)})});
  if(location.hash==='#grafico')show('chart');
})();
</script>
<?php finance_admin_end(); ?>
