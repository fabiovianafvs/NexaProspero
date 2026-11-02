<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Cobranças bancárias / boletos.
 * Camada independente do banco. Cada finance_bank_account pode ter uma configuração
 * de cobrança própria (API/CNAB) e cada contrato escolhe a forma de pagamento.
 */
function billing_has_column(PDO $pdo,string $table,string $column): bool {
    $st=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $st->execute([$table,$column]);
    return (bool)$st->fetchColumn();
}
function billing_table_exists(PDO $pdo,string $table): bool {
    $st=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $st->execute([$table]); return (bool)$st->fetchColumn();
}
function billing_add_column(PDO $pdo,string $table,string $column,string $definition): void {
    if(!billing_has_column($pdo,$table,$column)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}
function billing_ensure_schema(PDO $pdo): void {
    // V11.25: o contexto F11 nunca cria/altera schema durante a venda. O banco já deve estar atualizado pelo Atualizador.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_bank_billing_configs (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      bank_account_id INT NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      provider VARCHAR(30) NOT NULL DEFAULT 'GENERIC',
      integration_mode VARCHAR(20) NOT NULL DEFAULT 'API',
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      bank_code VARCHAR(10) NULL,
      convenio VARCHAR(50) NULL,
      carteira VARCHAR(30) NULL,
      variacao VARCHAR(30) NULL,
      default_payment_method_id INT NULL,
      api_client_id VARCHAR(255) NULL,
      api_client_secret VARCHAR(500) NULL,
      cert_path VARCHAR(500) NULL,
      key_path VARCHAR(500) NULL,
      ca_path VARCHAR(500) NULL,
      oauth_url VARCHAR(500) NULL,
      api_base_url VARCHAR(500) NULL,
      cnab_layout VARCHAR(20) NULL,
      protest_days INT NOT NULL DEFAULT 0,
      fine_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
      interest_month_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
      instructions VARCHAR(500) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uk_bank_billing_account(company_id,bank_account_id),
      KEY idx_bank_billing_provider(company_id,provider,enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Campos específicos do Itaú/Webhook são adicionados de forma idempotente para
    // preservar instalações já existentes sem exigir SQL manual.
    billing_add_column($pdo,'finance_bank_billing_configs','itau_beneficiary_id','VARCHAR(32) NULL AFTER api_base_url');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_enabled','TINYINT(1) NOT NULL DEFAULT 0 AFTER itau_beneficiary_id');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_client_id','VARCHAR(120) NULL AFTER itau_webhook_enabled');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_client_secret','VARCHAR(255) NULL AFTER itau_webhook_client_id');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_url','VARCHAR(500) NULL AFTER itau_webhook_client_secret');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_oauth_url','VARCHAR(500) NULL AFTER itau_webhook_url');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_oauth_scope','VARCHAR(160) NULL AFTER itau_webhook_oauth_url');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_notifications','VARCHAR(120) NULL AFTER itau_webhook_oauth_scope');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_webhook_registered_at','DATETIME NULL AFTER itau_webhook_notifications');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_species_code',"VARCHAR(2) NOT NULL DEFAULT '01' AFTER itau_webhook_registered_at");
    billing_add_column($pdo,'finance_bank_billing_configs','itau_nosso_numero_next','INT UNSIGNED NULL AFTER itau_species_code');
    billing_add_column($pdo,'finance_bank_billing_configs','itau_bank_fee_chart_account_id','INT NULL AFTER itau_nosso_numero_next');

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_boleto_charges (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      contract_id BIGINT UNSIGNED NULL,
      generation_id BIGINT UNSIGNED NULL,
      order_id BIGINT NULL,
      receivable_id BIGINT NULL,
      bank_account_id INT NOT NULL,
      payment_method_id INT NULL,
      provider VARCHAR(30) NOT NULL DEFAULT 'GENERIC',
      integration_mode VARCHAR(20) NOT NULL DEFAULT 'API',
      status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE_REGISTRO',
      issue_date DATE NOT NULL,
      due_date DATE NOT NULL,
      amount DECIMAL(15,2) NOT NULL DEFAULT 0,
      nosso_numero VARCHAR(80) NULL,
      seu_numero VARCHAR(80) NULL,
      external_id VARCHAR(160) NULL,
      linha_digitavel VARCHAR(200) NULL,
      barcode VARCHAR(200) NULL,
      boleto_url VARCHAR(700) NULL,
      remittance_file VARCHAR(500) NULL,
      request_payload LONGTEXT NULL,
      response_payload LONGTEXT NULL,
      error_message VARCHAR(1000) NULL,
      registered_at DATETIME NULL,
      paid_at DATETIME NULL,
      created_by INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uk_boleto_generation(company_id,generation_id),
      KEY idx_boleto_company_status(company_id,status,due_date),
      KEY idx_boleto_order(company_id,order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    billing_add_column($pdo,'finance_boleto_charges','paid_amount','DECIMAL(15,2) NULL AFTER paid_at');
    billing_add_column($pdo,'finance_boleto_charges','payment_date','DATE NULL AFTER paid_amount');
    billing_add_column($pdo,'finance_boleto_charges','credit_date','DATE NULL AFTER payment_date');
    billing_add_column($pdo,'finance_boleto_charges','credited_amount','DECIMAL(15,2) NULL AFTER credit_date');
    billing_add_column($pdo,'finance_boleto_charges','last_webhook_at','DATETIME NULL AFTER credited_amount');
    billing_add_column($pdo,'finance_boleto_charges','last_webhook_payload','LONGTEXT NULL AFTER last_webhook_at');
    billing_add_column($pdo,'finance_boleto_charges','api_http_status','INT NULL AFTER last_webhook_payload');
    billing_add_column($pdo,'finance_boleto_charges','api_correlation_id','VARCHAR(80) NULL AFTER api_http_status');
    billing_add_column($pdo,'finance_boleto_charges','dac_titulo','VARCHAR(4) NULL AFTER api_correlation_id');
    billing_add_column($pdo,'finance_boleto_charges','pix_key','VARCHAR(180) NULL AFTER dac_titulo');
    billing_add_column($pdo,'finance_boleto_charges','pix_txid','VARCHAR(100) NULL AFTER pix_key');
    billing_add_column($pdo,'finance_boleto_charges','pix_emv','LONGTEXT NULL AFTER pix_txid');
    billing_add_column($pdo,'finance_boleto_charges','pix_qr_base64','LONGTEXT NULL AFTER pix_emv');
    billing_add_column($pdo,'finance_boleto_charges','pix_location_id','BIGINT NULL AFTER pix_qr_base64');
    billing_add_column($pdo,'finance_boleto_charges','pix_location','VARCHAR(500) NULL AFTER pix_location_id');
    billing_add_column($pdo,'finance_boleto_charges','simulation_request_payload','LONGTEXT NULL AFTER pix_location');
    billing_add_column($pdo,'finance_boleto_charges','simulation_response_payload','LONGTEXT NULL AFTER simulation_request_payload');
    billing_add_column($pdo,'finance_boleto_charges','simulation_http_status','INT NULL AFTER simulation_response_payload');
    billing_add_column($pdo,'finance_boleto_charges','simulated_at','DATETIME NULL AFTER simulation_http_status');
    billing_add_column($pdo,'finance_boleto_charges','canceled_at','DATETIME NULL AFTER simulated_at');
    billing_add_column($pdo,'finance_boleto_charges','cancellation_reason','VARCHAR(500) NULL AFTER canceled_at');
    billing_add_column($pdo,'finance_boleto_charges','cancellation_source','VARCHAR(40) NULL AFTER cancellation_reason');
    billing_add_column($pdo,'finance_boleto_charges','last_due_date_before_change','DATE NULL AFTER cancellation_source');
    billing_add_column($pdo,'finance_boleto_charges','due_date_updated_at','DATETIME NULL AFTER last_due_date_before_change');
    billing_add_column($pdo,'finance_boleto_charges','due_date_updated_by','INT NULL AFTER due_date_updated_at');

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_boleto_webhook_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      bank_account_id INT NOT NULL,
      boleto_charge_id BIGINT UNSIGNED NULL,
      nosso_numero VARCHAR(80) NULL,
      event_hash CHAR(64) NOT NULL,
      payload LONGTEXT NOT NULL,
      matched TINYINT(1) NOT NULL DEFAULT 0,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uk_boleto_webhook_event(event_hash),
      KEY idx_boleto_webhook_company(company_id,bank_account_id,received_at),
      KEY idx_boleto_webhook_charge(company_id,boleto_charge_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    billing_add_column($pdo,'finance_boleto_webhook_logs','processed','TINYINT(1) NOT NULL DEFAULT 0 AFTER matched');
    billing_add_column($pdo,'finance_boleto_webhook_logs','processing_status','VARCHAR(30) NULL AFTER processed');
    billing_add_column($pdo,'finance_boleto_webhook_logs','processing_message','VARCHAR(500) NULL AFTER processing_status');
    billing_add_column($pdo,'finance_boleto_webhook_logs','processed_at','DATETIME NULL AFTER processing_message');

    if(billing_table_exists($pdo,'finance_payment_methods')) {
        billing_add_column($pdo,'finance_payment_methods','auto_register_boleto','TINYINT(1) NOT NULL DEFAULT 0 AFTER generate_receivable');
    }
    if(billing_table_exists($pdo,'contracts')) billing_add_column($pdo,'contracts','payment_method_id','INT NULL AFTER bank_account_id');
    if(billing_table_exists($pdo,'contract_generations')) {
        billing_add_column($pdo,'contract_generations','payment_method_id','INT NULL AFTER order_id');
        billing_add_column($pdo,'contract_generations','bank_account_id','INT NULL AFTER payment_method_id');
        billing_add_column($pdo,'contract_generations','receivable_id','BIGINT NULL AFTER bank_account_id');
        billing_add_column($pdo,'contract_generations','boleto_charge_id','BIGINT NULL AFTER receivable_id');
    }
    if(billing_table_exists($pdo,'finance_receivables')) {
        billing_add_column($pdo,'finance_receivables','bank_account_id','INT NULL AFTER cost_center_id');
        billing_add_column($pdo,'finance_receivables','payment_method_id','INT NULL AFTER bank_account_id');
        billing_add_column($pdo,'finance_receivables','order_id','BIGINT NULL AFTER payment_method_id');
        billing_add_column($pdo,'finance_receivables','contract_generation_id','BIGINT NULL AFTER order_id');
        billing_add_column($pdo,'finance_receivables','boleto_charge_id','BIGINT NULL AFTER contract_generation_id');
    }
    if(billing_table_exists($pdo,'pedidos')) billing_add_column($pdo,'pedidos','payment_method_id','INT NULL');
}
function billing_bank_config(PDO $pdo,int $companyId,int $bankAccountId): array {
    if($bankAccountId<=0) return [];
    $st=$pdo->prepare('SELECT bc.*,b.name AS bank_account_name,b.bank_name,b.agency,b.account_number FROM finance_bank_billing_configs bc INNER JOIN finance_bank_accounts b ON b.company_id=bc.company_id AND b.id=bc.bank_account_id WHERE bc.company_id=? AND bc.bank_account_id=? LIMIT 1');
    $st->execute([$companyId,$bankAccountId]); return $st->fetch(PDO::FETCH_ASSOC)?:[];
}
function billing_payment_method(PDO $pdo,int $companyId,int $methodId): array {
    if($methodId<=0) return [];
    $st=$pdo->prepare('SELECT * FROM finance_payment_methods WHERE company_id=? AND id=? AND is_active=1 LIMIT 1');$st->execute([$companyId,$methodId]);return $st->fetch(PDO::FETCH_ASSOC)?:[];
}
function billing_resolve_contract_method(PDO $pdo,int $companyId,array $contract): array {
    $bankId=(int)($contract['bank_account_id']??0); $cfg=$bankId?billing_bank_config($pdo,$companyId,$bankId):[];
    $methodId=(int)($contract['payment_method_id']??0);
    if($methodId<=0 && $cfg) $methodId=(int)($cfg['default_payment_method_id']??0);
    $method=$methodId?billing_payment_method($pdo,$companyId,$methodId):[];
    return ['bank_id'=>$bankId,'config'=>$cfg,'method_id'=>$methodId,'method'=>$method];
}
function billing_is_boleto_method(array $method): bool {
    $type=strtoupper((string)($method['type']??''));$code=strtoupper((string)($method['code']??''));$name=mb_strtoupper((string)($method['name']??''),'UTF-8');
    return $type==='BOLETO'||$code==='BOLETO'||str_contains($name,'BOLETO');
}

function billing_payment_method_auto_register(array $method): bool {
    return billing_is_boleto_method($method) && !empty($method['auto_register_boleto']);
}

/**
 * Tenta registrar uma cobrança automaticamente no banco quando a forma de pagamento
 * estiver explicitamente configurada para isso.
 *
 * A falha bancária nunca desfaz o Pedido/Contrato já gravado: o status da cobrança
 * fica disponível na fila para conferência/reprocessamento seguro.
 */
function billing_auto_register_charge(PDO $pdo,int $companyId,int $chargeId,?int $userId=null): array {
    billing_ensure_schema($pdo);
    if($companyId<=0||$chargeId<=0) return ['attempted'=>false,'ok'=>false,'reason'=>'dados_incompletos'];
    $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$chargeId]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:[];
    if(!$charge) return ['attempted'=>false,'ok'=>false,'reason'=>'cobranca_nao_encontrada'];

    $methodId=(int)($charge['payment_method_id']??0);
    $method=$methodId>0?billing_payment_method($pdo,$companyId,$methodId):[];
    if(!$method||!billing_payment_method_auto_register($method)) return ['attempted'=>false,'ok'=>false,'reason'=>'registro_automatico_desabilitado','charge'=>$charge];

    $status=strtoupper(trim((string)($charge['status']??'')));
    if(in_array($status,['REGISTRADO','PAGO'],true)) return ['attempted'=>false,'ok'=>true,'already'=>true,'status'=>$status,'charge'=>$charge];
    if(!in_array($status,['AGUARDANDO_API','ERRO'],true)) return ['attempted'=>false,'ok'=>false,'reason'=>'status_nao_enviavel','status'=>$status,'charge'=>$charge];

    $provider=strtoupper(trim((string)($charge['provider']??'')));
    $mode=strtoupper(trim((string)($charge['integration_mode']??'')));
    if(!in_array($provider,['ITAU','SICOOB'],true)||$mode!=='API') return ['attempted'=>false,'ok'=>false,'reason'=>'provedor_sem_registro_automatico','provider'=>$provider,'mode'=>$mode,'charge'=>$charge];
    // Sicoob: desligado por padrão e apenas AGUARDANDO_API, nunca reprocessar ERRO/VERIFICAR_API.
    if ($provider==='SICOOB') {
        require_once __DIR__.'/sicoob_boleto_register.php';
        if (!sicoob_v17_auto_enabled($companyId)) {
            return ['attempted'=>false,'ok'=>false,'reason'=>'sicoob_registro_automatico_desabilitado','charge'=>$charge];
        }
        if ($status!=='AGUARDANDO_API') return ['attempted'=>false,'ok'=>false,'reason'=>'sicoob_reenvio_bloqueado','charge'=>$charge];
    }

    try {
        if ($provider==='SICOOB') $result=sicoob_v15_register($pdo,$companyId,$chargeId);
        else {
            require_once __DIR__.'/itau_bolecode.php';
            $result=itau_bolecode_register_charge($pdo,$companyId,$chargeId);
        }
        $newStatus=!empty($result['processing'])?'PROCESSANDO_API':'REGISTRADO';
        if((int)($charge['generation_id']??0)>0 && billing_table_exists($pdo,'contract_billing_history')) {
            try {
                $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) VALUES(?,?,?,'BOLETO',?,'BOLETO_REGISTRADO',?,?,NOW(),?)")
                    ->execute([$companyId,(int)($charge['contract_id']??0),(int)$charge['generation_id'],$chargeId,'Boleto registrado automaticamente no '.$provider.' para o Pedido #'.(int)($charge['order_id']??0).'.',(float)($charge['amount']??0),$userId]);
            } catch(Throwable $ignored) {}
        }
        return ['attempted'=>true,'ok'=>true,'status'=>$newStatus,'result'=>$result];
    } catch(Throwable $e) {
        error_log('[Nexa][Boleto Auto][Cobrança #'.$chargeId.'] '.$e->getMessage());
        $statusSt=$pdo->prepare('SELECT status,error_message FROM finance_boleto_charges WHERE company_id=? AND id=? LIMIT 1');
        $statusSt->execute([$companyId,$chargeId]);$after=$statusSt->fetch(PDO::FETCH_ASSOC)?:[];
        return ['attempted'=>true,'ok'=>false,'status'=>(string)($after['status']??$status),'error'=>$e->getMessage(),'message'=>(string)($after['error_message']??'')];
    }
}

