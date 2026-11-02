<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';

if (!access_is_company_master() && !access_has_permission($pdo, (int)COMPANY_ID, 'usuarios_perfis')) {
  access_render_denied('Seu usuário não possui permissão para editar perfis de acesso.');
}

$cid = (int)COMPANY_ID;
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = '';

try {
  access_ensure_schema($pdo);
} catch (Throwable $e) {
  $err = $e->getMessage();
}

function aclpf_h($value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($id <= 0) {
  header('Location: usuarios_perfis.php');
  exit;
}

$catalog = access_permissions_catalog();
$modulesState = [];
try {
  foreach (saas_company_module_state($pdo, $cid) as $module) {
    $modulesState[(string)$module['code']] = !empty($module['enabled']);
  }
} catch (Throwable $e) {
}

$moduleEnabled = static function ($module) use ($pdo, $cid, $modulesState): bool {
  $codes = is_array($module) ? $module : [$module];
  foreach ($codes as $code) {
    $code = trim((string)$code);
    if ($code === '' || $code === '*') {
      if (saas_company_has_any_module($pdo, $cid)) return true;
      continue;
    }
    $enabled = array_key_exists($code, $modulesState)
      ? (bool)$modulesState[$code]
      : saas_company_has_module($pdo, $cid, $code);
    if ($enabled) return true;
  }
  return false;
};

$moduleLabel = static function ($module): string {
  $codes = is_array($module) ? $module : [$module];
  $labels = [];
  foreach ($codes as $code) {
    $code = trim((string)$code);
    if ($code === '' || $code === '*') {
      $labels[] = 'Geral/compartilhado';
      continue;
    }
    $labels[] = saas_module_label($code);
  }
  $labels = array_values(array_unique(array_filter($labels)));
  return implode(' ou ', $labels) ?: 'Geral/compartilhado';
};

$loadProfile = static function () use ($pdo, $cid, $id): ?array {
  $query = $pdo->prepare('SELECT * FROM user_profiles WHERE company_id = ? AND id = ? LIMIT 1');
  $query->execute([$cid, $id]);
  $row = $query->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
};

$profile = $loadProfile();
if (!$profile) {
  header('Location: usuarios_perfis.php?ok=' . rawurlencode('Perfil não encontrado.'));
  exit;
}

$oldPermissions = json_decode((string)($profile['permissions_json'] ?? '[]'), true);
$oldPermissions = is_array($oldPermissions) ? array_values(array_unique(array_map('strval', $oldPermissions))) : [];

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $postedPermissions = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['permissions'] ?? [])))));

    if ($name === '') {
      throw new RuntimeException('Informe o nome do perfil.');
    }

    $duplicate = $pdo->prepare('SELECT id FROM user_profiles WHERE company_id = ? AND LOWER(name) = LOWER(?) AND id <> ? LIMIT 1');
    $duplicate->execute([$cid, $name, $id]);
    if ($duplicate->fetchColumn()) {
      throw new RuntimeException('Já existe outro perfil com esse nome.');
    }

    $savedPermissions = [];
    foreach ($postedPermissions as $permissionKey) {
      if (!isset($catalog[$permissionKey])) {
        continue;
      }
      if (!$moduleEnabled($catalog[$permissionKey]['module'] ?? '*')) {
        continue;
      }
      $savedPermissions[] = $permissionKey;
    }

    // Mantém permissões de módulos temporariamente desabilitados para que não sejam
    // perdidas ao salvar outras alterações do perfil.
    foreach ($oldPermissions as $permissionKey) {
      if (!isset($catalog[$permissionKey])) {
        continue;
      }
      if ($moduleEnabled($catalog[$permissionKey]['module'] ?? '*')) {
        continue;
      }
      $savedPermissions[] = $permissionKey;
    }

    // Compatibilidade: mantém bloqueio legado ao desmarcar a opção.
    // A autorização também exige a permissão explícita no perfil.
    if (!in_array('relatorio_analise_dashboard', $postedPermissions, true)) {
      $savedPermissions[] = '__deny_relatorio_analise_dashboard';
    }
    $savedPermissions = array_values(array_unique($savedPermissions));
    $permissionsJson = json_encode($savedPermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($permissionsJson === false) {
      throw new RuntimeException('Não foi possível preparar as permissões do perfil.');
    }

    $update = $pdo->prepare(
      'UPDATE user_profiles
          SET name = ?,
              description = ?,
              permissions_json = ?,
              is_active = ?,
              updated_at = NOW()
        WHERE company_id = ?
          AND id = ?'
    );
    $update->execute([
      $name,
      $description !== '' ? $description : null,
      $permissionsJson,
      $active,
      $cid,
      $id,
    ]);

    header('Location: usuarios_perfis.php?ok=' . rawurlencode('Perfil atualizado.'));
    exit;
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
  $profile = array_merge($profile, [
    'name' => (string)($_POST['name'] ?? $profile['name'] ?? ''),
    'description' => (string)($_POST['description'] ?? $profile['description'] ?? ''),
    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
  ]);
  $oldPermissions = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['permissions'] ?? [])))));
  if (!in_array('relatorio_analise_dashboard', $oldPermissions, true)) $oldPermissions[] = '__deny_relatorio_analise_dashboard';
}

$groups = [];
foreach ($catalog as $key => $permission) {
  $groups[(string)($permission['group'] ?? 'Outros')][$key] = $permission;
}

