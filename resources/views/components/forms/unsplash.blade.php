@props([
    'photo' => '',
])

<x-buk-unsplash {{ $attributes->merge(['class' => '' ])}} photo="{{$photo}}" />