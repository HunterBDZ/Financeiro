// O movimento (movimento.css e movimento.js): a troca de aba e de mês funciona igual com e sem View Transition (o
// Firefox troca na hora), "reduzir movimento" mostra o estado final na hora, e um clique novo nunca deixa a tela num
// estado do meio: Pagar e Desfazer em seguida voltam ao que era.
import { test, expect, contaSemPagamento } from './fixtures.js';

// o que ainda se mexe na página: as animações (as do CSS e as do movimento.js), menos as de laço (a roda de
// "carregando") e o aviso. A transição de cor do hover não conta: é o mouse, não o movimento
async function movendo(page) {
    return page.evaluate(() => document.getAnimations()
        .filter((a) => !(typeof CSSTransition !== 'undefined' && a instanceof CSSTransition))
        .filter((a) => a.playState === 'running' && a.effect && a.effect.getTiming().iterations !== Infinity)
        .filter((a) => !(a.effect.target instanceof Element && a.effect.target.closest('[data-toast]')))
        .map((a) => a.animationName || 'animação'));
}

test('trocar de aba e de mês funciona igual, com ou sem View Transition', async ({ page }) => {
    await page.goto('/');
    const lateral = page.getByRole('complementary', { name: 'menu' });
    await expect(lateral.locator('.lat-i[aria-current="page"] .lat-realce')).toHaveCount(1);

    await lateral.getByRole('link', { name: 'Mês' }).click();
    await expect(page).toHaveURL('/mes');
    await expect(page.getByRole('heading', { level: 1, name: 'Novembro de 2026' })).toBeVisible();
    // o realce da aba ativa é um só, na aba nova
    await expect(lateral.locator('.lat-realce')).toHaveCount(1);
    await expect(lateral.getByRole('link', { name: 'Mês' }).locator('.lat-realce')).toHaveCount(1);

    // o mês seguinte e o voltar do navegador
    await page.locator('.seletor-mes a[rel="next"]').click();
    await expect(page).toHaveURL(/\/mes\?mes=2026-12-01/);
    await expect(page.getByRole('heading', { level: 1, name: 'Dezembro de 2026' })).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('heading', { level: 1, name: 'Novembro de 2026' })).toBeVisible();

    await lateral.getByRole('link', { name: 'Extrato' }).click();
    await expect(page).toHaveURL('/lancamentos');
    await expect(page.locator('.ext-linha').first()).toBeVisible();
    expect(await page.evaluate(() => getComputedStyle(document.querySelector('.conteudo')).opacity)).toBe('1');
});

test.describe('com "reduzir movimento"', () => {
    test.use({ reducedMotion: 'reduce' });

    test('tudo chega ao estado final na hora', async ({ page, baseURL }) => {
        await contaSemPagamento(page, baseURL, 'Celular');
        await page.goto('/');
        // a primeira visita do dia não preenche a barra: só anota o dia
        expect(await movendo(page)).toEqual([]);
        expect(await page.evaluate(() => localStorage.getItem('mov-barra-dia'))).toBe('2026-11-22');

        const emConta = page.locator('[data-mov-conta="em-conta"] .num');
        const antes = await emConta.textContent();
        await page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' })
            .getByRole('button', { name: /^Pagar/ }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Pago hoje' })).toBeVisible();
        // o número já é o novo, a barra já está no lugar, o carimbo já está lá: nada se mexe
        const depois = await emConta.textContent();
        expect(depois).not.toBe(antes);
        expect(await movendo(page)).toEqual([]);
        expect(await page.locator('.ini-destino .barra > span').evaluateAll((s) => s.map((e) => getComputedStyle(e).transform)))
            .toEqual(expect.arrayContaining(['none']));
        await expect(page.locator('[data-mov-pago] .carimbo').first()).toHaveCSS('opacity', '1');
        await page.waitForTimeout(500);
        expect(await emConta.textContent()).toBe(depois);
    });
});

test('Pagar e Desfazer em seguida não deixam estado errado', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Celular');
    await page.goto('/');
    const emConta = page.locator('[data-mov-conta="em-conta"] .num');
    const antes = await emConta.textContent();
    const pagarAntes = await page.locator('.btn-pagar').count();
    const larguras = () => page.locator('.ini-destino .barra > span').evaluateAll((s) => s.map((e) => Math.round(e.getBoundingClientRect().width)));

    await page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' })
        .getByRole('button', { name: /^Pagar/ }).click();
    const aviso = page.getByRole('status').filter({ hasText: 'Pago hoje' });
    // o Desfazer no meio da contagem: o clique fixa o estado final e a ação segue
    await aviso.getByRole('button', { name: 'desfazer' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'excluído' })).toBeVisible();

    // um clique novo no meio do caminho de volta fixa o estado final na hora
    await page.locator('.topo h1').click();
    expect(await emConta.textContent()).toBe(antes);
    expect(await movendo(page)).toEqual([]);
    await expect(page.locator('.btn-pagar')).toHaveCount(pagarAntes);
    await expect(page.locator('.btn-pagar.is-pagando')).toHaveCount(0);
    expect(await page.locator('.ini-destino .barra > span').evaluateAll((s) => s.every((e) => getComputedStyle(e).transform === 'none'))).toBe(true);

    // e o mesmo estado sem clique nenhum: recarregar dá a mesma tela
    const agora = await larguras();
    await page.reload();
    expect(await emConta.textContent()).toBe(antes);
    expect(await larguras()).toEqual(agora);

    // deixa a conta paga, como a demonstração: os outros testes do processo contam com isso
    await page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' })
        .getByRole('button', { name: /^Pagar/ }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Pago hoje' })).toBeVisible();
});

test('dois cliques rápidos em Pagar lançam uma vez só', async ({ page, baseURL }) => {
    await contaSemPagamento(page, baseURL, 'Celular');
    await page.goto('/lancamentos?busca=Celular');
    const linhasAntes = await page.locator('.ext-linha').count();
    await page.goto('/');
    const botao = page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' })
        .getByRole('button', { name: /^Pagar/ });
    await botao.dblclick();
    await expect(page.getByRole('status').filter({ hasText: 'Pago hoje' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Contas do mês' }).getByRole('listitem').filter({ hasText: 'Celular' }).locator('.carimbo')).toHaveCount(1);
    await page.goto('/lancamentos?busca=Celular');
    await expect(page.locator('.ext-linha')).toHaveCount(linhasAntes + 1);
});

test('a barra do Início se preenche só na primeira visita do dia', async ({ page, browserName }) => {
    await page.goto('/');
    expect(await page.evaluate(() => localStorage.getItem('mov-barra-dia'))).toBe('2026-11-22');
    // na segunda visita, a barra já aparece pronta
    await page.goto('/');
    const naBarra = await page.evaluate(() => document.getAnimations().filter((a) => a.effect && a.effect.target instanceof Element && a.effect.target.closest('.barra')).length);
    expect(naBarra).toBe(0);
    test.info().annotations.push({ type: 'navegador', description: browserName });
});

test('o diálogo de lançar abre a partir do botão clicado', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: /Lançar/ }).click();
    const dlg = page.locator('dialog#lancar');
    await expect(dlg).toBeVisible();
    // a origem é posta pelo observador do atributo open, logo depois de abrir
    await expect.poll(() => dlg.evaluate((d) => d.style.getPropertyValue('--origem'))).toMatch(/^-?\d+px -?\d+px$/);
    await page.keyboard.press('Escape');
    await expect(dlg).toBeHidden();
});
