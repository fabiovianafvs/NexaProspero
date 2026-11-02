<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/itau_boleto.php';
require_once __DIR__ . '/../config/bank_integrations.php';
// V11.15: Caixa/Banco e a configuração de Cobrança/Itaú passam a respeitar a empresa do F11.
// No contexto cruzado não executamos DDL; o schema deve ter sido aplicado pelo Atualizador do Banco.
$financeCrossBankWrite = function_exists('finance_multi_company_bank_write_enabled') && finance_multi_company_bank_write_enabled($pdo);
$financeBillingConfigCross = $financeCrossBankWrite && function_exists('finance_multi_company_billing_config_enabled') && finance_multi_company_billing_config_enabled($pdo);
if (!$financeCrossBankWrite) {
  billing_ensure_schema($pdo);
  $bankIntegrationSchemaReady = bank_integration_ensure_schema($pdo, true);
} elseif (!billing_table_exists($pdo,'finance_bank_billing_configs')) {
  throw new RuntimeException('A estrutura de Cobranças/Boletos ainda não está disponível. Aplique as migrations pelo Atualizador do Banco antes de configurar o Itaú pelo F11.');
} else {
  $bankIntegrationSchemaReady = bank_integration_ensure_schema($pdo, false);
}

$cid = $financeCrossBankWrite && function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
if ($financeCrossBankWrite && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'financeiro_caixas')) { throw new RuntimeException('Seu perfil não possui permissão para Caixas e Bancos na empresa selecionada pelo F11.'); }
$err = '';
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($financeCrossBankWrite) {
      $csrf = trim((string)($_POST['mc_csrf'] ?? ''));
      if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) {
        throw new RuntimeException('Sessão Multiempresa expirada ou inválida. Atualize a página e tente novamente.');
      }
      $allowed = ['save','delete'];
      if ($financeBillingConfigCross) $allowed = array_merge($allowed,['itau_validate','itau_test_token','bank_credential_save','bank_credential_disable','bank_service_save','bank_import_legacy','bank_test_itau_oauth']);
      if (!in_array($action,$allowed,true)) throw new RuntimeException('V11.15: esta ação de Caixa/Banco ou Itaú ainda não está homologada para o F11.');
    }

    if (in_array($action, ['bank_credential_save','bank_credential_disable','bank_service_save','bank_import_legacy','bank_test_itau_oauth'], true)) {
      if (!$bankIntegrationSchemaReady) throw new RuntimeException('A estrutura do Hub de Integrações Bancárias ainda não está disponível nesta empresa. Acesse a empresa principal para preparar o banco.');
      $bankId = (int)($_POST['bank_id'] ?? 0);
      $bank = bank_integration_bank($pdo, $cid, $bankId);
      if (!$bank) throw new RuntimeException('Conta bancária inválida para esta empresa.');
      $baseReturn = 'financeiro_caixas_bancos.php?integrations=' . $bankId;

      if ($action === 'bank_credential_save') {
        $credentialId = bank_integration_save_credential($pdo, $cid, $bankId, [
          'id'=>(int)($_POST['credential_id'] ?? 0),
          'name'=>(string)($_POST['credential_name'] ?? ''),
          'provider'=>(string)($_POST['credential_provider'] ?? 'GENERIC'),
          'environment'=>(string)($_POST['credential_environment'] ?? 'HOMOLOGACAO'),
          'auth_type'=>(string)($_POST['credential_auth_type'] ?? 'OAUTH_MTLS'),
          'client_id'=>(string)($_POST['credential_client_id'] ?? ''),
          'client_secret'=>(string)($_POST['credential_client_secret'] ?? ''),
          'api_key'=>(string)($_POST['credential_api_key'] ?? ''),
          'username'=>(string)($_POST['credential_username'] ?? ''),
          'password'=>(string)($_POST['credential_password'] ?? ''),
          'cert_path'=>(string)($_POST['credential_cert_path'] ?? ''),
          'cert_password'=>(string)($_POST['credential_cert_password'] ?? ''),
          'key_path'=>(string)($_POST['credential_key_path'] ?? ''),
          'ca_path'=>(string)($_POST['credential_ca_path'] ?? ''),
          'oauth_url'=>(string)($_POST['credential_oauth_url'] ?? ''),
          'api_base_url'=>(string)($_POST['credential_api_base_url'] ?? ''),
          'token_scope'=>(string)($_POST['credential_token_scope'] ?? ''),
          'notes'=>(string)($_POST['credential_notes'] ?? ''),
          'active'=>!empty($_POST['credential_active']) ? 1 : 0,
        ]);
        $url = $baseReturn . '&ok=' . rawurlencode('Perfil de credenciais salvo.');
        finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url);
      }

      if ($action === 'bank_credential_disable') {
        $credentialId = (int)($_POST['credential_id'] ?? 0);
        $cred = bank_integration_credential($pdo, $cid, $credentialId);
        if (!$cred || (int)$cred['bank_account_id'] !== $bankId) throw new RuntimeException('Perfil de credenciais inválido.');
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_bank_services WHERE company_id=? AND bank_account_id=? AND credential_id=? AND enabled=1');
        $st->execute([$cid,$bankId,$credentialId]);
        if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Este perfil está vinculado a um serviço ativo. Desabilite ou troque o perfil do serviço antes de inativá-lo.');
        $pdo->prepare('UPDATE finance_bank_credentials SET active=0,updated_at=NOW() WHERE company_id=? AND bank_account_id=? AND id=?')->execute([$cid,$bankId,$credentialId]);
        $url = $baseReturn . '&ok=' . rawurlencode('Perfil de credenciais inativado.');
        finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url);
      }

      if ($action === 'bank_service_save') {
        bank_integration_save_service($pdo, $cid, $bankId, [
          'service_code'=>(string)($_POST['service_code'] ?? ''),
          'enabled'=>!empty($_POST['service_enabled']) ? 1 : 0,
          'provider'=>(string)($_POST['service_provider'] ?? 'GENERIC'),
          'integration_mode'=>(string)($_POST['service_integration_mode'] ?? 'API'),
          'environment'=>(string)($_POST['service_environment'] ?? 'HOMOLOGACAO'),
          'credential_id'=>(int)($_POST['service_credential_id'] ?? 0),
          'default_payment_method_id'=>(int)($_POST['service_default_payment_method_id'] ?? 0),
          'api_base_url'=>(string)($_POST['service_api_base_url'] ?? ''),
          'webhook_url'=>(string)($_POST['service_webhook_url'] ?? ''),
        ]);
        $url = $baseReturn . '&ok=' . rawurlencode('Serviço bancário salvo.');
        finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url);
      }

      if ($action === 'bank_import_legacy') {
        $import = bank_integration_import_legacy_billing($pdo, $cid, $bankId);
        $url = $baseReturn . '&ok=' . rawurlencode('Configuração atual de Boleto/Bolecode importada para o Hub. O Pix não foi habilitado automaticamente.');
        finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url);
      }

      if ($action === 'bank_test_itau_oauth') {
        $credentialId = (int)($_POST['credential_id'] ?? 0);
        $cred = bank_integration_credential($pdo, $cid, $credentialId);
        if (!$cred || (int)$cred['bank_account_id'] !== $bankId) throw new RuntimeException('Perfil de credenciais inválido.');
        if (strtoupper((string)$cred['provider']) !== 'ITAU') throw new RuntimeException('O teste OAuth desta versão está disponível para perfis Itaú.');
        $cfg = [
          'provider'=>'ITAU',
          'integration_mode'=>'API',
          'environment'=>(string)($cred['environment'] ?? 'HOMOLOGACAO'),
          'api_client_id'=>(string)($cred['client_id'] ?? ''),
          'api_client_secret'=>(string)($cred['client_secret'] ?? ''),
          'cert_path'=>(string)($cred['cert_path'] ?? ''),
          'key_path'=>(string)($cred['key_path'] ?? ''),
          'ca_path'=>(string)($cred['ca_path'] ?? ''),
          'oauth_url'=>(string)($cred['oauth_url'] ?? ''),
          'api_base_url'=>(string)($cred['api_base_url'] ?? ''),
        ];
        $token = itau_boleto_get_token($cfg);
        if (empty($token['access_token'])) throw new RuntimeException('O Itaú não retornou access_token para este perfil.');
        $ttl = max(0, (int)($token['expires_at'] ?? 0) - time());
        $url = $baseReturn . '&ok=' . rawurlencode('OAuth/mTLS Itaú validado para este perfil. Token obtido com validade aproximada de ' . $ttl . ' segundo(s). A autorização do produto (Pix/Bolecode) é validada ao chamar a API específica.');
        finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url);
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($financeCrossBankWrite) { $st=$pdo->prepare('SELECT id FROM finance_bank_accounts WHERE company_id=? AND id=? LIMIT 1');$st->execute([$cid,$id]);if(!$st->fetchColumn())throw new RuntimeException('Conta/Caixa não encontrado na empresa selecionada pelo F11.'); }
      if ($id > 0) $pdo->prepare('UPDATE finance_bank_accounts SET active=0, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$cid,$id]);
      finance_redirect('financeiro_caixas_bancos.php?ok=' . rawurlencode('Conta inativada.'));
    }
    if ($action === 'itau_validate') {
      $id = (int)($_POST['id'] ?? 0);
      try {
        $cfg = $id > 0 ? billing_bank_config($pdo,$cid,$id) : [];
        if (!$cfg) throw new RuntimeException('Configuração de cobrança da conta não encontrada. Salve a conta antes de executar o diagnóstico Itaú.');
        $diag = itau_boleto_validate_config($cfg, false);
        $problems = array_values(array_filter($diag['checks'], static fn(array $c): bool => in_array((string)($c['level'] ?? ''), ['error','warning'], true)));
        if ($problems) {
          $msg = 'Diagnóstico local Itaú: ' . implode(' | ', array_map(static fn(array $c): string => ($c['label'] ?? 'Item') . ': ' . ($c['message'] ?? ''), $problems));
          finance_redirect('financeiro_caixas_bancos.php?edit=' . $id . '&err=' . rawurlencode($msg));
        }
        finance_redirect('financeiro_caixas_bancos.php?edit=' . $id . '&ok=' . rawurlencode('Diagnóstico local Itaú concluído: configuração, certificado e chave estão consistentes.'));
      } catch (Throwable $e) {
        finance_redirect('financeiro_caixas_bancos.php' . ($id > 0 ? '?edit=' . $id . '&' : '?') . 'err=' . rawurlencode($e->getMessage()));
      }
    }
    if ($action === 'itau_test_token') {
      $id = (int)($_POST['id'] ?? 0);
      try {
        $cfg = $id > 0 ? billing_bank_config($pdo,$cid,$id) : [];
        if (!$cfg) throw new RuntimeException('Configuração de cobrança da conta não encontrada.');
        $result = itau_boleto_test_token($cfg);
        finance_redirect('financeiro_caixas_bancos.php?edit=' . $id . '&ok=' . rawurlencode('OAuth Itaú validado com sucesso. HTTP ' . (int)$result['http_status'] . ', token recebido com validade de ' . (int)$result['expires_in'] . ' segundo(s).'));
      } catch (Throwable $e) {
        finance_redirect('financeiro_caixas_bancos.php' . ($id > 0 ? '?edit=' . $id . '&' : '?') . 'err=' . rawurlencode('Falha no OAuth Itaú: ' . $e->getMessage()));
      }
    }
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe o nome da conta/caixa.');
    $type = in_array((string)($_POST['type'] ?? ''), ['CAIXA','BANCO','PIX','CARTAO','OUTRO'], true) ? (string)$_POST['type'] : 'BANCO';
    $initialBalance=finance_money_in($_POST['initial_balance'] ?? 0);
    if($financeCrossBankWrite && $id>0){$existingBank=finance_query_one($pdo,'SELECT * FROM finance_bank_accounts WHERE company_id=? AND id=?',[$cid,$id]);if(!$existingBank)throw new RuntimeException('Conta/Caixa não encontrado na empresa selecionada pelo F11.');$initialBalance=(float)($existingBank['initial_balance']??0);}
    $params = [$name,$type,trim((string)($_POST['bank_name'] ?? '')),trim((string)($_POST['agency'] ?? '')),trim((string)($_POST['account_number'] ?? '')),$initialBalance,isset($_POST['active']) ? 1 : 0];
    if ($id > 0) {
      $params[] = $cid; $params[] = $id;
      $pdo->prepare('UPDATE finance_bank_accounts SET name=?, type=?, bank_name=?, agency=?, account_number=?, initial_balance=?, active=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute($params);
    } else {
      array_unshift($params, $cid);
      $pdo->prepare('INSERT INTO finance_bank_accounts (company_id,name,type,bank_name,agency,account_number,initial_balance,active) VALUES (?,?,?,?,?,?,?,?)')->execute($params);
      $id=(int)$pdo->lastInsertId();
    }
    $billingEnabled=!empty($_POST['billing_enabled'])?1:0;
    $provider=strtoupper(trim((string)($_POST['billing_provider']??'GENERIC'))); if(!in_array($provider,['GENERIC','ITAU','SICOOB'],true))$provider='GENERIC';
    $integrationMode=strtoupper(trim((string)($_POST['integration_mode']??'API'))); if(!in_array($integrationMode,['API','CNAB240','CNAB400'],true))$integrationMode='API';
    $environment=strtoupper(trim((string)($_POST['billing_environment']??'HOMOLOGACAO'))); if(!in_array($environment,['HOMOLOGACAO','PRODUCAO'],true))$environment='HOMOLOGACAO';
    $defaultPayment=(int)($_POST['default_payment_method_id']??0)?:null;
    if($financeCrossBankWrite && $defaultPayment){
      $stPayment=$pdo->prepare('SELECT id FROM finance_payment_methods WHERE company_id=? AND id=? AND is_active=1 LIMIT 1');
      $stPayment->execute([$cid,$defaultPayment]);
      if(!$stPayment->fetchColumn()) throw new RuntimeException('A forma de pagamento padrão não pertence à empresa selecionada pelo F11.');
    }
    $secret=trim((string)($_POST['api_client_secret']??''));
    $oldCfg=billing_bank_config($pdo,$cid,$id); if($secret===''&&$oldCfg)$secret=(string)($oldCfg['api_client_secret']??'');
    $cfgArgs=[$cid,$id,$billingEnabled,$provider,$integrationMode,$environment,trim((string)($_POST['bank_code']??''))?:null,trim((string)($_POST['convenio']??''))?:null,trim((string)($_POST['carteira']??''))?:null,trim((string)($_POST['variacao']??''))?:null,$defaultPayment,trim((string)($_POST['api_client_id']??''))?:null,$secret?:null,trim((string)($_POST['cert_path']??''))?:null,trim((string)($_POST['key_path']??''))?:null,trim((string)($_POST['ca_path']??''))?:null,trim((string)($_POST['oauth_url']??''))?:null,trim((string)($_POST['api_base_url']??''))?:null,$integrationMode==='CNAB240'?'240':($integrationMode==='CNAB400'?'400':null),max(0,(int)($_POST['protest_days']??0)),finance_money_in($_POST['fine_percent']??0),finance_money_in($_POST['interest_month_percent']??0),trim((string)($_POST['billing_instructions']??''))?:null];
    $pdo->prepare('INSERT INTO finance_bank_billing_configs(company_id,bank_account_id,enabled,provider,integration_mode,environment,bank_code,convenio,carteira,variacao,default_payment_method_id,api_client_id,api_client_secret,cert_path,key_path,ca_path,oauth_url,api_base_url,cnab_layout,protest_days,fine_percent,interest_month_percent,instructions) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),provider=VALUES(provider),integration_mode=VALUES(integration_mode),environment=VALUES(environment),bank_code=VALUES(bank_code),convenio=VALUES(convenio),carteira=VALUES(carteira),variacao=VALUES(variacao),default_payment_method_id=VALUES(default_payment_method_id),api_client_id=VALUES(api_client_id),api_client_secret=VALUES(api_client_secret),cert_path=VALUES(cert_path),key_path=VALUES(key_path),ca_path=VALUES(ca_path),oauth_url=VALUES(oauth_url),api_base_url=VALUES(api_base_url),cnab_layout=VALUES(cnab_layout),protest_days=VALUES(protest_days),fine_percent=VALUES(fine_percent),interest_month_percent=VALUES(interest_month_percent),instructions=VALUES(instructions),updated_at=NOW()')->execute($cfgArgs);
    // Persistência explícita do ambiente. Além do UPSERT acima, fazemos a gravação
    // e a leitura de confirmação para evitar que instalações antigas mantenham o valor anterior.
    $pdo->prepare('UPDATE finance_bank_billing_configs SET environment=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')->execute([$environment,$cid,$id]);
    $checkEnv=$pdo->prepare('SELECT environment FROM finance_bank_billing_configs WHERE company_id=? AND bank_account_id=? LIMIT 1');
    $checkEnv->execute([$cid,$id]);
    $savedEnvironment=strtoupper((string)($checkEnv->fetchColumn()?:''));
    if($savedEnvironment!==$environment) throw new RuntimeException('Não foi possível confirmar a gravação do ambiente da integração.');
    $saveMsg=$financeCrossBankWrite?'V11.15: conta e configuração de cobrança/Itaú salvas na empresa selecionada pelo F11.':'Conta e configuração de cobrança salvas.';
    finance_redirect('financeiro_caixas_bancos.php?ok=' . rawurlencode($saveMsg.' Ambiente: '.($environment==='PRODUCAO'?'Produção':'Homologação').'.'));
  }
} catch (Throwable $e) { $err = $e->getMessage(); }
$editId = (int)($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
$edit = $editId ? finance_query_one($pdo, 'SELECT * FROM finance_bank_accounts WHERE company_id=? AND id=?', [$cid,$editId]) : null;
if($edit){
  // V11.44: billing_bank_config() retorna bc.* e, portanto, também possui uma coluna `id`.
  // Um array_merge direto sobrescrevia o ID da conta pelo ID da configuração de cobrança.
  // O formulário então postava o ID errado e o UPDATE da conta não encontrava o registro,
  // dando a impressão de que o botão Salvar funcionava sem persistir os dados.
  $bankAccountEditId=(int)$edit['id'];
  $bc=billing_bank_config($pdo,$cid,$editId);
  if($bc){
    unset($bc['id'],$bc['company_id'],$bc['bank_account_id'],$bc['bank_name'],$bc['agency'],$bc['account_number']);
    $edit=array_merge($edit,$bc);
  }
  $edit['id']=$bankAccountEditId;
}
$paymentMethods=finance_query_all($pdo,'SELECT id,name,type FROM finance_payment_methods WHERE company_id=? AND is_active=1 ORDER BY sort_order,name',[$cid]);
$integrationBankId = (int)($_GET['integrations'] ?? ($_POST['bank_id'] ?? 0));
$integrationBank = $integrationBankId > 0 ? bank_integration_bank($pdo,$cid,$integrationBankId) : [];
$integrationCredentials = ($integrationBank && $bankIntegrationSchemaReady) ? bank_integration_credentials($pdo,$cid,$integrationBankId,false) : [];
$integrationServicesCurrent = ($integrationBank && $bankIntegrationSchemaReady) ? bank_integration_services_for_bank($pdo,$cid,$integrationBankId) : [];
$integrationEditCredentialId = (int)($_GET['edit_credential'] ?? ($_POST['credential_id'] ?? 0));
$integrationEditCredential = $integrationEditCredentialId > 0 ? bank_integration_credential($pdo,$cid,$integrationEditCredentialId) : [];
if ($integrationEditCredential && (int)($integrationEditCredential['bank_account_id'] ?? 0) !== $integrationBankId) $integrationEditCredential = [];
$integrationNewCredential = isset($_GET['new_credential']) || ($_SERVER['REQUEST_METHOD']==='POST' && $err!=='' && (string)($_POST['action'] ?? '')==='bank_credential_save' && $integrationEditCredentialId<=0);
$integrationCredentialModalOpen = $integrationNewCredential || !empty($integrationEditCredential);
$integrationEditServiceCode = strtoupper(trim((string)($_GET['edit_service'] ?? ($_POST['service_code'] ?? ''))));
if (!isset(bank_integration_services()[$integrationEditServiceCode])) $integrationEditServiceCode = '';
$integrationNewService = isset($_GET['new_service']) || ($_SERVER['REQUEST_METHOD']==='POST' && $err!=='' && (string)($_POST['action'] ?? '')==='bank_service_save' && !isset($integrationServicesCurrent[$integrationEditServiceCode]));
$integrationServiceModalOpen = $integrationNewService || $integrationEditServiceCode!=='';
$integrationLegacy = $integrationBank ? billing_bank_config($pdo,$cid,$integrationBankId) : [];
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '') $edit = $_POST;
$q = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$where = ['company_id=?','active=1']; $params=[$cid];
if ($q !== '') { $where[] = '(name LIKE ? OR bank_name LIKE ? OR account_number LIKE ?)'; $like=nexa_search_like($q); array_push($params,$like,$like,$like); }
if (in_array($typeFilter, ['CAIXA','BANCO','PIX','CARTAO','OUTRO'], true)) { $where[] = 'type=?'; $params[] = $typeFilter; }
$rows = finance_query_all($pdo, 'SELECT * FROM finance_bank_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC', $params);
finance_admin_start('Financeiro - Caixas e Bancos', 'fin_caixas');
finance_page_header('Caixas e Bancos', 'Cadastre contas bancárias, caixa interno, Pix, cartão e demais locais de movimentação.', [
  ['href'=>'financeiro_boletos.php','label'=>'Cobranças/Boletos','class'=>'btn'],
  ['href'=>'financeiro_caixas_bancos.php?new=1','label'=>'+ Nova conta','class'=>'btn btn-primary'],
]);
// V11.51.6: remove as abas gerais apenas desta tela para ampliar a listagem.
finance_flash(); ?>
<style>.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.finance-filter-card .finance-filter{gap:8px 10px}.finance-filter-card .finance-filter label{margin-bottom:3px}.finance-filter-card .finance-filter input,.finance-filter-card .finance-filter select{min-height:39px}.finance-filter-card .finance-filter-actions .btn{min-height:39px}</style>
<?php if($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<div class="finance-card finance-filter-card"><form method="get" class="finance-filter"><div class="wide"><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Nome, banco ou conta"></div><div><label>Tipo</label><select name="type"><option value="">Todos</option><?php foreach(['CAIXA'=>'Caixa','BANCO'=>'Banco','PIX'=>'Pix','CARTAO'=>'Cartão','OUTRO'=>'Outro'] as $k=>$v): ?><option value="<?= $k ?>" <?= $typeFilter===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div><div class="finance-filter-actions"><button class="btn">Filtrar</button><a class="btn" href="financeiro_caixas_bancos.php">Limpar</a></div></form></div>

<div class="finance-card"><div class="finance-card-head"><h2 class="finance-subtitle">Contas cadastradas</h2><span class="finance-muted"><?= count($rows) ?> conta(s)</span></div><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Nome</th><th>Tipo</th><th>Banco</th><th>Agência/Conta</th><th>Saldo inicial</th><th>Cobrança</th><th>Ações</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="7" class="finance-empty">Nenhuma conta encontrada.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr><td data-label="Nome" class="finance-primary"><strong><?= finance_h($r['name']) ?></strong></td><td data-label="Tipo"><?= finance_h($r['type']) ?></td><td data-label="Banco"><?= finance_h($r['bank_name'] ?? '') ?></td><td data-label="Agência/Conta"><span class="finance-muted"><?= finance_h($r['agency'] ?? '') ?> / <?= finance_h($r['account_number'] ?? '') ?></span></td><td data-label="Saldo inicial" class="finance-value">R$ <?= finance_money_br($r['initial_balance'] ?? 0) ?></td><td data-label="Cobrança"><?php $bcrow=billing_bank_config($pdo,$cid,(int)$r['id']); ?><?= !empty($bcrow['enabled']) ? finance_h(($bcrow['provider']??'GENERIC').' / '.($bcrow['integration_mode']??'')) : '<span class="finance-muted">Desativada</span>' ?></td><td data-label="Ações"><div class="finance-inline-actions"><a class="btn" href="<?= finance_h(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_caixas_bancos.php?edit='.(int)$r['id']) : ('?edit='.(int)$r['id'])) ?>">Editar</a><?php $hubUrl=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_caixas_bancos.php?integrations='.(int)$r['id']):('financeiro_caixas_bancos.php?integrations='.(int)$r['id']); ?><a class="btn" href="<?= finance_h($hubUrl) ?>">Integrações</a><?php if(!empty($bcrow['enabled']) && strtoupper((string)($bcrow['provider']??''))==='ITAU' && strtoupper((string)($bcrow['integration_mode']??''))==='API'):?><?php $itauUrl=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_itau.php?bank='.(int)$r['id']):('financeiro_itau.php?bank='.(int)$r['id']); ?><a class="btn" href="<?= finance_h($itauUrl) ?>">Configurar Itaú</a><form method="post"><?php if($financeCrossBankWrite):?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif;?><input type="hidden" name="action" value="itau_validate"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn" title="Valida configuração, arquivos, certificado e chave sem chamar a API de boletos">Diagnóstico Itaú</button></form><?php endif;?><?php if(!empty($bcrow['enabled']) && strtoupper((string)($bcrow['provider']??''))==='SICOOB' && strtoupper((string)($bcrow['integration_mode']??''))==='API'): ?><?php $sicoobUrl=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_sicoob.php?bank='.(int)$r['id']):('financeiro_sicoob.php?bank='.(int)$r['id']); ?><a class="btn" href="<?= finance_h($sicoobUrl) ?>">Configurar Sicoob</a><?php endif; ?><form method="post" onsubmit="return confirm('Inativar esta conta?')"><?php if($financeCrossBankWrite):?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif;?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-danger">Inativar</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php if($integrationBank): ?>
<div class="finance-modal" role="dialog" aria-modal="true">
  <div class="finance-modal-dialog finance-modal-wide" style="max-width:1180px;">
    <div class="finance-modal-head">
      <div>
        <h2>Integrações bancárias · <?= finance_h((string)($integrationBank['name'] ?? 'Conta')) ?></h2>
        <p class="finance-muted">Configure credenciais uma vez e vincule cada produto bancário separadamente. Assim Boleto/Bolecode, Pix, Extrato e outros serviços podem usar a mesma credencial ou credenciais diferentes, conforme a autorização do banco.</p>
      </div>
      <a class="finance-modal-close" href="financeiro_caixas_bancos.php">×</a>
    </div>

    <?php if(!$bankIntegrationSchemaReady): ?>
      <div class="msg err">A estrutura do Hub de Integrações Bancárias ainda não está preparada nesta empresa. Acesse esta tela pela empresa principal para atualizar automaticamente o banco.</div>
    <?php else: ?>
      <div class="msg ok" style="margin-bottom:14px;"><strong>Arquitetura multibanco.</strong> Provedores preparados: Itaú, Sicoob, Caixa, Bradesco, Santander, Inter e Cora. Nesta versão, os conectores operacionais já existentes continuam homologados para <strong>Itaú Boleto/Bolecode</strong> e <strong>Itaú Pix Recebimentos</strong>; os demais ficam estruturados para receber os adaptadores específicos sem mudar o cadastro.</div>

      <?php if($integrationLegacy): ?>
      <div class="finance-form-section" style="margin-bottom:14px;">
        <h3>Configuração atual de Boleto/Bolecode</h3>
        <p class="finance-muted">Foi encontrada a configuração legada <?= finance_h((string)($integrationLegacy['provider'] ?? '')) ?> / <?= finance_h((string)($integrationLegacy['integration_mode'] ?? '')) ?>. Você pode importá-la para um perfil reutilizável. <strong>O Pix não será habilitado automaticamente.</strong></p>
        <form method="post" class="finance-inline-actions">
          <?php if($financeCrossBankWrite): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif; ?>
          <input type="hidden" name="action" value="bank_import_legacy">
          <input type="hidden" name="bank_id" value="<?= (int)$integrationBankId ?>">
          <button class="btn" type="submit">Importar credencial atual</button>
        </form>
      </div>
      <?php endif; ?>

      <?php
        $hubBaseUrl='financeiro_caixas_bancos.php?integrations='.(int)$integrationBankId;
        if(function_exists('multi_company_context_url')) $hubBaseUrl=multi_company_context_url($hubBaseUrl);
        $newCredentialUrl='financeiro_caixas_bancos.php?integrations='.(int)$integrationBankId.'&new_credential=1';
        if(function_exists('multi_company_context_url')) $newCredentialUrl=multi_company_context_url($newCredentialUrl);
        $newServiceUrl='financeiro_caixas_bancos.php?integrations='.(int)$integrationBankId.'&new_service=1';
        if(function_exists('multi_company_context_url')) $newServiceUrl=multi_company_context_url($newServiceUrl);
        $credentialNameById=[];
        foreach($integrationCredentials as $c) $credentialNameById[(int)$c['id']] = (string)$c['name'];
        $paymentNameById=[];
        foreach($paymentMethods as $pm) $paymentNameById[(int)$pm['id']] = (string)$pm['name'];
      ?>

      <div class="finance-form-section" style="margin-bottom:14px;">
        <div class="finance-card-head">
          <div><h3 style="margin:0;">Perfis de credenciais</h3><p class="finance-muted" style="margin:4px 0 0;">Credenciais reutilizáveis pelos serviços bancários desta conta.</p></div>
          <div class="finance-inline-actions"><span class="finance-muted"><?= count($integrationCredentials) ?> perfil(is)</span><a class="btn btn-primary" href="<?= finance_h($newCredentialUrl) ?>">+ Novo perfil</a></div>
        </div>
        <div class="table-wrap" style="margin-top:10px;"><table class="finance-list-table"><thead><tr><th>Nome</th><th>Banco</th><th>Ambiente</th><th>Autenticação</th><th>Status</th><th>Ações</th></tr></thead><tbody>
          <?php if(!$integrationCredentials): ?><tr><td colspan="6" class="finance-empty">Nenhum perfil de credenciais cadastrado.</td></tr><?php endif; ?>
          <?php foreach($integrationCredentials as $cred): ?>
          <tr>
            <td data-label="Nome"><strong><?= finance_h((string)$cred['name']) ?></strong></td>
            <td data-label="Banco"><?= finance_h(bank_integration_providers()[(string)$cred['provider']] ?? (string)$cred['provider']) ?></td>
            <td data-label="Ambiente"><?= finance_h((string)$cred['environment']) ?></td>
            <td data-label="Autenticação"><?= finance_h(bank_integration_auth_types()[(string)$cred['auth_type']] ?? (string)$cred['auth_type']) ?></td>
            <td data-label="Status"><?= !empty($cred['active']) ? '<strong style="color:#047857">Ativo</strong>' : '<span class="finance-muted">Inativo</span>' ?></td>
            <td data-label="Ações"><div class="finance-inline-actions">
              <?php $editCredUrl='financeiro_caixas_bancos.php?integrations='.(int)$integrationBankId.'&edit_credential='.(int)$cred['id']; if(function_exists('multi_company_context_url'))$editCredUrl=multi_company_context_url($editCredUrl); ?>
              <a class="btn" href="<?= finance_h($editCredUrl) ?>">Editar</a>
              <?php if(strtoupper((string)$cred['provider'])==='ITAU' && !empty($cred['active'])): ?>
              <form method="post"><?php if($financeCrossBankWrite): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif; ?><input type="hidden" name="action" value="bank_test_itau_oauth"><input type="hidden" name="bank_id" value="<?= (int)$integrationBankId ?>"><input type="hidden" name="credential_id" value="<?= (int)$cred['id'] ?>"><button class="btn" type="submit">Testar OAuth</button></form>
              <?php endif; ?>
              <?php if(!empty($cred['active'])): ?><form method="post" onsubmit="return confirm('Inativar este perfil de credenciais?')"><?php if($financeCrossBankWrite): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif; ?><input type="hidden" name="action" value="bank_credential_disable"><input type="hidden" name="bank_id" value="<?= (int)$integrationBankId ?>"><input type="hidden" name="credential_id" value="<?= (int)$cred['id'] ?>"><button class="btn btn-danger" type="submit">Inativar</button></form><?php endif; ?>
            </div></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>

      <div class="finance-form-section">
        <div class="finance-card-head">
          <div><h3 style="margin:0;">Integrações desta conta</h3><p class="finance-muted" style="margin:4px 0 0;">Cada integração fica em uma linha. Use Editar para abrir a parametrização completa.</p></div>
          <div class="finance-inline-actions"><span class="finance-muted"><?= count($integrationServicesCurrent) ?> integração(ões)</span><a class="btn btn-primary" href="<?= finance_h($newServiceUrl) ?>">+ Nova integração</a></div>
        </div>
        <div class="table-wrap" style="margin-top:10px;"><table class="finance-list-table"><thead><tr><th>Integração</th><th>Provedor</th><th>Ambiente</th><th>Credencial</th><th>Forma padrão</th><th>Status</th><th>Conector</th><th>Ações</th></tr></thead><tbody>
          <?php if(!$integrationServicesCurrent): ?><tr><td colspan="8" class="finance-empty">Nenhuma integração cadastrada. Clique em <strong>Nova integração</strong> para começar.</td></tr><?php endif; ?>
          <?php foreach($integrationServicesCurrent as $serviceCode=>$svc): $serviceLabel=bank_integration_services()[$serviceCode] ?? $serviceCode; $connectorStatus=bank_integration_connector_status((string)($svc['provider'] ?? 'GENERIC'),$serviceCode); ?>
          <tr>
            <td data-label="Integração"><strong><?= finance_h($serviceLabel) ?></strong><div class="finance-muted" style="font-size:11px;margin-top:2px;"><?= finance_h($serviceCode) ?></div></td>
            <td data-label="Provedor"><?= finance_h(bank_integration_providers()[(string)($svc['provider'] ?? 'GENERIC')] ?? (string)($svc['provider'] ?? 'GENERIC')) ?></td>
            <td data-label="Ambiente"><?= finance_h((string)($svc['environment'] ?? 'HOMOLOGACAO')) ?></td>
            <td data-label="Credencial"><?= finance_h($credentialNameById[(int)($svc['credential_id'] ?? 0)] ?? 'Sem perfil') ?></td>
            <td data-label="Forma padrão"><?= finance_h($paymentNameById[(int)($svc['default_payment_method_id'] ?? 0)] ?? 'Sem padrão') ?></td>
            <td data-label="Status"><?= !empty($svc['enabled']) ? '<strong style="color:#047857">Ativa</strong>' : '<span class="finance-muted">Desativada</span>' ?></td>
            <td data-label="Conector"><?= $connectorStatus==='ATIVO' ? '<strong style="color:#047857">Disponível</strong>' : '<span class="finance-muted">Adaptador pendente</span>' ?></td>
            <td data-label="Ações"><?php $editSvcUrl='financeiro_caixas_bancos.php?integrations='.(int)$integrationBankId.'&edit_service='.rawurlencode((string)$serviceCode); if(function_exists('multi_company_context_url'))$editSvcUrl=multi_company_context_url($editSvcUrl); ?><a class="btn" href="<?= finance_h($editSvcUrl) ?>">Editar</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>

      <?php if($integrationCredentialModalOpen):
        $credForm=$integrationEditCredential ?: [];
        $credPostError=$_SERVER['REQUEST_METHOD']==='POST' && $err!=='' && (string)($_POST['action'] ?? '')==='bank_credential_save';
        if($credPostError){
          $credForm=array_merge($credForm,[
            'id'=>(int)($_POST['credential_id'] ?? 0),
            'name'=>(string)($_POST['credential_name'] ?? ''),
            'provider'=>(string)($_POST['credential_provider'] ?? 'ITAU'),
            'environment'=>(string)($_POST['credential_environment'] ?? 'HOMOLOGACAO'),
            'auth_type'=>(string)($_POST['credential_auth_type'] ?? 'OAUTH_MTLS'),
            'client_id'=>(string)($_POST['credential_client_id'] ?? ''),
            'username'=>(string)($_POST['credential_username'] ?? ''),
            'cert_path'=>(string)($_POST['credential_cert_path'] ?? ''),
            'key_path'=>(string)($_POST['credential_key_path'] ?? ''),
            'ca_path'=>(string)($_POST['credential_ca_path'] ?? ''),
            'oauth_url'=>(string)($_POST['credential_oauth_url'] ?? ''),
            'api_base_url'=>(string)($_POST['credential_api_base_url'] ?? ''),
            'token_scope'=>(string)($_POST['credential_token_scope'] ?? ''),
            'notes'=>(string)($_POST['credential_notes'] ?? ''),
            'active'=>!empty($_POST['credential_active'])?1:0,
          ]);
        }
      ?>
      <div class="finance-modal" role="dialog" aria-modal="true" style="z-index:460;">
        <div class="finance-modal-dialog finance-modal-wide" style="max-width:1040px;">
          <div class="finance-modal-head"><div><h2><?= !empty($credForm['id']) ? 'Editar perfil de credenciais' : 'Novo perfil de credenciais' ?></h2><p class="finance-muted">Cadastre a autenticação uma vez e reutilize nos serviços autorizados pelo banco.</p></div><a class="finance-modal-close" href="<?= finance_h($hubBaseUrl) ?>">×</a></div>
          <form method="post" class="finance-form">
            <?php if($financeCrossBankWrite): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="bank_credential_save"><input type="hidden" name="bank_id" value="<?= (int)$integrationBankId ?>"><input type="hidden" name="credential_id" value="<?= (int)($credForm['id'] ?? 0) ?>">
            <div class="finance-form-section"><h3>Identificação</h3><div class="finance-form-grid">
              <div><label>Nome do perfil *</label><input name="credential_name" required value="<?= finance_h((string)($credForm['name'] ?? '')) ?>" placeholder="Ex.: Itaú Produção"></div>
              <div><label>Banco / provedor</label><select name="credential_provider"><?php foreach(bank_integration_providers() as $k=>$v): ?><option value="<?= finance_h($k) ?>" <?= strtoupper((string)($credForm['provider'] ?? 'ITAU'))===$k?'selected':'' ?>><?= finance_h($v) ?></option><?php endforeach; ?></select></div>
              <div><label>Ambiente</label><select name="credential_environment"><option value="HOMOLOGACAO" <?= strtoupper((string)($credForm['environment'] ?? 'HOMOLOGACAO'))==='HOMOLOGACAO'?'selected':'' ?>>Homologação</option><option value="PRODUCAO" <?= strtoupper((string)($credForm['environment'] ?? ''))==='PRODUCAO'?'selected':'' ?>>Produção</option></select></div>
              <div><label>Autenticação</label><select name="credential_auth_type"><?php foreach(bank_integration_auth_types() as $k=>$v): ?><option value="<?= finance_h($k) ?>" <?= strtoupper((string)($credForm['auth_type'] ?? 'OAUTH_MTLS'))===$k?'selected':'' ?>><?= finance_h($v) ?></option><?php endforeach; ?></select></div>
              <label class="checkline"><input type="checkbox" name="credential_active" value="1" <?= !isset($credForm['active']) || !empty($credForm['active'])?'checked':'' ?>> Perfil ativo</label>
            </div></div>
            <div class="finance-form-section"><h3>Autenticação e certificado</h3><div class="finance-form-grid">
              <div><label>Client ID</label><input name="credential_client_id" value="<?= finance_h((string)($credForm['client_id'] ?? '')) ?>"></div>
              <div><label>Client Secret</label><input type="password" name="credential_client_secret" value="" placeholder="<?= !empty($credForm['id']) ? 'Deixe vazio para manter o atual' : '' ?>"></div>
              <div><label>API Key</label><input type="password" name="credential_api_key" value="" placeholder="<?= !empty($credForm['id']) ? 'Deixe vazio para manter a atual' : '' ?>"></div>
              <div><label>Usuário</label><input name="credential_username" value="<?= finance_h((string)($credForm['username'] ?? '')) ?>"></div>
              <div><label>Senha</label><input type="password" name="credential_password" value="" placeholder="<?= !empty($credForm['id']) ? 'Deixe vazio para manter a atual' : '' ?>"></div>
              <div><label>Certificado .crt/.pem/.pfx</label><input name="credential_cert_path" value="<?= finance_h((string)($credForm['cert_path'] ?? '')) ?>"></div>
              <div><label>Senha do certificado</label><input type="password" name="credential_cert_password" value="" placeholder="<?= !empty($credForm['id']) ? 'Deixe vazio para manter a atual' : '' ?>"></div>
              <div><label>Chave privada .key</label><input name="credential_key_path" value="<?= finance_h((string)($credForm['key_path'] ?? '')) ?>"></div>
              <div><label>CA / cadeia</label><input name="credential_ca_path" value="<?= finance_h((string)($credForm['ca_path'] ?? '')) ?>"></div>
              <div><label>OAuth URL</label><input name="credential_oauth_url" value="<?= finance_h((string)($credForm['oauth_url'] ?? '')) ?>"></div>
              <div><label>Base URL padrão</label><input name="credential_api_base_url" value="<?= finance_h((string)($credForm['api_base_url'] ?? '')) ?>"></div>
              <div><label>Scope do token</label><input name="credential_token_scope" value="<?= finance_h((string)($credForm['token_scope'] ?? '')) ?>"></div>
              <div class="span-2"><label>Observações</label><textarea name="credential_notes" rows="2"><?= finance_h((string)($credForm['notes'] ?? '')) ?></textarea></div>
            </div></div>
            <div class="finance-form-actions"><a class="btn" href="<?= finance_h($hubBaseUrl) ?>">Cancelar</a><button class="btn btn-primary" type="submit">Salvar perfil</button></div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if($integrationServiceModalOpen):
        $serviceFormCode=$integrationEditServiceCode;
        $svc=$serviceFormCode!=='' ? ($integrationServicesCurrent[$serviceFormCode] ?? []) : [];
        $svcPostError=$_SERVER['REQUEST_METHOD']==='POST' && $err!=='' && (string)($_POST['action'] ?? '')==='bank_service_save';
        if($svcPostError){
          $serviceFormCode=strtoupper(trim((string)($_POST['service_code'] ?? $serviceFormCode)));
          $svc=[
            'enabled'=>!empty($_POST['service_enabled'])?1:0,
            'provider'=>(string)($_POST['service_provider'] ?? 'ITAU'),
            'integration_mode'=>(string)($_POST['service_integration_mode'] ?? 'API'),
            'environment'=>(string)($_POST['service_environment'] ?? 'HOMOLOGACAO'),
            'credential_id'=>(int)($_POST['service_credential_id'] ?? 0),
            'default_payment_method_id'=>(int)($_POST['service_default_payment_method_id'] ?? 0),
            'api_base_url'=>(string)($_POST['service_api_base_url'] ?? ''),
            'webhook_url'=>(string)($_POST['service_webhook_url'] ?? ''),
          ];
        }
        $serviceIsNew=$integrationNewService || !isset($integrationServicesCurrent[$serviceFormCode]);
      ?>
      <div class="finance-modal" role="dialog" aria-modal="true" style="z-index:470;">
        <div class="finance-modal-dialog finance-modal-wide" style="max-width:940px;">
          <div class="finance-modal-head"><div><h2><?= $serviceIsNew ? 'Nova integração bancária' : 'Editar integração bancária' ?></h2><p class="finance-muted">Parametrize o serviço, a credencial e a forma de pagamento padrão desta conta.</p></div><a class="finance-modal-close" href="<?= finance_h($hubBaseUrl) ?>">×</a></div>
          <form method="post" class="finance-form">
            <?php if($financeCrossBankWrite): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="bank_service_save"><input type="hidden" name="bank_id" value="<?= (int)$integrationBankId ?>">
            <div class="finance-form-section"><h3>Integração</h3><div class="finance-form-grid">
              <?php if($serviceIsNew): ?>
              <div class="span-2"><label>Tipo de integração *</label><select name="service_code" required><option value="">Selecione...</option><?php foreach(bank_integration_services() as $k=>$v): if(isset($integrationServicesCurrent[$k]) && $k!==$serviceFormCode) continue; ?><option value="<?= finance_h($k) ?>" <?= $serviceFormCode===$k?'selected':'' ?>><?= finance_h($v) ?></option><?php endforeach; ?></select><div class="hint">Cada tipo é cadastrado uma vez por conta. Depois, use Editar na lista.</div></div>
              <?php else: ?><div class="span-2"><label>Tipo de integração</label><input value="<?= finance_h(bank_integration_services()[$serviceFormCode] ?? $serviceFormCode) ?>" readonly><input type="hidden" name="service_code" value="<?= finance_h($serviceFormCode) ?>"></div><?php endif; ?>
              <label class="checkline"><input type="checkbox" name="service_enabled" value="1" <?= !isset($svc['enabled']) || !empty($svc['enabled'])?'checked':'' ?>> Integração ativa</label>
              <div><label>Banco / provedor</label><select name="service_provider"><?php foreach(bank_integration_providers() as $k=>$v): ?><option value="<?= finance_h($k) ?>" <?= strtoupper((string)($svc['provider'] ?? 'ITAU'))===$k?'selected':'' ?>><?= finance_h($v) ?></option><?php endforeach; ?></select></div>
              <div><label>Forma de integração</label><select name="service_integration_mode"><?php foreach(['API'=>'API','CNAB240'=>'CNAB 240','CNAB400'=>'CNAB 400','OFX'=>'OFX','MANUAL'=>'Manual'] as $k=>$v): ?><option value="<?= $k ?>" <?= strtoupper((string)($svc['integration_mode'] ?? 'API'))===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
              <div><label>Ambiente</label><select name="service_environment"><option value="HOMOLOGACAO" <?= strtoupper((string)($svc['environment'] ?? 'HOMOLOGACAO'))==='HOMOLOGACAO'?'selected':'' ?>>Homologação</option><option value="PRODUCAO" <?= strtoupper((string)($svc['environment'] ?? ''))==='PRODUCAO'?'selected':'' ?>>Produção</option></select></div>
              <div><label>Perfil de credenciais</label><select name="service_credential_id"><option value="">Sem perfil</option><?php foreach($integrationCredentials as $cred): if(empty($cred['active']))continue; ?><option value="<?= (int)$cred['id'] ?>" <?= (int)($svc['credential_id'] ?? 0)===(int)$cred['id']?'selected':'' ?>><?= finance_h((string)$cred['name'].' · '.(bank_integration_providers()[(string)$cred['provider']] ?? (string)$cred['provider'])) ?></option><?php endforeach; ?></select></div>
              <div><label>Forma de pagamento padrão</label><select name="service_default_payment_method_id"><option value="">Sem padrão</option><?php foreach($paymentMethods as $pm): ?><option value="<?= (int)$pm['id'] ?>" <?= (int)($svc['default_payment_method_id'] ?? 0)===(int)$pm['id']?'selected':'' ?>><?= finance_h((string)$pm['name']) ?></option><?php endforeach; ?></select></div>
              <div class="span-2"><label>Base URL específica do serviço</label><input name="service_api_base_url" value="<?= finance_h((string)($svc['api_base_url'] ?? '')) ?>" placeholder="Opcional; prevalece sobre a URL do perfil"></div>
              <div class="span-2"><label>Webhook / callback</label><input name="service_webhook_url" value="<?= finance_h((string)($svc['webhook_url'] ?? '')) ?>"></div>
            </div></div>
            <div class="finance-form-actions"><a class="btn" href="<?= finance_h($hubBaseUrl) ?>">Cancelar</a><button class="btn btn-primary" type="submit">Salvar integração</button></div>
          </form>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog"><div class="finance-modal-head"><div><h2><?= $editId ? 'Editar conta' : 'Nova conta' ?></h2><p class="finance-muted">Informe contas bancárias, caixas internos e locais de recebimento/pagamento.</p></div><a class="finance-modal-close" href="financeiro_caixas_bancos.php">×</a></div><form method="post" class="finance-form"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><?php if($financeCrossBankWrite):?><input type="hidden" name="mc_csrf" value="<?= finance_h(multi_company_context_csrf_token()) ?>"><?php endif;?><div class="finance-form-section"><h3>Dados da conta</h3><div class="finance-form-grid"><div><label>Nome *</label><input name="name" value="<?= finance_h($edit['name'] ?? '') ?>" required></div><div><label>Tipo</label><select name="type"><?php foreach(['CAIXA'=>'Caixa','BANCO'=>'Banco','PIX'=>'Pix','CARTAO'=>'Cartão','OUTRO'=>'Outro'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($edit['type'] ?? 'BANCO')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div><div><label>Banco</label><input name="bank_name" value="<?= finance_h($edit['bank_name'] ?? '') ?>"></div><div><label>Agência</label><input name="agency" value="<?= finance_h($edit['agency'] ?? '') ?>"></div><div><label>Conta</label><input name="account_number" value="<?= finance_h($edit['account_number'] ?? '') ?>"></div><div><label>Saldo inicial</label><input name="initial_balance" value="<?= finance_money_br($edit['initial_balance'] ?? 0) ?>" <?= $financeCrossBankWrite && (int)($edit['id']??0)>0 ? 'readonly' : '' ?>></div><label class="checkline"><input type="checkbox" name="active" <?= !isset($edit['active']) || (int)$edit['active']===1 ? 'checked' : '' ?>> Ativa</label></div></div><?php if(!$financeCrossBankWrite || $financeBillingConfigCross): ?><div class="finance-form-section"><h3>Cobrança / Boletos</h3><p class="finance-muted">A conta bancária define como o Nexa registra cobranças. O contrato pode escolher a forma de pagamento ou usar o padrão abaixo.<?php if($financeCrossBankWrite): ?> <strong>V11.15:</strong> estes dados serão gravados somente em <?= finance_h(finance_operational_company_name($pdo)) ?>.<?php endif; ?></p><div class="finance-form-grid">
