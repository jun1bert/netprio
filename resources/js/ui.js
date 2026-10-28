import { enhanceWorkflows } from './workflow-ui';

export function registerUI(Alpine) {
    Alpine.data('batchAssessments',()=>({
        selected:0,ready:0,message:'',initial:new Map(),root:null,
        rows(){return [...this.root.querySelectorAll('[data-batch-row]')];},
        signature(row){return JSON.stringify([...row.querySelectorAll('input:not([type=checkbox]),textarea')].map(input=>[input.name,input.value]));},
        init(){this.root=this.$el;queueMicrotask(()=>{this.rows().forEach(row=>this.initial.set(row,this.signature(row)));this.refresh();});},
        refresh(){
            this.selected=0;this.ready=0;
            this.rows().forEach(row=>{
                const selected=row.querySelector('[data-batch-selected]').checked;
                const inputs=[...row.querySelectorAll('[data-batch-score]')];
                const filled=inputs.filter(input=>input.value!==''&&input.validity.valid).length;
                const invalid=inputs.some(input=>!input.validity.valid);
                const date=row.querySelector('input[type=date]');
                const validDate=date.value!==''&&date.validity.valid;
                if(selected){this.selected++;if(filled===inputs.length&&!invalid&&validDate)this.ready++;}
                inputs.forEach(input=>input.setAttribute('aria-invalid',String(selected&&!input.validity.valid)));
                const progress=(selected?'Selected · ':'Not selected · ')+filled+'/'+inputs.length+' scored'+(invalid?' · Check score range':'')+(!validDate?' · Check date':'');
                const status=row.querySelector('[data-batch-progress]');if(status.textContent!==progress)status.textContent=progress;
            });
        },
        selectAll(value){this.rows().forEach(row=>row.querySelector('[data-batch-selected]').checked=value);this.refresh();this.root.dispatchEvent(new Event('input',{bubbles:true}));},
        selectChanged(){this.rows().filter(row=>this.initial.get(row)!==this.signature(row)).forEach(row=>row.querySelector('[data-batch-selected]').checked=true);this.refresh();this.root.dispatchEvent(new Event('input',{bubbles:true}));},
        check(event){
            this.refresh();this.message='';
            if(this.rows().some(row=>!row.querySelector('[data-batch-selected]').checked&&this.initial.get(row)!==this.signature(row)))this.message='Some unselected rows contain edits. Use “Select rows with changes” and save drafts before completing a smaller group.';
            else if(!this.selected)this.message='Select at least one asset to save.';
            else if(this.rows().some(row=>row.querySelector('[data-batch-selected]').checked&&[...row.querySelectorAll('input:not([type=checkbox]),textarea')].some(input=>!input.validity.valid||(input.type==='date'&&!input.value))))this.message='Check the dates and score ranges in the selected rows before saving.';
            else if(event.submitter?.value==='completed'&&this.ready!==this.selected)this.message='Every active criterion needs a score for each selected asset before completion.';
            if(this.message){event.preventDefault();event.stopImmediatePropagation();this.$nextTick(()=>this.$refs.error.focus());}
        }
    }));
    Alpine.data('netpriorShell', () => ({
        open: false, desktop: window.matchMedia('(min-width: 1024px)').matches, returnFocus: null,
        init() {
            this.media=window.matchMedia('(min-width: 1024px)');
            this.onResize=e=>{this.desktop=e.matches;if(e.matches)this.closeDrawer();};
            this.media.addEventListener('change',this.onResize);
        },
        destroy(){this.media.removeEventListener('change',this.onResize);document.body.style.overflow='';},
        openDrawer(){this.returnFocus=document.activeElement;this.open=true;document.body.style.overflow='hidden';this.$nextTick(()=>document.querySelector('[data-drawer-close]')?.focus());},
        closeDrawer(){if(!this.open)return;this.open=false;document.body.style.overflow='';this.$nextTick(()=>this.returnFocus?.focus());},
        trapDrawer(event){
            if(!this.open || this.desktop || event.key!=='Tab')return;
            const nodes=[...document.querySelectorAll('#institution-navigation a[href], #institution-navigation button')].filter(n=>n.getClientRects().length && !n.disabled);
            const first=nodes[0],last=nodes.at(-1);
            if(event.shiftKey && document.activeElement===first){event.preventDefault();last?.focus();}
            if(!event.shiftKey && document.activeElement===last){event.preventDefault();first?.focus();}
        }
    }));
    Alpine.data('ahpComparisons', pairs => ({
        pairs:pairs.map(pair=>{const value=pair.value===null||pair.value===''?null:Number(pair.value);return {...pair,winner:value===null?'':Math.abs(value-1)<1e-10?'equal':value>1?'a':'b',strength:value===null||value===1?3:Math.round(value>1?value:1/value)};}),
        current:0, attempted:false,
        init(){const missing=this.pairs.findIndex(p=>!p.winner);if(missing>=0)this.current=missing;},
        get answered(){return this.pairs.filter(p=>p.winner).length;},
        get progress(){return this.pairs.length?this.answered/this.pairs.length*100:100;},
        value(pair){return !pair.winner?'':pair.winner==='equal'?'1':(pair.winner==='a'?Number(pair.strength):1/Number(pair.strength)).toPrecision(17);},
        meaning(pair){
            if(!pair?.winner)return 'Choose which criterion matters more to see your judgment.';
            if(pair.winner==='equal')return pair.a+' and '+pair.b+' are equally important.';
            const terms={2:'slightly',3:'moderately',4:'moderately to strongly',5:'strongly',6:'strongly to very strongly',7:'very strongly',8:'very strongly to extremely',9:'extremely'};
            return (pair.winner==='a'?pair.a:pair.b)+' is '+terms[Number(pair.strength)]+' more important than '+(pair.winner==='a'?pair.b:pair.a)+'.';
        },
        next(){if(!this.pairs[this.current]?.winner){this.attempted=true;return;}this.attempted=false;this.current=Math.min(this.current+1,this.pairs.length-1);},
        go(index){this.current=index;this.attempted=false;this.$nextTick(()=>this.$refs.comparisonHeading?.focus());},
        check(event){if(this.answered!==this.pairs.length){event.preventDefault();event.stopImmediatePropagation();this.current=this.pairs.findIndex(p=>!p.winner);this.attempted=true;this.$nextTick(()=>this.$refs.comparisonHeading?.focus());}}
    }));
    Alpine.data('assessmentScores', () => ({
        answered:0,total:0,
        init(){this.refresh();},
        refresh(){const inputs=[...this.$el.querySelectorAll('[data-criterion-score]')];this.total=inputs.filter(n=>n.dataset.active==='true').length;this.answered=inputs.filter(n=>n.dataset.active==='true' && n.value!=='' && Number(n.value)>=1 && Number(n.value)<=5).length;}
    }));
}

