<?php
namespace Financeiro\Pdf;

use Financeiro\Idioma;
use Financeiro\Relogio;
use Financeiro\Services\RelatorioDados;
use Financeiro\SiteIcone;

/**
 * Desenha os relatórios a partir dos fatos de RelatorioDados. Sempre claro, seja qual for o tema, porque é
 * um documento para ler, imprimir e enviar. O centro da página 1 é a escada do saldo: cada linha move a
 * anterior e o total fecha no centavo. Nada de nome de usuário: o arquivo pode sair do app.
 */
final class RelatorioPdf
{
    private const MARGEM = 44.0;
    private const LARG = 507.28;
    private const TOPO = 62.0;
    private const FUNDO = 786.0;

    private const TINTA = [0.067, 0.078, 0.106];
    private const GRAFITE = [0.345, 0.376, 0.439];
    private const LINHA = [0.867, 0.882, 0.906];
    private const NEVOA = [0.953, 0.957, 0.965];
    private const LIMA = [0.784, 0.961, 0.314];
    private const ENTRA = [0.071, 0.475, 0.659];
    private const SAI = [0.824, 0.376, 0.039];
    private const POS = [0.078, 0.424, 0.263];
    private const NEG = [0.702, 0.149, 0.118];
    private const BRANCO = [1, 1, 1];

    private const MED = 'manrope-500';
    private const BOLD = 'manrope-700';
    private const DISP = 'sora-600';

    private Documento $d;
    private float $y = self::TOPO;

    public function __construct(private array $r)
    {
        $agora = Relogio::agora();     // no fuso do site, que vai junto: -03'00'
        $this->d = new Documento(
            t('Relatório: {titulo}', ['titulo' => $r['titulo']]),
            Idioma::html(),
            $agora->format('YmdHis') . str_replace(':', "'", $agora->format('P')) . "'"
        );
    }

    public function gerar(): string
    {
        $this->d->novaPagina();
        $this->r['tipo'] === 'anual' ? $this->anual() : $this->mensal();
        $this->rodapes();
        return $this->d->saida();
    }

    public function nomeArquivo(): string
    {
        $sufixo = $this->r['tipo'] === 'anual' ? (string) $this->r['ano'] : substr((string) $this->r['mes'], 0, 7);
        return 'financeiro-' . t('relatorio') . '-' . $sufixo . '.pdf';
    }

    /* ================================================================== mensal */

    private function mensal(): void
    {
        $r = $this->r;
        $this->abertura(t('Relatório mensal'), $r['titulo'], $r['manchete'], $r['situacao'], $r['conferencia']);
        $this->secao(t('Como a conta chegou até aqui'));
        $this->escada($r['ponte']);
        if ($r['reserva'] > 0.004) {
            $this->nota(t('Mínimo que você quer manter na conta: {valor}.', ['valor' => money($r['reserva'])]));
        }
        if ($r['divida'] !== null) {
            $this->linhaDivida($r['divida']);
        }
        if ($r['encerrado']) {
            $this->achados($r['achados']);
        } else {
            $this->y += 6;
            $this->caixa(t('Sem veredito enquanto o mês não termina'), [t('Num mês em andamento, o planejamento usa o maior valor entre o lançado e o planejado, então gasto abaixo do planejado ainda não aparece. O que foi bom e o que foi ruim aparecem quando o mês for encerrado.')]);
        }
        $this->autonomia($r['autonomia']);
        $ritmoNaCapa = $this->y + 190 <= self::FUNDO;
        if ($ritmoNaCapa) {
            $this->ritmo($r['ritmo']);
        }

        $this->novaPagina();
        $this->categorias($r['categorias'], t('Para onde foi'), t('O que estava planejado e o que saiu de cada categoria em {mes}.', ['mes' => monthLabel($r['mes'])]));
        $this->fatura($r['fatura']);
        if (count($r['tendencia']) >= 2) {
            $this->y += 6;
            $this->secao(t('Como este mês se compara'), t('Entradas e saídas dos últimos meses. As listras marcam o que ainda é planejado.'), 190);
            $this->barrasDoAno($r['tendencia']);
        }
        if (!$ritmoNaCapa) {
            $this->ritmo($r['ritmo']);
        }

        $this->novaPagina();
        $this->secao(t('O que vem pela frente'));
        $this->placar($r);
        $this->frente($r['previsao'], $r['temCartao']);
    }

    /* ================================================================== anual */

    private function anual(): void
    {
        $r = $this->r;
        $this->abertura(t('Relatório anual'), $r['titulo'], $r['manchete'], $r['situacao'], null);
        if ($r['encerrados'] > 0) {
            $this->numerosDoAno();
            $this->achados($r['achados']);
            $this->autonomia($r['autonomia']);
            $this->evolucao($r['tabela']);
        } else {
            $this->caixa(t('Como este relatório funciona'), [t('O relatório do ano soma só meses encerrados. Encerre um mês na tela Mês, e os totais aparecem aqui. A tabela e as previsões abaixo já valem.')]);
        }

        $this->novaPagina();
        $this->secao(t('Mês a mês'), t('Entradas e saídas de cada mês. As listras marcam o que ainda é planejado.'));
        $this->barrasDoAno($r['tabela']);
        $this->tabelaAno($r['tabela']);

        $this->novaPagina();
        if ($r['encerrados'] > 0) {
            $this->categorias($r['categorias'], t('Para onde foi'), tn($r['encerrados'], 'A soma do mês encerrado, por categoria.', 'A soma dos {n} meses encerrados, por categoria.'));
            if ($r['heatmap'] !== null) {
                $this->heatmap($r['heatmap']);
            } else {
                $this->nota(t('O mapa de categorias por mês aparece com 6 meses encerrados.'));
            }
            $this->novaPagina();
        }
        $this->secao(t('O que vem pela frente'));
        $this->frente($r['previsao'], $r['temCartao']);
        if ($r['placares'] !== []) {
            $this->y += 8;
            $this->placarAnual($r);
        } elseif ($r['placarInicio'] === null || $r['placaresN'] < 3) {
            $this->y += 6;
            $this->nota(t('Com 3 meses comparados, entra aqui o placar do plano: o que ele dizia no começo de cada mês e o que aconteceu.'));
        }
    }

