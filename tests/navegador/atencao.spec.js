// "Precisa de atenção" e "Contas do mês", no Início: sem o pagamento da Internet de novembro (vence dia 12), no dia 22/11 do
// relógio dos testes a conta está atrasada. Ela aparece só nas contas do mês, com o Pagar, e não se repete em "Precisa de
// atenção"; o nome abre o lançar já com a conta e o valor previsto.
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

test('conta atrasada fica só nas contas do mês, com o Pagar e o lançar com o valor previsto, no computador e no celular', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    const ir = (caminho) => page.goto(srv.url + caminho);

    // tudo em dia na demonstração: nenhuma conta atrasada na faixa
    await ir('/');
    await expect(page.locator('[data-aviso="conta_atrasada"]')).toHaveCount(0);

    // apaga o pagamento da Internet de novembro
    await ir('/lancamentos?mes=2026-11-01&busca=Internet');
    await page.locator('.ext-tabela').getByRole('link', { name: /Internet/ }).first().click();
    await page.getByRole('button', { name: 'Excluir lançamento' }).click();
    await expect(page.getByRole('status')).toBeVisible();

    for (const tela of [{ width: 1280, height: 900 }, { width: 375, height: 812 }]) {
        await page.setViewportSize(tela);
        await ir('/');
        // a conta atrasada não se repete na faixa de avisos
        await expect(page.locator('[data-aviso="conta_atrasada"]')).toHaveCount(0);
        const conta = page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Internet' });
        await expect(conta).toContainText('venceu dia 12');
        await expect(conta).toContainText('119,90');
        await expect(conta.getByRole('button', { name: /^Pagar/ })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

        // o nome abre o lançar com a categoria e o valor previsto sugerido
        await conta.getByRole('link', { name: /Internet/ }).click();
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        await expect(dialogo).toBeVisible();
        await expect(dialogo.getByRole('combobox', { name: 'Categoria' })).toHaveValue('Internet');
        await expect(dialogo.getByRole('textbox', { name: 'valor' })).toHaveValue('119,90');
        await page.keyboard.press('Escape');
    }
});
