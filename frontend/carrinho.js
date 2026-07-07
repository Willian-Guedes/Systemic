/* ═══════════════════════════════════════════════
   CARRINHO — compartilhado entre todas as páginas
   públicas que exibem produtos (produto, produtos,
   carrinho...). Persiste em localStorage, client-side.
   Sem dependências externas.
═══════════════════════════════════════════════ */

(function () {
    'use strict';

    const CHAVE_STORAGE = 'automax_carrinho';

    // ── Persistência ────────────────────────────────────────────────────────

    function ler_carrinho() {
        try {
            const bruto = localStorage.getItem(CHAVE_STORAGE);
            const itens = bruto ? JSON.parse(bruto) : [];
            return Array.isArray(itens) ? itens : [];
        } catch (_) {
            return [];
        }
    }

    function salvar_carrinho(itens) {
        localStorage.setItem(CHAVE_STORAGE, JSON.stringify(itens));
        atualizar_badge();
        window.dispatchEvent(new CustomEvent('carrinho:atualizado', { detail: { itens } }));
    }

    // ── Operações ───────────────────────────────────────────────────────────

    function adicionar(produto, quantidade) {
        quantidade = Math.max(1, parseInt(quantidade, 10) || 1);

        const itens = ler_carrinho();
        const existente = itens.find(item => item.id === produto.id);

        if (existente) {
            existente.quantidade += quantidade;
        } else {
            itens.push({
                id: produto.id,
                nome: produto.nome,
                preco: Number(produto.preco),
                imagem: produto.imagem || '',
                quantidade,
            });
        }

        salvar_carrinho(itens);
        return itens;
    }

    function remover(id_produto) {
        const itens = ler_carrinho().filter(item => item.id !== id_produto);
        salvar_carrinho(itens);
        return itens;
    }

    function atualizar_quantidade(id_produto, quantidade) {
        const itens = ler_carrinho();
        const item = itens.find(i => i.id === id_produto);

        if (!item) return itens;

        quantidade = parseInt(quantidade, 10) || 0;

        if (quantidade <= 0) {
            return remover(id_produto);
        }

        item.quantidade = quantidade;
        salvar_carrinho(itens);
        return itens;
    }

    function limpar() {
        salvar_carrinho([]);
    }

    function contar_itens() {
        return ler_carrinho().reduce((soma, item) => soma + item.quantidade, 0);
    }

    function calcular_total() {
        return ler_carrinho().reduce((soma, item) => soma + item.preco * item.quantidade, 0);
    }

    // ── Badge na navbar ─────────────────────────────────────────────────────

    function atualizar_badge() {
        const badge = document.querySelector('.cart-badge');
        if (!badge) return;

        const total = contar_itens();
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.hidden = total === 0;
    }

    // ── Toast de feedback ───────────────────────────────────────────────────

    function garantir_container_toast() {
        let container = document.getElementById('carrinho-toast-container');
        if (container) return container;

        container = document.createElement('div');
        container.id = 'carrinho-toast-container';
        container.setAttribute('aria-live', 'polite');
        container.style.cssText = [
            'position:fixed', 'bottom:1.25rem', 'right:1.25rem', 'z-index:2000',
            'display:flex', 'flex-direction:column', 'gap:0.6rem', 'align-items:flex-end',
        ].join(';');
        document.body.appendChild(container);
        return container;
    }

    function mostrar_toast(mensagem, tipo) {
        const container = garantir_container_toast();

        const toast = document.createElement('div');
        toast.textContent = mensagem;
        toast.style.cssText = [
            'font-family: var(--font-body, sans-serif)',
            'font-size:0.9rem',
            'color:#fff',
            `background:${tipo === 'erro' ? '#ac2a2a' : '#1E2126'}`,
            `border:1px solid ${tipo === 'erro' ? '#D32F2F' : 'rgba(255,255,255,0.25)'}`,
            'padding:0.7rem 1.1rem',
            'border-radius:8px',
            'box-shadow:0 8px 32px rgba(0,0,0,0.55)',
            'opacity:0',
            'transform:translateY(8px)',
            'transition:opacity 0.25s ease, transform 0.25s ease',
        ].join(';');

        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(8px)';
            setTimeout(() => toast.remove(), 250);
        }, 2600);
    }

    // ── Verificação de cliente logado ───────────────────────────────────────

    async function esta_logado_como_cliente() {
        try {
            const resposta = await fetch('/api/perfil', { credentials: 'same-origin' });
            return resposta.ok;
        } catch (_) {
            return false;
        }
    }

    // ── API pública ─────────────────────────────────────────────────────────

    window.Carrinho = {
        listar: ler_carrinho,
        adicionar,
        remover,
        atualizarQuantidade: atualizar_quantidade,
        limpar,
        contarItens: contar_itens,
        calcularTotal: calcular_total,
        mostrarToast: mostrar_toast,
        estaLogadoComoCliente: esta_logado_como_cliente,
    };

    document.addEventListener('DOMContentLoaded', atualizar_badge);
}());