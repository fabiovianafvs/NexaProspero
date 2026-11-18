<?php
declare(strict_types=1);

// Nexa Ponto - Terminal: tablet/computador da empresa onde os colaboradores batem o ponto com
// matrícula (ou CPF) + PIN, ou com o cartão. O aparelho é ativado uma vez por um link de uso
// único gerado em Ponto Eletrônico > Aparelhos; depois fica identificado por um cookie seguro.
// O login de ninguém fica aberto aqui, e a hora gravada é sempre a do servidor.
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/saas/saas_guard.php';
require_once __DIR__ . '/config/saas_modules.php';
$company = tenant_resolve_company($pdo, (string)($_GET['company'] ?? ''), true);
$companyBlocked = !empty(saas_company_is_blocked($pdo, $company)['blocked']);
$cid = tenant_company_id($company);
require_once __DIR__ . '/config/ponto_marcacao.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
$companyName = trim((string)($company['fantasy_name'] ?? '')) ?: (string)($company['name'] ?? 'Nexa Próspero');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$nowMs = static fn(): int => (int)round(microtime(true) * 1000);

$fail = static function (string $msg, int $code = 400) use ($isPost, $nowMs, $companyName): void {
  if ($isPost) ponto_json_out(['ok' => false, 'erro' => $msg, 'now_ms' => $nowMs()], $code);
  http_response_code($code);
  ponto_terminal_page($companyName, '<div class="pa-msg warn" style="margin-top:18px">' . ponto_h($msg) . '</div>'
    . '<p class="pa-hint">Para registrar o seu próprio ponto pelo celular, entre no Nexa e use <a href="/admin/ponto_bater.php">Bater Ponto</a>.</p>');
  exit;
};

function ponto_json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ponto_terminal_page(string $companyName, string $body, string $script = ''): void {
  ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#071b55">
<meta name="robots" content="noindex">
<title>Terminal de Ponto · <?= ponto_h($companyName) ?></title>
<link rel="manifest" href="/ponto_manifest.php?m=terminal">
<link rel="apple-touch-icon" href="/assets/ponto/apple-touch-icon.png">
<link rel="icon" href="/assets/ponto/icon-192.png">
<link rel="stylesheet" href="/assets/ponto/ponto-app.css?v=2">
</head>
<body>
<div class="tm">
<header class="pa-top"><div><b>Terminal de Ponto</b><small><?= ponto_h($companyName) ?></small></div><span class="sp"></span><span id="tmDev" style="font-size:12px;opacity:.85"></span></header>
<?= $body ?>
</div>
<script src="/assets/ponto/ponto-app.js?v=2"></script>
<?= $script ?>
</body>
</html>
<?php
}

if ($companyBlocked) $fail('O acesso desta empresa ao sistema está suspenso no momento.', 403);
if (!saas_company_has_module($pdo, $cid, 'ponto_eletronico')) $fail('O Ponto Eletrônico não está liberado para esta empresa.', 404);
if (!ponto_ready($pdo) || !ponto_v2_ready($pdo)) $fail('O Ponto Eletrônico ainda não foi instalado no banco.', 503);

// Ativação do aparelho (link de uso único).
if (!$isPost && isset($_GET['ativar'])) {
  try {
    [$c, $token] = ponto_coletor_ativar($pdo, $cid, (string)$_GET['ativar']);
  } catch (RuntimeException $e) {
    $fail($e->getMessage());
  }
  setcookie(PONTO_COLETOR_COOKIE, $token, ['expires' => time() + 5 * 365 * 86400, 'path' => '/', 'secure' => str_starts_with(ponto_base_url(), 'https'), 'httponly' => true, 'samesite' => 'Lax']);
  header('Location: /ponto.php?ativado=1', true, 303);
  exit;
}

$coletor = ponto_coletor_atual($pdo, $cid);
if (!$coletor) $fail('Este aparelho ainda não está ativado como terminal de ponto. O responsável deve abrir Ponto Eletrônico > Aparelhos, gerar o link de ativação e abri-lo neste aparelho.', 403);

// Foto do colaborador na confirmação (só com o tíquete da identificação).
if (!$isPost && isset($_GET['foto'])) {
  try {
    [$colId] = ponto_ticket_check($pdo, $cid, (string)$_GET['foto'], (int)$coletor['id']);
    $col = ponto_colaborador_get($pdo, $cid, $colId);
    $file = $col ? ponto_foto_file($col) : null;
  } catch (RuntimeException $e) {
    $file = null;
  }
  if (!$file) { http_response_code(404); exit; }
  header('Content-Type: image/jpeg');
  readfile($file);
  exit;
}

