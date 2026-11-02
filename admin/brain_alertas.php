<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/brain.php';

function brain_alert_h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function brain_alert_date(mixed $value): string {
  $ts = strtotime((string)$value);
  return $ts ? date('d/m/Y H:i',$ts) : '-';
}

$companyId = (int)COMPANY_ID;
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flash = null;
if (!empty($_SESSION['brain_flash'])) { $flash = $_SESSION['brain_flash']; unset($_SESSION['brain_flash']); }

$statusOptions = ['open'=>'Abertos','snoozed'=>'Adiados','resolved'=>'Resolvidos','ignored'=>'Ignorados','all'=>'Todos'];
$severityOptions = ['all'=>'Todas','critical'=>'Crítica','high'=>'Alta','warning'=>'Atenção','opportunity'=>'Oportunidade','info'=>'Informativa'];
$moduleOptions = ['all'=>'Todos','rentabilidade'=>'Rentabilidade','estoque'=>'Estoque','financeiro'=>'Financeiro','vendas'=>'Vendas','delivery'=>'Delivery','fiscal'=>'Fiscal','brain'=>'Brain'];
$status = (string)($_GET['status'] ?? 'open');
$severity = (string)($_GET['severity'] ?? 'all');
$module = (string)($_GET['module'] ?? 'all');
if (!isset($statusOptions[$status])) $status = 'open';
if (!isset($severityOptions[$severity])) $severity = 'all';
if (!isset($moduleOptions[$module])) $module = 'all';

$alerts = [];
$historyByAlert = [];
$allCounts = ['open'=>0,'snoozed'=>0,'resolved'=>0,'ignored'=>0];
if (brain_actions_schema_ready($pdo)) {
  $params = [$companyId];
  $where = ['company_id=?'];
  if ($status !== 'all') { $where[] = 'status=?'; $params[] = $status; }
  if ($severity !== 'all') { $where[] = 'severity=?'; $params[] = $severity; }
  if ($module !== 'all') { $where[] = 'module_code=?'; $params[] = $module; }
  $alerts = brain_actions_query_rows($pdo,'SELECT * FROM brain_alerts WHERE ' . implode(' AND ',$where)
    . " ORDER BY FIELD(status,'open','snoozed','ignored','resolved'),FIELD(severity,'critical','high','warning','opportunity','info'),last_detected_at DESC,id DESC LIMIT 100",$params);

  $countRows = brain_actions_query_rows($pdo,'SELECT status,COUNT(*) total FROM brain_alerts WHERE company_id=? GROUP BY status',[$companyId]);
  foreach ($countRows as $row) if (isset($allCounts[(string)$row['status']])) $allCounts[(string)$row['status']] = (int)$row['total'];

  $ids = array_values(array_map(static fn(array $row): int => (int)$row['id'],$alerts));
  if ($ids) {
    $ph = implode(',',array_fill(0,count($ids),'?'));
    $historyRows = brain_actions_query_rows($pdo,"SELECT * FROM brain_alert_history WHERE company_id=? AND alert_id IN ($ph) ORDER BY created_at DESC,id DESC",array_merge([$companyId],$ids));
    foreach ($historyRows as $row) {
      $aid = (int)$row['alert_id'];
      if (!isset($historyByAlert[$aid])) $historyByAlert[$aid] = [];
      if (count($historyByAlert[$aid]) < 8) $historyByAlert[$aid][] = $row;
    }
  }
}

$csrf = brain_actions_csrf_token('brain_actions');
$returnUrl = 'brain_alertas.php?' . http_build_query(['status'=>$status,'severity'=>$severity,'module'=>$module]);
$statusLabels = ['open'=>'Aberto','snoozed'=>'Adiado','resolved'=>'Resolvido','ignored'=>'Ignorado'];
$severityLabels = ['critical'=>'Crítico','high'=>'Alta prioridade','warning'=>'Atenção','opportunity'=>'Oportunidade','info'=>'Informativo'];
$moduleLabels = ['rentabilidade'=>'Rentabilidade','estoque'=>'Estoque','financeiro'=>'Financeiro','vendas'=>'Vendas','delivery'=>'Delivery','fiscal'=>'Fiscal','brain'=>'Brain'];
$historyActions = [
  'detected'=>'Detectado automaticamente','auto_reopen'=>'Reaberto automaticamente','auto_resolve'=>'Resolvido automaticamente',
  'rule_disabled'=>'Resolvido porque a regra foi desativada','resolve'=>'Marcado como resolvido','snooze'=>'Adiado por 24 horas',
  'ignore'=>'Ignorado','reopen'=>'Reaberto',
];

