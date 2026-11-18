<?php
declare(strict_types=1);

// Transporte (CT-e/MDF-e): configurações, situação da instalação e teste de comunicação com a SEFAZ.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_transporte.php';

$cid = transp_guard($pdo, 'transp_configuracoes');
$hasCte = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, $cid, 'cte') : true;
$hasMdfe = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, $cid, 'mdfe') : true;
$aba = in_array((string)($_GET['aba'] ?? ''), ['cte', 'mdfe'], true) ? (string)$_GET['aba'] : ($hasCte ? 'cte' : 'mdfe');

transp_post_handle('transporte_configuracoes.php?aba=' . $aba, function () use ($pdo, $cid, $hasCte, $hasMdfe) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'salvar_cte' && $hasCte) {
    $cfg = transp_config_save($pdo, $cid, $_POST);
    if (trim((string)($_POST['cte_proximo'] ?? '')) !== '') transp_numeracao_set($pdo, $cid, TRANSP_MODELO_CTE, (int)$cfg['cte_serie'], (int)$_POST['cte_proximo']);
    return ['Configurações do CT-e salvas.', 'transporte_configuracoes.php?aba=cte'];
  }
  if ($action === 'salvar_mdfe' && $hasMdfe) {
    $cfg = transp_config_save($pdo, $cid, $_POST);
    if (trim((string)($_POST['mdfe_proximo'] ?? '')) !== '') transp_numeracao_set($pdo, $cid, TRANSP_MODELO_MDFE, (int)$cfg['mdfe_serie'], (int)$_POST['mdfe_proximo']);
    return ['Configurações do MDF-e salvas.', 'transporte_configuracoes.php?aba=mdfe'];
  }
  if ($action === 'testar' && in_array((string)($_POST['doc'] ?? ''), ['cte', 'mdfe'], true)) {
    $doc = (string)$_POST['doc'];
    if (($doc === 'cte' && !$hasCte) || ($doc === 'mdfe' && !$hasMdfe)) throw new RuntimeException('Módulo não liberado para esta empresa.');
    $r = transp_sefaz_status($pdo, $cid, $doc);
    $nome = $doc === 'cte' ? 'CT-e' : 'MDF-e';
    $amb = $r['ambiente'] === 'PRODUCAO' ? 'produção' : 'homologação';
    $msg = 'SEFAZ do ' . $nome . ' (' . $amb . '): ' . $r['cStat'] . ' - ' . $r['xMotivo'] . ($r['tempo_medio'] !== '' ? ' (tempo médio ' . $r['tempo_medio'] . ' s)' : '') . '.';
    if (!$r['ok']) throw new RuntimeException($msg);
    return [$msg . ' Certificado e comunicação funcionando.', 'transporte_configuracoes.php?aba=' . $doc];
  }
  throw new RuntimeException('Ação inválida.');
});

$ready = transp_ready($pdo);
$cfg = transp_config($pdo, $cid);
$inst = transp_instalacao();
$em = transp_emitente($pdo, $cid);
$homolog = nexa_is_homologacao();
$cteProx = $ready ? transp_numeracao_get($pdo, $cid, TRANSP_MODELO_CTE, (int)$cfg['cte_serie']) : 1;
$mdfeProx = $ready ? transp_numeracao_get($pdo, $cid, TRANSP_MODELO_MDFE, (int)$cfg['mdfe_serie']) : 1;
$pendCte = $hasCte ? transp_pendencias($pdo, $cid, $cfg, 'cte') : [];
$pendMdfe = $hasMdfe ? transp_pendencias($pdo, $cid, $cfg, 'mdfe') : [];
$certInfo = null;
try { require_once __DIR__ . '/../config/fiscal_operational.php'; $certInfo = fiscal_nfce_active_certificate($pdo, $cid); } catch (Throwable $e) { $certErr = $e->getMessage(); }
$crtNomes = ['1' => 'Simples Nacional', '2' => 'Simples Nacional - excesso de sublimite', '3' => 'Regime Normal', '4' => 'MEI'];
$ok = static fn(bool $b, string $sim, string $nao): string => $b ? '<b style="color:#15803d">✓</b><span>' . transp_h($sim) . '</span>' : '<b style="color:#b91c1c">✗</b><span>' . transp_h($nao) . '</span>';
$sel = static fn($a, $b): string => (string)$a === (string)$b ? 'selected' : '';

