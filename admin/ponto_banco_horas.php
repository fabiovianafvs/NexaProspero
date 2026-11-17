<?php
declare(strict_types=1);

// Ponto Eletrônico > Banco de horas: saldo de cada colaborador, horas que vão vencer, extrato
// e lançamentos manuais (ajuste, pagamento na folha, folga compensada). Os lançamentos do
// fechamento só saem reabrindo o mês; os manuais são estornados com motivo (nada é apagado).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_fechamento.php';

$cid = ponto_guard($pdo, 'ponto_banco_horas');
$colId = (int)($_GET['col'] ?? 0);
$back = 'ponto_banco_horas.php' . ($colId ? '?col=' . $colId : '');

ponto_post_handle($back, function () use ($pdo, $cid) {
  $a = (string)($_POST['action'] ?? '');
  if ($a === 'lancar') { ponto_bh_movimento_save($pdo, $cid, $_POST); return 'Lançamento gravado no banco de horas.'; }
  if ($a === 'estornar') { ponto_bh_estornar($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['motivo'] ?? '')); return 'Lançamento estornado (continua no extrato).'; }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_banco_horas', 'Banco de horas', 'Saldo de horas de cada colaborador: créditos das horas extras e débitos de atrasos, faltas e folgas compensadas, lançados no fechamento do mês.');
ponto_require_v4($pdo);
if (!ponto_v5_ready($pdo)) {
  echo '<div class="pt-msg warn">O banco de horas ainda não foi instalado no banco. O administrador da plataforma precisa aplicar "Ponto Eletrônico - V5 Banco de horas e fechamento" no Atualizador do Banco.</div>';
  ponto_end();
  exit;
}
$cfg = ponto_config($pdo, $cid);
$validade = (int)($cfg['bh_validade_meses'] ?? 6);
if (empty($cfg['bh_ativo'])) echo '<div class="pt-msg warn">O banco de horas está desligado: no fechamento, as horas extras vão para pagamento e os débitos para desconto. Para usar o banco, ligue em <a href="ponto_configuracoes.php">Configurações</a> (conforme o acordo com os colaboradores ou a convenção coletiva).</div>';
$saldos = ponto_bh_saldos($pdo, $cid);
$cols = [];
foreach (ponto_colaborador_list($pdo, $cid, ['status' => '']) as $c) {
  if (!(int)$c['controla_ponto']) continue;
  if ($c['status'] === 'DESLIGADO' && empty($saldos[(int)$c['id']])) continue;
  $cols[(int)$c['id']] = $c;
}
$users = ponto_company_users($pdo, $cid);
$em30 = date('Y-m-d', strtotime('+30 days'));
?>
<style>#pbSinal[hidden]{display:none!important}.pb-num{font-variant-numeric:tabular-nums;white-space:nowrap!important;text-align:right}.pb-x td{text-decoration:line-through;color:#94a3b8}</style>
<?php if ($colId && isset($cols[$colId])): $c = $cols[$colId]; $ext = ponto_bh_extrato($pdo, $cid, $colId, 2000); $saldo = $saldos[$colId] ?? 0; ?>
  <section class="pt-card" style="margin-bottom:12px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <div style="margin-right:auto"><h2 style="margin:0"><?= ponto_h($c['nome']) ?></h2><p class="pt-sub" style="margin:2px 0 0">Mat. <?= ponto_h($c['matricula']) ?><?= isset($c['banco_horas']) && !(int)$c['banco_horas'] ? ' · <b>não participa do banco de horas</b>' : '' ?></p></div>
      <div style="text-align:right"><span class="pt-hint">Saldo atual</span><br><b style="font-size:24px;color:<?= $saldo < 0 ? '#b91c1c' : '#15803d' ?>"><?= ponto_h(ponto_hm($saldo, false)) ?></b></div>
      <a class="pt-btn" href="ponto_banco_horas.php">← Todos</a>
    </div>
  </section>
  <section class="pt-card" style="margin-bottom:12px">
    <h2>Lançamento manual</h2>
    <form method="post" class="pt-grid three">
      <?= ponto_csrf_field() ?><input type="hidden" name="action" value="lancar"><input type="hidden" name="colaborador_id" value="<?= $colId ?>">
      <label class="f">Tipo<select name="tipo" id="pbTipo"><option value="PAGAMENTO">Pago na folha (tira do banco)</option><option value="COMPENSACAO">Folga compensada (tira do banco)</option><option value="AJUSTE">Ajuste (soma ou tira)</option></select></label>
      <label class="f" id="pbSinal" hidden>Ajuste<select name="sinal"><option value="+">Somar ao banco</option><option value="-">Tirar do banco</option></select></label>
      <label class="f">Data<input type="date" name="data" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></label>
      <label class="f">Horas<input type="text" name="horas" required placeholder="Ex.: 2:30" inputmode="numeric"></label>
      <label class="f s2">Descrição (fica no extrato)<input type="text" name="descricao" maxlength="300" required placeholder="Ex.: pagamento de 10h na folha de novembro"></label>
      <div class="s3" style="display:flex;justify-content:flex-end"><button class="pt-btn pri" type="submit">Lançar</button></div>
    </form>
    <p class="pt-hint" style="margin:6px 0 0">Para folga compensada num dia de trabalho, prefira lançar a ocorrência "Folga compensatória" no dia: ela abona o dia e desconta do banco no fechamento.</p>
  </section>
  <section class="pt-card">
    <h2>Extrato</h2>
    <?php if (!$ext): ?><div class="pt-empty">Nenhum lançamento. Os créditos e débitos entram quando o mês é fechado.</div><?php else: ?>
    <table class="pt-table cards">
      <thead><tr><th>Data</th><th>Lançamento</th><th class="pb-num">Horas</th><th class="pb-num">Saldo</th><th>Por</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($ext) as $m): $x = $m['status'] !== 'ATIVO'; ?>
        <tr class="<?= $x ? 'pb-x' : '' ?>">
          <td data-l="Data"><?= ponto_h(date('d/m/Y', strtotime((string)$m['data']))) ?></td>
          <td data-l="Lançamento"><b><?= ponto_h(PONTO_BH_TIPOS[$m['tipo']] ?? $m['tipo']) ?></b><br><span class="pt-hint"><?= ponto_h($m['descricao']) ?><?= $x ? ' · estornado: ' . ponto_h((string)$m['motivo_estorno']) : '' ?></span></td>
          <td data-l="Horas" class="pb-num" style="color:<?= (int)$m['minutos'] < 0 ? '#b91c1c' : '#15803d' ?>"><?= (int)$m['minutos'] > 0 ? '+' : '' ?><?= ponto_h(ponto_hm((int)$m['minutos'], false)) ?></td>
          <td data-l="Saldo" class="pb-num"><?= $x ? '' : ponto_h(ponto_hm((int)$m['saldo'], false)) ?></td>
          <td data-l="Por"><span class="pt-hint"><?= $m['origem'] === 'FECHAMENTO' ? 'Fechamento ' . ponto_h(ponto_comp_label((string)$m['competencia'])) : ponto_h((string)($users[(int)$m['created_by']] ?? '')) ?></span></td>
          <td class="act"><?php if (!$x && $m['origem'] === 'MANUAL'): ?><details><summary class="pt-btn sm" style="list-style:none">Estornar</summary><form method="post" style="display:flex;gap:6px;margin-top:6px"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="estornar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="text" name="motivo" required maxlength="300" placeholder="Motivo" style="width:150px;min-height:32px"><button class="pt-btn sm red" type="submit">OK</button></form></details><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <script>
  (function(){ const t = document.getElementById('pbTipo'), s = document.getElementById('pbSinal'); const f = () => { s.hidden = t.value !== 'AJUSTE'; }; t.addEventListener('change', f); f(); })();
  </script>
