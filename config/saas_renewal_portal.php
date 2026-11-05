<?php
declare(strict_types=1);
/**
 * V11.53 — Portal de regularizacao para contratos SaaS ja vinculados.
 * Somente LEITURA na abertura. Pedido/recebivel apenas por POST autenticado.
 * Renovacao somente apos BAIXA de recebivel ja reconhecida no financeiro.
 * Sem DDL; nao altera catalogo publico nem fluxo normal de pedidos.
 */
require_once __DIR__ . '/saas_subscription_bridge.php';

function saas_renewal_context(PDO $pdo, int $saasCompanyId): array {
    $base=['eligible'=>false,'error'=>'','company_id'=>$saasCompanyId];
    if ($saasCompanyId<=0 || !saas_sub_bridge_schema_ready($pdo) || !saas_sub_bridge_adjustment_ready($pdo)) {
        return array_merge($base,['error'=>'As migrations de integração SaaS/Contratos ainda não estão homologadas.']);
    }
    $link=saas_sub_bridge_link($pdo,$saasCompanyId);
    if (!$link || (int)($link['billing_company_id']??0)<=0 || (int)($link['contract_id']??0)<=0) {
        return array_merge($base,['error'=>'Não há contrato de faturamento vinculado a esta empresa.']);
    }
    $billingId=(int)$link['billing_company_id'];$contractId=(int)$link['contract_id'];
    $st=$pdo->prepare('SELECT * FROM contracts WHERE id=? AND company_id=? LIMIT 1');
    $st->execute([$contractId,$billingId]);$contract=$st->fetch(PDO::FETCH_ASSOC)?:[];
    if (!$contract || !in_array(strtoupper((string)$contract['status']),['ATIVO','TESTE'],true) ||
        contracts_billing_mode_normalize((string)($contract['billing_mode']??''))!=='AUTOMATICA') {
        return array_merge($base,['error'=>'O contrato vinculado não está ATIVO ou não é de cobrança AUTOMÁTICA.']);
    }
    $sub=saas_sub_bridge_latest_subscription($pdo,$saasCompanyId);
    if (!$sub || (int)($link['saas_subscription_id']??0)!==(int)($sub['id']??0)) {
        return array_merge($base,['error'=>'O vínculo não corresponde à assinatura atual. Solicite conferência cadastral.']);
    }
    // Vencida/aguardando pagamento também pode gerar a cobrança e pagar; somente
    // assinatura cancelada (ou outro status administrativo) exige o suporte.
    if (!in_array(strtolower((string)($sub['status']??'')),['trial','active','past_due','pending_payment'],true)) {
        return array_merge($base,['error'=>'O status da assinatura exige regularização administrativa, sem geração automática de cobrança.']);
    }
    // Data da assinatura: define quando mostrar aviso e quando começa a carência.
    // Competência contratual: gerada conforme o dia do vencimento (contracts.due_day).
    // Pode ser diferente para contratos ATIVOS já cadastrados manualmente.
    $cycleDue=substr(trim((string)($link['next_due_date']??'')),0,10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$cycleDue)) {
        return array_merge($base,['error'=>'Próximo vencimento não informado no vínculo do contrato.']);
    }
    $due=$cycleDue;
    $candidate=[];
    $billingDay=(int)($contract['due_day']??0);
    $isActive=strtoupper((string)($contract['status']??''))==='ATIVO';
    $divergence='';
    if ($isActive && $billingDay>=1 && $billingDay<=31 && (int)substr($cycleDue,8,2)!==$billingDay) {
        // Competências mensais existentes: identificar a data verdadeira pelo motor
        // de contratos; jamais trocar o dia ou criar uma nova fatura silenciosamente.
        $monthStart=substr($cycleDue,0,7).'-01';
        $monthEnd=(new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
        $candidates=[];
        foreach (contracts_generation_candidates($pdo,$billingId,$monthStart,$monthEnd,max(0,(int)($link['person_id']??0)),'AUTOMATICA') as $row) {
            if ((int)($row['contract']['id']??0)===$contractId) $candidates[]=$row;
        }
        if (count($candidates)===1) {
            $candidate=$candidates[0];
            $due=(string)$candidate['due_date'];
        } elseif (count($candidates)>1) {
            return array_merge($base,['error'=>'O contrato possui várias competências no mês. A cobrança precisa ser conferida para evitar duplicidade.']);
        }
        // Fatura anterior criada manualmente no mesmo mês não deve originar
        // um segundo pedido, mesmo que possua competência distinta da data do SaaS.
        $existingSt=$pdo->prepare('SELECT * FROM contract_generations WHERE company_id=? AND contract_id=? AND competence BETWEEN ? AND ? AND COALESCE(order_id,0)>0 ORDER BY competence');
        $existingSt->execute([$billingId,$contractId,$monthStart,$monthEnd]);
        $existing=$existingSt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if (count($existing)>1) {
            return array_merge($base,['error'=>'Há mais de uma cobrança deste contrato no mesmo mês. Confirme as competências na Próspero antes de renovar.']);
        }
        if (count($existing)===1) {
            $due=(string)$existing[0]['competence'];
            if ($candidate && (string)$candidate['due_date']!==$due) $candidate=[];
        } elseif (!$candidate) {
            return array_merge($base,['error'=>'O contrato automático tem dia de vencimento diferente da assinatura e não possui competência faturável neste mês. Confira a vigência dos itens do contrato antes de gerar.']);
        }
        $divergence='A assinatura tem vencimento em '.date('d/m/Y',strtotime($cycleDue)).', enquanto o contrato prevê o dia '.$billingDay.'. A cobrança utiliza a competência real do contrato, sem alterar as datas cadastradas.';
    }
    $st=$pdo->prepare('SELECT * FROM contract_generations WHERE company_id=? AND contract_id=? AND competence=? LIMIT 1');
    $st->execute([$billingId,$contractId,$due]);$generation=$st->fetch(PDO::FETCH_ASSOC)?:[];
    $receivable=[];$boleto=[];
    if ($generation) {
        $receivableId=(int)($generation['receivable_id']??0);
        if ($receivableId>0) {
            $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=? AND contract_generation_id=? LIMIT 1');
            $st->execute([$billingId,$receivableId,(int)$generation['id']]);$receivable=$st->fetch(PDO::FETCH_ASSOC)?:[];
        }
        if (billing_table_exists($pdo,'finance_boleto_charges')) {
            $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND generation_id=? ORDER BY id DESC LIMIT 1');
            $st->execute([$billingId,(int)$generation['id']]);$boleto=$st->fetch(PDO::FETCH_ASSOC)?:[];
        }
    }
    $amount=round((float)($generation['amount']??$contract['total_amount']??0),2);
    if (!$generation || (int)($generation['order_id']??0)<=0) {
        if (!$candidate) {
            foreach (contracts_generation_candidates($pdo,$billingId,$due,$due,max(0,(int)($link['person_id']??0)),'AUTOMATICA') as $row) {
                if ((int)($row['contract']['id']??0)===$contractId && (string)$row['due_date']===$due) {
                    $candidate=$row;break;
                }
            }
        }
        if ($candidate) $amount=round((float)$candidate['amount'],2);
    }
    return array_merge($base,[
        'eligible'=>true,'link'=>$link,'contract'=>$contract,'subscription'=>$sub,
        'billing_company_id'=>$billingId,'contract_id'=>$contractId,'due_date'=>$due,
        'cycle_due_date'=>$cycleDue,'due_date_note'=>$divergence,
        'generation'=>$generation,'receivable'=>$receivable,'boleto'=>$boleto,
        'candidate'=>$candidate,'amount'=>$amount,
    ]);
}

