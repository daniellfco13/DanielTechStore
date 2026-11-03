<?php
/**
 * GET  perfildaniel.php — devolve os dados do cliente logado (401 se não houver sessão).
 * POST perfildaniel.php — atualiza os dados do cliente logado (o e-mail não é editável aqui).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/dbdaniel.php';

function responder(bool $ok, string $msg, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['sucesso' => $ok, 'mensagem' => $msg], $extra));
    exit;
}

const COLUNAS = 'id, nome, sobrenome, email, idade, sexo, cep, cidade, bairro, rua, numero';

$id = (int)($_SESSION['cliente_id'] ?? 0);
if ($id <= 0) responder(false, 'Não autenticado.', 401);

$pdo = getConexao();

function buscar(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . COLUNAS . ' FROM clientes WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $u = buscar($pdo, $id);
    if (!$u) { $_SESSION = []; responder(false, 'Conta não encontrada.', 401); }
    responder(true, 'ok', 200, ['usuario' => $u]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método não permitido.', 405);

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) responder(false, 'Dados inválidos.', 400);

$nome      = trim((string)($d['nome'] ?? ''));
$sobrenome = trim((string)($d['sobrenome'] ?? ''));
$idade     = (int)($d['idade'] ?? 0);
$sexo      = (string)($d['sexo'] ?? '');
$cep       = trim((string)($d['cep'] ?? ''));
$cidade    = trim((string)($d['cidade'] ?? ''));
$bairro    = trim((string)($d['bairro'] ?? ''));
$rua       = trim((string)($d['rua'] ?? ''));
$numero    = trim((string)($d['numero'] ?? ''));

$erros = [];
if (mb_strlen($nome) < 2)      $erros[] = 'Nome inválido.';
if (mb_strlen($sobrenome) < 2) $erros[] = 'Sobrenome inválido.';
if ($idade < 13 || $idade > 120) $erros[] = 'Idade inválida.';
if (!in_array($sexo, ['feminino', 'masculino', 'outro', 'prefiro-nao-informar'], true)) $erros[] = 'Sexo inválido.';
if (!preg_match('/^\d{5}-?\d{3}$/', $cep)) $erros[] = 'CEP inválido.';
if (mb_strlen($cidade) < 2)    $erros[] = 'Cidade inválida.';
if (mb_strlen($bairro) < 2)    $erros[] = 'Bairro inválido.';
if (mb_strlen($rua) < 2)       $erros[] = 'Rua inválida.';
if ($numero === '')            $erros[] = 'Número inválido.';
if ($erros) responder(false, 'Corrija os campos indicados.', 422, ['erros' => $erros]);

if (!str_contains($cep, '-')) $cep = substr($cep, 0, 5) . '-' . substr($cep, 5);

try {
    $st = $pdo->prepare('UPDATE clientes SET nome=:nome, sobrenome=:sobrenome, idade=:idade, sexo=:sexo,
                         cep=:cep, cidade=:cidade, bairro=:bairro, rua=:rua, numero=:numero WHERE id=:id');
    $st->execute(compact('nome', 'sobrenome', 'idade', 'sexo', 'cep', 'cidade', 'bairro', 'rua', 'numero') + ['id' => $id]);
} catch (PDOException $e) {
    responder(false, 'Não foi possível salvar. Tente novamente.', 500);
}

responder(true, 'Dados atualizados!', 200, ['usuario' => buscar($pdo, $id)]);