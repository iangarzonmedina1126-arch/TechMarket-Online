<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';
tm_require_roles($conexion, ['Jefe','Administrador','Empleado']);
tm_require_same_origin_for_post();

function responder($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Guarda el historial de entradas y salidas sin modificar tablas existentes.
    $conexion->exec("CREATE TABLE IF NOT EXISTS movimientos_inventario (
        id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
        id_producto INT NOT NULL,
        tipo ENUM('ENTRADA','SALIDA') NOT NULL,
        cantidad INT NOT NULL,
        stock_anterior INT NOT NULL,
        stock_nuevo INT NOT NULL,
        motivo VARCHAR(255) NULL,
        fecha_movimiento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mov_inv_producto (id_producto),
        CONSTRAINT fk_mov_inv_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Crea límites predeterminados para productos nuevos que aún no tengan fila de inventario.
        $conexion->exec("INSERT INTO inventario (id_producto, stock_minimo, stock_maximo, fecha_ultima_actualizacion)
            SELECT p.id_producto, 2, 20, CURDATE() FROM productos p
            LEFT JOIN inventario i ON i.id_producto = p.id_producto
            WHERE i.id_inventario IS NULL");
        $sql = "SELECT i.id_inventario, p.id_producto, p.nombre AS producto, p.stock AS stock_actual,
                       i.stock_minimo, i.stock_maximo, i.fecha_ultima_actualizacion AS ultima_actualizacion,
                       p.estado
                FROM inventario i JOIN productos p ON p.id_producto = i.id_producto
                ORDER BY p.id_producto";
        $rows = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['error'=>'Método no permitido.']);
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos)) responder(400, ['error'=>'El cuerpo de la solicitud no es JSON válido.']);
    $accion = $datos['action'] ?? '';
    $idProducto = filter_var($datos['id_producto'] ?? null, FILTER_VALIDATE_INT);
    if (!$idProducto || $idProducto < 1) responder(422, ['error'=>'El producto seleccionado no es válido.']);

    if ($accion === 'limites') {
        $min = filter_var($datos['stock_minimo'] ?? null, FILTER_VALIDATE_INT);
        $max = filter_var($datos['stock_maximo'] ?? null, FILTER_VALIDATE_INT);
        if ($min === false || $max === false || $min < 0 || $max < $min) responder(422, ['error'=>'El mínimo debe ser ≥ 0 y el máximo debe ser ≥ mínimo.']);
        $check = $conexion->prepare('SELECT id_inventario FROM inventario WHERE id_producto = ?');
        $check->execute([$idProducto]);
        if (!$check->fetchColumn()) responder(404, ['error'=>'No existe registro de inventario para ese producto. Actualiza la página e inténtalo otra vez.']);
        $stmt = $conexion->prepare('UPDATE inventario SET stock_minimo = ?, stock_maximo = ?, fecha_ultima_actualizacion = CURDATE() WHERE id_producto = ?');
        $stmt->execute([$min, $max, $idProducto]);
        responder(200, ['ok'=>true, 'message'=>'Límites actualizados.']);
    }

    if ($accion === 'movimiento') {
        $tipo = $datos['tipo'] ?? '';
        $cantidad = filter_var($datos['cantidad'] ?? null, FILTER_VALIDATE_INT);
        $motivo = trim((string)($datos['motivo'] ?? ''));
        if (!in_array($tipo, ['ENTRADA','SALIDA'], true)) responder(422, ['error'=>'Tipo de movimiento inválido.']);
        if ($cantidad === false || $cantidad < 1) responder(422, ['error'=>'La cantidad debe ser un entero mayor que cero.']);
        if (mb_strlen($motivo) > 255) responder(422, ['error'=>'El motivo admite máximo 255 caracteres.']);

        $conexion->beginTransaction();
        $stmt = $conexion->prepare('SELECT stock FROM productos WHERE id_producto = ? FOR UPDATE');
        $stmt->execute([$idProducto]);
        $stockActual = $stmt->fetchColumn();
        if ($stockActual === false) { $conexion->rollBack(); responder(404, ['error'=>'No se encontró el producto.']); }
        $stockActual = (int)$stockActual;
        if ($tipo === 'SALIDA' && $cantidad > $stockActual) { $conexion->rollBack(); responder(422, ['error'=>'Stock insuficiente. Disponible: '.$stockActual.'.']); }
        $nuevoStock = $tipo === 'ENTRADA' ? $stockActual + $cantidad : $stockActual - $cantidad;
        $upd = $conexion->prepare('UPDATE productos SET stock = ? WHERE id_producto = ?');
        $upd->execute([$nuevoStock, $idProducto]);
        $updInv = $conexion->prepare('UPDATE inventario SET fecha_ultima_actualizacion = CURDATE() WHERE id_producto = ?');
        $updInv->execute([$idProducto]);
        $log = $conexion->prepare('INSERT INTO movimientos_inventario (id_producto, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute([$idProducto, $tipo, $cantidad, $stockActual, $nuevoStock, $motivo !== '' ? $motivo : null]);
        $conexion->commit();
        responder(200, ['ok'=>true, 'stock_anterior'=>$stockActual, 'stock_nuevo'=>$nuevoStock, 'message'=>'Movimiento registrado.']);
    }
    responder(400, ['error'=>'Acción no reconocida.']);
} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) $conexion->rollBack();
    error_log('Error en inventario TechMarket: '.$e->getMessage());
    responder(500, ['error'=>'No se pudo procesar el inventario. Verifica la conexión y que la tabla INVENTARIO tenga las columnas esperadas.']);
}
