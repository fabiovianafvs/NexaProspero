<?php
declare(strict_types=1);
/** Nexa Compras - etapa 1: diagnóstico seguro, somente leitura. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/stock_module.php';
require __DIR__ . '/_chrome.php';
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo, (int)COMPANY_ID)) {
    $companyId = function_exists('multi_company_operational_company_id')
        ? (int)multi_company_operational_company_id($pdo, 'stock_read') : (int)COMPANY_ID;
    if ($companyId !== (int)COMPANY_ID && function_exists('multi_company_context_permission_allowed')
        && !multi_company_context_permission_allowed($pdo, 'produtos')) {
        if (function_exists('access_render_denied')) access_render_denied('Sem permissão para consultar o estoque da empresa selecionada.');
        http_response_code(403); exit('Sem permissão.');
    }
} else {
    $companyId = (int)COMPANY_ID;
}
function compras_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function compras_col(PDO $pdo, string $table, string $col): bool {
    static $cache=[]; $key="$table.$col";
    if (isset($cache[$key])) return $cache[$key];
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$col]); return $cache[$key]=(bool)$q->fetchColumn();
}
function compras_n($n, int $d=2): string { return number_format((float)$n,$d,',','.'); }
$start = (string)($_GET['inicio'] ?? date('Y-m-01', strtotime('-2 months')));
$end = (string)($_GET['fim'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end) || !strtotime($start) || !strtotime($end) || $start > $end) {
    $start=date('Y-m-01',strtotime('-2 months'));$end=date('Y-m-d');
}
$days=max(1,min(365,(int)($_GET['dias']??30)));
$keyword=mb_substr(trim((string)($_GET['q']??'')),0,120);
$category=mb_substr(trim((string)($_GET['categoria']??'')),0,120);
$processed=isset($_GET['processar']);
// Três competências de calendário ancoradas no fim da consulta (M0, M-1, M-2).
$monthKeys=[];
for ($mi=2;$mi>=0;$mi--) $monthKeys[]=date('Y-m',strtotime(date('Y-m-01',strtotime($end))." -$mi months"));

$hasCodigoProduto=compras_col($pdo,'produtos','codigo_firebird');
$hasNumeroCadastro=compras_col($pdo,'produtos','numero_cadastro');
$hasMapProduto=compras_col($pdo,'produtos_firebird_map','codigo_firebird') && compras_col($pdo,'produtos_firebird_map','produto_id') && compras_col($pdo,'produtos_firebird_map','company_id');
$codigoParts=[];
if($hasCodigoProduto) $codigoParts[]="NULLIF(TRIM(p.codigo_firebird),'')";
if($hasMapProduto) $codigoParts[]="NULLIF(TRIM(m.codigo_firebird),'')";
if($hasNumeroCadastro) $codigoParts[]="CAST(p.numero_cadastro AS CHAR)";
$codigoParts[]="CAST(p.id AS CHAR)";
$codigoSql='COALESCE('.implode(',',$codigoParts).')';
$mapJoin=$hasMapProduto?' LEFT JOIN produtos_firebird_map m ON m.company_id=p.company_id AND m.produto_id=p.id ':'';

// Layout do grid conforme Cadastro de Produtos: preferências por usuário/empresa no app_config.
$comprasGridUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
$comprasGridKey = 'ui_compras_grid_user_' . $comprasGridUserId;
$comprasColumns = ['selecionar','codigo','produto','categoria','unidade','estoque','reservado','disponivel','minimo','entrada_qtd','entrada_valor','pedido_qtd','pedido_valor','finalizado_qtd','finalizado_valor','finalizado_m2','finalizado_m1','finalizado_m0','finalizado_v_m2','finalizado_v_m1','finalizado_v_m0','media_finalizados','entrada_m2','entrada_m1','entrada_m0','entrada_v_m2','entrada_v_m1','entrada_v_m0','pedido_m2','pedido_m1','pedido_m0','pedido_v_m2','pedido_v_m1','pedido_v_m0','saida_estoque','saida_media','giro_estoque','cobertura_estoque','media','sugestao','quantidade'];
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['compras_grid_csrf'])) $_SESSION['compras_grid_csrf'] = bin2hex(random_bytes(24));
$comprasGridCsrf = (string)$_SESSION['compras_grid_csrf'];
function compras_grid_normalize(array $input, array $cols): array {
    $fixed=['selecionar','quantidade'];$optional=array_values(array_diff($cols,$fixed));
    $visible=[];
    foreach (($input['visible']??[]) as $c) if (is_string($c)&&in_array($c,$optional,true)&&!in_array($c,$visible,true)) $visible[]=$c;
    $order=[];
    foreach (($input['order']??[]) as $c) if (is_string($c)&&in_array($c,$optional,true)&&!in_array($c,$order,true)) $order[]=$c;
    $widths=[];
    foreach (($input['widths']??[]) as $c=>$w) if (in_array($c,$cols,true)&&is_numeric($w)&&$w>=65&&$w<=700) $widths[$c]=(int)$w;
    return ['visible'=>$visible,'order'=>$order,'widths'=>$widths];
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_compras_grid') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($comprasGridUserId<=0 || !hash_equals($comprasGridCsrf,(string)($_POST['csrf_token']??''))) throw new RuntimeException('Sessão inválida.');
        $data=json_decode((string)($_POST['preferences']??''),true);
        if (!is_array($data)) throw new RuntimeException('Preferência inválida.');
        $normalized=compras_grid_normalize($data,$comprasColumns);
        $stmt=$pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');
        $stmt->execute([$companyId,$comprasGridKey,json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        echo json_encode(['ok'=>true]);
    } catch(Throwable $e) { http_response_code(422);echo json_encode(['ok'=>false,'message'=>'Não foi possível salvar o layout.']); }
    exit;
}
$comprasSavedGrid=[];
if($comprasGridUserId>0){try{$stmt=$pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');$stmt->execute([$companyId,$comprasGridKey]);$v=$stmt->fetchColumn();if(is_string($v)){$d=json_decode($v,true);if(is_array($d))$comprasSavedGrid=compras_grid_normalize($d,$comprasColumns);}}catch(Throwable $e){error_log('[compras_grid] '.$e->getMessage());}}
$rows=[];$cats=[];$error='';$lowCount=0;$periodDays=max(1,(int)((strtotime($end)-strtotime($start))/86400)+1);$hasMov=false;$hasOrders=false;$hasFinalized=false;$hasStockExits=false;
try {
    $s=$pdo->prepare("SELECT DISTINCT categoria FROM produtos WHERE company_id=? AND categoria IS NOT NULL AND categoria<>'' ORDER BY categoria LIMIT 300");
    $s->execute([$companyId]);$cats=$s->fetchAll(PDO::FETCH_COLUMN)?:[];
    if ($processed) {
        $min=compras_col($pdo,'produtos','estoque_minimo')?'COALESCE(p.estoque_minimo,0)':'0';
        $stock=compras_col($pdo,'produtos','estoque_atual')?'COALESCE(p.estoque_atual,0)':'0';
        $reserve=compras_col($pdo,'produtos','estoque_reservado')?'COALESCE(p.estoque_reservado,0)':'0';
        $unit=compras_col($pdo,'produtos','unidade_estoque')?"COALESCE(p.unidade_estoque,'UN')":"'UN'";
        $sql="SELECT p.id, $codigoSql codigo_empresa, p.nome,COALESCE(p.categoria,'') categoria, $unit unidade,
              $stock saldo, $min minimo, $reserve reservado
              FROM produtos p $mapJoin WHERE p.company_id=?";
        $args=[$companyId];
        if ($keyword!=='') {$sql.=' AND (p.nome LIKE ? OR $codigoSql LIKE ?)'; $args[]='%'.$keyword.'%';$args[]='%'.$keyword.'%';}
        if ($category!=='') {$sql.=' AND p.categoria=?';$args[]=$category;}
        $sql.=' ORDER BY p.nome,p.id LIMIT 1000';
        $s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
        // Consulta em lote, sem N+1: apenas entradas de XML NFe efetivadas.
        $ids=array_map(static fn($r)=>(int)$r['id'],$rows);
        $purchaseById=[];$ordersById=[];$finalizadosById=[];$stockExitsById=[];$entryMonths=[];$orderMonths=[];$entryValueMonths=[];$orderValueMonths=[];$finalizedMonths=[];$finalizedValueMonths=[];
        if ($ids && compras_col($pdo,'estoque_movimentos','origem_tipo')) {
            $hasMov=true;
            $hasMovValue=compras_col($pdo,'estoque_movimentos','valor_movimento');
            $valueExpr=$hasMovValue ? "SUM(COALESCE(m.valor_movimento,0))" : 'NULL';
            $pl=implode(',',array_fill(0,count($ids),'?'));
            $sqlMov="SELECT m.produto_id, SUM(COALESCE(m.qtd,0)) qty, $valueExpr amount FROM estoque_movimentos m
                     WHERE m.company_id=? AND m.origem_tipo='XML_NFE' AND m.tipo='entrada'
                       AND m.created_at >= ? AND m.created_at < DATE_ADD(?,INTERVAL 1 DAY)
                       AND m.produto_id IN ($pl) GROUP BY m.produto_id";
            $sm=$pdo->prepare($sqlMov);$sm->execute(array_merge([$companyId,$start,$end],$ids));
            foreach($sm->fetchAll(PDO::FETCH_ASSOC) as $m) $purchaseById[(int)$m['produto_id']]=$m;
            $valueMonthExpr=$hasMovValue ? 'SUM(COALESCE(m.valor_movimento,0))' : 'NULL';
            $sm=$pdo->prepare("SELECT m.produto_id, DATE_FORMAT(m.created_at,'%Y-%m') mes, SUM(COALESCE(m.qtd,0)) qty, $valueMonthExpr amount FROM estoque_movimentos m
              WHERE m.company_id=? AND m.origem_tipo='XML_NFE' AND m.tipo='entrada'
              AND m.created_at>=? AND m.created_at<DATE_ADD(?,INTERVAL 1 DAY)
              AND m.produto_id IN ($pl) GROUP BY m.produto_id, DATE_FORMAT(m.created_at,'%Y-%m')");
            $sm->execute(array_merge([$companyId,$start,$end],$ids));
            foreach($sm->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $entryMonths[(int)$m['produto_id']][(string)$m['mes']]=(float)$m['qty'];
                if ($m['amount']!==null) $entryValueMonths[(int)$m['produto_id']][(string)$m['mes']]=(float)$m['amount'];
            }
        }
        // Saídas físicas de estoque: indicador operacional, não prova de venda fiscal concluída.
        // Consultar somente quando os campos existem e sem misturar ajustes/transferências em vendas.
        if ($ids && compras_col($pdo,'estoque_movimentos','produto_id')
            && compras_col($pdo,'estoque_movimentos','tipo') && compras_col($pdo,'estoque_movimentos','qtd')
            && compras_col($pdo,'estoque_movimentos','company_id') && compras_col($pdo,'estoque_movimentos','created_at')) {
            $pl=implode(',',array_fill(0,count($ids),'?'));
            $stOut=$pdo->prepare("SELECT m.produto_id, SUM(ABS(COALESCE(m.qtd,0))) qty
                  FROM estoque_movimentos m WHERE m.company_id=? AND m.tipo='saida'
                  AND m.created_at >= ? AND m.created_at < DATE_ADD(?,INTERVAL 1 DAY)
                  AND m.produto_id IN ($pl) GROUP BY m.produto_id");
            $stOut->execute(array_merge([$companyId,$start,$end],$ids));
            foreach ($stOut->fetchAll(PDO::FETCH_ASSOC) as $out) $stockExitsById[(int)$out['produto_id']]=(float)$out['qty'];
            $hasStockExits=true;
        }
        if ($ids && compras_col($pdo,'pedido_itens','qtd') && compras_col($pdo,'pedidos','created_at') && compras_col($pdo,'pedido_itens','produto_id')) {
            $hasOrders=true;
            $pl=implode(',',array_fill(0,count($ids),'?'));
            $hasOrderValue=compras_col($pdo,'pedido_itens','total');
            $amountExpr=$hasOrderValue?'SUM(COALESCE(pi.total,0))':'NULL';
            $whereStatus=compras_col($pdo,'pedidos','status')?" AND pe.status NOT IN ('cancelado','cancelada')":'';
            $stOrder=$pdo->prepare("SELECT pi.produto_id, SUM(COALESCE(pi.qtd,0)) qty, $amountExpr amount,
                SUM(CASE WHEN pe.created_at >= DATE_FORMAT(?, '%Y-%m-01') AND pe.created_at < DATE_ADD(LAST_DAY(?),INTERVAL 1 DAY) THEN pi.qtd ELSE 0 END) current_month
                FROM pedido_itens pi JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id
                WHERE pe.company_id=? AND pe.created_at>=? AND pe.created_at<DATE_ADD(?,INTERVAL 1 DAY)
                $whereStatus AND pi.produto_id IN ($pl) GROUP BY pi.produto_id");
            $stOrder->execute(array_merge([$end,$end,$companyId,$start,$end],$ids));
            foreach($stOrder->fetchAll(PDO::FETCH_ASSOC) as $o) $ordersById[(int)$o['produto_id']]=$o;
            // Pedidos com status finalizado: indicador comercial, independente da autorização fiscal.
            // Apenas leitura; não altera situações, estoque, financeiro nem emissão.
            if (compras_col($pdo,'pedidos','status')) {
                $hasFinalized=true;
                $stFinal=$pdo->prepare("SELECT pi.produto_id, SUM(COALESCE(pi.qtd,0)) qty, $amountExpr amount
                    FROM pedido_itens pi JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id
                    WHERE pe.company_id=? AND pe.status='finalizado'
                    AND pe.created_at>=? AND pe.created_at<DATE_ADD(?,INTERVAL 1 DAY)
                    AND pi.produto_id IN ($pl) GROUP BY pi.produto_id");
                $stFinal->execute(array_merge([$companyId,$start,$end],$ids));
                foreach($stFinal->fetchAll(PDO::FETCH_ASSOC) as $f) $finalizadosById[(int)$f['produto_id']]=$f;
                // Histórico mensal dos pedidos finalizados na data de criação, com o mesmo filtro de período.
                $stFinalMonth=$pdo->prepare("SELECT pi.produto_id, DATE_FORMAT(pe.created_at,'%Y-%m') mes,
                    SUM(COALESCE(pi.qtd,0)) qty, $amountExpr amount
                    FROM pedido_itens pi JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id
                    WHERE pe.company_id=? AND pe.status='finalizado'
                    AND pe.created_at>=? AND pe.created_at<DATE_ADD(?,INTERVAL 1 DAY)
                    AND pi.produto_id IN ($pl)
                    GROUP BY pi.produto_id, DATE_FORMAT(pe.created_at,'%Y-%m')");
                $stFinalMonth->execute(array_merge([$companyId,$start,$end],$ids));
                foreach($stFinalMonth->fetchAll(PDO::FETCH_ASSOC) as $fm) {
                    $finalizedMonths[(int)$fm['produto_id']][(string)$fm['mes']] = (float)$fm['qty'];
                    if ($fm['amount']!==null) $finalizedValueMonths[(int)$fm['produto_id']][(string)$fm['mes']] = (float)$fm['amount'];
                }
            }
            $amountMonthExpr=$hasOrderValue?'SUM(COALESCE(pi.total,0))':'NULL';
            $stOrder=$pdo->prepare("SELECT pi.produto_id, DATE_FORMAT(pe.created_at,'%Y-%m') mes, SUM(COALESCE(pi.qtd,0)) qty, $amountMonthExpr amount
              FROM pedido_itens pi JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id
              WHERE pe.company_id=? AND pe.created_at>=? AND pe.created_at<DATE_ADD(?,INTERVAL 1 DAY)
              $whereStatus AND pi.produto_id IN ($pl) GROUP BY pi.produto_id, DATE_FORMAT(pe.created_at,'%Y-%m')");
            $stOrder->execute(array_merge([$companyId,$start,$end],$ids));
            foreach($stOrder->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $orderMonths[(int)$o['produto_id']][(string)$o['mes']]=(float)$o['qty'];
                if ($o['amount']!==null) $orderValueMonths[(int)$o['produto_id']][(string)$o['mes']]=(float)$o['amount'];
            }
        }
        foreach ($rows as &$r) {
            $id=(int)$r['id'];
            $r['compras_qtd']=isset($purchaseById[$id])?(float)$purchaseById[$id]['qty']:0;
            $r['compras_valor']=isset($purchaseById[$id])&&$purchaseById[$id]['amount']!==null?(float)$purchaseById[$id]['amount']:null;
            $r['pedidos_qtd']=isset($ordersById[$id])?(float)$ordersById[$id]['qty']:0;
            $r['pedidos_valor']=isset($ordersById[$id])&&$ordersById[$id]['amount']!==null?(float)$ordersById[$id]['amount']:null;
            $r['finalizados_qtd']=isset($finalizadosById[$id])?(float)$finalizadosById[$id]['qty']:0.0;
            $r['finalizados_valor']=isset($finalizadosById[$id])&&$finalizadosById[$id]['amount']!==null?(float)$finalizadosById[$id]['amount']:null;
            $r['media_mensal_finalizados']=$hasFinalized ? $r['finalizados_qtd']*30/$periodDays : null;
            foreach($monthKeys as $idx=>$month){
                $r['entrada_m'.(2-$idx)] = $entryMonths[$id][$month]??0.0;
                $r['pedido_m'.(2-$idx)] = $orderMonths[$id][$month]??0.0;
                $r['finalizado_m'.(2-$idx)] = $finalizedMonths[$id][$month]??0.0;
                $r['finalizado_v_m'.(2-$idx)] = $hasFinalized && !empty($hasOrderValue) ? ($finalizedValueMonths[$id][$month]??0.0) : null;
                $r['entrada_v_m'.(2-$idx)] = isset($entryValueMonths[$id]) ? ($entryValueMonths[$id][$month]??0.0) : ($hasMov && !empty($hasMovValue) ? 0.0 : null);
                $r['pedido_v_m'.(2-$idx)] = isset($orderValueMonths[$id]) ? ($orderValueMonths[$id][$month]??0.0) : ($hasOrders && !empty($hasOrderValue) ? 0.0 : null);
            }
            $r['saidas_estoque']=$hasStockExits ? ($stockExitsById[$id]??0.0) : null;
            $r['media_diaria_saidas']=$hasStockExits ? ($r['saidas_estoque']/$periodDays) : null;
            $r['giro_saidas']=$hasStockExits && (float)$r['saldo']>0 ? ($r['saidas_estoque']/(float)$r['saldo']) : null;
            $r['media_mensal_pedidos']=$r['pedidos_qtd']*30/$periodDays;
            $r['disponivel']=(float)$r['saldo']-(float)$r['reservado'];
            $r['cobertura_saidas']=$hasStockExits && $r['media_diaria_saidas']>0 ? max(0,$r['disponivel'])/$r['media_diaria_saidas'] : null;
            // Proposta preliminar baseada em demanda de pedidos; a inclusão dos finalizados não modifica esta regra nem identifica notas autorizadas.
            $r['sugestao_preliminar']=(int)max(0,ceil($r['media_mensal_pedidos']*$days/30 - $r['disponivel']));
            $r['abaixo_minimo']=$r['disponivel']<(float)$r['minimo'];
            if ($r['abaixo_minimo']) $lowCount++;
        } unset($r);
    }
} catch(Throwable $e) { $error='Não foi possível concluir a consulta. Confirme a estrutura e permissões da base antes de utilizar este relatório.'; error_log('[compras_assistente] '.$e->getMessage()); }
admin_topbar('Assistente de Compras', 'produtos');
?>
<style>
.compras-wrap{margin:16px auto;width:calc(100% - 32px);max-width:none;min-width:0;box-sizing:border-box}.compras-wrap h1{color:#09245d;margin:0 0 5px;font-size:29px}.compras-wrap .muted{color:#60718c}.compras-filter{display:grid;grid-template-columns:repeat(2,minmax(145px,1fr)) 1.5fr 1.5fr minmax(100px,1fr) auto;gap:10px;align-items:end}.compras-filter label{font-size:12px;font-weight:700;color:#273d61}.compras-filter input,.compras-filter select{display:block;box-sizing:border-box;width:100%;border:1px solid #b8cbe6;border-radius:7px;padding:10px;background:white}.compras-filter button{background:#1557ba;color:#fff;border:0;border-radius:7px;padding:11px 18px;font-weight:800;cursor:pointer}.compras-note{margin:13px 0;padding:12px;border:1px solid #d9e3f4;background:#f3f7fd;border-radius:8px}.compras-data{overflow:auto;max-height:65vh}.compras-data table{border-collapse:collapse;width:100%;min-width:1050px;font-size:13px}.compras-data th{position:sticky;top:0;background:#e1edfd;color:#102f6f;white-space:nowrap;padding:11px 9px;text-align:left}.compras-data td{border-bottom:1px solid #e5edf7;padding:9px}.compras-data tbody tr:hover{background:#f6faff}.compras-data td.num{text-align:right;font-variant-numeric:tabular-nums}.compras-data .low{color:#ba1c1c;font-weight:700}.compras-wrap .metric{display:flex;gap:12px;margin:14px 0;flex-wrap:wrap}.compras-wrap .metric>div{background:white;border:1px solid #d9e3f4;border-radius:10px;padding:13px 18px;min-width:160px}.compras-wrap .metric strong{display:block;font-size:23px;color:#092b65}@media(max-width:1050px){.compras-filter{grid-template-columns:repeat(2,minmax(130px,1fr))}}
.compras-grid-tools{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin:12px 0;flex-wrap:wrap}
.compras-grid-tools details{position:relative}.compras-grid-tools summary{list-style:none;border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:8px 14px;cursor:pointer;font-weight:700;color:#19315a}
.compras-grid-tools summary::-webkit-details-marker{display:none}.compras-cols-menu{position:absolute;right:0;top:calc(100% + 6px);background:white;border:1px solid #d9e3f2;box-shadow:0 16px 36px #1b2d4a33;border-radius:10px;padding:14px;z-index:90;width:285px;max-height:440px;overflow:auto}
.compras-cols-menu label{display:block;margin:7px 0;font-size:12px}.compras-cols-menu button{border:1px solid #d2dced;background:#f8faff;border-radius:6px;padding:7px 10px;cursor:pointer}
.compras-data{border:1px solid #d8e3f3;border-radius:11px;overflow:auto;max-height:67vh;background:#fff;max-width:100%;min-width:0;box-sizing:border-box}
.compras-data table{border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:0;width:max-content;font-size:12px}
.compras-data th{position:sticky;top:0;z-index:4;text-align:left;background:#f3f7fe;border-bottom:1px solid #ccd8ea;border-right:1px solid #dce6f2;color:#142e66;padding:10px 8px;white-space:nowrap;overflow:visible;user-select:none}
.compras-data td{border-bottom:1px solid #dce5f1;border-right:1px solid #edf2f8;padding:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.compras-data tbody tr:nth-child(even){background:#e9f0f9}.compras-data tbody tr:hover{background:#dceafa}.compras-data td.num{text-align:right;font-variant-numeric:tabular-nums}.compras-data .low{color:#b91c1c;font-weight:700}
.compras-data .col-hidden{display:none!important}.compras-data .grid-th-inner{display:flex;align-items:center;gap:5px;min-height:22px}.compras-data .sort-link{flex:1;cursor:pointer;color:inherit;font-weight:800;text-decoration:none}
.compras-data .col-drag-handle{cursor:grab;color:#91a6c5;padding:2px 3px;font-size:12px}.compras-data .col-resizer{position:absolute;right:0;top:0;bottom:0;width:7px;cursor:col-resize;z-index:5}
.compras-data th.col-dragging{opacity:.4}.compras-data th.col-drop-before{box-shadow:inset 3px 0 #315ad8}.compras-data th.col-drop-after{box-shadow:inset -3px 0 #315ad8}
.compras-data .grid-filter-menu{position:relative;flex:none}.compras-data .grid-filter-menu>summary{list-style:none;border:1px solid transparent;border-radius:5px;padding:3px;cursor:pointer;color:#7b8ba6}.compras-data .grid-filter-menu>summary::-webkit-details-marker{display:none}
.compras-data .grid-filter-menu.has-filter>summary{color:#1d4ed8;background:#e7efff}.compras-data .grid-filter-popover{position:absolute;right:0;top:calc(100% + 6px);width:230px;padding:12px;border-radius:10px;border:1px solid #d6e1f0;background:#fff;box-shadow:0 15px 35px #12234233;z-index:100;color:#19315a}
.compras-data .grid-filter-popover input{box-sizing:border-box;width:100%;padding:8px;border:1px solid #c8d5e6;border-radius:6px;margin:8px 0;font-size:12px}
.compras-data .grid-filter-popover button{border:1px solid #b8c8dc;border-radius:6px;background:#f4f7fd;padding:6px 9px;cursor:pointer}
.compras-data .grid-filter-popover .apply{background:#1557ba;color:#fff}.compras-grid-status{font-size:12px;color:#64748b}
.compras-data .col-fixed{background:#f3f7fe}
/* Fase 1.4.2: rolamento restrito à caixa de resultados e espaço físico acima do grid.
   NÃO aplicar position:relative ao thead nem aos tr: isso interfere no sticky dos th. */
