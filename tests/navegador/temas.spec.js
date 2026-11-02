// Temas: o escolhido vale em todas as telas, e o atalho claro/escuro troca só de lado dentro do mesmo tema.
// Antes da 1.5.1, o atalho voltava ao tema padrão.
import { test, expect, escolherTema } from './fixtures.js';

// o atalho grava o lado novo na conta sem trocar de página: a resposta do POST /temas
const gravacao = (page) => page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/temas');

test.afterEach(async ({ page, baseURL }) => {
    await escolherTema(page, baseURL, 'auto');
});

test('o atalho claro/escuro continua no tema escolhido', async ({ page }) => {
    const html = page.locator('html');
    await page.goto('/temas');
    await page.getByRole('button', { name: 'Gruvbox escuro' }).click();
    await expect(html).toHaveAttribute('data-tema', 'gruvd');     // mostra antes de salvar
    await page.getByRole('button', { name: 'Salvar tema' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Tema salvo' })).toContainText('Gruvbox escuro');

    await page.getByRole('navigation', { name: 'principal' }).getByRole('link', { name: 'Início' }).click();
    await expect(page).toHaveURL('/');
    await expect(html).toHaveAttribute('data-tema', 'gruvd');
    const atalho = page.getByRole('group', { name: 'tema' });
    await expect(atalho.getByRole('button', { name: 'Escuro' })).toHaveAttribute('aria-pressed', 'true');

    const gravou = gravacao(page);
    await atalho.getByRole('button', { name: 'Claro' }).click();
    expect((await gravou).status()).toBe(204);
    await expect(html).toHaveAttribute('data-tema', 'gruvl');
    await expect(html).toHaveAttribute('data-theme', 'light');

    // o lado claro ficou na conta: outra tela já abre nele, e a tela Temas marca o Gruvbox claro
    await page.getByRole('navigation', { name: 'principal' }).getByRole('link', { name: 'Temas' }).click();
    await expect(html).toHaveAttribute('data-tema', 'gruvl');
    await expect(page.getByRole('button', { name: 'Gruvbox claro' })).toHaveAttribute('aria-current', 'true');

    const voltou = gravacao(page);
    await page.getByRole('group', { name: 'tema' }).getByRole('button', { name: 'Escuro' }).click();
    expect((await voltou).status()).toBe(204);
    await page.reload();
    await expect(html).toHaveAttribute('data-tema', 'gruvd');
});

test('tema de um modo só mostra o nome no lugar do atalho', async ({ page, baseURL }) => {
    await escolherTema(page, baseURL, 'nord');
    await page.goto('/');
    const menu = page.getByRole('complementary', { name: 'menu' });
    await expect(menu.getByRole('group', { name: 'tema' })).toHaveCount(0);
    await menu.getByRole('link', { name: 'Nord' }).click();
    await expect(page).toHaveURL('/temas');
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('o botão de tema da barra de cima também fica no tema escolhido', async ({ page, baseURL }) => {
        await escolherTema(page, baseURL, 'soll');
        await page.goto('/');
        const html = page.locator('html');
        await expect(html).toHaveAttribute('data-tema', 'soll');

        const gravou = gravacao(page);
        await page.getByRole('banner').getByRole('button', { name: 'trocar tema' }).tap();
        expect((await gravou).status()).toBe(204);
        await expect(html).toHaveAttribute('data-tema', 'sold');
        await page.reload();
        await expect(html).toHaveAttribute('data-tema', 'sold');
        await expect(html).toHaveAttribute('data-theme', 'dark');
    });
});
