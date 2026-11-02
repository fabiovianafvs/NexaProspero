<?php
declare(strict_types=1);
require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/schema_runtime.php';

function contracts_db(): PDO { foreach (['pdo','db','conn'] as $n) if (($GLOBALS[$n] ?? null) instanceof PDO) return $GLOBALS[$n]; throw new RuntimeException('Conexão PDO não encontrada.'); }
function contracts_company_id(): int { if (defined('COMPANY_ID')) return (int)COMPANY_ID; return (int)($_SESSION['user_company_id'] ?? 0); }
function contracts_user_id(): ?int { $id=(int)($_SESSION['user_id'] ?? 0); return $id>0?$id:null; }
function contracts_e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function contracts_money($v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function contracts_decimal($v): float { if (is_string($v)) { $v=trim($v); if (strpos($v,',')!==false) $v=str_replace(',','.',str_replace('.','',$v)); } return round((float)$v,4); }
function contracts_schema_version(): int { return 2026090901; }
function contracts_schema_ready(PDO $pdo): bool { return nexa_schema_version_ready($pdo,'contracts',contracts_schema_version()); }
function contracts_require_schema(PDO $pdo): void {
  if (contracts_schema_ready($pdo)) return;
  http_response_code(503);
  echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contratos</title><style>body{font-family:Arial;background:#f5f7fb;color:#0f172a;padding:30px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #dbe4ee;border-radius:18px;padding:24px}code{background:#eef2f7;padding:3px 6px;border-radius:6px}</style></head><body><div class="box"><h2>Estrutura do módulo Contratos ainda não instalada</h2><p>Após copiar os arquivos, execute na VPS:</p><p><code>php /var/www/nexa/workers/migrate_contracts_v4.php</code></p></div></body></html>';
  exit;
}
function contracts_next_number(PDO $pdo,int $companyId): int {
  $pdo->beginTransaction();
  try {
    $st=$pdo->prepare('SELECT last_number FROM contract_sequences WHERE company_id=? FOR UPDATE');$st->execute([$companyId]);$n=$st->fetchColumn();
    if($n===false){$n=1;$pdo->prepare('INSERT INTO contract_sequences(company_id,last_number) VALUES(?,?)')->execute([$companyId,$n]);}
    else {$n=(int)$n+1;$pdo->prepare('UPDATE contract_sequences SET last_number=? WHERE company_id=?')->execute([$n,$companyId]);}
    $pdo->commit(); return (int)$n;
  } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
function contracts_recalc(PDO $pdo,int $companyId,int $contractId): void {
  $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='ATIVO' THEN total ELSE 0 END),0) FROM contract_items WHERE company_id=? AND contract_id=?");$st->execute([$companyId,$contractId]);
  $pdo->prepare('UPDATE contracts SET total_amount=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([(float)$st->fetchColumn(),$companyId,$contractId]);
}
function contracts_status_label(string $s): string { return match($s){'TESTE'=>'Teste','ATIVO'=>'Ativo','CANCELADO'=>'Cancelado','NEGOCIACAO'=>'Em negociação',default=>$s!==''?$s:'Em negociação'}; }
function contracts_billing_label(string $s): string { return match($s){'UNICA'=>'Cobrança única','SEMANAL'=>'Semanal','QUINZENAL'=>'Quinzenal','TRIMESTRAL'=>'Trimestral','SEMESTRAL'=>'Semestral','ANUAL'=>'Anual',default=>'Cobrança mensal'}; }
function contracts_billing_mode_normalize(string $s): string { $s=strtoupper(trim($s)); return $s==='AUTOMATICA'?'AUTOMATICA':'MANUAL'; }
function contracts_billing_mode_label(string $s): string { return contracts_billing_mode_normalize($s)==='AUTOMATICA'?'Automática':'Manual'; }
function contracts_ensure_schema(PDO $pdo): void {
  $ddl=[];
  $ddl[]="CREATE TABLE IF NOT EXISTS contract_sequences (company_id INT NOT NULL,last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $ddl[]="CREATE TABLE IF NOT EXISTS contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, company_id INT NOT NULL, contract_number BIGINT UNSIGNED NOT NULL,
    registration_date DATE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'NEGOCIACAO', release_date DATE NULL, block_date DATE NULL, cancellation_date DATE NULL, validity_start DATE NULL, validity_end DATE NULL,
    contracting_person_id INT NOT NULL, contracting_person_name VARCHAR(180) NOT NULL, billing_person_id INT NULL, billing_person_name VARCHAR(180) NULL,
    chart_account_id INT NULL, bank_account_id INT NULL, due_day TINYINT UNSIGNED NOT NULL DEFAULT 10, cost_center_id INT NULL,
    default_billing_type VARCHAR(20) NOT NULL DEFAULT 'MENSAL', billing_mode VARCHAR(20) NOT NULL DEFAULT 'MANUAL', notes TEXT NULL,total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_by INT NULL, updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
    PRIMARY KEY(id), UNIQUE KEY uk_contract_company_number(company_id,contract_number), KEY idx_contract_company_status(company_id,status), KEY idx_contract_company_date(company_id,registration_date), KEY idx_contract_person(company_id,contracting_person_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $ddl[]="CREATE TABLE IF NOT EXISTS contract_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,contract_id BIGINT UNSIGNED NOT NULL,product_id INT NOT NULL,
    product_code_snapshot VARCHAR(60) NULL,description_snapshot VARCHAR(180) NOT NULL,unit_snapshot VARCHAR(20) NULL,
    quantity DECIMAL(15,4) NOT NULL DEFAULT 1,billing_type VARCHAR(20) NOT NULL DEFAULT 'MENSAL',indefinite_term TINYINT(1) NOT NULL DEFAULT 1,
    validity_start DATE NULL,validity_end DATE NULL,grace_date DATE NULL,next_adjustment_date DATE NULL,price_table_name VARCHAR(120) NULL,cost DECIMAL(15,4) NOT NULL DEFAULT 0,
    table_price DECIMAL(15,4) NOT NULL DEFAULT 0,unit_price DECIMAL(15,4) NOT NULL DEFAULT 0,discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0,discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    addition_percent DECIMAL(9,4) NOT NULL DEFAULT 0,addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0,total DECIMAL(15,2) NOT NULL DEFAULT 0,
    complementary_info VARCHAR(500) NULL,status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',canceled_at DATETIME NULL,canceled_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,
    PRIMARY KEY(id),KEY idx_contract_items_contract(company_id,contract_id,status,id),KEY idx_contract_items_product(company_id,product_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $ddl[]="CREATE TABLE IF NOT EXISTS contract_sellers (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,contract_id BIGINT UNSIGNED NOT NULL,person_id INT NOT NULL,person_name_snapshot VARCHAR(180) NOT NULL,commission_percent DECIMAL(9,4) NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY uk_contract_seller(company_id,contract_id,person_id),KEY idx_contract_sellers_contract(company_id,contract_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $ddl[]="CREATE TABLE IF NOT EXISTS contract_notes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,contract_id BIGINT UNSIGNED NOT NULL,note TEXT NOT NULL,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_contract_notes_contract(company_id,contract_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $ddl[]="CREATE TABLE IF NOT EXISTS contract_generations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,contract_id BIGINT UNSIGNED NOT NULL,competence DATE NOT NULL,due_date DATE NULL,order_id BIGINT NULL,status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',amount DECIMAL(15,2) NOT NULL DEFAULT 0,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY uk_contract_generation(company_id,contract_id,competence),KEY idx_contract_generation_status(company_id,status,competence)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $ddl[]="CREATE TABLE IF NOT EXISTS contract_billing_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,contract_id BIGINT UNSIGNED NOT NULL,generation_id BIGINT NULL,origin_type VARCHAR(30) NULL,origin_id BIGINT NULL,event_type VARCHAR(40) NOT NULL,description VARCHAR(255) NULL,amount DECIMAL(15,2) NULL,event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_by INT NULL,PRIMARY KEY(id),KEY idx_contract_billing_history(company_id,contract_id,event_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  foreach($ddl as $sql)$pdo->exec($sql);
  $hasCol=function(string $table,string $column)use($pdo):bool{
    $st=$pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table,$column]);
    return (bool)$st->fetchColumn();
  };
  if(!$hasCol('contracts','validity_start'))$pdo->exec("ALTER TABLE contracts ADD COLUMN validity_start DATE NULL AFTER cancellation_date");
  if(!$hasCol('contracts','validity_end'))$pdo->exec("ALTER TABLE contracts ADD COLUMN validity_end DATE NULL AFTER validity_start");
  if(!$hasCol('contract_items','next_adjustment_date'))$pdo->exec("ALTER TABLE contract_items ADD COLUMN next_adjustment_date DATE NULL AFTER grace_date");
  billing_ensure_schema($pdo);
  
  $pdo->exec("CREATE TABLE IF NOT EXISTS contract_generation_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,generation_id BIGINT UNSIGNED NOT NULL,contract_item_id BIGINT UNSIGNED NOT NULL,amount DECIMAL(15,2) NOT NULL DEFAULT 0,PRIMARY KEY(id),UNIQUE KEY uk_contract_generation_item(company_id,generation_id,contract_item_id),KEY idx_contract_generation_items(company_id,generation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  foreach(['contract_id'=>'BIGINT NULL','contract_generation_id'=>'BIGINT NULL','contract_competence'=>'DATE NULL'] as $col=>$def){if(!$hasCol('pedidos',$col))$pdo->exec("ALTER TABLE pedidos ADD COLUMN `{$col}` {$def}");}
  nexa_schema_version_mark($pdo,'contracts',contracts_schema_version(),'Contratos - data do próximo reajuste por item');
}

function contracts_has_column(PDO $pdo,string $table,string $column): bool {
  static $cache=[];$k=$table.'.'.$column;if(array_key_exists($k,$cache))return $cache[$k];
  $st=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
  $st->execute([$table,$column]);return $cache[$k]=(bool)$st->fetchColumn();
}
function contracts_date_ok(?string $v): bool { return is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)===1; }
function contracts_clamp_day(int $year,int $month,int $day): string {
  $day=max(1,min($day,(int)date('t',strtotime(sprintf('%04d-%02d-01',$year,$month)))));
  return sprintf('%04d-%02d-%02d',$year,$month,$day);
}
function contracts_month_add(string $ymd,int $months,int $day): string {
  $d=new DateTimeImmutable(substr($ymd,0,7).'-01');$d=$d->modify(($months>=0?'+':'').$months.' months');
  return contracts_clamp_day((int)$d->format('Y'),(int)$d->format('m'),$day);
}
function contracts_min_date(?string $a,?string $b): ?string { if(!$a)return $b;if(!$b)return $a;return $a<$b?$a:$b; }
function contracts_max_date(?string $a,?string $b): ?string { if(!$a)return $b;if(!$b)return $a;return $a>$b?$a:$b; }
function contracts_item_occurrences(array $contract,array $item,string $periodStart,string $periodEnd): array {
  $anchor=(string)($item['validity_start']??'');if(!contracts_date_ok($anchor))$anchor=(string)($contract['validity_start']??'');if(!contracts_date_ok($anchor))$anchor=(string)$contract['registration_date'];
  $effectiveStart=contracts_max_date($anchor,contracts_date_ok((string)($item['grace_date']??''))?(string)$item['grace_date']:null) ?: $anchor;
  $end=null;if(contracts_date_ok((string)($contract['validity_end']??'')))$end=(string)$contract['validity_end'];if(contracts_date_ok((string)($item['validity_end']??'')))$end=contracts_min_date($end,(string)$item['validity_end']);
  if($end&&$end<$periodStart)return[];if($effectiveStart>$periodEnd)return[];
  $from=contracts_max_date($effectiveStart,$periodStart);$to=contracts_min_date($end,$periodEnd) ?: $periodEnd;if($from>$to)return[];
  $type=strtoupper((string)($item['billing_type']??$contract['default_billing_type']??'MENSAL'));
  $out=[];$dueDay=max(1,min(31,(int)($contract['due_day']??10)));
  if($type==='SEMANAL'||$type==='QUINZENAL'){
    $step=$type==='SEMANAL'?7:15;$d=new DateTimeImmutable($effectiveStart);$limit=0;
    while($d->format('Y-m-d')<$from&&$limit++<5000)$d=$d->modify('+'.$step.' days');
    while($d->format('Y-m-d')<=$to&&$limit++<5000){$out[]=$d->format('Y-m-d');$d=$d->modify('+'.$step.' days');}
    return$out;
  }
  $months=match($type){'TRIMESTRAL'=>3,'SEMESTRAL'=>6,'ANUAL'=>12,default=>1};
  $first=contracts_clamp_day((int)substr($anchor,0,4),(int)substr($anchor,5,2),$dueDay);
  if($first<$effectiveStart)$first=contracts_month_add($first,$months,$dueDay);
  if($type==='UNICA')return($first>=$from&&$first<=$to)?[$first]:[];
  $d=$first;$guard=0;while($d<$from&&$guard++<1000)$d=contracts_month_add($d,$months,$dueDay);
  while($d<=$to&&$guard++<1000){$out[]=$d;$d=contracts_month_add($d,$months,$dueDay);}return$out;
}
function contracts_generation_candidates(PDO $pdo,int $companyId,string $periodStart,string $periodEnd,int $personId=0,string $billingMode='MANUAL'): array {
  $billingMode=contracts_billing_mode_normalize($billingMode);
  $sql="SELECT c.* FROM contracts c WHERE c.company_id=? AND c.status='ATIVO' AND COALESCE(c.billing_mode,'MANUAL')=? AND c.registration_date<=? AND (c.validity_start IS NULL OR c.validity_start<=?) AND (c.validity_end IS NULL OR c.validity_end>=?)";
  $p=[$companyId,$billingMode,$periodEnd,$periodEnd,$periodStart];if($personId>0){$sql.=' AND (c.contracting_person_id=? OR c.billing_person_id=?)';$p[]=$personId;$p[]=$personId;}$sql.=' ORDER BY c.contract_number,c.id';
  $st=$pdo->prepare($sql);$st->execute($p);$contracts=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$rows=[];
  $genSt=$pdo->prepare('SELECT id,order_id,status,amount,created_at FROM contract_generations WHERE company_id=? AND contract_id=? AND competence=? LIMIT 1');
  $itemSt=$pdo->prepare("SELECT * FROM contract_items WHERE company_id=? AND contract_id=? AND status='ATIVO' ORDER BY id");
  foreach($contracts as $c){$itemSt->execute([$companyId,(int)$c['id']]);$items=$itemSt->fetchAll(PDO::FETCH_ASSOC)?:[];$byDate=[];
    foreach($items as $it){foreach(contracts_item_occurrences($c,$it,$periodStart,$periodEnd) as $date){$byDate[$date][]=$it;}}
    ksort($byDate);foreach($byDate as $date=>$dueItems){$amount=0.0;foreach($dueItems as $it)$amount+=(float)$it['total'];if($amount<=0)continue;$genSt->execute([$companyId,(int)$c['id'],$date]);$g=$genSt->fetch(PDO::FETCH_ASSOC)?:null;
      $rows[]=['key'=>(int)$c['id'].'|'.$date,'contract'=>$c,'due_date'=>$date,'competence'=>$date,'items'=>$dueItems,'item_count'=>count($dueItems),'amount'=>round($amount,2),'generation'=>$g];
    }
  }return$rows;
}
function contracts_person_order_snapshot(PDO $pdo,int $companyId,int $personId): array {
  if($personId<=0)return[];$st=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$personId]);return$st->fetch(PDO::FETCH_ASSOC)?:[];
}
function contracts_order_address(array $p): string {
  $line=trim((string)($p['address']??''));$num=trim((string)($p['number']??''));if($num!=='')$line.=($line!==''?', ':'').$num;$comp=trim((string)($p['complement']??''));if($comp!=='')$line.=($line!==''?' - ':'').$comp;
  $district=trim((string)($p['district']??''));$city=trim((string)($p['city']??''));$state=trim((string)($p['state']??''));$extra=trim(implode(' - ',array_filter([$district,$city,$state],fn($v)=>$v!=='')));if($extra!=='')$line.=($line!==''?' | ':'').$extra;return$line;
}
/** Gera o titulo financeiro antes de concluir um pedido de contrato, independentemente do meio de pagamento. */
function contracts_ensure_generation_receivable(PDO $pdo,int $companyId,int $generationId,?int $userId=null): int {
  $st=$pdo->prepare('SELECT g.*,c.contract_number,c.contracting_person_id,c.billing_person_id,c.chart_account_id,c.cost_center_id,c.bank_account_id,c.payment_method_id FROM contract_generations g JOIN contracts c ON c.company_id=g.company_id AND c.id=g.contract_id WHERE g.company_id=? AND g.id=? FOR UPDATE');
  $st->execute([$companyId,$generationId]);$g=$st->fetch(PDO::FETCH_ASSOC);
  if(!$g)throw new RuntimeException('Geração de contrato não encontrada para criação do financeiro.');
  $chartId=(int)($g['chart_account_id']??0);
  if($chartId<=0)throw new RuntimeException('Informe o plano de contas (natureza de lançamento) no contrato antes de gerar o pedido.');
  $amount=round((float)($g['amount']??0),2);
  if($amount<=0)throw new RuntimeException('O contrato não possui valor válido para gerar Contas a Receber.');
  $existingId=(int)($g['receivable_id']??0);
  if($existingId>0){
    $check=$pdo->prepare('SELECT id FROM finance_receivables WHERE company_id=? AND id=? LIMIT 1');$check->execute([$companyId,$existingId]);
    if($check->fetchColumn())return $existingId;
    throw new RuntimeException('Referência financeira inconsistente na geração do contrato.');
  }
  $check=$pdo->prepare('SELECT id FROM finance_receivables WHERE company_id=? AND contract_generation_id=? ORDER BY id LIMIT 1');
  $check->execute([$companyId,$generationId]);$found=(int)$check->fetchColumn();
  if($found>0){
    $pdo->prepare('UPDATE contract_generations SET receivable_id=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$found,$companyId,$generationId]);
    return $found;
  }
  $resolved=billing_resolve_contract_method($pdo,$companyId,$g);
  $methodId=(int)($resolved['method_id']??0);
  $method=(array)($resolved['method']??[]);
  $label=trim((string)($method['name']??''))?:'Contrato';
  $personId=(int)($g['billing_person_id']?:$g['contracting_person_id']);
  $due=(string)($g['due_date']?:$g['competence']);
  $competence=(string)$g['competence'];
  $description='Contrato #'.$g['contract_number'].' - competência '.date('m/Y',strtotime($competence));
  $ins=$pdo->prepare("INSERT INTO finance_receivables(company_id,person_id,chart_account_id,cost_center_id,bank_account_id,payment_method_id,order_id,contract_generation_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,paid_amount,open_amount,status,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,'ABERTO',?,NOW())");
  $ins->execute([$companyId,$personId,$chartId,(int)($g['cost_center_id']??0)?:null,(int)($g['bank_account_id']??0)?:null,$methodId?:null,(int)$g['order_id'],$generationId,$description,'CTR-'.$g['contract_number'].'-'.date('Ym',strtotime($competence)),'1/1',date('Y-m-d'),$due,$label,$amount,$amount,'Gerado automaticamente pelo contrato.']);
  $id=(int)$pdo->lastInsertId();
  $pdo->prepare('UPDATE contract_generations SET receivable_id=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$id,$companyId,$generationId]);
  $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) VALUES(?,?,?,'CONTAS_RECEBER',?,'RECEBIVEL_GERADO',?,?,NOW(),?)")
    ->execute([$companyId,(int)$g['contract_id'],$generationId,$id,'Contas a Receber gerado para Pedido #'.$g['order_id'],$amount,$userId]);
  return $id;
}
/** Adapta somente a descricao do pagamento ao campo legado do Pedido.
 * A identificacao real da modalidade continua preservada em payment_method_id.
 */
function contracts_order_payment_label(PDO $pdo,array $method,string $label): string {
  $name=trim($label)?:'Contrato';
  $normalized=mb_strtoupper($name,'UTF-8');
  $type=mb_strtoupper(trim((string)($method['type']??'')),'UTF-8');
  $code=mb_strtoupper(trim((string)($method['code']??'')),'UTF-8');
  if (in_array($type,['A_PRAZO','APRAZO','PRAZO'],true)
      || in_array($code,['A_PRAZO','APRAZO','PRAZO'],true)
      || preg_match('/^A\s+PRAZO(?:\b|\s|[-(])/u',$normalized)) {
    $name='A Prazo';
  }
  static $maxLength=null;
  if ($maxLength===null) {
    $stmt=$pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='forma_pagamento' LIMIT 1");
    $stmt->execute();
    $maxLength=max(0,(int)$stmt->fetchColumn());
  }
  return $maxLength>0 ? mb_substr($name,0,$maxLength,'UTF-8') : $name;
}
function contracts_create_order(PDO $pdo,int $companyId,array $candidate,?int $userId): int {
  $c=$candidate['contract'];if((int)($c['chart_account_id']??0)<=0)throw new RuntimeException('Informe o plano de contas (natureza de lançamento) no contrato antes de gerar o pedido.');$billingId=(int)($c['billing_person_id']?:$c['contracting_person_id']);$person=contracts_person_order_snapshot($pdo,$companyId,$billingId);if(!$person)throw new RuntimeException('Cliente de faturamento do contrato #'.$c['contract_number'].' não encontrado.');
  $date=(string)$candidate['due_date'];$genCheck=$pdo->prepare('SELECT id,order_id,status FROM contract_generations WHERE company_id=? AND contract_id=? AND competence=? FOR UPDATE');$genCheck->execute([$companyId,(int)$c['id'],$date]);$existing=$genCheck->fetch(PDO::FETCH_ASSOC);
  if($existing&&((int)($existing['order_id']??0)>0||strtoupper((string)$existing['status'])==='GERADO'))throw new RuntimeException('A competência '.date('d/m/Y',strtotime($date)).' do contrato #'.$c['contract_number'].' já foi gerada.');
  $amount=(float)$candidate['amount'];$obs='Gerado automaticamente pelo Contrato nº '.$c['contract_number'].' | Competência/Vencimento: '.date('d/m/Y',strtotime($date));
  $resolvedPayment=billing_resolve_contract_method($pdo,$companyId,$c);$contractPaymentId=(int)($resolvedPayment['method_id']??0);$contractPayment=$resolvedPayment['method']??[];$paymentLabel=contracts_order_payment_label($pdo,(array)$contractPayment,trim((string)($contractPayment['name']??''))?:'Contrato');
  $cols=['company_id'];$vals=[$companyId];
  require_once __DIR__.'/company_sequences.php';if(function_exists('company_sequence_ensure_schema'))company_sequence_ensure_schema($pdo,true);if(function_exists('company_sequence_has_column')&&company_sequence_has_column($pdo,'pedidos')){$cols[]='numero_pedido';$vals[]=company_sequence_next($pdo,$companyId,'pedidos');}
  $base=['nome_cliente'=>(string)$person['name'],'whatsapp'=>(string)($person['whatsapp']?:$person['phone']??''),'endereco'=>contracts_order_address($person),'tipo_entrega'=>'balcao','taxa_entrega'=>0,'forma_pagamento'=>$paymentLabel,'payment_method_id'=>$contractPaymentId?:null,'observacoes'=>$obs,'total_produtos'=>$amount,'total'=>$amount,'status'=>'finalizado'];
  foreach($base as $col=>$val)if(contracts_has_column($pdo,'pedidos',$col)){$cols[]=$col;$vals[]=$val;}
  foreach(['source'=>'contract','customer_id'=>$billingId,'contract_id'=>(int)$c['id'],'contract_competence'=>$date] as $col=>$val)if(contracts_has_column($pdo,'pedidos',$col)){$cols[]=$col;$vals[]=$val;}
  $ph=implode(',',array_fill(0,count($cols),'?'));$pdo->prepare('INSERT INTO pedidos('.implode(',',$cols).') VALUES('.$ph.')')->execute($vals);$orderId=(int)$pdo->lastInsertId();
  $obsCol=null;foreach(['complemento','observacao','obs','observacoes'] as $oc)if(contracts_has_column($pdo,'pedido_itens',$oc)){$obsCol=$oc;break;}
  $sql=$obsCol?"INSERT INTO pedido_itens(company_id,pedido_id,produto_id,qtd,preco_unit,total,$obsCol) VALUES(?,?,?,?,?,?,?)":"INSERT INTO pedido_itens(company_id,pedido_id,produto_id,qtd,preco_unit,total) VALUES(?,?,?,?,?,?)";$sti=$pdo->prepare($sql);
  foreach($candidate['items'] as $it){$qty=max(0.0001,(float)$it['quantity']);$total=round((float)$it['total'],2);$price=round($total/$qty,4);$iobs=trim((string)($it['complementary_info']??''));$args=[$companyId,$orderId,(int)$it['product_id'],$qty,$price,$total];if($obsCol)$args[]=$iobs;$sti->execute($args);}
  if($existing){$generationId=(int)$existing['id'];$pdo->prepare("UPDATE contract_generations SET due_date=?,order_id=?,status='GERADO',amount=?,updated_at=NOW() WHERE id=? AND company_id=?")->execute([$date,$orderId,$amount,$generationId,$companyId]);}
  else{$pdo->prepare("INSERT INTO contract_generations(company_id,contract_id,competence,due_date,order_id,status,amount,created_by,created_at,updated_at) VALUES(?,?,?,?,?,'GERADO',?,?,NOW(),NOW())")->execute([$companyId,(int)$c['id'],$date,$date,$orderId,$amount,$userId]);$generationId=(int)$pdo->lastInsertId();}
  if(contracts_has_column($pdo,'pedidos','contract_generation_id'))$pdo->prepare('UPDATE pedidos SET contract_generation_id=? WHERE company_id=? AND id=?')->execute([$generationId,$companyId,$orderId]);
  if(contracts_has_column($pdo,'contract_generations','payment_method_id'))$pdo->prepare('UPDATE contract_generations SET payment_method_id=?,bank_account_id=? WHERE company_id=? AND id=?')->execute([$contractPaymentId?:null,(int)($c['bank_account_id']??0)?:null,$companyId,$generationId]);
  if(contracts_has_column($pdo,'contract_generation_items','id')){$ig=$pdo->prepare('INSERT IGNORE INTO contract_generation_items(company_id,generation_id,contract_item_id,amount) VALUES(?,?,?,?)');foreach($candidate['items'] as $it)$ig->execute([$companyId,$generationId,(int)$it['id'],round((float)$it['total'],2)]);}
  $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) VALUES(?,?,?,'PEDIDO',?,'PEDIDO_GERADO',?,?,NOW(),?)")->execute([$companyId,(int)$c['id'],$generationId,$orderId,'Pedido #'.$orderId.' gerado para competência '.date('m/Y',strtotime($date)),$amount,$userId]);
  contracts_ensure_generation_receivable($pdo,$companyId,$generationId,$userId);
  try{if(contracts_has_column($pdo,'pedidos_exportacao','pedido_id'))$pdo->prepare('INSERT IGNORE INTO pedidos_exportacao(company_id,pedido_id) VALUES(?,?)')->execute([$companyId,$orderId]);}catch(Throwable $e){}
  try{require_once __DIR__.'/brain_profitability.php';if(function_exists('brain_profitability_recalculate_order'))brain_profitability_recalculate_order($pdo,$companyId,$orderId,true,'order.finalized');}catch(Throwable $e){}
  return$orderId;
}
function contracts_generate_selected(PDO $pdo,int $companyId,?int $userId,string $periodStart,string $periodEnd,array $keys,int $personId=0,string $billingMode='MANUAL'): array {
  $wanted=array_fill_keys(array_map('strval',$keys),true);
  $candidates=contracts_generation_candidates($pdo,$companyId,$periodStart,$periodEnd,$personId,$billingMode);
  $generated=[];$errors=[];$billingErrors=[];$autoQueue=[];$billingPrepared=0;$billingRegistered=0;

  // Primeiro grava todos os Pedidos e prepara as cobranças localmente. As chamadas
  // externas ao banco ficam para uma segunda etapa, evitando que uma API lenta impeça
  // a geração dos demais contratos do lote.
  foreach($candidates as $cand){
    if(!isset($wanted[$cand['key']]))continue;
    if($cand['generation']&&((int)($cand['generation']['order_id']??0)>0||strtoupper((string)$cand['generation']['status'])==='GERADO'))continue;
    try{
      $startedHere=!$pdo->inTransaction();
      if($startedHere)$pdo->beginTransaction();
      $oid=contracts_create_order($pdo,$companyId,$cand,$userId);
      // Alguns serviços reutilizados pelo Pedido (ex.: sequências) podem encerrar a
      // transação internamente. Só finalizamos se ela ainda estiver ativa.
      if($startedHere&&$pdo->inTransaction())$pdo->commit();
      $generatedRow=['key'=>$cand['key'],'order_id'=>$oid,'contract_number'=>$cand['contract']['contract_number']];
      $generated[]=$generatedRow;

      // O Contas a Receber ja foi criado para TODAS as formas de pagamento.
      // Somente o registro bancario continua condicionado ao boleto automatico.
      $resolved=billing_resolve_contract_method($pdo,$companyId,(array)$cand['contract']);
      $method=(array)($resolved['method']??[]);
      if($method && billing_payment_method_auto_register($method)){
        try{
          $gst=$pdo->prepare('SELECT id FROM contract_generations WHERE company_id=? AND contract_id=? AND competence=? AND order_id=? LIMIT 1');
          $gst->execute([$companyId,(int)$cand['contract']['id'],(string)$cand['competence'],$oid]);
          $generationId=(int)$gst->fetchColumn();
          if($generationId<=0) throw new RuntimeException('Geração do contrato não localizada após criar o Pedido.');
          $prepared=billing_prepare_boleto_for_generation($pdo,$companyId,$generationId,$userId);
          $charge=(array)($prepared['charge']??[]);
          $chargeId=(int)($charge['id']??0);
          if($chargeId>0){
            $billingPrepared++;
            $autoQueue[]=['charge_id'=>$chargeId,'order_id'=>$oid,'contract_number'=>$cand['contract']['contract_number']];
          }
        }catch(Throwable $billingPrepareError){
          $billingErrors[]='Pedido #'.$oid.' / Contrato #'.$cand['contract']['contract_number'].': '.$billingPrepareError->getMessage();
        }
      }
    }catch(Throwable $e){
      if(isset($startedHere)&&$startedHere&&$pdo->inTransaction())$pdo->rollBack();
      $errors[]='Contrato #'.$cand['contract']['contract_number'].' ('.date('d/m/Y',strtotime($cand['due_date'])).'): '.$e->getMessage();
    }
  }

  // Só depois de todos os pedidos locais estarem salvos, registra no banco.
  foreach($autoQueue as $q){
    $result=billing_auto_register_charge($pdo,$companyId,(int)$q['charge_id'],$userId);
    if(!empty($result['ok'])){
      if(empty($result['already']))$billingRegistered++;
      continue;
    }
    if(!empty($result['attempted'])){
      $billingErrors[]='Pedido #'.$q['order_id'].' / Contrato #'.$q['contract_number'].': '.((string)($result['error']??$result['message']??'Falha ao registrar boleto automaticamente.'));
    }
  }

  return ['generated'=>$generated,'errors'=>$errors,'billing_errors'=>$billingErrors,'billing_prepared'=>$billingPrepared,'billing_registered'=>$billingRegistered];
}
