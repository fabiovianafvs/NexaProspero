<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';

$user = require_support_user($pdo);

function support_has_company_column(PDO $pdo, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \'companies\' AND column_name = ? LIMIT 1');
    $st->execute([$column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function support_select_column(array $columns, string $column, string $alias): string {
  return !empty($columns[$column]) ? "c.`{$column}` AS `{$alias}`" : "NULL AS `{$alias}`";
}

$wanted = ['nome_fantasia','razao_social','cnpj','cpf','email','whatsapp','celular','cep','endereco','numero','complemento','bairro','cidade','estado','subdomain','custom_domain','status','created_at'];
$columns = [];
foreach ($wanted as $column) $columns[$column] = support_has_company_column($pdo, $column);

$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
$erro = '';
$searched = false;

if ($q !== '') {
  $searched = true;
  $digits = support_digits($q);
  if (strlen($q) < 2 && strlen($digits) < 2) {
    $erro = 'Digite pelo menos 2 caracteres para pesquisar.';
  } else {
    $select = [
      'c.id', 'c.name', 'c.slug',
      support_select_column($columns, 'nome_fantasia', 'nome_fantasia'),
      support_select_column($columns, 'razao_social', 'razao_social'),
      support_select_column($columns, 'cnpj', 'cnpj'),
      support_select_column($columns, 'cpf', 'cpf'),
      support_select_column($columns, 'email', 'email'),
      support_select_column($columns, 'whatsapp', 'whatsapp'),
      support_select_column($columns, 'celular', 'celular'),
      support_select_column($columns, 'cep', 'cep'),
      support_select_column($columns, 'endereco', 'endereco'),
      support_select_column($columns, 'numero', 'numero'),
      support_select_column($columns, 'complemento', 'complemento'),
      support_select_column($columns, 'bairro', 'bairro'),
      support_select_column($columns, 'cidade', 'cidade'),
      support_select_column($columns, 'estado', 'estado'),
      support_select_column($columns, 'subdomain', 'subdomain'),
      support_select_column($columns, 'custom_domain', 'custom_domain'),
      support_select_column($columns, 'status', 'status'),
      support_select_column($columns, 'created_at', 'created_at'),
    ];

    $parts = ['c.name LIKE ?', 'c.slug LIKE ?'];
    $params = [nexa_search_like($q), nexa_search_like($q)];
    foreach (['nome_fantasia','razao_social','subdomain','custom_domain'] as $column) {
      if (!empty($columns[$column])) {
        $parts[] = "c.`{$column}` LIKE ?";
        $params[] = nexa_search_like($q);
      }
    }
    if ($digits !== '' && strlen($digits) <= 9) {
      // Código da empresa.
      $parts[] = 'c.id = ?';
      $params[] = (int)$digits;
    }
    if ($digits !== '' && !empty($columns['whatsapp'])) {
      $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.whatsapp,''),' ',''),'-',''),'(',''),')',''),'+','') LIKE ?";
      $params[] = nexa_search_like($digits);
    }
    if ($digits !== '' && !empty($columns['cnpj'])) {
      $normalized = "REPLACE(REPLACE(REPLACE(COALESCE(c.cnpj,''),'.',''),'/',''),'-','')";
      $parts[] = $normalized . ' LIKE ?';
      $params[] = nexa_search_like($digits);
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM companies c WHERE (' . implode(' OR ', $parts) . ') ORDER BY c.name ASC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

function support_company_base_url(array $row): string {
  $sub = trim((string)($row['subdomain'] ?? '')) ?: trim((string)($row['slug'] ?? ''));
  $sub = preg_replace('/[^a-z0-9-]/', '', strtolower($sub)) ?? '';
  return $sub !== '' ? 'https://' . $sub . '.nexaprospero.com.br' : '';
}

function support_wa_digits(string $phone): string {
  $d = preg_replace('/\D+/', '', $phone) ?? '';
  if ($d === '') return '';
  if (strlen($d) <= 11) $d = '55' . $d;
  return $d;
}

function support_company_domain(array $row): string {
  $sub = trim((string)($row['subdomain'] ?? $row['slug'] ?? ''));
  return $sub !== '' ? $sub . '.nexaprospero.com.br' : '—';
}

function support_status_label(string $status): string {
  return match (strtolower(trim($status))) {
    'active' => 'Ativa',
    'blocked' => 'Bloqueada',
    'suspended' => 'Suspensa',
    default => $status !== '' ? ucfirst($status) : '—',
  };
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de Empresas - Nexa Próspero</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0b1f5b;--muted:#5b6475;--line:#dce7f5;--primary:#2454d6;--dark:#0b1f5b;--accent:#00b8ff;--danger:#b91c1c;--success:#15803d;--shadow:0 18px 44px rgba(11,31,91,.09)}*{box-sizing:border-box}html,body{margin:0;padding:0;background:radial-gradient(circle at 12% 10%,rgba(0,184,255,.10),transparent 28%),var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif}a{color:inherit}.shell{max-width:1240px;margin:0 auto;padding:22px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;border-radius:24px;background:linear-gradient(135deg,#0b1f5b,#2454d6 70%,#00b8ff 140%);color:#fff;box-shadow:0 20px 50px rgba(11,31,91,.18)}.brand{display:flex;align-items:center;gap:12px}.brand img{width:52px;height:52px;object-fit:contain;background:#fff;padding:5px;border-radius:15px}.brand strong{display:block;font-size:20px}.brand span{display:block;color:#dff6ff;font-size:12px;font-weight:700;margin-top:3px}.userbox{text-align:right;font-size:13px;color:#dff6ff}.userbox strong{display:block;color:#fff;font-size:14px}.logout{display:inline-flex;margin-top:6px;color:#fff;font-weight:900}.portal-nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.portal-nav a{color:#eaf8ff;text-decoration:none;font-weight:850;padding:7px 10px;border-radius:999px}.portal-nav a.active,.portal-nav a:hover{background:rgba(255,255,255,.16);color:#fff}.hero{padding:28px 4px 16px}.hero h1{font-size:34px;margin:0 0 8px;letter-spacing:-.04em}.hero p{margin:0;color:var(--muted);line-height:1.55}.security{margin:0 0 16px;padding:13px 15px;border:1px solid #bae6fd;background:#effaff;color:#075985;border-radius:16px;font-weight:700}.search-card,.company-card{background:var(--card);border:1px solid rgba(36,84,214,.12);border-radius:22px;box-shadow:var(--shadow)}.search-card{padding:18px;margin-bottom:18px}.search-form{display:flex;gap:10px;align-items:end}.search-form label{flex:1;font-weight:900}.search-form input{display:block;width:100%;height:48px;margin-top:7px;border:1px solid var(--line);border-radius:14px;padding:0 14px;font-size:16px;outline:none}.search-form input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(0,184,255,.13)}.btn{height:48px;border:0;border-radius:999px;padding:0 22px;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;font-weight:900;cursor:pointer}.result-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:18px 2px 10px;color:var(--muted);font-weight:800}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.company-card{padding:18px}.company-top{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;padding-bottom:14px;border-bottom:1px solid var(--line)}.company-top h2{margin:0;font-size:21px}.fantasia{margin-top:4px;color:var(--muted);font-size:13px}.status{white-space:nowrap;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:900;background:#eef4ff;color:var(--primary)}.status.active{background:#ecfdf5;color:#166534}.status.blocked,.status.suspended{background:#fef2f2;color:#991b1b}.data-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.item small{display:block;color:var(--muted);font-weight:800;margin-bottom:3px}.item strong,.item span{word-break:break-word}.span2{grid-column:1/-1}.domain{color:var(--primary);font-weight:900}.empty{padding:30px;text-align:center;background:#fff;border:1px dashed #bcd0eb;border-radius:20px;color:var(--muted)}.error{margin-bottom:14px;padding:12px 14px;border-radius:14px;background:#fef2f2;color:var(--danger);border:1px solid #fecaca;font-weight:800}@media(max-width:820px){.grid{grid-template-columns:1fr}.search-form{align-items:stretch;flex-direction:column}.btn{width:100%}.data-grid{grid-template-columns:1fr}.span2{grid-column:auto}.topbar{align-items:flex-start}.hero h1{font-size:29px}}@media(max-width:520px){.shell{padding:12px}.topbar{border-radius:18px}.brand img{width:44px;height:44px}.userbox strong{max-width:120px;overflow:hidden;text-overflow:ellipsis}.company-top{flex-direction:column}.status{align-self:flex-start}}
.sg-wrap{overflow-x:auto;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}
.sg-table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0;font-size:13px}
.sg-table thead th{position:sticky;top:0;background:#f7faff;color:#243b70;font-size:11px;text-transform:uppercase;letter-spacing:.03em;font-weight:900;text-align:left;padding:10px;border-bottom:1px solid var(--line)}
.sg-table td{padding:9px 10px;border-bottom:1px solid #eef3fa;vertical-align:middle}
.sg-table tbody tr:nth-child(even) td{background:#f6f9fe}.sg-table tbody tr:hover td{background:#e8f0fd}
.sg-code{font-weight:900;color:var(--primary)}.sg-name{display:block;color:var(--dark)}.sg-sub{display:block;color:var(--muted);font-size:11px;margin-top:2px;line-height:1.3}
.sg-nowrap{white-space:nowrap}.sg-wa{color:#15803d;font-weight:800;text-decoration:none}.sg-tel{margin-left:8px;font-size:11px;color:var(--primary);font-weight:800}
.sg-actions{display:flex;gap:6px;flex-wrap:wrap}.sg-btn{display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #cfdbf0;border-radius:9px;background:#fff;color:var(--dark);font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap}.sg-btn:hover{background:#eef4ff}.sg-btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
</style>
</head>
<body>
<div class="shell">
  <header class="topbar">
    <div class="brand"><img src="/assets/logo-nexa-v6-2.png" alt="Nexa Próspero"><div><strong>Portal do Suporte</strong><span>Nexa Próspero</span><nav class="portal-nav"><a class="active" href="/suporte/index.php">Empresas</a><a href="/suporte/monitor_fiscal.php">Monitor Fiscal</a><a href="/suporte/whatsapp.php">WhatsApp</a><a href="/suporte/monitor_saude.php">Saúde do Sistema</a><a href="/suporte/armazenamento.php">Armazenamento</a></nav></div></div>
    <div class="userbox"><strong><?= e((string)$user['name']) ?></strong><?= e((string)$user['email']) ?><a class="logout" href="/suporte/logout.php">Sair</a></div>
  </header>
  <section class="hero"><h1>Localizar empresa</h1><p>Pesquise pelo código, nome, razão/nome fantasia, domínio/subdomínio, CNPJ ou WhatsApp.</p></section>
  <div class="security">Consulta cadastral e ferramentas operacionais controladas do suporte. Credenciais, cobrança, planos e demais áreas administrativas permanecem protegidos.</div>
  <section class="search-card">
    <?php if ($erro !== ''): ?><div class="error"><?= e($erro) ?></div><?php endif; ?>
    <form method="get" class="search-form">
      <label for="q">Código, empresa, domínio, CNPJ ou WhatsApp<input id="q" name="q" value="<?= e($q) ?>" placeholder="Ex.: Minha Empresa, minhaempresa ou 12.345.678/0001-90" autocomplete="off" autofocus></label>
      <button class="btn" type="submit">Pesquisar</button>
    </form>
  </section>

  <?php if ($searched && $erro === ''): ?>
    <div class="result-head"><span><?= count($rows) ?> resultado(s)</span><?php if (count($rows) >= 200): ?><span>Mostrando os 200 primeiros</span><?php endif; ?></div>
    <?php if (!$rows): ?>
      <div class="empty">Nenhuma empresa encontrada para <strong><?= e($q) ?></strong>.</div>
    <?php else: ?>
      <div class="sg-wrap"><table class="sg-table">
        <thead><tr><th style="width:70px">Código</th><th>Razão social / endereço</th><th style="width:200px">Fantasia</th><th style="width:150px">CNPJ</th><th style="width:160px">WhatsApp</th><th style="width:96px">Situação</th><th style="width:300px">Acessos</th></tr></thead>
        <tbody>
      <?php foreach ($rows as $row):
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $contact = trim((string)($row['whatsapp'] ?: ($row['celular'] ?? '')));
        $waDigits = support_wa_digits($contact);
        $base = support_company_base_url($row);
        $razao = trim((string)($row['razao_social'] ?? '')) ?: (string)$row['name'];
        $fantasia = trim((string)($row['nome_fantasia'] ?? '')) ?: (string)$row['name'];
        $addressParts = array_filter([
          trim((string)($row['endereco'] ?? '')) . (trim((string)($row['numero'] ?? '')) !== '' ? ', ' . trim((string)$row['numero']) : ''),
          trim((string)($row['complemento'] ?? '')),
          trim((string)($row['bairro'] ?? '')),
          trim((string)($row['cidade'] ?? '')) . (trim((string)($row['estado'] ?? '')) !== '' ? '/' . trim((string)$row['estado']) : ''),
          trim((string)($row['cep'] ?? '')) !== '' ? 'CEP ' . trim((string)$row['cep']) : '',
        ], static fn($v) => trim((string)$v) !== '');
      ?>
          <tr>
            <td class="sg-code"><?= (int)$row['id'] ?></td>
            <td><strong class="sg-name"><?= e($razao) ?></strong><small class="sg-sub"><?= e($addressParts ? implode(' • ', $addressParts) : 'Endereço não informado') ?></small><?php if (trim((string)($row['email'] ?? '')) !== ''): ?><small class="sg-sub"><?= e((string)$row['email']) ?></small><?php endif; ?></td>
            <td><?= e($fantasia) ?><small class="sg-sub"><?= e(support_company_domain($row)) ?></small></td>
            <td class="sg-nowrap"><?= e(support_cnpj_format((string)($row['cnpj'] ?? ''))) ?></td>
            <td class="sg-nowrap"><?php if ($waDigits !== ''): ?><a class="sg-wa" href="https://wa.me/<?= e($waDigits) ?>" target="_blank" rel="noopener" title="Abrir conversa no WhatsApp"><?= e($contact) ?></a><a class="sg-tel" href="tel:+<?= e($waDigits) ?>" title="Ligar">Ligar</a><?php else: ?>—<?php endif; ?></td>
            <td><span class="status <?= e($status) ?>"><?= e(support_status_label($status)) ?></span></td>
            <td><?php if ($base !== ''): ?><div class="sg-actions">
              <a class="sg-btn primary" href="<?= e($base) ?>/admin/login.php" target="_blank" rel="noopener" title="Abrir o painel administrativo da empresa">Admin</a>
              <a class="sg-btn" href="<?= e($base) ?>/" target="_blank" rel="noopener" title="Catálogo de pedidos (loja)">Catálogo de pedidos</a>
              <a class="sg-btn" href="<?= e($base) ?>/catalogo_publico.php" target="_blank" rel="noopener" title="Catálogo somente para visualização">Catálogo</a>
            </div><?php else: ?>—<?php endif; ?></td>
          </tr>
      <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  <?php elseif (!$searched): ?>
    <div class="empty">Digite um nome, domínio ou CNPJ para iniciar a consulta.</div>
  <?php endif; ?>
</div>
</body>
</html>
