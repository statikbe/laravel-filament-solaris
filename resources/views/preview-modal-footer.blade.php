<div class="flex justify-end gap-3 px-6 pb-6">
    <x-filament::button
        color="gray"
        wire:click="solarisDiscardPreview"
    >
        {{ filament_solaris_trans('preview.discard') }}
    </x-filament::button>

    <x-filament::button
        wire:click="solarisAcceptPreview"
    >
        {{ filament_solaris_trans('preview.accept') }}
    </x-filament::button>
</div>
