# Inventario del módulo Taller — Kredix
**Tipo:** LEVANTAMIENTO (solo lectura) · **Fecha:** 2026-10-06 · **Agente:** CLI-A
**Alcance:** este documento. No se modificó código, base de datos ni se hizo commit.

---

## 1. Modelo de datos

### Tabla `tickets_taller`

Construida de forma incremental (1 migración base + 8 migraciones aditivas). Columnas finales:

| Columna | Tipo | Nullable | Notas |
|---|---|---|---|
| `id` | bigint PK | no | |
| `tipo` | enum-string (`servicio_cliente`, `armado_interno`) | no | distingue bici de cliente vs. armado nuevo en taller |
| `cliente_id` | FK → `clientes.id`, `nullOnDelete` | sí | null en `armado_interno` |
| `motivo_ingreso` | text | sí | nullable a nivel BD; obligatorio a nivel de controlador solo si `tipo=servicio_cliente` (migración `2026_09_18_140000`) |
| `bici_marca_modelo` | string | no | |
| `talla_rin` | string | no (default `''`) | texto libre corto (antes combinaba talla+categoría; separado en `2026_09_19_090000`) |
| `categoria_bici` | string (`ruta`\|`mtb`\|`otro`) | sí a nivel BD, requerido en controlador | agregada en `2026_09_19_090000`, con migración de datos para separar valores viejos |
| `es_electrica` | boolean, default `false` | no | agregada en `2026_09_18_130000` |
| `tipo_servicio` | string (`basico`\|`full`\|`vip`\|`otro`) | sí | solo aplica a `servicio_cliente` |
| `domicilio_direccion` | text | sí | solo obligatorio si `tipo_servicio=vip` (servicio a domicilio) |
| `monto_servicio` | decimal | sí | precio fijo por tipo de servicio, ver sección 7 |
| `diagnostico` | **JSON** | sí (default `[]`) | ver estructura abajo — elegido JSON explícitamente para que agregar un ítem de checklist nuevo no requiera migración (comentario en la migración base) |
| `estado` | string (`en_proceso`\|`atendido`), default `en_proceso` | no | |
| `mecanico_id` | FK → `users.id` | no | el "mecánico" es un usuario real del sistema (ver sección 4) |
| `registrado_por` | FK → `users.id` | no | quien efectivamente operó el formulario |
| `trabajo_realizado` | text | sí | agregada en `2026_09_18_150000`, mismo patrón (nullable en BD, obligatorio en `marcarAtendido()`) |
| `pagado_en_taller` | boolean, default `false` | no | agregada en `2026_09_29_110000` |
| `movimiento_cuenta_id` | FK → `movimientos_cuenta.id`, `nullOnDelete` | sí | enlaza el cargo automático generado al cerrar (ver sección 3) |
| `motivo_eliminacion` | text | sí | agregada en `2026_09_24_150000`, junto con soft-delete |
| `eliminado_por` | FK → `users.id`, `nullOnDelete` | sí | |
| `created_at` / `updated_at` / `deleted_at` | timestamps | — | soft-deletes reales (`SoftDeletes` en el modelo) |

### Estructura exacta del checklist de diagnóstico (`diagnostico`, columna JSON)

Array de objetos, uno por ítem:
```json
[
  { "item": "Cadena",     "estado": "bien",     "nota": "" },
  { "item": "Frenos",     "estado": "atencion", "nota": "Pastillas gastadas" }
]
```
- **Ítems fijos** (constante `CHECKLIST_ITEMS` hardcodeada en `Taller/Nuevo.vue:18`, no en BD ni catálogo): `Cadena`, `Frenos`, `Rayos`, `Cauchos`, `Rolineras`, `Cambios`.
- **Estados permitidos**: `bien` | `atencion` (validado en controlador: `diagnostico.*.estado => in:bien,atencion`).
- **`nota`**: texto libre opcional, se vacía automáticamente al volver un ítem a `bien` (`Taller/Nuevo.vue:150-153`).
- **Dónde se guarda**: columna `tickets_taller.diagnostico`, cast `array` en el modelo (`TicketTaller.php`).
- **Filtrado antes de guardar**: `Taller/Nuevo.vue:176-180` descarta al enviar los ítems que quedaron en `bien` sin nota — por eso en BD normalmente solo aparecen los ítems "con algo que decir", no los 6 completos. Esto es importante para interpretar la sección 6 (uso real): un diagnóstico vacío `[]` no necesariamente significa que nadie revisó la bici, puede significar "todo bien, nada que anotar" **o bien "nadie llenó el checklist"** — el dato por sí solo no distingue ambos casos.

