<?php
declare(strict_types=1);

// Transporte: criar/editar o CT-e rodoviário (rascunho ou rejeitado) e transmitir. Outras situações: só leitura.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_transporte.php';
require_once __DIR__ . '/../config/cte_emissao.php';

$cid = transp_guard($pdo, 'cte_documentos');
$uid = function_exists('auth_user_id') ? ((int)(auth_user_id() ?? 0) ?: null) : null;
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

transp_post_handle('cte_editar.php' . ($id ? '?id=' . $id : ''), function () use ($pdo, $cid, $uid, $id) {
  $action = (string)($_POST['action'] ?? '');
  if (!in_array($action, ['salvar', 'transmitir'], true)) throw new RuntimeException('Ação inválida.');
  $novoId = cte_salvar($pdo, $cid, $id, $_POST, $uid);
  $url = 'cte_editar.php?id=' . $novoId;
  if ($action === 'salvar') return ['Rascunho salvo.', $url];
  @set_time_limit(120);
  try {
    $r = cte_transmitir($pdo, $cid, $novoId, $uid);
  } catch (RuntimeException $e) {
    // Rascunho já foi salvo; mostra o que falta na mesma tela.
    transp_flash('err', $e->getMessage());
    return ['', $url];
  }
  if ($r['status'] === 'AUTORIZADO') return ['CT-e autorizado: ' . $r['mensagem'], $url];
  transp_flash($r['status'] === 'PENDENTE' ? 'warn' : 'err', ($r['status'] === 'PENDENTE' ? 'Resultado incerto: ' : 'CT-e não autorizado: ') . $r['mensagem']);
  return ['', $url];
});

if (!cte_ready($pdo)) { transp_redirect('cte.php'); }
$cfg = transp_config($pdo, $cid);
$row = $id ? cte_get($pdo, $cid, $id) : null;
if ($id && !$row) { transp_flash('err', 'CT-e não encontrado.'); transp_redirect('cte.php'); }
$d = $row ? cte_dados_normalizar($row['dados'], $cfg) : cte_dados_padrao($cfg);
$editavel = !$row || in_array($row['status'], CTE_EDITAVEIS, true);
$em = transp_emitente($pdo, $cid);
$simples = cte_regime_simples($em['crt']);
$labels = cte_status_labels();
$hist = [];
if ($row) {
  $st = $pdo->prepare('SELECT tipo, cstat, xmotivo, created_at FROM cte_transmissoes WHERE company_id = ? AND cte_id = ? ORDER BY id DESC LIMIT 20');
  $st->execute([$cid, $id]);
  $hist = $st->fetchAll(PDO::FETCH_ASSOC);
}
$ufs = TRANSP_UFS;
$h = 'transp_h';
$money = static fn($v): string => number_format((float)$v, 2, ',', '.');

$titulo = $row ? ('CT-e ' . ($row['numero'] !== null ? 'nº ' . $row['numero'] : '(rascunho)')) : 'Novo CT-e';
$acoes = '<a class="tr-btn" href="cte.php">Voltar para a lista</a>';
if ($row && in_array($row['status'], ['AUTORIZADO', 'CANCELADO', 'DENEGADO'], true)) {
  $acoes .= '<a class="tr-btn" href="cte_arquivo.php?id=' . $id . '&tipo=dacte" target="_blank" rel="noopener" data-no-processing="1">DACTE</a><a class="tr-btn" href="cte_arquivo.php?id=' . $id . '&tipo=xml" data-no-processing="1">XML</a>';
}
transp_start($pdo, 'cte_documentos', $titulo, $editavel ? 'Comece pelas notas fiscais: a chave (ou o XML) da NF-e preenche remetente, destinatário, valor e peso da carga.' : 'Este CT-e já foi transmitido e não pode mais ser alterado.', $acoes);

