<?php
declare(strict_types=1);

/**
 * Regularização PONTUAL da NF-e rejeitada de pedido já coberto por NFC-e autorizada.
 * Nunca exclui XML, chave, itens fiscais, números, efeitos do PDV ou registros SEFAZ.
 * Não substitui consulta/conciliacao junto à SEFAZ antes da confirmação.
 */
/**
 * Configuracao por empresa em app_config. Um registro salvo pela tela vence
 * a lista legada do arquivo, inclusive quando esta desabilitado.
 * Nao cria tabela nem executa DDL no fluxo fiscal.
 */
if (!function_exists('nexa_rejected_nfe_setting')) {
  function nexa_rejected_nfe_setting(PDO $pdo, int $companyId): ?array {
    if ($companyId <= 0) return null;
    $q = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
    $q->execute([$companyId, 'fiscal_rejected_nfe_discard']);
    $json = $q->fetchColumn();
    if ($json === false) return null; // ainda vale a lista legada
    $data = json_decode((string)$json, true);
    if (!is_array($data) || !isset($data['enabled']) || !is_bool($data['enabled'])) {
      return ['enabled' => false, 'invalid' => true]; // falha segura
    }
    return $data;
  }
}

if (!function_exists('nexa_rejected_nfe_discard_enabled')) {
  function nexa_rejected_nfe_discard_enabled(int $companyId, ?PDO $pdo = null): bool {
    if ($companyId <= 0) return false;
    if ($pdo === null) {
      $possiblePdo = $GLOBALS['pdo'] ?? null;
      $pdo = $possiblePdo instanceof PDO ? $possiblePdo : null;
    }
    if ($pdo instanceof PDO) {
      try {
        $setting = nexa_rejected_nfe_setting($pdo, $companyId);
        if ($setting !== null) return ($setting['enabled'] ?? false) === true;
      } catch (Throwable $e) {
        error_log('[NEXA_NFE_FLAG_READ_FAIL] company='.$companyId.' '.$e->getMessage());
        return false; // banco inconsistente ou inacessivel: NAO libera
      }
    }
    // Compatibilidade apenas para instalacoes anteriores sem registro na tabela.
    $file = __DIR__ . '/order_rejected_nfe_flags.php';
    if (!is_file($file)) return false;
    $flags = require $file;
    return is_array($flags)
      && in_array($companyId, array_map('intval', $flags['pilot_company_ids'] ?? []), true);
  }
}

