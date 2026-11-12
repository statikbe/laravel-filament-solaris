<?php

use Illuminate\Support\Str;
use Statikbe\FilamentSolaris\Support\Metadata\MetadataTooltip;

it('renders one escaped line per key', function () {
    $html = MetadataTooltip::html(['evidence' => 'derived', 'reasoning' => '<script>x</script>']);

    expect((string) $html)->toBe('evidence: derived<br>reasoning: &lt;script&gt;x&lt;/script&gt;');
});

it('cuts long strings at 150 characters', function () {
    $html = MetadataTooltip::html(['reasoning' => str_repeat('a', 200)]);

    expect((string) $html)->toBe(e('reasoning: '.Str::limit(str_repeat('a', 200), 150)));
});

it('formats arrays, booleans and null', function () {
    $html = MetadataTooltip::html(['tags' => ['a', 'b'], 'flag' => true, 'none' => null]);

    expect((string) $html)->toBe('tags: [&quot;a&quot;,&quot;b&quot;]<br>flag: true<br>none: null');
});

it('cuts long JSON values', function () {
    $html = (string) MetadataTooltip::html(['tags' => [str_repeat('a', 200)]]);
    $value = html_entity_decode(Str::after($html, 'tags: '));

    expect(mb_strlen($value))->toBeLessThanOrEqual(153)
        ->and($value)->toEndWith('...');
});

it('builds a plain-text label', function () {
    expect(MetadataTooltip::text(['evidence' => 'derived', 'reasoning' => 'x']))
        ->toBe('evidence: derived; reasoning: x');
});
