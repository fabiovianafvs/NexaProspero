<?php
declare(strict_types=1);

// Ponto Eletrônico > Arquivos legais: gera o AFD (Arquivo Fonte de Dados, do registrador REP-P)
// e o AEJ (Arquivo Eletrônico de Jornada, do tratamento) para entregar ao Auditor-Fiscal do
// Trabalho, e leva ao Espelho de Ponto. Cada arquivo gerado fica registrado na auditoria.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_fechamento.php';

$cid = ponto_guard($pdo, 'ponto_arquivos');
$estabs = ponto_options($pdo, $cid, 'estabelecimentos', false);
$estabId = (int)($_REQUEST['e'] ?? 0);
if (!isset($estabs[$estabId])) $estabId = (int)(array_key_first($estabs) ?? 0);
$de = ponto_date_safe($_REQUEST['de'] ?? '', date('Y-m-01', strtotime(date('Y-m-01') . ' -1 month')));
$ate = ponto_date_safe($_REQUEST['ate'] ?? '', date('Y-m-t', strtotime(date('Y-m-01') . ' -1 month')));
if ($ate < $de) $ate = $de;
$back = 'ponto_arquivos.php?' . http_build_query(['e' => $estabId, 'de' => $de, 'ate' => $ate]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array((string)($_POST['action'] ?? ''), ['afd', 'aej'], true) && ponto_v6_ready($pdo)) {
  try {
    ponto_csrf_check();
    if (!$estabId) throw new RuntimeException('Escolha o estabelecimento.');
    if ((strtotime($ate) - strtotime($de)) > 400 * 86400) throw new RuntimeException('Período máximo: 13 meses por arquivo.');
    $tipo = (string)$_POST['action'];
    [$nome, $conteudo, $qt] = $tipo === 'afd' ? ponto_afd_gerar($pdo, $cid, $estabId, $de, $ate) : ponto_aej_gerar($pdo, $cid, $estabId, $de, $ate);
    // V7: AFD assinado pelo desenvolvedor; AEJ pela empresa (A1 das notas) ou, sem ele, pelo desenvolvedor.
    $signer = $tipo === 'aej' ? ponto_cert_empresa($pdo, $cid) : null;
    if (!$signer) $signer = ponto_cert_desenvolvedor();
    $assinado = null;
    if ($signer) {
      [$p7s] = ponto_cades_assinar($conteudo, $signer);
      $assinado = $signer['titular'];
    }
    ponto_audit($pdo, $cid, 'gerar_' . $tipo, 'arquivos', null, null, ['estabelecimento' => $estabs[$estabId], 'periodo' => $de . ' a ' . $ate, 'arquivo' => $nome, 'sha256' => hash('sha256', $conteudo),
      'registros' => $qt, 'assinado_por' => $assinado ?? 'sem assinatura']);
    if ($signer) {
      $zip = ponto_zip([$nome => $conteudo, $nome . '.p7s' => $p7s]);
      while (ob_get_level() > 0) ob_end_clean();
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="' . preg_replace('/\.txt$/', '', $nome) . '.zip"');
      header('Content-Length: ' . strlen($zip));
      header('Cache-Control: no-store');
      echo $zip;
      exit;
    }
    ponto_arquivo_download($nome, $conteudo);
  } catch (Throwable $e) {
    ponto_flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gerar o arquivo.');
    header('Location: ' . $back, true, 303);
    exit;
  }
}

// Atestado Técnico: modelo para assinar (GET) e o PDF assinado guardado.
if (isset($_GET['attr_modelo']) && $estabId && in_array((string)$_GET['attr_modelo'], ['REP-P', 'PTRP'], true)) {
  $pdfA = ponto_attr_pdf($pdo, $cid, ponto_estabelecimento_get($pdo, $cid, $estabId), (string)$_GET['attr_modelo']);
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="Atestado_Tecnico_' . str_replace('-', '', (string)$_GET['attr_modelo']) . '_para_assinar.pdf"');
  echo $pdfA;
  exit;
}
if (isset($_GET['attr'])) {
  $lista = ponto_attr_list($pdo, $cid);
  $a = $lista[(int)$_GET['attr']] ?? null;
  $f = $a ? ponto_attr_arquivo($a) : null;
  if (!$f) { http_response_code(404); exit; }
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="Atestado_Tecnico_' . str_replace('-', '', (string)$a['tipo']) . '.pdf"');
  header('X-Content-Type-Options: nosniff');
  readfile($f);
  exit;
}

