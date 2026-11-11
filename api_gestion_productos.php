<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';
require_once __DIR__ . '/config/borrado_dependencias.php';
$productAdminUser = $productAdminUser = tm_require_roles($conexion, ['Jefe','Administrador']);
tm_require_same_origin_for_post();
try { foreach (['imagen_url'=>"ALTER TABLE productos ADD COLUMN imagen_url VARCHAR(500) NULL",'descuento_porcentaje'=>"ALTER TABLE productos ADD COLUMN descuento_porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0"] as $col=>$ddl) { $q=$conexion->query("SHOW COLUMNS FROM productos LIKE '$col'"); if(!$q->fetch(PDO::FETCH_ASSOC)) $conexion->exec($ddl); } } catch(Throwable $e) { error_log('No se pudo preparar columnas de productos.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
if (!is_array($datos)) {
    http_response_code(400);
    echo json_encode(['error' => 'El cuerpo de la solicitud no es JSON válido.']);
    exit;
}

try {
    $accion = $datos['action'] ?? '';
    $id = filter_var($datos['id'] ?? 0, FILTER_VALIDATE_INT);

    if ($accion === 'eliminar') {
        if (($productAdminUser['rol'] ?? '') !== 'Jefe') { http_response_code(403); echo json_encode(['error'=>'Solo el Jefe puede eliminar productos.']); exit; }
        if (!$id || $id < 1) { http_response_code(422); echo json_encode(['error'=>'ID de producto inválido.']); exit; }
        try {
            $conexion->beginTransaction();
            $check=$conexion->prepare('SELECT id_producto FROM productos WHERE id_producto=? LIMIT 1');$check->execute([$id]);
            if(!$check->fetchColumn()){$conexion->rollBack();http_response_code(404);echo json_encode(['error'=>'No se encontró el producto.']);exit;}
            $visited=[];tm_delete_row_with_dependents($conexion,'productos',['id_producto'=>$id],$visited);
            $conexion->commit();
            echo json_encode(['ok'=>true,'message'=>'Producto y registros dependientes eliminados definitivamente.']);
            exit;
        } catch (Throwable $e) {
            if($conexion->inTransaction())$conexion->rollBack();
            error_log('TechMarket eliminación de producto: '.$e->getMessage());
            http_response_code(500);echo json_encode(['error'=>'No se pudo completar el borrado relacionado. No se confirmó ningún cambio.']);exit;
        }
    }

    if ($accion === 'estado') {
        $estado = filter_var($datos['estado'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || !in_array($estado, [0, 1], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'ID o estado inválido.']);
            exit;
        }
        $stmt = $conexion->prepare('UPDATE productos SET estado = :estado WHERE id_producto = :id');
        $stmt->execute([':estado' => $estado, ':id' => $id]);
        if ($stmt->rowCount() === 0) {
            $check = $conexion->prepare('SELECT id_producto FROM productos WHERE id_producto = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'No se encontró el producto.']);
                exit;
            }
        }
        echo json_encode(['ok' => true, 'message' => 'Estado actualizado.']);
        exit;
    }

    if ($accion !== 'guardar') {
        http_response_code(400);
        echo json_encode(['error' => 'Acción no reconocida.']);
        exit;
    }

    $categoria = trim((string)($datos['categoria'] ?? ''));
    // En la interfaz, "Laptops" representa la categoría "Computadores" de la BD actual.
    if ($categoria === 'Laptops') $categoria = 'Computadores';
    $nombre = trim((string)($datos['nombre'] ?? ''));
    $precio = filter_var($datos['precio'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($datos['stock'] ?? null, FILTER_VALIDATE_INT);
    $descripcion = trim((string)($datos['descripcion'] ?? ''));
    $imagenUrl = trim((string)($datos['imagen_url'] ?? ''));
    $descuento = filter_var($datos['descuento_porcentaje'] ?? 0, FILTER_VALIDATE_INT);
    if (mb_strlen($imagenUrl) > 500 || ($imagenUrl !== '' && !preg_match('~^(uploads/productos/[a-f0-9]{32}\.(jpg|png|webp|gif)|https://images\.unsplash\.com/[A-Za-z0-9/_-]+(?:\?[A-Za-z0-9_=&%-]+)?)$~', $imagenUrl))) { http_response_code(422); echo json_encode(['error'=>'La imagen seleccionada no es válida.']); exit; }
    $estado = filter_var($datos['estado'] ?? null, FILTER_VALIDATE_INT);

    if ($categoria === '' || $nombre === '' || $precio === false || $precio <= 0 || $stock === false || $stock < 0 || !in_array($estado, [0, 1], true) || $descuento === false || $descuento < 0 || $descuento > 90) {
        http_response_code(422);
        echo json_encode(['error' => 'Revisa categoría, nombre, precio, stock, descuento (0–90%) y estado.']);
        exit;
    }
    if (mb_strlen($nombre) > 255 || mb_strlen($descripcion) > 255) {
        http_response_code(422);
        echo json_encode(['error' => 'El nombre y la descripción admiten máximo 255 caracteres.']);
        exit;
    }

    $catStmt = $conexion->prepare('SELECT id_categoria FROM categorias WHERE nombre = :nombre AND estado = 1 ORDER BY id_categoria LIMIT 1');
    $catStmt->execute([':nombre' => $categoria]);
    $idCategoria = $catStmt->fetchColumn();
    if (!$idCategoria) {
        http_response_code(422);
        echo json_encode(['error' => 'La categoría "' . $categoria . '" no existe en MySQL. Primero debes crearla en la tabla categorias.']);
        exit;
    }

    if ($id > 0) {
        $stmt = $conexion->prepare('UPDATE productos SET id_categoria=:categoria, nombre=:nombre, precio=:precio, stock=:stock, descripcion=:descripcion, estado=:estado, imagen_url=:imagen_url, descuento_porcentaje=:descuento WHERE id_producto=:id');
        $stmt->execute([':categoria'=>$idCategoria, ':nombre'=>$nombre, ':precio'=>$precio, ':stock'=>$stock, ':descripcion'=>$descripcion, ':estado'=>$estado, ':imagen_url'=>$imagenUrl, ':descuento'=>$descuento, ':id'=>$id]);
        echo json_encode(['ok'=>true, 'id'=>$id, 'message'=>'Producto actualizado.']);
    } else {
        $stmt = $conexion->prepare('INSERT INTO productos (id_categoria, nombre, precio, stock, descripcion, estado, imagen_url, descuento_porcentaje) VALUES (:categoria, :nombre, :precio, :stock, :descripcion, :estado, :imagen_url, :descuento)');
        $stmt->execute([':categoria'=>$idCategoria, ':nombre'=>$nombre, ':precio'=>$precio, ':stock'=>$stock, ':descripcion'=>$descripcion, ':estado'=>$estado, ':imagen_url'=>$imagenUrl, ':descuento'=>$descuento]);
        echo json_encode(['ok'=>true, 'id'=>(int)$conexion->lastInsertId(), 'message'=>'Producto creado.']);
    }
} catch (Throwable $e) {
    error_log('Error en gestión de productos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor al guardar el producto. Revisa el registro de errores de PHP.']);
}
