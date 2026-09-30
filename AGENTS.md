# Reglas de seguridad para nuevas API

- Los IDs de tablas (`id`, `*_id`, `tenant_id`, `actor_id`, `recurso_id`) son internos. No enviarlos en rutas, consultas, cuerpos, respuestas JSON ni canales WebSocket nuevos. Usar `slug` o `key` natural cuando exista; para el resto, un `url_token` opaco y estable de 24 caracteres.
- Un selector público no es autorización: cada lectura o mutación debe comprobar sesión, permiso y pertenencia al colegio y al recurso padre. Los selectores de otro colegio o tipo de recurso deben responder 404.
- Las sesiones de navegador usan cookies HttpOnly, host-only, SameSite y Secure bajo HTTPS, más CSRF y comprobación de origen. No emitir nuevos bearer tokens a JavaScript ni guardar credenciales, tokens, IDs internos o datos personales en `localStorage` o `sessionStorage`.
- Las respuestas públicas se construyen con presentadores explícitos; no devolver modelos Eloquent completos. Añadir pruebas que comprueben ausencia de IDs internos, rechazo de rutas numéricas, acceso entre colegios y permisos.
- Los archivos subidos deben pasar por MIME, tamaño, cuota y escáner. En producción, si el escáner falla o no está configurado, la carga se rechaza.
- El contrato académico público exige selectores opacos por defecto; una cabecera de compatibilidad numérica existe solo en `testing` para pruebas históricas. No introducir rutas ni cuerpos nuevos con IDs internos. Resolver selectores con `OpaqueUrlToken` y el índice `academic_public_tokens`; conservar siempre el scope autorizado. Los importadores SQL deben reconstruir el índice; las altas Eloquent lo actualizan automáticamente.

Estado, justificación y pendientes: [docs/SEGURIDAD_API.md](docs/SEGURIDAD_API.md).
