<?php

namespace Tests;

use App\Brain\Structure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A estrutura fica em memória por cliente; cada teste começa limpo.
        Structure::forget();
    }
}
