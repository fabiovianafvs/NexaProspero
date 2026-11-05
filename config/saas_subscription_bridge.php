<?php
declare(strict_types=1);

/**
 * V11.52 — Fundação da Gestão de Assinaturas SaaS.
 *
 * Responsabilidade desta camada:
 * - ligar a empresa SaaS a UMA empresa faturadora do Admin;
 * - ligar módulos SaaS a produtos/serviços do Admin;
 * - criar/atualizar a Pessoa e o Contrato de TESTE;
 * - registrar snapshots, mudanças futuras e eventos idempotentes.
 *
 * Fora do escopo da V11.52:
 * - gerar Pedido, Contas a Receber, boleto, PIX ou NFS-e automaticamente;
 * - liberar Produção a partir de pagamento;
 * - alterar cobranças já geradas.
 */

require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/contracts_module.php';
require_once __DIR__ . '/finance_module.php';

if (!function_exists('saas_sub_bridge_table_exists')) {
  function saas_sub_bridge_table_exists(PDO $pdo, string $table): bool {
    try {
      $st=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
      $st->execute([$table]);
      $ok=(bool)$st->fetchColumn();
      $st->closeCursor();
      return $ok;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('saas_sub_bridge_column_exists')) {
  function saas_sub_bridge_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
      $st->execute([$table,$column]);
      $ok=(bool)$st->fetchColumn();
      $st->closeCursor();
      return $ok;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('saas_sub_bridge_sync_subscription_dates')) {
  function saas_sub_bridge_sync_subscription_dates(PDO $pdo, int $subscriptionId, int $saasCompanyId, DateTimeImmutable $periodEnd, DateTimeImmutable $nextDue): void {
    if ($subscriptionId<=0 || $saasCompanyId<=0 || !saas_sub_bridge_table_exists($pdo,'subscriptions')) return;
    $set=['ends_at=?','next_billing_at=?'];
    $params=[$periodEnd->format('Y-m-d 23:59:59'),$nextDue->format('Y-m-d 09:00:00')];
    if (saas_sub_bridge_column_exists($pdo,'subscriptions','updated_at')) $set[]='updated_at=NOW()';
    $params[]=$subscriptionId;$params[]=$saasCompanyId;
    $pdo->prepare('UPDATE subscriptions SET '.implode(',',$set).' WHERE id=? AND company_id=?')->execute($params);
  }
}

if (!function_exists('saas_sub_bridge_schema_ready')) {
  function saas_sub_bridge_schema_ready(PDO $pdo): bool {
    foreach (['saas_subscription_bridge_config','saas_subscription_module_map','saas_subscription_links','saas_subscription_items','saas_subscription_changes','saas_billing_events'] as $t) {
      if (!saas_sub_bridge_table_exists($pdo,$t)) return false;
    }
    return true;
  }
}

if (!function_exists('saas_sub_bridge_adjustment_ready')) {
  function saas_sub_bridge_adjustment_ready(PDO $pdo): bool {
    return saas_sub_bridge_schema_ready($pdo)
      && saas_sub_bridge_column_exists($pdo,'saas_subscription_links','next_adjustment_date');
  }
}

if (!function_exists('saas_sub_bridge_config')) {
  function saas_sub_bridge_config(PDO $pdo): array {
    if (!saas_sub_bridge_schema_ready($pdo)) return [
      'id'=>1,'enabled'=>0,'billing_company_id'=>0,'trial_days'=>7,'charge_lead_days'=>2,'grace_days'=>3,
      'default_payment_method_id'=>0,'default_bank_account_id'=>0,'default_chart_account_id'=>0,'default_cost_center_id'=>0,
    ];
    $st=$pdo->query('SELECT * FROM saas_subscription_bridge_config WHERE id=1 LIMIT 1');
    $row=$st?($st->fetch(PDO::FETCH_ASSOC)?:[]):[];
    if ($st) $st->closeCursor();
    return array_merge([
      'id'=>1,'enabled'=>0,'billing_company_id'=>0,'trial_days'=>7,'charge_lead_days'=>2,'grace_days'=>3,
      'default_payment_method_id'=>0,'default_bank_account_id'=>0,'default_chart_account_id'=>0,'default_cost_center_id'=>0,
    ],$row);
  }
}

if (!function_exists('saas_sub_bridge_enabled')) {
  function saas_sub_bridge_enabled(PDO $pdo): bool {
    $cfg=saas_sub_bridge_config($pdo);
    return saas_sub_bridge_schema_ready($pdo) && (int)($cfg['enabled']??0)===1 && (int)($cfg['billing_company_id']??0)>0;
  }
}

if (!function_exists('saas_sub_bridge_billing_company')) {
  function saas_sub_bridge_billing_company(PDO $pdo, ?array $cfg=null): ?array {
    $cfg=$cfg??saas_sub_bridge_config($pdo);
    $id=(int)($cfg['billing_company_id']??0);
    if ($id<=0) return null;
    $st=$pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $r=$st->fetch(PDO::FETCH_ASSOC)?:null;
    $st->closeCursor();
    return $r;
  }
}

if (!function_exists('saas_sub_bridge_assert_billing_company')) {
  function saas_sub_bridge_assert_billing_company(PDO $pdo, int $companyId): array {
    $cfg=saas_sub_bridge_config($pdo);
    $configured=(int)($cfg['billing_company_id']??0);
    if ($configured<=0) throw new RuntimeException('A empresa faturadora da integração SaaS ainda não foi configurada.');
    if ($companyId!==$configured) throw new RuntimeException('Operação recusada: esta integração é exclusiva da empresa faturadora configurada.');
    $company=saas_sub_bridge_billing_company($pdo,$cfg);
    if (!$company) throw new RuntimeException('A empresa faturadora configurada não foi localizada.');
    return $company;
  }
}

if (!function_exists('saas_sub_bridge_event')) {
  function saas_sub_bridge_event(PDO $pdo, string $eventKey, string $eventType, ?int $saasCompanyId, ?int $linkId, ?int $billingCompanyId, string $originType='', ?int $originId=null, array $payload=[], bool $processed=true, ?string $error=null): void {
    if (!saas_sub_bridge_table_exists($pdo,'saas_billing_events')) return;
    $sql='INSERT INTO saas_billing_events(event_key,event_type,saas_company_id,link_id,billing_company_id,origin_type,origin_id,payload_json,processed,processed_at,error_message,created_at) VALUES(?,?,?,?,?,?,?,?,?,'.($processed?'NOW()':'NULL').',?,NOW()) ON DUPLICATE KEY UPDATE event_type=VALUES(event_type),link_id=VALUES(link_id),billing_company_id=VALUES(billing_company_id),payload_json=VALUES(payload_json),processed=VALUES(processed),processed_at=VALUES(processed_at),error_message=VALUES(error_message)';
    $pdo->prepare($sql)->execute([
      substr($eventKey,0,120),substr($eventType,0,50),$saasCompanyId?:null,$linkId?:null,$billingCompanyId?:null,$originType!==''?substr($originType,0,40):null,$originId,
      $payload?json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$processed?1:0,$error!==null?substr($error,0,1000):null,
    ]);
  }
}

if (!function_exists('saas_sub_bridge_latest_subscription')) {
  function saas_sub_bridge_latest_subscription(PDO $pdo, int $saasCompanyId): ?array {
    $st=$pdo->prepare('SELECT s.*,p.name AS plan_name,p.price AS plan_price,p.trial_days,p.billing_days FROM subscriptions s LEFT JOIN plans p ON p.id=s.plan_id WHERE s.company_id=? ORDER BY s.id DESC LIMIT 1');
    $st->execute([$saasCompanyId]);
    $r=$st->fetch(PDO::FETCH_ASSOC)?:null;
    $st->closeCursor();
    return $r;
  }
}

if (!function_exists('saas_sub_bridge_company')) {
  function saas_sub_bridge_company(PDO $pdo, int $saasCompanyId): ?array {
    $st=$pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
    $st->execute([$saasCompanyId]);
    $r=$st->fetch(PDO::FETCH_ASSOC)?:null;
    $st->closeCursor();
    return $r;
  }
}

if (!function_exists('saas_sub_bridge_link')) {
  function saas_sub_bridge_link(PDO $pdo, int $saasCompanyId): ?array {
    if (!saas_sub_bridge_table_exists($pdo,'saas_subscription_links')) return null;
    $st=$pdo->prepare('SELECT * FROM saas_subscription_links WHERE saas_company_id=? LIMIT 1');
    $st->execute([$saasCompanyId]);
    $r=$st->fetch(PDO::FETCH_ASSOC)?:null;
    $st->closeCursor();
    return $r;
  }
}

if (!function_exists('saas_sub_bridge_module_map')) {
  function saas_sub_bridge_module_map(PDO $pdo, int $billingCompanyId): array {
    if (!saas_sub_bridge_table_exists($pdo,'saas_subscription_module_map')) return [];
    $st=$pdo->prepare('SELECT mm.*,m.code,m.name AS module_name,COALESCE(m.allow_quantity,0) AS allow_quantity,COALESCE(m.quantity_label,\'unidade\') AS quantity_label,COALESCE(m.quantity_min,1) AS quantity_min,COALESCE(m.quantity_max,99) AS quantity_max,COALESCE(m.quantity_default,1) AS quantity_default,p.nome AS product_name,p.preco AS product_price,COALESCE(p.is_service,0) AS is_service FROM saas_subscription_module_map mm INNER JOIN saas_modules m ON m.id=mm.saas_module_id INNER JOIN produtos p ON p.company_id=mm.billing_company_id AND p.id=mm.product_id WHERE mm.billing_company_id=? AND mm.active=1 ORDER BY m.sort_order,m.name');
    $st->execute([$billingCompanyId]);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r) $out[(int)$r['saas_module_id']]=$r;
    $st->closeCursor();
    return $out;
  }
}

if (!function_exists('saas_sub_bridge_enabled_billable_modules')) {
  function saas_sub_bridge_enabled_billable_modules(PDO $pdo, int $saasCompanyId, int $billingCompanyId): array {
    $map=saas_sub_bridge_module_map($pdo,$billingCompanyId);
    if (!$map) return [];
    $out=[];
    foreach(saas_company_module_state($pdo,$saasCompanyId) as $m){
      $mid=(int)($m['id']??0);
      if ($mid<=0 || empty($m['enabled']) || !isset($map[$mid])) continue;
      $row=$map[$mid];
      if ((int)($row['is_service']??0)!==1) continue;
      $row['quantity']=max(1.0,(float)($m['quantity']??1));
      $row['module_code']=(string)($m['code']??$row['code']??'');
      $row['module_name']=(string)($m['name']??$row['module_name']??'');
      $out[]=$row;
    }
    return $out;
  }
}

if (!function_exists('saas_sub_bridge_billing_policy_ready')) {
  function saas_sub_bridge_billing_policy_ready(PDO $pdo): bool {
    return saas_sub_bridge_table_exists($pdo,'saas_subscription_billing_policy');
  }
}


if (!function_exists('saas_sub_bridge_commercial_policy_ready')) {
  function saas_sub_bridge_commercial_policy_ready(PDO $pdo): bool {
    if(!saas_sub_bridge_billing_policy_ready($pdo) || !saas_sub_bridge_table_exists($pdo,'saas_subscription_term_options')) return false;
    try{
      $need=[['saas_subscription_billing_policy','billing_quantity'],['saas_subscription_links','renewal_term_months'],['saas_subscription_links','renewal_discount_percent']];
      $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
      foreach($need as [$t,$c]){$st->execute([$t,$c]);$ok=(int)$st->fetchColumn();$st->closeCursor();if($ok<=0)return false;}
      return true;
    }catch(Throwable $e){return false;}
  }
}

if (!function_exists('saas_sub_bridge_term_options')) {
  function saas_sub_bridge_term_options(PDO $pdo,bool $activeOnly=true): array {
    if(!saas_sub_bridge_table_exists($pdo,'saas_subscription_term_options')) return [];
    $sql='SELECT term_months,code,label,discount_percent,active,sort_order FROM saas_subscription_term_options'.($activeOnly?' WHERE active=1':'').' ORDER BY sort_order,term_months';
    $st=$pdo->query($sql);$rows=$st?$st->fetchAll(PDO::FETCH_ASSOC):[];if($st)$st->closeCursor();return $rows?:[];
  }
}

if (!function_exists('saas_sub_bridge_term_option')) {
  function saas_sub_bridge_term_option(PDO $pdo,int $months): ?array {
    if(!saas_sub_bridge_table_exists($pdo,'saas_subscription_term_options')) return null;
    $st=$pdo->prepare('SELECT term_months,code,label,discount_percent,active,sort_order FROM saas_subscription_term_options WHERE term_months=? AND active=1 LIMIT 1');$st->execute([$months]);$r=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();return $r;
  }
}

if (!function_exists('saas_sub_bridge_term_billing_type')) {
  function saas_sub_bridge_term_billing_type(int $months): string { return match($months){3=>'TRIMESTRAL',6=>'SEMESTRAL',12=>'ANUAL',default=>'MENSAL'}; }
}

if (!function_exists('saas_sub_bridge_cycle_values')) {
  function saas_sub_bridge_cycle_values(float $monthlyUnit,float $quantity,int $months,float $discountPercent): array {
    $quantity=max(1.0,$quantity);$months=in_array($months,[1,3,6,12],true)?$months:1;$discountPercent=max(0.0,min(100.0,$discountPercent));
    $cycleUnit=round(max(0.0,$monthlyUnit)*$months,4);$gross=round($cycleUnit*$quantity,2);$discount=round($gross*($discountPercent/100),2);$net=round(max(0,$gross-$discount),2);
    return ['monthly_unit'=>round(max(0.0,$monthlyUnit),4),'cycle_unit'=>$cycleUnit,'quantity'=>$quantity,'months'=>$months,'discount_percent'=>$discountPercent,'gross'=>$gross,'discount'=>$discount,'net'=>$net];
  }
}

if (!function_exists('saas_sub_bridge_billing_policy_map')) {
  function saas_sub_bridge_billing_policy_map(PDO $pdo,int $linkId): array {
    if($linkId<=0 || !saas_sub_bridge_billing_policy_ready($pdo)) return [];
    $qtyCol=saas_sub_bridge_commercial_policy_ready($pdo)?'COALESCE(billing_quantity,1) billing_quantity':'1 billing_quantity';$st=$pdo->prepare('SELECT saas_module_id,billing_mode,'.$qtyCol.',courtesy_note FROM saas_subscription_billing_policy WHERE link_id=?');
    $st->execute([$linkId]);$out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$out[(int)$r['saas_module_id']]=$r;}
    $st->closeCursor();return $out;
  }
}