.compras-wrap{position:relative;z-index:0;isolation:isolate;padding-top:12px}
.compras-grid-tools{margin-bottom:18px}
.compras-data{position:relative;z-index:0;isolation:isolate;margin-top:14px;padding:0!important;overflow-x:auto;overflow-y:auto;max-height:clamp(280px,62dvh,780px);overscroll-behavior:contain;scrollbar-gutter:stable;background:#fff}
.compras-data table{border-spacing:0;border-collapse:separate}
.compras-list-actions{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:12px 0;padding:12px;background:#f4f8ff;border:1px solid #d7e3f6;border-radius:8px}
.compras-list-primary{border:0;border-radius:6px;padding:10px 16px;background:#166534;color:#fff;font-weight:700;cursor:pointer}
.compras-list-primary:hover{background:#14532d}
.compras-list-secondary{display:inline-block;border:1px solid #a9bedd;border-radius:6px;background:#fff;color:#164b8f;padding:9px 13px;text-decoration:none;font-weight:600}
.compras-list-actions .muted{font-size:12px}
.compras-data thead{position:static!important;z-index:auto!important}
.compras-data thead tr{position:static!important}
.compras-data thead th{position:sticky!important;top:0!important;z-index:20!important;background:#f3f7fe!important;background-clip:padding-box;box-shadow:inset 0 -2px #ccd8ea}
.compras-data thead th.col-fixed{background:#f3f7fe!important}
.compras-data tbody tr{position:static!important}
.compras-data tbody tr:nth-child(odd) td{background:#fff}
.compras-data tbody tr:nth-child(even) td{background:#e9f0f9}
.compras-data tbody tr:hover td{background:#dceafa}
/* Barra administrativa sempre acima da caixa de resultados durante o scroll da página. */
.topbar{z-index:80!important}
/* A largura cresce junto com o viewport CSS quando o zoom do navegador diminui. */
@media(max-width:760px){.compras-wrap{margin:10px auto;width:calc(100% - 16px)}.compras-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.compras-filter button{grid-column:1/-1}}
</style>
<div class="compras-wrap">
  <h1>Assistente de Compras</h1>
  <div class="card">
    <form class="compras-filter" method="get">
      <label>Período inicial<input type="date" name="inicio" value="<?=compras_h($start)?>"></label>
      <label>Período final<input type="date" name="fim" value="<?=compras_h($end)?>"></label>
      <label>Produto / código<input name="q" value="<?=compras_h($keyword)?>" placeholder="Pesquisar produto"></label>
      <label>Categoria<select name="categoria"><option value="">Todas</option><?php foreach($cats as $cat): ?><option value="<?=compras_h($cat)?>" <?=$cat===$category?'selected':''?>><?=compras_h($cat)?></option><?php endforeach; ?></select></label>
      <label>Cobertura (dias)<input type="number" name="dias" min="1" max="365" value="<?=$days?>"></label>
      <button type="submit" name="processar" value="1">Processar</button>
    </form>
  </div>
  <div class="compras-note"><strong>Indicadores identificados:</strong> Compras = entradas de XML NF-e movimentadas no estoque; Pedidos = quantidades registradas em pedidos não cancelados (podem não representar vendas fiscais concluídas). A sugestão é <strong>preliminar</strong>, baseada nesses pedidos, e não considera lead time, pedidos de compra pendentes ou devoluções conciliadas. Valores de compra são exibidos somente quando há <code>valor_movimento</code>. Finalizados = pedidos com status finalizado (pela data de criação), sem equivalência automática com NF-e autorizada. As colunas M-2, M-1 e M0 representam meses de calendário, limitados às datas filtradas. Saídas físicas e giro são indicadores operacionais: podem incluir baixas não fiscais, e não equivalem a vendas efetivadas. A seleção permite exportar CSV ou salvar uma lista em rascunho, sem gerar pedido, movimentar estoque ou faturar.</div>
  <?php if($error): ?><div class="compras-note" role="alert"><?=compras_h($error)?></div><?php endif; ?>
  <?php if($processed && !$error): ?>
    <div class="compras-list-actions" aria-label="Ações da lista de compras">
      <button type="button" id="compras-new-list" class="compras-list-primary">+ Nova Lista de Compras</button>
      <a href="compras_listas.php" class="compras-list-secondary">Consultar listas salvas</a>
      <span class="muted">Selecione os produtos no grid e informe as quantidades antes de criar a lista.</span>
    </div>
    <div class="metric"><div><span class="muted">Produtos exibidos</span><strong><?=count($rows)?></strong></div><div><span class="muted">Abaixo do estoque mínimo</span><strong><?=$lowCount?></strong></div></div>
    <div class="compras-note">Competências (M-2 / M-1 / M0): <?=compras_h(implode(" / ",$monthKeys))?>. Resultado limitado aos primeiros 1.000 produtos. Os totais financeiros ficam indisponíveis quando não há coluna de valor validada. Produtos com outras origens de compras ou vendas podem não estar refletidos nestes indicadores.</div>
    <div class="compras-grid-tools"><span class="compras-grid-status" id="compras-grid-status"></span><details id="compras-columns-picker"><summary>Colunas</summary><div class="compras-cols-menu"><strong>Colunas visíveis</strong><div id="compras-cols-panel"></div><p class="muted" style="font-size:11px">Preferências salvas no usuário por empresa. Arraste pelo marcador ⋮⋮ e ajuste a largura pela divisória.</p><button type="button" id="compras-reset-grid">Restaurar padrão</button></div></details></div>
    <div class="card compras-data"><table id="compras-grid"><thead><tr>
<th data-col="selecionar" class="col-fixed"><div class="grid-th-inner"><strong>Selecionar</strong></div></th>
<th data-col="codigo"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Código</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Código</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="produto"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Produto</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Produto</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="categoria"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Categoria</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Categoria</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="unidade"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Unid.</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Unid.</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="estoque"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Estoque</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Estoque</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="reservado"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Reservado</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Reservado</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="disponivel"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Disponível</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Disponível</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="minimo"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Mínimo</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Mínimo</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_qtd"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Entradas NF-e (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e (qtd.)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_valor"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Entradas NF-e (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_qtd"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Pedidos (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos (qtd.)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_valor"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Pedidos (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_qtd"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos com status finalizado, não necessariamente faturados">Finalizados (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados (qtd.)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_valor"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos com status finalizado, não necessariamente faturados">Finalizados (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_m2"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Finalizados M-2 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados M-2 (qtd.)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_m1"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Finalizados M-1 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados M-1 (qtd.)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_m0"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Finalizados M0 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados M0 (qtd.)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_v_m2"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Finalizados M-2 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados M-2 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_v_m1"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Finalizados M-1 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados M-1 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="finalizado_v_m0"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Finalizados M0 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Finalizados M0 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="media_finalizados"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Pedidos finalizados pela data de criação, não equivalem a NF-e autorizada">Média mensal finalizados</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Média mensal finalizados</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_m2"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Entradas NF-e M-2 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e M-2</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_m1"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Entradas NF-e M-1 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e M-1</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_m0"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Entradas NF-e M-0 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e M-0</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_v_m2"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Entradas NF-e M-2 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e M-2 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_v_m1"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Entradas NF-e M-1 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e M-1 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="entrada_v_m0"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Entradas NF-e M0 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Entradas NF-e M0 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_m2"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Pedidos M-2 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos M-2</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_m1"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Pedidos M-1 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos M-1</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_m0"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Pedidos M-0 (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos M-0</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_v_m2"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Pedidos M-2 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos M-2 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_v_m1"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Pedidos M-1 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos M-1 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="pedido_v_m0"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Pedidos M0 (R$)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Pedidos M0 (R$)</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="saida_estoque"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Saídas estoque (qtd.)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Saídas estoque</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="saida_media"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Média diária saídas</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Média diária saídas</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="giro_estoque"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Giro saídas / saldo</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Giro estimado</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="cobertura_estoque"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true">⋮⋮</span><span class="sort-link" role="button" tabindex="0">Cobertura saídas (dias)</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Cobertura em dias</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="media"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Média mensal de pedidos</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Média mensal de pedidos</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="sugestao"><div class="grid-th-inner"><span class="col-drag-handle" draggable="true" title="Arrastar coluna">⋮⋮</span><span class="sort-link" role="button" tabindex="0" title="Ordenar coluna">Sugestão preliminar</span><details class="grid-filter-menu"><summary title="Filtrar coluna">▿</summary><div class="grid-filter-popover"><b>Filtrar: Sugestão preliminar</b><input class="grid-filter-input" type="text" placeholder="Contém..."><div><button class="apply" type="button">Filtrar</button> <button class="clear" type="button">Limpar</button></div></div></details></div></th>
<th data-col="quantidade" class="col-fixed"><div class="grid-th-inner"><strong>Quantidade desejada</strong></div></th>
</tr></thead><tbody>
    <?php foreach($rows as $r): ?><tr data-id="<?=(int)$r['id']?>" data-codigo="<?=compras_h($r['codigo_empresa'])?>" data-nome="<?=compras_h($r['nome'])?>" data-unidade="<?=compras_h($r['unidade'])?>"><td data-col="selecionar"><input type="checkbox" class="buy-select" aria-label="Selecionar produto <?= (int)$r['id'] ?>"></td><td data-col="codigo"><?=compras_h($r['codigo_empresa'])?></td><td data-col="produto"><?=compras_h($r['nome'])?></td><td data-col="categoria"><?=compras_h($r['categoria'])?></td><td data-col="unidade"><?=compras_h($r['unidade'])?></td><td data-col="estoque" class="num"><?=compras_n($r['saldo'],3)?></td><td data-col="reservado" class="num"><?=compras_n($r['reservado'],3)?></td><td data-col="disponivel" class="num"><?=compras_n($r['disponivel'],3)?></td><td data-col="minimo" class="num"><?=compras_n($r['minimo'],3)?></td><td data-col="entrada_qtd" class="num"><?= $hasMov?compras_n($r['compras_qtd'],3):'—' ?></td><td data-col="entrada_valor" class="num"><?= $r['compras_valor']!==null?compras_n($r['compras_valor']):'—' ?></td><td data-col="pedido_qtd" class="num"><?= $hasOrders?compras_n($r['pedidos_qtd'],3):'—' ?></td><td data-col="pedido_valor" class="num"><?= $r['pedidos_valor']!==null?compras_n($r['pedidos_valor']):'—' ?></td><td data-col="finalizado_qtd" class="num"><?= $hasFinalized?compras_n($r['finalizados_qtd'],3):'—' ?></td><td data-col="finalizado_valor" class="num"><?= $r['finalizados_valor']!==null?compras_n($r['finalizados_valor']):($hasFinalized && !empty($hasOrderValue)?'0,00':'—') ?></td><td data-col="finalizado_m2" class="num"><?= $hasFinalized?compras_n($r['finalizado_m2'],3):'—' ?></td><td data-col="finalizado_m1" class="num"><?= $hasFinalized?compras_n($r['finalizado_m1'],3):'—' ?></td><td data-col="finalizado_m0" class="num"><?= $hasFinalized?compras_n($r['finalizado_m0'],3):'—' ?></td><td data-col="finalizado_v_m2" class="num"><?= $r['finalizado_v_m2']!==null?compras_n($r['finalizado_v_m2']):'—' ?></td><td data-col="finalizado_v_m1" class="num"><?= $r['finalizado_v_m1']!==null?compras_n($r['finalizado_v_m1']):'—' ?></td><td data-col="finalizado_v_m0" class="num"><?= $r['finalizado_v_m0']!==null?compras_n($r['finalizado_v_m0']):'—' ?></td><td data-col="media_finalizados" class="num"><?= $r['media_mensal_finalizados']!==null?compras_n($r['media_mensal_finalizados'],2):'—' ?></td><td data-col="entrada_m2" class="num"><?= $hasMov?compras_n($r['entrada_m2'],3):'—' ?></td><td data-col="entrada_m1" class="num"><?= $hasMov?compras_n($r['entrada_m1'],3):'—' ?></td><td data-col="entrada_m0" class="num"><?= $hasMov?compras_n($r['entrada_m0'],3):'—' ?></td><td data-col="entrada_v_m2" class="num"><?= $r['entrada_v_m2']!==null?compras_n($r['entrada_v_m2']):'—' ?></td><td data-col="entrada_v_m1" class="num"><?= $r['entrada_v_m1']!==null?compras_n($r['entrada_v_m1']):'—' ?></td><td data-col="entrada_v_m0" class="num"><?= $r['entrada_v_m0']!==null?compras_n($r['entrada_v_m0']):'—' ?></td><td data-col="pedido_m2" class="num"><?= $hasOrders?compras_n($r['pedido_m2'],3):'—' ?></td><td data-col="pedido_m1" class="num"><?= $hasOrders?compras_n($r['pedido_m1'],3):'—' ?></td><td data-col="pedido_m0" class="num"><?= $hasOrders?compras_n($r['pedido_m0'],3):'—' ?></td><td data-col="pedido_v_m2" class="num"><?= $r['pedido_v_m2']!==null?compras_n($r['pedido_v_m2']):'—' ?></td><td data-col="pedido_v_m1" class="num"><?= $r['pedido_v_m1']!==null?compras_n($r['pedido_v_m1']):'—' ?></td><td data-col="pedido_v_m0" class="num"><?= $r['pedido_v_m0']!==null?compras_n($r['pedido_v_m0']):'—' ?></td><td data-col="saida_estoque" class="num"><?= $r['saidas_estoque']!==null?compras_n($r['saidas_estoque'],3):'—' ?></td><td data-col="saida_media" class="num"><?= $r['media_diaria_saidas']!==null?compras_n($r['media_diaria_saidas'],3):'—' ?></td><td data-col="giro_estoque" class="num"><?= $r['giro_saidas']!==null?compras_n($r['giro_saidas'],2):'—' ?></td><td data-col="cobertura_estoque" class="num"><?= $r['cobertura_saidas']!==null?compras_n($r['cobertura_saidas'],1):'—' ?></td><td data-col="media" class="num"><?= $hasOrders?compras_n($r['media_mensal_pedidos'],2):'—' ?></td><td data-col="sugestao" class="num"><?= $hasOrders?compras_n($r['sugestao_preliminar'],0):'—' ?></td><td data-col="quantidade"><input aria-label="Quantidade desejada para produto <?= (int)$r['id'] ?>" type="number" class="buy-qty" min="0" step="1" value="<?= $hasOrders?(int)$r['sugestao_preliminar']:0 ?>" style="width:85px"></td></tr><?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="42">Nenhum produto encontrado.</td></tr><?php endif; ?></tbody></table></div>
    <div class="compras-note" id="compras-draft"><strong>Lista de Compras:</strong> <span id="compras-count">0 itens selecionados</span> <button type="button" id="compras-export" style="margin-left:12px;padding:8px 12px;background:#1557ba;color:#fff;border:0;border-radius:6px;cursor:pointer">Exportar seleção CSV</button> <button type="button" id="compras-save" style="margin-left:8px;padding:8px 12px;background:#166534;color:#fff;border:0;border-radius:6px;cursor:pointer">Salvar nova lista</button> <a href="compras_listas.php" style="margin-left:10px">Listas salvas</a> <span class="muted">Salvar cria somente um rascunho. Confirme as quantidades.</span></div>
    <script>
    (() => {
      const table=document.getElementById('compras-grid');if(!table)return;
      const wrap=table.closest('.compras-data');
      const cols=Array.from(table.tHead.rows[0].cells).map(c=>c.dataset.col);
      const optional=cols.filter(c=>c!=='selecionar'&&c!=='quantidade');
      const localKey='nexa.compras.grid.v2.company.<?= (int)$companyId ?>.user.<?= (int)$comprasGridUserId ?>';
      const server=<?= json_encode($comprasSavedGrid,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
      let prefs={};try{prefs=JSON.parse(localStorage.getItem(localKey)||'{}')||{}}catch(e){}
      if(Array.isArray(server.visible)&&server.visible.length)prefs=server;
      const defaults={codigo:100,produto:285,categoria:160,selecionar:95,quantidade:155};
      let order=optional.slice(),visible=optional.slice(),widths={};
      if(Array.isArray(prefs.order)){let valid=prefs.order.filter(c=>optional.includes(c));order=[...new Set(valid),...optional.filter(c=>!valid.includes(c))]}
      if(Array.isArray(prefs.visible))visible=optional.filter(c=>prefs.visible.includes(c));
      if(prefs.widths&&typeof prefs.widths==='object')widths=prefs.widths;
      const status=document.getElementById('compras-grid-status');
      let timeout;
      function cells(){return [...table.querySelectorAll('tr')].filter(r=>r.querySelector('[data-col]'))}
      function applyOrder(){let arrangement=['selecionar',...order,'quantidade'];cells().forEach(row=>{let map=new Map([...row.children].map(c=>[c.dataset.col,c]));arrangement.forEach(c=>{if(map.has(c))row.appendChild(map.get(c))})})}
      function refreshWidth(){let total=0;cols.forEach(c=>{const v=Number(widths[c]||defaults[c]||135);table.querySelectorAll('[data-col="'+c+'"]').forEach(el=>{el.style.width=v+'px';el.style.minWidth=v+'px';el.style.maxWidth=v+'px'});if(c==='selecionar'||c==='quantidade'||visible.includes(c))total+=v});table.style.width=Math.max(wrap.clientWidth,total)+'px'}
      function applyVisibility(){cols.forEach(c=>table.querySelectorAll('[data-col="'+c+'"]').forEach(el=>el.classList.toggle('col-hidden',c!=='selecionar'&&c!=='quantidade'&&!visible.includes(c))));refreshWidth()}
      function save(){try{localStorage.setItem(localKey,JSON.stringify({visible,order,widths}))}catch(e){}
        status.textContent='Salvando layout...';clearTimeout(timeout);timeout=setTimeout(async()=>{
          const body=new URLSearchParams({action:'save_compras_grid',csrf_token:<?= json_encode($comprasGridCsrf) ?>,preferences:JSON.stringify({visible,order,widths})});
          try{const r=await fetch('compras_assistente.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});const data=await r.json();if(!r.ok||!data.ok)throw Error();status.textContent='Layout salvo no usuário'}catch(e){status.textContent='Layout salvo neste navegador; falha ao sincronizar'}
        },350)}
      const panel=document.getElementById('compras-cols-panel');
      optional.forEach(c=>{const th=table.querySelector('th[data-col="'+c+'"]');const label=document.createElement('label');const input=document.createElement('input');input.type='checkbox';input.checked=visible.includes(c);input.onchange=()=>{visible=input.checked?[...visible,c]:visible.filter(x=>x!==c);applyVisibility();save()};label.append(input,document.createTextNode(' '+th.querySelector('.sort-link').textContent));panel.append(label)});
      document.getElementById('compras-reset-grid').onclick=()=>{visible=optional.slice();order=optional.slice();widths={};panel.querySelectorAll('input').forEach(x=>x.checked=true);applyOrder();applyVisibility();save()};
      function filterRows(){const searches=[...table.querySelectorAll('thead th')].map(th=>({col:th.dataset.col,q:th.querySelector('.grid-filter-input')?.value.trim().toLocaleLowerCase('pt-BR')||''})).filter(x=>x.q);
        table.tBodies[0].querySelectorAll('tr[data-id]').forEach(row=>{row.hidden=searches.some(x=>!((row.querySelector('td[data-col="'+x.col+'"]')?.textContent||'').toLocaleLowerCase('pt-BR').includes(x.q)))})}
      table.querySelectorAll('.grid-filter-menu').forEach(menu=>{
        menu.addEventListener('toggle',()=>{if(menu.open){table.querySelectorAll('.grid-filter-menu').forEach(other=>{if(other!==menu)other.open=false});setTimeout(()=>menu.querySelector('input').focus(),20)}});
        menu.querySelector('.apply').onclick=()=>{filterRows();menu.open=false};menu.querySelector('.clear').onclick=()=>{menu.querySelector('input').value='';filterRows();menu.open=false};menu.querySelector('input').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();filterRows();menu.open=false}}
      });
      document.addEventListener('click',e=>{table.querySelectorAll('.grid-filter-menu[open]').forEach(menu=>{if(!menu.contains(e.target))menu.open=false})});
      optional.forEach(c=>{
        const th=table.querySelector('th[data-col="'+c+'"]');const sort=th.querySelector('.sort-link');
        function sortRows(){let dir=th.dataset.dir==='asc'?'desc':'asc';table.querySelectorAll('thead th').forEach(x=>delete x.dataset.dir);th.dataset.dir=dir;sort.textContent=sort.textContent.replace(/ [↑↓]$/,'')+(dir==='asc'?' ↑':' ↓');let body=table.tBodies[0];let rows=[...body.querySelectorAll('tr[data-id]')];rows.sort((a,b)=>{const av=a.querySelector('td[data-col="'+c+'"]')?.textContent.trim()||'',bv=b.querySelector('td[data-col="'+c+'"]')?.textContent.trim()||'';const pn=t=>Number(t.replace(/\./g,'').replace(',','.'));const x=pn(av),y=pn(bv);const cmp=av&&bv&&Number.isFinite(x)&&Number.isFinite(y)?x-y:av.localeCompare(bv,'pt-BR',{numeric:true});return dir==='asc'?cmp:-cmp});rows.forEach(r=>body.appendChild(r))}
        sort.onclick=sortRows;sort.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();sortRows()}};
        const handle=th.querySelector('.col-drag-handle');handle.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/plain',c);th.classList.add('col-dragging')});handle.addEventListener('dragend',()=>th.classList.remove('col-dragging'));
        th.addEventListener('dragover',e=>{e.preventDefault();th.classList.add('col-drop-before')});th.addEventListener('dragleave',()=>th.classList.remove('col-drop-before'));
        th.addEventListener('drop',e=>{e.preventDefault();th.classList.remove('col-drop-before');const from=e.dataTransfer.getData('text/plain');if(!optional.includes(from)||from===c)return;order=order.filter(x=>x!==from);order.splice(order.indexOf(c),0,from);applyOrder();refreshWidth();save()});
        const resizer=document.createElement('span');resizer.className='col-resizer';resizer.title='Arraste para ajustar a largura';th.append(resizer);
        resizer.addEventListener('pointerdown',e=>{if(e.button!==0)return;e.stopPropagation();e.preventDefault();const sx=e.clientX,sw=th.getBoundingClientRect().width;const move=ev=>{widths[c]=Math.max(75,Math.min(700,Math.round(sw+ev.clientX-sx)));refreshWidth()};const up=()=>{document.removeEventListener('pointermove',move);save()};document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true})})
      });
      applyOrder();applyVisibility();window.addEventListener('resize',refreshWidth);
      if(typeof ResizeObserver!=='undefined'){const obs=new ResizeObserver(()=>refreshWidth());obs.observe(wrap)}
    })();
    </script>
    <script>
    (()=>{const t=document.getElementById('compras-grid');if(!t)return;
      const checked=()=>Array.from(t.querySelectorAll('tbody tr')).filter(r=>r.querySelector('.buy-select')?.checked);
      const update=()=>document.getElementById('compras-count').textContent=checked().length+' itens selecionados';
      t.addEventListener('change',update);
      document.getElementById('compras-new-list')?.addEventListener('click',()=>document.getElementById('compras-save')?.click());
      document.getElementById('compras-save').addEventListener('click',async()=>{
        const selecionados=checked();
        if(!selecionados.length){alert('Selecione pelo menos um produto.');return;}
        const itens=selecionados.map(r=>({produto_id:Number(r.dataset.id),quantidade:r.querySelector('.buy-qty').value}));
        if(!itens.every(i=>Number.isInteger(i.produto_id)&&i.produto_id>0&&/^\d{1,9}(?:\.\d{1,3})?$/.test(i.quantidade)&&Number(i.quantidade)>0)){
          alert('Informe quantidades positivas com até três casas decimais.');return;
        }
        const nome=prompt('Nome da lista de compras:','Lista de compras '+new Date().toLocaleDateString('pt-BR'));
        if(nome===null)return;
        const btn=document.getElementById('compras-save');btn.disabled=true;
        try {
          const params=new URLSearchParams({action:'create',csrf:<?= json_encode((string)($_SESSION['compras_listas_csrf'] ?? ($_SESSION['compras_listas_csrf']=bin2hex(random_bytes(24))))) ?>,nome,itens:JSON.stringify(itens)});
          const response=await fetch('compras_listas.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
          const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Falha ao salvar.');
          location.href='compras_listas.php?id='+encodeURIComponent(data.id);
        }catch(e){alert(e.message)}finally{btn.disabled=false}
      });
      document.getElementById('compras-export').addEventListener('click',()=>{
        const rows=checked().map(r=>[r.dataset.codigo,r.dataset.nome,r.dataset.unidade,r.querySelector('.buy-qty').value]);
        if(!rows.length){alert('Selecione pelo menos um produto.');return;}
        const valid=rows.every(r=>Number.isFinite(Number(r[3]))&&Number(r[3])>0);if(!valid){alert('Informe quantidades positivas nos itens selecionados.');return;}
        const csv=[['Codigo','Produto','Unidade','Quantidade'],...rows].map(fields=>fields.map(v=>'"'+String(v).replaceAll('"','""')+'"').join(';')).join('\r\n');
        const blob=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='nexa_lista_compras_'+new Date().toISOString().slice(0,10)+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
      });
    })();
    </script>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