/**
 * Opcoes de contratacao do trial. Sempre usa periodos/descontos cadastrados
 * no bridge SaaS e precos atuais dos servicos mapeados na faturadora.
 * Efeito somente de leitura. Nao gera contrato nem fatura.
 */
function saas_renewal_trial_options(PDO $pdo,array $ctx): array {
    if (strtoupper((string)($ctx['contract']['status']??''))!=='TESTE') return [];
    $billingId=(int)($ctx['billing_company_id']??0);
    $saasId=(int)($ctx['company_id']??0);
    $linkId=(int)($ctx['link']['id']??0);
    if ($billingId<=0||$saasId<=0||$linkId<=0||!saas_sub_bridge_commercial_policy_ready($pdo)) return [];
    $all=saas_sub_bridge_enabled_billable_modules($pdo,$saasId,$billingId);
    if (!$all) return [];
    $selected=saas_sub_bridge_billing_selection($pdo,$linkId,$all,[]);
    $billable=(array)($selected['billable']??[]);
    if (!$billable) return [];
    $options=[];
    foreach(saas_sub_bridge_term_options($pdo) as $term){
        $months=(int)$term['term_months'];
        if (!in_array($months,[1,3,6,12],true)) continue;
        $discount=max(0.,min(100.,(float)($term['discount_percent']??0)));
        $amount=0.;$gross=0.;
        foreach($billable as $module){
            $product=saas_sub_bridge_product_snapshot($pdo,$billingId,(int)$module['product_id']);
            $calc=saas_sub_bridge_cycle_values(saas_sub_bridge_module_monthly($module,$product),(float)($module['quantity']??1),$months,$discount);
            $amount+=(float)$calc['net'];$gross+=(float)$calc['gross'];
        }
        $options[$months]=[
            'months'=>$months,'label'=>(string)($term['label']??saas_sub_bridge_term_billing_type($months)),
            'discount_percent'=>$discount,'gross'=>round($gross,2),'amount'=>round($amount,2),
        ];
    }
    return $options;
}

