<?php
declare(strict_types=1);

// Ponto Eletrônico > Aparelhos (coletores): tablets/totens/computadores da empresa que funcionam
// como terminal de ponto (ponto.php). Cada aparelho é ativado por um link de uso único (30 min).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_marcacao.php';

$cid = ponto_guard($pdo, 'ponto_coletores');
$back = 'ponto_coletores.php';

ponto_post_handle($back, function () use ($pdo, $cid) {
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  if ($action === 'save') {
    $newId = ponto_coletor_save($pdo, $cid, $_POST);
    if ($id === 0) {
      $_SESSION['ponto_ativacao'] = [$newId, ponto_coletor_pareamento($pdo, $cid, $newId)];
      return ['Aparelho cadastrado. Abra o link de ativação no aparelho.', 'ponto_coletores.php'];
    }
    return 'Aparelho alterado.';
  }
  if ($action === 'ativacao') {
    $_SESSION['ponto_ativacao'] = [$id, ponto_coletor_pareamento($pdo, $cid, $id)];
    return 'Link de ativação gerado (vale por 30 minutos e uma única vez).';
  }
  if ($action === 'status') {
    $on = (string)($_POST['on'] ?? '') === '1';
    ponto_coletor_status($pdo, $cid, $id, $on);
    return $on ? 'Aparelho reativado. Gere um link de ativação para conectá-lo de novo.' : 'Aparelho desativado e desconectado.';
  }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_coletores', 'Aparelhos de ponto', 'Tablets, totens ou computadores da empresa que funcionam como terminal: o colaborador digita a matrícula e o PIN (ou passa o cartão). Para bater o próprio ponto pelo celular, o colaborador usa "Bater Ponto" com o login dele.', '<button class="pt-btn pri" type="button" onclick="pcNew()">+ Cadastrar aparelho</button>');
ponto_require_v2($pdo);

$ativ = $_SESSION['ponto_ativacao'] ?? null;
unset($_SESSION['ponto_ativacao']);
$rows = ponto_coletor_list($pdo, $cid);
$estabs = ponto_options($pdo, $cid, 'estabelecimentos');
$locais = ponto_options($pdo, $cid, 'locais');
$cfg = ponto_config($pdo, $cid);
$tipos = ['TABLET' => 'Tablet', 'TOTEM' => 'Totem', 'COMPUTADOR' => 'Computador', 'SMARTPHONE' => 'Celular da empresa'];
$link = null;
if (is_array($ativ) && count($ativ) === 2) {
  foreach ($rows as $r) if ((int)$r['id'] === (int)$ativ[0]) { $link = ['c' => $r, 'url' => ponto_base_url() . '/ponto.php?ativar=' . rawurlencode((string)$ativ[1])]; }
}
?>
<?php if (empty($cfg['permitir_rfid'])): ?>
  <div class="pt-msg warn" style="font-weight:600">O cartão (RFID) está desligado em Ponto Eletrônico &gt; Configurações. Os aparelhos só aceitarão matrícula + PIN.</div>
<?php endif; ?>
<section class="pt-card">
  <?php if (!$rows): ?>
    <div class="pt-empty">Nenhum aparelho cadastrado. Cadastre o tablet ou computador que ficará na entrada da empresa.</div>
  <?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Aparelho</th><th>Estabelecimento / local</th><th>Identificação</th><th>Situação</th><th>Último acesso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $con = !empty($r['token_hash']); ?>
      <tr class="<?= (int)$r['ativo'] ? '' : 'off' ?>">
        <td data-l="Aparelho"><b><?= ponto_h($r['descricao']) ?></b><br><span class="pt-hint"><?= ponto_h($tipos[$r['tipo']] ?? $r['tipo']) ?> · AFD <?= ponto_h($r['identificador_afd']) ?></span></td>
        <td data-l="Estabelecimento"><?= ponto_h($r['estabelecimento'] ?: '—') ?><?= $r['local'] ? '<br><span class="pt-hint">' . ponto_h($r['local']) . '</span>' : '' ?></td>
        <td data-l="Identificação"><?= ponto_h(implode(' + ', array_filter([(int)$r['permitir_pin'] ? 'Matrícula/CPF + PIN' : '', (int)$r['permitir_rfid'] ? 'Cartão' : '']))) ?></td>
        <td data-l="Situação"><?php if (!(int)$r['ativo']): ?><span class="pt-badge n">Desativado</span><?php elseif ($con): ?><span class="pt-badge g">Conectado</span><?php else: ?><span class="pt-badge y">Aguardando ativação</span><?php endif; ?></td>
        <td data-l="Último acesso"><?= $r['ultimo_acesso'] ? ponto_h(date('d/m/Y H:i', strtotime((string)$r['ultimo_acesso']))) : '—' ?></td>
        <td class="act">
          <?php if ((int)$r['ativo']): ?>
            <button class="pt-btn sm" type="button" onclick='pcEdit(<?= ponto_h(json_encode(array_intersect_key($r, array_flip(['id', 'descricao', 'tipo', 'identificador_afd', 'estabelecimento_id', 'local_id', 'permitir_pin', 'permitir_rfid'])), JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
            <form method="post" style="display:inline"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="ativacao"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="pt-btn sm" type="submit"><?= $con ? 'Reconectar' : 'Link de ativação' ?></button></form>
            <form method="post" style="display:inline" data-confirm="Desativar este aparelho? Ele deixa de registrar ponto imediatamente."><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="0"><button class="pt-btn sm red" type="submit">Desativar</button></form>
          <?php else: ?>
            <form method="post" style="display:inline"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="1"><button class="pt-btn sm" type="submit">Reativar</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="pt-hint" style="margin:12px 0 0">Como funciona: cadastre o aparelho → abra o link de ativação <b>no próprio aparelho</b> (ou leia o QR Code com a câmera dele) → a tela do terminal abre e fica conectada. Para colocar na tela inicial, use "Adicionar à tela inicial" no navegador. Desativar desconecta o aparelho na hora.</p>
</section>

<dialog class="pt-modal" id="pcModal">
  <form method="post">
    <?= ponto_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="pcId">
    <div class="pt-mh"><h3 id="pcTitle">Aparelho</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <div class="pt-grid">
        <label class="f s2">Nome do aparelho<input type="text" name="descricao" id="pcDesc" maxlength="80" required placeholder="Ex.: Tablet da recepção"></label>
        <label class="f">Tipo<select name="tipo" id="pcTipo"><?php foreach ($tipos as $k => $v): ?><option value="<?= $k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="f">Identificador no AFD<select name="identificador_afd" id="pcAfd"><?php foreach (PONTO_COLETOR_CODIGOS as $k => $v): ?><option value="<?= $k ?>"><?= $k ?> - <?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="f">Estabelecimento<select name="estabelecimento_id" id="pcEst"><option value="">—</option><?php foreach ($estabs as $k => $v): ?><option value="<?= (int)$k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="f">Local de trabalho<select name="local_id" id="pcLoc"><option value="">— (usa o local de cada colaborador)</option><?php foreach ($locais as $k => $v): ?><option value="<?= (int)$k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="ck s2"><input type="checkbox" name="permitir_pin" id="pcPin" value="1"> Matrícula (ou CPF) + PIN</label>
        <label class="ck s2"><input type="checkbox" name="permitir_rfid" id="pcRfid" value="1"> Cartão RFID (leitor USB que funciona como teclado)</label>
      </div>
      <p class="pt-hint" style="margin-top:10px">O terminal pelo navegador usa o identificador <b>02 (navegador)</b>. O estabelecimento e o local servem para o relógio da tela e para conferir a localização; cada registro vai para a numeração (NSR) do estabelecimento do colaborador.</p>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Salvar</button></div>
  </form>
</dialog>

<?php if ($link): ?>
<dialog class="pt-modal" id="pcLink">
  <div class="pt-mh"><h3>Ativar "<?= ponto_h($link['c']['descricao']) ?>"</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
  <div class="pt-mb" style="text-align:center">
    <p class="pt-sub">Abra este link <b>no aparelho</b> que vai ser o terminal (ou leia o QR Code com a câmera dele). Vale por <b>30 minutos</b> e só uma vez.</p>
    <div style="display:inline-block;padding:8px;border:1px solid #e2e8f0;border-radius:14px;background:#fff"><?= ponto_qr_svg($link['url'], 4) ?></div>
    <div style="display:flex;gap:8px;margin-top:12px"><input type="text" readonly value="<?= ponto_h($link['url']) ?>" id="pcUrl" onclick="this.select()"><button class="pt-btn" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('pcUrl').value);this.textContent='Copiado'">Copiar</button></div>
    <p class="pt-hint" style="margin-top:10px">Não envie este link para colaboradores: quem abrir o link vira o terminal da empresa.</p>
  </div>
  <div class="pt-mf"><button class="pt-btn pri" type="button" onclick="ptClose(this)">Fechar</button></div>
</dialog>
<?php endif; ?>

<script>
function pcFill(r){
  document.getElementById('pcId').value = r.id || '';
  document.getElementById('pcDesc').value = r.descricao || '';
  document.getElementById('pcTipo').value = r.tipo || 'TABLET';
  document.getElementById('pcAfd').value = r.identificador_afd || '02';
  document.getElementById('pcEst').value = r.estabelecimento_id || '';
  document.getElementById('pcLoc').value = r.local_id || '';
  document.getElementById('pcPin').checked = String(r.permitir_pin) === '1';
  document.getElementById('pcRfid').checked = String(r.permitir_rfid) === '1';
  document.getElementById('pcTitle').textContent = r.id ? 'Editar aparelho' : 'Novo aparelho';
  ptOpen('pcModal');
}
function pcNew(){ pcFill({permitir_pin: 1, estabelecimento_id: <?= json_encode(count($estabs) === 1 ? (int)array_key_first($estabs) : '') ?>}); }
function pcEdit(r){ pcFill(r); }
<?php if ($link): ?>document.addEventListener('DOMContentLoaded', function(){ ptOpen('pcLink'); });<?php endif; ?>
</script>
<?php ponto_end(); ?>
