# SYSTEM_MAP.md — Kredix
# Arbitro unico de verdad sobre el estado real del sistema
# Ultima actualizacion: 2026-09-08 | Sprint: 0 (pre-inicio)

---

## Estado general

- **Build:** Laravel 12.x + Inertia + Vue 3 + Tailwind v4 (CSS-first, sin config.js)
  scaffoldeado en `C:\laragon\www\kredix`, `composer audit` limpio, build y smoke
  test HTTP 200 verificados por CLI-A
- **Ultimo sprint cerrado:** Sprint 0 Tarea 1 (scaffold local + push) — CERRADA.
  Tarea 2 (deploy skeleton VPS) pendiente
- **Tests:** no aplica todavia
- **Deploy VPS:** COMPLETO — `/var/www/kredix` clonado, migrado contra MySQL `kredix`,
  vhost Nginx con SSL (Cloudflare Origin Certificate, modo Full) respondiendo HTTP 200
  local. Pendiente solo verificacion externa desde navegador.
- **Usuario MySQL dedicado:** `kredix_app` creado, password rotado tras incidente CRLF,
  guardado en `.env` del servidor
- **Sprint 0:** CERRADO (Tarea 1 scaffold+push, Tarea 2 deploy skeleton con SSL)
- **Repo:** github.com/syntidev/Kredix (main en commit b507dd0, force-push confirmado
  explicitamente por Carlos, commit anterior 50b3584 era solo boilerplate Laravel 11)
- **Cliente final:** OnBike Margarita

---

## Regla del Policia — estado actual

```
Clientes            ⏳ siguiente
Creditos/Ventas     🔒 no iniciado
Abonos              🔒 no iniciado
(arranque produccion, captura manual)
Importacion lote 2  🔒 no iniciado
KPI                 🔒 no iniciado
Notificaciones      🔒 no iniciado
```

Sprint 0 (Fundacion — scaffold + deploy) certificado y cerrado.

Ningun modulo avanza sin cumplir los 6 criterios de certificacion de CLAUDE.md.

---

## Deuda tecnica

Ninguna registrada — proyecto sin codigo aun.

(Formato cuando exista: DT-001 | descripcion | severidad P0-P3 | modulo | dueño | fecha)

---

## Invariantes de seguridad/negocio verificadas

Ninguna verificada aun. Lista de invariantes a verificar segun avancen los modulos
(definidas en CLAUDE.md):

- [ ] Moneda dual con tasa por transaccion (no tasa global)
- [ ] Soft-deletes en creditos y abonos
- [ ] `registrado_por` obligatorio en cada abono
- [ ] Un cliente puede tener multiples creditos activos — total siempre `SUM()`, nunca campo fijo
- [ ] Recuperacion de producto registrada como `ajuste`/`devolucion`, nunca como abono
- [ ] Renegociacion versiona el credito existente, nunca duplica

---

## Bloqueantes antes de avanzar

1. Confirmar usuario MySQL dedicado para Kredix en el VPS (no reutilizar credenciales
   de synticorex/syntimeat)
2. Crear repo `github.com/syntidev/kredix`
3. Scaffold inicial de Laravel + Inertia + Vue 3 en local

---

## Proximas acciones (orden)

1. `laravel new kredix` en `C:\laragon\www\kredix` + instalar Inertia/Vue 3 + Spatie MediaLibrary/ActivityLog
2. Skeleton de despliegue: repo en GitHub → clone en VPS → Nginx + Cloudflare → pagina de bienvenida de Laravel visible en `kredix.synti.cloud`
3. Sprint 1 — Modulo Clientes: migracion + modelo + formulario de captura (CLI-A) → auditoria (CLI-C) → certificacion → deploy
4. Actualizar este documento al cerrar cada sprint


# PLAN_MAESTRO — Kredix
# Secuencia de sprints, dimensionada a un CRUD de 3 usuarios — no a la escala de ActivoPOS
# Version: 1.0 | Septiembre 2026

---

## Como leer este plan

Cada sprint tiene: objetivo, CLI responsable, criterios verificables (los mismos
6 de CLAUDE.md se aplican a nivel de modulo, no se repiten aqui uno por uno),
y bloqueantes que deben resolverse ANTES de que el sprint arranque.

No hay CERT_SprintN ni HANDOFF_SprintN como archivos separados — ese volumen de
documentos tiene sentido en un proyecto de 113 sprints, no en uno de 7. El estado
de cada sprint se actualiza directamente en `.doc/SYSTEM_MAP.md` al cerrarlo.

---

## Sprint 0 — Fundacion (bloqueante activo)

**Objetivo:** infraestructura lista, cero codigo de negocio.
**CLI:** CLI-A (scaffold), Carlos (VPS/DNS/MySQL)

**Bloqueantes antes de arrancar:**
- [ ] Usuario MySQL dedicado `kredix_app` creado en el VPS (sin resolver — ver turno anterior)
- [ ] Repo `syntidev/kredix` creado en GitHub

**Tareas:**
1. Scaffold Laravel 11 + Inertia + Vue 3 + Tailwind + Spatie (prompt ya entregado, corregido con linea de skills)
2. Skeleton de despliegue: clone en VPS → Nginx → Cloudflare → `kredix.synti.cloud` sirviendo la pagina de bienvenida de Laravel

