# Historial de Cambios - Monitor IP

## [1.2.3] - 2026-08-14
- **Renovación de interfaz**: Se ha actualizado el diseño del panel con una cabecera, barra lateral y navegación más limpias y consistentes.

## [1.2.2] - 2026-08-14
- **Comparador de latencia gaming**: Nueva prueba de latencia para juegos competitivos, con selección de región y métricas de latencia media, mínima, máxima, jitter y pérdida de paquetes.
- **Comparador DNS**: Nueva comparativa de resolutores DNS públicos mediante cinco consultas por servidor, con resultados ordenados por latencia media, jitter y tasa de fallos.

## [1.2.1] - 2026-05-27
- **Diagnosis Red Privada**: Se ha corregido un error que impedía la correcta diagnosis de redes privadas, mejorando la precisión de los resultados y la detección de dispositivos en estas redes.

## [1.2.0] - 2026-05-24
- **Alertas por alta latencia**: Monitor-IP ahora puede detectar y alertar sobre latencias anormalmente altas en la red, enviando notificaciones automáticas a Telegram.
- **Generacion de reportes**: Se ha implementado la capacidad de generar reportes detallados sobre el estado de la red y los dispositivos conectados.
- **Se elimina la limitación de pings**: Ahora se pueden realizar pings ilimitados a los dispositivos monitorizados, mejorando la capacidad de diagnóstico y seguimiento.
- **Nueva funcionalidad de alertas de intrusos**: Monitor-IP ahora puede detectar y alertar sobre dispositivos desconocidos conectados a la red local, enviando notificaciones automáticas a Telegram.

---

## [1.1.0] - 2026-05-23
- **Migración a BD SQLite**: Cambio de almacenamiento a SQLite para mejorar la gestión de datos y la escalabilidad del sistema.
- **Optimización de rendimiento**: Mejoras en la eficiencia del sistema, reduciendo el consumo de recursos y acelerando los procesos de escaneo y monitorización.

---

## [1.0.6] - 2026-05-19
- **Correcciones varias**: Errores de configuración, tipos y versión mobile.

---

## [1.0.5] - 2026-05-17
- **Añadidas alertas de Telegram**: Notificaciones automáticas en Telegram para eventos de caída y recuperación de IPs, con detalles específicos de cada evento.
- **Corregidos errores de configuración**: Ajustes en la configuración para mejorar la estabilidad y el rendimiento del sistema.

---

## [1.0.4] - 2026-05-16
- **Menú de usuario**: Menú desplegable en la cabecera con las opciones *Cambiar contraseña* y *Cerrar sesión*, sustituyendo los botones sueltos.
- **Modales de análisis**: Cabeceras actualizadas para mantener coherencia visual con los demás modales.
- **Botón modo oscuro**: Corregido botón modo oscuro en menú lateral

---

## [1.0.3] - 2026-05-16
- **Seguridad**: Mejoras en la seguridad, solicitando un usuario y contraseña al primer acceso.
- **Interfaz de Usuario (UI)**: Visualización del nombre de usuario autenticado en la cabecera.

---

## [1.0.2] - 2026-05-15
- **Cambio de nombre y logo**: Actualización de la identidad visual del proyecto.
- **Añadidas Categorías**: Nueva funcionalidad para organizar dispositivos.
- **Añadido modo oscuro**: Interfaz optimizada para visualización nocturna o con poca luz.
- **Topología Interactiva**: Ahora es posible arrastrar elementos dentro del mapa de topología.
- **Ventana de Velocidad**: Implementada ventana para el ajuste de velocidad de escaneo.
- **Ajuste Mobile**: Optimización de la interfaz para una correcta visualización en dispositivos móviles.

---

## [1.0.1] - 2026-05-12
- Configuración inicial de Docker y despliegue básico.
- Monitorización en tiempo real de servidores.
- Escaneo de red local.
