import { ref, watch } from 'vue';

// Retroalimentacion de interaccion (sonido sintetizado + vibracion). Sin archivos
// de audio; el AudioContext se crea en el primer gesto del usuario (click/open/
// success solo se llaman desde handlers de eventos), nunca al cargar la pagina.
const CLAVE_SONIDOS = 'kredix.sonidos';
const CLAVE_VIBRACION = 'kredix.vibracion';

function leer(clave) {
    try {
        return localStorage.getItem(clave) !== '0';
    } catch {
        return true;
    }
}

function guardar(clave, valor) {
    try {
        localStorage.setItem(clave, valor ? '1' : '0');
    } catch {
        // almacenamiento bloqueado: el interruptor vale solo para esta sesion
    }
}

// por dispositivo, activados por defecto; compartidos por todos los componentes
const sonidos = ref(leer(CLAVE_SONIDOS));
const vibracion = ref(leer(CLAVE_VIBRACION));
// sync: el ajuste queda guardado en el mismo instante, aunque la pagina se recargue enseguida
watch(sonidos, (v) => guardar(CLAVE_SONIDOS, v), { flush: 'sync' });
watch(vibracion, (v) => guardar(CLAVE_VIBRACION, v), { flush: 'sync' });

const vibracionSoportada = typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function';

let contexto = null;

function obtenerContexto() {
    if (contexto) {
        return contexto;
    }
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) {
        return null;
    }
    contexto = new Ctor();
    return contexto;
}

// un tono: frecuencia desde -> hasta (exponencial), ganancia pico, duracion en ms
function tono(ctx, { desde, hasta = desde, ms, ganancia, inicio = 0, tipo = 'sine' }) {
    const t0 = ctx.currentTime + inicio;
    const fin = t0 + ms / 1000;
    const osc = ctx.createOscillator();
    const amp = ctx.createGain();
    osc.type = tipo;
    osc.frequency.setValueAtTime(desde, t0);
    if (hasta !== desde) {
        osc.frequency.exponentialRampToValueAtTime(hasta, fin);
    }
    amp.gain.setValueAtTime(ganancia, t0);
    amp.gain.exponentialRampToValueAtTime(0.0001, fin);
    osc.connect(amp).connect(ctx.destination);
    osc.start(t0);
    osc.stop(fin + 0.01);
}

function sonar(tonos) {
    if (!sonidos.value) {
        return;
    }
    const ctx = obtenerContexto();
    if (!ctx) {
        return;
    }
    if (ctx.state === 'suspended') {
        ctx.resume();
    }
    tonos.forEach((t) => tono(ctx, t));
}

function vibrar(patron) {
    if (vibracion.value && vibracionSoportada) {
        navigator.vibrate(patron);
    }
}

export function useFeedback() {
    return {
        sonidos,
        vibracion,
        vibracionSoportada,
        // toque: triangulo 1200 -> 600 Hz, ~35 ms, ganancia <= 0.05
        click() {
            sonar([{ tipo: 'triangle', desde: 1200, hasta: 600, ms: 35, ganancia: 0.05 }]);
            vibrar(6);
        },
        // apertura de hoja: ~520 Hz, 60 ms, ganancia <= 0.04
        open() {
            sonar([{ desde: 520, ms: 60, ganancia: 0.04 }]);
            vibrar(6);
        },
        // exito (expuesto, aun sin conectar): 660 y 880 Hz, ~90 ms cada uno
        success() {
            sonar([
                { desde: 660, ms: 90, ganancia: 0.05 },
                { desde: 880, ms: 90, ganancia: 0.05, inicio: 0.09 },
            ]);
            vibrar([8, 40, 8]);
        },
    };
}
