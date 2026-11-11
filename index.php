
<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechMarket</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<main class="login-container">
    <a class="brand" href="index.php">TechMarket</a>

    <?php if (!empty($_SESSION["id_usuario"])): ?>

        <h1>¡Hola, <?= htmlspecialchars($_SESSION["nombre"] ?? "usuario", ENT_QUOTES, "UTF-8") ?>!</h1>

        <p><?= htmlspecialchars($_SESSION["correo"] ?? "", ENT_QUOTES, "UTF-8") ?></p>

        <p>Has iniciado sesión correctamente.</p>

        <a class="logout" href="logout.php">Cerrar sesión</a>

    <?php else: ?>

        <h1>Bienvenido a TechMarket</h1>

        <p>Explora nuestra tienda de tecnología.</p>

        <a class="logout" href="login.php">Iniciar sesión</a>

    <?php endif; ?>

</main>

</body>
</html>
