<?php
/** POST logindaniel.php — valida e-mail/senha, abre a sessão e devolve o perfil (sem a senha). */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/dbdaniel.php';
require __DIR__ . '/paineladmdaniel.php';

function responder(bool $ok, string $msg, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['sucesso' => $ok, 'mensagem' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método não permitido.', 405);

$d = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($d['email'] ?? ''));
$senha = (string)($d['senha'] ?? '');
if ($email === '' || $senha === '') responder(false, 'Informe e-mail e senha.', 422);

$pdo = getConexao();

// Acesso do administrador: mesmo formulário de login, e-mail de acesso reservado + senha do painel.
// O navegador recebe "admin: true" e é levado direto para o painel.
if (painel_email_reservado($email)) {
    try {
        list($ok, $status, $msg) = painel_tentar_login($pdo, $senha);
    } catch (PDOException $e) {
        responder(false, 'Erro no banco de dados. Confira se o arquivo painel_tabelasdaniel.sql foi executado.', 500);
    }
    if (!$ok) responder(false, $msg, $status);
    responder(true, 'Login realizado!', 200, ['admin' => true, 'redirecionar' => PAINEL_PAGINA]);
}
$st = $pdo->prepare('SELECT id, nome, sobrenome, email, senha_hash, idade, sexo, cep, cidade, bairro, rua, numero
                     FROM clientes WHERE email = :e LIMIT 1');
$st->execute(['e' => $email]);
$u = $st->fetch();

if (!$u || !password_verify($senha, $u['senha_hash'])) {
    responder(false, 'E-mail ou senha inválidos.', 401);
}

session_regenerate_id(true);
$_SESSION['cliente_id'] = (int)$u['id'];
unset($u['senha_hash']);
responder(true, 'Login realizado!', 200, ['usuario' => $u]);