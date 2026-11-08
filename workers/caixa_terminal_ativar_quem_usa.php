<?php
declare(strict_types=1);

/**
 * "Ativar Caixa Terminal" para quem já usa caixa hoje.
 *
 * Com a migração 20261108_caixa_terminal_usuario_v1, todo usuário começa SEM
 * Caixa Terminal (vende sem abrir caixa). Este script lista os usuários que
 * abriram caixa nos últimos N dias (padrão 60) para manter o controle deles.
 *
 * Uso (na pasta do sistema):
 *   php workers/caixa_terminal_ativar_quem_usa.php              # só lista
 *   php workers/caixa_terminal_ativar_quem_usa.php --executar   # ativa
 *   ... --dias=30 --empresa=13                                  # filtros opcionais
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';

$execute = in_array('--executar', $argv, true);
$days = 60;
$companyFilter = 0;
foreach ($argv as $a) {
    if (strpos($a, '--dias=') === 0) $days = max(1, (int)substr($a, 7));
    if (strpos($a, '--empresa=') === 0) $companyFilter = (int)substr($a, 10);
}

$hasCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'cash_terminal_enabled'")->fetchColumn() > 0;
if (!$hasCol) exit("Aplique antes 'Caixa - Ativar Caixa Terminal por usuário' no Atualizador do Banco.\n");

$sql = "SELECT u.id, u.company_id, u.name, u.email, c.name AS company_name, COUNT(s.id) AS sessions, MAX(s.opened_at) AS last_opened
          FROM gestao_cash_sessions s
          INNER JOIN users u ON u.id = s.user_id
          LEFT JOIN companies c ON c.id = u.company_id
         WHERE s.opened_at >= (NOW() - INTERVAL " . (int)$days . " DAY)
           AND u.is_active = 1 AND COALESCE(u.cash_terminal_enabled, 0) = 0"
     . ($companyFilter > 0 ? ' AND u.company_id = ' . (int)$companyFilter : '') . "
         GROUP BY u.id, u.company_id, u.name, u.email, c.name
         ORDER BY u.company_id, u.name";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo ($execute ? "MODO EXECUÇÃO" : "MODO SIMULAÇÃO (use --executar para ativar)") . "\n";
echo "Usuários que abriram caixa nos últimos {$days} dias e ainda sem Caixa Terminal: " . count($rows) . "\n\n";
$up = $pdo->prepare('UPDATE users SET cash_terminal_enabled = 1 WHERE id = ?');
foreach ($rows as $r) {
    printf("%s Empresa #%d %s · %s (%s) · %d abertura(s), última em %s\n",
        $execute ? '[ATIVADO]' : '[SERÁ ATIVADO]', (int)$r['company_id'], (string)$r['company_name'],
        (string)$r['name'], (string)$r['email'], (int)$r['sessions'], (string)$r['last_opened']);
    if ($execute) $up->execute([(int)$r['id']]);
}
