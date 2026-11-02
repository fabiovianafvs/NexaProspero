<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/order_reopen_guard.php';
require_once __DIR__ . '/../config/order_payment_reversal_assessment.php';
require_once __DIR__ . '/../config/order_reopen_transaction_check.php';
require_once __DIR__ . '/../config/order_rejected_nfe_discard.php';

// Diagnóstico conservador e somente leitura. Nenhum documento ou pedido é alterado.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id <= 0) { http_response_code(400); exit('Pedido inválido.'); }
$companyId = (int)COMPANY_ID;
$st=$pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
$st->execute([$companyId,$id]);
$order=$st->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Pedido não encontrado nesta empresa.'); }
$number=(string)($order['numero_pedido'] ?? $id);

// Descarte local de REJEICAO comprovada: independente de reabertura/estorno.
// Sem F11, mediante permissao fiscal e feature flag especifica por empresa.
$discardEnabled = nexa_rejected_nfe_discard_enabled($companyId, $pdo);
$discardAuthorized = $discardEnabled
  && function_exists('access_has_permission')
  && access_has_permission($pdo,$companyId,'fiscal_documentos')
  && !(defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
       && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID);
if (empty($_SESSION['nexa_rejected_nfe_csrf'])) $_SESSION['nexa_rejected_nfe_csrf']=bin2hex(random_bytes(32));
$discardError=''; $discardOk='';
$successKey='nexa_rejected_nfe_success_'.$companyId.'_'.$id;
if (!empty($_SESSION[$successKey])) { $discardOk=(string)$_SESSION[$successKey]; unset($_SESSION[$successKey]); }
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='discard_rejected_nfe') {
  try {
    if (!$discardAuthorized) throw new RuntimeException('Regularização fiscal não habilitada ou sem permissão nesta empresa.');
    if (!hash_equals((string)$_SESSION['nexa_rejected_nfe_csrf'],(string)($_POST['csrf']??''))) {
      throw new RuntimeException('Sessão expirada. Atualize a página.');
    }
    if ((string)($_POST['confirmation']??'')!=='DESCARTAR'
      || (string)($_POST['confirmed_sefaz']??'')!=='1') {
      throw new RuntimeException('Confirme a consulta prévia à SEFAZ e digite DESCARTAR.');
    }
    $nfcId=nexa_rejected_nfe_discard($pdo,$companyId,$id,(int)($_POST['doc_id']??0),
      (int)($_SESSION['user_id']??0),(string)($_POST['reason']??''));
    $_SESSION[$successKey]='NF-e rejeitada descartada somente no cadastro local. NFC-e autorizada #'.$nfcId.
      ' e pedido permanecem intactos. Histórico e XML da rejeição foram preservados.';
    header('Location: pedido_regularizacao.php?id='.$id);
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[NEXA_DISCARD_REJECTED_NFE_BLOCK] company='.$companyId.' order='.$id.
      ' doc='.(int)($_POST['doc_id']??0).' user='.(int)($_SESSION['user_id']??0).' '. $e->getMessage());
    $discardError=$e->getMessage();
  }
}

// Autorização independente da troca de vendedor no PDV.
$canChangeFinalSeller = function_exists('access_has_permission')
  && access_has_permission($pdo, $companyId, 'pedido_finalizado_alterar_vendedor');
