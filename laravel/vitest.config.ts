import path from 'node:path';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [svelte()],
    resolve: {
        conditions: ['browser'],
        alias: [
            {
                find: /^@\/routes(?:\/.*)?$/,
                replacement: path.resolve('tests/frontend/mocks/routes.ts'),
            },
            {
                find: '@inertiajs/svelte',
                replacement: path.resolve('tests/frontend/mocks/inertia.ts'),
            },
            { find: '@', replacement: path.resolve('resources/js') },
        ],
    },
    test: {
        environment: 'jsdom',
        include: ['tests/frontend/**/*.test.ts'],
        setupFiles: ['tests/frontend/setup.ts'],
        restoreMocks: true,
    },
});