ponto_post_handle($back, function () use ($pdo, $cid, $estabId) {
  if ((string)($_POST['action'] ?? '') === 'attr_enviar') {
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException('Escolha o PDF assinado.');
    ponto_attr_salvar($pdo, $cid, (string)($_POST['tipo'] ?? ''), (string)$f['tmp_name'], (string)$f['name']);
    return 'Atestado assinado guardado. Ele fica disponível aqui para a fiscalização.';
  }
  if ((string)($_POST['action'] ?? '') !== 'sincronizar') throw new RuntimeException('Ação inválida.');
  [$n2, $n5] = ponto_arp_sincronizar($pdo, $cid, $estabId);
  return $n2 + $n5 ? 'Registrado no REP: ' . ($n2 ? 'dados do empregador' : '') . ($n2 && $n5 ? ' e ' : '') . ($n5 ? $n5 . ' colaborador(es)' : '') . '.' : 'Tudo já estava registrado no REP.';
});

ponto_start($pdo, 'ponto_arquivos', 'Arquivos legais', 'AFD e AEJ no leiaute do Ministério do Trabalho, para entregar ao Auditor-Fiscal quando solicitado, e o Espelho de Ponto mensal.');
ponto_require_v4($pdo);
if (!ponto_v6_ready($pdo)) {
  echo '<div class="pt-msg warn">Os arquivos legais ainda não foram instalados no banco. O administrador da plataforma precisa aplicar "Ponto Eletrônico - V6 Arquivos legais (AFD/AEJ)" no Atualizador do Banco.</div>';
  ponto_end();
  exit;
}
$pend = ponto_afd_pendencias($pdo, $cid);
$falta = 0;
if ($estabId) {
  $st = $pdo->prepare("SELECT COUNT(*) FROM ponto_colaboradores c WHERE c.company_id = ? AND c.estabelecimento_id = ? AND c.controla_ponto = 1 AND c.status <> 'DESLIGADO'
    AND NOT EXISTS (SELECT 1 FROM ponto_arp_eventos e WHERE e.company_id = c.company_id AND e.estabelecimento_id = c.estabelecimento_id AND e.tipo = '5' AND e.colaborador_id = c.id)");
  $st->execute([$cid, $estabId]);
  $falta = (int)$st->fetchColumn();
  $s2 = $pdo->prepare("SELECT COUNT(*) FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ? AND tipo = '2'");
  $s2->execute([$cid, $estabId]);
  $sem2 = !(int)$s2->fetchColumn();
}
$v = $estabId ? ponto_arp_verificar($pdo, $cid, $estabId) : null;
$cs = ponto_cert_status($pdo, $cid);
$attrs = ponto_attr_list($pdo, $cid);
$users = ponto_company_users($pdo, $cid);
?>
<?php if ($pend): ?>
  <div class="pt-msg warn"><b>Antes de entregar os arquivos, complete em <a href="ponto_configuracoes.php">Configurações</a>:</b>
    <ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($pend as $p): ?><li><?= ponto_h($p) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$estabs): ?><div class="pt-msg warn">Cadastre o estabelecimento em Ponto Eletrônico &gt; Estabelecimentos.</div><?php ponto_end(); exit; endif; ?>

<form method="get" class="pt-card pt-search">
  <?php if (count($estabs) > 1): ?><label class="f grow">Estabelecimento<select name="e"><?php foreach ($estabs as $k => $n): ?><option value="<?= (int)$k ?>" <?= (int)$k === $estabId ? 'selected' : '' ?>><?= ponto_h($n) ?></option><?php endforeach; ?></select></label><?php else: ?><input type="hidden" name="e" value="<?= $estabId ?>"><?php endif; ?>
  <label class="f">De<input type="date" name="de" value="<?= ponto_h($de) ?>"></label>
  <label class="f">Até<input type="date" name="ate" value="<?= ponto_h($ate) ?>"></label>
  <button class="pt-btn" type="submit">Aplicar período</button>
