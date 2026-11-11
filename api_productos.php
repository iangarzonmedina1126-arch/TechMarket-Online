<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config/conexion.php';

try {
    foreach (['imagen_url'=>"ALTER TABLE productos ADD COLUMN imagen_url VARCHAR(500) NULL", 'descuento_porcentaje'=>"ALTER TABLE productos ADD COLUMN descuento_porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0"] as $col=>$ddl) { $q=$conexion->query("SHOW COLUMNS FROM productos LIKE '$col'"); if(!$q->fetch(PDO::FETCH_ASSOC)) $conexion->exec($ddl); }
    $imageSelect = 'p.imagen_url';
    $sql = "SELECT p.id_producto, p.nombre, p.precio, p.stock, p.descripcion, p.estado, $imageSelect, p.descuento_porcentaje, c.nombre AS categoria
            FROM productos p
            LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
            ORDER BY p.id_producto ASC";
    $stmt = $conexion->query($sql);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron consultar los productos de MySQL.']);
}
