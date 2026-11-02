<?php
declare(strict_types=1);

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$companyId = max(1, (int)($argv[2] ?? 1));
$orderNumber = max(0, (int)($argv[3] ?? 0));

if ($orderNumber <= 0) {
  fwrite(STDERR, "Uso: php tools/diagnosticar_documentos_pedido.php /var/www/nexa ID_EMPRESA NUMERO_PEDIDO\n");
  exit(2);
}

$pdo = require $root . '/config/pdo.php';
require_once $root . '/config/company_sequences.php';
require_once $root . '/config/fiscal_order_composition.php';

$hasNumber = company_sequence_has_column($pdo, 'pedidos');
$sqlOrder = $hasNumber
  ? 'SELECT * FROM pedidos WHERE company_id=? AND numero_pedido=? LIMIT 1'
  : 'SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1';

$st = $pdo->prepare($sqlOrder);
$st->execute([$companyId, $orderNumber]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
  fwrite(STDERR, "Pedido não encontrado para a empresa informada.\n");
  exit(1);
}

$orderId = (int)$order['id'];
$display = company_sequence_display($order, 'pedidos');

$sql = "SELECT pi.id AS item_id,
               pi.produto_id,
               COALESCE(pi.produto_nome_snapshot,p.nome,CONCAT('Produto ',pi.produto_id)) AS produto,
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
                        WHEN MAX(CASE WHEN UPPER(TRIM(COALESCE(item_type,'')))
                          IN ('SERVICE','SERVICO','SERVIÇO','SERVICOS','SERVIÇOS','SERV','S')
                          THEN 1 ELSE 0 END)=1
                        THEN 'SERVICE' ELSE 'PRODUCT'
                      END AS item_type
                 FROM fiscal_product_tax
             GROUP BY company_id, product_id
          ) ft
            ON ft.company_id=pi.company_id
           AND ft.product_id=pi.produto_id
         WHERE pi.company_id=?
           AND pi.pedido_id=?
      ORDER BY pi.id";

$it = $pdo->prepare($sql);
$it->execute([$companyId, $orderId]);
$items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "Empresa: {$companyId}\n";
echo "Pedido visível: {$display}\n";
echo "ID interno: {$orderId}\n\n";

foreach ($items as $item) {
  $service = fiscal_order_item_is_service($item['is_service'] ?? 0, $item['item_type'] ?? 'PRODUCT');
  echo sprintf(
    "- Item %d | Produto %d | %s | produtos.is_service=%d | fiscal.item_type=%s | CLASSIFICAÇÃO=%s\n",
    (int)$item['item_id'],
    (int)$item['produto_id'],
    (string)$item['produto'],
    (int)$item['is_service'],
    (string)$item['item_type'],
    $service ? 'SERVIÇO' : 'MERCADORIA'
  );
}

$summary = fiscal_order_composition($pdo, $companyId, $orderId);
echo "\nResumo:\n";
foreach (['item_count','service_count','product_count','classification','only_services','only_products','mixed','read_error'] as $key) {
  $value = $summary[$key] ?? '';
  if (is_bool($value)) $value = $value ? 'SIM' : 'NÃO';
  echo "- {$key}: {$value}\n";
}

echo "\nDocumentos disponíveis esperados:\n";
if (!empty($summary['only_services'])) {
  echo "- NFS-e: SIM\n- NF-e: NÃO\n- NFC-e: NÃO\n";
} elseif (!empty($summary['only_products'])) {
  echo "- NFS-e: NÃO\n- NF-e: conforme configuração\n- NFC-e: conforme configuração\n";
} elseif (!empty($summary['mixed'])) {
  echo "- Nenhum automático. Separe mercadorias e serviços.\n";
} else {
  echo "- Nenhum automático. Verifique os itens ou o erro de leitura.\n";
}
