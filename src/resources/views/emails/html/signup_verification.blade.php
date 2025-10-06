<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
    </head>
    <body>
        <p>{{ __('mail.signupverification-header', $vars) }}</p>

        <p>{{ __('mail.signupverification-body1', $vars) }}</p>

        <p><strong>{!! $short_code !!}</strong></p>

        <p>{{ __('mail.signupverification-body2', $vars) }}</p>

        <p><a href="{!! $href !!}">{!! $href !!}</a></p>

        <p>{{ __('mail.footer1', $vars) }}</p>
        <p>{{ __('mail.footer2', $vars) }}</p>
    </body>
</html>
