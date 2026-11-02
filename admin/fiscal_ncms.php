<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
fiscal_ensure_schema($pdo);
$q=trim((string)($_GET['q']??''));
$items=fiscal_search_ncms($pdo,(int)COMPANY_ID,$q,500);
$uf=function_exists('fiscal_company_ibpt_uf')?fiscal_company_ibpt_uf($pdo,(int)COMPANY_ID):'';
$stPlatform=function_exists('auth_role') && auth_role()==='platform_admin';
$stCross=defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID===true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
$stSchemaReady=false;
try {$stSchemaReady=(bool)$pdo->query("SHOW TABLES LIKE 'fiscal_st_ncm_mva'")->fetchColumn();}catch(Throwable $e){}
$stMap=[];
if($stSchemaReady && $uf!=='' && $items){
  // Alíquota com CEST exato tem prioridade sobre MVA genérica (CEST vazio).
  $codes=array_values(array_unique(array_filter(array_map(static fn($r)=>(string)($r['ncm']??''),$items))));
  if($codes){
    $ph=implode(',',array_fill(0,count($codes),'?'));
    $date=date('Y-m-d');
    try{
      $stmt=$pdo->prepare("SELECT * FROM fiscal_st_ncm_mva WHERE uf=? AND ncm IN ($ph)
        AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
        ORDER BY valid_from DESC,id DESC");
      $stmt->execute(array_merge([$uf],$codes,[$date,$date]));
      foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $rate){
        $key=(string)$rate['ncm'].'|'.(string)$rate['cest'];
        if(!isset($stMap[$key]))$stMap[$key]=$rate;
      }
    }catch(Throwable $e){}
  }
}
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
if(empty($_SESSION['nexa_st_csrf']))$_SESSION['nexa_st_csrf']=bin2hex(random_bytes(24));
$stReturn='fiscal_ncms.php'.($q!==''?'?q='.rawurlencode(substr($q,0,60)):'');
fiscal_admin_start('Fiscal - NCM / IBPT','fiscal_ncms');
fiscal_page_header('NCM / IBPT global','Consulte o catálogo NCM compartilhado e a tabela IBPT vigente da UF da empresa.',[
  ['label'=>'Tributações da empresa','href'=>'fiscal_tributacoes.php','class'=>'btn btn-primary'],
  ['label'=>'Atualização global','href'=>'fiscal_importacoes.php','class'=>'btn'],
  ['label'=>'ICMS-ST por UF / MVA + CFOP da NF-e','href'=>'fiscal_st_uf.php#regrasNfeSt','class'=>'btn'],
]);
fiscal_tabs('fiscal_ncms');
?>
<div class="msg ok"><b>Catálogo NCM/IBPT em modo consulta.</b> Os percentuais IBPT não são alterados. O cadastro de MVA é mantido separadamente por NCM + CEST + UF + vigência. UF atual: <b><?= fiscal_h($uf!==''?$uf:'não configurada') ?></b>.</div>
<?php if (isset($_GET['ok'])): ?><div class="msg ok"><?= fiscal_h((string)$_GET['ok']) ?></div><?php endif; ?>
<?php if (!$stSchemaReady): ?><div class="msg err">A edição de MVA depende da migration fiscal_st_cliente_v1 no Atualizador do Banco.</div><?php endif; ?>
<div class="fiscal-card"><form method="get" class="fiscal-form fiscal-form-grid" style="align-items:end">
  <div class="row span-2"><label>Pesquisar</label><input class="field" name="q" value="<?= fiscal_h($q) ?>" placeholder="NCM ou descrição"></div>
  <div class="row fiscal-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="fiscal_ncms.php">Limpar</a></div>
