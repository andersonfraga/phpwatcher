<?php

function phpwatcher($glob, \Closure $func) 
{
	$watch = new Phpwatcher($glob);

	while(1) {
		if($updated = $watch->isUpdated()) {
			$func($updated);
			sleep(5);
		}

		sleep(1);
	}
}

class Phpwatcher 
{
	private $_pattern;
	private $_cache = array();

	public function __construct($glob)
	{
		$this->_pattern = $glob;
	}

	public function isUpdated()
	{
		if(empty($this->_cache)) {
			$this->_populate();
		}

		foreach($this->_cache as $file => $mtime) {
			$new_mtime = filemtime($file);

			if($new_mtime != $mtime) {
				$this->setCache($file, $new_mtime);
				$this->sortCache();

				return $file;
			}
		}

		return false;
	}

	private function _populate()
	{
		foreach (new GlobIterator($this->_pattern, FilesystemIterator::SKIP_DOTS) as $file) {
			$this->setCache($file->getPathname(), $file->getMTime());
		}

		$this->sortCache();
	}

	private function setCache($file, $mtime)
	{
		$this->_cache[$file] = $mtime;
	}

	private function sortCache()
	{
		asort($this->_cache);
	}
};