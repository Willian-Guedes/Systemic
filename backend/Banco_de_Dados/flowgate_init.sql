-- flowgate_init.sql
-- Cria o usuário flowgate e concede acesso ao flowgate_db.
-- Executado pelo Docker como 03_flowgate_init.sql.

CREATE USER IF NOT EXISTS 'flowgate'@'%' IDENTIFIED BY 'flowgate123';
GRANT ALL PRIVILEGES ON flowgate_db.* TO 'flowgate'@'%';
FLUSH PRIVILEGES;