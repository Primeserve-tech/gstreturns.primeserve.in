<?php
$path=__DIR__.'/../writable/data/janitor-v3-heartbeat';
exit(is_file($path) && time()-(int)file_get_contents($path)<10 ? 0 : 1);
