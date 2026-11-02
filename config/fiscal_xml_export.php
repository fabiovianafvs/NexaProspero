<?php
declare(strict_types=1);

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/fiscal_operational.php';

if (!function_exists('fiscal_xml_export_category_catalog')) {
  function fiscal_xml_export_category_catalog(): array {
    return [
      'nfce' => ['label' => 'NFC-e', 'model' => '65', 'status' => 'AUTORIZADA'],
      'nfe' => ['label' => 'NF-e', 'model' => '55', 'status' => 'AUTORIZADA'],
      'nfce_cancelada' => ['label' => 'NFC-e Canceladas', 'model' => '65', 'status' => 'CANCELADA'],
      'nfe_cancelada' => ['label' => 'NF-e Canceladas', 'model' => '55', 'status' => 'CANCELADA'],
      'nfce_inutilizada' => ['label' => 'NFC-e Inutilizadas', 'model' => '65', 'status' => 'INUTILIZADA'],
      'nfe_inutilizada' => ['label' => 'NF-e Inutilizadas', 'model' => '55', 'status' => 'INUTILIZADA'],
    ];
  }
}

if (!function_exists('fiscal_xml_export_options')) {
  function fiscal_xml_export_options(array $input): array {
    $catalog = fiscal_xml_export_category_catalog();
    $types = array_values(array_unique(array_filter(array_map('strval', (array)($input['types'] ?? [])), static fn(string $v): bool => isset($catalog[$v]))));
    if (!$types) $types = ['nfce', 'nfe'];

    $ini = fiscal_xml_date($input['ini'] ?? '') ?: date('Y-m-01');
    $fim = fiscal_xml_date($input['fim'] ?? '') ?: date('Y-m-d');
    if ($ini > $fim) throw new RuntimeException('A data inicial não pode ser maior que a data final.');

    return [
      'types' => $types,
      'ini' => $ini,
      'fim' => $fim,
      'series' => trim((string)($input['series'] ?? '')),
    ];
  }
}

if (!function_exists('fiscal_xml_export_series')) {
  function fiscal_xml_export_series(PDO $pdo, int $companyId): array {
    fiscal_nfce_ensure_schema($pdo);
    $series = [];
    try {
      $st = $pdo->prepare("SELECT series FROM fiscal_document_series WHERE company_id=? AND model IN ('55','65') AND COALESCE(series,'')<>'' UNION SELECT series FROM fiscal_documents WHERE company_id=? AND model IN ('55','65') AND COALESCE(series,'')<>'' ORDER BY series");
      $st->execute([$companyId, $companyId]);
      foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $value) {
        $value = trim((string)$value);
        if ($value !== '') $series[$value] = $value;
      }
    } catch (Throwable $e) {}
    uksort($series, 'strnatcasecmp');
    return array_values($series);
  }
}

if (!function_exists('fiscal_xml_export_safe_part')) {
  function fiscal_xml_export_safe_part(string $value, string $fallback = 'arquivo'): string {
    $value = trim($value);
    if ($value === '') $value = $fallback;
    $value = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $value) ?? $fallback;
    $value = trim($value, '._-');
    return $value !== '' ? $value : $fallback;
  }
}

