<?php
declare(strict_types=1);

// Nexa Ponto V8: tela mostrada quando a empresa bloqueia o uso do Nexa fora da jornada.
// Daqui o funcionário bate o ponto ou pede liberação ao gestor. Abre sempre (lista de telas
// livres em ponto_acesso_paginas_livres), mesmo bloqueado.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_acesso.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['ponto_csrf'])) $_SESSION['ponto_csrf'] = bin2hex(random_bytes(24));
header('Cache-Control: no-store');

$av = ponto_acesso_avaliar($pdo, $cid, $uid, auth_role());
$col = $av['colaborador'];
if (!$av['controlado'] || $av['ok'] || $av['modo'] !== 'BLOQUEAR') {
  ponto_acesso_limpar_cache();
  header('Location: ' . access_admin_default_url($pdo, $cid));
  exit;
}

$msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    ponto_csrf_check();
    $acao = (string)($_POST['action'] ?? '');
    if ($acao === 'pedir') {
      ponto_acesso_pedir($pdo, $cid, $col, $uid, (string)($_POST['motivo'] ?? ''));
      ponto_flash('ok', 'Pedido enviado. Assim que o gestor liberar, é só tocar em "Continuar".');
    } elseif ($acao === 'desistir') {
      ponto_acesso_cancelar($pdo, $cid, (int)($_POST['id'] ?? 0), $uid, (int)$col['id']);
      ponto_flash('ok', 'Pedido cancelado.');
    }
  } catch (Throwable $e) {
    ponto_flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível enviar.');
  }
  ponto_redirect('ponto_acesso_bloqueado.php');
}
$flash = ponto_flash_take();

// Último pedido de hoje (para mostrar a situação).
[, $agoraLocal] = ponto_acesso_agora($pdo, $cid, $col);
$st = $pdo->prepare('SELECT * FROM ponto_acesso_liberacoes WHERE company_id = ? AND colaborador_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 1');
$st->execute([$cid, (int)$col['id'], date('Y-m-d 00:00:00')]);
$pedido = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$podeBater = !in_array($av['motivo'], ['AFASTADO'], true);
$h = 'ponto_h';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Acesso ao Nexa fora da jornada</title>
<link rel="stylesheet" href="/assets/ponto/ponto-app.css?v=2">
<style>
.ab{max-width:520px;margin:24px auto;padding:0 12px}
.ab h1{font-size:21px;margin:0 0 6px;color:#0b1f5b;text-align:center}
.ab .ic{width:58px;height:58px;border-radius:50%;background:#fff4e5;color:#b45309;display:grid;place-items:center;margin:0 auto 10px;font-size:30px;font-weight:900}
.ab .why{text-align:center;color:#334155;font-weight:700;margin:0 0 12px;line-height:1.45}
.ab .meta{display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:14px;margin:10px 0;padding:10px 12px;background:#f8fafc;border-radius:12px}
.ab .meta b{color:#0b1f5b}
.ab textarea{width:100%;min-height:76px;border:1px solid #cbd5e1;border-radius:12px;padding:10px;font:inherit;resize:vertical}
.ab .st{padding:10px 12px;border-radius:12px;font-weight:700;margin:10px 0}
.ab .st.p{background:#eff6ff;color:#1e40af}.ab .st.n{background:#fef2f2;color:#991b1b}.ab .st.ok{background:#ecfdf5;color:#065f46}.ab .st.err{background:#fef2f2;color:#991b1b}
.ab hr{border:0;border-top:1px solid #eef2f7;margin:14px 0}
</style>
</head>
<body>
<main class="ab">
  <section class="pa-card">
    <div class="ic" aria-hidden="true">!</div>
    <h1>Acesso ao Nexa fora da jornada</h1>
    <p class="why"><?= $h($av['mensagem']) ?></p>
    <?php if ($flash): ?><div class="st <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= $h($flash[1]) ?></div><?php endif; ?>
    <div class="meta">
      <span>Colaborador</span><b><?= $h($col['nome'] ?? '') ?></b>
      <span>Agora</span><b><?= $h(date('d/m/Y H:i', strtotime($agoraLocal))) ?></b>
      <?php if ($av['janela'] !== ''): ?><span>Jornada</span><b><?= $h($av['janela']) ?></b><?php endif; ?>
    </div>
    <div class="pa-row">
      <?php if ($podeBater): ?><a class="pa-btn pri" href="ponto_bater.php">Bater ponto</a><?php endif; ?>
      <a class="pa-btn" href="ponto_acesso_bloqueado.php">Continuar</a>
    </div>
    <hr>
    <?php if ($pedido && $pedido['status'] === 'PENDENTE'): ?>
      <div class="st p">Pedido de liberação enviado às <?= $h(date('H:i', strtotime((string)$pedido['created_at']))) ?>, aguardando o gestor.<br><small><?= $h($pedido['motivo']) ?></small></div>
      <form method="post" class="pa-row"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="desistir"><input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>"><button class="pa-btn" type="submit">Cancelar pedido</button></form>
    <?php else: ?>
      <?php if ($pedido && $pedido['status'] === 'NEGADA'): ?><div class="st n">O gestor negou o pedido anterior<?= trim((string)$pedido['obs_decisao']) !== '' ? ': ' . $h($pedido['obs_decisao']) : '' ?>.</div><?php endif; ?>
      <form method="post">
        <?= ponto_csrf_field() ?><input type="hidden" name="action" value="pedir">
        <label for="mot" style="font-weight:800;color:#0b1f5b">Precisa usar o Nexa agora? Peça liberação ao gestor</label>
        <textarea id="mot" name="motivo" maxlength="300" required placeholder="Ex.: terminar o fechamento do caixa; cliente aguardando"></textarea>
        <div class="pa-row"><button class="pa-btn pri" type="submit">Pedir liberação</button></div>
      </form>
    <?php endif; ?>
    <div class="pa-row" style="margin-top:14px"><a class="pa-btn" href="logout.php">Sair do Nexa</a></div>
  </section>
</main>
</body>
</html>
