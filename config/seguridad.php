<?php
// Seguridad de sesiones para el panel administrativo de TechMarket Online.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
function tm_json_error(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function tm_ensure_role_column(PDO $db): void {
    $q=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuario' AND column_name='rol'");
    $q->execute();
    if (!(int)$q->fetchColumn()) $db->exec("ALTER TABLE usuario ADD COLUMN rol VARCHAR(30) NOT NULL DEFAULT 'Cliente'");
}
function tm_current_user(PDO $db): ?array {
    if (empty($_SESSION['tm_user_id'])) return null;
    $q=$db->prepare('SELECT id_usuario, correo, rol, estado FROM usuario WHERE id_usuario=? LIMIT 1');
    $q->execute([(int)$_SESSION['tm_user_id']]);
    $u=$q->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['estado'] !== 1 || !in_array($u['rol'], ['Jefe','Administrador','Empleado','Staff'], true)) {
        $_SESSION=[];
        if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']); }
        session_destroy();
        return null;
    }
    if ($u['rol']==='Staff') $u['rol']='Empleado';
    $_SESSION['tm_role']=$u['rol'];
    return ['id'=>(int)$u['id_usuario'],'correo'=>$u['correo'],'rol'=>$u['rol']];
}
function tm_require_roles(PDO $db, array $roles=['Jefe','Administrador','Empleado']): array {
    tm_ensure_role_column($db);
    $u=tm_current_user($db);
    if (!$u) tm_json_error(401,'Debes iniciar sesión con una cuenta empresarial para acceder a esta función.');
    if (!in_array($u['rol'],$roles,true)) tm_json_error(403,'Tu cuenta no tiene permisos para realizar esta acción.');
    return $u;
}
function tm_can_module(string $role, string $module): bool {
    if ($role==='Jefe') return true;
    if ($role==='Administrador') return !in_array($module,['usuarios','staff'],true);
    $allowed=['pedidos','ventas','pagos','inventario','devoluciones','servicio','clientes','direcciones'];
    return in_array($module,$allowed,true);
}
function tm_require_same_origin_for_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $origin=$_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin==='') return;
    $host=$_SERVER['HTTP_HOST'] ?? '';
    $originHost=parse_url($origin, PHP_URL_HOST);
    $originPort=parse_url($origin, PHP_URL_PORT);
    $hostOnly=preg_replace('/:\d+$/','',$host);
    if (!$originHost || strcasecmp($originHost,$hostOnly)!==0) tm_json_error(403,'Solicitud rechazada por validación de origen.');
    if ($originPort && strpos($host,':')!==false && (int)$originPort !== (int)substr(strrchr($host,':'),1)) tm_json_error(403,'Solicitud rechazada por validación de origen.');
}
