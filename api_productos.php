<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config/conexion.php';

try {
    foreach (['imagen_url'=>"ALTER TABLE productos ADD COLUMN imagen_url VARCHAR(500) NULL", 'descuento_porcentaje'=>"ALTER TABLE productos ADD COLUMN descuento_porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0"] as $col=>$ddl) { $q=$conexion->query("SHOW COLUMNS FROM productos LIKE '$col'"); if(!$q->fetch(PDO::FETCH_ASSOC)) $conexion->exec($ddl); }
    $imageSelect = 'p.imagen_url';
    $sql = "SELECT p.id_producto, p.nombre, p.precio, p.stock, p.descripcion, p.estado, $imageSelect, p.descuento_porcentaje, c.nombre AS categoria
            FROM productos p
            LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
            ORDER BY p.id_producto ASC";
    $stmt = $conexion->query($sql);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Fotografías de catálogo: solo se sobrescribe imagen_url en la respuesta pública.
    // Los datos comerciales y los registros de MySQL permanecen intactos.
    $imageOverrides = [
        1  => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=85',
        3  => 'https://images.unsplash.com/photo-1527814050087-3793815479db?auto=format&fit=crop&w=900&q=85',
        4  => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=900&q=85',
        8  => 'https://www.winpy.cl/files/36364-2388-Notebook-Lenovo-IdeaPad-Slim-3-1.jpg',
        9  => 'https://in.store.asus.com/media/catalog/product/x/1/x1502za_quiet_blue_nonfngrprnt_bcklt_black_kbd_1_.jpg',
        10 => 'https://www.hpobchod.sk/library/configuration/product/HP-Pavilion-14-ec-Natural-Silver_0b.jpg',
        11 => 'https://infotechsnepal.com.np/_next/image/?q=75&url=https%3A%2F%2Fapp.infotechsnepal.com.np%2Fwp-content%2Fuploads%2F2023%2F12%2F1-32.jpg&w=1080',
        12 => 'https://i.dell.com/sites/csimages/Product_Imagery/all/notebook-inspiron-16-7620-800x550-gray.png',
        13 => 'https://i0.wp.com/scidgroup.com/wp-content/uploads/2022/08/2022-08-18_194844.jpg?fit=1814%2C1808&ssl=1',
        14 => 'https://sotohit.ru/d/macbook_pro_14_2023_serebristyi.png',
        15 => 'https://e2simages.excess2sell.com/images/pviews/lenovcodede1654163401632_1654163401_view1.png',
        16 => 'https://i5.walmartimages.com/asr/8676946f-a68b-45c3-b2f2-6dc4d4f96c20.2b8cc3a141708b19d85398cfafbd1468.jpeg',
        17 => 'https://www.csl-computer.com/en/media/catalog/product/cache/4/small_image/400x400/9df78eab33525d08d6e5fb8d27136e95/8/6/86938-86939_-_bb_intel_nuc_kit_nuc12wski7-nuc12wski5_02_3000px_13.webp',
        18 => 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?auto=format&fit=crop&w=900&q=85',
        19 => 'https://www.elektroefekt.cz/img/2114/s27a600-qhd-ips-75hz-dp-hdmi-samsung-0--.jpg',
        20 => 'https://images.unsplash.com/photo-1593640408182-31c70c8268f5?auto=format&fit=crop&w=900&q=85',
        21 => 'https://images.unsplash.com/photo-1595225476474-87563907a212?auto=format&fit=crop&w=900&q=85',
        23 => 'https://lntsufin.com/storage/mediafiles/catalog/live/15919-525/original/15919-525_image_0.jpg',
        24 => 'https://ttesports-hbf5d7gvfzb2hqgq.a02.azurefd.net/files/product_Gallery/2019050317172561-47s.jpg',
        25 => 'https://api.esquire.co.za/Resources/Images/PageImage/UH7020C_UN_1.0_overview_port_with_text_large_20250115025036l.jpg',
        26 => 'https://jola.vn/cdn/720/Product/HoQEPdV71/58959b432b1bf7b59a32ed9afdedcd71.jpg',
        27 => 'https://media.mrfix.com.mx/img/art/FIX43739-1000GB%403x.png?t=638803062423676769',
        28 => 'https://www.myoffice.qa/cdn/shop/products/1_9771623c-199e-472c-bd6e-ba366a31fc36_1024x.jpg?v=1600459347',
        29 => 'https://rimage.ripley.com.pe/home.ripley/Attachment/MKP/447/PMP20000572876/thumbnail-1.jpeg',
        30 => 'https://shikostore.com/cdn/shop/files/M400_128GB_3f85e1ab-bbb8-49e2-b83e-71606b55b83e.png?v=1765024846&width=1000',
        31 => 'https://www.geewiz.co.za/238223-large_default/tp-link-ax3000-dual-band-gigabit-wi-fi-6-router.jpg',
        32 => 'https://www.levix.nl/media/18/81/35/1697732687/42f668fbe56080a2927dd1c24de33b65.jpg?ts=1697732687',
        33 => 'https://media.mipc.com.mx/catalog/product/h/p/hp-2z611a_bgj.1.png?height=270&image-type=image&store=default&width=270',
        34 => 'https://cdn-eu.aglty.io/epson/productimages/30164-productpicture-hires-en-et-4850_main_rnn.png',
        35 => 'https://item.tscimg.ca/TSC/7/73/731/0x0/731011.jpg?impolicy=XL',
        36 => 'https://www.daycomp.eu/60740-large_default/brother-laser-mono-printer-hl-l6210dw-50-pages-usb-lan-wifi-duplex-256mb.jpg',
        37 => 'https://www.mediaexpert.pl/media/cache/resolve/filemanager_original/images/descriptions/images/97/971136/storage_app_opisy_epson_876716/2.jpg',
        38 => 'https://cdn.blueinkgroup.com.au/processed/1500x1500/099%20804%20201.jpg',
        39 => 'https://deus.com.gh/media/catalog/product/cache/0fd4067eec299be81c60e324b54af6f0/8/3/836_60tri_.jpg',
        40 => 'https://colmenero.shop/cdn/shop/products/Toner_HP_CE285A_85A_Negro_Compatible_con_LaserJet_Pro.jpg?v=1746039774',
        41 => 'https://d31f1ehqijlcua.cloudfront.net/n/3/f/6/9/3f696d7bbae7ff5dc3e501b9e969a79e03530f3e_PrinterPaper_236197_02.JPG',
        42 => 'https://www.clevercel.co/cdn/shop/files/SamsungGalaxyA555G2024BLACK.webp?crop=center&height=1200&v=1781639745&width=1200',
        43 => 'https://store.gorecell.ca/cdn/shop/files/b0a619ec95a545a196881141dd3a36fe_1.jpg?v=1734112590&width=1500',
        44 => 'https://movilesquality.com/pics/contenido/591db6882d3507c60ad69513262ce1e3_apple-iphone-15-128gb-azul.jpg',
        45 => 'https://www.telecomarmenia.am/eshop/images/product/7/17072240335224.jpeg',
        46 => 'https://cdn.movertix.com/media/catalog/product/m/o/motorola-edge-50-fusion-5g-dual-sim-marshmallow-blue-512gb-and-12gb-ram-xt2429-2_1.jpg',
        48 => 'https://retrogameskw.com/cdn/shop/files/hyperx-cloud-ii-pro-gaming-headset-black-red-headphones-headsets-hyperx-444489.webp?v=1745026133',
        49 => 'https://s.alicdn.com/@sc04/kf/H45c9e0de949947fb830def4010d1851eo/Hi-Fi-Sound-in-Ear-Mic-Strong-Bass-Budget-Friendly-Simple-and-Elegant-Built-in-Microphone-Memory-Foam-Ear-Tips-Wired-Headphones.jpg',
        47 => 'https://myhypergear.com/cdn/shop/files/15859-HyperGear-Stealth2-ANC-Wireless-Headphones-Black-001a.jpg?v=1765821412',
        50 => 'https://www.gca-global.com/smart_editor/upload/202604171651362029299041.jpg',
        52 => 'https://contents.mediadecathlon.com/p2330864/k%2488f809c78fdef8647193bdca80fd47fc/gps-900-by-coros-smart-watch-black.jpg?f=1920x0&format=auto',
        53 => 'https://img.pccomponentes.com/articles/39/390059/4429-samsung-adaptador-de-corriente-45w-negro-especificaciones.jpg',
        54 => 'https://tehnomart.com/image/cache/catalog/product/107/97/product_image_81997_560276-800x800.png',
        55 => 'https://www.cdiscount.com/pdt2/0/5/8/1/700x700/pho3663364130058/rw/cable-usb-c-usb-c-2m-pour-macbook-macbook-air.jpg',
        56 => 'https://c1.neweggimages.com/productimage/nb640/BFMPS2304110N5H884E.jpg',
        61 => 'https://infinitebrandedaccessories.com/cdn/shop/files/G808-1-600x600.png?v=1759335379&width=1090',
        62 => 'https://electronikz.com/images/diacco-arcade-joystick-review_thumbnail.jpg',
        63 => 'https://pctechsupply.com/cdn/shop/files/71nulLJ9VmL.jpg?v=1737079976',
        64 => 'https://www.software.cz/img/uploaded/nw/4677_perso-s-preview.jpeg',
        65 => 'https://www.tfk.ru/upload/dev2fun.imagecompress/webp/iblock/cac/mk1djhf5r9vyhwqz0bwdh3e9qwj4axib/ofisnoe_prilozhenie_microsoft_365_family_subscr_1yr_medialess_p8_6gq_01556ofisnoe_prilozhenie_microsoft_365_family_subscr_1yr_medialess_p8_6gq_0155671fe_1006_0dba_38d8.webp',
        66 => 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?auto=format&fit=crop&w=900&q=85',
        67 => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?auto=format&fit=crop&w=900&q=85',
        69 => 'https://ueeshop.ly200-cdn.com/u_file/UPBE/UPBE110/2602/26/products/11logo-17114498b1.jpg',
        57 => 'https://shop.asetos.co.za/cdn/shop/products/sliedlhyfvfjjnra3cxq_1024x.png?v=1649909338',
        58 => 'https://api.mdp.co.id/upload/pictures/product/HE1748.jpg',
        51 => 'https://i5.walmartimages.com/asr/c5cbd37e-d2f5-4baf-94ec-2fa8cda679d9.f0dd3719c2cb28e1ec91ad34aaf7e950.jpeg?odnBg=FFFFFF&odnHeight=576&odnWidth=576',
        59 => 'https://imagenes.kissu.com.ec/productos/lg/17519900980.jpg',
        70 => 'https://perfectdealz.co.za/cdn/shop/files/d6454ea8-AHD-4-Channel-CCTV-Kitt_600x.jpg?v=1768539207',
        71 => 'https://importadoracuevas.com/cdn/shop/files/EnchufeConectorInteligenteSmartPlugWifi.jpg?v=1734114689',
        72 => 'https://cdn.manomano.com/images/images_products/2561874/P/169230879_3.jpg',
        60 => 'https://sellconnectug.com/wp-content/uploads/2023/12/playstation-5-console-ps5.jpg',
    ];
    foreach ($productos as &$producto) {
        $productId = (int)($producto['id_producto'] ?? 0);
        if (isset($imageOverrides[$productId])) {
            $producto['imagen_url'] = $imageOverrides[$productId];
        }
    }
    unset($producto);

    echo json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron consultar los productos de MySQL.']);
}
