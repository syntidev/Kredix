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

## Sprint 2 — Creditos / Ventas

**Objetivo:** registrar el momento de entrega del producto y el monto a credito.
**CLI:** CLI-A, CLI-C

**Tareas:**
1. Migraciones `ventas_credito`, `items_venta` (producto texto libre + precio + cantidad)
2. Motor de `reglas_plazo` parametrizable (monto min/max → plazo min/max, requiere_abono)
3. Captura de tasa de cambio en el momento de la venta (no global)
4. `frecuencia_pago` por credito

**Cierra cuando:** una venta a credito real, con su plazo calculado por la regla correspondiente, queda registrada y auditada.

---

## Sprint 3 — Abonos

**Objetivo:** cuantificar dinero entregado, con comprobante y comentario.
**CLI:** CLI-A, CLI-C

**Tareas:**
1. Migracion `abonos` (monto, moneda, tasa_cambio, metodo_pago, comentario, `registrado_por`)
2. Adjuntar imagen de comprobante via Spatie MediaLibrary
3. Movimiento tipo `ajuste`/`devolucion` separado del abono normal (caso de recuperacion de producto)
4. Soft-deletes verificados — cero borrado fisico

**Cierra cuando:** un abono real, con foto y comentario, actualiza el saldo pendiente de un credito via `SUM()`.

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

## Lo que NO esta en este plan (a proposito)

- Roles/permisos granulares — no aplica, los 3 usuarios ven toda la cartera
- Multi-tenant — confirmado como cliente unico
- Roadmap SEO, plan social, matriz de permisos sellada — no aplican a un sistema interno sin catalogo publico

Si alguno de estos se vuelve necesario, se agrega como sprint nuevo cuando exista
la necesidad real — no antes.
