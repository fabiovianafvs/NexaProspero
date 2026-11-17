<?php
declare(strict_types=1);

// Ponto Eletrônico > Ocorrências: justificativas e abonos (atestado, falta justificada, serviço
// externo...) e os pedidos feitos pelos colaboradores no Meu Ponto (aprovar / rejeitar).
// O documento anexo fica fora da pasta pública e só abre para quem tem esta permissão.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_apuracao.php';

$cid = ponto_guard($pdo, 'ponto_ocorrencias');
$canAprovar = ponto_can($pdo, 'ponto_ocorrencias_aprovar');

if (isset($_GET['doc']) && ponto_v4_ready($pdo)) {
  $oc = ponto_ocorrencia_get($pdo, $cid, (int)$_GET['doc']);
  if (!$oc) { http_response_code(404); exit; }
  ponto_audit($pdo, $cid, 'ver_documento', 'ocorrencias', (int)$oc['id'], null, null, (int)$oc['colaborador_id']);
  ponto_doc_output($oc);
}

$hoje = date('Y-m-d');
$f = [
  'status' => in_array((string)($_GET['status'] ?? ''), ['PENDENTE', 'APROVADA', 'REJEITADA', 'CANCELADA'], true) ? (string)$_GET['status'] : '',
  'colaborador_id' => (int)($_GET['colaborador_id'] ?? 0),
  'de' => ponto_date_safe($_GET['de'] ?? '', date('Y-m-01', strtotime('-1 month'))),
  'ate' => ponto_date_safe($_GET['ate'] ?? '', date('Y-m-t')),
];
$back = 'ponto_ocorrencias.php?' . http_build_query(array_filter($f));

