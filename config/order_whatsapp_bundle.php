<?php
declare(strict_types=1);

/**
 * Envio de documentos do pedido pelo WhatsApp em uma única tela
 * (Consulta de pedidos > WhatsApp): PDF do pedido, boletos e notas fiscais.
 *
 * Reaproveita exatamente os mesmos montadores de PDF e o mesmo serviço de envio
 * das telas individuais (financeiro_boleto_whatsapp.php e
 * fiscal_documento_whatsapp.php). Somente leitura do banco; nada é emitido.
 */

require_once __DIR__ . '/whatsapp_service.php';
require_once __DIR__ . '/boleto_document.php';
require_once __DIR__ . '/fiscal_document_pdf.php';
if (!function_exists('fiscal_nfce_document_participant')) require_once __DIR__ . '/fiscal_operational.php';

if (!function_exists('owb_money')) {
  function owb_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
}

if (!function_exists('owb_send_cfg')) {
  /** Envio manual: funciona mesmo com as automações desligadas (igual às telas individuais). */
  function owb_send_cfg(PDO $pdo, int $cid): array {
    $cfg = whatsapp_load_config($pdo, $cid);
    $cfg['enabled'] = true;
    $cfg['timeout_seconds'] = max(60, (int)($cfg['timeout_seconds'] ?? 30));
    return $cfg;
  }
}

