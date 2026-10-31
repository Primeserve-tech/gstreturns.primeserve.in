'use strict';
const adminEl=id=>document.getElementById(id);
let loading=false;
const adminCsrf=document.querySelector('meta[name="admin-csrf"]').content;
const shownKeys=new Map();
async function adminPost(path,body){const response=await fetch(path,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':adminCsrf},body:JSON.stringify(body)});if(!response.ok){const data=await response.json().catch(()=>({}));throw new Error(data.error||'The admin request could not be completed.');}return response;}
function keyCell(row){
 const cell=adminNode('td',undefined,'audit-key-cell');cell.dataset.auditKey=row.id;if(row.operation==='ZIP object fetch')cell.title='Originating GST API key used to obtain the signed URL; not sent to ZIP storage.';const label=adminNode('span',row.api_key_masked||'Not captured','audit-key-value');cell.append(label);
 if(Number(row.api_key_available)){
  const button=adminNode('button','Show','text-button');button.type='button';
  const paint=()=>{const value=shownKeys.get(row.id);const visible=value&&value.until>Date.now();label.textContent=visible?value.key:row.api_key_masked;button.textContent=visible?'Hide':'Show';};paint();
  button.addEventListener('click',async()=>{const value=shownKeys.get(row.id);if(value&&value.until>Date.now()){shownKeys.delete(row.id);paint();return;}button.disabled=true;try{const data=await(await adminPost('/admin/api/activity/'+row.id+'/key',{})).json();shownKeys.set(row.id,{key:data.apiKey,until:Date.now()+15000});paint();setTimeout(()=>{shownKeys.delete(row.id);for(const item of document.querySelectorAll('[data-audit-key]'))if(item.dataset.auditKey===row.id){item.querySelector('.audit-key-value').textContent=row.api_key_masked;item.querySelector('button').textContent='Show';}},15000);}catch(error){window.PortalErrors?.reset();window.PortalErrors?.show(error.message);}finally{button.disabled=false;}});cell.append(button);
 }
 return cell;
}
function adminNode(tag,text,className){const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(className)el.className=className;return el;}
async function loadAudit(){
 if(loading)return;loading=true;
 try{
  const params=new URLSearchParams({ip:adminEl('auditIp').value.trim(),outcome:adminEl('auditOutcome').value});
  const response=await fetch('/admin/api/activity?'+params,{credentials:'same-origin',cache:'no-store'});
  const data=await response.json();if(response.status===401){location.href='/admin';return;}if(!response.ok)throw new Error(data.error||'Unable to load activity.');
  const rows=data.rows;adminEl('auditBody').replaceChildren();adminEl('auditEmpty').hidden=rows.length>0;
  adminEl('auditTotal').textContent=rows.length;adminEl('auditSuccess').textContent=rows.filter(r=>['success','completed'].includes(r.outcome)).length;adminEl('auditFailed').textContent=rows.filter(r=>r.outcome==='failed').length;adminEl('auditIps').textContent=new Set(rows.map(r=>r.client_ip)).size;
  adminEl('adminMode').textContent=data.mode.toUpperCase()+' MODE';
  adminEl('adminNotice').textContent=`Auto-updating every 5 seconds. Each return expires 10 minutes after its first successful ZIP download; content-free IP/status records are retained for ${data.retentionDays} days.`;adminEl('adminNotice').className='notice';
  for(const row of rows){const tr=adminNode('tr');tr.append(adminNode('td',new Date(row.started*1000).toLocaleString('en-IN',{timeZone:'Asia/Kolkata',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',second:'2-digit'})),adminNode('td',row.client_ip),keyCell(row));const op=adminNode('td',row.operation);if(row.return_type)op.append(adminNode('small',row.return_type));tr.append(op);const path=adminNode('td',row.method);path.append(adminNode('small',row.path));tr.append(path,adminNode('td',row.http_status===null?'—':String(row.http_status)));const outcome=adminNode('td');outcome.append(adminNode('span',row.outcome.replaceAll('_',' '),'status-badge '+row.outcome));if(row.mode==='fixture')outcome.append(adminNode('small','LOCAL FIXTURE'));tr.append(outcome,adminNode('td',row.duration_ms===null?'—':row.duration_ms+' ms'));adminEl('auditBody').append(tr);}
  adminEl('auditFooter').textContent=`Showing up to 500 matching calls · Signed URL query strings and request/response bodies are excluded · Last update ${new Date().toLocaleTimeString('en-IN')}`;
 }catch(error){adminEl('adminNotice').textContent='Unable to refresh API activity.';adminEl('adminNotice').className='notice error';window.PortalErrors?.show(error.message);}finally{loading=false;}
}
adminEl('exportAudit').addEventListener('click',async()=>{const button=adminEl('exportAudit');if(button.disabled)return;button.disabled=true;button.textContent='Preparing Excel…';try{const response=await adminPost('/admin/export',{ip:adminEl('auditIp').value.trim(),outcome:adminEl('auditOutcome').value,includeKeys:adminEl('exportFullKeys').checked});const blob=await response.blob();const name=response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]||'PrimeServe_API_Logs.xlsx';const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=name;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),10000);}catch(error){window.PortalErrors?.reset();window.PortalErrors?.show(error.message);}finally{button.disabled=false;button.textContent='Download Excel';}});
adminEl('auditFilters').addEventListener('submit',event=>{event.preventDefault();window.PortalErrors?.reset();loadAudit();});adminEl('auditReset').addEventListener('click',()=>{adminEl('auditIp').value='';adminEl('auditOutcome').value='';loadAudit();});setInterval(()=>{if(!document.hidden)loadAudit();},5000);loadAudit();
