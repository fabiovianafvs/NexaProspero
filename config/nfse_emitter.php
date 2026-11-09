<?php
/**
 * Emissor NFS-e Nacional (DPS) do Nexa Próspero.
 *
 * Escopo desta versão:
 * - preparação transacional e numeração da DPS por empresa/ambiente/série;
 * - montagem do XML DPS 1.01 sem prefixo de namespace;
 * - assinatura XMLDSig RSA-SHA256 com certificado A1;
 * - envio síncrono à Sefin Nacional com mTLS;
 * - recuperação por ID da DPS após timeout;
 * - consulta por chave e armazenamento dos XMLs.
 *
 * Produção é bloqueada por padrão e somente pode ser habilitada por variável
 * de ambiente NFSE_ENABLE_PRODUCTION=1, além da configuração fiscal da empresa.
 */
declare(strict_types=1);

require_once __DIR__ . '/nfse_nacional.php';
require_once __DIR__ . '/detailed_order_fiscal_notes.php';
require_once __DIR__ . '/fiscal_order_composition.php';

if (!function_exists('nfse_emitter_runtime_issues')) {
  function nfse_emitter_runtime_issues(bool $network = true): array {
    $issues = [];
    if (!class_exists('DOMDocument')) $issues[] = 'Extensão PHP DOM/XML não instalada.';
    if (!function_exists('openssl_pkcs12_read')) $issues[] = 'Extensão PHP OpenSSL não instalada.';
    if (!function_exists('gzencode') || !function_exists('gzdecode')) $issues[] = 'Extensão PHP zlib não instalada.';
    if ($network && !function_exists('curl_init')) $issues[] = 'Extensão PHP cURL não instalada.';
    $autoload = function_exists('fiscal_nfce_nfephp_autoload_path') ? fiscal_nfce_nfephp_autoload_path() : dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) $issues[] = 'Dependências Composer/NFePHP não encontradas.';
    return $issues;
  }
}

if (!function_exists('nfse_emitter_require_runtime')) {
  function nfse_emitter_require_runtime(bool $network = true): void {
    $issues = nfse_emitter_runtime_issues($network);
    if ($issues) throw new RuntimeException('Servidor sem os requisitos do emissor: ' . implode(' ', $issues));
  }
}

if (!function_exists('nfse_emitter_required_columns')) {
  function nfse_emitter_required_columns(): array {
    return [
      'fiscal_nfse_details' => [
        'pedido_id','status','environment','dps_xml','signed_dps_xml','response_xml',
        'request_payload','response_payload','request_hash','api_http_status',
        'api_error_code','api_error_message','attempts','last_attempt_at',
        'authorized_at','consulted_at'
      ],
    ];
  }
}

if (!function_exists('nfse_emitter_schema_issues')) {
  function nfse_emitter_schema_issues(PDO $pdo): array {
    $issues = [];
    foreach (['fiscal_documents','fiscal_document_series','fiscal_nfse_details','fiscal_document_participants','fiscal_document_items','fiscal_certificates','fiscal_company_config'] as $table) {
      if (!fiscal_table_exists($pdo, $table)) $issues[] = 'Tabela ausente: ' . $table;
    }
    foreach (nfse_emitter_required_columns() as $table => $columns) {
      if (!fiscal_table_exists($pdo, $table)) continue;
      foreach ($columns as $column) if (!fiscal_column_exists($pdo, $table, $column)) $issues[] = 'Coluna ausente: ' . $table . '.' . $column;
    }
    return $issues;
  }
}

if (!function_exists('nfse_emitter_require_schema')) {
  function nfse_emitter_require_schema(PDO $pdo): void {
    $issues = nfse_emitter_schema_issues($pdo);
    if ($issues) {
      throw new RuntimeException('Execute o patch SQL do Emissor NFS-e Nacional antes de utilizar esta função. ' . implode(' | ', $issues));
    }
  }
}

if (!function_exists('nfse_emitter_csrf_token')) {
  function nfse_emitter_csrf_token(string $scope = 'nfse_emitter'): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $key = 'csrf_' . preg_replace('/[^a-z0-9_]/i', '_', $scope);
    if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(24));
    return (string)$_SESSION[$key];
  }
}

if (!function_exists('nfse_emitter_csrf_check')) {
  function nfse_emitter_csrf_check(string $token, string $scope = 'nfse_emitter'): void {
    if ($token === '' || !hash_equals(nfse_emitter_csrf_token($scope), $token)) {
      throw new RuntimeException('Sessão expirada ou formulário inválido. Atualize a tela e tente novamente.');
    }
  }
}

if (!function_exists('nfse_emitter_env_flag')) {
  function nfse_emitter_env_flag(string $name, bool $default = false): bool {
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') return $default;
    return in_array(strtolower(trim((string)$value)), ['1','true','yes','sim','on'], true);
  }
}

if (!function_exists('nfse_emitter_production_enabled')) {
  function nfse_emitter_production_enabled(): bool {
    return nfse_emitter_env_flag('NFSE_ENABLE_PRODUCTION', false);
  }
}

if (!function_exists('nfse_emitter_api_base')) {
  function nfse_emitter_api_base(string $environment): string {
    $environment = strtoupper(trim($environment));
    if ($environment === 'PRODUCAO') {
      $custom = trim((string)(getenv('NFSE_API_BASE_PRODUCAO') ?: ''));
      return rtrim($custom !== '' ? $custom : 'https://sefin.nfse.gov.br/SefinNacional', '/');
    }
    $custom = trim((string)(getenv('NFSE_API_BASE_HOMOLOGACAO') ?: ''));
    return rtrim($custom !== '' ? $custom : 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional', '/');
  }
}

if (!function_exists('nfse_emitter_value')) {
  function nfse_emitter_value(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
      if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') return $row[$key];
    }
    return $default;
  }
}

