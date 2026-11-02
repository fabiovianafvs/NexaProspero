<?php
declare(strict_types=1);

// Mantido por compatibilidade (v29 chamava este arquivo).
// v30: autenticação por usuários e empresa.
require_once __DIR__ . '/../config/auth.php';

require_company_admin(defined('COMPANY_ID') ? (int)COMPANY_ID : 0);

