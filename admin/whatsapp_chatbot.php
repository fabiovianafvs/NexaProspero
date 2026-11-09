<?php
declare(strict_types=1);

// WhatsApp > Chatbot: liga/desliga o atendimento automático da empresa,
// define as opções do menu e mostra as conversas recentes.
// Sempre da empresa de login (sem F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_chatbot.php';

$cid = (int)COMPANY_ID;
$msg = '';
$err = '';
function wab_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function wab_dt($v): string { $t = strtotime((string)$v); return $t ? date('d/m/Y H:i', $t) : '—'; }
function wab_contact(array $r): string {
  $d = preg_replace('/\D+/', '', (string)($r['phone'] ?? '')) ?? '';
  if (strlen($d) === 13 && str_starts_with($d, '55')) $d = '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 5) . '-' . substr($d, 9);
  elseif (strlen($d) === 12 && str_starts_with($d, '55')) $d = '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 4) . '-' . substr($d, 8);
  $name = trim((string)($r['push_name'] ?? ''));
  return trim($name . ($d !== '' ? ' · ' . $d : '')) ?: 'Contato';
}
const WAB_STATES = ['MENU' => 'No menu', 'ASK_DOC' => 'Informando CPF/CNPJ', 'ASK_ORDER' => 'Informando pedido', 'ASK_CRM' => 'Descrevendo atendimento', 'ATENDENTE' => 'Com atendente', 'FIM' => 'Encerrada', 'NOVA' => 'Nova'];

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_wab_csrf'])) $_SESSION['nexa_wab_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_wab_csrf'] ?? '');
$ready = whatsapp_bot_ready($pdo);

