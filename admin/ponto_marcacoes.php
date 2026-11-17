<?php
declare(strict_types=1);

// Ponto Eletrônico > Marcações: consulta dos registros de ponto gravados (somente leitura).
// Nenhum registro pode ser alterado ou apagado; ajustes virão pelo tratamento de ponto, que
// guarda a justificativa à parte e mantém a marcação original.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_marcacao.php';

$cid = ponto_guard($pdo, 'ponto_marcacoes');
$hoje = date('Y-m-d');
$f = [
  'de' => ponto_date_safe($_GET['de'] ?? '', $hoje),
  'ate' => ponto_date_safe($_GET['ate'] ?? '', $hoje),
  'colaborador_id' => (int)($_GET['colaborador_id'] ?? 0),
  'estabelecimento_id' => (int)($_GET['estabelecimento_id'] ?? 0),
  'geofence' => (string)($_GET['geofence'] ?? ''),
];
if ($f['ate'] < $f['de']) $f['ate'] = $f['de'];
if ((strtotime($f['ate']) - strtotime($f['de'])) > 92 * 86400) $f['ate'] = date('Y-m-d', strtotime($f['de'] . ' +92 days'));

ponto_start($pdo, 'ponto_marcacoes', 'Marcações', 'Registros de ponto gravados (com NSR e código hash). Eles não podem ser alterados nem apagados: correções serão feitas no tratamento de ponto, sem mexer no registro original.');
ponto_require_v2($pdo);

$estabs = ponto_options($pdo, $cid, 'estabelecimentos', false);
$cols = ponto_options($pdo, $cid, 'colaboradores', false);
$verif = null;
if (isset($_GET['verificar'])) {
  $verif = [];
  foreach (array_keys($estabs) as $eid) {
    $r = ponto_arp_verificar($pdo, $cid, (int)$eid);
    if ($r['total'] > 0 || !$r['ok']) $verif[(int)$eid] = $r;
  }
  ponto_audit($pdo, $cid, 'verificar_integridade', 'marcacoes', null, null, ['resultado' => array_map(static fn($r) => $r['ok'] ? 'OK' : $r['erro'], $verif)]);
}
$rows = ponto_marcacoes_list($pdo, $cid, $f, 2000);
$fora = count(array_filter($rows, static fn($r) => $r['geofence'] === 'FORA_DA_AREA'));
$meio = ['PIN' => 'Terminal (PIN)', 'RFID' => 'Terminal (cartão)', 'SESSAO' => 'Bater Ponto (login)'];
?>
<form method="get" class="pt-card pt-search">
  <label class="f">De<input type="date" name="de" value="<?= ponto_h($f['de']) ?>"></label>
  <label class="f">Até<input type="date" name="ate" value="<?= ponto_h($f['ate']) ?>"></label>
  <label class="f grow">Colaborador<select name="colaborador_id"><option value="">Todos</option><?php foreach ($cols as $k => $v): ?><option value="<?= (int)$k ?>" <?= $f['colaborador_id'] === (int)$k ? 'selected' : '' ?>><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
  <?php if (count($estabs) > 1): ?><label class="f">Estabelecimento<select name="estabelecimento_id"><option value="">Todos</option><?php foreach ($estabs as $k => $v): ?><option value="<?= (int)$k ?>" <?= $f['estabelecimento_id'] === (int)$k ? 'selected' : '' ?>><?= ponto_h($v) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label class="f">Localização<select name="geofence"><option value="">Todas</option><?php foreach (['FORA_DA_AREA', 'DENTRO_DA_AREA', 'EXTERNO_AUTORIZADO', 'LOCALIZACAO_INDISPONIVEL', 'SEM_LOCAL_CADASTRADO'] as $g): ?><option value="<?= $g ?>" <?= $f['geofence'] === $g ? 'selected' : '' ?>><?= ponto_h(ponto_geofence_label($g)[0]) ?></option><?php endforeach; ?></select></label>
  <button class="pt-btn pri" type="submit">Filtrar</button>
  <a class="pt-btn" href="ponto_marcacoes.php?<?= ponto_h(http_build_query(array_filter($f) + ['verificar' => 1])) ?>" title="Recalcula os códigos hash e confere a numeração (NSR)">Verificar integridade</a>
</form>

<?php if ($verif !== null): ?>
  <?php if (!$verif): ?><div class="pt-msg ok">Nenhum registro gravado ainda.</div><?php endif; ?>
  <?php foreach ($verif as $eid => $r): ?>
    <div class="pt-msg <?= $r['ok'] ? 'ok' : 'err' ?>"><?= ponto_h($estabs[$eid] ?? ('Estabelecimento ' . $eid)) ?>: <?= $r['ok'] ? 'íntegro — ' . (int)$r['total'] . ' registro(s), numeração e códigos hash conferem.' : 'PROBLEMA — ' . ponto_h($r['erro']) . ' Avise o suporte.' ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<section class="pt-card">
  <p class="pt-sub"><?= count($rows) ?> registro(s)<?= count($rows) >= 2000 ? ' (mostrando os 2.000 mais recentes; reduza o período)' : '' ?><?= $fora ? ' · <b style="color:#b91c1c">' . $fora . ' fora da área</b>' : '' ?></p>
  <?php if (!$rows): ?><div class="pt-empty">Nenhuma marcação no período.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Data / hora</th><th>Colaborador</th><th>Tipo</th><th>Como</th><th>Localização</th><th>NSR</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$gl, $gc] = ponto_geofence_label((string)$r['geofence']); ?>
      <tr>
        <td data-l="Data/hora"><b><?= ponto_h(date('d/m/Y', strtotime((string)$r['data_hora']))) ?> <?= ponto_h(substr((string)$r['data_hora'], 11, 5)) ?></b></td>
        <td data-l="Colaborador"><?= ponto_h($r['nome']) ?><br><span class="pt-hint">Mat. <?= ponto_h($r['matricula']) ?><?= count($estabs) > 1 ? ' · ' . ponto_h($r['estabelecimento']) : '' ?></span></td>
        <td data-l="Tipo"><?= ponto_h(PONTO_INTENCOES[(string)$r['intencao']] ?? '—') ?></td>
        <td data-l="Como"><?= ponto_h($meio[$r['identificacao']] ?? $r['identificacao']) ?><?= $r['coletor_nome'] ? '<br><span class="pt-hint">' . ponto_h($r['coletor_nome']) . '</span>' : '' ?></td>
        <td data-l="Localização"><span class="pt-badge <?= $gc ?>"><?= ponto_h($gl) ?></span>
          <?php if ($r['distancia_m'] !== null && $r['geofence'] !== 'DENTRO_DA_AREA'): ?><br><span class="pt-hint"><?= (int)$r['distancia_m'] ?> m de <?= ponto_h($r['local_nome'] ?: 'local') ?></span><?php endif; ?>
          <?php if ($r['latitude'] !== null): ?><br><a class="pt-hint" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= ponto_h($r['latitude']) ?>&mlon=<?= ponto_h($r['longitude']) ?>#map=18/<?= ponto_h($r['latitude']) ?>/<?= ponto_h($r['longitude']) ?>">ver no mapa<?= $r['precisao_m'] !== null ? ' (±' . (int)$r['precisao_m'] . ' m)' : '' ?></a><?php endif; ?>
        </td>
        <td data-l="NSR"><?= (int)$r['nsr'] ?></td>
        <td class="act"><a class="pt-btn sm" target="_blank" rel="noopener" href="<?= ponto_h(ponto_comprovante_url($pdo, $cid, $r)) ?>">Comprovante</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php ponto_end(); ?>
