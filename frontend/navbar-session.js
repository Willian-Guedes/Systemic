/* ═══════════════════════════════════════════════
   NAVBAR SESSION — compartilhado entre todas as
   páginas públicas (servicos, produtos, busca…).
   Detecta cliente logado via /api/perfil e troca
   os botões Entrar/Cadastrar/Agendar pelo avatar
   com dropdown. Sem dependências externas.
═══════════════════════════════════════════════ */

(function () {
    'use strict';

    function primeiro_nome(nome_completo) {
        return (nome_completo || '').trim().split(' ')[0] || '';
    }

    function inicial(nome_completo) {
        const c = (nome_completo || '').trim().charAt(0).toUpperCase();
        return c || '?';
    }

    function url_placeholder(nome) {
        return `https://placehold.co/64x64/272727/ffffff?text=${encodeURIComponent(inicial(nome))}`;
    }

    function montar_dropdown(container_actions, perfil, csrf_token) {
        const nome = perfil.nome || '';
        const foto = perfil.foto_url || url_placeholder(nome);

        // Remove só os botões padrão (Entrar/Cadastrar/Agendar), preservando
        // qualquer elemento fixo da navbar-actions — como o ícone do carrinho —
        // para que ele continue aparecendo à esquerda do avatar.
        Array.from(container_actions.children).forEach(function (filho) {
            if (!filho.classList.contains('cart-link')) {
                filho.remove();
            }
        });

        const wrapper = document.createElement('div');
        wrapper.className = 'nav-user';

        wrapper.innerHTML = `
            <button class="nav-avatar-btn" aria-haspopup="true" aria-expanded="false" aria-label="Menu do usuário ${primeiro_nome(nome)}">
                <img class="nav-avatar-img" src="${foto}" alt="Foto de perfil de ${primeiro_nome(nome)}" width="32" height="32">
                <span class="nav-avatar-name">${primeiro_nome(nome)}</span>
                <svg class="nav-avatar-caret" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="nav-user-dropdown" role="menu">
                <a href="/painel" role="menuitem">Meu painel</a>
                <a href="/pedir" role="menuitem">Agendar serviço</a>
                <div class="dropdown-divider" role="separator"></div>
                <button id="navBtnLogout" class="dropdown-item-danger" role="menuitem">Sair</button>
            </div>
        `;

        container_actions.appendChild(wrapper);

        const btn      = wrapper.querySelector('.nav-avatar-btn');
        const dropdown = wrapper.querySelector('.nav-user-dropdown');

        btn.addEventListener('click', function () {
            const aberto = wrapper.classList.toggle('open');
            btn.setAttribute('aria-expanded', aberto);
        });

        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) {
                wrapper.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && wrapper.classList.contains('open')) {
                wrapper.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
                btn.focus();
            }
        });

        wrapper.querySelector('#navBtnLogout').addEventListener('click', async function () {
            try {
                await fetch('/auth/logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrf_token },
                });
            } finally {
                window.location.href = '/';
            }
        });
    }

    async function init() {
        const container = document.querySelector('.navbar-actions');
        if (!container) return;

        try {
            const res = await fetch('/api/perfil', { credentials: 'same-origin' });
            if (!res.ok) return;
            const perfil = await res.json();
            if (perfil && perfil.nome) {
                montar_dropdown(container, perfil, perfil.csrf_token || '');
            }
        } catch (_) {
            // Silencioso: sessão ausente ou erro de rede — botões padrão ficam como estão
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());