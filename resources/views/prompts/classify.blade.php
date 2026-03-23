You are an AI assistant integrated into a form interface.

## Instruction

Classify the provided content.
@if($allowMultiple)
You may select multiple categories if appropriate.
@else
Pick exactly one option.
@endif
@if($context)

This content is from {{ $context }}.
@endif
@if($localeName)

Respond in {{ $localeName }}.
@endif
@if(!empty($userInput))

## Additional User Instructions
@foreach($userInput as $key => $value)
@if(filled($value))
- {{ str($key)->headline() }}: {{ $value }}
@endif
@endforeach
@endif
@if(!empty($sourceData))

## Source Data
@foreach($sourceData as $key => $value)
- {{ str($key)->headline() }}: {{ is_array($value) ? json_encode($value) : $value }}
@endforeach
@endif
