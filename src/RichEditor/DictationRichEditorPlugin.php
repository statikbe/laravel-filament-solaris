<?php

namespace Statikbe\FilamentSolaris\RichEditor;

use BackedEnum;
use Closure;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\HasToolbarButtons;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Laravel\Ai\Enums\Lab;
use Statikbe\FilamentSolaris\Actions\DictationToolbarAction;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Tiptap\Core\Extension;

/**
 * Public, fluent plugin that adds the Solaris dictation button to a RichEditor
 * toolbar. The same instance is passed both per-instance
 * (`RichEditor::make()->plugins([...])`) and globally (inside
 * `RichEditor::configureUsing(...)`, driven by the
 * `transcription.enable_rich_editor_toolbar_btn` config flag).
 *
 * It holds the (small) transcription config and builds the internal
 * {@see DictationToolbarAction} that owns the recording modal + cursor insert.
 */
class DictationRichEditorPlugin implements HasToolbarButtons, RichContentPlugin
{
    /** Shared by the tool name, the action name, and the toolbar-button id. */
    public const TOOL_NAME = 'solarisDictation';

    protected string|Closure|null $lang = null;

    /** @var Lab|array<string, string>|array<int, string>|string|Closure|null */
    protected Lab|array|string|Closure|null $transcriptionProvider = null;

    protected string|Closure|null $transcriptionModel = null;

    protected int|Closure|null $transcriptionTimeout = null;

    protected string|BackedEnum|Closure|null $icon = null;

    protected string|Closure|null $label = null;

    protected bool|Closure $isVisible = true;

    protected ?DictationToolbarAction $action = null;

    public static function make(): static
    {
        return new static;
    }

    public function lang(string|Closure $lang): static
    {
        $this->lang = $lang;

        return $this;
    }

    /**
     * @param  Lab|array<string, string>|array<int, string>|string|Closure  $provider
     */
    public function transcriptionProvider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static
    {
        $this->transcriptionProvider = $provider;

        if ($model !== null) {
            $this->transcriptionModel = $model;
        }

        return $this;
    }

    public function transcriptionTimeout(int|Closure $timeout): static
    {
        $this->transcriptionTimeout = $timeout;

        return $this;
    }

    public function icon(string|BackedEnum|Closure $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function label(string|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isVisible = fn (): bool => ! value($condition);

        return $this;
    }

    public function isVisible(): bool
    {
        return (bool) value($this->isVisible);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        if (! $this->shouldRender()) {
            return [];
        }

        return [
            RichEditorTool::make(self::TOOL_NAME)
                ->icon($this->resolveIcon())
                ->label($this->resolveLabel())
                ->action(self::TOOL_NAME)
                ->activeStyling(false),
        ];
    }

    /**
     * @return array<DictationToolbarAction>
     */
    public function getEditorActions(): array
    {
        return $this->shouldRender() ? [$this->getAction()] : [];
    }

    /**
     * @return array<string|array<string|array<string>>>
     */
    public function getEnabledToolbarButtons(): array
    {
        return $this->shouldRender() ? [self::TOOL_NAME] : [];
    }

    /**
     * @return array<string>
     */
    public function getDisabledToolbarButtons(): array
    {
        // When not rendering, actively disable the button so a per-instance
        // opt-out removes a globally-enabled button.
        return $this->shouldRender() ? [] : [self::TOOL_NAME];
    }

    protected function shouldRender(): bool
    {
        return $this->isVisible()
            && DictationToolbarAction::isAllowedInCurrentPanel();
    }

    protected function getAction(): DictationToolbarAction
    {
        return $this->action ??= $this->buildAction();
    }

    protected function buildAction(): DictationToolbarAction
    {
        $action = DictationToolbarAction::make(self::TOOL_NAME);

        if ($this->lang !== null) {
            $action->lang($this->lang);
        }

        if ($this->transcriptionProvider !== null) {
            $action->transcriptionProvider($this->transcriptionProvider, $this->transcriptionModel);
        }

        if ($this->transcriptionTimeout !== null) {
            $action->transcriptionTimeout($this->transcriptionTimeout);
        }

        return $action;
    }

    protected function resolveIcon(): string|BackedEnum
    {
        return value($this->icon) ?? FilamentSolaris::config()->getDictationIcon();
    }

    protected function resolveLabel(): string
    {
        return value($this->label) ?? filament_solaris_trans('dictation.toolbar_button_label');
    }
}
