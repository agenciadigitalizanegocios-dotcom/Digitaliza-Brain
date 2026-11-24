@extends('layouts.app')

@section('title', 'Entrar')
@section('main-class', 'login-wrap')

@section('content')
    <form method="post" action="{{ url('/login') }}" class="card login-card">
        @csrf
        <div class="login-head">
            <span class="brand-mark big">🧠</span>
            <h1>Entrar no Digitaliza Brain</h1>
            <p class="muted">Configure como o robô do seu WhatsApp atende.</p>
        </div>

        <label class="field">
            <span class="label">E-mail</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label class="field">
            <span class="label">Senha</span>
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        @error('email') <p class="field-error">{{ $message }}</p> @enderror

        <label class="check">
            <input type="checkbox" name="remember" value="1"> Continuar conectado
        </label>

        @if ($captcha)
            <div class="cf-turnstile" data-sitekey="{{ $captchaSiteKey }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <button class="btn primary block">Entrar</button>
    </form>
@endsection