if (!function_exists('nfse_emitter_timezone')) {
  /**
   * Fuso usado nas datas da DPS. Sempre um fuso do Brasil: quando a emissão roda
   * fora da tela (fila da O.S., cron) o PHP pode estar em UTC, e a Sefin Nacional
   * rejeita dhEmi com "+00:00" como se fosse horário futuro (E0008).
   */
  function nfse_emitter_timezone(): DateTimeZone {
    $name = (string)date_default_timezone_get();
    static $brazil = null;
    if ($brazil === null) {
      try { $brazil = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR'); } catch (Throwable $e) { $brazil = []; }
    }
    if (!in_array($name, $brazil, true)) $name = 'America/Sao_Paulo';
    return new DateTimeZone($name);
  }
}

if (!function_exists('nfse_emitter_digits')) {
  function nfse_emitter_digits($value): string {
    return preg_replace('/\D+/', '', (string)$value) ?: '';
  }
}

if (!function_exists('nfse_emitter_text')) {
  function nfse_emitter_text($value, int $max = 255): string {
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
    return substr($text, 0, $max);
  }
}

if (!function_exists('nfse_emitter_money')) {
  function nfse_emitter_money($value): string {
    return number_format(round((float)$value, 2), 2, '.', '');
  }
}

if (!function_exists('nfse_emitter_percent')) {
  function nfse_emitter_percent($value): string {
    $number = round((float)$value, 2);
    return number_format($number, 2, '.', '');
  }
}

if (!function_exists('nfse_emitter_code_from_label')) {
  function nfse_emitter_code_from_label($value, array $map, string $default = ''): string {
    $raw = trim((string)$value);
    if ($raw === '') return $default;
    if (preg_match('/^\s*(\d{1,3})\b/u', $raw, $m)) return (string)(int)$m[1];
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    if (function_exists('iconv')) {
      $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
      if ($ascii !== false) $normalized = strtolower($ascii);
    }
    foreach ($map as $needle => $code) {
      if (strpos($normalized, strtolower($needle)) !== false) return (string)$code;
    }
    return $default;
  }
}

if (!function_exists('nfse_emitter_simple_status')) {
  function nfse_emitter_simple_status(array $cfg): string {
    $crt = nfse_emitter_digits($cfg['crt'] ?? '');
    if ($crt === '4') return '2'; // MEI
    if ($crt === '1' || $crt === '2') return '3'; // ME/EPP
    return '1'; // não optante
  }
}

if (!function_exists('nfse_emitter_sn_total_tax_rate')) {
  /**
   * % aproximado do total de tributos da alíquota do Simples Nacional (pTotTribSN),
   * exigido pelo Sistema Nacional para ME/EPP (rejeição E0712 com indTotTrib).
   * Guardado por empresa em app_config (cfg_key 'nfse_nacional'), sem DDL.
   */
  function nfse_emitter_sn_total_tax_rate(PDO $pdo, int $companyId): float {
    try {
      require_once __DIR__ . '/config_store.php';
      $saved = config_store_get($pdo, $companyId, 'nfse_nacional');
      return max(0.0, min(100.0, round((float)($saved['sn_total_tax_rate'] ?? 0), 2)));
    } catch (Throwable $e) {
      return 0.0;
    }
  }
}

if (!function_exists('nfse_emitter_simple_regime')) {
  function nfse_emitter_simple_regime(array $tax, string $simpleStatus): string {
    if ($simpleStatus !== '3') return '';
    return nfse_emitter_code_from_label($tax['nfse_simples_tax_regime'] ?? '', [
      'federais e municipal pelo simples' => '1',
      'federais e municipal pelo sn' => '1',
      'federais pelo simples' => '2',
      'federais pelo sn' => '2',
      'legislacoes federal e municipal' => '3',
      'legislacao federal e municipal' => '3',
    ], '1');
  }
}

if (!function_exists('nfse_emitter_special_regime')) {
  function nfse_emitter_special_regime(array $tax): string {
    return nfse_emitter_code_from_label($tax['nfse_rtc_special_regime'] ?? $tax['nfse_municipal_special_regime'] ?? '', [
      'nenhum' => '0', 'normal' => '0', 'cooper' => '1', 'estimativa' => '2',
      'microempresa municipal' => '3', 'notar' => '4', 'registrador' => '4',
      'autonom' => '5', 'sociedade de profissionais' => '6', 'outros' => '9',
    ], '0');
  }
}

if (!function_exists('nfse_emitter_iss_taxation')) {
  function nfse_emitter_iss_taxation(array $tax): string {
    return nfse_emitter_code_from_label($tax['nfse_national_tax_type'] ?? $tax['nfse_municipal_tax_type'] ?? '', [
      'operacao tributavel' => '1', 'tributavel' => '1', 'imunidade' => '2',
      'exportacao' => '3', 'nao incidencia' => '4', 'sem incidencia' => '4',
    ], '1');
  }
}

if (!function_exists('nfse_emitter_operation_type')) {
  function nfse_emitter_operation_type(array $tax): string {
    return nfse_emitter_code_from_label($tax['nfse_operation_payment_type'] ?? '', [
      'fornecimento com pagamento posterior' => '1',
      'recebimento do pagamento com fornecimento ja realizado' => '2',
      'fornecimento com pagamento ja realizado' => '3',
      'recebimento do pagamento com fornecimento posterior' => '4',
      'concomitante' => '5',
    ], '');
  }
}

if (!function_exists('nfse_emitter_order_context')) {
  function nfse_emitter_order_context(PDO $pdo, int $companyId, int $orderId): array {
    fiscal_ensure_schema($pdo);
    if (!fiscal_table_exists($pdo, 'pedidos') || !fiscal_table_exists($pdo, 'pedido_itens')) {
      throw new RuntimeException('Estrutura de pedidos não encontrada.');
    }

    $base = fiscal_nfce_order_data($pdo, $companyId, $orderId);
    $order = $base['order'];
    $orderSource = strtolower(trim((string)($order['source'] ?? '')));
    // A conferência utiliza o mesmo editor do Pedido Detalhado, mas preserva
    // a origem comercial da O.S. para evitar baixa/financeiro duplicados.
    $isDetailedOrder = in_array($orderSource, ['pedido_detalhado','ordem_servico','ordem_servico_lote','ordem_servico_fiscal_tmp'], true);
    $orderFiscalNote = $isDetailedOrder ? detailed_order_fiscal_observation($order, false) : '';
    // O identificador de conciliação da O.S. é interno e não deve sair no XML.
    if (in_array($orderSource, ['ordem_servico','ordem_servico_lote','ordem_servico_fiscal_tmp'], true))
      $orderFiscalNote = trim(preg_replace('/\[NEXA-OS-(?:BILL|BATCH):\d+:\d+\]/', '', $orderFiscalNote) ?? $orderFiscalNote);

    // Complementa cidade IBGE e e-mail do tomador quando disponíveis no cadastro financeiro.
    try {
      $personId = (int)nfse_emitter_value($order, ['customer_id','finance_person_id'], 0);
      if ($personId > 0 && fiscal_table_exists($pdo, 'finance_people')) {
        $stPerson = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
        $stPerson->execute([$companyId, $personId]);
        $person = $stPerson->fetch(PDO::FETCH_ASSOC) ?: [];
        $personMap = [
          'customer_document'=>['document','cpf_cnpj'], 'nome_cliente'=>['name','nome'],
          'email'=>['email'], 'whatsapp'=>['whatsapp','phone'], 'zip_code'=>['zip_code','cep'],
          'endereco'=>['address','endereco'], 'numero'=>['number','numero'], 'complemento'=>['complement','complemento'],
          'bairro'=>['district','bairro'], 'cidade'=>['city','cidade'], 'city_ibge'=>['city_ibge','codigo_ibge'],
          'uf'=>['state','uf'], 'municipal_registration'=>['municipal_registration','inscricao_municipal']
        ];
        // Endereço do tomador: o cadastro de Pessoas é a fonte oficial. O pedido
        // guarda uma cópia do endereço do dia em que foi feito; se o cadastro foi
        // corrigido depois (ex.: CEP geral 33200-000 trocado pelo CEP da rua), a
        // DPS continuava saindo com o endereço antigo do pedido. Quando o cadastro
        // tem endereço completo (CEP + município IBGE), usa o bloco inteiro dele,
        // sem misturar com o do pedido (CEP de uma cidade e município de outra).
        $personZip = nfse_emitter_digits(nfse_emitter_value($person, ['zip_code','cep'], ''));
        $personIbge = nfse_emitter_digits(nfse_emitter_value($person, ['city_ibge','codigo_ibge'], ''));
        if (strlen($personZip) === 8 && strlen($personIbge) === 7) {
          $addressAliases = [
            'zip_code'=>['cep'], 'endereco'=>['address','logradouro'], 'numero'=>['number'],
            'complemento'=>['complement'], 'bairro'=>['district'], 'cidade'=>['city'],
            'city_ibge'=>['codigo_ibge'], 'uf'=>['state'],
          ];
          foreach ($addressAliases as $target => $aliases) {
            foreach ($aliases as $alias) unset($order[$alias]);
            $order[$target] = (string)nfse_emitter_value($person, $personMap[$target], '');
          }
        }
        foreach ($personMap as $target => $sources) {
          if (trim((string)($order[$target] ?? '')) !== '') continue;
          $value = nfse_emitter_value($person, $sources, '');
          if ($value !== '') $order[$target] = $value;
        }
      }
    } catch (Throwable $e) {}

    $sql = "SELECT pi.*, p.nome AS product_name, p.descricao AS product_description,
                   COALESCE(p.is_service,0) AS is_service,
                   fpt.*, tp.name AS tax_profile_name, tp.profile_type AS tax_profile_type,
                   tp.is_active AS tax_profile_active
              FROM pedido_itens pi
         LEFT JOIN produtos p ON p.company_id=pi.company_id AND p.id=pi.produto_id
         LEFT JOIN fiscal_product_tax fpt ON fpt.company_id=pi.company_id AND fpt.product_id=pi.produto_id
         LEFT JOIN fiscal_tax_profiles tp ON tp.company_id=pi.company_id AND tp.id=fpt.tax_profile_id
             WHERE pi.company_id=? AND pi.pedido_id=?
          ORDER BY pi.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $orderId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$items) throw new RuntimeException('Pedido sem itens para emissão da NFS-e.');

    $issues = [];
    $gross = 0.0;
    $allGross = 0.0;
    $descriptions = [];
    $identity = null;
    $serviceItems = [];
    foreach ($items as $sourceItem) {
      $qtyAll = (float)nfse_emitter_value($sourceItem, ['qtd','quantidade','quantity'], 1);
      $unitAll = (float)nfse_emitter_value($sourceItem, ['preco_unit','preco_unitario','unit_value','valor_unitario'], 0);
      $lineAll = (float)nfse_emitter_value($sourceItem, ['total','valor_total','total_value'], $qtyAll * $unitAll);
      if ($lineAll <= 0 && $qtyAll > 0 && $unitAll > 0) $lineAll = $qtyAll * $unitAll;
      $allGross += round($lineAll, 2);
      if (fiscal_order_item_is_service($sourceItem['is_service'] ?? 0, $sourceItem['item_type'] ?? 'PRODUCT')) {
        $serviceItems[] = $sourceItem;
      }
    }
    $allGross = round($allGross, 2);
    if (!$serviceItems) $issues[] = 'O pedido não possui itens de serviço para emissão da NFS-e.';

    foreach ($serviceItems as $index => $item) {
      $name = nfse_emitter_text($item['product_name'] ?? ('Serviço #' . ($item['produto_id'] ?? '')), 255);
      $itemIssues = nfse_nacional_service_product_issues($item);
      foreach ($itemIssues as $issue) $issues[] = $name . ': ' . $issue . '.';

      $qty = (float)nfse_emitter_value($item, ['qtd','quantidade','quantity'], 1);
      $unit = (float)nfse_emitter_value($item, ['preco_unit','preco_unitario','unit_value','valor_unitario'], 0);
      $lineTotal = (float)nfse_emitter_value($item, ['total','valor_total','total_value'], $qty * $unit);
      if ($lineTotal <= 0 && $qty > 0 && $unit > 0) $lineTotal = $qty * $unit;
      $gross += round($lineTotal, 2);
      $serviceLine = ($qty > 0 ? rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') . ' x ' : '') . $name;
      if ($isDetailedOrder) {
        $itemNote = detailed_order_fiscal_observation($item);
        if ($itemNote !== '') $serviceLine .= ' — Obs. do item: ' . $itemNote;
      }
      $descriptions[] = $serviceLine;

      $currentIdentity = [
        'cTribNac'=>str_pad(substr(nfse_emitter_digits($item['nfse_national_tax_code'] ?? ''), 0, 6), 6, '0', STR_PAD_LEFT),
        'cTribMun'=>substr(nfse_emitter_digits($item['municipal_tax_code'] ?? ''), 0, 3),
        'cNBS'=>substr(nfse_emitter_digits($item['nbs_code'] ?? ''), 0, 9),
        'cIntContrib'=>substr(preg_replace('/[^a-zA-Z0-9]+/', '', (string)($item['cnae'] ?? '')) ?: '', 0, 20),
        'issRate'=>round((float)($item['iss_rate'] ?? 0), 4),
        'issWithheld'=>(int)($item['iss_withheld'] ?? 0),
        'pisRetention'=>(string)($item['pis_cofins_retention_type'] ?? ''),
        'irrfRate'=>round((float)($item['irrf_rate'] ?? 0), 4),
        'csllRate'=>round((float)($item['csll_rate'] ?? 0), 4),
        'inssRate'=>round((float)($item['inss_rate'] ?? 0), 4),
        'cIndOp'=>substr(nfse_emitter_digits($item['nfse_national_operation_indicator'] ?? ''), 0, 6),
        'ibsCbsCst'=>str_pad(substr(nfse_emitter_digits($item['ibs_cbs_cst'] ?? ''), 0, 3), 3, '0', STR_PAD_LEFT),
        'classCode'=>substr(nfse_emitter_digits($item['ibs_cbs_classification_code'] ?? ''), 0, 6),
      ];
      if ($identity === null) $identity = $currentIdentity;
      elseif ($identity !== $currentIdentity) {
        $issues[] = 'O pedido reúne serviços com códigos ou regras tributárias diferentes. Separe-os em pedidos distintos para gerar uma DPS por tributação.';
      }
    }

    $gross = round($gross, 2);
    $orderTotal = (float)nfse_emitter_value($order, ['total','valor_total','total_geral','grand_total'], $gross);
    $orderDiscount = (float)nfse_emitter_value($order, ['discount_amount','desconto','valor_desconto','discount'], 0);
    if ($orderDiscount <= 0 && $orderTotal > 0 && $allGross > 0) {
      $orderOther = (float)nfse_emitter_value($order, ['addition_amount'], 0)
        + (float)nfse_emitter_value($order, ['taxa_entrega'], 0);
      $orderDiscount = max(0.0, round($allGross + $orderOther - $orderTotal, 2));
    }
    $isMixedOrder = $gross > 0 && $allGross > $gross + 0.004;
    $discount = $orderDiscount;
    if ($isMixedOrder && $orderDiscount > 0 && $allGross > 0) {
      $discount = round($orderDiscount * ($gross / $allGross), 2);
    } elseif (!$isMixedOrder && $discount <= 0 && $orderTotal > 0 && $gross > $orderTotal) {
      $discount = round($gross - $orderTotal, 2);
    }
    $discount = max(0.0, min($gross, round($discount, 2)));
    if ($gross <= 0) $issues[] = 'O valor bruto dos serviços deve ser maior que zero.';

    $tax = $serviceItems[0] ?? [];
    $cfg = fiscal_company_config($pdo, $companyId);
    $cfg['nfse_sn_total_tax_rate'] = nfse_emitter_sn_total_tax_rate($pdo, $companyId);
    $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';

    $companyCnpj = nfse_emitter_digits($cfg['company_cnpj'] ?? '');
    $cityIbge = nfse_emitter_digits($cfg['city_ibge'] ?? '');
    if (strlen($companyCnpj) !== 14) $issues[] = 'CNPJ do prestador inválido.';
    if (strlen($cityIbge) !== 7) $issues[] = 'Código IBGE do prestador inválido.';
    // Ambiente Nacional: a IM é opcional e NÃO deve ser enviada quando o município
    // não tem informações complementares no CNC NFS-e (rejeição E0120). Quando vazia,
    // a tag <IM> é omitida da DPS. O Pronim/GOVBR continua exigindo a inscrição.
    $providerRaw = strtoupper(trim((string)($cfg['nfse_provider'] ?? '')));
    $isPronimProvider = $providerRaw !== '' && (str_contains($providerRaw, 'PRONIM') || str_contains($providerRaw, 'GOVBR') || str_contains($providerRaw, 'ISS'));
    if ($isPronimProvider && trim((string)($cfg['municipal_registration'] ?? '')) === '') $issues[] = 'Inscrição municipal do prestador não informada.';
    if (!$isPronimProvider && nfse_emitter_simple_status($cfg) === '3' && (float)$cfg['nfse_sn_total_tax_rate'] <= 0) {
      $issues[] = 'Informe o "% aproximado de tributos do Simples Nacional" em Configurações fiscais (bloco NFC-e e NFS-e). O Portal Nacional exige este percentual para ME/EPP.';
    }

    $tomadorDocument = nfse_emitter_digits(nfse_emitter_value($order, ['customer_document','document','cpf_cnpj'], ''));
    $tomadorName = nfse_emitter_text(nfse_emitter_value($order, ['nome_cliente','customer_name','cliente_nome'], ''), 150);
    if ($tomadorDocument !== '' && !in_array(strlen($tomadorDocument), [11,14], true)) $issues[] = 'CPF/CNPJ do tomador inválido.';
    if ($tomadorDocument !== '' && $tomadorName === '') $issues[] = 'Informe o nome/razão social do tomador.';

    $tomador = [
      'document'=>$tomadorDocument,
      'name'=>$tomadorName,
      'municipal_registration'=>nfse_emitter_text(nfse_emitter_value($order, ['municipal_registration','inscricao_municipal'], ''), 30),
      'email'=>nfse_emitter_text(nfse_emitter_value($order, ['email','customer_email'], ''), 80),
      'phone'=>substr(nfse_emitter_digits(nfse_emitter_value($order, ['whatsapp','phone','telefone'], '')), 0, 20),
      'zip'=>substr(nfse_emitter_digits(nfse_emitter_value($order, ['zip_code','cep'], '')), 0, 8),
      'address'=>nfse_emitter_text(nfse_emitter_value($order, ['endereco','address','logradouro'], ''), 255),
      'number'=>nfse_emitter_text(nfse_emitter_value($order, ['numero','number'], 'S/N'), 60),
      'complement'=>nfse_emitter_text(nfse_emitter_value($order, ['complemento','complement'], ''), 156),
      'district'=>nfse_emitter_text(nfse_emitter_value($order, ['bairro','district'], ''), 60),
      'city'=>nfse_emitter_text(nfse_emitter_value($order, ['cidade','city'], ''), 120),
      'city_ibge'=>substr(nfse_emitter_digits(nfse_emitter_value($order, ['city_ibge','codigo_ibge'], '')), 0, 7),
      'state'=>strtoupper(substr(nfse_emitter_text(nfse_emitter_value($order, ['uf','state'], ''), 2), 0, 2)),
    ];

    // xDescServ é o campo principal exibido pelos dois provedores, inclusive Pronim,
    // que não utiliza infoCompl no XML municipal desta implementação.
    $serviceDescriptionFull = implode(' | ', $descriptions) . ($orderFiscalNote !== '' ? ' | Observação do pedido: ' . $orderFiscalNote : '');
    if ($isDetailedOrder && ($orderFiscalNote !== '' || array_reduce($serviceItems, static fn(bool $has, array $it): bool => $has || detailed_order_fiscal_observation($it) !== '', false))) {
      $descriptionLength = function_exists('mb_strlen') ? mb_strlen($serviceDescriptionFull, 'UTF-8') : strlen($serviceDescriptionFull);
      if ($descriptionLength > 1000) $issues[] = 'A descrição dos serviços e as observações ultrapassam o limite de 1000 caracteres da NFS-e. Reduza as observações antes de transmitir.';
    }

    return [
      'order'=>$order, 'items'=>$items, 'tax'=>$tax, 'config'=>$cfg,
      'environment'=>$environment, 'issues'=>array_values(array_unique($issues)),
      'gross'=>$gross, 'discount'=>$discount, 'net'=>round($gross - $discount, 2),
      // Pedido Detalhado e pedidos de O.S. conferidos acrescentam observações; PDV comum permanece inalterado.
      'description'=>nfse_emitter_text($serviceDescriptionFull, 1000),
      'tomador'=>$tomador,
      'service_city_ibge'=>strlen($tomador['city_ibge']) === 7 ? $tomador['city_ibge'] : $cityIbge,
    ];
  }
}

if (!function_exists('nfse_emitter_existing_document')) {
  function nfse_emitter_existing_document(PDO $pdo, int $companyId, int $orderId): ?array {
    nfse_emitter_require_schema($pdo);
    $sql = "SELECT d.*, nd.pedido_id, nd.dps_series, nd.dps_number, nd.dps_id, nd.nfse_id,
                   nd.nfse_number, nd.verification_code, nd.status AS nfse_status,
                   nd.dps_xml, nd.signed_dps_xml, nd.response_xml, nd.api_http_status,
                   nd.api_error_code, nd.api_error_message, nd.attempts, nd.last_attempt_at,
                   nd.authorized_at, nd.consulted_at
              FROM fiscal_documents d
         LEFT JOIN fiscal_nfse_details nd ON nd.fiscal_document_id=d.id AND nd.company_id=d.company_id
             WHERE d.company_id=? AND d.model='NFSE' AND d.direction='SAIDA'
               AND d.source_type='PEDIDO' AND d.source_id=?
          ORDER BY d.id DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)($row['pedido_id'] ?? 0) === $orderId) return $row;

    // Compatibilidade com documentos legados cujos vínculos source_id e pedido_id
    // não coincidem. A constraint ux_nfse_details_order utiliza pedido_id.
    // Nunca criar uma segunda DPS por deixar de procurar por esse vínculo.
    $fallbackSql = "SELECT d.*, nd.pedido_id, nd.dps_series, nd.dps_number, nd.dps_id,
                           nd.nfse_id, nd.nfse_number, nd.verification_code,
                           nd.status AS nfse_status, nd.dps_xml, nd.signed_dps_xml,
                           nd.response_xml, nd.api_http_status, nd.api_error_code,
                           nd.api_error_message, nd.attempts, nd.last_attempt_at,
                           nd.authorized_at, nd.consulted_at
                      FROM fiscal_nfse_details nd
                      JOIN fiscal_documents d ON d.id=nd.fiscal_document_id AND d.company_id=nd.company_id
                     WHERE nd.company_id=? AND nd.pedido_id=? AND d.model='NFSE'
                       AND d.direction='SAIDA' ORDER BY d.id DESC LIMIT 1";
    $fallback = $pdo->prepare($fallbackSql);
    $fallback->execute([$companyId, $orderId]);
    $detailLinked = $fallback->fetch(PDO::FETCH_ASSOC);
    if ($detailLinked) {
      if ($row && (int)$row['id'] !== (int)$detailLinked['id']) {
        throw new RuntimeException('Vínculos fiscais divergentes para este pedido. Consulte a auditoria antes de emitir outra DPS.');
      }
      return $detailLinked;
    }
    // Um documento principal sem detalhe válido NÃO deve gerar outra reserva.
    if ($row) return $row;
    return null;
  }
}

