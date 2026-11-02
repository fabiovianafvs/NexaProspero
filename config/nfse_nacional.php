<?php
/**
 * Validações cadastrais da NFS-e Nacional.
 *
 * Esta camada não transmite a DPS. Ela concentra as verificações necessárias
 * para liberar o emissor somente quando empresa, certificado, série, regras e
 * serviços estiverem coerentes com o padrão nacional.
 */
declare(strict_types=1);

require_once __DIR__ . '/nfse_runtime_env.php';
require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/fiscal_operational.php';

if (!function_exists('fiscal_normalize_item_type')) {
  /**
   * Normaliza o tipo fiscal sem transformar valores desconhecidos em serviço.
   * Somente identificadores explicitamente reconhecidos como serviço retornam
   * SERVICE; os demais permanecem como PRODUCT por segurança fiscal.
   */
  function fiscal_normalize_item_type($value): string {
    $type = strtoupper(trim((string)$value));
    if ($type === '') return 'PRODUCT';

    $type = strtr($type, [
      'Á'=>'A', 'À'=>'A', 'Â'=>'A', 'Ã'=>'A',
      'É'=>'E', 'Ê'=>'E',
      'Í'=>'I',
      'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O',
      'Ú'=>'U',
      'Ç'=>'C',
    ]);
    $type = preg_replace('/[^A-Z0-9]+/', '', $type) ?: '';

    return in_array($type, [
      'SERVICE', 'SERVICO', 'SERVICOS', 'SERV', 'S'
    ], true) ? 'SERVICE' : 'PRODUCT';
  }
}

if (!function_exists('nfse_nacional_document_version')) {
  function nfse_nacional_document_version(string $environment = 'HOMOLOGACAO'): array {
    $restricted = strtoupper(trim($environment)) !== 'PRODUCAO';
    return [
      'schema' => $restricted
        ? 'NFSe-ESQUEMAS_XSD-PRODREST-v1.01-20260727'
        : 'NFSe-ESQUEMAS_XSD-v1.01-20260209',
      'layout' => $restricted
        ? 'ANEXO_I-SEFIN_ADN-DPS_NFSe-SNNFSe-PRODREST-v1.01-20260209'
        : 'ANEXO_I-SEFIN_ADN-DPS_NFSe-SNNFSe-v1.01-20260209',
      'nbs' => $restricted
        ? 'ANEXO_B-NBS2-LISTA_SERVICO_NACIONAL-SNNFSe-PRODREST-v1.01-20260122'
        : 'ANEXO_B-NBS2-LISTA_SERVICO_NACIONAL-SNNFSe-v1.01-20260122',
      'operation_indicator' => $restricted
        ? 'ANEXO_C-INDOP_IBSCBS-SNNFSe-PRODREST-v1.01-20260122'
        : 'ANEXO_C-INDOP_IBSCBS-SNNFSe-v1.01-20260122',
      'danfse' => 'NT-008-DANFSe-v1.02-20260714',
    ];
  }
}

