# SYSTEM_MAP.md — Kredix
# Arbitro unico de verdad sobre el estado real del sistema
# Ultima actualizacion: 2026-10-06 (hotfix 5fd2f3e)

---

## Estado general

- **Stack real:** Laravel 12.x + Inertia + Vue 3 + Tailwind v3 (bajado de v4 por
  incompatibilidad con Breeze) + Spatie MediaLibrary + Spatie ActivityLog + Breeze
  (self-signup deshabilitado)
- **Repo:** github.com/syntidev/kredix, rama `main` en `7316a08`
- **Produccion (VPS):** codigo en `5fd2f3e`
- **REGLA — no confundir los dos hashes:** Produccion = ultimo hash de CODIGO
  desplegado (hoy `5fd2f3e`). `main` puede llevar commits solo de documentacion por
  delante. Si `main` y el VPS difieren, comparar primero *que* commits son: si todos
  son de `.doc/`, no hay nada que desplegar.
- **Local:** `C:\laragon\www\kredix`, MySQL real
- **VPS:** `/var/www/kredix`, MySQL dedicado, Nginx con SSL (Cloudflare Origin
  Certificate, modo Full — NO strict, otros sitios del VPS no lo soportarian)
- **Cliente final:** OnBike Margarita (razon social OTOMIX MARGARITA, C.A.)
- **Volumen desde la ultima actualizacion de este archivo:** 221 commits entre
  2026-09-09 y 2026-10-06. Este documento estuvo casi un mes desactualizado — lo que
  sigue es el resumen por modulo, no el detalle commit por commit.
- **Worktrees (verificado el 06/10 con `git worktree list` + `merge-base`):**
  - `kredix` → `main` (`7316a08`), al dia
  - `kredix-ia` → `feat/ia-cliente` (`5fd2f3e`). **Tiene todo el codigo de `main`**; lo
    unico que le falta es `7316a08`, que es solo documentacion — o sea, sincronizada
    por la regla de arriba
  - `kredix-taller` → `feat/taller-entrega-a` (`d6a0309`), Entrega A: "que le llega al
    cliente". **OJO: su base es `8d9e2ac` y NO contiene el hotfix `5fd2f3e`.** El merge
    sale limpio (`git merge-tree` sin conflictos), pero la rama nunca se probo con el
    codigo del hotfix. Conviene mergear `main` en ella antes de certificarla
  - `kredix-estetica` (`7e74be0`) / `kredix-funcional` (`9db7dbf`) → ramas ya mergeadas,
    pendientes de borrar (ver Housekeeping)
- **Migraciones:** 61 aplicadas, 0 pendientes en local (`migrate:status`, 06/10)

## Regla del Policia — estado real

