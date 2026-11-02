// Várias contas e vários cartões: criar uma conta, transferir para ela pelo lançar e pagar a fatura de outro cartão,
// escolhido no seletor da aba Cartão. Tem servidor e banco só seus: uma conta ou um cartão a mais mudariam as telas dos
// outros testes do mesmo processo.
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

test('transfere para uma conta nova e paga a fatura escolhendo o cartão', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    const ir = (caminho) => page.goto(srv.url + caminho);

    // a conta nova, em Ajustes → Contas
    await ir('/ajustes?aberto=carteira#carteira');
    const contas = page.locator('#carteira');
    await contas.getByText('Nova conta').click();
    const nova = contas.locator('.aj-cd-nova form');
    await nova.getByLabel('Nome').fill('Carteira');
    await nova.getByText('Dinheiro vivo').click();
    await nova.getByRole('button', { name: 'Criar conta' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Conta criada' })).toBeVisible();

    // com duas contas, o lançar tem o modo Transferir
    await ir('/lancar?volta=%2Flancamentos');
    await page.getByText('↔ Transferir').click();
    await page.getByRole('textbox', { name: 'valor' }).pressSequentially('120');
    await page.getByRole('combobox', { name: /^Para/ }).selectOption({ label: 'Carteira' });
    const transferir = page.getByRole('button', { name: 'Transferir', exact: true });
    await expect(transferir).toBeVisible();
    await transferir.click();
    await expect(page.getByRole('status').filter({ hasText: 'Transferência' })).toContainText('120,00');

    // o Extrato da carteira: a transferência e o saldo dela
    await ir('/lancamentos?conta=2');
    await expect(page.locator('.ex-saldo-conta')).toContainText('120,00');
    await expect(page.getByRole('link', { name: /Transferência/ })).toContainText('+120,00');

    // um segundo cartão, que fecha no dia 10 e vence no 20: no dia 22/11 do relógio dos testes, a fatura de novembro venceu
    await ir('/cartao?novo=1');
    const cadastro = page.locator('#cartao-config');
    await cadastro.getByLabel('Nome').fill('Cartão dois');
    await cadastro.getByLabel('Fecha no dia').fill('10');
    await cadastro.getByLabel('Vence no dia').fill('20');
    await cadastro.getByRole('button', { name: 'Salvar' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Cartão cadastrado' })).toBeVisible();

    // escolhe o cartão no seletor, a fatura de novembro na escada, e paga com a carteira
    await ir('/cartao');
    await page.getByRole('navigation', { name: 'cartões' }).getByRole('link', { name: 'Cartão dois' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cartão');
    await expect(page.getByText('Cartão dois · fecha dia 10 · vence dia 20')).toBeVisible();
    await page.getByRole('link', { name: /Fatura de nov\/26/ }).click();
    const fatura = page.locator('#f-2026-11');
    await expect(fatura).toBeVisible();
    await fatura.getByText('Pagar fatura').click();
    await fatura.getByLabel('Valor pago').fill('75');
    await fatura.getByLabel('Pagou com').selectOption({ label: 'Carteira' });
    await fatura.getByRole('button', { name: 'Lançar o pagamento' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Pagamento da fatura de novembro' })).toContainText('75,00');
    await expect(page.locator('#f-2026-11')).toContainText('Paga em');

    // o pagamento saiu da carteira
    await ir('/lancamentos?conta=2');
    await expect(page.locator('.ex-saldo-conta')).toContainText('45,00');
    expect(srv.logDoApp(), 'avisos ou erros do PHP').toBe('');
});
