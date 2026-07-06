/**
 * agendamentos.js
 *
 * Responsabilidades:
 *  1. Listar agendamentos vindos do site do cliente, com busca,
 *     filtro por status e paginação
 *  2. Abrir detalhes de um agendamento e alterar seu status
 *     (pendente -> confirmado -> concluído, ou cancelado)
 *  3. Remover um agendamento com confirmação
 */

const user         = window.__session_user || {};
const csrf         = user.csrf_token || '';
const pode_gerir   = (user.permissoes || []).includes('agendamentos.gerenciar');

let pagina_atual   = 1;
let total_paginas  = 1;
let status_atual   = '';
let busca_atual    = '';
let timeout_busca  = null;
let agendamentos_cache = [];
let id_excluindo   = null;

let modalAg, modalExc, modalNovo;

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
    document.querySelectorAll('.rnav.r-ag').forEach(el => {
        if (!perms.includes('agendamentos.visualizar')) el.style.display = 'none';
    });
    document.querySelectorAll('.rnav.r-g').forEach(el => {
        if (!perms.includes('funcionarios.visualizar')) el.style.display = 'none';
    });
    document.querySelectorAll('.rnav.r-m').forEach(el => {
        if (!perms.includes('estoque.visualizar')) el.style.display = 'none';
    });

    if (pode_gerir) {
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

function label_status(status) {
    return { pendente: 'Pendente', confirmado: 'Confirmado', concluido: 'Concluído', cancelado: 'Cancelado' }[status] ?? status;
}

function formatar_data(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.split('-');
    return `${dia}/${mes}/${ano}`;
}

// Carregamento

async function carregarAgendamentos(pagina = 1) {
    pagina_atual = pagina;

    const params = new URLSearchParams({ pagina, status: status_atual, busca: busca_atual });

    try {
        const res   = await fetch(`/api/agendamentos/gerenciar?${params}`, { credentials: 'same-origin' });
        const dados = await res.json();

        if (!res.ok || dados.ok === false) throw new Error(dados.erro || 'Erro ao carregar.');

        agendamentos_cache = dados.agendamentos || [];
        renderTabela(agendamentos_cache);
        renderPaginacao(dados.pagina, dados.total_paginas);
        document.getElementById('countLabel').textContent =
            `${dados.total ?? 0} agendamento${dados.total !== 1 ? 's' : ''}`;

    } catch (e) {
        document.getElementById('tbodyAgendamentos').innerHTML =
            `<tr><td colspan="6"><div class="empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><h4>Erro ao carregar agendamentos</h4></div></td></tr>`;
        toast(e.message, 'erro');
    }
}

function renderTabela(agendamentos) {
    const tbody = document.getElementById('tbodyAgendamentos');

    if (!agendamentos.length) {
        tbody.innerHTML = `<tr><td colspan="6"><div class="empty"><i class="bi bi-calendar2-check" aria-hidden="true"></i><h4>Nenhum agendamento encontrado</h4></div></td></tr>`;
        return;
    }

    tbody.innerHTML = agendamentos.map(a => {
        const status_cls = `status-badge status-${esc(a.status)}`;
        const veiculo     = `${esc(a.marca)} ${esc(a.modelo)}${a.placa ? ' · ' + esc(a.placa) : ''}`;

        return `
          <tr>
            <td style="font-weight:600;color:var(--off-white)">${esc(a.nome)}</td>
            <td style="font-size:12px;color:var(--text-dim)">${veiculo}</td>
            <td style="font-size:12px;color:var(--text-dim)">${esc(a.servico)}</td>
            <td style="font-family:var(--font-mono);font-size:12px;color:var(--text-dim)">
              ${formatar_data(a.data_preferida)}${a.turno ? ' · ' + esc(a.turno) : ''}
            </td>
            <td><span class="${status_cls}">${label_status(a.status)}</span></td>
            <td>
              <button class="icon-btn" onclick="abrirDetalhes(${a.id})" title="Ver detalhes" aria-label="Ver detalhes de ${esc(a.nome)}">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </td>
          </tr>`;
    }).join('');
}

function renderPaginacao(pagina, total) {
    total_paginas = total;
    const el      = document.getElementById('paginacao');

    if (total <= 1) { el.innerHTML = ''; return; }

    const btns = [];
    btns.push(`<button class="pb" ${pagina <= 1 ? 'disabled' : ''} onclick="carregarAgendamentos(${pagina - 1})" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></button>`);

    for (let i = 1; i <= total; i++) {
        btns.push(`<button class="pb ${i === pagina ? 'active' : ''}" onclick="carregarAgendamentos(${i})" aria-label="Página ${i}" ${i === pagina ? 'aria-current="page"' : ''}>${i}</button>`);
    }

    btns.push(`<button class="pb" ${pagina >= total ? 'disabled' : ''} onclick="carregarAgendamentos(${pagina + 1})" aria-label="Próxima página"><i class="bi bi-chevron-right"></i></button>`);
    el.innerHTML = `<div class="pag-btns">${btns.join('')}</div>`;
}

// Filtros de chip

function setupChips() {
    document.querySelectorAll('[data-status]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-status]').forEach(b => b.classList.remove('ativo'));
            btn.classList.add('ativo');
            status_atual = btn.dataset.status;
            carregarAgendamentos(1);
        });
    });
}

// Busca com debounce

function setupBusca() {
    document.getElementById('searchInput').addEventListener('input', e => {
        clearTimeout(timeout_busca);
        timeout_busca = setTimeout(() => {
            busca_atual = e.target.value.trim();
            carregarAgendamentos(1);
        }, 350);
    });
}

// Modal de detalhes

