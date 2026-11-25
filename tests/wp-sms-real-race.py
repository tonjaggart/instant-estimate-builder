#!/usr/bin/env python3
"""Compatibility entrypoint for the expanded multi-recipient MySQL race tests."""
from pathlib import Path
import runpy

runpy.run_path(str(Path(__file__).with_name('wp-sms-multiple-race.py')), run_name='__main__')
