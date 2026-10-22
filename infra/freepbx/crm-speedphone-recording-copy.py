#!/usr/bin/env python3
"""Kopiert eindeutig zugeordnete CRM-PBX-Mitschnitte in den Marketing-Thread.

Der vorhandene Anesda-Mitschnittversand bleibt unverändert. Dauerhafter
Versandstatus liegt in der CRM-MariaDB; die PBX-MariaDB merkt nur lokale
Scan-/Wiederholversuche. Es wird keine SQLite-Datenbank verwendet.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import hmac
import json
import logging
import os
import re
import runpy
import time
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from zoneinfo import ZoneInfo

import requests


SENDER_PATH = Path("/usr/local/sbin/telegram-recording-send")
INCOMING_CONFIG = Path("/etc/crm-speedphone-incoming/config.json")
MONITOR_ROOT = Path("/var/spool/asterisk/monitor")
START_MARKER = Path("/var/lib/asterisk/crm-speedphone-recording-copy.started")
PROCESS_LOCK = Path("/var/lib/asterisk/crm-speedphone-recording-copy.lock")
LOG_PATH = Path("/var/log/asterisk/crm_speedphone_recording_copy.log")
SUPPORTED_EXTENSIONS = {".wav", ".wav49", ".gsm", ".mp3", ".ogg", ".m4a"}


class UncertainDelivery(RuntimeError):
    """Telegram könnte die Kopie bereits angenommen haben."""


def sender_functions() -> dict:
    if not SENDER_PATH.is_file():
        raise RuntimeError("Der bestehende PBX-Mitschnittversand fehlt.")
    return runpy.run_path(str(SENDER_PATH))


def crm_settings() -> dict:
    settings = json.loads(INCOMING_CONFIG.read_text(encoding="utf-8"))
    source_url = urlsplit(str(settings["webhook_url"]))
    if source_url.scheme != "https" or not source_url.netloc:
        raise RuntimeError("Die CRM-Webhook-Adresse ist nicht HTTPS.")
    query = dict(parse_qsl(source_url.query, keep_blank_values=True))
    if query.get("entryPoint") != "crmSpeedPhonePbxIncoming":
        raise RuntimeError("Die bestehende PBX-Webhook-Adresse ist unerwartet.")
    query["entryPoint"] = "crmSpeedPhonePbxRecording"
    settings["recording_url"] = urlunsplit(
        (source_url.scheme, source_url.netloc, source_url.path, urlencode(query), "")
    )
    return settings


def post_crm(settings: dict, payload: dict) -> dict:
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    timestamp = str(int(time.time()))
    signature = hmac.new(
        settings["webhook_secret"].encode("utf-8"),
        timestamp.encode("ascii") + b"." + body,
        hashlib.sha256,
    ).hexdigest()
    response = requests.post(
        settings["recording_url"],
        data=body,
        headers={
            "Content-Type": "application/json",
            "X-SpeedPhone-Timestamp": timestamp,
            "X-SpeedPhone-Signature": "v1=" + signature,
            "User-Agent": "crm-speedphone-pbx-recording/1.0",
        },
        timeout=(5, 20),
    )
    try:
        result = response.json()
    except ValueError as error:
        raise RuntimeError(f"CRM lieferte kein JSON (HTTP {response.status_code}).") from error
    if response.status_code != 200 or result.get("success") is not True:
        raise RuntimeError(str(result.get("error", f"CRM HTTP {response.status_code}")))
    return result.get("data", {})


def ensure_scan_table(mysql_query) -> None:
    mysql_query(
        """CREATE TABLE IF NOT EXISTS crm_speedphone_recording_scans (
            path varchar(512) NOT NULL PRIMARY KEY,
            size bigint unsigned NOT NULL,
            mtime_ns bigint unsigned NOT NULL,
            status varchar(20) NOT NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            next_attempt_at bigint unsigned NOT NULL DEFAULT 0,
            updated_at bigint unsigned NOT NULL,
            telegram_message_id bigint unsigned NULL,
            last_error varchar(500) NULL,
            KEY idx_crm_recording_retry (status,next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci""",
        "asterisk",
    )


def mark(mysql_query, path: Path, stat, status: str, next_attempt: int = 0,
         message_id: int | None = None, error: str = "") -> None:
    quote = lambda value: "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"
    message = "NULL" if message_id is None else str(int(message_id))
    mysql_query(
        "INSERT INTO crm_speedphone_recording_scans "
        "(path,size,mtime_ns,status,attempts,next_attempt_at,updated_at,telegram_message_id,last_error) "
        f"VALUES ({quote(path)},{stat.st_size},{stat.st_mtime_ns},{quote(status)},1,{next_attempt},"
        f"{int(time.time())},{message},{quote(error[:500])}) "
        "ON DUPLICATE KEY UPDATE size=VALUES(size),mtime_ns=VALUES(mtime_ns),"
        "status=VALUES(status),attempts=attempts+1,next_attempt_at=VALUES(next_attempt_at),"
        "updated_at=VALUES(updated_at),telegram_message_id=VALUES(telegram_message_id),"
        "last_error=VALUES(last_error)",
        "asterisk",
    )


def pending_paths(mysql_query, start_epoch: int, backfill: bool = False) -> list[tuple[Path, int]]:
    now = int(time.time())
    retry_condition = (
        "(scans.path IS NULL OR scans.status IN ('unmatched','error') "
        "OR (scans.status='skipped' AND scans.last_error IN "
        "('Kein CRM-Gespräch zugeordnet.',"
        "'Kein eindeutig aus SpeedPhone gestarteter PBX-Anruf.')))"
        if backfill else
        "(scans.path IS NULL OR (scans.status IN ('unmatched','error') "
        f"AND scans.next_attempt_at<={now}))"
    )
    rows = mysql_query(
        "SELECT recordings.path,recordings.telegram_message_id "
        "FROM telegram_recordings recordings "
        "LEFT JOIN crm_speedphone_recording_scans scans ON scans.path=recordings.path "
        "WHERE recordings.status='sent' AND recordings.telegram_message_id>0 "
        f"AND recordings.updated_at>={start_epoch} "
        f"AND {retry_condition} "
        f"ORDER BY recordings.updated_at ASC LIMIT {1000 if backfill else 60}",
        "asterisk",
    )
    result = []
    for line in rows.splitlines():
        path, separator, message_id = line.rpartition("\t")
        if separator and message_id.isdecimal() and int(message_id) > 0:
            result.append((Path(path), int(message_id)))
    return result


def originating_extension(channel: str) -> str:
    match = re.match(r"^Local/([1-9][0-9]{2,7})@from-internal(?:/|-)", channel)
    return match.group(1) if match else ""


def recording_claim(path: Path, metadata: dict, settings: dict) -> tuple[str, dict]:
    local_time = dt.datetime.strptime(metadata["calldate"], "%Y-%m-%d %H:%M:%S")
    pbx_timezone = os.environ.get("CRM_SPEEDPHONE_PBX_TIMEZONE", "Europe/Berlin")
    started_utc = local_time.replace(tzinfo=ZoneInfo(pbx_timezone)).astimezone(
        dt.timezone.utc
    ).strftime("%Y-%m-%d %H:%M:%S")
    direction = "incoming" if path.name.startswith("in-") else "outgoing"
    extension = str(metadata.get("extension", ""))
    if direction == "outgoing":
        extension = originating_extension(str(metadata.get("channel", ""))) or extension
    stat = path.stat()
    key = hashlib.sha256(
        f"{path}:{stat.st_size}:{stat.st_mtime_ns}".encode("utf-8")
    ).hexdigest()
    payload = {
        "action": "claim",
        "recording_key": key,
        "direction": direction,
        "linkedid": metadata["linkedid"],
        "started_at_utc": started_utc,
        "extension": extension,
        "phone": str(metadata.get("dst", "")) if direction == "outgoing" else "",
        "channel": str(metadata.get("channel", "")),
    }
    return key, post_crm(settings, payload)


def send_copy(sender: dict, source_message_id: int, match: dict) -> int:
    config = sender["load_config"]()
    token = config.get("telegram", "bot_token")
    source_chat_id = config.get("telegram", "chat_id")
    if source_message_id <= 0 or not source_chat_id:
        raise RuntimeError("Die ursprüngliche Telegram-Nachricht fehlt.")
    api_base = os.environ.get("CRM_SPEEDPHONE_TELEGRAM_API_BASE", "https://api.telegram.org").rstrip("/")
    if not api_base.startswith(("https://", "http://127.0.0.1:", "http://10.")):
        raise RuntimeError("Die Telegram-API-Adresse ist nicht freigegeben.")
    try:
        response = requests.post(
            f"{api_base}/bot{token}/copyMessage",
            data={
                "chat_id": str(match["chat_id"]),
                "message_thread_id": str(match["thread_id"]),
                "from_chat_id": str(source_chat_id),
                "message_id": str(source_message_id),
            },
            timeout=(5, 45),
        )
    except requests.RequestException as error:
        raise UncertainDelivery("Telegram-Antwort nach Kopierauftrag unklar") from error
    try:
        result = response.json()
    except ValueError as error:
        raise UncertainDelivery("Telegram-Antwort ist kein JSON") from error
    if response.status_code >= 500:
        raise UncertainDelivery(f"Telegram HTTP {response.status_code}")
    if response.status_code != 200 or result.get("ok") is not True:
        raise RuntimeError(str(result.get("description", f"Telegram HTTP {response.status_code}")))
    message_id = int(result.get("result", {}).get("message_id", 0))
    if message_id <= 0:
        raise UncertainDelivery("Telegram bestätigte keine Kopie im Marketing-Thread.")
    return message_id


def process(path: Path, source_message_id: int, sender: dict, settings: dict,
            mysql_query, logger) -> None:
    root = MONITOR_ROOT.resolve()
    path = path.resolve()
    if root not in path.parents or path.suffix.lower() not in SUPPORTED_EXTENSIONS:
        return
    if not path.is_file() or not path.name.startswith(("in-", "out-")):
        return
    stat = path.stat()
    metadata = sender["cdr_metadata"](path)
    if not metadata or not metadata.get("linkedid") or not metadata.get("calldate"):
        mark(mysql_query, path, stat, "unmatched", int(time.time()) + 600,
             error="CDR-Metadaten noch nicht verfügbar")
        return
    try:
        key, claim = recording_claim(path, metadata, settings)
    except (KeyError, ValueError, RuntimeError, requests.RequestException) as error:
        mark(mysql_query, path, stat, "error", int(time.time()) + 300, error=str(error))
        logger.warning("CRM-Abgleich fehlgeschlagen: %s: %s", path, error)
        return
    if not claim.get("send"):
        reason = str(claim.get("reason", "Kein eindeutiger CRM-Bezug"))
        final = "bereits gesendet" in reason or "Versandstatus muss geprüft" in reason
        age_seconds = time.time() - stat.st_mtime
        status = "skipped" if final or age_seconds > 7 * 86400 else "unmatched"
        mark(mysql_query, path, stat, status,
             0 if status == "skipped" else int(time.time()) + 600, error=reason)
        return

    mark(mysql_query, path, stat, "processing")
    try:
        message_id = send_copy(sender, source_message_id, claim)
    except UncertainDelivery as error:
        # Nach einem Netzwerkfehler kann Telegram die Datei bereits angenommen haben.
        mark(mysql_query, path, stat, "uncertain", error=str(error))
        logger.error("Telegram-Ergebnis unklar, kein automatischer Zweitversand: %s", path)
        return
    except Exception as error:
        try:
            post_crm(settings, {"action": "fail", "recording_key": key, "error": str(error)[:500]})
            mark(mysql_query, path, stat, "error", int(time.time()) + 300, error=str(error))
        except Exception:
            mark(mysql_query, path, stat, "uncertain", error=str(error))
        logger.exception("CRM-Mitschnitt konnte nicht kopiert werden: %s", path)
        return

    try:
        for attempt in range(3):
            try:
                post_crm(settings, {"action": "complete", "recording_key": key,
                                    "telegram_message_id": message_id})
                mark(mysql_query, path, stat, "sent", message_id=message_id)
                logger.info("CRM-Mitschnitt kopiert: %s -> Nachricht %d", path, message_id)
                return
            except Exception:
                if attempt < 2:
                    time.sleep(2)
        mark(mysql_query, path, stat, "uncertain", message_id=message_id,
             error="Telegram bestätigt, CRM-Quittung fehlt")
        logger.error("CRM-Quittung fehlt nach Telegram-Erfolg: %s", path)
    except Exception:
        logger.exception("Lokaler Status konnte nicht gespeichert werden: %s", path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--scan", action="store_true")
    parser.add_argument("--backfill", action="store_true")
    args = parser.parse_args()
    if sum((args.check, args.scan, args.backfill)) != 1:
        parser.error("Genau eine Option aus --check, --scan oder --backfill ist erforderlich")
    sender = sender_functions()
    settings = crm_settings()
    config = sender["load_config"]()
    if not config.get("telegram", "bot_token"):
        raise RuntimeError("Der vorhandene Telegram-Bot ist nicht konfiguriert.")
    if args.check:
        print("CRM-Mitschnittkopie konfiguriert; es wurde keine Datei versendet.")
        return 0

    import fcntl
    PROCESS_LOCK.parent.mkdir(parents=True, exist_ok=True)
    with PROCESS_LOCK.open("w", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0
        LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
        logging.basicConfig(filename=LOG_PATH, level=logging.INFO,
                            format="%(asctime)s %(levelname)s %(message)s")
        mysql_query = sender["mysql_query"]
        ensure_scan_table(mysql_query)
        if not START_MARKER.exists():
            START_MARKER.touch()
        for path, source_message_id in pending_paths(
                mysql_query, int(START_MARKER.stat().st_mtime), args.backfill):
            try:
                process(path, source_message_id, sender, settings, mysql_query, logging)
                if args.backfill:
                    time.sleep(3.2)
            except Exception:
                logging.exception("Unbehandelter Scanfehler: %s", path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
