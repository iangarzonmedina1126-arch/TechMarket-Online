<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__.'/config/conexion.php';
require_once __DIR__.'/config/seguridad.php';
tm_ensure_role_column($conexion);
tm_require_same_origin_for_post();
function auth_out(int $status,array $data): void { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function ownerExists(PDO $db): bool { $q=$db->query("SELECT COUNT(*) FROM usuario WHERE rol='Jefe' AND estado=1"); return (int)$q->fetchColumn()>0; }
$action=$_GET['action']??'';
if ($_SERVER['REQUEST_METHOD']==='GET') {
    if ($action==='status') {
        $u=tm_current_user($conexion);
        auth_out(200,['owner_exists'=>ownerExists($conexion),'needs_setup'=>!ownerExists($conexion),'authenticated'=>(bool)$u,'user'=>$u]);
    }
    if ($action==='me') {
        $u=tm_current_user($conexion);
        if (!$u) auth_out(401,['authenticated'=>false,'error'=>'Sesión empresarial no iniciada.']);
        auth_out(200,['authenticated'=>true,'user'=>$u]);
    }
    auth_out(400,['error'=>'Acción no reconocida.']);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') auth_out(405,['error'=>'Método no permitido.']);
$d=json_decode(file_get_contents('php://input'),true);
if (!is_array($d)) auth_out(400,['error'=>'Solicitud inválida.']);
$action=(string)($d['action']??'');
if ($action==='setup_owner') {
    $ip=$_SERVER['REMOTE_ADDR']??'';
    if (!in_array($ip,['127.0.0.1','::1'],true)) auth_out(403,['error'=>'La creación inicial del jefe solo se permite desde este equipo con XAMPP.']);
    if (ownerExists($conexion)) auth_out(409,['error'=>'El jefe ya está configurado. Inicia sesión.']);
    $email=filter_var(trim((string)($d['correo']??'')),FILTER_VALIDATE_EMAIL);
    $pass=(string)($d['contrasena']??'');
    if (!$email || strlen($pass)<12) auth_out(422,['error'=>'Usa un correo válido y una contraseña de al menos 12 caracteres.']);
    $q=$conexion->prepare('SELECT id_usuario FROM usuario WHERE correo=? LIMIT 1');$q->execute([$email]);
    if ($q->fetchColumn()) auth_out(409,['error'=>'Ese correo ya existe. Usa otro correo para crear la cuenta Jefe.']);
    $q=$conexion->prepare("INSERT INTO usuario(correo,contrasena,fecha_registro,estado,rol) VALUES(?,?,CURDATE(),1,'Jefe')");
    $q->execute([$email,password_hash($pass,PASSWORD_DEFAULT)]);
    session_regenerate_id(true);unset($_SESSION['tm_cliente_id']);$_SESSION['tm_user_id']=(int)$conexion->lastInsertId();
    auth_out(201,['ok'=>true,'user'=>['id'=>(int)$_SESSION['tm_user_id'],'correo'=>$email,'rol'=>'Jefe'],'message'=>'Cuenta Jefe creada. Guarda tus credenciales en un lugar seguro.']);
}
if ($action==='recover_owner') {
    if (ownerExists($conexion)) auth_out(409,['error'=>'Ya existe una cuenta Jefe activa. Inicia sesión normalmente.']);
    $email=filter_var(trim((string)($d['correo']??'')),FILTER_VALIDATE_EMAIL);
    $pass=(string)($d['contrasena']??'');
    if (!$email || $pass==='') auth_out(422,['error'=>'Escribe el correo y la contraseña actual de tu cuenta.']);
    $q=$conexion->prepare("SELECT id_usuario,correo,contrasena,estado,rol FROM usuario WHERE correo=? LIMIT 1");
    $q->execute([$email]);
    $u=$q->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['estado']!==1 || !in_array($u['rol'],['Administrador','Empleado','Staff'],true)) auth_out(401,['error'=>'No se encontró una cuenta empresarial activa para recuperar.']);
    $valid=password_verify($pass,(string)$u['contrasena']);
    if (!$valid && hash_equals((string)$u['contrasena'],$pass)) {
        $valid=true;
        $upd=$conexion->prepare('UPDATE usuario SET contrasena=? WHERE id_usuario=?');
        $upd->execute([password_hash($pass,PASSWORD_DEFAULT),(int)$u['id_usuario']]);
    }
    if (!$valid) auth_out(401,['error'=>'La contraseña actual no es correcta.']);
    $conexion->beginTransaction();
    $lock=$conexion->query("SELECT id_usuario FROM usuario WHERE rol='Jefe' AND estado=1 LIMIT 1 FOR UPDATE")->fetchColumn();
    if ($lock) { $conexion->rollBack(); auth_out(409,['error'=>'Ya existe una cuenta Jefe activa. Inicia sesión normalmente.']); }
    $upd=$conexion->prepare("UPDATE usuario SET rol='Jefe' WHERE id_usuario=? AND estado=1 AND rol IN ('Administrador','Empleado','Staff')");
    $upd->execute([(int)$u['id_usuario']]);
    if ($upd->rowCount()!==1) { $conexion->rollBack(); auth_out(409,['error'=>'No se pudo recuperar la cuenta. Actualiza la página e inténtalo de nuevo.']); }
    $conexion->commit();
    session_regenerate_id(true);unset($_SESSION['tm_cliente_id']);$_SESSION['tm_user_id']=(int)$u['id_usuario'];
    auth_out(200,['ok'=>true,'user'=>['id'=>(int)$u['id_usuario'],'correo'=>$u['correo'],'rol'=>'Jefe'],'message'=>'La cuenta existente recuperó el rol Jefe. La contraseña se mantuvo sin cambios.']);
}
if ($action==='login') {
    $email=filter_var(trim((string)($d['correo']??'')),FILTER_VALIDATE_EMAIL);$pass=(string)($d['contrasena']??'');
    if (!$email || $pass==='') auth_out(422,['error'=>'Escribe tu correo empresarial y contraseña.']);
    if (!empty($_SESSION['tm_login_block_until']) && time() < (int)$_SESSION['tm_login_block_until']) auth_out(429,['error'=>'Demasiados intentos. Espera un minuto antes de volver a intentarlo.']);
    $q=$conexion->prepare('SELECT id_usuario,correo,contrasena,estado,rol FROM usuario WHERE correo=? LIMIT 1');$q->execute([$email]);$u=$q->fetch(PDO::FETCH_ASSOC);
    $valid=false;
    if ($u && (int)$u['estado']===1 && in_array($u['rol'],['Jefe','Administrador','Empleado','Staff'],true)) {
        $valid=password_verify($pass,(string)$u['contrasena']);
        // Migra de forma segura las contraseñas antiguas que estuvieran guardadas en texto plano.
        if (!$valid && hash_equals((string)$u['contrasena'],$pass)) { $valid=true; $upd=$conexion->prepare('UPDATE usuario SET contrasena=? WHERE id_usuario=?');$upd->execute([password_hash($pass,PASSWORD_DEFAULT),(int)$u['id_usuario']]); }
    }
    if (!$valid) {
        $_SESSION['tm_login_attempts']=(int)($_SESSION['tm_login_attempts']??0)+1;
        if ($_SESSION['tm_login_attempts']>=6) { $_SESSION['tm_login_block_until']=time()+60;$_SESSION['tm_login_attempts']=0; }
        usleep(250000);
        auth_out(401,['error'=>'Correo o contraseña incorrectos, cuenta inactiva o cuenta sin rol empresarial.']);
    }
    session_regenerate_id(true);unset($_SESSION['tm_cliente_id']);$_SESSION['tm_user_id']=(int)$u['id_usuario'];unset($_SESSION['tm_login_attempts'],$_SESSION['tm_login_block_until']);
    $role=$u['rol']==='Staff'?'Empleado':$u['rol'];
    auth_out(200,['ok'=>true,'user'=>['id'=>(int)$u['id_usuario'],'correo'=>$u['correo'],'rol'=>$role],'message'=>'Sesión empresarial iniciada.']);
}
if ($action==='logout') { $_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();auth_out(200,['ok'=>true,'message'=>'Sesión cerrada.']); }
if ($action==='create_staff') {
    $owner=tm_require_roles($conexion,['Jefe']);
    $email=filter_var(trim((string)($d['correo']??'')),FILTER_VALIDATE_EMAIL);$pass=(string)($d['contrasena']??'');$role=(string)($d['rol']??'Administrador');
    if (!$email || strlen($pass)<12 || !in_array($role,['Administrador','Empleado'],true)) auth_out(422,['error'=>'Correo válido, contraseña de 12 caracteres o más y rol Administrador o Empleado requeridos.']);
    $q=$conexion->prepare('SELECT id_usuario FROM usuario WHERE correo=? LIMIT 1');$q->execute([$email]);if($q->fetchColumn())auth_out(409,['error'=>'Ya existe una cuenta con ese correo.']);
    $q=$conexion->prepare('INSERT INTO usuario(correo,contrasena,fecha_registro,estado,rol) VALUES(?,?,CURDATE(),1,?)');$q->execute([$email,password_hash($pass,PASSWORD_DEFAULT),$role]);
    auth_out(201,['ok'=>true,'id'=>(int)$conexion->lastInsertId(),'message'=>'Cuenta empresarial creada. Entrega la contraseña directamente al trabajador.']);
}
auth_out(400,['error'=>'Acción no reconocida.']);