</form>

<?php if ($v): ?><div class="pt-msg <?= $v['ok'] ? 'ok' : 'err' ?>"><?= $v['ok'] ? 'Registros do REP íntegros: ' . (int)$v['total'] . ' registro(s) com numeração (NSR) e códigos hash conferidos.' : 'PROBLEMA nos registros do REP: ' . ponto_h($v['erro']) . ' Avise o suporte antes de entregar o AFD.' ?></div><?php endif; ?>
<?php if ($falta || !empty($sem2)): ?>
  <div class="pt-msg warn" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><span style="flex:1"><?= !empty($sem2) ? 'Os dados do empregador ainda não estão registrados no REP. ' : '' ?><?= $falta ? $falta . ' colaborador(es) ativo(s) ainda não registrado(s) no REP (acontece com quem foi cadastrado antes desta versão; a 1ª marcação de cada um também registra).' : '' ?></span>
    <form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="sincronizar"><input type="hidden" name="e" value="<?= $estabId ?>"><button class="pt-btn pri" type="submit">Registrar agora</button></form></div>
<?php endif; ?>

<section class="pt-card" style="margin-bottom:12px">
  <h2>Assinatura digital (ICP-Brasil)</h2>
  <div class="pt-grid">
    <div><b>AFD e comprovantes</b> — certificado do desenvolvedor:<br>
      <?php if ($cs['dev']): ?><span class="pt-badge g">Configurado</span> <?= ponto_h($cs['dev']['titular']) ?> · válido até <?= ponto_h(date('d/m/Y', (int)$cs['dev']['valido_ate'])) ?>
      <?php elseif ($cs['dev_erro']): ?><span class="pt-badge r">Com problema</span> <?= ponto_h($cs['dev_erro']) ?>
      <?php else: ?><span class="pt-badge y">Não configurado</span> os arquivos saem sem o .p7s. O administrador da plataforma configura no servidor.<?php endif; ?></div>
    <div><b>AEJ</b> — certificado da empresa (o mesmo das notas fiscais):<br>
      <?php if ($cs['empresa']): ?><span class="pt-badge g">Em uso</span> <?= ponto_h($cs['empresa']['titular']) ?> · válido até <?= ponto_h(date('d/m/Y', (int)$cs['empresa']['valido_ate'])) ?>
      <?php else: ?><span class="pt-badge n">Sem certificado A1 ativo</span> o AEJ é assinado pelo desenvolvedor (a Portaria permite).<?php endif; ?></div>
  </div>
  <p class="pt-hint" style="margin:8px 0 0">Com certificado, cada arquivo baixa num .zip com o .txt e o .p7s (assinatura CAdES destacada), como pede a Portaria 671 (art. 88).</p>
