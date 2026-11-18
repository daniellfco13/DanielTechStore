<?php
/**
 * paineladmdaniel.php — acesso ao painel administrativo
 *
 * Regras:
 *   • Só entra no painel quem tem a conta com status de administrador (is_admin = 1)
 *     E digita a senha do painel no lugar da senha da conta.
 *   • Administrador que digita a PRÓPRIA senha entra no site normalmente.
 *   • Usuário comum nunca entra no painel, mesmo sabendo a senha do painel.
 *
 * A senha do painel fica só aqui, como hash. Nenhuma página do site a mostra.
 */

// Hash bcrypt da senha do painel (a senha é: daniel13tech).
// Para trocar: php -r "echo password_hash('NOVA_SENHA', PASSWORD_DEFAULT);"
const PAINEL_SENHA_HASH = '$2y$12$y3BbuGuTGsY.ZyBz16UQVe0Khhp5IKMmxwLRXH6QDnvQu70i6W.Ba';

const PAINEL_PAGINA         = 'paineldaniel.html'; // painel fica em /HTML/
const PAINEL_TIMEOUT        = 7200;                // segundos sem uso até pedir login de novo (2h)
const PAINEL_MAX_TENTATIVAS = 5;                   // senhas erradas permitidas por IP...
const PAINEL_JANELA_MIN     = 15;                  // ...dentro desta janela (minutos)

/** A senha digitada é a senha do painel? */
function painel_senha_correta(string $senha): bool
{
    return $senha !== '' && password_verify($senha, PAINEL_SENHA_HASH);
}

/** Este IP errou senhas de administrador demais recentemente? */
function painel_bloqueado(PDO $pdo): bool
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $st = $pdo->prepare('SELECT COUNT(*) FROM admin_tentativas WHERE ip = :ip
                         AND criado_em > (NOW() - INTERVAL ' . (int)PAINEL_JANELA_MIN . ' MINUTE)');
    $st->execute([':ip' => $ip]);
    return (int)$st->fetchColumn() >= PAINEL_MAX_TENTATIVAS;
}

/** Registra uma senha errada de administrador (freia quem testa senhas em sequência). */
function painel_registrar_falha(PDO $pdo): void
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $pdo->prepare('INSERT INTO admin_tentativas (ip) VALUES (:ip)')->execute([':ip' => $ip]);
    $pdo->exec('DELETE FROM admin_tentativas WHERE criado_em < (NOW() - INTERVAL 1 DAY)');
    usleep(600000);
}

/** Zera as falhas deste IP após um acesso bem-sucedido. */
function painel_limpar_falhas(PDO $pdo): void
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $pdo->prepare('DELETE FROM admin_tentativas WHERE ip = :ip')->execute([':ip' => $ip]);
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