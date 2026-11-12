<?php
declare(strict_types=1);

// WhatsApp > Chatbot: liga/desliga o robô, monta o fluxo (menus e submenus,
// com ordem), cadastra os setores de atendimento e as mensagens padrão.
// Sempre da empresa de login (sem F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_chatbot.php';
require_once __DIR__ . '/../config/person_contacts.php';
require_once __DIR__ . '/../config/whatsapp_atendimento.php';

$cid = (int)COMPANY_ID;
// Abas da tela: cada uma mostra a sua lista, com os botões à direita.
$abas = [
  'menu' => 'Fluxo de atendimento',
  'fluxos' => 'Fluxos (campanhas, anúncios e roteiros)',
  'setores' => 'Setores de atendimento',
  'contatos' => 'Contatos',
  'rapidas' => 'Mensagens rápidas',
  'robo' => 'Robô e mensagens padrão',
  'horario' => 'Horário de atendimento',
  'crm' => 'CRM',
];
$aba = (string)($_GET['aba'] ?? 'menu');
if (!isset($abas[$aba])) $aba = 'menu';
$msg = isset($_GET['ok']) ? (string)$_GET['ok'] : '';
$err = '';
function wab_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_wab_csrf'])) $_SESSION['nexa_wab_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_wab_csrf'] ?? '');
$ready = whatsapp_bot_ready($pdo);
$contactsReady = person_contacts_ready($pdo);

// Busca de cliente (modal de contato): só a empresa de login.
if (($_GET['ajax'] ?? '') === 'pessoas') {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
  $items = [];
  if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';
    $st = $pdo->prepare('SELECT id, name, fantasy_name, document FROM finance_people WHERE company_id = ? AND (name LIKE ? OR fantasy_name LIKE ? OR document LIKE ?) ORDER BY name, id LIMIT 20');
    $st->execute([$cid, $like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $items[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'extra' => trim((string)($r['fantasy_name'] ?? '') . ((string)($r['document'] ?? '') !== '' ? ' · ' . $r['document'] : ''), ' ·')];
    }
  }
  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
  exit;
}

$waCfg = whatsapp_load_config($pdo, $cid, false);
$engine = whatsapp_engine_config($pdo);
$onEngine = whatsapp_engine_is_url($engine, (string)($waCfg['service_url'] ?? ''));

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  // Ao gravar, volta para a lista da aba em que a pessoa estava.
  $abaOf = ['save' => 'robo', 'node_save' => 'menu', 'node_delete' => 'menu', 'node_up' => 'menu', 'node_down' => 'menu', 'seed' => 'menu',
    'dep_save' => 'setores', 'dep_delete' => 'setores', 'flow_delete' => 'fluxos', 'contact_save' => 'contatos', 'contact_delete' => 'contatos',
    'quick_save' => 'rapidas', 'quick_delete' => 'rapidas', 'quick_up' => 'rapidas', 'quick_down' => 'rapidas'];
  $action = (string)($_POST['action'] ?? '');
  $aba = $abaOf[$action] ?? $aba;
  if ($action === 'save' && ($_POST['section'] ?? '') === 'crm') $aba = 'crm';
  if ($action === 'save' && ($_POST['section'] ?? '') === 'horario') $aba = 'horario';
  $go = static function (string $ok) use (&$aba) { header('Location: whatsapp_chatbot.php?aba=' . rawurlencode($aba) . '&ok=' . rawurlencode($ok)); exit; };
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    switch ($action) {
      case 'save':
        $res = whatsapp_bot_save_config($pdo, $cid, $_POST);
        $on = whatsapp_bot_config($pdo, $cid)['enabled'];
        if (!$res['ok']) throw new RuntimeException('Configuração salva, mas o WhatsApp não confirmou: ' . $res['message']);
        if (($_POST['section'] ?? '') === 'crm') $go('Configuração do CRM salva.');
        if (($_POST['section'] ?? '') === 'horario') $go('Horário de atendimento salvo.');
        $go(($on ? 'Configuração salva. O robô está LIGADO.' : 'Configuração salva. O robô está desligado: as conversas novas vão direto para a fila do Atendimento.') . ($res['message'] !== '' ? ' ' . $res['message'] : ''));
      case 'node_save':
        // O campo do modal chama "node_action" para não se misturar com a ação do formulário.
        $node = $_POST + ['active' => 0];
        $node['action'] = (string)($_POST['node_action'] ?? 'text');
        whatsapp_bot_node_save($pdo, $cid, $node);
        $go('Opção salva.');
      case 'node_delete':
        whatsapp_bot_node_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Opção excluída.');
      case 'node_up':
      case 'node_down':
        whatsapp_bot_node_move($pdo, $cid, (int)($_POST['id'] ?? 0), $action === 'node_up' ? -1 : 1);
        $go('Ordem alterada.');
      case 'seed':
        whatsapp_bot_seed_default_flow($pdo, $cid);
        $go('Menu padrão criado. Ajuste os textos e a ordem como preferir.');
      case 'dep_save':
        whatsapp_department_save($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), !empty($_POST['active']), (array)($_POST['users'] ?? []));
        $go('Setor salvo.');
      case 'flow_delete':
        whatsapp_bot_flow_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Fluxo excluído.');
      case 'dep_delete':
        whatsapp_department_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Setor excluído.');
      case 'contact_save':
        person_contact_save($pdo, $cid, array_intersect_key($_POST, array_flip(['id', 'person_id', 'name', 'role', 'whatsapp', 'phone', 'email', 'notes', 'tags', 'is_primary', 'active'])) + ['active' => 0]);
        $go('Contato salvo.');
      case 'quick_save':
        whatsapp_quick_reply_save($pdo, $cid, $_POST + ['active' => 0]);
        $go('Mensagem rápida salva.');
      case 'quick_delete':
        whatsapp_quick_reply_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Mensagem rápida excluída.');
      case 'quick_up':
      case 'quick_down':
        whatsapp_quick_reply_move($pdo, $cid, (int)($_POST['id'] ?? 0), $action === 'quick_up' ? -1 : 1);
        $go('Ordem alterada.');
      case 'contact_delete':
        if (!person_contact_get($pdo, $cid, (int)($_POST['id'] ?? 0))) throw new RuntimeException('Contato não encontrado.');
        person_contact_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Contato excluído.');
    }
    throw new RuntimeException('Ação inválida.');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cfg = whatsapp_bot_config($pdo, $cid);
