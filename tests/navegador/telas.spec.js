// Cada tela do menu abre pelo próprio menu, com status 200, marcada como a aba atual e sem erro de JavaScript
// (a vigia de erros está em fixtures.js).
import { test, expect, TELAS } from './fixtures.js';

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
