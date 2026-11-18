<?php
declare(strict_types=1);

// Transporte: lista dos CT-e (rascunhos, transmitidos, autorizados) e ações de transmissão/consulta.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_transporte.php';
require_once __DIR__ . '/../config/cte_emissao.php';

$cid = transp_guard($pdo, 'cte_documentos');
$uid = function_exists('auth_user_id') ? ((int)(auth_user_id() ?? 0) ?: null) : null;
$f = [
  'ini' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-m-01'),
  'fim' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d'),
  'status' => (string)($_GET['status'] ?? ''),
  'q' => trim((string)($_GET['q'] ?? '')),
];
$back = 'cte.php?' . http_build_query(array_filter($f, 'strlen'));

transp_post_handle($back, function () use ($pdo, $cid, $uid) {
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  if ($action === 'transmitir') {
    @set_time_limit(120);
    $r = cte_transmitir($pdo, $cid, $id, $uid);
    if ($r['status'] === 'AUTORIZADO') return 'CT-e autorizado: ' . $r['mensagem'];
    if ($r['status'] === 'PENDENTE') { transp_flash('warn', 'Resultado incerto: ' . $r['mensagem']); return ''; }
    throw new RuntimeException('CT-e não autorizado: ' . $r['mensagem']);
  }
  if ($action === 'consultar') {
    $r = cte_consultar($pdo, $cid, $id, $uid);
    if ($r['status'] === 'AUTORIZADO') return 'Situação na SEFAZ: ' . $r['mensagem'];
    if ($r['status'] === 'PENDENTE') { transp_flash('warn', 'Situação na SEFAZ: ' . $r['mensagem']); return ''; }
    throw new RuntimeException('Situação na SEFAZ: ' . $r['mensagem']);
  }
  if ($action === 'duplicar') {
    $novo = cte_duplicar($pdo, $cid, $id, $uid);
    return ['Cópia criada como rascunho.', 'cte_editar.php?id=' . $novo];
  }
  if ($action === 'excluir') {
    cte_excluir($pdo, $cid, $id);
    return 'Rascunho excluído.';
  }
  throw new RuntimeException('Ação inválida.');
});

$ready = cte_ready($pdo);
$rows = $ready ? cte_list($pdo, $cid, $f) : [];
$labels = cte_status_labels();
$totAut = 0.0; $qAut = 0; $qPend = 0;
foreach ($rows as $r) {
  if ($r['status'] === 'AUTORIZADO') { $totAut += (float)$r['valor_prestacao']; $qAut++; }
  if (in_array($r['status'], ['RASCUNHO', 'REJEITADO', 'PENDENTE', 'PROCESSANDO'], true)) $qPend++;
}
$cfg = transp_config($pdo, $cid);
$pend = $ready ? transp_pendencias($pdo, $cid, $cfg, 'cte') : [];

transp_start($pdo, 'cte_documentos', 'CT-e — Conhecimentos de Transporte', 'Emissão do CT-e rodoviário. Crie o CT-e, confira e transmita para a SEFAZ. Rascunhos e rejeitados podem ser corrigidos e transmitidos de novo com o mesmo número.', '<a class="tr-btn pri" href="cte_editar.php">+ Novo CT-e</a>');
if (!$ready) {
  echo '<div class="tr-msg warn">A emissão do CT-e ainda não foi instalada no banco. O administrador da plataforma precisa aplicar a atualização "Transporte (CT-e/MDF-e) - V2 Emissão do CT-e" no Atualizador do Banco.</div>';
  transp_end();
  exit;
}
?>
<?php if ($pend): ?><div class="tr-msg warn">Antes de transmitir, complete em <a href="transporte_configuracoes.php?aba=cte">Configurações</a>:<ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($pend as $p): ?><li><?= transp_h($p) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (transp_ambiente_efetivo($cfg, 'cte') === 'HOMOLOGACAO'): ?><div class="tr-msg warn">Ambiente de <b>homologação</b>: os CT-e emitidos são de teste, sem valor fiscal.</div><?php endif; ?>

<div class="tr-cols" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:12px">
  <section class="tr-card"><div class="tr-hint">Autorizados no período</div><div style="font-size:24px;font-weight:900;color:#0b1f5b"><?= $qAut ?></div></section>
  <section class="tr-card"><div class="tr-hint">Valor dos fretes autorizados</div><div style="font-size:24px;font-weight:900;color:#0b1f5b">R$ <?= number_format($totAut, 2, ',', '.') ?></div></section>
  <section class="tr-card"><div class="tr-hint">Rascunhos e pendentes</div><div style="font-size:24px;font-weight:900;color:<?= $qPend ? '#a16207' : '#0b1f5b' ?>"><?= $qPend ?></div></section>
</div>

