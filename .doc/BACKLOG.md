# Backlog — Taller

Ideas y hallazgos fuera del alcance del Release 1 (Fase 1 + Fase 2a). No se implementan sin decisión de Carlos.

## Registrado por CLI Taller (2026-10-06)

- **Autoguardado de la revisión:** si el técnico sale de la pantalla antes de 600 ms desde el último toque, ese último cambio se pierde (el debounce no se envía al salir). Opción: guardar al ocultar la página (`visibilitychange`) o avisar si hay cambios sin guardar.
- **"La IA está analizando el motivo de ingreso…" no se actualiza sola:** en Revisión el aviso queda hasta recargar la página. Show sí recarga el informe cada 15 s; Revisión podría usar lo mismo.
- **Calidad de las sugerencias:** con el motivo "frena mal y suena la cadena", Nemotron sugirió además "Cauchos · Cambiado" y "Cambio trasero · Ajustado". El técnico las descarta, pero conviene revisar el prompt de `SugerirRevision` (v1).
- **El redactor IA inventa recomendaciones:** en 2 de 3 corridas sobre el ticket #70 (prompt v2) mencionó el cassette sin estar en la revisión. El control `requiere_revision` lo detectó las dos veces. El prompt v3 de CLI IA apunta a esto; volver a medir.
- **Jobs manuales vs. cola:** `ia:sugerir` / `ia:redactar` no quitan el job que el Observer ya encoló. Si después corre el worker, `SugerirRevision` vuelve a escribir `sugerencias_ia` completo y borra la lista de descartadas. En producción no pasa (el worker corre primero), pero conviene que el job conserve `descartadas`.
- **Peor caso del PDF:** una revisión con 23 componentes y notas largas lleva el PDF a 5 páginas (main: 3), con las fotos de salida en la última. El tamaño de las fotos se mantiene igual que en main.
- **Trato de usted:** el texto automático y el prompt v3 de la IA fijan el tuteo. La guía de voz pide que sea configurable (tú/usted); hoy no lo es.
- **Hallazgo de seguridad con otro motivo:** si una pieza tiene fisura o fuga y además desgaste/ruido, el texto solo menciona el hallazgo de seguridad (nunca recomienda cambiarla). El motivo secundario queda solo en la tabla del PDF.

## Registrado por CLI IA (cierre R1, 2026-10-06)

Ordenado por impacto.

1. ~~**Texto automático de CLI Taller contradice la regla de fisura/fuga.**~~ **Resuelto en `feb7242`:** el texto automático
   ahora dice "Por seguridad, te recomendamos no rodar hasta que un especialista evalúe {pieza}: encontramos una fisura"
   y nunca recomienda cambiar esa pieza.
2. **Modelo de producción sin definir.** `NVIDIA_MODELO_TEXTO` está vacío en `.env`. DeepSeek v4.1 flash dio timeout a
   280 s en las dos pruebas reales; Nemotron 3.5 lightning responde en 4–30 s. Decide Carlos.
3. **Nemotron devuelve JSON inválido de vez en cuando** (1 de 5 llamadas en la prueba R1). Hoy termina en `error` sin
   reintento, porque los errores de validación no se reintentan. Opciones: un reintento solo ante `JsonException`, o
   salida guiada (`nvext.guided_json` o `response_format`) si el modelo la soporta.
4. **Cron del VPS.** Sin `* * * * * php artisan schedule:run` la cola `ia` nunca se procesa.
5. **El detector no ve omisiones ni inventos sobre componentes que sí están en la revisión.** v3 lo mitiga: el modelo solo
   une frases de hechos ya redactadas. La aprobación humana sigue siendo la red de seguridad.
6. **Gramática menor en el cuerpo:** falta la coma antes de "y están en buen estado" en enumeraciones largas.
7. **SugerirRevision sigue en prompt v1:** solo se probó con éxito en un caso con Nemotron.
8. **Suite de tests:** 27 fallos previos porque la migración `prospectos_200k` no corre en sqlite (drop de una columna
   indexada). Los tests de IA crean su propia tabla mínima para esquivarlo.
9. **Datos de prueba en la DB local compartida:** tickets #65–#69 (motivo "prueba R1" o bici "[DEMO] IA …").
   CLI Taller agregó #59–#64 y #70–#71 (bici "E2E …") y el usuario `e2e.tecnico@kredix.local`.

## Registrado por CLI Principal (hotfix financiar-multiproducto, 2026-10-06)

- **Agrupar el estado de cuenta por compra.** Con `compra_id` ya existe el dato para hacerlo, pero quedó fuera del
  alcance del hotfix. Hoy una compra de 6 productos sale como 6 renglones sueltos en el PDF del cliente, sin una línea
  que diga "compra del 11/09: $574,00". Las compras anteriores al hotfix tienen `compra_id` NULL y seguirían renglón
  por renglón, así que la pantalla tendría que tolerar las dos formas.
- **Guarda al borrar líneas de una compra financiada.** Hoy `MovimientoCuentaController::destroy()` permite anular
  cualquier línea de un carrito financiado sin avisar nada. Por decisión de Carlos el total pactado del plan NO cambia
  (se lee con `withTrashed()`), así que tras anular una línea las cuotas siguen sumando el total original mientras el
  saldo del cliente baja. Es coherente con "renegociar es explícito", pero el operador no recibe ninguna advertencia.
  Falta: avisar en el modal de eliminación que la línea pertenece a una compra financiada y que el plan no se ajusta
  solo, y ofrecer el camino de renegociación.

## Registrado por CLI Taller (Entrega A, 2026-10-06)

- **Vista previa del PDF por GET con el texto en la URL.** El botón "Vista previa" abre
  `/taller/{id}/atencion-….pdf?vista_previa=1&texto=…`: el borrador viaja en la URL (queda en el historial del navegador
  y en los logs del servidor, y tiene tope práctico de largo). Pasarla a POST (formulario a una pestaña nueva o fetch +
  blob) sin cambiar el PDF que se genera.
- **Etiqueta "anterior al control" para textos legados.** Los tickets con texto anterior a la compuerta (estado null) se
  tratan como manual aprobado y Show dice "Texto registrado antes de la aprobación de textos". Unificar con una etiqueta
  corta y consistente ("anterior al control") en la insignia de estado, para distinguirlos de un aprobado con nombre y hora.
