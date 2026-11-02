<?php
namespace Financeiro;

use Financeiro\Models\CategoriaModel;

/**
 * Ícones de categoria: emojis do Noto Emoji (160 px) num sprite só, public/assets/img/icones.webp;
 * a lista com posição e grupo de cada um é gerada em src/icones_lista.php por
 * tools/icones/gerar.py.
 *
 * Cada categoria guarda a chave em categorias.icone (escolhida no formulário). Sem chave, ou
 * com uma que saiu da lista, vale a sugestão pelo nome (REGRAS) e, por último, a do grupo.
 */
final class Icones
{
    /**
     * Palavras do nome que sugerem um ícone, em ordem: vence a 1ª regra que casar.
     * "abc" casa com o começo de uma palavra do nome; "=abc", só com a palavra inteira;
     * "abc def" (com espaço), com o trecho. Tudo sem acento e em minúscula.
     */
    public const REGRAS = [
        ['leao',           ['imposto', 'receita federal', 'irpf', 'leao']],
        ['governo',        ['ipva', 'iptu', 'taxa', 'tributo', 'detran']],
        ['documento',      ['licenciamento', 'documento', 'cartorio', 'despachante']],
        ['ferramenta',     ['revisao', 'oficina', 'mecanic', 'manutencao', 'conserto', 'pneu']],
        ['combustivel',    ['combust', 'gasolina', 'etanol', 'diesel', '=posto']],
        ['carro',          ['carro', 'veiculo', 'automovel', '=uber', '=99']],
        ['escudo',         ['seguro']],
        ['banco',          ['emprest', 'financiamento', 'amortiza', 'consignado']],
        ['porquinho',      ['poupanca', 'guardar', 'cofrinho', 'resgate']],
        ['grafico',        ['invest', '=acoes', 'tesouro', 'cripto']],
        ['saco',           ['salario', 'soldo', 'provento', '=renda']],
        ['moeda',          ['=pix', 'reembolso', 'outras entradas', '=13', 'decimo', 'bonus', 'freela']],
        ['nota',           ['mesada', 'carteira', '=pessoal']],
        ['voando',         ['juros', 'tarifa', 'multa', '=iof', 'cheque especial']],
        ['cartao',         ['fatura', 'cartao']],
        ['pilula',         ['farmac', 'remedio', 'drogaria']],
        ['dente',          ['dentist', 'odonto']],
        ['estetoscopio',   ['saude', 'medic', 'consulta', 'hospital', 'exame', 'clinica', 'terapia', 'psicolog']],
        ['barbearia',      ['barbe', 'cabelo', 'salao', 'cabeleir']],
        ['batom',          ['beleza', 'maquiag', 'estetic', 'unha', 'manicure', 'cosmetic']],
        ['sabonete',       ['higiene', 'perfumaria']],
        ['musculo',        ['academia', 'musculacao', 'crossfit', 'suplement']],
        ['tenis',          ['treino', 'corrida', 'assessoria', 'esporte', 'natacao']],
        ['prato',          ['mercado', 'supermercado', 'feira', 'hortifruti', 'acougue', 'alimentacao', 'comida']],
        ['sanduiche',      ['lanche', 'sanduiche', 'salgado']],
        ['cafe',           ['=cafe', 'cafeteria']],
        ['pao',            ['padaria', '=pao']],
        ['pizza',          ['pizza', 'delivery', 'ifood', 'rappi']],
        ['talheres',       ['restaurante', 'comer fora', 'almoco', 'jantar', 'refeic']],
        ['cerveja',        ['cerveja', '=bar', 'boteco']],
        ['vinho',          ['vinho']],
        ['bolo',           ['=doce', '=doces', 'confeitaria', 'sorvete', 'bolo']],
        ['presente',       ['presente', 'aniversario']],
        ['festa',          ['festa', 'comemoracao', 'casamento', 'balada']],
        ['casa',           ['aluguel', 'condominio', 'moradia', '=casa', 'apartamento']],
        ['lampada',        ['=luz', 'energia', 'eletric']],
        ['agua',           ['=agua', 'saneamento']],
        ['fogo',           ['=gas', 'botijao']],
        ['vassoura',       ['faxin', 'diarista', 'limpeza', 'lavanderia']],
        ['wifi',           ['internet', 'wifi', '=fibra', 'banda larga']],
        ['celular',        ['celular', 'telefone']],
        ['musica',         ['spotify', 'musica', 'deezer']],
        ['tv',             ['netflix', '=tv', 'streaming', 'disney', '=hbo', 'globoplay']],
        ['jogo',           ['jogo', '=game', '=games', 'steam', 'playstation', 'xbox', 'nintendo']],
        ['notebook',       ['computador', 'notebook', 'software', 'icloud']],
        ['recibo',         ['=conta', '=contas', 'boleto', 'assinatura', 'mensalidade']],
        ['sofa',           ['moveis', 'decoracao']],
        ['planta',         ['planta', 'jardim']],
        ['praia',          ['praia', 'ferias']],
        ['aviao',          ['viagem', '=voo', 'hotel', 'hospedagem']],
        ['moto',           ['=moto', 'motocicleta']],
        ['onibus',         ['onibus', 'metro', 'transporte', 'passagem']],
        ['bicicleta',      ['bicicleta', '=bike']],
        ['estacionamento', ['estacionamento', 'pedagio']],
        ['ingresso',       ['lazer', 'cinema', '=show', 'ingresso', 'passeio', 'teatro']],
        ['formatura',      ['formatura']],
        ['livros',         ['escola', 'curso', 'faculdade', 'educacao', 'livro']],
        ['cachorro',       ['=pet', 'cachorro', 'racao', 'veterinari', 'petshop', 'pet shop']],
        ['gato',           ['gato']],
        ['bebe',           ['bebe', '=filho', '=filha', 'crianca', 'fralda']],
        ['roupa',          ['roupa', 'vestuario', 'calcado']],
        ['militar',        ['quartel', 'exercito', 'militar', 'farda']],
        ['igreja',         ['igreja', 'dizimo', '=oferta', 'doacao', 'caridade']],
        ['maleta',         ['trabalho', 'escritorio']],
        ['caixa',          ['outros', 'diversos', 'pontua', 'eventua', 'imprevist']],
    ];

