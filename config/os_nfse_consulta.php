<?php
/**
 * Nexa Próspero - Consulta e conciliação conservadora de NFS-e da O.S.
 * Nunca prepara DPS, nunca autoriza e nunca retransmite.
 * Documentos criados pelo Pedido original também são reconhecidos, sem mudar a origem fiscal.
 */
if (!function_exists('os_nfse_consulta_context')) {
    function os_nfse_consulta_context(PDO $pdo, int $companyId, int $osId): array {
        if ($companyId <= 0 || $osId <= 0) throw new RuntimeException('Empresa ou O.S. inválida.');
        $s=$pdo->prepare('SELECT id,pedido_id,os_number,billing_status,requires_service_fiscal FROM os_orders WHERE company_id=? AND id=? LIMIT 1');
        $s->execute([$companyId,$osId]);
        $os=$s->fetch(PDO::FETCH_ASSOC);
        if (!$os || strtoupper((string)$os['billing_status'])!=='FATURADA' || (int)$os['requires_service_fiscal']!==1)
            throw new RuntimeException('O.S. faturada com serviços não encontrada nesta empresa.');
        $pedidoId=(int)$os['pedido_id'];
        $s=$pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND os_order_id=? AND document_type='NFSE' ORDER BY id DESC LIMIT 1");
        $s->execute([$companyId,$osId]);
        $queue=$s->fetch(PDO::FETCH_ASSOC) ?: null;
        $queueDocId=(int)($queue['fiscal_document_id']??0);
        // Cada caminho exige o vínculo exato da empresa: O.S., pedido faturado ou id presente na fila.
        $sql="SELECT d.id,d.source_type,d.source_id,d.model,d.status,d.number,d.series,d.access_key,
                     d.environment,nd.pedido_id AS nfse_pedido_id,nd.dps_number,nd.dps_series
                FROM fiscal_documents d
                LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id
               WHERE d.company_id=? AND d.model='NFSE' AND d.direction='SAIDA'
                 AND ((d.source_type='ORDEM_SERVICO' AND d.source_id=?)
                   OR (d.source_type='PEDIDO' AND d.source_id=?)
                   OR (nd.pedido_id=?)
                   OR (d.id=? AND ? > 0))
               ORDER BY d.id DESC LIMIT 30";
        $s=$pdo->prepare($sql);
        $s->execute([$companyId,$osId,$pedidoId,$pedidoId,$queueDocId,$queueDocId]);
        $docs=$s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $authorized=[];$selected=null;
        foreach($docs as $d) {
            if (strtoupper((string)$d['status'])==='AUTORIZADA') $authorized[]=$d;
            if ($selected===null && (int)$d['id']===$queueDocId) $selected=$d;
        }
        if ($authorized) $selected=$authorized[0];
        elseif (!$selected && $docs) $selected=$docs[0];
        return ['os'=>$os,'queue'=>$queue,'docs'=>$docs,'selected'=>$selected,
                'authorized'=>$authorized,'multiple_authorized'=>count($authorized)>1];
    }
}

