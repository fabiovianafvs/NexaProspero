<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/support_portal.php';

support_ensure_schema($pdo);
auth_start();
if (empty($_SESSION['saas_support_csrf'])) $_SESSION['saas_support_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['saas_support_csrf'];
$msg = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      $email = strtolower(trim((string)($_POST['email'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      if ($name === '') throw new RuntimeException('Informe o nome do usuário.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
      if (strlen($password) < 8) throw new RuntimeException('A senha deve ter pelo menos 8 caracteres.');
      $st = $pdo->prepare('SELECT id FROM saas_support_users WHERE email = ? LIMIT 1');
      $st->execute([$email]);
      if ($st->fetchColumn()) throw new RuntimeException('Já existe um usuário de suporte com este e-mail.');
      $pdo->prepare('INSERT INTO saas_support_users (name,email,password_hash,is_active,created_by_user_id) VALUES (?,?,?,?,?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 1, auth_user_id()]);
      $msg = 'Usuário de suporte criado com sucesso.';
    }

    if ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Usuário inválido.');
      $pdo->prepare('UPDATE saas_support_users SET is_active = IF(is_active=1,0,1) WHERE id = ?')->execute([$id]);
      $msg = 'Situação do usuário atualizada.';
    }

    if ($action === 'reset_password') {
      $id = (int)($_POST['id'] ?? 0);
      $password = (string)($_POST['new_password'] ?? '');
      if ($id <= 0) throw new RuntimeException('Usuário inválido.');
      if (strlen($password) < 8) throw new RuntimeException('A nova senha deve ter pelo menos 8 caracteres.');
      $pdo->prepare('UPDATE saas_support_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
      $msg = 'Senha redefinida com sucesso.';
    }

    $_SESSION['saas_support_csrf'] = bin2hex(random_bytes(24));
    $csrf = (string)$_SESSION['saas_support_csrf'];
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$rows = $pdo->query('SELECT id,name,email,is_active,last_login_at,created_at FROM saas_support_users ORDER BY name,email')->fetchAll(PDO::FETCH_ASSOC);

saas_layout_start('Equipe de Suporte • Central SaaS', 'Equipe de Suporte', 'Cadastre quem poderá acessar somente o portal separado de consulta de empresas.');
?>
<style>
.support-grid{display:grid;grid-template-columns:minmax(300px,.75fr) minmax(0,1.6fr);gap:16px}.support-note{background:#effaff;border:1px solid #bae6fd;color:#075985;border-radius:16px;padding:14px 16px;margin-bottom:16px;font-weight:700;line-height:1.5}.support-form{display:grid;gap:12px}.support-form label{font-weight:850}.support-form .field{margin-top:6px}.support-table{width:100%;border-collapse:collapse}.support-table th,.support-table td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:top}.support-table th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.status-pill{display:inline-flex;padding:6px 9px;border-radius:999px;font-weight:900;font-size:12px;background:#ecfdf5;color:#166534}.status-pill.off{background:#fef2f2;color:#991b1b}.support-actions{display:flex;gap:8px;flex-wrap:wrap}.support-actions form{margin:0}.reset-form{display:flex;gap:7px;margin-top:8px}.reset-form input{min-width:150px}@media(max-width:1000px){.support-grid{grid-template-columns:1fr}}@media(max-width:700px){.support-table,.support-table tbody,.support-table tr,.support-table td{display:block;width:100%}.support-table thead{display:none}.support-table tr{padding:12px 0;border-bottom:1px solid var(--line)}.support-table td{border:0;padding:5px 0}.support-table td:before{content:attr(data-label);display:block;font-size:11px;color:var(--muted);font-weight:900;text-transform:uppercase}.reset-form{flex-direction:column}}
</style>
<div class="top-actions"><a class="btn2 btn2-primary" href="/suporte/login.php" target="_blank" rel="noopener">Abrir portal do suporte</a></div>
<?php if ($msg !== ''): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<div class="support-note">Os usuários cadastrados aqui <strong>não recebem acesso à Central SaaS</strong>. O login separado é <strong>https://app.nexaprospero.com.br/suporte/login.php</strong> e permite somente pesquisar dados cadastrais das empresas.</div>
<div class="support-grid">
  <section class="panel">
    <h3>Novo usuário</h3>
    <form method="post" class="support-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create">
      <label>Nome<input class="field" name="name" maxlength="120" required></label>
      <label>E-mail<input class="field" name="email" type="email" maxlength="190" required></label>
      <label>Senha inicial<input class="field" name="password" type="password" minlength="8" required autocomplete="new-password"></label>
      <button class="btn2 btn2-primary" type="submit">Cadastrar usuário</button>
    </form>
  </section>
  <section class="panel">
    <h3>Usuários cadastrados</h3>
    <?php if (!$rows): ?><p class="muted">Nenhum usuário de suporte cadastrado.</p><?php else: ?>
    <div style="overflow:auto"><table class="support-table"><thead><tr><th>Usuário</th><th>Situação</th><th>Último acesso</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td data-label="Usuário"><strong><?= e((string)$row['name']) ?></strong><div class="muted"><?= e((string)$row['email']) ?></div></td>
        <td data-label="Situação"><span class="status-pill <?= (int)$row['is_active'] === 1 ? '' : 'off' ?>"><?= (int)$row['is_active'] === 1 ? 'Ativo' : 'Desativado' ?></span></td>
        <td data-label="Último acesso"><?= e(saas_datetime_br($row['last_login_at'] ?? null)) ?></td>
        <td data-label="Ações"><div class="support-actions"><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn2 <?= (int)$row['is_active'] === 1 ? 'btn2-danger' : 'btn2-dark' ?>" type="submit"><?= (int)$row['is_active'] === 1 ? 'Desativar' : 'Ativar' ?></button></form></div>
          <form method="post" class="reset-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input class="field" name="new_password" type="password" minlength="8" placeholder="Nova senha" autocomplete="new-password" required><button class="btn2 btn2-ghost" type="submit">Redefinir</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
<?php saas_layout_end(); ?>
