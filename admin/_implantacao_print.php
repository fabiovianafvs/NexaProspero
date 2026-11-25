<?php
declare(strict_types=1);

// Trilha de Implantação: cabeçalho e estilo comuns dos documentos para imprimir (A4).
require_once __DIR__ . '/_print_brand.php';

if (!function_exists('impl_print_head')) {
  function impl_print_head(PDO $pdo, int $companyId, string $titulo, string $sub = ''): void {
    $st = $pdo->prepare('SELECT name, cnpj, cpf, endereco, numero, bairro, cidade, estado, email, whatsapp FROM companies WHERE id=? LIMIT 1');
    $st->execute([$companyId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $logo = nexa_print_logo_url($companyId);
    $doc = trim((string)($c['cnpj'] ?? '')) !== '' ? 'CNPJ ' . $c['cnpj'] : (trim((string)($c['cpf'] ?? '')) !== '' ? 'CPF ' . $c['cpf'] : '');
    $end = trim(implode(', ', array_filter([(string)($c['endereco'] ?? ''), (string)($c['numero'] ?? ''), (string)($c['bairro'] ?? '')])) . ' ' . trim((string)($c['cidade'] ?? '') . '/' . (string)($c['estado'] ?? ''), '/ '));
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= impl_h($titulo) ?></title>
<style>
@page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#0f172a;font-size:10.5pt;background:#eef2f7}
.sheet{width:210mm;max-width:100%;margin:12px auto;background:#fff;padding:12mm;box-shadow:0 10px 30px rgba(0,0,0,.08)}
.hd{display:flex;gap:14px;align-items:center;border-bottom:2px solid #0b1f5b;padding-bottom:8px;margin-bottom:10px}
.hd img{max-width:34mm;max-height:20mm;object-fit:contain}.hd .co{flex:1}.hd .co b{font-size:13pt;color:#0b1f5b}.hd .co div{font-size:8.5pt;color:#475569}
.ti{text-align:right}.ti b{display:block;font-size:13pt;color:#0b1f5b}.ti span{font-size:9pt;color:#475569}
h2{font-size:10.5pt;background:#0b1f5b;color:#fff;padding:4px 8px;border-radius:4px;margin:12px 0 6px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.g{display:grid;grid-template-columns:repeat(2,1fr);gap:3px 16px}.g div b{color:#475569;font-weight:600;margin-right:4px}
table{width:100%;border-collapse:collapse;font-size:9.5pt}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left;vertical-align:top}th{background:#f1f5f9;-webkit-print-color-adjust:exact;print-color-adjust:exact}
ul{margin:2px 0;padding-left:16px}.mut{color:#64748b}
.kp{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:4px 0}.kp div{border:1px solid #cbd5e1;border-radius:6px;padding:5px 8px}.kp b{display:block;font-size:13pt;color:#0b1f5b}
.ph{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.ph figure{margin:0;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;break-inside:avoid}.ph img{width:100%;height:42mm;object-fit:cover;display:block}.ph figcaption{font-size:8.5pt;padding:3px 5px}
.av{border:1.5px solid #f59e0b;background:#fffbeb;border-radius:6px;padding:7px 9px;font-size:9.5pt;margin-top:6px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.sg{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:26px}.sg div{border-top:1px solid #0f172a;text-align:center;padding-top:3px;font-size:9pt}.sg img{max-height:22mm;max-width:100%;display:block;margin:0 auto -2px}
.bar{position:sticky;top:0;display:flex;gap:8px;justify-content:center;padding:8px;background:#0b1f5b}.bar button{border:0;border-radius:8px;padding:8px 16px;font-weight:700;cursor:pointer}
section{break-inside:avoid-page}
@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;padding:0;width:auto}.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Imprimir / salvar PDF</button><button onclick="window.close()">Fechar</button></div>
<div class="sheet">
  <div class="hd"><?php if ($logo !== ''): ?><img src="<?= impl_h($logo) ?>" alt=""><?php endif; ?>
    <div class="co"><b><?= impl_h($c['name'] ?? '') ?></b><div><?= impl_h(trim($doc . ($end !== '' ? ' · ' . $end : ''))) ?></div><div><?= impl_h(trim(implode(' · ', array_filter([(string)($c['email'] ?? ''), (string)($c['whatsapp'] ?? '')])))) ?></div></div>
    <div class="ti"><b><?= impl_h($titulo) ?></b><?php if ($sub !== ''): ?><span><?= impl_h($sub) ?></span><?php endif; ?></div>
  </div>
<?php
  }
  function impl_print_end(): void { echo '</div></body></html>'; }
}
