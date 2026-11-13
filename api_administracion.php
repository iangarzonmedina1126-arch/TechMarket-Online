<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/seguridad.php';
require_once __DIR__ . '/config/borrado_dependencias.php';
$apiUser = tm_require_roles($conexion, ['Jefe','Administrador','Empleado']);
tm_require_same_origin_for_post();

function out(int $status, array $body): void { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function tableExists(PDO $db, string $table): bool { $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'); $q->execute([$table]); return (int)$q->fetchColumn()>0; }
function colExists(PDO $db, string $table, string $col): bool { $q=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'); $q->execute([$table,$col]); return (int)$q->fetchColumn()>0; }
function ensureSchema(PDO $db): void {
  // Migraciones pequeñas e idempotentes para las tablas que ya existen.
  $adds=[
    ['usuario','rol',"ALTER TABLE usuario ADD COLUMN rol VARCHAR(30) NOT NULL DEFAULT 'Cliente'"],
    ['staff','id_usuario','ALTER TABLE staff ADD COLUMN id_usuario INT NULL'],
    ['ubicacion','estado',"ALTER TABLE ubicacion ADD COLUMN estado TINYINT NOT NULL DEFAULT 1"],
  ];
  foreach($adds as [$t,$c,$sql]) if(tableExists($db,$t)&&!colExists($db,$t,$c)) $db->exec($sql);
  $db->exec("CREATE TABLE IF NOT EXISTS detalle_pedido_proveedor (
    id_detalle_pedido_proveedor INT AUTO_INCREMENT PRIMARY KEY,
    id_pedido_proveedor INT NOT NULL,
    id_producto INT NOT NULL,
    cantidad INT NOT NULL,
    costo_unitario DECIMAL(12,2) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    INDEX idx_det_prov_pedido(id_pedido_proveedor),
    CONSTRAINT fk_det_prov_pedido FOREIGN KEY(id_pedido_proveedor) REFERENCES pedidos_proveedor(id_pedido_proveedor),
    CONSTRAINT fk_det_prov_producto FOREIGN KEY(id_producto) REFERENCES productos(id_producto)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    tipo ENUM('ENTRADA','SALIDA') NOT NULL,
    cantidad INT NOT NULL,
    stock_anterior INT NOT NULL,
    stock_nuevo INT NOT NULL,
    motivo VARCHAR(255) NULL,
    fecha_movimiento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mov_inv_producto (id_producto),
    CONSTRAINT fk_mov_inv_producto_admin FOREIGN KEY (id_producto) REFERENCES productos(id_producto)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS auditoria_techmarket (
    id_auditoria BIGINT AUTO_INCREMENT PRIMARY KEY,
    modulo VARCHAR(60) NOT NULL,
    accion VARCHAR(60) NOT NULL,
    registro_id BIGINT NULL,
    detalle VARCHAR(500) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function audit(PDO $db,string $mod,string $act,?int $id,string $detail=''): void { try{$q=$db->prepare('INSERT INTO auditoria_techmarket(modulo,accion,registro_id,detalle) VALUES(?,?,?,?)');$q->execute([$mod,$act,$id,$detail]);}catch(Throwable $e){} }
function stateInt($v): int { return in_array(mb_strtolower(trim((string)$v)),['activo','1','true','sí','si'],true)?1:0; }
function idFromLabel(PDO $db,string $table,string $idCol,string $nameCol,string $value): int {
  $value=trim($value); if(ctype_digit($value) && (int)$value>0) return (int)$value;
  if(preg_match('/#(\d+)/',$value,$m)) return (int)$m[1];
  if($table==='cliente'){$q=$db->prepare("SELECT id_cliente FROM cliente WHERE numero_documento=? OR CONCAT(nombre,' ',apellidos)=? LIMIT 1");$q->execute([$value,$value]);}else{$q=$db->prepare("SELECT `$idCol` FROM `$table` WHERE `$nameCol`=? LIMIT 1");$q->execute([$value]);}$id=$q->fetchColumn(); if(!$id) throw new RuntimeException("No se encontró el registro relacionado: $value.");return (int)$id;
}
try {
 ensureSchema($conexion);
 if($_SERVER['REQUEST_METHOD']==='GET') {
  $v=$_GET['vista']??'';
  $moduleForView = $v==='historial_cliente' ? 'clientes' : ($v==='detalle_pedido_proveedor' ? 'pedidos_proveedor' : $v);
  if($v==='usuarios' && ($apiUser['rol']??'')!=='Jefe') out(403,['error'=>'Solo el Jefe puede consultar usuarios y roles.']);
  if ($moduleForView!=='' && !tm_can_module($apiUser['rol'], $moduleForView)) out(403,['error'=>'Tu rol no tiene permiso para consultar este módulo.']);
  if($v==='productos') { $r=$conexion->query("SELECT id_producto,nombre,precio,stock,estado FROM productos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); out(200,['rows'=>$r]); }
  if($v==='detalle_pedido_proveedor') { $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);if(!$id||$id<1)out(422,['error'=>'Pedido no válido.']);$q=$conexion->prepare('SELECT d.id_detalle_pedido_proveedor,pr.nombre AS producto,d.cantidad,d.costo_unitario,d.total FROM detalle_pedido_proveedor d JOIN productos pr ON pr.id_producto=d.id_producto WHERE d.id_pedido_proveedor=? ORDER BY d.id_detalle_pedido_proveedor');$q->execute([$id]);out(200,['rows'=>$q->fetchAll(PDO::FETCH_ASSOC)]);}
  if($v==='historial_cliente') {
   $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT); if(!$id||$id<1) out(422,['error'=>'Cliente no válido.']);
   $q=$conexion->prepare("SELECT v.id_venta,v.id_pedido,v.numero_comprobante,v.fecha_venta,v.total,p.estado AS estado_pedido,COALESCE(pg.estado_pago,'Pendiente') AS estado_pago FROM ventas v JOIN pedidos p ON p.id_pedido=v.id_pedido LEFT JOIN (SELECT id_venta,GROUP_CONCAT(DISTINCT estado_pago ORDER BY id_pago SEPARATOR ', ') AS estado_pago FROM pago GROUP BY id_venta) pg ON pg.id_venta=v.id_venta WHERE v.id_cliente IN (SELECT c2.id_cliente FROM cliente c2 JOIN cliente base ON base.id_cliente=? WHERE c2.numero_documento=base.numero_documento) ORDER BY v.id_venta DESC");$q->execute([$id]);out(200,['rows'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  }
  $sqls=[
   'usuarios'=>"SELECT id_usuario,correo,fecha_registro,IF(estado=1,'Activo','Inactivo') estado,rol FROM usuario WHERE rol <> 'Cliente' ORDER BY id_usuario DESC",
   'clientes'=>"SELECT c.id_cliente,COALESCE(u.correo,'') correo_usuario,c.tipo_documento,c.numero_documento,COALESCE(c.correo,'') correo,COALESCE(c.telefono,'') telefono,c.fecha_registro,IF(c.estado=1,'Activo','Inactivo') estado FROM cliente c LEFT JOIN usuario u ON u.id_usuario=c.id_usuario WHERE c.id_cliente=(SELECT MIN(c2.id_cliente) FROM cliente c2 WHERE c2.numero_documento=c.numero_documento OR (c2.numero_documento IS NULL AND c.numero_documento IS NULL)) ORDER BY c.id_cliente DESC",
   'categorias'=>"SELECT id_categoria,COALESCE(id_categoria_principal,''),nombre,COALESCE(descripcion,''),IF(estado=1,'Activo','Inactivo') estado FROM categorias ORDER BY id_categoria",
   'proveedores'=>"SELECT id_proveedor,numero_documento_nit,nombre_empresa,COALESCE(persona_contacto,''),COALESCE(telefono,''),COALESCE(correo,''),COALESCE(direccion,''),IF(estado=1,'Activo','Inactivo') estado FROM proveedor ORDER BY id_proveedor DESC",
   'staff'=>"SELECT s.id_usuario_staff,COALESCE(u.correo,''),s.cargo,COALESCE(s.telefono,''),s.fecha_ingreso,IF(s.estado=1,'Activo','Inactivo') estado FROM staff s LEFT JOIN usuario u ON u.id_usuario=s.id_usuario ORDER BY s.id_usuario_staff DESC",
   'direcciones'=>"SELECT u.id_ubicacion,CONCAT(c.nombre,' ',c.apellidos),u.direccion_linea,u.ciudad,u.departamento,COALESCE(u.codigo_postal,''),COALESCE(u.referencia,''),IF(COALESCE(u.estado,1)=1,'Activo','Inactivo') FROM ubicacion u JOIN cliente c ON c.id_cliente=u.id_cliente ORDER BY u.id_ubicacion DESC",
   'servicio'=>"SELECT t.id_ticket,CONCAT(c.nombre,' ',c.apellidos),COALESCE(u.correo,s.cargo),COALESCE(v.numero_comprobante,''),t.asunto,COALESCE(t.descripcion,''),t.prioridad,t.estado,t.fecha_apertura,COALESCE(t.fecha_cierre,'') FROM servicio_al_cliente t JOIN cliente c ON c.id_cliente=t.id_cliente JOIN staff s ON s.id_usuario_staff=t.id_usuario_staff LEFT JOIN usuario u ON u.id_usuario=s.id_usuario LEFT JOIN ventas v ON v.id_venta=t.id_venta ORDER BY t.id_ticket DESC",
   'pedidos_proveedor'=>"SELECT pp.id_pedido_proveedor,p.nombre_empresa,COALESCE(u.correo,s.cargo,'Empleado'),pp.fecha_orden,pp.estado_pedido,pp.costo_total FROM pedidos_proveedor pp JOIN proveedor p ON p.id_proveedor=pp.id_proveedor LEFT JOIN staff s ON s.id_usuario_staff=pp.id_usuario_staff LEFT JOIN usuario u ON u.id_usuario=s.id_usuario ORDER BY pp.id_pedido_proveedor DESC",
  ];
  if(!isset($sqls[$v])) out(400,['error'=>'Vista administrativa no reconocida.']);
  $rows=$conexion->query($sqls[$v])->fetchAll(PDO::FETCH_NUM);out(200,['rows'=>$rows]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST') out(405,['error'=>'Método no permitido.']);
 $d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))out(400,['error'=>'JSON inválido.']);
 $action=(string)($d['action']??'');
 $moduleForAction = in_array($action,['crear_pedido_proveedor','cancelar_pedido_proveedor','recibir_pedido_proveedor','eliminar_pedido_proveedor'],true) ? 'pedidos_proveedor' : ((in_array($action,['guardar_modulo','estado','eliminar_modulo'],true)) ? ((string)($d['modulo']??'')==='garantias'?'devoluciones':(string)($d['modulo']??'')) : '');
 if($moduleForAction!=='' && !tm_can_module($apiUser['rol'],$moduleForAction)) out(403,['error'=>'Tu rol no tiene permiso para modificar este módulo.']);
 if(($action==='guardar_modulo' || $action==='estado') && ($d['modulo']??'')==='usuarios' && ($apiUser['rol']??'')!=='Jefe') out(403,['error'=>'Solo el Jefe puede administrar usuarios y roles.']);
 if($action==='eliminar_pedido_proveedor' && ($apiUser['rol']??'')!=='Jefe') out(403,['error'=>'Solo el Jefe puede eliminar información.']);
 $action=(string)($d['action']??'');$mod=(string)($d['modulo']??'');$id=(int)($d['id']??0);
 if($action==='eliminar_modulo') {
  if(($apiUser['rol']??'')!=='Jefe')out(403,['error'=>'Solo el Jefe puede eliminar información.']);
  $map=['usuarios'=>['usuario','id_usuario'],'clientes'=>['cliente','id_cliente'],'categorias'=>['categorias','id_categoria'],'proveedores'=>['proveedor','id_proveedor'],'staff'=>['staff','id_usuario_staff'],'direcciones'=>['ubicacion','id_ubicacion'],'servicio'=>['servicio_al_cliente','id_ticket'],'pedidos'=>['pedidos','id_pedido'],'ventas'=>['ventas','id_venta'],'pagos'=>['pago','id_pago'],'inventario'=>['inventario','id_inventario'],'pedidos_proveedor'=>['pedidos_proveedor','id_pedido_proveedor'],'devoluciones'=>['devolucion','id_devolucion'],'garantias'=>['garantia_solicitud','id_garantia']];
  if(!isset($map[$mod])||$id<1)out(422,['error'=>'Módulo o ID no válido para eliminar.']);
  if($mod==='usuarios'){$q=$conexion->prepare("SELECT rol FROM usuario WHERE id_usuario=?");$q->execute([$id]);$targetRole=$q->fetchColumn();if($targetRole==='Cliente')out(422,['error'=>'Las cuentas de clientes se administran en el módulo Clientes.']);if($targetRole==='Jefe')out(422,['error'=>'No se puede eliminar la cuenta Jefe desde este módulo.']);}
  if($mod==='usuarios' && (int)$id===(int)($apiUser['id']??0))out(422,['error'=>'No puedes eliminar tu propio usuario mientras tienes la sesión iniciada.']);
  [$table,$pk]=$map[$mod];
  try{$conexion->beginTransaction();$q=$conexion->prepare("SELECT `$pk` FROM `$table` WHERE `$pk`=? LIMIT 1");$q->execute([$id]);if(!$q->fetchColumn()){ $conexion->rollBack(); out(404,['error'=>'No se encontró el registro que intentas eliminar.']); }$linkedUserId=0;if($mod==='clientes'){$uq=$conexion->prepare('SELECT id_usuario FROM cliente WHERE id_cliente=?');$uq->execute([$id]);$linkedUserId=(int)$uq->fetchColumn();}$visited=[];tm_delete_row_with_dependents($conexion,$table,[$pk=>$id],$visited);if($mod==='clientes'&&$linkedUserId>0){$uq=$conexion->prepare("SELECT rol FROM usuario WHERE id_usuario=?");$uq->execute([$linkedUserId]);$linkedRole=$uq->fetchColumn();if($linkedRole==='Cliente')tm_delete_row_with_dependents($conexion,'usuario',['id_usuario'=>$linkedUserId],$visited);}$conexion->commit();audit($conexion,$mod,'eliminar',$id,'Registro y dependencias eliminados por el Jefe');out(200,['ok'=>true,'message'=>'Registro y dependencias relacionadas eliminados definitivamente.']);}
  catch(PDOException $e){if($conexion->inTransaction())$conexion->rollBack();if((string)$e->getCode()==='23000')out(409,['error'=>'No se pudo completar el borrado de los registros relacionados. La operación fue revertida; revisa las dependencias que la base de datos no permita eliminar.']);throw $e;}catch(Throwable $e){if($conexion->inTransaction())$conexion->rollBack();throw $e;}
 }
 if($action==='eliminar_pedido_proveedor') {
  $order=(int)($d['id_pedido_proveedor']??0);if($order<1)out(422,['error'=>'Pedido a proveedor no válido.']);
  $q=$conexion->prepare('SELECT estado_pedido FROM pedidos_proveedor WHERE id_pedido_proveedor=?');$q->execute([$order]);$status=$q->fetchColumn();if($status===false)out(404,['error'=>'No se encontró el pedido a proveedor.']);if($status==='Recibido')out(409,['error'=>'No se puede eliminar una orden recibida porque ya afectó el inventario y el cálculo de costos.']);
  $conexion->beginTransaction();$conexion->prepare('DELETE FROM detalle_pedido_proveedor WHERE id_pedido_proveedor=?')->execute([$order]);$conexion->prepare('DELETE FROM pedidos_proveedor WHERE id_pedido_proveedor=?')->execute([$order]);$conexion->commit();audit($conexion,'pedidos_proveedor','eliminar',$order,'Orden no recibida eliminada');out(200,['ok'=>true,'message'=>'Orden eliminada definitivamente.']);
 }
 if($action==='estado') {
  $map=['usuarios'=>['usuario','id_usuario'],'clientes'=>['cliente','id_cliente'],'categorias'=>['categorias','id_categoria'],'proveedores'=>['proveedor','id_proveedor'],'staff'=>['staff','id_usuario_staff'],'direcciones'=>['ubicacion','id_ubicacion']];
  if(!isset($map[$mod])||$id<1)out(422,['error'=>'Módulo o ID no válido.']);[$table,$pk]=$map[$mod];$estado=stateInt($d['estado']??0);$q=$conexion->prepare("UPDATE `$table` SET estado=? WHERE `$pk`=?");$q->execute([$estado,$id]);audit($conexion,$mod,'estado',$id,$estado?'Activo':'Inactivo');out(200,['ok'=>true,'message'=>'Estado actualizado en MySQL.']);
 }
 if($action==='guardar_modulo') {
  $v=$d['values']??[];if(!is_array($v))out(422,['error'=>'Datos del formulario inválidos.']);
  $str=static fn($i)=>trim((string)($v[$i]??''));$required=static function($x,$label){if(trim((string)$x)==='')throw new RuntimeException("El campo $label es obligatorio.");};
  switch($mod){
   case 'usuarios':
    $email=filter_var($str(0),FILTER_VALIDATE_EMAIL);$fecha=$str(1)?:date('Y-m-d');$estado=stateInt($str(2));$rol=$str(3)?:'Staff';$pass=(string)($d['password']??'');if(!$email)throw new RuntimeException('Escribe un correo válido.');if(!in_array($rol,['Administrador','Staff'],true))throw new RuntimeException('Rol inválido.');
    if($id){if($pass!==''){if(strlen($pass)<8)throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');$q=$conexion->prepare('UPDATE usuario SET correo=?,fecha_registro=?,estado=?,rol=?,contrasena=? WHERE id_usuario=?');$q->execute([$email,$fecha,$estado,$rol,password_hash($pass,PASSWORD_DEFAULT),$id]);}else{$q=$conexion->prepare('UPDATE usuario SET correo=?,fecha_registro=?,estado=?,rol=? WHERE id_usuario=?');$q->execute([$email,$fecha,$estado,$rol,$id]);}}
    else {if(strlen($pass)<8)throw new RuntimeException('Al crear un usuario, define una contraseña de al menos 8 caracteres.');$q=$conexion->prepare('INSERT INTO usuario(correo,fecha_registro,estado,rol,contrasena) VALUES(?,?,?,?,?)');$q->execute([$email,$fecha,$estado,$rol,password_hash($pass,PASSWORD_DEFAULT)]);$id=(int)$conexion->lastInsertId();}break;
   case 'clientes':
    $accountEmail=filter_var($str(0),FILTER_VALIDATE_EMAIL);$uq=$conexion->prepare("SELECT id_usuario FROM usuario WHERE correo=? AND rol='Cliente' AND estado=1 LIMIT 1");$uq->execute([$accountEmail]);$uid=(int)$uq->fetchColumn();$tipo=$str(1);$doc=$str(2);$email=filter_var($str(3),FILTER_VALIDATE_EMAIL);$tel=$str(4);$fecha=$str(5)?:date('Y-m-d');$estado=stateInt($str(6));if($uid<1)throw new RuntimeException('Selecciona una cuenta de cliente válida.');if(!$email)throw new RuntimeException('Correo del cliente no válido.');$required($tipo,'tipo de documento');$required($doc,'número de documento');
    if($id){$q=$conexion->prepare('UPDATE cliente SET id_usuario=?,tipo_documento=?,numero_documento=?,correo=?,telefono=?,fecha_registro=?,estado=? WHERE id_cliente=?');$q->execute([$uid,$tipo,$doc,$email,$tel,$fecha,$estado,$id]);}else{$q=$conexion->prepare('INSERT INTO cliente(id_usuario,tipo_documento,numero_documento,correo,telefono,fecha_registro,estado) VALUES(?,?,?,?,?,?,?)');$q->execute([$uid,$tipo,$doc,$email,$tel,$fecha,$estado]);$id=(int)$conexion->lastInsertId();}break;
   case 'categorias':
    $parent=$str(0)===''?null:(int)$str(0);$name=$str(1);$desc=$str(2);$state=stateInt($str(3));$required($name,'nombre');if($parent===$id&&$id>0)throw new RuntimeException('Una categoría no puede ser su propia categoría principal.');if($id){$q=$conexion->prepare('UPDATE categorias SET id_categoria_principal=?,nombre=?,descripcion=?,estado=? WHERE id_categoria=?');$q->execute([$parent,$name,$desc,$state,$id]);}else{$q=$conexion->prepare('INSERT INTO categorias(id_categoria_principal,nombre,descripcion,estado) VALUES(?,?,?,?)');$q->execute([$parent,$name,$desc,$state]);$id=(int)$conexion->lastInsertId();}break;
   case 'proveedores':
    $nit=$str(0);$empresa=$str(1);$contacto=$str(2);$tel=$str(3);$email=$str(4);$dir=$str(5);$state=stateInt($str(6));$required($nit,'NIT');$required($empresa,'nombre de empresa');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Correo del proveedor inválido.');if($id){$q=$conexion->prepare('UPDATE proveedor SET numero_documento_nit=?,nombre_empresa=?,persona_contacto=?,telefono=?,correo=?,direccion=?,estado=? WHERE id_proveedor=?');$q->execute([$nit,$empresa,$contacto,$tel,$email,$dir,$state,$id]);}else{$q=$conexion->prepare('INSERT INTO proveedor(numero_documento_nit,nombre_empresa,persona_contacto,telefono,correo,direccion,estado) VALUES(?,?,?,?,?,?,?)');$q->execute([$nit,$empresa,$contacto,$tel,$email,$dir,$state]);$id=(int)$conexion->lastInsertId();}break;
   case 'staff':
    $accountEmail=$str(0);$uid=null;if($accountEmail!==''){$uq=$conexion->prepare("SELECT id_usuario FROM usuario WHERE correo=? AND estado=1 LIMIT 1");$uq->execute([$accountEmail]);$uid=(int)$uq->fetchColumn();if(!$uid)throw new RuntimeException('No se encontró una cuenta activa con ese correo.');}$cargo=$str(1);$tel=$str(2);$fecha=$str(3)?:date('Y-m-d');$state=stateInt($str(4));$required($cargo,'cargo');if($uid!==null&&!$conexion->query('SELECT id_usuario FROM usuario WHERE id_usuario='.(int)$uid)->fetchColumn())throw new RuntimeException('La cuenta de usuario asociada no está disponible.');if($id){$q=$conexion->prepare('UPDATE staff SET id_usuario=?,cargo=?,telefono=?,fecha_ingreso=?,estado=? WHERE id_usuario_staff=?');$q->execute([$uid,$cargo,$tel,$fecha,$state,$id]);}else{$q=$conexion->prepare('INSERT INTO staff(id_usuario,cargo,telefono,fecha_ingreso,estado) VALUES(?,?,?,?,?)');$q->execute([$uid,$cargo,$tel,$fecha,$state]);$id=(int)$conexion->lastInsertId();}break;
   case 'direcciones':
    $cid=idFromLabel($conexion,'cliente','id_cliente','numero_documento',$str(0));$addr=$str(1);$city=$str(2);$dept=$str(3);$postal=$str(4);$ref=$str(5);$state=stateInt($str(6));$required($addr,'dirección');$required($city,'ciudad');$required($dept,'departamento');if($id){$q=$conexion->prepare('UPDATE ubicacion SET id_cliente=?,direccion_linea=?,ciudad=?,departamento=?,codigo_postal=?,referencia=?,estado=? WHERE id_ubicacion=?');$q->execute([$cid,$addr,$city,$dept,$postal,$ref,$state,$id]);}else{$q=$conexion->prepare('INSERT INTO ubicacion(id_cliente,direccion_linea,ciudad,departamento,codigo_postal,referencia,estado) VALUES(?,?,?,?,?,?,?)');$q->execute([$cid,$addr,$city,$dept,$postal,$ref,$state]);$id=(int)$conexion->lastInsertId();}break;
   case 'servicio':
    $cid=idFromLabel($conexion,'cliente','id_cliente','numero_documento',$str(0));$staffValue=$str(1);$sq=$conexion->prepare("SELECT s.id_usuario_staff FROM staff s LEFT JOIN usuario u ON u.id_usuario=s.id_usuario WHERE u.correo=? OR s.cargo=? LIMIT 1");$sq->execute([$staffValue,$staffValue]);$sid=(int)$sq->fetchColumn();$saleValue=$str(2);$vq=$conexion->prepare("SELECT id_venta FROM ventas WHERE numero_comprobante=? LIMIT 1");$vq->execute([$saleValue]);$sale=(int)$vq->fetchColumn();$subject=$str(3);$desc=$str(4);$priority=$str(5);$status=$str(6)?:'Abierto';$opened=$str(7)?:date('Y-m-d');$closed=$str(8)===''?null:$str(8);$required($subject,'asunto');if(!$sid)throw new RuntimeException('Selecciona un empleado existente.');if(!$sale)throw new RuntimeException('Selecciona un comprobante de venta válido.');if(!in_array($priority,['Baja','Media','Alta'],true)||!in_array($status,['Abierto','En proceso','Resuelto','Cerrado'],true))throw new RuntimeException('Prioridad o estado inválido.');if($status==='Cerrado'&&!$closed)$closed=date('Y-m-d');if($id){$q=$conexion->prepare('UPDATE servicio_al_cliente SET id_cliente=?,id_usuario_staff=?,id_venta=?,asunto=?,descripcion=?,prioridad=?,estado=?,fecha_apertura=?,fecha_cierre=? WHERE id_ticket=?');$q->execute([$cid,$sid,$sale,$subject,$desc,$priority,$status,$opened,$closed,$id]);}else{$q=$conexion->prepare('INSERT INTO servicio_al_cliente(id_cliente,id_usuario_staff,id_venta,asunto,descripcion,prioridad,estado,fecha_apertura,fecha_cierre) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$cid,$sid,$sale,$subject,$desc,$priority,$status,$opened,$closed]);$id=(int)$conexion->lastInsertId();}break;
   default: out(422,['error'=>'Este módulo se guarda mediante su API específica o no admite edición.']);
  }
  audit($conexion,$mod,$id?'guardar':'crear',$id,'Registro guardado');out(200,['ok'=>true,'id'=>$id,'message'=>'Cambios guardados en MySQL.']);
 }
 if($action==='crear_pedido_proveedor') {
  $provider=(int)($d['id_proveedor']??0);$staff=(int)($d['id_usuario_staff']??0);$product=(int)($d['id_producto']??0);$qty=filter_var($d['cantidad']??null,FILTER_VALIDATE_INT);$cost=(float)($d['costo_unitario']??0);$date=(string)($d['fecha_orden']??date('Y-m-d'));
  if($provider<1||$staff<1||$product<1||$qty===false||$qty<1||$cost<=0)out(422,['error'=>'Completa proveedor, empleado, producto, cantidad y costo unitario válido.']);
  $conexion->beginTransaction();$check=$conexion->prepare('SELECT id_proveedor FROM proveedor WHERE id_proveedor=? AND estado=1');$check->execute([$provider]);if(!$check->fetchColumn())throw new RuntimeException('El proveedor no existe o está inactivo.');$check=$conexion->prepare('SELECT id_usuario_staff FROM staff WHERE id_usuario_staff=? AND estado=1');$check->execute([$staff]);if(!$check->fetchColumn())throw new RuntimeException('El empleado no existe o está inactivo.');$check=$conexion->prepare('SELECT id_producto FROM productos WHERE id_producto=?');$check->execute([$product]);if(!$check->fetchColumn())throw new RuntimeException('El producto no existe.');$total=$qty*$cost;$q=$conexion->prepare("INSERT INTO pedidos_proveedor(id_proveedor,id_usuario_staff,fecha_orden,estado_pedido,costo_total) VALUES(?,?,?,'Pendiente',?)");$q->execute([$provider,$staff,$date,$total]);$order=(int)$conexion->lastInsertId();$q=$conexion->prepare('INSERT INTO detalle_pedido_proveedor(id_pedido_proveedor,id_producto,cantidad,costo_unitario,total) VALUES(?,?,?,?,?)');$q->execute([$order,$product,$qty,$cost,$total]);$conexion->commit();audit($conexion,'pedidos_proveedor','crear',$order,'Orden de compra creada');out(200,['ok'=>true,'id'=>$order,'message'=>'Pedido al proveedor creado. El inventario no se modifica hasta recibirlo.']);
 }
 if($action==='cancelar_pedido_proveedor') { $order=(int)($d['id_pedido_proveedor']??0);$q=$conexion->prepare('SELECT estado_pedido FROM pedidos_proveedor WHERE id_pedido_proveedor=?');$q->execute([$order]);$status=$q->fetchColumn();if($status===false)throw new RuntimeException('No se encontró el pedido al proveedor.');if($status==='Recibido')throw new RuntimeException('No se puede cancelar un pedido que ya fue recibido.');$conexion->prepare("UPDATE pedidos_proveedor SET estado_pedido='Cancelado' WHERE id_pedido_proveedor=?")->execute([$order]);audit($conexion,'pedidos_proveedor','cancelar',$order,'Pedido cancelado');out(200,['ok'=>true,'message'=>'Pedido cancelado. No se modificó el inventario.']);}
 if($action==='recibir_pedido_proveedor') {
  $order=(int)($d['id_pedido_proveedor']??0);if($order<1)out(422,['error'=>'Pedido no válido.']);$conexion->beginTransaction();$q=$conexion->prepare('SELECT estado_pedido FROM pedidos_proveedor WHERE id_pedido_proveedor=? FOR UPDATE');$q->execute([$order]);$status=$q->fetchColumn();if($status===false)throw new RuntimeException('No se encontró el pedido al proveedor.');if($status==='Recibido')throw new RuntimeException('Este pedido ya fue recibido; no se volverá a sumar el stock.');if($status==='Cancelado')throw new RuntimeException('No se puede recibir un pedido cancelado.');$q=$conexion->prepare('SELECT id_producto,cantidad FROM detalle_pedido_proveedor WHERE id_pedido_proveedor=?');$q->execute([$order]);$items=$q->fetchAll(PDO::FETCH_ASSOC);if(!$items)throw new RuntimeException('El pedido no tiene líneas de producto.');foreach($items as $it){$p=$conexion->prepare('SELECT stock FROM productos WHERE id_producto=? FOR UPDATE');$p->execute([(int)$it['id_producto']]);$old=$p->fetchColumn();if($old===false)throw new RuntimeException('Producto no encontrado al recibir la compra.');$new=(int)$old+(int)$it['cantidad'];$u=$conexion->prepare('UPDATE productos SET stock=? WHERE id_producto=?');$u->execute([$new,(int)$it['id_producto']]);$conexion->prepare('INSERT INTO inventario(id_producto,stock_minimo,stock_maximo,fecha_ultima_actualizacion) SELECT ?,2,20,CURDATE() WHERE NOT EXISTS (SELECT 1 FROM inventario WHERE id_producto=?)')->execute([(int)$it['id_producto'],(int)$it['id_producto']]);$conexion->prepare('UPDATE inventario SET fecha_ultima_actualizacion=CURDATE() WHERE id_producto=?')->execute([(int)$it['id_producto']]);$log=$conexion->prepare("INSERT INTO movimientos_inventario(id_producto,tipo,cantidad,stock_anterior,stock_nuevo,motivo) VALUES(?,'ENTRADA',?,?,?,?)");$log->execute([(int)$it['id_producto'],(int)$it['cantidad'],(int)$old,$new,'Recepción pedido proveedor #'.$order]);}$u=$conexion->prepare("UPDATE pedidos_proveedor SET estado_pedido='Recibido' WHERE id_pedido_proveedor=?");$u->execute([$order]);$conexion->commit();audit($conexion,'pedidos_proveedor','recibir',$order,'Stock actualizado');out(200,['ok'=>true,'message'=>'Pedido recibido. El inventario se actualizó una sola vez.']);
 }
 out(400,['error'=>'Acción no reconocida.']);
} catch(Throwable $e) {
 if(isset($conexion)&&$conexion instanceof PDO&&$conexion->inTransaction())$conexion->rollBack();
 error_log('TechMarket admin API: '.$e->getMessage());$code=$e instanceof RuntimeException?422:500;out($code,['error'=>$e instanceof RuntimeException?$e->getMessage():'No se pudo completar la operación. Verifica el esquema de MySQL y los datos relacionados.']);
}
