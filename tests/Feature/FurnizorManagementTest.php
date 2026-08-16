<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FurnizorManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('furnizori', function (Blueprint $table): void {
            $table->id();
            $table->string('denumire', 190);
            $table->string('cod_fiscal', 32)->unique();
            $table->char('tara', 2);
            $table->string('adresa', 500)->nullable();
            $table->char('moneda_implicita', 3);
            $table->json('configuratie_parser')->nullable();
            $table->boolean('activ')->default(true);
            $table->timestamps();
        });
        Schema::create('produse_furnizori', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('produs_id');
            $table->unsignedBigInteger('furnizor_id');
        });
        Schema::create('facturi_furnizor', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('furnizor_id');
        });

        DB::table('furnizori')->insert([
            ['denumire' => 'MOTO TREND S.A', 'cod_fiscal' => 'EL094496688', 'tara' => 'GR', 'adresa' => null, 'moneda_implicita' => 'EUR', 'activ' => true],
            ['denumire' => 'Scootercraft S.O.O', 'cod_fiscal' => 'PL6793242148', 'tara' => 'PL', 'adresa' => null, 'moneda_implicita' => 'EUR', 'activ' => true],
            ['denumire' => 'RACING PLANET Vertrieb GmbH', 'cod_fiscal' => 'DE297237364', 'tara' => 'DE', 'adresa' => null, 'moneda_implicita' => 'EUR', 'activ' => true],
            ['denumire' => 'MICHALIS GEORGIOU MOTOSPEED LTD', 'cod_fiscal' => '10089694', 'tara' => 'CY', 'adresa' => 'Paralimniou 54, Sotira Paralimni Road, 5390 Cipru', 'moneda_implicita' => 'EUR', 'activ' => true],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('facturi_furnizor');
        Schema::dropIfExists('produse_furnizori');
        Schema::dropIfExists('furnizori');

        parent::tearDown();
    }

    public function test_supplier_menu_lists_the_four_known_suppliers(): void
    {
        $this->get('/furnizori')
            ->assertOk()
            ->assertSee('MOTO TREND S.A')
            ->assertSee('Scootercraft S.O.O')
            ->assertSee('RACING PLANET Vertrieb GmbH')
            ->assertSee('MICHALIS GEORGIOU MOTOSPEED LTD')
            ->assertSee('Paralimniou 54, Sotira Paralimni Road, 5390 Cipru');
    }

    public function test_supplier_can_be_added_and_edited(): void
    {
        $this->post('/furnizori', [
            'denumire' => 'Furnizor test',
            'cod_fiscal' => 'ro123456',
            'tara' => 'ro',
            'adresa' => 'Adresă test',
            'moneda_implicita' => 'ron',
            'activ' => '1',
        ])->assertRedirect('/furnizori');

        $furnizorId = DB::table('furnizori')->where('cod_fiscal', 'RO123456')->value('id');
        $this->assertNotNull($furnizorId);

        $this->patch("/furnizori/{$furnizorId}", [
            'denumire' => 'Furnizor test actualizat',
            'cod_fiscal' => 'ro123456',
            'tara' => 'ro',
            'adresa' => 'Adresă nouă',
            'moneda_implicita' => 'eur',
            'activ' => '0',
        ])->assertRedirect('/furnizori');

        $this->assertDatabaseHas('furnizori', [
            'id' => $furnizorId,
            'denumire' => 'Furnizor test actualizat',
            'cod_fiscal' => 'RO123456',
            'tara' => 'RO',
            'adresa' => 'Adresă nouă',
            'moneda_implicita' => 'EUR',
            'activ' => false,
        ]);
    }

    public function test_supplier_vat_must_be_unique(): void
    {
        $this->post('/furnizori', [
            'denumire' => 'Duplicat',
            'cod_fiscal' => 'EL094496688',
            'tara' => 'GR',
            'moneda_implicita' => 'EUR',
            'activ' => '1',
        ])->assertSessionHasErrors('cod_fiscal');
    }
}
