/**
 * estoque.js
 *
 * Responsabilidades:
 *  1. Listar produtos com busca, filtro de categoria e paginação
 *  2. Criar e editar produtos via modal
 *  3. Ajustar estoque com botões +/-
 *  4. Remover produto com confirmação
 */

const user       = window.__session_user || {};
const csrf       = user.csrf_token || '';
const pode_editar = (user.permissoes || []).includes('estoque.editar');

let pagina_atual   = 1;
let total_paginas  = 1;
let categoria_atual = '';
let busca_atual    = '';
let timeout_busca  = null;
let id_excluindo   = null;

let modalProd, modalExc;

// Setup sidebar (igual ao fornecedores.html)

function setupSidebar() {
    const av = document.getElementById('sbAv');
    av.textContent = user.iniciais || '?';
    av.className   = 'av av-' + (user.nivel || '');
    document.getElementById('sbName').textContent = user.nome  || '';
    const role = document.getElementById('sbRole');
    role.textContent = user.nivel || '';
    role.className   = 'pbadge pb-' + (user.nivel || '');
    document.getElementById('csrfLogout').value = csrf;

    const perms = user.permissoes || [];
    document.querySelectorAll('.rnav.r-g').forEach(el => {
        if (!perms.includes('funcionarios.visualizar')) el.style.display = 'none';
    });
    document.querySelectorAll('.rnav.r-m').forEach(el => {
        if (!perms.includes('estoque.visualizar')) el.style.display = 'none';
    });
}

function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('overlay');
    const open = sb.classList.toggle('open');
    ov.classList.toggle('show', open);
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}

// Toast (igual ao fornecedores.html)

function toast(msg, tipo = 'ok') {
    const c = document.getElementById('toastC');
    const t = document.createElement('div');
    t.className = 'tmsg t-' + (tipo === 'ok' ? 'ok' : tipo === 'erro' ? 'er' : 'wn');
    const icon = tipo === 'ok' ? 'check-circle-fill' : tipo === 'erro' ? 'x-circle-fill' : 'exclamation-triangle-fill';
    const cor  = tipo === 'ok' ? 'var(--green)' : tipo === 'erro' ? 'var(--rose)' : 'var(--amber)';
    t.innerHTML = `<i class="bi bi-${icon}" style="color:${cor};font-size:18px;flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatar_brl(v) {
    return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function classe_stock(qtd) {
    if (qtd <= 0) return 'stock-zero';
    if (qtd <= 5) return 'stock-baixo';
    return 'stock-ok';
}

function label_cat(cat) {
    return { pecas: 'Peças', fluidos: 'Fluidos', eletrico: 'Elétrico' }[cat] ?? cat;
}

// Carregamento

async function carregarEstoque(pagina = 1) {
    pagina_atual = pagina;

    const params = new URLSearchParams({ pagina, categoria: categoria_atual, busca: busca_atual });

    try {
        const res   = await fetch(`/api/estoque?${params}`, { credentials: 'same-origin' });
        const dados = await res.json();

        if (!res.ok) throw new Error(dados.erro || 'Erro desconhecido');

        renderTabela(dados.produtos, dados.total);
        renderPaginacao(dados.pagina, dados.paginas);

    } catch (err) {
        document.getElementById('tbodyEstoque').innerHTML = `
            <tr><td colspan="6">
                <div class="empty">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h4>Erro ao carregar</h4>
                    <p>${esc(err.message)}</p>
                </div>
            </td></tr>`;
    }
}

function renderTabela(produtos, total) {
    document.getElementById('countLabel').textContent = `${total} produto(s)`;
    const tbody = document.getElementById('tbodyEstoque');

    if (produtos.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="6">
                <div class="empty">
                    <i class="bi bi-boxes"></i>
                    <h4>Nenhum produto encontrado</h4>
                    <p>Tente outro filtro ou cadastre um novo produto.</p>
                </div>
            </td></tr>`;
        return;
    }

    tbody.innerHTML = produtos.map(p => {
        const cls_stock = classe_stock(p.stock);

        const controle_stock = pode_editar ? `
            <div style="display:flex;align-items:center;gap:6px">
                <button class="btn btn-ghost btn-xs btn-delta" onclick="ajustarStock(${p.id}, -1)" aria-label="Remover 1">
                    <i class="bi bi-dash" aria-hidden="true"></i>
                </button>
                <span class="${cls_stock}" id="stock-${p.id}" style="font-family:var(--font-mono);min-width:28px;text-align:center">${p.stock}</span>
                <button class="btn btn-ghost btn-xs btn-delta" onclick="ajustarStock(${p.id}, 1)" aria-label="Adicionar 1">
                    <i class="bi bi-plus" aria-hidden="true"></i>
                </button>
            </div>
        ` : `<span class="${cls_stock}" style="font-family:var(--font-mono)">${p.stock}</span>`;

        const acoes = pode_editar ? `
            <div style="display:flex;gap:6px">
                <button class="btn btn-ghost btn-sm" onclick="abrirEdicao(${p.id})" aria-label="Editar">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </button>
                <button class="btn btn-danger btn-sm" onclick="confirmarDelete(${p.id}, '${esc(p.nome)}')" aria-label="Remover">
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                </button>
            </div>
        ` : '—';

        return `
            <tr>
                <td>
                    <img src="${esc(p.imagem)}" alt="${esc(p.nome)}" class="prod-thumb"
                         onerror="this.src='https://placehold.co/36x36/14161A/454952?text=?'">
                </td>
                <td style="font-weight:500;color:var(--off-white)">${esc(p.nome)}</td>
                <td><span class="cat-badge cat-${esc(p.categoria)}">${esc(label_cat(p.categoria))}</span></td>
                <td style="font-family:var(--font-mono);font-size:12px;color:var(--chrome-dim)">${formatar_brl(p.preco)}</td>
                <td>${controle_stock}</td>
                <td>${acoes}</td>
            </tr>
        `;
    }).join('');
}

