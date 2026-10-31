<?php
$path=__DIR__.'/../writable/data/worker-v3-heartbeat';
exit(is_file($path) && time()-(int)file_get_contents($path)<75 ? 0 : 1);
