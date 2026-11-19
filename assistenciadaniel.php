<?php
/**
 * assistenciadaniel.php — área do cliente para assistência técnica.
 *
 *   POST ?acao=criar     {nome, telefone, email, tipoEquipamento, marcaModelo, tipoServico,
 *                         modalidade, dataPreferida, horarioPreferido, urgencia, descricao}
 *   GET  ?acao=listar                 → meus pedidos
 *   GET  ?acao=detalhe&id=            → um pedido + conversa (marca as mensagens da loja como lidas)
 *   POST ?acao=mensagem  {id, texto}  → envia mensagem à loja
 *   POST ?acao=cancelar  {id}         → cancela (só se ainda não foi concluído)
 */
declare(strict_types=1);
date_default_timezone_set('America/Fortaleza');
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
function corpo(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

const TIPOS_EQUIPAMENTO = ['desktop', 'notebook', 'console', 'periferico', 'outro'];
const TIPOS_SERVICO     = ['diagnostico', 'manutencao', 'reparo-hardware', 'software', 'montagem', 'outro'];
const MODALIDADES       = ['loja', 'coleta'];
const HORARIOS          = ['manha', 'tarde', 'qualquer'];
const URGENCIAS         = ['sem-pressa', 'poucos-dias', 'urgente'];
const LIMITE_EM_ANALISE = 3;

$pdo = getConexao();
$clienteId = (int)($_SESSION['cliente_id'] ?? 0);
$st = $pdo->prepare('SELECT id FROM clientes WHERE id = :id AND excluido_em IS NULL');
$st->execute([':id' => $clienteId]);
if ($clienteId <= 0 || !$st->fetch()) responder(false, 'Entre na sua conta para pedir assistência.', 401);

$acao = (string)($_GET['acao'] ?? '');
$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'site') {
    responder(false, 'Requisição não autorizada.', 403);
}

/** Pedido que pertence a este cliente, ou 404. */
function meu_pedido(PDO $pdo, int $clienteId, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM assistencias WHERE id = :id AND cliente_id = :c');
    $st->execute([':id' => $id, ':c' => $clienteId]);
    $r = $st->fetch();
    if (!$r) responder(false, 'Pedido não encontrado.', 404);
    return $r;
}

if ($acao === 'criar' && $metodo === 'POST') {
    $d = corpo();
    $f = [
        'nome'             => trim((string)($d['nome'] ?? '')),
        'telefone'         => trim((string)($d['telefone'] ?? '')),
        'email'            => trim((string)($d['email'] ?? '')),
        'tipo_equipamento' => (string)($d['tipoEquipamento'] ?? ''),
        'marca_modelo'     => trim((string)($d['marcaModelo'] ?? '')),
        'tipo_servico'     => (string)($d['tipoServico'] ?? ''),
        'modalidade'       => (string)($d['modalidade'] ?? ''),
        'data_preferida'   => (string)($d['dataPreferida'] ?? ''),
        'horario_preferido'=> (string)($d['horarioPreferido'] ?? ''),
        'urgencia'         => (string)($d['urgencia'] ?? ''),
        'descricao'        => trim((string)($d['descricao'] ?? '')),
    ];
    $erros = [];
    if (mb_strlen($f['nome']) < 2) $erros[] = 'Informe seu nome.';
    $fone = preg_replace('/\D/', '', $f['telefone']);
    if (strlen($fone) < 10 || strlen($fone) > 11) $erros[] = 'Informe um telefone válido com DDD.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $erros[] = 'Informe um e-mail válido.';
    if (!in_array($f['tipo_equipamento'], TIPOS_EQUIPAMENTO, true)) $erros[] = 'Selecione o tipo de equipamento.';
    if (mb_strlen($f['marca_modelo']) > 100) $erros[] = 'Marca/modelo muito longo.';
    if (!in_array($f['tipo_servico'], TIPOS_SERVICO, true)) $erros[] = 'Selecione o tipo de serviço.';
    if (!in_array($f['modalidade'], MODALIDADES, true)) $erros[] = 'Escolha como prefere ser atendido.';
    if (!in_array($f['horario_preferido'], HORARIOS, true)) $erros[] = 'Selecione o horário preferido.';
    if (!in_array($f['urgencia'], URGENCIAS, true)) $erros[] = 'Selecione a urgência.';
    $hoje = new DateTime('today');
    $dataOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f['data_preferida'], $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    if (!$dataOk || new DateTime($f['data_preferida']) < $hoje) $erros[] = 'Escolha uma data a partir de hoje.';
    $tam = mb_strlen($f['descricao']);
    if ($tam < 10) $erros[] = 'Descreva o problema com pelo menos 10 caracteres.';
    if ($tam > 600) $erros[] = 'A descrição pode ter no máximo 600 caracteres.';
    if ($erros) responder(false, 'Corrija os campos indicados.', 422, ['erros' => $erros]);

    $st = $pdo->prepare("SELECT COUNT(*) FROM assistencias WHERE cliente_id = :c AND status = 'pendente'");
    $st->execute([':c' => $clienteId]);
    if ((int)$st->fetchColumn() >= LIMITE_EM_ANALISE) {
        responder(false, 'Você já tem ' . LIMITE_EM_ANALISE . ' pedidos aguardando análise. Aguarde a resposta antes de pedir outro.', 429);
    }

    $f['cliente_id'] = $clienteId;
    $st = $pdo->prepare('INSERT INTO assistencias (cliente_id, nome, telefone, email, tipo_equipamento, marca_modelo,
            tipo_servico, modalidade, data_preferida, horario_preferido, urgencia, descricao)
          VALUES (:cliente_id, :nome, :telefone, :email, :tipo_equipamento, :marca_modelo,
            :tipo_servico, :modalidade, :data_preferida, :horario_preferido, :urgencia, :descricao)');
    $st->execute($f);
    responder(true, 'Solicitação enviada! Nossa equipe vai analisar e responder por aqui.', 201, ['id' => (int)$pdo->lastInsertId()]);
}

