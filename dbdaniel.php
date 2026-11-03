<?php
/**
 * Conexão com o banco de dados da Daniel Tech Store.
 *
 * Preencha as constantes abaixo com os dados reais de hospedagem
 * (o painel do seu provedor mostra host, usuário e senha do MySQL).
 * Nunca coloque a senha real de produção em um repositório público.
 */

// Em hospedagem compartilhada, host/nome do banco/usuário costumam vir
// com um prefixo dado pelo provedor (ex.: "icei_daniel_tech_store",
// "icei_42647129") — copie os valores exatos do painel de hospedagem.
const DB_HOST = 'sql207.iceiy.com';
const DB_NAME = 'icei_42647129_loja';
const DB_USER = 'icei_42647129';
const DB_PASS = 'ESTELA13store';
const DB_CHARSET = 'utf8mb4';

/**
 * Retorna uma conexão PDO pronta para uso, ou encerra a requisição
 * com um erro em JSON caso a conexão falhe.
 */
function getConexao(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $opcoes = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, DB_USER, DB_PASS, $opcoes);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Não foi possível conectar ao banco de dados.',
        ]);
        exit;
    }
}