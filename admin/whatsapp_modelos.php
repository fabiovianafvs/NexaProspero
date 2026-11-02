<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/email_service.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'whatsapp_config')
  : (int)COMPANY_ID;
$waCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'whatsapp_config');
$waMcCsrf = ($waCrossContext && function_exists('multi_company_context_csrf_token'))
  ? multi_company_context_csrf_token()
  : '';
$waMcCtx = ($waCrossContext && function_exists('multi_company_context_request_token'))
  ? multi_company_context_request_token()
  : '';
$msg = trim((string)($_GET['ok'] ?? ''));
$err = '';
$waState = whatsapp_message_models_load($pdo, $cid);
$emailState = email_message_models_load($pdo, $cid);
$waContexts = whatsapp_message_model_contexts();
$emailContexts = email_message_model_contexts();

function wam_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function wam_channel_label(string $channel): string { return $channel === 'email' ? 'E-mail' : 'WhatsApp'; }
function wam_url(string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($waCrossContext && (!function_exists('multi_company_context_csrf_valid')
        || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('V11.22.1: sessão de segurança expirada. Atualize a página antes de alterar modelos da empresa selecionada.');
    }
    $action = (string)($_POST['action'] ?? 'save');
    $channel = strtolower(trim((string)($_POST['channel'] ?? 'whatsapp')));
    if (!in_array($channel,['whatsapp','email'],true)) throw new RuntimeException('Canal de envio inválido.');

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Modelo inválido.');
      if ($channel === 'email') {
        $state = email_message_models_load($pdo,$cid);
        $state['items'] = array_values(array_filter((array)$state['items'], static fn($item) => (int)($item['id'] ?? 0) !== $id));
        foreach ((array)$state['defaults'] as $key=>$defaultId) if ((int)$defaultId === $id) $state['defaults'][$key] = 0;
        email_message_models_save($pdo,$cid,$state);
      } else {
        $state = whatsapp_message_models_load($pdo,$cid);
        $state['items'] = array_values(array_filter((array)$state['items'], static fn($item) => (int)($item['id'] ?? 0) !== $id));
        foreach ((array)$state['defaults'] as $key=>$defaultId) if ((int)$defaultId === $id) $state['defaults'][$key] = 0;
        whatsapp_message_models_save($pdo,$cid,$state);
      }
      header('Location: ' . wam_url('whatsapp_modelos.php?ok=' . urlencode('Modelo de mensagem excluído.')));
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $context = strtolower(trim((string)($_POST['context'] ?? '')));
    $title = trim((string)($_POST['title'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $active = isset($_POST['active']);
    $isDefault = isset($_POST['is_default']);

    $contexts = $channel === 'email' ? $emailContexts : $waContexts;
    if (!isset($contexts[$context])) throw new RuntimeException('Selecione o tipo de documento/origem do modelo.');
    if ($title === '') throw new RuntimeException('Informe o título do modelo.');
    if ($channel === 'email' && $subject === '') throw new RuntimeException('Informe o assunto do e-mail.');
    if ($message === '') throw new RuntimeException('Informe a mensagem do modelo.');
    if (mb_strlen($title) > 120) throw new RuntimeException('O título deve possuir no máximo 120 caracteres.');
    if (mb_strlen($subject) > 255) throw new RuntimeException('O assunto deve possuir no máximo 255 caracteres.');
    if (mb_strlen($message) > 20000) throw new RuntimeException('A mensagem deve possuir no máximo 20.000 caracteres.');

    if ($channel === 'email') {
      $state = email_message_models_load($pdo,$cid);
      $found = false;
      foreach ($state['items'] as &$item) {
        if ($id > 0 && (int)($item['id'] ?? 0) === $id) {
          $item = ['id'=>$id,'context'=>$context,'title'=>$title,'subject'=>$subject,'message'=>$message,'active'=>$active];
          $found = true; break;
        }
      }
      unset($item);
      if (!$found) {
        $id = max(1,(int)($state['next_id'] ?? 1));
        $state['next_id'] = $id + 1;
        $state['items'][] = ['id'=>$id,'context'=>$context,'title'=>$title,'subject'=>$subject,'message'=>$message,'active'=>$active];
      }
      if ($isDefault && $active) $state['defaults'][$context] = $id;
      elseif ((int)($state['defaults'][$context] ?? 0) === $id && (!$active || !$isDefault)) $state['defaults'][$context] = 0;
      email_message_models_save($pdo,$cid,$state);
    } else {
      $state = whatsapp_message_models_load($pdo,$cid);
      $found = false;
      foreach ($state['items'] as &$item) {
        if ($id > 0 && (int)($item['id'] ?? 0) === $id) {
          $item = ['id'=>$id,'context'=>$context,'title'=>$title,'message'=>$message,'active'=>$active];
          $found = true; break;
        }
      }
      unset($item);
      if (!$found) {
        $id = max(1,(int)($state['next_id'] ?? 1));
        $state['next_id'] = $id + 1;
        $state['items'][] = ['id'=>$id,'context'=>$context,'title'=>$title,'message'=>$message,'active'=>$active];
      }
      if ($isDefault && $active) $state['defaults'][$context] = $id;
      elseif ((int)($state['defaults'][$context] ?? 0) === $id && (!$active || !$isDefault)) $state['defaults'][$context] = 0;
      whatsapp_message_models_save($pdo,$cid,$state);
    }

    header('Location: ' . wam_url('whatsapp_modelos.php?ok=' . urlencode($found ? 'Modelo de mensagem atualizado.' : 'Modelo de mensagem cadastrado.')));
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
    $waState = whatsapp_message_models_load($pdo,$cid);
    $emailState = email_message_models_load($pdo,$cid);
  }
}

$showForm = isset($_GET['new']) || isset($_GET['edit']) || $err !== '';
$editId = (int)($_GET['edit'] ?? 0);
$editChannel = strtolower(trim((string)($_GET['channel'] ?? $_POST['channel'] ?? 'whatsapp')));
if (!in_array($editChannel,['whatsapp','email'],true)) $editChannel='whatsapp';
$edit = null;
if ($editId > 0) {
  $source = $editChannel === 'email' ? $emailState : $waState;
  foreach ((array)$source['items'] as $item) if ((int)($item['id'] ?? 0) === $editId) { $edit=$item; break; }
  $showForm = true;
}

$formChannel = strtolower(trim((string)($editChannel ?: ($_POST['channel'] ?? 'whatsapp'))));
$formContexts = $formChannel === 'email' ? $emailContexts : $waContexts;
$defaultContext = $formChannel === 'email' ? 'pedido' : 'boleto';
$queryContext = strtolower(trim((string)($_GET['context'] ?? '')));
if ($queryContext !== '' && isset($formContexts[$queryContext])) $defaultContext = $queryContext;
$form = [
  'id'=>(int)($edit['id'] ?? ($_POST['id'] ?? 0)),
  'channel'=>$formChannel,
  'context'=>(string)($edit['context'] ?? ($_POST['context'] ?? $defaultContext)),
  'title'=>(string)($edit['title'] ?? ($_POST['title'] ?? '')),
  'subject'=>(string)($edit['subject'] ?? ($_POST['subject'] ?? '')),
  'message'=>(string)($edit['message'] ?? ($_POST['message'] ?? '')),
  'active'=>$edit ? !empty($edit['active']) : ($_SERVER['REQUEST_METHOD']==='POST' ? isset($_POST['active']) : true),
];
if (!isset($formContexts[$form['context']])) $form['context']=$defaultContext;
$formDefault = false;
if ((int)$form['id'] > 0) $formDefault = $formChannel === 'email' ? ((int)($emailState['defaults'][$form['context']] ?? 0) === (int)$form['id']) : ((int)($waState['defaults'][$form['context']] ?? 0) === (int)$form['id']);
if ($_SERVER['REQUEST_METHOD']==='POST' && $err!=='') $formDefault = isset($_POST['is_default']);

$items = [];
foreach ((array)$waState['items'] as $item) { $item['channel']='whatsapp'; $items[]=$item; }
foreach ((array)$emailState['items'] as $item) { $item['channel']='email'; $items[]=$item; }
usort($items, static function($a,$b): int {
  $c = strcmp((string)$a['channel'],(string)$b['channel']);
  if ($c !== 0) return $c;
  $c = strcasecmp((string)$a['context'],(string)$b['context']);
  return $c !== 0 ? $c : strcasecmp((string)$a['title'],(string)$b['title']);
});

admin_topbar('Modelos de Mensagens','whatsapp_modelos');
?>
<style>
.wam-wrap{max-width:1180px;margin:14px auto 0;display:grid;gap:16px}.wam-card{background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.wam-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.wam-head h2{margin:0;color:#0b1f5b;font-size:24px}.wam-muted{color:#64748b;line-height:1.5}.wam-list{display:grid;gap:10px;margin-top:14px}.wam-row{border:1px solid #dbe4f0;border-radius:16px;padding:14px;display:grid;grid-template-columns:minmax(230px,310px) minmax(0,1fr) auto;gap:14px;align-items:start}.wam-row-title{font-weight:900;color:#0b1f5b}.wam-row-subject{font-weight:850;color:#334155;margin-bottom:5px}.wam-row-message{white-space:pre-wrap;color:#475569;max-height:118px;overflow:hidden}.wam-row-actions{display:flex;gap:7px;flex-wrap:wrap}.wam-pill{display:inline-flex;border-radius:999px;padding:4px 8px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:900;margin-top:6px}.wam-pill.off{background:#f1f5f9;color:#64748b}.wam-pill.ctx{background:#eff6ff;color:#1d4ed8;margin-right:6px}.wam-pill.email{background:#f5f3ff;color:#6d28d9}.wam-pill.wa{background:#ecfdf5;color:#047857}.wam-pill.default{background:#fff7ed;color:#c2410c}.wam-form{display:grid;gap:12px;margin-top:14px}.wam-grid{display:grid;grid-template-columns:minmax(180px,240px) minmax(220px,300px) minmax(0,1fr);gap:12px}.wam-form label{display:grid;gap:6px;font-weight:850;color:#0f172a}.wam-form input,.wam-form textarea,.wam-form select{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;font:inherit}.wam-form textarea{min-height:220px;resize:vertical;font-family:ui-monospace,Consolas,monospace;line-height:1.45}.wam-actions{display:flex;gap:8px;flex-wrap:wrap}.wam-vars{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.wam-vars code{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:800}.wam-empty{padding:20px;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b}.wam-check{display:flex!important;align-items:center;gap:8px}.wam-check input{width:auto!important}.msg{padding:12px 14px;border-radius:14px;font-weight:850}.msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:900px){.wam-row,.wam-grid{grid-template-columns:1fr}.wam-row-actions{justify-content:flex-start}}
</style>
<div class="wam-wrap">
  <?php if($waCrossContext): ?><div class="msg ok"><strong>V11.22.1 · Modelos Multiempresa:</strong> WhatsApp e E-mail desta tela pertencem à empresa selecionada pelo F11 (ID <?= (int)$cid ?>).</div><?php endif; ?>
  <?php if($msg!==''): ?><div class="msg ok"><?= wam_h($msg) ?></div><?php endif; ?>
  <?php if($err!==''): ?><div class="msg err"><?= wam_h($err) ?></div><?php endif; ?>
  <section class="wam-card">
    <div class="wam-head">
      <div><h2>Modelos de mensagens</h2><div class="wam-muted">Cadastre mensagens para <b>WhatsApp</b> ou <b>E-mail</b> e vincule ao documento correspondente. Os modelos atuais do WhatsApp foram preservados.</div></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="<?= wam_h(wam_url('whatsapp.php')) ?>">Configuração WhatsApp</a><a class="btn btn-primary" href="<?= wam_h(wam_url('whatsapp_modelos.php?new=1&channel=email')) ?>">Novo modelo</a></div>
    </div>
    <div class="wam-list">
      <?php if(!$items): ?><div class="wam-empty">Nenhum modelo cadastrado ainda.</div>
      <?php else: foreach($items as $item):
        $channel=(string)($item['channel'] ?? 'whatsapp');
        $context=(string)($item['context'] ?? '');
        $ctxLabel=$channel==='email' ? (string)($emailContexts[$context] ?? $context) : whatsapp_message_model_context_label($context);
        $isDef=$channel==='email' ? ((int)($emailState['defaults'][$context] ?? 0)===(int)($item['id'] ?? 0)) : ((int)($waState['defaults'][$context] ?? 0)===(int)($item['id'] ?? 0));
      ?>
        <article class="wam-row">
          <div>
            <div class="wam-row-title"><?= wam_h($item['title'] ?? '') ?></div>
            <div><span class="wam-pill <?= $channel==='email'?'email':'wa' ?>"><?= wam_h(wam_channel_label($channel)) ?></span> <span class="wam-pill ctx"><?= wam_h($ctxLabel) ?></span> <span class="wam-pill<?= !empty($item['active'])?'':' off' ?>"><?= !empty($item['active'])?'Ativo':'Inativo' ?></span><?php if($isDef): ?> <span class="wam-pill default">Padrão</span><?php endif; ?></div>
          </div>
          <div><?php if($channel==='email'): ?><div class="wam-row-subject">Assunto: <?= wam_h($item['subject'] ?? '') ?></div><?php endif; ?><div class="wam-row-message"><?= wam_h($item['message'] ?? '') ?></div></div>
          <div class="wam-row-actions"><a class="btn" href="<?= wam_h(wam_url('whatsapp_modelos.php?edit=' . (int)$item['id'] . '&channel=' . rawurlencode($channel))) ?>">Editar</a><form method="post" onsubmit="return confirm('Excluir este modelo de mensagem?');"><?php if($waCrossContext): ?><input type="hidden" name="mc_ctx" value="<?= wam_h($waMcCtx) ?>"><input type="hidden" name="mc_csrf" value="<?= wam_h($waMcCsrf) ?>"><?php endif; ?><input type="hidden" name="action" value="delete"><input type="hidden" name="channel" value="<?= wam_h($channel) ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-danger" type="submit">Excluir</button></form></div>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <?php if($showForm): ?>
  <section class="wam-card">
    <div class="wam-head"><div><h2><?= $edit?'Editar modelo':'Novo modelo' ?></h2><div class="wam-muted">Defina o canal e o tipo de documento. Para e-mail, informe também o assunto.</div></div><a class="btn" href="<?= wam_h(wam_url('whatsapp_modelos.php')) ?>">Voltar para a lista</a></div>
    <form method="post" class="wam-form">
      <?php if($waCrossContext): ?><input type="hidden" name="mc_ctx" value="<?= wam_h($waMcCtx) ?>"><input type="hidden" name="mc_csrf" value="<?= wam_h($waMcCsrf) ?>"><?php endif; ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <div class="wam-grid">
        <label>Canal<select name="channel" id="wam-channel" required><option value="whatsapp" <?= $form['channel']==='whatsapp'?'selected':'' ?>>WhatsApp</option><option value="email" <?= $form['channel']==='email'?'selected':'' ?>>E-mail</option></select></label>
        <label>Documento / uso<select name="context" id="wam-context" required></select></label>
        <label>Título<input name="title" maxlength="120" value="<?= wam_h($form['title']) ?>" placeholder="Ex.: NF-e - envio ao cliente" required></label>
      </div>
      <label id="wam-subject-wrap">Assunto do e-mail<input name="subject" id="wam-subject" maxlength="255" value="<?= wam_h($form['subject']) ?>" placeholder="Ex.: NF-e {{numero}} - {{empresa}}"></label>
      <label>Mensagem<textarea name="message" maxlength="20000" placeholder="Digite a mensagem e use as variáveis disponíveis..." required><?= wam_h($form['message']) ?></textarea></label>
      <div><div style="font-weight:850;color:#0f172a">Variáveis disponíveis</div>
        <?php foreach($waContexts as $ctxKey=>$ctxLabel): ?><div class="wam-vars" data-wam-vars="whatsapp:<?= wam_h($ctxKey) ?>" style="display:none"><?php foreach(whatsapp_message_model_variables($ctxKey) as $var): ?><code><?= wam_h($var) ?></code><?php endforeach; ?></div><?php endforeach; ?>
        <?php foreach($emailContexts as $ctxKey=>$ctxLabel): ?><div class="wam-vars" data-wam-vars="email:<?= wam_h($ctxKey) ?>" style="display:none"><?php foreach(email_message_model_variables($ctxKey) as $var): ?><code><?= wam_h($var) ?></code><?php endforeach; ?></div><?php endforeach; ?>
      </div>
      <label class="wam-check"><input type="checkbox" name="active" <?= !empty($form['active'])?'checked':'' ?>> Modelo ativo</label>
      <label class="wam-check" id="wam-default-wrap"><input type="checkbox" name="is_default" <?= $formDefault?'checked':'' ?>> Usar como modelo padrão para este tipo de envio</label>
      <div class="wam-actions"><button class="btn btn-primary" type="submit">Salvar modelo</button><a class="btn" href="<?= wam_h(wam_url('whatsapp_modelos.php')) ?>">Cancelar</a></div>
    </form>
  </section>
  <?php endif; ?>
</div>
<script>
(function(){
  const channel=document.getElementById('wam-channel');
  const context=document.getElementById('wam-context');
  if(!channel||!context) return;
  const options={
    whatsapp: <?= json_encode($waContexts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
    email: <?= json_encode($emailContexts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
  };
  let initialContext=<?= json_encode((string)$form['context']) ?>;
  const sync=()=>{
    const ch=channel.value;
    const old=context.value||initialContext;
    context.innerHTML='';
    Object.entries(options[ch]||{}).forEach(([value,label])=>{const o=document.createElement('option');o.value=value;o.textContent=label;if(value===old)o.selected=true;context.appendChild(o);});
    if(!context.value && context.options.length) context.selectedIndex=0;
    const email=ch==='email';
    document.getElementById('wam-subject-wrap').style.display=email?'grid':'none';
    document.getElementById('wam-default-wrap').style.display='flex';
    document.getElementById('wam-subject').required=email;
    document.querySelectorAll('[data-wam-vars]').forEach(el=>{el.style.display=el.getAttribute('data-wam-vars')===(ch+':'+context.value)?'flex':'none';});
    initialContext=context.value;
  };
  channel.addEventListener('change',sync); context.addEventListener('change',sync); sync();
})();
</script>
