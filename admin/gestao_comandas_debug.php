<?php
declare(strict_types=1);

/**
 * Captura o erro fatal da página real de Comandas no ambiente Apache.
 *
 * Uso:
 *   /admin/gestao_comandas_debug.php
 *   /admin/gestao_comandas_debug.php?mobile=1
 *
 * Não altera o banco por conta própria. Apenas carrega a página real.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$debugStartedAt = microtime(true);
$debugFinished = false;
$debugReportFile = '/tmp/nexa_comandas_web_error.txt';

function comandas_debug_escape($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function comandas_debug_render(
    string $title,
    string $message,
    string $file = '',
    int $line = 0,
    string $trace = ''
): void {
    global $debugReportFile, $debugStartedAt;

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');

    $elapsed = round((microtime(true) - $debugStartedAt) * 1000, 1);
    $report = [
        'data_hora' => date('Y-m-d H:i:s P'),
        'php_sapi' => PHP_SAPI,
        'php_version' => PHP_VERSION,
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'title' => $title,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'elapsed_ms' => $elapsed,
        'trace' => $trace,
    ];

    @file_put_contents(
        $debugReportFile,
        print_r($report, true),
        LOCK_EX
    );

    echo '<!doctype html><html lang="pt-BR"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Diagnóstico Comandas</title>';
    echo '<style>
      body{font-family:Arial,sans-serif;background:#f4f7fb;color:#15224b;margin:0;padding:24px}
      main{max-width:1100px;margin:auto}
      .card{background:#fff;border:1px solid #d7dfec;border-radius:14px;padding:20px;margin-bottom:16px}
      .bad{background:#fff0ef;border-color:#efaaa3;color:#9f2018}
      h1,h2{margin-top:0}
      pre,code{white-space:pre-wrap;word-break:break-word;background:#f5f7fb;border-radius:8px;padding:10px;display:block}
      table{border-collapse:collapse;width:100%}
      th,td{border-bottom:1px solid #e5eaf2;padding:9px;text-align:left;vertical-align:top}
      th{width:220px}
    </style></head><body><main>';

    echo '<h1>Diagnóstico web das Comandas</h1>';
    echo '<section class="card bad">';
    echo '<h2>' . comandas_debug_escape($title) . '</h2>';
    echo '<p><strong>' . comandas_debug_escape($message) . '</strong></p>';
    echo '</section>';

    echo '<section class="card"><table>';
    echo '<tr><th>PHP SAPI</th><td>' . comandas_debug_escape(PHP_SAPI) . '</td></tr>';
    echo '<tr><th>Versão PHP</th><td>' . comandas_debug_escape(PHP_VERSION) . '</td></tr>';
    echo '<tr><th>Arquivo</th><td>' . comandas_debug_escape($file ?: '-') . '</td></tr>';
    echo '<tr><th>Linha</th><td>' . comandas_debug_escape((string)$line) . '</td></tr>';
    echo '<tr><th>Tempo até a falha</th><td>' . comandas_debug_escape((string)$elapsed) . ' ms</td></tr>';
    echo '<tr><th>Relatório salvo</th><td>' . comandas_debug_escape($debugReportFile) . '</td></tr>';
    echo '</table></section>';

    if ($trace !== '') {
        echo '<section class="card"><h2>Rastreamento</h2><pre>';
        echo comandas_debug_escape($trace);
        echo '</pre></section>';
    }

    echo '<section class="card">';
    echo '<p>Esta página é apenas de diagnóstico e não executou correção automática.</p>';
    echo '</section>';

    echo '</main></body></html>';
}

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

register_shutdown_function(
    static function () use (&$debugFinished): void {
        if ($debugFinished) {
            return;
        }

        $last = error_get_last();
        if (!is_array($last)) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ];

        if (!in_array((int)$last['type'], $fatalTypes, true)) {
            return;
        }

        comandas_debug_render(
            'Erro fatal capturado no encerramento',
            (string)($last['message'] ?? 'Erro fatal sem mensagem.'),
            (string)($last['file'] ?? ''),
            (int)($last['line'] ?? 0)
        );
    }
);

ob_start();

if (!empty($_GET['mobile'])) {
    $_GET['mobile'] = '1';
}

try {
    require __DIR__ . '/gestao_comandas.php';
    $debugFinished = true;
} catch (Throwable $e) {
    $debugFinished = true;

    comandas_debug_render(
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
}
