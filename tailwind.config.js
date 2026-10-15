/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: ['class', '.cf7sec-dark'],
    content: [
        './src/**/*.php',
        './assets/src/**/*.js',
    ],
    corePlugins: {
        // Never ship Tailwind's global reset into wp-admin.
        preflight: false,
    },
    theme: {
        extend: {
            colors: {
                border: 'hsl(var(--cf7sec-border))',
                input: 'hsl(var(--cf7sec-input))',
                ring: 'hsl(var(--cf7sec-ring))',
                background: 'hsl(var(--cf7sec-background))',
                foreground: 'hsl(var(--cf7sec-foreground))',
                primary: {
                    DEFAULT: 'hsl(var(--cf7sec-primary))',
                    foreground: 'hsl(var(--cf7sec-primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--cf7sec-secondary))',
                    foreground: 'hsl(var(--cf7sec-secondary-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--cf7sec-destructive))',
                    foreground: 'hsl(var(--cf7sec-destructive-foreground))',
                },
                success: {
                    DEFAULT: 'hsl(var(--cf7sec-success))',
                    foreground: 'hsl(var(--cf7sec-success-foreground))',
                },
                warning: {
                    DEFAULT: 'hsl(var(--cf7sec-warning))',
                    foreground: 'hsl(var(--cf7sec-warning-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--cf7sec-muted))',
                    foreground: 'hsl(var(--cf7sec-muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--cf7sec-accent))',
                    foreground: 'hsl(var(--cf7sec-accent-foreground))',
                },
                card: {
                    DEFAULT: 'hsl(var(--cf7sec-card))',
                    foreground: 'hsl(var(--cf7sec-card-foreground))',
                },
            },
            borderRadius: {
                lg: 'var(--cf7sec-radius)',
                md: 'calc(var(--cf7sec-radius) - 2px)',
                sm: 'calc(var(--cf7sec-radius) - 4px)',
            },
            fontFamily: {
                sans: ['var(--cf7sec-font-sans)'],
                mono: ['var(--cf7sec-font-mono)'],
            },
        },
    },
    plugins: [],
};
