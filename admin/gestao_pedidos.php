<?php
declare(strict_types=1);
$q = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: pedidos.php' . ($q !== '' ? '?' . $q : ''));
exit;
