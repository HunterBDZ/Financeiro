// As "Contas do mês" do Início e do Mês (o Extrato não repete a lista: mostra só a linha da conta vencida): o Pagar lança a conta fixa
// com o valor previsto, na data de hoje, e o aviso tem o desfazer. Na demonstração (dia 22), as contas fixas do mês já estão pagas: cada teste tira o pagamento de uma.
import { test, expect, contaSemPagamento } from './fixtures.js';

test('Pagar no Início: a conta ganha o carimbo, e desfazer a traz de volta', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Celular');
    await page.goto('/');
    const bloco = page.getByRole('region', { name: 'Contas do mês' });
    const conta = bloco.getByRole('listitem').filter({ hasText: 'Celular' });
    await expect(conta).toContainText('89,90');
    await expect(conta).toContainText('venceu dia 15');
    // o nome leva ao Lançar com a categoria, para outro valor
    await expect(conta.getByRole('link')).toHaveAttribute('href', /^\/lancar\?categoria=\d+/);
    const pagas = await bloco.locator('.secao-cab p').textContent();

    await conta.getByRole('button', { name: /^Pagar/ }).click();
    const aviso = page.getByRole('status').filter({ hasText: 'Pago hoje' });
    await expect(aviso).toContainText('Celular');
    await expect(aviso).toContainText('89,90');
    // a conta continua na lista, paga: o carimbo com o dia no lugar do Pagar, e o valor em vermelho com "−"
    const paga = page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' });
    await expect(paga.locator('.carimbo')).toHaveText('pago 22/11');
    await expect(paga.getByRole('button', { name: /^Pagar/ })).toHaveCount(0);
    await expect(paga.locator('.num.v-saida')).toHaveText('−89,90');
    await expect(page.getByRole('region', { name: 'Contas do mês' }).locator('.secao-cab p')).not.toHaveText(pagas);

    await aviso.getByRole('button', { name: 'desfazer' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'excluído' })).toContainText('89,90');
    const deNovo = page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' });
    await expect(deNovo.getByRole('button', { name: /^Pagar/ })).toBeVisible();
    await expect(deNovo.locator('.carimbo')).toHaveCount(0);
});

test('Pagar no Mês: a linha da conta ganha o carimbo', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Internet');
    await page.goto('/mes');
    const linha = page.getByRole('row').filter({ hasText: 'Internet' });
    await expect(linha).toContainText('venceu dia 12');
    await linha.getByRole('button', { name: /^Pagar/ }).click();
    await expect(page).toHaveURL(/\/mes\?mes=2026-11-01/);
    await expect(page.getByRole('status').filter({ hasText: 'Pago hoje' })).toContainText('Internet');
    const paga = page.getByRole('row').filter({ hasText: 'Internet' });
    await expect(paga.locator('.carimbo')).toHaveText('pago 22/11');
    await expect(paga.getByRole('cell').nth(2)).toHaveText('−119,90');
    await page.getByRole('status').getByRole('button', { name: 'desfazer' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'excluído' })).toContainText('119,90');
});

test('o Extrato não repete as Contas do mês: só avisa a vencida e leva ao Mês', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Luz');           // vence dia 10: no dia 22, vencida
    await page.goto('/lancamentos');
    await expect(page.getByRole('region', { name: 'Contas do mês' })).toHaveCount(0);
    const aviso = page.locator('.ext-vencida');
    await expect(aviso).toContainText(/contas? vencidas?: .*Luz/);
    await aviso.getByRole('link', { name: 'Ver no Mês' }).click();
    await expect(page).toHaveURL('/mes');
});

test('outro valor: o nome da conta abre o Lançar com a categoria escolhida', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Internet');
    await page.goto('/');
    await page.getByRole('region', { name: 'Contas do mês' }).getByRole('link', { name: /Internet/ }).click();
    const dialogo = page.getByRole('dialog', { name: 'lançar' });
    await expect(dialogo).toBeVisible();
    await expect(dialogo.getByRole('combobox', { name: 'Categoria' })).toHaveValue('Internet');
    await expect(dialogo.getByRole('textbox', { name: 'valor' })).toBeFocused();
});