if (!function_exists('nexa_rejected_nfe_setting_save')) {
  function nexa_rejected_nfe_setting_save(PDO $pdo, int $companyId, bool $enabled, int $userId): void {
    if ($companyId <= 0 || $userId <= 0) throw new RuntimeException('Empresa ou operador invalido.');
    if ($pdo->inTransaction()) throw new RuntimeException('Salvamento deve iniciar fora de outra transacao.');
    $pdo->beginTransaction();
    try {
      // Verifica se a empresa existe. Evita cadastrar configuracao de empresa inexistente.
      $q = $pdo->prepare('SELECT id FROM companies WHERE id=? LIMIT 1 FOR UPDATE');
      $q->execute([$companyId]);
      if (!(int)$q->fetchColumn()) throw new RuntimeException('Empresa nao encontrada.');

      $q = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? FOR UPDATE');
      $q->execute([$companyId, 'fiscal_rejected_nfe_discard']);
      $prevJson = $q->fetchColumn();
      $prev = $prevJson !== false ? json_decode((string)$prevJson, true) : [];
      if (!is_array($prev)) $prev = [];
      $history = isset($prev['history']) && is_array($prev['history']) ? $prev['history'] : [];
      $history[] = [
        'at' => date('Y-m-d H:i:s'), 'by_user_id' => $userId,
        'previous_enabled' => ($prevJson === false ? null : (($prev['enabled'] ?? false) === true)),
        'enabled' => $enabled,
      ];
      $history = array_slice($history, -50);
      $data = [
        'enabled' => $enabled, 'updated_by' => $userId,
        'updated_at' => date('Y-m-d H:i:s'), 'history' => $history,
      ];
      $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      $q = $pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json)');
      $q->execute([$companyId, 'fiscal_rejected_nfe_discard', $json]);
      $pdo->commit();
      error_log('[NEXA_NFE_FLAG_SAVED] company='.$companyId.' enabled='.($enabled ? '1':'0').' user='.$userId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('nexa_rejected_nfe_candidate')) {
  function nexa_rejected_nfe_candidate(array $doc): bool {
    $code = trim((string)($doc['sefaz_status_code'] ?? ''));
    $status = strtoupper(trim((string)($doc['status'] ?? '')));
    // Duplicidade, autorizacao, processamento, denegacao e inutilizacao NUNCA sao rejeicoes descartaveis.
    $unsafeCodes = ['100','101','102','103','104','105','106','107','110','135','136','150','151','155','204','205','301','302','539'];
    return (string)($doc['model'] ?? '') === '55'
      && $status === 'REJEITADA'
      && preg_match('/^[0-9]{3}$/', $code) === 1
      && !in_array($code, $unsafeCodes, true)
      && trim((string)($doc['protocol_number'] ?? '')) === ''
      && trim((string)($doc['authorization_datetime'] ?? '')) === '';
  }
}

if (!function_exists('nexa_rejected_nfe_table_required')) {
  function nexa_rejected_nfe_table_required(PDO $pdo, string $table): void {
    // Lista fixa, sem nome vindo da requisicao.
    if (!in_array($table, ['pedidos','fiscal_documents','fiscal_document_events',
      'fiscal_document_items','pedido_itens','fiscal_emission_queue'], true)) {
      throw new RuntimeException('Estrutura desconhecida na regularização.');
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute([$table]);
    if (!(int)$st->fetchColumn()) {
      throw new RuntimeException('Conferência indisponível: '.$table.'. Nenhuma alteração realizada.');
    }
  }
}

if (!function_exists('nexa_rejected_nfe_discard')) {
  function nexa_rejected_nfe_discard(
    PDO $pdo, int $companyId, int $orderId, int $docId, int $userId, string $reason
  ): int {
    if (!nexa_rejected_nfe_discard_enabled($companyId, $pdo)) {
      throw new RuntimeException('Descarte ainda não habilitado para esta empresa.');
    }
    if ($companyId <= 0 || $orderId <= 0 || $docId <= 0 || $userId <= 0) {
      throw new RuntimeException('Empresa, pedido, documento ou operador inválido.');
    }
    $reason = trim($reason);
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($reasonLength < 15 || $reasonLength > 500) {
      throw new RuntimeException('Informe a justificativa com 15 a 500 caracteres.');
    }
    if ($pdo->inTransaction()) throw new RuntimeException('Operação exige transação exclusiva.');
    // Pré-conferências de schema fora da transação; sem DDL em nenhum momento.
    foreach (['pedidos','fiscal_documents','fiscal_document_events','fiscal_document_items','pedido_itens','fiscal_emission_queue'] as $table) {
      nexa_rejected_nfe_table_required($pdo, $table);
    }
    $pdo->beginTransaction();
    try {
      $q=$pdo->prepare('SELECT id,status FROM pedidos WHERE company_id=? AND id=? FOR UPDATE');
      $q->execute([$companyId,$orderId]);
      $order=$q->fetch(PDO::FETCH_ASSOC);
      if (!$order || strtolower(trim((string)$order['status']))!=='finalizado') {
        throw new RuntimeException('A correção exige pedido finalizado da empresa atual.');
      }

      $q=$pdo->prepare('SELECT * FROM fiscal_documents WHERE company_id=? AND id=? FOR UPDATE');
      $q->execute([$companyId,$docId]);
      $doc=$q->fetch(PDO::FETCH_ASSOC);
      if (!$doc
        || strtoupper(trim((string)($doc['source_type'] ?? '')))!=='PEDIDO'
        || (int)($doc['source_id'] ?? 0)!==$orderId
        || strtoupper(trim((string)($doc['direction'] ?? '')))!=='SAIDA'
      ) {
        throw new RuntimeException('A NF-e não pertence diretamente ao pedido desta empresa.');
      }
      if (!nexa_rejected_nfe_candidate($doc)) {
        throw new RuntimeException('NF-e não é rejeição comprovada ou possui retorno fiscal inseguro. Consulte a SEFAZ.');
      }
      // Nao ocorre sem uma NFC-e REALMENTE autorizada no MESMO pedido e empresa.
      $q=$pdo->prepare("SELECT id,access_key,protocol_number,authorization_datetime
        FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=?
        AND model='65' AND direction='SAIDA' AND status='AUTORIZADA'
        ORDER BY id DESC LIMIT 1 FOR UPDATE");
      $q->execute([$companyId,$orderId]);
      $nfce=$q->fetch(PDO::FETCH_ASSOC);
      if (!$nfce
        || strlen((string)preg_replace('/\D+/', '', (string)$nfce['access_key'])) !== 44
        || trim((string)($nfce['protocol_number'] ?? '')) === ''
      ) {
        throw new RuntimeException('NFC-e autorizada com chave e protocolo não encontrada para este pedido.');
      }
      // Outros documentos 55 autorizados/pendentes impedem correção pontual automática.
      $q=$pdo->prepare("SELECT COUNT(*) FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO'
        AND source_id=? AND model='55' AND id<>?
        AND UPPER(TRIM(COALESCE(status,''))) IN ('AUTORIZADA','CANCELADA','DENEGADA',
          'CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA')");
      $q->execute([$companyId,$orderId,$docId]);
      if ((int)$q->fetchColumn() > 0) {
        throw new RuntimeException('Existe outra NF-e do pedido com situação fiscal relevante. Regularização manual necessária.');
      }
      // Retorno anterior nao pode esconder autorizacao, protocolo, duplicidade ou cancelamento.
      $q=$pdo->prepare("SELECT COUNT(*) FROM fiscal_document_events
        WHERE company_id=? AND fiscal_document_id=? AND (
        NULLIF(TRIM(COALESCE(protocol_number,'')),'') IS NOT NULL
        OR TRIM(COALESCE(status_code,'')) IN ('100','101','102','103','104','105','106','107','110',
                                             '135','136','150','151','155','204','205','301','302','539')
        OR UPPER(COALESCE(event_type,'')) LIKE '%CANCEL%'
        )");
      $q->execute([$companyId,$docId]);
      if ((int)$q->fetchColumn() > 0) {
        throw new RuntimeException('Eventos fiscais exigem conciliação: há sinal de protocolo, duplicidade ou outra situação protegida.');
      }
      // Fila em andamento ou desconhecida bloqueia a operacao (fail closed).
      $q=$pdo->prepare("SELECT COUNT(*) FROM fiscal_emission_queue WHERE company_id=?
        AND fiscal_document_id=? AND UPPER(TRIM(COALESCE(status,'')))
        NOT IN ('ERRO','FALHA','CANCELADA','CANCELADO','CONCLUIDA','CONCLUIDO','FINALIZADA','FINALIZADO')");
      $q->execute([$companyId,$docId]);
      if ((int)$q->fetchColumn() > 0) {
        throw new RuntimeException('Existe fila fiscal pendente, em processamento ou incerta. Descarte bloqueado.');
      }
      // Historico de referencia cruzada: bloquear se outro documento referencia esta chave.
      $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name='fiscal_document_references'");
      $st->execute();
      if ((int)$st->fetchColumn() > 0) {
        $key=trim((string)($doc['access_key'] ?? ''));
        if ($key !== '') {
          $q=$pdo->prepare('SELECT COUNT(*) FROM fiscal_document_references WHERE company_id=? AND reference_key=? AND fiscal_document_id<>?');
          $q->execute([$companyId,$key,$docId]);
          if ((int)$q->fetchColumn() > 0) throw new RuntimeException('Esta NF-e está referenciada em outro documento. Não descartar automaticamente.');
        }
      }
      $q=$pdo->prepare("UPDATE fiscal_documents SET status='DESCARTADA', updated_at=NOW()
        WHERE company_id=? AND id=? AND model='55' AND status='REJEITADA'
        AND source_type='PEDIDO' AND source_id=?");
      $q->execute([$companyId,$docId,$orderId]);
      if ($q->rowCount() !== 1) throw new RuntimeException('Documento alterado durante a operação. Nada foi descartado.');

      // Evento persistente: mantém rejeição original, retorno, XML e número.
      $q=$pdo->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM fiscal_document_events
        WHERE company_id=? AND fiscal_document_id=? AND event_type='DESCARTE_REJEICAO_LOCAL'");
      $q->execute([$companyId,$docId]);
      $seq=max(1,(int)$q->fetchColumn());
      $details='Descarte local, sem cancelar SEFAZ nem remover XML. Usuario='.$userId.
        '; pedido='.$orderId.'; NFCe autorizada id='.(int)$nfce['id'].
        '; status anterior=REJEITADA; cStat='.(string)$doc['sefaz_status_code'].
        '; justificativa='.$reason.
        '; operador confirmou consulta prévia da chave na SEFAZ.';
      $q=$pdo->prepare("INSERT INTO fiscal_document_events
        (company_id,fiscal_document_id,event_type,event_sequence,event_datetime,status_code,status_message,notes,created_at)
        VALUES (?,?,'DESCARTE_REJEICAO_LOCAL',?,NOW(),?,'Rejeição descartada localmente; histórico preservado',?,NOW())");
      $q->execute([$companyId,$docId,$seq,(string)$doc['sefaz_status_code'],$details]);
      if ($q->rowCount() !== 1) throw new RuntimeException('Não foi possível registrar auditoria fiscal.');
      $pdo->commit();
      error_log('[NEXA_NFE_REJEITADA_DESCARTADA] company='.$companyId.' order='.$orderId.' doc='.$docId.' nfce='.(int)$nfce['id'].' user='.$userId);
      return (int)$nfce['id'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
