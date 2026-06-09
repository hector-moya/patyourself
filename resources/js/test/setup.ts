import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// jsdom has no layout engine, so scrollIntoView is undefined; the chat thread
// calls it on every new message. Stub it so component renders don't throw.
if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = vi.fn();
}

// Unmount React trees between tests so queries never see a previous render.
afterEach(() => {
    cleanup();
});
