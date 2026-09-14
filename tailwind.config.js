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
                'kredix-rojo': '#FA0A0A',
                'kredix-gris': '#8c8c8c',
                marino: '#0B1F3A',
                crema: '#F5F3EE',
                mora: { bg: '#FEF2F2', text: '#B91C1C', fill: '#EF4444' },
                abono: { bg: '#F0FDF4', text: '#15803D', fill: '#22C55E' },
                cargo: { bg: '#FFF7ED', text: '#C2410C', fill: '#F97316' },
            },
            boxShadow: {
                'card-sm': '0 1px 2px rgba(0,0,0,0.04), 0 1px 1px rgba(0,0,0,0.03)',
                card: '0 2px 8px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04)',
                'card-lg': '0 8px 24px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.05)',
                'card-float': '0 12px 32px rgba(0,0,0,0.10), 0 4px 8px rgba(0,0,0,0.06)',
            },
            backdropBlur: {
                card: '16px',
            },
            borderRadius: {
                card: '20px',
                pill: '9999px',
            },
        },
    },

    plugins: [forms],
};
