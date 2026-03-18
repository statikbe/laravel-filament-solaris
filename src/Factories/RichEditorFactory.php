<?php

namespace Statikbe\FilamentSolaris\Factories;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Tiptap\Editor;

class RichEditorFactory extends ComponentFactory
{
    /**
     * {@inheritDoc}
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        $structural = 'HTML content. Use standard HTML tags: <p>, <h2>, <h3>, <strong>, <em>, <ul>, <ol>, <li>, <blockquote>, <code>, <a href="...">.';

        $component = $this->component;

        if (method_exists($component, 'getMaxLength') && $component->getMaxLength()) {
            $structural .= " Maximum {$component->getMaxLength()} characters.";
        }

        return $schema->string()
            ->description($this->buildDescription($structural))
            ->required();
    }

    /**
     * {@inheritDoc}
     */
    public function toFormValue(mixed $aiValue): mixed
    {
        if ($aiValue === null) {
            return ['type' => 'doc', 'content' => []];
        }

        if (is_array($aiValue) || is_object($aiValue)) {
            $aiValue = json_encode($aiValue);
        } else {
            $aiValue = (string) $aiValue;
        }

        // Detect HTML by looking for opening tags. A regex is more reliable than
        // strip_tags() which mangles content like "5 < 10" or "a<b>c".
        $isHtml = (bool) preg_match('/<[a-z][\s\S]*>/i', $aiValue);

        if ($isHtml) {
            $html = $aiValue;
        } else {
            $html = collect(preg_split('/\n\s*\n/', $aiValue))
                ->map(fn ($p) => '<p>'.trim($p).'</p>')
                ->implode('');
        }

        return $this->resolveEditor()
            ->setContent($html)
            ->getDocument();
    }

    /**
     * Resolve a TipTap Editor instance from the component.
     *
     * Prefers the component's getTipTapEditor() which includes custom plugins,
     * but falls back to a plain RichContentRenderer editor when the component
     * has no container (e.g. in unit tests).
     *
     * Note: Filament throws \Error (not \Exception) when getStatePath() is
     * called on a component without a container, so we must catch \Error here.
     */
    private function resolveEditor(): Editor
    {
        try {
            /** @var RichEditor $component */
            $component = $this->component;

            return $component->getTipTapEditor();
        } catch (\Error) {
            return RichContentRenderer::make()->getEditor();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function toPromptContext(mixed $formValue): mixed
    {
        if (empty($formValue)) {
            return '';
        }

        // TipTap JSON document (array with 'type' => 'doc')
        if (is_array($formValue)) {
            return RichContentRenderer::make($formValue)->toText();
        }

        // HTML string fallback
        return strip_tags((string) $formValue);
    }
}
