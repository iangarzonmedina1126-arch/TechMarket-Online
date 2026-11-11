<?php
session_start();
require_once "config/conexion.php";
if (empty($_SESSION["id_usuario"])) { header("Location: login.php"); exit; }
$errores = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
 $tipo = $_POST["tipo_documento"] ?? "";
 $numero = trim($_POST["numero_documento"] ?? "");
 $telefono = trim($_POST["telefono"] ?? "");
 if (!in_array($tipo, ["CC","CE","NIT","Pasaporte"], true)) $errores[] = "Selecciona un tipo de documento.";
 if ($numero === "" || strlen($numero)>255) $errores[] = "Ingresa un documento válido.";
 if ($telefono === "" || strlen($telefono)>255) $errores[] = "Ingresa un teléfono válido.";
 if (!$errores) {
  try {
   $q = $conexion->prepare("INSERT INTO cliente
    (id_usuario,tipo_documento,numero_documento,correo,telefono,fecha_registro,estado)
    VALUES (?,?,?,?,?,CURDATE(),1)");
   $q->execute([$_SESSION["id_usuario"],$tipo,$numero,$_SESSION["correo"],$telefono]);
   $_SESSION["id_cliente"] = (int)$conexion->lastInsertId();
   header("Location: index.php"); exit;
  } catch (PDOException $e) {
   $errores[] = $e->getCode()==="23000" ? "El documento ya está registrado." : "No fue posible guardar los datos.";
   error_log($e->getMessage());
  }
 }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Completar perfil | TechMarket</title><link rel="stylesheet" href="assets/css/login.css"></head><body>
<main class="login-container"><a class="brand" href="index.php">TechMarket</a><h1>Completa tu perfil</h1>
<p class="intro">Necesitamos estos datos para asociar tus compras a tu cuenta.</p>
<?php foreach($errores as $e): ?><p class="error"><?= htmlspecialchars($e,ENT_QUOTES,"UTF-8") ?></p><?php endforeach; ?>
<form method="POST" class="profile-form">
<label>Tipo de documento</label><select name="tipo_documento" required>
<option value="">Selecciona</option><option value="CC">Cédula de ciudadanía</option><option value="CE">Cédula de extranjería</option><option value="NIT">NIT</option><option value="Pasaporte">Pasaporte</option></select>
<label>Número de documento</label><input name="numero_documento" maxlength="255" required>
<label>Teléfono</label><input type="tel" name="telefono" maxlength="255" required>
<button type="submit">Guardar y continuar</button></form></main></body></html>
