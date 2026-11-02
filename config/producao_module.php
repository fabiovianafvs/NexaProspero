<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Ordem de Produção (V1)
 *
 * Regras centrais:
 * - produto com "Possui produção" usa estoque físico normal;
 * - ficha técnica da produção é separada da composição automática de venda;
 * - ao gerar O.P. as matérias-primas são consumidas imediatamente;
 * - modo automático: na mesma geração, os acabados entram no estoque e a O.P. finaliza;
 * - modo por setor: a O.P. fica EM_PROCESSO e os acabados entram apenas ao finalizar;
 * - variação é informada por matéria-prima, em quantidade (+ = consumo extra/perda, - = economia);
 * - toda movimentação é auditada em estoque_movimentos.
 */

require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/stock_module.php';

if (!function_exists('production_table_exists')) {
  function production_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('production_column_exists')) {
  function production_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
      $st->execute([$table,$column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('production_enabled')) {
  function production_enabled(PDO $pdo, int $companyId): bool {
    return $companyId > 0 && function_exists('saas_company_has_module') && saas_company_has_module($pdo, $companyId, 'ordem_producao');
  }
}

if (!function_exists('production_schema_ready')) {
  function production_schema_ready(PDO $pdo): bool {
    return production_column_exists($pdo,'produtos','is_materia_prima')
      && production_column_exists($pdo,'produtos','possui_producao')
      && production_table_exists($pdo,'producao_settings')
      && production_table_exists($pdo,'producao_ficha_itens')
      && production_table_exists($pdo,'producao_ordens')
      && production_table_exists($pdo,'producao_ordem_itens')
      && production_table_exists($pdo,'producao_ordem_materiais')
      && production_table_exists($pdo,'producao_sequences');
  }
}

if (!function_exists('production_ensure_schema')) {
  function production_ensure_schema(PDO $pdo): bool {
    if (production_schema_ready($pdo)) return true;
    if ($pdo->inTransaction()) return false;
    try { stock_ensure_schema($pdo, true); } catch (Throwable $e) {}

    if (!production_column_exists($pdo,'produtos','is_service')) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN is_service TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }
    if (!production_column_exists($pdo,'produtos','is_materia_prima')) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN is_materia_prima TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }
    if (!production_column_exists($pdo,'produtos','possui_producao')) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN possui_producao TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS producao_settings (
        company_id BIGINT UNSIGNED NOT NULL,
        order_by_sector TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        PRIMARY KEY (company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS producao_ficha_itens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        materia_prima_id BIGINT UNSIGNED NOT NULL,
        quantidade DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_producao_ficha_item (company_id,produto_id,materia_prima_id),
        KEY idx_producao_ficha_produto (company_id,produto_id),
        KEY idx_producao_ficha_materia (company_id,materia_prima_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS producao_sequences (
        company_id BIGINT UNSIGNED NOT NULL,
        next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME NULL,
        PRIMARY KEY (company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS producao_ordens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        numero_op BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'EM_PROCESSO',
        order_by_sector_snapshot TINYINT(1) NOT NULL DEFAULT 0,
        observacao TEXT NULL,
        variacao_observacao VARCHAR(500) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        canceled_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_producao_ordem_numero (company_id,numero_op),
        KEY idx_producao_ordem_status (company_id,status,created_at),
        KEY idx_producao_ordem_created (company_id,created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS producao_ordem_itens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        ordem_id BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        quantidade_planejada DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
        quantidade_produzida DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
        estoque_anterior DECIMAL(18,4) NULL,
        estoque_posterior DECIMAL(18,4) NULL,
        produced_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_producao_ordem_item (company_id,ordem_id,produto_id),
        KEY idx_producao_ordem_item_produto (company_id,produto_id),
        KEY idx_producao_ordem_item_ordem (company_id,ordem_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS producao_ordem_materiais (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        ordem_id BIGINT UNSIGNED NOT NULL,
        materia_prima_id BIGINT UNSIGNED NOT NULL,
        quantidade_teorica DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
        variacao DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
        quantidade_consumida DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
        estoque_anterior DECIMAL(18,4) NULL,
        estoque_posterior DECIMAL(18,4) NULL,
        consumed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_producao_ordem_material (company_id,ordem_id,materia_prima_id),
        KEY idx_producao_ordem_material_materia (company_id,materia_prima_id),
        KEY idx_producao_ordem_material_ordem (company_id,ordem_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    return production_schema_ready($pdo);
  }
}

if (!function_exists('production_decimal')) {
  function production_decimal($value, int $decimals = 6): float {
    $raw = trim((string)$value);
    if ($raw === '') return 0.0;
    $raw = str_replace(['R$',' '], '', $raw);
    if (str_contains($raw, ',') && str_contains($raw, '.')) $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? round((float)$raw, $decimals) : 0.0;
  }
}

if (!function_exists('production_qty_label')) {
  function production_qty_label($value, int $decimals = 6): string {
    $n=(float)$value;
    if (abs($n-round($n))<0.0000005) return number_format($n,0,',','.');
    return rtrim(rtrim(number_format($n,$decimals,',','.'),'0'),',');
  }
}

if (!function_exists('production_settings')) {
  function production_settings(PDO $pdo, int $companyId): array {
    $out=['order_by_sector'=>0];
    if ($companyId<=0 || !production_schema_ready($pdo)) return $out;
    $st=$pdo->prepare('SELECT order_by_sector FROM producao_settings WHERE company_id=? LIMIT 1');
    $st->execute([$companyId]);
    $v=$st->fetchColumn();
    if ($v!==false) $out['order_by_sector']=(int)$v===1?1:0;
    return $out;
  }
}

if (!function_exists('production_save_settings')) {
  function production_save_settings(PDO $pdo, int $companyId, bool $orderBySector): void {
    if (!production_ensure_schema($pdo)) throw new RuntimeException('Não foi possível preparar a estrutura da Ordem de Produção.');
    $st=$pdo->prepare('INSERT INTO producao_settings (company_id,order_by_sector,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE order_by_sector=VALUES(order_by_sector),updated_at=NOW()');
    $st->execute([$companyId,$orderBySector?1:0]);
  }
}

if (!function_exists('production_finished_products')) {
  function production_finished_products(PDO $pdo, int $companyId, bool $activeOnly=true): array {
    if (!production_schema_ready($pdo)) return [];
    $sql="SELECT id,nome,COALESCE(unidade_estoque,'UN') unidade_estoque,COALESCE(estoque_atual,0) estoque_atual,COALESCE(ativo,1) ativo
      FROM produtos WHERE company_id=? AND COALESCE(possui_producao,0)=1 AND COALESCE(is_service,0)=0";
    if ($activeOnly) $sql.=' AND COALESCE(ativo,1)=1';
    $sql.=' ORDER BY nome,id';
    $st=$pdo->prepare($sql);$st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('production_raw_materials')) {
  function production_raw_materials(PDO $pdo, int $companyId, bool $activeOnly=true): array {
    if (!production_schema_ready($pdo)) return [];
    $sql="SELECT id,nome,COALESCE(unidade_estoque,'UN') unidade_estoque,COALESCE(estoque_atual,0) estoque_atual,COALESCE(ativo,1) ativo
      FROM produtos WHERE company_id=? AND COALESCE(is_materia_prima,0)=1";
    if ($activeOnly) $sql.=' AND COALESCE(ativo,1)=1';
    $sql.=' ORDER BY nome,id';
    $st=$pdo->prepare($sql);$st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('production_recipe_load')) {
  function production_recipe_load(PDO $pdo, int $companyId, int $productId): array {
    if ($productId<=0 || !production_schema_ready($pdo)) return [];
    $st=$pdo->prepare("SELECT f.id,f.materia_prima_id,f.quantidade,p.nome materia_prima_nome,COALESCE(p.unidade_estoque,'UN') unidade_estoque,COALESCE(p.estoque_atual,0) estoque_atual
      FROM producao_ficha_itens f JOIN produtos p ON p.company_id=f.company_id AND p.id=f.materia_prima_id
      WHERE f.company_id=? AND f.produto_id=? AND f.ativo=1 ORDER BY p.nome,f.id");
    $st->execute([$companyId,$productId]);
    return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('production_recipe_count')) {
  function production_recipe_count(PDO $pdo, int $companyId, int $productId): int {
    if ($productId<=0 || !production_schema_ready($pdo)) return 0;
    $st=$pdo->prepare('SELECT COUNT(*) FROM producao_ficha_itens WHERE company_id=? AND produto_id=? AND ativo=1 AND quantidade>0');
    $st->execute([$companyId,$productId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('production_component_used_count')) {
  function production_component_used_count(PDO $pdo, int $companyId, int $materialId): int {
    if ($materialId<=0 || !production_schema_ready($pdo)) return 0;
    $st=$pdo->prepare('SELECT COUNT(*) FROM producao_ficha_itens WHERE company_id=? AND materia_prima_id=? AND ativo=1');
    $st->execute([$companyId,$materialId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('production_recipe_save')) {
  function production_recipe_save(PDO $pdo, int $companyId, int $productId, array $materialIds, array $quantities): void {
    if (!production_schema_ready($pdo)) throw new RuntimeException('Estrutura de produção indisponível.');
    $st=$pdo->prepare('SELECT nome,COALESCE(possui_producao,0),COALESCE(is_service,0) FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$productId]);$prod=$st->fetch(PDO::FETCH_NUM);
    if(!$prod) throw new RuntimeException('Produto acabado não encontrado.');
    if((int)$prod[1]!==1) throw new RuntimeException('Marque "Possui Produção" no cadastro do produto antes de cadastrar a ficha técnica.');
    if((int)$prod[2]===1) throw new RuntimeException('Serviços não podem possuir ficha técnica de produção.');

    $seen=[];$rows=[];
    $check=$pdo->prepare('SELECT nome,COALESCE(is_materia_prima,0) FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    foreach($materialIds as $idx=>$rawId){
      $mid=(int)$rawId;$qty=production_decimal($quantities[$idx]??0,6);
      if($mid<=0 && $qty<=0) continue;
      if($mid<=0 || $qty<=0) throw new RuntimeException('Informe matéria-prima e quantidade válida em todas as linhas da ficha técnica.');
      if($mid===$productId) throw new RuntimeException('O produto acabado não pode ser matéria-prima dele mesmo.');
      if(isset($seen[$mid])) throw new RuntimeException('A mesma matéria-prima foi informada mais de uma vez.');
      $seen[$mid]=true;
      $check->execute([$companyId,$mid]);$mat=$check->fetch(PDO::FETCH_NUM);
      if(!$mat || (int)$mat[1]!==1) throw new RuntimeException('Um dos itens selecionados não está marcado como Matéria-prima.');
      $rows[]=[$mid,$qty];
    }
    if(!$rows) throw new RuntimeException('Inclua pelo menos uma matéria-prima na ficha técnica.');

    $started=!$pdo->inTransaction();if($started)$pdo->beginTransaction();
    try{
      $pdo->prepare('DELETE FROM producao_ficha_itens WHERE company_id=? AND produto_id=?')->execute([$companyId,$productId]);
      $ins=$pdo->prepare('INSERT INTO producao_ficha_itens (company_id,produto_id,materia_prima_id,quantidade,ativo,created_at) VALUES (?,?,?,?,1,NOW())');
      foreach($rows as [$mid,$qty])$ins->execute([$companyId,$productId,$mid,$qty]);
      if($started)$pdo->commit();
    }catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('production_normalize_finished_lines')) {
  function production_normalize_finished_lines(array $ids, array $qtys): array {
    $out=[];
    foreach($ids as $i=>$rawId){
      $pid=(int)$rawId;$qty=production_decimal($qtys[$i]??0,4);
      if($pid<=0 && $qty<=0) continue;
      if($pid<=0 || $qty<=0) throw new RuntimeException('Informe produto acabado e quantidade válida em todas as linhas.');
      $out[$pid]=round(($out[$pid]??0)+$qty,4);
    }
    if(!$out) throw new RuntimeException('Inclua pelo menos um produto acabado na O.P.');
    return $out;
  }
}

if (!function_exists('production_calculate_order')) {
  function production_calculate_order(PDO $pdo, int $companyId, array $finished, array $variations=[], bool $lock=false): array {
    if(!$finished) return ['products'=>[],'materials'=>[],'can_generate'=>false,'errors'=>['Inclua produtos na O.P.']];
    $ids=array_keys($finished);$ph=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT id,nome,COALESCE(unidade_estoque,'UN') unidade_estoque,COALESCE(estoque_atual,0) estoque_atual,COALESCE(possui_producao,0) possui_producao,COALESCE(is_service,0) is_service,COALESCE(ativo,1) ativo FROM produtos WHERE company_id=? AND id IN ($ph)".($lock?' FOR UPDATE':'');
    $st=$pdo->prepare($sql);$st->execute(array_merge([$companyId],$ids));
    $productsBy=[];foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$productsBy[(int)$r['id']]=$r;
    $errors=[];$products=[];
    foreach($finished as $pid=>$qty){
      $r=$productsBy[$pid]??null;
      if(!$r){$errors[]='Produto acabado #'.$pid.' não encontrado.';continue;}
      if((int)$r['possui_producao']!==1)$errors[]='O produto '.$r['nome'].' não está marcado como Possui Produção.';
      if((int)$r['is_service']===1)$errors[]='O item '.$r['nome'].' é serviço e não pode entrar em O.P.';
      if((int)$r['ativo']!==1)$errors[]='O produto '.$r['nome'].' está inativo.';
      $products[$pid]=['id'=>$pid,'name'=>(string)$r['nome'],'unit'=>(string)$r['unidade_estoque'],'qty'=>(float)$qty,'stock'=>(float)$r['estoque_atual']];
    }

    $st=$pdo->prepare("SELECT f.produto_id,f.materia_prima_id,f.quantidade,m.nome materia_nome,COALESCE(m.unidade_estoque,'UN') materia_unidade,COALESCE(m.estoque_atual,0) materia_estoque,COALESCE(m.is_materia_prima,0) is_materia_prima
      FROM producao_ficha_itens f JOIN produtos m ON m.company_id=f.company_id AND m.id=f.materia_prima_id
      WHERE f.company_id=? AND f.produto_id IN ($ph) AND f.ativo=1 AND f.quantidade>0 ORDER BY f.produto_id,f.materia_prima_id");
    $st->execute(array_merge([$companyId],$ids));$recipeRows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $recipeCount=[];$theoretical=[];$matMeta=[];
    foreach($recipeRows as $r){
      $pid=(int)$r['produto_id'];$mid=(int)$r['materia_prima_id'];
      if(!isset($finished[$pid]))continue;
      $recipeCount[$pid]=($recipeCount[$pid]??0)+1;
      $need=round((float)$r['quantidade']*(float)$finished[$pid],6);
      $theoretical[$mid]=round(($theoretical[$mid]??0)+$need,6);
      $matMeta[$mid]=['id'=>$mid,'name'=>(string)$r['materia_nome'],'unit'=>(string)$r['materia_unidade'],'stock'=>(float)$r['materia_estoque'],'is_raw'=>(int)$r['is_materia_prima']];
    }
    foreach($finished as $pid=>$qty){if(($recipeCount[$pid]??0)<=0 && isset($products[$pid]))$errors[]='O produto '.$products[$pid]['name'].' ainda não possui ficha técnica de produção.';}

    if($lock && $matMeta){
      $mids=array_keys($matMeta);$mph=implode(',',array_fill(0,count($mids),'?'));
      $lockSt=$pdo->prepare("SELECT id,nome,COALESCE(unidade_estoque,'UN') unidade_estoque,COALESCE(estoque_atual,0) estoque_atual,COALESCE(is_materia_prima,0) is_materia_prima FROM produtos WHERE company_id=? AND id IN ($mph) ORDER BY id FOR UPDATE");
      $lockSt->execute(array_merge([$companyId],$mids));
      foreach($lockSt->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$mid=(int)$r['id'];$matMeta[$mid]=['id'=>$mid,'name'=>(string)$r['nome'],'unit'=>(string)$r['unidade_estoque'],'stock'=>(float)$r['estoque_atual'],'is_raw'=>(int)$r['is_materia_prima']];}
    }

    $materials=[];ksort($theoretical,SORT_NUMERIC);
    foreach($theoretical as $mid=>$base){
      $meta=$matMeta[$mid]??['id'=>$mid,'name'=>'#'.$mid,'unit'=>'UN','stock'=>0,'is_raw'=>0];
      $variation=production_decimal($variations[$mid]??0,6);
      $actual=round($base+$variation,6);
      if($actual<0){$errors[]='A variação de '.$meta['name'].' não pode deixar o consumo abaixo de zero.';$actual=0;}
      if((int)$meta['is_raw']!==1)$errors[]=$meta['name'].' não está mais marcado como Matéria-prima.';
      $enough=((float)$meta['stock']+0.000005)>=$actual;
      if(!$enough)$errors[]='Estoque insuficiente de '.$meta['name'].'. Necessário '.production_qty_label($actual).' '.$meta['unit'].'; disponível '.production_qty_label($meta['stock']).' '.$meta['unit'].'.';
      $materials[$mid]=['id'=>$mid,'name'=>$meta['name'],'unit'=>$meta['unit'],'stock'=>(float)$meta['stock'],'theoretical'=>(float)$base,'variation'=>$variation,'actual'=>$actual,'enough'=>$enough];
    }
    return ['products'=>array_values($products),'materials'=>array_values($materials),'can_generate'=>!$errors && (bool)$materials,'errors'=>array_values(array_unique($errors))];
  }
}

if (!function_exists('production_next_number')) {
  function production_next_number(PDO $pdo, int $companyId): int {
    $pdo->prepare('INSERT IGNORE INTO producao_sequences (company_id,next_number,updated_at) VALUES (?,1,NOW())')->execute([$companyId]);
    $st=$pdo->prepare('SELECT next_number FROM producao_sequences WHERE company_id=? FOR UPDATE');$st->execute([$companyId]);
    $n=max(1,(int)$st->fetchColumn());
    $pdo->prepare('UPDATE producao_sequences SET next_number=?,updated_at=NOW() WHERE company_id=?')->execute([$n+1,$companyId]);
    return $n;
  }
}

if (!function_exists('production_stock_cost_columns')) {
  function production_stock_cost_columns(PDO $pdo): bool {
    return production_column_exists($pdo,'estoque_movimentos','custo_unitario')
      && production_column_exists($pdo,'estoque_movimentos','valor_movimento')
      && production_column_exists($pdo,'estoque_movimentos','custo_medio_anterior')
      && production_column_exists($pdo,'estoque_movimentos','custo_medio_posterior');
  }
}

if (!function_exists('production_stock_change')) {
  function production_stock_change(PDO $pdo, int $companyId, int $productId, float $qty, string $direction, string $originType, int $originId, string $observation): array {
    $qty=round(abs($qty),6);
    $costFields=[];
    if(production_column_exists($pdo,'produtos','custo_medio'))$costFields[]='COALESCE(custo_medio,0) custo_medio';
    if(production_column_exists($pdo,'produtos','custo'))$costFields[]='COALESCE(custo,0) custo';
    $select='SELECT nome,COALESCE(estoque_atual,0) estoque_atual'.($costFields?','.implode(',',$costFields):'').' FROM produtos WHERE company_id=? AND id=? FOR UPDATE';
    $st=$pdo->prepare($select);$st->execute([$companyId,$productId]);$r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new RuntimeException('Produto #'.$productId.' não encontrado para movimentação da O.P.');
    $before=round((float)$r['estoque_atual'],4);
    if($qty<=0)return ['before'=>$before,'after'=>$before];
    $after=round($direction==='saida'?$before-$qty:$before+$qty,4);
    if($direction==='saida' && $after < -0.00005) throw new RuntimeException('Estoque insuficiente de '.$r['nome'].' durante a geração da O.P.');
    $pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?')->execute([$after,$companyId,$productId]);

    if(production_table_exists($pdo,'estoque_movimentos')){
      $withCosts=production_stock_cost_columns($pdo);
      $cost=(float)($r['custo_medio']??0);if($cost<=0)$cost=(float)($r['custo']??0);
      if($withCosts){
        $mv=$pdo->prepare('INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,custo_unitario,valor_movimento,custo_medio_anterior,custo_medio_posterior,observacao) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $mv->execute([$companyId,$productId,$direction,$originType,$originId,$qty,$before,$after,$cost,round($cost*$qty,2),$cost,$cost,$observation]);
      }else{
        $mv=$pdo->prepare('INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao) VALUES (?,?,?,?,?,?,?,?,?)');
        $mv->execute([$companyId,$productId,$direction,$originType,$originId,$qty,$before,$after,$observation]);
      }
    }
    return ['before'=>$before,'after'=>$after];
  }
}

if (!function_exists('production_recalculate_compositions')) {
  function production_recalculate_compositions(PDO $pdo, int $companyId, string $reason): void {
    try{
      $file=__DIR__.'/product_composition.php';if(is_file($file))require_once $file;
      if(function_exists('product_composition_enabled')&&function_exists('product_composition_schema_ready')&&function_exists('product_composition_recalculate_all')&&product_composition_enabled($pdo,$companyId)&&product_composition_schema_ready($pdo)){
        product_composition_recalculate_all($pdo,$companyId,$reason);
      }
    }catch(Throwable $e){}
  }
}

if (!function_exists('production_create_order')) {
  function production_create_order(PDO $pdo, int $companyId, int $userId, array $finished, array $variations=[], string $observation='', string $variationObservation=''): array {
    if(!production_enabled($pdo,$companyId))throw new RuntimeException('O módulo Ordem de Produção não está habilitado para esta empresa.');
    if(!production_schema_ready($pdo))throw new RuntimeException('A estrutura da Ordem de Produção ainda não está pronta.');
    $settings=production_settings($pdo,$companyId);$sector=!empty($settings['order_by_sector']);
    if($pdo->inTransaction())throw new RuntimeException('Não foi possível iniciar a O.P. porque existe outra transação em andamento.');
    $pdo->beginTransaction();
    try{
      $calc=production_calculate_order($pdo,$companyId,$finished,$variations,true);
      if(!$calc['can_generate'])throw new RuntimeException(implode(' ',$calc['errors']));
      $number=production_next_number($pdo,$companyId);
      $status=$sector?'EM_PROCESSO':'FINALIZADA';
      $st=$pdo->prepare('INSERT INTO producao_ordens (company_id,numero_op,status,order_by_sector_snapshot,observacao,variacao_observacao,created_by,created_at,started_at,finished_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW(),'.($sector?'NULL':'NOW()').')');
      $st->execute([$companyId,$number,$status,$sector?1:0,trim($observation)!==''?trim($observation):null,trim($variationObservation)!==''?mb_substr(trim($variationObservation),0,500):null,$userId>0?$userId:null]);
      $orderId=(int)$pdo->lastInsertId();

      $insItem=$pdo->prepare('INSERT INTO producao_ordem_itens (company_id,ordem_id,produto_id,quantidade_planejada,quantidade_produzida,estoque_anterior,estoque_posterior,produced_at) VALUES (?,?,?,?,?,?,?,?)');
      foreach($calc['products'] as $p){$insItem->execute([$companyId,$orderId,(int)$p['id'],(float)$p['qty'],0,null,null,null]);}

      $insMat=$pdo->prepare('INSERT INTO producao_ordem_materiais (company_id,ordem_id,materia_prima_id,quantidade_teorica,variacao,quantidade_consumida,estoque_anterior,estoque_posterior,consumed_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
      foreach($calc['materials'] as $m){
        $move=production_stock_change($pdo,$companyId,(int)$m['id'],(float)$m['actual'],'saida','ORDEM_PRODUCAO_CONSUMO',$orderId,'Consumo da O.P. #'.str_pad((string)$number,6,'0',STR_PAD_LEFT));
        $insMat->execute([$companyId,$orderId,(int)$m['id'],(float)$m['theoretical'],(float)$m['variation'],(float)$m['actual'],$move['before'],$move['after']]);
      }

      if(!$sector){
        $updItem=$pdo->prepare('UPDATE producao_ordem_itens SET quantidade_produzida=?,estoque_anterior=?,estoque_posterior=?,produced_at=NOW() WHERE company_id=? AND ordem_id=? AND produto_id=?');
        foreach($calc['products'] as $p){
          $move=production_stock_change($pdo,$companyId,(int)$p['id'],(float)$p['qty'],'entrada','ORDEM_PRODUCAO_ENTRADA',$orderId,'Entrada de produto acabado da O.P. #'.str_pad((string)$number,6,'0',STR_PAD_LEFT));
          $updItem->execute([(float)$p['qty'],$move['before'],$move['after'],$companyId,$orderId,(int)$p['id']]);
        }
      }
      $pdo->commit();
      production_recalculate_compositions($pdo,$companyId,'Consumo de matéria-prima na O.P. #'.$number);
      return ['id'=>$orderId,'number'=>$number,'status'=>$status,'sector'=>$sector];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('production_finalize_order')) {
  function production_finalize_order(PDO $pdo, int $companyId, int $orderId): void {
    if($pdo->inTransaction())throw new RuntimeException('Existe outra transação em andamento.');
    $pdo->beginTransaction();
    try{
      $st=$pdo->prepare('SELECT numero_op,status FROM producao_ordens WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$orderId]);$o=$st->fetch(PDO::FETCH_ASSOC);
      if(!$o)throw new RuntimeException('O.P. não encontrada.');
      if((string)$o['status']!=='EM_PROCESSO')throw new RuntimeException('Somente uma O.P. em processo pode ser finalizada.');
      $it=$pdo->prepare('SELECT produto_id,quantidade_planejada FROM producao_ordem_itens WHERE company_id=? AND ordem_id=? ORDER BY id FOR UPDATE');$it->execute([$companyId,$orderId]);$items=$it->fetchAll(PDO::FETCH_ASSOC)?:[];
      if(!$items)throw new RuntimeException('A O.P. não possui produtos acabados.');
      $upd=$pdo->prepare('UPDATE producao_ordem_itens SET quantidade_produzida=?,estoque_anterior=?,estoque_posterior=?,produced_at=NOW() WHERE company_id=? AND ordem_id=? AND produto_id=?');
      foreach($items as $r){$qty=(float)$r['quantidade_planejada'];$move=production_stock_change($pdo,$companyId,(int)$r['produto_id'],$qty,'entrada','ORDEM_PRODUCAO_ENTRADA',$orderId,'Finalização da O.P. #'.str_pad((string)$o['numero_op'],6,'0',STR_PAD_LEFT));$upd->execute([$qty,$move['before'],$move['after'],$companyId,$orderId,(int)$r['produto_id']]);}
      $pdo->prepare("UPDATE producao_ordens SET status='FINALIZADA',finished_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$orderId]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('production_cancel_process_order')) {
  function production_cancel_process_order(PDO $pdo, int $companyId, int $orderId): void {
    if($pdo->inTransaction())throw new RuntimeException('Existe outra transação em andamento.');
    $pdo->beginTransaction();
    try{
      $st=$pdo->prepare('SELECT numero_op,status FROM producao_ordens WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$orderId]);$o=$st->fetch(PDO::FETCH_ASSOC);
      if(!$o)throw new RuntimeException('O.P. não encontrada.');
      if((string)$o['status']!=='EM_PROCESSO')throw new RuntimeException('Somente uma O.P. em processo pode ser cancelada nesta versão.');
      $m=$pdo->prepare('SELECT materia_prima_id,quantidade_consumida FROM producao_ordem_materiais WHERE company_id=? AND ordem_id=? ORDER BY materia_prima_id FOR UPDATE');$m->execute([$companyId,$orderId]);
      foreach($m->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){production_stock_change($pdo,$companyId,(int)$r['materia_prima_id'],(float)$r['quantidade_consumida'],'entrada','ORDEM_PRODUCAO_CANCELAMENTO',$orderId,'Estorno de matéria-prima pelo cancelamento da O.P. #'.str_pad((string)$o['numero_op'],6,'0',STR_PAD_LEFT));}
      $pdo->prepare("UPDATE producao_ordens SET status='CANCELADA',canceled_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$orderId]);
      $pdo->commit();
      production_recalculate_compositions($pdo,$companyId,'Cancelamento da O.P. #'.$o['numero_op']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('production_order_load')) {
  function production_order_load(PDO $pdo, int $companyId, int $orderId): ?array {
    if($orderId<=0)return null;
    $st=$pdo->prepare('SELECT o.*,COALESCE(u.name,u.email,CONCAT("Usuário #",o.created_by)) created_by_name FROM producao_ordens o LEFT JOIN users u ON u.id=o.created_by WHERE o.company_id=? AND o.id=? LIMIT 1');
    try{$st->execute([$companyId,$orderId]);$order=$st->fetch(PDO::FETCH_ASSOC);}catch(Throwable $e){$st=$pdo->prepare('SELECT o.*,CONCAT("Usuário #",COALESCE(o.created_by,0)) created_by_name FROM producao_ordens o WHERE o.company_id=? AND o.id=? LIMIT 1');$st->execute([$companyId,$orderId]);$order=$st->fetch(PDO::FETCH_ASSOC);}
    if(!$order)return null;
    $it=$pdo->prepare("SELECT i.*,p.nome produto_nome,COALESCE(p.unidade_estoque,'UN') unidade_estoque FROM producao_ordem_itens i JOIN produtos p ON p.company_id=i.company_id AND p.id=i.produto_id WHERE i.company_id=? AND i.ordem_id=? ORDER BY i.id");$it->execute([$companyId,$orderId]);
    $mt=$pdo->prepare("SELECT m.*,p.nome materia_nome,COALESCE(p.unidade_estoque,'UN') unidade_estoque FROM producao_ordem_materiais m JOIN produtos p ON p.company_id=m.company_id AND p.id=m.materia_prima_id WHERE m.company_id=? AND m.ordem_id=? ORDER BY p.nome,m.id");$mt->execute([$companyId,$orderId]);
    $order['items']=$it->fetchAll(PDO::FETCH_ASSOC)?:[];$order['materials']=$mt->fetchAll(PDO::FETCH_ASSOC)?:[];
    return $order;
  }
}
