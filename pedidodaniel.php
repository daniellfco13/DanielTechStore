<?php
/**
 * POST pedidodaniel.php  {itens: [{id, qtd}, ...]}
 *
 * Registra um pedido do cliente logado. O PREÇO NUNCA vem do navegador:
 * o servidor busca cada produto na tabela `produtos` e calcula o total.
 * Todo pedido nasce com status "pendente" — você confirma o pagamento
 * no painel administrativo.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
} else {
    session_set_cookie_params(0, '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
}
session_start();
require __DIR__ . '/dbdaniel.php';

function responder(bool $ok, string $msg, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['sucesso' => $ok, 'mensagem' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método não permitido.', 405);

$clienteId = (int)($_SESSION['cliente_id'] ?? 0);
if ($clienteId <= 0) responder(false, 'Entre na sua conta para finalizar a compra.', 401);

$d = json_decode((string)file_get_contents('php://input'), true);
$itens = is_array($d) ? ($d['itens'] ?? null) : null;
if (!is_array($itens) || count($itens) < 1 || count($itens) > 30) {
    responder(false, 'Carrinho inválido.', 422);
}

// junta ids repetidos e valida as quantidades
$qtds = [];
foreach ($itens as $it) {
    $id  = is_array($it) ? (int)($it['id'] ?? 0) : 0;
    $qtd = is_array($it) ? (int)($it['qtd'] ?? 0) : 0;
    if ($id <= 0 || $qtd < 1) responder(false, 'Carrinho inválido.', 422);
    $qtds[$id] = ($qtds[$id] ?? 0) + $qtd;
    if ($qtds[$id] > 20) responder(false, 'Quantidade máxima por produto: 20 unidades.', 422);
}

try {
    $pdo = getConexao();

    $st = $pdo->prepare('SELECT id FROM clientes WHERE id = :id AND excluido_em IS NULL');
    $st->execute([':id' => $clienteId]);
    if (!$st->fetch()) responder(false, 'Entre na sua conta para finalizar a compra.', 401);

    // trava simples contra spam de pedidos
    $st = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE cliente_id = :c AND criado_em > (NOW() - INTERVAL 10 MINUTE)');
    $st->execute([':c' => $clienteId]);
    if ((int)$st->fetchColumn() >= 5) {
        responder(false, 'Você fez muitos pedidos em pouco tempo. Aguarde alguns minutos.', 429);
    }

    $ids = array_keys($qtds);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, nome, categoria, preco FROM produtos WHERE ativo = 1 AND id IN ($marcas)");
    $st->execute($ids);
    $produtos = [];
    foreach ($st->fetchAll() as $p) $produtos[(int)$p['id']] = $p;

    if (count($produtos) !== count($qtds)) {
        responder(false, 'Um ou mais produtos do carrinho não estão mais disponíveis. Atualize a página e tente de novo.', 422);
    }

    // total em centavos (inteiros, sem erro de arredondamento)
    $totalCent = 0;
    foreach ($qtds as $id => $qtd) {
        $totalCent += (int)round(((float)$produtos[$id]['preco']) * 100) * $qtd;
    }
    $total = number_format($totalCent / 100, 2, '.', '');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO pedidos (cliente_id, total) VALUES (:c, :t)')
            ->execute([':c' => $clienteId, ':t' => $total]);
        $pedidoId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO pedido_itens (pedido_id, produto_id, nome, categoria, preco_unit, quantidade)
                              VALUES (:pe, :pr, :n, :c, :pu, :q)');
        foreach ($qtds as $id => $qtd) {
            $p = $produtos[$id];
            $ins->execute([':pe' => $pedidoId, ':pr' => $id, ':n' => $p['nome'], ':c' => $p['categoria'],
                           ':pu' => $p['preco'], ':q' => $qtd]);
        }
        $pdo->prepare('DELETE FROM carrinho_itens WHERE cliente_id = :c')->execute([':c' => $clienteId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    responder(false, 'Não foi possível registrar o pedido. Tente novamente.', 500);
}

responder(true, 'Pedido registrado!', 201, ['pedido' => ['id' => $pedidoId, 'total' => (float)$total]]);