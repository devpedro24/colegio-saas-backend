# Identidad pública y seguridad de la API

## Contrato para cambios nuevos

La base de datos conserva sus claves primarias y foráneas para relaciones y auditoría. La API pública usa `slug` o `key` natural, o `url_token` opaco de 24 caracteres, tanto en URL como en cuerpos y respuestas. La generación del token incorpora el colegio y el tipo de recurso. El backend resuelve el token en una consulta limitada al colegio y después comprueba permiso y relación con el recurso padre. Un token válido de otro colegio o de otro tipo no da acceso.

El token **no es secreto ni reemplaza permisos**. El cifrado de un ID en el navegador tampoco protege una sesión si la clave está en el mismo navegador. La sesión se mantiene en cookies HttpOnly; JavaScript solo conserva preferencias inocuas como idioma, tema y tamaño de página. Las peticiones que cambian estado usan CSRF y control de origen.

## Aplicado en este cambio

- API académica: el contrato opaco es obligatorio por defecto en el subdominio y en la suplantación. Se rechazan rutas numéricas, `opaque=0` y claves `id`/`*_id` en consultas y cuerpos, incluso anidados. El modo numérico solo se conserva en el entorno `testing` con cabecera explícita para pruebas históricas.
- Años, períodos, estructura, plan de estudios, horarios, SIEE, evaluación, boletines, eventos y parámetros académicos usan selectores opacos en sus rutas, filtros, cuerpos y respuestas. Los enlaces nuevos de año usan 24 caracteres; el token largo anterior solo sirve para redirigir enlaces guardados.
- Plataforma: colegios por `slug`, sedes por `url_token`, planes y RBAC por `key`, suplantación por `slug`, auditoría presentada sin claves internas.
- Cuenta y tiempo real: identidad pública opaca, sesión de navegador con cookies HttpOnly y CSRF, canales privados por token de colegio y cierre de sesiones cuando se inactiva la cuenta.
- Archivos: respuesta y descarga firmada con selector público; cabeceras de descarga `attachment` y `nosniff`; permiso `archivos.subir` y límite de cargas por usuario/colegio. La producción bloquea cargas sin analizador antivirus activo. ClamAV se conecta mediante INSTREAM a un daemon privado configurado con `STORAGE_SCANNER=clamav`.
- Entorno local: Vite y Reverb enlazan por defecto a `127.0.0.1`, con orígenes y hosts limitados.
- Dependencias: se actualizaron versiones compatibles de Laravel, CommonMark, Flysystem, Axios, React Router y Vite; `composer audit` y `npm audit` no reportan avisos al cerrar este cambio.

## Pendiente para cerrar el contrato en toda la plataforma

1. Sustituir la búsqueda lineal de algunos tokens académicos por una columna pública indexada o un mecanismo equivalente antes de operar catálogos grandes. Probar tiempo de respuesta y paginación.
2. Retirar el soporte Bearer heredado después de migrar los clientes externos y sus pruebas a sesiones por cookie. El navegador ya no recibe ni guarda Bearer nuevos.
3. Verificar el escaneo con un daemon ClamAV real en el entorno de despliegue y configurar HTTPS, secretos, rotación de claves, copias y restauración. Sin daemon, la producción rechaza cargas.
4. Revisar acceso por objeto en cada módulo nuevo y ampliar pruebas entre colegios/roles. La presencia de un token opaco no reemplaza este control.

Estas tareas son condiciones para afirmar que **toda** la aplicación cumple el contrato; los cambios actuales no deben presentarse como una garantía de seguridad absoluta.
