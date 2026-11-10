<?php
declare(strict_types=1);

// Cancelar UMA NFS-e específica (ex.: nota emitida em duplicidade para o mesmo serviço).
// Só registra o evento de cancelamento no provedor (Ambiente Nacional ou Pronim);
// NÃO estorna O.S., pedido, financeiro nem estoque. Para refazer a venda inteira,
// use "Cancelar NFS-e / pedido" na O.S. ou no pedido.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';

function nfsec_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

$cid = (int)COMPANY_ID;
$docId = max(0, (int)($_GET['doc_id'] ?? 0));
$isF11 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && function_exists('multi_company_operational_company_id')
  && (int)multi_company_operational_company_id($pdo, 'fiscal_nfse_homologation') !== $cid;
$error = '';
$doc = null;
$siblings = [];
$queueDocId = 0;
try {
  if ($isF11) throw new RuntimeException('Saia do modo Multiempresa (F11) para cancelar uma NFS-e por esta tela.');
  $doc = $docId > 0 ? nfse_emitter_document_by_id($pdo, $cid, $docId) : null;
  if (!$doc) throw new RuntimeException('NFS-e não encontrada nesta empresa.');
  // Outras NFS-e da mesma origem (mesma O.S. ou mesmo pedido).
  $st = $pdo->prepare("SELECT d.id, d.status, d.number, d.total_document, d.created_at, nd.dps_series, nd.dps_number, nd.nfse_number
      FROM fiscal_documents d LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id
     WHERE d.company_id=? AND d.model='NFSE' AND d.source_type=? AND d.source_id=? AND d.id<>? ORDER BY d.id");
  $st->execute([$cid, (string)$doc['source_type'], (int)$doc['source_id'], $docId]);
  $siblings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (strtoupper((string)$doc['source_type']) === 'ORDEM_SERVICO') {
    try {
      $q = $pdo->prepare("SELECT fiscal_document_id FROM os_fiscal_queue WHERE company_id=? AND os_order_id=? AND document_type='NFSE' ORDER BY id DESC LIMIT 1");
      $q->execute([$cid, (int)$doc['source_id']]);
      $queueDocId = (int)$q->fetchColumn();
    } catch (Throwable $e) {}
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
}
$csrf = nfse_emitter_csrf_token('nfse_emitter');
$status = $doc ? strtoupper((string)$doc['status']) : '';
$env = $doc ? strtoupper((string)($doc['environment'] ?? '')) : '';
$provider = $doc ? nfse_provider_document_code($pdo, $cid, $docId) : '';
$authorizedSiblings = array_values(array_filter($siblings, static fn($s) => strtoupper((string)$s['status']) === 'AUTORIZADA'));
$nfseLabel = static fn(array $d) => 'NFS-e ' . (trim((string)($d['nfse_number'] ?? '')) ?: (string)($d['number'] ?? '-')) . ' (DPS ' . ltrim((string)($d['dps_series'] ?? ''), '0') . '/' . (string)($d['dps_number'] ?? '') . ')';

admin_topbar('Cancelar NFS-e', 'fiscal_documentos');
?>
<div style="max-width:860px;margin:14px auto;display:grid;gap:14px">
<?php if (isset($_GET['ok'])): ?><div class="msg ok"><?= fiscal_h((string)$_GET['ok']) ?></div><?php endif; ?>
<?php if (isset($_GET['err'])): ?><div class="msg err"><?= fiscal_h((string)$_GET['err']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="msg err"><?= fiscal_h($error) ?></div>
  <div><a class="btn" href="fiscal_documentos.php">Voltar aos documentos fiscais</a></div>
<?php else: ?>
  <section class="card" style="padding:18px">
    <h2 style="margin:0 0 8px"><?= fiscal_h($nfseLabel($doc)) ?></h2>
    <div class="fiscal-muted" style="line-height:1.6">
      Origem: <b><?= fiscal_h((string)$doc['source_type'] . ' #' . (string)$doc['source_id']) ?></b> ·
      Valor: <b><?= nfsec_money($doc['total_document'] ?? 0) ?></b> ·
      Situação: <b><?= fiscal_h($status) ?></b> · Ambiente: <?= fiscal_h($env) ?> · Provedor: <?= fiscal_h($provider) ?><br>
      Chave: <?= fiscal_h((string)($doc['access_key'] ?? '')) ?>
      <?php if ($queueDocId > 0): ?><br><?= $queueDocId === $docId ? '<b style="color:#0f766e">Esta é a NFS-e vinculada à O.S.</b>' : '<b style="color:#b45309">Esta NFS-e NÃO é a vinculada à O.S. (duplicada).</b>' ?><?php endif; ?>
    </div>
    <?php if ($authorizedSiblings): ?>
      <div class="msg" style="margin-top:12px;background:#fff7ed;border:1px solid #fed7aa">
        Existe(m) outra(s) NFS-e autorizada(s) para a mesma origem:
        <?php foreach ($authorizedSiblings as $s): ?><br>• <?= fiscal_h($nfseLabel($s)) ?> · <?= nfsec_money($s['total_document'] ?? 0) ?><?= (int)$s['id'] === $queueDocId ? ' · <b>vinculada à O.S.</b>' : '' ?> · <a href="fiscal_nfse_cancelar.php?doc_id=<?= (int)$s['id'] ?>">abrir</a><?php endforeach; ?>
        <br>Em caso de duplicidade, cancele somente <b>uma</b> delas<?= $queueDocId > 0 ? ' — de preferência a que <b>não</b> está vinculada à O.S.' : '' ?>.
      </div>
    <?php endif; ?>
  </section>

  <?php if ($status === 'AUTORIZADA'): ?>
  <section class="card" style="padding:18px">
    <h3 style="margin:0 0 6px">Enviar cancelamento</h3>
    <div class="fiscal-muted" style="margin-bottom:12px">O cancelamento é enviado <?= $provider === 'PRONIM' ? 'à prefeitura (ISS Digital)' : 'ao Ambiente Nacional (Receita)' ?> e tem efeito fiscal. <b>Não</b> altera a O.S., o pedido, o financeiro ou o estoque.</div>
    <form method="post" action="fiscal_nfse_acao.php" style="display:grid;gap:10px" onsubmit="return confirm('Confirma o cancelamento da <?= fiscal_h($nfseLabel($doc)) ?>? Esta ação tem efeito fiscal.');">
      <input type="hidden" name="csrf_token" value="<?= fiscal_h($csrf) ?>">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="doc_id" value="<?= $docId ?>">
      <input type="hidden" name="pedido_id" value="<?= (int)($doc['pedido_id'] ?? 0) ?>">
      <input type="hidden" name="return_to" value="fiscal_nfse_cancelar.php?doc_id=<?= $docId ?>">
      <label style="display:grid;gap:4px"><b>Motivo fiscal</b><select name="cancel_reason_code" required><option value="1">1 - Erro na emissão</option><option value="2">2 - Serviço não prestado</option><option value="9">9 - Outros</option></select></label>
      <label style="display:grid;gap:4px"><b>Justificativa</b> (15 a 255 caracteres)<input type="text" name="cancel_reason" minlength="15" maxlength="255" required value="<?= $authorizedSiblings ? 'NFS-e emitida em duplicidade para o mesmo servico' : '' ?>"></label>
      <?php if ($env === 'PRODUCAO'): ?>
        <label style="display:grid;gap:4px"><b>Confirmação de produção</b> — digite CANCELAR<input type="text" name="cancel_confirmation" autocomplete="off" required pattern="CANCELAR" placeholder="CANCELAR" style="text-transform:uppercase"></label>
      <?php endif; ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Enviar evento de cancelamento</button><a class="btn" href="fiscal_documentos.php">Voltar</a></div>
    </form>
  </section>
  <?php else: ?>
    <div class="msg">Somente NFS-e autorizada pode ser cancelada. <a href="fiscal_documentos.php">Voltar</a></div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
