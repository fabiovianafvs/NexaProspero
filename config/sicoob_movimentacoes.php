<?php
declare(strict_types=1);
/**
 * Nexa Próspero — Sicoob Cobrança V3 — V1.6.
 * Consulta assíncrona de movimentações de LIQUIDAÇÃO (tipoMovimento=5).
 * NÃO realiza baixa, altera boleto ou escreve no Contas a Receber.
 * JSON e ZIP recebidos são processados temporariamente e descartados.
 */
require_once __DIR__.'/sicoob_boleto.php';

function sicoob_v16_guard_dates(string $start, string $end): void {
    foreach ([$start,$end] as $v) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) || !checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4))) {
            throw new RuntimeException('Data inválida. Informe o formato AAAA-MM-DD.');
        }
    }
    $a=new DateTimeImmutable($start,new DateTimeZone('America/Sao_Paulo'));
    $b=new DateTimeImmutable($end,new DateTimeZone('America/Sao_Paulo'));
    $today=new DateTimeImmutable('today',new DateTimeZone('America/Sao_Paulo'));
    if ($a>$b || $b>$today) throw new RuntimeException('Período inválido ou no futuro.');
    if ($a<$today->modify('-1 year')) throw new RuntimeException('A API consulta somente o último ano.');
    if ($b->diff($a)->days>1) throw new RuntimeException('No Sicoob cada solicitação deve abranger no máximo 2 dias.');
}
function sicoob_v16_auth_request(array $cfg, PDO $pdo, string $method, string $suffix, ?array $body=null): array {
    $diag=sicoob_boleto_diagnose($cfg,$pdo);
    if (empty($diag['ok'])) throw new RuntimeException('Configuração Sicoob indisponível: '.implode('; ',(array)($diag['errors']??[])));
    $c=$diag['config'];
    if (!preg_match('#^/boletos/movimentacoes(?:/download)?(?:\?[a-zA-Z0-9=&%._-]+)?$#D',$suffix)) {
        throw new RuntimeException('Rota de movimentação não permitida.');
    }
    $tok=sicoob_boleto_get_token_for_scope($cfg,$pdo,'boletos_consulta');
    $rawBody=$body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return sicoob_boleto_http($c,$c['api_url'].$suffix,$method,[
        'Accept: application/json','Content-Type: application/json',
        'Authorization: Bearer '.$tok['access_token'],'client_id: '.$c['client_id'],
    ],$rawBody);
}
function sicoob_v16_error(array $response): string {
    $status=(int)($response['status']??0);
    if (function_exists('sicoob_boleto_response_error_summary')) {
        $message=sicoob_boleto_response_error_summary($response);
        return 'Sicoob HTTP '.$status.': '.$message;
    }
    return 'Sicoob HTTP '.$status.' — resposta não reconhecida.';
}
function sicoob_v16_request(array $cfg, PDO $pdo, string $start, string $end): array {
    sicoob_v16_guard_dates($start,$end);
    $c=sicoob_boleto_config($cfg);
    $r=sicoob_v16_auth_request($cfg,$pdo,'POST','/boletos/movimentacoes',[
        'numeroCliente'=>(int)$c['numero_cliente'], 'tipoMovimento'=>5,
        'dataInicial'=>$start,'dataFinal'=>$end,
    ]);
    if (!in_array((int)$r['status'],[200,201,202],true)) throw new RuntimeException(sicoob_v16_error($r));
    $data=$r['json']['resultado']??null;
    if (!is_array($data)||!ctype_digit((string)($data['codigoSolicitacao']??''))) {
        throw new RuntimeException('Sicoob aceitou solicitação mas não retornou codigoSolicitacao válido. Consulte o portal antes de repetir.');
    }
    return ['codigo_solicitacao'=>(string)$data['codigoSolicitacao'],'http'=>(int)$r['status'],'inicio'=>$start,'fim'=>$end];
}
function sicoob_v16_status(array $cfg, PDO $pdo, string $codigo): array {
    if (!ctype_digit($codigo)||strlen($codigo)>16) throw new RuntimeException('Código de solicitação inválido.');
    $c=sicoob_boleto_config($cfg);
    $q=http_build_query(['numeroCliente'=>$c['numero_cliente'],'codigoSolicitacao'=>$codigo],'','&',PHP_QUERY_RFC3986);
    $r=sicoob_v16_auth_request($cfg,$pdo,'GET','/boletos/movimentacoes?'.$q);
    if (!in_array((int)$r['status'],[200,202,204],true)) throw new RuntimeException(sicoob_v16_error($r));
    $data=$r['json']['resultado']??[];
    if (!is_array($data)) $data=[];
    $ids=[];
    foreach ((array)($data['idArquivos']??[]) as $value) {
        if ((is_int($value)||is_string($value)) && ctype_digit((string)$value) && (int)$value>0) $ids[]=(int)$value;
    }
    if (count($ids)>30) throw new RuntimeException('Resposta com mais arquivos que o limite de diagnóstico.');
    return ['http'=>(int)$r['status'],'codigo'=>$codigo,'quantidade_arquivos'=>(int)($data['quantidadeArquivo']??count($ids)),
        'quantidade_registros'=>(string)($data['quantidadeTotalRegistros']??''),'ids'=>array_values(array_unique($ids)),
        'situacao'=>$ids?'ARQUIVOS DISPONÍVEIS':'EM PROCESSAMENTO OU SEM ARQUIVOS'];
}
/**
 * NUNCA exibir valores de pagador, CPF/CNPJ, Nosso Número ou conteúdo bruto.
 * Somente nomes de campos e contagens, com limite de tamanho.
 */
