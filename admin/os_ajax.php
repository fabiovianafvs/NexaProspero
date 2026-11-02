<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/gestao_module.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = os_db();
$companyId = os_company_id();
if ($companyId <= 0 || !os_module_enabled($pdo, $companyId)) {
    http_response_code(403);
    echo json_encode(array('ok'=>false,'message'=>'Módulo Ordem de Serviço não habilitado.'));
    exit;
}
os_ensure_defaults($pdo, $companyId);

function os_json($data, $status)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function os_tokens($q)
{
    $parts = preg_split('/\s+/u', trim((string)$q));
    $out = array();
    foreach ($parts as $p) if ($p !== '') $out[] = $p;
    return array_slice($out, 0, 6);
}

function os_ajax_column_exists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->execute(array($table, $column));
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
try {
    if ($action === 'search_people') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $tokens = os_tokens($q);
        $where = array('company_id=?','active=1','is_customer=1');
        $params = array($companyId);
        foreach ($tokens as $t) {
            $where[] = "(name LIKE ? OR fantasy_name LIKE ? OR document LIKE ? OR whatsapp LIKE ? OR phone LIKE ?)";
            for ($i=0;$i<5;$i++) $params[] = nexa_search_like($t);
        }
        $sql = "SELECT id,name,fantasy_name,document,phone,whatsapp FROM finance_people WHERE ".implode(' AND ',$where)." ORDER BY name ASC,id ASC LIMIT 80";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        os_json(array('ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)),200);
    }

    if ($action === 'person_details') {
        $personId = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
        if ($personId <= 0) os_json(array('ok'=>false,'message'=>'Cliente inválido.'),422);

        $select = array(
            'id','name','fantasy_name','document','state_registration','municipal_registration',
            'identity_document','identity_issuer','identity_state','person_type','birth_opening_date',
            'email','phone','whatsapp','zip_code','address','number','complement','district','city',
            'city_ibge','state','is_customer','is_supplier','is_seller','is_employee','is_professional',
            'is_carrier','notes','active','created_at','updated_at'
        );
        if (os_ajax_column_exists($pdo, 'finance_people', 'numero_cadastro')) {
            array_splice($select, 1, 0, array('numero_cadastro'));
        }
        $stmt = $pdo->prepare('SELECT '.implode(',', $select).' FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
        $stmt->execute(array($companyId,$personId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) os_json(array('ok'=>false,'message'=>'Cadastro do cliente não encontrado.'),404);
        os_json(array('ok'=>true,'row'=>$row),200);
    }

    if ($action === 'search_items') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $type = isset($_GET['type']) ? strtoupper(trim($_GET['type'])) : '';
        $tokens = os_tokens($q);

        $hasDescricao = os_ajax_column_exists($pdo, 'produtos', 'descricao');
        $hasCategoria = os_ajax_column_exists($pdo, 'produtos', 'categoria');
        $hasNumeroCadastro = os_ajax_column_exists($pdo, 'produtos', 'numero_cadastro');
        $hasCodigoFirebird = os_ajax_column_exists($pdo, 'produtos', 'codigo_firebird');
        $hasReferencia = os_ajax_column_exists($pdo, 'produtos', 'referencia');
        $hasUnidade = os_ajax_column_exists($pdo, 'produtos', 'unidade_estoque');
        $hasEstoqueAtual = os_ajax_column_exists($pdo, 'produtos', 'estoque_atual');
        $hasEstoqueReservado = os_ajax_column_exists($pdo, 'produtos', 'estoque_reservado');
        $hasIsService = os_ajax_column_exists($pdo, 'produtos', 'is_service');

        $where = array('company_id=?', 'COALESCE(ativo,1)=1');
        $params = array($companyId);

        if ($hasIsService) {
            if ($type === 'PRODUTO') $where[] = 'COALESCE(is_service,0)=0';
            if ($type === 'SERVICO') $where[] = 'COALESCE(is_service,0)=1';
        } elseif ($type === 'SERVICO') {
            os_json(array('ok'=>true,'rows'=>array()),200);
        }

        foreach ($tokens as $t) {
            $parts = array('nome LIKE ?', 'CAST(id AS CHAR) LIKE ?');
            $like = nexa_search_like($t);
            $params[] = $like;
            $params[] = $like;
            if ($hasDescricao) { $parts[] = 'descricao LIKE ?'; $params[] = $like; }
            if ($hasCategoria) { $parts[] = 'categoria LIKE ?'; $params[] = $like; }
            if ($hasNumeroCadastro) { $parts[] = 'CAST(numero_cadastro AS CHAR) LIKE ?'; $params[] = $like; }
            if ($hasCodigoFirebird) { $parts[] = 'codigo_firebird LIKE ?'; $params[] = $like; }
            if ($hasReferencia) { $parts[] = 'referencia LIKE ?'; $params[] = $like; }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        $select = array(
            'id',
            'nome',
            $hasDescricao ? 'descricao' : "'' AS descricao",
            $hasCategoria ? 'categoria' : "'' AS categoria",
            'preco',
            $hasUnidade ? "COALESCE(NULLIF(unidade_estoque,''),'UN') AS unidade_estoque" : "'UN' AS unidade_estoque",
            $hasEstoqueAtual ? 'COALESCE(estoque_atual,0) AS estoque_atual' : '0 AS estoque_atual',
            $hasEstoqueReservado ? 'COALESCE(estoque_reservado,0) AS estoque_reservado' : '0 AS estoque_reservado',
            $hasIsService ? 'COALESCE(is_service,0) AS is_service' : '0 AS is_service',
            $hasNumeroCadastro ? 'numero_cadastro' : 'NULL AS numero_cadastro',
            $hasCodigoFirebird ? 'codigo_firebird' : 'NULL AS codigo_firebird',
            $hasReferencia ? 'referencia' : 'NULL AS referencia'
        );

        $order = $hasIsService ? 'COALESCE(is_service,0) ASC, nome ASC, id ASC' : 'nome ASC, id ASC';
        $sql = "SELECT ".implode(',', $select)." FROM produtos WHERE ".implode(' AND ',$where)." ORDER BY ".$order." LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        os_json(array('ok'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)),200);
    }

    if ($action === 'create_person') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') os_json(array('ok'=>false,'message'=>'Método inválido.'),405);

        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        if ($name === '') os_json(array('ok'=>false,'message'=>'Informe o nome/razão social.'),422);

        $personType = strtoupper(trim(isset($_POST['person_type']) ? $_POST['person_type'] : 'FISICA'));
        if (!in_array($personType, array('FISICA','JURIDICA'), true)) $personType = 'FISICA';

        $document = preg_replace('/[^0-9A-Za-z]/', '', (string)(isset($_POST['document']) ? $_POST['document'] : ''));
        $stateRegistration = strtoupper(trim((string)(isset($_POST['state_registration']) ? $_POST['state_registration'] : '')));
        if ($stateRegistration !== '' && $stateRegistration !== 'ISENTO') {
            $stateRegistration = preg_replace('/[^0-9A-Z]+/', '', $stateRegistration);
        }
        $birthOpening = trim((string)(isset($_POST['birth_opening_date']) ? $_POST['birth_opening_date'] : ''));
        if ($birthOpening !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthOpening)) $birthOpening = '';

        $data = array(
            'name' => $name,
            'fantasy_name' => $personType === 'JURIDICA' ? trim((string)(isset($_POST['fantasy_name']) ? $_POST['fantasy_name'] : '')) : '',
            'document' => $document,
            'state_registration' => $stateRegistration,
            'municipal_registration' => trim((string)(isset($_POST['municipal_registration']) ? $_POST['municipal_registration'] : '')),
            'identity_document' => trim((string)(isset($_POST['identity_document']) ? $_POST['identity_document'] : '')),
            'identity_issuer' => trim((string)(isset($_POST['identity_issuer']) ? $_POST['identity_issuer'] : '')),
            'identity_state' => strtoupper(substr(trim((string)(isset($_POST['identity_state']) ? $_POST['identity_state'] : '')), 0, 2)),
            'person_type' => $personType,
            'birth_opening_date' => $birthOpening !== '' ? $birthOpening : null,
            'email' => trim((string)(isset($_POST['email']) ? $_POST['email'] : '')),
            'phone' => trim((string)(isset($_POST['phone']) ? $_POST['phone'] : '')),
            'whatsapp' => trim((string)(isset($_POST['whatsapp']) ? $_POST['whatsapp'] : '')),
            'zip_code' => trim((string)(isset($_POST['zip_code']) ? $_POST['zip_code'] : '')),
            'address' => trim((string)(isset($_POST['address']) ? $_POST['address'] : '')),
            'number' => trim((string)(isset($_POST['number']) ? $_POST['number'] : '')),
            'complement' => trim((string)(isset($_POST['complement']) ? $_POST['complement'] : '')),
            'district' => trim((string)(isset($_POST['district']) ? $_POST['district'] : '')),
            'city' => trim((string)(isset($_POST['city']) ? $_POST['city'] : '')),
            'city_ibge' => preg_replace('/\D+/', '', (string)(isset($_POST['city_ibge']) ? $_POST['city_ibge'] : '')),
            'state' => strtoupper(substr(trim((string)(isset($_POST['state']) ? $_POST['state'] : '')), 0, 2)),
        );
        if (function_exists('gestao_clean_address_with_district')) {
            $data['address'] = gestao_clean_address_with_district((string)$data['address'], (string)$data['district']);
        }

        $cols = array('company_id');
        $marks = array('?');
        $params = array($companyId);
        try {
            if (function_exists('company_sequence_has_column') && company_sequence_has_column($pdo, 'pessoas')) {
                $cols[] = 'numero_cadastro';
                $marks[] = '?';
                $params[] = company_sequence_next($pdo, $companyId, 'pessoas');
            }
        } catch (Throwable $e) {
            // A sequência visual não impede o cadastro caso esteja indisponível.
        }
        foreach ($data as $col => $value) {
            $cols[] = $col;
            $marks[] = '?';
            $params[] = $value === '' ? null : $value;
        }
        $cols[] = 'is_customer'; $marks[] = '1';
        $cols[] = 'active'; $marks[] = '1';
        $cols[] = 'created_at'; $marks[] = 'NOW()';

        $stmt = $pdo->prepare('INSERT INTO finance_people (' . implode(',', $cols) . ') VALUES (' . implode(',', $marks) . ')');
        $stmt->execute($params);
        $personId = (int)$pdo->lastInsertId();

        // Mantém o endereço principal disponível também nos fluxos de entrega/PDV.
        if ($personId > 0 && ($data['address'] !== '' || $data['district'] !== '')) {
            try {
                gestao_ensure_schema($pdo);
                gestao_create_customer_address($pdo, $companyId, $personId, array(
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
                ), true);
            } catch (Throwable $e) {
                // O cadastro da pessoa permanece válido mesmo se a sincronização de endereço falhar.
            }
        }

        os_json(array('ok'=>true,'row'=>array(
            'id'=>$personId,
            'name'=>$name,
            'document'=>$document,
            'phone'=>$data['phone'],
            'whatsapp'=>$data['whatsapp']
        )),200);
    }

    if ($action === 'quick_add_option') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') os_json(array('ok'=>false,'message'=>'Método inválido.'),405);
        $fieldId = (int)(isset($_POST['field_id']) ? $_POST['field_id'] : 0);
        $label = trim(isset($_POST['label']) ? $_POST['label'] : '');
        if ($fieldId <= 0 || $label === '') os_json(array('ok'=>false,'message'=>'Dados inválidos.'),422);
        $stmt = $pdo->prepare("SELECT id,allow_quick_add,input_type FROM os_custom_fields WHERE company_id=? AND id=? AND active=1 LIMIT 1");
        $stmt->execute(array($companyId,$fieldId));
        $field = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$field || $field['input_type'] !== 'select' || (int)$field['allow_quick_add'] !== 1) os_json(array('ok'=>false,'message'=>'Este campo não permite cadastro rápido.'),403);
        $value = os_slug($label);
        $base = $value; $n=2;
        while (true) {
            $c = $pdo->prepare("SELECT 1 FROM os_custom_field_options WHERE company_id=? AND field_id=? AND option_value=? LIMIT 1");
            $c->execute(array($companyId,$fieldId,$value));
            if (!$c->fetchColumn()) break;
            $value = $base.'_'.$n; $n++;
        }
        $stmt = $pdo->prepare("INSERT INTO os_custom_field_options (company_id,field_id,label,option_value,sort_order,active) VALUES (?,?,?,?,0,1)");
        $stmt->execute(array($companyId,$fieldId,$label,$value));
        os_json(array('ok'=>true,'row'=>array('id'=>(int)$pdo->lastInsertId(),'label'=>$label,'value'=>$value)),200);
    }

    if ($action === 'vehicle_by_plate') {
        if (!os_vehicle_feature_ready($pdo)) {
            os_json(array('ok'=>false,'message'=>'A identificação de campos do veículo ainda não foi instalada. Execute o SQL da atualização da O.S.'),409);
        }
        $plateRaw = isset($_GET['plate']) ? $_GET['plate'] : (isset($_GET['q']) ? $_GET['q'] : '');
        $plate = os_vehicle_normalize_plate($plateRaw);
        if (strlen($plate) < 6) os_json(array('ok'=>false,'message'=>'Informe uma placa válida.'),422);
        $vehicle = os_vehicle_profile_find($pdo, $companyId, $plate);
        if (!$vehicle) os_json(array('ok'=>true,'found'=>false,'plate'=>$plate,'message'=>'Nenhum veículo cadastrado anteriormente com esta placa.'),200);
        $lastOrderNumber = null;
        if (!empty($vehicle['last_order_id'])) {
            $st=$pdo->prepare("SELECT os_number FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
            $st->execute(array($companyId,(int)$vehicle['last_order_id']));
            $n=$st->fetchColumn();
            if($n!==false) $lastOrderNumber=(int)$n;
        }
        os_json(array(
            'ok'=>true,
            'found'=>true,
            'plate'=>$plate,
            'vehicle_id'=>(int)$vehicle['id'],
            'last_order_id'=>(int)($vehicle['last_order_id'] ?: 0),
            'last_order_number'=>$lastOrderNumber,
            'values'=>isset($vehicle['semantic_data']) && is_array($vehicle['semantic_data']) ? $vehicle['semantic_data'] : array()
        ),200);
    }

    os_json(array('ok'=>false,'message'=>'Ação não encontrada.'),404);
} catch (Exception $e) {
    os_json(array('ok'=>false,'message'=>$e->getMessage()),500);
}
