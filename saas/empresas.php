<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/company_identity.php';
require_once __DIR__ . '/../config/config_store.php';
try { saas_ensure_modules_schema($pdo); } catch (Throwable $e) {}

function saas_has_column_local(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function saas_company_slug_clean(string $value): string {
  return company_subdomain_normalize($value);
}

function saas_company_digits(string $value): string {
  return preg_replace('/\D+/', '', $value) ?? '';
}

function money_br(float $v): string {
  return 'R$ ' . number_format($v, 2, ',', '.');
}

function badge_color(?string $status): string {
  return match ($status) {
    'active' => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
    'trial' => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
    'past_due' => 'background:#fff7ed;color:#c2410c;border:1px solid #fdba74;',
    'canceled' => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
    'blocked', 'suspended' => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
    default => 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;',
  };
}

$companyFields = [
  'nome_fantasia','razao_social','person_type','cnpj','cpf','celular','cep','endereco','numero',
  'complemento','bairro','cidade','estado','subdomain','custom_domain','email','whatsapp','status'
];
$companyColumns = [];
foreach ($companyFields as $field) $companyColumns[$field] = saas_has_column_local($pdo, 'companies', $field);

$msg = '';
$erro = '';
$editModalData = null;
$q = trim((string)($_GET['q'] ?? $_POST['return_q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_company') {
  try {
    $companyId = (int)($_POST['company_id'] ?? 0);
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe o nome da empresa.');

    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Informe um e-mail válido.');
    }

    if ($email !== '') {
      $st = $pdo->prepare('SELECT id FROM companies WHERE email = ? AND id <> ? LIMIT 1');
      $st->execute([$email, $companyId]);
      if ($st->fetchColumn()) throw new RuntimeException('Este e-mail já pertence a outra empresa.');
    }

    $cnpj = saas_company_digits((string)($_POST['cnpj'] ?? ''));
    $cpf = saas_company_digits((string)($_POST['cpf'] ?? ''));
    if ($cnpj !== '' && !company_identity_valid_cnpj($cnpj)) throw new RuntimeException('Informe um CNPJ válido.');
    if ($cpf !== '' && !company_identity_valid_cpf($cpf)) throw new RuntimeException('Informe um CPF válido.');

    $subdomain = saas_company_slug_clean((string)($_POST['subdomain'] ?? ''));
    if ($companyColumns['subdomain']) {
      $availability = company_subdomain_availability($pdo, $subdomain, $companyId);
      if (!$availability['available']) throw new RuntimeException($availability['message']);
      $subdomain = (string)$availability['subdomain'];
    }

    $personType = strtolower(trim((string)($_POST['person_type'] ?? 'pj')));
    if (!in_array($personType, ['pj','pf'], true)) $personType = 'pj';
    $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
    if (!in_array($status, ['active','blocked','suspended'], true)) $status = 'active';
    $state = strtoupper(substr(trim((string)($_POST['estado'] ?? '')), 0, 2));
    $whatsapp = saas_company_digits((string)($_POST['whatsapp'] ?? ''));

    $values = [
      'name' => $name,
      'nome_fantasia' => trim((string)($_POST['nome_fantasia'] ?? '')),
      'razao_social' => trim((string)($_POST['razao_social'] ?? '')),
      'person_type' => $personType,
      'cnpj' => $cnpj,
      'cpf' => $cpf,
      'email' => $email,
      'whatsapp' => $whatsapp,
      'celular' => $whatsapp,
      'cep' => saas_company_digits((string)($_POST['cep'] ?? '')),
      'endereco' => trim((string)($_POST['endereco'] ?? '')),
      'numero' => trim((string)($_POST['numero'] ?? '')),
      'complemento' => trim((string)($_POST['complemento'] ?? '')),
      'bairro' => trim((string)($_POST['bairro'] ?? '')),
      'cidade' => trim((string)($_POST['cidade'] ?? '')),
      'estado' => $state,
      'subdomain' => $subdomain,
      'custom_domain' => trim((string)($_POST['custom_domain'] ?? '')),
      'status' => $status,
    ];

    $sets = ['name = ?', 'slug = ?'];
    $params = [$values['name'], $subdomain];
    foreach ($values as $field => $value) {
      if ($field === 'name' || empty($companyColumns[$field])) continue;
      $sets[] = "`{$field}` = ?";
      $params[] = $value !== '' ? $value : null;
    }
    $params[] = $companyId;
    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE companies SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
      $business = config_store_get($pdo, $companyId, 'business') ?: [];
      if (!isset($business['domain']) || !is_array($business['domain'])) $business['domain'] = [];
      $business['domain']['subdomain'] = $subdomain;
      config_store_set($pdo, $companyId, 'business', $business);
      $pdo->commit();
    } catch (Throwable $updateError) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $updateError;
    }

    $redirect = '/saas/empresas.php?updated=1';
    if ($q !== '') $redirect .= '&q=' . rawurlencode($q);
    header('Location: ' . $redirect);
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
    $editModalData = ['id' => (int)($_POST['company_id'] ?? 0), 'created_at' => ''];
    foreach (array_merge(['name'], $companyFields) as $field) {
      $editModalData[$field] = (string)($_POST[$field] ?? '');
    }
  }
}