    private function numerosDoAno(): void
    {
        $r = $this->r;
        $this->secao(t('O ano em números'));
        $itens = [[t('Entrou'), money($r['entrou'])], [t('Saiu'), money($r['saiu'])], [t('Guardou ou aplicou'), money($r['guardou'])]];
        if ($r['adiantou'] > 0.004 || $r['temDivida']) {
            $itens[] = [t('Adiantou do empréstimo'), money($r['adiantou'])];
        }
        $l = self::LARG / count($itens);
        foreach ($itens as $i => [$rot, $val]) {
            $x = self::MARGEM + $i * $l;
            $this->d->texto($x, $this->y + 10, $rot, self::MED, 9, self::GRAFITE);
            $this->d->texto($x, $this->y + 28, $val, self::DISP, 14, self::TINTA);
        }
        $this->y += 42;
        $this->d->linha(self::MARGEM, $this->y, self::MARGEM + self::LARG, $this->y, self::LINHA);
        $this->y += 16;
        if ($r['contaFim'] !== null) {
            $txt = t('A conta foi de {inicio} a {fim}.', ['inicio' => money((float) $r['contaInicio']), 'fim' => money((float) $r['contaFim'])]);
            if ($r['dividaFim'] > 0.004) {
                $txt .= ' ' . t('A dívida do empréstimo terminou o período em {valor}.', ['valor' => money((float) $r['dividaFim'])]);
            }
            if ($r['liquido'] !== null) {
                $txt .= ' ' . t('O que tem menos o que deve: {valor}.', ['valor' => money((float) $r['liquido'])]);
            }
            $this->y = $this->paragrafo($txt, self::MARGEM, $this->y, self::LARG, 10.5, self::TINTA);
            $this->y += 4;
        }
        foreach ($r['destaques'] as $d) {
            $this->d->texto(self::MARGEM, $this->y + 8, $d['rotulo'] . ': ' . mesExtenso($d['mes']) . ', ' . money($d['valor']), self::MED, 9.5, self::GRAFITE);
            $this->y += 14;
        }
    }

    /* ================================================================== peças */

    private function abertura(string $tipo, string $periodo, string $manchete, string $situacao, ?array $conf): void
    {
        $x = self::MARGEM;
        $this->marca($x, 40, 24);
        $this->d->texto($x + 31, 56.5, 'financeiro', self::BOLD, 12, self::TINTA);
        $this->d->texto($x + self::LARG, 56.5, $tipo, self::MED, 10, self::GRAFITE, 'd');
        $this->y = 104;
        $this->d->texto($x, $this->y, $periodo, self::MED, 13, self::GRAFITE);
        $this->y += 34;
        foreach ($this->d->quebrar($manchete, self::DISP, 31, self::LARG) as $l) {
            $this->d->texto($x, $this->y, $l, self::DISP, 31, self::TINTA, 'e', -0.4);
            $this->y += 37;
        }
        $this->y -= 16;
        $this->y = $this->paragrafo($situacao, $x, $this->y, self::LARG, 10, self::GRAFITE);
        if ($conf !== null) {
            $this->y += 1;
            $this->marcaConferencia($conf['tipo'], $x + 5, $this->y + 4);
            $this->d->texto($x + 15, $this->y + 8, $conf['texto'], self::BOLD, 9.5, self::TINTA);
            $this->y += 16;
        }
        $this->y += 12;
    }

    private function marcaConferencia(string $tipo, float $cx, float $cy): void
    {
        if ($tipo === 'ok') {
            $this->d->circulo($cx, $cy, 4.2, self::POS);
            $this->d->polilinha([[$cx - 2.1, $cy], [$cx - 0.5, $cy + 1.6], [$cx + 2.2, $cy - 1.7]], self::BRANCO, 1.0);
        } elseif ($tipo === 'dif') {
            $this->triangulo($cx, $cy, 4.4, self::NEG, true);
        } else {
            $this->d->circulo($cx, $cy, 3.8, null, self::GRAFITE, 1.0);
        }
    }

    private function triangulo(float $cx, float $cy, float $r, array $cor, bool $cima): void
    {
        $s = $cima ? -1 : 1;
        $this->d->poligono([[$cx - $r, $cy - $s * $r * 0.8], [$cx + $r, $cy - $s * $r * 0.8], [$cx, $cy + $s * $r * 0.9]], $cor);
    }

    /** O ícone do site, ou a marca padrão desenhada em vetor quando ele não existe ou não dá para embutir. */
    private function marca(float $x, float $y, float $t): void
    {
        $f = SiteIcone::personalizado() !== null ? SiteIcone::arquivo() : null;
        if ($f !== null) {
            $p = Documento::proporcao((string) $f['dados']) ?: 1.0;
            [$l, $a] = $p >= 1 ? [$t, $t / $p] : [$t * $p, $t];
            if ($this->d->imagem((string) $f['dados'], $x + ($t - $l) / 2, $y + ($t - $a) / 2, $l, $a)) {
                return;
            }
        }
        $k = $t / 96;
        $this->d->retangulo($x, $y, $t, $t, self::TINTA, null, 22 * $k);
        $this->d->circulo($x + 30 * $k, $y + 30 * $k, 12 * $k, null, self::LIMA, 5 * $k);
        $this->d->circulo($x + 30 * $k, $y + 30 * $k, 3.5 * $k, self::LIMA);
        $this->d->retangulo($x + 18 * $k, $y + 56 * $k, 14 * $k, 22 * $k, [0.47, 0.57, 0.22], null, 4 * $k);
        $this->d->retangulo($x + 41 * $k, $y + 42 * $k, 14 * $k, 36 * $k, [0.63, 0.77, 0.27], null, 4 * $k);
        $this->d->retangulo($x + 64 * $k, $y + 20 * $k, 14 * $k, 58 * $k, self::LIMA, null, 4 * $k);
    }

    private function secao(string $titulo, ?string $sub = null, float $min = 60.0): void
    {
        if ($this->y + $min > self::FUNDO) {
            $this->novaPagina();
        }
        $this->d->texto(self::MARGEM, $this->y + 12, $titulo, self::DISP, 14, self::TINTA, 'e', -0.1);
        $this->y += 22;
        if ($sub !== null) {
            $this->y = $this->paragrafo($sub, self::MARGEM, $this->y - 2, self::LARG, 9.5, self::GRAFITE) + 4;
        }
    }

    private function novaPagina(): void
    {
        $this->d->novaPagina();
        $this->y = self::TOPO - 18;
    }

    private function paragrafo(string $txt, float $x, float $y, float $l, float $corpo, array $cor, string $fonte = self::MED, float $entre = 1.42): float
    {
        foreach ($this->d->quebrar($txt, $fonte, $corpo, $l) as $linha) {
            $y += $corpo * $entre;
            $this->d->texto($x, $y - $corpo * 0.3, $linha, $fonte, $corpo, $cor);
        }
        return $y;
    }

    private function nota(string $txt): void
    {
        $this->y = $this->paragrafo($txt, self::MARGEM, $this->y, self::LARG, 9, self::GRAFITE) + 3;
    }