<label class="checkline"><input type="checkbox" name="billing_enabled" <?= !empty($edit['enabled'])?'checked':'' ?>> Habilitar cobrança/boleto nesta conta</label>
<div><label>Banco / provedor</label><select name="billing_provider"><option value="GENERIC" <?= (($edit['provider']??'GENERIC')==='GENERIC')?'selected':'' ?>>Genérico</option><option value="ITAU" <?= (($edit['provider']??'')==='ITAU')?'selected':'' ?>>Itaú</option><option value="SICOOB" <?= (($edit['provider']??'')==='SICOOB')?'selected':'' ?>>Sicoob (756)</option></select></div>
<div><label>Forma de integração</label><select name="integration_mode"><option value="API" <?= (($edit['integration_mode']??'API')==='API')?'selected':'' ?>>API</option><option value="CNAB240" <?= (($edit['integration_mode']??'')==='CNAB240')?'selected':'' ?>>Remessa CNAB 240</option><option value="CNAB400" <?= (($edit['integration_mode']??'')==='CNAB400')?'selected':'' ?>>Remessa CNAB 400</option></select></div>
<div><label>Ambiente</label><select name="billing_environment"><option value="HOMOLOGACAO" <?= (($edit['environment']??'HOMOLOGACAO')==='HOMOLOGACAO')?'selected':'' ?>>Homologação</option><option value="PRODUCAO" <?= (($edit['environment']??'')==='PRODUCAO')?'selected':'' ?>>Produção</option></select></div>
<div><label>Código do banco</label><input name="bank_code" value="<?= finance_h($edit['bank_code']??'') ?>" placeholder="Ex.: 341"></div>
<div><label>Convênio (Sicoob: numeroCliente)</label><input name="convenio" value="<?= finance_h($edit['convenio']??'') ?>"></div><div><label>Carteira (Sicoob: codigoModalidade)</label><input name="carteira" value="<?= finance_h($edit['carteira']??'') ?>"></div><div><label>Variação</label><input name="variacao" value="<?= finance_h($edit['variacao']??'') ?>"></div>
<div><label>Forma de pagamento padrão</label><select name="default_payment_method_id"><option value="">Sem padrão</option><?php foreach($paymentMethods as $pm):?><option value="<?= (int)$pm['id'] ?>" <?= (int)($edit['default_payment_method_id']??0)===(int)$pm['id']?'selected':'' ?>><?= finance_h($pm['name']) ?></option><?php endforeach;?></select></div>
<div><label>Client ID (API)</label><input name="api_client_id" value="<?= finance_h($edit['api_client_id']??'') ?>"></div><div><label>Client Secret (API)</label><input type="password" name="api_client_secret" value="" placeholder="Deixe vazio para manter o atual"></div>
<div><label>Certificado .crt/.pem</label><input name="cert_path" value="<?= finance_h($edit['cert_path']??'') ?>" placeholder="/var/www/nexa/storage/certs/..."></div><div><label>Chave privada .key</label><input name="key_path" value="<?= finance_h($edit['key_path']??'') ?>"></div><div><label>CA / cadeia</label><input name="ca_path" value="<?= finance_h($edit['ca_path']??'') ?>"></div>
<div><label>OAuth URL</label><input name="oauth_url" value="<?= finance_h($edit['oauth_url']??'') ?>" placeholder="<?= finance_h(itau_boleto_default_oauth_url()) ?>"><div class="hint">Se ficar vazio, o conector Itaú usa o STS padrão documentado. Caso o onboarding informe outra URL, grave aqui.</div></div><div><label>Base URL da API</label><input name="api_base_url" value="<?= finance_h($edit['api_base_url']??'') ?>"><div class="hint">Preencher somente quando o Itaú fornecer a URL oficial da API de Cobrança/Boleto.</div></div>
<div><label>Protesto após (dias)</label><input type="number" min="0" name="protest_days" value="<?= (int)($edit['protest_days']??0) ?>"></div><div><label>Multa %</label><input name="fine_percent" value="<?= finance_money_br($edit['fine_percent']??0) ?>"></div><div><label>Juros ao mês %</label><input name="interest_month_percent" value="<?= finance_money_br($edit['interest_month_percent']??0) ?>"></div>
<div class="span-2"><label>Instruções do boleto</label><textarea name="billing_instructions" rows="3"><?= finance_h($edit['instructions']??'') ?></textarea></div>
</div></div><?php endif; ?><div class="finance-form-actions"><?php if((!$financeCrossBankWrite || $financeBillingConfigCross) && (int)($edit['id']??0)>0 && strtoupper((string)($edit['provider']??''))==='ITAU' && strtoupper((string)($edit['integration_mode']??''))==='API'):?><button class="btn" type="submit" name="action" value="itau_validate" formnovalidate>Diagnóstico local Itaú</button><?php $itauDiag=itau_boleto_validate_config($edit,false); if(!empty($itauDiag['ready_for_token'])):?><button class="btn" type="submit" name="action" value="itau_test_token" formnovalidate onclick="return confirm('Testar agora a autenticação OAuth/mTLS com o Itaú?')">Testar OAuth Itaú</button><?php endif;?><?php endif;?><?php if((!$financeCrossBankWrite || $financeBillingConfigCross) && (int)($edit['id']??0)>0 && strtoupper((string)($edit['provider']??''))==='ITAU' && strtoupper((string)($edit['integration_mode']??''))==='API'):?><?php $advancedItauUrl=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_itau.php?bank='.(int)$edit['id']):('financeiro_itau.php?bank='.(int)$edit['id']); ?><a class="btn" href="<?= finance_h($advancedItauUrl) ?>">Configuração avançada Itaú</a><?php endif;?><a class="btn" href="financeiro_caixas_bancos.php">Cancelar</a><button class="btn btn-primary" type="submit" name="action" value="save">Salvar conta</button></div></form></div></div>
<?php endif; ?>
<?php finance_admin_end(); ?>