if (!function_exists('fiscal_xml_export_tag_value')) {
  function fiscal_xml_export_tag_value(string $xml, string $tag): string {
    if (trim($xml) === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tag)) return '';
    $pattern = '~<(?:[A-Za-z0-9_]+:)?' . preg_quote($tag, '~') . '(?:\s[^>]*)?>(.*?)</(?:[A-Za-z0-9_]+:)?' . preg_quote($tag, '~') . '>~si';
    if (!preg_match($pattern, $xml, $m)) return '';
    return trim(html_entity_decode(strip_tags((string)$m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
  }
}

if (!function_exists('fiscal_xml_export_read_path')) {
  function fiscal_xml_export_read_path(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    $candidates = [$path];
    if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
      $root = dirname(__DIR__);
      $candidates[] = $root . '/' . ltrim($path, '/\\');
      if (!empty($_SERVER['DOCUMENT_ROOT'])) $candidates[] = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/' . ltrim($path, '/\\');
    }
    foreach (array_unique($candidates) as $candidate) {
      if (is_file($candidate) && is_readable($candidate)) {
        $raw = @file_get_contents($candidate);
        if (is_string($raw) && trim($raw) !== '') return $raw;
      }
    }
    return '';
  }
}

if (!function_exists('fiscal_xml_export_document_xml')) {
  function fiscal_xml_export_document_xml(array $doc): string {
    $raw = trim((string)($doc['xml_raw'] ?? ''));
    if ($raw !== '') return $raw;
    return fiscal_xml_export_read_path((string)($doc['xml_path'] ?? ''));
  }
}

if (!function_exists('fiscal_xml_export_add_entry')) {
  function fiscal_xml_export_add_entry(array &$entries, string $name, string $content): bool {
    $content = trim($content);
    if ($content === '') return false;
    $name = str_replace('\\', '/', trim($name, '/\\'));
    $parts = array_values(array_filter(explode('/', $name), static fn(string $p): bool => $p !== '' && $p !== '.' && $p !== '..'));
    $safe = [];
    foreach ($parts as $i => $part) $safe[] = fiscal_xml_export_safe_part($part, $i === count($parts) - 1 ? 'arquivo.xml' : 'pasta');
    $name = implode('/', $safe);
    if (!str_ends_with(strtolower($name), '.xml')) $name .= '.xml';

    $base = preg_replace('/\.xml$/i', '', $name) ?: $name;
    $candidate = $name;
    $seq = 2;
    while (isset($entries[$candidate])) {
      if (hash('sha256', (string)$entries[$candidate]) === hash('sha256', $content)) return false;
      $candidate = $base . '_' . $seq . '.xml';
      $seq++;
    }
    $entries[$candidate] = $content;
    return true;
  }
}

if (!function_exists('fiscal_xml_export_cancel_events')) {
  function fiscal_xml_export_cancel_events(PDO $pdo, int $companyId, array $docIds): array {
    $out = [];
    $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds), static fn(int $v): bool => $v > 0)));
    foreach (array_chunk($docIds, 400) as $chunk) {
      $ph = implode(',', array_fill(0, count($chunk), '?'));
      $params = array_merge([$companyId], $chunk);
      $st = $pdo->prepare("SELECT fiscal_document_id,request_xml,response_xml FROM fiscal_document_events WHERE company_id=? AND fiscal_document_id IN ($ph) AND UPPER(event_type) LIKE 'CANCELAMENTO_%' ORDER BY COALESCE(event_datetime,created_at) DESC, id DESC");
      $st->execute($params);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $docId = (int)($row['fiscal_document_id'] ?? 0);
        if ($docId > 0 && !isset($out[$docId])) $out[$docId] = $row;
      }
    }
    return $out;
  }
}

if (!function_exists('fiscal_xml_export_event_model')) {
  function fiscal_xml_export_event_model(array $row): string {
    $model = trim((string)($row['model'] ?? ''));
    if (in_array($model, ['55','65'], true)) return $model;
    $type = strtoupper((string)($row['event_type'] ?? ''));
    if (str_contains($type, 'NFCE')) return '65';
    if (str_contains($type, 'NFE')) return '55';
    foreach (['request_xml','response_xml'] as $field) {
      $model = fiscal_xml_export_tag_value((string)($row[$field] ?? ''), 'mod');
      if (in_array($model, ['55','65'], true)) return $model;
    }
    return '';
  }
}

if (!function_exists('fiscal_xml_export_event_series')) {
  function fiscal_xml_export_event_series(array $row): string {
    $series = trim((string)($row['series'] ?? ''));
    if ($series !== '') return $series;
    foreach (['request_xml','response_xml'] as $field) {
      $series = fiscal_xml_export_tag_value((string)($row[$field] ?? ''), 'serie');
      if ($series !== '') return $series;
    }
    return '';
  }
}

if (!function_exists('fiscal_xml_export_try_cancelled_xml')) {
  function fiscal_xml_export_try_cancelled_xml(string $documentXml, string $eventXml): string {
    if (trim($documentXml) === '' || trim($eventXml) === '') return '';
    try {
      if (class_exists('NFePHP\\NFe\\Complements') && class_exists('DOMDocument')) {
        return (string)\NFePHP\NFe\Complements::cancelRegister($documentXml, $eventXml);
      }
    } catch (Throwable $e) {}
    return '';
  }
}

if (!function_exists('fiscal_xml_export_try_inutilization_xml')) {
  function fiscal_xml_export_try_inutilization_xml(string $requestXml, string $responseXml): string {
    if (trim($requestXml) === '' || trim($responseXml) === '') return '';
    try {
      if (class_exists('NFePHP\\NFe\\Complements') && class_exists('DOMDocument')) {
        return (string)\NFePHP\NFe\Complements::toAuthorize($requestXml, $responseXml);
      }
    } catch (Throwable $e) {}
    return '';
  }
}


/** Exclui NF-e descartada dos ZIPs e fechamentos, inclusive se status
 * tiver sido alterado indevidamente por uma inutilizacao antiga. */
if (!function_exists('fiscal_xml_export_discard_exclusion_sql')) {
  function fiscal_xml_export_discard_exclusion_sql(): string {
    return "UPPER(TRIM(COALESCE(fiscal_documents.status,''))) <> 'DESCARTADA'"
      . " AND NOT EXISTS (SELECT 1 FROM fiscal_document_events discard_event"
      . " WHERE discard_event.company_id=fiscal_documents.company_id"
      . " AND discard_event.fiscal_document_id=fiscal_documents.id"
      . " AND discard_event.event_type='DESCARTE_REJEICAO_LOCAL')";
  }
}

