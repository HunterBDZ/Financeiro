// Acessibilidade básica: a varredura do axe nas telas principais, nos temas Claro e Escuro. Só reprova o que o axe
// classifica como grave (serious) ou crítico (critical). Roda só no Chromium (playwright.config.js).
import AxeBuilder from '@axe-core/playwright';
import { test, expect, escolherTema, TELAS } from './fixtures.js';

// Violações reais que o app ainda tem: [regra do axe, elemento]. As varreduras das telas ignoram só esse elemento
// nessa regra, para pegar qualquer violação nova. Se aparecer uma, ponha aqui e crie um teste "esperado falhar".
const CONHECIDAS = [];

/**
 * As violações graves da página: "regra (impacto): elemento, elemento". Sem `regras`, roda todas e deixa de fora as
 * conhecidas; com `regras`, roda só essas e não deixa nada de fora.
 */
async function graves(page, regras = null) {
    const axe = new AxeBuilder({ page });
    if (regras) {
        axe.withRules(regras);
    }
    const { violations } = await axe.analyze();
    return violations
        .filter((v) => v.impact === 'serious' || v.impact === 'critical')
        .map((v) => [v, v.nodes.map((n) => n.target.join(' ')).filter((alvo) => regras || !CONHECIDAS.some(([r, re]) => r === v.id && re.test(alvo)))])
        .filter(([, alvos]) => alvos.length > 0)
        .map(([v, alvos]) => `${v.id} (${v.impact}): ${alvos.join(', ')}`);
}

for (const tema of ['claro', 'escuro']) {
    test.describe(`tema ${tema}`, () => {
        test.beforeEach(async ({ page, baseURL }) => {
            await escolherTema(page, baseURL, tema);
        });
        test.afterEach(async ({ page, baseURL }) => {
            await escolherTema(page, baseURL, 'auto');
        });

        for (const [nome, caminho] of TELAS) {
            test(nome, async ({ page }) => {
                await page.goto(caminho);
                expect(await graves(page)).toEqual([]);
            });
        }

        test('privacidade', async ({ page }) => {
            await page.goto('/privacidade');
            expect(await graves(page)).toEqual([]);
        });

        test('diálogo de lançar', async ({ page }) => {
            await page.goto('/');
            await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: 'Lançar' }).click();
            await expect(page.getByRole('dialog', { name: 'lançar' })).toBeVisible();
            expect(await graves(page)).toEqual([]);
        });

        test.describe('no celular', () => {
            test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

            test('Início, com a barra de baixo e o menu aberto', async ({ page }) => {
                await page.goto('/');
                expect(await graves(page)).toEqual([]);
                await page.getByRole('banner').getByText('Menu', { exact: true }).click();
                await expect(page.getByRole('banner').getByRole('link', { name: 'Ajustes' })).toBeVisible();
                expect(await graves(page)).toEqual([]);
            });

            test('menu do botão Menu no modo organizar', async ({ page }) => {
                await page.goto('/');
                await page.getByRole('banner').getByText('Menu', { exact: true }).click();
                await page.getByRole('banner').getByRole('button', { name: 'Organizar menu' }).click();
                await expect(page.getByRole('banner').getByRole('button', { name: 'Pronto' })).toBeVisible();
                expect(await graves(page)).toEqual([]);
            });
        });

        test('menu lateral no modo organizar', async ({ page }) => {
            await page.goto('/');
            await page.getByRole('complementary', { name: 'menu' }).getByRole('button', { name: 'Organizar menu' }).click();
            await expect(page.getByRole('complementary', { name: 'menu' }).getByRole('button', { name: 'Pronto' })).toBeVisible();
            expect(await graves(page)).toEqual([]);
        });
    });

    test.describe(`tema ${tema}, fora da conta`, () => {
        test.use({ logado: false, colorScheme: tema === 'escuro' ? 'dark' : 'light' });

        test('Login', async ({ page }) => {
            await page.goto('/login');
            expect(await graves(page)).toEqual([]);
        });
    });
}

test.describe('violações já corrigidas não voltam', () => {
    test.afterEach(async ({ page, baseURL }) => {
        await escolherTema(page, baseURL, 'auto');
    });

    test('o <html> não recebe aria-pressed quando a conta tem tema', async ({ page, baseURL }) => {
        await escolherTema(page, baseURL, 'claro');
        await page.goto('/');
        expect(await graves(page, ['aria-allowed-attr'])).toEqual([]);
    });

    test('o seletor de mês tem no nome o texto que mostra', async ({ page }) => {
        await page.goto('/mes');
        expect(await graves(page, ['label-content-name-mismatch'])).toEqual([]);
    });

    test('a escada de faturas tem no nome o texto que mostra', async ({ page }) => {
        await page.goto('/cartao');
        expect(await graves(page, ['label-content-name-mismatch'])).toEqual([]);
    });
});