if (!function_exists('nfse_emitter_document_by_id')) {
  function nfse_emitter_document_by_id(PDO $pdo, int $companyId, int $docId): ?array {
    nfse_emitter_require_schema($pdo);
    $sql = "SELECT d.*, nd.pedido_id, nd.dps_series, nd.dps_number, nd.dps_id, nd.nfse_id,
                   nd.nfse_number, nd.verification_code, nd.status AS nfse_status,
                   nd.dps_xml, nd.signed_dps_xml, nd.response_xml, nd.request_payload,
                   nd.response_payload, nd.api_http_status, nd.api_error_code,
                   nd.api_error_message, nd.attempts, nd.last_attempt_at,
                   nd.authorized_at, nd.consulted_at
              FROM fiscal_documents d
         LEFT JOIN fiscal_nfse_details nd ON nd.fiscal_document_id=d.id AND nd.company_id=d.company_id
             WHERE d.company_id=? AND d.id=? AND d.model='NFSE' LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $docId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('nfse_emitter_reserve_document')) {
  function nfse_emitter_reserve_document(PDO $pdo, int $companyId, int $orderId, array $context): array {
    nfse_emitter_require_schema($pdo);
    $existing = nfse_emitter_existing_document($pdo, $companyId, $orderId);
    if ($existing) return $existing;

    $environment = (string)$context['environment'];
    $series = nfse_nacional_active_series($pdo, $companyId, $environment);
    if (!$series) throw new RuntimeException('Cadastre uma série DPS/NFS-e ativa para o ambiente ' . $environment . '.');

    $pdo->beginTransaction();
    try {
      $lockExisting = $pdo->prepare("SELECT d.id FROM fiscal_documents d WHERE d.company_id=? AND d.model='NFSE' AND d.direction='SAIDA' AND d.source_type='PEDIDO' AND d.source_id=? ORDER BY d.id DESC LIMIT 1 FOR UPDATE");
      $lockExisting->execute([$companyId, $orderId]);
      $existingId = (int)($lockExisting->fetchColumn() ?: 0);
      if ($existingId > 0) {
        $pdo->commit();
        $existing = nfse_emitter_document_by_id($pdo, $companyId, $existingId);
        if (!$existing) throw new RuntimeException('Documento NFS-e existente não pôde ser carregado.');
        return $existing;
      }

      $lockSeries = $pdo->prepare("SELECT * FROM fiscal_document_series WHERE id=? AND company_id=? AND is_active=1 AND environment=? FOR UPDATE");
      $lockSeries->execute([(int)$series['id'], $companyId, $environment]);
      $locked = $lockSeries->fetch(PDO::FETCH_ASSOC);
      if (!$locked) throw new RuntimeException('A série DPS selecionada não está mais disponível.');
      $number = max(1, (int)($locked['next_number'] ?? 1));
      $seriesNumber = (string)$locked['series'];
      $seriesDigits = nfse_emitter_digits($seriesNumber);
      $seriesInt = $seriesDigits !== '' ? (int)$seriesDigits : 0;
      if ($seriesDigits === '' || $seriesInt < 1 || $seriesInt > 49999) {
        throw new RuntimeException('A série DPS do emissor próprio deve ser numérica e estar entre 00001 e 49999. Ajuste a série fiscal antes de emitir.');
      }
      $seriesNumber = str_pad((string)$seriesInt, 5, '0', STR_PAD_LEFT);

      $insDoc = $pdo->prepare("INSERT INTO fiscal_documents
        (company_id,direction,model,series,number,status,environment,operation_nature,issue_datetime,competence_date,total_products,total_discount,total_iss,total_document,source_type,source_id,notes,created_at,updated_at)
        VALUES (?,?,?,?,?,'PREPARADA',?,'Prestação de serviços',NOW(),?,?,?,?,?,'PEDIDO',?,'DPS preparada pelo Nexa Próspero',NOW(),NOW())");
      $competence = (new DateTimeImmutable('now', nfse_emitter_timezone()))->format('Y-m-d');
      $orderDate = (string)nfse_emitter_value($context['order'], ['data','data_pedido','created_at'], '');
      if ($orderDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $orderDate, $m)) $competence = $m[0];
      $issAmount = round((float)$context['net'] * ((float)($context['tax']['iss_rate'] ?? 0) / 100), 2);
      $insDoc->execute([$companyId,'SAIDA','NFSE',$seriesNumber,$number,$environment,$competence,$context['gross'],$context['discount'],$issAmount,$context['net'],$orderId]);
      $docId = (int)$pdo->lastInsertId();

      $insDetail = $pdo->prepare("INSERT INTO fiscal_nfse_details
        (fiscal_document_id,company_id,pedido_id,dps_series,dps_number,status,environment,service_city_ibge,incidence_city_ibge,national_service_code,municipal_service_code,nbs_code,service_description,iss_rate,iss_amount,iss_withheld,ibs_cbs_cst,ibs_cbs_class_code,operation_indicator,created_at)
        VALUES (?,?,?,?,?,'PREPARADA',?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
      $tax = $context['tax'];
      $insDetail->execute([
        $docId,$companyId,$orderId,$seriesNumber,$number,$environment,
        $context['service_city_ibge'], $context['service_city_ibge'],
        substr(nfse_emitter_digits($tax['nfse_national_tax_code'] ?? ''),0,6),
        substr(nfse_emitter_digits($tax['municipal_tax_code'] ?? ''),0,3),
        substr(nfse_emitter_digits($tax['nbs_code'] ?? ''),0,9),
        $context['description'], (float)($tax['iss_rate'] ?? 0), $issAmount,
        !empty($tax['iss_withheld']) ? 1 : 0,
        substr(nfse_emitter_digits($tax['ibs_cbs_cst'] ?? ''),0,3),
        substr(nfse_emitter_digits($tax['ibs_cbs_classification_code'] ?? ''),0,6),
        substr(nfse_emitter_digits($tax['nfse_national_operation_indicator'] ?? ''),0,6),
      ]);

      $part = $pdo->prepare("INSERT INTO fiscal_document_participants
        (fiscal_document_id,company_id,role,person_id,document,state_registration,municipal_registration,name,email,phone,zip_code,address,number,complement,district,city,city_ibge,state,country_code,country_name,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,NOW())");
      $cfg = $context['config'];
      $part->execute([$docId,$companyId,'PRESTADOR',null,nfse_emitter_digits($cfg['company_cnpj'] ?? ''),(string)($cfg['state_registration'] ?? ''),(string)($cfg['municipal_registration'] ?? ''),(string)($cfg['company_name'] ?? ''),'','','','','','','','',(string)($cfg['city_ibge'] ?? ''),(string)($cfg['uf'] ?? ''),'1058','BRASIL']);
      $tom = $context['tomador'];
      if ($tom['document'] !== '') {
        $part->execute([$docId,$companyId,'TOMADOR',(int)nfse_emitter_value($context['order'],['customer_id','finance_person_id'],0),$tom['document'],'',$tom['municipal_registration'],$tom['name'],$tom['email'],$tom['phone'],$tom['zip'],$tom['address'],$tom['number'],$tom['complement'],$tom['district'],$tom['city'],$tom['city_ibge'],$tom['state'],'1058','BRASIL']);
      }

      $itemIns = $pdo->prepare("INSERT INTO fiscal_document_items
        (fiscal_document_id,company_id,item_number,product_id,product_code,description,unidade,quantity,unit_value,total_value,unidade_trib,quantity_trib,unit_value_trib,discount_value,ind_total,order_item_id,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
      foreach ($context['items'] as $i => $item) {
        $qty = (float)nfse_emitter_value($item,['qtd','quantidade','quantity'],1);
        $unit = (float)nfse_emitter_value($item,['preco_unit','preco_unitario','unit_value'],0);
        $line = (float)nfse_emitter_value($item,['total','valor_total','total_value'],$qty*$unit);
        $itemIns->execute([$docId,$companyId,$i+1,(int)($item['produto_id'] ?? 0),(string)($item['produto_id'] ?? ''),nfse_emitter_text($item['product_name'] ?? 'Serviço',255),'UN',$qty,$unit,$line,'UN',$qty,$unit,0,'1',(int)($item['id'] ?? 0)]);
      }

      $upSeries = $pdo->prepare('UPDATE fiscal_document_series SET last_number=?, next_number=?, updated_at=NOW() WHERE company_id=? AND id=?');
      $upSeries->execute([$number,$number+1,$companyId,(int)$locked['id']]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        $concurrent = nfse_emitter_existing_document($pdo, $companyId, $orderId);
        if ($concurrent) return $concurrent;
      }
      throw $e;
    }

    $doc = nfse_emitter_document_by_id($pdo, $companyId, $docId);
    if (!$doc) throw new RuntimeException('A DPS foi reservada, mas não pôde ser carregada.');
    return $doc;
  }
}

if (!function_exists('nfse_emitter_dps_id')) {
  function nfse_emitter_dps_id(array $cfg, string $series, int $number): string {
    $city = str_pad(substr(nfse_emitter_digits($cfg['city_ibge'] ?? ''),0,7),7,'0',STR_PAD_LEFT);
    $document = nfse_emitter_digits($cfg['company_cnpj'] ?? '');
    $type = strlen($document) === 11 ? '1' : '2';
    $federal = str_pad(substr($document,0,14),14,'0',STR_PAD_LEFT);
    $serie = str_pad(substr(nfse_emitter_digits($series),0,5),5,'0',STR_PAD_LEFT);
    $dpsNumber = str_pad((string)$number,15,'0',STR_PAD_LEFT);
    return 'DPS' . $city . $type . $federal . $serie . $dpsNumber;
  }
}

if (!function_exists('nfse_emitter_xml_element')) {
  function nfse_emitter_xml_element(DOMDocument $dom, string $name): DOMElement {
    return $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', $name);
  }
}

if (!function_exists('nfse_emitter_xml_add')) {
  function nfse_emitter_xml_add(DOMDocument $dom, DOMElement $parent, string $name, $value): DOMElement {
    $node = nfse_emitter_xml_element($dom, $name);
    $node->appendChild($dom->createTextNode((string)$value));
    $parent->appendChild($node);
    return $node;
  }
}

if (!function_exists('nfse_emitter_build_dps_xml')) {
  function nfse_emitter_build_dps_xml(array $context, array $doc): string {
    nfse_emitter_require_runtime(false);
    $cfg = $context['config'];
    $tax = $context['tax'];
    $environment = (string)$context['environment'];
    if ($environment === 'PRODUCAO' && !nfse_emitter_production_enabled()) {
      throw new RuntimeException('A emissão em produção está bloqueada. Homologue primeiro e defina NFSE_ENABLE_PRODUCTION=1 somente após a liberação formal.');
    }

    $series = (string)($doc['dps_series'] ?? $doc['series'] ?? '1');
    $number = (int)($doc['dps_number'] ?? $doc['number'] ?? 0);
    if ($number <= 0) throw new RuntimeException('Número da DPS inválido.');
    $dpsId = nfse_emitter_dps_id($cfg, $series, $number);

    $dom = new DOMDocument('1.0','UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $root = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse','DPS');
    $root->setAttribute('versao','1.01');
    $dom->appendChild($root);
    $inf = nfse_emitter_xml_element($dom,'infDPS');
    $inf->setAttribute('Id',$dpsId);
    $root->appendChild($inf);

    nfse_emitter_xml_add($dom,$inf,'tpAmb',$environment === 'PRODUCAO' ? '1' : '2');
    $timezone = nfse_emitter_timezone();
    $realNow = new DateTimeImmutable('now',$timezone);
    // Rejeição "data de emissão da DPS não pode ser posterior à data do seu
    // processamento": basta o relógio do servidor estar alguns segundos à frente
    // do relógio da Receita. Emite com 2 minutos de folga, sem ficar antes do dia
    // de competência (dCompet não pode ser posterior à data de emissão).
    $now = $realNow->modify('-2 minutes');
    $competenceDay = substr((string)($doc['competence_date'] ?? ''), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $competenceDay) && $now->format('Y-m-d') < $competenceDay && $realNow->format('Y-m-d') >= $competenceDay) {
      $now = new DateTimeImmutable($competenceDay . ' 00:00:00', $timezone);
    }
    nfse_emitter_xml_add($dom,$inf,'dhEmi',$now->format('Y-m-d\TH:i:sP'));
    nfse_emitter_xml_add($dom,$inf,'verAplic','NEXA-1.01');
    nfse_emitter_xml_add($dom,$inf,'serie',(string)(int)nfse_emitter_digits($series));
    nfse_emitter_xml_add($dom,$inf,'nDPS',(string)$number);
    $competence = (string)($doc['competence_date'] ?? $realNow->format('Y-m-d'));
    nfse_emitter_xml_add($dom,$inf,'dCompet',substr($competence,0,10));
    nfse_emitter_xml_add($dom,$inf,'tpEmit','1');
    nfse_emitter_xml_add($dom,$inf,'cLocEmi',substr(nfse_emitter_digits($cfg['city_ibge'] ?? ''),0,7));

    $prest = nfse_emitter_xml_element($dom,'prest'); $inf->appendChild($prest);
    $prestDoc = nfse_emitter_digits($cfg['company_cnpj'] ?? '');
    nfse_emitter_xml_add($dom,$prest,strlen($prestDoc) === 11 ? 'CPF' : 'CNPJ',$prestDoc);
    $im = nfse_emitter_text($cfg['municipal_registration'] ?? '',30);
    if ($im !== '') nfse_emitter_xml_add($dom,$prest,'IM',$im);
    $reg = nfse_emitter_xml_element($dom,'regTrib'); $prest->appendChild($reg);
    $simpleStatus = nfse_emitter_simple_status($cfg);
    nfse_emitter_xml_add($dom,$reg,'opSimpNac',$simpleStatus);
    $simpleRegime = nfse_emitter_simple_regime($tax,$simpleStatus);
    if ($simpleRegime !== '') nfse_emitter_xml_add($dom,$reg,'regApTribSN',$simpleRegime);
    nfse_emitter_xml_add($dom,$reg,'regEspTrib',nfse_emitter_special_regime($tax));

    $tom = $context['tomador'];
    if ($tom['document'] !== '') {
      $toma = nfse_emitter_xml_element($dom,'toma'); $inf->appendChild($toma);
      nfse_emitter_xml_add($dom,$toma,strlen($tom['document']) === 11 ? 'CPF' : 'CNPJ',$tom['document']);
      if ($tom['municipal_registration'] !== '') nfse_emitter_xml_add($dom,$toma,'IM',$tom['municipal_registration']);
      nfse_emitter_xml_add($dom,$toma,'xNome',$tom['name']);
      $addressComplete = strlen($tom['city_ibge']) === 7 && strlen($tom['zip']) === 8 && $tom['address'] !== '' && $tom['district'] !== '';
      if ($addressComplete) {
        $end = nfse_emitter_xml_element($dom,'end'); $toma->appendChild($end);
        $endNac = nfse_emitter_xml_element($dom,'endNac'); $end->appendChild($endNac);
        nfse_emitter_xml_add($dom,$endNac,'cMun',$tom['city_ibge']);
        nfse_emitter_xml_add($dom,$endNac,'CEP',$tom['zip']);
        nfse_emitter_xml_add($dom,$end,'xLgr',$tom['address']);
        nfse_emitter_xml_add($dom,$end,'nro',$tom['number'] !== '' ? $tom['number'] : 'S/N');
        if ($tom['complement'] !== '') nfse_emitter_xml_add($dom,$end,'xCpl',$tom['complement']);
        nfse_emitter_xml_add($dom,$end,'xBairro',$tom['district']);
      }
      if (strlen($tom['phone']) >= 6) nfse_emitter_xml_add($dom,$toma,'fone',$tom['phone']);
      if ($tom['email'] !== '' && filter_var($tom['email'],FILTER_VALIDATE_EMAIL)) nfse_emitter_xml_add($dom,$toma,'email',$tom['email']);
    }

    $serv = nfse_emitter_xml_element($dom,'serv'); $inf->appendChild($serv);
    $locPrest = nfse_emitter_xml_element($dom,'locPrest'); $serv->appendChild($locPrest);
    nfse_emitter_xml_add($dom,$locPrest,'cLocPrestacao',substr(nfse_emitter_digits($context['service_city_ibge']),0,7));
    $cServ = nfse_emitter_xml_element($dom,'cServ'); $serv->appendChild($cServ);
    $cTribNac = str_pad(substr(nfse_emitter_digits($tax['nfse_national_tax_code'] ?? ''),0,6),6,'0',STR_PAD_LEFT);
    nfse_emitter_xml_add($dom,$cServ,'cTribNac',$cTribNac);
    $cTribMun = substr(nfse_emitter_digits($tax['municipal_tax_code'] ?? ''),0,3);
    if ($cTribMun !== '') nfse_emitter_xml_add($dom,$cServ,'cTribMun',str_pad($cTribMun,3,'0',STR_PAD_LEFT));
    nfse_emitter_xml_add($dom,$cServ,'xDescServ',$context['description']);
    $nbs = substr(nfse_emitter_digits($tax['nbs_code'] ?? ''),0,9);
    if (strlen($nbs) === 9) nfse_emitter_xml_add($dom,$cServ,'cNBS',$nbs);
    $orderReference = (int)nfse_emitter_value($context['order'] ?? [], ['numero_pedido'], 0);
    if ($orderReference <= 0) $orderReference = (int)($doc['source_id'] ?? $doc['pedido_id'] ?? 0);
    nfse_emitter_xml_add($dom,$cServ,'cIntContrib','PED' . $orderReference);
    $info = nfse_emitter_xml_element($dom,'infoCompl'); $serv->appendChild($info);
    nfse_emitter_xml_add($dom,$info,'xPed',(string)$orderReference);
    nfse_emitter_xml_add($dom,$info,'xInfComp','Serviço referente ao pedido #' . $orderReference . ' do Nexa Próspero.');

    $valores = nfse_emitter_xml_element($dom,'valores'); $inf->appendChild($valores);
    $vServPrest = nfse_emitter_xml_element($dom,'vServPrest'); $valores->appendChild($vServPrest);
    nfse_emitter_xml_add($dom,$vServPrest,'vServ',nfse_emitter_money($context['gross']));
    if ((float)$context['discount'] > 0) {
      $desc = nfse_emitter_xml_element($dom,'vDescCondIncond'); $valores->appendChild($desc);
      nfse_emitter_xml_add($dom,$desc,'vDescIncond',nfse_emitter_money($context['discount']));
    }
    $trib = nfse_emitter_xml_element($dom,'trib'); $valores->appendChild($trib);
    $tribMun = nfse_emitter_xml_element($dom,'tribMun'); $trib->appendChild($tribMun);
    nfse_emitter_xml_add($dom,$tribMun,'tribISSQN',nfse_emitter_iss_taxation($tax));
    $issWithheld = !empty($tax['iss_withheld']);
    nfse_emitter_xml_add($dom,$tribMun,'tpRetISSQN',$issWithheld ? '2' : '1');
    // Regra E0625 do Sistema Nacional: ME/EPP do Simples (opSimpNac=3) com ISSQN
    // apurado pelo Simples (regApTribSN=1) e SEM retenção (tpRetISSQN=1) não pode
    // informar alíquota: o ISS é recolhido no DAS. Com retenção, a alíquota segue.
    $omitIssRate = $simpleStatus === '3' && $simpleRegime === '1' && !$issWithheld;
    if (!$omitIssRate && (float)($tax['iss_rate'] ?? 0) > 0) nfse_emitter_xml_add($dom,$tribMun,'pAliq',nfse_emitter_percent($tax['iss_rate']));

    $net = max(0.0,(float)$context['net']);
    $pisRetention = nfse_emitter_code_from_label($tax['pis_cofins_retention_type'] ?? '', [
      'pis/cofins/csll nao retidos' => '0',
      'pis/cofins retido' => '1',
      'pis/cofins nao retido' => '2',
      'pis/cofins/csll retidos' => '3',
      'pis/cofins retidos, csll nao retido' => '4',
      'pis retido, cofins/csll nao retido' => '5',
      'cofins retido, pis/csll nao retido' => '6',
      'pis nao retido, cofins/csll retidos' => '7',
      'pis/cofins nao retidos, csll retido' => '8',
      'cofins nao retido, pis/csll retidos' => '9',
    ], '');
    $pisRate = (float)($tax['pis_rate'] ?? 0);
    $cofinsRate = (float)($tax['cofins_rate'] ?? 0);
    $irrfRate = (float)($tax['irrf_rate'] ?? 0);
    $csllRate = (float)($tax['csll_rate'] ?? 0);
    $inssRate = (float)($tax['inss_rate'] ?? 0);
    if ($pisRetention !== '' || $pisRate > 0 || $cofinsRate > 0 || $irrfRate > 0 || $csllRate > 0 || $inssRate > 0) {
      $tribFed = nfse_emitter_xml_element($dom,'tribFed'); $trib->appendChild($tribFed);
      if ($pisRetention !== '' || $pisRate > 0 || $cofinsRate > 0) {
        $pc = nfse_emitter_xml_element($dom,'piscofins'); $tribFed->appendChild($pc);
        $pisCst = str_pad(substr(nfse_emitter_digits($tax['pis_cst'] ?? '00'),0,2),2,'0',STR_PAD_LEFT);
        nfse_emitter_xml_add($dom,$pc,'CST',$pisCst);
        if ($pisRate > 0 || $cofinsRate > 0) {
          nfse_emitter_xml_add($dom,$pc,'vBCPisCofins',nfse_emitter_money($net));
          // A ordem dos elementos precisa seguir o XSD: alíquotas antes dos valores.
          if ($pisRate > 0) nfse_emitter_xml_add($dom,$pc,'pAliqPis',nfse_emitter_percent($pisRate));
          if ($cofinsRate > 0) nfse_emitter_xml_add($dom,$pc,'pAliqCofins',nfse_emitter_percent($cofinsRate));
          if ($pisRate > 0) nfse_emitter_xml_add($dom,$pc,'vPis',nfse_emitter_money($net*$pisRate/100));
          if ($cofinsRate > 0) nfse_emitter_xml_add($dom,$pc,'vCofins',nfse_emitter_money($net*$cofinsRate/100));
        }
        if ($pisRetention !== '') nfse_emitter_xml_add($dom,$pc,'tpRetPisCofins',$pisRetention);
      }
      if ($inssRate > 0) nfse_emitter_xml_add($dom,$tribFed,'vRetCP',nfse_emitter_money($net*$inssRate/100));
      if ($irrfRate > 0) nfse_emitter_xml_add($dom,$tribFed,'vRetIRRF',nfse_emitter_money($net*$irrfRate/100));
      if ($csllRate > 0) nfse_emitter_xml_add($dom,$tribFed,'vRetCSLL',nfse_emitter_money($net*$csllRate/100));
    }
    $totTrib = nfse_emitter_xml_element($dom,'totTrib'); $trib->appendChild($totTrib);
    // ME/EPP do Simples (opSimpNac=3) não pode usar indTotTrib (E0712): informa o
    // % aproximado de tributos da alíquota do Simples Nacional (pTotTribSN).
    $snTotalRate = (float)($cfg['nfse_sn_total_tax_rate'] ?? 0);
    if ($simpleStatus === '3' && $snTotalRate > 0) {
      nfse_emitter_xml_add($dom,$totTrib,'pTotTribSN',number_format($snTotalRate,2,'.',''));
    } else {
      nfse_emitter_xml_add($dom,$totTrib,'indTotTrib','0');
    }

    $cIndOp = substr(nfse_emitter_digits($tax['nfse_national_operation_indicator'] ?? ''),0,6);
    $ibsCbsCst = str_pad(substr(nfse_emitter_digits($tax['ibs_cbs_cst'] ?? ''),0,3),3,'0',STR_PAD_LEFT);
    $classCode = substr(nfse_emitter_digits($tax['ibs_cbs_classification_code'] ?? ''),0,6);
    if (strlen($cIndOp) === 6 && strlen($ibsCbsCst) === 3 && strlen($classCode) === 6) {
      $ibscbs = nfse_emitter_xml_element($dom,'IBSCBS'); $inf->appendChild($ibscbs);
      nfse_emitter_xml_add($dom,$ibscbs,'finNFSe','0');
      nfse_emitter_xml_add($dom,$ibscbs,'indFinal','0');
      nfse_emitter_xml_add($dom,$ibscbs,'cIndOp',$cIndOp);
      $tpOper = nfse_emitter_operation_type($tax);
      if ($tpOper !== '') nfse_emitter_xml_add($dom,$ibscbs,'tpOper',$tpOper);
      nfse_emitter_xml_add($dom,$ibscbs,'indDest','0');
      $ibsValores = nfse_emitter_xml_element($dom,'valores'); $ibscbs->appendChild($ibsValores);
      $ibsTrib = nfse_emitter_xml_element($dom,'trib'); $ibsValores->appendChild($ibsTrib);
      $g = nfse_emitter_xml_element($dom,'gIBSCBS'); $ibsTrib->appendChild($g);
      nfse_emitter_xml_add($dom,$g,'CST',$ibsCbsCst);
      nfse_emitter_xml_add($dom,$g,'cClassTrib',$classCode);
    }

    $xml = $dom->saveXML($dom->documentElement, LIBXML_NOXMLDECL);
    if (!is_string($xml) || $xml === '') throw new RuntimeException('Falha ao gerar o XML da DPS.');
    return $xml;
  }
}

if (!function_exists('nfse_emitter_require_common')) {
  function nfse_emitter_require_common(): void {
    $autoload = function_exists('fiscal_nfce_nfephp_autoload_path') ? fiscal_nfce_nfephp_autoload_path() : dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Dependências fiscais não encontradas. Execute composer install na pasta nexa.');
    require_once $autoload;
    if (!class_exists('NFePHP\\Common\\Certificate') || !class_exists('NFePHP\\Common\\Signer')) {
      throw new RuntimeException('Classes de certificado e assinatura do NFePHP não estão disponíveis.');
    }
  }
}


if (!function_exists('nfse_emitter_ensure_utf8_xml')) {
  /**
   * Entrega o XML em UTF-8 estrito, sem BOM e com declaração explícita.
   *
   * A declaração fica fora do elemento assinado e, portanto, não altera o
   * DigestValue nem o SignatureValue.
   */
  function nfse_emitter_ensure_utf8_xml(string $xml): string {
    if (str_starts_with($xml, "\xEF\xBB\xBF")) {
      $xml = substr($xml, 3);
    }

    // Remove somente uma declaração XML preexistente no início.
    $xml = preg_replace(
      '/^\s*<\?xml\s+[^?]*\?>\s*/i',
      '',
      $xml,
      1
    );
    if (!is_string($xml)) {
      throw new RuntimeException('Não foi possível normalizar a declaração XML.');
    }

    $xml = ltrim($xml, " \t\r\n");

    // preg_match //u falha quando há qualquer sequência inválida em UTF-8.
    if ($xml === '' || preg_match('//u', $xml) !== 1) {
      throw new RuntimeException(
        'O XML contém bytes que não formam uma sequência UTF-8 válida.'
      );
    }

    // XML 1.0 não aceita a maioria dos caracteres de controle.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $xml) === 1) {
      throw new RuntimeException(
        'O XML contém caracteres de controle inválidos para XML 1.0.'
      );
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
  }
}

if (!function_exists('nfse_emitter_verify_declared_signature')) {
  /**
   * Valida a assinatura conforme os algoritmos declarados dentro do próprio XML.
   *
   * Essa validação é independente da verificação interna do NFePHP e impede que
   * uma assinatura calculada com canonicalização diferente da informada em
   * CanonicalizationMethod/Transform seja aceita localmente.
   *
   * @return array<int,string>
   */
  function nfse_emitter_verify_declared_signature(string $xml): array {
    $errors = [];
    if (!class_exists('DOMDocument') || !function_exists('openssl_verify')) {
      return ['DOM e OpenSSL são obrigatórios para validar a assinatura XML.'];
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;

    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
      foreach (libxml_get_errors() as $error) {
        $errors[] = 'Assinatura: XML inválido: ' . trim($error->message);
      }
      libxml_clear_errors();
      return $errors ?: ['Assinatura: XML inválido.'];
    }
    libxml_clear_errors();

    $signature = $dom->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'Signature'
    )->item(0);
    if (!$signature instanceof DOMElement) {
      return ['Assinatura: elemento Signature ausente.'];
    }

    $signedInfo = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'SignedInfo'
    )->item(0);
    $reference = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'Reference'
    )->item(0);
    $canonicalizationMethod = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'CanonicalizationMethod'
    )->item(0);
    $signatureMethod = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'SignatureMethod'
    )->item(0);
    $digestMethod = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'DigestMethod'
    )->item(0);
    $digestValueNode = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'DigestValue'
    )->item(0);
    $signatureValueNode = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'SignatureValue'
    )->item(0);
    $certificateNode = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'X509Certificate'
    )->item(0);

    foreach ([
      'SignedInfo' => $signedInfo,
      'Reference' => $reference,
      'CanonicalizationMethod' => $canonicalizationMethod,
      'SignatureMethod' => $signatureMethod,
      'DigestMethod' => $digestMethod,
      'DigestValue' => $digestValueNode,
      'SignatureValue' => $signatureValueNode,
      'X509Certificate' => $certificateNode,
    ] as $name => $node) {
      if (!$node instanceof DOMNode) {
        $errors[] = 'Assinatura: elemento ' . $name . ' ausente.';
      }
    }
    if ($errors) return $errors;

    $inclusiveC14n = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    $rsaSha256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    $sha256Uri = 'http://www.w3.org/2001/04/xmlenc#sha256';

    $canonicalUri = (string)$canonicalizationMethod->getAttribute('Algorithm');
    if ($canonicalUri !== $inclusiveC14n) {
      $errors[] = 'Assinatura: CanonicalizationMethod não está no padrão inclusivo esperado.';
    }

    $signatureUri = (string)$signatureMethod->getAttribute('Algorithm');
    if ($signatureUri !== $rsaSha256) {
      $errors[] = 'Assinatura: SignatureMethod diferente de RSA-SHA256.';
    }

    $digestUri = (string)$digestMethod->getAttribute('Algorithm');
    if ($digestUri !== $sha256Uri) {
      $errors[] = 'Assinatura: DigestMethod diferente de SHA-256.';
    }

    $transforms = $signature->getElementsByTagNameNS(
      'http://www.w3.org/2000/09/xmldsig#',
      'Transform'
    );
    $transformUris = [];
    foreach ($transforms as $transform) {
      if ($transform instanceof DOMElement) {
        $transformUris[] = (string)$transform->getAttribute('Algorithm');
      }
    }
    if (!in_array(
      'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
      $transformUris,
      true
    )) {
      $errors[] = 'Assinatura: transformação enveloped-signature ausente.';
    }
    if (!in_array($inclusiveC14n, $transformUris, true)) {
      $errors[] = 'Assinatura: transformação de canonicalização inclusiva ausente.';
    }

    $uri = trim((string)$reference->getAttribute('URI'));
    if (!str_starts_with($uri, '#')) {
      $errors[] = 'Assinatura: Reference URI inválida.';
      return $errors;
    }

    $referenceId = substr($uri, 1);
    if ($referenceId === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $referenceId)) {
      $errors[] = 'Assinatura: identificador referenciado inválido.';
      return $errors;
    }

    $xp = new DOMXPath($dom);
    $nodes = $xp->query('//*[@Id="' . $referenceId . '"]');
    $signedNode = $nodes ? $nodes->item(0) : null;
    if (!$signedNode instanceof DOMElement) {
      $errors[] = 'Assinatura: nó referenciado não encontrado.';
      return $errors;
    }

    // false = canonicalização inclusiva no DOMDocument::C14N.
    $canonicalReference = $signedNode->C14N(false, false);
    if (!is_string($canonicalReference)) {
      $errors[] = 'Assinatura: não foi possível canonicalizar o nó referenciado.';
      return $errors;
    }

    $calculatedDigest = base64_encode(hash('sha256', $canonicalReference, true));
    $informedDigest = preg_replace(
      '/\s+/',
      '',
      (string)$digestValueNode->nodeValue
    ) ?: '';

    if (!hash_equals($informedDigest, $calculatedDigest)) {
      $errors[] = 'Assinatura: DigestValue incompatível com o algoritmo declarado.';
    }

    $canonicalSignedInfo = $signedInfo->C14N(false, false);
    if (!is_string($canonicalSignedInfo)) {
      $errors[] = 'Assinatura: não foi possível canonicalizar SignedInfo.';
      return $errors;
    }

    $certificateBase64 = preg_replace(
      '/\s+/',
      '',
      (string)$certificateNode->nodeValue
    ) ?: '';
    $certificatePem = "-----BEGIN CERTIFICATE-----\n"
      . chunk_split($certificateBase64, 64, "\n")
      . "-----END CERTIFICATE-----\n";

    $publicKey = openssl_pkey_get_public($certificatePem);
    if ($publicKey === false) {
      $errors[] = 'Assinatura: certificado público inválido.';
      return $errors;
    }

    $signatureBytes = base64_decode(
      preg_replace('/\s+/', '', (string)$signatureValueNode->nodeValue) ?: '',
      true
    );
    if ($signatureBytes === false) {
      $errors[] = 'Assinatura: SignatureValue não é Base64 válido.';
      return $errors;
    }

    $verified = openssl_verify(
      $canonicalSignedInfo,
      $signatureBytes,
      $publicKey,
      OPENSSL_ALGO_SHA256
    );
    if ($verified !== 1) {
      $errors[] = 'Assinatura: SignatureValue incompatível com o algoritmo declarado.';
    }

    return array_values(array_unique($errors));
  }
}


