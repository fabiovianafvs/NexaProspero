<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/saas_renewal_portal.php';

$companyId=(int)COMPANY_ID;
$role=auth_role();$userId=(int)(auth_user_id()??0);
if (!$userId || (auth_company_id()!==$companyId && $role!=='platform_admin')) {
    header('Location: login.php',true,302);exit;
}
if (!in_array($role,['company_admin','company_user','platform_admin'],true)) {
    http_response_code(403);exit('Acesso não autorizado.');
}
$canPay=in_array($role,['company_admin','platform_admin'],true);
$csrf=$_SESSION['nexa_saas_renew_csrf']??'';
if (!is_string($csrf)||strlen($csrf)<32){$csrf=bin2hex(random_bytes(24));$_SESSION['nexa_saas_renew_csrf']=$csrf;}

$message='';$error='';$renewed=false;
// Recupera o vinculo do trial apenas na visita autenticada ao portal;
// nao executa varreduras na VPS nem gera lancamentos neste GET.
try {
    if ($canPay && saas_sub_bridge_enabled($pdo)) {
        $recent=saas_sub_bridge_latest_subscription($pdo,$companyId);
        if ($recent && strtolower((string)($recent['status']??''))==='trial' && !saas_sub_bridge_link($pdo,$companyId)) {
            $retry=saas_sub_bridge_sync_signup_safe($pdo,$companyId);
            if (empty($retry['ok'])) error_log('[SaaS renewal] Trial sem vinculo: '.(string)($retry['message']??''));
        }
    }
} catch (Throwable $e) {error_log('[SaaS renewal] Falha ao recuperar vinculo: '.$e->getMessage());}
try {$renewed=saas_renewal_reconcile($pdo,$companyId);} catch(Throwable $e){error_log('[SaaS renewal] Falha conciliação: '.$e->getMessage());$error='Não foi possível concluir a regularização. A cobrança existente foi preservada; solicite suporte.';}
if ($renewed) {header('Location: dashboard.php?assinatura=regularizada',true,302);exit;}
$blocked=saas_company_is_blocked($pdo,$company);
// Ao abrir a tela (aviso de vencimento), gera a cobrança da renovação sozinha,
// com boleto/Pix vencendo na data do contrato. Uma tentativa por sessão/dia.
$autoCharge=['generated'=>false,'message'=>''];
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET' && !isset($_GET['baixar_boleto'])) {
    $autoCharge=saas_renewal_auto_charge($pdo,$companyId,$userId);
}
// Portal acessível durante aviso, no último dia, na carência e no bloqueio.
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if (!$canPay) throw new RuntimeException('Somente o administrador da empresa pode confirmar a cobrança.');
        if (!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Atualize a página.');
        $action=(string)($_POST['action']??'');
        if (!in_array($action,['gerar','registrar_boleto'],true)) throw new RuntimeException('Ação não reconhecida.');
        // O vencimento bancário é calculado exclusivamente no servidor:
        // data de vencimento do contrato, ajustada a dia útil bancário.
        $previewContext=saas_renewal_context($pdo,$companyId);
        saas_renewal_validate_boleto_due(
            saas_renewal_bank_due((string)($previewContext['due_date']??'')), (string)($previewContext['cycle_due_date']??'')
        );
        if ($action==='gerar') {
            $termInput=filter_input(INPUT_POST,'renewal_term_months',FILTER_VALIDATE_INT);
            $termMonths=is_int($termInput)?$termInput:null;
            $oldDdl=$GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']??null;
            $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=true;
            try { $result=saas_renewal_generate($pdo,$companyId,$userId,$termMonths); }
            finally { if ($oldDdl===null) unset($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']); else $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=$oldDdl; }
            // Mesmo que o contrato não tenha registro automático habilitado,
            // tentar entregar a cobrança bancária ao confirmar a contratação.
            try { $bankResult=saas_renewal_register_existing_boleto($pdo,$companyId,$userId); }
            catch(Throwable $bankError) {
                error_log('[Nexa][SaaS portal] Pedido gerado, boleto pendente: '.$bankError->getMessage());
                $bankResult=['ok'=>false,'message'=>'O pedido foi gerado, mas a emissão bancária não foi concluída: '.$bankError->getMessage()];
            }
            $_SESSION['nexa_saas_renew_flash']=trim((string)($result['message']??'Cobrança processada.').' '.(string)($bankResult['message']??''));
        } else {
            $result=saas_renewal_register_existing_boleto($pdo,$companyId,$userId);
            $_SESSION['nexa_saas_renew_flash']=(string)($result['message']??'Cobrança consultada.');
        }
        header('Location: assinatura_regularizar.php',true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
if (!empty($_SESSION['nexa_saas_renew_flash'])) {$message=(string)$_SESSION['nexa_saas_renew_flash'];unset($_SESSION['nexa_saas_renew_flash']);}
if ($message==='' && !empty($autoCharge['message'])) $message=(string)$autoCharge['message'];
try{$ctx=saas_renewal_context($pdo,$companyId);}catch(Throwable $e){$ctx=['eligible'=>false,'error'=>'Não foi possível consultar o vínculo de cobrança. Entre em contato com o suporte.'];error_log('[SaaS renewal] '.$e->getMessage());}
function sr_h($x):string{return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function sr_money($x):string{return 'R$ '.number_format((float)$x,2,',','.');}
function sr_date($x):string{$t=strtotime((string)$x);return $t?date('d/m/Y',$t):'-';}
$billing=(array)($ctx['boleto']??[]);$gen=(array)($ctx['generation']??[]);$title=(array)($ctx['receivable']??[]);
$amount=(float)($title['open_amount']??$ctx['amount']??0);
$line=trim((string)($billing['linha_digitavel']??''));
$pix=trim((string)($billing['pix_emv']??''));
$qr=trim((string)($billing['pix_qr_base64']??''));
$boletoUrl=trim((string)($billing['boleto_url']??''));
if (!filter_var($boletoUrl,FILTER_VALIDATE_URL)||strtolower(parse_url($boletoUrl,PHP_URL_SCHEME)??'')!=='https')$boletoUrl='';
$qrSource='';
if ($qr!==''){
    if (preg_match('#^data:image/(?:png|jpeg|webp);base64,[a-zA-Z0-9+/=\s]+$#',$qr)) $qrSource=$qr;
    elseif(preg_match('#^[a-zA-Z0-9+/=\s]+$#',$qr) && strlen($qr)<800000) $qrSource='data:image/png;base64,'.preg_replace('/\s+/','',$qr);
}
$elig=!empty($ctx['eligible']);$due=(string)($ctx['due_date']??'');
$hasOrder=(int)($gen['order_id']??0)>0;
$chargeStatus=strtoupper(trim((string)($billing['status']??'')));
$boletoCanRegister=$canPay && $elig && $hasOrder && (float)($title['open_amount']??0)>0.009
    && (!$billing || ($chargeStatus==='AGUARDANDO_API' && array_reduce(
        ['api_correlation_id','request_payload','response_payload','external_id','linha_digitavel','barcode','registered_at','paid_at','api_http_status','simulation_request_payload','simulated_at'],
        static fn(bool $ok,string $key):bool => $ok && trim((string)($billing[$key]??''))==='',true
    )));
$boletoNeedsCheck=$hasOrder && $billing && !$boletoCanRegister && !in_array($chargeStatus,['REGISTRADO','PAGO','BAIXADO'],true);

// V11.60: apenas a data do boleto é automática; assinatura/competência não mudam.
$bankDueExisting=substr(trim((string)($billing['due_date']??$title['due_date']??'')),0,10);
$bankDueAutomatic=saas_renewal_bank_due($due);
$bankDueNote=$bankDueAutomatic===$due?'data de vencimento do contrato':'vencimento do contrato ajustado para o próximo dia útil bancário possível';

$trialContract=$elig&&strtoupper((string)($ctx['contract']['status']??''))==='TESTE';
$trialOptions=$trialContract?saas_renewal_trial_options($pdo,$ctx):[];
$expires=substr((string)($ctx['subscription']['ends_at']??''),0,10);
$billBase=strtolower((string)($ctx['subscription']['status']??''))==='trial' && $expires!=='' ? $expires : (($expires!==''&&$due!==''&&$expires<=$due)?$expires:$due);
$canGenerate=$canPay&&$elig&&!$hasOrder&&$billBase!==''&&date('Y-m-d')>=(new DateTimeImmutable($billBase))->modify('-3 days')->format('Y-m-d');

// V11.58: download autenticado do PDF da cobrança vinculada ao próprio contrato SaaS.
// Não chama o Itaú, não registra nova cobrança e não altera título/assinatura.
// A própria rota de regularização já é autorizada para empresas bloqueadas pelo SaaS.
if (isset($_GET['baixar_boleto'])) {
    try {
        $billingCompanyId=(int)($ctx['billing_company_id']??0);
        $generationId=(int)($gen['id']??0);
        $receivableId=(int)($title['id']??0);
        $chargeId=(int)($billing['id']??0);
        if (!$elig || !$hasOrder || $billingCompanyId<=0 || $generationId<=0 || $receivableId<=0 || $chargeId<=0) {
            throw new RuntimeException('A fatura ainda não possui boleto disponível para download.');
        }
        if ((int)($gen['receivable_id']??0)!==$receivableId
            || (int)($billing['generation_id']??0)!==$generationId
            || (int)($billing['receivable_id']??0)!==$receivableId
            || (int)($billing['company_id']??0)!==$billingCompanyId) {
            throw new RuntimeException('A cobrança não corresponde à fatura deste contrato.');
        }
        require_once __DIR__ . '/../config/boleto_document.php';
        // A impressão não pode executar DDL durante o download.
        $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=true;
        $boletoData=boleto_document_load($pdo,$billingCompanyId,$chargeId,0);
        if ((int)($boletoData['generation_id']??0)!==$generationId
            || (int)($boletoData['receivable_id']??0)!==$receivableId
            || !boleto_document_ready($boletoData)) {
            throw new RuntimeException('O boleto ainda não está pronto para download.');
        }
        $pdf=boleto_document_pdf($boletoData);
        if ($pdf==='' || !str_starts_with($pdf,'%PDF-')) {
            throw new RuntimeException('O documento PDF não pôde ser gerado.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="boleto-nexa-fatura-'.$generationId.'.pdf"');
        header('Content-Length: '.strlen($pdf));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        error_log('[Nexa][SaaS boleto PDF] '.$e->getMessage());
        $_SESSION['nexa_saas_renew_flash']='Não foi possível baixar o boleto desta fatura. Confira o registro no Itaú ou procure o suporte.';
        header('Location: assinatura_regularizar.php',true,303);
        exit;
    }
}
$boletoReadyForPdf=$line!=='' && preg_replace('/\D+/','',(string)($billing['barcode']??''))!=='';
?>
<!doctype html><!-- nexa-regularizacao-aviso-contrato-v5-20261023 --><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Regularizar assinatura | Nexa Próspero</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#f4f8ff 0%,#eef5ff 42%,#f9fbff 100%);font-family:Inter,Segoe UI,Arial,sans-serif;color:#112846}
.sr-wrap{max-width:1040px;margin:28px auto;padding:0 18px}
.sr-head{background:linear-gradient(120deg,#091d55,#1e53ba 60%,#0d86b9);color:#fff;padding:22px 26px;border-radius:24px;box-shadow:0 18px 48px rgba(17,40,70,.16);position:relative;overflow:hidden}
.sr-head:after{content:"";position:absolute;right:-60px;top:-50px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08)}
.sr-head h1{margin:8px 0 10px;font-size:clamp(26px,3vw,36px);position:relative;z-index:1}
.sr-head p{margin:0;line-height:1.6;color:#e5efff;max-width:760px;position:relative;z-index:1}
.sr-brand{display:flex;align-items:center;gap:14px;position:relative;z-index:1;margin-bottom:0}.sr-brand-logo{width:188px;height:70px;object-fit:contain;padding:9px;border-radius:15px;border:1px solid rgba(255,255,255,.62);box-shadow:0 10px 24px rgba(0,0,0,.12);background:#fff}.sr-brand-copy{display:flex;flex-direction:column;gap:8px}.sr-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:7px 14px;font-size:12px;font-weight:800;letter-spacing:.04em;position:relative;z-index:1;width:max-content}
.sr-layout{display:grid;gap:16px;margin-top:16px}
.sr-body{background:#fff;border:1px solid #dce7f7;box-shadow:0 12px 34px rgba(13,43,90,.08);border-radius:20px;padding:24px}
.sr-section-title{margin:0 0 18px;font-size:24px;color:#123d79}
.sr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.sr-stat{background:linear-gradient(180deg,#f7faff,#eef4ff);border:1px solid #dbe5f5;padding:16px;border-radius:16px;min-width:0}
.sr-stat small{display:block;color:#60728d;font-weight:700;margin-bottom:6px}
.sr-stat strong{display:block;font-size:22px;line-height:1.25;overflow-wrap:anywhere;color:#0f2f5f}
.sr-alert{padding:16px 18px;border-radius:16px;margin-top:14px;border:1px solid #f0dcb1;background:#fff9eb;color:#805419;line-height:1.55}
.sr-alert strong{color:#6d430c}
.sr-alert.ok{background:#edfff4;color:#166343;border-color:#b5ecc9}
.sr-alert.err{background:#fff0f2;color:#9d2142;border-color:#f5c5ce}
.sr-alert.featured{display:flex;gap:14px;align-items:flex-start;padding:18px 20px;border:1px solid #f3c96a;background:linear-gradient(180deg,#fff5cf,#ffeaa7);box-shadow:0 10px 24px rgba(208,153,24,.12);color:#6c4706}
.sr-alert-featured-icon{width:42px;height:42px;border-radius:14px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 42px;box-shadow:0 6px 18px rgba(177,130,16,.14)}
.sr-alert-featured-copy b{display:block;margin-bottom:4px;font-size:15px;color:#6b4200}
.sr-alert.featured .sr-alert-featured-copy>div>strong{display:block;font-size:19px;font-weight:950;line-height:1.45;color:#633b00;letter-spacing:-.01em}
.sr-muted{font-size:13px;color:#5d6b81;line-height:1.6}
.sr-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.sr-stack{display:flex;flex-direction:column;gap:16px}
.sr-copy{width:100%;padding:12px 13px;border:1px solid #bacce1;border-radius:12px;resize:vertical;font:14px/1.45 monospace;background:#f7faff;color:#183153}
.sr-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.sr-pay-card{border:1px solid #dce6f4;border-radius:18px;padding:18px;background:linear-gradient(180deg,#ffffff,#f9fbff)}
.sr-pay-card h3{margin:0 0 8px;font-size:20px;color:#123d79}
.sr-qr{width:210px;max-width:100%;border:1px solid #dce5f3;background:#fff;padding:8px;border-radius:14px}
.sr-terms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.sr-term{display:flex;gap:12px;align-items:center;padding:16px;border:1px solid #cddcf0;border-radius:14px;background:#f8fbff;cursor:pointer;transition:.18s ease}
.sr-term:hover{border-color:#9eb6e3;transform:translateY(-1px)}
.sr-term:has(input:checked){border:2px solid #2256ce;background:#eff5ff;box-shadow:0 8px 18px rgba(34,86,206,.08)}
.sr-term span{flex:1;min-width:0}
.sr-term strong{display:block;color:#123d79}
.sr-term small{display:block;color:#657c94;margin-top:4px}
.sr-term b{color:#123d79;white-space:nowrap}
.sr-term input{width:17px;height:17px;accent-color:#2256ce}

.sr-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:22px}
.sr-primary-actions{display:grid;grid-template-columns:minmax(0,1fr);gap:12px;margin-top:18px}
/* V11.58: quatro ações uniformes no desktop; quebra apenas em telas menores. */
.sr-footer-actions{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:stretch;margin-top:22px}
.sr-footer-actions .sr-btn{min-width:0;min-height:54px;padding:10px 9px;font-size:13px;line-height:1.25}
.sr-boleto-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}
.sr-boleto-actions .sr-btn{min-width:0;padding:11px 9px;font-size:13px}
@media(max-width:820px){.sr-footer-actions{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:540px){.sr-footer-actions,.sr-boleto-actions{grid-template-columns:1fr}}

.sr-btn{border:0;display:inline-flex;align-items:center;justify-content:center;text-align:center;padding:13px 18px;border-radius:12px;background:#1b58d0;color:#fff;font-weight:800;text-decoration:none;cursor:pointer;font-size:14px;min-height:46px;line-height:1.2;transition:.18s ease;box-shadow:0 8px 18px rgba(27,88,208,.16)}
.sr-btn:hover{transform:translateY(-1px);filter:brightness(1.02)}
.sr-btn.secondary{border:1px solid #c5d4e5;background:#fff;color:#143769;box-shadow:none}
.sr-btn.secondary:hover{background:#f7faff}
.sr-btn.ghost{background:#eef4ff;color:#17438a;box-shadow:none}
.sr-btn:disabled,.sr-btn-blocked{background:#eef2f7!important;color:#64748b!important;border-color:#cbd5e1!important;cursor:not-allowed;box-shadow:none;transform:none!important;filter:none!important}
.sr-footnote{margin:18px 0 0;color:#63748d;font-size:13px;text-align:center;line-height:1.6}
.sr-top-messages{display:grid;gap:12px}
@media(max-width:820px){.sr-grid,.sr-two,.sr-terms,.sr-actions{grid-template-columns:1fr}}
@media(max-width:680px){.sr-wrap{margin:12px auto}.sr-head{padding:22px}.sr-body{padding:18px}.sr-alert.featured{padding:16px}.sr-alert-featured-icon{width:38px;height:38px;flex-basis:38px}.sr-brand-logo{width:164px;height:62px;border-radius:14px;flex:none}.sr-brand{flex-direction:column;align-items:flex-start}.sr-brand-copy{width:100%;min-width:0}.sr-pill{font-size:11px;white-space:normal}.sr-head h1{font-size:29px;line-height:1.2}}
</style></head><body><main class="sr-wrap">
<header class="sr-head"><div class="sr-brand"><img src="/assets/logo-nexa-v6-1.png?v=20261023-checkout" alt="Logo Nexa Próspero" class="sr-brand-logo" width="188" height="70"><div class="sr-brand-copy"><span class="sr-pill">NEXA PRÓSPERO · ASSINATURA</span><h1>Contratação e renovação</h1></div></div></header>
<div class="sr-top-messages"><?php if($error):?><div class="sr-alert err" role="alert"><?=sr_h($error)?></div><?php endif;?>
<?php if($message):?><div class="sr-alert ok" role="status"><?=sr_h($message)?></div><?php endif;?></div>
<div class="sr-layout"><div class="sr-body"><h2 class="sr-section-title">Contrato e cobrança</h2>
<?php $cycle=(array)($blocked['cycle']??[]);if(!empty($cycle['message'])):?><div class="sr-alert featured"><div class="sr-alert-featured-icon">⚠️</div><div class="sr-alert-featured-copy"><b>Aviso importante</b><div><strong><?=sr_h($cycle['message'])?></strong></div></div></div><?php endif;?>
<?php if(!$elig):?><div class="sr-alert err"><?=sr_h($ctx['error']??'Contrato indisponível')?></div><p class="sr-muted">Nenhum plano novo foi contratado e nenhum lançamento foi criado. Solicite à equipe de suporte que confira o vínculo do contrato automático.</p>
<?php else: ?>
<div class="sr-grid"><div class="sr-stat"><small>Contrato automático</small><strong>#<?=sr_h($ctx['contract']['contract_number']??$ctx['contract_id'])?></strong></div><div class="sr-stat"><small>Vencimento original da assinatura</small><strong><?=sr_h(sr_date($ctx['cycle_due_date']??$due))?></strong></div><div class="sr-stat"><small>Valor da competência</small><strong><?=sr_h(sr_money($amount))?></strong></div></div><p class="sr-muted" style="margin:8px 0 0">Competência de faturamento: <b><?=sr_h(sr_date($due))?></b>. Ela não muda ao regularizar o boleto.</p>
<?php if(!empty($ctx['due_date_note'])): ?><div class="sr-alert"><?=sr_h($ctx['due_date_note'])?></div><?php endif;?>
<?php if($hasOrder):?><div class="sr-alert <?=($line!==''||$pix!=='')?'ok':''?>"><strong>Pedido já gerado:</strong> #<?=sr_h($gen['order_id'])?>. Não será duplicado.<?php if($title):?> Conta a Receber #<?=sr_h($title['id'])?> · <?=sr_h($title['status']??'ABERTO')?>.<?php endif;?> <br><strong>Situação do boleto:</strong> <?=sr_h($chargeStatus?:'NÃO PREPARADO')?>.<?php if($billing && $bankDueExisting!==''):?> <strong>Vencimento bancário:</strong> <?=sr_h(sr_date($bankDueExisting))?>.<?php endif;?></div>
<?php if($boletoCanRegister):?><div class="sr-alert"><strong>A cobrança ainda não possui boleto registrado.</strong> Você pode registrar o boleto Itaú deste mesmo pedido, sem criar outra fatura.</div><form method="post" onsubmit="return confirm('Registrar o boleto Itaú da fatura existente? Esta ação não cria outro pedido.');" style="margin:12px 0"><input type="hidden" name="csrf" value="<?=sr_h($csrf)?>"><input type="hidden" name="action" value="registrar_boleto"><p class="sr-muted" style="margin:6px 0 10px">Vencimento automático do boleto: <strong><?=sr_h(sr_date($bankDueAutomatic))?></strong> (<?=sr_h($bankDueNote)?>). A data da assinatura será preservada.</p><button class="sr-btn" type="submit">Registrar boleto Itaú da fatura</button></form><?php endif;?>
<?php if($boletoNeedsCheck):?><div class="sr-alert err">A cobrança existente está em <strong><?=sr_h($chargeStatus)?></strong>. Não será reenviada automaticamente para evitar duplicidade. Solicite ao suporte a conferência do retorno Itaú e do motivo do erro.</div><?php endif;?>
<?php elseif($canGenerate):?>
<form method="post" style="margin-top:18px" onsubmit="return confirm('Confirmar a contratação/cobrança desta competência?')">
<input type="hidden" name="csrf" value="<?=sr_h($csrf)?>"><input type="hidden" name="action" value="gerar"><p class="sr-muted" style="margin:7px 0 12px">O boleto será gerado com vencimento automático em <strong><?=sr_h(sr_date($bankDueAutomatic))?></strong> (<?=sr_h($bankDueNote)?>), sem alterar o vencimento da assinatura.</p>
<?php if($trialContract):?>
  <?php if(!$trialOptions):?><div class="sr-alert err">As periodicidades ou serviços SaaS ainda não estão configurados. Nenhuma cobrança foi criada. Confira o mapa de módulos e os períodos comerciais da Central SaaS.</div><?php else:?>
  <h3 style="margin:0 0 12px;color:#123d79">Escolha a periodicidade da assinatura</h3>
  <div class="sr-terms">
  <?php foreach($trialOptions as $month=>$option):?><label class="sr-term"><input type="radio" name="renewal_term_months" value="<?=(int)$month?>" required <?= (int)$month===1?'checked':'' ?>><span><strong><?=sr_h($option['label'])?></strong><small><?=(int)$month?> mês(es)<?=((float)$option['discount_percent']>0)?' · '.sr_h(number_format((float)$option['discount_percent'],2,',','.')).'% de desconto':''?></small></span><b><?=sr_h(sr_money($option['amount']))?></b></label><?php endforeach; ?>
  </div><?php endif;?>
<?php endif; ?>
<div class="sr-primary-actions"><button class="sr-btn" <?=($trialContract&&!$trialOptions)?'disabled':''?>><?=$trialContract?'Contratar e gerar cobrança':'Gerar cobrança do contrato'?></button></div>
</form>
<?php else: ?><div class="sr-alert">Não há cobrança disponível para geração nesta data.<?php if(!$canPay):?> Apenas o administrador da empresa pode confirmar a emissão.<?php endif;?> O suporte pode conferir o contrato, os itens e o próximo vencimento.</div><?php endif;?>
<?php if($hasOrder):?><div class="sr-two" style="margin-top:22px"><section class="sr-pay-card"><h3>Boleto</h3>
<?php if($line!==''):?><p class="sr-muted">Linha digitável para pagamento:</p><textarea class="sr-copy" rows="2" id="linha" readonly><?=sr_h($line)?></textarea><div class="sr-boleto-actions"><button type="button" class="sr-btn secondary" onclick="srCopy('linha')">Copiar linha digitável</button><?php if($boletoReadyForPdf):?><a class="sr-btn" href="assinatura_regularizar.php?baixar_boleto=1">Baixar boleto (PDF)</a><?php else: ?><span class="sr-btn secondary sr-btn-blocked" role="status" aria-disabled="true" title="Aguardando código de barras do Itaú">PDF indisponível</span><?php endif;?></div>
<?php else:?><p class="sr-muted">O boleto ainda não está disponível. Situação: <strong><?=sr_h($chargeStatus?:'NÃO PREPARADO')?></strong>. Após o registro Itaú, a linha digitável aparecerá nesta tela.</p><?php endif;?></section>
<section class="sr-pay-card"><h3>Pix integrado à cobrança</h3><?php if($pix!==''):?><?php if($qrSource):?><img class="sr-qr" src="<?=sr_h($qrSource)?>" alt="QR Code Pix da cobrança"><?php endif;?><p class="sr-muted">Pix Copia e Cola:</p><textarea class="sr-copy" rows="3" id="pix" readonly><?=sr_h($pix)?></textarea><div class="sr-actions" style="margin-top:14px;grid-template-columns:minmax(0,1fr)"><button type="button" class="sr-btn secondary" onclick="srCopy('pix')">Copiar Pix</button></div><?php else:?><p class="sr-muted">O Itaú ainda não retornou um Pix integrado para esta cobrança. Não é possível gerar um Pix paralelo sem vincular corretamente o pagamento ao título existente.</p><?php endif;?></section></div>
<?php endif;?>
<?php endif;?>
<div class="sr-footer-actions"><?php if(empty($blocked['blocked'])): ?><a href="dashboard.php" class="sr-btn secondary">Entrar no Sistema - Pagar Depois</a><?php else: ?><span class="sr-btn secondary sr-btn-blocked" role="status" aria-disabled="true" title="Acesso indisponível até a regularização">Acesso suspenso até o pagamento</span><?php endif; ?><a href="assinatura_regularizar.php" class="sr-btn ghost">Verificar pagamento / Atualizar</a><a href="minhas_faturas.php" class="sr-btn ghost">Minhas faturas</a><a href="logout.php" class="sr-btn secondary">Sair</a></div>
<p class="sr-footnote">O acesso será restabelecido depois que o sistema identificar a quitação no Contas a Receber. O comprovante de pagamento isolado não libera automaticamente a assinatura.</p></div>
<footer class="sr-footnote">Nexa Próspero · Acesso seguro de regularização de assinatura</footer></div></main>
<script>function srCopy(id){const x=document.getElementById(id);if(!x)return;x.select();if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(x.value).then(()=>alert('Código copiado')).catch(()=>alert('Selecione e copie o texto'));}else{try{document.execCommand('copy');alert('Código copiado')}catch(e){alert('Selecione e copie o texto')}}}</script>
</body></html>