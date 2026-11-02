<?php
declare(strict_types=1);
/** Regras diárias de acesso e aviso de assinatura SaaS (horário da empresa/servidor).
 * D=dia de vencimento ainda acessível; D+1 .. D+3 carência; bloqueio em D+4.
 * Não altera dados e nunca cria cobrança via GET.
 */
/**
 * V11.59: prazo informativo de tolerância (D+1 até D+3).
 * Não modifica o cálculo da fase nem a autorização de acesso.
 * A contagem mostra dias inteiros APÓS hoje, evitando incluir o dia atual.
 */
if (!function_exists('nexa_saas_grace_notice')) {
function nexa_saas_grace_notice(array $cycle): string {
    $dueRaw=(string)($cycle['due_date']??'');
    $due=DateTimeImmutable::createFromFormat('!Y-m-d',$dueRaw);
    if (!$due || $due->format('Y-m-d')!==$dueRaw) return 'Regularize o pagamento dentro do prazo de tolerância.';
    $deadline=$due->modify('+3 days')->format('d/m/Y');
    $days=(int)($cycle['days_left']??0);
    if ($days<=-3) return "O prazo de regularização termina hoje ({$deadline}), último dia de tolerância.";
    $daysAfterToday=max(0,3+$days);
    $remaining=$daysAfterToday===1 ? 'Resta 1 dia após hoje.' : "Restam {$daysAfterToday} dias após hoje.";
    return "Você tem até {$deadline} para regularizar o pagamento. {$remaining}";
}
}

if (!function_exists('nexa_saas_cycle_state')) {
function nexa_saas_cycle_state(array $sub, ?DateTimeImmutable $now=null): array {
    $now=$now??new DateTimeImmutable('now');
    $status=strtolower((string)($sub['status']??''));
    $raw=(string)($sub['ends_at']??'');
    $empty=['phase'=>'none','blocked'=>false,'days_left'=>null,'due_date'=>'','message'=>''];
    if (!in_array($status,['trial','active','past_due'],true) || $raw==='') return $empty;
    try { $due=new DateTimeImmutable(substr($raw,0,10).' 00:00:00', $now->getTimezone()); }
    catch(Throwable $e) {return $empty;}
    $today=$now->setTime(0,0,0);
    $days=(int)$today->diff($due)->format('%r%a');
    $dueLabel=$due->format('d/m/Y');
    if ($days>3) return ['phase'=>'normal','blocked'=>false,'days_left'=>$days,'due_date'=>$due->format('Y-m-d'),'message'=>''];
    if ($days>0) return ['phase'=>'warning','blocked'=>false,'days_left'=>$days,'due_date'=>$due->format('Y-m-d'),'message'=>"Seu período ".($status==='trial'?'de teste':'contratado')." termina em {$days} dia(s), em {$dueLabel}. Confira a cobrança e pague sem perder o acesso."];
    $limit=$due->modify('+3 days')->format('d/m/Y');
    if ($days===0) return ['phase'=>'last_day','blocked'=>false,'days_left'=>0,'due_date'=>$due->format('Y-m-d'),'message'=>"Hoje ({$dueLabel}) é o vencimento. Você poderá regularizar até {$limit} (3 dias de tolerância)."];
    if ($days>=-3) {
        $grace=['phase'=>'grace','blocked'=>false,'days_left'=>$days,'due_date'=>$due->format('Y-m-d'),'message'=>''];
        $grace['message']="Seu prazo venceu em {$dueLabel}. ".nexa_saas_grace_notice($grace);
        return $grace;
    }
    return ['phase'=>'blocked','blocked'=>true,'days_left'=>$days,'due_date'=>$due->format('Y-m-d'),'message'=>"O prazo de tolerância terminou em {$limit}. Regularize o pagamento para retornar ao sistema."];
}
}


/**
 * Somente texto de aviso: o contrato comercial vinculado prevalece sobre o rótulo
 * temporário da assinatura SaaS (por exemplo, subscriptions.status='trial'
 * enquanto contracts.status='ATIVO'). Não altera datas, bloqueio ou cobrança.
 */
