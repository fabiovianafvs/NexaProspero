<?php
/**
 * Nexa Próspero - Leitura informativa da situação real do boleto no Sicoob.
 * Sem atualização de cobranças, contas a receber ou lançamentos contábeis.
 * A situação 'Baixado' NÃO indica, por si, pagamento.
 */
declare(strict_types=1);

if (!function_exists('nexa_sicoob_read_bank_status')) {
    function nexa_sicoob_read_bank_status(array $consult): array
    {
        $http = (int)($consult['status'] ?? 0);
        $response = $consult['response'] ?? null;
        $raw = is_array($response) ? ($response['resultado'] ?? null) : null;
        if ($http !== 200 || !is_array($raw)) {
            return [
                'situacao' => 'NÃO DETERMINADA',
                'confirmed_paid' => false,
                'matched' => false,
                'detail' => 'A consulta não trouxe os dados bancários do boleto. HTTP ' . $http . '.',
            ];
        }
        if (array_is_list($raw)) {
            if (count($raw) !== 1 || !is_array($raw[0])) {
                return ['situacao'=>'NÃO DETERMINADA', 'confirmed_paid'=>false, 'matched'=>false,
                    'detail'=>'A API devolveu uma quantidade inesperada de títulos. Nenhum pagamento pode ser confirmado.'];
            }
            $raw = $raw[0];
        }
        $state = trim((string)($raw['situacaoBoleto'] ?? ''));
        $normal = strtolower($state);
        
        $mapped = match ($normal) {
            'liquidado' => 'LIQUIDADO',
            'em aberto' => 'EM ABERTO',
            'baixado' => 'BAIXADO',
            default => 'NÃO DETERMINADA',
        };
        $local = preg_replace('/\D+/', '', (string)($consult['nosso_numero'] ?? '')) ?? '';
        $bank  = preg_replace('/\D+/', '', (string)($raw['nossoNumero'] ?? '')) ?? '';
        $matched = $local !== '' && $bank !== '' && ltrim($local,'0') === ltrim($bank,'0');
        if (!$matched) {
            return ['situacao'=>'DADOS DIVERGENTES', 'confirmed_paid'=>false,'matched'=>false,
                'detail'=>'A resposta não confirmou o mesmo Nosso Número consultado. Confira o boleto e a conta bancária.'];
        }
        $value = $raw['valor'] ?? null;
        $amount = (is_numeric($value) && is_finite((float)$value) && (float)$value>=0)
            ? number_format((float)$value,2,',','.') : null;
        $detail = 'Situação informada pelo Sicoob: ' . $mapped . '. Nosso Número ' . $local;
        if ($amount !== null) $detail .= ' · valor nominal R$ ' . $amount;
        if ($mapped === 'LIQUIDADO') {
            $detail .= '. O banco indica liquidação; a baixa no Nexa ainda depende de conciliação e NÃO foi executada nesta consulta.';
        } elseif ($mapped === 'EM ABERTO') {
            $detail .= '. O banco ainda indica o título em aberto; compare com o comprovante e a data de compensação.';
        } elseif ($mapped === 'BAIXADO') {
            $detail .= '. ATENÇÃO: baixado pelo banco não significa necessariamente que foi pago.';
        } else {
            $detail .= '. A API não apresentou situação reconhecida; nenhuma baixa pode ser realizada.';
        }
        return [
            'situacao'=>$mapped,
            'confirmed_paid'=>$mapped === 'LIQUIDADO',
            'matched'=>true,
            'detail'=>$detail,
        ];
    }
}
