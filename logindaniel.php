<?php
/** POST logindaniel.php — login de cliente/administrador. Administrador com a senha do painel vai ao painel. */
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

/** Abre a sessão da conta (o site passa a reconhecer a pessoa como logada). */
function abrir_sessao_conta(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['cliente_id'] = $id;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método não permitido.', 405);

$d = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($d['email'] ?? ''));
$senha = (string)($d['senha'] ?? '');
if ($email === '' || $senha === '') responder(false, 'Informe e-mail e senha.', 422);

$msgInvalida = 'E-mail ou senha inválidos.';
$pdo = getConexao();

$st = $pdo->prepare('SELECT id, nome, sobrenome, email, senha_hash, idade, sexo, cep, cidade, bairro, rua, numero, is_admin
                     FROM clientes WHERE email = :e AND excluido_em IS NULL LIMIT 1');
$st->execute(['e' => $email]);
$u = $st->fetch();

// Conta inexistente ou senha da conta errada: para usuário comum, é só "inválido".
if (!$u) responder(false, $msgInvalida, 401);

$ehAdmin  = (int)$u['is_admin'] === 1;
$senhaConta = password_verify($senha, $u['senha_hash']);

if ($ehAdmin) {
    $bloqueado = painel_bloqueado($pdo);

    // Administrador + senha do painel → painel
    if (!$bloqueado && painel_senha_correta($senha)) {
        abrir_sessao_conta((int)$u['id']);
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_ts'] = time();
        painel_limpar_falhas($pdo);
        unset($u['senha_hash'], $u['is_admin']);
        responder(true, 'Login realizado!', 200, ['usuario' => $u, 'admin' => true, 'redirecionar' => PAINEL_PAGINA]);
    }

    // Administrador + própria senha → site normal (continua abaixo)
    if (!$senhaConta) {
        painel_registrar_falha($pdo);
        responder(false, $bloqueado ? 'Muitas tentativas incorretas. Aguarde ' . PAINEL_JANELA_MIN . ' minutos e tente de novo.' : $msgInvalida, $bloqueado ? 429 : 401);
    }
}

// Usuário comum (ou administrador usando a própria senha): login normal do site
if (!$senhaConta) responder(false, $msgInvalida, 401);

abrir_sessao_conta((int)$u['id']);
unset($u['senha_hash'], $u['is_admin']);
responder(true, 'Login realizado!', 200, ['usuario' => $u]);