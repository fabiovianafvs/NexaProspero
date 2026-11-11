<?php
declare(strict_types=1);

// WhatsApp > Chatbot: liga/desliga o robô, monta o fluxo (menus e submenus,
// com ordem), cadastra os setores de atendimento e as mensagens padrão.
// Sempre da empresa de login (sem F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_chatbot.php';

$cid = (int)COMPANY_ID;
$msg = isset($_GET['ok']) ? (string)$_GET['ok'] : '';
$err = '';
function wab_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_wab_csrf'])) $_SESSION['nexa_wab_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_wab_csrf'] ?? '');
$ready = whatsapp_bot_ready($pdo);

$waCfg = whatsapp_load_config($pdo, $cid, false);
$engine = whatsapp_engine_config($pdo);
$onEngine = whatsapp_engine_is_url($engine, (string)($waCfg['service_url'] ?? ''));

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $go = static function (string $ok, string $anchor = '') { header('Location: whatsapp_chatbot.php?ok=' . rawurlencode($ok) . $anchor); exit; };
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
      case 'save':
        $res = whatsapp_bot_save_config($pdo, $cid, $_POST);
        $on = whatsapp_bot_config($pdo, $cid)['enabled'];
        if (!$res['ok']) throw new RuntimeException('Configuração salva, mas o WhatsApp não confirmou: ' . $res['message']);
        $go(($on ? 'Configuração salva. O robô está LIGADO.' : 'Configuração salva. O robô está desligado: as conversas novas vão direto para a fila do Atendimento.') . ($res['message'] !== '' ? ' ' . $res['message'] : ''));
      case 'node_save':
        whatsapp_bot_node_save($pdo, $cid, $_POST + ['active' => 0]);
        $go('Opção salva.', '#fluxo');
      case 'node_delete':
        whatsapp_bot_node_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Opção excluída.', '#fluxo');
      case 'node_up':
      case 'node_down':
        whatsapp_bot_node_move($pdo, $cid, (int)($_POST['id'] ?? 0), $action === 'node_up' ? -1 : 1);
        $go('Ordem alterada.', '#fluxo');
      case 'seed':
        whatsapp_bot_seed_default_flow($pdo, $cid);
        $go('Menu padrão criado. Ajuste os textos e a ordem como preferir.', '#fluxo');
      case 'dep_save':
        whatsapp_department_save($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), !empty($_POST['active']), (array)($_POST['users'] ?? []));
        $go('Setor salvo.', '#setores');
      case 'flow_delete':
        whatsapp_bot_flow_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Fluxo excluído.', '#fluxos');
      case 'dep_delete':
        whatsapp_department_delete($pdo, $cid, (int)($_POST['id'] ?? 0));
        $go('Setor excluído.', '#setores');
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
.wab-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}
.wab-table{width:100%;border-collapse:collapse;font-size:13px}.wab-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0}.wab-table td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.wab-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-weight:800;font-size:11px;background:#eef2ff;color:#3730a3}.wab-chip.off{background:#e2e8f0;color:#64748b}
.wab-num{font-weight:800;color:#0b1f5b;white-space:nowrap}.wab-row-actions{display:flex;gap:4px;flex-wrap:wrap}.wab-row-actions form{margin:0}.wab-mini{padding:4px 9px!important;font-size:12px!important}
.wab-phone{background:#efeae2;border-radius:16px;padding:14px;max-width:360px}.wab-phone pre{background:#fff;border-radius:10px;padding:10px;white-space:pre-wrap;font:14px/1.45 system-ui,sans-serif;margin:0;box-shadow:0 1px .5px rgba(0,0,0,.15)}
.wab-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}.wab-modal.on{display:flex}.wab-modal .wab-card{width:100%;max-width:560px;max-height:92vh;overflow:auto}
.wab-users{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px;padding:8px}.wab-users label{display:flex;gap:6px;align-items:center;font-size:13px;font-weight:500}
</style>
<div class="wab-wrap">
<?php if ($msg !== ''): ?><div class="msg ok"><?= wab_h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= wab_h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="msg err">O chatbot ainda não foi instalado. Peça ao administrador da plataforma para aplicar "WhatsApp - Chatbot" e "WhatsApp - Atendimento" no Atualizador do Banco.</div><?php endif; ?>
<?php if ($ready && !$onEngine): ?><div class="msg err">O WhatsApp desta empresa ainda não está no motor novo; o chatbot e o atendimento só funcionam nele. Peça ao suporte para fazer a troca.</div><?php endif; ?>

<?php if ($ready): ?>
<section class="wab-card">
  <div class="wab-head">
    <div><h2>Chatbot e atendimento</h2><div class="wab-muted">1) Monte o <b>fluxo</b> (o que o robô oferece). 2) Cadastre os <b>setores</b> e quem atende em cada um. 3) Ligue o robô. As conversas transferidas aparecem em <b>WhatsApp → Atendimento</b>.</div></div>
    <a class="btn btn-primary" href="whatsapp_atendimento.php">Abrir tela de Atendimento</a>
  </div>
</section>

<section class="wab-card" id="fluxo">
  <div class="wab-head">
    <div><h2>Fluxo do atendimento</h2><div class="wab-muted">Cada opção aparece numerada para o cliente. Opções do tipo <b>Abrir submenu</b> mostram outras opções dentro delas (o cliente digita <b>0</b> para voltar). Use ↑ ↓ para mudar a ordem.</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
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

