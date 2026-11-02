<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_extra_options.php';
require_once __DIR__ . '/../config/os_step_visibility.php';
require_once __DIR__ . '/../config/os_customer_communication.php';
require_once __DIR__ . '/_os_ui.php';
require_once __DIR__ . '/_os_vehicle_damage_ui.php';

$pdo = os_db();
$companyId = os_company_id();
os_require_enabled($pdo, $companyId);
os_ensure_defaults($pdo, $companyId);
$userId = os_user_id();
$osExtra = os_extra_options($pdo,(int)$companyId);
$stepVisibility=os_step_visibility($pdo,(int)$companyId);
$showVehicle=(bool)$stepVisibility['show_vehicle_step'];
$showDamage=(bool)$stepVisibility['show_damage_step'];
function os_mobile_step_visible(int $target,int $direction=1):int {
  global $showVehicle,$showDamage;
  $target=max(1,min(8,$target));
  while (($target===3&&!$showVehicle)||($target===4&&!$showDamage)) $target=max(1,min(8,$target+($direction<0?-1:1)));
  return $target;
}


if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function os_mobile_key($companyId)
{
    return 'os_mobile_draft_' . (int)$companyId;
}

function os_mobile_get_draft($companyId)
{
    $key = os_mobile_key($companyId);
    return (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : null;
}

function os_mobile_set_draft($companyId, array $draft)
{
    $_SESSION[os_mobile_key($companyId)] = $draft;
}

function os_mobile_clear_draft($companyId)
{
    unset($_SESSION[os_mobile_key($companyId)]);
}

function os_mobile_blank_draft(PDO $pdo, $companyId)
{
    return array(
        'order_id' => 0,
        'status_id' => os_initial_status_id($pdo, $companyId),
        'person_id' => 0,
        'person_name' => '',
        'person_document' => '',
        'contact_name' => '',
        'contact_phone' => '',
        'expected_at' => '',
        'reported_problem' => '',
        'diagnosis' => '',
        'technical_notes' => '',
        'customer_notes' => '',
        'discount_amount' => '0,00',
        'addition_amount' => '0,00',
        'fields' => array(),
        'damage_points' => array(),
        'items' => array(),
        'payments' => array(),
        'started_at' => date('Y-m-d H:i:s'),
        'max_step' => 1,
    );
}

function os_mobile_load_order(PDO $pdo, $companyId, $orderId)
{
    $st = $pdo->prepare("SELECT * FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
    $st->execute(array((int)$companyId, (int)$orderId));
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) throw new RuntimeException('O.S. não encontrada.');
    if (in_array((string)$o['billing_status'], array('FATURADA','ENVIADA_PDV'), true)) {
        throw new RuntimeException('Esta O.S. já foi enviada/faturada e não pode ser alterada pelo O.S. Mobile.');
    }

    $draft = array(
        'order_id' => (int)$o['id'],
        'status_id' => (int)$o['status_id'],
        'person_id' => (int)$o['person_id'],
        'person_name' => (string)$o['person_name_snapshot'],
        'person_document' => (string)$o['person_document_snapshot'],
        'contact_name' => (string)($o['contact_name'] ?: $o['person_name_snapshot']),
        'contact_phone' => (string)($o['contact_phone'] ?: $o['person_phone_snapshot']),
        'expected_at' => $o['expected_at'] ? date('Y-m-d\TH:i', strtotime($o['expected_at'])) : '',
        'reported_problem' => (string)$o['reported_problem'],
        'diagnosis' => (string)$o['diagnosis'],
        'technical_notes' => (string)$o['technical_notes'],
        'customer_notes' => (string)$o['customer_notes'],
        'discount_amount' => number_format((float)$o['discount_amount'], 2, ',', '.'),
        'addition_amount' => number_format((float)$o['addition_amount'], 2, ',', '.'),
        'fields' => array(),
        'damage_points' => array(),
        'items' => array(),
        'payments' => array(),
        'started_at' => date('Y-m-d H:i:s'),
        'max_step' => 8,
    );

    $st = $pdo->prepare("SELECT field_id,value_text FROM os_order_field_values WHERE company_id=? AND order_id=?");
    $st->execute(array((int)$companyId, (int)$orderId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $draft['fields'][(int)$r['field_id']] = (string)$r['value_text'];
    }

    $draft['damage_points'] = os_vehicle_damage_load($pdo, $companyId, $orderId);

    $st = $pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? ORDER BY id");
    $st->execute(array((int)$companyId, (int)$orderId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $draft['items'][] = array(
            'produto_id' => (int)$r['produto_id'],
            'item_type' => (string)$r['item_type'],
            'description' => (string)$r['description_snapshot'],
            'unidade' => (string)$r['unidade_snapshot'],
            'quantity' => (float)$r['quantity'],
            'unit_price' => (float)$r['unit_price'],
            'reserve_stock' => ((int)$r['reserve_stock'] === 1),
            'responsible_person_id' => os_item_responsible_id($r),
            'responsible_name' => os_item_responsible_name($r),
            'notes' => (string)$r['notes'],
        );
    }

    $st = $pdo->prepare("SELECT * FROM os_order_payments WHERE company_id=? AND order_id=? ORDER BY id");
    $st->execute(array((int)$companyId, (int)$orderId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $draft['payments'][] = array(
            'payment_method_id' => (int)$r['payment_method_id'],
            'payment_label' => (string)$r['payment_label'],
            'amount' => (float)$r['amount'],
            'due_date' => (string)$r['due_date'],
            'notes' => (string)$r['notes'],
        );
    }
    return $draft;
}

function os_mobile_totals(array $draft)
{
    $products = 0.0; $services = 0.0;
    foreach ((array)$draft['items'] as $item) {
        $total = round((float)$item['quantity'] * (float)$item['unit_price'], 2);
        if ($item['item_type'] === 'SERVICO') $services += $total; else $products += $total;
    }
    $general = max(0, $products + $services - os_decimal($draft['discount_amount']) + os_decimal($draft['addition_amount']));
    $payments = 0.0;
    foreach ((array)$draft['payments'] as $p) $payments += (float)$p['amount'];
    return array('products'=>$products,'services'=>$services,'general'=>$general,'payments'=>$payments);
}

function os_mobile_save_final(PDO $pdo, $companyId, $userId, array $draft)
{
    $personId = (int)$draft['person_id'];
    list($pid,$pname,$pdoc,$pphone) = os_build_order_snapshot($pdo, $companyId, $personId);
    $contactName = trim((string)($draft['contact_name'] ?? ''));
    $contactPhone = trim((string)($draft['contact_phone'] ?? ''));
    if ($personId <= 0 && $contactName === '') throw new RuntimeException('Informe o contato do consumidor final ou selecione um cliente.');
    $extra = os_extra_options($pdo,(int)$companyId);
    $visibility=os_step_visibility($pdo,(int)$companyId);
    if (!empty($extra['require_contact_name']) && $contactName==='') throw new RuntimeException('Informe o contato da O.S.');
    if (!empty($extra['require_contact_phone']) && $contactPhone==='') throw new RuntimeException('Informe o telefone/WhatsApp da O.S.');

    $statusId = (int)$draft['status_id'];
    if ($statusId <= 0) $statusId = os_initial_status_id($pdo, $companyId);

    $fs = $pdo->prepare("SELECT * FROM os_custom_fields WHERE company_id=? AND active=1 ORDER BY sort_order,id");
    $fs->execute(array((int)$companyId));
    $fields = $fs->fetchAll(PDO::FETCH_ASSOC);
    foreach (($visibility['show_vehicle_step']?$fields:[]) as $f) {
        $v = isset($draft['fields'][(int)$f['id']]) ? (string)$draft['fields'][(int)$f['id']] : '';
        if ((int)$f['required'] === 1 && trim($v) === '') throw new RuntimeException('Preencha o campo obrigatório: ' . $f['label']);
    }

    $set = $pdo->prepare("SELECT * FROM os_settings WHERE company_id=? LIMIT 1");
    $set->execute(array((int)$companyId));
    $settings = $set->fetch(PDO::FETCH_ASSOC) ?: array();
    $allowOut = !empty($settings['allow_out_of_stock_reservation']);
    $orderId = (int)$draft['order_id'];

    $pdo->beginTransaction();
    try {
        $oldStatus = 0;
        if ($orderId > 0) {
            $st = $pdo->prepare("SELECT status_id,billing_status FROM os_orders WHERE company_id=? AND id=? LIMIT 1 FOR UPDATE");
            $st->execute(array((int)$companyId,$orderId));
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new RuntimeException('O.S. não encontrada para atualização.');
            if (in_array((string)$cur['billing_status'], array('FATURADA','ENVIADA_PDV'), true)) throw new RuntimeException('O.S. já enviada/faturada.');
            $oldStatus = (int)$cur['status_id'];

            $up = $pdo->prepare("UPDATE os_orders SET person_id=?,person_name_snapshot=?,person_document_snapshot=?,person_phone_snapshot=?,contact_name=?,contact_phone=?,status_id=?,expected_at=?,reported_problem=?,diagnosis=?,technical_notes=?,customer_notes=?,discount_amount=?,addition_amount=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?");
            $up->execute(array($pid,$pname,$pdoc,$pphone,$contactName!==''?$contactName:null,$contactPhone!==''?$contactPhone:null,$statusId,$draft['expected_at']!==''?str_replace('T',' ',$draft['expected_at']):null,$draft['reported_problem']!==''?$draft['reported_problem']:null,$draft['diagnosis']!==''?$draft['diagnosis']:null,$draft['technical_notes']!==''?$draft['technical_notes']:null,$draft['customer_notes']!==''?$draft['customer_notes']:null,os_decimal($draft['discount_amount']),os_decimal($draft['addition_amount']),$userId,$companyId,$orderId));

            os_release_order_reservations($pdo, $companyId, $orderId);
            $pdo->prepare("DELETE FROM os_order_items WHERE company_id=? AND order_id=?")->execute(array((int)$companyId,$orderId));
            $pdo->prepare("DELETE FROM os_order_payments WHERE company_id=? AND order_id=?")->execute(array((int)$companyId,$orderId));
        } else {
            $number = os_next_number($pdo, $companyId);
            $ins = $pdo->prepare("INSERT INTO os_orders (company_id,os_number,person_id,person_name_snapshot,person_document_snapshot,person_phone_snapshot,contact_name,contact_phone,status_id,expected_at,reported_problem,diagnosis,technical_notes,customer_notes,discount_amount,addition_amount,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute(array($companyId,$number,$pid,$pname,$pdoc,$pphone,$contactName!==''?$contactName:null,$contactPhone!==''?$contactPhone:null,$statusId,$draft['expected_at']!==''?str_replace('T',' ',$draft['expected_at']):null,$draft['reported_problem']!==''?$draft['reported_problem']:null,$draft['diagnosis']!==''?$draft['diagnosis']:null,$draft['technical_notes']!==''?$draft['technical_notes']:null,$draft['customer_notes']!==''?$draft['customer_notes']:null,os_decimal($draft['discount_amount']),os_decimal($draft['addition_amount']),$userId,$userId));
            $orderId = (int)$pdo->lastInsertId();
        }

        $fv = $pdo->prepare("INSERT INTO os_order_field_values (company_id,order_id,field_id,value_text,created_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=NOW()");
        $semanticValues = array();
        foreach (($visibility['show_vehicle_step']?$fields:[]) as $f) {
            $v = isset($draft['fields'][(int)$f['id']]) ? (string)$draft['fields'][(int)$f['id']] : '';
            $fv->execute(array((int)$companyId,$orderId,(int)$f['id'],$v));
            if (isset($f['semantic_role']) && trim((string)$f['semantic_role']) !== '') $semanticValues[(string)$f['semantic_role']] = trim($v);
        }
        if ($semanticValues) os_vehicle_profile_save($pdo, $companyId, $orderId, $pid, $semanticValues);

        if (!empty($visibility['show_damage_step']) && os_vehicle_damage_enabled((array)$settings)) {
            os_vehicle_damage_replace($pdo, $companyId, $orderId, isset($draft['damage_points']) ? $draft['damage_points'] : array());
        }

        foreach ((array)$draft['items'] as $item) {
            $productId = (int)$item['produto_id'];
            $qty = os_decimal($item['quantity']);
            $price = os_decimal($item['unit_price']);
            if ($productId <= 0 || $qty <= 0) throw new RuntimeException('Existe produto/serviço inválido na conferência.');

            $st = $pdo->prepare("SELECT id,nome,unidade_estoque,preco,is_service FROM produtos WHERE company_id=? AND id=? AND ativo=1 LIMIT 1 FOR UPDATE");
            $st->execute(array((int)$companyId,$productId));
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new RuntimeException('Produto/serviço não encontrado: #' . $productId);
            if ($price <= 0) $price = (float)$p['preco'];
            $type = ((int)$p['is_service'] === 1) ? 'SERVICO' : 'PRODUTO';
            $reserve = (!empty($item['reserve_stock']) && $type === 'PRODUTO') ? 1 : 0;
            $reserved = 0; $reservationStatus = 'NAO_RESERVADO';
            if ($reserve) {
                os_reserve_product($pdo, $companyId, $productId, $qty, $allowOut);
                $reserved = $qty;
                $reservationStatus = 'RESERVADO';
            }
            list($respId,$respName) = os_item_responsible_snapshot($pdo, $companyId, isset($item['responsible_person_id']) ? (int)$item['responsible_person_id'] : 0);
            $total = round($qty * $price, 2);
            $ins = $pdo->prepare("INSERT INTO os_order_items (company_id,order_id,produto_id,item_type,description_snapshot,unidade_snapshot,quantity,unit_price,total,reserve_stock,reserved_quantity,reservation_status,seller_person_id,seller_name_snapshot,technician_person_id,technician_name_snapshot,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,?)");
            $ins->execute(array((int)$companyId,$orderId,$productId,$type,$p['nome'],$p['unidade_estoque'],$qty,$price,$total,$reserve,$reserved,$reservationStatus,$respId,$respName,trim((string)$item['notes'])!==''?(string)$item['notes']:null));
        }

        foreach ((array)$draft['payments'] as $pay) {
            $methodId = (int)$pay['payment_method_id'];
            $amount = os_decimal($pay['amount']);
            if ($methodId <= 0 || $amount <= 0) continue;
            $st = $pdo->prepare("SELECT name,type,code FROM finance_payment_methods WHERE company_id=? AND id=? AND is_active=1 LIMIT 1");
            $st->execute(array((int)$companyId,$methodId));
            $methodRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$methodRow) throw new RuntimeException('Uma das formas de pagamento não está mais ativa.');
            if (strtoupper(trim((string)($methodRow['type']??'')))==='VALE'||strtoupper(trim((string)($methodRow['code']??'')))==='VALE') throw new RuntimeException('O pagamento com Vale está disponível nas vendas/PDV e não está habilitado no fluxo da O.S.');
            $label = (string)$methodRow['name'];
            $ins = $pdo->prepare("INSERT INTO os_order_payments (company_id,order_id,payment_method_id,payment_label,amount,due_date,notes) VALUES (?,?,?,?,?,?,?)");
            $ins->execute(array((int)$companyId,$orderId,$methodId,$label,$amount,$pay['due_date']!==''?$pay['due_date']:null,trim((string)$pay['notes'])!==''?(string)$pay['notes']:null));
        }

        if ($statusId > 0 && $statusId !== $oldStatus) {
            $h = $pdo->prepare("INSERT INTO os_status_history (company_id,order_id,from_status_id,to_status_id,user_id,created_at) VALUES (?,?,?,?,?,NOW())");
            $h->execute(array((int)$companyId,$orderId,$oldStatus>0?$oldStatus:null,$statusId,$userId));
        }

        os_recalculate_totals($pdo, $companyId, $orderId);

        $st = $pdo->prepare("SELECT code,is_final FROM os_statuses WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array((int)$companyId,$statusId));
        $newStatus = $st->fetch(PDO::FETCH_ASSOC);
        if ($newStatus && (string)$newStatus['code'] === 'CANCELADA') {
            os_release_order_reservations($pdo, $companyId, $orderId);
            $pdo->prepare("UPDATE os_orders SET billing_status='CANCELADA',closed_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")->execute(array((int)$companyId,$orderId));
        } elseif ($newStatus && (int)$newStatus['is_final'] === 1) {
            $pdo->prepare("UPDATE os_orders SET closed_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")->execute(array((int)$companyId,$orderId));
        } elseif ($newStatus) {
            $pdo->prepare("UPDATE os_orders SET closed_at=NULL,updated_at=NOW() WHERE company_id=? AND id=?")->execute(array((int)$companyId,$orderId));
        }

        $pdo->commit();
        os_vehicle_damage_flush_photo_deletes();
        return $orderId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        os_vehicle_damage_clear_photo_delete_queue();
        throw $e;
    }
}

function os_mobile_redirect($step, $extra)
{
    $step=os_mobile_step_visible((int)$step);
    $url = 'os_mobile.php?step=' . (int)$step;
    if ($extra !== '') $url .= '&' . ltrim($extra, '&');
    header('Location: ' . $url);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM os_settings WHERE company_id=? LIMIT 1");
$stmt->execute(array((int)$companyId));
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
$stmt = $pdo->prepare("SELECT * FROM os_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id");
$stmt->execute(array((int)$companyId));
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT * FROM os_custom_fields WHERE company_id=? AND active=1 ORDER BY sort_order,id");
$stmt->execute(array((int)$companyId));
$customFields = $stmt->fetchAll(PDO::FETCH_ASSOC);
$optionsByField = array();
if ($customFields) {
    $stmt = $pdo->prepare("SELECT * FROM os_custom_field_options WHERE company_id=? AND active=1 ORDER BY field_id,sort_order,id");
    $stmt->execute(array((int)$companyId));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $op) {
        if (!isset($optionsByField[(int)$op['field_id']])) $optionsByField[(int)$op['field_id']] = array();
        $optionsByField[(int)$op['field_id']][] = $op;
    }
}
$responsibles = os_item_responsible_people($pdo, $companyId);
$stmt = $pdo->prepare("SELECT * FROM finance_payment_methods WHERE company_id=? AND is_active=1 AND UPPER(COALESCE(type,''))<>'VALE' AND UPPER(COALESCE(code,''))<>'VALE' ORDER BY sort_order,name,id");
$stmt->execute(array((int)$companyId));
$methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$successOrderId = 0;
$showStatusPrompt = false;
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
$hasStepParam = isset($_REQUEST['step']);
$step = $hasStepParam ? max(1, min(8, (int)$_REQUEST['step'])) : 1;
$showHome = ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action === '' && !$hasStepParam);
$step=os_mobile_step_visible($step);

try {
    if ($action === 'new') {
        os_mobile_set_draft($companyId, os_mobile_blank_draft($pdo, $companyId));
        os_mobile_redirect(1, 'new=1');
    }
    if ($action === 'edit') {
        $orderId = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
        os_mobile_set_draft($companyId, os_mobile_load_order($pdo, $companyId, $orderId));
        os_mobile_redirect(1, 'editing=1');
    }
    if ($action === 'cancel_draft') {
        os_mobile_clear_draft($companyId);
        header('Location: os_mobile_monitor.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $draft = os_mobile_get_draft($companyId);
        if (!$draft && !in_array($action, array(), true)) throw new RuntimeException('A sessão da O.S. expirou. Inicie novamente pelo filtro.');

        // Alteração rápida e isolada do status: não recria itens, pagamentos ou reservas.
        if ($action === 'save_status_only') {
            $orderId = (int)($draft['order_id'] ?? 0);
            $newStatusId = (int)($_POST['status_id'] ?? 0);
            if ($orderId <= 0) throw new RuntimeException('Salvamento rápido disponível apenas para O.S. existente.');
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT status_id,billing_status FROM os_orders WHERE company_id=? AND id=? LIMIT 1 FOR UPDATE");
                $st->execute([$companyId,$orderId]);
                $order = $st->fetch(PDO::FETCH_ASSOC);
                if (!$order || in_array((string)$order['billing_status'], ['FATURADA','ENVIADA_PDV'], true)) throw new RuntimeException('O.S. não encontrada ou já faturada.');
                $st = $pdo->prepare("SELECT id,code,is_final FROM os_statuses WHERE company_id=? AND id=? AND active=1 LIMIT 1");
                $st->execute([$companyId,$newStatusId]);
                $status = $st->fetch(PDO::FETCH_ASSOC);
                if (!$status) throw new RuntimeException('Selecione um status válido.');
                // Cancelamento pode envolver liberação de reservas: usar apenas o fluxo completo.
                if (strtoupper((string)$status['code']) === 'CANCELADA') throw new RuntimeException('Para cancelar a O.S., utilize o procedimento normal de cancelamento.');
                $oldStatusId=(int)$order['status_id'];
                if ($oldStatusId !== $newStatusId) {
                    $pdo->prepare("UPDATE os_orders SET status_id=?,closed_at=" . ((int)$status['is_final']===1 ? 'NOW()' : 'NULL') . ",updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")
                        ->execute([$newStatusId,$userId,$companyId,$orderId]);
                    $pdo->prepare("INSERT INTO os_status_history (company_id,order_id,from_status_id,to_status_id,user_id,created_at) VALUES (?,?,?,?,?,NOW())")
                        ->execute([$companyId,$orderId,$oldStatusId>0?$oldStatusId:null,$newStatusId,$userId]);
                }
                $pdo->commit();
                os_mobile_clear_draft($companyId);
                $notify=($oldStatusId>0 && $oldStatusId!==$newStatusId && os_status_notify_enabled($pdo,(int)$companyId,$newStatusId));
                header('Location: os_mobile_monitor.php'.($notify?'?notify_status='.(int)$orderId:''));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'save_step1') {
            $draft['person_id'] = (int)(isset($_POST['person_id']) ? $_POST['person_id'] : 0);
            list($pid,$pname,$pdoc,$pphone) = os_build_order_snapshot($pdo,$companyId,$draft['person_id']);
            $draft['person_name'] = (string)$pname;
            $draft['person_document'] = (string)$pdoc;
            $draft['contact_name'] = trim(isset($_POST['contact_name']) ? (string)$_POST['contact_name'] : '');
            $draft['contact_phone'] = trim(isset($_POST['contact_phone']) ? (string)$_POST['contact_phone'] : '');
            if ($draft['person_id'] <= 0 && $draft['contact_name'] === '') throw new RuntimeException('Informe o nome do contato ou selecione um cliente.');
            if ($draft['person_id'] <= 0 && $draft['contact_phone'] === '') throw new RuntimeException('Informe o telefone/WhatsApp do consumidor final.');
            $target = max(1, min(8, (int)(isset($_POST['target_step']) ? $_POST['target_step'] : 2)));
            $draft['max_step'] = max((int)($draft['max_step'] ?? 1), min(2, $target));
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect($target, '');
        }

        if ($action === 'save_step2') {
            $draft['status_id'] = (int)($_POST['status_id'] ?? 0);
            $draft['expected_at'] = trim((string)($_POST['expected_at'] ?? ''));
            $draft['reported_problem'] = trim((string)($_POST['reported_problem'] ?? ''));
            $draft['diagnosis'] = trim((string)($_POST['diagnosis'] ?? ''));
            $draft['technical_notes'] = trim((string)($_POST['technical_notes'] ?? ''));
            $draft['customer_notes'] = trim((string)($_POST['customer_notes'] ?? ''));
            $draft['discount_amount'] = trim((string)($_POST['discount_amount'] ?? '0,00'));
            $draft['addition_amount'] = trim((string)($_POST['addition_amount'] ?? '0,00'));
            $target = os_mobile_step_visible(max(1,min(8,(int)($_POST['target_step']??3))),((int)($_POST['target_step']??3))<2?-1:1);
            $draft['max_step'] = max((int)($draft['max_step'] ?? 2), $target);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect($target, '');
        }

        if ($action === 'save_vehicle') {
            foreach (($showVehicle?$customFields:[]) as $f) {
                $key = 'field_' . (int)$f['id'];
                $v = ($f['input_type'] === 'checkbox') ? (isset($_POST[$key]) ? '1' : '0') : trim((string)($_POST[$key] ?? ''));
                if ((int)$f['required'] === 1 && $v === '') throw new RuntimeException('Preencha o campo obrigatório: ' . $f['label']);
                $draft['fields'][(int)$f['id']] = $v;
            }
            $target = os_mobile_step_visible(max(1,min(8,(int)($_POST['target_step']??4))),((int)($_POST['target_step']??4))<3?-1:1);
            $draft['max_step'] = max((int)($draft['max_step'] ?? 3), $target);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect($target, '');
        }

        if ($action === 'save_step3') {
            if ($showDamage && os_vehicle_damage_enabled((array)$settings)) {
                $rawDamage = (string)($_POST['damage_points_json'] ?? '[]');
                $decodedDamage = json_decode($rawDamage, true);
                if (!is_array($decodedDamage)) throw new RuntimeException('As marcações de avaria estão em formato inválido.');
                $draft['damage_points'] = $decodedDamage;
            }
            $target = max(1, min(8, (int)($_POST['target_step'] ?? 5)));
            $draft['max_step'] = max((int)($draft['max_step'] ?? 4), 6);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect($target, '');
        }

        if ($action === 'add_item' || $action === 'update_item') {
            $productId = (int)(isset($_POST['produto_id']) ? $_POST['produto_id'] : 0);
            $qty = os_decimal(isset($_POST['quantity']) ? $_POST['quantity'] : 1);
            $price = os_decimal(isset($_POST['unit_price']) ? $_POST['unit_price'] : 0);
            $reserve = isset($_POST['reserve_stock']) ? 1 : 0;
            $resp = (int)(isset($_POST['responsible_person_id']) ? $_POST['responsible_person_id'] : 0);
            $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');
            if ($productId <= 0 || $qty <= 0) throw new RuntimeException('Selecione o produto/serviço e informe a quantidade.');
            $st = $pdo->prepare("SELECT id,nome,unidade_estoque,preco,is_service,estoque_atual,estoque_reservado FROM produtos WHERE company_id=? AND id=? AND ativo=1 LIMIT 1");
            $st->execute(array((int)$companyId,$productId));
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new RuntimeException('Produto/serviço não encontrado.');
            if ($price <= 0) $price = (float)$p['preco'];
            $type = ((int)$p['is_service'] === 1) ? 'SERVICO' : 'PRODUTO';
            if ($type === 'SERVICO') $reserve = 0;
            if ($reserve && empty($settings['allow_out_of_stock_reservation'])) {
                $available = (float)$p['estoque_atual'] - (float)$p['estoque_reservado'];
                if ($qty > $available + 0.00001) throw new RuntimeException('Estoque disponível insuficiente para reservar. Disponível: ' . number_format($available,4,',','.'));
            }
            list($respId,$respName) = os_item_responsible_snapshot($pdo,$companyId,$resp);
            $item = array('produto_id'=>$productId,'item_type'=>$type,'description'=>$p['nome'],'unidade'=>$p['unidade_estoque'],'quantity'=>$qty,'unit_price'=>$price,'reserve_stock'=>(bool)$reserve,'responsible_person_id'=>$respId,'responsible_name'=>$respName,'notes'=>$notes);
            if ($action === 'update_item') {
                $idx = (int)(isset($_POST['item_index']) ? $_POST['item_index'] : -1);
                if (!isset($draft['items'][$idx])) throw new RuntimeException('Item não encontrado no rascunho.');
                $draft['items'][$idx] = $item;
            } else {
                $draft['items'][] = $item;
            }
            $draft['max_step'] = max((int)($draft['max_step'] ?? 5), 6);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect(6, '');
        }

        if ($action === 'remove_item') {
            $idx = (int)(isset($_POST['item_index']) ? $_POST['item_index'] : -1);
            if (isset($draft['items'][$idx])) {
                array_splice($draft['items'], $idx, 1);
                os_mobile_set_draft($companyId, $draft);
            }
            os_mobile_redirect(6, '');
        }

        if ($action === 'go_step5') {
            $draft['max_step'] = max((int)($draft['max_step'] ?? 6), 7);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect(7, '');
        }

        if ($action === 'go_summary') {
            $draft['max_step'] = max((int)($draft['max_step'] ?? 7), 8);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect(8, '');
        }

        if ($action === 'add_payment') {
            $methodId = (int)(isset($_POST['payment_method_id']) ? $_POST['payment_method_id'] : 0);
            $amount = os_decimal(isset($_POST['amount']) ? $_POST['amount'] : 0);
            $due = trim(isset($_POST['due_date']) ? $_POST['due_date'] : '');
            $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');
            if ($methodId <= 0 || $amount <= 0) throw new RuntimeException('Informe forma de pagamento e valor.');
            $st = $pdo->prepare("SELECT name,type,code FROM finance_payment_methods WHERE company_id=? AND id=? AND is_active=1 LIMIT 1");
            $st->execute(array((int)$companyId,$methodId));
            $methodRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$methodRow) throw new RuntimeException('Forma de pagamento inválida.');
            if (strtoupper(trim((string)($methodRow['type']??'')))==='VALE'||strtoupper(trim((string)($methodRow['code']??'')))==='VALE') throw new RuntimeException('O pagamento com Vale está disponível nas vendas/PDV e não está habilitado no fluxo da O.S.');
            $label = (string)$methodRow['name'];
            $draft['payments'][] = array('payment_method_id'=>$methodId,'payment_label'=>$label,'amount'=>$amount,'due_date'=>$due,'notes'=>$notes);
            os_mobile_set_draft($companyId, $draft);
            os_mobile_redirect(7, '');
        }

        if ($action === 'remove_payment') {
            $idx = (int)(isset($_POST['payment_index']) ? $_POST['payment_index'] : -1);
            if (isset($draft['payments'][$idx])) {
                array_splice($draft['payments'], $idx, 1);
                os_mobile_set_draft($companyId, $draft);
            }
            os_mobile_redirect(7, '');
        }


        if ($action === 'final_save') {
            $prevStatusId=0;
            if((int)($draft['order_id']??0)>0){
                $prevSt=$pdo->prepare('SELECT status_id FROM os_orders WHERE company_id=? AND id=? LIMIT 1');
                $prevSt->execute([(int)$companyId,(int)$draft['order_id']]);
                $prevStatusId=(int)$prevSt->fetchColumn();
            }
            $successOrderId = os_mobile_save_final($pdo,$companyId,$userId,$draft);
            $nowSt=$pdo->prepare('SELECT status_id FROM os_orders WHERE company_id=? AND id=? LIMIT 1');
            $nowSt->execute([(int)$companyId,(int)$successOrderId]);
            $newStatusId=(int)$nowSt->fetchColumn();
            $showStatusPrompt=($prevStatusId>0 && $newStatusId>0 && $newStatusId!==$prevStatusId && os_status_notify_enabled($pdo,(int)$companyId,$newStatusId));
            os_mobile_clear_draft($companyId);
            $step = 8;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$draft = os_mobile_get_draft($companyId);
if (!$showHome && !$draft && !$successOrderId) {
    $draft = os_mobile_blank_draft($pdo, $companyId);
    os_mobile_set_draft($companyId, $draft);
    $step = 1;
}
if ($draft) {
    $maxStep = max(1, min(8, (int)($draft['max_step'] ?? 1)));
    if ($step > $maxStep) $step = $maxStep;
    $step=os_mobile_step_visible($step);
} else {
    $maxStep = 1;
}

os_page_start('O.S. Mobile', 'mobile');
?>
<style>
.os-mobile-shell{max-width:780px;margin:0 auto}.mobile-home{min-height:calc(100vh - 132px);display:flex;align-items:center;justify-content:center;padding:18px 0}.mobile-home-card{width:100%;background:#fff;border:1px solid var(--os-border);border-radius:18px;padding:24px;box-shadow:0 12px 34px rgba(15,23,42,.08);text-align:center}.mobile-home-mark{width:62px;height:62px;margin:0 auto 14px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,var(--os-primary),var(--os-secondary));color:var(--os-on-primary);font-weight:950;font-size:23px;box-shadow:0 10px 24px color-mix(in srgb,var(--os-secondary) 22%,transparent)}.mobile-home-card h1{margin:0;color:var(--os-primary);font-size:24px}.mobile-home-card p{margin:8px auto 20px;color:var(--os-muted);font-size:13px;line-height:1.5;max-width:540px}.mobile-home-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mobile-home-action{min-height:132px;border:1px solid var(--os-border);border-radius:15px;padding:18px 14px;text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:#fff;color:var(--os-primary);font-weight:900;box-shadow:0 4px 12px rgba(15,23,42,.04)}.mobile-home-action:hover{border-color:color-mix(in srgb,var(--os-secondary) 45%,var(--os-border));background:color-mix(in srgb,var(--os-secondary) 4%,#fff)}.mobile-home-action.primary{background:linear-gradient(135deg,var(--os-primary),var(--os-secondary));color:var(--os-on-primary);border-color:transparent}.mobile-home-action .icon{font-size:31px;line-height:1}.mobile-home-action small{font-size:11px;font-weight:700;opacity:.76}.mobile-progress{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:6px;margin:2px 0 16px}.mobile-progress .p{appearance:none;border:0;background:transparent;padding:2px;min-width:0;text-align:center;cursor:pointer}.mobile-progress .p:disabled{cursor:not-allowed;opacity:.48}.mobile-progress .dot{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;margin:0 auto 5px;background:#e8eef5;color:#64748b;font-weight:900;font-size:12px;border:2px solid #dbe4ee}.mobile-progress .p.on .dot,.mobile-progress .p.done .dot{background:linear-gradient(135deg,var(--os-primary),var(--os-secondary));color:var(--os-on-primary);border-color:transparent}.mobile-progress .p.done .dot{box-shadow:0 0 0 3px color-mix(in srgb,var(--os-secondary) 10%,transparent)}.mobile-progress small{display:block;font-size:9px;line-height:1.1;color:#64748b;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mobile-progress .p.on small,.mobile-progress .p.done small{color:var(--os-primary)}
.mobile-step-title{display:flex;gap:10px;align-items:center;margin:0 0 12px}.mobile-step-title b{width:34px;height:34px;border-radius:50%;background:var(--os-primary);color:var(--os-on-primary);display:grid;place-items:center;flex:0 0 34px}.mobile-step-title h1{margin:0;font-size:21px;color:var(--os-primary)}.mobile-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}.mobile-actions .btn{flex:1 1 150px;min-height:44px}.mobile-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.mobile-form-grid .full{grid-column:1/-1}.mobile-card-list{display:grid;gap:10px}.mobile-item-card,.mobile-pay-card,.mobile-review{border:1px solid var(--os-border);border-radius:12px;padding:12px;background:#fff}.mobile-item-card .head{display:flex;align-items:flex-start;gap:8px}.mobile-item-card .head strong{font-size:15px;color:var(--os-primary)}.mobile-item-card .head .spacer{flex:1}.mobile-meta{font-size:12px;color:var(--os-muted);margin-top:5px;line-height:1.45}.mobile-values{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:9px}.mobile-values div{background:#f8fafc;border-radius:8px;padding:7px}.mobile-values span{display:block;font-size:9px;color:#64748b;font-weight:800;text-transform:uppercase}.mobile-values b{display:block;margin-top:2px;font-size:13px;color:var(--os-primary)}.mobile-search-wrap{position:relative}.mobile-search-results{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:80;background:#fff;border:1px solid var(--os-border);border-radius:10px;box-shadow:0 15px 35px rgba(15,23,42,.18);max-height:300px;overflow:auto}.mobile-search-results.open{display:block}.mobile-search-row{padding:11px;border-bottom:1px solid var(--os-border-soft);cursor:pointer}.mobile-search-row:last-child{border-bottom:0}.mobile-search-row:active{background:#f1f5f9}.mobile-search-row b{display:block;font-size:13px}.mobile-search-row small{color:#64748b}.mobile-selected{margin-top:7px;background:color-mix(in srgb,var(--os-secondary) 8%,#fff);border:1px solid color-mix(in srgb,var(--os-secondary) 24%,#dbe4ee);border-radius:9px;padding:9px;font-size:12px}.mobile-selected b{color:var(--os-primary)}.mobile-register{display:none;margin-top:10px;padding:11px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc}.mobile-register.open{display:block}.mobile-register-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}.mobile-register-grid .full{grid-column:1/-1}.mobile-totals{position:sticky;bottom:8px;z-index:10;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border:1px solid var(--os-border);border-radius:12px;padding:8px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:12px;box-shadow:0 8px 24px rgba(15,23,42,.1)}.mobile-totals div{text-align:center}.mobile-totals span{font-size:9px;color:#64748b;font-weight:800;display:block}.mobile-totals b{display:block;color:var(--os-primary);font-size:15px;margin-top:2px}.mobile-review h3{margin:0 0 8px;color:var(--os-primary);font-size:15px}.mobile-review-line{display:flex;gap:8px;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eef2f6;font-size:12px}.mobile-review-line:last-child{border-bottom:0}.mobile-review-line span:first-child{color:#64748b}.mobile-review-line b{text-align:right}.mobile-empty{text-align:center;color:#64748b;padding:22px 10px}.mobile-success{text-align:center;padding:22px 10px}.mobile-success .check{width:68px;height:68px;border-radius:50%;display:grid;place-items:center;margin:0 auto 12px;background:#eaf7ef;color:#08783e;font-size:34px;font-weight:950}.mobile-success h2{color:var(--os-primary);margin:0 0 8px}.mobile-pill{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;background:#eef2f7;color:#334155}.mobile-inline-btn{min-height:34px;padding:6px 9px}.mobile-details{margin-top:10px}.mobile-details summary{cursor:pointer;color:var(--os-primary);font-weight:850;font-size:13px;padding:7px 0}.mobile-step-help{margin:-4px 0 12px;color:#64748b;font-size:12px;line-height:1.45}.mobile-save-box{margin-top:12px;border:1px solid color-mix(in srgb,var(--os-primary) 22%,#dbe4ee);background:color-mix(in srgb,var(--os-primary) 4%,#fff);border-radius:12px;padding:12px}.mobile-save-box strong{color:var(--os-primary)}
@media(max-width:640px){.os-wrap{padding:10px}.mobile-home{min-height:calc(100vh - 105px);padding:8px 0}.mobile-home-card{padding:18px 14px}.mobile-home-actions{grid-template-columns:1fr}.mobile-home-action{min-height:112px}.os-top{padding:8px 10px;gap:8px}.os-brand{font-size:15px}.os-nav{display:none}.os-back{font-size:12px}.mobile-progress{gap:2px}.mobile-progress .dot{width:30px;height:30px}.mobile-progress small{font-size:8px}.mobile-form-grid{grid-template-columns:1fr}.mobile-form-grid .full{grid-column:auto}.mobile-register-grid{grid-template-columns:1fr}.mobile-register-grid .full{grid-column:auto}.mobile-values{grid-template-columns:1fr 1fr 1fr}.card-body{padding:12px}.mobile-actions .btn{flex:1 1 42%}}
/* Navegação uniforme do passo a passo - dois cantos, mesmo azul e letras brancas. */
.os-mobile-shell .mobile-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:nowrap}
.os-mobile-shell .mobile-actions.mobile-nav-actions>.btn{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;flex:0 0 116px!important;width:116px;min-width:116px;max-width:116px;height:46px;min-height:46px;margin:0;padding:0 8px;border:1px solid #0f4b95!important;border-radius:10px;background:#165ca8!important;color:#fff!important;box-shadow:0 3px 8px rgba(21,78,147,.17);font-size:13px;font-weight:900;line-height:1.15;text-decoration:none;text-align:center;white-space:normal}
.os-mobile-shell .mobile-actions.mobile-nav-actions>.btn:hover,.os-mobile-shell .mobile-actions.mobile-nav-actions>.btn:focus-visible{background:#0c488c!important;color:#fff!important}
/* Aplicar aos pares de navegação; outros botões de manutenção mantêm comportamento original. */
.os-mobile-shell .mobile-actions.mobile-nav-actions>.btn{flex:0 0 116px!important}
@media(max-width:480px){.os-mobile-shell .mobile-actions.mobile-nav-actions>.btn{flex-basis:112px!important;width:112px;min-width:112px;max-width:112px;height:45px;min-height:45px;font-size:12px}}
/* Ações de inclusão de itens: nenhum botão ultrapassa a largura da tela. */
.os-mobile-shell .mobile-actions:not(.mobile-nav-actions){flex-wrap:wrap;max-width:100%;min-width:0}
.os-mobile-shell .mobile-actions:not(.mobile-nav-actions)>.btn,
.os-mobile-shell .mobile-actions:not(.mobile-nav-actions)>button,
.os-mobile-shell .mobile-actions:not(.mobile-nav-actions)>a{box-sizing:border-box;min-width:0;max-width:100%;white-space:normal;overflow-wrap:anywhere;line-height:1.25}
@media(max-width:480px){
 .os-mobile-shell .mobile-actions:not(.mobile-nav-actions)>.btn,
 .os-mobile-shell .mobile-actions:not(.mobile-nav-actions)>button,
 .os-mobile-shell .mobile-actions:not(.mobile-nav-actions)>a{flex:1 1 100%;width:100%;font-size:13px;padding:10px 9px}
 .os-mobile-shell .mobile-save-box .btn{max-width:100%;white-space:normal}
}
/* Consulta veicular legível e adequada a toque em telas pequenas. */
.mobile-plate-row{display:flex;flex-direction:column;gap:10px;align-items:stretch;width:100%;min-width:0}
.mobile-plate-row input{width:100%;box-sizing:border-box;height:60px;min-height:60px;border:2px solid #245a9b!important;border-radius:10px;font-size:17px;font-weight:800;letter-spacing:1px;min-width:0;flex:1}
.mobile-plate-row .btn{width:100%;min-height:48px;flex:0 0 auto;padding:0 12px}
</style>
<div class="os-mobile-shell">
<?php
if ($showHome) {
    ?>
    <div class="mobile-home">
      <div class="mobile-home-card">
        <div class="mobile-home-mark">O.S.</div>
        <h1>O.S. Mobile</h1>
        <p>Escolha o que deseja fazer. O monitor foi preparado em uma lista responsiva para consulta rápida no celular.</p>
        <div class="mobile-home-actions">
          <a class="mobile-home-action" href="os_mobile_monitor.php"><span class="icon">☰</span><span>Monitor de O.S.</span><small>Filtrar, localizar e abrir ordens de serviço</small></a>
          <a class="mobile-home-action primary" href="os_mobile.php?action=new"><span class="icon">＋</span><span>Nova O.S.</span><small>Iniciar diretamente pelo passo 1</small></a>
        </div>
      </div>
    </div>
    <?php
} else {
$progressLabels = array(1=>'Cliente',2=>'Status',3=>'Veículo',4=>'Avarias',5=>'Adicionar',6=>'Itens',7=>'Pagamento',8=>'Resumo');
if(!$showVehicle)unset($progressLabels[3]);
if(!$showDamage)unset($progressLabels[4]);
echo '<div class="mobile-progress" style="grid-template-columns:repeat('.count($progressLabels).',minmax(0,1fr))" aria-label="Etapas da O.S. Mobile">';
foreach ($progressLabels as $n=>$label) {
    $cls = $step === $n ? 'on' : ($maxStep > $n || ($maxStep === $n && $step !== $n) ? 'done' : '');
    $enabled = ($n <= $maxStep);
    $dot = ($n < $maxStep) ? '✓' : (string)$n;
    echo '<button type="button" class="p '.$cls.'" data-step="'.$n.'" '.($enabled?'':'disabled').' onclick="mobileWizardGo('.$n.')"><span class="dot">'.$dot.'</span><small>'.os_e($label).'</small></button>';
}
echo '</div>';
if ($error !== '') echo '<div class="alert alert-error">'.os_e($error).'</div>';

if ($step === 1 && $draft) {
    ?>
    <div class="mobile-step-title"><b>1</b><h1>Cliente / contato</h1></div>
    <p class="mobile-step-help">Selecione um cliente cadastrado ou informe apenas o nome e o telefone/WhatsApp para consumidor final.</p>
    <div class="card"><div class="card-body">
      <form method="post" id="wizardStepForm" data-wizard-save="1">
        <input type="hidden" name="action" value="save_step1"><input type="hidden" name="step" value="1"><input type="hidden" name="target_step" value="2"><input type="hidden" name="person_id" id="mobile_person_id" value="<?php echo (int)$draft['person_id']; ?>">
        <div class="mobile-form-grid">
          <div class="full"><label>Cliente / Pessoa <span class="muted">(opcional)</span></label><div class="mobile-search-wrap"><input id="mobile_person_search" autocomplete="off" value="<?php echo os_e($draft['person_name']); ?>" placeholder="Digite nome, CPF/CNPJ ou telefone"><div id="mobile_person_results" class="mobile-search-results"></div></div><div id="mobile_person_selected" class="mobile-selected" <?php echo (int)$draft['person_id']>0?'':'style="display:none"'; ?>><b id="mobile_person_selected_name"><?php echo os_e($draft['person_name']); ?></b><span id="mobile_person_selected_doc"><?php echo $draft['person_document']?' • '.os_e($draft['person_document']):''; ?></span></div><button class="btn btn-secondary mobile-inline-btn" style="margin-top:8px" type="button" id="toggleRegister">+ Cadastrar novo cliente</button>
            <div class="mobile-register" id="mobileRegister"><div class="mobile-register-grid">
              <div class="full"><b style="color:var(--os-primary)">Cadastro completo do cliente</b><div class="mobile-meta">Dados fiscais, contato e endereço usados também na emissão dos documentos fiscais.</div></div>
              <div><label>Tipo de pessoa *</label><select id="reg_person_type"><option value="FISICA">Pessoa Física</option><option value="JURIDICA">Pessoa Jurídica</option></select></div>
              <div><label id="reg_doc_label">CPF</label><input id="reg_doc" inputmode="numeric" placeholder="Digite o CPF"></div>
              <div class="full" id="reg_cnpj_button_wrap" style="display:none"><button type="button" id="reg_cnpj_lookup" class="btn btn-secondary" style="width:100%">Buscar dados do CNPJ</button><div id="reg_doc_status" class="mobile-meta"></div></div>
              <div class="full"><label>Nome / Razão social *</label><input id="reg_name"></div>
              <div class="full" id="reg_fantasy_wrap" style="display:none"><label>Nome fantasia</label><input id="reg_fantasy"></div>
              <div><label id="reg_birth_label">Data de nascimento</label><input id="reg_birth" type="date"></div>
              <div><label>Inscrição Estadual (IE)</label><input id="reg_ie" placeholder="IE ou ISENTO"></div>
              <div id="reg_im_wrap" style="display:none"><label>Inscrição Municipal (IM)</label><input id="reg_im"></div>
              <div id="reg_rg_wrap"><label>RG / Identidade</label><input id="reg_rg"></div>
              <div id="reg_issuer_wrap"><label>Órgão expedidor</label><input id="reg_issuer" placeholder="Ex.: SSP"></div>
              <div id="reg_identity_state_wrap"><label>UF da identidade</label><input id="reg_identity_state" maxlength="2"></div>
              <div><label>E-mail</label><input id="reg_email" type="email"></div>
              <div><label>WhatsApp</label><input id="reg_whats"></div>
              <div><label>Telefone</label><input id="reg_phone"></div>
              <div><label>CEP</label><input id="reg_zip" inputmode="numeric" placeholder="00000-000"><div id="reg_zip_status" class="mobile-meta"></div></div>
              <div class="full"><label>Endereço</label><input id="reg_address"></div>
              <div><label>Número</label><input id="reg_number"></div>
              <div><label>Bairro</label><input id="reg_district"></div>
              <div><label>Cidade</label><input id="reg_city"></div>
              <div><label>Código IBGE</label><input id="reg_city_ibge" inputmode="numeric" maxlength="7"></div>
              <div><label>UF</label><input id="reg_state" maxlength="2"></div>
              <div class="full"><label>Complemento</label><input id="reg_complement"></div>
              <div class="full"><button type="button" id="saveRegister" class="btn btn-primary" style="width:100%">Cadastrar e selecionar</button></div>
            </div></div>
          </div>
          <div><label>Contato *</label><input id="mobile_contact_name" name="contact_name" value="<?php echo os_e($draft['contact_name']); ?>" placeholder="Nome da pessoa"></div>
          <div><label>Telefone / WhatsApp <span class="muted">(obrigatório para consumidor final)</span></label><input id="mobile_contact_phone" name="contact_phone" inputmode="tel" value="<?php echo os_e($draft['contact_phone']); ?>" placeholder="(00) 00000-0000"></div>
        </div>
        <div class="mobile-actions mobile-nav-actions"><a class="btn btn-secondary" href="os_mobile_monitor.php">← Filtros</a><button class="btn btn-primary" type="submit" onclick="this.form.elements['target_step'].value=2">Avançar →</button></div>
      </form>
    </div></div>
    <?php
}

if ($step === 2 && $draft) {
    ?>
    <div class="mobile-step-title"><b>2</b><h1>Status, previsão e problema relatado</h1></div>
    <p class="mobile-step-help">Informe o status, a previsão e o problema relatado. <?php echo $showVehicle?'No próximo passo serão informados os dados do veículo.':($showDamage?'No próximo passo será exibido o mapa de avarias.':'Depois, adicione produtos e serviços.'); ?></p>
    <div class="card"><div class="card-body">
      <form method="post" id="wizardStepForm" data-wizard-save="1">
        <input type="hidden" name="action" value="save_step2"><input type="hidden" name="step" value="2"><input type="hidden" name="target_step" value="3">
        <div class="mobile-form-grid">
          <div><label>Status</label><select name="status_id"><?php foreach($statuses as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$draft['status_id']===(int)$s['id']?'selected':''; ?>><?php echo os_e($s['name']); ?></option><?php endforeach; ?></select></div>
          <div><label>Previsão de conclusão</label><input type="datetime-local" name="expected_at" value="<?php echo os_e($draft['expected_at']); ?>"></div>
          <div class="full"><label>Problema relatado / solicitação</label><textarea name="reported_problem" placeholder="Descreva o pedido do cliente"><?php echo os_e($draft['reported_problem']); ?></textarea></div>

        </div>
        <details class="mobile-details"><summary>Mais dados da O.S.</summary><div class="mobile-form-grid">
          <div class="full"><label>Diagnóstico</label><textarea name="diagnosis"><?php echo os_e($draft['diagnosis']); ?></textarea></div>
          <div class="full"><label>Observações técnicas internas</label><textarea name="technical_notes"><?php echo os_e($draft['technical_notes']); ?></textarea></div>
          <div class="full"><label>Observações para cliente / impressão</label><textarea name="customer_notes"><?php echo os_e($draft['customer_notes']); ?></textarea></div>
          <div><label>Desconto geral</label><input name="discount_amount" inputmode="decimal" value="<?php echo os_e($draft['discount_amount']); ?>"></div><div><label>Acréscimo geral</label><input name="addition_amount" inputmode="decimal" value="<?php echo os_e($draft['addition_amount']); ?>"></div>
        </div></details>
        <?php if ((int)($draft['order_id'] ?? 0) > 0): ?>
        <div class="mobile-save-box"><strong>Alterar somente o status?</strong><p class="mobile-step-help">Salve o novo status e volte diretamente à lista, sem alterar itens, pagamentos ou dados do veículo.</p>
          <button class="btn btn-success" type="submit" formaction="os_mobile.php" onclick="this.form.elements['action'].value='save_status_only'">✓ Salvar status e voltar à lista</button>
        </div>
        <?php endif; ?>
        <div class="mobile-actions mobile-nav-actions"><button class="btn btn-secondary" type="submit" onclick="this.form.elements['target_step'].value=1">← Voltar</button><button class="btn btn-primary" type="submit" onclick="this.form.elements['target_step'].value=3">Avançar →</button></div>
      </form>
    </div></div>
    <?php
}

if ($showVehicle && $step === 3 && $draft) {
    ?>
    <div class="mobile-step-title"><b>3</b><h1>Dados do veículo</h1></div>
    <p class="mobile-step-help">Informe a placa, toque em Consultar e confira os dados encontrados antes de avançar.</p>
    <div class="card"><div class="card-body">
      <form method="post" id="wizardStepForm" data-wizard-save="1">
        <input type="hidden" name="action" value="save_vehicle"><input type="hidden" name="step" value="3"><input type="hidden" name="target_step" value="4">
        <div class="mobile-form-grid">
          <?php foreach($customFields as $f): $fid=(int)$f['id']; $val=isset($draft['fields'][$fid])?$draft['fields'][$fid]:''; ?>
            <div class="<?php echo ($f['input_type']==='textarea'||(string)($f['semantic_role']??'')==='vehicle_plate')?'full':''; ?>"><label><?php echo os_e($f['label']); ?><?php echo (int)$f['required']===1?' *':''; ?></label>
            <?php if($f['input_type']==='textarea'): ?><textarea name="field_<?php echo $fid; ?>" <?php echo !empty($f['semantic_role'])?'data-semantic="'.os_e($f['semantic_role']).'"':''; ?> placeholder="<?php echo os_e($f['placeholder']); ?>"><?php echo os_e($val); ?></textarea>
            <?php elseif($f['input_type']==='select'): ?><div class="inline"><select id="mfield_<?php echo $fid; ?>" name="field_<?php echo $fid; ?>" <?php echo !empty($f['semantic_role'])?'data-semantic="'.os_e($f['semantic_role']).'"':''; ?>><option value="">Selecione</option><?php foreach(isset($optionsByField[$fid])?$optionsByField[$fid]:array() as $op): ?><option value="<?php echo os_e($op['option_value']); ?>" <?php echo (string)$val===(string)$op['option_value']?'selected':''; ?>><?php echo os_e($op['label']); ?></option><?php endforeach; ?></select><?php if((int)$f['allow_quick_add']===1): ?><button type="button" class="btn btn-secondary mobile-inline-btn" onclick="mobileQuickOption(<?php echo $fid; ?>)">+</button><?php endif; ?></div>
            <?php elseif($f['input_type']==='checkbox'): ?><label class="checkline"><input style="width:auto" type="checkbox" name="field_<?php echo $fid; ?>" value="1" <?php echo (string)$val==='1'?'checked':''; ?>> Sim</label>
            <?php else: ?><div class="<?php echo (string)($f['semantic_role']??'')==='vehicle_plate'?'mobile-plate-row':''; ?>"><input type="<?php echo in_array($f['input_type'],array('number','date','password'),true)?os_e($f['input_type']):'text'; ?>" name="field_<?php echo $fid; ?>" <?php echo !empty($f['semantic_role'])?'data-semantic="'.os_e($f['semantic_role']).'"':''; ?> value="<?php echo $f['input_type']==='password'?'':os_e($val); ?>" placeholder="<?php echo os_e($f['placeholder']); ?>"><?php if ((string)($f['semantic_role']??'')==='vehicle_plate'): ?><button class="btn btn-secondary" type="button" id="mobilePlateLookup">Consultar</button><?php endif; ?></div><?php if ((string)($f['semantic_role']??'')==='vehicle_plate'): ?><div class="mobile-meta" id="mobileVehicleMessage" aria-live="polite">Digite a placa e consulte os dados do veículo.</div><?php endif; ?><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <div class="mobile-actions mobile-nav-actions"><button class="btn btn-secondary" type="submit" onclick="this.form.elements['target_step'].value=<?php echo $showVehicle?3:2; ?>">← Voltar</button><button class="btn btn-primary" type="submit" onclick="this.form.elements['target_step'].value=5">Avançar →</button></div>
      </form>
    </div></div>
    <?php
}

if ($showDamage && $step === 4 && $draft) {
    ?>
    <div class="mobile-step-title"><b>4</b><h1>Avarias</h1></div>
    <p class="mobile-step-help">Marque os pontos de avaria e, quando necessário, registre a foto diretamente pela câmera.</p>
    <div class="card"><div class="card-body">
      <form method="post" id="wizardStepForm" data-wizard-save="1">
        <input type="hidden" name="action" value="save_step3"><input type="hidden" name="step" value="4"><input type="hidden" name="target_step" value="5">
        <?php if(os_vehicle_damage_enabled((array)$settings)): ?>
          <?php os_vehicle_damage_editor(isset($draft['damage_points'])?(array)$draft['damage_points']:array(),(array)$settings); ?>
        <?php else: ?>
          <div class="mobile-empty">O mapa de avarias / inspeção está desabilitado nas Configurações da O.S. Você pode avançar normalmente.</div>
        <?php endif; ?>
        <div class="mobile-actions mobile-nav-actions"><button class="btn btn-secondary" type="submit" onclick="this.form.elements['target_step'].value=<?php echo $showVehicle?3:2; ?>">← Voltar</button><button class="btn btn-primary" type="submit" onclick="this.form.elements['target_step'].value=5">Avançar →</button></div>
      </form>
    </div></div>
    <?php
}

if ($step === 5 && $draft) {
    $editIndex = isset($_GET['edit_item']) ? (int)$_GET['edit_item'] : -1;
    $edit = ($editIndex >= 0 && isset($draft['items'][$editIndex])) ? $draft['items'][$editIndex] : null;
    $tot = os_mobile_totals($draft);
    ?>
    <div class="mobile-step-title"><b>5</b><h1>Adicionar produto ou serviço</h1></div>
    <div class="card"><div class="card-body">
      <form method="post" id="mobileItemForm"><input type="hidden" name="action" value="<?php echo $edit?'update_item':'add_item'; ?>"><input type="hidden" name="step" value="5"><input type="hidden" name="item_index" value="<?php echo $edit?$editIndex:-1; ?>"><input type="hidden" name="produto_id" id="mobile_produto_id" value="<?php echo $edit?(int)$edit['produto_id']:0; ?>">
        <div class="mobile-form-grid">
          <div class="full"><label>Produto / Serviço *</label><div class="mobile-search-wrap"><input id="mobile_product_search" autocomplete="off" value="<?php echo $edit?os_e($edit['description']):''; ?>" placeholder="Digite código ou descrição"><div id="mobile_product_results" class="mobile-search-results"></div></div><div id="mobile_stock_info" class="hint"></div></div>
          <div><label>Quantidade</label><input type="number" step="0.0001" min="0.0001" name="quantity" id="mobile_qty" value="<?php echo $edit?os_e($edit['quantity']):'1'; ?>"></div>
          <div><label>Valor unitário</label><input name="unit_price" id="mobile_price" inputmode="decimal" value="<?php echo $edit?os_e(number_format((float)$edit['unit_price'],2,',','.')):''; ?>"></div>
          <div><label>Vendedor/Técnico</label><select name="responsible_person_id"><option value="">Não informado</option><?php foreach($responsibles as $rp): ?><option value="<?php echo (int)$rp['id']; ?>" <?php echo $edit&&(int)$edit['responsible_person_id']===(int)$rp['id']?'selected':''; ?>><?php echo os_e($rp['name']); ?></option><?php endforeach; ?></select></div>
          <div><label>Estoque</label><label class="checkline"><input style="width:auto" type="checkbox" id="mobile_reserve" name="reserve_stock" value="1" <?php echo $edit?(!empty($edit['reserve_stock'])?'checked':''):(!empty($settings['default_reserve_product'])?'checked':''); ?>> Reservar produto</label></div>
          <div class="full"><label>Observação do item</label><input name="notes" value="<?php echo $edit?os_e($edit['notes']):''; ?>" placeholder="Opcional"></div>
        </div>
        <div class="mobile-actions"><button class="btn btn-primary" type="submit"><?php echo $edit?'Salvar alteração':'Adicionar item'; ?></button><?php if($edit): ?><a class="btn btn-secondary" href="os_mobile.php?step=6">Cancelar edição</a><?php endif; ?></div>
      </form>
    </div></div>
    <div class="mobile-actions mobile-nav-actions"><a class="btn btn-secondary" href="os_mobile.php?step=<?php echo $showDamage?4:($showVehicle?3:2); ?>">← Voltar</a><a class="btn btn-primary" href="os_mobile.php?step=6">Ver itens →</a></div>
    <?php
}

if ($step === 6 && $draft) {
    $tot = os_mobile_totals($draft);
    ?>
    <div class="mobile-step-title"><b>6</b><h1>Conferir produtos e serviços</h1></div>
    <div class="mobile-card-list">
      <?php if(!$draft['items']): ?><div class="mobile-item-card mobile-empty">Nenhum produto ou serviço adicionado.</div><?php endif; ?>
      <?php foreach($draft['items'] as $idx=>$it): ?>
      <div class="mobile-item-card"><div class="head"><strong><?php echo os_e($it['description']); ?></strong><span class="spacer"></span><span class="mobile-pill"><?php echo os_e($it['item_type']); ?></span></div><div class="mobile-meta"><?php echo number_format((float)$it['quantity'],4,',','.'); ?> × <?php echo os_money($it['unit_price']); ?><?php if($it['responsible_name']): ?><br>Vendedor/Técnico: <b><?php echo os_e($it['responsible_name']); ?></b><?php endif; ?><?php if(!empty($it['reserve_stock'])): ?><br>Estoque: <b>Reservar</b><?php endif; ?></div><div class="mobile-values"><div><span>Total item</span><b><?php echo os_money((float)$it['quantity']*(float)$it['unit_price']); ?></b></div></div><div class="mobile-actions"><a class="btn btn-outline" href="os_mobile.php?step=5&edit_item=<?php echo (int)$idx; ?>">Editar</a><form method="post" style="flex:1"><input type="hidden" name="action" value="remove_item"><input type="hidden" name="step" value="6"><input type="hidden" name="item_index" value="<?php echo (int)$idx; ?>"><button class="btn btn-danger" style="width:100%" type="submit">Remover</button></form></div></div>
      <?php endforeach; ?>
    </div>
    <div class="mobile-totals"><div><span>Produtos</span><b><?php echo os_money($tot['products']); ?></b></div><div><span>Serviços</span><b><?php echo os_money($tot['services']); ?></b></div><div><span>Total</span><b><?php echo os_money($tot['general']); ?></b></div></div>
    <form method="post"><input type="hidden" name="action" value="go_step5"><input type="hidden" name="step" value="6"><div class="mobile-actions mobile-nav-actions"><a class="btn btn-secondary" href="os_mobile.php?step=5">← Voltar</a><button class="btn btn-primary" type="submit">Avançar →</button></div></form>
    <?php
}

if ($step === 7 || $step === 8) {
    if ($successOrderId > 0 && $step === 8) {
        $st=$pdo->prepare("SELECT os_number FROM os_orders WHERE company_id=? AND id=? LIMIT 1");$st->execute(array((int)$companyId,$successOrderId));$num=(int)$st->fetchColumn();
        ?><div class="card"><div class="mobile-success"><div class="check">✓</div><h2>O.S. gravada com sucesso</h2><p>Ordem de Serviço <b>#<?php echo str_pad((string)$num,6,'0',STR_PAD_LEFT); ?></b> salva.</p>
        <?php if($showStatusPrompt): ?><div class="mobile-save-box" style="text-align:left"><strong>Status atualizado. Deseja avisar o cliente por WhatsApp?</strong><div class="mobile-actions"><a class="btn btn-success" href="os_status_comunicar.php?id=<?php echo (int)$successOrderId; ?>&amp;channel=whatsapp&amp;return_to=<?php echo rawurlencode('os_mobile_monitor.php'); ?>">Sim, enviar WhatsApp</a><a class="btn btn-secondary" href="os_mobile_monitor.php">Agora não</a></div></div><?php endif; ?>
        <div class="mobile-actions"><a class="btn btn-primary" href="os_mobile_view.php?id=<?php echo (int)$successOrderId; ?>">Abrir O.S.</a><a class="btn btn-outline" href="os_imprimir.php?id=<?php echo (int)$successOrderId; ?>&amp;origem=mobile">🖨 Imprimir / WhatsApp / E-mail</a><a class="btn btn-success" href="os_mobile.php?action=new">+ Nova O.S.</a><a class="btn btn-secondary" href="os_mobile_monitor.php">Monitor de O.S.</a></div></div></div><?php
    } elseif ($draft) {
        $tot = os_mobile_totals($draft);
        ?>
        <?php if ($step === 7): ?>
        <div class="mobile-step-title"><b>7</b><h1>Forma de pagamento</h1></div>
        <p class="mobile-step-help">Informe as condições de pagamento. Confira os valores no resumo do próximo passo.</p>
        <?php endif; ?>
        <?php if($step === 7 && !empty($settings['allow_payment_plan'])): ?>
        <div class="card"><div class="card-body"><form method="post"><input type="hidden" name="action" value="add_payment"><input type="hidden" name="step" value="7"><div class="mobile-form-grid"><div><label>Forma</label><select name="payment_method_id" id="mobilePaymentMethod" required><option value="">Selecione</option><?php foreach($methods as $m): ?><option value="<?php echo (int)$m['id']; ?>"><?php echo os_e($m['name']); ?></option><?php endforeach; ?></select></div><div><label>Valor</label><input name="amount" id="mobilePaymentAmount" required inputmode="decimal" value="<?php echo os_e(number_format(max(0.0, (float)$tot['general']-(float)$tot['payments']),2,',','.')); ?>"></div><div><label>Vencimento</label><input type="date" name="due_date"></div><div><label>Observação</label><input name="notes" placeholder="Opcional"></div></div><div class="mobile-actions"><button class="btn btn-primary" type="submit">Adicionar condição</button></div></form></div></div>
        <div class="mobile-card-list"><?php if(!$draft['payments']): ?><div class="mobile-pay-card mobile-empty">Pagamento opcional: nenhuma condição informada.</div><?php endif; ?><?php foreach($draft['payments'] as $idx=>$p): ?><div class="mobile-pay-card"><b><?php echo os_e($p['payment_label']); ?></b><div class="mobile-meta"><?php echo $p['due_date']?'Venc.: '.os_e(date('d/m/Y',strtotime($p['due_date']))):'Sem vencimento'; ?><?php echo $p['notes']?' • '.os_e($p['notes']):''; ?></div><div class="mobile-values"><div><span>Valor</span><b><?php echo os_money($p['amount']); ?></b></div></div><form method="post"><input type="hidden" name="action" value="remove_payment"><input type="hidden" name="step" value="7"><input type="hidden" name="payment_index" value="<?php echo (int)$idx; ?>"><button class="btn btn-danger" style="width:100%;margin-top:8px" type="submit">Remover</button></form></div><?php endforeach; ?></div>
        <?php elseif($step === 7): ?><div class="card"><div class="mobile-empty">O lançamento de formas de pagamento está desabilitado nas Configurações da O.S.</div></div><?php endif; ?>
        <?php if ($step === 7): ?>
        <div class="mobile-totals"><div><span>Total O.S.</span><b><?php echo os_money($tot['general']); ?></b></div><div><span>Informado</span><b><?php echo os_money($tot['payments']); ?></b></div><div><span>Diferença</span><b><?php echo os_money($tot['general']-$tot['payments']); ?></b></div></div>
        <form method="post"><input type="hidden" name="action" value="go_summary"><div class="mobile-actions mobile-nav-actions"><a class="btn btn-secondary" href="os_mobile.php?step=6">← Voltar</a><button class="btn btn-primary" type="submit">Avançar →</button></div></form>
        <?php else: ?>
        <div class="mobile-step-title"><b>8</b><h1>Resumo e salvamento</h1></div>
        <div class="mobile-review" style="margin-top:12px"><h3>Resumo para gravação</h3><div class="mobile-review-line"><span>Cliente</span><b><?php echo os_e($draft['person_name']?:'Consumidor final'); ?></b></div><div class="mobile-review-line"><span>Contato</span><b><?php echo os_e($draft['contact_name']?:'—'); ?></b></div><div class="mobile-review-line"><span>Telefone / WhatsApp</span><b><?php echo os_e($draft['contact_phone']?:'—'); ?></b></div><div class="mobile-review-line"><span>Itens</span><b><?php echo count((array)$draft['items']); ?></b></div><?php if($showDamage && os_vehicle_damage_enabled((array)$settings)): ?><div class="mobile-review-line"><span>Avarias</span><b><?php echo count((array)($draft['damage_points'] ?? array())); ?> marcação(ões)</b></div><?php endif; ?><div class="mobile-review-line"><span>Total</span><b><?php echo os_money($tot['general']); ?></b></div></div>
        <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');if(b)b.disabled=true;return true;"><input type="hidden" name="action" value="final_save"><input type="hidden" name="step" value="8"><div class="mobile-actions mobile-nav-actions"><a class="btn btn-secondary" href="os_mobile.php?step=7">← Voltar</a><button class="btn btn-success" type="submit">✓ Salvar O.S.</button></div></form>
        <?php endif; ?>
        <div class="mobile-actions"><a class="btn btn-outline" href="os_mobile.php?action=cancel_draft" onclick="return confirm('Descartar este lançamento mobile e voltar ao Monitor?')">Descartar rascunho</a></div>
        <?php
    }
}
}
?>
</div>
<script>
(function(){
 function jsonFetch(url,options){return fetch(url,options||{credentials:'same-origin'}).then(function(r){return r.text().then(function(t){var j;try{j=JSON.parse(t)}catch(e){throw new Error('Resposta inválida do servidor.');}if(!r.ok||!j.ok)throw new Error(j.message||'Falha na operação.');return j;});});}
 function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
 window.mobileWizardGo=function(target){target=parseInt(target||1,10);var form=document.querySelector('form[data-wizard-save="1"]');if(form){var input=form.querySelector('input[name="target_step"]');if(input)input.value=String(target);if(typeof form.requestSubmit==='function'){form.requestSubmit();}else{var ev=document.createEvent('Event');ev.initEvent('submit',true,true);if(form.dispatchEvent(ev))form.submit();}}else{window.location.href='os_mobile.php?step='+encodeURIComponent(target);}};
 var pInput=document.getElementById('mobile_person_search'),pResults=document.getElementById('mobile_person_results'),pId=document.getElementById('mobile_person_id'),pTimer=null,pReq=0;
 function hide(el){if(el)el.classList.remove('open');}
 if(pInput){pInput.addEventListener('input',function(){pId.value='';document.getElementById('mobile_person_selected').style.display='none';clearTimeout(pTimer);var q=this.value.trim();if(!q){hide(pResults);return;}pTimer=setTimeout(function(){var id=++pReq;jsonFetch('os_ajax.php?action=search_people&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(j){if(id!==pReq)return;var rows=j.rows||[];pResults.innerHTML=rows.length?rows.map(function(r){return '<div class="mobile-search-row" data-id="'+r.id+'" data-name="'+esc(r.name)+'" data-doc="'+esc(r.document||'')+'" data-phone="'+esc(r.whatsapp||r.phone||'')+'"><b>'+esc(r.name)+'</b><small>'+esc(r.document||'')+((r.whatsapp||r.phone)?' • '+esc(r.whatsapp||r.phone):'')+'</small></div>';}).join(''):'<div class="search-message">Nenhuma pessoa encontrada.</div>';pResults.classList.add('open');}).catch(function(e){pResults.innerHTML='<div class="search-message">'+esc(e.message)+'</div>';pResults.classList.add('open');});},260);});pResults.addEventListener('click',function(e){var r=e.target.closest('.mobile-search-row');if(!r)return;pId.value=r.dataset.id;pInput.value=r.dataset.name;document.getElementById('mobile_person_selected_name').textContent=r.dataset.name;document.getElementById('mobile_person_selected_doc').textContent=r.dataset.doc?' • '+r.dataset.doc:'';var cn=document.getElementById('mobile_contact_name'),cp=document.getElementById('mobile_contact_phone');if(window.osAutoFillContact){if(cn)cn.value=r.dataset.name||'';if(cp)cp.value=r.dataset.phone||'';}document.getElementById('mobile_person_selected').style.display='block';hide(pResults);});}
 function regDigits(v){return String(v||'').replace(/\D/g,'');}
 function regCpf(v){var d=regDigits(v).slice(0,11);return d.replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2');}
 function regCnpj(v){var d=regDigits(v).slice(0,14);return d.replace(/(\d{2})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1/$2').replace(/(\d{4})(\d{1,2})$/,'$1-$2');}
 function regCep(v){var d=regDigits(v).slice(0,8);return d.length>5?d.slice(0,5)+'-'+d.slice(5):d;}
 function regSet(id,v,emptyOnly){var el=document.getElementById(id);if(!el)return;if(emptyOnly&&String(el.value||'').trim()!=='')return;el.value=v||'';}
 function syncRegType(){var t=document.getElementById('reg_person_type'),d=document.getElementById('reg_doc');if(!t||!d)return;var pf=t.value==='FISICA';document.getElementById('reg_doc_label').textContent=pf?'CPF':'CNPJ';document.getElementById('reg_birth_label').textContent=pf?'Data de nascimento':'Data de abertura';document.getElementById('reg_fantasy_wrap').style.display=pf?'none':'';document.getElementById('reg_im_wrap').style.display=pf?'none':'';document.getElementById('reg_rg_wrap').style.display=pf?'':'none';document.getElementById('reg_issuer_wrap').style.display=pf?'':'none';document.getElementById('reg_identity_state_wrap').style.display=pf?'':'none';document.getElementById('reg_cnpj_button_wrap').style.display=pf?'none':'';d.placeholder=pf?'Digite o CPF':'Digite o CNPJ';d.value=pf?regCpf(d.value):regCnpj(d.value);}
 function lookupRegCnpj(){var t=document.getElementById('reg_person_type');if(!t||t.value!=='JURIDICA')return;var c=regDigits(document.getElementById('reg_doc').value),st=document.getElementById('reg_doc_status');if(c.length!==14){if(st)st.textContent='Informe um CNPJ com 14 dígitos.';return;}if(st)st.textContent='Consultando CNPJ...';fetch('pessoa_cnpj_lookup.php?cnpj='+encodeURIComponent(c),{cache:'no-store',credentials:'same-origin'}).then(function(r){return r.json().then(function(j){if(!r.ok||!j.ok)throw new Error(j.error||'CNPJ não encontrado.');return j;});}).then(function(j){var d=j.data||{};regSet('reg_name',d.razao_social||'',true);regSet('reg_fantasy',d.nome_fantasia||'',true);regSet('reg_email',d.email||'',true);regSet('reg_phone',d.telefone||'',true);regSet('reg_whats',d.telefone||'',true);regSet('reg_ie',d.state_registration||'',true);regSet('reg_im',d.municipal_registration||'',true);regSet('reg_birth',d.data_inicio_atividade||'',true);if(d.cep)regSet('reg_zip',regCep(d.cep),true);regSet('reg_address',d.logradouro||'',true);regSet('reg_number',d.numero||'',true);regSet('reg_complement',d.complemento||'',true);regSet('reg_district',d.bairro||'',true);regSet('reg_city',d.municipio||'',true);regSet('reg_city_ibge',d.city_ibge||'',true);regSet('reg_state',d.uf||'',true);if(st)st.textContent='Dados carregados. Confira antes de salvar.';}).catch(function(e){if(st)st.textContent=e.message||'Falha ao consultar CNPJ.';});}
 function lookupRegCep(){var c=regDigits(document.getElementById('reg_zip').value),st=document.getElementById('reg_zip_status');if(c.length!==8){if(st)st.textContent='Informe um CEP com 8 dígitos.';return;}if(st)st.textContent='Consultando CEP...';fetch('https://viacep.com.br/ws/'+c+'/json/',{cache:'no-store'}).then(function(r){if(!r.ok)throw new Error('CEP não encontrado.');return r.json();}).then(function(d){if(d.erro)throw new Error('CEP não encontrado.');regSet('reg_address',d.logradouro||'',false);regSet('reg_district',d.bairro||'',false);regSet('reg_city',d.localidade||'',false);regSet('reg_city_ibge',d.ibge||'',false);regSet('reg_state',d.uf||'',false);regSet('reg_complement',d.complemento||'',true);if(st)st.textContent='Endereço carregado pelo CEP.';}).catch(function(e){if(st)st.textContent=e.message||'Falha ao consultar CEP.';});}
 var regType=document.getElementById('reg_person_type'),regDoc=document.getElementById('reg_doc'),regZip=document.getElementById('reg_zip');if(regType)regType.addEventListener('change',syncRegType);if(regDoc){regDoc.addEventListener('input',function(){this.value=(regType&&regType.value==='JURIDICA')?regCnpj(this.value):regCpf(this.value);});regDoc.addEventListener('blur',function(){if(regType&&regType.value==='JURIDICA'&&regDigits(this.value).length===14)lookupRegCnpj();});}if(regZip){regZip.addEventListener('input',function(){this.value=regCep(this.value);});regZip.addEventListener('blur',lookupRegCep);}var regCnpjBtn=document.getElementById('reg_cnpj_lookup');if(regCnpjBtn)regCnpjBtn.onclick=lookupRegCnpj;
 var toggle=document.getElementById('toggleRegister'),reg=document.getElementById('mobileRegister');if(toggle&&reg)toggle.onclick=function(){reg.classList.toggle('open');if(reg.classList.contains('open')){syncRegType();document.getElementById('reg_person_type').focus();}};
 window.osAutoFillContact=<?php echo !empty($osExtra['auto_fill_contact'])?'true':'false'; ?>;
 var saveReg=document.getElementById('saveRegister');if(saveReg)saveReg.onclick=function(){var name=String(document.getElementById('reg_name').value||'').trim();if(!name){alert('Informe o nome/razão social.');document.getElementById('reg_name').focus();return;}var fd=new FormData();fd.append('action','create_person');var m={person_type:'reg_person_type',name:'reg_name',fantasy_name:'reg_fantasy',document:'reg_doc',birth_opening_date:'reg_birth',state_registration:'reg_ie',municipal_registration:'reg_im',identity_document:'reg_rg',identity_issuer:'reg_issuer',identity_state:'reg_identity_state',email:'reg_email',whatsapp:'reg_whats',phone:'reg_phone',zip_code:'reg_zip',address:'reg_address',number:'reg_number',district:'reg_district',city:'reg_city',city_ibge:'reg_city_ibge',state:'reg_state',complement:'reg_complement'};Object.keys(m).forEach(function(k){var el=document.getElementById(m[k]);fd.append(k,el?el.value:'');});saveReg.disabled=true;var txt=saveReg.textContent;saveReg.textContent='Salvando...';jsonFetch('os_ajax.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(j){pId.value=j.row.id;pInput.value=j.row.name;document.getElementById('mobile_person_selected_name').textContent=j.row.name;document.getElementById('mobile_person_selected_doc').textContent=j.row.document?' • '+j.row.document:'';var cn=document.getElementById('mobile_contact_name'),cp=document.getElementById('mobile_contact_phone');if(window.osAutoFillContact){if(cn)cn.value=j.row.name||'';if(cp)cp.value=j.row.whatsapp||j.row.phone||'';}document.getElementById('mobile_person_selected').style.display='block';reg.classList.remove('open');}).catch(function(e){alert(e.message);}).finally(function(){saveReg.disabled=false;saveReg.textContent=txt;});};
 syncRegType();
 window.mobileQuickOption=function(fid){var label=prompt('Digite a nova opção:');if(!label)return;var fd=new FormData();fd.append('action','quick_add_option');fd.append('field_id',fid);fd.append('label',label);jsonFetch('os_ajax.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(j){var sel=document.getElementById('mfield_'+fid),o=document.createElement('option');o.value=j.row.value;o.textContent=j.row.label;o.selected=true;sel.appendChild(o);}).catch(function(e){alert(e.message);});};
 var prod=document.getElementById('mobile_product_search'),prodResults=document.getElementById('mobile_product_results'),prodId=document.getElementById('mobile_produto_id'),prodTimer=null,prodReq=0,allowOut=<?php echo !empty($settings['allow_out_of_stock_reservation'])?'true':'false'; ?>;
 if(prod){prod.addEventListener('input',function(){prodId.value='';clearTimeout(prodTimer);var q=this.value.trim();if(!q){hide(prodResults);return;}prodTimer=setTimeout(function(){var rid=++prodReq;jsonFetch('os_ajax.php?action=search_items&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(j){if(rid!==prodReq)return;var rows=j.rows||[];prodResults.innerHTML=rows.length?rows.map(function(r){var av=parseFloat(r.estoque_atual||0)-parseFloat(r.estoque_reservado||0);return '<div class="mobile-search-row" data-json="'+encodeURIComponent(JSON.stringify(r))+'"><b>'+esc(r.nome)+'</b><small>Cód. '+esc(r.numero_cadastro||r.codigo_firebird||r.id)+' • '+(parseInt(r.is_service||0,10)===1?'Serviço':'Produto • Estoque disp.: '+av.toFixed(4))+' • R$ '+parseFloat(r.preco||0).toFixed(2).replace('.',',')+'</small></div>';}).join(''):'<div class="search-message">Nenhum item encontrado.</div>';prodResults.classList.add('open');}).catch(function(e){prodResults.innerHTML='<div class="search-message">'+esc(e.message)+'</div>';prodResults.classList.add('open');});},260);});prodResults.addEventListener('click',function(e){var row=e.target.closest('.mobile-search-row');if(!row)return;var r=JSON.parse(decodeURIComponent(row.dataset.json));prodId.value=r.id;prod.value=r.nome;document.getElementById('mobile_price').value=parseFloat(r.preco||0).toFixed(2).replace('.',',');var av=parseFloat(r.estoque_atual||0)-parseFloat(r.estoque_reservado||0),svc=parseInt(r.is_service||0,10)===1,res=document.getElementById('mobile_reserve');document.getElementById('mobile_stock_info').textContent=svc?'Serviço: sem reserva de estoque.':'Disponível: '+av.toFixed(4)+(allowOut?' • reserva acima do saldo permitida':'');if(res){res.disabled=svc;if(svc)res.checked=false;}hide(prodResults);});}
 document.addEventListener('click',function(e){if(pResults&&!e.target.closest('#mobile_person_search')&&!e.target.closest('#mobile_person_results'))hide(pResults);if(prodResults&&!e.target.closest('#mobile_product_search')&&!e.target.closest('#mobile_product_results'))hide(prodResults);});
})();
</script>
<script>
(function(){
 var pm=document.getElementById('mobilePaymentMethod'), pa=document.getElementById('mobilePaymentAmount');
 if(pm&&pa){pm.addEventListener('change',function(){if(!pa.value.trim())pa.value=pa.getAttribute('value')||'0,00';});}
 var plate=document.querySelector('[data-semantic="vehicle_plate"]'),button=document.getElementById('mobilePlateLookup'),msg=document.getElementById('mobileVehicleMessage');
 if(!plate||!button)return;
 function norm(v){return String(v||'').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,10);}
 function lookup(){
   var value=norm(plate.value);plate.value=value;
   if(value.length<6){msg.textContent='Informe uma placa válida.';return;}
   msg.textContent='Consultando veículo...';button.disabled=true;
   fetch('os_ajax.php?action=vehicle_by_plate&plate='+encodeURIComponent(value),{credentials:'same-origin',cache:'no-store'})
   .then(function(r){return r.json().then(function(j){if(!r.ok||!j.ok)throw Error(j.message||'Falha na consulta.');return j;});})
   .then(function(j){
     if(norm(plate.value)!==value)return;
     if(!j.found){msg.textContent='Placa não cadastrada. Preencha manualmente os dados do veículo e salve a O.S.';return;}
     var values=j.values||{},fields=Array.prototype.slice.call(document.querySelectorAll('[data-semantic]'));
     var conflicts=fields.some(function(el){var v=values[el.dataset.semantic];return el!==plate&&v!=null&&String(v)!==''&&String(el.value||'')!==''&&String(el.value)!==String(v);});
     if(conflicts&&!confirm('Existem dados preenchidos. Deseja substituí-los pelos dados do veículo encontrado?'))return;
     fields.forEach(function(el){var v=values[el.dataset.semantic];if(v==null)return;if(el.type==='checkbox')el.checked=String(v)==='1';else el.value=String(v);});
     plate.value=norm(values.vehicle_plate||value);
     msg.textContent='Veículo localizado e dados carregados'+(j.last_order_number?' • última O.S. #'+j.last_order_number:'')+'.';
   }).catch(function(e){msg.textContent=e.message||'Erro ao consultar placa.';}).finally(function(){button.disabled=false;});
 }
 button.addEventListener('click',lookup);
 plate.addEventListener('nexa-plate-recognized',lookup);
 plate.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();lookup();}});
 plate.addEventListener('input',function(){plate.value=norm(plate.value);});
})();
</script>
<script src="assets/os_plate_ocr.js?v=20261019_2"></script>
<?php os_page_end(); ?>