if (isset($_GET['updated'])) $msg = 'Cadastro da empresa atualizado com sucesso.';

$hasPlanInternal = saas_has_column_local($pdo, 'plans', 'is_internal');
$planInternalSelect = $hasPlanInternal ? ', p.is_internal AS plan_is_internal' : ', 0 AS plan_is_internal';

$where = '';
$params = [];
if ($q !== '') {
  $like = nexa_search_like($q);
  $digits = saas_company_digits($q);
  $parts = ['c.name LIKE ?', 'c.email LIKE ?', 'c.subdomain LIKE ?', 'c.whatsapp LIKE ?', 'c.cnpj LIKE ?', 'c.cpf LIKE ?'];
  $params = [$like, $like, $like, $like, $like, $like];
  if ($companyColumns['nome_fantasia']) { $parts[] = 'c.nome_fantasia LIKE ?'; $params[] = $like; }
  if ($companyColumns['razao_social']) { $parts[] = 'c.razao_social LIKE ?'; $params[] = $like; }
  if ($companyColumns['celular']) { $parts[] = 'c.celular LIKE ?'; $params[] = $like; }
  if ($digits !== '') {
    $digitLike = nexa_search_like($digits);
    $normalize = static fn(string $column): string => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column},''),'.',''),'/',''),'-',''),'(',''),')',''),' ','')";
    foreach (['c.cnpj','c.cpf','c.whatsapp'] as $col) { $parts[] = $normalize($col) . ' LIKE ?'; $params[] = $digitLike; }
    if ($companyColumns['celular']) { $parts[] = $normalize('c.celular') . ' LIKE ?'; $params[] = $digitLike; }
  }
  $where = 'WHERE (' . implode(' OR ', $parts) . ')';
}

$sql = "
  SELECT
    c.*,
    c.status AS company_status,
    s.status AS subscription_status,
    s.started_at,
    s.ends_at,
    s.next_billing_at,
    p.name AS plan_name,
    p.price
    {$planInternalSelect},
    (
      SELECT COUNT(*)
      FROM pedidos pd
      WHERE pd.company_id = c.id
    ) AS total_pedidos
  FROM companies c
  LEFT JOIN (
    SELECT s1.*
    FROM subscriptions s1
    INNER JOIN (
      SELECT company_id, MAX(id) AS max_id
      FROM subscriptions
      GROUP BY company_id
    ) x ON x.max_id = s1.id
  ) s ON s.company_id = c.id
  LEFT JOIN plans p ON p.id = s.plan_id
  {$where}
  ORDER BY c.id DESC
";

$stRows = $pdo->prepare($sql);
$stRows->execute($params);
$rows = $stRows->fetchAll(PDO::FETCH_ASSOC);

saas_layout_start(
  'Empresas • Central SaaS',
  'Empresas',
  'Pesquise, consulte e edite os dados completos das empresas cadastradas.'
);
?>