```
Clientes            ✅ certificado y en produccion
Creditos/Ventas      ❌ NO certificado (es la "Cuenta corriente", que reemplazo al
                        modelo original de venta-por-venta). Se daba por cerrado,
                        pero el hotfix 5fd2f3e del 06/10 demostro que no cumplia
                        los 6 criterios del CLAUDE.md -- ver detalle abajo
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

## Hotfix 5fd2f3e — desplegado en produccion el 2026-10-06

**Financiar el total del carrito, no el primer producto.** En "Nueva compra", al agregar
un segundo producto desaparecia la opcion "Es una venta financiada?" y el backend
rechazaba la compra con 422 ("Una venta financiada solo admite un producto"). Un carrito
de varias lineas no se podia financiar.

- **Migracion aplicada:** `movimientos_cuenta.compra_id` (uuid nullable con indice, sin
  backfill). Une las lineas de un mismo envio del formulario. Las compras anteriores
  quedan en NULL y se comportan exactamente igual que antes.
- El plan sigue colgando del primer cargo, pero las cuotas, la mora y el total que ve el
  cliente se calculan sobre `SUM(monto)` del grupo. Fuente unica:
  `PlanFinanciamiento::montoTotalCompra()`.
- `PlanFinanciamiento::cargo()` blindado con `withTrashed()`: arregla un bug previo por el
  que anular el cargo que sostiene el plan dejaba la relacion en null, la mora daba 500 y
  el plan desaparecia de la ficha con sus cuotas vivas.
- **Decision de Carlos:** anular una linea NO cambia el contrato pactado (el total se lee
  con `withTrashed`, las cuotas siguen sumando el total original); lo que baja es el saldo
  del cliente. Renegociar sigue siendo un acto explicito.

**Verificado en produccion el 06/10 (lectura directa del VPS):** HEAD en `5fd2f3e`,
`compra_id` existe, **0 archivos a nombre de root** en `storage` y `bootstrap/cache`, y hay
5 cargos en 2 grupos reales. El grupo `b10bebc9` (plan 7) confirma el arreglo con dinero
real: 2 lineas de $1.250 + $730 = **$1.980 de total del grupo**, inicial $693 y **suma de
cuotas $1.287**, que es exactamente 1.980 − 693. Antes del hotfix habria financiado solo
$1.250. Flujo E2E probado por Carlos con una compra real.

### Por que Creditos/Ventas queda NO certificado

De los 6 criterios del CLAUDE.md, el hotfix demostro que al menos dos no se cumplian
cuando el modulo se dio por cerrado:

- **Criterio 2 (flujo E2E completo probado manualmente):** la ruta "compra de varios
  productos + financiamiento" nunca se probo. Estaba bloqueada por codigo y nadie lo noto.
- **Criterio 5 (auditoria de calculos monetarios sin inconsistencias):** la mora se
  calculaba sobre `$plan->cargo->monto`, el primer producto, no sobre el total de la
  compra. Con un carrito eso subfacturaba la mora en silencio.
- **Efecto medido en produccion:** 19 carritos reales (ademas de los 165 grupos de la
  importacion del lote 1) quedaron sin credito financiado, por $10.590,00. Y la compra de
  la Alcaldia (ids 31/32) tiene **seis productos escritos dentro de una sola descripcion**
  por $16.100 — alguien necesitaba financiar y asi esquivo el bloqueo.

Pendiente para poder certificarlo: la verificacion visual a 390 px del formulario con
2 productos y el checkbox marcado (criterios 4 y 6), que no se hizo antes del merge.

## Cambios hechos fuera de git (no aparecen en el log)

- **02/10 — 55 cuentas del "Grupo B" restauradas a saldo $0.00.** Operacion directa en
  la base de datos de produccion: 51 cuentas via asiento `ajuste_devolucion` y 4 via
  asiento `cargo` (las 4 tenian saldo negativo, y `ajuste_devolucion` solo puede
  restar, por lo que no podia llevarlas a cero). **Efecto colateral aceptado por
  decision explicita de Carlos:** esos 4 cargos suman al KPI "Otorgado" (lo que Carlos
  llama "mercancia entregada") y lo inflan en **$2,415.00** [cifra aportada por Carlos,
  no re-derivada en esta actualizacion]. Todas las cuentas quedaron con la misma nota
  de auditoria y con `registrado_por` trazable.
- **06/10 — La tarea programada de Kredix paso del cron de root al de www-data.**
  Antes dejaba vistas compiladas y caches a nombre de root que la web no podia
  reescribir (43 archivos, entre ellos `app.blade.php` y el PDF de taller).
  Respaldo del cron anterior en `/root/crontab-backup-*`. Permisos normalizados con
  `chown`; 0 archivos de root en `storage` y `bootstrap/cache`.
  Verificado en el servidor el 06/10 a las 12:44 (hora del VPS): el cron de root ya
  no tiene la linea de Kredix, el de www-data si la tiene, existe
  `/root/crontab-backup-20261006-1241.txt`, `find storage bootstrap/cache -user root`
  devuelve 0, y `sudo -u www-data php artisan schedule:list` arranca y lista las 9
  tareas de BCV.
  [sin verificar] Que una tarea se ejecute de punta a punta bajo www-data: el
  respaldo es de las 12:41 y la siguiente tarea vence a las 14:00, asi que al momento
  de escribir esto ninguna habia corrido todavia con el cron nuevo. Confirmar
  despues de las 14:00 que la tasa BCV de esa corrida quedo registrada.

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

## Backlog — ideas y hallazgos fuera de alcance (detalle en `.doc/BACKLOG.md`)

No son pendientes activos: nada de esto se implementa sin decision de Carlos.

**Registrado por CLI Taller (06/10)**
1. El autoguardado de la revision pierde el ultimo toque si el tecnico sale antes de los
   600 ms del debounce.
2. El aviso "La IA esta analizando el motivo de ingreso…" no se actualiza solo en la
   pantalla de Revision (Show si recarga cada 15 s).
3. Calidad de las sugerencias: Nemotron sugirio componentes no mencionados en el motivo;
   revisar el prompt v1 de `SugerirRevision`.
4. El redactor de IA invento recomendaciones en 2 de 3 corridas con el prompt v2; el
   control `requiere_revision` lo detecto las dos veces. Volver a medir con v3.
5. `ia:sugerir` / `ia:redactar` no cancelan el job que el Observer ya encolo; si el worker
   corre despues, `SugerirRevision` reescribe `sugerencias_ia` y borra las descartadas.
6. Peor caso del PDF de taller: 23 componentes con notas largas llevan el documento a
   5 paginas (main: 3).
7. Trato de usted: el tuteo esta fijo en el texto automatico y en el prompt; la guia de voz
   pide que sea configurable.
8. Hallazgo de seguridad con otro motivo: si una pieza tiene fisura o fuga y ademas
   desgaste, el texto solo menciona el de seguridad; el secundario queda solo en el PDF.

**Registrado por CLI IA (cierre R1, 06/10)**
9. **Modelo de produccion sin definir:** `NVIDIA_MODELO_TEXTO` vacio en `.env`. DeepSeek
   v4.1 flash dio timeout a 280 s; Nemotron 3.5 lightning responde en 4–30 s. Decide Carlos.
10. Nemotron devuelve JSON invalido ~1 de 5 llamadas y hoy termina en `error` sin reintento.
11. El detector no ve omisiones ni inventos sobre componentes que si estan en la revision;
    la aprobacion humana es la red de seguridad.
12. Gramatica menor: falta la coma antes de "y estan en buen estado" en enumeraciones largas.
13. `SugerirRevision` sigue en prompt v1, probado con exito en un solo caso.
14. Datos de prueba en la BD local compartida: tickets #59–#71 y el usuario
    `e2e.tecnico@kredix.local`.

**Registrado por CLI Principal (hotfix 5fd2f3e, 06/10)**
15. **Agrupar el estado de cuenta por compra.** Con `compra_id` ya existe el dato, pero
    quedo fuera de alcance: hoy una compra de 6 productos sale como 6 renglones sueltos en
    el PDF del cliente. La pantalla tendria que tolerar las compras con `compra_id` NULL.
16. **Guarda al borrar lineas de una compra financiada.** Anular una linea no avisa nada, y
    por decision de Carlos el total pactado no se ajusta. Falta advertirlo en el modal de
    eliminacion y ofrecer el camino de renegociacion.

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

---

## Estado de la rama `feat/taller-cierre-comisiones-entregas` (2026-11-02)

- **Rama:** `feat/taller-cierre-comisiones-entregas`, sobre `main` en `e9b0cc2`, pusheada. **NO mergeada, NO desplegada.**
  Produccion sigue en `e9b0cc2`. Pendiente: **deploy unico** (un solo `git pull` + `migrate --force`).
- **Lo hecho en la rama (sin certificar):**
  - Taller: la foto de salida ya no es obligatoria al cerrar; tecnico por defecto configurable; el boton de
    crear muestra el tecnico elegido; "Siguiente" de Taller/Show ahora sigue el orden del listado
    (`FechaCompromiso::ordenarListado`, sin filtros).
  - KPI Taller: comisiones por tecnico (`taller_comision_servicio_pct` 50 y `taller_comision_repuestos_pct` 5, editables
    por admin en Configuracion > Taller), clasificacion normal/CORTESIA/TIENDA, columna `tickets_taller.comisionable`,
    selector `RangoFechas` con "Semana pasada" y "Mes anterior", tabla "Detalle del rango" para cotejar a mano.
    Caso de cotejo real (VPS, semana 26/10-01/11, Carlos Rojas): servicio base 120.00 -> 60.00; repuestos 78.00 -> 3.90; total 63.90
    (cubierto por `LoteCierreTest`).
  - Entregas pendientes: `movimientos_cuenta.pendiente_entrega/entregado_en/entregado_por`, bloque "Por entregar" en Inicio.
  - Fotos: `FotoInput.vue` (camara + galeria en tactil, un boton en escritorio) y compresion en el cliente. Excepcion
    intencional: `Configuracion/Index.vue` (logo y banners).
  - Presupuesto WhatsApp: `{monto_servicio}` ya incluye el armado (servicio + repuestos = total); cubierto por `PresupuestoWhatsappTest`.
- **Migraciones nuevas de la rama:** `2026_11_01_100000_add_comisionable_to_tickets_taller` y
  `2026_11_01_110000_add_entrega_to_movimientos_cuenta`. Orden de deploy: `git pull` y `php artisan migrate --force` seguidos,
  antes de los comandos de cache.
- **NO verificado aqui:** produccion, Safari/iOS, PWA instalada, camara fisica iOS/Android, HEIC en dispositivo real.
- **Certificacion:** ningun modulo queda certificado por este trabajo. El criterio 6 de la Regla del Policia exige la
  aprobacion visual de Carlos en vivo (local o VPS real).
- **Aviso de higiene:** `main` local quedo 6 commits adelante de `origin/main` con los mismos commits de la rama
  (sin push). Origen sin determinar; no se hizo merge a proposito.

### Venta de contado y seriales de producto (rama `feat/taller-cierre-comisiones-entregas`, 2026-11-02)

- **Causa del 422 en producción:** `MovimientoCuentaController::store` hacía `abort_if(monto_inicial >= montoTotal, 422, ...)`
  para toda venta financiada. Con inicial == total (los artículos Garmin de contado que se cargaban como "financiada" solo
  para guardar el serial) devolvía la página "Oops! An Error Occurred". Reproducido con una prueba antes del arreglo.
- **Venta de contado:** inicial == total (o el interruptor "De contado (pago completo)") no crea plan ni cuotas: crea el cargo
  (`es_contado = true`) y UN abono por el total por el mismo camino que la inicial de un plan (`registrarPagoInicial`), con
  tasa por transacción, `registrado_por` y ActivityLog `venta_contado`. Inicial > total → 422 "La inicial (…) no puede ser mayor
  al total de la venta (…)" en el campo `monto_inicial`. Inicial < total: sin cambios (plan + cuotas).
  Las filas de contado (cargo y abono) quedan FUERA de: KPI (otorgado/cobrado/antigüedad/salud/actividad), "último abono" de
  mora y Cartelera, y otorgado/cobrado/último abono de la cartera por cliente. SÍ cuentan en el panel Ventas (etiqueta "Contado")
  y en el cierre del día de Inicio (es dinero real cobrado hoy).
- **Seriales:** tabla `seriales_producto` (una fila por unidad; normalizado trim + MAYÚSCULAS; soft-delete). La unicidad de los
  vigentes se valida en la aplicación dentro de la transacción y la respalda un índice único sobre la columna generada
  `serial_vigente` (`CASE WHEN deleted_at IS NULL THEN serial END`; MySQL 8 y sqlite). Al eliminar la línea de cargo sus
  seriales quedan libres. Corregir/crear/liberar un serial queda en ActivityLog (`serial_creado`, `serial_editado`, `serial_liberado`).
  **La devolución (`ajuste_devolucion`) NO guarda a qué línea corresponde, así que no libera seriales** (no se adivina).
- **Catálogo:** `productos.lleva_serial` se actualiza con la casilla de cada venta y sale marcado la próxima vez
  (`/productos?q=…&detalle=1`). El buscador `/clientes?q=` y `/clientes/buscar` también encuentran por serial.
- **Migraciones nuevas de este bloque (todas aditivas):** `2026_11_02_100000_add_es_contado_to_movimientos_cuenta`,
  `2026_11_02_110000_create_seriales_producto_table`, `2026_11_02_120000_add_lleva_serial_to_productos`.
- **Migración `2026_11_01_100000_add_comisionable_to_tickets_taller`:** regla = todo ticket SIN cliente (`cliente_id` NULL =
  trabajo de la tienda) queda `comisionable = false`; el resto `true`. Ahora imprime al migrar cuántos quedaron como TIENDA y
  cuántos como COMISIONABLES (en local: 7 y 15). `down()` borra la columna (probado en local).
- **Línea base de pruebas:** `e9b0cc2` = 27 fallas / 229 pasan; la rama = 27 fallas / 285+ pasan; mismos 27 nombres, ninguno nuevo.
  Las 27 (Auth, Profile, TasaBcv) son de entorno: la migración `replace_procesado_con_estado_en_prospectos_200k` hace
  `drop column` sobre una columna indexada y sqlite en memoria no lo soporta (afecta a todo test con `RefreshDatabase`).
- **Pendiente:** deploy único (`git pull` + `migrate --force` seguidos). Ningún módulo queda certificado: falta la aprobación
  visual de Carlos (criterio 6). No verificado aquí: producción, Safari/iOS, PWA, dispositivos físicos.

### Lote de correcciones 1 (rama `feat/lote-correcciones-1`, 2026-11-03)

- **Punto de partida:** el deploy ya corrió; `main`, `origin/main` y `feat/taller-cierre-comisiones-entregas` coinciden en `e2dabb9`
  (ningún commit de la rama quedó fuera de `origin/main`). Esta rama parte de ahí. Sin merge ni deploy.
- **Pruebas:** la migración `replace_procesado_con_estado_en_prospectos_200k` ahora quita el índice de `procesado` antes del `dropColumn`
  SOLO en sqlite (MySQL queda igual). Resultado: la suite completa corre sin fallas (324 pasan; antes 27 rotas por esa causa, incluida `TasaBcvServiceTest`).
- **Ventas por entregar:** `/ventas?por_entregar=1` (chip "Solo por entregar" en Ventas y enlace "Ver todos" en el bloque de Inicio, este último solo
  para admin porque `/ventas` es solo de admin). Ignora el rango de fechas. Pagado/saldo por compra: contado = todo pagado; con plan = inicial + abonos
  del plan; crédito sin plan = 0 pagado (los abonos generales no se asignan a una compra). Sin menú nuevo ni botón nuevo.
- **Eliminar una línea de contado:** en la misma transacción ajusta su pago (mismo `compra_id`): sin líneas vivas lo anula (soft-delete), con líneas
  restantes lo reduce por el monto de la línea y conserva su tasa; ActivityLog `contado_pago_ajustado`; la UI avisa antes de confirmar.
- **"Lleva serial":** desmarcarla en una venta ya no toca `productos.lleva_serial` (solo marcar persiste). **No existe pantalla de edición del catálogo
  de artículos** (solo la ruta de búsqueda `/productos`): no se creó; hoy no hay forma de quitar la marca desde la UI.
- **Agregar serial** a líneas existentes con menos seriales vigentes que su cantidad entera (`POST /movimientos/{id}/seriales`), con las mismas reglas.
- **Hallazgo (NO corregido):** el KPI cuenta como "otorgado" la mercancía apartada sin entregar, por su fecha de venta
  (`KpiController.php:85,131,194,309`: suman todos los cargos sin mirar `pendiente_entrega`). Prueba: una compra apartada de 250 sube el otorgado en 250.
- **Pendiente:** deploy de esta rama; ningún módulo certificado (falta la aprobación visual de Carlos). No verificado: producción, Safari/iOS, PWA, dispositivos físicos.

## Lote de correcciones 2 (2026-11-03) — rama `feat/lote-correcciones-2`

- **Tarjeta "Por entregar" (Ventas):** muestra Total (de la compra), Abono y Saldo en una fila. Abono y Saldo son los de la CUENTA del cliente:
  `MovimientoCuenta::totalCobrado()` / `saldoPendiente()`, las mismas funciones de "Total cobrado" / "Saldo pendiente" de la ficha
  (`ClienteController`). Si el cliente tiene otros cargos vigentes además de esa compra, la tarjeta dice "Abono y saldo de toda la cuenta del cliente"
  (`cuenta_con_otros_cargos`). Contado: Abono = Total, Saldo = 0, sin nota. Se eliminó la aproximación anterior (pagado por plan / "0 pagado" sin plan).
  Sin reparto de abonos entre compras (decisión de negocio fuera de alcance).
- **Clientes/Show:** la etiqueta "Pendiente de entrega" (y "Contado") ahora va en su propia línea dentro de la celda del monto (antes se desbordaba
  sobre la columna Método); `textoPlazo()` pluraliza "1 mes" / "2 meses".
- **6c (solo reporte):** una compra no guarda método de pago al crearse (`MovimientoCuentaController::store`, sin `metodo_pago`), pero al EDITARLA el
  formulario (`editForm.metodo_pago = m.metodo_pago ?? 'efectivo'`, `Show.vue`) envía 'efectivo' y `update()` lo persiste en el cargo
  (`MovimientoCuentaController.php`, `'metodo_pago' => $validated['metodo_pago'] ?? null`). El nombre es `registrado_por` del cargo (quien lo registró,
  `store()`; `update()` no lo cambia), mostrado por `ClienteController` (`'registrado_por' => $m->registradoPor?->name`).
- **No verificado:** producción, Safari/iOS, PWA, dispositivos físicos; ningún módulo certificado (falta aprobación visual de Carlos).

## Lote 4 Taller (2026-11-10) — rama `feat/taller-presets-bicis`

- **Servicios rápidos (presets):** lista editable en Configuración › Taller (solo admin), clave `taller_presets_servicio` (JSON `[{nombre, monto}]`,
  máx. 20, nombre 1–60, monto 0–9999.99, sin migración, sin datos sembrados). `AjustesTaller::presetsServicio()`. Chips (`PresetsServicio.vue`) en
  Taller/Nuevo (deshabilitados si Servicio es de monto fijo: básico/full/vip) y en la edición de Taller/Show: SUMAN su monto a Servicio y, en Show,
  agregan el nombre al borrador de "trabajo realizado". No crean líneas ni tipos de comisión: el KPI no cambió.
- **Sugerencias de bici:** `GET /taller/bicis` (`?q=` mín. 2 caracteres → hasta 8; `?cliente_id=` → hasta 5 del cliente), sin tabla nueva, desde
  `tickets_taller` vivos, `BicisTaller` (sin mayúsculas/acentos/espacios, una por texto, datos del ticket más reciente). `BiciAutocompletar.vue`.
- **C1 (no se implementó C2):** un ticket sin pagos ni abonos SÍ se puede marcar atendido: `TallerController::marcarAtendido` solo exige trabajo
  realizado o revisión, ≥1 foto de entrada y decir si se pagó en taller o a cuenta (`pagado_en_taller=false` genera el cargo). Cerrarlo basta.
- **No verificado:** producción, Safari/iOS, PWA, dispositivos físicos; ningún módulo certificado (falta aprobación visual de Carlos).

## Lote 5: WhatsApp de cobranza en la ficha (2026-11-10) — rama `feat/whatsapp-cobranza` (parte de `feat/taller-presets-bicis`)

- **Un solo motor:** `PlantillasWhatsapp` ahora tiene el espacio `cobranza` (`COBRANZA`, 6 tipos con su texto original en código) además del Taller; `texto/defecto/errorDeVariables`
  reciben `$espacio` (por defecto `taller`, el Taller no cambia) y `armar()` reemplaza variables: un dato null quita su línea y nunca queda un `{x}`.
  Claves `whatsapp_cobranza_<tipo>` en Configuracion (null = texto del código).
- **Servicio:** `CobranzaWhatsapp::paraCliente()` arma los 6 textos (USD, `$1,199.00`). Abono y saldo son los de la CUENTA (`totalCobrado()` / `saldoPendiente()`).
  Estado de cuenta: compras de crédito (sin contado) recortadas con "• y N más" hasta 1800 de URL (`PresupuestoWhatsapp::largoDelEnlace`). Recordatorio: cuota pendiente
  de menor vencimiento ("Lleva N días de atraso" si venció); sin cuotas usa la plantilla de saldo. Abono recibido: último abono vigente que no es de contado.
  Preselección: recordatorio si hay cuota vencida, si no estado de cuenta.
- **UI:** `WhatsappCobranza.vue` (hoja inferior en móvil, ventana flotante en escritorio), texto editable, "Anotar gestión" (desactivada), sin teléfono = botón
  deshabilitado. `POST /clientes/{cliente}/whatsapp-cobranza` registra `whatsapp_cobranza` (tipo + usuario, sin el texto) y, si se pide, la gestión "WhatsApp: <tipo>".
  Configuración › WhatsApp › «WhatsApp · Cobranza» (solo admin). Se quitó el formulario de las plantillas 1-3; el endpoint `PUT /configuracion/whatsapp` queda
  como legado porque tiene pruebas del Taller que no se modifican.
- **No verificado:** apertura real de WhatsApp, Safari/iOS, PWA, dispositivos físicos; ningún módulo certificado (falta aprobación visual de Carlos).

## Lote 6 Taller: cierre flexible (2026-11-10) — rama `feat/cierre-flexible`

- **A0 (por qué el #59 no cerraba):** `Taller/Show.vue` habilita el botón con `puedeMarcarAtendido` = texto + (pago si se cobra) [+ foto, antes]. El texto cuenta solo si ya está GUARDADO
  (`ticket.trabajo_realizado` o una revisión técnica con acciones; lo escrito en el cuadro sin pulsar Guardar no cuenta) y el pago solo cuando se elige Sí/No. La foto subida sí cuenta al instante
  (`POST /taller/{id}/fotos/entrada` redirige a la ficha y la prop `ticket.fotos_entrada` ya viene actualizada). No era un bug: el aviso gris genérico no decía qué faltaba.
- **Cierre:** lista de verificación (texto, foto opcional, pago) con nota "escrito, falta pulsar Guardar". La foto de entrada ya no bloquea: sin foto pide confirmar ("Cerrar sin foto de entrada",
  `confirmar_sin_foto`) y registra `cierre_sin_foto` en ActivityLog. "Fecha del trabajo" (`fecha_trabajo`, hoy por defecto, ni futura ni anterior a la creación): otro día → `atendido_en` = ese día 18:00:00
  y evento `atendido_en_ajustado`; el cargo conserva su fecha. Sin migraciones; KPI y comisiones sin tocar (siguen por estado atendido y `atendido_en`).
- **Por cobrar:** `TallerController::cobroDe` → atendido A CUENTA con saldo de la cuenta > 0 = ámbar "A CUENTA · el cliente debe $X"; saldo 0 = verde "Cobrado"; pagado en taller = verde "PAGADO".
  `MovimientoCuenta::saldosPendientes()` calcula el saldo de varios clientes en una consulta (misma fórmula que `saldoPendiente()`, que ahora delega en ella). Filtro `?por_cobrar=1` con un subselect.
- **Método "Efectivo":** `Clientes/Show.vue` ya no preselecciona 'efectivo' al editar (queda vacío) y `MovimientoCuentaController::update` conserva el método de un cargo si no llega uno. Otros
  valores por defecto 'efectivo' que se dejan como estaban: `cargoForm.metodo_pago_inicial` (Show.vue:369, 503), `abonoForm.metodo_pago` (525, 621) para NUEVOS abonos/iniciales.
- **No verificado:** Safari/iOS, PWA, cámara real, dispositivos físicos; ningún módulo certificado (falta aprobación visual de Carlos).

## Lote 8: Etiquetas de envío e impresión libre (2026-11-10) — rama `feat/etiquetas`

- **Mecanismo reutilizado (Taller):** el ticket del Taller se imprime desde la misma página con `window.print()` y CSS `@media print` (`Taller/Show.vue`: `@page { size: 58mm auto; margin: 0 }`,
  `body * { visibility: hidden }` + el bloque visible, ancho 58 mm, Xprinter XP-58IIHT). Las etiquetas hacen lo mismo con `EtiquetaImpresion.vue` + `lib/imprimirEtiqueta.js`; una clase
  `imprimiendo-etiqueta` en `<body>` (solo mientras se imprime) aísla sus reglas para no tocar ninguna otra pantalla.
- **Página** `/etiquetas` (`EtiquetasController`, `Etiquetas/Index.vue`; menú: Más › Cobranza y barra lateral › Cobranza; cualquier usuario autenticado salvo `rol_taller`, que solo ve `/taller`):
  modos Texto libre, Título + texto, Campos y Envío (con "Tomar datos de un cliente" vía `GET /etiquetas/cliente/{id}`), tamaño Normal/Grande/Muy grande (Grande por defecto).
  Plantillas de Campos: solo títulos, en `Configuracion` clave `etiquetas_plantillas` (≤20 plantillas, nombre ≤60, ≤12 campos, título ≤40); «Envío» vive en código y no se guarda.
- **Ficha del cliente:** botón "Imprimir datos de envío" (Nombre, Cédula, Teléfono, Dirección; omite lo vacío).
- **Privacidad:** los valores impresos solo viven en el estado de la página (ni BD, ni ActivityLog, ni localStorage). Se registra únicamente `etiqueta_impresa` con modo/plantilla y usuario.
- **No verificado:** impresión real en la Xprinter, ausencia de encabezado/pie del navegador, Safari/iOS, PWA, dispositivos físicos. Ajuste recomendado del navegador al imprimir: márgenes "Ninguno",
  desactivar "Encabezados y pies de página" y el papel de la Xprinter (58 mm) ya configurado en el sistema. Ningún módulo certificado (falta aprobación visual de Carlos).

## Lote 7 Taller: "Trabajo terminado" sin cerrar (2026-11-10) — rama `feat/trabajo-terminado`

- **Columna** `tickets_taller.trabajado_en` (timestamp nullable; migración `2026_11_10_120000_add_trabajado_en_to_tickets_taller`, aditiva). `TicketTaller::fechaConteo()` = `trabajado_en ?? atendido_en`.
- **Marcar/desmarcar:** `PATCH /taller/{ticket}/trabajo-terminado` (`TallerController::trabajoTerminado`, solo En proceso): `fecha_trabajo` (hoy máx., ≥ creación; hoy = hora actual, otro día = 18:00:00); sin fecha = desmarcar.
  ActivityLog `trabajo_terminado_marcado` / `trabajo_terminado_desmarcado` con `anterior`/`nuevo` y usuario. No cambia estado, montos ni cuenta.
- **KPI** (`ComisionesTaller::calcular`, sin tocar la fórmula): entran tickets atendidos O con `trabajado_en`, contados una vez en `COALESCE(trabajado_en, atendido_en)` (eliminados fuera); `trabajados_sin_cerrar`
  y `sin_cerrar` por fila del detalle (su suma sigue igual que los totales). Cerrar un ticket ya trabajado no mueve ni repite su fecha: `marcarAtendido` ignora `fecha_trabajo` y el campo del lote 6 queda de solo lectura
  ("Ya contado para el técnico el dd/mm").
- **UI:** bloque azul "Trabajo terminado" y etiqueta "Trabajo terminado · sin cerrar · dd/mm" en Taller/Show y en la lista (ni verde ni ámbar de cobro); contador y marca "Sin cerrar" en el KPI.
- **No verificado:** Safari/iOS, PWA, dispositivos físicos; ningún módulo certificado (falta aprobación visual de Carlos).

## Lote 9: Etiquetas con clientes registrados (2026-11-10) — rama `feat/etiquetas-clientes`

- **Buscador del modo Envío** (`GET /etiquetas/clientes`, `EtiquetasController::clientes`): lo primero de la pantalla (ancho completo, enfocado). Busca por nombre (todas las palabras), cédula o teléfono (solo dígitos, "0414…" o "+58414…"),
  sin mayúsculas ni acentos (se filtra en PHP, no depende de la collation), mínimo 2 caracteres, máximo 8. Al elegir: "Cliente: NOMBRE" + "Quitar cliente"; los cuatro campos salen de `GET /etiquetas/cliente/{id}` y se pueden editar.
- **Casilla "Imprimir"** por fila (Envío y Campos): marcada sola cuando hay valor (y al escribir, mientras no se toque la casilla); se imprime solo lo marcado Y con valor.
- **Bidireccional:** con cliente ligado, la fila que difiere de la ficha muestra "Distinto a la ficha" y un solo botón "Guardar en la ficha del cliente" → `PUT /etiquetas/cliente/{id}`. Solo Nombre/Cédula/Teléfono/Dirección, solo los que difieren y traen valor
  (vaciar nunca borra). Rellenar un campo vacío es directo; reemplazar uno con valor pide confirmación en pantalla y `confirmar_reemplazo` en el servidor (422 si falta). Validación y mensajes: los de la edición normal
  (`ClienteController::reglasEdicion/mensajesEdicion`, ahora compartidos). ActivityLog `cliente_actualizado_desde_etiqueta` (usuario + campos con anterior/nuevo); la edición normal del cliente NO registra actividad, por eso este evento es nuevo.
  Permiso: el mismo de editar un cliente (usuario autenticado sin rol exclusivo de Taller). La dirección ya se edita en la ficha (`Clientes/Show.vue`, formulario de edición).
- Sin cliente ligado todo funciona como en el lote 8 (nada se guarda, nadie se da de alta, sin botón de guardar en ficha). Lo impreso sigue sin persistirse.
- **No verificado:** impresión real en la Xprinter, Safari/iOS, PWA, dispositivos físicos; ningún módulo certificado (falta aprobación visual de Carlos).
