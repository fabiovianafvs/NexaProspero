<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_extra_options.php';
require_once __DIR__ . '/../config/os_step_visibility.php';
require_once __DIR__ . '/../config/os_customer_communication.php';
require_once __DIR__ . '/_os_ui.php';

$pdo = os_db();
$companyId = os_company_id();
os_require_enabled($pdo, $companyId);
os_ensure_defaults($pdo, $companyId);

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'geral';
$ok = isset($_GET['ok']) ? $_GET['ok'] : '';
$error = '';
$extraOptions = os_extra_options($pdo, (int)$companyId);
$stepVisibility = os_step_visibility($pdo, (int)$companyId);
$semanticRoles = os_vehicle_semantic_roles();
$vehicleFeatureReady = os_vehicle_feature_ready($pdo);
if (empty($_SESSION['nexa_os_damage_template_csrf'])) $_SESSION['nexa_os_damage_template_csrf'] = bin2hex(random_bytes(24));
$templateCsrf = (string)$_SESSION['nexa_os_damage_template_csrf'];
$templateCategories = array('Automóveis','Motocicletas','Notebooks e Computadores','Celulares e Tablets','Impressoras','Bicicletas','Eletrodomésticos','Outros');

$damageTemplateEditId = (int)(isset($_GET['damage_template_id']) ? $_GET['damage_template_id'] : 0);
$damageTemplates = os_vehicle_damage_custom_templates($pdo, (int)$companyId, false);
$damageTemplateEdit = $damageTemplateEditId > 0 && isset($damageTemplates['custom:' . $damageTemplateEditId]) ? $damageTemplates['custom:' . $damageTemplateEditId] : null;

