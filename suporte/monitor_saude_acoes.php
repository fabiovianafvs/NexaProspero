<?php
declare(strict_types=1);
// Botões de manutenção do painel Saúde do Sistema.
// O PHP não executa nada como root: só grava um pedido com uma palavra fixa
// ("resolver" ou "reiniciar_vps") para o agente nexa-ops (deploy/ops/).
// Toda ação exige a senha de um usuário master (platform_admin) do Nexa Próspero.
if (!defined('NEXA_HEALTH_OPS_ALLOWED') || NEXA_HEALTH_OPS_ALLOWED !== true) {
    http_response_code(403);
    exit;
}

const NEXA_OPS_DIR = '/var/lib/nexa-ops';
const NEXA_OPS_ACTIONS = [
    'resolver' => 'Resolver sem reiniciar',
    'reiniciar_vps' => 'Reiniciar a VPS',
];

function nexa_ops_available(): bool {
    if (function_exists('nexa_is_homologacao') && nexa_is_homologacao()) return false;
    return is_dir(NEXA_OPS_DIR . '/queue') && is_writable(NEXA_OPS_DIR . '/queue');
}

function nexa_ops_unavailable_reason(): string {
    if (function_exists('nexa_is_homologacao') && nexa_is_homologacao()) return 'Na homologação os botões ficam desligados (a VPS é a mesma da produção).';
    return 'Agente de manutenção não instalado na VPS. Rode: bash /var/www/nexa/deploy/ops/instalar_nexa_ops.sh';
}

/** Confere a senha contra os usuários master ativos (platform_admin). */
function nexa_ops_check_master_password(PDO $pdo, string $password): ?array {
    if ($password === '') return null;
    $st = $pdo->query("SELECT id, name, email, password_hash FROM users WHERE role = 'platform_admin' AND is_active = 1");
    foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $u) {
        if (password_verify($password, (string)$u['password_hash'])) return $u;
    }
    return null;
}

/** Grava o pedido para o agente. Retorna o id do pedido. */
function nexa_ops_request(string $action, string $who): string {
    if (!isset(NEXA_OPS_ACTIONS[$action])) throw new RuntimeException('Ação inválida.');
    $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
    $who = preg_replace('/[^A-Za-z0-9@._ -]/', '', $who) ?? '';
    $tmp = NEXA_OPS_DIR . '/queue/.' . $id . '.tmp';
    if (@file_put_contents($tmp, $action . "\n" . substr($who, 0, 120) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível registrar o pedido para o agente de manutenção.');
    }
    if (!@rename($tmp, NEXA_OPS_DIR . '/queue/' . $id . '.req')) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível registrar o pedido para o agente de manutenção.');
    }
    return $id;
}

/** Últimos relatórios do agente (somente leitura). */
function nexa_ops_recent_results(int $limit = 5): array {
    $files = glob(NEXA_OPS_DIR . '/results/*.txt') ?: [];
    rsort($files, SORT_STRING);
    $out = [];
    foreach (array_slice($files, 0, $limit) as $f) {
        $id = basename($f, '.txt');
        if (!preg_match('/^(\d{14})-[a-f0-9]{16}$/', $id, $m)) continue;
        $when = DateTimeImmutable::createFromFormat('YmdHis', $m[1], new DateTimeZone('UTC'));
        $out[] = [
            'id' => $id,
            'when' => $when ? $when->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s') : $id,
            'text' => (string)@file_get_contents($f, false, null, 0, 20000),
        ];
    }
    return $out;
}

function nexa_ops_pending(): int {
    return count(glob(NEXA_OPS_DIR . '/queue/*.req') ?: []);
}
