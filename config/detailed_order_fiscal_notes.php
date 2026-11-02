<?php
declare(strict_types=1);

/**
 * Observações fiscais do Pedido Detalhado.
 * Apenas lê os campos já persistidos; não altera schema nem dados fiscais.
 * Mantém as diferentes denominações de coluna adotadas em bases antigas.
 */
if (!function_exists('detailed_order_fiscal_normalize_note')) {
  function detailed_order_fiscal_normalize_note($text): string {
    $value = trim((string)$text);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
  }
}

if (!function_exists('detailed_order_fiscal_observation')) {
  function detailed_order_fiscal_observation(array $row, bool $item = true): string {
    $candidates = $item
      ? ['complemento', 'observacao_item', 'observacao', 'observacoes', 'obs', 'adicionais']
      : ['observacoes', 'observacao', 'obs'];
    // Chaves provenientes do MySQL podem ter caixa diferente.
    $lookup = [];
    foreach ($row as $key => $value) $lookup[strtolower((string)$key)] = $value;
    foreach ($candidates as $key) {
      if (array_key_exists($key, $lookup)) {
        $note = detailed_order_fiscal_normalize_note($lookup[$key]);
        if ($note !== '') return $note;
      }
    }
    return '';
  }
}
