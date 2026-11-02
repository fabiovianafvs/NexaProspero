<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/resellers.php';
require_once __DIR__ . '/../config/reseller_integrations.php';
reseller_ensure_schema($pdo);
reseller_integrations_ensure_schema($pdo);
$reseller = reseller_resolve_context($pdo, true);
if (!$reseller) { http_response_code(404); exit('Revenda não encontrada ou inativa.'); }
define('RESELLER_COMPANY_ID', (int)$reseller['id']);
require_company_admin(RESELLER_COMPANY_ID);
function rev_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function rev_money(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

function rev_date_br(mixed $value, string $empty = '—'): string {
  $raw = trim((string)$value);
  if ($raw === '' || str_starts_with($raw, '0000-00-00')) return $empty;
  try { return (new DateTimeImmutable($raw))->format('d-m-Y'); }
  catch (Throwable $e) { return $empty; }
}
function rev_datetime_br(mixed $value, string $empty = '—'): string {
  $raw = trim((string)$value);
  if ($raw === '' || str_starts_with($raw, '0000-00-00')) return $empty;
  try { return (new DateTimeImmutable($raw))->format('d-m-Y H:i'); }
  catch (Throwable $e) { return $empty; }
}