/**
 * Prepara a cobrança bancária de uma parcela gerada por Pedido/PDV.
 * Não registra o boleto no banco; apenas cria a fila local.
 *
 * Segurança:
 * - somente formas de pagamento do tipo/código/nome Boleto;
 * - somente conta com cobrança habilitada;
 * - idempotente por receivable_id;
 * - quando executado dentro de transação, pressupõe que billing_ensure_schema()
 *   já tenha sido executado antes da abertura da transação para evitar DDL/commit implícito.
 */
function billing_prepare_boleto_for_order_receivable(
    PDO $pdo,
    int $companyId,
    int $orderId,
    int $receivableId,
    int $bankAccountId,
    int $paymentMethodId,
    ?int $userId=null,
    ?string $orderNumber=null
): array {
    if (!$pdo->inTransaction()) billing_ensure_schema($pdo);
    if ($companyId<=0 || $orderId<=0 || $receivableId<=0 || $bankAccountId<=0 || $paymentMethodId<=0) {
        return ['created'=>false,'reason'=>'dados_incompletos'];
    }

    $method=billing_payment_method($pdo,$companyId,$paymentMethodId);
    if (!$method || !billing_is_boleto_method($method)) {
        return ['created'=>false,'reason'=>'forma_nao_boleto'];
    }

    $cfg=billing_bank_config($pdo,$companyId,$bankAccountId);
    if (!$cfg || empty($cfg['enabled'])) {
        return ['created'=>false,'reason'=>'cobranca_bancaria_desabilitada'];
    }

    $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$receivableId]);
    $receivable=$st->fetch(PDO::FETCH_ASSOC)?:[];
    if (!$receivable) return ['created'=>false,'reason'=>'conta_receber_nao_encontrada'];

    $existingSt=$pdo->prepare('SELECT id,status FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? ORDER BY id DESC LIMIT 1');
    $existingSt->execute([$companyId,$receivableId]);
    $existing=$existingSt->fetch(PDO::FETCH_ASSOC)?:[];
    if ($existing) {
        $pdo->prepare('UPDATE finance_receivables SET bank_account_id=?,payment_method_id=?,order_id=?,boleto_charge_id=? WHERE company_id=? AND id=?')
            ->execute([$bankAccountId,$paymentMethodId,$orderId,(int)$existing['id'],$companyId,$receivableId]);
        return ['created'=>false,'existing'=>true,'charge_id'=>(int)$existing['id'],'status'=>(string)$existing['status']];
    }

    $amount=(float)($receivable['open_amount']??$receivable['amount']??0);
    if ($amount<=0) return ['created'=>false,'reason'=>'valor_sem_saldo'];

    $due=(string)($receivable['due_date']??'');
    if ($due==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) $due=date('Y-m-d');
    $issue=(string)($receivable['issue_date']??'');
    if ($issue==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$issue)) $issue=date('Y-m-d');

    $provider=strtoupper(trim((string)($cfg['provider']??'GENERIC')));
    $mode=strtoupper(trim((string)($cfg['integration_mode']??'API')));
    $status=$mode==='API'?'AGUARDANDO_API':'AGUARDANDO_REMESSA';

    $orderLabel=preg_replace('/\D+/','',(string)($orderNumber??'')) ?: (string)$orderId;
    $installment=trim((string)($receivable['installment']??''));
    $installmentCode=preg_replace('/[^0-9]+/','',$installment) ?: '01';
    $seuNumero='PED'.$orderLabel.'-'.$installmentCode;
    if (strlen($seuNumero)>80) $seuNumero=substr($seuNumero,0,80);

    $pdo->prepare('INSERT INTO finance_boleto_charges(company_id,contract_id,generation_id,order_id,receivable_id,bank_account_id,payment_method_id,provider,integration_mode,status,issue_date,due_date,amount,seu_numero,created_by) VALUES(?,NULL,NULL,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId,$orderId,$receivableId,$bankAccountId,$paymentMethodId,$provider,$mode,$status,$issue,$due,$amount,$seuNumero,$userId]);
    $chargeId=(int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE finance_receivables SET bank_account_id=?,payment_method_id=?,order_id=?,boleto_charge_id=? WHERE company_id=? AND id=?')
        ->execute([$bankAccountId,$paymentMethodId,$orderId,$chargeId,$companyId,$receivableId]);

    return ['created'=>true,'charge_id'=>$chargeId,'status'=>$status];
}

function billing_prepare_boleto_for_generation(PDO $pdo,int $companyId,int $generationId,?int $userId=null): array {
    billing_ensure_schema($pdo);
    $st=$pdo->prepare('SELECT g.*,c.contract_number,c.contracting_person_id,c.billing_person_id,c.chart_account_id,c.cost_center_id,c.bank_account_id,c.payment_method_id,c.contracting_person_name,c.billing_person_name FROM contract_generations g INNER JOIN contracts c ON c.company_id=g.company_id AND c.id=g.contract_id WHERE g.company_id=? AND g.id=? LIMIT 1');
    $st->execute([$companyId,$generationId]);$g=$st->fetch(PDO::FETCH_ASSOC)?:[]; if(!$g) throw new RuntimeException('Geração de contrato não encontrada.');
    if((int)($g['order_id']??0)<=0) throw new RuntimeException('A geração ainda não possui Pedido.');
    $resolved=billing_resolve_contract_method($pdo,$companyId,$g);$bankId=(int)$resolved['bank_id'];$cfg=$resolved['config'];$method=$resolved['method'];$methodId=(int)$resolved['method_id'];
    if($bankId<=0) throw new RuntimeException('Contrato sem conta bancária para cobrança.');
    if(!$cfg || empty($cfg['enabled'])) throw new RuntimeException('A conta bancária do contrato não está habilitada para cobrança/boleto.');
    if(!$methodId || !$method) throw new RuntimeException('Defina uma forma de pagamento no contrato ou uma forma padrão na configuração da conta bancária.');
    if(!billing_is_boleto_method($method)) throw new RuntimeException('A forma de pagamento configurada não é Boleto.');
    $due=(string)($g['due_date']??$g['competence']??date('Y-m-d'));$amount=(float)($g['amount']??0);if($amount<=0)throw new RuntimeException('Valor da cobrança inválido.');
    $existing=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND generation_id=? LIMIT 1');$existing->execute([$companyId,$generationId]);$charge=$existing->fetch(PDO::FETCH_ASSOC)?:null;
    if($charge && !in_array(strtoupper((string)$charge['status']),['ERRO','CANCELADO'],true)) return ['charge'=>$charge,'created'=>false,'message'=>'Boleto/cobrança já preparado para esta competência.'];
    $personId=(int)($g['billing_person_id']?:$g['contracting_person_id']);$chartId=(int)($g['chart_account_id']??0);if($chartId<=0)throw new RuntimeException('Informe a Natureza de lançamento no contrato para gerar o Contas a Receber.');
    $receivableId=(int)($g['receivable_id']??0);
    if($receivableId<=0){
      $desc='Contrato #'.$g['contract_number'].' - competência '.date('m/Y',strtotime((string)$g['competence']));
      $ins=$pdo->prepare("INSERT INTO finance_receivables(company_id,person_id,chart_account_id,cost_center_id,bank_account_id,payment_method_id,order_id,contract_generation_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,paid_amount,open_amount,status,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'Boleto',?,0,?,'ABERTO',?,NOW())");
      $ins->execute([$companyId,$personId,$chartId,(int)($g['cost_center_id']??0)?:null,$bankId,$methodId,(int)$g['order_id'],$generationId,$desc,'CTR-'.$g['contract_number'].'-'.date('Ym',strtotime((string)$g['competence'])),'1/1',date('Y-m-d'),$due,$amount,$amount,'Gerado pelo motor de recorrência de Contratos.']);
      $receivableId=(int)$pdo->lastInsertId();
    }
    $provider=strtoupper((string)($cfg['provider']??'GENERIC'));$mode=strtoupper((string)($cfg['integration_mode']??'API'));
    $status=$mode==='API'?'AGUARDANDO_API':'AGUARDANDO_REMESSA';
    $seuNumero='CTR'.str_pad((string)$g['contract_number'],6,'0',STR_PAD_LEFT).'-'.date('Ym',strtotime((string)$g['competence']));
    if($charge){$chargeId=(int)$charge['id'];$pdo->prepare("UPDATE finance_boleto_charges SET receivable_id=?,bank_account_id=?,payment_method_id=?,provider=?,integration_mode=?,status=?,issue_date=?,due_date=?,amount=?,seu_numero=?,error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$receivableId,$bankId,$methodId,$provider,$mode,$status,date('Y-m-d'),$due,$amount,$seuNumero,$companyId,$chargeId]);}
    else{$pdo->prepare('INSERT INTO finance_boleto_charges(company_id,contract_id,generation_id,order_id,receivable_id,bank_account_id,payment_method_id,provider,integration_mode,status,issue_date,due_date,amount,seu_numero,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$companyId,(int)$g['contract_id'],$generationId,(int)$g['order_id'],$receivableId,$bankId,$methodId,$provider,$mode,$status,date('Y-m-d'),$due,$amount,$seuNumero,$userId]);$chargeId=(int)$pdo->lastInsertId();}
    $pdo->prepare('UPDATE finance_receivables SET boleto_charge_id=? WHERE company_id=? AND id=?')->execute([$chargeId,$companyId,$receivableId]);
    $pdo->prepare('UPDATE contract_generations SET payment_method_id=?,bank_account_id=?,receivable_id=?,boleto_charge_id=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$methodId,$bankId,$receivableId,$chargeId,$companyId,$generationId]);
    try{$pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) VALUES(?,?,?,'BOLETO',?,'BOLETO_PREPARADO',?,?,NOW(),?)")->execute([$companyId,(int)$g['contract_id'],$generationId,$chargeId,'Cobrança de boleto preparada para o Pedido #'.$g['order_id'].' ('.$provider.' / '.$mode.').',$amount,$userId]);}catch(Throwable $ignored){}
    $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND id=?');$st->execute([$companyId,$chargeId]);
    return ['charge'=>$st->fetch(PDO::FETCH_ASSOC)?:[],'created'=>true,'message'=>$mode==='API'?'Cobrança preparada e aguardando registro pela API bancária.':'Cobrança preparada e aguardando geração de remessa.'];
}

/**
 * Gestão segura de cobranças antes/depois do registro bancário.
 *
 * O OpenAPI BoleCode v1.0.7 disponibilizado para esta integração expõe a emissão
 * em POST /boletos-pix, mas não expõe endpoint de manutenção/baixa/alteração do
 * boleto já registrado. Por isso, o Nexa permite operações locais apenas enquanto
 * a cobrança ainda não foi efetivada no banco e bloqueia divergências após registro.
 */
function billing_charge_local_cancel_statuses(): array {
    return ['AGUARDANDO_API','AGUARDANDO_REMESSA','ERRO'];
}

function billing_charge_bank_sensitive_statuses(): array {
    return ['ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API','VERIFICAR_PAGAMENTO','REGISTRADO','PAGO'];
}

function billing_charge_has_bank_identity(array $charge): bool {
    return trim((string)($charge['nosso_numero'] ?? '')) !== ''
        || trim((string)($charge['external_id'] ?? '')) !== ''
        || !empty($charge['registered_at'])
        || trim((string)($charge['linha_digitavel'] ?? '')) !== ''
        || trim((string)($charge['barcode'] ?? '')) !== '';
}

function billing_charge_local_cancel_message(array $charge): string {
    $status = strtoupper(trim((string)($charge['status'] ?? '')));
    if ($status === 'PAGO') return 'Este boleto já está pago e não pode ser cancelado pela rotina local.';
    if ($status === 'REGISTRADO' || billing_charge_has_bank_identity($charge)) {
        return 'Este boleto já foi registrado no banco. O cancelamento local foi bloqueado para não deixar um boleto ativo no Itaú enquanto a venda fica cancelada no Nexa. A especificação BoleCode v1.0.7 fornecida contém somente a emissão POST /boletos-pix e não publica a rota de baixa/cancelamento do título já registrado.';
    }
    if (in_array($status, ['ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API'], true)) {
        return 'Esta cobrança possui envio bancário em andamento ou situação incerta. Consulte o Itaú antes de cancelar para evitar duplicidade ou título ativo no banco.';
    }
    if ($status === 'VERIFICAR_PAGAMENTO') return 'Esta cobrança possui pagamento a verificar e não pode ser cancelada automaticamente.';
    return 'A cobrança não pode ser cancelada localmente no status atual: ' . ($status ?: 'SEM STATUS') . '.';
}

function billing_assert_charge_can_cancel_locally(array $charge): void {
    $status = strtoupper(trim((string)($charge['status'] ?? '')));
    if ($status === 'CANCELADO') return;
    if (!in_array($status, billing_charge_local_cancel_statuses(), true) || billing_charge_has_bank_identity($charge)) {
        throw new RuntimeException(billing_charge_local_cancel_message($charge));
    }
}

function billing_cancel_unregistered_charge(PDO $pdo,int $companyId,int $chargeId,string $reason='Cobrança cancelada.',string $source='MANUAL',bool $cancelReceivable=true,bool $skipSchemaEnsure=false): array {
    if (!$pdo->inTransaction() && !$skipSchemaEnsure) billing_ensure_schema($pdo);
    if ($companyId<=0 || $chargeId<=0) throw new InvalidArgumentException('Cobrança inválida para cancelamento.');
    $reason=trim($reason) ?: 'Cobrança cancelada.';
    $source=strtoupper(trim($source)) ?: 'MANUAL';
    if (function_exists('mb_substr')) {$reason=mb_substr($reason,0,500,'UTF-8');$source=mb_substr($source,0,40,'UTF-8');}
    else {$reason=substr($reason,0,500);$source=substr($source,0,40);}

    $started=!$pdo->inTransaction();
    if($started)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND id=? FOR UPDATE');
        $st->execute([$companyId,$chargeId]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if(!$charge) throw new RuntimeException('Cobrança não encontrada.');
        if(strtoupper((string)($charge['status']??''))==='CANCELADO'){
            if($started)$pdo->commit();
            return ['ok'=>true,'already'=>true,'charge_id'=>$chargeId,'receivable_id'=>(int)($charge['receivable_id']??0)];
        }
        billing_assert_charge_can_cancel_locally($charge);

        $receivableId=(int)($charge['receivable_id']??0);
        if($cancelReceivable && $receivableId>0 && billing_table_exists($pdo,'finance_receivables')){
            $sr=$pdo->prepare('SELECT id,status,COALESCE(paid_amount,0) paid_amount FROM finance_receivables WHERE company_id=? AND id=? FOR UPDATE');
            $sr->execute([$companyId,$receivableId]);$rec=$sr->fetch(PDO::FETCH_ASSOC)?:[];
            if($rec){
                $paid=(float)($rec['paid_amount']??0);
                if($paid>0.009 || strtoupper((string)($rec['status']??''))==='BAIXADO') throw new RuntimeException('A Conta a Receber vinculada já possui baixa/pagamento. O cancelamento automático foi bloqueado.');
                $set=["status='CANCELADO'"];
                if(billing_has_column($pdo,'finance_receivables','open_amount'))$set[]='open_amount=0';
                if(billing_has_column($pdo,'finance_receivables','canceled_at'))$set[]='canceled_at=COALESCE(canceled_at,NOW())';
                $params=[];
                if(billing_has_column($pdo,'finance_receivables','canceled_reason')){$set[]="canceled_reason=COALESCE(NULLIF(canceled_reason,''),?)";$params[]=$reason;}
                if(billing_has_column($pdo,'finance_receivables','updated_at'))$set[]='updated_at=NOW()';
                $params[]=$companyId;$params[]=$receivableId;
                $pdo->prepare('UPDATE finance_receivables SET '.implode(',',$set).' WHERE company_id=? AND id=?')->execute($params);
            }
        }

        $pdo->prepare("UPDATE finance_boleto_charges SET status='CANCELADO',canceled_at=COALESCE(canceled_at,NOW()),cancellation_reason=?,cancellation_source=?,error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$reason,$source,$companyId,$chargeId]);
        if($started)$pdo->commit();
        return ['ok'=>true,'already'=>false,'charge_id'=>$chargeId,'receivable_id'=>$receivableId];
    }catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function billing_order_charges(PDO $pdo,int $companyId,int $orderId,bool $forUpdate=false): array {
    if($companyId<=0||$orderId<=0||!billing_table_exists($pdo,'finance_boleto_charges'))return [];
    $sql='SELECT * FROM finance_boleto_charges WHERE company_id=? AND order_id=? ORDER BY id';
    if($forUpdate)$sql.=' FOR UPDATE';
    $st=$pdo->prepare($sql);$st->execute([$companyId,$orderId]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function billing_assert_order_can_cancel_locally(PDO $pdo,int $companyId,int $orderId): array {
    billing_ensure_schema($pdo);
    $charges=billing_order_charges($pdo,$companyId,$orderId,false);
    foreach($charges as $charge){
        $status=strtoupper(trim((string)($charge['status']??'')));
        if($status==='CANCELADO')continue;
        if(!in_array($status,billing_charge_local_cancel_statuses(),true) || billing_charge_has_bank_identity($charge)){
            throw new RuntimeException('O Pedido possui o boleto #'.(int)$charge['id'].' em situação '.$status.'. '.billing_charge_local_cancel_message($charge));
        }
    }
    return $charges;
}

function billing_cancel_order_unregistered_charges(PDO $pdo,int $companyId,int $orderId,string $reason,string $source='PEDIDO_CANCELADO'): array {
    if(!$pdo->inTransaction())billing_ensure_schema($pdo);
    $charges=billing_order_charges($pdo,$companyId,$orderId,$pdo->inTransaction());
    $count=0;
    foreach($charges as $charge){
        $status=strtoupper(trim((string)($charge['status']??'')));
        if($status==='CANCELADO')continue;
        billing_assert_charge_can_cancel_locally($charge);
        billing_cancel_unregistered_charge($pdo,$companyId,(int)$charge['id'],$reason,$source,true);
        $count++;
    }
    return ['ok'=>true,'canceled'=>$count];
}

function billing_receivable_charge(PDO $pdo,int $companyId,int $receivableId,bool $forUpdate=false): array {
    if($companyId<=0||$receivableId<=0||!billing_table_exists($pdo,'finance_boleto_charges'))return [];
    $sql='SELECT * FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? ORDER BY id DESC LIMIT 1';
    if($forUpdate)$sql.=' FOR UPDATE';
    $st=$pdo->prepare($sql);$st->execute([$companyId,$receivableId]);return $st->fetch(PDO::FETCH_ASSOC)?:[];
}

function billing_assert_receivable_due_date_change(PDO $pdo,int $companyId,int $receivableId,string $newDue): array {
    billing_ensure_schema($pdo);
    $charge=billing_receivable_charge($pdo,$companyId,$receivableId,false);
    if(!$charge)return [];
    $old=(string)($charge['due_date']??'');
    if($old===$newDue)return $charge;
    $status=strtoupper(trim((string)($charge['status']??'')));
    if($status==='CANCELADO')return $charge;
    if(in_array($status,billing_charge_local_cancel_statuses(),true) && !billing_charge_has_bank_identity($charge))return $charge;
    if($status==='PAGO')throw new RuntimeException('O vencimento não pode ser alterado porque o boleto vinculado já está pago.');
    throw new RuntimeException('O vencimento não foi alterado. O boleto vinculado já possui comunicação/registro bancário (status '.$status.'). A API BoleCode v1.0.7 fornecida não publica endpoint para alterar o vencimento de um boleto já registrado; o Nexa bloqueia a mudança local para não divergir do Itaú.');
}

function billing_sync_receivable_due_date(PDO $pdo,int $companyId,int $receivableId,string $newDue,?int $userId=null): array {
    if(!$pdo->inTransaction())billing_ensure_schema($pdo);
    $charge=billing_receivable_charge($pdo,$companyId,$receivableId,$pdo->inTransaction());
    if(!$charge)return ['updated'=>false,'reason'=>'sem_boleto'];
    $old=(string)($charge['due_date']??'');
    if($old===$newDue)return ['updated'=>false,'reason'=>'sem_alteracao','charge_id'=>(int)$charge['id']];
    $status=strtoupper(trim((string)($charge['status']??'')));
    if($status==='CANCELADO')return ['updated'=>false,'reason'=>'boleto_cancelado','charge_id'=>(int)$charge['id']];
    if(!in_array($status,billing_charge_local_cancel_statuses(),true) || billing_charge_has_bank_identity($charge)){
        billing_assert_receivable_due_date_change($pdo,$companyId,$receivableId,$newDue);
    }
    $pdo->prepare('UPDATE finance_boleto_charges SET last_due_date_before_change=due_date,due_date=?,due_date_updated_at=NOW(),due_date_updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$newDue,$userId,$companyId,(int)$charge['id']]);
    return ['updated'=>true,'charge_id'=>(int)$charge['id'],'old_due_date'=>$old,'new_due_date'=>$newDue];
}

function billing_cancel_receivable_with_pending_charge(PDO $pdo,int $companyId,int $receivableId,string $reason='Título cancelado.',string $source='CONTAS_RECEBER',bool $skipSchemaEnsure=false): array {
    if (!$skipSchemaEnsure) billing_ensure_schema($pdo);
    $charge=billing_receivable_charge($pdo,$companyId,$receivableId,false);
    if($charge && strtoupper((string)($charge['status']??''))!=='CANCELADO'){
        return billing_cancel_unregistered_charge($pdo,$companyId,(int)$charge['id'],$reason,$source,true,$skipSchemaEnsure);
    }
    $pdo->prepare("UPDATE finance_receivables SET status='CANCELADO',open_amount=0,updated_at=NOW() WHERE company_id=? AND id=? AND COALESCE(paid_amount,0)<=0.009")
        ->execute([$companyId,$receivableId]);
    return ['ok'=>true,'charge_id'=>(int)($charge['id']??0),'receivable_id'=>$receivableId];
}

