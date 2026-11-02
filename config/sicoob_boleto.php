<?php
declare(strict_types=1);
/**
 * Nexa Próspero / Sicoob Cobrança Bancária V3 — camada somente de consulta.
 * Este adaptador NÃO registra, altera, baixa, concilia ou liquida títulos.
 * Configuração por finance_bank_billing_configs da mesma empresa/conta bancária.
 */
if (!function_exists('sicoob_boleto_default_auth_url')) {
    function sicoob_boleto_default_auth_url(): string {
        return 'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token';
    }
}
if (!function_exists('sicoob_boleto_default_api_url')) {
    function sicoob_boleto_default_api_url(): string {
        return 'https://api.sicoob.com.br/cobranca-bancaria/v3';
    }
}
if (!function_exists('sicoob_boleto_digits')) {
    function sicoob_boleto_digits(mixed $value): string {
        return preg_replace('/\D+/', '', (string)$value) ?? '';
    }
}
if (!function_exists('sicoob_boleto_config')) {
    function sicoob_boleto_config(array $row): array {
        return [
            'company_id'=>(int)($row['company_id'] ?? 0),
            'bank_account_id'=>(int)($row['bank_account_id'] ?? 0),
            'provider'=>strtoupper(trim((string)($row['provider'] ?? ''))),
            'enabled'=>!empty($row['enabled']),
            'mode'=>strtoupper(trim((string)($row['integration_mode'] ?? ''))),
            'environment'=>strtoupper(trim((string)($row['environment'] ?? ''))),
            // Campos existentes; não reutilizar sequências Itaú.
            'numero_cliente'=>sicoob_boleto_digits($row['convenio'] ?? ''),
            'codigo_modalidade'=>sicoob_boleto_digits($row['carteira'] ?? ''),
            'client_id'=>trim((string)($row['api_client_id'] ?? '')),
            'cert_path'=>trim((string)($row['cert_path'] ?? '')),
            'key_path'=>trim((string)($row['key_path'] ?? '')),
            'ca_path'=>trim((string)($row['ca_path'] ?? '')),
            'oauth_url'=>trim((string)($row['oauth_url'] ?? '')) ?: sicoob_boleto_default_auth_url(),
            'api_url'=>rtrim(trim((string)($row['api_base_url'] ?? '')) ?: sicoob_boleto_default_api_url(), '/'),
        ];
    }
}
if (!function_exists('sicoob_boleto_validate_url')) {
    function sicoob_boleto_validate_url(string $url,string $expectedHost, string $pathPrefix): void {
        $parts=parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== $expectedHost
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !str_starts_with((string)($parts['path'] ?? ''),$pathPrefix)) {
            throw new RuntimeException('Endereço Sicoob não permitido. Confira o endpoint oficial de produção.');
        }
    }
}

/**
 * Usa exclusivamente o certificado fiscal A1 ATIVO da mesma empresa.
 * Não converte nem copia a chave privada em disco. O P12 continua sob guarda
 * do módulo fiscal; a senha é carregada em memória só durante a chamada.
 */
