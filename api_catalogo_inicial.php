<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__.'/config/conexion.php';
require_once __DIR__.'/config/seguridad.php';
$u=tm_require_roles($conexion,['Jefe']);
tm_require_same_origin_for_post();
function seed_out(int $status,array $data): void { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
$categories = [
 ['Computación',null,'Equipos, periféricos y componentes'],
 ['Laptops','Computación','Portátiles para estudio, trabajo y gaming'],
 ['Computadores de escritorio','Computación','PC de escritorio y estaciones de trabajo'],
 ['Monitores','Computación','Monitores para productividad y entretenimiento'],
 ['Teclados','Computación','Teclados mecánicos, inalámbricos y de oficina'],
 ['Ratones','Computación','Mouse ergonómicos y gaming'],
 ['Accesorios PC','Computación','Bases, hubs, adaptadores y accesorios'],
 ['Almacenamiento','Computación','Discos SSD, discos externos y memorias USB'],
 ['Redes','Computación','Routers, repetidores y conectividad'],
 ['Impresión',null,'Impresoras y consumibles de impresión'],
 ['Impresoras','Impresión','Impresoras láser, tinta y multifuncionales'],
 ['Tintas y cartuchos','Impresión','Tintas originales y compatibles'],
 ['Tóner','Impresión','Tóner para impresoras láser'],
 ['Papel','Impresión','Papel para oficina e impresión'],
 ['Telefonía y audio',null,'Dispositivos móviles y audio personal'],
 ['Celulares','Telefonía y audio','Smartphones liberados y accesorios'],
 ['Audífonos','Telefonía y audio','Audífonos Bluetooth, gaming y de estudio'],
 ['Parlantes','Telefonía y audio','Parlantes portátiles y de escritorio'],
 ['Smartwatches','Telefonía y audio','Relojes inteligentes y bandas deportivas'],
 ['Cargadores','Telefonía y audio','Cargadores de pared, inalámbricos y portátiles'],
 ['Cables','Telefonía y audio','Cables USB, HDMI, red y adaptadores'],
 ['Entretenimiento',null,'Pantallas, consolas y dispositivos multimedia'],
 ['Televisores','Entretenimiento','Smart TV para hogar y oficina'],
 ['Consolas','Entretenimiento','Consolas y accesorios de videojuegos'],
 ['Videojuegos','Entretenimiento','Juegos y accesorios gaming'],
 ['Streaming','Entretenimiento','Dispositivos de streaming y multimedia'],
 ['Software',null,'Licencias de software y seguridad digital'],
 ['Licencias','Software','Software de productividad y sistemas'],
 ['Antivirus','Software','Seguridad y protección digital'],
 ['Productividad','Software','Herramientas para trabajo y estudio'],
 ['Hogar inteligente',null,'Seguridad y automatización del hogar'],
 ['Cámaras de seguridad','Hogar inteligente','Cámaras IP y vigilancia'],
 ['Domótica','Hogar inteligente','Enchufes, luces y sensores inteligentes']
];
$products = [
 ['Laptops','Lenovo IdeaPad Slim 3 15.6',2199900,14,'Portátil para estudio y oficina, 8 GB RAM y SSD.','photo-1496181133206-80ce9b88a853'],
 ['Laptops','ASUS VivoBook 15',2599900,9,'Portátil delgado para productividad diaria.','photo-1517336714731-489689fd1ca8'],
 ['Laptops','HP Pavilion 14',3299900,7,'Portátil versátil para trabajo, estudio y videollamadas.','photo-1588872657578-7efd1f1555ed'],
 ['Laptops','Acer Nitro V Gaming',4299900,5,'Portátil gaming con gráficos dedicados y pantalla de alta frecuencia.','photo-1593642632823-8f785ba67e45'],
 ['Laptops','Dell Inspiron 16',3899900,6,'Pantalla amplia para multitarea y productividad.','photo-1496181133206-80ce9b88a853'],
 ['Laptops','MacBook Air 13',5299900,4,'Portátil ligero para creación y productividad.','photo-1517336714731-489689fd1ca8'],
 ['Laptops','MacBook Pro 14',8999900,2,'Equipo de alto rendimiento para desarrollo y edición.','photo-1517336714731-489689fd1ca8'],
 ['Computadores de escritorio','PC Oficina Intel Core i5',2499900,8,'Computador de escritorio para oficina con SSD.','photo-1587202372775-e229f172b9d7'],
 ['Computadores de escritorio','PC Gamer Ryzen 5 RTX',4699900,3,'Torre gaming para juegos actuales y streaming.','photo-1587202372775-e229f172b9d7'],
 ['Computadores de escritorio','Mini PC empresarial',1899900,6,'Equipo compacto para espacios de trabajo reducidos.','photo-1593642632823-8f785ba67e45'],
 ['Monitores','Monitor LG 24 pulgadas IPS',649900,16,'Monitor Full HD con panel IPS para oficina y estudio.','photo-1527443224154-c4a3942d3acf'],
 ['Monitores','Monitor Samsung 27 pulgadas QHD',1199900,8,'Monitor QHD con gran espacio de trabajo.','photo-1527443224154-c4a3942d3acf'],
 ['Monitores','Monitor Gamer 165 Hz 27 pulgadas',1399900,5,'Alta frecuencia de actualización para gaming.','photo-1527443224154-c4a3942d3acf'],
 ['Teclados','Teclado mecánico RGB',219900,18,'Teclado mecánico con retroiluminación RGB.','photo-1587829741301-dc798b83add3'],
 ['Teclados','Teclado inalámbrico compacto',129900,20,'Teclado inalámbrico para escritorio minimalista.','photo-1587829741301-dc798b83add3'],
 ['Ratones','Mouse Logitech inalámbrico',89900,25,'Mouse inalámbrico para oficina y uso diario.','photo-1527814050087-3793815479db'],
 ['Ratones','Mouse Gamer RGB 7200 DPI',139900,19,'Sensor de alta precisión y botones programables.','photo-1527814050087-3793815479db'],
 ['Accesorios PC','Hub USB-C 7 en 1',179900,15,'Amplía puertos con HDMI, USB y lector de tarjetas.','photo-1625842268584-8f3296236761'],
 ['Accesorios PC','Base refrigerante para portátil',119900,12,'Base con ventilación para mejorar la comodidad de uso.','photo-1525547719571-a2d4ac8945e2'],
 ['Almacenamiento','SSD NVMe 1 TB',329900,12,'Unidad de estado sólido de alta velocidad.','photo-1597872200969-2b65d56bd16b'],
 ['Almacenamiento','SSD SATA 480 GB',189900,16,'Actualización rápida para portátiles y equipos de escritorio.','photo-1597872200969-2b65d56bd16b'],
 ['Almacenamiento','Disco externo 2 TB',349900,10,'Almacenamiento portátil para copias de seguridad.','photo-1597872200969-2b65d56bd16b'],
 ['Almacenamiento','Memoria USB 128 GB',69900,30,'Memoria USB para transportar archivos y documentos.','photo-1597872200969-2b65d56bd16b'],
 ['Redes','Router Wi-Fi 6 doble banda',329900,11,'Conectividad estable para hogares y pequeñas oficinas.',''],
 ['Redes','Adaptador USB Wi-Fi',79900,20,'Conecta equipos a redes inalámbricas.','photo-1625842268584-8f3296236761'],
 ['Impresoras','HP LaserJet Pro monocromática',1099900,8,'Impresión láser rápida para pequeños negocios.','photo-1612815154858-60aa4c59eaa6'],
 ['Impresoras','Epson EcoTank multifuncional',999900,12,'Impresión, escaneo y copiado con sistema de tinta continua.','photo-1612815154858-60aa4c59eaa6'],
 ['Impresoras','Canon Pixma Wi-Fi',499900,10,'Impresora a color para hogar y estudio.','photo-1612815154858-60aa4c59eaa6'],
 ['Impresoras','Brother láser dúplex',899900,7,'Impresión a doble cara para oficina.','photo-1612815154858-60aa4c59eaa6'],
 ['Tintas y cartuchos','Kit tinta Epson 4 colores',159900,22,'Kit de tintas para impresoras compatibles.','photo-1612815154858-60aa4c59eaa6'],
 ['Tintas y cartuchos','Cartucho HP negro',89900,24,'Cartucho negro para documentos y textos.','photo-1612815154858-60aa4c59eaa6'],
 ['Tintas y cartuchos','Cartucho HP tricolor',119900,18,'Cartucho de color para documentos e imágenes.','photo-1612815154858-60aa4c59eaa6'],
 ['Tóner','Tóner compatible HP 85A',79900,20,'Tóner compatible para impresión monocromática.','photo-1612815154858-60aa4c59eaa6'],
 ['Papel','Resma papel carta 500 hojas',18900,100,'Papel tamaño carta para impresión y fotocopiado.','photo-1586075010923-2c464827d0ef'],
 ['Celulares','Samsung Galaxy A55 5G',1699900,8,'Smartphone con pantalla AMOLED y conectividad 5G.','photo-1511707171634-5f897ff02aa9'],
 ['Celulares','Samsung Galaxy S24 FE',2799900,5,'Smartphone de alto rendimiento para fotografía y entretenimiento.','photo-1511707171634-5f897ff02aa9'],
 ['Celulares','iPhone 15 128 GB',3499900,4,'Smartphone con cámara avanzada y alto rendimiento.','photo-1511707171634-5f897ff02aa9'],
 ['Celulares','Xiaomi Redmi Note 13',999900,11,'Smartphone con pantalla amplia y batería de larga duración.','photo-1511707171634-5f897ff02aa9'],
 ['Celulares','Motorola Edge 50 Fusion',1399900,7,'Smartphone equilibrado para trabajo y entretenimiento.','photo-1511707171634-5f897ff02aa9'],
 ['Audífonos','Audífonos Bluetooth con ANC',249900,18,'Audio inalámbrico con cancelación de ruido.','photo-1505740420928-5e560c06d30e'],
 ['Audífonos','Headset gamer con micrófono',189900,16,'Auriculares para partidas, llamadas y streaming.','photo-1599669454699-248893623440'],
 ['Audífonos','Audífonos USB-C',69900,22,'Audífonos con cable para dispositivos compatibles.','photo-1505740420928-5e560c06d30e'],
 ['Parlantes','Parlante Bluetooth portátil',199900,13,'Parlante compacto para escuchar música en cualquier lugar.','photo-1608043152269-423dbba4e7e1'],
 ['Parlantes','Barra de sonido para TV',449900,6,'Mejora el audio del televisor con conexión digital.','photo-1608043152269-423dbba4e7e1'],
 ['Smartwatches','Smartwatch deportivo',229900,14,'Seguimiento de actividad, notificaciones y monitoreo diario.','photo-1523275335684-37898b6baf30'],
 ['Cargadores','Cargador USB-C 45 W',99900,20,'Cargador compacto de carga rápida para equipos compatibles.','photo-1583863788434-e58a36330cf0'],
 ['Cargadores','Power bank 20000 mAh',159900,15,'Batería portátil para viajes y jornadas largas.','photo-1609091839311-d5365f9ff1c5'],
 ['Cables','Cable USB-C reforzado 2 m',29900,30,'Cable de carga y datos de uso diario.','photo-1583863788434-e58a36330cf0'],
 ['Cables','Cable HDMI 2.1 2 m',39900,25,'Conecta televisores, monitores y consolas compatibles.','photo-1625842268584-8f3296236761'],
 ['Televisores','Smart TV 43 pulgadas 4K',1399900,6,'Televisor 4K con aplicaciones de streaming.','photo-1593359677879-a4bb92f829d1'],
 ['Televisores','Smart TV 55 pulgadas 4K',2199900,4,'Pantalla 4K para cine en casa y videojuegos.','photo-1593359677879-a4bb92f829d1'],
 ['Televisores','Smart TV 65 pulgadas QLED',3299900,2,'Pantalla grande con colores vivos y funciones inteligentes.','photo-1593359677879-a4bb92f829d1'],
 ['Consolas','Consola PlayStation 5',2799900,3,'Consola de nueva generación para videojuegos.','photo-1486572788966-cfd3df1f5b42'],
 ['Consolas','Control inalámbrico para consola',299900,8,'Control inalámbrico adicional para juegos compatibles.','photo-1486572788966-cfd3df1f5b42'],
 ['Videojuegos','Controlador arcade USB',199900,5,'Control tipo arcade para juegos retro compatibles.','photo-1486572788966-cfd3df1f5b42'],
 ['Streaming','Dispositivo streaming 4K',249900,12,'Convierte tu televisor en un centro de entretenimiento.','photo-1593359677879-a4bb92f829d1'],
 ['Licencias','Microsoft 365 Personal 12 meses',269900,20,'Suscripción de productividad para uso personal.','photo-1633113214698-485cdb4c9f8a'],
 ['Licencias','Microsoft 365 Familia 12 meses',349900,15,'Herramientas de productividad para varios usuarios.','photo-1633113214698-485cdb4c9f8a'],
 ['Antivirus','Licencia antivirus 1 dispositivo',99900,25,'Protección digital para un equipo durante un año.','photo-1563013544-824ae1b704d3'],
 ['Antivirus','Licencia seguridad 3 dispositivos',169900,18,'Protección multidispositivo para el hogar.','photo-1563013544-824ae1b704d3'],
 ['Productividad','Licencia editor PDF anual',129900,12,'Herramientas para crear, organizar y firmar documentos PDF.','photo-1633113214698-485cdb4c9f8a'],
 ['Cámaras de seguridad','Cámara IP Wi-Fi interior',159900,14,'Cámara para monitoreo interior desde una aplicación.','photo-1557324232-b8917d3c3dcb'],
 ['Cámaras de seguridad','Kit 4 cámaras de seguridad',799900,4,'Kit de vigilancia para hogar o pequeño negocio.','photo-1557324232-b8917d3c3dcb'],
 ['Domótica','Enchufe inteligente Wi-Fi',59900,20,'Controla dispositivos compatibles desde el móvil.','photo-1558002038-1055907df827'],
 ['Domótica','Bombillo inteligente RGB',49900,24,'Iluminación inteligente con control de color y horario.','photo-1558002038-1055907df827']
];
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')seed_out(405,['error'=>'Método no permitido.']);
 $body=json_decode(file_get_contents('php://input'),true);if(($body['action']??'')!=='seed')seed_out(400,['error'=>'Acción no reconocida.']);
 foreach(['imagen_url'=>"ALTER TABLE productos ADD COLUMN imagen_url VARCHAR(500) NULL",'descuento_porcentaje'=>"ALTER TABLE productos ADD COLUMN descuento_porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0",'costo_compra'=>"ALTER TABLE productos ADD COLUMN costo_compra DECIMAL(12,2) NULL DEFAULT NULL"] as $col=>$ddl){$q=$conexion->query("SHOW COLUMNS FROM productos LIKE '$col'");if(!$q->fetch(PDO::FETCH_ASSOC))$conexion->exec($ddl);}
 $conexion->beginTransaction();$categoryIds=[];$categoriesAdded=0;$productsAdded=0;
 foreach($categories as [$name,$parentName,$description]){
  $q=$conexion->prepare('SELECT id_categoria FROM categorias WHERE nombre=? ORDER BY id_categoria LIMIT 1');$q->execute([$name]);$id=$q->fetchColumn();
  if(!$id){$parentId=null;if($parentName!==null){$parentId=$categoryIds[$parentName]??null;if(!$parentId){$pq=$conexion->prepare('SELECT id_categoria FROM categorias WHERE nombre=? ORDER BY id_categoria LIMIT 1');$pq->execute([$parentName]);$parentId=$pq->fetchColumn()?:null;}}$ins=$conexion->prepare('INSERT INTO categorias(id_categoria_principal,nombre,descripcion,estado) VALUES(?,?,?,1)');$ins->execute([$parentId,$name,$description]);$id=(int)$conexion->lastInsertId();$categoriesAdded++;}
  $categoryIds[$name]=(int)$id;
 }
 foreach($products as [$category,$name,$price,$stock,$description,$photo]){
  $q=$conexion->prepare('SELECT id_producto,costo_compra FROM productos WHERE nombre=? LIMIT 1');$q->execute([$name]);$existing=$q->fetch(PDO::FETCH_ASSOC);if($existing){if($existing['costo_compra']===null || (float)$existing['costo_compra']<=0){$estimated=round((float)$price*0.75,2);$upd=$conexion->prepare('UPDATE productos SET costo_compra=? WHERE id_producto=? AND (costo_compra IS NULL OR costo_compra<=0)');$upd->execute([$estimated,(int)$existing['id_producto']]);}continue;}
  $catId=$categoryIds[$category]??null;if(!$catId)continue;
  $image='';if($photo!=='')$image='https://images.unsplash.com/'.$photo.'?auto=format&fit=crop&w=800&q=82';
  $estimatedCost=round((float)$price*0.75,2);$ins=$conexion->prepare('INSERT INTO productos(id_categoria,nombre,precio,stock,descripcion,estado,imagen_url,descuento_porcentaje,costo_compra) VALUES(?,?,?,?,?,1,?,0,?)');$ins->execute([$catId,$name,$price,$stock,$description,$image,$estimatedCost]);$productsAdded++;
 }
 $conexion->commit();seed_out(200,['ok'=>true,'categorias_agregadas'=>$categoriesAdded,'productos_agregados'=>$productsAdded,'message'=>'Catálogo inicial agregado sin reemplazar registros existentes. Los costos de los productos de ejemplo son estimaciones iniciales equivalentes al 75% del precio de venta; verifica con las facturas de tus proveedores.']);
} catch(Throwable $e){if($conexion->inTransaction())$conexion->rollBack();error_log('TechMarket catálogo inicial: '.$e->getMessage());seed_out(500,['error'=>'No se pudo cargar el catálogo. Verifica que las tablas categorias y productos tengan las columnas esperadas.']);}
