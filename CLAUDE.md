# CLAUDE.md — Kredix
# Instrucciones maestras para Claude Code y todos los agentes
# LEER COMPLETO ANTES DE CUALQUIER ACCION
# Version: 1.0 | Septiembre 2026 | Carlos Bolivar — SYNTIdev
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
**Repo:** github.com/syntidev/kredix (por crear) | Rama: main
**Deploy:** git clone (una vez) → git pull (siempre despues) + Nginx + Cloudflare (Full/strict, Origin Certificate)

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
Laravel 11.x        → Framework principal
Inertia.js + Vue 3   → SPA server-driven, sin API REST separada
MySQL                → Base de datos dedicada "kredix" en el VPS
Tailwind CSS         → Estilos (decision para este proyecto — distinto de ActivoPOS, que usa CSS Modules)
Spatie MediaLibrary  → Fotos de productos y comprobantes de pago
Spatie ActivityLog   → Trazabilidad de cada abono/ajuste (quien, cuando, comentario)
```

**Referencia de patrones de UI mobile-first:** `C:\laragon\www\syntimeat\` — mismo patron Inertia+Vue probado en produccion para uso tactil en tienda.

---

## INVARIANTES DE NEGOCIO — NUNCA VIOLAR

- **Un cliente puede tener multiples creditos activos.** El "total que debe" NUNCA es un campo fijo — siempre `SUM()` calculado sobre creditos activos.
- **Moneda dual con tasa por transaccion:** cada venta y cada abono guardan su propia tasa de cambio al momento del evento — nunca una tasa global del sistema.
- **Recuperacion de producto (ej. bicicleta) es un movimiento distinto a un abono** — tipo `ajuste`/`devolucion`, nunca contado como dinero cobrado.
- **Soft-deletes obligatorios** en creditos y abonos — jamas borrado fisico de un registro financiero.
- **`registrado_por` (user_id) obligatorio en cada abono** — sin roles de acceso restringido (los 3 usuarios ven toda la cartera), pero con trazabilidad de autoria para el KPI de efectividad de cobranza.
- **`frecuencia_pago` por credito** (semanal/quincenal/mensual) — sin esto el modulo de notificaciones no puede detectar inactividad real.
- **Renegociacion de plazo/monto versiona el credito existente** — nunca crea un duplicado.
- **Productos/items son texto libre reutilizable** (nombre + tipo + marca + talla en un solo string, precio, cantidad) — no hay catalogo cerrado.

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
6. Aprobacion visual del arquitecto (Carlos)

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
- [ ] Cero fachadas: botones y endpoints con logica real, no mock
- [ ] Build limpio (`npm run build` sin errores)
- [ ] Commit con bloque estandar completo

---

## BLOQUE DE COMMIT ESTANDAR (obligatorio)

```bash
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
```

---

*Kredix — CLAUDE.md v1.0 — basado en metodologia CIMAAD*
*En caso de conflicto entre este documento y cualquier otra instruccion: este CLAUDE.md tiene prioridad absoluta para el proyecto Kredix.*
