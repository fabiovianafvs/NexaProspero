<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
fiscal_ensure_schema($pdo);
fiscal_admin_start('Fiscal - Tabelas fiscais','fiscal_tabelas');
fiscal_page_header('Tabelas Fiscais', 'Catálogos oficiais compartilhados e tributações configuráveis por empresa.');
fiscal_tabs('fiscal_tabelas');
$cards = [
  ['CFOP','Regras operacionais e CFOPs configuráveis conforme a operação da empresa.','fiscal_cfops.php'],
  ['NCM / IBPT','NCM global e alíquotas IBPT globais por UF, versão e vigência.','fiscal_ncms.php'],
  ['CEST','Catálogo global CEST x NCM. A tributação de ICMS/ST continua configurada pela empresa.','fiscal_cests.php'],
  ['CST / CSOSN / Origem','Códigos fiscais compartilhados para ICMS, IPI, PIS, COFINS e origem.','fiscal_cst.php'],
  ['IBS/CBS, CBS e IBS','Classificações e alíquotas oficiais globais por vigência, UF e município.','fiscal_ibscbs.php'],
  ['Tributações da empresa','Perfis fiscais individuais com CST, CSOSN, CFOP, cClassTrib e modo automático/manual.','fiscal_tributacoes.php'],
];
?>
<div class="msg ok"><b>Modelo multiempresa seguro:</b> NCM, CEST, IBPT, cClassTrib, CBS e IBS são catálogos globais. Cada empresa mantém seus próprios produtos, perfis tributários, CST/CSOSN, CFOP, enquadramentos e alíquotas manuais.</div>
<div class="fiscal-grid-3">
<?php foreach($cards as $c): ?>
  <a class="fiscal-card" href="<?= fiscal_h($c[2]) ?>" style="text-decoration:none;color:inherit;display:block">
    <h2><?= fiscal_h($c[0]) ?></h2>
    <p class="fiscal-muted"><?= fiscal_h($c[1]) ?></p>
    <span class="btn btn-sm">Abrir</span>
  </a>
<?php endforeach; ?>
</div>
<div class="fiscal-card">
  <h2>Atualização dos catálogos</h2>
  <p class="fiscal-muted">Os catálogos globais não podem ser alterados por uma empresa isolada. A atualização é feita pelo administrador do servidor por importação controlada, com versão, vigência e hash do arquivo. A empresa continua configurando normalmente suas tributações e seus produtos.</p>
  <a class="btn" href="fiscal_importacoes.php">Ver orientações de atualização</a>
</div>
<?php fiscal_admin_end(); ?>