    /** @param list<string> $linhas */
    private function caixa(string $titulo, array $linhas): void
    {
        $alt = 16.0;
        foreach ($linhas as $t) {
            $alt += count($this->d->quebrar($t, self::MED, 9.5, self::LARG - 28)) * 13.5;
        }
        $alt += 18;
        if ($this->y + $alt > self::FUNDO) {
            $this->novaPagina();
        }
        $this->d->retangulo(self::MARGEM, $this->y, self::LARG, $alt, self::NEVOA, null, 8);
        $this->d->texto(self::MARGEM + 14, $this->y + 18, $titulo, self::BOLD, 10.5, self::TINTA);
        $yy = $this->y + 24;
        foreach ($linhas as $t) {
            $yy = $this->paragrafo($t, self::MARGEM + 14, $yy, self::LARG - 28, 9.5, self::GRAFITE);
        }
        $this->y += $alt + 10;
    }

    /** A escada do saldo: rótulo, barra flutuante na mesma escala e valor. A última linha é o resultado. */
    private function escada(array $linhas): void
    {
        $x = self::MARGEM;
        $zonaX = $x + 176;
        $zonaL = 214.0;
        $corrente = 0.0;
        $pontos = [];
        foreach ($linhas as $l) {
            if ($l['tipo'] === 'inicio') {
                $corrente = $l['valor'];
                $pontos[] = [0.0, $corrente];
            } elseif ($l['tipo'] === 'fim') {
                $pontos[] = [0.0, $l['valor']];
            } else {
                $pontos[] = [$corrente, $corrente + $l['valor']];
                $corrente += $l['valor'];
            }
        }
        $todos = array_merge(...$pontos);
        [$min, $max] = [min(0.0, ...$todos), max(0.0, ...$todos)];
        $amp = max(1.0, $max - $min);
        $px = static fn(float $v): float => $zonaX + ($v - $min) / $amp * $zonaL;
        $alt = 21.0;
        foreach ($linhas as $i => $l) {
            $fim = $l['tipo'] === 'fim';
            if ($fim) {
                $this->d->retangulo($x - 8, $this->y + 1, self::LARG + 16, $alt + 2, self::LIMA, null, 5);
            } elseif ($i > 0) {
                $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::LINHA, 0.5);
            }
            $base = $this->y + ($fim ? 15.5 : 14.5);
            $fonte = $fim || $l['tipo'] === 'inicio' ? self::BOLD : self::MED;
            $this->d->texto($x, $base, $l['rotulo'], $fonte, 10, self::TINTA);
            [$a, $b] = $pontos[$i];
            [$e, $dd] = [min($a, $b), max($a, $b)];
            $larg = max(1.2, $px($dd) - $px($e));
            $barY = $this->y + 6;
            if ($fim || $l['tipo'] === 'inicio') {
                $this->d->retangulo($px($e), $barY, $larg, 10, self::TINTA, null, 1.5);
            } else {
                $cor = $l['valor'] >= 0 ? self::ENTRA : self::SAI;
                if ($l['tipo'] === 'plan') {
                    $this->d->hachura($px($e), $barY, $larg, 10, $cor, 2.6, 0.8);
                    $this->d->retangulo($px($e), $barY, $larg, 10, null, $cor, 0, 0.6);
                } else {
                    $this->d->retangulo($px($e), $barY, $larg, 10, $cor, null, 1.5);
                }
            }
            $sinal = in_array($l['tipo'], ['inicio', 'fim'], true) ? money($l['valor']) : ($l['valor'] >= 0 ? '+' . money($l['valor']) : money($l['valor']));
            $this->d->texto($x + self::LARG, $base, $sinal, $fim ? self::DISP : $fonte, $fim ? 12 : 10, self::TINTA, 'd');
            $this->y += $alt;
        }
        $this->y += 8;
    }

    private function linhaDivida(array $d): void
    {
        $partes = [t('Dívida do empréstimo ao fim do mês: {valor}.', ['valor' => money($d['fim'])])];
        if ($d['juros'] > 0.004) {
            $partes[] = t('Juros do mês: {valor}.', ['valor' => money($d['juros'])]);
        }
        $this->nota(implode(' ', $partes));
    }

    private function achados(array $a): void
    {
        $this->y += 8;
        $cols = [
            [t('O que foi bom'), $a['bom'], 'bom', t('Nada relevante neste período.')],
            [t('O que foi ruim'), $a['ruim'], 'ruim', t('Nada relevante neste período.')],
            [t('O que pode melhorar'), $a['melhorar'], 'melhorar', t('Nenhum corte simples muda o resultado agora.')],
        ];
        $gap = 18.0;
        $l = (self::LARG - 2 * $gap) / 3;
        $altura = 0.0;
        foreach ($cols as [, $itens, , $vazio]) {
            $h = 24.0;
            foreach ($itens === [] ? [$vazio] : $itens as $t) {
                $h += count($this->d->quebrar($t, self::MED, 9.5, $l)) * 13.3 + 7;
            }
            $altura = max($altura, $h);
        }
        if ($this->y + $altura > self::FUNDO) {
            $this->novaPagina();
        }
        foreach ($cols as $i => [$titulo, $itens, $tipo, $vazio]) {
            $x = self::MARGEM + $i * ($l + $gap);
            $this->d->linha($x, $this->y, $x + $l, $this->y, self::TINTA, 1.2);
            $mx = $x + 4.5;
            $my = $this->y + 13;
            if ($tipo === 'bom') {
                $this->triangulo($mx, $my, 4.2, self::POS, true);
            } elseif ($tipo === 'ruim') {
                $this->triangulo($mx, $my, 4.2, self::NEG, false);
            } else {
                $this->d->circulo($mx, $my, 3.8, null, self::TINTA, 1.2);
            }
            $this->d->texto($x + 13, $this->y + 16.5, $titulo, self::BOLD, 10, self::TINTA);
            $yy = $this->y + 22;
            foreach ($itens === [] ? [$vazio] : $itens as $t) {
                $yy = $this->paragrafo($t, $x, $yy, $l, 9.5, $itens === [] ? self::GRAFITE : self::TINTA, self::MED, 1.4) + 7;
            }
        }
        $this->y += $altura + 4;
        foreach ($a['conferir'] ?? [] as $t) {
            $this->nota($t);
        }
    }

    private function autonomia(?array $a): void
    {
        if ($a === null) {
            return;
        }
        $texto = t('Conta e poupança somam {tem}, e os gastos marcados como essenciais foram {essenciais} por mês. Referência comum de reserva: 3 a 6 meses de despesas básicas.', ['tem' => money($a['tem']), 'essenciais' => money($a['essenciais'])]);
        $h = 10 + count($this->d->quebrar($texto, self::MED, 9, 330)) * 12.8 + 10;
        $h = max($h, 46.0);
        if ($this->y + $h > self::FUNDO) {
            return;                                             // é um complemento: não abre página só para isso
        }
        $this->y += 4;
        $this->d->linha(self::MARGEM, $this->y, self::MARGEM + self::LARG, $this->y, self::LINHA);
        $this->d->texto(self::MARGEM, $this->y + 18, t('Se a renda parasse'), self::MED, 9.5, self::GRAFITE);
        $this->d->texto(self::MARGEM, $this->y + 38, tn((int) ceil($a['meses']) === 1 && $a['meses'] <= 1.0 ? 1 : 2, '{valor} mês', '{valor} meses', ['valor' => num($a['meses'], 1)]), self::DISP, 16, self::TINTA);
        $this->paragrafo($texto, self::MARGEM + 176, $this->y + 6, 330, 9, self::GRAFITE);
        $this->y += $h;
    }

    private function categorias(array $c, string $titulo, string $sub): void
    {
        $this->secao($titulo, $sub);
        if ($c['linhas'] === []) {
            $this->nota(t('Nenhum gasto lançado neste período.'));
            return;
        }
        $x = self::MARGEM;
        $zonaX = $x + 148;
        $zonaL = 208.0;
        $max = 1.0;
        foreach ($c['linhas'] as $l) {
            $max = max($max, $l['real'], $l['planejado']);
        }
        $cabY = $this->y + 8;
        $this->d->texto($zonaX, $cabY, t('A barra é o real; o traço é o planejado'), self::MED, 8, self::GRAFITE);
        $this->d->texto($x + self::LARG, $cabY, t('Diferença'), self::MED, 8, self::GRAFITE, 'd');
        $this->d->texto($x + self::LARG - 76, $cabY, t('Real'), self::MED, 8, self::GRAFITE, 'd');
        $this->y += 14;
        $linha = function (string $nome, float $real, float $plan, bool $tracoBarra = true) use ($x, $zonaX, $zonaL, $max): void {
            $alt = 22.0;
            if ($this->y + $alt > self::FUNDO) {
                $this->novaPagina();
            }
            $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::LINHA, 0.5);
            $base = $this->y + 14.5;
            $this->d->texto($x, $base, $this->cortar($nome, self::MED, 10, 140), self::MED, 10, self::TINTA);
            $l = max(0.0, $real / $max * $zonaL);
            $this->d->retangulo($zonaX, $this->y + 7, $zonaL, 8, self::NEVOA, null, 1.5);
            if ($l > 0.3) {
                $this->d->retangulo($zonaX, $this->y + 7, max(1.5, $l), 8, $real > $plan * 1.0005 && $plan > 0.004 ? self::SAI : self::TINTA, null, 1.5);
            }
            if ($tracoBarra && $plan > 0.004) {
                $tx = $zonaX + min(1.0, $plan / $max) * $zonaL;
                $this->d->linha($tx, $this->y + 4, $tx, $this->y + 18, self::TINTA, 1.4);
            }
            $this->d->texto($x + self::LARG - 76, $base, num($real, 0), self::BOLD, 10, self::TINTA, 'd');
            $dif = $real - $plan;
            if ($plan > 0.004 && abs($dif) >= 0.5) {
                $tTx = ($dif > 0 ? '+' : '') . num($dif, 0);
                $this->d->texto($x + self::LARG, $base, $tTx, self::MED, 10, self::TINTA, 'd');
                $this->triangulo($x + self::LARG - $this->d->medir($tTx, self::MED, 10) - 7, $base - 3.5, 3.1, $dif > 0 ? self::SAI : self::POS, $dif > 0);
            } elseif ($plan <= 0.004) {
                $this->d->texto($x + self::LARG, $base, t('sem plano'), self::MED, 8.5, self::GRAFITE, 'd');
            }
            $this->y += $alt;
        };
        foreach ($c['linhas'] as $l) {
            $linha($l['nome'], $l['real'], $l['planejado']);
        }
        if ($c['outros'] !== null) {
            $linha(tn($c['outros']['n'], 'Outra 1 categoria', 'Outras {n} categorias'), $c['outros']['real'], $c['outros']['planejado'], false);
        }
        $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::TINTA, 1.0);
        $this->d->texto($x, $this->y + 15, t('Total'), self::BOLD, 10, self::TINTA);
        $this->d->texto($x + self::LARG - 76, $this->y + 15, num($c['totalReal'], 0), self::BOLD, 10, self::TINTA, 'd');
        $d = $c['totalReal'] - $c['totalPlanejado'];
        $this->d->texto($x + self::LARG, $this->y + 15, ($d > 0 ? '+' : '') . num($d, 0), self::BOLD, 10, self::TINTA, 'd');
        $this->y += 30;
    }

    private function fatura(?array $f): void
    {
        if ($f === null || $f['linhas'] === [] && !$f['paga']) {
            return;
        }
        $titulo = $f['paga'] ? t('O que tinha na fatura de {valor}', ['valor' => money($f['pago'])]) : t('Compras na fatura deste mês');
        $this->secao($titulo, t('A fatura conta no mês em que é paga. Aqui ela aparece pelo que foi comprado.'), 90);
        $linhas = $f['linhas'];
        if ($f['restoCategorias'] > 0.004) {
            $linhas[] = ['nome' => t('Outras categorias'), 'valor' => $f['restoCategorias']];
        }
        if ($f['semDetalhe'] > 0.004) {
            $linhas[] = ['nome' => t('Fatura sem detalhe'), 'valor' => $f['semDetalhe'], 'hachura' => true];
        }
        $max = max(1.0, ...array_column($linhas, 'valor'));
        foreach ($linhas as $l) {
            if ($this->y + 18 > self::FUNDO) {
                $this->novaPagina();
            }
            $this->d->texto(self::MARGEM, $this->y + 11, $this->cortar($l['nome'], self::MED, 9.5, 140), self::MED, 9.5, self::TINTA);
            $larg = max(1.5, $l['valor'] / $max * 280);
            if (!empty($l['hachura'])) {
                $this->d->hachura(self::MARGEM + 148, $this->y + 4, $larg, 8, self::GRAFITE);
                $this->d->retangulo(self::MARGEM + 148, $this->y + 4, $larg, 8, null, self::GRAFITE, 0, 0.5);
            } else {
                $this->d->retangulo(self::MARGEM + 148, $this->y + 4, $larg, 8, self::TINTA, null, 1.5);
            }
            $this->d->texto(self::MARGEM + self::LARG, $this->y + 11, num($l['valor'], 0), self::MED, 9.5, self::TINTA, 'd');
            $this->y += 17;
        }
        $this->y += 3;
        if ($f['semDetalhe'] > 0.004) {
            $this->nota(t('"Fatura sem detalhe" é o que foi pago e não tem compra registrada no Cartão. Sem esse registro, não dá para saber a categoria.'));
        }
        if ($f['aMais'] > 0.004) {
            $this->nota(t('As compras registradas somam {valor} a mais do que a fatura paga. Vale conferir no Cartão.', ['valor' => money($f['aMais'])]));
        }
    }

    private function placar(array $r): void
    {
        $p = $r['placar'];
        if ($p === null) {
            if (!$r['encerrado']) {
                return;                                         // o placar só existe depois que o mês termina
            }
            $inicio = $r['placarInicio'];
            $this->caixa(t('O placar do planejamento'), [
                $inicio !== null && $inicio > $r['mes']
                    ? t('O placar começa em {mes}. Ele compara o que o planejamento dizia no começo do mês com o que aconteceu, e não dá para refazer meses passados.', ['mes' => mesExtenso($inicio)])
                    : t('O placar compara o que o planejamento dizia no começo do mês com o que aconteceu. Este mês não tem esse registro, porque o app não foi aberto na primeira semana.'),
            ]);
            return;
        }
        $linhas = [t('Em {data}, o planejamento dizia que a conta terminaria o mês com {prev}. Terminou com {real}: {dif}.', [
            'data' => diaMes($p['criada']), 'prev' => money($p['previsto']), 'real' => money($p['real']),
            'dif' => abs($p['dif']) < 0.01 ? t('exatamente o previsto') : ($p['dif'] > 0 ? t('{valor} acima', ['valor' => money($p['dif'])]) : t('{valor} abaixo', ['valor' => money(abs($p['dif']))])),
        ])];
        if ($p['causas'] !== []) {
            $linhas[] = t('O que mais pesou: {lista}.', ['lista' => implode(', ', array_map(static fn($c) => $c['nome'] . ' (' . moneySigned($c['efeito']) . ')', $p['causas']))]);
        }
        $this->caixa(t('O placar do planejamento'), $linhas);
    }

    private function placarAnual(array $r): void
    {
        $this->secao(t('O placar do planejamento'), t('O que ele dizia no começo de cada mês e como a conta terminou. Em média, a diferença foi de {valor}.', ['valor' => money((float) $r['erroMedio'])]), 40 + count($r['placares']) * 21);
        $x = self::MARGEM;
        $col = [$x + 200, $x + 340, $x + self::LARG];
        $this->d->texto($x, $this->y + 10, t('Mês'), self::MED, 8.5, self::GRAFITE);
        $this->d->texto($col[0], $this->y + 10, t('Dizia'), self::MED, 8.5, self::GRAFITE, 'd');
        $this->d->texto($col[1], $this->y + 10, t('Terminou'), self::MED, 8.5, self::GRAFITE, 'd');
        $this->d->texto($col[2], $this->y + 10, t('Diferença'), self::MED, 8.5, self::GRAFITE, 'd');
        $this->y += 15;
        foreach ($r['placares'] as $p) {
            $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::LINHA, 0.5);
            $this->d->texto($x, $this->y + 14, monthLabel($p['mes']), self::MED, 10, self::TINTA);
            $this->d->texto($col[0], $this->y + 14, num($p['previsto'], 0), self::MED, 10, self::TINTA, 'd');
            $this->d->texto($col[1], $this->y + 14, num($p['real'], 0), self::MED, 10, self::TINTA, 'd');
            $this->d->texto($col[2], $this->y + 14, ($p['dif'] > 0 ? '+' : '') . num($p['dif'], 0), self::BOLD, 10, self::TINTA, 'd');
            $this->y += 21;
        }
    }

    /** Três camadas, da certeza para a suposição: o que já está contratado, o estimado e os cenários. */
    private function frente(array $p, bool $temCartao): void
    {
        if ($p['meses'] === []) {
            $this->nota(t('Não há meses à frente para mostrar.'));
            return;
        }
        $this->y += 2;
        $total = array_sum(array_map(static fn($c) => $c['cartao'] + $c['emprestimo'], $p['contratado']));
        if ($total > 0.004) {
            $this->d->texto(self::MARGEM, $this->y + 10, t('Já contratado'), self::BOLD, 11, self::TINTA);
            $this->d->texto(self::MARGEM + self::LARG, $this->y + 10, t('Parcelas que vão sair, com certeza'), self::MED, 9, self::GRAFITE, 'd');
            $this->y += 17;
            $this->escadaContratado($p['contratado']);
        }
        $this->d->texto(self::MARGEM, $this->y + 10, t('Estimado'), self::BOLD, 11, self::TINTA);
        $this->d->texto(self::MARGEM + self::LARG, $this->y + 10, t('O que tem menos o que deve, ao fim de cada mês'), self::MED, 9, self::GRAFITE, 'd');
        $this->y += 18;
        $this->linhasCenarios($p);
        $fim = end($p['meses']);
        $vFim = $p['cenarios'][0]['fim'];
        $frase = t('Em {mes}: {valor} na conta, se nada mudar.', ['mes' => monthLabel($fim), 'valor' => RelatorioDados::cerca($vFim)]);
        $this->y = $this->paragrafo($frase, self::MARGEM, $this->y, self::LARG, 10.5, self::TINTA, self::BOLD) + 2;
        $this->tabelaCenarios($p);
        if (!empty($p['ganho'])) {
            $g = $p['ganho'];
            $this->nota(tn($g['meses'], 'Com os adiantamentos do plano, o empréstimo termina em {fim}, 1 mês antes, e evita cerca de {juros} de juros.', 'Com os adiantamentos do plano, o empréstimo termina em {fim}, {n} meses antes, e evita cerca de {juros} de juros.', ['fim' => monthLabel($g['quitaCom']), 'juros' => money($g['juros'])]));
        } elseif (!empty($p['quita']['mes']) && $p['cenarios'][0]['quita'] !== null) {
            $this->nota(t('O empréstimo termina em {fim} pelo planejamento.', ['fim' => monthLabel((string) $p['quita']['mes'])]));
        }
        if ($temCartao) {
            $this->nota(t('Compra à vista no cartão ainda não registrada não entra nesta conta, então o resultado real costuma ficar abaixo do estimado.'));
        }
    }

    private function escadaContratado(array $itens): void
    {
        $x = self::MARGEM;
        $alt = 62.0;
        $max = max(1.0, ...array_map(static fn($c) => $c['cartao'] + $c['emprestimo'], $itens));
        $n = count($itens);
        $l = self::LARG / $n;
        $barL = min(40.0, $l * 0.62);
        $temCart = array_sum(array_column($itens, 'cartao')) > 0.004;
        $temEmp = array_sum(array_column($itens, 'emprestimo')) > 0.004;
        $base = $this->y + 12 + $alt;
        foreach ($itens as $i => $c) {
            $bx = $x + $i * $l + ($l - $barL) / 2;
            $hE = $c['emprestimo'] / $max * $alt;
            $hC = $c['cartao'] / $max * $alt;
            if ($hE > 0.2) {
                $this->d->retangulo($bx, $base - $hE, $barL, $hE, self::GRAFITE);
            }
            if ($hC > 0.2) {
                $this->d->barra($bx, $base - $hE - $hC - ($hE > 0.2 ? 1 : 0), $barL, $hC, self::TINTA);
            }
            $this->d->texto($bx + $barL / 2, $base - $hE - $hC - 4, num($c['cartao'] + $c['emprestimo'], 0), self::BOLD, 8.5, self::TINTA, 'c');
            $this->d->texto($bx + $barL / 2, $base + 11, monthLabel($c['mes']), self::MED, 8, self::GRAFITE, 'c');
        }
        $this->d->linha($x, $base, $x + self::LARG, $base, self::TINTA, 0.7);
        $this->y = $base + 16;
        if ($temCart && $temEmp) {
            $this->d->retangulo($x, $this->y, 8, 8, self::TINTA, null, 1.5);
            $this->d->texto($x + 12, $this->y + 7.5, t('Cartão'), self::MED, 8.5, self::GRAFITE);
            $this->d->retangulo($x + 62, $this->y, 8, 8, self::GRAFITE, null, 1.5);
            $this->d->texto($x + 74, $this->y + 7.5, t('Parcela do empréstimo'), self::MED, 8.5, self::GRAFITE);
            $this->y += 13;
        }
        $this->y += 8;
    }

    /** Um eixo só, três linhas com o nome na ponta. O plano é a história; os cenários ficam em tom de apoio. */
    private function linhasCenarios(array $p): void
    {
        $x = self::MARGEM + 34;
        $l = self::LARG - 34 - 112;
        $alt = 112.0;
        $topoY = $this->y + 4;
        $series = array_values(array_map(static fn($c) => ['nome' => $c['nome'], 'v' => $c['linha']], $p['cenarios']));
        $todos = array_merge(...array_column($series, 'v'));
        [$min, $max] = [min(0.0, ...$todos), max(1.0, ...$todos)];
        [$lo, $hi, $passo] = self::escala($min, $max);
        $py = static fn(float $v): float => $topoY + $alt - ($v - $lo) / max(1e-9, $hi - $lo) * $alt;
        for ($t = $lo; $t <= $hi + 1e-6; $t += $passo) {
            $this->d->linha($x, $py($t), $x + $l, $py($t), abs($t) < 1e-6 ? self::GRAFITE : self::LINHA, abs($t) < 1e-6 ? 0.7 : 0.4);
            $this->d->texto($x - 5, $py($t) + 2.8, self::compacto($t), self::MED, 7.5, self::GRAFITE, 'd');
        }
        $meses = $p['meses'];
        $n = count($meses);
        $px = static fn(int $i): float => $x + ($n > 1 ? $i / ($n - 1) : 0.5) * $l;
        foreach ($meses as $i => $m) {
            if ($n <= 7 || $i % 2 === 0) {
                $this->d->texto($px($i), $topoY + $alt + 11, monthLabel($m), self::MED, 7.5, self::GRAFITE, 'c');
            }
        }
        $estilos = [[self::TINTA, 1.9, null], [self::SAI, 1.2, [4, 2.5]], [self::ENTRA, 1.2, [1.5, 2]]];
        $fins = [];
        foreach ($series as $i => $s) {
            $pts = [];
            foreach ($s['v'] as $k => $v) {
                $pts[] = [$px($k), $py($v)];
            }
            $this->d->polilinha($pts, $estilos[$i][0], $estilos[$i][1], $estilos[$i][2]);
            $fins[] = ['y' => $py(end($s['v'])), 'nome' => $s['nome'], 'cor' => $estilos[$i][0], 'i' => $i];
        }
        usort($fins, static fn($a, $b) => $a['y'] <=> $b['y']);
        $ult = -INF;
        foreach ($fins as $f) {
            $y = max($f['y'] + 2.5, $ult + 9.5);
            $this->d->texto($x + $l + 5, $y, $this->cortar($f['nome'], self::MED, 7.8, 106), $f['i'] === 0 ? self::BOLD : self::MED, 7.8, self::TINTA);
            $ult = $y;
        }
        $this->y = $topoY + $alt + 22;
    }

    /** O ano numa linha só: cheia até o último mês encerrado, tracejada no que ainda é planejado. */
    private function evolucao(array $t): void
    {
        if (count($t) < 2 || $this->y + 170 > self::FUNDO) {
            return;
        }
        $this->y += 6;
        $this->secao(t('O que tem menos o que deve, mês a mês'), t('Conta, poupança e investimentos, menos a dívida. A parte tracejada é planejada.'), 160);
        $this->linhaSimples($t);
    }

    private function linhaSimples(array $t): void
    {
        $x = self::MARGEM + 34;
        $l = self::LARG - 34 - 8;
        $alt = 100.0;
        $topoY = $this->y + 4;
        $vals = array_column($t, 'liquido');
        [$lo, $hi, $passo] = self::escala(min(0.0, ...$vals), max(1.0, ...$vals));
        $py = static fn(float $v): float => $topoY + $alt - ($v - $lo) / max(1e-9, $hi - $lo) * $alt;
        for ($v = $lo; $v <= $hi + 1e-6; $v += $passo) {
            $this->d->linha($x, $py($v), $x + $l, $py($v), abs($v) < 1e-6 ? self::GRAFITE : self::LINHA, abs($v) < 1e-6 ? 0.7 : 0.4);
            $this->d->texto($x - 5, $py($v) + 2.8, self::compacto($v), self::MED, 7.5, self::GRAFITE, 'd');
        }
        $n = count($t);
        $px = static fn(int $i): float => $x + ($n > 1 ? $i / ($n - 1) : 0.5) * $l;
        $cheia = $tracejada = [];
        foreach ($t as $i => $m) {
            if ($m['estado'] === 'real') {
                $cheia[] = [$px($i), $py($m['liquido'])];
                $tracejada = [[$px($i), $py($m['liquido'])]];
            } else {
                $tracejada[] = [$px($i), $py($m['liquido'])];
            }
            $this->d->texto($px($i), $topoY + $alt + 11, mesCurto($m['mes']), self::MED, 7.5, self::GRAFITE, 'c');
        }
        $this->d->polilinha($tracejada, self::GRAFITE, 1.4, [3.5, 2.5]);
        $this->d->polilinha($cheia, self::TINTA, 2.0);
        $ult = $cheia === [] ? null : end($cheia);
        if ($ult !== null) {
            $this->d->circulo($ult[0], $ult[1], 3.0, self::TINTA, self::BRANCO, 1.2);
        }
        $this->y = $topoY + $alt + 22;
    }

    /** Duas linhas de gasto acumulado: onde o mês está em relação ao anterior, no mesmo dia. */
    private function ritmo(?array $r): void
    {
        if ($r === null || count($r['atual']) < 2) {
            return;
        }
        $this->y += 6;
        $this->secao(t('Ritmo de gastos'), t('Quanto já tinha saído em cada dia do mês.'), 175);
        $x = self::MARGEM + 34;
        $l = self::LARG - 34 - 56;
        $alt = 100.0;
        $topoY = $this->y + 2;
        $todos = array_merge($r['atual'], $r['anterior']);
        [$lo, $hi, $passo] = self::escala(0.0, max(1.0, ...$todos));
        $py = static fn(float $v): float => $topoY + $alt - ($v - $lo) / max(1e-9, $hi - $lo) * $alt;
        for ($v = $lo; $v <= $hi + 1e-6; $v += $passo) {
            $this->d->linha($x, $py($v), $x + $l, $py($v), $v === 0.0 ? self::GRAFITE : self::LINHA, $v === 0.0 ? 0.7 : 0.4);
            $this->d->texto($x - 5, $py($v) + 2.8, self::compacto($v), self::MED, 7.5, self::GRAFITE, 'd');
        }
        $px = static fn(int $d) => $x + ($d - 1) / max(1, $r['dias'] - 1) * $l;
        foreach ([1, 10, 20, $r['dias']] as $d) {
            $this->d->texto($px($d), $topoY + $alt + 11, t('dia {n}', ['n' => $d]), self::MED, 7.5, self::GRAFITE, 'c');
        }
        $pts = static function (array $v) use ($px, $py): array {
            $o = [];
            foreach ($v as $i => $val) {
                $o[] = [$px($i + 1), $py($val)];
            }
            return $o;
        };
        if ($r['anterior'] !== []) {
            $p = $pts($r['anterior']);
            $this->d->polilinha($p, self::GRAFITE, 1.2, [3.5, 2.5]);
            $f = end($p);
            $this->d->texto($f[0] + 5, $f[1] + 2.5, monthLabel($r['mesAnterior']), self::MED, 7.8, self::GRAFITE);
        }
        $p = $pts($r['atual']);
        $this->d->polilinha($p, self::TINTA, 2.0);
        $f = end($p);
        $this->d->texto($f[0] + 5, $f[1] + 2.5, monthLabel($r['mes']), self::BOLD, 7.8, self::TINTA);
        $this->y = $topoY + $alt + 22;
    }

    private function tabelaCenarios(array $p): void
    {
        $x = self::MARGEM;
        $temDivida = $p['cenarios'][0]['quita'] !== null;
        $cols = $temDivida ? [$x + 244, $x + 392, $x + self::LARG] : [$x + 300, $x + self::LARG];
        $cab = $temDivida ? [t('Mês mais apertado na conta'), t('O que tem menos o que deve'), t('Termina de pagar')] : [t('Mês mais apertado na conta'), t('O que tem menos o que deve')];
        $this->y += 2;
        foreach ($cab as $i => $c) {
            $this->d->texto($cols[$i], $this->y + 9, $c, self::MED, 8.5, self::GRAFITE, 'd');
        }
        $this->y += 13;
        foreach ($p['cenarios'] as $i => $c) {
            $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::LINHA, 0.5);
            $base = $this->y + 13.5;
            $this->d->texto($x, $base, $c['nome'], $i === 0 ? self::BOLD : self::MED, 9.5, self::TINTA);
            $this->d->texto($cols[0], $base, monthLabel($c['menor']['mes']) . ': ' . num($c['menor']['fica'], 0), self::MED, 9.5, self::TINTA, 'd');
            $this->d->texto($cols[1], $base, num((float) end($c['linha']), 0), self::MED, 9.5, self::TINTA, 'd');
            if ($temDivida) {
                $this->d->texto($cols[2], $base, $c['quita'] !== null ? monthLabel((string) $c['quita']) : '-', self::MED, 9.5, self::TINTA, 'd');
            }
            $this->y += 20;
        }
        $this->y += 7;
    }

    private function barrasDoAno(array $t): void
    {
        $x = self::MARGEM + 30;
        $l = self::LARG - 30;
        $alt = 120.0;
        $topoY = $this->y + 14;
        $max = max(1.0, ...array_column($t, 'entrou'), ...array_column($t, 'saiu'));
        [$lo, $hi, $passo] = self::escala(0.0, $max);
        $py = static fn(float $v): float => $topoY + $alt - ($v - $lo) / max(1e-9, $hi - $lo) * $alt;
        for ($v = $lo; $v <= $hi + 1e-6; $v += $passo) {
            $this->d->linha($x, $py($v), $x + $l, $py($v), $v === 0.0 ? self::GRAFITE : self::LINHA, $v === 0.0 ? 0.7 : 0.4);
            $this->d->texto($x - 5, $py($v) + 2.8, self::compacto($v), self::MED, 7.5, self::GRAFITE, 'd');
        }
        $this->d->retangulo(self::MARGEM + self::LARG - 118, $this->y, 8, 8, self::ENTRA, null, 1.5);
        $this->d->texto(self::MARGEM + self::LARG - 106, $this->y + 7.5, t('Entrou'), self::MED, 8.5, self::GRAFITE);
        $this->d->retangulo(self::MARGEM + self::LARG - 60, $this->y, 8, 8, self::SAI, null, 1.5);
        $this->d->texto(self::MARGEM + self::LARG - 48, $this->y + 7.5, t('Saiu'), self::MED, 8.5, self::GRAFITE);
        $n = count($t) > 6 ? max(12, count($t)) : count($t);
        $cel = $l / $n;
        $bar = min(count($t) > 6 ? 13.0 : 24.0, ($cel - 6) / 2);
        foreach ($t as $i => $m) {
            $bx = $x + $i * $cel + ($cel - 2 * $bar - 2) / 2;
            foreach ([[$m['entrou'], self::ENTRA, 0], [$m['saiu'], self::SAI, $bar + 2]] as [$v, $cor, $dx]) {
                $h = max(0.0, $py(0.0) - $py($v));
                if ($h < 0.3) {
                    continue;
                }
                if ($m['estado'] === 'real') {
                    $this->d->barra($bx + $dx, $py($v), $bar, $h, $cor, 2.0);
                } else {
                    $this->d->hachura($bx + $dx, $py($v), $bar, $h, $cor, 2.6, 0.8);
                    $this->d->retangulo($bx + $dx, $py($v), $bar, $h, null, $cor, 0, 0.6);
                }
            }
            $this->d->texto($x + ($i + 0.5) * $cel, $topoY + $alt + 11, count($t) > 6 ? mesCurto($m['mes']) : monthLabel($m['mes']), self::MED, 7.8, self::GRAFITE, 'c');
        }
        $this->y = $topoY + $alt + 24;
    }

    private function tabelaAno(array $t): void
    {
        $x = self::MARGEM;
        $col = [$x + 192, $x + 270, $x + 348, $x + 428, $x + self::LARG];
        $temDiv = array_sum(array_column($t, 'divida')) > 0.004;
        $cab = [t('Entrou'), t('Saiu'), t('Sobrou'), t('Conta no fim')];
        if ($temDiv) {
            $cab[] = t('Dívida');
        } else {
            $col = [$x + 240, $x + 330, $x + 420, $x + self::LARG];
        }
        foreach ($cab as $i => $c) {
            $this->d->texto($col[$i], $this->y + 9, $c, self::MED, 8.5, self::GRAFITE, 'd');
        }
        $this->y += 13;
        foreach ($t as $m) {
            $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::LINHA, 0.5);
            $base = $this->y + 13.5;
            $cy = $this->y + 10;
            if ($m['estado'] === 'real') {
                $this->d->circulo($x + 4, $cy, 3.2, self::TINTA);
            } elseif ($m['estado'] === 'aberto') {
                $this->d->circulo($x + 4, $cy, 3.2, self::BRANCO, self::TINTA, 1);
                $this->d->retangulo($x + 1.6, $cy - 2.4, 2.4, 4.8, self::TINTA);
            } else {
                $this->d->circulo($x + 4, $cy, 3.2, null, self::GRAFITE, 1);
            }
            $this->d->texto($x + 14, $base, monthLabel($m['mes']), self::MED, 9.5, self::TINTA);
            $vals = [$m['entrou'], $m['saiu'], $m['sobrou'], $m['fica']];
            if ($temDiv) {
                $vals[] = $m['divida'];
            }
            foreach ($vals as $i => $v) {
                $this->d->texto($col[$i], $base, num($v, 0), $m['estado'] === 'real' ? self::BOLD : self::MED, 9.5, $m['estado'] === 'previsto' ? self::GRAFITE : self::TINTA, 'd');
            }
            $this->y += 20;
        }
        $this->d->linha($x, $this->y, $x + self::LARG, $this->y, self::LINHA, 0.5);
        $this->y += 6;
        $leg = [['real', t('Encerrado')], ['aberto', t('Em andamento')], ['previsto', t('Planejado')]];
        $lx = $x;
        foreach ($leg as [$e, $rot]) {
            $cy = $this->y + 5;
            if ($e === 'real') {
                $this->d->circulo($lx + 4, $cy, 3.2, self::TINTA);
            } elseif ($e === 'aberto') {
                $this->d->circulo($lx + 4, $cy, 3.2, self::BRANCO, self::TINTA, 1);
                $this->d->retangulo($lx + 1.6, $cy - 2.4, 2.4, 4.8, self::TINTA);
            } else {
                $this->d->circulo($lx + 4, $cy, 3.2, null, self::GRAFITE, 1);
            }
            $this->d->texto($lx + 12, $cy + 3, $rot, self::MED, 8.5, self::GRAFITE);
            $lx += 20 + $this->d->medir($rot, self::MED, 8.5);
        }
        $this->y += 16;
    }

    private function heatmap(array $h): void
    {
        $this->secao(t('Categorias mês a mês'), t('Quanto mais escuro, maior o gasto naquele mês.'), 60 + count($h['linhas']) * 19);
        $x = self::MARGEM;
        $nomeL = 120.0;
        $n = count($h['meses']);
        $cel = (self::LARG - $nomeL) / $n;
        foreach ($h['meses'] as $i => $m) {
            $this->d->texto($x + $nomeL + ($i + 0.5) * $cel, $this->y + 9, mesCurto($m), self::MED, 8, self::GRAFITE, 'c');
        }
        $this->y += 13;
        foreach ($h['linhas'] as $l) {
            $this->d->texto($x, $this->y + 12, $this->cortar($l['nome'], self::MED, 9.5, $nomeL - 6), self::MED, 9.5, self::TINTA);
            foreach ($l['valores'] as $i => $v) {
                $k = $h['max'] > 0 ? sqrt($v / $h['max']) : 0.0;
                $cor = [0.953 - $k * 0.886, 0.957 - $k * 0.879, 0.965 - $k * 0.859];
                $this->d->retangulo($x + $nomeL + $i * $cel + 1, $this->y + 1, $cel - 2, 17, $cor, null, 2);
                $this->d->texto($x + $nomeL + ($i + 0.5) * $cel, $this->y + 12.5, self::compacto($v), self::MED, 8, $k > 0.55 ? self::BRANCO : self::TINTA, 'c');
            }
            $this->y += 19;
        }
        $this->y += 8;
    }

    /* ------------------------------------------------------------------ miúdos */

    private function rodapes(): void
    {
        $total = $this->d->totalPaginas();
        $gerado = diaMesAno((string) $this->r['gerado']) . ' ' . date('H:i', (int) strtotime((string) $this->r['gerado']));
        for ($i = 0; $i < $total; $i++) {
            $this->d->irParaPagina($i);
            $this->d->linha(self::MARGEM, 804, self::MARGEM + self::LARG, 804, self::LINHA, 0.5);
            $this->d->texto(self::MARGEM, 816, t('Retrato de {data}. Os números mudam se algo for lançado depois.', ['data' => $gerado]), self::MED, 8, self::GRAFITE);
            $this->d->texto(self::MARGEM + self::LARG, 816, t('Página {n} de {total}', ['n' => $i + 1, 'total' => $total]), self::MED, 8, self::GRAFITE, 'd');
        }
    }

    private function cortar(string $t, string $f, float $c, float $l): string
    {
        if ($this->d->medir($t, $f, $c) <= $l) {
            return $t;
        }
        while (mb_strlen($t) > 1 && $this->d->medir($t . '…', $f, $c) > $l) {
            $t = mb_substr($t, 0, -1);
        }
        return rtrim($t) . '…';
    }

    /** Escala redonda: de 2 a 4 linhas. @return array{0:float,1:float,2:float} */
    private static function escala(float $min, float $max): array
    {
        $amp = max(1.0, $max - $min);
        $base = 10 ** floor(log10($amp));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $passo = $m * $base;
            if ($amp / $passo <= 4) {
                break;
            }
        }
        return [floor($min / $passo) * $passo, ceil($max / $passo) * $passo, $passo];
    }

    private static function compacto(float $v): string
    {
        $a = abs($v);
        $s = $v < -0.5 ? MENOS : '';
        if ($a >= 1000) {
            $k = $a / 1000;
            return $s . (abs($k - round($k)) < 0.05 ? num(round($k), 0) : num($k, 1)) . NBSP . t('mil');
        }
        return $s . num($a, 0);
    }
}