<?php if ($flowsReady): ?>
<section class="wab-card" id="fluxos">
  <div class="wab-head">
    <div><h2>Fluxos (campanhas, anúncios e roteiros)</h2><div class="wab-muted">Um fluxo é uma sequência de perguntas e mensagens que termina numa ação (ex.: transferir para um setor). Ele começa quando o cliente manda uma <b>frase de gatilho</b> — por exemplo a mensagem pronta do seu anúncio "Olá, gostaria de conhecer melhor a solução" — ou por uma opção do menu com a ação <b>Iniciar um fluxo</b>. As respostas aparecem para o atendente na tela de Atendimento.</div></div>
    <a class="btn btn-primary" href="whatsapp_chatbot_fluxo.php">+ Novo fluxo</a>
  </div>
  <table class="wab-table" style="margin-top:10px">
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
  </table>
</section>
<?php else: ?>
<section class="wab-card"><div class="wab-muted">Para usar <b>fluxos com frase de gatilho</b> e <b>repetir último pedido</b>, aplique "WhatsApp - Fluxos e repetir pedido" no Atualizador do Banco.</div></section>
<?php endif; ?>

<section class="wab-card" id="setores">
  <div class="wab-head">
    <div><h2>Setores de atendimento</h2><div class="wab-muted">Ex.: Financeiro, Suporte, Vendas. Cada atendente vê a fila dos setores em que está marcado (e as conversas sem setor). Administradores e quem pode configurar o chatbot veem tudo. Sem setores, todos veem todas as conversas.</div></div>
    <button class="btn btn-primary" type="button" onclick="wabDep({})">+ Novo setor</button>
  </div>
  <table class="wab-table" style="margin-top:10px">
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
  </table>
</section>

<form method="post" class="wab-card" id="config">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="save">
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
  <h3 style="margin:16px 0 6px;color:#0b1f5b;font-size:16px">CRM (opção "Abrir atendimento no CRM" do fluxo)</h3>
  <div class="wab-grid">
    <div class="wab-field"><label>Responsável</label><select name="crm_responsible_user_id"><option value="0">Automático (administrador)</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$cfg['crm_responsible_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= wab_h($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="wab-field"><label>Status inicial</label><select name="crm_status_id"><option value="0">Primeiro da lista</option><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$cfg['crm_status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= wab_h($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="wab-field"><label>Tipo de atendimento</label><select name="crm_service_type_id"><option value="0">Primeiro da lista</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$cfg['crm_service_type_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= wab_h($t['name']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Salvar</button><a class="btn" href="whatsapp.php">Configuração WhatsApp</a></div>
</form>

<div class="wab-modal" id="mNode"><form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="node_save"><input type="hidden" name="id" id="nId">
  <h2 id="nTitle">Opção do menu</h2>
  <div class="wab-field"><label>Fica em</label><select name="parent_id" id="nParent"><option value="0">Menu inicial</option><?php foreach ($submenus as $s): ?><option value="<?= (int)$s['id'] ?>">Dentro de <?= wab_h($s['number'] . ' - ' . $s['label']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field"><label>Texto da opção (o que o cliente vê no menu)</label><input type="text" name="label" id="nLabel" maxlength="80" required></div>
  <div class="wab-field"><label>O que acontece ao escolher</label><select name="action" id="nAction" onchange="wabNodeAction()"><?php foreach ($actions as $k => $lbl): ?><option value="<?= wab_h($k) ?>"><?= wab_h($lbl) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field" id="nFlowBox"><label>Fluxo que esta opção inicia</label><select name="flow_id" id="nFlow"><option value="0">— escolha —</option><?php foreach ($flows as $f): ?><option value="<?= (int)$f['id'] ?>"><?= wab_h($f['name']) ?></option><?php endforeach; ?></select><?php if (!$flows): ?><div class="wab-muted">Cadastre um fluxo primeiro (seção Fluxos).</div><?php endif; ?></div>
  <div class="wab-field" id="nDepBox"><label>Setor</label><select name="department_id" id="nDep"><option value="0">Fila geral (qualquer atendente)</option><?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= wab_h($d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="wab-field"><label id="nMsgLabel">Mensagem</label><textarea name="message" id="nMsg" maxlength="3000"></textarea><div class="wab-muted" id="nMsgHint"></div></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="nActive"> Opção ativa</label>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<div class="wab-modal" id="mDep"><form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="dep_save"><input type="hidden" name="id" id="dId">
  <h2>Setor</h2>
  <div class="wab-field"><label>Nome</label><input type="text" name="name" id="dName" maxlength="80" required></div>
  <div class="wab-field"><label>Quem atende neste setor</label><div class="wab-users"><?php foreach ($users as $u): ?><label><input type="checkbox" name="users[]" value="<?= (int)$u['id'] ?>" class="dUser"> <?= wab_h($u['name']) ?></label><?php endforeach; ?></div></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="dActive"> Setor ativo</label>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn" type="button" onclick="this.closest('.wab-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

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
document.querySelectorAll('.wab-modal').forEach((m) => m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('on'); }));
</script>
<style>@media(max-width:900px){.wab-flow{grid-template-columns:1fr!important}}</style>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
