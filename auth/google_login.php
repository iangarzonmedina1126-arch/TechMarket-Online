<?php
session_start();
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/google.php";
$autoload = __DIR__ . "/../vendor/autoload.php";
if (!is_file($autoload)) {
 http_response_code(503);
 respuesta(false, "El inicio de sesión con Google requiere instalar la dependencia de Google en el hosting.");
}
require_once $autoload;

function respuesta($ok, $mensaje, $destino = null) {
 echo json_encode(["success"=>$ok, "message"=>$mensaje, "redirect"=>$destino]); exit;
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); respuesta(false,"Método no permitido."); }
if (empty($_POST["csrf_token"]) || empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])) {
 http_response_code(403); respuesta(false,"Solicitud no válida. Recarga la página.");
}
$credential = $_POST["credential"] ?? "";
if (!$credential) respuesta(false,"No se recibió la credencial de Google.");

try {
 $google = new Google\Client(["client_id"=>GOOGLE_CLIENT_ID]);
 $payload = $google->verifyIdToken($credential);
 if (!$payload) respuesta(false,"No se pudo verificar la cuenta de Google.");

 $googleId = $payload["sub"] ?? "";
 $correo = strtolower($payload["email"] ?? "");
 $nombre = $payload["name"] ?? "Usuario";
 $foto = $payload["picture"] ?? null;
 if (!$googleId || !$correo || empty($payload["email_verified"])) {
  respuesta(false,"Google no confirmó una dirección de correo válida.");
 }

 $conexion->beginTransaction();
 $q = $conexion->prepare("SELECT * FROM usuario WHERE google_id=? LIMIT 1");
 $q->execute([$googleId]); $usuario = $q->fetch();

 if (!$usuario) {
  $q = $conexion->prepare("SELECT id_usuario FROM usuario WHERE correo=? LIMIT 1");
  $q->execute([$correo]);
  if ($q->fetch()) {
   $conexion->rollBack();
   respuesta(false,"Ya existe una cuenta con este correo. Ingresa con tu método habitual.");
  }
  $q = $conexion->prepare("INSERT INTO usuario
   (contrasena, correo, fecha_registro, estado, google_id, nombre, foto_perfil)
   VALUES (NULL, ?, CURDATE(), 1, ?, ?, ?)");
  $q->execute([$correo,$googleId,$nombre,$foto]);
  $id = (int)$conexion->lastInsertId();
  $q = $conexion->prepare("SELECT * FROM usuario WHERE id_usuario=?");
  $q->execute([$id]); $usuario = $q->fetch();
 } else {
  if ((int)$usuario["estado"] !== 1) {
   $conexion->rollBack(); respuesta(false,"Esta cuenta está desactivada.");
  }
  $q = $conexion->prepare("UPDATE usuario SET nombre=?, foto_perfil=? WHERE id_usuario=?");
  $q->execute([$nombre,$foto,$usuario["id_usuario"]]);
 }
 if ((int)$usuario["estado"] !== 1) {
  $conexion->rollBack(); respuesta(false,"Esta cuenta está desactivada.");
 }
 $q = $conexion->prepare("SELECT id_cliente FROM cliente WHERE id_usuario=? LIMIT 1");
 $q->execute([$usuario["id_usuario"]]); $perfil = $q->fetch();
 $conexion->commit();

 session_regenerate_id(true);
 $_SESSION["id_usuario"] = (int)$usuario["id_usuario"];
 $_SESSION["correo"] = $correo;
 $_SESSION["nombre"] = $nombre;
 $_SESSION["id_cliente"] = $perfil ? (int)$perfil["id_cliente"] : null;
 unset($_SESSION["csrf_token"]);
 respuesta(true,"Sesión iniciada.",$perfil ? "../index.php" : "../completar_perfil.php");
} catch (Throwable $e) {
 if (isset($conexion) && $conexion->inTransaction()) $conexion->rollBack();
 error_log("Google login: ".$e->getMessage());
 http_response_code(500); respuesta(false,"Ocurrió un error al iniciar sesión.");
}