function saas_renewal_generate(PDO $pdo, int $saasCompanyId, ?int $operatorId, ?int $trialTermMonths=null): array {
    $ctx=saas_renewal_context($pdo,$saasCompanyId);
    if (empty($ctx['eligible'])) throw new RuntimeException((string)($ctx['error']??'Contrato não elegível.'));
    $due=(string)$ctx['due_date'];$bid=(int)$ctx['billing_company_id'];
    $expires=substr((string)($ctx['subscription']['ends_at']??''),0,10);
    // O período de TESTE é sempre o marco do aviso de D-3, mesmo quando
    // o dia fixo do contrato é anterior ao fim do trial.
    $trialSubscription=strtolower((string)($ctx['subscription']['status']??''))==='trial';
    $base=$trialSubscription && $expires!=='' ? $expires : (($expires!==''&&$expires<=$due)?$expires:$due);
    $lead=(new DateTimeImmutable($base))->modify('-3 days')->format('Y-m-d');
    if (date('Y-m-d')<$lead) throw new RuntimeException('A cobrança fica disponível nos últimos três dias anteriores ao vencimento.');
    if ((int)($ctx['generation']['order_id']??0)>0) {
        return ['existing'=>true,'message'=>'Pedido desta competência já existente. Nenhuma nova cobrança foi criada.'];
    }
    if ((float)$ctx['amount']<=0 && strtoupper((string)($ctx['contract']['status']??''))==='ATIVO') {
        throw new RuntimeException('Nenhuma competência com valor positivo. Confira os itens e o contrato.');
    }
    // A própria rotina de Contratos já impede duplicidade por empresa/contrato/competência.
    // Lock adicional evita cliques concorrentes no portal de regularização.
    $lock='nexa_renov_saas_'.$saasCompanyId;
    $s=$pdo->prepare('SELECT GET_LOCK(?,10)');$s->execute([$lock]);
    if ((int)$s->fetchColumn()!==1) throw new RuntimeException('Outra cobrança está em processamento. Atualize a página.');
    try {
        $ctx=saas_renewal_context($pdo,$saasCompanyId);
        if ($ctx['due_date']!==$due) throw new RuntimeException('O vencimento mudou durante a operação. Atualize a página.');
        if ((int)($ctx['generation']['order_id']??0)>0) return ['existing'=>true,'message'=>'Pedido desta competência já gerado.'];
        // Ao fim do teste, o contrato TESTE passa a ATIVO apenas para este ciclo
        // automático. A assinatura continua trial até a baixa financeira.
        if (strtoupper((string)($ctx['contract']['status']??''))==='TESTE') {
            // Preparacao da primeira competencia atomica: falhas nao deixam
            // contrato ATIVO sem itens ou sem candidato faturavel.
            $pdo->beginTransaction();
            try {
            // A primeira contratacao depende da escolha expressa de periodicidade.
            // Nao antecipar assinatura ATIVA: isto so acontece na baixa financeira.
            $options=saas_renewal_trial_options($pdo,$ctx);
            if ($trialTermMonths===null || !isset($options[$trialTermMonths])) {
                throw new RuntimeException('Selecione uma periodicidade comercial habilitada antes da primeira cobranca.');
            }
            $term=$options[$trialTermMonths];
            if ((float)$term['amount']<=0) throw new RuntimeException('A modalidade selecionada nao possui servicos faturaveis.');
            $allModules=saas_sub_bridge_enabled_billable_modules($pdo,$saasCompanyId,$bid);
            $selection=saas_sub_bridge_billing_selection($pdo,(int)$ctx['link']['id'],$allModules,[]);
            if (!$selection['billable']) throw new RuntimeException('Nao ha modulos faturaveis vinculados a este contrato.');
            $start=new DateTimeImmutable($due.' 00:00:00');
            saas_sub_bridge_sync_active_contract_items(
                $pdo,$bid,(int)$ctx['contract_id'],(array)$selection['billable'],$start,
                $trialTermMonths,(float)$term['discount_percent']
            );
            $billingType=saas_sub_bridge_term_billing_type($trialTermMonths);
            $st=$pdo->prepare("UPDATE contracts SET status='ATIVO',default_billing_type=?,release_date=COALESCE(release_date,CURDATE()),updated_at=NOW() WHERE company_id=? AND id=? AND status='TESTE' AND COALESCE(billing_mode,'MANUAL')='AUTOMATICA'");
            $st->execute([$billingType,$bid,(int)$ctx['contract_id']]);
            if ($st->rowCount()!==1) throw new RuntimeException('O contrato mudou durante a contratacao. Atualize a pagina.');
            $pdo->prepare("UPDATE saas_subscription_links SET renewal_term_months=?,renewal_discount_percent=?,lifecycle_status='PENDING_PAYMENT',last_sync_at=NOW(),updated_at=NOW() WHERE id=? AND saas_company_id=?")
                ->execute([$trialTermMonths,(float)$term['discount_percent'],(int)$ctx['link']['id'],$saasCompanyId]);
            $check=saas_renewal_context($pdo,$saasCompanyId);
            if (empty($check['candidate']) || (float)($check['amount']??0)<=0) {
                throw new RuntimeException('O contrato nao produziu uma competencia faturavel. Corrija a configuracao antes de prosseguir.');
            }
            $pdo->commit();
            } catch(Throwable $trialError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $trialError;
            }
        }
        $ctx=saas_renewal_context($pdo,$saasCompanyId);
        if (!$ctx['candidate'] || (float)$ctx['amount']<=0) throw new RuntimeException('Nenhum item faturável encontrado. Confira o mapa de módulos, a natureza financeira e as datas do contrato.');
        $res=contracts_generate_selected($pdo,$bid,$operatorId,$due,$due,[(int)$ctx['contract_id'].'|'.$due],max(0,(int)($ctx['link']['person_id']??0)),'AUTOMATICA');
        if (empty($res['generated'])) {
            $detail=implode('; ',(array)($res['errors']??[]));
            throw new RuntimeException($detail!==''?$detail:'Não foi possível gerar a competência. Verifique o contrato.');
        }
        return ['existing'=>false,'message'=>'Pedido e Conta a Receber gerados. '.implode('; ',(array)($res['billing_errors']??[]))];
    } finally {
        $r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lock]);
    }
}

