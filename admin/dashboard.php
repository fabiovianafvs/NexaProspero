<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';

// V5: primeira área operacional habilitada para o contexto Multiempresa.
// A função é fail-safe e retorna COMPANY_ID para qualquer empresa sem o módulo
// Multiempresa, token inválido, usuário sem acesso ou escopo ainda não homologado.
$dashCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'dashboard')
  : (int)COMPANY_ID;
$dashContextOperational = $dashCompanyId !== (int)COMPANY_ID;
$dashCompany = function_exists('multi_company_context_company_row')
  ? multi_company_context_company_row($pdo, $dashCompanyId)
  : null;
$dashCompanyName = trim((string)($dashCompany['name'] ?? '')) ?: ('Empresa #' . $dashCompanyId);

// "Hoje" e CURDATE() precisam respeitar também o timezone da empresa escolhida.
// Este ajuste vale somente para a requisição do Dashboard.
if ($dashContextOperational && function_exists('load_business_config') && function_exists('app_apply_company_timezone')) {
  try {
    $dashBusinessCfg = load_business_config($dashCompanyId);
    app_apply_company_timezone($pdo, (string)($dashBusinessCfg['timezone'] ?? 'America/Sao_Paulo'));
  } catch (Throwable $e) {
    // Fail-soft: os indicadores continuam disponíveis com o timezone do tenant de entrada.
  }
}

function dash_money(float $value): string {
  return 'R$ ' . number_format($value, 2, ',', '.');
}

function dash_money_short(float $value): string {
  $abs = abs($value);
  if ($abs >= 1000000) return 'R$ ' . number_format($value / 1000000, 1, ',', '.') . ' mi';
  if ($abs >= 1000) return 'R$ ' . number_format($value / 1000, 1, ',', '.') . ' mil';
  return dash_money($value);
}


function dash_int($value): string {
  return number_format((float)$value, 0, ',', '.');
}

function dash_pct(float $value): string {
  return number_format($value, 1, ',', '.') . '%';
}

function dash_duration(float $seconds): string {
  if ($seconds <= 0) return '—';
  $minutes = max(1, (int)round($seconds / 60));
  if ($minutes < 60) return $minutes . ' min';
  $hours = intdiv($minutes, 60);
  $rest = $minutes % 60;
  return $hours . 'h' . ($rest > 0 ? ' ' . $rest . 'min' : '');
}

function dash_duration_days_hours(float $seconds): string {
  if ($seconds <= 0) return '—';
  $totalMinutes = max(1, (int)round($seconds / 60));
  $days = intdiv($totalMinutes, 1440);
  $hours = intdiv($totalMinutes % 1440, 60);
  $minutes = $totalMinutes % 60;
  if ($days > 0) return $days . 'd ' . $hours . 'h';
  if ($hours > 0) return $hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
  return $minutes . 'min';
}

function dash_safe_hex_color(string $color): string {
  $color = trim($color);
  return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#64748b';
}

function dash_delta(float $current, float $previous, bool $money = false): array {
  if ($previous <= 0 && $current > 0) {
    return ['label' => '+100%', 'class' => 'up'];
  }
  if ($previous <= 0 && $current <= 0) {
    return ['label' => '0%', 'class' => 'neutral'];
  }
  $pct = (($current - $previous) / $previous) * 100;
  return [
    'label' => ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%',
    'class' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'neutral'),
  ];
}

