# 🌐 Monitor de IPs Públicas  
Este proyecto permite monitorear la conectividad a servidores desde tu red local. Es útil para **diagnosticar bloqueos de tu proveedor de Internet (ISP)** y verificar la disponibilidad de estos servicios.  

![ip-monitor](https://github.com/user-attachments/assets/fcab05ae-e28f-4916-a62c-6f8e94bcf189)

## ⚠️ Aviso  
- Este proyecto es solo para **uso personal y diagnóstico de red**.  
- **No** se debe **abusar** de los pings a IPs públicas para evitar tráfico innecesario.
- Los pings se lanzan **cada minuto** o al pulsar el botón manual.
- Solo se almacenan los estados de los **últimos 5 pings lanzados**.
- Si no se refrescan los pings en la tabla, **borrar el contenido del archivo "ping_results.json"**.
  
## 🚀 Características  
✅ Monitoriza servidores desde tu red.  
✅ Configurable desde el archivo `config.php`.  
✅ **Diseño moderno y visual**.  
✅ Almacena el estado de los pings.  
✅ Compatible con **Windows, Linux y macOS**.  

## 🛠️ Instalación en Linux y MacOS  

### 1️⃣ Requisitos  
⚙️ **PHP 7.4+**  
⚙️ **Servidor Apache**  
⚙️ **Un navegador web**    

### 2️⃣ Instalación  
**🔹Instalar PHP y Apache:**  
sudo apt update && sudo apt install apache2 php -y

**🔹Clona el repositorio:**  
git clone https://github.com/negociatumente/monitor-ip.git

**🔹Mueve el contenido del proyecto a la carpeta de htdocs:**  
sudo mv ~/monitor-ip/monitor-ip /var/www/html/monitor-ip

### 3️⃣ Configuración
**🔹Abre el archivo config.php y modifica las IPs según los servidores que quieras monitorizar:**  
cd monitor-ip
nano config.php

### 4️⃣ Ejecución
**🔹Levanta el servidor Apache local:**  
sudo systemctl start apache2

### 5️⃣ Resultados
**🔹Luego, abre en tu navegador la siguiente url:**    
http://localhost/monitor-ip

## 🛠️ Instalación en Windows  

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
**🔹Abre el archivo config.php y modifica las IPs según los servidores que quieras monitorizar:**  
config.php

### 5️⃣ Resultados
**🔹Luego, abre en tu navegador la siguiente url:**    
http://localhost/monitor-ip
