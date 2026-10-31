# Planilla: vista previa y porcentajes de actividades

La planilla recalcula al editar una nota numérica o seleccionar una categoría visual. Usa el mismo motor decimal del guardado, el redondeo del SIEE y las equivalencias de la escala del grupo. Una petición agrupa las celdas pendientes tras una pausa de 350 ms. Una respuesta anterior no reemplaza una edición posterior. Los demás preinformes mantienen sus valores y los cambios siguen requiriendo Guardar.

`POST /evaluacion/planillas/{asignacion}/{periodo}/vista-previa` usa el contrato opaco, autenticación, permiso de escritura y asignación docente. Rechaza notas fuera de escala, períodos cerrados y versiones desactualizadas. Calcula sobre modelos en memoria; no persiste calificaciones ni genera eventos académicos o invalidaciones de caché. La auditoría HTTP conserva únicamente método, ruta y estado. Si el cálculo falla, la interfaz lo advierte y conserva el cálculo guardado.

Los preinformes automáticos de una materia y período comparten el modo de sus actividades: promedio simple o ponderado. Cambiar ese modo actualiza todos los componentes automáticos, con permisos y auditoría. Los componentes manuales mantienen su registro directo. El modo simple ignora los pesos para calcular, pero los conserva en cada actividad. Volver a ponderado restaura esos mismos porcentajes (por ejemplo 20/80 o 90/10). Solo cuando ninguna actividad tiene peso guardado se inicializa un reparto igual con cuatro decimales; la última absorbe el residuo para sumar exactamente 100 %. Cada cambio de modo aumenta las versiones del componente y de sus filas, aunque los pesos se conserven. Los nuevos componentes heredan el modo automático de sus hermanos.

Crear o eliminar actividades en simple no redistribuye los pesos personalizados de las demás. Las nuevas actividades sin porcentaje permanecen con peso nulo al volver a ponderado cuando existen otros pesos guardados: hay que asignarles un porcentaje explícitamente. Eliminar una actividad conserva los pesos de las restantes, aunque el total quede parcial. El resultado oficial seguirá pendiente hasta que todas tengan peso y la suma sea exactamente 100 %. No se normalizan ni se sobrepasan los porcentajes silenciosamente.

Cada actividad ponderada requiere un peso positivo. Crear o editar no puede superar el 100 % de su componente; editar excluye el peso anterior del total. Se permiten configuraciones incompletas por debajo del 100 % mientras se preparan las actividades; el resultado oficial requiere completar exactamente el 100 %. Esto es independiente del peso de cada preinforme dentro del período (por ejemplo 20/25/25/30).

## Edición rápida de todas las actividades

`PUT /api/evaluacion/planillas/{asignacion_token}/{periodo_token}/actividades` admite `operacion: "editar_lote"` en el mismo endpoint de crear/editar/eliminar/cambiar modo. Requiere sesión, asignación autorizada y el permiso existente `notas.actividades.editar`; no agrega permisos a la matriz. El cuerpo es:

```json
{
  "operacion": "editar_lote",
  "componente_token": "<selector opaco de 24 caracteres>",
  "version": 3,
  "actividades": [
    {"actividad_token": "<selector opaco de 24 caracteres>", "version": 5, "nombre": "Quiz", "fecha": "2026-02-05", "peso": "90"},
    {"actividad_token": "<selector opaco de 24 caracteres>", "version": 5, "nombre": "Taller", "fecha": "2026-02-06", "peso": "10"}
  ]
}
```

Se usa `componente_token` o `preinforme_token`; si se envían ambos, deben seleccionar la misma sección. `version` corresponde a `section.version`, y cada fila incluye su propia `version`. `actividades` contiene entre 1 y 1000 filas y debe incluir todas las actividades existentes de la sección exactamente una vez; el lote no crea ni elimina. Se rechazan IDs internos incluso dentro de las filas. No requiere `opaque=1`: el contrato público opaco es obligatorio por defecto.

En ponderado cada fila requiere `peso` mayor que cero y menor o igual a 100, con hasta cuatro decimales. Acepta números o texto decimal, incluida coma decimal. Se valida la suma final con aritmética decimal exacta antes de guardar: 20/80 → 90/10 funciona aunque la primera edición individual excedería 100. Un total positivo menor que 100 se puede guardar como configuración provisional; no permite emitir un cálculo definitivo. En simple se omite `peso` para conservarlo; un `null` también conserva el porcentaje guardado. Si se envían nuevos pesos explícitos en simple, se valida que estén entre 0 y 100 y que el conjunto no supere 100. Se permiten los ceros históricos en simple, donde se ignoran: no bloquean renombrar ni editar fechas. Activar ponderado con algún cero devuelve 422 y pide corregir los pesos en simple, sin redistribuir porcentajes personalizados. La edición antigua de actividades aplica la misma preservación y aumenta la versión de fila; la edición antigua de componentes también aumenta su versión para detectar cambios de modo concurrentes.

Los nombres se recortan, deben ser no vacíos y únicos dentro del conjunto final, y pueden intercambiarse entre filas. Las fechas deben pertenecer al período y, cuando corresponde, a las fechas estrictas del preinforme. El año debe estar en curso y el período abierto; se respeta el plan contratado para preinformes. Los componentes manuales no admiten el lote.

La operación usa una sola transacción con los locks existentes de año/período y bloqueo de filas. Valida versiones y membresía completa antes de escribir, aumenta cada versión de actividad y registra auditoría por fila mediante Eloquent. Conserva las notificaciones e invalidaciones existentes después del commit; un fallo revierte filas, auditorías e invalidaciones. No escribe calificaciones. Devuelve `{"data":{"guardado":true}}`, compatible con invalidar consultas y recargar la planilla.

Errores: 401 sin sesión; 403 sin permiso, asignación o plan habilitado; 404 para selectores inexistentes, de otro colegio, tipo o padre, o actividades ya eliminadas; 409 para versiones desactualizadas o un conjunto que omite actividades existentes; 422 para validaciones, fechas, modo manual o período/año no editables. Todos dejan el lote completo sin guardar.

## Otras reglas de la planilla

«Resultado manual» es una nota o categoría final registrada directamente para un componente o materia configurados como manuales. No promedia actividades. Exige motivo, versión y autorización, conserva auditoría y participa en el cálculo superior según el SIEE.

La migración tenant `2026_10_27_000001_align_pre_report_activity_modes` alinea las planillas abiertas existentes que mezclaban promedios: si alguna usa ponderación, convierte las simples a ponderadas mediante el mismo servicio, conservando porcentajes guardados e inicializando pesos iguales solo si no hay ninguno. No modifica períodos cerrados, años que no están en curso, notas de actividades ni componentes manuales. Los cambios de configuración quedan auditados como ajuste del sistema. Se aplica con el procedimiento de migraciones tenant del proyecto.

La tabla usa violeta y azul suaves de la paleta existente, texto oscuro y resultados de preinformes/definitiva en negrita. Los cambios pendientes se distinguen con amarillo.
