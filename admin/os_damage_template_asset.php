<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';

$pdo = os_db();
$companyId = os_company_id();
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Modelo não encontrado.');
}
$stmt = $pdo->prepare("SELECT image_ref,name FROM os_damage_templates WHERE company_id=? AND id=? LIMIT 1");
$stmt->execute(array((int)$companyId, $id));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Modelo não encontrado.');
}
$path = os_vehicle_damage_template_absolute((string)$row['image_ref'], (int)$companyId);
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
$mime = @mime_content_type($path) ?: 'image/png';
if (!preg_match('~^image/(png|jpeg|webp)$~i', (string)$mime)) $mime = 'image/png';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
