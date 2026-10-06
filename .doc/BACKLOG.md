# Backlog — Taller

Ideas y hallazgos fuera del alcance del Release 1 (Fase 1 + Fase 2a). No se implementan sin decisión de Carlos.

## Registrado por CLI Taller (2026-10-06)

- **Autoguardado de la revisión:** si el técnico sale de la pantalla antes de 600 ms desde el último toque, ese último cambio se pierde (el debounce no se envía al salir). Opción: guardar al ocultar la página (`visibilitychange`) o avisar si hay cambios sin guardar.
- **"La IA está analizando el motivo de ingreso…" no se actualiza sola:** en Revisión el aviso queda hasta recargar la página. Show sí recarga el informe cada 15 s; Revisión podría usar lo mismo.
- **Calidad de las sugerencias:** con el motivo "frena mal y suena la cadena", Nemotron sugirió además "Cauchos · Cambiado" y "Cambio trasero · Ajustado". El técnico las descarta, pero conviene revisar el prompt de `SugerirRevision` (v1).
- **El redactor IA inventa recomendaciones:** en 2 de 3 corridas sobre el ticket #70 mencionó el cassette sin estar en la revisión. El control `requiere_revision` lo detectó las dos veces. Revisar el prompt de `RedactarInforme` (v2).
- **Jobs manuales vs. cola:** `ia:sugerir` / `ia:redactar` no quitan el job que el Observer ya encoló. Si después corre el worker, `SugerirRevision` vuelve a escribir `sugerencias_ia` completo y borra la lista de descartadas. En producción no pasa (el worker corre primero), pero conviene que el job conserve `descartadas`.
- **Peor caso del PDF:** una revisión con 23 componentes y notas largas lleva el PDF a 5 páginas (main: 3), con las fotos de salida en la última. El tamaño de las fotos se mantiene igual que en main.
- **Texto automático, trato de usted:** el informe de la IA respeta `IA_TRATO`; el texto automático siempre tutea.
- **Hallazgo de seguridad con otro motivo:** si una pieza tiene fisura o fuga y además desgaste/ruido, el texto solo menciona el hallazgo de seguridad (nunca recomienda cambiarla). El motivo secundario queda solo en la tabla del PDF.
