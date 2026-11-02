<?php
declare(strict_types=1);
// Adaptador de autenticacao - reutiliza exclusivamente o login do Portal do Suporte.
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';
$user = require_support_user($pdo);
