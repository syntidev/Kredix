# SYSTEM_MAP.md — Kredix
# Arbitro unico de verdad sobre el estado real del sistema
# Ultima actualizacion: 2026-10-06

---

## Estado general

- **Stack real:** Laravel 12.x + Inertia + Vue 3 + Tailwind v3 (bajado de v4 por
  incompatibilidad con Breeze) + Spatie MediaLibrary + Spatie ActivityLog + Breeze
  (self-signup deshabilitado)
- **Repo:** github.com/syntidev/kredix, rama `main` en `849ff80`
- **Local:** `C:\laragon\www\kredix`, MySQL real
- **VPS:** `/var/www/kredix`, MySQL dedicado, Nginx con SSL (Cloudflare Origin
  Certificate, modo Full — NO strict, otros sitios del VPS no lo soportarian)
- **Cliente final:** OnBike Margarita (razon social OTOMIX MARGARITA, C.A.)
- **Volumen desde la ultima actualizacion de este archivo:** 221 commits entre
  2026-09-09 y 2026-10-06. Este documento estuvo casi un mes desactualizado — lo que
  sigue es el resumen por modulo, no el detalle commit por commit.
- **Worktrees activos (4):**
  - `kredix` → `main` (849ff80)
  - `kredix-ia` → `feat/ia-cliente` (Taller SMART Fase 0, en construccion)
  - `kredix-taller` → `feat/taller-revision` (Taller SMART Fase 1, en construccion)
  - `kredix-estetica` / `kredix-funcional` → ramas ya mergeadas, pendientes de borrar
    (ver Housekeeping)

## Regla del Policia — estado real

```
Clientes            ✅ certificado y en produccion
Cuenta corriente     ✅ certificado (reemplazo el modelo original de venta-por-venta)
Abonos/Gestion       ✅ certificado, incluye tipo gestion (contacto sin pago)
Cartera general      ✅ certificado, con semaforo de color, filtro por monto y export
Taller               ✅ en produccion y en uso real (modulo nuevo, no estaba en el
                        orden original de la Regla del Policia) — PERO el tecnico
                        nunca lo ha operado: 0 de 12 tickets activos tienen
                        registrado_por = mecanico_id (ver auditoria del 06/10)
Conciliacion         ⚠️ entregado, gateado por users.acceso_conciliacion
                        [sin verificar] si Carlos lo certifico formalmente
KPI                  ⚠️ dashboard funcional, PERO formula de buen/mal pagador
                        (clasificacion manual) sigue sin definir por Carlos
Importacion lote 2   🔒 bloqueada — entrevistas de campo no iniciadas
Notificaciones       🔒 no iniciado
```

## Desplegado desde la ultima actualizacion (2026-09-09 → 2026-10-06)

Reparto por modulo de los 221 commits (por scope del mensaje de commit):

```
taller 30 · clientes 23 · ui 15 · pdf 13 · movimientos 12 · seguridad 10 · kpi 9
prospectos 8 · home 8 · cartera 8 · ux 7 · nav 6 · bcv 6 · configuracion 4
financiamiento 3 · cartelera 3 · conciliacion 2 · uploads 2 · usuarios 1
```

Lo mas relevante, en bloques:

- **Taller (modulo nuevo completo):** tickets de servicio/armado interno, checklist de
  diagnostico en JSON, repuestos, fotos de entrada/salida, ticket fisico 58mm con QR,
  PDF de atencion, rol `rol_taller` con middleware de confinamiento a `/taller`,
  cargo automatico a credito al cerrar un ticket no pagado en el momento
- **Cartera y analisis:** filtro por rango de monto, export a Excel con hoja de
  posibles duplicados, sanitizacion contra formula injection en el export
- **Estado de cuenta / PDF:** plan de pagos con cronograma de cuotas, banners
  publicitarios opcionales configurables
- **Clientes:** triage de cuentas soft-deleted con saldo (`estado_revision`),
  herramienta temporal de "Cuentas en revision" (agregada y ya retirada del menu)
- **Configuracion:** 3 plantillas de WhatsApp con selector activo

Los 4 commits del cierre de jornada del 01-02/10:

