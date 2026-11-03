<?php
/**
 * Roteador de providers NFS-e.
 *
 * Mantém o emissor Nacional atual intacto e seleciona o provider Pronim/GOVBR
 * somente quando configurado explicitamente na empresa.
 */
declare(strict_types=1);

require_once __DIR__ . '/nfse_emitter.php';
require_once __DIR__ . '/nfse_pronim.php';

if (!function_exists('nfse_provider_normalize')) {
  function nfse_provider_normalize($value): string {
    $v = strtoupper(trim((string)$value));
    $v = strtr($v, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
    $v = preg_replace('/[^A-Z0-9]+/', '_', $v) ?: '';
    if (in_array($v, ['PRONIM','GOVBR','ISS_DIGITAL','PRONIM_GOVBR','GOVBR_ISS_DIGITAL'], true)) return 'PRONIM';
    return 'NACIONAL';
  }
}

if (!function_exists('nfse_provider_company_code')) {
  function nfse_provider_company_code(PDO $pdo, int $companyId): string {
    $cfg = fiscal_company_config($pdo, $companyId);
    return nfse_provider_normalize($cfg['nfse_provider'] ?? '');
  }
}

if (!function_exists('nfse_provider_document_code')) {
  function nfse_provider_document_code(PDO $pdo, int $companyId, int $docId): string {
    if ($docId > 0 && fiscal_table_exists($pdo, 'fiscal_nfse_details') && fiscal_column_exists($pdo, 'fiscal_nfse_details', 'provider_code')) {
      try {
        $st = $pdo->prepare('SELECT provider_code FROM fiscal_nfse_details WHERE company_id=? AND fiscal_document_id=? LIMIT 1');
        $st->execute([$companyId, $docId]);
        $stored = trim((string)$st->fetchColumn());
        if ($stored !== '') return nfse_provider_normalize($stored);
      } catch (Throwable $e) {}
    }
    return nfse_provider_company_code($pdo, $companyId);
  }
}

if (!function_exists('nfse_provider_label')) {
  function nfse_provider_label(string $provider): string {
    return nfse_provider_normalize($provider) === 'PRONIM'
      ? 'Pronim / GOVBR - ISS Digital'
      : 'Ambiente Nacional';
  }
}

if (!function_exists('nfse_provider_readiness')) {
  function nfse_provider_readiness(PDO $pdo, int $companyId): array {
    return nfse_provider_company_code($pdo, $companyId) === 'PRONIM'
      ? nfse_pronim_readiness($pdo, $companyId)
      : nfse_nacional_readiness($pdo, $companyId);
  }
}

if (!function_exists('nfse_provider_schema_issues')) {
  function nfse_provider_schema_issues(PDO $pdo, int $companyId): array {
    return nfse_provider_company_code($pdo, $companyId) === 'PRONIM'
      ? nfse_pronim_schema_issues($pdo)
      : nfse_emitter_schema_issues($pdo);
  }
}

if (!function_exists('nfse_provider_production_enabled')) {
  function nfse_provider_production_enabled(PDO $pdo, int $companyId): bool {
    return nfse_provider_company_code($pdo, $companyId) === 'PRONIM'
      ? nfse_pronim_production_enabled()
      : nfse_emitter_production_enabled();
  }
}

if (!function_exists('nfse_provider_prepare_order')) {
  function nfse_provider_prepare_order(PDO $pdo, int $companyId, int $orderId): array {
    $provider = nfse_provider_company_code($pdo, $companyId);
    $doc = $provider === 'PRONIM'
      ? nfse_pronim_prepare_order($pdo, $companyId, $orderId)
      : nfse_emitter_prepare_order($pdo, $companyId, $orderId);
    if ($provider === 'NACIONAL' && !empty($doc['id']) && fiscal_column_exists($pdo, 'fiscal_nfse_details', 'provider_code')) {
      try { $pdo->prepare("UPDATE fiscal_nfse_details SET provider_code='NACIONAL' WHERE company_id=? AND fiscal_document_id=? AND COALESCE(provider_code,'')=''")->execute([$companyId,(int)$doc['id']]); } catch (Throwable $e) {}
    }
    return $doc;
  }
}

if (!function_exists('nfse_provider_adopt_company_provider')) {
  /**
   * Migração Pronim -> Ambiente Nacional (Resolução CGSN 191/2026: desde 01/11/2026
   * o Pronim rejeita prestadores do Simples Nacional, código P3084).
   *
   * Um documento gravado como PRONIM passa a ser transmitido pelo Ambiente Nacional,
   * mantendo a MESMA série/número de DPS, somente quando:
   *  - a empresa já está configurada no provider Nacional;
   *  - o documento está REJEITADA, PREPARADA ou ASSINADA (nunca em transmissão
   *    incerta: TRANSMITINDO/ERRO_COMUNICACAO);
   *  - não há qualquer sinal de autorização (número da NFS-e, id, código de
   *    verificação ou data de autorização).
   * Documentos autorizados no Pronim continuam no Pronim (consulta/cancelamento).
   */
  function nfse_provider_adopt_company_provider(PDO $pdo, int $companyId, int $docId): bool {
    if ($docId <= 0 || !fiscal_table_exists($pdo, 'fiscal_nfse_details') || !fiscal_column_exists($pdo, 'fiscal_nfse_details', 'provider_code')) return false;
    if (nfse_provider_company_code($pdo, $companyId) !== 'NACIONAL') return false;
    $st = $pdo->prepare("SELECT d.status AS doc_status, nd.status, nd.provider_code, nd.nfse_number, nd.nfse_id, nd.verification_code, nd.authorized_at
      FROM fiscal_documents d
      INNER JOIN fiscal_nfse_details nd ON nd.fiscal_document_id=d.id AND nd.company_id=d.company_id
      WHERE d.company_id=? AND d.id=? AND d.model='NFSE' LIMIT 1");
    $st->execute([$companyId, $docId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || nfse_provider_normalize($row['provider_code'] ?? '') !== 'PRONIM') return false;
    $status = strtoupper(trim((string)(($row['status'] ?? '') ?: ($row['doc_status'] ?? ''))));
    if (!in_array($status, ['REJEITADA', 'PREPARADA', 'ASSINADA'], true)) return false;
    foreach (['nfse_number', 'nfse_id', 'verification_code', 'authorized_at'] as $key) {
      if (trim((string)($row[$key] ?? '')) !== '') return false;
    }
    $up = $pdo->prepare("UPDATE fiscal_nfse_details SET provider_code='NACIONAL' WHERE company_id=? AND fiscal_document_id=? AND provider_code='PRONIM'");
    $up->execute([$companyId, $docId]);
    if ($up->rowCount() === 1) {
      error_log('[Nexa][NFS-e] Documento #' . $docId . ' da empresa ' . $companyId . ' (status ' . $status . ') migrado de PRONIM para NACIONAL antes da transmissão.');
      return true;
    }
    return false;
  }
}

if (!function_exists('nfse_provider_authorize_document')) {
  function nfse_provider_authorize_document(PDO $pdo, int $companyId, int $docId): array {
    nfse_provider_adopt_company_provider($pdo, $companyId, $docId);
    return nfse_provider_document_code($pdo, $companyId, $docId) === 'PRONIM'
      ? nfse_pronim_authorize_document($pdo, $companyId, $docId)
      : nfse_emitter_authorize_document($pdo, $companyId, $docId);
  }
}

if (!function_exists('nfse_provider_authorize_order')) {
  function nfse_provider_authorize_order(PDO $pdo, int $companyId, int $orderId): array {
    $doc = nfse_provider_prepare_order($pdo, $companyId, $orderId);
    return nfse_provider_authorize_document($pdo, $companyId, (int)$doc['id']);
  }
}

if (!function_exists('nfse_provider_recover_by_dps')) {
  function nfse_provider_recover_by_dps(PDO $pdo, int $companyId, int $docId): array {
    return nfse_provider_document_code($pdo, $companyId, $docId) === 'PRONIM'
      ? nfse_pronim_recover_by_dps($pdo, $companyId, $docId)
      : nfse_emitter_recover_by_dps($pdo, $companyId, $docId);
  }
}

if (!function_exists('nfse_provider_query_by_key')) {
  function nfse_provider_query_by_key(PDO $pdo, int $companyId, int $docId, string $key): array {
    return nfse_provider_document_code($pdo, $companyId, $docId) === 'PRONIM'
      ? nfse_pronim_query_by_key($pdo, $companyId, $docId, $key)
      : nfse_emitter_query_by_key($pdo, $companyId, $docId, $key);
  }
}


if (!function_exists('nfse_provider_cancel_document')) {
  function nfse_provider_cancel_document(PDO $pdo, int $companyId, int $docId, string $reasonCode, string $reason): array {
    $provider = nfse_provider_document_code($pdo, $companyId, $docId);
    if ($provider !== 'PRONIM') throw new RuntimeException('O cancelamento implementado nesta etapa é exclusivo do provider Pronim/GOVBR; o emissor Nacional permanece inalterado.');
    return nfse_pronim_cancel_document($pdo, $companyId, $docId, $reasonCode, $reason);
  }
}
