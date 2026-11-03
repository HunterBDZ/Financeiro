// Organizar o menu: entrar no modo (botão ou segurar uma aba), arrastar com o mouse, mover pelo teclado, Esc sai,
// a ordem fica na conta depois de recarregar e "Voltar à ordem padrão" desfaz. No celular, a lista do botão Menu.
import { test, expect } from './fixtures.js';

const ordem = (page, onde = '.lateral') => page.locator(`${onde} [data-org-lista] > li`).evaluateAll((l) => l.map((i) => i.dataset.aba));
const gravacao = (page) => page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/menu/ordem');
const lateral = (page) => page.getByRole('complementary', { name: 'menu' });

// a ordem é da conta e o banco é do processo: cada teste deixa a ordem padrão para os outros
test.afterEach(async ({ page }) => {
    const status = await page.evaluate(() => {
        const corpo = new FormData();
        corpo.append('csrf_token', document.querySelector('[data-org]').getAttribute('data-org-csrf'));
        corpo.append('padrao', '1');
        return fetch('/menu/ordem', { method: 'POST', body: corpo }).then((r) => r.status);
    });
    expect(status).toBe(204);
});

test('arrastar com o mouse muda a ordem, e ela fica depois de recarregar', async ({ page }) => {
    await page.goto('/mes');
    const padrao = await ordem(page);
    expect(padrao.slice(0, 3)).toEqual(['inicio', 'extrato', 'mes']);
    await lateral(page).getByRole('button', { name: 'Organizar menu' }).click();
    await expect(page.locator('.lateral .org')).toHaveClass(/organizando/);
    await expect(lateral(page).getByRole('button', { name: 'Pronto' })).toBeVisible();

    // enquanto organiza, a aba não navega
    await page.locator('.lateral li[data-aba="extrato"]').click();
    await expect(page).toHaveURL('/mes');

    // Relatório para cima do Extrato
    const de = await page.locator('.lateral li[data-aba="relatorio"]').boundingBox();
    const para = await page.locator('.lateral li[data-aba="extrato"]').boundingBox();
    await page.mouse.move(de.x + 50, de.y + de.height / 2);
    await page.mouse.down();
    await page.mouse.move(de.x + 50, de.y + de.height / 2 - 10, { steps: 3 });
    await page.mouse.move(de.x + 50, para.y + 4, { steps: 12 });
    const gravou = gravacao(page);
    await page.mouse.up();
    expect((await gravou).status()).toBe(204);
    const nova = ['inicio', 'relatorio', ...padrao.filter((c) => c !== 'inicio' && c !== 'relatorio')];
    expect(await ordem(page)).toEqual(nova);

    await lateral(page).getByRole('button', { name: 'Pronto' }).click();
    await expect(page.locator('.lateral .org')).not.toHaveClass(/organizando/);
    await page.reload();
    expect(await ordem(page)).toEqual(nova);
    // o celular segue a mesma ordem na lista do botão Menu
    expect((await ordem(page, '.menu-geral'))[0]).toBe('relatorio');
    // fora do modo, a aba volta a navegar
    await lateral(page).getByRole('link', { name: 'Relatório' }).click();
    await expect(page).toHaveURL('/relatorio');
});

test('pelo teclado: as setas movem, o aviso diz a posição e Esc sai', async ({ page }) => {
    await page.goto('/');
    const padrao = await ordem(page);
    await lateral(page).getByRole('button', { name: 'Organizar menu' }).focus();
    await page.keyboard.press('Enter');
    // o foco vai para a primeira seta que funciona: a de descer a 1ª aba
    await expect(page.getByRole('button', { name: 'Descer Início' })).toBeFocused();
    await expect(page.getByRole('button', { name: 'Subir Início' })).toBeDisabled();

    const gravou = gravacao(page);
    await page.keyboard.press('Enter');
    expect((await gravou).status()).toBe(204);
    await expect(page.locator('.lateral [data-org-aviso]')).toHaveText(`Início: posição 2 de ${padrao.length}.`);
    await expect(page.getByRole('button', { name: 'Descer Início' })).toBeFocused();
    expect((await ordem(page)).slice(0, 2)).toEqual(['extrato', 'inicio']);

    await page.keyboard.press('Escape');
    await expect(page.locator('.lateral .org')).not.toHaveClass(/organizando/);
    await expect(lateral(page).getByRole('button', { name: 'Organizar menu' })).toBeFocused();
    await expect(page.getByRole('button', { name: 'Descer Início' })).toHaveCount(0);

    await page.reload();
    expect((await ordem(page)).slice(0, 2)).toEqual(['extrato', 'inicio']);

    // voltar à ordem padrão
    await lateral(page).getByRole('button', { name: 'Organizar menu' }).click();
    const voltou = gravacao(page);
    await lateral(page).getByRole('button', { name: 'Voltar à ordem padrão' }).click();
    expect((await voltou).status()).toBe(204);
    expect(await ordem(page)).toEqual(padrao);
    await page.reload();
    expect(await ordem(page)).toEqual(padrao);
});

test('segurar uma aba entra no modo sem abrir a aba', async ({ page }) => {
    await page.goto('/ajuda');
    const aba = page.locator('.lateral li[data-aba="wishlist"]');
    const caixa = await aba.boundingBox();
    await page.mouse.move(caixa.x + 50, caixa.y + caixa.height / 2);
    await page.mouse.down();
    await page.waitForTimeout(700);
    await page.mouse.up();
    await expect(page.locator('.lateral .org')).toHaveClass(/organizando/);
    await page.waitForTimeout(200);
    await expect(page).toHaveURL('/ajuda');
    await page.keyboard.press('Escape');
    await expect(page.locator('.lateral .org')).not.toHaveClass(/organizando/);
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('a lista do botão Menu também se organiza, e a barra de baixo não muda', async ({ page }) => {
        await page.goto('/');
        const barra = await page.locator('.tabbar a').evaluateAll((l) => l.map((a) => a.getAttribute('href').split('?')[0]));
        const banner = page.getByRole('banner');
        await banner.getByText('Menu', { exact: true }).click();
        await banner.getByRole('button', { name: 'Organizar menu' }).click();
        await expect(page.locator('.menu-geral .org')).toHaveClass(/organizando/);
        const antes = await ordem(page, '.menu-geral');
        const gravou = gravacao(page);
        await banner.getByRole('button', { name: 'Subir Ajustes' }).click();
        expect((await gravou).status()).toBe(204);
        // Esc sai do modo, e o menu continua aberto
        await page.keyboard.press('Escape');
        await expect(page.locator('.menu-geral .org')).not.toHaveClass(/organizando/);
        await expect(page.locator('.menu-geral')).toHaveAttribute('open', '');

        await page.reload();
        const depois = await ordem(page, '.menu-geral');
        const i = antes.indexOf('ajustes');
        expect(depois[i - 1]).toBe('ajustes');
        expect(depois[i]).toBe(antes[i - 1]);
        expect(await page.locator('.tabbar a').evaluateAll((l) => l.map((a) => a.getAttribute('href').split('?')[0]))).toEqual(barra);
    });
});
