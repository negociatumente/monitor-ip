# 🌐 Monitor de IPs Públicas  
Este proyecto permite monitorear la conectividad a servidores desde tu red local. Es útil para **diagnosticar bloqueos de tu proveedor de Internet (ISP)** y verificar la disponibilidad de estos servicios.  

## ⚠️ Aviso  
- Este proyecto es solo para **uso personal y diagnóstico de red**.  
- No se debe abusar del ping para evitar tráfico innecesario.
  
## 🚀 Características  
✅ Monitoriza servidores desde tu red.  
✅ Configurable desde el archivo `config.php`.  
✅ **Diseño moderno y visual**.  
✅ Almacena el estado de los pings.
✅ Compatible con **Windows, Linux y macOS**.  

## 🛠️ Instalación y Uso  

### 1️⃣ Requisitos  
🟢 **PHP 7.4+**
🟢 **Servidor Apache**
🟢 **Un navegador web**  

### 2️⃣ Instalación  
**-Instalar PHP y Apache:**  
sudo apt update && sudo apt install apache2 php -y

**-Clona el repositorio:**  
git clone https://github.com/negociatumente/monitor-ip.git

**-Mueve el proyecto a la carpeta de htdocs:**  
sudo mv ~/monitor-ip /var/www/html/

### 3️⃣ Configuración
**-Abre el archivo config.php y modifica las IPs según los servidores que quieras monitorizar:**  
cd monitor-ip
nano config.php

### 4️⃣ Ejecución
**-Levanta el servidor Apache local:**  
sudo systemctl start apache2

### 5️⃣ Resultados
**-Luego, abre en tu navegador la siguiente url:**    
http://localhost:8000
