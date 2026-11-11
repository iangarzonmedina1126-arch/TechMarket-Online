<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';

function marca_out(int $status, array $data): void {
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function marca_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS techmarket_configuracion (
        clave VARCHAR(80) NOT NULL PRIMARY KEY,
        valor TEXT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS techmarket_logos (
        clave VARCHAR(30) NOT NULL PRIMARY KEY,
        mime VARCHAR(40) NOT NULL,
        contenido MEDIUMBLOB NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function marca_save(PDO $db, array $data): void {
    $q = $db->prepare("INSERT INTO techmarket_configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    foreach (['company_name','logo_url','catalog_logo_url'] as $key) $q->execute([$key, $data[$key] ?? null]);
}
function marca_store_logo(PDO $db, string $key, string $mime, string $bytes): void {
    $q = $db->prepare("INSERT INTO techmarket_logos (clave,mime,contenido) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),contenido=VALUES(contenido)");
    $q->bindValue(1, $key, PDO::PARAM_STR);
    $q->bindValue(2, $mime, PDO::PARAM_STR);
    $q->bindValue(3, $bytes, PDO::PARAM_LOB);
    $q->execute();
}
function marca_get(PDO $db): array {
    $data = ['company_name'=>'TechMarket Online','logo_url'=>null,'catalog_logo_url'=>null];
    $q = $db->query("SELECT clave,valor FROM techmarket_configuracion WHERE clave IN ('company_name','logo_url','catalog_logo_url')");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) if (array_key_exists($row['clave'],$data)) $data[$row['clave']]=$row['valor'];
    foreach (['logo'=>'logo_url','catalogo'=>'catalog_logo_url'] as $asset=>$field) {
        $q=$db->prepare('SELECT 1 FROM techmarket_logos WHERE clave=? LIMIT 1');
        $q->execute([$asset]);
        if ($q->fetchColumn()) $data[$field]='api_marca.php?asset='.$asset;
    }
    return $data;
}

try {
    marca_ensure_schema($conexion);

    // Sirve los logos directamente desde MySQL para que no dependan del disco efímero de Render.
    $asset = $_GET['asset'] ?? '';
    if (in_array($asset, ['logo','catalogo'], true)) {
        $q=$conexion->prepare('SELECT mime,contenido FROM techmarket_logos WHERE clave=? LIMIT 1');
        $q->execute([$asset]);
        $image=$q->fetch(PDO::FETCH_ASSOC);
        if (!$image) { http_response_code(404); exit; }
        header('Content-Type: '.$image['mime']);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $image['contenido'];
        exit;
    }

    // Migra una vez los logos anteriores si el archivo todavía está disponible.
    $legacyPath=__DIR__.'/uploads/branding/logo.json';
    if (is_file($legacyPath)) {
        $legacy=json_decode((string)file_get_contents($legacyPath),true);
        if (is_array($legacy)) {
            $q=$conexion->prepare('SELECT 1 FROM techmarket_configuracion WHERE clave=? LIMIT 1');
            $q->execute(['company_name']);
            if (!$q->fetchColumn()) {
                marca_save($conexion,[
                    'company_name'=>trim((string)($legacy['company_name']??'TechMarket Online'))?:'TechMarket Online',
                    'logo_url'=>$legacy['logo_url']??null,
                    'catalog_logo_url'=>$legacy['catalog_logo_url']??null
                ]);
            }
            foreach (['logo'=>'logo_url','catalogo'=>'catalog_logo_url'] as $assetKey=>$field) {
                $q=$conexion->prepare('SELECT 1 FROM techmarket_logos WHERE clave=? LIMIT 1');
                $q->execute([$assetKey]);
                $path=$legacy[$field]??null;
                if (!$q->fetchColumn() && is_string($path) && str_starts_with($path,'uploads/branding/')) {
                    $full=__DIR__.'/'.$path;
                    if (is_file($full)) {
                        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($full);
                        if (in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)) {
                            marca_store_logo($conexion,$assetKey,$mime,(string)file_get_contents($full));
                        }
                    }
                }
            }
        }
    }

    $data=marca_get($conexion);
    if ($_SERVER['REQUEST_METHOD']==='GET') marca_out(200,$data);
    if ($_SERVER['REQUEST_METHOD']!=='POST') marca_out(405,['error'=>'Método no permitido.']);
    tm_require_roles($conexion,['Jefe']);
    tm_require_same_origin_for_post();

    $actualizado=false;
    if (isset($_POST['company_name'])) {
        $nombre=trim((string)$_POST['company_name']);
        $longitud=function_exists('mb_strlen')?mb_strlen($nombre,'UTF-8'):strlen($nombre);
        if ($nombre===''||$longitud>80) marca_out(422,['error'=>'El nombre debe tener entre 1 y 80 caracteres.']);
        $data['company_name']=$nombre;
        $actualizado=true;
    }

    $tipoLogo=null;
    if (isset($_FILES['logo'])&&$_FILES['logo']['error']!==UPLOAD_ERR_NO_FILE) {
        $f=$_FILES['logo'];
        if ($f['error']!==UPLOAD_ERR_OK) marca_out(422,['error'=>'No se pudo recibir la imagen.']);
        if ($f['size']>4*1024*1024) marca_out(422,['error'=>'La imagen debe pesar máximo 4 MB.']);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $valid=['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array($mime,$valid,true)) marca_out(422,['error'=>'Usa una imagen JPG, PNG, WEBP o GIF.']);
        $tipoLogo=($_POST['tipo']??'principal')==='catalogo'?'catalogo':'logo';
        $bytes=(string)file_get_contents($f['tmp_name']);
        marca_store_logo($conexion,$tipoLogo,$mime,$bytes);
        $data[$tipoLogo==='logo'?'logo_url':'catalog_logo_url']='api_marca.php?asset='.$tipoLogo;
        $actualizado=true;
    }

    if (!$actualizado) marca_out(422,['error'=>'No se recibieron cambios para guardar.']);
    marca_save($conexion,$data);
    $data=marca_get($conexion);
    marca_out(200,['ok'=>true]+$data+['message'=>'Identidad de la empresa actualizada correctamente.']);
} catch (Throwable $e) {
    error_log('TechMarket branding: '.$e->getMessage());
    marca_out(500,['error'=>'No se pudo consultar o actualizar la identidad de la empresa.']);
}