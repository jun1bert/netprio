// Dependency-free visual QA against HTML produced by verify-mysql.php; no live records are created.
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const qa = path.join(root, 'storage/app/qa');
const port = 9327;
const chrome = process.env.NETPRIOR_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const browser = spawn(chrome, ['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking',`--remote-debugging-port=${port}`,`--user-data-dir=${path.join(qa,'layout-profile')}`,'about:blank'], { stdio: 'ignore', windowsHide: true });
let socket;
try {
    let endpoint;
    for (let attempt=0;attempt<50;attempt++) {
        try { endpoint=await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, {method:'PUT'})).json(); break; }
        catch { await new Promise(resolve=>setTimeout(resolve,200)); }
    }
    if (!endpoint) throw new Error('Headless Chrome did not start.');
    socket = new WebSocket(endpoint.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
    let sequence=0;
    const pending=new Map();
    let loadResolve;
    const errors=[];
    socket.addEventListener('message', event=>{
        const message=JSON.parse(event.data);
        if (message.id) { const handler=pending.get(message.id);pending.delete(message.id); if (message.error) handler?.reject(new Error(message.error.message)); else handler?.resolve(message.result); }
        if (message.method==='Page.loadEventFired') loadResolve?.();
        if (message.method==='Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    });
    const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
    await send('Page.enable'); await send('Runtime.enable');
    const results=[];
    const screens=JSON.parse(await readFile(path.join(qa,'screens.json'),'utf8'));
    for (const viewport of (process.argv.includes("--interactions-only") ? [] : [{label:'mobile375',width:375,height:900,mobile:true},{label:'tablet768',width:768,height:1100,mobile:true},{label:'laptop1024',width:1024,height:1000,mobile:false},{label:'desktop1366',width:1366,height:1100,mobile:false},{label:'large1920',width:1920,height:1200,mobile:false}])) {
        await send('Emulation.setDeviceMetricsOverride',{...viewport,deviceScaleFactor:1});
        for (const page of screens) {
            await readFile(path.join(qa,page+'.html'));
            const loaded = new Promise(resolve=>{loadResolve=resolve;});
            await send('Page.navigate',{url:pathToFileURL(path.join(qa,page+'.html')).href});
            await loaded; await new Promise(resolve=>setTimeout(resolve,250));
            const measure=await send('Runtime.evaluate',{expression:'document.body.getBoundingClientRect();JSON.stringify({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,title:document.querySelector("h1")?.textContent,sidebar:document.querySelector("#institution-navigation")?.getBoundingClientRect().right})',returnByValue:true});
            const metrics=JSON.parse(measure.result.value);
            if (metrics.width!==viewport.width || metrics.scrollWidth>viewport.width) { console.log(await send('Runtime.evaluate',{expression:'JSON.stringify([...document.querySelectorAll("body *")].filter(el=>!el.closest("table") && el.getBoundingClientRect().right>'+viewport.width+').map(el=>({tag:el.tagName,cls:el.className,width:el.getBoundingClientRect().width,right:el.getBoundingClientRect().right})).slice(0,16))',returnByValue:true})); throw new Error(`${page}/${viewport.label}: invalid viewport or horizontal page overflow: ${JSON.stringify(metrics)}`); }
            if (viewport.width<1024 && metrics.sidebar>1) throw new Error(`${page}: mobile sidebar is not initially collapsed.`);
            const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            await writeFile(path.join(qa,`${page}-${viewport.label}.png`),Buffer.from(screenshot.data,'base64'));
            if (viewport.width<1024 && page==='dashboard') {
                await send('Runtime.evaluate',{expression:'document.querySelector("[aria-controls=institution-navigation]").click()'});
                await new Promise(resolve=>setTimeout(resolve,300));
                const navigation=await send('Runtime.evaluate',{expression:'document.querySelector("aside").getBoundingClientRect().left',returnByValue:true});
                if (Math.abs(navigation.result.value)>1) throw new Error('Mobile navigation did not open.');
            }
            results.push({page,viewport:viewport.label,width:metrics.width,horizontalOverflow:false});
        }
        console.log(viewport.label+': '+screens.length+' screens passed');
    }
    await send('Emulation.setDeviceMetricsOverride',{width:794,height:1123,mobile:false,deviceScaleFactor:1});
    await send('Emulation.setEmulatedMedia',{media:'print'});
    const printLoaded=new Promise(resolve=>{loadResolve=resolve;});
    await send('Page.navigate',{url:pathToFileURL(path.join(qa,'report.html')).href});
    await printLoaded; await new Promise(resolve=>setTimeout(resolve,600));
    const printState=await send('Runtime.evaluate',{expression:'JSON.stringify({navigationHidden:getComputedStyle(document.querySelector("aside")).display==="none",padding:getComputedStyle(document.querySelector(".lg\\\\:pl-64")).paddingLeft,formsHidden:[...document.querySelectorAll("form")].every(f=>getComputedStyle(f).display==="none")})',returnByValue:true});
    const printMetrics=JSON.parse(printState.result.value);
    if (!printMetrics.navigationHidden || !printMetrics.formsHidden || printMetrics.padding!=='0px') throw new Error('Report print layout retained navigation, filters or sidebar offset: '+JSON.stringify(printMetrics));
    const printScreenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    await writeFile(path.join(qa,'report-print.png'),Buffer.from(printScreenshot.data,'base64'));
    const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
    const load=async page=>{const ready=new Promise(resolve=>{loadResolve=resolve;});await send('Page.navigate',{url:pathToFileURL(path.join(qa,page+'.html')).href});await ready;await new Promise(resolve=>setTimeout(resolve,250));};
    const wait=()=>new Promise(resolve=>setTimeout(resolve,100));
    const requireValue=(condition,message)=>{if(!condition)throw new Error(message);};
    printMetrics.demoWarningVisible=await evaluate('[...document.querySelectorAll("[role=note]")].some(el=>el.getAttribute("aria-label")==="Demonstration data warning" && getComputedStyle(el).display!=="none" && el.getBoundingClientRect().width>0)');
    requireValue(printMetrics.demoWarningVisible,'Printed ranking report lost its demonstration-data warning.');
    printMetrics.exclusionsVisible=await evaluate('document.querySelector(".run-exclusions ul")?.getBoundingClientRect().height>0');
    requireValue(printMetrics.exclusionsVisible,'Print omitted the collapsed exclusion explanations.');
    await send('Emulation.setEmulatedMedia',{media:'screen'});
    await send('Emulation.setDeviceMetricsOverride',{width:683,height:550,mobile:false,deviceScaleFactor:1});
    for(const page of ['dashboard','assets','run-compare','assessment-form','ahp']){
        await load(page);
        requireValue(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Reflow failed at the CSS viewport equivalent of 200% zoom: '+page);
    }
    await send('Emulation.setDeviceMetricsOverride',{width:375,height:900,mobile:true,deviceScaleFactor:1});
    await load('run-compare');
    requireValue(await evaluate('document.querySelector("[name=against]").getBoundingClientRect().height>0'),'Comparison baseline selection was hidden on mobile.');
    const compareShot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(path.join(qa,'run-compare-mobile375.png'),Buffer.from(compareShot.data,'base64'));
    await load('dashboard');
    requireValue(await evaluate('!document.querySelector(".run-health-disclosure").open'),'Dashboard ranking details should be collapsed initially.');
    await evaluate('document.querySelector(".run-health-disclosure summary").click()');await wait();
    requireValue(await evaluate('document.querySelector(".run-health-disclosure").open'),'Dashboard ranking details did not expand.');
    await evaluate('document.querySelector(".run-health-disclosure summary").click()');
    await evaluate('document.querySelector("[aria-controls=institution-navigation]").focus();document.querySelector("[aria-controls=institution-navigation]").click()');await wait();
    requireValue(await evaluate('document.body.style.overflow==="hidden"'), 'Drawer did not lock background scrolling.');
    await evaluate('(()=>{const n=[...document.querySelectorAll("#institution-navigation a[href],#institution-navigation button")].filter(n=>n.getClientRects().length);n.at(-1).focus()})()');
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    requireValue(await evaluate('document.activeElement===document.querySelector("#institution-navigation a")'), 'Drawer did not trap keyboard focus.');
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await wait();
    requireValue(await evaluate('document.body.style.overflow!=="hidden" && document.activeElement===document.querySelector("[aria-controls=institution-navigation]")'), 'Drawer did not restore scroll and focus.');
    requireValue(await evaluate('getComputedStyle(document.querySelector(".record-table")).overflowX!=="auto"'), 'Mobile records still rely on horizontal scrolling.');

    await evaluate('document.querySelector(".dashboard-readiness").open=true');await wait();
    requireValue(await evaluate('document.querySelector(".dashboard-readiness ol").children.length===4 && document.documentElement.scrollWidth===innerWidth'),'Readiness checklist did not expand accessibly on mobile.');
    const readinessScreenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(path.join(qa,'workflow-readiness-mobile.png'),Buffer.from(readinessScreenshot.data,'base64'));
    await load('ahp-unanswered');
    await evaluate('document.querySelector("[data-ahp-form]").requestSubmit()');await wait();
    requireValue(await evaluate('!document.querySelector("[data-ahp-form]").hasAttribute("aria-busy")'), 'Incomplete AHP judgments were submitted.');
    for(const [winner,strength] of [['a',5],['equal',3],['b',3]]){
        await evaluate(`document.querySelector('input[name=comparison_preference][value=${winner}]').click()`);await wait();
        if(winner!=='equal'){await evaluate(`document.querySelector('#comparison-strength').value='${strength}';document.querySelector('#comparison-strength').dispatchEvent(new Event('change',{bubbles:true}))`);await wait();}
        if(winner!=='b'){await evaluate('[...document.querySelectorAll("[data-ahp-form] button")].find(n=>n.textContent.includes("Next comparison")).click()');await wait();}
    }
    const judgments=JSON.parse(await evaluate('JSON.stringify([...new FormData(document.querySelector("[data-ahp-form]")).entries()].filter(([key])=>key.startsWith("pairs[")))'));
    requireValue(await evaluate('document.querySelector(".judgment-summary").textContent.includes("moderately more important than")'),'AHP judgment did not explain the selected preference and strength.');
    requireValue(judgments.length===3 && Math.abs(Number(judgments[0][1])-5)<1e-12 && Number(judgments[1][1])===1 && Math.abs(Number(judgments[2][1])-1/3)<1e-12,'AHP preference/strength conversion failed.');
    await evaluate('document.addEventListener("submit",e=>e.preventDefault());document.querySelector("[data-ahp-form]").requestSubmit()');
    requireValue(await evaluate('document.querySelector("[data-ahp-form]").getAttribute("aria-busy")==="true" && document.querySelector("[data-ahp-form] button[type=submit]").disabled'), 'AHP loading or duplicate-submission protection failed.');

    await load('assessment-form');
    requireValue(await evaluate('(()=>{const event=new Event("beforeunload",{cancelable:true});window.dispatchEvent(event);return !event.defaultPrevented})()'),'An untouched assessment produced an unsaved warning.');
    await evaluate('document.querySelectorAll("[data-criterion-score]").item(2).scrollIntoView({block:"end",behavior:"instant"})');await wait();
    requireValue(await evaluate('getComputedStyle(document.querySelector(".sticky-actions")).position==="sticky" && document.querySelectorAll("[data-criterion-score]").item(2).getBoundingClientRect().bottom<=document.querySelector(".sticky-actions").getBoundingClientRect().top'),'Mobile actions obscured the assessment score field.');
    const guide=await evaluate('[...document.querySelectorAll("section")].find(el=>el.getAttribute("aria-label")==="Scoring rubric")?.textContent || ""');
    requireValue(guide.includes('1/5') && guide.includes('5/5'),'Assessment did not render all five score descriptions.');
    await evaluate('document.addEventListener("submit",e=>e.preventDefault());[...document.querySelectorAll("button[name=status]")].find(n=>n.value==="draft").click()');
    requireValue(await evaluate('document.querySelector("form[aria-busy]")!==null && new FormData(document.querySelector("form[aria-busy]")).get("status")==="draft"'), 'Loading state lost the assessment draft submitter value.');
    await load('assessment-form');
    await evaluate('document.querySelectorAll(".score-choice")[4].click();document.querySelectorAll(".score-choice")[14].click()');await wait();
    requireValue(await evaluate('document.querySelector("[data-criterion-score]").value==="5"'), 'Quick score controls did not update the submitted score.');
    await evaluate('document.addEventListener("submit",e=>e.preventDefault());[...document.querySelectorAll("button[name=status]")].find(n=>n.value==="completed").click()');await wait();
    requireValue(await evaluate('document.querySelector("#action-confirmation").open'), 'Assessment completion did not explain the immutable-record consequence.');
    requireValue(await evaluate('(()=>{const event=new Event("beforeunload",{cancelable:true});window.dispatchEvent(event);return event.defaultPrevented})()'),'Unsaved scores were unprotected while completion awaited confirmation.');
    await evaluate('document.querySelector("[data-confirm-proceed]").click()');
    requireValue(await evaluate('new FormData(document.querySelector("form[aria-busy]")).get("status")==="completed"'), 'Confirmation/loading lost the completed assessment submitter value.');
    const hiddenCount=await evaluate('document.querySelectorAll("[data-submitter-value]").length');
    await evaluate('document.querySelector("form[aria-busy]").dispatchEvent(new SubmitEvent("submit",{bubbles:true,cancelable:true}))');
    requireValue(await evaluate('document.querySelectorAll("[data-submitter-value]").length')===hiddenCount, 'Duplicate submission was not suppressed.');
    await load('assessment-create');
    const initialSelection=await evaluate('document.querySelector("[data-searchable-select]").value');
    const chosenAsset=await evaluate('(()=>{const select=document.querySelector("[data-searchable-select]");select.value=select.options[1].value;select.dispatchEvent(new Event("change",{bubbles:true}));return select.value})()');
    await evaluate('(()=>{const input=document.querySelector("input[aria-controls$=network_asset_id]");input.value="no-match-test";input.dispatchEvent(new Event("input",{bubbles:true}))})()');await wait();
    requireValue(await evaluate('document.querySelector("[data-searchable-select]").value')===chosenAsset,'Asset search discarded the selection.');
    await evaluate('(()=>{const input=document.querySelector("input[aria-controls$=network_asset_id]");input.value="";input.dispatchEvent(new Event("input",{bubbles:true}))})()');
    await evaluate('document.querySelector("[data-searchable-select]").value='+JSON.stringify(initialSelection));
    requireValue(await evaluate('(()=>{const event=new Event("beforeunload",{cancelable:true});window.dispatchEvent(event);return !event.defaultPrevented})()'),'Restoring the asset selection left a false unsaved warning.');
    await load('assessment-batch');
    requireValue(await evaluate('document.querySelector("[data-batch-form] button[value=draft]").disabled'),'Batch draft save should require a selection.');
    await evaluate('(()=>{const row=document.querySelector("[data-batch-row]");row.querySelector("[data-batch-selected]").click();row.querySelectorAll("[data-batch-score]").forEach(input=>{input.value="4";input.dispatchEvent(new Event("input",{bubbles:true}))})})()');await wait();
    requireValue(await evaluate('!document.querySelector("[data-batch-form] button[value=completed]").disabled'),'A fully scored selected batch row was not ready.');
    await evaluate('(()=>{const row=document.querySelectorAll("[data-batch-row]")[1];const input=row.querySelector("[data-batch-score]");input.value="3";input.dispatchEvent(new Event("input",{bubbles:true}));document.querySelector("[data-batch-form]").requestSubmit(document.querySelector("[data-batch-form] button[value=draft]"))})()');await wait();
    requireValue(await evaluate('!document.querySelector("[data-batch-form]").hasAttribute("aria-busy") && document.querySelector("[data-batch-form] [role=alert]").textContent.includes("unselected rows")'),'Batch save did not protect edits in unchecked rows.');
    await evaluate('[...document.querySelectorAll("[data-batch-form] button")].find(button=>button.textContent.includes("Select rows with changes")).click()');await wait();
    requireValue(await evaluate('document.querySelectorAll("[data-batch-selected]:checked").length===2 && document.querySelector("[data-batch-form] button[value=completed]").disabled'),'Batch changed-row selection or incomplete completion guard failed.');
    await evaluate('document.addEventListener("submit",event=>event.preventDefault());document.querySelector("[data-batch-form]").requestSubmit(document.querySelector("[data-batch-form] button[value=draft]"))');
    requireValue(await evaluate('document.querySelector("[data-batch-form]").getAttribute("aria-busy")==="true" && new FormData(document.querySelector("[data-batch-form]")).get("action")==="draft"'),'Batch draft submission lost its action or loading protection.');
    await load('assessment-batch');
    await evaluate('(()=>{const row=document.querySelector("[data-batch-row]");row.querySelector("[data-batch-selected]").click();row.querySelectorAll("[data-batch-score]").forEach(input=>{input.value="5";input.dispatchEvent(new Event("input",{bubbles:true}))});document.addEventListener("submit",event=>event.preventDefault());})()');await wait();
    await evaluate('document.querySelector("[data-batch-form] button[value=completed]").click()');await wait();
    requireValue(await evaluate('document.querySelector("#action-confirmation").open'),'Batch completion did not request confirmation.');
    await evaluate('document.querySelector("[data-confirm-proceed]").click()');
    requireValue(await evaluate('new FormData(document.querySelector("[data-batch-form]")).get("action")==="completed"'),'Batch completion confirmation lost the action.');
    await load('asset');
    await evaluate('document.querySelector("form[data-confirm-title] button").click()');await wait();
    requireValue(await evaluate('document.querySelector("#action-confirmation").open && document.activeElement.matches("[data-confirm-cancel]")'), 'Destructive confirmation did not open with safe initial focus.');
    await evaluate('document.querySelector("[data-confirm-cancel]").click()');
    requireValue(await evaluate('!document.querySelector("#action-confirmation").open && !document.querySelector("form[data-confirm-title]").hasAttribute("aria-busy")'), 'Canceling confirmation submitted the operation.');
    await load('asset-form');
    await evaluate('document.querySelector("select[name=status]").value="retired";document.querySelector("main form").requestSubmit()');await wait();
    requireValue(await evaluate('document.querySelector("#action-confirmation").open'), 'Retiring an asset did not require confirmation.');
    await evaluate('document.querySelector("[data-confirm-cancel]").click()');
    await load('asset-form');
    await evaluate('(()=>{const input=document.querySelector("[name=name]");window.originalAssetName=input.value;input.value="Changed name";input.dispatchEvent(new Event("input",{bubbles:true}))})()');await wait();
    requireValue(await evaluate('(()=>{const event=new Event("beforeunload",{cancelable:true});window.dispatchEvent(event);return event.defaultPrevented})()'),'Edited assets lacked an unsaved warning.');
    await evaluate('document.querySelector("[name=name]").value=window.originalAssetName');
    requireValue(await evaluate('(()=>{const event=new Event("beforeunload",{cancelable:true});window.dispatchEvent(event);return !event.defaultPrevented})()'),'Reverted edits retained an unsaved warning.');
    await load('asset-validation');
    requireValue(await evaluate('document.querySelector("[name=asset_code]").getAttribute("aria-invalid")==="true" && document.querySelector("[name=asset_code]").getAttribute("aria-describedby").includes("error")'), 'Field validation was not associated accessibly.');
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1100,mobile:false,deviceScaleFactor:1});
    await load('dashboard');
    requireValue(await evaluate('getComputedStyle(document.querySelector(".mobile-cell-label")).display==="none"'), 'Mobile record labels appeared on desktop.');
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    requireValue(await evaluate('parseFloat(getComputedStyle(document.querySelector("#institution-navigation")).transitionDuration)<0.01'), 'Reduced motion preference was ignored.');
    if (errors.length) throw new Error('Browser JavaScript errors: '+errors.join('; '));
    await writeFile(path.join(qa,process.argv.includes('--interactions-only')?'interaction-results.json':'layout-results.json'),JSON.stringify({results,print:printMetrics,interactions:"passed",javascriptErrors:errors},null,2));
    console.log(`${results.length} desktop/mobile page checks passed; no horizontal overflow or JavaScript exceptions. Navigation/focus, mobile cards, AHP conversion, loading, confirmations, validation and reduced-motion checks passed.`);
    await send('Browser.close').catch(()=>{});
} finally {
    socket?.close();
    browser.kill();
}
