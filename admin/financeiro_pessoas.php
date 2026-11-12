<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/gestao_module.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/commercial_rules.php';
require_once __DIR__ . '/../config/person_attachments.php';

// V7 permanece intacta para Clientes. A V11.4 adiciona, somente quando solicitado
// explicitamente pela tela financeira (mc_finance=1&role=supplier), o cadastro
// protegido de Fornecedor na empresa selecionada pelo F11.
$requestedFinanceRole = strtolower(trim((string)($_REQUEST['role'] ?? '')));
$financePersonMode = !empty($_REQUEST['mc_finance']) && in_array($requestedFinanceRole, ['supplier'], true);
$personScope = $financePersonMode ? 'finance_people' : 'customers_write';
$customerCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, $personScope)
  : (int)COMPANY_ID;
$customerCrossContext = $customerCompanyId !== (int)COMPANY_ID;
$financePersonCrossContext = $customerCrossContext && $financePersonMode;
$customerCompanyRow = function_exists('multi_company_context_company_row')
  ? multi_company_context_company_row($pdo, $customerCompanyId)
  : null;
$customerCompanyName = trim((string)($customerCompanyRow['name'] ?? '')) ?: ('Empresa #' . $customerCompanyId);
if ($customerCrossContext) {
  if ($financePersonCrossContext) {
    if (!function_exists('multi_company_context_permission_allowed') || !multi_company_context_permission_allowed($pdo, 'financeiro_pessoas')) {
      access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para alterar Pessoas do Financeiro.');
    }
  } elseif (function_exists('nexa_people_permission_allowed') && !nexa_people_permission_allowed($pdo, $customerCompanyId)) {
    access_render_denied('O acesso ao cadastro de Pessoas foi desabilitado para este perfil.');
  }
}
$cid = $customerCompanyId;
if ($financePersonCrossContext && function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $cid, 'financeiro')) {
  access_render_denied('O módulo Financeiro não está habilitado na empresa selecionada pelo F11.');
}
$err = '';
company_sequence_ensure_schema($pdo, true);
commercial_ensure_schema($pdo);
$hasPessoaNumero = company_sequence_has_column($pdo, 'pessoas');


