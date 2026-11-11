<?php
session_start();
if (!empty($_SESSION["id_usuario"])) { header("Location: index.php"); exit; }
if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
require_once "config/google.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión | TechMarket</title>
<link rel="stylesheet" href="assets/css/login.css">
<script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
<main class="login-container">
<a class="brand" href="index.php">TechMarket</a>
<p class="tagline">Tu tienda de tecnología</p>
<h1>Bienvenido</h1>
<p class="intro">Inicia sesión con tu cuenta de Google para continuar.</p>
<div id="google-button"></div><p id="mensaje" role="alert"></p>
</main>
<script>
window.addEventListener("load", () => {
 if (!window.google?.accounts?.id) {
   document.getElementById("mensaje").textContent = "No se pudo cargar Google. Revisa tu conexión.";
   return;
 }
 google.accounts.id.initialize({
   client_id: <?= json_encode(GOOGLE_CLIENT_ID) ?>,
   callback: manejarGoogle
 });
 google.accounts.id.renderButton(document.getElementById("google-button"), {
   theme:"outline", size:"large", shape:"rectangular", text:"continue_with", width:300
 });
});
async function manejarGoogle(response) {
 const mensaje = document.getElementById("mensaje");
 mensaje.textContent = "Verificando cuenta...";
 const datos = new URLSearchParams();
 datos.append("credential", response.credential);
 datos.append("csrf_token", <?= json_encode($_SESSION["csrf_token"]) ?>);
 try {
  const r = await fetch("auth/google_login.php", {
   method:"POST",
   headers:{"Content-Type":"application/x-www-form-urlencoded"},
   body:datos
  });
  const resultado = await r.json();
  if (resultado.success) location.href = resultado.redirect;
  else mensaje.textContent = resultado.message;
 } catch(e) { mensaje.textContent = "No fue posible conectar con el servidor."; }
}
</script>
</body></html>