| Commit | Fecha | Que resolvio |
|---|---|---|
| `9eef654` | 02/10 | Abono tipo `ajuste/devolucion` no guardaba — lo bloqueaba una validacion oculta de `plan_financiamiento_id` |
| `495320a` | 02/10 | Boton "Siguiente" en ficha de cliente y ticket de taller, estandar unico de visualizacion de imagenes (`ComprobanteLightbox` en todo el sistema), desglose del servicio en el PDF de taller, y fix de "Dinero en calle" en Home para que cuadre con KPI/Cartera |
| `f6c882f` | 02/10 | Cargo a credito de taller se mostraba en $0.00 en la ficha del cliente (la fila de un cargo se pinta con `precio_unitario`, que el cargo automatico dejaba en null) + guard permanente: un ticket a credito ya no puede quedar "atendido" sin cargo real |
| `849ff80` | 02/10 | Rotacion forzada a vertical y compresion de fotos client-side en los 6 puntos de subida + ajuste de tamano de fotos en el PDF de taller |

## Cambios hechos fuera de git (no aparecen en el log)

- **02/10 — 55 cuentas del "Grupo B" restauradas a saldo $0.00.** Operacion directa en
  la base de datos de produccion: 51 cuentas via asiento `ajuste_devolucion` y 4 via
  asiento `cargo` (las 4 tenian saldo negativo, y `ajuste_devolucion` solo puede
  restar, por lo que no podia llevarlas a cero). **Efecto colateral aceptado por
  decision explicita de Carlos:** esos 4 cargos suman al KPI "Otorgado" (lo que Carlos
  llama "mercancia entregada") y lo inflan en **$2,415.00** [cifra aportada por Carlos,
  no re-derivada en esta actualizacion]. Todas las cuentas quedaron con la misma nota
  de auditoria y con `registrado_por` trazable.
- **02/10 — Limites de subida ampliados en el VPS** (verificado en el servidor el
  06/10):
  - Nginx `/etc/nginx/sites-available/kredix`: `client_max_body_size 30M`
  - PHP-FPM `/etc/php/8.3/fpm/php.ini`: `post_max_size = 20M` y
    `upload_max_filesize = 20M` (el pedido de esta actualizacion decia 15M; el valor
    real aplicado es 20M)
  - Nota: `php -i` por CLI reporta 2M/8M porque la CLI usa otro `php.ini`. Eso es
    normal y no afecta las subidas por web, que pasan por FPM.

## En construccion (Taller SMART)

Plan maestro: `.doc/PLAN_TALLER_SMART.md` (aprobado por Carlos el 2026-10-06).
Diagnostico de base: auditoria de solo lectura en `.doc/auditoria-taller-2026-10-06/`.
Ambos archivos estan **sin commitear todavia** (untracked en `main`).

- **Fase 0 — Cimientos** · rama `feat/ia-cliente` · worktree `C:\laragon\www\kredix-ia`
  Cliente de IA en Laravel (proveedor NVIDIA, cuenta de Carlos), comando `ia:probar`,
  prueba de voz a texto con dictados reales del taller.
  Estado: **0 commits por delante de `main`** — rama creada, sin trabajo commiteado aun.
- **Fase 1 — Revision tecnica estructurada** · rama `feat/taller-revision` · worktree
  `C:\laragon\www\kredix-taller`
  Columna `revision_tecnica` (JSON) + `revisado_por` + `revisado_en`, catalogo en
  `config/taller.php`, pantalla `Taller/Revision.vue` para celular, lista "Mis tickets",
  informe deterministico, revision en el PDF.
  Estado: **0 commits por delante de `main`** — rama creada, sin trabajo commiteado aun.
  Metrica de exito definida en el plan: 50% de tickets nuevos con
  `revisado_por = mecanico_id` en 2 semanas (hoy: 0%, medido el 06/10).

Ninguna de las dos ramas existe todavia en `origin` — ambas son locales.

## Pendientes reales

- **Verificacion visual del ajuste de tamano de fotos del PDF de taller** (`849ff80`) —
  nunca se rasterizo el PDF resultante para compararlo. El commit se pusheo con esta
  limitacion declarada en su propio mensaje.
