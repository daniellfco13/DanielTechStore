<?php
/**
 * painel_apidaniel.php — API do painel administrativo (paineldaniel.html)
 *
 *   POST ?acao=logout                                → fecha a sessão de administrador
 *   GET  ?acao=sessao                                → {autenticado: true|false}
 *   GET  ?acao=dados&inicio=&fim=&tudo=&status=&categoria=&agrupar=
 *                                                    → KPIs, gráficos, clientes e pedidos
 *   GET  ?acao=cliente&id=                           → dados + histórico completo de 1 cliente
 *   POST ?acao=status   {pedido_id, status}          → muda o status de um pedido
 *
 * O login do administrador NÃO é feito aqui: ele entra pela página de login do
 * site (logindaniel.html → logindaniel.php), que abre a sessão de administrador
 * e leva direto ao painel. A senha e a lógica de acesso ficam em
 * paineladmdaniel.php. Todas as ações abaixo, exceto "sessao", exigem a sessão
 * de administrador.
 */
declare(strict_types=1);

// =====================================================================
// CONFIGURAÇÃO (a senha e o acesso ficam em paineladmdaniel.php)
// =====================================================================

const PAINEL_FUSO          = 'America/Fortaleza';
const PAINEL_FUSO_SQL      = '-03:00';           // Fortaleza não tem horário de verão

// Trechos de SQL reutilizados (precisam ficar aqui no topo: constantes não "sobem" como funções)
const SQL_REC     = 'SUM(i.preco_unit * i.quantidade)';
const SQL_FROM_PI = 'FROM pedidos p JOIN pedido_itens i ON i.pedido_id = p.id';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function responder(bool $ok, string $msg, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['sucesso' => $ok, 'mensagem' => $msg], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function iniciar_sessao(): void
{
    $seguro = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.gc_maxlifetime', (string)PAINEL_TIMEOUT);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $seguro]);
    } else {
        session_set_cookie_params(0, '/', '', $seguro, true);
    }
    session_start();
}

require __DIR__ . '/dbdaniel.php';
require __DIR__ . '/paineladmdaniel.php';   // precisa vir antes de iniciar_sessao() (usa PAINEL_TIMEOUT)
iniciar_sessao();

$acao   = (string)($_GET['acao'] ?? '');
$metodo = $_SERVER['REQUEST_METHOD'];

// Ações que alteram algo só aceitam POST vindo do nosso próprio JavaScript
// (o cabeçalho customizado não pode ser enviado por outro site → protege contra CSRF).
if ($metodo === 'POST') {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'painel') {
        responder(false, 'Requisição não autorizada.', 403);
    }
}

function exigir_admin(): void
{
    if (!painel_admin_logado()) responder(false, 'Sessão expirada. Entre com a senha novamente.', 401);
}

