<?php

namespace App\Http\Controllers;

use App\Brain\FieldValidator;
use App\Brain\Navigation;
use App\Brain\Structure;
use App\Brain\Versioner;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\BrainVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrainController extends Controller
{
    public function index()
    {
        $first = Structure::current()->firstTabKey();
        abort_unless($first, 404, 'Este cliente ainda não tem abas.');

        return redirect()->route('brain.tab', $first);
    }

    public function show(string $tab)
    {
        $section = $this->section($tab);
        $settings = BrainSetting::current();

        $items = $section['type'] === 'items'
            ? BrainItem::where('section', $tab)->orderBy('position')->get()
            : collect();

        return view('brain.show', [
            'tab' => $tab,
            'section' => $section,
            'settings' => $settings,
            'items' => $items,
            'nav' => Navigation::build($settings),
            'lastVersion' => BrainVersion::with('user')->latest('version')->first(),
        ]);
    }

    public function updateSettings(Request $request, string $tab)
    {
        $section = $this->section($tab, 'settings');
        $settings = BrainSetting::current();
        $before = $settings->data ?? [];

        // Só os campos ativos; o valor dos arquivados fica como estava.
        $values = FieldValidator::validate($request, $section['fields'], $before);
        $after = array_merge($before, $values);

        if ($after == $before) {
            return redirect()->route('brain.tab', $tab)->with('ok', 'Nada mudou.');
        }

        $version = DB::transaction(function () use ($settings, $after, $request, $section) {
            $settings->data = $after;
            $settings->save();

            return Versioner::record($request->user(), 'Editou '.$section['label']);
        });

        return redirect()->route('brain.tab', $tab)
            ->with('ok', "Salvo. O robô já está usando a versão {$version->version}.");
    }
}