if ($isPost) {
  try {
    if (!hash_equals(ponto_coletor_form_token($pdo, $cid, $coletor), (string)($_POST['ft'] ?? ''))) throw new RuntimeException('Tela desatualizada. Recarregue a página.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'identificar') {
      [$col, $ident] = ponto_identificar($pdo, $cid, ['login' => (string)($_POST['login'] ?? ''), 'pin' => (string)($_POST['pin'] ?? ''), 'rfid' => (string)($_POST['rfid'] ?? '')], $coletor);
      $sit = ponto_situacao($pdo, $cid, $col);
      $ticket = ponto_ticket($pdo, $cid, (int)$col['id'], (int)$coletor['id'], $ident);
      ponto_json_out(['ok' => true, 'now_ms' => $nowMs(), 'ticket' => $ticket, 'nome' => (string)$col['nome'], 'matricula' => (string)$col['matricula'],
        'foto' => ponto_foto_file($col) ? '/ponto.php?foto=' . rawurlencode($ticket) : null,
        'situacao' => $sit['situacao'], 'situacao_label' => $sit['situacao_label'], 'proximo' => $sit['proximo'],
        'hoje' => array_map(static fn($m) => ['hora' => substr((string)$m['data_hora'], 11, 5), 'tipo' => PONTO_INTENCOES[(string)$m['intencao']] ?? 'Registro'], $sit['marcacoes'])]);
    }
    if ($action === 'registrar') {
      [$colId, $ident] = ponto_ticket_check($pdo, $cid, (string)($_POST['ticket'] ?? ''), (int)$coletor['id']);
      $col = ponto_colaborador_get($pdo, $cid, $colId);
      if (!$col) throw new RuntimeException('Colaborador não encontrado.');
      $fctx = function_exists('ponto_facial_antes_de_marcar') ? ponto_facial_antes_de_marcar($pdo, $cid, $col, $_POST) : null;
      $m = ponto_marcar($pdo, $cid, $col, ['identificacao' => $ident, 'coletor' => $coletor, 'coletor_codigo' => (string)$coletor['identificador_afd'],
        'intencao' => (string)($_POST['intencao'] ?? ''), 'request_uuid' => (string)($_POST['uuid'] ?? ''),
        'latitude' => (string)($_POST['lat'] ?? ''), 'longitude' => (string)($_POST['lng'] ?? ''), 'precisao' => (string)($_POST['acc'] ?? '')]);
      if ($fctx) ponto_facial_depois_de_marcar($pdo, $cid, $m, $fctx);
      $url = ponto_comprovante_url($pdo, $cid, $m);
      ponto_json_out(['ok' => true, 'now_ms' => $nowMs(), 'nsr' => (int)$m['nsr'], 'nome' => (string)$col['nome'], 'facial' => $fctx ? (PONTO_FACIAL_RESULTADOS[$fctx['resultado']] ?? '') : '',
        'hora' => substr((string)$m['data_hora'], 11, 5), 'data' => date('d/m/Y', strtotime((string)$m['data_hora'])),
        'intencao' => PONTO_INTENCOES[(string)$m['intencao']] ?? 'Registro de ponto', 'comprovante' => $url, 'qr' => ponto_qr_svg(ponto_base_url() . $url, 3)]);
    }
    throw new RuntimeException('Ação inválida.');
  } catch (Throwable $e) {
    ponto_json_out(['ok' => false, 'now_ms' => $nowMs(), 'erro' => $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir. Tente de novo.'], 400);
  }
}

$estab = !empty($coletor['estabelecimento_id']) ? ponto_estabelecimento_get($pdo, $cid, (int)$coletor['estabelecimento_id']) : null;
$tz = ponto_tz($estab, $pdo, $cid)->getName();
$cfg = ponto_config($pdo, $cid);
$rfid = !empty($cfg['permitir_rfid']) && !empty($coletor['permitir_rfid']);
$pin = !empty($coletor['permitir_pin']);
$h = 'ponto_h';
ob_start(); ?>
<main class="tm-body">
  <section>
    <div class="pa-card pa-clock">
      <div class="pa-time" id="paTime">--:--:--</div>
      <div class="pa-date" id="paDate"></div>
      <div class="pa-tz"><?= $h($estab ? ($estab['nome_fantasia'] ?: $estab['razao_social']) . ' · ' : '') ?>hora oficial do servidor</div>
    </div>
    <?php if (isset($_GET['ativado'])): ?><div class="pa-msg ok">Aparelho ativado: "<?= $h($coletor['descricao']) ?>". Já pode registrar o ponto.</div><?php endif; ?>
    <div class="pa-card pa-hint" id="tmInfo">
      <?= $pin ? 'Digite sua <b>matrícula</b> (ou CPF) e o seu <b>PIN</b>.' : '' ?><?= $rfid ? ($pin ? ' Ou ' : '') . '<b>aproxime o seu cartão</b> do leitor.' : '' ?>
      Depois confira o seu nome e toque no tipo de registro. O comprovante aparece em seguida (QR Code).
    </div>
  </section>

  <section class="pa-card" id="tmIdent">
    <?php if ($rfid): ?><div class="tm-rfid">💳 Aproxime o cartão a qualquer momento</div><?php endif; ?>
    <?php if ($pin): ?>
      <form id="tmForm" autocomplete="off">
        <label class="tm-label" for="tmLogin">Matrícula ou CPF</label>
        <input class="tm-input" id="tmLogin" inputmode="numeric" autocomplete="off" maxlength="20">
        <label class="tm-label" for="tmPin">PIN</label>
        <input class="tm-input" id="tmPin" type="password" inputmode="numeric" autocomplete="off" maxlength="6">
        <div class="tm-pad" id="tmPad">
          <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?><button type="button" data-k="<?= $n ?>"><?= $n ?></button><?php endforeach; ?>
          <button type="button" class="clr" data-k="C">Limpar</button><button type="button" data-k="0">0</button><button type="submit" class="ok">OK</button>
        </div>
      </form>
    <?php else: ?>
      <p class="pa-hint">Este aparelho aceita só cartão.</p>
    <?php endif; ?>
    <div id="tmErr" class="pa-msg err" hidden style="margin:12px 0 0"></div>
  </section>

  <section class="pa-card" id="tmConf" hidden>
    <div class="pa-who"><span class="pa-ph" id="tmPh"></span><div><b id="tmNome"></b><small id="tmMat"></small><br><span class="pa-badge" id="tmSit"></span></div></div>
    <div class="pa-marks" id="tmHoje" style="margin-top:10px"></div>
    <div style="margin-top:14px">
      <button type="button" class="pa-main" id="tmMain"></button>
      <p class="pa-alts-t">Outro tipo de registro:</p>
      <div class="pa-alts" id="tmAlts"></div>
      <div class="pa-row"><button type="button" class="pa-btn" id="tmCancel">Não sou eu / Cancelar</button></div>
    </div>
  </section>
</main>
<div class="pa-done" id="paDone" hidden>
  <div class="pa-done-box" role="dialog" aria-modal="true">
    <div class="pa-ok">✓</div>
    <h2>Ponto registrado</h2>
    <div style="font-weight:800" id="dNome"></div>
    <div class="t" id="dHora"></div>
    <div style="font-weight:800;color:#334155" id="dTipo"></div>
    <dl><dt>Data</dt><dd id="dData"></dd><dt>NSR</dt><dd id="dNsr"></dd><dt id="dFacT" hidden>Rosto</dt><dd id="dFac" hidden></dd></dl>
    <div class="pa-qr" id="dQr"></div>
    <p class="pa-hint">Aponte a câmera do celular para o QR Code e guarde o seu comprovante.</p>
    <div class="pa-row"><button type="button" class="pa-btn pri" id="dOk">Concluir (<span id="dCount">15</span>)</button></div>
  </div>
</div>
<?php
$body = (string)ob_get_clean();
$labels = json_encode(PONTO_INTENCOES, JSON_UNESCAPED_UNICODE);
$script = '<script>window.TM=' . json_encode(['ft' => ponto_coletor_form_token($pdo, $cid, $coletor), 'tz' => $tz, 'now' => $nowMs(), 'rfid' => $rfid, 'pin' => $pin, 'dev' => (string)$coletor['descricao']], JSON_UNESCAPED_UNICODE) . ';window.TM.labels=' . $labels . ';</script>'
  . (($fcfg = ponto_facial_pagina_cfg($pdo, $cid))['modo'] !== 'DESLIGADO' ? '<script src="/assets/ponto/ponto-face.js?v=1"></script><script>NexaFace.init(' . json_encode($fcfg) . ');</script>' : '')
  . '<script src="/assets/ponto/ponto-terminal.js?v=3"></script>';
ponto_terminal_page($companyName, $body, $script);
