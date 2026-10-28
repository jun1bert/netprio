<button x-cloak x-show="open" @click="closeDrawer()" class="drawer-overlay lg:hidden" aria-label="Close navigation" tabindex="-1"></button>
<aside id="institution-navigation" :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'" :inert="!open && !desktop" :role="!desktop && open ? 'dialog' : 'complementary'" :aria-modal="!desktop && open ? 'true' : null" @keydown="trapDrawer($event)" class="app-sidebar print:hidden" aria-label="Primary navigation">
<div class="sidebar-brand"><a href="{{ route(App\Support\WorkspaceNavigation::home(auth()->user())) }}" class="flex items-center gap-3"><x-application-logo class="h-9 w-9 shrink-0" /><span class="font-semibold text-xl text-white tracking-tight">Net<span class="text-blue-400">Prior</span><span class="block text-[10px] tracking-wider uppercase text-slate-400 mt-0.5">Infrastructure intelligence</span></span></a><button type="button" @click="closeDrawer()" class="icon-button lg:hidden text-slate-300" aria-label="Close navigation" data-drawer-close><x-icon name="close" /></button></div>
<nav class="sidebar-navigation" aria-label="Main navigation">
@php
$sections = [
 'Overview'=>[['dashboard','Dashboard','view-dashboard','grid','dashboard']],
 'Infrastructure'=>[['assets.index','Network assets','view-assets','network','assets.*'],['asset-types.index','Asset types','view-assets','grid','asset-types.*'],['locations.index','Locations','view-assets','network','locations.*']],
 'Assessments'=>[['assessments.index','Assessment history','view-assessments','clipboard','assessments.index,assessments.show,assessments.edit'],['assessments.batch','Batch assessments','create-assessments','clipboard','assessments.batch'],['assessments.create','Single assessment','create-assessments','plus','assessments.create']],
 'Decision support'=>[['criteria.index','Decision criteria','view-criteria','settings','criteria.*'],['ahp.index',auth()->user()->can('manage-comparisons') ? 'AHP comparisons' : 'AHP overview','view-criteria','chart','ahp.index'],['ahp.weights','Criteria weights','view-criteria','chart','ahp.weights'],['runs.create','Run prioritization','run-prioritization','plus','runs.create'],['runs.index','Priority rankings','view-rankings','chart','runs.index,runs.show,runs.result,runs.compare']],
 'Reports'=>[['reports.index','Reports','view-reports','file','reports.*']],
 'Administration'=>[['administration.index','Administration','access-administration','settings','administration.*'],['users.index','Users','manage-users','users','users.*'],['roles.index','Roles','manage-roles','users','roles.*'],['audit.index','Audit logs','view-audit-logs','clock','audit.*'],['settings.edit','Settings','manage-settings','settings','settings.*']],
 'Account'=>[['profile.edit','My profile',null,'users','profile.*']],
];
@endphp
@foreach($sections as $heading=>$items)
@php($visible=collect($items)->contains(fn($item)=>$item[2]===null || auth()->user()->can($item[2])))
@if($visible)<div x-data="{ expanded: true }" class="nav-group"><button type="button" @click="expanded=!expanded" :aria-expanded="expanded" aria-controls="nav-group-{{ $loop->index }}" class="nav-group-label">{{ $heading }}<x-icon name="chevron" class="h-3.5 w-3.5 transition-transform" x-bind:class="expanded ? '' : '-rotate-90'" /></button><div id="nav-group-{{ $loop->index }}" x-show="expanded">
@foreach($items as [$route,$label,$ability,$icon,$pattern]) @if($ability===null || auth()->user()->can($ability)) @php($active=request()->routeIs(...explode(',',$pattern)))<a href="{{ route($route) }}" @click="if(!desktop) closeDrawer()" @class(['sidebar-link','sidebar-link-active'=>$active]) @if($active) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ $label }}</span>@if($active)<span class="nav-active-dot" aria-hidden="true"></span>@endif</a>@endif @endforeach
</div></div>@endif
@endforeach
</nav>
<div class="sidebar-footer"><span class="h-2 w-2 rounded-full bg-blue-400" aria-hidden="true"></span><span>Decision support workspace</span></div>
</aside>
