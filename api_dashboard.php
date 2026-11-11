<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';
$usuario = tm_require_roles($conexion, ['Jefe','Administrador','Empleado']);

function dashboardOut(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    // Se agregan los pagos por venta antes de unirlos para evitar duplicar ingresos.
    $pagosPorVenta = "SELECT id_venta,
        SUM(CASE WHEN estado_pago='Pagado' THEN monto_pagado ELSE 0 END) AS total_pagado
        FROM pago GROUP BY id_venta";

    $sqlResumen = "SELECT COUNT(*) AS cantidad_ventas,
        COALESCE(SUM(v.total),0) AS total_ventas,
        COALESCE(SUM(LEAST(v.total,COALESCE(pg.total_pagado,0))),0) AS total_pagado,
        COALESCE(SUM(GREATEST(v.total-COALESCE(pg.total_pagado,0),0)),0) AS valor_pendiente
        FROM ventas v LEFT JOIN ($pagosPorVenta) pg ON pg.id_venta=v.id_venta";
    $resumen = $conexion->query($sqlResumen)->fetch(PDO::FETCH_ASSOC) ?: [];

    $sqlMeses = "SELECT DATE_FORMAT(v.fecha_venta,'%Y-%m') AS mes,
        COUNT(*) AS cantidad,
        COALESCE(SUM(v.total),0) AS total,
        COALESCE(SUM(LEAST(v.total,COALESCE(pg.total_pagado,0))),0) AS total_pagado
        FROM ventas v LEFT JOIN ($pagosPorVenta) pg ON pg.id_venta=v.id_venta
        WHERE v.fecha_venta >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(v.fecha_venta,'%Y-%m') ORDER BY mes";
    $porMes = $conexion->query($sqlMeses)->fetchAll(PDO::FETCH_ASSOC);

    $indicadores = [
        'clientes' => (int)$conexion->query('SELECT COUNT(*) FROM cliente')->fetchColumn(),
        'productos' => (int)$conexion->query('SELECT COUNT(*) FROM productos WHERE estado=1')->fetchColumn(),
        'pedidos_pendientes' => (int)$conexion->query("SELECT COUNT(*) FROM pedidos WHERE estado='Pendiente'")->fetchColumn(),
        'stock_bajo' => (int)$conexion->query('SELECT COUNT(*) FROM productos WHERE estado=1 AND stock<=3')->fetchColumn(),
        'devoluciones_pendientes' => (int)$conexion->query("SELECT COUNT(*) FROM devolucion WHERE estado IN ('Solicitud recibida','En revisión')")->fetchColumn(),
        'tickets_abiertos' => (int)$conexion->query("SELECT COUNT(*) FROM servicio_al_cliente WHERE estado='Abierto'")->fetchColumn(),
    ];
    dashboardOut(200, ['resumen'=>$resumen, 'por_mes'=>$porMes, 'indicadores'=>$indicadores]);
} catch (Throwable $e) {
    error_log('TechMarket dashboard: '.$e->getMessage());
    dashboardOut(500, ['error'=>'No se pudo consultar el resumen. Verifica que las tablas de ventas, pagos, pedidos y productos estén disponibles.']);
}
