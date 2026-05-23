<div align="center">
<img src="assets/systemic-icon.png" width=200>

# Systemic

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Apache](https://img.shields.io/badge/Apache-D22128?style=for-the-badge&logo=apache&logoColor=white)
![XAMPP](https://img.shields.io/badge/XAMPP-FB7A24?style=for-the-badge&logo=xampp&logoColor=white)
![HTML](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)

**Projeto de Situacao de Aprendizagem — SENAI**

Sistema integrado para gerenciamento da Automax e portal de fornecedores da Flowgate.

</div>

---

## Historia

Um ano atras, eramos apenas uma equipe de desenvolvedores contratados para atender a **Automax** — uma oficina mecanica movimentada que precisava de um sistema para gerenciar suas operacoes. Entregamos a primeira versao, mas o tempo era curto e as escolhas tecnicas refletiam isso: SQLite, Flask, sessoes simples.

Um ano depois, voltamos diferentes. Voltamos com a **Flowgate** (ainda atuando como Systemic) — nossa propria empresa, que agrega multiplas fornecedoras em um unico ponto de acesso. A Automax cresceu, e nosso sistema precisa crescer com ela. Desta vez, fazemos do jeito certo.

> A Flowgate fornece servicos de pecas e informacoes tecnicas, integrando fornecedoras em uma unica API. A Automax consome esses servicos e ganha uma plataforma renovada para suas operacoes internas.

---

## O que mudou em relacao a S.A anterior

| Componente | Antes | Agora |
|---|---|---|
| Backend | Python + Flask | PHP com router proprio |
| Banco de dados | SQLite | MySQL via XAMPP |
| Autenticacao | Sessions no servidor | JWT Tokens |
| Servidor | Embutido no Flask | Apache via XAMPP |
| Ambiente | Docker simples | XAMPP local |

---

## Stack tecnica

![XAMPP](https://img.shields.io/badge/XAMPP-FB7A24?style=flat-square&logo=xampp&logoColor=white)
**XAMPP** gerencia o Apache e o MySQL localmente, simplificando o setup do ambiente de desenvolvimento.

![Apache](https://img.shields.io/badge/Apache-D22128?style=flat-square&logo=apache&logoColor=white)
**Apache** atua como servidor web, roteando requisicoes para os projetos Automax e Flowgate via Virtual Hosts.

![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white)
**PHP** com uma biblioteca de routing propria. Um `index.php` recebe todo o trafego e responde com a pagina e os dados corretos.

![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)
**MySQL** incluso no XAMPP, com conexao real e dados persistidos localmente.

---

## Arquitetura de Deployment

```
                    +-----------------------------+
                    |        HOST MACHINE          |
                    |                             |
 Browser/Client --> |  :80                        |
                    |  +------------------------+ |
                    |  |        APACHE          | |
                    |  |   (Virtual Hosts)      | |
                    |  +------------------------+ |
                    |       |            |        |
                    |       v            v        |
                    |  +---------+  +---------+   |
                    |  | AUTOMAX |  |FLOWGATE |   |
                    |  | /htdocs |  | /htdocs |   |
                    |  |   PHP   |  |   PHP   |   |
                    |  +---------+  +---------+   |
                    |       |            |        |
                    |       v            v        |
                    |  +--------------------+     |
                    |  |     MYSQL DB       |     |
                    |  |      :3306         |     |
                    |  +--------------------+     |
                    +-----------------------------+
```

### Fluxo de uma requisicao

```
Cliente
  |
  | HTTP Request
  v
Apache (porta 80)
  |
  |-- automax.local/* --> htdocs/automax (PHP)
  |                              |
  |                              --> MySQL (dados da oficina)
  |
  |-- flowgate.local/* --> htdocs/flowgate (PHP)
                                 |
                                 --> MySQL (catalogo de fornecedoras)
```

### Virtual Hosts — visao geral

```apache
# Conceito do httpd-vhosts.conf
<VirtualHost *:80>
    ServerName automax.local
    DocumentRoot "C:/xampp/htdocs/automax"
</VirtualHost>

<VirtualHost *:80>
    ServerName flowgate.local
    DocumentRoot "C:/xampp/htdocs/flowgate"
</VirtualHost>
```

---

## Estrutura do projeto

```
Systemic/
+-- docs/                  # Diagramas, modelagem e documentacao
+-- automax/               # App da oficina
+-- flowgate/              # API da Flowgate
+-- frontend/
    +-- assets/            # Arquivos estaticos globais
    +-- login/
    +-- ordem-servico/
    +-- produto/
    +-- styles/            # Estilos globais
```

---

## Distribuicao de tarefas

| Responsabilidade | Responsaveis |
|---|---|
| Apoio geral e modelagem de deployment | Gabriel |
| Configuracao do Apache e Virtual Hosts | William + Gabriel |
| API da Flowgate | William + Gabriel |
| Rework das paginas HTML/CSS | Iago + Wellinthon |
| PHP geral (Automax e Flowgate) | Victor Mellos |

> Todos podem e devem contribuir fora de suas areas principais. A distribuicao acima e o plano provisorio.

---

## Habilidades necessarias

![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)
Entendimento de modelagem relacional e queries MySQL.

![UML](https://img.shields.io/badge/UML-Diagramas-informational?style=flat-square)
Diagramas de classe, caso de uso e atividade.

![PHP](https://img.shields.io/badge/777BB4?style=flat-square&logo=php&logoColor=white)
Variaveis, controle de fluxo, funcoes — a caixa de ferramentas do PHP.

![Git](https://img.shields.io/badge/Conventional_Commits-F05032?style=flat-square&logo=git&logoColor=white)
Conventional Commits para manter o historico legivel para todos.

---

## Conventional Commits

```
feat:     nova funcionalidade
fix:      correcao de bug
docs:     alteracao na documentacao
style:    formatacao sem mudanca de logica
refactor: refatoracao sem nova funcionalidade
chore:    tarefas de build, config, etc.
```

**Exemplo:**
```
feat(flowgate): adiciona endpoint de busca de pecas por fornecedora
fix(automax): corrige validacao de ordem de servico duplicada
```

---

## O que ainda falta definir

- [ ] Planejamento de custos e canvas da Flowgate
- [ ] Diagramas de caso de uso e atividade da Flowgate
- [ ] Definicao final do schema do banco de dados
- [ ] Configuracao inicial dos Virtual Hosts no Apache

---

<div align="center">

**SENAI — Situacao de Aprendizagem**
Desenvolvido pela equipe Systemic

![Status](https://img.shields.io/badge/status-em_desenvolvimento-yellow?style=flat-square)

</div>
