<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/billing_module.php';
try{
  billing_ensure_schema($pdo);
  if(billing_table_exists($pdo,'finance_payment_methods')){
    $companies=$pdo->query('SELECT DISTINCT company_id FROM finance_payment_methods')->fetchAll(PDO::FETCH_COLUMN)?:[];
    foreach($companies as $cid){
      $st=$pdo->prepare("SELECT id FROM finance_payment_methods WHERE company_id=? AND (code='BOLETO' OR type='BOLETO') LIMIT 1");$st->execute([(int)$cid]);
      if(!$st->fetchColumn())$pdo->prepare("INSERT INTO finance_payment_methods(company_id,code,name,type,generate_receivable,allow_installments,default_installments,first_due_days,keep_due_day,affects_cash,require_open_cash,allow_change,is_active,sort_order,notes) VALUES(?,'BOLETO','Boleto','BOLETO',1,0,1,0,1,0,0,0,1,50,'Forma criada pelo módulo de cobrança bancária.')")->execute([(int)$cid]);
    }
  }
  echo "OK - Cobranças/Boleto Fase 3.1 instalado.\n";
}catch(Throwable $e){fwrite(STDERR,'ERRO: '.$e->getMessage()."\n");exit(1);}
