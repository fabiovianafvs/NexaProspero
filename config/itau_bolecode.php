<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Itaú Boleto + Pix (BoleCode)
 * Implementação baseada no OpenAPI oficial "Boleto com Pix" v1.0.7.
 * Endpoint: POST /recebimentos-pix/v1/boletos-pix
 */
require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/itau_boleto.php';

if (!function_exists('itau_bolecode_base_url')) {
    function itau_bolecode_base_url(array $rawCfg): string {
        $cfg = itau_boleto_normalize_config($rawCfg);
        $env = strtoupper((string)($cfg['environment'] ?? 'HOMOLOGACAO'));
        return $env === 'PRODUCAO'
            ? 'https://pix-pj.api.itau.com/recebimentos-pix/v1'
            : 'https://pix-pj.api.hom.itau.com/recebimentos-pix/v1';
    }
}

if (!function_exists('itau_bolecode_endpoint')) {
    function itau_bolecode_endpoint(array $cfg): string {
        return rtrim(itau_bolecode_base_url($cfg), '/') . '/boletos-pix';
    }
}

if (!function_exists('itau_bolecode_digits')) {
    function itau_bolecode_digits(string $value): string {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}

if (!function_exists('itau_bolecode_clean_spaces')) {
    function itau_bolecode_clean_spaces(string $value): string {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

if (!function_exists('itau_bolecode_cut')) {
    function itau_bolecode_cut(string $value, int $max): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}

if (!function_exists('itau_bolecode_person_name')) {
    function itau_bolecode_person_name(string $value, int $max = 50): string {
        $value = preg_replace('/[^\p{L}\s]/u', ' ', trim($value)) ?? trim($value);
        $value = itau_bolecode_clean_spaces($value);
        return itau_bolecode_cut($value, $max);
    }
}

if (!function_exists('itau_bolecode_address_text')) {
    function itau_bolecode_address_text(string $value, int $max): string {
        $value = preg_replace('/[^\p{L}\p{N}\s\-\.,]/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\b(?:https?|javascript|alert)\b/iu', ' ', $value) ?? $value;
        $value = itau_bolecode_clean_spaces($value);
        return itau_bolecode_cut($value, $max);
    }
}

if (!function_exists('itau_bolecode_money')) {
    function itau_bolecode_money(float $value): string {
        // O gateway produtivo do BoleCode valida os campos monetários no formato
        // legado Itaú: 15 posições inteiras + 2 casas decimais, sem separador.
        // Ex.: R$ 5,00 => 00000000000000500; R$ 1.234,56 => 00000000000123456.
        $cents = (int) round(max(0, $value) * 100);
        return str_pad((string)$cents, 17, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('itau_bolecode_percent5')) {
    function itau_bolecode_percent5(float $value): string {
        // Percentuais seguem o formato legado: 7 inteiros + 5 decimais,
        // sem ponto. Ex.: 2,00000% => 000000200000.
        $scaled = (int) round(max(0, $value) * 100000);
        return str_pad((string)$scaled, 12, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('itau_bolecode_valid_date')) {
    function itau_bolecode_valid_date(string $date): bool {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        return $d !== false && $d->format('Y-m-d') === trim($date);
    }
}

if (!function_exists('itau_bolecode_charge_context')) {
    function itau_bolecode_charge_context(PDO $pdo, int $companyId, int $chargeId): array {
        $crossBillingRead = (function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo))
    || (function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_billing_read'));
        if (!$crossBillingRead) {
            billing_ensure_schema($pdo);
        } elseif (!billing_table_exists($pdo, 'finance_boleto_charges')) {
            throw new RuntimeException('Estrutura de Cobranças/Boletos não está disponível na empresa selecionada.');
        }
        $sql = "SELECT bc.*, fr.person_id, fr.description AS receivable_description,
                       fp.name AS payer_name, fp.fantasy_name AS payer_fantasy_name,
                       fp.document AS payer_document, fp.person_type AS payer_person_type,
                       fp.email AS payer_email, fp.phone AS payer_phone, fp.whatsapp AS payer_whatsapp,
                       fp.zip_code AS payer_zip_code, fp.address AS payer_address, fp.number AS payer_number,
                       fp.complement AS payer_complement, fp.district AS payer_district,
                       fp.city AS payer_city, fp.state AS payer_state,
                       ba.name AS bank_account_name, ba.agency AS bank_agency, ba.account_number AS bank_account_number
                FROM finance_boleto_charges bc
                LEFT JOIN finance_receivables fr ON fr.company_id=bc.company_id AND fr.id=bc.receivable_id
                LEFT JOIN finance_people fp ON fp.company_id=bc.company_id AND fp.id=fr.person_id
                LEFT JOIN finance_bank_accounts ba ON ba.company_id=bc.company_id AND ba.id=bc.bank_account_id
                WHERE bc.company_id=? AND bc.id=? LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$companyId, $chargeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) throw new RuntimeException('Cobrança não encontrada.');
        if (strtoupper((string)($row['provider'] ?? '')) !== 'ITAU' || strtoupper((string)($row['integration_mode'] ?? '')) !== 'API') {
            throw new RuntimeException('A cobrança selecionada não pertence a uma integração Itaú / API.');
        }
        return $row;
    }
}

if (!function_exists('itau_bolecode_validate_bank_config')) {
    function itau_bolecode_validate_bank_config(array $rawCfg): array {
        $cfg = itau_boleto_normalize_config($rawCfg);
        $beneficiary = itau_bolecode_digits((string)($cfg['itau_beneficiary_id'] ?? ''));
        $wallet = itau_bolecode_digits((string)($cfg['carteira'] ?? ''));
        $species = itau_bolecode_digits((string)($cfg['itau_species_code'] ?? '01'));
        $next = (int)($cfg['itau_nosso_numero_next'] ?? 0);
        $errors = [];
        if (strlen($beneficiary) !== 12) $errors[] = 'Informe o ID Beneficiário Itaú com 12 dígitos (Agência 4 + Conta 7 + DAC 1).';
        if (!preg_match('/^\d{3}$/', $wallet)) $errors[] = 'Informe a carteira Itaú com 3 dígitos (ex.: 109).';
        if (!preg_match('/^\d{2}$/', $species)) $errors[] = 'Informe o código da espécie com 2 dígitos (ex.: 01).';
        if ($next < 1 || $next > 99999999) $errors[] = 'Informe o próximo Nosso Número disponível entre 1 e 99999999.';
        $diag = itau_boleto_validate_config($cfg, true);
        if (empty($diag['ready_for_token']) || empty($diag['ok'])) $errors[] = 'OAuth/mTLS Itaú ainda não está pronto. Valide Client ID, Client Secret, certificado e chave.';
        return [
            'ok' => !$errors,
            'errors' => $errors,
            'beneficiary_id' => $beneficiary,
            'wallet' => $wallet,
            'species' => $species,
            'next_nosso_numero' => $next,
            'endpoint' => itau_bolecode_endpoint($cfg),
            'config' => $cfg,
        ];
    }
}

if (!function_exists('itau_bolecode_payer_payload')) {
    function itau_bolecode_payer_payload(array $charge): array {
        $name = itau_bolecode_person_name((string)($charge['payer_name'] ?? ''), 50);
        $doc = itau_bolecode_digits((string)($charge['payer_document'] ?? ''));
        $personType = strtoupper((string)($charge['payer_person_type'] ?? ''));
        $type = ($personType === 'FISICA' || strlen($doc) === 11) ? 'F' : 'J';
        $errors = [];
        if ($name === '') $errors[] = 'Nome/Razão Social do pagador não informado.';
        if ($type === 'F' && strlen($doc) !== 11) $errors[] = 'CPF do pagador deve possuir 11 dígitos.';
        if ($type === 'J' && strlen($doc) !== 14) $errors[] = 'CNPJ do pagador deve possuir 14 dígitos.';

        $streetParts = [trim((string)($charge['payer_address'] ?? ''))];
        if (trim((string)($charge['payer_number'] ?? '')) !== '') $streetParts[] = trim((string)$charge['payer_number']);
        if (trim((string)($charge['payer_complement'] ?? '')) !== '') $streetParts[] = trim((string)$charge['payer_complement']);
        $street = itau_bolecode_address_text(implode(', ', array_filter($streetParts)), 45);
        $district = itau_bolecode_address_text((string)($charge['payer_district'] ?? ''), 15);
        $city = itau_bolecode_address_text((string)($charge['payer_city'] ?? ''), 20);
        $state = strtoupper(preg_replace('/[^A-Z]/', '', (string)($charge['payer_state'] ?? '')) ?? '');
        $state = substr($state, 0, 2);
        $zip = itau_bolecode_digits((string)($charge['payer_zip_code'] ?? ''));
        if ($street === '') $errors[] = 'Endereço do pagador não informado.';
        if ($district === '') $errors[] = 'Bairro do pagador não informado.';
        if ($city === '') $errors[] = 'Cidade do pagador não informada.';
        if (strlen($state) !== 2) $errors[] = 'UF do pagador inválida.';
        if (strlen($zip) !== 8) $errors[] = 'CEP do pagador deve possuir 8 dígitos.';
        if ($errors) throw new RuntimeException('Cadastro do pagador incompleto para o Itaú: ' . implode(' ', $errors));

        $tp = ['codigo_tipo_pessoa' => $type];
        if ($type === 'F') $tp['numero_cadastro_pessoa_fisica'] = $doc;
        else $tp['numero_cadastro_nacional_pessoa_juridica'] = $doc;

        $payload = [
            'pessoa' => [
                'nome_pessoa' => $name,
                'tipo_pessoa' => $tp,
            ],
            'endereco' => [
                'nome_logradouro' => $street,
                'nome_bairro' => $district,
                'nome_cidade' => $city,
                'sigla_UF' => $state,
                'numero_CEP' => $zip,
            ],
        ];
        return $payload;
    }
}

if (!function_exists('itau_bolecode_format_nosso_numero')) {
    function itau_bolecode_format_nosso_numero(int $number): string {
        if ($number < 1 || $number > 99999999) throw new RuntimeException('Nosso Número fora da faixa de 8 dígitos.');
        return str_pad((string)$number, 8, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('itau_bolecode_preview_nosso_numero')) {
    function itau_bolecode_preview_nosso_numero(array $charge, array $cfg): string {
        $current = itau_bolecode_digits((string)($charge['nosso_numero'] ?? ''));
        if ($current !== '') {
            if (strlen($current) > 8) throw new RuntimeException('Nosso Número já gravado possui mais de 8 dígitos e não é compatível com a carteira 109.');
            return str_pad($current, 8, '0', STR_PAD_LEFT);
        }
        return itau_bolecode_format_nosso_numero((int)($cfg['itau_nosso_numero_next'] ?? 0));
    }
}

if (!function_exists('itau_bolecode_assign_nosso_numero')) {
    function itau_bolecode_assign_nosso_numero(PDO $pdo, int $companyId, int $chargeId, int $bankAccountId): string {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT nosso_numero FROM finance_boleto_charges WHERE company_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId, $chargeId]);
            $current = itau_bolecode_digits((string)($st->fetchColumn() ?: ''));
            if ($current !== '') {
                if (strlen($current) > 8) throw new RuntimeException('Nosso Número existente não é compatível com 8 dígitos.');
                $number = str_pad($current, 8, '0', STR_PAD_LEFT);
            } else {
                $cfgSt = $pdo->prepare('SELECT itau_nosso_numero_next FROM finance_bank_billing_configs WHERE company_id=? AND bank_account_id=? FOR UPDATE');
                $cfgSt->execute([$companyId, $bankAccountId]);
                $next = (int)$cfgSt->fetchColumn();
                $number = itau_bolecode_format_nosso_numero($next);
                $pdo->prepare('UPDATE finance_boleto_charges SET nosso_numero=?,updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute([$number, $companyId, $chargeId]);
                if ($next >= 99999999) throw new RuntimeException('A sequência de Nosso Número atingiu o limite de 8 dígitos. Ajuste a configuração com o banco antes de continuar.');
                $pdo->prepare('UPDATE finance_bank_billing_configs SET itau_nosso_numero_next=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')
                    ->execute([$next + 1, $companyId, $bankAccountId]);
            }
            if ($ownTx) $pdo->commit();
            return $number;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('itau_bolecode_build_payload')) {
    function itau_bolecode_build_payload(array $charge, array $rawCfg, string $stage, string $nossoNumero): array {
        $cfgCheck = itau_bolecode_validate_bank_config($rawCfg);
        if (!$cfgCheck['ok']) throw new RuntimeException(implode(' ', $cfgCheck['errors']));
        $stage = strtolower(trim($stage));
        if (!in_array($stage, ['simulacao', 'efetivacao'], true)) throw new RuntimeException('Etapa BoleCode inválida.');
        $due = (string)($charge['due_date'] ?? '');
        if (!itau_bolecode_valid_date($due)) throw new RuntimeException('Data de vencimento inválida.');
        if ($due < date('Y-m-d')) throw new RuntimeException('O vencimento da cobrança já passou. Atualize o Contas a Receber antes de emitir.');
        $amount = (float)($charge['amount'] ?? 0);
        if ($amount <= 0) throw new RuntimeException('Valor da cobrança inválido.');
        $payer = itau_bolecode_payer_payload($charge);
        $issue = (string)($charge['issue_date'] ?? date('Y-m-d'));
        if (!itau_bolecode_valid_date($issue) || $issue > $due) $issue = date('Y-m-d');
        if ($issue > $due) throw new RuntimeException('A data de emissão não pode ser posterior ao vencimento.');

        $boleto = [
            'descricao_instrumento_cobranca' => 'boleto_pix',
            'tipo_boleto' => 'a vista',
            'codigo_carteira' => $cfgCheck['wallet'],
            'forma_envio' => 'impressao',
            'codigo_especie' => $cfgCheck['species'],
            'valor_total_titulo' => itau_bolecode_money($amount),
            'data_emissao' => $issue,
            'codigo_tipo_vencimento' => 3,
            'pagador' => $payer,
            'dados_individuais_boleto' => [[
                'numero_nosso_numero' => str_pad(itau_bolecode_digits($nossoNumero), 8, '0', STR_PAD_LEFT),
                'data_vencimento' => $due,
                'valor_titulo' => itau_bolecode_money($amount),
            ]],
            'recebimento_divergente' => ['codigo_tipo_autorizacao' => '03'],
        ];

        $interest = (float)($rawCfg['interest_month_percent'] ?? 0);
        if ($interest > 0) {
            $boleto['juros'] = ['codigo_tipo_juros' => '90', 'percentual_juros' => itau_bolecode_percent5($interest)];
        } else {
            $boleto['juros'] = ['codigo_tipo_juros' => '05'];
        }
        $fine = (float)($rawCfg['fine_percent'] ?? 0);
        if ($fine > 0) {
            $boleto['multa'] = ['codigo_tipo_multa' => '02', 'percentual_multa' => itau_bolecode_percent5($fine)];
        } else {
            $boleto['multa'] = ['codigo_tipo_multa' => '03'];
        }
        $protestDays = max(0, (int)($rawCfg['protest_days'] ?? 0));
        if ($protestDays > 99) throw new RuntimeException('O Itaú permite no máximo 99 dias para protesto nesta API. Ajuste a configuração da conta.');
        if ($protestDays > 0) $boleto['protesto'] = ['protesto' => true, 'quantidade_dias_protesto' => $protestDays];

        return [
            'etapa_processo_boleto' => $stage,
            'beneficiario' => ['id_beneficiario' => $cfgCheck['beneficiary_id']],
            'dado_boleto' => $boleto,
        ];
    }
}

if (!function_exists('itau_bolecode_headers')) {
    function itau_bolecode_headers(string $accessToken, string $correlationId): array {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . trim($accessToken),
            'x-itau-correlationID' => $correlationId,
        ];
    }
}

if (!function_exists('itau_bolecode_response_message')) {
    function itau_bolecode_response_message(array $response): string {
        $json = $response['json'] ?? null;
        if (is_array($json)) {
            $parts = [];
            if (!empty($json['mensagem'])) $parts[] = trim((string)$json['mensagem']);
            if (!empty($json['campos']) && is_array($json['campos'])) {
                foreach ($json['campos'] as $field) {
                    if (!is_array($field)) continue;
                    $fieldName = trim((string)($field['campo'] ?? ''));
                    $message = trim((string)($field['mensagem'] ?? ''));
                    $value = trim((string)($field['valor'] ?? ''));
                    $txt = ($fieldName !== '' ? $fieldName . ': ' : '') . $message;
                    if ($value !== '') $txt .= ' (valor: ' . $value . ')';
                    if (trim($txt) !== '') $parts[] = trim($txt);
                }
            }
            if ($parts) return implode(' | ', array_unique($parts));
        }
        return itau_boleto_response_message($response);
    }
}

if (!function_exists('itau_bolecode_request')) {
    function itau_bolecode_request(array $cfg, array $payload, string $correlationId): array {
        $token = itau_boleto_get_token($cfg);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) throw new RuntimeException('Não foi possível gerar o JSON da emissão Itaú.');
        return itau_boleto_http($cfg, 'POST', itau_bolecode_endpoint($cfg), itau_bolecode_headers((string)$token['access_token'], $correlationId), $body);
    }
}

if (!function_exists('itau_bolecode_extract_response')) {
    function itau_bolecode_extract_response(array $response): array {
        $json = $response['json'] ?? null;
        if (!is_array($json)) return [];
        if (isset($json['data']) && is_array($json['data']) && !isset($json['dado_boleto'])) $json = $json['data'];
        $boleto = is_array($json['dado_boleto'] ?? null) ? $json['dado_boleto'] : [];
        $items = is_array($boleto['dados_individuais_boleto'] ?? null) ? $boleto['dados_individuais_boleto'] : [];
        $item = is_array($items[0] ?? null) ? $items[0] : [];
        $qr = is_array($json['dados_qrcode'] ?? null) ? $json['dados_qrcode'] : [];
        return [
            'nosso_numero' => trim((string)($item['numero_nosso_numero'] ?? '')),
            'external_id' => trim((string)($item['id_boleto_individual'] ?? '')),
            'dac_titulo' => trim((string)($item['dac_titulo'] ?? '')),
            'barcode' => trim((string)($item['codigo_barras'] ?? '')),
            'linha_digitavel' => trim((string)($item['numero_linha_digitavel'] ?? '')),
            'pix_key' => trim((string)($qr['chave'] ?? '')),
            'pix_txid' => trim((string)($qr['txid'] ?? '')),
            'pix_emv' => trim((string)($qr['emv'] ?? '')),
            'pix_qr_base64' => trim((string)($qr['base64'] ?? '')),
            'pix_location_id' => isset($qr['id_location']) ? (string)$qr['id_location'] : '',
            'pix_location' => trim((string)($qr['location'] ?? '')),
        ];
    }
}

if (!function_exists('itau_bolecode_simulate_charge')) {
    function itau_bolecode_simulate_charge(PDO $pdo, int $companyId, int $chargeId, bool $preserveOperationalCorrelation = false): array {
        $charge = itau_bolecode_charge_context($pdo, $companyId, $chargeId);
        $cfg = billing_bank_config($pdo, $companyId, (int)$charge['bank_account_id']);
        $check = itau_bolecode_validate_bank_config($cfg);
        if (!$check['ok']) throw new RuntimeException(implode(' ', $check['errors']));
        $nosso = itau_bolecode_preview_nosso_numero($charge, $cfg);
        $payload = itau_bolecode_build_payload($charge, $cfg, 'simulacao', $nosso);
        $corr = itau_boleto_uuid();
        $requestJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        try {
            $resp = itau_bolecode_request($cfg, $payload, $corr);
        } catch (Throwable $e) {
            if ($preserveOperationalCorrelation) {
                $pdo->prepare('UPDATE finance_boleto_charges SET simulation_request_payload=?,simulation_response_payload=?,simulation_http_status=NULL,simulated_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute([$requestJson, json_encode(['exception'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), $companyId, $chargeId]);
            } else {
                // Fluxo legado preservado: mantém exatamente o comportamento anterior fora do F11.
                $pdo->prepare('UPDATE finance_boleto_charges SET simulation_request_payload=?,simulation_response_payload=?,simulation_http_status=NULL,simulated_at=NOW(),api_correlation_id=?,updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute([$requestJson, json_encode(['exception'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), $corr, $companyId, $chargeId]);
            }
            throw $e;
        }
        if ($preserveOperationalCorrelation) {
            $pdo->prepare('UPDATE finance_boleto_charges SET simulation_request_payload=?,simulation_response_payload=?,simulation_http_status=?,simulated_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?')
                ->execute([$requestJson, (string)$resp['body'], (int)$resp['status'], $companyId, $chargeId]);
        } else {
            // Fluxo legado preservado: mantém exatamente o comportamento anterior fora do F11.
            $pdo->prepare('UPDATE finance_boleto_charges SET simulation_request_payload=?,simulation_response_payload=?,simulation_http_status=?,simulated_at=NOW(),api_correlation_id=?,updated_at=NOW() WHERE company_id=? AND id=?')
                ->execute([$requestJson, (string)$resp['body'], (int)$resp['status'], $corr, $companyId, $chargeId]);
        }
        if ((int)$resp['status'] < 200 || (int)$resp['status'] >= 300) {
            throw new RuntimeException('Simulação Itaú recusada. HTTP ' . (int)$resp['status'] . ': ' . itau_bolecode_response_message($resp));
        }
        return ['ok'=>true,'status'=>(int)$resp['status'],'correlation_id'=>$corr,'response'=>$resp,'preview_nosso_numero'=>$nosso];
    }
}

if (!function_exists('itau_bolecode_registration_audit_missing_columns')) {
    function itau_bolecode_registration_audit_missing_columns(PDO $pdo): array {
        $required = ['api_attempt_count','api_last_action','api_last_attempt_at','api_last_attempt_by_user_id','api_request_hash'];
        $missing = [];
        foreach ($required as $column) {
            if (!billing_has_column($pdo, 'finance_boleto_charges', $column)) $missing[] = $column;
        }
        return $missing;
    }
}

if (!function_exists('itau_bolecode_canonicalize_value')) {
    function itau_bolecode_canonicalize_value($value) {
        if (!is_array($value)) return $value;
        $keys = array_keys($value);
        $assoc = $keys !== range(0, count($value) - 1);
        if ($assoc) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = itau_bolecode_canonicalize_value($item);
        return $value;
    }
}

if (!function_exists('itau_bolecode_payload_fingerprint')) {
    function itau_bolecode_payload_fingerprint(array $payload): string {
        $normalized = itau_bolecode_canonicalize_value($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Não foi possível gerar o fingerprint do payload Itaú.');
        return hash('sha256', $json);
    }
}

if (!function_exists('itau_bolecode_assert_simulation_matches_effective_payload')) {
    function itau_bolecode_assert_simulation_matches_effective_payload(array $charge, array $effectivePayload): void {
        $http = (int)($charge['simulation_http_status'] ?? 0);
        $simulatedAt = trim((string)($charge['simulated_at'] ?? ''));
        $request = trim((string)($charge['simulation_request_payload'] ?? ''));
        if ($http < 200 || $http >= 300 || $simulatedAt === '' || $request === '') {
            throw new RuntimeException('V11.11: execute uma Simulação Itaú com sucesso antes de registrar/reprocessar esta cobrança pelo F11.');
        }
        $simTs = strtotime($simulatedAt);
        if ($simTs === false || $simTs < (time() - 1800)) {
            throw new RuntimeException('V11.11: a última Simulação Itaú tem mais de 30 minutos. Simule novamente antes da operação efetiva.');
        }
        $simPayload = json_decode($request, true);
        if (!is_array($simPayload)) {
            throw new RuntimeException('V11.11: o payload da última simulação não pôde ser validado. Simule novamente.');
        }
        // A única diferença esperada entre a simulação aprovada e a efetivação é a etapa.
        $simPayload['etapa_processo_boleto'] = 'efetivacao';
        if (!hash_equals(itau_bolecode_payload_fingerprint($simPayload), itau_bolecode_payload_fingerprint($effectivePayload))) {
            throw new RuntimeException('V11.11: os dados atuais da cobrança não correspondem à última simulação aprovada. Simule novamente antes de registrar/reprocessar.');
        }
    }
}

if (!function_exists('itau_bolecode_has_confirmed_bank_identity')) {
    function itau_bolecode_has_confirmed_bank_identity(array $charge): bool {
        return trim((string)($charge['external_id'] ?? '')) !== ''
            || !empty($charge['registered_at'])
            || trim((string)($charge['linha_digitavel'] ?? '')) !== ''
            || trim((string)($charge['barcode'] ?? '')) !== ''
            || trim((string)($charge['pix_txid'] ?? '')) !== '';
    }
}

if (!function_exists('itau_bolecode_register_charge_multiempresa_protected')) {
    /**
     * V11.11 - Registro/reprocessamento efetivo somente para o contexto F11 homologado.
     *
     * O fluxo legado itau_bolecode_register_charge() permanece intocado. Este fluxo:
     * - separa REGISTRAR (AGUARDANDO_API) de REPROCESSAR (ERRO);
     * - exige simulação 2xx recente e exatamente compatível com o payload atual;
     * - reserva Nosso Número e muda para ENVIANDO_API sob lock antes da chamada externa;
     * - impede clique duplo/reenvio concorrente pelo próprio estado ENVIANDO_API;
     * - trata respostas incertas como VERIFICAR_API, bloqueando novo envio automático.
     */
    function itau_bolecode_register_charge_multiempresa_protected(PDO $pdo, int $companyId, int $chargeId, string $operation, int $userId): array {
        $operation = strtolower(trim($operation));
        if (!in_array($operation, ['register','reprocess'], true)) throw new InvalidArgumentException('Operação Itaú protegida inválida.');
        if ($companyId <= 0 || $chargeId <= 0 || $userId <= 0) throw new InvalidArgumentException('Empresa, cobrança ou usuário inválido para operação Itaú protegida.');

        $missing = itau_bolecode_registration_audit_missing_columns($pdo);
        if ($missing) {
            throw new RuntimeException('V11.11: estrutura de auditoria do registro Itaú incompleta. Aplique a migration V11.11 no Atualizador do Banco. Ausente: ' . implode(', ', $missing) . '.');
        }

        $expectedStatus = $operation === 'register' ? 'AGUARDANDO_API' : 'ERRO';
        $actionLabel = $operation === 'register' ? 'REGISTRAR' : 'REPROCESSAR';
        if ($pdo->inTransaction()) {
            throw new RuntimeException('V11.11: a operação bancária protegida não pode iniciar dentro de outra transação. Finalize a operação atual e tente novamente.');
        }
        $started = true;
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId, $chargeId]);
            $charge = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$charge) throw new RuntimeException('Cobrança não encontrada na empresa selecionada.');
            if (strtoupper(trim((string)($charge['provider'] ?? ''))) !== 'ITAU' || strtoupper(trim((string)($charge['integration_mode'] ?? ''))) !== 'API') {
                throw new RuntimeException('A cobrança selecionada não pertence à integração Itaú / API.');
            }
            $status = strtoupper(trim((string)($charge['status'] ?? '')));
            if ($status !== $expectedStatus) {
                throw new RuntimeException('V11.11: a ação ' . $actionLabel . ' exige status ' . $expectedStatus . '. Status atual: ' . ($status ?: 'SEM STATUS') . '. Atualize a fila antes de tentar novamente.');
            }
            if (itau_bolecode_has_confirmed_bank_identity($charge)) {
                throw new RuntimeException('V11.11: a cobrança já possui identidade bancária confirmada. O reenvio automático foi bloqueado para evitar duplicidade.');
            }

            $bankAccountId = (int)($charge['bank_account_id'] ?? 0);
            if ($bankAccountId <= 0) throw new RuntimeException('Conta bancária da cobrança não informada.');
            $cfgLock = $pdo->prepare('SELECT id FROM finance_bank_billing_configs WHERE company_id=? AND bank_account_id=? FOR UPDATE');
            $cfgLock->execute([$companyId, $bankAccountId]);
            if (!$cfgLock->fetchColumn()) throw new RuntimeException('Configuração de cobrança Itaú não encontrada para a conta da empresa selecionada.');

            $cfg = billing_bank_config($pdo, $companyId, $bankAccountId);
            $check = itau_bolecode_validate_bank_config($cfg);
            if (!$check['ok']) throw new RuntimeException(implode(' ', $check['errors']));

            // Dentro da mesma transação: se ainda não houver Nosso Número, reserva exatamente um.
            // Em reprocessamento reutiliza o número já reservado na tentativa anterior.
            $nosso = itau_bolecode_assign_nosso_numero($pdo, $companyId, $chargeId, $bankAccountId);
            $charge['nosso_numero'] = $nosso;
            $payload = itau_bolecode_build_payload($charge, $cfg, 'efetivacao', $nosso);
            itau_bolecode_assert_simulation_matches_effective_payload($charge, $payload);

            $corr = itau_boleto_uuid();
            $requestJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($requestJson === false) throw new RuntimeException('Não foi possível gerar o JSON da emissão Itaú.');
            $requestHash = itau_bolecode_payload_fingerprint($payload);

            $upd = $pdo->prepare("UPDATE finance_boleto_charges
                SET status='ENVIANDO_API', request_payload=?, api_correlation_id=?, error_message=NULL,
                    api_attempt_count=COALESCE(api_attempt_count,0)+1, api_last_action=?, api_last_attempt_at=NOW(),
                    api_last_attempt_by_user_id=?, api_request_hash=?, updated_at=NOW()
                WHERE company_id=? AND id=? AND status=?");
            $upd->execute([$requestJson, $corr, $actionLabel, $userId, $requestHash, $companyId, $chargeId, $expectedStatus]);
            if ($upd->rowCount() !== 1) throw new RuntimeException('V11.11: a cobrança mudou de estado durante a operação. Atualize a fila e confira antes de reenviar.');
            if ($started) $pdo->commit();
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // A chamada bancária ocorre somente depois do claim local ser persistido.
        // Se o processo cair daqui em diante, a cobrança fica ENVIANDO_API/VERIFICAR_API e não pode ser reenviada cegamente.
        try {
            $resp = itau_bolecode_request($cfg, $payload, $corr);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_API',response_payload=?,api_http_status=NULL,error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
                ->execute([json_encode(['exception'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), itau_bolecode_cut('Falha de comunicação após iniciar a emissão. Não reenvie antes de conferir no Itaú. ' . $e->getMessage(), 1000), $companyId, $chargeId]);
            throw new RuntimeException('Falha de comunicação com o Itaú. A cobrança foi marcada como VERIFICAR_API e o reenvio automático foi bloqueado para evitar duplicidade. ' . $e->getMessage());
        }

        $http = (int)$resp['status'];
        if ($http === 202) {
            $pdo->prepare("UPDATE finance_boleto_charges SET status='PROCESSANDO_API',response_payload=?,api_http_status=?,error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
                ->execute([(string)$resp['body'], $http, itau_bolecode_cut(itau_bolecode_response_message($resp),1000), $companyId, $chargeId]);
            return ['ok'=>true,'processing'=>true,'status'=>$http,'correlation_id'=>$corr,'response'=>$resp,'nosso_numero'=>$nosso,'action'=>$actionLabel];
        }

        if ($http < 200 || $http >= 300) {
            $message = itau_bolecode_response_message($resp);
            $uncertain = $http < 400 || $http >= 500 || in_array($http, [408,409,425,429], true);
            $newStatus = $uncertain ? 'VERIFICAR_API' : 'ERRO';
            $prefix = $uncertain
                ? 'Resposta bancária potencialmente incerta; não reenvie antes de conferir no Itaú. '
                : 'Emissão recusada pelo Itaú; após corrigir os dados, faça nova simulação antes de reprocessar. ';
            $pdo->prepare("UPDATE finance_boleto_charges SET status=?,response_payload=?,api_http_status=?,error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
                ->execute([$newStatus, (string)$resp['body'], $http, itau_bolecode_cut($prefix . $message,1000), $companyId, $chargeId]);
            throw new RuntimeException('Itaú respondeu HTTP ' . $http . '. Cobrança marcada como ' . $newStatus . '. ' . $message);
        }

        $data = itau_bolecode_extract_response($resp);
        if ($data['external_id'] === '' || $data['barcode'] === '' || $data['linha_digitavel'] === '') {
            $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_API',response_payload=?,api_http_status=?,error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
                ->execute([(string)$resp['body'], $http, 'Itaú respondeu sucesso, mas faltaram identificadores esperados. Não reenvie antes de conferir no Itaú.', $companyId, $chargeId]);
            throw new RuntimeException('Itaú respondeu sucesso, porém o retorno não trouxe UUID/código de barras/linha digitável completos. Cobrança marcada como VERIFICAR_API.');
        }

        $upd = $pdo->prepare("UPDATE finance_boleto_charges
            SET status='REGISTRADO',nosso_numero=?,external_id=?,linha_digitavel=?,barcode=?,response_payload=?,api_http_status=?,
                dac_titulo=?,pix_key=?,pix_txid=?,pix_emv=?,pix_qr_base64=?,pix_location_id=?,pix_location=?,registered_at=NOW(),error_message=NULL,updated_at=NOW()
            WHERE company_id=? AND id=? AND status='ENVIANDO_API'");
        $upd->execute([
            $data['nosso_numero'] !== '' ? $data['nosso_numero'] : $nosso,
            $data['external_id'], $data['linha_digitavel'], $data['barcode'], (string)$resp['body'], $http,
            $data['dac_titulo'] ?: null, $data['pix_key'] ?: null, $data['pix_txid'] ?: null, $data['pix_emv'] ?: null,
            $data['pix_qr_base64'] ?: null, $data['pix_location_id'] !== '' ? (int)$data['pix_location_id'] : null,
            $data['pix_location'] ?: null, $companyId, $chargeId
        ]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('V11.11: o Itaú respondeu sucesso, mas o estado local mudou antes da confirmação. Não reenvie; confira a cobrança no Itaú e no Nexa.');
        }
        return ['ok'=>true,'processing'=>false,'status'=>$http,'correlation_id'=>$corr,'response'=>$resp,'data'=>$data,'nosso_numero'=>$nosso,'action'=>$actionLabel];
    }
}

if (!function_exists('itau_bolecode_register_charge')) {
    function itau_bolecode_register_charge(PDO $pdo, int $companyId, int $chargeId): array {
        $charge = itau_bolecode_charge_context($pdo, $companyId, $chargeId);
        $status = strtoupper((string)($charge['status'] ?? ''));
        if (!in_array($status, ['AGUARDANDO_API','ERRO'], true)) {
            throw new RuntimeException('Esta cobrança não pode ser enviada no status atual: ' . ($status ?: 'SEM STATUS') . '.');
        }
        $cfg = billing_bank_config($pdo, $companyId, (int)$charge['bank_account_id']);
        $check = itau_bolecode_validate_bank_config($cfg);
        if (!$check['ok']) throw new RuntimeException(implode(' ', $check['errors']));
        $nosso = itau_bolecode_assign_nosso_numero($pdo, $companyId, $chargeId, (int)$charge['bank_account_id']);
        $charge['nosso_numero'] = $nosso;
        $payload = itau_bolecode_build_payload($charge, $cfg, 'efetivacao', $nosso);
        $corr = itau_boleto_uuid();
        $requestJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $pdo->prepare("UPDATE finance_boleto_charges SET status='ENVIANDO_API',request_payload=?,api_correlation_id=?,error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$requestJson, $corr, $companyId, $chargeId]);
        try {
            $resp = itau_bolecode_request($cfg, $payload, $corr);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_API',response_payload=?,api_http_status=NULL,error_message=?,updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute([json_encode(['exception'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), 'Falha de comunicação após iniciar a emissão. Não reenvie antes de consultar/conferir no Itaú. ' . $e->getMessage(), $companyId, $chargeId]);
            throw new RuntimeException('Falha de comunicação com o Itaú. A cobrança foi marcada como VERIFICAR_API para evitar emissão duplicada. ' . $e->getMessage());
        }
        $http = (int)$resp['status'];
        if ($http === 202) {
            $pdo->prepare("UPDATE finance_boleto_charges SET status='PROCESSANDO_API',response_payload=?,api_http_status=?,error_message=?,updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute([(string)$resp['body'], $http, itau_bolecode_response_message($resp), $companyId, $chargeId]);
            return ['ok'=>true,'processing'=>true,'status'=>$http,'correlation_id'=>$corr,'response'=>$resp];
        }
        if ($http < 200 || $http >= 300) {
            $message = itau_bolecode_response_message($resp);
            $pdo->prepare("UPDATE finance_boleto_charges SET status='ERRO',response_payload=?,api_http_status=?,error_message=?,updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute([(string)$resp['body'], $http, itau_bolecode_cut($message,1000), $companyId, $chargeId]);
            throw new RuntimeException('Itaú recusou a emissão. HTTP ' . $http . ': ' . $message);
        }
        $data = itau_bolecode_extract_response($resp);
        if ($data['external_id'] === '' || $data['barcode'] === '' || $data['linha_digitavel'] === '') {
            $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_API',response_payload=?,api_http_status=?,error_message=?,updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute([(string)$resp['body'], $http, 'Itaú respondeu sucesso, mas o retorno não contém todos os identificadores esperados. Verifique antes de reenviar.', $companyId, $chargeId]);
            throw new RuntimeException('Itaú respondeu sucesso, porém o retorno não trouxe UUID/código de barras/linha digitável completos. Cobrança marcada como VERIFICAR_API.');
        }
        $pdo->prepare("UPDATE finance_boleto_charges SET status='REGISTRADO',nosso_numero=?,external_id=?,linha_digitavel=?,barcode=?,response_payload=?,api_http_status=?,dac_titulo=?,pix_key=?,pix_txid=?,pix_emv=?,pix_qr_base64=?,pix_location_id=?,pix_location=?,registered_at=NOW(),error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([
                $data['nosso_numero'] !== '' ? $data['nosso_numero'] : $nosso,
                $data['external_id'], $data['linha_digitavel'], $data['barcode'], (string)$resp['body'], $http,
                $data['dac_titulo'] ?: null, $data['pix_key'] ?: null, $data['pix_txid'] ?: null, $data['pix_emv'] ?: null,
                $data['pix_qr_base64'] ?: null, $data['pix_location_id'] !== '' ? (int)$data['pix_location_id'] : null,
                $data['pix_location'] ?: null, $companyId, $chargeId
            ]);
        return ['ok'=>true,'processing'=>false,'status'=>$http,'correlation_id'=>$corr,'response'=>$resp,'data'=>$data];
    }
}
