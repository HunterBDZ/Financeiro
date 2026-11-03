// O visual do cartão: o estilo escolhido aparece no cartão desenhado antes de salvar, fica depois de salvar, e a aba
// Cartão passa no axe com ele, nos temas Claro e Escuro. No fim, o cartão volta ao Padrão (o banco é do processo).
import AxeBuilder from '@axe-core/playwright';
import { test, expect, escolherTema } from './fixtures.js';

const graves = async (page) => (await new AxeBuilder({ page }).analyze()).violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);

/** Escolhe o visual como a pessoa faz: clica no nome debaixo da miniatura (o radio fica escondido atrás dela). */
async function escolher(form, nome) {
    await form.locator('.ct-vis-n').filter({ hasText: new RegExp(`^${nome}$`) }).click();
    await expect(form.getByRole('radio', { name: nome, exact: true })).toBeChecked();
}

/** Salva o formulário e espera a volta à aba (o aviso "Cartão salvo" some sozinho, então não serve de sinal). */
async function salvar(page, form) {
    const volta = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/cartao/config');
    await form.getByRole('button', { name: 'Salvar' }).click();
    expect((await volta).status()).toBe(303);
    await page.waitForURL(/\/cartao$/);
}

/** Abre o "Configurar" do cartão, escolhe o visual pelo nome e salva. */
async function salvarVisual(page, nome) {
    await page.goto('/cartao');
    const form = page.locator('#cartao-config');
    await page.locator('.ct-config > summary').click();
    await escolher(form, nome);
    await salvar(page, form);
}

test.slow();     // cada teste salva o cartão algumas vezes

test.afterEach(async ({ page, baseURL }) => {
    await salvarVisual(page, 'Padrão');
    await escolherTema(page, baseURL, 'auto');
});

test('o estilo escolhido aparece no cartão antes e depois de salvar', async ({ page }) => {
    await page.goto('/cartao');
    const cartao = page.locator('[data-ct-plastico]');
    await expect(cartao).not.toHaveAttribute('data-visual');
    await page.locator('.ct-config > summary').click();
    const form = page.locator('#cartao-config');
    await expect(form.getByRole('radio', { name: 'Padrão', exact: true })).toBeChecked();

    await escolher(form, 'Roxo');
    await expect(cartao).toHaveAttribute('data-visual', 'roxo');            // a prévia, ainda sem salvar
    await escolher(form, 'Grafite e dourado');
    await expect(cartao).toHaveAttribute('data-visual', 'grafite-dourado');
    await salvar(page, form);
    await expect(cartao).toHaveAttribute('data-visual', 'grafite-dourado');
    // o desenho é de verdade outro: a cor do texto do valor é a dourada do estilo
    await expect(cartao.locator('.ct-plastico-v')).toHaveCSS('color', 'rgb(240, 209, 138)');

    // cor própria: a cor escolhida vai para o cartão, com texto legível
    await page.locator('.ct-config > summary').click();
    await escolher(form, 'Cor própria');
    await form.getByLabel('Escolha a cor').fill('#ffd400');
    await expect(cartao).toHaveAttribute('data-visual', 'propria');
    await salvar(page, form);
    await expect(cartao).toHaveAttribute('style', /--ct-a:#ffd400;.*--ct-txt:#000000/);
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('o cartão cabe na tela, na proporção de um cartão', async ({ page }) => {
        await salvarVisual(page, 'Azul-marinho');
        const caixa = await page.locator('[data-ct-plastico]').boundingBox();
        expect(caixa.x >= 0 && caixa.x + caixa.width <= 390).toBe(true);
        expect(Math.abs(caixa.width / caixa.height - 85.6 / 53.98)).toBeLessThan(0.02);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    });
});

for (const tema of ['claro', 'escuro']) {
    test(`axe na aba Cartão com estilos, tema ${tema}`, async ({ page, baseURL }) => {
        test.setTimeout(240_000);     // o axe numa tela grande, duas vezes
        await escolherTema(page, baseURL, tema);
        // um claro e um escuro; o contraste de todos os estilos, tests/regras.php confere pela paleta
        for (const nome of ['Amarelo e azul', 'Grafite e dourado']) {
            await salvarVisual(page, nome);
            await page.locator('.ct-config > summary').click();      // o seletor aberto também passa
            expect(await graves(page), nome).toEqual([]);
        }
    });
}
