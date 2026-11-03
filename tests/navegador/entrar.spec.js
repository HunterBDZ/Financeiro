// Entrar e sair pela tela de login.
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { test, expect, LOGIN, SENHA } from './fixtures.js';

test.use({ logado: false });

test('entra e sai', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL('/login');
    await page.getByLabel('Usuário').fill(LOGIN);
    await page.getByLabel('Senha').fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page).toHaveURL('/');
    // o Início abre com o mês como título; a pessoa que entrou aparece no pé do menu
    await expect(page.getByRole('heading', { level: 1, name: 'Novembro de 2026' })).toBeVisible();
    await expect(page.getByRole('complementary', { name: 'menu' }).getByText('Ana', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'sair' }).click();
    await expect(page).toHaveURL('/login');
    await page.goto('/');      // a sessão acabou de verdade: volta ao login
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
});

test('senha errada mostra a mensagem', async ({ page, erros }) => {
    erros.permitir(/status of 401/);    // o Chromium registra no console a resposta 401 do servidor
    await page.goto('/login');
    await page.getByLabel('Usuário').fill(LOGIN);
    await page.getByLabel('Senha').fill('senha-errada');
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page.getByRole('alert')).toHaveText('Login ou senha incorretos.');
    await expect(page.getByLabel('Senha')).toHaveAttribute('aria-invalid', 'true');    // o erro mora junto do campo
    await expect(page.getByLabel('Usuário')).toHaveValue(LOGIN);
    await expect(page.getByRole('button', { name: 'sair' })).toHaveCount(0);
});

test('fora dos buscadores, com favicon e a página de privacidade', async ({ page, request }) => {
    const robots = await request.get('/robots.txt');
    expect(robots.ok()).toBe(true);
    expect(await robots.text()).toMatch(/User-agent: \*\s+Disallow: \/$/m);
    const login = await request.get('/login');
    expect(login.headers()['x-robots-tag']).toBe('noindex, nofollow');
    const icone = await request.get('/favicon.ico');
    expect(icone.ok()).toBe(true);
    expect(icone.headers()['content-type']).toMatch(/^image\//);

    await page.goto('/login');
    await page.getByRole('link', { name: 'Privacidade e aviso' }).click();
    await expect(page).toHaveURL('/privacidade');
    await expect(page.getByRole('heading', { level: 1, name: 'Privacidade e aviso' })).toBeVisible();
    for (const secao of ['Seus dados', 'O que sai do computador', 'Cookies', 'Aviso', 'Contato']) {
        await expect(page.getByRole('heading', { level: 2, name: secao })).toBeVisible();
    }
    await page.getByRole('link', { name: 'Voltar para entrar' }).click();
    await expect(page).toHaveURL('/login');
});

test('o aviso do navegador: os passos de cada um, com o nome do botão', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('link', { name: 'Aviso do navegador' }).click();
    await expect(page).toHaveURL('/ajuda/conexao');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('O navegador disse que a conexão não é segura');
    for (const nav of ['Chrome e Edge', 'Firefox', 'Safari, no iPhone e no Mac']) {
        await expect(page.getByRole('heading', { level: 2, name: nav })).toBeVisible();
    }
    const host = new URL(page.url()).host;
    await expect(page.locator('#chrome')).toContainText(`Ir para ${host} (não seguro)`);
    await expect(page.locator('#firefox')).toContainText('Aceitar o risco e continuar');
});

test('os idiomas ficam numa seleção discreta e a troca volta para a mesma página', async ({ page }) => {
    await page.goto('/privacidade');
    await page.getByRole('form', { name: 'Idioma' }).getByRole('button', { name: 'English' }).click();
    await expect(page).toHaveURL('/privacidade');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { level: 1, name: 'Privacy and notice' })).toBeVisible();
    await page.getByRole('form', { name: 'Language' }).getByRole('button', { name: 'Português' }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'pt-BR');
});

