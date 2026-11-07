<?php
declare(strict_types=1);

/**
 * Andamento real de operações demoradas (finalizar venda, emitir nota).
 *
 * O navegador envia no POST um código aleatório (campo "nexa_progress").
 * Durante o processamento, o servidor grava a etapa atual num arquivo
 * temporário; a tela de espera lê esse arquivo por /api/progresso.php enquanto
 * aguarda, mostrando "Gravando venda", "Transmitindo para a SEFAZ",
 * "Autorizada", etc. Só existe durante a operação; nada fica rodando depois.
 * O arquivo contém apenas o texto da etapa (sem dados de clientes).
 */

if (!function_exists('nexa_progress_token')) {
  function nexa_progress_token(): string {
    static $token = null;
    if ($token !== null) return $token;
    $raw = (string)($_POST['nexa_progress'] ?? '');
    return $token = preg_match('/^[a-f0-9]{32}$/', $raw) ? $raw : '';
  }
}

if (!function_exists('nexa_progress_file')) {
  function nexa_progress_file(string $token): string {
    return rtrim(sys_get_temp_dir(), '/') . '/nexa_progress_' . $token . '.json';
  }
}

if (!function_exists('nexa_progress')) {
  /**
   * Registra a etapa atual. $state: run (em andamento), ok (concluída com sucesso),
   * warn (concluída com aviso). Sem código no POST, não faz nada.
   */
  function nexa_progress(string $text, string $state = 'run'): void {
    $token = nexa_progress_token();
    if ($token === '') return;
    static $steps = [];
    $steps[] = ['text' => mb_substr($text, 0, 160), 'state' => $state];
    $steps = array_slice($steps, -12);
    @file_put_contents(nexa_progress_file($token), json_encode(['steps' => $steps, 'at' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
    // Limpeza ocasional de arquivos antigos (mais de 15 minutos).
    if (random_int(1, 40) === 1) {
      foreach (glob(rtrim(sys_get_temp_dir(), '/') . '/nexa_progress_*.json') ?: [] as $f) {
        if (@filemtime($f) < time() - 900) @unlink($f);
      }
    }
  }
}