admin_topbar('Central de Ações do Nexa Brain','brain');
?>
<link rel="stylesheet" href="/assets/brain.css?v=2.3.0">
<main class="brain-shell">
  <section class="brain-config-head">
    <div><span class="brain-kicker">HISTÓRICO E ACOMPANHAMENTO</span><h1>Central de Ações Inteligentes</h1><p>Consulte alertas abertos, adiados, resolvidos e ignorados sem misturar dados entre empresas.</p></div>
    <a class="brain-secondary-button" href="brain.php">Voltar ao Brain</a>
  </section>

  <?php if ($flash): ?><div class="brain-flash <?= brain_alert_h((string)($flash['type'] ?? 'ok')) ?>"><?= brain_alert_h((string)($flash['message'] ?? '')) ?></div><?php endif; ?>
  <?php if (!brain_actions_schema_ready($pdo)): ?>
    <div class="brain-system-warning"><strong>Estrutura não instalada.</strong> Execute o worker <code>migrate_brain_actions_v230.php</code>.</div>
  <?php else: ?>
    <section class="brain-history-counts">
      <?php foreach (['open','snoozed','resolved','ignored'] as $key): ?><a class="<?= $status===$key?'active':'' ?>" href="brain_alertas.php?<?= brain_alert_h(http_build_query(['status'=>$key,'severity'=>$severity,'module'=>$module])) ?>"><strong><?= (int)$allCounts[$key] ?></strong><span><?= brain_alert_h($statusOptions[$key]) ?></span></a><?php endforeach; ?>
    </section>

    <form class="brain-alert-filters" method="get">
      <label>Situação<select name="status"><?php foreach ($statusOptions as $value=>$label): ?><option value="<?= brain_alert_h($value) ?>" <?= $status===$value?'selected':'' ?>><?= brain_alert_h($label) ?></option><?php endforeach; ?></select></label>
      <label>Gravidade<select name="severity"><?php foreach ($severityOptions as $value=>$label): ?><option value="<?= brain_alert_h($value) ?>" <?= $severity===$value?'selected':'' ?>><?= brain_alert_h($label) ?></option><?php endforeach; ?></select></label>
      <label>Módulo<select name="module"><?php foreach ($moduleOptions as $value=>$label): ?><option value="<?= brain_alert_h($value) ?>" <?= $module===$value?'selected':'' ?>><?= brain_alert_h($label) ?></option><?php endforeach; ?></select></label>
      <button type="submit">Aplicar filtros</button>
    </form>

    <section class="brain-alert-list brain-history-list">
      <?php if (!$alerts): ?><div class="brain-action-empty"><strong>Nenhum alerta encontrado.</strong><p>Altere os filtros ou execute uma nova análise no painel do Brain.</p></div><?php endif; ?>
      <?php foreach ($alerts as $alert): $sev=(string)$alert['severity']; $alertStatus=(string)$alert['status']; $aid=(int)$alert['id']; ?>
        <article class="brain-alert-item severity-<?= brain_alert_h($sev) ?> status-<?= brain_alert_h($alertStatus) ?>">
          <div class="brain-alert-main">
            <div class="brain-alert-tags">
              <span class="severity"><?= brain_alert_h($severityLabels[$sev] ?? ucfirst($sev)) ?></span>
              <span><?= brain_alert_h($moduleLabels[(string)$alert['module_code']] ?? ucfirst((string)$alert['module_code'])) ?></span>
              <span class="status"><?= brain_alert_h($statusLabels[$alertStatus] ?? ucfirst($alertStatus)) ?></span>
            </div>
            <h3><?= brain_alert_h((string)$alert['title']) ?></h3>
            <p><?= brain_alert_h((string)$alert['description']) ?></p>
            <?php if (!empty($alert['recommendation'])): ?><div class="brain-alert-recommendation"><strong>Ação recomendada:</strong> <?= brain_alert_h((string)$alert['recommendation']) ?></div><?php endif; ?>
            <div class="brain-alert-dates"><span>Primeira detecção: <?= brain_alert_h(brain_alert_date($alert['first_detected_at'])) ?></span><span>Última detecção: <?= brain_alert_h(brain_alert_date($alert['last_detected_at'])) ?></span><?php if ($alertStatus==='snoozed'): ?><span>Adiado até: <?= brain_alert_h(brain_alert_date($alert['snoozed_until'])) ?></span><?php endif; ?></div>

            <?php if (!empty($historyByAlert[$aid])): ?>
              <details class="brain-alert-history"><summary>Ver histórico do alerta</summary><div><?php foreach ($historyByAlert[$aid] as $history): ?><p><strong><?= brain_alert_h($historyActions[(string)$history['action_code']] ?? (string)$history['action_code']) ?></strong><span><?= brain_alert_h(brain_alert_date($history['created_at'])) ?></span><?php if (!empty($history['note'])): ?><small><?= brain_alert_h((string)$history['note']) ?></small><?php endif; ?></p><?php endforeach; ?></div></details>
            <?php endif; ?>
          </div>
          <div class="brain-alert-side">
            <?php if ($alert['financial_impact'] !== null): ?><div class="brain-alert-impact"><span>Valor relacionado</span><strong><?= brain_money((float)$alert['financial_impact']) ?></strong></div><?php endif; ?>
            <div class="brain-alert-buttons">
              <?php if (!empty($alert['action_url'])): ?><a href="<?= brain_alert_h(brain_actions_safe_url((string)$alert['action_url'])) ?>">Abrir ação</a><?php endif; ?>
              <?php if ($alertStatus==='open'): ?>
                <form method="post" action="brain_alert_action.php"><input type="hidden" name="csrf" value="<?= brain_alert_h($csrf) ?>"><input type="hidden" name="return" value="<?= brain_alert_h($returnUrl) ?>"><input type="hidden" name="alert_id" value="<?= $aid ?>"><input type="hidden" name="action" value="resolve"><button type="submit">Resolvido</button></form>
                <form method="post" action="brain_alert_action.php"><input type="hidden" name="csrf" value="<?= brain_alert_h($csrf) ?>"><input type="hidden" name="return" value="<?= brain_alert_h($returnUrl) ?>"><input type="hidden" name="alert_id" value="<?= $aid ?>"><input type="hidden" name="action" value="snooze"><button type="submit" class="secondary">Adiar 24h</button></form>
                <form method="post" action="brain_alert_action.php" onsubmit="return confirm('Ignorar este alerta? Ele poderá ser reaberto nesta tela.')"><input type="hidden" name="csrf" value="<?= brain_alert_h($csrf) ?>"><input type="hidden" name="return" value="<?= brain_alert_h($returnUrl) ?>"><input type="hidden" name="alert_id" value="<?= $aid ?>"><input type="hidden" name="action" value="ignore"><button type="submit" class="ghost">Ignorar</button></form>
              <?php else: ?>
                <form method="post" action="brain_alert_action.php"><input type="hidden" name="csrf" value="<?= brain_alert_h($csrf) ?>"><input type="hidden" name="return" value="<?= brain_alert_h($returnUrl) ?>"><input type="hidden" name="alert_id" value="<?= $aid ?>"><input type="hidden" name="action" value="reopen"><button type="submit">Reabrir</button></form>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
    <?php if (count($alerts)>=100): ?><p class="brain-list-limit">A consulta mostra os 100 registros mais recentes para os filtros selecionados.</p><?php endif; ?>
  <?php endif; ?>
</main>
<?php admin_footer(); ?>
