<?php
/**
 * Nexa Próspero - V11.36.4
 * Ponte segura Orçamento -> Pedido/PDV.
 *
 * Esta camada é somente leitura até o pedido ser finalizado. Nenhuma emissão fiscal
 * é disparada aqui. O fechamento continua integralmente no motor já homologado do PDV.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/orcamento_module.php';
require_once __DIR__ . '/delivery_control.php';


if (!function_exists('orc_bridge_os_link_schema_ready')) {
    function orc_bridge_os_link_schema_ready(PDO $pdo): bool {
        try {
            $st=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
            $st->execute(['os_quote_links']);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('orc_bridge_os_link')) {
    function orc_bridge_os_link(PDO $pdo, int $companyId, int $quoteId): ?array {
        if ($quoteId<=0 || !orc_bridge_os_link_schema_ready($pdo)) return null;
        try {
            $st=$pdo->prepare('SELECT l.os_order_id,o.os_number FROM os_quote_links l LEFT JOIN os_orders o ON o.company_id=l.company_id AND o.id=l.os_order_id WHERE l.company_id=? AND l.quote_id=? LIMIT 1');
            $st->execute([$companyId,$quoteId]);
            $row=$st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('orc_bridge_supported_scopes')) {
    function orc_bridge_supported_scopes(): array {
        return [
            'sales_pdv_order' => 'gestao_pdv',
            'sales_pdv_summary' => 'gestao_pdv_resumido',
            'sales_detailed_order' => 'gestao_pedido_detalhado',
        ];
    }
}

if (!function_exists('orc_bridge_company_id')) {
    function orc_bridge_company_id(PDO $pdo, string $scope): int {
        $supported = orc_bridge_supported_scopes();
        if (!isset($supported[$scope])) {
            throw new RuntimeException('Origem de leitura do orçamento não suportada.');
        }
        if (function_exists('multi_company_operational_company_id')) {
            $cid = (int)multi_company_operational_company_id($pdo, $scope);
            if ($cid > 0) return $cid;
        }
        return defined('COMPANY_ID') ? (int)COMPANY_ID : orc_company_id();
    }
}

if (!function_exists('orc_bridge_scope_allowed')) {
    function orc_bridge_scope_allowed(PDO $pdo, string $scope): bool {
        $supported = orc_bridge_supported_scopes();
        if (!isset($supported[$scope])) return false;
        if (function_exists('multi_company_scope_is_cross_company')
            && multi_company_scope_is_cross_company($pdo, $scope)) {
            if (!function_exists('multi_company_context_permission_allowed')) return true;
            if ($scope === 'sales_pdv_order') {
                return multi_company_context_permission_allowed($pdo, 'gestao_pdv')
                    || multi_company_context_permission_allowed($pdo, 'gestao_pdv_checkout');
            }
            return multi_company_context_permission_allowed($pdo, $supported[$scope]);
        }
        return true;
    }
}

if (!function_exists('orc_bridge_ready')) {
    function orc_bridge_ready(PDO $pdo, int $companyId): bool {
        if ($companyId <= 0) return false;
        try {
            return orc_schema_ready($pdo) && orc_module_enabled($pdo, $companyId);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('orc_bridge_search')) {
    function orc_bridge_search(PDO $pdo, int $companyId, string $q = '', int $limit = 80): array {
        $limit = max(10, min(120, $limit));
        $q = trim($q);
        $where = ["o.company_id=?", "o.status IN ('ABERTO','APROVADO')"];
        if (function_exists('orc_schema_has_column') && orc_schema_has_column($pdo, 'orcamentos', 'converted_order_id')) {
            $where[] = 'o.converted_order_id IS NULL';
        }
        $params = [$companyId];
        if (orc_bridge_os_link_schema_ready($pdo)) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM os_quote_links oqs WHERE oqs.company_id=o.company_id AND oqs.quote_id=o.id)';
        }
        if ($q !== '') {
            $tokens = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
            foreach ($tokens as $token) {
                $like = nexa_search_like($token);
                $where[] = '(CAST(o.quote_number AS CHAR) LIKE ? OR o.person_name_snapshot LIKE ? OR o.person_document_snapshot LIKE ? OR o.person_phone_snapshot LIKE ? OR o.seller_name_snapshot LIKE ? OR o.professional_name_snapshot LIKE ? OR o.notes LIKE ?)';
                for ($i = 0; $i < 7; $i++) $params[] = $like;
            }
        }
        $sql = "SELECT o.id,o.quote_number,o.quote_date,o.status,o.person_id,o.person_name_snapshot,o.person_document_snapshot,o.person_phone_snapshot,o.seller_name_snapshot,o.professional_name_snapshot,o.total_general,o.delivery_date,o.updated_at,
                       (SELECT COUNT(*) FROM orcamento_items oi WHERE oi.company_id=o.company_id AND oi.quote_id=o.id) item_count,
                       (SELECT COUNT(*) FROM orcamento_payments op WHERE op.company_id=o.company_id AND op.quote_id=o.id) payment_count
                FROM orcamentos o
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.quote_date DESC,o.id DESC
                LIMIT {$limit}";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('orc_bridge_customer')) {
    function orc_bridge_customer(PDO $pdo, int $companyId, array $quote): array {
        $personId = (int)($quote['person_id'] ?? 0);
        if ($personId > 0) {
            try {
                $st = $pdo->prepare("SELECT id,name,fantasy_name,document,phone,whatsapp,zip_code,address,number,complement,district,city,state,active
                                     FROM finance_people WHERE company_id=? AND id=? AND active=1 LIMIT 1");
                $st->execute([$companyId, $personId]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if ($p) return $p + ['linked'=>1];
            } catch (Throwable $e) {}
        }
        return [
            'id' => 0,
            'name' => (string)($quote['person_name_snapshot'] ?? ''),
            'fantasy_name' => '',
            'document' => (string)($quote['person_document_snapshot'] ?? ''),
            'phone' => (string)($quote['person_phone_snapshot'] ?? ''),
            'whatsapp' => (string)($quote['person_phone_snapshot'] ?? ''),
            'zip_code' => (string)($quote['delivery_zip_code'] ?? ''),
            'address' => (string)($quote['delivery_address'] ?? ''),
            'number' => (string)($quote['delivery_number'] ?? ''),
            'complement' => (string)($quote['delivery_complement'] ?? ''),
            'district' => (string)($quote['delivery_district'] ?? ''),
            'city' => (string)($quote['delivery_city'] ?? ''),
            'state' => (string)($quote['delivery_state'] ?? ''),
            'active' => 1,
            'linked' => 0,
        ];
    }
}

if (!function_exists('orc_bridge_load')) {
    function orc_bridge_load(PDO $pdo, int $companyId, int $quoteId): array {
        $quote = orc_fetch($pdo, $companyId, $quoteId);
        if (!$quote) throw new RuntimeException('Orçamento não encontrado para a empresa selecionada.');
        if (in_array((string)($quote['status'] ?? ''), ['RECUSADO','CANCELADO','FATURADO'], true)) {
            throw new RuntimeException('Este orçamento está encerrado e não pode ser carregado para o pedido.');
        }
        if ((int)($quote['converted_order_id'] ?? 0) > 0) {
            throw new RuntimeException('Este orçamento já foi utilizado na venda/pedido #' . (int)$quote['converted_order_id'] . ' e não pode ser lido novamente.');
        }

        $osLink=orc_bridge_os_link($pdo,$companyId,$quoteId);
        if ($osLink) {
            $osNo=(int)($osLink['os_number'] ?? 0);
            throw new RuntimeException('Este orçamento já foi convertido na O.S. #'.($osNo>0?str_pad((string)$osNo,6,'0',STR_PAD_LEFT):(int)$osLink['os_order_id']).' e não pode gerar também um Pedido.');
        }

        $deliveryJoin = function_exists('delivery_control_schema_ready') && delivery_control_schema_ready($pdo);
        $futureSelect = $deliveryJoin ? 'COALESCE(dqi.future_quantity,0)' : '0';
        $futureJoin = $deliveryJoin ? 'LEFT JOIN delivery_control_quote_items dqi ON dqi.company_id=oi.company_id AND dqi.quote_item_id=oi.id' : '';

        // Compatibilidade com bases em que o código legado Firebird já não existe
        // na tabela produtos. O numero_cadastro é o código operacional atual;
        // codigo_firebird é apenas fallback legado quando a coluna estiver presente.
        $hasProductNumber = orc_schema_has_column($pdo, 'produtos', 'numero_cadastro');
        $hasLegacyProductCode = orc_schema_has_column($pdo, 'produtos', 'codigo_firebird');
        $productNumberSelect = $hasProductNumber ? 'p.numero_cadastro' : 'NULL';
        $legacyProductCodeSelect = $hasLegacyProductCode ? 'p.codigo_firebird' : 'NULL';

        $st = $pdo->prepare("SELECT oi.*, {$futureSelect} future_quantity, p.id current_product_id,{$productNumberSelect} current_product_number,{$legacyProductCodeSelect} current_product_legacy_code,p.nome current_product_name,p.preco current_product_price,COALESCE(p.ativo,0) current_active,p.categoria current_category
                             FROM orcamento_items oi
                             {$futureJoin}
                             LEFT JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id
                             WHERE oi.company_id=? AND oi.quote_id=? ORDER BY oi.id");
        $st->execute([$companyId, $quoteId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare("SELECT op.*, fpm.name current_method_name,fpm.type payment_type,COALESCE(fpm.generate_receivable,0) generate_receivable,COALESCE(fpm.allow_installments,0) allow_installments,
                                    COALESCE(fpm.default_installments,1) default_installments,COALESCE(fpm.first_due_days,30) first_due_days,COALESCE(fpm.keep_due_day,1) keep_due_day,
                                    COALESCE(fpm.is_active,0) method_active
                             FROM orcamento_payments op
                             LEFT JOIN finance_payment_methods fpm ON fpm.company_id=op.company_id AND fpm.id=op.payment_method_id
                             WHERE op.company_id=? AND op.quote_id=? ORDER BY COALESCE(op.due_date,'9999-12-31'),op.id");
        $st->execute([$companyId, $quoteId]);
        $payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Resolve novamente as formas de pagamento para o cadastro atual da empresa.
        // Em migrações/ajustes de cadastro o ID pode mudar; o nome salvo no orçamento
        // permanece como fallback seguro para que o Pedido Detalhado não perca a condição.
        $activeMethodsById = [];
        $activeMethodsByName = [];
        try {
            $stMethods = $pdo->prepare('SELECT id,name FROM finance_payment_methods WHERE company_id=? AND is_active=1 ORDER BY id');
            $stMethods->execute([$companyId]);
            foreach (($stMethods->fetchAll(PDO::FETCH_ASSOC) ?: []) as $methodRow) {
                $mid = (int)($methodRow['id'] ?? 0);
                $mname = trim((string)($methodRow['name'] ?? ''));
                if ($mid <= 0 || $mname === '') continue;
                $activeMethodsById[$mid] = $mname;
                $normalized = preg_replace('/\s+/u', ' ', function_exists('mb_strtolower') ? mb_strtolower($mname, 'UTF-8') : strtolower($mname));
                $activeMethodsByName[$normalized] = ['id'=>$mid,'name'=>$mname];
            }
        } catch (Throwable $e) {}
        foreach ($payments as &$pay) {
            $savedId = (int)($pay['payment_method_id'] ?? 0);
            $label = trim((string)($pay['payment_label'] ?? $pay['current_method_name'] ?? ''));
            $resolvedId = 0;
            $resolvedName = '';
            if ($savedId > 0 && isset($activeMethodsById[$savedId])) {
                $resolvedId = $savedId;
                $resolvedName = $activeMethodsById[$savedId];
            } elseif ($label !== '') {
                $normalized = preg_replace('/\s+/u', ' ', function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label));
                if (isset($activeMethodsByName[$normalized])) {
                    $resolvedId = (int)$activeMethodsByName[$normalized]['id'];
                    $resolvedName = (string)$activeMethodsByName[$normalized]['name'];
                }
            }
            $pay['resolved_payment_method_id'] = $resolvedId;
            $pay['resolved_method_name'] = $resolvedName;
        }
        unset($pay);

        $hasFutureDelivery = false;
        foreach ($items as $it) {
            if ((float)($it['future_quantity'] ?? 0) > 0.00001) { $hasFutureDelivery = true; break; }
        }
        if ($hasFutureDelivery && !$payments) {
            throw new RuntimeException('Este orçamento possui entrega futura. Informe e salve a forma de pagamento no orçamento antes de carregá-lo para o Pedido.');
        }

        $warnings = [];
        foreach ($items as $it) {
            if (empty($it['current_product_id'])) {
                $warnings[] = 'Produto do orçamento não existe mais: ' . (string)($it['description_snapshot'] ?? ('ID ' . $it['produto_id']));
            } elseif ((int)($it['current_active'] ?? 0) !== 1) {
                $warnings[] = 'Produto inativo no cadastro atual: ' . (string)($it['description_snapshot'] ?? ('ID ' . $it['produto_id']));
            }
        }
        foreach ($payments as $pay) {
            if ((int)($pay['resolved_payment_method_id'] ?? 0) <= 0) {
                $warnings[] = 'Forma de pagamento do orçamento não pôde ser relacionada a uma forma ativa: ' . (string)($pay['payment_label'] ?? '');
            } elseif ((int)($pay['payment_method_id'] ?? 0) !== (int)($pay['resolved_payment_method_id'] ?? 0)) {
                $warnings[] = 'A forma de pagamento ' . (string)($pay['payment_label'] ?? '') . ' foi relacionada ao cadastro ativo atual da empresa.';
            }
        }

        return [
            'quote' => $quote,
            'customer' => orc_bridge_customer($pdo, $companyId, $quote),
            'items' => $items,
            'payments' => $payments,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}

if (!function_exists('orc_bridge_release_reservations_after_order')) {
    function orc_bridge_release_reservations_after_order(PDO $pdo, int $companyId, int $quoteId, ?int $userId = null, int $orderId = 0, string $requestedDoc = 'none'): void {
        if ($companyId <= 0 || $quoteId <= 0 || !orc_schema_ready($pdo)) return;
        if ($orderId > 0 && function_exists('delivery_control_enabled') && delivery_control_enabled($pdo,$companyId) && delivery_control_schema_ready($pdo)) {
            $futureRows=delivery_control_quote_future_rows($pdo,$companyId,$quoteId);
            if ($futureRows) {
                delivery_control_finalize_quote_order($pdo,$companyId,$quoteId,$orderId,$userId,$requestedDoc);
                if (function_exists('orc_schema_has_column') && orc_schema_has_column($pdo, 'orcamentos', 'converted_order_id')) {
                    $pdo->prepare("UPDATE orcamentos SET status='FATURADO',converted_order_id=?,converted_at=COALESCE(converted_at,NOW()),converted_by=COALESCE(converted_by,?),updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")
                        ->execute([$orderId, $userId ?: null, $userId ?: null, $companyId, $quoteId]);
                }
                return;
            }
        }
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id,status FROM orcamentos WHERE company_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId, $quoteId]);
            $quote = $st->fetch(PDO::FETCH_ASSOC);
            if (!$quote) throw new RuntimeException('Orçamento de origem não encontrado ao liberar reserva.');
            orc_release_reservations($pdo, $companyId, $quoteId);
            if ($orderId > 0 && function_exists('orc_schema_has_column') && orc_schema_has_column($pdo, 'orcamentos', 'converted_order_id')) {
                $pdo->prepare("UPDATE orcamentos SET status='FATURADO',converted_order_id=?,converted_at=NOW(),converted_by=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")
                    ->execute([$orderId, $userId ?: null, $userId ?: null, $companyId, $quoteId]);
            } else {
                $newStatus = (string)$quote['status'] === 'ABERTO' ? 'APROVADO' : (string)$quote['status'];
                $pdo->prepare('UPDATE orcamentos SET status=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute([$newStatus, $userId ?: null, $companyId, $quoteId]);
            }
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
