<?php
declare(strict_types=1);
/**
 * Navegacao: somente pagina PHP relativa ao diretorio admin, com query string.
 * Nunca aceita URL externa, fragmento, path traversal, barras invertidas ou caracteres de controle.
 * Preserva filtros e contexto F11 sem armazenar URL de outra origem em sessao.
 */
if (!function_exists('nexa_navigation_safe_return')) {
  function nexa_navigation_safe_return(string $candidate, string $fallback): string {
    $fallback = trim($fallback);
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*\.php(?:\?.*)?$/D', $fallback)) $fallback = 'pedidos.php';
    $candidate = trim($candidate);
    if ($candidate === '' || strlen($candidate) > 2048) return $fallback;
    if (preg_match('/[\x00-\x1F\x7F]/', $candidate) || str_contains($candidate, "\\") || str_contains($candidate, '#')) return $fallback;
    // Bloqueia tambem esquemas/barras codificados no primeiro nivel da URL.
    $decoded = rawurldecode($candidate);
    if (preg_match('/[\x00-\x1F\x7F]/', $decoded) || str_contains($decoded, "\\")) return $fallback;
    $parts = parse_url($candidate);
    if (!is_array($parts) || isset($parts['scheme'], $parts['host']) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment'])) return $fallback;
    $path = (string)($parts['path'] ?? '');
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*\.php$/D', $path)) return $fallback;
    return $path . (array_key_exists('query', $parts) ? '?' . (string)$parts['query'] : '');
  }
}
