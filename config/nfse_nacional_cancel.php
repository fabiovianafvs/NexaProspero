<?php
/**
 * Cancelamento de NFS-e no Ambiente Nacional (Sefin Nacional).
 *
 * Evento e101101 (pedRegEvento 1.01), assinado com o certificado A1 da empresa
 * (RSA-SHA256, como a DPS), compactado em GZip/Base64 e enviado por
 * POST {API}/nfse/{chaveAcesso}/eventos no campo pedidoRegistroEventoXmlGZipB64.
 * Em caso de erro ou resposta incerta, consulta GET {API}/nfse/{chave}/eventos/101101/1
 * para saber se o cancelamento já está registrado (evita cancelar duas vezes
 * e evita marcar como cancelada uma nota que não foi).
 */
declare(strict_types=1);

require_once __DIR__ . '/nfse_emitter.php';
require_once __DIR__ . '/nfse_pronim.php'; // helpers genéricos de evento (info/histórico)

if (!function_exists('nfse_nacional_build_cancel_event_xml')) {
  function nfse_nacional_build_cancel_event_xml(array $cfg, array $doc, string $reasonCode, string $reason): string {
    $key = nfse_emitter_digits($doc['access_key'] ?? '');
    if (strlen($key) !== 50) throw new RuntimeException('Chave de acesso da NFS-e inválida para cancelamento.');
    if (!in_array($reasonCode, ['1','2','9'], true)) throw new RuntimeException('Código do motivo de cancelamento inválido. Use 1, 2 ou 9.');
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    $len = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($len < 15 || $len > 255) throw new RuntimeException('O motivo do cancelamento deve possuir entre 15 e 255 caracteres.');
    $author = nfse_emitter_digits($cfg['company_cnpj'] ?? '');
    if (!in_array(strlen($author), [11,14], true)) throw new RuntimeException('CPF/CNPJ do autor do cancelamento inválido.');

    // Leiaute 1.01: Id = "PRE" + chave(50) + tipo do evento(6).
    $eventType = '101101';
    $env = strtoupper(trim((string)($doc['environment'] ?? 'HOMOLOGACAO')));
    // Fuso do Brasil e 2 minutos de folga: a Sefin rejeita data/hora do evento
    // posterior ao processamento (mesmo cuidado da DPS).
    $tz = nfse_emitter_timezone();
    $now = (new DateTimeImmutable('now', $tz))->modify('-2 minutes');
    $issued = trim((string)($doc['issue_datetime'] ?? ''));
    if ($issued !== '') {
      try {
        $issuedAt = new DateTimeImmutable($issued, $tz);
        if ($now < $issuedAt) $now = $issuedAt;
      } catch (Throwable $e) {}
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $root = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
    $root->setAttribute('versao', '1.01');
    $dom->appendChild($root);
    $inf = nfse_emitter_xml_element($dom, 'infPedReg');
    $inf->setAttribute('Id', 'PRE' . $key . $eventType);
    $root->appendChild($inf);
    nfse_emitter_xml_add($dom, $inf, 'tpAmb', $env === 'PRODUCAO' ? '1' : '2');
    nfse_emitter_xml_add($dom, $inf, 'verAplic', 'NEXA-NFSE-1.01');
    nfse_emitter_xml_add($dom, $inf, 'dhEvento', $now->format('Y-m-d\TH:i:sP'));
    nfse_emitter_xml_add($dom, $inf, strlen($author) === 11 ? 'CPFAutor' : 'CNPJAutor', $author);
    nfse_emitter_xml_add($dom, $inf, 'chNFSe', $key);
    $event = nfse_emitter_xml_element($dom, 'e101101');
    $inf->appendChild($event);
    nfse_emitter_xml_add($dom, $event, 'xDesc', 'Cancelamento de NFS-e');
    nfse_emitter_xml_add($dom, $event, 'cMotivo', $reasonCode);
    nfse_emitter_xml_add($dom, $event, 'xMotivo', $reason);
    $xml = $dom->saveXML($dom->documentElement, LIBXML_NOXMLDECL);
    if (!is_string($xml) || $xml === '') throw new RuntimeException('Falha ao gerar o XML do evento de cancelamento.');
    return $xml;
  }
}

if (!function_exists('nfse_nacional_sign_event_xml')) {
  function nfse_nacional_sign_event_xml(PDO $pdo, int $companyId, string $xml): string {
    nfse_emitter_require_common();
    $cert = fiscal_nfce_active_certificate($pdo, $companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    if ($password === '') throw new RuntimeException('A senha do certificado A1 não está salva.');
    $certificate = \NFePHP\Common\Certificate::readPfx($pfx, $password);
    $canonical = [false, false, null, null];
    $signed = \NFePHP\Common\Signer::sign($certificate, $xml, 'infPedReg', 'Id', OPENSSL_ALGO_SHA256, $canonical, 'pedRegEvento');
    $signed = nfse_emitter_ensure_utf8_xml($signed);
    if (!\NFePHP\Common\Signer::isSigned($signed, 'infPedReg', $canonical)) throw new RuntimeException('A assinatura do evento de cancelamento não pôde ser validada localmente.');
    $errors = nfse_emitter_verify_declared_signature($signed);
    if ($errors) throw new RuntimeException('Assinatura do evento inválida: ' . implode(' | ', $errors));
    return $signed;
  }
}

if (!function_exists('nfse_nacional_validate_cancel_event_xml')) {
  function nfse_nacional_validate_cancel_event_xml(string $xml): array {
    $errors = [];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
      foreach (libxml_get_errors() as $e) $errors[] = 'XML: ' . trim($e->message);
      libxml_clear_errors();
      return array_values(array_unique($errors ?: ['XML de evento inválido.']));
    }
    libxml_clear_errors();
    $xsd = function_exists('nfse_pronim_event_schema_path') ? nfse_pronim_event_schema_path() : '';
    if ($xsd !== '') {
      libxml_use_internal_errors(true);
      if (!$dom->schemaValidate($xsd)) foreach (libxml_get_errors() as $e) $errors[] = 'XSD evento 1.01: ' . trim($e->message);
      libxml_clear_errors();
    }
    return array_values(array_unique(array_filter($errors)));
  }
}

/** Lê o XML do evento devolvido pela Sefin (eventoXmlGZipB64) ou o próprio XML. */
if (!function_exists('nfse_nacional_event_xml_from_response')) {
  function nfse_nacional_event_xml_from_response(string $body): string {
    $trim = trim($body);
    if ($trim === '') return '';
    if ($trim[0] === '<') return $trim;
    $json = json_decode($trim, true);
    if (!is_array($json)) return '';
    $found = '';
    $walk = function ($value, $k = '') use (&$walk, &$found): void {
      if ($found !== '') return;
      if (is_array($value)) { foreach ($value as $kk => $vv) $walk($vv, (string)$kk); return; }
      if (is_string($value) && preg_match('/xml.*gzip.*b64|evento.*b64/i', (string)$k)) {
        $xml = nfse_emitter_decode_gzip_b64($value);
        if ($xml !== '') $found = $xml;
      }
    };
    $walk($json);
    return $found;
  }
}

/** Marca a NFS-e como cancelada no Nexa a partir do XML do evento confirmado. */
if (!function_exists('nfse_nacional_apply_cancelled')) {
  function nfse_nacional_apply_cancelled(PDO $pdo, int $companyId, int $docId, string $environment, string $requestXml, string $eventXml, int $httpStatus, string $message): void {
    $eventPath = nfse_emitter_store_xml_file($companyId, $environment, $docId, 'nfse-nacional-cancelamento', $eventXml);
    $pdo->prepare("UPDATE fiscal_documents SET status='CANCELADA',sefaz_status_code='101101',sefaz_status_message=?,updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([nfse_emitter_text($message, 255), $companyId, $docId]);
    $pdo->prepare("UPDATE fiscal_nfse_details SET status='CANCELADA',api_http_status=?,api_error_code=NULL,api_error_message=NULL,last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
      ->execute([$httpStatus, $companyId, $docId]);
    nfse_pronim_append_event($pdo, $companyId, $docId, '101101', $message, $requestXml, $eventXml, $eventPath !== '' ? ('XML do evento: ' . $eventPath) : 'Evento de cancelamento registrado.');
  }
}

/** Consulta se o cancelamento (101101, sequência 1) já está registrado na Sefin. */
if (!function_exists('nfse_nacional_find_cancel_event')) {
  function nfse_nacional_find_cancel_event(PDO $pdo, int $companyId, string $environment, string $key): array {
    try {
      $http = nfse_emitter_http($pdo, $companyId, $environment, 'GET', 'nfse/' . $key . '/eventos/101101/1');
    } catch (Throwable $e) {
      return ['found' => false, 'error' => $e->getMessage()];
    }
    if ((int)$http['status'] < 200 || (int)$http['status'] >= 300) return ['found' => false, 'http' => $http];
    $xml = nfse_nacional_event_xml_from_response((string)$http['body']);
    $info = nfse_pronim_event_info($xml);
    $found = $info['key'] !== '' && hash_equals($key, $info['key']) && $info['event_type'] === '101101';
    return ['found' => $found, 'xml' => $xml, 'http' => $http];
  }
}

if (!function_exists('nfse_nacional_cancel_document')) {
  function nfse_nacional_cancel_document(PDO $pdo, int $companyId, int $docId, string $reasonCode, string $reason): array {
    nfse_emitter_require_schema($pdo);
    nfse_emitter_require_runtime(true);
    $lock = 'nexa_nfse_nacional_cancel_' . $companyId . '_' . $docId;
    $st = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $st->execute([$lock]);
    $lockAcquired = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($lockAcquired !== 1) throw new RuntimeException('Este documento já está sendo processado. Aguarde e atualize a tela.');
    try {
      $doc = nfse_emitter_document_by_id($pdo, $companyId, $docId);
      if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
      $status = strtoupper(trim((string)($doc['status'] ?? '')));
      if ($status === 'CANCELADA') return ['ok' => true, 'already_cancelled' => true, 'document' => $doc, 'message' => 'A NFS-e já está cancelada no Nexa.'];
      if ($status !== 'AUTORIZADA') throw new RuntimeException('Somente NFS-e autorizada pode ser cancelada.');
      $key = nfse_emitter_digits($doc['access_key'] ?? '');
      if (strlen($key) !== 50) throw new RuntimeException('A NFS-e não possui chave de acesso válida para cancelamento.');
      $environment = strtoupper(trim((string)($doc['environment'] ?? 'HOMOLOGACAO')));
      if ($environment === 'PRODUCAO' && !nfse_emitter_production_enabled()) {
        throw new RuntimeException('Cancelamento em PRODUÇÃO não está liberado neste servidor (NFSE_ENABLE_PRODUCTION).');
      }

      $cfg = fiscal_company_config($pdo, $companyId);
      $xml = nfse_nacional_build_cancel_event_xml($cfg, $doc, $reasonCode, $reason);
      $signed = nfse_nacional_sign_event_xml($pdo, $companyId, $xml);
      $validation = nfse_nacional_validate_cancel_event_xml($signed);
      if ($validation) throw new RuntimeException('Evento de cancelamento inválido: ' . implode(' | ', $validation));
      $gzip = gzencode($signed, 9, FORCE_GZIP);
      if ($gzip === false) throw new RuntimeException('Não foi possível compactar o evento de cancelamento.');

      $http = nfse_emitter_http($pdo, $companyId, $environment, 'POST', 'nfse/' . $key . '/eventos', ['pedidoRegistroEventoXmlGZipB64' => base64_encode($gzip)]);
      $httpStatus = (int)$http['status'];

      if ($httpStatus >= 200 && $httpStatus < 300) {
        $eventXml = nfse_nacional_event_xml_from_response((string)$http['body']);
        $info = nfse_pronim_event_info($eventXml);
        if ($info['valid'] && hash_equals($key, $info['key'])) {
          nfse_nacional_apply_cancelled($pdo, $companyId, $docId, $environment, $signed, $eventXml, $httpStatus, 'NFS-e cancelada no Ambiente Nacional');
          return ['ok' => true, 'document' => nfse_emitter_document_by_id($pdo, $companyId, $docId), 'event_xml' => $eventXml, 'http' => $http, 'message' => 'NFS-e cancelada com sucesso no Ambiente Nacional.'];
        }
      }

      // Erro, resposta sem o evento ou falha de comunicação: confere na Sefin se o
      // cancelamento já existe (ex.: tentativa anterior que caiu no meio).
      $check = nfse_nacional_find_cancel_event($pdo, $companyId, $environment, $key);
      if (!empty($check['found'])) {
        nfse_nacional_apply_cancelled($pdo, $companyId, $docId, $environment, $signed, (string)$check['xml'], (int)($check['http']['status'] ?? 200), 'NFS-e cancelada no Ambiente Nacional (confirmado por consulta)');
        return ['ok' => true, 'document' => nfse_emitter_document_by_id($pdo, $companyId, $docId), 'event_xml' => (string)$check['xml'], 'http' => $http, 'message' => 'NFS-e cancelada no Ambiente Nacional (cancelamento confirmado por consulta).'];
      }

      if ((int)$http['curl_errno'] !== 0 || $httpStatus === 0) {
        $message = 'Falha de comunicação durante o cancelamento: ' . ((string)$http['curl_error'] ?: 'sem resposta HTTP') . '. Tente novamente em alguns minutos.';
        nfse_pronim_append_event($pdo, $companyId, $docId, 'CURL', $message, $signed, '', 'Resultado incerto; NFS-e mantida como AUTORIZADA no Nexa.');
        return ['ok' => false, 'ambiguous' => true, 'message' => $message, 'http' => $http];
      }
      $parsed = nfse_emitter_response_data((string)$http['body']);
      $code = $parsed['code'] !== '' ? $parsed['code'] : (string)$httpStatus;
      $message = $parsed['message'] !== '' ? $parsed['message'] : ('A Sefin Nacional não confirmou o cancelamento. HTTP ' . $httpStatus . '.');
      nfse_pronim_append_event($pdo, $companyId, $docId, $code, $message, $signed, (string)$http['body'], 'NFS-e permaneceu AUTORIZADA no Nexa.');
      return ['ok' => false, 'ambiguous' => $httpStatus >= 500, 'code' => $code, 'message' => 'Sefin Nacional: ' . $message, 'http' => $http, 'response' => $parsed];
    } finally {
      try {
        $r = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $r->execute([$lock]);
        $r->fetchColumn();
        $r->closeCursor();
      } catch (Throwable $e) {}
    }
  }
}
