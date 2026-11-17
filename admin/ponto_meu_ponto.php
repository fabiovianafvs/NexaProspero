<?php
declare(strict_types=1);

// Ponto Eletrônico > Meu Ponto: o colaborador vê as próprias horas do mês (marcações, extras,
// débitos), pede a inclusão de uma marcação esquecida e envia justificativas (com anexo).
// Os pedidos ficam pendentes até o responsável aprovar em Ocorrências. Basta o usuário estar
// ligado a um colaborador; não precisa de outra permissão.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_apuracao.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['ponto_csrf'])) $_SESSION['ponto_csrf'] = bin2hex(random_bytes(24));
header('Cache-Control: no-store');

$ready = ponto_ready($pdo) && ponto_v4_ready($pdo);
$col = $ready ? ponto_colaborador_do_usuario($pdo, $cid, $uid) : null;
[$de, $ate, $compLabel, $comp] = ponto_competencia((string)($_GET['c'] ?? ''));
$self = 'ponto_meu_ponto.php?c=' . $comp;

if ($col && isset($_GET['doc'])) {
  $oc = ponto_ocorrencia_get($pdo, $cid, (int)$_GET['doc']);
  if (!$oc || (int)$oc['colaborador_id'] !== (int)$col['id']) { http_response_code(404); exit; }
  ponto_doc_output($oc);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    ponto_csrf_check();
    if (!$col) throw new RuntimeException('Seu usuário não está ligado a um colaborador do ponto.');
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'pedir_inclusao') {
      ponto_tratamento_save($pdo, $cid, ['colaborador_id' => (int)$col['id'], 'data' => (string)($_POST['data'] ?? ''), 'tipo' => 'INCLUSAO', 'hora' => (string)($_POST['hora'] ?? ''),
        'proximo_dia' => !empty($_POST['proximo_dia']), 'intencao' => (string)($_POST['intencao'] ?? ''), 'motivo' => (string)($_POST['motivo'] ?? '')], true);
      $msg = 'Pedido enviado. A marcação passa a contar quando o responsável aprovar.';
    } elseif ($a === 'pedir_justificativa') {
      $fl = $_FILES['documento'] ?? null;
      $file = $fl && ($fl['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$fl['tmp_name']) ? ['tmp' => (string)$fl['tmp_name'], 'name' => (string)$fl['name']] : null;
      ponto_ocorrencia_save($pdo, $cid, ['colaborador_id' => (int)$col['id'], 'tipo_ocorrencia_id' => (int)($_POST['tipo_ocorrencia_id'] ?? 0), 'data_inicio' => (string)($_POST['data_inicio'] ?? ''),
        'data_fim' => (string)($_POST['data_fim'] ?? ''), 'hora_inicio' => (string)($_POST['hora_inicio'] ?? ''), 'hora_fim' => (string)($_POST['hora_fim'] ?? ''), 'descricao' => (string)($_POST['descricao'] ?? '')], true, $file);
      $msg = 'Justificativa enviada para aprovação.';
    } elseif ($a === 'cancelar_inclusao') {
      ponto_tratamento_decidir($pdo, $cid, (int)($_POST['id'] ?? 0), 'cancelar', 'Cancelado pelo colaborador', (int)$col['id']);
      $msg = 'Pedido cancelado.';
    } elseif ($a === 'cancelar_justificativa') {
      $oc = ponto_ocorrencia_get($pdo, $cid, (int)($_POST['id'] ?? 0));
      if (!$oc || $oc['status'] !== 'PENDENTE') throw new RuntimeException('Só é possível cancelar pedidos que ainda não foram decididos.');
      ponto_ocorrencia_decidir($pdo, $cid, (int)$oc['id'], 'cancelar', 'Cancelado pelo colaborador', (int)$col['id']);
      $msg = 'Pedido cancelado.';
    } else {
      throw new RuntimeException('Ação inválida.');
    }
    $_SESSION['mp_flash'] = ['ok', $msg];
  } catch (Throwable $e) {
    $_SESSION['mp_flash'] = ['err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível enviar. Tente de novo.'];
  }
  header('Location: ' . $self, true, 303);
  exit;
}

$flash = $_SESSION['mp_flash'] ?? null;
unset($_SESSION['mp_flash']);
$companyName = trim((string)($company['fantasy_name'] ?? '')) ?: (string)($company['name'] ?? 'Nexa Próspero');
$erro = !$ready ? 'O Meu Ponto ainda não foi instalado (Atualizador do Banco: "Ponto Eletrônico - V4 Tratamento e apuração").'
  : (!$col ? 'Seu usuário ainda não está ligado a um colaborador do ponto. Peça ao responsável para escolher o seu usuário no cadastro do colaborador.' : null);
