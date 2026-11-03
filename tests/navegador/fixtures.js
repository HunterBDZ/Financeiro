// O que todo teste de navegador usa: o servidor do processo, a conta da demonstração já entrada e a vigia de
// erros de JavaScript. Escreva os testes com `import { test, expect } from './fixtures.js'`.
import { test as base, expect } from '@playwright/test';
import { subirServidor } from './servidor.js';

export { expect };

export const LOGIN = 'ana';
export const SENHA = 'demo1234';

// as telas do menu, na ordem do menu lateral: [rótulo, endereço]. Temas e Ajuda abrem por Ajustes (TELAS_AJUSTES)
export const TELAS = [
    ['Início', '/'],
    ['Extrato', '/lancamentos'],
    ['Mês', '/mes'],
    ['Cartão', '/cartao'],
    ['Metas', '/meta'],
    ['Relatório', '/relatorio'],
    ['Simular', '/futuro'],
    ['Empréstimo', '/emprestimo'],
    ['Investimentos', '/investimentos'],
    ['Wishlist', '/wishlist'],
    ['Ajustes', '/ajustes'],
];
export const TELAS_AJUSTES = [
    ['Temas', '/temas'],
    ['Ajuda', '/ajuda'],
];

export const test = base.extend({
    // um servidor e um banco novos por processo de teste. FIN_NAVEGADOR_PORTA fixa as portas (base + nº do processo)
    servidor: [async ({}, use, workerInfo) => {
        const inicio = Number(process.env.FIN_NAVEGADOR_PORTA) || 0;
        const servidor = await subirServidor({ porta: inicio ? inicio + workerInfo.parallelIndex : 0 });
        await use(servidor);
        await servidor.parar();
    }, { scope: 'worker', timeout: 60_000 }],

    baseURL: async ({ servidor }, use) => {
        await use(servidor.url);
    },

    // false = o teste começa fora da conta: test.use({ logado: false })
    logado: [true, { option: true }],

    context: async ({ context, logado, baseURL }, use) => {
        if (logado) {
            await entrar(context.request, baseURL);
        }
        await use(context);
    },

    // erro de JavaScript em qualquer página do teste o reprova, e também aviso ou erro do PHP no log do app. Um erro
    // esperado (o 401 da senha errada, por exemplo) passa com erros.permitir(/regex/)
    erros: [async ({ context, servidor }, use) => {
        const achados = [];
        const logAntes = servidor.logDoApp().length;
        const permitidos = [];
        context.on('weberror', (e) => achados.push(`pageerror: ${e.error().message}`));
        context.on('console', (m) => {
            if (m.type() === 'error') {
                achados.push(`console.error: ${m.text()} (${m.location().url})`);
            }
        });
        await use({ permitir: (re) => permitidos.push(re) });
        achados.push(...servidor.logDoApp().slice(logAntes).split('\n').filter((l) => l.trim() !== '').map((l) => `php: ${l}`));
        expect(achados.filter((t) => !permitidos.some((re) => re.test(t))), 'erros de JavaScript nas páginas e do PHP no log').toEqual([]);
    }, { auto: true }],
});

/** Entra pela tela de login, sem abrir página: o cookie da sessão fica no contexto do navegador. */
export async function entrar(request, baseURL, login = LOGIN, senha = SENHA) {
    const r = await request.post(baseURL + '/login', {
        form: { csrf_token: await token(request, baseURL + '/login'), login, senha },
    });
    expect(r.ok() && !new URL(r.url()).pathname.startsWith('/login'), `entrar como ${login}`).toBe(true);
}

/** O token CSRF da sessão, lido de uma página que tem formulário. */
export async function token(request, url) {
    const html = await (await request.get(url)).text();
    const m = html.match(/name="csrf_token" value="([0-9a-f]+)"/);
    expect(m, `token CSRF em ${url}`).not.toBeNull();
    return m[1];
}

/** Grava o tema da conta (id de Temas::todos(), ou 'auto'), sem passar pela tela Temas. */
export async function escolherTema(page, baseURL, tema) {
    const r = await page.request.post(baseURL + '/temas', {
        form: { csrf_token: await token(page.request, baseURL + '/ajuda'), tema, silencioso: '1' },
    });
    expect(r.status(), `tema ${tema}`).toBe(204);
}

/**
 * Escolhe a categoria no formulário de lançar como a pessoa faz: clica no campo, digita o nome e escolhe na lista.
 */
export async function escolherCategoria(escopo, nome) {
    const campo = escopo.getByRole('combobox', { name: 'Categoria' });
    await campo.click();
    await campo.fill(nome);
    await escopo.getByRole('listbox', { name: 'Categoria' }).getByRole('option', { name: nome, exact: true }).click();
    await expect(campo).toHaveValue(nome);
}

/**
 * Deixa uma conta fixa do mês sem pagamento, excluindo o lançamento dela (na demonstração, com o relógio no dia 22,
 * todas já foram pagas): assim ela aparece nas "Contas do mês" do Início (e, vencida, na linha de aviso do Extrato). Sem o pagamento, não faz nada.
 */
export async function contaSemPagamento(page, baseURL, nome) {
    await page.goto('/lancamentos');
    const linha = page.locator('.ext-linha .ext-c-desc > a', { hasText: nome });
    if (await linha.count() === 0) {
        return;                                         // já estava sem pagamento (outro teste do mesmo processo)
    }
    const href = await linha.first().getAttribute('href');
    const id = href.match(/^\/lancamentos\/(\d+)\//)[1];
    const r = await page.request.post(`${baseURL}/lancamentos/${id}/excluir`, {
        form: { csrf_token: await token(page.request, baseURL + '/ajuda'), volta: '/' },
    });
    expect(r.ok(), `excluir o pagamento de ${nome}`).toBe(true);
}