function renderPaginacao(pagina, total) {
    total_paginas = total;
    const container = document.getElementById('paginacao');
    container.innerHTML = '';

    if (total <= 1) return;

    const btn_ant = document.createElement('button');
    btn_ant.className   = 'btn btn-ghost btn-sm';
    btn_ant.innerHTML   = '<i class="bi bi-chevron-left"></i>';
    btn_ant.disabled    = pagina <= 1;
    btn_ant.addEventListener('click', () => carregarEstoque(pagina - 1));
    container.appendChild(btn_ant);

    const info = document.createElement('span');
    info.style.cssText = 'font-family:var(--font-mono);font-size:11px;color:var(--text-faint);align-self:center;padding:0 4px';
    info.textContent   = `${pagina} / ${total}`;
    container.appendChild(info);

    const btn_prox = document.createElement('button');
    btn_prox.className  = 'btn btn-ghost btn-sm';
    btn_prox.innerHTML  = '<i class="bi bi-chevron-right"></i>';
    btn_prox.disabled   = pagina >= total;
    btn_prox.addEventListener('click', () => carregarEstoque(pagina + 1));
    container.appendChild(btn_prox);
}

// Ajuste rápido de stock

async function ajustarStock(id_produto, delta) {
    try {
        const res   = await fetch(`/api/estoque/${id_produto}/stock`, {
            method:      'PATCH',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body:        JSON.stringify({ delta }),
        });
        const dados = await res.json();

        if (!res.ok) { toast(dados.erro || 'Erro ao ajustar estoque.', 'erro'); return; }

        const span = document.getElementById(`stock-${id_produto}`);
        if (span) {
            span.textContent = dados.stock;
            span.className   = classe_stock(dados.stock);
        }

    } catch {
        toast('Falha de conexão ao ajustar estoque.', 'erro');
    }
}

// Modal criar / editar

function abrirNovo() {
    document.getElementById('mTit').textContent    = 'Novo Produto';
    document.getElementById('produtoId').value     = '';
    document.getElementById('inputNome').value     = '';
    document.getElementById('inputCategoria').value = '';
    document.getElementById('inputPreco').value    = '';
    document.getElementById('inputStock').value    = '0';
    document.getElementById('inputImagem').value   = '';
    document.getElementById('inputDetalhes').value = '';
    document.getElementById('vMsg').classList.remove('show');
    modalProd.show();
}

