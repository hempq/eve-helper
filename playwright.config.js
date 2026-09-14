import { defineConfig } from '@playwright/test';

/**
 * E2E specs against the running standalone stack (deploy/dc up). Auth goes
 * through the /dev/login/{characterId} bypass, so the stack must run with
 * EVE_ALLOW_DEV_LOGIN=true and the character must already be synced.
 */
export default defineConfig({
    testDir: './tests/playwright',
    fullyParallel: true,
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
        ignoreHTTPSErrors: true, // local mkcert certificate
    },
    reporter: [['list']],
});
