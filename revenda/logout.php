<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

auth_logout();
header('Location: /revenda/login.php');
exit;
