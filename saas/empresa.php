<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';
require_once __DIR__ . '/../config/password_setup.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/saas_subscription_bridge.php';
try { saas_ensure_modules_schema($pdo); } catch (Throwable $e) {}
try { saas_whatsapp_ensure_schema($pdo); } catch (Throwable $e) {}

/**
 * Ativar uma assinatura que ainda não tem contrato ATIVO na empresa faturadora
 * exige a conferência em Assinaturas Nexa > Vincular contratos (módulos,
 * quantidades e valores). Sem integração configurada, segue o fluxo antigo.
 */
function saas_empresa_activation_needs_contract(PDO $pdo, int $companyId): bool {
  try {
    if (!saas_sub_bridge_schema_ready($pdo) || !saas_sub_bridge_enabled($pdo)) return false;
    $billingCompanyId = (int)(saas_sub_bridge_config($pdo)['billing_company_id'] ?? 0);
    if ($billingCompanyId <= 0) return false;
    $link = saas_sub_bridge_link($pdo, $companyId);
    $contractId = (int)($link['contract_id'] ?? 0);
    if ($contractId <= 0) return true;
    $st = $pdo->prepare('SELECT status FROM contracts WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$billingCompanyId, $contractId]);
    return strtoupper((string)$st->fetchColumn()) !== 'ATIVO';
  } catch (Throwable $e) {
    return false;
  }
}

function money_br(float $v): string {
  return 'R$ ' . number_format($v, 2, ',', '.');
}

function random_pass(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  $out = '';
  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }
  return $out;
}

function saas_empresa_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function badge_color(?string $status): string {
  return match ($status) {
    'active' => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
    'trial' => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
    'past_due' => 'background:#fff7ed;color:#c2410c;border:1px solid #fdba74;',
    'canceled' => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
    'complimentary' => 'background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;',
    default => 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;',
  };
}

function saas_sub_status_label(?string $status): string {
  return match (strtolower((string)$status)) {
    'trial' => 'Trial', 'active' => 'Ativa', 'past_due' => 'Em atraso', 'canceled', 'cancelled' => 'Cancelada',
    'pending_payment' => 'Aguardando pagamento', 'complimentary' => 'Bonificada', default => (string)$status,
  };
}

/** O status Bonificada depende da migração 20261104_saas_status_bonificada_v1. */
function saas_empresa_complimentary_ready(PDO $pdo): bool {
  try {
    $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND column_name = 'status' LIMIT 1");
    $st->execute();
    return str_contains((string)$st->fetchColumn(), "'complimentary'");
  } catch (Throwable $e) {
    return false;
  }
}

function date_input_value(?string $dt): string {
  $dt = trim((string)$dt);
  if ($dt === '') return '';
  try { return (new DateTime($dt))->format('Y-m-d'); } catch (Throwable $e) { return ''; }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('Empresa inv&aacute;lida.');

$hasPlanInternal = saas_empresa_has_column($pdo, 'plans', 'is_internal');
$hasPlanPublic = saas_empresa_has_column($pdo, 'plans', 'is_public');
$hasManualNote = saas_empresa_has_column($pdo, 'subscriptions', 'manual_note');
$hasGrantedBy = saas_empresa_has_column($pdo, 'subscriptions', 'granted_by');
$hasGrantedReason = saas_empresa_has_column($pdo, 'subscriptions', 'granted_reason');
$hasSubUpdatedAt = saas_empresa_has_column($pdo, 'subscriptions', 'updated_at');

$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) die('Empresa n&atilde;o encontrada.');

$desiredPlan = null;
if (array_key_exists('desired_plan_id', $company) && (int)($company['desired_plan_id'] ?? 0) > 0) {
  $stPlan = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
  $stPlan->execute([(int)$company['desired_plan_id']]);
  $desiredPlan = $stPlan->fetch(PDO::FETCH_ASSOC) ?: null;
}

