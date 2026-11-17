<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V7: assinaturas digitais ICP-Brasil (Portaria 671, arts. 86, 88 e 89).
 *
 *  - AFD: assinado pelo DESENVOLVEDOR (certificado em /etc/nexa/ponto_dev.pfx, gravado por
 *    deploy/ponto/configurar_certificado_ponto.sh), padrão CAdES, arquivo .p7s destacado.
 *  - AEJ: assinado pelo EMPREGADOR (certificado A1 ativo da empresa, o mesmo das notas) ou,
 *    se a empresa não tiver, pelo desenvolvedor. CAdES, .p7s destacado.
 *  - Comprovante de Registro de Ponto em PDF: assinatura PAdES do desenvolvedor.
 *  - Atestado Técnico e Termo de Responsabilidade (art. 89): o Nexa gera o PDF no modelo do
 *    gov.br; os responsáveis legal e técnico (pessoas físicas, e-CPF) assinam fora e o PDF
 *    assinado é enviado de volta e fica guardado para a fiscalização.
 *
 * A assinatura usa o "openssl cms -cades" do servidor (acrescenta o atributo
 * signingCertificateV2 do CAdES); sem ele, cai para o openssl_cms_sign do PHP.
 */

require_once __DIR__ . '/ponto_marcacao.php';
if (is_file(__DIR__ . '/nexa_env.php')) {
  require_once __DIR__ . '/nexa_env.php';
  if (function_exists('nexa_load_environment_file')) nexa_load_environment_file('/etc/nexa/ponto.env');
}

/* ------------------------------------------------------------------------- */
/* Dados da plataforma (programa e desenvolvedor)                             */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_plataforma')) {
  /** Dados do programa gravados no servidor (valem para todas as empresas). */
  function ponto_plataforma(): array {
    $g = static fn(string $k): string => trim((string)(getenv($k) ?: ''));
    return ['inpi' => $g('NEXA_PONTO_INPI'), 'versao' => $g('NEXA_PONTO_VERSAO'), 'dev_nome' => $g('NEXA_PONTO_DEV_NOME'),
      'dev_cnpj' => preg_replace('/[^0-9A-Za-z]/', '', $g('NEXA_PONTO_DEV_CNPJ')) ?? '', 'dev_email' => $g('NEXA_PONTO_DEV_EMAIL'),
      'resp_legal' => $g('NEXA_PONTO_RESP_LEGAL'), 'resp_tecnico' => $g('NEXA_PONTO_RESP_TECNICO')];
  }
}

if (!function_exists('ponto_config_rep')) {
  /** Configuração do ponto com os dados do programa: o que a empresa não preencheu vem do servidor. */
  function ponto_config_rep(PDO $pdo, int $companyId): array {
    $cfg = ponto_config($pdo, $companyId);
    $p = ponto_plataforma();
    foreach (['rep_p_registro_inpi' => 'inpi', 'rep_p_versao' => 'versao', 'rep_p_desenvolvedor' => 'dev_nome', 'rep_p_cnpj_desenvolvedor' => 'dev_cnpj', 'rep_p_email_desenvolvedor' => 'dev_email'] as $k => $pk) {
      if (trim((string)($cfg[$k] ?? '')) === '' && $p[$pk] !== '') $cfg[$k] = $p[$pk];
    }
    return $cfg;
  }
}

