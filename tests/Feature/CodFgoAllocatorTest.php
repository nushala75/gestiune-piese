<?php

namespace Tests\Feature;

use App\Services\CodFgoAllocator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CodFgoAllocatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('secvente_cod_fgo', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('urmatorul_cod');
            $table->unsignedInteger('cod_minim');
            $table->unsignedInteger('cod_maxim');
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('secvente_cod_fgo');
        parent::tearDown();
    }

    public function test_allocates_unique_sequential_eight_digit_codes(): void
    {
        $this->seedSequence(1000000, 8999999);
        $allocator = app(CodFgoAllocator::class);

        $this->assertSame('01000000', $allocator->aloca());
        $this->assertSame('01000001', $allocator->aloca());
        $this->assertSame(1000002, DB::table('secvente_cod_fgo')->value('urmatorul_cod'));
    }

    public function test_allocates_last_code_then_marks_sequence_exhausted(): void
    {
        $this->seedSequence(8999999, 8999999);
        $allocator = app(CodFgoAllocator::class);

        $this->assertSame('08999999', $allocator->aloca());
        $this->assertSame(9000000, DB::table('secvente_cod_fgo')->value('urmatorul_cod'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Intervalul codurilor FGO este epuizat.');
        $allocator->aloca();
    }

    public function test_fails_when_sequence_row_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Secvența pentru codurile FGO nu există.');

        app(CodFgoAllocator::class)->aloca();
    }

    private function seedSequence(int $next, int $max): void
    {
        DB::table('secvente_cod_fgo')->insert([
            'id' => 1,
            'urmatorul_cod' => $next,
            'cod_minim' => 1000000,
            'cod_maxim' => $max,
        ]);
    }
}