### Tabla `ticket_repuestos`

| Columna | Tipo | Nullable |
|---|---|---|
| `id` | bigint PK | no |
| `ticket_id` | FK → `tickets_taller.id`, `cascadeOnDelete` | no |
| `producto` | string | no |
| `cantidad` | unsignedInteger | no |
| `precio` | decimal(10,2) | no |
| `created_at`/`updated_at` | timestamps | — |

**Sin soft-deletes** (a diferencia de `tickets_taller`) — un repuesto borrado se pierde físicamente (`eliminarRepuesto()` hace `$repuesto->delete()` real, no hay `deleted_at`). `producto` es texto libre sin FK a ningún catálogo (ver sección 7).

### Tabla `users` (columna relevante)

`rol_taller` (boolean, default `false`) — agregada en `2026_09_18_120000`, inmediatamente después de `acceso_conciliacion` en el orden de columnas. Ver sección 4.

---

## 2. Formularios y modales

No existen modales dedicados como componentes `.vue` separados para Taller — todo vive inline (`<div v-if>`) dentro de `Taller/Show.vue`, salvo el visor de fotos que reutiliza el componente compartido `ComprobanteLightbox.vue` (el estándar único de imágenes de todo el sistema).

### `Taller/Nuevo.vue` (creación — la llena recepción/administración, nunca el técnico, ver sección 4)

| Campo | Widget | Obligatorio | Condición |
|---|---|---|---|
| Tipo de ticket | radio (servicio_cliente / armado_interno) | sí | siempre |
| Motivo de ingreso | textarea | sí | solo `servicio_cliente` |
| Cliente | buscador con debounce 300ms + alta rápida inline (nombre+teléfono vía `/clientes/rapido`) | sí | solo `servicio_cliente` |
| Bicicleta (marca/modelo) | input texto | sí | siempre |
| Categoría | select (Ruta/MTB/Otro) | sí | siempre |
| Talla de rin | select corto (16/20/24/26/29) + "Otro" texto libre | no | siempre |
| ¿Es eléctrica? | checkbox | no | siempre |
| Tipo de servicio | select (Básico $15 / Full $20 / VIP $25 / Otro) | sí | solo `servicio_cliente` |
| Monto del servicio | input numérico, autocompletado y bloqueado salvo `tipo_servicio=otro` | sí | solo `servicio_cliente` |
| Dirección del domicilio | textarea | sí | solo si VIP |
| Mecánico | select (lista de usuarios no ocultos) | sí | siempre |
| Diagnóstico | 6 toggles Bien/Atención + nota condicional por ítem | no | siempre |
| Fotos de entrada | `<input type="file" multiple>`, con conversión HEIC→JPEG, rotación forzada a vertical y compresión client-side encadenadas | sí, salvo VIP (se agenda antes de ver la bici) | siempre (excepto VIP) |

### `Taller/Show.vue` (ficha del ticket — mezcla recepción y técnico, ver detalle abajo)

| Sección/campo | Widget | Obligatorio | Quién lo llena hoy |
|---|---|---|---|
| Edición de datos básicos (cliente, motivo, bici, categoría, talla, eléctrica, tipo/monto servicio, mecánico, diagnóstico) | mismo formulario de Nuevo.vue, en modo edición (`editando.value`) | según campo, igual que arriba | en la práctica, recepción (ver sección 6: `registrado_por` nunca coincide con `mecanico_id`) |
| Repuestos | input texto con autocompletado (`/productos?q=`) + cantidad + precio | sí los 3 | indistinto, normalmente quien cierra el ticket |
| **Trabajo realizado** | textarea libre | sí, para poder cerrar (`marcarAtendido` lo exige) | en la práctica, recepción |
| Fotos de entrada / salida | `<input type="file" multiple>` por colección, mismo pipeline HEIC→vertical→compresión que Nuevo.vue | salida obligatoria para cerrar | indistinto |
| ¿Se pagó en el momento? | radio Sí/No | sí, solo si `servicio_cliente` | quien cierra el ticket |
| Marcar como atendido | botón, deshabilitado hasta cumplir 4 condiciones (ver sección 3) | — | — |
| Eliminar ticket | modal inline de confirmación + motivo obligatorio | motivo sí | soft-delete |
| Imprimir ticket físico (58mm) | `window.print()` con CSS `@media print` aislado | — | — |
| Ver/descargar PDF de atención | botones, con lógica especial Web Share API para iOS/PWA standalone | — | — |