function saas_renewal_reconcile(PDO $pdo,int $saasCompanyId): bool {
    $ctx=saas_renewal_context($pdo,$saasCompanyId);
    if (empty($ctx['eligible'])) return false;
    $generation=(array)$ctx['generation'];$title=(array)$ctx['receivable'];$sub=(array)$ctx['subscription'];
    if ((int)($generation['order_id']??0)<=0 || !$title ||
        strtoupper((string)($title['status']??''))!=='BAIXADO' ||
        (float)($title['open_amount']??999)>0.009 || (float)($title['paid_amount']??0)<=0) return false;
    $orderSt=$pdo->prepare('SELECT status FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
    $orderSt->execute([(int)$ctx['billing_company_id'],(int)$generation['order_id']]);
    $orderStatus=strtolower((string)$orderSt->fetchColumn());
    if ($orderStatus==='' || in_array($orderStatus,['cancelado','cancelled','canceled'],true)) return false;
    // Comprova que a baixa veio do financeiro, não de um campo isolado.
    $st=$pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=? AND COALESCE(status,'ATIVO')='ATIVO'");
    $st->execute([(int)$ctx['billing_company_id'],(int)$title['id']]);
    if ((int)$st->fetchColumn()<1) return false;
    $link=(array)$ctx['link'];$due=(string)($ctx['cycle_due_date']??$ctx['due_date']);
    $billingDue=(string)$ctx['due_date'];
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT * FROM saas_subscription_links WHERE id=? AND saas_company_id=? FOR UPDATE');
        $st->execute([(int)$link['id'],$saasCompanyId]);$locked=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if (!$locked || substr((string)($locked['next_due_date']??''),0,10)!==$due) { $pdo->commit();return false; }
        $st=$pdo->prepare('SELECT * FROM subscriptions WHERE id=? AND company_id=? FOR UPDATE');
        $st->execute([(int)$sub['id'],$saasCompanyId]);$lockedSub=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if (!$lockedSub || !in_array(strtolower((string)($lockedSub['status']??'')),['trial','active','past_due','pending_payment'],true)) {
            $pdo->rollBack();return false;
        }
        $months=max(1,min(12,(int)($locked['renewal_term_months']??1)));
        if (!in_array($months,[1,3,6,12],true)) throw new RuntimeException('Prazo de renovação cadastrado inválido.');
        $day=max(1,min(31,(int)($ctx['contract']['due_day']??substr($billingDue,8,2))));$next=$billingDue;
        // A competência quitada sempre avança apenas uma vez. Em inadimplência longa,
        // nenhuma competência posterior é emitida sem nova validação.
        do {$next=contracts_month_add($next,$months,$day);} while ($next<=date('Y-m-d'));
        $nextDue=new DateTimeImmutable($next.' 00:00:00');
        $end=$nextDue->modify('-1 second');
        $start=new DateTimeImmutable($due.' 00:00:00');
        $graceDays=max(0,min(30,(int)(saas_sub_bridge_config($pdo)['grace_days']??3)));
        $grace=$nextDue->modify('+'.$graceDays.' days')->setTime(23,59,59);
        $pdo->prepare("UPDATE subscriptions SET status='active',started_at=COALESCE(started_at,NOW()),ends_at=?,next_billing_at=? WHERE id=? AND company_id=?")
            ->execute([$end->format('Y-m-d H:i:s'),$nextDue->format('Y-m-d H:i:s'),(int)$sub['id'],$saasCompanyId]);
        $pdo->prepare("UPDATE saas_subscription_links SET lifecycle_status='ACTIVE',current_period_start=?,current_period_end=?,next_due_date=?,grace_until=?,last_sync_at=NOW(),updated_at=NOW() WHERE id=? AND saas_company_id=? AND next_due_date=?")
            ->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$next,$grace->format('Y-m-d H:i:s'),(int)$link['id'],$saasCompanyId,$due]);
        // Somente bloqueio por vencimento e status de assinatura ativo/pendente;
        // contas suspensas permanecem sob análise administrativa.
        $pdo->prepare("UPDATE companies SET status='active' WHERE id=? AND status='blocked'")->execute([$saasCompanyId]);
        $pdo->commit();
        try {saas_sub_bridge_event($pdo,'PORTAL_RENEW:'.$saasCompanyId.':'.$due,'CONTRACT_RENEWED',$saasCompanyId,(int)$link['id'],(int)$ctx['billing_company_id'],'RECEIVABLE',(int)$title['id'],['competence'=>$due,'next_due_date'=>$next,'receipt_id'=>(int)$title['id'],'generation_id'=>(int)$generation['id']],true,null);}catch(Throwable $ignore){}
        return true;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}


