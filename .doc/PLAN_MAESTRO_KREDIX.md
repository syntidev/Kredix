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


## Sprint — Modulo de Conciliacion de Pagos Electronicos (iniciado 2026-09-14)

**Objetivo:** modulo dedicado para ver, buscar y validar pagos electronicos
(Zelle, Binance, Transferencia, Pago Movil) de toda la cartera, no solo el
dia actual como el "Resumen del dia" existente. Motivacion: es dinero real,
no se puede perder de vista ninguna transaccion, y hoy el "Resumen del dia"
carga un numero finito de registros sin busqueda completa por rango.

**Regla de visibilidad (coherente con invariante de CLAUDE.md v1.1):**
listado individual de transacciones (con imagen, referencia, estado de
validacion) visible para TODOS los roles - es lo que los operadores usan
para validar pagos. Cualquier TOTAL/suma agregada del modulo (ej. total
electronico del mes) exclusivo de admin, mismo patron que enCalle/
cobradoHoy/cierreDelDia.

**Fases:**

```
Fase A — Campo "referencia" estructurado y obligatorio segun metodo de pago
          (Zelle/Binance/Transferencia/P.Movil lo exigen, Efectivo no)     ⏳ en progreso
Fase B — Modulo Conciliacion: listado completo filtrable (dia/semana/mes/
          rango custom), por metodo, por estado de validacion, por cliente  🔒 no iniciado
Fase C — Version movil: cards expandibles (no tabla comprimida)            🔒 no iniciado
```

**Ya resuelto por separado, no espera a las fases:**
- Imagen de comprobante abre en lightbox in-page (no navega afuera) —
  commit `5979383`, DONE.

**Datos disponibles por transaccion (a confirmar layout en Fase B):**
metodo de pago, validado/no validado, cliente, fecha, referencia (si la
tiene, Fase A), imagen de comprobante (opcional), monto, registrado_por.

**Deuda relacionada, NO en scope de este sprint:** abonos historicos con
referencia mezclada en el campo "Comentario" (ej. "Nro.5444") no se migran
retroactivamente en Fase A - cantidad a reportar por CLI-A, decision
pendiente de Carlos sobre si vale la pena una migracion de datos aparte.

**Cierra cuando:** las 3 fases esten pusheadas, Carlos revise visualmente
(desktop y movil) el modulo completo, y se decida si se deploya solo o
junto con el proximo lote de cambios.