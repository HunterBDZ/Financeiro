// Verificação em duas etapas: ligar em Ajustes, sair, entrar com o código do app e desligar com um código de
// recuperação. Usa a conta bruno, para não mexer na ana, que os outros testes do mesmo processo usam. O código é
// calculado aqui, como o app autenticador faria (TOTP da RFC 6238), no relógio da máquina.
import { createHmac } from 'node:crypto';
import { test, expect, SENHA } from './fixtures.js';

test.use({ logado: false });

const LOGIN = 'bruno';

function deBase32(texto) {
    const letras = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of texto.replace(/[\s=]/g, '').toUpperCase()) {
        bits += letras.indexOf(c).toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

/** O código de 6 dígitos de um passo de 30 s; desloca = quantos passos depois de agora. */
function totp(segredo, desloca = 0) {
    const passo = Math.floor(Date.now() / 30000) + desloca;
    const contador = Buffer.alloc(8);
    contador.writeBigUInt64BE(BigInt(passo));
    const mac = createHmac('sha1', segredo).update(contador).digest();
    const i = mac[mac.length - 1] & 0x0f;
    const n = ((mac[i] & 0x7f) << 24) | (mac[i + 1] << 16) | (mac[i + 2] << 8) | mac[i + 3];
    return String(n % 1_000_000).padStart(6, '0');
}

async function entrarComSenha(page) {
    await page.goto('/login');
    await page.getByLabel('Login').fill(LOGIN);
    await page.getByLabel('Senha').fill(SENHA);
    await page.getByRole('button', { name: 'Entrar' }).click();
}

test('liga, entra com o código e desliga', async ({ page }) => {
    await entrarComSenha(page);
    await expect(page).toHaveURL('/');

    // ligar: o QR, a chave para digitar e o código de confirmação
    await page.goto('/ajustes?aberto=duasetapas');
    const grupo = page.locator('#duasetapas');
    await expect(grupo).toContainText('Desligada');
    await grupo.getByRole('link', { name: 'Ligar' }).click();
    await expect(page).toHaveURL('/ajustes/duas-etapas');
    await expect(page.getByRole('img', { name: 'Código QR para o app autenticador' })).toBeVisible();
    await page.getByText('Não dá para usar a câmera?').click();
    const segredo = deBase32(await page.locator('.de-chave').innerText());
    expect(segredo.length).toBe(20);
    await page.getByLabel('Código de 6 dígitos que o app mostra').fill(totp(segredo));
    await page.getByRole('button', { name: 'Ligar' }).click();

    // os códigos de recuperação aparecem uma vez
    await expect(page).toHaveURL('/ajustes/duas-etapas/codigos');
    const codigos = await page.locator('.de-lista li').allInnerTexts();
    expect(codigos).toHaveLength(10);
    await page.getByRole('link', { name: 'Já guardei' }).click();
    await expect(page.locator('#duasetapas')).toContainText('Ligada');
    await expect(page.locator('#duasetapas')).toContainText('Restam 10 códigos de recuperação.');

    // sair e entrar: depois da senha, o código
    await page.getByRole('button', { name: 'sair' }).click();
    await expect(page).toHaveURL('/login');
    await entrarComSenha(page);
    await expect(page).toHaveURL('/login/codigo');
    await page.goto('/');                                   // sem o código, nada abre
    await expect(page).toHaveURL(/\/login/);
    await page.goto('/login/codigo');
    // o passo seguinte: o de agora pode ter sido o usado para ligar, e um código não vale duas vezes
    await page.getByLabel('Código de 6 dígitos', { exact: true }).fill(totp(segredo, 1));
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Bruno' })).toBeVisible();

    // desligar com a senha e um código de recuperação
    await page.goto('/ajustes?aberto=duasetapas');
    const ligada = page.locator('#duasetapas');
    await ligada.getByLabel('Senha', { exact: true }).fill(SENHA);
    await ligada.getByLabel('Código do app ou de recuperação').fill(codigos[0]);
    await ligada.getByRole('button', { name: 'Desligar' }).click();
    await expect(page.locator('#duasetapas')).toContainText('Desligada');

    // sem a verificação, a senha basta de novo
    await page.getByRole('button', { name: 'sair' }).click();
    await entrarComSenha(page);
    await expect(page).toHaveURL('/');
});
