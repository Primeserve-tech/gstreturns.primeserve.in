"""Local fixture-only integration checks. Python standard library; no production calls."""
import io, json, re, time, uuid, zipfile, os
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError
from http.cookiejar import CookieJar

BASE = os.environ.get('GST_TEST_BASE','http://127.0.0.1:8087')
assert BASE.startswith('http://127.0.0.1:'), 'Only localhost may be tested'

class Client:
    def __init__(self):
        self.cookies = CookieJar()
        self.opener = build_opener(HTTPCookieProcessor(self.cookies))
        page = self.opener.open(BASE).read().decode()
        self.csrf = re.search(r'name="csrf-token" content="([^"]+)"', page)[1]
    def call(self, path, data=None, token=True):
        headers = {'Content-Type':'application/json'}
        if token: headers['X-CSRF-Token'] = self.csrf
        request = Request(BASE+path, data=None if data is None else json.dumps(data).encode(), headers=headers)
        try:
            response = self.opener.open(request)
            self.last_headers = response.headers
            payload = response.read()
            return response.status, json.loads(payload) if 'json' in response.headers.get('Content-Type','') else payload
        except HTTPError as error:
            return error.code, json.loads(error.read())

client = Client()
assert client.call('/api/state')[1]['mode'] == 'fixture', 'Only fixture server may be tested'
assert client.call('/api/otp', {'apiKey':'test-key'},token=False)[0] == 422, 'CSRF required'
assert client.call('/api/batches', {'returns':['GSTR-1']})[0] == 422, 'Auth required'
assert client.call('/api/otp', {'apiKey':'test-key','username':'local-user','gstin':'INVALID'})[0] == 422
status, result = client.call('/api/otp', {'apiKey':'test-key','username':'local-user','gstin':'27ABCDE1234F1Z5'})
assert status == 200, result
assert client.call('/api/connect', {'otp':'999999'})[0] == 422
assert client.call('/api/connect', {'otp':'000000'})[0] == 422
assert client.call('/api/connect', {'otp':'123456'})[0] == 200
key = str(uuid.uuid4())
body = {'requestKey':key,'returns':['GSTR-1','GSTR-2B','GSTR-3B'],'financialYear':'2026-27','month':'10'}
status, batch = client.call('/api/batches',body)
assert status == 200, batch
assert client.call('/api/batches',body)[1]['batchId'] == batch['batchId'], 'idempotent duplicate click'
assert client.call('/api/batches',{**body,'requestKey':str(uuid.uuid4())})[0] == 422, 'active batch duplication blocked'
state = client.call('/api/state')[1]
assert len(state['jobs']) == 3
assert 'test-key' not in json.dumps(state) and '123456' not in json.dumps(state), 'no credential echo'
other = Client()
assert other.call('/api/state')[1]['jobs'] == [], 'user isolation'
assert other.call('/api/jobs/'+state['jobs'][0]['id']+'/download',{})[0] == 422
# GET the page again with the same cookie: ownership survives refresh.
client.opener.open(BASE).read()
assert len(client.call('/api/state')[1]['jobs']) == 3
deadline = time.monotonic()+60
while time.monotonic()<deadline:
    jobs = client.call('/api/state')[1]['jobs']
    if all(job['status']=='completed' for job in jobs): break
    time.sleep(1)
assert all(job['status']=='completed' for job in jobs), jobs
assert len(set(job['job_id'] for job in jobs)) == 3
assert all(job['downloaded_at'] is None and job['expires_at'] is None for job in jobs), 'No deletion timer before a ZIP is received'
individual = {}
for job in jobs:
    status, data = client.call('/api/jobs/'+job['id']+'/download',{})
    assert status == 200
    receipt=client.last_headers['X-Download-Receipt']
    archive = zipfile.ZipFile(io.BytesIO(data)); assert archive.testzip() is None
    assert b'NOT A GST RETURN' in archive.read('LOCAL-TEST-ONLY.txt')
    individual[job['return_type']] = archive.read('LOCAL-TEST-ONLY.txt')
    assert client.call('/api/downloads/ack',{'receipt':receipt})[0] == 200
status, data = client.call('/api/download-all', {'batchId':batch['batchId']})
assert status == 200
receipt=client.last_headers['X-Download-Receipt']
bundle = zipfile.ZipFile(io.BytesIO(data)); assert len(bundle.namelist()) == 3
for name in bundle.namelist():
    nested = zipfile.ZipFile(io.BytesIO(bundle.read(name)))
    content = nested.read('LOCAL-TEST-ONLY.txt')
    assert content in individual.values()
assert client.call('/api/downloads/ack',{'receipt':receipt})[0] == 200
assert all(job['expires_at']-job['downloaded_at']==600 for job in client.call('/api/state')[1]['jobs'])
# A full financial year creates a separate monthly record per return and period.
status,annual=client.call('/api/batches',{'requestKey':str(uuid.uuid4()),'returns':['GSTR-1','GSTR-2B'],'financialYear':'2025-26','scope':'yearly','month':'04'})
assert status==200,annual
annual_jobs=[job for job in client.call('/api/state')[1]['jobs'] if job['batch_id']==annual['batchId']]
assert len(annual_jobs)==24 and len({(job['return_type'],job['period']) for job in annual_jobs})==24
assert {'042025','032026'}.issubset({job['period'] for job in annual_jobs})
print('PASS: HTTP auth, invalid/expired OTP, CSRF, selections, duplicate prevention, user isolation, refresh persistence, worker completion, unique job IDs, and individual/combined ZIP downloads.')
print('PASS: first-download acknowledgement starts 600-second retention; yearly mode creates 24 distinct monthly jobs for two selected return types.')
import sqlite3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
db=sqlite3.connect(root/'writable/data/returns-v3-fixture.sqlite',timeout=5)
owner=db.execute('SELECT owner FROM batches WHERE id=?',(annual['batchId'],)).fetchone()[0]
audit_before=db.execute('SELECT COUNT(*) FROM api_audit WHERE owner=?',(owner,)).fetchone()[0]
for job in jobs:db.execute('UPDATE jobs SET expires_at=? WHERE id=?',(int(time.time())-1,job['id']))
db.commit()
orphan=root/'writable/data'/f'ttl-{int(time.time())-1}-{uuid.uuid4().hex}.zip';orphan.write_bytes(b'fixture ZIP payload')
end=time.monotonic()+8
while time.monotonic()<end:
    count=db.execute('SELECT COUNT(*) FROM jobs WHERE batch_id=?',(batch['batchId'],)).fetchone()[0]
    if count==0 and not orphan.exists():break
    time.sleep(.2)
assert count==0 and not orphan.exists(), 'Independent janitor must remove expired downloaded records without browser traffic'
assert db.execute('SELECT COUNT(*) FROM jobs WHERE batch_id=?',(annual['batchId'],)).fetchone()[0]==24, 'Undownloaded yearly records remain'
assert db.execute('SELECT COUNT(*) FROM api_audit WHERE owner=?',(owner,)).fetchone()[0]>=audit_before
db.close()
print('PASS: independent janitor deletes expired downloaded records and ZIP leftovers while preserving undownloaded yearly jobs and admin audit.')