if (!function_exists('saas_sub_bridge_billing_selection')) {
  function saas_sub_bridge_billing_selection(PDO $pdo,int $linkId,array $modules,array $input=[]): array {
    $byId=[];foreach($modules as $m){$mid=(int)($m['saas_module_id']??0);if($mid>0)$byId[$mid]=$m;}
    $explicit=!empty($input['billing_policy_present']);$selected=[];$policy=saas_sub_bridge_billing_policy_map($pdo,$linkId);$rawQty=(array)($input['billing_quantity']??[]);
    if($explicit){
      if(!saas_sub_bridge_commercial_policy_ready($pdo)) throw new RuntimeException('A migration V11.52.3 ainda não foi aplicada. Aplique-a antes de configurar quantidades e períodos.');
      foreach((array)($input['billable_modules']??[]) as $raw){$mid=(int)$raw;if($mid>0 && isset($byId[$mid]))$selected[$mid]=true;}
    }else{
      if($policy){foreach($byId as $mid=>$m){if(strtoupper((string)($policy[$mid]['billing_mode']??'BILLABLE'))!=='COURTESY')$selected[$mid]=true;}}
      else{foreach($byId as $mid=>$m)$selected[$mid]=true;}
    }
    foreach($byId as $mid=>&$m){
      $base=max(1.0,(float)($m['quantity']??1));
      if($explicit && array_key_exists($mid,$rawQty)){$v=str_replace(',','.',trim((string)$rawQty[$mid]));$q=is_numeric($v)?(float)$v:$base;}
      elseif(isset($policy[$mid]['billing_quantity']))$q=(float)$policy[$mid]['billing_quantity'];else$q=$base;
      $q=max(1.0,min(999.0,$q));$m['quantity']=round($q,2);$m['billing_quantity']=$m['quantity'];
    }unset($m);
    $billable=[];$courtesy=[];
    foreach($byId as $mid=>$m){if(isset($selected[$mid]))$billable[]=$m;else$courtesy[]=$m;}
    return ['all'=>array_values($byId),'billable'=>$billable,'courtesy'=>$courtesy,'selected_ids'=>array_keys($selected),'explicit'=>$explicit];
  }
}

if (!function_exists('saas_sub_bridge_save_billing_policy')) {
  function saas_sub_bridge_save_billing_policy(PDO $pdo,int $linkId,array $allModules,array $selectedIds,array $courtesyNotes=[]): void {
    if($linkId<=0 || !saas_sub_bridge_billing_policy_ready($pdo)) return;
    $selected=[];foreach($selectedIds as $mid)$selected[(int)$mid]=true;
    $hasQty=saas_sub_bridge_commercial_policy_ready($pdo);
    $sql=$hasQty
      ? "INSERT INTO saas_subscription_billing_policy(link_id,saas_module_id,billing_mode,billing_quantity,courtesy_note,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE billing_mode=VALUES(billing_mode),billing_quantity=VALUES(billing_quantity),courtesy_note=VALUES(courtesy_note),updated_at=NOW()"
      : "INSERT INTO saas_subscription_billing_policy(link_id,saas_module_id,billing_mode,courtesy_note,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE billing_mode=VALUES(billing_mode),courtesy_note=VALUES(courtesy_note),updated_at=NOW()";
    $st=$pdo->prepare($sql);
    foreach($allModules as $m){$mid=(int)($m['saas_module_id']??0);if($mid<=0)continue;$billable=isset($selected[$mid]);$note=trim((string)($courtesyNotes[$mid]??''));if($billable)$note='';$safeNote=$note!==''?(function_exists('mb_substr')?mb_substr($note,0,500,'UTF-8'):substr($note,0,500)):null;$q=max(1.0,min(999.0,(float)($m['quantity']??1)));if($hasQty)$st->execute([$linkId,$mid,$billable?'BILLABLE':'COURTESY',$q,$safeNote]);else$st->execute([$linkId,$mid,$billable?'BILLABLE':'COURTESY',$safeNote]);}
  }
}

