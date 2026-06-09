import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * Component-test config — kept separate from vite.config.ts so the build
 * pipeline (Laravel/Inertia/Tailwind/Wayfinder plugins) stays untouched. Mirrors
 * the `@/` path alias from tsconfig so component imports resolve in tests.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': resolve(__dirname, './resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
});
