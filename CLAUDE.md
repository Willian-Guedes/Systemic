Claude, você receberá os arquivos do projeto (tanto separado ou em zip), analise a situação do código atual e tenha uma ideia da codebase.
Quando você for programar, você deve programar que nem gente, cuidando de quando introduz carga mental apra o desenvolvedor, e as funções devem fazer o que elas dizem,
Separe a lógica em blocos de funções para facil manutenção, seu código deve ser auto-documentativo.

snake_case para variaveis PascalCase para classes.

Sempre procure propor soluções e situações e demonstre o workflow do projeto ou da feature (ou da tarefa) que você foi pedido para trabalhar, siga princípios de segurança.
Apresente as modificações e explique elas e a lógica por trás delas.

Quando propor o código e soluções, faça a lista de commits seguindo conventional commits para copiar e colar no terminal.

Aqui um contexto desse projeto:

```
Um ano atras, eramos apenas uma equipe de desenvolvedores contratados para atender a Automax — uma oficina mecanica movimentada que precisava de um sistema para gerenciar suas operacoes. Entregamos a primeira versao, mas o tempo era curto e as escolhas tecnicas refletiam isso: SQLite, Flask, sessoes simples.

Um ano depois, voltamos diferentes. Voltamos com a Flowgate (ainda atuando como Systemic) — nossa propria empresa, que agrega multiplas fornecedoras em um unico ponto de acesso. A Automax cresceu, e nosso sistema precisa crescer com ela. Desta vez, fazemos do jeito certo.

    A Flowgate fornece servicos de pecas e informacoes tecnicas, integrando fornecedoras em uma unica API. A Automax consome esses servicos e ganha uma plataforma renovada para suas operacoes internas.


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


Quando for construir uma página web do projeto, você precisa seguir a seguinte palheta e ídeia de estilo:
```
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=Barlow:wght@300;400;500;600&display=swap');

:root {
  --red-vivid:     #ac2a2a;   /* após lightouse: mais luminoso */
  --red-dark:      #D32F2F;

  --red-glow:      rgba(255, 77, 77, 0.15);
  --red-glow-strong: rgba(212, 43, 43, 0.30);

/* após lighthouse: melhorar separação 1- ESCURIDAO + 2/ CONTRASTE + ESCURIDAO++*/
  --carbon:        #0D0E10;   /* Levemente mais escuro para aumentar contraste com texto */
  --carbon-mid:    #14161A;
  --carbon-light:  #1E2126;
  --steel:         #454952;

  --chrome:        #F0F1F3;   /* Próximo ao branco para leitura principal */
  --chrome-dim:    #B0B3B8;   /* Antes era #858890 (baixa acessibilidade) */
  --off-white:     #FFFFFF;
  --text-body:     #E4E6EB;   /* Texto principal mais claro */
  --text-dim:      #B0B3B8;   /* Texto secundário ajustado para passar no WCAG */

  /* após lighthouse: contraste aumentado, diferença aumentada */
  --border-subtle: rgba(255,255,255,0.15);
  --border-mid:    rgba(255,255,255,0.25);
  --border-accent: rgba(212,43,43,0.40);

  --shadow-card:   0 8px 32px rgba(0,0,0,0.55);
  --shadow-red:    0 0 28px rgba(212,43,43,0.35);
  --shadow-deep:   0 20px 60px rgba(0,0,0,0.7);

  --radius-sm:     4px;
  --radius-md:     8px;
  --radius-lg:     16px;


  --scrollbar-bg: rgba(0, 0, 0, 0);          
  --scrollbar-track: rgba(0, 0, 0, 0);      
  --scrollbar-thumb: rgba(0, 0, 0, 0.55);    
  --scrollbar-thumb-hover: rgba(0, 0, 0, 0.75);
  --scrollbar-thumb-active: rgba(0, 0, 0, 0.9);

  --font-display:  'Barlow Condensed', sans-serif;
  --font-body:     'Barlow', sans-serif;

  --transition:    0.26s cubic-bezier(0.4, 0, 0.2, 1);
  --transition-slow: 0.45s cubic-bezier(0.23, 1, 0.32, 1);
}
```
Qualquer coisa peça pelo código html da página homepage para ver o design, mas peça o zip do projeto se necessário.


Ao criar diretórios, não utilize {} em comandos como o mkdir, pois eles quebram.
Sempre programe com segurança em mente, com headers seguros e aplicações seguras.
Exemplo: ``mkdir -p {algo}`` ou qualquer coisa com {} para representar multiplos diretórios


Não use comentários gigantes com separadores no estilo "--------------------"