function dash_status_label(string $status): string {
  $labels = [
    'aberto' => 'Aberto',
    'confirmado' => 'Confirmado',
    'em_producao' => 'Em produção',
    'saiu_entrega' => 'Saiu para entrega',
    'finalizado' => 'Finalizado',
    'cancelado' => 'Cancelado',
  ];
  return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function dash_status_class(string $status): string {
  return preg_replace('/[^a-z0-9_-]/i', '', $status) ?: 'outro';
}

$today = date('Y-m-d');

// Pedidos e faturamento hoje
$st = $pdo->prepare("
  SELECT COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
  FROM pedidos
  WHERE company_id = ?
    AND DATE(created_at) = CURDATE()
    AND status <> 'cancelado'
");
$st->execute([$dashCompanyId]);
$hojeRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd'=>0,'soma'=>0];

// Ontem para comparação
$st = $pdo->prepare("
  SELECT COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
  FROM pedidos
  WHERE company_id = ?
    AND DATE(created_at) = (CURDATE() - INTERVAL 1 DAY)
    AND status <> 'cancelado'
");
$st->execute([$dashCompanyId]);
$ontemRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd'=>0,'soma'=>0];

$deltaPedidosHoje = dash_delta((float)$hojeRow['qtd'], (float)$ontemRow['qtd']);
$deltaFatHoje = dash_delta((float)$hojeRow['soma'], (float)$ontemRow['soma'], true);
$dashHasExportacaoModule = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, $dashCompanyId, 'exportacao') : true;

// Pedidos em andamento
$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM pedidos
  WHERE company_id = ?
    AND status IN ('aberto','confirmado','em_producao','saiu_entrega')
");
$st->execute([$dashCompanyId]);
$abertos = (int)$st->fetchColumn();

// Exportação pendente e com erro
$pendExport = 0;
$errExport = 0;
if ($dashHasExportacaoModule) {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM pedidos_exportacao WHERE company_id = ? AND exportado = 0");
    $st->execute([$dashCompanyId]);
    $pendExport = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $pendExport = 0;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM pedidos_exportacao
      WHERE company_id = ?
        AND exportado = 0
        AND ultimo_erro IS NOT NULL
        AND ultimo_erro <> ''
    ");
    $st->execute([$dashCompanyId]);
    $errExport = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $errExport = 0;
  }
}

// Mês corrente
$st = $pdo->prepare("
  SELECT COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
  FROM pedidos
  WHERE company_id = ?
    AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    AND status <> 'cancelado'
");
$st->execute([$dashCompanyId]);
$mesRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd'=>0,'soma'=>0];

// Mês anterior, mesmo período aproximado
$st = $pdo->prepare("
  SELECT COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
  FROM pedidos
  WHERE company_id = ?
    AND created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
    AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')
    AND status <> 'cancelado'
");
$st->execute([$dashCompanyId]);
$mesAnteriorRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd'=>0,'soma'=>0];

$deltaMes = dash_delta((float)$mesRow['soma'], (float)$mesAnteriorRow['soma'], true);

// Ticket médio e visão 30 dias
$st = $pdo->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN status <> 'cancelado' THEN total ELSE 0 END),0) AS soma,
    SUM(CASE WHEN status <> 'cancelado' THEN 1 ELSE 0 END) AS qtd_validos,
    COUNT(*) AS qtd,
    SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
    SUM(CASE WHEN status = 'finalizado' THEN 1 ELSE 0 END) AS finalizados
  FROM pedidos
  WHERE company_id = ?
    AND created_at >= (CURDATE() - INTERVAL 30 DAY)
");
$st->execute([$dashCompanyId]);
$t30 = $st->fetch(PDO::FETCH_ASSOC) ?: ['soma'=>0,'qtd_validos'=>0,'qtd'=>0,'cancelados'=>0,'finalizados'=>0];

$total30 = max(0, (int)($t30['qtd'] ?? 0));
$totalValidos30 = max(0, (int)($t30['qtd_validos'] ?? 0));
$ticketMedio = $totalValidos30 > 0 ? ((float)$t30['soma'] / $totalValidos30) : 0.0;
$taxaConclusao = $total30 > 0 ? (((int)$t30['finalizados'] / $total30) * 100) : 0.0;
$taxaCancelamento = $total30 > 0 ? (((int)$t30['cancelados'] / $total30) * 100) : 0.0;

// Produtos ativos
try {
  $st = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE company_id = ? AND ativo = 1");
  $st->execute([$dashCompanyId]);
  $produtosAtivos = (int)$st->fetchColumn();
} catch (Throwable $e) {
  $produtosAtivos = 0;
}

// Série últimos 7 dias, preenchendo dias sem venda
$st = $pdo->prepare("
  SELECT DATE(created_at) AS d, COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
  FROM pedidos
  WHERE company_id = ?
    AND created_at >= (CURDATE() - INTERVAL 6 DAY)
    AND status <> 'cancelado'
  GROUP BY DATE(created_at)
  ORDER BY d
");
$st->execute([$dashCompanyId]);
$serieRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$serieMap = [];
foreach ($serieRows as $r) {
  $serieMap[(string)$r['d']] = [
    'd' => (string)$r['d'],
    'qtd' => (int)$r['qtd'],
    'soma' => (float)$r['soma'],
  ];
}

$serie7 = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $serie7[] = $serieMap[$d] ?? ['d'=>$d, 'qtd'=>0, 'soma'=>0.0];
}

// Top produtos (30 dias)
$topProdutos = [];
try {
  $st = $pdo->prepare("
    SELECT p.nome, SUM(pi.qtd) AS qtd, COALESCE(SUM(pi.total),0) AS soma
    FROM pedido_itens pi
    JOIN pedidos pe ON pe.id = pi.pedido_id AND pe.company_id = pi.company_id
    JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pe.company_id
    WHERE pe.company_id = ?
      AND pe.created_at >= (CURDATE() - INTERVAL 30 DAY)
      AND pe.status <> 'cancelado'
    GROUP BY p.id, p.nome
    ORDER BY qtd DESC
    LIMIT 5
  ");
  $st->execute([$dashCompanyId]);
  $topProdutos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $topProdutos = [];
}

// Status dos pedidos
$statusRows = [];
try {
  $st = $pdo->prepare("
    SELECT status, COUNT(*) AS qtd
    FROM pedidos
    WHERE company_id = ?
      AND created_at >= (CURDATE() - INTERVAL 30 DAY)
    GROUP BY status
  ");
  $st->execute([$dashCompanyId]);
  $statusRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $statusRows = [];
}

$statusMap = [
  'aberto' => 0,
  'confirmado' => 0,
  'em_producao' => 0,
  'saiu_entrega' => 0,
  'finalizado' => 0,
  'cancelado' => 0,
];
foreach ($statusRows as $sr) {
  $statusMap[(string)$sr['status']] = (int)$sr['qtd'];
}

// Origem/tipo dos pedidos — últimos 30 dias
$orderChannelMap = [
  'delivery_retirada' => 0,
  'delivery_entrega' => 0,
  'balcao' => 0,
];
try {
  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN LOWER(COALESCE(source,''))='delivery' AND LOWER(COALESCE(tipo_entrega,''))='retirada' THEN 1 ELSE 0 END) AS delivery_retirada,
      SUM(CASE WHEN LOWER(COALESCE(source,''))='delivery' AND LOWER(COALESCE(tipo_entrega,''))='entrega' THEN 1 ELSE 0 END) AS delivery_entrega,
      SUM(CASE WHEN LOWER(COALESCE(tipo_entrega,''))='balcao' THEN 1 ELSE 0 END) AS balcao
    FROM pedidos
    WHERE company_id = ?
      AND created_at >= (CURDATE() - INTERVAL 30 DAY)
      AND status <> 'cancelado'
  ");
  $st->execute([$dashCompanyId]);
  $channelRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  foreach ($orderChannelMap as $key => $_) $orderChannelMap[$key] = (int)($channelRow[$key] ?? 0);
} catch (Throwable $e) {
  $orderChannelMap = ['delivery_retirada'=>0,'delivery_entrega'=>0,'balcao'=>0];
}
$orderChannelTotal = array_sum($orderChannelMap);

// Ranking de vendedores — últimos 30 dias
$topSellers = [];
try {
  $st = $pdo->prepare("
    SELECT COALESCE(NULLIF(fp.name,''), CONCAT('Vendedor #', pe.seller_id)) AS vendedor,
           COUNT(*) AS qtd,
           COALESCE(SUM(pe.total),0) AS total
    FROM pedidos pe
    LEFT JOIN finance_people fp ON fp.company_id=pe.company_id AND fp.id=pe.seller_id
    WHERE pe.company_id = ?
      AND pe.created_at >= (CURDATE() - INTERVAL 30 DAY)
      AND pe.status = 'finalizado'
      AND pe.seller_id IS NOT NULL
      AND pe.seller_id > 0
    GROUP BY pe.seller_id, COALESCE(NULLIF(fp.name,''), CONCAT('Vendedor #', pe.seller_id))
    ORDER BY total DESC, qtd DESC, vendedor ASC
    LIMIT 5
  ");
  $st->execute([$dashCompanyId]);
  $topSellers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $topSellers = [];
}
$maxSellerTotal = 0.0;
foreach ($topSellers as $sellerRow) $maxSellerTotal = max($maxSellerTotal, (float)($sellerRow['total'] ?? 0));
if ($maxSellerTotal <= 0) $maxSellerTotal = 1.0;

// Comandas — aparece somente quando o módulo está habilitado.
$dashHasComandasModule = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, $dashCompanyId, 'comandas') : false;
$comandaStats = ['finalizadas_30d'=>0,'finalizadas_hoje'=>0,'abertas_agora'=>0,'avg_seconds'=>0.0];
if ($dashHasComandasModule) {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*) AS finalizadas_30d,
             SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS finalizadas_hoje
      FROM pedidos
      WHERE company_id=?
        AND LOWER(COALESCE(source,''))='comanda'
        AND created_at >= (CURDATE() - INTERVAL 30 DAY)
        AND status = 'finalizado'
    ");
    $st->execute([$dashCompanyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $comandaStats['finalizadas_30d'] = (int)($row['finalizadas_30d'] ?? 0);
    $comandaStats['finalizadas_hoje'] = (int)($row['finalizadas_hoje'] ?? 0);

    $st = $pdo->prepare("SELECT COUNT(*) FROM comandas WHERE company_id=? AND status='OCUPADA'");
    $st->execute([$dashCompanyId]);
    $comandaStats['abertas_agora'] = (int)$st->fetchColumn();

    // O primeiro item é o momento em que a comanda passa a OCUPADA no fluxo atual.
    $st = $pdo->prepare("
      SELECT AVG(TIMESTAMPDIFF(SECOND, x.opened_at, pe.created_at)) AS avg_seconds
      FROM pedidos pe
      JOIN (
        SELECT company_id, pedido_id, MIN(created_at) AS opened_at
        FROM comanda_items
        WHERE company_id=? AND pedido_id IS NOT NULL
        GROUP BY company_id, pedido_id
      ) x ON x.company_id=pe.company_id AND x.pedido_id=pe.id
      WHERE pe.company_id=?
        AND LOWER(COALESCE(pe.source,''))='comanda'
        AND pe.created_at >= (CURDATE() - INTERVAL 30 DAY)
        AND pe.status = 'finalizado'
        AND x.opened_at IS NOT NULL
        AND x.opened_at <= pe.created_at
    ");
    $st->execute([$dashCompanyId, $dashCompanyId]);
    $comandaStats['avg_seconds'] = (float)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    $comandaStats = ['finalizadas_30d'=>0,'finalizadas_hoje'=>0,'abertas_agora'=>0,'avg_seconds'=>0.0];
  }
}

// Ordem de Serviço — aparece somente quando o módulo está habilitado.
$dashHasOsModule = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, $dashCompanyId, 'ordem_servico') : false;
$osStatusRows = [];
$osStats = ['total'=>0,'finalizadas'=>0,'avg_seconds'=>0.0];
if ($dashHasOsModule) {
  try {
    $st = $pdo->prepare("
      SELECT s.id,s.name,s.color,s.sort_order,s.active,s.is_final,COUNT(o.id) AS qtd
      FROM os_statuses s
      LEFT JOIN os_orders o ON o.company_id=s.company_id AND o.status_id=s.id
      WHERE s.company_id=?
      GROUP BY s.id,s.name,s.color,s.sort_order,s.active,s.is_final
      HAVING s.active=1 OR COUNT(o.id)>0
      ORDER BY s.sort_order ASC,s.name ASC,s.id ASC
    ");
    $st->execute([$dashCompanyId]);
    $osStatusRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($osStatusRows as $osRow) $osStats['total'] += (int)($osRow['qtd'] ?? 0);

    $st = $pdo->prepare("
      SELECT COUNT(*) AS finalizadas,
             AVG(TIMESTAMPDIFF(SECOND,COALESCE(opened_at,created_at),closed_at)) AS avg_seconds
      FROM os_orders
      WHERE company_id=?
        AND closed_at IS NOT NULL
        AND COALESCE(opened_at,created_at) IS NOT NULL
        AND closed_at >= COALESCE(opened_at,created_at)
    ");
    $st->execute([$dashCompanyId]);
    $osRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $osStats['finalizadas'] = (int)($osRow['finalizadas'] ?? 0);
    $osStats['avg_seconds'] = (float)($osRow['avg_seconds'] ?? 0);
  } catch (Throwable $e) {
    $osStatusRows = [];
    $osStats = ['total'=>0,'finalizadas'=>0,'avg_seconds'=>0.0];
  }
}

// Pagamentos
$pagamentos = [];
try {
  $st = $pdo->prepare("
    SELECT forma_pagamento, COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
    FROM pedidos
    WHERE company_id = ?
      AND created_at >= (CURDATE() - INTERVAL 30 DAY)
      AND status <> 'cancelado'
    GROUP BY forma_pagamento
    ORDER BY qtd DESC
    LIMIT 4
  ");
  $st->execute([$dashCompanyId]);
  $pagamentos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $pagamentos = [];
}

// Vendas dos últimos 12 meses, comparando com o mesmo mês do ano anterior
$monthNamesPt = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
$baseMonth = new DateTimeImmutable('first day of this month');
$rangeStart = $baseMonth->modify('-11 months');
$rangeEnd = $baseMonth->modify('+1 month');
$previousRangeStart = $rangeStart->modify('-1 year');

$vendas12Rows = [];
try {
  $st = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
    FROM pedidos
    WHERE company_id = ?
      AND created_at >= ?
      AND created_at < ?
      AND status <> 'cancelado'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
  ");
  $st->execute([$dashCompanyId, $previousRangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
  $vendas12Rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $vendas12Rows = [];
}

$vendas12Map = [];
foreach ($vendas12Rows as $r) {
  $vendas12Map[(string)$r['ym']] = ['qtd' => (int)($r['qtd'] ?? 0), 'soma' => (float)($r['soma'] ?? 0)];
}

$vendas12 = [];
$totalVendas12Atual = 0.0;
$totalVendas12Anterior = 0.0;
$maxVendas12 = 0.0;
for ($i = 11; $i >= 0; $i--) {
  $dt = $baseMonth->modify('-' . $i . ' months');
  $ym = $dt->format('Y-m');
  $prevYm = $dt->modify('-1 year')->format('Y-m');
  $cur = $vendas12Map[$ym] ?? ['qtd'=>0, 'soma'=>0.0];
  $prev = $vendas12Map[$prevYm] ?? ['qtd'=>0, 'soma'=>0.0];
  $totalVendas12Atual += (float)$cur['soma'];
  $totalVendas12Anterior += (float)$prev['soma'];
  $maxVendas12 = max($maxVendas12, (float)$cur['soma'], (float)$prev['soma']);
  $vendas12[] = [
    'label' => $monthNamesPt[(int)$dt->format('n')] . '/' . $dt->format('y'),
    'current_label' => $dt->format('m/Y'),
    'previous_label' => $dt->modify('-1 year')->format('m/Y'),
    'current_total' => (float)$cur['soma'],
    'previous_total' => (float)$prev['soma'],
    'current_qtd' => (int)$cur['qtd'],
    'previous_qtd' => (int)$prev['qtd'],
  ];
}
if ($maxVendas12 <= 0) $maxVendas12 = 1.0;
$deltaVendas12 = dash_delta($totalVendas12Atual, $totalVendas12Anterior, true);


$maxSerie = 0.0;
foreach ($serie7 as $r) $maxSerie = max($maxSerie, (float)$r['soma']);
if ($maxSerie <= 0) $maxSerie = 1.0;

$maxTop = 0.0;
foreach ($topProdutos as $tp) $maxTop = max($maxTop, (float)$tp['qtd']);
if ($maxTop <= 0) $maxTop = 1.0;

$saude = 100;
if ($errExport > 0) $saude -= 25;
if ($pendExport > 0) $saude -= 12;
if ($abertos > 15) $saude -= 10;
if ($taxaCancelamento > 15) $saude -= 18;
$saude = max(0, min(100, $saude));

$saudeLabel = $saude >= 85 ? 'Operação saudável' : ($saude >= 65 ? 'Atenção operacional' : 'Precisa de ação');
$saudeClass = $saude >= 85 ? 'ok' : ($saude >= 65 ? 'warn' : 'danger');

$topProdutoNome = $topProdutos ? (string)$topProdutos[0]['nome'] : 'Sem vendas ainda';
$topProdutoQtd = $topProdutos ? (int)$topProdutos[0]['qtd'] : 0;

admin_topbar('Central - Dashboard', 'dashboard');
// Acompanhamento dos últimos 3 dias de teste e renovação mensal.
require_once __DIR__ . '/../config/saas_trial_cycle.php';
require_once __DIR__ . '/../config/saas_user_notice.php';
$dashSub=tenant_latest_subscription($pdo,(int)COMPANY_ID);
$dashCycle=$dashSub?nexa_saas_cycle_notice_for_contract($pdo,(int)COMPANY_ID,nexa_saas_cycle_state($dashSub)):[];
$dashChargeNotice=nexa_user_contract_notice_enabled($pdo,(int)COMPANY_ID,(int)(auth_user_id()??0))
  && in_array((string)($dashCycle['phase']??''),['warning','last_day','grace','blocked'],true)
  && in_array((string)auth_role(),['company_admin','company_user','platform_admin'],true)
  && (auth_role()==='platform_admin' || (int)auth_company_id()===(int)COMPANY_ID);
?>
<?php if($dashChargeNotice): ?>
<?php $dashOverdue=in_array((string)($dashCycle['phase']??''),['grace','blocked'],true); ?>
<div role="status" style="margin:14px 0;padding:17px 19px;border:1px solid <?= $dashOverdue?'#fca5a5':'#fcd34d' ?>;border-left:5px solid <?= $dashOverdue?'#dc2626':'#d97706' ?>;border-radius:16px;background:<?= $dashOverdue?'#fef2f2':'#fffbeb' ?>;color:<?= $dashOverdue?'#991b1b':'#78350f' ?>;display:flex;align-items:center;gap:15px;justify-content:space-between;flex-wrap:wrap;box-shadow:0 6px 16px #0b1f5b0a">
 <div><strong style="display:block;font-size:17px;margin-bottom:5px"><?=htmlspecialchars(($dashCycle['phase']==='last_day'?(($dashCycle['notice_kind']??'')==='trial'?'Último dia do teste — ':'Vencimento hoje — '):($dashCycle['phase']==='grace'?'Pagamento pendente — ':(($dashCycle['notice_kind']??'')==='trial'?'Teste terminando — ':'Aviso de vencimento — '))),ENT_QUOTES,'UTF-8')?>Assinatura Nexa</strong>
 <span><?=htmlspecialchars((string)$dashCycle['message'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></span></div>
 <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="assinatura_regularizar.php" style="display:inline-block;padding:10px 14px;background:#2256ce;color:white;text-decoration:none;border-radius:12px;font-weight:800">Conferir e pagar</a><a href="minhas_faturas.php" style="display:inline-block;padding:10px 14px;background:#fff;color:#1e40af;text-decoration:none;border:1px solid #cbd5e1;border-radius:12px;font-weight:800">Minhas faturas</a></div>
</div>
<?php endif; ?>

<style>
  body{
    background:
      radial-gradient(circle at 8% 0%, color-mix(in srgb, var(--admin-accent) 8%, transparent), transparent 28%),
      linear-gradient(180deg,#f8fbff 0%,#f5f7fb 100%);
  }
  body > .container{max-width:1320px}
  .dash{padding:8px 0 28px}
  .dash-hero{
    position:relative;
    overflow:hidden;
    border-radius:28px;
    padding:24px;
    color:#fff;
    background:
      radial-gradient(circle at 92% 12%, rgba(255,255,255,.18), transparent 26%),
      linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));
    box-shadow:0 18px 42px rgba(11,31,91,.15);
  }
  .dash-hero::before{
    content:"";
    position:absolute;
    right:-100px;
    bottom:-130px;
    width:300px;
    height:300px;
    border-radius:50%;
    background:rgba(255,255,255,.08);
  }
  .dash-hero::after{display:none}
  .dash-hero-content{
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);
    gap:20px;
    align-items:center;
  }
  .dash-kicker{
    display:inline-flex;
    align-items:center;
    gap:8px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(255,255,255,.10);
    border-radius:999px;
    padding:7px 11px;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.07em;
  }
  .dash-title{
    font-size:clamp(28px,3.4vw,44px);
    line-height:1.04;
    margin:14px 0 10px;
    letter-spacing:-.045em;
    color:#fff;
  }
  .dash-subtitle{
    max-width:720px;
    color:rgba(255,255,255,.82);
    font-size:15px;
    line-height:1.55;
    margin:0;
  }
  .dash-hero-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
  }
  .dash-hero .btn{
    border-color:rgba(255,255,255,.20);
    box-shadow:none;
  }
  .dash-hero .btn-white{
    background:#fff;
    color:var(--admin-primary);
  }
  .dash-hero .btn-ghost{
    background:rgba(255,255,255,.10);
    color:#fff;
  }
  .dash-score{
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.16);
    border-radius:24px;
    padding:18px;
    backdrop-filter:blur(10px);
  }
  .dash-score-top{
    display:flex;
    justify-content:space-between;
    gap:14px;
    align-items:flex-start;
  }
  .dash-score-value{
    font-size:42px;
    font-weight:950;
    letter-spacing:-.05em;
    line-height:1;
    color:#fff;
  }
  .dash-score-label{
    color:rgba(255,255,255,.82);
    font-weight:820;
    margin-top:8px;
  }
  .dash-ring{
    width:72px;
    height:72px;
    border-radius:50%;
    display:grid;
    place-items:center;
    font-weight:950;
    background:conic-gradient(#fff <?= (int)$saude ?>%, rgba(255,255,255,.22) 0);
    color:var(--admin-primary);
  }
  .dash-ring span{
    width:54px;
    height:54px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#fff;
    font-size:13px;
  }
  .dash-score-bar{
    height:9px;
    border-radius:999px;
    background:rgba(255,255,255,.18);
    overflow:hidden;
    margin-top:16px;
  }
  .dash-score-bar i{
    display:block;
    height:100%;
    width:<?= (int)$saude ?>%;
    border-radius:999px;
    background:#fff;
  }
  .dash-metrics{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-top:16px;
  }
  .metric-card{
    position:relative;
    overflow:hidden;
    min-height:138px;
    background:rgba(255,255,255,.94);
    border:1px solid rgba(219,232,247,.96);
    border-radius:22px;
    padding:17px;
    box-shadow:0 12px 30px rgba(11,31,91,.06);
  }
  .metric-card::after{
    content:"";
    position:absolute;
    right:-48px;
    top:-54px;
    width:120px;
    height:120px;
    border-radius:50%;
    background:color-mix(in srgb, var(--admin-accent) 10%, transparent);
  }
  .metric-icon{
    width:38px;
    height:38px;
    border-radius:14px;
    display:grid;
    place-items:center;
    background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));
    color:#fff;
    font-size:18px;
    box-shadow:0 10px 20px color-mix(in srgb, var(--admin-primary) 14%, transparent);
  }
  .metric-label{
    margin-top:14px;
    color:#64748b;
    font-size:12px;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.055em;
  }
  .metric-value{
    margin-top:6px;
    font-size:26px;
    font-weight:950;
    letter-spacing:-.045em;
    color:#07163f;
  }
  .metric-foot{
    margin-top:9px;
    display:flex;
    align-items:center;
    gap:8px;
    color:#64748b;
    font-size:12.5px;
    font-weight:750;
  }
  .delta{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 8px;
    font-size:12px;
    font-weight:900;
  }
  .delta.up{background:#ecfdf5;color:#047857}
  .delta.down{background:#fef2f2;color:#b91c1c}
  .delta.neutral{background:#f1f5f9;color:#475569}
  .dash-grid{
    display:grid;
    grid-template-columns:minmax(0,1.28fr) minmax(320px,.72fr);
    gap:16px;
    margin-top:16px;
  }
  .dash-card{
    background:rgba(255,255,255,.94);
    border:1px solid rgba(219,232,247,.98);
    border-radius:24px;
    padding:20px;
    box-shadow:0 12px 30px rgba(11,31,91,.055);
  }
  .dash-card-head{
    display:flex;
    justify-content:space-between;
    gap:14px;
    align-items:flex-start;
    margin-bottom:16px;
  }
  .dash-card-title{
    margin:0;
    color:#07163f;
    font-size:19px;
    font-weight:950;
    letter-spacing:-.03em;
  }
  .dash-card-sub{
    margin:4px 0 0;
    color:#64748b;
    font-size:13px;
  }
  .chart-bars{
    display:flex;
    align-items:flex-end;
    gap:10px;
    height:205px;
    padding:6px 0 0;
  }
  .bar-col{
    flex:1;
    min-width:0;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:7px;
  }
  .bar-wrap{
    height:146px;
    width:100%;
    display:flex;
    align-items:flex-end;
  }
  .bar{
    width:100%;
    min-height:8px;
    border-radius:14px 14px 8px 8px;
    background:linear-gradient(180deg,var(--admin-secondary),var(--admin-primary));
    box-shadow:0 10px 22px color-mix(in srgb, var(--admin-secondary) 12%, transparent);
  }
  .bar-label,.bar-value{
    font-size:11.5px;
    color:#64748b;
    font-weight:800;
    text-align:center;
    white-space:nowrap;
  }
  .bar-value{
    color:#0f172a;
    font-weight:900;
  }
  .sales12-chart{
    display:flex;
    align-items:flex-end;
    gap:8px;
    min-height:262px;
    overflow-x:auto;
    padding:6px 2px 2px;
  }
  .sales12-month{
    flex:1 1 0;
    min-width:0;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:7px;
  }
  .sales12-value{
    font-size:11px;
    color:#0f172a;
    font-weight:900;
    white-space:nowrap;
    text-align:center;
  }
  .sales12-pair{
    height:162px;
    width:100%;
    display:flex;
    align-items:flex-end;
    justify-content:center;
    gap:4px;
    border-bottom:1px solid #e2e8f0;
    padding:0 3px;
  }
  .sales12-bar{
    width:18px;
    min-height:6px;
    border-radius:10px 10px 5px 5px;
    display:block;
  }
  .sales12-bar.current{
    background:linear-gradient(180deg,var(--admin-secondary),var(--admin-primary));
    box-shadow:0 10px 22px color-mix(in srgb, var(--admin-secondary) 12%, transparent);
  }
  .sales12-bar.previous{
    background:#cbd5e1;
    box-shadow:0 8px 16px rgba(100,116,139,.13);
  }
  .sales12-label{
    font-size:11.5px;
    color:#64748b;
    font-weight:900;
    text-align:center;
    white-space:nowrap;
  }
  .sales12-legend{
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:10px;
    color:#64748b;
    font-size:12.5px;
    font-weight:800;
  }
  .sales12-legend span{
    display:inline-flex;
    align-items:center;
    gap:6px;
  }
  .sales12-legend i{
    width:14px;
    height:9px;
    border-radius:99px;
    display:inline-block;
  }
  .sales12-legend .cur{background:linear-gradient(90deg,var(--admin-secondary),var(--admin-primary))}
  .sales12-legend .prev{background:#cbd5e1}
  .sales12-summary{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    justify-content:flex-end;
  }
  .top-list{
    display:grid;
    gap:12px;
  }
  .top-item{
    display:grid;
    gap:8px;
  }
  .top-row{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
  }
  .top-name{
    font-weight:840;
    color:#0f172a;
    line-height:1.25;
  }
  .top-qtd{
    font-weight:950;
    color:var(--admin-primary);
  }
  .progress{
    height:9px;
    border-radius:999px;
    background:#eef4ff;
    overflow:hidden;
  }
  .progress i{
    display:block;
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,var(--admin-primary),var(--admin-accent));
  }
  .insights{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:16px;
    margin-top:16px;
  }
  .insight-card{
    background:rgba(255,255,255,.94);
    border:1px solid rgba(219,232,247,.96);
    border-radius:24px;
    padding:18px;
    box-shadow:0 12px 30px rgba(11,31,91,.05);
  }
  .mini-title{
    font-weight:950;
    color:#07163f;
    font-size:16px;
    letter-spacing:-.02em;
  }
  .mini-muted{
    font-size:12.5px;
    color:#64748b;
    margin-top:4px;
  }
  .status-stack{
    display:grid;
    gap:8px;
    margin-top:14px;
  }
  .status-line{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:9px 11px;
    border:1px solid #e8eef8;
    border-radius:15px;
    background:#f8fbff;
  }
  .status-dot{
    width:10px;
    height:10px;
    border-radius:50%;
    background:#94a3b8;
    box-shadow:0 0 0 5px rgba(148,163,184,.12);
  }
  .status-dot.status-confirmado{background:#3b82f6;box-shadow:0 0 0 5px rgba(59,130,246,.12)}
  .status-dot.status-em_producao{background:#f59e0b;box-shadow:0 0 0 5px rgba(245,158,11,.14)}
  .status-dot.status-saiu_entrega{background:#0ea5e9;box-shadow:0 0 0 5px rgba(14,165,233,.12)}
  .status-dot.status-finalizado{background:#10b981;box-shadow:0 0 0 5px rgba(16,185,129,.12)}
  .status-dot.status-cancelado{background:#ef4444;box-shadow:0 0 0 5px rgba(239,68,68,.12)}
  .pill-soft{
    display:inline-flex;
    padding:7px 10px;
    border-radius:999px;
    background:#f1f5f9;
    color:#475569;
    font-weight:850;
    font-size:12px;
  }
  .channel-list{
    display:grid;
    gap:10px;
    margin-top:14px;
  }
  .channel-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:10px;
    align-items:center;
    padding:11px 12px;
    border:1px solid #e8eef8;
    border-radius:15px;
    background:#f8fbff;
  }
  .channel-row strong{color:#0f172a;font-size:13px}
  .channel-row small{display:block;color:#64748b;font-size:11.5px;margin-top:2px}
  .channel-count{font-size:22px;font-weight:950;color:var(--admin-primary)}
  .seller-ranking{display:grid;gap:12px;margin-top:8px}
  .seller-row{display:grid;gap:7px}
  .seller-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end}
  .seller-name{font-weight:900;color:#0f172a}
  .seller-meta{font-size:11.5px;color:#64748b;margin-top:2px}
  .seller-total{font-weight:950;color:var(--admin-primary);white-space:nowrap}
  .os-dash-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(250px,.7fr);gap:14px;align-items:start;margin-top:12px}
  .os-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
  .os-status-row{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #e8eef8;border-radius:14px;background:#fff;padding:10px 12px;min-height:48px}
  .os-status-name{display:flex;align-items:center;gap:9px;min-width:0;font-weight:900;color:#0f172a}
  .os-status-name i{width:10px;height:10px;min-width:10px;border-radius:999px;box-shadow:0 0 0 4px rgba(100,116,139,.10)}
  .os-status-name span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .os-status-row strong{font-size:20px;color:#0b1f5b;font-weight:950}
  .os-kpis{display:grid;gap:10px}
  .os-kpi{border:1px solid #dbe7f6;border-radius:16px;background:#f8fbff;padding:13px}
  .os-kpi span{display:block;color:#64748b;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.04em}
  .os-kpi strong{display:block;color:#07163f;font-size:25px;font-weight:950;margin-top:6px}
  .os-kpi small{display:block;color:#64748b;font-size:11.5px;margin-top:5px;line-height:1.4}
  .comanda-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
  .comanda-kpi{border:1px solid #e8eef8;border-radius:16px;background:#f8fbff;padding:13px}
  .comanda-kpi span{display:block;color:#64748b;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.04em}
  .comanda-kpi strong{display:block;color:#07163f;font-size:25px;font-weight:950;margin-top:6px}
  .dash-grid.single{grid-template-columns:1fr}
  .payment-list{
    display:grid;
    gap:10px;
    margin-top:14px;
  }
  .payment-row{
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
    padding-bottom:10px;
    border-bottom:1px solid #edf2f7;
  }
  .empty-soft{
    padding:16px;
    border:1px dashed #cbd5e1;
    border-radius:18px;
    background:#f8fbff;
    color:#64748b;
  }
  @media(max-width:1180px){
    .dash-metrics{grid-template-columns:repeat(2,1fr)}
    .dash-grid,.insights,.dash-hero-content{grid-template-columns:1fr}
  }
  @media(max-width:700px){
    .dash{padding-top:2px}
    .dash-hero{
      border-radius:22px;
      padding:18px;
    }
    .dash-title{
      font-size:30px;
    }
    .dash-hero-actions{
      display:grid;
      grid-template-columns:1fr;
    }
    .dash-metrics{
      grid-template-columns:1fr;
      gap:12px;
    }
    .metric-card{
      min-height:auto;
      border-radius:20px;
      padding:15px;
    }
    .metric-value{
      font-size:24px;
    }
    .dash-card,.insight-card{
      border-radius:20px;
      padding:15px;
    }
    .chart-bars{
      gap:6px;
      overflow-x:auto;
      padding-bottom:8px;
    }
    .bar-col{
      min-width:64px;
    }
    .sales12-month{
      flex-basis:76px;
      min-width:76px;
    }
    .bar-value{
      font-size:10.5px;
    }
    .dash-score-top{
      align-items:center;
    }
    .dash-card-head{
      flex-direction:column;
      align-items:stretch;
      gap:10px;
    }
    .sales12-summary{justify-content:flex-start}
    .os-dash-layout{grid-template-columns:1fr}
    .os-status-grid{grid-template-columns:1fr}
    .comanda-kpis{grid-template-columns:1fr}
    .sales12-chart{
      display:grid;
      grid-template-columns:repeat(12,minmax(0,1fr));
      gap:3px;
      min-height:218px;
      overflow:visible;
      width:100%;
      padding:4px 0 0;
    }
    .sales12-month{
      min-width:0!important;
      width:auto;
      flex:none;
      gap:5px;
    }
    .sales12-value{display:none}
    .sales12-pair{height:156px;gap:2px;padding:0 1px}
    .sales12-bar{width:min(7px,42%)}
    .sales12-label{font-size:8.5px;letter-spacing:-.02em}
    .top-row{align-items:flex-start}
    .top-name{min-width:0;overflow-wrap:anywhere;word-break:break-word}
    .top-qtd{flex:0 0 auto;white-space:nowrap}
  }
  @media(max-width:420px){
    .sales12-chart{gap:2px}
    .sales12-pair{height:145px}
    .sales12-bar{width:min(6px,44%)}
    .sales12-label{font-size:7.8px}
    .top-row{gap:8px}
  }
</style>

<div class="dash">
  <section class="dash-hero">
    <div class="dash-hero-content">
      <div>
        <span class="dash-kicker">📊 Dashboard operacional · <?= h($dashCompanyName) ?></span>
        <h1 class="dash-title">Sua operação em tempo real.</h1>
        <p class="dash-subtitle">
          Acompanhe pedidos, faturamento, integração, produtos mais vendidos e pontos de atenção em uma tela moderna, clara e preparada para gestão SaaS.
        </p>
        <?php if ($dashContextOperational): ?>
          <div style="margin-top:12px;display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.20);font-size:12px;font-weight:850;">
            ✓ V5: indicadores carregados da empresa <?= h($dashCompanyName) ?>. Demais módulos continuam no fluxo legado.
          </div>
        <?php endif; ?>
        <div class="dash-hero-actions">
          <a class="btn btn-white" href="pedidos.php">Gerenciar pedidos</a>
          <a class="btn btn-ghost" href="produtos.php">Atualizar produtos</a>
          <a class="btn btn-ghost" href="exportacao.php">Ver exportação</a>
        </div>
      </div>

      <div class="dash-score">
        <div class="dash-score-top">
          <div>
            <div class="dash-score-value"><?= (int)$saude ?></div>
            <div class="dash-score-label"><?= h($saudeLabel) ?></div>
          </div>
          <div class="dash-ring"><span><?= (int)$saude ?>%</span></div>
        </div>
        <div class="dash-score-bar"><i></i></div>
        <div style="margin-top:12px;color:rgba(255,255,255,.78);font-size:13px;line-height:1.45;">
          Baseado em exportação, pedidos em andamento e cancelamentos dos últimos 30 dias.
        </div>
      </div>
    </div>
  </section>

  <section class="dash-metrics">
    <article class="metric-card">
      <div class="metric-icon">🧾</div>
      <div class="metric-label">Pedidos hoje</div>
      <div class="metric-value"><?= dash_int($hojeRow['qtd']) ?></div>
      <div class="metric-foot"><span class="delta <?= h($deltaPedidosHoje['class']) ?>"><?= h($deltaPedidosHoje['label']) ?></span> vs. ontem</div>
    </article>

    <article class="metric-card">
      <div class="metric-icon">💰</div>
      <div class="metric-label">Faturamento hoje</div>
      <div class="metric-value"><?= dash_money((float)$hojeRow['soma']) ?></div>
      <div class="metric-foot"><span class="delta <?= h($deltaFatHoje['class']) ?>"><?= h($deltaFatHoje['label']) ?></span> vs. ontem</div>
    </article>

    <article class="metric-card">
      <div class="metric-icon">🚀</div>
      <div class="metric-label">Em andamento</div>
      <div class="metric-value"><?= dash_int($abertos) ?></div>
      <div class="metric-foot">Pedidos aguardando ação</div>
    </article>

    <?php if ($dashHasExportacaoModule): ?>
    <article class="metric-card">
      <div class="metric-icon">🔄</div>
      <div class="metric-label">Exportação pendente</div>
      <div class="metric-value"><?= dash_int($pendExport) ?></div>
      <div class="metric-foot"><?= $errExport > 0 ? '<span class="delta down">' . dash_int($errExport) . ' erro(s)</span>' : '<span class="delta up">sem erros</span>' ?></div>
    </article>
    <?php endif; ?>

    <article class="metric-card">
      <div class="metric-icon">📅</div>
      <div class="metric-label">Pedidos no mês</div>
      <div class="metric-value"><?= dash_int($mesRow['qtd']) ?></div>
      <div class="metric-foot">Mês atual</div>
    </article>

    <article class="metric-card">
      <div class="metric-icon">📈</div>
      <div class="metric-label">Faturamento no mês</div>
      <div class="metric-value"><?= dash_money((float)$mesRow['soma']) ?></div>
      <div class="metric-foot"><span class="delta <?= h($deltaMes['class']) ?>"><?= h($deltaMes['label']) ?></span> vs. mês anterior</div>
    </article>

    <article class="metric-card">
      <div class="metric-icon">🎯</div>
      <div class="metric-label">Ticket médio</div>
      <div class="metric-value"><?= dash_money((float)$ticketMedio) ?></div>
      <div class="metric-foot">Últimos 30 dias</div>
    </article>

    <article class="metric-card">
      <div class="metric-icon">⭐</div>
      <div class="metric-label">Top produto</div>
      <div class="metric-value" style="font-size:22px;line-height:1.15;"><?= h(mb_strimwidth($topProdutoNome, 0, 48, '…')) ?></div>
      <div class="metric-foot"><?= $topProdutoQtd > 0 ? 'Qtd: ' . dash_int($topProdutoQtd) : 'Sem dados ainda' ?></div>
    </article>
  </section>

  <section class="dash-grid">
    <article class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2 class="dash-card-title">Vendas dos últimos 12 meses</h2>
          <p class="dash-card-sub">Mês atual + 11 anteriores, com comparação do mesmo mês no ano anterior.</p>
        </div>
        <div class="sales12-summary">
          <span class="pill-soft"><?= dash_money($totalVendas12Atual) ?></span>
          <span class="delta <?= h($deltaVendas12['class']) ?>"><?= h($deltaVendas12['label']) ?></span>
        </div>
      </div>

      <div class="sales12-chart" aria-label="Gráfico de vendas dos últimos 12 meses">
        <?php foreach ($vendas12 as $r):
          $heightCurrent = max(6, (int)round(((float)$r['current_total'] / $maxVendas12) * 154));
          $heightPrevious = max(6, (int)round(((float)$r['previous_total'] / $maxVendas12) * 154));
        ?>
          <div class="sales12-month">
            <div class="sales12-value"><?= h(dash_money_short((float)$r['current_total'])) ?></div>
            <div class="sales12-pair">
              <i class="sales12-bar previous" title="<?= h($r['previous_label']) ?>: <?= dash_money((float)$r['previous_total']) ?> — <?= dash_int($r['previous_qtd']) ?> pedido(s)" style="height:<?= $heightPrevious ?>px;"></i>
              <i class="sales12-bar current" title="<?= h($r['current_label']) ?>: <?= dash_money((float)$r['current_total']) ?> — <?= dash_int($r['current_qtd']) ?> pedido(s)" style="height:<?= $heightCurrent ?>px;"></i>
            </div>
            <div class="sales12-label"><?= h($r['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="sales12-legend"><span><i class="cur"></i>Vendas do período</span><span><i class="prev"></i>Mesmo mês do ano anterior</span></div>
    </article>

    <article class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2 class="dash-card-title">Top 5 produtos</h2>
          <p class="dash-card-sub">Ranking por quantidade nos últimos 30 dias.</p>
        </div>
      </div>

      <?php if (!$topProdutos): ?>
        <div class="empty-soft">Ainda não há vendas suficientes para montar o ranking.</div>
      <?php else: ?>
        <div class="top-list">
          <?php foreach ($topProdutos as $tp):
            $pct = max(4, ((float)$tp['qtd'] / $maxTop) * 100);
          ?>
            <div class="top-item">
              <div class="top-row">
                <div class="top-name"><?= h((string)$tp['nome']) ?></div>
                <div class="top-qtd"><?= dash_int($tp['qtd']) ?></div>
              </div>
              <div class="progress"><i style="width:<?= number_format($pct, 2, '.', '') ?>%"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <section class="insights">
    <article class="insight-card">
      <div class="mini-title">Status dos pedidos</div>
      <div class="mini-muted">Distribuição dos últimos 30 dias.</div>
      <div class="status-stack">
        <?php foreach ($statusMap as $status => $qtd): ?>
          <div class="status-line">
            <div style="display:flex;align-items:center;gap:10px;">
              <span class="status-dot status-<?= h(dash_status_class($status)) ?>"></span>
              <strong><?= h(dash_status_label($status)) ?></strong>
            </div>
            <span class="pill-soft"><?= dash_int($qtd) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="insight-card">
      <div class="mini-title">Origem dos pedidos</div>
      <div class="mini-muted">Delivery e balcão nos últimos 30 dias.</div>
      <div class="channel-list">
        <?php
          $channelLabels = [
            'delivery_retirada' => ['Delivery (Retirada)', 'Pedido feito no Delivery para retirada'],
            'delivery_entrega' => ['Delivery (Entrega)', 'Pedido feito no Delivery para entrega'],
            'balcao' => ['Balcão', 'Atendimento com tipo de entrega balcão'],
          ];
          foreach ($channelLabels as $channelKey => $channelInfo):
            $channelQty = (int)($orderChannelMap[$channelKey] ?? 0);
            $channelPct = $orderChannelTotal > 0 ? ($channelQty / $orderChannelTotal) * 100 : 0;
        ?>
          <div class="channel-row">
            <div><strong><?= h($channelInfo[0]) ?></strong><small><?= h($channelInfo[1]) ?> · <?= dash_pct((float)$channelPct) ?></small></div>
            <div class="channel-count"><?= dash_int($channelQty) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="insight-card">
      <div class="mini-title">Resumo de performance</div>
      <div class="mini-muted">Indicadores que ajudam na gestão.</div>

      <div class="payment-list">
        <div class="payment-row">
          <span>Produtos ativos</span>
          <strong><?= dash_int($produtosAtivos) ?></strong>
        </div>
        <div class="payment-row">
          <span>Conclusão 30d</span>
          <strong><?= dash_pct((float)$taxaConclusao) ?></strong>
        </div>
        <div class="payment-row">
          <span>Cancelamento 30d</span>
          <strong><?= dash_pct((float)$taxaCancelamento) ?></strong>
        </div>
      </div>

      <?php if ($pagamentos): ?>
        <div style="margin-top:16px;">
          <div class="mini-title" style="font-size:15px;">Pagamentos mais usados</div>
          <div class="payment-list">
            <?php foreach ($pagamentos as $pg): ?>
              <div class="payment-row">
                <span><?= h((string)($pg['forma_pagamento'] ?: 'Não informado')) ?></span>
                <strong><?= dash_int($pg['qtd']) ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <section class="dash-grid <?= $dashHasComandasModule ? '' : 'single' ?>">
    <article class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2 class="dash-card-title">Ranking de vendedores</h2>
          <p class="dash-card-sub">Top 5 por valor vendido nos últimos 30 dias.</p>
        </div>
      </div>
      <?php if (!$topSellers): ?>
        <div class="empty-soft">Ainda não há pedidos com vendedor identificado suficientes para montar o ranking.</div>
      <?php else: ?>
        <div class="seller-ranking">
          <?php foreach ($topSellers as $pos => $seller):
            $sellerPct = max(4, ((float)$seller['total'] / $maxSellerTotal) * 100);
          ?>
            <div class="seller-row">
              <div class="seller-head">
                <div><div class="seller-name"><?= (int)$pos + 1 ?>º · <?= h((string)$seller['vendedor']) ?></div><div class="seller-meta"><?= dash_int($seller['qtd']) ?> pedido(s)</div></div>
                <div class="seller-total"><?= dash_money((float)$seller['total']) ?></div>
              </div>
              <div class="progress"><i style="width:<?= number_format($sellerPct, 2, '.', '') ?>%"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <?php if ($dashHasComandasModule): ?>
    <article class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2 class="dash-card-title">Comandas</h2>
          <p class="dash-card-sub">Giro das mesas/comandas nos últimos 30 dias.</p>
        </div>
      </div>
      <div class="comanda-kpis">
        <div class="comanda-kpi"><span>Finalizadas 30d</span><strong><?= dash_int($comandaStats['finalizadas_30d']) ?></strong></div>
        <div class="comanda-kpi"><span>Abertas agora</span><strong><?= dash_int($comandaStats['abertas_agora']) ?></strong></div>
        <div class="comanda-kpi"><span>Tempo médio</span><strong><?= h(dash_duration((float)$comandaStats['avg_seconds'])) ?></strong></div>
      </div>
      <div class="mini-muted" style="margin-top:12px;">Hoje: <b><?= dash_int($comandaStats['finalizadas_hoje']) ?></b> comanda(s) finalizada(s). O tempo médio considera do primeiro lançamento, quando a comanda passa a ocupada, até o fechamento.</div>
    </article>
    <?php endif; ?>
  </section>

  <?php if ($dashHasOsModule): ?>
  <section class="dash-grid single">
    <article class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2 class="dash-card-title">Ordens de Serviço</h2>
          <p class="dash-card-sub">Quantidade por status e tempo médio entre a entrada e a finalização da O.S.</p>
        </div>
      </div>
      <div class="os-dash-layout">
        <div>
          <div class="mini-title">Quantidade de O.S. por status</div>
          <?php if (!$osStatusRows): ?>
            <div class="empty-soft" style="margin-top:10px;">Nenhum status de O.S. encontrado.</div>
          <?php else: ?>
            <div class="os-status-grid" style="margin-top:10px;">
              <?php foreach ($osStatusRows as $osStatus): ?>
                <div class="os-status-row">
                  <div class="os-status-name"><i style="background:<?= h(dash_safe_hex_color((string)($osStatus['color'] ?? ''))) ?>"></i><span><?= h((string)($osStatus['name'] ?? 'Status')) ?></span></div>
                  <strong><?= dash_int((int)($osStatus['qtd'] ?? 0)) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="os-kpis">
          <div class="os-kpi"><span>Total de O.S.</span><strong><?= dash_int($osStats['total']) ?></strong><small>Soma das O.S. distribuídas pelos status cadastrados.</small></div>
          <div class="os-kpi"><span>Tempo médio até finalizar</span><strong><?= h(dash_duration_days_hours((float)$osStats['avg_seconds'])) ?></strong><small><?= dash_int($osStats['finalizadas']) ?> O.S. finalizada(s) consideradas no cálculo.</small></div>
        </div>
      </div>
    </article>
  </section>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