if (!function_exists('fiscal_xml_export_collect')) {
  function fiscal_xml_export_collect(PDO $pdo, int $companyId, array $options): array {
    fiscal_nfce_ensure_schema($pdo);
    $catalog = fiscal_xml_export_category_catalog();
    $types = (array)($options['types'] ?? []);
    $ini = (string)$options['ini'];
    $fim = (string)$options['fim'];
    $seriesFilter = trim((string)($options['series'] ?? ''));
    $endExclusive = date('Y-m-d', strtotime($fim . ' +1 day')) . ' 00:00:00';
    $startDateTime = $ini . ' 00:00:00';

    $entries = [];
    $stats = ['documents' => 0, 'events' => 0, 'files' => 0, 'skipped' => 0, 'by_type' => []];
    foreach ($types as $type) $stats['by_type'][$type] = 0;

    $docConditions = [];
    foreach ($types as $type) {
      $cfg = $catalog[$type] ?? null;
      if (!$cfg) continue;
      $model = (string)$cfg['model'];
      $status = (string)$cfg['status'];
      // Um documento marcado INUTILIZADA pode conter XML de NF-e rejeitada.
      // O unico XML valido de inutilizacao vem do evento homologado pela SEFAZ.
      if ($status === 'INUTILIZADA') continue;
      $docConditions[] = "(model='" . $model . "' AND UPPER(COALESCE(status,''))='" . $status . "')";
    }

    $documents = [];
    if ($docConditions) {
      $sql = "SELECT id,model,series,number,access_key,status,issue_datetime,created_at,xml_raw,xml_path FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND (" . implode(' OR ', $docConditions) . ") AND " . fiscal_xml_export_discard_exclusion_sql() . " AND COALESCE(issue_datetime,created_at)>=? AND COALESCE(issue_datetime,created_at)<?";
      $params = [$companyId, $startDateTime, $endExclusive];
      if ($seriesFilter !== '') { $sql .= ' AND COALESCE(series,\'\')=?'; $params[] = $seriesFilter; }
      $sql .= ' ORDER BY model,series,number,id';
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $documents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $cancelDocIds = [];
    foreach ($documents as $doc) if (strtoupper((string)$doc['status']) === 'CANCELADA') $cancelDocIds[] = (int)$doc['id'];
    $cancelEvents = $cancelDocIds ? fiscal_xml_export_cancel_events($pdo, $companyId, $cancelDocIds) : [];

    foreach ($documents as $doc) {
      $model = (string)($doc['model'] ?? '');
      $status = strtoupper((string)($doc['status'] ?? ''));
      $type = $status === 'AUTORIZADA' ? ($model === '65' ? 'nfce' : 'nfe') : ($status === 'CANCELADA' ? ($model === '65' ? 'nfce_cancelada' : 'nfe_cancelada') : ($model === '65' ? 'nfce_inutilizada' : 'nfe_inutilizada'));
      if (!in_array($type, $types, true)) continue;

      $labelDir = $model === '65' ? 'NFCe' : 'NFe';
      $statusDir = $status === 'AUTORIZADA' ? 'Autorizadas' : ($status === 'CANCELADA' ? 'Canceladas' : 'Inutilizadas');
      $key = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? '')) ?? '';
      $base = strlen($key) === 44 ? $key : ('modelo_' . $model . '_serie_' . fiscal_xml_export_safe_part((string)($doc['series'] ?? ''), '0') . '_numero_' . fiscal_xml_export_safe_part((string)($doc['number'] ?? ''), (string)$doc['id']));
      $documentXml = fiscal_xml_export_document_xml($doc);
      $added = false;

      if ($status === 'CANCELADA') {
        $event = $cancelEvents[(int)$doc['id']] ?? [];
        $eventXml = trim((string)($event['response_xml'] ?? '')) ?: trim((string)($event['request_xml'] ?? ''));
        $cancelledXml = fiscal_xml_export_try_cancelled_xml($documentXml, $eventXml);
        if ($cancelledXml !== '') {
          $added = fiscal_xml_export_add_entry($entries, $labelDir . '/' . $statusDir . '/' . $base . '-procNFe-cancelada.xml', $cancelledXml) || $added;
        } else {
          $added = fiscal_xml_export_add_entry($entries, $labelDir . '/' . $statusDir . '/' . $base . '-procNFe.xml', $documentXml) || $added;
          if ($eventXml !== '') $added = fiscal_xml_export_add_entry($entries, $labelDir . '/' . $statusDir . '/' . $base . '-evento-cancelamento.xml', $eventXml) || $added;
        }
      } else {
        $suffix = $status === 'AUTORIZADA' ? '-procNFe.xml' : '-inutilizacao.xml';
        $added = fiscal_xml_export_add_entry($entries, $labelDir . '/' . $statusDir . '/' . $base . $suffix, $documentXml) || $added;
      }

      $stats['documents']++;
      $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
      if (!$added) $stats['skipped']++;
    }

    $needInut65 = in_array('nfce_inutilizada', $types, true);
    $needInut55 = in_array('nfe_inutilizada', $types, true);
    if ($needInut65 || $needInut55) {
      $st = $pdo->prepare("SELECT e.*,d.model,d.series,d.number,d.access_key FROM fiscal_document_events e LEFT JOIN fiscal_documents d ON d.id=e.fiscal_document_id AND d.company_id=e.company_id WHERE e.company_id=? AND UPPER(COALESCE(e.event_type,'')) LIKE '%INUTIL%' AND TRIM(COALESCE(e.status_code,''))='102' AND NULLIF(TRIM(COALESCE(e.protocol_number,'')),'') IS NOT NULL AND COALESCE(e.event_datetime,e.created_at)>=? AND COALESCE(e.event_datetime,e.created_at)<? ORDER BY COALESCE(e.event_datetime,e.created_at),e.id");
      $st->execute([$companyId, $startDateTime, $endExclusive]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $event) {
        $model = fiscal_xml_export_event_model($event);
        if (($model === '65' && !$needInut65) || ($model === '55' && !$needInut55) || !in_array($model, ['55','65'], true)) continue;
        $eventSeries = fiscal_xml_export_event_series($event);
        if ($seriesFilter !== '' && $eventSeries !== $seriesFilter) continue;
        $type = $model === '65' ? 'nfce_inutilizada' : 'nfe_inutilizada';
        $labelDir = $model === '65' ? 'NFCe' : 'NFe';
        $requestXml = trim((string)($event['request_xml'] ?? ''));
        $responseXml = trim((string)($event['response_xml'] ?? ''));
        $processed = fiscal_xml_export_try_inutilization_xml($requestXml, $responseXml);
        $nIni = fiscal_xml_export_tag_value($requestXml . $responseXml, 'nNFIni');
        $nFim = fiscal_xml_export_tag_value($requestXml . $responseXml, 'nNFFin');
        $base = 'modelo_' . $model . '_serie_' . fiscal_xml_export_safe_part($eventSeries, '0') . '_numeros_' . fiscal_xml_export_safe_part($nIni, '0') . '_a_' . fiscal_xml_export_safe_part($nFim, $nIni !== '' ? $nIni : (string)($event['id'] ?? '0'));
        $added = false;
        if ($processed !== '') {
          $added = fiscal_xml_export_add_entry($entries, $labelDir . '/Inutilizadas/' . $base . '-procInutNFe.xml', $processed) || $added;
        } else {
          if ($requestXml !== '') $added = fiscal_xml_export_add_entry($entries, $labelDir . '/Inutilizadas/' . $base . '-pedido-inutilizacao.xml', $requestXml) || $added;
          if ($responseXml !== '') $added = fiscal_xml_export_add_entry($entries, $labelDir . '/Inutilizadas/' . $base . '-retorno-inutilizacao.xml', $responseXml) || $added;
        }
        $stats['events']++;
        $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
        if (!$added) $stats['skipped']++;
      }
    }

    $stats['files'] = count($entries);
    return ['entries' => $entries, 'stats' => $stats];
  }
}

