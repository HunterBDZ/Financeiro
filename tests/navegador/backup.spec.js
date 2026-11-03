// Ajustes → Backup e atualização: fazer o backup mostra a confirmação, e a cópia baixa depois de pedir a senha.
import { readFile } from 'node:fs/promises';
import { test, expect, SENHA } from './fixtures.js';

test('faz o backup e baixa a cópia com a senha', async ({ page }) => {
    await page.goto('/ajustes');
    await page.getByRole('link', { name: 'Backup e atualização' }).click();
    await expect(page).toHaveURL('/sistema');

    await page.getByRole('button', { name: 'Fazer backup agora' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Backup feito.' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Backup' })).toContainText('feito por você');

    const baixar = page.getByRole('group').filter({ hasText: 'Baixar uma cópia' });
    await baixar.getByText('Baixar uma cópia').click();
    await baixar.getByLabel('A sua senha').fill(SENHA);
    const baixou = page.waitForEvent('download');
    await baixar.getByRole('button', { name: 'Baixar cópia' }).click();
    // enquanto o servidor prepara, o botão avisa e não aceita outro clique; depois do download, volta ao normal
    const esperando = baixar.getByRole('button', { name: 'Preparando a cópia…' });
    await expect(esperando).toHaveAttribute('aria-busy', 'true');
    const arquivo = await baixou;
    await expect(baixar.getByRole('button', { name: 'Baixar cópia' })).toBeVisible({ timeout: 10_000 });
    expect(arquivo.suggestedFilename()).toMatch(/^financeiro-backup-\d{4}-\d{2}-\d{2}\.sqlite$/);
    const copia = await readFile(await arquivo.path());
    expect(copia.subarray(0, 16).toString('latin1')).toBe('SQLite format 3\0');
});