function load_subscription(PDO $pdo, int $companyId): ?array {
  $stmt = $pdo->prepare("
    SELECT s.*, p.name AS plan_name, p.price
    FROM subscriptions s
    INNER JOIN plans p ON p.id = s.plan_id
    WHERE s.company_id = ?
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $stmt->execute([$companyId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

$subscription = load_subscription($pdo, $id);

$stmt = $pdo->prepare("
  SELECT *
  FROM users
  WHERE company_id = ?
    AND role = 'company_admin'
  ORDER BY id ASC
  LIMIT 1
");
$stmt->execute([$id]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$plans = $pdo->query("
  SELECT *
  FROM plans
  WHERE is_active = 1
  ORDER BY " . ($hasPlanInternal ? "is_internal ASC, " : "") . "price ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

try { saas_seed_default_modules($pdo); } catch (Throwable $e) {}
$modules = saas_all_modules($pdo, true);
$moduleState = saas_company_module_state($pdo, $id);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE company_id = ?");
$stmt->execute([$id]);
$totalPedidos = (int)$stmt->fetchColumn();

$msg = '';
$erro = '';
$passwordLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    // Senha do admin: em vez de gerar e enviar uma senha, gera um link de uso único
    // (válido por 48h) para o próprio cliente criar a senha. A senha atual continua
    // funcionando até o cliente usar o link.
    if (in_array($action, ['reset_password', 'reset_password_send_whatsapp', 'resend_credentials_whatsapp'], true)) {
      if (!$adminUser) {
        throw new RuntimeException('Admin da empresa n&atilde;o encontrado.');
      }
      $purpose = $action === 'resend_credentials_whatsapp' ? 'setup' : 'reset';
      $passwordLink = password_setup_create_link($pdo, $company, (int)$adminUser['id'], $purpose, auth_user_id());
      $msg = 'Link para criar a senha gerado (v&aacute;lido por ' . (int)NEXA_PASSWORD_LINK_HOURS . ' horas, uso &uacute;nico).';

      if ($action !== 'reset_password') {
        $wa = saas_whatsapp_send_credentials($pdo, $id, $passwordLink);
        if (!empty($wa['ok'])) {
          $msg .= ' Os acessos e o link foram enviados pelo WhatsApp.';
        } else {
          $msg .= ' N&atilde;o foi poss&iacute;vel enviar pelo WhatsApp: ' . e((string)($wa['error'] ?? 'verifique as configura&ccedil;&otilde;es do WhatsApp SaaS')) . '. Copie o link abaixo e envie manualmente.';
        }
      }
    }

    if ($action === 'change_plan') {
      $planId = (int)($_POST['plan_id'] ?? 0);
      if ($planId <= 0) {
        throw new RuntimeException('Selecione um plano v&aacute;lido.');
      }

      $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
      $stmt->execute([$planId]);
      $plan = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$plan) {
        throw new RuntimeException('Plano n&atilde;o encontrado.');
      }

      $isTrialPlan = ((int)($plan['trial_days'] ?? 0) > 0)
        || in_array(strtolower((string)($plan['slug'] ?? '')), ['trial','start-free'], true)
        || stripos((string)$plan['name'], 'trial') !== false
        || stripos((string)$plan['name'], 'start free') !== false;
      $windowDays = $isTrialPlan ? max(1, (int)($plan['trial_days'] ?? 7)) : max(1, (int)($plan['billing_days'] ?? 30));
      $subStatus = $isTrialPlan ? 'trial' : 'active';
      $stmt = $pdo->prepare("
        INSERT INTO subscriptions
        (company_id, plan_id, status, started_at, ends_at, next_billing_at)
        VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL {$windowDays} DAY), DATE_ADD(NOW(), INTERVAL {$windowDays} DAY))
      ");
      $stmt->execute([$id, $planId, $subStatus]);
      if (array_key_exists('desired_plan_id', $company)) {
        $stmt = $pdo->prepare("UPDATE companies SET desired_plan_id = ? WHERE id = ?");
        $stmt->execute([$planId, $id]);
        $company['desired_plan_id'] = $planId;
      }

      $msg = 'Plano alterado com sucesso.';
    }

    if ($action === 'update_subscription') {
      $planId = (int)($_POST['plan_id'] ?? 0);
      $status = (string)($_POST['status'] ?? 'active');
      $endsAt = trim((string)($_POST['ends_at'] ?? ''));
      $nextBillingAt = trim((string)($_POST['next_billing_at'] ?? ''));
      $manualNote = trim((string)($_POST['manual_note'] ?? ''));
      $grantReason = trim((string)($_POST['granted_reason'] ?? ''));

      if ($planId <= 0) throw new RuntimeException('Selecione um plano.');
      if (!in_array($status, ['trial','active','past_due','canceled','complimentary'], true)) throw new RuntimeException('Status de assinatura inv&aacute;lido.');
      if ($status === 'complimentary' && !saas_empresa_complimentary_ready($pdo)) throw new RuntimeException('Para usar o status Bonificada, aplique antes "Assinaturas SaaS - status Bonificada" no Atualizador do Banco.');
      if ($endsAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsAt)) throw new RuntimeException('Data de vencimento inv&aacute;lida.');
      if ($nextBillingAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextBillingAt)) throw new RuntimeException('Data de pr&oacute;xima cobran&ccedil;a inv&aacute;lida.');

      $stPlan = $pdo->prepare('SELECT id FROM plans WHERE id = ? LIMIT 1');
      $stPlan->execute([$planId]);
      if (!$stPlan->fetchColumn()) throw new RuntimeException('Plano n&atilde;o encontrado.');

      // Trial/vencida -> ativa sem contrato ATIVO: grava plano e datas, mantém o
      // status atual e abre a conferência do contrato. A assinatura só fica ativa
      // quando o contrato for confirmado lá.
      $activationUrl = '';
      $currentStatus = strtolower((string)($subscription['status'] ?? ''));
      if ($status === 'active' && $currentStatus !== 'active' && saas_empresa_activation_needs_contract($pdo, $id)) {
        $status = in_array($currentStatus, ['trial','active','past_due','canceled','complimentary'], true) ? $currentStatus : 'trial';
        $activationUrl = '/saas/assinaturas_nexa.php?section=contratos&migrate=' . $id . '&activate=1#migracaoExistente';
      }

      $endsSql = $endsAt !== '' ? ($endsAt . ' 23:59:59') : null;
      $nextSql = $nextBillingAt !== '' ? ($nextBillingAt . ' 09:00:00') : $endsSql;

      if ($subscription) {
        $set = ['plan_id = ?', 'status = ?', 'ends_at = ?', 'next_billing_at = ?'];
        $vals = [$planId, $status, $endsSql, $nextSql];
        if ($hasManualNote) { $set[] = 'manual_note = ?'; $vals[] = $manualNote; }
        if ($hasGrantedBy) { $set[] = 'granted_by = ?'; $vals[] = 'Central SaaS'; }
        if ($hasGrantedReason) { $set[] = 'granted_reason = ?'; $vals[] = $grantReason; }
        if ($hasSubUpdatedAt) { $set[] = 'updated_at = NOW()'; }
        $vals[] = (int)$subscription['id'];
        $pdo->prepare('UPDATE subscriptions SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
      } else {
        $cols = ['company_id','plan_id','status','started_at','ends_at','next_billing_at'];
        $vals = [$id, $planId, $status, date('Y-m-d H:i:s'), $endsSql, $nextSql];
        if ($hasManualNote) { $cols[] = 'manual_note'; $vals[] = $manualNote; }
        if ($hasGrantedBy) { $cols[] = 'granted_by'; $vals[] = 'Central SaaS'; }
        if ($hasGrantedReason) { $cols[] = 'granted_reason'; $vals[] = $grantReason; }
        $pdo->prepare('INSERT INTO subscriptions (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
      }

      if (in_array($status, ['trial','active','complimentary'], true)) {
        $pdo->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$id]);
      }

      // Bonificada não gera contrato nem cobrança. Se já existir contrato ATIVO
      // ou de TESTE vinculado, avisa (o cancelamento é manual, em Contratos).
      $complimentaryWarn = '';
      if ($status === 'complimentary') {
        try {
          $bLink = saas_sub_bridge_schema_ready($pdo) ? saas_sub_bridge_link($pdo, $id) : null;
          if ($bLink && (int)($bLink['contract_id'] ?? 0) > 0) {
            $stc = $pdo->prepare("SELECT contract_number, status FROM contracts WHERE company_id = ? AND id = ? LIMIT 1");
            $stc->execute([(int)$bLink['billing_company_id'], (int)$bLink['contract_id']]);
            $bc = $stc->fetch(PDO::FETCH_ASSOC);
            if ($bc && in_array(strtoupper((string)$bc['status']), ['ATIVO','TESTE'], true)) {
              $complimentaryWarn = ' Atenção: esta empresa ainda tem o contrato nº ' . (string)$bc['contract_number'] . ' (' . (string)$bc['status'] . ') na empresa faturadora. Se ela não deve mais ser cobrada, cancele esse contrato em Contratos.';
            }
          }
        } catch (Throwable $e) {}
      }

      if ($activationUrl !== '') {
        header('Location: ' . $activationUrl);
        exit;
      }

      $msg = 'Assinatura, plano e vencimento atualizados com sucesso.' . ($complimentaryWarn ?? '');
    }

    if ($action === 'save_modules') {
      $mode = (string)($_POST['module_mode'] ?? 'custom');
      if ($mode === 'plan') {
        saas_reset_company_modules($pdo, $id);
        $msg = 'Módulos da empresa redefinidos para seguir o plano atual.';
      } else {
        $selected = $_POST['module_ids'] ?? [];
        $selected = is_array($selected) ? $selected : [];
        $expiresAt = trim((string)($_POST['modules_expires_at'] ?? ''));
        $note = trim((string)($_POST['modules_note'] ?? ''));
        saas_save_company_modules_custom($pdo, $id, $selected, $expiresAt !== '' ? $expiresAt : null, $note);
        $msg = 'Módulos da empresa atualizados com sucesso.';
      }
    }

    if ($action === 'suspend_company') {
      $stmt = $pdo->prepare("UPDATE companies SET status = 'suspended' WHERE id = ?");
      $stmt->execute([$id]);
      $msg = 'Cliente suspenso com sucesso.';
    }

    if ($action === 'reactivate_company') {
      $stmt = $pdo->prepare("UPDATE companies SET status = 'active' WHERE id = ?");
      $stmt->execute([$id]);
      $msg = 'Cliente reativado com sucesso.';
    }

    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    $subscription = load_subscription($pdo, $id);
    $moduleState = saas_company_module_state($pdo, $id);

  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$catalogUrl = 'https://' . $company['subdomain'] . '.nexaprospero.com.br';
$adminUrl = $catalogUrl . '/admin';

saas_layout_start(
  'Empresa • Central SaaS',
  e((string)$company['name']),
  e((string)$company['subdomain']) . '.nexaprospero.com.br'
);
?>

<div class="top-actions">
  <a class="btn2 btn2-primary" href="<?= e($catalogUrl) ?>" target="_blank" rel="noopener">Abrir cat&aacute;logo</a>
  <a class="btn2 btn2-ghost" href="<?= e($adminUrl) ?>" target="_blank" rel="noopener">Abrir admin</a>
  <a class="btn2 btn2-ghost" href="/saas/revendas.php?id=<?= (int)$id ?>">Configurar revenda</a>
  <a class="btn2 btn2-ghost" href="/saas/whatsapp.php">WhatsApp SaaS</a>
  <a class="btn2 btn2-ghost" href="/saas/empresas.php">Voltar</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="panel">
    <h3>Dados da empresa</h3>
    <p><strong>Empresa:</strong> <?= e((string)$company['name']) ?></p>
    <p><strong>E-mail:</strong> <?= e((string)($company['email'] ?? '—')) ?></p>
    <p><strong>WhatsApp:</strong> <?= e((string)($company['whatsapp'] ?? '—')) ?></p>
    <?php if ($desiredPlan): ?><p><strong>Plano de interesse:</strong> <?= e((string)$desiredPlan['name']) ?></p><?php endif; ?>
    <p><strong>Status:</strong> <?= e((string)($company['status'] ?? '—')) ?></p>
    <p><strong>Subdom&iacute;nio:</strong> <?= e((string)($company['subdomain'] ?? '—')) ?></p>
    <p><strong>Cadastro:</strong> <?= e(saas_date_br($company['created_at'] ?? null)) ?></p>
    <p><strong>Total de pedidos:</strong> <?= (int)$totalPedidos ?></p>

    <div class="row-actions" style="margin-top:14px">
      <?php if (($company['status'] ?? '') !== 'suspended'): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="suspend_company">
          <button class="btn2 btn2-danger" type="submit">Suspender cliente</button>
        </form>
      <?php else: ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="reactivate_company">
          <button class="btn2 btn2-dark" type="submit">Reativar cliente</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h3>Assinatura atual</h3>
    <?php if ($subscription): ?>
      <p><strong>Plano:</strong> <?= e((string)$subscription['plan_name']) ?> (<?= money_br((float)$subscription['price']) ?>)</p>
      <p><strong>Status:</strong> <span class="pill" style="<?= badge_color($subscription['status'] ?? null) ?>"><?= e(saas_sub_status_label($subscription['status'] ?? '')) ?></span></p>
      <p><strong>In&iacute;cio:</strong> <?= e(saas_date_br($subscription['started_at'] ?? null)) ?></p>
      <p><strong>Vencimento:</strong> <?= e(saas_date_br($subscription['ends_at'] ?? null)) ?></p>
      <p><strong>Pr&oacute;x. cobran&ccedil;a:</strong> <?= e(saas_date_br($subscription['next_billing_at'] ?? null)) ?></p>
      <?php if ($hasManualNote && !empty($subscription['manual_note'])): ?><p><strong>Obs. interna:</strong> <?= nl2br(e((string)$subscription['manual_note'])) ?></p><?php endif; ?>
    <?php else: ?>
      <p class="muted">Nenhuma assinatura encontrada.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Controle de plano, vencimento e bonificação</h3>
    <p class="muted">Use esta área para liberar planos internos, bonificar clientes por 3/6/12 meses ou ajustar manualmente a próxima cobrança.</p>
    <form method="post" id="subscriptionForm">
      <input type="hidden" name="action" value="update_subscription">
      <label>Plano</label>
      <select name="plan_id" class="field" required>
        <option value="">Selecione...</option>
        <?php foreach ($plans as $p): ?>
          <?php $selected = $subscription && (int)$subscription['plan_id'] === (int)$p['id']; ?>
          <option value="<?= (int)$p['id'] ?>" <?= $selected ? 'selected' : '' ?>>
            <?= e((string)$p['name']) ?> - <?= money_br((float)$p['price']) ?><?= !empty($p['is_internal']) ? ' • INTERNO' : '' ?><?= $hasPlanPublic && empty($p['is_public']) ? ' • OCULTO NO SITE' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div style="height:12px"></div>
      <label>Status da assinatura</label>
      <select name="status" class="field" required>
        <?php foreach (['trial'=>'Trial','active'=>'Ativa','complimentary'=>'Bonificada (sem cobrança)','past_due'=>'Em atraso','canceled'=>'Cancelada'] as $k=>$v): ?>
          <option value="<?= e($k) ?>" <?= (($subscription['status'] ?? 'active') === $k) ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="height:12px"></div>
      <div class="grid-2" style="gap:12px">
        <div>
          <label>Vencimento</label>
          <input class="field" type="date" name="ends_at" id="ends_at" value="<?= e(date_input_value($subscription['ends_at'] ?? null)) ?>">
        </div>
        <div>
          <label>Pr&oacute;xima cobran&ccedil;a</label>
          <input class="field" type="date" name="next_billing_at" id="next_billing_at" value="<?= e(date_input_value($subscription['next_billing_at'] ?? null)) ?>">
        </div>
      </div>
      <div class="row-actions" style="margin-top:12px">
        <button type="button" class="mini" data-add-months="3">Bonificar 3 meses</button>
        <button type="button" class="mini" data-add-months="6">Bonificar 6 meses</button>
        <button type="button" class="mini" data-add-months="12">Bonificar 1 ano</button>
      </div>
      <div style="height:12px"></div>
      <label>Motivo/observação da liberação</label>
      <input class="field" name="granted_reason" placeholder="Ex.: bonificação comercial, parceria, cortesia, implantação">
      <div style="height:12px"></div>
      <label>Observação interna</label>
      <textarea class="field" name="manual_note" rows="3" placeholder="Anotações internas para a equipe SaaS."><?= $hasManualNote ? e((string)($subscription['manual_note'] ?? '')) : '' ?></textarea>
      <div style="height:14px"></div>
      <button class="btn2 btn2-dark" type="submit">Salvar assinatura</button>
    </form>
  </div>

  <div class="panel">
    <h3>Módulos liberados</h3>
    <p class="muted">Controle real do que aparece no menu do Admin da empresa. A opção personalizada tem prioridade sobre os módulos do plano.</p>
    <?php if (!saas_modules_available($pdo)): ?>
      <div class="warn">Rode o patch de módulos para ativar este controle.</div>
    <?php endif; ?>
    <form method="post" id="modulesForm">
      <input type="hidden" name="action" value="save_modules">
      <label>Modo de liberação</label>
      <select class="field" name="module_mode" id="module_mode">
        <option value="plan">Seguir módulos do plano</option>
        <option value="custom" selected>Personalizar para esta empresa</option>
      </select>
      <div style="height:12px"></div>
      <div style="display:grid;gap:10px">
        <?php foreach ($moduleState as $m): ?>
          <?php
            $source = $m['override'] === null ? (!empty($m['from_plan']) ? 'Plano' : 'Não liberado pelo plano') : ((int)$m['override'] === 1 ? 'Liberado manualmente' : 'Bloqueado manualmente');
            $sourceStyle = !empty($m['enabled']) ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;';
          ?>
          <label style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fbff;">
            <input type="checkbox" name="module_ids[]" value="<?= (int)$m['id'] ?>" <?= !empty($m['enabled']) ? 'checked' : '' ?> style="margin-top:4px">
            <span style="min-width:0">
              <strong><?= e((string)$m['name']) ?></strong>
              <span class="pill" style="<?= $sourceStyle ?>margin-left:6px"><?= e($source) ?></span><br>
              <span class="muted"><?= e((string)($m['description'] ?? '')) ?></span>
              <?php if (!empty($m['expires_at'])): ?><br><span class="muted">Override até: <?= e(saas_date_br($m['expires_at'])) ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="height:12px"></div>
      <div class="grid-2" style="gap:12px">
        <div>
          <label>Validade da personalização</label>
          <input class="field" type="date" name="modules_expires_at">
          <div class="muted" style="margin-top:6px">Opcional. Em branco, a personalização não expira.</div>
        </div>
        <div>
          <label>Observação</label>
          <input class="field" name="modules_note" placeholder="Ex.: cortesia, implantação, negociação comercial">
        </div>
      </div>
      <div class="row-actions" style="margin-top:14px">
        <button class="btn2 btn2-dark" type="submit" onclick="document.getElementById('module_mode').value='custom'">Salvar módulos personalizados</button>
        <button class="btn2 btn2-ghost" type="submit" onclick="document.getElementById('module_mode').value='plan'">Seguir plano atual</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <h3>Senha do admin</h3>
    <?php if ($adminUser): ?>
      <p><strong>Admin:</strong> <?= e((string)$adminUser['name']) ?></p>
      <p><strong>E-mail:</strong> <?= e((string)$adminUser['email']) ?></p>
      <p class="muted">Por seguran&ccedil;a, o sistema n&atilde;o envia nem guarda senhas. O cliente recebe um link de uso &uacute;nico, v&aacute;lido por <?= (int)NEXA_PASSWORD_LINK_HOURS ?> horas, e cria a pr&oacute;pria senha. A senha atual continua valendo at&eacute; o link ser usado.</p>

      <div class="row-actions" style="margin-top:10px">
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="reset_password">
          <button class="btn2 btn2-dark" type="submit">Gerar link de senha</button>
        </form>
        <form method="post" style="margin:0" onsubmit="return confirm('Gerar um link de senha e enviar os acessos no WhatsApp da empresa?');">
          <input type="hidden" name="action" value="reset_password_send_whatsapp">
          <button class="btn2 btn2-primary" type="submit">Gerar e enviar WhatsApp</button>
        </form>
      </div>

      <?php if ($passwordLink !== ''): ?>
        <div style="margin-top:14px">
          <div class="muted" style="margin-bottom:8px">Link para o cliente criar a senha (copie e envie, se necess&aacute;rio)</div>
          <code class="box" id="password-link" style="word-break:break-all"><?= e($passwordLink) ?></code>
          <button class="btn2 btn2-ghost" type="button" style="margin-top:8px" onclick="navigator.clipboard.writeText(document.getElementById('password-link').innerText).then(function(){alert('Link copiado.');});">Copiar link</button>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Admin da empresa n&atilde;o encontrado.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Troca rápida de plano</h3>
    <form method="post">
      <input type="hidden" name="action" value="change_plan">
      <label>Selecione o plano</label><br><br>
      <select name="plan_id" class="field" required>
        <option value="">Selecione...</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= (int)$p['id'] ?>">
            <?= e((string)$p['name']) ?> - <?= money_br((float)$p['price']) ?><?= !empty($p['max_orders']) ? ' • at&eacute; ' . (int)$p['max_orders'] . ' pedidos' : (((int)($p['trial_days'] ?? 0) > 0) ? ' • ' . (int)($p['trial_days'] ?? 0) . ' dias gr&aacute;tis' : '') ?><?= !empty($p['is_internal']) ? ' • interno' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div style="height:14px"></div>
      <button class="btn2 btn2-dark" type="submit">Alterar plano</button>
    </form>
  </div>
</div>

<script>
(function(){
  function pad(n){ return String(n).padStart(2,'0'); }
  function toInputDate(d){ return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
  document.querySelectorAll('[data-add-months]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var months = parseInt(btn.getAttribute('data-add-months') || '0', 10);
      var startValue = document.getElementById('ends_at').value;
      var base = startValue ? new Date(startValue + 'T12:00:00') : new Date();
      base.setMonth(base.getMonth() + months);
      var value = toInputDate(base);
      document.getElementById('ends_at').value = value;
      document.getElementById('next_billing_at').value = value;
    });
  });
})();
</script>

<?php saas_layout_end(); ?>
