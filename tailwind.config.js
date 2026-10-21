import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'kredix-negro': '#101010',
                'kredix-rojo': '#EA0A0A',
                'kredix-gris': '#8c8c8c',
                marino: '#0B1F3A',
                crema: '#EDEFF5',
                mora: { bg: '#FEF2F2', text: '#B91C1C', fill: '#EF4444' },
                abono: { bg: '#F0FDF4', text: '#15803D', fill: '#22C55E' },
                cargo: { bg: '#FFF7ED', text: '#C2410C', fill: '#F97316' },
                // sistema visual del shell (Fase 1): fondo/tinta/primario/activo/suave/peligro/exito
                fondo: '#eef0f5',
                tinta: '#0f172a',
                primario: { DEFAULT: '#111827', claro: '#1f2937' },
                activo: '#3730a3',
                suave: '#5b6472',
                peligro: { DEFAULT: '#b42318', bg: '#fde8e6' },
                exito: '#15803d',
                linea: '#e1e4ec',
                'nav-inactivo': '#4b5563',
                lateral: { texto: '#e5e7eb', suave: '#9ca3af' },
            },
            // escala de capas del shell: contenido < mas < barra < modales de pagina (z-30) < hoja (+)
            zIndex: {
                mas: '20',
                barra: '25',
                modal: '30',
                hoja: '40',
            },
            transitionTimingFunction: {
                kredix: 'cubic-bezier(.2,.8,.2,1)',
            },
            boxShadow: {
                // elevacion con sombras en capas de tinte frio (slate), de menor a mayor
                e1: '0 1px 2px rgba(15,23,42,0.06), 0 2px 6px rgba(15,23,42,0.06)',
                e2: '0 2px 4px rgba(15,23,42,0.06), 0 8px 20px rgba(15,23,42,0.10)',
                e3: '0 4px 8px rgba(15,23,42,0.08), 0 16px 40px rgba(15,23,42,0.16)',
                // boton primario: realce interior de 1px + elevacion; "presionado" la reduce
                primario: 'inset 0 1px 0 rgba(255,255,255,0.5), 0 1px 2px rgba(15,23,42,0.20), 0 4px 10px rgba(15,23,42,0.18)',
                'primario-presionado': 'inset 0 1px 0 rgba(255,255,255,0.5), 0 1px 1px rgba(15,23,42,0.20)',
                'barra-inferior': 'inset 0 1px 0 rgba(255,255,255,0.5), 0 -4px 16px rgba(15,23,42,0.08)',
                mas: 'inset 0 1px 0 rgba(255,255,255,0.35), 0 2px 4px rgba(15,23,42,0.25), 0 8px 18px rgba(15,23,42,0.25)',
                'card-sm': '0 4px 12px rgba(30,41,59,0.08), 0 1px 3px rgba(30,41,59,0.04)',
                card: '0 10px 28px rgba(30,41,59,0.10), 0 2px 8px rgba(30,41,59,0.05)',
                'card-lg': '0 14px 34px rgba(30,41,59,0.12), 0 4px 10px rgba(30,41,59,0.06)',
                'card-float': '0 12px 32px rgba(30,41,59,0.14), 0 4px 10px rgba(30,41,59,0.06)',
            },
            backdropBlur: {
                card: '16px',
            },
            borderRadius: {
                k10: '10px',
                k12: '12px',
                k14: '14px',
                k22: '22px',
                card: '20px',
                pill: '9999px',
            },
        },
    },

    plugins: [forms],
};
