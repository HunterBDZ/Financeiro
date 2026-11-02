// Relatório: escolher o mês já abre o relatório dele, e o PDF baixa inteiro. O PDF já saiu vazio só no Firefox.
import { readFile } from 'node:fs/promises';
import { test, expect } from './fixtures.js';

test('o PDF mensal baixa e é um PDF de verdade', async ({ page }) => {
    await page.goto('/relatorio');
    const mes = page.getByRole('combobox', { name: 'Mês' });
    const outro = await mes.locator('option').nth(1).getAttribute('value');
    await mes.selectOption(outro);           // sem clicar em Ver: relatorio.js já envia
    await expect(page).toHaveURL(new RegExp(`[?&]mes=${outro}`));

    const baixou = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Baixar PDF' }).click();
    const arquivo = await baixou;
    expect(arquivo.suggestedFilename()).toMatch(/\.pdf$/);
    const pdf = await readFile(await arquivo.path());
    expect(pdf.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    expect(pdf.length).toBeGreaterThan(20_000);
    expect(pdf.subarray(-32).toString('latin1')).toContain('%%EOF');
});
