<?php
/**
 * Retorno ao módulo de Ordem de Serviço quando uma NFS-e foi originada por O.S.
 * Apenas leitura, sem alterações no pedido, documento ou faturamento.
 */
declare(strict_types=1);

if (!function_exists('os_nfse_is_os_origin')) {
    function os_nfse_is_os_origin(PDO $pdo, int $companyId, int $pedidoId, int $osId = 0): bool {
        if ($companyId <= 0) return false;
        // Origem explícita da O.S. no documento (casos legados).
        if ($osId > 0) {
            try {
                $st = $pdo->prepare('SELECT id FROM os_orders WHERE company_id=? AND id=? LIMIT 1');
                $st->execute([$companyId, $osId]);
                if ((int)$st->fetchColumn() > 0) return true;
            } catch (Throwable $e) {
                error_log('[Nexa O.S.] Verificação de origem fiscal (O.S.): ' . $e->getMessage());
            }
        }
        if ($pedidoId <= 0) return false;
        try {
            $st = $pdo->prepare('SELECT source FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
            $st->execute([$companyId, $pedidoId]);
            $origin = strtolower(trim((string)$st->fetchColumn()));
            if (in_array($origin, ['ordem_servico', 'ordem_servico_lote'], true)) return true;
            // Compatibilidade: pedidos vinculados à O.S., sem depender do marcador de origem.
            $st = $pdo->prepare('SELECT id FROM os_orders WHERE company_id=? AND pedido_id=? LIMIT 1');
            $st->execute([$companyId, $pedidoId]);
            if ((int)$st->fetchColumn() > 0) return true;
            $st = $pdo->prepare('SELECT id FROM os_batch_invoices WHERE company_id=? AND pedido_id=? LIMIT 1');
            $st->execute([$companyId, $pedidoId]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            // O módulo pode estar ausente/desabilitado. Não impacta a impressão normal.
            error_log('[Nexa O.S.] Verificação de origem fiscal (pedido): ' . $e->getMessage());
            return false;
        }
    }
}
