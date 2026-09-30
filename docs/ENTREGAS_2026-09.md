# Entregas del backend hasta el 30 de septiembre de 2026

Este registro resume los módulos trabajados en `pedro-dev`. El detalle de seguridad y sus límites está en [SEGURIDAD_API.md](SEGURIDAD_API.md). La documentación del negocio y la matriz de aceptación viven en `documentacion-girgit`.

| Módulo | Entrega verificable | Límite o siguiente paso |
|---|---|---|
| Colegios y acceso | Provisionamiento multitenant, migraciones centrales y por colegio, incorporación obligatoria del Rector, sesión web con cookies HttpOnly, CSRF, revocación y suplantación aislada. | Ensayar el provisionamiento y recuperación completos en PostgreSQL de staging antes de producción. |
| Perfil y permisos | Datos reales del usuario y plan, edición de perfil, MFA voluntario con TOTP y códigos de recuperación, matriz RBAC, permisos específicos para transiciones de períodos y carga de archivos. | Mantener pruebas de autorización por objeto para cada módulo nuevo; definir recuperación asistida de MFA. |
| Año y estructura | Años lectivos y períodos por fechas con transiciones manuales autorizadas; sedes, jornadas, niveles, grados, grupos, bloques y espacios; datos locales de demostración de Inmaculada 2026. | Los datos de demostración no son configuración para producción. |
| Plan de estudios y evaluación | Áreas, materias, asignación docente, horarios filtrados por grupo, SIEE y currículo por grado, planillas y matrículas; paginación y filtros en servidor. | Verificar reglas académicas y carga con volúmenes reales; indexar la resolución de algunos selectores opacos. |
| Comunicación y tiempo real | Eventos y avisos por Reverb en canales privados, con invalidación por tema y permisos al volver a consultar. | Operar Reverb, colas y scheduler como servicios supervisados en el despliegue. |
| Seguridad de identificadores | Rutas, filtros, cuerpos y respuestas académicas usan selectores públicos de 24 caracteres; plataforma usa `slug`, `key` o token según el recurso. Se rechazan IDs numéricos en la API académica pública y se comprueba el colegio y el permiso. | El selector no es una credencial ni garantiza seguridad total. El modo numérico heredado queda solo para pruebas en `testing`; algunos tokens se buscan de forma lineal. |
| Archivos y operación | Descargas firmadas, cuotas, permiso de carga y escaneo obligatorio que falla cerrado en producción; simulacro aislado de respaldo y restauración. | Configurar y probar ClamAV, HTTPS, secretos y respaldos externos antes de producción. El simulacro no equivale a una política operativa completa. |

## Verificación del corte de seguridad

En el entorno local se ejecutaron 206 pruebas backend (1443 aserciones), además de la compilación y pruebas frontend. `composer audit` y `npm audit` terminaron sin avisos en ese corte. Estas comprobaciones no certifican disponibilidad, seguridad absoluta ni la configuración de producción.

## Migraciones de este corte

Aplicar, tras respaldo y prueba en staging, `php artisan migrate --force` y `php artisan tenants:migrate --force`. Las migraciones nuevas registran permisos académicos y de archivos, un selector público indexado para archivos y conservación de la precisión introducida para el peso curricular. Sincronizar RBAC según el procedimiento de despliegue del README.
