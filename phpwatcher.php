<?php

function phpwatcher($path, $pattern, \Closure $func)
{
    $watch = new Phpwatcher($path, $pattern);

    $watch->isUpdated();
    echo "{$watch->getNumFiles()} files in standby\n";

    while(1) {
        if($updated = $watch->isUpdated()) {
            $func($updated);
        }

        sleep(1);
    }
}

class Phpwatcher
{
    private $_pattern, $_path;
    private $_cache = array();

    public function __construct($path, $pattern)
    {
        $this->_path    = $path;
        $this->_pattern = $pattern;
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

    public function getNumFiles()
    {
        return count($this->_cache);
    }

    private function _populate()
    {
        $ite    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->_path));
        $files  = new RegexIterator($ite, '/' . $this->_pattern . '/', RecursiveRegexIterator::GET_MATCH);

        foreach($files as $file) {
            $file = $file[0];
            $this->setCache($file, filemtime($file));
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
