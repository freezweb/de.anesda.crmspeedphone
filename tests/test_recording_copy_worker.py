"""Isolierte Tests für die PBX-Mitschnittkopie ohne Telegram-Versand."""

import importlib.util
import datetime as dt
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "infra/freepbx/crm-speedphone-recording-copy.py"
SPEC = importlib.util.spec_from_file_location("recording_copy_worker", SCRIPT)
worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(worker)


class FakeResponse:
    status_code = 200

    def json(self):
        return {"ok": True, "result": {"message_id": 9876}}


class RecordingCopyWorkerTests(unittest.TestCase):
    def test_local_channel_contains_originating_extension(self):
        self.assertEqual(
            worker.originating_extension("Local/6006@from-internal-000002aa;2"), "6006"
        )
        self.assertEqual(worker.originating_extension("PJSIP/3002-000002aa"), "")
        self.assertEqual(worker.originating_extension("Local/6006@other-context-000002aa;2"), "")

    def test_claim_uses_local_extension_instead_of_filename_fallback(self):
        with tempfile.TemporaryDirectory() as directory:
            recording = Path(directory) / "out-test.wav"
            recording.write_bytes(b"test")
            metadata = {
                "calldate": "2026-10-20 10:00:00",
                "linkedid": "1792483200.123",
                "extension": "1002",
                "dst": "038780579999",
                "channel": "Local/6006@from-internal-000002aa;2",
            }
            with patch.object(worker, "ZoneInfo", return_value=dt.timezone.utc):
                with patch.object(worker, "post_crm", return_value={"send": True}) as post:
                    _, claim = worker.recording_claim(recording, metadata, {})
            self.assertTrue(claim["send"])
            self.assertEqual(post.call_args.args[1]["extension"], "6006")

    def test_copy_preserves_original_caption_and_uses_marketing_thread(self):
        class Config:
            def get(self, section, key):
                return {"bot_token": "test-token", "chat_id": "-100111"}[key]

        sender = {"load_config": lambda: Config()}
        match = {"chat_id": -100222, "thread_id": 838}
        with patch.dict(os.environ, {"CRM_SPEEDPHONE_TELEGRAM_API_BASE": "http://127.0.0.1:8081"}):
            with patch.object(worker.requests, "post", return_value=FakeResponse()) as post:
                message_id = worker.send_copy(sender, 1234, match)
        self.assertEqual(message_id, 9876)
        self.assertTrue(post.call_args.args[0].endswith("/copyMessage"))
        self.assertEqual(post.call_args.kwargs["data"], {
            "chat_id": "-100222",
            "message_thread_id": "838",
            "from_chat_id": "-100111",
            "message_id": "1234",
        })

    def test_backfill_includes_previously_skipped_matches(self):
        queries = []

        def mysql_query(query, database):
            queries.append(query)
            return "/var/spool/asterisk/monitor/out-test.wav\t1234\n"

        self.assertEqual(worker.pending_paths(mysql_query, 123, True), [
            (Path("/var/spool/asterisk/monitor/out-test.wav"), 1234)
        ])
        self.assertIn("scans.status='skipped'", queries[0])
        self.assertIn("recordings.telegram_message_id>0", queries[0])
        self.assertNotIn("scans.status='sent'", queries[0])


if __name__ == "__main__":
    unittest.main()
