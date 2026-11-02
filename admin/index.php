<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/auth.php';

if (auth_role() === 'platform_admin'
    || (in_array(auth_role(), ['company_admin','company_user'], true)
        && (int)auth_company_id() === (int)COMPANY_ID)) {
  header('Location: area_trabalho.php');
  exit;
}

header('Location: login.php');
exit;
