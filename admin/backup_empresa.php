<?php
declare(strict_types=1);

// Backup da Empresa: cópia somente dos dados da empresa de login (company_id), sem senhas,
// tokens, certificados nem configurações de integração. Liberado pelo módulo do SaaS
// "backup_empresa" e pela permissão "Backup da Empresa" no perfil do usuário.
// Sempre da empresa de login (nunca do contexto F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/database_backup.php';
require_once __DIR__ . '/../config/company_backup.php';

$cid = (int)COMPANY_ID;
$allowed = (function_exists('access_is_company_master') && access_is_company_master())
  || (function_exists('access_has_permission') && access_has_permission($pdo, $cid, 'backup_empresa'));
if (!$allowed) {
  if (function_exists('access_render_denied')) access_render_denied('Seu usuário não possui permissão para fazer o backup da empresa.');
  http_response_code(403); exit('Acesso restrito.');
}

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['company_backup_csrf'])) $_SESSION['company_backup_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['company_backup_csrf'];
$msg = (string)($_SESSION['company_backup_flash_ok'] ?? '');
$err = (string)($_SESSION['company_backup_flash_err'] ?? '');
unset($_SESSION['company_backup_flash_ok'], $_SESSION['company_backup_flash_err']);

$companyName = '';
try {
  $st = $pdo->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
  $st->execute([$cid]);
  $companyName = (string)($st->fetchColumn() ?: '');
} catch (Throwable $ignored) {}

try {
  if (isset($_GET['download'])) {
    $file = company_backup_resolve($cid, (string)$_GET['download']);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . (string)filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf'] ?? '');
    if ($posted === '' || !hash_equals($csrf, $posted)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
      $meta = company_backup_create($pdo, $cid, (int)(auth_user_id() ?? 0), $companyName);
      $_SESSION['company_backup_flash_ok'] = 'Backup concluído: ' . (string)$meta['file'] . ' (' . number_format((int)($meta['rows'] ?? 0), 0, ',', '.') . ' registros). Faça o download e guarde em local seguro.';
    } elseif ($action === 'delete') {
      company_backup_delete($cid, (string)($_POST['file'] ?? ''));
      $_SESSION['company_backup_flash_ok'] = 'Backup excluído.';
    } else {
      throw new RuntimeException('Ação inválida.');
    }
    header('Location: backup_empresa.php');
    exit;
  }
} catch (Throwable $e) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') { $_SESSION['company_backup_flash_err'] = $e->getMessage(); header('Location: backup_empresa.php'); exit; }
  $err = $e->getMessage();
}

$backups = [];
try { $backups = company_backup_list($cid); } catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }
$hn = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$bkpTitle = 'Backup da Empresa';
$bkpIntro = 'Cópia dos dados da empresa <b>' . $hn($companyName ?: ('#' . $cid)) . '</b> (cadastros, vendas, financeiro, estoque, fiscal etc.), compactada em .sql.gz. '
  . 'Por segurança, <b>não inclui</b> senhas, tokens, certificados digitais nem as configurações de integrações (bancos, WhatsApp, e-mail). Ficam guardados os ' . COMPANY_BACKUP_KEEP . ' backups mais recentes.';
$bkpCreateLabel = 'Fazer backup';
$bkpConfirm = 'Gerar agora o backup dos dados da empresa ' . ($companyName ?: '') . '? Pode levar alguns minutos.';
$bkpPage = 'backup_empresa.php';
$bkpTip = 'Faça o download e guarde o arquivo em local seguro (pen drive, nuvem). Os mais antigos são apagados automaticamente quando passa de ' . COMPANY_BACKUP_KEEP . '.';
admin_topbar('Backup da Empresa', 'backup_empresa');
require __DIR__ . '/_backup_lista.php';
admin_footer();
