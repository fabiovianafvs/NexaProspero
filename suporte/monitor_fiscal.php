<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';

$user = require_support_user($pdo);

function smf_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}

function smf_column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}

function smf_company_column(PDO $pdo, string $column): bool {
  return smf_column_exists($pdo, 'companies', $column);
}

function smf_add_counts(array &$rowsById, array $counts, string $key): void {
  foreach ($counts as $row) {
    $cid = (int)($row['company_id'] ?? 0);
    if ($cid > 0 && isset($rowsById[$cid])) {
      $rowsById[$cid][$key] = (int)($row['qty'] ?? 0);
      $rowsById[$cid][substr($key, 0, -4) . '_last'] = $row['last_at'] ?? null;
    }
  }
}

function smf_valid_date(string $value): string {
  $value = trim($value);
  if ($value === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $value);
  return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

function smf_first_date_column(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $column) if (smf_column_exists($pdo, $table, $column)) return $column;
  return null;
}

// Quantidade no período (se houver) + data do último registro (sempre geral,
// sem o período, para saber o último dia em que a empresa usou o sistema).
function smf_period_condition(string $dateCol, string $dateFrom, string $dateTo, array &$params): string {
  $cond = [];
  if ($dateFrom !== '') { $cond[] = "`{$dateCol}` >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
  if ($dateTo !== '') { $cond[] = "`{$dateCol}` < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $dateTo . ' 00:00:00'; }
  return $cond ? implode(' AND ', $cond) : '1=1';
}

function smf_group_count(PDO $pdo, string $table, array $companyIds, string $extraWhere = '', string $dateFrom = '', string $dateTo = '', array $dateCandidates = ['created_at']): array {
  if (!$companyIds || !smf_table_exists($pdo, $table) || !smf_column_exists($pdo, $table, 'company_id')) return [];
  $dateCol = smf_first_date_column($pdo, $table, $dateCandidates);
  $params = [];
  if ($dateCol !== null) {
    $inPeriod = smf_period_condition($dateCol, $dateFrom, $dateTo, $params);
    $cols = "SUM(CASE WHEN {$inPeriod} THEN 1 ELSE 0 END) qty, MAX(`{$dateCol}`) last_at";
  } else {
    $cols = 'COUNT(*) qty, NULL last_at';
  }
  $ph = implode(',', array_fill(0, count($companyIds), '?'));
  $sql = "SELECT company_id, {$cols} FROM `{$table}` WHERE company_id IN ({$ph})";
  $params = array_merge($params, $companyIds);
  if ($extraWhere !== '') $sql .= ' AND (' . $extraWhere . ')';
  $sql .= ' GROUP BY company_id';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function smf_last_html($value, bool $activity = true): string {
  $value = trim((string)$value);
  if ($value === '' || strpos($value, '0000-00-00') === 0) return '<small class="last none">sem registro</small>';
  $ts = strtotime($value);
  if ($ts === false) return '';
  $days = (int)floor((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
  $cls = !$activity ? '' : ($days > 30 ? ' old' : ($days > 7 ? ' mid' : ''));
  return '<small class="last' . $cls . '" title="Último registro em ' . e(date('d/m/Y H:i', $ts)) . '">' . e(date('d/m/Y', $ts)) . '</small>';
}

function smf_domain(array $row): string {
  $custom = trim((string)($row['custom_domain'] ?? ''));
  if ($custom !== '') return $custom;
  $sub = trim((string)($row['subdomain'] ?? $row['slug'] ?? ''));
  return $sub !== '' ? $sub . '.nexaprospero.com.br' : '—';
}

$q = trim((string)($_GET['q'] ?? ''));
$dateFrom = smf_valid_date((string)($_GET['date_from'] ?? ''));
$dateTo = smf_valid_date((string)($_GET['date_to'] ?? ''));
$periodActive = ($dateFrom !== '' || $dateTo !== '');
$erro = '';
$rows = [];

try {
  $select = ['c.id','c.name','c.slug'];
  foreach (['nome_fantasia','razao_social','cnpj','subdomain','custom_domain','status'] as $col) {
    if (smf_company_column($pdo, $col)) $select[] = "c.`{$col}`";
    else $select[] = "NULL AS `{$col}`";
  }

  $where = [];
  $params = [];
  if ($q !== '') {
    $digits = support_digits($q);
    $parts = ['c.name LIKE ?','c.slug LIKE ?'];
    $params[] = nexa_search_like($q);
    $params[] = nexa_search_like($q);
    foreach (['nome_fantasia','razao_social','subdomain','custom_domain'] as $col) {
      if (smf_company_column($pdo, $col)) {
        $parts[] = "c.`{$col}` LIKE ?";
        $params[] = nexa_search_like($q);
      }
    }
    if ($digits !== '' && smf_company_column($pdo, 'cnpj')) {
      $parts[] = "REPLACE(REPLACE(REPLACE(COALESCE(c.cnpj,''),'.',''),'/',''),'-','') LIKE ?";
      $params[] = nexa_search_like($digits);
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
  }

  $sql = 'SELECT ' . implode(', ', $select) . ' FROM companies c';
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY c.name ASC LIMIT 1000';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $rowsById = [];
  foreach ($rows as $row) {
    $cid = (int)$row['id'];
    $row += [
      'products_qty'=>0,'customers_qty'=>0,'orders_qty'=>0,
      'nfce_qty'=>0,'nfe_qty'=>0,'nfse_qty'=>0,'rejected_qty'=>0,'contingency_qty'=>0,
      'products_last'=>null,'customers_last'=>null,'orders_last'=>null,
      'nfce_last'=>null,'nfe_last'=>null,'nfse_last'=>null,'rejected_last'=>null,'contingency_last'=>null,
    ];
    $rowsById[$cid] = $row;
  }

  $companyIds = array_keys($rowsById);
  if ($companyIds) {
    smf_add_counts($rowsById, smf_group_count($pdo, 'produtos', $companyIds, '', $dateFrom, $dateTo, ['created_at','data_cadastro','cadastrado_em']), 'products_qty');

    $clientWhere = smf_column_exists($pdo, 'finance_people', 'is_customer') ? 'COALESCE(is_customer,0)=1' : '';
    smf_add_counts($rowsById, smf_group_count($pdo, 'finance_people', $companyIds, $clientWhere, $dateFrom, $dateTo, ['created_at']), 'customers_qty');

    smf_add_counts($rowsById, smf_group_count($pdo, 'gestao_orders', $companyIds, '', $dateFrom, $dateTo, ['created_at','order_date','issued_at']), 'orders_qty');

    if (smf_table_exists($pdo, 'fiscal_documents') && smf_column_exists($pdo, 'fiscal_documents', 'company_id')) {
      $ph = implode(',', array_fill(0, count($companyIds), '?'));
      $hasDirection = smf_column_exists($pdo, 'fiscal_documents', 'direction');
      $hasEmission = smf_column_exists($pdo, 'fiscal_documents', 'emission_type');
      $hasContStarted = smf_column_exists($pdo, 'fiscal_documents', 'contingency_started_at');
      $saida = $hasDirection ? "AND UPPER(COALESCE(direction,'SAIDA'))='SAIDA'" : '';
      $contExpr = "UPPER(COALESCE(status,'')) IN ('CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','FS_DA')";
      if ($hasEmission) $contExpr .= " OR (UPPER(COALESCE(emission_type,'NORMAL')) <> 'NORMAL' AND UPPER(COALESCE(status,'')) NOT IN ('AUTORIZADA','CANCELADA','REJEITADA'))";
      if ($hasContStarted) $contExpr .= " OR (contingency_started_at IS NOT NULL AND UPPER(COALESCE(status,'')) NOT IN ('AUTORIZADA','CANCELADA','REJEITADA'))";

      $fiscalParams = [];
      $fiscalDateCol = smf_first_date_column($pdo, 'fiscal_documents', ['operation_datetime','issue_datetime','created_at']);
      $fiscalCats = [
        'nfce' => "model='65' AND UPPER(COALESCE(status,'')) IN ('AUTORIZADA','CANCELADA')",
        'nfe' => "model='55' AND UPPER(COALESCE(status,'')) IN ('AUTORIZADA','CANCELADA')",
        'nfse' => "UPPER(COALESCE(model,'')) IN ('NFSE','DPS','RPS','SS') AND UPPER(COALESCE(status,'')) IN ('AUTORIZADA','CANCELADA')",
        'rejected' => "UPPER(COALESCE(status,''))='REJEITADA'",
        'contingency' => "({$contExpr})",
      ];
      $fiscalCols = [];
      foreach ($fiscalCats as $k => $cond) {
        if ($fiscalDateCol !== null) {
          $inPeriod = smf_period_condition($fiscalDateCol, $dateFrom, $dateTo, $fiscalParams);
          $fiscalCols[] = "SUM(CASE WHEN ({$cond}) AND {$inPeriod} THEN 1 ELSE 0 END) {$k}_qty";
          $fiscalCols[] = "MAX(CASE WHEN ({$cond}) THEN `{$fiscalDateCol}` END) {$k}_last";
        } else {
          $fiscalCols[] = "SUM(CASE WHEN ({$cond}) THEN 1 ELSE 0 END) {$k}_qty";
          $fiscalCols[] = "NULL {$k}_last";
        }
      }
      $fiscalParams = array_merge($fiscalParams, $companyIds);
      $sqlFiscal = 'SELECT company_id, ' . implode(', ', $fiscalCols) . " FROM fiscal_documents WHERE company_id IN ({$ph}) {$saida} GROUP BY company_id";
      $fs = $pdo->prepare($sqlFiscal);
      $fs->execute($fiscalParams);
      foreach ($fs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fr) {
        $cid = (int)$fr['company_id'];
        if (!isset($rowsById[$cid])) continue;
        foreach (['nfce','nfe','nfse','rejected','contingency'] as $k) {
          $rowsById[$cid][$k . '_qty'] = (int)($fr[$k . '_qty'] ?? 0);
          $rowsById[$cid][$k . '_last'] = $fr[$k . '_last'] ?? null;
        }
      }
    }
  }
  $rows = array_values($rowsById);
} catch (Throwable $e) {
  $erro = 'Não foi possível carregar o monitor fiscal: ' . $e->getMessage();
}

$totals = ['products_qty'=>0,'customers_qty'=>0,'orders_qty'=>0,'nfce_qty'=>0,'nfe_qty'=>0,'nfse_qty'=>0,'rejected_qty'=>0,'contingency_qty'=>0];
foreach ($rows as $row) foreach ($totals as $key => $_) $totals[$key] += (int)($row[$key] ?? 0);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monitor Fiscal - Suporte Nexa Próspero</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0b1f5b;--muted:#5b6475;--line:#dce7f5;--primary:#2454d6;--accent:#00b8ff;--danger:#b91c1c;--warn:#b45309;--success:#15803d;--shadow:0 18px 44px rgba(11,31,91,.09)}*{box-sizing:border-box}html,body{margin:0;padding:0;background:radial-gradient(circle at 12% 10%,rgba(0,184,255,.10),transparent 28%),var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif}.shell{max-width:1600px;margin:0 auto;padding:22px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;border-radius:24px;background:linear-gradient(135deg,#0b1f5b,#2454d6 70%,#00b8ff 140%);color:#fff;box-shadow:0 20px 50px rgba(11,31,91,.18)}.brand{display:flex;align-items:center;gap:12px}.brand img{width:52px;height:52px;object-fit:contain;background:#fff;padding:5px;border-radius:15px}.brand strong{display:block;font-size:20px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px}.nav a{color:#eaf8ff;text-decoration:none;font-weight:850;padding:7px 10px;border-radius:999px}.nav a.active,.nav a:hover{background:rgba(255,255,255,.16);color:#fff}.user{text-align:right;font-size:13px;color:#dff6ff}.user strong{display:block;color:#fff}.hero{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;padding:28px 4px 16px}.hero h1{font-size:34px;margin:0 0 8px;letter-spacing:-.04em}.hero p{margin:0;color:var(--muted)}.search-card,.table-card,.kpi{background:#fff;border:1px solid rgba(36,84,214,.12);box-shadow:var(--shadow)}.search-card{padding:16px;border-radius:20px;margin-bottom:14px}.search-form{display:grid;grid-template-columns:minmax(280px,1fr) 170px 170px auto auto;gap:10px;align-items:end}.search-form label{font-weight:900}.search-form input{display:block;width:100%;height:46px;margin-top:6px;border:1px solid var(--line);border-radius:14px;padding:0 14px;font-size:15px;outline:none}.btn{height:46px;border:0;border-radius:999px;padding:0 20px;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn.secondary{background:#fff;color:var(--text);border:1px solid var(--line)}.kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.kpi{border-radius:18px;padding:14px}.kpi small{display:block;color:var(--muted);font-weight:800}.kpi strong{display:block;margin-top:4px;font-size:22px}.kpi.danger strong{color:var(--danger)}.kpi.warn strong{color:var(--warn)}.table-card{border-radius:22px;padding:16px;overflow:hidden}.table-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.table-head h2{margin:0;font-size:20px}.table-head span{color:var(--muted);font-weight:800}.scroll{overflow:auto;border:1px solid var(--line);border-radius:16px}table{width:100%;border-collapse:separate;border-spacing:0;min-width:1180px;background:#fff}thead th{position:sticky;top:0;z-index:2;background:#f5f8fd;color:#334155;font-size:12px;text-align:center;padding:11px 9px;border-bottom:1px solid var(--line);white-space:nowrap}thead th:first-child{text-align:left}tbody td{padding:11px 9px;border-bottom:1px solid #edf2f7;text-align:center;font-weight:800;color:#1f2937}tbody td:first-child{text-align:left}tbody tr:last-child td{border-bottom:0}tbody tr.has-danger{background:#fff7f7}tbody tr.has-warn:not(.has-danger){background:#fffaf0}.company strong{display:block;color:var(--text);font-size:14px}.company small{display:block;color:var(--muted);margin-top:3px;font-weight:700}.num{font-variant-numeric:tabular-nums}.bad{display:inline-flex;min-width:34px;justify-content:center;padding:5px 8px;border-radius:999px;background:#fee2e2;color:#991b1b}.warning{display:inline-flex;min-width:34px;justify-content:center;padding:5px 8px;border-radius:999px;background:#fef3c7;color:#92400e}.ok{color:#64748b}.error{margin-bottom:14px;padding:12px 14px;border-radius:14px;background:#fef2f2;color:var(--danger);border:1px solid #fecaca;font-weight:800}.empty{padding:28px;text-align:center;color:var(--muted)}@media(max-width:980px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.search-form{grid-template-columns:1fr}.btn{width:100%}.hero{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.shell{padding:12px}.topbar{align-items:flex-start;border-radius:18px}.kpis{grid-template-columns:1fr}.hero h1{font-size:28px}}
.num small.last{display:block;margin-top:2px;font-size:10.5px;font-weight:600;color:#7b8798;letter-spacing:.01em;white-space:nowrap}.num small.last.mid{color:#b45309}.num small.last.old{color:#b91c1c}.num small.last.none{color:#b4bcc8;font-weight:500}.last-legend{padding:0 2px 10px;font-size:12px;color:#7b8798}.last-legend .mid{color:#b45309}.last-legend .old{color:#b91c1c}
</style>
</head>
<body>
<div class="shell">
<header class="topbar"><div class="brand"><img src="/assets/logo-nexa-v6-2.png" alt="Nexa"><div><strong>Portal do Suporte</strong><nav class="nav"><a href="/suporte/index.php">Empresas</a><a class="active" href="/suporte/monitor_fiscal.php">Monitor Fiscal</a><a href="/suporte/whatsapp.php">WhatsApp</a><a href="/suporte/monitor_saude.php">Saúde do Sistema</a><a href="/suporte/armazenamento.php">Armazenamento</a></nav></div></div><div class="user"><strong><?= e((string)$user['name']) ?></strong><?= e((string)$user['email']) ?> · <a style="color:#fff" href="/suporte/logout.php">Sair</a></div></header>

<section class="hero"><div><h1>Monitor Fiscal</h1><p>Visão consolidada das empresas, cadastros, pedidos e documentos fiscais, com filtro opcional por período.</p></div></section>

<?php if ($erro !== ''): ?><div class="error"><?= e($erro) ?></div><?php endif; ?>

<section class="search-card">
<form method="get" class="search-form">
<label>Empresa, domínio ou CNPJ<input name="q" value="<?= e($q) ?>" placeholder="Deixe vazio para mostrar todas as empresas" autocomplete="off"></label>
<label>De<input type="date" name="date_from" value="<?= e($dateFrom) ?>"></label>
<label>Até<input type="date" name="date_to" value="<?= e($dateTo) ?>"></label>
<button class="btn" type="submit">Pesquisar</button>
<a class="btn secondary" href="/suporte/monitor_fiscal.php">Limpar</a>
</form>
<?php if ($periodActive): ?><div style="margin-top:10px;color:var(--muted);font-size:13px;font-weight:800">Período aplicado: <?= e($dateFrom !== '' ? date('d/m/Y', strtotime($dateFrom)) : 'início') ?> até <?= e($dateTo !== '' ? date('d/m/Y', strtotime($dateTo)) : 'hoje') ?>. Produtos e clientes representam cadastros criados no período; pedidos e documentos fiscais usam a data da operação.</div><?php endif; ?>
</section>

<div class="kpis">
<div class="kpi"><small>Empresas exibidas</small><strong><?= count($rows) ?></strong></div>
<div class="kpi"><small>NFC-e / NF-e / NFS-e emitidas</small><strong><?= number_format($totals['nfce_qty']+$totals['nfe_qty']+$totals['nfse_qty'],0,',','.') ?></strong></div>
<div class="kpi"><small>Pedidos</small><strong><?= number_format($totals['orders_qty'],0,',','.') ?></strong></div>
<div class="kpi danger"><small>Rejeitadas</small><strong><?= number_format($totals['rejected_qty'],0,',','.') ?></strong></div>
<div class="kpi warn"><small>Em contingência</small><strong><?= number_format($totals['contingency_qty'],0,',','.') ?></strong></div>
</div>

<section class="table-card">
<div class="table-head"><h2>Empresas monitoradas<?= $periodActive ? " no período" : "" ?></h2><span><?= count($rows) ?> registro(s)</span></div>
<div class="last-legend">Data pequena abaixo de cada número: último registro da empresa (geral, independente do período). <b class="mid">Laranja</b>: mais de 7 dias · <b class="old">vermelho</b>: mais de 30 dias sem movimento.</div>
<div class="scroll"><table>
<thead><tr><th>Empresa</th><th>Produtos</th><th>Clientes</th><th>Pedidos</th><th>NFC-e emitidas</th><th>NF-e emitidas</th><th>NFS-e emitidas</th><th>Rejeitadas</th><th>Contingência</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">Nenhuma empresa encontrada.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
  $rej=(int)$row['rejected_qty']; $cont=(int)$row['contingency_qty'];
  $rowClass=$rej>0?'has-danger':($cont>0?'has-warn':'');
  $fantasia=trim((string)($row['nome_fantasia'] ?? ''));
  $domain=smf_domain($row);
?>
<tr class="<?= e($rowClass) ?>">
<td><div class="company"><strong><?= e((string)$row['name']) ?></strong><small><?= e($fantasia !== '' && $fantasia !== (string)$row['name'] ? $fantasia . ' · ' . $domain : $domain) ?></small></div></td>
<td class="num"><?= number_format((int)$row['products_qty'],0,',','.') ?><?= smf_last_html($row['products_last']) ?></td>
<td class="num"><?= number_format((int)$row['customers_qty'],0,',','.') ?><?= smf_last_html($row['customers_last']) ?></td>
<td class="num"><?= number_format((int)$row['orders_qty'],0,',','.') ?><?= smf_last_html($row['orders_last']) ?></td>
<td class="num"><?= number_format((int)$row['nfce_qty'],0,',','.') ?><?= smf_last_html($row['nfce_last']) ?></td>
<td class="num"><?= number_format((int)$row['nfe_qty'],0,',','.') ?><?= smf_last_html($row['nfe_last']) ?></td>
<td class="num"><?= number_format((int)$row['nfse_qty'],0,',','.') ?><?= smf_last_html($row['nfse_last']) ?></td>
<td class="num"><?= $rej>0?'<span class="bad">'.number_format($rej,0,',','.').'</span>':'<span class="ok">0</span>' ?><?= smf_last_html($row['rejected_last'], false) ?></td>
<td class="num"><?= $cont>0?'<span class="warning">'.number_format($cont,0,',','.').'</span>':'<span class="ok">0</span>' ?><?= smf_last_html($row['contingency_last'], false) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
</div>
</body>
</html>
