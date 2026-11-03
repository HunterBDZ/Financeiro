// Entrar e sair pela tela de login.
import { test, expect, LOGIN, SENHA } from './fixtures.js';

test.use({ logado: false });

test('entra e sai', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL('/login');
    await page.getByLabel('Login').fill(LOGIN);
    await page.getByLabel('Senha').fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Ana' })).toBeVisible();

    await page.getByRole('button', { name: 'sair' }).click();
    await expect(page).toHaveURL('/login');
    await page.goto('/');      // a sessão acabou de verdade: volta ao login
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
});

test('senha errada mostra a mensagem', async ({ page, erros }) => {
    erros.permitir(/status of 401/);    // o Chromium registra no console a resposta 401 do servidor
    await page.goto('/login');
    await page.getByLabel('Login').fill(LOGIN);
    await page.getByLabel('Senha').fill('senha-errada');
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page.getByRole('alert')).toHaveText('Login ou senha incorretos.');
    await expect(page.getByLabel('Login')).toHaveValue(LOGIN);
    await expect(page.getByRole('button', { name: 'sair' })).toHaveCount(0);
});

test('fora dos buscadores, com favicon e a página de privacidade', async ({ page, request }) => {
    const robots = await request.get('/robots.txt');
    expect(robots.ok()).toBe(true);
    expect(await robots.text()).toMatch(/User-agent: \*\s+Disallow: \/$/m);
    const login = await request.get('/login');
    expect(login.headers()['x-robots-tag']).toBe('noindex, nofollow');
    const icone = await request.get('/favicon.ico');
    expect(icone.ok()).toBe(true);
    expect(icone.headers()['content-type']).toMatch(/^image\//);

    await page.goto('/login');
    await page.getByRole('link', { name: 'Privacidade e aviso' }).click();
    await expect(page).toHaveURL('/privacidade');
    await expect(page.getByRole('heading', { level: 1, name: 'Privacidade e aviso' })).toBeVisible();
    for (const secao of ['Seus dados', 'O que sai do computador', 'Cookies', 'Aviso', 'Contato']) {
        await expect(page.getByRole('heading', { level: 2, name: secao })).toBeVisible();
    }
    await page.getByRole('link', { name: 'Voltar para entrar' }).click();
    await expect(page).toHaveURL('/login');
});
