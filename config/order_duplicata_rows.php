<?php
declare(strict_types=1);

// Linhas de produtos da Duplicata de venda mercantil (impressão A4 e PDF).
// Mostra ao cliente o valor que era e o valor que está pagando:
//   Vlr. Unit       = preço original do item (pedido_itens.preco_original; itens antigos: preço cobrado)
//   Total s/ desc   = Vlr. Unit x quantidade
//   % desc/Vlr Desc = desconto do item + parte do desconto do final da venda (rateado pelo valor)
//   Total c/ desc   = Total s/ desc - Vlr Desc
if (!function_exists('order_duplicata_rows_calc')) {
  /**
   * @param array $lines cada linha: qty, unit (preço cobrado), total (total do item), original (preço original ou null)
   * @param float $orderDiscount desconto do final da venda
   * @return array{rows: array<int,array{unit:float,gross:float,disc:float,pct:float,net:float}>, gross: float, discount: float}
   */
  function order_duplicata_rows_calc(array $lines, float $orderDiscount): array {
    $rows = [];
    $netSum = 0.0;
    foreach (array_values($lines) as $i => $l) {
      $qty = (float)($l['qty'] ?? 0);
      $unit = (float)($l['unit'] ?? 0);
      $net = round((float)($l['total'] ?? ($qty * $unit)), 2);
      $original = isset($l['original']) && $l['original'] !== null && $l['original'] !== '' ? (float)$l['original'] : 0.0;
      $showUnit = $original > $unit ? $original : $unit;
      $gross = round($qty * $showUnit, 2);
      if ($gross < $net) { $gross = $net; }
      $rows[$i] = ['unit' => $showUnit, 'gross' => $gross, 'disc' => round($gross - $net, 2), 'net' => $net];
      $netSum += $net;
    }
    // Rateio do desconto do final da venda, proporcional ao valor de cada item; o último leva a sobra.
    $orderDiscount = round(max(0.0, min($orderDiscount, $netSum)), 2);
    if ($orderDiscount > 0 && $netSum > 0) {
      $left = $orderDiscount;
      $keys = array_keys($rows);
      $last = end($keys);
      foreach ($keys as $k) {
        $share = $k === $last ? $left : round($orderDiscount * $rows[$k]['net'] / $netSum, 2);
        $share = round(min($share, $rows[$k]['net'], $left), 2);
        $rows[$k]['disc'] = round($rows[$k]['disc'] + $share, 2);
        $left = round($left - $share, 2);
      }
    }
    $grossSum = 0.0;
    $discSum = 0.0;
    foreach ($rows as $k => $r) {
      $rows[$k]['net'] = round($r['gross'] - $r['disc'], 2);
      $rows[$k]['pct'] = $r['gross'] > 0 ? round($r['disc'] / $r['gross'] * 100, 2) : 0.0;
      $grossSum += $r['gross'];
      $discSum += $r['disc'];
    }
    return ['rows' => $rows, 'gross' => round($grossSum, 2), 'discount' => round($discSum, 2)];
  }
}

if (!function_exists('order_duplicata_order_amount')) {
  // Valor do pedido por nome de coluna (o PDV/Pedido grava discount_amount/addition_amount;
  // bases antigas usam desconto/acrescimo). Primeira coluna existente e numérica.
  function order_duplicata_order_amount(array $order, array $keys): float {
    foreach ($keys as $k) {
      if (array_key_exists($k, $order) && is_numeric($order[$k]) && (float)$order[$k] != 0.0) return (float)$order[$k];
    }
    return 0.0;
  }
}

if (!function_exists('order_duplicata_order_discount')) {
  // Desconto do final da venda. Sem coluna preenchida, calcula pela diferença:
  // itens + acréscimo + frete - total do pedido.
  function order_duplicata_order_discount(array $order, array $lines, float $addition, float $freight, float $total): float {
    $discount = order_duplicata_order_amount($order, ['discount_amount', 'desconto', 'discount', 'discount_value', 'valor_desconto']);
    if ($discount > 0) return round($discount, 2);
    if ($total <= 0) return 0.0;
    $itemsNet = 0.0;
    foreach ($lines as $l) $itemsNet += round((float)($l['total'] ?? 0), 2);
    $diff = round($itemsNet + $addition + $freight - $total, 2);
    return $diff > 0.009 ? $diff : 0.0;
  }
}
