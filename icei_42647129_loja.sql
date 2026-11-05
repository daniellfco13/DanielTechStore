-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql207.iceiy.com
-- Tempo de geração: 05/11/2026 às 05:57
-- Versão do servidor: 11.4.13-MariaDB
-- Versão do PHP: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `icei_42647129_loja`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `admin_tentativas`
--

CREATE TABLE `admin_tentativas` (
  `id` int(10) UNSIGNED NOT NULL,
  `ip` varchar(45) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `clientes`
--

CREATE TABLE `clientes` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(100) NOT NULL,
  `sobrenome` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `idade` tinyint(3) UNSIGNED NOT NULL,
  `sexo` enum('feminino','masculino','outro','prefiro-nao-informar') NOT NULL,
  `cep` char(9) NOT NULL,
  `cidade` varchar(100) NOT NULL,
  `bairro` varchar(100) NOT NULL,
  `rua` varchar(150) NOT NULL,
  `numero` varchar(10) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Despejando dados para a tabela `clientes`
--

INSERT INTO `clientes` (`id`, `nome`, `sobrenome`, `email`, `senha_hash`, `idade`, `sexo`, `cep`, `cidade`, `bairro`, `rua`, `numero`, `criado_em`, `atualizado_em`) VALUES
(1, 'Daniel', 'Pereira', 'danielpereira2527@gmail.com', '$2y$12$mkjok1UdCG.M0POGrEv2OOZRTLKvULaen6xHxA614SXbErAK.XwRu', 20, 'masculino', '60330-605', 'fortaleza', 'jardim iracema', 'antonieta clotilde', '450', '2026-09-28 23:36:27', '2026-09-28 23:36:27'),
(2, 'Daniel', 'Pereira', 'daniellfco13@gmail.com', '$2y$12$Ms/funK5xr5RJ2O48VNkOu4iK3dXPuqge5L9wI.Hw4cVtmrd6Td.O', 30, 'masculino', '32444-542', 'fortaleza', 'jardim iracema', 'antonieta clotilde', '450', '2026-10-01 11:47:34', '2026-10-01 11:47:34'),
(3, 'Daniel', 'Pereira', 'daniel13teste@gmail.com', '$2y$12$h8vnkkYs83tl7mFWLEToeOexgVWlhxmoErtGBjf6eo.nRw.Lop3tG', 25, 'masculino', '60330-605', 'Fortaleza', 'Jardim Iracema', 'Rua Antonieta Clotilde', '231', '2026-10-01 11:48:58', '2026-10-01 11:48:58');

-- --------------------------------------------------------

--
-- Estrutura para tabela `pedidos`
--

CREATE TABLE `pedidos` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `status` enum('pendente','pago','cancelado') NOT NULL DEFAULT 'pendente',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pedido_itens`
--

CREATE TABLE `pedido_itens` (
  `id` int(10) UNSIGNED NOT NULL,
  `pedido_id` int(10) UNSIGNED NOT NULL,
  `produto_id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(150) NOT NULL,
  `categoria` varchar(30) NOT NULL,
  `preco_unit` decimal(10,2) NOT NULL,
  `quantidade` smallint(5) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(150) NOT NULL,
  `categoria` varchar(30) NOT NULL,
  `preco` decimal(10,2) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `produtos`
--

INSERT INTO `produtos` (`id`, `nome`, `categoria`, `preco`, `ativo`) VALUES
(1, 'Teclado Mecânico RGB TK-500', 'perifericos', '349.90', 1),
(2, 'Mouse Gamer 16000 DPI Nyx', 'perifericos', '159.90', 1),
(3, 'Headset 7.1 Surround Aurora', 'perifericos', '279.90', 1),
(4, 'Monitor 27\" 165Hz QHD', 'perifericos', '1899.00', 1),
(5, 'Placa de Vídeo RTX 4060 8GB', 'pecas', '2699.00', 1),
(6, 'Processador Ryzen 7 5700X', 'pecas', '1249.00', 1),
(7, 'Memória RAM 16GB DDR4 3200MHz', 'pecas', '329.90', 1),
(8, 'SSD NVMe 1TB Gen4', 'pecas', '449.90', 1),
(9, 'Console PlayStation 5 Slim', 'games', '3799.00', 1),
(10, 'Controle Sem Fio DualSense', 'games', '399.90', 1),
(11, 'Console Xbox Series S', 'games', '2199.00', 1),
(12, 'Cadeira Gamer ErgoMax', 'acessorios', '1099.00', 1),
(13, 'Kit Cabos e Adaptadores USB-C', 'acessorios', '69.90', 1),
(14, 'Mousepad Gamer XL', 'acessorios', '49.90', 1);

--
-- Índices de tabelas apagadas
--

--
-- Índices de tabela `admin_tentativas`
--
ALTER TABLE `admin_tentativas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tentativas_ip` (`ip`,`criado_em`);

--
-- Índices de tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_clientes_email` (`email`),
  ADD KEY `idx_clientes_email` (`email`);

--
-- Índices de tabela `pedidos`
--
ALTER TABLE `pedidos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pedidos_cliente` (`cliente_id`),
  ADD KEY `idx_pedidos_criado` (`criado_em`);

--
-- Índices de tabela `pedido_itens`
--
ALTER TABLE `pedido_itens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_itens_pedido` (`pedido_id`),
  ADD KEY `idx_itens_produto` (`produto_id`),
  ADD KEY `idx_itens_categoria` (`categoria`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de tabelas apagadas
--

--
-- AUTO_INCREMENT de tabela `admin_tentativas`
--
ALTER TABLE `admin_tentativas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `pedidos`
--
ALTER TABLE `pedidos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `pedido_itens`
--
ALTER TABLE `pedido_itens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restrições para dumps de tabelas
--

--
-- Restrições para tabelas `pedidos`
--
ALTER TABLE `pedidos`
  ADD CONSTRAINT `fk_pedidos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`);

--
-- Restrições para tabelas `pedido_itens`
--
ALTER TABLE `pedido_itens`
  ADD CONSTRAINT `fk_itens_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
