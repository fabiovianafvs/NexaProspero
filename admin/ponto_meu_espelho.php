<?php
declare(strict_types=1);

// Meu Ponto > Espelho de Ponto do próprio colaborador (art. 84, parágrafo único: o trabalhador
// tem acesso ao espelho mensal). Basta o usuário estar ligado a um colaborador.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_fechamento.php';
require_once __DIR__ . '/_ponto_espelho_render.php';

$cid = (int)COMPANY_ID;
if (!ponto_ready($pdo) || !ponto_v4_ready($pdo)) exit('O Ponto Eletrônico ainda não foi instalado.');
$col = ponto_colaborador_do_usuario($pdo, $cid, (int)(auth_user_id() ?? 0));
if (!$col) exit('Seu usuário não está ligado a um colaborador do ponto.');
[$de, $ate, $compLabel, $comp] = ponto_competencia((string)($_GET['c'] ?? date('Y-m', strtotime(date('Y-m-01') . ' -1 month'))));
$lista = [ponto_espelho_dados($pdo, $cid, (int)$col['id'], $de, $ate)];
ob_start(); ?>
<a href="ponto_meu_ponto.php?c=<?= ponto_h($comp) ?>">← Meu Ponto</a>
<form method="get"><input type="month" name="c" value="<?= ponto_h($comp) ?>" max="<?= date('Y-m') ?>" onchange="this.form.submit()"></form>
<?php
ponto_espelho_render('Meu Espelho de Ponto · ' . $compLabel, $lista, (string)ob_get_clean(), $compLabel);
