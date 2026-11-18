<?php
declare(strict_types=1);

// Ponto Eletrônico > Aparelhos (coletores): tablets/totens/computadores da empresa que funcionam
// como terminal de ponto (ponto.php). Cada aparelho é ativado por um link de uso único (30 min).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_marcacao.php';
require_once __DIR__ . '/../config/ponto_equipamentos.php';

$cid = ponto_guard($pdo, 'ponto_coletores');
$v9 = ponto_v9_ready($pdo);
$back = 'ponto_coletores.php';

ponto_post_handle($back, function () use ($pdo, $cid, $v9) {
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  $isEquip = $v9 && isset(PONTO_EQUIP_TIPOS[(string)($_POST['tipo'] ?? '')]);
  if ($action === 'save') {
    if ($isEquip && empty($_POST['funcao_ponto']) && empty($_POST['funcao_acesso'])) throw new RuntimeException('Marque o que o equipamento faz: registrar o ponto, liberar a porta/catraca ou os dois.');
    $newId = ponto_coletor_save($pdo, $cid, $_POST);
    if ($isEquip) ponto_equip_save_extra($pdo, $cid, $newId, $_POST);
    if ($id === 0 && $isEquip) {
      $_SESSION['ponto_equip_url'] = [$newId, ponto_equip_gerar_endereco($pdo, $cid, $newId)];
      return ['Equipamento cadastrado. Configure o endereço abaixo no equipamento.', 'ponto_coletores.php'];
    }
    if ($id === 0) {
      $_SESSION['ponto_ativacao'] = [$newId, ponto_coletor_pareamento($pdo, $cid, $newId)];
      return ['Aparelho cadastrado. Abra o link de ativação no aparelho.', 'ponto_coletores.php'];
    }
    return 'Aparelho alterado.';
  }
  if ($action === 'endereco' && $v9) {
    $_SESSION['ponto_equip_url'] = [$id, ponto_equip_gerar_endereco($pdo, $cid, $id)];
    return 'Endereço novo gerado. O endereço anterior parou de funcionar: configure o novo no equipamento.';
  }
  if ($action === 'enviar_rostos' && $v9) {
    $n = ponto_equip_rostos_enfileirar($pdo, $cid, $id, null, 'FOTO');
    return $n ? $n . ' rosto(s) na fila: o aparelho busca na próxima conexão dele.' : 'Nenhum rosto novo para enviar (só vão os colaboradores com rosto cadastrado e foto).';
  }
  if ($action === 'aprender' && $v9) {
    ponto_equip_aprender($pdo, $cid, $id, (int)($_POST['colaborador_id'] ?? 0));
    return 'Aguardando o cartão: passe o crachá novo no equipamento em até 2 minutos.';
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

ponto_start($pdo, 'ponto_coletores', 'Aparelhos de ponto', 'Tablets, totens ou computadores da empresa que funcionam como terminal: o colaborador digita a matrícula e o PIN (ou passa o cartão). Para bater o próprio ponto pelo celular, o colaborador usa "Bater Ponto" com o login dele.' . ($v9 ? ' Equipamentos (Control iD e outras marcas) podem registrar o ponto e/ou abrir porta e catraca.' : ''), '<button class="pt-btn pri" type="button" onclick="pcNew()">+ Cadastrar aparelho</button>');
ponto_require_v2($pdo);

$ativ = $_SESSION['ponto_ativacao'] ?? null;
unset($_SESSION['ponto_ativacao']);
$equipUrl = $_SESSION['ponto_equip_url'] ?? null;
unset($_SESSION['ponto_equip_url']);
$rows = ponto_coletor_list($pdo, $cid);
$estabs = ponto_options($pdo, $cid, 'estabelecimentos');
$locais = ponto_options($pdo, $cid, 'locais');
$cfg = ponto_config($pdo, $cid);
$tipos = ['TABLET' => 'Tablet', 'TOTEM' => 'Totem', 'COMPUTADOR' => 'Computador', 'SMARTPHONE' => 'Celular da empresa'] + ($v9 ? PONTO_EQUIP_TIPOS : []);
$equipLink = null;
if (is_array($equipUrl) && count($equipUrl) === 2) {
  foreach ($rows as $r) if ((int)$r['id'] === (int)$equipUrl[0]) $equipLink = ['c' => $r, 'url' => (string)$equipUrl[1]];
}
$temEquip = $v9 && (bool)array_filter($rows, 'ponto_equip_is');
$eventos = $temEquip ? ponto_equip_eventos_lista($pdo, $cid, 0, 60) : [];
$colsOpt = $temEquip ? ponto_options($pdo, $cid, 'colaboradores', false) : [];
$v10 = $v9 && function_exists('ponto_v10_ready') && ponto_v10_ready($pdo);
$filaRostos = $v10 ? ponto_equip_comandos_resumo($pdo, $cid) : [];
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
    <?php foreach ($rows as $r): $con = !empty($r['token_hash']); $eqp = $v9 && ponto_equip_is($r); ?>
      <tr class="<?= (int)$r['ativo'] ? '' : 'off' ?>">
        <td data-l="Aparelho"><b><?= ponto_h($r['descricao']) ?></b><br><span class="pt-hint"><?= ponto_h($tipos[$r['tipo']] ?? $r['tipo']) ?> · AFD <?= ponto_h($r['identificador_afd']) ?></span></td>
        <td data-l="Estabelecimento"><?= ponto_h($r['estabelecimento'] ?: '—') ?><?= $r['local'] ? '<br><span class="pt-hint">' . ponto_h($r['local']) . '</span>' : '' ?></td>
        <td data-l="Identificação"><?php if ($eqp): ?><?= ponto_h(implode(' + ', array_filter([(int)$r['funcao_ponto'] ? 'Registra o ponto' : '', (int)$r['funcao_acesso'] ? 'Libera ' . ((string)$r['acesso_acao'] === 'catra' ? 'a catraca' : 'a porta') : '']))) ?><br><span class="pt-hint"><?= (int)$r['funcao_acesso'] ? ponto_h(PONTO_EQUIP_SENTIDOS[$r['acesso_sentido']] ?? '') . ' · ' . ((string)$r['acesso_regra'] === 'ATIVO' ? 'qualquer hora' : 'só na jornada') : '' ?></span>
          <?php if ($v10 && !empty($r['enviar_rostos'])): $fq = $filaRostos[(int)$r['id']] ?? []; ?><br><span class="pt-hint">Rostos: <?= (int)($fq['OK'] ?? 0) ?> enviado(s)<?= ($fq['PENDENTE'] ?? 0) + ($fq['ENVIADO'] ?? 0) ? ' · ' . (($fq['PENDENTE'] ?? 0) + ($fq['ENVIADO'] ?? 0)) . ' na fila' : '' ?><?= !empty($fq['ERRO']) ? ' · <b style="color:#b91c1c">' . (int)$fq['ERRO'] . ' com erro</b>' : '' ?></span><?php endif; ?><?php else: ?><?= ponto_h(implode(' + ', array_filter([(int)$r['permitir_pin'] ? 'Matrícula/CPF + PIN' : '', (int)$r['permitir_rfid'] ? 'Cartão' : '']))) ?><?php endif; ?></td>
        <td data-l="Situação"><?php if (!(int)$r['ativo']): ?><span class="pt-badge n">Desativado</span><?php elseif ($con && $eqp): ?><span class="pt-badge g">Endereço gerado</span><?php elseif ($con): ?><span class="pt-badge g">Conectado</span><?php else: ?><span class="pt-badge y"><?= $eqp ? 'Sem endereço' : 'Aguardando ativação' ?></span><?php endif; ?></td>
        <td data-l="Último acesso"><?= $r['ultimo_acesso'] ? ponto_h(date('d/m/Y H:i', strtotime((string)$r['ultimo_acesso']))) : '—' ?></td>
        <td class="act"><div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end">
          <?php if ((int)$r['ativo']): ?>
            <button class="pt-btn sm" type="button" onclick='pcEdit(<?= ponto_h(json_encode(array_intersect_key($r, array_flip(['id', 'descricao', 'tipo', 'identificador_afd', 'estabelecimento_id', 'local_id', 'permitir_pin', 'permitir_rfid', 'funcao_ponto', 'funcao_acesso', 'acesso_regra', 'acesso_sentido', 'acesso_acao', 'equip_serial', 'dupla_seg', 'enviar_rostos'])), JSON_UNESCAPED_UNICODE)) ?>)'>Editar</button>
            <?php if ($eqp): ?>
            <form method="post" style="display:inline-flex" <?= $con ? 'data-confirm="Gerar um endereço novo? O endereço atual para de funcionar e precisa ser trocado no equipamento."' : '' ?>><?= ponto_csrf_field() ?><input type="hidden" name="action" value="endereco"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="pt-btn sm" type="submit"><?= $con ? 'Novo endereço' : 'Gerar endereço' ?></button></form>
            <?php if ($v10 && !empty($r['enviar_rostos'])): ?><form method="post" style="display:inline-flex"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="enviar_rostos"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="pt-btn sm" type="submit">Enviar rostos</button></form><?php endif; ?>
            <?php if ((int)$r['permitir_rfid']): ?><button class="pt-btn sm" type="button" onclick="pcAprender(<?= (int)$r['id'] ?>, <?= ponto_h(json_encode((string)$r['descricao'], JSON_UNESCAPED_UNICODE)) ?>)">Cadastrar cartão</button><?php endif; ?>
            <?php else: ?>
            <form method="post" style="display:inline"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="ativacao"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="pt-btn sm" type="submit"><?= $con ? 'Reconectar' : 'Link de ativação' ?></button></form>
            <?php endif; ?>
            <form method="post" style="display:inline" data-confirm="Desativar este aparelho? Ele deixa de registrar ponto imediatamente."><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="0"><button class="pt-btn sm red" type="submit">Desativar</button></form>
          <?php else: ?>
            <form method="post" style="display:inline"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="1"><button class="pt-btn sm" type="submit">Reativar</button></form>
          <?php endif; ?>
        </div></td>
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
        <label class="ck s2"><input type="checkbox" name="permitir_rfid" id="pcRfid" value="1"> <span id="pcRfidTxt">Cartão RFID (leitor USB que funciona como teclado)</span></label>
      </div>
      <?php if ($v9): ?>
      <style>#pcEquip[hidden],#pcEquip [hidden]{display:none!important}</style>
      <div id="pcEquip" hidden>
        <h4 style="margin:14px 0 6px;color:#0b1f5b">O que este equipamento faz</h4>
        <div class="pt-grid">
          <label class="ck s2"><input type="checkbox" name="funcao_ponto" id="pcFPonto" value="1"> Registrar o ponto</label>
          <label class="ck s2"><input type="checkbox" name="funcao_acesso" id="pcFAcesso" value="1" onchange="pcSync()"> Liberar porta ou catraca</label>
          <label class="f s2 pc-ac">Quem pode passar<select name="acesso_regra" id="pcRegra"><?php foreach (PONTO_EQUIP_REGRAS as $k => $v): ?><option value="<?= $k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
          <label class="f pc-ac">Sentido<select name="acesso_sentido" id="pcSent"><?php foreach (PONTO_EQUIP_SENTIDOS as $k => $v): ?><option value="<?= $k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
          <label class="f pc-ac pc-cid">Como abrir<select name="acesso_acao" id="pcAcao"><?php foreach (PONTO_EQUIP_ACOES as $k => $v): ?><option value="<?= $k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
          <label class="f pc-cid">Número de série (device_id)<input type="text" name="equip_serial" id="pcSerial" maxlength="40" inputmode="numeric" placeholder="Opcional: só aceita este aparelho"></label>
          <label class="f">Ignorar leitura repetida por (segundos)<input type="number" name="dupla_seg" id="pcDupla" min="0" max="900" value="120"></label>
          <?php if ($v10): ?><label class="ck s2 pc-cid"><input type="checkbox" name="enviar_rostos" id="pcRostos" value="1"> Enviar os rostos cadastrados no Nexa para este aparelho (iDFace)</label><?php endif; ?>
        </div>
        <p class="pt-hint" style="margin-top:8px">A regra "só dentro da jornada" usa o horário de cada colaborador com as tolerâncias e liberações de Ponto &gt; Acesso ao Nexa. Saída é sempre liberada. Leituras repetidas da mesma pessoa no tempo indicado abrem a porta, mas não geram outra marcação.</p>
      </div>
      <?php endif; ?>
      <p class="pt-hint" style="margin-top:10px" id="pcAfdHint">O terminal pelo navegador usa o identificador <b>02 (navegador)</b>; equipamentos usam <b>04 (dispositivo eletrônico)</b>. O estabelecimento e o local servem para o relógio da tela e para conferir a localização; cada registro vai para a numeração (NSR) do estabelecimento do colaborador.</p>
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

<?php if ($equipLink): $isCid = (string)$equipLink['c']['tipo'] === 'CONTROLID'; ?>
<dialog class="pt-modal" id="pcEqUrl">
  <div class="pt-mh"><h3>Endereço de "<?= ponto_h($equipLink['c']['descricao']) ?>"</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
  <div class="pt-mb">
    <p class="pt-sub"><b>Copie agora:</b> por segurança este endereço só aparece uma vez. Se perder, gere um novo.</p>
    <div style="display:flex;gap:8px"><input type="text" readonly value="<?= ponto_h($equipLink['url']) ?>" id="pcEqU" onclick="this.select()"><button class="pt-btn" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('pcEqU').value);this.textContent='Copiado'">Copiar</button></div>
    <?php if ($isCid): ?>
    <ol class="pt-hint" style="margin:12px 0 0;padding-left:18px;line-height:1.6">
      <li>No equipamento Control iD (tela de administração pelo navegador, na rede da empresa), ligue o <b>modo online</b> e cadastre o servidor com o endereço acima.</li>
      <li>Para <b>crachá</b>: identificação no servidor (modo online "Enterprise"). Cadastre os cartões aqui em "Cadastrar cartão".</li>
      <li>Para <b>rosto ou digital</b>: identificação no aparelho (modo online "Pro"). Com "Enviar os rostos" marcado, ligue também o <b>modo push</b> no aparelho com este mesmo endereço: o Nexa envia a pessoa e a foto do cadastro (só de quem tem o rosto cadastrado em Ponto &gt; Biometria facial). Sem isso, cadastre a pessoa no aparelho usando como <b>ID</b> o "Código no equipamento" do colaborador (lista abaixo).</li>
      <li>Passe um crachá: o resultado aparece em "Últimas leituras dos equipamentos".</li>
    </ol>
    <?php else: ?>
    <p class="pt-hint" style="margin-top:12px">Para o integrador: <b>POST</b> em <code>&lt;endereço&gt;/identificar</code> com <code>cartao</code>, ou <code>usuario</code> (código do colaborador), ou <code>matricula</code> + <code>pin</code> (JSON ou formulário). Resposta JSON: <code>{"ok":true,"liberar":true,"ponto_registrado":true,"hora":"08:02","mensagem":"..."}</code>. Teste de conexão: <code>&lt;endereço&gt;/status</code>.</p>
    <?php endif; ?>
  </div>
  <div class="pt-mf"><button class="pt-btn pri" type="button" onclick="ptClose(this)">Já copiei</button></div>
</dialog>
<?php endif; ?>

<?php if ($temEquip): ?>
<dialog class="pt-modal" id="pcApr">
  <form method="post">
    <?= ponto_csrf_field() ?><input type="hidden" name="action" value="aprender"><input type="hidden" name="id" id="pcAprId">
    <div class="pt-mh"><h3>Cadastrar cartão pelo equipamento</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <label class="f">Colaborador<select name="colaborador_id" required><option value="">—</option><?php foreach ($colsOpt as $k => $v): ?><option value="<?= (int)$k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
      <p class="pt-hint" style="margin-top:8px">Depois de confirmar, passe o crachá novo no equipamento <b id="pcAprNome"></b> em até 2 minutos. O primeiro cartão ainda não cadastrado lido nele fica para este colaborador (o cartão antigo dele deixa de valer).</p>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Aguardar cartão</button></div>
  </form>
</dialog>

<section class="pt-card" style="margin-top:12px">
  <h2>Últimas leituras dos equipamentos</h2>
  <?php if (!$eventos): ?><div class="pt-empty">Nenhuma leitura ainda.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Quando</th><th>Equipamento</th><th>Colaborador</th><th>Como</th><th>Resultado</th><th>Mensagem</th></tr></thead>
    <tbody><?php $rb = ['LIBERADO' => 'g', 'PONTO' => 'g', 'CARTAO_NOVO' => 'g', 'NEGADO' => 'r', 'NAO_IDENT' => 'y', 'ERRO' => 'r']; foreach ($eventos as $e): ?>
      <tr>
        <td data-l="Quando"><?= ponto_h(date('d/m H:i:s', strtotime((string)$e['data_hora']))) ?></td>
        <td data-l="Equipamento"><?= ponto_h((string)$e['equipamento']) ?></td>
        <td data-l="Colaborador"><?= $e['nome'] ? '<b>' . ponto_h($e['nome']) . '</b>' : '<span class="pt-hint">' . ($e['final_cartao'] ? 'cartão final ' . ponto_h($e['final_cartao']) : '—') . '</span>' ?></td>
        <td data-l="Como"><?= ponto_h(['RFID' => 'Cartão', 'PIN' => 'Matrícula + senha', 'FACIAL' => 'Rosto', 'BIOMETRIA' => 'Digital'][$e['identificacao']] ?? $e['identificacao']) ?></td>
        <td data-l="Resultado"><span class="pt-badge <?= $rb[$e['resultado']] ?? 'n' ?>"><?= ponto_h(PONTO_EQUIP_RESULTADOS[$e['resultado']] ?? $e['resultado']) ?></span></td>
        <td data-l="Mensagem"><span class="pt-hint"><?= ponto_h((string)$e['mensagem']) ?></span></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <details style="margin-top:10px"><summary class="pt-hint" style="cursor:pointer"><b>Código no equipamento</b> de cada colaborador (para cadastrar rosto ou digital no aparelho)</summary>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 14px;margin-top:8px"><?php foreach ($colsOpt as $k => $v): ?><div><b><?= (int)$k ?></b> · <?= ponto_h($v) ?></div><?php endforeach; ?></div>
  </details>
</section>
<?php endif; ?>

<script>
function pcSync(){
  var t = document.getElementById('pcTipo').value, eq = <?= json_encode($v9 ? array_keys(PONTO_EQUIP_TIPOS) : []) ?>.indexOf(t) >= 0, box = document.getElementById('pcEquip');
  if (!box) return;
  box.hidden = !eq;
  var ac = document.getElementById('pcFAcesso').checked;
  box.querySelectorAll('.pc-ac').forEach(function(el){ el.hidden = !ac; });
  box.querySelectorAll('.pc-cid').forEach(function(el){ if (t !== 'CONTROLID') el.hidden = true; else if (!el.classList.contains('pc-ac')) el.hidden = false; });
  document.getElementById('pcRfidTxt').textContent = eq ? 'Aceitar crachá (cartão RFID)' : 'Cartão RFID (leitor USB que funciona como teclado)';
}
function pcAprender(id, nome){ document.getElementById('pcAprId').value = id; document.getElementById('pcAprNome').textContent = '"' + nome + '"'; ptOpen('pcApr'); }
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
  if (document.getElementById('pcEquip')) {
    document.getElementById('pcFPonto').checked = r.funcao_ponto === undefined ? true : String(r.funcao_ponto) === '1';
    document.getElementById('pcFAcesso').checked = String(r.funcao_acesso) === '1';
    document.getElementById('pcRegra').value = r.acesso_regra || 'JORNADA';
    document.getElementById('pcSent').value = r.acesso_sentido || 'ENTRADA';
    document.getElementById('pcAcao').value = r.acesso_acao || 'door';
    document.getElementById('pcSerial').value = r.equip_serial || '';
    document.getElementById('pcDupla').value = r.dupla_seg === undefined ? 120 : r.dupla_seg;
    if (document.getElementById('pcRostos')) document.getElementById('pcRostos').checked = String(r.enviar_rostos) === '1';
  }
  pcSync();
  ptOpen('pcModal');
}
document.getElementById('pcTipo').addEventListener('change', function(){
  var eq = <?= json_encode($v9 ? array_keys(PONTO_EQUIP_TIPOS) : []) ?>.indexOf(this.value) >= 0;
  if (!document.getElementById('pcId').value) { document.getElementById('pcAfd').value = eq ? '04' : '02'; if (eq) { document.getElementById('pcRfid').checked = true; document.getElementById('pcPin').checked = false; } }
  pcSync();
});
function pcNew(){ pcFill({permitir_pin: 1, estabelecimento_id: <?= json_encode(count($estabs) === 1 ? (int)array_key_first($estabs) : '') ?>}); }
function pcEdit(r){ pcFill(r); }
<?php if ($link): ?>document.addEventListener('DOMContentLoaded', function(){ ptOpen('pcLink'); });<?php endif; ?>
<?php if ($equipLink): ?>document.addEventListener('DOMContentLoaded', function(){ ptOpen('pcEqUrl'); });<?php endif; ?>
</script>
<?php ponto_end(); ?>
