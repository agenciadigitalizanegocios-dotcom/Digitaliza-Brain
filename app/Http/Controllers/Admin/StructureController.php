<?php

namespace App\Http\Controllers\Admin;

use App\Brain\FieldValidator;
use App\Brain\PromptFormats;
use App\Brain\Structure;
use App\Brain\StructureValidator;
use App\Brain\TemplateApplier;
use App\Brain\Templates;
use App\Brain\Versioner;
use App\Fields\FieldDefinitions;
use App\Fields\FieldTypes;
use App\Http\Controllers\Controller;
use App\Models\BrainSetting;
use App\Models\BrainTemplate;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Editor de estrutura (abas e campos). O mesmo editor serve para um modelo de nicho
 * (/admin/modelos/{id}/estrutura) e para um cliente (/admin/clientes/{id}/estrutura).
 * Cada ação é um formulário normal: POST → valida a estrutura inteira → grava → redireciona.
 */
class StructureController extends Controller
{
    public function show(Request $request, string $kind, int $id)
    {
        [$owner, $structure] = $this->load($kind, $id);
        $tabs = $structure['tabs'] ?? [];
        $selected = $request->query('aba', $tabs[0]['key'] ?? null);
        $tab = collect($tabs)->firstWhere('key', $selected);

        return view('admin.structure', [
            'kind' => $kind,
            'owner' => $owner,
            'structure' => $structure,
            'tab' => $tab,
            'formats' => PromptFormats::LABELS,
            'types' => FieldTypes::TYPES,
        ]);
    }

