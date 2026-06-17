/* ═══════════════════════════════════════════════
   PAINEL DO CLIENTE
   Gerencia veículos próprios e lista agendamentos.
═══════════════════════════════════════════════ */

let editandoVeiculoId = null;
let excluindoVeiculoId = null;

const STATUS_LABELS = {
    pendente:   { texto: 'Pendente',   classe: '' },
    confirmado: { texto: 'Confirmado', classe: 'confirmado' },
    concluido:  { texto: 'Concluído',  classe: 'concluido' },
};

document.addEventListener('DOMContentLoaded', function () {
    inject_csrf_logout();
    init_boas_vindas();
    init_botao_voltar();
    carregar_perfil();
    carregar_veiculos();
    carregar_agendamentos();

    document.getElementById('btnNovoVeiculo').addEventListener('click', abrir_modal_novo_veiculo);
    document.getElementById('btnSalvarVeiculo').addEventListener('click', salvar_veiculo);
    document.getElementById('btnConfirmarExcluir').addEventListener('click', confirmar_exclusao_veiculo);
    document.getElementById('btnAlterarFoto').addEventListener('click', () => document.getElementById('inputFoto').click());
    document.getElementById('inputFoto').addEventListener('change', enviar_foto_perfil);
    document.getElementById('btnRemoverFoto').addEventListener('click', remover_foto_perfil);

    document.getElementById('veiculoPlaca').addEventListener('input', function () {
        this.value = this.value.toUpperCase();
    });

    /* Fecha modal ao clicar no X, em "Cancelar", ou fora da caixa */
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fechar_modal(this.dataset.closeModal);
        });
    });

    document.querySelectorAll('.painel-modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (evento) {
            if (evento.target === overlay) fechar_modal(overlay.id);
        });
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape') {
            document.querySelectorAll('.painel-modal-overlay:not([hidden])').forEach(function (overlay) {
                fechar_modal(overlay.id);
            });
        }
    });
});

function abrir_modal(id) {
    document.getElementById(id).hidden = false;
}

function fechar_modal(id) {
    document.getElementById(id).hidden = true;
}

function inject_csrf_logout() {
    const token = window.__session_user?.csrf_token ?? '';
    document.querySelectorAll('#csrfLogout, .csrf-logout-input').forEach(function (input) {
        input.value = token;
    });
}

function init_boas_vindas() {
    const nome = window.__session_user?.nome ?? '';
    const el = document.getElementById('boasVindas');
    if (el && nome) {
        el.textContent = `Bem-vindo(a) de volta, ${nome.split(' ')[0]}. Gerencie seus veículos e agendamentos.`;
    }
}

function csrf_headers() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.__session_user?.csrf_token ?? '',
    };
}

function escape_html(texto) {
    const div = document.createElement('div');
    div.textContent = texto ?? '';
    return div.innerHTML;
}

/* ═══════════════════════════════════════════════
   PERFIL
═══════════════════════════════════════════════ */

function iniciais_para_avatar(nome) {
    const primeira = (nome || '').trim().charAt(0).toUpperCase();
    return primeira || '?';
}

function url_avatar_placeholder(nome) {
    const letra = encodeURIComponent(iniciais_para_avatar(nome));
    return `https://placehold.co/256x256/272727/ffffff?text=${letra}`;
}

function init_botao_voltar() {
    document.getElementById('btnVoltar').addEventListener('click', function () {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '/';
        }
    });
}

async function carregar_perfil() {
    try {
        const resposta = await fetch('/api/perfil', { credentials: 'same-origin' });
        if (!resposta.ok) throw new Error('Falha ao carregar perfil.');

        const perfil = await resposta.json();
        renderizar_perfil(perfil);
    } catch (erro) {
        mostrar_erro_perfil('Não foi possível carregar seu perfil.');
    }
}

function renderizar_perfil(perfil) {
    const avatar = document.getElementById('avatarPerfil');
    avatar.src = perfil.foto_url || url_avatar_placeholder(perfil.nome);

    document.getElementById('perfilNome').textContent  = perfil.nome  || '';
    document.getElementById('perfilEmail').textContent = perfil.email || '';
    document.getElementById('btnRemoverFoto').hidden = !perfil.foto_url;
}

function mostrar_erro_perfil(mensagem) {
    const erro = document.getElementById('erroPerfil');
    erro.textContent = mensagem;
    erro.hidden = false;
}

function esconder_erro_perfil() {
    document.getElementById('erroPerfil').hidden = true;
}

