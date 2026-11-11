<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';
tm_require_same_origin_for_post();


function tm_pedidos_require_module(PDO $db, string $module): array {
    $u = tm_require_roles($db, ['Jefe','Administrador','Empleado']);
    if (!tm_can_module($u['rol'], $module)) tm_json_error(403, 'Tu rol no tiene permiso para realizar esta acción.');
    return $u;
}
function respuesta($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function texto($value, $max = 255) {
    $value = trim((string)($value ?? ''));
    if (mb_strlen($value) > $max) return null;
    return $value;
}
function asegurarTablasAuxiliares(PDO $conexion) {
    $conexion->exec("CREATE TABLE IF NOT EXISTS pedido_contacto (
        id_pedido INT PRIMARY KEY,
        nombre_completo VARCHAR(255) NOT NULL,
        correo VARCHAR(255) NOT NULL,
        telefono VARCHAR(255) NOT NULL,
        tipo_documento VARCHAR(40) NOT NULL,
        numero_documento VARCHAR(100) NOT NULL,
        metodo_pago VARCHAR(100) NOT NULL,
        CONSTRAINT fk_pedido_contacto_pedido FOREIGN KEY (id_pedido) REFERENCES pedidos(id_pedido) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");    $conexion->exec("CREATE TABLE IF NOT EXISTS garantia_solicitud (
        id_garantia INT AUTO_INCREMENT PRIMARY KEY,
        id_venta INT NOT NULL,
        id_cliente INT NOT NULL,
        id_usuario_staff INT NOT NULL,
        id_producto INT NOT NULL,
        motivo VARCHAR(255) NOT NULL,
        diagnostico VARCHAR(255) NULL,
        estado VARCHAR(40) NOT NULL DEFAULT 'Solicitud recibida',
        fecha_solicitud DATE NOT NULL,
        fecha_cierre DATE NULL,
        INDEX idx_garantia_venta (id_venta),
        CONSTRAINT fk_garantia_venta FOREIGN KEY (id_venta) REFERENCES ventas(id_venta),
        CONSTRAINT fk_garantia_cliente FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente),
        CONSTRAINT fk_garantia_staff FOREIGN KEY (id_usuario_staff) REFERENCES staff(id_usuario_staff),
        CONSTRAINT fk_garantia_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function fechaActual() { return date('Y-m-d'); }
function asegurarColumnaCosto(PDO $conexion) {
    $stmt = $conexion->query("SHOW COLUMNS FROM productos LIKE 'costo_compra'");
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) $conexion->exec('ALTER TABLE productos ADD COLUMN costo_compra DECIMAL(12,2) NULL DEFAULT NULL');
    $stmt = $conexion->query("SHOW COLUMNS FROM productos LIKE 'descuento_porcentaje'");
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) $conexion->exec('ALTER TABLE productos ADD COLUMN descuento_porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $conexion->exec("CREATE TABLE IF NOT EXISTS detalle_pedido_proveedor (id_detalle_pedido_proveedor INT AUTO_INCREMENT PRIMARY KEY, id_pedido_proveedor INT NOT NULL, id_producto INT NOT NULL, cantidad INT NOT NULL, costo_unitario DECIMAL(12,2) NOT NULL, total DECIMAL(12,2) NOT NULL, INDEX idx_det_prov_pedido(id_pedido_proveedor), CONSTRAINT fk_det_prov_pedido_costos FOREIGN KEY(id_pedido_proveedor) REFERENCES pedidos_proveedor(id_pedido_proveedor), CONSTRAINT fk_det_prov_producto_costos FOREIGN KEY(id_producto) REFERENCES productos(id_producto)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


try {
    asegurarTablasAuxiliares($conexion);
    asegurarColumnaCosto($conexion);
    $metodos = ['Contraentrega', 'Transferencia bancaria', 'Tarjeta de prueba', 'Transferencia de prueba'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $vista = $_GET['vista'] ?? 'pedidos';
        $module = ['costos'=>'costos','devoluciones'=>'devoluciones','pagos'=>'pagos','ventas'=>'ventas','reportes'=>'reportes'][$vista] ?? 'pedidos';
        tm_pedidos_require_module($conexion, $module);
        if ($vista === 'costos') {
            $rows = $conexion->query("SELECT p.id_producto, p.nombre, p.precio, p.costo_compra, p.stock, p.estado, CASE WHEN SUM(CASE WHEN pp.estado_pedido='Recibido' THEN dp.cantidad ELSE 0 END)>0 THEN SUM(CASE WHEN pp.estado_pedido='Recibido' THEN dp.total ELSE 0 END)/SUM(CASE WHEN pp.estado_pedido='Recibido' THEN dp.cantidad ELSE 0 END) ELSE NULL END AS costo_estimado FROM productos p LEFT JOIN detalle_pedido_proveedor dp ON dp.id_producto=p.id_producto LEFT JOIN pedidos_proveedor pp ON pp.id_pedido_proveedor=dp.id_pedido_proveedor GROUP BY p.id_producto,p.nombre,p.precio,p.costo_compra,p.stock,p.estado ORDER BY p.nombre")->fetchAll(PDO::FETCH_ASSOC);
            respuesta(200, $rows);
        }

        if ($vista === 'devoluciones') {
            $ventas = $conexion->query("SELECT v.id_venta, v.id_pedido, v.numero_comprobante, v.fecha_venta, v.total, p.estado AS estado_pedido,
                    COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente, c.id_cliente,
                    GROUP_CONCAT(DISTINCT CONCAT(pr.id_producto, ': ', pr.nombre) ORDER BY pr.nombre SEPARATOR ' | ') AS productos
                FROM ventas v INNER JOIN pedidos p ON p.id_pedido = v.id_pedido INNER JOIN cliente c ON c.id_cliente = v.id_cliente
                LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido
                LEFT JOIN detalle_de_pedido d ON d.id_pedido = p.id_pedido LEFT JOIN productos pr ON pr.id_producto = d.id_producto
                WHERE p.estado = 'Entregado' GROUP BY v.id_venta, v.id_pedido, v.numero_comprobante, v.fecha_venta, v.total, p.estado, pc.nombre_completo, c.id_cliente
                ORDER BY v.id_venta DESC")->fetchAll(PDO::FETCH_ASSOC);
            $devoluciones = $conexion->query("SELECT d.id_devolucion, d.id_venta, v.id_pedido, v.numero_comprobante, d.motivo, d.monto_reembolsado,
                    d.fecha_devolucion, d.estado, COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente, v.total
                FROM devolucion d INNER JOIN ventas v ON v.id_venta = d.id_venta INNER JOIN cliente c ON c.id_cliente = d.id_cliente
                LEFT JOIN pedido_contacto pc ON pc.id_pedido = v.id_pedido ORDER BY d.id_devolucion DESC")->fetchAll(PDO::FETCH_ASSOC);
            $garantias = $conexion->query("SELECT g.id_garantia, g.id_venta, v.id_pedido, v.numero_comprobante, g.id_producto, pr.nombre AS producto,
                    g.motivo, COALESCE(g.diagnostico,'') AS diagnostico, g.estado, g.fecha_solicitud, g.fecha_cierre,
                    COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente
                FROM garantia_solicitud g INNER JOIN ventas v ON v.id_venta = g.id_venta INNER JOIN cliente c ON c.id_cliente = g.id_cliente
                INNER JOIN productos pr ON pr.id_producto = g.id_producto LEFT JOIN pedido_contacto pc ON pc.id_pedido = v.id_pedido
                ORDER BY g.id_garantia DESC")->fetchAll(PDO::FETCH_ASSOC);
            $productos = $conexion->query('SELECT id_producto, nombre FROM productos ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
            respuesta(200, ['ventas'=>$ventas, 'devoluciones'=>$devoluciones, 'garantias'=>$garantias, 'productos'=>$productos]);
        }

        if ($vista === 'pagos') {
            $sql = "SELECT v.id_venta, v.id_pedido, v.numero_comprobante, v.fecha_venta, v.total,
                           p.estado AS estado_pedido,
                           COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente,
                           c.correo,
                           COALESCE(pc.metodo_pago, 'No especificado') AS metodo_pago,
                           COALESCE(pg.estado_pago, CASE WHEN pc.metodo_pago LIKE 'Contraentrega%' THEN 'Pendiente de cobro' WHEN pc.metodo_pago LIKE 'Transferencia%' THEN 'Pendiente de verificación' WHEN pc.metodo_pago LIKE 'Tarjeta%' THEN 'Pendiente de confirmación' ELSE 'Pendiente' END) AS estado_pago,
                           pg.id_pago, pg.fecha_pago
                    FROM ventas v
                    INNER JOIN pedidos p ON p.id_pedido = v.id_pedido
                    INNER JOIN cliente c ON c.id_cliente = v.id_cliente
                    LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido
                    LEFT JOIN pago pg ON pg.id_venta = v.id_venta
                    ORDER BY v.id_venta DESC";
            $pagos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            respuesta(200, $pagos);
        }

        if ($vista === 'ventas') {
            $sql = "SELECT v.id_venta, v.id_pedido, v.numero_comprobante, v.fecha_venta, v.total,
                           p.estado AS estado_pedido, COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente,
                           c.correo, COALESCE(pc.metodo_pago, 'No especificado') AS metodo_pago,
                           COALESCE(pg.estado_pago, CASE WHEN pc.metodo_pago LIKE 'Contraentrega%' THEN 'Pendiente de cobro' WHEN pc.metodo_pago LIKE 'Transferencia%' THEN 'Pendiente de verificación' WHEN pc.metodo_pago LIKE 'Tarjeta%' THEN 'Pendiente de confirmación' ELSE 'Pendiente' END) AS estado_pago, pg.fecha_pago
                    FROM ventas v
                    INNER JOIN pedidos p ON p.id_pedido = v.id_pedido
                    INNER JOIN cliente c ON c.id_cliente = v.id_cliente
                    LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido
                    LEFT JOIN pago pg ON pg.id_venta = v.id_venta
                    ORDER BY v.id_venta DESC";
            $ventas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            respuesta(200, $ventas);
        }

        if ($vista === 'reportes') {
            $desde = $_GET['desde'] ?? '';
            $hasta = $_GET['hasta'] ?? '';
            $validDate = static function($value) { return $value === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value); };
            if (!$validDate($desde) || !$validDate($hasta)) respuesta(400, ['error'=>'El rango de fechas no es válido.']);
            if ($desde !== '' && $hasta !== '' && $desde > $hasta) respuesta(400, ['error'=>'La fecha inicial no puede ser posterior a la fecha final.']);
            $where = [];
            $params = [];
            if ($desde !== '') { $where[] = 'v.fecha_venta >= ?'; $params[] = $desde; }
            if ($hasta !== '') { $where[] = 'v.fecha_venta <= ?'; $params[] = $hasta; }
            $whereSql = $where ? (' WHERE '.implode(' AND ', $where)) : '';
            $base = " FROM ventas v INNER JOIN pedidos p ON p.id_pedido = v.id_pedido LEFT JOIN pago pg ON pg.id_venta = v.id_venta ";
            $stmt = $conexion->prepare("SELECT COUNT(DISTINCT v.id_venta) AS cantidad_ventas, COALESCE(SUM(v.total),0) AS total_ventas, COALESCE(SUM(CASE WHEN pg.estado_pago = 'Pagado' THEN v.total ELSE 0 END),0) AS total_pagado, COUNT(DISTINCT CASE WHEN pg.estado_pago = 'Pagado' THEN v.id_venta END) AS ventas_pagadas, COUNT(DISTINCT CASE WHEN COALESCE(pg.estado_pago,'') <> 'Pagado' AND p.estado <> 'Cancelado' THEN v.id_venta END) AS pagos_pendientes, COALESCE(SUM(CASE WHEN COALESCE(pg.estado_pago,'') <> 'Pagado' AND p.estado <> 'Cancelado' THEN v.total ELSE 0 END),0) AS valor_pendiente".$base.$whereSql);
            $stmt->execute($params);
            $resumen = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmtMes = $conexion->prepare("SELECT DATE_FORMAT(v.fecha_venta, '%Y-%m') AS mes, COUNT(DISTINCT v.id_venta) AS cantidad, COALESCE(SUM(v.total),0) AS total, COALESCE(SUM(CASE WHEN pg.estado_pago = 'Pagado' THEN v.total ELSE 0 END),0) AS total_pagado".$base.$whereSql." GROUP BY DATE_FORMAT(v.fecha_venta, '%Y-%m') ORDER BY mes DESC LIMIT 12");
            $stmtMes->execute($params);
            $porMes = array_reverse($stmtMes->fetchAll(PDO::FETCH_ASSOC));
            $stmtMet = $conexion->prepare("SELECT COALESCE(pc.metodo_pago,'No especificado') AS metodo, COUNT(DISTINCT v.id_venta) AS cantidad, COALESCE(SUM(v.total),0) AS total, COUNT(DISTINCT CASE WHEN pg.estado_pago = 'Pagado' THEN v.id_venta END) AS confirmados".$base." LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido".$whereSql." GROUP BY COALESCE(pc.metodo_pago,'No especificado') ORDER BY total DESC");
            $stmtMet->execute($params);
            $porMetodo = $stmtMet->fetchAll(PDO::FETCH_ASSOC);
            $productWhere = [];
            $productParams = [];
            if ($desde !== '') { $productWhere[] = 'v.fecha_venta >= ?'; $productParams[] = $desde; }
            if ($hasta !== '') { $productWhere[] = 'v.fecha_venta <= ?'; $productParams[] = $hasta; }
            $productWhereSql = $productWhere ? (' WHERE '.implode(' AND ', $productWhere)) : '';
            $stmtProd = $conexion->prepare("SELECT pr.id_producto, pr.nombre AS producto, COALESCE(SUM(d.cantidad),0) AS unidades, COALESCE(SUM(d.total),0) AS importe FROM detalle_de_pedido d INNER JOIN productos pr ON pr.id_producto = d.id_producto INNER JOIN pedidos p ON p.id_pedido = d.id_pedido INNER JOIN ventas v ON v.id_pedido = p.id_pedido".$productWhereSql." GROUP BY pr.id_producto, pr.nombre ORDER BY unidades DESC, importe DESC LIMIT 10");
            $stmtProd->execute($productParams);
            $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
            $stmtDetalle = $conexion->prepare("SELECT v.id_venta, v.id_pedido, v.numero_comprobante, v.fecha_venta, COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente, v.total, p.estado AS estado_pedido, COALESCE(pc.metodo_pago,'No especificado') AS metodo_pago, COALESCE(pg.estado_pago, CASE WHEN pc.metodo_pago LIKE 'Contraentrega%' THEN 'Pendiente de cobro' WHEN pc.metodo_pago LIKE 'Transferencia%' THEN 'Pendiente de verificación' WHEN pc.metodo_pago LIKE 'Tarjeta%' THEN 'Pendiente de confirmación' ELSE 'Pendiente' END) AS estado_pago".$base." INNER JOIN cliente c ON c.id_cliente = v.id_cliente LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido".$whereSql." ORDER BY v.fecha_venta DESC, v.id_venta DESC");
            $stmtDetalle->execute($params);
            respuesta(200, ['resumen'=>$resumen, 'por_mes'=>$porMes, 'por_metodo'=>$porMetodo, 'productos_mas_vendidos'=>$productos, 'ventas'=>$stmtDetalle->fetchAll(PDO::FETCH_ASSOC)]);
        }

        $sql = "SELECT p.id_pedido, p.id_cliente, p.fecha_pedido, p.estado, p.total,
                       c.correo AS cliente_correo, c.telefono AS cliente_telefono,
                       c.tipo_documento, c.numero_documento,
                       u.direccion_linea, u.ciudad, u.departamento, u.codigo_postal, u.referencia,
                       COALESCE(pc.nombre_completo, CONCAT('Cliente #', c.id_cliente)) AS cliente_nombre,
                       COALESCE(pc.metodo_pago, 'No especificado') AS metodo_pago,
                       v.id_venta, v.numero_comprobante, COALESCE(pg.estado_pago, CASE WHEN pc.metodo_pago LIKE 'Contraentrega%' THEN 'Pendiente de cobro' WHEN pc.metodo_pago LIKE 'Transferencia%' THEN 'Pendiente de verificación' WHEN pc.metodo_pago LIKE 'Tarjeta%' THEN 'Pendiente de confirmación' ELSE 'Pendiente' END) AS estado_pago
                FROM pedidos p
                INNER JOIN cliente c ON c.id_cliente = p.id_cliente
                INNER JOIN ubicacion u ON u.id_ubicacion = p.id_ubicacion
                LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido
                LEFT JOIN ventas v ON v.id_pedido = p.id_pedido
                LEFT JOIN pago pg ON pg.id_venta = v.id_venta
                ORDER BY p.id_pedido DESC";
        $pedidos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $detalleStmt = $conexion->prepare("SELECT d.id_producto, pr.nombre AS producto, d.cantidad, d.precio_unitario, d.total
                                           FROM detalle_de_pedido d INNER JOIN productos pr ON pr.id_producto = d.id_producto
                                           WHERE d.id_pedido = ? ORDER BY d.id_detalle_pedido");
        foreach ($pedidos as &$pedido) {
            $detalleStmt->execute([(int)$pedido['id_pedido']]);
            $pedido['detalles'] = $detalleStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($pedido);
        respuesta(200, $pedidos);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respuesta(405, ['error'=>'Método no permitido.']);
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos)) respuesta(400, ['error'=>'El cuerpo de la solicitud no es JSON válido.']);
    $accion = $datos['action'] ?? '';

    if ($accion === 'actualizar_costo_producto') {
        tm_pedidos_require_module($conexion, 'costos');
        $id = filter_var($datos['id_producto'] ?? null, FILTER_VALIDATE_INT);
        $costo = filter_var($datos['costo_compra'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$id || $id < 1 || $costo === false || !is_finite((float)$costo) || $costo <= 0 || $costo > 9999999999.99) respuesta(422, ['error'=>'El producto y el costo deben ser válidos. El costo debe ser mayor que cero.']);
        $stmt = $conexion->prepare('UPDATE productos SET costo_compra = ? WHERE id_producto = ?');
        $stmt->execute([round((float)$costo, 2), $id]);
        if (!$stmt->rowCount()) { $check = $conexion->prepare('SELECT id_producto FROM productos WHERE id_producto = ?'); $check->execute([$id]); if (!$check->fetchColumn()) respuesta(404, ['error'=>'No se encontró el producto.']); }
        respuesta(200, ['message'=>'Costo de compra guardado correctamente.']);
    }

    if ($accion === 'crear') {
        if (empty($_SESSION['tm_cliente_id'])) respuesta(401, ['error'=>'Para comprar debes iniciar sesión o crear una cuenta.']);
        $nombre = texto($datos['nombre'] ?? '', 255);
        $correo = filter_var(trim((string)($datos['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
        $telefono = texto($datos['telefono'] ?? '', 255);
        $tipoDoc = texto($datos['tipo_documento'] ?? '', 40);
        $numeroDoc = texto($datos['numero_documento'] ?? '', 100);
        $direccion = texto($datos['direccion'] ?? '', 255);
        $ciudad = texto($datos['ciudad'] ?? '', 255);
        $departamento = texto($datos['departamento'] ?? '', 255);
        $codigoPostal = texto($datos['codigo_postal'] ?? '', 255);
        $referencia = texto($datos['referencia'] ?? '', 255);
        $metodoPago = texto($datos['metodo_pago'] ?? '', 100);
        $items = $datos['items'] ?? null;
        if (!$nombre || !$correo || !$telefono || !$tipoDoc || !$numeroDoc || !$direccion || !$ciudad || !$departamento || !$metodoPago || !in_array($metodoPago, $metodos, true)) {
            respuesta(422, ['error'=>'Completa correctamente los datos de contacto, documento, envío y método de pago.']);
        }
        if (!is_array($items) || count($items) < 1 || count($items) > 50) respuesta(422, ['error'=>'El carrito está vacío o contiene demasiados productos.']);

        $cantidades = [];
        foreach ($items as $item) {
            $id = filter_var($item['id_producto'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || $cantidad === false || $cantidad < 1 || $cantidad > 1000) respuesta(422, ['error'=>'Hay un producto o una cantidad inválida en el carrito.']);
            $cantidades[$id] = ($cantidades[$id] ?? 0) + $cantidad;
        }
        ksort($cantidades, SORT_NUMERIC);
        $staffId = $conexion->query('SELECT id_usuario_staff FROM staff WHERE estado = 1 ORDER BY id_usuario_staff LIMIT 1')->fetchColumn();
        if (!$staffId) respuesta(409, ['error'=>'No hay personal activo en la tabla STAFF para registrar la venta. Activa o registra un usuario de staff.']);

        $conexion->beginTransaction();
        try {
            // Cada pedido se vincula a la cuenta autenticada: nunca se crea un cliente nuevo desde el checkout.
            $qCliente = $conexion->prepare("SELECT id_cliente, id_usuario, correo, numero_documento, nombre, apellidos FROM cliente WHERE id_cliente = ? AND estado = 1 FOR UPDATE");
            $qCliente->execute([(int)$_SESSION['tm_cliente_id']]);
            $cliente = $qCliente->fetch(PDO::FETCH_ASSOC);
            if (!$cliente) throw new RuntimeException('No se encontró tu cuenta de cliente. Inicia sesión nuevamente.');
            if (strcasecmp((string)$cliente['correo'], (string)$correo) !== 0 || (string)$cliente['numero_documento'] !== (string)$numeroDoc || trim((string)$nombre) !== trim((string)$cliente['nombre'].' '.(string)$cliente['apellidos'])) throw new RuntimeException('Los datos de la compra no coinciden con tu cuenta. Actualiza tus datos desde Mi cuenta.');
            $idCliente = (int)$cliente['id_cliente'];
            $upd = $conexion->prepare('UPDATE cliente SET telefono = ? WHERE id_cliente = ?');
            $upd->execute([$telefono, $idCliente]);

            // Reutiliza una dirección idéntica del mismo cliente para evitar duplicados en cada compra.
            $qUbicacion = $conexion->prepare("SELECT id_ubicacion FROM ubicacion WHERE id_cliente=? AND direccion_linea=? AND ciudad=? AND departamento=? AND COALESCE(codigo_postal, '')=? AND COALESCE(referencia, '')=? AND estado=1 ORDER BY id_ubicacion LIMIT 1 FOR UPDATE");
            $qUbicacion->execute([$idCliente,$direccion,$ciudad,$departamento,$codigoPostal,$referencia]);
            $idUbicacion = (int)$qUbicacion->fetchColumn();
            if (!$idUbicacion) {
                $insUbicacion = $conexion->prepare('INSERT INTO ubicacion (id_cliente, direccion_linea, ciudad, departamento, codigo_postal, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 1)');
                $insUbicacion->execute([$idCliente, $direccion, $ciudad, $departamento, $codigoPostal !== '' ? $codigoPostal : null, $referencia !== '' ? $referencia : null]);
                $idUbicacion = (int)$conexion->lastInsertId();
            }

            // Bloquea los productos en orden de ID y calcula los precios desde la base de datos.
            $productos = [];
            $qProducto = $conexion->prepare('SELECT id_producto, nombre, precio, stock, estado, COALESCE(descuento_porcentaje,0) AS descuento_porcentaje FROM productos WHERE id_producto = ? FOR UPDATE');
            $total = 0.0;
            foreach ($cantidades as $idProducto => $cantidad) {
                $qProducto->execute([(int)$idProducto]);
                $producto = $qProducto->fetch(PDO::FETCH_ASSOC);
                if (!$producto || (int)$producto['estado'] !== 1) throw new RuntimeException('Uno de los productos ya no está disponible. Actualiza el catálogo e inténtalo de nuevo.');
                if ((int)$producto['stock'] < $cantidad) throw new RuntimeException('Stock insuficiente para "'.$producto['nombre'].'". Disponible: '.(int)$producto['stock'].'.');
                $producto['cantidad_compra'] = $cantidad;
                $producto['precio_final'] = round((float)$producto['precio'] * (1 - max(0, min(90, (int)$producto['descuento_porcentaje'])) / 100), 2);
                $producto['total_linea'] = round($producto['precio_final'] * $cantidad, 2);
                $total += $producto['total_linea'];
                $productos[] = $producto;
            }
            $total = round($total, 2);
            $insPedido = $conexion->prepare("INSERT INTO pedidos (id_cliente, id_ubicacion, fecha_pedido, estado, total) VALUES (?, ?, ?, 'Pendiente', ?)");
            $insPedido->execute([$idCliente, $idUbicacion, fechaActual(), $total]);
            $idPedido = (int)$conexion->lastInsertId();
            $insContacto = $conexion->prepare('INSERT INTO pedido_contacto (id_pedido, nombre_completo, correo, telefono, tipo_documento, numero_documento, metodo_pago) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insContacto->execute([$idPedido, $nombre, $correo, $telefono, $tipoDoc, $numeroDoc, $metodoPago]);
            $insDetalle = $conexion->prepare('INSERT INTO detalle_de_pedido (id_pedido, id_producto, cantidad, total, precio_unitario) VALUES (?, ?, ?, ?, ?)');
            $updStock = $conexion->prepare('UPDATE productos SET stock = stock - ? WHERE id_producto = ? AND stock >= ?');
            $updFecha = $conexion->prepare('UPDATE inventario SET fecha_ultima_actualizacion = CURDATE() WHERE id_producto = ?');
            $logSalida = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?, 'SALIDA', ?, ?, ?, ?)");
            foreach ($productos as $producto) {
                $idProducto = (int)$producto['id_producto'];
                $cantidad = (int)$producto['cantidad_compra'];
                $stockAntes = (int)$producto['stock'];
                $stockNuevo = $stockAntes - $cantidad;
                $insDetalle->execute([$idPedido, $idProducto, $cantidad, $producto['total_linea'], $producto['precio_final']]);
                $updStock->execute([$cantidad, $idProducto, $cantidad]);
                if ($updStock->rowCount() !== 1) throw new RuntimeException('El stock cambió durante la compra. Intenta nuevamente.');
                $updFecha->execute([$idProducto]);
                $logSalida->execute([$idProducto, $cantidad, $stockAntes, $stockNuevo, 'Venta online pedido #'.$idPedido]);
            }
            $comprobante = 'FAC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $insVenta = $conexion->prepare('INSERT INTO ventas (id_pedido, id_cliente, id_usuario_staff, numero_comprobante, fecha_venta, total) VALUES (?, ?, ?, ?, ?, ?)');
            $insVenta->execute([$idPedido, $idCliente, (int)$staffId, $comprobante, fechaActual(), $total]);
            $idVenta = (int)$conexion->lastInsertId();
            // El esquema existente no tiene una tabla de intentos/medio de pago aparte del registro de pagos.
            // Guardamos el método en pedido_contacto y no marcamos como pagado un pago no verificado.
            $conexion->commit();
            $estadoPagoInicial = strpos($metodoPago, 'Contraentrega') === 0 ? 'Pendiente de cobro' : (strpos($metodoPago, 'Transferencia') === 0 ? 'Pendiente de verificación' : 'Pendiente de confirmación');
            respuesta(201, ['ok'=>true, 'id_pedido'=>$idPedido, 'id_venta'=>$idVenta, 'comprobante'=>$comprobante, 'total'=>$total, 'estado'=>'Pendiente', 'estado_pago'=>$estadoPagoInicial, 'message'=>'Pedido registrado y stock descontado correctamente. El pago aún no está confirmado.']);
        } catch (Throwable $e) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            $mensaje = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo registrar el pedido. Verifica los datos e inténtalo de nuevo.';
            respuesta(422, ['error'=>$mensaje]);
        }
    }

    if ($accion === 'crear_devolucion') {
        tm_pedidos_require_module($conexion, 'devoluciones');
        $idVenta = filter_var($datos['id_venta'] ?? null, FILTER_VALIDATE_INT);
        $motivo = texto($datos['motivo'] ?? '', 255);
        if (!$idVenta || $idVenta < 1 || !$motivo) respuesta(422, ['error'=>'Selecciona una venta y escribe el motivo de la devolución.']);
        $staffId = (int)$conexion->query('SELECT id_usuario_staff FROM staff WHERE estado = 1 ORDER BY id_usuario_staff LIMIT 1')->fetchColumn();
        if (!$staffId) respuesta(409, ['error'=>'No hay personal activo en STAFF para registrar la devolución.']);
        $conexion->beginTransaction();
        try {
            $q = $conexion->prepare("SELECT v.id_venta, v.id_cliente, v.total, p.estado FROM ventas v INNER JOIN pedidos p ON p.id_pedido = v.id_pedido WHERE v.id_venta = ? FOR UPDATE");
            $q->execute([$idVenta]); $venta = $q->fetch(PDO::FETCH_ASSOC);
            if (!$venta) throw new RuntimeException('No se encontró la venta seleccionada.');
            if ($venta['estado'] !== 'Entregado') throw new RuntimeException('Solo se pueden solicitar devoluciones de pedidos entregados.');
            $dup = $conexion->prepare("SELECT COUNT(*) FROM devolucion WHERE id_venta = ? AND estado NOT IN ('Rechazada')");
            $dup->execute([$idVenta]); if ((int)$dup->fetchColumn() > 0) throw new RuntimeException('Esta venta ya tiene una devolución en curso o finalizada.');
            $ins = $conexion->prepare("INSERT INTO devolucion (id_venta, id_cliente, id_usuario_staff, motivo, monto_reembolsado, fecha_devolucion, estado) VALUES (?, ?, ?, ?, 0, CURDATE(), 'Solicitud recibida')");
            $ins->execute([$idVenta, (int)$venta['id_cliente'], $staffId, $motivo]);
            $id = (int)$conexion->lastInsertId(); $conexion->commit();
            respuesta(200, ['ok'=>true, 'id_devolucion'=>$id, 'message'=>'Solicitud de devolución registrada.']);
        } catch (Throwable $e) { if ($conexion->inTransaction()) $conexion->rollBack(); respuesta(422, ['error'=>$e instanceof RuntimeException ? $e->getMessage() : 'No se pudo registrar la devolución.']); }
    }

    if ($accion === 'estado_devolucion') {
        tm_pedidos_require_module($conexion, 'devoluciones');
        $id = filter_var($datos['id_devolucion'] ?? null, FILTER_VALIDATE_INT);
        $estado = texto($datos['estado'] ?? '', 40);
        $permitidos = ['En revisión','Aprobada','Rechazada','Recibida','Reembolsada'];
        if (!$id || $id < 1 || !in_array($estado, $permitidos, true)) respuesta(422, ['error'=>'Devolución o estado no válido.']);
        $conexion->beginTransaction();
        try {
            $q = $conexion->prepare("SELECT d.id_devolucion, d.id_venta, d.estado, v.id_pedido, v.total FROM devolucion d INNER JOIN ventas v ON v.id_venta = d.id_venta WHERE d.id_devolucion = ? FOR UPDATE");
            $q->execute([$id]); $d = $q->fetch(PDO::FETCH_ASSOC);
            if (!$d) throw new RuntimeException('No se encontró la devolución.');
            $actual = $d['estado'];
            $transiciones = [
                'Solicitud recibida'=>['En revisión','Rechazada'],
                'En revisión'=>['Aprobada','Rechazada'],
                'Aprobada'=>['Recibida'],
                'Recibida'=>['Reembolsada'],
                'Reembolsada'=>[], 'Rechazada'=>[]
            ];
            if (!in_array($estado, $transiciones[$actual] ?? [], true)) throw new RuntimeException('No se puede cambiar de "'.$actual.'" a "'.$estado.'". Sigue el flujo de devolución.');
            if ($estado === 'Recibida') {
                $qDet = $conexion->prepare('SELECT id_producto, cantidad FROM detalle_de_pedido WHERE id_pedido = ? ORDER BY id_producto FOR UPDATE');
                $qDet->execute([(int)$d['id_pedido']]); $detalles = $qDet->fetchAll(PDO::FETCH_ASSOC);
                $qStock = $conexion->prepare('SELECT stock FROM productos WHERE id_producto = ? FOR UPDATE');
                $updStock = $conexion->prepare('UPDATE productos SET stock = stock + ? WHERE id_producto = ?');
                $updFecha = $conexion->prepare('UPDATE inventario SET fecha_ultima_actualizacion = CURDATE() WHERE id_producto = ?');
                $log = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?, 'ENTRADA', ?, ?, ?, ?)");
                foreach ($detalles as $item) {
                    $idProducto=(int)$item['id_producto']; $cantidad=(int)$item['cantidad']; $qStock->execute([$idProducto]); $antes=$qStock->fetchColumn();
                    if ($antes === false) throw new RuntimeException('No se encontró un producto de la venta al reintegrar stock.');
                    $antes=(int)$antes; $despues=$antes+$cantidad; $updStock->execute([$cantidad,$idProducto]); $updFecha->execute([$idProducto]);
                    $log->execute([$idProducto,$cantidad,$antes,$despues,'Devolución recibida #'.$id]);
                }
            }
            $monto = $estado === 'Reembolsada' ? (float)$d['total'] : 0;
            $upd = $conexion->prepare('UPDATE devolucion SET estado = ?, monto_reembolsado = ? WHERE id_devolucion = ?');
            $upd->execute([$estado, $monto, $id]); $conexion->commit();
            respuesta(200, ['ok'=>true,'estado'=>$estado,'message'=>$estado==='Recibida'?'Producto(s) recibido(s); stock reintegrado y movimiento registrado.':($estado==='Reembolsada'?'Reembolso simulado registrado; no se movió dinero real.':'Estado de devolución actualizado.')]);
        } catch (Throwable $e) { if ($conexion->inTransaction()) $conexion->rollBack(); respuesta(422, ['error'=>$e instanceof RuntimeException ? $e->getMessage() : 'No se pudo actualizar la devolución.']); }
    }

    if ($accion === 'crear_garantia') {
        tm_pedidos_require_module($conexion, 'devoluciones');
        $idVenta = filter_var($datos['id_venta'] ?? null, FILTER_VALIDATE_INT);
        $idProducto = filter_var($datos['id_producto'] ?? null, FILTER_VALIDATE_INT);
        $motivo = texto($datos['motivo'] ?? '', 255);
        if (!$idVenta || !$idProducto || !$motivo) respuesta(422, ['error'=>'Selecciona venta y producto e indica el problema.']);
        $staffId = (int)$conexion->query('SELECT id_usuario_staff FROM staff WHERE estado = 1 ORDER BY id_usuario_staff LIMIT 1')->fetchColumn();
        if (!$staffId) respuesta(409, ['error'=>'No hay personal activo en STAFF para registrar la garantía.']);
        $conexion->beginTransaction();
        try {
            $q = $conexion->prepare("SELECT v.id_cliente, p.estado FROM ventas v INNER JOIN pedidos p ON p.id_pedido = v.id_pedido WHERE v.id_venta = ? FOR UPDATE");
            $q->execute([$idVenta]); $venta = $q->fetch(PDO::FETCH_ASSOC);
            if (!$venta) throw new RuntimeException('No se encontró la venta seleccionada.');
            if ($venta['estado'] !== 'Entregado') throw new RuntimeException('Solo se pueden registrar garantías de pedidos entregados.');
            $qd = $conexion->prepare('SELECT COUNT(*) FROM detalle_de_pedido d INNER JOIN ventas v ON v.id_pedido = d.id_pedido WHERE v.id_venta = ? AND d.id_producto = ?');
            $qd->execute([$idVenta,$idProducto]); if ((int)$qd->fetchColumn() < 1) throw new RuntimeException('El producto seleccionado no pertenece a esa venta.');
            $dup=$conexion->prepare("SELECT COUNT(*) FROM garantia_solicitud WHERE id_venta = ? AND id_producto = ? AND estado NOT IN ('Rechazada','Cerrada','Resuelta')");
            $dup->execute([$idVenta,$idProducto]); if ((int)$dup->fetchColumn()>0) throw new RuntimeException('Ese producto ya tiene una garantía abierta para esta venta.');
            $ins=$conexion->prepare("INSERT INTO garantia_solicitud (id_venta,id_cliente,id_usuario_staff,id_producto,motivo,estado,fecha_solicitud) VALUES (?,?,?,?,?,'Solicitud recibida',CURDATE())");
            $ins->execute([$idVenta,(int)$venta['id_cliente'],$staffId,$idProducto,$motivo]); $id=(int)$conexion->lastInsertId(); $conexion->commit();
            respuesta(200,['ok'=>true,'id_garantia'=>$id,'message'=>'Solicitud de garantía registrada.']);
        } catch (Throwable $e) { if ($conexion->inTransaction()) $conexion->rollBack(); respuesta(422,['error'=>$e instanceof RuntimeException?$e->getMessage():'No se pudo registrar la garantía.']); }
    }

    if ($accion === 'estado_garantia') {
        tm_pedidos_require_module($conexion, 'devoluciones');
        $id = filter_var($datos['id_garantia'] ?? null, FILTER_VALIDATE_INT);
        $estado = texto($datos['estado'] ?? '', 40);
        $diagnostico = texto($datos['diagnostico'] ?? '', 255);
        $permitidos = ['En diagnóstico','En reparación','Resuelta','Rechazada','Cerrada'];
        if (!$id || $id < 1 || !in_array($estado, $permitidos, true)) respuesta(422, ['error'=>'Garantía o estado no válido.']);
        $q = $conexion->prepare('SELECT estado FROM garantia_solicitud WHERE id_garantia = ?'); $q->execute([$id]); $actual=$q->fetchColumn();
        if ($actual === false) respuesta(404,['error'=>'No se encontró la garantía.']);
        $transiciones = ['Solicitud recibida'=>['En diagnóstico','Rechazada'],'En diagnóstico'=>['En reparación','Resuelta','Rechazada'],'En reparación'=>['Resuelta'],'Resuelta'=>['Cerrada'],'Rechazada'=>[],'Cerrada'=>[]];
        if (!in_array($estado,$transiciones[$actual]??[],true)) respuesta(422,['error'=>'Transición no permitida: '.$actual.' → '.$estado.'.']);
        $fechaCierre = in_array($estado,['Resuelta','Rechazada','Cerrada'],true) ? fechaActual() : null;
        $upd=$conexion->prepare('UPDATE garantia_solicitud SET estado = ?, diagnostico = ?, fecha_cierre = ? WHERE id_garantia = ?');
        $upd->execute([$estado,$diagnostico ?: null,$fechaCierre,$id]);
        respuesta(200,['ok'=>true,'estado'=>$estado,'message'=>'Estado de garantía actualizado.']);
    }

    if ($accion === 'registrar_transferencia') {
        if (empty($_SESSION['tm_cliente_id'])) respuesta(401, ['error'=>'Inicia sesión con tu cuenta para registrar la transferencia.']);
        $idPedido = filter_var($datos['id_pedido'] ?? null, FILTER_VALIDATE_INT);
        if (!$idPedido || $idPedido < 1) respuesta(422, ['error'=>'El número de pedido no es válido.']);
        $conexion->beginTransaction();
        try {
            $qPedido = $conexion->prepare("SELECT p.estado AS estado_pedido, v.id_venta, v.total, COALESCE(pc.metodo_pago, '') AS metodo_pago
                FROM pedidos p INNER JOIN ventas v ON v.id_pedido = p.id_pedido
                LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido WHERE p.id_pedido = ? AND p.id_cliente = ? FOR UPDATE");
            $qPedido->execute([$idPedido, (int)$_SESSION['tm_cliente_id']]);
            $pedido = $qPedido->fetch(PDO::FETCH_ASSOC);
            if (!$pedido) throw new RuntimeException('No se encontró la venta asociada a este pedido.');
            if ($pedido['estado_pedido'] === 'Cancelado') throw new RuntimeException('No puedes registrar una transferencia para un pedido cancelado.');
            if (strpos($pedido['metodo_pago'], 'Transferencia') !== 0) throw new RuntimeException('Este pedido no fue creado con el método de transferencia.');
            $qPago = $conexion->prepare('SELECT id_pago, estado_pago FROM pago WHERE id_venta = ? ORDER BY id_pago LIMIT 1 FOR UPDATE');
            $qPago->execute([(int)$pedido['id_venta']]);
            $pagoExistente = $qPago->fetch(PDO::FETCH_ASSOC);
            if ($pagoExistente && $pagoExistente['estado_pago'] === 'Pagado') {
                $conexion->commit();
                respuesta(200, ['ok'=>true, 'estado_pago'=>'Pagado', 'message'=>'Este pago ya estaba confirmado.']);
            }
            if ($pagoExistente) {
                $updPago = $conexion->prepare("UPDATE pago SET metodo_pago = ?, monto_pagado = 0, fecha_pago = CURDATE(), estado_pago = 'Pendiente de verificación' WHERE id_pago = ?");
                $updPago->execute([$pedido['metodo_pago'], (int)$pagoExistente['id_pago']]);
            } else {
                $insPago = $conexion->prepare("INSERT INTO pago (id_venta, metodo_pago, monto_pagado, fecha_pago, estado_pago) VALUES (?, ?, 0, CURDATE(), 'Pendiente de verificación')");
                $insPago->execute([(int)$pedido['id_venta'], $pedido['metodo_pago']]);
            }
            $conexion->commit();
            respuesta(200, ['ok'=>true, 'estado_pago'=>'Pendiente de verificación', 'message'=>'Transferencia de prueba registrada. El pago queda pendiente de verificación del administrador.']);
        } catch (Throwable $e) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            respuesta(422, ['error'=>$e instanceof RuntimeException ? $e->getMessage() : 'No se pudo registrar la transferencia de prueba.']);
        }
    }

    if ($accion === 'confirmar_pago') {
        $esCliente = !empty($_SESSION['tm_cliente_id']);
        if (!$esCliente) tm_pedidos_require_module($conexion, 'pagos');
        $idPedido = filter_var($datos['id_pedido'] ?? null, FILTER_VALIDATE_INT);
        if (!$idPedido || $idPedido < 1) respuesta(422, ['error'=>'El número de pedido no es válido.']);
        $conexion->beginTransaction();
        try {
            $sqlPedido = "SELECT p.estado AS estado_pedido, v.id_venta, v.total, COALESCE(pc.metodo_pago, 'No especificado') AS metodo_pago
                FROM pedidos p INNER JOIN ventas v ON v.id_pedido = p.id_pedido
                LEFT JOIN pedido_contacto pc ON pc.id_pedido = p.id_pedido
                WHERE p.id_pedido = ?".($esCliente ? " AND p.id_cliente = ?" : "")." FOR UPDATE";
            $qPedido = $conexion->prepare($sqlPedido);
            $paramsPedido = [$idPedido]; if ($esCliente) $paramsPedido[] = (int)$_SESSION['tm_cliente_id'];
            $qPedido->execute($paramsPedido);
            $pedido = $qPedido->fetch(PDO::FETCH_ASSOC);
            if (!$pedido) throw new RuntimeException('No se encontró la venta asociada a este pedido.');
            if ($pedido['estado_pedido'] === 'Cancelado') throw new RuntimeException('No puedes confirmar el pago de un pedido cancelado.');
            if ($esCliente && strpos($pedido['metodo_pago'], 'Tarjeta') !== 0) throw new RuntimeException('Solo puedes confirmar desde aquí el pago de demostración con tarjeta. Para otros métodos, sigue las instrucciones del pedido.');
            if (strpos($pedido['metodo_pago'], 'Contraentrega') === 0 && $pedido['estado_pedido'] !== 'Entregado') {
                throw new RuntimeException('En contraentrega, primero marca el pedido como Entregado y después confirma el cobro recibido.');
            }
            $qPago = $conexion->prepare('SELECT id_pago, estado_pago FROM pago WHERE id_venta = ? ORDER BY id_pago LIMIT 1 FOR UPDATE');
            $qPago->execute([(int)$pedido['id_venta']]);
            $pagoExistente = $qPago->fetch(PDO::FETCH_ASSOC);
            if ($pagoExistente && $pagoExistente['estado_pago'] === 'Pagado') {
                $conexion->commit();
                respuesta(200, ['ok'=>true, 'estado_pago'=>'Pagado', 'message'=>'Este pago ya estaba confirmado. No se duplicó el registro.']);
            }
            if ($pagoExistente) {
                $updPago = $conexion->prepare("UPDATE pago SET metodo_pago = ?, monto_pagado = ?, fecha_pago = CURDATE(), estado_pago = 'Pagado' WHERE id_pago = ?");
                $updPago->execute([$pedido['metodo_pago'], $pedido['total'], (int)$pagoExistente['id_pago']]);
            } else {
                $insPago = $conexion->prepare("INSERT INTO pago (id_venta, metodo_pago, monto_pagado, fecha_pago, estado_pago) VALUES (?, ?, ?, CURDATE(), 'Pagado')");
                $insPago->execute([(int)$pedido['id_venta'], $pedido['metodo_pago'], $pedido['total']]);
            }
            $conexion->commit();
            respuesta(200, ['ok'=>true, 'estado_pago'=>'Pagado', 'message'=>'Pago confirmado y guardado en MySQL.']);
        } catch (Throwable $e) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            respuesta(422, ['error'=>$e instanceof RuntimeException ? $e->getMessage() : 'No se pudo confirmar el pago.']);
        }
    }

    if ($accion === 'estado') {
        tm_pedidos_require_module($conexion, 'pedidos');
        $idPedido = filter_var($datos['id_pedido'] ?? null, FILTER_VALIDATE_INT);
        $nuevoEstado = texto($datos['estado'] ?? '', 40);
        $estados = ['Pendiente','Confirmado','En preparación','Enviado','Entregado','Cancelado'];
        if (!$idPedido || $idPedido < 1 || !in_array($nuevoEstado, $estados, true)) respuesta(422, ['error'=>'Pedido o estado inválido.']);
        $conexion->beginTransaction();
        try {
            $q = $conexion->prepare('SELECT estado FROM pedidos WHERE id_pedido = ? FOR UPDATE');
            $q->execute([$idPedido]);
            $estadoActual = $q->fetchColumn();
            if ($estadoActual === false) throw new RuntimeException('No se encontró el pedido.');
            if ($estadoActual === 'Cancelado' && $nuevoEstado !== 'Cancelado') throw new RuntimeException('Un pedido cancelado no se puede reactivar desde esta pantalla.');
            if ($nuevoEstado === 'Cancelado' && in_array($estadoActual, ['Enviado','Entregado'], true)) throw new RuntimeException('No se puede cancelar un pedido que ya fue enviado o entregado. Gestiona una devolución.');
            if ($nuevoEstado === 'Cancelado' && $estadoActual !== 'Cancelado') {
                $qPagoConfirmado = $conexion->prepare("SELECT COUNT(*) FROM ventas v INNER JOIN pago pg ON pg.id_venta = v.id_venta WHERE v.id_pedido = ? AND pg.estado_pago = 'Pagado'");
                $qPagoConfirmado->execute([$idPedido]);
                if ((int)$qPagoConfirmado->fetchColumn() > 0) throw new RuntimeException('Este pedido tiene un pago confirmado. No se puede cancelar automáticamente; registra primero una devolución o reembolso.');
            }
            if ($nuevoEstado === 'Cancelado' && $estadoActual !== 'Cancelado') {
                $qDetalles = $conexion->prepare('SELECT id_producto, cantidad FROM detalle_de_pedido WHERE id_pedido = ? ORDER BY id_producto FOR UPDATE');
                $qDetalles->execute([$idPedido]);
                $detalles = $qDetalles->fetchAll(PDO::FETCH_ASSOC);
                $qStock = $conexion->prepare('SELECT stock FROM productos WHERE id_producto = ? FOR UPDATE');
                $updStock = $conexion->prepare('UPDATE productos SET stock = stock + ? WHERE id_producto = ?');
                $updFecha = $conexion->prepare('UPDATE inventario SET fecha_ultima_actualizacion = CURDATE() WHERE id_producto = ?');
                $logEntrada = $conexion->prepare("INSERT INTO movimientos_inventario (id_producto, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?, 'ENTRADA', ?, ?, ?, ?)");
                foreach ($detalles as $detalle) {
                    $idProducto = (int)$detalle['id_producto']; $cantidad = (int)$detalle['cantidad'];
                    $qStock->execute([$idProducto]); $antes = $qStock->fetchColumn();
                    if ($antes === false) throw new RuntimeException('No se encontró un producto del pedido al devolver stock.');
                    $antes = (int)$antes; $despues = $antes + $cantidad;
                    $updStock->execute([$cantidad, $idProducto]); $updFecha->execute([$idProducto]);
                    $logEntrada->execute([$idProducto, $cantidad, $antes, $despues, 'Reintegro por cancelación pedido #'.$idPedido]);
                }
            }
            $upd = $conexion->prepare('UPDATE pedidos SET estado = ? WHERE id_pedido = ?');
            $upd->execute([$nuevoEstado, $idPedido]);
            $conexion->commit();
            respuesta(200, ['ok'=>true, 'estado'=>$nuevoEstado, 'message'=>$nuevoEstado === 'Cancelado' && $estadoActual !== 'Cancelado' ? 'Pedido cancelado y stock reintegrado.' : 'Estado del pedido actualizado.']);
        } catch (Throwable $e) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            respuesta(422, ['error'=>$e instanceof RuntimeException ? $e->getMessage() : 'No se pudo actualizar el pedido.']);
        }
    }
    respuesta(400, ['error'=>'Acción no reconocida.']);
} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) $conexion->rollBack();
    error_log('Error en pedidos/ventas TechMarket: '.$e->getMessage());
    respuesta(500, ['error'=>'No se pudo procesar la operación. Verifica la conexión y que las tablas CLIENTE, UBICACION, PEDIDOS, DETALLE_DE_PEDIDO, VENTAS, STAFF y PRODUCTOS existan.']);
}
