<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';

$pdo = os_db();
$companyId = os_company_id();
os_require_enabled($pdo, $companyId);
os_ensure_defaults($pdo, $companyId);

$path = '';
if (!empty($_GET['tmp'])) {
    $token = strtolower(trim(isset($_GET['token']) ? (string)$_GET['token'] : ''));
    $file = strtolower(trim(isset($_GET['file']) ? (string)$_GET['file'] : ''));
    $path = os_vehicle_damage_tmp_photo_absolute($companyId, $token, $file);
} else {
    $pointId = (int)(isset($_GET['point_id']) ? $_GET['point_id'] : 0);
    if ($pointId > 0) {
        $st = $pdo->prepare("SELECT order_id,photo_path FROM os_vehicle_damage_points WHERE company_id=? AND id=? LIMIT 1");
        $st->execute(array((int)$companyId,$pointId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['photo_path'])) $path = os_vehicle_damage_photo_absolute($row['photo_path'], $companyId, (int)$row['order_id']);
    }
}

if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('Foto não encontrada.');
}
$finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
$mime = $finfo ? @finfo_file($finfo, $path) : '';
if ($finfo) @finfo_close($finfo);
if (!in_array($mime, array('image/jpeg','image/png','image/webp'), true)) $mime = 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="avaria-' . basename($path) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