**No hay ningún campo en todo el módulo marcado explícitamente como "de llenado exclusivo del técnico"** — el formulario es el mismo sin importar quién esté logueado, salvo la restricción de navegación de `RestringeRolTaller` (sección 4), que no cambia qué campos ve, solo qué rutas puede visitar.

---

## 3. Ciclo de vida del ticket

```
en_proceso ──────────────────────────────────► atendido
   │                                                 ▲
   │ (create)                                        │ (marcarAtendido)
   └── store()                                       │
            │                                        │
            ├── update() [editar datos, N veces]     │
            ├── subirFotos() [entrada/salida, N veces]
            ├── agregarRepuesto() / eliminarRepuesto() [N veces]
            ├── guardarTrabajoRealizado() [N veces, hasta cerrar]
            │                                        │
            └────────────────────────────────────────┘
   │
   └── destroy() [soft-delete, en cualquier estado, con motivo obligatorio]
```

| Método (controlador) | Qué dispara | Quién puede ejecutarlo |
|---|---|---|
| `store()` | crea el ticket en `en_proceso` | cualquier usuario autenticado con acceso a `/taller` (no hay gate de rol específico más allá del middleware global de auth) |
| `update()` | edita datos base, **nunca** toca fotos/repuestos/diagnóstico-de-cierre | idem |
| `subirFotos($coleccion)` | agrega a `entrada` o `salida` vía MediaLibrary, sin límite de cantidad | idem |
| `agregarRepuesto()` / `eliminarRepuesto()` | CRUD de `ticket_repuestos` (sin soft-delete) | idem |
| `guardarTrabajoRealizado()` | único método que escribe `trabajo_realizado` | idem |
| `marcarAtendido()` | **el único** que pasa `estado → atendido`, fija `pagado_en_taller`, y (si `servicio_cliente` + no pagado en el momento) crea el cargo automático en `movimientos_cuenta` | idem — bloqueado por 3 validaciones duras: `trabajo_realizado` no vacío, ≥1 foto entrada, ≥1 foto salida, + si es `servicio_cliente` debe elegirse explícitamente si se pagó |
| `destroy()` | soft-delete + registra `motivo_eliminacion` y `eliminado_por` | idem, en cualquier estado |

**Guard permanente confirmado en código** (`TallerController.php:578-588`): si `servicio_cliente` queda sin pagar en el momento y por cualquier razón no se pudo crear el `MovimientoCuenta`, toda la transacción revierte — el ticket **no puede** quedar "atendido" sin un cargo real asociado, desde que este guard se desplegó (`acc2463`, 2026-09-29 16:32). Antes de esa fecha, el cargo automático no existía como funcionalidad.

No existe ningún estado "cancelado" ni "pausado" — solo `en_proceso` / `atendido`, más el soft-delete como mecanismo separado (un ticket eliminado puede estar en cualquiera de los dos estados).

---

## 4. Técnicos

**Los "mecánicos" SON usuarios reales del sistema con login propio** — `tickets_taller.mecanico_id` es una FK directa a `users.id` (`TicketTaller::mecanico(): BelongsTo` → `User::class`), no existe una tabla separada de mecánicos/empleados.

**¿Pueden entrar al sistema hoy?** Sí, y de hecho ya hay 2 usuarios reales con ese rol en producción: `Carlos Rojas` (id=8) y `Kleiver Rojas` (id=10), ambos activos y no ocultos.

Existe una columna booleana `users.rol_taller` (agregada 2026-09-18) con un middleware dedicado, `RestringeRolTaller`, que:
- Si `rol_taller = true`, confina al usuario **solo** a `/taller/*` + un puñado de rutas whitelisteadas (`/logout`, `/login`, `/productos`, `/clientes/buscar`, `/clientes/rapido`) — cualquier otra ruta devuelve 403.
- `/`, `/dashboard`, `/home` lo redirigen automáticamente a `/taller` (para no dejarlo varado tras login).
- Gestionable vía `UsuarioController::toggleRolTaller` (ruta `/usuarios/{usuario}/toggle-rol-taller`).

