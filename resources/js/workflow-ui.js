// Small enhancements retain native controls and server-side authorization.
export function enhanceWorkflows() {
    const guarded=[...document.querySelectorAll('form[data-unsaved]')];
    const baselines=new Map();
    const signature=form=>JSON.stringify([...new FormData(form)].filter(([key])=>key!=='_token'));
    const changed=form=>baselines.has(form)&&form.dataset.submitting!=='true'&&(form.hasAttribute('data-unsaved-initial')||signature(form)!==baselines.get(form));
    guarded.forEach(form=>{
        const status=document.createElement('p');status.className='text-xs text-amber-900';status.setAttribute('role','status');status.dataset.unsavedStatus='';form.prepend(status);
        queueMicrotask(()=>baselines.set(form,signature(form)));
        const refresh=()=>setTimeout(()=>{status.textContent=changed(form)?'Unsaved changes — save before leaving this page.':'';},0);
        form.addEventListener('input',refresh);form.addEventListener('change',refresh);
    });
    window.addEventListener('beforeunload',event=>{
        if(guarded.some(changed)){event.preventDefault();event.returnValue='';}
    });
    window.addEventListener('pageshow',event=>{
        if(event.persisted)guarded.filter(form=>form.dataset.submitting==='true').forEach(form=>{
            // Loading controls may be disabled on a page restored from history.
            // Rebase after the shared loading handler restores their values.
            queueMicrotask(()=>{form.removeAttribute('data-unsaved-initial');baselines.set(form,signature(form));form.querySelector('[data-unsaved-status]').textContent='';});
        });
    });

    document.querySelectorAll('select[data-searchable-select]').forEach(select=>{
        const options=[...select.options].map(option=>option.cloneNode(true));
        const search=document.createElement('input');search.type='search';search.id=select.id+'-search';search.className='form-input mb-2';search.placeholder='Search by asset code, name or location';search.setAttribute('aria-controls',select.id);search.autocomplete='off';
        const label=document.createElement('label');label.htmlFor=search.id;label.className='field-label';label.textContent='Find an asset';
        const count=document.createElement('p');count.id=search.id+'-count';count.className='field-help mt-2';count.setAttribute('role','status');search.setAttribute('aria-describedby',count.id);
        select.before(label,search);select.after(count);
        const filter=()=>{
            const selected=select.value,term=search.value.trim().toLocaleLowerCase();
            const matches=options.filter(option=>option.value&&option.textContent.toLocaleLowerCase().includes(term));
            const shown=options.filter(option=>!option.value||option.value===selected||matches.some(match=>match.value===option.value));
            select.replaceChildren(...shown.map(option=>option.cloneNode(true)));select.value=selected;
            count.textContent=matches.length+' matching assets'+(selected&&!matches.some(option=>option.value===selected)?'; current selection retained.':'.');
        };
        search.addEventListener('input',filter);filter();
        // Search text is navigation assistance, not an assessment input.
    });

    try {
        const identity=document.querySelector('[data-workspace-user]')?.dataset.workspaceUser;
        if(!identity)return;
        const prefix='netprior:filters:'+identity+':';
        const getForms=[...document.querySelectorAll('main form[method=get]')];
        getForms.forEach(form=>{
            const path=new URL(form.action||location.href).pathname,key=prefix+path;
            if(location.search){
                const allowed=new Set([...form.elements].map(input=>input.name).filter(Boolean));
                const query=new URLSearchParams([...new URLSearchParams(location.search)].filter(([name])=>allowed.has(name)));
                if(query.size)sessionStorage.setItem(key,query.toString());
            } else if(sessionStorage.getItem(key)){
                const restore=document.createElement('a');restore.className='text-link text-xs print-hide';restore.href=path+'?'+sessionStorage.getItem(key);restore.textContent='Restore previous filters';form.before(restore);
            }
            form.querySelectorAll('a[href]').forEach(link=>{
                const target=new URL(link.href);
                if(target.pathname===path&&!target.search)link.addEventListener('click',()=>sessionStorage.removeItem(key));
            });
        });
        document.querySelectorAll('a[href]').forEach(link=>{
            const target=new URL(link.href),saved=sessionStorage.getItem(prefix+target.pathname);
            if(saved&&target.origin===location.origin&&!target.search&&target.pathname!==location.pathname)link.href=target.pathname+'?'+saved+target.hash;
        });
    } catch { /* Storage restrictions must not interfere with forms or navigation. */ }
}
