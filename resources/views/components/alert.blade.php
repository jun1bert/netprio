@props(['tone'=>'info','dismissible'=>false])
<div x-data="{ visible: true }" x-show="visible" @class(['ui-alert','ui-alert-success'=>$tone==='success','ui-alert-info'=>$tone==='info','ui-alert-warning'=>$tone==='warning']) role="status" aria-live="polite">
<x-icon :name="$tone === 'success' ? 'check' : ($tone === 'warning' ? 'alert' : 'info')" /><div class="flex-1 min-w-0">{{ $slot }}</div>@if($dismissible)<button type="button" @click="visible = false" aria-label="Dismiss notification" class="icon-button"><x-icon name="close" /></button>@endif
</div>