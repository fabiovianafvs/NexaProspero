<?php
declare(strict_types=1);

// Nos subdomínios das revendas, a apresentação e a precificação são resolvidas
// pelo configurador dinâmico. Este redirecionamento evita carregar a landing
// institucional com um bootstrap inexistente na instalação do aplicativo.
$query = $_GET;
$query['linha'] = 'food';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: /montesuaproposta?' . http_build_query($query), true, 302);
exit;
