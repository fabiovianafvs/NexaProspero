<?php
/**
 * Ponte de LEITURA entre Central de Pedidos e documentos fiscais originados da O.S.
 *
 * Segurança:
 * - não altera os emissores fiscais centrais;
 * - não religa fiscal_documents para PEDIDO;
 * - apenas traduz os vínculos ORDEM_SERVICO -> os_orders.pedido_id para a tela pedidos.php;
 * - usa os_fiscal_queue somente para conhecer pendências/erros da O.S.
 */

if (!function_exists('os_pedidos_fiscal_bridge')) {
    function os_pedidos_fiscal_bridge(PDO $pdo, int $companyId, array $pedidoIds): array
    {
        $out = [
            'orders' => [],
            'documents' => [],
            'queues' => [],
        ];
        $pedidoIds = array_values(array_unique(array_filter(array_map('intval', $pedidoIds), static fn($v) => $v > 0)));
        if ($companyId <= 0 || !$pedidoIds) return $out;

        $ph = implode(',', array_fill(0, count($pedidoIds), '?'));
        $params = array_merge([$companyId], $pedidoIds);

        try {
            $st = $pdo->prepare("SELECT o.id AS os_order_id,o.pedido_id,o.os_number,o.billing_status,o.requires_product_fiscal,o.requires_service_fiscal,o.product_document_type,o.service_document_type
                                 FROM os_orders o
                                 WHERE o.company_id=? AND o.pedido_id IN ($ph)
                                 ORDER BY o.id DESC");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pedidoId = (int)($row['pedido_id'] ?? 0);
                if ($pedidoId > 0 && !isset($out['orders'][$pedidoId])) $out['orders'][$pedidoId] = $row;
            }
        } catch (Throwable $e) {
            return $out;
        }

        if (!$out['orders']) return $out;
        $osIds = array_values(array_unique(array_map(static fn($r) => (int)$r['os_order_id'], $out['orders'])));
        if (!$osIds) return $out;
        $osPh = implode(',', array_fill(0, count($osIds), '?'));
        $osParams = array_merge([$companyId], $osIds);

        try {
            $sql = "SELECT d.*,o.pedido_id,o.id AS os_order_id,
                           nd.status AS nfse_status,nd.nfse_number,nd.api_error_message
                    FROM fiscal_documents d
                    INNER JOIN os_orders o ON o.company_id=d.company_id AND o.id=d.source_id
                    LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id
                    WHERE d.company_id=? AND d.source_type='ORDEM_SERVICO'
                      AND d.source_id IN ($osPh) AND d.model IN ('55','65','NFSE')
                    ORDER BY d.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($osParams);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pedidoId = (int)($row['pedido_id'] ?? 0);
                $model = (string)($row['model'] ?? '');
                $type = $model === '55' ? 'NFE' : ($model === '65' ? 'NFCE' : ($model === 'NFSE' ? 'NFSE' : ''));
                if ($pedidoId > 0 && $type !== '' && !isset($out['documents'][$pedidoId][$type])) {
                    $out['documents'][$pedidoId][$type] = $row;
                }
            }
        } catch (Throwable $e) {
            // A ausência da tabela de detalhes da NFS-e não deve derrubar Pedidos.
            try {
                $sql = "SELECT d.*,o.pedido_id,o.id AS os_order_id
                        FROM fiscal_documents d
                        INNER JOIN os_orders o ON o.company_id=d.company_id AND o.id=d.source_id
                        WHERE d.company_id=? AND d.source_type='ORDEM_SERVICO'
                          AND d.source_id IN ($osPh) AND d.model IN ('55','65','NFSE')
                        ORDER BY d.id DESC";
                $st = $pdo->prepare($sql);
                $st->execute($osParams);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $pedidoId = (int)($row['pedido_id'] ?? 0);
                    $model = (string)($row['model'] ?? '');
                    $type = $model === '55' ? 'NFE' : ($model === '65' ? 'NFCE' : ($model === 'NFSE' ? 'NFSE' : ''));
                    if ($pedidoId > 0 && $type !== '' && !isset($out['documents'][$pedidoId][$type])) {
                        $out['documents'][$pedidoId][$type] = $row;
                    }
                }
            } catch (Throwable $ignore) {}
        }

        try {
            $sql = "SELECT q.*,o.pedido_id
                    FROM os_fiscal_queue q
                    INNER JOIN os_orders o ON o.company_id=q.company_id AND o.id=q.os_order_id
                    WHERE q.company_id=? AND q.os_order_id IN ($osPh)
                    ORDER BY q.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($osParams);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pedidoId = (int)($row['pedido_id'] ?? 0);
                $type = strtoupper(trim((string)($row['document_type'] ?? '')));
                if ($pedidoId > 0 && in_array($type, ['NFE','NFCE','NFSE'], true) && !isset($out['queues'][$pedidoId][$type])) {
                    $out['queues'][$pedidoId][$type] = $row;
                }
            }
        } catch (Throwable $e) {}

        return $out;
    }
}

if (!function_exists('os_pedidos_queue_blocks_new_generation')) {
    function os_pedidos_queue_blocks_new_generation(?array $queue): bool
    {
        if (!$queue) return false;
        // Qualquer fila já existente deve ser tratada/reprocessada pela área fiscal da O.S.,
        // evitando a criação de um segundo job para o mesmo documento.
        return true;
    }
}
