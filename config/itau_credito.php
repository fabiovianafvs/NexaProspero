<?php
declare(strict_types=1);

/**
 * Nexa Próspero — Itaú: data de crédito dos boletos e BoleCode pago por Pix.
 *
 * 1) Floating: a Francesinha do Itaú mostra a DATA DO PAGAMENTO; o valor só entra
 *    no extrato depois do prazo de floating do contrato (normalmente 2 dias úteis).
 *    Crédito = data da Francesinha + N dias úteis (sem sábados, domingos e feriados
 *    bancários nacionais). N fica por conta Itaú em app_config, chave "itau_credito".
 *
 * 2) Pix: BoleCode pago pelo QR Code vira um Pix comum — o Itaú dá baixa no boleto
 *    sem valor na Francesinha (não aparece em "liquidações"). O pagamento é lido na
 *    API Pix (GET /pix por período; se a credencial não permitir, GET /cob/{txid}),
 *    pelo txid do QR Code guardado na cobrança. Pix cai na hora: crédito = data do Pix.
 */

require_once __DIR__ . '/config_store.php';

if (!function_exists('itau_credito_feriados')) {
    /** Feriados bancários nacionais (sem expediente de compensação) do ano: ['Y-m-d' => nome]. */
    function itau_credito_feriados(int $year): array {
        static $cache = [];
        if (isset($cache[$year])) return $cache[$year];
        $f = [
            "$year-01-01" => 'Confraternização Universal',
            "$year-04-21" => 'Tiradentes',
            "$year-05-01" => 'Dia do Trabalho',
            "$year-09-07" => 'Independência',
            "$year-10-12" => 'Nossa Senhora Aparecida',
            "$year-11-02" => 'Finados',
            "$year-11-15" => 'Proclamação da República',
            "$year-12-25" => 'Natal',
            "$year-12-31" => 'Sem expediente bancário (31/12)',
        ];
        if ($year >= 2024) $f["$year-11-20"] = 'Dia Nacional de Zumbi e da Consciência Negra';
        // Páscoa (algoritmo de Meeus/Jones/Butcher) e datas móveis.
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
        $g = intdiv(8 * $b + 13, 25); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 19 * $l, 433);
        $month = intdiv($h + $l - 7 * $m + 90, 25); $day = ($h + $l - 7 * $m + 33 * $month + 19) % 32;
        $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $f[$easter->modify('-48 days')->format('Y-m-d')] = 'Carnaval';
        $f[$easter->modify('-47 days')->format('Y-m-d')] = 'Carnaval';
        $f[$easter->modify('-2 days')->format('Y-m-d')] = 'Sexta-feira Santa';
        $f[$easter->modify('+60 days')->format('Y-m-d')] = 'Corpus Christi';
        return $cache[$year] = $f;
    }
}

if (!function_exists('itau_credito_dia_util')) {
    function itau_credito_dia_util(string $date): bool {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return false;
        if ((int)$d->format('N') >= 6) return false;
        return !isset(itau_credito_feriados((int)$d->format('Y'))[$date]);
    }
}

if (!function_exists('itau_credito_somar_dias_uteis')) {
    /** Data + N dias úteis bancários. N=0 devolve a própria data. */
    function itau_credito_somar_dias_uteis(string $date, int $days): string {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return $date;
        $days = max(0, min(10, $days));
        while ($days > 0) {
            $d = $d->modify('+1 day');
            if (itau_credito_dia_util($d->format('Y-m-d'))) $days--;
        }
        return $d->format('Y-m-d');
    }
}

if (!function_exists('itau_credito_floating_padrao')) {
    function itau_credito_floating_padrao(): int { return 2; }
}

if (!function_exists('itau_credito_floating')) {
    /** Dias úteis de floating da conta Itaú (padrão do Itaú: 2). */
    function itau_credito_floating(PDO $pdo, int $companyId, int $bankAccountId): int {
        try {
            $cfg = config_store_get($pdo, $companyId, 'itau_credito') ?? [];
            $v = $cfg['contas'][(string)$bankAccountId]['floating_dias'] ?? null;
            if ($v === null || $v === '' || !is_numeric($v)) return itau_credito_floating_padrao();
            return max(0, min(10, (int)$v));
        } catch (Throwable $e) {
            return itau_credito_floating_padrao();
        }
    }
}

