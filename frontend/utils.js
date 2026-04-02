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