if (!function_exists('sicoob_boleto_fiscal_a1')) {
    function sicoob_boleto_fiscal_a1(PDO $pdo, int $companyId): array {
        if ($companyId <= 0) throw new RuntimeException('Empresa inválida para certificado Sicoob.');
        $st = $pdo->prepare("SELECT certificate_type,file_path,password_secret FROM fiscal_certificates WHERE company_id=? AND is_active=1 ORDER BY valid_until DESC,id DESC LIMIT 1");
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) throw new RuntimeException('Nenhum A1 fiscal ativo nesta empresa. Cadastre-o em Configurações Fiscais.');
        if (strtoupper((string)($row['certificate_type'] ?? '')) !== 'A1') {
            throw new RuntimeException('O certificado fiscal ativo não é do tipo A1.');
        }
        $relative = str_replace('\\', '/', trim((string)($row['file_path'] ?? '')));
        $expectedPrefix = 'uploads/certificados/' . $companyId . '/';
        if (!str_starts_with($relative, $expectedPrefix) || str_contains($relative, '../')) {
            throw new RuntimeException('Caminho do A1 fiscal fora do diretório protegido da empresa.');
        }
        $base = realpath(dirname(__DIR__) . '/uploads/certificados/' . $companyId);
        $absolute = realpath(dirname(__DIR__) . '/' . $relative);
        if ($base === false || $absolute === false || !str_starts_with($absolute, rtrim($base, '/') . '/') || !is_file($absolute) || !is_readable($absolute)) {
            throw new RuntimeException('O A1 fiscal ativo não está acessível ao PHP.');
        }
        if (!in_array(strtolower(pathinfo($absolute, PATHINFO_EXTENSION)), ['pfx', 'p12'], true)) {
            throw new RuntimeException('O A1 fiscal ativo precisa estar em arquivo PFX/P12.');
        }
        $pass = (string)($row['password_secret'] ?? '');
        if ($pass === '') throw new RuntimeException('A senha do A1 fiscal não está cadastrada.');
        if (!function_exists('openssl_pkcs12_read')) throw new RuntimeException('Extensão OpenSSL do PHP indisponível.');
        $raw = @file_get_contents($absolute);
        $bundle = [];
        if (!is_string($raw) || $raw === '' || !@openssl_pkcs12_read($raw, $bundle, $pass)
            || empty($bundle['cert']) || empty($bundle['pkey'])) {
            throw new RuntimeException('Não foi possível validar o PFX/P12 fiscal com a senha armazenada.');
        }
        $info = @openssl_x509_parse((string)$bundle['cert']);
        $matched = @openssl_x509_check_private_key((string)$bundle['cert'], (string)$bundle['pkey']);
        unset($raw, $bundle);
        if (!$matched) throw new RuntimeException('Chave e certificado do A1 fiscal não correspondem.');
        if (!is_array($info) || empty($info['validTo_time_t']) || (int)$info['validTo_time_t'] <= time()) {
            throw new RuntimeException('A1 fiscal vencido ou sem validade verificável.');
        }
        return ['path' => $absolute, 'password' => $pass];
    }
}

