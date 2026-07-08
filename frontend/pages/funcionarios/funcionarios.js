/**
 * funcionarios.js
 *
 * Responsabilidades:
 *  1. Listar funcionários com busca, filtro de nível e paginação
 *  2. Criar e editar funcionários via modal
 *  3. Remover funcionário com confirmação
 */

const user        = window.__session_user || {};
const csrf        = user.csrf_token || '';
const pode_editar = (user.permissoes || []).includes('funcionarios.gerenciar');

let pagina_atual  = 1;
let total_paginas = 1;
let nivel_atual   = '';
let busca_atual   = '';
let timeout_busca = null;
let id_excluindo  = null;

let modalFunc, modalExc;

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

// Carregamento

async function carregarFuncionarios(pagina = 1) {
    pagina_atual = pagina;

    const params = new URLSearchParams({ pagina, nivel: nivel_atual, busca: busca_atual });

    try {
        const res   = await fetch(`/api/funcionarios?${params}`, { credentials: 'same-origin' });
        const dados = await res.json();

        if (!res.ok) throw new Error(dados.erro || 'Erro ao carregar.');

        renderTabela(dados.funcionarios || []);
        renderPaginacao(dados.pagina, dados.total_paginas);
        document.getElementById('countLabel').textContent =
            `${dados.total ?? 0} funcionário${dados.total !== 1 ? 's' : ''}`;

    } catch (e) {
        document.getElementById('tbodyFuncionarios').innerHTML =
            `<tr><td colspan="5"><div class="empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><h4>Erro ao carregar funcionários</h4></div></td></tr>`;
        toast(e.message, 'erro');
    }
}

function renderTabela(funcionarios) {
    const tbody = document.getElementById('tbodyFuncionarios');

    if (!funcionarios.length) {
        tbody.innerHTML = `<tr><td colspan="5"><div class="empty"><i class="bi bi-people" aria-hidden="true"></i><h4>Nenhum funcionário encontrado</h4></div></td></tr>`;
        return;
    }

    tbody.innerHTML = funcionarios.map(f => {
        const av_class  = `func-av av-${esc(f.nivel)}`;
        const ini       = esc(iniciais_do_nome(f.nome));
        const nivel_cls = `nivel-badge nivel-${esc(f.nivel)}`;
        const nivel_txt = label_nivel(f.nivel);

        const acoes = pode_editar
            ? `<button class="icon-btn" onclick="abrirModalEditar(${f.id})" title="Editar" aria-label="Editar ${esc(f.nome)}">
                 <i class="bi bi-pencil" aria-hidden="true"></i>
               </button>
               <button class="icon-btn" onclick="confirmarExclusao(${f.id}, '${ini}')" title="Remover" aria-label="Remover ${esc(f.nome)}" style="color:var(--rose)">
                 <i class="bi bi-person-x" aria-hidden="true"></i>
               </button>`
            : '—';

        return `
          <tr>
            <td><div class="${av_class}">${ini}</div></td>
            <td style="font-weight:600;color:var(--off-white)">${esc(f.nome)}</td>
            <td style="font-family:var(--font-mono);font-size:12px;color:var(--text-dim)">${esc(f.email)}</td>
            <td><span class="${nivel_cls}">${nivel_txt}</span></td>
            <td style="display:flex;gap:4px">${acoes}</td>
          </tr>`;
    }).join('');
}

function label_nivel(nivel) {
    return { gerente: 'Gerente', mecanico: 'Mecânico', recepcao: 'Recepção' }[nivel] ?? nivel;
}

function renderPaginacao(pagina, total) {
    total_paginas = total;
    const el      = document.getElementById('paginacao');

    if (total <= 1) { el.innerHTML = ''; return; }

    const btns = [];
    btns.push(`<button class="pb" ${pagina <= 1 ? 'disabled' : ''} onclick="carregarFuncionarios(${pagina - 1})" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></button>`);

    for (let i = 1; i <= total; i++) {
        btns.push(`<button class="pb ${i === pagina ? 'active' : ''}" onclick="carregarFuncionarios(${i})" aria-label="Página ${i}" ${i === pagina ? 'aria-current="page"' : ''}>${i}</button>`);
    }

    btns.push(`<button class="pb" ${pagina >= total ? 'disabled' : ''} onclick="carregarFuncionarios(${pagina + 1})" aria-label="Próxima página"><i class="bi bi-chevron-right"></i></button>`);
    el.innerHTML = `<div class="pag-btns">${btns.join('')}</div>`;
}

