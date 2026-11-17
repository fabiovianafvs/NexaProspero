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
    ponto_audit($pdo, $cid, 'gerar_' . $tipo, 'arquivos', null, null, ['estabelecimento' => $estabs[$estabId], 'periodo' => $de . ' a ' . $ate, 'arquivo' => $nome, 'sha256' => hash('sha256', $conteudo), 'registros' => $qt]);
    ponto_arquivo_download($nome, $conteudo);
  } catch (Throwable $e) {
    ponto_flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gerar o arquivo.');
    header('Location: ' . $back, true, 303);
    exit;
  }
}

ponto_post_handle($back, function () use ($pdo, $cid, $estabId) {
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
  <p class="pt-hint" style="margin:0">Os arquivos saem no formato do Ministério do Trabalho (texto ISO-8859-1), com a linha "ASSINATURA_DIGITAL_EM_ARQUIVO_P7S". A assinatura digital com certificado ICP-Brasil (arquivo .p7s que acompanha cada um) entra na próxima versão do Nexa Ponto. A Portaria 671 dá prazo mínimo de 2 dias para a entrega ao Auditor-Fiscal (art. 85).</p>
</section>
<?php ponto_end(); ?>