if (!function_exists('sicoob_boleto_diagnose')) {
    function sicoob_boleto_diagnose(array $raw, ?PDO $pdo = null): array {
        $c=sicoob_boleto_config($raw);$errors=[];
        if ($c['provider']!=='SICOOB' || $c['mode']!=='API') $errors[]='Provedor deve ser SICOOB / API.';
        if (!$c['enabled']) $errors[]='Habilite cobrança na conta bancária.';
        if ($c['environment']!=='PRODUCAO') $errors[]='Este conector requer PRODUCAO. O sandbox não é suportado nesta fase.';
        if ($c['client_id']==='') $errors[]='Client ID do aplicativo Sicoob não informado.';
        if ($c['numero_cliente']==='' || !ctype_digit($c['numero_cliente'])) $errors[]='Convênio deve conter o numeroCliente do Sicoob.';
        if ($c['codigo_modalidade']==='' || !ctype_digit($c['codigo_modalidade'])) $errors[]='Carteira deve conter o codigoModalidade do Sicoob.';
        // Sem caminhos bancários: reutiliza o A1 fiscal ATIVO desta empresa.
        // Se o usuário preencheu qualquer caminho bancário, exige o par PEM explícito.
        $useFiscalA1 = ($c['cert_path']==='' && $c['key_path']==='');
        if ($useFiscalA1) {
            if ($pdo === null) {
                $errors[]='Conexão do banco indisponível para localizar o A1 fiscal.';
            } else {
                try {
                    $a1=sicoob_boleto_fiscal_a1($pdo,$c['company_id']);
                    $c['cert_path']=$a1['path'];
                    $c['cert_password']=$a1['password'];
                    $c['cert_type']='P12';
                    $c['cert_source']='fiscal_a1';
                } catch (Throwable $e) { $errors[]=$e->getMessage(); }
            }
        } else {
            $c['cert_type']='PEM';
            $c['cert_source']='bank_pem';
            foreach (['cert_path'=>'certificado PEM/CRT','key_path'=>'chave privada PEM/KEY'] as $key=>$label){
                $p=$c[$key];
                if ($p==='' || !is_file($p) || !is_readable($p)) $errors[]='Arquivo de '.$label.' indisponível ao PHP.';
            }
        }
        if ($c['ca_path']!=='' && (!is_file($c['ca_path']) || !is_readable($c['ca_path']))) $errors[]='Cadeia CA configurada, mas não acessível.';
        try {
            sicoob_boleto_validate_url($c['oauth_url'],'auth.sicoob.com.br','/auth/realms/cooperado/protocol/openid-connect/token');
            sicoob_boleto_validate_url($c['api_url'],'api.sicoob.com.br','/cobranca-bancaria/v3');
        } catch(Throwable $e) {$errors[]=$e->getMessage();}
        if (!$errors && $c['cert_type']==='PEM' && function_exists('openssl_x509_check_private_key')) {
            $cert=@file_get_contents($c['cert_path']);$key=@file_get_contents($c['key_path']);
            if ($cert === false || $key === false || !@openssl_x509_check_private_key($cert,$key)) $errors[]='Certificado e chave privada não correspondem.';
            if ($cert!==false && function_exists('openssl_x509_parse')) {
                $info=@openssl_x509_parse($cert);
                if (is_array($info) && isset($info['validTo_time_t']) && (int)$info['validTo_time_t'] <= time()) $errors[]='Certificado vencido.';
            }
        }
        if (!extension_loaded('curl')) $errors[]='Extensão PHP cURL indisponível.';
        return ['ok'=>!$errors,'errors'=>$errors,'config'=>$c];
    }
}
if (!function_exists('sicoob_boleto_http')) {
    function sicoob_boleto_http(array $c,string $url,string $method,array $headers=[],?string $body=null): array {
        if (!extension_loaded('curl')) throw new RuntimeException('Extensão cURL não habilitada.');
        $curl=curl_init($url);
        if ($curl === false) throw new RuntimeException('Não foi possível preparar a conexão Sicoob.');
        $options=[
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>false, CURLOPT_CONNECTTIMEOUT=>12,
            CURLOPT_TIMEOUT=>35, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_SSLCERT=>$c['cert_path'],
            CURLOPT_SSLCERTTYPE=>($c['cert_type'] ?? 'PEM'), CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        ];
        if (defined('CURLOPT_SSLVERSION') && defined('CURL_SSLVERSION_TLSv1_2')) $options[CURLOPT_SSLVERSION]=CURL_SSLVERSION_TLSv1_2;
        if (($c['cert_type'] ?? 'PEM')==='P12') {
            // libcurl/OpenSSL suporta certificado PKCS#12 diretamente (sem exportar PEM).
            $options[CURLOPT_KEYPASSWD]=$c['cert_password'];
        } else {
            $options[CURLOPT_SSLKEY]=$c['key_path'];
        }
        if ($c['ca_path']!=='')$options[CURLOPT_CAINFO]=$c['ca_path'];
        if ($body!==null)$options[CURLOPT_POSTFIELDS]=$body;
        curl_setopt_array($curl,$options);
        try {
            $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($raw===false) throw new RuntimeException('Conexão com Sicoob não concluída (cURL '.curl_errno($curl).').');
            if (strlen($raw)>2000000) throw new RuntimeException('Resposta Sicoob maior que o limite de leitura.');
            return ['status'=>$status,'raw'=>$raw,'json'=>json_decode($raw,true),
                'content_type'=>(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE)];
        } finally {curl_close($curl);}
    }
}
/**
 * Escopos separados: consulta usa boletos_consulta; este pré-teste solicita
 * boletos_inclusao APENAS ao endpoint OAuth, sem tentar registrar boletos.
 */
