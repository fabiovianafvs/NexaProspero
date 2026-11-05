<?php
declare(strict_types=1);

if (!function_exists('saas_get_latest_subscription')) {
  function saas_get_latest_subscription(PDO $pdo, int $companyId): ?array {
    $stmt = $pdo->prepare("
      SELECT s.*, p.name AS plan_name, p.price
      FROM subscriptions s
      LEFT JOIN plans p ON p.id = s.plan_id
      WHERE s.company_id = ?
      ORDER BY s.id DESC
      LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

require_once __DIR__ . '/../config/saas_trial_cycle.php';
if (!function_exists('saas_company_is_blocked')) {
  function saas_company_is_blocked(PDO $pdo, array $company): array {
    $companyStatus = strtolower((string)($company['status'] ?? 'active'));
    if ($companyStatus === 'suspended') return ['blocked'=>true,'reason'=>'Esta empresa está suspensa administrativamente.'];
    if ($companyStatus === 'blocked') return ['blocked'=>true,'reason'=>'A empresa está bloqueada. Regularize a cobrança ou solicite revisão do bloqueio.'];
    $sub=saas_get_latest_subscription($pdo,(int)($company['id']??0));
    if (!$sub) return ['blocked'=>true,'reason'=>'Nenhuma assinatura encontrada. Contate o suporte.'];
    $status=strtolower((string)($sub['status']??''));
    if (in_array($status,['canceled','cancelled','pending_payment'],true)) return ['blocked'=>true,'reason'=>'Assinatura não ativa. Consulte a regularização.'];
    if (in_array($status,['trial','past_due'],true) && empty($sub['ends_at'])) return ['blocked'=>true,'reason'=>'Validade da assinatura não informada.'];
    $cycle=nexa_saas_cycle_state($sub);
    $cycle=nexa_saas_cycle_notice_for_contract($pdo,(int)($company['id']??0),$cycle);
    if ($status==='trial'||$status==='active'||$status==='past_due') {
      // Bloqueios por inadimplência seguem o período de tolerância. O cron
      // revalida o status de companies; suspensões manuais permanecem bloqueadas.
      return ['blocked'=>(bool)$cycle['blocked'],'reason'=>(string)$cycle['message'],'cycle'=>$cycle];
    }
    return ['blocked'=>$companyStatus==='blocked','reason'=>'Verifique sua assinatura.','cycle'=>$cycle];
  }
}
if (!function_exists('saas_company_can_self_regularize')) {
  /**
   * O próprio cliente pode gerar o boleto/Pix e reativar quando a assinatura está
   * vencida/aguardando pagamento. Não pode quando está cancelada ou quando a empresa
   * foi suspensa administrativamente.
   */
  function saas_company_can_self_regularize(PDO $pdo, array $company): bool {
    if (strtolower((string)($company['status'] ?? 'active')) === 'suspended') return false;
    $sub = saas_get_latest_subscription($pdo, (int)($company['id'] ?? 0));
    if (!$sub) return false;
    return in_array(strtolower((string)($sub['status'] ?? '')), ['trial', 'active', 'past_due', 'pending_payment'], true);
  }
}

if (!function_exists('saas_render_block_page')) {
  function saas_render_block_page(string $title, string $message, string $actionUrl = '', string $actionLabel = ''): void {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
      <style>
        body{
          margin:0;
          font-family:Arial, Helvetica, sans-serif;
          background:#f5f7fb;
          display:flex;
          align-items:center;
          justify-content:center;
          min-height:100vh;
          color:#0f172a;
        }
        .box{
          width:min(92vw, 560px);
          background:#fff;
          border:1px solid #e8eef5;
          border-radius:22px;
          padding:28px;
          box-shadow:0 12px 32px rgba(2,6,23,.06);
        }
        h1{margin:0 0 12px;font-size:28px}
        p{margin:0 0 10px;color:#475569;line-height:1.6}
        .tag{
          display:inline-flex;
          padding:8px 12px;
          border-radius:999px;
          background:#fff7ed;
          color:#9a3412;
          border:1px solid #fdba74;
          font-weight:700;
          margin-bottom:14px;
        }
      </style>
    </head>
    <body>
      <div class="box">
        <div class="tag">Acesso bloqueado</div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($actionUrl !== ''): ?>
          <p>Se você é o responsável pela empresa, entre no painel para gerar o boleto/Pix e reativar o sistema. O acesso volta assim que o pagamento for confirmado.</p>
          <p><a href="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block;margin-top:6px;padding:12px 18px;border-radius:12px;background:#0b1f5b;color:#fff;font-weight:700;text-decoration:none"><?= htmlspecialchars($actionLabel !== '' ? $actionLabel : 'Entrar e regularizar', ENT_QUOTES, 'UTF-8') ?></a></p>
        <?php else: ?>
        <p>Entre em contato para regularizar o acesso.</p>
        <?php endif; ?>
      </div>
    </body>
    </html>
    <?php
    exit;
  }
}
if (!function_exists('saas_enforce_company_access')) {
  function saas_enforce_company_access(PDO $pdo, array $company, string $context = 'catalog'): void {
    $check = saas_company_is_blocked($pdo, $company);
    if (!$check['blocked']) {
      return;
    }

    $title = $context === 'admin' ? 'Painel temporariamente indisponível' : 'Catálogo temporariamente indisponível';
    $message = (string)($check['reason'] ?? 'Acesso bloqueado no momento.');
    // Vencido (não cancelado e não suspenso manualmente): oferece o caminho para o
    // responsável entrar e pagar. O painel leva direto à tela de Contratação e renovação.
    $actionUrl = '';
    if (saas_company_can_self_regularize($pdo, $company)) {
      $slug = trim((string)($company['slug'] ?? ''));
      $actionUrl = '/admin/login.php' . (function_exists('tenant_extract_subdomain') && $slug !== '' && !tenant_extract_subdomain((string)($_SERVER['HTTP_HOST'] ?? '')) ? '?company=' . rawurlencode($slug) : '');
    }
    saas_render_block_page($title, $message, $actionUrl, 'Sou o responsável: entrar e regularizar');
  }
}
