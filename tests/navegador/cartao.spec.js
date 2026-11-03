// Cartão: no Lançar com "Pagou com: cartão", só as categorias que o servidor aceita numa compra no cartão (os atalhos
// e a lista); no editar da compra, a data nova leva a 1ª parcela para a fatura dela, até a pessoa escolher a fatura à
// mão. Tem servidor e banco só seus: o lançamento da Fatura muda as "usadas por último" dos outros testes.
import { test, expect, entrar, escolherCategoria } from './fixtures.js';
import { subirServidor } from './servidor.js';

let srv;
test.beforeAll(async () => {
    srv = await subirServidor();
});
test.afterAll(async () => {
    await srv?.parar();
});
test.use({ logado: false });

test('no cartão, o Lançar só oferece as categorias de compra', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    // a Fatura do cartão paga pela conta fica entre as usadas por último
    await page.goto(srv.url + '/');
    await page.keyboard.press('n');
    let dialogo = page.getByRole('dialog', { name: 'lançar' });
    await dialogo.getByRole('textbox', { name: 'valor' }).pressSequentially('77');
    await escolherCategoria(dialogo, 'Fatura');
    await dialogo.getByLabel('descrição', { exact: true }).fill('Fatura do teste');
    await dialogo.getByRole('button', { name: 'Lançar' }).click();
    await expect(page.getByRole('status').filter({ hasText: '77,00' })).toBeVisible();

    await page.goto(srv.url + '/');
    await page.keyboard.press('n');
    dialogo = page.getByRole('dialog', { name: 'lançar' });
    const recentesFora = dialogo.locator('[data-lf-recente]:not([data-lf-cartao])');
    await expect(recentesFora.first()).toBeVisible();              // com a conta, a Fatura está nos atalhos
    await recentesFora.first().click();                              // e fica escolhida
    const campo = dialogo.getByRole('combobox', { name: 'Categoria' });
    await expect(campo).not.toHaveValue('');

    await dialogo.locator('.ct-pagou').getByText('Cartão Exemplo').click();
    await expect(recentesFora.first()).toBeHidden();               // no cartão, o atalho some
    await expect(campo).toHaveValue('');                           // e a escolhida, que o cartão não aceita, sai
    await campo.click();
    const lista = dialogo.getByRole('listbox', { name: 'Categoria' });
    await expect(lista.getByRole('option', { name: 'Farmácia', exact: true })).toBeVisible();
    await expect(lista.getByRole('option', { name: 'Fatura', exact: true })).toBeHidden();
    await expect(lista.getByRole('option', { name: 'Salário', exact: true })).toBeHidden();
    expect(await lista.locator('[role="option"]:not([data-lf-cartao])').evaluateAll((ops) => ops.filter((o) => !o.hidden).length)).toBe(0);

    // de volta à conta, tudo volta
    await campo.press('Escape');
    await dialogo.locator('.ct-pagou').getByText('Conta', { exact: true }).click();
    await expect(recentesFora.first()).toBeVisible();
    expect(srv.logDoApp(), 'avisos ou erros do PHP').toBe('');
});

test('editar a data da compra leva a 1ª parcela para a fatura dela, até escolher a fatura à mão', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    await page.goto(srv.url + '/cartao');
    const editar = page.locator('a[href^="/cartao/compras/"][href$="/editar"]').first();
    await page.goto(srv.url + (await editar.getAttribute('href')));
    const data = page.getByLabel('Data da compra');
    const fatura = page.getByLabel('Fatura da 1ª parcela');
    // o Cartão Exemplo fecha no dia 25 e vence no dia 5: 24/11 cai na fatura de dez, 26/11 na de jan
    await data.fill('2026-11-26');
    await expect(fatura).toHaveValue('2027-01-01');
    await data.fill('2026-11-24');
    await expect(fatura).toHaveValue('2026-12-01');
    await fatura.selectOption('2027-02-01');                       // escolhida à mão: a data não mexe mais
    await data.fill('2026-11-26');
    await expect(fatura).toHaveValue('2027-02-01');
    expect(srv.logDoApp(), 'avisos ou erros do PHP').toBe('');
});