function os_cfg_redirect($tab, $msg)
{
    header('Location: os_configuracoes.php?tab='.urlencode($tab).'&ok='.urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'save_sequence') {
            if (empty($_SESSION['nexa_os_seq_csrf']) || !hash_equals((string)$_SESSION['nexa_os_seq_csrf'], (string)($_POST['seq_csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
            $nextNumber = (int)preg_replace('/\D+/', '', (string)($_POST['next_number'] ?? ''));
            os_sequence_set_next($pdo, (int)$companyId, $nextNumber);
            os_cfg_redirect('geral', 'Numeração atualizada: a próxima O.S. será a nº '.$nextNumber.'.');
        }
        if ($action === 'save_settings') {
            if (!$stepVisibility['migration_ready']) throw new RuntimeException('Aplique a migration de etapas opcionais pelo Atualizador do Banco antes de salvar.');
            $reserve = isset($_POST['default_reserve_product']) ? 1 : 0;
            $allowOutOfStock = isset($_POST['allow_out_of_stock_reservation']) ? 1 : 0;
            $payments = isset($_POST['allow_payment_plan']) ? 1 : 0;
            $damageMapType = os_vehicle_damage_mode_normalize(isset($_POST['damage_map_type']) ? $_POST['damage_map_type'] : 'none');
            $availableDamageProfiles = os_vehicle_damage_profiles_all($pdo, (int)$companyId, false);
            if (!isset($availableDamageProfiles[$damageMapType])) $damageMapType = 'none';
            $autoParts = $damageMapType !== 'none' ? 1 : 0;
            $model = isset($_POST['default_print_model']) ? $_POST['default_print_model'] : 'a4_half';
            if (!in_array($model, array('72mm','a4_half','a4_full','a4_landscape_dual'), true)) $model = 'a4_half';
            $sigCustomer = isset($_POST['print_customer_signature']) ? 1 : 0;
            $sigResp = isset($_POST['print_responsible_signature']) ? 1 : 0;
            $terms = trim(isset($_POST['terms_text']) ? $_POST['terms_text'] : '');
            $stmt = $pdo->prepare("UPDATE os_settings SET default_reserve_product=?,allow_out_of_stock_reservation=?,allow_payment_plan=?,auto_parts_mode=?,damage_map_type=?,default_print_model=?,print_customer_signature=?,print_responsible_signature=?,terms_text=?,updated_at=NOW() WHERE company_id=?");
            $stmt->execute(array($reserve,$allowOutOfStock,$payments,$autoParts,$damageMapType,$model,$sigCustomer,$sigResp,$terms!==''?$terms:null,$companyId));
            $extraKeys = ['auto_fill_contact','require_contact_name','require_contact_phone'];
            $values = array_map(static fn($k) => isset($_POST[$k]) ? 1 : 0, $extraKeys);
            $sql = 'UPDATE os_settings SET '.implode(',',array_map(static fn($k) => $k.'=?', $extraKeys)).' WHERE company_id=?';
            $extraStmt = $pdo->prepare($sql);
            $extraStmt->execute(array_merge($values,[(int)$companyId]));
            $stepsUpdate = $pdo->prepare('UPDATE os_settings SET show_vehicle_step=?,show_damage_step=?,updated_at=NOW() WHERE company_id=?');
            $stepsUpdate->execute([isset($_POST['show_vehicle_step'])?1:0,isset($_POST['show_damage_step'])?1:0,(int)$companyId]);
            os_cfg_redirect('geral','Configurações salvas.');
        }

        if (in_array($action, array('save_damage_template','disable_damage_template','duplicate_damage_template'), true)) {
            $token = (string)($_POST['template_csrf'] ?? '');
            if (!hash_equals($templateCsrf, $token)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
        }
        if ($action === 'duplicate_damage_template') {
            $origin = trim((string)($_POST['origin_mode'] ?? ''));
            $newId = os_vehicle_damage_template_clone($pdo, (int)$companyId, $origin);
            header('Location: os_configuracoes.php?tab=geral&damage_template_id=' . $newId . '&ok=' . urlencode('Modelo duplicado. Revise e salve os dados.'));
            exit;
        }

        if ($action === 'save_damage_template') {
            $templateId = (int)(isset($_POST['template_id']) ? $_POST['template_id'] : 0);
            $name = trim((string)(isset($_POST['template_name']) ? $_POST['template_name'] : ''));
            $code = os_vehicle_damage_template_code(isset($_POST['template_code']) && trim((string)$_POST['template_code']) !== '' ? (string)$_POST['template_code'] : $name);
            $sort = (int)(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0);
            $category = trim((string)($_POST['template_category'] ?? 'Outros'));
            if ($category === '') $category = 'Outros';
            if (mb_strlen($category, 'UTF-8') > 80) throw new RuntimeException('Categoria deve ter no máximo 80 caracteres.');
            $active = isset($_POST['active']) ? 1 : 0;
            if ($name === '') throw new RuntimeException('Informe o nome do modelo de avaria.');
            $current = null;
            if ($templateId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM os_damage_templates WHERE company_id=? AND id=? LIMIT 1");
                $stmt->execute(array((int)$companyId, $templateId));
                $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$current) throw new RuntimeException('Modelo de avaria não encontrado.');
            }
            $newImageRef = $current['image_ref'] ?? '';
            $hasUpload = isset($_FILES['template_image']) && is_array($_FILES['template_image']) && (int)($_FILES['template_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasUpload) {
                $newImageRef = os_vehicle_damage_template_store_upload((int)$companyId, $code, $_FILES['template_image']);
            }
            if ($templateId <= 0 && $newImageRef === '') throw new RuntimeException('Selecione a imagem do novo modelo de avaria.');
            if ($templateId > 0) {
                $stmt = $pdo->prepare("UPDATE os_damage_templates SET code=?,name=?,category=?,image_ref=?,sort_order=?,active=?,updated_at=NOW() WHERE company_id=? AND id=?");
                $stmt->execute(array($code,$name,$category,$newImageRef,$sort,$active,(int)$companyId,$templateId));
                if ($hasUpload && !empty($current['image_ref']) && $current['image_ref'] !== $newImageRef) {
                    os_vehicle_damage_template_delete_relative((string)$current['image_ref'], (int)$companyId);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO os_damage_templates (company_id,code,name,category,image_ref,sort_order,active) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute(array((int)$companyId,$code,$name,$category,$newImageRef,$sort,$active));
                $templateId = (int)$pdo->lastInsertId();
            }
            os_cfg_redirect('geral','Modelo de avaria salvo.');
        }

        if ($action === 'disable_damage_template') {
            $templateId = (int)(isset($_POST['template_id']) ? $_POST['template_id'] : 0);
            if ($templateId <= 0) throw new RuntimeException('Modelo de avaria inválido.');
            $stmt = $pdo->prepare("UPDATE os_damage_templates SET active=0,updated_at=NOW() WHERE company_id=? AND id=?");
            $stmt->execute(array((int)$companyId,$templateId));
            $selectedStmt = $pdo->prepare("SELECT damage_map_type FROM os_settings WHERE company_id=? LIMIT 1");
            $selectedStmt->execute(array((int)$companyId));
            if ((string)$selectedStmt->fetchColumn() === 'custom:' . $templateId) {
                $pdo->prepare("UPDATE os_settings SET damage_map_type='none',auto_parts_mode=0,updated_at=NOW() WHERE company_id=?")->execute(array((int)$companyId));
            }
            os_cfg_redirect('geral','Modelo de avaria desativado.');
        }

        if ($action === 'save_status') {
            $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
            $code = strtoupper(os_slug(isset($_POST['code']) && trim($_POST['code']) !== '' ? $_POST['code'] : $name));
            $color = trim(isset($_POST['color']) ? $_POST['color'] : '#64748b');
            $sort = (int)(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0);
            $initial = isset($_POST['is_initial']) ? 1 : 0;
            $final = isset($_POST['is_final']) ? 1 : 0;
            $batchBilling = isset($_POST['allow_batch_billing']) ? 1 : 0;
            $billedStatus = isset($_POST['is_billed_status']) ? 1 : 0;
            $active = isset($_POST['active']) ? 1 : 0;
            $customerMessage = trim(isset($_POST['customer_message']) ? (string)$_POST['customer_message'] : '');
            $notifyStatus = isset($_POST['notify_whatsapp_status']) && $active === 1;
            if (!$active) { $initial = 0; $batchBilling = 0; $billedStatus = 0; }
            if ($name === '') throw new RuntimeException('Informe o nome do status.');
            $pdo->beginTransaction();
            if ($initial) {
                $u = $pdo->prepare("UPDATE os_statuses SET is_initial=0 WHERE company_id=?");
                $u->execute(array($companyId));
            }
            if ($billedStatus) {
                $u = $pdo->prepare("UPDATE os_statuses SET is_billed_status=0 WHERE company_id=?");
                $u->execute(array($companyId));
            }
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE os_statuses SET name=?,code=?,color=?,sort_order=?,is_initial=?,is_final=?,allow_batch_billing=?,is_billed_status=?,active=?,updated_at=NOW() WHERE company_id=? AND id=?");
                $stmt->execute(array($name,$code,$color,$sort,$initial,$final,$batchBilling,$billedStatus,$active,$companyId,$id));
            } else {
                $stmt = $pdo->prepare("INSERT INTO os_statuses (company_id,name,code,color,sort_order,is_initial,is_final,allow_batch_billing,is_billed_status,active) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute(array($companyId,$name,$code,$color,$sort,$initial,$final,$batchBilling,$billedStatus,$active));
                $id = (int)$pdo->lastInsertId();
            }
            os_status_customer_message_save($pdo,$companyId,$id,$customerMessage);
            os_status_notify_save($pdo,$companyId,$id,$notifyStatus);
            $pdo->commit();
            os_cfg_redirect('status','Status salvo.');
        }

        if ($action === 'disable_status') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE os_statuses SET active=0,is_initial=0,allow_batch_billing=0,is_billed_status=0,updated_at=NOW() WHERE company_id=? AND id=?");
            $stmt->execute(array($companyId,$id));
            os_cfg_redirect('status','Status desativado.');
        }

        if ($action === 'set_batch_billing_status') {
            $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $enabled = isset($_POST['enabled']) && (int)$_POST['enabled'] === 1 ? 1 : 0;
            if ($id <= 0) throw new RuntimeException('Status inválido.');
            $stmt = $pdo->prepare("UPDATE os_statuses SET allow_batch_billing=?,updated_at=NOW() WHERE company_id=? AND id=? AND active=1");
            $stmt->execute(array($enabled,$companyId,$id));
            os_cfg_redirect('status',$enabled ? 'Status habilitado para faturamento em lote.' : 'Status removido do faturamento em lote.');
        }


        if ($action === 'set_billed_status') {
            $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $enabled = isset($_POST['enabled']) && (int)$_POST['enabled'] === 1 ? 1 : 0;
            if ($id <= 0) throw new RuntimeException('Status inválido.');
            $pdo->beginTransaction();
            try {
                if ($enabled) {
                    $pdo->prepare("UPDATE os_statuses SET is_billed_status=0 WHERE company_id=?")->execute(array($companyId));
                    $stmt = $pdo->prepare("UPDATE os_statuses SET is_billed_status=1,updated_at=NOW() WHERE company_id=? AND id=? AND active=1");
                    $stmt->execute(array($companyId,$id));
                    if ($stmt->rowCount() < 1) throw new RuntimeException('O status precisa estar ativo para ser definido como Faturado.');
                } else {
                    $pdo->prepare("UPDATE os_statuses SET is_billed_status=0,updated_at=NOW() WHERE company_id=? AND id=?")->execute(array($companyId,$id));
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            os_cfg_redirect('status',$enabled ? 'Status definido como Faturado.' : 'Status deixou de ser o status Faturado.');
        }

        if ($action === 'save_field') {
            $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $label = trim(isset($_POST['label']) ? $_POST['label'] : '');
            $key = os_slug(isset($_POST['field_key']) && trim($_POST['field_key']) !== '' ? $_POST['field_key'] : $label);
            $type = isset($_POST['input_type']) ? $_POST['input_type'] : 'text';
            $types = array('text','textarea','number','date','select','checkbox','password');
            if (!in_array($type,$types,true)) $type='text';
            $placeholder = trim(isset($_POST['placeholder']) ? $_POST['placeholder'] : '');
            $semanticRole = $vehicleFeatureReady ? trim(isset($_POST['semantic_role']) ? $_POST['semantic_role'] : '') : '';
            if (!array_key_exists($semanticRole, $semanticRoles)) $semanticRole = '';
            $required = isset($_POST['required']) ? 1 : 0;
            $quick = ($type==='select' && isset($_POST['allow_quick_add'])) ? 1 : 0;
            $print = isset($_POST['show_on_print']) ? 1 : 0;
            if ($type === 'password' && !isset($_POST['force_password_print'])) $print = 0;
            $sort = (int)(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0);
            $active = isset($_POST['active']) ? 1 : 0;
            if ($label === '') throw new RuntimeException('Informe o nome do campo.');
            if ($semanticRole !== '') {
                $check=$pdo->prepare("SELECT id,label FROM os_custom_fields WHERE company_id=? AND semantic_role=? AND id<>? AND active=1 LIMIT 1");
                $check->execute(array($companyId,$semanticRole,$id));
                $used=$check->fetch(PDO::FETCH_ASSOC);
                if($used) throw new RuntimeException('A identificação selecionada já está vinculada ao campo: '.$used['label'].'. Use uma identificação diferente ou edite o campo existente.');
            }
            if ($id > 0) {
                if ($vehicleFeatureReady) {
                    $stmt=$pdo->prepare("UPDATE os_custom_fields SET label=?,field_key=?,input_type=?,semantic_role=?,placeholder=?,required=?,allow_quick_add=?,show_on_print=?,sort_order=?,active=?,updated_at=NOW() WHERE company_id=? AND id=?");
                    $stmt->execute(array($label,$key,$type,$semanticRole!==''?$semanticRole:null,$placeholder!==''?$placeholder:null,$required,$quick,$print,$sort,$active,$companyId,$id));
                } else {
                    $stmt=$pdo->prepare("UPDATE os_custom_fields SET label=?,field_key=?,input_type=?,placeholder=?,required=?,allow_quick_add=?,show_on_print=?,sort_order=?,active=?,updated_at=NOW() WHERE company_id=? AND id=?");
                    $stmt->execute(array($label,$key,$type,$placeholder!==''?$placeholder:null,$required,$quick,$print,$sort,$active,$companyId,$id));
                }
            } else {
                if ($vehicleFeatureReady) {
                    $stmt=$pdo->prepare("INSERT INTO os_custom_fields (company_id,label,field_key,input_type,semantic_role,placeholder,required,allow_quick_add,show_on_print,sort_order,active) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute(array($companyId,$label,$key,$type,$semanticRole!==''?$semanticRole:null,$placeholder!==''?$placeholder:null,$required,$quick,$print,$sort,$active));
                } else {
                    $stmt=$pdo->prepare("INSERT INTO os_custom_fields (company_id,label,field_key,input_type,placeholder,required,allow_quick_add,show_on_print,sort_order,active) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute(array($companyId,$label,$key,$type,$placeholder!==''?$placeholder:null,$required,$quick,$print,$sort,$active));
                }
            }
            os_cfg_redirect('campos','Campo salvo.');
        }

        if ($action === 'move_field') {
            $id=(int)(isset($_POST['id'])?$_POST['id']:0);
            $direction=isset($_POST['direction'])?$_POST['direction']:'';
            if($id<=0 || !in_array($direction,array('up','down'),true)) throw new RuntimeException('Movimentação inválida.');
            $stmt=$pdo->prepare("SELECT id,sort_order FROM os_custom_fields WHERE company_id=? AND id=? LIMIT 1");
            $stmt->execute(array($companyId,$id));
            $current=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$current) throw new RuntimeException('Campo não encontrado.');
            if($direction==='up'){
                $stmt=$pdo->prepare("SELECT id,sort_order FROM os_custom_fields WHERE company_id=? AND id<>? AND (sort_order < ? OR (sort_order=? AND id<?)) ORDER BY sort_order DESC,id DESC LIMIT 1");
                $stmt->execute(array($companyId,$id,(int)$current['sort_order'],(int)$current['sort_order'],$id));
            }else{
                $stmt=$pdo->prepare("SELECT id,sort_order FROM os_custom_fields WHERE company_id=? AND id<>? AND (sort_order > ? OR (sort_order=? AND id>?)) ORDER BY sort_order ASC,id ASC LIMIT 1");
                $stmt->execute(array($companyId,$id,(int)$current['sort_order'],(int)$current['sort_order'],$id));
            }
            $neighbor=$stmt->fetch(PDO::FETCH_ASSOC);
            if($neighbor){
                $newOrder=(int)$neighbor['sort_order'] + ($direction==='up'?-1:1);
                $stmt=$pdo->prepare("UPDATE os_custom_fields SET sort_order=?,updated_at=NOW() WHERE company_id=? AND id=?");
                $stmt->execute(array($newOrder,$companyId,$id));
            }
            os_cfg_redirect('campos','Ordem dos campos atualizada.');
        }

        if ($action === 'disable_field') {
            $id=(int)$_POST['id'];
            $stmt=$pdo->prepare("UPDATE os_custom_fields SET active=0,updated_at=NOW() WHERE company_id=? AND id=?");
            $stmt->execute(array($companyId,$id));
            os_cfg_redirect('campos','Campo desativado.');
        }

        if ($action === 'add_option') {
            $fieldId=(int)$_POST['field_id'];
            $label=trim(isset($_POST['label'])?$_POST['label']:'');
            $value=os_slug(isset($_POST['option_value']) && trim($_POST['option_value'])!==''?$_POST['option_value']:$label);
            $sort=(int)(isset($_POST['sort_order'])?$_POST['sort_order']:0);
            if ($fieldId<=0 || $label==='') throw new RuntimeException('Informe a opção.');
            $stmt=$pdo->prepare("INSERT INTO os_custom_field_options (company_id,field_id,label,option_value,sort_order,active) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),sort_order=VALUES(sort_order),active=1,updated_at=NOW()");
            $stmt->execute(array($companyId,$fieldId,$label,$value,$sort));
            os_cfg_redirect('campos','Opção salva.');
        }

        if ($action === 'disable_option') {
            $id=(int)$_POST['id'];
            $stmt=$pdo->prepare("UPDATE os_custom_field_options SET active=0,updated_at=NOW() WHERE company_id=? AND id=?");
            $stmt->execute(array($companyId,$id));
            os_cfg_redirect('campos','Opção desativada.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$stmt=$pdo->prepare("SELECT * FROM os_settings WHERE company_id=? LIMIT 1"); $stmt->execute(array($companyId)); $settings=$stmt->fetch(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT * FROM os_statuses WHERE company_id=? ORDER BY sort_order,id"); $stmt->execute(array($companyId)); $statuses=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT * FROM os_custom_fields WHERE company_id=? ORDER BY sort_order,id"); $stmt->execute(array($companyId)); $fields=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT * FROM os_custom_field_options WHERE company_id=? ORDER BY field_id,sort_order,id"); $stmt->execute(array($companyId)); $allOptions=$stmt->fetchAll(PDO::FETCH_ASSOC);
$optionsByField=array(); foreach($allOptions as $o){ if(!isset($optionsByField[$o['field_id']]))$optionsByField[$o['field_id']]=array(); $optionsByField[$o['field_id']][]=$o; }
$editFieldId=(int)(isset($_GET['edit_field'])?$_GET['edit_field']:0);
$editField=null; foreach($fields as $f){ if((int)$f['id']===$editFieldId){$editField=$f;break;} }
$editStatusId=(int)(isset($_GET['edit_status'])?$_GET['edit_status']:0);
$editStatus=null; foreach($statuses as $s){ if((int)$s['id']===$editStatusId){$editStatus=$s;break;} }

os_page_start('Configurações da O.S.','config');
echo '<style>
.os-config-toggle-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:2px}.os-config-toggle{display:flex;gap:11px;align-items:flex-start;min-height:82px;padding:13px 14px;border:1px solid var(--os-border-soft);border-radius:12px;background:#fbfdff;cursor:pointer}.os-config-toggle:hover{border-color:#bfd0ea;background:#f8fbff}.os-config-toggle input[type=checkbox]{width:18px!important;height:18px!important;min-width:18px;margin:2px 0 0;padding:0}.os-config-toggle-text{display:flex;flex-direction:column;gap:4px;line-height:1.3}.os-config-toggle-text strong{color:#0b1f5b;font-size:13px}.os-config-toggle-text small{color:#64748b;font-size:11.5px;line-height:1.4}.os-status-flags{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.os-status-flag{display:flex;gap:9px;align-items:flex-start;padding:11px 12px;border:1px solid var(--os-border-soft);border-radius:11px;background:#fbfdff;min-height:72px;cursor:pointer}.os-status-flag input[type=checkbox]{width:18px!important;height:18px!important;min-width:18px;margin:1px 0 0;padding:0}.os-status-flag span{display:block}.os-status-flag b{display:block;color:#0b1f5b;font-size:12.5px}.os-status-flag small{display:block;color:#64748b;font-size:10.8px;line-height:1.35;margin-top:3px}.os-status-help{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}.os-status-help>div{border:1px solid var(--os-border-soft);border-radius:12px;padding:12px 14px;background:#f8fbff;color:#475569;font-size:12px;line-height:1.5}.os-status-help strong{color:#0b1f5b}.os-status-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.os-status-type-badges{display:flex;gap:5px;flex-wrap:wrap}.os-status-editing{border-left:4px solid var(--os-primary);padding-left:10px}@media(max-width:980px){.os-config-toggle-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.os-status-flags{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.os-config-toggle-grid,.os-status-flags,.os-status-help{grid-template-columns:1fr}}
.os-image-preview-panel{border:1px solid var(--os-border-soft);border-radius:12px;padding:12px;background:#f8fafc;max-width:620px;display:grid;gap:7px}.os-image-preview-canvas{width:100%;max-height:300px;overflow:hidden;background:#fff;border:1px solid #dce4ec;border-radius:9px;display:grid;place-items:center;min-height:110px}.os-image-preview-canvas img{display:block;max-height:280px;max-width:100%;width:auto;height:auto;object-fit:contain}.os-image-preview-panel small{color:#64748b}
</style>';
echo '<div class="os-title-row"><h1>Configurações da O.S.</h1><div class="spacer"></div></div>';
if($ok) echo '<div class="alert alert-ok">'.os_e($ok).'</div>';
if($error) echo '<div class="alert alert-error">'.os_e($error).'</div>';
echo '<div class="tabs"><a '.($tab==='geral'?'class="active"':'').' href="?tab=geral">Geral e impressão</a><a '.($tab==='status'?'class="active"':'').' href="?tab=status">Status</a><a '.($tab==='campos'?'class="active"':'').' href="?tab=campos">Campos personalizados</a></div>';

if($tab==='geral'){
    if (empty($_SESSION['nexa_os_seq_csrf'])) $_SESSION['nexa_os_seq_csrf'] = bin2hex(random_bytes(20));
    $osSeq = os_sequence_info($pdo, (int)$companyId);
    echo '<div class="card"><div class="card-head">Numeração da O.S.</div><div class="card-body"><form method="post" onsubmit="return confirm(\'Alterar a numeração? As próximas O.S. seguirão a partir do número informado.\')"><input type="hidden" name="action" value="save_sequence"><input type="hidden" name="seq_csrf" value="'.os_e((string)$_SESSION['nexa_os_seq_csrf']).'"><div class="grid">';
    echo '<div class="c4"><label>Próximo número da O.S.</label><input type="number" name="next_number" min="'.((int)$osSeq['max_used']+1).'" max="999999999" step="1" required value="'.(int)$osSeq['next'].'"><div class="hint">Use para continuar a sequência de outro sistema. Maior O.S. já lançada: nº '.(int)$osSeq['max_used'].'.</div></div>';
    echo '<div class="c3" style="display:flex;align-items:flex-end"><button class="btn btn-primary">Salvar numeração</button></div></div></form></div></div>';
    echo '<div class="card"><div class="card-head">Comportamento padrão</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="save_settings"><div class="grid">';
    echo '<div class="c5"><label>Modelo padrão de impressão</label><select name="default_print_model"><option value="72mm" '.($settings['default_print_model']==='72mm'?'selected':'').'>72 mm</option><option value="a4_half" '.($settings['default_print_model']==='a4_half'?'selected':'').'>A4 - meia folha</option><option value="a4_full" '.($settings['default_print_model']==='a4_full'?'selected':'').'>A4 - folha inteira</option><option value="a4_landscape_dual" '.($settings['default_print_model']==='a4_landscape_dual'?'selected':'').'>A4 paisagem - 2 vias</option></select><div class="hint">Modelo usado ao imprimir a O.S. quando nenhum formato diferente for escolhido.</div></div>';
    echo '<div class="c4"><label>Layout do modelo A4 - 2 vias</label><a class="btn btn-outline" style="width:100%" href="os_layout_modelo.php">✎ Editar layout</a><div class="hint">Edite posição, tamanho, visibilidade e textos dos blocos. A logo é lida das Configurações Gerais do Nexa.</div></div>';
    if (!$stepVisibility['migration_ready']) echo '<div class="c12"><div class="alert alert-error">Ative as novas opções aplicando a migration no Atualizador do Banco. Até lá, as etapas permanecem visíveis.</div></div>';
    echo '<div class="c12"><div class="os-config-toggle-grid">';
    echo '<label class="os-config-toggle"><input type="checkbox" name="default_reserve_product" value="1" '.((int)$settings['default_reserve_product']?'checked':'').'><span class="os-config-toggle-text"><strong>Reserva padrão</strong><small>Reservar o produto automaticamente ao inserir na O.S.</small></span></label>';
    echo '<label class="os-config-toggle"><input type="checkbox" name="allow_out_of_stock_reservation" value="1" '.(!empty($settings['allow_out_of_stock_reservation'])?'checked':'').'><span class="os-config-toggle-text"><strong>Estoque insuficiente</strong><small>Permitir reserva acima do saldo disponível, inclusive em encomendas.</small></span></label>';
    echo '<label class="os-config-toggle"><input type="checkbox" name="allow_payment_plan" value="1" '.((int)$settings['allow_payment_plan']?'checked':'').'><span class="os-config-toggle-text"><strong>Formas de pagamento</strong><small>Permitir informar pagamentos e condições diretamente na O.S.</small></span></label>';
    echo '<label class="os-config-toggle"><input type="checkbox" name="show_vehicle_step" value="1" '.(!empty($stepVisibility['show_vehicle_step'])?'checked':'').'><span class="os-config-toggle-text"><strong>Exibir Dados do Veículo</strong><small>Mostrar campos e etapa de veículo no Desktop e Mobile. Ao ocultar, os dados anteriores são preservados.</small></span></label>';
    echo '<label class="os-config-toggle"><input type="checkbox" name="show_damage_step" value="1" '.(!empty($stepVisibility['show_damage_step'])?'checked':'').'><span class="os-config-toggle-text"><strong>Exibir Passo de Avarias</strong><small>Mostrar o mapa de avarias no Desktop e Mobile. Independente do modo oficina.</small></span></label>';
    $damageMapType = os_vehicle_damage_mode_from_settings((array)$settings);
    $damageMapProfiles = os_vehicle_damage_profiles_all($pdo, (int)$companyId, false);
    echo '<div style="grid-column:1/-1"><label>Mapa de avarias / inspeção</label><select name="damage_map_type">';
    $damageNative = array(); $damageByCategory = array();
    foreach($damageMapProfiles as $mapKey=>$mapMeta){
      if (strpos((string)$mapKey, 'custom:') === 0) $damageByCategory[(string)($mapMeta['category'] ?? 'Outros')][$mapKey] = $mapMeta;
      else $damageNative[$mapKey] = $mapMeta;
    }
    foreach ($damageNative as $mapKey=>$mapMeta) echo '<option value="'.os_e($mapKey).'" '.($damageMapType===$mapKey?'selected':'').'>'.os_e($mapMeta['label']).'</option>';
    if ($damageByCategory) {
      ksort($damageByCategory, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($damageByCategory as $categoryName=>$models) {
        echo '<optgroup label="'.os_e($categoryName).'">';
        foreach ($models as $mapKey=>$mapMeta) echo '<option value="'.os_e($mapKey).'" '.($damageMapType===$mapKey?'selected':'').'>'.os_e($mapMeta['label']).'</option>';
        echo '</optgroup>';
      }
    }
    echo '</select><div class="hint">Escolha o desenho usado para marcar avarias no Desktop, Mobile e na impressão. Quando desabilitado, a etapa de avarias fica oculta.</div></div>';

    echo '<label class="os-config-toggle"><input type="checkbox" name="print_customer_signature" value="1" '.((int)$settings['print_customer_signature']?'checked':'').'><span class="os-config-toggle-text"><strong>Assinatura do cliente</strong><small>Mostrar o espaço para assinatura do cliente na impressão.</small></span></label>';
    echo '<label class="os-config-toggle"><input type="checkbox" name="print_responsible_signature" value="1" '.((int)$settings['print_responsible_signature']?'checked':'').'><span class="os-config-toggle-text"><strong>Assinatura do responsável</strong><small>Mostrar o espaço para assinatura do responsável na impressão.</small></span></label>';
    $extraLabels = [
      'auto_fill_contact'=>['Preencher contato e telefone automaticamente','Quando desligado, selecionar o cliente não substituirá os campos digitados.'],
      'require_contact_name'=>['Contato obrigatório','Exigir contato no Desktop e Mobile.'],
      'require_contact_phone'=>['Telefone/WhatsApp obrigatório','Exigir telefone no Desktop e Mobile.'],
    ];
    foreach($extraLabels as $key=>$texts){
      echo '<label class="os-config-toggle"><input type="checkbox" name="'.os_e($key).'" value="1" '.(!empty($extraOptions[$key])?'checked':'').'><span class="os-config-toggle-text"><strong>'.os_e($texts[0]).'</strong><small>'.os_e($texts[1]).'</small></span></label>';
    }
    echo '</div></div>';
    echo '<div class="c12"><label>Termos/observações padrão na impressão</label><textarea name="terms_text" placeholder="Ex.: equipamentos não retirados em 90 dias...">'.os_e($settings['terms_text']).'</textarea></div>';
    echo '<div class="c12"><button class="btn btn-primary">Salvar configurações</button></div></div></form></div></div>';
    echo '<div class="card"><div class="card-head">Modelos personalizados de avaria / inspeção</div><div class="card-body">';
    echo '<div class="hint" style="margin-bottom:12px">Cadastre novos desenhos para usar na marcação de avarias em qualquer segmento. Recomendado: imagem limpa, fundo claro, traços escuros e proporção horizontal.</div>';
    echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_damage_template"><input type="hidden" name="template_csrf" value="'.os_e($templateCsrf).'"><input type="hidden" name="template_id" value="'.(int)($damageTemplateEdit['id'] ?? 0).'">';
    echo '<div class="grid">';
    echo '<div class="c4"><label>Nome do modelo</label><input type="text" name="template_name" maxlength="120" value="'.os_e((string)($damageTemplateEdit['label'] ?? '')).'" placeholder="Ex.: Notebook gamer"></div>';
    echo '<div class="c3"><label>Código interno</label><input type="text" name="template_code" maxlength="60" value="'.os_e((string)($damageTemplateEdit['code'] ?? '')).'" placeholder="ex.: notebook-gamer"></div>';
    echo '<div class="c4"><label>Categoria / Segmento</label><input type="text" name="template_category" list="osDamageCategories" maxlength="80" required value="'.os_e((string)($damageTemplateEdit['category'] ?? 'Outros')).'" placeholder="Ex.: Equipamentos industriais"><datalist id="osDamageCategories">';
    foreach ($templateCategories as $categoryLabel) echo '<option value="'.os_e($categoryLabel).'">';
    echo '</datalist><div class="hint">Você pode digitar uma categoria nova.</div></div>';
    echo '<div class="c2"><label>Ordem</label><input type="number" name="sort_order" value="'.(int)($damageTemplateEdit['sort_order'] ?? 0).'"></div>';
    echo '<div class="c3"><label>Imagem do modelo</label><input type="file" name="template_image" id="osDamageImageInput" accept="image/png,image/jpeg,image/webp"><div class="hint">PNG, JPG ou WEBP. Máximo 10 MB.</div></div>';
    echo '<div class="c12"><div class="os-image-preview-panel"><strong>Prévia do desenho</strong><div class="os-image-preview-canvas"><img id="osDamagePreview" src="'.os_e($damageTemplateEdit ? (string)$damageTemplateEdit['asset'] : '').'" alt="Pré-visualização do modelo" style="'.($damageTemplateEdit ? '' : 'display:none').'" draggable="false"><span id="osDamageEmpty" style="'.($damageTemplateEdit ? 'display:none' : '').'">Selecione uma imagem para visualizar antes de cadastrar.</span></div><small id="osDamageDimHint">Recomendado: 1200 × 900 px, preferencialmente 4:3. Aceita outras proporções.</small></div></div>';
    echo '<div class="c12"><label class="os-config-toggle" style="min-height:auto"><input type="checkbox" name="active" value="1" '.((!isset($damageTemplateEdit['active']) || !empty($damageTemplateEdit['active']))?'checked':'').'><span class="os-config-toggle-text"><strong>Modelo ativo</strong><small>Disponibilizar este desenho na seleção da O.S.</small></span></label></div>';
    echo '<div class="c12"><div class="inline" style="gap:8px;flex-wrap:wrap">';
    echo '<button class="btn btn-primary">'.($damageTemplateEdit ? 'Salvar modelo' : 'Cadastrar modelo').'</button>';
    if ($damageTemplateEdit) echo '<a class="btn btn-outline" href="os_configuracoes.php?tab=geral">Cancelar edição</a>';
    echo '</div></div>';
    echo '</div></form>';
    echo '<div style="margin-top:18px">';
    echo '<table class="table"><thead><tr><th>Modelo</th><th>Categoria</th><th>Código</th><th>Prévia</th><th>Ordem</th><th>Status</th><th style="width:210px">Ações</th></tr></thead><tbody>';
    if (!$damageTemplates) {
      echo '<tr><td colspan="7" class="muted">Nenhum modelo personalizado cadastrado ainda.</td></tr>';
    } else {
      foreach($damageTemplates as $templateKey=>$templateMeta){
        echo '<tr>';
        echo '<td>'.os_e($templateMeta['label']).'</td>';
        echo '<td>'.os_e($templateMeta['category'] ?? 'Outros').'</td>';
        echo '<td>'.os_e($templateMeta['code']).'</td>';
        echo '<td><a class="btn btn-outline btn-sm" target="_blank" href="'.os_e($templateMeta['asset']).'">Ver imagem</a></td>';
        echo '<td>'.(int)$templateMeta['sort_order'].'</td>';
        echo '<td>'.(!empty($templateMeta['active']) ? 'Ativo' : 'Inativo').'</td>';
        echo '<td><div class="inline" style="gap:6px;flex-wrap:wrap"><a class="btn btn-outline btn-sm" href="os_configuracoes.php?tab=geral&damage_template_id='.(int)$templateMeta['id'].'">Editar</a>';
        echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="duplicate_damage_template"><input type="hidden" name="template_csrf" value="'.os_e($templateCsrf).'"><input type="hidden" name="origin_mode" value="'.os_e($templateKey).'"><button class="btn btn-secondary btn-sm">Duplicar</button></form>';
        if (!empty($templateMeta['active'])) echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="disable_damage_template"><input type="hidden" name="template_csrf" value="'.os_e($templateCsrf).'"><input type="hidden" name="template_id" value="'.(int)$templateMeta['id'].'"><button class="btn btn-danger btn-sm" onclick="return confirm(\'Desativar este modelo personalizado?\')">Desativar</button></form>';
        echo '</div></td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table></div><div class="inline" style="gap:7px;flex-wrap:wrap;margin-top:12px"><span class="muted">Duplicar modelo padrão:</span>';
    foreach (array('car'=>'Carro','motorcycle'=>'Moto','computer'=>'Computador') as $nativeCode => $nativeLabel) {
      echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="duplicate_damage_template"><input type="hidden" name="template_csrf" value="'.os_e($templateCsrf).'"><input type="hidden" name="origin_mode" value="'.os_e($nativeCode).'"><button class="btn btn-outline btn-sm">'.os_e($nativeLabel).'</button></form>';
    }
    echo '</div></div></div>';
    echo '<script>(function(){var inp=document.getElementById("osDamageImageInput"),img=document.getElementById("osDamagePreview"),empty=document.getElementById("osDamageEmpty"),msg=document.getElementById("osDamageDimHint");if(!inp||!img||!empty||!msg)return;var currentUrl=null;inp.addEventListener("change",function(){if(currentUrl){URL.revokeObjectURL(currentUrl);currentUrl=null;}var f=inp.files&&inp.files[0];if(!f){img.style.display="none";empty.style.display="";msg.textContent="Recomendado: 1200 × 900 px (4:3).";return;}if(f.size>10485760){msg.textContent="Imagem maior que 10 MB. Escolha um arquivo menor.";img.style.display="none";empty.style.display="";return;}currentUrl=URL.createObjectURL(f);img.onload=function(){var w=img.naturalWidth,h=img.naturalHeight,r=w/Math.max(h,1),warn=w<900||h<600||r<0.6||r>2.3;msg.textContent=w+" × "+h+" px — "+(warn?"Atenção: prefira no mínimo 900 × 600 px e desenho bem distribuído para facilitar as marcações.":"Boa resolução para marcação de avarias.");img.style.display="block";empty.style.display="none";};img.onerror=function(){msg.textContent="Não foi possível pré-visualizar esta imagem.";img.style.display="none";empty.style.display="";};img.src=currentUrl;});})();</script>';
    echo '<div class="card"><div class="card-head">Mensagens para envio da O.S. impressa</div><div class="card-body"><div class="alert" style="margin-bottom:12px;background:#eff6ff;border-color:#bfdbfe;color:#1e40af">Os botões WhatsApp e E-mail da impressão usam os modelos do Nexa. Cadastre aqui o texto que acompanhará o PDF da Ordem de Serviço.</div><div class="inline" style="gap:8px;flex-wrap:wrap"><a class="btn btn-success" href="whatsapp_modelos.php?new=1&channel=whatsapp&context=ordem_servico">Mensagem WhatsApp</a><a class="btn btn-secondary" href="whatsapp_modelos.php?new=1&channel=email&context=ordem_servico">Mensagem E-mail</a><a class="btn btn-outline" href="whatsapp_modelos.php">Ver modelos</a></div></div></div>';
}

if($tab==='status'){
    $es=$editStatus;
    $statusTitle=$es?'Editar status':'Cadastrar status';
    $statusButton=$es?'Salvar alterações':'Adicionar status';
    $statusName=$es?(string)$es['name']:'';
    $statusCode=$es?(string)$es['code']:'';
    $statusColor=$es?(string)$es['color']:'#64748b';
    $statusSort=$es?(int)$es['sort_order']:50;
    $statusInitial=$es?(int)$es['is_initial']:0;
    $statusFinal=$es?(int)$es['is_final']:0;
    $statusBatch=$es?!empty($es['allow_batch_billing']):false;
    $statusBilled=$es?!empty($es['is_billed_status']):false;
    $statusActive=$es?(int)$es['active']:1;
    $statusMessage=$es?os_status_customer_message_template($pdo,$companyId,(int)$es['id'],(string)$es['code'],(string)$es['name']):'';
    $statusNotify=$es?os_status_notify_enabled($pdo,$companyId,(int)$es['id']):false;

    echo '<div class="os-status-help"><div><strong>Inicial</strong><br>É o status padrão usado automaticamente ao criar uma nova O.S. Somente um status ativo deve ser o inicial; ao marcar outro, o sistema remove a marcação anterior.</div><div><strong>Final</strong><br>Indica que a O.S. foi encerrada. Ao entrar em um status final, o sistema grava a data/hora de fechamento. Se voltar para um status não final, a O.S. é reaberta. <b>Final não significa necessariamente faturada.</b></div></div>';
    echo '<div class="alert" style="margin-bottom:14px"><b>Faturamento em lote:</b> marque <b>Faturamento em lote</b> nos status que liberam a seleção (ex.: <b>A Faturar</b>) e marque exatamente um status como <b>Status Faturado</b> (ex.: <b>Faturado</b>). Ao gerar o Pedido consolidado, as O.S. da fatura são movidas automaticamente para esse status.</div>';
    echo '<div class="card"><div class="card-head"><span class="'.($es?'os-status-editing':'').'">'.os_e($statusTitle).'</span></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="save_status"><input type="hidden" name="id" value="'.($es?(int)$es['id']:0).'"><div class="grid">';
    echo '<div class="c4"><label>Nome</label><input name="name" required value="'.os_e($statusName).'" placeholder="Ex.: A Faturar"></div>';
    echo '<div class="c3"><label>Código</label><input name="code" value="'.os_e($statusCode).'" placeholder="automático"><div class="hint">Usado internamente. Evite alterar o código de status já integrados.</div></div>';
    echo '<div class="c2"><label>Cor</label><input name="color" type="color" value="'.os_e($statusColor).'" style="height:39px;padding:4px"></div>';
    echo '<div class="c2"><label>Ordem</label><input name="sort_order" type="number" value="'.(int)$statusSort.'"><div class="hint">Menor número aparece primeiro.</div></div>';
    echo '<div class="c12"><div class="os-status-flags">';
    echo '<label class="os-status-flag"><input type="checkbox" name="is_initial" '.($statusInitial?'checked':'').'><span><b>Inicial</b><small>Status padrão de uma nova O.S.</small></span></label>';
    echo '<label class="os-status-flag"><input type="checkbox" name="is_final" '.($statusFinal?'checked':'').'><span><b>Final</b><small>Encerra a O.S. e grava o fechamento.</small></span></label>';
    echo '<label class="os-status-flag"><input type="checkbox" name="allow_batch_billing" '.($statusBatch?'checked':'').'><span><b>Faturamento em lote</b><small>Libera O.S. nesse status para seleção em lote.</small></span></label>';
    echo '<label class="os-status-flag"><input type="checkbox" name="is_billed_status" '.($statusBilled?'checked':'').'><span><b>Status Faturado</b><small>Destino automático após gerar o Pedido do lote.</small></span></label>';
    echo '<label class="os-status-flag"><input type="checkbox" name="active" '.($statusActive?'checked':'').'><span><b>Ativo</b><small>Disponibiliza o status no fluxo da O.S.</small></span></label>';
    echo '</div></div>';
    echo '<div class="c12"><label class="os-status-flag" style="min-height:0;margin-bottom:10px"><input type="checkbox" name="notify_whatsapp_status" '.($statusNotify?'checked':'').'><span><b>Perguntar se deseja avisar o cliente por WhatsApp ao entrar neste status</b><small>Somente após alterar o status de uma O.S. existente. Nunca envia automaticamente.</small></span></label></div>';
    echo '<div class="c12"><label>Mensagem ao cliente ao entrar neste status</label><textarea name="customer_message" rows="4" placeholder="Ex.: Olá, {{cliente}}! Sua Ordem de Serviço #{{os}} foi autorizada...">'.os_e($statusMessage).'</textarea><div class="hint">Variáveis: <b>{{cliente}}</b>, <b>{{os}}</b>, <b>{{status}}</b>, <b>{{previsao}}</b>, <b>{{previsao_linha}}</b> e <b>{{empresa}}</b>. Se deixar vazio, o Nexa usa uma mensagem padrão para o status.</div></div>';
    echo '<div class="c12 inline" style="gap:8px;flex-wrap:wrap"><button class="btn btn-primary">'.os_e($statusButton).'</button>'; if($es) echo '<a class="btn btn-secondary" href="os_configuracoes.php?tab=status">Cancelar edição</a>'; echo '</div>';
    echo '</div></form></div></div>';

    echo '<div class="card"><div class="card-head">Status cadastrados</div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Ordem</th><th>Status</th><th>Código</th><th>Características</th><th>Faturamento em lote</th><th>Status Faturado</th><th>Ativo</th><th>Ações</th></tr></thead><tbody>';
    foreach($statuses as $s){
        $batch=!empty($s['allow_batch_billing']);
        $billed=!empty($s['is_billed_status']);
        $types=[];
        if((int)$s['is_initial']) $types[]='<span class="badge" style="background:#dbeafe;color:#1d4ed8">Inicial</span>';
        if((int)$s['is_final']) $types[]='<span class="badge" style="background:#dcfce7;color:#166534">Final</span>';
        if(os_status_notify_enabled($pdo,$companyId,(int)$s['id']))$types[]='<span class="badge" style="background:#dcfce7;color:#166534">WhatsApp ao alterar</span>';
        if(!$types) $types[]='<span class="muted">Fluxo</span>';
        echo '<tr><td>'.(int)$s['sort_order'].'</td><td><span class="badge" style="background:'.os_e($s['color']).'22;color:'.os_e($s['color']).'">'.os_e($s['name']).'</span></td><td>'.os_e($s['code']).'</td><td><div class="os-status-type-badges">'.implode('',$types).'</div></td><td>'.($batch?'<span class="badge" style="background:#dcfce7;color:#166534">Sim</span>':'<span class="muted">Não</span>').'</td><td>'.($billed?'<span class="badge" style="background:#dbeafe;color:#1d4ed8">Faturado</span>':'<span class="muted">—</span>').'</td><td>'.((int)$s['active']?'<span class="badge" style="background:#ecfdf5;color:#047857">Sim</span>':'<span class="badge" style="background:#f1f5f9;color:#64748b">Não</span>').'</td><td><div class="os-status-actions">';
        echo '<a class="btn btn-outline btn-sm" href="?tab=status&edit_status='.(int)$s['id'].'">Editar</a>';
        if((int)$s['active']){
            echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="set_batch_billing_status"><input type="hidden" name="id" value="'.(int)$s['id'].'"><input type="hidden" name="enabled" value="'.($batch?0:1).'"><button class="btn '.($batch?'btn-secondary':'btn-primary').' btn-sm">'.($batch?'Remover do lote':'Usar no lote').'</button></form>';
            echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="set_billed_status"><input type="hidden" name="id" value="'.(int)$s['id'].'"><input type="hidden" name="enabled" value="'.($billed?0:1).'"><button class="btn '.($billed?'btn-secondary':'btn-primary').' btn-sm">'.($billed?'Remover Faturado':'Definir Faturado').'</button></form>';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Desativar este status?\')"><input type="hidden" name="action" value="disable_status"><input type="hidden" name="id" value="'.(int)$s['id'].'"><button class="btn btn-danger btn-sm">Desativar</button></form>';
        }
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></div>';
}

if($tab==='campos'){
    $ef=$editField;
    $formTitle=$ef?'Editar campo personalizado':'Novo campo personalizado';
    $btnTitle=$ef?'Salvar alterações':'Adicionar campo';
    $labelVal=$ef?$ef['label']:'';
    $keyVal=$ef?$ef['field_key']:'';
    $typeVal=$ef?$ef['input_type']:'text';
    $semanticVal=$ef && isset($ef['semantic_role']) ? (string)$ef['semantic_role'] : '';
    $sortVal=$ef?(int)$ef['sort_order']:10;
    $placeholderVal=$ef?$ef['placeholder']:'';
    $requiredVal=$ef?(int)$ef['required']:0;
    $quickVal=$ef?(int)$ef['allow_quick_add']:0;
    $printVal=$ef?(int)$ef['show_on_print']:1;
    $activeVal=$ef?(int)$ef['active']:1;

    echo '<div class="card"><div class="card-head">'.os_e($formTitle).'</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="save_field"><input type="hidden" name="id" value="'.($ef?(int)$ef['id']:0).'"><div class="grid">';
    echo '<div class="c4"><label>Nome do campo</label><input name="label" required value="'.os_e($labelVal).'" placeholder="Ex.: Placa do veículo"></div><div class="c3"><label>Chave interna</label><input name="field_key" value="'.os_e($keyVal).'" placeholder="automática"></div><div class="c3"><label>Tipo</label><select name="input_type">';
    foreach(array('text'=>'Texto','textarea'=>'Texto longo','number'=>'Número','date'=>'Data','select'=>'Seleção / cadastro','checkbox'=>'Sim/Não','password'=>'Senha / segredo') as $tv=>$tl) echo '<option value="'.$tv.'" '.($typeVal===$tv?'selected':'').'>'.$tl.'</option>';
    echo '</select></div><div class="c2"><label>Ordem</label><input name="sort_order" type="number" value="'.(int)$sortVal.'"><div class="hint">Menor número aparece primeiro.</div></div>';
    if($vehicleFeatureReady){
        echo '<div class="c6"><label>Identificação do campo</label><select name="semantic_role">';
        foreach($semanticRoles as $rv=>$rl) echo '<option value="'.os_e($rv).'" '.($semanticVal===$rv?'selected':'').'>'.os_e($rl).'</option>';
        echo '</select><div class="hint">Identifique Placa, Marca, Modelo, Ano, Cor etc. Isso permite ao Nexa localizar um veículo pela placa e preencher os demais campos automaticamente.</div></div>';
    } else {
        echo '<div class="c6"><label>Identificação do campo</label><div class="alert alert-error" style="margin:0">Execute o SQL da atualização da O.S. para habilitar a identificação dos campos de veículo.</div></div>';
    }
    echo '<div class="c6"><label>Texto de ajuda / placeholder</label><input name="placeholder" value="'.os_e($placeholderVal).'" placeholder="Ex.: AAA0A00"></div><div class="c6 inline"><label style="margin:0;font-weight:normal"><input style="width:auto" type="checkbox" name="required" '.($requiredVal?'checked':'').'> Obrigatório</label><label style="margin:0;font-weight:normal"><input style="width:auto" type="checkbox" name="allow_quick_add" '.($quickVal?'checked':'').'> Permitir cadastro rápido (seleção)</label><label style="margin:0;font-weight:normal"><input style="width:auto" type="checkbox" name="show_on_print" '.($printVal?'checked':'').'> Imprimir</label><label style="margin:0;font-weight:normal"><input style="width:auto" type="checkbox" name="active" '.($activeVal?'checked':'').'> Ativo</label></div>';
    echo '<div class="c12 inline"><button class="btn btn-primary">'.os_e($btnTitle).'</button>'; if($ef) echo '<a class="btn btn-secondary" href="os_configuracoes.php?tab=campos">Cancelar edição</a>'; echo '</div></div></form></div></div>';

    echo '<div class="card"><div class="card-head">Campos cadastrados</div><div class="card-body"><div class="hint" style="margin-bottom:12px">Use Editar para alterar nome, tipo, ajuda, obrigatoriedade e ordem. Os botões ↑ e ↓ reposicionam rapidamente o campo na O.S.</div>';
    if(!$fields) echo '<div class="muted">Nenhum campo personalizado cadastrado.</div>';
    foreach($fields as $f){
        echo '<div style="border:1px solid var(--os-border-soft);border-radius:10px;padding:12px;margin-bottom:10px"><div class="grid"><div class="c8"><b>'.os_e($f['label']).'</b> <span class="muted small">('.os_e($f['input_type']).')</span><div class="small" style="margin-top:5px"><b>Ordem:</b> '.(int)$f['sort_order'].' &nbsp; <b>Chave:</b> '.os_e($f['field_key']).' &nbsp; <b>Identificação:</b> '.os_e((isset($f['semantic_role']) && $f['semantic_role']!=='' && isset($semanticRoles[$f['semantic_role']]))?$semanticRoles[$f['semantic_role']]:'Genérico').' &nbsp; <b>Obrigatório:</b> '.((int)$f['required']?'Sim':'Não').' &nbsp; <b>Imprime:</b> '.((int)$f['show_on_print']?'Sim':'Não').' &nbsp; <b>Ativo:</b> '.((int)$f['active']?'Sim':'Não').'</div>'; if($f['placeholder']) echo '<div class="hint">Ajuda: '.os_e($f['placeholder']).'</div>'; echo '</div><div class="c4"><div class="inline" style="justify-content:flex-end;flex-wrap:wrap"><a class="btn btn-outline btn-sm" href="?tab=campos&edit_field='.(int)$f['id'].'">Editar</a><form method="post" style="display:inline"><input type="hidden" name="action" value="move_field"><input type="hidden" name="id" value="'.(int)$f['id'].'"><input type="hidden" name="direction" value="up"><button class="btn btn-secondary btn-sm" title="Mover para cima">↑</button></form><form method="post" style="display:inline"><input type="hidden" name="action" value="move_field"><input type="hidden" name="id" value="'.(int)$f['id'].'"><input type="hidden" name="direction" value="down"><button class="btn btn-secondary btn-sm" title="Mover para baixo">↓</button></form>';
        if((int)$f['active']) echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Desativar este campo?\')"><input type="hidden" name="action" value="disable_field"><input type="hidden" name="id" value="'.(int)$f['id'].'"><button class="btn btn-danger btn-sm">Desativar</button></form>';
        echo '</div></div></div>';
        if($f['input_type']==='select'){
            echo '<hr style="border:0;border-top:1px solid #e6ecee;margin:14px 0"><form method="post"><input type="hidden" name="action" value="add_option"><input type="hidden" name="field_id" value="'.(int)$f['id'].'"><div class="grid"><div class="c5"><label>Nova opção</label><input name="label" required placeholder="Ex.: Notebook"></div><div class="c4"><label>Valor interno</label><input name="option_value" placeholder="automático"></div><div class="c2"><label>Ordem</label><input name="sort_order" type="number" value="10"></div><div class="c12"><button class="btn btn-secondary btn-sm">Adicionar opção</button></div></div></form>';
            if(isset($optionsByField[$f['id']])){ echo '<div class="table-wrap" style="margin-top:10px"><table class="table"><thead><tr><th>Ordem</th><th>Opção</th><th>Valor</th><th>Ativa</th><th></th></tr></thead><tbody>'; foreach($optionsByField[$f['id']] as $o){ echo '<tr><td>'.(int)$o['sort_order'].'</td><td>'.os_e($o['label']).'</td><td>'.os_e($o['option_value']).'</td><td>'.((int)$o['active']?'Sim':'Não').'</td><td>'; if((int)$o['active'])echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="disable_option"><input type="hidden" name="id" value="'.(int)$o['id'].'"><button class="btn btn-danger btn-sm">Desativar</button></form>'; echo '</td></tr>'; } echo '</tbody></table></div>'; }
        }
        echo '</div>';
    }
    echo '</div></div>';
}
os_page_end();