// Filtros de chip

function setupChips() {
    document.querySelectorAll('[data-nivel]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-nivel]').forEach(b => b.classList.remove('ativo'));
            btn.classList.add('ativo');
            nivel_atual = btn.dataset.nivel;
            carregarFuncionarios(1);
        });
    });
}

// Busca com debounce

function setupBusca() {
    document.getElementById('searchInput').addEventListener('input', e => {
        clearTimeout(timeout_busca);
        timeout_busca = setTimeout(() => {
            busca_atual = e.target.value.trim();
            carregarFuncionarios(1);
        }, 350);
    });
}

// Modal: criar novo

function abrirModalNovo() {
    document.getElementById('mTit').textContent         = 'Novo Funcionário';
    document.getElementById('funcId').value             = '';
    document.getElementById('inputNome').value          = '';
    document.getElementById('inputEmail').value         = '';
    document.getElementById('inputNivel').value         = '';
    document.getElementById('inputSenha').value         = '';
    document.getElementById('inputSenha').required      = true;
    document.getElementById('senhaOpcional').style.display = 'none';
    esconderErro();
    modalFunc.show();
}

// Modal: editar existente

async function abrirModalEditar(id) {
    try {
        const res   = await fetch(`/api/funcionarios/${id}`, { credentials: 'same-origin' });
        const dados = await res.json();
        if (!res.ok) throw new Error(dados.erro || 'Erro ao carregar.');

        document.getElementById('mTit').textContent         = 'Editar Funcionário';
        document.getElementById('funcId').value             = dados.id;
        document.getElementById('inputNome').value          = dados.nome;
        document.getElementById('inputEmail').value         = dados.email;
        document.getElementById('inputNivel').value         = dados.nivel;
        document.getElementById('inputSenha').value         = '';
        document.getElementById('inputSenha').required      = false;
        document.getElementById('senhaOpcional').style.display = '';
        esconderErro();
        modalFunc.show();
    } catch (e) {
        toast(e.message, 'erro');
    }
}

// Salvar (criar ou editar)

async function salvarFuncionario() {
    const id    = document.getElementById('funcId').value;
    const nome  = document.getElementById('inputNome').value.trim();
    const email = document.getElementById('inputEmail').value.trim();
    const nivel = document.getElementById('inputNivel').value;
    const senha = document.getElementById('inputSenha').value;

    if (!nome || !email || !nivel) {
        mostrarErro('Preencha todos os campos obrigatórios.');
        return;
    }
    if (!id && !senha) {
        mostrarErro('Informe uma senha para o novo funcionário.');
        return;
    }
    if (senha && senha.length < 8) {
        mostrarErro('A senha deve ter no mínimo 8 caracteres.');
        return;
    }

    const payload = { nome, email, nivel, csrf_token: csrf };
    if (senha) payload.senha = senha;

    const metodo = id ? 'PUT' : 'POST';
    const url    = id ? `/api/funcionarios/${id}` : '/api/funcionarios';

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

        modalFunc.hide();
        toast(id ? 'Funcionário atualizado.' : 'Funcionário criado.', 'ok');
        carregarFuncionarios(pagina_atual);
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
        const res   = await fetch(`/api/funcionarios/${id_excluindo}`, {
            method:      'DELETE',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ csrf_token: csrf }),
        });
        const dados = await res.json();
        if (!res.ok) throw new Error(dados.erro || 'Erro ao remover.');

        modalExc.hide();
        toast('Funcionário removido.', 'ok');
        carregarFuncionarios(pagina_atual);
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

// Chip helper (mesma classe usada em estoque.js)

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
    modalFunc = new bootstrap.Modal(document.getElementById('mFunc'));
    modalExc  = new bootstrap.Modal(document.getElementById('mExc'));

    document.getElementById('btnConfirmarDelete').addEventListener('click', executarExclusao);

    setupSidebar();
    setupChips();
    setupBusca();
    carregarFuncionarios();
});