<?php
declare(strict_types=1);

// Portal do Suporte > WhatsApp: monitor do motor de WhatsApp leve (Baileys,
// VPS própria). Uma única consulta ao motor traz todas as sessões; nada aqui
// abre sessão sozinho. Ações: reiniciar, parar, novo QR, ver QR e passar para o
// motor novo quem ainda estiver no serviço antigo (Chrome, em desativação).

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';
require_once __DIR__ . '/../config/config_store.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';
require_once __DIR__ . '/../config/reseller_integrations.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

$user = require_support_user($pdo);
support_ensure_schema($pdo);
try { reseller_integrations_ensure_schema($pdo); } catch (Throwable $e) {}

const SUPPORT_WA_DEFAULT_SERVICE = 'http://127.0.0.1:3333';

function support_wa_normalize_service_url(string $url): string {
  $url = rtrim(trim($url), '/');
  return $url !== '' ? $url : SUPPORT_WA_DEFAULT_SERVICE;
}

function support_wa_valid_session_id(string $value): bool {
  return (bool)preg_match('/^[1-9][0-9]{0,19}$/', $value);
}

function support_wa_rows(PDO $pdo): array {
  $rows = [];

  // 1) WhatsApps das empresas Nexa (Admin > WhatsApp).
  try {
    $st = $pdo->query("SELECT c.id, c.name, c.slug, ac.cfg_json FROM companies c LEFT JOIN app_config ac ON ac.company_id=c.id AND ac.cfg_key='whatsapp' ORDER BY c.name ASC");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cfg = [];
      if (!empty($r['cfg_json'])) {
        $tmp = json_decode((string)$r['cfg_json'], true);
        if (is_array($tmp)) $cfg = $tmp;
      }
      $enabled = !empty($cfg['enabled']);
      if (!$enabled && empty($r['cfg_json'])) continue;
      $sessionId = preg_replace('/\D+/', '', (string)($cfg['session_company_id'] ?? '')) ?: '';
      if ($sessionId === '' || $sessionId === '0') $sessionId = (string)$r['id'];
      $rows[] = [
        'owner_key' => 'company:' . (int)$r['id'],
        'source' => 'company',
        'source_label' => 'Empresa Nexa',
        'company_id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'slug' => (string)$r['slug'],
        'enabled' => $enabled,
        'provider' => 'unofficial',
        'service_url' => support_wa_normalize_service_url((string)($cfg['service_url'] ?? '')),
        'api_token' => trim((string)($cfg['api_token'] ?? '')),
        'session_company_id' => $sessionId,
      ];
    }
  } catch (Throwable $e) {}

  // 2) Central SaaS. Usa o mesmo serviço WebJS, mas com ID técnico reservado.
  try {
    $cfg = saas_whatsapp_load_config($pdo);
    $provider = (string)($cfg['provider'] ?? 'unofficial');
    $sessionId = $provider === 'unofficial' ? saas_whatsapp_unofficial_instance_id($cfg) : '';
    $rows[] = [
      'owner_key' => 'saas:1',
      'source' => 'saas',
      'source_label' => 'Central SaaS',
      'company_id' => null,
      'name' => 'Central SaaS Nexa Próspero',
      'slug' => '/saas/whatsapp.php',
      'enabled' => !empty($cfg['enabled']),
      'provider' => $provider,
      'service_url' => support_wa_normalize_service_url((string)($cfg['service_url'] ?? '')),
      'api_token' => trim((string)($cfg['api_token'] ?? '')),
      'session_company_id' => $sessionId,
    ];
  } catch (Throwable $e) {}

  // 3) WhatsApps das revendas. Lista todas as revendas ativas, mesmo antes da primeira
  // conexão, para que o suporte enxergue também o número comercial cadastrado.
  try {
    $sql = "SELECT c.id AS reseller_company_id, c.name, c.slug, c.reseller_brand_name, c.reseller_whatsapp,
                   COALESCE(rw.enabled,0) AS enabled, COALESCE(rw.provider,'unofficial') AS provider,
                   rw.service_url, rw.api_token, rw.unofficial_instance_id
            FROM companies c
            LEFT JOIN saas_reseller_whatsapp_config rw ON rw.reseller_company_id = c.id
            WHERE c.is_reseller=1 AND COALESCE(c.reseller_status,'inactive')='active'
            ORDER BY COALESCE(NULLIF(c.reseller_brand_name,''), c.name, CONCAT('Revenda #', c.id))";
    $st = $pdo->query($sql);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $resellerId = (int)$r['reseller_company_id'];
      $provider = in_array((string)$r['provider'], ['unofficial','official'], true) ? (string)$r['provider'] : 'unofficial';
      $sessionId = preg_replace('/\D+/', '', (string)($r['unofficial_instance_id'] ?? '')) ?: '';
      if ($provider === 'unofficial' && ($sessionId === '' || $sessionId === '0')) $sessionId = reseller_whatsapp_default_instance_id($resellerId);
      $displayName = trim((string)($r['reseller_brand_name'] ?? ''));
      if ($displayName === '') $displayName = trim((string)($r['name'] ?? ''));
      if ($displayName === '') $displayName = 'Revenda #' . $resellerId;
      $rows[] = [
        'owner_key' => 'reseller:' . $resellerId,
        'source' => 'reseller',
        'source_label' => 'Revenda',
        'company_id' => $resellerId,
        'name' => $displayName,
        'slug' => trim((string)($r['slug'] ?? '')) !== '' ? (string)$r['slug'] : '/revenda/whatsapp.php',
        'enabled' => !empty($r['enabled']),
        'provider' => $provider,
        'service_url' => support_wa_normalize_service_url((string)($r['service_url'] ?? '')),
        'api_token' => trim((string)($r['api_token'] ?? '')),
        'session_company_id' => $provider === 'unofficial' ? $sessionId : '',
        'contact_phone' => preg_replace('/\D+/', '', (string)($r['reseller_whatsapp'] ?? '')) ?: '',
      ];
    }
  } catch (Throwable $e) {}

  usort($rows, static function(array $a, array $b): int {
    $rank = ['saas'=>0,'reseller'=>1,'company'=>2];
    return ($rank[$a['source']] ?? 9) <=> ($rank[$b['source']] ?? 9)
      ?: strcasecmp((string)$a['name'], (string)$b['name']);
  });
  return $rows;
}