$users = $statuses = $types = $tree = $departments = $members = [];
try { $st = $pdo->prepare('SELECT id, name FROM users WHERE ' . (function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)') . ' AND is_active=1 ORDER BY name, id'); $st->execute((function_exists('access_company_users_params') ? access_company_users_params($pdo, $cid) : [$cid])); $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
try { $st = $pdo->prepare('SELECT id, name FROM crm_statuses WHERE company_id=? AND active=1 ORDER BY sort_order, name, id'); $st->execute([$cid]); $statuses = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
try { $st = $pdo->prepare('SELECT id, name FROM crm_service_types WHERE company_id=? AND active=1 ORDER BY sort_order, name, id'); $st->execute([$cid]); $types = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
if ($ready) {
  $tree = whatsapp_bot_node_tree($pdo, $cid);
  $departments = whatsapp_departments($pdo, $cid, false);
  $members = whatsapp_department_members($pdo, $cid);
}
$actions = whatsapp_bot_node_actions();
$flowsReady = $ready && whatsapp_bot_flows_ready($pdo);
$flows = $flowsReady ? whatsapp_bot_flows($pdo, $cid) : [];
$flowNames = [];
foreach ($flows as $f) $flowNames[(int)$f['id']] = (string)$f['name'];
if (!$flowsReady) unset($actions['fluxo']);
$depNames = [];
foreach ($departments as $d) $depNames[(int)$d['id']] = (string)$d['name'];
$submenus = array_values(array_filter($tree, static fn($n) => $n['action'] === 'submenu'));
$userNames = [];
foreach ($users as $u) $userNames[(int)$u['id']] = (string)$u['name'];
$quickReplies = ($ready && $aba === 'rapidas') ? whatsapp_quick_replies($pdo, $cid) : [];
$contactQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$contacts = ($contactsReady && $aba === 'contatos') ? person_contacts_search($pdo, $cid, $contactQ, 300) : [];
$contactTags = $contactsReady && person_contacts_has_tags($pdo);
$allTags = ($contactTags && $aba === 'contatos') ? person_contact_all_tags($pdo, $cid) : [];

// Simulação do menu inicial.
$preview = '';
if ($ready) {
  $preview = strtr($cfg['welcome'], ['{nome}' => ', Maria', '{empresa}' => whatsapp_bot_company_name($pdo, $cid)]);
  $menu = whatsapp_bot_menu_text($pdo, $cid, null);
  $preview .= $menu !== '' ? "\n\n" . $menu : "\n\n(sem opções: o cliente vai direto para a fila)";
}

admin_topbar('Chatbot do WhatsApp', 'whatsapp_chatbot');
?>
<style>
.wab-wrap{max-width:1180px;margin:14px auto 0;display:grid;gap:14px}.wab-card{background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.wab-card h2{margin:0 0 6px;color:#0b1f5b;font-size:20px}.wab-muted{color:#64748b;line-height:1.5;font-size:13px}
.wab-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.wab-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:4px}.wab-field input[type=text],.wab-field input[type=number],.wab-field select,.wab-field textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.wab-field textarea{min-height:76px}
.wab-switch{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;border:1px solid #dbe4f0;background:#f8fbff;font-weight:800;color:#0b1f5b}.wab-switch input{width:20px;height:20px}
.wab-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.wab-head>div:first-child{flex:1;min-width:0}.wab-head>.btn,.wab-head>.wab-btns{flex-shrink:0}.wab-modal .wab-field{margin-bottom:10px}
.wab-table{width:100%;border-collapse:collapse;font-size:13px}.wab-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0}.wab-table td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.wab-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-weight:800;font-size:11px;background:#eef2ff;color:#3730a3}.wab-chip.off{background:#e2e8f0;color:#64748b}
.wab-num{font-weight:800;color:#0b1f5b;white-space:nowrap}.wab-row-actions{display:flex;gap:4px;flex-wrap:wrap}.wab-row-actions form{margin:0}.wab-mini{padding:4px 9px!important;font-size:12px!important}
.wab-phone{background:#efeae2;border-radius:16px;padding:14px;max-width:360px}.wab-phone pre{background:#fff;border-radius:10px;padding:10px;white-space:pre-wrap;font:14px/1.45 system-ui,sans-serif;margin:0;box-shadow:0 1px .5px rgba(0,0,0,.15)}
.wab-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}.wab-modal.on{display:flex}.wab-modal .wab-card{width:100%;max-width:560px;max-height:92vh;overflow:auto}
.wab-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px}.wab-tabs a{padding:8px 14px;border-radius:999px;border:1px solid #dbe4f0;background:#f8fbff;color:#0b1f5b;font-weight:700;font-size:13px;text-decoration:none}.wab-tabs a.on{background:#2454d6;border-color:#2454d6;color:#fff}
.wab-btns{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}.wab-modal-foot{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.wab-search{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.wab-search input{flex:1;min-width:220px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}
.wab-res{display:grid;gap:2px;max-height:200px;overflow:auto;margin-top:4px}.wab-res button{text-align:left;border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:6px 8px;cursor:pointer;font:inherit;font-size:13px}.wab-res button:hover{background:#eff6ff}.wab-res span{color:#64748b}
.wab-sel{align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px;font-weight:700;color:#0b1f5b}
.wab-field input[type=text],.wab-field input[type=email]{width:100%;box-sizing:border-box}
.wab-users{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px;padding:8px}.wab-users label{display:flex;gap:6px;align-items:center;font-size:13px;font-weight:500}
</style>
<div class="wab-wrap">
<?php if ($msg !== ''): ?><div class="msg ok"><?= wab_h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= wab_h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="msg err">O chatbot ainda não foi instalado. Peça ao administrador da plataforma para aplicar "WhatsApp - Chatbot" e "WhatsApp - Atendimento" no Atualizador do Banco.</div><?php endif; ?>
<?php if ($ready && !$onEngine): ?><div class="msg err">O WhatsApp desta empresa ainda não está no motor novo; o chatbot e o atendimento só funcionam nele. Peça ao suporte para fazer a troca.</div><?php endif; ?>

<?php if ($ready): ?>
<section class="wab-card wab-top">
  <div class="wab-head">
    <div><h2>Chatbot e atendimento</h2><div class="wab-muted">1) Monte o <b>fluxo</b> (o que o robô oferece). 2) Cadastre os <b>setores</b> e quem atende em cada um. 3) Ligue o robô. As conversas transferidas aparecem na <b>tela de atendimento</b>.</div></div>
    <a class="btn btn-primary" href="whatsapp_atendimento.php">Abrir tela de atendimento</a>
  </div>
  <nav class="wab-tabs">
    <?php foreach ($abas as $k => $lbl): ?><a href="whatsapp_chatbot.php?aba=<?= wab_h($k) ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= wab_h($lbl) ?></a><?php endforeach; ?>
  </nav>
</section>

<?php if ($aba === 'menu'): ?>
<section class="wab-card" id="fluxo">
  <div class="wab-head">
    <div><h2>Fluxo de atendimento</h2><div class="wab-muted">Cada opção aparece numerada para o cliente. Opções do tipo <b>Abrir submenu</b> mostram outras opções dentro delas (o cliente digita <b>0</b> para voltar). Use ↑ ↓ para mudar a ordem.</div></div>
    <div class="wab-btns">
      <?php if (!$tree): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="seed"><button class="btn" type="submit">Criar menu padrão</button></form><?php endif; ?>
      <button class="btn btn-primary" type="button" onclick="wabNode({parent_id:0})">+ Nova opção</button>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;margin-top:12px" class="wab-flow">
    <div style="overflow-x:auto">
      <table class="wab-table">
        <thead><tr><th>Nº</th><th>Opção</th><th>Ação</th><th></th></tr></thead>
        <tbody>
        <?php if (!$tree): ?><tr><td colspan="4" class="wab-muted">Nenhuma opção ainda. Clique em <b>Criar menu padrão</b> para começar com 2ª via de boleto, consulta de pedido, CRM, Certificado Digital e atendente.</td></tr><?php endif; ?>
        <?php foreach ($tree as $n): ?>
          <tr>
            <td class="wab-num" style="padding-left:<?= 8 + (int)$n['depth'] * 22 ?>px"><?= wab_h($n['number']) ?></td>
            <td><?= (int)$n['depth'] > 0 ? '<span class="wab-muted">↳ </span>' : '' ?><?= wab_h($n['label']) ?><?= (int)$n['active'] ? '' : ' <span class="wab-chip off">inativa</span>' ?></td>
            <td><span class="wab-chip"><?= wab_h($actions[$n['action']] ?? $n['action']) ?></span><?php if ($n['action'] === 'setor'): ?> <span class="wab-muted"><?= wab_h($depNames[(int)$n['department_id']] ?? 'Fila geral') ?></span><?php endif; ?><?php if ($n['action'] === 'fluxo'): ?> <span class="wab-muted"><?= wab_h($flowNames[(int)($n['flow_id'] ?? 0)] ?? '(fluxo excluído)') ?></span><?php endif; ?></td>
            <td><div class="wab-row-actions">
              <?php foreach (['node_up' => '↑', 'node_down' => '↓'] as $a => $lbl): ?><form method="post"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn wab-mini" type="submit" title="<?= $a === 'node_up' ? 'Subir' : 'Descer' ?>"><?= $lbl ?></button></form><?php endforeach; ?>
              <button class="btn wab-mini" type="button" onclick='wabNode(<?= wab_h(json_encode(['id' => (int)$n['id'], 'parent_id' => (int)($n['parent_id'] ?? 0), 'label' => $n['label'], 'action' => $n['action'], 'message' => (string)($n['message'] ?? ''), 'department_id' => (int)($n['department_id'] ?? 0), 'flow_id' => (int)($n['flow_id'] ?? 0), 'active' => (int)$n['active']], JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
              <?php if ($n['action'] === 'submenu'): ?><button class="btn wab-mini" type="button" onclick="wabNode({parent_id:<?= (int)$n['id'] ?>})">+ Dentro</button><?php endif; ?>
              <form method="post" onsubmit="return confirm('Excluir esta opção<?= $n['action'] === 'submenu' ? ' e tudo que está dentro dela' : '' ?>?');"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="node_delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-danger wab-mini" type="submit">Excluir</button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div><div class="wab-muted" style="margin-bottom:6px;font-weight:700">Como o cliente vê o início:</div><div class="wab-phone"><pre><?= wab_h($preview) ?></pre></div></div>
  </div>
</section>
<?php endif; ?>

<?php if ($aba === 'fluxos'): ?>
<?php if ($flowsReady): ?>
<section class="wab-card" id="fluxos">
  <div class="wab-head">
    <div><h2>Fluxos (campanhas, anúncios e roteiros)</h2><div class="wab-muted">Um fluxo é uma sequência de perguntas e mensagens que termina numa ação (ex.: transferir para um setor). Ele começa quando o cliente manda uma <b>frase de gatilho</b> — por exemplo a mensagem pronta do seu anúncio "Olá, gostaria de conhecer melhor a solução" — ou por uma opção do menu com a ação <b>Iniciar um fluxo</b>. As respostas aparecem para o atendente na tela de Atendimento.</div></div>
    <div class="wab-btns"><a class="btn btn-primary" href="whatsapp_chatbot_fluxo.php">+ Novo fluxo</a></div>
  </div>
  <div style="overflow-x:auto"><table class="wab-table" style="margin-top:10px">
    <thead><tr><th>Fluxo</th><th>Frases de gatilho</th><th>Passos</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php if (!$flows): ?><tr><td colspan="5" class="wab-muted">Nenhum fluxo cadastrado.</td></tr><?php endif; ?>
    <?php foreach ($flows as $f): $trig = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($f['triggers'] ?? '')) ?: []))); ?>
      <tr>
        <td><b><?= wab_h($f['name']) ?></b></td>
        <td><?= $trig ? wab_h(mb_strimwidth(implode(' · ', $trig), 0, 120, '…')) : '<span class="wab-muted">Só pelo menu</span>' ?><?= $trig && $f['match_mode'] === 'exata' ? ' <span class="wab-chip off">exata</span>' : '' ?></td>
        <td><?= (int)$f['steps_count'] ?></td>
        <td><?= (int)$f['active'] ? '<span class="wab-chip">Ativo</span>' : '<span class="wab-chip off">Inativo</span>' ?></td>
        <td><div class="wab-row-actions">
          <a class="btn wab-mini" href="whatsapp_chatbot_fluxo.php?id=<?= (int)$f['id'] ?>">Editar</a>
          <form method="post" onsubmit="return confirm('Excluir este fluxo e todos os passos? Opções do menu que o iniciavam ficam inativas.');"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="flow_delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-danger wab-mini" type="submit">Excluir</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php else: ?>
<section class="wab-card"><div class="wab-muted">Para usar <b>fluxos com frase de gatilho</b> e <b>repetir último pedido</b>, aplique "WhatsApp - Fluxos e repetir pedido" no Atualizador do Banco.</div></section>
<?php endif; ?>
<?php endif; ?>

<?php if ($aba === 'setores'): ?>
<section class="wab-card" id="setores">
  <div class="wab-head">
    <div><h2>Setores de atendimento</h2><div class="wab-muted">Ex.: Financeiro, Suporte, Vendas. Cada atendente vê a fila dos setores em que está marcado (e as conversas sem setor). Administradores e quem pode configurar o chatbot veem tudo. Sem setores, todos veem todas as conversas.</div></div>
    <div class="wab-btns"><button class="btn btn-primary" type="button" onclick="wabDep({})">+ Novo setor</button></div>
  </div>
  <div style="overflow-x:auto"><table class="wab-table" style="margin-top:10px">
    <thead><tr><th>Setor</th><th>Atendentes</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php if (!$departments): ?><tr><td colspan="4" class="wab-muted">Nenhum setor cadastrado.</td></tr><?php endif; ?>
    <?php foreach ($departments as $d): $mem = $members[(int)$d['id']] ?? []; ?>
      <tr>
        <td><b><?= wab_h($d['name']) ?></b></td>
        <td><?= $mem ? wab_h(implode(', ', array_map(static fn($id) => $userNames[$id] ?? ('#' . $id), $mem))) : '<span class="wab-muted">Ninguém marcado</span>' ?></td>
        <td><?= (int)$d['active'] ? '<span class="wab-chip">Ativo</span>' : '<span class="wab-chip off">Inativo</span>' ?></td>
        <td><div class="wab-row-actions">
          <button class="btn wab-mini" type="button" onclick='wabDep(<?= wab_h(json_encode(['id' => (int)$d['id'], 'name' => $d['name'], 'active' => (int)$d['active'], 'users' => $mem], JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
          <form method="post" onsubmit="return confirm('Excluir este setor? As opções do fluxo que apontam para ele passam a usar a fila geral.');"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="dep_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-danger wab-mini" type="submit">Excluir</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<?php if ($aba === 'contatos'): ?>
<section class="wab-card" id="contatos">
  <div class="wab-head">
    <div><h2>Contatos</h2><div class="wab-muted">Quem fala pelos seus clientes: responsável, financeiro, contador, compras... Quando um contato chama no WhatsApp, a conversa já aparece ligada ao cliente dele. Também ficam na aba <b>Contatos</b> do cadastro de pessoas.</div></div>
    <div class="wab-btns"><button class="btn btn-primary" type="button" onclick="wabContact({})" <?= $contactsReady ? '' : 'disabled' ?>>+ Novo contato</button></div>
  </div>
  <?php if (!$contactsReady): ?>
    <div class="msg err" style="margin-top:10px">Aplique "Contatos das pessoas" no Atualizador do Banco para usar os contatos.</div>
  <?php else: ?>
  <form method="get" class="wab-search"><input type="hidden" name="aba" value="contatos"><input type="text" name="q" value="<?= wab_h($contactQ) ?>" placeholder="Buscar por nome, cliente, CPF/CNPJ, telefone, e-mail ou etiqueta"><button class="btn" type="submit">Buscar</button><?php if ($contactQ !== ''): ?><a class="btn" href="whatsapp_chatbot.php?aba=contatos">Limpar</a><?php endif; ?></form>
  <div style="overflow-x:auto"><table class="wab-table" style="margin-top:10px">
    <thead><tr><th>Contato</th><th>Cliente</th><th>WhatsApp</th><th>Telefone</th><th>E-mail</th><th></th></tr></thead>
    <tbody>
    <?php if (!$contacts): ?><tr><td colspan="6" class="wab-muted"><?= $contactQ !== '' ? 'Nenhum contato encontrado.' : 'Nenhum contato cadastrado.' ?></td></tr><?php endif; ?>
    <?php foreach ($contacts as $c): $callPhone = trim((string)($c['whatsapp'] ?? '')) ?: trim((string)($c['phone'] ?? '')); ?>
      <tr<?= (int)$c['active'] ? '' : ' style="opacity:.55"' ?>>
        <td><b><?= wab_h($c['name']) ?></b><?= (int)$c['is_primary'] ? ' <span class="wab-chip">Principal</span>' : '' ?><?= (int)$c['active'] ? '' : ' <span class="wab-chip off">inativo</span>' ?><?php if (!empty($c['role'])): ?><br><span class="wab-muted"><?= wab_h($c['role']) ?></span><?php endif; ?><?php if (!empty($c['tags'])): ?><div style="margin-top:3px"><?php foreach (person_contact_tags_list((string)$c['tags']) as $tg): [$tbg, $tfg] = person_contact_tag_color($tg); ?><a class="nexa-tag" title="Ver contatos com esta etiqueta" style="background:<?= $tbg ?>;color:<?= $tfg ?>;text-decoration:none" href="whatsapp_chatbot.php?aba=contatos&amp;q=<?= rawurlencode($tg) ?>"><?= wab_h($tg) ?></a><?php endforeach; ?></div><?php endif; ?></td>
        <td><?= !empty($c['person_name']) ? wab_h($c['person_name']) . (!empty($c['person_document']) ? '<br><span class="wab-muted">' . wab_h(function_exists('whatsapp_att_doc_label') ? whatsapp_att_doc_label((string)$c['person_document']) : $c['person_document']) . '</span>' : '') : '<span class="wab-muted">Sem cliente</span>' ?></td>
        <td><?= wab_h($c['whatsapp'] ?? '') ?></td>
        <td><?= wab_h($c['phone'] ?? '') ?></td>
        <td><?= wab_h($c['email'] ?? '') ?></td>
        <td><div class="wab-row-actions">
          <?php if ($callPhone !== '' && (int)$c['active']): ?><a class="btn wab-mini" style="background:#00a884;color:#fff;border-color:#00a884" href="whatsapp_atendimento.php?novo=<?= rawurlencode($callPhone) ?>&amp;nome=<?= rawurlencode((string)$c['name']) ?>">Chamar</a><?php endif; ?>
          <button class="btn wab-mini" type="button" onclick='wabContact(<?= wab_h(json_encode(['id' => (int)$c['id'], 'person_id' => (int)($c['person_id'] ?? 0), 'person_name' => (string)($c['person_name'] ?? ''), 'name' => $c['name'], 'role' => $c['role'], 'whatsapp' => $c['whatsapp'], 'phone' => $c['phone'], 'email' => $c['email'], 'notes' => $c['notes'], 'tags' => (string)($c['tags'] ?? ''), 'is_primary' => (int)$c['is_primary'], 'active' => (int)$c['active']], JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
          <form method="post" onsubmit="return confirm('Excluir este contato?');"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="contact_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-danger wab-mini" type="submit">Excluir</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($aba === 'rapidas'): ?>
<section class="wab-card" id="rapidas">
  <div class="wab-head">
    <div><h2>Mensagens rápidas</h2><div class="wab-muted">Respostas prontas para a tela de atendimento: o atendente digita <b>/</b> no início da mensagem (ex.: <b>/pix</b>) ou clica no <b>⚡</b> e escolhe. A mensagem entra na caixa de texto para conferir antes de enviar. Use <b>{nome}</b> (primeiro nome do cliente), <b>{atendente}</b> e <b>{empresa}</b>.</div></div>
    <div class="wab-btns"><button class="btn btn-primary" type="button" onclick="wabQuick({})">+ Nova mensagem</button></div>
  </div>
  <div style="overflow-x:auto"><table class="wab-table" style="margin-top:10px">
    <thead><tr><th>Atalho</th><th>Título</th><th>Mensagem</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php if (!$quickReplies): ?><tr><td colspan="5" class="wab-muted">Nenhuma mensagem rápida. Exemplos: <b>/pix</b> com a chave Pix, <b>/horario</b> com o horário de funcionamento, <b>/endereco</b> com o endereço da loja.</td></tr><?php endif; ?>
    <?php foreach ($quickReplies as $qr): ?>
      <tr<?= $qr['active'] ? '' : ' style="opacity:.55"' ?>>
        <td class="wab-num">/<?= wab_h($qr['shortcut']) ?></td>
        <td><b><?= wab_h($qr['title']) ?></b></td>
        <td class="wab-muted" style="max-width:460px"><?= wab_h(mb_strimwidth(preg_replace('/\s+/', ' ', $qr['text']) ?? '', 0, 140, '…')) ?></td>
        <td><?= $qr['active'] ? '<span class="wab-chip">Ativa</span>' : '<span class="wab-chip off">Inativa</span>' ?></td>
        <td><div class="wab-row-actions">
          <?php foreach (['quick_up' => '↑', 'quick_down' => '↓'] as $a => $lbl): ?><form method="post"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="id" value="<?= (int)$qr['id'] ?>"><button class="btn wab-mini" type="submit" title="<?= $a === 'quick_up' ? 'Subir' : 'Descer' ?>"><?= $lbl ?></button></form><?php endforeach; ?>
          <button class="btn wab-mini" type="button" onclick='wabQuick(<?= wab_h(json_encode($qr, JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
          <form method="post" onsubmit="return confirm('Excluir esta mensagem rápida?');"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="quick_delete"><input type="hidden" name="id" value="<?= (int)$qr['id'] ?>"><button class="btn btn-danger wab-mini" type="submit">Excluir</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<?php
  // Empresas do Grupo Empresarial (2ª via unificada pelo CPF/CNPJ).
  $groupNames = [];
  foreach (whatsapp_bot_group_company_ids($pdo, $cid, ['group_receivables' => true]) as $gid) $groupNames[] = whatsapp_bot_company_name($pdo, (int)$gid);
?>
<?php if ($aba === 'horario'):
  $dayNames = whatsapp_bot_day_names();
  $openNow = whatsapp_bot_hours_open($cfg);
?>
<section class="wab-card" id="horario">
  <div class="wab-head">
    <div><h2>Horário de atendimento</h2><div class="wab-muted">Fora do horário, o robô envia a mensagem de "fora do horário" no início da conversa e, ao transferir para um setor, avisa que a resposta vem no próximo horário.
      <?php if ($cfg['hours_enabled']): ?><br>Agora: <?= $openNow ? '<span class="wab-chip" style="background:#dcfce7;color:#166534">ABERTO</span>' : '<span class="wab-chip off" style="background:#fee2e2;color:#991b1b">FORA DO HORÁRIO</span>' ?> (<?= wab_h(date('d/m/Y H:i')) ?>)<?php else: ?><br><span class="wab-chip off">Controle de horário desligado</span> — o robô atende igual a qualquer hora.<?php endif; ?></div></div>
    <div class="wab-btns"><button class="btn btn-primary" type="button" onclick="wabOpen('mHorario')">Editar</button></div>
  </div>
  <table class="wab-table" style="margin-top:10px"><thead><tr><th>Dia</th><th>Horário</th></tr></thead><tbody>
  <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): $lbl = whatsapp_bot_hours_day_label($cfg['hours'][$d]); ?>
    <tr><td><b><?= wab_h($dayNames[$d]) ?></b></td><td><?= $lbl === 'fechado' ? '<span class="wab-chip off">fechado</span>' : wab_h($lbl) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <table class="wab-table" style="margin-top:12px"><tbody>
    <tr><th style="width:280px;vertical-align:top">Feriados / dias fechados</th><td style="white-space:pre-wrap"><?= trim($cfg['holidays']) !== '' ? wab_h($cfg['holidays']) : '<span class="wab-muted">Nenhum</span>' ?></td></tr>
    <tr><th style="vertical-align:top">Mensagem fora do horário</th><td style="white-space:pre-wrap"><?= wab_h($cfg['offhours_text']) ?></td></tr>
    <tr><th style="vertical-align:top">Ao transferir para um setor fora do horário</th><td style="white-space:pre-wrap"><?= wab_h($cfg['offhours_queue_text']) ?></td></tr>
    <tr><th>Fora do horário, o robô</th><td><?= $cfg['offhours_menu'] ? 'continua mostrando o menu (2ª via, consultas...)' : 'só envia o aviso e deixa a conversa na fila' ?></td></tr>
  </tbody></table>
</section>
<?php endif; ?>

<?php if ($aba === 'robo'):
  $sumRobo = [
    'Boas-vindas' => $cfg['welcome'],
    'Ao transferir para um setor' => $cfg['queue_text'],
    'Quando o atendente aceitar' => $cfg['accept_text'] !== '' ? $cfg['accept_text'] : '(não envia nada)',
    'Ao finalizar o atendimento' => $cfg['close_text'],
    'Nome do atendente nas mensagens' => $cfg['sign_messages'] ? 'Sim' : 'Não',
    'Respondeu pelo celular: o robô volta depois de' => (int)$cfg['pause_hours'] . ' hora(s) sem resposta',
    '2ª via com as empresas do Grupo Empresarial' => $cfg['group_receivables'] ? 'Sim — ' . implode(', ', $groupNames) : 'Não (só esta empresa)',
  ];
?>
<section class="wab-card" id="config">
  <div class="wab-head">
    <div><h2>Robô e mensagens padrão</h2><div class="wab-muted">Robô: <?= $cfg['enabled'] ? '<span class="wab-chip" style="background:#dcfce7;color:#166534">LIGADO</span>' : '<span class="wab-chip off">desligado</span> — toda conversa nova vai direto para a fila do Atendimento.' ?></div></div>
    <div class="wab-btns"><a class="btn" href="whatsapp.php">Configuração WhatsApp</a><button class="btn btn-primary" type="button" onclick="wabOpen('mRobo')">Editar</button></div>
  </div>
  <table class="wab-table" style="margin-top:10px"><tbody>
  <?php foreach ($sumRobo as $k => $v): ?><tr><th style="width:280px;vertical-align:top"><?= wab_h($k) ?></th><td style="white-space:pre-wrap"><?= wab_h($v) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>

<?php if ($aba === 'crm'):
  $stNames = []; foreach ($statuses as $x) $stNames[(int)$x['id']] = (string)$x['name'];
  $tpNames = []; foreach ($types as $x) $tpNames[(int)$x['id']] = (string)$x['name'];
?>
<section class="wab-card" id="crm">
  <div class="wab-head">
    <div><h2>CRM</h2><div class="wab-muted">Usado pela opção <b>"Abrir atendimento no CRM"</b> do fluxo e pelo botão CRM da tela de atendimento.</div></div>
    <div class="wab-btns"><button class="btn btn-primary" type="button" onclick="wabOpen('mCrm')">Editar</button></div>
  </div>
  <table class="wab-table" style="margin-top:10px"><tbody>
    <tr><th style="width:280px">Responsável</th><td><?= wab_h($userNames[(int)$cfg['crm_responsible_user_id']] ?? 'Automático (administrador)') ?></td></tr>
    <tr><th>Status inicial</th><td><?= wab_h($stNames[(int)$cfg['crm_status_id']] ?? 'Primeiro da lista') ?></td></tr>
    <tr><th>Tipo de atendimento</th><td><?= wab_h($tpNames[(int)$cfg['crm_service_type_id']] ?? 'Primeiro da lista') ?></td></tr>
  </tbody></table>
</section>
<?php endif; ?>

<div class="wab-modal" id="mRobo"><form method="post" class="wab-card" style="max-width:820px">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="section" value="robo">
  <h2>Robô e mensagens padrão</h2>
  <label class="wab-switch"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Robô ligado</label>
  <div class="wab-muted" style="margin:6px 0 12px">Com o robô desligado, toda conversa nova vai direto para a fila do Atendimento.</div>
  <div class="wab-grid">
    <div class="wab-field"><label>Boas-vindas ({nome} = nome do contato, {empresa} = nome da empresa). O menu vem logo abaixo.</label><textarea name="welcome" maxlength="1000"><?= wab_h($cfg['welcome']) ?></textarea></div>
    <div class="wab-field"><label>Ao transferir para um setor ({setor} = nome do setor)</label><textarea name="queue_text" maxlength="1000"><?= wab_h($cfg['queue_text']) ?></textarea></div>
    <div class="wab-field"><label>Quando o atendente aceitar ({atendente} = nome). Deixe em branco para não enviar.</label><textarea name="accept_text" maxlength="1000"><?= wab_h($cfg['accept_text']) ?></textarea></div>
    <div class="wab-field"><label>Ao finalizar o atendimento</label><textarea name="close_text" maxlength="1000"><?= wab_h($cfg['close_text']) ?></textarea></div>
  </div>
  <div class="wab-grid" style="margin-top:12px">
    <div class="wab-field"><label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:600"><input type="checkbox" name="sign_messages" value="1" <?= $cfg['sign_messages'] ? 'checked' : '' ?>> Mostrar o nome do atendente nas mensagens</label></div>
    <div class="wab-field"><label>Respondeu pelo celular: o robô volta depois de quantas horas sem resposta?</label><input type="number" name="pause_hours" min="1" max="72" value="<?= (int)$cfg['pause_hours'] ?>"></div>
  </div>
  <div class="wab-field" style="margin-top:12px;border:1px solid #dbeafe;background:#f8fbff;border-radius:12px;padding:10px 12px">
    <?php if (count($groupNames) > 1): ?>
      <label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:700"><input type="checkbox" name="group_receivables" value="1" <?= $cfg['group_receivables'] ? 'checked' : '' ?>> 2ª via de boleto com as empresas do Grupo Empresarial</label>
      <div class="wab-muted" style="margin-top:4px">O cliente informa o CPF/CNPJ e recebe uma lista numerada das contas em aberto de <b><?= wab_h(implode(', ', $groupNames)) ?></b> (só das empresas onde ele tiver contas) e escolhe quais boletos quer receber.</div>
    <?php else: ?>
      <div class="wab-muted">2ª via com várias empresas: disponível quando esta empresa faz parte de um <b>Grupo Empresarial</b> com outras empresas (Central SaaS).</div>
    <?php endif; ?>
  </div>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<div class="wab-modal" id="mHorario"><form method="post" class="wab-card" style="max-width:860px;max-height:92vh;overflow:auto">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="section" value="horario">
  <h2>Horário de atendimento</h2>
  <label class="wab-switch"><input type="checkbox" name="hours_enabled" value="1" <?= $cfg['hours_enabled'] ? 'checked' : '' ?>> Controlar o horário de atendimento</label>
  <div class="wab-muted" style="margin:6px 0 10px">Até dois períodos por dia (ex.: 08:00 às 12:00 e 13:30 às 18:00). Deixe o segundo em branco se não houver intervalo.</div>
  <table class="wab-table"><thead><tr><th>Dia</th><th>Aberto</th><th>Período 1</th><th>Período 2 (opcional)</th></tr></thead><tbody>
  <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): $h = $cfg['hours'][$d]; ?>
    <tr>
      <td><b><?= wab_h(whatsapp_bot_day_names()[$d]) ?></b></td>
      <td><input type="checkbox" name="hours[<?= $d ?>][on]" value="1" <?= $h['on'] ? 'checked' : '' ?>></td>
      <td style="white-space:nowrap"><input type="time" name="hours[<?= $d ?>][a]" value="<?= wab_h($h['a']) ?>"> às <input type="time" name="hours[<?= $d ?>][b]" value="<?= wab_h($h['b']) ?>"></td>
      <td style="white-space:nowrap"><input type="time" name="hours[<?= $d ?>][c]" value="<?= wab_h($h['c']) ?>"> às <input type="time" name="hours[<?= $d ?>][d]" value="<?= wab_h($h['d']) ?>"></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <div class="wab-grid" style="margin-top:12px">
    <div class="wab-field"><label>Mensagem fora do horário ({nome}, {empresa}, {horarios} = lista dos horários)</label><textarea name="offhours_text" maxlength="1000" rows="6"><?= wab_h($cfg['offhours_text']) ?></textarea></div>
    <div class="wab-field"><label>Ao transferir para um setor fora do horário ({setor})</label><textarea name="offhours_queue_text" maxlength="1000" rows="6"><?= wab_h($cfg['offhours_queue_text']) ?></textarea></div>
    <div class="wab-field"><label>Feriados / dias fechados (um por linha: 25/12 todo ano, ou 20/11/2026)</label><textarea name="holidays" maxlength="2000" rows="4" placeholder="01/01&#10;21/04&#10;25/12"><?= wab_h($cfg['holidays']) ?></textarea></div>
    <div class="wab-field"><label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;font-weight:600"><input type="checkbox" name="offhours_menu" value="1" <?= $cfg['offhours_menu'] ? 'checked' : '' ?> style="margin-top:3px"> Fora do horário, depois do aviso o robô continua mostrando o menu (2ª via de boleto, consulta de pedido...). Desmarcado: só envia o aviso e deixa a conversa na fila.</label></div>
  </div>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<div class="wab-modal" id="mCrm"><form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="section" value="crm">
  <h2>CRM</h2>
  <div class="wab-field"><label>Responsável</label><select name="crm_responsible_user_id"><option value="0">Automático (administrador)</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$cfg['crm_responsible_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= wab_h($u['name']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field"><label>Status inicial</label><select name="crm_status_id"><option value="0">Primeiro da lista</option><?php foreach ($statuses as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$cfg['crm_status_id'] === (int)$x['id'] ? 'selected' : '' ?>><?= wab_h($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field"><label>Tipo de atendimento</label><select name="crm_service_type_id"><option value="0">Primeiro da lista</option><?php foreach ($types as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$cfg['crm_service_type_id'] === (int)$x['id'] ? 'selected' : '' ?>><?= wab_h($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<div class="wab-modal" id="mQuick"><form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="quick_save"><input type="hidden" name="id" id="qId">
  <h2 id="qTitleH">Mensagem rápida</h2>
  <div class="wab-grid">
    <div class="wab-field"><label>Atalho (o que digita depois da /)</label><input type="text" name="shortcut" id="qShortcut" maxlength="30" required placeholder="Ex.: pix"></div>
    <div class="wab-field"><label>Título</label><input type="text" name="title" id="qTitle" maxlength="80" placeholder="Ex.: Chave Pix"></div>
  </div>
  <div class="wab-field"><label>Mensagem ({nome}, {atendente}, {empresa}; *negrito*, _itálico_)</label><textarea name="text" id="qText" maxlength="3000" rows="6" required></textarea></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="qActive"> Ativa</label>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<?php if ($contactsReady): ?>
<div class="wab-modal" id="mContact"><form method="post" class="wab-card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="contact_save"><input type="hidden" name="id" id="cId"><input type="hidden" name="person_id" id="cPerson">
  <h2 id="cTitle">Contato</h2>
  <div class="wab-field"><label>Cliente (empresa ou pessoa a quem o contato pertence)</label>
    <div id="cPersonSel" class="wab-sel" style="display:none"><span id="cPersonName"></span><button type="button" class="btn wab-mini" onclick="wabPerson(0,'')">Trocar</button></div>
    <div id="cPersonFind"><input type="text" id="cPersonQ" placeholder="Digite o nome, fantasia ou CPF/CNPJ"><div id="cPersonRes" class="wab-res"></div><div class="wab-muted">Pode deixar em branco para um contato avulso.</div></div>
  </div>
  <div class="wab-grid">
    <div class="wab-field"><label>Nome do contato *</label><input type="text" name="name" id="cName" maxlength="120" required></div>
    <div class="wab-field"><label>Função</label><input type="text" name="role" id="cRole" maxlength="60" list="cRoles" placeholder="Ex.: Financeiro, Contador"><datalist id="cRoles"><?php foreach (person_contact_roles() as $r): ?><option value="<?= wab_h($r) ?>"><?php endforeach; ?></datalist></div>
    <div class="wab-field"><label>WhatsApp</label><input type="text" name="whatsapp" id="cWa" maxlength="30" inputmode="tel" placeholder="(31) 99999-9999"></div>
    <div class="wab-field"><label>Telefone</label><input type="text" name="phone" id="cPhone" maxlength="30" inputmode="tel"></div>
    <div class="wab-field"><label>E-mail</label><input type="text" name="email" id="cEmail" maxlength="150" inputmode="email"></div>
    <div class="wab-field"><label>Observação</label><input type="text" name="notes" id="cNotes" maxlength="500"></div>
  </div>
  <?php if ($contactTags): ?><div class="wab-field" style="margin-top:4px"><label>Etiquetas (ex.: VIP, Inadimplente, Revenda — Enter para incluir)</label><input type="text" name="tags" id="cTags" data-tag-input data-suggest="<?= wab_h(json_encode($allTags, JSON_UNESCAPED_UNICODE)) ?>" placeholder="Digite a etiqueta e tecle Enter"></div><?php endif; ?>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px">
    <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="is_primary" value="1" id="cPrimary"> Contato principal do cliente</label>
    <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="cActive"> Ativo</label>
  </div>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>
<?php endif; ?>

<div class="wab-modal" id="mNode"><form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="node_save"><input type="hidden" name="id" id="nId">
  <h2 id="nTitle">Opção do menu</h2>
  <div class="wab-field"><label>Fica em</label><select name="parent_id" id="nParent"><option value="0">Menu inicial</option><?php foreach ($submenus as $s): ?><option value="<?= (int)$s['id'] ?>">Dentro de <?= wab_h($s['number'] . ' - ' . $s['label']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field"><label>Texto da opção (o que o cliente vê no menu)</label><input type="text" name="label" id="nLabel" maxlength="80" required></div>
  <div class="wab-field"><label>O que acontece ao escolher</label><select name="node_action" id="nAction" onchange="wabNodeAction()"><?php foreach ($actions as $k => $lbl): ?><option value="<?= wab_h($k) ?>"><?= wab_h($lbl) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field" id="nFlowBox"><label>Fluxo que esta opção inicia</label><select name="flow_id" id="nFlow"><option value="0">— escolha —</option><?php foreach ($flows as $f): ?><option value="<?= (int)$f['id'] ?>"><?= wab_h($f['name']) ?></option><?php endforeach; ?></select><?php if (!$flows): ?><div class="wab-muted">Cadastre um fluxo primeiro (aba Fluxos).</div><?php endif; ?></div>
  <div class="wab-field" id="nDepBox"><label>Setor</label><select name="department_id" id="nDep"><option value="0">Fila geral (qualquer atendente)</option><?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= wab_h($d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field"><label id="nMsgLabel">Mensagem</label><textarea name="message" id="nMsg" maxlength="3000"></textarea><div class="wab-muted" id="nMsgHint"></div></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="nActive"> Opção ativa</label>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<div class="wab-modal" id="mDep"><form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="dep_save"><input type="hidden" name="id" id="dId">
  <h2>Setor</h2>
  <div class="wab-field"><label>Nome</label><input type="text" name="name" id="dName" maxlength="80" required></div>
  <div class="wab-field"><label>Quem atende neste setor</label><div class="wab-users"><?php foreach ($users as $u): ?><label><input type="checkbox" name="users[]" value="<?= (int)$u['id'] ?>" class="dUser"> <?= wab_h($u['name']) ?></label><?php endforeach; ?></div></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="dActive"> Setor ativo</label>
  <div class="wab-modal-foot"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<script src="/assets/tag-input.js?v=20261112-1"></script>
<script>
const WAB_HINTS = {
  submenu: ['Texto acima das opções do submenu (opcional)', 'Depois de salvar, use "+ Dentro" para cadastrar as opções deste submenu.'],
  text: ['Mensagem enviada ao cliente', 'Ex.: informações sobre Certificado Digital, endereço, horário de funcionamento.'],
  setor: ['Mensagem ao transferir (opcional)', 'Em branco usa a mensagem padrão de transferência. A conversa vai para a fila do setor na tela de Atendimento.'],
  boleto: ['Pergunta do robô (opcional)', 'Em branco: "Para enviar a 2ª via, informe o CPF ou CNPJ do titular".'],
  pedido: ['Pergunta do robô (opcional)', 'Em branco: "Informe o número do pedido".'],
  crm: ['Pergunta do robô (opcional)', 'Em branco: pede para o cliente descrever o que precisa e gera um atendimento no CRM com protocolo.'],
  repetir_pedido: ['Mensagem antes (opcional)', 'O robô acha o último pedido do cliente (pelo WhatsApp ou CPF/CNPJ), mostra os itens com o preço de hoje e pede confirmação. Confirmado, cria um pedido EM ABERTO para a loja confirmar (igual pedido do catálogo online).'],
  fluxo: ['Mensagem antes (opcional)', 'Inicia o fluxo escolhido (perguntas em sequência).'],
};
function wabNodeAction() {
  const a = document.getElementById('nAction').value;
  document.getElementById('nDepBox').style.display = a === 'setor' ? '' : 'none';
  document.getElementById('nFlowBox').style.display = a === 'fluxo' ? '' : 'none';
  document.getElementById('nMsgLabel').textContent = (WAB_HINTS[a] || ['Mensagem'])[0];
  document.getElementById('nMsgHint').textContent = (WAB_HINTS[a] || ['', ''])[1];
}
function wabNode(n) {
  document.getElementById('nId').value = n.id || '';
  document.getElementById('nParent').value = String(n.parent_id || 0);
  document.getElementById('nLabel').value = n.label || '';
  document.getElementById('nAction').value = n.action || 'text';
  document.getElementById('nDep').value = String(n.department_id || 0);
  document.getElementById('nFlow').value = String(n.flow_id || 0);
  document.getElementById('nMsg').value = n.message || '';
  document.getElementById('nActive').checked = n.id ? !!n.active : true;
  document.getElementById('nTitle').textContent = n.id ? 'Editar opção' : 'Nova opção';
  wabNodeAction();
  document.getElementById('mNode').classList.add('on');
  document.getElementById('nLabel').focus();
}
function wabDep(d) {
  document.getElementById('dId').value = d.id || '';
  document.getElementById('dName').value = d.name || '';
  document.getElementById('dActive').checked = d.id ? !!d.active : true;
  const sel = (d.users || []).map(String);
  document.querySelectorAll('.dUser').forEach((c) => { c.checked = sel.includes(c.value); });
  document.getElementById('mDep').classList.add('on');
}
function wabQuick(q) {
  document.getElementById('qId').value = q.id || '';
  document.getElementById('qShortcut').value = q.shortcut || '';
  document.getElementById('qTitle').value = q.title || '';
  document.getElementById('qText').value = q.text || '';
  document.getElementById('qActive').checked = q.id ? !!q.active : true;
  document.getElementById('qTitleH').textContent = q.id ? 'Editar mensagem rápida' : 'Nova mensagem rápida';
  wabOpen('mQuick');
  document.getElementById(q.id ? 'qText' : 'qShortcut').focus();
}
function wabOpen(id) { const m = document.getElementById(id); if (m) m.classList.add('on'); }
function wabPerson(id, name) {
  document.getElementById('cPerson').value = id ? String(id) : '';
  document.getElementById('cPersonName').textContent = name || '';
  document.getElementById('cPersonSel').style.display = id ? 'flex' : 'none';
  document.getElementById('cPersonFind').style.display = id ? 'none' : '';
  document.getElementById('cPersonRes').innerHTML = '';
  if (!id) { const q = document.getElementById('cPersonQ'); q.value = ''; }
}
function wabContact(c) {
  if (!document.getElementById('mContact')) return;
  const set = (id, v) => { document.getElementById(id).value = v == null ? '' : v; };
  set('cId', c.id || ''); set('cName', c.name); set('cRole', c.role); set('cWa', c.whatsapp); set('cPhone', c.phone); set('cEmail', c.email); set('cNotes', c.notes); const tg = document.getElementById('cTags'); if (tg) { tg.value = c.tags || ''; if (window.NexaTags) NexaTags.sync(tg); }
  document.getElementById('cPrimary').checked = !!c.is_primary;
  document.getElementById('cActive').checked = c.id ? !!c.active : true;
  document.getElementById('cTitle').textContent = c.id ? 'Editar contato' : 'Novo contato';
  wabPerson(c.person_id || 0, c.person_name || '');
  wabOpen('mContact');
  (c.id ? document.getElementById('cName') : document.getElementById('cPersonQ')).focus();
}
(function () {
  const q = document.getElementById('cPersonQ'); if (!q) return;
  const box = document.getElementById('cPersonRes'); let t = null, seq = 0;
  q.addEventListener('input', () => {
    clearTimeout(t);
    const v = q.value.trim();
    if (v.length < 2) { box.innerHTML = ''; return; }
    t = setTimeout(async () => {
      const my = ++seq;
      try {
        const r = await fetch('whatsapp_chatbot.php?ajax=pessoas&q=' + encodeURIComponent(v), { credentials: 'same-origin' });
        const j = await r.json(); if (my !== seq) return;
        box.innerHTML = '';
        (j.items || []).forEach((p) => {
          const b = document.createElement('button'); b.type = 'button';
          const n = document.createElement('b'); n.textContent = p.name; b.appendChild(n);
          if (p.extra) { const e = document.createElement('span'); e.textContent = ' ' + p.extra; b.appendChild(e); }
          b.addEventListener('click', () => wabPerson(p.id, p.name));
          box.appendChild(b);
        });
        if (!(j.items || []).length) box.innerHTML = '<div class="wab-muted" style="padding:6px">Nenhum cliente encontrado.</div>';
      } catch (_) {}
    }, 250);
  });
})();
document.querySelectorAll('.wab-modal').forEach((m) => m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('on'); }));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.querySelectorAll('.wab-modal.on').forEach((m) => m.classList.remove('on')); });
</script>
<style>@media(max-width:900px){.wab-flow{grid-template-columns:1fr!important}.wab-head{flex-wrap:wrap}}</style>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
