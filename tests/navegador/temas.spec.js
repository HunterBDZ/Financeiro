// Temas: cada paleta em claro, escuro ou automático. Escolher mostra antes de salvar; o salvo vale em todas as telas e
// em outro aparelho; o automático de uma paleta segue o aparelho; a casca não tem mais atalho de tema.
import { test, expect, escolherTema } from './fixtures.js';

test.afterEach(async ({ page, baseURL }) => {
    await escolherTema(page, baseURL, 'auto');
});

test('escolher mostra antes de salvar, Voltar desfaz e Salvar vale nas outras telas', async ({ page }) => {
    const html = page.locator('html');
    await page.goto('/temas');
    const azulejo = page.getByRole('group', { name: 'Azulejo' });
    await azulejo.getByRole('button', { name: 'Escuro' }).click();
    await expect(html).toHaveAttribute('data-tema', 'azulejoescuro');      // mostra antes de salvar
    await expect(html).toHaveAttribute('data-theme', 'dark');
    await page.getByRole('button', { name: 'Voltar' }).click();
    await expect(html).not.toHaveAttribute('data-tema', /.+/);

    await azulejo.getByRole('button', { name: 'Escuro' }).click();
    await page.getByRole('button', { name: 'Salvar tema' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Tema salvo' })).toContainText('Azulejo, escuro');
    await expect(azulejo.getByRole('button', { name: 'Escuro' })).toHaveAttribute('aria-current', 'true');

    await page.getByRole('navigation', { name: 'principal' }).getByRole('link', { name: 'Início' }).click();
    await expect(page).toHaveURL('/');
    await expect(html).toHaveAttribute('data-tema', 'azulejoescuro');
    // o fundo veio do tema, e a cor de saída é a do app
    const cores = await page.evaluate(() => {
        const s = getComputedStyle(document.documentElement);
        return [s.getPropertyValue('--fundo').trim(), s.getPropertyValue('--saida').trim()];
    });
    expect(cores).toEqual(['#0B1630', '#F2877F']);
    await expect(page.getByRole('group', { name: 'tema' })).toHaveCount(0);   // sem atalho de tema na casca
});

test.describe('o automático de uma paleta segue o aparelho', () => {
    for (const [esquema, fundo] of [['light', '#F4F4F1'], ['dark', '#121211']]) {
        test.describe(esquema, () => {
            test.use({ colorScheme: esquema });

            test(`aparelho ${esquema}`, async ({ page, baseURL }) => {
                await escolherTema(page, baseURL, 'calcadaoauto');
                await page.goto('/mes');
                const html = page.locator('html');
                await expect(html).toHaveAttribute('data-tema', 'calcadaoauto');
                await expect(html).not.toHaveAttribute('data-theme', /.+/);
                expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--fundo').trim())).toBe(fundo);
            });
        });
    }
});

test('o tema da conta no automático apaga a cópia guardada neste aparelho', async ({ page, baseURL }) => {
    await page.goto('/');
    await page.evaluate(() => localStorage.setItem('tema', 'gruvd:dark'));     // um tema que saiu da lista
    await page.reload();
    await expect(page.locator('html')).not.toHaveAttribute('data-tema', /.+/);
    expect(await page.evaluate(() => localStorage.getItem('tema'))).toBeNull();
    await escolherTema(page, baseURL, 'papel');
    await page.reload();
    expect(await page.evaluate(() => localStorage.getItem('tema'))).toBe('papel:light');
});
