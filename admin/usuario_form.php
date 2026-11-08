<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/cash_operator_guard.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/finance_module.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/commercial_rules.php';
require_once __DIR__ . '/../config/email_service.php';

if (!access_is_company_master() && !access_has_permission($pdo, (int)COMPANY_ID, 'usuarios')) access_render_denied('Seu usuário não possui permissão para administrar usuários.');
$cid = (int)COMPANY_ID;
$err = '';
try { access_ensure_schema($pdo); commercial_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }
try { if (function_exists('finance_ensure_schema')) finance_ensure_schema($pdo); } catch (Throwable $e) {}
try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
try { email_service_ensure_schema($pdo); } catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }

function acluf_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function acluf_col(PDO $pdo, string $table, string $col): bool { return function_exists('access_column_exists') ? access_column_exists($pdo, $table, $col) : true; }
function acluf_series_text(array $s): string {
  $model = (string)($s['model'] ?? '');
  $label = ['55'=>'NF-e', '65'=>'NFC-e', 'NFSE'=>'NFS-e', 'DPS'=>'DPS', 'RPS'=>'RPS', 'SS'=>'SS'][$model] ?? $model;
  return $label . ' série ' . (string)($s['series'] ?? '') . ' · ' . (string)($s['environment'] ?? '');
}

