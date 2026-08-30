import fs from 'node:fs';
import path from 'node:path';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, type Plugin } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * Moves the built service worker (and its workbox runtime chunk) out of
 * `public/build/` and onto the web root, after the rest of the build has
 * finished writing.
 *
 * The worker has to be served from `/`: a service worker's control scope
 * defaults to the directory it's registered from, and every Inertia page
 * lives outside `/build/`. Registering `/build/sw.js` would leave the worker
 * precaching assets it can never actually serve from cache, because it would
 * never control the pages that request them.
 *
 * The workbox runtime chunk (`workbox-<hash>.js`) moves alongside it rather
 * than being inlined: `sw.js` loads it via a *relative* import, so as long as
 * both files move together the import keeps resolving. Keeping it a separate,
 * unminified-by-us Workbox build (rather than `inlineWorkboxRuntime: true`,
 * which folds it into `sw.js` and lets the bundler rename its classes) is
 * also what keeps the literal string `NetworkOnly` present in the shipped
 * file — the guard test greps for it.
 *
 * vite-plugin-pwa also adds the manifest's own precache entry
 * (`manifest.webmanifest`) through a code path that `modifyURLPrefix` never
 * sees (it's appended after that transform runs), so it's the one entry this
 * still has to patch by hand once the rest have been prefixed with `/build/`.
 *
 * `order: 'post'` runs this after vite-plugin-pwa's own `closeBundle` hook —
 * Rollup resolves ordered hooks in separate rounds (default, then `post`),
 * so this is guaranteed to see the files vite-plugin-pwa just wrote rather
 * than racing it.
 */
function relocateServiceWorkerToWebRoot(): Plugin {
    let root = '';
    let outDir = '';

    return {
        name: 'patyourself:relocate-service-worker',
        configResolved(config) {
            root = config.root;
            outDir = config.build.outDir;
        },
        closeBundle: {
            order: 'post',
            handler() {
                const buildDir = path.resolve(root, outDir);
                const publicDir = path.resolve(root, 'public');
                const swFrom = path.join(buildDir, 'sw.js');

                if (!fs.existsSync(swFrom)) {
                    return;
                }

                const contents = fs
                    .readFileSync(swFrom, 'utf8')
                    .replace('url:"manifest.webmanifest"', 'url:"/build/manifest.webmanifest"');
                fs.writeFileSync(path.join(publicDir, 'sw.js'), contents);
                fs.unlinkSync(swFrom);

                for (const entry of fs.readdirSync(buildDir)) {
                    if (/^workbox-[\w-]+\.js$/.test(entry)) {
                        fs.renameSync(path.join(buildDir, entry), path.join(publicDir, entry));
                    }
                }
            },
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        VitePWA({
            registerType: 'autoUpdate',
            outDir: 'public/build',
            manifest: {
                name: 'PatYourSelf',
                short_name: 'PatYourSelf',
                start_url: '/dashboard',
                scope: '/',
                display: 'standalone',
                background_color: '#ffffff',
                theme_color: '#ffffff',
                icons: [
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
            workbox: {
                // Assets only. Documents are NetworkOnly on purpose: caching an
                // Inertia HTML response serves a stale CSRF token, and the 419s
                // that follow look like random logouts. Do not "optimise" this.
                globPatterns: ['**/*.{js,css,woff2,png,svg}'],
                navigateFallback: null,
                // The worker itself moves to the web root (see
                // relocateServiceWorkerToWebRoot below), but the assets it
                // precaches still live under Vite's `/build/` base. Prefixing
                // every entry with `/build/` means the precached URLs stay
                // correct regardless of where the worker itself is served
                // from.
                modifyURLPrefix: {
                    '': '/build/',
                },
                runtimeCaching: [
                    {
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkOnly',
                    },
                ],
            },
        }),
        relocateServiceWorkerToWebRoot(),
    ],
});