async function abrirEdicao(id_produto) {
    document.getElementById('mTit').textContent = 'Editar Produto';
    document.getElementById('produtoId').value  = id_produto;
    document.getElementById('vMsg').classList.remove('show');
    modalProd.show();

    try {
        const res   = await fetch(`/api/estoque/${id_produto}`, { credentials: 'same-origin' });
        const dados = await res.json();

        if (!res.ok) throw new Error(dados.erro || 'Erro ao carregar produto.');

        document.getElementById('inputNome').value      = dados.nome      ?? '';
        document.getElementById('inputCategoria').value = dados.categoria  ?? '';
        document.getElementById('inputPreco').value     = dados.preco      ?? '';
        document.getElementById('inputStock').value     = dados.stock      ?? '0';
        document.getElementById('inputImagem').value    = dados.imagem     ?? '';
        document.getElementById('inputDetalhes').value  = dados.detalhes   ?? '';

    } catch (err) {
        document.getElementById('vTxt').textContent = err.message;
        document.getElementById('vMsg').classList.add('show');
    }
}

async function salvarProduto() {
    const id      = document.getElementById('produtoId').value;
    const payload = {
        nome:      document.getElementById('inputNome').value.trim(),
        categoria: document.getElementById('inputCategoria').value,
        preco:     parseFloat(document.getElementById('inputPreco').value),
        stock:     parseInt(document.getElementById('inputStock').value, 10),
        imagem:    document.getElementById('inputImagem').value.trim(),
        detalhes:  document.getElementById('inputDetalhes').value.trim(),
    };

    const btn = document.getElementById('btnSalvar');
    btn.disabled = true;

    try {
        const res   = await fetch(id ? `/api/estoque/${id}` : '/api/estoque', {
            method:      id ? 'PUT' : 'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body:        JSON.stringify(payload),
        });
        const dados = await res.json();

        if (!res.ok) throw new Error(dados.erro || 'Erro ao salvar.');

        toast(id ? 'Produto atualizado.' : 'Produto cadastrado.');
        modalProd.hide();
        carregarEstoque(pagina_atual);

    } catch (err) {
        document.getElementById('vTxt').textContent = err.message;
        document.getElementById('vMsg').classList.add('show');
    } finally {
        btn.disabled = false;
    }
}

// Modal exclusão

function confirmarDelete(id_produto, nome) {
    id_excluindo = id_produto;
    document.getElementById('excNome').textContent = nome;
    modalExc.show();
}

async function executarDelete() {
    if (id_excluindo === null) return;
    const btn = document.getElementById('btnConfirmarDelete');
    btn.disabled = true;

    try {
        const res   = await fetch(`/api/estoque/${id_excluindo}`, {
            method:      'DELETE',
            credentials: 'same-origin',
            headers:     { 'X-CSRF-Token': csrf },
        });
        const dados = await res.json();

        if (!res.ok) throw new Error(dados.erro || 'Erro ao remover.');

        toast('Produto removido do estoque.');
        modalExc.hide();
        carregarEstoque(pagina_atual);

    } catch (err) {
        toast(err.message, 'erro');
        btn.disabled = false;
    }
}

// Inicialização

document.addEventListener('DOMContentLoaded', () => {
    setupSidebar();
    modalProd = new bootstrap.Modal(document.getElementById('mProd'));
    modalExc  = new bootstrap.Modal(document.getElementById('mExc'));

    if (pode_editar) {
        document.getElementById('btnNovo').style.display = '';
        document.getElementById('btnNovo').addEventListener('click', abrirNovo);
    }

    document.getElementById('btnConfirmarDelete').addEventListener('click', executarDelete);

    document.getElementById('searchInput').addEventListener('input', e => {
        clearTimeout(timeout_busca);
        timeout_busca = setTimeout(() => {
            busca_atual = e.target.value.trim();
            carregarEstoque(1);
        }, 350);
    });

    document.querySelectorAll('.chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.chip').forEach(b => b.classList.remove('ativo'));
            btn.classList.add('ativo');
            categoria_atual = btn.dataset.cat;
            carregarEstoque(1);
        });
    });

    carregarEstoque();
});
