# Ingreso estudiantil — operación y verificación

Implementación local del 7 de octubre de 2026. Regla funcional vigente en `documentacion-girgit/educativo-saas/Logica del negocio/04-procesos-academicos/matriculas.md`.

## Qué incluye

Convocatorias por año/grados/cupos, requisitos/documentos, portal público por subdominio, correo + PIN, borrador en servidor, carga/versiones, revisión con motivos, espera/rechazo/aprobación, cuenta de estudiante y confirmación de grupos. Sin cuentas de acudiente ni pagos. Admisiones con selección/entrevistas y renovaciones de cuentas existentes son futuras; la matrícula académica manual se conserva.

## Accesos

Idioma de API: `SetIntakeLocale` negocia únicamente español/inglés mediante `Accept-Language` en rutas de ingreso público/privado y correo institucional/plataforma. Sin preferencia compatible se conserva español; el locale anterior se restaura al terminar, incluso con errores. Los mensajes fijos de estas operaciones usan catálogos JSON y las etiquetas de validación tienen traducción legible. No se traducen nombres, instrucciones, observaciones ni registros históricos ingresados por los usuarios. Prueba focalizada: 22 tests de `EnrollmentIntakeTest` / 323 aserciones, incluyendo en-US/es-CO, validaciones, no alteración de datos y no fuga de locale. Las 8 pruebas de autorización de correo también pasaron tras localizar las respuestas.

- Administración: `/admisiones/solicitudes` en frontend.
- Portal: `/ingreso` o `/ingreso/<campaña_opaca>`, mismo colegio.
- API privada: `/api/ingreso/catalogo`, `/campanas`, `/campanas/{token}/solicitudes`, `/solicitudes/{token}`, `/decision`, `/grado`, `/documentos/{token}`, `/campanas/{token}/distribuir`, `/mi-estado` (consultar rutas exactas en `routes/tenant_academico.php`).
- API pública: `/api/ingreso-publico/catalogo`, `/{campaña}/iniciar`, `/acceder`, `/recuperar`, `/solicitud`, `/documentos/{requisito}`, `/documentos/{token}`, `/salir` (ver `routes/tenant.php`).
- No exponer rutas físicas, IDs internos, PIN/hash ni contraseñas en respuestas.

Permisos: `ingreso.configurar`, `ingreso.ver`, `ingreso.revisar`, `ingreso.decidir`, `ingreso.cambiar_grado`, `ingreso.asignar`. Rector estructural; Secretaría configurable OFF; coordinación académica/combinada consulta y asignación configurables OFF. Operaciones de expedientes además exigen `ingreso.ver`. Todos dependen de `academico`.

## Modelo e invariantes

La fecha de cierre `hasta` es nullable: la API exige que la clave esté presente y acepta `null` para mantener una convocatoria sin vencimiento por calendario. Si hay fecha, debe ser válida e igual o posterior a `desde`; el último día es inclusivo según la zona horaria del colegio. El interruptor `abierta` pausa o permite nuevas solicitudes y cargas, sujeto a fecha de apertura, cierre opcional y año lectivo planificado/en curso. Pausar no borra expedientes ni bloquea consulta con correo y PIN. Un año cerrado sigue impidiendo nuevas solicitudes/cargas incluso sin cierre. La migración tenant `2026_10_08_000004_allow_open_ended_enrollment_campaigns` conserva todos los valores existentes y su rollback se niega a inventar fechas cuando hay NULL. Corte focalizado: 21 pruebas / 306 aserciones, incluyendo persistencia de NULL, pausa/reanudación, consulta y límites de fechas/año.

Al guardar una convocatoria, cada documento definido exige nombre, descripción/instrucciones (máximo 1000 caracteres), tamaño entero de 1 a 10 MB y al menos un formato entre PDF/JPG/PNG/DOCX. La API rechaza requisitos incompletos, también al editar, independientemente de su casilla `obligatorio` (esta determina lo que debe entregar el aspirante). Se conservan las validaciones de nombre/año/fechas, privacidad y cupos, y el bloqueo de requisitos después de la primera solicitud. No se rellenan descripciones ni se cambian requisitos históricos automáticamente. Validación focalizada del 8 de octubre: `EnrollmentIntakeTest`, 19 pruebas y 272 aserciones en bases aisladas.

