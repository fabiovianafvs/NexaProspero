<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/multi_company.php';

$isCrossProfiles = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
$isMaster = function_exists('access_is_company_master') && access_is_company_master();
$cid = $isCrossProfiles && function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'user_profiles_read')
  : (int)COMPANY_ID;

if ($isCrossProfiles) {
  $allowedProfiles = $isMaster
    || (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'usuarios_perfis'));
  if (!$allowedProfiles) access_render_denied('Seu perfil na empresa selecionada não possui permissão para consultar perfis de acesso.');
} elseif (!$isMaster) {
  access_render_denied('Somente o administrador principal da empresa pode cadastrar perfis de acesso.');
}

$err = '';
try { access_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }

function acl_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function acl_url(string $url): string { return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url; }

$group = function_exists('multi_company_group_by_company') ? multi_company_group_by_company($pdo, $cid) : null;
$groupId = (int)($group['id'] ?? 0);
$groupMode = strtolower(trim((string)($group['operation_mode'] ?? 'isolated')));
$companyName = '';
try { $st=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1'); $st->execute([$cid]); $companyName=trim((string)($st->fetchColumn() ?: '')); } catch (Throwable $e) {}

// V11.36: somente o modo ISOLADO possui perfil próprio da empresa. Nesse modo,
// o administrador principal pode editar diretamente o perfil da empresa selecionada.
// Em modo INTEGRADO o perfil pertence ao Grupo Empresarial e permanece centralizado.
$canEditProfiles = !$isCrossProfiles || ($isCrossProfiles && $groupMode === 'isolated' && $isMaster);
if ($canEditProfiles && $isCrossProfiles && function_exists('multi_company_operational_company_id')) {
  $cid = (int)multi_company_operational_company_id($pdo, 'user_profiles_write');
}

if ($isCrossProfiles && !$canEditProfiles) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    access_render_denied($groupMode === 'integrated'
      ? 'V11.36 Multiempresa: o perfil desta empresa é integrado ao Grupo Empresarial. Edite o perfil no cadastro central do grupo.'
      : 'V11.36 Multiempresa: somente o administrador principal pode alterar perfis da empresa selecionada.');
  }

  $contextProfiles = [];
  $profileScopeLabel = $groupMode === 'integrated' ? 'Perfil integrado do Grupo Empresarial' : 'Perfil específico da empresa selecionada';
  try {
    if ($groupMode === 'integrated' && $groupId > 0) {
      $stCtx = $pdo->prepare("SELECT p.*, COUNT(DISTINCT bgu.user_id) AS users_count
        FROM business_group_profiles p
        LEFT JOIN business_group_users bgu ON bgu.business_group_id=p.business_group_id AND bgu.group_profile_id=p.id AND bgu.is_active=1
        WHERE p.business_group_id=?
        GROUP BY p.id,p.business_group_id,p.name,p.description,p.permissions_json,p.is_active,p.created_at,p.updated_at
        ORDER BY p.is_active DESC,p.name ASC,p.id ASC");
      $stCtx->execute([$groupId]);
    } else {
      $stCtx = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(DISTINCT u.id)
           FROM users u
           LEFT JOIN user_company_access uca ON uca.user_id=u.id AND uca.company_id=p.company_id AND uca.is_active=1
          WHERE u.is_active=1 AND (
            (u.company_id=p.company_id AND u.profile_id=p.id)
            OR (uca.company_profile_id=p.id)
          )) AS users_count
        FROM user_profiles p
        WHERE p.company_id=?
        ORDER BY p.is_active DESC,p.name ASC,p.id ASC");
      $stCtx->execute([$cid]);
    }
    $contextProfiles = $stCtx->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $err = $e->getMessage(); }

  admin_topbar('Perfis de acesso', 'usuarios_perfis');
  ?>
  <style>
  .aclr-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08)}.aclr-card h1{margin:0;color:var(--admin-primary)}.aclr-muted{color:#64748b;line-height:1.45}.aclr-msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.aclr-err{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.aclr-table-wrap{overflow:auto}.aclr-table{width:100%;border-collapse:separate;border-spacing:0}.aclr-table th,.aclr-table td{padding:11px 10px;border-bottom:1px solid #e5edf6;text-align:left;vertical-align:top}.aclr-table th{background:#f8fbff;color:#334155;font-size:12px;text-transform:uppercase}.aclr-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:12px;font-weight:900}.aclr-badge.off{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
  </style>
  <?php if ($err): ?><div class="aclr-msg aclr-err"><?= acl_h($err) ?></div><?php endif; ?>
  <div class="aclr-msg"><?= acl_h($profileScopeLabel) ?><?= $companyName !== '' ? ' · ' . acl_h($companyName) : '' ?>. <?= $groupMode === 'integrated' ? 'Como este grupo opera em modo integrado, alterações neste perfil afetariam outras empresas e permanecem centralizadas.' : 'Seu usuário possui acesso de consulta, mas não autorização administrativa para alterar os perfis.' ?></div>
  <section class="aclr-card">
    <h1>Perfis de acesso</h1>
    <p class="aclr-muted">Perfis efetivamente disponíveis para a empresa selecionada pelo F11.</p>
    <div class="aclr-table-wrap"><table class="aclr-table"><thead><tr><th>Perfil</th><th>Escopo</th><th>Status</th><th>Usuários</th><th>Permissões</th></tr></thead><tbody>
    <?php if (!$contextProfiles): ?><tr><td colspan="5" class="aclr-muted">Nenhum perfil disponível para esta empresa.</td></tr><?php endif; ?>
    <?php foreach ($contextProfiles as $p): $perms=json_decode((string)($p['permissions_json']??'[]'), true); $count=is_array($perms)?count($perms):0; ?>
      <tr><td><strong><?= acl_h($p['name'] ?? '') ?></strong><br><small class="aclr-muted"><?= acl_h($p['description'] ?? '') ?></small></td><td><?= acl_h($profileScopeLabel) ?></td><td><span class="aclr-badge <?= (int)($p['is_active']??0)===1?'':'off' ?>"><?= (int)($p['is_active']??0)===1?'Ativo':'Inativo' ?></span></td><td><?= (int)($p['users_count'] ?? 0) ?></td><td><?= $count ?> permissão(ões)</td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php admin_footer(); exit;
}

