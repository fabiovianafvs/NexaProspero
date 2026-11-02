<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/accounting_module.php';

// Download de arquivo JA gerado: nao executar CREATE/ALTER TABLE nesta rota.
// O bootstrap mantem a autenticacao e a verificacao de acesso ao modulo.
$cid = (int)COMPANY_ID;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$stream = null;

try {
  if (!$id) {
    http_response_code(400);
    throw new RuntimeException('Informe um arquivo valido para download.');
  }

  // Busca sempre com escopo da empresa e vinculo ao fechamento.
  $file = accounting_get_company_file($pdo, $cid, (int)$id);
  if (!$file) {
    http_response_code(404);
    throw new RuntimeException('Arquivo do fechamento nao encontrado nesta empresa.');
  }

  // A funcao existente exige arquivo real dentro da area privada de fechamentos.
  $path = accounting_file_absolute_path((string)$file['relative_path']);
  $stream = @fopen($path, 'rb');
  if (!is_resource($stream)) {
    http_response_code(503);
    throw new RuntimeException('O arquivo nao pode ser lido no servidor. Verifique as permissoes da pasta.');
  }
  $info = fstat($stream);
  $size = is_array($info) && isset($info['size']) ? (int)$info['size'] : -1;
  if ($size <= 0) {
    http_response_code(503);
    throw new RuntimeException('O arquivo esta vazio ou indisponivel. Verifique o fechamento publicado.');
  }

  $filename = accounting_safe_part((string)$file['file_name'], 'fechamento');
  $mime = trim((string)($file['mime_type'] ?? ''));
  // Evita caracteres de controle na origem do MIME, mantendo o tipo gravado.
  if ($mime === '' || preg_match('/[\r\n]/', $mime)) $mime = 'application/octet-stream';

  // Permite navegar na tela enquanto o arquivo (possivelmente grande) e enviado.
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  while (ob_get_level() > 0) @ob_end_clean();
  @ini_set('zlib.output_compression', '0');
  @set_time_limit(0);

  header('Content-Type: ' . $mime);
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . (string)$size);
  header('Cache-Control: private, no-store, max-age=0');
  header('Pragma: no-cache');
  header('X-Content-Type-Options: nosniff');

  // Envia em blocos para nao carregar o ZIP todo na memoria PHP.
  $bytesSent = 0;
  while (!feof($stream) && !connection_aborted()) {
    $chunk = fread($stream, 1024 * 1024);
    if ($chunk === false || $chunk === '') {
      if (!feof($stream)) error_log('[Nexa][Fechamento] Falha durante leitura do arquivo id=' . (int)$id . ' empresa=' . $cid);
      break;
    }
    $bytesSent += strlen($chunk);
    echo $chunk;
    flush();
  }
  fclose($stream);
  if (!connection_aborted() && $bytesSent !== $size) {
    error_log('[Nexa][Fechamento] Envio incompleto arquivo id=' . (int)$id . ' empresa=' . $cid . ' bytes=' . $bytesSent . '/' . $size);
  }
  exit;
} catch (Throwable $e) {
  if (is_resource($stream)) fclose($stream);
  error_log('[Nexa][Fechamento] Download falhou: empresa=' . $cid . ' arquivo=' . (int)($id ?: 0) . ' erro=' . $e->getMessage());
  if (http_response_code() === 200) http_response_code(404);
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/plain; charset=UTF-8');
  header('Cache-Control: private, no-store');
  echo $e->getMessage();
  exit;
}
