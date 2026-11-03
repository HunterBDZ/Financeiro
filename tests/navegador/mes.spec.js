// O Mês do redesenho: o livro-razão com a edição do previsto na própria linha, a barra do dinheiro do mês, o Fechar como
// botão de verdade e o mês fechado como outro estado. No relógio dos testes (22/11), novembro está aberto e outubro fechado.
import { test, expect, token } from './fixtures.js';

const lerNum = (t) => Number(t.replace(/[^\d,−-]/g, '').replace('−', '-').replace(/\./g, '').replace(',', '.'));

test('o previsto abre a edição na linha, salva e o desfazer volta', async ({ page }) => {
    await page.goto('/mes');
    const linha = page.locator('tr', { hasText: 'Mercado' });
    const antes = await linha.locator('td.c-prev summary .num').textContent();
    await linha.locator('details.mes-ed > summary').click();
    const campo = linha.getByRole('textbox', { name: /previsto em/ });
    await expect(campo).toBeFocused();
    await campo.fill('1.234,00');
    await linha.getByRole('button', { name: 'Salvar' }).click();

    const aviso = page.getByRole('status').filter({ hasText: 'Mercado' });
    await expect(aviso).toContainText('1.234,00');
    await expect(page.locator('tr', { hasText: 'Mercado' }).locator('td.c-prev summary .num')).toHaveText('−1.234,00');
    await aviso.getByRole('button', { name: 'desfazer' }).click();
    await expect(page.locator('tr', { hasText: 'Mercado' }).locator('td.c-prev summary .num')).toHaveText(antes);
});

test('a barra do dinheiro do mês: as cinco partes somam o total', async ({ page }) => {
    await page.goto('/mes');
    const destino = page.locator('[data-destino]');
    const total = Number(await destino.getAttribute('data-total'));
    const valores = await destino.locator('.legenda .val').allTextContents();
    expect(valores).toHaveLength(5);
    expect(Math.abs(valores.map(lerNum).reduce((a, b) => a + b, 0) - total)).toBeLessThan(0.006);
    await expect(destino.locator('.legenda .destaque')).toContainText('Dá para gastar');
});

test('Fechar é um botão: antes do fim do mês, desligado e com o motivo', async ({ page }) => {
    await page.goto('/mes');
    const fechar = page.getByRole('button', { name: 'Fechar novembro' });
    await expect(fechar).toHaveAttribute('aria-disabled', 'true');
    await expect(fechar).toHaveAccessibleDescription('a partir de 30 de novembro');
    await expect(fechar).toBeDisabled();
    await expect(page.getByRole('link', { name: 'Fechar novembro' })).toHaveCount(0);
});

test('o servidor também só fecha o mês a partir do dia que a tela promete (B-02)', async ({ page, baseURL }) => {
    const r = await page.request.post(baseURL + '/mes/fechar', { form: { csrf_token: await token(page.request, baseURL + '/ajuda'), mes: '2026-11-01' } });
    expect(await r.text()).toContain('Dá para fechar novembro a partir de 30 de novembro.');
    await page.goto('/mes');
    await expect(page.getByRole('button', { name: 'Fechar novembro' })).toBeDisabled();   // continua aberto
    await expect(page.locator('.topo-sub')).not.toContainText('Fechado');
});

test('o mês fechado é outro estado: planejado, realizado, diferença e o fio de fechamento', async ({ page }) => {
    await page.goto('/mes?mes=2026-10-01');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Outubro de 2026');
    await expect(page.locator('.topo-sub')).toContainText('Fechado');
    await expect(page.getByRole('columnheader', { name: 'Diferença' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Planejado' })).toBeVisible();
    await expect(page.locator('details.mes-ed')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Fechar/ })).toHaveCount(0);
    const fio = await page.locator('.mes-livro tfoot tr.total-final td').first().evaluate((el) => getComputedStyle(el).borderBottomWidth);
    expect(fio).toBe('2px');
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('o livro vira lista, sem rolar para o lado, e a edição abre na linha', async ({ page }) => {
        await page.goto('/mes');
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
        const linha = page.locator('tr', { hasText: 'Combustível' });
        await expect(linha.locator('td.c-prev')).toContainText('previsto');
        await linha.locator('details.mes-ed > summary').tap();
        await expect(linha.getByRole('button', { name: 'Salvar' })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    });
});
