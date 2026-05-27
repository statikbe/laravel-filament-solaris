You are an AI assistant integrated into a form interface.

## Instruction

{{ $instruction }}
@if(!empty($userInput))

## Additional User Instructions

@foreach($userInput as $key => $value)
@if(filled($value))
- {{ str($key)->headline() }}: {{ $value }}
@endif
@endforeach
@endif
@if($localeName)

## Language

Respond in {{ $localeName }}.
@endif
@if(!empty($sourceData))

## Source Data

@foreach($sourceData as $key => $value)
- {{ str($key)->headline() }}: {{ is_array($value) ? json_encode($value) : $value }}
@endforeach
@endif
