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
$apply = false;
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
  if (preg_match('/^--document=(\d+)$/', (string)$arg, $m)) $documentId = (int)$m[1];
  if (preg_match('/^--number=(\d+)$/', (string)$arg, $m)) $number = (int)$m[1];
  if ((string)$arg === '--apply') $apply = true;
}
if ($companyId <= 0 || ($documentId <= 0 && $number <= 0)) {
  fwrite(STDERR, "Uso: php audit_fiscal_cancel_reconciliation_v2610.php --company=1 (--document=245 | --number=207) [--apply]\n");
  exit(2);
}

try {
  fiscal_nfce_ensure_schema($pdo);
  $where = ['company_id=?'];
  $params = [$companyId];
  if ($documentId > 0) { $where[] = 'id=?'; $params[] = $documentId; }
  else { $where[] = 'number=?'; $params[] = $number; }
  $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE " . implode(' AND ', $where) . " AND model IN ('55','65') ORDER BY id DESC LIMIT 1");
  $st->execute($params);
  $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');

  $key = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
  if (strlen($key) !== 44) throw new RuntimeException('Documento sem chave de acesso válida.');
  $cfg = fiscal_company_config($pdo, $companyId);
  $model = in_array((string)($doc['model'] ?? ''), ['55','65'], true) ? (string)$doc['model'] : '65';
  $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, $model);
  $responseXml = (string)$tools->sefazConsultaChave($key);
  $summary = fiscal_nfce_response_summary($responseXml);
  $cancel = is_array($summary['cancel_event'] ?? null) ? $summary['cancel_event'] : ['found'=>false];

  echo "NEXA FISCAL v2.6.10 - AUDITORIA/RECONCILIAÇÃO DE CANCELAMENTO\n";
  echo str_repeat('=', 88) . "\n";
  echo 'Empresa: ' . $companyId . "\n";
  echo 'Documento: ' . (int)$doc['id'] . ' | Modelo: ' . $model . ' | Série/Número: ' . (string)$doc['series'] . '/' . (string)$doc['number'] . "\n";
  echo 'Status local: ' . (string)$doc['status'] . "\n";
  echo 'Consulta SEFAZ (protocolo principal): ' . (string)($summary['code'] ?? '-') . ' - ' . (string)($summary['message'] ?? '-') . "\n";
  echo 'Evento 110111 localizado: ' . (!empty($cancel['found']) ? 'SIM' : 'NÃO') . "\n";
  if (!empty($cancel['found'])) {
    echo 'cStat do evento: ' . (string)($cancel['code'] ?? '-') . "\n";
    echo 'Motivo do evento: ' . (string)($cancel['message'] ?? '-') . "\n";
    echo 'Protocolo do cancelamento: ' . (string)($cancel['protocol'] ?? '-') . "\n";
    echo 'nSeqEvento: ' . (string)($cancel['sequence'] ?? '-') . "\n";
    echo 'Registrado em: ' . (string)($cancel['registered_at'] ?? '-') . "\n";
  }

  if (!$apply) {
    echo "\nModo auditoria: nenhuma alteração interna foi aplicada.\n";
    if (!empty($cancel['found'])) {
      echo "Execute novamente com --apply para reconciliar pedido, estoque, financeiro e caixa.\n";
    } else {
      echo "[ATENÇÃO] A SEFAZ ainda não retornou cancelamento registrado. Não reverta pedido, estoque ou caixa.\n";
    }
    exit(0);
  }

  if (empty($cancel['found'])) {
    echo "[SEM AÇÃO] Não há evento 110111 confirmado pela SEFAZ.\n";
    exit(3);
  }

  $result = fiscal_nfce_consult_document_status($pdo, $companyId, (int)$doc['id']);
  echo "\nResultado da reconciliação: " . (string)($result['status'] ?? '-') . "\n";
  echo 'Retorno: ' . (string)($result['code'] ?? '-') . ' - ' . (string)($result['message'] ?? '-') . "\n";
  if (!empty($result['internal_error'])) {
    echo '[ATENÇÃO] Cancelamento fiscal confirmado, mas houve erro interno: ' . (string)$result['internal_error'] . "\n";
    exit(4);
  }
  echo "[OK] Cancelamento fiscal e efeitos internos reconciliados.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
