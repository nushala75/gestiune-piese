<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Auth::guard('web')->logout();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function test_guest_is_redirected_to_one_time_admin_setup_when_no_user_exists(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/configurare-administrator');
        $this->get('/configurare-administrator')
            ->assertOk()
            ->assertSee('nusescu@gmail.com');
    }

    public function test_admin_can_be_created_once_then_login_and_logout_are_enforced(): void
    {
        $this->post('/configurare-administrator', [
            'password' => 'ParolaSigura!2026',
            'password_confirmation' => 'ParolaSigura!2026',
        ])->assertRedirect('/');

        $this->assertDatabaseHas('users', [
            'email' => 'nusescu@gmail.com',
            'name' => 'Administrator',
        ]);
        $this->assertTrue(Hash::check('ParolaSigura!2026', (string) User::query()->value('password')));
        $this->get('/')->assertOk();

        $this->post('/logout')->assertRedirect('/login');
        $this->get('/configurare-administrator')->assertNotFound();
        $this->get('/')->assertRedirect('/login');

        $this->post('/login', [
            'email' => 'nusescu@gmail.com',
            'password' => 'ParolaSigura!2026',
        ])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_invalid_password_is_rejected(): void
    {
        User::query()->create([
            'name' => 'Administrator',
            'email' => 'nusescu@gmail.com',
            'password' => Hash::make('ParolaSigura!2026'),
        ]);

        $this->post('/login', [
            'email' => 'nusescu@gmail.com',
            'password' => 'gresita',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
