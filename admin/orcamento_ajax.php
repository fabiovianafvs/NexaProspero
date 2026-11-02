<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/orcamento_module.php';
header('Content-Type: application/json; charset=utf-8');
$pdo=orc_db();$companyId=orc_company_id();
if($companyId<=0||!orc_module_enabled($pdo,$companyId)){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Módulo Orçamento não habilitado.'],JSON_UNESCAPED_UNICODE);exit;}
orc_require_schema($pdo);
function orc_json(array $d,int $s=200):void{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
function orc_ajax_tokens(string $q):array{return array_slice(preg_split('/\s+/u',trim($q),-1,PREG_SPLIT_NO_EMPTY) ?: [],0,6);}
function orc_ajax_col(PDO $pdo,string $table,string $col):bool{static $c=[];$k=$table.'.'.$col;if(array_key_exists($k,$c))return $c[$k];$st=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');$st->execute([$table,$col]);return $c[$k]=(bool)$st->fetchColumn();}
try{
 $action=(string)($_GET['action'] ?? '');
 if($action==='search_people'){
   $role=strtolower(trim((string)($_GET['role'] ?? 'customer')));
   $roleCol=match($role){'customer'=>'is_customer','seller'=>'is_seller','professional'=>'is_professional','carrier'=>'is_carrier',default=>''};
   if($roleCol==='')orc_json(['ok'=>false,'message'=>'Classificação inválida.'],422);
   $where=['company_id=?','active=1',"COALESCE($roleCol,0)=1"];$params=[$companyId];
   foreach(orc_ajax_tokens((string)($_GET['q'] ?? '')) as $t){$where[]='(name LIKE ? OR fantasy_name LIKE ? OR document LIKE ? OR whatsapp LIKE ? OR phone LIKE ?)';for($i=0;$i<5;$i++)$params[]=nexa_search_like($t);}
   $st=$pdo->prepare('SELECT id,name,fantasy_name,document,phone,whatsapp,zip_code,address,number,complement,district,city,state FROM finance_people WHERE '.implode(' AND ',$where).' ORDER BY name,id LIMIT 100');$st->execute($params);
   orc_json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
 }
 if($action==='search_items'){
   $q=(string)($_GET['q'] ?? '');$type=strtoupper(trim((string)($_GET['type'] ?? '')));$hideCost=function_exists('access_product_cost_hidden')?access_product_cost_hidden($pdo,$companyId):false;
   $hasDescricao=orc_ajax_col($pdo,'produtos','descricao');$hasCategoria=orc_ajax_col($pdo,'produtos','categoria');$hasNumero=orc_ajax_col($pdo,'produtos','numero_cadastro');$hasCodigo=orc_ajax_col($pdo,'produtos','codigo_firebird');$hasReferencia=orc_ajax_col($pdo,'produtos','referencia');$hasUnidade=orc_ajax_col($pdo,'produtos','unidade_estoque');$hasAtual=orc_ajax_col($pdo,'produtos','estoque_atual');$hasReservado=orc_ajax_col($pdo,'produtos','estoque_reservado');$hasService=orc_ajax_col($pdo,'produtos','is_service');$hasImagem=orc_ajax_col($pdo,'produtos','imagem');$hasCost=(!$hideCost)&&orc_ajax_col($pdo,'produtos','custo');
   $barcodeCol='';foreach(['codigo_barras','ean','gtin','barcode'] as $candidate){if(orc_ajax_col($pdo,'produtos',$candidate)){$barcodeCol=$candidate;break;}}
   $where=['company_id=?','COALESCE(ativo,1)=1'];$params=[$companyId];
   if($hasService){if($type==='PRODUTO')$where[]='COALESCE(is_service,0)=0';if($type==='SERVICO')$where[]='COALESCE(is_service,0)=1';}elseif($type==='SERVICO')orc_json(['ok'=>true,'rows'=>[]]);
   foreach(orc_ajax_tokens($q) as $t){$like=nexa_search_like($t);$parts=['nome LIKE ?','CAST(id AS CHAR) LIKE ?'];$params[]=$like;$params[]=$like;if($hasDescricao){$parts[]='descricao LIKE ?';$params[]=$like;}if($hasCategoria){$parts[]='categoria LIKE ?';$params[]=$like;}if($hasNumero){$parts[]='CAST(numero_cadastro AS CHAR) LIKE ?';$params[]=$like;}if($hasCodigo){$parts[]='codigo_firebird LIKE ?';$params[]=$like;}if($hasReferencia){$parts[]='referencia LIKE ?';$params[]=$like;}if($barcodeCol!==''){$parts[]='`'.$barcodeCol.'` LIKE ?';$params[]=$like;}$where[]='('.implode(' OR ',$parts).')';}
   $sel=['id','nome',$hasDescricao?'descricao':"'' descricao",$hasCategoria?'categoria':"'' categoria",'preco',$hasUnidade?"COALESCE(NULLIF(unidade_estoque,''),'UN') unidade_estoque":"'UN' unidade_estoque",$hasAtual?'COALESCE(estoque_atual,0) estoque_atual':'0 estoque_atual',$hasReservado?'COALESCE(estoque_reservado,0) estoque_reservado':'0 estoque_reservado',$hasService?'COALESCE(is_service,0) is_service':'0 is_service',$hasNumero?'numero_cadastro':'NULL numero_cadastro',$hasCodigo?'codigo_firebird':'NULL codigo_firebird',$hasReferencia?'referencia':'NULL referencia',$barcodeCol!==''?'`'.$barcodeCol.'` barcode':"'' barcode",$hasImagem?'imagem':"'' imagem",$hasCost?'COALESCE(custo,0) custo':'NULL custo'];
   $st=$pdo->prepare('SELECT '.implode(',',$sel).' FROM produtos WHERE '.implode(' AND ',$where).' ORDER BY '.($hasService?'COALESCE(is_service,0),':'').' nome,id LIMIT 120');$st->execute($params);
   $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
   foreach($rows as &$row){$img=trim((string)($row['imagem']??''));$row['image_url']=$img!==''?('/uploads/products/'.rawurlencode($img)):'/assets/no-image.svg';$row['available_qty']=max(0,(float)($row['estoque_atual']??0)-(float)($row['estoque_reservado']??0));}unset($row);
   orc_json(['ok'=>true,'rows'=>$rows]);
 }
 orc_json(['ok'=>false,'message'=>'Ação não encontrada.'],404);
}catch(Throwable $e){orc_json(['ok'=>false,'message'=>$e->getMessage()],500);}