$part = static function (string $papel, array $p, string $titulo, string $ajuda) use ($h, $ufs, $editavel): void { ?>
  <div class="tr-pane<?= $papel === 'remetente' ? ' on' : '' ?>" id="pane-<?= $papel ?>" data-papel="<?= $papel ?>">
    <p class="tr-hint" style="margin:0 0 8px"><?= $h($ajuda) ?></p>
    <?php if ($editavel): ?>
    <div class="cte-busca"><label class="f">Buscar no cadastro de Pessoas<input type="text" class="cte-pessoa-q" placeholder="Nome, fantasia ou CPF/CNPJ" autocomplete="off"></label><div class="cte-sug" hidden></div></div>
    <?php endif; ?>
    <input type="hidden" name="part[<?= $papel ?>][pessoa_id]" value="<?= (int)($p['pessoa_id'] ?? 0) ?: '' ?>">
    <div class="tr-grid three">
      <label class="f">CPF/CNPJ<span style="display:flex;gap:6px"><input type="text" name="part[<?= $papel ?>][documento]" value="<?= $h($p['documento']) ?>" inputmode="numeric" maxlength="18"><?php if ($editavel): ?><button type="button" class="tr-btn sm cte-cnpj" title="Buscar os dados na Receita">Buscar</button><?php endif; ?></span></label>
      <label class="f">Inscrição estadual<input type="text" name="part[<?= $papel ?>][ie]" value="<?= $h($p['ie']) ?>" maxlength="14" placeholder="ou ISENTO"></label>
      <label class="f">Telefone<input type="text" name="part[<?= $papel ?>][fone]" value="<?= $h($p['fone']) ?>" inputmode="numeric" maxlength="15"></label>
      <label class="f s2">Nome / razão social<input type="text" name="part[<?= $papel ?>][nome]" value="<?= $h($p['nome']) ?>" maxlength="60"></label>
      <label class="f">Nome fantasia<input type="text" name="part[<?= $papel ?>][fantasia]" value="<?= $h($p['fantasia']) ?>" maxlength="60"></label>
      <label class="f">CEP<span style="display:flex;gap:6px"><input type="text" name="part[<?= $papel ?>][cep]" value="<?= $h($p['cep']) ?>" inputmode="numeric" maxlength="9"><?php if ($editavel): ?><button type="button" class="tr-btn sm cte-cep">Buscar</button><?php endif; ?></span></label>
      <label class="f s2">Rua / logradouro<input type="text" name="part[<?= $papel ?>][logradouro]" value="<?= $h($p['logradouro']) ?>" maxlength="255"></label>
      <label class="f">Número<input type="text" name="part[<?= $papel ?>][numero]" value="<?= $h($p['numero']) ?>" maxlength="60"></label>
      <label class="f">Complemento<input type="text" name="part[<?= $papel ?>][complemento]" value="<?= $h($p['complemento']) ?>" maxlength="60"></label>
      <label class="f">Bairro<input type="text" name="part[<?= $papel ?>][bairro]" value="<?= $h($p['bairro']) ?>" maxlength="60"></label>
      <label class="f">E-mail<input type="text" name="part[<?= $papel ?>][email]" value="<?= $h($p['email']) ?>" maxlength="60"></label>
      <label class="f cte-cidade-box">Cidade<input type="text" name="part[<?= $papel ?>][cidade]" value="<?= $h($p['cidade']) ?>" class="cte-cidade" data-ibge="part[<?= $papel ?>][cidade_ibge]" data-uf="part[<?= $papel ?>][uf]" autocomplete="off" maxlength="60"><div class="cte-sug" hidden></div></label>
      <label class="f">UF<select name="part[<?= $papel ?>][uf]"><option value="">—</option><?php foreach ($ufs as $u): ?><option <?= $p['uf'] === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></label>
      <label class="f">Código IBGE da cidade<input type="text" name="part[<?= $papel ?>][cidade_ibge]" value="<?= $h($p['cidade_ibge']) ?>" inputmode="numeric" maxlength="7"></label>
    </div>
    <?php if ($editavel && $papel !== 'remetente' && $papel !== 'destinatario'): ?><p style="margin:10px 0 0"><button type="button" class="tr-btn sm red cte-limpar">Limpar <?= $h(mb_strtolower($titulo)) ?></button></p><?php endif; ?>
  </div>
<?php };
?>
<style>
.cte-busca,.cte-cidade-box{position:relative}
.cte-sug{position:absolute;z-index:30;left:0;right:0;top:100%;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 14px 30px rgba(2,6,23,.15);max-height:260px;overflow:auto}
.cte-sug button{display:block;width:100%;text-align:left;border:0;background:none;padding:8px 11px;font:inherit;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9}
.cte-sug button:hover,.cte-sug button.on{background:#eef2ff}
.cte-sec{margin-bottom:12px}
.cte-linhas td{padding:6px 6px!important}
.cte-linhas input,.cte-linhas select{min-height:36px!important}
.cte-bar{position:sticky;bottom:0;z-index:20;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:center;padding:12px 14px;margin-top:12px;background:rgba(255,255,255,.96);border:1px solid #dbe4f0;border-radius:18px;box-shadow:0 -8px 24px rgba(11,31,91,.08)}
.cte-tot{font-size:22px;font-weight:900;color:#0b1f5b}
.cte-conf ul{margin:6px 0 0 18px;padding:0}
.cte-ro input,.cte-ro select,.cte-ro textarea{background:#f8fafc!important;color:#334155!important}
</style>

<?php if ($row): [$lb, $cor] = $labels[$row['status']] ?? [$row['status'], 'n']; ?>
<section class="tr-card cte-sec">
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
    <span class="tr-badge <?= $cor ?>" style="font-size:13px"><?= $h($lb) ?></span>
    <?php if ($row['numero'] !== null): ?><span>Número <b><?= (int)$row['numero'] ?></b> · Série <?= (int)$row['serie'] ?></span><?php endif; ?>
    <?php if ($row['chave']): ?><span class="tr-hint" style="font-family:ui-monospace,monospace">Chave <?= $h(trim(chunk_split((string)$row['chave'], 4, ' '))) ?></span><?php endif; ?>
    <?php if ($row['protocolo']): ?><span>Protocolo <b><?= $h($row['protocolo']) ?></b> em <?= $h(date('d/m/Y H:i', strtotime((string)$row['dh_autorizacao']))) ?></span><?php endif; ?>
    <span class="tr-hint"><?= $row['ambiente'] === 'HOMOLOGACAO' ? 'Ambiente de homologação (teste)' : 'Ambiente de produção' ?></span>
  </div>
  <?php if ($row['xmotivo'] && $row['status'] !== 'AUTORIZADO'): ?><div class="tr-msg <?= $row['status'] === 'PENDENTE' ? 'warn' : 'err' ?>" style="margin:10px 0 0">Último retorno: <?= $h(($row['cstat'] ? $row['cstat'] . ' - ' : '') . $row['xmotivo']) ?></div><?php endif; ?>
</section>
<?php endif; ?>

<form method="post" id="cteForm" class="<?= $editavel ? '' : 'cte-ro' ?>" autocomplete="off">
  <?= transp_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="salvar">

  <section class="tr-card cte-sec">
    <h2>1. Notas fiscais transportadas</h2>
    <p class="tr-sub">Digite ou leia a chave de acesso (44 números) da NF-e. Se a nota estiver no Nexa (emitida, entrada por XML ou Radar), os dados são preenchidos sozinhos. Também dá para enviar os arquivos XML das notas.</p>
    <?php if ($editavel): ?>
    <div class="tr-search" style="margin-bottom:8px">
      <label class="f grow">Chave da NF-e<input type="text" id="nfeChave" inputmode="numeric" maxlength="54" placeholder="Leia o código de barras do DANFE ou digite a chave"></label>
      <button type="button" class="tr-btn pri" id="nfeAdd">Adicionar</button>
      <label class="tr-btn" style="cursor:pointer">Enviar XML das notas<input type="file" id="nfeXml" accept=".xml,text/xml" multiple hidden></label>
    </div>
    <div class="tr-hint" id="nfeMsg"></div>
    <?php endif; ?>
    <table class="tr-table cte-linhas" id="nfeTab"><thead><tr><th>Chave de acesso</th><th style="width:170px">Previsão de entrega</th><th style="width:60px"></th></tr></thead><tbody></tbody></table>
    <details style="margin-top:10px" <?= $d['documentos']['outros'] ? 'open' : '' ?>>
      <summary style="cursor:pointer;font-weight:800;color:#0b1f5b">Outros documentos (declaração, NFC-e, CF-e, outros)</summary>
      <table class="tr-table cte-linhas" id="outTab" style="margin-top:8px"><thead><tr><th style="width:160px">Tipo</th><th>Descrição</th><th style="width:130px">Número</th><th style="width:150px">Data</th><th style="width:130px">Valor</th><th style="width:60px"></th></tr></thead><tbody></tbody></table>
      <?php if ($editavel): ?><button type="button" class="tr-btn sm" id="outAdd" style="margin-top:6px">+ Outro documento</button><?php endif; ?>
    </details>
  </section>

  <section class="tr-card cte-sec">
    <h2>2. Participantes</h2>
    <div class="tr-grid three" style="margin-bottom:10px">
      <label class="f s2">Quem paga o frete (tomador do serviço)<select name="tomador" id="tomador"><?php foreach (cte_tomadores() as $k => $lb): ?><option value="<?= $k ?>" <?= (string)$d['tomador'] === (string)$k ? 'selected' : '' ?>><?= $h($lb) ?></option><?php endforeach; ?></select></label>
    </div>
    <nav class="tr-mtabs">
      <?php foreach (cte_papeis() as $papel => $lb): ?><button type="button" data-pane="pane-<?= $papel ?>" class="<?= $papel === 'remetente' ? 'on' : '' ?>" data-tab-papel="<?= $papel ?>"><?= $h($lb) ?><span class="cte-tab-ok"></span></button><?php endforeach; ?>
    </nav>
    <?php
      $part('remetente', $d['participantes']['remetente'], 'Remetente', 'Quem envia a mercadoria (normalmente o emitente da NF-e).');
      $part('destinatario', $d['participantes']['destinatario'], 'Destinatário', 'Quem recebe a mercadoria (o destinatário da NF-e).');
      $part('expedidor', $d['participantes']['expedidor'], 'Expedidor', 'Opcional: quem entrega a carga à transportadora, quando não é o remetente.');
      $part('recebedor', $d['participantes']['recebedor'], 'Recebedor', 'Opcional: quem recebe a carga da transportadora, quando não é o destinatário.');
      $part('tomador', $d['participantes']['tomador'], 'Tomador', 'Só quando quem paga o frete é outra pessoa (tomador "Outro").');
    ?>
  </section>

  <section class="tr-card cte-sec">
    <h2>3. Percurso e serviço</h2>
    <div class="tr-grid three">
      <label class="f cte-cidade-box">Início da prestação (cidade)<input type="text" name="percurso[mun_ini_nome]" value="<?= $h($d['percurso']['mun_ini_nome']) ?>" class="cte-cidade" data-ibge="percurso[mun_ini_ibge]" data-uf="percurso[uf_ini]" maxlength="60"><div class="cte-sug" hidden></div></label>
      <label class="f">UF<select name="percurso[uf_ini]"><option value="">—</option><?php foreach ($ufs as $u): ?><option <?= $d['percurso']['uf_ini'] === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></label>
      <label class="f">Código IBGE<input type="text" name="percurso[mun_ini_ibge]" value="<?= $h($d['percurso']['mun_ini_ibge']) ?>" inputmode="numeric" maxlength="7"></label>
      <label class="f cte-cidade-box">Fim da prestação (cidade)<input type="text" name="percurso[mun_fim_nome]" value="<?= $h($d['percurso']['mun_fim_nome']) ?>" class="cte-cidade" data-ibge="percurso[mun_fim_ibge]" data-uf="percurso[uf_fim]" maxlength="60"><div class="cte-sug" hidden></div></label>
      <label class="f">UF<select name="percurso[uf_fim]"><option value="">—</option><?php foreach ($ufs as $u): ?><option <?= $d['percurso']['uf_fim'] === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></label>
      <label class="f">Código IBGE<input type="text" name="percurso[mun_fim_ibge]" value="<?= $h($d['percurso']['mun_fim_ibge']) ?>" inputmode="numeric" maxlength="7"></label>
      <?php if ($editavel): ?><p class="s3" style="margin:0;display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="tr-btn sm" id="percAuto">Preencher pelo remetente e destinatário</button><span class="tr-hint" style="align-self:center">Com expedidor/recebedor, o percurso começa e termina neles.</span></p><?php endif; ?>
      <label class="f">Tipo de serviço<select name="tp_serv"><?php foreach (cte_tipos_servico() as $k => $lb): ?><option value="<?= $k ?>" <?= (string)$d['tp_serv'] === (string)$k ? 'selected' : '' ?>><?= $h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f s2">CFOP<select name="cfop" id="cfop"><?php foreach (transp_cfops_cte() as $c => $lb): $c = (string)$c; ?><option value="<?= $c ?>" <?= (string)$d['cfop'] === $c ? 'selected' : '' ?>><?= $h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f s2">Natureza da operação<input type="text" name="natureza" value="<?= $h($d['natureza']) ?>" maxlength="60"></label>
      <label class="f">Recebedor retira no destino?<select name="retira"><option value="1" <?= $d['retira'] !== '0' ? 'selected' : '' ?>>Não</option><option value="0" <?= $d['retira'] === '0' ? 'selected' : '' ?>>Sim</option></select></label>
    </div>
  </section>

  <section class="tr-card cte-sec">
    <h2>4. Carga</h2>
    <div class="tr-grid three">
      <label class="f">Valor total da carga (R$)<input type="text" name="carga_valor" id="cargaValor" value="<?= $h($money($d['carga']['valor'])) ?>" inputmode="decimal"></label>
      <label class="f">Produto predominante<input type="text" name="carga_produto" id="cargaProduto" value="<?= $h($d['carga']['produto']) ?>" maxlength="60"></label>
      <label class="f">Outras características<input type="text" name="carga_outras" value="<?= $h($d['carga']['outras']) ?>" maxlength="30" placeholder="ex.: CAIXAS, PALETES"></label>
    </div>
    <table class="tr-table cte-linhas" id="qTab" style="margin-top:8px"><thead><tr><th style="width:140px">Unidade</th><th>Tipo de medida</th><th style="width:180px">Quantidade</th><th style="width:60px"></th></tr></thead><tbody></tbody></table>
    <?php if ($editavel): ?><button type="button" class="tr-btn sm" id="qAdd" style="margin-top:6px">+ Quantidade</button><?php endif; ?>
  </section>

  <section class="tr-card cte-sec">
    <h2>5. Valores do frete</h2>
    <p class="tr-sub">O valor da prestação é a soma dos componentes.</p>
    <table class="tr-table cte-linhas" id="compTab"><thead><tr><th>Componente</th><th style="width:200px">Valor (R$)</th><th style="width:60px"></th></tr></thead><tbody></tbody></table>
    <?php if ($editavel): ?><button type="button" class="tr-btn sm" id="compAdd" style="margin-top:6px">+ Componente</button><?php endif; ?>
    <div class="tr-grid three" style="margin-top:10px">
      <label class="f">Valor da prestação (R$)<input type="text" name="valor_prestacao" id="vPrest" value="<?= $h($money($d['valores']['prestacao'])) ?>" readonly></label>
      <label class="f">Valor a receber (R$)<input type="text" name="valor_receber" id="vRec" value="<?= $h($money($d['valores']['receber'])) ?>" inputmode="decimal"></label>
    </div>
  </section>

  <section class="tr-card cte-sec">
    <h2>6. Impostos</h2>
    <?php if ($simples): ?><p class="tr-msg ok" style="margin:0 0 10px">Empresa do Simples Nacional: o ICMS vai como "Simples Nacional" (indSN = 1), sem destaque.</p><?php endif; ?>
    <div class="tr-grid three" <?= $simples ? 'hidden' : '' ?>>
      <label class="f s2">Situação do ICMS<select name="icms[cst]"><?php foreach (transp_icms_situacoes_cte() as $c => $lb): $c = (string)$c; if ($c === 'SN') continue; ?><option value="<?= $c ?>" <?= (string)$d['icms']['cst'] === $c ? 'selected' : '' ?>><?= $h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f">Alíquota (%)<input type="text" name="icms[aliquota]" value="<?= $h($money($d['icms']['aliquota'])) ?>" inputmode="decimal"></label>
      <label class="f">Redução da base (%)<input type="text" name="icms[reducao]" value="<?= $h($money($d['icms']['reducao'])) ?>" inputmode="decimal"></label>
    </div>
    <?php if ($simples): ?><input type="hidden" name="icms[cst]" value="<?= $h($d['icms']['cst'] === 'SN' ? '00' : $d['icms']['cst']) ?>"><input type="hidden" name="icms[aliquota]" value="0"><input type="hidden" name="icms[reducao]" value="0"><?php endif; ?>
    <?php if ($cfg['cte_ibscbs_cclasstrib'] !== ''): ?>
      <div class="tr-grid three" style="margin-top:10px">
        <label class="f">Base do IBS/CBS (R$)<input type="text" name="ibscbs_base" value="<?= $d['ibscbs']['base_manual'] !== null ? $h($money($d['ibscbs']['base_manual'])) : '' ?>" inputmode="decimal" placeholder="automática"></label>
        <p class="tr-hint s2" style="margin:0;align-self:end">IBS/CBS: CST <?= $h($cfg['cte_ibscbs_cst']) ?>, classificação <?= $h($cfg['cte_ibscbs_cclasstrib']) ?> (Configurações). Base automática = valor da prestação menos o ICMS. Local = cidade de fim da prestação.</p>
      </div>
    <?php endif; ?>
    <div class="cte-conf tr-hint" id="confBox" style="margin-top:10px"></div>
  </section>

  <section class="tr-card cte-sec">
    <h2>7. Observações</h2>
    <label class="f">Observações do CT-e<textarea name="observacao" maxlength="2000"><?= $h($d['observacao']) ?></textarea></label>
    <?php if ($cfg['cte_obs_padrao'] !== ''): ?><p class="tr-hint" style="margin:6px 0 0">A observação padrão das configurações também vai: "<?= $h($cfg['cte_obs_padrao']) ?>"</p><?php endif; ?>
  </section>

  <?php if ($editavel): ?>
  <div class="cte-bar">
    <span class="tr-hint">Total do frete</span><span class="cte-tot" id="barTot">R$ <?= $h($money($d['valores']['prestacao'])) ?></span>
    <button type="button" class="tr-btn" id="btnConferir">Conferir</button>
    <button type="submit" class="tr-btn" data-act="salvar">Salvar rascunho</button>
    <button type="submit" class="tr-btn pri" data-act="transmitir">Salvar e transmitir</button>
  </div>
  <?php endif; ?>
</form>

<?php if ($hist): ?>
<section class="tr-card cte-sec" style="margin-top:12px">
  <h2>Histórico de comunicação com a SEFAZ</h2>
  <table class="tr-table"><thead><tr><th>Quando</th><th>Tipo</th><th>Retorno</th></tr></thead><tbody>
  <?php foreach ($hist as $x): ?><tr><td><?= $h(date('d/m/Y H:i:s', strtotime((string)$x['created_at']))) ?></td><td><?= $h(['ENVIO' => 'Transmissão', 'CONSULTA' => 'Consulta', 'VALIDACAO' => 'Conferência do Nexa'][$x['tipo']] ?? $x['tipo']) ?></td><td><?= $h(($x['cstat'] ? $x['cstat'] . ' - ' : '') . $x['xmotivo']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>

<script>
(function(){
  const EDIT=<?= $editavel ? 'true' : 'false' ?>;
  const CSRF=<?= json_encode(transp_csrf()) ?>;
  const form=document.getElementById('cteForm');
  const D=<?= json_encode(['nfe' => $d['documentos']['nfe'], 'outros' => $d['documentos']['outros'], 'q' => $d['carga']['quantidades'], 'comp' => $d['valores']['componentes'], 'unid' => cte_unidades(), 'outTipos' => cte_outros_tipos(), 'cfopE' => (string)$cfg['cte_cfop_estadual'], 'cfopI' => (string)$cfg['cte_cfop_interestadual']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num=v=>{v=String(v??'').trim();if(!v)return 0;if(v.includes(','))v=v.replace(/\./g,'').replace(',','.');const n=parseFloat(v);return isFinite(n)?n:0;};
  const fmt=(n,d=2)=>Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d});
  const F=n=>form.querySelector('[name="'+n+'"]');
  const api=(acao,qs)=>fetch('cte_api.php?acao='+acao+(qs?'&'+qs:''),{credentials:'same-origin'}).then(r=>r.json());
  const apiPost=(acao,body)=>{body.append('csrf',CSRF);return fetch('cte_api.php?acao='+acao,{method:'POST',body,credentials:'same-origin'}).then(r=>r.json());};
  const del=EDIT?'<button type="button" class="tr-btn sm red" data-del>×</button>':'';

  // ---------- linhas (NF-e, outros, quantidades, componentes)
  const nfeTb=document.querySelector('#nfeTab tbody'), outTb=document.querySelector('#outTab tbody'), qTb=document.querySelector('#qTab tbody'), cTb=document.querySelector('#compTab tbody');
  function addNfe(ch,prev){if([...nfeTb.querySelectorAll('[name="nfe_chave[]"]')].some(i=>i.value===ch))return false;nfeTb.insertAdjacentHTML('beforeend','<tr><td><input type="text" name="nfe_chave[]" value="'+esc(ch)+'" readonly style="font-family:ui-monospace,monospace"></td><td><input type="date" name="nfe_prev[]" value="'+esc(prev||'')+'"></td><td>'+del+'</td></tr>');return true;}
  function addOut(o){o=o||{};let opts='';for(const[k,v]of Object.entries(D.outTipos))opts+='<option value="'+k+'"'+(o.tipo===k?' selected':'')+'>'+esc(v)+'</option>';outTb.insertAdjacentHTML('beforeend','<tr><td><select name="out_tipo[]">'+opts+'</select></td><td><input type="text" name="out_desc[]" value="'+esc(o.descricao||'')+'" maxlength="100"></td><td><input type="text" name="out_numero[]" value="'+esc(o.numero||'')+'" maxlength="20"></td><td><input type="date" name="out_data[]" value="'+esc(o.data||'')+'"></td><td><input type="text" name="out_valor[]" value="'+(o.valor?fmt(o.valor):'')+'" inputmode="decimal"></td><td>'+del+'</td></tr>');}
  function addQ(q){q=q||{unidade:'01',tipo:'PESO BRUTO',quantidade:''};let opts='';for(const[k,v]of Object.entries(D.unid))opts+='<option value="'+k+'"'+(q.unidade===k?' selected':'')+'>'+esc(v)+'</option>';qTb.insertAdjacentHTML('beforeend','<tr><td><select name="q_unidade[]">'+opts+'</select></td><td><input type="text" name="q_tipo[]" value="'+esc(q.tipo||'')+'" maxlength="20" placeholder="PESO BRUTO, CAIXAS..."></td><td><input type="text" name="q_quantidade[]" value="'+(q.quantidade!==''&&q.quantidade!=null?fmt(q.quantidade,4).replace(/,?0+$/,''):'')+'" inputmode="decimal"></td><td>'+del+'</td></tr>');}
  function addComp(c){c=c||{nome:'',valor:''};cTb.insertAdjacentHTML('beforeend','<tr><td><input type="text" name="comp_nome[]" value="'+esc(c.nome)+'" maxlength="15" placeholder="FRETE VALOR, PEDÁGIO..." style="text-transform:uppercase"></td><td><input type="text" name="comp_valor[]" value="'+(c.valor!==''?fmt(c.valor):'')+'" inputmode="decimal" class="comp-v"></td><td>'+del+'</td></tr>');}
  (D.nfe||[]).forEach(n=>addNfe(n.chave,n.dprev));(D.outros||[]).forEach(addOut);
  (D.q&&D.q.length?D.q:[null]).forEach(addQ);(D.comp&&D.comp.length?D.comp:[null]).forEach(addComp);
  const vPrest=document.getElementById('vPrest'), vRec=document.getElementById('vRec'), barTot=document.getElementById('barTot');
  let recManual=num(vRec.value)!==num(vPrest.value);
  function soma(){let t=0;cTb.querySelectorAll('.comp-v').forEach(i=>t+=num(i.value));vPrest.value=fmt(t);if(!recManual)vRec.value=fmt(t);if(barTot)barTot.textContent='R$ '+fmt(t);}
  vRec.addEventListener('input',()=>{recManual=true;});
  form.addEventListener('input',e=>{if(e.target.classList.contains('comp-v'))soma();});
  form.addEventListener('click',e=>{const b=e.target.closest('[data-del]');if(b){b.closest('tr').remove();soma();}});
  if(EDIT){
    document.getElementById('outAdd').onclick=()=>addOut();
    document.getElementById('qAdd').onclick=()=>addQ();
    document.getElementById('compAdd').onclick=()=>addComp();
  } else {
    form.querySelectorAll('input,select,textarea').forEach(el=>{if(el.type!=='hidden'){el.disabled=true;}});
  }

  // ---------- participantes
  const FIELDS=['pessoa_id','documento','ie','nome','fantasia','fone','email','logradouro','numero','complemento','bairro','cep','cidade_ibge','cidade','uf'];
  const P=(papel,f)=>F('part['+papel+']['+f+']');
  const vazio=papel=>!P(papel,'documento').value.trim()&&!P(papel,'nome').value.trim();
  function setPart(papel,p,force){if(!force&&!vazio(papel))return false;FIELDS.forEach(f=>{const el=P(papel,f);if(el)el.value=p[f]??'';});marcaAbas();return true;}
  function marcaAbas(){document.querySelectorAll('[data-tab-papel]').forEach(b=>{b.querySelector('.cte-tab-ok').textContent=vazio(b.dataset.tabPapel)?'':' ✓';});}
  marcaAbas();
  form.addEventListener('change',e=>{if(e.target.name&&e.target.name.startsWith('part['))marcaAbas();});
  // tomador: abre a aba certa
  const tomaMap={'0':'remetente','1':'expedidor','2':'recebedor','3':'destinatario','4':'tomador'};
  document.getElementById('tomador').addEventListener('change',e=>{const b=document.querySelector('[data-tab-papel="'+tomaMap[e.target.value]+'"]');if(b)b.click();});
  function sugestoes(box,itens,label,pick){box.innerHTML='';if(!itens.length){box.hidden=true;return;}itens.forEach(it=>{const b=document.createElement('button');b.type='button';b.innerHTML=label(it);b.addEventListener('mousedown',ev=>{ev.preventDefault();pick(it);box.hidden=true;});box.appendChild(b);});box.hidden=false;}
  let tm=null;
  document.querySelectorAll('.cte-pessoa-q').forEach(inp=>{
    const box=inp.closest('.cte-busca').querySelector('.cte-sug'), papel=inp.closest('[data-papel]').dataset.papel;
    inp.addEventListener('input',()=>{clearTimeout(tm);const q=inp.value.trim();if(q.length<2){box.hidden=true;return;}tm=setTimeout(()=>api('pessoas','q='+encodeURIComponent(q)).then(j=>sugestoes(box,j.itens||[],p=>'<b>'+esc(p.nome)+'</b><br><span class="tr-hint">'+esc(p.documento)+' · '+esc(p.cidade)+'/'+esc(p.uf)+'</span>',p=>{setPart(papel,p,true);inp.value='';percursoAuto(false);})),250);});
    inp.addEventListener('blur',()=>setTimeout(()=>box.hidden=true,150));
  });
  document.querySelectorAll('.cte-cnpj').forEach(b=>b.addEventListener('click',()=>{
    const papel=b.closest('[data-papel]').dataset.papel, doc=P(papel,'documento').value.replace(/\D/g,'');
    if(doc.length!==14){alert('Digite um CNPJ com 14 números.');return;}
    b.disabled=true;fetch('pessoa_cnpj_lookup.php?cnpj='+doc,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(!j.ok){alert(j.error||'CNPJ não encontrado.');return;}const x=j.data;
      const m={nome:x.razao_social,fantasia:x.nome_fantasia,ie:x.state_registration,fone:x.telefone,email:x.email,cep:x.cep,logradouro:x.logradouro,numero:x.numero,complemento:x.complemento,bairro:x.bairro,cidade:x.municipio,cidade_ibge:x.city_ibge,uf:x.uf};
      for(const[k,v]of Object.entries(m)){const el=P(papel,k);if(el&&v)el.value=String(v).slice(0,60);}marcaAbas();percursoAuto(false);
    }).catch(()=>alert('Não foi possível consultar o CNPJ agora.')).finally(()=>b.disabled=false);
  }));
  document.querySelectorAll('.cte-cep').forEach(b=>b.addEventListener('click',()=>{
    const papel=b.closest('[data-papel]').dataset.papel, cep=P(papel,'cep').value.replace(/\D/g,'');
    if(cep.length!==8){alert('Digite o CEP com 8 números.');return;}
    b.disabled=true;fetch('pessoa_cep_lookup.php?cep='+cep,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(!j.ok){alert(j.error||'CEP não encontrado.');return;}const x=j.data;
      const m={logradouro:x.logradouro,bairro:x.bairro,cidade:x.cidade,cidade_ibge:x.city_ibge,uf:x.uf};
      for(const[k,v]of Object.entries(m)){const el=P(papel,k);if(el&&v)el.value=v;}percursoAuto(false);
    }).catch(()=>alert('Não foi possível consultar o CEP agora.')).finally(()=>b.disabled=false);
  }));
  document.querySelectorAll('.cte-limpar').forEach(b=>b.addEventListener('click',()=>{const papel=b.closest('[data-papel]').dataset.papel;FIELDS.forEach(f=>{const el=P(papel,f);if(el)el.value='';});marcaAbas();}));
  // cidades (código IBGE)
  document.querySelectorAll('.cte-cidade').forEach(inp=>{
    const box=inp.parentElement.querySelector('.cte-sug'), ibge=F(inp.dataset.ibge), uf=F(inp.dataset.uf);
    inp.addEventListener('input',()=>{if(ibge)ibge.value='';clearTimeout(tm);const q=inp.value.trim();if(q.length<2){box.hidden=true;return;}tm=setTimeout(()=>api('cidades','q='+encodeURIComponent(q)+'&uf='+encodeURIComponent(uf?uf.value:'')).then(j=>sugestoes(box,j.itens||[],c=>esc(c.nome)+' - '+esc(c.uf)+' <span class="tr-hint">'+esc(c.ibge)+'</span>',c=>{inp.value=c.nome;if(ibge)ibge.value=c.ibge;if(uf)uf.value=c.uf;cfopAuto();})),250);});
    inp.addEventListener('blur',()=>setTimeout(()=>box.hidden=true,150));
  });
  // percurso e CFOP
  let cfopManual=<?= $row ? 'true' : 'false' ?>;
  document.getElementById('cfop').addEventListener('change',()=>{cfopManual=true;});
  function cfopAuto(){if(cfopManual)return;const a=F('percurso[uf_ini]').value,b=F('percurso[uf_fim]').value;if(!a||!b)return;document.getElementById('cfop').value=a===b?D.cfopE:D.cfopI;}
  ['percurso[uf_ini]','percurso[uf_fim]'].forEach(n=>F(n).addEventListener('change',cfopAuto));
  function percursoAuto(force){
    const ini=vazio('expedidor')?'remetente':'expedidor', fim=vazio('recebedor')?'destinatario':'recebedor';
    const pares=[[ini,'ini'],[fim,'fim']];
    pares.forEach(([papel,k])=>{const nome=F('percurso[mun_'+k+'_nome]');if(!force&&nome.value.trim())return;if(!P(papel,'cidade_ibge').value)return;nome.value=P(papel,'cidade').value;F('percurso[mun_'+k+'_ibge]').value=P(papel,'cidade_ibge').value;F('percurso[uf_'+k+']').value=P(papel,'uf').value;});
    cfopAuto();
  }
  if(EDIT)document.getElementById('percAuto').onclick=()=>percursoAuto(true);

  // ---------- NF-e
  if(EDIT){
    const msg=document.getElementById('nfeMsg'), chIn=document.getElementById('nfeChave');
    let somaPeso=0;
    function aplicaNfe(n,origem){
      const novo=addNfe(n.chave,'');if(!novo){msg.textContent='A NF-e '+n.chave+' já está na lista.';return;}
      const r1=setPart('remetente',n.remetente,false), r2=setPart('destinatario',n.destinatario,false);
      const cv=document.getElementById('cargaValor');cv.value=fmt(num(cv.value)+Number(n.valor||0));
      const pr=document.getElementById('cargaProduto');if(!pr.value.trim()&&n.produto)pr.value=n.produto;
      if(n.peso_bruto>0){const linha=[...qTb.querySelectorAll('tr')].find(tr=>tr.querySelector('[name="q_unidade[]"]').value==='01');if(linha){const q=linha.querySelector('[name="q_quantidade[]"]');q.value=fmt(num(q.value)+n.peso_bruto,4).replace(/,?0+$/,'');}}
      percursoAuto(false);
      msg.textContent='NF-e '+(n.numero||'')+' adicionada'+(origem?' ('+origem+')':'')+(r1||r2?': remetente/destinatário preenchidos.':'.')+' Valor R$ '+fmt(n.valor)+(n.peso_bruto?' · peso '+fmt(n.peso_bruto,3)+' kg':'')+'.';
    }
    function addChave(){
      const ch=chIn.value.replace(/\D/g,'');
      if(ch.length!==44){msg.textContent='A chave tem 44 números (você digitou '+ch.length+').';return;}
      msg.textContent='Procurando a NF-e no Nexa...';
      api('nfe','chave='+ch).then(j=>{
        if(!j.ok){msg.textContent=j.error||'Chave inválida.';return;}
        if(j.encontrada){aplicaNfe(j.nfe,'dados do Nexa');}
        else{addNfe(ch,'');msg.textContent='Chave adicionada. A nota não está no Nexa: preencha remetente, destinatário e carga, ou envie o XML.';}
        chIn.value='';chIn.focus();
      }).catch(()=>{msg.textContent='Não foi possível procurar agora.';});
    }
    document.getElementById('nfeAdd').onclick=addChave;
    chIn.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addChave();}});
    document.getElementById('nfeXml').addEventListener('change',e=>{
      [...e.target.files].forEach(f=>{const fr=new FileReader();fr.onload=()=>{const fd=new FormData();fd.append('xml',fr.result);apiPost('nfe_xml',fd).then(j=>{if(!j.ok){msg.textContent=f.name+': '+(j.error||'arquivo inválido');return;}aplicaNfe(j.nfe,'XML');});};fr.readAsText(f);});
      e.target.value='';
    });
  }

  // ---------- conferir (impostos e o que falta)
  const conf=document.getElementById('confBox');
  function conferir(){
    if(!EDIT)return;
    const fd=new FormData(form);fd.delete('csrf');fd.delete('action');
    conf.innerHTML='Conferindo...';
    apiPost('conferir',fd).then(j=>{
      if(!j.ok){conf.innerHTML='<span style="color:#b91c1c">'+esc(j.error)+'</span>';return;}
      const i=j.impostos.icms;let h='<b>ICMS:</b> '+(i.cst==='SN'?'Simples Nacional (sem destaque)':(i.valor>0?'base R$ '+fmt(i.base)+' × '+fmt(i.aliquota)+'% = <b>R$ '+fmt(i.valor)+'</b>':'situação '+esc(i.cst)+', sem valor'));
      const x=j.impostos.ibscbs;if(x)h+=' · <b>IBS/CBS:</b> base R$ '+fmt(x.base)+' · CBS '+fmt(x.cbs_rate,2)+'% = R$ '+fmt(x.cbs_amount)+' · IBS '+fmt(x.ibs_uf_rate+x.ibs_mun_rate,2)+'% = R$ '+fmt(x.ibs_amount);
      if(j.impostos.erro_ibscbs)h+='<br><span style="color:#b91c1c">'+esc(j.impostos.erro_ibscbs)+'</span>';
      h+=j.pendencias.length?'<div class="tr-msg warn" style="margin:8px 0 0">Falta para transmitir:<ul>'+j.pendencias.map(p=>'<li>'+esc(p)+'</li>').join('')+'</ul></div>':'<div class="tr-msg ok" style="margin:8px 0 0">Tudo certo para transmitir.</div>';
      conf.innerHTML=h;
    }).catch(()=>{conf.innerHTML='Não foi possível conferir agora.';});
  }
  if(EDIT){
    document.getElementById('btnConferir').onclick=conferir;
    // A confirmação e a trava contra clique duplo ficam com o rodapé do módulo (data-confirm).
    form.querySelectorAll('button[data-act]').forEach(b=>b.addEventListener('click',()=>{form.querySelector('[name=action]').value=b.dataset.act;if(b.dataset.act==='transmitir')form.dataset.confirm='Salvar e transmitir o CT-e para a SEFAZ?';else delete form.dataset.confirm;}));
    form.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.tagName==='INPUT')e.preventDefault();});
  }
  soma();
})();
</script>
<?php transp_end(); ?>
