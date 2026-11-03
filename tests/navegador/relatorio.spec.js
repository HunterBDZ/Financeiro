// Relatório: o seletor de mês abre o relatório de outro mês, e o PDF baixa inteiro (o PDF já saiu vazio só no Firefox).
// A frase do topo não chama de falta um mês em que a casa guardou; na cascata, o guardado é neutro e o planejado,
// tracejado; o ano escolhe pelo seletor e abre sem clicar em Ver.
import { readFile } from 'node:fs/promises';
import { test, expect } from './fixtures.js';

test('o PDF mensal baixa e é um PDF de verdade', async ({ page }) => {
    await page.goto('/relatorio?tipo=mensal&mes=2026-11-01');
    await page.locator('.seletor-mes a[rel="prev"]').click();
    await expect(page).toHaveURL(/[?&]mes=2026-10-01/);

    const baixou = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Baixar PDF' }).click();
    await expect(page.getByRole('link', { name: 'Gerando o PDF…' })).toHaveAttribute('aria-busy', 'true');
    const arquivo = await baixou;
    expect(arquivo.suggestedFilename()).toMatch(/\.pdf$/);
    const pdf = await readFile(await arquivo.path());
    expect(pdf.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    expect(pdf.length).toBeGreaterThan(20_000);
    expect(pdf.subarray(-32).toString('latin1')).toContain('%%EOF');
});

test('a frase do topo e a cascata: guardar não é falta, o guardado é neutro, o planejado é tracejado', async ({ page }) => {
    // outubro na demonstração: a casa guardou 500 e a conta terminou 195,94 abaixo de onde começou
    await page.goto('/relatorio?tipo=mensal&mes=2026-10-01');
    const frase = page.locator('#rl-manchete');
    await expect(frase).toContainText('Guardou R$');
    await expect(frase).not.toContainText('Faltaram');
    const cascata = page.getByRole('table', { name: 'Como a conta chegou até aqui' });
    await expect(cascata.getByRole('rowheader', { name: 'Gastou' })).toBeVisible();
    await expect(cascata.getByRole('rowheader', { name: 'Saiu' })).toHaveCount(0);
    const guardou = cascata.locator('tr.is-guardado').first();
    await expect(guardou.locator('.num')).not.toHaveClass(/v-saida/);
    const corGasto = await cascata.locator('tr.is-gasto .rl-d-trilho i').evaluate((el) => getComputedStyle(el).backgroundColor);
    const corGuardado = await guardou.locator('.rl-d-trilho i').evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(corGuardado).not.toBe(corGasto);

    // novembro em andamento: o planejado tracejado, com a legenda
    await page.goto('/relatorio?tipo=mensal&mes=2026-11-01');
    const plan = page.locator('tr.is-plan .rl-d-trilho i').first();
    await expect(plan).toHaveCSS('border-top-style', 'dashed');
    await expect(page.locator('.rl-leg')).toContainText('planejado');
});

test('o relatório anual abre pelo ano, sem o botão Ver', async ({ page }) => {
    await page.goto('/relatorio?tipo=anual');
    await expect(page.getByRole('combobox', { name: 'Ano' })).toHaveValue('2026');
    await expect(page.locator('.rl-ver')).toBeHidden();
    await expect(page.getByText('Gastou', { exact: true })).toBeVisible();
});