</form></div>
<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Catálogo global</h2><span class="fiscal-list-count"><?= count($items) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>NCM</th><th>Descrição</th><th>IBPT federal</th><th>Estadual</th><th>Municipal</th><th>Versão/Vigência</th><th>MVA (<?= fiscal_h($uf?:'UF') ?>)</th><th class="fiscal-actions-head">Ações</th></tr></thead><tbody>
  <?php foreach($items as $r):
    $ncmDigits=preg_replace('/\D+/','',(string)($r['ncm']??''));
    $cestDigits=preg_replace('/\D+/','',(string)($r['cest']??''));
    $stExact=$stMap[$ncmDigits.'|'.$cestDigits]??null;
    $stRow=$stExact??$stMap[$ncmDigits.'|']??null;
    $stEditing=$stExact??($cestDigits===''?$stRow:null);
  ?>
    <tr>
      <td data-label="NCM"><strong><?= fiscal_h($r['ncm']??'') ?></strong></td>
      <td data-label="Descrição" class="primary"><?= fiscal_h($r['description']??'') ?></td>
      <td data-label="IBPT federal">Nac. <?= fiscal_h($r['ibpt_federal_national']??'0') ?>% · Imp. <?= fiscal_h($r['ibpt_federal_imported']??'0') ?>%</td>
      <td data-label="Estadual"><?= fiscal_h($r['ibpt_state']??'0') ?>%</td>
      <td data-label="Municipal"><?= fiscal_h($r['ibpt_municipal']??'0') ?>%</td>
      <td data-label="Versão/Vigência"><?= fiscal_h($r['ibpt_version']??'') ?><br><small><?= fiscal_h($r['validity_start']??'') ?> a <?= fiscal_h($r['validity_end']??'') ?></small></td>
      <td data-label="MVA (UF)"><?= $stRow ? fiscal_h(number_format((float)$stRow['mva_rate'],4,',','.')).'%' . ($stEditing ? '' : ' <small>(genérica)</small>') : '<span class="fiscal-muted">Não cadastrada</span>' ?></td>
      <td data-label="Ações" class="fiscal-actions-col">
        <?php if ($stPlatform && !$stCross && $stSchemaReady && strlen($ncmDigits)===8 && $uf!==''): ?>
          <button type="button" class="btn btn-sm st-edit-from-ncm"
            data-id="<?= (int)($stEditing['id']??0) ?>" data-uf="<?= fiscal_h($uf) ?>"
            data-ncm="<?= fiscal_h($ncmDigits) ?>" data-cest="<?= fiscal_h($stEditing['cest']??$cestDigits) ?>"
            data-mva="<?= fiscal_h($stRow['mva_rate']??'') ?>"
            data-from="<?= fiscal_h($stEditing['valid_from']??date('Y-m-d')) ?>"
            data-to="<?= fiscal_h($stEditing['valid_to']??'') ?>" data-legal="<?= fiscal_h($stEditing['legal_basis']??'') ?>">Editar MVA</button>
        <?php else: ?><span class="fiscal-muted"><?= $stPlatform && !$stSchemaReady ? 'Migração pendente' : 'Consulta' ?></span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if(!$items): ?><tr><td colspan="8" style="text-align:center;color:#64748b">Nenhum NCM encontrado no catálogo global.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($stPlatform && !$stCross && $stSchemaReady && $uf!==''): ?>
<div id="stNcmMvaModal" class="fiscal-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="stNcmMvaTitle">
  <form method="post" action="fiscal_st_uf.php" class="fiscal-modal-dialog fiscal-form" style="width:min(650px,100%);margin:auto">
    <div class="fiscal-modal-head"><div><h2 id="stNcmMvaTitle">Editar MVA por NCM</h2><p class="fiscal-muted">Altera apenas a tabela global de MVA (não altera IBPT).</p></div><button type="button" class="fiscal-modal-close st-close-mva" aria-label="Fechar">×</button></div>
    <input type="hidden" name="csrf" value="<?= fiscal_h($_SESSION['nexa_st_csrf']) ?>">
    <input type="hidden" name="action" value="mva">
    <input type="hidden" name="mva_id" value="0">
    <input type="hidden" name="return_to" value="<?= fiscal_h($stReturn) ?>">
    <div class="fiscal-form-grid">
      <div><label>UF</label><input name="uf" maxlength="2" value="<?= fiscal_h($uf) ?>" readonly required></div>
      <div><label>NCM (8 dígitos)</label><input name="ncm" maxlength="8" readonly required></div>
      <div><label>CEST (7 dígitos, opcional)</label><input name="cest" maxlength="7" inputmode="numeric" placeholder="Quando aplicável"></div>
      <div><label>MVA (%)</label><input name="mva_rate" type="number" min="0" max="1000" step="0.0001" required></div>
      <div><label>Início da vigência</label><input name="valid_from" type="date" required></div>
      <div><label>Fim da vigência</label><input name="valid_to" type="date"></div>
      <div class="span-3"><label>Fundamento normativo</label><input name="legal_basis" maxlength="500" placeholder="Norma fiscal conferida pelo contador"></div>
    </div>
    <p class="fiscal-muted">MVA é específica da mercadoria/UF/operação. Confira a legislação e a vigência antes de salvar. Edição de registro em vigor afeta cálculos futuros de todas as empresas do SaaS.</p>
    <div class="fiscal-form-actions"><button type="button" class="btn st-close-mva">Cancelar</button><button type="submit" class="btn btn-primary">Salvar MVA</button></div>
  </form>
</div>
<script>
(function(){
  const modal=document.getElementById('stNcmMvaModal'), form=modal && modal.querySelector('form');
  if(!modal || !form)return;
  function close(){modal.style.display='none';}
  document.querySelectorAll('.st-close-mva').forEach(btn=>btn.addEventListener('click',close));
  document.querySelectorAll('.st-edit-from-ncm').forEach(btn=>btn.addEventListener('click',()=>{
    const d=btn.dataset;
    form.elements['mva_id'].value=d.id||'0';
    form.elements['uf'].value=d.uf||'';
    form.elements['ncm'].value=d.ncm||'';
    form.elements['cest'].value=d.cest||'';
    form.elements['mva_rate'].value=d.mva||'';
    form.elements['valid_from'].value=d.from||'';
    form.elements['valid_to'].value=d.to||'';
    form.elements['legal_basis'].value=d.legal||'';
    document.getElementById('stNcmMvaTitle').textContent=(Number(d.id)>0?'Editar':'Cadastrar')+' MVA do NCM '+(d.ncm||'');
    modal.style.display='flex';
    form.elements['mva_rate'].focus();
  }));
  modal.addEventListener('click',e=>{if(e.target===modal)close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
})();
</script>
<?php endif; ?>
<?php fiscal_admin_end(); ?>
