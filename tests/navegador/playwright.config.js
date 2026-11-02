// Testes de navegador: npm test (todos), npm test -- --project=chromium (um navegador), npx playwright show-report
// (o relatório da última vez). Cada processo de teste sobe o próprio servidor, então os testes rodam em paralelo.
import { defineConfig, devices } from '@playwright/test';

const ci = !!process.env.CI;

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.js',
    fullyParallel: true,
    timeout: 30_000,
    expect: { timeout: 5_000 },
    forbidOnly: ci,
    retries: ci ? 1 : 0,
    workers: ci ? 2 : undefined,
    reporter: ci ? [['github'], ['list'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'never' }]],
    use: {
        // sem escolha na conta, o app segue o idioma do navegador
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
        trace: ci ? 'on-first-retry' : 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        // a varredura do axe fica só no Chromium: o resultado quase não depende do navegador, e o tempo do CI conta
        { name: 'firefox', use: { ...devices['Desktop Firefox'] }, testIgnore: 'acessibilidade.spec.js' },
    ],
});
