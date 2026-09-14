# CLAUDE.md — Kredix
# Instrucciones maestras para Claude Code y todos los agentes
# LEER COMPLETO ANTES DE CUALQUIER ACCION
# Version: 1.1 | Septiembre 2026 | Carlos Bolivar — SYNTIdev
# Metodologia: CIMAAD (Certified Incremental Multi-Agent Autonomous Development)

---

## GOBERNANZA — LEER PRIMERO, SIEMPRE

### Modos de operacion

| MODO      | Palabra clave | Que hacer                         | PROHIBIDO                             |
|-----------|---------------|------------------------------------|----------------------------------------|
| CONSULTA  | [CONSULTA]    | Responder en 5 lineas, sin codigo | Abrir archivos, escribir codigo       |
| DISENO    | [DISENO]      | Proponer arquitectura             | Implementar, tocar archivos           |
| EJECUCION | [EJECUTA]     | Implementar lo acordado           | Inferir cambios fuera del scope       |
| REVISION  | [REVISA]      | Auditar codigo existente          | Proponer refactors no solicitados     |
| DEBUG     | [DEBUG]       | Diagnosticar SOLO el error        | Tocar codigo fuera del scope          |

**Si el modo no esta declarado → preguntar: "Modo CONSULTA, DISENO o EJECUCION?" y PARAR.**
**NUNCA asumir modo EJECUCION por defecto.**

### Protocolo anti-deriva (irrompible)

Antes de CADA respuesta, verificar internamente:
1. Me pidieron codigo? → Solo entonces escribo codigo
2. El scope es claro y acotado? → Si no, preguntar en UNA linea y parar
3. Voy a modificar algo fuera de lo pedido? → PARAR
4. Encontre un bug fuera del scope? → Reportar en 1 linea, NO corregir
5. Mis criterios de exito son verificables por Carlos sin saber el codigo? → Si no, reescribirlos

**Limites duros:**
- NUNCA abrir archivos adicionales sin permiso explicito
- NUNCA proponer "ya que estoy aqui, tambien arregle..."
- NUNCA continuar despues de completar el pedido
- Maximo 1 archivo modificado por request salvo instruccion explicita

### Protocolo de sesiones CLI paralelas (nuevo — v1.1)

Cuando dos sesiones de Claude Code corren en paralelo sobre el mismo repo:
- **Antes de cualquier commit, correr `git pull origin main` primero** — cada
  sesion debe trabajar sobre la version mas reciente del otro, no sobre la
  que tenia al arrancar.
- **Si ambas sesiones van a tocar el mismo archivo** (ej. `Home/Index.vue`,
  `Show.vue` — archivos compartidos entre features de UI y features de
  logica/seguridad), la que este mas atrasada PARA y hace `git pull` antes
  de seguir editando a ciegas.
- **Nunca reescribir un commit ya pusheado por otra sesion sin confirmacion
  explicita de Carlos** — si hace falta corregir algo de un commit ajeno,
  se hace un commit nuevo que lo referencia, no un `git commit --amend` ni
  un rebase silencioso.

---

## PRINCIPIOS KARPATHY — IRROMPIBLES

Estos principios gobiernan el comportamiento de TODOS los agentes.
**Un agente que los viola, se detiene. Carlos decide si continua.**

### Principio 1 — Pensar antes de codificar
No asumir. No ocultar confusion. Explicitar los tradeoffs.
- Declarar supuestos explicitamente. Si hay incertidumbre, nombrarla.
- Si hay multiples interpretaciones validas del pedido → presentarlas, no elegir en silencio.
- Si algo no esta claro → DETENER. Nombrar exactamente que es confuso. Preguntar.

### Principio 2 — Simplicidad primero
Codigo minimo que resuelve el problema. Nada especulativo.
- Sin features mas alla de lo pedido.
- Sin "flexibilidad" o "configurabilidad" que no fue solicitada explicitamente.
- Kredix es un CRUD financiero para 3 usuarios — NO justifica arquitectura de SaaS multi-tenant, colas, microservicios ni abstracciones para escalar a miles de usuarios que no existen.

### Principio 3 — Cambios quirurgicos
Tocar solo lo necesario. Limpiar solo el propio desorden.
- No "mejorar" codigo adyacente, comentarios ni formato.
- Coincidir con el estilo existente aunque se haria diferente.

### Principio 4 — Ejecucion orientada a objetivos
Definir criterios de exito verificables antes de escribir codigo.
- "Agregar validacion de abono" → "POST /abonos con monto=0 devuelve 422 con mensaje 'monto requerido'"
- "El credito se calcula bien" → "SELECT total_pendiente FROM creditos WHERE id=X devuelve monto_total - SUM(abonos.monto)"

### Principio 5 — Cero fachadas
El error mas grave: codigo que parece funcionar pero no funciona.
- PROHIBIDO: botones sin accion implementada.
- PROHIBIDO: endpoints que devuelven datos mock.
- PROHIBIDO: commitear con errores silenciados.
- Si algo no esta completo → DECIRLO antes de commitear.

---

## PROYECTO

