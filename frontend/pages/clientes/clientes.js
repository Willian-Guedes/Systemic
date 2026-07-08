/**
 * clientes.js
 *
 * Responsabilidades:
 *  1. Listar clientes com busca, filtro de status VIP e paginação
 *  2. Criar e editar clientes via modal (incluindo alternar VIP)
 *  3. Remover cliente com confirmação
 *
 * Acesso restrito ao gerente — a rota /clientes e as chamadas de
 * /api/clientes já são bloqueadas no backend pela permissão
 * 'clientes.gerenciar'; aqui só ajustamos a interface.
 */

const user        = window.__session_user || {};
const csrf        = user.csrf_token || '';
const pode_editar = (user.permissoes || []).includes('clientes.gerenciar');

let pagina_atual  = 1;
let total_paginas = 1;
let vip_atual     = '';
let busca_atual   = '';
let timeout_busca = null;
let id_excluindo  = null;

let modalCli, modalExc;

// Sidebar

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
    document.querySelectorAll('.rnav.r-c').forEach(el => {
        if (!perms.includes('clientes.gerenciar')) el.style.display = 'none';
    });

    if (pode_editar) {
        document.getElementById('btnNovo').style.display = '';
    }
}

function toggleSidebar() {
    const sb   = document.getElementById('sidebar');
    const ov   = document.getElementById('overlay');
    const open = sb.classList.toggle('open');
    ov.classList.toggle('show', open);
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}

// Toast

function toast(msg, tipo = 'ok') {
    const c    = document.getElementById('toastC');
    const t    = document.createElement('div');
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

// Iniciais do nome para avatar

function iniciais_do_nome(nome) {
    const partes = nome.trim().split(/\s+/).filter(Boolean);
    const first  = (partes[0] ?? '').charAt(0);
    const last   = (partes[partes.length - 1] ?? '').charAt(0);
    return (first !== last ? first + last : first).toUpperCase();
}

// Máscaras de exibição

function formatar_cpf(cpf) {
    const d = String(cpf ?? '').replace(/\D/g, '');
    if (d.length !== 11) return cpf ?? '';
    return `${d.slice(0,3)}.${d.slice(3,6)}.${d.slice(6,9)}-${d.slice(9,11)}`;
}

// Carregamento

async function carregarClientes(pagina = 1) {
    pagina_atual = pagina;

    const params = new URLSearchParams({ pagina, vip: vip_atual, busca: busca_atual });

    try {
        const res   = await fetch(`/api/clientes?${params}`, { credentials: 'same-origin' });
        const dados = await res.json();

        if (!res.ok) throw new Error(dados.erro || 'Erro ao carregar.');

        renderTabela(dados.clientes || []);
        renderPaginacao(dados.pagina, dados.total_paginas);
        document.getElementById('countLabel').textContent =
            `${dados.total ?? 0} cliente${dados.total !== 1 ? 's' : ''}`;

    } catch (e) {
        document.getElementById('tbodyClientes').innerHTML =
            `<tr><td colspan="6"><div class="empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><h4>Erro ao carregar clientes</h4></div></td></tr>`;
        toast(e.message, 'erro');
    }
}

function renderTabela(clientes) {
    const tbody = document.getElementById('tbodyClientes');

    if (!clientes.length) {
        tbody.innerHTML = `<tr><td colspan="6"><div class="empty"><i class="bi bi-people" aria-hidden="true"></i><h4>Nenhum cliente encontrado</h4></div></td></tr>`;
        return;
    }

    tbody.innerHTML = clientes.map(c => {
        const av_class = `cli-av${c.vip ? ' vip' : ''}`;
        const ini      = esc(iniciais_do_nome(c.nome));
        const status   = c.vip
            ? `<span class="vip-badge"><i class="bi bi-star-fill" aria-hidden="true"></i>VIP</span>`
            : `<span style="font-family:var(--font-mono);font-size:11px;color:var(--text-faint)">Padrão</span>`;

        const acoes = pode_editar
            ? `<button class="icon-btn" onclick="abrirModalEditar(${c.id})" title="Editar" aria-label="Editar ${esc(c.nome)}">
                 <i class="bi bi-pencil" aria-hidden="true"></i>
               </button>
               <button class="icon-btn" onclick="confirmarExclusao(${c.id}, '${ini}')" title="Remover" aria-label="Remover ${esc(c.nome)}" style="color:var(--rose)">
                 <i class="bi bi-person-x" aria-hidden="true"></i>
               </button>`
            : '—';

        return `
          <tr>
            <td><div class="${av_class}">${ini}</div></td>
            <td style="font-weight:600;color:var(--off-white)">${esc(c.nome)}</td>
            <td style="font-family:var(--font-mono);font-size:12px;color:var(--text-dim)">${esc(c.email)}</td>
            <td style="font-family:var(--font-mono);font-size:12px;color:var(--text-dim)">${esc(c.celular)}</td>
            <td>${status}</td>
            <td style="display:flex;gap:4px">${acoes}</td>
          </tr>`;
    }).join('');
}

function renderPaginacao(pagina, total) {
    total_paginas = total;
    const el      = document.getElementById('paginacao');

    if (total <= 1) { el.innerHTML = ''; return; }

    const btns = [];
    btns.push(`<button class="pb" ${pagina <= 1 ? 'disabled' : ''} onclick="carregarClientes(${pagina - 1})" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></button>`);

    for (let i = 1; i <= total; i++) {
        btns.push(`<button class="pb ${i === pagina ? 'active' : ''}" onclick="carregarClientes(${i})" aria-label="Página ${i}" ${i === pagina ? 'aria-current="page"' : ''}>${i}</button>`);
    }

    btns.push(`<button class="pb" ${pagina >= total ? 'disabled' : ''} onclick="carregarClientes(${pagina + 1})" aria-label="Próxima página"><i class="bi bi-chevron-right"></i></button>`);
    el.innerHTML = `<div class="pag-btns">${btns.join('')}</div>`;
}

// Filtros de chip

function setupChips() {
    document.querySelectorAll('[data-vip]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-vip]').forEach(b => b.classList.remove('ativo'));
            btn.classList.add('ativo');
            vip_atual = btn.dataset.vip;
            carregarClientes(1);
        });
    });
}

