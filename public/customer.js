'use strict';
(()=>{
const el=id=>document.getElementById(id),notice=message=>{el('accountNotice').textContent=message;};
const registrationSuccess=el('registrationSuccessDialog');
if(registrationSuccess){
  const close=()=>registrationSuccess.close();
  el('registrationSuccessLogin').addEventListener('click',close);
  el('registrationSuccessClose').addEventListener('click',close);
  registrationSuccess.addEventListener('close',()=>el('email')?.focus());
  registrationSuccess.showModal();
}
async function call(action,data){const r=await fetch('/customer/'+action,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="customer-csrf"]').content},body:JSON.stringify(data)});const json=await r.json();if(!r.ok)throw Error(json.error||'Account request failed.');if(json.redirect)location.assign(json.redirect);if(json.message)notice(json.message);if(['billing-details','key','recharge'].includes(action))window.PortalSuccess?.show(json.message||'Your changes have been saved.');return json;}
const titles={register:'Create your account',login:'Customer login',forgot:'Reset your password',reset:'Choose a new password'};if(titles[document.body.dataset.screen]&&el('accountTitle'))el('accountTitle').textContent=titles[document.body.dataset.screen];
function showError(error){el('accountNotice').textContent='';if(window.PortalErrors)window.PortalErrors.show(error.message);else notice(error.message);}
let attempt='';
function bind(id,fn){el(id)?.addEventListener('submit',async e=>{e.preventDefault();const b=e.submitter;if(b.disabled)return;b.disabled=true;window.PortalErrors?.reset();try{await fn();}catch(err){showError(err);}finally{b.disabled=false;}});}
bind('registerForm',async()=>{if(!/^(?:\+91)?[6-9][0-9]{9}$/.test(el('mobile').value))throw Error('Enter a valid Indian mobile number.');if(el('password').value!==el('confirm').value)throw Error('Passwords do not match.');const d={};for(const key of ['name','company','email','mobile','password','confirm'])d[key]=el(key).value;const r=await call('register',d);attempt=r.attempt;el('verifyForm').hidden=false;el('emailOtp').focus();});
bind('verifyForm',async()=>{const otp=el('emailOtp').value;el('emailOtp').value='';await call('verify',{attempt,otp});});
bind('loginForm',async()=>{const password=el('password').value;el('password').value='';await call('login',{email:el('email').value,password});});
bind('forgotForm',()=>call('forgot',{email:el('email').value}));
const resetToken=location.hash.slice(1);if(document.body.dataset.screen==='reset')history.replaceState(null,'',location.pathname);
bind('resetForm',async()=>{const password=el('password').value;el('password').value='';await call('reset',{password,token:resetToken});});
bind('sessionKeyForm',async()=>{const key=el('sessionKey').value;el('sessionKey').value='';await call('key',{key});});
bind('billingDetailsForm',()=>call('billing-details',{state:el('billingState').value,gstin:el('billingGstin').value}));
bind('rechargeForm',async()=>{await call('recharge',{amount:el('amount').value,utr:el('utr').value});await load();});
el('customerLogout')?.addEventListener('click',()=>call('logout',{}).catch(showError));
const money=n=>'₹'+(Number(n)/100).toFixed(2);
function table(title,rows,action=null,labels={}){
  const heading=document.createElement('h2');heading.textContent=title;el('accountTables').append(heading);
  if(['Wallet Transaction History','API Charges','API Usage History'].includes(title)){
    const form=document.createElement('form');form.className='form-grid';
    const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Kolkata',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const dates={};for(const [key,label]of [['from','From date'],['to','To date']]){const field=document.createElement('label');field.className='field';field.textContent=label;const input=document.createElement('input');input.type='date';input.required=true;input.value=key==='from'?today.slice(0,7)+'-01':today;field.append(input);form.append(field);dates[key]=input;}
    const button=document.createElement('button');button.className='secondary-action';button.textContent='Download Excel';form.append(button);el('accountTables').append(form);
    form.addEventListener('submit',async event=>{event.preventDefault();if(button.disabled)return;button.disabled=true;try{if(dates.from.value>dates.to.value)throw Error('From date must be before or equal to To date.');const response=await fetch('/customer/history-export',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="customer-csrf"]').content},body:JSON.stringify({kind:title==='Wallet Transaction History'?'wallet':'usage',from:dates.from.value,to:dates.to.value})});if(!response.ok){const d=await response.json();throw Error(d.error||'Unable to export history.');}const url=URL.createObjectURL(await response.blob());const link=document.createElement('a');link.href=url;link.download='Primeserve_'+(title==='Wallet Transaction History'?'Wallet':'API_Charges')+'_'+dates.from.value+'_'+dates.to.value+'.xlsx';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),10000);}catch(error){showError(error);}finally{button.disabled=false;}});
  }
  if(!rows.length){const empty=document.createElement('p');empty.textContent='No records yet.';el('accountTables').append(empty);return;}
  const wrap=document.createElement('div');wrap.className='table-scroll';const t=document.createElement('table');const head=t.createTHead().insertRow();
  for(const key of [...Object.keys(rows[0]),...(action?['Action']:[])]){const th=document.createElement('th');th.textContent=labels[key]||key.replaceAll('_',' ');head.append(th);}
  for(const row of rows){const tr=t.insertRow();for(const[key,value]of Object.entries(row))tr.insertCell().textContent=['amount','balance_after','net','tax','gross'].includes(key)?money(value):key==='created'&&value?new Date(Number(value)*1000).toLocaleString('en-IN',{timeZone:'Asia/Kolkata'}):String(value);if(action)tr.insertCell().append(action(row));}
  wrap.append(t);el('accountTables').append(wrap);
}
function statementButton(row){
  const button=document.createElement('button');button.type='button';button.className='secondary-action';button.textContent='Download statement';
  button.addEventListener('click',async()=>{if(button.disabled)return;button.disabled=true;try{
    const response=await fetch('/customer/statement',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="customer-csrf"]').content},body:JSON.stringify({month:row.month})});
    if(!response.ok){const data=await response.json();throw Error(data.error||'Unable to download the statement.');}
    if(!response.headers.get('Content-Type')?.includes('text/csv'))throw Error('Unexpected statement response.');
    const url=URL.createObjectURL(await response.blob());const link=document.createElement('a');link.href=url;link.download='Primeserve_Statement_'+row.month+'.csv';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),10000);
  }catch(error){showError(error);}finally{button.disabled=false;}});return button;
}
async function load(){
  const r=await call('state',{});const d=r.dashboard;
  if(!d||el('accountSummary').dataset.billingMode!==d.mode){location.reload();return;}
  el('accountTitle').textContent='My Account';el('accountSummary').replaceChildren();
  for(const card of d.cards){const metric=document.createElement('div');metric.className='metric';const label=document.createElement('span');label.textContent=card.label;const value=document.createElement('strong');value.textContent=card.kind==='money'?money(card.value):card.value+' days';metric.append(label,value);if(card.detail){const detail=document.createElement('small');detail.textContent=card.detail;metric.append(detail);}el('accountSummary').append(metric);}
  el('accountTables').replaceChildren();
  if(d.sections.wallet){
    el('upiDetails').textContent=r.upi?.id?`${r.upi.name} · UPI: ${r.upi.id}`:'Payment details are not configured. Contact support before making a payment.';el('rechargeForm').hidden=!r.upi?.id;el('upiQrPanel').hidden=!r.upi?.qrDataUri;if(r.upi?.qrDataUri){el('upiQrImage').src=r.upi.qrDataUri;el('upiAppLink').href=r.upi.intent;}
    table('Wallet Transaction History',d.transactions);table('API Charges',d.usage);table('Recharge Requests',d.recharges);
  }
  if(d.sections.postpaid){table('Billing Statements',d.statements,statementButton);table('API Usage History',d.usage);}
  table('Your Fixed API Prices',r.prices.map(({service,net,tax,gross,unit})=>({service,net,tax,gross,unit})),null,{net:'Gross',tax:'Tax',gross:'Net'});
  if(location.hash==='#profile')el('profile').scrollIntoView({block:'start'});
}
if(document.body.dataset.screen==='account')load().catch(showError);
})();
