<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo.php';
require_once __DIR__ . '/helpers.php';

function tenant_host_name(): string {
  return current_host_name();
}

function tenant_is_base_domain(string $host): bool {
  $host = strtolower(trim($host));
  if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') return true;
  foreach (configured_root_domains() as $rootDomain) {
    if ($host === $rootDomain || $host === 'www.' . $rootDomain) return true;
  }
  return false;
}

function tenant_extract_subdomain(string $host): string {
  $host = strtolower(trim($host));
  if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return '';

  foreach (configured_root_domains() as $rootDomain) {
    if ($host === $rootDomain || $host === 'www.' . $rootDomain) return '';
    $suffix = '.' . $rootDomain;
    if (str_ends_with($host, $suffix)) {
      $sub = substr($host, 0, -strlen($suffix));
      $parts = array_values(array_filter(explode('.', $sub)));
      return count($parts) === 1 ? ($parts[0] ?? '') : '';
    }
  }

  $parts = explode('.', $host);
  if (count($parts) >= 3) {
    return $parts[0] ?? '';
  }

  return '';
}

function tenant_latest_subscription(PDO $pdo, int $companyId): ?array {
  try {
    $st = $pdo->prepare("
      SELECT s.*, p.name AS plan_name
      FROM subscriptions s
      LEFT JOIN plans p ON p.id = s.plan_id
      WHERE s.company_id = ?
      ORDER BY s.id DESC
      LIMIT 1
    ");
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function tenant_render_block_page(string $title, string $message): void {
  http_response_code(403);
  ?>
  <!doctype html>
  <html lang="pt-br">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
      body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
      .box{width:min(92vw,620px);background:#fff;border:1px solid #e8eef5;border-radius:22px;padding:28px;box-shadow:0 12px 32px rgba(2,6,23,.06)}
      .tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;font-weight:700;margin-bottom:14px}
      h1{margin:0 0 12px;font-size:30px}
      p{margin:0 0 10px;color:#475569;line-height:1.6}
      .btn{display:inline-flex;margin-top:16px;padding:12px 18px;border-radius:12px;background:#0f766e;color:#fff;text-decoration:none;font-weight:700}
    </style>
  </head>
  <body>
    <div class="box">
      <div class="tag">Acesso bloqueado</div>
      <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
      <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
      <p>Consulte a situação da sua assinatura e regularize eventuais pendências de pagamento.</p>
      <a class="btn" href="https://nexaprospero.com.br/">Escolher um plano</a>
    </div>
  </body>
  </html>
  <?php
  exit;
}

function tenant_access_status(PDO $pdo, array $company): array {
  require_once __DIR__ . '/saas_trial_cycle.php';
  $companyStatus=strtolower((string)($company['status']??'active'));
  if (in_array($companyStatus,['suspended','blocked'],true)) return ['allowed'=>false,'reason'=>'Empresa bloqueada ou suspensa.'];
  $sub=tenant_latest_subscription($pdo,(int)($company['id']??0));
  if (!$sub) return ['allowed'=>false,'reason'=>'Assinatura não localizada.'];
  $status=strtolower((string)($sub['status']??''));
  if (in_array($status,['pending_payment','canceled','cancelled'],true)) return ['allowed'=>false,'reason'=>'Assinatura não ativa.'];
  if (in_array($status,['trial','past_due'],true) && empty($sub['ends_at'])) return ['allowed'=>false,'reason'=>'Validade da assinatura não informada.'];
  if (in_array($status,['trial','active','past_due'],true)) {
    $cycle=nexa_saas_cycle_notice_for_contract($pdo,(int)($company['id']??0),nexa_saas_cycle_state($sub));
    return ['allowed'=>!$cycle['blocked'],'reason'=>$cycle['message']?:$cycle['phase']];
  }
  return ['allowed'=>$companyStatus!=='blocked','reason'=>'Consulte a assinatura.'];
}

function tenant_resolve_company(PDO $pdo, ?string $slug, bool $deferAccessCheck = false): array {
  $slug = trim((string)$slug);
  $company = null;

  if ($slug !== '') {
    $st = $pdo->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } else {
    $host = tenant_host_name();
    if ($host !== '' && !tenant_is_base_domain($host) && !filter_var($host, FILTER_VALIDATE_IP)) {
      try {
        $st = $pdo->prepare('SELECT * FROM companies WHERE LOWER(custom_domain) = ? LIMIT 1');
        $st->execute([$host]);
        $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      } catch (Throwable $e) {
        $company = null;
      }

      if (!$company) {
        $sub = tenant_extract_subdomain($host);
        if ($sub !== '' && !in_array($sub, ['www', 'app'], true)) {
          try {
            $st = $pdo->prepare('SELECT * FROM companies WHERE slug = ? OR subdomain = ? LIMIT 1');
            $st->execute([$sub, $sub]);
            $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          } catch (Throwable $e) {
            $st = $pdo->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
            $st->execute([$sub]);
            $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          }
        }
      }
    }
  }

  if (!$company) {
    http_response_code(404);
    echo 'Empresa não encontrada.';
    exit;
  }

  $access = tenant_access_status($pdo, $company);
  if (!$deferAccessCheck && !$access['allowed']) {
    tenant_render_block_page('Catálogo temporariamente indisponível', $access['reason']);
  }

  return $company;
}

function tenant_company_id(array $company): int {
  return (int)($company['id'] ?? 0);
}
