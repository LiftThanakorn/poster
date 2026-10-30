import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Sarabun', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                navy: {
                    DEFAULT: '#000080',
                    light: '#1a1a99',
                    dark: '#000066',
                },
                gold: {
                    DEFAULT: '#FFD700',
                    light: '#FFE44D',
                    dark: '#CCAC00',
                }
            }
        },
    },

    plugins: [forms, require('@tailwindcss/typography')],
};
