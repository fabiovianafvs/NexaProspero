<?php
declare(strict_types=1);

// WhatsApp > Chatbot > Fluxo: frases de gatilho e passos (perguntas, mensagens
// e ação final). Sempre da empresa de login (sem F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_chatbot.php';

$cid = (int)COMPANY_ID;
function wfx_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_wab_csrf'])) $_SESSION['nexa_wab_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_wab_csrf'] ?? '');
$ready = whatsapp_bot_ready($pdo) && whatsapp_bot_flows_ready($pdo);
$flowId = max(0, (int)($_GET['id'] ?? 0));
$msg = isset($_GET['ok']) ? (string)$_GET['ok'] : '';
$err = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $go = static function (int $id, string $ok) { header('Location: whatsapp_chatbot_fluxo.php?id=' . $id . '&ok=' . rawurlencode($ok) . '#passos'); exit; };
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');
    $postFlow = (int)($_POST['flow_id'] ?? 0);
    switch ($action) {
      case 'flow_save':
        $id = whatsapp_bot_flow_save($pdo, $cid, ['id' => $postFlow] + $_POST);
        $go($id, $postFlow > 0 ? 'Fluxo salvo.' : 'Fluxo criado. Agora cadastre os passos.');
      case 'step_save':
        whatsapp_bot_flow_step_save($pdo, $cid, $postFlow, $_POST + ['active' => 0]);
        $go($postFlow, 'Passo salvo.');
      case 'step_delete':
        whatsapp_bot_flow_step_delete($pdo, $cid, $postFlow, (int)($_POST['id'] ?? 0));
        $go($postFlow, 'Passo excluído.');
      case 'step_up':
      case 'step_down':
        whatsapp_bot_flow_step_move($pdo, $cid, $postFlow, (int)($_POST['id'] ?? 0), $action === 'step_up' ? -1 : 1);
        $go($postFlow, 'Ordem alterada.');
    }
    throw new RuntimeException('Ação inválida.');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$flow = $ready && $flowId > 0 ? whatsapp_bot_flow($pdo, $cid, $flowId) : null;
if ($ready && $flowId > 0 && !$flow) $err = 'Fluxo não encontrado.';
$steps = $flow ? whatsapp_bot_flow_steps($pdo, $cid, (int)$flow['id'], false) : [];
$types = whatsapp_bot_step_types();
$answerTypes = whatsapp_bot_answer_types();
$departments = $ready ? whatsapp_departments($pdo, $cid) : [];
$depNames = [];
foreach ($departments as $d) $depNames[(int)$d['id']] = (string)$d['name'];
$lastIsFinal = $steps && !in_array((string)end($steps)['step_type'], ['pergunta', 'text'], true);

