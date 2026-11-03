// Simular: o gráfico é a peça principal, os rótulos do eixo não se tocam (no computador e no celular), "Ver e
// ajustar" é um ícone com nome que não quebra em duas linhas, e o "E se" mostra o plano comparado.
import { test, expect } from './fixtures.js';

/** Os rótulos de mês visíveis do eixo não se encostam: entre um e o próximo sobra pelo menos 4 px. */
async function rotulosSeparados(page) {
    return page.locator('.sim-mx').evaluateAll((els) => {
        const caixas = els.filter((e) => getComputedStyle(e).visibility !== 'hidden').map((e) => e.getBoundingClientRect()).sort((a, b) => a.left - b.left);
        return caixas.every((c, i) => i === 0 || c.left - caixas[i - 1].right >= 4) && caixas.length >= 3;
    });
}

test('o gráfico vem primeiro e os rótulos do eixo não se tocam', async ({ page }) => {
    await page.goto('/futuro');
    const faixas = page.locator('main .faixa-grade');
    await expect(faixas.nth(1)).toHaveClass(/sim-graf/);
    await expect(page.locator('#sim-graf-t')).toHaveText(/^A conta /);
    expect(await rotulosSeparados(page)).toBe(true);
    // o último rótulo fica dentro do gráfico
    const fig = await page.locator('.sim-fig').boundingBox();
    const ultimo = await page.locator('.sim-mx').last().boundingBox();
    expect(ultimo.x + ultimo.width).toBeLessThanOrEqual(fig.x + fig.width + 1);
});

test('"Ver e ajustar" é um ícone com nome, numa linha só', async ({ page }) => {
    await page.goto('/futuro');
    await page.locator('.sim-tabela > summary').click();
    const mes = page.locator('details.sim-mes.is-prev').first();
    await mes.locator('summary').click();
    const abrir = mes.getByRole('link', { name: /^Ver e ajustar / });
    await expect(abrir).toBeVisible();
    const caixa = await abrir.boundingBox();
    expect(caixa.height).toBeLessThanOrEqual(48);
});

test('"E se": a comparação com o plano e o aviso de que nada foi salvo', async ({ page }) => {
    await page.goto('/futuro?se=1&mensal_tipo=economizar&mensal=500');
    await expect(page.getByRole('status')).toContainText('Nada foi salvo');
    await expect(page.getByRole('table', { name: 'Plano comparado com a simulação' })).toBeVisible();
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('os rótulos do eixo não se tocam e nada rola para o lado', async ({ page }) => {
        await page.goto('/futuro');
        expect(await rotulosSeparados(page)).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    });
});
