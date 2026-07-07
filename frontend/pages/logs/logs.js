let pagina_atual  = 1;
let total_paginas = 1;
let total_global  = 0;
let timeout_busca = null;

const nivel_para_estilo = {
    gerente:  { av: 'av-gerente',  pb: 'pb-gerente',  bdg: 'b-red',  label: 'Gerente'        },
    recepcao: { av: 'av-recepcao', pb: 'pb-recepcao', bdg: 'b-cyan', label: 'Recepcionista'  },
    mecanico: { av: 'av-mecanico', pb: 'pb-mecanico', bdg: 'b-blue', label: 'Mecânico'       },
};

function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function fmt_datetime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function init_user_display() {
    const user   = window.__session_user || {};
    const estilo = nivel_para_estilo[user.nivel] ?? nivel_para_estilo.mecanico;

    const av = document.getElementById('sbAv');
    av.textContent = user.iniciais || '?';
    av.className   = 'av ' + (user.nivel ? estilo.av : '');

    document.getElementById('sbName').textContent = user.nome || '';

    const role = document.getElementById('sbRole');
    role.textContent = user.nivel ? estilo.label : '';
    role.className   = 'pbadge ' + (user.nivel ? estilo.pb : '');

    const perms = user.permissoes || [];
    document.querySelectorAll('.rnav.r-g').forEach(el => {
        if (!perms.includes('funcionarios.visualizar') && !perms.includes('logs.visualizar'))
            el.style.display = 'none';
    });
    document.querySelectorAll('.rnav.r-m').forEach(el => {
        if (!perms.includes('estoque.visualizar')) el.style.display = 'none';
    });

    const csrf = document.getElementById('csrfLogout');
    if (csrf) csrf.value = user.csrf_token ?? '';
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

async function carregar_funcionarios() {
    try {
        const res   = await fetch('/api/logs/funcionarios');
        if (!res.ok) return;
        const lista = await res.json();
        const sel   = document.getElementById('selectFuncionario');
        lista.forEach(f => {
            const opt       = document.createElement('option');
            opt.value       = f.id;
            opt.textContent = f.nome;
            sel.appendChild(opt);
        });
    } catch { /* select fica só com "Todos" */ }
}

async function carregar_logs(pagina = 1) {
    pagina_atual = pagina;

    const busca       = document.getElementById('inputBusca').value.trim();
    const funcionario = document.getElementById('selectFuncionario').value;
    const data_inicio = document.getElementById('inputDataInicio').value;
    const data_fim    = document.getElementById('inputDataFim').value;

    const params = new URLSearchParams({ pagina });
    if (busca)       params.set('busca',       busca);
    if (funcionario) params.set('funcionario', funcionario);
    if (data_inicio) params.set('data_inicio', data_inicio);
    if (data_fim)    params.set('data_fim',    data_fim);

    try {
        const res  = await fetch('/api/logs?' + params.toString());
        if (!res.ok) throw new Error('Erro ' + res.status);
        const data = await res.json();

        total_paginas = data.total_paginas || 1;
        total_global  = data.total         || 0;

        renderizar_tabela(data.registros || []);
        renderizar_stats(data.total);
        renderizar_paginacao();

    } catch (e) {
        console.error('[carregar_logs]', e);
        document.getElementById('tabelaBody').innerHTML = `
            <tr><td colspan="4">
                <div class="empty">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    <h4>Erro ao carregar</h4>
                    <p>Tente atualizar a página.</p>
                </div>
            </td></tr>`;
    }
}

function renderizar_tabela(registros) {
    const tbody = document.getElementById('tabelaBody');

    if (registros.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="4">
                <div class="empty">
                    <i class="bi bi-journal-x" aria-hidden="true"></i>
                    <h4>Nenhum registro encontrado</h4>
                    <p>Tente ajustar os filtros.</p>
                </div>
            </td></tr>`;
        return;
    }

    tbody.innerHTML = registros.map(r => {
        const nivel        = r.nivel_de_acesso || '';
        const nivel_estilo = nivel_para_estilo[nivel];
        const badge_nivel  = nivel_estilo
            ? `<span class="bdg ${nivel_estilo.bdg}">${esc(nivel_estilo.label)}</span>`
            : '<span style="color:var(--text-faint)">—</span>';

        return `
            <tr>
                <td style="white-space:nowrap;color:var(--text-dim);font-family:var(--font-mono);font-size:11px">
                    ${fmt_datetime(r.momento_completo)}
                </td>
                <td>
                    <div style="font-weight:500;color:var(--off-white)">${esc(r.nome_funcionario) || '—'}</div>
                    <div style="margin-top:2px">${badge_nivel}</div>
                </td>
                <td style="color:var(--text-body)">${esc(r.detalhe) || '—'}</td>
            </tr>`;
    }).join('');
}

function renderizar_stats(total) {
    document.getElementById('statTotal').textContent = total ?? 0;
}

function renderizar_paginacao() {
    const bar  = document.getElementById('pagBar');
    const info = document.getElementById('pagInfo');
    const btns = document.getElementById('pagBtns');

    if (total_paginas <= 1) { bar.style.display = 'none'; return; }
    bar.style.display = '';
    info.textContent  = `Página ${pagina_atual} de ${total_paginas} — ${total_global} registros`;
    btns.innerHTML    = '';

    const criar_botao = (label, pagina, ativo = false, desabilitado = false) => {
        const b     = document.createElement('button');
        b.className = 'pb' + (ativo ? ' active' : '');
        b.textContent = label;
        b.disabled    = desabilitado;
        if (!desabilitado) b.onclick = () => carregar_logs(pagina);
        return b;
    };

    btns.appendChild(criar_botao('‹', pagina_atual - 1, false, pagina_atual === 1));

    const inicio = Math.max(1, pagina_atual - 2);
    const fim    = Math.min(total_paginas, pagina_atual + 2);

    if (inicio > 1) {
        btns.appendChild(criar_botao('1', 1));
        if (inicio > 2) btns.insertAdjacentHTML('beforeend', '<span style="color:var(--text-faint);padding:0 4px">…</span>');
    }
    for (let p = inicio; p <= fim; p++) {
        btns.appendChild(criar_botao(String(p), p, p === pagina_atual));
    }
    if (fim < total_paginas) {
        if (fim < total_paginas - 1) btns.insertAdjacentHTML('beforeend', '<span style="color:var(--text-faint);padding:0 4px">…</span>');
        btns.appendChild(criar_botao(String(total_paginas), total_paginas));
    }
    btns.appendChild(criar_botao('›', pagina_atual + 1, false, pagina_atual === total_paginas));
}

function limparFiltros() {
    document.getElementById('inputBusca').value        = '';
    document.getElementById('selectFuncionario').value = '';
    document.getElementById('inputDataInicio').value   = '';
    document.getElementById('inputDataFim').value      = '';
    carregar_logs(1);
}

function setup_filtros() {
    document.getElementById('inputBusca').addEventListener('input', () => {
        clearTimeout(timeout_busca);
        timeout_busca = setTimeout(() => carregar_logs(1), 350);
    });
    ['selectFuncionario', 'inputDataInicio', 'inputDataFim'].forEach(id =>
        document.getElementById(id).addEventListener('change', () => carregar_logs(1))
    );
}

document.addEventListener('DOMContentLoaded', async () => {
    init_user_display();
    setup_filtros();
    await carregar_funcionarios();
    await carregar_logs(1);
});
