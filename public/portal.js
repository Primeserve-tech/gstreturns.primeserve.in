'use strict';
const $ = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const types = ['GSTR-1','GSTR-1A','GSTR-2A','GSTR-2B','GSTR-3B','GSTR-6','GSTR-6A','GSTR-9'];
let state = null, busy = false, refreshing = false, selectedBatch = '', requestKey = null, lastConnectionState = null;
let returnDeadline=null;
let stateFetchedAt=performance.now();
let downloadFeedback=false;
const knownJobErrors=new Map();
let hasJobSnapshot=false;
function serverNow(){return (state?.serverTime||Date.now()/1000)+(performance.now()-stateFetchedAt)/1000;}
function chosenMonths(){
  if($('downloadScope').value==='monthly')return[$('month').value];
  const first=Number($('financialYear').value.slice(0,4));
  const parts=new Intl.DateTimeFormat('en',{timeZone:'Asia/Kolkata',year:'numeric',month:'2-digit'}).formatToParts(new Date());
  const nowYM=Number(parts.find(p=>p.type==='year').value)*100+Number(parts.find(p=>p.type==='month').value);
  return ['04','05','06','07','08','09','10','11','12','01','02','03'].filter(m=>(first+(Number(m)<4?1:0))*100+Number(m)<=nowYM);
}
const terminal = new Set(['completed','failed','attention','timeout','monitoring_error','empty']);
const active = new Set(['queued','initiating','processing']);
const statusLabels = {queued:'Queued',initiating:'Initiating',processing:'Processing',completed:'Completed',failed:'Failed',attention:'Review required',timeout:'Timed out',monitoring_error:'Checks paused',empty:'No data'};

