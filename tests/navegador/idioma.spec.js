// Idioma: trocar em Ajustes muda os textos do menu na hora, e dá para voltar ao português.
import { test, expect } from './fixtures.js';

test('troca para inglês e volta para português', async ({ page }) => {
    await page.goto('/ajustes');
    await page.getByText('Idioma', { exact: true }).click();          // a linha abre ali mesmo
    await page.getByRole('form', { name: 'Idioma' }).getByRole('button', { name: 'English' }).click();

    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('status').filter({ hasText: 'Language saved.' })).toBeVisible();
    const menu = page.getByRole('navigation', { name: 'main' });
    await expect(menu.getByRole('link')).toHaveText(['Home', 'Activity', 'Month', 'Card', 'Goals', 'Report', 'Simulate', 'Loan', 'Investments', 'Wishlist', 'Settings']);

    await page.getByRole('form', { name: 'Language' }).getByRole('button', { name: 'Português' }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'pt-BR');
    await expect(page.getByRole('navigation', { name: 'principal' }).getByRole('link', { name: 'Início' })).toBeVisible();
});
