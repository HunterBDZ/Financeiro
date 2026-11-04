// Várias contas e vários cartões: criar uma conta, ver o "Recebeu em" pela categoria, transferir para ela pelo lançar,
// cadastrar outro cartão com outro visual e pagar a fatura dele, escolhido no seletor da aba Cartão. Tem servidor e banco
// só seus: uma conta ou um cartão a mais mudariam as telas dos outros testes do mesmo processo.
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

    // com duas contas, a categoria escolhida no campo de busca muda o "Pagou com": numa entrada, "Recebeu em" e sem os cartões
    await ir('/lancar?volta=%2Flancamentos');
    const pagou = page.locator('[data-ct-pagou]');
    const categoria = page.getByRole('combobox', { name: 'Categoria' });
    await expect(pagou.locator('[data-pagou-rot]')).toHaveText('Pagou com');
    await expect(pagou.locator('[data-so-gasto]').first()).toBeVisible();
    await categoria.click();
    await categoria.pressSequentially('salar');
    await categoria.press('Enter');
    await expect(pagou.locator('[data-pagou-rot]')).toHaveText('Recebeu em');
    await expect(pagou.locator('[data-so-gasto]').first()).toBeHidden();
    await categoria.fill('');
    await categoria.pressSequentially('merc');
    await categoria.press('Enter');
    await expect(pagou.locator('[data-pagou-rot]')).toHaveText('Pagou com');
    await expect(pagou.locator('[data-so-gasto]').first()).toBeVisible();

    // e tem o modo Transferir
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
    await expect(page.locator('.ext-saldo-conta')).toContainText('120,00');
    await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: /Transferência/ }) })).toContainText('+120,00');

    // um segundo cartão, que fecha no dia 10 e vence no 20: no dia 22/11 do relógio dos testes, a fatura de novembro venceu
    await ir('/cartao?novo=1');
    const cadastro = page.locator('#cartao-config');
    await cadastro.getByLabel('Nome').fill('Cartão dois');
    await cadastro.getByLabel('Fecha no dia').fill('10');
    await cadastro.getByLabel('Vence no dia').fill('20');
    await cadastro.locator('.ct-vis-n').filter({ hasText: /^Verde$/ }).click();
    await cadastro.getByRole('button', { name: 'Salvar' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Cartão cadastrado' })).toBeVisible();

    // o visual é de cada cartão: o segundo fica verde, e o primeiro, com o seu
    await expect(page.locator('[data-ct-plastico]')).toHaveAttribute('data-visual', 'verde');
    await ir('/cartao');
    await expect(page.locator('[data-ct-plastico]')).not.toHaveAttribute('data-visual', 'verde');
    await page.locator('.ct-config > summary').click();
    await expect(page.locator('#cartao-config').getByRole('radio', { name: 'Verde', exact: true })).not.toBeChecked();

    // escolhe o cartão no seletor, a fatura de novembro na escada, e paga com a carteira
    await page.getByRole('navigation', { name: 'cartões' }).getByRole('link', { name: 'Cartão dois' }).click();
    await expect(page.locator('[data-ct-plastico]')).toHaveAttribute('data-visual', 'verde');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cartão');
    await expect(page.getByText('Cartão dois: fecha dia 10, vence dia 20')).toBeVisible();
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
    await expect(page.locator('.ext-saldo-conta')).toContainText('45,00');
    expect(srv.logDoApp(), 'avisos ou erros do PHP').toBe('');
});

// transferência que deixa a conta negativa pede confirmação; confirmada, grava (B-14)
test('transferir mais do que a conta tem pede confirmação', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    const ir = (caminho) => page.goto(srv.url + caminho);
    await ir('/ajustes?aberto=carteira#carteira');
    const contas = page.locator('#carteira');
    await contas.getByText('Nova conta').click();
    const nova = contas.locator('.aj-cd-nova form');
    await nova.getByLabel('Nome').fill('Bolso');
    await nova.getByText('Dinheiro vivo').click();
    await nova.getByRole('button', { name: 'Criar conta' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Conta criada' })).toBeVisible();

    await ir('/lancar?volta=%2Flancamentos');
    await page.getByText('↔ Transferir').click();
    await page.getByRole('textbox', { name: 'valor' }).pressSequentially('5000');
    await page.getByRole('combobox', { name: /^De/ }).selectOption({ label: 'Bolso' });
    await page.getByRole('combobox', { name: /^Para/ }).selectOption({ index: 0 });
    await page.getByRole('button', { name: 'Transferir', exact: true }).click();
    await expect(page.getByRole('main').getByRole('status')).toContainText('Bolso fica com −R$');
    await page.getByRole('button', { name: 'Transferir mesmo assim' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Transferência' })).toContainText('5.000,00');
});

// editar a transferência e deixar a conta negativa faz a mesma pergunta da criação (B-14), com o que foi digitado
test('editar a transferência para mais do que a conta tem pede confirmação', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    const ir = (caminho) => page.goto(srv.url + caminho);
    await ir('/lancar?volta=%2Flancamentos');
    await page.getByText('↔ Transferir').click();
    await page.getByRole('textbox', { name: 'valor' }).pressSequentially('100');
    await page.getByRole('combobox', { name: /^De/ }).selectOption({ index: 0 });
    await page.getByRole('combobox', { name: /^Para/ }).selectOption({ label: 'Bolso' });
    await page.locator('input[name="descricao"]').fill('edição negativa');
    await page.getByRole('button', { name: /^Transferir/ }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Transferência' })).toContainText('100,00');

    await page.getByRole('link', { name: 'edição negativa' }).click();
    const form = page.locator('form.ct-form');
    await form.locator('select[name="de_conta"]').selectOption({ label: 'Bolso' });
    await form.locator('select[name="para_conta"]').selectOption({ index: 0 });
    await form.locator('input[name="valor"]').fill('9000');
    await form.getByRole('button', { name: 'Salvar', exact: true }).click();
    await expect(form.getByRole('status')).toContainText('Bolso fica com −R$');
    await expect(form.locator('input[name="valor"]')).toHaveValue('9000');
    await form.getByRole('button', { name: 'Salvar mesmo assim' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'salvo' })).toContainText('9.000,00');
});

// no celular, as abas do "Pagou com" ficam numa linha, mesmo com duas contas e um cartão (B-16)
test('celular: as abas do "Pagou com" cabem numa linha', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    // a segunda conta: o primeiro teste a cria, mas este pode rodar sozinho ou noutro processo
    await page.goto(srv.url + '/ajustes?aberto=carteira#carteira');
    const contas = page.locator('#carteira');
    if (!(await contas.getByText('Reserva da casa').count())) {
        await contas.getByText('Nova conta').click();
        const nova = contas.locator('.aj-cd-nova form');
        await nova.getByLabel('Nome').fill('Reserva da casa');
        await nova.getByRole('button', { name: 'Criar conta' }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Conta criada' })).toBeVisible();
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(srv.url + '/lancar?volta=%2F');
    const abas = page.locator('[data-ct-pagou] label');
    expect(await abas.count()).toBeGreaterThanOrEqual(3);
    const topos = await abas.evaluateAll((ls) => ls.filter((l) => !l.hidden).map((l) => Math.round(l.getBoundingClientRect().top)));
    expect(new Set(topos).size).toBe(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
});
