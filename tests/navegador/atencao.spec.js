// "Precisa de atenção", no Início: sem o pagamento da Internet de novembro (vence dia 12), no dia 22/11 do relógio
// dos testes a conta está atrasada, a faixa aparece logo abaixo do saldo e a ação abre o lançar já com a conta e o valor.
// Tem servidor e banco só seus: apagar um lançamento mudaria as telas dos outros testes do mesmo processo.
import { test, expect, entrar } from './fixtures.js';
import { subirServidor } from './servidor.js';

let srv;
test.beforeAll(async () => {
    srv = await subirServidor();
});
test.afterAll(async () => {
    await srv?.parar();
});
test.use({ logado: false });

test('conta atrasada aparece na faixa e a ação leva ao lançar, no computador e no celular', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    const ir = (caminho) => page.goto(srv.url + caminho);

    // tudo em dia na demonstração: nenhuma conta atrasada na faixa
    await ir('/');
    await expect(page.locator('[data-aviso="conta_atrasada"]')).toHaveCount(0);

    // apaga o pagamento da Internet de novembro
    await ir('/lancamentos?mes=2026-11-01&busca=Internet');
    await page.getByRole('link', { name: /Internet/ }).first().click();
    await page.getByRole('button', { name: 'Excluir lançamento' }).click();
    await expect(page.getByRole('status')).toBeVisible();

    for (const tela of [{ width: 1280, height: 900 }, { width: 375, height: 812 }]) {
        await page.setViewportSize(tela);
        await ir('/');
        const faixa = page.getByRole('region', { name: 'Precisa de atenção' });
        await expect(faixa).toBeVisible();
        const aviso = faixa.locator('[data-aviso="conta_atrasada"]');
        await expect(aviso).toContainText('Internet venceu 12/11');
        await expect(aviso).toContainText('sem pagamento lançado');

        // a faixa fica logo abaixo do saldo e cabe na tela, sem rolar para o lado
        const saldo = await page.locator('section.saldo').boundingBox();
        const caixa = await faixa.boundingBox();
        expect(caixa.y).toBeGreaterThan(saldo.y + saldo.height - 1);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

        // a ação abre o lançar com a categoria e o valor que falta
        await aviso.getByRole('link', { name: /^Lançar/ }).click();
        await expect(page).toHaveURL(/\/lancar\?/);
        const url = new URL(page.url());
        expect(url.searchParams.get('valor')).toBe('119,90');
        await expect(page.getByRole('textbox', { name: 'valor' })).toHaveValue('119,90');
        await expect(page.locator('select[name="categoria_id"]')).toHaveValue(url.searchParams.get('categoria'));
    }
});
