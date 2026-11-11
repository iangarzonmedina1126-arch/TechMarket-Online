<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__.'/config/conexion.php';
try {
    $sql = "SELECT c.id_categoria,c.id_categoria_principal,c.nombre,c.descripcion,c.estado
            FROM categorias c
            WHERE c.estado=1
            ORDER BY COALESCE(c.id_categoria_principal,c.id_categoria), c.id_categoria_principal, c.nombre";
    $q=$conexion->query($sql);
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    error_log('TechMarket categorías: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'No se pudieron consultar las categorías.']);
}
