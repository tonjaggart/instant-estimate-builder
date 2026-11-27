<?php
/** Application-local admission fence. The operator pre-creates this inode; never replace it. */
function ieb_maintenance_acquire(int $operation) {
    if ($operation!==LOCK_SH && $operation!==LOCK_EX) return false;
    $path=dirname(__DIR__).'/maintenance.lock';
    clearstatcache(true,$path);
    $before=@lstat($path);
    if (!$before || ($before['mode'] & 0170000)!==0100000 || $before['nlink']!==1) return false;
    $handle=@fopen($path,'r');
    if (!$handle) return false;
    $opened=fstat($handle);
    clearstatcache(true,$path);
    $after=@lstat($path);
    if (!$opened || !$after || ($after['mode'] & 0170000)!==0100000 || $after['nlink']!==1 ||
        $before['dev']!==$opened['dev'] || $before['ino']!==$opened['ino'] ||
        $after['dev']!==$opened['dev'] || $after['ino']!==$opened['ino'] ||
        !flock($handle,$operation|LOCK_NB)) {
        fclose($handle); return false;
    }
    // The caller retains this resource for its entire request/worker lifetime.
    return $handle;
}
function ieb_maintenance_shared() { return ieb_maintenance_acquire(LOCK_SH); }