**Conclusión clave para el rediseño**: el sistema ya tiene la infraestructura de login + gate de navegación pensada para que el técnico entre directamente — lo que **no** existe es ninguna diferenciación de **qué ve o llena** dentro de `/taller` según si es `rol_taller` o no. El formulario es idéntico para recepción y para el técnico. Y según la sección 6, en la práctica el técnico (mecánico asignado) nunca ha sido quien registra el ticket — siempre es otra persona.

---

## 5. PDF de atención (`taller-atencion.blade.php` + `TallerController::pdfAtencion()`)

**Campos que SÍ alimentan el PDF:**
- Encabezado empresa (logo, razón social, RIF, dirección, teléfono, email) + banner superior/inferior configurables
- Cliente (o "Armado interno"), estado, bici+categoría+talla+eléctrica, tipo de servicio, mecánico, domicilio (si VIP), motivo de ingreso, **trabajo realizado**, fecha de ingreso
- Desglose: servicio base (si `monto_servicio > 0`) + cada repuesto (producto/cantidad/precio/subtotal) + total del ticket
- Fotos de entrada y de salida, en grilla de hasta 3 columnas, con salto de página automático si hay ambas secciones

**Campos que se capturan pero NUNCA se imprimen:**
1. **Diagnóstico / checklist** (confirmado, ya conocido antes de esta auditoría) — ningún ítem del array `diagnostico` aparece en ninguna parte de la vista Blade.
2. **`pagado_en_taller`** — si se pagó en el momento o quedó a crédito no aparece en el PDF que se le entrega al cliente.
3. **`motivo_eliminacion` / `eliminado_por`** — no aplica (un ticket eliminado no debería generar PDF en el flujo normal, pero la ruta no valida `estado`/`deleted_at` antes de generar el PDF).
4. **`es_electrica`** — se imprime solo como sufijo "· E-BIKE" junto a categoría/talla, fácil de pasar por alto, no es un campo destacado.

No se encontraron más campos capturados y nunca impresos aparte de los ya confirmados.

---

## 6. Uso real en producción (consultas de solo lectura, 2026-10-06)

> Dataset: 19 tickets totales históricos, **12 activos** (7 ya soft-deleted). Muestra pequeña — las conclusiones son direccionales, no estadísticamente robustas, pero consistentes con la hipótesis.

**Total y distribución por `tipo_servicio`** (solo tickets activos):
- `servicio_cliente` / `full`: 9
- `servicio_cliente` / `otro`: 3
- **Cero tickets `armado_interno`, cero `basico`, cero `vip`** en los 12 activos actuales — el catálogo de 3 tipos fijos + "otro" casi no se usa en su forma "estándar" (todo cae en `full` u `otro`).

**Campos de texto libre — vacío / corto:**
| Campo | % vacío | % no-vacío pero <20 caracteres |
|---|---|---|
| `motivo_ingreso` | 0% (0/12) | 16.7% (2/12) |
| `trabajo_realizado` | 0% (0/12) | 41.7% (5/12) |

Ningún ticket activo tiene estos campos vacíos (ambos son obligatorios para operar/cerrar, consistente con el código), pero **trabajo_realizado es corto/poco informativo en 4 de cada 10 tickets** — sugiere llenado de trámite ("listo", "ajustado") más que una bitácora real de taller.

**Checklist de diagnóstico:**
- **83.3% (10/12) de los tickets activos tienen `diagnostico = []` (completamente vacío)**.
- Solo 16.7% (2/12) tiene al menos un ítem marcado `atencion` o con nota.
- Dado el filtrado client-side (sección 1: solo se envían ítems "con algo que decir"), esto es coherente con la hipótesis "el checklist casi nunca se usa" — no se puede saber si es porque la bici siempre estaba perfecta o porque nadie lo llena, pero la proporción (5 de cada 6 en blanco) es alta para asumir lo primero.

**Fotos:**
- Promedio 1.33 fotos de entrada y 1.42 de salida por ticket (16 y 17 fotos totales sobre 12 tickets).
- 0 tickets atendidos sin foto de salida — consistente con el guard duro del controlador (`marcarAtendido` lo bloquea).

