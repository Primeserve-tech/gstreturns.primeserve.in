"""HTTP admin auth and real janitor expiry checks, against the fixture server only."""
import json,re,time,sqlite3,os,uuid
from pathlib import Path
from urllib.request import build_opener,HTTPCookieProcessor,Request
from urllib.error import HTTPError
from http.cookiejar import CookieJar

BASE=os.environ.get('GST_TEST_BASE','http://127.0.0.1:8085')
assert BASE.startswith('http://127.0.0.1:')
root=Path(__file__).resolve().parents[1]
credentials={'username':os.environ.get('GST_TEST_ADMIN_USERNAME',''),'password':os.environ.get('GST_TEST_ADMIN_PASSWORD','')}
assert all(credentials.values()), 'Set GST_TEST_ADMIN_USERNAME and GST_TEST_ADMIN_PASSWORD in the test process environment; plaintext credential files are not used.'
cookies=CookieJar();opener=build_opener(HTTPCookieProcessor(cookies))
def call(path,data=None,csrf=None):
    headers={'Content-Type':'application/json'}
    if csrf:headers['X-CSRF-Token']=csrf
    request=Request(BASE+path,data=None if data is None else json.dumps(data).encode(),headers=headers)
    try:
        response=opener.open(request);return response.status,json.loads(response.read())
    except HTTPError as error:return error.code,json.loads(error.read())
assert call('/api/state')[1]['mode']=='fixture','Never run expiry mutation tests against live storage'
assert call('/admin/api/activity')[0]==401,'Admin authentication required'
page=opener.open(BASE).read().decode();csrf=re.search('name="csrf-token" content="([^"]+)"',page)[1]
username='expire-'+uuid.uuid4().hex[:8]
assert call('/api/otp',{'apiKey':'fixture-secret-key','username':username,'gstin':'27ABCDE1234F1Z5'},csrf)[0]==200
assert call('/api/connect',{'otp':'123456'},csrf)[0]==200
assert call('/api/batches',{'returns':['GSTR-6'],'financialYear':'2026-27','month':'10','requestKey':str(uuid.uuid4())},csrf)[0]==200
login=opener.open(BASE+'/admin').read().decode();admin_csrf=re.search('name="csrf" value="([^"]+)"',login)[1]
from urllib.parse import urlencode
request=Request(BASE+'/admin/login',data=urlencode({'csrf':admin_csrf,'username':credentials['username'],'password':credentials['password']}).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
page=opener.open(request).read().decode();assert 'API Activity &amp; Status' in page
status,data=call('/admin/api/activity');assert status==200 and data['rows']
assert 'fixture-secret-key' not in json.dumps(data) and '123456' not in json.dumps(data),'Audit never contains keys or OTPs'
assert any(row['client_ip']=='127.0.0.1' for row in data['rows'])
assert call('/admin/api/activity?ip=invalid')[0]==422
db=sqlite3.connect(root/'writable/data/returns-v3-fixture.sqlite',timeout=5)
owner=db.execute('SELECT owner FROM connections WHERE username=?',(username,)).fetchone()[0]
audit_before=db.execute('SELECT COUNT(*) FROM api_audit WHERE owner=?',(owner,)).fetchone()[0]
db.execute('UPDATE jobs SET downloaded_at=?,expires_at=? WHERE owner=?',(int(time.time())-601,int(time.time())-1,owner));db.commit()
orphan=root/'writable/data'/f'ttl-{int(time.time())-1}-{uuid.uuid4().hex}.zip';orphan.write_bytes(b'fixture return payload')
# Do not issue a portal read to trigger cleanup. The independent janitor must perform it.
deadline=time.monotonic()+8
while time.monotonic()<deadline:
    remaining=db.execute('SELECT COUNT(*) FROM jobs WHERE owner=?',(owner,)).fetchone()[0]
    if not remaining and not orphan.exists():break
    time.sleep(.2)
assert remaining==0 and not orphan.exists(),'Janitor removes data without browser traffic or a status poll'
assert db.execute('SELECT COUNT(*) FROM api_audit WHERE owner=?',(owner,)).fetchone()[0]>=audit_before,'Admin tracking retained separately'
assert db.execute('SELECT COUNT(*) FROM connections WHERE owner=?',(owner,)).fetchone()[0]==1,'Connection retention independent of ZIP data'
db.close()
print('PASS: admin authentication, IP/status tracking, filters, no credential disclosure, independent ZIP/job expiry and audit preservation.')
