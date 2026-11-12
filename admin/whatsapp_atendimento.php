<?php
declare(strict_types=1);

// WhatsApp > Atendimento: conversas no estilo WhatsApp Web (fila por setor,
// aceitar, conversar com texto e arquivos, transferir, gerar CRM, finalizar).
// Atualiza em tempo real pelo serviço nexa-realtime (sem consultas periódicas).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_atendimento.php';
require_once __DIR__ . '/../config/person_contacts.php';
if (is_file(__DIR__ . '/../config/brain_realtime.php')) require_once __DIR__ . '/../config/brain_realtime.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$supervisor = access_is_company_master() || access_has_permission($pdo, $cid, 'whatsapp_chatbot');
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_waa_csrf'])) $_SESSION['nexa_waa_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_waa_csrf'] ?? '');
$ready = whatsapp_bot_ready($pdo);
$rt = ['enabled' => false];
if (function_exists('nexa_realtime_browser_config')) {
  try { $rt = nexa_realtime_browser_config($cid, $uid); } catch (Throwable $e) {}
}
$myName = whatsapp_bot_user_name($pdo, $cid, $uid);
function waa_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

admin_topbar('Atendimento WhatsApp', 'whatsapp_atendimento');
?>
<style>
.waa{--g:#00a884;--bg:#f0f2f5;--line:#e9edef;--txt:#111b21;--mut:#667781;display:grid;grid-template-columns:var(--side,clamp(300px,28vw,420px)) 1fr;height:calc(100vh - 150px);height:calc(100dvh - 150px);min-height:420px;border:1px solid #d1d7db;border-radius:16px;overflow:hidden;background:#fff;color:var(--txt);margin-top:10px}
.waa *{box-sizing:border-box}
.waa-side{display:flex;flex-direction:column;border-right:1px solid var(--line);min-width:0;min-height:0;position:relative}
.waa-split{position:absolute;top:0;right:-5px;width:10px;height:100%;cursor:col-resize;z-index:4;touch-action:none}.waa-split::after{content:'';position:absolute;top:0;bottom:0;left:4px;width:2px;background:transparent;transition:background .15s}
.waa-split:hover::after,.waa.resizing .waa-split::after{background:#00a884}.waa.resizing{user-select:none;cursor:col-resize}.waa.resizing *{cursor:col-resize!important}
.waa-sidehead{padding:10px 12px;background:var(--bg);display:flex;gap:8px;align-items:center}
.waa-sidehead input{flex:1;border:0;border-radius:10px;padding:9px 12px;background:#fff;font:inherit}
.waa-btn{border:1px solid #d1d7db;background:#fff;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer;color:#0b1f5b;font-size:13px;white-space:nowrap}
.waa-btn.g{background:var(--g);border-color:var(--g);color:#fff}.waa-btn.r{color:#b91c1c}.waa-btn:disabled{opacity:.5;cursor:default}
.waa-tabs{display:flex;flex-wrap:wrap;gap:4px;padding:8px 10px;border-bottom:1px solid var(--line)}
.waa-tab{border:0;background:#f0f2f5;border-radius:999px;padding:6px 11px;font-weight:700;font-size:12px;cursor:pointer;color:#54656f;white-space:nowrap}
.waa-tab.on{background:#d9fdd3;color:#008069}.waa-tab b{background:var(--g);color:#fff;border-radius:999px;padding:1px 6px;margin-left:4px;font-size:11px}.waa-tab b.n{background:#8696a0}
.waa-list{flex:1;min-height:0;overflow-y:auto}
.waa-item{display:flex;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);cursor:pointer;align-items:center}
.waa-item:hover{background:#f5f6f6}.waa-item.on{background:#f0f2f5}
.waa-av{width:44px;height:44px;border-radius:50%;background:#dfe5e7;color:#54656f;display:flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 44px}
.waa-meta{flex:1;min-width:0}.waa-row{display:flex;justify-content:space-between;gap:6px;align-items:baseline}
.waa-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.waa-time{font-size:11px;color:var(--mut);white-space:nowrap}
.waa-prev{font-size:13px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.waa-tags{display:flex;gap:4px;margin-top:3px;flex-wrap:wrap}.waa-tag{font-size:10px;font-weight:800;border-radius:6px;padding:1px 6px;background:#eef2ff;color:#3730a3}.waa-tag.u{background:#fef3c7;color:#92400e}
.waa-unread{background:var(--g);color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:2px 7px}
.waa-empty{padding:30px 16px;color:var(--mut);text-align:center;font-size:14px}
.waa-main{display:flex;flex-direction:column;min-width:0;min-height:0;overflow:hidden;background:#efeae2}
.waa-head{background:var(--bg);padding:10px 14px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--line);flex-wrap:wrap}
.waa-head .waa-meta{min-width:160px}.waa-profile-open{cursor:pointer}.waa-head .waa-meta.waa-profile-open:hover .waa-name{text-decoration:underline}
.waa-card.wide{max-width:620px;max-height:92vh;overflow:auto}.waa-pf-top{display:flex;gap:12px;align-items:center}.waa-pf-top .waa-av{width:56px;height:56px;flex-basis:56px;font-size:20px}
.waa-pf-sec{border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;background:#fff}.waa-pf-sec h4{margin:0 0 6px;font-size:13px;color:#008069;letter-spacing:.02em;display:flex;justify-content:space-between;align-items:center;gap:8px}
.waa-pf-grid{display:grid;grid-template-columns:120px 1fr;gap:4px 10px;font-size:14px}.waa-pf-grid dt{color:#667781;font-size:12px;padding-top:2px}.waa-pf-grid dd{margin:0;word-break:break-word}
.waa-pf-list{display:grid;gap:4px;font-size:13px}.waa-pf-list div{display:flex;justify-content:space-between;gap:8px;border-bottom:1px solid #f0f2f5;padding:4px 0}.waa-pf-list div:last-child{border-bottom:0}
.waa-pf-sel{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 10px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:10px;font-weight:700}.waa-sub{font-size:12px;color:var(--mut)}
.waa-actions{display:flex;gap:6px;flex-wrap:wrap}
.waa-msgs{flex:1;min-height:0;overflow-y:auto;padding:16px 6%;display:flex;flex-direction:column;gap:4px}
.waa-b{max-width:65%;padding:6px 9px 4px;border-radius:8px;box-shadow:0 1px .5px rgba(11,20,26,.13);font-size:14px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;position:relative}
.waa-b.in{align-self:flex-start;background:#fff}.waa-b.out{align-self:flex-end;background:#d9fdd3}.waa-b.bot{background:#e7f0ff}.waa-b.fail{border:1px solid #ef4444}
.waa-b small{display:block;text-align:right;font-size:11px;color:var(--mut);margin-top:2px}
.waa-who{font-size:12px;font-weight:800;color:#008069;margin-bottom:2px}.waa-b.bot .waa-who{color:#2454d6}
.waa-sys{white-space:pre-wrap;align-self:center;background:#fff8c5;color:#54656f;font-size:12px;border-radius:8px;padding:4px 10px;margin:4px 0;text-align:center}
.waa-day{align-self:center;background:#fff;color:#54656f;font-size:12px;border-radius:8px;padding:3px 10px;margin:8px 0;box-shadow:0 1px .5px rgba(11,20,26,.13)}
.waa-b img,.waa-b video{max-width:280px;max-height:320px;border-radius:6px;display:block;cursor:pointer}.waa-b audio{width:260px}
.waa-doc{display:flex;gap:8px;align-items:center;background:rgba(0,0,0,.05);border-radius:6px;padding:8px;text-decoration:none;color:inherit}
.waa-compose{background:var(--bg);padding:8px 12px;display:flex;gap:8px;align-items:flex-end;position:relative}
.waa-quick{position:absolute;left:8px;right:8px;bottom:100%;margin-bottom:6px;background:#fff;border:1px solid #d1d7db;border-radius:12px;box-shadow:0 12px 30px rgba(11,20,26,.18);display:none;flex-direction:column;max-height:min(360px,55vh);z-index:6;overflow:hidden}.waa-quick.on{display:flex}
.waa-quick input{border:0;border-bottom:1px solid var(--line);padding:10px 12px;font:inherit;outline:0}.waa-quick.slash input{display:none}
#waaQuickList{overflow-y:auto}.waa-qi{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #f0f2f5;background:#fff;padding:8px 12px;cursor:pointer;font:inherit}.waa-qi.sel,.waa-qi:hover{background:#e7f8f2}
.waa-qi b{color:#008069}.waa-qi small{display:block;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px}.waa-qempty{padding:12px;color:var(--mut);font-size:13px}
.waa-recbar{background:var(--bg);padding:8px 12px;display:none;gap:10px;align-items:center}.waa-recbar.on{display:flex}
.waa-recdot{width:12px;height:12px;border-radius:50%;background:#ea0038;animation:waaBlink 1s infinite}#waaRecTime{font-weight:800;min-width:42px}@keyframes waaBlink{50%{opacity:.25}}
.waa-compose textarea{flex:1;border:0;border-radius:10px;padding:10px 12px;font:inherit;resize:none;max-height:140px;min-height:42px}
.waa-ico{border:0;background:transparent;font-size:22px;cursor:pointer;padding:6px;color:#54656f}
.waa-send{border:0;background:var(--g);color:#fff;border-radius:50%;width:44px;height:44px;font-size:18px;cursor:pointer}
.waa-filebar{background:#fff;border-top:1px solid var(--line);padding:8px 12px;display:none;gap:8px;align-items:center}.waa-filebar.on{display:flex}.waa-filebar input{flex:1;border:1px solid #d1d7db;border-radius:8px;padding:7px 10px}
.waa-welcome{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--mut);text-align:center;padding:30px;background:var(--bg)}
.waa-welcome h3{color:#41525d;margin:8px 0}
.waa-banner{background:#fff8c5;padding:8px 14px;font-size:13px;color:#54656f;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.waa-modal{--g:#00a884;--bg:#f0f2f5;--line:#e9edef;--txt:#111b21;--mut:#667781;position:fixed;inset:0;background:rgba(11,20,26,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}.waa-modal.on{display:flex}
.waa-card{background:#fff;border-radius:16px;padding:18px;width:100%;max-width:420px;display:grid;gap:10px}
.waa-cres{position:absolute;left:0;right:0;top:100%;z-index:5;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 24px rgba(0,0,0,.12);max-height:240px;overflow:auto}.waa-cres:empty{display:none}.waa-cres button{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #f1f5f9;background:#fff;padding:8px 10px;cursor:pointer;font:inherit}.waa-cres button:hover{background:#f0fdf4}.waa-cres button span{font-size:11px;font-weight:800;color:#3730a3;background:#eef2ff;border-radius:6px;padding:1px 6px}.waa-cres small{display:block;color:#64748b}.waa-cres-empty{padding:10px;color:#64748b;font-size:13px}
.waa-card h3{margin:0;color:#0b1f5b}.waa-card label{font-size:12px;font-weight:700;color:#334155}.waa-card select,.waa-card input,.waa-card textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;font:inherit}
.waa-rt{font-size:11px;color:var(--mut)}
.waa-answers{background:#eef6ff;border-bottom:1px solid var(--line);padding:8px 14px;font-size:13px;color:#1e3a5f;display:flex;gap:6px 16px;flex-wrap:wrap}.waa-answers b{color:#0b1f5b}
.waa-back{display:none}
@media(max-width:860px){.waa-split{display:none}.waa{grid-template-columns:1fr!important;height:calc(100vh - 120px)}.waa.chat-open .waa-side{display:none}.waa:not(.chat-open) .waa-main{display:none}.waa-back{display:inline-block}.waa-b{max-width:85%}.waa-msgs{padding:12px}}
</style>
<?php if (!$ready): ?>
<div class="msg err" style="margin-top:12px">O atendimento ainda não foi instalado. Peça ao administrador da plataforma para aplicar "WhatsApp - Atendimento (fluxo, setores e conversas)" no Atualizador do Banco.</div>
<?php else: ?>
<div class="waa" id="waa">
  <aside class="waa-side">
    <div class="waa-split" id="waaSplit" title="Arraste para ajustar a largura da lista (duplo clique volta ao padrão)"></div>
    <div class="waa-sidehead">
      <input id="waaSearch" placeholder="Pesquisar nome ou telefone" autocomplete="off">
      <button class="waa-btn g" type="button" id="waaNew" title="Iniciar conversa">+ Nova</button>
    </div>
    <div class="waa-tabs" id="waaTabs">
      <button class="waa-tab on" data-tab="fila">Fila</button>
      <button class="waa-tab" data-tab="meus">Meus</button>
      <button class="waa-tab" data-tab="atendimento" title="Todas as conversas em atendimento">Atendendo</button>
      <button class="waa-tab" data-tab="robo" title="Conversas com o robô (o número conta as das últimas 24 horas)">Robô</button>
      <button class="waa-tab" data-tab="finalizados" title="Conversas finalizadas (o número conta as de hoje)">Finalizados</button>
    </div>
    <div class="waa-list" id="waaList"><div class="waa-empty">Carregando…</div></div>
    <div class="waa-sidehead" style="justify-content:space-between"><span class="waa-rt" id="waaRt"></span><a class="waa-rt" href="whatsapp_chatbot.php">Configurar chatbot</a></div>
  </aside>
  <section class="waa-main" id="waaMain">
    <div class="waa-welcome" id="waaWelcome">
      <div style="font-size:54px">💬</div>
      <h3>Atendimento WhatsApp</h3>
      <div>Escolha uma conversa à esquerda. Na <b>Fila</b> ficam os clientes que pediram atendente; clique e depois em <b>Aceitar</b>.</div>
    </div>
    <div id="waaChat" style="display:none;flex:1;flex-direction:column;min-height:0">
      <div class="waa-head">
        <button class="waa-btn waa-back" type="button" id="waaBack">←</button>
        <div class="waa-av waa-profile-open" id="waaAv" title="Ver dados do contato"></div>
        <div class="waa-meta waa-profile-open" title="Ver dados do contato"><div class="waa-name"><span id="waaName"></span> <span id="waaTags"></span></div><div class="waa-sub" id="waaSub"></div></div>
        <div class="waa-actions">
          <button class="waa-btn g" type="button" id="waaAccept">Aceitar</button>
          <button class="waa-btn" type="button" id="waaTransfer">Transferir</button>
          <button class="waa-btn" type="button" id="waaCrm">Gerar CRM</button>
          <a class="waa-btn" id="waaCrmLink" target="_blank" style="display:none"></a>
          <button class="waa-btn r" type="button" id="waaFinish">Finalizar</button>
        </div>
      </div>
      <div class="waa-banner" id="waaBanner" style="display:none"></div>
      <div class="waa-answers" id="waaAnswers" style="display:none"></div>
      <div class="waa-msgs" id="waaMsgs"></div>
      <div class="waa-filebar" id="waaFileBar"><span id="waaFileName"></span><input id="waaCaption" placeholder="Legenda (opcional)"><button class="waa-btn g" type="button" id="waaFileSend">Enviar</button><button class="waa-btn" type="button" id="waaFileCancel">✕</button></div>
      <form class="waa-compose" id="waaForm">
        <input type="file" id="waaFile" hidden>
        <div class="waa-quick" id="waaQuick"><input id="waaQuickQ" placeholder="Buscar mensagem rápida" autocomplete="off"><div id="waaQuickList"></div></div>
        <button class="waa-ico" type="button" id="waaQuickBtn" title="Mensagens rápidas (ou digite / no início da mensagem)">⚡</button>
        <button class="waa-ico" type="button" id="waaAttach" title="Anexar arquivo">📎</button>
        <textarea id="waaText" rows="1" placeholder="Digite uma mensagem ou / para mensagens rápidas" title="Enter envia; Shift+Enter quebra a linha; / abre as mensagens rápidas"></textarea>
        <button class="waa-send" type="button" id="waaMic" title="Gravar áudio" style="display:none">🎤</button>
        <button class="waa-send" type="submit" id="waaSend" title="Enviar">➤</button>
      </form>
      <div class="waa-recbar" id="waaRecBar">
        <button class="waa-ico" type="button" id="waaRecCancel" title="Descartar áudio">🗑️</button>
        <span class="waa-recdot"></span><span id="waaRecTime">0:00</span><span class="waa-sub" style="flex:1">Gravando áudio…</span>
        <button class="waa-send" type="button" id="waaRecSend" title="Enviar áudio">➤</button>
      </div>
    </div>
  </section>
</div>

<div class="waa-modal" id="mTransfer"><div class="waa-card">
  <h3>Transferir conversa</h3>
  <div><label>Para o setor (vai para a fila do setor)</label><select id="tDep"></select></div>
  <div><label>Ou direto para um usuário</label><select id="tUser"></select></div>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="waa-btn" type="button" data-close>Cancelar</button><button class="waa-btn g" type="button" id="tGo">Transferir</button></div>
</div></div>
<div class="waa-modal" id="mFinish"><div class="waa-card">
  <h3>Finalizar atendimento</h3>
  <label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:600"><input type="checkbox" id="fNotify" checked style="width:auto"> Enviar a mensagem de encerramento ao cliente</label>
  <div class="waa-sub">Se o cliente mandar outra mensagem, ele volta para o robô.</div>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="waa-btn" type="button" data-close>Cancelar</button><button class="waa-btn r" type="button" id="fGo">Finalizar</button></div>
</div></div>
<div class="waa-modal" id="mCrm"><div class="waa-card">
  <h3>Gerar atendimento no CRM</h3>
  <div class="waa-sub">O histórico recente da conversa vai junto. Você fica como responsável.</div>
  <div><label>Observação (opcional)</label><textarea id="cNote" rows="3"></textarea></div>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="waa-btn" type="button" data-close>Cancelar</button><button class="waa-btn g" type="button" id="cGo">Gerar</button></div>
</div></div>
<div class="waa-modal" id="mProfile"><div class="waa-card wide">
  <div class="waa-pf-top"><div class="waa-av" id="pfAv"></div><div style="flex:1;min-width:0"><h3 id="pfName"></h3><div class="waa-sub" id="pfSub"></div></div><button class="waa-btn" type="button" data-close>✕</button></div>
  <div id="pfBody"><div class="waa-sub">Carregando…</div></div>
  <form id="pfForm" style="display:none;gap:8px" class="waa-pf-sec" autocomplete="off">
    <h4 id="pfFormTitle">Cadastrar contato</h4>
    <div><label>Nome do contato *</label><input id="pfFName" maxlength="120" required></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <div><label>Função</label><input id="pfFRole" maxlength="60" list="pfRoles" placeholder="Ex.: Financeiro, Contador"></div>
      <div><label>E-mail</label><input id="pfFEmail" maxlength="150" inputmode="email"></div>
    </div>
    <datalist id="pfRoles"><?php foreach (person_contact_roles() as $r): ?><option value="<?= waa_h($r) ?>"><?php endforeach; ?></datalist>
    <div style="position:relative"><label>Cliente (empresa ou pessoa a quem pertence)</label>
      <div id="pfFPersonSel" class="waa-pf-sel" style="display:none"><span id="pfFPersonName"></span><button class="waa-btn" type="button" id="pfFPersonClear">Trocar</button></div>
      <input id="pfFPersonQ" placeholder="Buscar por nome, fantasia ou CPF/CNPJ (opcional)"><div id="pfFPersonRes" class="waa-cres"></div>
    </div>
    <div><label>Observação</label><input id="pfFNotes" maxlength="500"></div>
    <div id="pfFTagsBox"><label>Etiquetas (ex.: VIP, Inadimplente — Enter para incluir)</label><input id="pfFTags" data-tag-input placeholder="Digite a etiqueta e tecle Enter"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end"><button class="waa-btn" type="button" id="pfFCancel">Cancelar</button><button class="waa-btn g" type="submit">Salvar contato</button></div>
  </form>
</div></div>
<div class="waa-modal" id="mNew"><div class="waa-card">
  <h3>Nova conversa</h3>
  <div style="position:relative"><label>Buscar contato (nome, cliente, telefone)</label><input id="nSearch" autocomplete="off" placeholder="Digite para buscar no cadastro de contatos e pessoas"><div id="nResults" class="waa-cres"></div></div>
  <div><label>Celular com DDD</label><input id="nPhone" inputmode="tel" placeholder="(31) 99999-9999"></div>
  <div><label>Mensagem</label><textarea id="nText" rows="3"></textarea></div>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="waa-btn" type="button" data-close>Cancelar</button><button class="waa-btn g" type="button" id="nGo">Enviar</button></div>
</div></div>

<script src="/assets/whatsapp-audio.js?v=20261112-1"></script>
<script src="/assets/tag-input.js?v=20261112-1"></script>
<script>
// A tela ocupa toda a altura disponível da janela (qualquer tamanho de tela/zoom).
(function () {
  const box = document.getElementById('waa'); if (!box) return;
  // Considera o zoom automático do painel (admin.css usa zoom .8 em notebooks):
  // mede a escala real (px na tela / px do CSS) e corrige a altura.
  const fit = () => {
    const want = window.innerHeight - 12; // onde a caixa deve terminar, na tela
    let css = parseFloat(box.style.height) || box.offsetHeight;
    for (let i = 0; i < 3; i++) {
      const r = box.getBoundingClientRect();
      const scale = r.height > 0 && css > 0 ? r.height / css : 1;
      const diff = want - (r.top + r.height);
      if (Math.abs(diff) < 1.5) break;
      css = Math.max(380, css + diff / scale);
      box.style.height = Math.floor(css) + 'px';
    }
  };
  fit();
  window.addEventListener('resize', fit);
  // Divisória arrastável entre a lista de conversas e a conversa (lembrada neste navegador).
  const split = document.getElementById('waaSplit');
  const KEY = 'nexa_waa_side_width';
  const apply = (px) => {
    const max = Math.max(260, box.clientWidth * 0.6);
    const w = Math.round(Math.min(max, Math.max(240, px)));
    box.style.setProperty('--side', w + 'px');
    return w;
  };
  try { const saved = parseInt(localStorage.getItem(KEY) || '', 10); if (saved > 0) apply(saved); } catch (_) {}
  if (split) {
    let dragging = false, left = 0, scale = 1;
    split.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      const r = box.getBoundingClientRect();
      left = r.left; scale = box.offsetWidth > 0 ? r.width / box.offsetWidth : 1; // zoom automático do painel
      dragging = true; box.classList.add('resizing');
      try { split.setPointerCapture(e.pointerId); } catch (_) {}
    });
    split.addEventListener('pointermove', (e) => { if (dragging) apply((e.clientX - left) / scale); });
    const end = () => {
      if (!dragging) return;
      dragging = false; box.classList.remove('resizing');
      const w = parseInt(getComputedStyle(box).getPropertyValue('--side'), 10);
      try { if (w > 0) localStorage.setItem(KEY, String(w)); } catch (_) {}
    };
    split.addEventListener('pointerup', end);
    split.addEventListener('pointercancel', end);
    split.addEventListener('dblclick', () => { box.style.removeProperty('--side'); try { localStorage.removeItem(KEY); } catch (_) {} });
  }
  window.addEventListener('load', fit);
  setTimeout(fit, 300);
})();
</script>
<script>
(() => {
  const API = 'whatsapp_atendimento_api.php';
  const CSRF = <?= json_encode($csrf) ?>;
  const ME = <?= (int)$uid ?>;
  const SUP = <?= $supervisor ? 'true' : 'false' ?>;
  const RT = <?= json_encode(['enabled' => !empty($rt['enabled']), 'url' => (string)($rt['url'] ?? ''), 'token' => (string)($rt['token'] ?? ''), 'companyId' => $cid], JSON_UNESCAPED_SLASHES) ?>;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtWa = (s) => esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/_([^_\n]+)_/g, '<i>$1</i>').replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  const ini = (n) => String(n || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0] || '').join('').toUpperCase();
  const tm = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); const t = new Date(); return d.toDateString() === t.toDateString() ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }); };
  const hm = (s) => new Date(String(s).replace(' ', 'T')).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  const day = (s) => new Date(String(s).replace(' ', 'T')).toLocaleDateString('pt-BR');
  const STATUS = { BOT: 'No robô', PENDENTE: 'Na fila', ATENDIMENTO: 'Em atendimento', FINALIZADO: 'Finalizado' };
  let tab = 'fila', q = '', chatId = 0, lastId = 0, lastDay = '', chat = null, listTimer = 0, busy = false, options = null;

  async function api(action, params = {}, post = false, file = null) {
    let res;
    if (post) {
      const fd = new FormData();
      fd.append('action', action);
      Object.entries(params).forEach(([k, v]) => fd.append(k, v));
      if (file) fd.append('file', file);
      res = await fetch(API, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF }, credentials: 'same-origin' });
    } else {
      res = await fetch(API + '?' + new URLSearchParams({ action, ...params }), { credentials: 'same-origin' });
    }
    let data = null;
    try { data = await res.json(); } catch (_) { data = { ok: false, error: 'Resposta inválida do servidor (' + res.status + ').' }; }
    if (!data.ok) throw new Error(data.error || 'Falha.');
    return data;
  }
  const fail = (e) => alert(e.message || e);

  async function loadList() {
    try {
      const d = await api('list', { tab, q });
      document.querySelectorAll('.waa-tab').forEach((b) => {
        const n = d.counts[b.dataset.tab];
        b.classList.toggle('on', b.dataset.tab === tab);
        const quiet = b.dataset.tab === 'robo' || b.dataset.tab === 'finalizados';
        b.innerHTML = esc(b.textContent.replace(/\d+$/, '').trim()) + (n ? ' <b' + (quiet ? ' class="n"' : '') + '>' + n + '</b>' : '');
      });
      const fila = d.counts.fila || 0, meus = d.counts.meus || 0;
      document.title = (fila + meus > 0 ? '(' + (fila + meus) + ') ' : '') + 'Atendimento WhatsApp';
      const list = $('waaList');
      if (!d.items.length) { list.innerHTML = '<div class="waa-empty">Nenhuma conversa aqui.</div>'; return; }
      list.innerHTML = d.items.map((c) => `
        <div class="waa-item${c.id === chatId ? ' on' : ''}" data-id="${c.id}">
          <div class="waa-av">${esc(ini(c.name))}</div>
          <div class="waa-meta">
            <div class="waa-row"><span class="waa-name">${esc(c.name)}</span><span class="waa-time">${esc(tm(c.last_at))}</span></div>
            <div class="waa-row"><span class="waa-prev">${c.last_direction === 'OUT' ? '↪ ' : ''}${esc(c.preview)}</span>${c.unread ? '<span class="waa-unread">' + c.unread + '</span>' : ''}</div>
            ${c.company_label || c.contact_role ? '<div class="waa-prev" style="font-size:12px">' + esc([c.contact_role, c.company_label].filter(Boolean).join(' · ')) + '</div>' : ''}
            <div class="waa-tags">${(c.tags || []).length && window.NexaTags ? NexaTags.html(c.tags.join(',')) : ''}${c.department ? '<span class="waa-tag">' + esc(c.department) + '</span>' : ''}${c.user ? '<span class="waa-tag u">' + esc(c.user) + '</span>' : (c.status === 'ATENDIMENTO' ? '<span class="waa-tag u">Celular</span>' : '')}</div>
          </div>
        </div>`).join('');
    } catch (e) { $('waaList').innerHTML = '<div class="waa-empty">' + esc(e.message) + '</div>'; }
  }
  const scheduleList = () => { clearTimeout(listTimer); listTimer = setTimeout(loadList, 300); };

  function bubble(m) {
    let html = '';
    const d = day(m.at);
    if (d !== lastDay) { lastDay = d; html += '<div class="waa-day">' + esc(d) + '</div>'; }
    if (m.sender === 'sistema') return html + '<div class="waa-sys">' + esc(m.body) + ' · ' + esc(hm(m.at)) + '</div>';
    const out = m.dir === 'OUT';
    const who = out ? (m.sender === 'robo' ? '🤖 Robô' : m.sender === 'celular' ? '📱 Celular' : (m.user || 'Atendente')) : '';
    let media = '';
    if (m.media) {
      const src = 'whatsapp_midia.php?id=' + m.id;
      if (m.type === 'image' || m.type === 'sticker') media = '<a href="' + src + '" target="_blank"><img src="' + src + '" alt="foto" loading="lazy"></a>';
      else if (m.type === 'audio') media = '<audio controls preload="none" src="' + src + '"></audio>';
      else if (m.type === 'video') media = '<video controls preload="none" src="' + src + '"></video>';
      else media = '<a class="waa-doc" href="' + src + '&download=1">📄 <span>' + esc(m.name || 'Documento') + (m.size ? ' · ' + Math.max(1, Math.round(m.size / 1024)) + ' KB' : '') + '</span></a>';
    } else if (['image', 'audio', 'video', 'document', 'sticker'].includes(m.type)) {
      media = '<div class="waa-sub">' + ({ image: '📷 Foto', audio: '🎤 Áudio', video: '🎬 Vídeo', document: '📄 ' + esc(m.name || 'Documento'), sticker: 'Figurinha' }[m.type]) + '</div>';
    }
    return html + `<div class="waa-b ${out ? 'out' : 'in'}${m.sender === 'robo' ? ' bot' : ''}${m.ok ? '' : ' fail'}">${who ? '<div class="waa-who">' + esc(who) + '</div>' : ''}${media}${m.body ? '<div>' + fmtWa(m.body) + '</div>' : ''}<small>${esc(hm(m.at))}${m.ok ? '' : ' · não enviado'}</small></div>`;
  }

  function renderHead() {
    const c = chat;
    $('waaAv').textContent = ini(c.name);
    $('waaName').textContent = c.name;
    const parts = [c.contact_role, c.company_label, c.phone, STATUS[c.status] || c.status];
    if (c.department) parts.push(c.department);
    if (c.user) parts.push('com ' + c.user); else if (c.status === 'ATENDIMENTO') parts.push('respondido pelo celular');
    $('waaSub').textContent = parts.filter(Boolean).join(' · ');
    $('waaTags').innerHTML = (c.tags || []).length && window.NexaTags ? NexaTags.html(c.tags.join(',')) : '';
    const mine = c.status === 'ATENDIMENTO' && c.user_id === ME;
    const other = c.status === 'ATENDIMENTO' && c.user_id > 0 && c.user_id !== ME;
    $('waaAccept').style.display = mine ? 'none' : '';
    $('waaAccept').textContent = other ? 'Assumir' : 'Aceitar';
    $('waaAccept').disabled = other && !SUP;
    $('waaTransfer').disabled = other && !SUP;
    $('waaFinish').disabled = (other && !SUP) || c.status === 'FINALIZADO';
    $('waaCrm').style.display = c.crm_attendance_id ? 'none' : '';
    $('waaCrmLink').style.display = c.crm_attendance_id ? '' : 'none';
    if (c.crm_attendance_id) { $('waaCrmLink').href = 'crm_atendimento.php?id=' + c.crm_attendance_id; $('waaCrmLink').textContent = 'CRM nº ' + c.crm_attendance_id; }
    const b = $('waaBanner');
    if (other) { b.style.display = ''; b.textContent = 'Esta conversa está com ' + c.user + '.' + (SUP ? ' Como supervisor você pode assumir.' : ''); }
    else if (c.status === 'PENDENTE') { b.style.display = ''; b.textContent = 'Cliente aguardando na fila' + (c.department ? ' de ' + c.department : '') + '. Clique em Aceitar ou simplesmente responda.'; }
    else if (c.status === 'BOT') { b.style.display = ''; b.textContent = 'O robô está atendendo. Se você responder, a conversa passa para você.'; }
    else b.style.display = 'none';
    $('waaText').disabled = other && !SUP;
    const ans = Array.isArray(c.answers) ? c.answers : [];
    $('waaAnswers').style.display = ans.length ? '' : 'none';
    $('waaAnswers').innerHTML = ans.length ? '<span>📝 Respostas do fluxo:</span>' + ans.map((a) => '<span><b>' + esc(a.label) + ':</b> ' + esc(a.value) + '</span>').join('') : '';
  }

  async function openChat(id) {
    if (id !== chatId) { cancelRec(); closeQuick(); }
    chatId = id; lastId = 0; lastDay = '';
    $('waa').classList.add('chat-open');
    $('waaWelcome').style.display = 'none';
    $('waaChat').style.display = 'flex';
    $('waaMsgs').innerHTML = '<div class="waa-sys">Carregando…</div>';
    document.querySelectorAll('.waa-item').forEach((el) => el.classList.toggle('on', Number(el.dataset.id) === id));
    try {
      const d = await api('chat', { id });
      if (chatId !== id) return;
      chat = d.chat; renderHead();
      $('waaMsgs').innerHTML = d.messages.map(bubble).join('') || '<div class="waa-sys">Sem mensagens.</div>';
      if (d.messages.length) lastId = d.messages[d.messages.length - 1].id;
      $('waaMsgs').scrollTop = $('waaMsgs').scrollHeight;
      scheduleList();
    } catch (e) { $('waaMsgs').innerHTML = '<div class="waa-sys">' + esc(e.message) + '</div>'; }
  }

  async function refreshChat() {
    if (!chatId || busy) return;
    busy = true;
    try {
      const d = await api('chat', { id: chatId, after: lastId });
      chat = d.chat; renderHead();
      if (d.messages.length) {
        const box = $('waaMsgs');
        const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        box.insertAdjacentHTML('beforeend', d.messages.map(bubble).join(''));
        lastId = d.messages[d.messages.length - 1].id;
        if (atBottom || d.messages.some((m) => m.dir === 'OUT')) box.scrollTop = box.scrollHeight;
      }
    } catch (_) {} finally { busy = false; }
  }

  async function act(action, params = {}, file = null) {
    try { const d = await api(action, { id: chatId, ...params }, true, file); if (d.warning) alert(d.warning); await refreshChat(); scheduleList(); return d; }
    catch (e) { fail(e); return null; }
  }

  // Lista
  $('waaTabs').addEventListener('click', (e) => { const b = e.target.closest('.waa-tab'); if (!b) return; tab = b.dataset.tab; loadList(); });
  $('waaList').addEventListener('click', (e) => { const it = e.target.closest('.waa-item'); if (it) openChat(Number(it.dataset.id)); });
  let st = 0; $('waaSearch').addEventListener('input', (e) => { clearTimeout(st); st = setTimeout(() => { q = e.target.value; loadList(); }, 350); });
  $('waaBack').addEventListener('click', () => { $('waa').classList.remove('chat-open'); chatId = 0; loadList(); });

  // Envio
  const text = $('waaText');
  const rec = (window.NexaAudio && NexaAudio.Recorder.supported()) ? new NexaAudio.Recorder() : null;
  // Como no WhatsApp: com a caixa vazia aparece o microfone; com texto, o botão de enviar.
  const toggleSend = () => { const empty = text.value.trim() === ''; $('waaMic').style.display = rec && empty ? '' : 'none'; $('waaSend').style.display = rec && empty ? 'none' : ''; };
  const resize = () => { text.style.height = 'auto'; text.style.height = Math.min(140, text.scrollHeight) + 'px'; };
  text.addEventListener('input', () => {
    resize(); toggleSend();
    const m = text.value.match(/^\/(\S*)$/);
    if (m) openQuick('slash', m[1]); else if (qMode === 'slash') closeQuick();
  });
  text.addEventListener('keydown', (e) => {
    if (quickKey(e)) return;
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('waaForm').requestSubmit(); }
  });
  $('waaForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const v = text.value.trim();
    if (!v || !chatId) return;
    closeQuick();
    text.value = ''; resize(); toggleSend();
    const d = await act('send', { text: v });
    if (!d) { text.value = v; toggleSend(); }
  });

  // Mensagens rápidas: "/" no início da mensagem ou o botão ⚡.
  let quick = null, qMode = '', qSel = 0, qList = [];
  async function loadQuick() {
    if (quick) return quick;
    try { quick = await api('quick'); } catch (e) { quick = null; fail(e); }
    return quick;
  }
  const fillVars = (t) => {
    const nm = String((chat && chat.name) || '').trim();
    const first = /^[+\d\s()-]+$/.test(nm) ? '' : nm.split(/\s+/)[0];
    return String(t)
      .replace(/\{nome\}/gi, first)
      .replace(/\{atendente\}/gi, (quick && quick.me) || '')
      .replace(/\{empresa\}/gi, (quick && quick.company) || '')
      .replace(/[ \t]+([,.!?])/g, '$1');
  };
  function renderQuick(filter) {
    const f = String(filter || '').toLowerCase().trim();
    const items = (quick && quick.items) || [];
    qList = items.filter((it) => !f || it.shortcut.includes(f) || it.title.toLowerCase().includes(f) || it.text.toLowerCase().includes(f));
    if (f) qList.sort((a, b) => (b.shortcut.startsWith(f) ? 1 : 0) - (a.shortcut.startsWith(f) ? 1 : 0));
    qSel = Math.min(qSel, Math.max(0, qList.length - 1));
    const box = $('waaQuickList');
    if (!qList.length) {
      box.innerHTML = '<div class="waa-qempty">' + (items.length ? 'Nenhuma mensagem rápida com "' + esc(f) + '".' : 'Nenhuma mensagem rápida cadastrada. Cadastre em <a href="whatsapp_chatbot.php?aba=rapidas">Chatbot → Mensagens rápidas</a>.') + '</div>';
      return;
    }
    box.innerHTML = qList.map((it, i) => '<button type="button" class="waa-qi' + (i === qSel ? ' sel' : '') + '" data-i="' + i + '"><b>/' + esc(it.shortcut) + '</b> ' + esc(it.title) + '<small>' + esc(fillVars(it.text).replace(/\s+/g, ' ')) + '</small></button>').join('');
    const sel = box.querySelector('.sel'); if (sel) sel.scrollIntoView({ block: 'nearest' });
  }
  async function openQuick(mode, filter) {
    if (!chatId) return;
    await loadQuick();
    if (mode === 'slash' && !/^\/\S*$/.test(text.value)) return;
    if (qMode !== mode) qSel = 0;
    qMode = mode;
    $('waaQuick').classList.add('on');
    $('waaQuick').classList.toggle('slash', mode === 'slash');
    if (mode === 'button') { $('waaQuickQ').value = filter || ''; $('waaQuickQ').focus(); }
    renderQuick(filter);
  }
  function closeQuick() { qMode = ''; $('waaQuick').classList.remove('on'); }
  function pickQuick(i) {
    const it = qList[i]; if (!it) return;
    closeQuick();
    text.value = fillVars(it.text);
    resize(); toggleSend(); text.focus();
    text.setSelectionRange(text.value.length, text.value.length);
  }
  function quickKey(e) {
    if (!qMode) return false;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (qList.length) { qSel = (qSel + (e.key === 'ArrowDown' ? 1 : -1) + qList.length) % qList.length; renderQuick(qMode === 'slash' ? text.value.slice(1) : $('waaQuickQ').value); }
      return true;
    }
    if ((e.key === 'Enter' || e.key === 'Tab') && qList.length) { e.preventDefault(); pickQuick(qSel); return true; }
    if (e.key === 'Escape') { e.preventDefault(); closeQuick(); text.focus(); return true; }
    return false;
  }
  $('waaQuickBtn').addEventListener('click', () => { if (qMode === 'button') { closeQuick(); return; } openQuick('button', ''); });
  $('waaQuickQ').addEventListener('input', (e) => { qSel = 0; renderQuick(e.target.value); });
  $('waaQuickQ').addEventListener('keydown', (e) => { quickKey(e); });
  $('waaQuickList').addEventListener('mousedown', (e) => { const b = e.target.closest('.waa-qi'); if (b) { e.preventDefault(); pickQuick(Number(b.dataset.i)); } });
  document.addEventListener('mousedown', (e) => { if (qMode && !e.target.closest('#waaQuick') && e.target.id !== 'waaQuickBtn' && e.target !== text) closeQuick(); });

  // Áudio (mensagem de voz).
  let recTimer = 0, recChat = 0;
  const recUi = (on) => {
    $('waaForm').style.display = on ? 'none' : '';
    $('waaRecBar').classList.toggle('on', on);
    clearInterval(recTimer);
    if (on) {
      const tick = () => { const s = rec.seconds(); $('waaRecTime').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); if (s >= 900) sendRec(); };
      tick(); recTimer = setInterval(tick, 500);
    }
  };
  function cancelRec() { if (!rec || !recChat) return; rec.cancel(); recChat = 0; recUi(false); }
  async function sendRec() {
    if (!rec || !recChat) return;
    const target = recChat; recChat = 0; recUi(false);
    let f;
    try { f = await rec.stop(); } catch (e) { fail(e); return; }
    if (f.size > 16 * 1024 * 1024) { alert('O áudio ficou maior que 16 MB.'); return; }
    if (target !== chatId) return;
    $('waaMic').disabled = true;
    await act('file', { voice: 1 }, f);
    $('waaMic').disabled = false;
  }
  $('waaMic').addEventListener('click', async () => {
    if (!rec || !chatId || recChat) return;
    try { await rec.start(); } catch (e) { alert('Não foi possível usar o microfone. Permita o acesso ao microfone para este site no navegador (ícone ao lado do endereço) e tente de novo.'); return; }
    recChat = chatId; closeQuick(); recUi(true);
  });
  $('waaRecCancel').addEventListener('click', cancelRec);
  $('waaRecSend').addEventListener('click', sendRec);
  toggleSend();
  let pending = null;
  $('waaAttach').addEventListener('click', () => $('waaFile').click());
  $('waaFile').addEventListener('change', (e) => {
    pending = e.target.files[0] || null;
    if (!pending) return;
    if (pending.size > 16 * 1024 * 1024) { alert('O arquivo deve ter no máximo 16 MB.'); pending = null; e.target.value = ''; return; }
    $('waaFileName').textContent = '📎 ' + pending.name;
    $('waaFileBar').classList.add('on'); $('waaCaption').focus();
  });
  const clearFile = () => { pending = null; $('waaFile').value = ''; $('waaCaption').value = ''; $('waaFileBar').classList.remove('on'); };
  $('waaFileCancel').addEventListener('click', clearFile);
  $('waaFileSend').addEventListener('click', async () => {
    if (!pending) return;
    const f = pending, cap = $('waaCaption').value;
    $('waaFileSend').disabled = true;
    await act('file', { caption: cap }, f);
    $('waaFileSend').disabled = false; clearFile();
  });

  // Dados completos do contato (clique no nome/foto do topo da conversa).
  let profile = null, pfPerson = 0;
  const dl = (rows) => '<dl class="waa-pf-grid">' + rows.filter((r) => r[1]).map((r) => '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>').join('') + '</dl>';
  function renderProfile() {
    const p = profile, c = p.contact, pe = p.person;
    $('pfAv').textContent = ini(chat ? chat.name : '');
    $('pfName').textContent = c ? c.name : (pe ? pe.name : (p.push_name || p.phone || 'Contato'));
    $('pfSub').textContent = [c && c.role, p.phone, p.push_name && (c || pe) ? 'no WhatsApp: ' + p.push_name : ''].filter(Boolean).join(' · ');
    let h = '';
    if (c) {
      h += '<div class="waa-pf-sec"><h4>Contato <button class="waa-btn" type="button" id="pfEdit">Editar</button></h4>' + dl([['Nome', c.name], ['Função', c.role], ['WhatsApp', c.whatsapp], ['Telefone', c.phone], ['E-mail', c.email], ['Observação', c.notes], ['Principal', c.is_primary ? 'Sim' : '']]) + (c.tags && window.NexaTags ? '<dl class="waa-pf-grid"><dt>Etiquetas</dt><dd>' + NexaTags.html(c.tags) + '</dd></dl>' : '') + '</div>';
    } else if (p.contacts_ready) {
      h += '<div class="waa-pf-sec"><h4>Contato <button class="waa-btn g" type="button" id="pfEdit">Cadastrar contato</button></h4><div class="waa-sub">Este número ainda não está nos Contatos. Cadastre para identificar quem é e de qual cliente, nas próximas conversas também.</div></div>';
    }
    if (pe) {
      h += '<div class="waa-pf-sec"><h4>Cliente <a class="waa-btn" href="' + esc(pe.url) + '" target="_blank" rel="noopener">Abrir cadastro</a></h4>' + dl([['Nome / Razão', pe.name], ['Fantasia', pe.fantasy_name], [pe.person_type === 'FISICA' ? 'CPF' : 'CPF/CNPJ', pe.document], ['E-mail', pe.email], ['Telefone', pe.phone], ['WhatsApp', pe.whatsapp], ['Endereço', pe.address], ['Observações', pe.notes]]) + '</div>';
    } else {
      h += '<div class="waa-pf-sec"><h4>Cliente</h4><div class="waa-sub">Conversa ainda não ligada a um cliente do cadastro de pessoas.' + (p.contacts_ready ? ' Use <b>' + (c ? 'Editar' : 'Cadastrar contato') + '</b> e escolha o cliente.' : '') + '</div></div>';
    }
    if (p.contacts.length) {
      h += '<div class="waa-pf-sec"><h4>Outros contatos do cliente</h4><div class="waa-pf-list">' + p.contacts.map((o) => '<div><span><b>' + esc(o.name) + '</b>' + (o.role ? ' · ' + esc(o.role) : '') + '</span><span class="waa-sub">' + esc([o.phone, o.email].filter(Boolean).join(' · ')) + '</span></div>').join('') + '</div></div>';
    }
    $('pfBody').innerHTML = h;
    $('pfBody').style.display = 'grid'; $('pfBody').style.gap = '10px';
    $('pfForm').style.display = 'none';
    const ed = $('pfEdit'); if (ed) ed.addEventListener('click', openPfForm);
  }
  async function openProfile() {
    if (!chatId) return;
    $('pfBody').innerHTML = '<div class="waa-sub">Carregando…</div>'; $('pfForm').style.display = 'none';
    $('pfAv').textContent = ini(chat ? chat.name : ''); $('pfName').textContent = chat ? chat.name : ''; $('pfSub').textContent = '';
    open('mProfile');
    try { const d = await api('profile', { id: chatId }); profile = d.profile; renderProfile(); }
    catch (e) { $('pfBody').innerHTML = '<div class="waa-sub">' + esc(e.message) + '</div>'; }
  }
  const pfSetPerson = (id, name) => {
    pfPerson = id || 0;
    $('pfFPersonName').textContent = name || '';
    $('pfFPersonSel').style.display = pfPerson ? 'flex' : 'none';
    $('pfFPersonQ').style.display = pfPerson ? 'none' : '';
    $('pfFPersonRes').innerHTML = ''; if (!pfPerson) $('pfFPersonQ').value = '';
  };
  function openPfForm() {
    const c = profile.contact, pe = profile.person;
    $('pfFormTitle').textContent = c ? 'Editar contato' : 'Cadastrar contato';
    $('pfFName').value = c ? c.name : (profile.push_name || '');
    $('pfFRole').value = c ? c.role : '';
    $('pfFEmail').value = c ? c.email : '';
    $('pfFNotes').value = c ? c.notes : '';
    $('pfFTagsBox').style.display = profile.tags_ready ? '' : 'none';
    if (window.NexaTags) { const tg = $('pfFTags'); const api = NexaTags.attach(tg); if (api) api.setSuggest(profile.all_tags || []); tg.value = c ? (c.tags || '') : ''; NexaTags.sync(tg); }
    pfSetPerson(pe ? pe.id : 0, pe ? pe.name + (pe.document ? ' · ' + pe.document : '') : '');
    $('pfBody').style.display = 'none';
    $('pfForm').style.display = 'grid';
    $('pfFName').focus();
  }
  $('waaAv').addEventListener('click', openProfile);
  document.querySelector('.waa-head .waa-meta').addEventListener('click', openProfile);
  $('pfFCancel').addEventListener('click', () => { if (profile) renderProfile(); });
  $('pfFPersonClear').addEventListener('click', () => { pfSetPerson(0, ''); $('pfFPersonQ').focus(); });
  let pfTimer = 0;
  $('pfFPersonQ').addEventListener('input', () => {
    clearTimeout(pfTimer);
    const q = $('pfFPersonQ').value.trim();
    if (q.length < 2) { $('pfFPersonRes').innerHTML = ''; return; }
    pfTimer = setTimeout(async () => {
      try {
        const d = await api('people', { q });
        $('pfFPersonRes').innerHTML = (d.items || []).map((r, i) => `<button type="button" data-i="${i}"><b>${esc(r.name)}</b><small>${esc([r.fantasy, r.document].filter(Boolean).join(' · '))}</small></button>`).join('') || '<div class="waa-cres-empty">Nenhum cliente encontrado.</div>';
        $('pfFPersonRes').querySelectorAll('button').forEach((b) => b.addEventListener('click', () => { const r = d.items[+b.dataset.i]; pfSetPerson(r.id, r.name + (r.document ? ' · ' + r.document : '')); }));
      } catch (e) { $('pfFPersonRes').innerHTML = '<div class="waa-cres-empty">' + esc(e.message) + '</div>'; }
    }, 300);
  });
  $('pfForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = await act('contact_save', { name: $('pfFName').value, role: $('pfFRole').value, email: $('pfFEmail').value, notes: $('pfFNotes').value, person_id: pfPerson, ...(profile && profile.tags_ready ? { tags: $('pfFTags').value } : {}) });
    if (!d) return;
    scheduleList();
    try { const r = await api('profile', { id: chatId }); profile = r.profile; renderProfile(); } catch (_) {}
  });

  // Ações
  const open = (id) => $(id).classList.add('on');
  document.querySelectorAll('.waa-modal').forEach((m) => m.addEventListener('click', (e) => { if (e.target === m || e.target.hasAttribute('data-close')) m.classList.remove('on'); }));
  $('waaAccept').addEventListener('click', () => act('accept'));
  $('waaTransfer').addEventListener('click', async () => {
    try {
      options = options || await api('options');
      $('tDep').innerHTML = '<option value="">— escolha um setor —</option><option value="0">Fila geral (sem setor)</option>' + options.departments.map((d) => '<option value="' + d.id + '">' + esc(d.name) + '</option>').join('');
      $('tUser').innerHTML = '<option value="">— ou escolha um usuário —</option>' + options.users.filter((u) => Number(u.id) !== ME).map((u) => '<option value="' + u.id + '">' + esc(u.name) + '</option>').join('');
      open('mTransfer');
    } catch (e) { fail(e); }
  });
  $('tGo').addEventListener('click', async () => {
    const dep = $('tDep').value, user = $('tUser').value;
    if (dep === '' && user === '') { alert('Escolha um setor ou um usuário.'); return; }
    $('mTransfer').classList.remove('on');
    await act('transfer', { department_id: user ? 0 : dep, user_id: user || 0 });
  });
  $('waaFinish').addEventListener('click', () => open('mFinish'));
  $('fGo').addEventListener('click', async () => { $('mFinish').classList.remove('on'); await act('finish', { notify: $('fNotify').checked ? 1 : '' }); });
  $('waaCrm').addEventListener('click', () => { $('cNote').value = ''; open('mCrm'); });
  $('cGo').addEventListener('click', async () => { $('mCrm').classList.remove('on'); const d = await act('crm', { note: $('cNote').value }); if (d) alert('Atendimento nº ' + d.crm_id + ' gerado no CRM.'); });
  const startNew = (phone = '', who = '') => { $('nPhone').value = phone; $('nText').value = ''; $('nSearch').value = who; $('nResults').innerHTML = ''; open('mNew'); (phone ? $('nText') : $('nSearch')).focus(); };
  $('waaNew').addEventListener('click', () => startNew());
  // Busca no cadastro de Contatos (quem chama, contador...) e no WhatsApp/telefone das pessoas.
  let nTimer = null;
  $('nSearch').addEventListener('input', () => {
    clearTimeout(nTimer);
    const q = $('nSearch').value.trim();
    if (q.length < 2) { $('nResults').innerHTML = ''; return; }
    nTimer = setTimeout(async () => {
      try {
        const d = await api('contacts', { q });
        $('nResults').innerHTML = (d.items || []).map((r, i) => `<button type="button" data-i="${i}"><b>${esc(r.name)}</b>${r.role ? ' <span>' + esc(r.role) + '</span>' : ''}<small>${esc([r.company, r.document, r.phone].filter(Boolean).join(' · '))}</small></button>`).join('') || '<div class="waa-cres-empty">Nada encontrado.</div>';
        $('nResults').querySelectorAll('button').forEach((b) => b.addEventListener('click', () => { const r = d.items[+b.dataset.i]; $('nPhone').value = r.phone; $('nSearch').value = r.name + (r.company ? ' · ' + r.company : ''); $('nResults').innerHTML = ''; $('nText').focus(); }));
      } catch (e) { $('nResults').innerHTML = '<div class="waa-cres-empty">' + esc(e.message) + '</div>'; }
    }, 250);
  });
  // Aberto pela tela de Contatos do Chatbot ("Chamar").
  try { const u = new URL(location.href); if (u.searchParams.get('novo')) startNew(u.searchParams.get('novo'), u.searchParams.get('nome') || ''); } catch (_) {}
  $('nGo').addEventListener('click', async () => {
    try {
      const d = await api('new', { phone: $('nPhone').value, text: $('nText').value }, true);
      $('mNew').classList.remove('on'); tab = 'meus'; await loadList(); openChat(d.id);
    } catch (e) { fail(e); }
  });

  // Tempo real (sem consultas periódicas): o servidor avisa quando algo muda.
  let beepCtx = null;
  const beep = () => { try { beepCtx = beepCtx || new (window.AudioContext || window.webkitAudioContext)(); const o = beepCtx.createOscillator(), g = beepCtx.createGain(); o.frequency.value = 880; g.gain.value = .08; o.connect(g); g.connect(beepCtx.destination); o.start(); o.stop(beepCtx.currentTime + .15); } catch (_) {} };
  function connectRt(attempt = 0) {
    if (!RT.enabled) { $('waaRt').textContent = 'Atualização automática indisponível — a lista atualiza ao voltar para esta aba.'; return; }
    let ws;
    try { ws = new WebSocket(RT.url + (RT.url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(RT.token)); } catch (_) { return setTimeout(() => connectRt(attempt + 1), Math.min(30000, 1000 * 2 ** Math.min(attempt, 5))); }
    ws.addEventListener('open', () => { attempt = 0; $('waaRt').textContent = '● Tempo real conectado'; loadList(); if (chatId) refreshChat(); });
    ws.addEventListener('message', (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch (_) { return; }
      if (!m || m.eventType !== 'whatsapp.chat.updated' || m.replay) return;
      scheduleList();
      if (Number(m.entityId) === chatId) refreshChat();
      const p = m.payload || {};
      if (p.new_message && p.status === 'PENDENTE' && document.hidden) beep();
    });
    ws.addEventListener('close', () => { $('waaRt').textContent = '● Tempo real desconectado — reconectando…'; setTimeout(() => connectRt(attempt + 1), Math.min(30000, 1000 * 2 ** Math.min(attempt + 1, 5))); });
    ws.addEventListener('error', () => { try { ws.close(); } catch (_) {} });
  }
  window.addEventListener('focus', () => { loadList(); if (chatId) refreshChat(); });
  document.addEventListener('pointerdown', () => { try { beepCtx = beepCtx || new (window.AudioContext || window.webkitAudioContext)(); beepCtx.resume(); } catch (_) {} }, { once: true });

  const startId = Number(new URLSearchParams(location.search).get('id') || 0);
  loadList().then(() => { if (startId) openChat(startId); });
  connectRt();
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>
