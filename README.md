# 🌐 Monitor de IPs  
Este proyecto permite monitorear la conectividad a servidores desde tu red local. Es útil para **diagnosticar bloqueos de tu proveedor de Internet (ISP)** y verificar la disponibilidad de estos servicios.  

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
✅ Monitoriza servidores desde tu red.  
✅ Configurable desde el archivo `config.ini`.  
✅ **Diseño moderno y visual**.  
✅ Almacena el estado de los pings.  
✅ Compatible con **Windows, Linux y macOS**.  

## 📁 Estructura del proyecto
```
monitor-ip/
├── index.php          # Página principal y lógica de backend
├── menu.php           # Menú de navegación y acciones rápidas
├── views.php          # Vista principal del dashboard
├── conf/              # Archivos de configuración y resultados
│   ├── config.ini     # Configuración de IPs y servicios
│   └── ping_results.json # Resultados de los pings
├── lib/               # Librerías y recursos
│   ├── functions.php  # Funciones PHP reutilizables
│   ├── script.js      # Scripts JavaScript para la interfaz
│   └── styles.css     # Estilos CSS personalizados
```

## 🛠️ Instalación en Linux y MacOS  

### 1️⃣ Requisitos  
⚙️ **PHP 7.4+**  
⚙️ **Servidor Apache**  
⚙️ **Un navegador web**    

### 2️⃣ Instalación  
**🔹Instalar PHP y Apache:**  
```bash
sudo apt update && sudo apt install apache2 php -y
```

**🔹Clona el repositorio:**  
```bash
git clone https://github.com/negociatumente/monitor-ip.git
```

**🔹Mueve el contenido del proyecto a la carpeta del servidor web:**
```bash
sudo mv ~/monitor-ip/monitor-ip /var/www/html/monitor-ip
```

**🔹Da permisos de lectura y escritura al archivo ping_results.json:**
```bash
sudo chmod 664 /var/www/html/monitor-ip/ping_results.json
```

### 3️⃣ Configuración
**🔹Abre el archivo config.ini y modifica las IPs según los servidores que quieras monitorizar:**
```bash
cd monitor-ip  
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

## 🛠️ Instalación en Windows  

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
