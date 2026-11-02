<?php
declare(strict_types=1);

/**
 * Classifica a composição fiscal dos pedidos.
 *
 * - somente serviços: NFS-e;
 * - somente mercadorias: NF-e/NFC-e;
 * - composição mista: emissão separada; NF-e/NFC-e usam mercadorias e NFS-e usa serviços;
 * - sem itens ou falha de leitura: nenhuma emissão automática.
 */

if (!function_exists('fiscal_order_normalize_item_type')) {
  function fiscal_order_normalize_item_type($value): string {
    $type = strtoupper(trim((string)$value));
    if ($type === '') return 'PRODUCT';

    $type = strtr($type, [
      'Á'=>'A', 'À'=>'A', 'Â'=>'A', 'Ã'=>'A',
      'É'=>'E', 'Ê'=>'E',
      'Í'=>'I',
      'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O',
      'Ú'=>'U',
      'Ç'=>'C',
    ]);
    $type = preg_replace('/[^A-Z0-9]+/', '', $type) ?: '';

    return in_array($type, [
      'SERVICE', 'SERVICO', 'SERVICOS', 'SERV', 'S'
    ], true) ? 'SERVICE' : 'PRODUCT';
  }
}

if (!function_exists('fiscal_order_item_is_service')) {
  function fiscal_order_item_is_service($isServiceFlag, $itemType): bool {
    // Considera serviço quando qualquer uma das marcações fiscais o identifica.
    // Isso recupera cadastros antigos em que só um dos campos foi atualizado.
    return (int)$isServiceFlag === 1
      || fiscal_order_normalize_item_type($itemType) === 'SERVICE';
  }
}

if (!function_exists('fiscal_order_empty_composition')) {
  function fiscal_order_empty_composition(): array {
    return [
      'item_count' => 0,
      'service_count' => 0,
      'product_count' => 0,
      'only_services' => false,
      'only_products' => false,
      'mixed' => false,
      'classification' => 'EMPTY',
      'read_error' => '',
    ];
  }
}

if (!function_exists('fiscal_order_composition_map')) {
  function fiscal_order_composition_map(PDO $pdo, int $companyId, array $orderIds): array {
    $ids = array_values(array_unique(array_filter(
      array_map('intval', $orderIds),
      static fn(int $id): bool => $id > 0
    )));

    $result = [];
    foreach ($ids as $id) $result[$id] = fiscal_order_empty_composition();
    if ($companyId <= 0 || !$ids) return $result;

    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));

      $sql = "SELECT pi.pedido_id,
                     COALESCE(p.is_service,0) AS is_service,
                     COALESCE(ft.item_type,'PRODUCT') AS item_type
                FROM pedido_itens pi
           LEFT JOIN produtos p
                  ON p.company_id=pi.company_id
                 AND p.id=pi.produto_id
           LEFT JOIN (
                     SELECT company_id,
                            product_id,
                            CASE
                              WHEN MAX(
                                CASE
                                  WHEN UPPER(TRIM(COALESCE(item_type,'')))
                                       IN ('SERVICE','SERVICO','SERVIÇO','SERVICOS','SERVIÇOS','SERV','S')
                                  THEN 1 ELSE 0
                                END
                              ) = 1
                              THEN 'SERVICE'
                              ELSE 'PRODUCT'
                            END AS item_type
                       FROM fiscal_product_tax
                   GROUP BY company_id, product_id
                ) ft
                  ON ft.company_id=pi.company_id
                 AND ft.product_id=pi.produto_id
               WHERE pi.company_id=?
                 AND pi.pedido_id IN ({$ph})
            ORDER BY pi.pedido_id, pi.id";

      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$companyId], $ids));

      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $orderId = (int)($row['pedido_id'] ?? 0);
        if ($orderId <= 0 || !isset($result[$orderId])) continue;

        $result[$orderId]['item_count']++;
        if (fiscal_order_item_is_service(
          $row['is_service'] ?? 0,
          $row['item_type'] ?? 'PRODUCT'
        )) {
          $result[$orderId]['service_count']++;
        } else {
          $result[$orderId]['product_count']++;
        }
      }
    } catch (Throwable $e) {
      foreach ($result as $orderId => $summary) {
        $result[$orderId]['read_error'] = $e->getMessage();
        $result[$orderId]['classification'] = 'ERROR';
      }
      return $result;
    }

    foreach ($result as $orderId => $summary) {
      $items = (int)$summary['item_count'];
      $services = (int)$summary['service_count'];
      $products = (int)$summary['product_count'];

      $onlyServices = $items > 0 && $services === $items && $products === 0;
      $onlyProducts = $items > 0 && $products === $items && $services === 0;
      $mixed = $services > 0 && $products > 0;

      $result[$orderId]['only_services'] = $onlyServices;
      $result[$orderId]['only_products'] = $onlyProducts;
      $result[$orderId]['mixed'] = $mixed;
      $result[$orderId]['classification'] = $onlyServices
        ? 'SERVICES_ONLY'
        : ($onlyProducts ? 'PRODUCTS_ONLY' : ($mixed ? 'MIXED' : 'EMPTY'));
    }

    return $result;
  }
}

if (!function_exists('fiscal_order_composition')) {
  function fiscal_order_composition(PDO $pdo, int $companyId, int $orderId): array {
    $map = fiscal_order_composition_map($pdo, $companyId, [$orderId]);
    return $map[$orderId] ?? fiscal_order_empty_composition();
  }
}