</section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
  <section class="pt-card">
    <h2>AFD — Arquivo Fonte de Dados</h2>
    <p class="pt-sub">Gerado pelo registrador (REP-P): empregador, empregados e todas as marcações originais do período, na ordem do NSR, com os códigos de verificação. Leiaute 004.</p>
    <form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="afd"><input type="hidden" name="e" value="<?= $estabId ?>"><input type="hidden" name="de" value="<?= ponto_h($de) ?>"><input type="hidden" name="ate" value="<?= ponto_h($ate) ?>">
      <button class="pt-btn pri" type="submit" data-no-processing>Baixar AFD (<?= ponto_h(date('d/m/Y', strtotime($de))) ?> a <?= ponto_h(date('d/m/Y', strtotime($ate))) ?>)</button></form>
  </section>
  <section class="pt-card">
    <h2>AEJ — Arquivo Eletrônico de Jornada</h2>
    <p class="pt-sub">Gerado pelo tratamento (PTRP): vínculos, horários contratuais, marcações tratadas (incluídas e desconsideradas, com motivo), faltas e banco de horas. Leiaute 002.</p>
    <form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="aej"><input type="hidden" name="e" value="<?= $estabId ?>"><input type="hidden" name="de" value="<?= ponto_h($de) ?>"><input type="hidden" name="ate" value="<?= ponto_h($ate) ?>">
      <button class="pt-btn pri" type="submit" data-no-processing>Baixar AEJ (<?= ponto_h(date('d/m/Y', strtotime($de))) ?> a <?= ponto_h(date('d/m/Y', strtotime($ate))) ?>)</button></form>
  </section>
  <section class="pt-card">
    <h2>Espelho de Ponto</h2>
    <p class="pt-sub">Relatório mensal de cada colaborador com marcações originais e tratadas, horário contratual e jornada (art. 84). O colaborador também vê o dele no Meu Ponto.</p>
    <a class="pt-btn pri" href="ponto_espelho.php?<?= ponto_h(http_build_query(['e' => $estabId, 'c' => substr($de, 0, 7)])) ?>" target="_blank" rel="noopener">Abrir espelhos de <?= ponto_h(ponto_comp_label(substr($de, 0, 7))) ?></a>
  </section>
</div>
<section class="pt-card" style="margin-top:12px">
  <h2>Atestado Técnico e Termo de Responsabilidade</h2>
  <p class="pt-sub">Documento do desenvolvedor que a empresa precisa ter para usar o ponto (Portaria 671, art. 89). Baixe o modelo, os responsáveis legal e técnico do desenvolvedor assinam com o e-CPF (PDF assinado, padrão PAdES) e o arquivo assinado é enviado aqui, onde fica guardado para a fiscalização.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <a class="pt-btn" href="ponto_arquivos.php?<?= ponto_h(http_build_query(['e' => $estabId, 'attr_modelo' => 'REP-P'])) ?>" data-no-processing download>Modelo REP-P (registro)</a>
    <a class="pt-btn" href="ponto_arquivos.php?<?= ponto_h(http_build_query(['e' => $estabId, 'attr_modelo' => 'PTRP'])) ?>" data-no-processing download>Modelo PTRP (tratamento)</a>
  </div>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <?= ponto_csrf_field() ?><input type="hidden" name="action" value="attr_enviar"><input type="hidden" name="e" value="<?= $estabId ?>">
    <label class="f">Tipo<select name="tipo"><option value="REP-P">REP-P</option><option value="PTRP">PTRP</option></select></label>
    <label class="f grow">PDF assinado<input type="file" name="arquivo" accept="application/pdf" required></label>
    <button class="pt-btn pri" type="submit">Enviar atestado assinado</button>
  </form>
  <?php if ($attrs): ?>
    <table class="pt-table cards" style="margin-top:10px">
      <thead><tr><th>Tipo</th><th>Arquivo</th><th>Enviado</th><th></th></tr></thead>
      <tbody><?php foreach (array_reverse($attrs, true) as $i => $a): ?>
        <tr><td data-l="Tipo"><b><?= ponto_h($a['tipo']) ?></b></td><td data-l="Arquivo"><?= ponto_h($a['nome']) ?></td>
          <td data-l="Enviado"><span class="pt-hint"><?= ponto_h(date('d/m/Y H:i', strtotime((string)$a['enviado_em']))) ?> · <?= ponto_h((string)($users[(int)$a['por']] ?? '')) ?></span></td>
          <td class="act"><a class="pt-btn sm" href="ponto_arquivos.php?attr=<?= (int)$i ?>" target="_blank" rel="noopener">Abrir</a></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php else: ?><div class="pt-msg warn" style="margin:10px 0 0">Nenhum atestado assinado enviado ainda. Sem ele, a empresa não pode usar o sistema de ponto perante a fiscalização (art. 89, § 4º).</div><?php endif; ?>
  <p class="pt-hint" style="margin:10px 0 0">A Portaria 671 dá prazo mínimo de 2 dias para a entrega dos arquivos ao Auditor-Fiscal (art. 85).</p>
</section>
<?php ponto_end(); ?>
