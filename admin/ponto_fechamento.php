<?php
declare(strict_types=1);

// Ponto Eletrônico > Fechamento do mês: confere as pendências, fecha a competência (grava os
// totais de cada colaborador e lança o banco de horas), baixa a planilha para a folha e, com
// permissão própria e motivo, reabre. Mês fechado trava tratamento, ocorrências e banco.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_fechamento.php';

$cid = ponto_guard($pdo, 'ponto_fechamento');
$canReabrir = ponto_can($pdo, 'ponto_fechamento_reabrir');
$comp = (string)($_GET['c'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $comp)) $comp = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
$estabs = ponto_options($pdo, $cid, 'estabelecimentos', false);
$estabId = (int)($_GET['e'] ?? 0);
if (!isset($estabs[$estabId])) $estabId = (int)(array_key_first($estabs) ?? 0);
$back = 'ponto_fechamento.php?' . http_build_query(['c' => $comp, 'e' => $estabId]);

if (isset($_GET['csv']) && $estabId && ponto_v5_ready($pdo)) {
  $f = ponto_fechamento_get($pdo, $cid, $estabId, $comp);
  if ($f && $f['status'] === 'FECHADO') {
    $linhas = ponto_fechamento_itens($pdo, $cid, $f);
  } else {
    $linhas = [];
    foreach (ponto_fechamento_calcular($pdo, $cid, $estabId, $comp)['itens'] as $it) $linhas[] = $it + ['nome' => $it['colaborador']['nome'], 'matricula' => $it['colaborador']['matricula'], 'cpf' => $it['colaborador']['cpf']];
  }
  ponto_audit($pdo, $cid, 'baixar_planilha_fechamento', 'fechamentos', $f ? (int)$f['id'] : null, null, ['competencia' => ponto_comp_label($comp), 'previa' => !$f || $f['status'] !== 'FECHADO']);
  ponto_fechamento_csv($linhas, 'ponto_' . $comp . ($f && $f['status'] === 'FECHADO' ? '' : '_previa') . '.csv');
}

