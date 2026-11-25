<?php
declare(strict_types=1);

// WhatsApp > Cobranças: régua de cobrança do Contas a Receber (lembretes antes, no dia e depois
// do vencimento). Sempre da empresa de login (sem F11). Regras em config/whatsapp_cobrancas.php;
// roda pelo agendador das campanhas (workers/whatsapp_campanhas_worker.php).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_cobrancas.php';

$cid = (int)COMPANY_ID;
$ready = wa_cob_ready($pdo);
$h = static fn($v): string => wa_camp_h($v);
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_wcp_csrf'])) $_SESSION['nexa_wcp_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_wcp_csrf'] ?? '');
$ok = (string)($_GET['ok'] ?? '');
$err = '';
$postCfg = null;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    if ((string)($_POST['action'] ?? '') !== 'save') throw new RuntimeException('Ação inválida.');
    $saved = wa_cob_config_save($pdo, $cid, $_POST);
    header('Location: whatsapp_cobrancas.php?ok=' . rawurlencode($saved['enabled'] ? 'Régua salva e ligada: envia todo dia às ' . $saved['send_time'] . '.' : 'Régua salva (desligada: nada é enviado).'));
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
    $postCfg = $_POST;
  }
}

$cfg = $ready ? wa_cob_config($pdo, $cid) : wa_cob_config_defaults();
if ($postCfg !== null) {
  // Erro ao salvar: mostra de novo o que foi digitado.
  $cfg = array_merge($cfg, ['enabled' => !empty($postCfg['enabled']), 'send_time' => (string)($postCfg['send_time'] ?? $cfg['send_time']), 'include_payment' => !empty($postCfg['include_payment']), 'respect_optout' => !empty($postCfg['respect_optout']), 'max_per_person' => (int)($postCfg['max_per_person'] ?? 3)]);
  $cfg['steps'] = array_values(array_filter((array)($postCfg['steps'] ?? []), 'is_array'));
}
if ($ready) wa_cob_sync($pdo, $cid);