export function enhanceUI() {
    enhanceWorkflows();
    let printDisclosures=[];
    window.addEventListener('beforeprint',()=>{printDisclosures=[...document.querySelectorAll('main details:not(.filter-disclosure)')].filter(el=>!el.open);printDisclosures.forEach(el=>el.open=true);});
    window.addEventListener('afterprint',()=>{printDisclosures.forEach(el=>el.open=false);printDisclosures=[];});
    document.querySelectorAll('form[method="get"]').forEach(form=>{
        if(form.closest('details') || form.hasAttribute('data-filter-static'))return;
        const details=document.createElement('details');details.className='panel filter-disclosure';details.open=matchMedia('(min-width:640px)').matches;
        const summary=document.createElement('summary');summary.className='text-sm font-semibold text-slate-700 cursor-pointer';
        const active=[...new URLSearchParams(location.search)].filter(([key,value])=>value!=='' && key!=='page').length;
        summary.textContent='Filters & sorting'+(active ? ` · ${active} active` : '');
        form.before(details);details.append(summary,form);form.classList.remove('panel');form.classList.add('mt-4');
        matchMedia('(min-width:640px)').addEventListener('change',event=>{details.open=event.matches;});
    });
    document.querySelectorAll('.table-wrap:not(.matrix-table)').forEach(wrapper=>{
        const table=wrapper.querySelector('table');if(!table?.tHead)return;
        wrapper.classList.add('record-table'); table.setAttribute('role','table');
        const headings=[...table.tHead.rows[0].cells].map(cell=>{cell.scope='col';return cell.textContent.trim()||'Actions';});
        [...table.tBodies].forEach(body=>[...body.rows].forEach(row=>{
            row.setAttribute('role','row');
            [...row.cells].forEach((cell,index)=>{
                cell.setAttribute('role','cell');
                if(cell.colSpan>1){cell.dataset.empty='true';return;}
                if(!cell.textContent.trim()&&!cell.querySelector('button,a'))cell.dataset.noContent='true';
                const value=document.createElement('div');value.className='cell-value';
                while(cell.firstChild)value.append(cell.firstChild);
                const label=document.createElement('span');label.className='mobile-cell-label';label.setAttribute('aria-hidden','true');label.textContent=({'Recommendation':'Next step','Asset at time of run':'Asset'}[headings[index]]||headings[index]||'Details');
                cell.append(label,value);
            });
        }));
    });
    document.querySelectorAll('.matrix-table').forEach((wrapper,index)=>{wrapper.tabIndex=0;wrapper.setAttribute('role','region');wrapper.setAttribute('aria-label',wrapper.dataset.label||`Comparison matrix ${index+1}; scroll horizontally to inspect columns`);});
    const summary=document.querySelector('[data-error-summary]');
    if(summary){
        summary.querySelectorAll('a[href^="#"]').forEach(link=>{
            const id=link.hash.slice(1).startsWith('field-pairs-')?'comparison-editor':link.hash.slice(1);
            const target=document.getElementById(id);
            if(!target){link.replaceWith(document.createTextNode(link.textContent));return;}
            link.href='#'+id;
            link.addEventListener('click',()=>{let parent=target.closest('details');while(parent){parent.open=true;parent=parent.parentElement.closest('details');}requestAnimationFrame(()=>target.focus());});
        });
        summary.focus({preventScroll:true});
    }

    const dialog=document.querySelector('#action-confirmation');
    let pending=null,previousOverflow='';
    const release=()=>{document.body.style.overflow=previousOverflow;pending=null;};
    dialog?.addEventListener('close',release);
    dialog?.querySelector('[data-confirm-cancel]')?.addEventListener('click',()=>dialog.close());
    dialog?.querySelector('[data-confirm-proceed]')?.addEventListener('click',()=>{
        const item=pending;dialog.close();if(!item)return;
        item.form.dataset.confirmed='true';item.form.requestSubmit(item.submitter||undefined);
    });
    document.addEventListener('submit',event=>{
        const form=event.target;if(!(form instanceof HTMLFormElement))return;
        if(form.dataset.submitting==='true'){event.preventDefault();return;}
        const button=event.submitter;
        let title=button?.dataset.confirmTitle||form.dataset.confirmTitle;
        let message=button?.dataset.confirmMessage||form.dataset.confirmMessage;
        const stateField=form.dataset.stateField;
        const state=stateField?form.elements.namedItem(stateField)?.value:null;
        if(stateField&&state!==form.dataset.stateOriginal&&(form.dataset.confirmStates||'0').split(',').includes(state)){
            title=form.dataset.stateTitle||'Change this record’s availability?';
            message=form.dataset.stateMessage||'This record will no longer be available for new assignments. Existing history will be retained.';
        }
        if(title&&dialog&&form.dataset.confirmed!=='true'){
            event.preventDefault();pending={form,submitter:button};
            dialog.querySelector('#confirmation-title').textContent=title;
            dialog.querySelector('#confirmation-message').textContent=message||'Please confirm before continuing. This operation may be irreversible.';
            dialog.querySelector('[data-confirm-proceed]').textContent=button?.dataset.confirmAction||form.dataset.confirmAction||'Confirm action';
            previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';dialog.showModal();return;
        }
        delete form.dataset.confirmed;
        if(event.defaultPrevented || form.method.toLowerCase()==='get')return;
        form.dataset.submitting='true';form.setAttribute('aria-busy','true');
        if(button?.name){const hidden=document.createElement('input');hidden.type='hidden';hidden.name=button.name;hidden.value=button.value;hidden.dataset.submitterValue='true';form.append(hidden);}
        const label=button?.dataset.loading||form.dataset.loading||'Saving changes…';
        if(button){button.dataset.originalLabel=button.innerHTML;button.replaceChildren();const spinner=document.createElement('span');spinner.className='loading-spinner';spinner.setAttribute('aria-hidden','true');button.append(spinner,document.createTextNode(label));}
        form.querySelectorAll('button[type=submit],button:not([type]),input[type=submit]').forEach(item=>{item.dataset.wasDisabled=String(item.disabled);item.disabled=true;});
        const status=document.querySelector('#submission-status');if(status)status.textContent=label;
    });
    window.addEventListener('pageshow',event=>{
        if(!event.persisted)return;
        document.querySelectorAll('form[data-submitting]').forEach(form=>{delete form.dataset.submitting;form.removeAttribute('aria-busy');form.querySelectorAll('[data-was-disabled]').forEach(button=>{button.disabled=button.dataset.wasDisabled==='true';delete button.dataset.wasDisabled;});form.querySelectorAll('[data-original-label]').forEach(button=>{button.innerHTML=button.dataset.originalLabel;delete button.dataset.originalLabel;});form.querySelectorAll('[data-submitter-value]').forEach(input=>input.remove());});
    });
}
