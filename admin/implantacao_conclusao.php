<?php
declare(strict_types=1);

// Trilha de Implantação: relatório de conclusão da implantação (só com status do tipo Concluído).
// Resumo das datas, pessoas treinadas, procedimentos realizados no período, fotos dos
// equipamentos instalados e o aviso sobre serviços fora do suporte aos sistemas.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_implantacao.php';
require __DIR__ . '/_implantacao_print.php';
$cid = impl_guard($pdo, 'crm_implantacao_documentacao');
try { $d = impl_ready($pdo) ? impl_conclusao_dados($pdo, $cid, (int)($_GET['id'] ?? 0)) : null; } catch (RuntimeException $e) { $d = null; }
if (!$d) { http_response_code(404); exit('Implantação não encontrada.'); }
$i = $d['impl'];
if (($i['status_tipo'] ?? '') !== 'CONCLUIDO') { http_response_code(409); exit('O relatório de conclusão fica disponível quando o status da implantação for Concluído.'); }
impl_print_head($pdo, $cid, 'Relatório de Conclusão da Implantação', impl_data_br($d['primeiro']) . ' a ' . impl_data_br($i['data_conclusao'] ?: $d['ultimo']));
$end = trim(implode(', ', array_filter([(string)($i['cliente_endereco'] ?? ''), (string)($i['cliente_numero'] ?? ''), (string)($i['cliente_bairro'] ?? '')])) . ' ' . trim((string)($i['cliente_cidade'] ?? '') . '/' . (string)($i['cliente_uf'] ?? ''), '/ '));
$porEtapa = [];
foreach ($d['feitas'] as $t) $porEtapa[(string)$t['etapa_nome']][] = $t;
$impls = $d['implantadores'] ?: array_filter([(string)($i['implantador'] ?? '')]);
?>
<section><h2>Cliente</h2><div class="g">
  <div><b>Cliente:</b><?= impl_h($i['cliente']) ?></div><div><b>CPF/CNPJ:</b><?= impl_h($i['cliente_doc'] ?: '-') ?></div>
  <div style="grid-column:1/-1"><b>Endereço:</b><?= impl_h($end ?: '-') ?></div>
  <div><b>Trilha:</b><?= impl_h($i['trilha_nome'] ?: '-') ?></div><div><b>Implantador(es):</b><?= impl_h(implode(', ', $impls) ?: '-') ?></div>
  <div><b>Início da implantação:</b><?= impl_data_br($i['data_inicio']) ?></div><div><b>Conclusão:</b><?= impl_data_br($i['data_conclusao']) ?></div>
</div></section>

<section><h2>Resumo</h2><div class="kp">
  <div>Atendimentos<b><?= count($d['relatorios']) ?></b></div>
  <div>Horas de treinamento<b><?= impl_horas_txt((int)$d['minutos']) ?></b></div>
  <div>Pessoas treinadas<b><?= count($d['treinandos']) ?></b></div>
  <div>Procedimentos<b><?= count($d['feitas']) ?>/<?= (int)$d['total'] ?></b></div>
</div></section>

<section><h2>Atendimentos realizados</h2>
<?php if (!$d['relatorios']): ?><p class="mut">Nenhum relatório de atendimento confirmado.</p><?php else: ?>
<table><thead><tr><th style="width:12%">Data</th><th style="width:14%">Horário</th><th style="width:16%">Implantador</th><th style="width:22%">Participantes</th><th>Procedimentos</th></tr></thead><tbody>
<?php foreach ($d['relatorios'] as $r): ?>
  <tr><td><?= impl_data_br($r['data']) ?><br><span class="mut">nº <?= (int)$r['numero'] ?></span></td>
    <td><?= impl_h(substr((string)$r['hora_inicio'], 0, 5) . '–' . substr((string)$r['hora_fim'], 0, 5)) ?><br><span class="mut"><?= impl_horas_txt((int)$r['minutos']) ?></span></td>
    <td><?= impl_h($r['implantador'] ?: '-') ?></td>
    <td><?= impl_h(implode(', ', impl_participantes((string)$r['participantes'])) ?: '-') ?></td>
    <td><?= impl_h(implode('; ', array_map(static fn($t) => (string)$t['nome'], $r['tarefas_nomes'])) ?: (trim((string)$r['observacoes']) !== '' ? mb_substr((string)$r['observacoes'], 0, 200) : '-')) ?></td></tr>
<?php endforeach; ?>
</tbody></table><?php endif; ?>
</section>

<section><h2>Pessoas treinadas</h2><?= $d['treinandos'] ? '<p style="margin:0">' . impl_h(implode(', ', $d['treinandos'])) . '</p>' : '<p class="mut">—</p>' ?></section>

<section><h2>Procedimentos realizados</h2>
<?php if (!$porEtapa): ?><p class="mut">—</p><?php else: ?>
<table><thead><tr><th style="width:28%">Etapa</th><th>Procedimento</th><th style="width:14%">Data</th></tr></thead><tbody>
<?php foreach ($porEtapa as $etapa => $ts): foreach ($ts as $k => $t): ?>
  <tr><?php if ($k === 0): ?><td rowspan="<?= count($ts) ?>"><?= impl_h($etapa) ?></td><?php endif; ?><td><?= impl_h($t['nome']) ?></td><td><?= impl_h(date('d/m/Y', strtotime((string)$t['done_at']))) ?></td></tr>
<?php endforeach; endforeach; ?>
</tbody></table><?php endif; ?>
<?php if ($d['pendentes']): ?><p class="mut" style="margin:4px 0 0">Não realizados: <?= impl_h(implode('; ', array_map(static fn($t) => (string)$t['nome'], $d['pendentes']))) ?>.</p><?php endif; ?>
</section>

<?php if ($d['fotos']): ?>
<section><h2>Equipamentos instalados</h2>
  <div class="ph"><?php foreach ($d['fotos'] as $f): ?><figure><img src="implantacao_arquivo.php?f=<?= rawurlencode((string)$f['arquivo']) ?>" alt=""><?php if ($f['legenda']): ?><figcaption><?= impl_h($f['legenda']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?></div>
  <div class="av"><b>Importante:</b> <?= nl2br(impl_h($d['aviso'])) ?></div>
</section>
<?php endif; ?>

<p style="margin-top:14px">Declaramos concluída a implantação descrita acima, com os treinamentos e procedimentos relacionados neste relatório.</p>
<div class="sg"><div><?= impl_h(implode(', ', $impls) ?: 'Responsável pela implantação') ?></div><div><?= impl_h($i['cliente']) ?><br><span class="mut">Nome e assinatura do cliente</span></div></div>
<?php impl_print_end();
