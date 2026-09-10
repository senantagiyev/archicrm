import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    timeout: 45_000,
    use: {
        baseURL: 'http://archicrm.test',
        headless: true,
        browserName: 'chromium',
        launchOptions: {
            executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
        },
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
});