/**
 * V11.60: vencimento bancário automático do SaaS.
 * Três dias CORRIDOS após a confirmação da cobrança, prorrogando quando
 * cair em fim de semana ou feriado bancário nacional conhecido.
 * Não altera data de contrato, competência, assinatura ou carência de acesso.
 * O Itaú pode aplicar calendário adicional (feriados municipais/regionais).
 */
function saas_renewal_bank_is_nonbusiness_day(DateTimeImmutable $day): bool {
    if ((int)$day->format('N') >= 6) return true;
    $fixed = ['01-01','04-21','05-01','09-07','10-12','11-02','11-15','11-20','12-25','12-31'];
    if (in_array($day->format('m-d'), $fixed, true)) return true;
    // Datas móveis observadas pelo calendário bancário brasileiro.
    $year = (int)$day->format('Y');
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $v = $h + $l - 7 * $m + 114;
    $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, intdiv($v, 31), $v % 31 + 1), $day->getTimezone());
    foreach (['-48 days','-47 days','-2 days','+60 days'] as $offset) {
        if ($easter->modify($offset)->format('Y-m-d') === $day->format('Y-m-d')) return true;
    }
    return false;
}

function saas_renewal_suggest_boleto_due(?DateTimeImmutable $generatedAt=null): string {
    $tz = new DateTimeZone('America/Sao_Paulo');
    $base = $generatedAt ? $generatedAt->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $due = $base->setTime(0,0)->modify('+3 days');
    for ($n=0; $n<10 && saas_renewal_bank_is_nonbusiness_day($due); $n++) {
        $due = $due->modify('+1 day');
    }
    if (saas_renewal_bank_is_nonbusiness_day($due)) throw new RuntimeException('Não foi possível definir um dia bancário válido para o boleto.');
    return $due->format('Y-m-d');
}

/**
 * Vencimento do boleto = data de vencimento do contrato (competência), ajustada
 * para o próximo dia útil bancário. Quando essa data já passou, é hoje ou está
 * além do prazo aceito pelo banco (30 dias), usa a regra anterior: 3 dias após a
 * geração, em dia útil. Não altera contrato, competência nem assinatura.
 */
function saas_renewal_bank_due(string $contractDue): string {
    $tz=new DateTimeZone('America/Sao_Paulo');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',trim($contractDue),$tz);
    if ($date && $date->format('Y-m-d')===trim($contractDue)) {
        for ($n=0; $n<10 && saas_renewal_bank_is_nonbusiness_day($date); $n++) $date=$date->modify('+1 day');
        $today=new DateTimeImmutable('today',$tz);
        $value=$date->format('Y-m-d');
        if (!saas_renewal_bank_is_nonbusiness_day($date)
            && $value>=$today->modify('+1 day')->format('Y-m-d')
            && $value<=$today->modify('+30 days')->format('Y-m-d')) {
            return $value;
        }
    }
    return saas_renewal_suggest_boleto_due();
}

/** V11.60: valida a data bancária sem vinculá-la ao vencimento da assinatura. */
function saas_renewal_validate_boleto_due(string $value,string $originalDue): string {
    // $originalDue permanece no contrato da função por compatibilidade;
    // NÃO determina a validade nem o bloqueio do boleto bancário.
    $value=trim($value);
    $tz=new DateTimeZone('America/Sao_Paulo');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$tz);
    if (!$date || $date->format('Y-m-d')!==$value) throw new RuntimeException('Vencimento bancário inválido.');
    if (saas_renewal_bank_is_nonbusiness_day($date)) throw new RuntimeException('O vencimento bancário calculado não é dia útil. Solicite suporte.');
    $today=new DateTimeImmutable('today',$tz);
    $minimum=$today->modify('+1 day')->format('Y-m-d');
    $maximum=$today->modify('+30 days')->format('Y-m-d');
    if ($value<$minimum || $value>$maximum) throw new RuntimeException('Vencimento bancário fora do prazo permitido.');
    return $value;
}

