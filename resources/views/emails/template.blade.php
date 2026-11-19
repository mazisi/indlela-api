@component('mail::message')
{!! $body !!}

@if ($url && $buttonText)
@component('mail::button', ['url' => $url])
{{ $buttonText }}
@endcomponent
@endif

Thanks,<br>
{{ config('app.name') }}

@if ($url && $buttonText)
@component('mail::subcopy')
If you're having trouble clicking the "{{ $buttonText }}" button, copy and paste the URL below into your web browser: <br>
{{ $url }}
@endcomponent
@endif
@endcomponent
