<?php

namespace App\Brain;

use App\Models\BrainItem;
use App\Models\BrainSetting;

/** Menu lateral: cada aba ativa com seu status de preenchimento. */
final class Navigation
{
    public static function build(?BrainSetting $settings = null): array
    {
        $settings ??= BrainSetting::current();
        $data = $settings->data ?? [];

        $counts = BrainItem::where('active', true)
            ->selectRaw('section, count(*) as total')
            ->groupBy('section')
            ->pluck('total', 'section');

        $nav = [];

        foreach (Structure::current()->tabs() as $key => $tab) {
            $fields = Structure::activeFields($tab);

            if ($tab['type'] === 'settings') {
                $requiredOk = collect($fields)
                    ->filter(fn ($f) => $f['required'] ?? false)
                    ->every(fn ($f) => filled($data[$f['key']] ?? null));
                $anyFilled = collect($fields)
                    ->contains(fn ($f) => filled($data[$f['key']] ?? null));

                $nav[$key] = ['section' => $tab, 'done' => $requiredOk && $anyFilled, 'count' => null];
            } else {
                $total = (int) ($counts[$key] ?? 0);
                $nav[$key] = ['section' => $tab, 'done' => $total > 0, 'count' => $total];
            }
        }

        return $nav;
    }
}