if (!function_exists('os_nfse_consulta_reconcile')) {
    /** Ajusta somente o estado da fila, após confirmação local AUTORIZADA e sem documento conflitante. */
    function os_nfse_consulta_reconcile(PDO $pdo, int $companyId, array $context): bool {
        if (!empty($context['multiple_authorized']) || count($context['authorized']??[])!==1) return false;
        $doc=$context['authorized'][0];
        if (strtoupper((string)$doc['status'])!=='AUTORIZADA') return false;
        $queue=$context['queue'];
        if (!$queue) return false;
        if (strtoupper((string)($queue['status']??''))==='PROCESSANDO') return false;
        $linked=(int)($queue['fiscal_document_id']??0);
        $authorizedId=(int)$doc['id'];
        // Lotes podem faturar diversas O.S. no mesmo pedido. Uma nota do pedido
        // não comprova, por si só, a emissão de uma NFS-e por cada O.S. do lote.
        if (strtoupper((string)($doc['source_type']??''))==='PEDIDO') {
            $pedidoId=(int)($context['os']['pedido_id']??0);
            if ($pedidoId<=0) return false;
            $count=$pdo->prepare("SELECT COUNT(*) FROM os_orders WHERE company_id=? AND pedido_id=? AND billing_status='FATURADA' AND requires_service_fiscal=1");
            $count->execute([$companyId,$pedidoId]);
            if ((int)$count->fetchColumn()!==1) return false;
        }
        // A fila pode ter criado OUTRA DPS: nesse caso não se misturam documentos.
        if ($linked>0 && $linked!==$authorizedId) return false;
        $payload=json_encode(['consulta_nfse_os'=>true,'document_id'=>$authorizedId,'situacao'=>'AUTORIZADA'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $s=$pdo->prepare("UPDATE os_fiscal_queue SET fiscal_document_id=?,status='CONCLUIDO',error_message=NULL,
                            response_payload=?,scheduled_at=NULL,finished_at=COALESCE(finished_at,NOW()),updated_at=NOW()
                          WHERE company_id=? AND id=? AND status IN ('ERRO','PENDENTE','CONCLUIDO')
                            AND (fiscal_document_id IS NULL OR fiscal_document_id=?)");
        $s->execute([$authorizedId,$payload,$companyId,(int)$queue['id'],$authorizedId]);
        return $s->rowCount()>0 || strtoupper((string)($queue['status']??''))==='CONCLUIDO';
    }
}

if (!function_exists('os_nfse_consulta_execute')) {
    function os_nfse_consulta_execute(PDO $pdo, int $companyId, int $osId): array {
        $ctx=os_nfse_consulta_context($pdo,$companyId,$osId);
        if (!$ctx['selected']) return ['kind'=>'info','message'=>'Nenhuma NFS-e existente foi localizada para esta O.S. ou para seu pedido. Nenhuma emissão foi realizada.'];
        if ($ctx['multiple_authorized']) return ['kind'=>'warn','message'=>'Há mais de uma NFS-e autorizada vinculada a este pedido/O.S. A conciliação foi bloqueada para conferência fiscal.'];
        $doc=$ctx['selected'];
        $docId=(int)$doc['id'];
        $status=strtoupper((string)$doc['status']);
        require_once __DIR__.'/nfse_provider_router.php';
        if ($status==='AUTORIZADA') {
            $key=trim((string)($doc['access_key']??''));
            if ($key!=='') {
                $reply=nfse_provider_query_by_key($pdo,$companyId,$docId,$key);
                if (empty($reply['ok'])) return ['kind'=>'warn','message'=>'NFS-e autorizada no Nexa (documento #'.$docId.'), mas a consulta ao provedor não foi conclusiva: '.(string)($reply['message']??'Sem resposta confirmada').'. A autorização local foi preservada.'];
            } else {
                $reply=nfse_provider_recover_by_dps($pdo,$companyId,$docId);
                if (empty($reply['ok'])) return ['kind'=>'warn','message'=>'NFS-e com status AUTORIZADA no Nexa, porém sem chave para consulta direta. Recuperação por DPS inconclusiva: '.(string)($reply['message']??'Sem resposta confirmada').'. Nenhuma nova transmissão foi realizada.'];
            }
        } elseif (in_array($status,['ASSINADA','TRANSMITINDO','ERRO_COMUNICACAO','PENDENTE_RETORNO','REJEITADA','ERRO_AUTORIZACAO'],true)) {
            $reply=nfse_provider_recover_by_dps($pdo,$companyId,$docId);
            if (empty($reply['ok'])) return ['kind'=>'warn','message'=>(string)($reply['message']??'Consulta inconclusiva.'). ' Nenhuma nova DPS foi transmitida.'];
        } else {
            return ['kind'=>'info','message'=>'Documento #'.$docId.' está '.($status?:'sem situação definida').'. A consulta não transmite notas e não altera esta situação.'];
        }
        $updated=os_nfse_consulta_context($pdo,$companyId,$osId);
        $confirmed=$updated['selected'];
        if (!$confirmed || strtoupper((string)$confirmed['status'])!=='AUTORIZADA' || !empty($updated['multiple_authorized']))
            return ['kind'=>'warn','message'=>'O provedor respondeu, porém a autorização não foi confirmada sem divergências no Nexa. Nenhuma nova emissão foi realizada.'];
        $reconciled=os_nfse_consulta_reconcile($pdo,$companyId,$updated);
        $msg='NFS-e autorizada confirmada. Documento fiscal #'.(int)$confirmed['id'].'.';
        if ($reconciled) $msg.=' Fila da O.S. conciliada com sucesso.';
        elseif ($updated['queue']) $msg.=' A fila não foi alterada, pois há divergência de vínculo ou processamento em andamento; confira antes de reprocessar.';
        else $msg.=' Documento localizado no pedido, sem item de fila para conciliar.';
        return ['kind'=>$reconciled||!$updated['queue']?'ok':'warn','message'=>$msg];
    }
}
