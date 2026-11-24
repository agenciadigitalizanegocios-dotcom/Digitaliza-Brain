<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrainTemplate;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Modelos de nicho: listar, duplicar (para criar um nicho novo), renomear e excluir (se ninguém usa). */
class TemplateController extends Controller
{
    public function index()
    {
        return view('admin.templates', [
            'templates' => BrainTemplate::withCount('tenants')->orderBy('name')->get(),
        ]);
    }

    public function duplicate(Request $request, BrainTemplate $template)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']], [], ['name' => 'nome do modelo']);

        $slug = Str::slug($data['name']) ?: 'modelo';
        $base = $slug;
        $n = 2;
        while (BrainTemplate::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        $copy = BrainTemplate::create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => 'Copiado de '.$template->name,
            'version' => 1,
            'structure' => $template->structure,
        ]);

        Audit::log('modelo', null, ['duplicou' => $template->name, 'novo' => $copy->name]);

        return redirect()->route('admin.structure.show', ['modelos', $copy->id])
            ->with('info', 'Revise o fechamento do prompt e os itens iniciais: eles vieram do modelo '.$template->name.'.');
    }

    public function rename(Request $request, BrainTemplate $template)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        Audit::log('modelo', null, ['renomeou' => $template->name, 'para' => $data['name']]);
        $template->update($data);

        return redirect()->route('admin.templates.index')->with('ok', 'Modelo atualizado.');
    }

    public function destroy(BrainTemplate $template)
    {
        if ($template->tenants()->exists()) {
            return redirect()->route('admin.templates.index')->with('info', 'Este modelo é usado por clientes e não pode ser excluído.');
        }
        Audit::log('modelo', null, ['excluiu' => $template->name]);
        $template->delete();

        return redirect()->route('admin.templates.index')->with('ok', 'Modelo excluído.');
    }
}
