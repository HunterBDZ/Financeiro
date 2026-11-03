// Cada tela do menu abre pelo próprio menu, com status 200, marcada como a aba atual e sem erro de JavaScript
// (a vigia de erros está em fixtures.js).
import { test, expect, TELAS, TELAS_AJUSTES } from './fixtures.js';

for (const [nome, caminho] of TELAS) {
    test(`${nome} abre pelo menu`, async ({ page }) => {
        // começa numa tela que não é a do teste, para o clique sempre trocar de página
        await page.goto(caminho === '/ajuda' ? '/' : '/ajuda');
        const link = page.getByRole('navigation', { name: 'principal' }).getByRole('link', { name: nome, exact: true });
        await expect(link).toHaveAttribute('href', caminho);

        const resposta = page.waitForResponse((r) => r.request().isNavigationRequest() && new URL(r.url()).pathname === caminho);
        await link.click();
        expect((await resposta).status()).toBe(200);
        await expect(page).toHaveURL(caminho);
        await expect(link).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('main')).toBeVisible();
        await page.waitForLoadState('load');
    });
}

// Temas e Ajuda saíram do menu: abrem pela lista de Ajustes, e lá a aba acesa é a de Ajustes
for (const [nome, caminho] of TELAS_AJUSTES) {
    test(`${nome} abre por Ajustes`, async ({ page }) => {
        await page.goto('/ajustes');
        await page.getByRole('main').getByRole('link', { name: new RegExp('^' + nome) }).click();
        await expect(page).toHaveURL(caminho);
        const menu = page.getByRole('navigation', { name: 'principal' });
        await expect(menu.getByRole('link', { name: nome, exact: true })).toHaveCount(0);
        await expect(menu.getByRole('link', { name: 'Ajustes', exact: true })).toHaveClass(/is-active/);
    });
}

test('a casca: a marca, o Lançar com a tecla N à vista e os destinos em 3 grupos', async ({ page }) => {
    await page.goto('/mes');
    const lateral = page.getByRole('complementary', { name: 'menu' });
    await expect(lateral.getByRole('link', { name: 'MyMonetto' })).toBeVisible();
    await expect(lateral.getByRole('link', { name: 'Lançar' })).toHaveAttribute('aria-keyshortcuts', 'n');
    await expect(lateral.locator('kbd')).toHaveText('N');
    await expect(page).toHaveTitle(/MyMonetto$/);
    // o espaço entre os grupos: Cartão → Metas e Simular → Empréstimo
    const topo = async (aba) => (await page.locator(`.lateral li[data-aba="${aba}"]`).boundingBox()).y;
    const passo = (await topo('extrato')) - (await topo('inicio'));
    expect((await topo('meta')) - (await topo('cartao'))).toBeGreaterThan(passo + 16);
    expect((await topo('emprestimo')) - (await topo('simular'))).toBeGreaterThan(passo + 16);
    expect((await topo('relatorio')) - (await topo('meta'))).toBe(passo);
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('a barra de baixo tem Início, Extrato, o +, Mês e Mais; sem barra de cima', async ({ page }) => {
        await page.goto('/');
        const barra = page.getByRole('navigation', { name: 'principal' });
        expect(await page.locator('.tabbar > a').evaluateAll((l) => l.map((a) => a.getAttribute('href').split('?')[0]))).toEqual(['/', '/lancamentos', '/lancar', '/mes']);
        await expect(page.getByRole('banner')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'trocar tema' })).toHaveCount(0);
        await barra.getByText('Mais', { exact: true }).tap();
        await barra.getByRole('link', { name: 'Cartão' }).tap();
        await expect(page).toHaveURL('/cartao');
        await expect(page.locator('.tab-mais > summary')).toHaveClass(/is-active/);
    });
});
