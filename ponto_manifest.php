<?php
declare(strict_types=1);

// Nexa Ponto: manifesto para instalar o ponto como aplicativo (tela inicial do celular/tablet).
// Não lê dados da empresa: o endereço (subdomínio) já identifica a empresa.
$terminal = (string)($_GET['m'] ?? '') === 'terminal';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');
$start = $terminal ? '/ponto.php' : '/admin/ponto_bater.php';
echo json_encode([
  'id' => $start,
  'name' => $terminal ? 'Nexa Ponto - Terminal' : 'Nexa Ponto',
  'short_name' => $terminal ? 'Terminal Ponto' : 'Bater Ponto',
  'description' => $terminal ? 'Terminal de registro de ponto da empresa.' : 'Registro do próprio ponto pelo celular.',
  'lang' => 'pt-BR',
  'start_url' => $start,
  'scope' => $start,
  'display' => 'standalone',
  'orientation' => $terminal ? 'any' : 'portrait',
  'background_color' => '#eef3fb',
  'theme_color' => '#071b55',
  'icons' => [
    ['src' => '/assets/ponto/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
    ['src' => '/assets/ponto/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ['src' => '/assets/ponto/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
