<?php

namespace App\Brain;

/**
 * Módulos = ferramentas que a IA usa, ligadas por cliente.
 * Catálogo chegou na Parte 2; a Agenda vem na Parte 3.
 */
final class Modules
{
    public const ALL = [
        'catalogo' => 'Catálogo de produtos',
        'agenda' => 'Agenda de visitas',
    ];

    /** Módulos que já funcionam. Os outros aparecem como "em breve" e não podem ser ligados. */
    public const READY = ['catalogo'];

    public const DESCRIPTIONS = [
        'catalogo' => 'Produtos com fotos, vitrine pública e busca de produtos para o robô.',
        'agenda' => 'O robô marca visitas na agenda do cliente.',
    ];
}
