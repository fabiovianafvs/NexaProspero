<?php
declare(strict_types=1);

// Central SaaS > WhatsApp > Motor novo: passa as empresas, uma a uma, do
// serviço antigo (um Chrome por empresa) para o motor leve (Baileys) na VPS
// própria do WhatsApp. Só platform_admin (garantido pelo _bootstrap).

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

if (empty($_SESSION['saas_wa_motor_csrf'])) $_SESSION['saas_wa_motor_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['saas_wa_motor_csrf'];
$engine = whatsapp_engine_config($pdo);
$msg = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $companyId = (int)($_POST['company_id'] ?? 0);
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
    $st = $pdo->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $companyName = (string)($st->fetchColumn() ?: '');
    if ($companyName === '') throw new RuntimeException('Empresa não encontrada.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'migrate') {
      whatsapp_engine_migrate_company($pdo, $companyId, (int)(auth_user_id() ?? 0));
      header('Location: /saas/whatsapp_motor.php?qr=' . $companyId . '&t=' . time() . '&ok=' . rawurlencode($companyName . ' passou para o motor novo. Leia o QR Code abaixo (ou peça para a empresa abrir Central > WhatsApp).'));
      exit;
    }
    if ($action === 'revert') {
      whatsapp_engine_revert_company($pdo, $companyId, (int)(auth_user_id() ?? 0));
      header('Location: /saas/whatsapp_motor.php?ok=' . rawurlencode($companyName . ' voltou para o serviço antigo. A empresa precisa ler o QR Code novamente em Central > WhatsApp.'));
      exit;
    }
    throw new RuntimeException('Ação inválida.');
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}
if ($msg === '' && isset($_GET['ok'])) $msg = (string)$_GET['ok'];

// Empresas com WhatsApp configurado.
$rows = [];
$st = $pdo->query("SELECT c.id, c.name, c.slug, ac.cfg_json FROM companies c JOIN app_config ac ON ac.company_id = c.id AND ac.cfg_key = 'whatsapp' ORDER BY c.name ASC");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $cfg = json_decode((string)$r['cfg_json'], true);
  if (!is_array($cfg)) continue;
  $sid = preg_replace('/\D+/', '', (string)($cfg['session_company_id'] ?? '')) ?: '';
  if ($sid === '' || $sid === '0') $sid = (string)$r['id'];
  $rows[] = [
    'id' => (int)$r['id'],
    'name' => (string)$r['name'],
    'slug' => (string)$r['slug'],
    'enabled' => !empty($cfg['enabled']),
    'sid' => $sid,
    'on_engine' => whatsapp_engine_is_url($engine, (string)($cfg['service_url'] ?? '')),
  ];
}

// Situação nos dois serviços (só leitura: não abre sessão nenhuma).
$health = whatsapp_engine_health($engine);
$engineSessions = [];
if (!empty($health['ok'])) {
  try {
    foreach ((array)(whatsapp_engine_request($engine, 'GET', '/admin/sessions', [], 5)['items'] ?? []) as $it) {
      if (is_array($it)) $engineSessions[(string)($it['company_id'] ?? '')] = $it;
    }
  } catch (Throwable $e) {}
}
$legacySessions = [];
$legacyOnline = false;
try {
  $legacy = whatsapp_api_request(['service_url' => WHATSAPP_LEGACY_SERVICE_URL, 'api_token' => '', 'timeout_seconds' => 4], 'GET', '/admin/sessions');
  $legacyOnline = true;
  foreach ((array)($legacy['items'] ?? []) as $it) {
    if (is_array($it)) $legacySessions[(string)($it['company_id'] ?? '')] = $it;
  }
} catch (Throwable $e) {}

// Painel do QR Code de uma empresa (logo após migrar).
$qrCompany = null;
$qrData = null;
$qrId = (int)($_GET['qr'] ?? 0);
if ($qrId > 0) {
  foreach ($rows as $r) if ($r['id'] === $qrId && $r['on_engine']) $qrCompany = $r;
  if ($qrCompany) {
    try {
      $qrData = whatsapp_engine_request($engine, 'GET', '/qr?company_id=' . rawurlencode($qrCompany['sid']), [], 10);
    } catch (Throwable $e) {
      $qrData = ['ok' => false, 'error' => $e->getMessage()];
    }
  }
}