$dias = []; $tot = null; $pedT = []; $pedO = []; $tipos = []; $bhSaldo = null; $mesFechado = false;
if (!$erro) {
  $dias = ponto_apurar_periodo($pdo, $cid, (int)$col['id'], $de, $ate);
  $tot = ponto_apuracao_totais($dias);
  $st = $pdo->prepare("SELECT * FROM ponto_tratamentos WHERE company_id = ? AND colaborador_id = ? AND pelo_colaborador = 1 AND created_at >= ? ORDER BY id DESC LIMIT 50");
  $st->execute([$cid, (int)$col['id'], date('Y-m-d', strtotime('-90 days'))]);
  $pedT = $st->fetchAll(PDO::FETCH_ASSOC);
  $pedO = ponto_ocorrencias_list($pdo, $cid, ['colaborador_id' => (int)$col['id'], 'de' => date('Y-m-d', strtotime('-90 days'))], 50);
  $tipos = ponto_tipos_ocorrencia($pdo, $cid, true, true);
  if (ponto_v5_ready($pdo) && !empty(ponto_config($pdo, $cid)['bh_ativo']) && (int)($col['banco_horas'] ?? 1)) {
    require_once __DIR__ . '/../config/ponto_fechamento.php';
    $bhSaldo = ponto_bh_saldo($pdo, $cid, (int)$col['id']);
    $mesFechado = ponto_competencia_fechada($pdo, $cid, (int)$col['id'], $de);
  }
}
$wd = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$stLabel = ['PENDENTE' => ['Aguardando', 'y'], 'ATIVO' => ['Aprovado', 'g'], 'APROVADA' => ['Aprovada', 'g'], 'REJEITADO' => ['Rejeitado', 'r'], 'REJEITADA' => ['Rejeitada', 'r'], 'CANCELADO' => ['Cancelado', 'n'], 'CANCELADA' => ['Cancelada', 'n']];
$h = 'ponto_h';
$hoje = date('Y-m-d');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#071b55">
<meta name="robots" content="noindex">
<title>Meu Ponto · <?= $h($companyName) ?></title>
<link rel="manifest" href="/ponto_manifest.php?m=pessoal">
<link rel="apple-touch-icon" href="/assets/ponto/apple-touch-icon.png">
<link rel="icon" href="/assets/ponto/icon-192.png">
<link rel="stylesheet" href="/assets/ponto/ponto-app.css?v=2">
<style>
.mp-k{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.mp-k div{background:#f8fbff;border:1px solid #dbe4f0;border-radius:14px;padding:8px 10px}.mp-k span{display:block;font-size:11.5px;color:#64748b;font-weight:800}.mp-k b{font-size:19px;color:#0b1f5b;font-variant-numeric:tabular-nums}
.mp-d{display:grid;grid-template-columns:64px minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px 0;border-top:1px solid #eef2f7;font-size:13.5px}
.mp-d .dt{font-weight:800;color:#334155}.mp-d .dt small{display:block;color:#64748b;font-weight:700}
.mp-d .ms{display:flex;flex-wrap:wrap;gap:4px}
.mp-m{font-variant-numeric:tabular-nums;font-weight:800;font-size:12.5px;border:1px solid #dbe4f0;border-radius:8px;padding:2px 6px;background:#fff}
.mp-m.i{border-color:#86efac;background:#f0fdf4;color:#15803d}.mp-m.p{border-style:dashed;border-color:#fbbf24;color:#a16207}.mp-m.x{text-decoration:line-through;color:#94a3b8}
.mp-d .r{text-align:right;font-size:12.5px;font-weight:800;white-space:nowrap}
.mp-al{display:block;color:#b91c1c;font-size:11.5px;font-weight:700}
.mp-f{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.mp-f label{display:grid;gap:4px;font-size:12px;font-weight:800;color:#334155}
.mp-f .s2{grid-column:1/-1}
.mp-f[hidden]{display:none}
.mp-f input,.mp-f select{width:100%;min-height:44px;border:1px solid #cbd5e1;border-radius:12px;padding:0 11px;font:inherit;font-size:15px;background:#fff}
.mp-f input[type=file]{padding:9px 6px;min-height:0}
.mp-f label.ck{display:flex;gap:8px;align-items:center;font-size:13.5px}.mp-f label.ck input{width:20px;min-height:20px}
.mp-tabs{display:flex;gap:6px;margin-bottom:12px}.mp-tabs button{flex:1;min-height:42px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;font-weight:850;color:#0b1f5b;cursor:pointer}
.mp-tabs button.on{background:#2454d6;border-color:#2454d6;color:#fff}
.mp-p{border-top:1px solid #eef2f7;padding:9px 0;font-size:13.5px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.mp-p .sp{flex:1}
@media(max-width:520px){.mp-k{grid-template-columns:repeat(2,minmax(0,1fr))}.mp-f{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="pa-top">
  <div><b>Meu Ponto</b><small><?= $h($col['nome'] ?? $companyName) ?></small></div>
  <span class="sp"></span>
  <a href="ponto_bater.php">Bater Ponto</a>
  <a href="index.php" style="margin-left:6px">Sistema</a>
</header>
<main class="pa-wrap">
  <?php if ($flash): ?><div class="pa-msg <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= $h($flash[1]) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="pa-msg warn"><?= $h($erro) ?></div><?php else: ?>

  <section class="pa-card">
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
      <h2 class="pa-h2" style="margin:0;flex:1">Minhas horas · <?= $h($compLabel) ?></h2>
      <input type="month" name="c" value="<?= $h($comp) ?>" onchange="this.form.submit()" style="min-height:40px;border:1px solid #cbd5e1;border-radius:10px;padding:0 8px;font:inherit">
    </form>
    <div class="mp-k">
      <div><span>Trabalhado</span><b><?= $h(ponto_hm($tot['trabalhados'], false)) ?></b></div>
      <div><span>Extras</span><b style="color:#15803d"><?= $h(ponto_hm($tot['extra'], false)) ?></b></div>
      <div><span>Débito</span><b style="color:#b91c1c"><?= $h(ponto_hm($tot['falta'], false)) ?></b></div>
      <div><span>Saldo</span><b style="color:<?= $tot['saldo'] < 0 ? '#b91c1c' : '#15803d' ?>"><?= $h(ponto_hm($tot['saldo'], false)) ?></b></div>
    </div>
    <?php if ($bhSaldo !== null): ?><div class="pa-msg <?= $bhSaldo < 0 ? 'warn' : 'ok' ?>" style="margin:10px 0 0">Banco de horas (meses já fechados): <b><?= $h(ponto_hm($bhSaldo, false)) ?></b></div><?php endif; ?>
    <p class="pa-hint" style="margin:8px 0 0"><?= ponto_v5_ready($pdo) && ponto_competencia_fechada($pdo, $cid, (int)$col['id'], $de) ? 'Mês fechado pelo responsável.' : 'Valores provisórios até o fechamento do mês pelo responsável.' ?></p>
    <div style="margin-top:8px">
      <?php foreach (array_reverse($dias, true) as $d => $r):
        if (in_array($r['situacao'], ['FUTURO', 'FORA_CONTRATO', 'ANTES_INICIO'], true)) continue;
        if (in_array($r['situacao'], ['FOLGA', 'FERIADO'], true) && !$r['marcacoes']) continue;
        [$sl, $sc] = PONTO_SITUACOES[$r['situacao']] ?? [$r['situacao'], 'n']; ?>
        <div class="mp-d">
          <div class="dt"><?= date('d/m', strtotime($d)) ?><small><?= $wd[(int)date('w', strtotime($d))] ?></small></div>
          <div>
            <div class="ms"><?php foreach ($r['marcacoes'] as $m): $cls = $m['desconsiderada'] ? 'x' : ($m['pendente'] ? 'p' : ($m['origem'] === 'INCLUIDA' ? 'i' : '')); ?><span class="mp-m <?= $cls ?>" title="<?= $h(PONTO_INTENCOES[(string)$m['intencao']] ?? '') ?>"><?= date('H:i', $m['ts']) ?></span><?php endforeach; ?><?php if (!$r['marcacoes']): ?><span class="pa-hint">sem marcações</span><?php endif; ?></div>
            <?php foreach ($r['alertas'] as $al): ?><span class="mp-al"><?= $h($al) ?></span><?php endforeach; ?>
            <?php foreach ($r['ocorrencias'] as $o): ?><span class="pa-hint" style="display:block;color:#1d4ed8"><?= $h($o['tipo']) ?></span><?php endforeach; ?>
          </div>
          <div class="r"><span class="pa-badge <?= $sc ?>"><?= $h($sl) ?></span><br><?= $r['extra'] ? '<span style="color:#15803d">+' . $h(ponto_hm($r['extra'])) . '</span>' : '' ?><?= $r['falta'] ? '<span style="color:#b91c1c">-' . $h(ponto_hm($r['falta'])) . '</span>' : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="pa-hint" style="margin:8px 0 0"><span class="mp-m i">08:00</span> incluída no tratamento · <span class="mp-m p">08:00</span> pedido aguardando · <span class="mp-m x">08:00</span> desconsiderada</p>
  </section>

  <section class="pa-card" id="pedir">
    <h2 class="pa-h2">Pedir ajuste</h2>
    <div class="mp-tabs"><button type="button" class="on" data-p="mpInc">Esqueci de marcar</button><button type="button" data-p="mpJus">Justificar ausência</button></div>
    <form method="post" class="mp-f" id="mpInc">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="pedir_inclusao">
      <label>Dia<input type="date" name="data" required max="<?= $hoje ?>" value="<?= $hoje ?>"></label>
      <label>Horário em que você chegou/saiu<input type="time" name="hora" required></label>
      <label>Tipo<select name="intencao"><?php foreach (PONTO_INTENCOES as $k => $v): ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?></select></label>
      <label class="ck" style="align-self:end"><input type="checkbox" name="proximo_dia" value="1"> Foi no dia seguinte (turno da noite)</label>
      <label class="s2">Motivo<input type="text" name="motivo" maxlength="300" required placeholder="Ex.: esqueci de marcar a volta do almoço"></label>
      <div class="s2"><button class="pa-btn pri" type="submit" style="width:100%">Enviar pedido</button></div>
    </form>
    <form method="post" class="mp-f" id="mpJus" enctype="multipart/form-data" hidden>
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="pedir_justificativa">
      <label class="s2">Motivo<select name="tipo_ocorrencia_id" required><option value="">—</option><?php foreach ($tipos as $id => $tp): ?><option value="<?= $id ?>"><?= $h($tp['descricao']) ?><?= (int)$tp['exige_documento'] ? ' (anexar documento)' : '' ?></option><?php endforeach; ?></select></label>
      <label>De<input type="date" name="data_inicio" required value="<?= $hoje ?>"></label>
      <label>Até (se mais de um dia)<input type="date" name="data_fim"></label>
      <label>Das (só parte do dia)<input type="time" name="hora_inicio"></label>
      <label>Às<input type="time" name="hora_fim"></label>
      <label class="s2">Explique<input type="text" name="descricao" maxlength="500" required placeholder="Ex.: consulta médica às 9h"></label>
      <label class="s2">Documento (PDF ou foto)<input type="file" name="documento" accept="application/pdf,image/*" capture="environment"></label>
      <div class="s2"><button class="pa-btn pri" type="submit" style="width:100%">Enviar justificativa</button></div>
    </form>
  </section>

  <section class="pa-card">
    <h2 class="pa-h2">Meus pedidos (90 dias)</h2>
    <?php if (!$pedT && !$pedO): ?><p class="pa-empty">Nenhum pedido.</p><?php endif; ?>
    <?php foreach ($pedT as $t): [$sl, $sc] = $stLabel[$t['status']] ?? [$t['status'], 'n']; ?>
      <div class="mp-p"><b>Marcação <?= $h(substr((string)$t['data_hora'], 11, 5)) ?></b> em <?= $h(date('d/m', strtotime((string)$t['data']))) ?><span class="pa-badge <?= $sc ?>"><?= $h($sl) ?></span><?= $t['motivo_decisao'] && $t['status'] === 'REJEITADO' ? '<span class="pa-hint">' . $h($t['motivo_decisao']) . '</span>' : '' ?><span class="sp"></span>
        <?php if ($t['status'] === 'PENDENTE'): ?><form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="cancelar_inclusao"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="pa-btn" type="submit" style="min-height:34px;font-size:13px">Cancelar</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php foreach ($pedO as $o): [$sl, $sc] = $stLabel[$o['status']] ?? [$o['status'], 'n']; ?>
      <div class="mp-p"><b><?= $h($o['tipo']) ?></b> <?= $h(date('d/m', strtotime((string)$o['data_inicio']))) ?><?= $o['data_fim'] !== $o['data_inicio'] ? ' a ' . $h(date('d/m', strtotime((string)$o['data_fim']))) : '' ?>
        <span class="pa-badge <?= $sc ?>"><?= $h($sl) ?></span><?= $o['documento_path'] ? '<a class="pa-hint" href="ponto_meu_ponto.php?doc=' . (int)$o['id'] . '" target="_blank" rel="noopener">📎 documento</a>' : '' ?><?= $o['motivo_decisao'] && $o['status'] === 'REJEITADA' ? '<span class="pa-hint">' . $h($o['motivo_decisao']) . '</span>' : '' ?><span class="sp"></span>
        <?php if ($o['status'] === 'PENDENTE'): ?><form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="cancelar_justificativa"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="pa-btn" type="submit" style="min-height:34px;font-size:13px">Cancelar</button></form><?php endif; ?></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</main>
<script>
document.querySelectorAll('.mp-tabs button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('.mp-tabs button').forEach(x => x.classList.toggle('on', x === b));
  ['mpInc', 'mpJus'].forEach(id => { const f = document.getElementById(id); if (f) f.hidden = id !== b.dataset.p; });
}));
document.addEventListener('submit', e => { const b = e.target.querySelector('button[type=submit]'); if (b) setTimeout(() => { b.disabled = true; }, 0); });
</script>
</body>
</html>
