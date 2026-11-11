<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__.'/config/conexion.php';
require_once __DIR__.'/config/seguridad.php';
$u=tm_require_roles($conexion,['Jefe','Administrador']);
tm_require_same_origin_for_post();
function offer_out(int $status,array $data): void { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
try {
  $q=$conexion->query("SHOW COLUMNS FROM productos LIKE 'descuento_porcentaje'");
  if(!$q->fetch(PDO::FETCH_ASSOC)) $conexion->exec('ALTER TABLE productos ADD COLUMN descuento_porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0');
  if($_SERVER['REQUEST_METHOD']!=='POST') offer_out(405,['error'=>'Método no permitido.']);
  $d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))offer_out(400,['error'=>'Solicitud inválida.']);
  $id=filter_var($d['id_producto']??null,FILTER_VALIDATE_INT);$discount=filter_var($d['descuento_porcentaje']??null,FILTER_VALIDATE_INT);
  if(!$id||$id<1||$discount===false||$discount<0||$discount>90)offer_out(422,['error'=>'El producto debe existir y el descuento debe estar entre 0% y 90%.']);
  $q=$conexion->prepare('SELECT id_producto,nombre,precio,estado FROM productos WHERE id_producto=?');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)offer_out(404,['error'=>'No se encontró el producto.']);
  $q=$conexion->prepare('UPDATE productos SET descuento_porcentaje=? WHERE id_producto=?');$q->execute([$discount,$id]);
  $final=round((float)$p['precio']*(1-$discount/100),2);
  offer_out(200,['ok'=>true,'id_producto'=>$id,'descuento_porcentaje'=>$discount,'precio_original'=>(float)$p['precio'],'precio_final'=>$final,'ahorro'=>round((float)$p['precio']-$final,2),'message'=>$discount?'Descuento guardado.':'Descuento retirado.']);
} catch(Throwable $e){error_log('TechMarket ofertas: '.$e->getMessage());offer_out(500,['error'=>'No se pudo guardar el descuento. Revisa la conexión y las columnas de productos.']);}