function abrirDetalhes(id) {
    const a = agendamentos_cache.find(x => x.id === id);
    if (!a) return;

    document.getElementById('agId').value        = a.id;
    document.getElementById('dNome').textContent      = a.nome;
    document.getElementById('dTelefone').textContent   = a.telefone || '—';
    document.getElementById('dEmail').textContent      = a.email || '—';
    document.getElementById('dPlaca').textContent      = a.placa || '—';
    document.getElementById('dVeiculo').textContent    = `${a.marca} ${a.modelo}${a.ano ? ' (' + a.ano + ')' : ''}`;
    document.getElementById('dComb').textContent        = `${a.combustivel || '—'} · ${a.km ? a.km + ' km' : '—'}`;
    document.getElementById('dServico').textContent     = a.servico;
    document.getElementById('dData').textContent        = `${formatar_data(a.data_preferida)} · ${a.turno || '—'}`;
    document.getElementById('dSintomas').textContent    = a.sintomas || '—';
    document.getElementById('dDescricao').textContent   = a.descricao || '—';
    document.getElementById('dCriado').textContent      = a.criado_em || '—';
    document.getElementById('inputStatus').value        = a.status;

    document.getElementById('inputStatus').disabled = !pode_gerir;
    document.getElementById('btnSalvarStatus').style.display = pode_gerir ? '' : 'none';
    document.getElementById('btnExcluirAg').style.display    = pode_gerir ? '' : 'none';

    esconderErro();
    modalAg.show();
}

// Salvar novo status

async function salvarStatus() {
    const id     = document.getElementById('agId').value;
    const status = document.getElementById('inputStatus').value;

    const btn = document.getElementById('btnSalvarStatus');
    btn.disabled = true;

    try {
        const res   = await fetch(`/api/agendamentos/${id}/status`, {
            method:      'PATCH',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body:        JSON.stringify({ status }),
        });
        const dados = await res.json();
        if (!res.ok || dados.ok === false) throw new Error(dados.erro || 'Erro ao salvar.');

        modalAg.hide();
        toast('Status do agendamento atualizado.', 'ok');
        carregarAgendamentos(pagina_atual);
    } catch (e) {
        mostrarErro(e.message);
    } finally {
        btn.disabled = false;
    }
}

// Modal: criar novo agendamento (recepção/gerência)

function abrirModalNovo() {
    ['nNome','nTelefone','nEmail','nPlaca','nMarca','nModelo','nAno','nCombustivel',
     'nKm','nServico','nSintomas','nDescricao','nData'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.getElementById('nTurno').value  = '';
    document.getElementById('nStatus').value = 'confirmado';
    esconderErroNovo();
    modalNovo.show();
}

async function salvarNovoAgendamento() {
    const payload = {
        nome:           document.getElementById('nNome').value.trim(),
        telefone:       document.getElementById('nTelefone').value.trim(),
        email:          document.getElementById('nEmail').value.trim(),
        placa:          document.getElementById('nPlaca').value.trim(),
        marca:          document.getElementById('nMarca').value.trim(),
        modelo:         document.getElementById('nModelo').value.trim(),
        ano:            document.getElementById('nAno').value,
        combustivel:    document.getElementById('nCombustivel').value.trim(),
        km:             document.getElementById('nKm').value,
        servico:        document.getElementById('nServico').value.trim(),
        sintomas:       document.getElementById('nSintomas').value.trim(),
        descricao:      document.getElementById('nDescricao').value.trim(),
        data_preferida: document.getElementById('nData').value,
        turno:          document.getElementById('nTurno').value,
        status:         document.getElementById('nStatus').value,
    };

    if (!payload.nome || !payload.telefone || !payload.marca || !payload.modelo ||
        !payload.servico || !payload.data_preferida) {
        mostrarErroNovo('Preencha todos os campos obrigatórios.');
        return;
    }

    const btn = document.getElementById('btnSalvarNovo');
    btn.disabled = true;

    try {
        const res   = await fetch('/api/agendamentos/gerenciar', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body:        JSON.stringify(payload),
        });
        const dados = await res.json();
        if (!res.ok || dados.ok === false) throw new Error(dados.erro || 'Erro ao salvar.');

        modalNovo.hide();
        toast('Agendamento criado.', 'ok');
        carregarAgendamentos(1);
    } catch (e) {
        mostrarErroNovo(e.message);
    } finally {
        btn.disabled = false;
    }
}

function mostrarErroNovo(msg) {
    const el = document.getElementById('vMsgNovo');
    document.getElementById('vTxtNovo').textContent = msg;
    el.classList.add('show');
}

function esconderErroNovo() {
    document.getElementById('vMsgNovo').classList.remove('show');
}

// Exclusão

function confirmarExclusao() {
    id_excluindo = document.getElementById('agId').value;
    document.getElementById('excNome').textContent = document.getElementById('dNome').textContent;
    modalAg.hide();
    modalExc.show();
}

async function executarExclusao() {
    if (!id_excluindo) return;

    const btn = document.getElementById('btnConfirmarDelete');
    btn.disabled = true;

    try {
        const res   = await fetch(`/api/agendamentos/${id_excluindo}`, {
            method:      'DELETE',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        });
        const dados = await res.json();
        if (!res.ok || dados.ok === false) throw new Error(dados.erro || 'Erro ao remover.');

        modalExc.hide();
        toast('Agendamento removido.', 'ok');
        carregarAgendamentos(pagina_atual);
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
    modalAg   = new bootstrap.Modal(document.getElementById('mAg'));
    modalExc  = new bootstrap.Modal(document.getElementById('mExc'));
    modalNovo = new bootstrap.Modal(document.getElementById('mNovo'));

    document.getElementById('btnConfirmarDelete').addEventListener('click', executarExclusao);

    setupSidebar();
    setupChips();
    setupBusca();
    carregarAgendamentos();
});
