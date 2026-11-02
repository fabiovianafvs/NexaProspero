<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/contracts_module.php';
header('Content-Type: application/json; charset=utf-8');
$pdo=contracts_db();$cid=contracts_company_id();contracts_require_schema($pdo);
function cj(array $d,int $s=200):void{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
function ctokens(string $q):array{return array_slice(preg_split('/\s+/u',trim($q),-1,PREG_SPLIT_NO_EMPTY)?:[],0,6);}
function ccol(PDO $pdo,string $t,string $c):bool{static $x=[];$k="$t.$c";if(isset($x[$k]))return $x[$k];$st=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');$st->execute([$t,$c]);return $x[$k]=(bool)$st->fetchColumn();}
try{
  $action=(string)($_GET['action']??'');
  if($action==='people'){
    $role=(string)($_GET['role']??'customer');$roleCol=$role==='seller'?'is_seller':'is_customer';
    $where=['company_id=?','active=1',"COALESCE($roleCol,0)=1"];$p=[$cid];
    foreach(ctokens((string)($_GET['q']??'')) as $t){$where[]='(name LIKE ? OR fantasy_name LIKE ? OR document LIKE ? OR phone LIKE ? OR whatsapp LIKE ?)';for($i=0;$i<5;$i++)$p[]=nexa_search_like($t);}
    $st=$pdo->prepare('SELECT id,name,fantasy_name,document,phone,whatsapp FROM finance_people WHERE '.implode(' AND ',$where).' ORDER BY name LIMIT 100');$st->execute($p);cj(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)?:[]]);
  }
  if($action==='products'){
    $where=['company_id=?','COALESCE(ativo,1)=1'];$p=[$cid];
    foreach(ctokens((string)($_GET['q']??'')) as $t){
      $like=nexa_search_like($t);$parts=['nome LIKE ?'];$p[]=$like;
      if(ccol($pdo,'produtos','numero_cadastro')){$parts[]='CAST(numero_cadastro AS CHAR) LIKE ?';$p[]=$like;}
      if(ccol($pdo,'produtos','descricao')){$parts[]='descricao LIKE ?';$p[]=$like;}
      if(ccol($pdo,'produtos','referencia')){$parts[]='referencia LIKE ?';$p[]=$like;}
      foreach(['codigo_barras','ean','gtin','barcode'] as $bc){if(ccol($pdo,'produtos',$bc)){$parts[]="{$bc} LIKE ?";$p[]=$like;}}
      $where[]='('.implode(' OR ',$parts).')';
    }
    $sel=['id','nome','preco',ccol($pdo,'produtos','numero_cadastro')?'numero_cadastro':'NULL numero_cadastro',ccol($pdo,'produtos','referencia')?'referencia':'NULL referencia',ccol($pdo,'produtos','custo')?'COALESCE(custo,0) custo':'0 custo',ccol($pdo,'produtos','unidade_estoque')?"COALESCE(NULLIF(unidade_estoque,''),'UN') unidade_estoque":"'UN' unidade_estoque"];
    $st=$pdo->prepare('SELECT '.implode(',',$sel).' FROM produtos WHERE '.implode(' AND ',$where).' ORDER BY nome LIMIT 120');$st->execute($p);cj(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)?:[]]);
  }
  cj(['ok'=>false,'message'=>'Ação inválida.'],404);
}catch(Throwable $e){cj(['ok'=>false,'message'=>$e->getMessage()],500);}
