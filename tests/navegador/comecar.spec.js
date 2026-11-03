// A primeira impressão numa instalação nova: criar a conta, o começo guiado (a prévia do mês se monta a cada
// resposta, com "adicionar outra conta fixa") e a chegada, com o primeiro "Dá para gastar". Cada teste sobe um
// servidor próprio, sem a demonstração: o começo guiado só acontece uma vez em cada instalação.
import { test as base, expect } from '@playwright/test';
import { subirServidor } from './servidor.js';

const test = base.extend({
    novo: [async ({}, use) => {
        const s = await subirServidor({ demo: false });
        await use(s);
        await s.parar();
    }, { timeout: 60_000 }],
    baseURL: async ({ novo }, use) => { await use(novo.url); },
});

/** Cria a primeira conta e chega ao 1º passo. */
async function criarConta(page) {
    await page.goto('/criar-conta');
    await page.getByLabel('Seu nome').fill('Teste');
    await page.getByLabel('Nome de usuário').fill('teste');
    await page.getByLabel('Senha', { exact: true }).fill('senha1234');
    await page.getByRole('button', { name: 'Criar conta' }).click();
    await expect(page).toHaveURL('/comecar');
}

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

test('o erro mantém o que foi digitado, e Voltar mostra o que já foi salvo sem zerar nada (A-02, A-09)', async ({ page }) => {
    await criarConta(page);
    const previa = page.getByRole('complementary', { name: /Novembro de 2026/ });

    // passo 1: o erro volta com o que foi digitado
    await page.getByLabel('Data de início').fill('2026-11-03');
    await page.getByLabel('Saldo da conta').fill('abc');
    await page.getByRole('button', { name: 'Continuar' }).click();
    await expect(page.getByRole('alert')).toContainText('Confira os valores');
    await expect(page.getByLabel('Saldo da conta')).toHaveValue('abc');
    await expect(page.getByLabel('Data de início')).toHaveValue('2026-11-03');
    await page.getByLabel('Saldo da conta').fill('1000');
    await page.getByText('Poupança e investimentos').click();
    await page.getByLabel('Poupança', { exact: true }).fill('500');
    await page.getByRole('button', { name: 'Continuar' }).click();

    // passo 2: o mesmo
    await page.getByLabel('Quanto entra por mês, em média').fill('3000');
    await page.getByLabel('Dia em que recebe').fill('32');
    await page.getByRole('button', { name: 'Continuar' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByLabel('Quanto entra por mês, em média')).toHaveValue('3000');
    await expect(page.getByLabel('Dia em que recebe')).toHaveValue('32');
    await page.getByLabel('Dia em que recebe').fill('28');
    await page.getByRole('button', { name: 'Continuar' }).click();
    await expect(page.locator('[aria-current="step"]')).toContainText('O que usa');

    // Voltar até o 1º: os campos mostram o que foi salvo
    await page.getByRole('link', { name: 'Voltar' }).click();
    await expect(page.getByLabel('Quanto entra por mês, em média')).toHaveValue('3.000,00');
    await expect(page.getByLabel('Dia em que recebe')).toHaveValue('28');
    await page.getByRole('link', { name: 'Voltar' }).click();
    await expect(page.getByLabel('Saldo da conta')).toHaveValue('1.000,00');
    await expect(page.getByLabel('Poupança', { exact: true })).toHaveValue('500,00');

    // Continuar sem redigitar não zera o saldo; nem com o campo apagado
    await page.getByLabel('Saldo da conta').fill('');
    await page.getByRole('button', { name: 'Continuar' }).click();
    await expect(previa.locator('[data-previa-linha="saldo"]')).toContainText('1.000,00');
    await page.getByRole('link', { name: 'Voltar' }).click();
    await expect(page.getByLabel('Poupança', { exact: true })).toHaveValue('500,00');
});

test('o 3º passo mostra o que vale, a prévia acompanha, e Pular grava o que está na tela (A-03)', async ({ page }) => {
    await criarConta(page);
    await page.getByLabel('Data de início').fill('2026-11-03');
    await page.getByLabel('Saldo da conta').fill('1000');
    await page.getByRole('button', { name: 'Continuar' }).click();
    // nada marcado ainda: a prévia não traz a fatura nem o seguro do carro
    const previa = page.getByRole('complementary', { name: /Novembro de 2026/ });
    await expect(previa).not.toContainText('Fatura do cartão');
    await expect(previa).not.toContainText('Seguro do carro');
    await page.getByRole('link', { name: 'Pular este passo' }).click();

    const cartao = page.getByRole('checkbox', { name: /Cartão de crédito/ });
    const emprestimo = page.getByRole('checkbox', { name: /Empréstimo ou financiamento/ });
    const carro = page.getByRole('checkbox', { name: /Carro/ });
    await expect(cartao).not.toBeChecked();
    await expect(emprestimo).not.toBeChecked();
    await expect(carro).not.toBeChecked();
    await expect(previa.locator('[data-previa-usos]')).toBeHidden();

    // marca o cartão e pula: grava o que a tela mostra
    await cartao.check();
    await expect(previa).toContainText('a aba Cartão');
    await page.getByRole('button', { name: 'Pular este passo' }).click();
    await expect(page.locator('[aria-current="step"]')).toContainText('Contas');
    await expect(previa).toContainText('Fatura do cartão');
    await page.getByRole('link', { name: 'Voltar' }).click();
    await expect(cartao).toBeChecked();
    await expect(emprestimo).not.toBeChecked();
    await expect(carro).not.toBeChecked();
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByRole('button', { name: 'Pular este passo' }).click();
    await expect(page).toHaveURL('/comecar?pronto=1');
    await expect(page.getByText('Informar os dados do empréstimo')).toHaveCount(0);
    await page.goto('/ajustes');
    await expect(page.locator('#recursos > summary')).toContainText('Cartão');
    await expect(page.locator('#recursos > summary')).not.toContainText('Empréstimo');
    await expect(page.locator('#recursos > summary')).not.toContainText('Carro');
});