admin_topbar($flow ? 'Fluxo: ' . $flow['name'] : 'Novo fluxo do chatbot', 'whatsapp_chatbot');
?>
<style>
.wfx{max-width:1100px;margin:14px auto 0;display:grid;gap:14px}.wfx-card{background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.wfx-card h2{margin:0 0 6px;color:#0b1f5b;font-size:20px}.wfx-muted{color:#64748b;line-height:1.5;font-size:13px}
.wfx-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:4px}.wfx-field input[type=text],.wfx-field select,.wfx-field textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.wfx-field textarea{min-height:90px}
.wfx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.wfx-steps{display:grid;gap:8px}.wfx-step{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:start;border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px}.wfx-step.off{opacity:.55}
.wfx-n{width:32px;height:32px;border-radius:50%;background:#2454d6;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}.wfx-step.final .wfx-n{background:#00a884}
.wfx-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-weight:800;font-size:11px;background:#eef2ff;color:#3730a3;margin-right:6px}
.wfx-msg{white-space:pre-wrap;font-size:14px;margin-top:4px}.wfx-actions{display:flex;gap:4px;flex-wrap:wrap}.wfx-actions form{margin:0}.wfx-mini{padding:4px 9px!important;font-size:12px!important}
.wfx-arrow{text-align:center;color:#94a3b8;font-size:12px;margin:-4px 0}
.wfx-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}.wfx-modal.on{display:flex}.wfx-modal .wfx-card{width:100%;max-width:560px;max-height:92vh;overflow:auto;display:grid;gap:10px}
</style>
<div class="wfx">
<?php if ($msg !== ''): ?><div class="msg ok"><?= wfx_h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= wfx_h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="msg err">Aplique "WhatsApp - Fluxos e repetir pedido" no Atualizador do Banco para usar os fluxos.</div><?php else: ?>
<div><a class="btn" href="whatsapp_chatbot.php?aba=fluxos">← Voltar à lista de fluxos</a></div>

<form method="post" class="wfx-card">
  <input type="hidden" name="csrf" value="<?= wfx_h($csrf) ?>"><input type="hidden" name="action" value="flow_save"><input type="hidden" name="flow_id" value="<?= (int)($flow['id'] ?? 0) ?>">
  <h2><?= $flow ? 'Dados do fluxo' : 'Novo fluxo' ?></h2>
  <div class="wfx-grid">
    <div class="wfx-field"><label>Nome (só para você identificar)</label><input type="text" name="name" maxlength="80" required value="<?= wfx_h($flow['name'] ?? '') ?>" placeholder="Ex.: Anúncio Instagram - Conhecer a solução"></div>
    <div class="wfx-field"><label>Como comparar a mensagem</label><select name="match_mode"><option value="contem" <?= ($flow['match_mode'] ?? 'contem') !== 'exata' ? 'selected' : '' ?>>A mensagem CONTÉM a frase (recomendado)</option><option value="exata" <?= ($flow['match_mode'] ?? '') === 'exata' ? 'selected' : '' ?>>A mensagem é EXATAMENTE a frase</option></select></div>
  </div>
  <div class="wfx-field" style="margin-top:10px"><label>Frases de gatilho — uma por linha (maiúsculas, acentos e pontuação não importam). Deixe em branco se o fluxo for aberto só por uma opção do menu.</label><textarea name="triggers" placeholder="Olá, gostaria de conhecer melhor a solução&#10;quero saber mais sobre o sistema"><?= wfx_h($flow['triggers'] ?? '') ?></textarea></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px;margin-top:8px"><input type="checkbox" name="active" value="1" <?= !$flow || (int)$flow['active'] ? 'checked' : '' ?>> Fluxo ativo</label>
  <div style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= $flow ? 'Salvar' : 'Criar fluxo' ?></button></div>
</form>

<?php if ($flow): ?>
<section class="wfx-card" id="passos">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start">
    <div><h2>Passos</h2><div class="wfx-muted">O robô segue de cima para baixo. <b>Pergunta</b> espera a resposta do cliente; <b>Mensagem</b> segue direto. Use as respostas nas mensagens seguintes com <b>{nome_do_campo}</b> — ex.: pergunta com campo <b>nome</b> → "Prazer, {nome}!". Termine com uma ação (ex.: <b>Transferir para setor</b>); se não houver, o robô mostra o menu principal.</div></div>
    <button class="btn btn-primary" type="button" onclick="wfxStep({})">+ Adicionar passo</button>
  </div>
  <div class="wfx-steps" style="margin-top:12px">
    <?php if (!$steps): ?><div class="wfx-muted">Nenhum passo ainda. Exemplo: 1) Pergunta "Olá, seja bem-vindo! Qual o seu nome?" (campo nome) → 2) Pergunta "Prazer, {nome}! Qual o seu segmento?" (campo segmento) → 3) Transferir para o setor Comercial.</div><?php endif; ?>
    <?php foreach ($steps as $i => $st): $final = !in_array($st['step_type'], ['pergunta', 'text'], true); ?>
      <?php if ($i > 0): ?><div class="wfx-arrow">↓</div><?php endif; ?>
      <div class="wfx-step<?= $final ? ' final' : '' ?><?= (int)$st['active'] ? '' : ' off' ?>">
        <div class="wfx-n"><?= $i + 1 ?></div>
        <div>
          <span class="wfx-chip"><?= wfx_h(explode(' (', explode(' —', $types[$st['step_type']] ?? $st['step_type'])[0])[0]) ?></span>
          <?php if ($st['step_type'] === 'pergunta'): ?><span class="wfx-muted">campo: <b><?= wfx_h($st['var_name'] ?: '(sem nome)') ?></b> · <?= wfx_h($answerTypes[$st['answer_type']] ?? 'Texto livre') ?></span><?php endif; ?>
          <?php if ($st['step_type'] === 'setor'): ?><span class="wfx-muted"><?= wfx_h($depNames[(int)$st['department_id']] ?? 'Fila geral') ?></span><?php endif; ?>
          <?php if (!(int)$st['active']): ?><span class="wfx-chip" style="background:#e2e8f0;color:#64748b">inativo</span><?php endif; ?>
          <?php if (trim((string)$st['message']) !== ''): ?><div class="wfx-msg"><?= wfx_h($st['message']) ?></div><?php endif; ?>
        </div>
        <div class="wfx-actions">
          <?php foreach (['step_up' => '↑', 'step_down' => '↓'] as $a => $lbl): ?><form method="post"><input type="hidden" name="csrf" value="<?= wfx_h($csrf) ?>"><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="flow_id" value="<?= (int)$flow['id'] ?>"><input type="hidden" name="id" value="<?= (int)$st['id'] ?>"><button class="btn wfx-mini" type="submit"><?= $lbl ?></button></form><?php endforeach; ?>
          <button class="btn wfx-mini" type="button" onclick='wfxStep(<?= wfx_h(json_encode(['id' => (int)$st['id'], 'step_type' => $st['step_type'], 'message' => (string)$st['message'], 'var_name' => (string)$st['var_name'], 'answer_type' => (string)$st['answer_type'], 'department_id' => (int)$st['department_id'], 'active' => (int)$st['active']], JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
          <form method="post" onsubmit="return confirm('Excluir este passo?');"><input type="hidden" name="csrf" value="<?= wfx_h($csrf) ?>"><input type="hidden" name="action" value="step_delete"><input type="hidden" name="flow_id" value="<?= (int)$flow['id'] ?>"><input type="hidden" name="id" value="<?= (int)$st['id'] ?>"><button class="btn btn-danger wfx-mini" type="submit">Excluir</button></form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($steps && !$lastIsFinal): ?><div class="wfx-arrow">↓</div><div class="wfx-muted" style="text-align:center">Sem ação final: o robô mostra o menu principal ao terminar.</div><?php endif; ?>
  </div>
