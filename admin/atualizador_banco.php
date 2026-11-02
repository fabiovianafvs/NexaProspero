<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/schema_updater.php';

$flash = $_SESSION['nexa_schema_updater_flash'] ?? null;
unset($_SESSION['nexa_schema_updater_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    nexa_schema_updater_csrf_check((string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'apply') throw new RuntimeException('Ação inválida.');

    $confirmation = strtoupper(trim((string)($_POST['confirmation'] ?? '')));
    if ($confirmation !== 'ATUALIZAR') {
      throw new RuntimeException('Digite ATUALIZAR no campo de confirmação antes de executar a migration.');
    }

    $migrationId = trim((string)($_POST['migration_id'] ?? ''));
    $result = nexa_schema_updater_apply($pdo, $migrationId, (int)(auth_user_id() ?? 0));
    $_SESSION['nexa_schema_updater_flash'] = [
      'type' => 'ok',
      'message' => (string)($result['message'] ?? 'Atualização aplicada.') . ' Comandos executados: ' . (int)($result['statements'] ?? 0) . '.',
    ];
  } catch (Throwable $e) {
    $_SESSION['nexa_schema_updater_flash'] = ['type' => 'err', 'message' => $e->getMessage()];
  }
  header('Location: atualizador_banco.php');
  exit;
}

$status = nexa_schema_updater_status($pdo);
$nextPending = nexa_schema_updater_next_pending($pdo);
$history = nexa_schema_updater_history($pdo, 30);
$dbName = '';
$dbVersion = '';
try { $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''); } catch (Throwable $e) {}
try { $dbVersion = (string)($pdo->query('SELECT VERSION()')->fetchColumn() ?: ''); } catch (Throwable $e) {}

$pendingCount = 0;
$blockedCount = 0;
foreach ($status as $row) {
  if (!empty($row['pending'])) {
    $pendingCount++;
    if (empty($row['file_ok'])) $blockedCount++;
  }
}

