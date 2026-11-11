<?php
require_once __DIR__ . "/config/conexion.php";

try {
    $consulta = $conexion->prepare(
        "SELECT id_producto, nombre, precio, stock, descripcion, estado
         FROM productos
         ORDER BY id_producto ASC"
    );

    $consulta->execute();
    $productos = $consulta->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("No se pudieron cargar los productos.");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos | TechMarket Online</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            background: #eef1f7;
            color: #222;
        }

        h1 {
            color: #3f51b5;
        }

        .productos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .producto {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px #00000015;
        }

        .precio {
            color: #3f51b5;
            font-size: 22px;
            font-weight: bold;
        }

        .estado {
            color: #287a3d;
        }
    </style>
</head>
<body>

    <h1>TechMarket Online</h1>
    <h2>Catálogo de productos</h2>

    <?php if (count($productos) > 0): ?>
        <div class="productos">

            <?php foreach ($productos as $producto): ?>
                <?php if ((int)$producto["estado"] === 1): ?>
                    <div class="producto">
                        <h3>
                            <?= htmlspecialchars($producto["nombre"]) ?>
                        </h3>

                        <p>
                            <?= htmlspecialchars($producto["descripcion"] ?? "") ?>
                        </p>

                        <p class="precio">
                            $<?= number_format((float)$producto["precio"], 0, ",", ".") ?>
                        </p>

                        <p>
                            Stock: <?= (int)$producto["stock"] ?>
                        </p>

                        <p class="estado">Disponible</p>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

        </div>
    <?php else: ?>
        <p>No hay productos registrados o disponibles.</p>
    <?php endif; ?>

</body>
</html>