<style>
  .companies-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
  .company-search{display:flex;gap:10px;align-items:flex-end;flex:1 1 620px;max-width:820px}
  .company-search label{flex:1;margin:0;font-weight:850;color:var(--primary-dark)}
  .company-search .field{margin-top:7px}
  .companies-count{color:var(--muted);font-size:13px;font-weight:900;white-space:nowrap}
  .companies-table{width:100%;min-width:1120px;table-layout:fixed;border-collapse:collapse}
  .companies-table th,.companies-table td{vertical-align:top}
  .companies-table th:nth-child(1),.companies-table td:nth-child(1){width:24%}
  .companies-table th:nth-child(2),.companies-table td:nth-child(2){width:11%}
  .companies-table th:nth-child(3),.companies-table td:nth-child(3){width:15%}
  .companies-table th:nth-child(4),.companies-table td:nth-child(4){width:13%}
  .companies-table th:nth-child(5),.companies-table td:nth-child(5){width:14%}
  .companies-table th:nth-child(6),.companies-table td:nth-child(6){width:9%}
  .companies-table th:nth-child(7),.companies-table td:nth-child(7){width:14%}
  .company-main-row td{border-bottom:0;padding-bottom:8px}
  .company-main-row strong{display:block;line-height:1.25}
  .company-main-row .muted{display:block;margin-top:3px;line-height:1.35;font-size:12px;word-break:break-word;overflow-wrap:anywhere}
  .company-modules{display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start}
  .company-action-row td{padding:0 10px 14px!important;border-bottom:1px solid #eef4fb;background:rgba(248,251,255,.72)}
  .company-action-panel{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:9px 10px}
  .company-action-title{font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em;margin-right:auto}
  .company-action-buttons{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
  .company-empty{padding:22px!important;text-align:center;color:var(--muted);font-weight:800}
  .company-modal[hidden]{display:none}.company-modal{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:18px}.company-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.66);backdrop-filter:blur(4px)}
  .company-modal-card{position:relative;width:min(980px,96vw);max-height:92vh;overflow:auto;background:#fff;border-radius:26px;border:1px solid #dbe7f6;box-shadow:0 30px 90px rgba(2,6,23,.30);padding:22px}
  .company-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:16px}.company-modal-head h2{margin:0;color:var(--primary-dark)}.company-modal-head p{margin:6px 0 0;color:var(--muted)}
  .modal-close{border:1px solid #dbe7f6;background:#fff;border-radius:999px;width:42px;height:42px;font-size:22px;cursor:pointer;color:var(--primary-dark)}
  .company-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.company-form-grid .span-2{grid-column:span 2}.company-form-grid .full{grid-column:1/-1}.company-form-grid label{font-size:13px;font-weight:850;color:#1d356f}.company-form-grid .field{margin-top:6px}
  .modal-section-title{grid-column:1/-1;margin:7px 0 -2px;padding-top:13px;border-top:1px solid #e8eef6;color:var(--primary-dark);font-weight:950;font-size:15px}
  .modal-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:18px}
  body.modal-open{overflow:hidden}
  @media(max-width:760px){
    .company-search{display:grid;grid-template-columns:1fr;width:100%}.company-search .btn2{width:100%}
    .companies-table{min-width:0}.companies-table thead{display:none}.companies-table,.companies-table tbody,.companies-table tr,.companies-table td{display:block;width:100%}
    .company-main-row{border:1px solid #dbe4f0;border-bottom:0;border-radius:18px 18px 0 0;background:#fff;margin-top:10px}
    .company-main-row td{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf2f7;padding:9px 12px;white-space:normal!important;overflow:visible!important;text-align:left!important}
    .company-main-row td::before{content:attr(data-label);flex:0 0 112px;font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em}
    .company-main-row td:first-child,.company-main-row td:nth-child(3),.company-main-row td:nth-child(4),.company-main-row td:nth-child(5),.company-main-row td:nth-child(7){display:block}
    .company-main-row td:first-child::before,.company-main-row td:nth-child(3)::before,.company-main-row td:nth-child(4)::before,.company-main-row td:nth-child(5)::before,.company-main-row td:nth-child(7)::before{display:block;margin-bottom:5px}
    .company-action-row{display:block;border:1px solid #dbe4f0;border-top:0;border-radius:0 0 18px 18px;background:#fff;margin-bottom:10px}.company-action-row td{display:block!important;border:0!important;padding:0 10px 10px!important;background:#fff!important}
    .company-action-panel{justify-content:flex-start;border-radius:14px}.company-action-title{flex:1 0 100%;margin:0}.company-action-buttons{width:100%}.company-action-buttons .mini{flex:1 1 100%;max-width:none}
    .company-form-grid{grid-template-columns:1fr}.company-form-grid .span-2,.company-form-grid .full{grid-column:1}.company-modal-card{padding:17px;border-radius:22px}.modal-actions .btn2{width:100%}
  }
</style>

<div class="top-actions">
  <a class="btn2 btn2-primary" href="/cadastro.php">+ Nova empresa</a>
  <a class="btn2 btn2-ghost" href="/saas/whatsapp.php">WhatsApp</a>
  <a class="btn2 btn2-ghost" href="/saas/dashboard.php">Dashboard</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="panel">
  <div class="companies-toolbar">
    <form class="company-search" method="get" action="/saas/empresas.php">
      <label>Pesquisar empresa
        <input class="field" type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, razão social, CNPJ, telefone, e-mail ou subdomínio" autocomplete="off">
      </label>
      <button class="btn2 btn2-dark" type="submit">Pesquisar</button>
      <?php if ($q !== ''): ?><a class="btn2 btn2-ghost" href="/saas/empresas.php">Limpar</a><?php endif; ?>
    </form>
    <span class="companies-count"><?= count($rows) ?> empresa(s)<?= $q !== '' ? ' encontrada(s)' : '' ?></span>
  </div>
  <div class="table-wrap">
    <table class="companies-table">
      <thead><tr><th>Empresa</th><th>Plano</th><th>Módulos</th><th>Assinatura</th><th>Vencimento</th><th>Cadastro</th><th>Subdomínio</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="company-empty">Nenhuma empresa encontrada para a pesquisa informada.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $catalogUrl = 'https://' . (string)$r['subdomain'] . '.nexaprospero.com.br';
            $adminUrl = $catalogUrl . '/admin';
            $doc = trim((string)($r['cnpj'] ?: ($r['cpf'] ?? '')));
            $phone = trim((string)($r['whatsapp'] ?: ($r['celular'] ?? '')));
            $modalData = [];
            foreach (array_merge(['id','name','created_at'], $companyFields) as $field) $modalData[$field] = $r[$field] ?? '';
          ?>
          <tr class="company-main-row">
            <td data-label="Empresa"><strong><?= e((string)$r['name']) ?></strong><span class="muted"><?= e((string)($r['email'] ?? '')) ?></span><?php if ($doc !== ''): ?><span class="muted">Documento: <?= e($doc) ?></span><?php endif; ?><?php if ($phone !== ''): ?><span class="muted">Telefone: <?= e($phone) ?></span><?php endif; ?><span class="muted">Status: <?= e((string)$r['company_status']) ?></span></td>
            <td data-label="Plano"><strong><?= e((string)($r['plan_name'] ?? '-')) ?></strong><?php if (!empty($r['plan_is_internal'])): ?><span class="pill" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74;margin-top:4px;">Interno</span><?php endif; ?><span class="muted"><?= money_br((float)($r['price'] ?? 0)) ?></span></td>
            <td data-label="Módulos"><div class="company-modules"><?php $codes = saas_company_module_codes($pdo, (int)$r['id']); ?><?php if ($codes): ?><?php foreach ($codes as $code): ?><span class="pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;"><?= e(saas_module_label($code)) ?></span><?php endforeach; ?><?php else: ?><span class="muted">Nenhum</span><?php endif; ?></div></td>
            <td data-label="Assinatura"><span class="pill" style="<?= badge_color($r['subscription_status'] ?? null) ?>"><?= e((string)($r['subscription_status'] ?? '-')) ?></span><span class="muted">Início: <?= e(saas_date_br($r['started_at'] ?? null, '-')) ?></span></td>
            <td data-label="Vencimento"><strong><?= e(saas_date_br($r['ends_at'] ?? null, '-')) ?></strong><span class="muted">Próx. cobrança: <?= e(saas_date_br($r['next_billing_at'] ?? null, '-')) ?></span></td>
            <td data-label="Cadastro"><strong><?= e(saas_date_br($r['created_at'] ?? null, '-')) ?></strong><span class="muted"><?= (int)($r['total_pedidos'] ?? 0) ?> pedido(s)</span></td>
            <td data-label="Subdomínio"><strong><?= e((string)$r['subdomain']) ?></strong><span class="muted"><?= e((string)$r['subdomain']) ?>.nexaprospero.com.br</span></td>
          </tr>
          <tr class="company-action-row"><td colspan="7"><div class="company-action-panel"><span class="company-action-title">Ações da empresa</span><div class="company-action-buttons"><button class="mini mini-primary js-edit-company" type="button" data-company="<?= e((string)json_encode($modalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Editar cadastro</button><a class="mini" href="/saas/empresa.php?id=<?= (int)$r['id'] ?>">Gerenciar</a><a class="mini" href="<?= e($catalogUrl) ?>" target="_blank" rel="noopener">Catálogo</a><a class="mini" href="<?= e($adminUrl) ?>" target="_blank" rel="noopener">Admin</a></div></div></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="company-modal" id="companyModal" hidden aria-hidden="true">
  <div class="company-modal-backdrop" data-close-modal></div>
  <div class="company-modal-card" role="dialog" aria-modal="true" aria-labelledby="companyModalTitle">
    <div class="company-modal-head"><div><h2 id="companyModalTitle">Editar cadastro da empresa</h2><p id="companyModalSubtitle">Atualize os dados cadastrais e de contato.</p></div><button class="modal-close" type="button" data-close-modal aria-label="Fechar">×</button></div>
    <form method="post" id="companyEditForm">
      <input type="hidden" name="action" value="update_company"><input type="hidden" name="company_id" id="edit_company_id"><input type="hidden" name="return_q" value="<?= e($q) ?>">
      <div class="company-form-grid">
        <div class="modal-section-title">Identificação</div>
        <label class="span-2">Nome exibido<input class="field" name="name" id="edit_name" required></label>
        <label>Tipo de pessoa<select class="field" name="person_type" id="edit_person_type"><option value="pj">Pessoa jurídica</option><option value="pf">Pessoa física</option></select></label>
        <label>Nome fantasia<input class="field" name="nome_fantasia" id="edit_nome_fantasia"></label>
        <label class="span-2">Razão social<input class="field" name="razao_social" id="edit_razao_social"></label>
        <label>CNPJ<input class="field" name="cnpj" id="edit_cnpj" inputmode="numeric"></label>
        <label>CPF<input class="field" name="cpf" id="edit_cpf" inputmode="numeric"></label>
        <label>Status<select class="field" name="status" id="edit_status"><option value="active">Ativa</option><option value="blocked">Bloqueada</option><option value="suspended">Suspensa</option></select></label>
        <div class="modal-section-title">Contato e acesso</div>
        <label class="span-2">E-mail<input class="field" type="email" name="email" id="edit_email"></label>
        <label>Telefone / WhatsApp<input class="field" name="whatsapp" id="edit_whatsapp" inputmode="tel"></label>
        <label>Subdomínio<input class="field" name="subdomain" id="edit_subdomain" required><small class="muted">Acentos, ç e caracteres especiais serão removidos automaticamente.</small></label>
        <label class="span-2">Domínio personalizado<input class="field" name="custom_domain" id="edit_custom_domain" placeholder="cliente.com.br"></label>
        <div class="modal-section-title">Endereço</div>
        <label>CEP<input class="field" name="cep" id="edit_cep" inputmode="numeric"></label>
        <label class="span-2">Endereço<input class="field" name="endereco" id="edit_endereco"></label>
        <label>Número<input class="field" name="numero" id="edit_numero"></label>
        <label class="span-2">Complemento<input class="field" name="complemento" id="edit_complemento"></label>
        <label>Bairro<input class="field" name="bairro" id="edit_bairro"></label>
        <label>Cidade<input class="field" name="cidade" id="edit_cidade"></label>
        <label>UF<input class="field" name="estado" id="edit_estado" maxlength="2"></label>
      </div>
      <div class="modal-actions"><button class="btn2 btn2-ghost" type="button" data-close-modal>Cancelar</button><button class="btn2 btn2-dark" type="submit">Salvar alterações</button></div>
    </form>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('companyModal');
  const buttons = document.querySelectorAll('.js-edit-company');
  const fields = ['name','nome_fantasia','razao_social','person_type','cnpj','cpf','email','whatsapp','cep','endereco','numero','complemento','bairro','cidade','estado','subdomain','custom_domain','status'];
  const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); };
  const open = (data) => {
    const companyId = document.getElementById('edit_company_id');
    if (companyId) companyId.value = data.id ?? data.company_id ?? '';
    fields.forEach((field) => { const input = document.getElementById('edit_' + field); if (input) input.value = data[field] ?? ''; });
    document.getElementById('companyModalSubtitle').textContent = (data.name || 'Empresa') + ' • cadastrada em ' + formatDate(data.created_at);
    modal.hidden = false; modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
    window.setTimeout(() => document.getElementById('edit_name')?.focus(), 40);
  };
  const formatDate = (raw) => { if (!raw) return 'data não informada'; const part = String(raw).slice(0,10).split('-'); return part.length === 3 ? `${part[2]}-${part[1]}-${part[0]}` : raw; };
  buttons.forEach((button) => button.addEventListener('click', () => { try { open(JSON.parse(button.dataset.company || '{}')); } catch (error) { alert('Não foi possível abrir os dados desta empresa.'); } }));
  modal.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', close));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
  const editAfterError = <?= $editModalData !== null ? json_encode($editModalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null' ?>;
  if (editAfterError) open(editAfterError);
})();
</script>

<?php saas_layout_end(); ?>