- **Prueba real con celular de la compresion de imagenes** (`849ff80`) — el codigo esta
  verificado a nivel de logica, pero no existe medicion de peso antes/despues con una
  foto real de camara. Sin acceso a navegador en la sesion que lo implemento, no se
  pudo generar esa prueba.
- **Rotar la API key de NVIDIA** — [sin verificar] no hay ninguna key de NVIDIA en el
  repo (la unica mencion al proveedor esta en `PLAN_TALLER_SMART.md`), asi que la key
  vive fuera del repositorio y su rotacion no se puede confirmar desde aqui.
- ~~Vulnerabilidades de npm sin revisar~~ — **resuelto parcialmente el 06/10**
  (commit `67193f7`). Se analizaron las 10 una por una: ninguna llega al navegador
  (0 rastros en `public/build`), todas son herramientas de compilacion. Se elimino
  `concurrently` (dependencia muerta: ningun script la usaba) y con ella las 2
  criticas, y `npm audit fix` subio `source-map-js` a 1.2.2. Quedan 7, todas de la
  cadena de Tailwind — ver Riesgos aceptados.
- **Formula de "buen/mal pagador" sin definir** — sigue siendo decision de Carlos y
  sigue bloqueando que el modulo KPI se considere completo. Es el mismo pendiente que
  ya figuraba en la version del 2026-09-09 de este documento.

## Riesgos aceptados (decision consciente, no son pendientes)

- **7 vulnerabilidades de Tailwind v3 (solo de compilacion, no llegan al navegador).**
  Se aceptan por decision de stack: migrar a v4 rompe la paleta. Revisar si en algun
  momento se migra Tailwind.
  Detalle para quien lo revise despues: son `tailwindcss` 3.4.19 (directa) mas
  `braces`, `chokidar`, `fast-glob`, `micromatch`, `postcss-nested` y
  `postcss-selector-parser` (todas transitivas suyas). Son agotamiento de CPU /
  denegacion de servicio, explotables solo por quien controle la entrada del proceso
  de build — en Kredix el build lo corre Carlos con su propio codigo. El unico fix que
  ofrece npm es Tailwind 4.3.3, salto mayor: el CLAUDE.md fija v3 porque Breeze para
  Vue no soporta el enfoque CSS-first de v4 y la paleta vive en `tailwind.config.js`,
  no en `@theme`.
- **4 asientos `cargo` del Grupo B inflando el KPI "Otorgado" en $2,415.00** — ver
  Cambios hechos fuera de git. Aceptado explicitamente por Carlos para poder llevar a
  cero las 4 cuentas con saldo negativo.

## Housekeeping pendiente (bajo riesgo, sin apuro)

- Borrar worktrees ya mergeados: `git worktree remove kredix-estetica` y
  `kredix-funcional`, mas las ramas locales y remotas `feature/estetica` /
  `feature/funcional` (confirmado el 06/10: los 4 siguen existiendo)
- Archivos muertos de Breeze sin referencias (AuthenticatedLayout, NavLink,
  ResponsiveNavLink, DropdownLink, Dropdown) — limpiar en una pasada dedicada
- Rotar password del usuario de prueba `sistema@kredix.local` y el certificado de
  Cloudflare (quedaron en texto plano en el historial de un chat)
- ~~Commitear o descartar los documentos untracked en `.doc/`~~ — **resuelto el
  06/10**: `PLAN_TALLER_SMART.md` (`6dfa42f`), `ADENDA_app-env-produccion.md` y
  `auditoria-taller-2026-10-06/INFORME.md` ya estan versionados. Las copias de codigo
  fuente de esa carpeta de auditoria quedaron en `.gitignore` a proposito (duplican
  archivos de `app/` y `resources/` y se desactualizan solas)
- 3 cuentas reales de los operadores: parcialmente resuelto — ya existen usuarios
  reales (Wilmer Moreno, Carlos Rojas, Kleiver Rojas), no todo corre bajo "Sistema"
  como decia la version anterior de este documento

---

*Actualizacion del 2026-10-06 sobre la version consolidada del 2026-09-09. Los estados
marcados [sin verificar] no se pudieron confirmar con git ni con archivos del repo y
quedan pendientes de confirmacion por Carlos.*