/**
 * Altera somente o vencimento do TÍTULO e do boleto ainda não transmitido.
 * A data original da assinatura, competência e contrato não são modificadas.
 * Executar sempre com o lock nomeado da competência e antes da chamada Itaú.
 */
function saas_renewal_set_unregistered_bank_due(PDO $pdo,int $bid,int $generationId,int $receivableId,int $chargeId,string $newDue,?int $userId): bool {
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=? FOR UPDATE');
        $st->execute([$bid,$receivableId]);$title=$st->fetch(PDO::FETCH_ASSOC)?:[];
        $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND id=? FOR UPDATE');
        $st->execute([$bid,$chargeId]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if(!$title || !$charge || (int)($title['contract_generation_id']??0)!==$generationId ||
           (int)($charge['generation_id']??0)!==$generationId ||
           (int)($charge['receivable_id']??0)!==$receivableId ||
           (int)($title['boleto_charge_id']??0)!==$chargeId){
            throw new RuntimeException('Vínculo da cobrança não confere com o título. Solicite conferência antes de registrar.');
        }
        if(strtoupper(trim((string)($title['status']??'')))!=='ABERTO' ||
           (float)($title['paid_amount']??0)>0.009 ||
           (float)($title['open_amount']??0)<=0.009){
            throw new RuntimeException('Título já movimentado ou liquidado; o vencimento bancário não pode ser alterado neste fluxo.');
        }
        if(strtoupper(trim((string)($charge['status']??'')))!=='AGUARDANDO_API'){
            throw new RuntimeException('O boleto não está mais aguardando registro. Confira a situação bancária.');
        }
        // Nosso número pode ter sido reservado localmente mesmo se a data antiga
        // impediu a chamada; o que bloqueia é QUALQUER indício de envio ao Itaú.
        foreach(['api_correlation_id','request_payload','response_payload','external_id','linha_digitavel','barcode','registered_at','paid_at','api_http_status','simulation_request_payload','simulated_at'] as $key){
            if(trim((string)($charge[$key]??''))!==''){
                throw new RuntimeException('Há indício de comunicação ou registro no Itaú. Não é seguro mudar o vencimento sem conferência bancária.');
            }
        }
        $oldTitle=substr((string)($title['due_date']??''),0,10);
        $oldCharge=substr((string)($charge['due_date']??''),0,10);
        if($oldTitle===$newDue && $oldCharge===$newDue){$pdo->commit();return false;}
        $st=$pdo->prepare('UPDATE finance_receivables SET due_date=?,updated_at=NOW() WHERE company_id=? AND id=?');
        $st->execute([$newDue,$bid,$receivableId]);
        $st=$pdo->prepare('UPDATE finance_boleto_charges SET last_due_date_before_change=due_date,due_date=?,due_date_updated_at=NOW(),due_date_updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?');
        $st->execute([$newDue,$userId,$bid,$chargeId]);
        // Histórico financeiro, independente da data contratual.
        $description='Regularização SaaS: vencimento bancário ajustado de '.$oldCharge.' para '.$newDue.' sem mudar competência/assinatura. Título #'.$receivableId.'.';
        $st=$pdo->prepare("INSERT INTO contract_billing_history (company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) SELECT company_id,contract_id,id,'BOLETO',?,'VENCIMENTO_BANCARIO_AJUSTADO',?,amount,NOW(),? FROM contract_generations WHERE company_id=? AND id=?");
        $st->execute([$chargeId,$description,$userId,$bid,$generationId]);
        if($st->rowCount()!==1)throw new RuntimeException('Não foi possível auditar o ajuste da cobrança.');
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/**
 * V11.56/V11.57: registro sob demanda do boleto da MESMA geração SaaS.
 * Somente POST confirmado do administrador; jamais cria Pedido ou outro título.
 * Bloqueia estados de resposta incerta, ERRO e cobrança paga.
 */
function saas_renewal_register_existing_boleto(PDO $pdo,int $saasCompanyId,?int $userId,?string $requestedBankDue=null): array {
    $ctx=saas_renewal_context($pdo,$saasCompanyId);
    if (empty($ctx['eligible'])) throw new RuntimeException('Contrato não elegível para cobrança: '.(string)($ctx['error']??''));
    $bid=(int)($ctx['billing_company_id']??0);
    $generation=(array)($ctx['generation']??[]);
    $generationId=(int)($generation['id']??0);
    if ($bid<=0 || $generationId<=0 || (int)($generation['order_id']??0)<=0) {
        throw new RuntimeException('O pedido desta competência ainda não foi gerado.');
    }
    $title=(array)($ctx['receivable']??[]);
    if (!$title || (int)($title['id']??0)<=0) throw new RuntimeException('Conta a Receber do pedido não encontrada; solicite conferência do financeiro.');
    // A data do boleto é sempre calculada no servidor (saas_renewal_bank_due):
    // vencimento do contrato, ajustado a dia útil; o parâmetro opcional antigo é ignorado.
    $bankDue=saas_renewal_validate_boleto_due(
        saas_renewal_bank_due((string)($ctx['due_date']??'')), (string)($ctx['cycle_due_date']??'')
    );
    if ((float)($title['open_amount']??0)<=0.009 || strtoupper((string)($title['status']??''))==='BAIXADO') {
        return ['ok'=>true,'message'=>'A fatura já foi liquidada; nenhum novo boleto será emitido.'];
    }
    if (!billing_table_exists($pdo,'finance_boleto_charges')) {
        throw new RuntimeException('Estrutura de boletos ainda não preparada. Atualize o banco pelo procedimento homologado.');
    }
    $resolved=billing_resolve_contract_method($pdo,$bid,(array)$ctx['contract']);
    $cfg=(array)($resolved['config']??[]);
    $method=(array)($resolved['method']??[]);
    if (!billing_is_boleto_method($method)) throw new RuntimeException('O contrato não possui forma de pagamento Boleto ativa. Confira a configuração antes de continuar.');
    if (!$cfg || empty($cfg['enabled']) || strtoupper((string)($cfg['provider']??''))!=='ITAU' || strtoupper((string)($cfg['integration_mode']??''))!=='API') {
        throw new RuntimeException('O contrato não está vinculado a uma conta de cobrança Itaú por API habilitada. Confira a configuração bancária.');
    }
    if (strtoupper(trim((string)($cfg['environment']??'')))!=='PRODUCAO') {
        throw new RuntimeException('A conta Itaú não está configurada em PRODUÇÃO; não é possível entregar um boleto válido para pagamento real.');
    }
    $lock='nexa_renew_charge_'.$bid.'_'.$generationId;
    $st=$pdo->prepare('SELECT GET_LOCK(?,10)');$st->execute([$lock]);
    if ((int)$st->fetchColumn()!==1) throw new RuntimeException('Esta cobrança está sendo processada. Atualize a tela.');
    try {
        // Releitura sob trava antes de qualquer criação ou requisição externa.
        $st=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND generation_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$bid,$generationId]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:[];
        $status=strtoupper(trim((string)($charge['status']??'')));
        if (in_array($status,['REGISTRADO','PAGO','BAIXADO'],true)) return ['ok'=>true,'message'=>'Boleto já registrado. Consulte a linha digitável ou o Pix da cobrança existente.'];
        if ($charge && $status!=='AGUARDANDO_API') {
            return ['ok'=>false,'message'=>'Cobrança #'.(int)$charge['id'].' está em '.($status?:'STATUS INDEFINIDO').'. Não foi reenviada para evitar duplicidade. Solicite conferência no Itaú.'];
        }
        if ($charge && (
            strtoupper((string)($charge['provider']??''))!=='ITAU' ||
            strtoupper((string)($charge['integration_mode']??''))!=='API' ||
            (int)($charge['bank_account_id']??0)!==(int)($resolved['bank_id']??0) ||
            (int)($charge['payment_method_id']??0)!==(int)($resolved['method_id']??0) ||
            (int)($charge['receivable_id']??0)!==(int)$title['id'] ||
            abs((float)($charge['amount']??0)-(float)($generation['amount']??0))>0.009
        )) {
            return ['ok'=>false,'message'=>'Os dados bancários ou o valor da cobrança não correspondem ao contrato atual. Solicite conferência antes de emitir.'];
        }
        if (!$charge) {
            // Outra cobrança eventualmente criada por Pedido/Receber NÃO pode ser
            // ignorada apenas porque seu generation_id está vazio.
            $st=$pdo->prepare('SELECT id,status FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? ORDER BY id DESC LIMIT 1');
            $st->execute([$bid,(int)$title['id']]);
            $other=$st->fetch(PDO::FETCH_ASSOC)?:[];
            if($other)throw new RuntimeException('O título já possui cobrança bancária #'.(int)$other['id'].' ('.(string)$other['status'].'). Solicite conferência antes de registrar outra.');
            // Impede DDL implícita da rotina legada de cobrança em requisições web.
            $oldDdl=$GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']??null;
            $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=true;
            try { $prepared=billing_prepare_boleto_for_generation($pdo,$bid,$generationId,$userId); }
            finally {
                if ($oldDdl===null) unset($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']);
                else $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=$oldDdl;
            }
            $charge=(array)($prepared['charge']??[]);
            $status=strtoupper(trim((string)($charge['status']??'')));
        }
        if ($status!=='AGUARDANDO_API' || empty($charge['id'])) {
            return ['ok'=>false,'message'=>'A cobrança não está pronta para envio. Status: '.($status?:'NÃO INFORMADO').'.'];
        }
        // Confere sob transação o título e o boleto já preparados; NÃO altera
        // vencimento de contrato ou geração e não reemite após tentativa externa.
        saas_renewal_set_unregistered_bank_due($pdo,$bid,$generationId,(int)$title['id'],(int)$charge['id'],$bankDue,$userId);
        require_once __DIR__.'/itau_bolecode.php';
        // Execução explícita: não exige registro automático nas formas de pagamento.
        // BoleCode mantém status de incerteza como VERIFICAR_API.
        $oldDdl=$GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']??null;
        $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=true;
        try { $result=itau_bolecode_register_charge($pdo,$bid,(int)$charge['id']); }
        catch(Throwable $e) {
            error_log('[Nexa][SaaS cobrança] #'.(int)$charge['id'].' '.$e->getMessage());
            return ['ok'=>false,'message'=>'O boleto ainda não está disponível. Consulte o status da cobrança e solicite conferência bancária antes de uma nova tentativa.'];
        } finally {
            if ($oldDdl===null) unset($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']);
            else $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=$oldDdl;
        }
        if (!empty($result['processing'])) return ['ok'=>true,'message'=>'O Itaú recebeu a cobrança e está processando o registro. Atualize a página para consultar o status.'];
        return ['ok'=>true,'message'=>'Boleto registrado no Itaú. Consulte abaixo a linha digitável e o Pix, quando retornado pelo banco.'];
    } finally {
        $st=$pdo->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lock]);
    }
}

/**
 * Geração automática da cobrança quando o aviso de vencimento aparece
 * (tela de Contratação e renovação, aviso da tela Início).
 *
 * - Somente contratos ATIVOS de cobrança AUTOMÁTICA (renovação). A primeira
 *   contratação do teste continua exigindo a escolha da periodicidade.
 * - Respeita a janela de 3 dias antes do vencimento (saas_renewal_generate).
 * - Nunca duplica: usa as mesmas travas e conferências da geração manual.
 * - Registra o boleto Itaú com vencimento na data do contrato.
 * - Uma tentativa por sessão e por dia, para não chamar o banco a cada página.
 * Nunca lança exceção: falhas ficam no log e a tela continua normalmente.
 */
function saas_renewal_auto_charge(PDO $pdo,int $saasCompanyId,?int $userId): array {
    $out=['generated'=>false,'registered'=>false,'message'=>''];
    if ($saasCompanyId<=0) return $out;
    if (session_status()===PHP_SESSION_ACTIVE) {
        $key='nexa_saas_auto_charge_'.$saasCompanyId;
        if (($_SESSION[$key]??'')===date('Y-m-d')) return $out;
        $_SESSION[$key]=date('Y-m-d');
    }
    $oldDdl=$GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']??null;
    $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=true;
    try {
        $ctx=saas_renewal_context($pdo,$saasCompanyId);
        if (empty($ctx['eligible'])) return $out;
        if (strtoupper((string)($ctx['contract']['status']??''))!=='ATIVO') return $out;
        $title=(array)($ctx['receivable']??[]);
        if ($title && ((float)($title['open_amount']??0)<=0.009 || strtoupper((string)($title['status']??''))==='BAIXADO')) return $out;

        if ((int)($ctx['generation']['order_id']??0)<=0) {
            try {
                $res=saas_renewal_generate($pdo,$saasCompanyId,$userId,null);
                $out['generated']=empty($res['existing']);
            } catch (Throwable $e) {
                // Fora da janela de 3 dias ou contrato sem item faturável: nada a fazer agora.
                error_log('[Nexa][SaaS cobrança automática] Empresa '.$saasCompanyId.': '.$e->getMessage());
                return $out;
            }
        }
        try {
            $bank=saas_renewal_register_existing_boleto($pdo,$saasCompanyId,$userId);
            $out['registered']=!empty($bank['ok']);
            if (empty($bank['ok'])) error_log('[Nexa][SaaS cobrança automática] Boleto empresa '.$saasCompanyId.': '.(string)($bank['message']??''));
        } catch (Throwable $e) {
            error_log('[Nexa][SaaS cobrança automática] Boleto empresa '.$saasCompanyId.': '.$e->getMessage());
        }
        if ($out['generated']) {
            $out['message']='A cobrança deste vencimento foi gerada automaticamente.'.($out['registered']?' O boleto com Pix já está disponível abaixo.':'');
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[Nexa][SaaS cobrança automática] Empresa '.$saasCompanyId.': '.$e->getMessage());
        return $out;
    } finally {
        if ($oldDdl===null) unset($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']);
        else $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']=$oldDdl;
    }
}
