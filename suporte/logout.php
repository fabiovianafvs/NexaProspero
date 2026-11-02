<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/support_portal.php';

// Usa a rotina existente: remove a sessao e invalida o cookie do suporte.
support_logout();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: /suporte/login.php', true, 303);
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><meta http-equiv="refresh" content="0;url=/suporte/login.php"><title>Saindo do Suporte</title></head><body><p>Sessão encerrada. <a href="/suporte/login.php">Voltar para o login do suporte</a>.</p></body></html>
