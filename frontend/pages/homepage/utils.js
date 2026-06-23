const nomeProduto = "cadeira legal";
const produtoAtivo = true; 
let quantidadeEstoque;

function saudarCliente(Nome) {
    return `Olá, ${Nome}! Bem-vindo à nossa loja.`;
}

function formatarMoedaBRL(valor){
    return valor.toFixed(2)
}

function calcularDesconto(precoOriginal, isFuncionario){
    if (isFuncionario){
        const desconto = precoOriginal * 0.30;
        return precoOriginal - desconto;
    }else{
        return precoOriginal
    }
}

const pneu = {
    id: 1,
    nome: "Pneu 205/55 R16 Goodyear",
    preco: 389.00,
    categorias: ["Pneus", "Rodas", "Segurança"]
};

const validarSenha = (senha) =>
    senha.length >= 8 && 
    senha !== "12345678" && 
    senha !== "senha";

function fecharCarrinho(valorProduto, quantidade, valorFrete, cupom = null, cliente = {}) {
    let totalProdutos = valorProduto * quantidade;

    if (totalProdutos > 200) {
        valorFrete = 0;
    }

    let total = totalProdutos + valorFrete;

    if (cupom) {
        if (cupom.tipo === "porcentagem") {
            total -= total * (cupom.valor / 100);
        } else if (cupom.tipo === "fixo") {
            total -= cupom.valor;
        }
    }

    if (cliente.vip) {
        total *= 0.9;
    }

    if (total < 0) total = 0;

    return total;
}

/* A lógica de sessão da navbar vive em /navbar-session.js,
   compartilhado com todas as páginas públicas.. */