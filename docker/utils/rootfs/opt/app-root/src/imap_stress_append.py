#!/usr/bin/env python3
#
# python imap_stress_append.py
#         --host kolab.klab.cc \
#         --user admin@kolab.klab.cc \
#         --password ... \
#         --ssl \
#         --count 1000 \
#         --body-size 50000 \
#         --workers 4

import imaplib
import email.message
import email.utils
import random
import string
import time
import argparse
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

# -------------------- Utilities --------------------

def random_text(size):
    return ''.join(random.choices(string.ascii_letters + string.digits + ' ', k=size))

def generate_email(from_addr, to_addr, subject, body_size):
    msg = email.message.EmailMessage()
    msg["From"] = from_addr
    msg["To"] = to_addr
    msg["Subject"] = subject
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg.set_content(random_text(body_size))
    return msg.as_bytes()

def imap_connect(host, port, user, password, ssl, starttls):
    if ssl:
        imap = imaplib.IMAP4_SSL(host, port)
    else:
        imap = imaplib.IMAP4(host, port)
        if starttls:
            imap.starttls()
    imap.login(user, password)
    return imap

# -------------------- Progress Tracking --------------------

progress_lock = threading.Lock()
total_appended = 0
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
        interval_count = total_appended - last_report_count

        current_rate = interval_count / interval if interval > 0 else 0
        avg_rate = total_appended / elapsed if elapsed > 0 else 0

        print(
            f"[APPEND] {total_appended}/{total_target} msgs | "
            f"{elapsed:.1f}s | "
            f"{current_rate:.1f} msg/s (current) | "
            f"{avg_rate:.1f} msg/s (avg)"
        )

        last_report_time = now
        last_report_count = total_appended

# -------------------- Workers --------------------

def append_messages(worker_id, args, start_index, count):
    global total_appended

    imap = imap_connect(
        args.host,
        args.port,
        args.user,
        args.password,
        args.ssl,
        args.starttls,
    )

    imap.create(args.mailbox)

    for i in range(count):
        msg_num = start_index + i
        subject = f"StressTest {worker_id}-{msg_num}"
        msg_bytes = generate_email(
            args.from_addr,
            args.to_addr,
            subject,
            args.body_size,
        )

        try:
            imap.append(args.mailbox, None, None, msg_bytes)
            with progress_lock:
                total_appended += 1
        except Exception as e:
            print(f"[Worker {worker_id}] Append failed: {e}")

        report_progress(args.count)

        if args.delay:
            time.sleep(args.delay)

    imap.logout()

# -------------------- Main --------------------

def main():
    global start_time

    parser = argparse.ArgumentParser(description="IMAP APPEND stress test")
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=993)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--mailbox", default="INBOX.StressTest")
    parser.add_argument("--count", type=int, default=1000)
    parser.add_argument("--body-size", type=int, default=10240)
    parser.add_argument("--workers", type=int, default=1)
    parser.add_argument("--delay", type=float, default=0.0)
    parser.add_argument("--ssl", action="store_true")
    parser.add_argument("--starttls", action="store_true")
    parser.add_argument("--from-addr", default="stress@test.local")
    parser.add_argument("--to-addr", default="stress@test.local")

    args = parser.parse_args()

    start_time = time.time()
    print(f"[START] Mailbox: {args.mailbox}")
    print(f"[START] Target messages: {args.count}")
    print(f"[START] Workers: {args.workers}")

    msgs_per_worker = args.count // args.workers
    remainder = args.count % args.workers

    with ThreadPoolExecutor(max_workers=args.workers) as executor:
        index = 0
        futures = []
        for w in range(args.workers):
            cnt = msgs_per_worker + (1 if w < remainder else 0)
            futures.append(
                executor.submit(append_messages, w, args, index, cnt)
            )
            index += cnt

        for _ in as_completed(futures):
            pass

    report_progress(args.count, force=True)

    total_time = time.time() - start_time
    print(
        f"\n[COMPLETE] {total_appended} messages in {total_time:.1f}s "
        f"({total_appended / total_time:.1f} msg/s avg)"
    )

if __name__ == "__main__":
    main()