<section class="tr-card">
  <form method="get" class="tr-search">
    <label class="f grow"><span>Pesquisar <span class="tr-kbd">F8</span></span><input type="text" name="q" value="<?= transp_h($f['q']) ?>" data-tr-search placeholder="Número, chave, tomador, remetente ou destinatário"></label>
    <label class="f" style="width:150px">De<input type="date" name="ini" value="<?= transp_h($f['ini']) ?>"></label>
    <label class="f" style="width:150px">Até<input type="date" name="fim" value="<?= transp_h($f['fim']) ?>"></label>
    <label class="f" style="width:170px">Situação<select name="status"><option value="">Todas</option><?php foreach ($labels as $k => [$lb]): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= transp_h($lb) ?></option><?php endforeach; ?></select></label>
    <button class="tr-btn" type="submit">Pesquisar</button>
  </form>
  <?php if (!$rows): ?>
    <div class="tr-empty">Nenhum CT-e no período. <a href="cte_editar.php">Criar o primeiro CT-e</a>.</div>
  <?php else: ?>
  <table class="tr-table cards">
    <thead><tr><th>Número</th><th>Emissão</th><th>Tomador</th><th>Percurso</th><th style="text-align:right">Valor</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      [$lb, $cor] = $labels[$r['status']] ?? [$r['status'], 'n'];
      $edit = in_array($r['status'], CTE_EDITAVEIS, true);
      $consultar = $r['status'] === 'PENDENTE' || ($r['status'] === 'PROCESSANDO' && strtotime((string)$r['updated_at']) < time() - 120);
      $temDoc = in_array($r['status'], ['AUTORIZADO', 'CANCELADO', 'DENEGADO'], true); ?>
      <tr>
        <td><b><?= $r['numero'] !== null ? transp_h((string)$r['numero']) : '—' ?></b><div class="tr-hint">Série <?= (int)$r['serie'] ?><?= $r['ambiente'] === 'HOMOLOGACAO' ? ' · teste' : '' ?></div></td>
        <td data-l="Emissão"><?= transp_h(date('d/m/Y H:i', strtotime((string)($r['dh_emissao'] ?: $r['created_at'])))) ?></td>
        <td data-l="Tomador"><?= transp_h($r['tomador_nome'] ?: '—') ?><div class="tr-hint"><?= transp_h('De ' . ($r['remetente_nome'] ?: '—') . ' para ' . ($r['destinatario_nome'] ?: '—')) ?></div></td>
        <td data-l="Percurso"><?= transp_h(($r['mun_ini_nome'] ?: '—') . '/' . ($r['uf_ini'] ?: '') . ' → ' . ($r['mun_fim_nome'] ?: '—') . '/' . ($r['uf_fim'] ?: '')) ?></td>
        <td data-l="Valor" style="text-align:right;white-space:nowrap">R$ <?= number_format((float)$r['valor_prestacao'], 2, ',', '.') ?></td>
        <td data-l="Situação"><span class="tr-badge <?= $cor ?>"><?= transp_h($lb) ?></span><?php if ($r['xmotivo'] && $r['status'] !== 'AUTORIZADO'): ?><div class="tr-hint" style="max-width:260px"><?= transp_h(($r['cstat'] ? $r['cstat'] . ' - ' : '') . $r['xmotivo']) ?></div><?php endif; ?></td>
        <td class="act">
          <?php if ($edit): ?><a class="tr-btn sm" href="cte_editar.php?id=<?= (int)$r['id'] ?>">Editar</a>
            <form method="post" style="display:inline" data-confirm="Transmitir o CT-e para a SEFAZ?"><?= transp_csrf_field() ?><input type="hidden" name="action" value="transmitir"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="tr-btn sm pri" type="submit">Transmitir</button></form><?php endif; ?>
          <?php if ($consultar): ?><form method="post" style="display:inline"><?= transp_csrf_field() ?><input type="hidden" name="action" value="consultar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="tr-btn sm pri" type="submit">Consultar situação</button></form><?php endif; ?>
          <?php if ($temDoc): ?><a class="tr-btn sm" href="cte_arquivo.php?id=<?= (int)$r['id'] ?>&tipo=dacte" target="_blank" rel="noopener" data-no-processing="1">DACTE</a>
            <a class="tr-btn sm" href="cte_arquivo.php?id=<?= (int)$r['id'] ?>&tipo=xml" data-no-processing="1">XML</a><?php endif; ?>
          <?php if (!$edit): ?><a class="tr-btn sm" href="cte_editar.php?id=<?= (int)$r['id'] ?>">Ver</a><?php endif; ?>
          <form method="post" style="display:inline"><?= transp_csrf_field() ?><input type="hidden" name="action" value="duplicar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="tr-btn sm" type="submit" title="Criar um novo CT-e com os mesmos dados">Copiar</button></form>
          <?php if ($r['status'] === 'RASCUNHO' && $r['numero'] === null): ?><form method="post" style="display:inline" data-confirm="Excluir este rascunho?"><?= transp_csrf_field() ?><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="tr-btn sm red" type="submit">Excluir</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php transp_end(); ?>
