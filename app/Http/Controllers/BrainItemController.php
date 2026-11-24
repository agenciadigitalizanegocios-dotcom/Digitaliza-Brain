<?php

namespace App\Http\Controllers;

use App\Brain\FieldValidator;
use App\Brain\Structure;
use App\Brain\Versioner;
use App\Models\BrainItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cartões das abas de lista: adicionar, editar, ligar/desligar, mover e excluir. */
class BrainItemController extends Controller
{
    public function store(Request $request, string $tab)
    {
        $section = $this->section($tab, 'items');
        $data = FieldValidator::validate($request, $section['fields']);

        $max = (int) config('cerebro.limits.items_per_tab', 200);
        if ($max > 0 && BrainItem::where('section', $tab)->count() >= $max) {
            throw ValidationException::withMessages(['limite' => "Esta aba já tem o máximo de {$max} itens. Exclua os que não usa mais."]);
        }

        DB::transaction(function () use ($tab, $data, $request, $section) {
            $item = BrainItem::create([
                'section' => $tab,
                'data' => $data,
                'position' => (int) BrainItem::where('section', $tab)->max('position') + 1,
                'active' => true,
            ]);

            $this->record($request, $section, 'adicionou', $item);
        });

        return redirect()->route('brain.tab', $tab)->with('ok', 'Adicionado. O robô já está usando.');
    }

    public function update(Request $request, string $tab, int $item)
    {
        $section = $this->section($tab, 'items');
        $model = $this->find($tab, $item);
        $old = $model->data ?? [];
        // Merge: os valores dos campos arquivados continuam guardados.
        $data = array_merge($old, FieldValidator::validate($request, $section['fields'], $old));

        if ($data == $old) {
            return redirect()->route('brain.tab', $tab)->with('ok', 'Nada mudou.');
        }

        DB::transaction(function () use ($model, $data, $request, $section) {
            $model->update(['data' => $data]);
            $this->record($request, $section, 'editou', $model);
        });

        return redirect()->route('brain.tab', $tab)->with('ok', 'Alteração salva. O robô já está usando.');
    }

    public function toggle(Request $request, string $tab, int $item)
    {
        $section = $this->section($tab, 'items');
        $model = $this->find($tab, $item);

        DB::transaction(function () use ($model, $request, $section) {
            $model->update(['active' => ! $model->active]);
            $this->record($request, $section, $model->active ? 'ativou' : 'pausou', $model);
        });

        return redirect()->route('brain.tab', $tab)
            ->with('ok', $model->active ? 'Ativado: o robô volta a usar.' : 'Pausado: o robô deixa de usar, mas nada foi apagado.');
    }

    public function move(Request $request, string $tab, int $item, string $direction)
    {
        $section = $this->section($tab, 'items');
        $list = BrainItem::where('section', $tab)->orderBy('position')->get()->values();
        $index = $list->search(fn (BrainItem $i) => $i->id === $item);

        abort_if($index === false, 404);

        $target = $direction === 'subir' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= $list->count()) {
            return redirect()->route('brain.tab', $tab);
        }

        $moved = $list[$index];
        $list[$index] = $list[$target];
        $list[$target] = $moved;

        DB::transaction(function () use ($list) {
            foreach ($list as $position => $row) {
                if ($row->position !== $position + 1) {
                    $row->update(['position' => $position + 1]);
                }
            }
        });

        $this->record($request, $section, 'mudou a ordem de', $moved);

        return redirect()->route('brain.tab', $tab);
    }

    public function destroy(Request $request, string $tab, int $item)
    {
        $section = $this->section($tab, 'items');
        $model = $this->find($tab, $item);
        $model->delete();

        $this->record($request, $section, 'excluiu', $model);

        return redirect()->route('brain.tab', $tab)
            ->with('ok', 'Excluído. Se precisar, dá para voltar pelo histórico de versões.');
    }

    /** O global scope garante que só acha itens da imobiliária logada. */
    private function find(string $tab, int $id): BrainItem
    {
        return BrainItem::where('section', $tab)->findOrFail($id);
    }

    private function record(Request $request, array $section, string $action, BrainItem $item): void
    {
        $title = Structure::itemTitle($section, $item->data ?? []);

        Versioner::record($request->user(), "{$section['label']}: {$action} “{$title}”");
    }
}
