<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/multi_company.php';

// V2 deliberadamente administrativa: somente administradores principais podem
// conceder acesso cruzado entre empresas. Nenhuma regra operacional é ativada aqui.
if (!access_is_company_master()) {
  access_render_denied('Somente o administrador principal pode configurar acessos multiempresa nesta etapa.');
}

$cid = (int)COMPANY_ID;
$group = multi_company_group_by_company($pdo, $cid);
$err = '';
$ok = trim((string)($_GET['ok'] ?? ''));
$mcauCsrf = function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';

function mcau_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mcau_url(string $url): string { return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url; }
function mcau_go_ok(string $message): void {
  header('Location: ' . mcau_url('multiempresa_acessos.php?ok=' . rawurlencode($message)));
  exit;
}
function mcau_int_list($value): array {
  $out = [];
  foreach ((array)$value as $v) {
    $i = (int)$v;
    if ($i > 0) $out[$i] = $i;
  }
  return array_values($out);
}

if (!$group) {
  admin_topbar('Acessos multiempresa', 'usuarios');
  ?>
  <style>.mcau-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:22px;box-shadow:0 15px 40px rgba(11,31,91,.08)}.mcau-btn{display:inline-flex;padding:10px 14px;border-radius:999px;text-decoration:none;font-weight:900;background:#0b1f5b;color:#fff}</style>
  <div class="mcau-card"><h1 style="margin-top:0;color:#0b1f5b">Acessos multiempresa</h1><p>Esta empresa ainda não pertence a um Grupo Empresarial ativo.</p><p>O vínculo deve ser criado primeiro na Central SaaS.</p><a class="mcau-btn" href="<?= mcau_h(mcau_url('usuarios.php')) ?>">Voltar para usuários</a></div>
  <?php admin_footer(); exit;
}

$groupId = (int)$group['id'];
$mode = (string)($group['operation_mode'] ?? 'integrated');
$companies = multi_company_group_companies($pdo, $groupId, true);
$companyIds = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $companies)));
$companyMap = [];
foreach ($companies as $c) $companyMap[(int)$c['id']] = $c;

if (!$companyIds) {
  $err = 'O Grupo Empresarial não possui empresas ativas vinculadas.';
}

// Perfis legados por empresa. São somente fonte/configuração nesta V2.
$legacyProfilesByCompany = [];
$legacyProfileMap = [];
if ($companyIds) {
  try {
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $st = $pdo->prepare("SELECT id, company_id, name, description, permissions_json, is_active FROM user_profiles WHERE company_id IN ({$ph}) AND is_active = 1 ORDER BY company_id, name");
    $st->execute($companyIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
      $pcid = (int)$p['company_id'];
      $pid = (int)$p['id'];
      $legacyProfilesByCompany[$pcid][] = $p;
      $legacyProfileMap[$pcid . ':' . $pid] = $p;
    }
  } catch (Throwable $e) {
    $err = trim($err . ' Não foi possível carregar os perfis atuais: ' . $e->getMessage());
  }
}

$groupProfiles = [];
$groupProfileMap = [];
try {
  $st = $pdo->prepare('SELECT id, name, description, permissions_json, is_active, created_at, updated_at FROM business_group_profiles WHERE business_group_id = ? AND is_active = 1 ORDER BY name');
  $st->execute([$groupId]);
  $groupProfiles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($groupProfiles as $p) $groupProfileMap[(int)$p['id']] = $p;
} catch (Throwable $e) {
  $err = trim($err . ' ' . $e->getMessage());
}

