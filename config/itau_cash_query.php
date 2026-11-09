<?php
declare(strict_types=1);

/**
 * Nexa Próspero - V11.12
 * Consulta individual protegida de títulos Itaú (Cash Management v2).
 *
 * Objetivo: resolver estados incertos de registro sem reenviar cobrança.
 * A consulta é somente leitura no banco e no Itaú; o Nexa só consolida o
 * status local quando o título é encontrado de forma inequívoca.
 */

require_once __DIR__ . '/itau_boleto.php';
require_once __DIR__ . '/billing_module.php';

if (!function_exists('itau_cash_query_endpoint')) {
    function itau_cash_query_endpoint(array $rawCfg): string {
        $cfg = itau_boleto_normalize_config($rawCfg);
        return strtoupper((string)($cfg['environment'] ?? 'HOMOLOGACAO')) === 'PRODUCAO'
            ? 'https://secure.api.cloud.itau.com.br/boletoscash/v2/boletos'
            : 'https://devportal.itau.com.br/sandboxapi/cash_management_ext_v2/v2/boletos';
    }
}

if (!function_exists('itau_cash_query_auth_config')) {
    function itau_cash_query_auth_config(array $rawCfg): array {
        $cfg = $rawCfg;
        $cashId = trim((string)($cfg['itau_cash_client_id'] ?? ''));
        $cashSecret = trim((string)($cfg['itau_cash_client_secret'] ?? ''));

        // Nunca mistura metade de uma credencial dedicada com metade da emissão.
        // Se o par dedicado não estiver completo, usa integralmente o par atual.
        if ($cashId !== '' && $cashSecret !== '') {
            $cfg['api_client_id'] = $cashId;
            $cfg['api_client_secret'] = $cashSecret;
        }
        return $cfg;
    }
}

if (!function_exists('itau_cash_query_schema_missing_columns')) {
    function itau_cash_query_schema_missing_columns(PDO $pdo): array {
        $required = [
            'api_last_check_at',
            'api_last_check_by_user_id',
            'api_last_check_http_status',
            'api_last_check_result',
            'api_last_check_correlation_id',
            'api_last_check_message',
            'api_last_check_response',
        ];
        $missing = [];
        foreach ($required as $column) {
            if (!billing_has_column($pdo, 'finance_boleto_charges', $column)) $missing[] = $column;
        }
        return $missing;
    }
}

if (!function_exists('itau_cash_query_digits')) {
    function itau_cash_query_digits(string $value): string {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}

if (!function_exists('itau_cash_query_same_nosso_numero')) {
    function itau_cash_query_same_nosso_numero(string $a, string $b): bool {
        $da = ltrim(itau_cash_query_digits($a), '0');
        $db = ltrim(itau_cash_query_digits($b), '0');
        if ($da === '') $da = '0';
        if ($db === '') $db = '0';
        return $da === $db;
    }
}

if (!function_exists('itau_cash_query_extract')) {
    function itau_cash_query_extract(array $response, string $expectedNossoNumero): array {
        $json = $response['json'] ?? null;
        if (!is_array($json)) return ['found'=>false, 'raw'=>[]];

        $rows = $json['data'] ?? [];
        if (isset($rows['dado_boleto'])) $rows = [$rows];
        if (!is_array($rows)) $rows = [];

        $selected = null;
        $selectedItem = null;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $boleto = is_array($row['dado_boleto'] ?? null) ? $row['dado_boleto'] : [];
            $items = $boleto['dados_individuais_boleto'] ?? [];
            if (isset($items['numero_nosso_numero'])) $items = [$items];
            if (!is_array($items)) $items = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $candidate = trim((string)($item['numero_nosso_numero'] ?? ''));
                if ($candidate !== '' && itau_cash_query_same_nosso_numero($candidate, $expectedNossoNumero)) {
                    $selected = $row;
                    $selectedItem = $item;
                    break 2;
                }
            }
        }

        if (!is_array($selected) || !is_array($selectedItem)) {
            return ['found'=>false, 'raw'=>$json];
        }

        $boleto = is_array($selected['dado_boleto'] ?? null) ? $selected['dado_boleto'] : [];
        $qr = is_array($selected['dados_qrcode'] ?? null) ? $selected['dados_qrcode'] : [];
        if (!$qr && is_array($boleto['dados_qrcode'] ?? null)) $qr = $boleto['dados_qrcode'];

        return [
            'found' => true,
            'raw' => $json,
            'row' => $selected,
            'item' => $selectedItem,
            'nosso_numero' => trim((string)($selectedItem['numero_nosso_numero'] ?? '')),
            'external_id' => trim((string)($selectedItem['id_boleto_individual'] ?? $selected['id_boleto'] ?? '')),
            'dac_titulo' => trim((string)($selectedItem['dac_titulo'] ?? '')),
            'linha_digitavel' => trim((string)($selectedItem['numero_linha_digitavel'] ?? '')),
            'barcode' => trim((string)($selectedItem['codigo_barras'] ?? '')),
            'situacao_geral' => trim((string)($selectedItem['situacao_geral_boleto'] ?? '')),
            'status_vencimento' => trim((string)($selectedItem['status_vencimento'] ?? '')),
            'pix_key' => trim((string)($qr['chave'] ?? '')),
            'pix_txid' => trim((string)($qr['txid'] ?? '')),
            'pix_emv' => trim((string)($qr['emv'] ?? '')),
            'pix_qr_base64' => trim((string)($qr['base64'] ?? '')),
            'pix_location_id' => isset($qr['id_location']) ? trim((string)$qr['id_location']) : '',
            'pix_location' => trim((string)($qr['location'] ?? '')),
            'historico' => is_array($boleto['historico'] ?? null) ? $boleto['historico'] : [],
        ];
    }
}

