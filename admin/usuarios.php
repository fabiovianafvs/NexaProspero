<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/finance_module.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/multi_company.php';

$isCrossUsers = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
$cid = $isCrossUsers && function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'users_read')
  : (int)COMPANY_ID;
if ($isCrossUsers) {
  $allowedUsers = (function_exists('access_is_company_master') && access_is_company_master())
    || (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'usuarios'));
  if (!$allowedUsers) access_render_denied('Seu perfil na empresa selecionada não possui permissão para consultar usuários.');
} elseif (!access_is_company_master() && !access_has_permission($pdo, $cid, 'usuarios')) {
  access_render_denied('Seu usuário não possui permissão para administrar usuários.');
}
$multiCompanyGroup = multi_company_group_by_company($pdo, $cid);
$err = '';
try { access_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }
try { if (!$isCrossUsers && function_exists('finance_ensure_schema')) finance_ensure_schema($pdo); } catch (Throwable $e) {}
try { if (!$isCrossUsers && function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}

function aclu_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function aclu_url(string $url): string { return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url; }
function aclu_series_label(?array $s): string {
  if (!$s) return '-';
  $model = (string)($s['model'] ?? '');
  $label = ['55'=>'NF-e', '65'=>'NFC-e', 'NFSE'=>'NFS-e', 'DPS'=>'DPS', 'RPS'=>'RPS', 'SS'=>'SS'][$model] ?? $model;
  return trim($label . ' série ' . (string)($s['series'] ?? '') . ' / ' . (string)($s['environment'] ?? ''));
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if ($isCrossUsers) throw new RuntimeException('No contexto F11 o status da identidade-base não é alterado. Use Editar acesso para administrar o vínculo/perfil da empresa selecionada.');
    $id = (int)($_POST['id'] ?? 0);
    $active = (int)($_POST['active'] ?? 0) ? 1 : 0;
    if ($id <= 0) throw new RuntimeException('Usuário não informado.');
    if ($id === (int)(auth_user_id() ?? 0)) throw new RuntimeException('Você não pode inativar o próprio usuário.');
    $pdo->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE company_id = ? AND id = ? AND role = 'company_user'")->execute([$active, $cid, $id]);
    header('Location: usuarios.php?ok=' . rawurlencode($active ? 'Usuário ativado.' : 'Usuário inativado.')); exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$users = [];
try {
  if ($isCrossUsers) {
    $groupId = (int)($multiCompanyGroup['id'] ?? 0);
    $groupMode = strtolower(trim((string)($multiCompanyGroup['operation_mode'] ?? 'isolated')));
    if ($groupMode === 'integrated' && $groupId > 0) {
      $st = $pdo->prepare("SELECT DISTINCT u.*, bgp.name AS profile_name, oc.name AS origin_company_name,
        NULL AS caixa_financeiro, NULL AS caixa_pdv, NULL AS caixa_comanda
        FROM users u
        LEFT JOIN companies oc ON oc.id = u.company_id
        LEFT JOIN user_company_access uca ON uca.user_id = u.id AND uca.company_id = ? AND uca.is_active = 1
        LEFT JOIN business_group_users bgu ON bgu.user_id = u.id AND bgu.business_group_id = ? AND bgu.is_active = 1
        LEFT JOIN business_group_profiles bgp ON bgp.id = bgu.group_profile_id AND bgp.business_group_id = ? AND bgp.is_active = 1
        WHERE u.is_active = 1 AND u.role IN ('company_admin','company_user') AND (u.company_id = ? OR uca.user_id IS NOT NULL)
        ORDER BY u.name ASC, u.id ASC");
      $st->execute([$cid, $groupId, $groupId, $cid]);
    } else {
      $st = $pdo->prepare("SELECT DISTINCT u.*, p.name AS profile_name, oc.name AS origin_company_name,
        NULL AS caixa_financeiro, NULL AS caixa_pdv, NULL AS caixa_comanda
        FROM users u
        LEFT JOIN companies oc ON oc.id = u.company_id
        LEFT JOIN user_company_access uca ON uca.user_id = u.id AND uca.company_id = ? AND uca.is_active = 1
        LEFT JOIN user_profiles p ON p.company_id = ? AND p.id = CASE
          WHEN uca.company_profile_id IS NOT NULL AND uca.company_profile_id > 0 THEN uca.company_profile_id
          WHEN u.company_id = ? THEN u.profile_id ELSE NULL END
        WHERE u.is_active = 1 AND u.role IN ('company_admin','company_user') AND (u.company_id = ? OR uca.user_id IS NOT NULL)
        ORDER BY u.name ASC, u.id ASC");
      $st->execute([$cid, $cid, $cid, $cid]);
    }
  } else {
    $st = $pdo->prepare("SELECT u.*, p.name AS profile_name,
      bf.name AS caixa_financeiro, bp.name AS caixa_pdv, bc.name AS caixa_comanda,
      NULL AS origin_company_name
      FROM users u
      LEFT JOIN user_profiles p ON p.company_id = u.company_id AND p.id = u.profile_id
      LEFT JOIN finance_bank_accounts bf ON bf.company_id = u.company_id AND bf.id = u.default_cash_bank_account_id
      LEFT JOIN finance_bank_accounts bp ON bp.company_id = u.company_id AND bp.id = u.default_pdv_cash_bank_account_id
      LEFT JOIN finance_bank_accounts bc ON bc.company_id = u.company_id AND bc.id = u.default_comanda_cash_bank_account_id
      WHERE u.company_id = ? AND u.role IN ('company_admin','company_user')
      ORDER BY u.is_active DESC, u.name ASC");
    $st->execute([$cid]);
  }
  $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $err = trim($err . ' ' . $e->getMessage()); }

$userCashLabels = [];
if ($users) {
  try {
    $ids = array_map('intval', array_column($users, 'id'));
    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$cid], $ids);
    $st = $pdo->prepare("SELECT uca.user_id, b.name FROM user_cash_accounts uca JOIN finance_bank_accounts b ON b.company_id = uca.company_id AND b.id = uca.bank_account_id WHERE uca.company_id = ? AND uca.user_id IN ({$place}) ORDER BY b.name ASC");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $userCashLabels[(int)$r['user_id']][] = (string)$r['name'];
  } catch (Throwable $e) {}
}

$seriesById = [];
try {
  $rows = function_exists('fiscal_series_rows') ? fiscal_series_rows($pdo, $cid) : [];
  foreach ($rows as $s) $seriesById[(int)$s['id']] = $s;
} catch (Throwable $e) {}
$fiscalEnvironment = 'HOMOLOGACAO';
try {
  $stEnv = $pdo->prepare('SELECT environment FROM fiscal_company_config WHERE company_id=? LIMIT 1');
  $stEnv->execute([$cid]);
  $envDb = strtoupper(trim((string)($stEnv->fetchColumn() ?: '')));
  if ($envDb !== '') $fiscalEnvironment = $envDb;
} catch (Throwable $e) {}

admin_topbar('Usuários', 'usuarios');
?>
<style>
.aclu-page-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}.aclu-page-head h1{margin:0;color:var(--admin-primary)}.aclu-muted{color:#64748b;line-height:1.45}.aclu-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08)}.aclu-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}.aclu-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.aclu-btn.danger{border-color:#fecaca;color:#b91c1c;background:#fef2f2}.aclu-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:12px;font-weight:900}.aclu-badge.off{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.aclu-table-wrap{overflow:auto}.aclu-table{width:100%;border-collapse:separate;border-spacing:0}.aclu-table th,.aclu-table td{padding:11px 10px;border-bottom:1px solid #e5edf6;text-align:left;vertical-align:top}.aclu-table th{background:#f8fbff;color:#334155;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.aclu-user-title strong{display:block;color:#0b1f5b}.aclu-user-title span,.aclu-small{display:block;color:#64748b;font-size:12px;margin-top:3px}.aclu-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}.msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.aclu-empty{border:1px dashed #cbd5e1;border-radius:18px;background:#f8fbff;color:#64748b;text-align:center;padding:26px}@media(max-width:860px){.aclu-table,.aclu-table thead,.aclu-table tbody,.aclu-table th,.aclu-table td,.aclu-table tr{display:block}.aclu-table thead{display:none}.aclu-table tr{border:1px solid #e5edf6;border-radius:18px;margin-bottom:10px;padding:8px}.aclu-table td{border-bottom:0;padding:8px}.aclu-table td:before{content:attr(data-label);display:block;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:900;margin-bottom:3px}.aclu-actions{justify-content:flex-start}}
</style>
<?php if ($err): ?><div class="msg err"><?= aclu_h($err) ?></div><?php endif; ?><?php if (!empty($_GET['ok'])): ?><div class="msg ok"><?= aclu_h($_GET['ok']) ?></div><?php endif; ?>
<div class="aclu-page-head">
  <div><h1>Usuários</h1><p class="aclu-muted" style="margin:5px 0 0"><?= $isCrossUsers ? 'Usuários com acesso à empresa selecionada pelo F11. O vínculo e o perfil da empresa podem ser administrados sem alterar a identidade-base do usuário.' : 'Listagem dos operadores, caixas permitidos e séries fiscais vinculadas ao usuário.' ?></p></div>
  <div class="aclu-actions">
    <?php if ($multiCompanyGroup && function_exists('access_is_company_master') && access_is_company_master()): ?><a class="aclu-btn" href="<?= aclu_h(aclu_url('multiempresa_acessos.php')) ?>">Acessos multiempresa</a><?php endif; ?>
    <?php if (!$isCrossUsers): ?><a class="aclu-btn primary" href="usuario_form.php">Cadastrar novo usuário</a><?php else: ?><span class="aclu-badge">Vínculos editáveis</span><?php endif; ?>
  </div>
</div>
<?php if ($isCrossUsers): ?><div class="msg ok"><strong>V11.36 · Edição segura:</strong> o perfil e o acesso à empresa selecionada podem ser alterados em <b>Editar acesso</b>. Nome, e-mail, senha e empresa de origem continuam pertencendo à identidade-base e não são duplicados pelo F11.</div><?php endif; ?>
<div class="aclu-card">
  <?php if (!$users): ?>
    <div class="aclu-empty"><?= $isCrossUsers ? 'Nenhum usuário possui acesso ativo a esta empresa.' : 'Nenhum usuário cadastrado. Clique em <b>Cadastrar novo usuário</b> para criar o primeiro operador.' ?></div>
  <?php else: ?>
    <div class="aclu-table-wrap"><table class="aclu-table"><thead><tr><th>Usuário</th><th>Perfil</th><th>Caixas</th><th>Séries fiscais</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead><tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $uid = (int)$u['id'];
          $cash = $userCashLabels[$uid] ?? [];
          $seriesParts = [];
          if (function_exists('fiscal_user_default_series')) {
            foreach ([['65','NFC-e'],['55','NF-e']] as [$model,$labelSeries]) {
              $sr = fiscal_user_default_series($pdo, $cid, $uid, $model, $fiscalEnvironment);
              if ($sr) $seriesParts[] = aclu_series_label($sr);
            }
            foreach (['NFSE','DPS','RPS','SS'] as $serviceModel) {
              $sr = fiscal_user_default_series($pdo, $cid, $uid, $serviceModel, $fiscalEnvironment);
              if ($sr) { $seriesParts[] = aclu_series_label($sr); break; }
            }
          } else {
            foreach (['default_nfce_series_id','default_nfe_series_id','default_service_series_id'] as $col) {
              $sid = (int)($u[$col] ?? 0);
              if ($sid > 0) $seriesParts[] = aclu_series_label($seriesById[$sid] ?? null);
            }
          }
        ?>
        <tr>
          <td data-label="Usuário" class="aclu-user-title"><strong><?= aclu_h($u['name'] ?? '') ?></strong><?php if(($u['role']??'')==='company_admin'): ?><span>Administrador da empresa</span><?php endif; ?><span><?= aclu_h($u['email'] ?? '') ?></span><?php if($isCrossUsers && !empty($u['origin_company_name'])): ?><span>Origem: <?= aclu_h($u['origin_company_name']) ?></span><?php endif; ?></td>
          <td data-label="Perfil"><?= aclu_h($u['profile_name'] ?? '-') ?></td>
          <td data-label="Caixas"><?php if($cash): ?><?= aclu_h(implode(', ', $cash)) ?><?php else: ?><span class="aclu-small">Sem caixa específico</span><?php endif; ?><?php if(!empty($u['caixa_pdv'])): ?><span class="aclu-small">PDV: <?= aclu_h($u['caixa_pdv']) ?></span><?php endif; ?><?php if(!empty($u['caixa_comanda'])): ?><span class="aclu-small">Comanda: <?= aclu_h($u['caixa_comanda']) ?></span><?php endif; ?></td>
          <td data-label="Séries fiscais"><?= $seriesParts ? aclu_h(implode(' · ', $seriesParts)) : '<span class="aclu-small">Usa a primeira série ativa do modelo</span>' ?></td>
          <td data-label="Status"><span class="aclu-badge <?= !empty($u['is_active']) ? '' : 'off' ?>"><?= !empty($u['is_active']) ? 'Ativo' : 'Inativo' ?></span></td>
          <td data-label="Ações"><div class="aclu-actions"><?php if($isCrossUsers): ?><?php if(function_exists('access_is_company_master') && access_is_company_master()): ?><a class="aclu-btn primary" href="<?= aclu_h(aclu_url('multiempresa_acessos.php?user_id=' . $uid)) ?>">Editar acesso</a><?php else: ?><span class="aclu-small">Somente o administrador principal altera vínculos.</span><?php endif; ?><?php else: ?><a class="aclu-btn" href="usuario_form.php?id=<?= $uid ?>">Editar</a><?php if (($u['role'] ?? '') === 'company_user'): ?><form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="active" value="<?= empty($u['is_active']) ? '1' : '0' ?>"><button class="aclu-btn <?= empty($u['is_active']) ? '' : 'danger' ?>" type="submit"><?= empty($u['is_active']) ? 'Ativar' : 'Inativar' ?></button></form><?php endif; ?><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