$waCfg = whatsapp_load_config($pdo, $cid, false);
$engine = whatsapp_engine_config($pdo);
$onEngine = whatsapp_engine_is_url($engine, (string)($waCfg['service_url'] ?? ''));

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
      $res = whatsapp_bot_save_config($pdo, $cid, $_POST);
      $on = !empty($_POST['enabled']);
      if ($res['ok']) $msg = $on ? 'Configuração salva. O chatbot está LIGADO e já responde às mensagens recebidas.' : 'Configuração salva. O chatbot está desligado.';
      else $err = 'Configuração salva, mas o WhatsApp não confirmou: ' . $res['message'];
      if ($res['ok'] && $res['message'] !== '') $err = $res['message'];
    } elseif ($action === 'release') {
      $contact = (string)($_POST['contact'] ?? '');
      $pdo->prepare("UPDATE whatsapp_bot_sessions SET paused_until=NULL, state='FIM' WHERE company_id=? AND contact=?")->execute([$cid, $contact]);
      $msg = 'Conversa devolvida ao robô. Na próxima mensagem do cliente ele recebe o menu.';
    } else {
      throw new RuntimeException('Ação inválida.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cfg = whatsapp_bot_config($pdo, $cid);
$users = $statuses = $types = [];
try { $st = $pdo->prepare('SELECT id, name FROM users WHERE company_id=? AND is_active=1 ORDER BY name, id'); $st->execute([$cid]); $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
try { $st = $pdo->prepare('SELECT id, name FROM crm_statuses WHERE company_id=? AND active=1 ORDER BY sort_order, name, id'); $st->execute([$cid]); $statuses = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
try { $st = $pdo->prepare('SELECT id, name FROM crm_service_types WHERE company_id=? AND active=1 ORDER BY sort_order, name, id'); $st->execute([$cid]); $types = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}

$conversations = [];
$view = trim((string)($_GET['ver'] ?? ''));
$history = [];
if ($ready) {
  $st = $pdo->prepare('SELECT * FROM whatsapp_bot_sessions WHERE company_id=? ORDER BY last_message_at DESC, id DESC LIMIT 50');
  $st->execute([$cid]);
  $conversations = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($view !== '') {
    $st = $pdo->prepare('SELECT * FROM (SELECT * FROM whatsapp_bot_messages WHERE company_id=? AND contact=? ORDER BY id DESC LIMIT 60) t ORDER BY id');
    $st->execute([$cid, $view]);
    $history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
$auto = $cfg['auto_messages'];
while (count($auto) < 4) $auto[] = ['label' => '', 'text' => ''];

admin_topbar('Chatbot do WhatsApp', 'whatsapp_chatbot');
?>
<style>
.wab-wrap{max-width:1180px;margin:14px auto 0;display:grid;gap:14px}.wab-card{background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.wab-card h2{margin:0 0 6px;color:#0b1f5b;font-size:20px}.wab-muted{color:#64748b;line-height:1.5;font-size:13px}
.wab-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.wab-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:4px}.wab-field input[type=text],.wab-field input[type=number],.wab-field select,.wab-field textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.wab-field textarea{min-height:80px}
.wab-switch{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;border:1px solid #dbe4f0;background:#f8fbff;font-weight:800;color:#0b1f5b}.wab-switch input{width:20px;height:20px}
.wab-opts{display:flex;gap:14px;flex-wrap:wrap}.wab-opts label{display:flex;gap:6px;align-items:center;font-size:14px}
.wab-auto{display:grid;grid-template-columns:220px 1fr;gap:8px;margin-bottom:8px}@media(max-width:700px){.wab-auto{grid-template-columns:1fr}}
.wab-table{width:100%;border-collapse:collapse;font-size:13px}.wab-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0}.wab-table td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:top}.wab-chip{display:inline-block;padding:3px 9px;border-radius:999px;font-weight:800;font-size:11px;background:#e2e8f0;color:#475569}.wab-chip.on{background:#fef3c7;color:#92400e}
.wab-chat{display:flex;flex-direction:column;gap:6px;max-height:460px;overflow:auto;background:#f1f5f9;border-radius:14px;padding:12px}.wab-bubble{max-width:75%;padding:8px 10px;border-radius:12px;white-space:pre-wrap;font-size:13px}.wab-IN{align-self:flex-start;background:#fff;border:1px solid #e2e8f0}.wab-OUT{align-self:flex-end;background:#dcfce7}.wab-bubble small{display:block;color:#64748b;font-size:11px;margin-top:3px}
</style>
<div class="wab-wrap">
<?php if ($msg !== ''): ?><div class="msg ok"><?= wab_h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= wab_h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="msg err">O chatbot ainda não foi instalado. Peça ao administrador da plataforma para aplicar "WhatsApp - Chatbot (atendimento automático)" no Atualizador do Banco.</div><?php endif; ?>
<?php if ($ready && !$onEngine): ?><div class="msg err">O WhatsApp desta empresa ainda não está no motor novo; o chatbot só funciona nele. Peça ao suporte para fazer a troca.</div><?php endif; ?>

<?php if ($ready): ?>
<form method="post" class="wab-card">
  <input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="save">
  <h2>Atendimento automático</h2>
  <div class="wab-muted" style="margin-bottom:12px">Quando um cliente manda mensagem para o WhatsApp da empresa, o robô responde com um menu numerado. Se alguém da empresa responder pelo celular ou WhatsApp Web, o robô fica em silêncio naquela conversa pelo tempo definido abaixo (o cliente pode digitar <b>menu</b> para voltar ao robô).</div>
  <label class="wab-switch"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Chatbot ligado</label>

  <div class="wab-field" style="margin-top:14px"><label>Mensagem de boas-vindas ({nome} = nome do contato, {empresa} = nome da empresa). O menu é incluído logo abaixo.</label><textarea name="welcome" maxlength="1000"><?= wab_h($cfg['welcome']) ?></textarea></div>

  <div class="wab-field" style="margin-top:14px"><label>Opções do menu</label>
    <div class="wab-opts">
      <label><input type="checkbox" name="opt_boleto" value="1" <?= $cfg['opt_boleto'] ? 'checked' : '' ?>> 2ª via de boleto (pede CPF/CNPJ)</label>
      <label><input type="checkbox" name="opt_pedido" value="1" <?= $cfg['opt_pedido'] ? 'checked' : '' ?>> Consultar pedido</label>
      <label><input type="checkbox" name="opt_crm" value="1" <?= $cfg['opt_crm'] ? 'checked' : '' ?>> Abrir atendimento (CRM)</label>
      <label><input type="checkbox" name="opt_atendente" value="1" <?= $cfg['opt_atendente'] ? 'checked' : '' ?>> Falar com atendente</label>
    </div>
  </div>

  <div class="wab-field" style="margin-top:14px"><label>Mensagens automáticas (aparecem no menu; o robô responde o texto cadastrado). Deixe o título em branco para remover.</label>
    <?php foreach ($auto as $a): ?>
      <div class="wab-auto"><input type="text" name="auto_label[]" maxlength="60" placeholder="Título no menu (ex.: Certificado Digital)" value="<?= wab_h($a['label']) ?>"><textarea name="auto_text[]" maxlength="3000" placeholder="Texto enviado ao cliente" style="min-height:60px"><?= wab_h($a['text']) ?></textarea></div>
    <?php endforeach; ?>
  </div>

  <div class="wab-grid" style="margin-top:14px">
    <div class="wab-field"><label>Resposta ao pedir atendente</label><textarea name="atendente_text" maxlength="1000"><?= wab_h($cfg['atendente_text']) ?></textarea></div>
    <div class="wab-field"><label>Robô em silêncio após atendente (horas)</label><input type="number" name="pause_hours" min="1" max="72" value="<?= (int)$cfg['pause_hours'] ?>"></div>
  </div>

  <div class="wab-grid" style="margin-top:14px">
    <div class="wab-field"><label>CRM: responsável pelos atendimentos abertos</label><select name="crm_responsible_user_id"><option value="0">Automático (administrador)</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$cfg['crm_responsible_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= wab_h($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="wab-field"><label>CRM: status inicial</label><select name="crm_status_id"><option value="0">Primeiro da lista</option><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$cfg['crm_status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= wab_h($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="wab-field"><label>CRM: tipo de atendimento</label><select name="crm_service_type_id"><option value="0">Primeiro da lista</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$cfg['crm_service_type_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= wab_h($t['name']) ?></option><?php endforeach; ?></select></div>
  </div>

  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Salvar</button><a class="btn" href="whatsapp.php">Configuração WhatsApp</a></div>
  <details style="margin-top:12px"><summary class="wab-muted" style="cursor:pointer">Como o cliente vê o menu</summary><pre class="wab-muted" style="white-space:pre-wrap;background:#f8fafc;border-radius:10px;padding:10px"><?= wab_h(whatsapp_bot_menu_text($cfg)) ?></pre></details>
</form>

<section class="wab-card">
  <h2>Conversas recentes</h2>
  <table class="wab-table">
    <thead><tr><th>Contato</th><th>Última mensagem</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php if (!$conversations): ?><tr><td colspan="4" class="wab-muted">Nenhuma conversa ainda.</td></tr><?php endif; ?>
    <?php foreach ($conversations as $c): $paused = !empty($c['paused_until']) && strtotime((string)$c['paused_until']) > time(); ?>
      <tr>
        <td><?= wab_h(wab_contact($c)) ?></td>
        <td style="white-space:nowrap"><?= wab_h(wab_dt($c['last_message_at'])) ?></td>
        <td><span class="wab-chip<?= $paused ? ' on' : '' ?>"><?= $paused ? 'Com atendente até ' . wab_h(date('d/m H:i', strtotime((string)$c['paused_until']))) : wab_h(WAB_STATES[(string)$c['state']] ?? (string)$c['state']) ?></span></td>
        <td style="white-space:nowrap;display:flex;gap:6px">
          <a class="btn" href="whatsapp_chatbot.php?ver=<?= rawurlencode((string)$c['contact']) ?>#historico">Ver conversa</a>
          <?php if ($paused): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= wab_h($csrf) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="contact" value="<?= wab_h($c['contact']) ?>"><button class="btn" type="submit">Devolver ao robô</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($view !== ''): ?>
<section class="wab-card" id="historico">
  <h2>Conversa</h2>
  <div class="wab-muted" style="margin-bottom:8px">Mostra o que o robô recebeu e respondeu (as mensagens digitadas pelos atendentes no celular não aparecem aqui).</div>
  <div class="wab-chat">
    <?php if (!$history): ?><div class="wab-muted">Sem mensagens.</div><?php endif; ?>
    <?php foreach ($history as $m): $dir = (string)$m['direction'] === 'OUT' ? 'OUT' : 'IN'; ?>
      <div class="wab-bubble wab-<?= $dir ?>"><?= wab_h($m['body']) ?><small><?= $dir === 'OUT' ? 'Robô' : 'Cliente' ?> · <?= wab_h(wab_dt($m['created_at'])) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
