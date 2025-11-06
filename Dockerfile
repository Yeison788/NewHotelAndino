# Imagen base PHP 8.2 con Apache
FROM php:8.2-apache

# Instalar dependencias del sistema necesarias
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libcurl4-openssl-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    zip \
    && rm -rf /var/lib/apt/lists/*

# Configurar extensiones PHP (para MySQL, cURL, GD, ZIP, mbstring, etc.)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip mbstring curl

# Habilitar módulos de Apache necesarios
RUN a2enmod rewrite headers expires

# Copiar el código del proyecto al directorio web
COPY . /var/www/html/

# Permisos correctos para Apache
RUN chown -R www-data:www-data /var/www/html

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Exponer el puerto 80 para Apache
EXPOSE 80

# Comando de inicio
CMD ["apache2-foreground"]