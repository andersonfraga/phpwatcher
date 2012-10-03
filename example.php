#!/usr/bin/php
<?php

include 'phpwatcher.php';

phpwatcher('/www/*.php', function($file) {
	echo $file . PHP_EOL;
});