<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: pedidos.php');
  exit;
}

$params = ['id' => $id];
if ((string)($_GET['edit_ok'] ?? '') === '1') {
  $params['edit_ok'] = '1';
}
// V11.24: preserva o contexto F11 no redirecionamento legado. Assim, se alguém
// tentar abrir este atalho em outra empresa, pedido_editar.php recebe o mc_ctx e
// o guardião Multiempresa bloqueia a edição em vez de cair silenciosamente no COMPANY_ID.
$mcCtx = trim((string)($_GET['mc_ctx'] ?? ''));
if ($mcCtx !== '') $params['mc_ctx'] = $mcCtx;

header('Location: pedido_editar.php?' . http_build_query($params));
exit;
