"""Executable local HTTP smoke. Requires a migrated pyh_v16_phase4_test only."""
import os
import re
import secrets
import subprocess
import time
import urllib.request
import urllib.parse
import urllib.error
import http.cookiejar
from pathlib import Path

assert os.environ.get('APP_ENV') == 'test'
assert os.environ.get('TEST_DB_DATABASE') == 'pyh_v16_phase4_test'
assert os.environ.get('TEST_DB_HOST') in ('localhost', '127.0.0.1')
root = Path(__file__).resolve().parents[2]
base = 'http://127.0.0.1:18043'

def sql(query):
    return subprocess.check_output(['/usr/local/bin/mysql', '--protocol=socket', '--database=pyh_v16_phase4_test', '-N', '-B', '-e', query], text=True).strip()

suffix = secrets.token_hex(6)
password = secrets.token_urlsafe(24)
hash_value = subprocess.check_output(['/usr/local/bin/php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], text=True)
org = sql("INSERT INTO organisations (legal_name,trading_name) VALUES ('HTTP smoke','HTTP smoke'); SELECT LAST_INSERT_ID();")
loc = sql(f"INSERT INTO locations (organisation_id,name,internal_code) VALUES ({org},'Smoke','SMOKE'); SELECT LAST_INSERT_ID();")
email = f'http-{suffix}@example.test'
user = sql(f"INSERT INTO users (organisation_id,location_id,first_name,last_name,email,password_hash,agent_code) VALUES ({org},{loc},'HTTP','Agent','{email}','{hash_value}','HTTP'); SELECT LAST_INSERT_ID();")
sql(f"INSERT INTO user_roles (user_id,role_id) SELECT {user},id FROM roles WHERE name='System Administrator' AND organisation_id IS NULL;")
env = dict(os.environ, DB_HOST='127.0.0.1', DB_PORT='3306', DB_DATABASE='pyh_v16_phase4_test', DB_USERNAME='root', DB_PASSWORD='', APP_ENV='test', APP_DEBUG='false')
log = open(root / 'storage/logs/booking-operations-http-smoke.log', 'w')
server = subprocess.Popen(['/usr/local/bin/php', '-S', '127.0.0.1:18043', '-t', 'public'], cwd=root, env=env, stdout=log, stderr=log)
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def get(path):
    with client.open(base + path) as response:
        return response.status, response.url, response.read().decode()

def post(path, data, expected=200, csrf=True):
    if csrf:
        _, _, page = get('/login')
        data = dict(data, _csrf=re.search(r'name="_csrf" value="([^"]+)"', page).group(1))
    request = urllib.request.Request(base + path, urllib.parse.urlencode(data).encode())
    try:
        with client.open(request) as response:
            result = response.status, response.url, response.read().decode()
    except urllib.error.HTTPError as error:
        result = error.code, error.url, error.read().decode()
    assert result[0] == expected, (path, expected, result[0])
    return result

uploaded_path = None
try:
    for _ in range(50):
        try:
            get('/login')
            break
        except urllib.error.URLError:
            time.sleep(.1)
    assert get('/bookings')[1].endswith('/login')
    assert get('/booking?id=1')[1].endswith('/login')
    post('/login', {'email': email, 'password': password})
    post('/customers', {'first_name': 'HTTPJane', 'last_name': 'Snapshot', 'email': f'customer-{suffix}@example.test'})
    customer = sql(f'SELECT id FROM customers WHERE organisation_id={org} ORDER BY id DESC LIMIT 1')
    traveller = sql(f"INSERT INTO travellers (customer_id,first_name,last_name,date_of_birth) VALUES ({customer},'HTTPJane','Snapshot','1980-01-01'); SELECT LAST_INSERT_ID();")
    post('/enquiries', {'customer_id': customer, 'product_type': 'Package Holiday', 'destinations': 'Madeira', 'adults': '1'})
    enquiry = sql(f'SELECT id FROM enquiries WHERE organisation_id={org} ORDER BY id DESC LIMIT 1')
    enquiry_ref = sql(f'SELECT reference FROM enquiries WHERE id={enquiry}')
    post('/enquiries', {'_operation': 'transition', 'id': enquiry, 'status': 'Contacted'})
    post('/quotes', {'customer_id': customer, 'enquiry_id': enquiry, 'title': 'HTTP holiday', 'product_type': 'Package Holiday', 'departure_date': '2027-06-01', 'return_date': '2027-06-08', 'expires_at_utc': '2027-05-01 00:00:00'})
    quote = sql(f'SELECT id FROM quotes WHERE organisation_id={org} ORDER BY id DESC LIMIT 1')
    def action(operation, **data):
        return post('/quotes', dict(data, _operation=operation, quote_id=quote))
    action('traveller', traveller_id=traveller, traveller_type='Adult', is_lead='1')
    action('component', component_type='Other', title='Holiday package', supplier='HTTP Supplier', supplier_reference='HTTP-REF', selling_price='1234.56', supplier_cost='1000.00', commission_amount='234.56')
    action('compliance', **{key: '1' for key in ['total_price_clear', 'mandatory_charges_included', 'material_information_present', 'supplier_identity_present', 'deposit_balance_clear', 'significant_terms_present', 'availability_caveat_present']})
    action('ready')
    action('proposal')
    proposal = sql(f'SELECT id FROM proposal_versions WHERE quote_id={quote} ORDER BY id DESC LIMIT 1')
    action('send', proposal_version_id=proposal, method='In Person')
    action('decision', proposal_version_id=proposal, decision='Accepted')
    assert sql(f'SELECT status FROM enquiries WHERE id={enquiry}') == 'Quoted'
    action('handoff')
    handoff = sql(f'SELECT id FROM quote_booking_handoffs WHERE quote_id={quote}')
    assert 'Create Booking' in get(f'/quote?id={quote}')[2]
    post('/bookings/convert', {'quote_id': quote, 'handoff_id': handoff}, expected=403, csrf=False)
    _, url, page = post('/bookings/convert', {'quote_id': quote, 'handoff_id': handoff})
    assert '/booking?id=' in url and 'HTTPJane' in page and 'Snapshot' in page
    booking = sql(f'SELECT id FROM bookings WHERE quote_booking_handoff_id={handoff}')
    ref = sql(f'SELECT booking_reference FROM bookings WHERE id={booking}')
    assert ref in get('/bookings')[2]
    assert sql(f'SELECT status FROM quotes WHERE id={quote}') == 'Converted'
    assert sql(f'SELECT status FROM enquiries WHERE id={enquiry}') == 'Booked'
    assert sql(f'SELECT status FROM bookings WHERE id={booking}') == 'Booked'
    assert enquiry_ref not in get('/enquiries')[2]
    post('/bookings/convert', {'quote_id': quote, 'handoff_id': handoff}, expected=422)
    assert sql(f'SELECT COUNT(*) FROM bookings WHERE quote_booking_handoff_id={handoff}') == '1'
    post('/booking', {'id': booking, 'status': 'Cancelled'}, expected=422)
    post('/booking', {'id': booking, 'supplier_booking_reference': 'HTTP-CONFIRMED'})
    assert 'HTTP-CONFIRMED' in get(f'/booking?id={booking}')[2]
    post('/booking', {'id': booking, 'internal_notes': 'Bad CSRF'}, expected=403, csrf=False)
    # Exercise every new operation over authenticated HTTP.
    paths=['elements','payments','supplier-payments','commission','adjustments','documents','checklist','amendments']
    for path in paths:
        post('/booking/'+path, {'booking_id': booking}, expected=403, csrf=False)
    def operation(path, **data):
        return post('/booking/'+path, dict(data, booking_id=booking))
    operation('elements', element_type='Lounge', title='<script>unsafe</script>', selling_price='50.00', supplier_cost='30.00', commission='20.00')
    element=sql(f'SELECT id FROM booking_elements WHERE booking_id={booking} ORDER BY id DESC LIMIT 1')
    _,_,holiday=get(f'/booking?id={booking}&tab=holiday')
    assert '&lt;script&gt;unsafe&lt;/script&gt;' in holiday and '<script>unsafe</script>' not in holiday
    operation('payments', transaction_type='Payment', amount='100.00', transaction_reference='HAYS-HTTP')
    operation('payments', transaction_type='Refund', amount='10.00')
    post('/booking/payments', {'booking_id': booking, 'transaction_type': 'Refund', 'amount':'1000'}, expected=422)
    operation('supplier-payments', booking_element_id=element, status='Paid', amount='30', paid_date='2026-09-05')
    operation('commission', operation='generate')
    operation('commission', operation='generate')
    assert sql(f'SELECT COUNT(*) FROM booking_commission_ledger WHERE booking_id={booking}')=='2'
    ledger=sql(f'SELECT id FROM booking_commission_ledger WHERE booking_id={booking} ORDER BY id LIMIT 1')
    operation('commission', operation='status', id=ledger, status='Received', received_date='2026-09-05')
    operation('adjustments', operation='submit', adjustment_type='Discount', amount='10', direction='Decrease', reason='HTTP goodwill')
    adjustment=sql(f'SELECT id FROM booking_financial_adjustments WHERE booking_id={booking} ORDER BY id DESC LIMIT 1')
    operation('adjustments', operation='decide', id=adjustment, status='Approved', decision_note='Explicit self-approval capability')
    operation('documents', document_type='ATOL Certificate', original_filename='atol.pdf', storage_reference='opaque_http_atol', mime_type='application/pdf')
    doc=sql(f'SELECT id FROM booking_documents WHERE booking_id={booking} ORDER BY id DESC LIMIT 1')
    assert sql(f'SELECT visibility FROM booking_documents WHERE id={doc}')=='Agent only'
    operation('documents', operation='visibility', id=doc, visibility='Customer visible')
    _,_,login_page=get('/login')
    token=re.search(r'name="_csrf" value="([^"]+)"',login_page).group(1)
    boundary='----PYHSmoke'+secrets.token_hex(8)
    fields={'_csrf':token,'booking_id':booking,'operation':'upload','document_type':'Other'}
    body=b''
    for key,value in fields.items():
        body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode()
    contents=b'Local test supplier document. No customer data.\n'
    body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="smoke.txt"\r\nContent-Type: text/plain\r\n\r\n').encode()+contents+(f'\r\n--{boundary}--\r\n').encode()
    request=urllib.request.Request(base+'/booking/documents',body,{'Content-Type':'multipart/form-data; boundary='+boundary})
    with client.open(request) as response:
        assert response.status==200
    uploaded=sql(f'SELECT id FROM booking_documents WHERE booking_id={booking} ORDER BY id DESC LIMIT 1')
    key=sql(f'SELECT storage_reference FROM booking_documents WHERE id={uploaded}')
    uploaded_path=root/'storage/private/booking-documents'/booking/key
    assert uploaded_path.exists()
    assert sql(f'SELECT visibility FROM booking_documents WHERE id={uploaded}')=='Agent only'
    with client.open(base+f'/booking/document?booking_id={booking}&id={uploaded}') as response:
        assert response.read()==contents
        assert response.headers['X-Content-Type-Options']=='nosniff'
        assert 'attachment' in response.headers['Content-Disposition']

    operation('checklist', operation='initialise')
    operation('checklist', operation='initialise')
    item=sql(f'SELECT id FROM booking_checklist_items WHERE booking_id={booking} ORDER BY id LIMIT 1')
    operation('checklist', id=item, status='Completed')
    operation('amendments', operation='draft', reason='Requested change', description='Travel date change', after_summary='{"departure_date":"2027-06-02"}')
    amendment=sql(f'SELECT id FROM booking_amendments WHERE booking_id={booking} ORDER BY id DESC LIMIT 1')
    for state in ['Pending','Approved','Applied']:
        operation('amendments', operation='transition', id=amendment, status=state)
    assert sql(f'SELECT departure_date FROM bookings WHERE id={booking}')=='2027-06-02'
    for tab in ['overview','travellers','holiday','payments','documents','checklist','finance','timeline']:
        assert get(f'/booking?id={booking}&tab={tab}')[0]==200
    assert 'HTTPJane' in get(f'/booking?id={booking}&tab=travellers')[2]
    assert 'Commission' in get(f'/booking?id={booking}&tab=finance')[2]
    assert 'Created' in get(f'/booking?id={booking}&tab=timeline')[2]
    sql(f"DELETE ur FROM user_roles ur WHERE ur.user_id={user}")
    try:
        get('/bookings')
        raise AssertionError('Missing permission accepted')
    except urllib.error.HTTPError as error:
        assert error.code == 403
    sql(f"INSERT INTO user_roles (user_id,role_id) SELECT {user},id FROM roles WHERE name='Agent' AND organisation_id IS NULL")
    try:
        get(f'/booking?id={booking}&tab=finance')
        raise AssertionError('Finance page allowed without capability')
    except urllib.error.HTTPError as error:
        assert error.code==403
    ordinary_holiday=get(f'/booking?id={booking}&tab=holiday')[2]
    assert 'Supplier Cost' not in ordinary_holiday and 'Commission' not in ordinary_holiday
    sql(f"UPDATE bookings SET assigned_user_id=NULL WHERE id={booking}")
    bodies=[]
    for target in (booking, '999999999'):
        try:
            get(f'/booking?id={target}')
            raise AssertionError('Inaccessible booking accepted')
        except urllib.error.HTTPError as error:
            assert error.code == 404
            bodies.append(error.read())
    assert bodies[0] == bodies[1]
    print('Operations HTTP smoke PASSED: all eight workspace tabs and mutation routes, Hays payment/refund, supplier obligation, commission, self approval, documents, checklist, amendments, HTML escaping;  authenticated end-to-end conversion, overview/travellers, list, state consistency, open-enquiry exclusion, duplicate 422, status tampering 422, missing CSRF 403, forbidden 403, anonymous login redirect.')
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    if uploaded_path is not None and uploaded_path.exists():
        uploaded_path.unlink()