transp_start($pdo, 'transp_configuracoes', 'Transporte — Configurações', 'Configurações do CT-e e do MDF-e. Os dados do emitente (CNPJ, IE, endereço, regime) e o certificado digital são os mesmos da NF-e, em Configurações fiscais.');
?>
<?php if ($homolog): ?><div class="tr-msg warn">Você está no ambiente de homologação do Nexa: CT-e e MDF-e sempre vão para a SEFAZ em <b>homologação</b> (sem valor fiscal), mesmo que a opção abaixo esteja em Produção.</div><?php endif; ?>

<div class="tr-cols" style="margin-bottom:12px">
  <section class="tr-card">
    <h2>Situação do servidor</h2>
    <p class="tr-sub">Bibliotecas oficiais NFePHP instaladas pelo <code>composer install</code>.</p>
    <ul class="tr-checks">
      <li><?= $ok(!$inst['extensoes'], 'Extensões do PHP completas', 'Faltam extensões do PHP: ' . implode(', ', $inst['extensoes'])) ?></li>
      <li><?= $ok($inst['cte'], 'Biblioteca do CT-e (sped-cte)', 'Biblioteca do CT-e não instalada (rode composer install)') ?></li>
      <li><?= $ok($inst['cte_rtc'], 'Esquemas do CT-e 4.00 com IBS/CBS', 'Esquemas do CT-e com IBS/CBS não encontrados') ?></li>
      <li><?= $ok($inst['mdfe'], 'Biblioteca do MDF-e (sped-mdfe)', 'Biblioteca do MDF-e não instalada (rode composer install)') ?></li>
      <li><?= $ok($inst['dacte'] && $inst['damdfe'], 'Impressão do DACTE e do DAMDFE (sped-da)', 'Impressão do DACTE/DAMDFE indisponível') ?></li>
    </ul>
  </section>
  <section class="tr-card">
    <h2>Emitente e certificado</h2>
    <p class="tr-sub">Para alterar, use <a href="fiscal_configuracoes.php">Configurações fiscais</a> (dados da empresa e certificado digital).</p>
    <ul class="tr-checks">
      <li><b>Empresa:</b><span><?= transp_h($em['razao_social'] ?: '—') ?></span></li>
      <li><b>CNPJ / IE:</b><span><?= transp_h($em['cnpj'] ?: '—') ?> / <?= transp_h($em['ie'] ?: '—') ?></span></li>
      <li><b>Cidade:</b><span><?= transp_h(($em['cidade'] ?: '—') . ' - ' . ($em['uf'] ?: '—')) ?></span></li>
      <li><b>Regime:</b><span><?= transp_h($crtNomes[$em['crt']] ?? 'não informado') ?></span></li>
      <li><?= $certInfo ? $ok(true, 'Certificado A1 ativo' . (!empty($certInfo['expires_at_datetime']) ? ', vence em ' . date('d/m/Y', strtotime((string)$certInfo['expires_at_datetime'])) : ''), '') : $ok(false, '', 'Certificado: ' . ($certErr ?? 'não encontrado')) ?></li>
    </ul>
  </section>
</div>

<nav class="tr-mtabs" style="margin-bottom:12px">
  <?php if ($hasCte): ?><button type="button" class="<?= $aba === 'cte' ? 'on' : '' ?>" onclick="location.href='transporte_configuracoes.php?aba=cte'">CT-e (conhecimento de transporte)</button><?php endif; ?>
  <?php if ($hasMdfe): ?><button type="button" class="<?= $aba === 'mdfe' ? 'on' : '' ?>" onclick="location.href='transporte_configuracoes.php?aba=mdfe'">MDF-e (manifesto)</button><?php endif; ?>
</nav>