function wam_badge(array $session, bool $known): string {
  if (!$known) return '<span class="wam-pill off">sem informação</span>';
  $status = strtolower((string)($session['status'] ?? ''));
  $map = [
    'connected' => ['ok', 'Conectado'],
    'qr' => ['warn', 'Aguardando QR Code'],
    'initializing' => ['warn', 'Conectando'],
    'stored' => ['warn', 'Salva (não aberta)'],
  ];
  [$cls, $label] = $map[$status] ?? ['err', 'Desconectado'];
  $title = trim((string)($session['last_error'] ?? ''));
  return '<span class="wam-pill ' . $cls . '"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</span>';
}

$total = count($rows);
$migrated = count(array_filter($rows, static fn($r) => $r['on_engine']));
saas_layout_start('Motor WhatsApp • Central SaaS', 'Motor de WhatsApp leve', 'Passe as empresas para o motor novo (sem Chrome), uma de cada vez.');
?>
<style>
.wam-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.wam-kpi{background:#fff;border:1px solid #d9e5fb;border-radius:16px;padding:10px 14px}
.wam-kpi small{display:block;color:#64748b;font-size:12px}.wam-kpi b{font-size:20px;color:#0b1f5b}
.wam-card{background:#fff;border:1px solid #d9e5fb;border-radius:18px;padding:16px;margin-bottom:16px;color:#0b1f5b}
.wam-pill{display:inline-flex;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;white-space:nowrap}
.wam-pill.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.wam-pill.warn{background:#fff7ed;color:#c2410c;border:1px solid #fdba74}
.wam-pill.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.wam-pill.off{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
.wam-pill.new{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.wam-table{width:100%;border-collapse:collapse}.wam-table th,.wam-table td{padding:9px 10px;border-bottom:1px solid #e5eefb;text-align:left;vertical-align:middle}
.wam-table th{font-size:12px;color:#64748b;text-transform:uppercase}
.wam-qr{display:flex;gap:18px;align-items:center;flex-wrap:wrap}.wam-qr img{width:260px;height:260px;border:1px solid #d9e5fb;border-radius:12px}
.wam-muted{color:#64748b;font-size:13px}
@media(max-width:900px){.wam-grid{grid-template-columns:1fr 1fr}}
</style>

<div class="top-actions">
  <a class="btn2 btn2-ghost" href="/saas/whatsapp.php">WhatsApp</a>
  <a class="btn2 btn2-ghost" href="/saas/whatsapp_motor.php">Atualizar</a>
</div>

<?php if ($msg !== ''): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="wam-grid">
  <div class="wam-kpi"><small>Motor novo</small><b><?= !empty($health['ok']) ? 'Online' : (whatsapp_engine_ready($engine) ? 'Fora do ar' : 'Não instalado') ?></b></div>
  <div class="wam-kpi"><small>Empresas no motor novo</small><b><?= $migrated ?> de <?= $total ?></b></div>
  <div class="wam-kpi"><small>Conectadas no motor novo</small><b><?= (int)($health['sessions_connected'] ?? 0) ?></b></div>
  <div class="wam-kpi"><small>Memória do motor novo</small><b><?= !empty($health['ok']) ? (int)($health['memory_mb'] ?? 0) . ' MB' : '—' ?></b></div>
</div>

<?php if (!whatsapp_engine_ready($engine)): ?>
  <div class="wam-card"><b>O motor novo ainda não foi instalado.</b><div class="wam-muted">Na VPS do sistema, rode: <code>bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP_DA_VPS_DO_WHATSAPP</code></div></div>
<?php elseif (empty($health['ok'])): ?>
  <div class="alert-err">O motor novo não respondeu: <?= e((string)($health['error'] ?? '')) ?></div>
<?php endif; ?>

<?php if ($qrCompany): ?>
  <div class="wam-card" id="wamQr">
    <h3 style="margin:0 0 10px">QR Code — <?= e($qrCompany['name']) ?></h3>
    <?php $qrStatus = strtolower((string)($qrData['status'] ?? '')); ?>
    <?php if ($qrStatus === 'connected'): ?>
      <div class="alert-ok" style="margin:0">Conectado<?= !empty($qrData['phone']) ? ' (' . e((string)$qrData['phone']) . ')' : '' ?>. Pronto!</div>
    <?php elseif ($qrStatus === 'qr' && !empty($qrData['qr'])): ?>
      <div class="wam-qr">
        <img src="<?= e((string)$qrData['qr']) ?>" alt="QR Code do WhatsApp">
        <div class="wam-muted">No celular da empresa: WhatsApp → <b>Dispositivos conectados</b> → <b>Conectar dispositivo</b> e aponte para o QR Code.<br>A tela se atualiza sozinha por 2 minutos.</div>
      </div>
    <?php else: ?>
      <div class="wam-muted"><?= e((string)($qrData['lastError'] ?? $qrData['error'] ?? 'Gerando QR Code...')) ?></div>
    <?php endif; ?>
    <?php if ($qrStatus !== 'connected'): ?>
      <script>
      (function(){var k='wamQrTries<?= (int)$qrCompany['id'] ?>_<?= (int)($_GET['t'] ?? 0) ?>',n=0;try{n=+sessionStorage.getItem(k)||0}catch(e){}
      if(n<8){try{sessionStorage.setItem(k,n+1)}catch(e){}setTimeout(function(){location.reload()},15000)}})();
      </script>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="wam-card">
  <div class="wam-muted" style="margin-bottom:10px">Ao passar uma empresa para o motor novo, o WhatsApp dela é desconectado do serviço antigo e precisa ler o QR Code uma vez. Faça junto com a empresa (ou leia o QR Code aqui mesmo). Dá para voltar ao serviço antigo a qualquer momento (também pede QR Code).</div>
  <div class="table-wrap"><table class="wam-table">
    <thead><tr><th>Empresa</th><th>Motor</th><th>Situação</th><th style="text-align:right">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $session = $r['on_engine'] ? ($engineSessions[$r['sid']] ?? []) : ($legacySessions[$r['sid']] ?? []);
      $known = $r['on_engine'] ? !empty($health['ok']) : $legacyOnline;
      if ($known && !$session) $session = ['status' => 'disconnected'];
    ?>
      <tr>
        <td><b><?= e($r['name']) ?></b><div class="wam-muted">#<?= $r['id'] ?> · sessão <?= e($r['sid']) ?><?= $r['enabled'] ? '' : ' · envio automático desligado' ?></div></td>
        <td><?= $r['on_engine'] ? '<span class="wam-pill new">Novo (leve)</span>' : '<span class="wam-pill off">Antigo (Chrome)</span>' ?></td>
        <td><?= wam_badge($session, $known) ?></td>
        <td style="text-align:right">
          <form method="post" style="display:inline" onsubmit="return confirm(<?= e(json_encode($r['on_engine'] ? 'Voltar ' . $r['name'] . ' para o serviço antigo? Será preciso ler o QR Code novamente.' : 'Passar ' . $r['name'] . ' para o motor novo? O WhatsApp será desconectado e será preciso ler o QR Code novamente.', JSON_UNESCAPED_UNICODE)) ?>)">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="company_id" value="<?= $r['id'] ?>">
            <?php if ($r['on_engine']): ?>
              <a class="btn2 btn2-ghost" href="/saas/whatsapp_motor.php?qr=<?= $r['id'] ?>&t=<?= time() ?>#wamQr">QR Code</a>
              <button class="btn2 btn2-ghost" name="action" value="revert">Voltar ao antigo</button>
            <?php else: ?>
              <button class="btn2" name="action" value="migrate" <?= empty($health['ok']) ? 'disabled' : '' ?>>Passar para o motor novo</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="wam-muted">Nenhuma empresa com WhatsApp configurado.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php saas_layout_end(); ?>
