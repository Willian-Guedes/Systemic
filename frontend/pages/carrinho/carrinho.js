/**
 * carrinho.js (página)
 *
 * Responsabilidades:
 *  1. Ler os itens do carrinho (via window.Carrinho, definido em /carrinho.js)
 *  2. Renderizar a lista de itens com controles de quantidade/remoção
 *  3. Calcular e exibir o resumo (itens e total)
 *  4. Exibir o pop-up cosmético de "Pedido concluído" (demonstração — sem
 *     pagamento ou integração real; ao fechar, o carrinho é esvaziado)
 */

function formatar_brl(valor) {
    return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function renderizar_item(item) {
    const div = document.createElement('div');
    div.className = 'cart-item';
    div.dataset.id = item.id;

    div.innerHTML = `
        <img
            src="${item.imagem || ''}"
            alt="${item.nome}"
            class="cart-item-img"
            onerror="this.src='https://placehold.co/120x120/1E2126/B0B3B8?text=Sem+Imagem'"
        >
        <div class="cart-item-info">
            <p class="cart-item-nome">${item.nome}</p>
            <p class="cart-item-preco-unit">${formatar_brl(item.preco)} / un.</p>
            <div class="cart-item-qtd">
                <button type="button" class="cart-qtd-btn" data-acao="diminuir" aria-label="Diminuir quantidade">−</button>
                <input type="number" class="cart-qtd-input" min="1" value="${item.quantidade}" aria-label="Quantidade">
                <button type="button" class="cart-qtd-btn" data-acao="aumentar" aria-label="Aumentar quantidade">+</button>
            </div>
        </div>
        <div class="cart-item-subtotal">${formatar_brl(item.preco * item.quantidade)}</div>
        <button type="button" class="cart-item-remover" aria-label="Remover ${item.nome}">
            <i class="fas fa-trash"></i>
        </button>
    `;

    return div;
}

function renderizar_carrinho() {
    const itens = Carrinho.listar();
    const container_vazio = document.getElementById('cart-vazio');
    const container_conteudo = document.getElementById('cart-conteudo');
    const container_itens = document.getElementById('cart-itens');

    if (itens.length === 0) {
        container_vazio.hidden = false;
        container_conteudo.hidden = true;
        return;
    }

    container_vazio.hidden = true;
    container_conteudo.hidden = false;

    container_itens.innerHTML = '';
    itens.forEach(item => container_itens.appendChild(renderizar_item(item)));

    const total = Carrinho.calcularTotal();
    document.getElementById('cart-total-itens').textContent = Carrinho.contarItens();
    document.getElementById('cart-total-preco').textContent = formatar_brl(total);
}

function inicializar_eventos_item(container) {
    container.addEventListener('click', evento => {
        const item_el = evento.target.closest('.cart-item');
        if (!item_el) return;

        const id = Number(item_el.dataset.id);

        if (evento.target.closest('.cart-item-remover')) {
            Carrinho.remover(id);
            Carrinho.mostrarToast('Item removido do carrinho.');
            return;
        }

        const botao_qtd = evento.target.closest('.cart-qtd-btn');
        if (botao_qtd) {
            const input = item_el.querySelector('.cart-qtd-input');
            const atual = parseInt(input.value, 10) || 1;
            const nova = botao_qtd.dataset.acao === 'aumentar' ? atual + 1 : atual - 1;
            Carrinho.atualizarQuantidade(id, nova);
        }
    });

    container.addEventListener('change', evento => {
        const input = evento.target.closest('.cart-qtd-input');
        if (!input) return;

        const item_el = evento.target.closest('.cart-item');
        const id = Number(item_el.dataset.id);
        Carrinho.atualizarQuantidade(id, input.value);
    });
}

// ── Modal cosmético: Pedido concluído ─────────────────────────────────────
// Demonstração apenas — não há integração de pagamento nem envio real.
// Ao fechar o modal, o carrinho é esvaziado (simula o pedido "concluído").

function preencher_modal_pedido(itens, total) {
    const container = document.getElementById('pedido-modal-itens');
    container.innerHTML = itens.map(item => `
        <div class="pedido-modal-item">
            <span>${item.nome}</span>
            <span class="pedido-modal-item-qtd">${item.quantidade}x ${formatar_brl(item.preco)}</span>
        </div>
    `).join('');

    document.getElementById('pedido-modal-total-preco').textContent = formatar_brl(total);
}

function abrir_modal_pedido() {
    const itens = Carrinho.listar();
    if (itens.length === 0) return;

    preencher_modal_pedido(itens, Carrinho.calcularTotal());
    document.getElementById('modal-pedido-concluido').hidden = false;
    document.body.style.overflow = 'hidden';
}

function fechar_modal_pedido() {
    document.getElementById('modal-pedido-concluido').hidden = true;
    document.body.style.overflow = '';
    Carrinho.limpar();
}

function inicializar_modal_pedido() {
    const overlay = document.getElementById('modal-pedido-concluido');

    document.getElementById('btn-finalizar').addEventListener('click', abrir_modal_pedido);
    document.getElementById('pedido-modal-fechar').addEventListener('click', fechar_modal_pedido);
    document.getElementById('pedido-modal-ok').addEventListener('click', fechar_modal_pedido);

    overlay.addEventListener('click', evento => {
        if (evento.target === overlay) fechar_modal_pedido();
    });

    document.addEventListener('keydown', evento => {
        if (evento.key === 'Escape' && !overlay.hidden) fechar_modal_pedido();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    renderizar_carrinho();
    inicializar_eventos_item(document.getElementById('cart-itens'));
    inicializar_modal_pedido();

    document.getElementById('btn-limpar-carrinho').addEventListener('click', () => {
        if (Carrinho.contarItens() === 0) return;
        Carrinho.limpar();
        Carrinho.mostrarToast('Carrinho esvaziado.');
    });

    window.addEventListener('carrinho:atualizado', renderizar_carrinho);
});