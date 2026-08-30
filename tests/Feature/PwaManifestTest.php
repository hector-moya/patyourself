<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the two things that must not go wrong with the PWA: the service
 * worker caching a document, and the app shell losing the link that makes it
 * installable at all. `public/sw.js` is a gitignored build artifact, so the
 * first test skips rather than fails on a checkout that has not run
 * `npm run build` — the suite must stay green everywhere.
 */
class PwaManifestTest extends TestCase
{
    public function test_the_service_worker_never_precaches_documents(): void
    {
        $sw = public_path('sw.js');

        if (! file_exists($sw)) {
            $this->markTestSkipped('Run `npm run build` before this test.');
        }

        $contents = file_get_contents($sw);

        // The failure this guards is silent: a cached document serves a stale CSRF
        // token and the user sees random 419s that look like being logged out.
        $this->assertNoDocumentUrlsInPrecacheManifest($contents);
        $this->assertStringContainsString('NetworkOnly', $contents);
    }

    public function test_the_app_shell_references_the_manifest(): void
    {
        // Plain Blade markup, not a Vite-built asset — but @vite() still runs
        // on the same page and would throw ViteException on an unbuilt
        // checkout without this, breaking the "stay green everywhere"
        // invariant this file exists to uphold.
        $this->withoutVite();

        // The link is load-bearing: without it there is no install prompt at
        // all, regardless of how correct the manifest or worker are.
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('<link rel="manifest" href="/build/manifest.webmanifest">', false);
    }

    /**
     * Workbox's generated precache manifest is a JS array literal embedded in
     * the minified service worker, not JSON — whether its keys are quoted,
     * and their order, isn't guaranteed across workbox versions or build
     * options. Pulling every `url` value out with a regex is tolerant of
     * that; asserting each one *resolves to a real file on disk* is the
     * strong version of the check — stronger than testing the string shape
     * (a prefix or an extension), because it also catches the build silently
     * producing a broken reference rather than a document. That's not
     * hypothetical: the manifest's own precache entry is added through a
     * workbox-build code path that `modifyURLPrefix` never sees, so
     * `vite.config.ts` patches its prefix by hand — a plain string replace
     * that would no-op silently if a future workbox/rollup bump changes how
     * that one entry is quoted or ordered, leaving `/manifest.webmanifest`
     * (unprefixed, 404) in the precache list. `assertFileExists` catches
     * that the moment it happens, rather than only catching this one
     * instance of it.
     */
    private function assertNoDocumentUrlsInPrecacheManifest(string $contents): void
    {
        preg_match_all('/"?url"?\s*:\s*"([^"]*)"/', $contents, $matches);

        $urls = $matches[1];

        $this->assertNotEmpty($urls, 'Expected to find at least one precached entry in the service worker.');

        foreach ($urls as $url) {
            $this->assertFileExists(
                public_path(ltrim($url, '/')),
                "Precached URL [{$url}] does not resolve to a real file on disk — it may be a document (which must never be cached) or a broken prefix."
            );
        }
    }
}
