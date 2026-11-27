@extends('vitrine.layout')

@php
    $url = $tenant->vitrineUrl($product->urlSlug());
    $publicFields = $type->publicFields();
    $long = array_filter($publicFields, fn ($f) => $f['type'] === 'textarea' && $f['key'] !== $type->title_field);
    $short = array_filter($publicFields, fn ($f) => ! in_array($f['type'], ['textarea', 'image'], true)
        && ! in_array($f['key'], [$type->title_field, $type->price_field], true));
    $description = collect($long)->map(fn ($f) => trim((string) ($product->data[$f['key']] ?? '')))->filter()->first();
    $cover = $product->images->first();
@endphp

@section('title', $product->title())
@section('canonical', $url)
@section('description', \Illuminate\Support\Str::limit(trim(($product->priceText() ? $product->priceText().' · ' : '').($description ?? '')), 155))
@if ($cover)
    @section('og_image', $cover->url())
@endif

@section('content')
    <a class="v-back" href="{{ $tenant->vitrineUrl() }}{{ $type->key ? '?produto='.$type->key : '' }}">← Ver todas as opções</a>

    <article class="v-product">
        <div class="v-gallery" data-gallery>
            @if ($product->images->isNotEmpty())
                <img class="v-gallery-main" data-gallery-main src="{{ $cover->url() }}" alt="{{ $product->title() }}">
                @if ($product->images->count() > 1)
                    <div class="v-thumbs">
                        @foreach ($product->images as $img)
                            <button type="button" class="v-thumb {{ $loop->first ? 'active' : '' }}" data-gallery-thumb="{{ $img->url() }}" aria-label="Foto {{ $loop->iteration }}">
                                <img src="{{ $img->url() }}" alt="" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="v-gallery-empty">{{ $type->icon }}</div>
            @endif
        </div>

        <div class="v-info">
            @if ($product->status === 'reservado')<span class="v-badge static">{{ $type->statusLabel('reservado') }}</span>@endif
            <h1>{{ $product->title() }}</h1>
            @if ($product->priceText())<p class="v-price big">{{ $product->priceText() }}</p>@endif

            @if ($short)
                <dl class="v-specs">
                    @foreach ($short as $f)
                        @php $v = \App\Fields\FieldTypes::display($f, $product->data[$f['key']] ?? null); @endphp
                        @if ($v !== '')
                            <div><dt>{{ $f['label'] }}</dt><dd>{{ $v }}</dd></div>
                        @endif
                    @endforeach
                </dl>
            @endif

            @if ($settings['whatsapp'])
                <a class="v-btn v-btn-wa big" target="_blank" rel="noopener"
                   href="https://wa.me/{{ $settings['whatsapp'] }}?text={{ rawurlencode('Olá! Tenho interesse em: '.$product->title().' — '.$url) }}">
                    Tenho interesse
                </a>
            @endif
        </div>
    </article>

    @foreach ($long as $f)
        @php $text = trim((string) ($product->data[$f['key']] ?? '')); @endphp
        @if ($text !== '')
            <section class="v-desc">
                <h2>{{ $f['label'] }}</h2>
                <p>{!! nl2br(e($text)) !!}</p>
            </section>
        @endif
    @endforeach
@endsection
