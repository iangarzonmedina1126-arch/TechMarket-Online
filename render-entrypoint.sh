
#!/bin/sh
set -eu

PORT="${PORT:-10000}"

# Preparar certificado SSL de Aiven para PHP
SOURCE_CA="/etc/secrets/ca.pem"
TARGET_CA="/tmp/aiven-ca.pem"

if [ ! -f "$SOURCE_CA" ]; then
    echo "ERROR: No se encuentra el certificado de Aiven."
    exit 1
fi

cp "$SOURCE_CA" "$TARGET_CA"
chmod 644 "$TARGET_CA"

export MYSQL_CA_PATH="$TARGET_CA"

# Configurar puerto de Apache
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s#<VirtualHost \*:80>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