**`registrado_por` vs. `mecanico_id` — la pregunta central:**
- **0 de 12 tickets (0%) tienen `registrado_por === mecanico_id`.**
- **100% de los tickets fueron registrados por alguien distinto del mecánico asignado:**
  - Wilmer Moreno registró, Carlos Rojas como mecánico → 6 tickets
  - Carlos Bolivar registró, Carlos Rojas como mecánico → 4 tickets
  - Carlos Bolivar registró, Kleiver Rojas como mecánico → 2 tickets
- **Esto confirma de forma contundente la hipótesis del prompt**: en el 100% de los casos reales, el técnico/mecánico no ha sido quien opera el formulario — siempre es recepción/administración (Wilmer, Carlos Bolivar) quien registra en su nombre. Ningún mecánico (`rol_taller=true`) ha generado un solo ticket a su propio nombre todavía.

---

## 7. Catálogos existentes: servicios, repuestos y precios

**Servicios**: `MONTOS_SERVICIO = { basico: 15, full: 20, vip: 25 }` — **constante hardcodeada en Vue** (`Taller/Nuevo.vue:25`), no hay tabla ni modelo de servicios en la BD. No es reutilizable como catálogo dinámico sin refactor; cualquier cambio de precio requiere editar y desplegar código.

**Repuestos / precios**: existe y **ya está activamente integrado** un catálogo real — modelo `Producto` (tabla `productos`, 894 registros en producción), con:
- `nombre` (normalizado a mayúsculas/espacios colapsados vía `Producto::normalizarNombre()`)
- `veces_usado` (contador de popularidad)
- Endpoint `GET /productos?q=` (`ProductoController::search`), ya consumido por `Taller/Show.vue::buscarSugerenciasRepuesto()` como autocompletado de texto libre con debounce 250ms.

Top productos reales en el catálogo: `COMIDA` (19x), `GARMIN FORERUNNER 165 MUSIC` (15x), `GARMIN EDGE 1050` (13x), `SERVICIO FULL` (10x), etc. — el catálogo es compartido con el resto del sistema (no exclusivo de Taller), lo que lo hace un candidato directo y ya probado para chips/autocompletado en cualquier campo de texto libre nuevo que se agregue al rediseño (ej. para estandarizar el campo `trabajo_realizado` o ítems de diagnóstico adicionales).

**Conclusión para el rediseño**: no hace falta crear un catálogo nuevo para repuestos (ya existe y funciona); si se quiere un catálogo de **servicios** con precios editables sin tocar código, eso sí habría que construirlo desde cero (hoy es 100% hardcoded).

---

## Resumen ejecutivo (para la decisión de rediseño)

1. El técnico **nunca** ha llenado el formulario él mismo (0/12 tickets) — el rediseño de "captura del técnico" parte de una situación donde hoy, literalmente, no hay ninguna captura del técnico que rediseñar; es una funcionalidad a **crear**, no a mejorar.
2. La infraestructura de login + gate de navegación para el técnico (`rol_taller`, middleware, 2 usuarios ya configurados) **ya existe y nunca se ha usado en la práctica**.
3. El checklist de diagnóstico (83% vacío) y el campo `trabajo_realizado` (42% con <20 caracteres) son los dos puntos de fricción más claros — ambos son campos de texto/estado libre sin ayuda de autocompletado ni estructura, llenados hoy por alguien que no estuvo frente a la bici.
4. El catálogo de `Producto` (894 ítems, ya con endpoint de búsqueda) es directamente reutilizable para cualquier chip/autocompletado nuevo; el catálogo de servicios no existe como datos, solo como constante en frontend.
5. El diagnóstico nunca se imprime en el PDF que ve el cliente — si el rediseño apunta a que el técnico lo llene de verdad, vale la pena decidir si debería empezar a imprimirse.

---

## Archivos copiados para revisión externa

Carpeta `.doc/auditoria-taller-2026-10-06/`:
- `Nuevo.vue`, `Show.vue` (Taller)
- `ComprobanteLightbox.vue` (único modal de imágenes reutilizado)
- `TallerController.php`, `TicketTaller.php`, `TicketRepuesto.php`
- `SembrarDemoTaller.php`, `LimpiarDemoTaller.php` (comandos de datos demo)
- `RestringeRolTaller.php` (middleware de gate por rol)
- `migraciones/` — las 9 migraciones de `tickets_taller` + `ticket_repuestos`
- `pdf/taller-atencion.blade.php`
