<?php
declare(strict_types=1);

/**
 * LGPD: nas telas de BUSCA de pessoas, CPF/CNPJ aparece só com os 3 primeiros e
 * os 3 últimos dígitos (o resto vira *), mantendo a pontuação.
 * Ex.: 123.456.789-01 vira 123.***.**9-01 (CNPJ segue a mesma regra).
 * Telas de cadastro continuam mostrando o documento completo.
 */
if (!function_exists('nexa_lgpd_mask_document')) {
  function nexa_lgpd_mask_document($value): string {
    $value = (string)($value ?? '');
    $digits = preg_replace('/\D+/', '', $value);
    $n = strlen((string)$digits);
    if ($n < 7) return $value;
    $i = 0;
    return (string)preg_replace_callback('/\d/', static function (array $m) use (&$i, $n): string {
      $k = $i++;
      return ($k < 3 || $k >= $n - 3) ? $m[0] : '*';
    }, $value);
  }
}
