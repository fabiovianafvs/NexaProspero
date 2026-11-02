<?php
declare(strict_types=1);
require __DIR__ . '/../config/navigation_return.php';
$valid = [
    ['gestao_pdv_checkout.php', 'gestao_pdv_checkout.php'],
    ['gestao_pdv_resumido.php?mc_ctx=ABC123', 'gestao_pdv_resumido.php?mc_ctx=ABC123'],
    ['pedidos.php?q=arca&status=finalizado&dt_ini=2026-10-26&pg=2', 'pedidos.php?q=arca&status=finalizado&dt_ini=2026-10-26&pg=2'],
    ['fiscal_documentos.php?q=arca&series=99&issue_from=2026-10-26&auth_status=AUTORIZADA', 'fiscal_documentos.php?q=arca&series=99&issue_from=2026-10-26&auth_status=AUTORIZADA'],
    ['fiscal_nfse_nacional.php?pedido_id=1700&return_to=gestao_pdv_checkout.php', 'fiscal_nfse_nacional.php?pedido_id=1700&return_to=gestao_pdv_checkout.php'],
];
foreach ($valid as [$input,$expected]) {
    $actual = nexa_navigation_safe_return($input,'pedidos.php');
    if ($actual !== $expected) throw new RuntimeException("Valido: $input => $actual");
}
$invalid = [
    'https://evil.example', '//evil.example', '/admin/pedidos.php', '../index.php', 'javascript:alert(1)',
    'pedidos.php#fragment', "pedidos.php\nLocation: https://evil.example", 'pedidos.php%0d%0aLocation:X',
    'pedidos.php?x=%0d%0aLocation%3aX', 'pedidos.php\\abc', 'fiscal_documentos.php/extra',
];
foreach ($invalid as $input) {
    $actual = nexa_navigation_safe_return($input,'pedidos.php');
    if ($actual !== 'pedidos.php') throw new RuntimeException("Invalido: $input => $actual");
}
print "OK: ".count($valid)." destinos locais e ".count($invalid)." entradas rejeitadas.\n";
