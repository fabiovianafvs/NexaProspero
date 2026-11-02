<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/auth.php';

require_platform_admin();

if (!function_exists('saas_date_br')) {
  /** Exibe datas do banco no padrão brasileiro solicitado pela Central SaaS. */
  function saas_date_br(mixed $value, string $empty = '—'): string {
    $raw = trim((string)$value);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) return $empty;
    try {
      return (new DateTimeImmutable($raw))->format('d-m-Y');
    } catch (Throwable $e) {
      return $empty;
    }
  }
}

if (!function_exists('saas_datetime_br')) {
  /** Exibe data e hora, mantendo a data em dd-mm-aaaa. */
  function saas_datetime_br(mixed $value, string $empty = '—'): string {
    $raw = trim((string)$value);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) return $empty;
    try {
      return (new DateTimeImmutable($raw))->format('d-m-Y H:i');
    } catch (Throwable $e) {
      return $empty;
    }
  }
}