function notice(message, error = false) {
  if(error){window.PortalErrors?.show(message);$('notice').hidden=true;if(downloadFeedback)$('downloadNotice').hidden=true;return;}
  $('notice').textContent = message; $('notice').className = 'notice' + (error ? ' error' : ''); $('notice').hidden = false;
  if(downloadFeedback){$('downloadNotice').textContent=message;$('downloadNotice').className='notice download-notice'+(error?' error':'');$('downloadNotice').hidden=false;}
}
function showError(error) { notice(error.message || 'Unable to complete the request.', true); }
async function api(path, data, download = false) {
  const response = await fetch(path, {method:data === undefined ? 'GET' : 'POST', credentials:'same-origin', cache:'no-store', headers: data === undefined ? {} : {'Content-Type':'application/json','X-CSRF-Token':csrf}, body:data === undefined ? undefined : JSON.stringify(data)});
  if (!response.ok) {
    const json = await response.json().catch(() => ({}));
    throw new Error(json.error || (response.status === 429 ? 'Too many requests. Please wait.' : 'The server could not complete the request.'));
  }
  if (download) {
    if (!response.headers.get('Content-Type')?.includes('application/zip')) throw new Error('The download response was not a ZIP file.');
    const blob = await response.blob();
    const receipt=response.headers.get('X-Download-Receipt');
    if(!receipt)throw new Error('The server did not provide a download confirmation receipt.');
    await api('/api/downloads/ack',{receipt});
    const disposition = response.headers.get('Content-Disposition') || '';
    const filename = disposition.match(/filename="([^"]+)"/)?.[1] || 'PrimeServe_returns.zip';
    const url = URL.createObjectURL(blob), link = document.createElement('a');
    link.href = url; link.download = filename; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 10000);
    return;
  }
  return response.json();
}
async function action(button, operation) {
  if (busy) return;
  window.PortalErrors?.reset();
  downloadFeedback=button.id==='downloadAll'||button.dataset.download==='true';
  if(downloadFeedback){$('downloadNotice').textContent='Preparing your ZIP download…';$('downloadNotice').className='notice download-notice';$('downloadNotice').hidden=false;}
  busy = true; button.classList.add('busy'); button.setAttribute('aria-busy','true');
  const previous = button.disabled; button.disabled = true;
  try { await operation(); await refresh(); }
  catch (error) { showError(error); }
  finally { busy = false; downloadFeedback=false;button.classList.remove('busy'); button.removeAttribute('aria-busy'); button.disabled = previous; updateSelection(); }
}
function selectedTypes() { return [...document.querySelectorAll('.return-choice input:checked')].map(input => input.value); }
function currentJobs() {
  const batch = selectedBatch || state?.jobs[0]?.batch_id;
  return (state?.jobs || []).filter(job => job.batch_id === batch);
}
function updateSelection() {
  const count = selectedTypes().length;
  $('selectedCount').textContent = `${count} selected`;
  const connected = state?.connection?.state === 'connected';
  const running = (state?.jobs || []).some(job => active.has(job.status));
  $('selectionFields').disabled = !connected || !state?.ready || running || busy;
  $('startDownload').disabled = !connected || !count || running || busy || !state?.ready;
  $('selectAll').disabled = count === 8;
  $('deselectAll').disabled = count === 0;
  const yearly=$('downloadScope').value==='yearly';
  $('month').disabled=yearly;$('monthField').hidden=yearly;
  $('periodSelectionGrid').classList.toggle('yearly',yearly);
  const months=chosenMonths(),requests=count*months.length;
  $('requestPreview').textContent=yearly?`${count} return types × ${months.length} monthly periods = ${requests} API requests. Yearly downloads collect the financial year's monthly ZIPs; future periods are excluded.`:`${count} return types × 1 month = ${requests} API requests. The API period is filled automatically.`;
  if (running) $('startDownload').innerHTML = '<svg><use href="#i-clock"/></svg>Batch in progress';
  else { $('startDownload').replaceChildren();const icon=document.createElementNS('http://www.w3.org/2000/svg','svg');const use=document.createElementNS('http://www.w3.org/2000/svg','use');use.setAttribute('href','#i-download');icon.append(use);$('startDownload').append(icon,document.createTextNode(`Download Selected Returns${requests?' ('+requests+')':''}`)); }
  const jobs = currentJobs();
  $('downloadAll').disabled = busy || !jobs.some(job => job.status === 'completed') || !connected;
}
function node(tag, text, className) {
  const element = document.createElement(tag); if (text !== undefined) element.textContent = text; if (className) element.className = className; return element;
}
function renderJobs() {
  const jobs = currentJobs(), body = $('jobsBody'); body.replaceChildren();
  const expiries=jobs.filter(job=>job.expires_at).map(job=>Number(job.expires_at));
  returnDeadline=expiries.length?performance.now()+Math.max(0,Math.min(...expiries)-serverNow())*1000:null;
  $('retentionBanner').hidden=returnDeadline===null;
  $('emptyState').hidden = jobs.length > 0;
  $('tableCount').textContent = jobs.length ? `${jobs.length} returns · ${jobs.filter(job => job.status === 'completed').length} ready to download` : 'No return requests yet';
  for (const job of jobs) {
    const row = node('tr'); row.dataset.job = job.id;
    row.append(node('td', job.return_type), node('td', job.gstin));
    const period = node('td', job.financial_year); period.append(node('small', job.period)); row.append(period);
    row.append(node('td', job.job_id || 'Awaiting initiation', 'job-id'));
    const status = node('td'); status.append(node('span', statusLabels[job.status] || job.status, `status-badge ${job.status}`));
    if (job.message) status.append(node('p', job.message, 'row-message')); row.append(status);
    if(job.expires_at)status.append(node('small',`Deletes at ${new Date(job.expires_at*1000).toLocaleTimeString('en-IN')}`));
    const cell = node('td');
    if (job.status === 'completed') {
      const button = node('button','Download ZIP','table-action'); button.type='button'; button.disabled = busy;
      button.dataset.download='true';
      button.addEventListener('click',() => action(button,async () => { await api(`/api/jobs/${job.id}/download`,{},true); notice(`${job.return_type} ZIP downloaded.`); })); cell.append(button);
    } else if (Number(job.retry_allowed)) {
      const resume = ['timeout','monitoring_error'].includes(job.status);
      const button = node('button',resume ? 'Resume checks' : 'Retry return','table-action'); button.type='button'; button.disabled = busy;
      button.addEventListener('click',() => {
        if (!resume && !window.confirm(`Initiate ${job.return_type} again? A new request may incur an API charge. Only this failed return will be retried.`)) return;
        action(button,async () => { const result=await api(`/api/jobs/${job.id}/retry`,{}); notice(result.message); });
      }); cell.append(button);
    } else cell.append(node('span',job.status === 'attention' ? 'Check provider' : job.status === 'empty' ? 'No ZIP available' : job.status === 'failed' ? 'Retry unavailable' : 'Waiting','waiting-label'));
    row.append(cell); body.append(row);
  }
  const total=jobs.length, completed=jobs.filter(job=>job.status==='completed').length, finished=jobs.filter(job=>terminal.has(job.status)).length;
  $('metricTotal').textContent=total;
  $('metricInitiated').textContent=jobs.filter(job=>job.job_id).length;
  $('metricProcessing').textContent=jobs.filter(job=>['processing','initiating'].includes(job.status)).length;
  $('metricCompleted').textContent=completed;
  $('metricFailed').textContent=jobs.filter(job=>['failed','attention','timeout','monitoring_error'].includes(job.status)).length;
  $('batchProgress').hidden=!total;
  const percent=total ? Math.round(finished/total*100) : 0;
  $('progressLabel').textContent=finished===total ? `${completed} completed · ${total-completed} other outcomes` : `${finished} of ${total} returns finished · Requests run sequentially`;
  $('progressPercent').textContent=`${percent}%`; $('progressBar').style.width=`${percent}%`; $('progressBar').parentElement.setAttribute('aria-valuenow',percent);
  for(let i=1;i<=4;i++) $('step'+i).className='';
  const step=!state?.connection || state.connection.state!=='connected' ? 1 : !total ? 2 : finished<total ? 3 : 4;
  for(let i=1;i<=4;i++) $('step'+i).className=i===step ? 'current' : i<step ? 'done' : '';
}
function render() {
  const conn=state.connection, connected=conn?.state==='connected', pending=conn?.state==='otp_pending';
  $('modeBanner').hidden=state.mode!=='fixture'&&state.ready;
  if (state.mode==='fixture') {
    $('modeBanner').innerHTML='<strong>LOCAL TEST MODE</strong> — No production API calls. Use OTP <strong>123456</strong>. Downloaded ZIPs contain labelled test files, not GST returns.';
  } else if (!state.ready) $('modeBanner').textContent='API integration is awaiting the verified response and ZIP download contract. Production requests are disabled.';
  else { $('modeBanner').textContent=''; }
  for(const job of state.jobs){
    const signature=job.status+':'+job.message;
    if(hasJobSnapshot&&(['failed','attention','timeout','monitoring_error'].includes(job.status)||Number(job.poll_errors)>0)&&knownJobErrors.get(job.id)!==signature)window.PortalErrors?.show(`${job.return_type} (${job.period}): ${job.message||statusLabels[job.status]}`,{key:'job:'+job.id+':'+signature});
    knownJobErrors.set(job.id,signature);
  }
  hasJobSnapshot=true;
  $('authForm').hidden=connected || pending;
  $('otpForm').hidden=!pending;
  $('connectedSummary').hidden=!connected;
  $('connectionPill').className='nav-status'+(connected?' connected':'');
  $('connectionPill').replaceChildren(node('span',undefined,'status-dot'),document.createTextNode(connected ? 'GST connected' : pending ? 'OTP verification pending' : 'GST not connected'));
  $('authBadge').textContent=connected?'Connected':pending?'Verify OTP':'Not connected';
  $('authBadge').className='badge '+(connected?'badge-success':'badge-neutral');
  $('selectionLock').hidden=connected && state.ready;
  if (conn) { $('username').value=conn.username; $('gstin').value=conn.gstin; }
  if (connected) {
    $('connectedDetails').textContent=`${conn.gstin} · ${conn.username}`;
    $('connectedExpiry').textContent=`Connection valid until ${new Date(conn.expires*1000).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'})}`;
    $('apiKey').value=''; $('otp').value='';
    if (lastConnectionState==='otp_pending') $('return-selection').scrollIntoView({behavior:'smooth',block:'start'});
  }
  $('requestOtp').disabled=!state.ready || busy;
  $('pollInterval').textContent=`${state.pollSeconds} seconds`;
  $('retentionDays').textContent='10 minutes';
  $('liveIndicator').className='live-indicator'+(state.workerOnline?' online':'');
  $('liveIndicator').replaceChildren(node('span',undefined,'status-dot'),document.createTextNode(state.workerOnline?'Worker online · Auto-updating':'Worker offline · Jobs will wait'));
  $('periodNote').textContent='Periods are generated automatically as MMYYYY (for example, April 2026 → 042026). Each return’s 10-minute deletion timer starts only after its first successful ZIP download.';
  for(const type of types) {
    const label=document.querySelector(`[data-return="${type}"] small`);
    label.textContent='Automatic period';
  }
  const batches=[...new Set(state.jobs.map(job=>job.batch_id))];
  if(selectedBatch && !batches.includes(selectedBatch)) selectedBatch='';
  const filter=$('batchFilter'); filter.replaceChildren();
  for(const [index,id] of batches.entries()) {
    const job=state.jobs.find(item=>item.batch_id===id);
    const time=new Date(job.created*1000).toLocaleString('en-IN',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
    const option=node('option',`${index===0?'Latest · ':''}${time} · ${id.slice(0,6)}`); option.value=id; filter.append(option);
  }
  if(!batches.length) filter.append(node('option','No batches yet'));
  if(selectedBatch) filter.value=selectedBatch;
  renderJobs(); updateSelection(); lastConnectionState=conn?.state || 'none';
}
async function refresh() {
  if (refreshing) return; refreshing=true;
  try { state=await api('/api/state');stateFetchedAt=performance.now(); render(); }
  catch(error) { $('liveIndicator').textContent='Connection interrupted · Reconnecting'; showError(error); }
  finally { refreshing=false; }
}

const today=new Date(), currentYear=today.getMonth()>=3?today.getFullYear():today.getFullYear()-1;
for(let year=currentYear;year>=2017;year--) { const value=`${year}-${String(year+1).slice(-2)}`; const option=node('option',value); option.value=value; $('financialYear').append(option); }
$('month').value=String(today.getMonth()+1).padStart(2,'0');
for(const type of types) {
  const label=node('label',undefined,'return-choice'); label.dataset.return=type;
  const input=node('input'); input.type='checkbox'; input.value=type; input.addEventListener('change',updateSelection);
  const text=node('span'); text.append(node('strong',type),node('small','Period unverified')); label.append(input,text); $('returnChoices').append(label);
}
$('selectAll').addEventListener('click',()=>{ document.querySelectorAll('.return-choice input').forEach(input=>input.checked=true); updateSelection(); });
$('deselectAll').addEventListener('click',()=>{ document.querySelectorAll('.return-choice input').forEach(input=>input.checked=false); updateSelection(); });
for(const id of ['downloadScope','financialYear','month'])$(id).addEventListener('change',()=>{requestKey=null;updateSelection();});
$('revealKey').addEventListener('click',()=>{ const show=$('apiKey').type==='password'; $('apiKey').type=show?'text':'password'; $('revealKey').textContent=show?'Hide':'Show'; $('revealKey').setAttribute('aria-label',show?'Hide API key':'Show API key'); });
$('gstin').addEventListener('input',()=>{ $('gstin').value=$('gstin').value.toUpperCase(); });
$('authForm').addEventListener('submit',event=>{ event.preventDefault(); action($('requestOtp'),async()=>{ const result=await api('/api/otp',{apiKey:$('apiKey').value,username:$('username').value,gstin:$('gstin').value}); $('apiKey').value=''; notice(result.message); }); });
$('otpForm').addEventListener('submit',event=>{ event.preventDefault(); action($('connectGst'),async()=>{ const otp=$('otp').value; $('otp').value=''; const result=await api('/api/connect',{otp}); notice(result.message); }); });
$('resendOtp').addEventListener('click',()=>action($('resendOtp'),async()=>{ const result=await api('/api/otp',{resend:true}); notice(result.message); }));
$('resetOtp').addEventListener('click',()=>action($('resetOtp'),async()=>{ await api('/api/disconnect',{}); $('otp').value=''; notice('Enter the GST account details again to request a new OTP.'); }));
$('disconnect').addEventListener('click',()=>action($('disconnect'),async()=>{ const result=await api('/api/disconnect',{}); notice(result.message); }));
$('startDownload').addEventListener('click',()=>action($('startDownload'),async()=>{
  requestKey ||= crypto.randomUUID();
  const result=await api('/api/batches',{returns:selectedTypes(),financialYear:$('financialYear').value,month:$('month').value,scope:$('downloadScope').value,requestKey});
  selectedBatch=result.batchId; requestKey=null; notice(result.message); $('processingTitle').scrollIntoView({behavior:'smooth',block:'start'});
}));
$('downloadAll').addEventListener('click',()=>action($('downloadAll'),async()=>{ await api('/api/download-all',{batchId:selectedBatch || state.jobs[0]?.batch_id},true); notice('Combined archive downloaded. Each individual ZIP is preserved unchanged.'); }));
$('batchFilter').addEventListener('change',()=>{ selectedBatch=$('batchFilter').value; renderJobs(); updateSelection(); });
setInterval(()=>{
  if(returnDeadline!==null){const left=Math.max(0,Math.ceil((returnDeadline-performance.now())/1000));$('retentionCountdown').textContent=`${Math.floor(left/60)}:${String(left%60).padStart(2,'0')}`;if(!left){returnDeadline=null;if(state)state.jobs=state.jobs.filter(job=>!job.expires_at||Number(job.expires_at)>serverNow());renderJobs();updateSelection();notice('Downloaded returns whose 10-minute timers expired have been deleted. Undownloaded returns remain available.');refresh();}}
  if(state?.connection?.state==='otp_pending') {
    const seconds=Math.max(0,state.connection.resendAt-Math.floor(Date.now()/1000));
    $('resendOtp').textContent=seconds?`Resend in ${seconds}s`:'Resend OTP'; $('resendOtp').disabled=!!seconds || busy;
  }
},1000);
setInterval(()=>{if(!document.hidden && !busy) refresh();},3000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
window.addEventListener('pageshow',()=>{if(state)state.jobs=state.jobs.filter(job=>!job.expires_at||Number(job.expires_at)>serverNow());renderJobs();refresh();});
refresh();
