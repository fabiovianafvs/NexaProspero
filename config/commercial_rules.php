<?php
declare(strict_types=1);

if (!function_exists('commercial_table_exists')) {
  function commercial_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try { $st=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1'); $st->execute([$table]); return (bool)$st->fetchColumn(); } catch(Throwable $e){ return false; }
  }
}
if (!function_exists('commercial_column_exists')) {
  function commercial_column_exists(PDO $pdo, string $table, string $col): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) return false;
    try { $st=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1'); $st->execute([$table,$col]); return (bool)$st->fetchColumn(); } catch(Throwable $e){ return false; }
  }
}
if (!function_exists('commercial_add_col')) {
  function commercial_add_col(PDO $pdo,string $table,string $col,string $def,array &$errors): void {
    if (commercial_column_exists($pdo,$table,$col)) return;
    try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}"); } catch(Throwable $e){ $errors[]=$e->getMessage(); }
  }
}
if (!function_exists('commercial_ensure_schema')) {
  function commercial_ensure_schema(PDO $pdo): array {
    static $done=[]; $k=spl_object_id($pdo); if(isset($done[$k])) return $done[$k];
    // V11.25: no F11 cruzado o PDV só usa o schema já homologado; nunca executa DDL em runtime.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return ['ok'=>true,'errors'=>[]];
    $errors=[];
    if (commercial_table_exists($pdo,'users')) {
      commercial_add_col($pdo,'users','default_seller_id','INT NULL',$errors);
      commercial_add_col($pdo,'users','allow_seller_change','TINYINT(1) NOT NULL DEFAULT 1',$errors);
      commercial_add_col($pdo,'users','max_discount_percent','DECIMAL(6,2) NULL',$errors);
      commercial_add_col($pdo,'users','allow_credit_override','TINYINT(1) NOT NULL DEFAULT 0',$errors);
      commercial_add_col($pdo,'users','allow_waive_interest','TINYINT(1) NOT NULL DEFAULT 0',$errors);
      commercial_add_col($pdo,'users','allow_waive_fine','TINYINT(1) NOT NULL DEFAULT 0',$errors);
      commercial_add_col($pdo,'users','max_settlement_discount_percent','DECIMAL(6,2) NULL',$errors);
    }
    if (commercial_table_exists($pdo,'finance_people')) {
      commercial_add_col($pdo,'finance_people','seller_commission_percent','DECIMAL(6,2) NULL',$errors);
      commercial_add_col($pdo,'finance_people','credit_limit','DECIMAL(15,2) NULL',$errors);
    }
    if (commercial_table_exists($pdo,'pedidos')) {
      commercial_add_col($pdo,'pedidos','seller_commission_percent','DECIMAL(6,2) NULL',$errors);
      commercial_add_col($pdo,'pedidos','seller_commission_base','DECIMAL(15,2) NOT NULL DEFAULT 0',$errors);
      commercial_add_col($pdo,'pedidos','seller_commission_amount','DECIMAL(15,2) NOT NULL DEFAULT 0',$errors);
      commercial_add_col($pdo,'pedidos','credit_limit_snapshot','DECIMAL(15,2) NULL',$errors);
      commercial_add_col($pdo,'pedidos','credit_open_before','DECIMAL(15,2) NOT NULL DEFAULT 0',$errors);
      commercial_add_col($pdo,'pedidos','credit_override_user_id','INT NULL',$errors);
      commercial_add_col($pdo,'pedidos','credit_override_at','DATETIME NULL',$errors);
    }
    return $done[$k]=['ok'=>!$errors,'errors'=>$errors];
  }
}
if (!function_exists('commercial_user_rules')) {
  function commercial_user_rules(PDO $pdo,int $companyId,int $userId): array {
    commercial_ensure_schema($pdo);
    $d=['default_seller_id'=>0,'allow_seller_change'=>1,'max_discount_percent'=>null,'allow_credit_override'=>0,'allow_waive_interest'=>0,'allow_waive_fine'=>0,'max_settlement_discount_percent'=>null];
    if($userId<=0) return $d;
    try { $st=$pdo->prepare('SELECT default_seller_id,allow_seller_change,max_discount_percent,allow_credit_override,allow_waive_interest,allow_waive_fine,max_settlement_discount_percent FROM users WHERE id=? AND (company_id=? OR company_id IS NULL) LIMIT 1'); $st->execute([$userId,$companyId]); $r=$st->fetch(PDO::FETCH_ASSOC); if($r){$d['default_seller_id']=(int)($r['default_seller_id']??0);$d['allow_seller_change']=(int)($r['allow_seller_change']??1);$d['max_discount_percent']=$r['max_discount_percent']===null?null:(float)$r['max_discount_percent'];$d['allow_credit_override']=(int)($r['allow_credit_override']??0);$d['allow_waive_interest']=(int)($r['allow_waive_interest']??0);$d['allow_waive_fine']=(int)($r['allow_waive_fine']??0);$d['max_settlement_discount_percent']=$r['max_settlement_discount_percent']===null?null:(float)$r['max_settlement_discount_percent'];} } catch(Throwable $e){}
    return $d;
  }
}
if (!function_exists('commercial_resolve_seller')) {
  function commercial_resolve_seller(PDO $pdo,int $companyId,int $userId,int $requestedSellerId): int {
    $r=commercial_user_rules($pdo,$companyId,$userId); $seller=$requestedSellerId;
    if(empty($r['allow_seller_change'])) $seller=(int)$r['default_seller_id'];
    elseif($seller<=0 && (int)$r['default_seller_id']>0) $seller=(int)$r['default_seller_id'];
    if($seller>0){ try{$st=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_seller=1 LIMIT 1');$st->execute([$companyId,$seller]); if(!$st->fetchColumn()) $seller=0;}catch(Throwable $e){$seller=0;} }
    return $seller;
  }
}
if (!function_exists('commercial_validate_discount')) {
  function commercial_validate_discount(PDO $pdo,int $companyId,int $userId,float $subtotal,float $discount): void {
    $r=commercial_user_rules($pdo,$companyId,$userId); if($r['max_discount_percent']===null) return;
    $pct=max(0.0,(float)$r['max_discount_percent']); $max=round(max(0,$subtotal)*$pct/100,2);
    if(round($discount,2)>$max+0.009) throw new RuntimeException('Desconto acima do limite deste usuário. Limite: '.number_format($pct,2,',','.').'% (R$ '.number_format($max,2,',','.').').');
  }
}


if (!function_exists('commercial_user_role')) {
  function commercial_user_role(PDO $pdo,int $companyId,int $userId): string {
    if($userId<=0) return '';
    try{$st=$pdo->prepare("SELECT role FROM users WHERE id=? AND (company_id=? OR role='platform_admin') LIMIT 1");$st->execute([$userId,$companyId]);return (string)($st->fetchColumn()?:'');}catch(Throwable $e){return '';}
  }
}
if (!function_exists('commercial_current_user_can_waive')) {
  function commercial_current_user_can_waive(PDO $pdo,int $companyId,string $type,?int $userId=null): bool {
    commercial_ensure_schema($pdo);
    $userId=$userId??(function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0));
    if($userId<=0) return false;
    $role=commercial_user_role($pdo,$companyId,$userId);
    if(in_array($role,['company_admin','platform_admin'],true)) return true;
    $rules=commercial_user_rules($pdo,$companyId,$userId);
    return strtolower($type)==='fine' ? !empty($rules['allow_waive_fine']) : !empty($rules['allow_waive_interest']);
  }
}
if (!function_exists('commercial_settlement_discount_limit')) {
  function commercial_settlement_discount_limit(PDO $pdo,int $companyId,?int $userId=null): ?float {
    commercial_ensure_schema($pdo);
    $userId=$userId??(function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0));
    if($userId<=0) return 0.0;
    $role=commercial_user_role($pdo,$companyId,$userId);
    if(in_array($role,['company_admin','platform_admin'],true)) return null;
    $r=commercial_user_rules($pdo,$companyId,$userId);
    return $r['max_settlement_discount_percent']===null?null:max(0.0,(float)$r['max_settlement_discount_percent']);
  }
}
if (!function_exists('commercial_validate_settlement_discount')) {
  function commercial_validate_settlement_discount(PDO $pdo,int $companyId,float $principal,float $manualDiscount,?int $userId=null): void {
    $principal=max(0.0,$principal);$manualDiscount=max(0.0,$manualDiscount);
    if($manualDiscount<=0.009) return;
    $limit=commercial_settlement_discount_limit($pdo,$companyId,$userId);
    if($limit===null) return;
    $max=round($principal*$limit/100,2);
    if($manualDiscount>$max+0.009){
      throw new RuntimeException('DESCONTO_BAIXA_EXCEDIDO|Desconto adicional acima do limite deste usuário. Limite: '.number_format($limit,2,',','.').'% (R$ '.number_format($max,2,',','.').').');
    }
  }
}
if (!function_exists('commercial_authorize_waiver_credentials')) {
  function commercial_authorize_waiver_credentials(PDO $pdo,int $companyId,string $code,string $password): array {
    commercial_ensure_schema($pdo);$code=trim($code);if($code===''||$password==='') return ['user_id'=>0,'allow_fine'=>0,'allow_interest'=>0];
    try{
      if(ctype_digit($code)){$st=$pdo->prepare("SELECT id,password_hash,role,allow_waive_fine,allow_waive_interest,is_active FROM users WHERE id=? AND (company_id=? OR role='platform_admin') LIMIT 1");$st->execute([(int)$code,$companyId]);}
      else{$st=$pdo->prepare("SELECT id,password_hash,role,allow_waive_fine,allow_waive_interest,is_active FROM users WHERE email=? AND (company_id=? OR role='platform_admin') LIMIT 1");$st->execute([strtolower($code),$companyId]);}
      $u=$st->fetch(PDO::FETCH_ASSOC);if(!$u||empty($u['is_active'])||!password_verify($password,(string)$u['password_hash'])) return ['user_id'=>0,'allow_fine'=>0,'allow_interest'=>0];
      $admin=in_array((string)$u['role'],['company_admin','platform_admin'],true);
      return ['user_id'=>(int)$u['id'],'allow_fine'=>$admin||!empty($u['allow_waive_fine'])?1:0,'allow_interest'=>$admin||!empty($u['allow_waive_interest'])?1:0];
    }catch(Throwable $e){return ['user_id'=>0,'allow_fine'=>0,'allow_interest'=>0];}
  }
}
if (!function_exists('commercial_issue_waiver_token')) {
  function commercial_issue_waiver_token(int $companyId,array $auth): string {
    $uid=(int)($auth['user_id']??0);if($companyId<=0||$uid<=0) return '';
    $token=bin2hex(random_bytes(24));
    if(!isset($_SESSION['finance_waiver_tokens'])||!is_array($_SESSION['finance_waiver_tokens']))$_SESSION['finance_waiver_tokens']=[];
    $now=time();foreach($_SESSION['finance_waiver_tokens'] as $k=>$v){if((int)($v['expires']??0)<$now)unset($_SESSION['finance_waiver_tokens'][$k]);}
    $_SESSION['finance_waiver_tokens'][$token]=['company_id'=>$companyId,'user_id'=>$uid,'allow_fine'=>!empty($auth['allow_fine'])?1:0,'allow_interest'=>!empty($auth['allow_interest'])?1:0,'expires'=>$now+600];
    return $token;
  }
}
if (!function_exists('commercial_waiver_token_info')) {
  function commercial_waiver_token_info(int $companyId,string $token): array {
    $token=trim($token);if($token===''||empty($_SESSION['finance_waiver_tokens'][$token]))return ['user_id'=>0,'allow_fine'=>0,'allow_interest'=>0];
    $v=(array)$_SESSION['finance_waiver_tokens'][$token];if((int)($v['company_id']??0)!==$companyId||(int)($v['expires']??0)<time()){unset($_SESSION['finance_waiver_tokens'][$token]);return ['user_id'=>0,'allow_fine'=>0,'allow_interest'=>0];}
    return ['user_id'=>(int)($v['user_id']??0),'allow_fine'=>!empty($v['allow_fine'])?1:0,'allow_interest'=>!empty($v['allow_interest'])?1:0];
  }
}
if (!function_exists('commercial_assert_settlement_waiver')) {
  function commercial_assert_settlement_waiver(PDO $pdo,int $companyId,bool $needFine,bool $needInterest,string $token=''): int {
    if(!$needFine&&!$needInterest) return 0;
    $uid=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
    $fineOk=!$needFine||commercial_current_user_can_waive($pdo,$companyId,'fine',$uid);
    $interestOk=!$needInterest||commercial_current_user_can_waive($pdo,$companyId,'interest',$uid);
    if($fineOk&&$interestOk) return $uid;
    $t=commercial_waiver_token_info($companyId,$token);
    if(!$fineOk&&$needFine&&!empty($t['allow_fine']))$fineOk=true;
    if(!$interestOk&&$needInterest&&!empty($t['allow_interest']))$interestOk=true;
    if($fineOk&&$interestOk) return (int)($t['user_id']?:$uid);
    $missing=[];if(!$fineOk)$missing[]='multa';if(!$interestOk)$missing[]='juros';
    throw new RuntimeException('DISPENSA_NAO_AUTORIZADA|É necessária autorização de usuário com permissão para dispensar '.implode(' e ',$missing).'.');
  }
}

