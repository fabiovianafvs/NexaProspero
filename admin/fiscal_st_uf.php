<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/nexa_st_recipient.php';

$companyId = (int)COMPANY_ID;
$platform = function_exists('auth_role') && auth_role() === 'platform_admin';
$master = $platform || (function_exists('access_is_company_master') && access_is_company_master());
$cross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $companyId;
$hasSchema = false;
try {
  $check=$pdo->query("SHOW COLUMNS FROM finance_people LIKE 'tax_regime'");
  $hasSchema=(bool)$check->fetch(PDO::FETCH_ASSOC);
  $hasSchema=$hasSchema && (bool)$pdo->query("SHOW TABLES LIKE 'fiscal_st_state_rates'")->fetchColumn()
    && (bool)$pdo->query("SHOW TABLES LIKE 'fiscal_st_ncm_mva'")->fetchColumn();
} catch (Throwable $e) {}
$hasProfileSchema = false;
try { $hasProfileSchema = (bool)$pdo->query("SHOW TABLES LIKE 'fiscal_st_nfe_profiles'")->fetchColumn(); } catch (Throwable $e) {}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['nexa_st_csrf'])) $_SESSION['nexa_st_csrf']=bin2hex(random_bytes(24));
$err = ''; $ok = trim((string)($_GET['ok'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!$hasSchema) throw new RuntimeException('Execute a migration de ICMS-ST no Atualizador do Banco.');
    if ($cross) throw new RuntimeException('Configuração fiscal de ICMS-ST bloqueada durante a troca de empresa F11.');
    if (!$master) throw new RuntimeException('Somente o administrador principal pode alterar configurações de ICMS-ST.');
    if (!hash_equals((string)$_SESSION['nexa_st_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'company') {
      $cfg=fiscal_company_config($pdo,$companyId);
      $enableRequested = !empty($_POST['enabled']);
      if ($enableRequested && (string)($cfg['environment'] ?? '') !== 'HOMOLOGACAO') {
        throw new RuntimeException('A ativação somente é permitida em ambiente de HOMOLOGAÇÃO.');
      }
      $flags=['enabled'=>$enableRequested,'production_approved'=>false, 'updated_by'=>(int)(auth_user_id() ?? 0)];
      $json=json_encode($flags, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $st=$pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');
      $st->execute([$companyId,'fiscal_st_recipient_v1',$json]);
    } elseif ($action === 'nfe_profile') {
      if (!$hasProfileSchema) throw new RuntimeException('Aplique a migration fiscal_st_cfop_nfe_v3 pelo Atualizador do Banco.');
      $mvaId = (int)($_POST['mva_id'] ?? 0);
      if ($mvaId <= 0) throw new RuntimeException('Selecione um registro MVA existente.');
      $st = $pdo->prepare('SELECT ncm, uf, cest FROM fiscal_st_ncm_mva WHERE id=? LIMIT 1');
      $st->execute([$mvaId]);
      if (!$st->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Registro de MVA não encontrado.');
      $codes = nexa_st_nfe_validate_codes([
        'cfop' => (string)($_POST['nfe_cfop'] ?? ''),
        'cst' => (string)($_POST['nfe_cst'] ?? ''),
        'csosn' => (string)($_POST['nfe_csosn'] ?? ''),
      ], (string)(fiscal_company_config($pdo, $companyId)['crt'] ?? '1'));
      // Código não aplicável ao CRT atual pode ficar vazio, sem aplicar efeito fora da empresa.
      $st = $pdo->prepare('INSERT INTO fiscal_st_nfe_profiles
        (company_id,mva_id,cfop,cst,csosn,updated_at,updated_by) VALUES (?,?,?,?,?,NOW(),?)
        ON DUPLICATE KEY UPDATE cfop=VALUES(cfop),cst=VALUES(cst),csosn=VALUES(csosn),
          updated_at=NOW(),updated_by=VALUES(updated_by)');
      $st->execute([$companyId,$mvaId,$codes['cfop'],$codes['cst'] ?: null,$codes['csosn'] ?: null,(int)(auth_user_id() ?? 0)]);
    } elseif ($action === 'state_rate') {
      if (!$platform) throw new RuntimeException('Somente platform_admin altera alíquotas globais.');
      $uf=strtoupper(trim((string)($_POST['uf']??'')));
      $from=(string)($_POST['valid_from']??'');
      $to=trim((string)($_POST['valid_to']??''));
      $raw=str_replace(',','.',trim((string)($_POST['rate']??'')));
      if (!preg_match('/^[A-Z]{2}$/',$uf)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)
        ||($to!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||$to<$from))) throw new RuntimeException('UF/vigência inválida.');
      if ($raw!=='' && (!is_numeric($raw)||(float)$raw<0||(float)$raw>100)) throw new RuntimeException('Alíquota deve estar entre 0% e 100%.');
      $state=$pdo->prepare('SELECT state_name FROM fiscal_st_state_rates WHERE uf=? LIMIT 1');
      $state->execute([$uf]);
      $stateName=(string)($state->fetchColumn()?:'');
      if ($stateName==='') throw new RuntimeException('UF não cadastrada.');
      $st=$pdo->prepare(
        'INSERT INTO fiscal_st_state_rates (uf,state_name,own_icms_rate,valid_from,valid_to,legal_basis,updated_by,updated_at)
         VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE own_icms_rate=VALUES(own_icms_rate),
         valid_to=VALUES(valid_to),legal_basis=VALUES(legal_basis),updated_by=VALUES(updated_by),updated_at=NOW()'
      );
      $st->execute([$uf,$stateName,$raw===''?null:(float)$raw,$from,$to!==''?$to:null,
        substr(trim((string)($_POST['legal_basis']??'')),0,500),(int)(auth_user_id()??0)]);
    } elseif ($action === 'mva') {
      if (!$platform) throw new RuntimeException('Somente platform_admin altera MVA global.');
      $uf=strtoupper(trim((string)($_POST['uf']??'')));
      $ncm=preg_replace('/\D+/','',(string)($_POST['ncm']??''));
      $cest=preg_replace('/\D+/','',(string)($_POST['cest']??''));
      $from=(string)($_POST['valid_from']??'');
      $to=trim((string)($_POST['valid_to']??''));
      $raw=str_replace(',','.',trim((string)($_POST['mva_rate']??'')));
      if (!preg_match('/^[A-Z]{2}$/',$uf)||strlen($ncm)!==8||($cest!==''&&strlen($cest)!==7)
        ||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)
        ||($to!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||$to<$from))
        ||!is_numeric($raw)||(float)$raw<0||(float)$raw>1000)
        throw new RuntimeException('Informe UF, NCM (8 dígitos), CEST (7 dígitos ou vazio), vigência e MVA válida.');
      $mvaData = [$uf,$ncm,$cest,(float)$raw,$from,$to!==''?$to:null,
        substr(trim((string)($_POST['legal_basis']??'')),0,500),(int)(auth_user_id()??0)];
      $mvaId=(int)($_POST['mva_id']??0);
      if ($mvaId>0) {
        $st=$pdo->prepare('UPDATE fiscal_st_ncm_mva SET uf=?,ncm=?,cest=?,mva_rate=?,valid_from=?,valid_to=?,legal_basis=?,updated_by=?,updated_at=NOW() WHERE id=?');
        $st->execute(array_merge($mvaData, [$mvaId]));
        if ($st->rowCount()===0) {
          $check=$pdo->prepare('SELECT 1 FROM fiscal_st_ncm_mva WHERE id=?');
          $check->execute([$mvaId]);
          if (!$check->fetchColumn()) throw new RuntimeException('Registro de MVA não encontrado para edição.');
        }
      } else {
        $st=$pdo->prepare(
          'INSERT INTO fiscal_st_ncm_mva (uf,ncm,cest,mva_rate,valid_from,valid_to,legal_basis,updated_at,updated_by)
           VALUES (?,?,?,?,?,?,?,NOW(),?)
           ON DUPLICATE KEY UPDATE mva_rate=VALUES(mva_rate),valid_to=VALUES(valid_to),
           legal_basis=VALUES(legal_basis),updated_at=NOW(),updated_by=VALUES(updated_by)'
        );
        $st->execute($mvaData);
      }
    } else throw new RuntimeException('Ação não reconhecida.');
    $back = (string)($_POST['return_to'] ?? '');
    // Retorno local restrito; nunca aceitar URLs ou caminhos arbitrários.
    if ($action === 'mva' && preg_match('/^fiscal_ncms\.php(?:\?q=[a-zA-Z0-9%+._~-]{0,150})?$/D', $back)) {
      header('Location: '.$back.(strpos($back,'?')!==false?'&':'?').'ok='.rawurlencode('MVA salva.')); exit;
    }
    header('Location: fiscal_st_uf.php?ok='.rawurlencode('Configuração salva.')); exit;
  } catch (Throwable $e) { $err = $e->getMessage(); }
}
$states=[]; $mvas=[];
if ($hasSchema) {
  try { $states=$pdo->query('SELECT * FROM fiscal_st_state_rates ORDER BY uf,valid_from DESC')->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch(Throwable $e) {}
  try {
    if ($hasProfileSchema) {
      $st=$pdo->prepare('SELECT m.*, p.cfop AS nfe_cfop, p.cst AS nfe_cst, p.csosn AS nfe_csosn
        FROM fiscal_st_ncm_mva m LEFT JOIN fiscal_st_nfe_profiles p
          ON p.mva_id=m.id AND p.company_id=?
        ORDER BY m.updated_at DESC,m.id DESC LIMIT 300');
      $st->execute([$companyId]);
      $mvas=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $mvas=$pdo->query('SELECT * FROM fiscal_st_ncm_mva ORDER BY updated_at DESC,id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch(Throwable $e) {}
}
$settings=nexa_st_settings($pdo,$companyId);
$h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$today=date('Y-m-d');
fiscal_admin_start('Fiscal — ICMS-ST', 'fiscal_st_uf');
fiscal_page_header('ICMS-ST por destinatário','Cadastro de alíquotas compartilhadas do SaaS, MVA por NCM/UF e ativação controlada na empresa.',[
  ['label'=>'Configurações fiscais','href'=>'fiscal_configuracoes.php#icmsStDestinatario','class'=>'btn'],
  ['label'=>'NCM / IBPT','href'=>'fiscal_ncms.php','class'=>'btn'],
  ['label'=>'Tributações','href'=>'fiscal_tributacoes.php','class'=>'btn'],
]);
?>
<?php if ($err!==''): ?><div class="msg err"><?= $h($err) ?></div><?php endif; ?>
<?php if ($ok!==''): ?><div class="msg ok"><?= $h($ok) ?></div><?php endif; ?>
<?php if ($hasSchema && !$hasProfileSchema): ?><div class="msg err"><b>Migration V3 pendente.</b> Aplique Fiscal — CFOP/CST/CSOSN ST por empresa no Atualizador do Banco. Até aplicar, a NF-e que utiliza a nova regra ficará sem cadastro específico de CFOP/CST/CSOSN.</div><?php endif; ?>
<?php if (!$hasSchema): ?>
<div class="msg err"><b>Migração pendente.</b> É necessário aplicar a atualização do banco antes de usar esta tela. Nenhuma emissão existente será alterada.</div>
<?php else: ?>
<div class="fiscal-card">
  <h2>1. Habilitação por empresa</h2>
  <p class="fiscal-muted">A regra é <b>desligada por padrão</b> e só atua em NF-e (55), para PJ cadastrada como Lucro Presumido ou Lucro Real, com CST/CSOSN de ST compatível, NCM/CEST, MVA e alíquotas válidas. Primeira etapa: somente operações dentro do mesmo estado e ambiente de homologação. NFC-e (65) permanece intacta.</p>
  <form method="post" class="fiscal-form">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_st_csrf']) ?>">
    <input type="hidden" name="action" value="company">
    <label style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
      <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled'])?'checked':'' ?> <?= !$master||$cross?'disabled':'' ?>>
      Aplicar regra ST por destinatário nesta empresa (somente HOMOLOGAÇÃO)
    </label>
    <?php if ($master && !$cross): ?><button class="btn btn-primary" type="submit">Salvar configuração</button><?php endif; ?>
  </form>
</div>
<div class="fiscal-card">
  <div class="fiscal-list-head"><div><h2>2. Alíquotas estaduais de referência</h2><p class="fiscal-muted">Não existe alíquota universal de ICMS por UF para todos os produtos/operações. Cadastre apenas valores validados pelo responsável fiscal; a tabela usa vigências.</p></div></div>
  <?php if ($platform && !$cross): ?>
  <form method="post" class="fiscal-form fiscal-form-grid" style="align-items:end">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_st_csrf']) ?>">
    <input type="hidden" name="action" value="state_rate">
    <div class="row"><label>UF</label><select class="field" name="uf" required><?php foreach ($states as $s): if ((string)$s['valid_from']!=='2026-01-01') continue; ?><option value="<?= $h($s['uf']) ?>"><?= $h($s['uf'].' · '.$s['state_name']) ?></option><?php endforeach; ?></select></div>
    <div class="row"><label>% ICMS próprio de referência</label><input class="field" type="number" step="0.0001" min="0" max="100" name="rate" placeholder="Ex.: 18,0000"></div>
    <div class="row"><label>Início vigência</label><input class="field" type="date" name="valid_from" value="<?= $h($today) ?>" required></div>
    <div class="row"><label>Fim vigência (opcional)</label><input class="field" type="date" name="valid_to"></div>
    <div class="row"><label>Fundamento normativo</label><input class="field" name="legal_basis" maxlength="500" placeholder="Norma validada"></div>
    <div class="row"><button class="btn btn-primary" type="submit">Salvar alíquota</button></div>
  </form>
  <?php endif; ?>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table">
  <thead><tr><th>UF</th><th>Estado</th><th>% ICMS</th><th>Início</th><th>Fim</th><th>Norma</th></tr></thead><tbody>
  <?php foreach ($states as $s): ?><tr>
    <td><?= $h($s['uf']) ?></td><td><?= $h($s['state_name']) ?></td>
    <td><?= $s['own_icms_rate']===null?'<em>Não configurada</em>':$h(number_format((float)$s['own_icms_rate'],4,',','.')).'%' ?></td>
    <td><?= $h($s['valid_from']) ?></td><td><?= $h($s['valid_to']??'') ?></td><td><?= $h($s['legal_basis']??'') ?></td>
  </tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="fiscal-card" id="regrasNfeSt">
  <div class="fiscal-list-head">
    <div><h2>3. MVA por NCM, CEST e UF + códigos da NF-e</h2><p class="fiscal-muted">A MVA é compartilhada no SaaS; CFOP/CST/CSOSN da NF-e são exclusivos da empresa atual (<?= $h($companyId) ?>). A NFC-e permanece com a tributação existente.</p></div>
    <?php if ($platform && !$cross): ?><button class="btn btn-primary" type="button" onclick="const f=document.querySelector('#stMvaModal form');f.reset();f.elements['mva_id'].value='0';document.getElementById('stMvaTitle').textContent='Nova MVA fiscal';document.getElementById('stMvaModal').style.display='flex'">+ Novo</button><?php endif; ?>
  </div>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>UF</th><th>NCM</th><th>CEST</th><th>MVA</th><th>Início</th><th>Fim</th><th>Norma</th><th>CFOP NF-e</th><th>CST NF-e</th><th>CSOSN NF-e</th><th class="fiscal-actions-head">Ações</th></tr></thead><tbody>
  <?php foreach ($mvas as $m): ?><tr>
    <td><?= $h($m['uf']) ?></td><td><?= $h($m['ncm']) ?></td><td><?= $h($m['cest']) ?></td>
    <td><?= $h(number_format((float)$m['mva_rate'],4,',','.')) ?>%</td>
    <td><?= $h($m['valid_from']) ?></td><td><?= $h($m['valid_to']??'') ?></td><td><?= $h($m['legal_basis']??'') ?></td>
    <td><?= $h($m['nfe_cfop']??'—') ?></td><td><?= $h($m['nfe_cst']??'—') ?></td><td><?= $h($m['nfe_csosn']??'—') ?></td>
    <td class="fiscal-actions-col"><?php if ($platform && !$cross): ?>
      <button class="btn btn-sm st-mva-edit" type="button"
        data-id="<?= (int)$m['id'] ?>" data-uf="<?= $h($m['uf']) ?>" data-ncm="<?= $h($m['ncm']) ?>"
        data-cest="<?= $h($m['cest']) ?>" data-rate="<?= $h($m['mva_rate']) ?>"
        data-from="<?= $h($m['valid_from']) ?>" data-to="<?= $h($m['valid_to']??'') ?>"
        data-legal="<?= $h($m['legal_basis']??'') ?>">Editar MVA</button>
    <?php endif; ?>
    <?php if ($master && !$cross && $hasProfileSchema): ?>
      <button class="btn btn-sm st-nfe-edit" type="button"
        data-id="<?= (int)$m['id'] ?>" data-uf="<?= $h($m['uf']) ?>" data-ncm="<?= $h($m['ncm']) ?>"
        data-cest="<?= $h($m['cest']) ?>" data-cfop="<?= $h($m['nfe_cfop']??'') ?>"
        data-cst="<?= $h($m['nfe_cst']??'') ?>" data-csosn="<?= $h($m['nfe_csosn']??'') ?>">Configurar NF-e</button>
    <?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$mvas): ?><tr><td colspan="11" style="text-align:center">Nenhuma MVA cadastrada.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($platform && !$cross): ?>
<div id="stMvaModal" style="display:none;position:fixed;inset:0;background:#0f172a90;z-index:400;align-items:center;justify-content:center;padding:18px">
  <form method="post" class="fiscal-card fiscal-form" style="width:min(630px,95vw);max-height:90vh;overflow:auto">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_st_csrf']) ?>">
    <input type="hidden" name="action" value="mva">
    <input type="hidden" name="mva_id" id="stMvaId" value="0">
    <h2 id="stMvaTitle">Nova MVA fiscal</h2>
    <div class="fiscal-form-grid">
      <div class="row"><label>UF</label><select class="field" name="uf" required><?php foreach ($states as $s): if ((string)$s['valid_from']!=='2026-01-01') continue; ?><option><?= $h($s['uf']) ?></option><?php endforeach; ?></select></div>
      <div class="row"><label>NCM (8 dígitos)</label><input class="field" name="ncm" maxlength="8" required></div>
      <div class="row"><label>CEST (7 dígitos, opcional)</label><input class="field" name="cest" maxlength="7"></div>
      <div class="row"><label>MVA %</label><input class="field" type="number" name="mva_rate" min="0" max="1000" step="0.0001" required></div>
      <div class="row"><label>Início vigência</label><input class="field" type="date" name="valid_from" value="<?= $h($today) ?>" required></div>
      <div class="row"><label>Fim vigência</label><input class="field" type="date" name="valid_to"></div>
      <div class="row span-2"><label>Fundamento normativo</label><input class="field" name="legal_basis" maxlength="500"></div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
      <button type="button" class="btn" onclick="document.getElementById('stMvaModal').style.display='none'">Cancelar</button>
      <button type="submit" class="btn btn-primary">Salvar</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php if ($master && !$cross && $hasProfileSchema): ?>
<div id="stNfeProfileModal" style="display:none;position:fixed;inset:0;background:#0f172a90;z-index:410;align-items:center;justify-content:center;padding:18px" role="dialog" aria-modal="true" aria-labelledby="stNfeTitle">
  <form method="post" class="fiscal-card fiscal-form" style="width:min(650px,95vw);max-height:90vh;overflow:auto">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_st_csrf']) ?>">
    <input type="hidden" name="action" value="nfe_profile">
    <input type="hidden" name="mva_id" value="0">
    <h2 id="stNfeTitle">Tributação NF-e com retenção ST</h2>
    <p class="fiscal-muted">Empresa <?= $h($companyId) ?> · somente NF-e modelo 55, operação interna. Não altera a NFC-e. Confira os códigos com a contabilidade antes de emitir.</p>
    <p id="stNfeNcmInfo"></p>
    <div class="fiscal-form-grid">
      <div class="row"><label>CFOP na NF-e</label><select class="field" name="nfe_cfop" required><option value="">Selecione</option><option value="5401">5401 - Venda de produção própria sujeita à ST</option><option value="5403">5403 - Venda de mercadoria adquirida de terceiros sujeita à ST</option></select></div>
      <div class="row"><label>CST na NF-e (regime normal)</label><select class="field" name="nfe_cst"><option value="">Não aplicável</option><option value="30">30 - Isenta/não tributada com cobrança de ST</option></select></div>
      <div class="row"><label>CSOSN na NF-e (Simples Nacional)</label><select class="field" name="nfe_csosn"><option value="">Não aplicável</option><option value="202">202 - Sem crédito, com cobrança de ST</option><option value="203">203 - Isenção por faixa de receita, com ST</option></select></div>
    </div>
    <p class="fiscal-muted">CST 10, CSOSN 201, CFOP 5405 e operações interestaduais requerem outra regra tributária/XML e não são habilitados nesta etapa. O regime do <strong>emitente</strong> define se a NF-e utiliza CST ou CSOSN.</p>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px"><button type="button" class="btn" id="stNfeCancel">Cancelar</button><button type="submit" class="btn btn-primary">Salvar códigos da NF-e</button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<script>
(function(){
  const modal=document.getElementById('stMvaModal');
  const form=modal && modal.querySelector('form');
  if (modal && form) document.querySelectorAll('.st-mva-edit').forEach(button=>button.addEventListener('click',()=>{
    form.elements['mva_id'].value=button.dataset.id||'0';
    for(const name of ['uf','ncm','cest','mva_rate','valid_from','valid_to','legal_basis']){
      const key=({'mva_rate':'rate','valid_from':'from','valid_to':'to','legal_basis':'legal'})[name]||name;
      if(form.elements[name]) form.elements[name].value=button.dataset[key]||'';
    }
    document.getElementById('stMvaTitle').textContent='Editar MVA fiscal';
    modal.style.display='flex';
    form.elements['mva_rate'].focus();
  }));
  const nfeModal=document.getElementById('stNfeProfileModal');
  const nfeForm=nfeModal && nfeModal.querySelector('form');
  if(nfeModal && nfeForm){
    document.querySelectorAll('.st-nfe-edit').forEach(button=>button.addEventListener('click',()=>{
      nfeForm.elements['mva_id'].value=button.dataset.id;
      nfeForm.elements['nfe_cfop'].value=button.dataset.cfop||'';
      nfeForm.elements['nfe_cst'].value=button.dataset.cst||'';
      nfeForm.elements['nfe_csosn'].value=button.dataset.csosn||'';
      document.getElementById('stNfeNcmInfo').textContent='UF: '+button.dataset.uf+' | NCM: '+button.dataset.ncm+' | CEST: '+(button.dataset.cest||'genérico');
      nfeModal.style.display='flex';
      nfeForm.elements['nfe_cfop'].focus();
    }));
    document.getElementById('stNfeCancel').addEventListener('click',()=>{nfeModal.style.display='none';});
  }
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      if(modal)modal.style.display='none';
      if(nfeModal)nfeModal.style.display='none';
    }
  });
})();
</script>
<?php fiscal_admin_end(); ?>