if (!function_exists('fiscal_xml_export_dos_datetime')) {
  function fiscal_xml_export_dos_datetime(?int $timestamp = null): array {
    $timestamp = $timestamp ?: time();
    $parts = getdate($timestamp);
    $year = max(1980, min(2107, (int)$parts['year']));
    $dosTime = (((int)$parts['hours']) << 11) | (((int)$parts['minutes']) << 5) | ((int)floor(((int)$parts['seconds']) / 2));
    $dosDate = (($year - 1980) << 9) | (((int)$parts['mon']) << 5) | (int)$parts['mday'];
    return [$dosTime, $dosDate];
  }
}


if (!function_exists('fiscal_xml_export_zip_archive_name')) {
  function fiscal_xml_export_zip_archive_name(string $name, string $fallback = 'arquivo'): string {
    $name = str_replace('\\', '/', trim($name, '/\\'));
    $parts = array_values(array_filter(explode('/', $name), static fn(string $p): bool => $p !== '' && $p !== '.' && $p !== '..'));
    $safe = [];
    foreach ($parts as $i => $part) $safe[] = fiscal_xml_export_safe_part($part, $i === count($parts) - 1 ? $fallback : 'pasta');
    $name = implode('/', $safe);
    return $name !== '' ? $name : $fallback;
  }
}

if (!function_exists('fiscal_xml_export_zip_writer_open')) {
  /** @return array{handle:resource,central:array<int,array<int,mixed>>,dos_time:int,dos_date:int,closed:bool} */
  function fiscal_xml_export_zip_writer_open(string $targetFile): array {
    $handle = @fopen($targetFile, 'w+b');
    if (!$handle) throw new RuntimeException('Não foi possível criar o arquivo ZIP temporário.');
    [$dosTime, $dosDate] = fiscal_xml_export_dos_datetime();
    return ['handle'=>$handle,'central'=>[],'dos_time'=>$dosTime,'dos_date'=>$dosDate,'closed'=>false];
  }
}