    /** Sem palavra conhecida no nome: o ícone do grupo. */
    public const POR_GRUPO = [
        'Entradas'   => 'moeda',
        'Fixos'      => 'recibo',
        'Dia a dia'  => 'sacola',
        'Cartao'     => 'cartao',
        'Ano'        => 'calendario',
        'Emprestimo' => 'banco',
        'Poupanca'   => 'porquinho',
        'Investimento' => 'grafico',
    ];

    private static ?array $lista = null;
    /** @var array<int, ?string>|null categorias.icone de todas as categorias (1 consulta por requisição) */
    private static ?array $porId = null;

    /** @return array{tamanho:int, colunas:int, linhas:int, grupos:array<string,string>, icones:array<string,array{0:string,1:int,2:int,3:string}>} */
    public static function lista(): array
    {
        return self::$lista ??= require __DIR__ . '/icones_lista.php';
    }

    public static function existe(?string $chave): bool
    {
        return $chave !== null && $chave !== '' && isset(self::lista()['icones'][$chave]);
    }

    public static function rotulo(string $chave): string
    {
        return isset(self::lista()['icones'][$chave]) ? t((string) self::lista()['icones'][$chave][0]) : $chave;
    }

    /** @return array<string, array{rotulo:string, chaves:list<string>}> os ícones por grupo, na ordem do sprite */
    public static function porGrupo(): array
    {
        $l = self::lista();
        $out = [];
        foreach ($l['grupos'] as $g => $rotulo) {
            $out[$g] = ['rotulo' => t((string) $rotulo), 'chaves' => []];
        }
        foreach ($l['icones'] as $chave => [, , , $g]) {
            $out[$g]['chaves'][] = $chave;
        }
        return $out;
    }

    /** "Farmácia e saúde" → "farmacia e saude". */
    public static function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /** Ícone sugerido pelo nome; sem palavra conhecida, o do grupo (ou a sacola). */
    public static function sugerir(string $nome, string $grupo = ''): string
    {
        $norm = self::normalizar($nome);
        $palavras = $norm === '' ? [] : explode(' ', $norm);
        foreach (self::REGRAS as [$chave, $termos]) {
            foreach ($termos as $t) {
                if (self::casa($t, $norm, $palavras)) {
                    return $chave;
                }
            }
        }
        return self::POR_GRUPO[$grupo] ?? 'sacola';
    }

    private static function casa(string $termo, string $norm, array $palavras): bool
    {
        if ($termo[0] === '=') {
            return in_array(substr($termo, 1), $palavras, true);
        }
        if (str_contains($termo, ' ')) {
            return str_contains(' ' . $norm . ' ', ' ' . $termo);
        }
        foreach ($palavras as $p) {
            if (str_starts_with($p, $termo)) {
                return true;
            }
        }
        return false;
    }

    /** O ícone da categoria: o guardado, se ainda existe; senão o sugerido pelo nome e grupo. */
    public static function daCategoria(int $id, string $nome = '', string $grupo = ''): string
    {
        self::$porId ??= CategoriaModel::icones();
        $guardado = self::$porId[$id] ?? null;
        return self::existe($guardado) ? (string) $guardado : self::sugerir($nome, $grupo);
    }

    /** Esquece o cache (depois de gravar um ícone, e nos testes). */
    public static function limpar(): void
    {
        self::$porId = null;
    }

    /**
     * <span> do tamanho $px (o círculo) com o emoji do sprite dentro. Decorativo.
     * Cada célula do sprite tem 'tamanho' px de desenho a cada 'passo' px (o resto é respiro).
     */
    public static function html(string $chave, int $px = 40, string $classe = ''): string
    {
        $l = self::lista();
        [, $c, $r] = $l['icones'][$chave] ?? $l['icones']['sacola'];
        $t = $l['tamanho'];
        $p = $l['passo'];
        $w = $l['colunas'] * $p;
        $h = $l['linhas'] * $p;
        $tam = round($w / $t * 100, 4) . '% ' . round($h / $t * 100, 4) . '%';
        $pos = round($c * $p / ($w - $t) * 100, 4) . '% ' . round($r * $p / ($h - $t) * 100, 4) . '%';
        $cls = 'ci' . ($classe !== '' ? ' ' . $classe : '');
        return '<span class="' . e($cls) . '" style="width:' . $px . 'px;height:' . $px . 'px" aria-hidden="true">'
            . '<i style="background-size:' . $tam . ';background-position:' . $pos . '"></i></span>';
    }

    /** Regras e ícones por grupo em JSON, para o formulário sugerir o ícone enquanto se digita. */
    public static function regrasJson(): string
    {
        return (string) json_encode(['regras' => self::REGRAS, 'grupos' => self::POR_GRUPO], JSON_UNESCAPED_UNICODE);
    }
}