test('endereço que não existe: a página diz isso e volta ao Início', async ({ page, erros }) => {
    erros.permitir(/status of 404/);
    const r = await page.goto('/nao-existe-mesmo');
    expect(r.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Esta página não existe');
    await expect(page.getByText('404')).toHaveCount(0);              // o número fica só no título da aba
    await page.getByRole('link', { name: 'Voltar ao Início' }).click();
    await expect(page).toHaveURL(/\/login/);
});

test('sair com a sessão de 8 h vencida também esquece o aparelho (A-01)', async ({ page, context }) => {
    await page.goto('/login');
    await page.getByLabel('Usuário').fill(LOGIN);
    await page.getByLabel('Senha').fill(SENHA);
    await page.getByLabel('Lembrar este aparelho').check();
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL('/');
    await page.goto('/ajustes');

    // a sessão venceu com a página aberta: sobra só o cookie de lembrar
    const cookies = await context.cookies();
    expect(cookies.some((c) => c.name === 'financeiro_lembrar')).toBe(true);
    await context.clearCookies({ name: 'financeiro_sid' });

    await page.getByRole('button', { name: 'sair' }).click();
    await expect(page).toHaveURL('/login');
    expect((await context.cookies()).some((c) => c.name === 'financeiro_lembrar')).toBe(false);
    await page.goto('/');
    await expect(page).toHaveURL(/\/login/);
});

test('sair só por POST: um link para /logout não derruba ninguém (A-01)', async ({ page, erros }) => {
    erros.permitir(/status of 404/);
    await page.goto('/login');
    await page.getByLabel('Usuário').fill(LOGIN);
    await page.getByLabel('Senha').fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL('/');
    const r = await page.goto('/logout');
    expect(r.status()).toBe(404);
    await page.goto('/');
    await expect(page).toHaveURL('/');
});

/** Entra pela tela, com ou sem "Lembrar este aparelho". */
async function entrarPelaTela(page, lembrar = false) {
    await page.goto('/login');
    await page.getByLabel('Usuário').fill(LOGIN);
    await page.getByLabel('Senha').fill(SENHA);
    if (lembrar) {
        await page.getByLabel('Lembrar este aparelho').check();
    }
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL('/');
}

test('sem "Lembrar", fechar o navegador sai: o cookie da sessão não tem data (A-06)', async ({ page, context, browser, baseURL }) => {
    await entrarPelaTela(page);
    const sessao = (await context.cookies()).find((c) => c.name === 'financeiro_sid');
    expect(sessao.expires).toBe(-1);                     // cookie da sessão do navegador
    expect((await context.cookies()).some((c) => c.name === 'financeiro_lembrar')).toBe(false);

    // "fechar e abrir o navegador": um navegador novo, só com os cookies que têm data
    const reaberto = await browser.newContext({ baseURL });
    await reaberto.addCookies((await context.cookies()).filter((c) => c.expires > 0));
    const p2 = await reaberto.newPage();
    await p2.goto('/');
    await expect(p2).toHaveURL(/\/login/);
    await reaberto.close();
});

test('parada por mais de 8 h, a sessão acaba no servidor e a tela diz que expirou (A-06, A-10)', async ({ page, context, servidor }) => {
    await entrarPelaTela(page);
    const id = (await context.cookies()).find((c) => c.name === 'financeiro_sid').value;
    const arq = path.join(servidor.pasta, 'sessoes', 'sess_' + id);
    const nove = Math.floor(Date.now() / 1000) - 9 * 3600;
    writeFileSync(arq, readFileSync(arq, 'utf8').replace(/ultimo_uso\|i:\d+;/, `ultimo_uso|i:${nove};`));
    await page.goto('/ajustes');
    await expect(page).toHaveURL('/login?expirou=1');
    await expect(page.getByText('A sessão expirou. Entre de novo.')).toBeVisible();
});

test('depois de sair, a tela de entrar não diz que a sessão expirou (A-10)', async ({ page }) => {
    await entrarPelaTela(page);
    await page.getByRole('button', { name: 'sair' }).click();
    await expect(page).toHaveURL('/login');
    await page.goto('/ajuda');
    await expect(page).toHaveURL('/login');
    await expect(page.getByText('A sessão expirou. Entre de novo.')).toHaveCount(0);
    await page.goBack();
    await expect(page.getByText('A sessão expirou. Entre de novo.')).toHaveCount(0);
});
