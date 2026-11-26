@extends('layouts.app')

@section('title', 'Vitrine')
@section('main-class', 'shell')

@section('content')
<div class="layout">
    @include('brain.partials.nav', ['nav' => \App\Brain\Navigation::build(), 'active' => 'vitrine'])

    <section class="content">
        <div class="section-head">
            <div>
                <h1><span class="h-icon">🎨</span> Vitrine</h1>
                <p class="muted">Como a vitrine aparece para os clientes: logo, cor e contatos do rodapé.</p>
                <p class="small">Endereço: <a href="{{ $tenant->vitrineUrl() }}" target="_blank" rel="noopener">{{ $tenant->vitrineUrl() }} ↗</a></p>
            </div>
        </div>

        <form method="post" action="{{ route('vitrine.settings.update') }}" enctype="multipart/form-data" class="card form-grid" data-dirty-check>
            @csrf @method('PUT')
            <fieldset class="contents" @disabled(! ($canEdit ?? true))>

            <div class="field wide {{ $errors->has('logo') ? 'has-error' : '' }}">
                <span class="label">Logo</span>
                <div class="image-field" data-image-field>
                    <img class="thumb" data-preview src="{{ $settings['logo'] ? \Illuminate\Support\Facades\Storage::disk('public')->url($settings['logo']) : '' }}" alt="" @if (! $settings['logo']) hidden @endif>
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" data-image-input>
                    <label class="check small" data-remove-wrap @if (! $settings['logo']) hidden @endif><input type="checkbox" name="remove_logo" value="1" data-remove> Remover logo</label>
                    <p class="help">PNG com fundo transparente fica melhor.</p>
                </div>
                @error('logo') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field {{ $errors->has('color') ? 'has-error' : '' }}">
                <label class="label" for="color">Cor principal</label>
                <input id="color" type="color" name="color" value="{{ old('color', $settings['color']) }}" class="color-input">
                @error('color') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label class="label" for="whatsapp">WhatsApp</label>
                <input id="whatsapp" type="text" name="whatsapp" value="{{ old('whatsapp', $settings['whatsapp']) }}" placeholder="(51) 99999-9999">
                @error('whatsapp') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field"><label class="label" for="phone">Telefone</label><input id="phone" type="text" name="phone" value="{{ old('phone', $settings['phone']) }}"></div>
            <div class="field {{ $errors->has('email') ? 'has-error' : '' }}"><label class="label" for="email">E-mail</label><input id="email" type="text" name="email" value="{{ old('email', $settings['email']) }}">@error('email') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div class="field wide"><label class="check"><input type="hidden" name="building" value="0"><input type="checkbox" name="building" value="1" @checked(old('building', $settings['building']))> Vitrine em montagem (fica fora do Google)</label><p class="help">Desmarque quando os produtos estiverem prontos para o público achar no Google.</p></div>
            <div class="field {{ $errors->has('instagram') ? 'has-error' : '' }}"><label class="label" for="instagram">Instagram</label><input id="instagram" type="text" name="instagram" value="{{ old('instagram', $settings['instagram'] ? '@'.$settings['instagram'] : '') }}" placeholder="@seuperfil">@error('instagram') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div class="field"><label class="label" for="address">Endereço</label><input id="address" type="text" name="address" value="{{ old('address', $settings['address']) }}"></div>
            <div class="field wide"><label class="label" for="about">Texto de apresentação</label><textarea id="about" name="about" rows="3" data-autosize maxlength="600">{{ old('about', $settings['about']) }}</textarea></div>
            </fieldset>

            @if ($canEdit ?? true)
                <div class="actions wide"><span class="unsaved" hidden>Alterações não salvas</span><button class="btn primary">Salvar</button></div>
            @endif
        </form>
    </section>
</div>
@endsection
