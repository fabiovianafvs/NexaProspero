<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/company_sequences.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'customers_write')
  : (int)COMPANY_ID;
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? 'financeiro_pessoas.php?searched=1'));
if ($returnTo === '' || preg_match('#^https?://#i', $returnTo) || str_starts_with($returnTo, '//') || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
  $returnTo = 'financeiro_pessoas.php?searched=1';
}
if (!preg_match('#^financeiro_pessoas\.php(?:\?.*)?$#', $returnTo)) $returnTo = 'financeiro_pessoas.php?searched=1';

if (!function_exists('paw_csrf_token')) {
  function paw_csrf_token(): string {
    if (empty($_SESSION['pessoa_aniversario_whatsapp_csrf']) || !is_string($_SESSION['pessoa_aniversario_whatsapp_csrf'])) $_SESSION['pessoa_aniversario_whatsapp_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['pessoa_aniversario_whatsapp_csrf'];
  }
}
function paw_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function paw_date_br($v): string { $ts=strtotime((string)$v); return $ts?date('d/m/Y',$ts):'—'; }

$st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? AND active=1 LIMIT 1');
$st->execute([$cid,$id]);$person=$st->fetch(PDO::FETCH_ASSOC)?:null;
if(!$person){http_response_code(404);finance_admin_start('Aniversário por WhatsApp','fin_pessoas');finance_page_header('Aniversário por WhatsApp','Pessoa não encontrada.',[['href'=>$returnTo,'label'=>'Voltar','class'=>'btn']]);finance_tabs('fin_pessoas');echo '<div class="msg err">Pessoa não encontrada.</div>';finance_admin_end();exit;}
if((string)($person['person_type']??'')!=='FISICA' || trim((string)($person['birth_opening_date']??''))===''){
  finance_admin_start('Aniversário por WhatsApp','fin_pessoas');finance_page_header('Aniversário por WhatsApp','Este envio é destinado a pessoas físicas com data de nascimento cadastrada.',[['href'=>$returnTo,'label'=>'Voltar','class'=>'btn']]);finance_tabs('fin_pessoas');echo '<div class="msg err">Informe a data de nascimento no cadastro da pessoa antes de enviar a mensagem.</div>';finance_admin_end();exit;
}

$phone=whatsapp_digits((string)($_POST['phone']??($person['whatsapp']??$person['phone']??'')));
$name=trim((string)($person['name']??''))?:'cliente';
$firstName=trim((string)(preg_split('/\s+/u',$name,-1,PREG_SPLIT_NO_EMPTY)[0]??$name));
$companyName='Nexa Próspero';
try{$st=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');$st->execute([$cid]);$companyName=trim((string)($st->fetchColumn()?:''))?:$companyName;}catch(Throwable $e){}
$birth=(string)$person['birth_opening_date'];$birthYear=(int)substr($birth,0,4);$age=$birthYear>0?max(0,(int)date('Y')-$birthYear):0;
$fallback="Olá, {{primeiro_nome}}! 🎉\n\nA {{empresa}} deseja a você um feliz aniversário, com muita saúde, alegria e realizações.\n\nParabéns pelo seu dia! 🎂";
$message=whatsapp_message_model_render($pdo,$cid,'aniversario',[
  'empresa'=>$companyName,'nome'=>$name,'primeiro_nome'=>$firstName,'data_nascimento'=>paw_date_br($birth),'idade'=>(string)$age,
],$fallback);
$model=whatsapp_message_model_selected($pdo,$cid,'aniversario');
$err='';$ok='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  try{
    $token=(string)($_POST['csrf']??'');if($token===''||!hash_equals(paw_csrf_token(),$token))throw new RuntimeException('Sessão expirada. Atualize a página.');
    if($phone==='')throw new RuntimeException('Informe um número de WhatsApp para a pessoa.');
    $mode=(string)($_POST['mode']??'api');
    if($mode==='web'){
      $to=whatsapp_normalize_phone($phone);header('Location: https://wa.me/'.rawurlencode($to).'?text='.rawurlencode($message));exit;
    }
    $cfg=whatsapp_load_config($pdo,$cid);$cfg['enabled']=true;$cfg['timeout_seconds']=max(60,(int)($cfg['timeout_seconds']??30));
    if(!whatsapp_send_message($pdo,$cid,$phone,$message,'birthday_person',null,$cfg))throw new RuntimeException('Não foi possível enviar pelo serviço WhatsApp conectado. Verifique a conexão e tente novamente.');
    $ok='Mensagem de aniversário enviada com sucesso.';
  }catch(Throwable $e){$err=$e->getMessage();}
}

finance_admin_start('Aniversário por WhatsApp','fin_pessoas');
finance_page_header('Mensagem de aniversário','Envio individual usando o modelo específico de Aniversário.',[['href'=>$returnTo,'label'=>'Voltar','class'=>'btn'],['href'=>'whatsapp_modelos.php?new=1&channel=whatsapp&context=aniversario','label'=>'🎂 Configurar modelo','class'=>'btn']]);
finance_tabs('fin_pessoas');
if($ok!=='')echo '<div class="msg ok">'.paw_h($ok).'</div>';if($err!=='')echo '<div class="msg err">'.paw_h($err).'</div>';
?>
<style>.paw-card{max-width:820px}.paw-person{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0}.paw-box{border:1px solid #dbe4f0;border-radius:13px;padding:11px;background:#f8fafc}.paw-message{min-height:180px;resize:vertical}.paw-wa{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important}@media(max-width:680px){.paw-person{grid-template-columns:1fr}}</style>
<div class="finance-card paw-card">
  <h2 class="finance-subtitle"><?=paw_h($name)?></h2>
  <div class="paw-person"><div class="paw-box"><span class="finance-muted">Nascimento</span><br><strong><?=paw_h(paw_date_br($birth))?></strong></div><div class="paw-box"><span class="finance-muted">Idade no ano</span><br><strong><?=$age?> anos</strong></div></div>
  <form method="post" class="finance-form"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="csrf" value="<?=paw_h(paw_csrf_token())?>"><input type="hidden" name="return_to" value="<?=paw_h($returnTo)?>">
    <label>WhatsApp<input name="phone" value="<?=paw_h($phone)?>" inputmode="numeric" autocomplete="off" placeholder="Ex.: 31999999999"></label>
    <label>Mensagem<textarea class="paw-message" readonly><?=paw_h($message)?></textarea></label>
    <div class="finance-muted"><?php if($model):?>Modelo em uso: <strong><?=paw_h($model['title']??'Aniversário')?></strong>.<?php else:?>Nenhum modelo padrão de Aniversário foi selecionado; o sistema está mostrando a mensagem segura padrão. Use <strong>Configurar modelo</strong> para personalizar.<?php endif;?></div>
    <div class="finance-form-actions"><button class="btn paw-wa" type="submit" name="mode" value="api">Enviar pelo WhatsApp</button><button class="btn" type="submit" name="mode" value="web">Abrir WhatsApp</button><a class="btn" href="<?=paw_h($returnTo)?>">Cancelar</a></div>
  </form>
</div>
<?php finance_admin_end(); ?>