if (!function_exists('nfse_emitter_sign_xml')) {
  function nfse_emitter_sign_xml(PDO $pdo, int $companyId, string $xml): string {
    nfse_emitter_require_common();
    $cert = fiscal_nfce_active_certificate($pdo,$companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    if ($password === '') throw new RuntimeException('A senha do certificado A1 não está salva.');
    $certificate = \NFePHP\Common\Certificate::readPfx($pfx,$password);

    // O NFePHP declara canonicalização inclusiva nos elementos
    // CanonicalizationMethod e Transform. Portanto, o cálculo também deve usar
    // C14N inclusivo (primeiro parâmetro false).
    $canonical = [false,false,null,null];

    $signed = \NFePHP\Common\Signer::sign(
      $certificate,
      $xml,
      'infDPS',
      'Id',
      OPENSSL_ALGO_SHA256,
      $canonical,
      'DPS'
    );

    // A API valida a codificação da área descompactada. O Signer pode devolver
    // somente o elemento raiz, sem a declaração XML; por isso ela é adicionada
    // explicitamente após a assinatura.
    $signed = nfse_emitter_ensure_utf8_xml($signed);

    if (!\NFePHP\Common\Signer::isSigned($signed,'infDPS',$canonical)) {
      throw new RuntimeException('A assinatura da DPS não pôde ser validada localmente.');
    }

    $declaredErrors = nfse_emitter_verify_declared_signature($signed);
    if ($declaredErrors) {
      throw new RuntimeException(
        'A assinatura XML não corresponde aos algoritmos declarados: '
        . implode(' | ', $declaredErrors)
      );
    }

    return $signed;
  }
}

if (!function_exists('nfse_emitter_validate_xml')) {
  function nfse_emitter_validate_xml(string $xml): array {
    $errors = [];

    if (str_starts_with($xml, "\xEF\xBB\xBF")) {
      $errors[] = 'O XML não pode iniciar com BOM UTF-8.';
    }
    if (preg_match('//u', $xml) !== 1) {
      $errors[] = 'O XML contém sequência de bytes inválida em UTF-8.';
    }
    if (!preg_match(
      '/^<\?xml\s+version=["\']1\.0["\']\s+encoding=["\']UTF-8["\']\s*\?>/i',
      $xml
    )) {
      $errors[] = 'O XML deve iniciar com <?xml version="1.0" encoding="UTF-8"?>.';
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $xml) === 1) {
      $errors[] = 'O XML contém caracteres de controle inválidos.';
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0','UTF-8');
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
      foreach (libxml_get_errors() as $error) $errors[] = trim($error->message);
      libxml_clear_errors();
      return $errors ?: ['XML inválido.'];
    }
    $xp = new DOMXPath($dom);
    foreach ([
      '//*[local-name()="DPS"]' => 'Raiz DPS ausente.',
      '//*[local-name()="infDPS"]' => 'Grupo infDPS ausente.',
      '//*[local-name()="infDPS"]/*[local-name()="prest"]' => 'Prestador ausente.',
      '//*[local-name()="infDPS"]/*[local-name()="serv"]' => 'Serviço ausente.',
      '//*[local-name()="infDPS"]/*[local-name()="valores"]' => 'Valores ausentes.',
      '//*[local-name()="Signature"]' => 'Assinatura XML ausente.',
    ] as $query => $message) if ($xp->query($query)->length === 0) $errors[] = $message;
    if (strpos($xml,'xmlns:') !== false) $errors[] = 'O XML não pode declarar prefixo de namespace.';

    foreach (nfse_emitter_verify_declared_signature($xml) as $signatureError) {
      $errors[] = $signatureError;
    }

    $xsdPath = trim((string)(getenv('NFSE_DPS_XSD_PATH') ?: ''));
    if ($xsdPath !== '' && is_file($xsdPath)) {
      if (!$dom->schemaValidate($xsdPath)) {
        foreach (libxml_get_errors() as $error) $errors[] = 'XSD: ' . trim($error->message);
      }
      libxml_clear_errors();
    }
    return array_values(array_unique($errors));
  }
}