if (!function_exists('saas_sub_bridge_norm_digits')) {
  function saas_sub_bridge_norm_digits(string $v): string { return preg_replace('/\D+/','',$v)??''; }
}

if (!function_exists('saas_sub_bridge_person_name')) {
  function saas_sub_bridge_person_name(array $company): string {
    foreach(['razao_social','name','nome_fantasia'] as $k){$v=trim((string)($company[$k]??''));if($v!=='')return $v;}
    return 'Cliente SaaS #'.(int)($company['id']??0);
  }
}

if (!function_exists('saas_sub_bridge_find_or_create_person')) {
  function saas_sub_bridge_find_or_create_person(PDO $pdo, int $billingCompanyId, array $company, ?int $linkedPersonId=null): int {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $personId=(int)($linkedPersonId??0);
    if($personId>0){
      $st=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$st->execute([$billingCompanyId,$personId]);$found=(int)$st->fetchColumn();$st->closeCursor();if($found>0)$personId=$found;else$personId=0;
    }
    $document=saas_sub_bridge_norm_digits((string)(($company['person_type']??'pj')==='pf'?($company['cpf']??''):($company['cnpj']??'')));
    if($personId<=0 && $document!==''){
      $norm="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(document,''),'.',''),'/',''),'-',''),'(',''),')',''),' ','')";
      $st=$pdo->prepare("SELECT id FROM finance_people WHERE company_id=? AND {$norm}=? ORDER BY active DESC,id LIMIT 1");$st->execute([$billingCompanyId,$document]);$personId=(int)$st->fetchColumn();$st->closeCursor();
    }
    $email=trim((string)($company['email']??''));
    if($personId<=0 && $email!==''){$st=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND LOWER(TRIM(COALESCE(email,\'\')))=LOWER(?) ORDER BY active DESC,id LIMIT 1');$st->execute([$billingCompanyId,$email]);$personId=(int)$st->fetchColumn();$st->closeCursor();}
    $name=saas_sub_bridge_person_name($company);$fantasy=trim((string)($company['nome_fantasia']??$company['name']??''));$phone=saas_sub_bridge_norm_digits((string)($company['celular']??$company['whatsapp']??''));
    $type=strtolower((string)($company['person_type']??'pj'))==='pf'?'FISICA':'JURIDICA';
    $data=[
      $name,$fantasy!==''?$fantasy:null,$document!==''?$document:null,$type,$email!==''?$email:null,$phone!==''?$phone:null,$phone!==''?$phone:null,
      trim((string)($company['cep']??''))?:null,trim((string)($company['endereco']??''))?:null,trim((string)($company['numero']??''))?:null,
      trim((string)($company['complemento']??''))?:null,trim((string)($company['bairro']??''))?:null,trim((string)($company['cidade']??''))?:null,strtoupper(trim((string)($company['estado']??'')))?:null,
    ];
    if($personId>0){
      $pdo->prepare('UPDATE finance_people SET name=?,fantasy_name=?,document=?,person_type=?,email=?,phone=?,whatsapp=?,zip_code=?,address=?,number=?,complement=?,district=?,city=?,state=?,is_customer=1,active=1,updated_at=NOW() WHERE company_id=? AND id=?')->execute(array_merge($data,[$billingCompanyId,$personId]));
      return $personId;
    }
    $pdo->prepare('INSERT INTO finance_people(company_id,name,fantasy_name,document,person_type,email,phone,whatsapp,zip_code,address,number,complement,district,city,state,is_customer,active,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,?,NOW())')->execute(array_merge([$billingCompanyId],$data,['Criado automaticamente pela integração SaaS V11.52 · Empresa SaaS #'.(int)$company['id']]));
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('saas_sub_bridge_trial_dates')) {
  function saas_sub_bridge_trial_dates(array $subscription, array $cfg): array {
    $startRaw=trim((string)($subscription['started_at']??''));
    try{$start=$startRaw!==''?new DateTimeImmutable($startRaw):new DateTimeImmutable('now');}catch(Throwable $e){$start=new DateTimeImmutable('now');}
    $endRaw=trim((string)($subscription['ends_at']??''));
    if($endRaw!==''){try{$end=new DateTimeImmutable($endRaw);}catch(Throwable $e){$end=$start->modify('+'.max(1,(int)($cfg['trial_days']??7)).' days');}}
    else{$days=max(1,(int)($subscription['trial_days']??$cfg['trial_days']??7));$end=$start->modify('+'.$days.' days');}
    return [$start,$end];
  }
}

if (!function_exists('saas_sub_bridge_product_snapshot')) {
  function saas_sub_bridge_product_snapshot(PDO $pdo, int $billingCompanyId, int $productId): array {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $cols=['id','nome','preco','COALESCE(is_service,0) AS is_service'];
    foreach(['custo','unidade_estoque','numero_cadastro','referencia'] as $c) if(contracts_has_column($pdo,'produtos',$c)) $cols[]=$c;
    $st=$pdo->prepare('SELECT '.implode(',',$cols).' FROM produtos WHERE company_id=? AND id=? AND COALESCE(ativo,1)=1 LIMIT 1');
    $st->execute([$billingCompanyId,$productId]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$st->closeCursor();
    if(!$r || (int)($r['is_service']??0)!==1) throw new RuntimeException('O serviço vinculado ao módulo SaaS não está ativo ou não está marcado como Serviço no Admin.');
    return $r;
  }
}

if (!function_exists('saas_sub_bridge_upsert_link')) {
  function saas_sub_bridge_upsert_link(PDO $pdo,int $saasCompanyId,int $saasSubscriptionId,int $billingCompanyId,int $personId,?int $contractId,string $status,DateTimeImmutable $trialStart,DateTimeImmutable $trialEnd,?string $error=null): int {
    $existing=saas_sub_bridge_link($pdo,$saasCompanyId);
    if($existing){
      $pdo->prepare('UPDATE saas_subscription_links SET saas_subscription_id=?,billing_company_id=?,person_id=?,contract_id=COALESCE(?,contract_id),lifecycle_status=?,trial_started_at=?,trial_ends_at=?,next_due_date=?,last_sync_at=NOW(),last_error=?,updated_at=NOW() WHERE id=?')->execute([
        $saasSubscriptionId?:null,$billingCompanyId,$personId,$contractId,$status,$trialStart->format('Y-m-d H:i:s'),$trialEnd->format('Y-m-d H:i:s'),$trialEnd->format('Y-m-d'),$error,(int)$existing['id']]);
      return (int)$existing['id'];
    }
    $pdo->prepare('INSERT INTO saas_subscription_links(saas_company_id,saas_subscription_id,billing_company_id,person_id,contract_id,lifecycle_status,trial_started_at,trial_ends_at,next_due_date,last_sync_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?,NOW(),NOW())')->execute([
      $saasCompanyId,$saasSubscriptionId?:null,$billingCompanyId,$personId,$contractId,$status,$trialStart->format('Y-m-d H:i:s'),$trialEnd->format('Y-m-d H:i:s'),$trialEnd->format('Y-m-d'),$error]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('saas_sub_bridge_create_contract')) {
  function saas_sub_bridge_create_contract(PDO $pdo,int $billingCompanyId,int $personId,array $company,array $subscription,array $cfg,array $modules): int {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    if(!contracts_schema_ready($pdo)) throw new RuntimeException('O módulo Contratos da empresa faturadora ainda não está com o schema homologado.');
    [$trialStart,$trialEnd]=saas_sub_bridge_trial_dates($subscription,$cfg);
    $number=contracts_next_number($pdo,$billingCompanyId);
    $dueDay=(int)$trialEnd->format('d');$name=saas_sub_bridge_person_name($company);
    $payment=(int)($cfg['default_payment_method_id']??0)?:null;$bank=(int)($cfg['default_bank_account_id']??0)?:null;$chart=(int)($cfg['default_chart_account_id']??0)?:null;$cost=(int)($cfg['default_cost_center_id']??0)?:null;
    $notes='Assinatura SaaS · Empresa #'.(int)$company['id'].' · Trial até '.$trialEnd->format('d/m/Y').'. Cobranças somente após execução do ciclo da assinatura.';
    $pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO contracts(company_id,contract_number,registration_date,status,release_date,block_date,cancellation_date,validity_start,validity_end,contracting_person_id,contracting_person_name,billing_person_id,billing_person_name,chart_account_id,bank_account_id,payment_method_id,due_day,cost_center_id,default_billing_type,billing_mode,notes,total_amount,created_by,updated_by,created_at,updated_at) VALUES(?,?,CURDATE(),'TESTE',NULL,NULL,NULL,?,NULL,?,?,?,?,?,?,?,?,?,'MENSAL','AUTOMATICA',?,0,NULL,NULL,NOW(),NOW())")->execute([
        $billingCompanyId,$number,$trialEnd->format('Y-m-d'),$personId,$name,$personId,$name,$chart,$bank,$payment,$dueDay,$cost,$notes]);
      $contractId=(int)$pdo->lastInsertId();
      $total=0.0;
      $insert=$pdo->prepare("INSERT INTO contract_items(company_id,contract_id,product_id,product_code_snapshot,description_snapshot,unit_snapshot,quantity,billing_type,indefinite_term,validity_start,validity_end,grace_date,next_adjustment_date,price_table_name,cost,table_price,unit_price,discount_percent,discount_amount,addition_percent,addition_amount,total,complementary_info,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'MENSAL',1,?,NULL,NULL,NULL,NULL,?,?,?,0,0,0,0,?,?,'ATIVO',NOW(),NOW())");
      foreach($modules as $m){
        $p=saas_sub_bridge_product_snapshot($pdo,$billingCompanyId,(int)$m['product_id']);$qty=max(1.0,(float)($m['quantity']??1));$unit=round((float)($p['preco']??0),4);$line=round($qty*$unit,2);$costV=round((float)($p['custo']??0),4);$code=(string)($p['numero_cadastro']??$p['referencia']??$p['id']);$unitName=(string)($p['unidade_estoque']??'UN');$info='Módulo SaaS: '.(string)($m['module_code']??$m['code']??'').' (#'.(int)$m['saas_module_id'].')';
        $insert->execute([$billingCompanyId,$contractId,(int)$p['id'],$code,(string)$p['nome'],$unitName,$qty,$trialEnd->format('Y-m-d'),$costV,$unit,$unit,$line,$info]);
        $contractItemId=(int)$pdo->lastInsertId();$total+=$line;
        $m['_contract_item_id']=$contractItemId;
      }
      $pdo->prepare('UPDATE contracts SET total_amount=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([round($total,2),$billingCompanyId,$contractId]);
      $pdo->commit();return $contractId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('saas_sub_bridge_sync_trial_items')) {
  function saas_sub_bridge_sync_trial_items(PDO $pdo,int $linkId,int $billingCompanyId,int $contractId,array $allModules,array $billableModules,DateTimeImmutable $trialEnd,array $courtesyNotes=[]): void {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $st=$pdo->prepare('SELECT status FROM contracts WHERE company_id=? AND id=? LIMIT 1');$st->execute([$billingCompanyId,$contractId]);$status=strtoupper((string)$st->fetchColumn());$st->closeCursor();
    if($status!=='TESTE') return; // Contratos ativos serão tratados pelas alterações agendadas/ciclos futuros.
    $wanted=[];foreach($billableModules as $m){$wanted[(int)$m['saas_module_id']]=$m;}
    $allById=[];foreach($allModules as $m){$allById[(int)$m['saas_module_id']]=$m;}
    $pdo->beginTransaction();
    try{
      $st=$pdo->prepare("SELECT id,product_id,complementary_info FROM contract_items WHERE company_id=? AND contract_id=? AND status='ATIVO'");$st->execute([$billingCompanyId,$contractId]);$contractItems=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();
      $byModule=[];foreach($contractItems as $it){if(preg_match('/\(#(\d+)\)/',(string)($it['complementary_info']??''),$mm))$byModule[(int)$mm[1]]=$it;}
      $total=0.0;
      foreach($wanted as $mid=>$m){$p=saas_sub_bridge_product_snapshot($pdo,$billingCompanyId,(int)$m['product_id']);$qty=max(1.0,(float)($m['quantity']??1));$unit=round((float)($p['preco']??0),4);$line=round($qty*$unit,2);$costV=round((float)($p['custo']??0),4);$code=(string)($p['numero_cadastro']??$p['referencia']??$p['id']);$unitName=(string)($p['unidade_estoque']??'UN');$info='Módulo SaaS: '.(string)($m['module_code']??$m['code']??'').' (#'.$mid.')';
        if(isset($byModule[$mid])){$iid=(int)$byModule[$mid]['id'];$pdo->prepare('UPDATE contract_items SET product_id=?,product_code_snapshot=?,description_snapshot=?,unit_snapshot=?,quantity=?,validity_start=?,cost=?,table_price=?,unit_price=?,total=?,complementary_info=?,updated_at=NOW() WHERE company_id=? AND contract_id=? AND id=?')->execute([(int)$p['id'],$code,(string)$p['nome'],$unitName,$qty,$trialEnd->format('Y-m-d'),$costV,$unit,$unit,$line,$info,$billingCompanyId,$contractId,$iid]);}
        else{$pdo->prepare("INSERT INTO contract_items(company_id,contract_id,product_id,product_code_snapshot,description_snapshot,unit_snapshot,quantity,billing_type,indefinite_term,validity_start,cost,table_price,unit_price,total,complementary_info,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'MENSAL',1,?,?,?,?,?,?,'ATIVO',NOW(),NOW())")->execute([$billingCompanyId,$contractId,(int)$p['id'],$code,(string)$p['nome'],$unitName,$qty,$trialEnd->format('Y-m-d'),$costV,$unit,$unit,$line,$info]);}
        $total+=$line;
      }
      foreach($byModule as $mid=>$it) if(!isset($wanted[$mid])) $pdo->prepare("UPDATE contract_items SET status='CANCELADO',canceled_at=NOW(),complementary_info=CONCAT(COALESCE(complementary_info,''),' | Removido/bonificado durante Trial SaaS'),updated_at=NOW() WHERE company_id=? AND contract_id=? AND id=?")->execute([$billingCompanyId,$contractId,(int)$it['id']]);
      $pdo->prepare("UPDATE contracts SET validity_start=?,due_day=?,billing_mode='AUTOMATICA',total_amount=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$trialEnd->format('Y-m-d'),(int)$trialEnd->format('d'),round($total,2),$billingCompanyId,$contractId]);
      $pdo->prepare('DELETE FROM saas_subscription_items WHERE link_id=?')->execute([$linkId]);
      $itemFind=$pdo->prepare("SELECT id FROM contract_items WHERE company_id=? AND contract_id=? AND status='ATIVO' AND complementary_info LIKE ? ORDER BY id DESC LIMIT 1");
      $itemIns=$pdo->prepare("INSERT INTO saas_subscription_items(link_id,saas_module_id,product_id,quantity,unit_price,status,starts_at,contract_item_id,created_at,updated_at) VALUES(?,?,?,?,?,'TRIAL',?,?,NOW(),NOW())");
      $selected=[];foreach($wanted as $mid=>$m)$selected[]=$mid;
      foreach($allById as $mid=>$m){$p=saas_sub_bridge_product_snapshot($pdo,$billingCompanyId,(int)$m['product_id']);$ci=0;if(isset($wanted[$mid])){$like='%'.'(#'.$mid.')'.'%';$itemFind->execute([$billingCompanyId,$contractId,$like]);$ci=(int)$itemFind->fetchColumn();$itemFind->closeCursor();}$itemIns->execute([$linkId,$mid,(int)$m['product_id'],max(1.0,(float)($m['quantity']??1)),round((float)($p['preco']??0),4),$trialEnd->format('Y-m-d H:i:s'),$ci?:null]);}
      saas_sub_bridge_save_billing_policy($pdo,$linkId,array_values($allById),$selected,$courtesyNotes);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}


if (!function_exists('saas_sub_bridge_parse_date')) {
  function saas_sub_bridge_parse_date(string $value, string $label, bool $required=true): ?DateTimeImmutable {
    $value=trim($value);
    if($value===''){
      if($required) throw new RuntimeException($label.' é obrigatória.');
      return null;
    }
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    $errors=DateTimeImmutable::getLastErrors();
    if(!$d || ($errors!==false && ((int)($errors['warning_count']??0)>0 || (int)($errors['error_count']??0)>0)) || $d->format('Y-m-d')!==$value){
      throw new RuntimeException($label.' inválida.');
    }
    return $d;
  }
}

if (!function_exists('saas_sub_bridge_find_person_suggestion')) {
  function saas_sub_bridge_find_person_suggestion(PDO $pdo,int $billingCompanyId,array $company): ?array {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $document=saas_sub_bridge_norm_digits((string)(strtolower((string)($company['person_type']??'pj'))==='pf'?($company['cpf']??''):($company['cnpj']??'')));
    if($document!==''){
      $norm="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(document,''),'.',''),'/',''),'-',''),'(',''),')',''),' ','')";
      $st=$pdo->prepare("SELECT id,name,document,email,phone,active FROM finance_people WHERE company_id=? AND {$norm}=? ORDER BY active DESC,id LIMIT 1");
      $st->execute([$billingCompanyId,$document]);$r=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();if($r)return$r;
    }
    $email=trim((string)($company['email']??''));
    if($email!==''){
      $st=$pdo->prepare("SELECT id,name,document,email,phone,active FROM finance_people WHERE company_id=? AND LOWER(TRIM(COALESCE(email,'')))=LOWER(?) ORDER BY active DESC,id LIMIT 1");
      $st->execute([$billingCompanyId,$email]);$r=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();if($r)return$r;
    }
    return null;
  }
}

if (!function_exists('saas_sub_bridge_people_search')) {
  function saas_sub_bridge_people_search(PDO $pdo,int $billingCompanyId,string $q='',int $limit=30): array {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $limit=max(1,min(50,$limit));$q=trim($q);$params=[$billingCompanyId];
    $where="company_id=? AND COALESCE(active,1)=1 AND COALESCE(is_customer,0)=1";
    if($q!==''){
      $like='%'.$q.'%';$digits=saas_sub_bridge_norm_digits($q);
      $where.=" AND (name LIKE ? OR fantasy_name LIKE ? OR email LIKE ? OR document LIKE ?";$params=array_merge($params,[$like,$like,$like,$like]);
      if($digits!==''){
        $norm="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(document,''),'.',''),'/',''),'-',''),'(',''),')',''),' ','')";
        $where.=" OR {$norm} LIKE ?";$params[]='%'.$digits.'%';
      }
      $where.=')';
    }
    $st=$pdo->prepare("SELECT id,name,fantasy_name,document,email,phone FROM finance_people WHERE {$where} ORDER BY name,id LIMIT {$limit}");
    $st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();return$rows;
  }
}

if (!function_exists('saas_sub_bridge_contract_search')) {
  function saas_sub_bridge_contract_search(PDO $pdo,int $billingCompanyId,int $personId=0,string $q='',int $limit=30): array {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $limit=max(1,min(50,$limit));$params=[$billingCompanyId];$where="c.company_id=? AND c.status<>'CANCELADO'";
    if($personId>0){$where.=' AND (c.contracting_person_id=? OR c.billing_person_id=?)';$params[]=$personId;$params[]=$personId;}
    $q=trim($q);if($q!==''){$like='%'.$q.'%';$where.=' AND (CAST(c.contract_number AS CHAR) LIKE ? OR c.contracting_person_name LIKE ? OR c.billing_person_name LIKE ?)';$params=array_merge($params,[$like,$like,$like]);}
    $sql="SELECT c.id,c.contract_number,c.status,c.contracting_person_id,c.contracting_person_name,c.billing_person_id,c.billing_person_name,c.due_day,c.total_amount,c.validity_start,c.validity_end,
      (SELECT MIN(ci.next_adjustment_date) FROM contract_items ci WHERE ci.company_id=c.company_id AND ci.contract_id=c.id AND ci.status='ATIVO' AND ci.next_adjustment_date IS NOT NULL) next_adjustment_date,
      (SELECT l.saas_company_id FROM saas_subscription_links l WHERE l.billing_company_id=c.company_id AND l.contract_id=c.id LIMIT 1) linked_saas_company_id
      FROM contracts c WHERE {$where} ORDER BY c.id DESC LIMIT {$limit}";
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();return$rows;
  }
}

if (!function_exists('saas_sub_bridge_contract_billing_state')) {
  function saas_sub_bridge_contract_billing_state(PDO $pdo,int $billingCompanyId,int $contractId): array {
    $out=['generation_count'=>0,'order_count'=>0,'open_receivable_count'=>0,'boleto_count'=>0,'latest_due_date'=>null];
    if($contractId<=0)return$out;
    try{
      $st=$pdo->prepare("SELECT COUNT(*) generation_count,SUM(CASE WHEN COALESCE(order_id,0)>0 THEN 1 ELSE 0 END) order_count,MAX(due_date) latest_due_date FROM contract_generations WHERE company_id=? AND contract_id=?");
      $st->execute([$billingCompanyId,$contractId]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$st->closeCursor();$out['generation_count']=(int)($r['generation_count']??0);$out['order_count']=(int)($r['order_count']??0);$out['latest_due_date']=$r['latest_due_date']??null;
    }catch(Throwable $e){}
    try{
      if(contracts_has_column($pdo,'finance_boleto_charges','contract_id')){
        $st=$pdo->prepare("SELECT COUNT(*) FROM finance_boleto_charges WHERE company_id=? AND contract_id=? AND UPPER(COALESCE(status,'')) NOT IN ('CANCELADO','CANCELLED','BAIXADO')");$st->execute([$billingCompanyId,$contractId]);$out['boleto_count']=(int)$st->fetchColumn();$st->closeCursor();
      }
    }catch(Throwable $e){}
    try{
      if(contracts_has_column($pdo,'finance_receivables','contract_generation_id')){
        $st=$pdo->prepare("SELECT COUNT(*) FROM finance_receivables r INNER JOIN contract_generations g ON g.company_id=r.company_id AND g.id=r.contract_generation_id WHERE g.company_id=? AND g.contract_id=? AND UPPER(COALESCE(r.status,'')) NOT IN ('PAGO','CANCELADO','CANCELLED') AND COALESCE(r.open_amount,0)>0");$st->execute([$billingCompanyId,$contractId]);$out['open_receivable_count']=(int)$st->fetchColumn();$st->closeCursor();
      }
    }catch(Throwable $e){}
    return$out;
  }
}

if (!function_exists('saas_sub_bridge_contract_module_state')) {
  function saas_sub_bridge_contract_module_state(PDO $pdo,int $billingCompanyId,int $contractId,array $wantedModules): array {
    $map=saas_sub_bridge_module_map($pdo,$billingCompanyId);$productToModules=[];
    foreach($map as $mid=>$m){$productToModules[(int)$m['product_id']][]=(int)$mid;}
    $wanted=[];foreach($wantedModules as $m)$wanted[(int)$m['saas_module_id']]=$m;
    $st=$pdo->prepare("SELECT id,product_id,description_snapshot,quantity,unit_price,total,complementary_info,status FROM contract_items WHERE company_id=? AND contract_id=? AND status='ATIVO' ORDER BY id");$st->execute([$billingCompanyId,$contractId]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();
    $managed=[];$unrelated=[];
    foreach($items as $it){
      $mid=0;if(preg_match('/M[oó]dulo SaaS:.*\(#(\d+)\)/iu',(string)($it['complementary_info']??''),$mm))$mid=(int)$mm[1];
      if($mid<=0){$mods=$productToModules[(int)$it['product_id']]??[];if(count($mods)===1)$mid=(int)$mods[0];}
      if($mid>0){if(!isset($managed[$mid]))$managed[$mid]=$it;else$unrelated[]=$it;}else$unrelated[]=$it;
    }
    $missing=[];foreach($wanted as $mid=>$m)if(!isset($managed[$mid]))$missing[$mid]=$m;
    $extra=[];foreach($managed as $mid=>$it)if(!isset($wanted[$mid]))$extra[$mid]=['item'=>$it,'map'=>$map[$mid]??null];
    $changed=[];foreach($wanted as $mid=>$m)if(isset($managed[$mid])){$it=$managed[$mid];$wantQty=max(1.0,(float)($m['quantity']??1));if(abs((float)($it['quantity']??0)-$wantQty)>0.0001 || (int)($it['product_id']??0)!==(int)($m['product_id']??0))$changed[$mid]=['item'=>$it,'wanted'=>$m];}
    return ['wanted'=>$wanted,'managed'=>$managed,'missing'=>$missing,'extra'=>$extra,'changed'=>$changed,'unrelated'=>$unrelated];
  }
}

if (!function_exists('saas_sub_bridge_create_active_contract')) {
  function saas_sub_bridge_create_active_contract(PDO $pdo,int $billingCompanyId,int $personId,array $company,array $cfg,array $modules,DateTimeImmutable $contractStart,DateTimeImmutable $nextDue,int $termMonths=1): int {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    if(!contracts_schema_ready($pdo))throw new RuntimeException('O módulo Contratos da empresa faturadora ainda não está com o schema homologado.');
    $st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? AND COALESCE(active,1)=1 LIMIT 1');$st->execute([$billingCompanyId,$personId]);$person=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();if(!$person)throw new RuntimeException('Pessoa selecionada não pertence à empresa faturadora.');
    $number=contracts_next_number($pdo,$billingCompanyId);$name=(string)($person['name']??saas_sub_bridge_person_name($company));$dueDay=(int)$nextDue->format('d');$billingType=saas_sub_bridge_term_billing_type($termMonths);
    $payment=(int)($cfg['default_payment_method_id']??0)?:null;$bank=(int)($cfg['default_bank_account_id']??0)?:null;$chart=(int)($cfg['default_chart_account_id']??0)?:null;$cost=(int)($cfg['default_cost_center_id']??0)?:null;
    $notes='Assinatura SaaS migrada/vinculada · Empresa SaaS #'.(int)$company['id'].' · Contrato mestre. As cobranças serão geradas por ciclos de renovação.';
    $pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO contracts(company_id,contract_number,registration_date,status,release_date,block_date,cancellation_date,validity_start,validity_end,contracting_person_id,contracting_person_name,billing_person_id,billing_person_name,chart_account_id,bank_account_id,payment_method_id,due_day,cost_center_id,default_billing_type,billing_mode,notes,total_amount,created_by,updated_by,created_at,updated_at) VALUES(?,?,CURDATE(),'ATIVO',CURDATE(),NULL,NULL,?,NULL,?,?,?,?,?,?,?,?,?,?,'AUTOMATICA',?,0,NULL,NULL,NOW(),NOW())")->execute([$billingCompanyId,$number,$contractStart->format('Y-m-d'),$personId,$name,$personId,$name,$chart,$bank,$payment,$dueDay,$cost,$billingType,$notes]);
      $contractId=(int)$pdo->lastInsertId();$pdo->commit();return$contractId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
  }
}

if (!function_exists('saas_sub_bridge_sync_active_contract_items')) {
  function saas_sub_bridge_sync_active_contract_items(PDO $pdo,int $billingCompanyId,int $contractId,array $modules,DateTimeImmutable $nextDue,int $termMonths=1,float $discountPercent=0,?DateTimeImmutable $nextAdjustment=null): array {
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);$state=saas_sub_bridge_contract_module_state($pdo,$billingCompanyId,$contractId,$modules);$wanted=$state['wanted'];$managed=$state['managed'];$billingType=saas_sub_bridge_term_billing_type($termMonths);
    $added=0;$updated=0;$removed=0;
    foreach($wanted as $mid=>$m){
      $p=saas_sub_bridge_product_snapshot($pdo,$billingCompanyId,(int)$m['product_id']);$qty=max(1.0,(float)($m['quantity']??1));$monthly=round((float)($p['preco']??0),4);$calc=saas_sub_bridge_cycle_values($monthly,$qty,$termMonths,$discountPercent);$cycleUnit=$calc['cycle_unit'];$line=$calc['net'];$discAmount=$calc['discount'];$costV=round((float)($p['custo']??0)*$termMonths,4);$code=(string)($p['numero_cadastro']??$p['referencia']??$p['id']);$unitName=(string)($p['unidade_estoque']??'UN');$info='Módulo SaaS: '.(string)($m['module_code']??$m['code']??'').' (#'.$mid.') · Base mensal R$ '.number_format($monthly,2,',','.').' · ciclo '.$termMonths.' mês(es)';
      if(isset($managed[$mid])){
        $iid=(int)$managed[$mid]['id'];$pdo->prepare("UPDATE contract_items SET product_id=?,product_code_snapshot=?,description_snapshot=?,unit_snapshot=?,quantity=?,billing_type=?,indefinite_term=1,validity_start=?,validity_end=NULL,next_adjustment_date=?,cost=?,table_price=?,unit_price=?,discount_percent=?,discount_amount=?,addition_percent=0,addition_amount=0,total=?,complementary_info=?,updated_at=NOW() WHERE company_id=? AND contract_id=? AND id=? AND status='ATIVO'")->execute([(int)$p['id'],$code,(string)$p['nome'],$unitName,$qty,$billingType,$nextDue->format('Y-m-d'),$nextAdjustment?->format('Y-m-d'),$costV,$cycleUnit,$cycleUnit,$discountPercent,$discAmount,$line,$info,$billingCompanyId,$contractId,$iid]);$updated++;
      }else{
        $pdo->prepare("INSERT INTO contract_items(company_id,contract_id,product_id,product_code_snapshot,description_snapshot,unit_snapshot,quantity,billing_type,indefinite_term,validity_start,validity_end,grace_date,next_adjustment_date,price_table_name,cost,table_price,unit_price,discount_percent,discount_amount,addition_percent,addition_amount,total,complementary_info,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,1,?,NULL,NULL,?,NULL,?,?,?,?,?,0,0,?,?,'ATIVO',NOW(),NOW())")->execute([$billingCompanyId,$contractId,(int)$p['id'],$code,(string)$p['nome'],$unitName,$qty,$billingType,$nextDue->format('Y-m-d'),$nextAdjustment?->format('Y-m-d'),$costV,$cycleUnit,$cycleUnit,$discountPercent,$discAmount,$line,$info]);$added++;
      }
    }
    foreach($state['extra'] as $mid=>$x){$iid=(int)($x['item']['id']??0);if($iid>0){$pdo->prepare("UPDATE contract_items SET status='CANCELADO',canceled_at=NOW(),complementary_info=CONCAT(COALESCE(complementary_info,''),' | Removido na migração da assinatura SaaS'),updated_at=NOW() WHERE company_id=? AND contract_id=? AND id=? AND status='ATIVO'")->execute([$billingCompanyId,$contractId,$iid]);$removed++;}}
    contracts_recalc($pdo,$billingCompanyId,$contractId);return['added'=>$added,'updated'=>$updated,'removed'=>$removed,'billing_type'=>$billingType,'term_months'=>$termMonths,'discount_percent'=>$discountPercent];
  }
}

if (!function_exists('saas_sub_bridge_snapshot_active_items')) {
  function saas_sub_bridge_snapshot_active_items(PDO $pdo,int $linkId,int $billingCompanyId,int $contractId,array $allModules,array $billableModules,DateTimeImmutable $periodStart,array $courtesyNotes=[]): void {
    $state=saas_sub_bridge_contract_module_state($pdo,$billingCompanyId,$contractId,$billableModules);$pdo->prepare('DELETE FROM saas_subscription_items WHERE link_id=?')->execute([$linkId]);
    $ins=$pdo->prepare("INSERT INTO saas_subscription_items(link_id,saas_module_id,product_id,quantity,unit_price,status,starts_at,ends_at,contract_item_id,created_at,updated_at) VALUES(?,?,?,?,?,'ACTIVE',?,NULL,?,NOW(),NOW())");
    $billableIds=[];foreach($billableModules as $m)$billableIds[(int)$m['saas_module_id']]=true;
    foreach($allModules as $m){$mid=(int)$m['saas_module_id'];$p=saas_sub_bridge_product_snapshot($pdo,$billingCompanyId,(int)$m['product_id']);$ci=isset($billableIds[$mid])?(int)($state['managed'][$mid]['id']??0):0;$ins->execute([$linkId,$mid,(int)$m['product_id'],max(1.0,(float)($m['quantity']??1)),round((float)($p['preco']??0),4),$periodStart->format('Y-m-d H:i:s'),$ci?:null]);}
    saas_sub_bridge_save_billing_policy($pdo,$linkId,$allModules,array_keys($billableIds),$courtesyNotes);
  }
}


if (!function_exists('saas_sub_bridge_migrate_existing')) {
  function saas_sub_bridge_migrate_existing(PDO $pdo,array $input,string $source='central_saas'): array {
    if(!saas_sub_bridge_schema_ready($pdo))throw new RuntimeException('A migration V11.52 ainda não foi aplicada.');
    $cfg=saas_sub_bridge_config($pdo);$billingCompanyId=(int)($cfg['billing_company_id']??0);saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $saasCompanyId=max(0,(int)($input['saas_company_id']??0));$personId=max(0,(int)($input['person_id']??0));$contractId=max(0,(int)($input['contract_id']??0));$createPerson=!empty($input['create_person']);$createNew=!empty($input['create_new_contract']);$syncModules=!empty($input['sync_modules']);
    if($saasCompanyId<=0)throw new RuntimeException('Selecione a empresa SaaS.');
    $company=saas_sub_bridge_company($pdo,$saasCompanyId);if(!$company)throw new RuntimeException('Empresa SaaS não localizada.');
    $subscription=saas_sub_bridge_latest_subscription($pdo,$saasCompanyId);if(!$subscription)throw new RuntimeException('A empresa SaaS não possui assinatura cadastrada.');
    $subStatus=strtolower((string)($subscription['status']??''));if(in_array($subStatus,['canceled','cancelled'],true))throw new RuntimeException('Assinatura SaaS cancelada não pode ser migrada como cliente ativo.');
    $periodStart=saas_sub_bridge_parse_date((string)($input['current_period_start']??''),'Início do período vigente');$periodEnd=saas_sub_bridge_parse_date((string)($input['current_period_end']??''),'Fim do período vigente');$nextDue=saas_sub_bridge_parse_date((string)($input['next_due_date']??''),'Próximo vencimento');$graceUntil=saas_sub_bridge_parse_date((string)($input['grace_until']??''),'Data limite da carência');
    if(!saas_sub_bridge_adjustment_ready($pdo))throw new RuntimeException('Aplique a migration V11.52.9 — Vinculação completa e próximo reajuste antes de confirmar.');
    $nextAdjustment=saas_sub_bridge_parse_date((string)($input['next_adjustment_date']??''),'Próximo reajuste');
    if($periodStart>$periodEnd)throw new RuntimeException('O início do período vigente não pode ser posterior ao fim.');if($nextDue<$periodEnd)throw new RuntimeException('O próximo vencimento não pode ser anterior ao fim do período vigente.');if($graceUntil<$nextDue)throw new RuntimeException('A carência não pode terminar antes do próximo vencimento.');if($nextAdjustment<$periodStart)throw new RuntimeException('O próximo reajuste não pode ser anterior ao início do período vigente.');
    $lifecycle=strtoupper(trim((string)($input['lifecycle_status']??'ACTIVE')));if(!in_array($lifecycle,['ACTIVE','PENDING_PAYMENT','GRACE_PERIOD'],true))throw new RuntimeException('Situação inicial da assinatura inválida.');
    $today=new DateTimeImmutable('today');if($lifecycle==='ACTIVE' && $nextDue<$today)throw new RuntimeException('Uma assinatura marcada como ATIVA não pode ter próximo vencimento no passado. Use Aguardando pagamento/Carência ou ajuste a data.');
    $allModules=saas_sub_bridge_enabled_billable_modules($pdo,$saasCompanyId,$billingCompanyId);if(!$allModules)throw new RuntimeException('Nenhum módulo habilitado desta empresa possui vínculo com Serviço no Admin.');
    $oldLink=saas_sub_bridge_link($pdo,$saasCompanyId);if($oldLink && (int)($oldLink['billing_company_id']??0)!==$billingCompanyId)throw new RuntimeException('Esta empresa SaaS já está ligada a outra empresa faturadora.');
    $selection=saas_sub_bridge_billing_selection($pdo,(int)($oldLink['id']??0),$allModules,$input);$allModules=$selection['all'];$modules=$selection['billable'];$courtesyModules=$selection['courtesy'];$courtesyNotes=(array)($input['courtesy_note']??[]);
    $termMonths=max(1,(int)($input['renewal_term_months']??1));$term=saas_sub_bridge_term_option($pdo,$termMonths);if(!$term)throw new RuntimeException('Período de renovação inválido ou desativado.');$termMonths=(int)$term['term_months'];$termDiscount=max(0.0,min(100.0,(float)($term['discount_percent']??0)));$billingType=saas_sub_bridge_term_billing_type($termMonths);$syncModules=true; // V11.52.3: aplica quantidade, período e preço do próximo ciclo somente aos itens SaaS mapeados.
    if($personId<=0 && $createPerson)$personId=saas_sub_bridge_find_or_create_person($pdo,$billingCompanyId,$company,null);
    if($personId<=0)throw new RuntimeException('Selecione uma Pessoa existente ou marque “Criar Pessoa automaticamente”.');
    $st=$pdo->prepare('SELECT id,name FROM finance_people WHERE company_id=? AND id=? AND COALESCE(active,1)=1 LIMIT 1');$st->execute([$billingCompanyId,$personId]);$person=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();if(!$person)throw new RuntimeException('Pessoa selecionada não pertence à empresa faturadora.');
    $newContractCreated=false;
    if($createNew){$contractId=saas_sub_bridge_create_active_contract($pdo,$billingCompanyId,$personId,$company,$cfg,$modules,$periodStart,$nextDue,$termMonths);$newContractCreated=true;$syncModules=true;}
    if($contractId<=0)throw new RuntimeException('Selecione um contrato existente ou escolha criar um novo contrato.');
    $st=$pdo->prepare("SELECT * FROM contracts WHERE company_id=? AND id=? AND status<>'CANCELADO' LIMIT 1");$st->execute([$billingCompanyId,$contractId]);$contract=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();if(!$contract)throw new RuntimeException('Contrato selecionado não pertence à empresa faturadora ou está cancelado.');
    if((int)$contract['contracting_person_id']!==$personId && (int)($contract['billing_person_id']??0)!==$personId)throw new RuntimeException('O contrato selecionado não pertence à Pessoa escolhida.');
    $st=$pdo->prepare('SELECT saas_company_id FROM saas_subscription_links WHERE billing_company_id=? AND contract_id=? AND saas_company_id<>? LIMIT 1');$st->execute([$billingCompanyId,$contractId,$saasCompanyId]);$other=(int)$st->fetchColumn();$st->closeCursor();if($other>0)throw new RuntimeException('Este contrato já está vinculado a outra empresa SaaS (#'.$other.').');
    $moduleState=saas_sub_bridge_contract_module_state($pdo,$billingCompanyId,$contractId,$modules);if((!empty($moduleState['missing'])||!empty($moduleState['extra'])||!empty($moduleState['changed']))&&!$syncModules){$parts=[];if($moduleState['missing'])$parts[]=count($moduleState['missing']).' módulo(s) do SaaS ausente(s) no contrato';if($moduleState['extra'])$parts[]=count($moduleState['extra']).' módulo(s) do contrato fora do SaaS';if($moduleState['changed'])$parts[]=count($moduleState['changed']).' módulo(s) com quantidade/serviço diferente';throw new RuntimeException('Há divergências entre SaaS e Contrato ('.implode(' e ',$parts).'). Marque “Sincronizar módulos SaaS com o contrato” para concluir com segurança.');}
    $billingState=saas_sub_bridge_contract_billing_state($pdo,$billingCompanyId,$contractId);$syncResult=['added'=>0,'updated'=>0,'removed'=>0];
    $pdo->beginTransaction();
    try{
      if($syncModules)$syncResult=saas_sub_bridge_sync_active_contract_items($pdo,$billingCompanyId,$contractId,$modules,$nextDue,$termMonths,$termDiscount,$nextAdjustment);
      $name=(string)$person['name'];$payment=(int)($cfg['default_payment_method_id']??0)?:null;$bank=(int)($cfg['default_bank_account_id']??0)?:null;$chart=(int)($cfg['default_chart_account_id']??0)?:null;$cost=(int)($cfg['default_cost_center_id']??0)?:null;
      $pdo->prepare("UPDATE contracts SET status='ATIVO',billing_mode='AUTOMATICA',release_date=COALESCE(release_date,CURDATE()),validity_start=COALESCE(validity_start,?),validity_end=NULL,contracting_person_id=?,contracting_person_name=?,billing_person_id=?,billing_person_name=?,due_day=?,default_billing_type=?,chart_account_id=COALESCE(chart_account_id,?),bank_account_id=COALESCE(bank_account_id,?),payment_method_id=COALESCE(payment_method_id,?),cost_center_id=COALESCE(cost_center_id,?),updated_at=NOW() WHERE company_id=? AND id=?")->execute([$periodStart->format('Y-m-d'),$personId,$name,$personId,$name,(int)$nextDue->format('d'),$billingType,$chart,$bank,$payment,$cost,$billingCompanyId,$contractId]);
      if($oldLink){$linkId=(int)$oldLink['id'];$pdo->prepare("UPDATE saas_subscription_links SET saas_subscription_id=?,billing_company_id=?,person_id=?,contract_id=?,lifecycle_status=?,current_period_start=?,current_period_end=?,next_due_date=?,next_adjustment_date=?,grace_until=?,renewal_term_months=?,renewal_discount_percent=?,activated_at=CASE WHEN ?='ACTIVE' THEN COALESCE(activated_at,NOW()) ELSE activated_at END,last_sync_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$subscription['id'],$billingCompanyId,$personId,$contractId,$lifecycle,$periodStart->format('Y-m-d 00:00:00'),$periodEnd->format('Y-m-d 23:59:59'),$nextDue->format('Y-m-d'),$nextAdjustment->format('Y-m-d'),$graceUntil->format('Y-m-d 23:59:59'),$termMonths,$termDiscount,$lifecycle,$linkId]);}
      else{$pdo->prepare("INSERT INTO saas_subscription_links(saas_company_id,saas_subscription_id,billing_company_id,person_id,contract_id,lifecycle_status,current_period_start,current_period_end,next_due_date,next_adjustment_date,grace_until,renewal_term_months,renewal_discount_percent,activated_at,last_sync_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,CASE WHEN ?='ACTIVE' THEN NOW() ELSE NULL END,NOW(),NULL,NOW(),NOW())")->execute([$saasCompanyId,(int)$subscription['id'],$billingCompanyId,$personId,$contractId,$lifecycle,$periodStart->format('Y-m-d 00:00:00'),$periodEnd->format('Y-m-d 23:59:59'),$nextDue->format('Y-m-d'),$nextAdjustment->format('Y-m-d'),$graceUntil->format('Y-m-d 23:59:59'),$termMonths,$termDiscount,$lifecycle]);$linkId=(int)$pdo->lastInsertId();}
      saas_sub_bridge_snapshot_active_items($pdo,$linkId,$billingCompanyId,$contractId,$allModules,$modules,$periodStart,$courtesyNotes);
      // V11.52.4: a tela legada da empresa SaaS lê vencimento/próxima cobrança da tabela subscriptions.
      // Mantém as duas visões sincronizadas com as datas confirmadas na vinculação segura.
      saas_sub_bridge_sync_subscription_dates($pdo,(int)$subscription['id'],$saasCompanyId,$periodEnd,$nextDue);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($newContractCreated){try{$pdo->prepare("UPDATE contracts SET status='CANCELADO',cancellation_date=CURDATE(),notes=CONCAT(COALESCE(notes,''),' | Criação de migração revertida por erro posterior.'),updated_at=NOW() WHERE company_id=? AND id=?")->execute([$billingCompanyId,$contractId]);}catch(Throwable $ignore){}}throw$e;}
    $billingModes=[];foreach($allModules as $m){$mid=(int)$m['saas_module_id'];$billingModes[$mid]=in_array($mid,$selection['selected_ids'],true)?'BILLABLE':'COURTESY';}
    saas_sub_bridge_event($pdo,'EXISTING_LINK:'.$saasCompanyId.':'.$contractId.':'.$nextDue->format('Ymd'),'EXISTING_SUBSCRIPTION_LINKED',$saasCompanyId,$linkId,$billingCompanyId,'CONTRACT',$contractId,['source'=>$source,'subscription_status'=>$subStatus,'person_id'=>$personId,'contract_id'=>$contractId,'new_contract'=>$newContractCreated,'sync_modules'=>$syncModules,'sync_result'=>$syncResult,'current_period_start'=>$periodStart->format('Y-m-d'),'current_period_end'=>$periodEnd->format('Y-m-d'),'next_due_date'=>$nextDue->format('Y-m-d'),'next_adjustment_date'=>$nextAdjustment->format('Y-m-d'),'grace_until'=>$graceUntil->format('Y-m-d'),'billing_state'=>$billingState,'billing_modes'=>$billingModes,'billing_quantities'=>array_column($allModules,'quantity','saas_module_id'),'renewal_term_months'=>$termMonths,'renewal_discount_percent'=>$termDiscount,'billing_type'=>$billingType],true,null);
    $policyPayload=['billing_modes'=>$billingModes,'billing_quantities'=>array_column($allModules,'quantity','saas_module_id'),'courtesy_notes'=>$courtesyNotes,'next_due_date'=>$nextDue->format('Y-m-d'),'next_adjustment_date'=>$nextAdjustment->format('Y-m-d'),'renewal_term_months'=>$termMonths,'renewal_discount_percent'=>$termDiscount,'billing_type'=>$billingType];$policyHash=substr(hash('sha256',json_encode($policyPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''),0,24);
    saas_sub_bridge_event($pdo,'BILLING_POLICY:'.$linkId.':'.$policyHash,'SUBSCRIPTION_BILLING_POLICY_SET',$saasCompanyId,$linkId,$billingCompanyId,'CONTRACT',$contractId,$policyPayload,true,null);
    return['ok'=>true,'saas_company_id'=>$saasCompanyId,'billing_company_id'=>$billingCompanyId,'person_id'=>$personId,'contract_id'=>$contractId,'link_id'=>$linkId,'module_count'=>count($allModules),'billable_count'=>count($modules),'courtesy_count'=>count($courtesyModules),'sync_result'=>$syncResult,'billing_state'=>$billingState,'next_due_date'=>$nextDue->format('Y-m-d'),'next_adjustment_date'=>$nextAdjustment->format('Y-m-d'),'grace_until'=>$graceUntil->format('Y-m-d'),'renewal_term_months'=>$termMonths,'renewal_discount_percent'=>$termDiscount,'billing_type'=>$billingType];
  }
}

if (!function_exists('saas_sub_bridge_sync_company')) {
  function saas_sub_bridge_sync_company(PDO $pdo,int $saasCompanyId,string $source='manual'): array {
    if(!saas_sub_bridge_schema_ready($pdo)) throw new RuntimeException('A migration V11.52 ainda não foi aplicada.');
    $cfg=saas_sub_bridge_config($pdo);$billingCompanyId=(int)($cfg['billing_company_id']??0);
    if((int)($cfg['enabled']??0)!==1) throw new RuntimeException('A integração de Assinaturas SaaS está desativada.');
    saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
    $company=saas_sub_bridge_company($pdo,$saasCompanyId);if(!$company)throw new RuntimeException('Empresa SaaS não localizada.');
    $subscription=saas_sub_bridge_latest_subscription($pdo,$saasCompanyId);if(!$subscription)throw new RuntimeException('A empresa SaaS não possui assinatura cadastrada.');
    $subStatus=strtolower((string)($subscription['status']??''));if($subStatus!=='trial') throw new RuntimeException('A V11.52 sincroniza automaticamente somente assinaturas em Trial. Assinaturas ativas serão migradas em etapa posterior para não alterar cobranças existentes.');
    [$trialStart,$trialEnd]=saas_sub_bridge_trial_dates($subscription,$cfg);
    $allModules=saas_sub_bridge_enabled_billable_modules($pdo,$saasCompanyId,$billingCompanyId);if(!$allModules)throw new RuntimeException('Nenhum módulo habilitado desta empresa possui vínculo ativo com um serviço do Admin. Configure o mapa Módulo SaaS ↔ Serviço antes de sincronizar.');
    $oldLink=saas_sub_bridge_link($pdo,$saasCompanyId);$selection=saas_sub_bridge_billing_selection($pdo,(int)($oldLink['id']??0),$allModules,[]);$allModules=$selection['all'];$modules=$selection['billable'];
    $personId=saas_sub_bridge_find_or_create_person($pdo,$billingCompanyId,$company,(int)($oldLink['person_id']??0));
    $linkId=saas_sub_bridge_upsert_link($pdo,$saasCompanyId,(int)$subscription['id'],$billingCompanyId,$personId,(int)($oldLink['contract_id']??0)?:null,'TRIAL',$trialStart,$trialEnd,null);
    $link=saas_sub_bridge_link($pdo,$saasCompanyId);$contractId=(int)($link['contract_id']??0);
    // Trial NÃO gera contrato na empresa faturadora: o contrato só é lançado na
    // ativação (Central SaaS, CRM ou contratação feita pelo próprio cliente), depois
    // da conferência de módulos, quantidades e valores. Vínculos antigos que já
    // tenham contrato de teste continuam sendo sincronizados como antes.
    if($contractId>0) saas_sub_bridge_sync_trial_items($pdo,$linkId,$billingCompanyId,$contractId,$allModules,$modules,$trialEnd);
    $pdo->prepare('UPDATE saas_subscription_links SET lifecycle_status=\'TRIAL\',trial_started_at=?,trial_ends_at=?,next_due_date=?,last_sync_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?')->execute([$trialStart->format('Y-m-d H:i:s'),$trialEnd->format('Y-m-d H:i:s'),$trialEnd->format('Y-m-d'),$linkId]);
    saas_sub_bridge_event($pdo,'TRIAL_SYNC:'.$saasCompanyId.':'.$subscription['id'],'TRIAL_SYNCED',$saasCompanyId,$linkId,$billingCompanyId,'SUBSCRIPTION',(int)$subscription['id'],['source'=>$source,'person_id'=>$personId,'contract_id'=>$contractId,'trial_ends_at'=>$trialEnd->format('Y-m-d H:i:s'),'modules'=>array_map(static fn($m)=>['module_id'=>(int)$m['saas_module_id'],'product_id'=>(int)$m['product_id'],'quantity'=>(float)$m['quantity']],$allModules),'billable_module_ids'=>$selection['selected_ids']],true,null);
    return ['ok'=>true,'saas_company_id'=>$saasCompanyId,'billing_company_id'=>$billingCompanyId,'person_id'=>$personId,'contract_id'=>$contractId,'link_id'=>$linkId,'trial_ends_at'=>$trialEnd->format('Y-m-d H:i:s'),'module_count'=>count($allModules),'billable_count'=>count($modules),'courtesy_count'=>count($selection['courtesy'])];
  }
}

if (!function_exists('saas_sub_bridge_sync_signup_safe')) {
  function saas_sub_bridge_sync_signup_safe(PDO $pdo,int $saasCompanyId): array {
    if(!saas_sub_bridge_schema_ready($pdo) || !saas_sub_bridge_enabled($pdo)) return ['ok'=>false,'skipped'=>true,'message'=>'Integração SaaS/Admin desativada ou migration pendente.'];
    try{return saas_sub_bridge_sync_company($pdo,$saasCompanyId,'signup');}
    catch(Throwable $e){
      $cfg=saas_sub_bridge_config($pdo);$billingCompanyId=(int)($cfg['billing_company_id']??0);$link=saas_sub_bridge_link($pdo,$saasCompanyId);
      if($link){try{$pdo->prepare('UPDATE saas_subscription_links SET last_error=?,last_sync_at=NOW(),updated_at=NOW() WHERE id=?')->execute([substr($e->getMessage(),0,1000),(int)$link['id']]);}catch(Throwable $ignore){}}
      try{saas_sub_bridge_event($pdo,'TRIAL_SYNC_ERROR:'.$saasCompanyId.':'.date('YmdHi'),'TRIAL_SYNC_ERROR',$saasCompanyId,(int)($link['id']??0),$billingCompanyId,'SIGNUP',$saasCompanyId,['message'=>$e->getMessage()],false,$e->getMessage());}catch(Throwable $ignore){}
      return ['ok'=>false,'skipped'=>false,'message'=>$e->getMessage()];
    }
  }
}