if (!function_exists('fiscal_xml_export_zip_writer_add_string')) {
  function fiscal_xml_export_zip_writer_add_string(array &$state, string $name, string $content): void {
    $handle = $state['handle'] ?? null;
    if (!is_resource($handle)) throw new RuntimeException('Arquivo ZIP não está disponível para gravação.');
    if (count((array)($state['central'] ?? [])) >= 65535) throw new RuntimeException('O ZIP excedeu a quantidade máxima de arquivos suportada.');

    $name = fiscal_xml_export_zip_archive_name($name, 'arquivo');
    $uncompressedSize = strlen($content);
    if ($uncompressedSize > 0xFFFFFFFF) throw new RuntimeException('Arquivo muito grande para o formato ZIP utilizado.');

    $compressed = function_exists('gzdeflate') ? gzdeflate($content, 6) : false;
    $method = is_string($compressed) ? 8 : 0;
    if (!is_string($compressed)) $compressed = $content;
    $compressedSize = strlen($compressed);
    if ($compressedSize > 0xFFFFFFFF) throw new RuntimeException('Arquivo compactado muito grande para o formato ZIP utilizado.');

    $crc = crc32($content);
    if ($crc < 0) $crc += 4294967296;
    $offset = ftell($handle);
    if ($offset === false || $offset > 0xFFFFFFFF) throw new RuntimeException('O ZIP excedeu o tamanho máximo suportado.');
    $nameLen = strlen($name);
    if ($nameLen > 65535) throw new RuntimeException('Nome de arquivo muito grande para o ZIP.');

    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, (int)$state['dos_time'], (int)$state['dos_date'], $crc, $compressedSize, $uncompressedSize, $nameLen, 0);
    if (fwrite($handle, $local . $name . $compressed) === false) throw new RuntimeException('Falha ao gravar conteúdo no ZIP.');
    $state['central'][] = [$name, $method, $crc, $compressedSize, $uncompressedSize, $offset];
  }
}

if (!function_exists('fiscal_xml_export_zip_writer_add_file')) {
  function fiscal_xml_export_zip_writer_add_file(array &$state, string $name, string $sourceFile): void {
    $handle = $state['handle'] ?? null;
    if (!is_resource($handle)) throw new RuntimeException('Arquivo ZIP não está disponível para gravação.');
    if (count((array)($state['central'] ?? [])) >= 65535) throw new RuntimeException('O ZIP excedeu a quantidade máxima de arquivos suportada.');

    $real = realpath($sourceFile);
    if (!$real || !is_file($real) || !is_readable($real)) throw new RuntimeException('Arquivo não encontrado para inclusão no pacote: ' . $sourceFile);
    $size = filesize($real);
    if ($size === false || $size > 0xFFFFFFFF) throw new RuntimeException('Arquivo muito grande para o formato ZIP utilizado: ' . basename($real));

    $crcHex = hash_file('crc32b', $real);
    if (!is_string($crcHex) || $crcHex === '') throw new RuntimeException('Não foi possível calcular a integridade do arquivo: ' . basename($real));
    $crc = (int)hexdec($crcHex);

    $name = fiscal_xml_export_zip_archive_name($name, basename($real));
    $nameLen = strlen($name);
    if ($nameLen > 65535) throw new RuntimeException('Nome de arquivo muito grande para o ZIP.');
    $offset = ftell($handle);
    if ($offset === false || $offset > 0xFFFFFFFF) throw new RuntimeException('O ZIP excedeu o tamanho máximo suportado.');

    // Arquivos já gerados (PDF/ZIP/TXT) entram como "store" para não serem carregados
    // e recomprimidos integralmente na memória.
    $method = 0;
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, (int)$state['dos_time'], (int)$state['dos_date'], $crc, (int)$size, (int)$size, $nameLen, 0);
    if (fwrite($handle, $local . $name) === false) throw new RuntimeException('Falha ao gravar cabeçalho no ZIP.');

    $source = @fopen($real, 'rb');
    if (!$source) throw new RuntimeException('Não foi possível ler o arquivo para inclusão no pacote: ' . basename($real));
    try {
      $copied = stream_copy_to_stream($source, $handle);
      if ($copied === false || (int)$copied !== (int)$size) throw new RuntimeException('Falha ao copiar arquivo para o pacote: ' . basename($real));
    } finally {
      fclose($source);
    }
    $state['central'][] = [$name, $method, $crc, (int)$size, (int)$size, $offset];
  }
}

if (!function_exists('fiscal_xml_export_zip_writer_close')) {
  function fiscal_xml_export_zip_writer_close(array &$state): void {
    if (!empty($state['closed'])) return;
    $handle = $state['handle'] ?? null;
    if (!is_resource($handle)) { $state['closed'] = true; return; }

    $centralOffset = ftell($handle);
    if ($centralOffset === false || $centralOffset > 0xFFFFFFFF) throw new RuntimeException('O ZIP excedeu o tamanho máximo suportado.');
    foreach ((array)$state['central'] as [$name, $method, $crc, $compressedSize, $uncompressedSize, $offset]) {
      $nameLen = strlen((string)$name);
      $header = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, (int)$method, (int)$state['dos_time'], (int)$state['dos_date'], $crc, $compressedSize, $uncompressedSize, $nameLen, 0, 0, 0, 0, 0, $offset);
      if (fwrite($handle, $header . $name) === false) throw new RuntimeException('Falha ao finalizar o ZIP.');
    }
    $end = ftell($handle);
    if ($end === false) throw new RuntimeException('Falha ao finalizar o ZIP.');
    $centralSize = $end - $centralOffset;
    $count = count((array)$state['central']);
    if (fwrite($handle, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0)) === false) throw new RuntimeException('Falha ao finalizar o ZIP.');
    fclose($handle);
    $state['closed'] = true;
  }
}