$catalog = access_permissions_catalog();
$peopleDenyToken = '__deny_pessoas';
$modulesState = [];
try { foreach (saas_company_module_state($pdo, $cid) as $m) $modulesState[(string)$m['code']] = !empty($m['enabled']); } catch (Throwable $e) {}
$moduleEnabled = static function($module) use ($pdo, $cid, $modulesState): bool {
  $codes = is_array($module) ? $module : [$module];
  foreach ($codes as $code) {
    $code = trim((string)$code);
    if ($code === '' || $code === '*') {
      if (saas_company_has_any_module($pdo, $cid)) return true;
      continue;
    }
    $enabled = array_key_exists($code, $modulesState) ? (bool)$modulesState[$code] : saas_company_has_module($pdo, $cid, $code);
    if ($enabled) return true;
  }
  return false;
};
$csrf = $isCrossProfiles && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isCrossProfiles && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Token de segurança do contexto F11 inválido. Recarregue a página antes de salvar.');
    }
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Perfil não informado.');
      $stRef = $pdo->prepare("SELECT COUNT(DISTINCT x.user_id) FROM (
        SELECT u.id AS user_id FROM users u WHERE u.company_id=? AND u.profile_id=?
        UNION ALL
        SELECT uca.user_id FROM user_company_access uca WHERE uca.company_id=? AND uca.company_profile_id=?
      ) x");
      $stRef->execute([$cid, $id, $cid, $id]);
      $refs = (int)$stRef->fetchColumn();
      if ($refs > 0) {
        $pdo->prepare('UPDATE user_profiles SET is_active = 0, updated_at = NOW() WHERE company_id = ? AND id = ?')->execute([$cid, $id]);
      } else {
        $pdo->prepare('DELETE FROM user_profiles WHERE company_id = ? AND id = ?')->execute([$cid, $id]);
      }
      header('Location: ' . acl_url('usuarios_perfis.php?ok=' . rawurlencode($refs > 0 ? 'Perfil em uso: foi inativado com segurança.' : 'Perfil removido.'))); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $selected = [];
    if (!empty($_POST['permissions_present'])) {
      $selected = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['permissions'] ?? [])))));
    } elseif ($id > 0) {
      $stOld = $pdo->prepare('SELECT permissions_json FROM user_profiles WHERE company_id = ? AND id = ? LIMIT 1');
      $stOld->execute([$cid, $id]);
      $old = json_decode((string)($stOld->fetchColumn() ?: '[]'), true);
      $selected = is_array($old) ? array_values(array_map('strval', $old)) : [];
    }
    $allowed = [];
    foreach ($selected as $key) {
      if (!isset($catalog[$key])) continue;
      if (!$moduleEnabled($catalog[$key]['module'] ?? '*')) continue;
      $allowed[] = $key;
    }
    // Pessoas é opt-out: perfis antigos e novos possuem acesso por padrão.
    // Ao desmarcar no modal, gravamos um token interno de negação sem alterar schema.
    if (!in_array('clientes', $selected, true)) $allowed[] = $peopleDenyToken;
    if ($name === '') throw new RuntimeException('Informe o nome do perfil.');
    $json = json_encode(array_values(array_unique($allowed)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($id > 0) {
      $st = $pdo->prepare('UPDATE user_profiles SET name = ?, description = ?, permissions_json = ?, is_active = ?, updated_at = NOW() WHERE company_id = ? AND id = ?');
      $st->execute([$name, $description !== '' ? $description : null, $json, $active, $cid, $id]);
      if ($st->rowCount() === 0) {
        $chk=$pdo->prepare('SELECT 1 FROM user_profiles WHERE company_id=? AND id=? LIMIT 1'); $chk->execute([$cid,$id]);
        if (!$chk->fetchColumn()) throw new RuntimeException('O perfil informado não pertence à empresa selecionada.');
      }
      header('Location: ' . acl_url('usuarios_perfis.php?ok=' . rawurlencode('Perfil atualizado.'))); exit;
    }
    $st = $pdo->prepare('INSERT INTO user_profiles (company_id, name, description, permissions_json, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $st->execute([$cid, $name, $description !== '' ? $description : null, $json, $active]);
    $newId=(int)$pdo->lastInsertId();
    header('Location: ' . acl_url('usuarios_perfis.php?ok=' . rawurlencode('Perfil cadastrado.'))); exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? (function() use ($pdo, $cid, $editId) { $st=$pdo->prepare('SELECT * FROM user_profiles WHERE company_id = ? AND id = ? LIMIT 1'); $st->execute([$cid,$editId]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; })() : null;
if ($editId > 0 && !$edit && $err === '') $err = 'O perfil informado não pertence à empresa selecionada.';
$selectedPerms = [];
if ($edit) {
  $tmp = json_decode((string)($edit['permissions_json'] ?? '[]'), true);
  $selectedPerms = is_array($tmp) ? array_values(array_map('strval', $tmp)) : [];
}
$formProfile = $edit ?: ['id'=>0,'name'=>'','description'=>'','is_active'=>1];
if ($err !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? 'save') !== 'delete') {
  $formProfile = [
    'id' => (int)($_POST['id'] ?? 0),
    'name' => trim((string)($_POST['name'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
  ];
  if (!empty($_POST['permissions_present'])) {
    $selectedPerms = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['permissions'] ?? [])))));
    if (!in_array('clientes', $selectedPerms, true)) $selectedPerms[] = $peopleDenyToken;
  }
}
$profiles = [];
try {
  $st = $pdo->prepare("SELECT p.*,
    (SELECT COUNT(DISTINCT u.id)
       FROM users u
       LEFT JOIN user_company_access uca ON uca.user_id=u.id AND uca.company_id=p.company_id AND uca.is_active=1
      WHERE u.is_active=1 AND (
        (u.company_id=p.company_id AND u.profile_id=p.id)
        OR (uca.company_profile_id=p.id)
      )) AS users_count
    FROM user_profiles p WHERE p.company_id = ? ORDER BY p.is_active DESC, p.name ASC");
  $st->execute([$cid]);
  $profiles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$groups = [];
foreach ($catalog as $key => $perm) $groups[(string)($perm['group'] ?? 'Outros')][$key] = $perm;

admin_topbar('Perfis de acesso', 'usuarios_perfis');
$openModal = isset($_GET['new']) || $editId > 0 || ($err !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
?>
<style>
.acl-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08)}
.acl-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 16px}.acl-page-head h1{margin:0;color:var(--admin-primary);font-size:clamp(24px,3vw,32px)}
.acl-muted{color:#64748b;line-height:1.45}.acl-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;align-items:center}.acl-actions form{margin:0}
.acl-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer;min-height:40px}
.acl-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.acl-btn.danger{border-color:#fecaca;color:#b91c1c;background:#fef2f2}
.acl-badge{display:inline-flex;padding:3px 8px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:12px;font-weight:900}.acl-badge.off{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.acl-table-wrap{overflow:auto}.acl-table{width:100%;border-collapse:separate;border-spacing:0}.acl-table th,.acl-table td{padding:11px 10px;border-bottom:1px solid #e5edf6;text-align:left;vertical-align:middle}.acl-table th{background:#f8fbff;color:#334155;font-size:12px;text-transform:uppercase}.acl-table td:last-child,.acl-table th:last-child{text-align:right}
.msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}.msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.msg.info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.acl-modal{position:fixed;inset:0;z-index:300;display:flex;align-items:flex-start;justify-content:center;padding:22px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);overflow:auto}.acl-modal[hidden]{display:none!important}.acl-modal-dialog{width:min(1050px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 28px 90px rgba(2,6,23,.35);padding:18px;margin:auto}.acl-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}.acl-modal-head h2{margin:0;color:var(--admin-primary)}.acl-modal-close{display:inline-flex;width:42px;height:42px;align-items:center;justify-content:center;border-radius:14px;border:1px solid #dbe4f0;background:#fff;color:#0f172a;text-decoration:none;font-size:22px;font-weight:900}
.acl-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.acl-form-grid .wide{grid-column:1/-1}.acl-form-grid label{font-weight:850;color:#334155}.acl-form-grid input[type=text],.acl-form-grid textarea{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;box-sizing:border-box}.acl-active-line{display:flex;gap:8px;align-items:center;padding:10px 12px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fbff}
.acl-permissions{margin-top:16px}.acl-permissions-title{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.acl-permissions-title h3{margin:0;color:var(--admin-primary)}.acl-perm-group{border:1px solid #e2e8f0;border-radius:18px;padding:12px;margin-bottom:12px;background:#f8fbff}.acl-perm-group h4{margin:0 0 10px;color:var(--admin-primary);font-size:15px}.acl-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.acl-check{display:flex;gap:8px;align-items:flex-start;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:9px}.acl-check.disabled{opacity:.52;background:#f8fafc}.acl-check.people-default{border-color:#bfdbfe;background:#eff6ff}.acl-check small{display:block;margin-top:2px}.acl-modal-actions{position:sticky;bottom:-18px;background:linear-gradient(180deg,rgba(255,255,255,.72),#fff 28%);border-top:1px solid #eef2f7;padding:14px 0 0;margin-top:12px;display:flex;justify-content:flex-end;gap:8px}
@media(max-width:760px){.acl-form-grid,.acl-checks{grid-template-columns:1fr}.acl-modal{padding:0}.acl-modal-dialog{min-height:100vh;border-radius:0;border:0}.acl-table th:nth-child(3),.acl-table td:nth-child(3){display:none}.acl-actions{justify-content:flex-start}.acl-table td:last-child{text-align:left}}
</style>
<?php if ($err): ?><div class="msg err"><?= acl_h($err) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="msg ok"><?= acl_h($_GET['ok']) ?></div><?php endif; ?>
<?php if ($isCrossProfiles): ?><div class="msg info"><strong>Empresa selecionada:</strong> <?= acl_h($companyName !== '' ? $companyName : ('Empresa #' . $cid)) ?>. Estes perfis pertencem somente a esta empresa no modo isolado.</div><?php endif; ?>

<div class="acl-page-head">
  <div><h1>Perfis de acesso</h1><p class="acl-muted" style="margin:6px 0 0">Cadastre perfis e defina as funções disponíveis para cada operador.</p></div>
  <a class="acl-btn primary" href="<?= acl_h(acl_url('usuarios_perfis.php?new=1')) ?>">+ Cadastrar perfil</a>
</div>

<section class="acl-card">
  <div class="acl-table-wrap"><table class="acl-table"><thead><tr><th>Perfil</th><th>Status</th><th>Usuários</th><th>Permissões</th><th>Ações</th></tr></thead><tbody>
  <?php if (!$profiles): ?><tr><td colspan="5" class="acl-muted">Nenhum perfil cadastrado.</td></tr><?php endif; ?>
  <?php foreach ($profiles as $p):
    $perms=json_decode((string)($p['permissions_json']??'[]'), true);
    $perms=is_array($perms)?array_values(array_filter(array_map('strval',$perms), static fn($v)=>!str_starts_with($v,'__deny_'))):[];
    $count=count($perms);
  ?>
    <tr>
      <td><strong><?= acl_h($p['name']) ?></strong><br><small class="acl-muted"><?= acl_h($p['description'] ?? '') ?></small></td>
      <td><span class="acl-badge <?= (int)$p['is_active']===1?'':'off' ?>"><?= (int)$p['is_active']===1?'Ativo':'Inativo' ?></span></td>
      <td><?= (int)($p['users_count'] ?? 0) ?></td>
      <td><?= $count ?> permissão(ões)</td>
      <td><div class="acl-actions"><a class="acl-btn" href="<?= acl_h(acl_url('usuarios_perfis.php?edit=' . (int)$p['id'])) ?>">Editar</a><form method="post" onsubmit="return confirm('Remover/inativar este perfil?')"><?php if ($isCrossProfiles): ?><input type="hidden" name="mc_csrf" value="<?= acl_h($csrf) ?>"><?php endif; ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="acl-btn danger">Remover</button></form></div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<div class="acl-modal" id="profileModal" <?= $openModal ? '' : 'hidden' ?> role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
  <div class="acl-modal-dialog">
    <div class="acl-modal-head">
      <div><h2 id="profileModalTitle"><?= (int)($formProfile['id'] ?? 0) > 0 ? 'Editar perfil' : 'Cadastrar perfil' ?></h2><p class="acl-muted" style="margin:5px 0 0">Defina o perfil e suas permissões. Pessoas vem habilitado por padrão e pode ser desmarcado aqui.</p></div>
      <a class="acl-modal-close" href="<?= acl_h(acl_url('usuarios_perfis.php')) ?>" aria-label="Fechar">×</a>
    </div>
    <form method="post" id="profileForm">
      <?php if ($isCrossProfiles): ?><input type="hidden" name="mc_csrf" value="<?= acl_h($csrf) ?>"><?php endif; ?>
      <input type="hidden" name="id" value="<?= (int)($formProfile['id'] ?? 0) ?>">
      <input type="hidden" name="permissions_present" value="1">
      <div class="acl-form-grid">
        <label>Nome do perfil<input type="text" name="name" value="<?= acl_h($formProfile['name'] ?? '') ?>" required placeholder="Ex.: Caixa, Gerente, Fiscal"></label>
        <label class="acl-active-line"><input type="checkbox" name="is_active" value="1" <?= ((int)($formProfile['is_active'] ?? 1) === 1) ? 'checked' : '' ?>> Perfil ativo</label>
        <label class="wide">Descrição<textarea name="description" rows="2" placeholder="Resumo do que este perfil pode fazer"><?= acl_h($formProfile['description'] ?? '') ?></textarea></label>
      </div>

      <div class="acl-permissions">
        <div class="acl-permissions-title"><h3>Permissões</h3><span class="acl-muted">Marque apenas o que este perfil poderá utilizar.</span></div>
        <?php foreach ($groups as $groupLabel => $perms): ?>
          <div class="acl-perm-group">
            <h4><?= acl_h($groupLabel) ?></h4>
            <div class="acl-checks">
              <?php foreach ($perms as $key => $perm):
                $isPeople = (string)$key === 'clientes';
                $enabled = $isPeople ? true : $moduleEnabled($perm['module'] ?? '*');
                $checked = $isPeople ? !in_array($peopleDenyToken, $selectedPerms, true) : in_array($key, $selectedPerms, true);
                $label = $isPeople ? 'Pessoas' : (string)($perm['label'] ?? $key);
              ?>
                <label class="acl-check <?= $enabled ? '' : 'disabled' ?> <?= $isPeople ? 'people-default' : '' ?>">
                  <input type="checkbox" name="permissions[]" value="<?= acl_h($key) ?>" <?= $checked ? 'checked' : '' ?> <?= $enabled ? '' : 'disabled' ?>>
                  <span><strong><?= acl_h($label) ?></strong><small class="acl-muted"><?= $isPeople ? 'Padrão do sistema · disponível em todos os módulos; desmarque para ocultar.' : ('Módulo: ' . acl_h(($perm['module'] ?? '*') === '*' ? 'Geral/compartilhado' : saas_module_label((string)$perm['module'])) . ($enabled ? '' : ' — indisponível no plano')) ?></small></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="acl-modal-actions"><a class="acl-btn" href="<?= acl_h(acl_url('usuarios_perfis.php')) ?>">Cancelar</a><button class="acl-btn primary">Salvar perfil</button></div>
    </form>
  </div>
</div>
<script>
(function(){
  const modal=document.getElementById('profileModal');
  if(!modal || modal.hasAttribute('hidden')) return;
  modal.addEventListener('click',function(e){ if(e.target===modal) window.location.href=<?= json_encode(acl_url('usuarios_perfis.php'), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>; });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') window.location.href=<?= json_encode(acl_url('usuarios_perfis.php'), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>; });
})();
</script>
<?php admin_footer(); ?>
