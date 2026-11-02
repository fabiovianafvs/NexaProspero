<?php
declare(strict_types=1);

/**
 * Regras centralizadas para CPF/CNPJ e subdomínios de empresas.
 *
 * O subdomínio é sempre um único label DNS: letras ASCII minúsculas,
 * números e hífen, sem hífen no início/fim e com até 63 caracteres.
 */

if (!function_exists('company_identity_digits')) {
  function company_identity_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
  }
}

if (!function_exists('company_identity_valid_cpf')) {
  function company_identity_valid_cpf(string $value): bool {
    $cpf = company_identity_digits($value);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;

    for ($position = 9; $position <= 10; $position++) {
      $sum = 0;
      for ($i = 0; $i < $position; $i++) {
        $sum += ((int)$cpf[$i]) * (($position + 1) - $i);
      }
      $digit = (($sum * 10) % 11) % 10;
      if ($digit !== (int)$cpf[$position]) return false;
    }
    return true;
  }
}

if (!function_exists('company_identity_valid_cnpj')) {
  function company_identity_valid_cnpj(string $value): bool {
    $cnpj = company_identity_digits($value);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;

    $calculate = static function (string $base, array $weights): int {
      $sum = 0;
      foreach ($weights as $i => $weight) $sum += ((int)$base[$i]) * $weight;
      $remainder = $sum % 11;
      return $remainder < 2 ? 0 : 11 - $remainder;
    };

    $first = $calculate(substr($cnpj, 0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
    $second = $calculate(substr($cnpj, 0, 12) . $first, [6,5,4,3,2,9,8,7,6,5,4,3,2]);
    return $first === (int)$cnpj[12] && $second === (int)$cnpj[13];
  }
}

if (!function_exists('company_subdomain_transliterate')) {
  function company_subdomain_transliterate(string $value): string {
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $value = str_replace(['&', '+'], [' e ', ' e '], $value);

    // Casos que não são transliterados de forma uniforme por todos os servidores.
    $value = strtr($value, [
      'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'ø' => 'o', 'ł' => 'l',
      'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ı' => 'i',
    ]);

    if (class_exists('Normalizer')) {
      $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
      if (is_string($normalized)) {
        $value = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
      }
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') $value = $ascii;

    return strtolower($value);
  }
}

if (!function_exists('company_subdomain_normalize')) {
  function company_subdomain_normalize(string $value): string {
    $value = company_subdomain_transliterate($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = preg_replace('/-+/', '-', $value) ?? '';
    $value = trim($value, '-');
    $value = substr($value, 0, 63);
    return rtrim($value, '-');
  }
}

if (!function_exists('company_subdomain_reserved')) {
  function company_subdomain_reserved(): array {
    return [
      'admin','api','app','assets','cdn','dashboard','dev','ftp','help','imap',
      'localhost','mail','nexa','nexaprospero','pop','root','saas','smtp','static',
      'status','sub','suporte','support','webmail','www',
    ];
  }
}

if (!function_exists('company_subdomain_validate')) {
  /** @return array{valid:bool,message:string} */
  function company_subdomain_validate(string $subdomain): array {
    if ($subdomain === '') {
      return ['valid' => false, 'message' => 'O nome fantasia não gerou um endereço válido. Use letras ou números.'];
    }
    if (strlen($subdomain) < 3) {
      return ['valid' => false, 'message' => 'O endereço precisa ter pelo menos 3 caracteres.'];
    }
    if (strlen($subdomain) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $subdomain)) {
      return ['valid' => false, 'message' => 'O endereço gerado contém caracteres inválidos.'];
    }
    if (in_array($subdomain, company_subdomain_reserved(), true)) {
      return ['valid' => false, 'message' => 'Este nome é reservado pelo sistema. Escolha outro nome fantasia.'];
    }
    return ['valid' => true, 'message' => ''];
  }
}

if (!function_exists('company_subdomain_availability')) {
  /** @return array{available:bool,subdomain:string,message:string,company_id:int} */
  function company_subdomain_availability(PDO $pdo, string $source, int $excludeCompanyId = 0): array {
    $subdomain = company_subdomain_normalize($source);
    $validation = company_subdomain_validate($subdomain);
    if (!$validation['valid']) {
      return [
        'available' => false,
        'subdomain' => $subdomain,
        'message' => $validation['message'],
        'company_id' => 0,
      ];
    }

    $sql = "SELECT id FROM companies
            WHERE (LOWER(slug) = ? OR LOWER(COALESCE(subdomain,'')) = ?)
              AND id <> ?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$subdomain, $subdomain, $excludeCompanyId]);
    $existingId = (int)($st->fetchColumn() ?: 0);
    if ($existingId > 0) {
      return [
        'available' => false,
        'subdomain' => $subdomain,
        'message' => 'Este endereço já está em uso. Altere o nome fantasia para gerar outro subdomínio.',
        'company_id' => $existingId,
      ];
    }

    return [
      'available' => true,
      'subdomain' => $subdomain,
      'message' => 'Endereço disponível.',
      'company_id' => 0,
    ];
  }
}

if (!function_exists('company_identity_document_exists')) {
  function company_identity_document_exists(PDO $pdo, string $personType, string $document, int $excludeCompanyId = 0): bool {
    $document = company_identity_digits($document);
    if ($document === '') return false;
    $column = $personType === 'pf' ? 'cpf' : 'cnpj';
    $st = $pdo->prepare("SELECT id FROM companies WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column},''),'.',''),'/',''),'-',''),' ','') = ? AND id <> ? LIMIT 1");
    $st->execute([$document, $excludeCompanyId]);
    return (bool)$st->fetchColumn();
  }
}
