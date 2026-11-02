<?php
declare(strict_types=1);
/**
 * Sicoob V1.5: registro bancário com confirmação explícita e sem reenvio cego.
 * DESLIGADO POR PADRÃO: NEXA_SICOOB_REGISTRO_V15_COMPANY_IDS=5 (ambiente do PHP-FPM).
 * Emissão Sicoob no contexto F11 permanece bloqueada nesta etapa.
 */
require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/sicoob_boleto.php';

/** Permissões locais do piloto, carregadas de arquivo PHP não acessível como JSON público.
 * Por segurança, registro automático exige autorização SEPARADA do registro manual.
 */
function sicoob_v17_pilot_config(): array {
    $file = __DIR__.'/sicoob_registro_piloto.php';
    $cfg = is_file($file) ? require $file : [];
    return is_array($cfg) ? $cfg : [];
}
function sicoob_v15_company_enabled(int $companyId): bool {
    if ($companyId <= 0) return false;
    $cfg = sicoob_v17_pilot_config();
    $allowed = array_map('intval',(array)($cfg['empresas_registro_manual'] ?? []));
    if (in_array($companyId,$allowed,true)) return true;
    // Preserva a flag antiga, se ela já havia sido configurada no PHP-FPM.
    $raw = trim((string)(getenv('NEXA_SICOOB_REGISTRO_V15_COMPANY_IDS') ?: ''));
    foreach (explode(',', $raw) as $item) {
        if (ctype_digit(trim($item)) && (int)trim($item) === $companyId) return true;
    }
    return false;
}
function sicoob_v17_auto_enabled(int $companyId): bool {
    if (!sicoob_v15_company_enabled($companyId)) return false;
    $cfg = sicoob_v17_pilot_config();
    // Apenas allowlist explícita no arquivo; variáveis herdadas não liberam POST automático.
    return in_array($companyId,array_map('intval',(array)($cfg['empresas_registro_automatico'] ?? [])),true);
}
function sicoob_v15_digits(mixed $value): string {
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}
function sicoob_v15_text(mixed $value, int $length): string {
    $value = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? (string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}
/**
 * V8: formato bancário do endereço do pagador. O cadastro original não é alterado.
 * A API recebe apenas 'endereco' e 'bairro': mantém logradouro e número, adiciona
 * complemento somente se couber. Nunca trunca o número ou o nome da rua.
 */
function sicoob_v18_char_length(string $text): int {
    if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
    $length = preg_match_all('/./us', $text);
    return $length === false ? strlen($text) : $length;
}
function sicoob_v18_normalize_text(mixed $text): string {
    $raw = trim((string)$text);
    return trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
}
function sicoob_v18_compact_prefix(string $text, bool $bairro = false): string {
    $patterns = $bairro ? [
        '/^Jardim\b/ui' => 'Jd.',
        '/^Residencial\b/ui' => 'Res.',
        '/^Conjunto\b/ui' => 'Cj.',
        '/^Loteamento\b/ui' => 'Lot.',
        '/^Parque\b/ui' => 'Pq.',
    ] : [
        '/^Avenida\b/ui' => 'Av.',
        '/^Travessa\b/ui' => 'Tv.',
        '/^Rodovia\b/ui' => 'Rod.',
        '/^Estrada\b/ui' => 'Est.',
        '/^Alameda\b/ui' => 'Al.',
        '/^Praça\b/ui' => 'Pça.',
        '/^Rua\b/ui' => 'R.',
    ];
    foreach ($patterns as $pattern => $abbr) {
        $candidate = preg_replace($pattern, $abbr, $text, 1, $replaced);
        if ($replaced && $candidate !== null) return $candidate;
    }
    return $text;
}
function sicoob_v18_prepare_payer_address(array $charge): array {
    $street = sicoob_v18_normalize_text($charge['payer_address'] ?? '');
    $number = sicoob_v18_normalize_text($charge['payer_number'] ?? '');
    $complement = sicoob_v18_normalize_text($charge['payer_complement'] ?? '');
    $district = sicoob_v18_normalize_text($charge['payer_district'] ?? '');

    // Alguns cadastros já incluem o número no logradouro: não duplicá-lo.
    $numberAlreadyPresent = $number !== '' && preg_match(
        '/(?:^|[\s,])(?:n(?:[º°.]|o\.?)*\s*)?' . preg_quote($number, '/') . '\s*$/ui',
        $street
    ) === 1;
    $base = $street . (($number !== '' && !$numberAlreadyPresent && $street !== '') ? ', ' . $number : '');
    if (sicoob_v18_char_length($base) > 40) {
        $shortStreet = sicoob_v18_compact_prefix($street);
        $base = $shortStreet . (($number !== '' && !$numberAlreadyPresent && $shortStreet !== '') ? ', ' . $number : '');
    }
    // Complemento é opcional no payload atual. Não deixar que ele bloqueie
    // a cobrança se o endereço com o número já estiver completo.
    $withComplement = $base . (($base !== '' && $complement !== '') ? ', ' . $complement : '');
    $address = ($complement !== '' && sicoob_v18_char_length($withComplement) <= 40) ? $withComplement : $base;

    if (sicoob_v18_char_length($district) > 30) {
        $shortDistrict = sicoob_v18_compact_prefix($district, true);
        if (sicoob_v18_char_length($shortDistrict) <= 30) $district = $shortDistrict;
    }
    return ['endereco' => $address, 'bairro' => $district];
}
function sicoob_v15_build_payload(array $charge, array $cfg): array {
    $c = sicoob_boleto_config($cfg);
    $doc = sicoob_v15_digits($charge['payer_document'] ?? '');
    $nome = sicoob_v15_text($charge['payer_name'] ?? '', 50);
    $payerAddress = sicoob_v18_prepare_payer_address($charge);
    $endereco = $payerAddress['endereco'];
    $bairro = $payerAddress['bairro'];
    $cidade = sicoob_v15_text($charge['payer_city'] ?? '', 40);
    $uf = strtoupper(trim((string)($charge['payer_state'] ?? '')));
    $cep = sicoob_v15_digits($charge['payer_zip_code'] ?? '');
    $conta = sicoob_v15_digits($cfg['account_number'] ?? $cfg['bank_account_number'] ?? '');
    $seu = sicoob_v15_text($charge['seu_numero'] ?? '', 18);
    $valor = round((float)($charge['amount'] ?? 0), 2);
    $venc = trim((string)($charge['due_date'] ?? ''));
    $issue = trim((string)($charge['issue_date'] ?? ''));
    $parcela = (int)explode('/', (string)($charge['installment'] ?? '1'))[0];
    if ($parcela < 1) $parcela = 1;
    $problems = [];
    if (strlen($doc) !== 11 && strlen($doc) !== 14) $problems[]='CPF/CNPJ do pagador inválido';
    if ($nome === '') $problems[]='nome do pagador ausente';
    $addressLength = sicoob_v18_char_length($endereco);
    $districtLength = sicoob_v18_char_length($bairro);
    if ($addressLength > 40) $problems[]='logradouro + número com '.$addressLength.' caracteres (máx. 40), mesmo sem complemento; ajuste o cadastro de Pessoas';
    if ($districtLength > 30) $problems[]='bairro com '.$districtLength.' caracteres (máx. 30); ajuste o cadastro de Pessoas';
    if ($endereco === '' || $bairro === '' || $cidade === '' || !preg_match('/^[A-Z]{2}$/D', $uf) || strlen($cep) !== 8) $problems[]='endereço, bairro, cidade, UF ou CEP do pagador incompleto';
    if ($conta === '' || $conta === '0') $problems[]='número da conta bancária ausente';
    if (strlen($seu) < 1 || strlen((string)($charge['seu_numero'] ?? '')) > 18) $problems[]='Seu Número inválido (máximo 18 caracteres)';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $venc) || !checkdate((int)substr($venc,5,2),(int)substr($venc,8,2),(int)substr($venc,0,4))) $problems[]='vencimento inválido';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $issue)) $issue = date('Y-m-d');
    if ($valor <= 0 || $valor > 99999999) $problems[]='valor do boleto inválido';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$venc) && $venc<date('Y-m-d')) $problems[]='vencimento anterior à data atual';
    if ($parcela > 99) $problems[]='parcela superior a 99';
    if ($c['numero_cliente'] === '' || $c['codigo_modalidade'] === '') $problems[]='convênio/modalidade Sicoob não configurados';
    if ($problems) throw new RuntimeException('Boleto Sicoob não pode ser enviado: '.implode('; ', $problems).'.');
    $pagador = [
        'numeroCpfCnpj'=>$doc,'nome'=>$nome,'endereco'=>$endereco,
        'bairro'=>$bairro,'cidade'=>$cidade,'cep'=>$cep,'uf'=>$uf,
    ];
    $email = trim((string)($charge['payer_email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $pagador['email'] = sicoob_v15_text($email, 100);
    // NÃO enviar nossoNumero: Sicoob atribui, evitando conflito com registros históricos no banco.
    // Não enviar opcionais null, vazios ou numeroContratoCobranca sem orientação expressa.
    return [
        'numeroCliente'=>(int)$c['numero_cliente'],
        'codigoModalidade'=>(int)$c['codigo_modalidade'],
        'numeroContaCorrente'=>(int)$conta,
        'codigoEspecieDocumento'=>'DM', // Requer confirmação do tipo de título na homologação.
        'dataEmissao'=>$issue,
        'seuNumero'=>$seu,
        'identificacaoBoletoEmpresa'=>'NEXA-'.(int)($charge['id'] ?? 0),
        'identificacaoEmissaoBoleto'=>1,
        'identificacaoDistribuicaoBoleto'=>2,
        'valor'=>$valor,
        'dataVencimento'=>$venc,
        'tipoDesconto'=>0,
        'tipoMulta'=>0,
        'tipoJurosMora'=>3,
        'numeroParcela'=>$parcela,
        'aceite'=>false,
        'codigoNegativacao'=>3,
        'codigoProtesto'=>3,
        'pagador'=>$pagador,
        'gerarPdf'=>false,
    ];
}
/**
 * Uma única tentativa por cobrança, concorrência serializada em MySQL.
 * Após qualquer resultado não conclusivo, NUNCA repetir POST automaticamente.
 */
function sicoob_v15_register(PDO $pdo,int $companyId,int $chargeId): array {
    if (!sicoob_v15_company_enabled($companyId)) throw new RuntimeException('Registro Sicoob desabilitado para esta empresa.');
    if ($pdo->inTransaction()) throw new RuntimeException('Finalize a transação financeira antes de registrar no Sicoob.');
    if (function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo)) {
        throw new RuntimeException('Registro bancário Sicoob pelo contexto F11 não habilitado nesta versão.');
    }
    if ($companyId <= 0 || $chargeId <= 0) throw new RuntimeException('Empresa ou cobrança inválida.');
    $st=$pdo->prepare("SELECT bc.*, fr.installment, fr.status AS receivable_status, fr.open_amount AS receivable_open_amount, ba.active AS bank_account_active, fp.name AS payer_name, fp.document AS payer_document,
        fp.email AS payer_email, fp.zip_code AS payer_zip_code, fp.address AS payer_address,
        fp.number AS payer_number, fp.complement AS payer_complement, fp.district AS payer_district,
        fp.city AS payer_city, fp.state AS payer_state,
        ba.account_number AS bank_account_number
        FROM finance_boleto_charges bc
        LEFT JOIN finance_receivables fr ON fr.company_id=bc.company_id AND fr.id=bc.receivable_id
        LEFT JOIN finance_people fp ON fp.company_id=bc.company_id AND fp.id=fr.person_id
        LEFT JOIN finance_bank_accounts ba ON ba.company_id=bc.company_id AND ba.id=bc.bank_account_id
        WHERE bc.company_id=? AND bc.id=? LIMIT 1");
    $st->execute([$companyId,$chargeId]);
    $charge=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$charge || strtoupper((string)($charge['provider']??''))!=='SICOOB' || strtoupper((string)($charge['integration_mode']??''))!=='API') {
        throw new RuntimeException('Cobrança Sicoob/API não encontrada nesta empresa.');
    }
    if (strtoupper((string)$charge['status'])!=='AGUARDANDO_API') throw new RuntimeException('Esta cobrança já teve tentativa ou não está aguardando registro; não reenviar.');
    if (!empty($charge['nosso_numero']) || !empty($charge['registered_at']) || !empty($charge['api_http_status'])) throw new RuntimeException('Cobrança com identidade ou tentativa bancária anterior.');
    if ((int)($charge['receivable_id'] ?? 0)<=0) throw new RuntimeException('Boleto sem Conta a Receber vinculada.');
    if (empty($charge['bank_account_active'])) throw new RuntimeException('Conta bancária Sicoob inativa.');
    if (in_array(strtoupper(trim((string)($charge['receivable_status']??''))), ['BAIXADO','CANCELADO'], true)
        || (float)($charge['receivable_open_amount']??0) < 0.01) {
        throw new RuntimeException('Título financeiro já baixado/cancelado ou sem saldo. Registro bloqueado.');
    }
    $cfg=billing_bank_config($pdo,$companyId,(int)$charge['bank_account_id']);
    if (!$cfg || !sicoob_boleto_diagnose($cfg,$pdo)['ok']) throw new RuntimeException('Configuração bancária Sicoob incompleta.');
    $payload=sicoob_v15_build_payload($charge,$cfg);
    // OAuth antes de adquirir lock; rejeição do token não cria tentativa bancária.
    $token=sicoob_boleto_get_token_for_scope($cfg,$pdo,'boletos_inclusao');
    $c=sicoob_boleto_diagnose($cfg,$pdo)['config'];
    $payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT * FROM finance_boleto_charges WHERE company_id=? AND id=? FOR UPDATE');
        $lock->execute([$companyId,$chargeId]);$current=$lock->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$current || strtoupper((string)($current['status']??''))!=='AGUARDANDO_API'
            || !empty($current['nosso_numero']) || !empty($current['registered_at']) || !empty($current['api_http_status'])) {
            throw new RuntimeException('Outra operação já iniciou o registro desta cobrança. Nenhum novo POST foi enviado.');
        }
        $titleCheck=$pdo->prepare('SELECT status,open_amount FROM finance_receivables WHERE company_id=? AND id=? FOR UPDATE');
        $titleCheck->execute([$companyId,(int)$current['receivable_id']]);
        $title=$titleCheck->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$title || in_array(strtoupper((string)($title['status']??'')), ['BAIXADO','CANCELADO'],true)
            || (float)($title['open_amount']??0) < 0.01) {
            throw new RuntimeException('Título alterado ou já liquidado antes do envio Sicoob. Nenhum POST enviado.');
        }
        $up=$pdo->prepare("UPDATE finance_boleto_charges SET status='ENVIANDO_API',
           request_payload=?,error_message='Envio Sicoob iniciado. Não repetir sem reconciliação.',updated_at=NOW()
           WHERE company_id=? AND id=? AND status='AGUARDANDO_API'");
        $up->execute([$payloadJson,$companyId,$chargeId]);
        if ($up->rowCount()!==1) throw new RuntimeException('Falha ao reservar a cobrança para envio exclusivo.');
        $pdo->commit();
    } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    // Após o commit: qualquer erro/queda mantém o estado ENVIANDO_API ou VERIFICAR_API.
    try {
        $res=sicoob_boleto_http($c,$c['api_url'].'/boletos','POST',[
            'Accept: application/json','Content-Type: application/json',
            'Authorization: Bearer '.$token['access_token'],'client_id: '.$c['client_id']
        ],$payloadJson);
    } catch(Throwable $e) {
        $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_API',error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
            ->execute(['Resposta Sicoob incerta (falha de comunicação). Não reenvie antes de conferir no banco.',$companyId,$chargeId]);
        throw new RuntimeException('Comunicação inconclusiva com o Sicoob. Cobrança marcada para conferência; envio repetido bloqueado.');
    }
    $http=(int)$res['status'];$j=is_array($res['json'])?$res['json']:[];
    $data=is_array($j['resultado']??null)?$j['resultado']:[];
    $nosso=sicoob_v15_digits($data['nossoNumero']??'');
    $codigo=sicoob_v15_digits($data['codigoBarras']??'');
    $linha=sicoob_v15_digits($data['linhaDigitavel']??'');
    $safeResponse=json_encode(['http'=>$http,'numeroCliente'=>$data['numeroCliente']??null,'nossoNumero'=>$nosso,
         'seuNumero'=>$data['seuNumero']??null,'codigoBarras'=>$codigo,'linhaDigitavel'=>$linha,
         'mensagem'=>$http>=400?sicoob_boleto_response_error_summary($res):null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($http>=200 && $http<300 && strlen($nosso)>=1 && strlen($codigo)===44 && strlen($linha)===47) {
        $pdo->prepare("UPDATE finance_boleto_charges SET status='REGISTRADO',nosso_numero=?,
          external_id=?,linha_digitavel=?,barcode=?,response_payload=?,api_http_status=?,
          registered_at=NOW(),error_message=NULL,updated_at=NOW()
          WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
          ->execute([$nosso,$nosso,$linha,$codigo,$safeResponse,$http,$companyId,$chargeId]);
        return ['ok'=>true,'status'=>'REGISTRADO','nosso_numero'=>$nosso,'http_status'=>$http];
    }
    // Inclusive HTTP 400: sem identidade forte não podemos garantir que uma cobrança
    // não tenha sido registrada. Exigir análise manual antes de liberar qualquer novo POST.
    $pdo->prepare("UPDATE finance_boleto_charges SET status='VERIFICAR_API',response_payload=?,api_http_status=?,
      error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='ENVIANDO_API'")
      ->execute([$safeResponse,$http,'Registro não confirmado (HTTP '.$http.'). Consulte a conta Sicoob; reenvio automático bloqueado.',$companyId,$chargeId]);
    throw new RuntimeException('Sicoob não confirmou registro completo (HTTP '.$http.'). Não reenviar sem verificar no banco.');
}