admin_topbar('Cobranças pelo WhatsApp', 'whatsapp_cobrancas');
?>
<style>
.wcb{max-width:1180px;margin:14px auto 0;display:grid;gap:14px;color:#0f172a}
.wcb-card{background:#fff;border:1px solid #dbeafe;border-radius:20px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}
.wcb-card h2{margin:0 0 6px;color:#0b1f5b;font-size:20px}.wcb-card h3{margin:0 0 8px;color:#0b1f5b;font-size:16px}
.wcb-muted{color:#64748b;font-size:13px;line-height:1.5}
.wcb-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.wcb-head>div:first-child{flex:1;min-width:260px}
.wcb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.wcb-field label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:4px}.wcb-field input,.wcb-field select,.wcb-field textarea{width:100%;box-sizing:border-box}
.wcb-check{display:flex;gap:8px;align-items:center;font-weight:700;font-size:13px;margin:0}
.wcb-switch{display:flex;gap:10px;align-items:center;padding:12px 14px;border-radius:14px;border:1px solid #dbe4f0;background:#f8fbff;font-weight:800;color:#0b1f5b}
.wcb-switch:has(input:checked){background:#ecfdf5;border-color:#a7f3d0;color:#047857}
.wcb-step{display:grid;grid-template-columns:130px 1fr auto;gap:12px;align-items:start;border:1px solid #e2e8f0;border-radius:14px;padding:12px;background:#fcfdff}
.wcb-step+.wcb-step{margin-top:10px}.wcb-step.off{opacity:.6}
.wcb-step textarea{min-height:86px;font:13.5px/1.45 system-ui,sans-serif}
.wcb-step .lbl{font-size:12px;font-weight:800;color:#2454d6;margin-top:4px}
.wcb-vars{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 12px}.wcb-vars button{border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;cursor:pointer}
.wcb-table{width:100%;border-collapse:collapse;font-size:13px}.wcb-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap}.wcb-table td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.wcb-chip{display:inline-block;padding:2px 9px;border-radius:999px;font-weight:800;font-size:11px;background:#eef2ff;color:#3730a3;white-space:nowrap}
.wcb-chip.s-ENVIADO,.wcb-chip.s-ok{background:#ecfdf5;color:#047857}.wcb-chip.s-NA_FILA{background:#fffbeb;color:#92400e}.wcb-chip.s-FALHOU,.wcb-chip.s-IGNORADO,.wcb-chip.s-skip{background:#fef2f2;color:#b91c1c}.wcb-chip.s-SIMULADO{background:#f5f3ff;color:#5b21b6}.wcb-chip.s-CANCELADO{background:#f1f5f9;color:#475569}
.wcb-msg{max-width:460px;white-space:pre-wrap;font-size:12.5px;color:#334155;max-height:3.2em;overflow:hidden;cursor:pointer;word-break:break-word}.wcb-msg.open{max-height:none}
.wcb-note{border-radius:14px;padding:10px 12px;font-size:13px;line-height:1.5;background:#fffbeb;border:1px solid #fde68a;color:#78350f}.wcb-note.info{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a}
.wcb-kpis{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 12px}.wcb-kpi{border:1px solid #e2e8f0;border-radius:12px;padding:8px 12px;background:#f8fafc;font-size:12px;color:#64748b;font-weight:700}.wcb-kpi b{font-size:18px;color:#0b1f5b;margin-right:4px;font-variant-numeric:tabular-nums}
.wcb-btns .btn{font-size:13px!important;padding:8px 14px!important;min-height:0!important;line-height:1.2!important}
@media(max-width:700px){.wcb-step{grid-template-columns:1fr}}
</style>
<div class="wcb">
<?php if ($ok !== ''): ?><div class="msg ok"><?= $h($ok) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= $h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="msg err">A régua de cobrança ainda não foi instalada. Peça ao administrador da plataforma para aplicar "WhatsApp - Campanhas v1" e "WhatsApp - Cobranças v1" no Atualizador do Banco.</div>
<?php else: ?>

<form method="post" class="wcb-card" id="wcbForm">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="save">
  <div class="wcb-head">
    <div><h2>Régua de cobrança</h2>
      <div class="wcb-muted">Todo dia, na hora marcada, o Nexa manda um lembrete pelo WhatsApp para cada conta do <b>Contas a Receber</b> que ainda está em aberto e cai numa etapa da régua (dias antes, no dia ou depois do vencimento). Conta paga ou baixada não recebe. Com boleto, vai a linha digitável e o Pix copia e cola numa mensagem separada.</div></div>
    <div class="wcb-btns"><a class="btn" href="whatsapp_campanhas.php">Campanhas</a></div>
  </div>
  <?php if (wa_camp_simulated()): ?><div class="wcb-note info" style="margin-top:12px">Homologação: a régua monta os lembretes e mostra como <b>Simulado</b>, mas nada é enviado.</div><?php endif; ?>

  <div class="wcb-grid" style="margin-top:14px">
    <label class="wcb-switch"><input type="checkbox" name="enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>> Régua ligada</label>
    <div class="wcb-field"><label>Hora do envio (todo dia)</label><input type="time" name="send_time" value="<?= $h($cfg['send_time']) ?>" required></div>
    <div class="wcb-field"><label>Máximo de lembretes por pessoa no dia</label><input type="number" name="max_per_person" min="1" max="10" value="<?= (int)$cfg['max_per_person'] ?>"></div>
  </div>
  <div style="display:grid;gap:8px;margin-top:12px">
    <label class="wcb-check"><input type="checkbox" name="include_payment" value="1" <?= !empty($cfg['include_payment']) ? 'checked' : '' ?>> Incluir a linha digitável e o Pix copia e cola quando a conta tiver boleto</label>
    <label class="wcb-check"><input type="checkbox" name="respect_optout" value="1" <?= !empty($cfg['respect_optout']) ? 'checked' : '' ?>> Não mandar cobrança para quem pediu para sair das campanhas (PARAR)</label>
  </div>

  <h3 style="margin-top:18px">Etapas</h3>
  <div class="wcb-muted">Dias em relação ao vencimento: <b>-5</b> = 5 dias antes, <b>0</b> = no dia, <b>5</b> = 5 dias depois, <b>30</b>, <b>60</b>... Desmarque "Ativa" para pausar uma etapa sem apagar.</div>
  <div class="wcb-vars"><?php foreach (wa_cob_variables() as $tok => $lbl): ?><button type="button" data-var="<?= $h($tok) ?>" title="Coloca no texto onde está o cursor"><?= $h($lbl) ?></button><?php endforeach; ?></div>
  <div id="wcbSteps">
  <?php foreach (array_values($cfg['steps']) as $i => $s): $d = (int)($s['days'] ?? 0); ?>
    <div class="wcb-step <?= empty($s['active']) ? 'off' : '' ?>">
      <div><div class="wcb-field"><label>Dias</label><input type="number" name="steps[<?= $i ?>][days]" min="-30" max="365" value="<?= $d ?>" data-days></div><div class="lbl" data-lbl><?= $h(wa_cob_step_label($d)) ?></div></div>
      <div class="wcb-field"><label>Mensagem</label><textarea name="steps[<?= $i ?>][message]" maxlength="2000"><?= $h($s['message'] ?? '') ?></textarea></div>
      <div style="display:grid;gap:8px;padding-top:20px"><label class="wcb-check"><input type="checkbox" name="steps[<?= $i ?>][active]" value="1" <?= !empty($s['active']) ? 'checked' : '' ?> data-active> Ativa</label><button type="button" class="btn" data-remove style="padding:4px 10px;font-size:12px">Remover</button></div>
    </div>
  <?php endforeach; ?>
  </div>
  <div style="display:flex;justify-content:space-between;gap:10px;margin-top:12px;flex-wrap:wrap" class="wcb-btns">
    <button type="button" class="btn" id="wcbAdd">+ Etapa</button>
    <button class="btn btn-primary" type="submit">Salvar régua</button>
  </div>
</form>

<?php
  $nextDay = wa_cob_next_run_day($pdo, $cid, wa_cob_config($pdo, $cid));
  try { $plan = wa_cob_plan($pdo, $cid, $nextDay); $planErr = ''; } catch (Throwable $e) { $plan = []; $planErr = $e->getMessage(); }
  $willSend = array_values(array_filter($plan, static fn($p) => $p['skip'] === ''));
  $isToday = $nextDay->format('Y-m-d') === date('Y-m-d');
?>
<section class="wcb-card">
  <h3>Próximo envio: <?= $isToday ? 'hoje' : ($nextDay->format('Y-m-d') === date('Y-m-d', strtotime('+1 day')) ? 'amanhã' : $h($nextDay->format('d/m/Y'))) ?> às <?= $h(wa_cob_config($pdo, $cid)['send_time']) ?><?= empty(wa_cob_config($pdo, $cid)['enabled']) ? ' (se a régua estiver ligada)' : '' ?></h3>
  <div class="wcb-muted">Prévia com os títulos em aberto de agora; quem pagar até lá sai da lista sozinho.</div>
  <?php if ($planErr !== ''): ?><div class="msg err" style="margin-top:10px"><?= $h($planErr) ?></div><?php endif; ?>
  <div class="wcb-kpis"><span class="wcb-kpi"><b><?= count($willSend) ?></b>lembrete(s)</span><span class="wcb-kpi"><b><?= count(array_filter($willSend, static fn($p) => $p['has_boleto'])) ?></b>com boleto</span><span class="wcb-kpi"><b><?= count($plan) - count($willSend) ?></b>fora (sem celular, já enviado...)</span></div>
  <?php if ($plan): ?><div style="overflow-x:auto"><table class="wcb-table">
    <thead><tr><th>Etapa</th><th>Cliente</th><th>WhatsApp</th><th>Vencimento</th><th style="text-align:right">Em aberto</th><th>Mensagem</th><th>Situação</th></tr></thead><tbody>
    <?php foreach (array_slice($plan, 0, 300) as $p): $t = $p['title']; ?>
      <tr>
        <td style="white-space:nowrap"><?= $h(wa_cob_step_label((int)$p['step'])) ?></td>
        <td><a href="financeiro_contas_receber.php?edit=<?= (int)$t['id'] ?>" style="color:#0b1f5b;font-weight:700"><?= $h($t['name']) ?></a><?php if (!empty($t['description'])): ?><div class="wcb-muted" style="font-size:11.5px"><?= $h($t['description']) ?></div><?php endif; ?></td>
        <td style="white-space:nowrap"><?= $p['phone'] !== '' ? $h(wa_camp_phone_fmt($p['phone'])) : '—' ?></td>
        <td style="white-space:nowrap"><?= $h(date('d/m/Y', strtotime((string)$t['due_date']))) ?></td>
        <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums"><?= $h(wa_cob_money($t['open_amount'])) ?></td>
        <td><div class="wcb-msg" title="Clique para ver tudo" onclick="this.classList.toggle('open')"><?= $h($p['text']) ?><?= $p['pix'] !== '' ? "\n\n[+ mensagem com o Pix copia e cola]" : '' ?></div></td>
        <td><?php if ($p['skip'] === ''): ?><span class="wcb-chip s-ok">Vai receber</span><?php else: ?><span class="wcb-chip s-skip"><?= $h($p['skip']) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<?php
  $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['de'] ?? '')) ? (string)$_GET['de'] : date('Y-m-d', strtotime('-30 days'));
  $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ate'] ?? '')) ? (string)$_GET['ate'] : date('Y-m-d');
  $stF = in_array((string)($_GET['situacao'] ?? ''), ['NA_FILA', 'ENVIADO', 'FALHOU', 'IGNORADO', 'SIMULADO', 'CANCELADO'], true) ? (string)$_GET['situacao'] : '';
  $hist = wa_cob_history($pdo, $cid, $from, $to, $stF);
  $labels = ['NA_FILA' => 'Na fila', 'ENVIADO' => 'Enviado', 'FALHOU' => 'Falhou', 'IGNORADO' => 'Não enviado', 'SIMULADO' => 'Simulado (homologação)', 'CANCELADO' => 'Cancelado'];
?>
<section class="wcb-card" id="historico">
  <div class="wcb-head"><div><h3>Lembretes enviados</h3></div>
    <form method="get" action="whatsapp_cobrancas.php#historico" class="wcb-btns" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
      <div class="wcb-field"><label>De</label><input type="date" name="de" value="<?= $h($from) ?>"></div>
      <div class="wcb-field"><label>Até</label><input type="date" name="ate" value="<?= $h($to) ?>"></div>
      <div class="wcb-field"><label>Situação</label><select name="situacao"><option value="">Todas</option><?php foreach ($labels as $k => $l): ?><option value="<?= $h($k) ?>" <?= $stF === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select></div>
      <button class="btn" type="submit">Filtrar</button>
    </form></div>
  <?php if (!$hist): ?><div class="wcb-muted">Nenhum lembrete no período.</div>
  <?php else: ?><div style="overflow-x:auto"><table class="wcb-table">
    <thead><tr><th>Dia</th><th>Etapa</th><th>Cliente</th><th>WhatsApp</th><th>Vencimento</th><th style="text-align:right">Valor</th><th>Mensagem</th><th>Situação</th></tr></thead><tbody>
    <?php foreach ($hist as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?= $h(date('d/m/Y', strtotime((string)$r['run_date']))) ?></td>
        <td style="white-space:nowrap"><?= $h(wa_cob_step_label((int)$r['step_days'])) ?></td>
        <td><a href="financeiro_contas_receber.php?edit=<?= (int)$r['receivable_id'] ?>" style="color:#0b1f5b;font-weight:700"><?= $h($r['name']) ?></a></td>
        <td style="white-space:nowrap"><?= $r['phone'] !== '' ? $h(wa_camp_phone_fmt((string)$r['phone'])) : '—' ?></td>
        <td style="white-space:nowrap"><?= $h(date('d/m/Y', strtotime((string)$r['due_date']))) ?></td>
        <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums"><?= $h(wa_cob_money($r['amount'])) ?></td>
        <td><div class="wcb-msg" title="Clique para ver tudo" onclick="this.classList.toggle('open')"><?= $h($r['message']) ?></div></td>
        <td><span class="wcb-chip s-<?= $h($r['status']) ?>"><?= $h($labels[$r['status']] ?? $r['status']) ?></span><?php if (!empty($r['reason']) && $r['status'] !== 'SIMULADO'): ?><div class="wcb-muted" style="font-size:11.5px"><?= $h($r['reason']) ?></div><?php endif; ?><?php if (!empty($r['sent_at'])): ?><div class="wcb-muted" style="font-size:11.5px"><?= $h(date('d/m H:i', strtotime((string)$r['sent_at']))) ?></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<template id="wcbTpl">
  <div class="wcb-step">
    <div><div class="wcb-field"><label>Dias</label><input type="number" name="steps[__I__][days]" min="-30" max="365" value="" data-days></div><div class="lbl" data-lbl></div></div>
    <div class="wcb-field"><label>Mensagem</label><textarea name="steps[__I__][message]" maxlength="2000">Olá, {{primeiro_nome}}! </textarea></div>
    <div style="display:grid;gap:8px;padding-top:20px"><label class="wcb-check"><input type="checkbox" name="steps[__I__][active]" value="1" checked data-active> Ativa</label><button type="button" class="btn" data-remove style="padding:4px 10px;font-size:12px">Remover</button></div>
  </div>
</template>
<script>
(() => {
  const box = document.getElementById('wcbSteps'), tpl = document.getElementById('wcbTpl');
  let next = box.children.length + 100, lastArea = null;
  const label = (v) => { if (v === '' || isNaN(v)) return ''; const d = parseInt(v, 10); if (d === 0) return 'No dia do vencimento'; const n = Math.abs(d); return n + ' dia' + (n > 1 ? 's' : '') + (d < 0 ? ' antes' : ' depois'); };
  const wire = (row) => {
    const days = row.querySelector('[data-days]'), lbl = row.querySelector('[data-lbl]'), act = row.querySelector('[data-active]');
    days.addEventListener('input', () => { lbl.textContent = label(days.value); });
    act.addEventListener('change', () => row.classList.toggle('off', !act.checked));
    row.querySelector('[data-remove]').addEventListener('click', () => { if (box.children.length <= 1) { alert('A régua precisa de pelo menos uma etapa.'); return; } row.remove(); });
    row.querySelector('textarea').addEventListener('focus', (e) => { lastArea = e.target; });
  };
  Array.from(box.children).forEach(wire);
  document.getElementById('wcbAdd').addEventListener('click', () => {
    if (box.children.length >= 10) { alert('Use no máximo 10 etapas.'); return; }
    const frag = tpl.content.cloneNode(true); const row = frag.firstElementChild;
    row.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace('__I__', String(next)); });
    next++; box.appendChild(row); wire(row); row.querySelector('[data-days]').focus();
  });
  document.querySelectorAll('[data-var]').forEach((b) => b.addEventListener('mousedown', (e) => e.preventDefault()));
  document.querySelectorAll('[data-var]').forEach((b) => b.addEventListener('click', () => {
    const ta = lastArea || box.querySelector('textarea'); if (!ta) return;
    const t = b.dataset.var, s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? s;
    ta.value = ta.value.slice(0, s) + t + ta.value.slice(e); ta.focus(); ta.selectionStart = ta.selectionEnd = s + t.length;
  }));
})();
</script>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
