<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Composição de Produtos (V1)
 *
 * Modelo operacional desta versão:
 * - matérias-primas mantêm o estoque físico em produtos.estoque_atual;
 * - produtos compostos recebem estoque operacional calculado em
 *   produtos.estoque_composicao_calculado;
 * - ao vender um produto composto, as matérias-primas são baixadas pela ficha técnica;
 * - o estoque operacional de todos os compostos afetados é recalculado;
 * - o estoque físico do produto acabado NÃO é inventado/duplicado. Para produção física
 *   por lote deverá existir futuramente um fluxo próprio de ordem de produção.
 */

require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/stock_module.php';

if (!function_exists('product_composition_table_exists')) {
  function product_composition_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('product_composition_column_exists')) {
  function product_composition_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
      $st->execute([$table,$column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('product_composition_enabled')) {
  function product_composition_enabled(PDO $pdo, int $companyId): bool {
    return $companyId > 0 && function_exists('saas_company_has_module') && saas_company_has_module($pdo, $companyId, 'composicao_produtos');
  }
}

if (!function_exists('product_composition_schema_ready')) {
  function product_composition_schema_ready(PDO $pdo): bool {
    return product_composition_column_exists($pdo,'produtos','is_materia_prima')
      && product_composition_column_exists($pdo,'produtos','possui_composicao')
      && product_composition_column_exists($pdo,'produtos','composition_weight')
      && product_composition_column_exists($pdo,'produtos','estoque_composicao_individual')
      && product_composition_column_exists($pdo,'produtos','estoque_composicao_calculado')
      && product_composition_column_exists($pdo,'produtos','composition_last_recalc_at')
      && product_composition_table_exists($pdo,'produto_composicao_itens')
      && product_composition_table_exists($pdo,'produto_composicao_consumos');
  }
}

if (!function_exists('product_composition_ensure_schema')) {
  function product_composition_ensure_schema(PDO $pdo): bool {
    if (product_composition_schema_ready($pdo)) return true;
    // DDL em transação pode provocar commit implícito no MySQL. O chamador deve preparar
    // a estrutura antes da transação de venda.
    if ($pdo->inTransaction()) return false;
    try { stock_ensure_schema($pdo, true); } catch (Throwable $e) {}

    $columns = [
      'is_materia_prima' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'possui_composicao' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'composition_weight' => 'DECIMAL(12,4) NOT NULL DEFAULT 1.0000',
      'estoque_composicao_individual' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
      'estoque_composicao_calculado' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
      'composition_last_recalc_at' => 'DATETIME NULL',
    ];
    foreach ($columns as $column => $definition) {
      if (product_composition_column_exists($pdo,'produtos',$column)) continue;
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN `' . $column . '` ' . $definition); } catch (Throwable $e) {}
    }

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS produto_composicao_itens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        materia_prima_id BIGINT UNSIGNED NOT NULL,
        quantidade DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_produto_composicao_item (company_id, produto_id, materia_prima_id),
        KEY idx_produto_composicao_materia (company_id, materia_prima_id),
        KEY idx_produto_composicao_produto (company_id, produto_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS produto_composicao_consumos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        materia_prima_id BIGINT UNSIGNED NOT NULL,
        qtd_produto DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
        qtd_materia_prima DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
        status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reversed_at DATETIME NULL,
        reverse_reason VARCHAR(255) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_produto_composicao_consumo (company_id, order_id, produto_id, materia_prima_id),
        KEY idx_produto_composicao_consumo_order (company_id, order_id, status),
        KEY idx_produto_composicao_consumo_materia (company_id, materia_prima_id, status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    return product_composition_schema_ready($pdo);
  }
}

if (!function_exists('product_composition_decimal')) {
  function product_composition_decimal($value, int $decimals = 6): float {
    $raw = trim((string)$value);
    if ($raw === '') return 0.0;
    $raw = str_replace(['R$',' '], '', $raw);
    if (str_contains($raw, ',') && str_contains($raw, '.')) $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? round((float)$raw, $decimals) : 0.0;
  }
}

if (!function_exists('product_composition_qty_label')) {
  function product_composition_qty_label($value, int $decimals = 6): string {
    $n = (float)$value;
    if (abs($n-round($n)) < 0.0000005) return number_format($n,0,',','.');
    return rtrim(rtrim(number_format($n,$decimals,',','.'),'0'),',');
  }
}

if (!function_exists('product_composition_product_is_composed')) {
  function product_composition_product_is_composed(PDO $pdo, int $companyId, int $productId): bool {
    if ($productId <= 0 || !product_composition_column_exists($pdo,'produtos','possui_composicao')) return false;
    $st = $pdo->prepare('SELECT COALESCE(possui_composicao,0) FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$productId]);
    return (int)$st->fetchColumn() === 1;
  }
}

if (!function_exists('product_composition_component_used_count')) {
  function product_composition_component_used_count(PDO $pdo, int $companyId, int $productId): int {
    if (!product_composition_table_exists($pdo,'produto_composicao_itens')) return 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM produto_composicao_itens WHERE company_id=? AND materia_prima_id=? AND ativo=1');
    $st->execute([$companyId,$productId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('product_composition_raw_materials')) {
  function product_composition_raw_materials(PDO $pdo, int $companyId, int $excludeProductId = 0): array {
    if (!product_composition_schema_ready($pdo)) return [];
    $referenceSelect = product_composition_column_exists($pdo, 'produtos', 'referencia') ? ',referencia' : ',NULL AS referencia';
    $sql = "SELECT id,nome,COALESCE(unidade_estoque,'UN') unidade_estoque,COALESCE(estoque_atual,0) estoque_atual,COALESCE(ativo,1) ativo{$referenceSelect}
      FROM produtos WHERE company_id=? AND COALESCE(is_materia_prima,0)=1";
    $params = [$companyId];
    if ($excludeProductId > 0) { $sql .= ' AND id<>?'; $params[] = $excludeProductId; }
    $sql .= ' ORDER BY COALESCE(ativo,1) DESC,nome ASC,id ASC';
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('product_composition_load')) {
  function product_composition_load(PDO $pdo, int $companyId, int $productId): array {
    if ($productId <= 0 || !product_composition_table_exists($pdo,'produto_composicao_itens')) return [];
    $st = $pdo->prepare("SELECT c.id,c.materia_prima_id,c.quantidade,p.nome materia_prima_nome,COALESCE(p.unidade_estoque,'UN') unidade_estoque,COALESCE(p.estoque_atual,0) estoque_atual
      FROM produto_composicao_itens c INNER JOIN produtos p ON p.company_id=c.company_id AND p.id=c.materia_prima_id
      WHERE c.company_id=? AND c.produto_id=? AND c.ativo=1 ORDER BY p.nome,c.id");
    $st->execute([$companyId,$productId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('product_composition_validate_post')) {
  function product_composition_validate_post(PDO $pdo, int $companyId, int $productId, bool $isRaw, bool $hasComposition, array $post): array {
    $errors = [];
    if (!$isRaw && $productId > 0 && product_composition_component_used_count($pdo,$companyId,$productId) > 0) {
      $errors[] = 'Este produto está sendo usado como matéria-prima em uma ou mais composições. Remova-o dessas composições antes de desmarcar Matéria-prima.';
    }
    if ($isRaw && $hasComposition) $errors[] = 'Nesta versão um produto não pode ser matéria-prima e produto composto ao mesmo tempo.';
    if (!$hasComposition) return $errors;

    $ids = $post['composition_material_id'] ?? [];
    $qtys = $post['composition_qty'] ?? [];
    if (!is_array($ids)) $ids = [];
    if (!is_array($qtys)) $qtys = [];
    $valid = 0;
    $seen = [];
    $check = $pdo->prepare('SELECT COALESCE(is_materia_prima,0) FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    foreach ($ids as $idx => $rawId) {
      $mid = (int)$rawId;
      $qty = product_composition_decimal($qtys[$idx] ?? 0, 6);
      if ($mid <= 0 && $qty <= 0) continue;
      if ($mid <= 0) { $errors[] = 'Selecione a matéria-prima em todas as linhas preenchidas da composição.'; continue; }
      if ($qty <= 0) { $errors[] = 'A quantidade de cada matéria-prima deve ser maior que zero.'; continue; }
      if ($mid === $productId && $productId > 0) { $errors[] = 'O produto não pode fazer parte da própria composição.'; continue; }
      if (isset($seen[$mid])) { $errors[] = 'A mesma matéria-prima foi informada mais de uma vez. Mantenha apenas uma linha por matéria-prima.'; continue; }
      $seen[$mid] = true;
      $check->execute([$companyId,$mid]);
      if ((int)$check->fetchColumn() !== 1) { $errors[] = 'Um dos itens selecionados não está marcado como Matéria-prima.'; continue; }
      $valid++;
    }
    if ($valid === 0) $errors[] = 'Informe pelo menos uma matéria-prima para o produto que possui composição.';
    return array_values(array_unique($errors));
  }
}

if (!function_exists('product_composition_save_from_post')) {
  function product_composition_save_from_post(PDO $pdo, int $companyId, int $productId, bool $hasComposition, array $post): void {
    if ($productId <= 0 || !product_composition_schema_ready($pdo)) return;
    $ids = is_array($post['composition_material_id'] ?? null) ? $post['composition_material_id'] : [];
    $qtys = is_array($post['composition_qty'] ?? null) ? $post['composition_qty'] : [];
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM produto_composicao_itens WHERE company_id=? AND produto_id=?')->execute([$companyId,$productId]);
      if ($hasComposition) {
        $ins = $pdo->prepare('INSERT INTO produto_composicao_itens (company_id,produto_id,materia_prima_id,quantidade,ativo,created_at) VALUES (?,?,?,?,1,NOW())');
        foreach ($ids as $idx => $rawId) {
          $mid = (int)$rawId;
          $qty = product_composition_decimal($qtys[$idx] ?? 0, 6);
          if ($mid <= 0 || $qty <= 0) continue;
          $ins->execute([$companyId,$productId,$mid,$qty]);
        }
      }
      if ($owns) $pdo->commit();
    } catch (Throwable $e) {
      if ($owns && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('product_composition_capacity_round')) {
  function product_composition_capacity_round(float $value, string $unit = 'UN', bool $weighed = false): float {
    $value = max(0.0,$value);
    $unit = strtoupper(trim($unit));
    if (!$weighed && in_array($unit,['UN','UND','UNID','UNIDADE','PC','PÇ','PCA','PCT'],true)) {
      return (float)floor($value + 0.0000001);
    }
    return round($value,4);
  }
}

if (!function_exists('product_composition_stock_cost_columns')) {
  function product_composition_stock_cost_columns(PDO $pdo): bool {
    return product_composition_column_exists($pdo,'estoque_movimentos','custo_unitario')
      && product_composition_column_exists($pdo,'estoque_movimentos','valor_movimento')
      && product_composition_column_exists($pdo,'estoque_movimentos','custo_medio_anterior')
      && product_composition_column_exists($pdo,'estoque_movimentos','custo_medio_posterior');
  }
}

if (!function_exists('product_composition_recalculate_all')) {
  function product_composition_recalculate_all(PDO $pdo, int $companyId, string $reason = 'Recalculo automatico'): array {
    $result = ['products'=>0,'changed'=>0];
    if ($companyId <= 0 || !product_composition_enabled($pdo,$companyId) || !product_composition_schema_ready($pdo)) return $result;

    $weighedExpr = product_composition_column_exists($pdo,'produtos','produto_pesavel') ? 'COALESCE(fp.produto_pesavel,0)' : '0';
    $st = $pdo->prepare("SELECT c.produto_id,c.materia_prima_id,c.quantidade,
        COALESCE(fp.composition_weight,1) composition_weight,COALESCE(fp.ativo,1) produto_ativo,
        COALESCE(fp.unidade_estoque,'UN') produto_unidade,{$weighedExpr} produto_pesavel,
        COALESCE(mp.estoque_atual,0) materia_estoque
      FROM produto_composicao_itens c
      INNER JOIN produtos fp ON fp.company_id=c.company_id AND fp.id=c.produto_id AND COALESCE(fp.possui_composicao,0)=1
      INNER JOIN produtos mp ON mp.company_id=c.company_id AND mp.id=c.materia_prima_id
      WHERE c.company_id=? AND c.ativo=1 AND c.quantidade>0
      ORDER BY c.materia_prima_id,c.produto_id,c.id");
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
      $pdo->prepare('UPDATE produtos SET estoque_composicao_individual=0,estoque_composicao_calculado=0,composition_last_recalc_at=NOW() WHERE company_id=? AND COALESCE(possui_composicao,0)=1')->execute([$companyId]);
      return $result;
    }

    $recipes=[]; $materialStock=[]; $denom=[]; $productMeta=[];
    foreach ($rows as $row) {
      $pid=(int)$row['produto_id']; $mid=(int)$row['materia_prima_id']; $qty=max(0.0,(float)$row['quantidade']);
      $weight=max(0.0001,(float)$row['composition_weight']);
      if ($pid<=0 || $mid<=0 || $qty<=0) continue;
      $recipes[$pid][$mid]=$qty;
      $materialStock[$mid]=max(0.0,(float)$row['materia_estoque']);
      // Cada matéria-prima compartilhada é rateada pelos pesos dos produtos que a utilizam.
      // Peso 1 para todos = divisão igual do saldo da matéria-prima entre os compostos.
      $denom[$mid]=($denom[$mid]??0.0)+$weight;
      $productMeta[$pid]=[
        'weight'=>$weight,'active'=>(int)$row['produto_ativo']===1,
        'unit'=>(string)$row['produto_unidade'],'weighed'=>(int)$row['produto_pesavel']===1,
      ];
    }
    if (!$recipes) return $result;

    $upd=$pdo->prepare('UPDATE produtos SET estoque_composicao_individual=?,estoque_composicao_calculado=?,composition_last_recalc_at=NOW() WHERE company_id=? AND id=?');
    foreach ($recipes as $pid=>$recipe) {
      $meta=$productMeta[$pid];
      $individual=INF; $proportional=INF;
      foreach ($recipe as $mid=>$qty) {
        $stock=$materialStock[$mid]??0.0;
        $individual=min($individual,$qty>0?$stock/$qty:0.0);
        $d=$denom[$mid]??0.0;
        $allocated=$d>0?($stock*$meta['weight']/$d):0.0;
        $proportional=min($proportional,$qty>0?$allocated/$qty:0.0);
      }
      if (!is_finite($individual)) $individual=0.0;
      if (!is_finite($proportional)) $proportional=0.0;
      if (!$meta['active']) { $individual=0.0; $proportional=0.0; }
      $individual=product_composition_capacity_round($individual,$meta['unit'],$meta['weighed']);
      $proportional=product_composition_capacity_round($proportional,$meta['unit'],$meta['weighed']);
      $upd->execute([$individual,$proportional,$companyId,$pid]);
      $result['products']++;
    }
    return $result;
  }
}

if (!function_exists('product_composition_effective_stock_sql')) {
  function product_composition_effective_stock_sql(PDO $pdo, int $companyId, string $alias = ''): string {
    $p = $alias !== '' ? rtrim($alias,'.') . '.' : '';
    if (product_composition_enabled($pdo,$companyId) && product_composition_schema_ready($pdo)) {
      return "CASE WHEN COALESCE({$p}possui_composicao,0)=1 THEN COALESCE({$p}estoque_composicao_calculado,0) ELSE COALESCE({$p}estoque_atual,0) END";
    }
    return "COALESCE({$p}estoque_atual,0)";
  }
}

if (!function_exists('product_composition_apply_sale')) {
  function product_composition_apply_sale(PDO $pdo, int $companyId, int $orderId, string $orderNumber, array $items): array {
    $result=['components'=>0,'quantity'=>0.0];
    if ($companyId<=0 || $orderId<=0 || !product_composition_enabled($pdo,$companyId) || !product_composition_schema_ready($pdo)) return $result;

    $sold=[];
    foreach ($items as $it) {
      $pid=(int)($it['produto_id']??0); $qty=round((float)($it['qtd']??0),4);
      if ($pid>0 && $qty>0) $sold[$pid]=($sold[$pid]??0.0)+$qty;
    }
    if (!$sold) return $result;
    $ids=array_keys($sold); $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT c.produto_id,c.materia_prima_id,c.quantidade
      FROM produto_composicao_itens c INNER JOIN produtos p ON p.company_id=c.company_id AND p.id=c.produto_id
      WHERE c.company_id=? AND c.produto_id IN ($ph) AND c.ativo=1 AND c.quantidade>0 AND COALESCE(p.possui_composicao,0)=1
      ORDER BY c.materia_prima_id,c.produto_id");
    $st->execute(array_merge([$companyId],$ids));
    $recipeRows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    if (!$recipeRows) { product_composition_recalculate_all($pdo,$companyId,'Venda sem composto'); return $result; }

    $pending=[]; $componentTotals=[];
    $exists=$pdo->prepare('SELECT id,status FROM produto_composicao_consumos WHERE company_id=? AND order_id=? AND produto_id=? AND materia_prima_id=? LIMIT 1 FOR UPDATE');
    foreach ($recipeRows as $row) {
      $pid=(int)$row['produto_id']; $mid=(int)$row['materia_prima_id']; $soldQty=$sold[$pid]??0.0;
      $consume=round($soldQty*(float)$row['quantidade'],6);
      if ($consume<=0) continue;
      $exists->execute([$companyId,$orderId,$pid,$mid]);
      if ($exists->fetch(PDO::FETCH_ASSOC)) continue; // idempotência
      $pending[]=['pid'=>$pid,'mid'=>$mid,'sold'=>$soldQty,'consume'=>$consume];
      $componentTotals[$mid]=($componentTotals[$mid]??0.0)+$consume;
    }
    if (!$pending) { product_composition_recalculate_all($pdo,$companyId,'Venda ja processada'); return $result; }

    ksort($componentTotals,SORT_NUMERIC);
    $sel=$pdo->prepare('SELECT estoque_atual,COALESCE(custo_medio,0) custo_medio,COALESCE(custo,0) custo FROM produtos WHERE company_id=? AND id=? FOR UPDATE');
    $upd=$pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?');
    $withCosts=product_composition_stock_cost_columns($pdo);
    $mov=null;
    if (product_composition_table_exists($pdo,'estoque_movimentos')) {
      $mov=$withCosts
        ? $pdo->prepare("INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,custo_unitario,valor_movimento,custo_medio_anterior,custo_medio_posterior,observacao) VALUES (?,?,'saida','COMPOSICAO_PDV',?,?,?,?,?,?,?,?,?)")
        : $pdo->prepare("INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao) VALUES (?,?,'saida','COMPOSICAO_PDV',?,?,?,?,?)");
    }
    foreach ($componentTotals as $mid=>$qty) {
      $qty=round((float)$qty,6); if ($qty<=0) continue;
      $sel->execute([$companyId,$mid]); $row=$sel->fetch(PDO::FETCH_ASSOC); if (!$row) throw new RuntimeException('Matéria-prima #' . $mid . ' não encontrada para baixa da composição.');
      $before=(float)$row['estoque_atual']; $after=round($before-$qty,4); $upd->execute([$after,$companyId,$mid]);
      if ($mov) {
        $cost=(float)$row['custo_medio']; if ($cost<=0) $cost=(float)$row['custo'];
        $obs='Consumo automático da composição no pedido #' . $orderNumber;
        if ($withCosts) $mov->execute([$companyId,$mid,$orderId,$qty,$before,$after,$cost,round($cost*$qty,2),$cost,$cost,$obs]);
        else $mov->execute([$companyId,$mid,$orderId,$qty,$before,$after,$obs]);
      }
      $result['components']++; $result['quantity']+=$qty;
    }
    $ins=$pdo->prepare("INSERT INTO produto_composicao_consumos (company_id,order_id,produto_id,materia_prima_id,qtd_produto,qtd_materia_prima,status,created_at) VALUES (?,?,?,?,?,?,'ATIVO',NOW())");
    foreach ($pending as $p) $ins->execute([$companyId,$orderId,$p['pid'],$p['mid'],$p['sold'],$p['consume']]);
    $result['quantity']=round($result['quantity'],6);
    product_composition_recalculate_all($pdo,$companyId,'Venda pedido #' . $orderNumber);
    return $result;
  }
}

if (!function_exists('product_composition_reverse_sale')) {
  function product_composition_reverse_sale(PDO $pdo, int $companyId, int $orderId, string $reason=''): array {
    $result=['components'=>0,'quantity'=>0.0];
    if ($companyId<=0 || $orderId<=0 || !product_composition_schema_ready($pdo)) return $result;
    $st=$pdo->prepare("SELECT * FROM produto_composicao_consumos WHERE company_id=? AND order_id=? AND status='ATIVO' ORDER BY materia_prima_id,produto_id FOR UPDATE");
    $st->execute([$companyId,$orderId]); $rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[]; if (!$rows) return $result;
    $totals=[]; foreach($rows as $row){$mid=(int)$row['materia_prima_id'];$totals[$mid]=($totals[$mid]??0.0)+(float)$row['qtd_materia_prima'];}
    ksort($totals,SORT_NUMERIC);
    $sel=$pdo->prepare('SELECT estoque_atual,COALESCE(custo_medio,0)custo_medio,COALESCE(custo,0)custo FROM produtos WHERE company_id=? AND id=? FOR UPDATE');
    $upd=$pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?');
    $withCosts=product_composition_stock_cost_columns($pdo);
    $mov=null;
    if (product_composition_table_exists($pdo,'estoque_movimentos')) {
      $mov=$withCosts
        ? $pdo->prepare("INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,custo_unitario,valor_movimento,custo_medio_anterior,custo_medio_posterior,observacao) VALUES (?,?,'entrada','COMPOSICAO_CANCELAMENTO',?,?,?,?,?,?,?,?,?)")
        : $pdo->prepare("INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao) VALUES (?,?,'entrada','COMPOSICAO_CANCELAMENTO',?,?,?,?,?)");
    }
    foreach($totals as $mid=>$qty){
      $qty=round((float)$qty,6); if($qty<=0)continue; $sel->execute([$companyId,$mid]);$row=$sel->fetch(PDO::FETCH_ASSOC);if(!$row)continue;
      $before=(float)$row['estoque_atual'];$after=round($before+$qty,4);$upd->execute([$after,$companyId,$mid]);
      if($mov){$cost=(float)$row['custo_medio'];if($cost<=0)$cost=(float)$row['custo'];$obs='Devolução de matéria-prima pelo cancelamento do pedido #'.$orderId.($reason!==''?' - '.mb_substr($reason,0,120):'');if($withCosts)$mov->execute([$companyId,$mid,$orderId,$qty,$before,$after,$cost,round($cost*$qty,2),$cost,$cost,$obs]);else $mov->execute([$companyId,$mid,$orderId,$qty,$before,$after,$obs]);}
      $result['components']++;$result['quantity']+=$qty;
    }
    $updRows=$pdo->prepare("UPDATE produto_composicao_consumos SET status='ESTORNADO',reversed_at=NOW(),reverse_reason=? WHERE company_id=? AND order_id=? AND status='ATIVO'");
    $updRows->execute([$reason!==''?mb_substr($reason,0,255):null,$companyId,$orderId]);
    $result['quantity']=round($result['quantity'],6);
    if(product_composition_enabled($pdo,$companyId)) product_composition_recalculate_all($pdo,$companyId,'Cancelamento pedido #'.$orderId);
    return $result;
  }
}

if (!function_exists('product_composition_stats')) {
  function product_composition_stats(PDO $pdo, int $companyId, int $productId): array {
    $out=['individual'=>0.0,'calculated'=>0.0,'last_recalc'=>null];
    if($productId<=0 || !product_composition_schema_ready($pdo)) return $out;
    $st=$pdo->prepare('SELECT COALESCE(estoque_composicao_individual,0),COALESCE(estoque_composicao_calculado,0),composition_last_recalc_at FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$productId]);$r=$st->fetch(PDO::FETCH_NUM);if(!$r)return $out;
    return ['individual'=>(float)$r[0],'calculated'=>(float)$r[1],'last_recalc'=>$r[2]?:null];
  }
}
