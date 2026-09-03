#!/usr/bin/env python3
"""Eng begrenztes Click-to-Call-Gateway für Asterisk/FreePBX."""

from __future__ import annotations

import json
import os
import re
import sys
import tempfile
import time
from pathlib import Path

CONFIG_PATH = Path("/etc/crm-speedphone-call/config.json")
MAX_INPUT_BYTES = 8192
EXTENSION_PATTERN = re.compile(r"^[1-9][0-9]{2,7}$")
TARGET_PATTERN = re.compile(r"^(?:0|00)[0-9]{4,19}$")
REQUEST_PATTERN = re.compile(r"^[a-f0-9-]{36}$", re.IGNORECASE)


def fail(message: str, exit_code: int = 1) -> None:
    print(json.dumps({"success": False, "error": message}, ensure_ascii=False))
    raise SystemExit(exit_code)


def load_config() -> dict:
    try:
        data = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        fail("Die Konfiguration des Telefonanlagen-Gateways ist ungültig.")
    if not isinstance(data, dict):
        fail("Die Konfiguration des Telefonanlagen-Gateways ist ungültig.")
    return data


def read_request() -> dict:
    raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        fail("Der Anrufauftrag ist zu groß.")
    try:
        data = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        fail("Der Anrufauftrag ist kein gültiges JSON-Dokument.")
    if not isinstance(data, dict):
        fail("Der Anrufauftrag ist ungültig.")
    return data


def clean_text(value: object, maximum: int) -> str:
    return re.sub(r"[\r\n\x00-\x1f]+", " ", str(value)).strip()[:maximum]


def main() -> None:
    config = load_config()
    request = read_request()
    if request.get("action") != "bridge":
        fail("Diese Gateway-Aktion ist nicht erlaubt.")

    extension = clean_text(request.get("extension", ""), 8)
    target = clean_text(request.get("target", ""), 20)
    request_id = clean_text(request.get("request_id", ""), 36)
    display_name = clean_text(request.get("display_name", "Zielkontakt"), 80) or "Zielkontakt"
    allowed_extensions = {str(item) for item in config.get("allowed_extensions", [])}
    if not EXTENSION_PATTERN.fullmatch(extension) or extension not in allowed_extensions:
        fail("Diese Mitarbeiter-Durchwahl ist nicht freigegeben.")
    if not TARGET_PATTERN.fullmatch(target):
        fail("Die Zielrufnummer ist nicht zulässig.")
    if not REQUEST_PATTERN.fullmatch(request_id):
        fail("Die Auftrags-ID ist ungültig.")

    context = clean_text(config.get("context", "from-internal"), 40)
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,40}", context):
        fail("Der konfigurierte Wählkontext ist ungültig.")
    wait_time = max(10, min(120, int(config.get("wait_time_seconds", 45))))
    spool_root = Path(str(config.get("spool_root", "/var/spool/asterisk")))
    temp_directory = spool_root / "tmp"
    outgoing_directory = spool_root / "outgoing"
    if not temp_directory.is_dir() or not outgoing_directory.is_dir():
        fail("Das Asterisk-Spoolverzeichnis ist nicht verfügbar.")

    job_id = f"speedphone-{int(time.time())}-{request_id}"
    call_file = "\n".join(
        [
            f"Channel: Local/{extension}@{context}/n",
            f"CallerID: CRM SpeedPhone <{extension}>",
            "MaxRetries: 0",
            "RetryTime: 60",
            f"WaitTime: {wait_time}",
            f"Context: {context}",
            f"Extension: {target}",
            "Priority: 1",
            f"Setvar: __SPEEDPHONE_REQUEST_ID={request_id}",
            f"Setvar: __SPEEDPHONE_DISPLAY_NAME={display_name}",
            "Archive: yes",
            "",
        ]
    )

    temporary_path = ""
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=temp_directory,
            prefix="speedphone-",
            suffix=".call",
            delete=False,
        ) as handle:
            temporary_path = handle.name
            handle.write(call_file)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary_path, 0o640)
        os.replace(temporary_path, outgoing_directory / f"{job_id}.call")
    except OSError:
        if temporary_path:
            try:
                os.unlink(temporary_path)
            except OSError:
                pass
        fail("Der Anrufauftrag konnte nicht an Asterisk übergeben werden.")

    print(json.dumps({"success": True, "job_id": job_id}, ensure_ascii=False))


if __name__ == "__main__":
    main()
