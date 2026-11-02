<?php
namespace Financeiro\Controllers;

use Financeiro\Atualizacao;
use Financeiro\Auth;
use Financeiro\Backup;
use Financeiro\Models\UsuarioModel;
use Financeiro\Paths;
use Financeiro\Primeira;
use Financeiro\QrCode;
use Financeiro\Rede;
use Financeiro\View;

/**
 * Backup, atualização e acesso pela rede, sem terminal. Só a conta administradora age (Auth::podeAdministrar);
 * as outras veem a versão e o endereço.
 *
 * As mensagens de Backup e Atualizacao são escritas em português e traduzidas ao mostrar, porque a atualização roda
 * em outro processo:
 * i18n: t('Esse arquivo não é um backup do Financeiro.') t('Esse backup está danificado.') t('Não deu para guardar a cópia.')
 * i18n: t('Não deu para ler o backup.') t('Esse backup veio de uma versão mais nova do Financeiro. Atualize o Financeiro antes de restaurar.')
 * i18n: t('Não consigo gravar na pasta de dados.') t('Este PHP não consegue conferir a assinatura da atualização.')
 * i18n: t('A assinatura da atualização não confere. Nada foi baixado.') t('A resposta sobre a versão nova veio incompleta.')
 * i18n: t('A atualização parou no meio. Se o site estranhar algo, restaure o backup mais recente.') t('Outra atualização está rodando.')
 * i18n: t('Esta instalação não atualiza pela tela.') t('Esta versão não está mais disponível. Consulte de novo.') t('Não deu para fazer o backup.')
 * i18n: t('Uma mudança no banco falhou.') t('Não deu para trocar o programa.') t('A conferência da versão nova falhou.')
 * i18n: t('A atualização falhou e o site voltou para a versão anterior.') t('A atualização falhou e não consegui voltar sozinho. Restaure o backup mais recente e instale o pacote de novo.')
 * i18n: t('O arquivo baixado não confere com a versão assinada.') t('O pacote baixado está danificado.') t('O pacote baixado não é o da versão anunciada.')
 * i18n: t('Uma mudança no banco falhou no ensaio.') t('Os testes da versão nova falharam no ensaio.') t('Falta a extensão curl do PHP para consultar a versão nova.')
 * i18n: t('O endereço de atualização precisa ser HTTPS.') t('O repositório ainda não tem versão publicada.') t('Não consegui falar com o repositório. Confira a internet e tente de novo.')
 * i18n: t('Não consegui baixar a versão nova. Confira a internet e tente de novo.') t('Não deu para iniciar a atualização.')
 * i18n: t('Começando') t('Baixando a versão nova') t('Ensaiando numa cópia dos seus dados') t('Fazendo backup') t('Aplicando') t('Conferindo o site')
 */