// Usuários cuja empresa de origem pertence ao grupo. O company_id legado não é alterado.
$users = [];
$userMap = [];
if ($companyIds) {
  try {
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $st = $pdo->prepare("SELECT id, company_id, name, email, role, profile_id, is_active FROM users WHERE company_id IN ({$ph}) AND role IN ('company_admin','company_user') ORDER BY is_active DESC, name, id");
    $st->execute($companyIds);
    $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($users as $u) $userMap[(int)$u['id']] = $u;
  } catch (Throwable $e) {
    $err = trim($err . ' ' . $e->getMessage());
  }
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mcauCsrf !== '' && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Token de segurança inválido. Recarregue a página antes de salvar os acessos.');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'import_group_profile') {
      if ($mode !== 'integrated') throw new RuntimeException('Perfis integrados são utilizados apenas em grupos do tipo Integrado.');
      $sourceKey = trim((string)($_POST['source_profile'] ?? ''));
      $src = $legacyProfileMap[$sourceKey] ?? null;
      if (!$src) throw new RuntimeException('Selecione um perfil válido de uma das empresas do grupo.');
      if (!isset($companyMap[(int)$src['company_id']])) throw new RuntimeException('O perfil selecionado não pertence ao Grupo Empresarial.');

      $name = trim((string)($src['name'] ?? ''));
      if ($name === '') throw new RuntimeException('O perfil selecionado não possui nome válido.');
      $description = trim((string)($src['description'] ?? ''));
      $permissionsJson = (string)($src['permissions_json'] ?? '[]');
      $decoded = json_decode($permissionsJson, true);
      if (!is_array($decoded)) $permissionsJson = '[]';

      $pdo->beginTransaction();
      $find = $pdo->prepare('SELECT id FROM business_group_profiles WHERE business_group_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
      $find->execute([$groupId, $name]);
      $existingId = (int)($find->fetchColumn() ?: 0);
      if ($existingId > 0) {
        $up = $pdo->prepare('UPDATE business_group_profiles SET description = ?, permissions_json = ?, is_active = 1, updated_at = NOW() WHERE business_group_id = ? AND id = ?');
        $up->execute([$description !== '' ? $description : null, $permissionsJson, $groupId, $existingId]);
      } else {
        $ins = $pdo->prepare('INSERT INTO business_group_profiles (business_group_id, name, description, permissions_json, is_active, created_at) VALUES (?,?,?,?,1,NOW())');
        $ins->execute([$groupId, $name, $description !== '' ? $description : null, $permissionsJson]);
      }
      $pdo->commit();
      mcau_go_ok($existingId > 0 ? 'Perfil integrado atualizado a partir do perfil da empresa.' : 'Perfil integrado criado a partir do perfil da empresa.');
    }

    if ($action === 'save_user_access') {
      $userId = (int)($_POST['user_id'] ?? 0);
      $u = $userMap[$userId] ?? null;
      if (!$u) throw new RuntimeException('Selecione um usuário válido do Grupo Empresarial.');

      $selected = mcau_int_list($_POST['company_ids'] ?? []);
      $selected = array_values(array_intersect($selected, $companyIds));
      $legacyCompanyId = (int)$u['company_id'];
      // A empresa de origem permanece sempre acessível nesta fase de compatibilidade.
      if (isset($companyMap[$legacyCompanyId]) && !in_array($legacyCompanyId, $selected, true)) $selected[] = $legacyCompanyId;
      sort($selected);
      if (!$selected) throw new RuntimeException('Selecione ao menos uma empresa para o usuário.');

      $defaultCompanyId = (int)($_POST['default_company_id'] ?? 0);
      if (!in_array($defaultCompanyId, $selected, true)) {
        $defaultCompanyId = in_array($legacyCompanyId, $selected, true) ? $legacyCompanyId : (int)$selected[0];
      }

      $groupProfileId = null;
      $companyProfiles = [];
      if ($mode === 'integrated') {
        $groupProfileId = (int)($_POST['group_profile_id'] ?? 0);
        if ($groupProfileId <= 0 || !isset($groupProfileMap[$groupProfileId])) {
          throw new RuntimeException('Selecione o perfil integrado do usuário.');
        }
      } else {
        foreach ($selected as $scid) {
          $pid = (int)($_POST['company_profile_id'][$scid] ?? 0);
          // Compatibilidade: para a empresa de origem, usa o perfil atual do usuário quando não informado.
          if ($pid <= 0 && $scid === $legacyCompanyId) $pid = (int)($u['profile_id'] ?? 0);
          if ($pid <= 0 || !isset($legacyProfileMap[$scid . ':' . $pid])) {
            throw new RuntimeException('Selecione um perfil válido para ' . (string)($companyMap[$scid]['name'] ?? ('empresa ' . $scid)) . '.');
          }
          $companyProfiles[$scid] = $pid;
        }
      }

      $pdo->beginTransaction();

      $bgu = $pdo->prepare("INSERT INTO business_group_users (business_group_id,user_id,group_profile_id,is_active,created_at,updated_at) VALUES (?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE group_profile_id=VALUES(group_profile_id),is_active=1,updated_at=NOW()");
      $bgu->execute([$groupId, $userId, $mode === 'integrated' ? $groupProfileId : null]);

      // Inativa somente vínculos pertencentes a este grupo. Não toca em eventuais acessos de outros grupos.
      $ph = implode(',', array_fill(0, count($companyIds), '?'));
      $params = array_merge([$userId], $companyIds);
      $pdo->prepare("UPDATE user_company_access SET is_active = 0, is_default = 0, updated_at = NOW() WHERE user_id = ? AND company_id IN ({$ph})")->execute($params);

      $uca = $pdo->prepare("INSERT INTO user_company_access (user_id,company_id,company_profile_id,is_default,is_active,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE company_profile_id=VALUES(company_profile_id),is_default=VALUES(is_default),is_active=1,updated_at=NOW()");
      foreach ($selected as $scid) {
        $profileForCompany = $mode === 'isolated' ? (int)$companyProfiles[$scid] : null;
        $uca->execute([$userId, $scid, $profileForCompany, $scid === $defaultCompanyId ? 1 : 0]);
      }

      $pdo->commit();
      mcau_go_ok('Acessos multiempresa do usuário salvos. O perfil efetivo já será utilizado ao trocar de empresa pelo F11.');
    }

    throw new RuntimeException('Ação inválida.');
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $err = $e->getMessage();
}

