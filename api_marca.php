<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';

function marca_out(int $status, array $data): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function marca_guardar(string $archivo, array $data): bool {
    $temporal = $archivo . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return false;
    }

    if (file_put_contents($temporal, $json, LOCK_EX) === false) {
        return false;
    }

    if (!rename($temporal, $archivo)) {
        @unlink($temporal);
        return false;
    }

    return true;
}

$dir = __DIR__ . '/uploads/branding';
$meta = $dir . '/logo.json';

if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    marca_out(500, ['error' => 'No se pudo acceder a la carpeta de identidad visual.']);
}

$data = [];

if (is_file($meta)) {
    $raw = json_decode((string) file_get_contents($meta), true);

    if (is_array($raw)) {
        $data = $raw;
    }
}

$data['company_name'] = trim((string) ($data['company_name'] ?? 'TechMarket Online'));

$data['logo_url'] = $data['logo_url'] ?? null;
$data['catalog_logo_url'] = $data['catalog_logo_url'] ?? null;

/* CONSULTAR IDENTIDAD */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    marca_out(200, [
        'company_name' => $data['company_name'],
        'logo_url' => $data['logo_url'],
        'catalog_logo_url' => $data['catalog_logo_url']
    ]);
}

/* ACTUALIZAR IDENTIDAD */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    marca_out(405, ['error' => 'Método no permitido.']);
}

try {
    tm_require_roles($conexion, ['Jefe']);
    tm_require_same_origin_for_post();

    $actualizado = false;

    /* GUARDAR NOMBRE DE LA EMPRESA */
    if (isset($_POST['company_name'])) {
        $nombre = trim((string) $_POST['company_name']);
        $longitud = function_exists('mb_strlen')
            ? mb_strlen($nombre, 'UTF-8')
            : strlen($nombre);

        if ($nombre === '' || $longitud > 80) {
            marca_out(422, [
                'error' => 'El nombre debe tener entre 1 y 80 caracteres.'
            ]);
        }

        $data['company_name'] = $nombre;
        $actualizado = true;
    }

    /* SUBIR LOGO */
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['logo'];

        if ($f['error'] !== UPLOAD_ERR_OK) {
            marca_out(422, ['error' => 'No se pudo recibir la imagen.']);
        }

        if ($f['size'] > 4 * 1024 * 1024) {
            marca_out(422, ['error' => 'La imagen debe pesar máximo 4 MB.']);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);

        $extensiones = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];

        if (!isset($extensiones[$mime])) {
            marca_out(422, [
                'error' => 'Usa una imagen JPG, PNG, WEBP o GIF.'
            ]);
        }

        $tipo = ($_POST['tipo'] ?? 'principal') === 'catalogo'
            ? 'catalog_logo_url'
            : 'logo_url';

        $prefijo = $tipo === 'catalog_logo_url' ? 'catalogo-' : 'logo-';

        $nombreArchivo = $prefijo
            . bin2hex(random_bytes(8))
            . '.'
            . $extensiones[$mime];

        $destino = $dir . '/' . $nombreArchivo;

        if (!move_uploaded_file($f['tmp_name'], $destino)) {
            marca_out(500, ['error' => 'No se pudo guardar la imagen.']);
        }

        $anterior = $data[$tipo] ?? null;
        $data[$tipo] = 'uploads/branding/' . $nombreArchivo;

        $actualizado = true;

        /* Guardar primero la configuración y luego eliminar el logo anterior. */
        if (!marca_guardar($meta, $data)) {
            @unlink($destino);
            marca_out(500, [
                'error' => 'No se pudo guardar la configuración del logo.'
            ]);
        }

        if (
            is_string($anterior) &&
            str_starts_with($anterior, 'uploads/branding/') &&
            basename($anterior) !== basename($data[$tipo])
        ) {
            @unlink(__DIR__ . '/' . $anterior);
        }
    }

    if (!$actualizado) {
        marca_out(422, ['error' => 'No se recibieron cambios para guardar.']);
    }

    /* Si solo se cambió el nombre, también se guarda aquí. */
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) {
        if (!marca_guardar($meta, $data)) {
            marca_out(500, ['error' => 'No se pudo guardar el nombre de la empresa.']);
        }
    }

    marca_out(200, [
        'ok' => true,
        'company_name' => $data['company_name'],
        'logo_url' => $data['logo_url'],
        'catalog_logo_url' => $data['catalog_logo_url'],
        'message' => 'Identidad de la empresa actualizada correctamente.'
    ]);

} catch (Throwable $e) {
    error_log('TechMarket branding: ' . $e->getMessage());

    marca_out(500, [
        'error' => 'No se pudo actualizar la identidad de la empresa.'
    ]);
}
