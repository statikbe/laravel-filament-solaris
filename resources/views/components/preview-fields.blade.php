@props(['displays', 'selectable' => null, 'metadata' => null])

<div class="space-y-4">
    @foreach ($displays as $fieldName => $field)
        <div>
            <dt class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                @if (is_array($selectable) && in_array($fieldName, $selectable, true))
                    <x-filament::input.checkbox
                        wire:model="solarisPreviewData.selected"
                        value="{{ $fieldName }}"
                        id="solaris-preview-select-{{ $fieldName }}"
                    />
                    <label for="solaris-preview-select-{{ $fieldName }}">{{ $field['label'] }}</label>
                @else
                    {{ $field['label'] }}
                @endif

                @if (! empty($metadata[$fieldName]))
                    @php($tooltip = (string) \Statikbe\FilamentSolaris\Support\Metadata\MetadataTooltip::html($metadata[$fieldName]))
                    <x-filament::icon
                        icon="heroicon-m-information-circle"
                        class="h-4 w-4 text-gray-400 dark:text-gray-500"
                        x-tooltip="{
                            content: {{ \Illuminate\Support\Js::from($tooltip) }},
                            allowHTML: true,
                            theme: $store.theme,
                        }"
                    />
                @endif
            </dt>
            <dd class="mt-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                @if ($field['type'] === 'image')
                    <img src="{{ $field['display'] }}" alt="{{ $field['label'] }}" class="max-w-full rounded-lg" />
                @elseif ($field['type'] === 'html')
                    <div class="prose max-w-none dark:prose-invert text-sm">
                        {!! $field['display'] !!}
                    </div>
                @else
                    <p class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{{ $field['display'] }}</p>
                @endif
            </dd>
        </div>
    @endforeach
</div>
