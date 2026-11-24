<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login', [
            'captcha' => $this->captchaEnabled() && (session('captcha') || $this->failuresFor('login-fail-ip:'.request()->ip()) >= $this->captchaAfter()),
            'captchaSiteKey' => config('cerebro.turnstile.site_key'),
        ]);
    }

    private function captchaEnabled(): bool
    {
        return (bool) config('cerebro.turnstile.enabled') && config('cerebro.turnstile.secret_key');
    }

    private function captchaAfter(): int
    {
        return (int) config('cerebro.turnstile.after_failures');
    }

    private function failuresFor(string $key): int
    {
        return RateLimiter::attempts($key);
    }

    private function captchaValid(Request $request): bool
    {
        $token = $request->input('cf-turnstile-response');
        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $res = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('cerebro.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable) {
            return false;
        }

        return $res->ok() && $res->json('success') === true;
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower($credentials['email']);
        $ipKey = 'login:'.$email.'|'.$request->ip();
        // Só por e-mail: protege a conta mesmo se o atacante trocar de IP a cada tentativa.
        $emailKey = 'login-email:'.$email;
        $limits = config('cerebro.login');

        foreach ([[$ipKey, $limits['per_ip_attempts']], [$emailKey, $limits['per_email_attempts']]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Audit::log('login_bloqueado', null, ['email' => $email]);
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'email' => 'Muitas tentativas. Tente de novo em '.max($minutes, 1).' minuto(s).',
                ]);
            }
        }

        $captchaKey = 'login-fail-ip:'.$request->ip();
        if ($this->captchaEnabled()
            && ($this->failuresFor($captchaKey) >= $this->captchaAfter() || $this->failuresFor($emailKey) >= $this->captchaAfter())
            && ! $this->captchaValid($request)) {
            Audit::log('login_captcha_falhou', null, ['email' => $email]);
            $request->session()->flash('captcha', true);

            throw ValidationException::withMessages(['email' => 'Confirme que você não é um robô.']);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($emailKey, $limits['per_email_lock_seconds']);
            RateLimiter::hit($captchaKey, $limits['per_email_lock_seconds']);
            $request->session()->flash('captcha', true);
            Audit::log('login_falhou', null, ['email' => $email]);

            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($emailKey);
        RateLimiter::clear($captchaKey);
        Audit::log('login', Auth::user()->tenant_id);
        $request->session()->regenerate();

        return redirect()->intended(
            Auth::user()->isAdmin() ? route('admin.tenants.index') : route('brain.index')
        );
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
