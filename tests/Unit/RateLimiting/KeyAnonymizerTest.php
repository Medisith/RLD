<?php

declare(strict_types=1);

/**
 * Testes de unidade do KeyAnonymizer — classe pura, sem framework (Fase 6).
 *
 * Garantias cobertas: pseudônimo estável (correlação de logs preservada),
 * distinto por cliente e por segredo, sem PII crua na saída, IPv6 com ":"
 * no identificador tratado, e chave fora do padrão pseudonimizada inteira.
 */

use App\RateLimiting\Support\KeyAnonymizer;

const ANONYMIZER_SECRET = 'unit-test-secret';

test('same key and secret always produce the same pseudonymized key', function (): void {
    $anonymizer = new KeyAnonymizer(ANONYMIZER_SECRET);

    $first = $anonymizer->anonymize('rate-limit:ip:203.0.113.10:rate-limited.ping');
    $second = $anonymizer->anonymize('rate-limit:ip:203.0.113.10:rate-limited.ping');

    expect($first)->toBe($second);
});

test('identifier is replaced but strategy and route stay readable', function (): void {
    $anonymizer = new KeyAnonymizer(ANONYMIZER_SECRET);

    $anonymized = $anonymizer->anonymize('rate-limit:ip:203.0.113.10:rate-limited.ping');

    expect($anonymized)->not->toContain('203.0.113.10')
        ->and($anonymized)->toStartWith('rate-limit:ip:')
        ->and($anonymized)->toEndWith(':rate-limited.ping');
});

test('different clients produce different pseudonyms', function (): void {
    $anonymizer = new KeyAnonymizer(ANONYMIZER_SECRET);

    expect($anonymizer->anonymize('rate-limit:ip:203.0.113.10:rate-limited.ping'))
        ->not->toBe($anonymizer->anonymize('rate-limit:ip:203.0.113.11:rate-limited.ping'));
});

test('different secrets produce different pseudonyms for the same client', function (): void {
    $key = 'rate-limit:ip:203.0.113.10:rate-limited.ping';

    expect((new KeyAnonymizer('secret-a'))->anonymize($key))
        ->not->toBe((new KeyAnonymizer('secret-b'))->anonymize($key));
});

test('ipv6 identifier with colons is fully pseudonymized and route preserved', function (): void {
    $anonymizer = new KeyAnonymizer(ANONYMIZER_SECRET);

    $anonymized = $anonymizer->anonymize('rate-limit:ip:2001:db8::1:rate-limited.ping');

    expect($anonymized)->not->toContain('2001:db8')
        ->and($anonymized)->toStartWith('rate-limit:ip:')
        ->and($anonymized)->toEndWith(':rate-limited.ping');
});

test('key outside the canonical format is pseudonymized as a whole', function (): void {
    $anonymizer = new KeyAnonymizer(ANONYMIZER_SECRET);

    $anonymized = $anonymizer->anonymize('unexpected-key-shape');

    // Sem estrutura conhecida, nada legível sobra — melhor perder contexto
    // do que vazar PII por engano.
    expect($anonymized)->not->toContain('unexpected')
        ->and(strlen($anonymized))->toBe(16);
});