- `ingreso_campanas`: definición congelada desde primera solicitud; nombre/fechas/habilitación editables.
- `ingreso_solicitudes`: correo, datos, estado, grado solicitado/propuesto, PIN hash, versión de sesión; única campaña/correo.
- `ingreso_documentos`: referencias a `stored_files` central, requisito/version/revisor/estado/motivo; última versión revisable.
- `ingreso_historial`: cronología visible y auditada, sin claves secretas.
- `ingreso_perfiles`: documento único de estudiantes dados de alta aquí; no inventa identificaciones de usuarios históricos.
- `ingreso_notificaciones`: outbox cifrado y clave única. Tras entrega se borra contenido, no la trazabilidad.
- `users.temporary_password_expires_at`: expiración de contraseña temporal del estudiante (72 h); se limpia al cambiar/restablecer. Restablecimientos administrativos nuevos también caducan a las 72 h.

PIN 15 días, sesión pública 2 h. Cookies host-only, HttpOnly (sesión), SameSite Strict/Secure en HTTPS, CSRF y Origin. Plan/estado de colegio se revalidan. Logout revoca sesiones anteriores; recuperación reemplaza PIN. Rate limits de acceso y carga; no PIN ni PII en localStorage/sessionStorage.

Validación de aprobación: fuera de correcciones, expediente enviado/verificado/con consentimiento, documentos aprobados según grado destino, cupo del grado, cuota comercial, email/documento no duplicados. Aprobación transaccional crea solo estudiante; asignación crea `Matricula`. Bloqueos de año/campaña/solicitud/grupo previenen conflictos entre decisiones del mismo año. Propuestas no reservan cupos y se revalidan en confirmación; reintentos del mismo resultado son idempotentes.

Archivos: StorageService existente, disco tenant privado, MIME/tamaño/escáner/cuota. Ruta legible: `matriculas/Ano_lectivo_<año>/<campaña>/Solicitud_<token>/<requisito>/Version_<n>/<archivo>`. Los datos y descargas tienen `Cache-Control: no-store, private`. Hasta 30 requisitos, 20 versiones, 20 campos adicionales, propuesta de hasta 300 solicitudes. No hay limpieza destructiva automática de expedientes.

## Migraciones

Actualización local del cierre opcional (8 de octubre): ensayo PostgreSQL nuevo y restaurado, seguido de aplicación en los cinco tenants. El helper también compara el contenido íntegro de las convocatorias y verifica `hasta` nullable; no cambia fechas ni activa enlaces existentes. Respaldos conservados en `C:\Users\Pedro\AppData\Local\Temp\colegio-ingreso-20261008-054031-56926d3cd11b`; trasladar a almacenamiento protegido/persistente según política operativa. Se retiraron únicamente las dos bases desechables del ensayo. Esta aplicación local no constituye despliegue al VPS.

1. Respaldar central y todos los tenants antes del despliegue; verificar restauración.
2. `php artisan migrate --force` agrega catálogo/matriz central.
3. `php artisan tenants:migrate --force` agrega tablas, columna y permisos en cada tenant, preservando decisiones RBAC existentes.
4. Desplegar frontend y reiniciar workers/configuración según procedimiento del entorno; verificar permisos del rector en nueva sesión.
5. No ejecutar rollback de tablas con expedientes reales: el `down` elimina datos del módulo. Restauración solo con plan y respaldo validado.

Ensayo local disponible, sin tocar datos de usuarios para fabricar solicitudes:

```powershell
& C:\xampp\php\php.exe tests/Support/migrate-flexible-grading.php --verify --verify-intake --apply '--pg-bin=C:\Program Files\PostgreSQL\16\bin'
```

Este helper solo opera en `APP_ENV=local` y PostgreSQL. Hace dumps de central/tenants, verifica catálogos de respaldo, crea dos bases temporales propias (nueva y restaurada), migra y compara datos. Elimina exclusivamente esas bases de ensayo; conserva respaldos. `--apply` actualiza después central y colegios. No es un comando de producción.

Validado localmente: central y cinco tenants/sedes (Campestre, Canaima, Cándido, Inmaculada, José Martí). Respaldos de ingreso en directorio temporal `colegio-ingreso-20261008-042958-d35aea1f7597`; segundo ensayo y aplicación de correo en `colegio-ingreso-20261008-044900-e7ccf45d9ff2`. Trasladarlos a almacenamiento protegido/persistente según política operativa, no Git. Se compararon usuarios existentes (excluida columna nueva), matrículas y notas. No se crearon aspirantes de prueba en colegios reales; las dos bases desechables propias de cada ensayo se eliminaron tras verificar, manteniendo los respaldos.

## Correo institucional: Gmail visual por colegio