// Configurações existentes para preencher a tela.
$configByUser = [];
try {
  $st = $pdo->prepare('SELECT user_id, group_profile_id, is_active FROM business_group_users WHERE business_group_id = ?');
  $st->execute([$groupId]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $configByUser[(int)$r['user_id']]['group_profile_id'] = $r['group_profile_id'] !== null ? (int)$r['group_profile_id'] : null;
    $configByUser[(int)$r['user_id']]['group_active'] = (int)$r['is_active'];
  }

  if ($companyIds) {
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $st = $pdo->prepare("SELECT user_id, company_id, company_profile_id, is_default, is_active FROM user_company_access WHERE company_id IN ({$ph}) ORDER BY user_id, is_default DESC, company_id");
    $st->execute($companyIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $uid = (int)$r['user_id'];
      $ccid = (int)$r['company_id'];
      if ((int)$r['is_active'] !== 1) continue;
      $configByUser[$uid]['companies'][$ccid] = [
        'profile_id' => $r['company_profile_id'] !== null ? (int)$r['company_profile_id'] : null,
        'is_default' => (int)$r['is_default'] === 1,
      ];
    }
  }
} catch (Throwable $e) {
  $err = trim($err . ' ' . $e->getMessage());
}

$selectedUserId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($selectedUserId <= 0 && $users) $selectedUserId = (int)$users[0]['id'];
$selectedUser = $userMap[$selectedUserId] ?? null;
$selectedCfg = $selectedUser ? ($configByUser[$selectedUserId] ?? []) : [];

