<?php

namespace App\Http\Controllers;

use App\Fields\FieldTypes;
use App\Fields\ImageStore;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Aparência da vitrine (dono do cliente): logo, cor principal, texto e contatos do rodapé. */
class VitrineSettingsController extends Controller
{
    public function edit()
    {
        $tenant = app(CurrentTenant::class)->get();

        return view('products.vitrine', ['tenant' => $tenant, 'settings' => $tenant->vitrineSettings()]);
    }

    public function update(Request $request)
    {
        $tenant = app(CurrentTenant::class)->get();
        $data = $request->validate([
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'about' => ['nullable', 'string', 'max:600'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'],
            'instagram' => ['nullable', 'string', 'max:60', 'regex:/^@?[A-Za-z0-9._]+$/'],
            'logo' => FieldTypes::imageRules(),
            'remove_logo' => ['nullable', 'boolean'],
            'building' => ['nullable', 'boolean'],
        ], [], ['color' => 'cor', 'about' => 'texto', 'phone' => 'telefone', 'address' => 'endereço']);

        $settings = $tenant->vitrineSettings();
        $oldLogo = $settings['logo'];

        foreach (['color', 'about', 'phone', 'email', 'address'] as $k) {
            $settings[$k] = trim((string) ($data[$k] ?? ''));
        }
        // Só os dígitos, com DDI do Brasil se faltar (para o link wa.me).
        $digits = preg_replace('/\D/', '', (string) ($data['whatsapp'] ?? ''));
        $settings['whatsapp'] = $digits === '' ? '' : (strlen($digits) <= 11 ? '55'.$digits : $digits);
        $settings['instagram'] = ltrim(trim((string) ($data['instagram'] ?? '')), '@');
        $settings['building'] = $request->boolean('building');

        if ($request->file('logo')) {
            $settings['logo'] = ImageStore::store($request->file('logo'), $tenant->id, 'logo');
        } elseif ($request->boolean('remove_logo')) {
            $settings['logo'] = null;
        }

        $tenant->update(['vitrine' => $settings]);
        \App\Support\Audit::log('vitrine', $tenant->id, ['google' => $settings['building'] ? 'fora do Google' : 'liberada para o Google']);

        if ($oldLogo && $oldLogo !== $settings['logo']) {
            Storage::disk('public')->delete($oldLogo);
        }

        return redirect()->route('vitrine.settings')->with('ok', 'Vitrine atualizada.');
    }
}