<?php if ($aba === 'cte' && $hasCte): ?>
<section class="tr-card">
  <?php if ($pendCte): ?><div class="tr-msg warn">Falta para emitir CT-e:<ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($pendCte as $p): ?><li><?= transp_h($p) ?></li><?php endforeach; ?></ul></div>
  <?php else: ?><div class="tr-msg ok">Cadastro completo para emitir CT-e.</div><?php endif; ?>
  <form method="post">
    <?= transp_csrf_field() ?><input type="hidden" name="action" value="salvar_cte">
    <div class="tr-grid three">
      <label class="f">Ambiente da SEFAZ<select name="cte_ambiente"><option value="HOMOLOGACAO" <?= $sel($cfg['cte_ambiente'], 'HOMOLOGACAO') ?>>Homologação (testes, sem valor fiscal)</option><option value="PRODUCAO" <?= $sel($cfg['cte_ambiente'], 'PRODUCAO') ?>>Produção</option></select></label>
      <label class="f">Série<input type="number" name="cte_serie" min="0" max="999" value="<?= (int)$cfg['cte_serie'] ?>"></label>
      <label class="f">Próximo número desta série<input type="number" name="cte_proximo" min="1" value="<?= (int)$cteProx ?>"></label>
      <label class="f">RNTRC da empresa (ANTT)<input type="text" name="cte_rntrc" inputmode="numeric" maxlength="8" value="<?= transp_h($cfg['cte_rntrc']) ?>" placeholder="8 dígitos"></label>
      <label class="f s2">Natureza da operação<input type="text" name="cte_natureza" maxlength="60" value="<?= transp_h($cfg['cte_natureza']) ?>"></label>
      <label class="f s2">CFOP padrão dentro do estado<select name="cte_cfop_estadual"><?php foreach (transp_cfops_cte() as $c => $lb): $c = (string)$c; if ($c[0] !== '5') continue; ?><option value="<?= $c ?>" <?= $sel($cfg['cte_cfop_estadual'], $c) ?>><?= transp_h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f">&nbsp;<span class="tr-hint">O CFOP pode ser trocado em cada CT-e.</span></label>
      <label class="f s2">CFOP padrão para outro estado<select name="cte_cfop_interestadual"><?php foreach (transp_cfops_cte() as $c => $lb): $c = (string)$c; if ($c[0] !== '6') continue; ?><option value="<?= $c ?>" <?= $sel($cfg['cte_cfop_interestadual'], $c) ?>><?= transp_h($lb) ?></option><?php endforeach; ?></select></label>
      <div class="sec">ICMS padrão</div>
      <label class="f s2">Situação tributária<select name="cte_icms_cst"><?php foreach (transp_icms_situacoes_cte() as $c => $lb): ?><option value="<?= $c ?>" <?= $sel($cfg['cte_icms_cst'], $c) ?>><?= transp_h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f">Alíquota (%)<input type="text" name="cte_icms_aliquota" inputmode="decimal" value="<?= transp_h(number_format((float)$cfg['cte_icms_aliquota'], 2, ',', '')) ?>"></label>
      <label class="f">Redução da base (%)<input type="text" name="cte_icms_reducao" inputmode="decimal" value="<?= transp_h(number_format((float)$cfg['cte_icms_reducao'], 2, ',', '')) ?>"></label>
      <div class="sec">IBS / CBS (Reforma Tributária)</div>
      <label class="f">CST do IBS/CBS<input type="text" name="cte_ibscbs_cst" inputmode="numeric" maxlength="3" value="<?= transp_h($cfg['cte_ibscbs_cst']) ?>" placeholder="ex.: 000"></label>
      <label class="f">Classificação (cClassTrib)<input type="text" name="cte_ibscbs_cclasstrib" inputmode="numeric" maxlength="6" value="<?= transp_h($cfg['cte_ibscbs_cclasstrib']) ?>" placeholder="6 dígitos"></label>
      <p class="tr-hint" style="margin:0;align-self:end">Obrigatório para empresas do Regime Normal. Para o Simples Nacional e MEI pode ficar em branco. Confira os códigos com a contabilidade (tabela oficial em Tabelas &gt; IBS/CBS).</p>
      <label class="f s3">Observação padrão (vai em todo CT-e)<textarea name="cte_obs_padrao" maxlength="2000"><?= transp_h($cfg['cte_obs_padrao']) ?></textarea></label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:12px"><button class="tr-btn pri" type="submit">Salvar configurações do CT-e</button></div>
  </form>
  <form method="post" style="margin-top:10px;border-top:1px solid #e2e8f0;padding-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?= transp_csrf_field() ?><input type="hidden" name="action" value="testar"><input type="hidden" name="doc" value="cte">
    <button class="tr-btn" type="submit">Testar comunicação com a SEFAZ (CT-e)</button>
    <span class="tr-hint">Consulta só a situação do serviço da SEFAZ (não emite nada). Confere o certificado, a biblioteca e a internet do servidor. Ambiente que será usado: <b><?= transp_ambiente_efetivo($cfg, 'cte') === 'PRODUCAO' ? 'produção' : 'homologação' ?></b>.</span>
  </form>
</section>
<?php endif; ?>

