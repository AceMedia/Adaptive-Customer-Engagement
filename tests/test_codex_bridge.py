"""Exercise the Codex bridge without credentials or model requests."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


BRIDGE = Path(__file__).resolve().parents[1] / "bin" / "ace-codex-bridge"


class CodexBridgeTest(unittest.TestCase):
    def run_case(self, scenario):
        with tempfile.TemporaryDirectory() as root:
            fake = Path(root) / "codex"
            fake.write_text(textwrap.dedent("""\
                #!/usr/bin/env python3
                import json, os, pathlib, sys, time
                if sys.argv[1:3] == ['login', 'status']:
                    sys.exit(0)
                assert sys.argv[1] == 'exec'
                assert sys.argv[-1] == '-'
                prompt = sys.stdin.read()
                assert 'Reply briefly' in prompt
                scenario = os.environ['FAKE_SCENARIO']
                if scenario == 'timeout':
                    time.sleep(3)
                elif scenario == 'answer':
                    pathlib.Path(sys.argv[sys.argv.index('-o') + 1]).write_text('Ready.')
                    print(json.dumps({'type':'turn.completed','usage':{'input_tokens':4,'output_tokens':2}}))
                elif scenario == 'rate_limit':
                    print(json.dumps({'type':'turn.failed','error':{'code':'rate_limit_exceeded','message':'private prompt must never appear'}}))
                    print('Reading additional input from stdin...', file=sys.stderr)
                    sys.exit(1)
                elif scenario == 'empty':
                    print(json.dumps({'type':'turn.completed'}))
                elif scenario == 'top_level_error':
                    print(json.dumps({'type':'error','code':'auth_failed','message':'private prompt must never appear'}))
                    sys.exit(1)
                elif scenario == 'stale_answer':
                    pathlib.Path(sys.argv[sys.argv.index('-o') + 1]).write_text('Stale.')
                    print(json.dumps({'type':'turn.failed','error':'model not found'}))
                elif scenario == 'recovered':
                    pathlib.Path(sys.argv[sys.argv.index('-o') + 1]).write_text('Ready.')
                    print(json.dumps({'type':'error','error':'temporary transport error'}))
                    print(json.dumps({'type':'turn.completed'}))
                elif scenario == 'stderr':
                    print('private prompt must never appear', file=sys.stderr)
                    sys.exit(1)
                else:
                    raise AssertionError(scenario)
            """))
            fake.chmod(0o755)
            env = dict(os.environ, ACE_CODEX_BIN=str(fake), ACE_CODEX_ROOT=root,
                       ACE_CODEX_TIMEOUT="1", FAKE_SCENARIO=scenario)
            request = {"messages": [{"role": "user", "content": "Reply briefly."}]}
            result = subprocess.run([str(BRIDGE), "ask", "site-1"],
                                    input=json.dumps(request), text=True,
                                    capture_output=True, env=env, timeout=8)
            self.assertNotIn("private prompt", result.stdout + result.stderr)
            self.assertNotIn("Reading additional", result.stdout + result.stderr)
            return result.returncode, json.loads(result.stdout)

    def test_answer(self):
        code, body = self.run_case("answer")
        self.assertEqual(0, code)
        self.assertEqual("Ready.", body["message"])
        self.assertEqual(2, body["usage"]["output_tokens"])

    def test_structured_failure(self):
        code, body = self.run_case("rate_limit")
        self.assertEqual(1, code)
        self.assertEqual("rate_limited", body["error_code"])

    def test_empty_output(self):
        code, body = self.run_case("empty")
        self.assertEqual(1, code)
        self.assertEqual("empty_answer", body["error_code"])

    def test_stderr_is_not_public(self):
        code, body = self.run_case("stderr")
        self.assertEqual(1, code)
        self.assertEqual("transport_error", body["error_code"])

    def test_top_level_error(self):
        code, body = self.run_case("top_level_error")
        self.assertEqual(1, code)
        self.assertEqual("sign_in_required", body["error_code"])

    def test_stale_answer_is_failure(self):
        code, body = self.run_case("stale_answer")
        self.assertEqual(1, code)
        self.assertEqual("model_unavailable", body["error_code"])

    def test_recovered_error_is_success(self):
        code, body = self.run_case("recovered")
        self.assertEqual(0, code)
        self.assertEqual("Ready.", body["message"])

    def test_timeout(self):
        code, body = self.run_case("timeout")
        self.assertEqual(1, code)
        self.assertEqual("timed_out", body["error_code"])


if __name__ == "__main__":
    unittest.main()
