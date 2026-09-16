#!/usr/bin/env python3
"""Meldet eingehende Asterisk-Trunkanrufe signiert an CRM SpeedPhone."""

import hashlib
import hmac
import json
import logging
import socket
import ssl
import time
import urllib.request
from pathlib import Path

CONFIG = Path('/etc/crm-speedphone-incoming/config.json')


def read_frame(stream):
    frame = {}
    while True:
        line = stream.readline()
        if not line:
            raise ConnectionError('AMI-Verbindung beendet')
        line = line.decode('utf-8', 'replace').rstrip('\r\n')
        if not line:
            if frame:
                return frame
            continue
        if ':' in line:
            key, value = line.split(':', 1)
            frame[key.strip()] = value.strip()


def send_frame(sock, fields):
    payload = ''.join(f'{key}: {value}\r\n' for key, value in fields.items()) + '\r\n'
    sock.sendall(payload.encode('utf-8'))


def post_event(config, phone, event_id):
    body = json.dumps({
        'phone': phone,
        'event_id': event_id,
        'extension': str(config.get('broadcast_extension', '')),
    }, separators=(',', ':')).encode('utf-8')
    timestamp = str(int(time.time()))
    digest = hmac.new(
        config['webhook_secret'].encode('utf-8'),
        timestamp.encode('ascii') + b'.' + body,
        hashlib.sha256,
    ).hexdigest()
    request = urllib.request.Request(
        config['webhook_url'],
        data=body,
        headers={
            'Content-Type': 'application/json',
            'X-SpeedPhone-Timestamp': timestamp,
            'X-SpeedPhone-Signature': 'v1=' + digest,
            'User-Agent': 'crm-speedphone-pbx-incoming/1.0',
        },
        method='POST',
    )
    with urllib.request.urlopen(request, timeout=10, context=ssl.create_default_context()) as response:
        result = json.loads(response.read().decode('utf-8'))
        if not result.get('success'):
            raise RuntimeError(result.get('error', 'CRM lehnte die Anrufmeldung ab'))
        return result.get('data', {})


def listen(config):
    prefixes = tuple(config.get('trunk_channel_prefixes', []))
    contexts = set(config.get('incoming_contexts', ['from-pstn', 'from-trunk']))
    seen = {}
    with socket.create_connection((config.get('ami_host', '127.0.0.1'), int(config.get('ami_port', 5038))), timeout=10) as sock:
        stream = sock.makefile('rb')
        stream.readline()
        send_frame(sock, {
            'Action': 'Login',
            'Username': config['ami_username'],
            'Secret': config['ami_secret'],
            'Events': 'on',
        })
        login = read_frame(stream)
        if login.get('Response', '').lower() != 'success':
            raise RuntimeError('AMI-Anmeldung abgelehnt')
        logging.info('Mit Asterisk AMI verbunden')
        while True:
            frame = read_frame(stream)
            now = time.time()
            seen = {key: value for key, value in seen.items() if now - value < 3600}
            if frame.get('Event') != 'Newchannel':
                continue
            channel = frame.get('Channel', '')
            context = frame.get('Context', '')
            if not channel.startswith(prefixes) or context not in contexts:
                continue
            phone = frame.get('CallerIDNum', '').strip()
            event_id = (frame.get('Linkedid') or frame.get('Uniqueid') or '').strip()
            if len(phone) < 5 or not event_id or event_id in seen:
                continue
            seen[event_id] = now
            result = post_event(config, phone, event_id)
            logging.info('Anruf %s gemeldet: %s Ereignisse, %s Treffer', event_id, result.get('events', 0), result.get('matches', 0))


def main():
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    while True:
        try:
            config = json.loads(CONFIG.read_text(encoding='utf-8'))
            listen(config)
        except Exception as error:
            logging.exception('Anrufmelder unterbrochen: %s', error)
            time.sleep(5)


if __name__ == '__main__':
    main()
