<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/saas_modules.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_comanda_setup')
  : (int)COMPANY_ID;
$qrCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_comanda_setup');
$qrCsrf = $qrCross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$qrContextUrl = static function(string $url) use ($qrCross): string {
  return $qrCross && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
if ($qrCross && (!function_exists('multi_company_context_permission_allowed') || !multi_company_context_permission_allowed($pdo, 'gestao_comandas'))) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para QR Codes de Comandas.');
}
saas_require_company_module($pdo, $companyId, 'comandas', 'QR Codes das mesas/comandas');
comanda_ensure_schema($pdo);
comanda_ensure_public_tokens($pdo, $companyId);
$err = '';
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_token') {
    if ($qrCross && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirado. Atualize a página e tente novamente.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || !comanda_find($pdo, $companyId, $id)) throw new RuntimeException('Comanda não encontrada na empresa selecionada.');
    comanda_regenerate_public_token($pdo, $companyId, $id);
    header('Location: ' . $qrContextUrl('gestao_comandas_qrcode.php?id=' . $id . '&ok=' . rawurlencode('QR Code regenerado. O código anterior deixou de funcionar.'))); exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$company = finance_query_one($pdo, 'SELECT * FROM companies WHERE id=? LIMIT 1', [$companyId]) ?: ['slug'=>defined('COMPANY_SLUG') ? COMPANY_SLUG : ''];
$rows = array_values(array_filter(comanda_rows($pdo, $companyId, false), static fn(array $r): bool => !empty($r['public_order_enabled'])));
$onlyId = (int)($_GET['id'] ?? 0);
if ($onlyId > 0) $rows = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['id'] === $onlyId));

function cmdqr_image(string $content): string {
  $autoload = dirname(__DIR__) . '/vendor/autoload.php';
  if (is_file($autoload)) require_once $autoload;
  if (!class_exists('Com\\Tecnick\\Barcode\\Barcode')) return '';
  try {
    $barcode = new \Com\Tecnick\Barcode\Barcode();
    $obj = $barcode->getBarcodeObj('QRCODE,M', $content, -5, -5, 'black', [-2,-2,-2,-2])->setBackgroundColor('white');
    // SVG não depende da extensão GD/Imagick e funciona melhor em servidores enxutos.
    $svg = $obj->getSvgCode();
    if (is_string($svg) && $svg !== '') return 'data:image/svg+xml;base64,' . base64_encode($svg);
    try {
      $png = $obj->getPngData();
      return is_string($png) && $png !== '' ? 'data:image/png;base64,' . base64_encode($png) : '';
    } catch (Throwable $e) { return ''; }
  } catch (Throwable $e) { return ''; }
}
function cmdqr_url(array $company, array $cmd): string {
  $slug = trim((string)($company['slug'] ?? ''));
  $path = 'comanda_pedido.php?empresa=' . rawurlencode($slug) . '&token=' . rawurlencode((string)($cmd['public_token'] ?? ''));
  return company_catalog_url($company, $path);
}

// Recarrega os tokens, pois comanda_rows retorna todas as colunas da comanda.
$rows = array_map(static function(array $row) use ($pdo, $companyId): array {
  $full = comanda_find($pdo, $companyId, (int)$row['id']);
  return $full ? array_merge($row, $full) : $row;
}, $rows);

