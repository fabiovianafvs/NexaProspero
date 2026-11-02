<?php
/**
 * Processador reutilizável da fila fiscal EXCLUSIVA da Ordem de Serviço.
 *
 * Pode ser usado tanto pela tela web (processamento imediato, estilo PDV)
 * quanto pelo worker/cron como fallback. Não altera o fluxo fiscal do PDV comum.
 */
require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/os_fiscal_queue.php';
require_once __DIR__ . '/fiscal_operational.php';
require_once __DIR__ . '/nfse_provider_router.php';
require_once __DIR__ . '/os_nfse_consulta.php';

if (!function_exists('os_fiscal_processor_document_status')) {
    function os_fiscal_processor_document_status(PDO $pdo, $companyId, $docId)
    {
        $docId = (int)$docId;
        if ($docId <= 0) return '';
        $st = $pdo->prepare("SELECT UPPER(COALESCE(status,'')) FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array((int)$companyId,$docId));
        return strtoupper(trim((string)$st->fetchColumn()));
    }
}

if (!function_exists('os_fiscal_processor_document_row')) {
    function os_fiscal_processor_document_row(PDO $pdo, $companyId, $docId)
    {
        if ((int)$docId <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array((int)$companyId,(int)$docId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('os_fiscal_processor_prepare_context')) {
    function os_fiscal_processor_prepare_context(PDO $pdo, array $job)
    {
        $cid=(int)$job['company_id'];
        $osId=(int)$job['os_order_id'];
        $pedidoId=(int)($job['pedido_id'] ?? 0);
        $type=os_fiscal_type((string)$job['document_type']);
        if ($cid<=0 || $osId<=0 || $pedidoId<=0 || $type==='') {
            throw new RuntimeException('Job da O.S. sem empresa, O.S., pedido ou tipo fiscal válido.');
        }
        if (!function_exists('saas_company_has_module') || !saas_company_has_module($pdo,$cid,'ordem_servico')) {
            throw new RuntimeException('O módulo Ordem de Serviço não está habilitado para esta empresa.');
        }
        $fiscalOptions=os_fiscal_company_options($pdo,$cid);
        if (empty($fiscalOptions[$type])) {
            throw new RuntimeException(os_fiscal_label($type).' não está habilitada nas Configurações Fiscais da empresa.');
        }

        $tempId=os_fiscal_create_temp_order($pdo,$cid,$osId,$type);
        if ($tempId<=0) throw new RuntimeException('Não foi possível criar o contexto fiscal temporário da O.S.');

        try {
            $docId=(int)($job['fiscal_document_id'] ?? 0);
            if ($type==='NFSE' && $docId>0 && os_fiscal_processor_document_status($pdo,$cid,$docId)==='CANCELADA') $docId=0;
            if ($docId<=0) {
                $existing=os_fiscal_document_for_os($pdo,$cid,$osId,$type);
                if ($existing && !($type==='NFSE' && strtoupper((string)($existing['status']??''))==='CANCELADA')) $docId=(int)$existing['id'];
            }
            // NFS-e cancelada nunca pode servir como pré-nota de novo faturamento.
            if ($type==='NFSE' && $docId>0) {
                $bind=$pdo->prepare('SELECT pedido_id FROM fiscal_nfse_details WHERE company_id=? AND fiscal_document_id=? LIMIT 1');
                $bind->execute([$cid,$docId]);
                $docPedido=(int)$bind->fetchColumn();
                if ($docPedido>0 && $docPedido!==$pedidoId) $docId=0;
            }

            if ($docId>0) {
                os_fiscal_bind_document_to_temp($pdo,$cid,$tempId,$docId);
                // Uma nova execução manual reconstrói a pré-nota para refletir
                // correções recentes do cliente antes da autorização.
                if ($type==='NFE') {
                    $doc=fiscal_nfe_create_or_update_from_order($pdo,$cid,$tempId);
                    $docId=(int)($doc['id'] ?? $docId);
                } elseif ($type==='NFCE') {
                    $doc=fiscal_nfce_create_or_update_from_order($pdo,$cid,$tempId,'65');
                    $docId=(int)($doc['id'] ?? $docId);
                }
            } else {
                if ($type==='NFE') {
                    $doc=fiscal_nfe_create_or_update_from_order($pdo,$cid,$tempId);
                } elseif ($type==='NFCE') {
                    $doc=fiscal_nfce_create_or_update_from_order($pdo,$cid,$tempId,'65');
                } else {
                    $doc=nfse_provider_prepare_order($pdo,$cid,$tempId);
                }
                $docId=(int)($doc['id'] ?? 0);
                if ($docId<=0) throw new RuntimeException('O emissor não retornou o identificador do documento preparado.');
            }

            os_fiscal_sync_destination_from_person($pdo,$cid,$osId,$docId,$type);
            $pdo->prepare("UPDATE os_fiscal_queue SET fiscal_document_id=?,updated_at=NOW() WHERE id=?")
                ->execute(array($docId,(int)$job['id']));
            return array('doc_id'=>$docId,'temp_order_id'=>$tempId);
        } catch (Throwable $e) {
            try {
                $model=os_fiscal_model($type);
                $st=$pdo->prepare("SELECT id FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=? AND model=? ORDER BY id DESC LIMIT 1");
                $st->execute(array($cid,$tempId,$model));
                $maybe=(int)$st->fetchColumn();
                if($maybe>0){
                    os_fiscal_relink_document($pdo,$cid,$osId,$pedidoId,$maybe);
                    $pdo->prepare("UPDATE os_fiscal_queue SET fiscal_document_id=?,updated_at=NOW() WHERE id=?")
                        ->execute(array($maybe,(int)$job['id']));
                }
            } catch (Throwable $ignore) {}
            os_fiscal_cleanup_temp_order($pdo,$cid,$tempId);
            throw $e;
        }
    }
}

if (!function_exists('os_fiscal_processor_authorize')) {
    function os_fiscal_processor_authorize(PDO $pdo, array $job, $docId)
    {
        $cid=(int)$job['company_id'];
        $type=os_fiscal_type((string)$job['document_type']);
        $status=os_fiscal_processor_document_status($pdo,$cid,$docId);
        if($status==='AUTORIZADA') return array('ok'=>true,'already_authorized'=>true,'doc_id'=>(int)$docId);

        // Segurança fiscal: retorno incerto nunca é retransmitido automaticamente.
        if($type==='NFSE' && in_array($status,array('TRANSMITINDO','ERRO_COMUNICACAO'),true)){
            $recovered=nfse_provider_recover_by_dps($pdo,$cid,(int)$docId);
            if(is_array($recovered) && !empty($recovered['ok'])) return $recovered;
            throw new RuntimeException('RETORNO_INCERTO: '.(string)($recovered['message'] ?? 'Não foi possível confirmar a NFS-e pela DPS.'));
        }
        if(in_array($status,array('PENDENTE_RETORNO','TRANSMITINDO','ERRO_COMUNICACAO'),true)){
            throw new RuntimeException('RETORNO_INCERTO: o documento possui transmissão com resultado incerto. Faça a recuperação/consulta antes de qualquer nova transmissão.');
        }

        if ($type==='NFE' || $type==='NFCE') {
            $result=fiscal_nfce_authorize_document($pdo,$cid,(int)$docId);
        } elseif ($type==='NFSE') {
            $result=nfse_provider_authorize_document($pdo,$cid,(int)$docId);
        } else {
            throw new RuntimeException('Tipo fiscal inválido.');
        }
        if (is_array($result) && array_key_exists('ok',$result) && empty($result['ok'])) {
            $ambiguous=!empty($result['ambiguous']);
            $message=(string)($result['message'] ?? ('Falha ao autorizar '.os_fiscal_label($type).'.'));
            if($ambiguous)$message='RETORNO_INCERTO: '.$message;
            throw new RuntimeException($message);
        }
        return is_array($result)?$result:array('ok'=>true);
    }
}

if (!function_exists('os_fiscal_processor_result_payload')) {
    function os_fiscal_processor_result_payload(PDO $pdo, array $job, $ok, $message = '')
    {
        $docId=(int)($job['fiscal_document_id'] ?? 0);
        $doc=os_fiscal_processor_document_row($pdo,(int)$job['company_id'],$docId);
        $type=os_fiscal_type((string)$job['document_type']);
        $docStatus=$doc ? strtoupper((string)($doc['status'] ?? '')) : '';
        return array(
            'ok'=>(bool)$ok,
            'queue_id'=>(int)$job['id'],
            'os_id'=>(int)$job['os_order_id'],
            'pedido_id'=>(int)($job['pedido_id'] ?? 0),
            'pedido_number'=>os_pedido_display_number($pdo,(int)$job['company_id'],(int)($job['pedido_id'] ?? 0)),
            'type'=>$type,
            'type_label'=>os_fiscal_label($type),
            'queue_status'=>(string)($job['status'] ?? ''),
            'document_id'=>$docId,
            'document_status'=>$docStatus,
            'document_status_label'=>$docStatus!==''?os_fiscal_status_label($docStatus):'',
            'number'=>$doc ? (string)($doc['number'] ?? '') : '',
            'series'=>$doc ? (string)($doc['series'] ?? '') : '',
            'access_key'=>$doc ? (string)($doc['access_key'] ?? '') : '',
            'message'=>(string)$message,
            'print_url'=>($docId>0 && in_array($type,array('NFE','NFCE'),true) && $docStatus==='AUTORIZADA') ? ('fiscal_nfce_imprimir.php?doc_id='.$docId.'&source=os&return_to='.rawurlencode('os_monitor.php')) : ''
        );
    }
}

if (!function_exists('os_fiscal_process_queue_job')) {
    /**
     * Executa UMA emissão da O.S. imediatamente. Não agenda tentativas automáticas.
     * Em falha, grava ERRO e aguarda nova ação explícita do usuário.
     */
    function os_fiscal_process_queue_job(PDO $pdo, $companyId, $queueId)
    {
        os_fiscal_queue_ensure_schema($pdo);
        $companyId=(int)$companyId;
        $queueId=(int)$queueId;
        if($companyId<=0 || $queueId<=0) throw new RuntimeException('Documento fiscal inválido para processamento.');

        $pdo->beginTransaction();
        try {
            $st=$pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND id=? LIMIT 1 FOR UPDATE");
            $st->execute(array($companyId,$queueId));
            $job=$st->fetch(PDO::FETCH_ASSOC);
            if(!$job) throw new RuntimeException('Item da fila fiscal da O.S. não encontrado.');
            $status=strtoupper((string)$job['status']);
            if($status==='CONCLUIDO'){
                $pdo->commit();
                $job['status']='CONCLUIDO';
                return os_fiscal_processor_result_payload($pdo,$job,true,'Documento já processado.');
            }
            if($status==='PROCESSANDO'){
                $started=!empty($job['started_at'])?strtotime((string)$job['started_at']):0;
                if($started && $started >= time()-600){
                    $pdo->commit();
                    $r=os_fiscal_processor_result_payload($pdo,$job,false,'Documento já está sendo processado em outra requisição.');
                    $r['busy']=true;
                    return $r;
                }
                $status='PENDENTE';
            }
            if($status!=='PENDENTE'){
                $pdo->commit();
                return os_fiscal_processor_result_payload($pdo,$job,false,'Use “Tentar novamente” para reprocessar um documento com erro.');
            }
            $pdo->prepare("UPDATE os_fiscal_queue SET status='PROCESSANDO',attempts=attempts+1,started_at=NOW(),scheduled_at=NULL,error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute(array($companyId,$queueId));
            $pdo->commit();
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }

        $st=$pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array($companyId,$queueId));
        $job=$st->fetch(PDO::FETCH_ASSOC);
        if(!$job) throw new RuntimeException('Item da fila desapareceu durante o processamento.');

        $docId=(int)($job['fiscal_document_id'] ?? 0);
        $tempId=0;
        try {
            if (os_fiscal_type($job['document_type']) === 'NFSE') {
                $prior=os_nfse_consulta_context($pdo,$companyId,(int)$job['os_order_id']);
                if (!empty($prior['multiple_authorized']))
                    throw new RuntimeException('Mais de uma NFS-e autorizada vinculada ao pedido. Conciliação manual necessária.');
                if (!empty($prior['authorized'])) {
                    $confirmed=$prior['authorized'][0];
                    $linked=(int)($job['fiscal_document_id']??0);
                    if ($linked>0 && $linked!==(int)$confirmed['id'])
                        throw new RuntimeException('Existe NFS-e autorizada, porém a fila aponta para outra DPS. Não retransmita; confira os documentos.');
                    $docId=(int)$confirmed['id'];
                    $payload=json_encode(array('ja_autorizada'=>true,'document_id'=>$docId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $pdo->prepare("UPDATE os_fiscal_queue SET fiscal_document_id=?,status='CONCLUIDO',response_payload=?,error_message=NULL,scheduled_at=NULL,finished_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")
                        ->execute(array($docId,$payload,$companyId,$queueId));
                    $job['status']='CONCLUIDO';$job['fiscal_document_id']=$docId;
                    return os_fiscal_processor_result_payload($pdo,$job,true,'NFS-e já autorizada no pedido/O.S.; fila conciliada sem nova transmissão.');
                }
                foreach($prior['docs'] as $existingNfse) {
                    $priorStatus=strtoupper((string)($existingNfse['status']??''));
                    if (in_array($priorStatus,array('ASSINADA','TRANSMITINDO','ERRO_COMUNICACAO','PENDENTE_RETORNO'),true))
                        throw new RuntimeException('NFS-e possui DPS anterior '.$priorStatus.'. Use Consultar NFS-e. Retransmissão automática bloqueada.');
                    if (strtoupper((string)($existingNfse['source_type']??''))==='PEDIDO' && $priorStatus!=='CANCELADA')
                        throw new RuntimeException('O pedido já possui documento NFS-e. Consulte/regularize o documento antes de processar a fila fiscal da O.S.');
                }
            }
            $ctx=os_fiscal_processor_prepare_context($pdo,$job);
            $docId=(int)$ctx['doc_id'];
            $tempId=(int)$ctx['temp_order_id'];
            $job['fiscal_document_id']=$docId;

            $result=os_fiscal_processor_authorize($pdo,$job,$docId);
            os_fiscal_relink_document($pdo,(int)$job['company_id'],(int)$job['os_order_id'],(int)$job['pedido_id'],$docId);
            os_fiscal_cleanup_temp_order($pdo,(int)$job['company_id'],$tempId);
            $tempId=0;

            $payload=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $pdo->prepare("UPDATE os_fiscal_queue SET fiscal_document_id=?,status='CONCLUIDO',response_payload=?,error_message=NULL,scheduled_at=NULL,finished_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute(array($docId,$payload,$companyId,$queueId));
            $job['status']='CONCLUIDO';
            $job['fiscal_document_id']=$docId;
            return os_fiscal_processor_result_payload($pdo,$job,true,os_fiscal_label($job['document_type']).' autorizada/processada com sucesso.');
        } catch (Throwable $e) {
            if($docId>0){
                try{os_fiscal_relink_document($pdo,(int)$job['company_id'],(int)$job['os_order_id'],(int)$job['pedido_id'],$docId);}catch(Throwable $ignore){}
            }
            if($tempId>0){
                try{os_fiscal_cleanup_temp_order($pdo,(int)$job['company_id'],$tempId);}catch(Throwable $ignore){}
            }
            $msg=(string)$e->getMessage();
            if(function_exists('mb_substr'))$msg=mb_substr($msg,0,65000,'UTF-8');else$msg=substr($msg,0,65000);
            $pdo->prepare("UPDATE os_fiscal_queue SET fiscal_document_id=?,status='ERRO',error_message=?,scheduled_at=NULL,finished_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute(array($docId>0?$docId:null,$msg,$companyId,$queueId));
            $job['status']='ERRO';
            $job['fiscal_document_id']=$docId;
            return os_fiscal_processor_result_payload($pdo,$job,false,$msg);
        }
    }
}
