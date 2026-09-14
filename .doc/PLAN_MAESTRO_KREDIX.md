## Sprint — Rediseño visual por fases (2026-09-14)

**Objetivo:** aplicar sistema de tokens visuales (shadow/blur/color/radius,
identidad OnBike Margarita — marino #0B1F3A + crema #F5F3EE, semaforo de
negocio rojo/verde/naranja intacto) a toda la app, en fases quirurgicas
verificadas una por una, con deploy unico al final (decision explicita de
Carlos: acumular todo, no deployar fase por fase).

**Referencia de diseño:** `.doc/kredix-template-referencia.html`

**Estado por fase:**

```
Fase 1 — Tokens globales + Home + Ficha cliente   ✅ DONE (commit 6f7e475)
Fase 2 — Cartera general                          ✅ DONE (commit d552ba9)
Fase 3 — Modales de dinero (cargo/abono)           ✅ DONE (commit 0edc52e)
Fase 4 — Modales de edicion (editar/gestion)       ⏳ en progreso
Fase 5 — Clientes, KPI, Configuracion              ⏳ en progreso
```

**Fixes relacionados, ejecutados en paralelo durante el mismo sprint:**
- `fix(seguridad)`: agregados de cartera (en calle, cobrado hoy, cierre del
  dia por metodo) ocultos a usuarios no-admin — commits `3e46346`, `3cc9203`.
  Saldos/movimientos de cliente individual siguen visibles a todos los roles.
- `fix(pdf)`: Web Share API para exponer compartir en iOS PWA standalone —
  commit `bceef9c`.
- `fix(ux)`: scroll-lock de body en los 8 modales de Show.vue — corrige
  arrastre de modal en Safari iOS — commit `3ede137`.
- Pendiente de lanzar (bloqueado hasta que Fase 4/5 pusheen): mostrar autor
  (`registrado_por`) de cada movimiento en Home y Ficha de cliente, texto
  secundario, visible a todos los roles (dato individual, no agregado).

**Protocolo usado (2 sesiones CLI en paralelo sobre el mismo repo):**
`git pull origin main` obligatorio antes de cada commit; ningun amend/rebase
de commits ajenos sin confirmacion explicita de Carlos. Detallado en
`CLAUDE.md` v1.1, seccion "Protocolo de sesiones CLI paralelas".

**Cierra cuando:** Fase 4 y 5 pusheadas, fix de atribucion de autor aplicado,
Carlos hace review visual completo (no solo reporte de texto de los agentes)
de las 5 fases en local, y recien ahi se ejecuta el deploy unico a
kredix.synti.cloud con el bloque de deploy estandar de CLAUDE.md.

**Leccion de proceso de este sprint (para no repetir):** Fase 1 se dio por
"verificada" en base a reportes de texto de CLI-A sin revision visual real,
y quedo con contenido duplicado en el Home (tiles viejos + nuevos conviviendo)
hasta que Carlos lo detecto por percepcion propia varias fases despues.
Desde entonces, Regla del Policia criterio 6 (aprobacion visual del
arquitecto) se exige en vivo con captura real, no por descripcion del agente.
