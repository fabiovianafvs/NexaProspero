<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/itau_bolecode.php';
require_once __DIR__ . '/../config/itau_cash_query.php';
$billingCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
if (!$billingCrossContext) billing_ensure_schema($pdo);
$cid=function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;$id=(int)($_GET['id']??0);
if($id<=0)finance_redirect('financeiro_boletos.php?err='.rawurlencode('Cobrança inválida.'));
$charge=itau_bolecode_charge_context($pdo,$cid,$id);
$cfg=billing_bank_config($pdo,$cid,(int)$charge['bank_account_id']);
$status=strtoupper((string)($charge['status']??''));
$itauDetailTab=$billingCrossContext?'fin_boletos':'fin_caixas';
finance_admin_start('Financeiro - Dados Itaú',$itauDetailTab);
$itauDetailActions=[['href'=>'financeiro_boletos.php','label'=>'Voltar à fila','class'=>'btn']];
if(!$billingCrossContext)$itauDetailActions[]=['href'=>'financeiro_itau.php?bank='.(int)$charge['bank_account_id'],'label'=>'Configuração Itaú','class'=>'btn'];
finance_page_header('Dados Itaú - Cobrança #'.$id,'Detalhes do Boleto + Pix (BoleCode) e auditoria da integração.',$itauDetailActions);finance_tabs($itauDetailTab);finance_flash();
?>
<style>
.itau-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.itau-detail{border:1px solid #dbe4f0;border-radius:16px;padding:14px;background:#fff}.itau-detail .label{font-size:12px;text-transform:uppercase;color:#64748b;font-weight:800;margin-bottom:5px}.itau-detail .value{font-size:16px;font-weight:700;color:#0f274f;word-break:break-word}.itau-copy{display:flex;gap:8px;align-items:flex-start}.itau-copy textarea{flex:1;min-height:72px}.itau-qr{max-width:260px;width:100%;height:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;padding:10px}.itau-tech{width:100%;min-height:180px;font-family:ui-monospace,monospace;font-size:12px}.itau-warning{padding:12px;border-radius:14px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}@media(max-width:850px){.itau-detail-grid{grid-template-columns:1fr 1fr}}@media(max-width:560px){.itau-detail-grid{grid-template-columns:1fr}}
</style>
<div class="finance-card">
 <div class="finance-card-head"><h2 class="finance-subtitle">Resumo</h2><span class="finance-badge <?=$status==='REGISTRADO'?'green':'gray'?>"><?=finance_h($status)?></span></div>
 <div class="itau-detail-grid">
  <div class="itau-detail"><div class="label">Banco / Conta</div><div class="value"><?=finance_h((string)($charge['bank_account_name']??''))?></div></div>
  <div class="itau-detail"><div class="label">Vencimento</div><div class="value"><?=finance_h(date('d/m/Y',strtotime((string)$charge['due_date'])))?></div></div>
  <div class="itau-detail"><div class="label">Valor</div><div class="value">R$ <?=finance_money_br($charge['amount'])?></div></div>
  <div class="itau-detail"><div class="label">Nosso Número</div><div class="value"><?=finance_h((string)($charge['nosso_numero']?:'—'))?></div></div>
  <div class="itau-detail"><div class="label">UUID boleto</div><div class="value"><?=finance_h((string)($charge['external_id']?:'—'))?></div></div>
  <div class="itau-detail"><div class="label">Correlação</div><div class="value"><?=finance_h((string)($charge['api_correlation_id']?:'—'))?></div></div>
 </div>
</div>
<?php if($status==='VERIFICAR_API'||$status==='PROCESSANDO_API'):?><div class="finance-card"><div class="itau-warning"><strong>Não reenvie automaticamente esta cobrança.</strong> O status indica comunicação incerta ou processamento assíncrono. <?= $billingCrossContext ? 'Confira a cobrança no Itaú pelo Nosso Número; a Conciliação permanece fora do F11 nesta versão.' : 'Consulte a conciliação Itaú pelo Nosso Número antes de qualquer nova tentativa.' ?></div></div><?php endif;?>
<?php if($billingCrossContext && billing_has_column($pdo,'finance_boleto_charges','api_last_check_at')):?><div class="finance-card"><h2 class="finance-subtitle">Auditoria V11.12 · Consulta de situação</h2><div class="itau-detail-grid">
 <div class="itau-detail"><div class="label">Última consulta</div><div class="value"><?=!empty($charge['api_last_check_at'])?finance_h(date('d/m/Y H:i:s',strtotime((string)$charge['api_last_check_at']))):'—'?></div></div>
 <div class="itau-detail"><div class="label">Resultado</div><div class="value"><?=finance_h((string)($charge['api_last_check_result']?:'—'))?></div></div>
 <div class="itau-detail"><div class="label">HTTP</div><div class="value"><?=isset($charge['api_last_check_http_status'])&&$charge['api_last_check_http_status']!==null?(int)$charge['api_last_check_http_status']:'—'?></div></div>
 <div class="itau-detail"><div class="label">Usuário</div><div class="value"><?=!empty($charge['api_last_check_by_user_id'])?'#'.(int)$charge['api_last_check_by_user_id']:'—'?></div></div>
 <div class="itau-detail" style="grid-column:span 2"><div class="label">Correlação</div><div class="value" style="font-size:12px"><?=finance_h((string)($charge['api_last_check_correlation_id']?:'—'))?></div></div>
 </div><?php if(!empty($charge['api_last_check_message'])):?><div class="itau-warning" style="margin-top:12px"><?=finance_h((string)$charge['api_last_check_message'])?></div><?php endif;?><?php if(!empty($charge['api_last_check_response'])):?><details style="margin-top:12px"><summary><strong>Resposta da última consulta</strong></summary><textarea class="itau-tech" readonly><?=finance_h((string)$charge['api_last_check_response'])?></textarea></details><?php endif;?></div><?php endif;?>
<?php if($billingCrossContext && billing_has_column($pdo,'finance_boleto_charges','api_attempt_count')):?><div class="finance-card"><h2 class="finance-subtitle">Auditoria V11.11 · Operação efetiva</h2><div class="itau-detail-grid">
 <div class="itau-detail"><div class="label">Tentativas efetivas</div><div class="value"><?=(int)($charge['api_attempt_count']??0)?></div></div>
 <div class="itau-detail"><div class="label">Última ação</div><div class="value"><?=finance_h((string)($charge['api_last_action']?:'—'))?></div></div>
 <div class="itau-detail"><div class="label">Última tentativa</div><div class="value"><?=!empty($charge['api_last_attempt_at'])?finance_h(date('d/m/Y H:i:s',strtotime((string)$charge['api_last_attempt_at']))):'—'?></div></div>
 <div class="itau-detail"><div class="label">Usuário</div><div class="value"><?=!empty($charge['api_last_attempt_by_user_id'])?'#'.(int)$charge['api_last_attempt_by_user_id']:'—'?></div></div>
 <div class="itau-detail" style="grid-column:span 2"><div class="label">Fingerprint SHA-256 do payload efetivo</div><div class="value" style="font-size:12px"><?=finance_h((string)($charge['api_request_hash']?:'—'))?></div></div>
 </div></div><?php endif;?>
<?php if(!empty($charge['linha_digitavel'])||!empty($charge['barcode'])):?><div class="finance-card"><h2 class="finance-subtitle">Boleto</h2>
 <?php if(!empty($charge['linha_digitavel'])):?><div style="margin-bottom:14px"><label>Linha digitável</label><div class="itau-copy"><textarea id="linhaDigitavel" readonly><?=finance_h((string)$charge['linha_digitavel'])?></textarea><button class="btn" type="button" onclick="copyItau('linhaDigitavel')">Copiar</button></div></div><?php endif;?>
 <?php if(!empty($charge['barcode'])):?><div><label>Código de barras</label><div class="itau-copy"><textarea id="codigoBarras" readonly><?=finance_h((string)$charge['barcode'])?></textarea><button class="btn" type="button" onclick="copyItau('codigoBarras')">Copiar</button></div></div><?php endif;?>
</div><?php endif;?>
<?php if(!empty($charge['pix_txid'])||!empty($charge['pix_emv'])||!empty($charge['pix_qr_base64'])):?><div class="finance-card"><h2 class="finance-subtitle">Pix do BoleCode</h2><div class="finance-form-grid">
 <div>
  <div class="itau-detail" style="margin-bottom:12px"><div class="label">TXID</div><div class="value"><?=finance_h((string)($charge['pix_txid']?:'—'))?></div></div>
  <?php if(!empty($charge['pix_key'])):?><div class="itau-detail" style="margin-bottom:12px"><div class="label">Chave</div><div class="value"><?=finance_h((string)$charge['pix_key'])?></div></div><?php endif;?>
  <?php if(!empty($charge['pix_emv'])):?><label>Pix Copia e Cola</label><div class="itau-copy"><textarea id="pixEmv" readonly><?=finance_h((string)$charge['pix_emv'])?></textarea><button class="btn" type="button" onclick="copyItau('pixEmv')">Copiar Pix</button></div><?php endif;?>
 </div>
 <div><?php if(!empty($charge['pix_qr_base64'])):?><img class="itau-qr" alt="QR Code Pix" src="data:image/png;base64,<?=finance_h((string)$charge['pix_qr_base64'])?>"><?php endif;?><?php if(!empty($charge['pix_location'])):?><p class="finance-muted"><strong>Location:</strong> <?=finance_h((string)$charge['pix_location'])?></p><?php endif;?></div>
</div></div><?php endif;?>
<div class="finance-card"><h2 class="finance-subtitle">Pagador usado na integração</h2><div class="itau-detail-grid">
 <div class="itau-detail"><div class="label">Nome/Razão social</div><div class="value"><?=finance_h((string)($charge['payer_name']?:'—'))?></div></div>
 <div class="itau-detail"><div class="label">Documento</div><div class="value"><?=finance_h((string)($charge['payer_document']?:'—'))?></div></div>
 <div class="itau-detail"><div class="label">Cidade / UF</div><div class="value"><?=finance_h(trim((string)($charge['payer_city']??'')).' / '.trim((string)($charge['payer_state']??'')))?></div></div>
</div></div>
<div class="finance-card"><details><summary><strong>Auditoria técnica</strong></summary>
 <p class="finance-muted">Os dados abaixo são internos e servem para homologação/suporte. O access_token nunca é armazenado.</p>
 <?php if(!empty($charge['simulation_request_payload'])||!empty($charge['simulation_response_payload'])):?><h3>Última simulação</h3><textarea class="itau-tech" readonly><?=finance_h((string)($charge['simulation_request_payload']??''))?>\n\n--- RESPOSTA ---\n<?=finance_h((string)($charge['simulation_response_payload']??''))?></textarea><?php endif;?>
 <h3>Efetivação</h3><textarea class="itau-tech" readonly><?=finance_h((string)($charge['request_payload']??''))?>\n\n--- RESPOSTA ---\n<?=finance_h((string)($charge['response_payload']??''))?></textarea>
 </details></div>
<script>
function copyItau(id){const el=document.getElementById(id);if(!el)return;const value=el.value||el.textContent||'';if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(value);}else{el.focus();el.select();document.execCommand('copy');}}
</script>
<?php finance_admin_end(); ?>
