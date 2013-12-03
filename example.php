#!/usr/bin/php
<?php

include 'phpwatcher.php';

$paths = array(
    realpath('./module/'),
    realpath('./tests/'),
);

phpwatcher($paths, '(.*)\.php$', function($file) {
    $run = "phpunit --bootstrap tests/unit/_bootstrap.php";

    system('clear');
    echo $run . PHP_EOL;
    system($run);
    echo PHP_EOL;
});
