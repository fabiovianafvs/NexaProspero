<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_billing.php';
saas_billing_ensure_schema($pdo);
$msg='';$erro='';$result=null;$diagnostic=[];
$current=pagbank_get_config($pdo,false)?:pagbank_normalize_config([]);
$defaultWebhook=current_scheme().'://'.current_host_name().'/webhooks/pagbank.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $action=(string)($_POST['action']??'');
  if($action==='save_config'){
   pagbank_save_config($pdo,['environment'=>$_POST['environment']??'sandbox','token'=>$_POST['token']??'','api_base_url'=>$_POST['api_base_url']??'','webhook_url'=>$_POST['webhook_url']??$defaultWebhook,'webhook_token'=>$_POST['webhook_token']??'','pix_expiration_minutes'=>$_POST['pix_expiration_minutes']??1440,'max_installments'=>$_POST['max_installments']??12,'soft_descriptor'=>$_POST['soft_descriptor']??'NEXAPROSPERO','pix_enabled'=>isset($_POST['pix_enabled']),'card_enabled'=>isset($_POST['card_enabled']),'active'=>isset($_POST['active'])]);
   $current=pagbank_get_config($pdo,false)?:$current;$msg='Configuração salva com sucesso.';
  }elseif($action==='test'){
   $amount=(float)str_replace(',','.',(string)($_POST['amount']??'1.00'));
   $company=['name'=>trim((string)($_POST['name']??'Cliente Teste Nexa')),'email'=>trim((string)($_POST['email']??'')),'tax_id'=>trim((string)($_POST['tax_id']??'')),'phone'=>trim((string)($_POST['phone']??'')),'id'=>0,'slug'=>'teste'];
   $plan=['id'=>0,'name'=>'Cobrança de teste Nexa','price'=>$amount,'billing_days'=>30];
   $cfg=pagbank_require_config($pdo);$reference='TESTE'.date('YmdHis').strtoupper(bin2hex(random_bytes(2)));
   $method=(string)($_POST['method']??'pix');
   $result=$method==='card'?pagbank_create_card_checkout($cfg,$company,$plan,$reference,saas_pagbank_webhook_url($pdo),current_scheme().'://'.current_host_name().'/saas/pagbank.php','Teste de integração'):pagbank_create_pix_order($cfg,$company,$plan,$reference,saas_pagbank_webhook_url($pdo),'Teste de integração');
   $msg='Cobrança de teste criada com sucesso.';$diagnostic=pagbank_get_last_diagnostic();
  }
 }catch(Throwable $e){$erro=$e->getMessage();$diagnostic=pagbank_get_last_diagnostic();}
}
$webhook=(string)($current['webhook_url']??$defaultWebhook);if(!empty($current['webhook_token']))$webhook.=(strpos($webhook,'?')!==false?'&':'?').'token='.rawurlencode((string)$current['webhook_token']);
saas_layout_start('PagBank','PagBank • Gateway profissional','Pix, cartão, webhooks, diagnóstico e logs da integração.'); ?>
<div class="top-actions"><a class="btn2 btn2-ghost" href="/saas/dashboard.php">Voltar</a><a class="btn2 btn2-ghost" href="/saas/nova_cobranca.php">Nova cobrança</a><a class="btn2 btn2-ghost" href="/saas/pagbank_logs.php">Logs da API</a></div>
<?php if($msg):?><div class="alert-ok"><?=e($msg)?></div><?php endif;?><?php if($erro):?><div class="alert-err"><?=e($erro)?></div><?php endif;?>
<div class="grid-2" style="align-items:start">
<div class="panel"><h3>Credenciais e operação</h3><form method="post" class="form-grid"><input type="hidden" name="action" value="save_config">
<label>Ambiente</label><select class="field" id="pagbankEnvironment" name="environment"><option value="sandbox" <?=($current['environment']??'')==='sandbox'?'selected':''?>>Sandbox</option><option value="production" <?=($current['environment']??'')==='production'?'selected':''?>>Produção</option></select>
<label>Token</label><input class="field" type="password" name="token" value="<?=e((string)($current['token']??''))?>" required>
<label>URL base</label><input class="field" id="pagbankApiBase" name="api_base_url" value="<?=e((string)($current['api_base_url']??''))?>" readonly>
<label>Expiração Pix (minutos)</label><input class="field" type="number" min="5" name="pix_expiration_minutes" value="<?=e((string)($current['pix_expiration_minutes']??1440))?>">
<label>Máximo de parcelas</label><input class="field" type="number" min="1" max="18" name="max_installments" value="<?=e((string)($current['max_installments']??12))?>">
<label>Nome na fatura</label><input class="field" maxlength="17" name="soft_descriptor" value="<?=e((string)($current['soft_descriptor']??'NEXAPROSPERO'))?>">
<label>Webhook</label><input class="field" name="webhook_url" value="<?=e((string)($current['webhook_url']??$defaultWebhook))?>">
<label>Token interno do webhook</label><input class="field" name="webhook_token" value="<?=e((string)($current['webhook_token']??''))?>">
<label><input type="checkbox" name="pix_enabled" <?=!empty($current['pix_enabled'])?'checked':''?>> Habilitar Pix</label><label><input type="checkbox" name="card_enabled" <?=!empty($current['card_enabled'])?'checked':''?>> Habilitar cartão</label><label><input type="checkbox" name="active" <?=!empty($current['active'])?'checked':''?>> Integração ativa</label><div><button class="btn2 btn2-dark">Salvar integração</button></div></form></div>
<div class="panel"><h3>Saúde da integração</h3><p><strong>Status:</strong> <?=!empty($current['active'])?'Ativa':'Inativa'?></p><p><strong>Ambiente:</strong> <?=e((string)($current['environment']??'sandbox'))?></p><p><strong>Token:</strong> <?=e(pagbank_mask_secret((string)($current['token']??'')))?></p><p><strong>Webhook público:</strong></p><code class="box"><?=e($webhook)?></code><div class="warn" style="margin-top:14px">Para o Pix aceitar pagamento, a conta PagBank vinculada ao token precisa ter ao menos uma chave Pix ativa.</div></div>
</div>
<div class="panel" style="margin-top:18px"><h3>Teste técnico de cobrança</h3><?php if(($current['environment']??'sandbox')==='sandbox'):?><div class="warn" style="margin-bottom:14px"><strong>Ambiente Sandbox:</strong> o Pix é uma simulação e não deve ser pago pelo aplicativo bancário real. O portal do Sandbox pode marcar a transação como paga durante os testes, sem movimentação financeira.</div><?php endif;?><form method="post" class="form-grid"><input type="hidden" name="action" value="test"><label>Forma</label><select class="field" name="method"><option value="pix">Pix</option><option value="card">Cartão</option></select><label>Valor</label><input class="field" name="amount" type="number" step="0.01" min="1" value="1.00"><label>Nome completo</label><input class="field" name="name" value="<?=e((string)($_POST['name']??'Cliente Teste Nexa'))?>"><label>E-mail</label><input class="field" name="email" type="email" value="<?=e((string)($_POST['email']??''))?>" required><label>CPF/CNPJ</label><input class="field" name="tax_id" value="<?=e((string)($_POST['tax_id']??''))?>" required><label>Celular</label><input class="field" name="phone" value="<?=e((string)($_POST['phone']??''))?>"><div><button class="btn2 btn2-dark">Gerar teste</button></div></form>
<?php if(is_array($result)):?><div class="alert-ok" style="margin-top:16px"><strong>ID:</strong> <?=e((string)($result['id']??''))?><br><strong>Referência:</strong> <?=e((string)($result['reference_id']??''))?></div>
<?php if(!empty($result['qr_image'])):?><div style="margin:18px 0;text-align:center"><img src="data:image/png;base64,<?=e((string)$result['qr_image'])?>" alt="QR Code Pix" style="display:inline-block;max-width:320px;width:100%;height:auto;background:#fff;padding:14px;border:1px solid #dbe4f0;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.08)"></div><?php endif;?>
<?php if(!empty($result['copy_paste'])):?><label>Pix copia e cola</label><textarea id="pixCode" class="field" rows="6" readonly><?=e((string)$result['copy_paste'])?></textarea><button class="btn2 btn2-ghost" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pixCode').value).then(function(){alert('Código Pix copiado.');})">Copiar Pix</button><?php endif;?>
<?php if(!empty($result['pay_url'])):?><p><a class="btn2 btn2-dark" target="_blank" rel="noopener" href="<?=e((string)$result['pay_url'])?>">Abrir checkout</a></p><?php endif;?><?php endif;?>
<?php if($diagnostic):?><details open style="margin-top:18px"><summary><strong>Diagnóstico da última requisição</strong></summary><p>Método: <?=e((string)($diagnostic['method']??''))?> | HTTP: <?=e((string)($diagnostic['http_status']??0))?> | Tempo: <?=e((string)($diagnostic['duration_ms']??0))?> ms</p><p>Endpoint: <?=e((string)($diagnostic['url']??''))?></p><label>JSON enviado</label><textarea class="field" rows="12" readonly><?=e((string)($diagnostic['request_json']??''))?></textarea><label>Resposta</label><textarea class="field" rows="12" readonly><?=e(!empty($diagnostic['response_json'])?(string)json_encode($diagnostic['response_json'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT):(string)($diagnostic['response_raw']??''))?></textarea></details><?php endif;?></div>
<script>
(function(){
 const env=document.getElementById('pagbankEnvironment');
 const base=document.getElementById('pagbankApiBase');
 if(!env||!base)return;
 const sync=()=>{base.value=env.value==='production'?'https://api.pagseguro.com':'https://sandbox.api.pagseguro.com';};
 env.addEventListener('change',sync);
 sync();
})();
</script>
<?php saas_layout_end(); ?>
