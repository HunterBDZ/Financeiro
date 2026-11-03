// Ajustes com mais de uma pessoa: o que uma salva não some quando a outra salva por cima, e as regras de conta
// (senha, pessoas, categorias do app). Usa a ana (o contexto do teste) e o bruno (um contexto a mais) da
// demonstração; cada teste devolve o que mudou, porque os outros testes do mesmo processo usam os mesmos dados.
import { test, expect, entrar, token, SENHA } from './fixtures.js';

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

test('a mesma regra de senha em todo lugar: 8 caracteres e não só espaços (A-07, A-08)', async ({ page, baseURL }) => {
    // o campo pede 8; o servidor confere também, para quem manda o formulário sem a tela
    await page.goto('/ajustes?aberto=senha');
    await expect(page.locator('#senha input[name="senha_nova"]')).toHaveAttribute('minlength', '8');
    const enviar = async (url, campos) => page.request.post(baseURL + url, { form: { csrf_token: await token(page.request, baseURL + '/ajuda'), ...campos } });

    // a resposta segue o redirecionamento: é a página de Ajustes com o aviso
    let r = await enviar('/ajustes/senha', { senha_atual: SENHA, senha_nova: 'abcdef', senha_repetir: 'abcdef' });
    expect(await r.text()).toContain('A senha precisa de pelo menos 8 caracteres.');
    r = await enviar('/ajustes/senha', { senha_atual: SENHA, senha_nova: '        ', senha_repetir: '        ' });
    expect(await r.text()).toContain('A senha não pode ser só espaços.');
    r = await enviar('/ajustes/pessoas', { nome: 'Eva', login: 'eva-espacos', senha: '        ' });
    expect(await r.text()).toContain('A senha não pode ser só espaços.');
    await page.goto('/ajustes?aberto=pessoas');
    await expect(page.locator('#pessoas')).not.toContainText('Eva');

    // a senha da ana continua a mesma
    await page.request.post(baseURL + '/logout');
    await entrar(page.request, baseURL);
});

test('erro em Pessoas e em Criar categoria volta com o que foi digitado (A-09)', async ({ page }) => {
    // Pessoas: senha curta (o campo pede 8; sem a regra do navegador, o servidor confere)
    await page.goto('/ajustes?aberto=pessoas');
    const pessoas = page.locator('#pessoas');
    await pessoas.getByLabel('Nome', { exact: true }).fill('Eva');
    await pessoas.getByLabel('Nome de usuário').fill('eva');
    await pessoas.getByLabel('Senha', { exact: true }).fill('1234567');
    await pessoas.locator('form').first().evaluate((f) => { f.noValidate = true; });
    await pessoas.getByRole('button', { name: 'Adicionar pessoa' }).click();
    await expect(pessoas).toContainText('A senha precisa de pelo menos 8 caracteres.');
    await expect(pessoas.getByLabel('Nome', { exact: true })).toHaveValue('Eva');
    await expect(pessoas.getByLabel('Nome de usuário')).toHaveValue('eva');
    await expect(pessoas.getByLabel('Senha', { exact: true })).toHaveValue('');

    // Criar categoria: o formulário volta aberto, com a mensagem e o que foi digitado
    await page.goto('/ajustes?aberto=categorias');
    const cats = page.locator('#categorias');
    await cats.getByText('Criar categoria').first().click();
    await cats.getByLabel('Nome da categoria').fill('Pet de teste');
    await cats.getByText('Não essencial').click();
    await cats.getByLabel('Meta de gasto por mês (opcional)').fill('-50');
    await cats.getByRole('button', { name: 'Criar categoria' }).click();
    const form = cats.locator('form.aj-cn');
    await expect(form).toBeVisible();
    await expect(form.getByRole('alert')).toHaveText('Valor inválido. Use só números, como 150 ou 150,90.');
    await expect(form.getByLabel('Nome da categoria')).toHaveValue('Pet de teste');
    await expect(form.getByLabel('Meta de gasto por mês (opcional)')).toHaveValue('-50');
    await expect(form.locator('input[name="essencial"][value="0"]')).toBeChecked();
    await expect(cats).not.toContainText('Pet de teste criada');
});

test('errar a senha atual 5 vezes bloqueia só a troca, com aviso, e a sessão continua (A-13)', async ({ page, browser, baseURL }) => {
    // uma conta só deste teste: o bloqueio dura alguns minutos e não pode atrapalhar os outros
    const login = 'teste-a13-' + Date.now();
    const criada = await page.request.post(baseURL + '/ajustes/pessoas', {
        form: { csrf_token: await token(page.request, baseURL + '/ajuda'), nome: 'Teste do bloqueio', login, senha: 'senha-certa-a13' },
    });
    expect(criada.ok()).toBe(true);
    const ctx = await browser.newContext({ baseURL });
    await entrar(ctx.request, baseURL, login, 'senha-certa-a13');
    const p = await ctx.newPage();
    const trocar = async (atual) => {
        await p.goto('/ajustes?aberto=senha');
        const f = p.locator('#senha');
        await f.getByLabel('Senha atual').fill(atual);
        await f.getByLabel('Nova senha', { exact: true }).fill('nova-senha-a13');
        await f.getByLabel('Repita a nova senha').fill('nova-senha-a13');
        await f.getByRole('button', { name: 'Trocar senha' }).click();
    };
    try {
        for (let i = 1; i <= 4; i++) {
            await trocar('errada-' + i);
            await expect(p.locator('#senha')).toContainText('A senha atual não confere.');
        }
        await trocar('errada-5');
        await expect(p).toHaveURL(/\/ajustes/);
        await expect(p.locator('#senha')).toContainText('Muitas tentativas. Tente de novo em 5 min.');
        // nem a senha certa troca agora; a sessão continua, e entrar pela tela continua valendo
        await trocar('senha-certa-a13');
        await expect(p.locator('#senha')).toContainText('Muitas tentativas.');
        await p.goto('/');
        await expect(p).toHaveURL('/');
        const outro = await browser.newContext({ baseURL });
        await entrar(outro.request, baseURL, login, 'senha-certa-a13');
        await outro.close();
    } finally {
        await ctx.close();
    }
});

test('Ajuda: com a busca, o índice "Nesta página" mostra só o que sobrou (A-14)', async ({ page }) => {
    await page.goto('/ajuda');
    const indice = page.getByRole('navigation', { name: 'Nesta página' });
    await expect(indice).toBeVisible();
    const total = await indice.getByRole('link').count();
    await page.getByPlaceholder('Buscar na ajuda').fill('senha');
    const visiveis = indice.getByRole('link');
    const n = await visiveis.count();
    expect(n).toBeGreaterThan(0);
    expect(n).toBeLessThan(total);
    // cada link que ficou leva a uma resposta à vista
    for (const href of await visiveis.evaluateAll((l) => l.map((a) => a.getAttribute('href')))) {
        await expect(page.locator(href)).toBeVisible();
    }
    await visiveis.last().click();
    await expect(page.locator(await visiveis.last().getAttribute('href'))).toBeInViewport();
    await page.getByPlaceholder('Buscar na ajuda').fill('');
    await expect(indice.getByRole('link')).toHaveCount(total);
});