if ($acao === 'listar' && $metodo === 'GET') {
    $st = $pdo->prepare("SELECT a.id, a.tipo_equipamento, a.tipo_servico, a.modalidade, a.data_preferida, a.status,
            a.data_confirmada, a.horario_confirmado, a.criado_em,
            (SELECT COUNT(*) FROM assistencia_mensagens m WHERE m.assistencia_id = a.id AND m.autor = 'admin' AND m.lida = 0) AS nao_lidas
          FROM assistencias a WHERE a.cliente_id = :c ORDER BY a.criado_em DESC, a.id DESC LIMIT 200");
    $st->execute([':c' => $clienteId]);
    responder(true, 'ok', 200, ['pedidos' => $st->fetchAll()]);
}

if ($acao === 'detalhe' && $metodo === 'GET') {
    $pedido = meu_pedido($pdo, $clienteId, (int)($_GET['id'] ?? 0));
    $pdo->prepare("UPDATE assistencia_mensagens SET lida = 1 WHERE assistencia_id = :id AND autor = 'admin' AND lida = 0")
        ->execute([':id' => $pedido['id']]);
    $st = $pdo->prepare('SELECT id, autor, texto, criado_em FROM assistencia_mensagens WHERE assistencia_id = :id ORDER BY criado_em, id');
    $st->execute([':id' => $pedido['id']]);
    responder(true, 'ok', 200, ['pedido' => $pedido, 'mensagens' => $st->fetchAll()]);
}

if ($acao === 'mensagem' && $metodo === 'POST') {
    $d = corpo();
    $pedido = meu_pedido($pdo, $clienteId, (int)($d['id'] ?? 0));
    $texto = trim((string)($d['texto'] ?? ''));
    if (mb_strlen($texto) < 1 || mb_strlen($texto) > 1000) responder(false, 'A mensagem deve ter de 1 a 1000 caracteres.', 422);
    $pdo->prepare("INSERT INTO assistencia_mensagens (assistencia_id, autor, texto) VALUES (:id, 'cliente', :t)")
        ->execute([':id' => $pedido['id'], ':t' => $texto]);
    responder(true, 'Mensagem enviada.', 201);
}

if ($acao === 'cancelar' && $metodo === 'POST') {
    $d = corpo();
    $pedido = meu_pedido($pdo, $clienteId, (int)($d['id'] ?? 0));
    if (!in_array($pedido['status'], ['pendente', 'aprovada'], true)) {
        responder(false, 'Este pedido não pode mais ser cancelado.', 422);
    }
    $pdo->prepare("UPDATE assistencias SET status = 'cancelada' WHERE id = :id")->execute([':id' => $pedido['id']]);
    responder(true, 'Pedido cancelado.');
}

responder(false, 'Ação inválida.', 400);