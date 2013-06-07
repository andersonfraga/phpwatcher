#!/usr/bin/php
<?php

include 'phpwatcher.php';

phpwatcher('/www/', '(.*)\.php', function($file) {
    echo date('Y-m-d H:i:s') . ": {$file} changed! Running phpunit...\n";
    system('cd /www/site/test/ && phpunit');
    echo PHP_EOL;
});
