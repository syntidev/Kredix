# Plan — Taller SMART (Kredix)

**Estado:** APROBADO por Carlos el 2026-10-06 ("adelante con todo desde ya").
**Reemplaza** la sección de fases de `DISENO_TALLER_REVISION_TECNICA.md`. El diagnóstico de ese documento sigue vigente.
**Arquitecto:** Claude Web · **Ejecución:** CLI-A · **Deploy:** Carlos (manual)

---

## Visión

El técnico no llena formularios. Toca, o habla, y el sistema arma el informe, recuerda la historia de la bici y le avisa al cliente cuándo volver.

Cinco momentos SMART:

1. **Captura sin fricción:** botones grandes en el puesto de trabajo, y luego voz.
2. **Informe que se escribe solo:** primero con plantilla y luego redactado por IA, siempre aprobado por una persona.
3. **Memoria de la bici:** al volver, el sistema muestra lo que se hizo y lo que quedó recomendado.
4. **Predicción:** próximo servicio sugerido y recordatorio por WhatsApp.
5. **Seguimiento en vivo:** el QR del ticket muestra al cliente en qué etapa está su bici.

**Regla de oro:** ningún texto generado por IA llega al cliente sin aprobación humana. La IA solo puede mencionar lo que está registrado en la revisión.

---

## Fases

### Fase 0 — Cimientos (en paralelo con la Fase 1)

| # | Tarea | Responsable | Criterio de cierre |
|---|---|---|---|
| 0.1 | Enviar la lista de modelos disponibles en la cuenta NVIDIA | Carlos | Lista pegada en el chat |
| 0.2 | Elegir los modelos: texto (estructurar y redactar en español), voz a texto en español y, más adelante, visión | Claude Web | Decisión documentada con justificación |
| 0.3 | Cliente de IA en Laravel + comando `ia:probar` | CLI-A | `php artisan ia:probar` responde desde local y desde el VPS, con latencia medida |
| 0.4 | Grabar 5 dictados reales de 30 a 60 segundos en el taller, con el ruido real | Carlos / técnicos | 5 audios disponibles |
| 0.5 | Validar en 15 minutos con Carlos Rojas y Kleiver la lista de componentes y el contenido de cada paquete (`config/taller.php`) | Carlos | Lista corregida devuelta |
| 0.6 | Teléfono o tablet fijo en el puesto de trabajo | Carlos | Dispositivo instalado con sesión del técnico |
| 0.7 | Prueba de voz: audios → transcripción → JSON de revisión | CLI-A + Claude Web | Al menos 4 de 5 dictados bien estructurados, o la voz se descarta como captura principal |

### Fase 1 — Revisión técnica estructurada (la base de todo)

- Nueva columna `revision_tecnica` (JSON) + `revisado_por` + `revisado_en`.
- Catálogo de componentes, acciones, motivos y paquetes en `config/taller.php` (borrador hasta que se cierre la tarea 0.5).
- Pantalla `Taller/Revision.vue` para el celular:
  - botones grandes, guardado automático, botón "Todo OK" por grupo;
  - cámara directa para la foto de salida.
- Lista "Mis tickets" para el técnico logueado.
- Informe determinístico: genera el borrador de `trabajo_realizado` a partir de la revisión.
- Requisito de cierre: una revisión con al menos 1 acción **o** texto manual.
- PDF: tabla "Revisión técnica", "Recomendaciones" y "Revisado por".
- Se quita el checklist del alta de recepción. Los tickets viejos siguen viéndose igual.
- **Métrica:** al menos 50% de tickets nuevos con `revisado_por = mecanico_id` en 2 semanas. Hoy es 0%.

### Fase 2 — IA en la captura y en el informe

- **Botón de micrófono en la pantalla de revisión.** Flujo: audio → transcripción → el modelo de texto devuelve un JSON contra el catálogo. Los componentes que detecta aparecen marcados como "sugerido por IA" (`origen: "ia"`) y el técnico los confirma o descarta con un toque.
- **Botón "Redactar con IA" para recepción.** Convierte la revisión en 3 o 4 frases para el cliente. Recepción edita y aprueba. Si la IA falla, se usa el texto determinístico.
- **Mantenimiento:** se registra cada llamada (tiempo de respuesta, error o éxito) en un log simple, sin guardar audio ni datos personales.

### Fase 3 — Memoria y predicción

- **Identificar la bici entre visitas:** misma `cliente_id` + `bici_marca_modelo` normalizada. Decisión de arquitectura pendiente: si hace falta una tabla `bicicletas`, se decide en esta fase.
- **Pendientes de la visita anterior:** al crear un ticket y al abrir la revisión aparece, por ejemplo, "Recomendado el 12/09: cambio de piñones (desgaste)".
- **Próximo servicio sugerido:** reglas basadas en el historial (recomendaciones pendientes + tiempo desde el último servicio). La IA redacta el mensaje.
- **Recordatorio por WhatsApp:** reutiliza el deep link y las plantillas que ya existen.
- [Seguro] Con unos 20 tickets históricos no hay datos para un modelo estadístico real. La predicción empieza basada en reglas y en el historial, y se vuelve estadística cuando haya cientos de servicios. No se promete más que eso.

### Fase 4 — Seguimiento en vivo y etapas

- Etapas con hora registrada: Recepción → Lavado → Revisión → Servicio → Control de calidad → Listo → Entregado. [Suposición] El flujo exacto de la bañera se confirma con el taller.
- Foto opcional "después del lavado".
- Página pública desde el QR del ticket, con un token que no se pueda adivinar y datos mínimos: estado, bici y etapa. Nunca teléfono ni montos.
- KPI de tiempo por etapa y por técnico.

### Fase 5 — Exploratoria

- Visión por IA sobre las fotos (describir daños visibles). Solo si las fases anteriores funcionan y el modelo demuestra precisión real.

---

## Riesgos vigilados

| Riesgo | Mitigación |
|---|---|
| Ruido del taller degrada la voz | La prueba 0.7 decide antes de construir; los botones de la Fase 1 siempre quedan como respaldo |
| [Suposición] Los modelos de voz de NVIDIA podrían requerir gRPC (Riva) y no REST simple | Se verifica en 0.3/0.7. Alternativa: dictado nativo del navegador en Chrome Android + modelo de texto en NVIDIA |
| La IA inventa trabajos no realizados | Prompt restringido al JSON de la revisión + aprobación humana obligatoria |
| El técnico igual no lo usa | La misma pantalla sirve a recepción, así que el peor caso no es peor que hoy. Si la métrica de la Fase 1 no se mueve, el problema es operativo |
| Romper la paginación del PDF, ya estabilizada | Verificación visual obligatoria contra los tickets reales #7, #13 y #24 |

---

## Bitácora de decisiones

- 2026-10-06 — Fase 1 aprobada con sus 5 decisiones: columna nueva `revision_tecnica`, checklist fuera del alta, nuevo requisito de cierre, catálogo como config y revisión en el PDF.
- 2026-10-06 — Proveedor de IA: NVIDIA (cuenta de Carlos). El modelo se elige en 0.2.
