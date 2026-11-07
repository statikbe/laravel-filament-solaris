<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Support\Metadata\ClosureFieldMetadata;
use Statikbe\FilamentSolaris\Support\Metadata\FieldMetadata;

function evidencePreset(?string $instructions = 'Preset instructions.'): FieldMetadata
{
    return new class($instructions) extends FieldMetadata
    {
        public function __construct(private ?string $text = 'Preset instructions.') {}

        public function keys(JsonSchemaTypeFactory $schema): array
        {
            return [
                'evidence' => $schema->string()->enum(['verbatim', 'derived'])->required(),
                'reasoning' => $schema->string()->required(),
            ];
        }

        public function instructions(): ?string
        {
            return $this->text;
        }
    };
}

it('wraps every field in a nullable required envelope', function () {
    $s = new JsonSchemaTypeFactory;

    $schema = evidencePreset()->wrap(fn ($s) => ['summary' => $s->string()])($s);
    $summary = $schema['summary']->toArray();

    expect($summary['type'])->toContain('object')->toContain('null')
        ->and(array_keys($summary['properties']))->toBe(['value', 'evidence', 'reasoning'])
        ->and($summary['properties']['value'])->toBe($s->string()->toArray())
        ->and($summary['required'] ?? [])->toContain('value');
});

it('wraps a hand-written resolver, not only factory schemas', function () {
    $s = new JsonSchemaTypeFactory;

    $schema = evidencePreset()->wrap(fn ($s) => ['count' => $s->integer()])($s);

    expect($schema['count']->toArray()['properties']['value'])->toBe($s->integer()->toArray());
});

it('rejects a metadata key named value', function () {
    $metadata = new ClosureFieldMetadata(fn ($s) => ['value' => $s->string()]);

    $metadata->wrap(fn ($s) => ['summary' => $s->string()])(new JsonSchemaTypeFactory);
})->throws(InvalidArgumentException::class);

it('unwraps a well-formed envelope', function () {
    $unwrapped = evidencePreset()->unwrap(
        ['summary' => ['value' => 'S', 'evidence' => 'derived']],
        ['summary'],
    );

    expect($unwrapped->values)->toBe(['summary' => 'S'])
        ->and($unwrapped->metadata)->toBe(['summary' => ['evidence' => 'derived']]);
});

it('turns a null, non-array or value-less envelope into null without metadata', function () {
    $unwrapped = evidencePreset()->unwrap(
        ['a' => null, 'b' => 'plain', 'c' => ['evidence' => 'derived']],
        ['a', 'b', 'c'],
    );

    expect($unwrapped->values)->toBe(['a' => null, 'b' => null, 'c' => null])
        ->and($unwrapped->metadata)->toBe([]);
});

it('passes non-target keys through untouched', function () {
    $unwrapped = evidencePreset()->unwrap(
        ['_message' => 'hi', 'summary' => ['value' => 'S', 'evidence' => 'derived']],
        ['summary'],
    );

    expect($unwrapped->values)->toBe(['_message' => 'hi', 'summary' => 'S'])
        ->and($unwrapped->metadata)->not->toHaveKey('_message');
});

it('returns metadata raw', function () {
    $unwrapped = evidencePreset()->unwrap(
        ['summary' => ['value' => 'S', 'reasoning' => '<b>x</b>']],
        ['summary'],
    );

    expect($unwrapped->metadata['summary']['reasoning'])->toBe('<b>x</b>');
});

it('builds the prompt fragment from instructions, with override', function () {
    $fixed = '## '.filament_solaris_trans('metadata.heading')."\n\n".filament_solaris_trans('metadata.prompt');

    expect(evidencePreset()->promptFragment())->toBe($fixed."\n\nPreset instructions.")
        ->and(evidencePreset()->promptFragment('Override'))->toBe($fixed."\n\nOverride")
        ->and(evidencePreset(null)->promptFragment())->toBe($fixed);
});

it('make() returns an instance of the subclass', function () {
    $class = get_class(evidencePreset());

    expect($class::make())->toBeInstanceOf($class);
});

it('ClosureFieldMetadata evaluates its keys closure', function () {
    $metadata = new ClosureFieldMetadata(fn ($s) => ['evidence' => $s->string()]);

    expect(array_keys($metadata->keys(new JsonSchemaTypeFactory)))->toBe(['evidence'])
        ->and($metadata->instructions())->toBeNull();
});
