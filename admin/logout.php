<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/auth.php';

auth_logout();
header('Location: login.php');
exit;
