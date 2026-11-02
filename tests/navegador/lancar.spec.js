// Lançar um gasto: no computador (a coluna do Extrato e o diálogo) e no celular (o "+" da barra de baixo).
// Cada teste usa valor e categoria só seus: lançamento igual no mesmo dia pede confirmação antes de entrar.
import { test, expect } from './fixtures.js';

test.describe('no computador', () => {
    test('lança 45 no Extrato, vê o aviso e desfaz', async ({ page }) => {
        await page.goto('/lancamentos');
        // no Extrato, o botão Lançar do menu leva ao formulário da coluna, sem abrir o diálogo
        await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: 'Lançar' }).click();
        const form = page.getByRole('complementary', { name: 'lançar' });
        const valor = form.getByRole('textbox', { name: 'valor' });
        await expect(valor).toBeFocused();

        await valor.pressSequentially('45');
        await valor.blur();
        await expect(valor).toHaveValue('45,00');      // o valor é lido como se fala: 45 é R$ 45,00
        await form.getByRole('group', { name: 'Categoria' }).getByText('Mercado', { exact: true }).first().click();   // categoria em um toque
        await form.getByLabel('descrição', { exact: true }).fill('Feira do teste');
        await form.getByRole('button', { name: 'Lançar' }).click();

        const aviso = page.getByRole('status').filter({ hasText: 'Mercado' });
        await expect(aviso).toContainText('45,00');
        const linha = page.getByRole('link', { name: /Feira do teste/ });
        await expect(linha).toContainText('45,00');

        await aviso.getByRole('button', { name: 'desfazer' }).click();
        await expect(page.getByRole('status').filter({ hasText: 'excluído' })).toContainText('45,00');
        await expect(linha).toHaveCount(0);
    });

    test('o diálogo de lançar abre pelo menu e pela tecla n, e fecha', async ({ page }) => {
        await page.goto('/');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        await expect(dialogo).toBeHidden();

        await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: 'Lançar' }).click();
        await expect(dialogo).toBeVisible();
        await expect(dialogo.getByRole('textbox', { name: 'valor' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialogo).toBeHidden();

        await page.keyboard.press('n');
        await expect(dialogo).toBeVisible();
        await dialogo.getByRole('button', { name: 'fechar' }).click();
        await expect(dialogo).toBeHidden();
    });
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('o "+" fica no meio da barra de baixo, abre o formulário e o lançamento entra', async ({ page }) => {
        await page.goto('/');
        const barra = page.getByRole('navigation', { name: 'principal' });
        const itens = barra.getByRole('link');
        await expect(itens).toHaveCount(5);
        const mais = itens.nth(2);
        await expect(mais).toHaveAccessibleName('lançar');
        const caixa = await mais.boundingBox();
        expect(Math.abs(caixa.x + caixa.width / 2 - 390 / 2)).toBeLessThan(8);

        await mais.tap();
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        await expect(dialogo).toBeVisible();
        await dialogo.getByRole('textbox', { name: 'valor' }).pressSequentially('37');
        await dialogo.getByRole('group', { name: 'Categoria' }).getByText('Lazer', { exact: true }).first().tap();
        await dialogo.getByLabel('descrição', { exact: true }).fill('Cinema do teste');
        await dialogo.getByRole('button', { name: 'Lançar' }).tap();

        await expect(dialogo).toBeHidden();
        await expect(page.getByRole('status').filter({ hasText: 'Lazer' })).toContainText('37,00');
        await barra.getByRole('link', { name: 'Extrato' }).tap();
        await expect(page.getByRole('link', { name: /Cinema do teste/ })).toContainText('37,00');
    });
});