// Permissão própria para regularização: não depende de 'allow_seller_change' do PDV.
// As restrições de empresa, situação e vínculo fiscal continuam verificadas no POST.
$changeSellerError=''; $changeSellerOk='';
if (empty($_SESSION['nexa_final_seller_csrf'])) $_SESSION['nexa_final_seller_csrf']=bin2hex(random_bytes(32));
$allSellers=[];
if ($canChangeFinalSeller) {
  try {
    $q=$pdo->prepare('SELECT id,name FROM finance_people WHERE company_id=? AND active=1 AND is_seller=1 ORDER BY name');
    $q->execute([$companyId]);$allSellers=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch(Throwable $e) { $canChangeFinalSeller=false; }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='change_final_seller') {
  try {
    if (!$canChangeFinalSeller) throw new RuntimeException('Usuário sem permissão para esta correção.');
    if (!hash_equals((string)$_SESSION['nexa_final_seller_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão inválida. Atualize a página.');
    $newSeller=(int)($_POST['seller_id']??0);
    if($newSeller<=0) throw new RuntimeException('Selecione um vendedor ativo.');
    $pdo->beginTransaction();
    // Proteção contra corrida com emissão fiscal: travar o pedido antes de consultar documentos.
    $q=$pdo->prepare('SELECT id,status,seller_id FROM pedidos WHERE company_id=? AND id=? FOR UPDATE');
    $q->execute([$companyId,$id]);$locked=$q->fetch(PDO::FETCH_ASSOC);
    if(!$locked || strtolower(trim((string)$locked['status']))!=='finalizado') throw new RuntimeException('Apenas pedidos finalizados podem utilizar esta correção.');
    $q=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_seller=1 LIMIT 1');
    $q->execute([$companyId,$newSeller]);if(!$q->fetchColumn()) throw new RuntimeException('Vendedor não pertence à empresa ou está inativo.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='fiscal_documents'");
    $q->execute();if(!(int)$q->fetchColumn()) throw new RuntimeException('Não é possível validar documentos fiscais; operação bloqueada.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM fiscal_documents WHERE company_id=? AND source_id=? AND UPPER(COALESCE(source_type,'')) IN ('PEDIDO','ORDER','VENDA')");
    $q->execute([$companyId,$id]);if((int)$q->fetchColumn()>0) throw new RuntimeException('Pedido possui documento fiscal vinculado. Utilize a regularização fiscal.');
    // Conferência extra por itens fiscais (abrange documentos cujo vínculo não é pelo source_id).
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='fiscal_document_items'");
    $q->execute();if(!(int)$q->fetchColumn()) throw new RuntimeException('Não foi possível auditar itens fiscais.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM fiscal_document_items i JOIN pedido_itens pi ON pi.id=i.order_item_id AND pi.company_id=i.company_id WHERE pi.company_id=? AND pi.pedido_id=?');
    $q->execute([$companyId,$id]);if((int)$q->fetchColumn()>0) throw new RuntimeException('Encontrados itens de documentos fiscais relacionados ao pedido.');
    $oldSeller=(int)($locked['seller_id']??0);
    if($oldSeller!==$newSeller){
      $q=$pdo->prepare('UPDATE pedidos SET seller_id=? WHERE company_id=? AND id=? AND status=? AND (seller_id <=> ?)');
      $q->execute([$newSeller,$companyId,$id,$locked['status'],$locked['seller_id']]);
      if($q->rowCount()!==1) throw new RuntimeException('O pedido mudou durante a operação. Atualize e tente novamente.');
    }
    $pdo->commit();
    // Registro operacional no log PHP; não altera estoque/financeiro/comissões.
    error_log('[NEXA_FINAL_SELLER_CHANGE] company='.$companyId.' order='.$id.' user='.(int)($_SESSION['user_id']??0).' old='.$oldSeller.' new='.$newSeller);
    $changeSellerOk='Vendedor atualizado. Pedido permanece finalizado; financeiro, estoque e comissões não foram recalculados.';
    $order['seller_id']=$newSeller;
  } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$changeSellerError=$e->getMessage();}
}

// Etapa 8: conferência sob lock do pedido, sem modificação de dados.
if (empty($_SESSION['nexa_reopen_check_csrf'])) $_SESSION['nexa_reopen_check_csrf']=bin2hex(random_bytes(32));
$transactionCheck=null;
$transactionCheckError='';
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='simulate_reopen') {
  try {
    if(!hash_equals((string)$_SESSION['nexa_reopen_check_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Token inválido. Atualize a página.');
    $transactionCheck=nexa_order_reopen_transaction_check($pdo,$companyId,$id);
  } catch(Throwable $e) {
    // A falha continua bloqueando a operação. Mostrar somente mensagem segura ao operador.
    // Detalhes técnicos ficam no log PHP, com ID para correlação.
    $diagnosticId = bin2hex(random_bytes(4));
    error_log('[NEXA_REOPEN_SIMULATION]['.$diagnosticId.'] company='.$companyId.' order='.$id.' '.$e->getMessage().' cause='.($e->getPrevious() ? $e->getPrevious()->getMessage() : 'none'));
    $transactionCheckError='Simulação interrompida (diagnóstico '.$diagnosticId.'). Nenhuma alteração realizada. Verifique o log PHP se persistir.';
  }
}

$docs=[]; $warnings=[]; $safeFiscal=true;
try {
  $check=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=database() AND table_name='fiscal_documents' LIMIT 1");
  $check->execute();
  if ($check->fetchColumn()) {
    $q=$pdo->prepare("SELECT id, model, series, number, status, environment, access_key, sefaz_status_code, sefaz_status_message FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=? ORDER BY id DESC");
    $q->execute([$companyId,$id]); $docs=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($docs as $d) {
      $s=strtoupper(trim((string)($d['status'] ?? '')));
      // Somente estes estados podem ser considerados tecnicamente candidatos a descarte,
      // nunca sem reconciliação com o autorizador e auditoria fiscal.
      if (!in_array($s,['PREPARADA','REJEITADA','PRE_NFE','PRE_NFCE'],true)) $safeFiscal=false;
      if (!empty($d['access_key'])) $safeFiscal=false;
    }
  } else { $safeFiscal=false; $warnings[]='Estrutura de documentos fiscais indisponível.'; }
} catch(Throwable $e) { $safeFiscal=false; $warnings[]='Não foi possível consultar os documentos fiscais.'; }
$counts=[];
foreach (['gestao_order_payments'=>['order_id'], 'finance_receivables'=>['origin_id','origin_type'], 'finance_movements'=>['origin_id','origin_type'], 'finance_boleto_charges'=>[], 'order_cancellation_effects'=>['order_id']] as $table=>$fields) {
  try {
    $check=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=database() AND table_name=? LIMIT 1');
    $check->execute([$table]); if (!$check->fetchColumn()) { $counts[$table]=null; continue; }
    if ($table==='finance_boleto_charges') { $counts[$table]=null; continue; }
    if (in_array('origin_type',$fields,true)) {
      $q=$pdo->prepare("SELECT count(*) FROM {$table} WHERE company_id=? AND origin_type='PEDIDO' AND origin_id=?");
    } else {
      $q=$pdo->prepare("SELECT count(*) FROM {$table} WHERE company_id=? AND order_id=?");
    }
    $q->execute([$companyId,$id]); $counts[$table]=(int)$q->fetchColumn();
  } catch(Throwable $e) { $counts[$table]=null; }
}
// Etapa 4: cruzamento fiscal pela origem e pelos itens. Não confundir
// ausência de documento na consulta por source_id com ausência de vínculo.
$crossFiscal = ['count'=>null, 'documents'=>[], 'error'=>null];
try {
  foreach (['fiscal_documents','fiscal_document_items','pedido_itens'] as $requiredTable) {
    $verify=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $verify->execute([$requiredTable]);
    if (!(int)$verify->fetchColumn()) throw new RuntimeException('Estrutura fiscal incompleta: '.$requiredTable);
  }
  $q=$pdo->prepare("SELECT DISTINCT fd.id,fd.model,fd.series,fd.number,fd.status,fd.access_key,fd.source_type
    FROM fiscal_documents fd
    WHERE fd.company_id=? AND (
      (UPPER(TRIM(COALESCE(fd.source_type,''))) IN ('PEDIDO','ORDER','VENDA') AND fd.source_id=?)
      OR EXISTS (
        SELECT 1 FROM fiscal_document_items di
        JOIN pedido_itens pi ON pi.company_id=di.company_id AND pi.id=di.order_item_id
        WHERE di.company_id=fd.company_id AND di.fiscal_document_id=fd.id
          AND pi.company_id=? AND pi.pedido_id=?
      )
    ) ORDER BY fd.id DESC");
  $q->execute([$companyId,$id,$companyId,$id]);
  $crossFiscal['documents']=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $crossFiscal['count']=count($crossFiscal['documents']);
} catch(Throwable $e) {
  $crossFiscal['error']='Não foi possível verificar todos os vínculos fiscais do pedido.';
}
// Etapa 6: rastreabilidade de cada pagamento e de seus vínculos financeiros.
// Somente leitura; ausência de vínculo explícito não significa ausência de liquidação externa.
$paymentTrace=['rows'=>[], 'error'=>null, 'has_external_links'=>false, 'receivables'=>[]];
try {
  foreach (['gestao_order_payments','finance_receivables'] as $traceTable) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute([$traceTable]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('Estrutura não disponível para conferência financeira.');
  }
  // As colunas de vinculação estão na estrutura base do Checkout; nenhuma alteração de schema.
  $st=$pdo->prepare('SELECT id,payment_method_id,payment_label,amount,received_amount,change_amount,finance_movement_id,receivable_id,receivable_ids,cash_session_id FROM gestao_order_payments WHERE company_id=? AND order_id=? ORDER BY id');
  $st->execute([$companyId,$id]);
  $paymentTrace['rows']=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $ids=[];
  foreach($paymentTrace['rows'] as $line){
    if ((int)($line['finance_movement_id']??0)>0 || (int)($line['cash_session_id']??0)>0) $paymentTrace['has_external_links']=true;
    if((int)($line['receivable_id']??0)>0) $ids[]=(int)$line['receivable_id'];
    foreach(explode(',',(string)($line['receivable_ids']??'')) as $part){
      $part=trim($part); if($part!=='' && ctype_digit($part) && (int)$part>0) $ids[]=(int)$part;
    }
  }
  $ids=array_values(array_unique($ids));
  if($ids){
    $params=array_merge([$companyId],$ids);
    $query='SELECT id,installment,due_date,amount,paid_amount,open_amount,status FROM finance_receivables WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id';
    $st=$pdo->prepare($query);$st->execute($params);
    $paymentTrace['receivables']=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if(count($paymentTrace['receivables'])!==count($ids)) throw new RuntimeException('Há parcelas referenciadas que não foram localizadas nesta empresa.');
    foreach($paymentTrace['receivables'] as $rec){
      if((float)($rec['paid_amount']??0)>0) $paymentTrace['has_external_links']=true;
    }
  }
} catch(Throwable $e) {
  $paymentTrace['error']='Não foi possível reconciliar os pagamentos e suas parcelas. Correção financeira bloqueada.';
}

// Etapa 5: guarda central para futuras operações de reabertura.
// Etapa 7: avaliação isolada, sem execução de estornos.
$reversalAssessment=nexa_order_payment_reversal_assessment(
  $paymentTrace['rows'], $paymentTrace['receivables'], $paymentTrace['error']
);
// A auditoria permanece read-only. A guarda nunca autoriza reversão financeira/estoque.
$reopenGuard = nexa_order_reopen_guard($pdo, $companyId, $id);
// Etapa 2: pré-validação somente leitura. Falhar fechado é obrigatório.
$preflight=[];
$pfAdd=static function(string $level,string $title,string $detail) use (&$preflight):void {
  $preflight[]=['level'=>$level,'title'=>$title,'detail'=>$detail];
};
$statusNow=strtolower(trim((string)($order['status']??'')));
if (!in_array($statusNow,['finalizado','concluido'],true)) {
  $pfAdd('block','Situação comercial','A reabertura em estudo parte exclusivamente de pedidos finalizados.');
} else {
  $pfAdd('ok','Situação comercial','Pedido finalizado identificado. Isso não autoriza reabrir ou refinalizar.');
}
if ($crossFiscal['error'] !== null) {
  $pfAdd('block','Verificação fiscal cruzada incompleta',$crossFiscal['error']);
} elseif ($crossFiscal['count'] > 0) {
  $pfAdd('block','Vínculo fiscal cruzado','Encontrado(s) '.$crossFiscal['count'].' documento(s) por origem ou item. A reabertura exige reconciliação fiscal de cada documento, mesmo que rejeitado ou preparado.');
} else {
  $pfAdd('review','Vínculo fiscal cruzado','Nenhum documento nas duas relações consultadas. Verificar ainda filas fiscais e documentos externos antes de reabrir.');
}
if (!$safeFiscal) {
  $pfAdd('block','Fiscal não liberado','Há vínculo fiscal com transmissão/autorização possível ou a situação fiscal não pôde ser verificada. Exige reconciliação fiscal.');
} elseif ($docs) {
  $pfAdd('review','Documento fiscal existente','Há documento local. Confira transmissão e resposta do autorizador antes de qualquer descarte ou correção.');
} else {
  $pfAdd('review','Nenhum documento na consulta principal','O resultado não substitui conferência de todos os vínculos fiscais (inclusive itens, fila e documentos de outras origens).');
}
foreach ($counts as $table=>$count) {
  if ($count===null) {
    $pfAdd('block','Auditoria financeira incompleta','Não foi possível verificar '.$table.'. Nenhuma reabertura pode ser aprovada automaticamente.');
  } elseif ($count>0 && $table==='order_cancellation_effects') {
    $pfAdd('block','Estorno anterior','Existe controle de cancelamento/estorno; é necessária reconciliação antes de qualquer nova movimentação.');
  } elseif ($count>0) {
    $pfAdd('review','Vínculos operacionais','Foram encontrados '.$count.' registro(s) em '.$table.'. A refinalização exigirá reconciliação sem duplicidade.');
  }
}
// Etapa 3: rastreio efetivo de movimentos de estoque e caixa por pedido.
// Consultas fail-closed: erro de schema/permissão nunca implica ausência de movimento.
$movementAudit=[];
$movementChecks=[
  ['table'=>'estoque_movimentos','title'=>'Saída de estoque PDV','where'=>"origem_tipo='PDV' AND origem_id=? AND LOWER(COALESCE(tipo,''))='saida'"],
  ['table'=>'estoque_movimentos','title'=>'Devoluções por cancelamento','where'=>"origem_tipo='CANCELAMENTO_PEDIDO' AND origem_id=?"],
  ['table'=>'gestao_cash_operations','title'=>'Lançamentos no caixa da venda','where'=>"order_id=? AND UPPER(COALESCE(type,''))='VENDA'"],
  ['table'=>'gestao_cash_operations','title'=>'Estornos no caixa','where'=>"order_id=? AND UPPER(COALESCE(type,''))='ESTORNO'"],
];
foreach($movementChecks as $checkMove){
  $result=['title'=>$checkMove['title'],'count'=>null,'amount'=>null];
  try {
    $tbl=$checkMove['table']; // tabela selecionada apenas da lista fixa interna
    $q=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $q->execute([$tbl]);
    if(!$q->fetchColumn()) throw new RuntimeException('Tabela não encontrada');
    $amountExpr=$tbl==='gestao_cash_operations'?'COALESCE(SUM(amount),0)':'COALESCE(SUM(ABS(qtd)),0)';
    $q=$pdo->prepare("SELECT COUNT(*) AS total, {$amountExpr} AS amount FROM {$tbl} WHERE company_id=? AND {$checkMove['where']}");
    $q->execute([$companyId,$id]);
    $r=$q->fetch(PDO::FETCH_ASSOC);
    $result['count']=(int)($r['total']??0);
    $result['amount']=(float)($r['amount']??0);
    if($result['count']>0) $pfAdd('review',$checkMove['title'],'Existem '.$result['count'].' movimentação(ões). Necessário reconciliar antes de qualquer refinalização.');
    else $pfAdd('review',$checkMove['title'],'Nenhum registro encontrado nesta origem; verificar integrações e outras origens antes de considerar ausência de efeitos.');
  } catch(Throwable $e) {
    $pfAdd('block',$checkMove['title'],'Não foi possível auditar esta movimentação: reabertura bloqueada.');
  }
  $movementAudit[]=$result;
}
// A ausência de linhas nos registros acima não prova ausência de efeitos de estoque,
// caixa, contratos ou integrações externas; estes serão tratados no motor transacional.
$pfAdd('review','Estoque, caixa e integrações','Antes de habilitar desfazer finalização, conferir movimentações e idempotência do ciclo completo.');
$pfBlocking=count(array_filter($preflight,static fn(array $r):bool=>$r['level']==='block'));
$h=static fn($v):string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
admin_topbar('Análise de regularização do pedido #'.$number, 'pedidos');
?>
<div class="card" style="margin:16px auto;max-width:1050px;padding:22px">
  <h2 style="margin-top:0">Análise fiscal e operacional — Pedido #<?= $h($number) ?></h2>
  <p>Empresa <?= $companyId ?> · Situação comercial: <strong><?= $h($order['status'] ?? '') ?></strong></p>
  <?php if ($discardError): ?><p role="alert" style="background:#fef2f2;color:#991b1b;padding:12px;border:1px solid #fecaca;border-radius:8px"><?= $h($discardError) ?></p><?php endif; ?>
  <?php if ($discardOk): ?><p role="status" style="background:#f0fdf4;color:#166534;padding:12px;border:1px solid #bbf7d0;border-radius:8px"><?= $h($discardOk) ?></p><?php endif; ?>
  <p style="background:#fff7ed;padding:12px;border-radius:8px;color:#92400e"><strong>Modo de segurança:</strong> a auditoria não reabre nem cancela pedidos e não altera estoque ou financeiro. O descarte local de NF-e rejeitada, quando habilitado, modifica apenas o estado de seu documento preservando XML e auditoria. A correção pontual de vendedor possui regras próprias. A reabertura com refinalização exigirá homologação do ciclo completo.</p>
  <?php if($canChangeFinalSeller && strtolower(trim((string)($order['status']??'')))==='finalizado'): ?>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:14px;margin:18px 0">
    <h3 style="margin-top:0">Correção autorizada · Vendedor</h3>
    <p>Exclusivo para pedido finalizado sem documento fiscal. A operação mantém o pedido finalizado e não refaz vendas, caixa, estoque, parcelas ou comissões.</p>
    <?php if($changeSellerError): ?><p style="color:#b91c1c"><?= $h($changeSellerError) ?></p><?php endif; ?>
    <?php if($changeSellerOk): ?><p style="color:#166534"><?= $h($changeSellerOk) ?></p><?php endif; ?>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Confirma a correção do vendedor neste pedido finalizado?');">
      <input type="hidden" name="action" value="change_final_seller"><input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_final_seller_csrf']) ?>">
      <label style="display:flex;flex-direction:column;gap:6px;margin:0">Vendedor<select name="seller_id" required style="min-width:250px;height:40px;padding:8px 10px;box-sizing:border-box"><option value="">Selecione</option>
      <?php foreach($allSellers as $seller): ?><option value="<?= (int)$seller['id'] ?>" <?= (int)($order['seller_id']??0)===(int)$seller['id']?'selected':'' ?>><?= $h($seller['name']) ?></option><?php endforeach; ?>
      </select></label><button type="submit" class="btn btn-primary" style="height:40px;padding:0 16px;align-self:flex-end;white-space:nowrap;box-sizing:border-box;margin:0">Atualizar vendedor</button>
    </form>
  </section>
  <?php endif; ?>
  <?php if($changeSellerError): ?><p class="alert alert-danger" style="color:#991b1b"><?= $h($changeSellerError) ?></p><?php endif; ?>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:16px;margin:18px 0" aria-label="Pré-validação para correção do pedido">
    <h3 style="margin:0 0 8px">Pré-validação para desfazer finalização</h3>
    <p style="margin:0 0 12px"><strong>Estado: <?= $pfBlocking ? 'BLOQUEADO PARA REABERTURA' : 'AGUARDANDO HOMOLOGAÇÃO DO CICLO COMPLETO' ?></strong>. Esta análise não altera o pedido e não libera refinalização.</p>
    <div style="display:grid;gap:8px">
      <?php foreach ($preflight as $checkRow):
        $tone=$checkRow['level']==='block'?'#fef2f2':($checkRow['level']==='ok'?'#f0fdf4':'#fffbeb');
        $border=$checkRow['level']==='block'?'#fecaca':($checkRow['level']==='ok'?'#bbf7d0':'#fde68a');
      ?>
      <div style="background:<?= $tone ?>;border:1px solid <?= $border ?>;border-radius:8px;padding:10px 12px">
        <strong><?= $h($checkRow['title']) ?></strong> — <?= $h($checkRow['detail']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <p style="margin:12px 0 0;font-size:.9em;color:#475569">A confirmação de reabertura, descarte de documento e inutilização continuará indisponível até implantação e homologação das operações transacionais e reconciliação com a SEFAZ.</p>
  </section>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:16px;margin:18px 0" aria-label="Simulação transacional de reabertura">
    <h3 style="margin:0 0 8px">Etapa 8 — Simular reabertura (sem gravar)</h3>
    <p>Consulta o banco em uma transação com bloqueio do pedido e identifica vínculos conhecidos. <strong>Não estorna, não reabre e não altera registros.</strong></p>
    <form method="post"><input type="hidden" name="action" value="simulate_reopen"><input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_reopen_check_csrf']) ?>"><button class="btn btn-primary" type="submit">Simular reabertura</button></form>
    <?php if ($transactionCheckError): ?><p style="color:#991b1b"><?= $h($transactionCheckError) ?></p><?php endif; ?>
    <?php if ($transactionCheck): ?>
      <p><strong>Reabertura: BLOQUEADA.</strong> Resultado da conferência nesta solicitação:</p>
      <?php foreach ($transactionCheck['reasons'] as $reason): ?><p style="margin:5px 0;color:#92400e">• <?= $h($reason) ?></p><?php endforeach; ?>
      <p style="font-size:.88em;color:#64748b">A consulta não autoriza reabertura. Requer homologação do motor de refinalização e conciliação dos meios de pagamento.</p>
    <?php endif; ?>
  </section>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:16px;margin:18px 0" aria-label="Guarda central de reabertura">
    <h3 style="margin:0 0 8px">Etapa 5 — Guarda transacional de reabertura</h3>
    <p style="margin:0 0 10px"><strong><?= $reopenGuard['eligible'] ? 'VÍNCULOS BÁSICOS SEM BLOQUEIO (AINDA NÃO LIBERADO)' : 'BLOQUEADO' ?></strong>. Nenhuma operação de reabertura é executada por esta tela.</p>
    <p>Esta guarda verifica novamente, em uma única rotina reutilizável, a empresa, o status, os documentos fiscais diretos/por item e controles anteriores de cancelamento. Ela será aplicada <strong>dentro da transação</strong> antes de qualquer futura mudança de estado.</p>
    <div style="display:grid;gap:7px">
      <?php foreach ($reopenGuard['reasons'] as $reason): ?>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:9px 12px;color:#991b1b"><?= $h($reason) ?></div>
      <?php endforeach; ?>
      <?php if (!$reopenGuard['reasons']): ?><div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 12px">Nenhuma barreira foi encontrada nesta guarda parcial; isso <strong>não</strong> equivale à aprovação para reabrir. Ainda faltam reversão idempotente e validação de boleto/cartão/Pix/estoque/caixa.</div><?php endif; ?>
    </div>
  </section>
  <h3>Conferência fiscal cruzada — Etapa 4</h3>
  <p>Esta conferência procura documentos pelo vínculo direto com o pedido <strong>e</strong> pelos itens fiscais associados aos itens do pedido. Não transmite, exclui ou cancela documento.</p>
  <?php if ($crossFiscal['error'] !== null): ?><p style="color:#991b1b"><strong>Bloqueado:</strong> <?= $h($crossFiscal['error']) ?></p><?php else: ?>
  <?php
  $crossArchived=count(array_filter($crossFiscal['documents'],static fn(array $d):bool=>strtoupper((string)($d['status']??''))==='DESCARTADA'));
  ?>
  <p><strong>Documentos localizados no histórico:</strong> <?= (int)$crossFiscal['count'] ?>
    (<?= (int)$crossArchived ?> rejeição(ões) descartada(s) localmente).
    <?= $crossFiscal['count'] > 0 ? 'A reabertura continua bloqueada: os vínculos históricos e a NFC-e autorizada são preservados.' : 'Ausência nos vínculos consultados não garante liberação fiscal.' ?></p>
  <?php endif; ?>
  <?php if($crossFiscal['documents']): ?>
  <div style="overflow-x:auto"><table class="table" style="width:100%"><thead><tr><th>ID</th><th>Modelo</th><th>Série / Nº</th><th>Situação</th><th>Origem</th></tr></thead><tbody>
  <?php foreach($crossFiscal['documents'] as $fd): ?><tr><td><?= (int)$fd['id'] ?></td><td><?= $h($fd['model']??'') ?></td><td><?= $h(($fd['series']??'').' / '.($fd['number']??'')) ?></td><td><?= $h($fd['status']??'') ?></td><td><?= $h($fd['source_type']??'') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <h3>Documentos fiscais vinculados</h3>
  <div style="overflow-x:auto"><table class="table" style="width:100%;border-collapse:collapse"><thead><tr><th>ID</th><th>Modelo</th><th>Série/Número</th><th>Situação</th><th>Ambiente</th><th>Chave</th></tr></thead><tbody>
  <?php foreach($docs as $d): ?><tr><td><?= (int)$d['id'] ?></td><td><?= $h($d['model']) ?></td><td><?= $h(($d['series']??'').' / '.($d['number']??'')) ?></td><td><?= $h($d['status']) ?></td><td><?= $h($d['environment']??'') ?></td><td style="overflow-wrap:anywhere;max-width:230px"><?= $h($d['access_key']??'') ?></td></tr><?php endforeach; ?>
  <?php if(!$docs): ?><tr><td colspan="6">Nenhum documento vinculado encontrado.</td></tr><?php endif; ?>
  </tbody></table></div>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:14px;margin:18px 0" aria-label="Regularização pontual de rejeição NF-e">
    <h3 style="margin:0 0 8px">Regularização pontual · NF-e rejeitada e NFC-e autorizada</h3>
    <p>Esta ação <strong>não reabre o pedido, não estorna a venda, não cancela notas na SEFAZ e não apaga XML ou histórico</strong>.
       Após comprovar a rejeição da NF-e na SEFAZ, registra o descarte <em>local</em> da tentativa rejeitada.
       A NFC-e autorizada continua válida. A numeração NF-e deve ser conferida separadamente, inclusive eventual inutilização.</p>
    <?php
      $discardCandidates=array_values(array_filter($docs,static fn(array $d):bool=>nexa_rejected_nfe_candidate($d)));
    ?>
    <?php if (!$discardCandidates): ?>
      <p>Nenhuma NF-e candidata a descarte seguro nesta consulta. Rejeições sem cStat conclusivo ou com retorno incerto exigem conciliação manual.</p>
    <?php elseif (!$discardEnabled): ?>
      <p style="color:#92400e"><strong>Homologação pendente:</strong> descarte bloqueado por padrão. É necessário habilitar o piloto exclusivamente para esta empresa nas configurações de implantação.</p>
    <?php elseif (!$discardAuthorized): ?>
      <p style="color:#991b1b">Seu acesso não possui permissão para regularização fiscal nesta empresa.</p>
    <?php else: ?>
      <?php foreach ($discardCandidates as $candidate): ?>
        <form method="post" style="display:grid;gap:10px;border-top:1px solid #e2e8f0;padding-top:14px;margin-top:12px"
          onsubmit="return confirm('Confirma o descarte LOCAL desta NF-e rejeitada, preservando o histórico? Esta ação não cancela documentos na SEFAZ.');">
          <input type="hidden" name="action" value="discard_rejected_nfe">
          <input type="hidden" name="doc_id" value="<?= (int)$candidate['id'] ?>">
          <input type="hidden" name="csrf" value="<?= $h($_SESSION['nexa_rejected_nfe_csrf']) ?>">
          <p style="margin:0"><strong>NF-e #<?= (int)$candidate['id'] ?></strong> · Série <?= $h($candidate['series']??'-') ?> / Nº <?= $h($candidate['number']??'-') ?> · Rejeição SEFAZ: <?= $h($candidate['sefaz_status_code']??'-') ?> — <?= $h($candidate['sefaz_status_message']??'') ?></p>
          <label>Justificativa (mínimo 15 caracteres)
            <textarea name="reason" required minlength="15" maxlength="500" rows="2" style="width:100%;box-sizing:border-box;margin-top:4px" placeholder="Ex.: Operador selecionou NF-e por engano; venda coberta pela NFC-e autorizada."></textarea>
          </label>
          <label><input type="checkbox" name="confirmed_sefaz" value="1" required>
            Confirmei na SEFAZ que a chave da NF-e selecionada não foi autorizada e que a NFC-e correta está autorizada.</label>
          <label>Digite <strong>DESCARTAR</strong> para confirmar
            <input name="confirmation" required autocomplete="off" maxlength="20" placeholder="DESCARTAR" style="margin-left:8px;padding:6px"></label>
          <div><button class="btn btn-primary" type="submit">Descartar somente NF-e rejeitada</button></div>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
  <h3>Movimentações de estoque e caixa</h3>
  <div style="overflow-x:auto"><table class="table" style="width:100%;border-collapse:collapse">
  <thead><tr><th>Origem</th><th>Registros</th><th>Quantidade / valor total</th></tr></thead><tbody>
  <?php foreach($movementAudit as $m): ?><tr>
   <td><?= $h($m['title']) ?></td><td><?= $m['count']===null?'Não verificado':(int)$m['count'] ?></td>
   <td><?= $m['amount']===null?'—':$h(number_format((float)$m['amount'],2,',','.')) ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <p style="font-size:.88em;color:#475569">Para estoque, a terceira coluna indica quantidade absoluta movimentada. Para caixa, indica soma dos valores em R$. Esta consulta é somente leitura e não comprova, isoladamente, elegibilidade para reabertura.</p>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:14px;margin:18px 0">
    <h3 style="margin:0 0 8px">Etapa 6 · Rastreabilidade dos pagamentos</h3>
    <p>Conferência somente leitura dos pagamentos registrados na finalização e dos títulos explicitamente vinculados. Não permite alterar pagamento nem desfazer finalização.</p>
    <?php if($paymentTrace['error']): ?><p style="color:#991b1b"><?= $h($paymentTrace['error']) ?></p><?php else: ?>
      <div style="overflow-x:auto"><table class="table" style="width:100%;border-collapse:collapse">
      <thead><tr><th>Pagamento</th><th>Forma</th><th>Valor</th><th>Recebido</th><th>Troco</th><th>Mov. financeiro</th><th>Parcela(s)</th><th>Caixa</th></tr></thead>
      <tbody><?php foreach($paymentTrace['rows'] as $line): ?><tr>
        <td><?= (int)$line['id'] ?></td><td><?= $h($line['payment_label']??'') ?></td>
        <td><?= $h(number_format((float)$line['amount'],2,',','.')) ?></td>
        <td><?= $h(number_format((float)$line['received_amount'],2,',','.')) ?></td>
        <td><?= $h(number_format((float)$line['change_amount'],2,',','.')) ?></td>
        <td><?= (int)($line['finance_movement_id']??0) ?: '—' ?></td>
        <td><?= $h(implode(', ',array_filter([(string)($line['receivable_id']??''),(string)($line['receivable_ids']??'')],static fn($x)=>trim($x)!==''))) ?: '—' ?></td>
        <td><?= (int)($line['cash_session_id']??0) ?: '—' ?></td>
      </tr><?php endforeach; ?><?php if(!$paymentTrace['rows']): ?><tr><td colspan="8">Nenhum pagamento vinculado foi localizado.</td></tr><?php endif; ?></tbody></table></div>
      <?php if($paymentTrace['receivables']): ?>
      <h4>Parcelas vinculadas</h4><div style="overflow-x:auto"><table class="table" style="width:100%"><thead><tr><th>Título</th><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Aberto</th><th>Status</th></tr></thead><tbody>
      <?php foreach($paymentTrace['receivables'] as $rec): ?><tr><td><?= (int)$rec['id'] ?></td><td><?= $h($rec['installment']) ?></td><td><?= $h($rec['due_date']) ?></td><td><?= $h(number_format((float)$rec['amount'],2,',','.')) ?></td><td><?= $h(number_format((float)$rec['paid_amount'],2,',','.')) ?></td><td><?= $h(number_format((float)$rec['open_amount'],2,',','.')) ?></td><td><?= $h($rec['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
      <p style="color:#92400e"><strong>Condição para futura correção:</strong> <?= $paymentTrace['has_external_links']?'Foram identificados vínculos com caixa, movimento financeiro ou parcela paga. Exige conciliação e tratamento específico.':'Nenhum dos vínculos rastreados exige reversão imediata aparente; outras integrações (Pix, cartão, boleto) ainda precisam de verificação.' ?></p>
    <?php endif; ?>
  </section>
  <section style="border:1px solid #cbd5e1;border-radius:12px;padding:14px;margin:18px 0" aria-label="Avaliação de reversibilidade financeira">
    <h3 style="margin:0 0 8px">Etapa 7 · Avaliação de reversibilidade financeira</h3>
    <p><strong>Resultado: <?= $h($reversalAssessment['category']) ?></strong>. Avaliação exclusivamente informativa; nenhum pagamento está autorizado para exclusão ou estorno automático.</p>
    <?php if($reversalAssessment['rows']): ?>
    <div style="overflow-x:auto"><table class="table" style="width:100%"><thead><tr><th>Pagamento</th><th>Forma</th><th>Conferências necessárias</th></tr></thead><tbody>
    <?php foreach($reversalAssessment['rows'] as $a): ?><tr><td><?= (int)$a['payment_id'] ?></td><td><?= $h($a['label']) ?></td><td><?= $h(implode(' · ',$a['issues'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php if(!$reversalAssessment['rows']): ?><p><?= $h(implode(' · ',$reversalAssessment['issues'])) ?></p><?php endif; ?>
    <p style="color:#92400e">A ausência de um vínculo neste relatório não comprova ausência de liquidação, integração bancária ou efeitos no caixa. Reabertura e reversão continuam bloqueadas até a implementação transacional homologada.</p>
  </section>
  <h3>Movimentações associadas</h3>
  <?php $names=['gestao_order_payments'=>'Pagamentos do pedido','finance_receivables'=>'Títulos a receber','finance_movements'=>'Movimentos financeiros','finance_boleto_charges'=>'Boletos (verificação manual obrigatória)','order_cancellation_effects'=>'Estornos previamente processados']; ?>
  <ul><?php foreach($counts as $key=>$count): ?><li><?= $h($names[$key] ?? $key) ?>: <strong><?= $count===null?'Não verificado':(int)$count ?></strong></li><?php endforeach; ?></ul>
  <p><strong>Triagem fiscal:</strong> <?= $safeFiscal ? 'Não há indicação local de autorização/transmissão nos documentos encontrados. Ainda é necessária a conferência na SEFAZ/provedor antes de descarte.' : 'Existe estado fiscal incerto, autorizado, vinculado a chave, ou não verificável. Não descarte o documento sem reconciliar com o autorizador.' ?></p>
  <?php foreach($warnings as $w): ?><p><?= $h($w) ?></p><?php endforeach; ?>
  <p>Quando houver lacuna na sequência fiscal, confira <strong>modelo, série, ambiente, número e situação</strong> antes de avaliar inutilização. A existência de documento posterior não autoriza inutilização automática.</p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
    <a class="btn btn-primary" href="pedido_editar.php?id=<?= $id ?>">Voltar ao pedido</a>
    <a class="btn" href="fiscal_documentos.php">Consultar documentos fiscais</a>
    <a class="btn" href="fiscal_inutilizacao.php">Conferir inutilização</a>
  </div>
</div>
<?php admin_footer(); ?>
