<?php
/** TechMarket: conexión segura a Aiven desde Render.
 * Configura MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER y MYSQL_PASSWORD
 * como variables de entorno en Render. Sube el certificado Aiven como Secret File
 * /etc/secrets/ca.pem y define MYSQL_CA_PATH=/etc/secrets/ca.pem.
 */
$host = getenv('MYSQL_HOST') ?: '';
$port = (int)(getenv('MYSQL_PORT') ?: 17112);
$database = getenv('MYSQL_DATABASE') ?: 'defaultdb';
$username = getenv('MYSQL_USER') ?: '';
$password = getenv('MYSQL_PASSWORD') ?: '';
$caPath = getenv('MYSQL_CA_PATH') ?: '/etc/secrets/ca.pem';
if ($host === '' || $username === '' || $password === '' || !is_file($caPath)) {
    error_log('TechMarket: faltan variables MySQL o certificado CA de Aiven.');
    http_response_code(503);
    exit('La conexión segura con la base de datos no está configurada.');
}
try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_CA => $caPath,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ];
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $conexion = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    error_log('TechMarket Aiven: ' . $e->getMessage());
    http_response_code(503);
    exit('No se pudo conectar con Aiven. Revisa las variables y el certificado de conexión.');
}
