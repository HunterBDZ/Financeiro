// Metas: mudar a meta só de um mês, pelo editor da própria linha, e ver o valor no Mês. Os outros meses não mudam.
import { test, expect } from './fixtures.js';

test('meta só para um mês aparece no Mês e não muda o mês seguinte', async ({ page }) => {
    await page.goto('/meta?mes=2026-12-01');
    const linha = page.locator('details.meta-cat').filter({ has: page.locator('.mes-nome', { hasText: /^Mercado$/ }) });
    await linha.locator('summary').click();
    await linha.getByLabel('Valor por mês').fill('777');
    await expect(linha.getByLabel('A partir de')).toHaveValue('2026-12-01');
    await linha.getByLabel('Até').selectOption('so');
    await linha.getByRole('button', { name: 'Salvar' }).click();

    await expect(page.locator('[data-toast]')).toContainText('dez/26');
    await expect(page.locator('[data-toast]').getByRole('button', { name: 'desfazer' })).toBeVisible();
    await expect(linha.locator('.mes-val')).toHaveText('777,00');
    await expect(linha.locator('.meta-proprio')).toHaveText('só em dezembro · meta 1.500,00');

    await page.goto('/mes?mes=2026-12-01');
    // no livro do Mês, o previsto do Mercado em dezembro
    await expect(page.locator('.mes-livro tr', { hasText: 'Mercado' }).locator('td.c-prev')).toContainText('−777,00');

    await page.goto('/meta?mes=2027-01-01');
    await expect(linha.locator('.mes-val')).toHaveText('1.500,00');
    await expect(linha.locator('.meta-proprio')).toHaveCount(0);

    // "Até" não oferece mês antes de "A partir de"
    await linha.locator('summary').click();
    await expect(linha.getByLabel('Até').locator('option[value="2026-12-01"]')).toHaveAttribute('disabled', '');
    await linha.getByLabel('A partir de').selectOption('2026-11-01');
    await expect(linha.getByLabel('Até').locator('option[value="2026-12-01"]')).not.toHaveAttribute('disabled');
});