async function enviar_foto_perfil(evento) {
    const arquivo = evento.target.files[0];
    evento.target.value = '';
    if (!arquivo) return;

    esconder_erro_perfil();

    const btn = document.getElementById('btnAlterarFoto');
    btn.disabled = true;

    const dados = new FormData();
    dados.append('foto', arquivo);

    try {
        const resposta = await fetch('/api/perfil/foto', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.__session_user?.csrf_token ?? '' },
            body: dados,
        });

        const json = await resposta.json();

        if (!resposta.ok) {
            mostrar_erro_perfil(json.erro || 'Não foi possível enviar a foto.');
            return;
        }

        document.getElementById('avatarPerfil').src = json.foto_url;
        document.getElementById('btnRemoverFoto').hidden = false;
    } catch (erro) {
        mostrar_erro_perfil('Falha na conexão. Tente novamente.');
    } finally {
        btn.disabled = false;
    }
}

async function remover_foto_perfil() {
    esconder_erro_perfil();

    const btn = document.getElementById('btnRemoverFoto');
    btn.disabled = true;

    try {
        const resposta = await fetch('/api/perfil/foto', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: csrf_headers(),
        });

        if (!resposta.ok) throw new Error('Falha ao remover foto.');

        const nome = document.getElementById('perfilNome').textContent;
        document.getElementById('avatarPerfil').src = url_avatar_placeholder(nome);
        btn.hidden = true;
    } catch (erro) {
        mostrar_erro_perfil('Não foi possível remover a foto. Tente novamente.');
    } finally {
        btn.disabled = false;
    }
}

/* ═══════════════════════════════════════════════
   VEÍCULOS
═══════════════════════════════════════════════ */

async function carregar_veiculos() {
    const container = document.getElementById('listaVeiculos');

    try {
        const resposta = await fetch('/api/veiculos', { credentials: 'same-origin' });
        if (!resposta.ok) throw new Error('Falha ao carregar veículos.');

        const veiculos = await resposta.json();
        renderizar_veiculos(veiculos);
    } catch (erro) {
        container.innerHTML = `<p class="estado-vazio">Não foi possível carregar seus veículos.</p>`;
    }
}

function renderizar_veiculos(veiculos) {
    const container = document.getElementById('listaVeiculos');

    if (!veiculos.length) {
        container.innerHTML = `<p class="estado-vazio">Você ainda não cadastrou nenhum veículo.</p>`;
        return;
    }

    container.innerHTML = veiculos.map(function (v) {
        return `
            <div class="veiculo-item" data-id="${v.id_veiculo}">
                <div class="veiculo-info">
                    <strong>${escape_html(v.marca)} ${escape_html(v.modelo)}</strong>
                    <div class="veiculo-sub">${escape_html(v.cor)} · ${escape_html(v.ano)}</div>
                    <span class="placa">${escape_html(v.placa)}</span>
                </div>
                <div class="veiculo-acoes">
                    <button type="button" class="editar" title="Editar" aria-label="Editar veículo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>
                    </button>
                    <button type="button" class="excluir" title="Remover" aria-label="Remover veículo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </div>
        `;
    }).join('');

    container.querySelectorAll('.veiculo-item').forEach(function (item) {
        const id = item.dataset.id;
        item.querySelector('.editar').addEventListener('click', function () {
            abrir_modal_editar_veiculo(id, veiculos.find(v => String(v.id_veiculo) === id));
        });
        item.querySelector('.excluir').addEventListener('click', function () {
            excluindoVeiculoId = id;
            abrir_modal('modalExcluir');
        });
    });
}

function abrir_modal_novo_veiculo() {
    editandoVeiculoId = null;
    document.getElementById('modalVeiculoTitulo').textContent = 'Adicionar veículo';
    limpar_form_veiculo();
    esconder_erro_veiculo();
    abrir_modal('modalVeiculo');
}

function abrir_modal_editar_veiculo(id, veiculo) {
    editandoVeiculoId = id;
    document.getElementById('modalVeiculoTitulo').textContent = 'Editar veículo';
    esconder_erro_veiculo();

    document.getElementById('veiculoId').value     = id;
    document.getElementById('veiculoMarca').value  = veiculo?.marca  ?? '';
    document.getElementById('veiculoModelo').value = veiculo?.modelo ?? '';
    document.getElementById('veiculoAno').value    = veiculo?.ano    ?? '';
    document.getElementById('veiculoCor').value    = veiculo?.cor    ?? '';
    document.getElementById('veiculoPlaca').value  = veiculo?.placa  ?? '';

    abrir_modal('modalVeiculo');
}

