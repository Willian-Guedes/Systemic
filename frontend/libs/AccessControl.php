<?php

declare(strict_types=1);

/*
 * Define o que cada nível de acesso pode fazer no sistema.
 *
 * Convenção de nomes: modulo.acao
 * Para adicionar um nível: nova chave em PERMISSIONS.
 * Para adicionar uma permissão: nova string nos níveis que devem tê-la.
 *
 * Módulos ativos: ordem_servico · clientes · estoque
 */
class AccessControl
{
    private const PERMISSIONS = [
        'gerente' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',
            'ordem_servico.editar',
            'ordem_servico.fechar',
            'ordem_servico.excluir',

            'clientes.visualizar',
            'clientes.cadastrar',
            'clientes.editar',

            'estoque.visualizar',
            'estoque.editar',
        ],

        // Recepção: frente de atendimento — abre OS e gerencia clientes
        'recepcao' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',

            'clientes.visualizar',
            'clientes.cadastrar',
            'clientes.editar',
        ],

        // Mecânico: execução técnica completa, sem excluir OS e sem ver clientes
        // estoque.editar cobre ajuste direto de quantidade e baixa via OS
        'mecanico' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',
            'ordem_servico.editar',
            'ordem_servico.fechar',

            'estoque.visualizar',
            'estoque.editar',
        ],
    ];

    /*
     * Encerra a requisição com 403 se o usuário não tiver a permissão.
     * Garante que haja sessão ativa antes de verificar o nível — sem isso,
     * um usuário não autenticado receberia 403 em vez de ser redirecionado ao login.
     * Sempre chamado antes de servir uma página ou executar uma ação sensível.
     */
    public static function exigir_permissao(string $permissao): void
    {
        AuthController::exigir_autenticacao();

        $nivel = $_SESSION['nivel_de_acesso'] ?? '';

        if (!self::nivel_tem_permissao($nivel, $permissao)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            include __DIR__ . '/../pages/errors/403.html';
            exit;
        }
    }

    /*
     * Retorna true/false sem encerrar a requisição.
     * Útil para decisões condicionais dentro de uma rota já autenticada.
     */
    public static function nivel_tem_permissao(string $nivel, string $permissao): bool
    {
        $permissoes_do_nivel = self::PERMISSIONS[$nivel] ?? [];
        return in_array($permissao, $permissoes_do_nivel, strict: true);
    }

    /*
     * Retorna todas as permissões do nível como array.
     * Injetado pelo index.php em window.__session_user.permissoes para o JS.
     */
    public static function permissoes_do_nivel(string $nivel): array
    {
        return self::PERMISSIONS[$nivel] ?? [];
    }
}