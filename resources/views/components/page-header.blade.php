@props(['title','description'=>null,'breadcrumbs'=>[]])
<div class="page-heading">
    <div class="min-w-0 flex-1">
        @if($breadcrumbs)<nav aria-label="Breadcrumb" class="breadcrumbs"><ol>@foreach($breadcrumbs as $crumb)<li><a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a><span aria-hidden="true">/</span></li>@endforeach<li aria-current="page">{{ $title }}</li></ol></nav>@endif
        <h1>{{ $title }}</h1>@if($description)<p class="page-description">{{ $description }}</p>@endif
    </div>
    @if(isset($actions) && trim((string)$actions) !== '')<div class="page-actions">{{ $actions }}</div>@endif
</div>