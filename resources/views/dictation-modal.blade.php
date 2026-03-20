<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('dictation-modal', 'statikbe/filament-solaris') }}"
    x-data="dictationModal(@js($statePath))"
>
    {{-- Browser not supported --}}
    <div
        x-show="!supported"
        x-cloak
        class="rounded-lg bg-danger-50 p-4 text-center text-sm text-danger-600 dark:bg-danger-950 dark:text-danger-400"
    >
        {{ filament_solaris_trans('dictation.not_supported') }}
    </div>

    {{-- Microphone denied --}}
    <div
        x-show="microphoneDenied"
        x-cloak
        class="rounded-lg bg-danger-50 p-4 text-center text-sm text-danger-600 dark:bg-danger-950 dark:text-danger-400"
    >
        {{ filament_solaris_trans('dictation.microphone_denied') }}
    </div>

    {{-- Recording controls --}}
    <div
        x-show="supported && !microphoneDenied"
        class="flex flex-col items-center gap-3 py-6"
    >
        {{-- Record/Stop button --}}
        <button
            type="button"
            x-on:click="toggle()"
            x-bind:disabled="uploading"
            class="rounded-full p-4 border-none cursor-pointer leading-none transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2"
            x-bind:class="{
                'bg-danger-500 scale-110 animate-pulse ring-4 ring-danger-200 dark:ring-danger-800 focus:ring-danger-500': recording,
                'bg-primary-500 hover:bg-primary-600 focus:ring-primary-500': !recording && !uploaded,
                'bg-success-500 focus:ring-success-500': uploaded,
                'opacity-50 cursor-not-allowed': uploading,
            }"
        >
            <span x-show="!uploaded">
                <x-heroicon-o-microphone class="h-8 w-8 text-white" />
            </span>
            <span x-show="uploaded" x-cloak>
                <x-heroicon-o-check class="h-8 w-8 text-white" />
            </span>
        </button>

        {{-- Recording timer --}}
        <div
            x-show="recording"
            x-cloak
            x-transition
            class="text-lg font-mono tabular-nums text-danger-600 dark:text-danger-400"
        >
            <span x-text="formattedDuration"></span>
        </div>

        {{-- Status text --}}
        <span
            x-text="statusText"
            class="text-sm text-gray-500 dark:text-gray-400"
        ></span>

        {{-- Upload progress --}}
        <div x-show="uploading" x-cloak x-transition>
            <x-filament::loading-indicator class="h-5 w-5" />
        </div>
    </div>
</div>
