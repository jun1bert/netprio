@props(['title','description'=>null,'breadcrumbs'=>null])
@php($context = App\Support\PageContext::for(request()))
@php($notice = match(session('status')) {'profile-updated'=>'Your profile was updated successfully.','password-updated'=>'Your password was updated successfully.','verification-link-sent'=>'A verification link has been sent to your email.',default=>session('status')})
<x-app-layout>
    <x-slot name="header"><x-page-header :title="$title" :description="$description ?? $context['description']" :breadcrumbs="$breadcrumbs ?? $context['breadcrumbs']"><x-slot name="actions">{{ $actions ?? '' }}</x-slot></x-page-header></x-slot>
    <div class="page-content">
        @if($notice)<x-alert tone="success" dismissible>{{ $notice }}</x-alert>@endif
        @if($errors->any())<div class="error-box" role="alert" tabindex="-1" data-error-summary><p class="font-semibold">Some details need your attention</p><ul class="mt-2 list-disc pl-5">@foreach($errors->getMessages() as $key => $messages)@foreach($messages as $error)<li><a class="underline" href="#field-{{ preg_replace('/[^a-zA-Z0-9_-]/', '-', $key) }}">{{ $error }}</a></li>@endforeach @endforeach</ul></div>@endif
        {{ $slot }}
    </div>
</x-app-layout>
