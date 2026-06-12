<?php

declare(strict_types=1);

namespace Tests\Unit\Cadastro;

use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseMock;
use Automax\Controllers\CadastroController;

class CadastroControllerTest extends TestCase
{
    private DatabaseMock $db;
    private int $ob_level_before;

    protected function setUp(): void
    {
        $this->ob_level_before = ob_get_level();
        $this->db = DatabaseMock::setup();
    }

    protected function tearDown(): void
    {
        DatabaseMock::reset();
        unset($GLOBALS['_test_input']);
        while (ob_get_level() > $this->ob_level_before) ob_end_clean();
    }

    private function run_criar(array $body): string
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $GLOBALS['_test_input'] = json_encode($body);

        ob_start();
        try {
            CadastroController::handle_criar();
        } catch (\Throwable) {}
        return ob_get_clean() ?: '';
    }

    private function payload_valido(): array
    {
        return [
            'cliente' => [
                'nome_cliente' => 'Maria Silva',
                'cpf'          => '529.982.247-25',
                'celular'      => '(47) 99999-1234',
                'email'        => 'maria@exemplo.com',
                'senha'        => 'senhaSegura123',
            ],
            'veiculo' => [
                'marca'  => 'Toyota',
                'modelo' => 'Corolla',
                'ano'    => '2020',
                'cor'    => 'Prata',
                'placa'  => 'ABC-1234',
            ],
        ];
    }

    // --- Validação de campos do cliente ---

    public function test_nome_muito_curto_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['cliente']['nome_cliente'] = 'Jo';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('nome', implode(' ', $json['fields'] ?? []));
    }

    public function test_cpf_invalido_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['cliente']['cpf'] = '111.111.111-11';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('CPF', implode(' ', $json['fields'] ?? []));
    }

    public function test_celular_com_menos_de_10_digitos_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['cliente']['celular'] = '9999';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('celular', implode(' ', $json['fields'] ?? []));
    }

    public function test_email_invalido_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['cliente']['email'] = 'nao-e-email';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('mail', implode(' ', $json['fields'] ?? []));
    }

    public function test_senha_curta_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['cliente']['senha'] = '123';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('senha', implode(' ', $json['fields'] ?? []));
    }

    // --- Validação de campos do veículo ---

    public function test_marca_muito_curta_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['veiculo']['marca'] = 'A';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('marca', implode(' ', $json['fields'] ?? []));
    }

    public function test_ano_com_formato_invalido_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['veiculo']['ano'] = '99';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('ano', implode(' ', $json['fields'] ?? []));
    }

    public function test_placa_formato_invalido_retorna_422(): void
    {
        $payload = $this->payload_valido();
        $payload['veiculo']['placa'] = 'ZZZ9999X';

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('placa', implode(' ', $json['fields'] ?? []));
    }

    public function test_placa_mercosul_valida_passa_na_validacao(): void
    {
        // Mercosul: ABC1D23 — sem CPF duplicado no mock, retorna 201
        $payload = $this->payload_valido();
        $payload['veiculo']['placa'] = 'ABC1D23';

        // Mock: CPF livre, email livre, placa livre, inserts OK
        $this->db
            ->willReturnOnQueryOne(null) // cpf não existe
            ->willReturnOnQueryOne(null) // email não existe
            ->willReturnOnQueryOne(null) // placa não existe
            ->willReturnOnExecute(1)     // INSERT clientes
            ->willReturnLastInsertId('5')
            ->willReturnOnExecute(1);    // INSERT veiculos

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertArrayHasKey('id_cliente', $json);
    }

    // --- Conflitos no banco ---

    public function test_cpf_duplicado_retorna_409(): void
    {
        $payload = $this->payload_valido();

        $this->db->willReturnOnQueryOne(['CPF' => '52998224725']); // CPF já existe

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('CPF', $json['message'] ?? '');
    }

    public function test_email_duplicado_retorna_409(): void
    {
        $payload = $this->payload_valido();

        $this->db
            ->willReturnOnQueryOne(null)                           // CPF livre
            ->willReturnOnQueryOne(['email' => 'maria@exemplo.com']); // email duplicado

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('e-mail', $json['message'] ?? '');
    }

    public function test_placa_duplicada_retorna_409(): void
    {
        $payload = $this->payload_valido();

        $this->db
            ->willReturnOnQueryOne(null)                       // CPF livre
            ->willReturnOnQueryOne(null)                       // email livre
            ->willReturnOnQueryOne(['placa' => 'ABC1234']);    // placa duplicada

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertStringContainsStringIgnoringCase('placa', $json['message'] ?? '');
    }

    // --- Fluxo de sucesso ---

    public function test_cadastro_valido_insere_cliente_e_veiculo_no_banco(): void
    {
        $payload = $this->payload_valido();

        $this->db
            ->willReturnOnQueryOne(null) // CPF livre
            ->willReturnOnQueryOne(null) // email livre
            ->willReturnOnQueryOne(null) // placa livre
            ->willReturnOnExecute(1)     // INSERT clientes
            ->willReturnLastInsertId('42')
            ->willReturnOnExecute(1);    // INSERT veiculos

        $this->run_criar($payload);

        $executes = array_filter($this->db->calls, fn($c) => $c['method'] === 'execute');
        $sqls = array_column(array_values($executes), 'sql');

        $this->assertCount(2, $executes);
        $this->assertStringContainsString('clientes', $sqls[0]);
        $this->assertStringContainsString('veiculos', $sqls[1]);
    }

    public function test_cadastro_valido_retorna_id_do_cliente_criado(): void
    {
        $payload = $this->payload_valido();

        $this->db
            ->willReturnOnQueryOne(null)
            ->willReturnOnQueryOne(null)
            ->willReturnOnQueryOne(null)
            ->willReturnOnExecute(1)
            ->willReturnLastInsertId('99')
            ->willReturnOnExecute(1);

        $resposta = $this->run_criar($payload);
        $json = json_decode($resposta, true);

        $this->assertNotNull($json);
        $this->assertEquals(99, $json['id_cliente']);
    }

    public function test_cpf_e_normalizado_antes_de_salvar_no_banco(): void
    {
        $payload = $this->payload_valido();
        $payload['cliente']['cpf'] = '529.982.247-25'; // com máscara

        $this->db
            ->willReturnOnQueryOne(null)
            ->willReturnOnQueryOne(null)
            ->willReturnOnQueryOne(null)
            ->willReturnOnExecute(1)
            ->willReturnLastInsertId('1')
            ->willReturnOnExecute(1);

        $this->run_criar($payload);

        $insert_cliente = array_values(array_filter(
            $this->db->calls,
            fn($c) => $c['method'] === 'execute' && str_contains($c['sql'], 'clientes')
        ))[0] ?? null;

        $this->assertNotNull($insert_cliente);
        $this->assertEquals('52998224725', $insert_cliente['params'][':cpf']);
    }

    public function test_placa_e_normalizada_para_maiusculas_sem_hifen(): void
    {
        $payload = $this->payload_valido();
        $payload['veiculo']['placa'] = 'abc-1234';

        $this->db
            ->willReturnOnQueryOne(null)
            ->willReturnOnQueryOne(null)
            ->willReturnOnQueryOne(null)
            ->willReturnOnExecute(1)
            ->willReturnLastInsertId('1')
            ->willReturnOnExecute(1);

        $this->run_criar($payload);

        $insert_veiculo = array_values(array_filter(
            $this->db->calls,
            fn($c) => $c['method'] === 'execute' && str_contains($c['sql'], 'veiculos')
        ))[0] ?? null;

        $this->assertNotNull($insert_veiculo);
        $this->assertEquals('ABC1234', $insert_veiculo['params'][':placa']);
    }
}