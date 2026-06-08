/**
 * produtos.js
 *
 * Responsabilidades:
 *  1. Buscar lista de produtos na API (/api/produtos)
 *  2. Renderizar o grid com paginação
 *  3. Filtrar por categoria sem reload de página
 */

let pagina_atual  = 1;
let categoria_atual = 'todos';

function formatar_brl(valor) {
    return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;');
}

function mostrar_loading() {
    document.getElementById('estado-loading').hidden = false;
    document.getElementById('estado-erro').hidden    = true;
    document.getElementById('estado-vazio').hidden   = true;
    document.getElementById('grid-produtos').hidden  = true;
    document.getElementById('paginacao').hidden       = true;
}

function mostrar_erro(mensagem) {
    document.getElementById('estado-loading').hidden = true;
    document.getElementById('estado-erro').hidden    = false;
    document.getElementById('mensagem-erro').textContent = mensagem;
}

function mostrar_vazio() {
    document.getElementById('estado-loading').hidden = true;
    document.getElementById('estado-vazio').hidden   = false;
}

function mostrar_grid() {
    document.getElementById('estado-loading').hidden = true;
    document.getElementById('grid-produtos').hidden  = false;
    document.getElementById('paginacao').hidden       = false;
}

async function carregar_pagina(pagina) {
    mostrar_loading();
    pagina_atual = pagina;

    const params = new URLSearchParams({ pagina, categoria: categoria_atual });

    try {
        const resposta = await fetch(`/api/produtos?${params}`);
        const dados    = await resposta.json();

        if (!resposta.ok) {
            mostrar_erro(dados.erro ?? 'Erro desconhecido.');
            return;
        }

        if (dados.produtos.length === 0) {
            mostrar_vazio();
            document.getElementById('total-info').textContent = '';
            return;
        }

        renderizar_grid(dados.produtos);
        renderizar_paginacao(dados.pagina, dados.paginas);

        document.getElementById('total-info').textContent =
            `${dados.total} produto${dados.total !== 1 ? 's' : ''} encontrado${dados.total !== 1 ? 's' : ''}`;

        mostrar_grid();
        window.scrollTo({ top: 0, behavior: 'smooth' });

    } catch (erro) {
        mostrar_erro('Falha de conexão. Verifique sua rede e tente novamente.');
    }
}

function renderizar_grid(produtos) {
    const grid = document.getElementById('grid-produtos');
    grid.innerHTML = '';

    produtos.forEach(produto => {
        const col = document.createElement('div');
        col.className = 'col-sm-6 col-md-4 col-lg-3';

        const sem_estoque = produto.stock <= 0
            ? '<span class="badge-sem-estoque">SEM ESTOQUE</span>'
            : '';

        col.innerHTML = `
            <a href="/produto/${esc(produto.id)}" class="card-produto">
                <img
                    src="${esc(produto.imagem)}"
                    alt="${esc(produto.nome)}"
                    class="card-produto-img"
                    onerror="this.src='https://placehold.co/400x300/1E2126/B0B3B8?text=Sem+Imagem'"
                >
                <div class="card-produto-body">
                    <p class="card-produto-cat">${esc(produto.categoria)}</p>
                    <h3 class="card-produto-nome">${esc(produto.nome)}</h3>
                    ${sem_estoque}
                    <p class="card-produto-preco">${formatar_brl(produto.preco)}</p>
                </div>
            </a>
        `;

        grid.appendChild(col);
    });
}

function renderizar_paginacao(pagina, total_paginas) {
    const container = document.getElementById('paginacao');
    container.innerHTML = '';

    if (total_paginas <= 1) return;

    const btn_anterior = document.createElement('button');
    btn_anterior.className = 'btn';
    btn_anterior.innerHTML = '<i class="fas fa-chevron-left"></i>';
    btn_anterior.disabled  = pagina <= 1;
    btn_anterior.addEventListener('click', () => carregar_pagina(pagina - 1));
    container.appendChild(btn_anterior);

    for (let i = 1; i <= total_paginas; i++) {
        const btn = document.createElement('button');
        btn.className = `btn${i === pagina ? ' ativo' : ''}`;
        btn.textContent = i;
        btn.addEventListener('click', () => carregar_pagina(i));
        container.appendChild(btn);
    }

    const btn_proximo = document.createElement('button');
    btn_proximo.className = 'btn';
    btn_proximo.innerHTML = '<i class="fas fa-chevron-right"></i>';
    btn_proximo.disabled  = pagina >= total_paginas;
    btn_proximo.addEventListener('click', () => carregar_pagina(pagina + 1));
    container.appendChild(btn_proximo);
}

function inicializar_filtros() {
    document.querySelectorAll('.btn-filtro').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.btn-filtro').forEach(b => b.classList.remove('ativo'));
            btn.classList.add('ativo');
            categoria_atual = btn.dataset.categoria;
            carregar_pagina(1);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    inicializar_filtros();
    carregar_pagina(1);
});