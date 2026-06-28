import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// jsdom has no layout engine, so scrollIntoView is undefined; the chat thread
// calls it on every new message. Stub it so component renders don't throw.
if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = vi.fn();
}

// jsdom doesn't implement matchMedia; the responsive `use-mobile` hook (pulled in
// by the starter-kit sidebar) reads it at import time. Stub a never-matching
// query so those modules load under test.
if (!window.matchMedia) {
    window.matchMedia = (query: string): MediaQueryList =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }) as unknown as MediaQueryList;
}

// Unmount React trees between tests so queries never see a previous render.
afterEach(() => {
    cleanup();
});