ponto_post_handle($back, function () use ($pdo, $cid, $canAprovar) {
  $a = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  $motivo = (string)($_POST['motivo'] ?? '');
  if ($a === 'save') {
    $fl = $_FILES['documento'] ?? null;
    $file = $fl && ($fl['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$fl['tmp_name']) ? ['tmp' => (string)$fl['tmp_name'], 'name' => (string)$fl['name']] : null;
    if ($fl && ($fl['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE) throw new RuntimeException('O arquivo é maior que o permitido pelo servidor.');
    ponto_ocorrencia_save($pdo, $cid, $_POST, false, $file);
    return 'Ocorrência lançada e aplicada na apuração.';
  }
  if (in_array($a, ['oc_aprovar', 'oc_rejeitar', 'trat_aprovar', 'trat_rejeitar'], true) && !$canAprovar) throw new RuntimeException('Seu usuário não pode aprovar pedidos do ponto.');
  if ($a === 'oc_aprovar') { ponto_ocorrencia_decidir($pdo, $cid, $id, 'aprovar'); return 'Justificativa aprovada.'; }
  if ($a === 'oc_rejeitar') { ponto_ocorrencia_decidir($pdo, $cid, $id, 'rejeitar', $motivo); return 'Justificativa rejeitada.'; }
  if ($a === 'oc_cancelar') { ponto_ocorrencia_decidir($pdo, $cid, $id, 'cancelar', $motivo); return 'Ocorrência cancelada (fica no histórico).'; }
  if ($a === 'trat_aprovar') { ponto_tratamento_decidir($pdo, $cid, $id, 'aprovar'); return 'Marcação aprovada e incluída na apuração.'; }
  if ($a === 'trat_rejeitar') { ponto_tratamento_decidir($pdo, $cid, $id, 'rejeitar', $motivo); return 'Pedido de marcação rejeitado.'; }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_ocorrencias', 'Ocorrências e justificativas', 'Atestados, faltas justificadas, serviços externos e outros motivos. Tipos que abonam não descontam as horas. Os pedidos dos colaboradores (Meu Ponto) chegam aqui para aprovar.', '<button class="pt-btn pri" type="button" onclick="ptOpen(\'pocModal\')">+ Lançar ocorrência</button>');
ponto_require_v4($pdo);

$tipos = ponto_tipos_ocorrencia($pdo, $cid);
$cols = ponto_options($pdo, $cid, 'colaboradores', false);
$pend = ponto_solicitacoes_pendentes($pdo, $cid);
$rows = ponto_ocorrencias_list($pdo, $cid, $f);
$users = ponto_company_users($pdo, $cid);
$stBadge = ['PENDENTE' => 'y', 'APROVADA' => 'g', 'REJEITADA' => 'r', 'CANCELADA' => 'n'];
$per = static fn($o) => date('d/m/Y', strtotime((string)$o['data_inicio'])) . ($o['data_fim'] !== $o['data_inicio'] ? ' a ' . date('d/m/Y', strtotime((string)$o['data_fim'])) : '') . ($o['hora_inicio'] ? ' · ' . substr((string)$o['hora_inicio'], 0, 5) . '–' . substr((string)$o['hora_fim'], 0, 5) : ' · dia inteiro');
$decide = static function (string $a, int $id, string $label, string $cls, bool $motivo) {
  return '<form method="post" style="display:inline-flex;gap:6px;flex-wrap:wrap;align-items:center">' . ponto_csrf_field() . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '">'
    . ($motivo ? '<input type="text" name="motivo" maxlength="300" required placeholder="Motivo" style="width:160px;min-height:32px">' : '') . '<button class="pt-btn sm ' . $cls . '" type="submit">' . $label . '</button></form>';
};
?>
<?php if ($pend['inclusoes'] || $pend['ocorrencias']): ?>
<section class="pt-card" style="margin-bottom:12px;border-color:#fde68a">
  <h2>Pedidos aguardando aprovação</h2>
  <p class="pt-sub"><?= $canAprovar ? 'Aprove ou rejeite (rejeitar pede o motivo, que o colaborador vê no Meu Ponto).' : 'Seu usuário pode ver, mas não aprovar estes pedidos (permissão "Aprovar pedidos do ponto").' ?></p>
  <table class="pt-table cards">
    <thead><tr><th>Colaborador</th><th>Pedido</th><th>Quando</th><th>Motivo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pend['inclusoes'] as $t): ?>
      <tr>
        <td data-l="Colaborador"><?= ponto_h($t['nome']) ?></td>
        <td data-l="Pedido">Incluir marcação <b><?= ponto_h(substr((string)$t['data_hora'], 11, 5)) ?></b><?= $t['intencao'] ? ' (' . ponto_h(PONTO_INTENCOES[$t['intencao']] ?? '') . ')' : '' ?></td>
        <td data-l="Dia"><?= ponto_h(date('d/m/Y', strtotime((string)$t['data']))) ?></td>
        <td data-l="Motivo"><?= ponto_h($t['motivo']) ?></td>
        <td class="act"><?php if ($canAprovar): ?><?= $decide('trat_aprovar', (int)$t['id'], 'Aprovar', 'ok', false) ?> <?= $decide('trat_rejeitar', (int)$t['id'], 'Rejeitar', 'red', true) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($pend['ocorrencias'] as $o): ?>
      <tr>
        <td data-l="Colaborador"><?= ponto_h($o['nome']) ?></td>
        <td data-l="Pedido"><?= ponto_h($o['tipo']) ?><?= $o['documento_path'] ? ' · <a href="ponto_ocorrencias.php?doc=' . (int)$o['id'] . '" target="_blank" rel="noopener">documento</a>' : '' ?></td>
        <td data-l="Quando"><?= ponto_h($per($o)) ?></td>
        <td data-l="Motivo"><?= ponto_h($o['descricao']) ?></td>
        <td class="act"><?php if ($canAprovar): ?><?= $decide('oc_aprovar', (int)$o['id'], 'Aprovar', 'ok', false) ?> <?= $decide('oc_rejeitar', (int)$o['id'], 'Rejeitar', 'red', true) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<form method="get" class="pt-card pt-search">
  <label class="f grow">Colaborador<select name="colaborador_id"><option value="">Todos</option><?php foreach ($cols as $k => $v): ?><option value="<?= (int)$k ?>" <?= $f['colaborador_id'] === (int)$k ? 'selected' : '' ?>><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
  <label class="f">De<input type="date" name="de" value="<?= ponto_h($f['de']) ?>"></label>
  <label class="f">Até<input type="date" name="ate" value="<?= ponto_h($f['ate']) ?>"></label>
  <label class="f">Situação<select name="status"><option value="">Todas</option><?php foreach ($stBadge as $k => $x): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= ponto_h(ucfirst(strtolower($k))) ?></option><?php endforeach; ?></select></label>
  <button class="pt-btn pri" type="submit">Filtrar</button>
</form>

<section class="pt-card">
  <?php if (!$rows): ?><div class="pt-empty">Nenhuma ocorrência no período.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Colaborador</th><th>Tipo</th><th>Período</th><th>Situação</th><th>Lançado / decidido</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $o): ?>
      <tr class="<?= in_array($o['status'], ['CANCELADA', 'REJEITADA'], true) ? 'off' : '' ?>">
        <td data-l="Colaborador"><?= ponto_h($o['nome']) ?></td>
        <td data-l="Tipo"><?= ponto_h($o['tipo']) ?><?= (int)$o['abona'] ? ' <span class="pt-badge g">abona</span>' : '' ?><?= $o['descricao'] ? '<br><span class="pt-hint">' . ponto_h($o['descricao']) . '</span>' : '' ?>
          <?= $o['documento_path'] ? '<br><a class="pt-hint" href="ponto_ocorrencias.php?doc=' . (int)$o['id'] . '" target="_blank" rel="noopener">📎 ' . ponto_h($o['documento_nome'] ?: 'documento') . '</a>' : '' ?></td>
        <td data-l="Período"><?= ponto_h($per($o)) ?></td>
        <td data-l="Situação"><span class="pt-badge <?= $stBadge[$o['status']] ?? 'n' ?>"><?= ponto_h(ucfirst(strtolower((string)$o['status']))) ?></span><?= $o['motivo_decisao'] ? '<br><span class="pt-hint">' . ponto_h($o['motivo_decisao']) . '</span>' : '' ?></td>
        <td data-l="Lançado"><span class="pt-hint"><?= (int)$o['pelo_colaborador'] ? 'Pedido do colaborador' : ponto_h((string)($users[(int)$o['solicitado_por']] ?? '')) ?> em <?= ponto_h(date('d/m/Y H:i', strtotime((string)$o['created_at']))) ?><?= $o['decidido_em'] && (int)$o['pelo_colaborador'] ? '<br>Decidido por ' . ponto_h((string)($users[(int)$o['decidido_por']] ?? '')) . ' em ' . ponto_h(date('d/m/Y H:i', strtotime((string)$o['decidido_em']))) : '' ?></span></td>
        <td class="act"><?php if (in_array($o['status'], ['APROVADA', 'PENDENTE'], true)): ?><details><summary class="pt-btn sm red" style="list-style:none">Cancelar</summary><div style="margin-top:6px"><?= $decide('oc_cancelar', (int)$o['id'], 'Confirmar cancelamento', 'red', true) ?></div></details><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<dialog class="pt-modal" id="pocModal">
  <form method="post" enctype="multipart/form-data">
    <?= ponto_csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="pt-mh"><h3>Lançar ocorrência</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <div class="pt-grid">
        <label class="f s2">Colaborador<select name="colaborador_id" required><option value="">—</option><?php foreach ($cols as $k => $v): ?><option value="<?= (int)$k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="f s2">Tipo<select name="tipo_ocorrencia_id" required id="pocTipo"><option value="">—</option><?php foreach ($tipos as $id => $tp): ?><option value="<?= $id ?>" data-doc="<?= (int)$tp['exige_documento'] ?>"><?= ponto_h($tp['descricao'] . ($tp['abona'] ? ' (abona)' : '')) ?></option><?php endforeach; ?></select></label>
        <label class="f">Data inicial<input type="date" name="data_inicio" required value="<?= ponto_h($hoje) ?>"></label>
        <label class="f">Data final<input type="date" name="data_fim"></label>
        <label class="f">Das (só parte do dia)<input type="time" name="hora_inicio"></label>
        <label class="f">Às<input type="time" name="hora_fim"></label>
        <label class="f s2">Descrição<input type="text" name="descricao" maxlength="500" placeholder="Evite dados médicos desnecessários (não informe o CID)"></label>
        <label class="f s2">Documento <span class="pt-hint" id="pocDoc"></span><input type="file" name="documento" accept="application/pdf,image/jpeg,image/png"></label>
      </div>
      <p class="pt-hint" style="margin-top:8px">Sem horário = dia(s) inteiro(s). O documento (PDF, JPG ou PNG, até 8 MB) fica guardado fora do site e só abre para quem tem acesso a esta tela.</p>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Lançar</button></div>
  </form>
</dialog>
<script>
document.getElementById('pocTipo').addEventListener('change', function(){ const o = this.selectedOptions[0]; document.getElementById('pocDoc').textContent = o && o.dataset.doc === '1' ? '(obrigatório para este tipo)' : ''; });
</script>
<?php ponto_end(); ?>
