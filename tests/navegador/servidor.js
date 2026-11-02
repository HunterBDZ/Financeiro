// Sobe o Financeiro para os testes de navegador: um banco de demonstração novo numa pasta temporária e o servidor
// embutido do PHP (php -S), como no CONTRIBUTING. Cada processo de teste sobe o seu (fixtures.js), então um teste
// que troca o tema ou o idioma não atrapalha os outros processos.
// Sozinho, serve para olhar as telas com os mesmos dados: node servidor.js  (Ctrl+C para parar)
import { execFile, spawn } from 'node:child_process';
import { closeSync, openSync, readFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
// o PHP do PATH ou outro, em FIN_NAVEGADOR_PHP; precisa de pdo_sqlite e mbstring
const PHP = process.env.FIN_NAVEGADOR_PHP || 'php';
// o relógio fixo do app (só em linha de comando): com ele, o "hoje" da demonstração e das telas não muda
const RELOGIO = process.env.FIN_RELOGIO ?? '2026-11-22 15:30:00';

/**
 * Cria a pasta e o banco de demonstração, sobe o php -S e espera a tela de login responder.
 * porta: a preferida; ocupada ou 0, o sistema escolhe uma livre.
 * log() é a saída do php -S; logDoApp(), o log de erros do app (avisos do PHP e falhas registradas).
 * @returns {Promise<{url: string, pasta: string, log: () => string, logDoApp: () => string, parar: () => Promise<void>}>}
 */
export async function subirServidor({ porta = 0 } = {}) {
    const pasta = await mkdtemp(path.join(os.tmpdir(), 'financeiro-navegador-'));
    const env = { ...process.env, FIN_DADOS: pasta, FIN_RELOGIO: RELOGIO };
    const arqLog = path.join(pasta, 'php-s.log');
    const ler = (arq) => {
        try {
            return readFileSync(arq, 'utf8');
        } catch {
            return '';
        }
    };
    const log = () => ler(arqLog);
    const logDoApp = () => ler(path.join(pasta, 'log', 'financeiro.log'));
    try {
        await rodar(['database/instalar.php', '--demo'], env);
        // entre achar a porta livre e o PHP ocupá-la, outro programa pode chegar antes: tenta de novo
        for (let tentativa = 1; ; tentativa++) {
            const livre = await portaLivre(tentativa === 1 ? porta : 0);
            const fd = openSync(arqLog, 'a');
            const proc = spawn(PHP, ['-S', `127.0.0.1:${livre}`, '-t', 'public', 'tools/servir-dev.php'], { cwd: RAIZ, env, stdio: ['ignore', fd, fd] });
            closeSync(fd);
            proc.on('error', () => {});     // aparece como saída antes da hora, em esperarPronto
            const matar = () => proc.kill('SIGKILL');
            process.once('exit', matar);    // o processo de teste caiu: o servidor não fica órfão
            const url = `http://127.0.0.1:${livre}`;
            try {
                await esperarPronto(url, proc, log);
            } catch (e) {
                matar();
                process.removeListener('exit', matar);
                if (tentativa < 3 && /Address already in use/i.test(log())) {
                    continue;
                }
                throw e;
            }
            return {
                url,
                pasta,
                log,
                logDoApp,
                async parar() {
                    process.removeListener('exit', matar);
                    if (proc.exitCode === null && proc.signalCode === null) {
                        const saiu = new Promise((ok) => proc.once('exit', ok));
                        proc.kill('SIGTERM');
                        await Promise.race([saiu, espera(3000)]);
                        matar();
                    }
                    await rm(pasta, { recursive: true, force: true });
                },
            };
        }
    } catch (e) {
        await rm(pasta, { recursive: true, force: true });
        throw e;
    }
}

function rodar(args, env) {
    return new Promise((ok, falha) => {
        execFile(PHP, args, { cwd: RAIZ, env, timeout: 60_000 }, (erro, saida, erros) => {
            if (erro) {
                falha(new Error(erro.code === 'ENOENT'
                    ? `Não achei o PHP (${PHP}). Instale o PHP 8.4 com pdo_sqlite e mbstring, ou aponte FIN_NAVEGADOR_PHP para ele.`
                    : `php ${args.join(' ')} falhou:\n${saida}${erros}`));
                return;
            }
            ok(saida);
        });
    });
}

function portaLivre(preferida) {
    return new Promise((ok, falha) => {
        const s = net.createServer();
        s.unref();
        s.once('error', (e) => (preferida !== 0 && e.code === 'EADDRINUSE' ? portaLivre(0).then(ok, falha) : falha(e)));
        s.listen(preferida, '127.0.0.1', () => {
            const { port } = s.address();
            s.close(() => ok(port));
        });
    });
}

async function esperarPronto(url, proc, log) {
    const limite = Date.now() + 15_000;
    while (Date.now() < limite) {
        if (proc.exitCode !== null || proc.signalCode !== null) {
            throw new Error(`O php -S parou ao subir:\n${log()}`);
        }
        try {
            const r = await fetch(url + '/login', { redirect: 'manual' });
            if (r.status === 200) {
                return;
            }
        } catch {
            // ainda não está ouvindo
        }
        await espera(100);
    }
    throw new Error(`O php -S não respondeu em 15 s:\n${log()}`);
}

function espera(ms) {
    return new Promise((ok) => setTimeout(ok, ms));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const servidor = await subirServidor({ porta: Number(process.env.FIN_NAVEGADOR_PORTA) || 0 });
    console.log(`Financeiro em ${servidor.url} (ana / demo1234). Dados em ${servidor.pasta}. Ctrl+C para parar.`);
    process.once('SIGINT', async () => {
        await servidor.parar();
        process.exit(0);
    });
}
