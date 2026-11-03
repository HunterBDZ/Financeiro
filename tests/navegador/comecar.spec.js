// A primeira impressão numa instalação nova: criar a conta, o começo guiado (a prévia do mês se monta a cada
// resposta, com "adicionar outra conta fixa") e a chegada, com o primeiro "Dá para gastar". Sobe um servidor próprio,
// sem a demonstração: os outros testes usam a conta da ana.
import { test as base, expect } from '@playwright/test';
import { subirServidor } from './servidor.js';

const test = base.extend({
    novo: [async ({}, use) => {
        const s = await subirServidor({ demo: false });
        await use(s);
        await s.parar();
    }, { scope: 'worker', timeout: 60_000 }],
    baseURL: async ({ novo }, use) => { await use(novo.url); },
});

test('cria a conta, monta o mês na prévia e chega ao primeiro "Dá para gastar"', async ({ page }) => {
    const erros = [];
    page.on('pageerror', (e) => erros.push(e.message));

    await page.goto('/');
    await expect(page).toHaveURL('/criar-conta');
    await expect(page.getByRole('heading', { level: 1, name: 'Falta só a sua conta' })).toBeVisible();
    await expect(page.getByText('Pelo menos 8 caracteres.')).toBeVisible();          // a regra da senha fica à vista
    await page.getByLabel('Seu nome').fill('Teste');
    await page.getByLabel('Nome de usuário').fill('teste');
    await page.getByLabel('Senha', { exact: true }).fill('senha1234');
    await page.getByRole('button', { name: 'Mostrar a senha' }).click();
    await expect(page.getByLabel('Senha', { exact: true })).toHaveAttribute('type', 'text');
    await page.getByRole('button', { name: 'Criar conta' }).click();

    // passo 1: a prévia mostra o saldo enquanto se digita
    await expect(page).toHaveURL('/comecar');
    const previa = page.getByRole('complementary', { name: /Novembro de 2026/ });
    await expect(previa).toContainText('a responder');
    await page.getByLabel('Data de início').fill('2026-11-03');
    await page.getByLabel('Saldo da conta').fill('2.400,50');
    await expect(previa.locator('[data-previa-total]')).toHaveText('R$2.400,50');
    await page.getByRole('button', { name: 'Continuar' }).click();

    // passo 2: o que entra
    await expect(page.locator('[aria-current="step"]')).toContainText('Renda');
    await page.getByLabel('Quanto entra por mês, em média').fill('5.200');
    await page.getByLabel('Dia em que recebe').fill('5');
    await expect(previa.locator('[data-previa-total]')).toHaveText('R$7.600,50');
    await page.getByRole('button', { name: 'Continuar' }).click();

    // passo 3: cada opção diz o que liga
    await expect(page.getByText('Mostra a aba Cartão, com a fatura do mês e as próximas.')).toBeVisible();
    await page.getByRole('button', { name: 'Continuar' }).click();

    // passo 4: a regra do início no meio do mês acontece na prévia, sem texto de aviso
    await expect(page.getByText(/antes do dia \d/)).toHaveCount(0);
    await page.getByLabel('Valor de Aluguel').fill('1.800');
    await page.getByLabel('Dia de vencimento de Aluguel').fill('10');
    await page.getByLabel('Valor de Luz').fill('230');
    await page.getByLabel('Dia de vencimento de Luz').fill('2');
    await expect(previa.locator('[data-previa-linha="' + await page.getByLabel('Valor de Luz').getAttribute('data-previa-campo') + '"]')).toContainText('já no saldo');
    await page.getByLabel('Nome da outra conta').fill('Água');
    await page.getByLabel('Valor da outra conta').fill('95,30');
    await page.getByLabel('Dia de vencimento da outra conta').fill('15');
    await page.getByRole('button', { name: 'Adicionar outra conta fixa' }).click();
    await expect(page.getByLabel('Nome da outra conta')).toHaveCount(2);
    await expect(previa).toContainText('Água');
    await expect(previa.locator('[data-previa-total]')).toHaveText('R$5.705,20');
    await page.getByRole('button', { name: 'Concluir' }).click();

    // a chegada: o mesmo número, agora calculado pelo app
    await expect(page).toHaveURL('/comecar?pronto=1');
    await expect(page.getByRole('heading', { level: 2, name: 'Dá para gastar até dia 30' })).toBeVisible();
    await expect(page.locator('.com-chegada .resposta-num')).toHaveText('R$5.705,20');
    await page.getByRole('link', { name: 'Abrir o Início' }).click();
    await expect(page).toHaveURL('/');

    // a conta nova virou uma categoria de conta fixa
    await page.goto('/ajustes?aberto=categorias#categorias');
    await expect(page.locator('#categorias')).toContainText('Água');
    expect(erros).toEqual([]);
});