if (!function_exists('itau_credito_floating_configurado')) {
    function itau_credito_floating_configurado(PDO $pdo, int $companyId, int $bankAccountId): bool {
        try {
            $cfg = config_store_get($pdo, $companyId, 'itau_credito') ?? [];
            return isset($cfg['contas'][(string)$bankAccountId]['floating_dias']);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('itau_credito_floating_salvar')) {
    function itau_credito_floating_salvar(PDO $pdo, int $companyId, int $bankAccountId, int $days): void {
        if ($days < 0 || $days > 10) throw new RuntimeException('Informe o floating entre 0 e 10 dias úteis.');
        $cfg = config_store_get($pdo, $companyId, 'itau_credito') ?? [];
        if (!isset($cfg['contas']) || !is_array($cfg['contas'])) $cfg['contas'] = [];
        $cfg['contas'][(string)$bankAccountId] = ['floating_dias' => $days, 'alterado_em' => date('Y-m-d H:i:s')];
        config_store_set($pdo, $companyId, 'itau_credito', $cfg);
    }
}

if (!function_exists('itau_credito_data')) {
    /** Data em que o valor da Francesinha do dia $date entra no extrato. */
    function itau_credito_data(string $date, int $floatingDays): string {
        return itau_credito_somar_dias_uteis($date, $floatingDays);
    }
}

/* ------------------------------------------------------------------ Pix (BoleCode) */

if (!function_exists('itau_pix_base_url')) {
    function itau_pix_base_url(array $cfg): string {
        $env = strtoupper(trim((string)($cfg['environment'] ?? 'PRODUCAO')));
        return $env === 'PRODUCAO'
            ? 'https://secure.api.itau/pix_recebimentos/v2'
            : 'https://devportal.itau.com.br/sandboxapi/pix_recebimentos_ext_v2/v2';
    }
}

if (!function_exists('itau_pix_horario_data')) {
    /** Data (fuso de Brasília) do horário do Pix (RFC 3339). */
    function itau_pix_horario_data(string $horario): ?string {
        $horario = trim($horario);
        if ($horario === '') return null;
        try {
            $dt = new DateTimeImmutable($horario);
            return $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
        } catch (Throwable $e) {
            return preg_match('/^(\d{4}-\d{2}-\d{2})/', $horario, $m) ? $m[1] : null;
        }
    }
}

if (!function_exists('itau_pix_candidatas')) {
    /** Cobranças BoleCode em aberto desta conta, com txid do QR Code. */
    function itau_pix_candidatas(PDO $pdo, int $companyId, int $bankId, string $endDate): array {
        $st = $pdo->prepare("SELECT id,nosso_numero,pix_txid,amount,status,receivable_id,created_at FROM finance_boleto_charges
            WHERE company_id=? AND bank_account_id=? AND pix_txid IS NOT NULL AND pix_txid<>''
              AND status IN ('REGISTRADO','VERIFICAR_API') AND nosso_numero IS NOT NULL AND nosso_numero<>''
              AND DATE(created_at)<=?
            ORDER BY id DESC LIMIT 500");
        $st->execute([$companyId, $bankId, $endDate]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(string)$row['pix_txid']] = $row;
        return $out;
    }
}

if (!function_exists('itau_pix_get')) {
    function itau_pix_get(array $cfg, string $path, array $query = []): array {
        if (!function_exists('itau_boleto_get_token')) require_once __DIR__ . '/itau_boleto.php';
        $token = itau_boleto_get_token($cfg);
        $url = rtrim(itau_pix_base_url($cfg), '/') . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return itau_boleto_http($cfg, 'GET', $url, itau_boleto_api_headers($cfg, (string)$token['access_token']), null);
    }
}

if (!function_exists('itau_pix_listar_dia')) {
    /**
     * Pix recebidos com txid no dia (GET /pix). Devolve ['ok'=>bool,'http'=>int,'pix'=>[...]].
     * ok=false com http 401/403/404 = credencial sem acesso à listagem (usa o fallback).
     */
    function itau_pix_listar_dia(array $cfg, string $date): array {
        $all = [];
        for ($page = 0; $page < 50; $page++) {
            $resp = itau_pix_get($cfg, '/pix', [
                'inicio' => $date . 'T00:00:00-03:00',
                'fim' => $date . 'T23:59:59-03:00',
                'txIdPresente' => 'true',
                'paginacao.paginaAtual' => $page,
                'paginacao.itensPorPagina' => 100,
            ]);
            $http = (int)($resp['status'] ?? 0);
            if ($http < 200 || $http >= 300 || !is_array($resp['json'] ?? null)) {
                return ['ok' => false, 'http' => $http, 'pix' => $all, 'message' => itau_boleto_response_message($resp)];
            }
            $json = $resp['json'];
            foreach ((array)($json['pix'] ?? []) as $p) if (is_array($p)) $all[] = $p;
            $pages = (int)($json['parametros']['paginacao']['quantidadeDePaginas'] ?? 1);
            if ($page + 1 >= max(1, $pages)) break;
        }
        return ['ok' => true, 'http' => 200, 'pix' => $all];
    }
}

if (!function_exists('itau_pix_consultar_cob')) {
    /** GET /cob/{txid}: ['ok'=>bool,'http'=>int,'status'=>'CONCLUIDA'|...,'pix'=>[...]]. */
    function itau_pix_consultar_cob(array $cfg, string $txid): array {
        $resp = itau_pix_get($cfg, '/cob/' . rawurlencode($txid));
        $http = (int)($resp['status'] ?? 0);
        if ($http < 200 || $http >= 300 || !is_array($resp['json'] ?? null)) {
            return ['ok' => false, 'http' => $http, 'status' => '', 'pix' => [], 'message' => itau_boleto_response_message($resp)];
        }
        $json = $resp['json'];
        return ['ok' => true, 'http' => $http, 'status' => strtoupper(trim((string)($json['status'] ?? ''))), 'pix' => array_values(array_filter((array)($json['pix'] ?? []), 'is_array'))];
    }
}

if (!function_exists('itau_pix_evento')) {
    /** Monta o evento no mesmo formato do webhook de boletos, para a baixa idempotente. */
    function itau_pix_evento(array $charge, array $pixItems): array {
        $total = 0.0; $date = null; $e2e = []; $devolvido = false;
        foreach ($pixItems as $p) {
            $total += (float)($p['valor'] ?? 0);
            $d = itau_pix_horario_data((string)($p['horario'] ?? ''));
            if ($d !== null && ($date === null || $d > $date)) $date = $d;
            if (!empty($p['endToEndId'])) $e2e[] = (string)$p['endToEndId'];
            if (!empty($p['devolucoes'])) $devolvido = true;
        }
        $date = $date ?: date('Y-m-d');
        return [
            'numeroNossoNumero' => (string)($charge['nosso_numero'] ?? ''),
            'valorTitulo' => round((float)($charge['amount'] ?? 0), 2),
            'valorPagoTotalCobranca' => round($total, 2),
            'valorCreditado' => round($total, 2),
            'dataInclusaoPagamento' => $date,
            'dataCredito' => $date,
            'dataNotificacao' => $date,
            'tipoLiquidacao' => 'PIX',
            'txid' => (string)($charge['pix_txid'] ?? ''),
            'endToEndId' => implode(',', $e2e),
            '_nexa_source' => 'API_PIX_RECEBIMENTOS',
            '_nexa_credit_source' => 'PIX',
            '_nexa_pix_devolvido' => $devolvido ? 1 : 0,
        ];
    }
}

if (!function_exists('itau_pix_sync_bolecode')) {
    /**
     * Procura Pix pagos de BoleCodes em aberto no período e dá a baixa (ou só lista,
     * em modo consulta). Nunca baixa sem o Pix confirmado pelo Itaú.
     */
    function itau_pix_sync_bolecode(PDO $pdo, array $cfg, string $startDate, string $endDate, bool $queryOnly): array {
        $out = ['candidates' => 0, 'found' => 0, 'processed' => 0, 'already' => 0, 'pending' => 0, 'errors' => 0, 'amount' => 0.0, 'details' => [], 'diagnostic_rows' => [], 'method' => ''];
        $companyId = (int)($cfg['company_id'] ?? 0); $bankId = (int)($cfg['bank_account_id'] ?? 0);
        if ($companyId <= 0 || $bankId <= 0) return $out;
        $cands = itau_pix_candidatas($pdo, $companyId, $bankId, $endDate);
        $out['candidates'] = count($cands);
        if (!$cands) return $out;

        // 1) Listagem dos Pix recebidos por dia (uma chamada por dia/página).
        $paid = []; // txid => [pix items]
        $listOk = true; $listHttp = 0; $listMsg = '';
        $start = new DateTimeImmutable($startDate); $end = new DateTimeImmutable($endDate);
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            try { $r = itau_pix_listar_dia($cfg, $d->format('Y-m-d')); }
            catch (Throwable $e) { $r = ['ok' => false, 'http' => 0, 'pix' => [], 'message' => $e->getMessage()]; }
            if (empty($r['ok'])) { $listOk = false; $listHttp = (int)($r['http'] ?? 0); $listMsg = (string)($r['message'] ?? ''); break; }
            foreach ($r['pix'] as $p) {
                $txid = trim((string)($p['txid'] ?? ''));
                if ($txid !== '' && isset($cands[$txid])) $paid[$txid][] = $p;
            }
        }
        $out['method'] = $listOk ? 'LISTA_PIX' : 'COB_TXID';

        // 2) Sem acesso à listagem: consulta cada QR Code em aberto (GET /cob/{txid}).
        if (!$listOk) {
            $paid = []; $cobErrors = 0; $cobHttp = 0; $cobMsg = '';
            foreach (array_slice($cands, 0, 150, true) as $txid => $charge) {
                try { $r = itau_pix_consultar_cob($cfg, (string)$txid); }
                catch (Throwable $e) { $r = ['ok' => false, 'http' => 0, 'message' => $e->getMessage()]; }
                if (empty($r['ok'])) {
                    $cobErrors++; $cobHttp = (int)($r['http'] ?? 0); $cobMsg = (string)($r['message'] ?? '');
                    if (in_array($cobHttp, [401, 403], true)) break; // sem permissão: não insiste
                    continue;
                }
                if ($r['status'] === 'CONCLUIDA' && $r['pix']) {
                    // Só considera Pix pagos até o fim do período consultado.
                    $items = array_values(array_filter($r['pix'], static function ($p) use ($endDate) {
                        $d = itau_pix_horario_data((string)($p['horario'] ?? ''));
                        return $d === null || $d <= $endDate;
                    }));
                    if ($items) $paid[$txid] = $items;
                }
            }
            if ($cobErrors > 0 && !$paid && in_array($cobHttp, [401, 403], true)) {
                $out['errors']++;
                $out['details'][] = ['nosso_numero' => '', 'status' => 'PIX_SEM_PERMISSAO', 'message' => 'Há ' . count($cands) . ' BoleCode(s) em aberto, mas a credencial Itaú não tem acesso à consulta de Pix (listagem HTTP ' . $listHttp . ', consulta do QR Code HTTP ' . $cobHttp . '). Peça ao Itaú os escopos de leitura de Pix (pix.read e cob.read) para a credencial do BoleCode. Pagamentos feitos pelo QR Code não puderam ser conferidos.' . ($cobMsg !== '' ? ' Retorno: ' . $cobMsg : ($listMsg !== '' ? ' Retorno: ' . $listMsg : ''))];
                return $out;
            }
        }

        foreach ($paid as $txid => $items) {
            $charge = $cands[$txid];
            $event = itau_pix_evento($charge, $items);
            $out['found']++;
            $out['amount'] = round($out['amount'] + (float)$event['valorPagoTotalCobranca'], 2);
            $nosso = (string)$charge['nosso_numero'];
            $diag = ['date' => $event['dataInclusaoPagamento'], 'payment_date' => $event['dataInclusaoPagamento'], 'credit_date' => $event['dataCredito'], 'credit_date_confirmed' => true, 'nosso_numero' => $nosso, 'seu_numero' => '', 'payer' => '', 'tipo_cobranca' => 'PIX (QR Code)', 'title_amount' => (float)$charge['amount'], 'gross_amount' => (float)$event['valorPagoTotalCobranca'], 'net_amount' => (float)$event['valorCreditado'], 'bank_fee_amount' => 0.0, 'bank_fee_description' => '', 'charge_id' => (int)$charge['id'], 'charge_status' => (string)$charge['status'], 'receivable_id' => (int)($charge['receivable_id'] ?? 0), 'receivable_status' => '', 'local_status' => 'LOCALIZADO'];
            if (!empty($event['_nexa_pix_devolvido'])) {
                $out['pending']++;
                $msg = 'Pix do BoleCode tem devolução registrada no Itaú; confira e baixe manualmente.';
                $out['details'][] = ['nosso_numero' => $nosso, 'status' => 'PIX_COM_DEVOLUCAO', 'message' => $msg];
                $out['diagnostic_rows'][] = $diag + ['processing_status' => 'PIX_COM_DEVOLUCAO', 'processing_message' => $msg];
                continue;
            }
            if ($queryOnly) {
                $out['details'][] = ['nosso_numero' => $nosso, 'status' => 'LOCALIZADO', 'message' => 'Consulta diagnóstica: Pix localizado; nenhuma baixa foi gerada.'];
                $out['diagnostic_rows'][] = $diag;
                continue;
            }
            $raw = json_encode(['boletos' => [$event]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            try {
                $stored = itau_webhook_store_event($pdo, $cfg, $event, $raw);
                $result = itau_webhook_process_event($pdo, $cfg, $event, $stored);
                $status = (string)($result['processing_status'] ?? $result['status'] ?? 'PENDENTE');
                if (!empty($result['processed'])) {
                    if (in_array((string)($result['status'] ?? ''), ['JA_PAGO', 'RECEBER_JA_BAIXADO'], true)) $out['already']++; else $out['processed']++;
                } elseif ($status === 'ERRO') $out['errors']++; else $out['pending']++;
                $out['details'][] = ['nosso_numero' => $nosso, 'status' => $status, 'message' => (string)($result['message'] ?? '')];
                $out['diagnostic_rows'][] = $diag + ['processing_status' => $status, 'processing_message' => (string)($result['message'] ?? '')];
            } catch (Throwable $e) {
                $out['errors']++;
                $out['details'][] = ['nosso_numero' => $nosso, 'status' => 'ERRO', 'message' => $e->getMessage()];
                $out['diagnostic_rows'][] = $diag + ['processing_status' => 'ERRO', 'processing_message' => $e->getMessage()];
            }
        }
        return $out;
    }
}
