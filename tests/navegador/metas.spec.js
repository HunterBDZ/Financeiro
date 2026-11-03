// Metas: mudar a meta só de um mês, pelo editor da própria linha, e ver o valor no Mês. Os outros meses não mudam.
import { test, expect, contaSemPagamento } from './fixtures.js';

test('meta só para um mês aparece no Mês e não muda o mês seguinte', async ({ page }) => {
    await page.goto('/meta?mes=2026-12-01');
    const linha = page.locator('details.mt-linha').filter({ has: page.locator('.mt-nome', { hasText: /^Mercado$/ }) });
    await linha.locator('summary').click();
    await linha.getByLabel('Valor por mês').fill('777');
    await expect(linha.getByLabel('A partir de')).toHaveValue('2026-12-01');
    await linha.getByLabel('Até').selectOption('so');
    await linha.getByRole('button', { name: 'Salvar' }).click();

    await expect(page.locator('[data-toast]')).toContainText('dez/26');
    await expect(page.locator('[data-toast]').getByRole('button', { name: 'desfazer' })).toBeVisible();
    await expect(linha.locator('.mt-c-meta')).toHaveText('meta 777,00');
    await expect(linha.locator('.mt-proprio')).toHaveText('só em dezembro, a meta é 1.500,00');

    await page.goto('/mes?mes=2026-12-01');
    await expect(page.locator('#saidas')).toContainText('Mercado');
    await expect(page.locator('#saidas')).toContainText('777,00');

    await page.goto('/meta?mes=2027-01-01');
    await expect(linha.locator('.mt-c-meta')).toHaveText('meta 1.500,00');
    await expect(linha.locator('.mt-proprio')).toHaveCount(0);

    // "Até" não oferece mês antes de "A partir de"
    await linha.locator('summary').click();
    await expect(linha.getByLabel('Até').locator('option[value="2026-12-01"]')).toHaveAttribute('disabled', '');
    await linha.getByLabel('A partir de').selectOption('2026-11-01');
    await expect(linha.getByLabel('Até').locator('option[value="2026-12-01"]')).not.toHaveAttribute('disabled');
});

// "Para usar neste mês" desconta as contas fixas que ainda faltam pagar, e a conta aparece ao lado; a barra de "% livre" saiu
test('"Para usar neste mês" desconta as contas fixas que faltam pagar', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Luz');
    await page.goto('/meta');
    const conta = page.getByRole('definition').filter({ hasText: /238,40/ });
    await expect(page.locator('.mt-conta-dl')).toContainText('Contas fixas a pagar');
    await expect(conta.first()).toHaveText('−238,40');
    const pg = page.locator('.mt');
    const [renda, gasto, porPagar] = await Promise.all(['data-renda', 'data-gasto-total', 'data-por-pagar'].map((a) => pg.getAttribute(a).then(Number)));
    expect(porPagar).toBe(238.4);
    const esperado = (renda - gasto - porPagar).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    await expect(page.locator('[data-para-usar]')).toHaveText(new RegExp('R\\$\\s?' + esperado.replace('.', '\\.') + '$'));
    await expect(page.locator('[data-meta-aloc]')).toHaveCount(0);
    // na linha da conta, o que falta é o mesmo
    await expect(page.locator('details.mt-linha').filter({ has: page.locator('.mt-nome', { hasText: /^Luz$/ }) }).locator('.mt-c-falta')).toHaveText('falta 238,40');
});
