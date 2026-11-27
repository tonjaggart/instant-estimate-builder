#!/usr/bin/env python3
"""Synthetic tests for the staging-only scoped WP-Cron bridge."""
import importlib.util
import pathlib
import subprocess
import unittest

path = pathlib.Path(__file__).resolve().parent.parent / 'sms-relay' / 'heritage-wp-sms-cron.py'
spec = importlib.util.spec_from_file_location('heritage_sms_cron', path)
assert spec is not None and spec.loader is not None
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)

class Result:
    def __init__(self, stdout=''):
        self.stdout = stdout

class ScopedCronTest(unittest.TestCase):
    def test_only_allowlisted_due_hooks_are_run(self):
        calls = []
        def execute(args, **kwargs):
            calls.append(args)
            if args[1] == 'eval':
                return Result('["ieb_sms_dispatch_lead","wp_version_check","ieb_sms_recover_pending","ieb_sms_dispatch_lead"]')
            return Result()
        bridge.run_due('wp', '/fixture', execute)
        self.assertEqual(calls[1], ['wp', 'cron', 'event', 'run', 'ieb_sms_dispatch_lead', 'ieb_sms_recover_pending', '--due-now', '--quiet'])
        self.assertEqual(len(calls), 2)

    def test_no_due_hooks_means_no_cron_invocation(self):
        calls = []
        def execute(args, **kwargs):
            calls.append(args)
            return Result('[]')
        bridge.run_due('wp', '/fixture', execute)
        self.assertEqual(len(calls), 1)

    def test_invalid_discovery_fails_closed(self):
        calls = []
        def execute(args, **kwargs):
            calls.append(args)
            return Result('{not-json')
        with self.assertRaises(ValueError):
            bridge.run_due('wp', '/fixture', execute)
        self.assertEqual(len(calls), 1)

    def test_failed_discovery_fails_closed(self):
        calls = []
        def execute(args, **kwargs):
            calls.append(args)
            raise subprocess.CalledProcessError(1, args)
        with self.assertRaises(subprocess.CalledProcessError):
            bridge.run_due('wp', '/fixture', execute)
        self.assertEqual(len(calls), 1)

if __name__ == '__main__':
    unittest.main()
