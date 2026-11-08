<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/finance_module.php';

if (!function_exists('itau_webhook_base64url_encode')) {
    function itau_webhook_base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('itau_webhook_base64url_decode')) {
    function itau_webhook_base64url_decode(string $value): string|false {
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}

if (!function_exists('itau_webhook_default_public_base')) {
    function itau_webhook_default_public_base(): string {
        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
        $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'app.nexaprospero.com.br'));
        if (str_contains($host, ',')) $host = trim(explode(',', $host)[0]);
        return $scheme . '://' . $host;
    }
}

if (!function_exists('itau_webhook_default_urls')) {
    function itau_webhook_default_urls(): array {
        $base = rtrim(itau_webhook_default_public_base(), '/');
        return [
            'webhook_url' => $base . '/webhooks/itau_boletos.php',
            'webhook_oauth_url' => $base . '/webhooks/itau_oauth.php',
        ];
    }
}

if (!function_exists('itau_webhook_uuid')) {
    function itau_webhook_uuid(): string {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}

if (!function_exists('itau_webhook_prepare_credentials')) {
    function itau_webhook_prepare_credentials(PDO $pdo, int $companyId, int $bankAccountId): array {
        billing_ensure_schema($pdo);
        $cfg = billing_bank_config($pdo,$companyId,$bankAccountId);
        if (!$cfg) throw new RuntimeException('Configuração bancária não encontrada.');
        $clientId = trim((string)($cfg['itau_webhook_client_id'] ?? '')) ?: itau_webhook_uuid();
        $secret = trim((string)($cfg['itau_webhook_client_secret'] ?? '')) ?: bin2hex(random_bytes(32));
        $urls = itau_webhook_default_urls();
        $webhookUrl = trim((string)($cfg['itau_webhook_url'] ?? '')) ?: $urls['webhook_url'];
        $oauthUrl = trim((string)($cfg['itau_webhook_oauth_url'] ?? '')) ?: $urls['webhook_oauth_url'];
        $notifications = trim((string)($cfg['itau_webhook_notifications'] ?? '')) ?: 'BAIXA_EFETIVA,BAIXA_OPERACIONAL';
        $st=$pdo->prepare('UPDATE finance_bank_billing_configs SET itau_webhook_client_id=?,itau_webhook_client_secret=?,itau_webhook_url=?,itau_webhook_oauth_url=?,itau_webhook_notifications=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?');
        $st->execute([$clientId,$secret,$webhookUrl,$oauthUrl,$notifications,$companyId,$bankAccountId]);
        return billing_bank_config($pdo,$companyId,$bankAccountId);
    }
}

if (!function_exists('itau_webhook_parse_basic_auth')) {
    function itau_webhook_parse_basic_auth(): array {
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($auth === '' && isset($_SERVER['PHP_AUTH_USER'])) return [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')];
        if (!preg_match('/^Basic\s+(.+)$/i',$auth,$m)) return ['',''];
        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) return ['',''];
        return explode(':',$decoded,2);
    }
}

if (!function_exists('itau_webhook_issue_token')) {
    function itau_webhook_issue_token(array $cfg, int $ttl = 300): array {
        $clientId = trim((string)($cfg['itau_webhook_client_id'] ?? ''));
        $secret = trim((string)($cfg['itau_webhook_client_secret'] ?? ''));
        if ($clientId === '' || $secret === '') throw new RuntimeException('Credenciais internas do webhook não configuradas.');
        $now=time();$exp=$now+max(60,min(900,$ttl));
        $payload=json_encode([
            'cid'=>(int)($cfg['company_id']??0),
            'bid'=>(int)($cfg['bank_account_id']??0),
            'sub'=>$clientId,
            'iat'=>$now,
            'exp'=>$exp,
            'jti'=>bin2hex(random_bytes(10)),
        ],JSON_UNESCAPED_SLASHES);
        if ($payload===false) throw new RuntimeException('Falha ao gerar token do webhook.');
        $body=itau_webhook_base64url_encode($payload);
        $sig=itau_webhook_base64url_encode(hash_hmac('sha256',$body,$secret,true));
        return ['access_token'=>$body.'.'.$sig,'token_type'=>'Bearer','expires_in'=>$exp-$now];
    }
}

if (!function_exists('itau_webhook_validate_bearer')) {
    function itau_webhook_validate_bearer(PDO $pdo, string $token): array {
        $parts=explode('.',$token,2);
        if(count($parts)!==2) throw new RuntimeException('Token webhook inválido.');
        $payloadRaw=itau_webhook_base64url_decode($parts[0]);
        if($payloadRaw===false) throw new RuntimeException('Token webhook inválido.');
        $payload=json_decode($payloadRaw,true);
        if(!is_array($payload)) throw new RuntimeException('Token webhook inválido.');
        $companyId=(int)($payload['cid']??0);$bankId=(int)($payload['bid']??0);
        if($companyId<=0||$bankId<=0||(int)($payload['exp']??0)<time()) throw new RuntimeException('Token webhook expirado ou inválido.');
        $cfg=billing_bank_config($pdo,$companyId,$bankId);
        if(!$cfg || empty($cfg['itau_webhook_enabled'])) throw new RuntimeException('Webhook Itaú não habilitado.');
        $secret=(string)($cfg['itau_webhook_client_secret']??'');
        $expected=itau_webhook_base64url_encode(hash_hmac('sha256',$parts[0],$secret,true));
        if(!hash_equals($expected,$parts[1])) throw new RuntimeException('Assinatura do token webhook inválida.');
        if(!hash_equals((string)($cfg['itau_webhook_client_id']??''),(string)($payload['sub']??''))) throw new RuntimeException('Cliente do token webhook inválido.');
        return $cfg;
    }
}

if (!function_exists('itau_webhook_find_bearer')) {
    function itau_webhook_find_bearer(): string {
        $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        return preg_match('/^Bearer\s+(.+)$/i',$auth,$m) ? trim($m[1]) : '';
    }
}

if (!function_exists('itau_webhook_normalized_key')) {
    function itau_webhook_normalized_key(string $key): string {
        return strtolower((string)(preg_replace('/[^a-zA-Z0-9]+/','',$key) ?? ''));
    }
}

if (!function_exists('itau_webhook_flatten_values')) {
    function itau_webhook_flatten_values(array $data, array &$out): void {
        foreach ($data as $key=>$value) {
            if (is_array($value)) {
                itau_webhook_flatten_values($value,$out);
                continue;
            }
            $nk=itau_webhook_normalized_key((string)$key);
            if ($nk!=='' && !array_key_exists($nk,$out)) $out[$nk]=$value;
        }
    }
}

if (!function_exists('itau_webhook_pick')) {
    function itau_webhook_pick(array $item, array $keys, mixed $default=null): mixed {
        $flat=[];itau_webhook_flatten_values($item,$flat);
        foreach($keys as $key){$nk=itau_webhook_normalized_key((string)$key);if($nk!==''&&array_key_exists($nk,$flat))return $flat[$nk];}
        return $default;
    }
}

if (!function_exists('itau_webhook_money')) {
    function itau_webhook_money(mixed $value): float {
        if (is_int($value)||is_float($value)) return round((float)$value,2);
        $s=trim((string)$value);if($s==='')return 0.0;
        $s=preg_replace('/[^0-9,.-]+/','',$s)??'';
        if(str_contains($s,',')&&str_contains($s,'.')){$s=str_replace('.','',$s);$s=str_replace(',','.',$s);}
        elseif(str_contains($s,',')){$s=str_replace(',','.',$s);}
        return is_numeric($s)?round((float)$s,2):0.0;
    }
}

if (!function_exists('itau_webhook_date')) {
    function itau_webhook_date(mixed $value): ?string {
        $s=trim((string)$value);if($s==='')return null;
        foreach(['!Y-m-d','!d/m/Y','!Ymd'] as $fmt){$d=DateTimeImmutable::createFromFormat($fmt,$s);if($d&&$d->format(str_replace('!','',$fmt))===$s)return $d->format('Y-m-d');}
        if(preg_match('/^(\d{4}-\d{2}-\d{2})[T\s]/',$s,$m))return $m[1];
        return null;
    }
}

if (!function_exists('itau_webhook_payment_data')) {
    function itau_webhook_payment_data(array $item): array {
        $paid=itau_webhook_money(itau_webhook_pick($item,['valorPagoTotalCobranca','valor_pago_total_cobranca','valorPago','valorPagamento']));
        $credited=itau_webhook_money(itau_webhook_pick($item,['valorCreditado','valor_creditado']));
        if($paid<=0 && $credited>0)$paid=$credited;
        $title=itau_webhook_money(itau_webhook_pick($item,['valorTitulo','valor_titulo']));
        $fine=abs(itau_webhook_money(itau_webhook_pick($item,['valorMulta','valorMultaPago','valor_multa'])));
        $interest=abs(itau_webhook_money(itau_webhook_pick($item,['valorJuros','valorJurosMora','valorJurosPago','valor_juros'])));
        $otherExpenses=abs(itau_webhook_money(itau_webhook_pick($item,['valorOutrasDespesas','valor_outras_despesas','outrasDespesas'])));
        $discount=abs(itau_webhook_money(itau_webhook_pick($item,['valorDesconto','valorDescontoConcedido','valor_desconto'])));
        $bankFee=abs(itau_webhook_money(itau_webhook_pick($item,['bankFeeAmount','valorTarifaBancaria','valor_tarifa_bancaria'])));
        $bankFeeDescription=trim((string)itau_webhook_pick($item,['bankFeeDescription','descricaoTarifaBancaria','descricao_tarifa_bancaria']));
        $paymentDate=itau_webhook_date(itau_webhook_pick($item,['dataInclusaoPagamento','dataPagamento','data_pagamento']));
        $creditDate=itau_webhook_date(itau_webhook_pick($item,['dataCredito','data_credito']));
        $notificationDate=itau_webhook_date(itau_webhook_pick($item,['dataNotificacao','data_notificacao']));
        // Baixa e movimento na data em que o valor entrou no extrato (igual ao Sicoob).
        // Sem data de crédito (webhook no momento do pagamento), usa a data do pagamento.
        $settlementDate=$creditDate?:$paymentDate?:$notificationDate?:date('Y-m-d');
        $movementDate=$creditDate?:$paymentDate?:$notificationDate?:date('Y-m-d');
        return [
            'paid_amount'=>$paid,
            'credited_amount'=>$credited,
            'title_amount'=>$title,
            'fine_amount'=>$fine,
            'interest_amount'=>$interest,
            'other_expenses_amount'=>$otherExpenses,
            'discount_amount'=>$discount,
            'bank_fee_amount'=>$bankFee,
            'bank_fee_description'=>$bankFeeDescription,
            'payment_date'=>$paymentDate,
            'credit_date'=>$creditDate,
            'notification_date'=>$notificationDate,
            'settlement_date'=>$settlementDate,
            'movement_date'=>$movementDate,
            'liquidation_type'=>trim((string)itau_webhook_pick($item,['tipoLiquidacao','tipo_liquidacao'])),
        ];
    }
}

if (!function_exists('itau_webhook_find_charge')) {
    function itau_webhook_find_charge(PDO $pdo,int $companyId,int $bankId,string $nosso): ?array {
        $nosso=trim($nosso);if($nosso==='')return null;
        $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND bank_account_id=? AND nosso_numero=? ORDER BY id DESC LIMIT 1');
        $st->execute([$companyId,$bankId,$nosso]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$charge && ctype_digit($nosso)){
            $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND bank_account_id=? AND CAST(nosso_numero AS UNSIGNED)=? ORDER BY id DESC LIMIT 1');
            $st->execute([$companyId,$bankId,(int)$nosso]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:null;
        }
        return $charge;
    }
}

if (!function_exists('itau_webhook_store_event')) {
    function itau_webhook_store_event(PDO $pdo, array $cfg, array $item, string $rawPayload): array {
        if (empty($cfg['_nexa_skip_schema_ensure'])) billing_ensure_schema($pdo);
        elseif (!billing_table_exists($pdo,'finance_boleto_webhook_logs')) throw new RuntimeException('Estrutura de auditoria Itaú incompleta. Aplique as migrations pelo Atualizador do Banco.');
        $companyId=(int)$cfg['company_id'];$bankId=(int)$cfg['bank_account_id'];
        $nosso=trim((string)itau_webhook_pick($item,['numeroNossoNumero','numero_nosso_numero','nossoNumero','nosso_numero']));
        $charge=itau_webhook_find_charge($pdo,$companyId,$bankId,$nosso);
        $hash=hash('sha256',$companyId.'|'.$bankId.'|'.json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $logId=0;$duplicate=false;$alreadyProcessed=false;$previousStatus='';$previousMessage='';
        try{
            $pdo->prepare('INSERT INTO finance_boleto_webhook_logs(company_id,bank_account_id,boleto_charge_id,nosso_numero,event_hash,payload,matched) VALUES(?,?,?,?,?,?,?)')
                ->execute([$companyId,$bankId,$charge?(int)$charge['id']:null,$nosso?:null,$hash,$rawPayload,$charge?1:0]);
            $logId=(int)$pdo->lastInsertId();
        }catch(PDOException $e){
            if((string)$e->getCode()!=='23000') throw $e;
            $duplicate=true;
            $st=$pdo->prepare('SELECT id,boleto_charge_id,matched,processed,processing_status,processing_message FROM finance_boleto_webhook_logs WHERE event_hash=? LIMIT 1');
            $st->execute([$hash]);$existing=$st->fetch(PDO::FETCH_ASSOC)?:[];
            $logId=(int)($existing['id']??0);
            $alreadyProcessed=!empty($existing['processed']);
            $previousStatus=trim((string)($existing['processing_status']??''));
            $previousMessage=trim((string)($existing['processing_message']??''));
            if($charge && $logId>0 && (empty($existing['matched']) || (int)($existing['boleto_charge_id']??0)<=0)){
                $pdo->prepare('UPDATE finance_boleto_webhook_logs SET boleto_charge_id=?,nosso_numero=?,matched=1 WHERE id=?')
                    ->execute([(int)$charge['id'],$nosso?:null,$logId]);
            }
        }
        if($charge){
            $externalId=trim((string)itau_webhook_pick($item,['idBoleto','id_boleto']));$barcode=trim((string)itau_webhook_pick($item,['codigoBarras','codigo_barras']));$linha=trim((string)itau_webhook_pick($item,['numeroLinhaDigitavel','numero_linha_digitavel']));
            $pdo->prepare('UPDATE finance_boleto_charges SET external_id=COALESCE(NULLIF(external_id,\'\'),?),barcode=COALESCE(NULLIF(barcode,\'\'),?),linha_digitavel=COALESCE(NULLIF(linha_digitavel,\'\'),?),last_webhook_at=NOW(),last_webhook_payload=?,updated_at=NOW() WHERE company_id=? AND id=?')
                ->execute([$externalId?:null,$barcode?:null,$linha?:null,$rawPayload,$companyId,(int)$charge['id']]);
        }
        return [
            'matched'=>(bool)$charge,
            'charge_id'=>$charge?(int)$charge['id']:null,
            'nosso_numero'=>$nosso,
            'log_id'=>$logId,
            'duplicate'=>$duplicate,
            'already_processed'=>$alreadyProcessed,
            'previous_status'=>$previousStatus,
            'previous_message'=>$previousMessage,
        ];
    }
}

if (!function_exists('itau_webhook_log_processing')) {
    function itau_webhook_log_processing(PDO $pdo,int $logId,bool $processed,string $status,string $message=''): void {
        if($logId<=0)return;
        $msg=trim($message);if(function_exists('mb_substr'))$msg=mb_substr($msg,0,500,'UTF-8');else$msg=substr($msg,0,500);
        $pdo->prepare('UPDATE finance_boleto_webhook_logs SET processed=?,processing_status=?,processing_message=?,processed_at='.($processed?'NOW()':'NULL').' WHERE id=?')
            ->execute([$processed?1:0,$status?:null,$msg?:null,$logId]);
    }
}

if (!function_exists('itau_webhook_bank_fee_chart')) {
    function itau_webhook_bank_fee_chart(PDO $pdo, array $cfg): ?array {
        $companyId=(int)($cfg['company_id']??0);$chartId=(int)($cfg['itau_bank_fee_chart_account_id']??0);
        if($companyId<=0||$chartId<=0)return null;
        $st=$pdo->prepare("SELECT id,code,name,account_type,movement_type,is_analytic,active FROM finance_chart_accounts WHERE company_id=? AND id=? LIMIT 1");
        $st->execute([$companyId,$chartId]);$row=$st->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$row||empty($row['active'])||empty($row['is_analytic']))return null;
        $mv=strtoupper(trim((string)($row['movement_type']??'')));if(!in_array($mv,['DEBITO','AMBOS'],true))return null;
        return $row;
    }
}

if (!function_exists('itau_webhook_create_bank_fee_movement')) {
    function itau_webhook_create_bank_fee_movement(PDO $pdo,array $cfg,array $charge,array $title,float $fee,string $date,string $description=''): int {
        $fee=round(max(0.0,$fee),2);if($fee<=0.009)return 0;
        $companyId=(int)$cfg['company_id'];$bankId=(int)$cfg['bank_account_id'];$chargeId=(int)$charge['id'];
        $st=$pdo->prepare("SELECT id FROM finance_movements WHERE company_id=? AND origin_type='BOLETO_TARIFA' AND origin_id=? AND status='ATIVO' ORDER BY id DESC LIMIT 1");
        $st->execute([$companyId,$chargeId]);$existing=(int)($st->fetchColumn()?:0);if($existing>0)return $existing;
        $chart=itau_webhook_bank_fee_chart($pdo,$cfg);
        if(!$chart)throw new RuntimeException('A liquidação possui tarifa bancária. Configure o plano de contas para tarifas bancárias na Etapa 4 antes de sincronizar a baixa.');
        $nosso=trim((string)($charge['nosso_numero']??''));$desc='Tarifa bancária Itaú'.($nosso!==''?' · Nosso Número '.$nosso:'');
        if(trim($description)!=='')$desc.=' · '.trim($description);
        $notes='Tarifa descontada pelo Itaú na liquidação da cobrança #'.$chargeId.'.';
        $ins=$pdo->prepare("INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes,status) VALUES (?,?,'DEBITO',NULL,?,?,?,'BOLETO_TARIFA',?,?,?,?,?,'ATIVO')");
        $ins->execute([$companyId,$date,(int)$chart['id'],(int)($title['cost_center_id']??0)?:null,$bankId,$chargeId,$desc,(string)($title['document_number']??''),$fee,$notes]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('itau_webhook_reconcile_existing_bank_fee')) {
    function itau_webhook_reconcile_existing_bank_fee(PDO $pdo,array $cfg,array $charge,float $fee,string $date,string $description=''): int {
        $fee=round(max(0.0,$fee),2);
        $receivableId=(int)($charge['receivable_id']??0);
        if($fee<=0.009||$receivableId<=0)return 0;
        $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([(int)$cfg['company_id'],$receivableId]);
        $title=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if(!$title)return 0;
        return itau_webhook_create_bank_fee_movement($pdo,$cfg,$charge,$title,$fee,$date,$description);
    }
}

if (!function_exists('itau_webhook_align_settlement_dates')) {
    /**
     * Baixa Itaú já feita (ex.: pelo webhook no dia do pagamento) com data diferente
     * da data em que o valor entrou no extrato (Francesinha): acerta a data da baixa,
     * do movimento financeiro e da tarifa. Só mexe em baixas automáticas do Itaú
     * desta cobrança, ativas e NÃO conciliadas. Deve rodar dentro da transação.
     */
    function itau_webhook_align_settlement_dates(PDO $pdo,array $cfg,array $charge,string $creditDate): int {
        $companyId=(int)($cfg['company_id']??0);$chargeId=(int)($charge['id']??0);$receivableId=(int)($charge['receivable_id']??0);
        if($companyId<=0||$chargeId<=0||itau_webhook_date($creditDate)!==$creditDate)return 0;
        $changed=0;
        if($receivableId>0){
            $statusSql=finance_column_exists($pdo,'finance_settlements','status')?" AND COALESCE(status,'ATIVO')='ATIVO'":'';
            $st=$pdo->prepare("SELECT id,settlement_date,movement_id FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=? AND notes LIKE ?".$statusSql.' FOR UPDATE');
            $st->execute([$companyId,$receivableId,'Baixa automática via Webhook Itaú. Cobrança #'.$chargeId.' %']);
            $hasRec=finance_table_exists($pdo,'finance_bank_reconciliation_items');
            $recSettlement=$hasRec&&finance_column_exists($pdo,'finance_bank_reconciliation_items','settlement_id');
            $recMovement=$hasRec&&finance_column_exists($pdo,'finance_bank_reconciliation_items','movement_id');
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
                $settlementId=(int)$row['id'];$movementId=(int)($row['movement_id']??0);
                if($recSettlement||$recMovement){
                    $parts=[];$params=[$companyId];
                    if($recSettlement){$parts[]='settlement_id=?';$params[]=$settlementId;}
                    if($recMovement&&$movementId>0){$parts[]='movement_id=?';$params[]=$movementId;}
                    $q=$pdo->prepare("SELECT COUNT(*) FROM finance_bank_reconciliation_items WHERE company_id=? AND status='CONCILIADO' AND (".implode(' OR ',$parts).')');
                    $q->execute($params);if((int)$q->fetchColumn()>0)continue; // conciliado: não altera
                }
                if((string)$row['settlement_date']!==$creditDate){
                    $pdo->prepare('UPDATE finance_settlements SET settlement_date=? WHERE company_id=? AND id=?')->execute([$creditDate,$companyId,$settlementId]);
                    $changed++;
                }
                if($movementId>0){
                    $up=$pdo->prepare("UPDATE finance_movements SET movement_date=? WHERE company_id=? AND id=? AND origin_type='RECEBER' AND movement_date<>?");
                    $up->execute([$creditDate,$companyId,$movementId,$creditDate]);$changed+=$up->rowCount();
                }
            }
        }
        $fee=$pdo->prepare("UPDATE finance_movements SET movement_date=? WHERE company_id=? AND origin_type='BOLETO_TARIFA' AND origin_id=? AND status='ATIVO' AND movement_date<>?");
        $fee->execute([$creditDate,$companyId,$chargeId,$creditDate]);$changed+=$fee->rowCount();
        $pdo->prepare('UPDATE finance_boleto_charges SET credit_date=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$creditDate,$companyId,$chargeId]);
        return $changed;
    }
}

if (!function_exists('itau_webhook_auto_settle_charge')) {
    function itau_webhook_auto_settle_charge(PDO $pdo,array $cfg,int $chargeId,array $item): array {
        if (empty($cfg['_nexa_skip_schema_ensure'])) {
            billing_ensure_schema($pdo);finance_ensure_schema($pdo);
        } else {
            foreach (['finance_boleto_charges','finance_boleto_webhook_logs','finance_receivables','finance_settlements','finance_movements','finance_bank_accounts'] as $requiredTable) {
                if (!finance_table_exists($pdo, $requiredTable)) throw new RuntimeException('Estrutura financeira incompleta para sincronizar liquidações: tabela ' . $requiredTable . ' ausente. Aplique as migrations pelo Atualizador do Banco.');
            }
        }
        $companyId=(int)$cfg['company_id'];$bankId=(int)$cfg['bank_account_id'];
        $payment=itau_webhook_payment_data($item);$paid=round((float)$payment['paid_amount'],2);$bankFee=round(max(0.0,(float)($payment['bank_fee_amount']??0)),2);$bankFeeDescription=trim((string)($payment['bank_fee_description']??''));
        if($paid<=0.0)return ['processed'=>false,'status'=>'SEM_VALOR_PAGO','message'=>'Notificação recebida sem valor pago maior que zero.'];

        $pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND bank_account_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId,$bankId,$chargeId]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:[];
            if(!$charge)throw new RuntimeException('Cobrança bancária não encontrada durante a baixa.');
            $receivableId=(int)($charge['receivable_id']??0);
            $settlementDate=(string)$payment['settlement_date'];
            $movementDate=(string)$payment['movement_date'];
            $paymentDate=(string)($payment['payment_date']?:$settlementDate);
            $isFrancesinha=(string)($item['_nexa_source']??'')==='API_BOLETOS_V3_MOVIMENTACOES';
            $creditDate=($payment['credit_date']??null) ?: ($isFrancesinha ? null : $movementDate);
            // Francesinha: crédito = data em que o valor foi lançado na conta (dataCredito
            // montado em itau_webhook_event_from_movement). Webhook sem dataCredito mantém
            // o fallback anterior; a próxima sincronização da Francesinha acerta a data.
            $credited=round((float)($payment['credited_amount']?:$paid),2);
            $paidAt=$paymentDate.' 00:00:00';

            if(strtoupper((string)($charge['status']??''))==='PAGO'){
                $feeMovementId=itau_webhook_reconcile_existing_bank_fee($pdo,$cfg,$charge,$bankFee,$movementDate,$bankFeeDescription);
                $pdo->prepare('UPDATE finance_boleto_charges SET paid_amount=COALESCE(paid_amount,?),payment_date=COALESCE(payment_date,?),credit_date=COALESCE(credit_date,?),credited_amount=COALESCE(credited_amount,?),paid_at=COALESCE(paid_at,?),last_webhook_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute([$paid,$paymentDate,$creditDate,$credited,$paidAt,$companyId,$chargeId]);
                $aligned=($isFrancesinha&&$creditDate)?itau_webhook_align_settlement_dates($pdo,$cfg,$charge,(string)$creditDate):0;
                $pdo->commit();
                $msg=$feeMovementId>0?'Cobrança já estava paga; tarifa bancária conferida/conciliada sem gerar nova baixa.':'Cobrança já estava marcada como paga; evento mantido apenas para auditoria.';
                if($aligned>0)$msg.=' Data da baixa/movimento ajustada para '.date('d/m/Y',strtotime((string)$creditDate)).' (entrada no extrato Itaú).';
                return ['processed'=>true,'status'=>'JA_PAGO','message'=>$msg,'receivable_id'=>$receivableId,'bank_fee_movement_id'=>$feeMovementId,'bank_fee_amount'=>$bankFee,'dates_aligned'=>$aligned];
            }

            if($receivableId<=0){
                $pdo->prepare("UPDATE finance_boleto_charges SET status='PAGO',paid_at=?,paid_amount=?,payment_date=?,credit_date=?,credited_amount=?,last_webhook_at=NOW(),error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
                    ->execute([$paidAt,$paid,$paymentDate,$creditDate,$credited,$companyId,$chargeId]);
                $pdo->commit();
                return ['processed'=>true,'status'=>'PAGO_SEM_RECEBER','message'=>'Boleto marcado como pago, mas a cobrança não possui Conta a Receber vinculada.','receivable_id'=>0];
            }

            $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId,$receivableId]);$title=$st->fetch(PDO::FETCH_ASSOC)?:[];
            if(!$title)throw new RuntimeException('Conta a Receber vinculada ao boleto não foi encontrada.');
            $principalOpen=round(finance_cre_title_principal_open($pdo,$companyId,$title),2);
            if($principalOpen<=0.009 || strtoupper((string)($title['status']??''))==='BAIXADO'){
                $feeMovementId=$bankFee>0.009?itau_webhook_create_bank_fee_movement($pdo,$cfg,$charge,$title,$bankFee,$movementDate,$bankFeeDescription):0;
                $pdo->prepare("UPDATE finance_boleto_charges SET status='PAGO',paid_at=?,paid_amount=?,payment_date=?,credit_date=?,credited_amount=?,last_webhook_at=NOW(),error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
                    ->execute([$paidAt,$paid,$paymentDate,$creditDate,$credited,$companyId,$chargeId]);
                if($isFrancesinha&&$creditDate)itau_webhook_align_settlement_dates($pdo,$cfg,$charge,(string)$creditDate);
                $pdo->commit();
                return ['processed'=>true,'status'=>'RECEBER_JA_BAIXADO','message'=>$feeMovementId>0?'Conta a Receber já estava baixada; boleto atualizado para PAGO e tarifa bancária conferida/conciliada sem gerar nova baixa.':'Conta a Receber já estava baixada; boleto atualizado para PAGO sem gerar nova movimentação.','receivable_id'=>$receivableId,'bank_fee_movement_id'=>$feeMovementId,'bank_fee_amount'=>$bankFee];
            }

            // Evita dupla baixa se alguém já tiver feito uma liquidação parcial/manual antes da notificação do banco.
            $titleAmount=round(max(0.0,(float)($title['amount']??0)),2);
            $titlePaid=round(max(0.0,(float)($title['paid_amount']??0)),2);
            $titleStatus=strtoupper(trim((string)($title['status']??'')));
            $hasPreviousSettlement=$titlePaid>0.009 || $titleStatus==='PARCIAL' || ($titleAmount>0 && $principalOpen<$titleAmount-0.009);
            if($hasPreviousSettlement){
                $warning='Pagamento Itaú confirmado, porém a Conta a Receber já possui baixa parcial/manual. Conferir antes de efetivar nova baixa para evitar duplicidade.';
                $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_PAGAMENTO',paid_amount=?,payment_date=?,credit_date=?,credited_amount=?,last_webhook_at=NOW(),error_message=?,updated_at=NOW() WHERE company_id=? AND id=?")
                    ->execute([$paid,$paymentDate,$creditDate,$credited,$warning,$companyId,$chargeId]);
                $pdo->commit();
                return ['processed'=>false,'status'=>'CONFERIR_RECEBER_PARCIAL','message'=>$warning,'receivable_id'=>$receivableId,'paid_amount'=>$paid];
            }

            // O BoleCode do Nexa é emitido sem aceitar pagamento de valor divergente. Portanto,
            // uma notificação de pagamento válida liquida todo o principal em aberto. Diferenças
            // entre principal e valor pago são classificadas como juros/encargos ou desconto.
            $principal=$principalOpen;
            $fine=round(max(0.0,(float)$payment['fine_amount']),2);
            $interest=round(max(0.0,(float)$payment['interest_amount'])+max(0.0,(float)$payment['other_expenses_amount']),2);
            $discount=round(max(0.0,(float)$payment['discount_amount']),2);
            $knownNet=round($principal+$fine+$interest-$discount,2);
            $delta=round($paid-$knownNet,2);
            if($delta>0.01)$interest=round($interest+$delta,2);
            elseif($delta<-0.01)$discount=round($discount+abs($delta),2);
            $net=round($principal+$fine+$interest-$discount,2);
            if($net<=0)throw new RuntimeException('Valor líquido da baixa bancária inválido.');
            if(abs($net-$paid)>0.01)throw new RuntimeException('Não foi possível conciliar o valor pago informado pelo Itaú com o saldo do título.');

            $methodId=(int)($charge['payment_method_id']??0);
            $method=$methodId?billing_payment_method($pdo,$companyId,$methodId):[];
            $methodLabel=trim((string)($method['name']??''))?:'Boleto Itaú';
            $nosso=trim((string)($charge['nosso_numero']??''));
            $notes='Baixa automática via Webhook Itaú. Cobrança #'.$chargeId
                .($nosso!==''?' · Nosso Número '.$nosso:'')
                .' · Valor pago R$ '.number_format($paid,2,',','.')
                .($credited>0?' · Crédito líquido R$ '.number_format($credited,2,',','.'):'')
                .($bankFee>0?' · Tarifa bancária R$ '.number_format($bankFee,2,',','.'):'')
                .' · Pagamento '.$paymentDate
                .' · Crédito '.($creditDate ? $creditDate.((string)($item['_nexa_credit_source']??'')==='FRANCESINHA'?' (lançamento na Francesinha Itaú)':'') : 'não informado pelo banco (conferir extrato)').'.';

            $st=$pdo->prepare('INSERT INTO finance_settlements (company_id, origin_type, origin_id, settlement_date, payment_method, bank_account_id, amount, fine_amount, interest_amount, discount_amount, waived_fine_amount, waived_interest_amount, waiver_user_id, notes, movement_id) VALUES (?,\'RECEBER\',?,?,?,?,?,?,?,?,0,0,NULL,?,NULL)');
            $st->execute([$companyId,$receivableId,$settlementDate,$methodLabel,$bankId,$principal,$fine,$interest,$discount,$notes]);
            $settlementId=(int)$pdo->lastInsertId();

            $split=['payment_method_id'=>$methodId?:null,'payment_method'=>$methodLabel,'bank_account_id'=>$bankId,'amount'=>$net];
            $movementId=finance_create_split_movement($pdo,$companyId,'RECEBER',$receivableId,$movementDate,$title,$split,$net,'','',$notes);
            finance_insert_settlement_payment($pdo,$companyId,$settlementId,$split,$net,$movementId?:null);
            if($movementId>0)$pdo->prepare('UPDATE finance_settlements SET movement_id=? WHERE company_id=? AND id=?')->execute([$movementId,$companyId,$settlementId]);
            $feeMovementId=0;if($bankFee>0.009)$feeMovementId=itau_webhook_create_bank_fee_movement($pdo,$cfg,$charge,$title,$bankFee,$movementDate,$bankFeeDescription);

            $pdo->prepare('UPDATE finance_receivables SET paid_amount=paid_amount+?,fine_amount=fine_amount+?,interest_amount=interest_amount+?,discount_amount=discount_amount+?,updated_at=NOW() WHERE company_id=? AND id=?')
                ->execute([$net,$fine,$interest,$discount,$companyId,$receivableId]);
            finance_update_title_status($pdo,'finance_receivables',$companyId,$receivableId);
            $after=finance_query_one($pdo,'SELECT status,open_amount FROM finance_receivables WHERE company_id=? AND id=?',[$companyId,$receivableId])?:[];
            if(strtoupper((string)($after['status']??''))!=='BAIXADO' || (float)($after['open_amount']??0)>0.009){
                throw new RuntimeException('A baixa bancária não zerou o saldo da Conta a Receber; operação revertida para conferência.');
            }

            $pdo->prepare("UPDATE finance_boleto_charges SET status='PAGO',paid_at=?,paid_amount=?,payment_date=?,credit_date=?,credited_amount=?,last_webhook_at=NOW(),error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute([$paidAt,$paid,$paymentDate,$creditDate,$credited,$companyId,$chargeId]);
            if((int)($charge['contract_id']??0)>0 && billing_table_exists($pdo,'contract_billing_history')){
                try{
                    $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) VALUES(?,?,?,'BOLETO',?,'BOLETO_PAGO',?,?,NOW(),NULL)")
                        ->execute([$companyId,(int)$charge['contract_id'],(int)($charge['generation_id']??0)?:null,$chargeId,'Pagamento confirmado pelo Itaú para o boleto '.$nosso.'.',$paid]);
                }catch(Throwable $ignored){}
            }
            $pdo->commit();
            return [
                'processed'=>true,
                'status'=>'LIQUIDADO',
                'message'=>'Pagamento Itaú confirmado, Conta a Receber baixada e movimento financeiro de crédito gerado automaticamente.',
                'receivable_id'=>$receivableId,
                'settlement_id'=>$settlementId,
                'movement_id'=>$movementId,
                'bank_fee_movement_id'=>$feeMovementId,
                'paid_amount'=>$paid,
                'payment_date'=>$paymentDate,
                'credit_date'=>$creditDate,
            ];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}

if (!function_exists('itau_webhook_process_event')) {
    function itau_webhook_process_event(PDO $pdo,array $cfg,array $item,array $stored): array {
        $logId=(int)($stored['log_id']??0);$chargeId=(int)($stored['charge_id']??0);
        if(!empty($stored['duplicate']) && !empty($stored['already_processed'])){
            return $stored+[
                'processed'=>true,
                'processing_status'=>(string)($stored['previous_status']?:'DUPLICADO_PROCESSADO'),
                'message'=>(string)($stored['previous_message']?:'Notificação duplicada já processada anteriormente; nenhuma nova baixa foi gerada.'),
            ];
        }
        if($chargeId<=0){
            itau_webhook_log_processing($pdo,$logId,false,'SEM_CORRESPONDENCIA','Nenhuma cobrança local encontrada para o Nosso Número informado.');
            return $stored+['processed'=>false,'processing_status'=>'SEM_CORRESPONDENCIA'];
        }
        try{
            $r=itau_webhook_auto_settle_charge($pdo,$cfg,$chargeId,$item);
            $done=!empty($r['processed']);
            itau_webhook_log_processing($pdo,$logId,$done,(string)($r['status']??($done?'PROCESSADO':'PENDENTE')),(string)($r['message']??''));
            return $stored+$r+['processing_status'=>(string)($r['status']??'')];
        }catch(Throwable $e){
            itau_webhook_log_processing($pdo,$logId,false,'ERRO',$e->getMessage());
            return $stored+['processed'=>false,'processing_status'=>'ERRO','message'=>$e->getMessage()];
        }
    }
}

if (!function_exists('itau_webhook_find_item_in_raw')) {
    function itau_webhook_find_item_in_raw(string $raw,string $nosso): ?array {
        $payload=json_decode($raw,true);if(!is_array($payload))return null;$items=$payload['boletos']??[];if(!is_array($items))return null;
        $target=preg_replace('/\D+/','',$nosso)??'';
        foreach($items as $item){if(!is_array($item))continue;$candidate=trim((string)itau_webhook_pick($item,['numeroNossoNumero','numero_nosso_numero','nossoNumero','nosso_numero']));if($candidate===$nosso)return $item;$digits=preg_replace('/\D+/','',$candidate)??'';if($target!==''&&$digits!==''&&(int)$target===(int)$digits)return $item;}
        return null;
    }
}

if (!function_exists('itau_webhook_movement_fee_data')) {
    function itau_webhook_movement_fee_data(array $movement): array {
        $fee=0.0;$labels=[];
        foreach((array)($movement['operacoes_cobranca']??[]) as $op){
            if(!is_array($op))continue;
            $desc=trim((string)($op['descricao']??''));
            $value=itau_webhook_money($op['valor']??0);
            $norm=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$desc);
            if(!is_string($norm)||$norm==='')$norm=$desc;
            $norm=strtolower($norm);
            // A francesinha v3 pode devolver a tarifa com sinal positivo ou negativo.
            // A natureza é determinada pela descrição da operação, não pelo sinal.
            if(abs($value)>0.0001&&(str_contains($norm,'tarifa')||str_contains($norm,'taxa banc')||str_contains($norm,'taxa de cobran'))){
                $fee+=abs($value);
                if($desc!=='')$labels[]=$desc;
            }
        }
        // Em retornos reais da Francesinha v3 a tarifa de cobrança também pode vir
        // consolidada em valor_decrescimo, enquanto valor_liquido_lancado já está líquido.
        // Usamos esse campo somente como fallback quando não houve tarifa detalhada.
        if($fee<=0.0001){
            $decrescimo=abs(itau_webhook_money($movement['valor_decrescimo']??0));
            if($decrescimo>0.0001){
                $fee=$decrescimo;
                $labels[]='Tarifa de cobrança Itaú';
            }
        }
        return ['amount'=>round($fee,2),'description'=>implode(' + ',array_values(array_unique($labels)))];
    }
}


if (!function_exists('itau_webhook_movement_diagnostic')) {
    /**
     * Monta uma linha puramente diagnóstica da Francesinha, sem produzir efeitos
     * financeiros. O objetivo é permitir conciliar o agrupamento do extrato bancário
     * com cada liquidação individual devolvida pela API Itaú.
     */
    function itau_webhook_movement_diagnostic(PDO $pdo, array $cfg, array $movement, string $queryDate = ''): array {
        $companyId=(int)($cfg['company_id']??0);$bankId=(int)($cfg['bank_account_id']??0);
        $nosso=trim((string)itau_webhook_pick($movement,['nosso_numero','numeroNossoNumero','numero_nosso_numero','nossoNumero']));
        $seu=trim((string)itau_webhook_pick($movement,['seu_numero','numeroSeuNumero','numero_seu_numero','seuNumero']));
        $payer=trim((string)itau_webhook_pick($movement,['pagador','nome_pagador','nomePagador','nomePagadorSacado']));
        $movementDate=itau_webhook_date(itau_webhook_pick($movement,['data_movimentacao','dataMovimentacao'])) ?: ($queryDate?:'');
        $bankPaymentDate=itau_webhook_date(itau_webhook_pick($movement,['dataPagamento','data_pagamento','dataInclusaoPagamento','data_liquidacao'])) ?: $movementDate;
        $explicitCreditDate=itau_webhook_date(itau_webhook_pick($movement,['dataCredito','data_credito','data_efetiva_credito','dataEfetivaCredito']));
        $bankCreditDate=$explicitCreditDate?:($movementDate!==''?$movementDate:null);
        $title=abs(itau_webhook_money(itau_webhook_pick($movement,['valor_titulo','valorTitulo'])));
        $net=abs(itau_webhook_money(itau_webhook_pick($movement,['valor_liquido_lancado','valorLiquidoLancado','valor_creditado','valorCreditado'])));
        $fee=itau_webhook_movement_fee_data($movement);$feeAmount=round(max(0.0,(float)($fee['amount']??0)),2);
        $gross=round($net+$feeAmount,2);if($gross<=0.009&&$title>0)$gross=$title;
        $charge=$nosso!==''?itau_webhook_find_charge($pdo,$companyId,$bankId,$nosso):null;
        $chargeId=$charge?(int)($charge['id']??0):0;$receivableId=$charge?(int)($charge['receivable_id']??0):0;$receivableStatus='';
        if($receivableId>0){
            try{$st=$pdo->prepare('SELECT status FROM finance_receivables WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$receivableId]);$receivableStatus=trim((string)($st->fetchColumn()?:''));}catch(Throwable $ignored){}
        }
        $chargeStatus=$charge?trim((string)($charge['status']??'')):'';
        $localStatus='SEM_CORRESPONDENCIA';
        if($nosso==='')$localStatus='SEM_NOSSO_NUMERO';
        elseif($charge){
            if(in_array($chargeStatus,['PAGO','LIQUIDADO'],true)||$receivableStatus==='BAIXADO')$localStatus='JA_PROCESSADO';
            else $localStatus='LOCALIZADO';
        }
        return [
            'date'=>$movementDate,
            'payment_date'=>$bankPaymentDate,
            'credit_date'=>$bankCreditDate,
            'credit_date_confirmed'=>$explicitCreditDate!==null,
            'nosso_numero'=>$nosso,
            'seu_numero'=>$seu,
            'payer'=>$payer,
            'tipo_cobranca'=>trim((string)itau_webhook_pick($movement,['tipo_cobranca','tipoCobranca'])),
            'title_amount'=>$title,
            'gross_amount'=>$gross,
            'net_amount'=>$net,
            'bank_fee_amount'=>$feeAmount,
            'bank_fee_description'=>(string)($fee['description']??''),
            'charge_id'=>$chargeId,
            'charge_status'=>$chargeStatus,
            'receivable_id'=>$receivableId,
            'receivable_status'=>$receivableStatus,
            'local_status'=>$localStatus,
        ];
    }
}

if (!function_exists('itau_webhook_event_from_movement')) {
    function itau_webhook_event_from_movement(array $movement, string $queryDate = ''): array {
        $net=itau_webhook_money($movement['valor_liquido_lancado']??0);
        $fee=itau_webhook_movement_fee_data($movement);
        $gross=round($net+(float)$fee['amount'],2);
        $title=itau_webhook_money($movement['valor_titulo']??0);
        if($gross<=0&&$title>0)$gross=$title;
        $movementDate=itau_webhook_date(itau_webhook_pick($movement,['data_movimentacao','dataMovimentacao']))?:(itau_webhook_date($queryDate)?:date('Y-m-d'));
        $paymentDate=itau_webhook_date(itau_webhook_pick($movement,['dataPagamento','data_pagamento','dataInclusaoPagamento','data_liquidacao']))?:$movementDate;
        $explicitCredit=itau_webhook_date(itau_webhook_pick($movement,['dataCredito','data_credito','data_efetiva_credito','dataEfetivaCredito']));
        // A Francesinha do dia D lista as liquidações cujo valor líquido foi LANÇADO
        // na conta no dia D (valor_liquido_lancado). É a data que aparece no extrato,
        // e não a data em que o pagador pagou (que costuma ser 1 dia útil antes).
        $creditDate=$explicitCredit?:$movementDate;
        $event=['numeroNossoNumero'=>(string)($movement['nosso_numero']??''),'dacTitulo'=>(string)($movement['dac_titulo']??''),'tipoCobranca'=>(string)($movement['tipo_cobranca']??''),'nomePagador'=>(string)($movement['pagador']??''),'valorTitulo'=>$title,'valorPagoTotalCobranca'=>$gross,'valorCreditado'=>$net,'dataInclusaoPagamento'=>$paymentDate,'dataNotificacao'=>$movementDate,'bankFeeAmount'=>(float)$fee['amount'],'bankFeeDescription'=>(string)$fee['description'],'tipoLiquidacao'=>'CONSULTA_MOVIMENTACOES','_nexa_source'=>'API_BOLETOS_V3_MOVIMENTACOES','_itau_movement'=>$movement];
        $event['dataCredito']=$creditDate;
        $event['_nexa_credit_source']=$explicitCredit!==null?'ITAU':'FRANCESINHA';
        return $event;
    }
}

if (!function_exists('itau_webhook_array_is_list')) {
    function itau_webhook_array_is_list(array $value): bool {
        if ($value === []) return true;
        return array_keys($value) === range(0, count($value) - 1);
    }
}

if (!function_exists('itau_webhook_v3_movement_rows')) {
    function itau_webhook_v3_movement_rows(array $json): array {
        if (itau_webhook_array_is_list($json)) return array_values(array_filter($json, 'is_array'));
        $candidates = [$json['data'] ?? null, $json['content'] ?? null, $json['items'] ?? null, $json['movimentacoes'] ?? null];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            if (itau_webhook_array_is_list($candidate)) return array_values(array_filter($candidate, 'is_array'));
            foreach (['content','items','movimentacoes','registros','lista'] as $key) {
                $rows = $candidate[$key] ?? null;
                if (is_array($rows) && itau_webhook_array_is_list($rows)) return array_values(array_filter($rows, 'is_array'));
            }
        }
        return [];
    }
}

if (!function_exists('itau_webhook_v3_total_pages')) {
    function itau_webhook_v3_total_pages(array $json): int {
        $containers = [$json, $json['pagination'] ?? null, $json['paginacao'] ?? null, $json['page'] ?? null, $json['data'] ?? null];
        foreach ($containers as $container) {
            if (!is_array($container)) continue;
            foreach (['total_pages','totalPages','total_paginas'] as $key) {
                if (isset($container[$key]) && is_numeric($container[$key])) return max(1, (int)$container[$key]);
            }
        }
        return 1;
    }
}

if (!function_exists('itau_webhook_liquidation_filters')) {
    function itau_webhook_liquidation_filters(array $cfg, array $filters = []): array {
        $filters['tipo_movimentacao'] = 'liquidacoes';
        // Não fixe BoleCode aqui. Na Francesinha v3 uma cobrança originada na jornada
        // BoleCode pode ser classificada de forma diferente na movimentação. O Nexa
        // consulta todas as liquidações da carteira e identifica a cobrança local pelo
        // Nosso Número + conta bancária, que é a chave segura para a baixa.
        if (trim((string)($filters['numero_carteira'] ?? '')) === '') {
            $wallet = preg_replace('/\D+/', '', (string)($cfg['carteira'] ?? '')) ?? '';
            if ($wallet !== '') $filters['numero_carteira'] = $wallet;
        }
        $filters['page_size'] = 50;
        $filters['page'] = 0;
        return $filters;
    }
}

if (!function_exists('itau_webhook_is_no_movements_error')) {
    function itau_webhook_is_no_movements_error(Throwable $e): bool {
        $message = (string)$e->getMessage();
        if (!str_contains($message, 'HTTP 422')) return false;
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message);
        if (!is_string($normalized) || $normalized === '') $normalized = $message;
        $normalized = strtolower($normalized);
        return str_contains($normalized, 'nao existe moviment')
            || str_contains($normalized, 'nao existem moviment')
            || str_contains($normalized, 'sem movimentacao');
    }
}

if (!function_exists('itau_webhook_sync_liquidations')) {
    function itau_webhook_sync_liquidations(PDO $pdo,array $cfg,string $date,array $filters=[]): array {
        if(!function_exists('itau_v3_get_movements'))throw new RuntimeException('Módulo Itaú Boletos v3 não carregado.');
        $queryOnly=!empty($filters['_nexa_query_only']);unset($filters['_nexa_query_only']);
        $filters=itau_webhook_liquidation_filters($cfg,$filters);
        if(trim((string)($filters['numero_carteira']??''))==='')throw new RuntimeException('Carteira Itaú não configurada para esta conta. Informe a carteira (ex.: 109) na Integração Itaú antes de sincronizar liquidações.');

        // A classificação tipo_cobranca da Francesinha pode variar mesmo para uma
        // cobrança emitida pelo fluxo BoleCode. Tentamos primeiro a consulta ampla da
        // carteira e, se o banco não aceitar/retornar dados, fazemos fallbacks controlados.
        $variants=[];
        $explicitType=trim((string)($filters['tipo_cobranca']??''));
        if($explicitType!==''){
            $variants[]=$filters;
        }else{
            $wide=$filters;unset($wide['tipo_cobranca']);$variants[]=$wide;
            $bolecode=$filters;$bolecode['tipo_cobranca']='bolecode';$variants[]=$bolecode;
            $boleto=$filters;$boleto['tipo_cobranca']='boleto';$variants[]=$boleto;
        }
        $allRows=[];$rawPages=[];$lastError=null;$hadNoMovement=false;
        foreach($variants as $variantIndex=>$variant){
            $variantRows=[];$variantRaw=[];$totalPages=1;$variantNoMovement=false;
            try{
                for($page=0;$page<$totalPages;$page++){
                    if($page>=100)throw new RuntimeException('A consulta Itaú retornou paginação acima do limite de segurança (100 páginas).');
                    $pageFilters=$variant;$pageFilters['page']=$page;
                    try{
                        $resp=itau_v3_get_movements($cfg,(string)($cfg['itau_beneficiary_id']??''),$date,$pageFilters);
                    }catch(RuntimeException $e){
                        if($page===0&&itau_webhook_is_no_movements_error($e)){$variantNoMovement=true;$hadNoMovement=true;break;}
                        throw $e;
                    }
                    $json=json_decode((string)($resp['body']??''),true);
                    if(!is_array($json))throw new RuntimeException('O Itaú retornou uma resposta de liquidações em formato inválido.');
                    foreach(itau_webhook_v3_movement_rows($json) as $movement)if(is_array($movement))$variantRows[]=$movement;
                    $variantRaw[]=(string)($resp['body']??'');
                    if($page===0)$totalPages=itau_webhook_v3_total_pages($json);
                }
                if($variantRows){$allRows=$variantRows;$rawPages=$variantRaw;break;}
                if(!$variantNoMovement&&$variantIndex===0&&$explicitType!==''){$allRows=[];$rawPages=$variantRaw;break;}
            }catch(RuntimeException $e){
                $lastError=$e;
                // Se a consulta ampla for recusada por uma particularidade do contrato,
                // ainda tentamos BoleCode e Boleto antes de desistir.
                if($explicitType!==''||!str_contains((string)$e->getMessage(),'HTTP 422'))throw $e;
            }
        }
        if(!$allRows){
            if($hadNoMovement)return ['found'=>0,'matched'=>0,'processed'=>0,'already'=>0,'pending'=>0,'errors'=>0,'fees_found'=>0,'fee_amount'=>0.0,'gross_amount'=>0.0,'net_amount'=>0.0,'details'=>[],'diagnostic_rows'=>[],'raw'=>'','no_movement'=>1,'query_only'=>$queryOnly?1:0];
            if($lastError instanceof Throwable)throw $lastError;
        }

        $rows=$allRows;$found=count($rows);$matched=0;$processed=0;$already=0;$pending=0;$errors=0;$feesFound=0;$feeAmount=0.0;$grossAmount=0.0;$netAmount=0.0;$details=[];$diagnosticRows=[];$companyId=(int)$cfg['company_id'];$bankId=(int)$cfg['bank_account_id'];
        foreach($rows as $movement){
            if(!is_array($movement))continue;
            $diag=itau_webhook_movement_diagnostic($pdo,$cfg,$movement,$date);$diagnosticRows[]=$diag;
            $grossAmount=round($grossAmount+(float)($diag['gross_amount']??0),2);$netAmount=round($netAmount+(float)($diag['net_amount']??0),2);
            $movementFee=round(max(0.0,(float)($diag['bank_fee_amount']??0)),2);if($movementFee>0.009){$feesFound++;$feeAmount=round($feeAmount+$movementFee,2);}
            $nosso=trim((string)($diag['nosso_numero']??''));
            if($nosso===''){$pending++;$details[]=['nosso_numero'=>'','status'=>'SEM_NOSSO_NUMERO','message'=>'Movimentação Itaú sem Nosso Número; nenhuma baixa foi executada.','bank_fee_amount'=>$movementFee];continue;}
            $charge=itau_webhook_find_charge($pdo,$companyId,$bankId,$nosso);
            if(!$charge){$pending++;$details[]=['nosso_numero'=>$nosso,'status'=>'SEM_CORRESPONDENCIA','message'=>'Nenhuma cobrança local encontrada para este Nosso Número.','bank_fee_amount'=>$movementFee];continue;}
            $matched++;
            if($queryOnly){
                if((string)($diag['local_status']??'')==='JA_PROCESSADO')$already++;
                $details[]=['nosso_numero'=>$nosso,'status'=>(string)($diag['local_status']??'LOCALIZADO'),'message'=>'Consulta diagnóstica: nenhuma baixa ou movimento foi gerado.','bank_fee_amount'=>$movementFee];
                continue;
            }
            $event=itau_webhook_event_from_movement($movement,$date);
            $raw=json_encode(['boletos'=>[$event]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
            try{
                $stored=itau_webhook_store_event($pdo,$cfg,$event,$raw);
                $result=itau_webhook_process_event($pdo,$cfg,$event,$stored);
                if(!empty($result['processed'])){
                    if(in_array((string)($result['status']??''),['JA_PAGO','RECEBER_JA_BAIXADO'],true))$already++;else$processed++;
                }else$pending++;
                $details[]=['nosso_numero'=>$nosso,'status'=>(string)($result['processing_status']??$result['status']??'PENDENTE'),'message'=>(string)($result['message']??''),'bank_fee_amount'=>$movementFee];
                $last=count($diagnosticRows)-1;if($last>=0){$diagnosticRows[$last]['processing_status']=(string)($result['processing_status']??$result['status']??'');$diagnosticRows[$last]['processing_message']=(string)($result['message']??'');}
            }catch(Throwable $e){$errors++;$details[]=['nosso_numero'=>$nosso,'status'=>'ERRO','message'=>$e->getMessage(),'bank_fee_amount'=>$movementFee];$last=count($diagnosticRows)-1;if($last>=0){$diagnosticRows[$last]['processing_status']='ERRO';$diagnosticRows[$last]['processing_message']=$e->getMessage();}}
        }
        return ['found'=>$found,'matched'=>$matched,'processed'=>$processed,'already'=>$already,'pending'=>$pending,'errors'=>$errors,'fees_found'=>$feesFound,'fee_amount'=>round($feeAmount,2),'gross_amount'=>round($grossAmount,2),'net_amount'=>round($netAmount,2),'details'=>$details,'diagnostic_rows'=>$diagnosticRows,'raw'=>implode("\n--- PAGE ---\n",$rawPages),'query_only'=>$queryOnly?1:0];
    }
}

if (!function_exists('itau_webhook_sync_liquidations_period')) {
    function itau_webhook_sync_liquidations_period(PDO $pdo,array $cfg,string $startDate,string $endDate,array $filters=[]): array {
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',trim($startDate));
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',trim($endDate));
        if(!$start||$start->format('Y-m-d')!==trim($startDate))throw new RuntimeException('Data inicial da liquidação inválida.');
        if(!$end||$end->format('Y-m-d')!==trim($endDate))throw new RuntimeException('Data final da liquidação inválida.');
        if($end<$start)throw new RuntimeException('A data final não pode ser anterior à data inicial.');
        $days=(int)$start->diff($end)->format('%a')+1;
        if($days>31)throw new RuntimeException('O período máximo por sincronização é de 31 dias. Divida a consulta em períodos menores.');
        if($end>new DateTimeImmutable('today'))throw new RuntimeException('A data final da liquidação não pode estar no futuro.');
        $queryOnly=!empty($filters['_nexa_query_only']);
        $totals=['days'=>$days,'no_movement_days'=>0,'found'=>0,'matched'=>0,'processed'=>0,'already'=>0,'pending'=>0,'errors'=>0,'fees_found'=>0,'fee_amount'=>0.0,'gross_amount'=>0.0,'net_amount'=>0.0,'details'=>[],'diagnostic_rows'=>[],'day_summaries'=>[],'query_only'=>$queryOnly?1:0];
        for($cursor=$start;$cursor<=$end;$cursor=$cursor->modify('+1 day')){
            $date=$cursor->format('Y-m-d');
            try{$day=itau_webhook_sync_liquidations($pdo,$cfg,$date,$filters);}catch(Throwable $e){throw new RuntimeException('Falha ao consultar liquidações de '.$cursor->format('d/m/Y').': '.$e->getMessage(),0,$e);}
            if (!empty($day['no_movement'])) $totals['no_movement_days']++;
            foreach(['found','matched','processed','already','pending','errors','fees_found'] as $key)$totals[$key]+=(int)($day[$key]??0);
            $totals['fee_amount']=round((float)$totals['fee_amount']+(float)($day['fee_amount']??0),2);$totals['gross_amount']=round((float)$totals['gross_amount']+(float)($day['gross_amount']??0),2);$totals['net_amount']=round((float)$totals['net_amount']+(float)($day['net_amount']??0),2);
            foreach((array)($day['details']??[]) as $detail){if(!is_array($detail))continue;$detail['date']=$cursor->format('d/m/Y');$totals['details'][]=$detail;}
            foreach((array)($day['diagnostic_rows']??[]) as $diag){if(!is_array($diag))continue;if(empty($diag['date']))$diag['date']=$date;$totals['diagnostic_rows'][]=$diag;}
            $totals['day_summaries'][]=['date'=>$date,'found'=>(int)($day['found']??0),'matched'=>(int)($day['matched']??0),'pending'=>(int)($day['pending']??0),'gross_amount'=>(float)($day['gross_amount']??0),'net_amount'=>(float)($day['net_amount']??0),'fees_found'=>(int)($day['fees_found']??0),'fee_amount'=>(float)($day['fee_amount']??0),'no_movement'=>!empty($day['no_movement'])?1:0];
        }
        return $totals;
    }
}

if (!function_exists('itau_webhook_reprocess_pending_logs')) {
    function itau_webhook_reprocess_pending_logs(PDO $pdo,array $cfg,int $limit=200): array {
        if (empty($cfg['_nexa_skip_schema_ensure'])) {
            billing_ensure_schema($pdo);finance_ensure_schema($pdo);
        } else {
            foreach (['finance_boleto_webhook_logs','finance_boleto_charges','finance_receivables','finance_settlements','finance_movements','finance_bank_accounts'] as $requiredTable) {
                if (!finance_table_exists($pdo, $requiredTable)) throw new RuntimeException('Estrutura financeira incompleta para processar retornos Itaú: tabela ' . $requiredTable . ' ausente. Aplique as migrations pelo Atualizador do Banco.');
            }
        }
        $companyId=(int)($cfg['company_id']??0);$bankId=(int)($cfg['bank_account_id']??0);$limit=max(1,min(1000,$limit));
        if($companyId<=0||$bankId<=0)throw new RuntimeException('Configuração Itaú sem empresa/conta bancária válida para processar retornos.');
        $st=$pdo->prepare('SELECT * FROM finance_boleto_webhook_logs WHERE company_id=? AND bank_account_id=? AND matched=1 AND processed=0 ORDER BY id ASC LIMIT '.$limit);$st->execute([$companyId,$bankId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        $processed=0;$errors=0;$pending=0;$details=[];
        foreach($rows as $row){$item=itau_webhook_find_item_in_raw((string)$row['payload'],(string)($row['nosso_numero']??''));if(!$item){itau_webhook_log_processing($pdo,(int)$row['id'],false,'PAYLOAD_INVALIDO','Não foi possível localizar o boleto dentro do payload armazenado.');$errors++;continue;}
            $stored=['matched'=>true,'charge_id'=>(int)($row['boleto_charge_id']??0),'nosso_numero'=>(string)($row['nosso_numero']??''),'log_id'=>(int)$row['id'],'duplicate'=>true];$r=itau_webhook_process_event($pdo,$cfg,$item,$stored);if(!empty($r['processed']))$processed++;elseif(($r['processing_status']??'')==='ERRO')$errors++;else$pending++;$details[]=$r;
        }
        return ['found'=>count($rows),'processed'=>$processed,'errors'=>$errors,'pending'=>$pending,'details'=>$details];
    }
}
