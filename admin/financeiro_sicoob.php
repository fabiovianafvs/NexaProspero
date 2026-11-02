<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/sicoob_boleto.php';
require_once __DIR__ . '/../config/sicoob_bank_status.php';
require_once __DIR__ . '/../config/sicoob_boleto_register.php';
require_once __DIR__ . '/../config/sicoob_movimentacoes.php';
$cross = function_exists('finance_multi_company_billing_config_enabled') && finance_multi_company_billing_config_enabled($pdo);
$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
if ($cross && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'financeiro_caixas')) {
    throw new RuntimeException('Sem permissão para configurar bancos nesta empresa.');
}
$bankId=(int)($_GET['bank']??$_POST['bank']??0);
if ($bankId<=0) finance_redirect('financeiro_caixas_bancos.php');
$st=$pdo->prepare('SELECT id,name FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1');
$st->execute([$cid,$bankId]);$bank=$st->fetch(PDO::FETCH_ASSOC)?:[];
if (!$bank) throw new RuntimeException('Conta bancária inválida para a empresa selecionada.');
$cfg=billing_bank_config($pdo,$cid,$bankId);
if (!$cfg || strtoupper((string)($cfg['provider']??''))!=='SICOOB' || strtoupper((string)($cfg['integration_mode']??''))!=='API') {
    throw new RuntimeException('Configure a conta como SICOOB / API antes de continuar.');
}
if(session_status()!==PHP_SESSION_ACTIVE) throw new RuntimeException('Sessão indisponível para validação CSRF.');
if(empty($_SESSION['nexa_sicoob_token']))$_SESSION['nexa_sicoob_token']=bin2hex(random_bytes(20));
$csrf=(string)$_SESSION['nexa_sicoob_token'];
$diag=sicoob_boleto_diagnose($cfg,$pdo);$msg='';$err='';$technical='';
$movementSessionKey='sicoob_mov_'.(int)$cid.'_'.(int)$bankId;
$movementSession=(array)($_SESSION[$movementSessionKey]??[]);
$movementSummary=null;
$moveStart=(string)($_POST['movimento_inicio']??$_GET['from']??date('Y-m-d',strtotime('-1 day')));
$moveEnd=(string)($_POST['movimento_fim']??$_GET['to']??date('Y-m-d'));
try {
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada; recarregue a página.');
    if($cross && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf']??'')))){
      throw new RuntimeException('Sessão de segurança multiempresa expirada.');
    }
    $action=(string)($_POST['action']??'');
    if($action==='request_movements_v16'){
      if(function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo,'financeiro_boletos')) throw new RuntimeException('Sem permissão para consultar cobranças.');
      $movementSummary=sicoob_v16_request($cfg,$pdo,$moveStart,$moveEnd);
      $_SESSION[$movementSessionKey]=['codigo'=>$movementSummary['codigo_solicitacao'],'inicio'=>$moveStart,'fim'=>$moveEnd,'ids'=>[],'criado_em'=>time()];
      $movementSession=$_SESSION[$movementSessionKey];
      $msg='Solicitação de LIQUIDAÇÕES recebida pelo Sicoob, código '.$movementSummary['codigo_solicitacao'].'. Aguarde o processamento e clique em Verificar solicitação. Nenhuma baixa executada.';
    }elseif($action==='check_movements_v16'){
      if(function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo,'financeiro_boletos')) throw new RuntimeException('Sem permissão para consultar cobranças.');
      if(empty($movementSession['codigo'])||time()-(int)($movementSession['criado_em']??0)>7200) throw new RuntimeException('Nenhuma solicitação recente desta conta nesta sessão. Solicite um período novamente.');
      $movementSummary=sicoob_v16_status($cfg,$pdo,(string)$movementSession['codigo']);
      $movementSession['ids']=$movementSummary['ids'];
      $_SESSION[$movementSessionKey]=$movementSession;
      $msg='Consulta de situação Sicoob: '.$movementSummary['situacao'].' (HTTP '.$movementSummary['http'].'). Nenhum título alterado.';
    }elseif($action==='inspect_movements_v16'){
      if(function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo,'financeiro_boletos')) throw new RuntimeException('Sem permissão para consultar cobranças.');
      $fileId=(int)($_POST['arquivo_movimento_id']??0);
      if(empty($movementSession['codigo'])||time()-(int)($movementSession['criado_em']??0)>7200||!in_array($fileId,(array)($movementSession['ids']??[]),true)) throw new RuntimeException('Arquivo não pertence à solicitação desta conta e sessão.');
      $movementSummary=sicoob_v16_inspect_zip($cfg,$pdo,(string)$movementSession['codigo'],$fileId);
      $msg='Estrutura do movimento Sicoob lida em modo seguro. Somente chaves JSON foram exibidas. Não houve baixa.';
    }elseif($action==='test_token'){
      $token=sicoob_boleto_get_token($cfg,$pdo);
      $msg='OAuth Sicoob: token de consulta recebido com sucesso ('.(int)$token['expires_in'].' segundos). Nenhum boleto alterado.';
    }elseif($action==='preflight_inclusion'){
      $result=sicoob_boleto_preflight_inclusion($cfg,$pdo);
      $msg='Solicitação OAuth para boletos_inclusao aceita ('.(int)$result['expires_in'].' segundos). Nenhuma cobrança emitida. O registro ainda depende de homologação.';
    }elseif($action==='consult_ranges'){
      $result=sicoob_boleto_consult_number_ranges(
        $cfg,$pdo,
        (string)($_POST['numero_contrato_cobranca']??''),
        (int)($_POST['quantidade_faixas']??1),
        (string)($_POST['rota_faixas']??'alternativa')
      );
      $technical=json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:'';
      $msg='Consulta de faixas concluída (HTTP '.(int)$result['http_status'].'). Não reserva números e não registra cobranças.';
    }elseif($action==='consult_number'){
      $r=sicoob_boleto_consult_number($pdo,$cfg,(string)($_POST['nosso_numero']??''));
      $bankReading=nexa_sicoob_read_bank_status($r);
      $msg='Consulta por Nosso Número (HTTP '.$r['status'].'): '.$bankReading['detail'].' Nenhum título do Nexa foi alterado.';
      $technical=json_encode($r['response'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:'';
    }elseif($action==='consult'){
      $chargeId=(int)($_POST['charge_id']??0);
      $r=sicoob_boleto_consult_charge($pdo,$cid,$bankId,$chargeId);
      $bankReading=nexa_sicoob_read_bank_status($r);
      $msg='Consulta (HTTP '.$r['status'].'): '.$bankReading['detail'].' Nenhum status financeiro foi modificado.';
      $technical=json_encode($r['response'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:'';
    }else throw new RuntimeException('Ação não permitida nesta versão de leitura.');
  }
} catch(Throwable $e){$err=$e->getMessage();}
finance_admin_start('Sicoob - Cobrança Bancária','fin_boletos');
finance_page_header('Sicoob · Cobrança Bancária V3','Configuração, OAuth e diagnóstico Sicoob. Registro e boletos: Financeiro → Cobranças e Boletos. Sincronização LIQUI sem quitação nesta etapa.',[]);
finance_flash();
?>
<div class="finance-card">
<h2 class="finance-subtitle">Conta <?= finance_h((string)$bank['name']) ?> · Empresa #<?= (int)$cid ?></h2>
<p class="finance-muted">Nesta integração: Convênio = <code>numeroCliente</code>; Carteira = <code>codigoModalidade</code>. Sem caminhos PEM na configuração bancária, o Sicoob utiliza o A1 fiscal ativo <strong>desta mesma empresa</strong> via PFX/P12 (sem exportar chave privada). A conta e o aplicativo precisam estar autorizados no Sicoob. Não envie credenciais por mensagens.</p>
<?php if($diag['ok']): ?><p class="finance-muted">Fonte do certificado: <?= (($diag['config']['cert_source'] ?? '')==='fiscal_a1') ? 'A1 fiscal ativo (PFX/P12)' : 'Arquivos bancários PEM' ?>.</p><?php endif; ?>
<p><a class="btn" href="financeiro_caixas_bancos.php?edit=<?=$bankId?>">Editar configuração bancária</a> <a class="btn btn-primary" href="financeiro_boletos.php?filtrar=1&amp;bank=<?=$bankId?>">Cobranças e Boletos — conta Sicoob</a></p>
<?php if($diag['ok']): ?><div class="msg ok">Configuração local pronta para teste OAuth/consulta. Ainda não há homologação bancária.</div>
<?php else: ?><div class="msg err"><strong>Configuração incompleta:</strong><ul><?php foreach($diag['errors'] as $e):?><li><?=finance_h($e)?></li><?php endforeach;?></ul></div><?php endif; ?>
<?php if($msg!==''):?><div class="msg ok"><?=finance_h($msg)?></div><?php endif;?>
<?php if($err!==''):?><div class="msg err"><?=finance_h($err)?></div><?php endif;?>
<form method="post" style="margin-top:12px">
<input type="hidden" name="action" value="test_token"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<button class="btn btn-primary" <?=!$diag['ok']?'disabled':''?> onclick="return confirm('Testar o OAuth Sicoob usando o certificado desta conta?')">Testar OAuth Sicoob</button>
</form>
<div style="margin-top:18px"><h3>Pré-validação para futura emissão</h3>
<p class="finance-muted">Estas verificações não registram boletos, não reservam Nosso Número e não alteram o Contas a Receber. O registro manual autorizado fica em Cobranças e Boletos.</p>
<div class="finance-inline-actions" style="gap:8px;flex-wrap:wrap">
<form method="post">
<input type="hidden" name="action" value="preflight_inclusion"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<button class="btn" <?=!$diag['ok']?'disabled':''?>>Verificar permissão de inclusão (OAuth)</button>
</form>
<form method="post" class="finance-form" style="max-width:520px">
<input type="hidden" name="action" value="consult_ranges"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<label for="sicoob_rota_faixas">Rota de consulta</label>
<select id="sicoob_rota_faixas" name="rota_faixas">
<option value="alternativa" <?=($_POST['rota_faixas']??'alternativa')==='alternativa'?'selected':''?>>Documentação V3 — faixas-nosso-numero (teste alternativo)</option>
<option value="colecao" <?=($_POST['rota_faixas']??'alternativa')==='colecao'?'selected':''?>>Coleção Postman — faixas-nosso-numero-disponiveis</option>
</select>
<label for="sicoob_numero_contrato">Contrato de cobrança (opcional; apenas para consultar faixas)</label>
<input id="sicoob_numero_contrato" type="text" name="numero_contrato_cobranca" inputmode="numeric" pattern="[0-9]{1,18}" maxlength="18" autocomplete="off" placeholder="Se o Sicoob exigir, informe o número do contrato" value="<?=finance_h((string)($_POST['numero_contrato_cobranca']??''))?>">
<label for="sicoob_quantidade_faixas">Quantidade mínima</label>
<input id="sicoob_quantidade_faixas" type="number" name="quantidade_faixas" min="1" max="1000" value="<?=max(1,min(1000,(int)($_POST['quantidade_faixas']??1)))?>">
<button class="btn" <?=!$diag['ok']?'disabled':''?>>Consultar faixas de Nosso Número</button>
</form>
</div></div>
<form method="post" class="finance-form" style="margin-top:18px">
<h3>Consultar boleto Sicoob pelo Nosso Número</h3>
<p class="finance-muted">Consulta bancária read-only, inclusive para boletos ainda não importados no Nexa.</p>
<input type="hidden" name="action" value="consult_number"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<div class="finance-inline-actions"><input name="nosso_numero" inputmode="numeric" pattern="[0-9]+" maxlength="25" placeholder="Nosso Número" required><button class="btn" <?=!$diag['ok']?'disabled':''?>>Consultar no Sicoob</button></div>
</form>
</div>
<div class="finance-card" id="sicoobMovimentos"><h2 class="finance-subtitle">Sincronização Sicoob — diagnóstico de liquidações (V1.6)</h2>
<p class="finance-muted">Consulte a movimentação <strong>tipo 5 — LIQUIDAÇÃO</strong>, em até dois dias por solicitação. O banco processa o arquivo de forma assíncrona. Esta etapa não altera títulos, não cria recebimentos e não executa baixa automática.</p>
<form method="post" class="finance-form">
<input type="hidden" name="action" value="request_movements_v16"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<div class="finance-inline-actions" style="gap:10px;flex-wrap:wrap;align-items:end">
<div><label for="sicoob_mov_start">Movimentação inicial</label><input id="sicoob_mov_start" type="date" name="movimento_inicio" required value="<?=finance_h($moveStart)?>" max="<?=date('Y-m-d')?>"></div>
<div><label for="sicoob_mov_end">Movimentação final</label><input id="sicoob_mov_end" type="date" name="movimento_fim" required value="<?=finance_h($moveEnd)?>" max="<?=date('Y-m-d')?>"></div>
<button class="btn" <?=!$diag['ok']?'disabled':''?>>Solicitar liquidações — sem baixar</button>
</div>
</form>
<?php if(!empty($movementSession['codigo'])): ?>
<div style="margin-top:12px;padding:12px;background:#f8fafc;border:1px solid #dbe4f0;border-radius:10px"><strong>Solicitação Sicoob #<?=finance_h((string)$movementSession['codigo'])?></strong> · <?=finance_h((string)($movementSession['inicio']??''))?> até <?=finance_h((string)($movementSession['fim']??''))?>
<form method="post" style="margin-top:8px"><input type="hidden" name="action" value="check_movements_v16"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<button class="btn" <?=!$diag['ok']?'disabled':''?>>Verificar solicitação</button></form>
<?php foreach((array)($movementSession['ids']??[]) as $movFileId): if((int)$movFileId<=0)continue; ?>
<form method="post" style="display:inline-block;margin:8px 7px 0 0"><input type="hidden" name="action" value="inspect_movements_v16"><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="csrf" value="<?=finance_h($csrf)?>"><input type="hidden" name="arquivo_movimento_id" value="<?=(int)$movFileId?>">
<?php if($cross):?><input type="hidden" name="mc_csrf" value="<?=finance_h(multi_company_context_csrf_token())?>"><?php endif;?>
<button class="btn" <?=!$diag['ok']?'disabled':''?>>Analisar campos do arquivo #<?=(int)$movFileId?></button></form>
<?php endforeach; ?>
</div><?php endif;?>
<?php if($movementSummary):?><div style="margin-top:12px"><h3>Resultado da movimentação</h3><pre style="max-height:300px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere"><?=finance_h(json_encode($movementSummary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:'')?></pre></div><?php endif;?>
</div>
<div class="finance-card"><h2 class="finance-subtitle">Cobranças e Boletos</h2>
<p class="finance-muted">O registro autorizado, a consulta do título e a impressão de boletos Sicoob agora ficam na lista principal do Financeiro, junto com os boletos Itaú. Esta tela continua somente com a configuração e os diagnósticos bancários.</p>
<p><a class="btn btn-primary" href="financeiro_boletos.php?filtrar=1&amp;bank=<?=$bankId?>">Abrir Cobranças e Boletos</a></p>
</div>
<?php if($technical!==''):?><div class="finance-card"><details open><summary>Retorno da API (dados bancários restritos)</summary><pre style="max-height:320px;overflow:auto;white-space:pre-wrap;word-wrap:break-word"><?=finance_h($technical)?></pre></details></div><?php endif;?>
<div class="finance-card"><p class="finance-muted">Proteção: a emissão V1.5 exige liberação explícita por empresa e usa numeração gerada pelo banco. Toda resposta inconclusiva bloqueia reenvio. Não executa baixa bancária nem quitação financeira. A conciliação via LIQUI será liberada somente após validação de arquivo de movimentação real Sicoob.</p></div>
<?php finance_admin_end(); ?>