admin_topbar('Editar perfil', 'usuarios_perfis');
?>
<style>
.aclpf-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
.aclpf-head h1{margin:0;color:var(--admin-primary)}
.aclpf-muted{color:#64748b;line-height:1.45}
.aclpf-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08);margin-bottom:14px}
.aclpf-card h2{margin:0 0 10px;color:var(--admin-primary)}
.aclpf-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.aclpf-form-grid .span-2{grid-column:1/-1}
.aclpf-form-grid label{font-weight:850;color:#334155}
.aclpf-form-grid input[type=text],.aclpf-form-grid textarea{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;background:#fff}
.aclpf-check-status{display:flex;gap:9px;align-items:flex-start;border:1px solid #e2e8f0;background:#f8fbff;border-radius:14px;padding:10px}
.aclpf-check-status input{margin-top:3px}
.aclpf-perm-group{border:1px solid #e2e8f0;border-radius:18px;padding:12px;margin-bottom:12px;background:#f8fbff}
.aclpf-perm-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.aclpf-perm-head h3{margin:0;color:var(--admin-primary);font-size:16px}
.aclpf-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.aclpf-check{display:flex;gap:8px;align-items:flex-start;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:9px}
.aclpf-check.disabled{opacity:.58;background:#f8fafc}
.aclpf-check input{margin-top:3px}
.aclpf-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.aclpf-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}
.aclpf-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}
.aclpf-btn.small{padding:7px 10px;font-size:12px}
.msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}
.msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
@media(max-width:900px){.aclpf-form-grid,.aclpf-checks{grid-template-columns:1fr}.aclpf-form-grid .span-2{grid-column:1}}
</style>

<?php if ($err): ?>
  <div class="msg err"><?= aclpf_h($err) ?></div>
<?php endif; ?>

<div class="aclpf-head">
  <div>
    <h1>Editar perfil</h1>
    <p class="aclpf-muted" style="margin:5px 0 0">Atualize os dados e defina separadamente os menus, submenus e funções permitidas.</p>
  </div>
  <a class="aclpf-btn" href="usuarios_perfis.php">Voltar para lista</a>
</div>

<form method="post" id="profileEditForm">
  <input type="hidden" name="id" value="<?= $id ?>">

  <div class="aclpf-card">
    <h2>Dados do perfil</h2>
    <div class="aclpf-form-grid">
      <label>
        Nome do perfil
        <input type="text" name="name" value="<?= aclpf_h($profile['name'] ?? '') ?>" required maxlength="120" placeholder="Ex.: Caixa, Gerente, Fiscal">
      </label>
      <label class="aclpf-check-status">
        <input type="checkbox" name="is_active" value="1" <?= !empty($profile['is_active']) ? 'checked' : '' ?>>
        <span><b>Perfil ativo</b><br><span class="aclpf-muted">Permite vincular e utilizar este perfil nos usuários.</span></span>
      </label>
      <label class="span-2">
        Descrição
        <textarea name="description" rows="3" maxlength="500" placeholder="Resumo do que este perfil pode fazer"><?= aclpf_h($profile['description'] ?? '') ?></textarea>
      </label>
    </div>
  </div>

  <div class="aclpf-card">
    <h2>Permissões do perfil</h2>
    <p class="aclpf-muted">Todos os menus e submenus do sistema podem ser habilitados individualmente. Opções ligadas a módulos não contratados ficam bloqueadas e são preservadas no perfil para uma futura reativação do módulo.</p>

    <?php foreach ($groups as $groupName => $permissions): ?>
      <section class="aclpf-perm-group" data-permission-group>
        <div class="aclpf-perm-head">
          <h3><?= aclpf_h($groupName) ?></h3>
          <div>
            <button class="aclpf-btn small" type="button" data-check-group>Marcar disponíveis</button>
            <button class="aclpf-btn small" type="button" data-uncheck-group>Desmarcar</button>
          </div>
        </div>
        <div class="aclpf-checks">
          <?php foreach ($permissions as $key => $permission): ?>
            <?php
              $module = $permission['module'] ?? '*';
              $enabled = $moduleEnabled($module);
              $checked = in_array((string)$key, $oldPermissions, true);
              if ($key === 'relatorio_analise_dashboard'
                  && in_array('__deny_relatorio_analise_dashboard', $oldPermissions, true)) $checked = false;
            ?>
            <label class="aclpf-check <?= $enabled ? '' : 'disabled' ?>">
              <input
                type="checkbox"
                name="permissions[]"
                value="<?= aclpf_h($key) ?>"
                <?= $checked ? 'checked' : '' ?>
                <?= $enabled ? '' : 'disabled' ?>
              >
              <span>
                <strong><?= aclpf_h($permission['label'] ?? $key) ?></strong><br>
                <small class="aclpf-muted">
                  <?php if (!empty($permission['description'])): ?>
                    <?= aclpf_h($permission['description']) ?>
                  <?php else: ?>
                    Módulo:
                    <?= aclpf_h($moduleLabel($module)) ?>
                    <?= $enabled ? '' : ' — indisponível no plano' ?>
                  <?php endif; ?>
                </small>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <div class="aclpf-actions">
    <a class="aclpf-btn" href="usuarios_perfis.php">Cancelar</a>
    <button class="aclpf-btn primary" type="submit">Salvar perfil</button>
  </div>
</form>

<script>
(function () {
  document.querySelectorAll('[data-permission-group]').forEach(function (group) {
    const enabledChecks = function () {
      return group.querySelectorAll('input[type="checkbox"][name="permissions[]"]:not(:disabled)');
    };

    const checkButton = group.querySelector('[data-check-group]');
    const uncheckButton = group.querySelector('[data-uncheck-group]');

    if (checkButton) {
      checkButton.addEventListener('click', function () {
        enabledChecks().forEach(function (checkbox) {
          checkbox.checked = true;
        });
      });
    }

    if (uncheckButton) {
      uncheckButton.addEventListener('click', function () {
        enabledChecks().forEach(function (checkbox) {
          checkbox.checked = false;
        });
      });
    }
  });
})();
</script>
<?php admin_footer(); ?>
