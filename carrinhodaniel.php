<?php
/**
 * carrinhodaniel.php — carrinho guardado na conta do cliente logado.
 *   GET  → {itens: [{id, qtd}]}
 *   POST → {itens: [{id, qtd}]} substitui o carrinho inteiro da conta
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
    echo json_encode(array_merge(['sucesso' => $ok, 'mensagem' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

const MAX_QTD = 20;

$pdo = getConexao();
$clienteId = (int)($_SESSION['cliente_id'] ?? 0);
$st = $pdo->prepare('SELECT id FROM clientes WHERE id = :id AND excluido_em IS NULL');
$st->execute([':id' => $clienteId]);
if ($clienteId <= 0 || !$st->fetch()) responder(false, 'Entre na sua conta para ver o carrinho.', 401);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT c.produto_id AS id, c.quantidade AS qtd FROM carrinho_itens c
                         JOIN produtos p ON p.id = c.produto_id AND p.ativo = 1
                         WHERE c.cliente_id = :c ORDER BY c.produto_id');
    $st->execute([':c' => $clienteId]);
    responder(true, 'ok', 200, ['itens' => array_map(fn($r) => ['id' => (int)$r['id'], 'qtd' => (int)$r['qtd']], $st->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método não permitido.', 405);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'site') responder(false, 'Requisição não autorizada.', 403);

$d = json_decode((string)file_get_contents('php://input'), true);
$itens = is_array($d) && is_array($d['itens'] ?? null) ? $d['itens'] : [];
if (count($itens) > 50) responder(false, 'Carrinho grande demais.', 422);

// junta repetidos, descarta inválidos e limita a quantidade
$qtds = [];
foreach ($itens as $it) {
    $id = is_array($it) ? (int)($it['id'] ?? 0) : 0;
    $qtd = is_array($it) ? (int)($it['qtd'] ?? 0) : 0;
    if ($id <= 0 || $qtd <= 0) continue;
    $qtds[$id] = min(MAX_QTD, ($qtds[$id] ?? 0) + $qtd);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM carrinho_itens WHERE cliente_id = :c')->execute([':c' => $clienteId]);
    if ($qtds) {
        $ids = array_keys($qtds);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM produtos WHERE ativo = 1 AND id IN ($marcas)");
        $st->execute($ids);
        $validos = array_map('intval', array_column($st->fetchAll(), 'id'));
        $ins = $pdo->prepare('INSERT INTO carrinho_itens (cliente_id, produto_id, quantidade) VALUES (:c, :p, :q)');
        foreach ($qtds as $id => $qtd) {
            if (in_array($id, $validos, true)) $ins->execute([':c' => $clienteId, ':p' => $id, ':q' => $qtd]);
        }
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    responder(false, 'Não foi possível salvar o carrinho.', 500);
}
responder(true, 'Carrinho salvo.');