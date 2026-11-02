<?php
require_once __DIR__ . '/helpers.php';
/**
 * Nexa Próspero - Fila fiscal EXCLUSIVA da Ordem de Serviço.
 *
 * Regra de segurança:
 * - não altera gestao_pdv.php;
 * - não altera fiscal_operational.php;
 * - não altera nfse_emitter.php;
 * - não usa a fila fiscal antiga do PDV/ACBr;
 * - usa a tabela própria os_fiscal_queue;
 * - reutiliza os emissores fiscais existentes apenas no worker.
 */

require_once __DIR__ . '/os_module.php';
require_once __DIR__ . '/schema_runtime.php';


if (!function_exists('os_pedido_display_number')) {
    /**
     * Retorna o número visível do pedido, respeitando o sequencial por empresa.
     * O pedido_id continua sendo apenas a chave interna global.
     */
    function os_pedido_display_number(PDO $pdo, $companyId, $pedidoId)
    {
        $companyId=(int)$companyId;
        $pedidoId=(int)$pedidoId;
        if($companyId<=0 || $pedidoId<=0) return 0;
        require_once __DIR__ . '/company_sequences.php';
        try {
            $st=$pdo->prepare("SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1");
            $st->execute(array($companyId,$pedidoId));
            $row=$st->fetch(PDO::FETCH_ASSOC);
            if(!$row) return $pedidoId;
            return function_exists('company_sequence_display') ? company_sequence_display($row,'pedidos') : (int)($row['numero_pedido'] ?? $row['id'] ?? $pedidoId);
        } catch(Throwable $e) {
            return $pedidoId;
        }
    }
}

if (!function_exists('os_pedido_display_numbers')) {
    function os_pedido_display_numbers(PDO $pdo, $companyId, array $pedidoIds)
    {
        $companyId=(int)$companyId;
        $ids=array_values(array_unique(array_filter(array_map('intval',$pedidoIds))));
        $out=array();
        if($companyId<=0 || !$ids) return $out;
        require_once __DIR__ . '/company_sequences.php';
        try {
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $st=$pdo->prepare("SELECT * FROM pedidos WHERE company_id=? AND id IN ($ph)");
            $st->execute(array_merge(array($companyId),$ids));
            foreach($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row){
                $id=(int)($row['id'] ?? 0);
                if($id<=0) continue;
                $out[$id]=function_exists('company_sequence_display') ? company_sequence_display($row,'pedidos') : (int)($row['numero_pedido'] ?? $id);
            }
        } catch(Throwable $e) {}
        foreach($ids as $id) if(!isset($out[$id])) $out[$id]=$id;
        return $out;
    }
}

if (!function_exists('os_pedido_sequence_search_clause')) {
    /**
     * Retorna uma cláusula EXISTS opcional para permitir pesquisar pelo número
     * visível do pedido sem confundir com o ID interno global.
     */
    function os_pedido_sequence_search_clause(PDO $pdo, $pedidoAlias, $likeParamPlaceholder='?')
    {
        require_once __DIR__ . '/company_sequences.php';
        if(!function_exists('company_sequence_has_column') || !company_sequence_has_column($pdo,'pedidos')) return '';
        $alias=preg_replace('/[^a-zA-Z0-9_]/','',(string)$pedidoAlias);
        if($alias==='') return '';
        return "EXISTS (SELECT 1 FROM pedidos pvis WHERE pvis.company_id={$alias}.company_id AND pvis.id={$alias}.pedido_id AND CAST(pvis.numero_pedido AS CHAR) LIKE {$likeParamPlaceholder})";
    }
}

if (!function_exists('os_fiscal_csrf_token')) {
    function os_fiscal_csrf_token()
    {
        if (PHP_SAPI === 'cli') return '';
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        if (empty($_SESSION['csrf_os_fiscal'])) {
            $_SESSION['csrf_os_fiscal'] = bin2hex(random_bytes(24));
        }
        return (string)$_SESSION['csrf_os_fiscal'];
    }
}

if (!function_exists('os_fiscal_csrf_check')) {
    function os_fiscal_csrf_check($token)
    {
        $expected = os_fiscal_csrf_token();
        if ($expected === '' || !is_string($token) || $token === '' || !hash_equals($expected,$token)) {
            throw new RuntimeException('Sessão expirada ou requisição inválida. Atualize a tela e tente novamente.');
        }
        return true;
    }
}

if (!function_exists('os_fiscal_schema_version')) {
    function os_fiscal_schema_version()
    {
        return 2026081201;
    }
}

