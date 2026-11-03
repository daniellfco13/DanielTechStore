<?php
/**
 * paineladmdaniel.php — acesso do administrador
 *
 * Usado por logindaniel.php, cadastrodaniel.php e painel_apidaniel.php.
 *
 * COMO O ADMINISTRADOR ENTRA
 *   Na página de login normal do site (logindaniel.html), digitando:
 *       E-mail: o "e-mail de acesso" definido abaixo (não precisa existir de verdade)
 *       Senha : a senha do painel
 *   O servidor confere, abre a sessão de administrador e o navegador é levado
 *   direto para o painel. Não existe outra tela de senha.
 *
 * Fica tudo aqui, no servidor: nem a senha nem o e-mail de acesso aparecem
 * em nenhuma página do site.
 */

// E-mail de acesso do administrador. Precisa ter formato de e-mail (o campo do
// login exige). Pode trocar por qualquer um que só você saiba. Ninguém consegue
// criar uma conta de cliente com ele (o cadastro recusa).
const PAINEL_EMAIL_ACESSO = 'danielpereira2527@gmail.com';

// Hash bcrypt da senha do painel (a senha em si não está em lugar nenhum do site).
// Para TROCAR a senha, gere um novo hash e cole aqui:
//     php -r "echo password_hash('NOVA_SENHA', PASSWORD_DEFAULT);"
const PAINEL_SENHA_HASH = '$2y$12$y3BbuGuTGsY.ZyBz16UQVe0Khhp5IKMmxwLRXH6QDnvQu70i6W.Ba';

const PAINEL_PAGINA         = 'paineldaniel.html'; // para onde o administrador é levado após entrar
const PAINEL_TIMEOUT        = 7200;                // segundos sem uso até pedir login de novo (2h)
const PAINEL_MAX_TENTATIVAS = 5;                   // erros de senha permitidos por IP...
const PAINEL_JANELA_MIN     = 15;                  // ...dentro desta janela (minutos)

/** O e-mail digitado é o e-mail de acesso do administrador? */
function painel_email_reservado(string $email): bool
{
    return strcasecmp(trim($email), PAINEL_EMAIL_ACESSO) === 0;
}

/** Já existe sessão de administrador válida? (renova o prazo a cada uso) */
function painel_admin_logado(): bool
{
    if (empty($_SESSION['admin_ok'])) return false;
    $ultimo = (int)($_SESSION['admin_ts'] ?? 0);
    if (time() - $ultimo > PAINEL_TIMEOUT) {
        unset($_SESSION['admin_ok'], $_SESSION['admin_ts']);
        return false;
    }
    $_SESSION['admin_ts'] = time();
    return true;
}

/**
 * Confere a senha do painel (com bloqueio de força bruta por IP) e, se estiver
 * certa, abre a sessão de administrador.
 * Retorna [ok, status HTTP, mensagem]. A mensagem de erro é a mesma do login
 * comum, para não revelar que este e-mail é especial.
 */
function painel_tentar_login(PDO $pdo, string $senha): array
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_tentativas WHERE ip = :ip
         AND criado_em > (NOW() - INTERVAL ' . (int)PAINEL_JANELA_MIN . ' MINUTE)'
    );
    $st->execute([':ip' => $ip]);
    if ((int)$st->fetchColumn() >= PAINEL_MAX_TENTATIVAS) {
        return [false, 429, 'Muitas tentativas incorretas. Aguarde ' . PAINEL_JANELA_MIN . ' minutos e tente de novo.'];
    }

    if ($senha === '' || !password_verify($senha, PAINEL_SENHA_HASH)) {
        $pdo->prepare('INSERT INTO admin_tentativas (ip) VALUES (:ip)')->execute([':ip' => $ip]);
        $pdo->exec('DELETE FROM admin_tentativas WHERE criado_em < (NOW() - INTERVAL 1 DAY)');
        usleep(600000); // freia quem testa senhas em sequência
        return [false, 401, 'E-mail ou senha inválidos.'];
    }

    $pdo->prepare('DELETE FROM admin_tentativas WHERE ip = :ip')->execute([':ip' => $ip]);
    session_regenerate_id(true);
    $_SESSION['admin_ok'] = true;
    $_SESSION['admin_ts'] = time();
    return [true, 200, 'ok'];
}
