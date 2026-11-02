<?php
declare(strict_types=1);
/**
 * V11.56: consulta de faturas do próprio contrato SaaS.
 * Somente leitura, mesmo quando a assinatura está no período de bloqueio.
 * Nunca gera pedido, cobrança ou chama API externa durante GET.
 */
require __DIR__.'/_bootstrap_public.php';
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/saas_renewal_portal.php';
$saasId=(int)COMPANY_ID;
$userId=(int)(auth_user_id()??0);
$role=auth_role();
if ($userId<=0 || !in_array($role,['company_admin','company_user','platform_admin'],true) ||
    ($role!=='platform_admin' && (int)auth_company_id()!==$saasId)) {
    header('Location: login.php',true,302);exit;
}
function mf_h(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function mf_date(mixed $v):string {$t=strtotime((string)$v);return $t?date('d/m/Y',$t):'-';}
function mf_money(mixed $v):string {return 'R$ '.number_format((float)$v,2,',','.');}
$rows=[];$error='';$detail=[];$bid=0;$contractId=0;$selectedId=0;
try {
    $link=saas_sub_bridge_link($pdo,$saasId);
    $bid=(int)($link['billing_company_id']??0);
    $contractId=(int)($link['contract_id']??0);
    if ($bid<=0||$contractId<=0) throw new RuntimeException('Não há contrato de faturamento associado à empresa.');
    $st=$pdo->prepare('SELECT g.id,g.order_id,g.competence,g.due_date,g.amount,g.receivable_id,g.status, r.status AS receivable_status,r.open_amount AS open_amount,r.due_date AS receivable_due_date,b.due_date AS bank_due_date, b.status AS boleto_status,b.id AS boleto_id,b.barcode AS boleto_barcode,b.linha_digitavel AS boleto_linha,b.receivable_id AS boleto_receivable_id FROM contract_generations g LEFT JOIN finance_receivables r ON r.company_id=g.company_id AND r.id=g.receivable_id LEFT JOIN finance_boleto_charges b ON b.company_id=g.company_id AND b.generation_id=g.id WHERE g.company_id=? AND g.contract_id=? AND COALESCE(g.order_id,0)>0 ORDER BY g.competence DESC,g.id DESC LIMIT 36');
    $st->execute([$bid,$contractId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $selectedId=(int)($_GET['fatura']??0);
    if ($selectedId>0) {
        $st=$pdo->prepare('SELECT g.id,g.order_id,g.competence,g.due_date,g.amount, r.status AS receivable_status,r.open_amount,r.due_date AS receivable_due_date,b.due_date AS bank_due_date,b.status AS boleto_status,b.linha_digitavel,b.pix_emv,b.pix_qr_base64,b.boleto_url FROM contract_generations g LEFT JOIN finance_receivables r ON r.company_id=g.company_id AND r.id=g.receivable_id LEFT JOIN finance_boleto_charges b ON b.company_id=g.company_id AND b.generation_id=g.id WHERE g.company_id=? AND g.contract_id=? AND g.id=? AND COALESCE(g.order_id,0)>0 LIMIT 1');
        $st->execute([$bid,$contractId,$selectedId]);$detail=$st->fetch(PDO::FETCH_ASSOC)?:[];
    }
} catch(Throwable $e) {error_log('[Nexa][Minhas Faturas] '.$e->getMessage());$error='Não foi possível consultar as faturas. Solicite ao suporte a conferência do vínculo financeiro.';}
// V11.59: PDF da fatura selecionada, somente para empresa/contrato autenticados.
// Nunca registra nova cobrança e nunca aceita IDs de empresa/cobrança fornecidos pelo navegador.
if (isset($_GET['baixar_boleto'])) {
    $requestedId=filter_var($_GET['baixar_boleto'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    try {
        if ($error!=='' || $bid<=0 || $contractId<=0 || $requestedId===false) {
            throw new RuntimeException('Fatura não disponível para download.');
        }
        $st=$pdo->prepare('SELECT g.id AS generation_id,g.receivable_id AS title_id,b.id AS charge_id FROM contract_generations g JOIN finance_boleto_charges b ON b.company_id=g.company_id AND b.generation_id=g.id AND b.receivable_id=g.receivable_id WHERE g.company_id=? AND g.contract_id=? AND g.id=? AND COALESCE(g.order_id,0)>0 LIMIT 1');
        $st->execute([$bid,$contractId,(int)$requestedId]);
        $invoice=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if (!$invoice || (int)($invoice['charge_id']??0)<=0 || (int)($invoice['title_id']??0)<=0) {
            throw new RuntimeException('A fatura ainda não tem boleto vinculado.');
        }
        require_once __DIR__.'/../config/boleto_document.php';
        $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=true;
        $doc=boleto_document_load($pdo,$bid,(int)$invoice['charge_id'],0);
        if ((int)($doc['company_id']??0)!==$bid
            || (int)($doc['generation_id']??0)!==(int)$invoice['generation_id']
            || (int)($doc['receivable_id']??0)!==(int)$invoice['title_id']
            || !boleto_document_ready($doc)) {
            throw new RuntimeException('O boleto não está pronto para download.');
        }
        $pdf=boleto_document_pdf($doc);
        if (!str_starts_with($pdf,'%PDF-')) throw new RuntimeException('Falha na preparação do PDF.');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="boleto-nexa-fatura-'.(int)$requestedId.'.pdf"');
        header('Content-Length: '.strlen($pdf));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    } catch(Throwable $e) {
        error_log('[Nexa][Minhas Faturas PDF] '.$e->getMessage());
        $_SESSION['nexa_mf_flash']='Não foi possível baixar o PDF. Confira o registro do boleto Itaú ou solicite suporte.';
        header('Location: minhas_faturas.php'.($requestedId!==false?'?fatura='.(int)$requestedId:''),true,303);
        exit;
    }
}
$mfFlash=trim((string)($_SESSION['nexa_mf_flash']??''));
unset($_SESSION['nexa_mf_flash']);
$boletoUrl=trim((string)($detail['boleto_url']??''));
if (!filter_var($boletoUrl,FILTER_VALIDATE_URL)||strtolower((string)(parse_url($boletoUrl,PHP_URL_SCHEME)??''))!=='https')$boletoUrl='';
$qrSource='';$qr=trim((string)($detail['pix_qr_base64']??''));
if ($qr!=='' && strlen($qr)<800000) {
    if(preg_match('#^data:image/(?:png|jpeg|webp);base64,[a-zA-Z0-9+/=\s]+$#',$qr))$qrSource=$qr;
    elseif(preg_match('#^[a-zA-Z0-9+/=\s]+$#',$qr))$qrSource='data:image/png;base64,'.preg_replace('/\s+/','',$qr);
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Minhas Faturas | Nexa Próspero</title>
<style>
*{box-sizing:border-box}body{font:14px/1.5 Inter,Segoe UI,Arial,sans-serif;background:#f5f8fe;color:#14213f;margin:0}.page{max-width:1060px;margin:26px auto;padding:0 16px}.hero{background:linear-gradient(130deg,#071b55,#2563c7);padding:22px 24px;color:#fff;border-radius:20px}.hero h1{margin:4px 0;font-size:25px}.hero p{margin:0;color:#dbeafe}.actions{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.btn{display:inline-block;border-radius:10px;border:1px solid #c7d7ed;padding:9px 12px;background:white;color:#17438a;text-decoration:none;font-weight:800;cursor:pointer;font:inherit}.btn.primary{background:#2155cc;color:white;border-color:#2155cc}.card{padding:18px;background:#fff;border:1px solid #d9e5f5;border-radius:17px;margin-top:14px;box-shadow:0 8px 22px #122e5710}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:930px}.invoice-buttons{display:flex;gap:7px;align-items:center;white-space:nowrap}.invoice-buttons .btn{font-size:12px;white-space:nowrap;padding:8px 10px}th,td{padding:11px 10px;text-align:left;border-bottom:1px solid #e7edf6}th{color:#476180;font-size:12px}td b{white-space:nowrap}.muted{color:#667a95}.warning{background:#fff7e6;border:1px solid #f6d48a;color:#854d0e;padding:12px;border-radius:12px}.status{font-size:12px;background:#eff4ff;border-radius:999px;padding:4px 8px;font-weight:800}.copy{width:100%;resize:vertical;background:#f7faff;border:1px solid #b9cce6;padding:12px;border-radius:12px;font:13px/1.5 monospace;color:#102f54}.qr{max-width:210px;border:1px solid #d9e5f5;border-radius:12px;padding:6px;background:#fff}.split{display:grid;grid-template-columns:1fr 1fr;gap:15px}@media(max-width:700px){.split{grid-template-columns:1fr}}</style></head><body><div class="page"><div class="hero"><h1>Minhas faturas</h1><p>Consulte cobranças da assinatura Nexa Próspero, inclusive as que ainda não foram pagas.</p></div>
<div class="actions"><a class="btn" href="area_trabalho.php">Área de Trabalho</a><a class="btn" href="assinatura_regularizar.php">Meu contrato / pagar</a></div>
<?php if($error!==''):?><div class="warning"><?=mf_h($error)?></div><?php endif;?>
<?php if($mfFlash!==''):?><div class="warning" role="status"><?=mf_h($mfFlash)?></div><?php endif;?>
<div class="card"><h2 style="margin-top:0">Faturas do contrato</h2><div class="table-wrap"><table><thead><tr><th>Competência</th><th>Pedido</th><th>Venc. original</th><th>Venc. boleto</th><th>Valor</th><th>Conta a Receber</th><th>Itaú</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=mf_date($r['competence']??'')?></td><td>#<?=(int)$r['order_id']?></td><td><?=mf_date($r['due_date']??'')?></td><td><?=mf_date($r['bank_due_date']??$r['receivable_due_date']??'')?></td><td><b><?=mf_money($r['amount']??0)?></b></td><td><span class="status"><?=mf_h($r['receivable_status']?:'NÃO LOCALIZADA')?></span></td><td><span class="status"><?=mf_h($r['boleto_status']?:'NÃO PREPARADO')?></span></td><td><div class="invoice-buttons"><a class="btn<?=((int)$selectedId===(int)$r['id'])?' primary':''?>" href="<?=((int)$selectedId===(int)$r['id'])?'minhas_faturas.php':'minhas_faturas.php?fatura='.(int)$r['id'].'#mf-detalhe'?>" aria-controls="mf-detalhe" aria-expanded="<?=((int)$selectedId===(int)$r['id'])?'true':'false'?>" title="<?=((int)$selectedId===(int)$r['id'])?'Clique novamente para fechar':'Ver os detalhes e pagar'?>">Ver / Pagar</a><?php if((int)($r['boleto_id']??0)>0 && (int)($r['boleto_receivable_id']??0)===(int)($r['receivable_id']??0) && trim((string)($r['boleto_linha']??''))!=='' && preg_replace('/\D+/','',(string)($r['boleto_barcode']??''))!==''):?><a class="btn" href="minhas_faturas.php?baixar_boleto=<?=(int)$r['id']?>" title="Baixar PDF do boleto vinculado a esta fatura">Boleto PDF</a><?php endif;?></div></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8" class="muted">Ainda não existem faturas geradas para este contrato.</td></tr><?php endif;?></tbody></table></div></div>
<?php if($detail):?><div class="card" id="mf-detalhe"><h2>Fatura do pedido #<?=(int)$detail['order_id']?></h2><p>Vencimento original: <strong><?=mf_date($detail['due_date'])?></strong> · Vencimento do boleto: <strong><?=mf_date($detail['bank_due_date']??$detail['receivable_due_date']??'')?></strong> · Valor: <strong><?=mf_money($detail['amount'])?></strong> · Situação: <strong><?=mf_h($detail['boleto_status']?:'NÃO PREPARADO')?></strong></p>
<?php if(trim((string)($detail['linha_digitavel']??''))!==''):?><h3>Boleto</h3><textarea class="copy" rows="2" id="boleto" readonly><?=mf_h($detail['linha_digitavel'])?></textarea><div class="actions"><?php if($boletoUrl!==''):?><a class="btn primary" href="<?=mf_h($boletoUrl)?>" target="_blank" rel="noopener noreferrer">Abrir boleto</a><?php endif;?><button class="btn" type="button" onclick="mfCopy('boleto')">Copiar linha digitável</button></div><?php endif;?>
<?php if(trim((string)($detail['pix_emv']??''))!==''):?><h3>Pix integrado</h3><div class="split"><div><?php if($qrSource!==''):?><img class="qr" src="<?=mf_h($qrSource)?>" alt="QR Code Pix do boleto"><?php endif;?></div><div><textarea class="copy" rows="4" id="pix" readonly><?=mf_h($detail['pix_emv'])?></textarea><button type="button" class="btn" onclick="mfCopy('pix')">Copiar Pix</button></div></div><?php endif;?>
<?php if(trim((string)($detail['linha_digitavel']??''))==='' && trim((string)($detail['pix_emv']??''))===''):?><div class="warning">O boleto/Pix ainda não foi disponibilizado pelo Itaú. Não gere outro pedido: use “Meu contrato / pagar” para consultar o status e, se permitido, registrar a cobrança existente.</div><?php endif;?>
</div><?php endif; ?>
</div><script>function mfCopy(id){const n=document.getElementById(id);if(!n)return;n.select();try{document.execCommand('copy');alert('Código copiado.')}catch(e){alert('Selecione o texto e copie.') }}</script></body></html>