El rector configura **Ajustes institucionales → Conexión de correo electrónico** (`/ajustes-institucionales/correo`), disponible también en el menú del usuario. Permiso estructural `config.correo`, sin suplemento de plan. La API además exige rol rector: asignar ese permiso a otro rol NO permite usarla. API GET/PUT/DELETE `/api/correo-institucional`. Introduce correo, nombre visible y contraseña de aplicación de Google; no es la contraseña habitual, OAuth ni el código de segundo factor. La pantalla contiene pasos y enlaces a Google, restricciones y ayuda de conexión.

### Bloqueo y autorización individual (8 de octubre)

- La primera configuración la realiza el rector. Al guardarla, campos y acciones quedan bloqueados, también por API y después de recargar.
- Para cambiar nombre, dirección o clave: POST `/api/correo-institucional/solicitudes`, acción `editar` y motivo. Para desconectar se requiere solicitud con acción `desconectar`.
- El superadministrador recibe una notificación persistente en el panel central, con contador y bandeja `/configuracion/correo-solicitudes`. No depende del Gmail que se pretende cambiar ni de enviarle la contraseña al superadministrador.
- GET `/api/platform/correo-solicitudes` pagina 20 filas; `/resumen` devuelve solo pendientes. POST `/{token}/resolver` aprueba o rechaza, con observación obligatoria para rechazo. Son rutas centrales exclusivas de superadmin; ni un permiso delegado ni el contexto suplantado pueden aprobar.
- La aprobación dura 24 horas, sirve para **una** modificación, y está ligada a colegio, rector solicitante, acción y revisión de la conexión. Una prueba SMTP fallida conserva la autorización hasta vencer. Un guardado exitoso vuelve a bloquear.
- No se añade un permiso permanente. Aprobaciones vencidas, rechazadas, usadas, de otro actor/colegio/acción o de una versión anterior no autorizan cambios. No hay scheduler para expirarlas: se comprueba al leer y al escribir.
- Desconectar borra la credencial cifrada y conserva un registro bloqueado. Reconectar requiere nueva aprobación: no se puede simular una primera configuración borrando la anterior.
- Migraciones `2026_10_08_000003_*`: solicitudes centrales, revisión y marca de desconexión por tenant. Configuración y revisión se actualizan en la misma transacción tenant. Si falla el marcado posterior de la solicitud central como usada, la revisión anterior ya no autoriza una segunda escritura.
- El primer guardado usa inserción exclusiva: dos primeras configuraciones concurrentes no se sobrescriben. Un colegio temporalmente inaccesible se marca como no disponible en la bandeja sin impedir revisar los demás; no se puede resolver su solicitud hasta poder revalidarlo. La bandeja consulta únicamente metadatos de autorización mediante conexiones independientes y de corta duración, sin inicializar el contexto del tenant ni cambiar caché/filesystem centrales.
- Solicitudes y decisiones invalidan solo las consultas de correo involucradas; no vuelven a cargar matrículas, estructura ni otros módulos. El guardado o la desconexión efectivos conservan la actualización de disponibilidad de correo para ingreso.

Ensayo/migración local con datos existentes: respaldo `colegio-ingreso-20261008-050543-1daefa746d24` en directorio temporal. Se compararon también remitente, nombre y credencial cifrada antes/después, sin descifrarlos ni publicarlos. Solo se eliminaron las bases temporales del ensayo; respaldos conservados. No se registraron solicitudes/autorizaciones ficticias en el colegio real.

«Probar y guardar» envía solamente al remitente indicado, con SMTP seguro fijo `smtp.gmail.com:465` y límite de tres pruebas/minuto por tenant. Solo guarda si el transporte acepta el mensaje; el rector debe revisar recepción/spam. No garantiza entregabilidad futura ni evita límites/revocaciones de Google. La prueba fallida conserva la conexión previa. Para conservar la clave, dejarla vacía sin cambiar la dirección. Desconectar elimina la copia local; también hay que revocarla en Google.

Migraciones `2026_10_08_000002_*`: tabla tenant `correo_configuracion`, clave cifrada mediante APP_KEY, y permiso/celda central. Respaldar APP_KEY en gestor de secretos separado: sin ella no se descifra la clave. Nunca devolverla a la API ni incluirla en auditoría, logs, errores, repositorio o almacenamiento del navegador. HTTPS obligatorio en producción. Los workers construyen un transporte nuevo usando el tenant actual; no reutilizan credenciales entre colegios. Se utiliza en avisos de matrícula y notificaciones de recuperación de contraseña.

