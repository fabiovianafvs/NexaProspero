<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
fiscal_xml_ensure_schema($pdo);
$err = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['delete_rule']) ? 'delete' : (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Regra inválida para excluir.');
      $pdo->prepare('DELETE FROM fiscal_xml_tax_conversions WHERE company_id = ? AND id = ?')->execute([$cid, $id]);
      header('Location: fiscal_xml_conversoes.php?ok=' . rawurlencode('Regra de conversão excluída.'));
      exit;
    }
    fiscal_xml_save_tax_conversion_from_post($pdo, $cid, $_POST);
    header('Location: fiscal_xml_conversoes.php?ok=' . rawurlencode('Regra de conversão fiscal salva.'));
    exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$taxProfiles = [];
try { $taxProfiles = fiscal_tax_profiles($pdo, $cid, true); } catch (Throwable $e) {}
$rows = fiscal_xml_tax_conversions($pdo, $cid, false);

fiscal_admin_start('Fiscal - Conversões XML', 'fiscal_xml_conversoes');
fiscal_page_header('Conversões fiscais do XML', 'Configure como as tributações recebidas do fornecedor devem ser convertidas para a entrada da nota.', [
  ['label'=>'RADAR XML', 'href'=>'fiscal_radar_xml.php', 'class'=>'btn'],
  ['label'=>'+ Importar XML', 'href'=>'fiscal_importar_xml.php', 'class'=>'btn'],
]);
fiscal_tabs('fiscal_xml_conversoes');
fiscal_flash();
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<style>
.xml-conv-modal{position:fixed;inset:0;z-index:600;display:none;align-items:flex-start;justify-content:center;padding:5vh 18px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);overflow:auto}.xml-conv-modal.open{display:flex}.xml-conv-dialog{width:min(1180px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:22px;box-shadow:0 28px 90px rgba(2,6,23,.35);padding:16px}.xml-conv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:1px solid #e5edf6;padding-bottom:12px;margin-bottom:14px}.xml-conv-head h2{margin:0;color:#0b1f5b}.xml-conv-close{width:42px;height:42px;border:1px solid #dbe4f0;background:#fff;border-radius:14px;font-size:24px;font-weight:900;cursor:pointer}.xml-conv-section{border:1px solid #dbe4f0;background:#f8fafc;border-radius:16px;padding:12px}.xml-conv-section.dest{background:#f0fdf4;border-color:#bbf7d0}.xml-conv-section h3{margin:0 0 9px;color:#0b1f5b}.xml-conv-top{display:grid;grid-template-columns:minmax(0,1fr) 150px;gap:12px}.xml-conv-pair{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.xml-conv-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.xml-conv-grid label,.xml-conv-top label{display:grid;gap:5px;font-weight:850;color:#334155}.xml-conv-grid input,.xml-conv-grid select,.xml-conv-grid textarea,.xml-conv-top input{width:100%;box-sizing:border-box}.xml-conv-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:14px;border-top:1px solid #e5edf6;padding-top:12px}.xml-conv-actions-right{display:flex;gap:10px}.xml-conv-delete{border-color:#fecaca!important;color:#b91c1c!important}.xml-conv-new-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.xml-conv-rule-note{margin-top:7px;font-size:12px;color:#64748b}.xml-conv-list .fiscal-cell-clip{max-width:420px}@media(max-width:800px){.xml-conv-top,.xml-conv-pair,.xml-conv-grid{grid-template-columns:1fr}.xml-conv-actions,.xml-conv-actions-right{flex-direction:column;align-items:stretch}.xml-conv-actions .btn{width:100%}}
</style>

<div class="fiscal-card xml-conv-list">
  <div class="xml-conv-new-row">
    <div>
      <h2 style="margin:0">Regras cadastradas</h2>
      <div class="fiscal-muted">A regra mais específica é aplicada automaticamente na conferência do XML. Campos de origem em branco funcionam como coringa.</div>
    </div>
    <div style="display:flex;gap:10px;align-items:center"><span class="fiscal-list-count"><?= count($rows) ?> regra(s)</span><button class="btn btn-primary" type="button" id="xmlConvNew">+ Nova regra</button></div>
  </div>
  <div class="table-wrap" style="margin-top:12px"><table class="fiscal-table fiscal-grid-table"><thead><tr><th>Regra</th><th>Quando vier no XML</th><th>Converter para entrada</th><th>Tributação</th><th>Status</th><th class="fiscal-actions-head">Ações</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:#64748b">Nenhuma regra cadastrada. Clique em <b>Nova regra</b> para cadastrar.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $input = 'CFOP ' . (($r['input_cfop'] ?? '') ?: 'qualquer') . ' • ICMS ' . (($r['input_icms_cst'] ?? '') ?: (($r['input_icms_csosn'] ?? '') ?: 'qualquer')) . ' • IPI ' . (($r['input_ipi_cst'] ?? '') ?: 'qualquer') . ' • PIS ' . (($r['input_pis_cst'] ?? '') ?: 'qualquer') . ' • COFINS ' . (($r['input_cofins_cst'] ?? '') ?: 'qualquer');
        $output = 'CFOP ' . (($r['output_cfop'] ?? '') ?: 'manter') . ' • ICMS ' . (($r['output_icms_cst'] ?? '') ?: (($r['output_icms_csosn'] ?? '') ?: 'manter')) . ' • IPI ' . (($r['output_ipi_cst'] ?? '') ?: 'manter') . ' • PIS ' . (($r['output_pis_cst'] ?? '') ?: 'manter') . ' • COFINS ' . (($r['output_cofins_cst'] ?? '') ?: 'manter');
        $tpName = trim((string)($r['tax_profile_code'] ?? '') . ' - ' . (string)($r['tax_profile_name'] ?? ''), ' -');
        $ruleJson = json_encode([
          'id'=>(int)($r['id']??0),'name'=>(string)($r['name']??''),'is_active'=>(int)($r['is_active']??0),
          'input_cfop'=>(string)($r['input_cfop']??''),'input_icms_cst'=>(string)($r['input_icms_cst']??''),'input_icms_csosn'=>(string)($r['input_icms_csosn']??''),'input_ipi_cst'=>(string)($r['input_ipi_cst']??''),'input_pis_cst'=>(string)($r['input_pis_cst']??''),'input_cofins_cst'=>(string)($r['input_cofins_cst']??''),
          'output_cfop'=>(string)($r['output_cfop']??''),'output_icms_cst'=>(string)($r['output_icms_cst']??''),'output_icms_csosn'=>(string)($r['output_icms_csosn']??''),'output_ipi_cst'=>(string)($r['output_ipi_cst']??''),'output_pis_cst'=>(string)($r['output_pis_cst']??''),'output_cofins_cst'=>(string)($r['output_cofins_cst']??''),
          'tax_profile_id'=>(string)($r['tax_profile_id']??''),'notes'=>(string)($r['notes']??'')
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      ?>
      <tr>
        <td data-label="Regra" class="primary"><strong><?= fiscal_h($r['name'] ?? '') ?></strong><?php if (!empty($r['notes'])): ?><div class="fiscal-muted fiscal-cell-clip"><?= fiscal_h($r['notes']) ?></div><?php endif; ?></td>
        <td data-label="Quando vier"><span class="fiscal-cell-clip"><?= fiscal_h($input) ?></span></td>
        <td data-label="Converter"><span class="fiscal-cell-clip"><?= fiscal_h($output) ?></span></td>
        <td data-label="Tributação"><?= fiscal_h($tpName ?: '—') ?></td>
        <td data-label="Status"><span class="fiscal-badge <?= !empty($r['is_active']) ? '' : 'off' ?>"><?= !empty($r['is_active']) ? 'Ativa' : 'Inativa' ?></span></td>
        <td data-label="Ações" class="fiscal-actions-col"><button class="btn btn-sm xml-conv-edit" type="button" data-rule="<?= fiscal_h($ruleJson ?: '{}') ?>">Editar</button></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="xml-conv-modal" id="xmlConvModal" aria-hidden="true">
  <form method="post" class="xml-conv-dialog fiscal-form" id="xmlConvForm">
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="convId" value="0">
    <div class="xml-conv-head"><div><h2 id="xmlConvTitle">Nova regra de conversão</h2><div class="fiscal-muted">Revise a origem do XML e informe como a tributação deve entrar no Nexa.</div></div><button type="button" class="xml-conv-close" id="xmlConvClose">×</button></div>
    <div class="xml-conv-top"><label>Nome da regra *<input name="name" id="convName" required maxlength="120" placeholder="Ex.: Compra ST - Bebidas"></label><label>Status<label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" name="is_active" id="convActive" value="1" style="width:auto;min-height:0"> Ativa</label></label></div>
    <div class="xml-conv-pair">
      <div class="xml-conv-section"><h3>Quando vier no XML do fornecedor</h3><div class="xml-conv-grid">
        <label>CFOP XML<input name="input_cfop" id="convInputCfop" maxlength="4" inputmode="numeric"></label><label>CST ICMS XML<input name="input_icms_cst" id="convInputIcmsCst" maxlength="5"></label><label>CSOSN XML<input name="input_icms_csosn" id="convInputIcmsCsosn" maxlength="5"></label>
        <label>CST IPI XML<input name="input_ipi_cst" id="convInputIpiCst" maxlength="5"></label><label>CST PIS XML<input name="input_pis_cst" id="convInputPisCst" maxlength="5"></label><label>CST COFINS XML<input name="input_cofins_cst" id="convInputCofinsCst" maxlength="5"></label>
      </div><div class="xml-conv-rule-note">Campos em branco funcionam como coringa. Quanto mais campos preenchidos, mais específica será a regra.</div></div>
      <div class="xml-conv-section dest"><h3>Converter para entrada da nota</h3><div class="xml-conv-grid">
        <label>CFOP entrada<input name="output_cfop" id="convOutputCfop" maxlength="4" inputmode="numeric"></label><label>CST ICMS entrada<input name="output_icms_cst" id="convOutputIcmsCst" maxlength="5"></label><label>CSOSN entrada<input name="output_icms_csosn" id="convOutputIcmsCsosn" maxlength="5"></label>
        <label>CST IPI entrada<input name="output_ipi_cst" id="convOutputIpiCst" maxlength="5"></label><label>CST PIS entrada<input name="output_pis_cst" id="convOutputPisCst" maxlength="5"></label><label>CST COFINS entrada<input name="output_cofins_cst" id="convOutputCofinsCst" maxlength="5"></label>
      </div></div>
    </div>
    <div class="fiscal-form-grid" style="margin-top:12px"><div class="span-2"><label>Tributação sugerida no cadastro do produto</label><select name="tax_profile_id" id="convTaxProfile"><option value="">Não selecionar automaticamente</option><?php foreach ($taxProfiles as $tp): ?><option value="<?= (int)$tp['id'] ?>"><?= fiscal_h(trim((string)($tp['code'] ?? '') . ' - ' . (string)($tp['name'] ?? ''), ' -')) ?></option><?php endforeach; ?></select></div><div class="span-3"><label>Observações</label><textarea name="notes" id="convNotes" rows="2"></textarea></div></div>
    <div class="xml-conv-actions"><button class="btn xml-conv-delete" type="submit" name="delete_rule" id="convDelete" value="1">Excluir regra</button><div class="xml-conv-actions-right"><button class="btn" type="button" id="xmlConvCancel">Cancelar</button><button class="btn btn-primary" type="submit">Salvar regra</button></div></div>
  </form>
</div>
<script>
(function(){
  const modal=document.getElementById('xmlConvModal'), form=document.getElementById('xmlConvForm');
  const map={id:'convId',name:'convName',input_cfop:'convInputCfop',input_icms_cst:'convInputIcmsCst',input_icms_csosn:'convInputIcmsCsosn',input_ipi_cst:'convInputIpiCst',input_pis_cst:'convInputPisCst',input_cofins_cst:'convInputCofinsCst',output_cfop:'convOutputCfop',output_icms_cst:'convOutputIcmsCst',output_icms_csosn:'convOutputIcmsCsosn',output_ipi_cst:'convOutputIpiCst',output_pis_cst:'convOutputPisCst',output_cofins_cst:'convOutputCofinsCst',tax_profile_id:'convTaxProfile',notes:'convNotes'};
  function set(id,v){const e=document.getElementById(id);if(e)e.value=v==null?'':String(v)}
  function open(rule){rule=rule||{};form.reset();Object.keys(map).forEach(k=>set(map[k],rule[k]??''));set('convId',rule.id||0);document.getElementById('convActive').checked=rule.id?Number(rule.is_active||0)===1:true;document.getElementById('xmlConvTitle').textContent=rule.id?'Editar regra de conversão':'Nova regra de conversão';document.getElementById('convDelete').style.display=rule.id?'inline-flex':'none';modal.classList.add('open');modal.setAttribute('aria-hidden','false');setTimeout(()=>document.getElementById('convName').focus(),50)}
  function close(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true')}
  document.getElementById('xmlConvNew').addEventListener('click',()=>open({is_active:1}));document.querySelectorAll('.xml-conv-edit').forEach(btn=>btn.addEventListener('click',()=>{try{open(JSON.parse(btn.dataset.rule||'{}'))}catch(e){open({})}}));
  document.getElementById('xmlConvClose').addEventListener('click',close);document.getElementById('xmlConvCancel').addEventListener('click',close);modal.addEventListener('click',e=>{if(e.target===modal)close()});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open')){e.preventDefault();close()}});
  document.getElementById('convDelete').addEventListener('click',e=>{if(!confirm('Excluir esta regra de conversão?'))e.preventDefault()});
})();
</script>
<?php fiscal_admin_end(); ?>