**Cierra cuando:** `kredix.synti.cloud` responde en produccion sin una sola tabla de negocio creada todavia.

---

## Sprint 1 — Clientes

**Objetivo:** capturar clientes reales de WhatsApp desde produccion.
**CLI:** CLI-A (migracion + modelo + formulario Inertia/Vue), CLI-C (audita antes de deploy)

**Tareas:**
1. Migracion `clientes` (nombre, telefono, email, cedula opcional)
2. Formulario de captura mobile-first, touch-friendly
3. CLI-C audita: sin roles restringidos, `registrado_por` no aplica aun (no hay dinero involucrado)

**Cierra cuando:** los 6 criterios de certificacion de CLAUDE.md pasan Y el modulo esta desplegado — los 3 usuarios pueden cargar clientes reales ese mismo dia.

---

## Sprint 2+3 (REVISADO) — Cuenta corriente por cliente

**Cambio de modelo, documentado el 2026-09-08:** el diseño original (Sprint 2 Creditos/Ventas
con saldo independiente por venta + Sprint 3 Abonos atados a una venta especifica) no
corresponde a como OTOMIX MARGARITA realmente lleva la cartera. La hoja de referencia real
(WhatsApp transcrito a Excel) muestra una **cuenta corriente por cliente**: cada compra es
un cargo, cada pago (efectivo, Zelle, o intercambio valorado como una maquina) es un abono,
ambos en una sola linea de tiempo por cliente, con saldo acumulado — no por venta.

**Objetivo:** un solo modulo `movimientos_cuenta` (cargo/abono) reemplaza a `ventas_credito`
+ `items_venta` + `abonos`. Saldo pendiente = SUM(cargos) - SUM(abonos), siempre por cliente.
Se aprovecha el rebuild para agregar login basico (Breeze) — necesario para que
`registrado_por` sea real y no un valor fijo.

**CLI:** CLI-A (rebuild completo), CLI-C (audita el calculo de saldo antes de dar por cerrado)

**Cierra cuando:** el saldo acumulado de un cliente con cargos + abonos + un ajuste tipo
intercambio calza exactamente con el ejemplo de la hoja de OTOMIX, y el login distingue
cual de los 3 usuarios registro cada movimiento.

`reglas_plazo` no se elimina — pasa a ser metadata informativa sobre un cargo puntual
(plazo sugerido), ya no controla ningun saldo propio.

---

## → Arranque en produccion (no es un sprint, es un hito)

Con Sprint 1-3 certificados y desplegados, los 3 usuarios empiezan a operar Kredix
para la cartera real. La lista de 30 clientes en papel sigue esperando su turno
(Sprint 4) — no bloquea el arranque con los clientes de WhatsApp.

---

## Sprint 4 — Importacion lote 2 (30 clientes en papel)

**Objetivo:** digitalizar la cartera documentada solo en papel.
**CLI:** CLI-A (importer de una sola vez, no reutilizable como feature)

**Bloqueante:** protocolo de entrevista por cliente para reconstruir plazo y
condiciones de abono que no estan escritas en ningun lado — esto es trabajo de
campo de Carlos/equipo, no de codigo. El importer no puede arrancar sin esos
datos ya recolectados.

**Cierra cuando:** los 30 clientes estan en el sistema con sus creditos y
condiciones reales, no con datos inventados para que "cuadre".

---

## Sprint 5 — KPI

**Objetivo:** dinero en calle, recuperacion, efectividad de cobrador, score de pagador.
**Bloqueante:** formula de "buen/mal pagador" definida por Carlos (escala 1-5 manual
+ indicador automatico de puntualidad) — no se construye sin esa definicion.

---

## Sprint 6 — Notificaciones

**Objetivo:** alertas de inactividad de pago segun `frecuencia_pago` por credito.
**Depende de:** Sprint 2 (frecuencia_pago) y datos reales de Sprint 1-4 para calibrar
que umbral de dias es "alarma real" vs "normal".

---

## Item planificado (en pauta, no construido aun) — Recordatorio por WhatsApp

**Idea (Carlos, 2026-09-09):** boton en la ficha de cliente que arma un mensaje de
cobranza (saludo, estado del credito, saldo pendiente, invitacion cordial a abonar)
usando datos reales, y abre WhatsApp con el texto pre-cargado listo para enviar.

**Decisiones ya tomadas, pendientes de ejecutar:**
- Usar deep link `wa.me/{telefono}?text=...` — NO WhatsApp Business API (verificacion
  Meta, costo mensual, sobre-ingenieria para 3 usuarios enviando manualmente)
- Numero de telefono: siempre el ya guardado en la ficha del cliente, nunca fijo/hardcoded
- Intro del mensaje: editable por el usuario, vive en un modulo de **Configuracion**
  separado del "Sistema" actual (que es el perfil de Breeze, no configuracion de la app)

**Depende de:** modulo de Configuracion (no existe todavia, se crea junto con esto).

---

---

## Lo que NO esta en este plan (a proposito)

- Roles/permisos granulares — no aplica, los 3 usuarios ven toda la cartera
- Multi-tenant — confirmado como cliente unico
- Roadmap SEO, plan social, matriz de permisos sellada — no aplican a un sistema interno sin catalogo publico

Si alguno de estos se vuelve necesario, se agrega como sprint nuevo cuando exista
la necesidad real — no antes.