// Busca com debounce

function setupBusca() {
    document.getElementById('searchInput').addEventListener('input', e => {
        clearTimeout(timeout_busca);
        timeout_busca = setTimeout(() => {
            busca_atual = e.target.value.trim();
            carregarClientes(1);
        }, 350);
    });
}

// Modal: criar novo

function abrirModalNovo() {
    document.getElementById('mTit').textContent         = 'Novo Cliente';
    document.getElementById('cliId').value              = '';
    document.getElementById('inputNome').value          = '';
    document.getElementById('inputCpf').value           = '';
    document.getElementById('inputCelular').value       = '';
    document.getElementById('inputEmail').value         = '';
    document.getElementById('inputSenha').value         = '';
    document.getElementById('inputSenha').required      = true;
    document.getElementById('senhaOpcional').style.display = 'none';
    document.getElementById('inputVip').checked         = false;
    esconderErro();
    modalCli.show();
}

// Modal: editar existente

async function abrirModalEditar(id) {
    try {
        const res   = await fetch(`/api/clientes/${id}`, { credentials: 'same-origin' });
        const dados = await res.json();
        if (!res.ok) throw new Error(dados.erro || 'Erro ao carregar.');

        document.getElementById('mTit').textContent         = 'Editar Cliente';
        document.getElementById('cliId').value              = dados.id;
        document.getElementById('inputNome').value          = dados.nome;
        document.getElementById('inputCpf').value           = formatar_cpf(dados.cpf);
        document.getElementById('inputCelular').value       = dados.celular;
        document.getElementById('inputEmail').value         = dados.email;
        document.getElementById('inputSenha').value         = '';
        document.getElementById('inputSenha').required      = false;
        document.getElementById('senhaOpcional').style.display = '';
        document.getElementById('inputVip').checked         = !!dados.vip;
        esconderErro();
        modalCli.show();
    } catch (e) {
        toast(e.message, 'erro');
    }
}

