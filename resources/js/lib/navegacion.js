import {
    ArrowDown,
    ArrowUp,
    BarChart3,
    Landmark,
    Megaphone,
    NotebookPen,
    Settings,
    Target,
    UserCog,
    UserPlus,
    Wallet,
    Wrench,
} from '@lucide/vue';

// Unico lugar donde viven los destinos del shell y el mapeo ruta -> tab activa.
// La seguridad real sigue en el backend (RestringeRolTaller, es_admin,
// acceso_conciliacion): esto solo evita mostrar links que devolverian 403.

/** Permisos de navegacion derivados del usuario autenticado. */
export function permisosNav(user) {
    const esTaller = !!user?.rol_taller;
    const esAdmin = !esTaller && !!user?.es_admin;

    return {
        esTaller,
        esAdmin,
        conciliacion: !esTaller && (!!user?.es_admin || !!user?.acceso_conciliacion),
    };
}

/** Ruta sin query/hash. */
export function rutaDe(url) {
    return (url ?? '').split(/[?#]/)[0];
}

/** Tab inferior activa segun la URL: clientes -> Clientes, taller -> Taller, inicio -> Inicio, el resto -> Mas. */
export function tabDeRuta(url) {
    const ruta = rutaDe(url);

    if (ruta === '/' || ruta === '/home') return 'inicio';
    if (ruta === '/clientes' || ruta.startsWith('/clientes/')) return 'clientes';
    if (ruta === '/taller' || ruta.startsWith('/taller/')) return 'taller';

    return 'mas';
}

/** Link activo: la ruta coincide o es un hijo (/clientes/5 activa /clientes). */
export function esRutaActiva(url, href) {
    const ruta = rutaDe(url);
    const base = rutaDe(href);

    return ruta === base || ruta.startsWith(`${base}/`);
}

// acciones de la hoja (+): mismos destinos/flujos que las acciones rapidas de Inicio
export const ACCIONES_COBRANZA = [
    { clave: 'abono', label: 'Abono', icon: ArrowDown, tono: 'abono' },
    { clave: 'cargo', label: 'Cargo', icon: ArrowUp, tono: 'cargo' },
    { clave: 'cliente', label: 'Cliente', icon: UserPlus, href: '/clientes?nuevo=1', tono: 'neutro' },
    { clave: 'gestion', label: 'Gestión', icon: NotebookPen, tono: 'neutro' },
];

export const ACCION_TALLER = { clave: 'ticket', label: 'Nuevo ticket de taller', icon: Wrench, href: '/taller/nuevo' };

/** Grupos de la pantalla "Mas" (movil). Clientes, Taller e Inicio son tabs; Mi perfil y Salir van aparte. */
export function gruposMas(permisos) {
    if (permisos.esTaller) {
        return [];
    }

    return [
        {
            titulo: 'Cobranza',
            items: [
                { href: '/cartera', label: 'Cartera', icon: Wallet },
                ...(permisos.conciliacion ? [{ href: '/conciliacion', label: 'Conciliación', icon: Landmark }] : []),
                ...(permisos.esAdmin ? [{ href: '/kpi', label: 'KPI', icon: BarChart3 }] : []),
            ],
        },
        {
            titulo: 'Crecimiento',
            items: [
                { href: '/prospectos', label: 'Prospectos', icon: Target },
                { href: '/cartelera', label: 'Cartelera', icon: Megaphone },
            ],
        },
        {
            titulo: 'Administración',
            items: [
                { href: '/configuracion', label: 'Configuración', icon: Settings },
                ...(permisos.esAdmin ? [{ href: '/usuarios', label: 'Usuarios', icon: UserCog }] : []),
            ],
        },
    ];
}

/** Grupos de la barra lateral (escritorio). `match` afina la coincidencia cuando dos items comparten prefijo. */
export function gruposSidebar(permisos) {
    const taller = {
        titulo: 'Taller',
        items: [
            { href: '/taller', label: 'Tickets', match: (ruta) => ruta === '/taller' || (ruta.startsWith('/taller/') && ruta !== '/taller/nuevo') },
            { href: '/taller/nuevo', label: 'Nuevo ticket' },
        ],
    };

    if (permisos.esTaller) {
        return { inicio: null, grupos: [taller] };
    }

    return {
        inicio: { href: '/home', label: 'Inicio' },
        grupos: [
            {
                titulo: 'Cobranza',
                items: [
                    { href: '/clientes', label: 'Clientes' },
                    { href: '/cartera', label: 'Cartera' },
                    ...(permisos.conciliacion ? [{ href: '/conciliacion', label: 'Conciliación' }] : []),
                    ...(permisos.esAdmin ? [{ href: '/kpi', label: 'KPI' }] : []),
                ],
            },
            taller,
            {
                titulo: 'Crecimiento',
                items: [
                    { href: '/prospectos', label: 'Prospectos' },
                    { href: '/cartelera', label: 'Cartelera' },
                ],
            },
        ],
    };
}

/** Items del menu de usuario de escritorio (Salir y los interruptores se agregan en el componente). */
export function itemsMenuUsuario(permisos) {
    if (permisos.esTaller) {
        return [];
    }

    return [
        { href: '/configuracion', label: 'Configuración' },
        ...(permisos.esAdmin ? [{ href: '/usuarios', label: 'Usuarios' }] : []),
        { href: '/profile', label: 'Mi perfil' },
    ];
}