<?php else: ?>
  <section class="pt-card">
    <p class="pt-sub">Validade do banco: <b><?= $validade ?> <?= $validade === 1 ? 'mês' : 'meses' ?></b>. Horas não compensadas no prazo vencem no fechamento e vão para pagamento (as mais antigas primeiro).</p>
    <?php if (!$cols): ?><div class="pt-empty">Nenhum colaborador com controle de ponto.</div><?php else: ?>
    <table class="pt-table cards">
      <thead><tr><th>Colaborador</th><th class="pb-num">Saldo</th><th class="pb-num">Vence em até 30 dias</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cols as $id => $c): $s = $saldos[$id] ?? 0; $v = $s > 0 ? ponto_bh_a_vencer($pdo, $cid, $id, $em30, $validade) : 0; ?>
        <tr class="<?= $c['status'] === 'DESLIGADO' ? 'off' : '' ?>">
          <td data-l="Colaborador"><b><?= ponto_h($c['nome']) ?></b><?= isset($c['banco_horas']) && !(int)$c['banco_horas'] ? ' <span class="pt-badge n">não participa</span>' : '' ?><?= $c['status'] === 'DESLIGADO' ? ' <span class="pt-badge r">desligado</span>' : '' ?></td>
          <td data-l="Saldo" class="pb-num" style="font-weight:900;color:<?= $s < 0 ? '#b91c1c' : ($s > 0 ? '#15803d' : '#0b1f5b') ?>"><?= ponto_h(ponto_hm($s, false)) ?></td>
          <td data-l="Vence em 30 dias" class="pb-num"><?= $v ? '<b style="color:#a16207">' . ponto_h(ponto_hm($v, false)) . '</b>' : '—' ?></td>
          <td class="act"><a class="pt-btn sm" href="ponto_banco_horas.php?col=<?= $id ?>">Extrato</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php ponto_end(); ?>