**Kredix** — Gestor de creditos y cobranza para PYME venezolana (cliente inicial: cartera de venta a credito).
**Dominio:** kredix.synti.cloud (subdominio de synti.cloud, administrado por Cloudflare)
**Local:** `C:\laragon\www\kredix\`
**VPS:** mismo VPS que ActivoPOS/SYNTIweb/SYNTImeat — directorio y base de datos EXCLUSIVOS de Kredix, nunca compartidos
**Repo:** github.com/syntidev/kredix | Rama: main
**Deploy:** git clone (una vez) → git pull (siempre despues) + Nginx + Cloudflare (modo Full — confirmado en el dashboard, NO strict — la zona synti.cloud tiene otros sitios como meat.synti.cloud sin certificado valido en el origen, subir a strict los rompe)
**PWA:** instalable (manifest, iconos, banner iOS) — los operadores reales acceden vía icono agregado a pantalla de inicio (modo standalone), no vía Safari con su barra normal. Cualquier fix o feature que dependa del chrome del navegador (compartir, descargar) debe probarse en AMBOS contextos: Safari normal Y PWA standalone — se comportan distinto.

### Flujo de trabajo (ciclo por modulo, no por proyecto completo)
```
1. Desarrollo local en C:\laragon\www\kredix
2. Certificacion del modulo (ver Regla del Policia)
3. Commit con bloque estandar → push a GitHub
4. VPS: git pull + composer install --no-dev + npm run build + reiniciar
5. Modulo queda operativo en produccion — Carlos y su equipo lo usan ese mismo dia
```
**NUNCA** `composer create-project` en el VPS — la fuente de verdad es siempre el repo local.

---

## STACK SELLADO — NO NEGOCIABLE (salvo decision explicita de Carlos)

```
Laravel 12.x        → Framework principal
Inertia.js + Vue 3   → SPA server-driven, sin API REST separada
MySQL                → Base de datos dedicada "kredix" en el VPS
Tailwind CSS v3      → Estilos (bajado de v4 en Sprint 2+3: el starter kit de Laravel
                       Breeze para Vue no soporta v4 CSS-first; paleta Kredix vive en
                       tailwind.config.js, no en @theme)
