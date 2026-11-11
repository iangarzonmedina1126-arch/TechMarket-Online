<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';

function marca_out(int $status, array $data): void {
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function marca_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS techmarket_configuracion (
        clave VARCHAR(80) NOT NULL PRIMARY KEY,
        valor TEXT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function marca_get(PDO $db): array {
    $data = ['company_name'=>'TechMarket Online','logo_url'=>null,'catalog_logo_url'=>null];
    $q = $db->query("SELECT clave, valor FROM techmarket_configuracion WHERE clave IN ('company_name','logo_url','catalog_logo_url')");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['clave'], $data)) $data[$row['clave']] = $row['valor'];
    }
    return $data;
}
function marca_save(PDO $db, array $data): void {
    $q = $db->prepare("INSERT INTO techmarket_configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    foreach (['company_name','logo_url','catalog_logo_url'] as $key) $q->execute([$key, $data[$key] ?? null]);
}
try {
    marca_ensure_schema($conexion);
    // Importa una sola vez la identidad anterior, si aún existe en el almacenamiento local.
    $count = (int)$conexion->query("SELECT COUNT(*) FROM techmarket_configuracion")->fetchColumn();
    $legacyPath = __DIR__ . '/uploads/branding/logo.json';
    if ($count === 0 && is_file($legacyPath)) {
        $legacy = json_decode((string)file_get_contents($legacyPath), true);
        if (is_array($legacy)) {
            marca_save($conexion, [
                'company_name'=>trim((string)($legacy['company_name'] ?? 'TechMarket Online')) ?: 'TechMarket Online',
                'logo_url'=>$legacy['logo_url'] ?? null,
                'catalog_logo_url'=>$legacy['catalog_logo_url'] ?? null
            ]);
        }
    }
    $data = marca_get($conexion);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') marca_out(200, $data);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') marca_out(405, ['error'=>'Método no permitido.']);
    tm_require_roles($conexion, ['Jefe']);
    tm_require_same_origin_for_post();

    $actualizado = false;
    if (isset($_POST['company_name'])) {
        $nombre = trim((string)$_POST['company_name']);
        $longitud = function_exists('mb_strlen') ? mb_strlen($nombre, 'UTF-8') : strlen($nombre);
        if ($nombre === '' || $longitud > 80) marca_out(422, ['error'=>'El nombre debe tener entre 1 y 80 caracteres.']);
        $data['company_name'] = $nombre;
        $actualizado = true;
    }

    $archivoNuevo = null;
    $tipoLogo = null;
    $logoAnterior = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['logo'];
        if ($f['error'] !== UPLOAD_ERR_OK) marca_out(422, ['error'=>'No se pudo recibir la imagen.']);
        if ($f['size'] > 4 * 1024 * 1024) marca_out(422, ['error'=>'La imagen debe pesar máximo 4 MB.']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $extensiones = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        if (!isset($extensiones[$mime])) marca_out(422, ['error'=>'Usa una imagen JPG, PNG, WEBP o GIF.']);
        $tipoLogo = ($_POST['tipo'] ?? 'principal') === 'catalogo' ? 'catalog_logo_url' : 'logo_url';
        $prefijo = $tipoLogo === 'catalog_logo_url' ? 'catalogo-' : 'logo-';
        $nombreArchivo = $prefijo . bin2hex(random_bytes(8)) . '.' . $extensiones[$mime];
        $dir = __DIR__ . '/uploads/branding';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) marca_out(500, ['error'=>'No se pudo acceder a la carpeta de identidad visual.']);
        $destino = $dir . '/' . $nombreArchivo;
        if (!move_uploaded_file($f['tmp_name'], $destino)) marca_out(500, ['error'=>'No se pudo guardar la imagen.']);
        $archivoNuevo = $destino;
        $logoAnterior = $data[$tipoLogo] ?? null;
        $data[$tipoLogo] = 'uploads/branding/' . $nombreArchivo;
        $actualizado = true;
    }
    if (!$actualizado) marca_out(422, ['error'=>'No se recibieron cambios para guardar.']);
    try {
        marca_save($conexion, $data);
    } catch (Throwable $e) {
        if ($archivoNuevo && is_file($archivoNuevo)) @unlink($archivoNuevo);
        throw $e;
    }
    if ($tipoLogo !== null && is_string($logoAnterior) && str_starts_with($logoAnterior, 'uploads/branding/') && basename($logoAnterior) !== basename($data[$tipoLogo])) {
        @unlink(__DIR__ . '/' . $logoAnterior);
    }
    marca_out(200, ['ok'=>true,'company_name'=>$data['company_name'],'logo_url'=>$data['logo_url'],'catalog_logo_url'=>$data['catalog_logo_url'],'message'=>'Identidad de la empresa actualizada correctamente.']);
} catch (Throwable $e) {
    error_log('TechMarket branding: ' . $e->getMessage());
    marca_out(500, ['error'=>'No se pudo consultar o actualizar la identidad de la empresa.']);
}