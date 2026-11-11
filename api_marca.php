<?php
header('Cache-Control: no-store');
require_once __DIR__.'/config/conexion.php';
require_once __DIR__.'/config/seguridad.php';
function marca_out(int $status,array $data): void { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
$dir=__DIR__.'/uploads/branding';$meta=$dir.'/logo.json';
$data=[];if(is_file($meta)){ $raw=json_decode((string)file_get_contents($meta),true); if(is_array($raw))$data=$raw; }
if($_SERVER['REQUEST_METHOD']==='GET'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['logo_url'=>$data['logo_url']??null,'catalog_logo_url'=>$data['catalog_logo_url']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')marca_out(405,['error'=>'Método no permitido.']);
try{
 tm_require_roles($conexion,['Jefe']);tm_require_same_origin_for_post();
 if(empty($_FILES['logo'])||$_FILES['logo']['error']!==UPLOAD_ERR_OK)marca_out(422,['error'=>'Selecciona una imagen válida.']);
 $f=$_FILES['logo'];if($f['size']>4*1024*1024)marca_out(422,['error'=>'La imagen debe pesar máximo 4 MB.']);
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$exts=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];if(!isset($exts[$mime]))marca_out(422,['error'=>'El archivo debe ser una imagen JPG, PNG, WEBP o GIF.']);
 if(!is_dir($dir)&&!mkdir($dir,0755,true))marca_out(500,['error'=>'No se pudo crear la carpeta de imágenes.']);
 $type=($_POST['tipo']??'principal')==='catalogo'?'catalog_logo_url':'logo_url';$name=($type==='catalog_logo_url'?'catalogo-':'logo-').bin2hex(random_bytes(8)).'.'.$exts[$mime];if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))marca_out(500,['error'=>'No se pudo guardar la imagen.']);
 $url='uploads/branding/'.$name;$old=$data[$type]??null;$data[$type]=$url;$tmp=$meta.'.tmp';
 if(file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX)===false){@unlink($dir.'/'.$name);marca_out(500,['error'=>'No se pudo guardar la configuración del logo.']);}rename($tmp,$meta);
 if(is_string($old)&&str_starts_with($old,'uploads/branding/')&&basename($old)!==basename($url)){@unlink(__DIR__.'/'.$old);}
 marca_out(200,['ok'=>true,'logo_url'=>$data['logo_url']??null,'catalog_logo_url'=>$data['catalog_logo_url']??null,'message'=>'Logo actualizado correctamente.']);
}catch(Throwable $e){error_log('TechMarket branding upload: '.$e->getMessage());marca_out(500,['error'=>'No se pudo actualizar el logo. Verifica permisos de escritura en uploads/branding.']);}