// Salvar (criar ou editar)

async function salvarCliente() {
    const id      = document.getElementById('cliId').value;
    const nome    = document.getElementById('inputNome').value.trim();
    const cpf     = document.getElementById('inputCpf').value.trim();
    const celular = document.getElementById('inputCelular').value.trim();
    const email   = document.getElementById('inputEmail').value.trim();
    const senha   = document.getElementById('inputSenha').value;
    const vip     = document.getElementById('inputVip').checked;

    if (!nome || !cpf || !celular || !email) {
        mostrarErro('Preencha todos os campos obrigatórios.');
        return;
    }
    if (!id && !senha) {
        mostrarErro('Informe uma senha para o novo cliente.');
        return;
    }
    if (senha && senha.length < 8) {
        mostrarErro('A senha deve ter no mínimo 8 caracteres.');
        return;
    }

    const payload = { nome, cpf, celular, email, vip, csrf_token: csrf };
    if (senha) payload.senha = senha;

    const metodo = id ? 'PATCH' : 'POST';
    const url    = id ? `/api/clientes/${id}` : '/api/clientes';

    const btn = document.getElementById('btnSalvar');
    btn.disabled = true;

    try {
        const res   = await fetch(url, {
            method:      metodo,
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify(payload),
        });
        const dados = await res.json();
        if (!res.ok) throw new Error(dados.erro || 'Erro ao salvar.');

        modalCli.hide();
        toast(id ? 'Cliente atualizado.' : 'Cliente criado.', 'ok');
        carregarClientes(pagina_atual);
    } catch (e) {
        mostrarErro(e.message);
    } finally {
        btn.disabled = false;
    }
}

// Exclusão

function confirmarExclusao(id, nome) {
    id_excluindo = id;
    document.getElementById('excNome').textContent = nome;
    modalExc.show();
}

async function executarExclusao() {
    if (!id_excluindo) return;

    const btn = document.getElementById('btnConfirmarDelete');
    btn.disabled = true;

    try {
        const res   = await fetch(`/api/clientes/${id_excluindo}`, {
            method:      'DELETE',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ csrf_token: csrf }),
        });
        const dados = await res.json();
        if (!res.ok) throw new Error(dados.erro || 'Erro ao remover.');

        modalExc.hide();
        toast('Cliente removido.', 'ok');
        carregarClientes(pagina_atual);
    } catch (e) {
        toast(e.message, 'erro');
    } finally {
        btn.disabled  = false;
        id_excluindo  = null;
    }
}

// Utilitários de validação no modal

function mostrarErro(msg) {
    const el = document.getElementById('vMsg');
    document.getElementById('vTxt').textContent = msg;
    el.classList.add('show');
}

function esconderErro() {
    document.getElementById('vMsg').classList.remove('show');
}

// Chip helper (mesma classe usada em funcionarios.js / estoque.js)

(function injetarChipStyle() {
    if (document.querySelector('style[data-chip]')) return;
    const s = document.createElement('style');
    s.dataset.chip = '1';
    s.textContent = `
      .chip {
        padding: 4px 14px;
        border-radius: 50px;
        border: 1px solid var(--border-subtle);
        background: transparent;
        color: var(--text-dim);
        font-size: 12px;
        font-family: var(--font-display);
        letter-spacing: .04em;
        cursor: pointer;
        transition: var(--transition);
      }
      .chip:hover, .chip.ativo {
        background: var(--red-vivid);
        border-color: var(--red-vivid);
        color: #fff;
      }
    `;
    document.head.appendChild(s);
})();

// Boot

document.addEventListener('DOMContentLoaded', () => {
    modalCli = new bootstrap.Modal(document.getElementById('mCli'));
    modalExc = new bootstrap.Modal(document.getElementById('mExc'));

    document.getElementById('btnConfirmarDelete').addEventListener('click', executarExclusao);

    setupSidebar();
    setupChips();
    setupBusca();
    carregarClientes();
});