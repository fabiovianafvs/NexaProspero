<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = os_db();
$companyId = os_company_id();

try {
    os_require_enabled($pdo, $companyId);
    os_ensure_defaults($pdo, $companyId);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Método não permitido.');
    }
    $st = $pdo->prepare("SELECT auto_parts_mode,damage_map_type FROM os_settings WHERE company_id=? LIMIT 1");
    $st->execute(array((int)$companyId));
    $settings = $st->fetch(PDO::FETCH_ASSOC) ?: array();
    if (!os_vehicle_damage_enabled($settings)) throw new RuntimeException('O mapa de avarias / inspeção não está habilitado.');

    $token = strtolower(trim(isset($_POST['token']) ? (string)$_POST['token'] : ''));
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) throw new RuntimeException('Sessão da câmera inválida. Recarregue a O.S. e tente novamente.');
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) throw new RuntimeException('Selecione uma foto da avaria.');
    $file = $_FILES['photo'];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no recebimento da foto. Código: ' . (int)$file['error']);
    if ((int)$file['size'] <= 0 || (int)$file['size'] > 8 * 1024 * 1024) throw new RuntimeException('A foto deve ter no máximo 8 MB.');
    if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Upload da foto inválido.');

    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info[0]) || empty($info[1])) throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
    $width = (int)$info[0]; $height = (int)$info[1];
    if ($width <= 0 || $height <= 0 || ($width * $height) > 30000000) throw new RuntimeException('A resolução da foto é muito alta.');
    $mime = isset($info['mime']) ? strtolower((string)$info['mime']) : '';
    if (!in_array($mime, array('image/jpeg','image/png','image/webp'), true)) throw new RuntimeException('Formato não aceito. Use JPG, PNG ou WEBP.');

    $destDir = os_vehicle_damage_photo_storage_root() . '/tmp/company_' . (int)$companyId . '/' . $token;
    os_vehicle_damage_photo_ensure_dir($destDir);
    $base = bin2hex(random_bytes(16));
    $dest = $destDir . '/' . $base . '.jpg';
    $saved = false;

    // Quando GD estiver disponível, normaliza, redimensiona e comprime para JPG.
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $raw = @file_get_contents($file['tmp_name']);
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($src) {
            // Corrige orientação EXIF de fotos JPEG feitas pelo smartphone.
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file['tmp_name']);
                $orientation = is_array($exif) && isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
                if ($orientation === 3) $src = imagerotate($src, 180, 0);
                elseif ($orientation === 6) $src = imagerotate($src, -90, 0);
                elseif ($orientation === 8) $src = imagerotate($src, 90, 0);
            }
            $sw = imagesx($src); $sh = imagesy($src);
            $max = 1600;
            $scale = min(1, $max / max($sw, $sh));
            $dw = max(1, (int)round($sw * $scale));
            $dh = max(1, (int)round($sh * $scale));
            $dst = imagecreatetruecolor($dw, $dh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
            $saved = @imagejpeg($dst, $dest, 78);
            imagedestroy($dst); imagedestroy($src);
        }
    }

    // Fallback para servidores sem GD: preserva o arquivo original, com limite menor.
    if (!$saved) {
        if ((int)$file['size'] > 4 * 1024 * 1024) throw new RuntimeException('O servidor não possui redimensionamento de imagens ativo; use uma foto de até 4 MB.');
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $dest = $destDir . '/' . $base . '.' . $ext;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Não foi possível armazenar a foto temporária.');
    }
    @chmod($dest, 0660);
    $filename = basename($dest);
    $photoRef = 'tmp:' . $token . ':' . $filename;
    $photoUrl = 'os_damage_photo.php?tmp=1&token=' . rawurlencode($token) . '&file=' . rawurlencode($filename);
    echo json_encode(array('ok'=>true,'photo_ref'=>$photoRef,'photo_url'=>$photoUrl), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(array('ok'=>false,'message'=>$e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
