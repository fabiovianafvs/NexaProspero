<?php
declare(strict_types=1);

// Backup Geral: mysqldump do banco inteiro (todas as empresas). Só o administrador
// da plataforma (Master) acessa — o _bootstrap.php exige platform_admin nesta página.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/database_backup.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['db_backup_csrf'])) $_SESSION['db_backup_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['db_backup_csrf'];
$msg = (string)($_SESSION['db_backup_flash_ok'] ?? '');
$err = (string)($_SESSION['db_backup_flash_err'] ?? '');
unset($_SESSION['db_backup_flash_ok'], $_SESSION['db_backup_flash_err']);

try {
  if (isset($_GET['download'])) {
    $file = nexa_db_backup_resolve_file((string)$_GET['download']);
    while (ob_get_level() > 0) ob_end_clean(); // arquivo grande: envia direto, sem guardar na memória
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
      $meta = nexa_db_backup_create($pdo, (int)(auth_user_id() ?? 0));
      $_SESSION['db_backup_flash_ok'] = 'Backup geral concluído: ' . (string)$meta['file'] . '. Faça o download e guarde em local seguro.';
    } elseif ($action === 'delete') {
      nexa_db_backup_delete((string)($_POST['file'] ?? ''));
      $_SESSION['db_backup_flash_ok'] = 'Backup excluído.';
    } else {
      throw new RuntimeException('Ação inválida.');
    }
    header('Location: backup_banco.php');
    exit;
  }
} catch (Throwable $e) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') { $_SESSION['db_backup_flash_err'] = $e->getMessage(); header('Location: backup_banco.php'); exit; }
  $err = $e->getMessage();
}
$backups = [];
try { $backups = nexa_db_backup_list(); } catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }
$dbName = '';
try { $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''); } catch (Throwable $ignored) {}
$bkpTitle = 'Backup Geral';
$bkpIntro = 'Cópia completa do banco <b>' . nexa_db_backup_h($dbName ?: '—') . '</b>, com <b>todas as empresas</b> (tabelas, dados, triggers, rotinas e eventos), compactada em .sql.gz e guardada fora da pasta pública. Só o administrador da plataforma vê esta tela.';
$bkpCreateLabel = 'Fazer backup geral';
$bkpConfirm = 'Gerar agora o backup completo do banco ' . $dbName . '? Pode levar alguns minutos.';
$bkpPage = 'backup_banco.php';
$bkpTip = 'Antes de aplicar atualizações do banco: faça o backup, baixe o arquivo e confira que o download terminou.';
admin_topbar('Backup Geral', 'backup_banco');
require __DIR__ . '/_backup_lista.php';
admin_footer();
