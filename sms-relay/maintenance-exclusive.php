<?php
/** Run a reviewed in-process maintenance plan only after all shared holders drain. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if ($argc!==2 || !is_file($argv[1]) || realpath($argv[1])!==$argv[1]) { fwrite(STDERR,"Absolute maintenance plan required\n"); exit(64); }
require_once __DIR__.'/maintenance-lock.php';
$maintenanceLock=ieb_maintenance_acquire(LOCK_EX);
if (!$maintenanceLock) { fwrite(STDERR,"Maintenance gate unavailable or busy\n"); exit(75); }
// The plan runs synchronously in this PHP process, not a detached subprocess.
(function (string $plan): void { require $plan; })($argv[1]);