if (!function_exists('os_fiscal_queue_ensure_schema')) {
    function os_fiscal_queue_ensure_schema(PDO $pdo)
    {
        static $ready = false;
        if ($ready) return true;

        $version = os_fiscal_schema_version();
        if (nexa_schema_version_ready($pdo, 'ordem_servico_fiscal_queue', $version)) {
            $ready = true;
            return true;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS os_fiscal_queue (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          company_id INT NOT NULL,
          os_order_id BIGINT UNSIGNED NOT NULL,
          pedido_id BIGINT UNSIGNED NULL,
          document_type VARCHAR(10) NOT NULL,
          fiscal_document_id BIGINT UNSIGNED NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
          priority INT NOT NULL DEFAULT 5,
          attempts INT NOT NULL DEFAULT 0,
          max_attempts INT NOT NULL DEFAULT 1,
          requested_by INT NULL,
          request_payload LONGTEXT NULL,
          response_payload LONGTEXT NULL,
          error_message TEXT NULL,
          scheduled_at DATETIME NULL,
          started_at DATETIME NULL,
          finished_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL,
          PRIMARY KEY (id),
          KEY idx_os_fiscal_queue_pending (status, scheduled_at, priority, id),
          KEY idx_os_fiscal_queue_order (company_id, os_order_id, document_type, id),
          KEY idx_os_fiscal_queue_pedido (company_id, pedido_id, id),
          KEY idx_os_fiscal_queue_document (company_id, fiscal_document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        nexa_schema_version_mark(
            $pdo,
            'ordem_servico_fiscal_queue',
            $version,
            'O.S. v1.9 - fila fiscal exclusiva e isolada da Ordem de Serviço'
        );
        if (!nexa_schema_version_ready($pdo, 'ordem_servico_fiscal_queue', $version)) {
            throw new RuntimeException('A fila fiscal da O.S. foi criada, mas sua versão não pôde ser registrada.');
        }
        $ready = true;
        return true;
    }
}

if (!function_exists('os_fiscal_type')) {
    function os_fiscal_type($type)
    {
        $type = strtoupper(trim((string)$type));
        if ($type === '55' || $type === 'NFE' || $type === 'NF-E') return 'NFE';
        if ($type === '65' || $type === 'NFCE' || $type === 'NFC-E') return 'NFCE';
        if ($type === 'NFSE' || $type === 'NFS-E') return 'NFSE';
        return '';
    }
}

if (!function_exists('os_fiscal_label')) {
    function os_fiscal_label($type)
    {
        $type = os_fiscal_type($type);
        if ($type === 'NFE') return 'NF-e';
        if ($type === 'NFCE') return 'NFC-e';
        if ($type === 'NFSE') return 'NFS-e';
        return 'Documento fiscal';
    }
}

if (!function_exists('os_fiscal_model')) {
    function os_fiscal_model($type)
    {
        $type = os_fiscal_type($type);
        if ($type === 'NFE') return '55';
        if ($type === 'NFCE') return '65';
        if ($type === 'NFSE') return 'NFSE';
        return '';
    }
}

if (!function_exists('os_fiscal_status_label')) {
    function os_fiscal_status_label($status)
    {
        $status = strtoupper(trim((string)$status));
        $map = array(
            'PENDENTE' => 'Em fila',
            'PROCESSANDO' => 'Processando',
            'CONCLUIDO' => 'Concluído',
            'ERRO' => 'Erro',
            'CANCELADO' => 'Cancelado',
            'AUTORIZADA' => 'Autorizada',
            'REJEITADA' => 'Rejeitada',
            'ERRO_AUTORIZACAO' => 'Erro de autorização',
            'ERRO_COMUNICACAO' => 'Erro de comunicação',
            'ASSINADA' => 'Assinada',
            'PREPARADA' => 'Preparada',
            'TRANSMITINDO' => 'Transmitindo',
            'CANCELADA' => 'Cancelada',
            'PRE_NFE' => 'Pré-NF-e',
            'PRE_NFCE' => 'Pré-NFC-e'
        );
        return isset($map[$status]) ? $map[$status] : ($status !== '' ? str_replace('_', ' ', $status) : 'Pendente');
    }
}

if (!function_exists('os_fiscal_company_options')) {
    function os_fiscal_company_options(PDO $pdo, $companyId)
    {
        $out = array('enabled'=>false, 'NFE'=>false, 'NFCE'=>false, 'NFSE'=>false, 'problems'=>array());
        try {
            require_once __DIR__ . '/saas_modules.php';
            if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, (int)$companyId, 'fiscal')) {
                $out['problems'][] = 'O módulo Fiscal não está habilitado para esta empresa.';
                return $out;
            }
            require_once __DIR__ . '/fiscal_operational.php';
            $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, (int)$companyId) : array();
            $out['enabled'] = !empty($cfg['fiscal_emission_enabled']);
            $out['NFE'] = $out['enabled'] && !empty($cfg['nfe_enabled']);
            $out['NFCE'] = $out['enabled'] && !empty($cfg['nfce_enabled']);
            $out['NFSE'] = $out['enabled'] && !empty($cfg['nfse_enabled']) && !empty($cfg['nfse_national_standard']);
            if (!$out['enabled']) $out['problems'][] = 'A emissão fiscal está desabilitada nas Configurações Fiscais.';
        } catch (Throwable $e) {
            $out['problems'][] = $e->getMessage();
        }
        return $out;
    }
}

