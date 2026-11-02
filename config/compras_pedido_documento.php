<?php
declare(strict_types=1);
/** Documento PDF exclusivamente dos pedidos de compra; nenhuma mutação de estoque ou fiscal. */
function compras_doc_text(string $text): string {
    $v = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    return $v === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $v;
}
function compras_doc_load(PDO $pdo, int $companyId, int $pedidoId): array {
    $s = $pdo->prepare('SELECT p.id,p.company_id,p.cotacao_id,p.fornecedor_nome,p.status,p.created_at,c.lista_id,l.nome lista_nome FROM compras_pedidos p JOIN compras_cotacoes c ON c.id=p.cotacao_id AND c.company_id=p.company_id JOIN compras_listas l ON l.id=c.lista_id AND l.company_id=p.company_id WHERE p.id=? AND p.company_id=? LIMIT 1');
    $s->execute([$pedidoId,$companyId]);
    $pedido=$s->fetch(PDO::FETCH_ASSOC);
    if (!$pedido) throw new RuntimeException('Pedido de compra não encontrado para esta empresa.');
    $s=$pdo->prepare('SELECT pr.nome,i.produto_id,i.quantidade,i.preco_unitario,i.desconto_percentual FROM compras_pedido_itens i JOIN produtos pr ON pr.id=i.produto_id AND pr.company_id=i.company_id WHERE i.pedido_id=? AND i.company_id=? ORDER BY i.id');
    $s->execute([$pedidoId,$companyId]);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
    $s->execute([$companyId]);
    $empresa=trim((string)$s->fetchColumn()) ?: 'Nexa Próspero';
    return ['pedido'=>$pedido,'itens'=>$items,'empresa'=>$empresa];
}
function compras_doc_total(array $itens): float {
    $total=0.0;
    foreach ($itens as $r) $total += (float)$r['quantidade'] * (float)$r['preco_unitario'] * (1 - (float)$r['desconto_percentual']/100);
    return $total;
}
function compras_doc_mensagem(array $data): string {
    $p=$data['pedido'];
    $t="PEDIDO DE COMPRA #".(int)$p['id']."\n".$data['empresa']."\nFornecedor: ".$p['fornecedor_nome']."\nData: ".date('d/m/Y',strtotime((string)$p['created_at']))."\n\n";
    foreach ($data['itens'] as $i) {
        $sub=(float)$i['quantidade']*(float)$i['preco_unitario']*(1-(float)$i['desconto_percentual']/100);
        $t.=trim((string)$i['nome'])." | ".number_format((float)$i['quantidade'],3,',','.')." x R$ ".number_format((float)$i['preco_unitario'],2,',','.')." | R$ ".number_format($sub,2,',','.')."\n";
    }
    return $t."\nTOTAL: R$ ".number_format(compras_doc_total($data['itens']),2,',','.')."\nDocumento comercial sujeito à confirmação do fornecedor. Não é documento fiscal.";
}
function compras_doc_pdf(array $data): array {
    $autoloader=__DIR__.'/../vendor/autoload.php';
    if (!is_file($autoloader)) throw new RuntimeException('Biblioteca PDF indisponível.');
    require_once $autoloader;
    if (!class_exists('NFePHP\\DA\\Legacy\\FPDF\\Fpdf')) throw new RuntimeException('Biblioteca FPDF indisponível.');
    $pdf=new \NFePHP\DA\Legacy\FPDF\Fpdf('P','mm','A4');
    $pdf->SetAutoPageBreak(true,18);
    $pdf->SetMargins(14,14,14);
    $pdf->AddPage();
    $p=$data['pedido'];
    $pdf->SetFont('Arial','B',15);
    $pdf->MultiCell(0,8,compras_doc_text($data['empresa']));
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(0,10,compras_doc_text('PEDIDO DE COMPRA #'.(int)$p['id']),0,1);
    $pdf->SetFont('Arial','',10);
    foreach (['Fornecedor: '.$p['fornecedor_nome'],'Lista: '.$p['lista_nome'],'Cotação: #'.$p['cotacao_id'],'Data: '.date('d/m/Y H:i',strtotime((string)$p['created_at'])),'Situação: '.$p['status']] as $line) $pdf->MultiCell(0,6,compras_doc_text($line));
    $pdf->Ln(4);
    $pdf->SetFillColor(234,242,252);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(90,8,'Produto',1,0,'L',true);
    $pdf->Cell(20,8,'Qtd.',1,0,'R',true);
    $pdf->Cell(24,8,'Unit. (R$)',1,0,'R',true);
    $pdf->Cell(20,8,'Desc. %',1,0,'R',true);
    $pdf->Cell(28,8,'Total (R$)',1,1,'R',true);
    $pdf->SetFont('Arial','',9);
    foreach ($data['itens'] as $i) {
        if ($pdf->GetY()>265) { $pdf->AddPage(); }
        $nome=compras_doc_text((string)$i['nome']);
        if (function_exists('mb_strimwidth')) $nome=mb_strimwidth($nome,0,48,'...','Windows-1252');
        else $nome=substr($nome,0,48);
        $sub=(float)$i['quantidade']*(float)$i['preco_unitario']*(1-(float)$i['desconto_percentual']/100);
        $pdf->Cell(90,8,$nome,1);
        $pdf->Cell(20,8,number_format((float)$i['quantidade'],3,',','.'),1,0,'R');
        $pdf->Cell(24,8,number_format((float)$i['preco_unitario'],2,',','.'),1,0,'R');
        $pdf->Cell(20,8,number_format((float)$i['desconto_percentual'],2,',','.'),1,0,'R');
        $pdf->Cell(28,8,number_format($sub,2,',','.'),1,1,'R');
    }
    $pdf->Ln(5);
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,8,'TOTAL: R$ '.number_format(compras_doc_total($data['itens']),2,',','.'),0,1,'R');
    $pdf->SetFont('Arial','',9);
    $pdf->MultiCell(0,6,compras_doc_text('Documento comercial. Não é documento fiscal nem comprovação de recebimento.'));
    return ['filename'=>'pedido_compra_'.(int)$p['id'].'.pdf','mimetype'=>'application/pdf','content'=>$pdf->Output('', 'S')];
}
