<div x-init="$nextTick(() => $el.closest('form').requestSubmit())">
    <div class="flex items-center justify-center py-8">
        <x-filament::loading-indicator class="h-8 w-8" />
    </div>
</div>
