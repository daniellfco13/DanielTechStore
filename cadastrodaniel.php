<?php
/**
 * POST /api/cadastrodaniel.php
 *
 * Recebe os dados do formulário de cadastrodaniel.html e insere um novo
 * cliente na tabela `clientes`. Responde sempre em JSON.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

require __DIR__ . '/dbdaniel.php';
require __DIR__ . '/paineladmdaniel.php';

function responder(bool $sucesso, string $mensagem, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['sucesso' => $sucesso, 'mensagem' => $mensagem], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 'Método não permitido.', 405);
}

$dados = json_decode(file_get_contents('php://input'), true);
if (!is_array($dados)) {
    responder(false, 'Dados inválidos.', 400);
}

// -----------------------------------------------------------------
// Validação server-side — nunca confie apenas na validação do navegador
// -----------------------------------------------------------------
$sexosValidos = ['feminino', 'masculino', 'outro', 'prefiro-nao-informar'];

$nome       = trim((string)($dados['nome'] ?? ''));
$sobrenome  = trim((string)($dados['sobrenome'] ?? ''));
$email      = trim((string)($dados['email'] ?? ''));
$senha      = (string)($dados['senha'] ?? '');
$idade      = (int)($dados['idade'] ?? 0);
$sexo       = (string)($dados['sexo'] ?? '');
$cep        = trim((string)($dados['cep'] ?? ''));
$cidade     = trim((string)($dados['cidade'] ?? ''));
$bairro     = trim((string)($dados['bairro'] ?? ''));
$rua        = trim((string)($dados['rua'] ?? ''));
$numero     = trim((string)($dados['numero'] ?? ''));

$erros = [];

if (mb_strlen($nome) < 2)                              $erros[] = 'Nome inválido.';
if (mb_strlen($sobrenome) < 2)                         $erros[] = 'Sobrenome inválido.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))        $erros[] = 'E-mail inválido.';
if (mb_strlen($senha) < 6)                             $erros[] = 'A senha precisa de pelo menos 6 caracteres.';
if ($idade < 13 || $idade > 120)                       $erros[] = 'Idade inválida.';
if (!in_array($sexo, $sexosValidos, true))             $erros[] = 'Sexo inválido.';
if (!preg_match('/^\d{5}-?\d{3}$/', $cep))             $erros[] = 'CEP inválido.';
if (mb_strlen($cidade) < 2)                            $erros[] = 'Cidade inválida.';
if (mb_strlen($bairro) < 2)                            $erros[] = 'Bairro inválido.';
if (mb_strlen($rua) < 2)                               $erros[] = 'Rua inválida.';
if ($numero === '')                                    $erros[] = 'Número inválido.';

if ($erros) {
    responder(false, 'Corrija os campos indicados.', 422, ['erros' => $erros]);
}

// O e-mail de acesso do administrador é reservado: ninguém pode criar conta com ele.
// (mesma mensagem de "e-mail já cadastrado", para não revelar que ele é especial)
if (painel_email_reservado($email)) {
    responder(false, 'Já existe uma conta cadastrada com este e-mail.', 409);
}

// normaliza o CEP para o formato 00000-000
if (!str_contains($cep, '-')) {
    $cep = substr($cep, 0, 5) . '-' . substr($cep, 5);
}

$pdo = getConexao();

// e-mail duplicado
$stmt = $pdo->prepare('SELECT id FROM clientes WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    responder(false, 'Já existe uma conta cadastrada com este e-mail.', 409);
}

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('
    INSERT INTO clientes
        (nome, sobrenome, email, senha_hash, idade, sexo, cep, cidade, bairro, rua, numero)
    VALUES
        (:nome, :sobrenome, :email, :senha_hash, :idade, :sexo, :cep, :cidade, :bairro, :rua, :numero)
');

try {
    $stmt->execute([
        'nome'       => $nome,
        'sobrenome'  => $sobrenome,
        'email'      => $email,
        'senha_hash' => $senhaHash,
        'idade'      => $idade,
        'sexo'       => $sexo,
        'cep'        => $cep,
        'cidade'     => $cidade,
        'bairro'     => $bairro,
        'rua'        => $rua,
        'numero'     => $numero,
    ]);
} catch (PDOException $e) {
    responder(false, 'Não foi possível concluir o cadastro. Tente novamente.', 500);
}

$novoId = (int)$pdo->lastInsertId();
session_regenerate_id(true);
$_SESSION['cliente_id'] = $novoId;
responder(true, 'Conta criada com sucesso!', 201, ['id' => $novoId]);