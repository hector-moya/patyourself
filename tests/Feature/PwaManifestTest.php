<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the one thing that must not go wrong with the service worker:
 * caching a document. `public/build` is gitignored, so this skips rather than
 * fails on a checkout that has not run `npm run build` — the suite must stay
 * green everywhere.
 */
class PwaManifestTest extends TestCase
{
    public function test_the_service_worker_never_precaches_documents(): void
    {
        $sw = base_path('public/build/sw.js');

        if (! file_exists($sw)) {
            $this->markTestSkipped('Run `npm run build` before this test.');
        }

        $contents = file_get_contents($sw);

        // The failure this guards is silent: a cached document serves a stale CSRF
        // token and the user sees random 419s that look like being logged out.
        $this->assertStringNotContainsString('"revision":null,"url":"/"', $contents);
        $this->assertStringContainsString('NetworkOnly', $contents);
    }
}
