<?php
declare(strict_types=1);

// WhatsApp > Campanhas: envio programado para um público (com prévia conferida antes de
// agendar) e campanha automática de aniversário. Sempre da empresa de login (sem F11).
// Regras em config/whatsapp_campanhas.php; agendador em workers/whatsapp_campanhas_worker.php.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_campanhas.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$ready = wa_camp_ready($pdo);
$h = static fn($v): string => wa_camp_h($v);
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_wcp_csrf'])) $_SESSION['nexa_wcp_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_wcp_csrf'] ?? '');
$ok = (string)($_GET['ok'] ?? '');
$err = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  $back = $id > 0 ? 'whatsapp_campanhas.php?id=' . $id : 'whatsapp_campanhas.php';
  $go = static function (string $url, string $msg): void { header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . 'ok=' . rawurlencode($msg)); exit; };
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    switch ($action) {
      case 'save':
        $newId = wa_camp_save($pdo, $cid, $uid, $_POST, $id);
        $c = wa_camp_get($pdo, $cid, $newId);
        if ($c && $c['kind'] === 'UNICA') {
          [$n, $sk] = wa_camp_preview($pdo, $cid, $newId);
          $go('whatsapp_campanhas.php?id=' . $newId, 'Campanha salva. Prévia com ' . $n . ' pessoa(s). Confira a lista e confirme o envio.');
        }
        $go('whatsapp_campanhas.php?id=' . $newId, 'Campanha salva. Ligue-a para começar a enviar.');
      case 'preview':
        [$n] = wa_camp_preview($pdo, $cid, $id);
        $go($back, 'Prévia refeita: ' . $n . ' pessoa(s) na lista.');
      case 'toggle':
        wa_camp_recipient_toggle($pdo, $cid, $id, (int)($_POST['rid'] ?? 0));
        header('Location: ' . $back . '#lista'); exit;
      case 'confirm':
      case 'confirm_now':
        $when = wa_camp_confirm($pdo, $cid, $uid, $id, $action === 'confirm_now');
        if (strtotime($when) <= time() + 60) {
          wa_camp_dispatch($pdo, $cid);
          $go($back, wa_camp_simulated() ? 'Homologação: a lista foi processada, mas nada é enviado.' : 'Envio iniciado. As mensagens saem aos poucos pela fila do WhatsApp.');
        }
        $go($back, 'Campanha agendada para ' . date('d/m/Y \à\s H:i', strtotime($when)) . '.');
      case 'unschedule':
        wa_camp_unschedule($pdo, $cid, $id);
        $go($back, 'Campanha voltou para rascunho.');
      case 'cancel':
        $n = wa_camp_cancel($pdo, $cid, $id);
        $go($back, 'Campanha cancelada.' . ($n > 0 ? ' ' . $n . ' mensagem(ns) não serão enviadas.' : ''));
      case 'activate':
      case 'pause':
        wa_camp_set_active($pdo, $cid, $id, $action === 'activate');
        $go($back, $action === 'activate' ? 'Campanha ligada: envia todo dia na hora marcada.' : 'Campanha pausada.');
      case 'copy':
        $nid = wa_camp_copy($pdo, $cid, $uid, $id);
        $go('whatsapp_campanhas.php?editar=' . $nid, 'Cópia criada. Ajuste e salve.');
      case 'delete':
        wa_camp_delete($pdo, $cid, $id);
        $go('whatsapp_campanhas.php', 'Campanha excluída.');
      case 'config_save':
        wa_camp_config_save($pdo, $cid, $_POST);
        $go('whatsapp_campanhas.php?aba=config', 'Configurações salvas.');
      case 'optout_add':
        $added = wa_camp_optout_add($pdo, $cid, (string)($_POST['phone'] ?? ''), 'MANUAL', $uid);
        $go('whatsapp_campanhas.php?aba=config#descadastro', $added ? 'Número incluído: não recebe mais campanhas.' : 'Este número já estava na lista.');
      case 'optout_remove':
        wa_camp_optout_remove($pdo, $cid, (string)($_POST['phone'] ?? ''));
        $go('whatsapp_campanhas.php?aba=config#descadastro', 'Número liberado para receber campanhas.');
      default:
        throw new RuntimeException('Ação inválida.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cfg = $ready ? wa_camp_config($pdo, $cid) : wa_camp_config_defaults();
$view = 'list';
$camp = null;
if ($ready && isset($_GET['id'])) { $camp = wa_camp_get($pdo, $cid, (int)$_GET['id']); $view = $camp ? 'detail' : 'list'; }
if ($ready && isset($_GET['editar'])) { $camp = wa_camp_get($pdo, $cid, (int)$_GET['editar']); $view = $camp ? 'form' : 'list'; }
if ($ready && isset($_GET['nova'])) { $view = 'form'; $camp = null; }
if ($ready && ($_GET['aba'] ?? '') === 'config') $view = 'config';
// Erro ao salvar: volta ao formulário com o que foi digitado.
$formIn = ($err !== '' && ($_POST['action'] ?? '') === 'save') ? $_POST : null;
if ($formIn !== null) { $view = 'form'; $camp = (int)($_POST['id'] ?? 0) > 0 ? wa_camp_get($pdo, $cid, (int)$_POST['id']) : null; }

if ($ready) wa_camp_sync($pdo, $cid);
$statusLbl = wa_camp_status_labels();
$recLbl = wa_camp_recipient_labels();
$companyName = $ready ? wa_camp_company_name($pdo, $cid) : '';

admin_topbar('Campanhas do WhatsApp', 'whatsapp_campanhas');
?>
<style>
.wcp{max-width:1180px;margin:14px auto 0;display:grid;gap:14px;color:#0f172a}
.wcp-card{background:#fff;border:1px solid #dbeafe;border-radius:20px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}
.wcp-card h2{margin:0 0 6px;color:#0b1f5b;font-size:20px}.wcp-card h3{margin:0 0 8px;color:#0b1f5b;font-size:16px}
.wcp-muted{color:#64748b;font-size:13px;line-height:1.5}
.wcp-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.wcp-head>div:first-child{flex:1;min-width:260px}
.wcp-btns{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.wcp-btns form{margin:0}
.wcp-table{width:100%;border-collapse:collapse;font-size:13px}.wcp-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap}.wcp-table td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.wcp-table tr.off td{color:#94a3b8;text-decoration:line-through}
.wcp-chip{display:inline-block;padding:2px 9px;border-radius:999px;font-weight:800;font-size:11px;background:#eef2ff;color:#3730a3;white-space:nowrap}
.wcp-chip.s-RASCUNHO,.wcp-chip.s-PAUSADA,.wcp-chip.s-REMOVIDO,.wcp-chip.s-CANCELADA,.wcp-chip.s-CANCELADO{background:#f1f5f9;color:#475569}
.wcp-chip.s-AGENDADA,.wcp-chip.s-PREVISTO{background:#eff6ff;color:#1d4ed8}.wcp-chip.s-ENVIANDO,.wcp-chip.s-NA_FILA{background:#fffbeb;color:#92400e}
.wcp-chip.s-CONCLUIDA,.wcp-chip.s-ATIVA,.wcp-chip.s-ENVIADO{background:#ecfdf5;color:#047857}.wcp-chip.s-FALHOU,.wcp-chip.s-IGNORADO{background:#fef2f2;color:#b91c1c}.wcp-chip.s-SIMULADO{background:#f5f3ff;color:#5b21b6}
.wcp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:12px}
.wcp-kpi{border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;background:#f8fafc}.wcp-kpi b{display:block;font-size:22px;color:#0b1f5b;font-variant-numeric:tabular-nums}.wcp-kpi span{font-size:12px;color:#64748b;font-weight:700}
.wcp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.wcp-field label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:4px}
.wcp-field input,.wcp-field select,.wcp-field textarea{width:100%;box-sizing:border-box}
.wcp-field textarea{min-height:170px;font:14px/1.45 system-ui,sans-serif}
.wcp-kind{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
.wcp-kind label{display:flex;gap:10px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:14px;padding:12px;cursor:pointer;background:#f8fbff}
.wcp-kind label:has(input:checked){border-color:#2454d6;background:#eef4ff;box-shadow:0 0 0 2px rgba(36,84,214,.15)}
.wcp-kind b{display:block;color:#0b1f5b}.wcp-kind small{color:#64748b}
.wcp-vars{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}.wcp-vars button{border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;cursor:pointer}
.wcp-phone{background:#efeae2;border-radius:16px;padding:14px}.wcp-bubble{background:#d9fdd3;border-radius:10px;padding:9px 11px;white-space:pre-wrap;font:14px/1.45 system-ui,sans-serif;box-shadow:0 1px .5px rgba(0,0,0,.15);max-width:420px;margin-left:auto;word-wrap:break-word}
.wcp-two{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:16px}
@media(max-width:900px){.wcp-two{grid-template-columns:1fr}}
.wcp-msg{max-width:520px;white-space:pre-wrap;font-size:12.5px;color:#334155;max-height:3.2em;overflow:hidden;cursor:pointer}.wcp-msg.open{max-height:none}
.wcp-note{border-radius:14px;padding:10px 12px;font-size:13px;line-height:1.5;background:#fffbeb;border:1px solid #fde68a;color:#78350f}
.wcp-note.info{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a}
.wcp-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.wcp-tabs a{padding:7px 14px;border-radius:999px;border:1px solid #dbe4f0;background:#f8fbff;color:#0b1f5b;font-weight:700;font-size:13px;text-decoration:none}.wcp-tabs a.on{background:#0b1f5b;color:#fff;border-color:#0b1f5b}
.wcp-hide{display:none!important}
.wcp-btns .btn{font-size:13px!important;padding:8px 14px!important;min-height:0!important;line-height:1.2!important}
</style>
<div class="wcp">
<?php if ($ok !== ''): ?><div class="msg ok"><?= $h($ok) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= $h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="msg err">As campanhas ainda não foram instaladas. Peça ao administrador da plataforma para aplicar "WhatsApp - Campanhas v1" no Atualizador do Banco.</div>
<?php else: ?>

<section class="wcp-card">
  <div class="wcp-head">
    <div><h2>Campanhas</h2><div class="wcp-muted">Mensagens programadas para um grupo de clientes e a campanha automática de aniversário. As mensagens saem <b>aos poucos</b> (uma a cada <?= (int)$cfg['interval'] ?> segundos, em média) para proteger o número de bloqueio; quem responder <b>PARAR</b> não recebe mais.</div></div>
    <div class="wcp-btns">
      <a class="btn btn-primary" href="whatsapp_campanhas.php?nova=UNICA">+ Envio programado</a>
      <a class="btn" href="whatsapp_campanhas.php?nova=ANIVERSARIO">+ Aniversário automático</a>
      <?php if (access_is_company_master() || access_has_permission($pdo, $cid, 'whatsapp_cobrancas')): ?><a class="btn" href="whatsapp_cobrancas.php">Régua de cobrança</a><?php endif; ?>
    </div>
  </div>
  <nav class="wcp-tabs">
    <a href="whatsapp_campanhas.php" class="<?= in_array($view, ['list', 'detail', 'form'], true) ? 'on' : '' ?>">Campanhas</a>
    <a href="whatsapp_campanhas.php?aba=config" class="<?= $view === 'config' ? 'on' : '' ?>">Configurações de envio e descadastrados</a>
  </nav>
  <?php if (wa_camp_simulated()): ?><div class="wcp-note info" style="margin-top:12px">Homologação: as campanhas montam a lista e mostram o resultado como <b>Simulado</b>, mas nenhuma mensagem é enviada.</div><?php endif; ?>
</section>

<?php if ($view === 'list'): ?>
<?php $rows = wa_camp_list($pdo, $cid); $counts = wa_camp_counts($pdo, $cid, array_column($rows, 'id')); ?>
<section class="wcp-card">
  <?php if (!$rows): ?>
    <div class="wcp-muted">Nenhuma campanha ainda. Comece por um <b>Envio programado</b> (ex.: aniversariantes da semana, quem comprou nos últimos 90 dias) ou ligue o <b>Aniversário automático</b>.</div>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="wcp-table">
    <thead><tr><th>Campanha</th><th>Público</th><th>Quando</th><th>Situação</th><th style="text-align:right">Enviadas</th><th style="text-align:right">Falhas</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $cn = $counts[(int)$r['id']] ?? []; ?>
      <tr>
        <td><a href="whatsapp_campanhas.php?id=<?= (int)$r['id'] ?>" style="font-weight:800;color:#0b1f5b"><?= $h($r['name']) ?></a><div class="wcp-muted" style="font-size:12px"><?= $r['kind'] === 'ANIVERSARIO' ? 'Aniversário automático' : 'Envio programado' ?></div></td>
        <td><?= $h(wa_camp_audience_summary($r)) ?></td>
        <td style="white-space:nowrap"><?php if ($r['kind'] === 'ANIVERSARIO'): ?>Todo dia às <?= $h($r['send_time']) ?><?php elseif (!empty($r['scheduled_at'])): ?><?= $h(date('d/m/Y H:i', strtotime((string)$r['scheduled_at']))) ?><?php else: ?>—<?php endif; ?></td>
        <td><span class="wcp-chip s-<?= $h($r['status']) ?>"><?= $h($statusLbl[$r['status']] ?? $r['status']) ?></span></td>
        <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)($cn['ENVIADO'] ?? 0) + (int)($cn['SIMULADO'] ?? 0) ?><?php if (!empty($cn['NA_FILA'])): ?> <span class="wcp-muted">(+<?= (int)$cn['NA_FILA'] ?> na fila)</span><?php endif; ?></td>
        <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)($cn['FALHOU'] ?? 0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php elseif ($view === 'form'): ?>
<?php
  $kind = $camp ? (string)$camp['kind'] : (strtoupper((string)($formIn['kind'] ?? $_GET['nova'] ?? 'UNICA')) === 'ANIVERSARIO' ? 'ANIVERSARIO' : 'UNICA');
  $o = $camp['opts'] ?? [];
  $v = static function (string $k, $def = '') use ($formIn, $camp, $o) {
    if ($formIn !== null && array_key_exists($k, $formIn)) return $formIn[$k];
    if ($camp) {
      if ($k === 'scheduled_date') return !empty($camp['scheduled_at']) ? substr((string)$camp['scheduled_at'], 0, 10) : $def;
      if ($k === 'scheduled_time') return !empty($camp['scheduled_at']) ? substr((string)$camp['scheduled_at'], 11, 5) : $def;
      if ($k === 'birth_from' || $k === 'birth_to') { $md = (string)($o[$k] ?? ''); return $md !== '' ? substr($md, 3, 2) . '/' . substr($md, 0, 2) : $def; }
      if (array_key_exists($k, $camp)) return $camp[$k];
      if (array_key_exists($k, $o)) return $o[$k];
    }
    return $def;
  };
  $onlyCust = $formIn !== null ? !empty($formIn['only_customers']) : (array_key_exists('only_customers', $o) ? !empty($o['only_customers']) : !empty($cfg['only_customers']));
  $defaultMsg = $kind === 'ANIVERSARIO'
    ? "Olá, {{primeiro_nome}}! 🎉\nA equipe da {{empresa}} deseja um feliz aniversário!\nNo seu dia, você ganha 10% de desconto nas compras. Esperamos você!"
    : "Olá, {{primeiro_nome}}! Tudo bem?\n";
?>
<form method="post" class="wcp-card" id="wcpForm">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($camp['id'] ?? 0) ?>"><input type="hidden" name="kind" value="<?= $h($kind) ?>">
  <div class="wcp-head"><div><h2><?= $camp ? 'Editar campanha' : ($kind === 'ANIVERSARIO' ? 'Nova campanha de aniversário' : 'Novo envio programado') ?></h2>
    <div class="wcp-muted"><?= $kind === 'ANIVERSARIO' ? 'Todo dia, na hora marcada, envia para quem faz aniversário no dia — ou alguns dias antes, para avisar de uma promoção no dia do aniversário.' : 'Escolha o público, a data e a mensagem. Ao salvar, o Nexa monta a prévia com quem vai receber para você conferir antes de confirmar.' ?></div></div>
    <div class="wcp-btns"><a class="btn" href="<?= $camp ? 'whatsapp_campanhas.php?id=' . (int)$camp['id'] : 'whatsapp_campanhas.php' ?>">Voltar</a></div></div>

  <div class="wcp-two" style="margin-top:14px">
    <div style="display:grid;gap:12px;align-content:start">
      <div class="wcp-field"><label>Nome da campanha</label><input type="text" name="name" maxlength="120" required value="<?= $h($v('name', $kind === 'ANIVERSARIO' ? 'Feliz aniversário' : '')) ?>" placeholder="Ex.: Aniversariantes de dezembro"></div>
      <?php if ($kind === 'UNICA'): $aud = (string)$v('audience', 'ANIVERSARIANTES'); $per = (string)$v('birth_period', 'SEMANA'); ?>
      <div class="wcp-field"><label>Quem recebe</label>
        <select name="audience" id="wcpAud"><?php foreach (wa_camp_audiences() as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $aud === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="wcp-grid" data-aud="ANIVERSARIANTES">
        <div class="wcp-field"><label>Que fazem aniversário</label><select name="birth_period" id="wcpPer"><?php foreach (wa_camp_birth_periods() as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $per === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="wcp-field" data-per="INTERVALO"><label>De (dia/mês)</label><input type="text" name="birth_from" placeholder="01/12" maxlength="5" value="<?= $h($v('birth_from')) ?>"></div>
        <div class="wcp-field" data-per="INTERVALO"><label>Até (dia/mês)</label><input type="text" name="birth_to" placeholder="07/12" maxlength="5" value="<?= $h($v('birth_to')) ?>"></div>
      </div>
      <div class="wcp-field" data-aud="COMPRARAM SEM_COMPRA"><label id="wcpDaysLbl">Dias</label>
        <select name="days"><?php $dv = (int)$v('days', 90); foreach ([7, 15, 30, 60, 90, 120, 180, 365] as $d): ?><option value="<?= $d ?>" <?= $dv === $d ? 'selected' : '' ?>><?= $d ?> dias<?= $d === 90 ? ' (3 meses)' : ($d === 180 ? ' (6 meses)' : ($d === 365 ? ' (1 ano)' : '')) ?></option><?php endforeach; ?></select></div>
      <div class="wcp-grid">
        <div class="wcp-field"><label>Dia do envio</label><input type="date" name="scheduled_date" value="<?= $h($v('scheduled_date', date('Y-m-d'))) ?>" required></div>
        <div class="wcp-field"><label>Hora do envio</label><input type="time" name="scheduled_time" value="<?= $h($v('scheduled_time', '09:00')) ?>" required></div>
      </div>
      <div class="wcp-muted" style="margin-top:-6px">Os aniversários e as compras são contados a partir do <b>dia do envio</b>.</div>
      <?php else: ?>
      <div class="wcp-grid">
        <div class="wcp-field"><label>Hora do envio (todo dia)</label><input type="time" name="send_time" value="<?= $h($v('send_time', '09:00')) ?>" required></div>
        <div class="wcp-field"><label>Enviar para quem faz aniversário</label><select name="days_before"><?php $db = (int)$v('days_before', 0); foreach ([0, 1, 2, 3, 5, 7, 10, 15] as $d): ?><option value="<?= $d ?>" <?= $db === $d ? 'selected' : '' ?>><?= $d === 0 ? 'No próprio dia' : 'Daqui a ' . $d . ' dia' . ($d > 1 ? 's' : '') . ' (avisar antes)' ?></option><?php endforeach; ?></select></div>
      </div>
      <?php endif; ?>
      <label style="display:flex;gap:8px;align-items:center;font-weight:700;font-size:13px"><input type="hidden" name="only_customers" value="0"><input type="checkbox" name="only_customers" value="1" <?= $onlyCust ? 'checked' : '' ?>> Só cadastros marcados como <b>cliente</b> (fora fornecedores e outros)</label>
      <div class="wcp-field"><label>Mensagem</label>
        <div class="wcp-vars"><?php foreach (wa_camp_variables() as $tok => $lbl): ?><button type="button" data-var="<?= $h($tok) ?>" title="<?= $h($lbl) ?>"><?= $h($lbl) ?></button><?php endforeach; ?></div>
        <textarea name="message" id="wcpMsg" maxlength="3000" required><?= $h($v('message', $defaultMsg)) ?></textarea>
        <div class="wcp-muted">Clique num botão acima para colocar o dado de cada pessoa no texto. Use *asteriscos* para negrito.</div></div>
    </div>
    <div>
      <div class="wcp-field"><label>Como o cliente vai ver (exemplo)</label></div>
      <div class="wcp-phone"><div class="wcp-bubble" id="wcpPrev"></div></div>
      <div class="wcp-muted" style="margin-top:8px">Exemplo com dados fictícios. <?= !empty($cfg['footer_on']) ? 'A linha do fim (como sair da lista) é colocada sozinha; mude em Configurações de envio.' : 'A linha de descadastro está desligada em Configurações de envio.' ?></div>
    </div>
  </div>
  <div class="wcp-btns" style="margin-top:14px;justify-content:flex-end">
    <button class="btn btn-primary" type="submit"><?= $kind === 'UNICA' ? 'Salvar e gerar prévia' : 'Salvar' ?></button>
  </div>
</form>
<script>
(() => {
  const msg = document.getElementById('wcpMsg'), prev = document.getElementById('wcpPrev');
  const FOOT = <?= json_encode(!empty($cfg['footer_on']) && trim((string)$cfg['footer']) !== '' ? (string)$cfg['footer'] : '', JSON_UNESCAPED_UNICODE) ?>;
  const SAMPLE = {'{{primeiro_nome}}': 'Maria', '{{nome}}': 'Maria da Silva', '{{empresa}}': <?= json_encode($companyName !== '' ? $companyName : 'Sua Empresa', JSON_UNESCAPED_UNICODE) ?>, '{{data_aniversario}}': '15/12', '{{idade}}': '30', '{{ultima_compra}}': '02/10/2026'};
  const esc = (s) => s.replace(/[&<>]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
  const fmt = (s) => esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/_([^_\n]+)_/g, '<i>$1</i>');
  const render = () => { let t = msg.value; for (const k in SAMPLE) t = t.split(k).join(SAMPLE[k]); t = t.replace(/\{\{[a-z_]+\}\}/g, '').trim(); if (FOOT) t += '\n\n_' + FOOT + '_'; prev.innerHTML = fmt(t) || '<span style="color:#94a3b8">(mensagem vazia)</span>'; };
  msg.addEventListener('input', render); render();
  document.querySelectorAll('[data-var]').forEach((b) => b.addEventListener('click', () => {
    const t = b.dataset.var, s = msg.selectionStart ?? msg.value.length, e = msg.selectionEnd ?? s;
    msg.value = msg.value.slice(0, s) + t + msg.value.slice(e); msg.focus(); msg.selectionStart = msg.selectionEnd = s + t.length; render();
  }));
  const aud = document.getElementById('wcpAud'), per = document.getElementById('wcpPer'), dl = document.getElementById('wcpDaysLbl');
  const sync = () => {
    if (!aud) return;
    document.querySelectorAll('[data-aud]').forEach((el) => el.classList.toggle('wcp-hide', !el.dataset.aud.split(' ').includes(aud.value)));
    document.querySelectorAll('[data-per]').forEach((el) => el.classList.toggle('wcp-hide', aud.value !== 'ANIVERSARIANTES' || per.value !== 'INTERVALO'));
    if (dl) dl.textContent = aud.value === 'SEM_COMPRA' ? 'Sem comprar há pelo menos' : 'Compraram nos últimos';
  };
  aud && aud.addEventListener('change', sync); per && per.addEventListener('change', sync); sync();
})();
</script>

<?php elseif ($view === 'detail' && $camp): ?>
<?php
  $id = (int)$camp['id'];
  $isBirth = $camp['kind'] === 'ANIVERSARIO';
  $cn = wa_camp_counts($pdo, $cid, [$id])[$id] ?? [];
  $btn = static function (string $action, string $label, string $cls = 'btn', string $confirm = '') use ($csrf, $id, $h): string {
    return '<form method="post"' . ($confirm !== '' ? ' onsubmit="return confirm(' . $h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '><input type="hidden" name="csrf" value="' . $h($csrf) . '"><input type="hidden" name="action" value="' . $h($action) . '"><input type="hidden" name="id" value="' . $id . '"><button class="' . $h($cls) . '" type="submit">' . $h($label) . '</button></form>';
  };
?>
<section class="wcp-card">
  <div class="wcp-head">
    <div>
      <h2><?= $h($camp['name']) ?> <span class="wcp-chip s-<?= $h($camp['status']) ?>" style="vertical-align:middle"><?= $h($statusLbl[$camp['status']] ?? $camp['status']) ?></span></h2>
      <div class="wcp-muted"><?= $h(wa_camp_audience_summary($camp)) ?><?php if (!$isBirth && !empty($camp['scheduled_at'])): ?> · envio em <b><?= $h(date('d/m/Y \à\s H:i', strtotime((string)$camp['scheduled_at']))) ?></b><?php endif; ?><?= !empty($camp['opts']['only_customers']) ? ' · só clientes' : '' ?></div>
    </div>
    <div class="wcp-btns">
      <a class="btn" href="whatsapp_campanhas.php">Voltar</a>
      <?php if ($isBirth): ?>
        <?php if (!in_array($camp['status'], ['CANCELADA'], true)): ?><a class="btn" href="whatsapp_campanhas.php?editar=<?= $id ?>">Editar</a><?php endif; ?>
        <?= $camp['status'] === 'ATIVA' ? $btn('pause', 'Pausar') : $btn('activate', 'Ligar campanha', 'btn btn-primary', 'Ligar a campanha? A partir de hoje ela envia todo dia às ' . ($camp['send_time'] ?: '09:00') . '.') ?>
      <?php else: ?>
        <?php if ($camp['status'] === 'RASCUNHO'): ?>
          <a class="btn" href="whatsapp_campanhas.php?editar=<?= $id ?>">Editar</a>
          <?= $btn('preview', 'Refazer prévia') ?>
          <?= $btn('confirm', 'Confirmar e agendar', 'btn btn-primary', 'Confirmar o envio para as ' . (int)($cn['PREVISTO'] ?? 0) . ' pessoas da lista em ' . (!empty($camp['scheduled_at']) ? date('d/m/Y H:i', strtotime((string)$camp['scheduled_at'])) : 'agora') . '?') ?>
        <?php elseif ($camp['status'] === 'AGENDADA'): ?>
          <?= $btn('unschedule', 'Voltar para rascunho') ?>
          <?= $btn('confirm_now', 'Enviar agora', 'btn', 'Começar o envio agora, sem esperar a hora marcada?') ?>
        <?php endif; ?>
      <?php endif; ?>
      <?= $btn('copy', 'Copiar') ?>
      <?php if (in_array($camp['status'], ['AGENDADA', 'ENVIANDO', 'ATIVA'], true)): ?><?= $btn('cancel', 'Cancelar campanha', 'btn', 'Cancelar? O que ainda não saiu não será enviado.') ?><?php endif; ?>
      <?php if (in_array($camp['status'], ['RASCUNHO', 'PAUSADA', 'CANCELADA'], true) && empty($cn['ENVIADO']) && empty($cn['NA_FILA']) && empty($cn['FALHOU'])): ?><?= $btn('delete', 'Excluir', 'btn', 'Excluir esta campanha?') ?><?php endif; ?>
    </div>
  </div>
  <div class="wcp-kpis">
    <?php if (!$isBirth && in_array($camp['status'], ['RASCUNHO', 'AGENDADA'], true)): ?>
      <div class="wcp-kpi"><b><?= (int)($cn['PREVISTO'] ?? 0) ?></b><span>na lista</span></div>
      <div class="wcp-kpi"><b><?= (int)($cn['REMOVIDO'] ?? 0) ?></b><span>retirados por você</span></div>
    <?php else: ?>
      <div class="wcp-kpi"><b><?= (int)($cn['ENVIADO'] ?? 0) ?></b><span>enviadas</span></div>
      <div class="wcp-kpi"><b><?= (int)($cn['NA_FILA'] ?? 0) ?></b><span>na fila</span></div>
      <div class="wcp-kpi"><b><?= (int)($cn['FALHOU'] ?? 0) ?></b><span>falharam</span></div>
      <div class="wcp-kpi"><b><?= (int)($cn['IGNORADO'] ?? 0) + (int)($cn['CANCELADO'] ?? 0) ?></b><span>não enviadas</span></div>
      <?php if (!empty($cn['SIMULADO'])): ?><div class="wcp-kpi"><b><?= (int)$cn['SIMULADO'] ?></b><span>simuladas</span></div><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if (!$isBirth && $camp['status'] === 'RASCUNHO'): ?>
    <div class="wcp-note" style="margin-top:12px">Confira a lista abaixo. Clique em <b>Tirar</b> para quem não deve receber. A campanha só é enviada depois de <b>Confirmar e agendar</b>.</div>
  <?php elseif ($camp['status'] === 'ENVIANDO'): ?>
    <div class="wcp-note info" style="margin-top:12px">Enviando aos poucos pela fila do WhatsApp. Atualize a página para acompanhar.</div>
  <?php elseif ($isBirth && $camp['status'] === 'PAUSADA'): ?>
    <div class="wcp-note" style="margin-top:12px">Campanha pausada: nada é enviado. Clique em <b>Ligar campanha</b> para começar.</div>
  <?php endif; ?>
</section>

<?php if ($isBirth): ?>
<?php $nextDay = wa_camp_birthday_next_day($camp); $isToday = $nextDay->format('Y-m-d') === date('Y-m-d'); $todayList = wa_camp_audience($pdo, $cid, $camp, $nextDay, $skToday); ?>
<section class="wcp-card">
  <h3>Próximo envio: <?= $isToday ? 'hoje' : ($nextDay->format('Y-m-d') === date('Y-m-d', strtotime('+1 day')) ? 'amanhã' : $h($nextDay->format('d/m'))) ?> às <?= $h($camp['send_time']) ?><?= $camp['status'] !== 'ATIVA' ? ' (se a campanha estiver ligada)' : '' ?></h3>
  <div class="wcp-muted" style="margin-bottom:8px"><?= count($todayList) ?> pessoa(s) com aniversário em <?= $h($nextDay->modify('+' . (int)$camp['days_before'] . ' days')->format('d/m')) ?>. Fora da lista: <?= (int)$skToday['sem_whatsapp'] ?> sem celular válido, <?= (int)$skToday['descadastrado'] ?> descadastrado(s).<?= !$isToday && $camp['last_run_date'] !== date('Y-m-d') ? ' Hoje já passou do horário (o envio vai até 3 horas depois da hora marcada).' : '' ?></div>
  <?php if ($todayList): ?><div style="overflow-x:auto"><table class="wcp-table"><thead><tr><th>Nome</th><th>WhatsApp</th><th>Aniversário</th><th>Idade</th></tr></thead><tbody>
    <?php foreach (array_slice($todayList, 0, 200) as $p): ?><tr><td><?= $h($p['name']) ?></td><td style="white-space:nowrap"><?= $h(wa_camp_phone_fmt($p['phone'])) ?></td><td><?= $h($p['vars']['data_aniversario']) ?></td><td><?= $h($p['vars']['idade']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>

<?php $recs = wa_camp_recipients($pdo, $cid, $id, '', 2000); ?>
<section class="wcp-card" id="lista">
  <h3><?= $isBirth ? 'Envios feitos' : 'Lista de quem recebe' ?></h3>
  <?php if (!$recs): ?><div class="wcp-muted"><?= $isBirth ? 'Nenhum envio ainda.' : 'Ninguém na lista. Confira o público e a data e refaça a prévia.' ?></div>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="wcp-table">
    <thead><tr><?php if ($isBirth): ?><th>Dia</th><?php endif; ?><th>Nome</th><th>WhatsApp</th><th>Mensagem</th><th>Situação</th><?php if (!$isBirth && $camp['status'] === 'RASCUNHO'): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($recs as $r): ?>
      <tr class="<?= $r['status'] === 'REMOVIDO' ? 'off' : '' ?>">
        <?php if ($isBirth): ?><td style="white-space:nowrap"><?= $h(date('d/m/Y', strtotime((string)$r['run_date']))) ?></td><?php endif; ?>
        <td><?= $h($r['name']) ?></td>
        <td style="white-space:nowrap"><?= $h(wa_camp_phone_fmt((string)$r['phone'])) ?></td>
        <td><div class="wcp-msg" title="Clique para ver tudo" onclick="this.classList.toggle('open')"><?= $h($r['message']) ?></div></td>
        <td><span class="wcp-chip s-<?= $h($r['status']) ?>"><?= $h($recLbl[$r['status']] ?? $r['status']) ?></span><?php if (!empty($r['reason']) && $r['status'] !== 'SIMULADO'): ?><div class="wcp-muted" style="font-size:11.5px"><?= $h($r['reason']) ?></div><?php endif; ?><?php if (!empty($r['sent_at'])): ?><div class="wcp-muted" style="font-size:11.5px"><?= $h(date('d/m H:i', strtotime((string)$r['sent_at']))) ?></div><?php endif; ?></td>
        <?php if (!$isBirth && $camp['status'] === 'RASCUNHO'): ?><td><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><button class="btn" type="submit" style="padding:4px 10px;font-size:12px"><?= $r['status'] === 'REMOVIDO' ? 'Devolver' : 'Tirar' ?></button></form></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php elseif ($view === 'config'): ?>
<form method="post" class="wcp-card">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="config_save">
  <h2>Configurações de envio</h2>
  <div class="wcp-muted" style="margin-bottom:12px">Disparos em massa pelo WhatsApp podem levar o número a ser bloqueado. Mensagens espaçadas, listas menores, só para clientes e com opção de sair reduzem esse risco.</div>
  <div class="wcp-grid">
    <div class="wcp-field"><label>Intervalo entre mensagens (segundos)</label><input type="number" name="interval" min="10" max="300" value="<?= (int)$cfg['interval'] ?>"><div class="wcp-muted">Mínimo 10. Com 20 s, 100 mensagens levam cerca de 40 minutos.</div></div>
    <div class="wcp-field"><label>Máximo de pessoas por campanha</label><input type="number" name="max_per_campaign" min="10" max="5000" value="<?= (int)$cfg['max_per_campaign'] ?>"></div>
  </div>
  <div style="display:grid;gap:10px;margin-top:12px">
    <label style="display:flex;gap:8px;align-items:center;font-weight:700;font-size:13px"><input type="checkbox" name="only_customers" value="1" <?= $cfg['only_customers'] ? 'checked' : '' ?>> Novas campanhas: só cadastros marcados como cliente</label>
    <label style="display:flex;gap:8px;align-items:center;font-weight:700;font-size:13px"><input type="checkbox" name="footer_on" value="1" <?= $cfg['footer_on'] ? 'checked' : '' ?>> Colocar no fim de cada mensagem como sair da lista (recomendado)</label>
    <div class="wcp-field"><label>Texto do fim da mensagem</label><input type="text" name="footer" maxlength="200" value="<?= $h($cfg['footer']) ?>"></div>
    <div class="wcp-field"><label>Resposta para quem pede para sair (PARAR)</label><input type="text" name="optout_reply" maxlength="400" value="<?= $h($cfg['optout_reply']) ?>"></div>
  </div>
  <div class="wcp-btns" style="justify-content:flex-end;margin-top:12px"><button class="btn btn-primary" type="submit">Salvar</button></div>
</form>

<?php $outs = wa_camp_optout_list($pdo, $cid); ?>
<section class="wcp-card" id="descadastro">
  <div class="wcp-head"><div><h3>Não recebem campanhas (<?= count($outs) ?>)</h3><div class="wcp-muted">Quem responde <b>PARAR</b> (ou "descadastrar", "stop") entra aqui sozinho. Também dá para incluir um número à mão. As mensagens normais (pedidos, notas, boletos) continuam chegando.</div></div>
    <form method="post" class="wcp-btns"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="optout_add"><input type="text" name="phone" placeholder="(31) 99999-0000" style="width:170px" required><button class="btn" type="submit">Incluir número</button></form></div>
  <?php if ($outs): ?><div style="overflow-x:auto;margin-top:10px"><table class="wcp-table"><thead><tr><th>WhatsApp</th><th>Origem</th><th>Desde</th><th></th></tr></thead><tbody>
    <?php foreach ($outs as $o2): ?><tr><td style="white-space:nowrap"><?= $h(wa_camp_phone_fmt((string)$o2['phone'])) ?></td><td><?= $o2['source'] === 'CLIENTE' ? 'Pedido do cliente (PARAR)' : 'Incluído por ' . $h($o2['user_name'] ?: 'usuário') ?></td><td><?= $h(date('d/m/Y H:i', strtotime((string)$o2['created_at']))) ?></td>
      <td><form method="post" style="margin:0" onsubmit="return confirm('Liberar este número para receber campanhas de novo?')"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="optout_remove"><input type="hidden" name="phone" value="<?= $h($o2['phone']) ?>"><button class="btn" type="submit" style="padding:4px 10px;font-size:12px">Liberar</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>

<?php endif; ?>
</div>
<?php admin_footer(); ?>