<?php if ($aba === 'mdfe' && $hasMdfe): ?>
<section class="tr-card">
  <?php if ($pendMdfe): ?><div class="tr-msg warn">Falta para emitir MDF-e:<ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($pendMdfe as $p): ?><li><?= transp_h($p) ?></li><?php endforeach; ?></ul></div>
  <?php else: ?><div class="tr-msg ok">Cadastro completo para emitir MDF-e.</div><?php endif; ?>
  <form method="post">
    <?= transp_csrf_field() ?><input type="hidden" name="action" value="salvar_mdfe">
    <div class="tr-grid three">
      <label class="f">Ambiente da SEFAZ<select name="mdfe_ambiente"><option value="HOMOLOGACAO" <?= $sel($cfg['mdfe_ambiente'], 'HOMOLOGACAO') ?>>Homologação (testes, sem valor fiscal)</option><option value="PRODUCAO" <?= $sel($cfg['mdfe_ambiente'], 'PRODUCAO') ?>>Produção</option></select></label>
      <label class="f">Série<input type="number" name="mdfe_serie" min="0" max="999" value="<?= (int)$cfg['mdfe_serie'] ?>"></label>
      <label class="f">Próximo número desta série<input type="number" name="mdfe_proximo" min="1" value="<?= (int)$mdfeProx ?>"></label>
      <label class="f s2">Tipo de emitente<select name="mdfe_tipo_emitente">
        <option value="1" <?= $sel($cfg['mdfe_tipo_emitente'], '1') ?>>Transportadora (prestador de serviço de transporte, com CT-e)</option>
        <option value="2" <?= $sel($cfg['mdfe_tipo_emitente'], '2') ?>>Carga própria (a empresa leva a própria mercadoria, com NF-e)</option>
        <option value="3" <?= $sel($cfg['mdfe_tipo_emitente'], '3') ?>>Transportadora com CT-e globalizado</option>
      </select></label>
      <label class="f">Tipo de transportador<select name="mdfe_tipo_transportador">
        <option value="" <?= $sel($cfg['mdfe_tipo_transportador'], '') ?>>Não informar</option>
        <option value="1" <?= $sel($cfg['mdfe_tipo_transportador'], '1') ?>>ETC - Empresa de transporte</option>
        <option value="2" <?= $sel($cfg['mdfe_tipo_transportador'], '2') ?>>TAC - Autônomo</option>
        <option value="3" <?= $sel($cfg['mdfe_tipo_transportador'], '3') ?>>CTC - Cooperativa</option>
      </select></label>
      <div class="sec">Seguro da carga (obrigatório para transportadora)</div>
      <label class="f">Responsável pelo seguro<select name="mdfe_seguro_responsavel"><option value="1" <?= $sel($cfg['mdfe_seguro_responsavel'], '1') ?>>Emitente do MDF-e</option><option value="2" <?= $sel($cfg['mdfe_seguro_responsavel'], '2') ?>>Contratante do serviço</option></select></label>
      <label class="f">Seguradora (nome)<input type="text" name="mdfe_seguradora_nome" maxlength="30" value="<?= transp_h($cfg['mdfe_seguradora_nome']) ?>"></label>
      <label class="f">CNPJ da seguradora<input type="text" name="mdfe_seguradora_cnpj" inputmode="numeric" maxlength="18" value="<?= transp_h($cfg['mdfe_seguradora_cnpj']) ?>"></label>
      <label class="f">Número da apólice<input type="text" name="mdfe_seguro_apolice" maxlength="20" value="<?= transp_h($cfg['mdfe_seguro_apolice']) ?>"></label>
      <p class="tr-hint" style="margin:0;align-self:end;grid-column:span 2">O RNTRC da empresa é o mesmo informado na aba CT-e. Os números de averbação do seguro serão informados em cada MDF-e.</p>
      <label class="f s3">Observação padrão (vai em todo MDF-e)<textarea name="mdfe_obs_padrao" maxlength="2000"><?= transp_h($cfg['mdfe_obs_padrao']) ?></textarea></label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:12px"><button class="tr-btn pri" type="submit">Salvar configurações do MDF-e</button></div>
  </form>
  <form method="post" style="margin-top:10px;border-top:1px solid #e2e8f0;padding-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?= transp_csrf_field() ?><input type="hidden" name="action" value="testar"><input type="hidden" name="doc" value="mdfe">
    <button class="tr-btn" type="submit">Testar comunicação com a SEFAZ (MDF-e)</button>
    <span class="tr-hint">Consulta só a situação do serviço (não emite nada). Ambiente que será usado: <b><?= transp_ambiente_efetivo($cfg, 'mdfe') === 'PRODUCAO' ? 'produção' : 'homologação' ?></b>.</span>
  </form>
</section>
<?php endif; ?>

<?php if (!$hasCte && !$hasMdfe): ?><div class="tr-msg warn">Nenhum dos módulos CT-e ou MDF-e está liberado para esta empresa.</div><?php endif; ?>
<?php transp_end(); ?>
