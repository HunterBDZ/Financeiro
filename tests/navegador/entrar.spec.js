// Entrar e sair pela tela de login.
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
