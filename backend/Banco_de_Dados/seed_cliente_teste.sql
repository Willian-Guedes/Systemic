-- Seed: cliente de teste para desenvolvimento
--
--  Cliente:
--    email : teste@gmail.com
--    senha : teste123
--
--  NUNCA inclua este arquivo em produção.

USE oficina_db;

INSERT INTO clientes (nome_cliente, CPF, celular, email, senha, vip)
VALUES (
    'Cliente Teste',
    '000.000.000-00',
    '(47) 9 0000-0000',
    'teste@gmail.com',
    '$2y$12$ZzntjQFjox1nBCC/jsaBz.22.sfovpJyw93Yms8TVfsniIyXF04Bi',
    0
)
ON DUPLICATE KEY UPDATE
    nome_cliente = VALUES(nome_cliente),
    celular      = VALUES(celular);
