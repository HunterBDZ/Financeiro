// Ajustes → Categorias: criar uma categoria, abrir o editar pela lista e mudar tudo nela; o erro aparece junto do
// campo; e a que já tem lançamentos não troca de tipo. Cada teste usa uma categoria só sua.
import { test, expect } from './fixtures.js';

test('cria, edita tudo e vê a categoria no lugar novo', async ({ page }) => {
    test.slow();                                            // são várias páginas seguidas
    await page.goto('/ajustes?aberto=categorias');
    const categorias = page.locator('#categorias');
    await categorias.getByText('Criar categoria').first().click();
    await categorias.getByLabel('Nome da categoria').fill('Aula do teste');
    await categorias.getByRole('button', { name: 'Criar categoria' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Aula do teste' })).toBeVisible();

    // a lista leva ao editar
    await page.locator('#categorias').getByRole('link', { name: /Aula do teste/ }).click();
    await expect(page).toHaveURL(/\/categorias\/\d+$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Editar categoria' })).toBeVisible();
    // gasto que varia: o vencimento só aparece para conta fixa ou pontual
    await expect(page.getByLabel('Vence no dia (opcional)')).toBeHidden();

    await page.getByLabel('Nome', { exact: true }).fill('Natação do teste');
    await page.getByLabel('Apelido (opcional)').fill('Natação');
    await page.getByText('Fixo todo mês (conta)').click();
    await page.getByLabel('Vence no dia (opcional)').fill('40');
    await page.getByText('Essencial', { exact: true }).click();
    await page.getByRole('button', { name: 'Salvar' }).click();

    // o erro volta junto do campo, com o que foi digitado
    const dia = page.getByLabel('Vence no dia (opcional)');
    await expect(dia).toHaveAttribute('aria-invalid', 'true');
    await expect(dia).toHaveAccessibleDescription('O dia do vencimento vai de 1 a 31.');
    await expect(page.getByLabel('Nome', { exact: true })).toHaveValue('Natação do teste');

    await dia.fill('12');
    await page.getByRole('button', { name: 'Salvar' }).click();
    await expect(page).toHaveURL(/\/ajustes\?aberto=categorias/);
    await expect(page.getByRole('status').filter({ hasText: 'Natação salva' })).toBeVisible();
    const item = page.locator('#categorias').getByRole('link', { name: /Natação/ });
    await expect(item).toContainText('Fixos · dia 12');

    // de volta ao editar: ficou tudo gravado; arquivar tira da lista
    await item.click();
    await expect(page.getByLabel('Vence no dia (opcional)')).toHaveValue('12');
    await expect(page.getByRole('radio', { name: 'Essencial', exact: true })).toBeChecked();
    await page.getByText('Arquivada', { exact: true }).click();
    await page.getByRole('button', { name: 'Salvar' }).click();
    await expect(page.locator('#categorias .aj-cat-arq')).toContainText('Natação');
    await expect(page.locator('#categorias > .ajs-corpo > .aj-cats').getByRole('link', { name: /Natação/ })).toHaveCount(0);
});

test('a categoria com lançamentos não troca entre entrada e saída', async ({ page }) => {
    await page.goto('/ajustes?aberto=categorias');
    await page.locator('#categorias').getByRole('link', { name: /Combustível/ }).click();
    await expect(page.getByRole('radio', { name: 'Entrada' })).toBeDisabled();
    await expect(page.getByRole('group', { name: 'Tipo' })).toHaveAccessibleDescription('Já tem lançamentos: trocar o tipo inverteria o histórico.');
    await expect(page.getByRole('link', { name: /Valor e recorrência/ })).toHaveAttribute('href', /^\/meta\?add=\d+#c\d+$/);
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('Contas fixas leva a Metas', async ({ page }) => {
        await page.goto('/ajustes');
        await expect(page.getByRole('link', { name: /Contas fixas/ })).toHaveAttribute('href', '/meta#contas-fixas');
    });
});

test('o seletor mostra os ícones de traço com nome, e o escolhido aparece na lista', async ({ page }) => {
    await page.goto('/ajustes?aberto=categorias');
    await page.locator('#categorias').getByRole('link', { name: /Combustível/ }).click();
    await page.getByText('Trocar o ícone').click();
    const seletor = page.getByRole('group', { name: 'Ícone' });
    // cada opção é um rádio com o nome do ícone; o desenho é o símbolo do sprite, sem emoji
    await expect(seletor.getByRole('radio', { name: 'Bomba de combustível' })).toBeChecked();
    await expect(seletor.locator('.ic-opcao svg.ci use')).toHaveCount(await seletor.getByRole('radio').count());
    await expect(seletor.locator('.ic-opcao').first().locator('svg.ci')).toHaveAttribute('aria-hidden', 'true');
    await seletor.locator('label.ic-opcao[title="Carro"]').click();         // clica no desenho, como a pessoa
    await expect(seletor.getByRole('radio', { name: 'Carro', exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Salvar' }).click();
    await expect(page).toHaveURL(/\/ajustes\?aberto=categorias/);
    const item = page.locator('#categorias').getByRole('link', { name: /Combustível/ });
    await expect(item.locator('svg.ci use')).toHaveAttribute('href', /^\/assets\/img\/categorias\.svg\?v=\d+#cat-carro$/);
});

test('as telas desenham o ícone de traço do sprite, sem o círculo e sem o emoji', async ({ page }) => {
    for (const url of ['/mes', '/lancamentos', '/ajustes?aberto=categorias']) {
        await page.goto(url);
        const icone = page.locator('svg.ci:visible').first();
        await expect(icone).toBeVisible();
        await expect(icone.locator('use')).toHaveAttribute('href', /categorias\.svg\?v=\d+#cat-[a-z]+$/);
        // o símbolo carregou (a CSP deixou) e tem desenho; o fundo é transparente, sem o círculo de antes
        await expect.poll(() => icone.evaluate((el) => el.querySelector('use').getBBox().width)).toBeGreaterThan(0);
        expect(await icone.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgba(0, 0, 0, 0)');
        expect(await page.locator('.ci > i, [style*="icones.webp"], [style*="--ic-sprite"]').count()).toBe(0);
    }
});
