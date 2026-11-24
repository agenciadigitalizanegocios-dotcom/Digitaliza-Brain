<?php

namespace App\Brain;

/**
 * Módulos = ferramentas que a IA usa, ligadas por cliente.
 * Nesta parte só existe o registro e o liga/desliga; o Catálogo e a Agenda vêm nas Partes 2 e 3.
 */
final class Modules
{
    public const ALL = [
        'catalogo' => 'Catálogo de produtos',
        'agenda' => 'Agenda de visitas',
    ];
}