if (!function_exists('os_fiscal_document_for_os')) {
    function os_fiscal_document_for_os(PDO $pdo, $companyId, $osOrderId, $type)
    {
        $model = os_fiscal_model($type);
        if ($model === '') return null;
        try {
            $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND source_type='ORDEM_SERVICO' AND source_id=? AND model=? ORDER BY id DESC LIMIT 1");
            $st->execute(array((int)$companyId,(int)$osOrderId,$model));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('os_fiscal_latest_queue')) {
    function os_fiscal_latest_queue(PDO $pdo, $companyId, $osOrderId, $type)
    {
        os_fiscal_queue_ensure_schema($pdo);
        $type = os_fiscal_type($type);
        if ($type === '') return null;
        $st = $pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND os_order_id=? AND document_type=? ORDER BY id DESC LIMIT 1");
        $st->execute(array((int)$companyId,(int)$osOrderId,$type));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('os_fiscal_queue_enqueue')) {
    function os_fiscal_queue_enqueue(PDO $pdo, $companyId, $osOrderId, $type, $requestedBy = 0)
    {
        os_fiscal_queue_ensure_schema($pdo);
        $companyId = (int)$companyId;
        $osOrderId = (int)$osOrderId;
        $type = os_fiscal_type($type);
        if ($companyId <= 0 || $osOrderId <= 0 || $type === '') throw new RuntimeException('Dados inválidos para a fila fiscal da O.S.');
        if (!os_module_enabled($pdo,$companyId)) {
            throw new RuntimeException('O módulo Ordem de Serviço não está habilitado para esta empresa.');
        }

        $st = $pdo->prepare("SELECT * FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array($companyId,$osOrderId));
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new RuntimeException('O.S. não encontrada.');
        if (strtoupper((string)$order['billing_status']) !== 'FATURADA' || (int)$order['pedido_id'] <= 0) {
            throw new RuntimeException('A O.S. precisa estar faturada antes de gerar documentos fiscais.');
        }
        if (($type === 'NFE' || $type === 'NFCE') && (int)$order['requires_product_fiscal'] !== 1) {
            throw new RuntimeException('Esta O.S. não possui produtos para gerar ' . os_fiscal_label($type) . '.');
        }
        if ($type === 'NFSE' && (int)$order['requires_service_fiscal'] !== 1) {
            throw new RuntimeException('Esta O.S. não possui serviços para gerar NFS-e.');
        }

        $options = os_fiscal_company_options($pdo, $companyId);
        if (empty($options[$type])) throw new RuntimeException(os_fiscal_label($type) . ' não está habilitada nas Configurações Fiscais da empresa.');

        if ($type === 'NFE' || $type === 'NFCE') {
            $other = $type === 'NFE' ? 'NFCE' : 'NFE';
            $otherDoc = os_fiscal_document_for_os($pdo,$companyId,$osOrderId,$other);
            if ($otherDoc && strtoupper((string)($otherDoc['status'] ?? '')) !== 'CANCELADA') {
                throw new RuntimeException('Esta O.S. já possui ' . os_fiscal_label($other) . '. Não é permitido gerar NF-e e NFC-e para a mesma parte de mercadorias.');
            }
            $otherQueue = os_fiscal_latest_queue($pdo,$companyId,$osOrderId,$other);
            $otherQueueStatus = $otherQueue ? strtoupper((string)$otherQueue['status']) : '';
            if ($otherQueue && in_array($otherQueueStatus, array('PENDENTE','PROCESSANDO'), true)) {
                throw new RuntimeException('Já existe ' . os_fiscal_label($other) . ' na fila para esta O.S.');
            }
            if ($otherQueue && $otherQueueStatus === 'CONCLUIDO' && $otherDoc && strtoupper((string)($otherDoc['status'] ?? '')) !== 'CANCELADA') {
                throw new RuntimeException('Já existe ' . os_fiscal_label($other) . ' concluída para esta O.S.');
            }
        }

        if ($type === 'NFSE') {
            require_once __DIR__ . '/os_nfse_consulta.php';
            $nfseContext=os_nfse_consulta_context($pdo,$companyId,$osOrderId);
            if (!empty($nfseContext['multiple_authorized']))
                throw new RuntimeException('Há múltiplas NFS-e autorizadas vinculadas ao Pedido/O.S. Consulte antes de gerar outra DPS.');
            if (!empty($nfseContext['authorized'])) {
                $authorized=$nfseContext['authorized'][0];
                return array('queued'=>false,'existing'=>true,'queue_id'=>0,'document_id'=>(int)$authorized['id'],
                             'message'=>'A NFS-e já está autorizada para o pedido desta O.S. Utilize Consultar NFS-e.');
            }
            foreach ($nfseContext['docs'] as $existingNfse) {
                $s=strtoupper((string)($existingNfse['status']??''));
                if (in_array($s,array('ASSINADA','TRANSMITINDO','ERRO_COMUNICACAO','PENDENTE_RETORNO'),true))
                    throw new RuntimeException('Esta O.S. já possui NFS-e com DPS gerada ou retorno incerto. Consulte no provedor antes de emitir outra.');
                if (strtoupper((string)($existingNfse['source_type']??''))==='PEDIDO' && $s!=='CANCELADA')
                    throw new RuntimeException('O pedido desta O.S. já possui documento NFS-e. Consulte/regularize o documento existente; não crie outra DPS na fila.');
            }
        }

        $doc = os_fiscal_document_for_os($pdo,$companyId,$osOrderId,$type);
        // Não reaproveita documento cancelado do faturamento anterior da mesma O.S.
        if ($type === 'NFSE' && $doc && strtoupper((string)($doc['status'] ?? '')) === 'CANCELADA') $doc = null;
        if ($doc && strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA') {
            return array('queued'=>false,'existing'=>true,'queue_id'=>0,'document_id'=>(int)$doc['id'],'message'=>os_fiscal_label($type).' já está autorizada.');
        }

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND os_order_id=? AND document_type=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $lock->execute(array($companyId,$osOrderId,$type));
            $last = $lock->fetch(PDO::FETCH_ASSOC);
            if ($last && in_array(strtoupper((string)$last['status']), array('PENDENTE','PROCESSANDO'), true)) {
                $pdo->commit();
                return array('queued'=>false,'existing'=>true,'queue_id'=>(int)$last['id'],'document_id'=>(int)($last['fiscal_document_id'] ?? 0),'message'=>os_fiscal_label($type).' já está na fila.');
            }

            $payload = json_encode(array(
                'os_order_id'=>$osOrderId,
                'pedido_id'=>(int)$order['pedido_id'],
                'document_type'=>$type,
                'requested_by'=>(int)$requestedBy,
                'queued_at'=>date('c')
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ins = $pdo->prepare("INSERT INTO os_fiscal_queue (company_id,os_order_id,pedido_id,document_type,fiscal_document_id,status,priority,attempts,max_attempts,requested_by,request_payload,scheduled_at,created_at) VALUES (?,?,?,?,?,'PENDENTE',5,0,1,?,?,DATE_ADD(NOW(),INTERVAL 2 MINUTE),NOW())");
            $ins->execute(array($companyId,$osOrderId,(int)$order['pedido_id'],$type,$doc ? (int)$doc['id'] : null,(int)$requestedBy ?: null,$payload));
            $queueId = (int)$pdo->lastInsertId();
            if ($type === 'NFSE') {
                $pdo->prepare("UPDATE os_orders SET service_document_type='NFSE',updated_at=NOW() WHERE company_id=? AND id=?")
                    ->execute(array($companyId,$osOrderId));
            } else {
                $pdo->prepare("UPDATE os_orders SET product_document_type=?,updated_at=NOW() WHERE company_id=? AND id=?")
                    ->execute(array($type,$companyId,$osOrderId));
            }
            $pdo->commit();
            return array('queued'=>true,'existing'=>false,'queue_id'=>$queueId,'document_id'=>$doc ? (int)$doc['id'] : 0,'message'=>os_fiscal_label($type).' preparada para autorização imediata.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('os_fiscal_queue_retry')) {
    function os_fiscal_queue_retry(PDO $pdo, $companyId, $queueId, $requestedBy = 0)
    {
        os_fiscal_queue_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array((int)$companyId,(int)$queueId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Item da fila não encontrado.');
        if (!in_array(strtoupper((string)$row['status']), array('ERRO','CANCELADO'), true)) {
            throw new RuntimeException('Somente itens com erro/cancelados podem ser reenviados manualmente.');
        }
        if (os_fiscal_type($row['document_type']) === 'NFSE') {
            require_once __DIR__ . '/os_nfse_consulta.php';
            $ctx=os_nfse_consulta_context($pdo,(int)$companyId,(int)$row['os_order_id']);
            if (count($ctx['docs']) !== 1 || strtoupper((string)($ctx['docs'][0]['status']??'')) !== 'REJEITADA')
                throw new RuntimeException('Reenvio da NFS-e bloqueado enquanto não houver uma única DPS com rejeição confirmada. Use Consultar NFS-e para verificar o provedor.');
        }
        $u = $pdo->prepare("UPDATE os_fiscal_queue SET status='PENDENTE',attempts=0,max_attempts=1,requested_by=?,error_message=NULL,response_payload=NULL,scheduled_at=DATE_ADD(NOW(),INTERVAL 2 MINUTE),started_at=NULL,finished_at=NULL,updated_at=NOW() WHERE company_id=? AND id=?");
        $u->execute(array((int)$requestedBy ?: null,(int)$companyId,(int)$queueId));
        return true;
    }
}

if (!function_exists('os_billing_token')) {
    function os_billing_token($companyId, $osOrderId)
    {
        return '[NEXA-OS-BILL:' . (int)$companyId . ':' . (int)$osOrderId . ']';
    }
}

if (!function_exists('os_billing_find_existing_pedido')) {
    function os_billing_find_existing_pedido(PDO $pdo, $companyId, $osOrderId)
    {
        require_once __DIR__ . '/gestao_module.php';
        $obsCol = function_exists('gestao_pedido_obs_column') ? gestao_pedido_obs_column($pdo) : '';
        if (!$obsCol) return 0;
        $token = os_billing_token($companyId,$osOrderId);
        $sql = 'SELECT id FROM pedidos WHERE company_id=? AND `' . str_replace('`','``',$obsCol) . '` LIKE ? ORDER BY id DESC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute(array((int)$companyId,nexa_search_like($token)));
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('os_billing_finalize_order')) {
    /**
     * Faturamento OPERACIONAL exclusivo da O.S.
     *
     * Importante: não chama gestao_finalize_pdv_order(), justamente para não acionar
     * comportamentos específicos do PDV (ex.: WhatsApp automático) nem alterar o fluxo
     * dos clientes que já estão em produção. Reutiliza apenas helpers estáveis de
     * pagamento/estoque/financeiro e grava o pedido com source=ordem_servico.
     */
    function os_billing_finalize_order(PDO $pdo, $companyId, $osOrderId, $userId = 0)
    {
        require_once __DIR__ . '/gestao_module.php';
        $companyId = (int)$companyId;
        $osOrderId = (int)$osOrderId;
        $userId = (int)$userId;
        if ($companyId <= 0 || $osOrderId <= 0) throw new RuntimeException('O.S. inválida para faturamento.');

        if (function_exists('gestao_ensure_schema')) {
            $schema = gestao_ensure_schema($pdo);
            if (is_array($schema) && !empty($schema['errors'])) {
                throw new RuntimeException('Não foi possível preparar a estrutura de gestão: '.implode(' ',(array)$schema['errors']));
            }
        }

        $st = $pdo->prepare("SELECT * FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array($companyId,$osOrderId));
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new RuntimeException('O.S. não encontrada.');
        $billingStatus = strtoupper(trim((string)($order['billing_status'] ?? 'PENDENTE')));
        if ($billingStatus === 'FATURADA' && (int)($order['pedido_id'] ?? 0) > 0) return (int)$order['pedido_id'];
        if ($billingStatus === 'CANCELADA') throw new RuntimeException('O.S. cancelada não pode ser faturada.');
        if ((int)($order['pedido_id'] ?? 0) > 0 || $billingStatus === 'ENVIADA_PDV') {
            throw new RuntimeException('Esta O.S. possui um pedido do fluxo anterior. Para segurança, regularize/cancele esse pedido antes de usar o novo faturamento isolado.');
        }
        if ((float)$order['total_general'] <= 0) throw new RuntimeException('A O.S. precisa possuir valor para ser faturada.');

        $it = $pdo->prepare("SELECT oi.*,p.nome AS product_name,p.categoria AS product_category,COALESCE(p.custo,0) AS product_cost,COALESCE(p.custo_medio,0) AS product_avg_cost FROM os_order_items oi LEFT JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id WHERE oi.company_id=? AND oi.order_id=? ORDER BY oi.id");
        $it->execute(array($companyId,$osOrderId));
        $osItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if (!$osItems) throw new RuntimeException('Inclua ao menos um produto ou serviço antes de faturar.');

        $pay = $pdo->prepare("SELECT * FROM os_order_payments WHERE company_id=? AND order_id=? ORDER BY id");
        $pay->execute(array($companyId,$osOrderId));
        $osPayments = $pay->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if (!$osPayments) throw new RuntimeException('Informe ao menos uma forma de pagamento na O.S. antes de faturar. A forma de pagamento é opcional durante o lançamento, mas necessária no faturamento.');
        $payTotal = 0.0;
        foreach ($osPayments as $row) $payTotal += (float)$row['amount'];
        if (abs(round($payTotal - (float)$order['total_general'],2)) > 0.01) {
            throw new RuntimeException('As formas de pagamento da O.S. precisam fechar o total. Total da O.S.: '.os_money($order['total_general']).' / Pagamentos: '.os_money($payTotal).'.');
        }

        $post = array(
            'payment_method_id'=>array(), 'payment_amount'=>array(), 'payment_received'=>array(),
            'payment_line_key'=>array(), 'installment_due'=>array(), 'installment_amount'=>array()
        );
        foreach ($osPayments as $idx=>$row) {
            $methodId=(int)$row['payment_method_id'];
            if ($methodId<=0) throw new RuntimeException('Existe uma forma de pagamento sem cadastro válido na O.S. Edite a condição de pagamento antes de faturar.');
            $key='os'.$idx; $amount=round((float)$row['amount'],2);
            $post['payment_method_id'][]=$methodId; $post['payment_amount'][]=$amount;
            $post['payment_received'][]=$amount; $post['payment_line_key'][]=$key;
            if (!empty($row['due_date'])) {
                $post['installment_due'][$key]=array((string)$row['due_date']);
                $post['installment_amount'][$key]=array($amount);
            }
        }
        $payments = gestao_payment_lines_from_post($pdo,$companyId,$post,(float)$order['total_general']);

        $customerId=(int)($order['person_id'] ?? 0);
        $customer=null;
        if ($customerId>0) {
            $c=$pdo->prepare("SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1");
            $c->execute(array($companyId,$customerId));
            $customer=$c->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        foreach ($payments as $line) {
            $payment=$line['payment'];
            if (!empty($payment['generate_receivable']) && !$customer) throw new RuntimeException('Pagamento a prazo exige cliente cadastrado.');
        }

        $session = gestao_open_cash_session($pdo,$companyId,$userId);
        foreach ($payments as $line) {
            $payment=$line['payment'];
            if (!empty($payment['require_open_cash']) && !$session) throw new RuntimeException('Abra o caixa antes de faturar a O.S. em '.(string)$payment['name'].'.');
            $chartIdLine=(int)($payment['default_chart_account_id'] ?? 0) ?: gestao_default_chart_id($pdo,$companyId,'CREDITO');
            if ($chartIdLine<=0) throw new RuntimeException('Configure o plano de contas da forma de pagamento: '.(string)$payment['name']);
        }

        $gross=0.0; $items=array(); $stockItems=array(); $responsibles=array();
        foreach ($osItems as $row) {
            $qty=round((float)$row['quantity'],4); $price=round((float)$row['unit_price'],6);
            if ((int)$row['produto_id']<=0 || $qty<=0 || $price<0) continue;
            $lineTotal=round($qty*$price,2); $gross+=$lineTotal;
            $item=array(
                'produto_id'=>(int)$row['produto_id'], 'nome'=>(string)($row['product_name'] ?: 'Item'),
                'categoria'=>(string)($row['product_category'] ?: 'SEM CATEGORIA'),
                'custo'=>(float)$row['product_cost'], 'custo_medio'=>(float)$row['product_avg_cost'],
                'qtd'=>$qty, 'price'=>$price, 'total'=>$lineTotal, 'obs'=>(string)$row['notes']
            );
            $items[]=$item;
            if (strtoupper((string)$row['item_type'])==='PRODUTO') $stockItems[]=$item;
            $resp=os_item_responsible_id($row); if($resp>0)$responsibles[$resp]=true;
        }
        if (!$items) throw new RuntimeException('Nenhum item válido foi encontrado para o faturamento.');
        $gross=round($gross,2);
        $totalOrder=max(0,round($gross-(float)$order['discount_amount']+(float)$order['addition_amount'],2));
        if (abs($totalOrder-(float)$order['total_general'])>0.02) throw new RuntimeException('O total calculado dos itens diverge do total da O.S. Salve a O.S. novamente antes de faturar.');

        $paymentLabel=implode(' + ',array_map(static fn($l)=>(string)$l['payment']['name'],$payments));
        $paymentLabelDb=gestao_payment_label_for_pedidos($pdo,$payments,$paymentLabel);
        $firstPayment=$payments[0]['payment'];
        $firstPaymentId=count($payments)===1?(int)$payments[0]['payment_id']:null;
        $firstBankId=(int)($firstPayment['default_bank_account_id'] ?? 0) ?: gestao_user_default_cash_account($pdo,$companyId,$userId,'pdv');
        if (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo,$companyId,$firstBankId,'Este usuário não possui permissão para usar o caixa/banco padrão do faturamento.');
        $firstChartId=(int)($firstPayment['default_chart_account_id'] ?? 0) ?: gestao_default_chart_id($pdo,$companyId,'CREDITO');
        $firstCostId=(int)($firstPayment['default_cost_center_id'] ?? 0);
        $sellerId=count($responsibles)===1?(int)array_key_first($responsibles):0;

        $personName=(string)($customer['name'] ?? $order['person_name_snapshot'] ?? 'Consumidor final');
        if(trim($personName)==='')$personName='Consumidor final';
        $personPhone=(string)($customer['whatsapp'] ?? $customer['phone'] ?? $order['person_phone_snapshot'] ?? '');
        $personDoc=(string)($customer['document'] ?? $order['person_document_snapshot'] ?? '');
        $addr=(string)($customer['address'] ?? ''); $district=(string)($customer['district'] ?? '');
        $zip=(string)($customer['zip_code'] ?? ''); $number=(string)($customer['number'] ?? '');
        $complement=(string)($customer['complement'] ?? ''); $city=(string)($customer['city'] ?? '');
        $state=strtoupper(substr((string)($customer['state'] ?? ''),0,2));
        $notes=os_billing_token($companyId,$osOrderId).' Faturamento da O.S. #'.str_pad((string)$order['os_number'],6,'0',STR_PAD_LEFT);

        $saleOperation=gestao_pdv_sale_operation($pdo,$companyId);
        $updateStock=$stockItems ? gestao_pdv_prepare_stock_out($pdo,$companyId,$saleOperation) : false;
        $obsCol=gestao_pedido_obs_column($pdo); $itemObsCol=gestao_pedido_item_obs_column($pdo);

        $pdo->beginTransaction();
        try {
            // Bloqueia a O.S. e evita duplo clique/duplo faturamento concorrente.
            $lock=$pdo->prepare("SELECT billing_status,pedido_id FROM os_orders WHERE company_id=? AND id=? FOR UPDATE");
            $lock->execute(array($companyId,$osOrderId)); $fresh=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$fresh) throw new RuntimeException('O.S. não encontrada durante o faturamento.');
            if(strtoupper((string)$fresh['billing_status'])==='FATURADA' && (int)$fresh['pedido_id']>0){$pdo->commit();return (int)$fresh['pedido_id'];}
            if((int)$fresh['pedido_id']>0 || strtoupper((string)$fresh['billing_status'])!=='PENDENTE') throw new RuntimeException('A situação da O.S. mudou. Atualize a tela antes de faturar.');

            $pedidoId=gestao_insert_pedido_dynamic($pdo,array(
                'company_id'=>$companyId, 'nome_cliente'=>$personName, 'customer_document'=>$personDoc,
                'whatsapp'=>preg_replace('/\D+/','',$personPhone), 'endereco'=>$addr, 'bairro'=>$district,
                'zip_code'=>$zip!==''?$zip:null, 'numero'=>$number!==''?$number:null,
                'complemento'=>$complement!==''?$complement:null, 'cidade'=>$city!==''?$city:null,
                'uf'=>$state!==''?$state:null, 'city'=>$city!==''?$city:null, 'state'=>$state!==''?$state:null,
                'tipo_entrega'=>'balcao', 'taxa_entrega'=>0, 'forma_pagamento'=>$paymentLabelDb,
                $obsCol=>$notes, 'total_produtos'=>$gross, 'total'=>$totalOrder, 'status'=>'finalizado',
                'finance_person_id'=>$customerId?:null, 'customer_id'=>$customerId?:null,
                'seller_id'=>$sellerId?:null, 'payment_method_id'=>$firstPaymentId,
                'bank_account_id'=>$firstBankId?:null, 'chart_account_id'=>$firstChartId,
                'cost_center_id'=>$firstCostId?:null, 'cash_session_id'=>$session?(int)$session['id']:null,
                'source'=>'ordem_servico', 'discount_amount'=>(float)$order['discount_amount'],
                'addition_amount'=>(float)$order['addition_amount']
            ));
            if($pedidoId<=0) throw new RuntimeException('Não foi possível gerar o pedido da O.S.');
            $pedidoRow=finance_query_one($pdo,'SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1',array($companyId,$pedidoId)) ?: array('id'=>$pedidoId);
            $orderNumber=company_sequence_display($pedidoRow,'pedidos');

            foreach($items as $item){
                if($itemObsCol){
                    $pdo->prepare('INSERT INTO pedido_itens (company_id,pedido_id,produto_id,qtd,preco_unit,total,`'.str_replace('`','``',$itemObsCol).'`) VALUES (?,?,?,?,?,?,?)')
                        ->execute(array($companyId,$pedidoId,$item['produto_id'],$item['qtd'],$item['price'],$item['total'],$item['obs']));
                }else{
                    $pdo->prepare('INSERT INTO pedido_itens (company_id,pedido_id,produto_id,qtd,preco_unit,total) VALUES (?,?,?,?,?,?)')
                        ->execute(array($companyId,$pedidoId,$item['produto_id'],$item['qtd'],$item['price'],$item['total']));
                }
            }
            if(function_exists('brain_profitability_recalculate_order')) brain_profitability_recalculate_order($pdo,$companyId,$pedidoId,true,'order.finalized');
            if($updateStock && $stockItems) gestao_pdv_apply_stock_out($pdo,$companyId,$pedidoId,(string)$orderNumber,$stockItems,$saleOperation);

            foreach($payments as $line){
                $payment=$line['payment']; $amount=(float)$line['amount'];
                $bankId=(int)($payment['default_bank_account_id'] ?? 0) ?: gestao_user_default_cash_account($pdo,$companyId,$userId,'pdv');
                if(function_exists('access_assert_cash_account')) access_assert_cash_account($pdo,$companyId,$bankId,'Este usuário não possui permissão para usar o caixa/banco da forma de pagamento.');
                $chartId=(int)($payment['default_chart_account_id'] ?? 0) ?: gestao_default_chart_id($pdo,$companyId,'CREDITO');
                $costId=(int)($payment['default_cost_center_id'] ?? 0); $movementId=null; $receivableId=null; $receivableIds=array();
                $desc='O.S. #'.str_pad((string)$order['os_number'],6,'0',STR_PAD_LEFT).' / Pedido #'.$orderNumber.' - '.(string)$payment['name'];
                if(!empty($payment['generate_receivable'])){
                    $schedule=$line['installments'] ?? array(); if(!$schedule)$schedule=gestao_default_installment_schedule($payment,$amount,date('Y-m-d'));
                    $totalInstallments=count($schedule);
                    foreach($schedule as $idxParc=>$parc){
                        $parcAmount=round((float)($parc['amount'] ?? 0),2); if($parcAmount<=0)continue;
                        $due=finance_date_or_null($parc['due_date'] ?? '') ?: date('Y-m-d');
                        $installmentLabel=str_pad((string)($idxParc+1),2,'0',STR_PAD_LEFT).'/'.str_pad((string)$totalInstallments,2,'0',STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO finance_receivables (company_id,person_id,chart_account_id,cost_center_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,paid_amount,open_amount,status,notes) VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,0,?,'ABERTO',?)")
                            ->execute(array($companyId,$customerId,$chartId,$costId?:null,$desc,(string)$orderNumber,$installmentLabel,$due,(string)$payment['name'],$parcAmount,$parcAmount,$notes));
                        $receivableIds[]=(int)$pdo->lastInsertId();
                    }
                    $receivableId=$receivableIds[0] ?? null;
                }else{
                    $pdo->prepare("INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes) VALUES (?,CURDATE(),'CREDITO',?,?,?,?,'PEDIDO',?,?,?,?,?)")
                        ->execute(array($companyId,$customerId?:null,$chartId,$costId?:null,$bankId?:null,$pedidoId,$desc,(string)$orderNumber,$amount,$notes));
                    $movementId=(int)$pdo->lastInsertId();
                    if($session && !empty($payment['affects_cash'])) gestao_create_cash_operation($pdo,$companyId,$userId,(int)$session['id'],'VENDA',$amount,$bankId?:null,null,$costId?:null,$desc,'',$pedidoId);
                }
                $pdo->prepare('INSERT INTO gestao_order_payments (company_id,order_id,cash_session_id,payment_method_id,payment_label,amount,received_amount,change_amount,bank_account_id,chart_account_id,cost_center_id,finance_movement_id,receivable_id,receivable_ids) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array($companyId,$pedidoId,$session?(int)$session['id']:null,(int)$line['payment_id'],(string)$payment['name'],$amount,(float)$line['received'],(float)$line['change'],$bankId?:null,$chartId,$costId?:null,$movementId,$receivableId,$receivableIds?implode(',',$receivableIds):null));
            }

            try{$pdo->prepare('INSERT IGNORE INTO pedidos_exportacao (company_id,pedido_id) VALUES (?,?)')->execute(array($companyId,$pedidoId));}catch(Throwable $ignore){}
            os_release_order_reservations($pdo,$companyId,$osOrderId);
            $pdo->prepare("UPDATE os_orders SET billing_status='FATURADA',pedido_id=?,billed_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")
                ->execute(array($pedidoId,$companyId,$osOrderId));
            $pdo->commit();
            return $pedidoId;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('os_fiscal_allocated_adjustments')) {
    function os_fiscal_allocated_adjustments(array $order, $type)
    {
        $p = max(0.0,(float)($order['total_products'] ?? 0));
        $s = max(0.0,(float)($order['total_services'] ?? 0));
        $gross = $p + $s;
        $disc = max(0.0,(float)($order['discount_amount'] ?? 0));
        $add = max(0.0,(float)($order['addition_amount'] ?? 0));
        $isProduct = in_array(os_fiscal_type($type),array('NFE','NFCE'),true);
        if ($gross <= 0) return array(0.0,0.0);
        if ($p <= 0) return $isProduct ? array(0.0,0.0) : array(round($disc,2),round($add,2));
        if ($s <= 0) return $isProduct ? array(round($disc,2),round($add,2)) : array(0.0,0.0);
        $pDisc = round($disc * ($p/$gross),2);
        $pAdd = round($add * ($p/$gross),2);
        if ($isProduct) return array($pDisc,$pAdd);
        return array(round($disc-$pDisc,2),round($add-$pAdd,2));
    }
}

if (!function_exists('os_fiscal_create_temp_order')) {
    /**
     * Cria um pedido técnico temporário contendo somente PRODUTOS ou somente SERVIÇOS.
     * Ele existe apenas durante a preparação do documento e é removido pelo worker.
     * Assim os motores fiscais atuais permanecem 100% inalterados.
     */
    function os_fiscal_create_temp_order(PDO $pdo, $companyId, $osOrderId, $type)
    {
        require_once __DIR__ . '/gestao_module.php';
        $companyId = (int)$companyId;
        $osOrderId = (int)$osOrderId;
        $type = os_fiscal_type($type);
        $itemType = in_array($type,array('NFE','NFCE'),true) ? 'PRODUTO' : 'SERVICO';

        $st = $pdo->prepare("SELECT * FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array($companyId,$osOrderId));
        $os = $st->fetch(PDO::FETCH_ASSOC);
        if (!$os) throw new RuntimeException('O.S. não encontrada para preparar documento fiscal.');

        $it = $pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? AND item_type=? ORDER BY id");
        $it->execute(array($companyId,$osOrderId,$itemType));
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) throw new RuntimeException('A O.S. não possui itens para ' . os_fiscal_label($type) . '.');

        $main = array();
        if ((int)$os['pedido_id'] > 0) {
            $p = $pdo->prepare("SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1");
            $p->execute(array($companyId,(int)$os['pedido_id']));
            $main = $p->fetch(PDO::FETCH_ASSOC) ?: array();
        }

        list($discount,$addition) = os_fiscal_allocated_adjustments($os,$type);
        $gross = 0.0;
        foreach ($items as $row) $gross += round((float)$row['total'],2);

        // A NFS-e atual aceita desconto, mas não possui campo separado de acréscimo.
        // Para manter o total correto sem alterar o emissor, o acréscimo da parte de
        // serviços é distribuído nos próprios itens temporários.
        $serviceAddition = ($type === 'NFSE') ? $addition : 0.0;
        $adjustedItems = array();
        $remainingAddition = round($serviceAddition,2);
        $baseGross = max(0.0,$gross);
        foreach ($items as $idx=>$row) {
            $line = round((float)$row['total'],2);
            $extra = 0.0;
            if ($serviceAddition > 0 && $baseGross > 0) {
                if ($idx === count($items)-1) $extra = $remainingAddition;
                else {
                    $extra = round($serviceAddition * ($line/$baseGross),2);
                    $remainingAddition = round($remainingAddition-$extra,2);
                }
            }
            $qty = max(0.0001,(float)$row['quantity']);
            $lineAdjusted = round($line+$extra,2);
            $row['_tmp_total'] = $lineAdjusted;
            $row['_tmp_unit'] = round($lineAdjusted/$qty,6);
            $adjustedItems[] = $row;
        }
        $fiscalGross = $gross + $serviceAddition;
        $orderAddition = $type === 'NFSE' ? 0.0 : $addition;
        $total = max(0.0,round($fiscalGross-$discount+$orderAddition,2));

        // A NFS-e deve refletir a conferência realizada no Pedido Detalhado.
        // Os documentos de mercadorias continuam usando a lógica anterior.
        $mainServiceNotes = array();
        if ($type==='NFSE' && (int)($os['pedido_id']??0)>0) {
            $noteColumn = function_exists('gestao_pedido_item_obs_column') ? gestao_pedido_item_obs_column($pdo) : '';
            if ($noteColumn !== '') {
                $q = $pdo->prepare('SELECT produto_id, `'.str_replace('`','``',$noteColumn).'` AS obs FROM pedido_itens WHERE company_id=? AND pedido_id=? ORDER BY id');
                $q->execute(array($companyId,(int)$os['pedido_id']));
                foreach($q->fetchAll(PDO::FETCH_ASSOC) ?: array() as $pi) $mainServiceNotes[(int)$pi['produto_id']][]=(string)$pi['obs'];
            }
        }
        $data = array(
            'company_id'=>$companyId,
            'nome_cliente'=>(string)($main['nome_cliente'] ?? $os['person_name_snapshot'] ?? 'Consumidor final'),
            'whatsapp'=>(string)($main['whatsapp'] ?? $os['person_phone_snapshot'] ?? ''),
            'endereco'=>(string)($main['endereco'] ?? ''),
            'bairro'=>(string)($main['bairro'] ?? ''),
            'zip_code'=>$main['zip_code'] ?? null,
            'numero'=>$main['numero'] ?? null,
            'complemento'=>$main['complemento'] ?? null,
            'referencia'=>$main['referencia'] ?? null,
            'cidade'=>$main['cidade'] ?? ($main['city'] ?? null),
            'uf'=>$main['uf'] ?? ($main['state'] ?? null),
            'city'=>$main['city'] ?? ($main['cidade'] ?? null),
            'state'=>$main['state'] ?? ($main['uf'] ?? null),
            'tipo_entrega'=>'balcao',
            'taxa_entrega'=>0,
            'forma_pagamento'=>(string)($main['forma_pagamento'] ?? ''),
            'total_produtos'=>round($fiscalGross,2),
            'total'=>$total,
            'status'=>'finalizado',
            'finance_person_id'=>(int)($os['person_id'] ?? 0) ?: null,
            'customer_id'=>(int)($os['person_id'] ?? 0) ?: null,
            'customer_document'=>(string)($os['person_document_snapshot'] ?? ''),
            'payment_method_id'=>$main['payment_method_id'] ?? null,
            'bank_account_id'=>$main['bank_account_id'] ?? null,
            'chart_account_id'=>$main['chart_account_id'] ?? null,
            'cost_center_id'=>$main['cost_center_id'] ?? null,
            'source'=>'ordem_servico_fiscal_tmp',
            'discount_amount'=>round($discount,2),
            'addition_amount'=>round($orderAddition,2)
        );
        if ($type==='NFSE') {
            $pedObsCol=function_exists('gestao_pedido_obs_column') ? gestao_pedido_obs_column($pdo) : '';
            if ($pedObsCol!=='' && array_key_exists($pedObsCol,$main)) $data[$pedObsCol]=$main[$pedObsCol];
        }
        $tempId = gestao_insert_pedido_dynamic($pdo,$data);
        if ($tempId <= 0) throw new RuntimeException('Não foi possível preparar o pedido fiscal temporário da O.S.');

        $obsCol = function_exists('gestao_pedido_item_obs_column') ? gestao_pedido_item_obs_column($pdo) : '';
        foreach ($adjustedItems as $row) {
            $itemObservation=(string)$row['notes'];
            if($type==='NFSE' && !empty($mainServiceNotes[(int)$row['produto_id']]))
                $itemObservation=array_shift($mainServiceNotes[(int)$row['produto_id']]);
            if ($obsCol) {
                $sql = 'INSERT INTO pedido_itens (company_id,pedido_id,produto_id,qtd,preco_unit,total,`'.str_replace('`','``',$obsCol).'`) VALUES (?,?,?,?,?,?,?)';
                $pdo->prepare($sql)->execute(array($companyId,$tempId,(int)$row['produto_id'],(float)$row['quantity'],(float)$row['_tmp_unit'],(float)$row['_tmp_total'],$itemObservation));
            } else {
                $pdo->prepare("INSERT INTO pedido_itens (company_id,pedido_id,produto_id,qtd,preco_unit,total) VALUES (?,?,?,?,?,?)")
                    ->execute(array($companyId,$tempId,(int)$row['produto_id'],(float)$row['quantity'],(float)$row['_tmp_unit'],(float)$row['_tmp_total']));
            }
        }

        // Replica pagamentos apenas para que o XML de NF-e/NFC-e use a mesma forma
        // do pedido principal. Nenhum movimento financeiro é criado para o pedido técnico.
        try {
            if ((int)$os['pedido_id'] > 0) {
                $gp = $pdo->prepare("SELECT * FROM gestao_order_payments WHERE company_id=? AND order_id=? ORDER BY id");
                $gp->execute(array($companyId,(int)$os['pedido_id']));
                $mainPays = $gp->fetchAll(PDO::FETCH_ASSOC);
                if ($mainPays) {
                    $remaining = $total;
                    foreach ($mainPays as $idx=>$p) {
                        $mainTotal = max(0.01,(float)$os['total_general']);
                        $amount = ($idx === count($mainPays)-1) ? $remaining : round($total * ((float)$p['amount']/$mainTotal),2);
                        $amount = max(0.0,min($remaining,$amount));
                        $remaining = round($remaining-$amount,2);
                        $pdo->prepare("INSERT INTO gestao_order_payments (company_id,order_id,payment_method_id,payment_label,amount,received_amount,change_amount,created_at) VALUES (?,?,?,?,?,?,0,NOW())")
                            ->execute(array($companyId,$tempId,(int)$p['payment_method_id'],(string)$p['payment_label'],$amount,$amount));
                    }
                }
            }
        } catch (Throwable $ignore) {}

        return $tempId;
    }
}


if (!function_exists('os_fiscal_sync_destination_from_person')) {
    /**
     * Enriquece SOMENTE o documento fiscal da O.S. com o cadastro atual da pessoa.
     * Não altera o motor fiscal global nem o fluxo do PDV comum.
     */
    function os_fiscal_sync_destination_from_person(PDO $pdo, $companyId, $osOrderId, $docId, $type)
    {
        $companyId=(int)$companyId; $osOrderId=(int)$osOrderId; $docId=(int)$docId; $type=os_fiscal_type($type);
        if($companyId<=0 || $osOrderId<=0 || $docId<=0) return;
        try {
            require_once __DIR__ . '/finance_module.php';
            if(function_exists('finance_ensure_schema')) finance_ensure_schema($pdo);
            $st=$pdo->prepare("SELECT person_id FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
            $st->execute(array($companyId,$osOrderId));
            $personId=(int)$st->fetchColumn();
            if($personId<=0) return;
            $st=$pdo->prepare("SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1");
            $st->execute(array($companyId,$personId));
            $p=$st->fetch(PDO::FETCH_ASSOC) ?: array();
            if(!$p) return;
            $role=$type==='NFSE'?'TOMADOR':'DESTINATARIO';
            $sets=array(); $params=array();
            $map=array(
                'person_id'=>$personId,
                'document'=>preg_replace('/[^0-9A-Za-z]+/','',(string)($p['document'] ?? '')),
                'state_registration'=>trim((string)($p['state_registration'] ?? '')),
                'municipal_registration'=>trim((string)($p['municipal_registration'] ?? '')),
                'name'=>trim((string)($p['name'] ?? '')),
                'email'=>trim((string)($p['email'] ?? '')),
                'phone'=>trim((string)(($p['whatsapp'] ?? '') ?: ($p['phone'] ?? ''))),
                'zip_code'=>preg_replace('/\D+/','',(string)($p['zip_code'] ?? '')),
                'address'=>trim((string)($p['address'] ?? '')),
                'number'=>trim((string)($p['number'] ?? '')),
                'complement'=>trim((string)($p['complement'] ?? '')),
                'district'=>trim((string)($p['district'] ?? '')),
                'city'=>trim((string)($p['city'] ?? '')),
                'city_ibge'=>preg_replace('/\D+/','',(string)($p['city_ibge'] ?? '')),
                'state'=>strtoupper(substr(trim((string)($p['state'] ?? '')),0,2)),
            );
            foreach($map as $col=>$value){
                $exists=function_exists('fiscal_column_exists')?fiscal_column_exists($pdo,'fiscal_document_participants',$col):true;
                if(!$exists) continue;
                $sets[]='`'.str_replace('`','``',$col).'`=?'; $params[]=$value!==''?$value:null;
            }
            if($sets){
                $params[]=$companyId; $params[]=$docId; $params[]=$role;
                $pdo->prepare('UPDATE fiscal_document_participants SET '.implode(',',$sets).' WHERE company_id=? AND fiscal_document_id=? AND role=?')->execute($params);
            }
            // NF-e/NFC-e já havia sido montada pelo motor atual; refaz somente o XML do
            // documento da O.S. após enriquecer IE/endereço/IBGE/e-mail do destinatário.
            if(in_array($type,array('NFE','NFCE'),true) && function_exists('fiscal_nfce_build_draft_xml')){
                $xml=fiscal_nfce_build_draft_xml($pdo,$companyId,$docId);
                $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?,xml_hash=?,updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute(array($xml,hash('sha256',$xml),$companyId,$docId));
            }
        } catch(Throwable $e){
            throw new RuntimeException('Não foi possível atualizar os dados fiscais do destinatário da O.S.: '.$e->getMessage(),0,$e);
        }
    }
}

if (!function_exists('os_fiscal_cleanup_temp_order')) {
    function os_fiscal_cleanup_temp_order(PDO $pdo, $companyId, $tempOrderId)
    {
        $companyId = (int)$companyId;
        $tempOrderId = (int)$tempOrderId;
        if ($tempOrderId <= 0) return;
        try { $pdo->prepare("DELETE FROM gestao_order_payments WHERE company_id=? AND order_id=?")->execute(array($companyId,$tempOrderId)); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM pedido_itens WHERE company_id=? AND pedido_id=?")->execute(array($companyId,$tempOrderId)); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM pedidos WHERE company_id=? AND id=? AND source='ordem_servico_fiscal_tmp'")->execute(array($companyId,$tempOrderId)); } catch (Throwable $e) {}
    }
}

if (!function_exists('os_fiscal_bind_document_to_temp')) {
    /**
     * Durante a autorização, os emissores atuais continuam enxergando um PEDIDO,
     * mas esse pedido é técnico e temporário. Depois do processamento o documento
     * é religado à O.S. e o pedido técnico é removido.
     */
    function os_fiscal_bind_document_to_temp(PDO $pdo, $companyId, $tempOrderId, $docId)
    {
        $pdo->prepare("UPDATE fiscal_documents SET source_type='PEDIDO',source_id=?,updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute(array((int)$tempOrderId,(int)$companyId,(int)$docId));
        try {
            $pdo->prepare("UPDATE fiscal_nfse_details SET pedido_id=? WHERE company_id=? AND fiscal_document_id=?")
                ->execute(array((int)$tempOrderId,(int)$companyId,(int)$docId));
        } catch (Throwable $e) {}
    }
}

if (!function_exists('os_fiscal_relink_document')) {
    function os_fiscal_relink_document(PDO $pdo, $companyId, $osOrderId, $pedidoId, $docId)
    {
        $st = $pdo->prepare("SELECT os_number FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array((int)$companyId,(int)$osOrderId));
        $osNumber = (string)$st->fetchColumn();
        $suffix = 'Documento originado da O.S. #' . str_pad($osNumber,6,'0',STR_PAD_LEFT) . '.';
        $u = $pdo->prepare("UPDATE fiscal_documents SET source_type='ORDEM_SERVICO',source_id=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE ' ' END,?),updated_at=NOW() WHERE company_id=? AND id=?");
        $u->execute(array((int)$osOrderId,$suffix,(int)$companyId,(int)$docId));
        try {
            $pdo->prepare("UPDATE fiscal_nfse_details SET pedido_id=? WHERE company_id=? AND fiscal_document_id=?")
                ->execute(array((int)$pedidoId ?: null,(int)$companyId,(int)$docId));
        } catch (Throwable $e) {}
    }
}
