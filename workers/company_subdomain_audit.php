<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Somente CLI.\n");
}

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/company_identity.php';
require_once __DIR__ . '/../config/config_store.php';

$options = getopt('', ['company::', 'name::', 'apply', 'help']);
if (isset($options['help'])) {
  echo "Uso:\n";
  echo "  php company_subdomain_audit.php\n";
  echo "  php company_subdomain_audit.php --company=123\n";
  echo "  php company_subdomain_audit.php --company=123 --name=\"Novo Nome Fantasia\" --apply\n";
  exit(0);
}

$companyId = isset($options['company']) ? (int)$options['company'] : 0;
$apply = array_key_exists('apply', $options);
$nameOverride = trim((string)($options['name'] ?? ''));

function v24_company_invalid_subdomain(array $row): bool {
  $subdomain = strtolower(trim((string)($row['subdomain'] ?? '')));
  $slug = strtolower(trim((string)($row['slug'] ?? '')));
  $subValidation = company_subdomain_validate($subdomain);
  $slugValidation = company_subdomain_validate($slug);
  return !$subValidation['valid']
    || !$slugValidation['valid']
    || company_subdomain_normalize($subdomain) !== $subdomain
    || company_subdomain_normalize($slug) !== $slug
    || $subdomain !== $slug;
}

if ($companyId <= 0) {
  $rows = $pdo->query("SELECT id,name,nome_fantasia,slug,subdomain FROM companies ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $found = 0;
  foreach ($rows as $row) {
    if (!v24_company_invalid_subdomain($row)) continue;
    $found++;
    $source = trim((string)($row['nome_fantasia'] ?? '')) ?: trim((string)$row['name']);
    $candidate = company_subdomain_normalize($source);
    $availability = company_subdomain_availability($pdo, $candidate, (int)$row['id']);
    echo sprintf(
      "#%d | nome=%s | atual=%s | slug=%s | sugestão=%s | %s\n",
      (int)$row['id'],
      $source,
      (string)($row['subdomain'] ?? ''),
      (string)($row['slug'] ?? ''),
      $candidate,
      $availability['available'] ? 'disponível' : $availability['message']
    );
  }
  if ($found === 0) echo "[OK] Nenhum subdomínio inválido encontrado.\n";
  else echo "\nEncontrados: {$found}. Para corrigir, use --company=ID --apply.\n";
  exit(0);
}

$st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$st->execute([$companyId]);
$company = $st->fetch(PDO::FETCH_ASSOC);
if (!$company) {
  fwrite(STDERR, "[ERRO] Empresa não encontrada.\n");
  exit(1);
}

$source = $nameOverride !== ''
  ? $nameOverride
  : (trim((string)($company['nome_fantasia'] ?? '')) ?: trim((string)$company['name']));
$availability = company_subdomain_availability($pdo, $source, $companyId);
$candidate = (string)$availability['subdomain'];

echo "Empresa: #{$companyId} - {$company['name']}\n";
echo "Atual: " . (string)($company['subdomain'] ?? '') . "\n";
echo "Novo: {$candidate}\n";
echo "Status: {$availability['message']}\n";

if (!$availability['available']) exit(1);
if (!$apply) {
  echo "Simulação concluída. Acrescente --apply para gravar.\n";
  exit(0);
}

try {
  config_store_init($pdo);
  $pdo->beginTransaction();
  $pdo->prepare('UPDATE companies SET name = ?, nome_fantasia = ?, slug = ?, subdomain = ? WHERE id = ?')
    ->execute([$source, $source, $candidate, $candidate, $companyId]);

  $business = config_store_get($pdo, $companyId, 'business') ?: [];
  if (!isset($business['brand']) || !is_array($business['brand'])) $business['brand'] = [];
  if (!isset($business['domain']) || !is_array($business['domain'])) $business['domain'] = [];
  $business['brand']['catalog_title'] = $source;
  $business['domain']['subdomain'] = $candidate;
  config_store_set($pdo, $companyId, 'business', $business);
  $pdo->commit();

  echo "[OK] Empresa atualizada para https://{$candidate}.nexaprospero.com.br\n";
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
