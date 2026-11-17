<?php
declare(strict_types=1);

// Nexa Ponto: Comprovante de Registro de Ponto do Trabalhador (Portaria MTE 671/2021, art. 79).
// Abre sem login pelo link com código (QR Code do terminal ou "Meus registros" do Bater Ponto).
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
$company = tenant_resolve_company($pdo, (string)($_GET['company'] ?? ''), true);
$cid = tenant_company_id($company);
require_once __DIR__ . '/config/ponto_marcacao.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
$d = null;
if (ponto_ready($pdo) && ponto_v2_ready($pdo)) {
  try { $d = ponto_comprovante_dados($pdo, $cid, (string)($_GET['t'] ?? '')); } catch (Throwable $e) { $d = null; }
}
$h = 'ponto_h';
if (!$d) {
  http_response_code(404);
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comprovante</title><p style="font-family:system-ui;padding:24px">Comprovante não encontrado. Confira o link.</p>';
  exit;
}
$m = $d['m']; $e = $d['estabelecimento']; $c = $d['colaborador']; $cfg = $d['config'];
$doc = (string)($e['documento'] ?? '');
$docLabel = ((string)($e['tipo_documento'] ?? 'CNPJ')) === 'CPF' ? 'CPF' : 'CNPJ';
$extra = array_filter(['CAEPF' => (string)($e['caepf'] ?? ''), 'CNO' => (string)($e['cno'] ?? '')]);
$local = $d['local'] && trim((string)($d['local']['endereco'] ?? '')) !== '' ? (string)$d['local']['endereco'] : $d['endereco_estabelecimento'];
$inpi = trim((string)($cfg['rep_p_registro_inpi'] ?? ''));
$dh = DateTime::createFromFormat('Y-m-d H:i:s', (string)$m['data_hora']);
$url = ponto_base_url() . ponto_comprovante_url($pdo, $cid, $m);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Comprovante de Registro de Ponto · NSR <?= (int)$m['nsr'] ?></title>
<link rel="stylesheet" href="/assets/ponto/ponto-app.css?v=1">
<style>
.cp{max-width:620px;margin:16px auto;padding:0 12px}
.cp h1{font-size:19px;margin:0 0 4px;color:#0b1f5b;text-align:center}
.cp .big{text-align:center;font-size:40px;font-weight:900;color:#0b1f5b;font-variant-numeric:tabular-nums;margin:8px 0 0}
.cp .sub{text-align:center;color:#334155;font-weight:700;margin-bottom:12px}
.cp table{width:100%;border-collapse:collapse;font-size:14px}
.cp th{text-align:left;color:#64748b;font-weight:700;padding:7px 8px 7px 0;vertical-align:top;width:38%}
.cp td{padding:7px 0;font-weight:700;overflow-wrap:anywhere;border-bottom:1px solid #eef2f7}
.cp th{border-bottom:1px solid #eef2f7}
.cp .hash{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;font-weight:600;word-break:break-all}
.cp .qr{text-align:center;margin-top:10px}.cp .qr svg{width:130px;height:130px}
</style>
</head>
<body>
<main class="cp">
  <section class="pa-card">
    <h1>Comprovante de Registro de Ponto do Trabalhador</h1>
    <div class="big"><?= $h($dh ? $dh->format('H:i') : '') ?></div>
    <div class="sub"><?= $h($dh ? $dh->format('d/m/Y') : '') ?> · <?= $h(PONTO_INTENCOES[(string)$m['intencao']] ?? 'Registro de ponto') ?></div>
    <table>
      <tr><th>NSR</th><td><?= $h(str_pad((string)$m['nsr'], 9, '0', STR_PAD_LEFT)) ?></td></tr>
      <tr><th>Empregador</th><td><?= $h($e['razao_social'] ?? '') ?></td></tr>
      <tr><th><?= $docLabel ?></th><td><?= $h(ponto_fmt_doc($doc)) ?></td></tr>
      <?php foreach ($extra as $k => $v): ?><tr><th><?= $h($k) ?></th><td><?= $h($v) ?></td></tr><?php endforeach; ?>
      <tr><th>Local da prestação do serviço</th><td><?= $h($local ?: '—') ?></td></tr>
      <tr><th>Trabalhador</th><td><?= $h($c['nome'] ?? '') ?></td></tr>
      <tr><th>CPF</th><td><?= $h(ponto_fmt_doc((string)$m['cpf'])) ?></td></tr>
      <tr><th>Data e horário</th><td><?= $h($dh ? $dh->format('d/m/Y H:i') : '') ?> (UTC<?= $h(substr((string)$m['fuso'], 0, 3) . ':' . substr((string)$m['fuso'], 3)) ?>)</td></tr>
      <tr><th>Registro do programa (INPI)</th><td><?= $h($inpi !== '' ? $inpi : 'Em registro') ?></td></tr>
      <tr><th>Código hash (SHA-256)</th><td class="hash"><?= $h($m['hash']) ?></td></tr>
    </table>
    <?php $qr = ponto_qr_svg($url, 3); if ($qr !== ''): ?><div class="qr"><?= $qr ?></div><?php endif; ?>
    <p class="pa-hint" style="text-align:center">Registro gravado pelo Nexa Ponto. Este registro não pode ser alterado nem apagado.</p>
    <div class="pa-row pa-noprint"><button type="button" class="pa-btn pri" onclick="window.print()">Imprimir / salvar PDF</button></div>
  </section>
</main>
</body>
</html>