function support_wa_status_label(string $status): string {
  return match ($status) {
    'connected' => 'Conectado',
    'initializing' => 'Conectando',
    'qr' => 'Aguardando QR',
    'stored' => 'Salva (não aberta)',
    'legacy' => 'Motor antigo',
    'official' => 'API Oficial',
    'invalid' => 'Configuração inválida',
    default => 'Desconectado',
  };
}

function support_wa_format_updated_at(string $value): string {
  $value = trim($value);
  if ($value === '') return '—';
  try {
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s');
  } catch (Throwable $e) {
    return $value;
  }
}

function support_wa_phone_format(string $digits): string {
  if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $digits, $m)) return '(' . $m[1] . ') ' . $m[2] . '-' . $m[3];
  return $digits;
}

$engine = whatsapp_engine_config($pdo);
$engineReady = whatsapp_engine_ready($engine);
$rows = support_wa_rows($pdo);
foreach ($rows as &$row) {
  $row['on_engine'] = $engineReady && whatsapp_engine_is_url($engine, (string)$row['service_url']);
}
unset($row);
$rowsByKey = [];
foreach ($rows as $row) $rowsByKey[(string)$row['owner_key']] = $row;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  $ownerKey = trim((string)($_POST['owner_key'] ?? ''));
  $target = $rowsByKey[$ownerKey] ?? null;
  $sid = $target ? (string)$target['session_company_id'] : '';
  $result = ['ok' => false, 'message' => 'Ação inválida.'];
  try {
    if (!support_verify_csrf((string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    if (!$engineReady) throw new RuntimeException('O motor novo ainda não foi instalado.');
    if ($action === 'migrate_all') {
      $res = whatsapp_engine_migrate_all($pdo, 0);
      $parts = [];
      if ($res['companies']) $parts[] = $res['companies'] . ' empresa(s)';
      if ($res['saas']) $parts[] = 'Central SaaS';
      if ($res['resellers']) $parts[] = $res['resellers'] . ' revenda(s)';
      $msg = $parts ? 'Passaram para o motor novo: ' . implode(', ', $parts) . '. Cada uma precisa ler o QR Code uma vez.' : 'Nada a passar: todos já estão no motor novo.';
      if ($res['errors']) $msg .= ' Falhas: ' . implode(' | ', array_slice($res['errors'], 0, 5));
      $result = ['ok' => !$res['errors'], 'message' => $msg];
      $sid = 'todas';
    } elseif (!$target) {
      throw new RuntimeException('Configuração não encontrada.');
    } elseif ($action === 'migrate_one') {
      if ($target['source'] !== 'company') throw new RuntimeException('Use "Passar todas" para Central SaaS e revendas.');
      whatsapp_engine_migrate_company($pdo, (int)$target['company_id'], 0, true, true);
      header('Location: /suporte/whatsapp.php?qr=' . rawurlencode($ownerKey) . '&t=' . time() . '#qr');
      $_SESSION['support_wa_flash'] = ['ok' => true, 'message' => $target['name'] . ' passou para o motor novo. Leia o QR Code abaixo.'];
      support_whatsapp_audit($pdo, (int)$user['id'], $target['company_id'] ? (int)$target['company_id'] : null, $sid, 'migrate_one', true, '[' . $target['source_label'] . '] motor novo');
      exit;
    } else {
      if (empty($target['on_engine'])) throw new RuntimeException('Esta configuração ainda está no motor antigo. Passe-a para o motor novo primeiro.');
      if (($target['provider'] ?? '') !== 'unofficial' || !support_wa_valid_session_id($sid)) throw new RuntimeException('Esta configuração não tem sessão gerenciável.');
      $path = match ($action) {
        'restart' => '/admin/session/restart',
        'stop' => '/admin/session/stop',
        'new_qr' => '/admin/session/new-qr',
        default => '',
      };
      if ($path === '') throw new RuntimeException('Ação inválida.');
      $res = whatsapp_engine_request($engine, 'POST', $path, ['company_id' => $sid], 20);
      $result = ['ok' => !empty($res['ok']), 'message' => (string)($res['message'] ?? 'Operação concluída.')];
      if ($action === 'new_qr') {
        $_SESSION['support_wa_flash'] = $result;
        support_whatsapp_audit($pdo, (int)$user['id'], $target['company_id'] ? (int)$target['company_id'] : null, $sid, $action, true, '[' . $target['source_label'] . '] ' . $result['message']);
        header('Location: /suporte/whatsapp.php?qr=' . rawurlencode($ownerKey) . '&t=' . time() . '#qr');
        exit;
      }
    }
  } catch (Throwable $e) {
    $result = ['ok' => false, 'message' => $e->getMessage()];
  }
  support_whatsapp_audit($pdo, (int)$user['id'], $target && !empty($target['company_id']) ? (int)$target['company_id'] : null, $sid, $action, !empty($result['ok']), '[' . ($target['source_label'] ?? 'WhatsApp') . '] ' . $result['message']);
  $_SESSION['support_wa_flash'] = $result;
  header('Location: /suporte/whatsapp.php');
  exit;
}

// Uma consulta ao motor traz todas as sessões (não abre nenhuma).
$health = whatsapp_engine_health($engine);
$sessions = [];
if (!empty($health['ok'])) {
  try {
    foreach ((array)(whatsapp_engine_request($engine, 'GET', '/admin/sessions', [], 6)['items'] ?? []) as $it) {
      if (is_array($it)) $sessions[(string)($it['company_id'] ?? '')] = $it;
    }
  } catch (Throwable $e) {
    $health = ['ok' => false, 'error' => $e->getMessage()];
  }
}

function support_wa_row_status(array $row, array $sessions, bool $engineOnline): string {
  if (($row['provider'] ?? 'unofficial') === 'official') return 'official';
  if (empty($row['on_engine'])) return 'legacy';
  if (!support_wa_valid_session_id((string)$row['session_company_id'])) return 'invalid';
  if (!$engineOnline) return 'disconnected';
  $status = strtolower((string)($sessions[(string)$row['session_company_id']]['status'] ?? 'disconnected'));
  return in_array($status, ['connected', 'qr', 'initializing', 'stored'], true) ? $status : 'disconnected';
}

$engineOnline = !empty($health['ok']);
$counts = ['connected' => 0, 'qr' => 0, 'initializing' => 0, 'disconnected' => 0, 'stored' => 0, 'legacy' => 0, 'official' => 0, 'invalid' => 0];
foreach ($rows as $row) $counts[support_wa_row_status($row, $sessions, $engineOnline)]++;

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$filtered = array_values(array_filter($rows, static function (array $row) use ($q, $statusFilter, $sessions, $engineOnline): bool {
  $phone = (string)($sessions[(string)$row['session_company_id']]['phone'] ?? '');
  $haystack = $row['name'] . ' ' . $row['slug'] . ' ' . $row['session_company_id'] . ' ' . $row['source_label'] . ' ' . (string)($row['contact_phone'] ?? '') . ' ' . $phone;
  if ($q !== '' && stripos($haystack, $q) === false) return false;
  if ($statusFilter !== '' && support_wa_row_status($row, $sessions, $engineOnline) !== $statusFilter) return false;
  return true;
}));

// Painel do QR Code (para ajudar o cliente a conectar).
$qrRow = null;
$qrData = null;
$qrKey = trim((string)($_GET['qr'] ?? ''));
if ($qrKey !== '' && isset($rowsByKey[$qrKey]) && !empty($rowsByKey[$qrKey]['on_engine']) && $engineOnline) {
  $qrRow = $rowsByKey[$qrKey];
  try {
    $qrData = whatsapp_engine_request($engine, 'GET', '/qr?company_id=' . rawurlencode((string)$qrRow['session_company_id']), [], 10);
  } catch (Throwable $e) {
    $qrData = ['ok' => false, 'error' => $e->getMessage()];
  }
}

$flash = $_SESSION['support_wa_flash'] ?? null;
unset($_SESSION['support_wa_flash']);
$csrf = support_csrf_token();
$uptimeH = (int)floor(((int)($health['uptime_s'] ?? 0)) / 3600);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp - Suporte Nexa Próspero</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0b1f5b;--muted:#64748b;--line:#dce7f5;--primary:#2454d6;--cyan:#00b8ff;--shadow:0 14px 36px rgba(11,31,91,.08)}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:"Segoe UI",Arial,sans-serif;color:var(--text)}.shell{max-width:1500px;margin:auto;padding:20px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 18px;border-radius:20px;background:linear-gradient(135deg,#0b1f5b,#2454d6);color:#fff}.brand{display:flex;align-items:center;gap:12px}.brand img{width:44px;height:44px;background:#fff;border-radius:12px;padding:4px}.brand strong{font-size:18px}.nav{display:flex;gap:8px;flex-wrap:wrap}.nav a{color:#eaf4ff;text-decoration:none;font-weight:800;padding:9px 13px;border-radius:999px}.nav a.active,.nav a:hover{background:rgba(255,255,255,.16);color:#fff}.user{font-size:12px;text-align:right}.user strong{display:block;font-size:13px}
.hero{display:flex;justify-content:space-between;gap:16px;align-items:end;padding:24px 3px 14px}.hero h1{margin:0;font-size:30px}.hero p{margin:5px 0 0;color:var(--muted)}.hero-side{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.service{font-size:12px;font-weight:900;padding:8px 12px;border-radius:999px;background:#dcfce7;color:#166534}.service.off{background:#fee2e2;color:#991b1b}.refresh-info{font-size:11px;color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px;margin-bottom:14px}.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:10px 14px;box-shadow:var(--shadow);min-width:0}.stat small{display:block;color:var(--muted);font-weight:800;line-height:1.25}.stat strong{font-size:22px}.stat.warn{border-color:#fde68a;background:#fffdf5}
.filters{display:grid;grid-template-columns:minmax(260px,1fr) 220px auto auto;gap:10px;background:#fff;padding:14px;border:1px solid var(--line);border-radius:18px;margin-bottom:14px}.filters input,.filters select{height:44px;border:1px solid var(--line);border-radius:12px;padding:0 12px;font-size:14px;min-width:0}
.btn{display:inline-flex;align-items:center;border:0;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;white-space:nowrap;text-decoration:none;font-size:13px}.btn-primary{background:linear-gradient(135deg,var(--primary),var(--cyan));color:#fff}.btn-light{background:#fff;color:var(--text);border:1px solid #cbd5e1}.btn-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.btn-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.flash{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:800;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.flash.bad{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.legacy-box{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border-radius:16px;margin-bottom:14px;background:#fffbeb;border:1px solid #fde68a;color:#78350f;font-size:13px}.legacy-box form{margin:0}
.qr-card{display:flex;gap:18px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px;margin-bottom:14px;box-shadow:var(--shadow)}.qr-card img{width:250px;height:250px;border:1px solid var(--line);border-radius:12px}.qr-card h3{margin:0 0 6px}
.table-wrap{overflow:auto;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:11px;border-bottom:1px solid #edf2f7;text-align:left;font-size:13px;vertical-align:middle}th{background:#f8fbff;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.company strong{display:block}.company small{display:block;color:var(--muted)}.source{display:inline-flex;margin-bottom:5px;border-radius:999px;padding:4px 7px;background:#eef6ff;color:#1d4ed8;font-size:10px;font-weight:900}
.badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 9px;font-weight:900;font-size:11px;white-space:nowrap}.badge::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.connected{background:#ecfdf5;color:#15803d}.initializing{background:#eff6ff;color:#1d4ed8}.qr{background:#fff7ed;color:#c2410c}.disconnected,.invalid{background:#fef2f2;color:#b91c1c}.stored{background:#f8fafc;color:#64748b}.legacy{background:#fef9c3;color:#854d0e}.official{background:#f0fdf4;color:#166534}
.actions{display:flex;gap:6px;flex-wrap:wrap}.actions form{margin:0}.muted{color:var(--muted)}.small{font-size:11px}.empty{padding:28px;text-align:center;color:var(--muted)}.note{margin-top:12px;color:var(--muted);font-size:12px;line-height:1.5;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px}
@media(max-width:1250px){.stats{grid-template-columns:repeat(4,1fr)}}@media(max-width:900px){.filters{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.hero-side{justify-content:flex-start}.topbar{align-items:flex-start;flex-direction:column}.user{text-align:left}}@media(max-width:520px){.stats{grid-template-columns:repeat(2,1fr)}.shell{padding:10px}}
</style>
</head>
<body><div class="shell">
<header class="topbar"><div class="brand"><img src="/assets/logo-nexa-v6-2.png" alt="Nexa"><div><strong>Portal do Suporte</strong><div class="nav"><a href="/suporte/index.php">Empresas</a><a href="/suporte/monitor_fiscal.php">Monitor Fiscal</a><a class="active" href="/suporte/whatsapp.php">WhatsApp</a><a href="/suporte/monitor_saude.php">Saúde do Sistema</a></div></div></div><div class="user"><strong><?= e((string)$user['name']) ?></strong><?= e((string)$user['email']) ?> · <a style="color:#fff" href="/suporte/logout.php">Sair</a></div></header>
<section class="hero"><div><h1>Monitor de WhatsApp</h1><p>Motor leve (sem Chrome) na VPS do WhatsApp<?= !empty($health['baileys_version']) ? ' · Baileys ' . e((string)$health['baileys_version']) : '' ?></p></div><div class="hero-side"><span class="refresh-info">Atualizado em <?= date('d/m/Y H:i:s') ?></span><a class="btn btn-light" href="/suporte/whatsapp.php<?= $q !== '' || $statusFilter !== '' ? '?' . e(http_build_query(['q' => $q, 'status' => $statusFilter])) : '' ?>">Atualizar</a><span class="service <?= $engineOnline ? '' : 'off' ?>"><?= $engineOnline ? 'Motor: online' : ($engineReady ? 'Motor: fora do ar' : 'Motor: não instalado') ?></span></div></section>
<?php if (is_array($flash)): ?><div class="flash <?= empty($flash['ok']) ? 'bad' : '' ?>"><?= e((string)$flash['message']) ?></div><?php endif; ?>
<?php if ($engineReady && !$engineOnline): ?><div class="flash bad">O motor não respondeu: <?= e((string)($health['error'] ?? 'sem resposta')) ?></div><?php endif; ?>
<?php if (!$engineReady): ?><div class="flash bad">O motor novo ainda não foi instalado. Na VPS do sistema: bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP_DA_VPS_DO_WHATSAPP</div><?php endif; ?>
<?php if ($counts['legacy'] > 0 && $engineOnline): ?>
<div class="legacy-box"><span><strong><?= (int)$counts['legacy'] ?></strong> configuração(ões) ainda no motor antigo (Chrome). Ao passar, cada uma precisa ler o QR Code uma vez.</span>
<form method="post" onsubmit="return confirm('Passar TODAS as configurações para o motor novo? Cada empresa, a Central SaaS e as revendas vão precisar ler o QR Code novamente.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="migrate_all"><button class="btn btn-primary">Passar todas para o motor novo</button></form></div>
<?php endif; ?>
<div class="stats">
  <div class="stat"><small>Conectadas</small><strong><?= (int)$counts['connected'] ?></strong></div>
  <div class="stat"><small>Aguardando QR</small><strong><?= (int)$counts['qr'] ?></strong></div>
  <div class="stat"><small>Conectando</small><strong><?= (int)$counts['initializing'] ?></strong></div>
  <div class="stat"><small>Desconectadas</small><strong><?= (int)$counts['disconnected'] + (int)$counts['stored'] + (int)$counts['invalid'] ?></strong></div>
  <div class="stat <?= $counts['legacy'] ? 'warn' : '' ?>"><small>No motor antigo</small><strong><?= (int)$counts['legacy'] ?></strong></div>
  <div class="stat"><small>Memória do motor</small><strong><?= $engineOnline ? (int)($health['memory_mb'] ?? 0) . ' MB' : '—' ?></strong></div>
  <div class="stat"><small>Motor no ar há</small><strong><?= $engineOnline ? ($uptimeH >= 24 ? intdiv($uptimeH, 24) . ' d' : $uptimeH . ' h') : '—' ?></strong></div>
</div>
<?php if ($qrRow): $qrStatus = strtolower((string)($qrData['status'] ?? '')); ?>
<div class="qr-card" id="qr">
  <?php if ($qrStatus === 'qr' && !empty($qrData['qr'])): ?><img src="<?= e((string)$qrData['qr']) ?>" alt="QR Code do WhatsApp"><?php endif; ?>
  <div><h3>QR Code — <?= e((string)$qrRow['name']) ?></h3>
  <?php if ($qrStatus === 'connected'): ?><div class="badge connected">Conectado<?= !empty($qrData['phone']) ? ' · ' . e(support_wa_phone_format((string)$qrData['phone'])) : '' ?></div>
  <?php elseif ($qrStatus === 'qr'): ?><div class="muted">No celular: WhatsApp → <b>Dispositivos conectados</b> → <b>Conectar dispositivo</b> e aponte para o QR Code.<br>A tela se atualiza sozinha por 2 minutos.</div>
  <?php else: ?><div class="muted"><?= e((string)($qrData['lastError'] ?? $qrData['error'] ?? 'Gerando QR Code...')) ?></div><?php endif; ?>
  <div style="margin-top:10px"><a class="btn btn-light" href="/suporte/whatsapp.php">Fechar</a></div></div>
  <?php if ($qrStatus !== 'connected'): ?><script>(function(){var k='supWaQr_<?= e(md5($qrKey . '|' . (string)($_GET['t'] ?? ''))) ?>',n=0;try{n=+sessionStorage.getItem(k)||0}catch(e){}if(n<8){try{sessionStorage.setItem(k,n+1)}catch(e){}setTimeout(function(){location.reload()},15000)}})();</script><?php endif; ?>
</div>
<?php endif; ?>
<form class="filters" method="get"><input name="q" value="<?= e($q) ?>" placeholder="Empresa, Central SaaS, Revenda, número ou ID da sessão"><select name="status"><option value="">Todos os status</option><?php foreach (['connected' => 'Conectado', 'qr' => 'Aguardando QR', 'initializing' => 'Conectando', 'disconnected' => 'Desconectado', 'stored' => 'Salva (não aberta)', 'legacy' => 'Motor antigo', 'official' => 'API Oficial'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><button class="btn btn-primary">Filtrar</button><a class="btn btn-light" href="/suporte/whatsapp.php">Limpar</a></form>
<div class="table-wrap"><table><thead><tr><th>Origem / Empresa</th><th>Status</th><th>Número conectado</th><th>Sessão</th><th>Última atualização</th><th>Ações</th></tr></thead><tbody>
<?php if (!$filtered): ?><tr><td colspan="6" class="empty">Nenhuma configuração de WhatsApp encontrada.</td></tr><?php endif; ?>
<?php foreach ($filtered as $row):
  $sid = (string)$row['session_company_id'];
  $info = (array)($sessions[$sid] ?? []);
  $st = support_wa_row_status($row, $sessions, $engineOnline);
  $manageable = !empty($row['on_engine']) && $engineOnline && ($row['provider'] ?? '') === 'unofficial' && support_wa_valid_session_id($sid);
  $hidden = '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="owner_key" value="' . e((string)$row['owner_key']) . '">';
?>
<tr>
<td class="company"><span class="source"><?= e((string)$row['source_label']) ?></span><strong><?= e((string)$row['name']) ?></strong><small><?= e((string)$row['slug']) ?><?= !empty($row['company_id']) ? ' · empresa #' . (int)$row['company_id'] : '' ?><?= empty($row['enabled']) ? ' · envio desligado' : '' ?></small></td>
<td><span class="badge <?= e($st) ?>"><?= e(support_wa_status_label($st)) ?></span><?php if (!empty($info['last_error']) && $st !== 'connected'): ?><div class="small muted" title="<?= e((string)$info['last_error']) ?>"><?= e(mb_strimwidth((string)$info['last_error'], 0, 90, '…')) ?></div><?php endif; ?></td>
<td><?= !empty($info['phone']) ? e(support_wa_phone_format((string)$info['phone'])) : '<span class="muted">—</span>' ?></td>
<td><span class="small muted"><?= $row['provider'] === 'official' ? 'Meta Cloud API' : 'company-' . e($sid) ?></span></td>
<td><?= e(support_wa_format_updated_at((string)($info['updated_at'] ?? ''))) ?></td>
<td><div class="actions">
<?php if ($manageable): ?>
  <?php if ($st !== 'connected'): ?><a class="btn btn-primary" href="/suporte/whatsapp.php?qr=<?= e(rawurlencode((string)$row['owner_key'])) ?>&amp;t=<?= time() ?>#qr">Ver QR</a><?php endif; ?>
  <form method="post"><?= $hidden ?><input type="hidden" name="action" value="restart"><button class="btn btn-light" onclick="return confirm('Reabrir esta sessão mantendo o login?')">Reiniciar</button></form>
  <form method="post"><?= $hidden ?><input type="hidden" name="action" value="stop"><button class="btn btn-warn" onclick="return confirm('Parar esta sessão? O login é mantido e ela volta ao reiniciar ou ao abrir o WhatsApp no painel.')">Parar</button></form>
  <form method="post"><?= $hidden ?><input type="hidden" name="action" value="new_qr"><button class="btn btn-danger" onclick="return confirm('Gerar novo QR? A conexão atual será desfeita e será preciso ler um novo QR Code.')">Novo QR</button></form>
<?php elseif ($st === 'legacy' && $row['source'] === 'company' && $engineOnline): ?>
  <form method="post"><?= $hidden ?><input type="hidden" name="action" value="migrate_one"><button class="btn btn-primary" onclick="return confirm('Passar esta empresa para o motor novo? Será preciso ler o QR Code novamente.')">Passar para o motor novo</button></form>
<?php elseif ($row['provider'] === 'official'): ?><span class="small muted">Gerenciado pela configuração Meta/API.</span>
<?php else: ?><span class="small muted">—</span><?php endif; ?>
</div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="note">O motor roda numa VPS própria e reconecta as sessões sozinho quando a internet oscila; esta tela só consulta (não abre sessões). <strong>Reiniciar</strong> reabre a sessão sem pedir QR Code; <strong>Parar</strong> desliga a sessão mantendo o login; <strong>Novo QR</strong> desfaz a conexão e gera outro QR Code.</div>
</div></body></html>
