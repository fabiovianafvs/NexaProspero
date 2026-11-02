<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Somente CLI.\n");
  exit(1);
}

$companyId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($companyId <= 0) {
  fwrite(STDERR, "Uso: php /var/www/nexa/deploy/test-nexa-realtime-event.php ID_EMPRESA\n");
  exit(1);
}

require_once dirname(__DIR__) . '/config/brain_realtime.php';
$number = 900000 + random_int(1, 99999);
$ok = nexa_realtime_publish_event(
  $companyId,
  'delivery.order.created',
  'order',
  $number,
  [
    'order_id' => $number,
    'order_number' => $number,
    'customer' => 'Teste Nexa Realtime',
    'total' => 1.00,
    'delivery_fee' => 0.00,
    'delivery_type' => 'teste',
    'payment_method' => 'teste',
    'source' => 'diagnostic',
  ],
  false
);

echo $ok
  ? "Evento de teste publicado para empresa {$companyId}. Pedido #{$number}.\n"
  : "Falha ao publicar evento. Consulte o error_log do PHP.\n";
exit($ok ? 0 : 2);