ponto_post_handle($back, function () use ($pdo, $cid, $canReabrir, $estabId, $comp) {
  $a = (string)($_POST['action'] ?? '');
  if ($a === 'fechar') {
    ponto_fechar($pdo, $cid, $estabId, $comp);
    return 'Mês ' . ponto_comp_label($comp) . ' fechado. Totais gravados e banco de horas atualizado.';
  }
  if ($a === 'reabrir') {
    if (!$canReabrir) throw new RuntimeException('Seu usuário não pode reabrir meses fechados.');
    ponto_reabrir($pdo, $cid, $estabId, $comp, (string)($_POST['motivo'] ?? ''));
    return 'Mês ' . ponto_comp_label($comp) . ' reaberto. Os lançamentos do banco de horas dele foram estornados; feche de novo depois das correções.';
  }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_fechamento', 'Fechamento do mês', 'Confere as pendências, grava os totais do mês de cada colaborador e lança o banco de horas. Depois de fechado, o mês fica travado; reabrir exige permissão e motivo, e tudo fica registrado.');
ponto_require_v4($pdo);
if (!ponto_v5_ready($pdo)) {
  echo '<div class="pt-msg warn">O fechamento ainda não foi instalado no banco. O administrador da plataforma precisa aplicar "Ponto Eletrônico - V5 Banco de horas e fechamento" no Atualizador do Banco.</div>';
  ponto_end();
  exit;
}
if (!$estabId) { echo '<div class="pt-msg warn">Cadastre o estabelecimento em Ponto Eletrônico &gt; Estabelecimentos.</div>'; ponto_end(); exit; }

$cfg = ponto_config($pdo, $cid);
$f = ponto_fechamento_get($pdo, $cid, $estabId, $comp);
$fechado = $f && $f['status'] === 'FECHADO';
$users = ponto_company_users($pdo, $cid);
[$de, $ate] = ponto_competencia($comp);
$terminou = $ate < date('Y-m-d');
if ($fechado) {
  $linhas = ponto_fechamento_itens($pdo, $cid, $f);
  $pend = [];
} else {
  $calc = ponto_fechamento_calcular($pdo, $cid, $estabId, $comp);
  $pend = $calc['pendencias'];
  $linhas = [];
  foreach ($calc['itens'] as $it) $linhas[] = $it + ['nome' => $it['colaborador']['nome'], 'matricula' => $it['colaborador']['matricula'], 'colaborador_id' => (int)$it['colaborador']['id'], 'alertas_json' => json_encode($it['alertas'])];
}
$hm = static fn($m) => ponto_hm((int)$m);
$bhAtivo = !empty($cfg['bh_ativo']);
$hist = ponto_fechamentos_list($pdo, $cid, 24);
?>
<style>
.pf-st{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.pf-st .big{font-size:20px;font-weight:900;color:#0b1f5b}
.pf-num{font-variant-numeric:tabular-nums;white-space:nowrap!important;text-align:right}
.pf-bh{font-size:12px;color:#475569;white-space:nowrap}
</style>
<form method="get" class="pt-card pt-search">
  <?php if (count($estabs) > 1): ?><label class="f grow">Estabelecimento<select name="e" onchange="this.form.submit()"><?php foreach ($estabs as $k => $v): ?><option value="<?= (int)$k ?>" <?= (int)$k === $estabId ? 'selected' : '' ?>><?= ponto_h($v) ?></option><?php endforeach; ?></select></label><?php else: ?><input type="hidden" name="e" value="<?= $estabId ?>"><?php endif; ?>
  <label class="f">Mês<input type="month" name="c" value="<?= ponto_h($comp) ?>" onchange="this.form.submit()"></label>
  <a class="pt-btn" href="ponto_fechamento.php?<?= ponto_h(http_build_query(['c' => $comp, 'e' => $estabId, 'csv' => 1])) ?>" data-no-processing download><?= $fechado ? 'Baixar planilha (Excel)' : 'Baixar prévia (Excel)' ?></a>
  <?php if (ponto_can($pdo, 'ponto_espelho')): ?><a class="pt-btn" href="ponto_espelho.php?<?= ponto_h(http_build_query(['c' => $comp, 'e' => $estabId])) ?>" target="_blank" rel="noopener">Espelhos de ponto</a><?php endif; ?>
</form>

<section class="pt-card" style="margin-bottom:12px">
  <div class="pf-st">
    <div style="margin-right:auto">
      <div class="big"><?= ponto_h(ponto_competencia($comp)[2]) ?> · <?php if ($fechado): ?><span class="pt-badge g">Fechado</span><?php elseif ($f): ?><span class="pt-badge y">Reaberto</span><?php else: ?><span class="pt-badge n">Aberto</span><?php endif; ?></div>
      <?php if ($fechado): ?><p class="pt-sub" style="margin:4px 0 0">Fechado em <?= ponto_h(date('d/m/Y H:i', strtotime((string)$f['fechado_em']))) ?> por <?= ponto_h((string)($users[(int)$f['fechado_por']] ?? '—')) ?><?= (int)$f['versao'] > 1 ? ' · ' . (int)$f['versao'] . 'º fechamento' : '' ?>.</p>
      <?php elseif ($f): ?><p class="pt-sub" style="margin:4px 0 0">Reaberto em <?= ponto_h(date('d/m/Y H:i', strtotime((string)$f['reaberto_em']))) ?> por <?= ponto_h((string)($users[(int)$f['reaberto_por']] ?? '—')) ?>: "<?= ponto_h($f['motivo_reabertura']) ?>"</p>
      <?php else: ?><p class="pt-sub" style="margin:4px 0 0">Prévia calculada agora. <?= $bhAtivo ? 'Banco de horas ligado (validade ' . (int)$cfg['bh_validade_meses'] . ' meses).' : 'Banco de horas desligado: extras vão para pagamento e débitos para desconto.' ?></p><?php endif; ?>
    </div>
    <?php if ($fechado && $canReabrir): ?>
      <details><summary class="pt-btn red" style="list-style:none">Reabrir mês</summary>
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;max-width:520px"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="reabrir">
          <input type="text" name="motivo" maxlength="300" required placeholder="Motivo da reabertura (fica registrado)" style="flex:1 1 260px"><button class="pt-btn red" type="submit">Confirmar reabertura</button></form>
        <p class="pt-hint" style="margin:6px 0 0">Os lançamentos deste mês no banco de horas serão estornados (continuam no extrato, riscados).</p>
      </details>
    <?php elseif (!$fechado): ?>
      <form method="post" data-confirm="Fechar <?= ponto_h(ponto_comp_label($comp)) ?>? Os totais serão gravados, o banco de horas atualizado e o mês ficará travado para alterações."><?= ponto_csrf_field() ?><input type="hidden" name="action" value="fechar">
        <button class="pt-btn pri" type="submit" <?= ($pend || !$terminou || !$linhas) ? 'disabled' : '' ?>>Fechar o mês</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$fechado && !$terminou): ?><div class="pt-msg warn" style="margin:10px 0 0">O mês ainda não terminou: o fechamento fica liberado a partir de <?= ponto_h(date('d/m/Y', strtotime($ate . ' +1 day'))) ?>.</div><?php endif; ?>
  <?php if ($pend): ?>
    <div class="pt-msg err" style="margin:10px 0 0"><b><?= count($pend) ?> pendência(s) impedem o fechamento:</b>
      <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach (array_slice($pend, 0, 30) as $p): ?><li><?= ponto_h($p['nome']) ?> · <?= ponto_h(date('d/m', strtotime((string)$p['data']))) ?> · <?= ponto_h($p['motivo']) ?> — <a href="<?= $p['motivo'] === 'Marcação incompleta' ? 'ponto_tratamento.php?' . ponto_h(http_build_query(['c' => $comp, 'col' => $p['colaborador_id'], 'dia' => $p['data']])) : 'ponto_ocorrencias.php' ?>">resolver</a></li><?php endforeach; ?>
      </ul></div>
  <?php endif; ?>
</section>

<section class="pt-card">
  <?php if (!$linhas): ?><div class="pt-empty">Nenhum colaborador com controle de ponto neste estabelecimento no mês.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Colaborador</th><th class="pf-num">Trabalhado</th><th class="pf-num">Extras 50%</th><th class="pf-num">Extras 100%</th><th class="pf-num">Débito</th><th class="pf-num">Faltas</th><th class="pf-num">Noturno</th><th>Banco de horas</th><th class="pf-num">Pagar</th><th class="pf-num">Descontar</th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): $al = json_decode((string)($l['alertas_json'] ?? '[]'), true) ?: []; ?>
      <tr>
        <td data-l="Colaborador"><b><?= ponto_h($l['nome']) ?></b><br><span class="pt-hint">Mat. <?= ponto_h($l['matricula']) ?></span>
          <?php foreach (array_slice($al, 0, 3) as $a): ?><span class="pt-hint" style="display:block;color:#b45309"><?= ponto_h($a) ?></span><?php endforeach; ?></td>
        <td data-l="Trabalhado" class="pf-num"><?= ponto_h($hm($l['minutos_trabalhados'])) ?></td>
        <td data-l="Extras 50%" class="pf-num"><?= ponto_h($hm($l['minutos_extra_50'])) ?></td>
        <td data-l="Extras 100%" class="pf-num"><?= ponto_h($hm($l['minutos_extra_100'])) ?></td>
        <td data-l="Débito" class="pf-num"><?= ponto_h($hm($l['minutos_falta'])) ?></td>
        <td data-l="Faltas (dias)" class="pf-num"><?= (int)$l['dias_falta'] ?: '—' ?></td>
        <td data-l="Noturno" class="pf-num"><?= ponto_h($hm($l['minutos_noturnos'])) ?><?= (int)$l['minutos_noturnos'] ? '<br><span class="pt-hint">reduzida ' . ponto_h($hm($l['minutos_noturnos_reduzidos'])) . '</span>' : '' ?></td>
        <td data-l="Banco de horas"><?php if ((int)$l['banco_horas']): ?><span class="pf-bh"><?= ponto_h(ponto_hm((int)$l['bh_saldo_anterior'], false)) ?> <span style="color:#15803d">+<?= ponto_h(ponto_hm((int)$l['bh_creditos'], false)) ?></span> <span style="color:#b91c1c">−<?= ponto_h(ponto_hm((int)$l['bh_debitos'], false)) ?></span><?= (int)$l['bh_vencidos'] ? ' <span style="color:#a16207">−' . ponto_h(ponto_hm((int)$l['bh_vencidos'], false)) . ' venc.</span>' : '' ?></span><br><b>= <?= ponto_h(ponto_hm((int)$l['bh_saldo_final'], false)) ?></b><?php else: ?><span class="pt-hint">não participa</span><?php endif; ?></td>
        <td data-l="Pagar" class="pf-num"><?= (int)$l['minutos_pagar_50'] ? ponto_h($hm($l['minutos_pagar_50'])) . ' <span class="pt-hint">' . (int)$cfg['extra_percentual'] . '%</span>' : '' ?><?= (int)$l['minutos_pagar_100'] ? '<br>' . ponto_h($hm($l['minutos_pagar_100'])) . ' <span class="pt-hint">' . (int)$cfg['extra_folga_percentual'] . '%</span>' : '' ?><?= !(int)$l['minutos_pagar_50'] && !(int)$l['minutos_pagar_100'] ? '—' : '' ?></td>
        <td data-l="Descontar" class="pf-num"><?= ponto_h($hm($l['minutos_descontar'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="pt-hint" style="margin:10px 0 0">Extras 100% = trabalho em feriado<?= !empty($cfg['extra_folga_100']) ? ' e no dia de folga (descanso semanal)' : '' ?>. Noturno: horas de relógio entre 22h e 5h e a hora noturna reduzida (52min30s = 1h). "Pagar" com banco de horas = horas que venceram sem compensação. Confira os percentuais com o acordo ou a convenção coletiva da empresa.</p>
  <?php endif; ?>
</section>

<?php if ($hist): ?>
<section class="pt-card" style="margin-top:12px">
  <h2>Histórico de fechamentos</h2>
  <table class="pt-table cards">
    <thead><tr><th>Mês</th><th>Estabelecimento</th><th>Situação</th><th>Fechado</th><th>Reaberto</th></tr></thead>
    <tbody>
    <?php foreach ($hist as $h): ?>
      <tr>
        <td data-l="Mês"><a href="ponto_fechamento.php?<?= ponto_h(http_build_query(['c' => $h['competencia'], 'e' => (int)$h['estabelecimento_id']])) ?>"><b><?= ponto_h(ponto_comp_label((string)$h['competencia'])) ?></b></a></td>
        <td data-l="Estabelecimento"><?= ponto_h($h['estabelecimento']) ?></td>
        <td data-l="Situação"><span class="pt-badge <?= $h['status'] === 'FECHADO' ? 'g' : 'y' ?>"><?= $h['status'] === 'FECHADO' ? 'Fechado' : 'Reaberto' ?></span><?= (int)$h['versao'] > 1 ? ' <span class="pt-hint">' . (int)$h['versao'] . 'º</span>' : '' ?></td>
        <td data-l="Fechado"><span class="pt-hint"><?= $h['fechado_em'] ? ponto_h(date('d/m/Y H:i', strtotime((string)$h['fechado_em'])) . ' · ' . (string)($users[(int)$h['fechado_por']] ?? '')) : '' ?></span></td>
        <td data-l="Reaberto"><span class="pt-hint"><?= $h['reaberto_em'] ? ponto_h(date('d/m/Y H:i', strtotime((string)$h['reaberto_em'])) . ' · ' . (string)($users[(int)$h['reaberto_por']] ?? '') . ' · ' . (string)$h['motivo_reabertura']) : '' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php ponto_end(); ?>
