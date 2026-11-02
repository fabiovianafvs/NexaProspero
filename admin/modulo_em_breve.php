<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/saas_modules.php';

$module = strtolower(trim((string)($_GET['modulo'] ?? '')));
$allowed = ['financeiro', 'gestao', 'fiscal', 'exportacao', 'api'];
if (!in_array($module, $allowed, true)) {
  $module = 'gestao';
}

saas_require_company_module($pdo, (int)COMPANY_ID, $module);

$title = saas_module_label($module);
$descriptions = [
  'financeiro' => 'Este módulo será usado para contas a receber, contas a pagar, movimento bancário, livro caixa, plano de contas e centro de custo.',
  'gestao' => 'Este módulo será usado para cadastro de clientes, vendedores, pedido manual, dashboards gerenciais, relatórios e DRE.',
  'fiscal' => 'Este módulo será usado para NF-e, NFC-e, entrada por XML, Sintegra, SPED, XMLs e painel do contador.',
  'exportacao' => 'Este módulo libera a fila de exportação e a integração Firebird/Próspero nas configurações.',
  'api' => 'Este módulo libera a configuração da API externa e o uso dos endpoints de integração.',
];

admin_topbar('Central - ' . $title, $module);
?>

<style>
.module-soon{max-width:920px;margin:18px auto;background:#fff;border:1px solid #dbeafe;border-radius:24px;padding:26px;box-shadow:0 14px 36px rgba(11,31,91,.08)}
.module-soon .tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:850;margin-bottom:14px}
.module-soon h2{margin:0 0 10px;color:#0b1f5b;font-size:30px;letter-spacing:-.03em}.module-soon p{color:#475569;line-height:1.65;font-size:15px}.module-list{display:grid;gap:10px;margin-top:18px}.module-item{padding:14px 16px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fbff;color:#0f172a;font-weight:750}.module-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.module-actions a{display:inline-flex;padding:12px 16px;border-radius:999px;text-decoration:none;font-weight:900}.primary{background:#0b1f5b;color:#fff}.ghost{border:1px solid #cbd5e1;color:#0b1f5b;background:#fff}
</style>

<section class="module-soon">
  <div class="tag">Módulo liberado</div>
  <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
  <p><?= htmlspecialchars($descriptions[$module] ?? 'Módulo em preparação.', ENT_QUOTES, 'UTF-8') ?></p>
  <p>A liberação por plano/empresa já está ativa. As telas definitivas deste módulo podem ser implementadas mantendo o mesmo controle de acesso.</p>

  <div class="module-list">
    <?php if ($module === 'financeiro'): ?>
      <div class="module-item">Contas a receber</div>
      <div class="module-item">Contas a pagar</div>
      <div class="module-item">Caixas, bancos e livro caixa</div>
      <div class="module-item">Plano de contas e centro de custo</div>
    <?php elseif ($module === 'gestao'): ?>
      <div class="module-item">Clientes e vendedores</div>
      <div class="module-item">Pedido manual dentro do Admin</div>
      <div class="module-item">Dashboard gerencial e DRE</div>
      <div class="module-item">Relatórios comerciais</div>
    <?php else: ?>
      <div class="module-item">NF-e e NFC-e</div>
      <div class="module-item">Entrada de notas por XML</div>
      <div class="module-item">Sintegra e SPED</div>
      <div class="module-item">Painel do contador para XMLs e relatórios</div>
    <?php endif; ?>
  </div>

  <div class="module-actions">
    <a class="primary" href="dashboard.php">Voltar ao Dashboard</a>
    <a class="ghost" href="parametros.php">Configurações</a>
  </div>
</section>

<?php admin_footer(); ?>