admin_topbar('Atualizador do Banco', 'atualizador_banco');
?>
<style>
.dbup-page{display:grid;gap:18px}
.dbup-hero{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-radius:22px;padding:22px;box-shadow:0 18px 48px rgba(11,31,91,.16)}
.dbup-hero h1{margin:0 0 8px;font-size:25px}.dbup-hero p{margin:0;color:#dbeafe;line-height:1.55;max-width:900px}
.dbup-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}.dbup-stat{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:16px;padding:14px}.dbup-stat small{display:block;color:#bfdbfe;font-weight:800;margin-bottom:5px}.dbup-stat strong{font-size:19px;word-break:break-word}
.dbup-card{background:#fff;border:1px solid #dfe7f3;border-radius:20px;padding:18px;box-shadow:0 12px 34px rgba(15,23,42,.05)}
.dbup-card h2{margin:0 0 7px;color:#0b1f5b;font-size:19px}.dbup-muted{color:#64748b;font-size:13px;line-height:1.5}
.dbup-alert{border-radius:16px;padding:13px 15px;font-weight:800;line-height:1.45}.dbup-alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.dbup-alert.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.dbup-alert.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.dbup-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:16px}.dbup-table{width:100%;border-collapse:collapse;min-width:860px}.dbup-table th,.dbup-table td{padding:12px 13px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top;font-size:13px}.dbup-table th{background:#f8fafc;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.03em}.dbup-table tr:last-child td{border-bottom:0}
.dbup-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900}.dbup-badge.ok{background:#dcfce7;color:#166534}.dbup-badge.pending{background:#fef3c7;color:#92400e}.dbup-badge.err{background:#fee2e2;color:#991b1b}.dbup-badge.run{background:#dbeafe;color:#1d4ed8}
.dbup-apply{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:end;margin-top:14px;padding:16px;border-radius:16px;background:#f8fbff;border:1px solid #dbeafe}.dbup-field label{display:block;font-weight:900;color:#172554;margin-bottom:7px}.dbup-field input{width:100%;max-width:320px;padding:12px 13px;border:1px solid #cbd5e1;border-radius:12px;font-weight:800}.dbup-btn{border:0;border-radius:12px;padding:12px 16px;background:#2454d6;color:#fff;font-weight:900;cursor:pointer}.dbup-btn:disabled{opacity:.45;cursor:not-allowed}
.dbup-code{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:11px 13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;overflow:auto;margin-top:8px}
@media(max-width:800px){.dbup-grid{grid-template-columns:1fr}.dbup-apply{grid-template-columns:1fr}.dbup-btn{width:100%}}
</style>
<div class="dbup-page">
  <section class="dbup-hero">
    <h1>Atualizador controlado do banco</h1>
    <p>O Nexa apenas verifica versões automaticamente. Alterações de estrutura são executadas somente aqui, por ação explícita do administrador da plataforma, uma migration por vez, com checksum, trava global e histórico.</p>
    <div class="dbup-grid">
      <div class="dbup-stat"><small>Banco em uso</small><strong><?= htmlspecialchars($dbName !== '' ? $dbName : 'não identificado', ENT_QUOTES, 'UTF-8') ?></strong></div>
      <div class="dbup-stat"><small>MySQL / MariaDB</small><strong><?= htmlspecialchars($dbVersion !== '' ? $dbVersion : 'não identificado', ENT_QUOTES, 'UTF-8') ?></strong></div>
      <div class="dbup-stat"><small>Status</small><strong><?= $pendingCount > 0 ? $pendingCount . ' atualização(ões) pendente(s)' : 'Banco atualizado' ?></strong></div>
    </div>
  </section>

  <?php if (is_array($flash)): ?>
    <div class="dbup-alert <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($blockedCount > 0): ?>
    <div class="dbup-alert err">Existe migration pendente com arquivo ausente ou checksum divergente. O Atualizador bloqueou a execução. Reponha o pacote oficial antes de tentar novamente.</div>
  <?php elseif ($pendingCount === 0): ?>
    <div class="dbup-alert ok">Banco em dia. Nenhuma migration registrada neste pacote está pendente.</div>
  <?php else: ?>
    <div class="dbup-alert warn">Há atualização pendente. Faça backup antes de aplicar. O Nexa nunca executa esta operação automaticamente no login.</div>
  <?php endif; ?>

  <section class="dbup-card">
    <h2>Migrations registradas</h2>
    <div class="dbup-muted">Somente arquivos explicitamente homologados no código podem ser executados por esta tela. SQL avulso nunca é aceito.</div>
    <div class="dbup-table-wrap" style="margin-top:13px">
      <table class="dbup-table">
        <thead><tr><th>Migration</th><th>Módulo</th><th>Versão exigida</th><th>Versão atual</th><th>Arquivo</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($status as $row): ?>
          <tr>
            <td><strong><?= htmlspecialchars((string)$row['label'], ENT_QUOTES, 'UTF-8') ?></strong><div class="dbup-muted"><?= htmlspecialchars((string)$row['description'], ENT_QUOTES, 'UTF-8') ?></div></td>
            <td><?= htmlspecialchars((string)$row['module'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$row['version'] ?></td>
            <td><?= (int)$row['current_version'] ?></td>
            <td><code><?= htmlspecialchars(basename((string)$row['file']), ENT_QUOTES, 'UTF-8') ?></code><div class="dbup-muted"><?= htmlspecialchars((string)$row['file_message'], ENT_QUOTES, 'UTF-8') ?></div></td>
            <td>
              <?php if (!empty($row['applied'])): ?><span class="dbup-badge ok">Aplicada</span>
              <?php elseif (empty($row['file_ok'])): ?><span class="dbup-badge err">Bloqueada</span>
              <?php else: ?><span class="dbup-badge pending">Pendente</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($nextPending): ?>
      <form method="post" class="dbup-apply" onsubmit="return confirm('Aplicar a próxima migration no banco <?= htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') ?>? Confirme somente após realizar o backup.');">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(nexa_schema_updater_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="migration_id" value="<?= htmlspecialchars((string)$nextPending['id'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="dbup-field">
          <label>Próxima atualização: <?= htmlspecialchars((string)$nextPending['label'], ENT_QUOTES, 'UTF-8') ?></label>
          <div class="dbup-muted">Para confirmar conscientemente a alteração de estrutura, digite <strong>ATUALIZAR</strong>.</div>
          <input name="confirmation" autocomplete="off" placeholder="Digite ATUALIZAR" required <?= empty($nextPending['file_ok']) ? 'disabled' : '' ?>>
        </div>
        <button class="dbup-btn" type="submit" <?= empty($nextPending['file_ok']) ? 'disabled' : '' ?>>Aplicar próxima migration</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="dbup-card">
    <h2>Histórico de execução</h2>
    <div class="dbup-muted">O histórico começa a ser gravado a partir da V11.8. Em caso de falha, a migration permanece pendente e pode ser repetida somente depois da correção.</div>
    <?php if (!$history): ?>
      <div class="dbup-muted" style="margin-top:13px">Ainda não há execuções registradas.</div>
    <?php else: ?>
      <div class="dbup-table-wrap" style="margin-top:13px">
        <table class="dbup-table">
          <thead><tr><th>Data</th><th>Migration</th><th>Status</th><th>Usuário</th><th>Banco</th><th>Detalhes</th></tr></thead>
          <tbody>
          <?php foreach ($history as $row): $st = strtoupper((string)($row['status'] ?? '')); ?>
            <tr>
              <td><?= htmlspecialchars((string)($row['started_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?><div class="dbup-muted"><?= htmlspecialchars((string)($row['finished_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></td>
              <td><strong><?= htmlspecialchars((string)($row['migration_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><div class="dbup-muted"><?= htmlspecialchars((string)($row['module_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= (int)($row['version_number'] ?? 0) ?></div></td>
              <td><span class="dbup-badge <?= $st === 'SUCCESS' ? 'ok' : ($st === 'FAILED' ? 'err' : 'run') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td>#<?= (int)($row['executed_by_user_id'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string)($row['database_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="dbup-muted"><?= htmlspecialchars((string)($row['error_message'] ?? 'Concluída sem erro.'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="dbup-card">
    <h2>Plano de contingência</h2>
    <div class="dbup-muted">Se uma migration futura for marcada como não compatível com execução web, o Nexa irá bloqueá-la. Nesse caso, o procedimento administrativo via VPS continuará disponível.</div>
    <div class="dbup-code">mysql -u USUARIO -p <?= htmlspecialchars($dbName !== '' ? $dbName : 'BANCO', ENT_QUOTES, 'UTF-8') ?> &lt; /var/www/nexa/db/ARQUIVO_DA_MIGRATION.sql</div>
  </section>
</div>
<?php admin_footer(); ?>