    public function storeTab(Request $request, string $kind, int $id)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:8'],
            'type' => ['required', Rule::in(['settings', 'items'])],
        ], [], ['label' => 'nome da aba', 'type' => 'tipo']);

        return $this->mutate($kind, $id, function (array $s) use ($data) {
            $key = StructureValidator::makeKey($data['label'], array_column($s['tabs'], 'key'), 'aba');
            $order = max(array_merge([0], array_map(fn ($t) => (int) ($t['ai']['order'] ?? 0), $s['tabs']))) + 1;
            $fields = [];
            $titleField = null;
            if ($data['type'] === 'items') {
                // Toda lista precisa de um campo título.
                $fields[] = FieldDefinitions::make('titulo', 'Título', 'text', true);
                $titleField = 'titulo';
            }
            $s['tabs'][] = [
                'key' => $key, 'label' => $data['label'], 'icon' => ($data['icon'] ?? null) ?: '📄', 'type' => $data['type'],
                'intro' => '', 'singular' => '', 'ordered' => false,
                'title_field' => $titleField, 'badge_field' => null, 'badge_text' => null, 'archived' => false,
                'ai' => ['format' => $data['type'] === 'items' ? 'lista' : 'campos', 'title' => mb_strtoupper($data['label']),
                    'before' => '', 'after' => '', 'line' => '', 'order' => $order],
                'fields' => $fields,
            ];

            return [$s, "Estrutura: adicionou a aba “{$data['label']}”", $key];
        });
    }

    public function updateTab(Request $request, string $kind, int $id, string $tab)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:8'],
            'intro' => ['nullable', 'string', 'max:500'],
            'singular' => ['nullable', 'string', 'max:40'],
            'ordered' => ['nullable', 'boolean'],
            'title_field' => ['nullable', 'string', 'max:40'],
            'badge_field' => ['nullable', 'string', 'max:40'],
            'badge_text' => ['nullable', 'string', 'max:40'],
            'ai_format' => ['required', Rule::in(array_keys(PromptFormats::LABELS))],
            'ai_title' => ['nullable', 'string', 'max:120'],
            'ai_before' => ['nullable', 'string', 'max:2000'],
            'ai_after' => ['nullable', 'string', 'max:2000'],
            'ai_line' => ['nullable', 'string', 'max:500'],
            'ai_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        return $this->mutate($kind, $id, function (array $s) use ($data, $tab, $request) {
            $label = '';
            foreach ($s['tabs'] as &$t) {
                if ($t['key'] !== $tab) {
                    continue;
                }
                // O que não veio no formulário continua como estava.
                $has = fn (string $k) => $request->has($k);
                $label = $data['label'];
                $t['label'] = $data['label'];
                $t['icon'] = ($data['icon'] ?? null) ?: $t['icon'];
                foreach (['intro', 'singular'] as $k) {
                    if ($has($k)) {
                        $t[$k] = (string) ($data[$k] ?? '');
                    }
                }
                if ($has('ordered')) {
                    $t['ordered'] = $request->boolean('ordered');
                }
                if ($t['type'] === 'items') {
                    foreach (['title_field', 'badge_field', 'badge_text'] as $k) {
                        if ($has($k)) {
                            $t[$k] = ($data[$k] ?? null) ?: null;
                        }
                    }
                }
                $ai = $t['ai'] ?? [];
                $ai['format'] = $data['ai_format'];
                $ai['order'] = (int) $data['ai_order'];
                foreach (['title', 'before', 'after', 'line'] as $k) {
                    if ($has('ai_'.$k)) {
                        $ai[$k] = (string) ($data['ai_'.$k] ?? '');
                    }
                }
                $t['ai'] = $ai + ['title' => '', 'before' => '', 'after' => '', 'line' => ''];
            }
            unset($t);
            abort_if($label === '', 404);

            return [$s, "Estrutura: editou a aba “{$label}”", $tab];
        });
    }

    public function archiveTab(string $kind, int $id, string $tab)
    {
        return $this->mutate($kind, $id, function (array $s) use ($tab) {
            $msg = null;
            foreach ($s['tabs'] as &$t) {
                if ($t['key'] === $tab) {
                    $t['archived'] = empty($t['archived']);
                    $msg = ($t['archived'] ? 'arquivou' : 'desarquivou')." a aba “{$t['label']}”";
                }
            }
            unset($t);
            abort_unless($msg, 404);

            return [$s, "Estrutura: {$msg}", $tab];
        });
    }

    public function moveTab(string $kind, int $id, string $tab, string $direction)
    {
        return $this->mutate($kind, $id, function (array $s) use ($tab, $direction) {
            $s['tabs'] = self::moveIn($s['tabs'], $tab, $direction);

            return [$s, 'Estrutura: mudou a ordem das abas', $tab];
        });
    }

    public function storeField(Request $request, string $kind, int $id, string $tab)
    {
        $data = FieldDefinitions::validate($request);

        return $this->mutate($kind, $id, function (array $s) use ($data, $tab) {
            $taken = [];
            foreach ($s['tabs'] as $t) {
                // Formulário: chave única entre todas as abas de formulário (data é um JSON plano).
                if ($t['key'] === $tab || ($t['type'] === 'settings' && self::tabType($s, $tab) === 'settings')) {
                    $taken = array_merge($taken, array_column($t['fields'] ?? [], 'key'));
                }
            }
            $key = StructureValidator::makeKey($data['label'], $taken);
            $field = FieldDefinitions::make($key, $data['label'], $data['type'], (bool) ($data['required'] ?? false));
            $field = FieldDefinitions::fill($field, $data);

            foreach ($s['tabs'] as &$t) {
                if ($t['key'] === $tab) {
                    $t['fields'][] = $field;
                }
            }
            unset($t);

            return [$s, "Estrutura: adicionou o campo “{$data['label']}”", $tab];
        });
    }

    public function updateField(Request $request, string $kind, int $id, string $tab, string $field)
    {
        $data = FieldDefinitions::validate($request);

        return $this->mutate($kind, $id, function (array $s) use ($data, $tab, $field) {
            $found = false;
            foreach ($s['tabs'] as &$t) {
                if ($t['key'] !== $tab) {
                    continue;
                }
                foreach ($t['fields'] as &$f) {
                    if ($f['key'] === $field) {
                        $f = FieldDefinitions::fill($f, $data);
                        $found = true;
                    }
                }
                unset($f);
            }
            unset($t);
            abort_unless($found, 404);

            return [$s, "Estrutura: editou o campo “{$data['label']}”", $tab];
        });
    }

    public function archiveField(string $kind, int $id, string $tab, string $field)
    {
        return $this->mutate($kind, $id, function (array $s) use ($tab, $field) {
            $msg = null;
            foreach ($s['tabs'] as &$t) {
                if ($t['key'] !== $tab) {
                    continue;
                }
                foreach ($t['fields'] as &$f) {
                    if ($f['key'] === $field) {
                        $f['archived'] = empty($f['archived']);
                        $msg = ($f['archived'] ? 'arquivou' : 'desarquivou')." o campo “{$f['label']}”";
                    }
                }
                unset($f);
            }
            unset($t);
            abort_unless($msg, 404);

            return [$s, "Estrutura: {$msg}", $tab];
        });
    }

    public function moveField(string $kind, int $id, string $tab, string $field, string $direction)
    {
        return $this->mutate($kind, $id, function (array $s) use ($tab, $field, $direction) {
            foreach ($s['tabs'] as &$t) {
                if ($t['key'] === $tab) {
                    $t['fields'] = self::moveIn($t['fields'], $field, $direction);
                }
            }
            unset($t);

            return [$s, 'Estrutura: mudou a ordem dos campos', $tab];
        });
    }

    public function updateClosing(Request $request, string $kind, int $id)
    {
        $request->validate(['closing' => ['nullable', 'string', 'max:3000']]);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $request->input('closing'))), fn ($l) => $l !== ''));

        return $this->mutate($kind, $id, function (array $s) use ($lines, $request) {
            $s['closing'] = $lines;

            return [$s, 'Estrutura: editou o fechamento do prompt', $request->input('aba')];
        });
    }

    /** Itens iniciais (só em modelos): copiados uma única vez na criação do cliente. */
    public function storeStarter(Request $request, int $id, string $tab)
    {
        $template = BrainTemplate::findOrFail($id);
        $s = $template->structure;
        $tabDef = collect($s['tabs'])->firstWhere('key', $tab);
        abort_unless($tabDef && $tabDef['type'] === 'items', 404);

        $values = FieldValidator::validate($request, array_values(array_filter(
            $tabDef['fields'], fn ($f) => empty($f['archived']) && $f['type'] !== 'image'
        )));
        $s['starter_items'][] = ['section' => $tab, 'data' => $values];
        $template->structure = $s; // não sobe a versão: itens iniciais não geram pendência
        $template->save();

        return redirect()->route('admin.structure.show', ['modelos', $id, 'aba' => $tab])->with('ok', 'Item inicial adicionado.');
    }

    public function destroyStarter(int $id, int $index)
    {
        $template = BrainTemplate::findOrFail($id);
        $s = $template->structure;
        $tab = $s['starter_items'][$index]['section'] ?? null;
        abort_unless($tab, 404);
        array_splice($s['starter_items'], $index, 1);
        $template->structure = $s;
        $template->save();

        return redirect()->route('admin.structure.show', ['modelos', $id, 'aba' => $tab])->with('ok', 'Item inicial removido.');
    }

    // ---------------------------------------------------------------------

    /** Carrega a estrutura do dono (modelo ou cliente). Para cliente, define o CurrentTenant. */
    private function load(string $kind, int $id): array
    {
        if ($kind === 'modelos') {
            $template = BrainTemplate::findOrFail($id);

            return [$template, $template->structure];
        }

        $tenant = Tenant::findOrFail($id);
        app(CurrentTenant::class)->set($tenant);
        $structure = BrainSetting::current()->structure ?? Templates::fallback();

        return [$tenant, $structure];
    }

    /** Aplica a mudança, valida a estrutura inteira e grava. */
    private function mutate(string $kind, int $id, callable $change)
    {
        [$owner, $old] = $this->load($kind, $id);
        [$new, $description, $tab] = $change($old);

        $countLong = $owner instanceof Tenant
            ? fn ($t, $f) => TemplateApplier::countLong($owner, $t, $f)
            : null;
        $errors = StructureValidator::validate($new, $old, $countLong);

        if ($errors) {
            throw ValidationException::withMessages(['structure' => $errors]);
        }

        if ($owner instanceof BrainTemplate) {
            $owner->structure = $new;
            $owner->version = $owner->version + 1;
            $owner->save();
            Audit::log('estrutura_modelo', null, ['modelo' => $owner->name, 'o_que' => $description]);
        } else {
            DB::transaction(function () use ($new, $owner, $description) {
                $settings = BrainSetting::current();
                $settings->structure = $new;
                $settings->save();
                Structure::forget($owner->id);
                Versioner::record(request()->user(), $description);
            });
            Structure::forget($owner->id);
            Audit::log('estrutura_cliente', $owner->id, ['o_que' => $description]);
        }

        return redirect()->route('admin.structure.show', array_filter([$kind, $id, 'aba' => $tab]))
            ->with('ok', 'Estrutura salva.'.($owner instanceof BrainTemplate ? ' Os clientes deste modelo vão ver “atualização pendente”.' : ''));
    }

    private static function moveIn(array $list, string $key, string $direction): array
    {
        $list = array_values($list);
        $i = array_search($key, array_column($list, 'key'), true);
        abort_if($i === false, 404);
        $j = $direction === 'subir' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($list)) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }

        return $list;
    }

    private static function tabType(array $s, string $tab): ?string
    {
        return collect($s['tabs'])->firstWhere('key', $tab)['type'] ?? null;
    }
}
