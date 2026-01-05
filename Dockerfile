# Use the official PHP image as the base image
FROM php:8.2-apache

# Instalar speedtest-cli y herramientas de escaneo de red
RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y \
		nmap \
		iputils-ping \
		net-tools \
		traceroute \
		iproute2 \
		curl \
		dnsutils \
		bind9-host \
	&& rm -rf /var/lib/apt/lists/*

# Permitir que www-data ejecute ping sin contraseña
RUN echo 'www-data ALL=(ALL) NOPASSWD: /bin/ping' >> /etc/sudoers.d/www-data \
	&& chmod 0440 /etc/sudoers.d/www-data

# Set working directory
WORKDIR /var/www/html/monitor-ip

# Copy project files to the container
COPY monitor-ip/ /var/www/html/monitor-ip/

# Configurar Apache: Alias /monitor-ip
RUN echo 'Alias /monitor-ip /var/www/html/monitor-ip\n\
<Directory /var/www/html/monitor-ip>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' \
> /etc/apache2/conf-available/monitor-ip.conf

# Habilitar la nueva configuración
RUN a2enconf monitor-ip

# Habilitar rewrite si tu app lo necesita
RUN a2enmod rewrite

# Set permissions (optional, adjust as needed)
RUN chown -R www-data:www-data /var/www/html/monitor-ip && chmod -R 755 /var/www/html/monitor-ip

# Expose port 80
EXPOSE 80

# Use the default Apache start command
CMD ["apache2-foreground"]
