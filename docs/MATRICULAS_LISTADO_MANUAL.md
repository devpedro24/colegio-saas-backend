# Gestión de matrículas y registro manual — 23-10-2026

`/admisiones/matriculas` es un listado independiente de la consulta de boletines. Requiere `academico.matriculas.gestionar` y capacidad académica del plan. Filtra por año, grupo, convocatoria, origen (manual/enlace) y nombre, con paginación de 30 estudiantes. La ficha muestra información personal y documentos privados; no contiene acciones de notas ni recuperaciones.

API nueva: `GET/POST /api/matriculas`, `GET /api/matriculas/geografia`, `GET /api/matriculas/{token}` y `GET /api/matriculas/{token}/documentos/{document}`. Todos los selectores son opacos; los documentos se resuelven dentro de la matrícula y del tenant. Ninguna respuesta incluye contraseñas ni IDs internos.

La matrícula manual usa una convocatoria configurada del año seleccionado, incluso si ya no está abierta al público. Esto permite reutilizar sus documentos obligatorios y datos adicionales. Selecciona sede y grupo, recibe nombres y apellidos separados, documento, nacimiento, correo, teléfono y dirección, archivos y confirmación expresa del operador de que recibió la autorización legal.

El servidor reutiliza `EnrollmentSubmission`, la validación documental/OCR, MIME, escáner, cuotas, edades y geografía. Solo después de validar todos los archivos registra la revisión manual, crea/reutiliza la cuenta y confirma la asignación del grupo mediante `EnrollmentIntake`. Nunca inventa una verificación de correo. Mantiene límites del plan y cupos; serializa por año e incluye idempotencia por envío. Ante fallo revierte la transacción y elimina exclusivamente los archivos provisionales creados por ese intento.

La cuenta nueva recibe contraseña temporal con cambio obligatorio y vencimiento en 72 horas; la renovación conserva la cuenta. El envío usa la cola y el correo institucional existentes. La respuesta distingue configuración operativa de entrega efectiva: no afirma que el mensaje haya llegado. Producción rechaza un registro manual si el correo institucional no está listo.

Los registros previos sin solicitud se muestran como manuales. No se migran ni eliminan estudiantes históricos. El endpoint antiguo `/api/evaluacion/matriculas` permanece retirado.

Prueba: `test_manual_enrollment_reuses_validations_creates_account_and_exposes_a_separate_roster` cubre documentos obligatorios, idempotencia, cuenta y notificación, filtros, ficha, selectores numéricos y permisos.
