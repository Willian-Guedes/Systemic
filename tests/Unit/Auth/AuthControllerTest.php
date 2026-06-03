<?php
declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseMock;

class AuthControllerTest extends TestCase
{
    private DatabaseMock $db;

    protected function setUp(): void
    {
        $_POST    = [];
        $_SESSION = [];
        $this->db = DatabaseMock::setup();
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    protected function tearDown(): void
    {
        DatabaseMock::reset();
        $_POST    = [];
        $_SESSION = [];
        while (ob_get_level() > 0) ob_end_clean();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    private function runLogin(): void
    {
        ob_start();
        try { \AuthController::handle_login(); }
        catch (\Throwable $e) {}
        finally { ob_end_clean(); }
    }

    private function runLogout(): void
    {
        ob_start();
        try { \AuthController::handle_logout(); }
        catch (\Throwable $e) {}
        finally { ob_end_clean(); }
    }

    private function senhaHash(string $s): string
    {
        return password_hash($s, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private function funcionarioFake(string $senha, int $id = 1, string $nome = 'Admin', string $nivel = 'gerente'): array
    {
        return ['id_funcionario' => $id, 'nome_funcionario' => $nome, 'nivel_de_acesso' => $nivel, 'senha' => $this->senhaHash($senha)];
    }

    public function test_login_com_campos_vazios_registra_flash_error(): void
    {
        $_POST = ['email' => '', 'senha' => ''];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsString('Preencha', $_SESSION['flash_error']);
    }

    public function test_login_com_email_invalido_registra_flash_error(): void
    {
        $_POST = ['email' => 'isso-nao-e-email', 'senha' => 'qualquercoisa'];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsStringIgnoringCase('email', $_SESSION['flash_error']);
    }

    public function test_login_com_email_nao_cadastrado_registra_flash_error(): void
    {
        $this->db->willReturnOnQueryOne(null);
        $_POST = ['email' => 'naoexiste@automax.com', 'senha' => 'senha123'];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsStringIgnoringCase('incorretos', $_SESSION['flash_error']);
    }

    public function test_login_com_senha_errada_nao_cria_sessao(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('senhaCorreta'));
        $_POST = ['email' => 'admin@automax.com', 'senha' => 'senhaErrada'];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertArrayNotHasKey('funcionario_id', $_SESSION);
    }

    public function test_login_valido_preenche_sessao_corretamente(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('minhasenha123', id: 42, nome: 'Jonas Pereira', nivel: 'mecanico'));
        $_POST = ['email' => 'jonas@automax.com', 'senha' => 'minhasenha123'];
        $this->runLogin();
        $this->assertEquals(42,              $_SESSION['funcionario_id']   ?? null);
        $this->assertEquals('Jonas Pereira', $_SESSION['funcionario_nome'] ?? null);
        $this->assertEquals('mecanico',      $_SESSION['nivel_de_acesso']  ?? null);
    }

    public function test_login_valido_registra_timestamp_de_autenticacao(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('admin123'));
        $_POST = ['email' => 'admin@automax.com', 'senha' => 'admin123'];
        $antes = time();
        $this->runLogin();
        $depois = time();
        $this->assertArrayHasKey('autenticado_em', $_SESSION);
        $this->assertGreaterThanOrEqual($antes,  $_SESSION['autenticado_em']);
        $this->assertLessThanOrEqual($depois, $_SESSION['autenticado_em']);
    }

    public function test_login_valido_consulta_banco_com_email_correto(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('pass'));
        $_POST = ['email' => 'maria@automax.com', 'senha' => 'pass'];
        $this->runLogin();
        $chamada = $this->db->calls[0] ?? null;
        $this->assertNotNull($chamada);
        $this->assertEquals('query_one', $chamada['method']);
        $this->assertStringContainsString('funcionarios', $chamada['sql']);
        $this->assertEquals('maria@automax.com', $chamada['params'][':email']);
    }

    public function test_logout_limpa_sessao_completamente(): void
    {
        $_SESSION = ['funcionario_id' => 7, 'funcionario_nome' => 'Alguem', 'nivel_de_acesso' => 'gerente', 'autenticado_em' => time()];
        $this->runLogout();
        $this->assertEmpty($_SESSION);
    }

    public function test_logout_sem_sessao_ativa_nao_lanca_excecao(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        $this->expectNotToPerformAssertions();
        $this->runLogout();
    }
}
