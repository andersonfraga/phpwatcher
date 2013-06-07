<?php

function phpwatcher($path, $pattern, \Closure $func)
{
    $watch = new Phpwatcher($path, $pattern);

    $watch->isUpdated();
    $numFiles = 0;

    while(1) {
        if ($numFiles != $watch->getNumFiles()) {
            $numFiles = $watch->getNumFiles();
            echo "{$watch->getNumFiles()} files in standby\n";
        }

        if (date('s') % 30 == 0) {
            $watch->clearCache();
        }

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


    public function clearCache()
    {
        clearstatcache();
        $this->_cache = array();
    }

    public function isUpdated()
    {
        if(empty($this->_cache)) {
            $this->_populate();
        }

        foreach($this->_cache as $file => $actual_md5) {
            if (!is_file($file)) {
                $this->clearCache();
                $this->_populate();
                return false;
            }

            $new_md5 = md5_file($file);

            if($new_md5 != $actual_md5) {
                $this->setCache($file, $new_md5);
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
            $this->setCache($file, md5_file($file));
        }

        $this->sortCache();
    }

    private function setCache($file, $md5_file)
    {
        $this->_cache[$file] = $md5_file;
    }

    private function sortCache()
    {
        asort($this->_cache);
    }
};