/* ------------------------------------------------------------------------- */
/* Certificados                                                               */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_pkcs12_legacy')) {
  /**
   * Muitos A1 vêm com criptografia antiga (RC2/3DES) que o OpenSSL 3 do PHP não abre.
   * Tenta pelo "openssl pkcs12 -legacy" do servidor (senha por variável de ambiente, nada em disco
   * além do .pfx temporário, que já é cifrado). Devolve no formato do openssl_pkcs12_read ou null.
   */
  function ponto_pkcs12_legacy(string $pfx, string $senha): ?array {
    if (!function_exists('proc_open')) return null;
    $dir = ponto_tmp_dir();
    try {
      file_put_contents($dir . '/c.pfx', $pfx);
      $env = ['NEXA_P12PASS' => $senha, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
      $p = @proc_open(['openssl', 'pkcs12', '-in', $dir . '/c.pfx', '-legacy', '-nodes', '-passin', 'env:NEXA_P12PASS'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
      if (!is_resource($p)) return null;
      $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
      if (proc_close($p) !== 0) return null;
      if (!preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.+?-----END (?:RSA |EC )?PRIVATE KEY-----/s', $out, $mk)) return null;
      preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $out, $mc);
      $leaf = null; $extra = [];
      foreach ($mc[0] as $c) {
        if ($leaf === null && @openssl_x509_check_private_key($c, $mk[0])) $leaf = $c; else $extra[] = $c;
      }
      return $leaf ? ['cert' => $leaf, 'pkey' => $mk[0], 'extracerts' => $extra] : null;
    } finally {
      ponto_tmp_limpar($dir);
    }
  }
}

if (!function_exists('ponto_cert_ler')) {
  /** Lê um .pfx e devolve cert/chave/cadeia em PEM e os dados do titular. */
  function ponto_cert_ler(string $pfx, string $senha, string $origem): array {
    $certs = [];
    if (!@openssl_pkcs12_read($pfx, $certs, $senha) && !($certs = ponto_pkcs12_legacy($pfx, $senha))) {
      throw new RuntimeException('Não foi possível abrir o certificado digital (' . $origem . '). Confira o arquivo e a senha.');
    }
    $x = openssl_x509_parse($certs['cert']) ?: [];
    $cn = (string)($x['subject']['CN'] ?? '');
    $doc = '';
    if (preg_match('/:(\d{14}|\d{11})$/', $cn, $m)) $doc = $m[1];
    $validTo = (int)($x['validTo_time_t'] ?? 0);
    if ($validTo && $validTo < time()) throw new RuntimeException('O certificado digital (' . $origem . ') venceu em ' . date('d/m/Y', $validTo) . '.');
    return ['cert' => (string)$certs['cert'], 'key' => (string)$certs['pkey'], 'chain' => array_values((array)($certs['extracerts'] ?? [])),
      'titular' => preg_replace('/:\d{11,14}$/', '', $cn) ?: $cn, 'documento' => $doc, 'valido_ate' => $validTo, 'emissor' => (string)($x['issuer']['CN'] ?? ''), 'origem' => $origem];
  }
}

if (!function_exists('ponto_cert_desenvolvedor')) {
  /** Certificado do desenvolvedor (servidor). null = não configurado. Erro de leitura = exceção. */
  function ponto_cert_desenvolvedor(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $file = trim((string)(getenv('NEXA_PONTO_CERT_FILE') ?: '/etc/nexa/ponto_dev.pfx'));
    if (!is_file($file) || !is_readable($file)) return $c = null;
    return $c = ponto_cert_ler((string)file_get_contents($file), (string)(getenv('NEXA_PONTO_CERT_PASSWORD') ?: ''), 'desenvolvedor');
  }
}

if (!function_exists('ponto_cert_empresa')) {
  /** Certificado A1 ativo da empresa (o das notas fiscais). null = não tem. */
  function ponto_cert_empresa(PDO $pdo, int $companyId): ?array {
    try {
      if (!function_exists('fiscal_nfce_active_certificate')) {
        $f = __DIR__ . '/fiscal_operational.php';
        if (!is_file($f)) return null;
        require_once $f;
      }
      $cert = fiscal_nfce_active_certificate($pdo, $companyId);
      $pfx = @file_get_contents((string)$cert['absolute_path']);
      $senha = (string)($cert['password_secret'] ?? '');
      if (!$pfx || $senha === '') return null;
      return ponto_cert_ler($pfx, $senha, 'empresa');
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('ponto_cert_status')) {
  /** Situação dos certificados, para a tela de configurações. */
  function ponto_cert_status(PDO $pdo, int $companyId): array {
    $dev = null; $devErr = null;
    try { $dev = ponto_cert_desenvolvedor(); } catch (Throwable $e) { $devErr = $e->getMessage(); }
    return ['dev' => $dev, 'dev_erro' => $devErr, 'empresa' => ponto_cert_empresa($pdo, $companyId)];
  }
}

/* ------------------------------------------------------------------------- */
/* CAdES (arquivo .p7s destacado)                                             */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_tmp_dir')) {
  function ponto_tmp_dir(): string {
    $d = rtrim(sys_get_temp_dir(), '/') . '/nexa_ponto_' . bin2hex(random_bytes(8));
    if (!@mkdir($d, 0700, true)) throw new RuntimeException('Não foi possível preparar a assinatura (pasta temporária).');
    return $d;
  }
  function ponto_tmp_limpar(string $d): void {
    foreach ((array)glob($d . '/*') as $f) { if (is_file($f)) { @file_put_contents($f, str_repeat("\0", (int)@filesize($f))); @unlink($f); } }
    @rmdir($d);
  }
}

if (!function_exists('ponto_cades_assinar')) {
  /**
   * Assinatura CAdES destacada (DER) de $dados. Devolve [der, metodo]. metodo = 'cades'
   * (openssl cms -cades, com signingCertificateV2) ou 'cms' (PHP, sem esse atributo).
   */
  function ponto_cades_assinar(string $dados, array $signer): array {
    $d = ponto_tmp_dir();
    try {
      file_put_contents($d . '/in.bin', $dados);
      file_put_contents($d . '/signer.pem', $signer['cert'] . "\n" . $signer['key']);
      file_put_contents($d . '/chain.pem', implode("\n", $signer['chain']));
      $bin = is_executable('/usr/bin/openssl') ? '/usr/bin/openssl' : 'openssl';
      if (function_exists('proc_open')) {
        $cmd = [$bin, 'cms', '-sign', '-binary', '-cades', '-md', 'sha256', '-outform', 'DER', '-in', $d . '/in.bin', '-signer', $d . '/signer.pem', '-out', $d . '/out.p7s'];
        if ($signer['chain']) { $cmd[] = '-certfile'; $cmd[] = $d . '/chain.pem'; }
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (is_resource($proc)) {
          fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
          $rc = proc_close($proc);
          if ($rc === 0 && is_file($d . '/out.p7s') && filesize($d . '/out.p7s') > 0) return [(string)file_get_contents($d . '/out.p7s'), 'cades'];
        }
      }
      if (!function_exists('openssl_cms_sign')) throw new RuntimeException('O servidor não tem como assinar (openssl indisponível).');
      $extra = $signer['chain'] ? $d . '/chain.pem' : null;
      $ok = $extra
        ? openssl_cms_sign($d . '/in.bin', $d . '/out.p7s', $signer['cert'], $signer['key'], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, $extra)
        : openssl_cms_sign($d . '/in.bin', $d . '/out.p7s', $signer['cert'], $signer['key'], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
      if (!$ok) throw new RuntimeException('Falha ao assinar: ' . (string)openssl_error_string());
      return [(string)file_get_contents($d . '/out.p7s'), 'cms'];
    } finally {
      ponto_tmp_limpar($d);
    }
  }
}

if (!function_exists('ponto_cades_verificar')) {
  /** Confere uma assinatura .p7s destacada sobre $dados (sem validar a cadeia ICP-Brasil). */
  function ponto_cades_verificar(string $dados, string $p7sDer): bool {
    $d = ponto_tmp_dir();
    try {
      file_put_contents($d . '/in.bin', $dados);
      file_put_contents($d . '/sig.p7s', $p7sDer);
      $proc = @proc_open(['openssl', 'cms', '-verify', '-binary', '-noverify', '-inform', 'DER', '-in', $d . '/sig.p7s', '-content', $d . '/in.bin', '-out', '/dev/null'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
      if (!is_resource($proc)) return false;
      fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
      return proc_close($proc) === 0;
    } finally {
      ponto_tmp_limpar($d);
    }
  }
}

if (!function_exists('ponto_zip')) {
  /** ZIP em memória com os arquivos [nome => conteúdo]. */
  function ponto_zip(array $arquivos): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('O servidor não tem suporte a ZIP (extensão php-zip).');
    $tmp = tempnam(sys_get_temp_dir(), 'pz');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    foreach ($arquivos as $n => $c) $z->addFromString($n, $c);
    $z->close();
    $out = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $out;
  }
}

/* ------------------------------------------------------------------------- */
/* PDF simples (comprovante e atestado), com assinatura PAdES                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_pdf_txt')) {
  function ponto_pdf_txt(string $s): string {
    $s = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8') ?: '';
    return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
  }
}

if (!function_exists('ponto_pdf_quebrar')) {
  /** Quebra o texto em linhas pela largura (estimativa da Helvetica). */
  function ponto_pdf_quebrar(string $txt, float $size, float $largura): array {
    $max = max(10, (int)floor($largura / ($size * 0.5)));
    $out = [];
    foreach (preg_split('/\R/u', $txt) ?: [] as $par) {
      $linha = '';
      foreach (preg_split('/\s+/u', trim($par)) ?: [] as $w) {
        if ($w === '') continue;
        if (mb_strlen($linha . ' ' . $w) > $max && $linha !== '') { $out[] = $linha; $linha = $w; }
        else $linha = $linha === '' ? $w : $linha . ' ' . $w;
      }
      $out[] = $linha;
    }
    return $out;
  }
}

if (!function_exists('ponto_pdf_gerar')) {
  /**
   * Gera um PDF A4 de uma página. $itens: ['t', x, y, size, texto, bold?] (y a partir do topo, em pt),
   * ['l', x1, y1, x2, y2] linha, ['r', x, y, w, h] retângulo cheio. Com $signer, assina em PAdES
   * (SubFilter ETSI.CAdES.detached). $meta: titulo, motivo, local.
   */
  function ponto_pdf_gerar(array $itens, ?array $signer = null, array $meta = []): string {
    $H = 842.0;
    $c = "0 g 0 G\n";
    foreach ($itens as $i) {
      if ($i[0] === 't') $c .= 'BT /' . (!empty($i[5]) ? 'F2' : 'F1') . ' ' . $i[3] . ' Tf ' . round($i[1], 2) . ' ' . round($H - $i[2], 2) . ' Td (' . ponto_pdf_txt((string)$i[4]) . ") Tj ET\n";
      elseif ($i[0] === 'l') $c .= '0.6 w ' . round($i[1], 2) . ' ' . round($H - $i[2], 2) . ' m ' . round($i[3], 2) . ' ' . round($H - $i[4], 2) . " l S\n";
      elseif ($i[0] === 'r') $c .= round($i[1], 3) . ' ' . round($H - $i[2] - $i[4], 3) . ' ' . round($i[3], 3) . ' ' . round($i[4], 3) . " re f\n";
    }
    $assina = $signer !== null;
    $obj = [];
    $obj[1] = '<< /Type /Catalog /Pages 2 0 R' . ($assina ? ' /AcroForm << /Fields [6 0 R] /SigFlags 3 >>' : '') . ' >>';
    $obj[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $obj[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 7 0 R' . ($assina ? ' /Annots [6 0 R]' : '') . ' >>';
    $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $obj[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $obj[7] = "<< /Length " . strlen($c) . " >>\nstream\n" . $c . "\nendstream";
    $info = '<< /Title (' . ponto_pdf_txt((string)($meta['titulo'] ?? 'Documento')) . ') /Producer (Nexa Ponto) /CreationDate (D:' . date('YmdHisO') . ') >>';
    $obj[9] = str_replace('+0000', 'Z', preg_replace('/([+-]\d{2})(\d{2})\)/', "$1'$2')", $info) ?? $info);
    $placeholder = 24000; // bytes da assinatura (hexadecimal = o dobro)
    if ($assina) {
      $obj[6] = '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Assinatura) /V 8 0 R /F 132 /Rect [0 0 0 0] /P 3 0 R >>';
      $m = preg_replace('/([+-]\d{2})(\d{2})$/', "$1'$2'", date('YmdHisO')) ?? date('YmdHis');
      $obj[8] = '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 ********** ********** **********] /Contents <'
        . str_repeat('0', $placeholder * 2) . '> /M (D:' . $m . ') /Name (' . ponto_pdf_txt((string)$signer['titular']) . ') /Reason (' . ponto_pdf_txt((string)($meta['motivo'] ?? 'Documento do Nexa Ponto')) . ')'
        . (!empty($meta['local']) ? ' /Location (' . ponto_pdf_txt((string)$meta['local']) . ')' : '') . ' >>';
    }
    ksort($obj);
    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $off = [];
    foreach ($obj as $n => $body) { $off[$n] = strlen($pdf); $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n"; }
    $max = max(array_keys($obj));
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= $max; $n++) $pdf .= isset($off[$n]) ? sprintf("%010d 00000 n \n", $off[$n]) : "0000000000 65535 f \n";
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 9 0 R /ID [<" . ($id = bin2hex(random_bytes(16))) . "> <" . $id . ">] >>\nstartxref\n" . $xref . "\n%%EOF\n";
    if (!$assina) return $pdf;
    $ini = strpos($pdf, '/Contents <' . str_repeat('0', 20)) + strlen('/Contents ');
    $fim = $ini + 2 + $placeholder * 2;
    $br = sprintf('[0 %010d %010d %010d]', $ini, $fim, strlen($pdf) - $fim);
    $pdf = str_replace('[0 ********** ********** **********]', $br, $pdf);
    [$der] = ponto_cades_assinar(substr($pdf, 0, $ini) . substr($pdf, $fim), $signer);
    if (strlen($der) > $placeholder) throw new RuntimeException('Assinatura maior que o espaço reservado no PDF.');
    return substr($pdf, 0, $ini + 1) . str_pad(strtoupper(bin2hex($der)), $placeholder * 2, '0') . substr($pdf, $fim - 1);
  }
}

if (!function_exists('ponto_pdf_qr')) {
  /** Itens de PDF que desenham um QR Code (módulos como retângulos). */
  function ponto_pdf_qr(string $texto, float $x, float $y, float $tam): array {
    try {
      $autoload = dirname(__DIR__) . '/vendor/autoload.php';
      if (!class_exists('\Com\Tecnick\Barcode\Barcode') && is_file($autoload)) require_once $autoload;
      if (!class_exists('\Com\Tecnick\Barcode\Barcode')) return [];
      $grid = (new \Com\Tecnick\Barcode\Barcode())->getBarcodeObj('QRCODE,M', $texto, -1, -1, 'black', [0, 0, 0, 0])->getGrid('0', '1');
      $rows = array_values(array_filter(explode("\n", $grid), 'strlen'));
      $n = count($rows);
      if (!$n) return [];
      $m = $tam / $n;
      $out = [];
      foreach ($rows as $r => $row) {
        $len = strlen($row);
        for ($col = 0; $col < $len; $col++) if ($row[$col] === '1') $out[] = ['r', $x + $col * $m, $y + $r * $m, $m + 0.02, $m + 0.02];
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Documentos                                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_comprovante_pdf')) {
  /** PDF do comprovante (art. 79), assinado pelo desenvolvedor se o certificado estiver configurado. */
  function ponto_comprovante_pdf(PDO $pdo, int $companyId, array $d, string $url): array {
    $m = $d['m']; $e = $d['estabelecimento']; $c = $d['colaborador']; $cfg = ponto_config_rep($pdo, $companyId);
    $signer = null;
    try { $signer = ponto_cert_desenvolvedor(); } catch (Throwable $ex) { $signer = null; }
    $dh = DateTime::createFromFormat('Y-m-d H:i:s', (string)$m['data_hora']);
    $doc = (string)($e['documento'] ?? '');
    $local = $d['local'] && trim((string)($d['local']['endereco'] ?? '')) !== '' ? (string)$d['local']['endereco'] : $d['endereco_estabelecimento'];
    $extra = trim(implode(' · ', array_filter([!empty($e['caepf']) ? 'CAEPF ' . $e['caepf'] : '', !empty($e['cno']) ? 'CNO ' . $e['cno'] : ''])));
    $linhas = [
      ['NSR', str_pad((string)$m['nsr'], 9, '0', STR_PAD_LEFT)],
      ['Empregador', (string)($e['razao_social'] ?? '')],
      [((string)($e['tipo_documento'] ?? 'CNPJ')) === 'CPF' ? 'CPF' : 'CNPJ', ponto_fmt_doc($doc) . ($extra ? ' · ' . $extra : '')],
      ['Local da prestação do serviço', $local ?: '—'],
      ['Trabalhador', (string)($c['nome'] ?? '')],
      ['CPF do trabalhador', ponto_fmt_doc((string)$m['cpf'])],
      ['Data e horário', ($dh ? $dh->format('d/m/Y H:i') : '') . ' (UTC' . substr((string)$m['fuso'], 0, 3) . ':' . substr((string)$m['fuso'], 3) . ')'],
      ['Registro do programa no INPI', trim((string)$cfg['rep_p_registro_inpi']) !== '' ? (string)$cfg['rep_p_registro_inpi'] : 'Em registro'],
    ];
    $it = [['t', 50, 70, 15, 'Comprovante de Registro de Ponto do Trabalhador', true], ['l', 50, 82, 545, 82]];
    $y = 110;
    foreach ($linhas as [$k, $v]) {
      $it[] = ['t', 50, $y, 9, $k];
      foreach (ponto_pdf_quebrar((string)$v, 11, 330) as $i => $ln) $it[] = ['t', 215, $y + $i * 14, 11, $ln, true];
      $y += 14 * max(1, count(ponto_pdf_quebrar((string)$v, 11, 330))) + 8;
    }
    $it[] = ['t', 50, $y, 9, 'Código hash (SHA-256)'];
    $it[] = ['t', 215, $y, 8.5, substr((string)$m['hash'], 0, 32), true];
    $it[] = ['t', 215, $y + 12, 8.5, substr((string)$m['hash'], 32), true];
    $y += 40;
    $it[] = ['t', 50, $y, 9, 'Assinatura eletrônica'];
    $it[] = ['t', 215, $y, 10, $signer ? 'Assinado digitalmente (ICP-Brasil, PAdES) por ' . $signer['titular'] : 'Assinatura digital pendente (certificado do desenvolvedor não configurado).', true];
    $y += 30;
    $it = array_merge($it, ponto_pdf_qr($url, 50, $y, 110));
    foreach (ponto_pdf_quebrar('Registro gravado pelo Nexa Ponto (REP-P). Este registro não pode ser alterado nem apagado. Leia o QR Code para conferir o comprovante on-line.', 9, 300) as $i => $ln) $it[] = ['t', 180, $y + 14 + $i * 12, 9, $ln];
    $pdf = ponto_pdf_gerar($it, $signer, ['titulo' => 'Comprovante de Registro de Ponto - NSR ' . (int)$m['nsr'], 'motivo' => 'Comprovante de Registro de Ponto do Trabalhador (Portaria MTE 671/2021, art. 79)']);
    return [$pdf, (bool)$signer];
  }
}

if (!function_exists('ponto_attr_pdf')) {
  /** Atestado Técnico e Termo de Responsabilidade (modelo do gov.br, art. 89) para assinar. $tipo: REP-P ou PTRP. */
  function ponto_attr_pdf(PDO $pdo, int $companyId, array $estab, string $tipo): string {
    $cfg = ponto_config_rep($pdo, $companyId);
    $p = ponto_plataforma();
    $na = 'N/A';
    $inpi = trim((string)$cfg['rep_p_registro_inpi']) ?: '(registro no INPI)';
    $campos = [
      ['Tipo do REP/PTRP', $tipo], ['Marca Equipamento', $na], ['Modelo Equipamento', $na], ['Certificado de conformidade', $na], ['Número de fabricação', $na],
      ['Número de registro no INPI', $inpi], ['Certificado de registro de programa de computador no INPI', $inpi],
      ['Identificador do Programa', 'Nexa Ponto (Nexa Próspero)'], ['Versão do Programa', trim((string)$cfg['rep_p_versao']) ?: '1'],
      ['Assinatura Eletrônica', $na], ['Chave pública', $na], ['Algoritmo de criptografia assimétrica', $na], ['Algoritmo de hash', $na],
    ];
    $dev = trim((string)$cfg['rep_p_desenvolvedor']) ?: '(razão social do desenvolvedor)';
    $devDoc = trim((string)$cfg['rep_p_cnpj_desenvolvedor']) ? ponto_fmt_doc((string)$cfg['rep_p_cnpj_desenvolvedor']) : '(CNPJ)';
    $it = [['t', 50, 70, 15, 'ATESTADO TÉCNICO E TERMO DE RESPONSABILIDADE', true], ['l', 50, 82, 545, 82]];
    $y = 105;
    foreach (ponto_pdf_quebrar('Na qualidade de responsável técnico e de responsável legal da empresa ' . $dev . ', CNPJ/CPF nº ' . $devDoc . ', os signatários abaixo, em atenção ao art. 89 da Portaria MTP nº 671/2021, atestam e declaram que o equipamento e/ou programa identificados abaixo estão em conformidade com a Portaria MTP nº 671/2021.', 10.5, 495) as $ln) { $it[] = ['t', 50, $y, 10.5, $ln]; $y += 15; }
    $y += 10;
    foreach ($campos as [$k, $v]) { $it[] = ['t', 50, $y, 10, $k . ':']; $it[] = ['t', 330, $y, 10, $v, true]; $y += 17; }
    $y += 12;
    $it[] = ['t', 50, $y, 11, 'Empresa/Pessoa Destinatária', true]; $y += 18;
    $it[] = ['t', 50, $y, 10, 'Razão Social:']; $it[] = ['t', 150, $y, 10, (string)($estab['razao_social'] ?? ''), true]; $y += 16;
    $it[] = ['t', 50, $y, 10, 'CNPJ/CPF:']; $it[] = ['t', 150, $y, 10, ponto_fmt_doc((string)($estab['documento'] ?? '')), true]; $y += 34;
    foreach (ponto_pdf_quebrar('Os signatários declaram estar cientes das consequências legais, cíveis e criminais decorrentes de falsa declaração, falso atestado e falsidade ideológica. Este documento deve ficar disponível para pronta apresentação à Inspeção do Trabalho.', 9.5, 495) as $ln) { $it[] = ['t', 50, $y, 9.5, $ln]; $y += 13; }
    $y += 50;
    $it[] = ['l', 50, $y, 270, $y]; $it[] = ['l', 325, $y, 545, $y];
    $it[] = ['t', 50, $y + 14, 9, 'Responsável Legal: ' . ($p['resp_legal'] ?: 'Nome e CPF')];
    $it[] = ['t', 325, $y + 14, 9, 'Responsável Técnico: ' . ($p['resp_tecnico'] ?: 'Nome e CPF')];
    $it[] = ['t', 50, $y + 40, 8.5, 'Assinar com certificado digital ICP-Brasil de pessoa física (e-CPF), padrão PAdES (Portaria 671, art. 89, §§ 2º e 3º).'];
    return ponto_pdf_gerar($it, null, ['titulo' => 'Atestado Técnico e Termo de Responsabilidade - ' . $tipo]);
  }
}

if (!function_exists('ponto_pdf_assinado')) {
  /** O PDF tem assinatura digital (dicionário /Sig com /ByteRange e /Contents)? */
  function ponto_pdf_assinado(string $pdf): bool {
    return strncmp($pdf, '%PDF-', 5) === 0 && preg_match('#/ByteRange\s*\[\s*\d+\s+\d+\s+\d+\s+\d+\s*\]#', $pdf) === 1 && preg_match('#/Contents\s*<[0-9A-Fa-f]{200,}#', $pdf) === 1;
  }
}

if (!function_exists('ponto_attr_list')) {
  /** Atestados assinados enviados (guardados fora do site). */
  function ponto_attr_list(PDO $pdo, int $companyId): array {
    $l = config_store_get($pdo, $companyId, 'ponto_attr') ?? [];
    return is_array($l) ? array_values($l) : [];
  }
}

if (!function_exists('ponto_attr_salvar')) {
  function ponto_attr_salvar(PDO $pdo, int $companyId, string $tipo, string $tmp, string $nome): void {
    if (!in_array($tipo, ['REP-P', 'PTRP'], true)) throw new RuntimeException('Tipo inválido.');
    if (!is_file($tmp) || filesize($tmp) > 10 * 1024 * 1024) throw new RuntimeException('Envie o PDF assinado (até 10 MB).');
    $pdf = (string)file_get_contents($tmp);
    if (!ponto_pdf_assinado($pdf)) throw new RuntimeException('O arquivo não é um PDF com assinatura digital. Assine com o e-CPF dos responsáveis (padrão PAdES) e envie de novo.');
    $dir = ponto_storage_root() . '/' . $companyId . '/documentos';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível gravar o documento.');
    $rel = $companyId . '/documentos/attr_' . strtolower(str_replace('-', '', $tipo)) . '_' . bin2hex(random_bytes(8)) . '.pdf';
    if (!@file_put_contents(ponto_storage_root() . '/' . $rel, $pdf)) throw new RuntimeException('Não foi possível gravar o documento.');
    $l = ponto_attr_list($pdo, $companyId);
    $l[] = ['tipo' => $tipo, 'path' => $rel, 'nome' => mb_substr(basename($nome), 0, 120), 'sha256' => hash('sha256', $pdf), 'enviado_em' => date('Y-m-d H:i:s'),
      'por' => function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0];
    config_store_set($pdo, $companyId, 'ponto_attr', $l);
    ponto_audit($pdo, $companyId, 'enviar_atestado_tecnico', 'documentos', null, null, ['tipo' => $tipo, 'sha256' => hash('sha256', $pdf)]);
  }
}

if (!function_exists('ponto_attr_arquivo')) {
  function ponto_attr_arquivo(array $a): ?string {
    $rel = (string)($a['path'] ?? '');
    if (!preg_match('#^\d+/documentos/attr_(repp|ptrp)_[a-f0-9]{16}\.pdf$#', $rel)) return null;
    $f = ponto_storage_root() . '/' . $rel;
    return is_file($f) ? $f : null;
  }
}