if (!function_exists('itau_cash_query_normalized_text')) {
    function itau_cash_query_normalized_text(string $value): string {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $map = ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C'];
        return strtr($value, $map);
    }
}

if (!function_exists('itau_cash_query_result_status')) {
    function itau_cash_query_result_status(array $data): array {
        $situation = itau_cash_query_normalized_text((string)($data['situacao_geral'] ?? ''));
        if ($situation === '') return ['status'=>'REGISTRADO','result'=>'ENCONTRADO','message'=>'Título localizado no Itaú; registro bancário confirmado.'];
        if (str_contains($situation, 'LIQUID') || str_contains($situation, 'PAGO') || str_contains($situation, 'QUITAD')) {
            return ['status'=>'VERIFICAR_PAGAMENTO','result'=>'ENCONTRADO_PAGO','message'=>'Título localizado no Itaú com indicação de pagamento/liquidação. Sincronize as liquidações para efetivar a baixa financeira no Nexa.'];
        }
        if (str_contains($situation, 'ABERTO') || str_contains($situation, 'ATIVO') || str_contains($situation, 'A VENCER') || str_contains($situation, 'VENCIDO')) {
            return ['status'=>'REGISTRADO','result'=>'ENCONTRADO','message'=>'Título localizado no Itaú; registro bancário confirmado.'];
        }
        // Situações como BAIXADO/CANCELADO não devem ser convertidas automaticamente,
        // porque podem exigir análise do título/recebível local antes de qualquer mudança.
        return ['status'=>'VERIFICAR_API','result'=>'ENCONTRADO_REVISAR','message'=>'Título localizado no Itaú com situação "'.trim((string)($data['situacao_geral'] ?? '')).'". O reenvio permanece bloqueado para conferência manual.'];
    }
}

if (!function_exists('itau_cash_query_audit')) {
    function itau_cash_query_audit(PDO $pdo, int $companyId, int $chargeId, int $userId, ?int $httpStatus, string $result, string $correlationId, string $message, string $responseBody): void {
        if (itau_cash_query_schema_missing_columns($pdo)) return;
        $st = $pdo->prepare('UPDATE finance_boleto_charges SET api_last_check_at=NOW(),api_last_check_by_user_id=?,api_last_check_http_status=?,api_last_check_result=?,api_last_check_correlation_id=?,api_last_check_message=?,api_last_check_response=?,updated_at=NOW() WHERE company_id=? AND id=?');
        $st->execute([$userId > 0 ? $userId : null, $httpStatus, $result ?: null, $correlationId ?: null, $message !== '' ? mb_substr($message,0,500,'UTF-8') : null, $responseBody !== '' ? $responseBody : null, $companyId, $chargeId]);
    }
}