function limpar_form_veiculo() {
    ['veiculoId', 'veiculoMarca', 'veiculoModelo', 'veiculoAno', 'veiculoCor', 'veiculoPlaca']
        .forEach(id => document.getElementById(id).value = '');
}

function mostrar_erro_veiculo(mensagem) {
    const erro = document.getElementById('erroVeiculo');
    erro.textContent = mensagem;
    erro.hidden = false;
    erro.classList.add('visible');
}

function esconder_erro_veiculo() {
    const erro = document.getElementById('erroVeiculo');
    erro.hidden = true;
    erro.classList.remove('visible');
}

async function salvar_veiculo() {
    const payload = {
        marca:  document.getElementById('veiculoMarca').value.trim(),
        modelo: document.getElementById('veiculoModelo').value.trim(),
        ano:    document.getElementById('veiculoAno').value.trim(),
        cor:    document.getElementById('veiculoCor').value.trim(),
        placa:  document.getElementById('veiculoPlaca').value.trim(),
    };

    const url    = editandoVeiculoId ? `/api/veiculos/${editandoVeiculoId}` : '/api/veiculos';
    const metodo = editandoVeiculoId ? 'PATCH' : 'POST';

    const btn = document.getElementById('btnSalvarVeiculo');
    btn.disabled = true;

    try {
        const resposta = await fetch(url, {
            method: metodo,
            headers: csrf_headers(),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        const json = await resposta.json();

        if (!resposta.ok) {
            mostrar_erro_veiculo(json.erro || 'Não foi possível salvar o veículo.');
            return;
        }

        fechar_modal('modalVeiculo');
        carregar_veiculos();
    } catch (erro) {
        mostrar_erro_veiculo('Falha na conexão. Tente novamente.');
    } finally {
        btn.disabled = false;
    }
}

async function confirmar_exclusao_veiculo() {
    if (!excluindoVeiculoId) return;

    const btn = document.getElementById('btnConfirmarExcluir');
    btn.disabled = true;

    try {
        const resposta = await fetch(`/api/veiculos/${excluindoVeiculoId}`, {
            method: 'DELETE',
            headers: csrf_headers(),
            credentials: 'same-origin',
        });

        if (!resposta.ok) throw new Error('Falha ao remover.');

        fechar_modal('modalExcluir');
        excluindoVeiculoId = null;
        carregar_veiculos();
    } catch (erro) {
        alert('Não foi possível remover o veículo. Tente novamente.');
    } finally {
        btn.disabled = false;
    }
}

/* ═══════════════════════════════════════════════
   AGENDAMENTOS
═══════════════════════════════════════════════ */

async function carregar_agendamentos() {
    const container = document.getElementById('listaAgendamentos');

    try {
        const resposta = await fetch('/api/agendamentos', { credentials: 'same-origin' });
        if (!resposta.ok) throw new Error('Falha ao carregar agendamentos.');

        const agendamentos = await resposta.json();
        renderizar_agendamentos(agendamentos);
    } catch (erro) {
        container.innerHTML = `<p class="estado-vazio">Não foi possível carregar seus agendamentos.</p>`;
    }
}

function renderizar_agendamentos(agendamentos) {
    const container = document.getElementById('listaAgendamentos');

    if (!agendamentos.length) {
        container.innerHTML = `<p class="estado-vazio">Você ainda não tem agendamentos.</p>`;
        return;
    }

    container.innerHTML = agendamentos.map(function (a) {
        const status = STATUS_LABELS[a.status] ?? { texto: a.status, classe: '' };
        const data = formatar_data(a.data_preferida);

        return `
            <div class="agendamento-item">
                <div class="agendamento-top">
                    <span class="servico">${escape_html(a.servico)}</span>
                    <span class="status-badge ${status.classe}">${escape_html(status.texto)}</span>
                </div>
                <div class="meta">
                    ${escape_html(a.marca)} ${escape_html(a.modelo)}${a.placa ? ' · ' + escape_html(a.placa) : ''}
                </div>
                <div class="meta">
                    ${data}${a.turno ? ' · ' + (a.turno === 'manha' ? 'Manhã' : 'Tarde') : ''}
                </div>
            </div>
        `;
    }).join('');
}

function formatar_data(data_iso) {
    if (!data_iso) return '';
    const [ano, mes, dia] = data_iso.split('-');
    return `${dia}/${mes}/${ano}`;
}