<?php
declare(strict_types=1);
/**
 * Nexa Próspero — Análise Dashboard por período.
 * Histórico de pedidos e itens, sem alterar o Dashboard inicial.
 * Consulta permitida somente mediante autorização explícita no perfil.
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';

$reportCompanyId = (int)COMPANY_ID; // Escopo da empresa autenticada (sem leitura cruzada via parâmetros GET).
if (!function_exists('access_has_permission') || !access_has_permission($pdo, $reportCompanyId, 'relatorio_analise_dashboard')) {
  access_render_denied('Seu perfil não permite consultar a Análise Dashboard por período.');
  exit;
}

function rad_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rad_money(mixed $n): string { return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function rad_num(mixed $n, int $d = 0): string { return number_format((float)$n, $d, ',', '.'); }
/** Escala resumida para os eixos do gráfico; os valores exatos ficam na legenda mensal. */
function rad_money_short(mixed $n): string {
  $n = (float)$n;
  if (abs($n) >= 1000000) return 'R$ '.number_format($n / 1000000, 1, ',', '.').' mi';
  if (abs($n) >= 1000) return 'R$ '.number_format($n / 1000, 1, ',', '.').' mil';
  return 'R$ '.number_format($n, 0, ',', '.');
}
function rad_date(string $s): ?DateTimeImmutable {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s ? $d : null;
}
function rad_delta(float $now, float $old): string {
  if ($old == 0.0) return $now == 0.0 ? '0%' : '—';
  $p = ($now / $old - 1) * 100;
  return ($p > 0 ? '+' : '') . number_format($p, 1, ',', '.') . '%';
}
function rad_fetch(PDO $pdo, string $sql, array $args, string $label, array &$warnings): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('[Nexa Análise Dashboard] '.$label.': '.$e->getMessage());
    $warnings[] = 'Não foi possível calcular ' . $label . '. Consulte a administração técnica.';
    return [];
  }
}
function rad_first(PDO $pdo, string $sql, array $args, string $label, array &$warnings): array {
  return rad_fetch($pdo, $sql, $args, $label, $warnings)[0] ?? [];
}

$today = new DateTimeImmutable('today');
$initialFrom = $today->format('Y-m-01');
$initialTo = $today->format('Y-m-d');
$fromStr = trim((string)($_GET['from'] ?? $initialFrom));
$toStr = trim((string)($_GET['to'] ?? $initialTo));
$errors = [];
$warnings = [];
$from = rad_date($fromStr);
$to = rad_date($toStr);
if (!$from || !$to) $errors[] = 'Informe datas válidas no formato dia/mês/ano.';
elseif ($from > $to) $errors[] = 'A data inicial não pode ser maior que a final.';
elseif ($from->diff($to)->days > 730) $errors[] = 'Consulte no máximo 731 dias por pesquisa para preservar o desempenho do sistema.';

$summary = [];
$compare = [];
$products = [];
$services = [];
$sellers = [];
$status = [];
$payments = [];
$channels = [];
$series = [];
$topProduct = null;
$topService = null;
$days = 0;
$previousStart = '';
$previousEnd = '';
$cancelRate = 0.0;
$finalRate = 0.0;
$ticket = 0.0;
$averageDaily = 0.0;
$hasServiceColumn = false;
$extraKpis = [];
$monthSummary = [];
$monthLabel = '';
$monthRangeLabel = '';
$monthBaseDate = null;
// Série anual independente da seleção diária: utiliza o ano da data 'Até'.
$annualYear = '';
$annualMonths = [];
$annualSalesTotal = 0.0;
$annualOrdersTotal = 0;
$annualTicketTotal = 0.0;
$annualAvailable = true;

// Atalhos como links GET comuns: não dependem de JavaScript nem de requestSubmit.
// Assim o usuário pode alternar livremente entre Hoje, Este mês, Mês anterior etc.
$rangeToday = $today->format('Y-m-d');
$quickRanges = [
  ['today', 'Hoje', $rangeToday, $rangeToday],
  ['month', 'Este mês', $today->format('Y-m-01'), $rangeToday],
  ['previous', 'Mês anterior', $today->modify('first day of previous month')->format('Y-m-d'), $today->modify('last day of previous month')->format('Y-m-d')],
  ['30', 'Últimos 30 dias', $today->modify('-29 days')->format('Y-m-d'), $rangeToday],
  ['year', 'Este ano', $today->format('Y-01-01'), $rangeToday],
];

