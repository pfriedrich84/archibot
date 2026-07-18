import { cleanup } from '@testing-library/svelte';
import { afterEach, vi } from 'vitest';

HTMLFormElement.prototype.requestSubmit = function requestSubmit() {
    this.dispatchEvent(
        new SubmitEvent('submit', { bubbles: true, cancelable: true }),
    );
};

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