function corpo_json(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

try {
    $pdo = getConexao();
    $pdo->exec("SET time_zone = '" . PAINEL_FUSO_SQL . "'"); // datas e agrupamentos no horário de Brasília

    switch ($acao) {
        case 'sessao':
            responder(true, 'ok', 200, ['autenticado' => painel_admin_logado()]);
            break;

        case 'logout':
            if ($metodo !== 'POST') responder(false, 'Método não permitido.', 405);
            unset($_SESSION['admin_ok'], $_SESSION['admin_ts']);
            responder(true, 'Sessão encerrada.');
            break;

        case 'dados':
            exigir_admin();
            acao_dados($pdo);
            break;

        case 'cliente':
            exigir_admin();
            acao_cliente($pdo);
            break;

        case 'status':
            if ($metodo !== 'POST') responder(false, 'Método não permitido.', 405);
            exigir_admin();
            acao_status($pdo);
            break;

        default:
            responder(false, 'Ação inválida.', 400);
    }
} catch (PDOException $e) {
    // Não vaza detalhes do banco; o mais comum é esquecer de rodar o SQL das tabelas.
    responder(false, 'Erro no banco de dados. Confira se o arquivo painel_tabelasdaniel.sql foi executado.', 500);
}

// =====================================================================
// STATUS DE PEDIDO
// =====================================================================
function acao_status(PDO $pdo): void
{
    $d = corpo_json();
    $id = (int)($d['pedido_id'] ?? 0);
    $status = (string)($d['status'] ?? '');
    if ($id <= 0 || !in_array($status, ['pendente', 'pago', 'cancelado'], true)) {
        responder(false, 'Dados inválidos.', 422);
    }
    $st = $pdo->prepare('SELECT id FROM pedidos WHERE id = :id');
    $st->execute([':id' => $id]);
    if (!$st->fetch()) responder(false, 'Pedido não encontrado.', 404);

    $pdo->prepare('UPDATE pedidos SET status = :s WHERE id = :id')->execute([':s' => $status, ':id' => $id]);
    responder(true, 'Status atualizado.');
}

// =====================================================================
// FUNÇÕES AUXILIARES DE DADOS
// =====================================================================
function parse_data($s): ?DateTime
{
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return new DateTime($s . ' 00:00:00', new DateTimeZone(PAINEL_FUSO));
}

function cond_status(string $status): string
{
    $mapa = [
        'ativos'    => "p.status <> 'cancelado'",
        'pago'      => "p.status = 'pago'",
        'pendente'  => "p.status = 'pendente'",
        'cancelado' => "p.status = 'cancelado'",
        'todos'     => '1 = 1',
    ];
    return $mapa[$status] ?? $mapa['ativos'];
}

/** WHERE para consultas que juntam pedidos (p) com itens (i). */
function filtro_itens(DateTime $ini, DateTime $fimEx, string $status, string $cat, bool $comStatus = true): array
{
    $w = ['p.criado_em >= :ini', 'p.criado_em < :fim'];
    $p = [':ini' => $ini->format('Y-m-d') . ' 00:00:00', ':fim' => $fimEx->format('Y-m-d') . ' 00:00:00'];
    if ($comStatus) $w[] = cond_status($status);
    if ($cat !== '') { $w[] = 'i.categoria = :cat'; $p[':cat'] = $cat; }
    return [implode(' AND ', $w), $p];
}

function consulta(PDO $pdo, string $sql, array $params): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function kpis(PDO $pdo, array $f): array
{
    list($w, $p) = $f;
    $r = consulta($pdo,
        'SELECT COALESCE(' . SQL_REC . ',0) AS fat, COUNT(DISTINCT p.id) AS ped,
                COALESCE(SUM(i.quantidade),0) AS itens, COUNT(DISTINCT p.cliente_id) AS clientes '
        . SQL_FROM_PI . " WHERE $w", $p)[0];
    $fat = round((float)$r['fat'], 2);
    $ped = (int)$r['ped'];
    return [
        'faturamento' => $fat,
        'pedidos'     => $ped,
        'itens'       => (int)$r['itens'],
        'clientes'    => (int)$r['clientes'],
        'ticket_medio' => $ped > 0 ? round($fat / $ped, 2) : 0.0,
    ];
}

/** Lista de pedidos (com itens) segundo um WHERE sobre a tabela pedidos (p) e clientes (c). */
function pedidos_com_itens(PDO $pdo, string $where, array $params, int $limite): array
{
    $linhas = consulta($pdo,
        'SELECT p.id, p.cliente_id, p.status, p.criado_em, p.total, c.nome, c.sobrenome, c.email
         FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
         WHERE ' . $where . ' ORDER BY p.criado_em DESC, p.id DESC LIMIT ' . (int)($limite + 1), $params);

    $limitado = count($linhas) > $limite;
    if ($limitado) array_pop($linhas);

    $ids = array_map(function ($l) { return (int)$l['id']; }, $linhas);
    $itens = [];
    if ($ids) {
        $rows = consulta($pdo,
            'SELECT pedido_id, produto_id, nome, categoria, preco_unit, quantidade
             FROM pedido_itens WHERE pedido_id IN (' . implode(',', $ids) . ') ORDER BY id', []);
        foreach ($rows as $it) {
            $itens[(int)$it['pedido_id']][] = [
                'produto_id' => (int)$it['produto_id'],
                'nome'       => $it['nome'],
                'categoria'  => $it['categoria'],
                'preco_unit' => (float)$it['preco_unit'],
                'qtd'        => (int)$it['quantidade'],
            ];
        }
    }
    $saida = [];
    foreach ($linhas as $l) {
        $id = (int)$l['id'];
        $saida[] = [
            'id'         => $id,
            'cliente_id' => (int)$l['cliente_id'],
            'cliente'    => trim($l['nome'] . ' ' . $l['sobrenome']),
            'email'      => $l['email'],
            'status'     => $l['status'],
            'criado_em'  => $l['criado_em'],
            'total'      => (float)$l['total'],
            'itens'      => $itens[$id] ?? [],
        ];
    }
    return [$saida, $limitado];
}

// =====================================================================
// DADOS DO PAINEL
// =====================================================================
function acao_dados(PDO $pdo): void
{
    $tz   = new DateTimeZone(PAINEL_FUSO);
    $hoje = new DateTime('today', $tz);

    // ---- período -----------------------------------------------------
    $tudo = !empty($_GET['tudo']);
    if ($tudo) {
        $min = $pdo->query('SELECT DATE(MIN(criado_em)) FROM pedidos')->fetchColumn();
        $ini = $min ? parse_data((string)$min) : null;
        if (!$ini) $ini = (clone $hoje)->modify('-29 days');
        $fim = clone $hoje;
    } else {
        $ini = parse_data($_GET['inicio'] ?? null) ?: (clone $hoje)->modify('-29 days');
        $fim = parse_data($_GET['fim'] ?? null) ?: clone $hoje;
    }
    if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }
    if ((int)$ini->diff($fim)->days > 3659) $ini = (clone $fim)->modify('-3659 days');
    $dias  = (int)$ini->diff($fim)->days + 1;
    $fimEx = (clone $fim)->modify('+1 day');

    // ---- filtros -----------------------------------------------------
    $status = (string)($_GET['status'] ?? 'ativos');
    if (!in_array($status, ['ativos', 'pago', 'pendente', 'cancelado', 'todos'], true)) $status = 'ativos';
    $cat = (string)($_GET['categoria'] ?? '');
    if (!preg_match('/^[a-z0-9_-]{1,30}$/', $cat)) $cat = '';
    $agrupar = (string)($_GET['agrupar'] ?? 'auto');
    if (!in_array($agrupar, ['auto', 'dia', 'semana', 'mes'], true)) $agrupar = 'auto';
    if ($agrupar === 'auto') $agrupar = $dias <= 45 ? 'dia' : ($dias <= 200 ? 'semana' : 'mes');

    $f = filtro_itens($ini, $fimEx, $status, $cat);

    // ---- KPIs e comparação com o período anterior ---------------------
    $k = kpis($pdo, $f);

    $anterior = null;
    if (!$tudo) {
        $pFim = (clone $ini)->modify('-1 day');
        $pIni = (clone $pFim)->modify('-' . ($dias - 1) . ' days');
        $anterior = kpis($pdo, filtro_itens($pIni, $ini, $status, $cat));
    }

    $r = consulta($pdo, 'SELECT COUNT(*) AS n, COALESCE(SUM(criado_em >= :ini AND criado_em < :fim),0) AS novos FROM clientes',
        [':ini' => $f[1][':ini'], ':fim' => $f[1][':fim']])[0];
    $k['clientes_total'] = (int)$r['n'];
    $k['clientes_novos'] = (int)$r['novos'];

    // ---- série temporal ----------------------------------------------
    $expr = [
        'dia'    => 'DATE(p.criado_em)',
        'semana' => 'DATE_SUB(DATE(p.criado_em), INTERVAL WEEKDAY(p.criado_em) DAY)',
        'mes'    => "DATE_FORMAT(p.criado_em, '%Y-%m-01')",
    ][$agrupar];

    $linhas = consulta($pdo,
        "SELECT $expr AS periodo, " . SQL_REC . ' AS fat, COUNT(DISTINCT p.id) AS ped '
        . SQL_FROM_PI . ' WHERE ' . $f[0] . ' GROUP BY periodo ORDER BY periodo', $f[1]);
    $mapa = [];
    foreach ($linhas as $l) $mapa[(string)$l['periodo']] = $l;

    $cursor = clone $ini;
    if ($agrupar === 'semana') $cursor->modify('-' . ((int)$cursor->format('N') - 1) . ' days');
    if ($agrupar === 'mes')    $cursor->modify('first day of this month');
    $passo = ['dia' => '+1 day', 'semana' => '+7 days', 'mes' => '+1 month'][$agrupar];

    $serie = [];
    while ($cursor <= $fim && count($serie) < 4000) {
        $chave = $cursor->format('Y-m-d');
        $serie[] = [
            'k'   => $chave,
            'fat' => isset($mapa[$chave]) ? round((float)$mapa[$chave]['fat'], 2) : 0.0,
            'ped' => isset($mapa[$chave]) ? (int)$mapa[$chave]['ped'] : 0,
        ];
        if ($agrupar === 'mes') $cursor->modify('first day of next month');
        else $cursor->modify($passo);
    }

    // ---- quebras ------------------------------------------------------
    $categorias = array_map(function ($l) {
        return ['categoria' => $l['categoria'], 'fat' => round((float)$l['fat'], 2), 'qtd' => (int)$l['qtd']];
    }, consulta($pdo,
        'SELECT i.categoria, ' . SQL_REC . ' AS fat, SUM(i.quantidade) AS qtd ' . SQL_FROM_PI
        . ' WHERE ' . $f[0] . ' GROUP BY i.categoria ORDER BY fat DESC', $f[1]));

    $produtos = array_map(function ($l) {
        return ['id' => (int)$l['produto_id'], 'nome' => $l['nome'], 'qtd' => (int)$l['qtd'], 'fat' => round((float)$l['fat'], 2)];
    }, consulta($pdo,
        'SELECT i.produto_id, MAX(i.nome) AS nome, SUM(i.quantidade) AS qtd, ' . SQL_REC . ' AS fat ' . SQL_FROM_PI
        . ' WHERE ' . $f[0] . ' GROUP BY i.produto_id ORDER BY fat DESC LIMIT 8', $f[1]));

    $fSemStatus = filtro_itens($ini, $fimEx, $status, $cat, false);
    $porStatus = array_map(function ($l) {
        return ['status' => $l['status'], 'ped' => (int)$l['ped'], 'fat' => round((float)$l['fat'], 2)];
    }, consulta($pdo,
        'SELECT p.status, COUNT(DISTINCT p.id) AS ped, ' . SQL_REC . ' AS fat ' . SQL_FROM_PI
        . ' WHERE ' . $fSemStatus[0] . ' GROUP BY p.status', $fSemStatus[1]));

    $cidades = array_map(function ($l) {
        return ['cidade' => $l['cidade'], 'fat' => round((float)$l['fat'], 2), 'ped' => (int)$l['ped']];
    }, consulta($pdo,
        'SELECT LOWER(TRIM(c.cidade)) AS cidade, ' . SQL_REC . ' AS fat, COUNT(DISTINCT p.id) AS ped '
        . SQL_FROM_PI . ' JOIN clientes c ON c.id = p.cliente_id WHERE ' . $f[0]
        . ' GROUP BY LOWER(TRIM(c.cidade)) ORDER BY fat DESC LIMIT 8', $f[1]));

    $ordemFaixas = ['13-17', '18-24', '25-34', '35-44', '45-54', '55+'];
    $contFaixas = array_fill_keys($ordemFaixas, 0);
    foreach (consulta($pdo,
        "SELECT CASE WHEN idade < 18 THEN '13-17' WHEN idade < 25 THEN '18-24' WHEN idade < 35 THEN '25-34'
                     WHEN idade < 45 THEN '35-44' WHEN idade < 55 THEN '45-54' ELSE '55+' END AS faixa,
                COUNT(*) AS n FROM clientes GROUP BY faixa", []) as $l) {
        $contFaixas[$l['faixa']] = (int)$l['n'];
    }
    $faixas = [];
    foreach ($ordemFaixas as $fx) $faixas[] = ['faixa' => $fx, 'n' => $contFaixas[$fx]];

    // ---- clientes (histórico completo, sem contar pedidos cancelados) ----
    $clientes = array_map(function ($l) {
        return [
            'id' => (int)$l['id'], 'nome' => $l['nome'], 'sobrenome' => $l['sobrenome'], 'email' => $l['email'],
            'idade' => (int)$l['idade'], 'sexo' => $l['sexo'], 'cep' => $l['cep'], 'cidade' => $l['cidade'],
            'bairro' => $l['bairro'], 'rua' => $l['rua'], 'numero' => $l['numero'], 'criado_em' => $l['criado_em'],
            'pedidos' => (int)$l['pedidos'], 'total_gasto' => round((float)$l['total_gasto'], 2),
            'ultimo_pedido' => $l['ultimo_pedido'],
        ];
    }, consulta($pdo,
        "SELECT c.id, c.nome, c.sobrenome, c.email, c.idade, c.sexo, c.cep, c.cidade, c.bairro, c.rua, c.numero,
                c.criado_em, COUNT(DISTINCT p.id) AS pedidos,
                COALESCE(" . SQL_REC . ", 0) AS total_gasto, MAX(p.criado_em) AS ultimo_pedido
         FROM clientes c
         LEFT JOIN pedidos p ON p.cliente_id = c.id AND p.status <> 'cancelado'
         LEFT JOIN pedido_itens i ON i.pedido_id = p.id
         GROUP BY c.id ORDER BY c.criado_em DESC, c.id DESC", []));

    // ---- pedidos do período (respeitando status e categoria) -----------
    $wp = ['p.criado_em >= :ini', 'p.criado_em < :fim', cond_status($status)];
    $pp = [':ini' => $f[1][':ini'], ':fim' => $f[1][':fim']];
    if ($cat !== '') {
        $wp[] = 'EXISTS (SELECT 1 FROM pedido_itens x WHERE x.pedido_id = p.id AND x.categoria = :cat)';
        $pp[':cat'] = $cat;
    }
    list($pedidos, $limitado) = pedidos_com_itens($pdo, implode(' AND ', $wp), $pp, 1000);

    $catsDisp = array_map(function ($l) { return $l['categoria']; },
        consulta($pdo, 'SELECT DISTINCT categoria FROM produtos ORDER BY categoria', []));

    responder(true, 'ok', 200, [
        'periodo'  => ['inicio' => $ini->format('Y-m-d'), 'fim' => $fim->format('Y-m-d'), 'dias' => $dias,
                       'agrupar' => $agrupar, 'tudo' => $tudo],
        'kpis'     => $k,
        'anterior' => $anterior,
        'serie'    => $serie,
        'categorias' => $categorias,
        'produtos' => $produtos,
        'status'   => $porStatus,
        'cidades'  => $cidades,
        'faixas'   => $faixas,
        'clientes' => $clientes,
        'pedidos'  => $pedidos,
        'pedidos_limitado' => $limitado,
        'categorias_disponiveis' => $catsDisp,
    ]);
}

// =====================================================================
// UM CLIENTE (dados + histórico completo de pedidos)
// =====================================================================
function acao_cliente(PDO $pdo): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(false, 'Cliente inválido.', 422);

    $r = consulta($pdo,
        'SELECT id, nome, sobrenome, email, idade, sexo, cep, cidade, bairro, rua, numero, criado_em
         FROM clientes WHERE id = :id', [':id' => $id]);
    if (!$r) responder(false, 'Cliente não encontrado.', 404);

    list($pedidos, $limitado) = pedidos_com_itens($pdo, 'p.cliente_id = :cid', [':cid' => $id], 500);
    responder(true, 'ok', 200, ['cliente' => $r[0], 'pedidos' => $pedidos, 'pedidos_limitado' => $limitado]);
}