if (!function_exists('nfse_emitter_certificate_pem_files')) {
  function nfse_emitter_certificate_pem_files(PDO $pdo, int $companyId): array {
    if (!function_exists('openssl_pkcs12_read')) throw new RuntimeException('A extensão OpenSSL do PHP é obrigatória.');
    $cert = fiscal_nfce_active_certificate($pdo,$companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    $parsed = [];
    if (!@openssl_pkcs12_read($pfx,$parsed,$password)) throw new RuntimeException('Não foi possível abrir o certificado A1 para a conexão mTLS. Confira a senha.');
    if (empty($parsed['cert']) || empty($parsed['pkey'])) throw new RuntimeException('O arquivo A1 não contém certificado e chave privada utilizáveis.');
    $dir = sys_get_temp_dir() . '/nexa_nfse_' . bin2hex(random_bytes(8));
    if (!@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar arquivos temporários seguros para o certificado.');
    $certPath = $dir . '/cert.pem';
    $keyPath = $dir . '/key.pem';
    $certificateChain = (string)$parsed['cert'];
    foreach (($parsed['extracerts'] ?? []) as $extraCertificate) {
      if (is_string($extraCertificate) && trim($extraCertificate) !== '') {
        $certificateChain .= "\n" . trim($extraCertificate) . "\n";
      }
    }
    if (@file_put_contents($certPath,$certificateChain,LOCK_EX) === false || @file_put_contents($keyPath,(string)$parsed['pkey'],LOCK_EX) === false) {
      @unlink($certPath); @unlink($keyPath); @rmdir($dir);
      throw new RuntimeException('Não foi possível preparar o certificado para a conexão mTLS.');
    }
    @chmod($certPath,0600); @chmod($keyPath,0600);
    return ['cert'=>$certPath,'key'=>$keyPath,'dir'=>$dir];
  }
}

if (!function_exists('nfse_emitter_cleanup_pem')) {
  function nfse_emitter_cleanup_pem(array $files): void {
    foreach (['cert','key'] as $key) if (!empty($files[$key])) @unlink((string)$files[$key]);
    if (!empty($files['dir'])) @rmdir((string)$files['dir']);
  }
}

if (!function_exists('nfse_emitter_http')) {
  function nfse_emitter_http(PDO $pdo, int $companyId, string $environment, string $method, string $path, ?array $jsonPayload = null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL do PHP é obrigatória para transmitir a NFS-e Nacional.');
    $base = nfse_emitter_api_base($environment);
    $url = $base . '/' . ltrim($path,'/');
    $headers = ['Accept: application/json, application/xml, text/xml','User-Agent: Nexa-Prospero-NFSe/1.01'];
    $body = null;
    if ($jsonPayload !== null) {
      $body = json_encode($jsonPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      if (!is_string($body)) throw new RuntimeException('Falha ao gerar o JSON de transmissão da DPS.');
      $headers[] = 'Content-Type: application/json; charset=UTF-8';
      $headers[] = 'Content-Length: ' . strlen($body);
    }
    $pem = nfse_emitter_certificate_pem_files($pdo,$companyId);
    $ch = curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_CUSTOMREQUEST=>strtoupper($method), CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_HEADER=>false, CURLOPT_FOLLOWLOCATION=>false,
      CURLOPT_CONNECTTIMEOUT=>20, CURLOPT_TIMEOUT=>60,
      CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSLCERT=>$pem['cert'], CURLOPT_SSLKEY=>$pem['key'],
      CURLOPT_SSLCERTTYPE=>'PEM', CURLOPT_SSLKEYTYPE=>'PEM',
      CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if ($body !== null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    try {
      $response = curl_exec($ch);
      $errno = curl_errno($ch);
      $error = curl_error($ch);
      $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
      $contentType = (string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    } finally {
      curl_close($ch);
      nfse_emitter_cleanup_pem($pem);
    }
    if ($response === false) $response = '';
    return ['url'=>$url,'method'=>strtoupper($method),'status'=>$status,'body'=>(string)$response,'content_type'=>$contentType,'curl_errno'=>$errno,'curl_error'=>$error,'request_body'=>$body];
  }
}

if (!function_exists('nfse_emitter_decode_gzip_b64')) {
  function nfse_emitter_decode_gzip_b64(string $value): string {
    $raw = base64_decode(trim($value),true);
    if ($raw === false) return '';
    $decoded = @gzdecode($raw);
    if ($decoded === false) $decoded = @gzinflate(substr($raw,10,-8));
    return is_string($decoded) ? $decoded : '';
  }
}

if (!function_exists('nfse_emitter_response_data')) {
  function nfse_emitter_response_data(string $body): array {
    $trim = trim($body);
    $out = ['json'=>null,'xml'=>'','access_key'=>'','code'=>'','message'=>''];
    if ($trim === '') return $out;
    if (substr($trim,0,1) === '<') {
      $out['xml'] = $trim;
      return $out;
    }
    $json = json_decode($trim,true);
    if (!is_array($json)) {
      $out['message'] = nfse_emitter_text($trim,1000);
      return $out;
    }
    $out['json'] = $json;
    $flat = [];
    $walker = function($value,$key='') use (&$walker,&$flat): void {
      if (is_array($value)) { foreach ($value as $k=>$v) $walker($v,(string)$k); return; }
      if (is_scalar($value) || $value === null) $flat[strtolower($key)][] = (string)$value;
    };
    $walker($json);
    foreach (['nfsexmlgzipb64','nfsexmlzipb64','xmlnfsegzipb64','xmlgzipb64','arquivoxmlgzipb64'] as $key) {
      if (!empty($flat[$key][0])) { $out['xml'] = nfse_emitter_decode_gzip_b64($flat[$key][0]); if ($out['xml'] !== '') break; }
    }
    if ($out['xml'] === '') {
      foreach (['nfsxml','nfsexml','xmlnfse','xml'] as $key) {
        if (!empty($flat[$key][0]) && strpos(trim($flat[$key][0]),'<') === 0) { $out['xml'] = trim($flat[$key][0]); break; }
      }
    }
    foreach (['chaveacesso','chavenfse','chave'] as $key) {
      if (!empty($flat[$key][0])) { $out['access_key'] = nfse_emitter_digits($flat[$key][0]); break; }
    }
    foreach (['codigo','code','codigomensagem','codigoerro'] as $key) if (!empty($flat[$key][0])) { $out['code'] = $flat[$key][0]; break; }
    $messages = [];
    foreach (['mensagem','message','descricao','description','complemento','erro','errors','detail','title'] as $key) {
      foreach (($flat[$key] ?? []) as $value) if (trim($value) !== '') $messages[] = trim($value);
    }
    $out['message'] = nfse_emitter_text(implode(' | ',array_values(array_unique($messages))),2000);
    return $out;
  }
}

if (!function_exists('nfse_emitter_xml_info')) {
  function nfse_emitter_xml_info(string $xml): array {
    $out = ['access_key'=>'','nfse_number'=>'','nfse_id'=>'','verification_code'=>'','protocol'=>''];
    if (trim($xml) === '') return $out;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) { libxml_clear_errors(); return $out; }
    $xp = new DOMXPath($dom);
    $text = function(string $query) use ($xp): string {
      $node = $xp->query($query)->item(0);
      return $node ? trim((string)$node->textContent) : '';
    };
    $inf = $xp->query('//*[local-name()="infNFSe"]')->item(0);
    if ($inf instanceof DOMElement) {
      $id = $inf->getAttribute('Id') ?: $inf->getAttribute('id');
      $out['nfse_id'] = $id;
      if (strpos($id,'NFS') === 0) $out['access_key'] = nfse_emitter_digits(substr($id,3));
    }
    if ($out['access_key'] === '') $out['access_key'] = nfse_emitter_digits($text('//*[local-name()="chaveAcesso"]'));
    $out['nfse_number'] = nfse_emitter_digits($text('//*[local-name()="infNFSe"]/*[local-name()="nNFSe"]'));
    $out['verification_code'] = $text('//*[local-name()="cVerif"]') ?: $text('//*[local-name()="cVerificacao"]');
    $out['protocol'] = $text('//*[local-name()="nProt"]') ?: $text('//*[local-name()="protocolo"]');
    libxml_clear_errors();
    return $out;
  }
}

if (!function_exists('nfse_emitter_store_xml_file')) {
  function nfse_emitter_store_xml_file(int $companyId, string $environment, int $docId, string $kind, string $xml): string {
    $dir = dirname(__DIR__) . '/storage/fiscal/nfse/' . $companyId . '/' . strtolower($environment) . '/' . date('Y/m');
    if (!is_dir($dir) && !@mkdir($dir,0750,true) && !is_dir($dir)) return '';
    $name = preg_replace('/[^a-z0-9_-]/i','_',strtolower($kind)) . '-doc-' . $docId . '.xml';
    $path = $dir . '/' . $name;
    if (@file_put_contents($path,$xml,LOCK_EX) === false) return '';
    return ltrim(str_replace(dirname(__DIR__),'',$path),'/\\');
  }
}

if (!function_exists('nfse_emitter_mark_error')) {
  function nfse_emitter_mark_error(PDO $pdo, int $companyId, int $docId, string $status, string $code, string $message, int $httpStatus = 0, string $response = ''): void {
    $pdo->prepare("UPDATE fiscal_documents SET status=?, sefaz_status_code=?, sefaz_status_message=?, updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$status,substr($code,0,10),nfse_emitter_text($message,255),$companyId,$docId]);
    $pdo->prepare("UPDATE fiscal_nfse_details SET status=?, api_http_status=?, api_error_code=?, api_error_message=?, response_payload=?, last_attempt_at=NOW(), attempts=attempts+1 WHERE company_id=? AND fiscal_document_id=?")
      ->execute([$status,$httpStatus,substr($code,0,40),nfse_emitter_text($message,4000),$response,$companyId,$docId]);
  }
}

if (!function_exists('nfse_emitter_authorize_document_unlocked')) {
  function nfse_emitter_authorize_document_unlocked(PDO $pdo, int $companyId, int $docId): array {
    nfse_emitter_require_schema($pdo);
    nfse_emitter_require_runtime(true);
    $doc = nfse_emitter_document_by_id($pdo,$companyId,$docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
    $currentStatus = strtoupper((string)($doc['status'] ?? ''));
    if ($currentStatus === 'AUTORIZADA') return ['ok'=>true,'document'=>$doc,'already_authorized'=>true];
    if (in_array($currentStatus, ['TRANSMITINDO','ERRO_COMUNICACAO'], true)) {
      throw new RuntimeException('Existe uma transmissão com resultado incerto. Use Recuperar por DPS antes de qualquer novo envio.');
    }
    $orderId = (int)($doc['source_id'] ?? $doc['pedido_id'] ?? 0);
    $context = nfse_emitter_order_context($pdo,$companyId,$orderId);
    if ($context['issues']) throw new RuntimeException(implode(' ', $context['issues']));
    if ($context['environment'] === 'PRODUCAO' && !nfse_emitter_production_enabled()) throw new RuntimeException('Produção bloqueada. Defina NFSE_ENABLE_PRODUCTION=1 somente depois da homologação formal.');

    $xml = nfse_emitter_build_dps_xml($context,$doc);
    $signed = nfse_emitter_sign_xml($pdo,$companyId,$xml);
    $validation = nfse_emitter_validate_xml($signed);
    if ($validation) throw new RuntimeException('DPS inválida: ' . implode(' | ',$validation));
    $dpsId = nfse_emitter_dps_id($context['config'],(string)$doc['series'],(int)$doc['number']);
    $gzip = gzencode($signed,9,FORCE_GZIP);
    if ($gzip === false) throw new RuntimeException('Não foi possível compactar a DPS.');

    $roundTrip = gzdecode($gzip);
    if (!is_string($roundTrip) || !hash_equals($signed, $roundTrip)) {
      throw new RuntimeException(
        'A conferência da compactação GZip da DPS falhou.'
      );
    }
    if (!str_starts_with(
      $roundTrip,
      '<?xml version="1.0" encoding="UTF-8"?>'
    )) {
      throw new RuntimeException(
        'A DPS descompactada não contém a declaração UTF-8 obrigatória.'
      );
    }

    $payload = ['dpsXmlGZipB64'=>base64_encode($gzip)];
    $payloadJson = json_encode($payload,JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256',$signed);
    $dpsPath = nfse_emitter_store_xml_file($companyId,(string)$context['environment'],$docId,'dps-assinada',$signed);
    $pdo->prepare("UPDATE fiscal_documents SET status='TRANSMITINDO', xml_path=?, xml_raw=?, xml_hash=?, issue_datetime=NOW(), updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$dpsPath,$signed,$hash,$companyId,$docId]);
    $pdo->prepare("UPDATE fiscal_nfse_details SET status='TRANSMITINDO', dps_id=?, dps_xml=?, signed_dps_xml=?, request_payload=?, request_hash=?, api_error_code=NULL, api_error_message=NULL, last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
      ->execute([$dpsId,$xml,$signed,$payloadJson,$hash,$companyId,$docId]);

    $http = nfse_emitter_http($pdo,$companyId,(string)$context['environment'],'POST','nfse',$payload);
    if ((int)$http['curl_errno'] !== 0 || (int)$http['status'] === 0) {
      $message = 'Falha de comunicação com a Sefin Nacional: ' . ((string)$http['curl_error'] ?: 'sem resposta HTTP') . '. Use Recuperar por DPS antes de tentar nova emissão.';
      nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO','CURL',$message,(int)$http['status'],(string)$http['body']);
      return ['ok'=>false,'ambiguous'=>true,'code'=>'CURL','message'=>$message,'http'=>$http];
    }

    $parsed = nfse_emitter_response_data((string)$http['body']);
    if ((int)$http['status'] >= 200 && (int)$http['status'] < 300 && $parsed['xml'] !== '') {
      $info = nfse_emitter_xml_info($parsed['xml']);
      if ($info['access_key'] === '') {
        $message = 'A API retornou XML, mas a chave da NFS-e não foi identificada. Consulte a DPS antes de reenviar.';
        nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO','SEM_CHAVE',$message,(int)$http['status'],(string)$http['body']);
        return ['ok'=>false,'ambiguous'=>true,'message'=>$message,'http'=>$http];
      }
      $xmlPath = nfse_emitter_store_xml_file($companyId,(string)$context['environment'],$docId,'nfse-autorizada',$parsed['xml']);
      $pdo->prepare("UPDATE fiscal_documents SET access_key=?, protocol_number=?, status='AUTORIZADA', sefaz_status_code='100', sefaz_status_message='NFS-e autorizada', xml_path=?, xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$info['access_key'],$info['protocol'],$xmlPath,$parsed['xml'],hash('sha256',$parsed['xml']),$companyId,$docId]);
      $pdo->prepare("UPDATE fiscal_nfse_details SET status='AUTORIZADA', nfse_number=?, nfse_id=?, verification_code=?, response_xml=?, response_payload=?, api_http_status=?, api_error_code=NULL, api_error_message=NULL, attempts=attempts+1, authorized_at=NOW(), last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
        ->execute([$info['nfse_number'] !== '' ? (int)$info['nfse_number'] : null,$info['nfse_id'],$info['verification_code'],$parsed['xml'],(string)$http['body'],(int)$http['status'],$companyId,$docId]);
      return ['ok'=>true,'access_key'=>$info['access_key'],'nfse_number'=>$info['nfse_number'],'document'=>nfse_emitter_document_by_id($pdo,$companyId,$docId),'http'=>$http];
    }

    $code = $parsed['code'] !== '' ? $parsed['code'] : (string)$http['status'];
    $message = $parsed['message'] !== '' ? $parsed['message'] : ('A Sefin Nacional rejeitou a DPS. HTTP ' . $http['status'] . '.');
    $ambiguous = (int)$http['status'] >= 500 || in_array((int)$http['status'], [408,409], true);
    nfse_emitter_mark_error($pdo,$companyId,$docId,$ambiguous ? 'ERRO_COMUNICACAO' : 'REJEITADA',$code,$message,(int)$http['status'],(string)$http['body']);
    return ['ok'=>false,'ambiguous'=>$ambiguous,'code'=>$code,'message'=>$message,'http'=>$http,'response'=>$parsed];
  }
}

if (!function_exists('nfse_emitter_authorize_document')) {
  function nfse_emitter_authorize_document(PDO $pdo, int $companyId, int $docId): array {
    $lockName = 'nexa_nfse_emit_' . $companyId . '_' . $docId;
    $stLock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stLock->execute([$lockName]);
    if ((int)$stLock->fetchColumn() !== 1) {
      throw new RuntimeException('Este documento já está sendo processado em outra requisição. Aguarde e atualize a tela.');
    }
    try {
      return nfse_emitter_authorize_document_unlocked($pdo, $companyId, $docId);
    } finally {
      try {
        $stRelease = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stRelease->execute([$lockName]);
      } catch (Throwable $ignored) {}
    }
  }
}

if (!function_exists('nfse_emitter_authorize_order')) {
  function nfse_emitter_authorize_order(PDO $pdo, int $companyId, int $orderId): array {
    $context = nfse_emitter_order_context($pdo,$companyId,$orderId);
    if ($context['issues']) throw new RuntimeException(implode(' ', $context['issues']));
    $doc = nfse_emitter_reserve_document($pdo,$companyId,$orderId,$context);
    return nfse_emitter_authorize_document($pdo,$companyId,(int)$doc['id']);
  }
}

if (!function_exists('nfse_emitter_extract_access_key')) {
  function nfse_emitter_extract_access_key(array $parsed): string {
    if (!empty($parsed['access_key'])) return nfse_emitter_digits($parsed['access_key']);
    if (!empty($parsed['xml'])) return nfse_emitter_xml_info($parsed['xml'])['access_key'];
    return '';
  }
}

if (!function_exists('nfse_emitter_query_by_key')) {
  function nfse_emitter_query_by_key(PDO $pdo, int $companyId, int $docId, string $accessKey): array {
    $doc = nfse_emitter_document_by_id($pdo,$companyId,$docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
    $key = nfse_emitter_digits($accessKey);
    if ($key === '') throw new RuntimeException('Chave de acesso não informada.');
    $http = nfse_emitter_http($pdo,$companyId,(string)$doc['environment'],'GET','nfse/' . rawurlencode($key),null);
    $parsed = nfse_emitter_response_data((string)$http['body']);
    if ((int)$http['status'] >= 200 && (int)$http['status'] < 300 && $parsed['xml'] !== '') {
      $info = nfse_emitter_xml_info($parsed['xml']);
      if ($info['access_key'] === '') $info['access_key'] = $key;
      $xmlPath = nfse_emitter_store_xml_file($companyId,(string)$doc['environment'],$docId,'nfse-consultada',$parsed['xml']);
      $pdo->prepare("UPDATE fiscal_documents SET access_key=?, protocol_number=?, status='AUTORIZADA', sefaz_status_code='100', sefaz_status_message='NFS-e recuperada/consultada', xml_path=?, xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$info['access_key'],$info['protocol'],$xmlPath,$parsed['xml'],hash('sha256',$parsed['xml']),$companyId,$docId]);
      $pdo->prepare("UPDATE fiscal_nfse_details SET status='AUTORIZADA', nfse_number=?, nfse_id=?, verification_code=?, response_xml=?, response_payload=?, api_http_status=?, api_error_code=NULL, api_error_message=NULL, authorized_at=COALESCE(authorized_at,NOW()), consulted_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
        ->execute([$info['nfse_number'] !== '' ? (int)$info['nfse_number'] : null,$info['nfse_id'],$info['verification_code'],$parsed['xml'],(string)$http['body'],(int)$http['status'],$companyId,$docId]);
      return ['ok'=>true,'access_key'=>$info['access_key'],'nfse_number'=>$info['nfse_number'],'document'=>nfse_emitter_document_by_id($pdo,$companyId,$docId)];
    }
    $message = $parsed['message'] ?: ('Consulta da NFS-e retornou HTTP ' . (int)$http['status'] . '.');
    if (strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA') {
      $pdo->prepare("UPDATE fiscal_nfse_details
                        SET api_http_status=?, api_error_code=?, api_error_message=?,
                            response_payload=?, consulted_at=NOW()
                      WHERE company_id=? AND fiscal_document_id=?")
        ->execute([(int)$http['status'],$parsed['code'] ?: (string)$http['status'],$message,(string)$http['body'],$companyId,$docId]);
    } else {
      nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO',$parsed['code'] ?: (string)$http['status'],$message,(int)$http['status'],(string)$http['body']);
    }
    return ['ok'=>false,'message'=>$message,'http'=>$http];
  }
}

if (!function_exists('nfse_emitter_recover_by_dps')) {
  function nfse_emitter_recover_by_dps(PDO $pdo, int $companyId, int $docId): array {
    $doc = nfse_emitter_document_by_id($pdo,$companyId,$docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
    if (strtoupper((string)$doc['status']) === 'AUTORIZADA' && !empty($doc['access_key'])) return ['ok'=>true,'document'=>$doc,'already_authorized'=>true];
    $dpsId = trim((string)($doc['dps_id'] ?? ''));
    if ($dpsId === '') {
      $cfg = fiscal_company_config($pdo,$companyId);
      $dpsId = nfse_emitter_dps_id($cfg,(string)($doc['dps_series'] ?? $doc['series']),(int)($doc['dps_number'] ?? $doc['number']));
    }
    $head = nfse_emitter_http($pdo,$companyId,(string)$doc['environment'],'HEAD','dps/' . rawurlencode($dpsId),null);
    if ((int)$head['curl_errno'] !== 0 || (int)$head['status'] === 0) {
      $message = 'Não foi possível consultar o identificador da DPS: ' . ((string)$head['curl_error'] ?: 'sem resposta HTTP') . '.';
      nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO','HEAD_DPS',$message,(int)$head['status'],(string)$head['body']);
      return ['ok'=>false,'message'=>$message,'http'=>$head];
    }
    if ((int)$head['status'] === 404) {
      $message = 'A Sefin Nacional não localizou NFS-e gerada para esta DPS. Revise a rejeição antes de transmitir novamente.';
      nfse_emitter_mark_error($pdo,$companyId,$docId,'REJEITADA','DPS_NAO_GERADA',$message,(int)$head['status'],'');
      return ['ok'=>false,'not_generated'=>true,'message'=>$message];
    }
    $get = nfse_emitter_http($pdo,$companyId,(string)$doc['environment'],'GET','dps/' . rawurlencode($dpsId),null);
    $parsed = nfse_emitter_response_data((string)$get['body']);
    $key = nfse_emitter_extract_access_key($parsed);
    if ($key === '') {
      $headersBody = trim((string)$get['body']);
      if (preg_match('/\b(\d{44,60})\b/',$headersBody,$m)) $key = $m[1];
    }
    if ($key === '') {
      $message = $parsed['message'] ?: 'A DPS existe, mas a chave de acesso não foi retornada para este certificado.';
      nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO',$parsed['code'] ?: 'SEM_CHAVE',$message,(int)$get['status'],(string)$get['body']);
      return ['ok'=>false,'ambiguous'=>true,'message'=>$message];
    }
    return nfse_emitter_query_by_key($pdo,$companyId,$docId,$key);
  }
}

if (!function_exists('nfse_emitter_prepare_order')) {
  function nfse_emitter_prepare_order(PDO $pdo, int $companyId, int $orderId): array {
    $context = nfse_emitter_order_context($pdo,$companyId,$orderId);
    if ($context['issues']) throw new RuntimeException(implode(' ', $context['issues']));
    $doc = nfse_emitter_reserve_document($pdo,$companyId,$orderId,$context);
    $xml = nfse_emitter_build_dps_xml($context,$doc);
    $signed = nfse_emitter_sign_xml($pdo,$companyId,$xml);
    $errors = nfse_emitter_validate_xml($signed);
    if ($errors) throw new RuntimeException('DPS inválida: ' . implode(' | ',$errors));
    $dpsId = nfse_emitter_dps_id($context['config'],(string)$doc['series'],(int)$doc['number']);
    $path = nfse_emitter_store_xml_file($companyId,(string)$context['environment'],(int)$doc['id'],'dps-assinada',$signed);
    $pdo->prepare("UPDATE fiscal_documents SET status='ASSINADA', xml_path=?, xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$path,$signed,hash('sha256',$signed),$companyId,(int)$doc['id']]);
    $pdo->prepare("UPDATE fiscal_nfse_details SET status='ASSINADA', dps_id=?, dps_xml=?, signed_dps_xml=?, request_hash=?, api_error_code=NULL, api_error_message=NULL WHERE company_id=? AND fiscal_document_id=?")
      ->execute([$dpsId,$xml,$signed,hash('sha256',$signed),$companyId,(int)$doc['id']]);
    return nfse_emitter_document_by_id($pdo,$companyId,(int)$doc['id']) ?: $doc;
  }
}
