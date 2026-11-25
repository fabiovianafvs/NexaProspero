<?php
declare(strict_types=1);

// Trilha de Implantação: relatório de atendimento técnico para imprimir/salvar em PDF.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_implantacao.php';
require __DIR__ . '/_implantacao_print.php';
$cid = impl_guard($pdo, 'crm_implantacao_relatorios');
$rel = impl_ready($pdo) ? impl_relatorio($pdo, $cid, (int)($_GET['id'] ?? 0)) : null;
if (!$rel) { http_response_code(404); exit('Relatório não encontrado.'); }
$impl = impl_implantacao($pdo, $cid, (int)$rel['implantacao_id']);
$tarefas = impl_relatorio_tarefas_nomes($pdo, $cid, (int)$rel['id']);
$part = impl_participantes((string)$rel['participantes']);
$min = impl_minutos((string)$rel['hora_inicio'], (string)$rel['hora_fim']);
impl_print_head($pdo, $cid, 'Relatório de Atendimento Técnico', 'Nº ' . (int)$rel['numero'] . ($rel['status'] === 'CONFIRMADO' ? '' : ' · RASCUNHO'));
$end = trim(implode(', ', array_filter([(string)($impl['cliente_endereco'] ?? ''), (string)($impl['cliente_numero'] ?? ''), (string)($impl['cliente_bairro'] ?? '')])) . ' ' . trim((string)($impl['cliente_cidade'] ?? '') . '/' . (string)($impl['cliente_uf'] ?? ''), '/ '));
?>
<section><h2>Cliente e atendimento</h2><div class="g">
  <div><b>Cliente:</b><?= impl_h($impl['cliente'] ?? '') ?></div><div><b>CPF/CNPJ:</b><?= impl_h($impl['cliente_doc'] ?? '-') ?></div>
  <div style="grid-column:1/-1"><b>Endereço:</b><?= impl_h($end ?: '-') ?></div>
  <div><b>Data:</b><?= impl_data_br($rel['data']) ?></div><div><b>Horário:</b><?= impl_h(substr((string)$rel['hora_inicio'], 0, 5) . ' às ' . substr((string)$rel['hora_fim'], 0, 5)) ?> (<?= impl_horas_txt($min) ?>)</div>
  <div><b>Técnico/implantador:</b><?= impl_h($rel['implantador'] ?: '-') ?></div><div><b>Trilha:</b><?= impl_h($impl['trilha_nome'] ?? '-') ?></div>
</div></section>
<section><h2>Participantes do treinamento</h2><?= $part ? '<ul><li>' . implode('</li><li>', array_map('impl_h', $part)) . '</li></ul>' : '<p class="mut">—</p>' ?></section>
<section><h2>Processos implantados</h2>
  <?php if ($tarefas): ?><table><thead><tr><th style="width:35%">Etapa</th><th>Processo</th></tr></thead><tbody><?php foreach ($tarefas as $t): ?><tr><td><?= impl_h($t['etapa_nome']) ?></td><td><?= impl_h($t['nome']) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php else: ?><p class="mut">—</p><?php endif; ?>
</section>
<?php if (trim((string)$rel['observacoes']) !== ''): ?><section><h2>Observações</h2><p style="white-space:pre-wrap;margin:0"><?= impl_h($rel['observacoes']) ?></p></section><?php endif; ?>
<div class="sg">
  <div><br><?= impl_h($rel['implantador'] ?: 'Técnico responsável') ?></div>
  <div><?php if ($rel['assinatura_arquivo']): ?><img src="implantacao_arquivo.php?f=<?= rawurlencode((string)$rel['assinatura_arquivo']) ?>" alt="Assinatura"><?php else: ?><br><?php endif; ?>
    <?= impl_h($rel['assinatura_nome'] ?: 'Cliente') ?><?php if ($rel['assinatura_em']): ?><br><span class="mut">Assinado em <?= impl_h(date('d/m/Y H:i', strtotime((string)$rel['assinatura_em']))) ?></span><?php endif; ?></div>
</div>
<?php impl_print_end();
