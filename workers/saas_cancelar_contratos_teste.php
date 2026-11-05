<?php
declare(strict_types=1);

/**
 * Cancela os contratos TESTE que o cadastro de empresas criava na empresa
 * faturadora (Próspero Tecnologia) para assinaturas ainda em teste (trial).
 *
 * A partir desta versão o trial não gera mais contrato: ele é lançado na
 * ativação (Central SaaS / CRM) ou quando o próprio cliente contrata perto do
 * fim do teste. Este script limpa os contratos de teste antigos.
 *
 * Segurança:
 *  - Só mexe em contratos com status TESTE, ligados a assinatura TRIAL.
 *  - Pula qualquer contrato que já tenha competência gerada, Pedido, boleto ou
 *    título em aberto (esses ficam para conferência manual).
 *  - Sem --executar apenas LISTA (simulação). Nada é apagado: o contrato fica
 *    CANCELADO e o vínculo da assinatura fica sem contrato.
 *
 * Uso (na pasta do sistema):
 *   php workers/saas_cancelar_contratos_teste.php              # simulação
 *   php workers/saas_cancelar_contratos_teste.php --executar   # cancela
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/saas_subscription_bridge.php';

$execute = in_array('--executar', $argv, true);

if (!saas_sub_bridge_schema_ready($pdo)) {
    exit("Integração de Assinaturas SaaS não instalada neste banco. Nada a fazer.\n");
}
$cfg = saas_sub_bridge_config($pdo);
$billingCompanyId = (int)($cfg['billing_company_id'] ?? 0);
if ($billingCompanyId <= 0) {
    exit("Empresa faturadora não configurada em Assinaturas Nexa. Nada a fazer.\n");
}

$sql = "SELECT l.id link_id, l.saas_company_id, c.name company_name, s.status sub_status,
               ct.id contract_id, ct.contract_number, ct.status contract_status, ct.total_amount
          FROM saas_subscription_links l
          INNER JOIN companies c ON c.id = l.saas_company_id
          INNER JOIN contracts ct ON ct.company_id = l.billing_company_id AND ct.id = l.contract_id
          LEFT JOIN (SELECT s1.* FROM subscriptions s1
                     INNER JOIN (SELECT company_id, MAX(id) max_id FROM subscriptions GROUP BY company_id) x ON x.max_id = s1.id) s
                 ON s.company_id = l.saas_company_id
         WHERE l.billing_company_id = ? AND UPPER(ct.status) = 'TESTE'
         ORDER BY l.saas_company_id";
$st = $pdo->prepare($sql);
$st->execute([$billingCompanyId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo ($execute ? "MODO EXECUÇÃO" : "MODO SIMULAÇÃO (nada será alterado; use --executar para cancelar)") . "\n";
echo "Empresa faturadora #{$billingCompanyId}. Contratos TESTE encontrados: " . count($rows) . "\n\n";

$canceled = 0; $skipped = 0;
foreach ($rows as $r) {
    $label = sprintf('Empresa #%d %s · contrato nº %s (#%d) · R$ %s',
        (int)$r['saas_company_id'], (string)$r['company_name'], (string)$r['contract_number'],
        (int)$r['contract_id'], number_format((float)$r['total_amount'], 2, ',', '.'));
    if (strtolower((string)($r['sub_status'] ?? '')) !== 'trial') {
        echo "[PULADO] {$label} — assinatura não está em teste (" . ($r['sub_status'] ?: 'sem assinatura') . ").\n";
        $skipped++;
        continue;
    }
    $billing = saas_sub_bridge_contract_billing_state($pdo, $billingCompanyId, (int)$r['contract_id']);
    if ($billing['generation_count'] > 0 || $billing['order_count'] > 0 || $billing['boleto_count'] > 0 || $billing['open_receivable_count'] > 0) {
        echo "[PULADO] {$label} — já tem cobrança gerada; confira manualmente.\n";
        $skipped++;
        continue;
    }
    if (!$execute) {
        echo "[SERÁ CANCELADO] {$label}\n";
        $canceled++;
        continue;
    }
    $pdo->beginTransaction();
    try {
        $note = ' | Cancelado: assinatura em teste não gera mais contrato (o contrato é lançado na ativação).';
        $up = $pdo->prepare("UPDATE contracts SET status='CANCELADO', cancellation_date=CURDATE(), notes=CONCAT(COALESCE(notes,''), ?), updated_at=NOW() WHERE company_id=? AND id=? AND UPPER(status)='TESTE'");
        $up->execute([$note, $billingCompanyId, (int)$r['contract_id']]);
        if ($up->rowCount() !== 1) throw new RuntimeException('contrato mudou durante a operação');
        $pdo->prepare("UPDATE contract_items SET status='CANCELADO', canceled_at=NOW(), updated_at=NOW() WHERE company_id=? AND contract_id=? AND status='ATIVO'")
            ->execute([$billingCompanyId, (int)$r['contract_id']]);
        $pdo->prepare("UPDATE saas_subscription_links SET contract_id=NULL, last_sync_at=NOW(), updated_at=NOW() WHERE id=? AND contract_id=?")
            ->execute([(int)$r['link_id'], (int)$r['contract_id']]);
        $pdo->prepare("UPDATE saas_subscription_items SET contract_item_id=NULL, updated_at=NOW() WHERE link_id=?")
            ->execute([(int)$r['link_id']]);
        $pdo->commit();
        saas_sub_bridge_event($pdo, 'TRIAL_CONTRACT_CANCELED:' . (int)$r['contract_id'], 'TRIAL_CONTRACT_CANCELED', (int)$r['saas_company_id'], (int)$r['link_id'], $billingCompanyId, 'CONTRACT', (int)$r['contract_id'], ['source' => 'workers/saas_cancelar_contratos_teste.php'], true, null);
        echo "[CANCELADO] {$label}\n";
        $canceled++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "[ERRO] {$label} — " . $e->getMessage() . "\n";
        $skipped++;
    }
}

echo "\nResumo: " . ($execute ? 'cancelados' : 'a cancelar') . " {$canceled} · pulados {$skipped}.\n";