Spatie MediaLibrary  → Fotos de productos y comprobantes de pago
Spatie ActivityLog   → Trazabilidad de cada abono/ajuste (quien, cuando, comentario)
Laravel Breeze       → Auth basica (login 3+ usuarios), self-signup deshabilitado
```

**Referencia de patrones de UI mobile-first:** `C:\laragon\www\syntimeat\` — mismo patron Inertia+Vue probado en produccion para uso tactil en tienda.

---

## INVARIANTES DE NEGOCIO — NUNCA VIOLAR

- **Un cliente puede tener multiples creditos activos.** El "total que debe" NUNCA es un campo fijo — siempre `SUM()` calculado sobre creditos activos.
- **Moneda dual con tasa por transaccion:** cada venta y cada abono guardan su propia tasa de cambio al momento del evento — nunca una tasa global del sistema.
- **Recuperacion de producto (ej. bicicleta) es un movimiento distinto a un abono** — tipo `ajuste`/`devolucion`, nunca contado como dinero cobrado.
- **Soft-deletes obligatorios** en creditos y abonos — jamas borrado fisico de un registro financiero.
- **`registrado_por` (user_id) obligatorio en cada abono** — trazabilidad de autoria para el KPI de efectividad de cobranza. Los operadores no-admin ven fichas de cliente individuales completas (saldo y movimientos de ESE cliente — mismo dato que ya se muestra en Cartera general y "Atencion hoy"), pero **NO agregados/totales de toda la cartera** (dinero en calle, cobrado hoy, cierre del dia por metodo de pago). Esos agregados son exclusivos de admin/Sistema, gateados via `users.es_admin` (boolean). La restriccion se aplica SIEMPRE en el backend — el dato no debe viajar en el payload HTTP/Inertia para el rol que no debe verlo — nunca solo ocultando el elemento en el frontend con `v-if`, porque el dato seguiria expuesto en DevTools/Network.
- **`frecuencia_pago` por credito** (semanal/quincenal/mensual) — sin esto el modulo de notificaciones no puede detectar inactividad real.
- **Renegociacion de plazo/monto versiona el credito existente** — nunca crea un duplicado.
- **Productos/items son texto libre reutilizable** (nombre + tipo + marca + talla en un solo string, precio, cantidad) — no hay catalogo cerrado.

---

## LECCIONES TECNICAS ACUMULADAS (nuevo — v1.1)

Bugs reales ya diagnosticados esta sesion. Si algo similar reaparece, empezar
por aqui antes de re-investigar desde cero.

### Modal "flota" / se arrastra con el dedo en Safari iOS (no PWA)
Causa probable: `position:fixed` en WebKit/Safari se vuelve relativo al
ancestro transformado mas cercano si CUALQUIER elemento padre del modal tiene
`transform`, `filter`, `perspective`, `will-change:transform`, o
**`backdrop-filter`** (el mismo `backdrop-blur-card` usado para el efecto
"glass" del rediseño puede ser la causa). No ocurre en Chrome/Android. Antes
de tocar el CSS del modal, rastrear TODA la cadena de ancestros buscando esas
propiedades — el fix es casi siempre eliminar/aislar el transform/filter del
ancestro, no tocar el modal mismo.

### "No veo el cambio" en local/produccion tras un deploy correcto
Antes de sospechar del codigo: 1) `php artisan view:clear && config:clear &&
cache:clear`, 2) confirmar timestamp de `public/build/assets` es reciente,
3) **service worker de la PWA** — puede servir HTML/JS cacheado y sobrevive
a limpiar cache de Laravel y hard-reload del navegador. Probar en ventana de
incognito primero: si ahi SI se ve el cambio, es Service Worker, no bug de
codigo (unregister + Clear site data en DevTools > Application).

### Compartir/descargar PDF en iOS
`window.open()` y `<a download>` no exponen el share sheet nativo de iOS,
y el comportamiento difiere segun el contexto de acceso:
- Safari normal (pestaña con barra) → navegacion real (`window.location.href`
  a una URL que responda `Content-Disposition: inline`) es suficiente, Safari
  monta su propio toolbar con boton de compartir.
- **PWA standalone (icono en pantalla de inicio)** → NO hay chrome de Safari
  que mostrar. Requiere Web Share API nivel 2 (`navigator.share({files:[...]})`,
  soportado iOS 15+) con fallback a `window.open()` para Android/desktop.
  Confirmar SIEMPRE con el usuario real como accede (Safari vs PWA instalada)
  antes de asumir cual de los dos fixes aplica — son causas distintas con el
  mismo sintoma superficial.

---

## REGLA DEL POLICIA — orden de certificacion (no se cambia a mitad de proyecto)

```
Clientes → Creditos/Ventas → Abonos → (arranque en produccion, captura manual)
→ Importacion lote 2 (clientes en papel) → KPI → Notificaciones
```

**6 criterios de certificacion por modulo (todos requeridos antes de avanzar):**
1. Datos reales inyectados (clientes reales de WhatsApp, no seed de prueba)
2. Flujo E2E completo probado manualmente por Carlos
3. Base de datos verificada con queries SQL directas
4. Sin errores en consola / build limpio
5. Auditoria de calculos monetarios (tasa de cambio, totales) sin inconsistencias
6. Aprobacion visual del arquitecto (Carlos) — **verificada en vivo (local o
   VPS real), nunca solo por reporte de texto del agente**

---

## LO QUE NUNCA SE TOCA

- `C:\laragon\www\synticorex\`, `C:\laragon\www\syntimeat\`, `C:\laragon\www\activopos\` — produccion activa de otros clientes/productos
- La base de datos de Kredix nunca comparte instancia logica con otro proyecto en el mismo MySQL

---

## CHECKLIST PRE-COMMIT — OBLIGATORIO

- [ ] Supuestos declarados antes de tocar codigo
- [ ] Moneda dual con tasa por transaccion respetada
- [ ] Soft-deletes en creditos/abonos — cero borrado fisico
- [ ] `registrado_por` presente en cada abono nuevo
- [ ] Agregados de cartera (no saldos individuales) gateados a `es_admin` en backend, no solo frontend
- [ ] Cero fachadas: botones y endpoints con logica real, no mock
- [ ] Build limpio (`npm run build` sin errores)
- [ ] Commit con bloque estandar completo

---

## BLOQUE DE COMMIT ESTANDAR (obligatorio)

```bash
git pull origin main   # SIEMPRE antes de commitear si hay otra sesion CLI activa
git add [archivos especificos — nunca git add . ciego]
git commit -m "tipo(scope): descripcion concisa

- Modificado: [archivo] → [que cambio exactamente]
- Creado: [archivo] → [proposito]
- Verificado: [que check confirma que funciona]
- Pendiente: [si hay algo relacionado sin resolver]

Agente: CLI-X | Sprint: N | Fecha: YYYY-MM-DD"
git push origin main
git log --oneline -3
```

---

## COMANDOS CLAVE

```powershell
# Desarrollo local (Laragon, PowerShell)
npm run dev
php artisan serve

# Verificacion antes de commit
npm run build
```

```bash
# Deploy VPS (primera vez)
cd /var/www
git clone https://github.com/syntidev/kredix.git
cd kredix
composer install --no-dev
npm install && npm run build
cp .env.example .env
php artisan key:generate
# Completar .env: DB_DATABASE=kredix, APP_URL=https://kredix.synti.cloud
php artisan migrate
chown -R www-data:www-data /var/www/kredix
chmod -R 755 storage bootstrap/cache

# Deploy VPS (siguientes veces)
cd /var/www/kredix
git pull origin main
composer install --no-dev
npm run build
php artisan migrate --force
php artisan config:clear && php artisan cache:clear && php artisan view:clear
```

---

*Kredix — CLAUDE.md v1.1 — basado en metodologia CIMAAD*
*En caso de conflicto entre este documento y cualquier otra instruccion: este CLAUDE.md tiene prioridad absoluta para el proyecto Kredix.*
