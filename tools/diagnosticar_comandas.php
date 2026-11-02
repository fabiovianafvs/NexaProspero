<?php
declare(strict_types=1);

/**
 * Diagnóstico do módulo Comandas.
 *
 * Uso:
 * php tools/diagnosticar_comandas.php /var/www/nexa ID_EMPRESA
 *
 * Observação:
 * a etapa "Preparação do schema" chama comanda_ensure_schema(), a mesma rotina
 * executada pela página real de Comandas.
 */

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$companyId = max(1, (int)($argv[2] ?? 1));

if (!defined('COMPANY_ID')) {
    define('COMPANY_ID', $companyId);
}

/**
 * Executa uma etapa, mostra o resultado e devolve o valor produzido.
 *
 * @template T
 * @param callable():T $callback
 * @return T
 */
function etapa(string $nome, callable $callback): mixed
{
    echo PHP_EOL;
    echo str_repeat('=', 58) . PHP_EOL;
    echo "ETAPA: {$nome}" . PHP_EOL;
    echo str_repeat('=', 58) . PHP_EOL;

    try {
        $resultado = $callback();

        echo "[OK] {$nome}" . PHP_EOL;

        if ($resultado !== null) {
            if (is_scalar($resultado)) {
                echo (string)$resultado . PHP_EOL;
            } elseif ($resultado instanceof PDO) {
                echo "Conexão PDO preservada para as próximas etapas." . PHP_EOL;
            } else {
                print_r($resultado);
            }
        }

        return $resultado;
    } catch (Throwable $e) {
        echo "[FALHA] {$nome}" . PHP_EOL;
        echo "Classe: " . get_class($e) . PHP_EOL;
        echo "Código: " . $e->getCode() . PHP_EOL;
        echo "Mensagem: " . $e->getMessage() . PHP_EOL;
        echo "Arquivo: " . $e->getFile() . PHP_EOL;
        echo "Linha: " . $e->getLine() . PHP_EOL;

        if ($e instanceof PDOException && !empty($e->errorInfo)) {
            echo "PDO errorInfo:" . PHP_EOL;
            print_r($e->errorInfo);
        }

        echo PHP_EOL;
        echo "Rastreamento:" . PHP_EOL;
        echo $e->getTraceAsString() . PHP_EOL;
        exit(1);
    }
}

$pdo = etapa('Conexão com o banco', function () use ($root): PDO {
    $path = $root . '/config/pdo.php';
    if (!is_file($path)) {
        throw new RuntimeException("Arquivo não encontrado: {$path}");
    }

    $pdo = require $path;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('config/pdo.php não devolveu uma conexão PDO.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Banco: " .
        $pdo->query('SELECT DATABASE()')->fetchColumn() .
        PHP_EOL;

    echo "Servidor: " .
        $pdo->query('SELECT @@hostname')->fetchColumn() .
        PHP_EOL;

    return $pdo;
});

etapa('Carregamento dos módulos', function () use ($root): string {
    $files = [
        '/config/schema_runtime.php',
        '/config/finance_module.php',
        '/config/gestao_module.php',
        '/config/balanca_module.php',
        '/config/comanda_module.php',
        '/config/saas_modules.php',
    ];

    foreach ($files as $relative) {
        $path = $root . $relative;
        if (!is_file($path)) {
            throw new RuntimeException("Dependência ausente: {$path}");
        }
        require_once $path;
    }

    foreach ([
        'saas_company_has_module',
        'comanda_ensure_schema',
        'comanda_settings',
        'comanda_rows',
    ] as $function) {
        if (!function_exists($function)) {
            throw new RuntimeException("Função não carregada: {$function}()");
        }
    }

    return 'Módulos e funções carregados.';
});

etapa(
    'Liberação dos módulos da empresa',
    function () use ($pdo, $companyId): array {
        return [
            'empresa' => $companyId,
            'comandas' => saas_company_has_module(
                $pdo,
                $companyId,
                'comandas'
            ) ? 'LIBERADO' : 'NÃO LIBERADO',
            'gestao' => saas_company_has_module(
                $pdo,
                $companyId,
                'gestao'
            ) ? 'LIBERADO' : 'NÃO LIBERADO',
        ];
    }
);

etapa('Tabelas atuais', function () use ($pdo): array {
    $tabelas = [
        'nexa_schema_versions',
        'comanda_settings',
        'comandas',
        'comanda_items',
        'comanda_printer_groups',
        'comanda_public_orders',
        'comanda_print_jobs',
        'comanda_print_agents',
        'comanda_transfers',
        'finance_people',
        'produtos',
        'pedidos',
    ];

    $st = $pdo->prepare(
        'SELECT COUNT(*)
           FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = ?'
    );

    $resultado = [];
    foreach ($tabelas as $tabela) {
        $st->execute([$tabela]);
        $resultado[$tabela] =
            (int)$st->fetchColumn() > 0 ? 'EXISTE' : 'AUSENTE';
    }

    return $resultado;
});

etapa('Colunas críticas atuais', function () use ($pdo): array {
    $checks = [
        'comanda_settings' => [
            'company_id',
            'show_only_occupied',
            'allow_transfer',
        ],
        'comandas' => [
            'id',
            'company_id',
            'codigo',
            'status',
        ],
        'comanda_items' => [
            'id',
            'company_id',
            'comanda_id',
            'produto_id',
        ],
    ];

    $sql = 'SELECT COUNT(*)
              FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?';

    $st = $pdo->prepare($sql);
    $resultado = [];

    foreach ($checks as $table => $columns) {
        foreach ($columns as $column) {
            $st->execute([$table, $column]);
            $resultado["{$table}.{$column}"] =
                (int)$st->fetchColumn() > 0 ? 'EXISTE' : 'AUSENTE';
        }
    }

    return $resultado;
});

etapa('Versão registrada do schema', function () use ($pdo): mixed {
    $tableExists = (int)$pdo->query(
        "SELECT COUNT(*)
           FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = 'nexa_schema_versions'"
    )->fetchColumn() > 0;

    if (!$tableExists) {
        return 'Tabela nexa_schema_versions ainda não existe.';
    }

    $st = $pdo->prepare(
        'SELECT *
           FROM nexa_schema_versions
          WHERE module_name IN (?, ?)
          ORDER BY module_name'
    );
    $st->execute(['comanda_runtime', 'comandas']);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: 'Sem versão registrada.';
});

etapa('Preparação do schema das comandas', function () use ($pdo): string {
    comanda_ensure_schema($pdo);
    return 'comanda_ensure_schema() executado.';
});

etapa(
    'Configurações da empresa',
    function () use ($pdo, $companyId): array {
        return comanda_settings($pdo, $companyId);
    }
);

etapa(
    'Consulta das comandas',
    function () use ($pdo, $companyId): array {
        $registros = comanda_rows($pdo, $companyId, false);

        return [
            'quantidade' => count($registros),
            'primeiro_registro' => $registros[0] ?? null,
        ];
    }
);

echo PHP_EOL;
echo str_repeat('=', 58) . PHP_EOL;
echo 'DIAGNÓSTICO CONCLUÍDO SEM ERROS' . PHP_EOL;
echo str_repeat('=', 58) . PHP_EOL;
