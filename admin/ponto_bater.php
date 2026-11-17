<?php
declare(strict_types=1);

// Ponto Eletrônico > Bater Ponto: o colaborador registra o próprio ponto (celular ou computador),
// com o login do Nexa. Basta o usuário estar ligado a um colaborador no cadastro do Ponto.
// O login NUNCA registra ponto: só o toque em "Registrar". A hora gravada é a do servidor.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_marcacao.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['ponto_csrf'])) $_SESSION['ponto_csrf'] = bin2hex(random_bytes(24));
header('Cache-Control: no-store');

$ready = ponto_ready($pdo) && ponto_v2_ready($pdo);
$col = $ready ? ponto_colaborador_do_usuario($pdo, $cid, $uid) : null;

// Foto do próprio colaborador.
if (isset($_GET['foto'])) {
  $file = $col ? ponto_foto_file($col) : null;
  if (!$file) { http_response_code(404); exit; }
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: image/jpeg');
  header('Cache-Control: private, max-age=3600');
  readfile($file);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    ponto_csrf_check();
    if (!$ready) throw new RuntimeException('O ponto ainda não foi instalado no banco.');
    if (!$col) throw new RuntimeException('Seu usuário não está ligado a um colaborador do ponto.');
    [$pode, $motivo, $exige] = ponto_pessoal_regras($pdo, $cid, $col);
    if (!$pode) throw new RuntimeException($motivo);
    $lat = trim((string)($_POST['lat'] ?? ''));
    if ($exige && $lat === '') throw new RuntimeException('Para registrar o ponto é preciso permitir a localização neste aparelho.');
    $m = ponto_marcar($pdo, $cid, $col, [
      'identificacao' => 'SESSAO', 'coletor_codigo' => '02', 'usuario_id' => $uid,
      'intencao' => (string)($_POST['intencao'] ?? ''), 'request_uuid' => (string)($_POST['uuid'] ?? ''),
      'latitude' => $lat, 'longitude' => (string)($_POST['lng'] ?? ''), 'precisao' => (string)($_POST['acc'] ?? ''),
    ]);
    [$gl] = ponto_geofence_label((string)$m['geofence']);
    ponto_json(['ok' => true, 'now_ms' => (int)round(microtime(true) * 1000), 'nsr' => (int)$m['nsr'],
      'hora' => substr((string)$m['data_hora'], 11, 5), 'data' => date('d/m/Y', strtotime((string)$m['data_hora'])),
      'intencao' => PONTO_INTENCOES[(string)$m['intencao']] ?? 'Registro de ponto', 'geofence' => (string)$m['geofence'], 'geofence_label' => $gl,
      'distancia' => $m['distancia_m'] !== null ? (int)$m['distancia_m'] : null, 'repetida' => !empty($m['repetida']),
      'comprovante' => ponto_comprovante_url($pdo, $cid, $m)]);
  } catch (Throwable $e) {
    ponto_json(['ok' => false, 'now_ms' => (int)round(microtime(true) * 1000), 'erro' => $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível registrar o ponto. Tente de novo.'], 400);
  }
}

$companyName = trim((string)($company['fantasy_name'] ?? '')) ?: (string)($company['name'] ?? 'Nexa Próspero');
$erro = null; $sit = null; $hist = []; $regras = [false, '', false];
if (!$ready) $erro = 'O Ponto Eletrônico ainda não foi instalado no banco (Atualizador do Banco: "Ponto Eletrônico - V2 Marcação de ponto").';
elseif (!$col) $erro = 'Seu usuário ainda não está ligado a um colaborador do ponto. Peça ao responsável para abrir Ponto Eletrônico > Colaboradores e escolher o seu usuário no campo "Usuário do Nexa".';
else {
  try {
    ponto_colaborador_apto($col);
    $regras = ponto_pessoal_regras($pdo, $cid, $col);
    if (!$regras[0]) $erro = $regras[1];
  } catch (RuntimeException $e) {
    $erro = $e->getMessage();
  }
  $sit = ponto_situacao($pdo, $cid, $col);
  $hist = ponto_marcacoes_list($pdo, $cid, ['colaborador_id' => (int)$col['id'], 'de' => date('Y-m-d', strtotime('-30 days'))], 300);
}
$tz = $sit ? $sit['agora']->getTimezone()->getName() : (string)(ponto_config($pdo, $cid)['timezone'] ?? 'America/Sao_Paulo');
$tzLabel = ['America/Sao_Paulo' => 'Horário de Brasília', 'America/Manaus' => 'Horário do Amazonas', 'America/Cuiaba' => 'Horário de Mato Grosso', 'America/Porto_Velho' => 'Horário de Rondônia', 'America/Rio_Branco' => 'Horário do Acre', 'America/Noronha' => 'Horário de Fernando de Noronha', 'America/Boa_Vista' => 'Horário de Roraima'][$tz] ?? $tz;
$h = 'ponto_h';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#071b55">
<meta name="robots" content="noindex">
<title>Bater Ponto · <?= $h($companyName) ?></title>
<link rel="manifest" href="/ponto_manifest.php?m=pessoal">
<link rel="apple-touch-icon" href="/assets/ponto/apple-touch-icon.png">
<link rel="icon" href="/assets/ponto/icon-192.png">
<link rel="stylesheet" href="/assets/ponto/ponto-app.css?v=1">
</head>
<body>
<header class="pa-top">
  <div><b>Bater Ponto</b><small><?= $h($companyName) ?></small></div>
  <span class="sp"></span>
  <a href="index.php">Voltar ao sistema</a>
</header>
<main class="pa-wrap">
  <section class="pa-card pa-clock">
    <div class="pa-time" id="paTime">--:--:--</div>
    <div class="pa-date" id="paDate"></div>
    <div class="pa-tz"><?= $h($tzLabel) ?> · hora oficial do servidor</div>
  </section>

  <?php if ($erro): ?>
    <div class="pa-msg <?= $col ? 'err' : 'warn' ?>"><?= $h($erro) ?></div>
  <?php endif; ?>

  <?php if ($col && $sit): ?>
  <section class="pa-card">
    <div class="pa-who">
      <?php if (ponto_foto_file($col)): ?><img class="pa-ph" src="ponto_bater.php?foto=1&v=<?= $h(substr((string)$col['foto_hash'], 0, 10)) ?>" alt=""><?php else: ?><span class="pa-ph"><?= $h(mb_strtoupper(mb_substr((string)$col['nome'], 0, 1))) ?></span><?php endif; ?>
      <div style="min-width:0">
        <b><?= $h($col['nome']) ?></b>
        <small>Matrícula <?= $h($col['matricula']) ?><?= $sit['estabelecimento'] ? ' · ' . $h($sit['estabelecimento']['nome_fantasia'] ?: $sit['estabelecimento']['razao_social']) : '' ?></small><br>
        <span class="pa-badge <?= ['TRABALHANDO' => 'g', 'INTERVALO' => 'y', 'ENCERROU' => 'n', 'NAO_INICIOU' => ''][$sit['situacao']] ?>"><?= $h($sit['situacao_label']) ?></span>
      </div>
    </div>
    <?php if (!$erro): ?>
      <div style="margin-top:14px">
        <button type="button" class="pa-main" id="paMain" data-int="<?= $h($sit['proximo']) ?>">Registrar <?= $h(mb_strtolower($sit['proximo_label'])) ?><small>toque para registrar o ponto agora</small></button>
        <p class="pa-alts-t">Outro tipo de registro:</p>
        <div class="pa-alts">
          <?php foreach (PONTO_INTENCOES as $k => $label): if ($k === $sit['proximo']) continue; ?>
            <button type="button" class="pa-alt" data-int="<?= $h($k) ?>"><?= $h($label) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="pa-geo" id="paGeo"><span>📍</span><span id="paGeoTxt">Obtendo localização…</span><button type="button" id="paGeoBtn" hidden>Tentar de novo</button></div>
        <?php if ($regras[2]): ?><p class="pa-hint" style="margin:6px 0 0">A empresa exige a localização para registrar o ponto.</p><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="pa-card">
    <h2 class="pa-h2">Hoje<?= $sit['previsto']['trabalha'] ? ' · previsto ' . $h(implode('  ', array_map(static fn($q) => $q['ini'] . '–' . $q['fim'], $sit['previsto']['periodos']))) : ($sit['previsto']['feriado'] ? ' · ' . $h($sit['previsto']['feriado']) : '') ?></h2>
    <?php if (!$sit['marcacoes']): ?><p class="pa-empty">Nenhum registro hoje.</p><?php else: ?>
      <div class="pa-marks">
        <?php foreach ($sit['marcacoes'] as $mk): ?>
          <div class="pa-mark"><b><?= $h(substr((string)$mk['data_hora'], 11, 5)) ?></b><small><?= $h(PONTO_INTENCOES[(string)$mk['intencao']] ?? 'Registro') ?></small></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="pa-card">
    <h2 class="pa-h2">Meus registros (últimos 30 dias)</h2>
    <p class="pa-hint" style="margin:-4px 0 8px">Toque no horário para abrir o comprovante.</p>
    <?php if (!$hist): ?><p class="pa-empty">Nenhum registro.</p><?php else:
      $byDay = [];
      foreach (array_reverse($hist) as $mk) $byDay[(string)$mk['data']][] = $mk;
      krsort($byDay);
      foreach ($byDay as $d => $list): ?>
        <div class="pa-day"><span><?= $h(date('d/m', strtotime($d))) ?> <?= $h(['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][(int)date('w', strtotime($d))]) ?></span>
          <?php foreach ($list as $mk): ?><a href="<?= $h(ponto_comprovante_url($pdo, $cid, $mk)) ?>" target="_blank" rel="noopener" title="NSR <?= (int)$mk['nsr'] ?>"><?= $h(substr((string)$mk['data_hora'], 11, 5)) ?></a><?php endforeach; ?>
        </div>
      <?php endforeach; endif; ?>
  </section>
  <?php endif; ?>
  <p class="pa-hint" style="text-align:center">Dica: no celular, use "Adicionar à tela inicial" para abrir o ponto como um aplicativo.</p>
</main>

<div class="pa-done" id="paDone" hidden>
  <div class="pa-done-box" role="dialog" aria-modal="true" aria-labelledby="paDoneT">
    <div class="pa-ok">✓</div>
    <h2 id="paDoneT">Ponto registrado</h2>
    <div class="t" id="paDoneHora"></div>
    <div id="paDoneTipo" style="font-weight:800;color:#334155"></div>
    <dl><dt>Data</dt><dd id="paDoneData"></dd><dt>NSR</dt><dd id="paDoneNsr"></dd><dt>Localização</dt><dd id="paDoneGeo"></dd></dl>
    <div class="pa-row"><a class="pa-btn" id="paDoneComp" href="#" target="_blank" rel="noopener">Ver comprovante</a><button type="button" class="pa-btn pri" id="paDoneOk">OK</button></div>
  </div>
</div>

<script src="/assets/ponto/ponto-app.js?v=1"></script>
<script>
(function(){
  var P = window.NexaPonto;
  P.sync(<?= (int)round(microtime(true) * 1000) ?>);
  P.startClock(document.getElementById('paTime'), document.getElementById('paDate'), <?= json_encode($tz) ?>);
  var main = document.getElementById('paMain');
  if (!main) return;
  var csrf = <?= json_encode(ponto_csrf()) ?>, exige = <?= $regras[2] ? 'true' : 'false' ?>, uuid = P.uuid(), busy = false;
  var geoBox = document.getElementById('paGeo'), geoTxt = document.getElementById('paGeoTxt'), geoBtn = document.getElementById('paGeoBtn');
  function showGeo(g){ geoTxt.textContent = g.msg; geoBox.className = 'pa-geo ' + (g.status === 'ok' ? 'ok' : (g.status === 'pendente' ? '' : 'bad')); geoBtn.hidden = g.status !== 'indisponivel'; }
  P.locate(showGeo);
  geoBtn.addEventListener('click', function(){ P.locate(showGeo, true); });
  function buttons(on){ document.querySelectorAll('[data-int]').forEach(function(b){ b.disabled = !on; }); }
  function registrar(intencao){
    if (busy) return;
    var g = P.geo;
    if (exige && g.status !== 'ok') {
      if (g.status === 'pendente') { geoTxt.textContent = 'Aguarde a localização…'; return; }
      alert('A empresa exige a localização para registrar o ponto. ' + g.msg); return;
    }
    busy = true; buttons(false); main.firstChild.textContent = 'Registrando…';
    P.post('ponto_bater.php', { csrf: csrf, intencao: intencao, uuid: uuid, lat: g.lat, lng: g.lng, acc: g.acc }).then(function(r){
      busy = false;
      if (!r || !r.ok) { buttons(true); main.firstChild.textContent = 'Registrar de novo'; P.beep(false); alert((r && r.erro) || 'Não foi possível registrar.'); return; }
      uuid = P.uuid(); P.beep(true);
      document.getElementById('paDoneHora').textContent = r.hora;
      document.getElementById('paDoneTipo').textContent = r.intencao;
      document.getElementById('paDoneData').textContent = r.data;
      document.getElementById('paDoneNsr').textContent = r.nsr;
      document.getElementById('paDoneGeo').textContent = r.geofence_label + (r.distancia !== null && r.geofence !== 'DENTRO_DA_AREA' ? ' (' + r.distancia + ' m do local)' : '');
      document.getElementById('paDoneComp').href = r.comprovante;
      document.getElementById('paDone').hidden = false;
      document.getElementById('paDoneOk').focus();
    });
  }
  document.querySelectorAll('[data-int]').forEach(function(b){ b.addEventListener('click', function(){ registrar(b.dataset.int); }); });
  document.getElementById('paDoneOk').addEventListener('click', function(){ location.reload(); });
})();
</script>
</body>
</html>