if (!function_exists('itau_cash_query_charge_protected')) {
    function itau_cash_query_charge_protected(PDO $pdo, int $companyId, int $chargeId, int $userId = 0): array {
        $missing = itau_cash_query_schema_missing_columns($pdo);
        if ($missing) throw new RuntimeException('V11.12: estrutura de acompanhamento Itaú incompleta. Use o Atualizador do Banco. Ausente: finance_boleto_charges.'.implode(', finance_boleto_charges.', $missing).'.');

        if (!function_exists('itau_bolecode_charge_context')) require_once __DIR__ . '/itau_bolecode.php';
        $charge = itau_bolecode_charge_context($pdo, $companyId, $chargeId);
        $status = strtoupper(trim((string)($charge['status'] ?? '')));
        if (!in_array($status, ['ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API'], true)) {
            throw new RuntimeException('V11.12: a consulta protegida é destinada somente a cobranças ENVIANDO_API, PROCESSANDO_API ou VERIFICAR_API. Status atual: '.($status ?: 'SEM STATUS').'.');
        }
        if ($status === 'ENVIANDO_API') {
            $updatedTs = !empty($charge['updated_at']) ? strtotime((string)$charge['updated_at']) : false;
            if ($updatedTs !== false && $updatedTs > time() - 60) {
                throw new RuntimeException('V11.12: a cobrança ainda está em janela de envio. Aguarde pelo menos 60 segundos antes de consultar o Itaú para não disputar com a requisição em andamento.');
            }
        }

        $nosso = trim((string)($charge['nosso_numero'] ?? ''));
        if ($nosso === '') throw new RuntimeException('V11.12: cobrança sem Nosso Número reservado; não há identificação segura para consulta individual no Itaú.');

        $cfg = billing_bank_config($pdo, $companyId, (int)($charge['bank_account_id'] ?? 0));
        if (!$cfg) throw new RuntimeException('Configuração bancária da cobrança não encontrada na empresa selecionada.');
        $beneficiary = itau_cash_query_digits((string)($cfg['itau_beneficiary_id'] ?? ''));
        $wallet = itau_cash_query_digits((string)($cfg['carteira'] ?? ''));
        if ($beneficiary === '') throw new RuntimeException('Informe o ID Beneficiário Itaú na configuração da conta antes de consultar o título.');
        if ($wallet === '') throw new RuntimeException('Informe a carteira Itaú na configuração da conta antes de consultar o título.');

        $authCfg = itau_cash_query_auth_config($cfg);
        $check = itau_boleto_validate_config($authCfg, true);
        if (empty($check['ok']) || empty($check['ready_for_token'])) {
            $messages = [];
            foreach ((array)($check['errors'] ?? []) as $e) $messages[] = (string)($e['message'] ?? '');
            if (!$messages) $messages[] = 'Credencial/certificado da consulta Itaú ainda não está completa.';
            throw new RuntimeException(implode(' ', array_filter($messages)));
        }

        $corr = itau_boleto_uuid();
        $endpoint = itau_cash_query_endpoint($authCfg);
        $query = [
            'id_beneficiario' => $beneficiary,
            'codigo_carteira' => str_pad($wallet, 3, '0', STR_PAD_LEFT),
            'nosso_numero' => str_pad(itau_cash_query_digits($nosso), 8, '0', STR_PAD_LEFT),
            'view' => 'specific',
            'page_size' => 20,
            'page' => 0,
        ];
        $url = $endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            $token = itau_boleto_get_token($authCfg);
            $headers = itau_boleto_api_headers($authCfg, (string)$token['access_token'], $corr);
            $resp = itau_boleto_http($authCfg, 'GET', $url, $headers, null);
        } catch (Throwable $e) {
            itau_cash_query_audit($pdo, $companyId, $chargeId, $userId, null, 'FALHA_COMUNICACAO', $corr, $e->getMessage(), json_encode(['exception'=>$e->getMessage()], JSON_UNESCAPED_UNICODE) ?: '');
            throw new RuntimeException('V11.12: falha ao consultar o título no Itaú. Nenhum reenvio foi realizado. '.$e->getMessage());
        }

        $http = (int)($resp['status'] ?? 0);
        $body = (string)($resp['body'] ?? '');
        if ($http === 401 || $http === 403) {
            $msg = 'A credencial usada não possui acesso à consulta Cash Management do Itaú (HTTP '.$http.'). Configure o Client ID/Secret específico de consulta, ou solicite ao banco o escopo boletoscash-boletos-consulta_titulo.';
            itau_cash_query_audit($pdo, $companyId, $chargeId, $userId, $http, 'SEM_ESCOPO', $corr, $msg, $body);
            throw new RuntimeException('V11.12: '.$msg);
        }
        if ($http === 404) {
            $msg = 'O Itaú não localizou o título pelo Beneficiário + Carteira + Nosso Número. O reenvio permanece bloqueado para evitar duplicidade.';
            itau_cash_query_audit($pdo, $companyId, $chargeId, $userId, $http, 'NAO_ENCONTRADO', $corr, $msg, $body);
            return ['ok'=>true,'found'=>false,'resolved'=>false,'status'=>$status,'http_status'=>$http,'message'=>$msg,'response'=>$resp,'correlation_id'=>$corr];
        }
        if ($http < 200 || $http >= 300) {
            $msg = 'Consulta Itaú recusada. HTTP '.$http.': '.itau_boleto_response_message($resp);
            itau_cash_query_audit($pdo, $companyId, $chargeId, $userId, $http, 'ERRO_HTTP', $corr, $msg, $body);
            throw new RuntimeException('V11.12: '.$msg.' Nenhum reenvio foi realizado.');
        }

        $data = itau_cash_query_extract($resp, $nosso);
        if (empty($data['found'])) {
            $msg = 'A consulta respondeu com sucesso, mas não trouxe um título correspondente ao Nosso Número local. O reenvio permanece bloqueado.';
            itau_cash_query_audit($pdo, $companyId, $chargeId, $userId, $http, 'NAO_ENCONTRADO', $corr, $msg, $body);
            return ['ok'=>true,'found'=>false,'resolved'=>false,'status'=>$status,'http_status'=>$http,'message'=>$msg,'response'=>$resp,'correlation_id'=>$corr];
        }

        $resolution = itau_cash_query_result_status($data);
        $newStatus = (string)$resolution['status'];
        $result = (string)$resolution['result'];
        $message = (string)$resolution['message'];

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status FROM finance_boleto_charges WHERE company_id=? AND id=? FOR UPDATE');
            $lock->execute([$companyId,$chargeId]);
            $currentStatus = strtoupper(trim((string)($lock->fetchColumn() ?: '')));
            if (!in_array($currentStatus, ['ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API'], true)) {
                throw new RuntimeException('O status local foi alterado durante a consulta. Atualize a página antes de continuar.');
            }

            $sql = "UPDATE finance_boleto_charges SET status=?,nosso_numero=COALESCE(NULLIF(?,''),nosso_numero),external_id=COALESCE(NULLIF(?,''),external_id),dac_titulo=COALESCE(NULLIF(?,''),dac_titulo),linha_digitavel=COALESCE(NULLIF(?,''),linha_digitavel),barcode=COALESCE(NULLIF(?,''),barcode),pix_key=COALESCE(NULLIF(?,''),pix_key),pix_txid=COALESCE(NULLIF(?,''),pix_txid),pix_emv=COALESCE(NULLIF(?,''),pix_emv),pix_qr_base64=COALESCE(NULLIF(?,''),pix_qr_base64),pix_location_id=COALESCE(NULLIF(?,''),pix_location_id),pix_location=COALESCE(NULLIF(?,''),pix_location),registered_at=CASE WHEN ? IN ('REGISTRADO','VERIFICAR_PAGAMENTO') THEN COALESCE(registered_at,NOW()) ELSE registered_at END,error_message=?,api_last_check_at=NOW(),api_last_check_by_user_id=?,api_last_check_http_status=?,api_last_check_result=?,api_last_check_correlation_id=?,api_last_check_message=?,api_last_check_response=?,updated_at=NOW() WHERE company_id=? AND id=?";
            $st = $pdo->prepare($sql);
            $locationId = trim((string)($data['pix_location_id'] ?? ''));
            $st->execute([
                $newStatus,
                (string)($data['nosso_numero'] ?? ''), (string)($data['external_id'] ?? ''), (string)($data['dac_titulo'] ?? ''),
                (string)($data['linha_digitavel'] ?? ''), (string)($data['barcode'] ?? ''), (string)($data['pix_key'] ?? ''),
                (string)($data['pix_txid'] ?? ''), (string)($data['pix_emv'] ?? ''), (string)($data['pix_qr_base64'] ?? ''),
                $locationId !== '' && ctype_digit($locationId) ? (int)$locationId : null, (string)($data['pix_location'] ?? ''),
                $newStatus, $newStatus === 'VERIFICAR_API' ? mb_substr($message,0,1000,'UTF-8') : null,
                $userId > 0 ? $userId : null, $http, $result, $corr, mb_substr($message,0,500,'UTF-8'), $body,
                $companyId, $chargeId
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'ok'=>true,
            'found'=>true,
            'resolved'=>$newStatus !== 'VERIFICAR_API',
            'status'=>$newStatus,
            'previous_status'=>$status,
            'http_status'=>$http,
            'message'=>$message,
            'data'=>$data,
            'response'=>$resp,
            'correlation_id'=>$corr,
        ];
    }
}

