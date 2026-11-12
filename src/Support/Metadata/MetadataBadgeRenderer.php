<?php

namespace Statikbe\FilamentSolaris\Support\Metadata;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Component as LivewireComponent;

/**
 * Renders a metadata badge component to HTML for the preview modal, outside
 * any form. The component gets a minimal schema as its container (so it can
 * resolve its Livewire component and evaluate closures), but only the
 * component itself is rendered: no schema grid or column wrappers, so the
 * badge sits inline beside the field label.
 */
final class MetadataBadgeRenderer
{
    /**
     * @param  HasSchemas&LivewireComponent  $livewire
     */
    public static function render(Component $badge, HasSchemas $livewire): Htmlable
    {
        $badge->container(Schema::make($livewire));

        if ($badge->isHidden()) {
            return new HtmlString('');
        }

        return new HtmlString('<span class="inline-flex items-center">'.$badge->toHtml().'</span>');
    }
}
