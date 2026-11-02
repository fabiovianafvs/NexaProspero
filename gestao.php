<?php
declare(strict_types=1);

$query = $_GET;
$query['linha'] = 'gestao';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: /montesuaproposta?' . http_build_query($query), true, 302);
exit;