if (!function_exists('fiscal_xml_export_stream_to_zip')) {
  /**
   * Exporta XMLs diretamente para o ZIP sem manter o conteúdo de todos os documentos
   * simultaneamente em memória. Retorna as mesmas estatísticas principais do coletor.
   *
   * @return array{documents:int,events:int,files:int,skipped:int,by_type:array<string,int>}
   */
  function fiscal_xml_export_stream_to_zip(PDO $pdo, int $companyId, array $options, string $targetFile, array $extra = []): array {
    fiscal_nfce_ensure_schema($pdo);
    $parsed = fiscal_xml_export_options($options);
    $catalog = fiscal_xml_export_category_catalog();
    $types = (array)$parsed['types'];
    $ini = (string)$parsed['ini'];
    $fim = (string)$parsed['fim'];
    $seriesFilter = trim((string)$parsed['series']);
    $endExclusive = date('Y-m-d', strtotime($fim . ' +1 day')) . ' 00:00:00';
    $startDateTime = $ini . ' 00:00:00';

    $stats = ['documents'=>0,'events'=>0,'files'=>0,'skipped'=>0,'by_type'=>[]];
    foreach ($types as $type) $stats['by_type'][$type] = 0;

    $docConditions = [];
    foreach ($types as $type) {
      $cfg = $catalog[$type] ?? null;
      if (!$cfg) continue;
      $model = (string)$cfg['model'];
      $status = (string)$cfg['status'];
      // Somente eventos de inutilizacao homologados podem ser exportados;
      // nao reutilizar xml_raw da tentativa NF-e rejeitada nesta categoria.
      if ($status === 'INUTILIZADA') continue;
      $docConditions[] = "(model='" . $model . "' AND UPPER(COALESCE(status,''))='" . $status . "')";
    }

    $documents = [];
    if ($docConditions) {
      // Não seleciona xml_raw. O XML é buscado individualmente somente no momento
      // em que será escrito no ZIP.
      $sql = "SELECT id,model,series,number,access_key,status,issue_datetime,created_at,xml_path
        FROM fiscal_documents
        WHERE company_id=? AND direction='SAIDA' AND (" . implode(' OR ', $docConditions) . ")
          AND " . fiscal_xml_export_discard_exclusion_sql() . "
          AND COALESCE(issue_datetime,created_at)>=? AND COALESCE(issue_datetime,created_at)<?";
      $params = [$companyId,$startDateTime,$endExclusive];
      if ($seriesFilter !== '') { $sql .= " AND COALESCE(series,'')=?"; $params[] = $seriesFilter; }
      $sql .= ' ORDER BY model,series,number,id';
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $documents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $cancelDocIds = [];
    foreach ($documents as $doc) if (strtoupper((string)($doc['status'] ?? '')) === 'CANCELADA') $cancelDocIds[] = (int)$doc['id'];
    $cancelEvents = $cancelDocIds ? fiscal_xml_export_cancel_events($pdo,$companyId,$cancelDocIds) : [];

    $rawSt = $pdo->prepare('SELECT xml_raw FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
    $readDocumentXml = static function(array $doc) use ($pdo,$companyId,$rawSt): string {
      $fromPath = fiscal_xml_export_read_path((string)($doc['xml_path'] ?? ''));
      if ($fromPath !== '') return $fromPath;
      $rawSt->execute([$companyId,(int)($doc['id'] ?? 0)]);
      $raw = $rawSt->fetchColumn();
      $rawSt->closeCursor();
      return is_string($raw) ? $raw : '';
    };

    $writer = fiscal_xml_export_zip_writer_open($targetFile);
    $seen = [];
    $addXml = static function(string $name, string $content) use (&$writer,&$seen,&$stats): bool {
      $content = trim($content);
      if ($content === '') return false;
      $name = str_replace('\\', '/', trim($name, '/\\'));
      $parts = array_values(array_filter(explode('/', $name), static fn(string $p): bool => $p !== '' && $p !== '.' && $p !== '..'));
      $safe = [];
      foreach ($parts as $i => $part) $safe[] = fiscal_xml_export_safe_part($part, $i === count($parts) - 1 ? 'arquivo.xml' : 'pasta');
      $name = implode('/', $safe);
      if (!str_ends_with(strtolower($name), '.xml')) $name .= '.xml';

      $hash = hash('sha256', $content);
      $base = preg_replace('/\.xml$/i', '', $name) ?: $name;
      $candidate = $name;
      $seq = 2;
      while (isset($seen[$candidate])) {
        if ($seen[$candidate] === $hash) return false;
        $candidate = $base . '_' . $seq . '.xml';
        $seq++;
      }
      $seen[$candidate] = $hash;
      fiscal_xml_export_zip_writer_add_string($writer,$candidate,$content);
      $stats['files']++;
      return true;
    };

    $completed = false;
    try {
      foreach ($documents as $doc) {
        $model = (string)($doc['model'] ?? '');
        $status = strtoupper((string)($doc['status'] ?? ''));
        $type = $status === 'AUTORIZADA' ? ($model === '65' ? 'nfce' : 'nfe') : ($status === 'CANCELADA' ? ($model === '65' ? 'nfce_cancelada' : 'nfe_cancelada') : ($model === '65' ? 'nfce_inutilizada' : 'nfe_inutilizada'));
        if (!in_array($type,$types,true)) continue;

        $labelDir = $model === '65' ? 'NFCe' : 'NFe';
        $statusDir = $status === 'AUTORIZADA' ? 'Autorizadas' : ($status === 'CANCELADA' ? 'Canceladas' : 'Inutilizadas');
        $key = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? '')) ?? '';
        $base = strlen($key) === 44 ? $key : ('modelo_' . $model . '_serie_' . fiscal_xml_export_safe_part((string)($doc['series'] ?? ''), '0') . '_numero_' . fiscal_xml_export_safe_part((string)($doc['number'] ?? ''), (string)$doc['id']));
        $documentXml = $readDocumentXml($doc);
        $added = false;

        if ($status === 'CANCELADA') {
          $event = $cancelEvents[(int)$doc['id']] ?? [];
          $eventXml = trim((string)($event['response_xml'] ?? '')) ?: trim((string)($event['request_xml'] ?? ''));
          $cancelledXml = fiscal_xml_export_try_cancelled_xml($documentXml,$eventXml);
          if ($cancelledXml !== '') {
            $added = $addXml($labelDir . '/' . $statusDir . '/' . $base . '-procNFe-cancelada.xml',$cancelledXml) || $added;
          } else {
            $added = $addXml($labelDir . '/' . $statusDir . '/' . $base . '-procNFe.xml',$documentXml) || $added;
            if ($eventXml !== '') $added = $addXml($labelDir . '/' . $statusDir . '/' . $base . '-evento-cancelamento.xml',$eventXml) || $added;
          }
        } else {
          $suffix = $status === 'AUTORIZADA' ? '-procNFe.xml' : '-inutilizacao.xml';
          $added = $addXml($labelDir . '/' . $statusDir . '/' . $base . $suffix,$documentXml) || $added;
        }

        $stats['documents']++;
        $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
        if (!$added) $stats['skipped']++;
        unset($documentXml,$cancelledXml,$eventXml);
      }
      unset($documents,$cancelEvents);

      $needInut65 = in_array('nfce_inutilizada',$types,true);
      $needInut55 = in_array('nfe_inutilizada',$types,true);
      if ($needInut65 || $needInut55) {
        $st = $pdo->prepare("SELECT e.id,e.event_type,e.request_xml,e.response_xml,e.event_datetime,e.created_at,d.model,d.series,d.number,d.access_key
          FROM fiscal_document_events e
          LEFT JOIN fiscal_documents d ON d.id=e.fiscal_document_id AND d.company_id=e.company_id
          WHERE e.company_id=? AND UPPER(COALESCE(e.event_type,'')) LIKE '%INUTIL%'
            AND TRIM(COALESCE(e.status_code,''))='102'
            AND NULLIF(TRIM(COALESCE(e.protocol_number,'')),'') IS NOT NULL
            AND COALESCE(e.event_datetime,e.created_at)>=? AND COALESCE(e.event_datetime,e.created_at)<?
          ORDER BY COALESCE(e.event_datetime,e.created_at),e.id");
        $st->execute([$companyId,$startDateTime,$endExclusive]);
        while ($event = $st->fetch(PDO::FETCH_ASSOC)) {
          $model = fiscal_xml_export_event_model($event);
          if (($model === '65' && !$needInut65) || ($model === '55' && !$needInut55) || !in_array($model,['55','65'],true)) continue;
          $eventSeries = fiscal_xml_export_event_series($event);
          if ($seriesFilter !== '' && $eventSeries !== $seriesFilter) continue;
          $type = $model === '65' ? 'nfce_inutilizada' : 'nfe_inutilizada';
          $labelDir = $model === '65' ? 'NFCe' : 'NFe';
          $requestXml = trim((string)($event['request_xml'] ?? ''));
          $responseXml = trim((string)($event['response_xml'] ?? ''));
          $processed = fiscal_xml_export_try_inutilization_xml($requestXml,$responseXml);
          $nIni = fiscal_xml_export_tag_value($requestXml . $responseXml,'nNFIni');
          $nFim = fiscal_xml_export_tag_value($requestXml . $responseXml,'nNFFin');
          $base = 'modelo_' . $model . '_serie_' . fiscal_xml_export_safe_part($eventSeries,'0') . '_numeros_' . fiscal_xml_export_safe_part($nIni,'0') . '_a_' . fiscal_xml_export_safe_part($nFim,$nIni !== '' ? $nIni : (string)($event['id'] ?? '0'));
          $added = false;
          if ($processed !== '') {
            $added = $addXml($labelDir . '/Inutilizadas/' . $base . '-procInutNFe.xml',$processed) || $added;
          } else {
            if ($requestXml !== '') $added = $addXml($labelDir . '/Inutilizadas/' . $base . '-pedido-inutilizacao.xml',$requestXml) || $added;
            if ($responseXml !== '') $added = $addXml($labelDir . '/Inutilizadas/' . $base . '-retorno-inutilizacao.xml',$responseXml) || $added;
          }
          $stats['events']++;
          $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
          if (!$added) $stats['skipped']++;
          unset($requestXml,$responseXml,$processed);
        }
        $st->closeCursor();
      }

      if ((int)$stats['files'] === 0) {
        $message = (string)($extra['empty_message'] ?? "Nenhum XML foi localizado.\r\nPeríodo: {$ini} a {$fim}\r\n");
        fiscal_xml_export_zip_writer_add_string($writer,'LEIA-ME.txt',$message);
      }
      fiscal_xml_export_zip_writer_close($writer);
      $completed = true;
      return $stats;
    } finally {
      if (!$completed) {
        $handle = $writer['handle'] ?? null;
        if (is_resource($handle)) @fclose($handle);
        @unlink($targetFile);
      }
    }
  }
}

if (!function_exists('fiscal_xml_export_build_zip_from_files')) {
  /**
   * Monta um ZIP a partir de arquivos existentes sem ler todos eles para a memória.
   * @param array<string,string> $entries nome-no-zip => caminho-absoluto
   */
  function fiscal_xml_export_build_zip_from_files(array $entries, string $targetFile): void {
    $writer = fiscal_xml_export_zip_writer_open($targetFile);
    $completed = false;
    try {
      foreach ($entries as $name => $sourceFile) {
        fiscal_xml_export_zip_writer_add_file($writer,(string)$name,(string)$sourceFile);
      }
      fiscal_xml_export_zip_writer_close($writer);
      $completed = true;
    } finally {
      if (!$completed) {
        $handle = $writer['handle'] ?? null;
        if (is_resource($handle)) @fclose($handle);
        @unlink($targetFile);
      }
    }
  }
}

if (!function_exists('fiscal_xml_export_build_zip')) {
  function fiscal_xml_export_build_zip(array $entries, string $targetFile): void {
    $handle = @fopen($targetFile, 'w+b');
    if (!$handle) throw new RuntimeException('Não foi possível criar o arquivo ZIP temporário.');
    $central = [];
    [$dosTime, $dosDate] = fiscal_xml_export_dos_datetime();
    try {
      foreach ($entries as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        $content = (string)$content;
        $uncompressedSize = strlen($content);
        $compressed = function_exists('gzdeflate') ? gzdeflate($content, 6) : false;
        $method = is_string($compressed) ? 8 : 0;
        if (!is_string($compressed)) $compressed = $content;
        $compressedSize = strlen($compressed);
        $crc = crc32($content);
        if ($crc < 0) $crc += 4294967296;
        $offset = ftell($handle);
        $nameLen = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, $nameLen, 0);
        fwrite($handle, $local . $name . $compressed);
        $central[] = [$name, $method, $crc, $compressedSize, $uncompressedSize, $offset];
      }
      $centralOffset = ftell($handle);
      foreach ($central as [$name, $method, $crc, $compressedSize, $uncompressedSize, $offset]) {
        $nameLen = strlen($name);
        $header = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, $nameLen, 0, 0, 0, 0, 0, $offset);
        fwrite($handle, $header . $name);
      }
      $centralSize = ftell($handle) - $centralOffset;
      $count = count($central);
      fwrite($handle, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
    } finally {
      fclose($handle);
    }
  }
}

if (!function_exists('fiscal_xml_export_download')) {
  function fiscal_xml_export_download(string $filename, array $entries): never {
    if (!$entries) throw new RuntimeException('Nenhum XML foi localizado para os filtros selecionados.');
    $tmp = tempnam(sys_get_temp_dir(), 'nexa_xml_');
    if ($tmp === false) throw new RuntimeException('Não foi possível preparar o arquivo ZIP.');
    try {
      fiscal_xml_export_build_zip($entries, $tmp);
      $filename = fiscal_xml_export_safe_part($filename, 'XML_FISCAIS.zip');
      if (!str_ends_with(strtolower($filename), '.zip')) $filename .= '.zip';
      while (ob_get_level() > 0) @ob_end_clean();
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . (string)filesize($tmp));
      header('Cache-Control: private, no-store, max-age=0');
      readfile($tmp);
    } finally {
      @unlink($tmp);
    }
    exit;
  }
}
