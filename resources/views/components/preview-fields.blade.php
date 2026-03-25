@props(['displays'])

<div class="space-y-4">
    @foreach ($displays as $fieldName => $field)
        <div>
            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ $field['label'] }}
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
