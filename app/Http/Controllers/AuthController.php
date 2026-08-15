<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const ADMIN_EMAIL = 'nusescu@gmail.com';

    public function showLogin(): View|RedirectResponse
    {
        if (! Schema::hasTable('users') || ! User::query()->exists()) {
            return redirect()->route('admin.setup');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (! Schema::hasTable('users') || ! User::query()->exists()) {
            return redirect()->route('admin.setup');
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            'email' => mb_strtolower(trim($credentials['email'])),
            'password' => $credentials['password'],
        ])) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Adresa de e-mail sau parola este incorectă.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showSetup(): View
    {
        if (Schema::hasTable('users') && User::query()->exists()) {
            abort(404);
        }

        return view('auth.setup', [
            'adminEmail' => self::ADMIN_EMAIL,
            'schemaDisponibila' => Schema::hasTable('users'),
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        abort_unless(Schema::hasTable('users'), 503, 'Aplică mai întâi migrarea 009_autentificare_admin.');

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)],
        ], [
            'password.confirmed' => 'Confirmarea parolei nu corespunde.',
            'password.min' => 'Parola trebuie să aibă cel puțin 12 caractere.',
        ]);

        $admin = DB::transaction(function () use ($data): User {
            if (User::query()->lockForUpdate()->exists()) {
                abort(404);
            }

            return User::query()->create([
                'name' => 'Administrator',
                'email' => self::ADMIN_EMAIL,
                'password' => Hash::make($data['password']),
            ]);
        });

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('status', 'Administratorul a fost configurat. Autentificarea este acum activă.');
    }
}
