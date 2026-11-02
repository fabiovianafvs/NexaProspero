<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$profiles = fiscal_tax_profiles($pdo, (int)COMPANY_ID, false);
$activeProfiles = array_values(array_filter($profiles, fn($r) => (int)($r['is_active'] ?? 0) === 1));
$productTaxCount = 0;
$xmlTotal = 0;
$xmlPending = 0;
try { fiscal_xml_ensure_schema($pdo); } catch (Throwable $e) {}
try {
  $st = $pdo->prepare('SELECT COUNT(*) FROM fiscal_product_tax WHERE company_id = ?');
  $st->execute([COMPANY_ID]);
  $productTaxCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}
try {
  $st = $pdo->prepare("SELECT COUNT(*) total, SUM(status='IMPORTADO') pending FROM fiscal_xml_imports WHERE company_id = ?");
  $st->execute([COMPANY_ID]);
  $xmlKpi = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $xmlTotal = (int)($xmlKpi['total'] ?? 0);
  $xmlPending = (int)($xmlKpi['pending'] ?? 0);
} catch (Throwable $e) {}

fiscal_admin_start('Fiscal', 'fiscal');
fiscal_page_header('Fiscal', 'Cadastros fiscais, operações, XMLs de NF-e, tributações por produto e base para emissão futura de NFC-e/NF-e.', [
  ['label' => 'RADAR XML', 'href' => 'fiscal_radar_xml.php', 'class' => 'btn btn-primary'],
  ['label' => '+ Nova tributação', 'href' => 'fiscal_tributacoes.php?new=1', 'class' => 'btn'],
  ['label' => 'Produtos', 'href' => 'produtos.php', 'class' => 'btn'],
]);
fiscal_tabs('fiscal');
fiscal_flash();
?>
<div class="fiscal-grid-4">
  <div class="fiscal-kpi"><span>Tributações cadastradas</span><strong><?= count($profiles) ?></strong></div>
  <div class="fiscal-kpi"><span>Produtos com dados fiscais</span><strong><?= (int)$productTaxCount ?></strong></div>
  <div class="fiscal-kpi"><span>XMLs no RADAR</span><strong><?= (int)$xmlTotal ?></strong></div>
  <div class="fiscal-kpi"><span>XMLs aguardando entrada</span><strong><?= (int)$xmlPending ?></strong></div>
</div>

<div class="fiscal-grid" style="margin-top:14px;align-items:start">
  <div class="fiscal-card">
    <h2>Fluxo de entrada por XML</h2>
    <p class="fiscal-muted">O módulo fiscal agora possui RADAR XML, importação de XML de NF-e, cadastro de operações e geração de entrada integrada ao contas a pagar.</p>
    <ul class="fiscal-muted" style="line-height:1.7;margin-bottom:14px">
      <li>Cadastre ou confira as <b>Operações</b> de entrada, saída e devolução.</li>
      <li>Importe o XML da NF-e pelo menu <b>Importar XML</b>.</li>
      <li>No <b>RADAR XML</b>, clique em <b>Gerar entrada</b>.</li>
      <li>O sistema cadastra o fornecedor, lê as parcelas do XML e cria o contas a pagar.</li>
    </ul>
    <div class="fiscal-inline-actions"><a class="btn btn-primary" href="fiscal_importar_xml.php">Importar XML</a><a class="btn" href="fiscal_operacoes.php">Operações</a></div>
  </div>
  <div class="fiscal-card">
    <h2>Tributações recentes</h2>
    <div class="table-wrap">
      <table class="fiscal-table">
        <thead><tr><th>Código</th><th>Nome</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
          <?php if (!$profiles): ?><tr><td colspan="4" style="text-align:center;color:#64748b">Nenhuma tributação cadastrada.</td></tr><?php endif; ?>
          <?php foreach (array_slice($profiles, 0, 6) as $p): ?>
            <tr>
              <td data-label="Código"><?= fiscal_h($p['code'] ?? '') ?></td>
              <td data-label="Nome" class="primary"><strong><?= fiscal_h($p['name'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h($p['tax_mode'] ?? '') ?></div></td>
              <td data-label="Status"><span class="fiscal-badge <?= !empty($p['is_active']) ? '' : 'off' ?>"><?= !empty($p['is_active']) ? 'Ativa' : 'Inativa' ?></span></td>
              <td data-label="Ações"><a class="btn btn-sm" href="fiscal_tributacoes.php?id=<?= (int)$p['id'] ?>">Editar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php fiscal_admin_end(); ?>