function sicoob_v16_inspect_zip(array $cfg, PDO $pdo, string $codigo,int $fileId): array {
    if (!ctype_digit($codigo)||strlen($codigo)>16||$fileId<=0) throw new RuntimeException('Identificação de arquivo inválida.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Extensão ZIP do PHP indisponível.');
    $c=sicoob_boleto_config($cfg);
    $q=http_build_query(['numeroCliente'=>$c['numero_cliente'],'codigoSolicitacao'=>$codigo,'idArquivo'=>$fileId],'','&',PHP_QUERY_RFC3986);
    $r=sicoob_v16_auth_request($cfg,$pdo,'GET','/boletos/movimentacoes/download?'.$q);
    if ((int)$r['status']!==200) throw new RuntimeException(sicoob_v16_error($r));
    $raw=$r['json']['resultado']['arquivo']??null;
    if (!is_string($raw)||$raw===''||strlen($raw)>1500000) throw new RuntimeException('Download não retornou ZIP Base64 válido dentro do limite de diagnóstico (1,5 MB).');
    $decoded=base64_decode($raw,true);
    if (!is_string($decoded)||strlen($decoded)>1100000||substr($decoded,0,2)!=='PK') throw new RuntimeException('Arquivo Sicoob não reconhecido como ZIP válido.');
    $tmp=tempnam(sys_get_temp_dir(),'nexa_sicoob_');
    if ($tmp===false) throw new RuntimeException('Área temporária indisponível.');
    $z=new ZipArchive();
    $opened=false;
    try {
        if (file_put_contents($tmp,$decoded,LOCK_EX)===false) throw new RuntimeException('Falha na escrita temporária protegida.');
        if ($z->open($tmp)!==true) throw new RuntimeException('ZIP devolvido pelo Sicoob inválido.');
        $opened=true;
        if ($z->numFiles<1||$z->numFiles>5) throw new RuntimeException('ZIP com quantidade de arquivos fora do limite.');
        $records=[];
        for($i=0;$i<$z->numFiles;$i++){
            $stat=$z->statIndex($i);$name=(string)($stat['name']??'');
            if (!preg_match('/^[A-Za-z0-9_.-]+\.json$/iD',$name) || (int)($stat['size']??0)>5000000) continue;
            $jsonString=$z->getFromIndex($i,5000001);
            if (!is_string($jsonString)||strlen($jsonString)>5000000) continue;
            $data=json_decode($jsonString,true,128,JSON_BIGINT_AS_STRING);
            if (!is_array($data)) continue;
            $fields=[];
            $walk=static function(mixed $node,string $path,int $depth) use (&$walk,&$fields):void {
                if($depth>4||count($fields)>35||!is_array($node)) return;
                $keys=array_keys($node);
                $assoc=!array_is_list($node);
                if($assoc){
                    foreach(array_slice($keys,0,35) as $key){
                        $key=(string)$key;
                        if(!preg_match('/^[A-Za-z0-9_]{1,60}$/D',$key))continue;
                        $next=$path===''?$key:$path.'.'.$key;
                        $fields[]=$next;
                        if(is_array($node[$key]))$walk($node[$key],$next,$depth+1);
                        if(count($fields)>35)break;
                    }
                } elseif(isset($node[0])&&is_array($node[0])) {$walk($node[0],$path.'[]',$depth+1);}
            };
            $walk($data,'',0);
            $records[]=['arquivo'=>'JSON #'.($i+1),'chaves'=>array_slice(array_values(array_unique($fields)),0,35),
                'itens_no_primeiro_nivel'=>count($data)];
        }
        if (!$records) throw new RuntimeException('ZIP recebido, mas não contém JSON interpretável dentro dos limites.');
        return ['arquivo_id'=>$fileId,'jsons'=>$records,'observacao'=>'Somente nomes dos campos são exibidos. Nenhum título foi baixado.'];
    } finally {
        if ($opened) @$z->close();
        @unlink($tmp);
    }
}