if (!function_exists('owb_order_boletos')) {
  /** Boletos registrados e em aberto do pedido (direto ou pela Conta a Receber do pedido). */
  function owb_order_boletos(PDO $pdo, int $cid, int $orderId): array {
    if (!billing_table_exists($pdo, 'finance_boleto_charges')) return [];
    $st = $pdo->prepare("SELECT bc.id FROM finance_boleto_charges bc
      WHERE bc.company_id=? AND (bc.order_id=? OR bc.receivable_id IN (SELECT fr.id FROM finance_receivables fr WHERE fr.company_id=? AND fr.order_id=?))
        AND UPPER(COALESCE(bc.status,'')) NOT IN ('PAGO','BAIXADO','CANCELADO','CANCELADA')
      ORDER BY bc.due_date, bc.id");
    $st->execute([$cid, $orderId, $cid, $orderId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chargeId) {
      try {
        $data = boleto_document_load($pdo, $cid, (int)$chargeId, 0);
        if (!boleto_document_ready($data)) continue;
        $installment = trim((string)($data['installment'] ?? ''));
        $out[] = [
          'id' => (int)$chargeId,
          'label' => 'Boleto' . ($installment !== '' ? ' parcela ' . $installment : ''),
          'detail' => 'Vencimento ' . boleto_document_date($data['due_date'] ?? '') . ' · ' . boleto_document_money($data['amount'] ?? 0),
        ];
      } catch (Throwable $e) {}
    }
    return $out;
  }
}

if (!function_exists('owb_order_fiscal_docs')) {
  /** Notas fiscais AUTORIZADAS do pedido (NF-e, NFC-e e NFS-e). */
  function owb_order_fiscal_docs(PDO $pdo, int $cid, int $orderId): array {
    $st = $pdo->prepare("SELECT d.id, d.model, d.series, d.number, d.total_document, n.nfse_number
      FROM fiscal_documents d
      LEFT JOIN fiscal_nfse_details n ON n.company_id=d.company_id AND n.fiscal_document_id=d.id
      WHERE d.company_id=? AND d.source_type='PEDIDO' AND d.source_id=? AND d.model IN ('55','65','NFSE')
        AND UPPER(COALESCE(n.status, d.status))='AUTORIZADA'
      ORDER BY d.id");
    $st->execute([$cid, $orderId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $model = strtoupper((string)$row['model']);
      $label = $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'NFS-e');
      $number = $model === 'NFSE' ? (trim((string)($row['nfse_number'] ?? '')) ?: (string)$row['number']) : ((string)$row['series'] . '/' . (string)$row['number']);
      $out[] = ['id' => (int)$row['id'], 'label' => $label . ' nº ' . $number, 'detail' => 'Total ' . owb_money($row['total_document'] ?? 0)];
    }
    return $out;
  }
}

if (!function_exists('owb_send_boleto')) {
  function owb_send_boleto(PDO $pdo, int $cid, int $chargeId, string $phone): array {
    $data = boleto_document_load($pdo, $cid, $chargeId, 0);
    if (!boleto_document_ready($data)) throw new RuntimeException('O boleto ainda não foi registrado no banco.');
    $companyName = boleto_document_company_name($data);
    $customerName = trim((string)($data['payer_name'] ?? '')) ?: 'cliente';
    $fallback = trim(implode("\n", array_filter([
      'Olá, ' . $customerName . '!',
      'Segue em anexo o boleto emitido pela ' . $companyName . '.',
      'Vencimento: ' . boleto_document_date($data['due_date'] ?? ''),
      'Valor: ' . boleto_document_money($data['amount'] ?? 0),
      trim((string)($data['linha_digitavel'] ?? '')) !== '' ? 'Linha digitável: ' . trim((string)$data['linha_digitavel']) : '',
    ])));
    $vars = [
      'empresa' => $companyName, 'cliente' => $customerName,
      'titulo_id' => (string)((int)($data['receivable_id'] ?? 0)), 'boleto_id' => (string)((int)($data['id'] ?? 0)),
      'pedido' => (int)($data['order_id'] ?? 0) > 0 ? '#' . (int)$data['order_id'] : '',
      'contrato' => (int)($data['contract_id'] ?? 0) > 0 ? '#' . (int)$data['contract_id'] : '',
      'vencimento' => boleto_document_date($data['due_date'] ?? ''), 'valor' => boleto_document_money($data['amount'] ?? 0),
      'nosso_numero' => trim((string)($data['nosso_numero'] ?? '')), 'linha_digitavel' => trim((string)($data['linha_digitavel'] ?? '')),
      'pix_copia_cola' => trim((string)($data['pix_emv'] ?? '')),
    ];
    $state = whatsapp_message_models_load($pdo, $cid);
    $modelId = max(0, (int)($state['defaults']['boleto'] ?? 0));
    $message = whatsapp_message_model_render($pdo, $cid, 'boleto', $vars, $fallback, $modelId > 0 ? $modelId : null);
    $filename = 'boleto-' . ((int)($data['receivable_id'] ?? 0) ?: (int)$data['id']) . '.pdf';
    return whatsapp_send_document($pdo, $cid, $phone, $message, $filename, 'application/pdf', boleto_document_pdf($data), 'finance_boleto_admin', null, owb_send_cfg($pdo, $cid));
  }
}

if (!function_exists('owb_send_fiscal')) {
  function owb_send_fiscal(PDO $pdo, int $cid, int $docId, string $phone): array {
    $st = $pdo->prepare("SELECT d.*,n.provider_code,n.dps_series,n.dps_number,n.nfse_number,n.verification_code,n.response_xml FROM fiscal_documents d LEFT JOIN fiscal_nfse_details n ON n.company_id=d.company_id AND n.fiscal_document_id=d.id WHERE d.company_id=? AND d.id=? AND d.model IN ('55','65','NFSE') LIMIT 1");
    $st->execute([$cid, $docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
    $isNfse = strtoupper((string)$doc['model']) === 'NFSE';
    $model = $isNfse ? 'NFSE' : (preg_replace('/\D+/', '', (string)$doc['model']) ?: '65');
    $label = $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'NFS-e');
    $dest = fiscal_nfce_document_participant($pdo, $cid, $docId, $isNfse ? 'TOMADOR' : 'DESTINATARIO');
    $emit = fiscal_nfce_document_participant($pdo, $cid, $docId, $isNfse ? 'PRESTADOR' : 'EMITENTE');
    $number = $isNfse ? (trim((string)($doc['nfse_number'] ?? '')) ?: (string)($doc['number'] ?? '')) : (string)($doc['number'] ?? '');
    $series = $isNfse ? trim((string)($doc['dps_series'] ?? $doc['series'] ?? '')) : (string)($doc['series'] ?? '');
    $key = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? '')) ?? '';
    $customerName = trim((string)($dest['name'] ?? '')) ?: 'cliente';
    $issuerName = trim((string)($emit['name'] ?? '')) ?: 'empresa';
    $fallback = trim(implode("\n", array_filter([
      'Olá, ' . $customerName . '!',
      'Segue em anexo o PDF do documento fiscal emitido pela ' . $issuerName . '.',
      $isNfse ? ($label . ' nº ' . $number) : ($label . ' nº ' . $series . '/' . $number),
      'Total: ' . owb_money($doc['total_document'] ?? 0),
      $key !== '' ? 'Chave de acesso: ' . $key : '',
    ])));
    $message = whatsapp_message_model_render($pdo, $cid, $model === '55' ? 'nfe' : ($model === 'NFSE' ? 'nfse' : 'nfce'), [
      'empresa' => $issuerName, 'cliente' => $customerName, 'documento' => $label, 'numero' => $number,
      'serie' => $series, 'dps_serie' => $series, 'dps_numero' => (string)($doc['dps_number'] ?? $doc['number'] ?? ''),
      'codigo_verificacao' => (string)($doc['verification_code'] ?? ''), 'total' => owb_money($doc['total_document'] ?? 0), 'chave_acesso' => $key,
    ], $fallback);
    if (function_exists('fiscal_company_report_identity')) $doc['_issuer_identity'] = fiscal_company_report_identity($pdo, $cid);
    if ($isNfse) { $doc['_prestador'] = $emit; $doc['_tomador'] = $dest; }
    $pdf = fiscal_document_pdf_build($doc);
    return whatsapp_send_document($pdo, $cid, $phone, $message, (string)$pdf['filename'], (string)$pdf['mimetype'], (string)$pdf['content'], 'fiscal_document_admin', null, owb_send_cfg($pdo, $cid));
  }
}
