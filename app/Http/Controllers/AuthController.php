<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use App\Notifications\Channels\BrevoApiException;

class AuthController extends Controller
{
    protected \App\Services\CentralAuthService $centralAuth;

    public function __construct(\App\Services\CentralAuthService $centralAuth)
    {
        $this->centralAuth = $centralAuth;
    }

    private const TWO_FACTOR_VALID_MINUTES = 10;

    public function create(): View { return view('login'); }

    public function register(): View { return view('register'); }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $client = $this->centralAuth->getClient();

        // 1. Verify that the Centralized Backend is reachable
        if (!$client->isAvailable()) {
            return back()->withErrors([
                'email' => 'The Centralized Backend server (' . $client->getBaseUrl() . ') is currently offline or unreachable. Please verify your connection.'
            ])->onlyInput('email');
        }

        // 2. Authenticate with Centralized Backend
        $centralLogin = $this->centralAuth->login($credentials['email'], $credentials['password']);

        if ($centralLogin) {
            $centralUser = $centralLogin['user'] ?? [];
            $globalRole = strtolower($centralUser['global_role'] ?? 'user');
            $role = in_array($globalRole, ['superadmin', 'admin', 'manager'], true) ? 'Admin' : ($globalRole === 'driver' ? 'Driver' : 'User');
            if (strtolower($credentials['email']) === 'fleet@archonnell.com') {
                $role = 'User';
            }

            $user = null;
            try {
                $user = User::updateOrCreate(
                    ['email' => strtolower($credentials['email'])],
                    [
                        'name' => $centralUser['full_name'] ?? 'Archon User',
                        User::passwordColumnName() => \Illuminate\Support\Facades\Hash::make($credentials['password']),
                        'role' => $role,
                        'status' => 'active',
                    ]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Local SQLite write failed: ' . $e->getMessage());
                $user = User::where('email', strtolower($credentials['email']))->first();
                if (!$user) {
                    $user = new User([
                        'email' => strtolower($credentials['email']),
                        'name' => $centralUser['full_name'] ?? 'Archon User',
                        'role' => $role,
                        'status' => 'active',
                    ]);
                    $user->id = 11;
                    $user->exists = true;
                }
            }

            try {
                Auth::login($user);
                $request->session()->regenerate();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Auth login / session error: ' . $e->getMessage());
            }

            try {
                AuditLog::record($request, 'Signed in', 'Authentication', 'Centralized backend authenticated sign in.');
            } catch (\Throwable) {
                // Ignore audit log error in serverless read-only mode
            }

            return redirect()->to($this->dashboardPath($user));
        }

        if ($client->isUnavailable()) {
            return back()->withErrors([
                'email' => 'Centralized backend connection failed or timed out: ' . ($client->getLastError() ?? 'Server unreachable.')
            ])->onlyInput('email');
        }

        AuditLog::record($request, 'Sign in failed', 'Authentication', 'Failed sign in attempt for '.strtolower($credentials['email']).'.');
        return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
    }

    public function showTwoFactorChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('two_factor.user_id')) {
            return redirect()->route('login');
        }

        return view('two-factor-challenge');
    }

    public function verifyTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);

        $userId = $request->session()->get('two_factor.user_id');
        $user = $userId ? User::find($userId) : null;

        if (! $user || $user->status !== 'active') {
            $request->session()->forget('two_factor');
            return redirect()->route('login');
        }

        $codeValid = $user->two_factor_code !== null
            && hash_equals($user->two_factor_code, $request->input('code'))
            && $user->two_factor_expires_at?->isFuture();

        if (! $codeValid) {
            return back()->withErrors(['code' => 'That code is invalid or has expired.']);
        }

        $user->two_factor_code = null;
        $user->two_factor_expires_at = null;
        $user->save();

        $request->session()->forget('two_factor');
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record($request, 'Signed in', 'Authentication', 'Completed two-factor authentication and signed in.');

        return redirect()->to($this->dashboardPath($user));
    }

    public function resendTwoFactor(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('two_factor.user_id');
        $user = $userId ? User::find($userId) : null;

        if (! $user || $user->status !== 'active') {
            $request->session()->forget('two_factor');
            return redirect()->route('login');
        }

        if ($user->two_factor_expires_at?->copy()->subMinutes(self::TWO_FACTOR_VALID_MINUTES - 1)->isFuture()) {
            return back()->withErrors(['code' => 'Please wait a moment before requesting a new code.']);
        }

        if (app()->environment('local') && empty(env('BREVO_API_KEY'))) {
            Auth::login($user);
            $request->session()->regenerate();
            AuditLog::record($request, 'Signed in', 'Authentication', 'Direct local development sign in.');
            return redirect()->to($this->dashboardPath($user));
        }

        if (! $this->issueTwoFactorCode($user)) {
            return back()->withErrors(['code' => 'We could not send a verification code. Please try again.']);
        }

        return back()->with('status', 'A new verification code has been sent to your email.');
    }

    private function issueTwoFactorCode(User $user): bool
    {
        $code = (string) random_int(100000, 999999);

        $user->two_factor_code = $code;
        $user->two_factor_expires_at = Carbon::now()->addMinutes(self::TWO_FACTOR_VALID_MINUTES);
        $user->save();

        try {
            $user->notify(new TwoFactorCodeNotification($code, self::TWO_FACTOR_VALID_MINUTES));
        } catch (BrevoApiException $exception) {
            $user->two_factor_code = null;
            $user->two_factor_expires_at = null;
            $user->save();

            report($exception);

            return false;
        }

        return true;
    }

    public function showForgotPassword(): View
    {
        return view('forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If that email is registered, a password reset link has been sent.');
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->setPassword($password);
                $user->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password has been reset. You can now sign in.')
            : back()->withErrors(['email' => __($status)]);
    }

    public function storeRegistration(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:100', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = new User([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => 'User',
            'status' => 'active',
        ]);
        $user->setPassword($data['password']);
        $user->save();

        Alert::log('🆕', 'New User Registered', "{$user->name} ({$user->email}) just signed up.");
        AuditLog::record($request, 'Account registered', 'Authentication', "Registered a new account for {$user->name}.");

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/users/dashboard');
    }

    private function dashboardPath(User $user): string
    {
        return $user->role === 'Admin' ? '/dashboard' : '/users/dashboard';
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->centralAuth->logout();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
