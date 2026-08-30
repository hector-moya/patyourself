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
     * options (moving the worker off `/build/` alone changes both, since the
     * manifest entry it adds for itself bypasses the normal URL-prefixing
     * step). Pulling every `url` value out with a regex and checking its
     * shape — it must end in a real static asset extension — catches an
     * accidentally-precached document regardless of how workbox happens to
     * format the surrounding object.
     */
    private function assertNoDocumentUrlsInPrecacheManifest(string $contents): void
    {
        preg_match_all('/"?url"?\s*:\s*"([^"]*)"/', $contents, $matches);

        $urls = $matches[1];

        $this->assertNotEmpty($urls, 'Expected to find at least one precached entry in the service worker.');

        foreach ($urls as $url) {
            $this->assertMatchesRegularExpression(
                '/\.(js|css|woff2?|png|svg|webmanifest)$/i',
                $url,
                "Precached URL [{$url}] does not look like a static asset — it may be a document, which must never be cached."
            );
        }
    }
}