if (!function_exists('fp_grid_user_id')) {
  function fp_grid_user_id(): int { return function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0); }
}
if (!function_exists('fp_grid_csrf_token')) {
  function fp_grid_csrf_token(): string {
    if (empty($_SESSION['finance_people_grid_csrf']) || !is_string($_SESSION['finance_people_grid_csrf'])) $_SESSION['finance_people_grid_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['finance_people_grid_csrf'];
  }
}
// Aba Anexos - ações interceptadas antes do POST geral de Pessoas.
// Aba Anexos: controlada pelo perfil do usuário (Tabelas > Perfis de usuário).
// O administrador da empresa e a senha master têm todas as permissões.
$paCan = static function (string $permission) use ($pdo): bool {
  return function_exists('access_has_permission') && access_has_permission($pdo, (int)COMPANY_ID, $permission);
};
$paViewAllowed = $paCan('pessoas_anexos_ver');
$paUploadAllowed = $paCan('pessoas_anexos_incluir');
$paDeleteAllowed = $paCan('pessoas_anexos_remover');
$paAccessAllowed = $paViewAllowed || $paUploadAllowed || $paDeleteAllowed; // mostra a aba
$paAction = (string)($_REQUEST['action'] ?? '');
// Aba Contatos (quem chama, contador, financeiro...): config/person_contacts.php.
require_once __DIR__ . '/../config/person_contacts.php';
$pcReady = person_contacts_ready($pdo);
if (in_array($paAction, ['pc_save','pc_delete'], true)) {
  if (($_SERVER['REQUEST_METHOD']??'GET') !== 'POST') {http_response_code(405);exit('Método não permitido.');}
  if (!hash_equals(fp_grid_csrf_token(),(string)($_POST['csrf']??''))) {http_response_code(403);exit('Sessão expirada.');}
  $personId = max(0,(int)($_POST['person_id'] ?? 0));
  if ($personId <= 0 || !pa_person_exists($pdo,$cid,$personId)) { http_response_code(404); exit('Pessoa não encontrada.'); }
  if ($customerCrossContext) {
    $field = $financePersonCrossContext ? 'is_supplier' : 'is_customer';
    $stmt=$pdo->prepare('SELECT '.$field.' FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
    $stmt->execute([$cid,$personId]);
    if (!$stmt->fetchColumn()) { http_response_code(403);exit('Acesso não autorizado.'); }
  }
  try {
    if ($paAction === 'pc_save') {
      $contactId = (int)($_POST['contact_id'] ?? 0);
      if ($contactId > 0) { $cur = person_contact_get($pdo,$cid,$contactId); if (!$cur || (int)$cur['person_id'] !== $personId) throw new RuntimeException('Contato não encontrado.'); }
      person_contact_save($pdo,$cid,['id'=>$contactId,'person_id'=>$personId] + array_intersect_key($_POST, array_flip(['name','role','whatsapp','phone','email','notes','tags','is_primary','active'])) + ['active'=>0]);
      $msg = 'Contato salvo.';
    } else {
      $contactId = (int)($_POST['contact_id'] ?? 0);
      $cur = person_contact_get($pdo,$cid,$contactId);
      if (!$cur || (int)$cur['person_id'] !== $personId) throw new RuntimeException('Contato não encontrado.');
      person_contact_delete($pdo,$cid,$contactId);
      $msg = 'Contato excluído.';
    }
  } catch (Throwable $e) {
    $msg = 'Falha: '.$e->getMessage();
  }
  $params=['edit'=>$personId,'tab'=>'contatos','pc_result'=>$msg];
  if ($customerCrossContext) {
    $params['role']=$financePersonCrossContext?'supplier':'customer';
    $params[$financePersonCrossContext?'mc_finance':'mc_customer']='1';
  }
  header('Location: financeiro_pessoas.php?'.http_build_query($params),true,303);
  exit;
}
if (in_array($paAction, ['pa_upload','pa_delete','pa_download','pa_view'], true)) {
  $paActionAllowed = ['pa_upload' => $paUploadAllowed, 'pa_delete' => $paDeleteAllowed, 'pa_download' => $paViewAllowed, 'pa_view' => $paViewAllowed][$paAction];
  if (!$paActionAllowed) {http_response_code(403);exit('Seu perfil de usuário não tem permissão para esta ação nos anexos de pessoas.');}
  $personId = max(0,(int)($_REQUEST['person_id'] ?? $_GET['edit'] ?? 0));
  if ($personId <= 0 || !pa_person_exists($pdo,$cid,$personId)) { http_response_code(404); exit('Pessoa não encontrada.'); }
  // Respeitar o papel da tela F11 (cliente / fornecedor).
  if ($customerCrossContext) {
    $field = $financePersonCrossContext ? 'is_supplier' : 'is_customer';
    $stmt=$pdo->prepare('SELECT '.$field.' FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
    $stmt->execute([$cid,$personId]);
    if (!$stmt->fetchColumn()) { http_response_code(403);exit('Acesso não autorizado.'); }
  }
  if ($paAction === 'pa_download' || $paAction === 'pa_view') {
    $attachment=pa_get($pdo,$cid,$personId,max(0,(int)($_GET['attachment_id']??0)));
    if (!$attachment) {http_response_code(404);exit('Anexo não encontrado.');}
    try {
      $path=pa_file_path($cid,(string)$attachment['storage_sha256']);
      if (!is_file($path) || is_link($path) || !is_readable($path)) throw new RuntimeException('Arquivo não disponível.');
      $inline = $paAction === 'pa_view';
      if ($inline) {
        $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/bmp','application/pdf'];
        $mime = strtolower(trim((string)($attachment['mime_type'] ?? '')));
        $realMime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (!in_array($mime, $allowed, true) || $realMime !== $mime) {
          http_response_code(415);
          exit('Visualizacao indisponivel. Utilize Baixar.');
        }
      }
      header('Content-Type: '.(string)$attachment['mime_type']);
      header('X-Content-Type-Options: nosniff');
      header('Cache-Control: private, no-store, max-age=0');
      header("Content-Security-Policy: default-src 'none'; sandbox");
      header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="anexo-'.(int)$attachment['id'].'"; filename*=UTF-8\'\''.rawurlencode((string)$attachment['original_name']));
      header('Content-Length: '.(string)filesize($path));
      pa_audit($pdo,$cid,$personId,(int)$attachment['id'],fp_grid_user_id(),'DOWNLOAD');
      readfile($path);
    } catch (Throwable $e) {http_response_code(404);echo 'Arquivo não disponível.';}
    exit;
  }
  if (($_SERVER['REQUEST_METHOD']??'GET') !== 'POST') {http_response_code(405);exit('Método não permitido.');}
  if (!hash_equals(fp_grid_csrf_token(),(string)($_POST['csrf']??''))) {http_response_code(403);exit('Sessão expirada.');}
  try {
    if ($paAction === 'pa_upload') {
      $f=$_FILES['person_attachment']??null;
      if (!is_array($f) || (int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
          || (int)($f['size']??0)>30*1024*1024 || !is_uploaded_file((string)($f['tmp_name']??''))) {
        throw new RuntimeException('Selecione um anexo válido de até 30 MB.');
      }
      pa_save($pdo,$cid,$personId,(string)$f['name'],(string)$f['tmp_name'],fp_grid_user_id());
      $msg='Anexo incluído.';
    } else {
      $removeId=max(0,(int)($_POST['attachment_id']??0));
      if (!pa_get($pdo,$cid,$personId,$removeId)) throw new RuntimeException('Anexo não encontrado.');
      $pdo->beginTransaction();
      try {
        pa_delete($pdo,$cid,$personId,$removeId);
        pa_audit($pdo,$cid,$personId,$removeId,fp_grid_user_id(),'DELETE');
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
      $msg='Vínculo do anexo removido.';
    }
  } catch (Throwable $e) {
    $msg='Falha: '.$e->getMessage();
  }
  $params=['edit'=>$personId,'tab'=>'anexos','pa_result'=>$msg];
  if ($customerCrossContext) {
    $params['role']=$financePersonCrossContext?'supplier':'customer';
    $params[$financePersonCrossContext?'mc_finance':'mc_customer']='1';
  }
  header('Location: financeiro_pessoas.php?'.http_build_query($params),true,303);
  exit;
}

if (!function_exists('fp_grid_preferences_normalize')) {
  function fp_grid_preferences_normalize(array $prefs, array $allowed): array {
    $visible=array_values(array_filter(array_map('strval',(array)($prefs['visible']??[])),fn($v)=>in_array($v,$allowed,true)));
    $order=array_values(array_unique(array_filter(array_map('strval',(array)($prefs['order']??[])),fn($v)=>in_array($v,$allowed,true))));
    foreach($allowed as $c) if(!in_array($c,$order,true)) $order[]=$c;
    $widths=[]; foreach((array)($prefs['widths']??[]) as $k=>$v) if(in_array((string)$k,$allowed,true)) $widths[(string)$k]=max(70,min(520,(int)$v));
    return ['visible'=>$visible?:$allowed,'order'=>$order,'widths'=>$widths];
  }
}
if (!function_exists('fp_birthday_md')) {
  function fp_birthday_md(string $value): ?int {
    $value=trim($value); if($value==='') return null;
    if(!preg_match('#^(\d{1,2})/(\d{1,2})$#',$value,$m)) return null;
    $d=(int)$m[1];$mo=(int)$m[2]; if(!checkdate($mo,$d,2000)) return null;
    return $mo*100+$d;
  }
}
if (!function_exists('fp_date_br')) {
  function fp_date_br($value): string { $v=trim((string)$value); if($v==='')return '—';$ts=strtotime($v);return $ts?date('d/m/Y',$ts):'—'; }
}
if (!function_exists('fp_address_full')) {
  function fp_address_full(array $r): string {
    $parts=[];$street=trim((string)($r['address']??''));$num=trim((string)($r['number']??''));if($street!=='')$parts[]=$street.($num!==''?', '.$num:'');
    foreach(['complement','district'] as $k){$v=trim((string)($r[$k]??''));if($v!=='')$parts[]=$v;}
    $city=trim((string)($r['city']??''));$state=trim((string)($r['state']??''));if($city!==''||$state!=='')$parts[]=trim($city.($state!==''?' / '.$state:''));
    return implode(' · ',$parts);
  }
}

if (!function_exists('fp_grid_filter_menu')) {
  function fp_grid_filter_menu(string $key, string $label): string {
    $keyEsc=finance_h($key);$labelEsc=finance_h($label);
    $icon='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 5.5h17l-6.7 7.2v5.6l-3.6 1.9v-7.5L3.5 5.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    return '<details class="fp-filter-menu" data-fp-filter-menu draggable="false">'
      . '<summary class="fp-filter-trigger" title="Filtrar '.$labelEsc.'" aria-label="Filtrar '.$labelEsc.'">'.$icon.'</summary>'
      . '<div class="fp-filter-popover">'
      . '<div class="fp-filter-title">Filtrar '.$labelEsc.'</div>'
      . '<input type="text" class="fp-filter-input" data-fp-filter="'.$keyEsc.'" placeholder="Digite para filtrar" autocomplete="off">'
      . '<div class="fp-filter-actions-menu"><button type="button" class="btn btn-primary" data-fp-filter-apply>Aplicar</button><button type="button" class="btn" data-fp-filter-clear>Limpar</button></div>'
      . '</div></details>';
  }
}

$fpGridUserId=fp_grid_user_id();
$fpGridColumns=['codigo','nome','nascimento','documento','telefone','email','endereco','papeis','acoes'];
$fpGridLabels=['codigo'=>'Código','nome'=>'Nome','nascimento'=>'Data de nascimento','documento'=>'Documento','telefone'=>'Telefone','email'=>'E-mail','endereco'=>'Endereço','papeis'=>'Papéis','acoes'=>'Ações'];
$fpGridKey='finance_people_grid_user_'.$fpGridUserId;
$fpGridDefaults=['visible'=>$fpGridColumns,'order'=>$fpGridColumns,'widths'=>['codigo'=>90,'nome'=>220,'nascimento'=>130,'documento'=>160,'telefone'=>150,'email'=>210,'endereco'=>300,'papeis'=>210,'acoes'=>245]];
$fpGridPrefs=$fpGridDefaults;
try{$st=$pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');$st->execute([$cid??$customerCompanyId,$fpGridKey]);$raw=$st->fetchColumn();if(is_string($raw)&&trim($raw)!==''){$j=json_decode($raw,true);if(is_array($j)){$n=fp_grid_preferences_normalize($j,$fpGridColumns);$fpGridPrefs=['visible'=>$n['visible'],'order'=>$n['order'],'widths'=>array_merge($fpGridDefaults['widths'],$n['widths'])];}}}catch(Throwable $e){}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && (string)($_POST['action']??'')==='save_people_grid_preferences'){
  header('Content-Type: application/json; charset=utf-8');
  try{
    $token=(string)($_POST['csrf']??'');if($token===''||!hash_equals(fp_grid_csrf_token(),$token))throw new RuntimeException('Sessão expirada. Atualize a página.');
    $raw=json_decode((string)($_POST['preferences']??''),true);if(!is_array($raw))throw new RuntimeException('Preferências inválidas.');
    $n=fp_grid_preferences_normalize($raw,$fpGridColumns);$n['visible']=array_values(array_filter($n['visible'],fn($c)=>$c!=='acoes'));if(!$n['visible'])$n['visible']=array_values(array_filter($fpGridColumns,fn($c)=>$c!=='acoes'));$n['visible'][]='acoes';
    $n['order']=array_values(array_filter($n['order'],fn($c)=>$c!=='acoes'));foreach($fpGridColumns as $c)if($c!=='acoes'&&!in_array($c,$n['order'],true))$n['order'][]=$c;$n['order'][]='acoes';
    $json=json_encode($n,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Falha ao salvar layout.');
    $st=$pdo->prepare('INSERT INTO app_config(company_id,cfg_key,cfg_json,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');$st->execute([$cid??$customerCompanyId,$fpGridKey,$json]);
    echo json_encode(['ok'=>true]);
  }catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}exit;
}

if (!function_exists('finance_person_clean_address')) {
  function finance_person_clean_address(string $address, string $district = ''): string {
    $address = trim($address);
    $district = trim($district);
    if ($address === '') return '';
    if (function_exists('gestao_clean_address_with_district')) {
      return gestao_clean_address_with_district($address, $district);
    }
    $streetTypes = '(?:Rua|R\.?|Avenida|Av\.?|Alameda|Travessa|Tv\.?|Pra[cç]a|Rodovia|Estrada|Viela|Largo|Quadra|Condom[ií]nio|Conjunto|Residencial)';
    if ($district !== '') {
      $districtQuoted = preg_quote($district, '/');
      $address = preg_replace('/^Bairro:\s*' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $address) ?? $address;
      $address = preg_replace('/^' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $address) ?? $address;
    }
    $address = preg_replace('/^Bairro:\s*.*?(?=' . $streetTypes . '\b)/iu', '', $address) ?? $address;
    $address = preg_replace('/^Bairro:\s*/iu', '', $address) ?? $address;
    $address = preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r", "\n"], ' ', trim($address))) ?? $address;
    return trim($address);
  }
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($customerCrossContext && $action === 'delete') {
      throw new RuntimeException('A inativação de pessoas ainda não foi homologada para troca de empresa pelo F11.');
    }

    if ($action === 'save_address') {
      if ($financePersonCrossContext) throw new RuntimeException('V11.4: endereços adicionais de Fornecedor permanecem fora do escopo F11. Edite apenas o endereço principal.');
      gestao_ensure_schema($pdo);
      $personId = (int)($_POST['person_id'] ?? 0);
      $addressId = (int)($_POST['address_id'] ?? 0);
      if ($personId <= 0) throw new RuntimeException('Salve a pessoa antes de cadastrar endereços adicionais.');
      $person = finance_query_one($pdo, 'SELECT id, is_customer FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$cid, $personId]);
      if (!$person) throw new RuntimeException('Pessoa não encontrada.');
      if ($customerCrossContext && empty($person['is_customer'])) throw new RuntimeException('Na V7, o contexto cruzado permite alterar somente Clientes.');

      $addrData = [
        'label' => trim((string)($_POST['label'] ?? '')) ?: 'Entrega',
        'zip_code' => preg_replace('/\D+/', '', (string)($_POST['zip_code'] ?? '')),
        'address' => trim((string)($_POST['address'] ?? '')),
        'number' => trim((string)($_POST['number'] ?? '')),
        'complement' => trim((string)($_POST['complement'] ?? '')),
        'reference' => trim((string)($_POST['reference'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'city' => trim((string)($_POST['city'] ?? '')),
        'city_ibge' => preg_replace('/\D+/', '', (string)($_POST['city_ibge'] ?? '')),
        'state' => strtoupper(substr(trim((string)($_POST['state'] ?? '')), 0, 2)),
      ];
      $addrData['address'] = finance_person_clean_address((string)$addrData['address'], (string)$addrData['district']);
      $makeDefault = !empty($_POST['is_default']);
      if ($addrData['address'] === '' && $addrData['district'] === '') throw new RuntimeException('Informe ao menos endereço ou bairro.');

      if ($makeDefault) {
        $pdo->prepare('UPDATE gestao_customer_addresses SET is_default = 0, updated_at = NOW() WHERE company_id = ? AND person_id = ?')->execute([$cid, $personId]);
      }

      if ($addressId > 0) {
        $sql = 'UPDATE gestao_customer_addresses SET label=?, zip_code=?, address=?, number=?, complement=?, reference=?, district=?, city=?, city_ibge=?, state=?, is_default=?, active=1, updated_at=NOW() WHERE company_id=? AND person_id=? AND id=?';
        $pdo->prepare($sql)->execute([
          $addrData['label'], $addrData['zip_code'] ?: null, $addrData['address'] ?: null, $addrData['number'] ?: null,
          $addrData['complement'] ?: null, $addrData['reference'] ?: null, $addrData['district'] ?: null, $addrData['city'] ?: null,
          $addrData['city_ibge'] ?: null, $addrData['state'] ?: null, $makeDefault ? 1 : 0, $cid, $personId, $addressId
        ]);
      } else {
        gestao_create_customer_address($pdo, $cid, $personId, $addrData, $makeDefault);
      }
      finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_pessoas.php?edit=' . $personId . '&role=customer&mc_customer=1&tab=enderecos&ok=' . rawurlencode('Endereço salvo.')) : ('financeiro_pessoas.php?edit=' . $personId . '&tab=enderecos&ok=' . rawurlencode('Endereço salvo.')));
    }

    if ($action === 'delete_address') {
      if ($financePersonCrossContext) throw new RuntimeException('V11.4: endereços adicionais de Fornecedor permanecem fora do escopo F11.');
      $personId = (int)($_POST['person_id'] ?? 0);
      $addressId = (int)($_POST['address_id'] ?? 0);
      if ($personId > 0 && $addressId > 0) {
        if ($customerCrossContext) {
          $person = finance_query_one($pdo, 'SELECT id, is_customer FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$cid, $personId]);
          if (!$person || empty($person['is_customer'])) throw new RuntimeException('Na V7, o contexto cruzado permite alterar somente Clientes.');
        }
        $pdo->prepare('UPDATE gestao_customer_addresses SET active = 0, updated_at = NOW() WHERE company_id = ? AND person_id = ? AND id = ?')->execute([$cid, $personId, $addressId]);
      }
      finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_pessoas.php?edit=' . $personId . '&role=customer&mc_customer=1&tab=enderecos&ok=' . rawurlencode('Endereço removido.')) : ('financeiro_pessoas.php?edit=' . $personId . '&tab=enderecos&ok=' . rawurlencode('Endereço removido.')));
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $pdo->prepare('UPDATE finance_people SET active = 0, updated_at = NOW() WHERE company_id = ? AND id = ?')->execute([$cid, $id]);
      }
      finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_pessoas.php?ok=' . rawurlencode('Pessoa inativada.')) : ('financeiro_pessoas.php?ok=' . rawurlencode('Pessoa inativada.')));
    }

    $id = (int)($_POST['id'] ?? 0);
    $existingCustomer = null;
    if ($customerCrossContext && $id > 0) {
      $existingCustomer = finance_query_one($pdo, 'SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$cid, $id]);
      $requiredRoleField = $financePersonCrossContext ? 'is_supplier' : 'is_customer';
      $requiredRoleLabel = $financePersonCrossContext ? 'Fornecedor' : 'Cliente';
      if (!$existingCustomer || empty($existingCustomer[$requiredRoleField])) {
        throw new RuntimeException($requiredRoleLabel . ' não encontrado na empresa selecionada.');
      }
    }
    // Migração do regime é opcional até que o platform_admin aplique o SQL.
    // Uma instalação que ainda não foi atualizada continua cadastrando Pessoas.
    $hasTaxRegimeColumn = false;
    try {
      $hasTaxRegimeColumn = (bool)$pdo->query("SHOW COLUMNS FROM finance_people LIKE 'tax_regime'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {}
    if (!$hasTaxRegimeColumn && trim((string)($_POST['tax_regime'] ?? '')) !== '') {
      throw new RuntimeException('Execute a atualização de banco do ICMS-ST antes de selecionar um regime tributário.');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe o nome/razão social.');
    $stateRegistration = strtoupper(trim((string)($_POST['state_registration'] ?? '')));
    if ($stateRegistration !== '' && $stateRegistration !== 'ISENTO') {
      $stateRegistration = preg_replace('/[^0-9A-Z]+/', '', $stateRegistration);
    }
    $data = [
      'name' => $name,
      'fantasy_name' => '',
      'document' => preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['document'] ?? '')),
      'state_registration' => $stateRegistration,
      'municipal_registration' => trim((string)($_POST['municipal_registration'] ?? '')),
      'identity_document' => trim((string)($_POST['identity_document'] ?? '')),
      'identity_issuer' => trim((string)($_POST['identity_issuer'] ?? '')),
      'identity_state' => strtoupper(substr(trim((string)($_POST['identity_state'] ?? '')), 0, 2)),
      'person_type' => in_array((string)($_POST['person_type'] ?? ''), ['FISICA','JURIDICA'], true) ? (string)$_POST['person_type'] : 'JURIDICA',
      'birth_opening_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['birth_opening_date'] ?? '')) ? (string)$_POST['birth_opening_date'] : null,
      'email' => trim((string)($_POST['email'] ?? '')),
      'phone' => trim((string)($_POST['phone'] ?? '')),
      'whatsapp' => trim((string)($_POST['whatsapp'] ?? '')),
      'zip_code' => trim((string)($_POST['zip_code'] ?? '')),
      'address' => trim((string)($_POST['address'] ?? '')),
      'number' => trim((string)($_POST['number'] ?? '')),
      'complement' => trim((string)($_POST['complement'] ?? '')),
      'district' => trim((string)($_POST['district'] ?? '')),
      'city' => trim((string)($_POST['city'] ?? '')),
      'city_ibge' => preg_replace('/\D+/', '', (string)($_POST['city_ibge'] ?? '')),
      'state' => strtoupper(substr(trim((string)($_POST['state'] ?? '')), 0, 2)),
      'is_customer' => $customerCrossContext ? ($financePersonCrossContext ? (int)($existingCustomer['is_customer'] ?? 0) : 1) : (isset($_POST['is_customer']) ? 1 : 0),
      'is_supplier' => $customerCrossContext ? ($financePersonCrossContext ? 1 : (int)($existingCustomer['is_supplier'] ?? 0)) : (isset($_POST['is_supplier']) ? 1 : 0),
      'is_seller' => $customerCrossContext ? (int)($existingCustomer['is_seller'] ?? 0) : (isset($_POST['is_seller']) ? 1 : 0),
      'is_employee' => $customerCrossContext ? (int)($existingCustomer['is_employee'] ?? 0) : (isset($_POST['is_employee']) ? 1 : 0),
      'is_professional' => $customerCrossContext ? (int)($existingCustomer['is_professional'] ?? 0) : (isset($_POST['is_professional']) ? 1 : 0),
      'is_carrier' => $customerCrossContext ? (int)($existingCustomer['is_carrier'] ?? 0) : (isset($_POST['is_carrier']) ? 1 : 0),
      'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
    $sellerCommissionRaw = trim((string)($_POST['seller_commission_percent'] ?? ''));
    $sellerCommission = $customerCrossContext ? ($existingCustomer['seller_commission_percent'] ?? null) : ($sellerCommissionRaw === '' ? null : max(0.0, min(100.0, (float)str_replace(',', '.', $sellerCommissionRaw))));
    $creditLimitRaw = trim((string)($_POST['credit_limit'] ?? ''));
    $creditLimit = $creditLimitRaw === '' ? null : max(0.0, (float)str_replace(['. ','.'], ['', ''], str_replace(',', '.', $creditLimitRaw)));
    // Corrige entradas pt-BR do tipo 1.500,00 sem perder o separador decimal.
    if ($creditLimitRaw !== '') {
      $tmp = str_replace(' ', '', $creditLimitRaw);
      if (str_contains($tmp, ',')) $tmp = str_replace('.', '', $tmp);
      $tmp = str_replace(',', '.', $tmp);
      $creditLimit = max(0.0, (float)preg_replace('/[^0-9.\-]/', '', $tmp));
    }
    if ($financePersonCrossContext) $creditLimit = $existingCustomer['credit_limit'] ?? null;
    $data['address'] = finance_person_clean_address((string)$data['address'], (string)$data['district']);
    if ($data['person_type'] === 'JURIDICA') {
      $data['fantasy_name'] = trim((string)($_POST['fantasy_name'] ?? ''));
    }

    if (!$data['is_customer'] && !$data['is_supplier'] && !$data['is_seller'] && !$data['is_employee'] && !$data['is_professional'] && !$data['is_carrier']) {
      throw new RuntimeException('Marque pelo menos um papel: cliente, fornecedor, vendedor, colaborador, profissional ou transportadora.');
    }

    if ($id > 0) {
      $sql = 'UPDATE finance_people SET name=?, fantasy_name=?, document=?, state_registration=?, municipal_registration=?, identity_document=?, identity_issuer=?, identity_state=?, person_type=?, birth_opening_date=?, email=?, phone=?, whatsapp=?, zip_code=?, address=?, number=?, complement=?, district=?, city=?, city_ibge=?, state=?, is_customer=?, is_supplier=?, is_seller=?, is_employee=?, is_professional=?, is_carrier=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?';
      $params = array_values($data);
      $params[] = $cid; $params[] = $id;
      $pdo->prepare($sql)->execute($params);
      $personIdSaved = $id;
    } else {
      $cols = ['company_id'];
      $vals = ['?'];
      $params = [$cid];
      if ($hasPessoaNumero) {
        $cols[] = 'numero_cadastro';
        $vals[] = '?';
        $params[] = company_sequence_next($pdo, $cid, 'pessoas');
      }
      foreach ($data as $col => $value) {
        $cols[] = $col;
        $vals[] = '?';
        $params[] = $value;
      }
      $sql = 'INSERT INTO finance_people (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
      $pdo->prepare($sql)->execute($params);
      $personIdSaved = (int)$pdo->lastInsertId();
    }

    $pdo->prepare('UPDATE finance_people SET seller_commission_percent=?, credit_limit=?, updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([$sellerCommission, $creditLimit, $cid, $personIdSaved]);

    // V12 ST: classificação informativa do regime da pessoa jurídica.
    // O campo NÃO altera o cálculo tributário por si só.
    $allowedTaxRegimes = ['', 'MEI', 'SIMPLES_NACIONAL', 'LUCRO_PRESUMIDO', 'LUCRO_REAL', 'REGIME_HIBRIDO'];
    $taxRegime = (string)($_POST['tax_regime'] ?? '');
    if (!in_array($taxRegime, $allowedTaxRegimes, true)) {
      throw new RuntimeException('Regime tributário inválido.');
    }
    if ($data['person_type'] !== 'JURIDICA') $taxRegime = '';
    if ($hasTaxRegimeColumn) {
      $pdo->prepare('UPDATE finance_people SET tax_regime=? WHERE company_id=? AND id=?')
        ->execute([$taxRegime !== '' ? $taxRegime : null, $cid, $personIdSaved]);
    }

    // Sincroniza o endereço principal com a tabela de endereços adicionais.
    // Assim checkout/PDV podem oferecer múltiplos endereços do mesmo cadastro.
    try {
      if (!$financePersonCrossContext) gestao_ensure_schema($pdo);
      if (!$financePersonCrossContext && $personIdSaved > 0 && ($data['address'] !== '' || $data['district'] !== '')) {
        $defaultAddr = finance_query_one($pdo, 'SELECT id FROM gestao_customer_addresses WHERE company_id = ? AND person_id = ? AND active = 1 AND is_default = 1 LIMIT 1', [$cid, $personIdSaved]);
        $addrData = [
          'label' => 'Principal',
          'zip_code' => $data['zip_code'],
          'address' => $data['address'],
          'number' => $data['number'],
          'complement' => $data['complement'],
          'reference' => '',
          'district' => $data['district'],
          'city' => $data['city'],
          'city_ibge' => $data['city_ibge'],
          'state' => $data['state'],
        ];
        if ($defaultAddr) {
          $pdo->prepare('UPDATE gestao_customer_addresses SET label=?, zip_code=?, address=?, number=?, complement=?, district=?, city=?, city_ibge=?, state=?, is_default=1, active=1, updated_at=NOW() WHERE company_id=? AND person_id=? AND id=?')->execute([
            $addrData['label'], $addrData['zip_code'] ?: null, $addrData['address'] ?: null, $addrData['number'] ?: null,
            $addrData['complement'] ?: null, $addrData['district'] ?: null, $addrData['city'] ?: null, $addrData['city_ibge'] ?: null, $addrData['state'] ?: null,
            $cid, $personIdSaved, (int)$defaultAddr['id']
          ]);
        } else {
          gestao_create_customer_address($pdo, $cid, $personIdSaved, $addrData, true);
        }
      }
    } catch (Throwable $e) {}

    if ($financePersonCrossContext) {
      finance_redirect('financeiro_pessoas.php?mc_finance=1&role=supplier&ok=' . rawurlencode('Fornecedor salvo com sucesso na empresa selecionada pelo F11.'));
    }
    finance_redirect(function_exists('multi_company_context_url') ? multi_company_context_url(($customerCrossContext ? 'gestao_clientes.php?ok=' : 'financeiro_pessoas.php?ok=') . rawurlencode($customerCrossContext ? 'Cliente salvo com sucesso.' : 'Pessoa salva com sucesso.')) : ('financeiro_pessoas.php?ok=' . rawurlencode('Pessoa salva com sucesso.')));
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
$edit = $editId > 0 ? finance_query_one($pdo, 'SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$cid, $editId]) : null;
if ($customerCrossContext && $editId > 0) {
  $requiredRoleField = $financePersonCrossContext ? 'is_supplier' : 'is_customer';
  $requiredRoleLabel = $financePersonCrossContext ? 'Fornecedor' : 'Cliente';
  if (!$edit || empty($edit[$requiredRoleField])) {
    http_response_code(404);
    access_render_denied($requiredRoleLabel . ' não encontrado na empresa selecionada pelo F11.');
  }
}
// Somente metadados: arquivos não são carregados na abertura do cadastro.
$paRows=[]; $paListError='';
if ($editId > 0 && $edit && $paAccessAllowed) {
  try {$paRows=pa_list($pdo,$cid,$editId);} catch (Throwable $e) {$paListError='Anexos indisponíveis. Verifique a migration e a pasta privada.';}
}
if ($edit) {
  $edit['address'] = finance_person_clean_address((string)($edit['address'] ?? ''), (string)($edit['district'] ?? ''));
}
$personAddresses = [];
if ($editId > 0) {
  try {
    gestao_ensure_schema($pdo);
    $personAddresses = gestao_customer_addresses($pdo, $cid, $editId);
  } catch (Throwable $e) {
    $personAddresses = [];
  }
}
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '') {
  $edit = $_POST;
}
// V11.36.4.1: quando o cadastro parte do Buscar Cliente do Orçamento,
// a nova Pessoa já abre classificada como Cliente.
if (!$edit && $showForm && $_SERVER['REQUEST_METHOD'] !== 'POST' && !$customerCrossContext && $requestedFinanceRole === 'customer') {
  $edit = ['is_customer' => 1];
}
$q = trim((string)($_GET['q'] ?? ''));
$role = $customerCrossContext ? ($financePersonCrossContext ? 'supplier' : 'customer') : trim((string)($_GET['role'] ?? ''));
$birthdayFrom=trim((string)($_GET['birthday_from']??''));$birthdayTo=trim((string)($_GET['birthday_to']??''));
$birthdayFromMd=fp_birthday_md($birthdayFrom);$birthdayToMd=fp_birthday_md($birthdayTo);
$didSearchPeople = isset($_GET['searched']) || $q!=='' || $role!=='' || $birthdayFrom!=='' || $birthdayTo!=='';
$rows = [];
if ($didSearchPeople) {
  $where = ['company_id = ?', 'active = 1'];
  $params = [$cid];
  if($q!==''){
    $searchParts = ['name LIKE ?', 'fantasy_name LIKE ?', 'document LIKE ?', 'state_registration LIKE ?', 'municipal_registration LIKE ?', 'identity_document LIKE ?', 'identity_issuer LIKE ?', 'email LIKE ?', 'phone LIKE ?', 'whatsapp LIKE ?', 'address LIKE ?', 'district LIKE ?', 'city LIKE ?'];
    $like = nexa_search_like($q);$searchParams = array_fill(0, count($searchParts), $like);$qDigits = preg_replace('/\D+/', '', $q);
    if ($hasPessoaNumero && $qDigits !== '') {$searchParts[] = 'numero_cadastro = ?';$searchParams[] = (int)$qDigits;} elseif ($hasPessoaNumero) {$searchParts[] = 'CAST(numero_cadastro AS CHAR) LIKE ?';$searchParams[] = $like;}
    $where[] = '(' . implode(' OR ', $searchParts) . ')';$params = array_merge($params, $searchParams);
  }
  if ($role === 'customer') $where[] = 'is_customer = 1';if ($role === 'supplier') $where[] = 'is_supplier = 1';if ($role === 'seller') $where[] = 'is_seller = 1';if ($role === 'employee') $where[] = 'is_employee = 1';if ($role === 'professional') $where[] = 'is_professional = 1';if ($role === 'carrier') $where[] = 'is_carrier = 1';
  if($birthdayFrom!==''||$birthdayTo!==''){
    if($birthdayFromMd===null||$birthdayToMd===null){$err=$err?:'Informe o período de aniversariantes no formato dd/mm.';}
    else{
      $where[]="person_type='FISICA'";$where[]='birth_opening_date IS NOT NULL';
      if($birthdayFromMd<=$birthdayToMd){$where[]="CAST(DATE_FORMAT(birth_opening_date,'%m%d') AS UNSIGNED) BETWEEN ? AND ?";$params[]=$birthdayFromMd;$params[]=$birthdayToMd;}
      else{$where[]="(CAST(DATE_FORMAT(birth_opening_date,'%m%d') AS UNSIGNED)>=? OR CAST(DATE_FORMAT(birth_opening_date,'%m%d') AS UNSIGNED)<=?)";$params[]=$birthdayFromMd;$params[]=$birthdayToMd;}
    }
  }
  if($err===''){$orderPeople = $hasPessoaNumero ? 'COALESCE(numero_cadastro, id) ASC, id ASC' : 'name ASC';$rows = finance_query_all($pdo, 'SELECT * FROM finance_people WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderPeople . ' LIMIT 700', $params);}
}
$peopleListParams=['searched'=>1];if($q!=='')$peopleListParams['q']=$q;if($role!=='')$peopleListParams['role']=$role;if($birthdayFrom!=='')$peopleListParams['birthday_from']=$birthdayFrom;if($birthdayTo!=='')$peopleListParams['birthday_to']=$birthdayTo;if($financePersonCrossContext)$peopleListParams['mc_finance']=1;$peopleReturnUrl='financeiro_pessoas.php?'.http_build_query($peopleListParams);

finance_admin_start($customerCrossContext ? ($financePersonCrossContext ? 'Fornecedores - Multiempresa V11.4' : 'Clientes - Multiempresa V7') : 'Financeiro - Pessoas', $customerCrossContext ? ($financePersonCrossContext ? 'fin_pessoas' : 'gestao_clientes') : 'fin_pessoas');
if ($customerCrossContext) {
  if ($financePersonCrossContext) {
    finance_page_header('Fornecedores · ' . $customerCompanyName, 'V11.4: inclusão e edição protegidas na empresa selecionada pelo F11.', [
      ['href' => 'financeiro_pessoas.php?new=1&role=supplier&mc_finance=1', 'label' => '+ Novo fornecedor', 'class' => 'btn btn-primary'],
    ]);
    finance_tabs('fin_pessoas');
    echo '<div class="finance-card" style="border-color:#86efac;background:#f0fdf4"><strong style="color:#166534">V11.4 · Fornecedor financeiro Multiempresa</strong><div class="finance-muted" style="margin-top:5px;color:#166534">Somente o papel Fornecedor pode ser incluído ou editado por este fluxo. Outros papéis existentes são preservados. Inativação e endereços adicionais permanecem fora do escopo F11.</div></div>';
  } else {
    finance_page_header('Clientes · ' . $customerCompanyName, 'V7: inclusão e edição protegidas na empresa selecionada pelo F11.', [
      ['href' => 'financeiro_pessoas.php?new=1&role=customer&mc_customer=1', 'label' => '+ Novo cliente', 'class' => 'btn btn-primary'],
    ]);
    echo '<div class="finance-card" style="border-color:#86efac;background:#f0fdf4"><strong style="color:#166534">V7 · Gravação protegida</strong><div class="finance-muted" style="margin-top:5px;color:#166534">Somente cadastros marcados como Cliente podem ser alterados neste contexto. Demais papéis e rotinas financeiras permanecem no fluxo legado.</div></div>';
  }
} else {
  finance_page_header('Pessoas', '', [
    ['href' => 'financeiro_pessoas.php?new=1', 'label' => '+ Nova Pessoa', 'class' => 'btn btn-primary'],
  ]);
}
finance_flash();
if ($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<style>
.fp-filter-line{display:grid;grid-template-columns:minmax(260px,1.8fr) minmax(150px,.75fr) minmax(120px,.55fr) minmax(120px,.55fr) auto;gap:10px;align-items:end}.fp-filter-line label{display:block;font-weight:800;font-size:12px;margin-bottom:5px;color:#334155}.fp-filter-line input,.fp-filter-line select{width:100%;min-height:42px}.fp-filter-actions{display:flex;gap:8px;align-items:center}.fp-grid-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.fp-grid-tools{margin-left:auto;display:flex;align-items:center;gap:8px}.fp-grid-status{font-size:11px;font-weight:800;color:#64748b}.fp-grid-status.saved{color:#047857}.fp-grid-status.saving{color:#a16207}.fp-grid-status.error{color:#b91c1c}.fp-col-picker{position:relative}.fp-col-picker>summary{list-style:none;cursor:pointer}.fp-col-picker>summary::-webkit-details-marker{display:none}.fp-col-menu{position:absolute;right:0;top:calc(100% + 7px);z-index:120;min-width:230px;max-height:390px;overflow:auto;background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:12px;box-shadow:0 16px 38px rgba(15,35,80,.2)}.fp-col-menu strong{display:block;margin-bottom:8px}.fp-col-menu label{display:flex;align-items:center;gap:8px;padding:6px 2px;font-size:12px}.fp-col-menu input{width:16px;height:16px}.fp-grid-wrap{overflow-x:auto;overflow-y:visible;border:1px solid #e2e8f0;border-radius:12px}.fp-grid{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;font-size:12px}.fp-grid th,.fp-grid td{padding:8px 9px;border-bottom:1px solid #e9eff8;border-right:1px solid #f1f5f9;vertical-align:middle;overflow:visible;text-overflow:ellipsis}.fp-grid td{overflow:hidden}.fp-grid thead th{position:relative;background:#f7faff;color:#243b70;font-size:10px;text-transform:uppercase;font-weight:900}.fp-grid thead th.fp-filter-open{z-index:95}.fp-grid thead tr:first-child th{position:sticky;top:0;z-index:12}.fp-grid tbody tr:nth-child(even) td{background:#f8fafc}.fp-grid tbody tr:hover td{background:#eef5ff}.fp-th{display:flex;align-items:center;gap:5px;min-height:22px;cursor:pointer}.fp-th-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.fp-drag{width:14px;color:#94a3b8;cursor:grab;letter-spacing:-2px}.fp-resizer{position:absolute;right:-3px;top:0;width:7px;height:100%;cursor:col-resize}.fp-resizer:after{content:"";position:absolute;left:3px;top:20%;bottom:20%;width:1px;background:#c8d4e8}.fp-sort-asc .fp-th-label:after{content:' ▲';font-size:8px}.fp-sort-desc .fp-th-label:after{content:' ▼';font-size:8px}.fp-filter-menu{position:relative;flex:0 0 auto;text-transform:none;letter-spacing:normal}.fp-filter-menu>summary{list-style:none}.fp-filter-menu>summary::-webkit-details-marker{display:none}.fp-filter-trigger{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:6px;color:#71809c;cursor:pointer;background:transparent}.fp-filter-trigger:hover{background:#eaf1ff;color:#2448b8;border-color:#cfdbf5}.fp-filter-trigger svg{width:14px;height:14px}.fp-filter-menu.has-filter .fp-filter-trigger,.fp-filter-menu[open] .fp-filter-trigger{background:#e8efff;color:#163da8;border-color:#b9c9f3;box-shadow:inset 0 -2px 0 #3558c9}.fp-filter-popover{position:absolute;top:calc(100% + 7px);right:0;z-index:120;width:240px;padding:12px;background:#fff;border:1px solid #d9e3f2;border-radius:12px;box-shadow:0 16px 38px rgba(15,35,80,.22);text-transform:none;letter-spacing:normal;font-weight:600}.fp-filter-title{font-size:12px;font-weight:900;color:#18336f;margin-bottom:8px}.fp-filter-input{width:100%;height:36px;border:1px solid #ccd8ea;border-radius:8px;padding:6px 9px;background:#fff;color:#0f172a;font-size:12px;outline:none;text-transform:none}.fp-filter-input:focus{border-color:#4c6fff;box-shadow:0 0 0 2px rgba(76,111,255,.10)}.fp-filter-actions-menu{display:flex;gap:7px;margin-top:9px}.fp-filter-actions-menu .btn{min-height:32px;padding:6px 10px;font-size:11px;flex:1;justify-content:center}.fp-hidden{display:none!important}.fp-actions-col{position:sticky!important;right:0;z-index:10;background:#fff!important;box-shadow:-5px 0 10px rgba(15,35,80,.05)}.fp-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.fp-actions .btn{padding:6px 8px;font-size:11px}.fp-wa-action{position:relative;flex:0 0 auto;width:58px;min-width:58px;height:48px;padding:4px 3px;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;border:1px solid #1ebe57;border-radius:10px;background:#25D366;color:#fff!important;text-decoration:none;text-align:center;font-weight:800;line-height:1;box-shadow:0 5px 12px rgba(37,211,102,.18);transition:background .14s ease,border-color .14s ease,transform .14s ease}.fp-wa-action:hover{background:#1fbd5a;border-color:#18a94c;color:#fff!important;transform:translateY(-1px)}.fp-wa-icon{display:block;font-size:17px;line-height:18px;font-weight:900}.fp-wa-label{display:block;max-width:100%;font-size:8.5px;line-height:1.05;font-weight:850}.fp-disabled{opacity:.45;pointer-events:none;filter:grayscale(.25)}.fp-mobile{display:none}.fp-mobile-card{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #dbe4f0;border-radius:13px;padding:11px 12px;box-shadow:0 3px 10px rgba(15,23,42,.04)}.fp-mobile-top{display:flex;align-items:center;gap:8px}.fp-mobile-code{font-size:11px;font-weight:900;color:#3558c9}.fp-mobile-name{font-size:14px;font-weight:900;color:#0f172a;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.fp-mobile-line{font-size:11px;color:#64748b;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.fp-no-results{display:none;padding:20px;text-align:center;color:#64748b}.fp-drop-before{box-shadow:inset 3px 0 0 #3558c9}.fp-drop-after{box-shadow:inset -3px 0 0 #3558c9}.fp-dragging{opacity:.55}
@media(max-width:980px){.fp-filter-line{grid-template-columns:1fr 1fr}.fp-filter-line .fp-search{grid-column:1/-1}.fp-filter-actions{grid-column:1/-1}.fp-grid-tools{width:100%;margin-left:0;justify-content:flex-end}}
@media(max-width:720px){.fp-filter-line{grid-template-columns:1fr 1fr}.fp-grid-wrap{display:none}.fp-grid-tools{display:none}.fp-mobile{display:grid;gap:8px}.finance-mobile-note{display:block}.fp-filter-actions .btn{flex:1}.fp-filter-line input,.fp-filter-line select{min-height:44px}}
</style>
<div class="finance-card finance-filter-card">
  <form method="get" class="fp-filter-line" autocomplete="off">
    <input type="hidden" name="searched" value="1">
    <div class="fp-search"><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Nome, nº, CPF/CNPJ, telefone, e-mail ou endereço"></div>
    <?php if (!$customerCrossContext): ?><div><label>Papel</label><select name="role"><option value="">Todos</option><option value="customer" <?= $role==='customer'?'selected':'' ?>>Clientes</option><option value="supplier" <?= $role==='supplier'?'selected':'' ?>>Fornecedores</option><option value="seller" <?= $role==='seller'?'selected':'' ?>>Vendedores</option><option value="employee" <?= $role==='employee'?'selected':'' ?>>Colaboradores</option><option value="professional" <?= $role==='professional'?'selected':'' ?>>Profissionais</option><option value="carrier" <?= $role==='carrier'?'selected':'' ?>>Transportadoras</option></select></div><?php else: ?><input type="hidden" name="role" value="<?= $financePersonCrossContext ? 'supplier' : 'customer' ?>"><?php if ($financePersonCrossContext): ?><input type="hidden" name="mc_finance" value="1"><?php endif; ?><?php endif; ?>
    <div><label>Aniversariantes de</label><input name="birthday_from" inputmode="numeric" maxlength="5" value="<?=finance_h($birthdayFrom)?>" placeholder="dd/mm" data-birthday-mask></div>
    <div><label>Até</label><input name="birthday_to" inputmode="numeric" maxlength="5" value="<?=finance_h($birthdayTo)?>" placeholder="dd/mm" data-birthday-mask></div>
    <div class="fp-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="financeiro_pessoas.php">Limpar</a></div>
  </form>
</div>

<div class="finance-card">
  <div class="finance-card-head fp-grid-head"><h2 class="finance-subtitle"><?= $customerCrossContext ? ($financePersonCrossContext ? 'Fornecedores cadastrados' : 'Clientes cadastrados') : 'Pessoas cadastradas' ?></h2><span class="finance-muted"><?= count($rows) ?> registro(s)</span>
    <div class="fp-grid-tools"><span id="fpGridStatus" class="fp-grid-status"></span><details class="fp-col-picker"><summary class="btn">☷ Colunas</summary><div class="fp-col-menu"><strong>Colunas visíveis</strong><?php foreach($fpGridLabels as $key=>$label):if($key==='acoes')continue;?><label><input type="checkbox" data-fp-toggle value="<?=finance_h($key)?>" <?=in_array($key,(array)$fpGridPrefs['visible'],true)?'checked':''?>> <?=finance_h($label)?></label><?php endforeach;?><button type="button" class="btn" id="fpGridReset" style="width:100%;margin-top:8px">Restaurar padrão</button></div></details></div>
  </div>
  <p class="finance-mobile-note">No celular, cada pessoa aparece em um cartão com código, nome, telefone e endereço. Toque no cartão para editar.</p>
  <?php if(!$rows): ?><div class="finance-empty"><?= $didSearchPeople ? 'Nenhuma pessoa encontrada para os filtros informados.' : 'Use os filtros acima para localizar pessoas.' ?></div><?php else: ?>
  <div class="fp-grid-wrap"><table class="fp-grid" id="fpPeopleGrid"><thead><tr>
    <?php foreach($fpGridLabels as $key=>$label):?><th data-col="<?=finance_h($key)?>" draggable="<?=$key==='acoes'?'false':'true'?>" class="<?=$key==='acoes'?'fp-actions-col':''?>"><div class="fp-th"><?php if($key!=='acoes'):?><span class="fp-drag">⋮⋮</span><?php endif;?><span class="fp-th-label"><?=finance_h($label)?></span><?php if($key!=='acoes'):?><?=fp_grid_filter_menu($key,$label)?><?php endif;?></div><?php if($key!=='acoes'):?><span class="fp-resizer"></span><?php endif;?></th><?php endforeach;?>
  </tr></thead><tbody>
  <?php foreach($rows as $idx=>$r):$numeroPessoa=company_sequence_display($r,'pessoas');$phone=trim((string)($r['whatsapp']??''))?:trim((string)($r['phone']??''));$addr=fp_address_full($r);$birth=(($r['person_type']??'')==='FISICA')?fp_date_br($r['birth_opening_date']??''):'—';$editUrl='financeiro_pessoas.php?edit='.(int)$r['id'].($customerCrossContext?($financePersonCrossContext?'&role=supplier&mc_finance=1':'&role=customer&mc_customer=1'):'');$canBirthday=(($r['person_type']??'')==='FISICA'&&trim((string)($r['birth_opening_date']??''))!==''&&$phone!==''); ?>
    <tr data-fp-row="<?=(int)$r['id']?>">
      <td data-col="codigo" data-sort="<?=(int)$numeroPessoa?>"><strong>#<?=(int)$numeroPessoa?></strong></td>
      <td data-col="nome" data-sort="<?=finance_h(mb_strtolower((string)$r['name'],'UTF-8'))?>"><strong><?=finance_h($r['name'])?></strong><?php if(trim((string)($r['fantasy_name']??''))!==''):?><br><span class="finance-muted"><?=finance_h($r['fantasy_name'])?></span><?php endif;?></td>
      <td data-col="nascimento" data-sort="<?=finance_h((string)($r['birth_opening_date']??''))?>"><?=$birth?></td>
      <td data-col="documento"><?=finance_h($r['document']??'')?><?php if(!empty($r['state_registration'])):?><br><span class="finance-muted">IE: <?=finance_h($r['state_registration'])?></span><?php endif;?></td>
      <td data-col="telefone"><?=finance_h($phone?:'—')?></td>
      <td data-col="email"><?=finance_h(trim((string)($r['email']??''))?:'—')?></td>
      <td data-col="endereco" title="<?=finance_h($addr)?>"><?=finance_h($addr?:'—')?></td>
      <td data-col="papeis"><?=finance_roles_badges($r)?></td>
      <td data-col="acoes" class="fp-actions-col"><div class="fp-actions"><a class="btn" href="<?=finance_h($editUrl)?>">Editar</a><?php if(!$customerCrossContext):?><a class="fp-wa-action<?=$canBirthday?'':' fp-disabled'?>" <?=$canBirthday?'href="pessoa_aniversario_whatsapp.php?id='.(int)$r['id'].'&amp;return_to='.rawurlencode($peopleReturnUrl).'" title="Enviar mensagem de aniversário por WhatsApp" aria-label="Enviar mensagem de aniversário por WhatsApp"':'aria-disabled="true" title="Informe data de nascimento e WhatsApp/telefone"'?>> <span class="fp-wa-icon" aria-hidden="true">💬</span><span class="fp-wa-label">WhatsApp</span></a><form method="post" onsubmit="return confirm('Inativar esta pessoa?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-danger">Inativar</button></form><?php endif;?></div></td>
    </tr>
  <?php endforeach;?></tbody></table></div>
  <div class="fp-mobile" id="fpPeopleMobile"><?php foreach($rows as $r):$numeroPessoa=company_sequence_display($r,'pessoas');$phone=trim((string)($r['whatsapp']??''))?:trim((string)($r['phone']??''));$addr=fp_address_full($r);$editUrl='financeiro_pessoas.php?edit='.(int)$r['id'].($customerCrossContext?($financePersonCrossContext?'&role=supplier&mc_finance=1':'&role=customer&mc_customer=1'):'');?><a class="fp-mobile-card" data-fp-mobile-row="<?=(int)$r['id']?>" href="<?=finance_h($editUrl)?>"><div class="fp-mobile-top"><span class="fp-mobile-code">#<?=(int)$numeroPessoa?></span><span class="fp-mobile-name"><?=finance_h($r['name'])?></span></div><div class="fp-mobile-line">☎ <?=finance_h($phone?:'Sem telefone')?></div><div class="fp-mobile-line">⌖ <?=finance_h($addr?:'Sem endereço')?></div></a><?php endforeach;?></div>
  <div id="fpNoClientResults" class="fp-no-results">Nenhum registro corresponde aos filtros das colunas.</div>
  <?php endif; ?>
</div>
<script>
(()=>{
 document.querySelectorAll('[data-birthday-mask]').forEach(el=>el.addEventListener('input',()=>{let v=el.value.replace(/\D/g,'').slice(0,4);if(v.length>2)v=v.slice(0,2)+'/'+v.slice(2);el.value=v;}));
 const table=document.getElementById('fpPeopleGrid');if(!table)return;const csrf=<?=json_encode(fp_grid_csrf_token())?>;const defaults=<?=json_encode($fpGridDefaults,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;let state=<?=json_encode($fpGridPrefs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const cols=<?=json_encode($fpGridColumns)?>;const actions='acoes';let saveTimer=null,seq=0,sort={col:'',dir:1};const status=document.getElementById('fpGridStatus');
 const getHeads=()=>Array.from(table.tHead.rows[0].cells);const getRows=()=>Array.from(table.tBodies[0].rows);const esc=s=>String(s??'').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
 const setStatus=(t,c='')=>{status.textContent=t;status.className='fp-grid-status'+(c?' '+c:'')};
 function normalize(){state.visible=(state.visible||[]).filter(c=>cols.includes(c));if(!state.visible.length)state.visible=cols.slice();state.order=(state.order||[]).filter(c=>cols.includes(c)&&c!==actions);cols.forEach(c=>{if(c!==actions&&!state.order.includes(c))state.order.push(c)});state.order.push(actions);state.widths=state.widths||{};}
 function reorder(){normalize();const order=state.order;[...table.tHead.rows].forEach(row=>{const map=new Map(Array.from(row.cells).map(c=>[c.dataset.col,c]));order.forEach(c=>{if(map.get(c))row.appendChild(map.get(c))})});getRows().forEach(row=>{const map=new Map(Array.from(row.cells).map(c=>[c.dataset.col,c]));order.forEach(c=>{if(map.get(c))row.appendChild(map.get(c))})});}
 function apply(){normalize();reorder();const vis=new Set(state.visible);[...table.querySelectorAll('[data-col]')].forEach(el=>el.classList.toggle('fp-hidden',!vis.has(el.dataset.col)));getHeads().forEach(h=>{const w=Number(state.widths[h.dataset.col]||defaults.widths[h.dataset.col]||120);h.style.width=w+'px';h.style.minWidth=w+'px';});document.querySelectorAll('[data-fp-toggle]').forEach(x=>x.checked=vis.has(x.value));}
 function payload(){return{visible:state.visible.slice(),order:state.order.slice(),widths:{...state.widths}}}
 async function save(){const my=++seq;setStatus('Salvando…','saving');try{const b=new URLSearchParams();b.set('action','save_people_grid_preferences');b.set('csrf',csrf);b.set('preferences',JSON.stringify(payload()));const r=await fetch('financeiro_pessoas.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:b.toString(),credentials:'same-origin'});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Falha');if(my===seq){setStatus('Salvo no usuário','saved');setTimeout(()=>{if(my===seq)setStatus('')},1600)}}catch(e){if(my===seq)setStatus('Não foi possível salvar','error')}}
 const schedule=()=>{clearTimeout(saveTimer);saveTimer=setTimeout(save,320)};
 document.querySelectorAll('[data-fp-toggle]').forEach(cb=>cb.addEventListener('change',()=>{if(cb.checked){if(!state.visible.includes(cb.value))state.visible.push(cb.value)}else state.visible=state.visible.filter(c=>c!==cb.value);if(!state.visible.includes(actions))state.visible.push(actions);apply();schedule()}));
 document.getElementById('fpGridReset')?.addEventListener('click',()=>{state=JSON.parse(JSON.stringify(defaults));apply();schedule()});
 getHeads().forEach(th=>{const col=th.dataset.col;if(col===actions)return;th.addEventListener('click',e=>{if(e.target.closest('.fp-resizer,.fp-drag,.fp-filter-menu'))return;sort.dir=sort.col===col?-sort.dir:1;sort.col=col;getHeads().forEach(h=>h.classList.remove('fp-sort-asc','fp-sort-desc'));th.classList.add(sort.dir===1?'fp-sort-asc':'fp-sort-desc');const rows=getRows();rows.sort((a,b)=>{const ca=a.querySelector('[data-col="'+col+'"]'),cb=b.querySelector('[data-col="'+col+'"]');let va=ca?.dataset.sort??ca?.innerText??'',vb=cb?.dataset.sort??cb?.innerText??'';const na=Number(va),nb=Number(vb);if(va!==''&&vb!==''&&!Number.isNaN(na)&&!Number.isNaN(nb))return(na-nb)*sort.dir;return String(va).localeCompare(String(vb),'pt-BR',{numeric:true,sensitivity:'base'})*sort.dir});rows.forEach(r=>table.tBodies[0].appendChild(r));});
  th.addEventListener('dragstart',e=>{th.classList.add('fp-dragging');e.dataTransfer.setData('text/plain',col)});th.addEventListener('dragend',()=>{th.classList.remove('fp-dragging');getHeads().forEach(h=>h.classList.remove('fp-drop-before','fp-drop-after'))});th.addEventListener('dragover',e=>{e.preventDefault();if(th.dataset.col===actions)return;const rect=th.getBoundingClientRect();th.classList.toggle('fp-drop-before',e.clientX<rect.left+rect.width/2);th.classList.toggle('fp-drop-after',e.clientX>=rect.left+rect.width/2)});th.addEventListener('drop',e=>{e.preventDefault();const src=e.dataTransfer.getData('text/plain'),dst=col;if(!src||src===dst||src===actions)return;state.order=state.order.filter(c=>c!==src&&c!==actions);const i=state.order.indexOf(dst);const before=th.classList.contains('fp-drop-before');state.order.splice(Math.max(0,i+(before?0:1)),0,src);state.order.push(actions);apply();schedule()});
  const rz=th.querySelector('.fp-resizer');if(rz){rz.addEventListener('mousedown',e=>{e.preventDefault();e.stopPropagation();const x=e.clientX,w=th.getBoundingClientRect().width;const move=ev=>{state.widths[col]=Math.max(40,Math.min(520,Math.round(w+ev.clientX-x)));apply()};const up=()=>{document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up);schedule()};document.addEventListener('mousemove',move);document.addEventListener('mouseup',up)});}
 });
 function applyFilters(){const fs={};document.querySelectorAll('[data-fp-filter]').forEach(i=>{const v=esc(i.value.trim());const menu=i.closest('[data-fp-filter-menu]');menu?.classList.toggle('has-filter',!!v);if(v)fs[i.dataset.fpFilter]=v});let shown=0;getRows().forEach(row=>{let ok=true;for(const [c,v] of Object.entries(fs)){const cell=row.querySelector('[data-col="'+c+'"]');if(!esc(cell?.innerText||'').includes(v)){ok=false;break}}row.style.display=ok?'':'none';document.querySelector('[data-fp-mobile-row="'+row.dataset.fpRow+'"]')?.classList.toggle('fp-hidden',!ok);if(ok)shown++});document.getElementById('fpNoClientResults').style.display=shown?'none':'block';}
 const filterMenus=Array.from(document.querySelectorAll('[data-fp-filter-menu]'));
 filterMenus.forEach(menu=>{menu.addEventListener('toggle',function(){const th=this.closest('th');th?.classList.toggle('fp-filter-open',this.open);if(!this.open)return;filterMenus.forEach(other=>{if(other!==this&&other.open){other.open=false;other.closest('th')?.classList.remove('fp-filter-open')}});setTimeout(()=>this.querySelector('[data-fp-filter]')?.focus(),0)});menu.addEventListener('click',e=>e.stopPropagation());menu.addEventListener('pointerdown',e=>e.stopPropagation());});
 document.addEventListener('click',e=>{if(e.target.closest('[data-fp-filter-menu]'))return;filterMenus.forEach(menu=>{menu.open=false;menu.closest('th')?.classList.remove('fp-filter-open')})});
 document.querySelectorAll('[data-fp-filter]').forEach(i=>{i.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();applyFilters();const m=i.closest('[data-fp-filter-menu]');if(m)m.open=false;}if(e.key==='Escape'){const m=i.closest('[data-fp-filter-menu]');if(m)m.open=false;}})});
 document.querySelectorAll('[data-fp-filter-apply]').forEach(b=>b.addEventListener('click',()=>{applyFilters();const m=b.closest('[data-fp-filter-menu]');if(m)m.open=false;}));
 document.querySelectorAll('[data-fp-filter-clear]').forEach(b=>b.addEventListener('click',()=>{const m=b.closest('[data-fp-filter-menu]');const i=m?.querySelector('[data-fp-filter]');if(i)i.value='';applyFilters();if(m)m.open=false;}));
 apply();applyFilters();
})();
</script>
<?php if ($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div>
        <h2><?= $customerCrossContext ? ($financePersonCrossContext ? ($editId > 0 ? 'Editar fornecedor' : 'Novo fornecedor') : ($editId > 0 ? 'Editar cliente' : 'Novo cliente')) : ($editId > 0 ? 'Editar pessoa' : 'Nova pessoa') ?></h2>
        <p class="finance-muted">Informe primeiro o tipo de pessoa. Para CNPJ e CEP, o sistema busca os dados automaticamente.</p>
      </div>
      <a class="finance-modal-close" href="financeiro_pessoas.php" aria-label="Fechar">×</a>
    </div>

    <?php $pcContacts = ((int)($edit['id'] ?? 0) > 0 && $pcReady) ? person_contacts_by_person($pdo, $cid, (int)$edit['id']) : [];
      // Abas do cadastro: Dados, Endereços, Contatos e Anexos.
      $adTab = (int)($edit['id'] ?? 0) > 0 && !$financePersonCrossContext;
      $fpTabs = (int)($edit['id'] ?? 0) > 0 && ($paAccessAllowed || $pcReady || $adTab); ?>
    <?php if ($fpTabs): ?>
    <style>
    .pa-tabs{display:flex;gap:8px;margin:12px 0 16px;border-bottom:1px solid #d9e5f4;padding-bottom:9px}
    .pa-tabs button{min-width:110px;border:1px solid #cbd5e1;padding:9px 15px;border-radius:9px;background:white;color:#334155;cursor:pointer;font-weight:700}
    .pa-tabs button[aria-selected="true"]{color:#1d4ed8;background:#eff6ff;border-color:#93c5fd}
    .pa-list{width:100%;border-collapse:collapse;font-size:13px}.pa-list th,.pa-list td{border-bottom:1px solid #e2e8f0;padding:9px;text-align:left}

    .pa-upload-form{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:15px 0}
    .pa-upload-field{display:flex;flex-direction:column;gap:6px;flex:1 1 340px;min-width:220px}
    .pa-upload-field label{font-size:12px;font-weight:700;color:#334155}
    .pa-upload-field input[type=file]{display:block;width:100%;height:42px;min-height:42px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;background:#fff}
    .pa-upload-submit{height:42px;min-height:42px;align-self:flex-end;white-space:nowrap;margin:0}
    @media(max-width:640px){.pa-upload-field{flex-basis:100%;min-width:0}.pa-upload-submit{width:100%}}
    </style>
    <div class="pa-tabs" role="tablist" aria-label="Abas do cadastro de pessoa">
      <button type="button" role="tab" id="paTabDados" aria-controls="financePersonForm" aria-selected="true" data-pa-tab="dados">Dados</button>
      <?php if ($adTab): ?><button type="button" role="tab" id="paTabEnderecos" aria-controls="adPanel" aria-selected="false" data-pa-tab="enderecos">Endereços (<?=count($personAddresses)?>)</button><?php endif; ?>
      <?php if ($pcReady): ?><button type="button" role="tab" id="paTabContatos" aria-controls="pcPanel" aria-selected="false" data-pa-tab="contatos">Contatos (<?=count($pcContacts)?>)</button><?php endif; ?>
      <?php if ($paAccessAllowed): ?><button type="button" role="tab" id="paTabAnexos" aria-controls="paPanel" aria-selected="false" data-pa-tab="anexos">Anexos (<?=count($paRows)?>)</button><?php endif; ?>
    </div>
    <?php endif; ?>
    <form method="post" class="finance-form" id="financePersonForm">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <?php if ($financePersonCrossContext): ?><input type="hidden" name="mc_finance" value="1"><input type="hidden" name="role" value="supplier"><?php endif; ?>

      <div class="finance-form-section">
        <h3>Tipo e documento</h3>
        <div class="finance-form-grid three">
          <div>
            <label>Número</label>
            <input value="<?= (int)company_sequence_display((array)($edit ?? []), 'pessoas') ?: 'Novo' ?>" readonly title="Número visível por empresa. O ID fica reservado para controle interno do banco.">
          </div>
          <div>
            <label>Tipo de pessoa *</label>
            <select name="person_type" id="personType" required>
              <option value="FISICA" <?= (($edit['person_type'] ?? '') === 'FISICA')?'selected':'' ?>>Pessoa Física</option>
              <option value="JURIDICA" <?= (($edit['person_type'] ?? '') !== 'FISICA')?'selected':'' ?>>Pessoa Jurídica</option>
            </select>
          </div>
          <div>
            <label id="documentLabel">CNPJ</label>
            <div class="finance-field-inline">
              <input name="document" id="personDocument" value="<?= finance_h($edit['document'] ?? '') ?>" inputmode="numeric" autocomplete="off" placeholder="Digite o CNPJ">
              <button type="button" class="btn" id="btnLookupCnpj">Buscar CNPJ</button>
            </div>
            <div class="finance-lookup-status" id="documentLookupStatus"></div>
          </div>
        </div>
      </div>

      <div class="finance-form-section">
        <h3>Dados principais</h3>
        <div class="finance-form-grid">
          <div>
            <label>Nome/Razão social *</label>
            <input name="name" id="personName" value="<?= finance_h($edit['name'] ?? '') ?>" required>
          </div>
          <div id="personFantasyWrap">
            <label>Nome fantasia</label>
            <input name="fantasy_name" id="personFantasy" value="<?= finance_h($edit['fantasy_name'] ?? '') ?>">
          </div>
          <div>
            <label id="birthOpeningLabel">Data de abertura</label>
            <input type="date" name="birth_opening_date" id="personBirthOpeningDate" value="<?= finance_h($edit['birth_opening_date'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="finance-form-section">
        <h3>Dados fiscais e identidade</h3>
        <div class="finance-form-grid three">
          <div id="personTaxRegimeWrap">
            <label>Regime tributário (pessoa jurídica)</label>
            <select name="tax_regime" id="personTaxRegime">
              <?php foreach ([
                '' => 'Não informado', 'MEI' => 'MEI', 'SIMPLES_NACIONAL' => 'Simples Nacional',
                'LUCRO_PRESUMIDO' => 'Lucro Presumido', 'LUCRO_REAL' => 'Lucro Real',
                'REGIME_HIBRIDO' => 'Regime Híbrido (classificação interna)'
              ] as $regimeCode=>$regimeLabel): ?>
                <option value="<?= finance_h($regimeCode) ?>" <?= (string)($edit['tax_regime'] ?? '')===$regimeCode?'selected':'' ?>><?= finance_h($regimeLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="finance-muted" style="font-size:12px;margin-top:5px">Este cadastro não altera a NFC-e. O cálculo ST da NF-e depende de habilitação fiscal e de regras adicionais.</div>
          </div>
          <div id="personStateRegistrationWrap">
            <label>Inscrição Estadual (IE)</label>
            <input name="state_registration" id="personStateRegistration" value="<?= finance_h($edit['state_registration'] ?? '') ?>" placeholder="Número da IE ou ISENTO">
            <div class="finance-muted" style="font-size:12px;margin-top:5px">Para contribuinte de ICMS, informe a IE. Quando aplicável, digite ISENTO.</div>
          </div>
          <div id="personMunicipalRegistrationWrap">
            <label>Inscrição Municipal (IM)</label>
            <input name="municipal_registration" id="personMunicipalRegistration" value="<?= finance_h($edit['municipal_registration'] ?? '') ?>" placeholder="Inscrição municipal">
          </div>
          <div id="personIdentityWrap">
            <label>RG / Identidade</label>
            <input name="identity_document" id="personIdentity" value="<?= finance_h($edit['identity_document'] ?? '') ?>" placeholder="Documento de identidade">
          </div>
          <div id="personIdentityIssuerWrap">
            <label>Órgão expedidor</label>
            <input name="identity_issuer" id="personIdentityIssuer" value="<?= finance_h($edit['identity_issuer'] ?? '') ?>" placeholder="Ex.: SSP">
          </div>
          <div id="personIdentityStateWrap">
            <label>UF da identidade</label>
            <input name="identity_state" id="personIdentityState" maxlength="2" value="<?= finance_h($edit['identity_state'] ?? '') ?>" placeholder="UF">
          </div>
        </div>
      </div>

      <div class="finance-form-section">
        <h3>Contato</h3>
        <div class="finance-form-grid three">
          <div><label>E-mail</label><input name="email" id="personEmail" type="email" value="<?= finance_h($edit['email'] ?? '') ?>"></div>
          <div><label>WhatsApp</label><input name="whatsapp" id="personWhatsapp" value="<?= finance_h($edit['whatsapp'] ?? '') ?>"></div>
          <div><label>Telefone</label><input name="phone" id="personPhone" value="<?= finance_h($edit['phone'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="finance-form-section">
        <h3>Endereço</h3>
        <div class="finance-form-grid three">
          <div>
            <label>CEP</label>
            <input name="zip_code" id="personZip" value="<?= finance_h($edit['zip_code'] ?? '') ?>" inputmode="numeric" autocomplete="postal-code" placeholder="Digite o CEP">
            <div class="finance-lookup-status" id="zipLookupStatus"></div>
          </div>
          <div class="span-2"><label>Endereço</label><input name="address" id="personAddress" value="<?= finance_h($edit['address'] ?? '') ?>"></div>
          <div><label>Número</label><input name="number" id="personNumber" value="<?= finance_h($edit['number'] ?? '') ?>"></div>
          <div><label>Bairro</label><input name="district" id="personDistrict" value="<?= finance_h($edit['district'] ?? '') ?>"></div>
          <div><label>Cidade</label><input name="city" id="personCity" value="<?= finance_h($edit['city'] ?? '') ?>"></div>
          <div><label>Código IBGE da cidade</label><input name="city_ibge" id="personCityIbge" inputmode="numeric" maxlength="7" value="<?= finance_h($edit['city_ibge'] ?? '') ?>"></div>
          <div><label>UF</label><input name="state" id="personState" maxlength="2" value="<?= finance_h($edit['state'] ?? '') ?>"></div>
          <div class="span-2"><label>Complemento</label><input name="complement" id="personComplement" value="<?= finance_h($edit['complement'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="finance-form-section">
        <h3>Classificação</h3>
        <?php if ($customerCrossContext): ?>
          <?php if ($financePersonCrossContext): ?>
            <input type="hidden" name="is_supplier" value="1">
            <div class="finance-muted">V11.4 Multiempresa: este formulário grava somente o papel <strong>Fornecedor</strong>. Outros papéis existentes são preservados e não podem ser alterados por esta aba.</div>
          <?php else: ?>
            <input type="hidden" name="is_customer" value="1">
            <div class="finance-muted">V7 Multiempresa: este formulário grava somente o papel <strong>Cliente</strong>. Outros papéis existentes são preservados e não podem ser alterados por esta aba.</div>
          <?php endif; ?>
        <?php else: ?>
        <div class="finance-form-grid">
          <label class="checkline"><input type="checkbox" name="is_customer" <?= !empty($edit['is_customer']) ? 'checked' : '' ?>> Cliente</label>
          <label class="checkline"><input type="checkbox" name="is_supplier" <?= !empty($edit['is_supplier']) ? 'checked' : '' ?>> Fornecedor</label>
          <label class="checkline"><input type="checkbox" name="is_seller" <?= !empty($edit['is_seller']) ? 'checked' : '' ?>> Vendedor</label>
          <label class="checkline"><input type="checkbox" name="is_employee" <?= !empty($edit['is_employee']) ? 'checked' : '' ?>> Colaborador</label>
          <label class="checkline"><input type="checkbox" name="is_professional" <?= !empty($edit['is_professional']) ? 'checked' : '' ?>> Profissional</label>
          <label class="checkline"><input type="checkbox" name="is_carrier" <?= !empty($edit['is_carrier']) ? 'checked' : '' ?>> Transportadora</label>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$financePersonCrossContext): ?>
      <div class="finance-form-section">
        <h3>Regras comerciais</h3>
        <div class="finance-form-grid">
          <div><label>Limite de crédito</label><input name="credit_limit" inputmode="decimal" placeholder="Em branco = sem limite" value="<?= ($edit['credit_limit'] ?? null) === null || ($edit['credit_limit'] ?? '') === '' ? '' : finance_h(number_format((float)$edit['credit_limit'],2,',','.')) ?>"><div class="finance-muted" style="font-size:12px;margin-top:5px">Usado nas vendas a prazo. Zero bloqueia crédito; em branco não limita.</div></div>
          <?php if (!$customerCrossContext): ?><div><label>Comissão do vendedor (%)</label><input name="seller_commission_percent" inputmode="decimal" placeholder="Ex.: 5,00" value="<?= ($edit['seller_commission_percent'] ?? null) === null || ($edit['seller_commission_percent'] ?? '') === '' ? '' : finance_h(number_format((float)$edit['seller_commission_percent'],2,',','')) ?>"><div class="finance-muted" style="font-size:12px;margin-top:5px">Aplicada quando esta pessoa estiver vinculada como vendedor. A venda guarda o percentual histórico.</div></div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="finance-form-section"><h3>Observações</h3><textarea name="notes" rows="4"><?= finance_h($edit['notes'] ?? '') ?></textarea></div>
      <div class="finance-form-actions"><a class="btn" href="<?= $financePersonCrossContext ? 'financeiro_pessoas.php?mc_finance=1&role=supplier' : 'financeiro_pessoas.php' ?>">Cancelar</a><button class="btn btn-primary"><?= $customerCrossContext ? ($financePersonCrossContext ? 'Salvar fornecedor' : 'Salvar cliente') : 'Salvar pessoa' ?></button></div>
    </form>

    <?php if ((int)($edit['id'] ?? 0) > 0 && $paAccessAllowed): ?>
    <section id="paPanel" role="tabpanel" aria-labelledby="paTabAnexos" hidden style="padding:12px 4px 20px">
      <h3>Anexos da pessoa</h3>
      <p class="finance-muted">Os arquivos ficam armazenados em pasta privada fora dos fontes e não são inseridos no MySQL.</p>
      <?php if ($paListError): ?><div class="msg err"><?=finance_h($paListError)?></div><?php endif; ?>
      <?php if (isset($_GET['pa_result'])): ?><p class="finance-muted"><?=finance_h((string)$_GET['pa_result'])?></p><?php endif; ?>
      <?php if (!$paListError): ?>
      <?php if ($paUploadAllowed): ?>
      <form method="post" enctype="multipart/form-data" class="pa-upload-form">
        <input type="hidden" name="action" value="pa_upload"><input type="hidden" name="person_id" value="<?= (int)$editId ?>">
        <input type="hidden" name="csrf" value="<?=finance_h(fp_grid_csrf_token())?>">
        <div class="pa-upload-field"><label for="paUploadFile">Selecionar anexo (máximo 30 MB)</label><input id="paUploadFile" type="file" name="person_attachment" required accept=".pdf,.jpg,.jpeg,.png,.webp,.bmp,.doc,.docx,.xls,.xlsx,.txt"></div>
        <button class="btn btn-primary pa-upload-submit" type="submit">Adicionar anexo</button>
      </form>
      <?php endif; ?>
      <?php if (!$paRows): ?><p class="finance-muted">Nenhum anexo cadastrado.</p><?php else: ?>
      <div style="overflow-x:auto"><table class="pa-list"><thead><tr><th>Arquivo</th><th>Tamanho</th><th>Origem</th><th>Data</th><th>Ações</th></tr></thead><tbody>
      <?php foreach ($paRows as $ar):
        $params=['edit'=>$editId,'person_id'=>$editId,'action'=>'pa_download','attachment_id'=>(int)$ar['id']];
        if ($customerCrossContext) {$params['role']=$financePersonCrossContext?'supplier':'customer';$params[$financePersonCrossContext?'mc_finance':'mc_customer']='1';}
      ?>
       <tr><td><?=finance_h($ar['original_name'])?></td><td><?=finance_h(number_format(((int)$ar['size_stored'])/1024,1,',','.'))?> KB</td><td><?=finance_h($ar['source_system']?:'Manual')?></td><td><?=finance_h($ar['created_at'])?></td>
       <td><div style="display:flex;gap:8px;flex-wrap:wrap">
         <?php if ($paViewAllowed): ?>
         <?php if(in_array(strtolower(trim((string)($ar['mime_type']??''))),['image/jpeg','image/png','image/webp','image/gif','image/bmp','application/pdf'],true)): $viewParams=$params; $viewParams['action']='pa_view'; ?>
         <a class="btn btn-sm btn-primary" href="financeiro_pessoas.php?<?=finance_h(http_build_query($viewParams))?>" target="_blank" rel="noopener noreferrer">Abrir</a>
         <?php endif; ?>
         <a class="btn btn-sm" href="financeiro_pessoas.php?<?=finance_h(http_build_query($params))?>">Baixar</a>
         <?php endif; ?>
         <?php if ($paDeleteAllowed): ?>
         <form method="post" onsubmit="return confirm('Remover este anexo do cadastro?')">
           <input type="hidden" name="action" value="pa_delete"><input type="hidden" name="person_id" value="<?= (int)$editId ?>">
           <input type="hidden" name="attachment_id" value="<?= (int)$ar['id'] ?>"><input type="hidden" name="csrf" value="<?=finance_h(fp_grid_csrf_token())?>">
           <button class="btn btn-sm btn-danger" type="submit">Remover</button>
         </form>
         <?php endif; ?></div></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <?php endif; endif; ?>
    </section>
    <?php endif; ?>
    <?php if ((int)($edit['id'] ?? 0) > 0 && $pcReady): $pcRoles = person_contact_roles(); ?>
    <script src="/assets/tag-input.js?v=20261112-1"></script>
    <section id="pcPanel" role="tabpanel" aria-labelledby="paTabContatos" hidden style="padding:12px 4px 20px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
        <div><h3 style="margin:0">Contatos</h3><p class="finance-muted" style="margin:4px 0 0">Quem fala pela empresa: responsável, financeiro, contador, compras... O WhatsApp do contato identifica de qual cliente é a conversa no Atendimento.</p></div>
        <button type="button" class="btn btn-primary" id="pcNewBtn">+ Novo contato</button>
      </div>
      <?php if (isset($_GET['pc_result'])): ?><div class="msg <?= str_starts_with((string)$_GET['pc_result'], 'Falha') ? 'err' : 'ok' ?>" style="margin-top:10px"><?=finance_h((string)$_GET['pc_result'])?></div><?php endif; ?>
      <form method="post" id="pcForm" class="finance-form" style="display:none;margin-top:12px;border:1px solid #dbeafe;border-radius:12px;padding:12px;background:#f8fbff">
        <input type="hidden" name="action" value="pc_save"><input type="hidden" name="person_id" value="<?= (int)$editId ?>"><input type="hidden" name="contact_id" id="pcId" value="0">
        <input type="hidden" name="csrf" value="<?=finance_h(fp_grid_csrf_token())?>">
        <div class="finance-form-grid three">
          <div><label>Nome *</label><input name="name" id="pcName" maxlength="120" required></div>
          <div><label>Função</label><input name="role" id="pcRole" maxlength="60" list="pcRoles" placeholder="Ex.: Financeiro, Contador"><datalist id="pcRoles"><?php foreach ($pcRoles as $r): ?><option value="<?=finance_h($r)?>"><?php endforeach; ?></datalist></div>
          <div><label>WhatsApp</label><input name="whatsapp" id="pcWa" maxlength="30" inputmode="tel" placeholder="(31) 99999-9999"></div>
          <div><label>Telefone</label><input name="phone" id="pcPhone" maxlength="30" inputmode="tel"></div>
          <div><label>E-mail</label><input name="email" id="pcEmail" maxlength="150" type="email"></div>
          <div><label>Observação</label><input name="notes" id="pcNotes" maxlength="500"></div>
        </div>
        <?php if (person_contacts_has_tags($pdo)): ?><div style="margin-top:8px"><label>Etiquetas (ex.: VIP, Inadimplente, Revenda — Enter para incluir)</label><input name="tags" id="pcTags" data-tag-input data-suggest="<?=finance_h(json_encode(person_contact_all_tags($pdo, $cid), JSON_UNESCAPED_UNICODE))?>" placeholder="Digite a etiqueta e tecle Enter"></div><?php endif; ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px">
          <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="is_primary" value="1" id="pcPrimary"> Contato principal</label>
          <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="active" value="1" id="pcActive" checked> Ativo</label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px"><button type="button" class="btn" id="pcCancel">Cancelar</button><button class="btn btn-primary" type="submit">Salvar contato</button></div>
      </form>
      <?php if (!$pcContacts): ?><p class="finance-muted" style="margin-top:12px">Nenhum contato cadastrado.</p><?php else: ?>
      <div style="overflow-x:auto;margin-top:12px"><table class="pa-list"><thead><tr><th>Nome</th><th>Função</th><th>WhatsApp</th><th>Telefone</th><th>E-mail</th><th>Ações</th></tr></thead><tbody>
      <?php foreach ($pcContacts as $ct): ?>
        <tr<?= (int)$ct['active'] ? '' : ' style="opacity:.55"' ?>>
          <td><strong><?=finance_h($ct['name'])?></strong><?= (int)$ct['is_primary'] ? ' <span class="badge ok">Principal</span>' : '' ?><?= (int)$ct['active'] ? '' : ' <span class="finance-muted">(inativo)</span>' ?><?php if (!empty($ct['notes'])): ?><br><span class="finance-muted"><?=finance_h($ct['notes'])?></span><?php endif; ?><?php if (!empty($ct['tags'])): ?><div style="margin-top:3px"><?=person_contact_tags_html((string)$ct['tags'])?></div><?php endif; ?></td>
          <td><?=finance_h($ct['role'] ?? '')?></td><td><?=finance_h($ct['whatsapp'] ?? '')?></td><td><?=finance_h($ct['phone'] ?? '')?></td><td><?=finance_h($ct['email'] ?? '')?></td>
          <td><div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm js-pc-edit" data-contact='<?=finance_h(json_encode(['id'=>(int)$ct['id'],'name'=>$ct['name'],'role'=>$ct['role'],'whatsapp'=>$ct['whatsapp'],'phone'=>$ct['phone'],'email'=>$ct['email'],'notes'=>$ct['notes'],'tags'=>(string)($ct['tags']??''),'is_primary'=>(int)$ct['is_primary'],'active'=>(int)$ct['active']], JSON_UNESCAPED_UNICODE))?>'>Editar</button>
            <form method="post" onsubmit="return confirm('Excluir este contato?')"><input type="hidden" name="action" value="pc_delete"><input type="hidden" name="person_id" value="<?= (int)$editId ?>"><input type="hidden" name="contact_id" value="<?= (int)$ct['id'] ?>"><input type="hidden" name="csrf" value="<?=finance_h(fp_grid_csrf_token())?>"><button class="btn btn-sm btn-danger" type="submit">Excluir</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </section>
    <script>
      (function(){
        const f=document.getElementById('pcForm');if(!f)return;
        const set=(id,v)=>{const e=document.getElementById(id);if(e)e.value=v==null?'':v;};
        const open=(c)=>{c=c||{};set('pcId',c.id||0);set('pcName',c.name);set('pcRole',c.role);set('pcWa',c.whatsapp);set('pcPhone',c.phone);set('pcEmail',c.email);set('pcNotes',c.notes);const tg=document.getElementById('pcTags');if(tg){tg.value=c.tags||'';if(window.NexaTags)NexaTags.sync(tg);}document.getElementById('pcPrimary').checked=!!c.is_primary;document.getElementById('pcActive').checked=c.id?!!c.active:true;f.style.display='';document.getElementById('pcName').focus();};
        document.getElementById('pcNewBtn').addEventListener('click',()=>open({}));
        document.getElementById('pcCancel').addEventListener('click',()=>{f.style.display='none';});
        document.querySelectorAll('.js-pc-edit').forEach(b=>b.addEventListener('click',()=>{try{open(JSON.parse(b.dataset.contact||'{}'))}catch(e){open({})}}));
      })();
    </script>
    <?php endif; ?>
    <?php if ($fpTabs): ?>
    <script>
      document.addEventListener('DOMContentLoaded',function(){
        // Abas do cadastro: Dados / Contatos / Anexos.
        const form=document.getElementById('financePersonForm'),extras=document.getElementById('paFormExtras');
        const panels={enderecos:document.getElementById('adPanel'),contatos:document.getElementById('pcPanel'),anexos:document.getElementById('paPanel')};
        const tabs=Array.from(document.querySelectorAll('[data-pa-tab]'));
        if(!form||!tabs.length)return;
        const activate=(which)=>{
          if(which!=='dados'&&!panels[which])which='dados';
          const dados=which==='dados';
          form.style.display=dados?'':'none';if(extras)extras.style.display=(dados||which==='enderecos')?'':'none';
          Object.entries(panels).forEach(([k,p])=>{if(!p)return;p.hidden=k!==which;p.style.display=k===which?'block':'none';});
          tabs.forEach(t=>t.setAttribute('aria-selected',String(t.dataset.paTab===which)));
        };
        tabs.forEach(t=>t.addEventListener('click',()=>activate(t.dataset.paTab)));
        activate(<?=json_encode(in_array((string)($_GET['tab']??''),['anexos','contatos','enderecos'],true)?(string)$_GET['tab']:'dados')?>);
      });
    </script>
    <?php endif; ?>
    <div id="paFormExtras">
    <?php if ((int)($edit['id'] ?? 0) > 0 && !$financePersonCrossContext): ?>
      <div class="finance-form-section" id="adPanel" role="tabpanel" aria-labelledby="paTabEnderecos" hidden style="margin-top:18px;">
        <h3>Endereços adicionais</h3>
        <p class="finance-muted">Cadastre outros locais de entrega/atendimento para esta pessoa. O endereço padrão aparece primeiro no checkout e no PDV.</p>

        <?php if ($personAddresses): ?>
          <div class="table-wrap" style="margin-bottom:14px;"><table class="finance-list-table">
            <thead><tr><th>Identificação</th><th>Endereço</th><th>Bairro</th><th>Padrão</th><th>Ações</th></tr></thead><tbody>
            <?php foreach ($personAddresses as $addr): ?>
              <?php $addrAddressClean = finance_person_clean_address((string)($addr['address'] ?? ''), (string)($addr['district'] ?? '')); ?>
              <tr>
                <td data-label="Identificação"><strong><?= finance_h($addr['label'] ?? 'Entrega') ?></strong></td>
                <td data-label="Endereço"><?= finance_h(trim($addrAddressClean . ', ' . (string)($addr['number'] ?? ''), ', ')) ?><br><span class="finance-muted"><?= finance_h($addr['complement'] ?? '') ?> <?= !empty($addr['reference']) ? ' - Ref.: ' . finance_h($addr['reference']) : '' ?></span></td>
                <td data-label="Bairro"><?= finance_h($addr['district'] ?? '') ?><br><span class="finance-muted"><?= finance_h(trim((string)($addr['city'] ?? '') . '/' . (string)($addr['state'] ?? ''), '/')) ?></span></td>
                <td data-label="Padrão"><?= !empty($addr['is_default']) ? '<span class="badge ok">Sim</span>' : '<span class="finance-muted">Não</span>' ?></td>
                <td data-label="Ações">
                  <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                    <button type="button" class="btn btn-sm js-edit-address"
                      data-id="<?= (int)$addr['id'] ?>"
                      data-label="<?= finance_h($addr['label'] ?? 'Entrega') ?>"
                      data-zip="<?= finance_h($addr['zip_code'] ?? '') ?>"
                      data-address="<?= finance_h($addrAddressClean) ?>"
                      data-number="<?= finance_h($addr['number'] ?? '') ?>"
                      data-complement="<?= finance_h($addr['complement'] ?? '') ?>"
                      data-reference="<?= finance_h($addr['reference'] ?? '') ?>"
                      data-district="<?= finance_h($addr['district'] ?? '') ?>"
                      data-city="<?= finance_h($addr['city'] ?? '') ?>"
                      data-city-ibge="<?= finance_h($addr['city_ibge'] ?? '') ?>"
                      data-state="<?= finance_h($addr['state'] ?? '') ?>"
                      data-default="<?= !empty($addr['is_default']) ? '1' : '0' ?>">Editar</button>
                    <form method="post" onsubmit="return confirm('Remover este endereço?')"><input type="hidden" name="action" value="delete_address"><input type="hidden" name="person_id" value="<?= (int)($edit['id'] ?? 0) ?>"><input type="hidden" name="address_id" value="<?= (int)$addr['id'] ?>"><button class="btn btn-danger">Remover</button></form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
          <p class="finance-muted">Nenhum endereço adicional cadastrado.</p>
        <?php endif; ?>

        <form method="post" class="finance-form" id="financeAddressForm">
          <input type="hidden" name="action" value="save_address">
          <input type="hidden" name="person_id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <input type="hidden" name="address_id" id="extraAddressId" value="0">
          <div class="finance-form-grid three">
            <div><label>Identificação</label><input name="label" id="extraLabel" placeholder="Ex.: Casa, Trabalho, Loja 2" value="Entrega"></div>
            <div><label>CEP</label><input name="zip_code" id="extraZip" inputmode="numeric" autocomplete="postal-code"></div>
            <div><label>Bairro</label><input name="district" id="extraDistrict"></div>
            <div class="span-2"><label>Endereço</label><input name="address" id="extraAddress"></div>
            <div><label>Número</label><input name="number" id="extraNumber"></div>
            <div><label>Complemento</label><input name="complement" id="extraComplement"></div>
            <div><label>Cidade</label><input name="city" id="extraCity"></div>
            <div><label>Código IBGE</label><input name="city_ibge" id="extraCityIbge" maxlength="7" inputmode="numeric"></div>
            <div><label>UF</label><input name="state" id="extraState" maxlength="2"></div>
            <div class="span-2"><label>Referência</label><input name="reference" id="extraReference" placeholder="Ponto de referência para entrega"></div>
            <label class="checkline"><input type="checkbox" name="is_default" id="extraDefault"> Tornar padrão</label>
          </div>
          <div class="finance-form-actions"><button class="btn btn-primary" id="extraAddressSubmit">Adicionar endereço</button><button type="button" class="btn" id="extraAddressCancel" style="display:none;">Cancelar edição</button></div>
        </form>
      </div>
    <?php else: ?>
      <div class="finance-form-section" style="margin-top:18px;"><h3>Endereços adicionais</h3><p class="finance-muted">Salve a pessoa primeiro para cadastrar outros endereços.</p></div>
    <?php endif; ?>
    </div><!-- paFormExtras -->
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('financePersonForm');
  if (!form) return;
  const $ = (id) => document.getElementById(id);
  const type = $('personType');
  const doc = $('personDocument');
  const docLabel = $('documentLabel');
  const btnCnpj = $('btnLookupCnpj');
  const docStatus = $('documentLookupStatus');
  const zip = $('personZip');
  const zipStatus = $('zipLookupStatus');
  const onlyDigits = (value) => String(value || '').replace(/\D+/g, '');
  const setStatus = (el, msg, cls) => { if (!el) return; el.textContent = msg || ''; el.className = 'finance-lookup-status' + (cls ? ' ' + cls : ''); };
  const setIfEmpty = (id, value) => {
    const el = $(id);
    if (!el || value === undefined || value === null || value === '') return;
    if (String(el.value || '').trim() === '') el.value = value;
  };
  const setValue = (id, value) => {
    const el = $(id);
    if (!el || value === undefined || value === null || value === '') return;
    el.value = value;
  };
  function formatCpf(v){
    v = onlyDigits(v).slice(0, 11);
    return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  }
  function formatCnpj(v){
    v = onlyDigits(v).slice(0, 14);
    return v.replace(/(\d{2})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2');
  }
  function formatCep(v){
    v = onlyDigits(v).slice(0, 8);
    return v.replace(/(\d{5})(\d{1,3})$/, '$1-$2');
  }
  function cleanAddressWithDistrict(address, district){
    let value = String(address || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
    const bairro = String(district || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (!value) return '';
    const streetTypes = '(?:Rua|R\\.?|Avenida|Av\\.?|Alameda|Travessa|Tv\\.?|Pra[cç]a|Rodovia|Estrada|Viela|Largo|Quadra|Condom[ií]nio|Conjunto|Residencial)';
    if (bairro) {
      const escBairro = bairro.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      value = value.replace(new RegExp('^Bairro:\\s*' + escBairro + '\\s*(?=' + streetTypes + '\\b)', 'iu'), '');
      value = value.replace(new RegExp('^' + escBairro + '\\s*(?=' + streetTypes + '\\b)', 'iu'), '');
    }
    value = value.replace(new RegExp('^Bairro:\\s*.*?(?=' + streetTypes + '\\b)', 'iu'), '');
    value = value.replace(/^Bairro:\s*/iu, '');
    value = value.replace(/\s+/g, ' ').trim();
    if (bairro && value.toLocaleLowerCase('pt-BR') === bairro.toLocaleLowerCase('pt-BR')) return '';
    return value;
  }
  function syncDocumentMode(){
    const isFisica = type && type.value === 'FISICA';
    docLabel.textContent = isFisica ? 'CPF' : 'CNPJ';
    const birthLbl = $('birthOpeningLabel'); if (birthLbl) birthLbl.textContent = isFisica ? 'Data de nascimento' : 'Data de abertura';
    const fantasyWrap = $('personFantasyWrap');
    if (fantasyWrap) fantasyWrap.style.display = isFisica ? 'none' : '';
    const identityWrap = $('personIdentityWrap'); if (identityWrap) identityWrap.style.display = isFisica ? '' : 'none';
    const identityIssuerWrap = $('personIdentityIssuerWrap'); if (identityIssuerWrap) identityIssuerWrap.style.display = isFisica ? '' : 'none';
    const identityStateWrap = $('personIdentityStateWrap'); if (identityStateWrap) identityStateWrap.style.display = isFisica ? '' : 'none';
    const municipalWrap = $('personMunicipalRegistrationWrap'); if (municipalWrap) municipalWrap.style.display = isFisica ? 'none' : '';
    const taxRegimeWrap = $('personTaxRegimeWrap'); if (taxRegimeWrap) taxRegimeWrap.style.display = isFisica ? 'none' : '';
    if (isFisica) setValue('personFantasy', '');
    doc.placeholder = isFisica ? 'Digite o CPF' : 'Digite o CNPJ';
    doc.maxLength = isFisica ? 14 : 18;
    if (btnCnpj) btnCnpj.style.display = isFisica ? 'none' : '';
    doc.value = isFisica ? formatCpf(doc.value) : formatCnpj(doc.value);
    setStatus(docStatus, '', '');
  }
  async function lookupCnpj(){
    if (!type || type.value !== 'JURIDICA') return;
    const cnpj = onlyDigits(doc.value);
    if (cnpj.length !== 14) {
      setStatus(docStatus, 'Informe um CNPJ com 14 dígitos para buscar os dados.', 'err');
      return;
    }
    setStatus(docStatus, 'Consultando CNPJ e dados fiscais...', 'load');
    try {
      const resp = await fetch('pessoa_cnpj_lookup.php?cnpj=' + encodeURIComponent(cnpj), {cache:'no-store', credentials:'same-origin'});
      const payload = await resp.json().catch(() => ({}));
      if (!resp.ok || !payload.ok) throw new Error(payload.error || 'CNPJ não encontrado.');
      const data = payload.data || {};
      setIfEmpty('personName', data.razao_social || '');
      setIfEmpty('personFantasy', data.nome_fantasia || '');
      setIfEmpty('personEmail', data.email || '');
      setIfEmpty('personPhone', data.telefone || '');
      setIfEmpty('personWhatsapp', data.telefone || '');
      setIfEmpty('personStateRegistration', data.state_registration || '');
      setIfEmpty('personMunicipalRegistration', data.municipal_registration || '');
      if (data.cep) setIfEmpty('personZip', formatCep(data.cep));
      setIfEmpty('personAddress', data.logradouro || '');
      setIfEmpty('personNumber', data.numero || '');
      setIfEmpty('personComplement', data.complemento || '');
      setIfEmpty('personDistrict', data.bairro || '');
      setIfEmpty('personCity', data.municipio || '');
      setIfEmpty('personCityIbge', data.city_ibge || '');
      setIfEmpty('personState', data.uf || '');
      setIfEmpty('personBirthOpeningDate', data.data_inicio_atividade || '');
      const ieMsg = data.state_registration
        ? ' IE ' + data.state_registration + (data.state_registration_active === false ? ' (inativa)' : '') + '.'
        : ' IE não localizada automaticamente; confira se o destinatário é contribuinte, isento ou não contribuinte.';
      const statusMsg = data.situacao_cadastral ? ' Situação: ' + data.situacao_cadastral + '.' : '';
      setStatus(docStatus, 'Dados do CNPJ carregados.' + ieMsg + statusMsg + ' Confira antes de salvar.', 'ok');
      if (zip && onlyDigits(zip.value).length === 8) lookupCep(false);
    } catch (e) {
      // Fallback: mantém a consulta básica pela BrasilAPI caso o proxy/consulta de IE esteja indisponível.
      try {
        const resp = await fetch('https://brasilapi.com.br/api/cnpj/v1/' + cnpj, {cache:'no-store'});
        if (!resp.ok) throw new Error('CNPJ não encontrado.');
        const data = await resp.json();
        setIfEmpty('personName', data.razao_social || data.nome || '');
        setIfEmpty('personFantasy', data.nome_fantasia || '');
        setIfEmpty('personEmail', data.email || '');
        const telefone = [data.ddd_telefone_1, data.ddd_telefone_2].filter(Boolean)[0] || '';
        setIfEmpty('personPhone', telefone); setIfEmpty('personWhatsapp', telefone);
        if (data.cep) setIfEmpty('personZip', formatCep(data.cep));
        setIfEmpty('personAddress', data.logradouro || ''); setIfEmpty('personNumber', data.numero || '');
        setIfEmpty('personComplement', data.complemento || ''); setIfEmpty('personDistrict', data.bairro || '');
        setIfEmpty('personCity', data.municipio || ''); setIfEmpty('personState', data.uf || '');
        setIfEmpty('personBirthOpeningDate', data.data_inicio_atividade || '');
        setStatus(docStatus, 'Dados básicos carregados pela BrasilAPI. A IE não pôde ser consultada automaticamente; confira antes de salvar.', 'ok');
      } catch (fallbackError) {
        setStatus(docStatus, fallbackError.message || e.message || 'Não foi possível consultar o CNPJ.', 'err');
      }
    }
  }
  async function lookupCep(showEmptyMessage){
    const cep = onlyDigits(zip.value);
    if (cep.length !== 8) {
      if (showEmptyMessage) setStatus(zipStatus, 'Informe um CEP com 8 dígitos.', 'err');
      return;
    }
    setStatus(zipStatus, 'Consultando CEP...', 'load');
    try {
      const resp = await fetch('https://viacep.com.br/ws/' + cep + '/json/', {cache: 'no-store'});
      if (!resp.ok) throw new Error('CEP não encontrado.');
      const data = await resp.json();
      if (data.erro) throw new Error('CEP não encontrado.');
      setValue('personAddress', data.logradouro || '');
      setValue('personDistrict', data.bairro || '');
      setValue('personCity', data.localidade || '');
      setValue('personCityIbge', data.ibge || '');
      setValue('personState', data.uf || '');
      setIfEmpty('personComplement', data.complemento || '');
      setStatus(zipStatus, 'Endereço carregado pelo CEP.', 'ok');
    } catch (e) {
      setStatus(zipStatus, e.message || 'Não foi possível consultar o CEP.', 'err');
    }
  }
  if (type) type.addEventListener('change', syncDocumentMode);
  if (doc) {
    doc.addEventListener('input', () => { doc.value = (type && type.value === 'FISICA') ? formatCpf(doc.value) : formatCnpj(doc.value); });
    doc.addEventListener('blur', () => { if (type && type.value === 'JURIDICA' && onlyDigits(doc.value).length === 14) lookupCnpj(); });
  }
  if (btnCnpj) btnCnpj.addEventListener('click', lookupCnpj);
  if (zip) {
    zip.addEventListener('input', () => { zip.value = formatCep(zip.value); });
    zip.addEventListener('blur', () => lookupCep(true));
  }

  const extraZip = $('extraZip');
  async function lookupExtraCep(){
    const cep = onlyDigits(extraZip?.value || '');
    if (cep.length !== 8) return;
    try {
      const resp = await fetch('https://viacep.com.br/ws/' + cep + '/json/', {cache: 'no-store'});
      if (!resp.ok) return;
      const data = await resp.json();
      if (data.erro) return;
      setValue('extraAddress', data.logradouro || '');
      setValue('extraDistrict', data.bairro || '');
      setValue('extraCity', data.localidade || '');
      setValue('extraCityIbge', data.ibge || '');
      setValue('extraState', data.uf || '');
      setIfEmpty('extraComplement', data.complemento || '');
    } catch(e) {}
  }
  if (extraZip) {
    extraZip.addEventListener('input', () => { extraZip.value = formatCep(extraZip.value); });
    extraZip.addEventListener('blur', lookupExtraCep);
  }

  const addressForm = $('financeAddressForm');
  const addressSubmit = $('extraAddressSubmit');
  const addressCancel = $('extraAddressCancel');
  function resetAddressForm(){
    if (!addressForm) return;
    setValue('extraAddressId', '0');
    setValue('extraLabel', 'Entrega');
    setValue('extraZip', '');
    setValue('extraDistrict', '');
    setValue('extraAddress', '');
    setValue('extraNumber', '');
    setValue('extraComplement', '');
    setValue('extraCity', '');
    setValue('extraState', '');
    setValue('extraReference', '');
    const def = $('extraDefault'); if (def) def.checked = false;
    if (addressSubmit) addressSubmit.textContent = 'Adicionar endereço';
    if (addressCancel) addressCancel.style.display = 'none';
  }
  document.querySelectorAll('.js-edit-address').forEach((btn) => {
    btn.addEventListener('click', () => {
      setValue('extraAddressId', btn.dataset.id || '0');
      setValue('extraLabel', btn.dataset.label || 'Entrega');
      setValue('extraZip', formatCep(btn.dataset.zip || ''));
      setValue('extraDistrict', btn.dataset.district || '');
      setValue('extraAddress', cleanAddressWithDistrict(btn.dataset.address || '', btn.dataset.district || ''));
      setValue('extraNumber', btn.dataset.number || '');
      setValue('extraComplement', btn.dataset.complement || '');
      setValue('extraCity', btn.dataset.city || '');
      setValue('extraCityIbge', btn.dataset.cityIbge || '');
      setValue('extraState', btn.dataset.state || '');
      setValue('extraReference', btn.dataset.reference || '');
      const def = $('extraDefault'); if (def) def.checked = String(btn.dataset.default || '0') === '1';
      if (addressSubmit) addressSubmit.textContent = 'Salvar alteração';
      if (addressCancel) addressCancel.style.display = '';
      addressForm?.scrollIntoView({behavior:'smooth', block:'center'});
      $('extraLabel')?.focus();
    });
  });
  if (addressCancel) addressCancel.addEventListener('click', resetAddressForm);
  if (addressForm) {
    addressForm.addEventListener('submit', () => {
      setValue('extraAddress', cleanAddressWithDistrict($('extraAddress')?.value || '', $('extraDistrict')?.value || ''));
    });
  }

  form.addEventListener('submit', () => {
    setValue('personAddress', cleanAddressWithDistrict($('personAddress')?.value || '', $('personDistrict')?.value || ''));
  });

  syncDocumentMode();
})();
</script>
<?php endif; ?>
<?php finance_admin_end(); ?>
