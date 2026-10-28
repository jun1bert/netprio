<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>NetPrior · Infrastructure decision support</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body data-workspace-user="{{ auth()->id() }}" class="font-sans antialiased bg-slate-50 text-slate-800">
<a href="#main-content" class="skip-link">Skip to page content</a>
<div x-data="netpriorShell" @keydown.escape.window="closeDrawer()" class="min-h-screen">
@include('layouts.navigation')
<div class="app-workspace lg:pl-64" :inert="open && !desktop">
<header class="topbar print-hide"><div class="flex items-center gap-3 min-w-0"><button type="button" @click="openDrawer()" :aria-expanded="open" aria-controls="institution-navigation" class="icon-button lg:hidden" aria-label="Open navigation"><x-icon name="menu" /></button><span class="topbar-context">{{ App\Support\PageContext::for(request())['label'] }}</span><span class="hidden sm:inline text-slate-300" aria-hidden="true">/</span><span class="hidden sm:inline text-xs text-slate-500">Institutional workspace</span></div>
<div x-data="{ expanded: false }" @click.outside="expanded=false" @keydown.escape.stop="expanded=false; $refs.accountTrigger.focus()" class="relative shrink-0"><button type="button" x-ref="accountTrigger" @click="expanded=!expanded" :aria-expanded="expanded" aria-controls="account-menu" class="profile-trigger"><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="hidden sm:block text-left"><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->role?->name ?? 'Awaiting role assignment' }}</span></span><x-icon name="chevron" /></button><div id="account-menu" x-cloak x-show="expanded" x-transition.opacity.duration.150ms class="profile-menu"><p class="sm:hidden px-3 py-2 text-sm font-medium">{{ auth()->user()->name }}<span class="block text-xs text-slate-500">{{ auth()->user()->role?->name ?? 'Unassigned' }}</span></p><a href="{{ route('profile.edit') }}"><x-icon name="users" />My profile</a><form method="post" data-loading="Signing out…" action="{{ route('logout') }}">@csrf<button type="submit"><x-icon name="logout" />Log out</button></form></div></div></header>
@php($demoWorkspace=app(App\Services\DemoDataService::class)->workspace())
@if($demoWorkspace['demo'])<div class="workspace-demo-banner sticky top-[68px] z-10 border-b border-amber-200 bg-amber-50 px-4 sm:px-6 lg:px-8 py-2.5 text-xs text-amber-950 print-hide" role="note"><strong>DEMO DATA PRESENT</strong><span class="ml-2">{{ $demoWorkspace['demo'] }} synthetic assets are stored in this workspace. Charts and totals may include them. Check each run’s data label before interpreting findings.</span></div>@endif
@isset($header)<div class="page-header-container">{{ $header }}</div>@endisset
<main id="main-content" tabindex="-1">{{ $slot }}</main>
<footer class="app-footer">NetPrior supports evidence-based institutional decisions. Final upgrade decisions remain with authorized personnel.</footer>
</div>
<x-confirm-dialog /><noscript><style>form[data-confirm-title] button[type=submit],form[data-confirm-title] button:not([type]),button[data-confirm-title]{display:none}</style><p class="page-content text-sm text-slate-500">Enable JavaScript to use confirmation-protected actions.</p></noscript>
<div class="sr-only" role="status" aria-live="polite" id="submission-status"></div>
</div></body></html>
