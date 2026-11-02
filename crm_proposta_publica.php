<?php
declare(strict_types=1);
require_once __DIR__.'/config/pdo.php';
require_once __DIR__.'/config/crm_proposal_document.php';
$token=trim((string)($_GET['token']??''));
header('Cache-Control: no-store');
if(!preg_match('/^[a-f0-9]{64}$/D',$token)){http_response_code(404);exit('Proposta não encontrada ou indisponível.');}
$st=$pdo->prepare('SELECT p.*,a.customer_name,a.phone,a.email,c.name company_name,c.fantasy_name FROM crm_proposals p JOIN crm_attendances a ON a.id=p.attendance_id AND a.company_id=p.company_id JOIN companies c ON c.id=p.company_id WHERE p.share_token=? AND p.share_enabled=1 LIMIT 1');
$st->execute([$token]);$row=$st->fetch(PDO::FETCH_ASSOC);
if(!$row){http_response_code(404);exit('Proposta não encontrada ou indisponível.');}
$it=$pdo->prepare('SELECT description,quantity,unit_price,monthly_unit_price FROM crm_proposal_items WHERE company_id=? AND proposal_id=? ORDER BY sort_order,id');
$it->execute([(int)$row['company_id'],(int)$row['id']]);$items=$it->fetchAll(PDO::FETCH_ASSOC)?:[];
$company=trim((string)($row['fantasy_name']??''))?:trim((string)$row['company_name']);
crm_proposal_document_render($row,$items,$row,$company);