</section>

<div class="wfx-modal" id="mStep"><form method="post" class="wfx-card">
  <input type="hidden" name="csrf" value="<?= wfx_h($csrf) ?>"><input type="hidden" name="action" value="step_save"><input type="hidden" name="flow_id" value="<?= (int)$flow['id'] ?>"><input type="hidden" name="id" id="sId">
  <h2 id="sTitle">Passo</h2>
  <div class="wfx-field"><label>Tipo</label><select name="step_type" id="sType" onchange="wfxType()"><?php foreach ($types as $k => $lbl): ?><option value="<?= wfx_h($k) ?>"><?= wfx_h($lbl) ?></option><?php endforeach; ?></select></div>
  <div class="wfx-field"><label id="sMsgLabel">Mensagem</label><textarea name="message" id="sMsg" maxlength="3000"></textarea><div class="wfx-muted" id="sHint"></div></div>
  <div class="wfx-grid" id="sAskBox">
    <div class="wfx-field"><label>Guardar a resposta no campo</label><input type="text" name="var_name" id="sVar" maxlength="40" placeholder="ex.: nome, empresa, interesse"></div>
    <div class="wfx-field"><label>Tipo de resposta esperada</label><select name="answer_type" id="sAns"><?php foreach ($answerTypes as $k => $lbl): ?><option value="<?= wfx_h($k) ?>"><?= wfx_h($lbl) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="wfx-field" id="sDepBox"><label>Setor</label><select name="department_id" id="sDep"><option value="0">Fila geral (qualquer atendente)</option><?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= wfx_h($d['name']) ?></option><?php endforeach; ?></select></div>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px"><input type="checkbox" name="active" value="1" id="sActive"> Passo ativo</label>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn" type="button" onclick="this.closest('.wfx-modal').classList.remove('on')">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
</form></div>

<script>
const WFX = {
  pergunta: ['Pergunta que o robô faz', 'Ex.: Olá, seja bem-vindo! Qual o seu nome?'],
  text: ['Mensagem', 'Enviada e o robô segue para o próximo passo sem esperar resposta.'],
  setor: ['Mensagem ao transferir (opcional)', 'Em branco usa a mensagem padrão de transferência. As respostas vão junto para o atendente.'],
  menu: ['Mensagem antes do menu (opcional)', ''],
  boleto: ['Pergunta do robô (opcional)', 'Em branco: pede o CPF/CNPJ do titular e envia os boletos em aberto.'],
  pedido: ['Pergunta do robô (opcional)', 'Em branco: pede o número do pedido.'],
  repetir_pedido: ['Mensagem antes (opcional)', 'Mostra o último pedido com os preços de hoje e pede confirmação; cria o pedido em aberto para a loja confirmar.'],
  crm: ['Pergunta do robô (opcional)', 'Em branco: pede para descrever o que precisa e abre um atendimento no CRM.'],
};
function wfxType() {
  const t = document.getElementById('sType').value;
  document.getElementById('sAskBox').style.display = t === 'pergunta' ? '' : 'none';
  document.getElementById('sDepBox').style.display = t === 'setor' ? '' : 'none';
  document.getElementById('sMsgLabel').textContent = (WFX[t] || ['Mensagem'])[0];
  document.getElementById('sHint').textContent = (WFX[t] || ['', ''])[1];
}
function wfxStep(s) {
  document.getElementById('sId').value = s.id || '';
  document.getElementById('sType').value = s.step_type || 'pergunta';
  document.getElementById('sMsg').value = s.message || '';
  document.getElementById('sVar').value = s.var_name || '';
  document.getElementById('sAns').value = s.answer_type || 'texto';
  document.getElementById('sDep').value = String(s.department_id || 0);
  document.getElementById('sActive').checked = s.id ? !!s.active : true;
  document.getElementById('sTitle').textContent = s.id ? 'Editar passo' : 'Novo passo';
  wfxType();
  document.getElementById('mStep').classList.add('on');
  document.getElementById('sMsg').focus();
}
document.querySelectorAll('.wfx-modal').forEach((m) => m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('on'); }));
</script>
<?php endif; ?>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
