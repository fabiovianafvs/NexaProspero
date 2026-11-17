<?php
declare(strict_types=1);

// Nexa Ponto: impressão do Espelho de Ponto (Portaria 671, art. 84). Usado por
// ponto_espelho.php (responsável, todos os colaboradores) e ponto_meu_espelho.php (o próprio).

if (!function_exists('ponto_espelho_render')) {
  /**
   * $lista = resultados de ponto_espelho_dados(). $toolbar = HTML dos filtros (não sai na impressão).
   */
  function ponto_espelho_render(string $titulo, array $lista, string $toolbar, string $compLabel): void {
    $h = 'ponto_h';
    $wd = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    $wdl = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $emitido = date('d/m/Y H:i');
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($titulo) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef3fb;color:#0f172a;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:12px}
.tb{position:sticky;top:0;z-index:5;background:#071b55;color:#fff;padding:10px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.tb form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.tb select,.tb input{min-height:36px;border-radius:10px;border:0;padding:0 10px;font:inherit;font-size:13px}
.tb button,.tb a{min-height:36px;border-radius:10px;border:1px solid rgba(255,255,255,.4);background:transparent;color:#fff;font-weight:800;padding:0 12px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.tb .pri{background:#fff;color:#071b55}
.pg{background:#fff;width:210mm;max-width:100%;margin:14px auto;padding:12mm 11mm;box-shadow:0 10px 30px rgba(11,31,91,.1)}
.pg h1{font-size:16px;margin:0 0 6px;text-align:center;letter-spacing:.02em}
.id{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;border:1px solid #cbd5e1;border-radius:6px;padding:7px 9px;margin-bottom:8px}
.id div{font-size:11.5px}.id b{font-weight:800}
.id .t{grid-column:1/-1;font-size:10.5px;color:#475569;text-transform:uppercase;letter-spacing:.04em;font-weight:800;margin-bottom:-2px}
table{width:100%;border-collapse:collapse;font-size:11px}
th,td{border:1px solid #cbd5e1;padding:3px 4px;vertical-align:top}
th{background:#f1f5f9;font-size:10px;text-transform:uppercase;letter-spacing:.03em}
td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tr.fds td{background:#f8fafc}
.mk{display:inline-block;margin-right:4px;font-variant-numeric:tabular-nums;white-space:nowrap}
.mk.i{font-weight:800}.mk.d{text-decoration:line-through;color:#64748b}
.mk sup{font-size:8px;font-weight:900}
.tot{margin-top:8px;display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.tot div{border:1px solid #cbd5e1;border-radius:6px;padding:5px 7px}.tot span{display:block;font-size:9.5px;color:#475569;text-transform:uppercase;font-weight:800}.tot b{font-size:13px}
.trt{margin-top:8px;font-size:10.5px}.trt li{margin:1px 0}
.leg{margin-top:6px;font-size:10px;color:#475569}
.ass{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:26px;font-size:10.5px;text-align:center}
.ass div{border-top:1px solid #0f172a;padding-top:4px}
.foot{margin-top:10px;font-size:9.5px;color:#64748b;display:flex;justify-content:space-between;gap:10px}
.vazio{text-align:center;padding:40px;color:#475569;font-size:14px}
@media print{body{background:#fff}.tb{display:none}.pg{box-shadow:none;margin:0;width:auto;padding:0 0 4mm;page-break-after:always}.pg:last-child{page-break-after:auto}@page{size:A4;margin:9mm}}
@media(max-width:700px){.id{grid-template-columns:1fr}.tot{grid-template-columns:repeat(2,1fr)}.pg{padding:10px;overflow-x:auto}table{min-width:620px}}
</style>
</head>
<body>
<div class="tb"><?= $toolbar ?><span style="flex:1"></span><button type="button" class="pri" onclick="window.print()">Imprimir / salvar PDF</button></div>
<?php if (!$lista): ?><div class="pg vazio">Nenhum colaborador no período.</div><?php endif; ?>
<?php foreach ($lista as $e):
  $c = $e['col']; $es = $e['estab'] ?? []; $tot = $e['totais'];
  $doc = (string)($es['documento'] ?? ''); $docTipo = ((string)($es['tipo_documento'] ?? 'CNPJ')) === 'CPF' ? 'CPF' : 'CNPJ';
  $extra = array_filter(['CAEPF' => (string)($es['caepf'] ?? ''), 'CNO' => (string)($es['cno'] ?? '')]);
  $trat = [];
  $redTot = 0;
  ?>
  <section class="pg">
    <h1>ESPELHO DE PONTO ELETRÔNICO · <?= $h(mb_strtoupper($compLabel)) ?></h1>
    <div class="id">
      <div class="t">Empregador</div>
      <div><b><?= $h($es['razao_social'] ?? '—') ?></b></div>
      <div><?= $docTipo ?>: <b><?= $h(ponto_fmt_doc($doc)) ?></b><?php foreach ($extra as $k => $v): ?> · <?= $h($k) ?>: <b><?= $h($v) ?></b><?php endforeach; ?></div>
      <div class="t">Trabalhador</div>
      <div><b><?= $h($c['nome']) ?></b> · Matrícula <?= $h($c['matricula']) ?></div>
      <div>CPF: <b><?= $h(ponto_fmt_doc((string)$c['cpf'])) ?></b> · Admissão: <b><?= $h(date('d/m/Y', strtotime((string)$c['data_admissao']))) ?></b> · Cargo/função: <b><?= $h($e['cargo'] ?: '—') ?></b></div>
      <div class="t">Período e horário contratual</div>
      <div>Período: <b><?= $h(date('d/m/Y', strtotime($e['de']))) ?> a <?= $h(date('d/m/Y', strtotime($e['ate']))) ?></b> · Emissão: <b><?= $h($emitido) ?></b></div>
      <div><?php if (!$e['horarios']): ?>Sem horário contratual no período.<?php endif; ?>
        <?php foreach ($e['horarios'] as $k => $hr): $ds = array_keys($hr['dias']); sort($ds); ?>
          <div><b><?= $h(implode(', ', array_map(static fn($d) => $wdl[$d], $ds))) ?>:</b> <?= $h(implode(' / ', array_map(static fn($q) => $q['ini'] . '–' . $q['fim'], $hr['periodos']))) ?> (jornada <?= $h(ponto_hm((int)$hr['duracao'], false)) ?>)</div>
        <?php endforeach; ?></div>
    </div>
    <table>
      <thead><tr><th style="width:52px">Dia</th><th>Marcações (REP e tratadas)</th><th style="width:58px">Jornada realizada</th><th style="width:46px">Extra</th><th style="width:46px">Débito</th><th style="width:46px">Noturno</th><th>Situação / ocorrência</th></tr></thead>
      <tbody>
      <?php foreach ($e['dias'] as $d => $r):
        if (in_array($r['situacao'], ['FUTURO', 'FORA_CONTRATO', 'ANTES_INICIO'], true) && !$r['marcacoes']) continue;
        $w = (int)date('w', strtotime($d));
        $redTot += (int)$r['duracao_reduzida']; ?>
        <tr class="<?= ($w === 0 || $w === 6) ? 'fds' : '' ?>">
          <td><?= date('d/m', strtotime($d)) ?> <?= $wd[$w] ?></td>
          <td><?php foreach ($r['marcacoes'] as $m):
            if (!empty($m['pendente'])) continue;
            $cls = !empty($m['desconsiderada']) ? 'd' : ($m['origem'] === 'INCLUIDA' ? 'i' : '');
            if ($cls) $trat[] = date('d/m H:i', $m['ts']) . ' — ' . ($cls === 'd' ? 'desconsiderada' : 'incluída') . ': ' . (string)($m['motivo'] ?? ''); ?>
            <span class="mk <?= $cls ?>"><?= date('H:i', $m['ts']) ?><?= $cls === 'i' ? '<sup>I</sup>' : ($cls === 'd' ? '<sup>D</sup>' : '') ?><?= substr((string)$m['data_hora'], 0, 10) !== $d ? '<sup>+1</sup>' : '' ?></span>
          <?php endforeach; ?></td>
          <td class="n"><?= $h(ponto_hm((int)$r['duracao_reduzida'])) ?></td>
          <td class="n"><?= $h(ponto_hm((int)$r['extra'])) ?></td>
          <td class="n"><?= $h(ponto_hm((int)$r['falta'])) ?></td>
          <td class="n"><?= $h(ponto_hm((int)$r['noturnos'])) ?></td>
          <td><?= $h((PONTO_SITUACOES[$r['situacao']][0] ?? $r['situacao'])) ?><?= $r['ocorrencias'] ? ' · ' . $h(implode(', ', array_column($r['ocorrencias'], 'tipo'))) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="tot">
      <div><span>Jornada realizada</span><b><?= $h(ponto_hm($redTot, false)) ?></b></div>
      <div><span>Horas extras</span><b><?= $h(ponto_hm((int)$tot['extra'], false)) ?></b></div>
      <div><span>Débitos</span><b><?= $h(ponto_hm((int)$tot['falta'], false)) ?></b></div>
      <div><span>Faltas (dias) / noturno</span><b><?= (int)$tot['faltas_dias'] ?> / <?= $h(ponto_hm((int)$tot['noturnos'], false)) ?></b></div>
    </div>
    <div class="leg">Marcações: hora normal = original do registrador (REP); <b>I</b> = incluída no tratamento; <b>D</b> (riscada) = desconsiderada; +1 = dia seguinte. Jornada realizada considera a hora noturna reduzida (22h às 5h: 52min30s = 1h).</div>
    <?php if ($trat): ?><div class="trt"><b>Marcações tratadas:</b><ul style="margin:3px 0 0;padding-left:16px"><?php foreach ($trat as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="ass"><div>Empregador</div><div><?= $h($c['nome']) ?></div></div>
    <div class="foot"><span>Nexa Ponto · Espelho de Ponto (Portaria MTE 671/2021, art. 84)</span><span>Emitido em <?= $h($emitido) ?></span></div>
  </section>
<?php endforeach; ?>
</body>
</html>
<?php
  }
}
