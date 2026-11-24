<?php

namespace App\Http\Controllers;

use App\Brain\Structure;

abstract class Controller
{
    /**
     * Devolve a aba (ativa) da estrutura do cliente atual ou 404.
     * Se $type vier, a aba precisa ser desse tipo. Em "fields" vêm só os campos ativos.
     */
    protected function section(string $tab, ?string $type = null): array
    {
        $section = Structure::current()->tab($tab);

        abort_if(! $section || ($type && $section['type'] !== $type), 404);

        return $section;
    }
}
