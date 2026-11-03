// Importar extrato: um CSV do Extrato até o lançamento, e o lote desfeito depois. Tem servidor e banco só seus: as
// linhas importadas mudariam as telas dos outros testes do mesmo processo.
import AxeBuilder from '@axe-core/playwright';
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

// as violações graves ou críticas do axe na página
async function graves(page) {
    const { violations } = await new AxeBuilder({ page }).analyze();
    return violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

const CSV = 'Data;Histórico;Valor;Saldo\n03/11/2026;PADARIA DA ESQUINA;-12,50;100,00\n05/11/2026;Mercado do bairro;-1.234,56;87,50\n';

test('importa um CSV, revisa, lança todos e desfaz', async ({ page, context }) => {
    await entrar(context.request, srv.url);
    await page.goto(srv.url + '/lancamentos?mes=2026-11-01');
    // Importar e a planilha ficam no ⋯ do topo do Extrato
    await page.getByRole('main').locator('.ext-menu > summary').click();
    await page.getByRole('link', { name: 'Importar extrato' }).first().click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Importar extrato');
    expect(await graves(page)).toEqual([]);

    // o arquivo vai para a conta; o CSV pede as colunas na primeira vez, já com o palpite
    await page.locator('input[name="arquivo"]').setInputFiles({ name: 'extrato.csv', mimeType: 'text/csv', buffer: Buffer.from(CSV, 'utf8') });
    await page.getByRole('button', { name: 'Continuar' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Ligar as colunas');
    await expect(page.getByRole('combobox', { name: /^Valor/ })).toHaveValue('2');
    expect(await graves(page)).toEqual([]);
    await page.getByRole('button', { name: 'Continuar' }).click();

    // a revisão: duas linhas marcadas; "1.234,56" é mil duzentos e trinta e quatro
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Revisar importação');
    await expect(page.getByText('2 para lançar')).toBeVisible();
    await expect(page.locator('.imp-tabela')).toContainText('1.234,56');
    expect(await graves(page)).toEqual([]);
    for (const desc of ['PADARIA DA ESQUINA', 'Mercado do bairro']) {
        const cat = page.getByRole('combobox', { name: 'Categoria de ' + desc });
        if ((await cat.inputValue()) === '') {
            await cat.selectOption({ index: 1 });
        }
    }
    await page.getByRole('button', { name: 'Lançar todos' }).click();
    await expect(page.getByRole('status').filter({ hasText: '2 lançamentos importados' })).toBeVisible();
    await expect(page.locator('.ext-tabela')).toContainText('PADARIA DA ESQUINA');
    await expect(page.locator('.ext-tabela')).toContainText('1.234,56');

    // desfazer, com confirmação, pela lista das últimas importações
    await page.goto(srv.url + '/importar');
    page.once('dialog', (d) => d.accept());
    await page.locator('#imp-hist').getByRole('button', { name: 'Desfazer importação' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Importação desfeita' })).toContainText('2 linhas apagadas');
    await page.goto(srv.url + '/lancamentos?mes=2026-11-01');
    await expect(page.locator('main')).not.toContainText('PADARIA DA ESQUINA');
    expect(srv.logDoApp(), 'avisos ou erros do PHP').toBe('');
});
