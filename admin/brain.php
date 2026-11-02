<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/brain.php';

function brain_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$companyId = (int)COMPANY_ID;
$dashboard = brain_dashboard_get($pdo, $companyId);
$s = (array)($dashboard['snapshot'] ?? []);
$insights = array_slice((array)($dashboard['insights'] ?? []), 0, 8);
$topProducts = array_slice((array)($dashboard['topProducts'] ?? []), 0, 8);
$categories = array_slice((array)($dashboard['categories'] ?? []), 0, 12);
$sellers = array_slice((array)($dashboard['sellers'] ?? []), 0, 12);
$channels = array_slice((array)($dashboard['channels'] ?? []), 0, 12);
$stockRisks = array_slice((array)($dashboard['stockRisks'] ?? []), 0, 8);
$advice = array_slice((array)($dashboard['advice'] ?? []), 0, 5);
$actionsData = (array)($dashboard['actions'] ?? []);
$actionCounts = (array)($actionsData['counts'] ?? []);
$actionAlerts = array_slice((array)($actionsData['alerts'] ?? []), 0, 12);
$cacheMeta = (array)($dashboard['_cache'] ?? []);
$realtimeConfig = brain_realtime_browser_config($companyId, (int)(auth_user_id() ?? 0));
$brainFlash = null;
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['brain_flash'])) { $brainFlash = $_SESSION['brain_flash']; unset($_SESSION['brain_flash']); }
$brainCsrf = brain_actions_csrf_token('brain_actions');
$severityLabels = ['critical'=>'Crítico','high'=>'Alta prioridade','warning'=>'Atenção','opportunity'=>'Oportunidade','info'=>'Informativo'];
$moduleLabels = ['rentabilidade'=>'Rentabilidade','estoque'=>'Estoque','financeiro'=>'Financeiro','vendas'=>'Vendas','delivery'=>'Delivery','fiscal'=>'Fiscal','brain'=>'Brain'];
admin_topbar('Nexa Brain', 'brain');
?>
<link rel="stylesheet" href="/assets/brain.css?v=2.3.2">
<main class="brain-shell">
  <section class="brain-hero">
    <div>
      <span class="brain-kicker">INTELIGÊNCIA DE RESULTADOS</span>
      <h1>Nexa Brain</h1>
      <p>Vendas, CMV, lucro, estoque e recomendações baseadas nos dados reais da empresa.</p>
      <div class="brain-hero-badges">
        <span>CMV histórico: <?= (int)$s['estimatedOrders'] > 0 ? 'parte estimada' : 'snapshot registrado' ?></span>
        <span>Período dos rankings: 30 dias</span>
        <span>Vendas reconhecidas: pedidos finalizados</span>
        <span id="brainRealtimeStatus" class="brain-realtime-status" data-state="<?= !empty($realtimeConfig['enabled']) ? 'connecting' : 'disabled' ?>"><?= !empty($realtimeConfig['enabled']) ? 'Tempo real: conectando' : 'Tempo real: não configurado' ?></span>
        <span>Indicadores: <?= ($cacheMeta['source'] ?? '') === 'redis' ? 'cache Redis' : 'base de dados' ?></span>
        <span>Ações abertas: <?= (int)($actionCounts['total'] ?? 0) ?></span>
      </div>
    </div>
    <div class="brain-score"><span>Score de saúde</span><strong><?= (int)$s['score'] ?></strong><small>de 100 pontos</small></div>
  </section>

  <?php if (!$s['dataAvailable']): ?>
    <div class="brain-system-warning"><strong>Atenção:</strong> uma ou mais fontes de dados estão indisponíveis. Consulte o log PHP; o Brain não substituiu falhas por resultados falsos.</div>
  <?php endif; ?>

  <section class="brain-kpis">
    <article><span>Faturamento hoje</span><strong><?= brain_money($s['salesToday']) ?></strong><small><?= brain_percent($s['variation']) ?> vs. ontem</small></article>
    <article><span>Lucro bruto hoje</span><strong class="<?= $s['grossProfitToday'] >= 0 ? 'positive-text' : 'negative-text' ?>"><?= brain_money($s['grossProfitToday']) ?></strong><small>CMV <?= brain_money($s['cmvToday']) ?></small></article>
    <article><span>Margem sobre venda</span><strong><?= brain_percent($s['marginSaleToday']) ?></strong><small><?= brain_percent($s['marginCostToday']) ?> sobre o custo</small></article>
    <article><span>Markup fator</span><strong><?= number_format($s['markupToday'], 2, ',', '.') ?>x</strong><small>Receita líquida ÷ CMV</small></article>
    <article><span>Pedidos hoje</span><strong><?= number_format($s['ordersToday'],0,',','.') ?></strong><small><?= number_format($s['openOrders'],0,',','.') ?> em andamento</small></article>
    <article><span>Ticket médio</span><strong><?= brain_money($s['ticket']) ?></strong><small><?= brain_money($s['salesMonth']) ?> no mês</small></article>
    <article><span>Estoque a custo</span><strong><?= brain_money($s['inventoryCost']) ?></strong><small><?= brain_money($s['inventorySale']) ?> a venda</small></article>
    <article><span>Estoque crítico</span><strong><?= number_format($s['lowStock'],0,',','.') ?></strong><small><?= (int)$s['productsWithoutCost'] ?> sem custo</small></article>
  </section>


  <?php if ($brainFlash): ?><div class="brain-flash <?= brain_h((string)($brainFlash['type'] ?? 'ok')) ?>"><?= brain_h((string)($brainFlash['message'] ?? '')) ?></div><?php endif; ?>

  <section class="brain-card brain-action-center">
    <div class="brain-action-center-head">
      <div>
        <span class="brain-kicker dark">CENTRAL DE AÇÕES INTELIGENTES</span>
        <h2>O que precisa da sua atenção agora</h2>
        <p>O Brain transforma os indicadores existentes em prioridades, impacto e ações práticas.</p>
      </div>
      <div class="brain-action-toolbar">
        <a class="brain-secondary-button" href="brain_alertas.php">Ver todos e histórico</a>
        <?php if (!function_exists('access_is_company_master') || access_is_company_master()): ?><a class="brain-secondary-button" href="brain_configuracoes.php">Configurar regras</a><?php endif; ?>
        <form method="post" action="brain_alert_action.php">
          <input type="hidden" name="csrf" value="<?= brain_h($brainCsrf) ?>">
          <input type="hidden" name="action" value="evaluate">
          <button type="submit">Analisar agora</button>
        </form>
      </div>
    </div>

    <?php if (!brain_actions_schema_ready($pdo)): ?>
      <div class="brain-system-warning"><strong>Central ainda não instalada.</strong> Execute a migração <code>migrate_brain_actions_v230.php</code>.</div>
    <?php else: ?>
      <div class="brain-action-summary">
        <div class="critical"><strong><?= (int)($actionCounts['critical'] ?? 0) ?></strong><span>Críticos</span></div>
        <div class="high"><strong><?= (int)($actionCounts['high'] ?? 0) ?></strong><span>Alta prioridade</span></div>
        <div class="warning"><strong><?= (int)($actionCounts['warning'] ?? 0) ?></strong><span>Atenção</span></div>
        <div class="impact"><strong><?= brain_money((float)($actionCounts['financial_impact'] ?? 0)) ?></strong><span>Valores envolvidos</span></div>
      </div>

      <div class="brain-alert-list">
        <?php if (!$actionAlerts): ?>
          <div class="brain-action-empty"><strong>Nenhuma ação pendente.</strong><p>As regras não detectaram problemas abertos neste momento.</p></div>
        <?php endif; ?>
        <?php foreach ($actionAlerts as $alert): $sev=(string)$alert['severity']; ?>
          <article class="brain-alert-item severity-<?= brain_h($sev) ?>">
            <div class="brain-alert-main">
              <div class="brain-alert-tags"><span class="severity"><?= brain_h($severityLabels[$sev] ?? ucfirst($sev)) ?></span><span><?= brain_h($moduleLabels[(string)$alert['module_code']] ?? ucfirst((string)$alert['module_code'])) ?></span></div>
              <h3><?= brain_h((string)$alert['title']) ?></h3>
              <p><?= brain_h((string)$alert['description']) ?></p>
              <?php if (!empty($alert['recommendation'])): ?><div class="brain-alert-recommendation"><strong>Ação recomendada:</strong> <?= brain_h((string)$alert['recommendation']) ?></div><?php endif; ?>
            </div>
            <div class="brain-alert-side">
              <?php if ($alert['financial_impact'] !== null): ?><div class="brain-alert-impact"><span>Valores envolvidos</span><strong><?= brain_money((float)$alert['financial_impact']) ?></strong></div><?php endif; ?>
              <div class="brain-alert-buttons">
                <?php if (!empty($alert['action_url'])): ?><a href="<?= brain_h((string)$alert['action_url']) ?>">Abrir ação</a><?php endif; ?>
                <form method="post" action="brain_alert_action.php"><input type="hidden" name="csrf" value="<?= brain_h($brainCsrf) ?>"><input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>"><input type="hidden" name="action" value="resolve"><button type="submit">Resolvido</button></form>
                <form method="post" action="brain_alert_action.php"><input type="hidden" name="csrf" value="<?= brain_h($brainCsrf) ?>"><input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>"><input type="hidden" name="action" value="snooze"><button type="submit" class="secondary">Adiar 24h</button></form>
                <form method="post" action="brain_alert_action.php" onsubmit="return confirm('Ignorar este alerta até que seja reaberto manualmente?')"><input type="hidden" name="csrf" value="<?= brain_h($brainCsrf) ?>"><input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>"><input type="hidden" name="action" value="ignore"><button type="submit" class="ghost">Ignorar</button></form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="brain-grid">
    <div class="brain-card brain-assistant">
      <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Converse com o Brain</h2></div><small>Consultas seguras e determinísticas</small></div>
      <div id="brainMessages" class="brain-messages"><div class="brain-message brain-bot">Olá! Pergunte: “Qual meu lucro hoje?”, “Qual categoria vende mais?”, “Qual canal é mais rentável?” ou “O que posso fazer para aumentar o lucro?”.</div></div>
      <form id="brainForm" class="brain-form"><input id="brainQuestion" maxlength="240" autocomplete="off" placeholder="Faça uma pergunta sobre sua empresa..."><button type="submit">Perguntar</button></form>
      <div class="brain-chips">
        <button data-q="Qual meu lucro e CMV hoje?">Lucro e CMV</button>
        <button data-q="Qual categoria vende mais?">Categorias</button>
        <button data-q="Qual canal é mais rentável?">Canais</button>
        <button data-q="Qual o valor do meu estoque?">Valor do estoque</button>
        <button data-q="O que posso fazer para aumentar o lucro?">Recomendações</button>
        <button data-q="Quais alertas devo resolver primeiro?">Prioridades</button>
      </div>
    </div>

    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot alert"></span><h2>Leituras rápidas dos indicadores</h2></div></div>
      <div class="brain-insights">
        <?php foreach($insights as $i): ?>
          <article class="<?= brain_h($i['level']) ?>"><div><strong><?= brain_h($i['title']) ?></strong><p><?= brain_h($i['message']) ?></p></div><a href="<?= brain_h($i['url']) ?>"><?= brain_h($i['action']) ?></a></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="brain-grid lower">
    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Resultado de hoje</h2></div><small>Taxa de entrega separada dos produtos</small></div>
      <div class="brain-profit-grid">
        <div><span>Receita líquida dos produtos</span><strong><?= brain_money($s['productRevenueToday']) ?></strong></div>
        <div><span>CMV</span><strong><?= brain_money($s['cmvToday']) ?></strong></div>
        <div class="<?= $s['grossProfitToday'] >= 0 ? 'positive' : 'negative' ?>"><span>Lucro bruto</span><strong><?= brain_money($s['grossProfitToday']) ?></strong></div>
        <div><span>Descontos</span><strong><?= brain_money($s['discountsToday']) ?></strong></div>
        <div><span>Acréscimos</span><strong><?= brain_money($s['additionsToday']) ?></strong></div>
        <div><span>Taxas de entrega</span><strong><?= brain_money($s['deliveryFeesToday']) ?></strong></div>
      </div>
    </div>
    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Valorização do estoque</h2></div><small>Estoque disponível</small></div>
      <div class="brain-profit-grid">
        <div><span>Valor em custo</span><strong><?= brain_money($s['inventoryCost']) ?></strong></div>
        <div><span>Valor em venda</span><strong><?= brain_money($s['inventorySale']) ?></strong></div>
        <div><span>Lucro potencial bruto</span><strong><?= brain_money($s['inventoryPotentialProfit']) ?></strong></div>
        <div><span>Margem potencial / venda</span><strong><?= brain_percent($s['inventoryMarginSale']) ?></strong></div>
        <div><span>Margem potencial / custo</span><strong><?= brain_percent($s['inventoryMarginCost']) ?></strong></div>
        <div><span>Produtos acima do máximo</span><strong><?= (int)$s['excessProducts'] ?></strong></div>
      </div>
    </div>
  </section>

  <section class="brain-card brain-wide-card">
    <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Resultado por categoria — 30 dias</h2></div><small>Ordenado da maior venda para a menor</small></div>
    <div class="brain-table-wrap">
      <table class="brain-table">
        <thead><tr><th>Categoria</th><th>Venda líquida</th><th>Represent.</th><th>CMV</th><th>Lucro bruto</th><th>Margem venda</th><th>Margem custo</th><th>Markup</th></tr></thead>
        <tbody>
        <?php if (!$categories): ?><tr><td colspan="8" class="brain-empty">Sem vendas finalizadas no período.</td></tr><?php endif; ?>
        <?php foreach ($categories as $row): ?>
          <tr><td><strong><?= brain_h($row['category']) ?></strong></td><td><?= brain_money((float)$row['revenue']) ?></td><td><?= brain_percent((float)$row['representation']) ?></td><td><?= brain_money((float)$row['cmv']) ?></td><td class="<?= (float)$row['gross_profit'] >= 0 ? 'positive-text' : 'negative-text' ?>"><?= brain_money((float)$row['gross_profit']) ?></td><td><?= brain_percent((float)$row['margin_sale']) ?></td><td><?= brain_percent((float)$row['margin_cost']) ?></td><td><?= number_format((float)$row['markup'],2,',','.') ?>x</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="brain-grid lower brain-equal">
    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Vendedores — 30 dias</h2></div></div>
      <div class="brain-table-wrap">
        <table class="brain-table compact">
          <thead><tr><th>Vendedor</th><th>Venda</th><th>Rep.</th><th>Lucro</th><th>Margem</th><th>Ticket</th></tr></thead>
          <tbody>
          <?php if (!$sellers): ?><tr><td colspan="6" class="brain-empty">Sem dados suficientes.</td></tr><?php endif; ?>
          <?php foreach ($sellers as $row): ?>
            <tr><td><strong><?= brain_h($row['seller']) ?></strong><small><?= (int)$row['orders_count'] ?> pedido(s)</small></td><td><?= brain_money((float)$row['sales_total']) ?></td><td><?= brain_percent((float)$row['representation']) ?></td><td><?= brain_money((float)$row['gross_profit']) ?></td><td><?= brain_percent((float)$row['margin_sale']) ?></td><td><?= brain_money((float)$row['average_ticket']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Canais — 30 dias</h2></div></div>
      <div class="brain-table-wrap">
        <table class="brain-table compact">
          <thead><tr><th>Canal</th><th>Venda</th><th>Rep.</th><th>Lucro</th><th>Margem</th><th>Ticket</th></tr></thead>
          <tbody>
          <?php if (!$channels): ?><tr><td colspan="6" class="brain-empty">Sem dados suficientes.</td></tr><?php endif; ?>
          <?php foreach ($channels as $row): ?>
            <tr><td><strong><?= brain_h(brain_channel_label((string)$row['channel'])) ?></strong><small><?= (int)$row['orders_count'] ?> pedido(s)</small></td><td><?= brain_money((float)$row['sales_total']) ?></td><td><?= brain_percent((float)$row['representation']) ?></td><td><?= brain_money((float)$row['gross_profit']) ?></td><td><?= brain_percent((float)$row['margin_sale']) ?></td><td><?= brain_money((float)$row['average_ticket']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="brain-grid lower">
    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Produtos líderes — 30 dias</h2></div></div>
      <div class="brain-ranking">
        <?php if(!$topProducts): ?><p>Sem dados suficientes.</p><?php else: foreach($topProducts as $n=>$p): ?>
          <div><b><?= $n+1 ?></b><span><?= brain_h($p['nome']) ?><small><?= number_format((float)$p['qtd'],3,',','.') ?> un. · margem <?= brain_percent((float)$p['margem']) ?></small></span><strong><?= brain_money((float)$p['total']) ?><small>lucro <?= brain_money((float)$p['lucro_bruto']) ?></small></strong></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="brain-card">
      <div class="brain-card-head"><div><span class="brain-dot alert"></span><h2>Riscos de estoque</h2></div></div>
      <div class="brain-stock-list">
        <?php if (!$stockRisks): ?><p>Nenhum risco relevante identificado.</p><?php endif; ?>
        <?php foreach ($stockRisks as $row): ?>
          <div><span><strong><?= brain_h($row['nome']) ?></strong><small><?= brain_h($row['categoria'] ?: 'Sem categoria') ?> · disponível <?= number_format(max(0,(float)$row['estoque_atual']-(float)$row['estoque_reservado']),3,',','.') ?></small></span><b><?= brain_money((float)$row['stock_cost']) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="brain-card brain-wide-card">
    <div class="brain-card-head"><div><span class="brain-dot"></span><h2>Plano de ação sugerido</h2></div><small>Baseado nos indicadores atuais</small></div>
    <ol class="brain-action-list"><?php foreach ($advice as $item): ?><li><?= brain_h($item) ?></li><?php endforeach; ?></ol>
  </section>

  <section class="brain-grid lower">
    <div class="brain-card"><div class="brain-card-head"><div><span class="brain-dot"></span><h2>Previsão financeira de 7 dias</h2></div></div><div class="brain-cash"><div><span>A receber</span><strong><?= brain_money($s['dueReceivables7']) ?></strong></div><div><span>A pagar</span><strong><?= brain_money($s['duePayables7']) ?></strong></div><div class="<?= $s['dueReceivables7']-$s['duePayables7'] >= 0 ? 'positive':'negative' ?>"><span>Saldo projetado</span><strong><?= brain_money($s['dueReceivables7']-$s['duePayables7']) ?></strong></div></div></div>
    <div class="brain-card"><div class="brain-card-head"><div><span class="brain-dot"></span><h2>Qualidade dos dados</h2></div></div><div class="brain-quality"><div><span>Pedidos com custo estimado</span><strong><?= (int)$s['estimatedOrders'] ?></strong></div><div><span>Produtos em estoque sem custo</span><strong><?= (int)$s['productsWithoutCost'] ?></strong></div><div><span>Itens abaixo do custo no mês</span><strong><?= (int)$s['salesBelowCostItems'] ?></strong></div></div></div>
  </section>
</main>
<script>window.NEXA_BRAIN_REALTIME = <?= json_encode($realtimeConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/assets/brain.js?v=2.3.2"></script>
<?php admin_footer(); ?>
