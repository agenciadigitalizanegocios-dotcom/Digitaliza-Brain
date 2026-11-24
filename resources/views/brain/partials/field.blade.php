@php
    /**
     * Campo genérico de formulário. Recebe só a definição do campo ($f) e o valor ($value).
     * O Catálogo (Parte 2) usa este mesmo partial.
     * Para imagem, $value é a URL da prévia; para dinheiro, o texto "1.234,56".
     */
    $name = 'fields['.$f['key'].']';
    $errorKey = 'fields.'.$f['key'];
    $id = ($prefix ?? 'f').'-'.$f['key'];
    $required = $f['required'] ?? false;
    $options = $f['type'] === 'select' ? \App\Fields\FieldTypes::activeOptions($f) : [];
    if ($f['type'] === 'select' && filled($value) && ! isset($options[$value]) && isset($f['options'][$value])) {
        $options[$value] = $f['options'][$value]; // opção arquivada ainda usada neste item
    }
@endphp

<div class="field {{ $errors->has($errorKey) ? 'has-error' : '' }} {{ in_array($f['type'], ['textarea', 'image']) ? 'wide' : '' }}">
    @if ($f['type'] === 'checkbox')
        <input type="hidden" name="{{ $name }}" value="0">
        <label class="check">
            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" data-field="{{ $f['key'] }}"
                   data-default="{{ ($f['default'] ?? false) ? '1' : '' }}" @checked($value)>
            {{ $f['label'] }}
        </label>
    @else
        <label class="label" for="{{ $id }}">
            {{ $f['label'] }} @if ($required)<span class="req" title="Obrigatório">*</span>@endif
        </label>

        @if ($f['type'] === 'textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" rows="3" data-field="{{ $f['key'] }}" data-autosize
                      placeholder="{{ $f['placeholder'] ?? '' }}" @required($required)>{{ $value }}</textarea>
        @elseif ($f['type'] === 'select')
            <select id="{{ $id }}" name="{{ $name }}" data-field="{{ $f['key'] }}" @required($required)>
                <option value="">Escolha…</option>
                @foreach ($options as $optValue => $optLabel)
                    <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                @endforeach
            </select>
        @elseif ($f['type'] === 'money')
            <div class="money">
                <span>R$</span>
                <input id="{{ $id }}" name="{{ $name }}" data-field="{{ $f['key'] }}" data-money inputmode="decimal"
                       type="text" value="{{ $value }}" placeholder="{{ $f['placeholder'] ?? '0,00' }}" @required($required)>
            </div>
        @elseif ($f['type'] === 'image')
            <div class="image-field" data-image-field>
                <img class="thumb" data-preview src="{{ $value ?: '' }}" alt="" @if (! $value) hidden @endif>
                <input id="{{ $id }}" name="{{ $name }}" type="file" accept="image/jpeg,image/png,image/webp" data-field="{{ $f['key'] }}" data-image-input>
                <label class="check small" data-remove-wrap @if (! $value) hidden @endif>
                    <input type="checkbox" name="remove[{{ $f['key'] }}]" value="1" data-remove> Remover imagem
                </label>
                <p class="help">JPG, PNG ou WEBP, até 5 MB.</p>
            </div>
        @else
            <input id="{{ $id }}" name="{{ $name }}" data-field="{{ $f['key'] }}"
                   type="{{ $f['type'] === 'number' ? 'number' : ($f['type'] === 'url' ? 'url' : 'text') }}"
                   @if (isset($f['min']) && $f['min'] !== null) min="{{ $f['min'] }}" @endif
                   @if (isset($f['max']) && $f['max'] !== null) max="{{ $f['max'] }}" @endif
                   value="{{ $value }}" placeholder="{{ $f['placeholder'] ?? '' }}" @required($required)>
        @endif
    @endif

    @if (! empty($f['help']))
        <p class="help">{{ $f['help'] }}</p>
    @endif
    @error($errorKey)
        <p class="field-error">{{ $message }}</p>
    @enderror
</div>
