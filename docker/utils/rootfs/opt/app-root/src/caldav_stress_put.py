#!/usr/bin/env python3
#
# python caldav_stress_put.py \
#   --base-url https://kolab.klab.cc/dav/calendars/user/admin@kolab.klab.cc/testcal/ \
#   --user admin@kolab.klab.cc \
#   --password ... \
#   --count 10 \
#   --body-size 10240 \
#   --workers 4

import requests
import threading
import time
import random
import string
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed

# -------------------- Utilities --------------------

def random_text(size):
    return ''.join(random.choices(string.ascii_letters + string.digits + ' ', k=size))

def generate_ics(uid, summary="Stress Test Event", body_size=1024):
    """Generates a minimal iCalendar object with random description"""
    description = random_text(body_size)
    return f"""BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//StressTest//EN
BEGIN:VEVENT
UID:{uid}
DTSTAMP:{time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())}
SUMMARY:{summary}
DESCRIPTION:{description}
DTSTART:{time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())}
DTEND:{time.strftime('%Y%m%dT%H%M%SZ', time.gmtime(time.time()+3600))}
END:VEVENT
END:VCALENDAR
"""

# -------------------- CalDAV Collection Creation --------------------

def create_collection(base_url, user, password):
    """Send MKCOL to create a CalDAV calendar collection"""
    session = requests.Session()
    session.auth = (user, password)

    # XML body to define the resource as a calendar collection
    mkcol_body = '''
<D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:set>
    <D:prop>
      <D:resourcetype>
        <D:collection/>
        <C:calendar/>
      </D:resourcetype>
      <D:displayname>StressTest</D:displayname>
    </D:prop>
  </D:set>
</D:mkcol>
    '''.strip()

    headers = {'Content-Type': 'application/xml; charset="utf-8"'}
    
    resp = session.request("MKCOL", base_url, headers=headers, data=mkcol_body.encode('utf-8'))

    if resp.status_code == 201:
        print(f"[MKCOL] Calendar collection created: {base_url}")
    # 405 Method Not Allowed is a common response for "already exists"
    elif resp.status_code == 405 or resp.status_code == 403:
        print(f"[MKCOL] Calendar collection already exists: {base_url}")
    else:
        raise RuntimeError(f"[MKCOL] Failed to create collection ({resp.status_code}): {resp.text}")

# -------------------- Progress Tracking --------------------

progress_lock = threading.Lock()
total_sent = 0
start_time = None
last_report_time = 0
last_report_count = 0

def report_progress(total_target, force=False):
    global last_report_time, last_report_count
    now = time.time()
    if not force and now - last_report_time < 1.0:
        return

    with progress_lock:
        elapsed = now - start_time
        interval = now - last_report_time if last_report_time else elapsed
        interval_count = total_sent - last_report_count

        current_rate = interval_count / interval if interval > 0 else 0
        avg_rate = total_sent / elapsed if elapsed > 0 else 0

        print(
            f"[PUT] {total_sent}/{total_target} events | "
            f"{elapsed:.1f}s | "
            f"{current_rate:.1f} evt/s (current) | "
            f"{avg_rate:.1f} evt/s (avg)"
        )

        last_report_time = now
        last_report_count = total_sent

# -------------------- Worker --------------------

def put_events(worker_id, args, start_index, count):
    global total_sent
    session = requests.Session()
    session.auth = (args.user, args.password)
    session.headers.update({'Content-Type': 'text/calendar'})

    for i in range(count):
        uid = f"stress-{worker_id}-{start_index+i}-{int(time.time()*1000)}"
        ics_data = generate_ics(uid, body_size=args.body_size)

        url = f"{args.base_url}{uid}.ics"  # Note: base_url must end with '/'

        try:
            resp = session.put(url, data=ics_data, timeout=10)
            if resp.status_code not in (200, 201, 204):
                print(f"[Worker {worker_id}] PUT failed ({resp.status_code}): {url}")
            else:
                with progress_lock:
                    total_sent += 1
        except Exception as e:
            print(f"[Worker {worker_id}] PUT exception: {e}")

        report_progress(args.count)

# -------------------- Main --------------------

def main():
    global start_time

    parser = argparse.ArgumentParser(description="CalDAV PUT stress test (Cyrus IMAP) with collection creation")
    parser.add_argument("--base-url", required=True, help="Full URL to target calendar collection (must end with /)")
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--count", type=int, default=1000)
    parser.add_argument("--body-size", type=int, default=1024)
    parser.add_argument("--workers", type=int, default=1)

    args = parser.parse_args()

    # -------------------- Create Test Collection --------------------
    print(f"[START] Creating calendar collection if needed: {args.base_url}")
    try:
        create_collection(args.base_url, args.user, args.password)
    except RuntimeError as e:
        print(f"[FATAL] Setup failed: {e}")
        return  # Exit the script

    # -------------------- Start PUT Stress --------------------
    start_time = time.time()
    print(f"[START] Target events: {args.count}")
    print(f"[START] Workers: {args.workers}")

    events_per_worker = args.count // args.workers
    remainder = args.count % args.workers

    with ThreadPoolExecutor(max_workers=args.workers) as executor:
        index = 0
        futures = []
        for w in range(args.workers):
            cnt = events_per_worker + (1 if w < remainder else 0)
            futures.append(executor.submit(put_events, w, args, index, cnt))
            index += cnt

        for _ in as_completed(futures):
            pass

    report_progress(args.count, force=True)

    total_time = time.time() - start_time
    print(
        f"\n[COMPLETE] {total_sent} events in {total_time:.1f}s "
        f"({total_sent / total_time:.1f} evt/s avg)"
    )

if __name__ == "__main__":
    main()
