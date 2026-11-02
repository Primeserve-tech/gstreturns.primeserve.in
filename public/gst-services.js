'use strict';
(() => {
  const service = document.getElementById('gstService');
  const el = id => document.getElementById(id);
  GSTCalendar.bind(el('challanYear'),el('challanMonth'),false,period);
  let documentBlob=null, documentUrl=null, responseRows=[], filename='', expiry=null;
  function clearResponse(){documentBlob=null;responseRows=[];if(documentUrl)URL.revokeObjectURL(documentUrl);documentUrl=null;if(expiry)clearTimeout(expiry);el('serviceResponse').hidden=true;el('serviceResponseBody').replaceChildren();}
  function period() {
    const yearly = el('challanScope').value === 'yearly';
    el('challanMonthField').hidden = yearly;
    el('challanYearLabel').textContent = 'Calendar Year';
    el('challanPeriodHelp').textContent = yearly
      ? 'Choose the calendar year for the yearly challan request.'
      : `The API period will be ${el('challanMonth').value}${el('challanYear').value} (MMYYYY).`;
  }
  function changeService() {
    if(service.value.startsWith('challan-'))el('challanScope').value=service.value.slice(8);
    const returns = service.value === 'returns', certificate = service.value === 'certificate';
    el('returnsService').hidden = !returns; el('additionalService').hidden = returns;
    el('challanPeriodFields').hidden = certificate; el('challanPeriodHelp').hidden = certificate;
    el('additionalServiceTitle').textContent = certificate ? 'GST Certificate Download' : 'GST Challan Download';
    el('additionalServiceDescription').textContent = certificate
      ? 'Retrieve your GST registration certificate using the provider’s certificate service.'
      : 'Choose monthly or yearly challan information for your GST account.';
    el('getAdditionalService').textContent = certificate ? 'Get Certificate' : 'Get Challan';
    clearResponse();
    // Never copy secrets between forms or persist passwords.
    el('serviceApiKey').value = ''; el('servicePassword').value = '';
    const connection = typeof state === 'undefined' ? null : state?.connection;
    el('serviceUsername').value = connection?.username || ''; el('serviceGstin').value = connection?.gstin || '';
    document.querySelector('.page-heading h1').textContent = returns ? 'GST Return Download Portal' : el('additionalServiceTitle').textContent;
    document.querySelector('.page-subtitle').textContent = returns ? 'Connect your GST account. Select your returns. Download in one place.' : el('additionalServiceDescription').textContent;
    // The returns Download Centre remains reachable while another service is selected.
    document.querySelector('.portal-tag').hidden = !returns;
    period();
  }
  service.addEventListener('change', changeService);
  for (const id of ['challanScope','challanYear','challanMonth']) el(id).addEventListener('change',()=>{if(id==='challanScope'&&service.value!=='returns'&&service.value!=='certificate')service.value='challan-'+el('challanScope').value;period();});
  document.querySelectorAll('.portal-nav a').forEach(link => link.addEventListener('click', () => {service.value = 'returns'; changeService();}));
  function save(blob,name){const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=name;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),10000);}
  function flatten(value,path='$',rows=[]){
    if(rows.length>=5000)return rows;
    if(value!==null&&typeof value==='object') {const entries=Object.entries(value);if(!entries.length)rows.push([path,Array.isArray(value)?'[]':'{}']);for(const [key,item] of entries){if(rows.length>=5000)break;flatten(item,`${path}.${key}`,rows);}}
    else rows.push([path,value===null?'null':String(value)]);
    return rows;
  }
  el('saveServiceResponse').addEventListener('click',()=>{if(documentBlob)save(documentBlob,filename);});
  el('exportServiceCsv').addEventListener('click',()=>{
    const cell=v=>'"'+String(/^[=+@\t\r-]/.test(v)?"'"+v:v).replaceAll('"','""')+'"';
    const text=[['Response field','Value'],...responseRows].map(row=>row.map(cell).join(',')).join('\r\n');save(new Blob(['\ufeff'+text],{type:'text/csv;charset=utf-8'}),'GST_challan_response.csv');
  });
  el('additionalServiceForm').addEventListener('submit',async event=>{
    event.preventDefault();const button=el('getAdditionalService');if(button.disabled)return;
    clearResponse();button.disabled=true;button.classList.add('busy');button.setAttribute('aria-busy','true');service.disabled=true;
    const payload={service:service.value==='certificate'?'certificate':`challan-${el('challanScope').value}`,apiKey:el('serviceApiKey').value,username:el('serviceUsername').value,gstin:el('serviceGstin').value,password:el('servicePassword').value,year:el('challanYear').value,month:el('challanMonth').value,downloadFormat:'zip'};
    el('servicePassword').value='';el('serviceApiKey').value='';
    try {
      if(window.PrimeserveBilling){payload.confirmation=await window.PrimeserveBilling.confirm(payload.service,1);payload.requestKey=crypto.randomUUID();}
      const response=await fetch('/api/documents',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(payload)});
      if(!response.ok){const error=await response.json().catch(()=>({}));throw new Error(error.error||'The request could not be completed.');}
      const format=response.headers.get('X-GST-Document-Format');if(!['json','pdf','zip','xlsx'].includes(format))throw new Error('Unexpected document response.');
      documentBlob=await response.blob();filename=response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]||`GST_document.${format}`;
      el('serviceResponse').hidden=false;el('exportServiceCsv').hidden=format!=='json'||service.value!=='challan';
      el('saveServiceResponse').textContent=format==='pdf'?'Download GST Certificate':format==='zip'?'Download ZIP':format==='xlsx'?'Download Excel':'Download JSON response';
      if(format==='json') {
        const json=JSON.parse(await documentBlob.text());responseRows=flatten(json);
        if(json?.error){const detail=typeof json.error==='string'?json.error:json.error.message;window.PortalErrors?.show(typeof detail==='string'?detail.slice(0,600):'Primeserve reported an error. Review the provider response below.');}
        for(const [key,value] of responseRows){const tr=document.createElement('tr');for(const text of [key,value]){const td=document.createElement('td');td.textContent=text;tr.append(td);}el('serviceResponseBody').append(tr);}
        el('serviceResponseMessage').textContent='Primeserve returned the response below. Review its status and details; receipt does not confirm a successful GST operation. JSON export preserves the response; CSV exports up to 5,000 displayed fields. File links, if returned, appear as text until their download host and format are verified.';
      } else el('serviceResponseMessage').textContent='The provider returned a '+format.toUpperCase()+' file. Use the download button to save it.';
      if(format!=='json'||!JSON.parse(await documentBlob.text())?.error)save(documentBlob,filename);
      expiry=setTimeout(clearResponse,600000);
      el('serviceResponse').scrollIntoView({behavior:'smooth',block:'start'});
    }catch(error){window.PortalErrors?.show(error.message||'Unable to complete this GST request.');}
    finally{payload.password='';payload.apiKey='';button.disabled=false;service.disabled=false;button.classList.remove('busy');button.removeAttribute('aria-busy');}
  });
  changeService();
})();