if (!function_exists('nexa_saas_cycle_notice_with_contract')) {
function nexa_saas_cycle_notice_with_contract(array $cycle, array $contract): array {
    $phase=(string)($cycle['phase']??'');
    if (!in_array($phase,['warning','last_day','grace','blocked'],true)) return $cycle;
    $status=strtoupper(trim((string)($contract['status']??'')));
    if ($status!=='ATIVO' && $status!=='TESTE') return $cycle;
    $isTrial=$status==='TESTE';
    $automatic=strtoupper(trim((string)($contract['billing_mode']??'')))==='AUTOMATICA';
    $dueRaw=(string)($cycle['due_date']??'');
    $dueLabel='';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$dueRaw)) {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$dueRaw);
        if ($date && $date->format('Y-m-d')===$dueRaw) $dueLabel=$date->format('d/m/Y');
    }
    $dateText=$dueLabel!==''?' em '.$dueLabel:'';
    $days=(int)($cycle['days_left']??0);
    $limitText=$dueLabel!==''?(new DateTimeImmutable($dueRaw))->modify('+3 days')->format('d/m/Y'):'';
    if ($isTrial) {
        if ($phase==='warning') $message=($days===1?'Falta 1 dia':'Faltam '.$days.' dias')." para terminar seu período de teste{$dateText}. Você já pode escolher a contratação e realizar o pagamento.";
        elseif ($phase==='last_day') $message='Hoje é o último dia do seu período de teste'.$dateText.'. Você poderá contratar e pagar até '.$limitText.' (3 dias de tolerância).';
        elseif ($phase==='grace') $message='Seu período de teste terminou'.$dateText.'. '.nexa_saas_grace_notice($cycle);
        else $message='Seu período de teste terminou'.$dateText.'. A tolerância terminou em '.$limitText.'. Escolha uma contratação e pague para acessar o sistema.';
    } else {
        $chargeText=$automatic?'cobrança automática':'cobrança';
        if ($phase==='warning') $message=($days===1?'Falta 1 dia':'Faltam '.$days.' dias')." para o vencimento da sua assinatura{$dateText}. Confira a {$chargeText} e, se desejar, antecipe o pagamento.";
        elseif ($phase==='last_day') $message='Hoje é o dia do vencimento da sua assinatura'.$dateText.'. Regularize a '.$chargeText.' até '.$limitText.' (3 dias de tolerância).';
        elseif ($phase==='grace') $message='Sua assinatura venceu'.$dateText.'. '.nexa_saas_grace_notice($cycle);
        else $message='Sua assinatura está vencida. A tolerância terminou em '.$limitText.'. Regularize o pagamento para voltar a acessar o sistema.';
    }
    $cycle['message']=$message;
    $cycle['notice_kind']=$isTrial?'trial':'contract';
    return $cycle;
}
}

/** Consulta leve, somente leitura, limitada aos dias com aviso. */
if (!function_exists('nexa_saas_cycle_notice_for_contract')) {
function nexa_saas_cycle_notice_for_contract(PDO $pdo,int $saasCompanyId,array $cycle): array {
    if ($saasCompanyId<=0 || !in_array((string)($cycle['phase']??''),['warning','last_day','grace','blocked'],true)) return $cycle;
    try {
        $st=$pdo->prepare('SELECT c.status, c.billing_mode FROM saas_subscription_links l JOIN contracts c ON c.id=l.contract_id AND c.company_id=l.billing_company_id WHERE l.saas_company_id=? LIMIT 1');
        $st->execute([$saasCompanyId]);
        $contract=$st->fetch(PDO::FETCH_ASSOC)?:[];
        $st->closeCursor();
        if ($contract) return nexa_saas_cycle_notice_with_contract($cycle,$contract);
    } catch (Throwable $e) {
        // Instalações sem o vínculo SaaS/Contratos mantêm o aviso original.
        error_log('[SaaS aviso] Contrato não consultado: '.$e->getMessage());
    }
    return $cycle;
}
}
