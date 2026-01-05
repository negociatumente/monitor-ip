# 🌐 Monitor de IPs  
Este proyecto permite monitorear la conectividad a servidores desde tu red local. Es útil para **diagnosticar bloqueos de tu proveedor de Internet (ISP)** y verificar la disponibilidad de estos servicios. Ademas, puedes realizar un escaneo de red local para descubrir dispositivos conectados a tu red y medir latencias y velocidades de tu red. Finalmente, puedes generar un reporte de la calidad de tu red.

## 📖 Ayuda  

Para saber más sobre monitorización de IPs y redes tienes la siguiente **guía**:  
https://negociatumente.com/guia-redes

![ip-monitor](https://github.com/user-attachments/assets/fcab05ae-e28f-4916-a62c-6f8e94bcf189)

## ⚠️ Aviso  
- Este proyecto es solo para **uso personal y diagnóstico de red**.  
- **No** se debe **abusar** de los pings a IPs públicas para evitar tráfico innecesario.
- Este proyecto es solo para **uso personal y diagnóstico de red**.  
- Los pings se lanzan según el parámetro **Timer Interval** o al pulsar el botón manual.
- Solo se almacenan los estados de los últimos pings lanzados según el parámetro **Ping History**.
- Si no se refrescan los pings en la tabla, borrar los pings anteriores con el botón de **Clear Data**.
  
## 🚀 Características  
✅ **Monitorización en tiempo real** de servidores y dispositivos.  
✅ **Gestión de Servicios**: Agrupa y organiza tus dispositivos por servicios con colores personalizados.  
✅ **Configurable**: Ajusta intervalos, historial y las ips desde la interfaz.  
✅ **Múltiples Métodos**: Soporte para Ping (ICMP), HTTP/HTTPS (Curl) y DNS.  
✅ **Trazabilidad de Red**: Realiza traceroutes para diagnosticar rutas de red.  
✅ **Detección de CGNAT**: Identifica si estás detrás de una NAT compartida.  
✅ **Escaneo de Red Local**: Descubre dispositivos conectados a tu red.  
✅ **Test de Velocidad**: Mide tu latencia, velocidad de descarga y subida.  
✅ **Reporte de Red**: Genera un reporte de la calidad de tu red.  
✅ **Compatible** con Windows, Linux y macOS.  

## 📁 Estructura del proyecto
```
monitor-ip/
├── index.php                       # Página principal y lógica de backend
├── menu.php                        # Menú de navegación y acciones rápidas
├── views.php                       # Vista principal del dashboard
├── conf/                           # Archivos de configuración y resultados
│   ├── config.ini                  # Configuración de IPs y servicios remotos
│   ├── config_local.ini            # Configuración de IPs locales
├── results/                        # Resultados de los pings y speedtests
│   ├── ping_results.json           # Resultados de los pings remotos
│   ├── ping_results_local.json     # Resultados de los pings locales
│   ├── speedtest_results.json      # Resultados de los speedtests
└── lib/                            # Librerías y recursos
	├── functions.php               # Funciones PHP reutilizables
    ├── script.js                   # Scripts JavaScript principales
    ├── network_scan.js             # Lógica de escaneo de red y speedtest
    └── styles.css                  # Estilos CSS personalizados
```

## 🛠️ Instalación en Docker (Recomendada)

### 1️⃣ Requisitos  
⚙️ **Docker**  
⚙️ **Un navegador web**

### 2️⃣ Instalación  
**🔹Debes descargar e instalar docker en tu sistema (Linux, Windows o MacOS):**  
https://docs.docker.com/get-docker/  

### 3️⃣ Configuración
**🔹Clona el repositorio:**
```bash
docker pull ghcr.io/negociatumente/monitor-ip:latest
```

**🔹Ejecuta el contenedor:**
```bash
docker run --name monitor-ip --network host -p 80 ghcr.io/negociatumente/monitor-ip:latest
``` 

### 4️⃣ Resultados
**🔹Finalmente, abre en tu navegador la siguiente url:**  
http://localhost/monitor-ip


## 🛠️ Instalación en Linux y MacOS  

### 1️⃣ Requisitos  
⚙️ **PHP 7.4+**  
⚙️ **Servidor Apache**  
⚙️ **Un navegador web**    

### 2️⃣ Instalación  
**🔹Instalar PHP, Apache, Git y Speedtest-cli:**  
```bash
sudo apt update && sudo apt install apache2 php git
	nmap \
	iputils-ping \  
	net-tools \
	traceroute \
	iproute2 \
	curl \
	dnsutils \
	bind9-host -y  
```

**🔹Clona el repositorio:**  
```bash
git clone https://github.com/negociatumente/monitor-ip.git
```

**🔹Mueve el contenido del proyecto a la carpeta del servidor web:**
```bash
sudo mv ~/monitor-ip/monitor-ip /var/www/html/monitor-ip
```

**🔹Da permisos de escritura a la carpeta de configuración:**
```bash
sudo chown -R www-data:www-data /var/www/html/monitor-ip/conf
sudo chmod -R 775 /var/www/html/monitor-ip/conf
sudo chmod -R 775 /var/www/html/monitor-ip/results
```

### 3️⃣ Configuración
**🔹Abre el archivo config.ini y modifica las IPs según los servidores que quieras monitorizar:**
```bash
cd /var/www/html/monitor-ip/conf
nano config.ini
```

### 4️⃣ Ejecución
**🔹Levanta el servidor Apache local:**
```bash
sudo systemctl start apache2
```

### 5️⃣ Resultados
**🔹Finalmente, abre en tu navegador la siguiente url:**
```bash
http://localhost/monitor-ip
```

## 🛠️ Instalación en Windows  (Funciones Limitadas)

🔹Aquí tienes un video sobre la instalación en Windows:  
https://www.tiktok.com/@negociatumente/video/7504332909923568919

### 1️⃣ Requisitos  
⚙️ **XAMPP**  
⚙️ **Un navegador web**    

### 2️⃣ Instalación  
**🔹Descargar XAMPP:**  
https://www.apachefriends.org/es/download.html  

**🔹Instalar XAMPP:**  
-Ejecuta el instalador y sigue los pasos.  
-Asegúrate de seleccionar Apache y PHP en la instalación.  
-Cuando termine, abre XAMPP Control Panel y presiona "Start" en Apache.  

### 3️⃣ Descargar y configurar el proyecto
**🔹Descargar el código ZIP:**  
https://github.com/negociatumente/monitor-ip

**🔹Mueve la carpeta /monitor-ip que hay dentro de la carpeta /monitor-ip-main a la carpeta de htdocs:**  
C:\xampp\htdocs\monitor-ip

### 4️⃣ Configuración
**🔹Abre el archivo config.ini y modifica las IPs según los servidores que quieras monitorizar:**  
config.ini

### 5️⃣ Resultados
**🔹Finalmente, abre en tu navegador la siguiente url:**    
http://localhost/monitor-ip