final class SistemaController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pode = Auth::podeAdministrar();
        $estado = Atualizacao::estado();
        if ($estado !== null && $estado['fase'] === 'rodando') {
            header('Refresh: 3');
        }
        $consulta = $_SESSION['sis_consulta'] ?? null;
        unset($_SESSION['sis_consulta']);
        $backups = Backup::disponivel() && $pode ? Backup::listar() : [];
        $motivos = ['diario' => t('do dia'), 'manual' => t('feito por você'), 'antes-atualizacao' => t('antes de atualizar'), 'antes-restaurar' => t('antes de restaurar')];

        View::render('sistema/index', [
            'pageTitle' => t('backup e atualização · financeiro'),
            'pode' => $pode, 'administrador' => Auth::administrador(),
            'nomeAdministrador' => (string) (UsuarioModel::find((int) UsuarioModel::administradorId())['nome'] ?? ''),
            'tipo' => Atualizacao::tipo(), 'versao' => Atualizacao::versaoAtual(), 'estado' => $estado, 'consulta' => is_array($consulta) ? $consulta : null,
            'backupDisponivel' => Backup::disponivel(), 'backups' => array_map(static fn(array $b): array => ['rotulo' => self::rotuloBackup($b, $motivos)] + $b, $backups),
            'pastaBackups' => Backup::disponivel() && Rede::requisicaoLocal() ? Paths::paraMostrar(Backup::pasta()) : null,
            'limiteEnvio' => self::limiteEnvio(),
        ], 'ajustes');

        if ($pode && $estado !== null && $estado['fase'] !== 'rodando') {
            Atualizacao::limparEstado();
        }
    }

    /* ---- backup */

    public function fazerBackup(): void
    {
        $this->exigirBackup();
        try {
            Backup::criar('manual');
            flash('success', t('Backup feito.'));
        } catch (\Throwable $e) {
            error_log('backup: ' . $e->getMessage());
            flash('error', t('Não deu para fazer o backup. Confira se há espaço em disco.'));
        }
        redirecionar('/sistema#backup', 303);
    }

    public function exportar(): void
    {
        $this->exigirBackup();
        $this->exigirSenha('#backup');
        try {
            $tmp = Backup::paraBaixar();
        } catch (\Throwable $e) {
            error_log('backup: ' . $e->getMessage());
            flash('error', t('Não deu para fazer o backup. Confira se há espaço em disco.'));
            redirecionar('/sistema#backup', 303);
        }
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="financeiro-backup-' . date('Y-m-d') . '.sqlite"');
        header('Content-Length: ' . (int) filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        exit;
    }

    public function restaurar(): void
    {
        // arquivo maior que o limite: o PHP descarta o formulário inteiro, e o CSRF reclamaria de outra coisa
        if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Auth::requireLogin();
            flash('error', t('O arquivo é maior que o limite de envio ({limite}). Use a lista de backups deste computador.', ['limite' => self::limiteEnvio()]));
            redirecionar('/sistema#restaurar', 303);
        }
        $this->exigirBackup();
        $this->exigirSenha('#restaurar');

        $origem = null;
        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        if ($nome !== '') {
            $origem = Backup::caminho($nome);
        } elseif (is_array($_FILES['arquivo'] ?? null)) {
            $erro = (int) $_FILES['arquivo']['error'];
            if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
                flash('error', t('O arquivo é maior que o limite de envio ({limite}). Use a lista de backups deste computador.', ['limite' => self::limiteEnvio()]));
                redirecionar('/sistema#restaurar', 303);
            }
            if ($erro === UPLOAD_ERR_OK && is_uploaded_file((string) $_FILES['arquivo']['tmp_name'])) {
                $origem = (string) $_FILES['arquivo']['tmp_name'];
            }
        }
        if ($origem === null) {
            flash('error', t('Escolha um backup da lista ou envie um arquivo.'));
            redirecionar('/sistema#restaurar', 303);
        }
        try {
            Backup::restaurar($origem);
        } catch (\RuntimeException $e) {
            error_log('restaurar: ' . $e->getMessage());
            flash('error', t($e->getMessage()));
            redirecionar('/sistema#restaurar', 303);
        } catch (\Throwable $e) {
            error_log('restaurar: ' . $e->getMessage());
            flash('error', t('Esse backup está danificado.'));
            redirecionar('/sistema#restaurar', 303);
        }
        session_regenerate_id(true);
        redirecionar('/login?restaurado=1', 303);
    }

    /* ---- atualização */

    public function consultar(): void
    {
        $this->exigirAdministracao();
        try {
            $m = Atualizacao::consultar();
            $_SESSION['sis_consulta'] = ['versao' => $m['versao'], 'notas' => $m['notas'], 'nova' => Atualizacao::maisNova($m['versao'])];
        } catch (\RuntimeException $e) {
            error_log('atualização: ' . $e->getMessage());
            flash('error', t($e->getMessage()));
        }
        redirecionar('/sistema#versao', 303);
    }

    public function atualizar(): void
    {
        $this->exigirAdministracao();
        $versao = is_string($_POST['versao'] ?? null) ? $_POST['versao'] : '';
        $estado = Atualizacao::estado();
        if (Atualizacao::tipo() !== 'pacote' || !preg_match('/^\d+\.\d+\.\d+$/', $versao)) {
            flash('error', t('Esta instalação não atualiza pela tela.'));
        } elseif ($estado === null || $estado['fase'] !== 'rodando') {
            Atualizacao::iniciar($versao);
        }
        redirecionar('/sistema#versao', 303);
    }

    /* ---- acesso pela rede */

    public function rede(): void
    {
        Auth::requireLogin();
        $modo = Primeira::modo();
        $ligada = $modo !== 'instalador' || Rede::acessoLigado();
        $ip = Rede::ipLocal();
        $porta = Rede::porta();
        if ($modo === 'instalador') {
            $url = $ip !== null ? 'http://' . $ip . ($porta === 80 ? '' : ':' . $porta) : null;
        } else {
            $url = (Rede::https() ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? '');
        }
        View::render('sistema/rede', [
            'pageTitle' => t('usar no celular · financeiro'),
            'modo' => $modo, 'ligada' => $ligada, 'gerenciada' => Rede::gerenciada(), 'pode' => Auth::podeAdministrar(), 'administrador' => Auth::administrador(),
            'url' => $url, 'qr' => $url !== null && $ligada ? QrCode::svg($url, t('Código QR com o endereço {url}', ['url' => $url])) : null,
            'porta' => $porta, 'sistema' => PHP_OS_FAMILY,
        ], 'ajustes');
    }

    public function salvarRede(): void
    {
        $this->exigirAdministracao();
        $ligar = ($_POST['ligar'] ?? '') === '1';
        if (Primeira::modo() !== 'instalador' || !Rede::gerenciada()) {
            flash('error', t('Esta instalação não liga a rede pela tela.'));
            redirecionar('/rede', 303);
        }
        if ($ligar && Rede::ipLocal() === null) {
            flash('error', t('Este computador não parece estar em uma rede. Conecte-o ao Wi-Fi ou ao cabo e tente de novo.'));
            redirecionar('/rede', 303);
        }
        Primeira::gravarConfig('ACESSO_REDE', $ligar ? '1' : '0');
        Rede::pedirReinicio();
        header('Refresh: 6; url=/rede');
        View::render('sistema/reiniciando', ['pageTitle' => t('usar no celular · financeiro'), 'ligar' => $ligar], 'ajustes');
    }

    /* ---- internos */

    private function exigirAdministracao(): void
    {
        Auth::requireLogin();
        csrfCheck();
        if (!Auth::podeAdministrar()) {
            flash('error', Auth::administrador()
                ? t('Faça isso no computador onde o Financeiro está instalado.')
                : t('Só a primeira conta criada neste site pode fazer isso.'));
            redirecionar('/sistema', 303);
        }
    }

    private function exigirBackup(): void
    {
        $this->exigirAdministracao();
        if (!Backup::disponivel()) {
            flash('error', t('Neste site, os backups são feitos no servidor.'));
            redirecionar('/sistema', 303);
        }
    }

    private function exigirSenha(string $ancora): void
    {
        $senha = is_string($_POST['senha_atual'] ?? null) ? $_POST['senha_atual'] : '';
        $r = Auth::conferirSenhaAtual((int) Auth::id(), $senha);
        if ($r !== 'ok') {
            flash('error', $r === 'bloqueado' ? t('Muitas tentativas. Espere alguns minutos.') : t('A sua senha não confere.'));
            redirecionar('/sistema' . $ancora, 303);
        }
    }

    /** @param array{quando:string, motivo:string, tamanho:int} $b  @param array<string, string> $motivos */
    private static function rotuloBackup(array $b, array $motivos): string
    {
        return t('{data}, {motivo}, {tamanho}', [
            'data' => date('d/m/Y H:i', (int) strtotime($b['quando'])), 'motivo' => $motivos[$b['motivo']] ?? $b['motivo'],
            'tamanho' => self::tamanho($b['tamanho']),
        ]);
    }

    private static function tamanho(int $bytes): string
    {
        return $bytes >= 1048576 ? num($bytes / 1048576, 1) . ' MB' : num(max(1, (int) round($bytes / 1024)), 0) . ' KB';
    }

    private static function limiteEnvio(): string
    {
        $bytes = static function (string $v): int {
            $n = (int) $v;
            return match (strtoupper(substr(trim($v), -1))) {
                'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n,
            };
        };
        return self::tamanho(min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))));
    }
}
