<?php

namespace App\Http\Controllers;

use App\Brain\Navigation;
use App\Brain\Versioner;
use App\Models\BrainVersion;
use Illuminate\Http\Request;

class BrainVersionController extends Controller
{
    public function index()
    {
        return view('brain.versions', [
            'versions' => BrainVersion::with('user')->latest('version')->paginate(30),
            'current' => (int) BrainVersion::max('version'),
            'nav' => Navigation::build(),
        ]);
    }

    public function show(int $version)
    {
        $model = BrainVersion::with('user')->where('version', $version)->firstOrFail();

        return view('brain.version', [
            'version' => $model,
            'current' => (int) BrainVersion::max('version'),
            'nav' => Navigation::build(),
        ]);
    }

    public function restore(Request $request, int $version)
    {
        $model = BrainVersion::where('version', $version)->firstOrFail();
        $new = Versioner::restore($model, $request->user());

        return redirect()->route('brain.versions.index')
            ->with('ok', "Pronto: o cérebro voltou a ser como na versão {$version} (agora é a versão {$new->version}).");
    }
}