if (!function_exists('itau_cash_query_situation_readonly')) {
    /**
     * Consulta a situação do título no Itaú SEM alterar nada no Nexa (usada para
     * conferir uma baixa feita direto no banco). Retorna checked=false quando a
     * consulta não é possível (sem escopo Cash Management, sem Nosso Número etc.).
     */
    function itau_cash_query_situation_readonly(PDO $pdo, int $companyId, array $charge): array {
        try {
            $nosso = trim((string)($charge['nosso_numero'] ?? ''));
            if ($nosso === '') return ['checked'=>false,'error'=>'Cobrança sem Nosso Número.'];
            $cfg = billing_bank_config($pdo, $companyId, (int)($charge['bank_account_id'] ?? 0));
            if (!$cfg) return ['checked'=>false,'error'=>'Configuração bancária não encontrada.'];
            $beneficiary = itau_cash_query_digits((string)($cfg['itau_beneficiary_id'] ?? ''));
            $wallet = itau_cash_query_digits((string)($cfg['carteira'] ?? ''));
            if ($beneficiary === '' || $wallet === '') return ['checked'=>false,'error'=>'Beneficiário/carteira Itaú não configurados.'];
            $authCfg = itau_cash_query_auth_config($cfg);
            $check = itau_boleto_validate_config($authCfg, true);
            if (empty($check['ok']) || empty($check['ready_for_token'])) return ['checked'=>false,'error'=>'Credencial de consulta Itaú incompleta.'];
            $corr = itau_boleto_uuid();
            $url = itau_cash_query_endpoint($authCfg) . '?' . http_build_query([
                'id_beneficiario' => $beneficiary,
                'codigo_carteira' => str_pad($wallet, 3, '0', STR_PAD_LEFT),
                'nosso_numero' => str_pad(itau_cash_query_digits($nosso), 8, '0', STR_PAD_LEFT),
                'view' => 'specific', 'page_size' => 20, 'page' => 0,
            ], '', '&', PHP_QUERY_RFC3986);
            $token = itau_boleto_get_token($authCfg);
            $resp = itau_boleto_http($authCfg, 'GET', $url, itau_boleto_api_headers($authCfg, (string)$token['access_token'], $corr), null);
            $http = (int)($resp['status'] ?? 0);
            if ($http < 200 || $http >= 300) return ['checked'=>false,'error'=>'Consulta Itaú indisponível (HTTP '.$http.').'];
            $data = itau_cash_query_extract($resp, $nosso);
            if (empty($data['found'])) return ['checked'=>false,'error'=>'Título não localizado na consulta Itaú.'];
            return ['checked'=>true,'situation'=>trim((string)($data['situacao_geral'] ?? ''))];
        } catch (Throwable $e) {
            return ['checked'=>false,'error'=>$e->getMessage()];
        }
    }
}