admin_topbar('Acessos multiempresa', 'usuarios');
?>
<style>
.mcau-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}.mcau-head h1{margin:0;color:var(--admin-primary)}.mcau-muted{color:#64748b;line-height:1.5}.mcau-grid{display:grid;grid-template-columns:minmax(260px,.85fr) minmax(0,1.8fr);gap:16px}.mcau-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08)}.mcau-card h2{margin:0 0 12px;color:#0b1f5b;font-size:19px}.mcau-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}.mcau-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.mcau-badge{display:inline-flex;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:900;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.mcau-badge.integrated{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.mcau-badge.isolated{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.mcau-user{display:block;padding:11px 12px;border:1px solid #e2e8f0;border-radius:15px;margin-bottom:8px;text-decoration:none;color:#0f172a}.mcau-user.active{border-color:#93c5fd;background:#eff6ff}.mcau-user strong{display:block}.mcau-user small{display:block;color:#64748b;margin-top:3px}.mcau-field{margin-bottom:14px}.mcau-field label{display:block;font-weight:900;color:#334155;margin-bottom:6px}.mcau-field select,.mcau-field input[type=text]{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:13px;padding:11px 12px;background:#fff}.mcau-company{border:1px solid #e2e8f0;border-radius:16px;padding:12px;margin-bottom:10px}.mcau-company-top{display:flex;align-items:center;gap:9px;font-weight:900}.mcau-company-grid{display:grid;grid-template-columns:minmax(0,1fr) 160px;gap:10px;margin-top:10px}.mcau-msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}.mcau-msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.mcau-msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.mcau-note{border:1px solid #dbeafe;background:#eff6ff;color:#1e3a8a;border-radius:16px;padding:12px 14px;margin-bottom:14px;line-height:1.45}.mcau-source{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.mcau-summary{margin-top:14px;border-top:1px solid #e5edf6;padding-top:12px}.mcau-summary ul{margin:8px 0 0;padding-left:20px}.mcau-actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:900px){.mcau-grid{grid-template-columns:1fr}.mcau-company-grid,.mcau-source{grid-template-columns:1fr}}
</style>

<?php if ($err): ?><div class="mcau-msg err"><?= mcau_h($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="mcau-msg ok"><?= mcau_h($ok) ?></div><?php endif; ?>

<div class="mcau-head">
  <div>
    <h1>Acessos multiempresa</h1>
    <p class="mcau-muted" style="margin:5px 0 0"><strong><?= mcau_h($group['name'] ?? '') ?></strong> · <span class="mcau-badge <?= mcau_h($mode) ?>"><?= $mode === 'integrated' ? 'Integrado' : 'Isolado' ?></span></p>
  </div>
  <div class="mcau-actions"><a class="mcau-btn" href="<?= mcau_h(mcau_url('usuarios.php')) ?>">Voltar para usuários</a></div>
</div>

<div class="mcau-note"><strong>V11.36 · Vínculos ativos:</strong> esta tela administra em segurança quais empresas o usuário pode acessar e qual perfil efetivo utiliza em cada empresa. A identidade-base do usuário e o <code>COMPANY_ID</code> de origem não são alterados.</div>

<?php if ($mode === 'integrated'): ?>
<div class="mcau-card" style="margin-bottom:16px">
  <h2>Perfis integrados do grupo</h2>
  <p class="mcau-muted">Importe um perfil existente de qualquer empresa do grupo. Se já existir um perfil integrado com o mesmo nome, ele será atualizado com as permissões da origem.</p>
  <form method="post" class="mcau-source">
    <input type="hidden" name="mc_csrf" value="<?= mcau_h($mcauCsrf) ?>">
    <input type="hidden" name="action" value="import_group_profile">
    <select name="source_profile" required>
      <option value="">Selecione o perfil que servirá de referência...</option>
      <?php foreach ($companies as $c): $ccid=(int)$c['id']; ?>
        <?php if (!empty($legacyProfilesByCompany[$ccid])): ?>
          <optgroup label="<?= mcau_h($c['name'] ?? '') ?>">
            <?php foreach ($legacyProfilesByCompany[$ccid] as $p): ?><option value="<?= $ccid ?>:<?= (int)$p['id'] ?>"><?= mcau_h($p['name'] ?? '') ?></option><?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
      <?php endforeach; ?>
    </select>
    <button class="mcau-btn primary" type="submit">Importar / atualizar perfil</button>
  </form>
  <?php if ($groupProfiles): ?><div class="mcau-summary"><strong>Perfis integrados disponíveis:</strong> <?= mcau_h(implode(', ', array_map(static fn($p)=>(string)$p['name'], $groupProfiles))) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="mcau-grid">
  <div class="mcau-card">
    <h2>Usuários do grupo</h2>
    <?php if (!$users): ?><p class="mcau-muted">Nenhum usuário encontrado nas empresas deste grupo.</p><?php endif; ?>
    <?php foreach ($users as $u): $uid=(int)$u['id']; $origin=$companyMap[(int)$u['company_id']]['name'] ?? ('Empresa ' . (int)$u['company_id']); ?>
      <a class="mcau-user <?= $uid === $selectedUserId ? 'active' : '' ?>" href="<?= mcau_h(mcau_url('multiempresa_acessos.php?user_id=' . $uid)) ?>">
        <strong><?= mcau_h($u['name'] ?? '') ?><?= ($u['role'] ?? '') === 'company_admin' ? ' · Administrador' : '' ?></strong>
        <small><?= mcau_h($u['email'] ?? '') ?></small>
        <small>Origem: <?= mcau_h($origin) ?></small>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="mcau-card">
    <?php if (!$selectedUser): ?>
      <h2>Configuração do usuário</h2><p class="mcau-muted">Selecione um usuário para configurar.</p>
    <?php else: ?>
      <h2><?= mcau_h($selectedUser['name'] ?? '') ?></h2>
      <p class="mcau-muted">Defina em quais empresas este usuário poderá trabalhar. A empresa de origem continuará marcada nesta fase para preservar compatibilidade.</p>
      <form method="post" id="mcau-form">
        <input type="hidden" name="mc_csrf" value="<?= mcau_h($mcauCsrf) ?>">
        <input type="hidden" name="action" value="save_user_access">
        <input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">

        <?php if ($mode === 'integrated'): ?>
          <div class="mcau-field"><label>Perfil integrado</label><select name="group_profile_id" required><option value="">Selecione...</option><?php foreach ($groupProfiles as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($selectedCfg['group_profile_id'] ?? 0)===(int)$p['id']?'selected':'' ?>><?= mcau_h($p['name'] ?? '') ?></option><?php endforeach; ?></select></div>
          <?php if (!$groupProfiles): ?><div class="mcau-msg err">Crie ao menos um perfil integrado acima antes de salvar o usuário.</div><?php endif; ?>
        <?php endif; ?>

        <div class="mcau-field"><label>Empresas autorizadas</label></div>
        <?php foreach ($companies as $c):
          $ccid=(int)$c['id'];
          $origin = $ccid === (int)$selectedUser['company_id'];
          $saved = isset($selectedCfg['companies'][$ccid]);
          $checked = $saved || $origin;
          $savedProfile=(int)($selectedCfg['companies'][$ccid]['profile_id'] ?? 0);
          if ($savedProfile<=0 && $origin) $savedProfile=(int)($selectedUser['profile_id'] ?? 0);
          $isDefault = !empty($selectedCfg['companies'][$ccid]['is_default']);
          if (empty($selectedCfg['companies']) && $origin) $isDefault = true;
        ?>
          <div class="mcau-company" data-company="<?= $ccid ?>">
            <div class="mcau-company-top">
              <input class="mcau-company-check" type="checkbox" name="company_ids[]" value="<?= $ccid ?>" <?= $checked?'checked':'' ?> <?= $origin?'disabled':'' ?>>
              <?php if ($origin): ?><input type="hidden" name="company_ids[]" value="<?= $ccid ?>"><?php endif; ?>
              <span><?= mcau_h($c['name'] ?? '') ?></span>
              <?php if ($origin): ?><span class="mcau-badge">Empresa de origem</span><?php endif; ?>
            </div>
            <div class="mcau-company-grid">
              <?php if ($mode === 'isolated'): ?>
                <select name="company_profile_id[<?= $ccid ?>]" class="mcau-profile-select">
                  <option value="">Perfil nesta empresa...</option>
                  <?php foreach ($legacyProfilesByCompany[$ccid] ?? [] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $savedProfile===(int)$p['id']?'selected':'' ?>><?= mcau_h($p['name'] ?? '') ?></option><?php endforeach; ?>
                </select>
              <?php else: ?><div class="mcau-muted">Usará o perfil integrado selecionado acima.</div><?php endif; ?>
              <label style="display:flex;align-items:center;gap:7px;font-weight:800"><input type="radio" name="default_company_id" value="<?= $ccid ?>" <?= $isDefault?'checked':'' ?>> Empresa padrão</label>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="mcau-actions" style="margin-top:14px"><button class="mcau-btn primary" type="submit" <?= $mode==='integrated' && !$groupProfiles ? 'disabled' : '' ?>>Salvar acessos</button></div>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  const form=document.getElementById('mcau-form'); if(!form) return;
  function sync(){
    document.querySelectorAll('.mcau-company').forEach(box=>{
      const check=box.querySelector('.mcau-company-check');
      const hidden=box.querySelector('input[type=hidden][name="company_ids[]"]');
      const active=(check && check.checked) || !!hidden;
      const prof=box.querySelector('.mcau-profile-select');
      const radio=box.querySelector('input[type=radio]');
      if(prof) prof.disabled=!active;
      if(radio) radio.disabled=!active;
    });
    const chosen=document.querySelector('input[name="default_company_id"]:checked');
    if(!chosen || chosen.disabled){ const first=[...document.querySelectorAll('input[name="default_company_id"]')].find(r=>!r.disabled); if(first) first.checked=true; }
  }
  document.querySelectorAll('.mcau-company-check').forEach(c=>c.addEventListener('change',sync)); sync();
})();
</script>
<?php admin_footer(); ?>
