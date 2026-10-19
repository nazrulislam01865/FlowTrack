import defaultTheme from 'tailwindcss/defaultTheme';

export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './app/View/Components/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Oswald', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                brand: {
                    navy: '#15345d',
                    dark: '#0d2545',
                    blue: '#2467b7',
                    red: '#e91d33',
                    redDark: '#c9182b',
                    ink: '#111827',
                    muted: '#64748b',
                    soft: '#f4f6f8',
                },
            },

            boxShadow: {
                soft: '0 8px 22px rgba(15,23,42,.08)',
                card: '0 6px 18px rgba(15,23,42,.05)',
                hero: '0 14px 36px rgba(15,23,42,.10)',
            },
        },
    },

    plugins: [],
};