if (!function_exists('commercial_open_credit')) {
  function commercial_open_credit(PDO $pdo,int $companyId,int $personId,bool $lock=false): float {
    if($personId<=0 || !commercial_table_exists($pdo,'finance_receivables')) return 0.0;
    // A serialização é feita pelo bloqueio da linha da pessoa em commercial_credit_check().
    // Não adicionamos FOR UPDATE ao SUM para manter compatibilidade entre versões do MySQL/MariaDB.
    try { $sql="SELECT COALESCE(SUM(CASE WHEN open_amount>0 THEN open_amount ELSE 0 END),0) FROM finance_receivables WHERE company_id=? AND person_id=? AND UPPER(COALESCE(status,'')) NOT IN ('CANCELADO','CANCELADA')"; $st=$pdo->prepare($sql);$st->execute([$companyId,$personId]);return round((float)$st->fetchColumn(),2);} catch(Throwable $e){return 0.0;}
  }
}
if (!function_exists('commercial_authorize_credit')) {
  function commercial_authorize_credit(PDO $pdo,int $companyId,string $code,string $password): int {
    $code=trim($code); if($code===''||$password==='') return 0;
    try{
      if(ctype_digit($code)){$st=$pdo->prepare('SELECT id,password_hash,role,allow_credit_override,is_active FROM users WHERE id=? AND (company_id=? OR role=\'platform_admin\') LIMIT 1');$st->execute([(int)$code,$companyId]);}
      else {$st=$pdo->prepare('SELECT id,password_hash,role,allow_credit_override,is_active FROM users WHERE email=? AND (company_id=? OR role=\'platform_admin\') LIMIT 1');$st->execute([strtolower($code),$companyId]);}
      $u=$st->fetch(PDO::FETCH_ASSOC); if(!$u||empty($u['is_active'])||!password_verify($password,(string)$u['password_hash'])) return 0;
      if(in_array((string)$u['role'],['company_admin','platform_admin'],true)||!empty($u['allow_credit_override'])) return (int)$u['id'];
    }catch(Throwable $e){}
    return 0;
  }
}
if (!function_exists('commercial_credit_check')) {
  function commercial_credit_check(PDO $pdo,int $companyId,int $personId,float $newCredit,array $post): array {
    commercial_ensure_schema($pdo); if($newCredit<=0) return ['limited'=>false,'open'=>0,'limit'=>null,'override_user_id'=>0];
    $st=$pdo->prepare('SELECT credit_limit FROM finance_people WHERE company_id=? AND id=? LIMIT 1 FOR UPDATE');$st->execute([$companyId,$personId]);$limitRaw=$st->fetchColumn();
    if($limitRaw===false) throw new RuntimeException('Cliente não encontrado para validar o limite de crédito.');
    if($limitRaw===null) return ['limited'=>false,'open'=>commercial_open_credit($pdo,$companyId,$personId,true),'limit'=>null,'override_user_id'=>0];
    $limit=max(0.0,(float)$limitRaw); $open=commercial_open_credit($pdo,$companyId,$personId,true); $projected=round($open+$newCredit,2);
    if($projected<=$limit+0.009) return ['limited'=>true,'open'=>$open,'limit'=>$limit,'override_user_id'=>0];
    $authId=commercial_authorize_credit($pdo,$companyId,(string)($post['credit_override_user_code']??''),(string)($post['credit_override_password']??''));
    if($authId<=0){$available=max(0,$limit-$open);throw new RuntimeException('LIMITE_CREDITO_EXCEDIDO|Limite R$ '.number_format($limit,2,',','.').' · em aberto R$ '.number_format($open,2,',','.').' · disponível R$ '.number_format($available,2,',','.').' · nova compra a prazo R$ '.number_format($newCredit,2,',','.').'. Informe código e senha de um usuário autorizado para liberar.');}
    return ['limited'=>true,'open'=>$open,'limit'=>$limit,'override_user_id'=>$authId];
  }
}
if (!function_exists('commercial_commission_snapshot')) {
  function commercial_commission_snapshot(PDO $pdo,int $companyId,int $sellerId,float $base): array {
    if($sellerId<=0) return ['percent'=>null,'base'=>round($base,2),'amount'=>0.0];
    try{$st=$pdo->prepare('SELECT seller_commission_percent FROM finance_people WHERE company_id=? AND id=? AND is_seller=1 LIMIT 1');$st->execute([$companyId,$sellerId]);$raw=$st->fetchColumn();$pct=$raw===null||$raw===false?null:max(0,(float)$raw);$amt=$pct===null?0.0:round(max(0,$base)*$pct/100,2);return ['percent'=>$pct,'base'=>round(max(0,$base),2),'amount'=>$amt];}catch(Throwable $e){return ['percent'=>null,'base'=>round(max(0,$base),2),'amount'=>0.0];}
  }
}

if (!function_exists('commercial_credit_open_map')) {
  function commercial_credit_open_map(PDO $pdo,int $companyId,array $personIds): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$personIds),static fn($v)=>$v>0))); if(!$ids) return [];
    $out=[]; foreach($ids as $id)$out[$id]=0.0;
    if(!commercial_table_exists($pdo,'finance_receivables')) return $out;
    try{$ph=implode(',',array_fill(0,count($ids),'?'));$st=$pdo->prepare("SELECT person_id,COALESCE(SUM(CASE WHEN open_amount>0 THEN open_amount ELSE 0 END),0) total_open FROM finance_receivables WHERE company_id=? AND person_id IN ($ph) AND UPPER(COALESCE(status,'')) NOT IN ('CANCELADO','CANCELADA') GROUP BY person_id");$st->execute(array_merge([$companyId],$ids));foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r)$out[(int)$r['person_id']]=round((float)$r['total_open'],2);}catch(Throwable $e){}
    return $out;
  }
}
