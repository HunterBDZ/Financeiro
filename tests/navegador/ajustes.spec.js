// Ajustes com mais de uma pessoa: o que uma salva não some quando a outra salva por cima, e as regras de conta
// (senha, pessoas, categorias do app). Usa a ana (o contexto do teste) e o bruno (um contexto a mais) da
// demonstração; cada teste devolve o que mudou, porque os outros testes do mesmo processo usam os mesmos dados.
import { test, expect, entrar, SENHA } from './fixtures.js';

/** Um segundo navegador, com o bruno entrado. */
async function bruno(browser, baseURL) {
    const ctx = await browser.newContext({ baseURL });
    await entrar(ctx.request, baseURL, 'bruno', SENHA);
    return ctx;
}

test('duas pessoas em Reserva e meta: cada uma grava só o que mudou, e o mesmo campo avisa (A-04)', async ({ page, browser, baseURL }) => {
    const outro = await bruno(browser, baseURL);
    const pb = await outro.newPage();
    try {
        await page.goto('/ajustes?aberto=parametros');
        await pb.goto('/ajustes?aberto=parametros');
        const reserva = (p) => p.locator('#parametros').getByLabel('Reserva mínima');
        const meta = (p) => p.locator('#parametros').getByLabel('Meta de conta e poupança');
        const salvar = (p) => p.locator('#parametros').getByRole('button', { name: 'Salvar' }).click();

        await reserva(page).fill('2.000,00');
        await salvar(page);
        await expect(page.getByText('Ajustes salvos.')).toBeVisible();
        await meta(pb).fill('30.000,00');                       // a página do bruno ainda mostra a reserva antiga
        await salvar(pb);
        await expect(pb.getByText('Ajustes salvos.')).toBeVisible();
        await page.goto('/ajustes?aberto=parametros');
        await expect(reserva(page)).toHaveValue('2.000,00');
        await expect(meta(page)).toHaveValue('30.000,00');

        // os dois mudam o mesmo campo: o segundo é avisado, e nada dele é gravado
        await pb.goto('/ajustes?aberto=parametros');
        await reserva(page).fill('2.100,00');
        await salvar(page);
        await reserva(pb).fill('2.200,00');
        await meta(pb).fill('31.000,00');
        await salvar(pb);
        await expect(pb.locator('#parametros')).toContainText('Outra pessoa mudou isto agora. Confira e salve de novo.');
        await expect(reserva(pb)).toHaveValue('2.100,00');
        await expect(meta(pb)).toHaveValue('30.000,00');
    } finally {
        await page.goto('/ajustes?aberto=parametros');
        await page.locator('#parametros').getByLabel('Reserva mínima').fill('1.500,00');
        await page.locator('#parametros').getByLabel('Meta de conta e poupança').fill('25.000,00');
        await page.locator('#parametros').getByRole('button', { name: 'Salvar' }).click();
        await outro.close();
    }
});

test('duas pessoas em "O que você usa": desligar uma coisa não liga de novo a outra (A-04)', async ({ page, browser, baseURL }) => {
    const outro = await bruno(browser, baseURL);
    const pb = await outro.newPage();
    const caixa = (p, nome) => p.locator('#recursos').getByRole('checkbox', { name: nome });
    const salvar = (p) => p.locator('#recursos').getByRole('button', { name: 'Salvar' }).click();
    try {
        await page.goto('/ajustes?aberto=recursos');
        await pb.goto('/ajustes?aberto=recursos');
        await caixa(page, 'Carro').uncheck();
        await salvar(page);
        await caixa(pb, 'Empréstimo ou financiamento').uncheck();      // na página do bruno, o carro ainda está ligado
        await salvar(pb);
        await expect(pb.getByText('Ajustes salvos.')).toBeVisible();
        await expect(caixa(pb, 'Carro')).not.toBeChecked();
        await expect(caixa(pb, 'Empréstimo ou financiamento')).not.toBeChecked();
        await expect(caixa(pb, 'Cartão de crédito')).toBeChecked();
    } finally {
        await page.goto('/ajustes?aberto=recursos');
        await caixa(page, 'Carro').check();
        await caixa(page, 'Empréstimo ou financiamento').check();
        await salvar(page);
        await outro.close();
    }
});
