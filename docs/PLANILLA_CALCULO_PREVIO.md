# Planilla: vista previa y porcentajes de actividades

La planilla recalcula al editar una nota numérica o seleccionar una categoría visual. Usa el mismo motor decimal del guardado, el redondeo del SIEE y las equivalencias de la escala del grupo. Una petición agrupa las celdas pendientes tras una pausa de 350 ms. Una respuesta anterior no reemplaza una edición posterior. Los demás preinformes mantienen sus valores y los cambios siguen requiriendo Guardar.

`POST /evaluacion/planillas/{asignacion}/{periodo}/vista-previa` usa el contrato opaco, autenticación, permiso de escritura y asignación docente. Rechaza notas fuera de escala, períodos cerrados y versiones desactualizadas. Calcula sobre modelos en memoria; no persiste calificaciones ni genera eventos académicos o invalidaciones de caché. La auditoría HTTP conserva únicamente método, ruta y estado. Si el cálculo falla, la interfaz lo advierte y conserva el cálculo guardado.

Los preinformes automáticos de una materia y período comparten el modo de sus actividades: promedio simple o ponderado. Cambiar ese modo actualiza todos los componentes automáticos, con permisos y auditoría. Los componentes manuales mantienen su registro directo. Al pasar de simple a ponderado, las actividades existentes reciben porcentajes iguales con cuatro decimales y el último absorbe el residuo para sumar exactamente 100 %. Los pesos ponderados ya existentes se conservan. Los nuevos componentes heredan el modo automático de sus hermanos.

Cada actividad ponderada requiere un peso positivo. Crear o editar no puede superar el 100 % de su componente; editar excluye el peso anterior del total. Se permiten configuraciones incompletas por debajo del 100 % mientras se preparan las actividades; el resultado oficial requiere completar exactamente el 100 %. Esto es independiente del peso de cada preinforme dentro del período (por ejemplo 20/25/25/30).

«Resultado manual» es una nota o categoría final registrada directamente para un componente o materia configurados como manuales. No promedia actividades. Exige motivo, versión y autorización, conserva auditoría y participa en el cálculo superior según el SIEE.

La migración tenant `2026_10_27_000001_align_pre_report_activity_modes` alinea las planillas abiertas existentes que mezclaban promedios: si alguna usa ponderación, convierte las simples a ponderadas con pesos iguales. No modifica períodos cerrados, años que no están en curso, notas de actividades ni componentes manuales. Los cambios de configuración quedan auditados como ajuste del sistema. Se aplica con el procedimiento de migraciones tenant del proyecto.

La tabla usa violeta y azul suaves de la paleta existente, texto oscuro y resultados de preinformes/definitiva en negrita. Los cambios pendientes se distinguen con amarillo.
