<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';

$companyId = 0;
$documentId = 0;
$number = 0;
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
  if (preg_match('/^--document=(\d+)$/', (string)$arg, $m)) $documentId = (int)$m[1];
  if (preg_match('/^--number=(\d+)$/', (string)$arg, $m)) $number = (int)$m[1];
}
if ($companyId <= 0 || ($documentId <= 0 && $number <= 0)) {
  fwrite(STDERR, "Uso: php audit_fiscal_cancel_event_v269.php --company=1 (--document=207 | --number=207)\n");
  exit(2);
}

try {
  fiscal_nfce_ensure_schema($pdo);
  $where = ['company_id=?'];
  $params = [$companyId];
  if ($documentId > 0) { $where[] = 'id=?'; $params[] = $documentId; }
  else { $where[] = 'number=?'; $params[] = $number; }
  $st = $pdo->prepare('SELECT id,model,series,number,status,access_key,protocol_number,sefaz_status_code,sefaz_status_message FROM fiscal_documents WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1');
  $st->execute($params);
  $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');

  $ev = $pdo->prepare("SELECT id,event_type,event_sequence,status_code,status_message,protocol_number,request_xml,response_xml,created_at
    FROM fiscal_document_events
    WHERE company_id=? AND fiscal_document_id=? AND event_type IN ('CANCELAMENTO_NFE','CANCELAMENTO_NFCE')
    ORDER BY id DESC LIMIT 1");
  $ev->execute([$companyId, (int)$doc['id']]);
  $event = $ev->fetch(PDO::FETCH_ASSOC) ?: null;

  echo "NEXA FISCAL v2.6.9 - DIAGNÓSTICO DO EVENTO DE CANCELAMENTO\n";
  echo str_repeat('=', 82) . "\n";
  echo 'Empresa: ' . $companyId . "\n";
  echo 'Documento: ' . (int)$doc['id'] . ' | Modelo: ' . (string)$doc['model'] . ' | Série/Número: ' . (string)$doc['series'] . '/' . (string)$doc['number'] . "\n";
  echo 'Status atual: ' . (string)$doc['status'] . ' | SEFAZ: ' . (string)($doc['sefaz_status_code'] ?? '-') . ' - ' . (string)($doc['sefaz_status_message'] ?? '-') . "\n";

  if (!$event) {
    echo "Nenhum evento de cancelamento armazenado para o documento.\n";
    exit(0);
  }

  $request = (string)($event['request_xml'] ?? '');
  $response = (string)($event['response_xml'] ?? '');
  $seq = '';
  $tpEvento = '';
  if ($request !== '') {
    if (preg_match('/<nSeqEvento>\s*([0-9]+)\s*<\/nSeqEvento>/i', $request, $m)) $seq = (string)((int)$m[1]);
    if (preg_match('/<tpEvento>\s*([0-9]+)\s*<\/tpEvento>/i', $request, $m)) $tpEvento = (string)$m[1];
  }
  if ($seq === '' && preg_match('/<nSeqEvento>\s*([0-9]+)\s*<\/nSeqEvento>/i', $response, $m)) $seq = (string)((int)$m[1]);

  echo 'Último evento interno: #' . (int)$event['id'] . ' | ' . (string)$event['event_type'] . ' | sequência interna: ' . (int)$event['event_sequence'] . "\n";
  echo 'Retorno: ' . (string)($event['status_code'] ?? '-') . ' - ' . (string)($event['status_message'] ?? '-') . "\n";
  echo 'tpEvento enviado: ' . ($tpEvento !== '' ? $tpEvento : 'não armazenado') . "\n";
  echo 'nSeqEvento enviado: ' . ($seq !== '' ? $seq : 'não armazenado') . "\n";
  echo 'XML da requisição armazenado: ' . ($request !== '' ? 'SIM' : 'NÃO (evento anterior ao hotfix)') . "\n";

  if ($seq !== '' && $seq !== '1') {
    echo "[ERRO] Cancelamento 110111 deve ser enviado com nSeqEvento=1.\n";
    exit(3);
  }
  if ((string)($event['status_code'] ?? '') === '594') {
    echo "[ATENÇÃO] A SEFAZ rejeitou a tentativa. O pedido, estoque e caixa não devem ser revertidos. Instale o hotfix e tente novamente.\n";
  } elseif (in_array((string)($event['status_code'] ?? ''), ['101','135','155'], true)) {
    echo "[OK] Evento de cancelamento aceito pela SEFAZ.\n";
  }
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