if (!function_exists('sicoob_boleto_get_token_for_scope')) {
    function sicoob_boleto_get_token_for_scope(array $raw, ?PDO $pdo, string $scope): array {
        if (!in_array($scope, ['boletos_consulta', 'boletos_inclusao'], true)) {
            throw new RuntimeException('Escopo de integração bancária não permitido.');
        }
        $d=sicoob_boleto_diagnose($raw,$pdo);
        if (!$d['ok']) throw new RuntimeException(implode(' ', $d['errors']));
        $c=$d['config'];
        $params=http_build_query(['grant_type'=>'client_credentials','client_id'=>$c['client_id'],'scope'=>$scope],'','&',PHP_QUERY_RFC3986);
        $r=sicoob_boleto_http($c,$c['oauth_url'],'POST',['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],$params);
        $j=is_array($r['json'])?$r['json']:[];
        if ($r['status']!==200 || empty($j['access_token'])) {
            throw new RuntimeException('OAuth Sicoob não autorizado para '.$scope.' (HTTP '.$r['status'].'). Verifique permissão do aplicativo no Sicoob.');
        }
        // Escopo concedido pode ser reduzido pelo provedor. Quando vier na resposta, conferir.
        if (isset($j['scope']) && is_string($j['scope']) && $j['scope'] !== ''
            && !in_array($scope, preg_split('/\s+/', trim($j['scope'])) ?: [], true)) {
            throw new RuntimeException('OAuth Sicoob não concedeu o escopo '.$scope.'.');
        }
        // Token jamais retornado ao navegador/log; a chamada só existe em memória PHP.
        return ['access_token'=>(string)$j['access_token'],'expires_in'=>(int)($j['expires_in']??0)];
    }
}
if (!function_exists('sicoob_boleto_get_token')) {
    function sicoob_boleto_get_token(array $raw, ?PDO $pdo = null): array {
        return sicoob_boleto_get_token_for_scope($raw, $pdo, 'boletos_consulta');
    }
}
if (!function_exists('sicoob_boleto_preflight_inclusion')) {
    function sicoob_boleto_preflight_inclusion(array $raw, PDO $pdo): array {
        $token=sicoob_boleto_get_token_for_scope($raw,$pdo,'boletos_inclusao');
        return ['scope'=>'boletos_inclusao','expires_in'=>(int)$token['expires_in']];
    }
}
/**
 * Extrai APENAS mensagens conhecidas de erros de negocio, sem imprimir corpo
 * bruto, credenciais, token ou dados completos da resposta.
 */
if (!function_exists('sicoob_boleto_response_error_summary')) {
    function sicoob_boleto_response_error_summary(array $response): string {
        $json=$response['json'] ?? null;
        if (!is_array($json)) return 'O Sicoob não forneceu uma mensagem JSON de erro.';
        $messages=[];
        $walk=function(mixed $item,int $depth=0) use (&$walk,&$messages): void {
            if ($depth>4 || count($messages)>=3 || !is_array($item)) return;
            foreach ($item as $key=>$value) {
                $k=strtolower((string)$key);
                // Não revelar payloads genéricos, tokens ou campos bancários.
                if (in_array($k,['mensagem','message','detail','descricao','description','title','erro','error_description'],true)
                    && is_string($value) && trim($value)!=='') {
                    $s=preg_replace('/[\x00-\x1F\x7F]/u',' ', trim($value));
                    if (is_string($s)) $messages[]=function_exists('mb_substr') ? mb_substr($s,0,300,'UTF-8') : substr($s,0,300);
                    if (count($messages)>=3) return;
                } elseif (in_array($k,['erros','errors','mensagens','violacoes','violations','resultado'],true)) {
                    if (is_array($value)) $walk($value,$depth+1);
                } elseif (is_int($key) && is_array($value)) {
                    $walk($value,$depth+1);
                } elseif (is_int($key) && is_string($value) && trim($value)!=='') {
                    $messages[]=function_exists('mb_substr') ? mb_substr(trim($value),0,300,'UTF-8') : substr(trim($value),0,300);
                }
            }
        };
        $walk($json);
        $messages=array_values(array_unique($messages));
        return $messages ? implode(' | ', array_slice($messages,0,3)) : 'O Sicoob não detalhou o motivo no formato reconhecido.';
    }
}
/**
 * Consulta read-only; o numeroContratoCobranca é opcional NESTE endpoint,
 * conforme a coleção Sicoob V3. Não confundir com registro de boleto.
 */
if (!function_exists('sicoob_boleto_consult_number_ranges')) {
    function sicoob_boleto_consult_number_ranges(array $raw, PDO $pdo, string $numeroContratoCobranca='', int $quantidade=1, string $rota='colecao'): array {
        $diag=sicoob_boleto_diagnose($raw,$pdo);
        if (!$diag['ok']) throw new RuntimeException(implode(' ', $diag['errors']));
        $c=$diag['config'];
        $numeroContratoCobranca=trim($numeroContratoCobranca);
        if ($numeroContratoCobranca!=='' && !preg_match('/^[0-9]{1,18}$/D', $numeroContratoCobranca)) {
            throw new RuntimeException('Número do contrato de cobrança inválido: informe apenas dígitos (até 18).');
        }
        if ($quantidade < 1 || $quantidade > 1000) {
            throw new RuntimeException('Quantidade para consulta deve estar entre 1 e 1000.');
        }
        // Duas versões documentadas da mesma consulta GET. Nunca aceitar URL arbitrária.
        $rotas=[
            'colecao'=>'/boletos/faixas-nosso-numero-disponiveis',
            'alternativa'=>'/boletos/faixas-nosso-numero',
        ];
        if (!isset($rotas[$rota])) throw new RuntimeException('Rota de consulta Sicoob não permitida.');
        $params=[
            'numeroCliente'=>$c['numero_cliente'],
            'codigoModalidade'=>$c['codigo_modalidade'],
            'quantidade'=>$quantidade,
        ];
        if ($numeroContratoCobranca!=='') $params['numeroContratoCobranca']=$numeroContratoCobranca;
        $token=sicoob_boleto_get_token($raw,$pdo);
        $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
        $res=sicoob_boleto_http($c,$c['api_url'].$rotas[$rota].'?'.$query,'GET',[
            'Accept: application/json', 'Content-Type: application/json',
            'Authorization: Bearer '.$token['access_token'], 'client_id: '.$c['client_id']
        ]);
        if ($res['status']===204) return [
            'ranges'=>[], 'http_status'=>204,
            'rota_testada'=>$rotas[$rota], 'quantidade_solicitada'=>$quantidade,
            'contrato_informado'=>$numeroContratoCobranca!=='',
            'observacao'=>'O Sicoob processou a consulta sem retornar faixas (HTTP 204). Nenhum número foi reservado.'
        ];
        if ($res['status'] !== 200) {
            $contentType=trim((string)($res['content_type']??''));
            $contentType=preg_replace('/[^a-zA-Z0-9._+\/;= -]/','',$contentType) ?: 'não informado';
            throw new RuntimeException(
                'Consulta de faixas recusada na rota '.$rotas[$rota].' (HTTP '.$res['status'].'). '.
                sicoob_boleto_response_error_summary($res).
                ' Tipo de resposta: '.substr($contentType,0,90).'.'
            );
        }
        $items=$res['json']['resultado'] ?? null;
        if (!is_array($items)) throw new RuntimeException('Consulta HTTP 200 sem lista de faixas reconhecível na rota '.$rotas[$rota].'.');
        $ranges=[];
        foreach (array_slice($items,0,20) as $item) {
            if (!is_array($item)) continue;
            $initial=sicoob_boleto_digits($item['numeroInicial'] ?? '');
            $final=sicoob_boleto_digits($item['numeroFinal'] ?? '');
            if ($initial === '' || $final === '' || strlen($initial) > 25 || strlen($final) > 25) continue;
            $ranges[]=[
                'numeroInicial'=>$initial,
                'numeroFinal'=>$final,
                'quantidade'=>max(0,(int)($item['quantidade'] ?? 0)),
                'validaDigitoVerificadorNossoNumero'=>(string)($item['validaDigitoVerificadorNossoNumero'] ?? ''),
            ];
        }
        return [
            'ranges'=>$ranges, 'http_status'=>$res['status'],
            'rota_testada'=>$rotas[$rota],
            'quantidade_solicitada'=>$quantidade,
            'contrato_informado'=>$numeroContratoCobranca!=='',
            'observacao'=>'Faixas consultadas, não reservadas.'
        ];
    }
}
if (!function_exists('sicoob_boleto_consult_charge')) {
    function sicoob_boleto_consult_charge(PDO $pdo,int $companyId,int $bankId,int $chargeId): array {
        // Todas as buscas são segmentadas por empresa e conta, e somente leituras.
        $st=$pdo->prepare("SELECT * FROM finance_boleto_charges WHERE company_id=? AND bank_account_id=? AND id=? AND provider='SICOOB' AND integration_mode='API' LIMIT 1");
        $st->execute([$companyId,$bankId,$chargeId]);$charge=$st->fetch(PDO::FETCH_ASSOC)?:[];
        if (!$charge) throw new RuntimeException('Boleto Sicoob não encontrado nesta empresa/conta.');
        $st=$pdo->prepare('SELECT * FROM finance_bank_billing_configs WHERE company_id=? AND bank_account_id=? LIMIT 1');
        $st->execute([$companyId,$bankId]);$cfg=$st->fetch(PDO::FETCH_ASSOC)?:[];
        // A consulta do boleto vinculado deve resolver o certificado exatamente
        // como as demais operacoes Sicoob (A1 fiscal PFX/P12 ou par PEM).
        // sicoob_boleto_config() sozinho nao inclui a fonte do A1 fiscal;
        // sua utilizacao no GET causava cURL 58, embora o POST fosse autorizado.
        $diag=sicoob_boleto_diagnose($cfg,$pdo);
        if (!$diag['ok']) throw new RuntimeException(implode(' ', $diag['errors']));
        $c=$diag['config'];
        $nosso=sicoob_boleto_digits($charge['nosso_numero']??'');
        $barcode=sicoob_boleto_digits($charge['barcode']??'');
        if ($nosso==='0')$nosso='';
        if ($nosso==='') throw new RuntimeException('Cobrança ainda sem Nosso Número para consulta. Este pacote não registra novos boletos.');
        $token=sicoob_boleto_get_token($cfg,$pdo);
        $params=http_build_query(['numeroCliente'=>$c['numero_cliente'],'codigoModalidade'=>$c['codigo_modalidade'],'nossoNumero'=>$nosso],'','&',PHP_QUERY_RFC3986);
        $r=sicoob_boleto_http($c,$c['api_url'].'/boletos?'.$params,'GET',[
            'Accept: application/json', 'Authorization: Bearer '.$token['access_token'], 'client_id: '.$c['client_id']
        ]);
        // Não modificar status nem baixar financeiro apenas pela consulta.
        return ['status'=>$r['status'],'response'=>is_array($r['json'])?$r['json']:['mensagem'=>'Resposta não JSON.'],'charge_id'=>$chargeId,'nosso_numero'=>$nosso];
    }
}


if (!function_exists('sicoob_boleto_consult_number')) {
    function sicoob_boleto_consult_number(PDO $pdo, array $cfg, string $nossoNumero): array {
        $nosso=sicoob_boleto_digits($nossoNumero);
        if ($nosso==='' || strlen($nosso)>25) throw new RuntimeException('Informe um Nosso Número válido (somente dígitos).');
        $d=sicoob_boleto_diagnose($cfg,$pdo);
        if (!$d['ok']) throw new RuntimeException(implode(' ', $d['errors']));
        $c=$d['config'];$token=sicoob_boleto_get_token($cfg,$pdo);
        $params=http_build_query(['numeroCliente'=>$c['numero_cliente'],'codigoModalidade'=>$c['codigo_modalidade'],'nossoNumero'=>$nosso],'','&',PHP_QUERY_RFC3986);
        $r=sicoob_boleto_http($c,$c['api_url'].'/boletos?'.$params,'GET',[
            'Accept: application/json', 'Authorization: Bearer '.$token['access_token'], 'client_id: '.$c['client_id']
        ]);
        return ['status'=>$r['status'],'response'=>is_array($r['json'])?$r['json']:['mensagem'=>'Resposta não JSON.'],'nosso_numero'=>$nosso];
    }
}
