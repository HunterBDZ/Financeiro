// Lançar um gasto: no computador (o diálogo, também no Extrato) e no celular (o "+" da barra de baixo); as usadas
// por último, o valor sugerido das contas fixas, o erro no campo, Conta e Cartão em abas e o aviso sem verde.
// Cada teste usa valor e categoria só seus: lançamento igual no mesmo dia pede confirmação antes de entrar.
import { test, expect, escolherCategoria, contaSemPagamento } from './fixtures.js';

test.describe('no computador', () => {
    test('lança 45 no Extrato, vê o aviso e desfaz', async ({ page }) => {
        await page.goto('/lancamentos');
        // o Extrato não tem mais o formulário na tela: o Lançar do menu abre o diálogo, como nas outras telas
        await expect(page.getByRole('complementary', { name: 'lançar' })).toHaveCount(0);
        await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: 'Lançar' }).click();
        const form = page.getByRole('dialog', { name: 'lançar' });
        const valor = form.getByRole('textbox', { name: 'valor' });
        await expect(valor).toBeFocused();

        await valor.pressSequentially('45');
        await valor.blur();
        await expect(valor).toHaveValue('45,00');      // o valor é lido como se fala: 45 é R$ 45,00
        await escolherCategoria(form, 'Mercado');
        await form.getByLabel('descrição', { exact: true }).fill('Feira do teste');
        await form.getByRole('button', { name: 'Lançar' }).click();

        const aviso = page.getByRole('status').filter({ hasText: 'Mercado' });
        await expect(aviso).toContainText('45,00');
        // a linha do livro-razão: a descrição leva ao editar, o valor fica na coluna dele
        const linha = page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Feira do teste' }) });
        await expect(linha).toContainText('45,00');

        await aviso.getByRole('button', { name: 'desfazer' }).click();
        await expect(page.getByRole('status').filter({ hasText: 'excluído' })).toContainText('45,00');
        await expect(linha).toHaveCount(0);
    });

    test('a categoria é um campo só: abre a lista ao clicar, filtra ao digitar e escolhe pelo teclado', async ({ page }) => {
        await page.goto('/');
        await page.keyboard.press('n');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        const campo = dialogo.getByRole('combobox', { name: 'Categoria' });
        const lista = dialogo.getByRole('listbox', { name: 'Categoria' });
        await expect(dialogo.locator('.lf-chip, [data-lf-mais]')).toHaveCount(0);   // sem os botões das mais usadas
        await expect(lista).toBeHidden();

        // clicar abre a lista inteira, agrupada, com ícone em cada opção
        await campo.click();
        await expect(campo).toHaveAttribute('aria-expanded', 'true');
        const todas = await lista.getByRole('option').count();
        expect(todas).toBeGreaterThan(8);
        await expect(lista.getByRole('group', { name: 'essenciais', exact: true })).toBeVisible();
        await expect(lista.getByRole('option').first().locator('.ci')).toBeVisible();

        // digitar filtra, sem acento e sem caixa; as setas andam pela lista
        await campo.pressSequentially('FARMA');
        await expect(lista.getByRole('option')).not.toHaveCount(todas);
        await expect(lista.getByRole('option').first()).toContainText('Farmácia');
        await campo.fill('');
        await campo.pressSequentially('a');
        const visiveis = lista.getByRole('option');
        await expect(campo).toHaveAttribute('aria-activedescendant', await visiveis.nth(0).getAttribute('id'));
        await campo.press('ArrowDown');
        await campo.press('ArrowDown');
        await expect(campo).toHaveAttribute('aria-activedescendant', await visiveis.nth(2).getAttribute('id'));
        await campo.press('ArrowUp');
        await expect(campo).toHaveAttribute('aria-activedescendant', await visiveis.nth(1).getAttribute('id'));

        // Esc fecha só a lista, não o diálogo, e volta ao que estava escolhido (nada)
        await campo.press('Escape');
        await expect(campo).toHaveAttribute('aria-expanded', 'false');
        await expect(dialogo).toBeVisible();
        await expect(campo).toHaveValue('');

        // Enter escolhe a opção em destaque e não envia o lançamento
        await campo.pressSequentially('merc');
        await campo.press('Enter');
        await expect(campo).toHaveValue('Mercado');
        await expect(campo).toHaveAttribute('aria-expanded', 'false');
        await expect(dialogo).toBeVisible();
        const escolhida = dialogo.locator('[role="option"][aria-selected="true"]');   // a lista fechada some da árvore de acessibilidade
        await expect(escolhida).toHaveText('Mercado');
        await expect(dialogo.locator('select[name="categoria_id"]')).toHaveValue(await escolhida.getAttribute('data-value'));
    });

    test('as 3 categorias usadas por último escolhem num toque', async ({ page }) => {
        await page.goto('/');
        await page.keyboard.press('n');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        const recentes = dialogo.getByRole('group', { name: 'Usadas por último' }).getByRole('button');
        const n = await recentes.count();
        expect(n).toBeGreaterThan(0);
        expect(n).toBeLessThanOrEqual(3);
        const nome = (await recentes.first().textContent()).trim();
        await recentes.first().click();
        await expect(dialogo.getByRole('combobox', { name: 'Categoria' })).toHaveValue(nome);
        await expect(recentes.first()).toHaveAttribute('aria-pressed', 'true');
    });

    test('a conta fixa sugere o valor previsto, e o que a pessoa digita fica', async ({ page, baseURL }) => {
        await contaSemPagamento(page, baseURL, 'Luz');
        await page.goto('/');
        await page.keyboard.press('n');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        const valor = dialogo.getByRole('textbox', { name: 'valor' });
        await valor.fill('');
        await escolherCategoria(dialogo, 'Luz');
        await expect(valor).toHaveValue('238,40');
        await expect(dialogo.getByText('O previsto para novembro')).toBeVisible();
        await valor.fill('');
        await valor.pressSequentially('250');
        await escolherCategoria(dialogo, 'Internet');
        await expect(valor).toHaveValue('250,00');           // formatado ao sair do campo, e não trocado pela sugestão
    });

    test('o erro aparece no próprio campo', async ({ page }) => {
        await page.goto('/');
        await page.keyboard.press('n');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        const valor = dialogo.getByRole('textbox', { name: 'valor' });
        await valor.fill('');
        await dialogo.getByRole('button', { name: 'Lançar' }).click();
        await expect(valor).toHaveAttribute('aria-invalid', 'true');
        await expect(valor).toHaveAccessibleDescription('Digite o valor.');
        await expect(valor).toBeFocused();
        await valor.pressSequentially('12');
        await expect(valor).not.toHaveAttribute('aria-invalid', 'true');
        await dialogo.getByRole('button', { name: 'Lançar' }).click();
        const campo = dialogo.getByRole('combobox', { name: 'Categoria' });
        await expect(campo).toHaveAttribute('aria-invalid', 'true');
        await expect(campo).toHaveAccessibleDescription('Escolha a categoria.');
        await expect(dialogo.locator('[data-lf-erro]')).toBeHidden();
    });

    test('Conta e Cartão em abas; o aviso de lançado fica em tinta, sem verde', async ({ page }) => {
        await page.goto('/');
        await page.keyboard.press('n');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        const abas = dialogo.locator('.ct-pagou');
        await expect(abas.getByRole('radio', { name: 'Conta' })).toBeChecked();
        await abas.getByText('Cartão Exemplo').click();
        await expect(dialogo.getByText('Em quantas vezes?')).toBeVisible();
        await abas.getByText('Conta', { exact: true }).click();
        await expect(dialogo.getByText('Em quantas vezes?')).toBeHidden();
        // o sublinhado azul da aba escolhida é um traço só (::after), que desliza de uma aba para a outra (movimento.js)
        const sublinhado = await abas.locator('label:has(input:checked)').evaluate((el) => {
            const d = getComputedStyle(el, '::after');
            const prova = document.createElement('i');
            prova.style.backgroundColor = 'var(--azul)';
            el.append(prova);
            const azul = getComputedStyle(prova).backgroundColor;
            prova.remove();
            return { altura: d.height, cor: d.backgroundColor, azul };
        });
        expect(sublinhado.altura).toBe('2px');
        expect(sublinhado.cor).toBe(sublinhado.azul);

        await dialogo.getByRole('textbox', { name: 'valor' }).fill('');
        await dialogo.getByRole('textbox', { name: 'valor' }).pressSequentially('19');
        await escolherCategoria(dialogo, 'Farmácia');
        await dialogo.getByLabel('descrição', { exact: true }).fill('Aviso do teste');
        await dialogo.getByRole('button', { name: 'Lançar' }).click();
        const aviso = page.getByRole('status').filter({ hasText: 'Farmácia' });
        await expect(aviso).toContainText('19,00');
        const cores = await aviso.evaluate((el) => {
            const tinta = getComputedStyle(document.documentElement).getPropertyValue('--tinta').trim();
            const entrada = getComputedStyle(document.documentElement).getPropertyValue('--entrada').trim();
            const prova = document.createElement('i');
            document.body.append(prova);
            prova.style.color = tinta;
            const rgbTinta = getComputedStyle(prova).color;
            prova.style.color = entrada;
            const rgbEntrada = getComputedStyle(prova).color;
            prova.remove();
            const usadas = [el, ...el.querySelectorAll('*')].flatMap((n) => [getComputedStyle(n).color, getComputedStyle(n).backgroundColor]);
            return { fundo: getComputedStyle(el).backgroundColor, rgbTinta, verde: usadas.includes(rgbEntrada) };
        });
        expect(cores.fundo).toBe(cores.rgbTinta);
        expect(cores.verde).toBe(false);
        await aviso.getByRole('button', { name: 'desfazer' }).click();
        await expect(page.getByRole('status').filter({ hasText: 'excluído' })).toContainText('19,00');
    });

    test('o diálogo de lançar abre pelo menu e pela tecla n, e fecha', async ({ page }) => {
        await page.goto('/');
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        await expect(dialogo).toBeHidden();

        await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: 'Lançar' }).click();
        await expect(dialogo).toBeVisible();
        await expect(dialogo.getByRole('textbox', { name: 'valor' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialogo).toBeHidden();

        await page.keyboard.press('n');
        await expect(dialogo).toBeVisible();
        await dialogo.getByRole('button', { name: 'fechar' }).click();
        await expect(dialogo).toBeHidden();
    });
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('o "+" fica no meio da barra de baixo, abre o formulário e o lançamento entra', async ({ page }) => {
        await page.goto('/');
        const barra = page.getByRole('navigation', { name: 'principal' });
        const itens = barra.getByRole('link');
        await expect(itens).toHaveCount(4);                 // Início, Extrato, o +, Mês; o Mais é um botão que abre o resto
        await expect(barra.getByText('Mais', { exact: true })).toBeVisible();
        const mais = itens.nth(2);
        await expect(mais).toHaveAccessibleName('lançar');
        const caixa = await mais.boundingBox();
        expect(Math.abs(caixa.x + caixa.width / 2 - 390 / 2)).toBeLessThan(8);

        await mais.tap();
        const dialogo = page.getByRole('dialog', { name: 'lançar' });
        await expect(dialogo).toBeVisible();
        await dialogo.getByRole('textbox', { name: 'valor' }).pressSequentially('37');
        await escolherCategoria(dialogo, 'Lazer');
        await dialogo.getByLabel('descrição', { exact: true }).fill('Cinema do teste');
        await dialogo.getByRole('button', { name: 'Lançar' }).tap();

        await expect(dialogo).toBeHidden();
        await expect(page.getByRole('status').filter({ hasText: 'Lazer' })).toContainText('37,00');
        await barra.getByRole('link', { name: 'Extrato' }).tap();
        await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: /Cinema do teste/ }) })).toContainText('37,00');
    });
});
