<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_crm.php';
require_once __DIR__.'/../config/crm_proposal_document.php';
$cid=crm_company_id();$attId=(int)($_GET['atendimento']??0);$id=(int)($_GET['id']??0);
$customer=crm_attendance($pdo,$cid,$attId);$proposal=crm_commercial_proposal($pdo,$cid,$attId,$id);
if(!$customer||!$proposal){http_response_code(404);exit('Proposta não encontrada.');}
$items=crm_commercial_items($pdo,$cid,$id);
$company='Nexa Próspero';try{$st=$pdo->prepare('SELECT COALESCE(NULLIF(fantasy_name,\'\'),name) FROM companies WHERE id=?');$st->execute([$cid]);$company=(string)($st->fetchColumn()?:$company);}catch(Throwable $e){}
crm_proposal_document_render($proposal,$items,$customer,$company,'crm_proposta.php?atendimento='.$attId.'&id='.$id);
