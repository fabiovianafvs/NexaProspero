<?php
/**
 * Nexa Próspero - Sicoob: baixa assistida a partir de consulta individual.
 * Permitido somente no piloto Sicoob, por empresa/conta, com operador autorizado,
 * situação LIQUIDADO confirmada pela API, correspondência estrita e crédito no extrato.
 * Não altera registros durante uma simples consulta/prévia.
 */
declare(strict_types=1);
require_once __DIR__ . '/sicoob_bank_status.php';

if (!function_exists('sicoob_direct_digits')) {
    function sicoob_direct_digits($v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
}
if (!function_exists('sicoob_direct_same_number')) {
    function sicoob_direct_same_number($a, $b): bool {
        $a = sicoob_direct_digits($a); $b = sicoob_direct_digits($b);
        return $a !== '' && $b !== '' && (ltrim($a,'0') ?: '0') === (ltrim($b,'0') ?: '0');
    }
}
if (!function_exists('sicoob_direct_cents')) {
    function sicoob_direct_cents($v): ?int {
        if ((!is_string($v) && !is_int($v) && !is_float($v)) || !is_numeric($v) || !is_finite((float)$v)) return null;
        if ((float)$v < 0 || (float)$v > 999999999) return null;
        return (int)round((float)$v * 100);
    }
}
if (!function_exists('sicoob_direct_validate_date')) {
    function sicoob_direct_validate_date($v, string $label): string {
        $date = trim((string)$v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new RuntimeException('Data inválida: '.$label.'.');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) throw new RuntimeException('Data inválida ou futura: '.$label.'.');
        return $date;
    }
}
if (!function_exists('sicoob_direct_extract')) {
    function sicoob_direct_extract(array $consult): array {
        $reading = nexa_sicoob_read_bank_status($consult);
        if (($reading['situacao'] ?? '') !== 'LIQUIDADO' || empty($reading['matched'])) {
            throw new RuntimeException('O Sicoob não confirmou LIQUIDADO com o mesmo Nosso Número. Nenhuma baixa autorizada.');
        }
        $raw = $consult['response']['resultado'] ?? null;
        if (is_array($raw) && array_is_list($raw)) $raw = count($raw)===1 ? $raw[0] : null;
        if (!is_array($raw)) throw new RuntimeException('Dados do boleto não retornaram em formato válido.');
        return $raw;
    }
}
if (!function_exists('sicoob_direct_validate_context')) {
    function sicoob_direct_validate_context(PDO $pdo, int $companyId, int $bankId, int $chargeId, array $consult, bool $lock = false): array {
        if ($companyId <= 0 || $bankId <= 0 || $chargeId <= 0) throw new RuntimeException('Empresa, banco ou cobrança inválida.');
        $raw = sicoob_direct_extract($consult);
        $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND bank_account_id=? AND id=?'.($lock?' FOR UPDATE':''));
        $st->execute([$companyId,$bankId,$chargeId]);$charge=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$charge || strtoupper((string)($charge['provider']??''))!=='SICOOB' || strtoupper((string)($charge['integration_mode']??''))!=='API') throw new RuntimeException('Cobrança Sicoob/API não encontrada na empresa e conta selecionadas.');
        $cfg=billing_bank_config($pdo,$companyId,$bankId);
        if (!$cfg || strtoupper((string)($cfg['provider']??''))!=='SICOOB' || strtoupper((string)($cfg['integration_mode']??''))!=='API' || strtoupper((string)($cfg['environment']??''))!=='PRODUCAO') throw new RuntimeException('Configuração da conta Sicoob divergente ou fora do ambiente de produção.');
        if (!sicoob_direct_same_number($raw['nossoNumero']??'', $charge['nosso_numero']??'') || !sicoob_direct_same_number($raw['nossoNumero']??'', $consult['nosso_numero']??'')) {
            throw new RuntimeException('Nosso Número bancário divergente da cobrança local.');
        }
        if (!sicoob_direct_same_number($raw['numeroCliente']??'', $cfg['convenio']??'') || !sicoob_direct_same_number($raw['codigoModalidade']??'', $cfg['carteira']??'')) {
            throw new RuntimeException('Número do cliente / modalidade diferente da conta Sicoob configurada.');
        }
        $bankCents=sicoob_direct_cents($raw['valor']??null);
        $localCents=sicoob_direct_cents($charge['amount']??null);
        if ($bankCents===null || $localCents===null || $bankCents<=0 || $bankCents!==$localCents) throw new RuntimeException('Valor nominal da API diferente da cobrança Nexa.');
        if (trim((string)($raw['seuNumero']??''))!=='' && trim((string)($charge['seu_numero']??''))!=='' && trim((string)$raw['seuNumero']) !== trim((string)$charge['seu_numero'])) {
            throw new RuntimeException('Seu Número do Sicoob diferente do registrado no Nexa.');
        }
        foreach (['codigoBarras'=>'barcode','linhaDigitavel'=>'linha_digitavel'] as $bankKey=>$localKey) {
            $b=sicoob_direct_digits($raw[$bankKey]??'');$l=sicoob_direct_digits($charge[$localKey]??'');
            if ($b!=='' && $l!=='' && $b!==$l) throw new RuntimeException('Identificação bancária '.$bankKey.' divergente.');
        }
        foreach (['valorPago','valorLiquidado','valorRecebido'] as $field) {
            if (array_key_exists($field,$raw) && $raw[$field] !== null) {
                $v=sicoob_direct_cents($raw[$field]);
                if ($v===null || $v!==$bankCents) throw new RuntimeException('A API informa pagamento divergente do valor nominal. Exige conciliação específica.');
            }
        }
        if (strtoupper((string)($charge['status']??''))==='PAGO') return ['status'=>'JA_BAIXADO','charge'=>$charge,'title'=>null,'amount'=>$bankCents/100];
        if (strtoupper((string)($charge['status']??''))!=='REGISTRADO') throw new RuntimeException('Cobrança não está REGISTRADA; status atual: '.(string)($charge['status']??'').'.');
        $rid=(int)($charge['receivable_id']??0);
        if ($rid<=0) throw new RuntimeException('Cobrança sem Conta a Receber vinculada.');
        $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''));
        $st->execute([$companyId,$rid]);$title=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$title) throw new RuntimeException('Conta a Receber vinculada não localizada.');
        if (strtoupper((string)($title['status']??''))==='BAIXADO' || (float)($title['open_amount']??0)<0.01) {
            return ['status'=>'JA_BAIXADO','charge'=>$charge,'title'=>$title,'amount'=>$bankCents/100];
        }
        if (strtoupper((string)($title['status']??''))!=='ABERTO' || (float)($title['paid_amount']??0)>0.009) throw new RuntimeException('Título parcialmente baixado, cancelado ou alterado.');
        foreach (['fine_amount','interest_amount','discount_amount'] as $field) {
            if (abs((float)($title[$field]??0))>0.009) throw new RuntimeException('Título possui juros, multa ou desconto local: conciliação específica necessária.');
        }
        $openCents=sicoob_direct_cents($title['open_amount']??null);
        $faceCents=sicoob_direct_cents($title['amount']??null);
        $principalCents=sicoob_direct_cents(finance_cre_title_principal_open($pdo,$companyId,$title));
        if ($openCents!==$bankCents || $faceCents!==$bankCents || $principalCents!==$bankCents) throw new RuntimeException('Saldo do título diverge do valor pago de acordo com o Sicoob.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=? AND COALESCE(status,'ATIVO')='ATIVO'");
        $st->execute([$companyId,$rid]);
        if ((int)$st->fetchColumn()>0) throw new RuntimeException('Título já possui lançamento de baixa ativo; operação bloqueada para conferência.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND id<>? AND status='PAGO'");
        $st->execute([$companyId,$rid,$chargeId]);
        if ((int)$st->fetchColumn()>0) throw new RuntimeException('Há outra cobrança paga vinculada ao mesmo título.');
        return ['status'=>'APTO','charge'=>$charge,'title'=>$title,'amount'=>$bankCents/100];
    }
}
if (!function_exists('sicoob_direct_confirm_settlement')) {
    function sicoob_direct_confirm_settlement(PDO $pdo,int $cid,int $bankId,int $chargeId,array $consult,string $paymentDate,string $creditDate,string $netCredit): array {
        if (!function_exists('sicoob_v15_company_enabled') || !sicoob_v15_company_enabled($cid)) throw new RuntimeException('Baixa Sicoob restrita ao piloto autorizado.');
        if (function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo)) throw new RuntimeException('Baixa Sicoob não permitida no contexto F11.');
        if ($pdo->inTransaction()) throw new RuntimeException('Outra transação já está em andamento.');
        $paid=sicoob_direct_validate_date($paymentDate,'pagamento');
        $credited=sicoob_direct_validate_date($creditDate,'crédito no banco');
        if ($credited < $paid) throw new RuntimeException('Data de crédito não pode ser anterior ao pagamento.');
        $days=(new DateTimeImmutable($paid))->diff(new DateTimeImmutable($credited))->days;
        if ($days>31) throw new RuntimeException('Intervalo entre pagamento e crédito acima de 31 dias.');
        $pdo->beginTransaction();
        try {
            $ctx=sicoob_direct_validate_context($pdo,$cid,$bankId,$chargeId,$consult,true);
            if ($ctx['status']==='JA_BAIXADO') { $pdo->commit();return ['status'=>'JA_BAIXADO','amount'=>0]; }
            if ($ctx['status']!=='APTO') throw new RuntimeException('Cobrança não apta para baixa.');
            $amount=(float)$ctx['amount'];
            $credit=sicoob_direct_cents($netCredit);
            if ($credit===null || $credit!==sicoob_direct_cents($amount)) {
                throw new RuntimeException('Crédito líquido do extrato diferente do valor nominal. Tarifas e diferenças exigem conciliação bancária específica.');
            }
            if (function_exists('finance_assert_cash_account_effective')) finance_assert_cash_account_effective($pdo,$cid,$bankId,'Conta Sicoob sem permissão para este operador.');
            elseif (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo,$cid,$bankId,'Conta Sicoob sem permissão para este operador.');
            $charge=$ctx['charge'];$title=$ctx['title'];$rid=(int)$charge['receivable_id'];
            $methodId=(int)($charge['payment_method_id']??0);
            $method=$methodId>0 ? billing_payment_method($pdo,$cid,$methodId):[];
            $name=trim((string)($method['name']??'')) ?: 'Boleto Sicoob';
            $operatorId=function_exists('auth_user_id') ? (int)(auth_user_id()??0) : 0;
            $note='Liquidação Sicoob confirmada por GET /boletos (LIQUIDADO), Nosso Número '.(string)$charge['nosso_numero'].'. Baixa assistida com crédito no extrato conferido pelo operador #'.$operatorId.'; cobrança #'.$chargeId.'.';
            $st=$pdo->prepare("INSERT INTO finance_settlements (company_id,origin_type,origin_id,settlement_date,payment_method,bank_account_id,amount,fine_amount,interest_amount,discount_amount,waived_fine_amount,waived_interest_amount,waiver_user_id,notes,movement_id) VALUES (?,'RECEBER',?,?,?,?,?,0,0,0,0,0,NULL,?,NULL)");
            $st->execute([$cid,$rid,$paid,$name,$bankId,$amount,$note]);
            $settlementId=(int)$pdo->lastInsertId();
            $split=['payment_method_id'=>$methodId?:null,'payment_method'=>$name,'bank_account_id'=>$bankId,'amount'=>$amount];
            $movementId=finance_create_split_movement($pdo,$cid,'RECEBER',$rid,$credited,$title,$split,$amount,'','',$note);
            if ($movementId<=0) throw new RuntimeException('Não foi possível criar o movimento financeiro.');
            finance_insert_settlement_payment($pdo,$cid,$settlementId,$split,$amount,$movementId);
            $pdo->prepare('UPDATE finance_settlements SET movement_id=? WHERE company_id=? AND id=?')->execute([$movementId,$cid,$settlementId]);
            $pdo->prepare('UPDATE finance_receivables SET paid_amount=paid_amount+?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$amount,$cid,$rid]);
            finance_update_title_status($pdo,'finance_receivables',$cid,$rid);
            $st=$pdo->prepare('SELECT status,open_amount FROM finance_receivables WHERE company_id=? AND id=?');
            $st->execute([$cid,$rid]);$after=$st->fetch(PDO::FETCH_ASSOC)?:[];
            if (strtoupper((string)($after['status']??''))!=='BAIXADO' || (float)($after['open_amount']??0)>0.009) throw new RuntimeException('Título não ficou integralmente quitado. Transação revertida.');
            $st=$pdo->prepare("UPDATE finance_boleto_charges SET status='PAGO',paid_at=?,paid_amount=?,payment_date=?,credit_date=?,credited_amount=?,error_message=NULL,updated_at=NOW() WHERE company_id=? AND bank_account_id=? AND id=? AND status='REGISTRADO'");
            $st->execute([$paid.' 00:00:00',$amount,$paid,$credited,$amount,$cid,$bankId,$chargeId]);
            if ($st->rowCount()!==1) throw new RuntimeException('A cobrança mudou durante a baixa. Transação revertida.');
            $pdo->commit();
            return ['status'=>'BAIXADO','amount'=>$amount,'settlement_id'=>$settlementId,'movement_id'=>$movementId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