if (!function_exists('nfse_nacional_active_certificate')) {
  function nfse_nacional_active_certificate(PDO $pdo, int $companyId): ?array {
    if (!fiscal_table_exists($pdo, 'fiscal_certificates')) return null;
    try {
      $st = $pdo->prepare('SELECT * FROM fiscal_certificates WHERE company_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('nfse_nacional_active_series')) {
  function nfse_nacional_active_series(PDO $pdo, int $companyId, string $environment): ?array {
    if (!fiscal_table_exists($pdo, 'fiscal_document_series')) return null;
    try {
      $st = $pdo->prepare("SELECT * FROM fiscal_document_series WHERE company_id = ? AND is_active = 1 AND environment = ? AND model IN ('DPS','NFSE','SS') ORDER BY CASE model WHEN 'DPS' THEN 1 WHEN 'NFSE' THEN 2 ELSE 3 END, id ASC LIMIT 1");
      $st->execute([$companyId, $environment]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('nfse_nacional_service_products')) {
  function nfse_nacional_service_products(PDO $pdo, int $companyId, int $limit = 500): array {
    fiscal_ensure_schema($pdo);
    if (!fiscal_table_exists($pdo, 'produtos') || !fiscal_column_exists($pdo, 'produtos', 'is_service')) return [];
    $limit = max(1, min(2000, $limit));
    $numberExpr = fiscal_column_exists($pdo, 'produtos', 'numero_cadastro') ? 'p.numero_cadastro' : 'p.id';
    $sql = "SELECT p.id, {$numberExpr} AS numero_cadastro, p.nome, p.ativo, COALESCE(p.is_service,0) AS is_service,
                   fpt.tax_profile_id, COALESCE(fpt.item_type,'PRODUCT') AS item_type,
                   fpt.nbs_code, fpt.nbs_description, fpt.lc116_service_code, fpt.lc116_service_description,
                   fpt.iss_rate, fpt.iss_withheld, fpt.pis_cofins_retention_type,
                   fpt.municipal_tax_code, fpt.cnae, fpt.nfse_national_tax_code,
                   fpt.nfse_national_operation_indicator, fpt.ibs_cbs_cst,
                   fpt.ibs_cbs_classification_code, fpt.ibs_cbs_classification_name,
                   tp.name AS tax_profile_name, tp.profile_type AS tax_profile_type, tp.is_active AS tax_profile_active
              FROM produtos p
         LEFT JOIN fiscal_product_tax fpt ON fpt.company_id = p.company_id AND fpt.product_id = p.id
         LEFT JOIN fiscal_tax_profiles tp ON tp.company_id = p.company_id AND tp.id = fpt.tax_profile_id
             WHERE p.company_id = ? AND COALESCE(p.is_service,0) = 1
          ORDER BY p.ativo DESC, p.nome ASC, p.id ASC
             LIMIT {$limit}";
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$companyId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('nfse_nacional_service_product_issues')) {
  function nfse_nacional_service_product_issues(array $row): array {
    $issues = [];
    if (fiscal_normalize_item_type($row['item_type'] ?? 'PRODUCT') !== 'SERVICE') $issues[] = 'Tipo fiscal não está como serviço';
    if ((int)($row['tax_profile_id'] ?? 0) <= 0) $issues[] = 'Regra de tributação não vinculada';
    elseif (fiscal_normalize_item_type($row['tax_profile_type'] ?? 'PRODUCT') !== 'SERVICE') $issues[] = 'Regra vinculada não é do tipo Serviço';
    elseif (empty($row['tax_profile_active'])) $issues[] = 'Regra de tributação inativa';
    $nbs = preg_replace('/\D+/', '', (string)($row['nbs_code'] ?? '')) ?: '';
    $nationalCode = preg_replace('/\D+/', '', (string)($row['nfse_national_tax_code'] ?? '')) ?: '';
    $ibsCbsCst = preg_replace('/\D+/', '', (string)($row['ibs_cbs_cst'] ?? '')) ?: '';
    $classCode = preg_replace('/\D+/', '', (string)($row['ibs_cbs_classification_code'] ?? '')) ?: '';
    $operationIndicator = preg_replace('/\D+/', '', (string)($row['nfse_national_operation_indicator'] ?? '')) ?: '';
    if (strlen($nbs) !== 9) $issues[] = 'NBS deve possuir 9 dígitos';
    if (trim((string)($row['lc116_service_code'] ?? '')) === '') $issues[] = 'LC 116/03 não informada';
    if (strlen($nationalCode) !== 6) $issues[] = 'Código de Tributação Nacional deve possuir 6 dígitos';
    if (strlen($ibsCbsCst) !== 3) $issues[] = 'CST IBS/CBS deve possuir 3 dígitos';
    if (strlen($classCode) !== 6) $issues[] = 'Classificação IBS/CBS deve possuir 6 dígitos';
    if (strlen($operationIndicator) !== 6) $issues[] = 'Indicador da operação deve possuir 6 dígitos';
    return $issues;
  }
}

if (!function_exists('nfse_nacional_readiness')) {
  function nfse_nacional_readiness(PDO $pdo, int $companyId): array {
    fiscal_ensure_schema($pdo);
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}

    $cfg = fiscal_company_config($pdo, $companyId);
    $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';
    $certificate = nfse_nacional_active_certificate($pdo, $companyId);
    $series = nfse_nacional_active_series($pdo, $companyId, $environment);
    $products = nfse_nacional_service_products($pdo, $companyId, 2000);
    $incomplete = [];
    foreach ($products as $row) {
      $issues = nfse_nacional_service_product_issues($row);
      if ($issues) {
        $row['issues'] = $issues;
        $incomplete[] = $row;
      }
    }

    $serviceProfiles = [];
    try { $serviceProfiles = fiscal_tax_profiles($pdo, $companyId, true, 'SERVICE'); } catch (Throwable $e) {}

    $certPathOk = false;
    $certNotExpired = false;
    $certTypeOk = false;
    if ($certificate) {
      $rel = trim((string)($certificate['file_path'] ?? ''));
      $abs = $rel !== '' ? (__DIR__ . '/../' . ltrim($rel, '/\\')) : '';
      $certPathOk = $abs !== '' && is_file($abs) && is_readable($abs);
      $validUntil = trim((string)($certificate['valid_until'] ?? ''));
      $certNotExpired = $validUntil === '' || $validUntil >= date('Y-m-d');
      $certTypeOk = strtoupper((string)($certificate['certificate_type'] ?? '')) === 'A1';
    }

    $checks = [
      ['key'=>'nfse_enabled','label'=>'NFS-e habilitada na empresa','ok'=>!empty($cfg['nfse_enabled']),'detail'=>!empty($cfg['nfse_enabled']) ? 'Documento habilitado.' : 'Marque NFS-e nas Configurações fiscais.','href'=>'fiscal_configuracoes.php?edit_config=1'],
      ['key'=>'national_standard','label'=>'Padrão Nacional selecionado','ok'=>!empty($cfg['nfse_national_standard']),'detail'=>!empty($cfg['nfse_national_standard']) ? 'Integração nacional selecionada.' : 'Marque “Usar padrão nacional”.','href'=>'fiscal_configuracoes.php?edit_config=1'],
      ['key'=>'company_identity','label'=>'Prestador identificado','ok'=>strlen(preg_replace('/\D+/', '', (string)($cfg['company_cnpj'] ?? '')))===14 && trim((string)($cfg['company_name'] ?? ''))!=='','detail'=>'Razão social e CNPJ são obrigatórios.','href'=>'fiscal_configuracoes.php?edit_config=1'],
      ['key'=>'municipality','label'=>'Município do prestador','ok'=>strlen(preg_replace('/\D+/', '', (string)($cfg['city_ibge'] ?? '')))===7,'detail'=>'Informe o código IBGE de 7 dígitos.','href'=>'fiscal_configuracoes.php?edit_config=1'],
      ['key'=>'municipal_registration','label'=>'Inscrição municipal','ok'=>trim((string)($cfg['municipal_registration'] ?? ''))!=='','detail'=>'Cadastre a inscrição municipal usada pelo emissor.','href'=>'fiscal_configuracoes.php?edit_config=1'],
      ['key'=>'certificate','label'=>'Certificado A1 ativo e legível','ok'=>(bool)$certificate && $certPathOk && $certNotExpired && $certTypeOk,'detail'=>!$certificate ? 'Nenhum certificado ativo.' : (!$certTypeOk ? 'Para integração web direta, use certificado A1.' : (!$certPathOk ? 'Arquivo do certificado não foi localizado.' : (!$certNotExpired ? 'Certificado vencido.' : 'Certificado disponível.'))),'href'=>'fiscal_configuracoes.php?new_cert=1'],
      ['key'=>'series','label'=>'Série DPS/NFS-e do ambiente','ok'=>(bool)$series && (int)(preg_replace('/\D+/', '', (string)($series['series'] ?? '')) ?: 0) >= 1 && (int)(preg_replace('/\D+/', '', (string)($series['series'] ?? '')) ?: 0) <= 49999,'detail'=>$series ? ('Série ' . (string)$series['series'] . ' · próximo número ' . (string)$series['next_number'] . ' · emissor próprio exige 00001 a 49999') : ('Cadastre uma série DPS no ambiente ' . $environment . '.'),'href'=>'fiscal_configuracoes.php?new_series=1'],
      ['key'=>'profiles','label'=>'Regra de tributação de serviço','ok'=>count($serviceProfiles)>0,'detail'=>count($serviceProfiles) . ' regra(s) ativa(s) do tipo Serviço.','href'=>'fiscal_tributacoes.php?new=1'],
      ['key'=>'products','label'=>'Serviços cadastrados','ok'=>count($products)>0,'detail'=>count($products) . ' item(ns) marcado(s) como serviço.','href'=>'produto_form.php'],
      ['key'=>'products_complete','label'=>'Serviços com fiscal completo','ok'=>count($products)>0 && count($incomplete)===0,'detail'=>count($incomplete) . ' serviço(s) com pendência fiscal.','href'=>'produtos.php'],
    ];

    $okCount = count(array_filter($checks, static fn(array $c): bool => !empty($c['ok'])));
    return [
      'config' => $cfg,
      'environment' => $environment,
      'certificate' => $certificate,
      'series' => $series,
      'profiles' => $serviceProfiles,
      'products' => $products,
      'incomplete_products' => $incomplete,
      'checks' => $checks,
      'ok_count' => $okCount,
      'check_count' => count($checks),
      'cadaster_ready' => $okCount === count($checks),
      'documentation' => nfse_nacional_document_version($environment),
    ];
  }
}
