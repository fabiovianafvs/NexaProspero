<?php
declare(strict_types=1);

// Orçamento no modelo "Proposta/Orçamento" (A4 com quadros).
// Incluído somente por orcamento_imprimir.php (já autenticado e com a empresa resolvida).
require_once __DIR__ . '/../config/orcamento_proposta.php';

$pp = orc_proposta_data($pdo, $companyId, $q, $items, $payments);
$ppC = $pp['company'];
$ppU = $pp['customer'];
$ppT = $pp['totals'];
$ppH = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ppDocLabel = strlen(preg_replace('/\D/', '', $ppC['doc']) ?? '') > 11 ? 'CNPJ' : 'CPF';
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Proposta/Orçamento <?= $ppH($pp['number']) ?></title>
<link rel="stylesheet" href="../assets/nexa-print-actions.css?v=1145197">
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#000;font-family:"Times New Roman",Times,serif}
@page{size:A4 portrait;margin:8mm}
.pp{width:194mm;min-height:281mm;margin:12px auto;background:#fff;padding:4mm;box-shadow:0 10px 28px rgba(15,23,42,.12);font-size:9.5pt}
.pp-box{border:1px solid #000}
.pp-head{display:grid;grid-template-columns:38mm 1fr 58mm;border:1.2px solid #000;min-height:34mm}
.pp-head>div{padding:2mm}.pp-head>div+div{border-left:1px solid #000}
.pp-logo{display:flex;align-items:center;justify-content:center}.pp-logo img{max-width:34mm;max-height:30mm;object-fit:contain}
.pp-co{font-size:9pt;line-height:1.3}.pp-co strong{font-size:10pt;display:block;text-transform:uppercase}
.pp-num{font-size:9pt}.pp-num .n{display:flex;gap:6px;align-items:baseline;font-weight:700;font-size:10pt;margin:3mm 0 2mm}.pp-num .n span:last-child{font-family:Arial,Helvetica,sans-serif;font-size:11pt}
.pp-num .r{display:grid;grid-template-columns:30mm 1fr;margin-bottom:1.5mm}
.pp-bar{background:#d9d9d9;border:1px solid #000;border-top:0;font-weight:700;padding:.6mm 1mm;font-size:9.5pt}
.pp-bar.sep{border-top:1px solid #000;margin-top:5mm}
.pp-cli{border:1px solid #000;border-top:0;padding:1.5mm 2mm;display:grid;grid-template-columns:62% 38%;row-gap:.6mm;font-size:9pt}
.pp-cli b{display:inline-block;min-width:19mm}.pp-cli .full{grid-column:1/-1}
.pp-intro{border:1px solid #000;border-top:0;text-align:center;padding:2mm;font-family:Arial,Helvetica,sans-serif;font-style:italic;text-decoration:underline;font-size:11pt}
.pp-items{width:100%;border-collapse:separate;border-spacing:0 1mm;font-size:8.5pt;margin-top:-1mm}
.pp-items thead th{font-weight:700;text-align:left;padding:.6mm 1mm;border-bottom:1px solid #000}
.pp-items tbody td{border-top:1px solid #000;border-bottom:1px solid #000;padding:1mm;vertical-align:top;font-family:Arial,Helvetica,sans-serif;font-size:7.5pt}
.pp-items tbody td:first-child{border-left:1px solid #000}.pp-items tbody td:last-child{border-right:1px solid #000}
.pp-items .c{text-align:center}.pp-items .r{text-align:right}
.pp-items .desc{white-space:pre-line}
.pp-items tr{break-inside:avoid;page-break-inside:avoid}
.pp-tot{width:82mm;border:1px solid #000;padding:1mm 2mm;margin-top:1mm;font-size:8.5pt}
.pp-tot div{display:flex;justify-content:space-between;padding:.4mm 0}.pp-tot .i{font-style:italic}.pp-tot .b{font-weight:700}.pp-tot span:last-child{font-weight:700;min-width:25mm;text-align:right}
.pp-other{border:1px solid #000;border-top:0;padding:1.5mm 2mm;font-weight:700;font-size:10pt}
.pp-other .v{font-family:Arial,Helvetica,sans-serif;font-size:9pt}
.pp-obs{border:1px solid #000;border-top:0;padding:1mm;min-height:34mm;font-weight:700;font-size:9pt;white-space:pre-line;text-transform:uppercase}
.pp-terms{margin-top:4mm;font-size:8pt;max-width:150mm;line-height:1.6;text-transform:uppercase}
.pp-date{margin:6mm 6mm 0 auto;width:30mm;text-align:center;font-size:8.5pt}.pp-date b{display:block}
.pp-sign{display:grid;grid-template-columns:75mm 75mm;gap:6mm;margin-top:6mm;font-size:8pt;font-weight:700;text-align:center;text-transform:uppercase}
.pp-sign div{border-top:1px solid #000;padding-top:.5mm}
@media print{body{background:#fff}.nexa-print-toolbar{display:none!important}.pp{box-shadow:none;margin:0;padding:0;width:auto;min-height:0}.pp-bar,.pp-items thead{-webkit-print-color-adjust:exact;print-color-adjust:exact}.pp-items thead{display:table-header-group}}
@media screen and (max-width:820px){.pp{width:100%;min-height:0;margin:0}}
</style></head><body>
<?= $orcToolbarHtml ?>
<main class="pp">
  <div class="pp-head">
    <div class="pp-logo"><?php if ($logo !== ''): ?><img src="<?= $ppH($logo) ?>" alt="Logo"><?php endif; ?></div>
    <div class="pp-co">
      <strong><?= $ppH($ppC['name']) ?></strong>
      <?php if ($ppC['doc'] !== ''): ?><div><?= $ppDocLabel ?>: <?= $ppH($ppC['doc']) ?></div><?php endif; ?>
      <?php if ($ppC['ie'] !== ''): ?><div>I.E.: <?= $ppH($ppC['ie']) ?></div><?php endif; ?>
      <?php if ($ppC['address'] !== ''): ?><div><?= $ppH($ppC['address']) ?></div><?php endif; ?>
      <?php if ($ppC['city'] !== ''): ?><div><?= $ppH($ppC['city']) ?></div><?php endif; ?>
      <?php if ($ppC['phone'] !== ''): ?><div><?= $ppH($ppC['phone']) ?></div><?php endif; ?>
    </div>
    <div class="pp-num">
      <div class="n"><span>Nº da Proposta/Orçamento :</span><span><?= $ppH($pp['number']) ?></span></div>
      <div class="r"><span>Data da Proposta:</span><span><?= $ppH($pp['date']) ?></span></div>
      <?php if ($pp['validity'] !== ''): ?><div class="r"><span>Validade da Proposta</span><span><?= $ppH($pp['validity']) ?></span></div><?php endif; ?>
    </div>
  </div>

  <div class="pp-bar">Dados de Cliente</div>
  <div class="pp-cli">
    <div class="full"><b>Destinatário:</b> <?= $ppH($ppU['name']) ?></div>
    <div><b>Endereço:</b> <?= $ppH($ppU['address']) ?></div><div><b>Bairro:</b> <?= $ppH($ppU['district']) ?></div>
    <div><b>Cidade:</b> <?= $ppH($ppU['city']) ?><span style="margin-left:8mm"><b style="min-width:6mm">UF</b> <?= $ppH($ppU['uf']) ?></span></div><div><b>Telefone:</b> <?= $ppH($ppU['phone']) ?></div>
    <div><b>CPF/CNPJ:</b> <?= $ppH($ppU['doc']) ?></div><div><b>RG/I.E.:</b> <?= $ppH($ppU['rg_ie']) ?></div>
    <div class="full"><b>A/C:</b></div>
  </div>
  <?php if ($pp['intro'] !== ''): ?><div class="pp-intro"><?= $ppH($pp['intro']) ?></div><?php endif; ?>

  <div class="pp-bar">Dados dos Produtos/Serviços</div>
  <table class="pp-items">
    <thead><tr><th style="width:22mm">Código</th><th class="c" style="width:20mm">Quantidade</th><th class="c" style="width:15mm">Unidade</th><th>Descrição</th><th class="r" style="width:24mm">Vlr Unitário</th><th class="r" style="width:24mm">Total</th></tr></thead>
    <tbody>
    <?php foreach ($pp['items'] as $it): ?>
      <tr><td><?= $ppH($it['code']) ?></td><td class="c"><?= $ppH(orc_proposta_qty($it['qty'])) ?></td><td class="c"><?= $ppH($it['unit']) ?></td><td class="desc"><?= $ppH($it['desc']) ?></td><td class="r"><?= $ppH(orc_proposta_money($it['price'])) ?></td><td class="r"><?= $ppH(orc_proposta_money($it['total'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pp-tot">
    <div class="b"><span>Valor total bruto:</span><span><?= $ppH(orc_proposta_money($ppT['bruto'])) ?></span></div>
    <div class="i"><span>Total de Descontos:</span><span><?= $ppT['desconto'] > 0 ? $ppH(orc_proposta_money($ppT['desconto'])) : '' ?></span></div>
    <div class="i"><span>Total de Acréscimos:</span><span><?= $ppT['acrescimo'] > 0 ? $ppH(orc_proposta_money($ppT['acrescimo'])) : '' ?></span></div>
    <?php if ($ppT['frete'] > 0): ?><div class="i"><span>Frete:</span><span><?= $ppH(orc_proposta_money($ppT['frete'])) ?></span></div><?php endif; ?>
    <div class="b"><span>Valor a pagar:</span><span><?= $ppH(orc_proposta_money($ppT['pagar'])) ?></span></div>
  </div>

  <?php if ($pp['seller'] !== '' || $pp['payments'] || $pp['other'] !== ''): ?>
  <div class="pp-bar sep">Outros dados:</div>
  <div class="pp-other">
    <?php if ($pp['seller'] !== ''): ?><div>Representante: <span class="v"><?= $ppH(mb_strtoupper($pp['seller'], 'UTF-8')) ?></span></div><?php endif; ?>
    <?php foreach ($pp['payments'] as $i => $pay): ?><div><?= $i === 0 ? 'Pagamento: ' : '' ?><span class="v"><?= $ppH($pay) ?></span></div><?php endforeach; ?>
    <?php if ($pp['other'] !== ''): ?><div style="white-space:pre-line"><?= $ppH($pp['other']) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="pp-bar sep">Observações:</div>
  <div class="pp-obs"><?= $ppH($pp['notes']) ?></div>

  <?php if ($pp['terms'] !== ''): ?><div class="pp-terms"><?= $ppH($pp['terms']) ?></div><?php endif; ?>
  <div class="pp-date"><?= $ppH($pp['date']) ?><b>DATA</b></div>
  <div class="pp-sign"><div><?= $ppH($ppC['name']) ?></div><div>Assinatura/Carimbo cliente</div></div>
</main>
<script>function nexaPrintBack(url){try{if(window.opener||window.history.length<=1){window.close();}}catch(e){}setTimeout(function(){window.location.href=url;},250);}(function(){const backUrl=<?= json_encode('orcamentos.php?print_id=' . $id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;document.addEventListener('keydown',function(e){if(e.key!=='Escape'||e.ctrlKey||e.altKey||e.metaKey)return;e.preventDefault();nexaPrintBack(backUrl);});})();</script>
</body></html>
