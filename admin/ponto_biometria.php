<?php
declare(strict_types=1);

// Ponto Eletrônico > Biometria facial (V10): cadastro do rosto de cada colaborador (com o termo de
// consentimento, LGPD art. 11), remoção a qualquer momento e situação do envio aos aparelhos
// Control iD. O rosto vira um "modelo" numérico calculado no navegador; a foto fica no cadastro.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_equipamentos.php';

$cid = ponto_guard($pdo, 'ponto_biometria');
$uid = (int)(auth_user_id() ?? 0);
$back = 'ponto_biometria.php';

// Termo de consentimento para imprimir e colher a assinatura.
if (isset($_GET['termo'])) {
  $col = ponto_colaborador_get($pdo, $cid, (int)$_GET['termo']);
  if (!$col) { http_response_code(404); exit('Colaborador não encontrado.'); }
  $estab = !empty($col['estabelecimento_id']) ? ponto_estabelecimento_get($pdo, $cid, (int)$col['estabelecimento_id']) : null;
  $emp = $estab ? (($estab['razao_social'] ?? '') . ' · ' . ponto_fmt_doc((string)$estab['documento'])) : (string)(($company ?? [])['name'] ?? '');
  $h = 'ponto_h';
  ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Termo de consentimento - <?= $h($col['nome']) ?></title>
  <style>body{font-family:Arial,Helvetica,sans-serif;max-width:760px;margin:30px auto;padding:0 20px;color:#111;line-height:1.55;font-size:14px}h1{font-size:18px;text-align:center}ol li{margin-bottom:6px}.ass{margin-top:60px;display:grid;grid-template-columns:1fr 1fr;gap:40px}.ass div{border-top:1px solid #000;padding-top:6px;text-align:center;font-size:12px}@media print{.np{display:none}}</style></head><body>
  <p class="np"><button onclick="print()">Imprimir</button></p>
  <h1>TERMO DE CONSENTIMENTO PARA USO DA IMAGEM FACIAL<br>(dado biométrico — Lei 13.709/2018, art. 11, I)</h1>
  <p><b>Empregador:</b> <?= $h($emp) ?><br><b>Colaborador:</b> <?= $h($col['nome']) ?> · CPF <?= $h(ponto_fmt_doc((string)$col['cpf'])) ?> · Matrícula <?= $h($col['matricula']) ?></p>
  <p>Eu, colaborador acima identificado, autorizo de forma livre, informada e inequívoca o empregador a tratar a imagem do meu rosto e o modelo numérico (biométrico) gerado a partir dela, nas condições abaixo:</p>
  <ol>
    <li><b>Finalidade:</b> exclusivamente confirmar a minha identidade no registro do ponto e, quando houver, liberar a minha entrada nas portas/catracas da empresa.</li>
    <li><b>O que é guardado:</b> a foto do cadastro, o modelo numérico do rosto e a foto tirada no momento de cada registro do ponto, esta última por até <?= (int)ponto_facial_cfg($pdo, $cid)['foto_dias'] ?> dias.</li>
    <li><b>Compartilhamento:</b> os dados ficam no sistema de ponto contratado pelo empregador e, quando houver, nos aparelhos de ponto/acesso da própria empresa. Não são vendidos nem usados para outra finalidade.</li>
    <li><b>Alternativa:</b> se eu não concordar, poderei registrar o ponto por outro meio (matrícula e PIN, cartão ou login), sem qualquer prejuízo.</li>
    <li><b>Revogação:</b> posso retirar este consentimento a qualquer momento, por escrito ao empregador; o modelo do meu rosto será apagado e retirado dos aparelhos. Ao fim do contrato de trabalho ele também é apagado.</li>
    <li><b>Direitos:</b> posso pedir acesso, correção ou eliminação dos meus dados (LGPD, art. 18) ao responsável indicado pelo empregador.</li>
  </ol>
  <p>Local e data: ______________________, ____/____/________</p>
  <div class="ass"><div><?= $h($col['nome']) ?><br>Colaborador</div><div>Empregador</div></div>
  <p style="margin-top:30px;font-size:11px;color:#555">Termo versão <?= $h(PONTO_FACIAL_TERMO_VERSAO) ?> · gerado pelo Nexa Ponto em <?= $h(date('d/m/Y H:i')) ?></p>
  </body></html><?php
  exit;
}

ponto_post_handle($back, function () use ($pdo, $cid, $uid) {
  if (!ponto_v10_ready($pdo)) throw new RuntimeException('A biometria facial ainda não foi instalada no banco.');
  $a = (string)($_POST['action'] ?? '');
  $colId = (int)($_POST['colaborador_id'] ?? 0);
  if ($a === 'cadastrar') {
    $foto = ponto_facial_jpeg(isset($_POST['foto']) ? (string)$_POST['foto'] : null);
    ponto_biometria_cadastrar($pdo, $cid, $colId, (string)($_POST['descritor'] ?? ''), !empty($_POST['consentimento']), $uid, (string)($_POST['origem'] ?? ''), $foto);
    return 'Rosto cadastrado.';
  }
  if ($a === 'remover') {
    ponto_biometria_revogar($pdo, $cid, $colId, $uid, trim((string)($_POST['motivo'] ?? '')) ?: 'Removido pelo gestor');
    return 'Rosto removido. O modelo foi apagado e sai dos aparelhos na próxima conexão deles.';
  }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_biometria', 'Biometria facial', 'Cadastro do rosto de cada colaborador para conferir a identidade ao bater o ponto (tablet/celular) e nos aparelhos com câmera. Só com o termo de consentimento assinado; quem não consentir continua batendo ponto por outro meio.');
if (!ponto_v10_ready($pdo)) {
  echo '<div class="pt-msg warn">A biometria facial ainda não foi instalada no banco. O administrador da plataforma precisa aplicar a atualização "Ponto Eletrônico - V10 Biometria facial" no Atualizador do Banco.</div>';
  ponto_end();
  exit;
}
$fc = ponto_facial_cfg($pdo, $cid);
$q = trim((string)($_GET['q'] ?? ''));
$cols = ponto_colaborador_list($pdo, $cid, ['status' => 'ATIVOS', 'q' => $q]);
$bios = [];
$st = $pdo->prepare('SELECT * FROM ponto_biometria WHERE company_id = ?');
$st->execute([$cid]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $bios[(int)$b['colaborador_id']] = $b;
$sync = ponto_equip_comandos_por_colaborador($pdo, $cid);
$st = $pdo->prepare("SELECT COUNT(*) FROM ponto_coletores WHERE company_id = ? AND ativo = 1 AND tipo = 'CONTROLID' AND enviar_rostos = 1");
$st->execute([$cid]);
$temAparelho = (bool)$st->fetchColumn();
$cad = count(array_filter($bios, 'ponto_biometria_ativa'));
?>
<section class="pt-card" style="margin-bottom:12px">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
    <div><b>Reconhecimento no Bater Ponto e no terminal:</b> <span class="pt-badge <?= $fc['modo'] === 'DESLIGADO' ? 'n' : 'g' ?>"><?= ponto_h(PONTO_FACIAL_MODOS[$fc['modo']]) ?></span>
      <span class="pt-hint">· <?= (int)$cad ?> rosto(s) cadastrado(s)<?= $temAparelho ? ' · enviados aos aparelhos Control iD marcados' : '' ?></span></div>
    <?php if (ponto_can($pdo, 'ponto_configuracoes')): ?><a class="pt-btn sm" href="ponto_configuracoes.php">Alterar regra</a><?php endif; ?>
  </div>
</section>

<section class="pt-card">
  <form method="get" class="pt-search" style="margin-bottom:10px"><label class="f grow">Buscar<input type="search" name="q" value="<?= ponto_h($q) ?>" placeholder="Nome, matrícula ou CPF"></label><button class="pt-btn" type="submit">Buscar</button></form>
  <?php if (!$cols): ?><div class="pt-empty">Nenhum colaborador.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Colaborador</th><th>Rosto</th><th>Aparelhos</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cols as $c): $b = $bios[(int)$c['id']] ?? null; $ativo = ponto_biometria_ativa($b); $temFoto = !empty($c['foto_hash']); ?>
      <tr>
        <td data-l="Colaborador"><div style="display:flex;gap:10px;align-items:center">
          <?php if ($temFoto): ?><img src="ponto_colaborador_foto.php?id=<?= (int)$c['id'] ?>&v=<?= ponto_h(substr((string)$c['foto_hash'], 0, 10)) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover" loading="lazy"><?php else: ?><span style="width:40px;height:40px;border-radius:50%;background:#e2e8f0;display:grid;place-items:center;font-weight:800"><?= ponto_h(mb_strtoupper(mb_substr((string)$c['nome'], 0, 1))) ?></span><?php endif; ?>
          <div><b><?= ponto_h($c['nome']) ?></b><br><span class="pt-hint">Matr. <?= ponto_h($c['matricula']) ?> · código no aparelho <?= (int)$c['id'] ?></span></div></div></td>
        <td data-l="Rosto"><?php if ($ativo): ?><span class="pt-badge g">Cadastrado</span><br><span class="pt-hint"><?= ponto_h(date('d/m/Y', strtotime((string)$b['descritor_em']))) ?> · <?= $b['descritor_origem'] === 'CAMERA' ? 'foto tirada na hora' : 'foto do cadastro' ?></span>
          <?php elseif ($b && $b['revogado_em']): ?><span class="pt-badge n">Removido</span><br><span class="pt-hint"><?= ponto_h(date('d/m/Y', strtotime((string)$b['revogado_em']))) ?></span>
          <?php else: ?><span class="pt-badge y">Sem cadastro</span><?php endif; ?></td>
        <td data-l="Aparelhos"><?php if (empty($sync[(int)$c['id']])): ?><span class="pt-hint">—</span><?php else: foreach ($sync[(int)$c['id']] as $s): ?>
          <div class="pt-hint"><?= ponto_h($s['descricao']) ?>: <b style="color:<?= $s['status'] === 'OK' ? '#047857' : ($s['status'] === 'ERRO' ? '#b91c1c' : '#92400e') ?>"><?= ponto_h(['OK' => $s['tipo'] === 'REMOVER' ? 'removido' : 'enviado', 'ERRO' => 'erro', 'PENDENTE' => 'aguardando o aparelho', 'ENVIADO' => 'enviando'][$s['status']] ?? $s['status']) ?></b><?= $s['status'] === 'ERRO' && $s['mensagem'] ? ' — ' . ponto_h($s['mensagem']) : '' ?></div>
        <?php endforeach; endif; ?></td>
        <td class="act"><div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end">
          <button class="pt-btn sm <?= $ativo ? '' : 'pri' ?>" type="button" onclick='pbCad(<?= ponto_h(json_encode(['id' => (int)$c['id'], 'nome' => (string)$c['nome'], 'foto' => $temFoto ? 'ponto_colaborador_foto.php?id=' . (int)$c['id'] . '&v=' . substr((string)$c['foto_hash'], 0, 10) : null], JSON_UNESCAPED_UNICODE)) ?>)'><?= $ativo ? 'Atualizar rosto' : 'Cadastrar rosto' ?></button>
          <a class="pt-btn sm" href="ponto_biometria.php?termo=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">Termo</a>
          <?php if ($ativo): ?><form method="post" data-confirm="Remover o rosto de <?= ponto_h($c['nome']) ?>? O modelo é apagado e sai dos aparelhos."><?= ponto_csrf_field() ?><input type="hidden" name="action" value="remover"><input type="hidden" name="colaborador_id" value="<?= (int)$c['id'] ?>"><button class="pt-btn sm red" type="submit">Remover</button></form><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="pt-hint" style="margin:10px 0 0">Antes de cadastrar, imprima o <b>Termo</b> e colha a assinatura do colaborador. O rosto é apagado automaticamente quando o colaborador é desligado. Para os aparelhos Control iD com câmera, marque "Enviar os rostos" no cadastro do aparelho (Ponto &gt; Aparelhos): a pessoa vai para o aparelho com o código mostrado aqui.</p>
</section>

<dialog class="pt-modal" id="pbModal">
  <form method="post" id="pbForm">
    <?= ponto_csrf_field() ?><input type="hidden" name="action" value="cadastrar"><input type="hidden" name="colaborador_id" id="pbId">
    <input type="hidden" name="descritor" id="pbDesc"><input type="hidden" name="origem" id="pbOrig"><input type="hidden" name="foto" id="pbFoto">
    <div class="pt-mh"><h3 id="pbTit">Cadastrar rosto</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <label class="ck"><input type="checkbox" name="consentimento" value="1" id="pbCons"> O colaborador leu e assinou o <a href="#" id="pbTermo" target="_blank" rel="noopener">termo de consentimento</a> (fica guardado pela empresa).</label>
      <p class="pt-hint" style="margin:10px 0">Escolha de onde vem o rosto. Use uma foto de frente, com boa luz, sem óculos escuros e só com uma pessoa.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="pt-btn" type="button" id="pbUsarFoto">Usar a foto do cadastro</button>
        <button class="pt-btn pri" type="button" id="pbCamera">Tirar foto agora</button>
      </div>
      <div class="pt-msg" id="pbMsg" hidden style="margin-top:10px"></div>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button></div>
  </form>
</dialog>
<script src="/assets/ponto/ponto-face.js?v=1"></script>
<script>
(function(){
  NexaFace.init({ base: '/assets/vendor/face-api' });
  var cur = null, $ = function(id){ return document.getElementById(id); };
  function msg(t, err){ var m = $('pbMsg'); m.hidden = !t; m.textContent = t || ''; m.className = 'pt-msg ' + (err ? 'err' : 'ok'); }
  function consent(){ if (!$('pbCons').checked) { msg('Marque que o colaborador assinou o termo de consentimento.', true); return false; } return true; }
  function enviar(desc, origem, foto){ $('pbDesc').value = desc; $('pbOrig').value = origem; $('pbFoto').value = foto || ''; $('pbForm').submit(); }
  window.pbCad = function(c){
    cur = c; $('pbId').value = c.id; $('pbTit').textContent = 'Rosto de ' + c.nome; $('pbCons').checked = false; msg('');
    $('pbTermo').href = 'ponto_biometria.php?termo=' + c.id;
    $('pbUsarFoto').hidden = !c.foto;
    ptOpen('pbModal');
  };
  $('pbUsarFoto').addEventListener('click', function(){
    if (!consent() || !cur || !cur.foto) return;
    var b = this; b.disabled = true; msg('Conferindo o rosto na foto do cadastro…');
    var img = new Image();
    img.onload = function(){
      NexaFace.descritor(img).then(function(d){
        b.disabled = false;
        if (!d) { msg('Não foi encontrado exatamente um rosto na foto do cadastro. Tire uma foto agora.', true); return; }
        enviar(d, 'FOTO_CADASTRO', '');
      }, function(e){ b.disabled = false; msg(e.message || 'Falha no reconhecimento.', true); });
    };
    img.onerror = function(){ b.disabled = false; msg('Não foi possível abrir a foto do cadastro.', true); };
    img.src = cur.foto;
  });
  $('pbCamera').addEventListener('click', function(){
    if (!consent() || !cur) return;
    var dlg = $('pbModal'); dlg.close();
    NexaFace.capturar({ reconhecer: true, exigeRosto: true, titulo: 'Rosto de ' + cur.nome, sub: 'Centralize o rosto no contorno, de frente e com boa luz.', botao: 'Cadastrar este rosto' }).then(function(f){
      dlg.showModal();
      if (!f) return;
      enviar(f.descritor, 'CAMERA', f.foto);
    });
  });
})();
</script>
<?php ponto_end(); ?>
