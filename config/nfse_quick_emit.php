<?php
declare(strict_types=1);

/**
 * Emissão direta de NFS-e para ações explícitas de venda/pedido.
 *
 * Reutiliza o provider já configurado (Ambiente Nacional ou Pronim/GOVBR),
 * prepara a DPS e transmite na mesma operação. Para Pronim em produção, o clique
 * explícito em "Emitir NFS-e"/finalização fiscal vale como confirmação desta
 * requisição; a tela manual fiscal continua com a confirmação textual própria.
 */
require_once __DIR__ . '/nfse_provider_router.php';

if (!function_exists('nfse_quick_authorize_order')) {
  function nfse_quick_authorize_order(PDO $pdo, int $companyId, int $orderId): array {
    if ($companyId <= 0 || $orderId <= 0) throw new RuntimeException('Empresa ou pedido inválido para emissão da NFS-e.');

    $existing = nfse_emitter_existing_document($pdo, $companyId, $orderId);
    if ($existing && strtoupper(trim((string)($existing['status'] ?? $existing['nfse_status'] ?? ''))) === 'AUTORIZADA') {
      return ['ok'=>true, 'document'=>$existing, 'already_authorized'=>true];
    }

    // Transmissão anterior incerta ou DPS já assinada: nunca retransmitir
    // automaticamente ao clicar em emissão direta. Usar recuperação explícita.
    if ($existing) {
      $currentStatus = strtoupper(trim((string)(($existing['nfse_status'] ?? '') ?: ($existing['status'] ?? ''))));
      $hasSigned = trim((string)($existing['signed_dps_xml'] ?? '')) !== '';
      if ($hasSigned || !in_array($currentStatus, ['PREPARADA', ''], true)) {
        throw new RuntimeException('Já existe DPS vinculada a este pedido (documento #' . (int)$existing['id'] . ', situação ' . $currentStatus . '). Não retransmita: abra o documento e utilize Recuperar/Consultar para verificar a prefeitura.');
      }
    }

    $provider = nfse_provider_company_code($pdo, $companyId);
    if (function_exists('nexa_progress')) nexa_progress($provider === 'PRONIM' ? 'Transmitindo a NFS-e para a prefeitura e aguardando autorização...' : 'Transmitindo a NFS-e para o Ambiente Nacional e aguardando autorização...');
    $cfg = fiscal_company_config($pdo, $companyId);
    $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));

    // No fluxo direto o usuário já tomou uma ação fiscal explícita. A constante é
    // válida apenas nesta requisição e não remove a confirmação da tela manual.
    if ($provider === 'PRONIM' && $environment === 'PRODUCAO' && !defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) {
      define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED', true);
    }

    $result = nfse_provider_authorize_order($pdo, $companyId, $orderId);
    if (!is_array($result)) throw new RuntimeException('O provedor da NFS-e não retornou uma resposta válida.');
    if (empty($result['ok'])) {
      $message = trim((string)($result['message'] ?? 'A NFS-e não foi autorizada.'));
      if (!empty($result['ambiguous'])) $message .= ' Não gere outra DPS: utilize a recuperação/consulta da NFS-e.';
      throw new RuntimeException($message);
    }
    return $result;
  }
}

if (!function_exists('nfse_quick_document_id')) {
  function nfse_quick_document_id(PDO $pdo, int $companyId, int $orderId, array $result = []): int {
    $docId = (int)($result['document']['id'] ?? $result['doc_id'] ?? 0);
    if ($docId > 0) return $docId;
    $doc = nfse_emitter_existing_document($pdo, $companyId, $orderId);
    return (int)($doc['id'] ?? 0);
  }
}
