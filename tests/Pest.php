<?php

declare(strict_types=1);

/**
 * Bootstrap do Pest.
 *
 * Responsabilidade: ligar os testes de Feature ao TestCase da aplicação
 * (boot completo do framework). Os testes de Unit exercitam classes puras
 * do domínio e não precisam de aplicação.
 */

pest()->extend(Tests\TestCase::class)->in('Feature');
