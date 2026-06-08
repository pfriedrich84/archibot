import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

if (process.env.CI === 'true') {
    process.env.LARAVEL_BYPASS_ENV_CHECK = '1';
}

const generateWayfinder = process.env.WAYFINDER_GENERATE !== 'false';
const enableRefresh = process.env.CI !== 'true';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: enableRefresh,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        svelte(),
        generateWayfinder &&
            wayfinder({
                formVariants: true,
            }),
    ].filter(Boolean),
});
