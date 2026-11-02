<?php
declare(strict_types=1);
/**
 * Sicoob V7 - apenas elegibilidade LOCAL. O envio bancario permanece sob
 * sicoob_v15_register(), cujo lock de banco impede duplo POST de uma cobranca.
 */
function sicoob_v7_batch_normalize_ids(mixed $input, int $limit=200): array {
    if (!is_array($input) || count($input)>$limit) throw new RuntimeException('O lote aceita no maximo '.$limit.' cobrancas selecionadas por vez.');
    $ids=[];
    foreach ($input as $raw) {
        if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]*$/D', trim((string)$raw))) throw new RuntimeException('Identificador invalido no lote.');
        $id=(int)$raw;
        if ($id<1) throw new RuntimeException('Identificador invalido no lote.');
        $ids[$id]=$id;
    }
    if (!$ids) throw new RuntimeException('Marque pelo menos um boleto Sicoob aguardando registro.');
    return array_values($ids);
}
function sicoob_v7_batch_eligible(array $charge): bool {
    return strtoupper(trim((string)($charge['provider']??'')))==='SICOOB'
        && strtoupper(trim((string)($charge['integration_mode']??'')))==='API'
        && strtoupper(trim((string)($charge['status']??'')))==='AGUARDANDO_API'
        && empty($charge['nosso_numero'])
        && empty($charge['registered_at'])
        && empty($charge['api_http_status'])
        && empty($charge['request_payload'])
        && empty($charge['response_payload']);
}
function sicoob_v7_batch_check_session(array $batch,int $companyId,int $userId,string $token): bool {
    return $token!=='' && hash_equals((string)($batch['token']??''),$token)
        && (int)($batch['company_id']??0)===$companyId
        && (int)($batch['user_id']??-1)===$userId
        && (int)($batch['created_at']??0)>time()-7200;
}
