<?php

declare(strict_types=1);

namespace Automax\Auth;

use Automax\Controllers\AuthController;

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

            'funcionarios.visualizar',
            'funcionarios.gerenciar',

            'logs.visualizar',
        ],

        'recepcao' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',

            'clientes.visualizar',
            'clientes.cadastrar',
            'clientes.editar',
        ],

        'mecanico' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',
            'ordem_servico.editar',
            'ordem_servico.fechar',

            'estoque.visualizar',
            'estoque.editar',
        ],

        'cliente' => [
            'veiculos.visualizar',
            'veiculos.cadastrar',
            'veiculos.editar',
            'veiculos.excluir',

            'agendamentos.visualizar',
            'agendamentos.criar',
        ],
    ];

    public static function exigir_permissao(string $permissao): void
    {
        AuthController::exigir_autenticacao();

        $nivel = $_SESSION['nivel_de_acesso'] ?? '';

        if (!self::nivel_tem_permissao($nivel, $permissao)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            include __DIR__ . '/../../pages/errors/403.html';
            exit;
        }
    }

    public static function exigir_cliente(): void
    {
        AuthController::exigir_autenticacao();

        if (($_SESSION['tipo_usuario'] ?? '') !== 'cliente') {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            include __DIR__ . '/../../pages/errors/403.html';
            exit;
        }
    }

    public static function nivel_tem_permissao(string $nivel, string $permissao): bool
    {
        $permissoes_do_nivel = self::PERMISSIONS[$nivel] ?? [];
        return in_array($permissao, $permissoes_do_nivel, strict: true);
    }

    public static function permissoes_do_nivel(string $nivel): array
    {
        return self::PERMISSIONS[$nivel] ?? [];
    }
}