$profiles = [];
try { $st = $pdo->prepare('SELECT id, name FROM user_profiles WHERE company_id = ? AND is_active = 1 ORDER BY name ASC'); $st->execute([$cid]); $profiles = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
$profileMap = [];
foreach ($profiles as $p) $profileMap[(int)$p['id']] = (string)$p['name'];

$banks = [];
try { $banks = finance_query_all($pdo, 'SELECT id, name, type FROM finance_bank_accounts WHERE company_id = ? AND active = 1 ORDER BY name ASC', [$cid]); } catch (Throwable $e) {}
$bankMap = [];
foreach ($banks as $b) $bankMap[(int)$b['id']] = (string)$b['name'];
$sellers = [];
try { $sellers = finance_query_all($pdo, 'SELECT id, name, fantasy_name FROM finance_people WHERE company_id = ? AND active = 1 AND is_seller = 1 ORDER BY name ASC', [$cid]); } catch (Throwable $e) {}
$sellerMap = [];
foreach ($sellers as $s) $sellerMap[(int)$s['id']] = (string)$s['name'];

$seriesRows = [];
try { $seriesRows = function_exists('fiscal_series_rows') ? fiscal_series_rows($pdo, $cid) : []; } catch (Throwable $e) {}
$seriesMap = [];
$seriesByPurpose = ['55'=>[], '65'=>[], 'service'=>[]];
foreach ($seriesRows as $s) {
  if (empty($s['is_active'])) continue;
  $sid = (int)$s['id'];
  $seriesMap[$sid] = $s;
  $m = strtoupper((string)($s['model'] ?? ''));
  if ($m === '55') $seriesByPurpose['55'][] = $s;
  elseif ($m === '65') $seriesByPurpose['65'][] = $s;
  elseif (in_array($m, ['NFSE','DPS','RPS','SS'], true)) $seriesByPurpose['service'][] = $s;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = null;
$editCash = [];
$editEmail = email_settings_default();
if ($id > 0) {
  try { $st=$pdo->prepare("SELECT * FROM users WHERE company_id = ? AND id = ? AND role IN ('company_admin','company_user') LIMIT 1"); $st->execute([$cid,$id]); $edit=$st->fetch(PDO::FETCH_ASSOC) ?: null; } catch(Throwable $e) {}
  if ($edit && ($edit['role'] ?? '') === 'company_admin' && !access_is_company_master()) access_render_denied('Somente um administrador pode alterar o usuário principal.');
  if (!$edit && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: usuarios.php?err=' . rawurlencode('Usuário não encontrado.')); exit; }
  try { $st=$pdo->prepare('SELECT bank_account_id FROM user_cash_accounts WHERE company_id = ? AND user_id = ?'); $st->execute([$cid,$id]); $editCash=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); } catch(Throwable $e) {}
  try { $editEmail = email_settings_load($pdo, $cid, $id); } catch(Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $profileId = (int)($_POST['profile_id'] ?? 0);
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $allowOsMobile = !empty($_POST['allow_os_mobile']) ? 1 : 0;
    $showContractNotice = !empty($_POST['show_contract_due_notice']) ? 1 : 0;
    $cashTerminal = !empty($_POST['cash_terminal_enabled']) ? 1 : 0;
    $defaultFinance = (int)($_POST['default_cash_bank_account_id'] ?? 0) ?: null;
    $defaultPdv = (int)($_POST['default_pdv_cash_bank_account_id'] ?? 0) ?: null;
    $defaultComanda = (int)($_POST['default_comanda_cash_bank_account_id'] ?? 0) ?: null;
    $defaultNfeSeries = (int)($_POST['default_nfe_series_id'] ?? 0) ?: null;
    $defaultNfceSeries = (int)($_POST['default_nfce_series_id'] ?? 0) ?: null;
    $defaultServiceSeries = (int)($_POST['default_service_series_id'] ?? 0) ?: null;
    $defaultSeller = (int)($_POST['default_seller_id'] ?? 0) ?: null;
    $allowSellerChange = !empty($_POST['allow_seller_change']) ? 1 : 0;
    $maxDiscountRaw = trim((string)($_POST['max_discount_percent'] ?? ''));
    $maxDiscount = $maxDiscountRaw === '' ? null : max(0.0, min(100.0, (float)str_replace(',', '.', $maxDiscountRaw)));
    $allowCreditOverride = !empty($_POST['allow_credit_override']) ? 1 : 0;
    $allowWaiveInterest = !empty($_POST['allow_waive_interest']) ? 1 : 0;
    $allowWaiveFine = !empty($_POST['allow_waive_fine']) ? 1 : 0;
    $maxSettlementDiscountRaw = trim((string)($_POST['max_settlement_discount_percent'] ?? ''));
    $maxSettlementDiscount = $maxSettlementDiscountRaw === '' ? null : max(0.0, min(100.0, (float)str_replace(',', '.', $maxSettlementDiscountRaw)));

    $emailCfgInput = [
      'enabled' => !empty($_POST['email_smtp_enabled']),
      'smtp_host' => trim((string)($_POST['email_smtp_host'] ?? '')),
      'smtp_port' => (int)($_POST['email_smtp_port'] ?? 587),
      'smtp_security' => (string)($_POST['email_smtp_security'] ?? 'tls'),
      'smtp_username' => trim((string)($_POST['email_smtp_username'] ?? '')),
      'smtp_password' => (string)($_POST['email_smtp_password'] ?? ''),
      'from_email' => strtolower(trim((string)($_POST['email_from_email'] ?? ''))),
      'from_name' => trim((string)($_POST['email_from_name'] ?? '')),
      'reply_to' => strtolower(trim((string)($_POST['email_reply_to'] ?? ''))),
      'timeout_seconds' => (int)($_POST['email_timeout_seconds'] ?? 30),
    ];

    $allowedCashIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cash_account_ids'] ?? [])), static fn($v) => $v > 0)));
    foreach ([$defaultFinance, $defaultPdv, $defaultComanda] as $defaultId) if ($defaultId) $allowedCashIds[] = (int)$defaultId;
    $allowedCashIds = array_values(array_unique($allowedCashIds));
    $validBankIds = array_fill_keys(array_map('intval', array_column($banks, 'id')), true);
    $allowedCashIds = array_values(array_filter($allowedCashIds, static fn($v) => isset($validBankIds[$v])));
    foreach (['Financeiro' => $defaultFinance, 'PDV' => $defaultPdv, 'Comanda' => $defaultComanda] as $label => $bankId) {
      if ($bankId && !isset($validBankIds[(int)$bankId])) throw new RuntimeException('Caixa padrão de ' . $label . ' inválido.');
    }

    // V11.61: caixas físicos são exclusivos; contas bancárias permanecem compartilháveis.
    // Validar no POST, não apenas desabilitar opções no HTML.
    foreach ($allowedCashIds as $cashId) {
      $conflict = nexa_cash_operator_conflict($pdo, $cid, (int)$cashId, $id > 0 ? $id : -1, $cashTerminal === 1 || !acluf_col($pdo, 'users', 'cash_terminal_enabled'));
      if ($conflict) throw new RuntimeException('Caixa físico em uso/vinculado a outro usuário: ' . (string)$conflict['name'] . '. Revise os caixas permitidos e os padrões.');
    }
    if ($name === '') throw new RuntimeException('Informe o nome do usuário.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
    if ($profileId <= 0 || !isset($profileMap[$profileId])) throw new RuntimeException('Selecione o perfil de acesso.');
    if ($defaultSeller && !isset($sellerMap[(int)$defaultSeller])) throw new RuntimeException('Vendedor padrão inválido.');
    if ($id <= 0 && strlen($password) < 6) throw new RuntimeException('Informe uma senha com pelo menos 6 caracteres.');
    if ($password !== '' && strlen($password) < 6) throw new RuntimeException('A senha deve ter pelo menos 6 caracteres.');

    foreach ([['NF-e', $defaultNfeSeries, '55'], ['NFC-e', $defaultNfceSeries, '65']] as [$label, $sid, $model]) {
      if ($sid && (!isset($seriesMap[$sid]) || strtoupper((string)$seriesMap[$sid]['model']) !== $model)) throw new RuntimeException('Série padrão de ' . $label . ' inválida.');
    }
    if ($defaultServiceSeries) {
      $m = strtoupper((string)($seriesMap[$defaultServiceSeries]['model'] ?? ''));
      if (!in_array($m, ['NFSE','DPS','RPS','SS'], true)) throw new RuntimeException('Série padrão de serviço inválida.');
    }

    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $st->execute([$email, $id]);
    if ($st->fetchColumn()) throw new RuntimeException('Já existe outro usuário com este e-mail.');

    $seriesCols = [];
    if (acluf_col($pdo, 'users', 'default_nfe_series_id')) $seriesCols['default_nfe_series_id'] = $defaultNfeSeries;
    if (acluf_col($pdo, 'users', 'default_nfce_series_id')) $seriesCols['default_nfce_series_id'] = $defaultNfceSeries;
    if (acluf_col($pdo, 'users', 'default_service_series_id')) $seriesCols['default_service_series_id'] = $defaultServiceSeries;

    $userAccessCols = [];
    if (acluf_col($pdo, 'users', 'allow_os_mobile')) $userAccessCols['allow_os_mobile'] = $allowOsMobile;
    if (acluf_col($pdo, 'users', 'show_contract_due_notice')) $userAccessCols['show_contract_due_notice'] = $showContractNotice;
    if (acluf_col($pdo, 'users', 'cash_terminal_enabled')) $userAccessCols['cash_terminal_enabled'] = $cashTerminal;

    if ($id > 0) {
      $chk = $pdo->prepare("SELECT id,role FROM users WHERE company_id = ? AND id = ? AND role IN ('company_admin','company_user') LIMIT 1");
      $chk->execute([$cid, $id]);
      $userRow = $chk->fetch(PDO::FETCH_ASSOC);
      if (!$userRow) throw new RuntimeException('Usuário não encontrado.');
      $isPrimaryAdmin = (string)$userRow['role'] === 'company_admin';
      if ($isPrimaryAdmin && !access_is_company_master()) throw new RuntimeException('Sem permissão para alterar o administrador da empresa.');
      if ($isPrimaryAdmin) $active = 1; // Impede bloqueio acidental do único administrador.
      $sets = ['name=?','email=?','profile_id=?','default_cash_bank_account_id=?','default_pdv_cash_bank_account_id=?','default_comanda_cash_bank_account_id=?','is_active=?','default_seller_id=?','allow_seller_change=?','max_discount_percent=?','allow_credit_override=?','allow_waive_interest=?','allow_waive_fine=?','max_settlement_discount_percent=?'];
      $params = [$name, $email, $profileId, $defaultFinance, $defaultPdv, $defaultComanda, $active, $defaultSeller, $allowSellerChange, $maxDiscount, $allowCreditOverride, $allowWaiveInterest, $allowWaiveFine, $maxSettlementDiscount];
      foreach ($seriesCols as $col => $val) { $sets[] = '`' . $col . '`=?'; $params[] = $val; }
      foreach ($userAccessCols as $col => $val) { $sets[] = '`' . $col . '`=?'; $params[] = $val; }
      if ($password !== '') {
        $sets[] = 'password_hash=?'; $params[] = password_hash($password, PASSWORD_DEFAULT);
        // Não manter a senha provisória antiga após troca de senha do administrador.
        if (acluf_col($pdo, 'users', 'last_plain_password')) $sets[] = 'last_plain_password=NULL';
      }
      $params[] = $cid; $params[] = $id;
      $pdo->prepare('UPDATE users SET ' . implode(',', $sets) . ", updated_at=NOW() WHERE company_id=? AND id=? AND role IN ('company_admin','company_user')")->execute($params);
      $userId = $id;
    } else {
      $cols = ['company_id','name','email','password_hash','role','is_active','profile_id','default_cash_bank_account_id','default_pdv_cash_bank_account_id','default_comanda_cash_bank_account_id','default_seller_id','allow_seller_change','max_discount_percent','allow_credit_override','allow_waive_interest','allow_waive_fine','max_settlement_discount_percent','created_at'];
      $values = [$cid, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'company_user', $active, $profileId, $defaultFinance, $defaultPdv, $defaultComanda, $defaultSeller, $allowSellerChange, $maxDiscount, $allowCreditOverride, $allowWaiveInterest, $allowWaiveFine, $maxSettlementDiscount, date('Y-m-d H:i:s')];
      foreach ($seriesCols as $col => $val) { $cols[] = $col; $values[] = $val; }
      foreach ($userAccessCols as $col => $val) { $cols[] = $col; $values[] = $val; }
      $ph = implode(',', array_fill(0, count($cols), '?'));
      $pdo->prepare('INSERT INTO users (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')')->execute($values);
      $userId = (int)$pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM user_cash_accounts WHERE company_id = ? AND user_id = ?')->execute([$cid, $userId]);
    if ($allowedCashIds) {
      $ins = $pdo->prepare('INSERT IGNORE INTO user_cash_accounts (company_id, user_id, bank_account_id) VALUES (?, ?, ?)');
      foreach ($allowedCashIds as $bankId) $ins->execute([$cid, $userId, (int)$bankId]);
    }
    email_settings_save($pdo, $cid, $userId, $emailCfgInput);
    header('Location: usuario_form.php?id=' . $userId . '&ok=' . rawurlencode('Usuário, acessos e configurações de e-mail salvos.')); exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
    $edit = array_merge($edit ?: [], $_POST);
    $editCash = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cash_account_ids'] ?? [])), static fn($v)=>$v>0)));
    $editEmail = array_merge($editEmail, $emailCfgInput ?? []);
  }
}

$form = $edit ?: ['id'=>0,'name'=>'','email'=>'','profile_id'=>(int)($profiles[0]['id'] ?? 0),'is_active'=>1,'default_cash_bank_account_id'=>null,'default_pdv_cash_bank_account_id'=>null,'default_comanda_cash_bank_account_id'=>null,'default_nfe_series_id'=>null,'default_nfce_series_id'=>null,'default_service_series_id'=>null,'default_seller_id'=>null,'allow_seller_change'=>1,'max_discount_percent'=>null,'allow_credit_override'=>0,'allow_waive_interest'=>0,'allow_waive_fine'=>0,'max_settlement_discount_percent'=>null,'allow_os_mobile'=>1,'show_contract_due_notice'=>1];
if ($id <= 0 && trim((string)($editEmail['from_email'] ?? '')) === '') $editEmail['from_email'] = (string)($form['email'] ?? '');
$ok = trim((string)($_GET['ok'] ?? ''));

admin_topbar(($id > 0 ? 'Editar usuário' : 'Novo usuário'), 'usuarios');
?>
<style>
.acluf-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}.acluf-head h1{margin:0;color:var(--admin-primary)}.acluf-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08);margin-bottom:14px}.acluf-card h2{margin:0 0 10px;color:var(--admin-primary)}.acluf-muted{color:#64748b;line-height:1.45}.acluf-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.acluf-form-grid .span-2{grid-column:span 2}.acluf-form-grid .span-3{grid-column:1/-1}.acluf-form label{font-weight:850;color:#334155}.acluf-form input,.acluf-form select{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;background:#fff}.acluf-check{display:flex;gap:8px;align-items:flex-start;border:1px solid #e2e8f0;background:#f8fbff;border-radius:14px;padding:9px}.acluf-check input{width:auto;margin-top:3px}.acluf-cash-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.acluf-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.acluf-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}.acluf-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}.msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:980px){.acluf-form-grid{grid-template-columns:1fr}.acluf-form-grid .span-2,.acluf-form-grid .span-3{grid-column:1}.acluf-cash-grid{grid-template-columns:1fr}}
</style>
<?php if ($ok): ?><div class="msg ok"><?= acluf_h($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg err"><?= acluf_h($err) ?></div><?php endif; ?>
<div class="acluf-head">
  <div><h1><?= $id > 0 ? 'Editar usuário' : 'Cadastrar novo usuário' ?></h1><p class="acluf-muted" style="margin:5px 0 0">Defina acesso, caixa padrão e a série fiscal que este operador usará na emissão.</p></div>
  <a class="acluf-btn" href="usuarios.php">Voltar para lista</a>
</div>
<form method="post" class="acluf-form">
  <input type="hidden" name="id" value="<?= (int)($form['id'] ?? $id) ?>">
  <div class="acluf-card">
    <h2>Dados do usuário</h2>
    <div class="acluf-form-grid">
      <div><label>Nome<input name="name" value="<?= acluf_h($form['name'] ?? '') ?>" required></label></div>
      <div><label>E-mail<input type="email" name="email" value="<?= acluf_h($form['email'] ?? '') ?>" required></label></div>
      <div><label>Perfil<select name="profile_id" required><option value="">Selecione</option><?php foreach($profiles as $p): ?><option value="<?= (int)$p['id'] ?>" <?= ((int)($form['profile_id'] ?? 0)===(int)$p['id'])?'selected':'' ?>><?= acluf_h($p['name']) ?></option><?php endforeach; ?></select></label></div>
      <div><label>Senha<?= $id > 0 ? ' <span class="acluf-muted">(em branco mantém)</span>' : '' ?><input type="password" name="password" autocomplete="new-password" <?= $id > 0 ? '' : 'required' ?>></label></div>
      <label class="acluf-check"><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active'])?'checked':'' ?>><span><b>Usuário ativo</b><br><span class="acluf-muted">Permite login e operação no sistema.</span></span></label>
      <label class="acluf-check"><input type="checkbox" name="allow_os_mobile" value="1" <?= !empty($form['allow_os_mobile'])?'checked':'' ?>><span><b>Habilitar O.S. Mobile</b><br><span class="acluf-muted">Quando desmarcado, oculta a O.S. Mobile do menu e bloqueia o acesso direto para este usuário.</span></span></label>
      <label class="acluf-check"><input type="checkbox" name="show_contract_due_notice" value="1" <?= !empty($form['show_contract_due_notice'])?'checked':'' ?> <?= !acluf_col($pdo,'users','show_contract_due_notice')?'disabled':'' ?>><span><b>Habilita mensagem de vencimento de contrato</b><br><span class="acluf-muted">Exibe o aviso após o login na Área de Trabalho e no Dashboard. Ativado por padrão para novos usuários e administradores do SaaS. <?= !acluf_col($pdo,'users','show_contract_due_notice')?'Aguardando atualização do banco.':'' ?></span></span></label>
    </div>
  </div>

  <div class="acluf-card">
    <h2>Caixas e contas permitidas</h2>
    <?php $cashTerminalReady = acluf_col($pdo, 'users', 'cash_terminal_enabled'); ?>
    <label class="acluf-check" style="margin-bottom:12px"><input type="checkbox" name="cash_terminal_enabled" value="1" <?= !empty($form['cash_terminal_enabled'])?'checked':'' ?> <?= $cashTerminalReady?'':'disabled' ?>><span><b>Ativar Caixa Terminal</b><br><span class="acluf-muted">Marcado: este usuário abre e fecha caixa, precisa do caixa aberto para vender nas formas de pagamento que exigem caixa, e o caixa físico dele fica exclusivo. Desmarcado (padrão): vende, recebe e paga sem abrir caixa; os lançamentos vão para o Movimento Financeiro normalmente.<?= $cashTerminalReady ? '' : ' Para usar, aplique "Caixa - Ativar Caixa Terminal por usuário" no Atualizador do Banco.' ?></span></span></label>
    <div class="acluf-form-grid">
      <div><label>Caixa padrão financeiro<select name="default_cash_bank_account_id"><option value="">Selecione</option><?php foreach($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= ((int)($form['default_cash_bank_account_id'] ?? 0)===(int)$b['id'])?'selected':'' ?> <?= nexa_cash_operator_conflict($pdo,$cid,(int)$b['id'],$id>0?$id:-1)?'disabled':'' ?>><?= acluf_h($b['name']) ?></option><?php endforeach; ?></select></label></div>
      <div><label>Caixa padrão PDV<select name="default_pdv_cash_bank_account_id"><option value="">Usar padrão financeiro</option><?php foreach($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= ((int)($form['default_pdv_cash_bank_account_id'] ?? 0)===(int)$b['id'])?'selected':'' ?> <?= nexa_cash_operator_conflict($pdo,$cid,(int)$b['id'],$id>0?$id:-1)?'disabled':'' ?>><?= acluf_h($b['name']) ?></option><?php endforeach; ?></select></label></div>
      <div><label>Caixa padrão Comanda<select name="default_comanda_cash_bank_account_id"><option value="">Usar padrão financeiro</option><?php foreach($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= ((int)($form['default_comanda_cash_bank_account_id'] ?? 0)===(int)$b['id'])?'selected':'' ?> <?= nexa_cash_operator_conflict($pdo,$cid,(int)$b['id'],$id>0?$id:-1)?'disabled':'' ?>><?= acluf_h($b['name']) ?></option><?php endforeach; ?></select></label></div>
      <div class="span-3"><label>Caixas permitidos</label><div class="acluf-cash-grid"><?php foreach($banks as $b): ?><label class="acluf-check"><input type="checkbox" name="cash_account_ids[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $editCash, true)?'checked':'' ?> <?= nexa_cash_operator_conflict($pdo,$cid,(int)$b['id'],$id>0?$id:-1)?'disabled':'' ?>><span><?= acluf_h($b['name']) ?><br><span class="acluf-muted"><?= acluf_h($b['type'] ?? '') ?></span></span></label><?php endforeach; ?><?php if(!$banks): ?><div class="acluf-muted">Nenhum caixa/banco ativo cadastrado.</div><?php endif; ?></div></div>
    </div>
  </div>

  <div class="acluf-card">
    <h2>Regras comerciais</h2>
    <p class="acluf-muted">Defina o vendedor sugerido, permissão de troca, limite de desconto e autorização para exceção de crédito.</p>
    <div class="acluf-form-grid">
      <div><label>Vendedor padrão<select name="default_seller_id"><option value="">Sem vendedor padrão</option><?php foreach($sellers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ((int)($form['default_seller_id'] ?? 0)===(int)$s['id'])?'selected':'' ?>><?= acluf_h($s['name']) ?></option><?php endforeach; ?></select></label></div>
      <div><label>Limite máximo de desconto em vendas (%)<input name="max_discount_percent" inputmode="decimal" placeholder="Em branco = sem limite" value="<?= $form['max_discount_percent'] === null || $form['max_discount_percent'] === '' ? '' : acluf_h(number_format((float)$form['max_discount_percent'],2,',','')) ?>"></label><div class="acluf-muted" style="font-size:12px;margin-top:5px">Ex.: 10,00 permite desconto de até 10% sobre os produtos.</div></div>
      <label class="acluf-check"><input type="checkbox" name="allow_seller_change" value="1" <?= !empty($form['allow_seller_change'])?'checked':'' ?>><span><b>Habilita alteração de vendedor</b><br><span class="acluf-muted">Quando desmarcado, o sistema usa apenas o vendedor padrão deste usuário.</span></span></label>
      <label class="acluf-check"><input type="checkbox" name="allow_credit_override" value="1" <?= !empty($form['allow_credit_override'])?'checked':'' ?>><span><b>Permite liberar venda com limite excedido</b><br><span class="acluf-muted">Autoriza este usuário a informar seu código e senha para liberar uma venda acima do limite de crédito do cliente.</span></span></label>
      <div><label>Limite máximo de desconto na baixa (%)<input name="max_settlement_discount_percent" inputmode="decimal" placeholder="Em branco = sem limite" value="<?= $form['max_settlement_discount_percent'] === null || $form['max_settlement_discount_percent'] === '' ? '' : acluf_h(number_format((float)$form['max_settlement_discount_percent'],2,',','')) ?>"></label><div class="acluf-muted" style="font-size:12px;margin-top:5px">Em branco: qualquer desconto adicional. 0,00: não permite desconto manual. Ex.: 10,00 limita a 10% na baixa.</div></div>
      <label class="acluf-check"><input type="checkbox" name="allow_waive_interest" value="1" <?= !empty($form['allow_waive_interest'])?'checked':'' ?>><span><b>Permite dispensar juros</b><br><span class="acluf-muted">Permite retirar os juros calculados pelo CRE durante a baixa de contas a receber.</span></span></label>
      <label class="acluf-check"><input type="checkbox" name="allow_waive_fine" value="1" <?= !empty($form['allow_waive_fine'])?'checked':'' ?>><span><b>Permite dispensar multa</b><br><span class="acluf-muted">Permite retirar a multa calculada pelo CRE durante a baixa de contas a receber.</span></span></label>
    </div>
  </div>

  <div class="acluf-card">
    <div class="acluf-head" style="margin-bottom:8px">
      <div>
        <h2 style="margin:0">Configurações de e-mail</h2>
        <p class="acluf-muted" style="margin:5px 0 0">Configuração SMTP específica deste usuário. Quando ele estiver logado, os envios de documentos usarão esta conta.</p>
      </div>
      <?php if ($id > 0): ?><a class="acluf-btn" href="usuario_email_teste.php?id=<?= (int)$id ?>">Testar e-mail</a><?php endif; ?>
    </div>
    <div class="acluf-form-grid">
      <label class="acluf-check"><input type="checkbox" name="email_smtp_enabled" value="1" <?= !empty($editEmail['enabled'])?'checked':'' ?>><span><b>Habilitar envio por e-mail</b><br><span class="acluf-muted">Ativa o SMTP deste usuário para Pedido, documentos fiscais, boletos e relatórios.</span></span></label>
      <div><label>Servidor SMTP<input name="email_smtp_host" value="<?= acluf_h($editEmail['smtp_host'] ?? '') ?>" placeholder="Ex.: smtp.seudominio.com.br"></label></div>
      <div><label>Porta<input type="number" min="1" max="65535" name="email_smtp_port" value="<?= (int)($editEmail['smtp_port'] ?? 587) ?>"></label></div>
      <div><label>Segurança<select name="email_smtp_security"><option value="tls" <?= (($editEmail['smtp_security'] ?? 'tls')==='tls')?'selected':'' ?>>STARTTLS / TLS</option><option value="ssl" <?= (($editEmail['smtp_security'] ?? '')==='ssl')?'selected':'' ?>>SSL/TLS direto</option><option value="none" <?= (($editEmail['smtp_security'] ?? '')==='none')?'selected':'' ?>>Sem criptografia</option></select></label></div>
      <div><label>Usuário SMTP<input name="email_smtp_username" value="<?= acluf_h($editEmail['smtp_username'] ?? '') ?>" autocomplete="off" placeholder="Normalmente o próprio e-mail"></label></div>
      <div><label>Senha SMTP <span class="acluf-muted">(em branco mantém)</span><input type="password" name="email_smtp_password" autocomplete="new-password" placeholder="<?= !empty($editEmail['smtp_password_secret']) ? 'Senha já configurada' : 'Informe a senha ou senha de aplicativo' ?>"></label></div>
      <div><label>E-mail remetente<input type="email" name="email_from_email" value="<?= acluf_h($editEmail['from_email'] ?? ($form['email'] ?? '')) ?>" placeholder="contato@empresa.com.br"></label></div>
      <div><label>Nome do remetente<input name="email_from_name" value="<?= acluf_h($editEmail['from_name'] ?? '') ?>" placeholder="Ex.: Minha Empresa"></label></div>
      <div><label>Responder para (opcional)<input type="email" name="email_reply_to" value="<?= acluf_h($editEmail['reply_to'] ?? '') ?>" placeholder="financeiro@empresa.com.br"></label></div>
      <div><label>Timeout (segundos)<input type="number" min="10" max="120" name="email_timeout_seconds" value="<?= (int)($editEmail['timeout_seconds'] ?? 30) ?>"></label></div>
      <div class="span-3 acluf-muted" style="font-size:12px">A senha SMTP é armazenada protegida no banco. Para Gmail, Microsoft 365 e alguns provedores, pode ser necessário usar senha de aplicativo ou liberar autenticação SMTP.</div>
    </div>
  </div>

  <div class="acluf-card">
    <h2>Séries fiscais do operador</h2>
    <p class="acluf-muted">Quando o usuário emitir NF-e/NFC-e, o sistema usará a série abaixo. Sem seleção, será usada a primeira série ativa do modelo no ambiente fiscal.</p>
    <div class="acluf-form-grid">
      <div><label>Série NFC-e modelo 65<select name="default_nfce_series_id"><option value="">Primeira série ativa</option><?php foreach($seriesByPurpose['65'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ((int)($form['default_nfce_series_id'] ?? 0)===(int)$s['id'])?'selected':'' ?>><?= acluf_h(acluf_series_text($s)) ?></option><?php endforeach; ?></select></label></div>
      <div><label>Série NF-e modelo 55<select name="default_nfe_series_id"><option value="">Primeira série ativa</option><?php foreach($seriesByPurpose['55'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ((int)($form['default_nfe_series_id'] ?? 0)===(int)$s['id'])?'selected':'' ?>><?= acluf_h(acluf_series_text($s)) ?></option><?php endforeach; ?></select></label></div>
      <div><label>Série serviço / SS<select name="default_service_series_id"><option value="">Primeira série ativa</option><?php foreach($seriesByPurpose['service'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ((int)($form['default_service_series_id'] ?? 0)===(int)$s['id'])?'selected':'' ?>><?= acluf_h(acluf_series_text($s)) ?></option><?php endforeach; ?></select></label></div>
    </div>
  </div>

  <div class="acluf-actions"><a class="acluf-btn" href="usuarios.php">Cancelar</a><button class="acluf-btn primary" type="submit">Salvar usuário</button></div>
</form>
<?php admin_footer(); ?>