gestao_admin_start('QR Codes das Mesas', 'gestao_comandas_qrcode');
gestao_page_header('QR Codes das Mesas/Comandas', 'Imprima um QR Code para cada mesa. O cliente será direcionado ao cardápio já com a mesa/comanda identificada.', [
  ['label'=>'Cadastro de Comandas','href'=>$qrContextUrl('gestao_comandas_config.php'),'class'=>'btn'],
  ['label'=>'Agentes de Impressão','href'=>$qrContextUrl('gestao_comandas_agentes.php'),'class'=>'btn'],
  ['label'=>'Monitor de Impressão','href'=>$qrContextUrl('gestao_comandas_impressao.php'),'class'=>'btn'],
  ['label'=>'Imprimir','href'=>'javascript:window.print()','class'=>'btn btn-primary'],
]);
gestao_tabs('gestao_comandas_qrcode');
gestao_flash(); if ($qrCross) echo '<div class="msg ok"><strong>V11.32 · QR Code Multiempresa</strong> — os QR Codes abaixo pertencem exclusivamente à empresa selecionada pelo F11.</div>'; if ($err) echo '<div class="msg err">' . comanda_h($err) . '</div>';
?>
<style>
.qr-help{margin-bottom:14px;padding:13px 15px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:18px;line-height:1.5}.qr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}.qr-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;text-align:center;break-inside:avoid;box-shadow:0 12px 30px rgba(15,23,42,.06)}.qr-card h2{margin:0;color:#0b1f5b;font-size:27px}.qr-card p{margin:5px 0 12px;color:#64748b}.qr-img{width:min(230px,100%);aspect-ratio:1;margin:auto;display:grid;place-items:center;border:1px solid #e2e8f0;border-radius:20px;background:#fff;overflow:hidden}.qr-img img{width:100%;height:100%;object-fit:contain}.qr-url{font-size:10px;color:#64748b;word-break:break-all;margin-top:9px}.qr-actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:12px}.qr-instruction{font-size:14px;color:#0f172a;font-weight:900;margin-top:10px}@media(max-width:1000px){.qr-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.qr-grid{grid-template-columns:1fr}}@media print{@page{size:A4;margin:9mm}.admin-topbar,.mg-hero,.mg-tabs,.qr-help,.qr-actions,.admin-footer,.admin-sidebar,.admin-menu-overlay{display:none!important}.mg-page,.admin-main{padding:0!important;margin:0!important}.qr-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:6mm}.qr-card{box-shadow:none;border:1px solid #000;border-radius:5mm;padding:6mm;page-break-inside:avoid}.qr-img{width:48mm;border:0}.qr-url{display:none}.qr-card h2{font-size:20pt}.qr-card p{font-size:11pt}.qr-instruction{font-size:10pt}}
</style>
<div class="qr-help"><strong>Como usar:</strong> imprima e fixe o QR Code na mesa. O cliente escaneia, escolhe os produtos, confere o pedido e informa nome e celular. O pedido entra na comanda e na fila da cozinha/bar.</div>
<div class="qr-grid">
<?php foreach ($rows as $c): $url=cmdqr_url($company,$c); $img=cmdqr_image($url); ?>
  <article class="qr-card"><h2>Mesa/Comanda <?= comanda_h($c['codigo']) ?></h2><p><?= comanda_h($c['nome'] ?: 'Faça seu pedido pelo celular') ?></p><div class="qr-img"><?php if ($img): ?><img src="<?= comanda_h($img) ?>" alt="QR Code da comanda <?= comanda_h($c['codigo']) ?>"><?php else: ?><div>Biblioteca de QR Code indisponível.</div><?php endif; ?></div><div class="qr-instruction">Aponte a câmera do celular e faça seu pedido</div><div class="qr-url"><?= comanda_h($url) ?></div><div class="qr-actions"><a class="btn btn-sm" target="_blank" href="<?= comanda_h($url) ?>">Testar</a><?php if ($onlyId<=0): ?><a class="btn btn-sm" href="<?= comanda_h($qrContextUrl('gestao_comandas_qrcode.php?id=' . (int)$c['id'])) ?>">Imprimir somente esta</a><?php endif; ?><form method="post" onsubmit="return confirm('Regenerar o QR Code? O código impresso anteriormente deixará de funcionar.');"><input type="hidden" name="action" value="regenerate_token"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?php if ($qrCross && $qrCsrf !== ''): ?><input type="hidden" name="mc_csrf" value="<?= comanda_h($qrCsrf) ?>"><?php endif; ?><button class="btn btn-sm" type="submit">Regenerar</button></form></div></article>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="mg-card">Nenhuma comanda habilitada para pedidos por QR Code. Ative a opção no Cadastro de Comandas.</div><?php endif; ?>
</div>
<?php gestao_admin_end(); ?>
