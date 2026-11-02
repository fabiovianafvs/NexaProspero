<?php
declare(strict_types=1);

/**
 * Identificação visual do ambiente de HOMOLOGAÇÃO.
 *
 * O site de homologação (deploy/homolog/criar_homologacao.sh) define
 * "SetEnv NEXA_AMBIENTE homologacao" no Apache. Somente nesse caso as páginas
 * HTML ganham uma faixa no topo e o prefixo [HOMOLOG] no título da aba.
 *
 * Em produção a variável não existe: nenhuma função abaixo altera a saída.
 */

if (!function_exists('nexa_is_homologacao')) {
  function nexa_is_homologacao(): bool {
    return strtolower(trim((string)(getenv('NEXA_AMBIENTE') ?: ''))) === 'homologacao';
  }
}

if (!function_exists('nexa_homologacao_banner_inject')) {
  function nexa_homologacao_banner_inject(string $buffer, int $phase = 0): string {
    static $done = false;
    if ($done || $buffer === '' || stripos($buffer, '<body') === false) return $buffer;

    // Só altera respostas HTML (não mexe em JSON, PDF, XML, ZIP etc.).
    foreach (headers_list() as $header) {
      if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) return $buffer;
    }

    $banner = '<div data-nexa-homolog="1" style="position:fixed;top:0;left:0;right:0;z-index:2147483647;'
      . 'height:22px;line-height:22px;text-align:center;pointer-events:none;'
      . 'font:800 12px/22px Arial,Helvetica,sans-serif;letter-spacing:.06em;color:#1f1300;'
      . 'background:repeating-linear-gradient(135deg,#facc15 0 18px,#f59e0b 18px 36px);'
      . 'box-shadow:0 2px 8px rgba(0,0,0,.25);opacity:.95">'
      . '&#9888; AMBIENTE DE HOMOLOGA&Ccedil;&Atilde;O &middot; dados de teste, sem efeito na produ&ccedil;&atilde;o'
      . '</div>'
      . '<script>try{if(document.title.indexOf("[HOMOLOG]")!==0)document.title="[HOMOLOG] "+document.title;}catch(e){}</script>';

    $result = preg_replace_callback('/<body\b[^>]*>/i', static function (array $m) use ($banner): string {
      return $m[0] . $banner;
    }, $buffer, 1);

    if (!is_string($result)) return $buffer;
    $done = true;
    return $result;
  }
}

if (!function_exists('nexa_homologacao_banner_start')) {
  function nexa_homologacao_banner_start(): void {
    static $started = false;
    if ($started || PHP_SAPI === 'cli' || !nexa_is_homologacao()) return;
    $started = true;
    ob_start('nexa_homologacao_banner_inject');
  }
}