if (!$errors) {
  $days = $from->diff($to)->days + 1;
  $endEx = $to->modify('+1 day')->format('Y-m-d');
  $prevFrom = $from->modify('-'.$days.' days');
  $previousStart = $prevFrom->format('Y-m-d');
  $previousEnd = $from->modify('-1 day')->format('Y-m-d');
  $sqlOverview = <<<'SQL'
SELECT COUNT(*) AS total_pedidos,
       SUM(CASE WHEN status <> 'cancelado' THEN 1 ELSE 0 END) AS pedidos_validos,
       SUM(CASE WHEN status = 'finalizado' THEN 1 ELSE 0 END) AS finalizados,
       SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
       COALESCE(SUM(CASE WHEN status <> 'cancelado' THEN total ELSE 0 END),0) AS faturamento,
       COALESCE(SUM(CASE WHEN status = 'finalizado' THEN total ELSE 0 END),0) AS faturamento_finalizado
FROM pedidos WHERE company_id=? AND created_at >= ? AND created_at < ?
SQL;
  $summary = rad_first($pdo, $sqlOverview, [$reportCompanyId, $fromStr, $endEx], 'os indicadores principais', $warnings);
  $compare = rad_first($pdo, $sqlOverview, [$reportCompanyId, $previousStart, $fromStr], 'a comparação anterior', $warnings);

  // Indicador independente do filtro: mês calendário que contém a data final (Até).
  // Ex.: 10/09 a 15/09 => mostra 01/09 a 30/09, enquanto Total vendido mostra só 10/09 a 15/09.
  // Quando o intervalo cruza meses, o mês de referência é sempre o da data Até.
  $monthBaseDate = $to->modify('first day of this month');
  $monthStart = $monthBaseDate->format('Y-m-d');
  $monthEndEx = $monthBaseDate->modify('first day of next month')->format('Y-m-d');
  $monthLast = $monthBaseDate->modify('last day of this month');
  $monthNames = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
  $monthLabel = ucfirst($monthNames[(int)$monthBaseDate->format('n')]).'/'.$monthBaseDate->format('Y');
  $monthRangeLabel = $monthBaseDate->format('d/m/Y').' a '.$monthLast->format('d/m/Y');
  // Reutiliza a consulta do período quando ele já cobre integralmente o mês.
  $monthSummary = ($fromStr === $monthStart && $toStr === $monthLast->format('Y-m-d'))
    ? $summary
    : rad_first($pdo, $sqlOverview, [$reportCompanyId, $monthStart, $monthEndEx], 'as vendas do mês de referência', $warnings);

  $valid = (int)($summary['pedidos_validos'] ?? 0);
  $tot = (int)($summary['total_pedidos'] ?? 0);
  $ticket = $valid > 0 ? (float)($summary['faturamento'] ?? 0) / $valid : 0.0;
  $averageDaily = (float)($summary['faturamento'] ?? 0) / $days;
  $cancelRate = $tot > 0 ? 100 * (int)($summary['cancelados'] ?? 0) / $tot : 0.0;
  $finalRate = $tot > 0 ? 100 * (int)($summary['finalizados'] ?? 0) / $tot : 0.0;

  // Agregações independentes: nenhum JOIN em pedidos para somar faturamento geral,
  // evitando duplicação de totais quando um pedido possui vários itens.
  $hasServiceColumn = function_exists('access_column_exists') && access_column_exists($pdo, 'produtos', 'is_service');
  $itemTypeFilter = $hasServiceColumn ? 'COALESCE(p.is_service,0) = ' : '0 = ';
  $sqlProducts = 'SELECT COALESCE(NULLIF(p.nome,\'\'),CONCAT(\'Produto #\',pi.produto_id)) AS nome, SUM(pi.qtd) AS qtd, COALESCE(SUM(pi.total),0) AS valor
    FROM pedido_itens pi JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id
    LEFT JOIN produtos p ON p.id=pi.produto_id AND p.company_id=pi.company_id
    WHERE pe.company_id=? AND pe.created_at >= ? AND pe.created_at < ? AND pe.status<>\'cancelado\' AND ' . $itemTypeFilter . '0
    GROUP BY pi.produto_id,p.nome ORDER BY qtd DESC,valor DESC LIMIT 10';
  $products = rad_fetch($pdo, $sqlProducts, [$reportCompanyId, $fromStr, $endEx], 'os produtos mais vendidos', $warnings);
  if ($hasServiceColumn) {
    $sqlServices = str_replace($itemTypeFilter.'0', $itemTypeFilter.'1', $sqlProducts);
    $services = rad_fetch($pdo, $sqlServices, [$reportCompanyId, $fromStr, $endEx], 'os serviços mais vendidos', $warnings);
  }
  $topProduct = $products[0] ?? null;
  $topService = $services[0] ?? null;

  // Indicadores específicos, condicionados ao módulo contratado e ao histórico existente.
  if (function_exists('saas_company_has_module') && saas_company_has_module($pdo, $reportCompanyId, 'comandas')) {
    $com = rad_first($pdo, "SELECT COUNT(*) AS qtd, COALESCE(SUM(total),0) AS valor FROM pedidos
      WHERE company_id=? AND created_at>=? AND created_at<? AND LOWER(COALESCE(source,''))='comanda' AND status='finalizado'",
      [$reportCompanyId, $fromStr, $endEx], 'as comandas finalizadas', $warnings);
    $comOld = rad_first($pdo, "SELECT COUNT(*) AS qtd FROM pedidos
      WHERE company_id=? AND created_at>=? AND created_at<? AND LOWER(COALESCE(source,''))='comanda' AND status='finalizado'",
      [$reportCompanyId, $previousStart, $fromStr], 'a comparação de comandas', $warnings);
    $extraKpis[] = ['Comandas finalizadas', rad_num($com['qtd'] ?? 0), rad_delta((float)($com['qtd'] ?? 0),(float)($comOld['qtd'] ?? 0)), 'pedidos de comanda finalizados no período'];
  }
  if (function_exists('saas_company_has_module') && saas_company_has_module($pdo, $reportCompanyId, 'ordem_servico')) {
    $os = rad_first($pdo, "SELECT COUNT(*) AS abertas,
      SUM(CASE WHEN closed_at>=? AND closed_at<? THEN 1 ELSE 0 END) AS fechadas
      FROM os_orders WHERE company_id=? AND created_at>=? AND created_at<?",
      [$fromStr, $endEx, $reportCompanyId, $fromStr, $endEx], 'as ordens de serviço', $warnings);
    $extraKpis[] = ['O.S. abertas no período',rad_num($os['abertas'] ?? 0),null,'data de criação da ordem de serviço'];
    // Fechamentos independentes da data de abertura (podem ter começado antes do intervalo).
    $osClosed = rad_first($pdo, 'SELECT COUNT(*) AS qtd FROM os_orders WHERE company_id=? AND closed_at>=? AND closed_at<?',
      [$reportCompanyId, $fromStr, $endEx], 'as O.S. encerradas', $warnings);
    $extraKpis[] = ['O.S. encerradas no período',rad_num($osClosed['qtd'] ?? 0),null,'data de encerramento da ordem de serviço'];
  }


  $sellers = rad_fetch($pdo, <<<'SQL'
SELECT COALESCE(NULLIF(fp.name,''),CONCAT('Vendedor #',pe.seller_id)) AS vendedor,
       COUNT(*) AS qtd, COALESCE(SUM(pe.total),0) AS valor
FROM pedidos pe LEFT JOIN finance_people fp ON fp.company_id=pe.company_id AND fp.id=pe.seller_id
WHERE pe.company_id=? AND pe.created_at >= ? AND pe.created_at < ? AND pe.status='finalizado' AND pe.seller_id > 0
GROUP BY pe.seller_id,fp.name ORDER BY valor DESC LIMIT 10
SQL, [$reportCompanyId, $fromStr, $endEx], 'o ranking de vendedores', $warnings);

  $status = rad_fetch($pdo, <<<'SQL'
SELECT status AS rotulo, COUNT(*) AS qtd
FROM pedidos WHERE company_id=? AND created_at >= ? AND created_at < ?
GROUP BY status ORDER BY qtd DESC
SQL, [$reportCompanyId, $fromStr, $endEx], 'a distribuição dos status', $warnings);
  $payments = rad_fetch($pdo, <<<'SQL'
SELECT COALESCE(NULLIF(forma_pagamento,''),'Não informado') AS rotulo,
       COUNT(*) AS qtd, COALESCE(SUM(total),0) AS valor
FROM pedidos WHERE company_id=? AND created_at >= ? AND created_at < ? AND status<>'cancelado'
GROUP BY forma_pagamento ORDER BY valor DESC LIMIT 10
SQL, [$reportCompanyId, $fromStr, $endEx], 'as formas de pagamento', $warnings);
  $channels = rad_fetch($pdo, <<<'SQL'
SELECT COALESCE(NULLIF(source,''),'Não informado') AS origem,
       COALESCE(NULLIF(tipo_entrega,''),'Não informado') AS atendimento,
       COUNT(*) AS qtd, COALESCE(SUM(total),0) AS valor
FROM pedidos WHERE company_id=? AND created_at >= ? AND created_at < ? AND status<>'cancelado'
GROUP BY source,tipo_entrega ORDER BY valor DESC LIMIT 12
SQL, [$reportCompanyId, $fromStr, $endEx], 'as origens dos pedidos', $warnings);

  $groupExpression = $days <= 62 ? 'DATE(created_at)' : "DATE_FORMAT(created_at,'%Y-%m')";
  $series = rad_fetch($pdo, 'SELECT '.$groupExpression.' AS periodo, COUNT(*) AS qtd,COALESCE(SUM(total),0) AS valor
    FROM pedidos WHERE company_id=? AND created_at >= ? AND created_at < ? AND status<>\'cancelado\'
    GROUP BY '.$groupExpression.' ORDER BY periodo', [$reportCompanyId, $fromStr, $endEx], 'a evolução das vendas', $warnings);


  // 12 meses do ANO CIVIL correspondente à data Até, mesmo quando o filtro é de um dia.
  // O ticket mensal sempre usa o mesmo conjunto de pedidos válidos da receita mensal:
  // ticket = SUM(total) / COUNT(*), excluindo status cancelado e respeitando company_id.
  $annualYear = $to->format('Y');
  $annualFrom = $annualYear.'-01-01';
  $annualEndEx = ((int)$annualYear + 1).'-01-01';
  // Não contar pedidos datados em meses futuros nem sugerir venda realizada no futuro.
  $annualQueryEndEx = min($annualEndEx, $today->modify('+1 day')->format('Y-m-d'));
  $warningCountBefore = count($warnings);
  $annualRows = rad_fetch($pdo, <<<'SQL'
SELECT DATE_FORMAT(created_at, '%Y-%m') AS mes,
       COUNT(*) AS pedidos_validos,
       COALESCE(SUM(total),0) AS vendido
FROM pedidos
WHERE company_id=? AND created_at >= ? AND created_at < ? AND status <> 'cancelado'
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
ORDER BY mes
SQL, [$reportCompanyId, $annualFrom, $annualQueryEndEx], 'o gráfico anual de vendas e ticket médio', $warnings);
  $annualAvailable = count($warnings) === $warningCountBefore;
  $annualByMonth = [];
  foreach ($annualRows as $annualRow) {
    $annualByMonth[(string)$annualRow['mes']] = $annualRow;
  }
  $monthAbbreviations = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
  for ($m=1; $m<=12; $m++) {
    $ym = sprintf('%s-%02d', $annualYear, $m);
    $monthRow = $annualByMonth[$ym] ?? [];
    $monthSales = (float)($monthRow['vendido'] ?? 0);
    $monthOrders = (int)($monthRow['pedidos_validos'] ?? 0);
    $monthStartDate = new DateTimeImmutable($ym.'-01');
    $isFutureMonth = $monthStartDate > $today->modify('first day of this month');
    $annualSalesTotal += $monthSales;
    $annualOrdersTotal += $monthOrders;
    $annualMonths[] = [
      'label' => $monthAbbreviations[$m],
      'mes' => $ym,
      'sales' => $monthSales,
      'orders' => $monthOrders,
      'ticket' => $monthOrders > 0 ? $monthSales / $monthOrders : null,
      'future' => $isFutureMonth,
    ];
  }
  $annualTicketTotal = $annualOrdersTotal > 0 ? $annualSalesTotal / $annualOrdersTotal : 0.0;
}

admin_topbar('Análise Dashboard por período','relatorio_analise_dashboard');
$maxSeries = max([1.0, ...array_map(static fn(array $r): float => (float)($r['valor'] ?? 0), $series)]);
// Coordenadas pré-calculadas no servidor: sem JavaScript externo e sem rolagem no quadro.
// Vendas usam o eixo esquerdo; ticket médio usa o direito (duas escalas independentes).
$annualMaxSales = max([1.0, ...array_map(static fn(array $m): float => (float)$m['sales'], $annualMonths)]);
$annualMaxTicket = max([1.0, ...array_map(static fn(array $m): float => (float)($m['ticket'] ?? 0), $annualMonths)]);
$annualPlotLeft = 95.0;
$annualPlotRight = 1065.0;
$annualPlotTop = 46.0;
$annualPlotBottom = 300.0;
$annualSalePoints = [];
$annualTicketSegments = [];
$annualTicketSegment = [];
$annualCoordinates = [];
foreach ($annualMonths as $monthIdx => $monthData) {
  $chartX = $annualPlotLeft + ($annualPlotRight-$annualPlotLeft) * $monthIdx / 11;
  $saleY = $annualPlotBottom - (float)$monthData['sales'] / $annualMaxSales * ($annualPlotBottom-$annualPlotTop);
  $ticketY = $monthData['ticket'] === null ? null
    : $annualPlotBottom - (float)$monthData['ticket'] / $annualMaxTicket * ($annualPlotBottom-$annualPlotTop);
  $annualCoordinates[] = ['x'=>$chartX, 'sale_y'=>$saleY, 'ticket_y'=>$ticketY];
  if (empty($monthData['future'])) {
    $annualSalePoints[] = sprintf('%.1f,%.1f', $chartX, $saleY);
  }
  if (!empty($monthData['future']) || $ticketY === null) {
    if ($annualTicketSegment) $annualTicketSegments[] = $annualTicketSegment;
    $annualTicketSegment = [];
  } else {
    $annualTicketSegment[] = sprintf('%.1f,%.1f', $chartX, $ticketY);
  }
}
if ($annualTicketSegment) $annualTicketSegments[] = $annualTicketSegment;
?>
<style>
/* Nexa Próspero: identidade do Dashboard inicial, aplicada somente à Análise. */
body{background:radial-gradient(circle at 8% 0%,color-mix(in srgb,var(--admin-accent,#50a2f5) 8%,transparent),transparent 28%),linear-gradient(180deg,#f8fbff,#f5f7fb)}
body > .container{max-width:1380px}
.rad-app,.rad-app *{box-sizing:border-box}
.rad-app{padding:8px 0 32px;color:#172b4d}
.rad-hero{position:relative;isolation:isolate;overflow:hidden;border-radius:26px;padding:26px 30px;color:#fff;background:radial-gradient(circle at 90% 6%,rgba(255,255,255,.20),transparent 35%),linear-gradient(135deg,var(--admin-primary,#12377e),var(--admin-secondary,#2563eb));box-shadow:0 20px 46px rgba(11,31,91,.14)}
.rad-hero::before{content:"";position:absolute;right:-78px;bottom:-165px;width:340px;height:340px;border-radius:50%;background:rgba(255,255,255,.075);z-index:-1}
.rad-hero-inner{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.rad-kicker{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:99px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);font-size:11px;font-weight:850;letter-spacing:.055em;text-transform:uppercase}
.rad-title{font-size:clamp(26px,3.3vw,40px);letter-spacing:-.04em;line-height:1.07;margin:13px 0 9px!important;color:#fff!important;font-weight:950}
.rad-hero .rad-subtitle{max-width:820px;color:rgba(255,255,255,.84);font-size:14px;line-height:1.55;margin:0}
.rad-back{display:inline-flex;justify-content:center;align-items:center;min-height:42px;padding:9px 15px;background:#fff;border:1px solid rgba(255,255,255,.5);color:var(--admin-primary,#12377e);font-size:12px;font-weight:900;border-radius:12px;text-decoration:none;white-space:nowrap}
.rad-back:hover{background:#eff6ff;color:var(--admin-primary,#12377e)}
.rad-filter-card{background:#fff;border:1px solid #deE9f6;border-radius:21px;padding:19px 22px;margin-top:16px;box-shadow:0 12px 30px rgba(11,31,91,.045)}
.rad-filter-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.rad-filter-head h2{margin:0;color:#0a2555;font-size:16px;font-weight:900;letter-spacing:-.02em}
.rad-muted{font-size:12px;color:#64748b}
/* Ações e datas compartilham as MESMAS duas linhas fixas (legenda 18px + controle 44px). */
.rad-date-filter{display:grid;grid-template-columns:minmax(0,210px) minmax(0,210px) 230px;justify-content:start;column-gap:12px;row-gap:12px;margin-top:14px;max-width:100%;align-items:start}
.rad-date-filter>label,.rad-date-filter>.rad-filter-actions{display:grid!important;grid-template-rows:18px 44px;row-gap:6px;align-items:stretch;min-width:0;margin:0!important;padding:0!important}
.rad-date-filter>label,.rad-filter-actions .rad-actions-caption{font-size:12px;font-weight:850;color:#334155;line-height:18px}
.rad-filter-actions .rad-actions-caption{display:block;height:18px;visibility:hidden}
.rad-date-filter input{display:block;box-sizing:border-box;width:100%;min-width:0;height:44px;min-height:44px;margin:0!important;padding:8px 12px;border:1px solid #b8c9e2;border-radius:11px;background:#fff;color:#142d56;font:inherit;font-size:13px;font-weight:650;outline:none}
.rad-date-filter input:focus{border-color:var(--admin-secondary,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.10)}
.rad-filter-actions .rad-action-buttons{display:flex;align-items:stretch;gap:10px;height:44px;min-width:0}
.rad-app .rad-date-filter .rad-filter-actions .btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important;flex:1 1 0;width:110px;min-width:0;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0 12px!important;line-height:1.2!important;text-align:center;border-radius:11px;font-size:13px;font-weight:850;text-decoration:none;white-space:nowrap;margin:0!important;position:static!important;transform:none!important}
.rad-filter-actions .rad-clear{color:#1c427f;background:#f7faff;border:1px solid #ccd9ec}
.rad-filter-actions .rad-clear:hover{background:#eaf2ff}
.rad-periods{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}
.rad-periods a{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #dbe7f6;background:#f5f8fd;border-radius:9px;color:#194584;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:800;text-decoration:none;line-height:1.3}
.rad-periods a:hover,.rad-periods a:focus-visible{border-color:var(--admin-primary,#12377e);background:#eaf2ff;color:#12377e}
.rad-periods a.is-active{background:#e6f0ff;border-color:#7baaf1;color:#113d83;box-shadow:inset 0 0 0 1px #bbd8ff}
.rad-period-summary{display:flex;align-items:center;gap:8px 14px;flex-wrap:wrap;background:#eaf2ff;border:1px solid #d8e6ff;padding:12px 16px;border-radius:13px;margin:16px 0;color:#375176;font-size:12px;line-height:1.5}
.rad-period-summary strong{color:#12377e;font-weight:900}
.rad-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0}
.rad-card{position:relative;overflow:hidden;min-width:0;min-height:172px;background:rgba(255,255,255,.96);border:1px solid #dae6f5;border-radius:22px;padding:17px 18px;box-shadow:0 12px 30px rgba(11,31,91,.055);transition:transform .18s ease,box-shadow .18s ease}
.rad-card:hover{transform:translateY(-2px);box-shadow:0 15px 34px rgba(11,31,91,.085)}
.rad-card:after{content:"";position:absolute;right:-40px;top:-44px;width:116px;height:116px;border-radius:50%;background:color-mix(in srgb,var(--admin-accent,#50a2f5) 10%,transparent)}
.rad-card-icon{position:relative;z-index:1;display:grid;place-items:center;width:38px;height:38px;border-radius:13px;background:linear-gradient(135deg,var(--admin-primary,#12377e),var(--admin-secondary,#2563eb));box-shadow:0 8px 20px rgba(11,31,91,.13);color:#fff;font-size:19px}
.rad-card small{position:relative;z-index:1;display:block;margin-top:12px;color:#64748b;font-size:11px;font-weight:900;letter-spacing:.035em;text-transform:uppercase}
.rad-card strong{display:block;margin:6px 0;color:#07163f;font-size:clamp(19px,2.1vw,28px);letter-spacing:-.03em;font-weight:950;line-height:1.2;overflow-wrap:anywhere}
.rad-card p{font-size:11.5px;line-height:1.45;color:#64748b;margin:4px 0 0;overflow-wrap:anywhere}
.rad-delta{display:inline-flex;align-items:center;border-radius:8px;padding:3px 7px;font-weight:900;font-size:11px}
.rad-up{color:#047857!important;background:#ecfdf5}.rad-down{color:#b91c1c!important;background:#fef2f2}.rad-neutral{color:#475569;background:#f1f5f9}
.rad-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:16px 0;align-items:start}
.rad-panel{min-width:0;max-width:100%;overflow-wrap:anywhere;background:rgba(255,255,255,.97);border:1px solid #dce7f5;border-radius:23px;padding:20px 21px;box-shadow:0 12px 30px rgba(11,31,91,.055)}
.rad-panel-head{display:flex;align-items:center;gap:12px;margin-bottom:15px}
.rad-panel-icon{display:grid;place-items:center;flex:0 0 38px;height:38px;background:#edf4ff;border:1px solid #dfebfb;border-radius:12px;font-size:19px}
.rad-panel-head h2{font-size:17px;font-weight:950;color:#07163f;line-height:1.25;letter-spacing:-.025em;margin:0}
.rad-panel-sub{font-size:12px;color:#64748b;margin:3px 0 0;line-height:1.4}
.rad-spotlight{display:flex;align-items:center;gap:13px;min-width:0;background:#f7faff;border:1px solid #e8eff9;border-radius:14px;padding:14px}
.rad-spotlight-star{font-size:23px;width:42px;height:42px;display:grid;place-items:center;flex:none;border-radius:13px;background:#fff;border:1px solid #e0eafb}
.rad-spotlight-info{min-width:0;flex:1}
.rad-spotlight-name{font-size:15px;font-weight:900;color:#102e66;line-height:1.4;overflow-wrap:anywhere}
.rad-spotlight-sub{font-size:12px;color:#60718c;margin-top:5px;overflow-wrap:anywhere}
.rad-evolution{margin:16px 0}.rad-evolution-list{display:grid;gap:8px}
.rad-bar-row{display:grid;grid-template-columns:minmax(82px,116px) minmax(0,1fr) minmax(92px,132px);gap:13px;align-items:center;padding:5px 0;font-size:12px;color:#64748b}
.rad-bar-label{font-weight:800;color:#456088;overflow-wrap:anywhere}
.rad-bar-value{font-size:12px;text-align:right;font-weight:900;color:#1b3a70;overflow-wrap:anywhere}
.rad-track{height:11px;border-radius:99px;background:#edf2fa;overflow:hidden}
.rad-track>div{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--admin-primary,#12377e),var(--admin-secondary,#2563eb))}
.rad-ranking{display:grid;gap:8px}
.rad-rank-item{min-width:0;display:grid;grid-template-columns:30px minmax(0,1fr) minmax(84px,auto);column-gap:11px;align-items:center;padding:11px;border:1px solid #e8eef8;border-radius:14px;background:#f9fbff}
.rad-rank-index{align-self:start;width:27px;height:27px;display:grid;place-items:center;border-radius:9px;background:#e8f0ff;color:#15488b;font-size:11px;font-weight:950}
.rad-rank-info{min-width:0;overflow-wrap:anywhere;word-break:normal}
.rad-rank-name{color:#132c59;font-size:13px;line-height:1.35;font-weight:850;overflow-wrap:anywhere}
.rad-rank-detail{margin-top:3px;font-size:11px;line-height:1.45;color:#64748b;overflow-wrap:anywhere}
.rad-rank-aside{display:grid;align-content:center;gap:3px;text-align:right;min-width:0;max-width:160px}
.rad-rank-count{font-size:11px;color:#64748b;font-weight:750;white-space:normal;overflow-wrap:anywhere}
.rad-rank-aside strong{font-weight:950;font-size:13px;color:#173b7d;white-space:normal;overflow-wrap:anywhere}
.rad-rank-track{margin-top:9px;height:6px;border-radius:99px;overflow:hidden;background:#e6edfa}
.rad-rank-track i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--admin-primary,#12377e),var(--admin-secondary,#2563eb))}
.rad-empty{font-size:13px;color:#64748b;padding:14px 16px;border:1px dashed #cfdaeb;background:#f8fbff;border-radius:12px}
/* Série anual: duas linhas/eixos e detalhes legíveis sem barras de rolagem. */
.rad-annual{margin:16px 0}
.rad-annual-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}
.rad-annual-figures{display:flex;gap:10px;flex-wrap:wrap}
.rad-annual-figure{padding:10px 14px;background:#f4f8ff;border:1px solid #e1eaf8;border-radius:13px;min-width:170px}
.rad-annual-figure small{display:block;color:#60708b;font-size:11px;font-weight:850}
.rad-annual-figure strong{display:block;color:#103e82;font-size:19px;font-weight:950;margin-top:5px}
.rad-annual-figure.ticket{background:#fffaef;border-color:#f7e6bf}
.rad-annual-figure.ticket strong{color:#b66b05}
.rad-annual-legend{display:flex;gap:18px;align-items:center;flex-wrap:wrap;font-size:12px;font-weight:850;color:#53647f;margin:12px 0 5px}
.rad-annual-legend span{display:inline-flex;align-items:center;gap:7px}
.rad-legend-stroke{display:inline-block;width:27px;height:4px;border-radius:3px;background:#2563eb}
.rad-legend-stroke.ticket{background:#df880c}
.rad-annual-chart{max-width:100%;margin-top:7px;border-radius:15px;border:1px solid #e9eff8;background:linear-gradient(180deg,#fcfdff,#f8fbff);padding:9px 3px}
.rad-annual-chart svg{display:block;width:100%;height:auto;overflow:visible;font-family:inherit}
.rad-chart-grid{stroke:#e5edf8;stroke-width:1}
.rad-chart-axis{fill:#71829c;font-size:12px;font-weight:750}
.rad-chart-month{fill:#445e82;font-size:13px;font-weight:850}
.rad-chart-sales{fill:none;stroke:#2563eb;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}
.rad-chart-ticket{fill:none;stroke:#df880c;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}
.rad-chart-point{stroke:#fff;stroke-width:2.5}
.rad-chart-muted{fill:#9ba9ba;font-size:11px}
.rad-annual-hint{margin:8px 0 0;color:#61718b;font-size:11.5px;line-height:1.55}
.rad-annual-details{margin-top:14px;background:#f7faff;border:1px solid #e5edf9;border-radius:13px;padding:11px 13px}
.rad-annual-details summary{cursor:pointer;color:#194584;font-size:12px;font-weight:900}
.rad-annual-breakdown{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
.rad-annual-month{padding:10px 11px;border-radius:10px;border:1px solid #e1eafa;background:#fff;min-width:0}
.rad-annual-month b{font-size:12px;color:#173968;text-transform:uppercase}
.rad-annual-month span{display:block;font-size:11.5px;color:#5a6b85;line-height:1.6;overflow-wrap:anywhere}
.rad-annual-month span strong{font-weight:900;color:#183d79}
.rad-annual-month .ticket-value{color:#ab6a10}
.rad-note{font-size:12px;color:#53647f;line-height:1.65}
.rad-methodology{margin-top:16px}.rad-methodology p{margin:0}
@media(max-width:1140px){.rad-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:800px){.rad-two{grid-template-columns:1fr}.rad-annual-breakdown{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:690px){.rad-hero{padding:21px;border-radius:22px}.rad-filter-card{padding:16px}.rad-date-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.rad-filter-actions{grid-column:1/-1}.rad-date-filter .rad-filter-actions .btn{width:auto}.rad-metrics{gap:10px}.rad-panel{padding:16px}.rad-hero-inner{align-items:flex-start}.rad-bar-row{grid-template-columns:minmax(75px,95px) minmax(0,1fr) minmax(90px,auto);gap:8px}}
@media(max-width:440px){.rad-annual-breakdown{grid-template-columns:repeat(2,minmax(0,1fr))}.rad-annual-figure{min-width:0;flex:1 1 140px}.rad-annual-figure strong{font-size:17px}.rad-annual-chart{padding:7px 0}.rad-metrics{grid-template-columns:1fr}.rad-card{min-height:auto}.rad-rank-item{grid-template-columns:30px minmax(0,1fr)}.rad-rank-aside{grid-column:2;text-align:left;max-width:none;display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap;margin-top:6px}.rad-bar-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5px}.rad-bar-label{grid-column:1}.rad-bar-value{grid-column:2}.rad-track{grid-column:1/-1;grid-row:2}.rad-back{width:100%}}
@media(prefers-reduced-motion:reduce){.rad-card{transition:none}.rad-card:hover{transform:none}}
</style>
<div class="rad-app">
<header class="rad-hero">
 <div class="rad-hero-inner">
  <div>
   <span class="rad-kicker">📊 Inteligência de negócios · Visão histórica</span>
   <h1 class="rad-title">Análise Dashboard</h1>
   <p class="rad-subtitle">Analise o desempenho do seu negócio, encontre os destaques de vendas e compare períodos, com a mesma identidade visual do Dashboard inicial.</p>
  </div>
  <a class="rad-back" href="dashboard.php">← Dashboard inicial</a>
 </div>
</header>
<section class="rad-filter-card">
 <div class="rad-filter-head"><h2>📅 Selecione o período</h2><span class="rad-muted">Comparação automática com o período anterior</span></div>
 <form class="rad-date-filter" method="get" id="radForm">
  <label for="radFrom">De<input id="radFrom" type="date" name="from" value="<?=rad_h($fromStr)?>" required></label>
  <label for="radTo">Até<input id="radTo" type="date" name="to" value="<?=rad_h($toStr)?>" required></label>
  <div class="rad-filter-actions"><span class="rad-actions-caption" aria-hidden="true">Ações</span><div class="rad-action-buttons"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn rad-clear" href="relatorio_analise_dashboard.php">Limpar</a></div></div>
 </form>
 <nav class="rad-periods" aria-label="Períodos rápidos">
  <?php foreach ($quickRanges as [$rangeKey, $rangeTitle, $rangeFrom, $rangeTo]):
    $rangeActive = $fromStr === $rangeFrom && $toStr === $rangeTo;
    $rangeHref = 'relatorio_analise_dashboard.php?'.http_build_query(['from'=>$rangeFrom,'to'=>$rangeTo], '', '&', PHP_QUERY_RFC3986);
  ?>
   <a href="<?=rad_h($rangeHref)?>" class="<?=$rangeActive?'is-active':''?>" <?= $rangeActive ? 'aria-current="page"' : '' ?>><?=rad_h($rangeTitle)?></a>
  <?php endforeach; ?>
 </nav>
</section>
<?php foreach ($errors as $error): ?><div class="msg err" style="margin-top:12px"><?=rad_h($error)?></div><?php endforeach; ?>
<?php foreach (array_unique($warnings) as $warning): ?><div class="msg" style="margin-top:12px;background:#fff8ee;border:1px solid #f9d79a;color:#9a560a"><?=rad_h($warning)?></div><?php endforeach; ?>
<?php if (!$errors): ?>
<div class="rad-period-summary"><span>📅 Período analisado: <strong><?=rad_h($from->format('d/m/Y'))?> a <?=rad_h($to->format('d/m/Y'))?></strong> (<?=$days?> dia(s))</span><span>↔ Comparação: <strong><?=rad_h($prevFrom->format('d/m/Y'))?> a <?=rad_h($previousEnd ? (new DateTimeImmutable($previousEnd))->format('d/m/Y') : '')?></strong></span><span>Valores sem pedidos cancelados, exceto status e taxas.</span></div>
<div class="rad-metrics">
 <?php
 $cards = [
  ['Total vendido',rad_money($summary['faturamento']??0),rad_delta((float)($summary['faturamento']??0),(float)($compare['faturamento']??0)),'somente o período filtrado'],
  ['Venda do mês',rad_money($monthSummary['faturamento']??0),null,$monthLabel.' · mês completo ('.$monthRangeLabel.'). Referência: data Até.'],
  ['Pedidos válidos',rad_num($summary['pedidos_validos']??0),rad_delta((float)($summary['pedidos_validos']??0),(float)($compare['pedidos_validos']??0)),'sem cancelados'],
  ['Ticket médio',rad_money($ticket),rad_delta($ticket,((int)($compare['pedidos_validos']??0)>0?(float)($compare['faturamento']??0)/(int)$compare['pedidos_validos']:0.0)),'por pedido válido'],
  ['Média vendida por dia',rad_money($averageDaily),rad_delta($averageDaily,(float)($compare['faturamento']??0)/$days),'por dia do período'],
  ['Pedidos finalizados',rad_num($summary['finalizados']??0),rad_delta((float)($summary['finalizados']??0),(float)($compare['finalizados']??0)),'status atual de pedidos criados no período'],
  ['Taxa de conclusão',rad_num($finalRate,1).'%',null,'do total de pedidos criados'],
  ['Pedidos cancelados',rad_num($summary['cancelados']??0),rad_delta((float)($summary['cancelados']??0),(float)($compare['cancelados']??0)),'no período selecionado'],
  ['Taxa de cancelamento',rad_num($cancelRate,1).'%',null,'do total de pedidos criados'],
 ];
 $cards = array_merge($cards, $extraKpis);
 $cardIcons = ['💰','🗓️','🧾','🎯','📈','✅','🏁','↩️','📉','🍽️','🛠️','✅'];
 foreach ($cards as $cardIndex => [$label,$value,$delta,$desc]):
   $deltaClass = $delta === null ? 'rad-neutral' : (str_starts_with($delta, '+') ? 'rad-up' : (str_starts_with($delta, '-') ? 'rad-down' : 'rad-neutral'));
 ?>
 <article class="rad-card"><span class="rad-card-icon" aria-hidden="true"><?=rad_h($cardIcons[$cardIndex]??'📊')?></span><small><?=rad_h($label)?></small><strong><?=rad_h($value)?></strong><?php if($delta!==null): ?><p><span class="rad-delta <?=rad_h($deltaClass)?>"><?=rad_h($delta)?></span> em relação ao período anterior</p><?php endif; ?><p><?=rad_h($desc)?></p></article>
 <?php endforeach; ?>
</div>
<div class="rad-two">
 <section class="rad-panel"><div class="rad-panel-head"><span class="rad-panel-icon">🏆</span><div><h2>Produto mais vendido</h2><p class="rad-panel-sub">Destaque em quantidade no período</p></div></div><?php if($topProduct): ?><div class="rad-spotlight"><div class="rad-spotlight-star">📦</div><div class="rad-spotlight-info"><div class="rad-spotlight-name"><?=rad_h($topProduct['nome'])?></div><div class="rad-spotlight-sub"><?=rad_num($topProduct['qtd'],3)?> un. · <?=rad_money($topProduct['valor'])?></div></div></div><?php else: ?><p class="rad-empty">Nenhum produto vendido no período.</p><?php endif; ?></section>
 <section class="rad-panel"><div class="rad-panel-head"><span class="rad-panel-icon">🏅</span><div><h2>Serviço mais vendido</h2><p class="rad-panel-sub">Destaque dos serviços prestados</p></div></div><?php if($topService): ?><div class="rad-spotlight"><div class="rad-spotlight-star">🛠️</div><div class="rad-spotlight-info"><div class="rad-spotlight-name"><?=rad_h($topService['nome'])?></div><div class="rad-spotlight-sub"><?=rad_num($topService['qtd'],3)?> un. · <?=rad_money($topService['valor'])?></div></div></div><?php else: ?><p class="rad-empty"><?=$hasServiceColumn?'Nenhum serviço vendido no período.':'Classificação de serviços não disponível nesta instalação.'?></p><?php endif; ?></section>
</div>
<section class="rad-panel rad-annual" aria-labelledby="radAnnualTitle">
 <div class="rad-annual-head">
  <div class="rad-panel-head"><span class="rad-panel-icon" aria-hidden="true">📊</span><div>
   <h2 id="radAnnualTitle">Vendas e ticket médio — <?=rad_h($annualYear)?></h2>
   <p class="rad-panel-sub">Janeiro a dezembro do ano da data <b>Até</b> · valores mensais, independentemente do intervalo diário selecionado</p>
  </div></div>
  <div class="rad-annual-figures" aria-label="Totais do ano">
   <div class="rad-annual-figure"><small>Vendas no ano</small><strong><?=rad_money($annualSalesTotal)?></strong></div>
   <div class="rad-annual-figure ticket"><small>Ticket médio do ano</small><strong><?=rad_money($annualTicketTotal)?></strong></div>
  </div>
 </div>
 <div class="rad-annual-legend" aria-label="Legenda do gráfico">
  <span><i class="rad-legend-stroke" aria-hidden="true"></i>Vendas por mês · eixo esquerdo</span>
  <span><i class="rad-legend-stroke ticket" aria-hidden="true"></i>Ticket médio · eixo direito</span>
 </div>
 <?php if (!$annualAvailable): ?>
 <p class="rad-empty">Os dados do gráfico anual estão indisponíveis nesta consulta.</p>
 <?php elseif ($annualOrdersTotal === 0): ?>
 <p class="rad-empty">Nenhum pedido válido no ano selecionado. As vendas e o ticket médio serão apresentados quando houver movimentação.</p>
 <?php else: ?>
 <div class="rad-annual-chart">
  <svg viewBox="0 0 1165 347" role="img" aria-label="Vendas mensais e ticket médio de <?=rad_h($annualYear)?>, com duas escalas em reais">
   <defs>
    <linearGradient id="radAnnualFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#3b82f6" stop-opacity=".15"/><stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/></linearGradient>
   </defs>
   <?php for($step=0;$step<=4;$step++):
     $tickY=$annualPlotBottom-($annualPlotBottom-$annualPlotTop)*$step/4;
   ?>
   <line class="rad-chart-grid" x1="<?=$annualPlotLeft?>" y1="<?=number_format($tickY,1,'.','')?>" x2="<?=$annualPlotRight?>" y2="<?=number_format($tickY,1,'.','')?>"/>
   <text class="rad-chart-axis" text-anchor="end" x="84" y="<?=number_format($tickY+4,1,'.','')?>"><?=rad_h(rad_money_short($annualMaxSales*$step/4))?></text>
   <text class="rad-chart-axis" text-anchor="start" x="1075" y="<?=number_format($tickY+4,1,'.','')?>"><?=rad_h(rad_money_short($annualMaxTicket*$step/4))?></text>
   <?php endfor; ?>
   <?php if(count($annualSalePoints)>1):
     $firstX = $annualCoordinates[0]['x'];
     $lastX = $annualCoordinates[count($annualSalePoints)-1]['x'];
     $closedPolygon = implode(' ', $annualSalePoints).' '.sprintf('%.1f,%.1f %.1f,%.1f', $lastX,$annualPlotBottom,$firstX,$annualPlotBottom);
   ?>
    <polygon points="<?=rad_h($closedPolygon)?>" fill="url(#radAnnualFill)"/>
   <?php endif; ?>
   <?php if(count($annualSalePoints)>1): ?><polyline class="rad-chart-sales" points="<?=rad_h(implode(' ', $annualSalePoints))?>"/><?php endif; ?>
   <?php foreach($annualTicketSegments as $segment): if(count($segment)>1): ?>
    <polyline class="rad-chart-ticket" points="<?=rad_h(implode(' ', $segment))?>"/>
   <?php endif; endforeach; ?>
   <?php foreach($annualMonths as $i=>$m):
     $cx = $annualCoordinates[$i]['x'];
     $cySale = $annualCoordinates[$i]['sale_y'];
     $cyTicket = $annualCoordinates[$i]['ticket_y'];
   ?>
    <text class="rad-chart-month" text-anchor="middle" x="<?=number_format($cx,1,'.','')?>" y="329"><?=rad_h($m['label'])?></text>
    <?php if(empty($m['future'])): ?>
    <circle class="rad-chart-point" cx="<?=number_format($cx,1,'.','')?>" cy="<?=number_format($cySale,1,'.','')?>" r="5" fill="#2563eb"><title><?=rad_h($m['mes'])?> · Vendas: <?=rad_h(rad_money($m['sales']))?> · <?=rad_num($m['orders'])?> pedidos válidos</title></circle>
    <?php if($cyTicket !== null): ?><circle class="rad-chart-point" cx="<?=number_format($cx,1,'.','')?>" cy="<?=number_format($cyTicket,1,'.','')?>" r="5" fill="#df880c"><title><?=rad_h($m['mes'])?> · Ticket médio: <?=rad_h(rad_money($m['ticket']))?> · <?=rad_num($m['orders'])?> pedidos válidos</title></circle><?php endif; ?>
    <?php else: ?><text class="rad-chart-muted" text-anchor="middle" x="<?=number_format($cx,1,'.','')?>" y="290">—</text><?php endif; ?>
   <?php endforeach; ?>
  </svg>
 </div>
 <p class="rad-annual-hint">Passe o cursor sobre os pontos para consultar cada mês. Meses sem pedidos válidos não possuem ticket médio; meses futuros não são tratados como queda de vendas. No celular, abra os valores mensais abaixo.</p>
 <details class="rad-annual-details"><summary>Ver valores mês a mês (vendas, pedidos e ticket médio)</summary>
  <div class="rad-annual-breakdown">
   <?php foreach($annualMonths as $m): ?>
   <div class="rad-annual-month"><b><?=rad_h($m['label'])?>/<?=rad_h($annualYear)?></b>
    <?php if($m['future']): ?><span>Mês futuro</span>
    <?php else: ?>
     <span>Vendas: <strong><?=rad_money($m['sales'])?></strong></span>
     <span>Pedidos: <?=rad_num($m['orders'])?></span>
     <span>Ticket: <strong class="ticket-value"><?=$m['ticket']===null?'—':rad_money($m['ticket'])?></strong></span>
    <?php endif; ?>
   </div>
   <?php endforeach; ?>
  </div>
 </details>
 <?php endif; ?>
</section>
<section class="rad-panel rad-evolution"><div class="rad-panel-head"><span class="rad-panel-icon">📈</span><div><h2>Evolução de vendas</h2><p class="rad-panel-sub">Receita <?=$days<=62?'dia a dia':'mês a mês'?> no intervalo selecionado</p></div></div>
<?php if (!$series): ?><p class="rad-empty">Sem vendas válidas no período.</p><?php endif; ?>
<div class="rad-evolution-list"><?php foreach ($series as $r): $width = min(100,max(0,100*(float)$r['valor']/$maxSeries)); ?><div class="rad-bar-row"><span class="rad-bar-label"><?=rad_h($r['periodo'])?></span><div class="rad-track"><div style="width:<?=number_format($width,2,'.','')?>%"></div></div><strong class="rad-bar-value"><?=rad_money($r['valor'])?></strong></div><?php endforeach; ?></div></section>
<div class="rad-two">
<?php
$tables = [
 ['Produtos mais vendidos',$products,['nome'=>'Produto','qtd'=>'Quantidade','valor'=>'Vendido']],
 ['Serviços mais vendidos',$services,['nome'=>'Serviço','qtd'=>'Quantidade','valor'=>'Vendido']],
 ['Vendedores — pedidos finalizados',$sellers,['vendedor'=>'Vendedor','qtd'=>'Pedidos','valor'=>'Vendido']],
 ['Formas de pagamento (resumo do pedido)',$payments,['rotulo'=>'Pagamento','qtd'=>'Pedidos','valor'=>'Vendido']],
 ['Status dos pedidos',$status,['rotulo'=>'Status atual','qtd'=>'Pedidos']],
 ['Origem e atendimento',$channels,['origem'=>'Origem','atendimento'=>'Atendimento','qtd'=>'Pedidos','valor'=>'Vendido']],
];
$panelIcons = ['📦','🛠️','👤','💳','📋','🧭'];
$panelSubtitles = ['Ranking por quantidade','Ranking por quantidade','Ranking por valor finalizado','Distribuição do valor das vendas','Situação atual dos pedidos','Canais e formas de atendimento'];
foreach($tables as $panelIndex => [$title,$rows,$columns]):
  $maxValue = max([1.0, ...array_map(static fn(array $r):float => (float)($r['valor'] ?? $r['qtd'] ?? 0),$rows)]);
  $isItem = array_key_exists('nome',$columns);
?>
<section class="rad-panel">
 <div class="rad-panel-head"><span class="rad-panel-icon" aria-hidden="true"><?=rad_h($panelIcons[$panelIndex]??'📊')?></span><div><h2><?=rad_h($title)?></h2><p class="rad-panel-sub"><?=rad_h($panelSubtitles[$panelIndex]??'Análise do período')?> · <?=count($rows)?> registro(s)</p></div></div>
 <?php if (!$rows): ?><p class="rad-empty">Sem informações no período.</p><?php else: ?>
 <div class="rad-ranking">
  <?php foreach ($rows as $rowIndex=>$r):
    $name = (string)($r['nome']??$r['vendedor']??$r['rotulo']??$r['origem']??'—');
    $detail = array_key_exists('atendimento',$columns) ? 'Atendimento: '.(string)($r['atendimento']??'Não informado') : '';
    $volume = (float)($r['valor']??$r['qtd']??0);
    $width = max(0,min(100,100 * $volume / $maxValue));
  ?>
  <div class="rad-rank-item">
   <span class="rad-rank-index" aria-hidden="true"><?=str_pad((string)($rowIndex+1),2,'0',STR_PAD_LEFT)?></span>
   <div class="rad-rank-info"><div class="rad-rank-name"><?=rad_h($name)?></div><?php if ($detail!==''): ?><div class="rad-rank-detail"><?=rad_h($detail)?></div><?php endif; ?><div class="rad-rank-track" aria-hidden="true"><i style="width:<?=number_format($width,2,'.','')?>%"></i></div></div>
   <div class="rad-rank-aside"><span class="rad-rank-count"><?=rad_num($r['qtd']??0,$isItem?3:0)?> <?= $isItem ? 'un.' : 'pedido(s)' ?></span><?php if(array_key_exists('valor',$columns)): ?><strong><?=rad_money($r['valor']??0)?></strong><?php endif; ?></div>
  </div>
  <?php endforeach; ?>
 </div>
 <?php endif; ?>
</section>
<?php endforeach; ?>
</div>
<div class="rad-panel rad-methodology"><p class="rad-note"><strong>Como interpretar os números.</strong> Critérios: vendas por <b>data de criação</b> do pedido; valor de venda corresponde ao total gravado no pedido, não à conciliação financeira nem ao documento fiscal autorizado. Status consultado é o <b>atual</b> de pedidos originados no período, não o status que possuíam naquela data. Produtos e serviços exibem sua classificação e descrição cadastral atual. Indicadores exclusivamente em tempo real do Dashboard original (como estoque atual, fila de exportação e pedidos abertos agora) não são reconstruídos retroativamente sem histórico de eventos. O gráfico anual considera os 12 meses do ano da data Até (sem ficar limitado ao intervalo diário pesquisado); o ticket mensal corresponde a vendas do mês divididas por pedidos não cancelados daquele mês, e não deve ser calculado pela média simples dos tickets de cada dia. A forma de pagamento reflete o campo-resumo da venda, que pode não detalhar múltiplas formas no mesmo pedido.</p></div>
<?php endif; ?>
</div><!-- /.rad-app -->
<?php admin_footer(); ?>
