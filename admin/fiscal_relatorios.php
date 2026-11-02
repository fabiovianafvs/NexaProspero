<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
fiscal_xml_ensure_schema($pdo);
$month = date('Y-m');
[$ini, $fim] = fiscal_report_period(['period_month' => $month]);
$entries = fiscal_report_entries($pdo, $cid, $ini, $fim);
$total = 0.0;
foreach ($entries as $e) $total += (float)($e['total_invoice'] ?? 0);

fiscal_admin_start('Relatórios Fiscais', 'fiscal_relatorios');
fiscal_page_header('Relatórios Fiscais', 'Relatórios de documentos autorizados, movimentação fiscal, inventário, Sintegra e SPED. Os novos relatórios permitem filtrar período, tipo de documento e série.', [
  ['label'=>'Documentos Fiscais', 'href'=>'fiscal_relatorio_documentos.php', 'class'=>'btn btn-primary'],
  ['label'=>'Inutilizações', 'href'=>'fiscal_relatorio_inutilizacoes.php', 'class'=>'btn'],
  ['label'=>'Documentos para Contabilidade', 'href'=>'fiscal_contabilidade.php', 'class'=>'btn'],
  ['label'=>'Sintegra', 'href'=>'fiscal_relatorio_sintegra.php', 'class'=>'btn'],
  ['label'=>'SPED ICMS/IPI', 'href'=>'fiscal_relatorio_sped_icms.php', 'class'=>'btn'],
  ['label'=>'SPED Contribuições', 'href'=>'fiscal_relatorio_sped_contrib.php', 'class'=>'btn'],
  ['label'=>'Inventário', 'href'=>'fiscal_relatorio_inventario.php', 'class'=>'btn'],
  ['label'=>'Exportar XMLs', 'href'=>'fiscal_exportar_xml.php', 'class'=>'btn'],
]);
fiscal_report_tabs('fiscal_relatorios');
fiscal_flash();
?>
<div class="fiscal-grid-3">
  <div class="fiscal-kpi"><span>Período atual</span><strong><?= fiscal_h($ini . ' a ' . $fim) ?></strong></div>
  <div class="fiscal-kpi"><span>Entradas consideradas</span><strong><?= count($entries) ?></strong></div>
  <div class="fiscal-kpi"><span>Total reportável</span><strong>R$ <?= number_format($total, 2, ',', '.') ?></strong></div>
</div>
<div class="fiscal-grid" style="margin-top:14px;align-items:stretch">
  <div class="fiscal-card">
    <h2>Documentos fiscais por período</h2>
    <p class="fiscal-muted">Emite relação de documentos fiscais, documentos agrupados por CFOP e resumo de CFOP por forma de pagamento, com filtros de período, NFC-e/NF-e e série.</p>
    <a class="btn btn-primary" href="fiscal_relatorio_documentos.php">Abrir relatórios</a>
  </div>
  <div class="fiscal-card">
    <h2>Inutilização de numeração</h2>
    <p class="fiscal-muted">Relação das faixas de NF-e e NFC-e inutilizadas, com série, protocolo, justificativa e retorno da SEFAZ.</p>
    <a class="btn btn-primary" href="fiscal_relatorio_inutilizacoes.php">Abrir relatório</a>
  </div>
  <div class="fiscal-card">
    <h2>Documentos para Contabilidade</h2>
    <p class="fiscal-muted">Gera e publica fechamentos com SINTEGRA, documentos fiscais, XMLs, inventário e pacote completo para a Central do Contador Próspero.</p>
    <a class="btn btn-primary" href="fiscal_contabilidade.php">Abrir fechamentos</a>
  </div>
  <div class="fiscal-card">
    <h2>Geração SINTEGRA</h2>
    <p class="fiscal-muted">Prepara arquivo texto com registros de cadastro, entradas e totalização. Lançamentos com número 0 ficam fora automaticamente.</p>
    <a class="btn btn-primary" href="fiscal_relatorio_sintegra.php">Abrir geração</a>
  </div>
  <div class="fiscal-card">
    <h2>SPED ICMS/IPI</h2>
    <p class="fiscal-muted">Estrutura inicial para EFD ICMS/IPI com entradas. Blocos de vendas serão completados após a emissão dos documentos fiscais.</p>
    <a class="btn btn-primary" href="fiscal_relatorio_sped_icms.php">Abrir geração</a>
  </div>
  <div class="fiscal-card">
    <h2>SPED Contribuições</h2>
    <p class="fiscal-muted">Geração inicial de PIS/COFINS com dados de entrada e CSTs convertidos conforme regras fiscais cadastradas.</p>
    <a class="btn btn-primary" href="fiscal_relatorio_sped_contrib.php">Abrir geração</a>
  </div>
  <div class="fiscal-card">
    <h2>Inventário</h2>
    <p class="fiscal-muted">Emite estoque com NCM, CEST, CST/CSOSN, PIS/COFINS, custo, total geral e totalização por tributação.</p>
    <a class="btn btn-primary" href="fiscal_relatorio_inventario.php">Abrir relatório</a>
  </div>
  <div class="fiscal-card">
    <h2>Exportar XMLs</h2>
    <p class="fiscal-muted">Gera arquivo ZIP de NF-e e NFC-e autorizadas, canceladas ou inutilizadas, com filtro de período e série.</p>
    <a class="btn btn-primary" href="fiscal_exportar_xml.php">Abrir exportação</a>
  </div>
</div>
<div class="msg ok" style="margin-top:14px">Os relatórios de documentos consideram as NF-e e NFC-e de saída autorizadas no período selecionado. Para escrituração oficial, confira os cadastros tributários e os arquivos SPED/Sintegra com a contabilidade.</div>
<?php fiscal_admin_end(); ?>