La plataforma local conserva `MAIL_MAILER=log` como respaldo. El usuario conectó Gmail en Inmaculada; el 8 de octubre se validó bloqueo y se envió **un correo de prueba al propio remitente**, aceptado por SMTP, sin modificar configuración ni credencial. Esto confirma aceptación SMTP, no revisión de la bandeja de entrada ni entregabilidad masiva. Sin Gmail configurado ni otro transporte real, administración advierte; producción rechaza abrir convocatorias y enviar PIN/contraseñas por `log`/`array`. En desarrollo, los logs de correo contienen secretos: tratarlos como privados. Una conexión guardada significa última prueba aceptada, no comprobación SMTP en cada consulta.

Verificación local explícita: `php tests/Support/verify-school-mail.php --verify --tenant=inmaculada` comprueba solo lectura/bloqueo e invariancia. Agregar `--send-test` envía un único mensaje real al remitente; no ejecutarlo rutinariamente ni sin autorización. No muestra dirección ni secretos en salida.

Requisitos de infraestructura: salida TLS al puerto 465, cola supervisada, `FRONTEND_URL` con esquema/host/puerto público correcto, dominios de tenants y HTTPS. El rector no edita variables ni puertos. Un transporte alternativo administrado por plataforma se configura mediante secretos fuera de Git. No usar endpoints arbitrarios del usuario para construir enlaces.

Referencias: [Contraseñas de aplicación de Google](https://support.google.com/accounts/answer/185833?hl=es), [SMTP de Gmail/Workspace](https://support.google.com/a/answer/176600?hl=en). Requieren verificación en dos pasos y pueden estar restringidas por la organización/Protección Avanzada; no desactivar protecciones para forzarlas. Gmail tiene cuotas y antispam; evaluar capacidad antes de campañas masivas. OAuth y proveedor transaccional no están implementados en esta entrega.

Mantener worker existente supervisado (configuración local `QUEUE_CONNECTION=database`). `SendEnrollmentNotice` se despacha después del commit, cinco intentos con esperas progresivas; no reintenta la creación del estudiante. Contenido cifrado en outbox; job contiene colegio e ID interno de notificación, no contraseña. Locks evitan dos workers normales enviando el mismo aviso ya marcado; SMTP no garantiza exactamente una entrega si cae entre envío y marcado.

Recuperación operativa, sin scheduler adicional:

```sh
php artisan ingreso:reenviar-notificaciones --tenant=inmaculada
```

Reencola hasta 100 pendientes del colegio indicado, no regenera PIN/contraseñas. Para PIN vencido usar recuperación pública; contraseña vencida usar recuperación de cuenta. Verificar workers y fallos de entrega. No declarar envío real probado por haber pasado tests con Mail mock o transporte log.

## Verificación reproducible

- `php artisan test`: **328 pruebas/2885 aserciones aprobadas** con los ajustes finales del 8 de octubre.
- Incluye 18 casos de `EnrollmentIntakeTest` y 8 de `SchoolMailApprovalTest`: aprobación individual, vencimiento/rechazo, desconexión/reconexión, permisos no delegables, conservación ante error, concurrencia inicial, colegio inaccesible e inspección central sin inicializar tenants.
- `php artisan test --filter=SchoolMailRealtimeScopeTest`: 1 prueba/3 aserciones aprobadas para el alcance de las invalidaciones.
- Frontend: `npm test` (41 pruebas aprobadas), `npm run build`, `npm run test:ui:ingreso`, recorrido institucional y lint dirigido aprobados.
- API cubre recorrido completo, reenvío obligatorio, CSRF/origen/tenant de cookie, pertenencia de archivos, expiración y recuperación PIN, permisos, límite de plan/identidad, cambio de grado, cupo cambiado tras propuesta, idempotencia y onboarding estudiantil sin institución. Correo: prueba antes de guardar, cifrado, autorización, clave omitida en API, conservar configuración ante fallo, transportes aislados, recuperación de contraseña, conservación de clave solo con igual remitente y errores sin secretos.
- Browser smoke usa fixtures y navegador aislado: configuración, corrección+carga, PIN, borrador, envío, aprobación, confirmación individual y móvil; incluye formulario Gmail, bloqueo tras recargar, solicitud, aprobación central, edición autorizada y desconexión protegida. Capturas en frontend `artifacts/ui-smoke/ingreso-*.png`, `correo-institucional-*.png` y `correo-solicitudes-*.png` (ignoradas en Git). No confundir fixtures del navegador con la verificación SMTP real descrita arriba.

## Antes de producción

Entrega real de PIN/corrección/credenciales, recuperación de contraseña, cola supervisada, HTTPS, escáner real (falla cerrado), restauración externa, política de conservación/consentimiento definida por institución y validación de carga con volúmenes reales. No se desplegó VPS en este trabajo.
