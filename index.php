<?php

class hashIndex extends format
{
        
        const DAT_DIR = '/var/mregister/';
        const INDEX_FILE_EXT = '.idx';
        const LOCATION_FILE_EXT = '.loc';
        const OPEN_MODE = 'c+';
        
        private $indexHandle;
        private $locationHandle;
        public $index;
        public $table;
        
        public function __construct($table)
        {
                $this->table = $table;
                
                $indexFile = self::DAT_DIR . $table . self::INDEX_FILE_EXT;
                $locationFile = self::DAT_DIR . $table . self::LOCATION_FILE_EXT;
                
                $this->indexHandle = fopen($indexFile, self::OPEN_MODE);
                $this->locationHandle = fopen($locationFile, self::OPEN_MODE);
                if (!$this->indexHandle || !$this->locationHandle)
                {
                        throw new Exception('Could not open files.');
                }
        }
        
        public function write($data, $indexString)
        {
                $key = (int) crc32($indexString);
                $this->index[$key][$indexString] = $value;
                $this->indexChanged = true;
        }
        
}

class index extends format
{

        const DAT_DIR = '/var/mregister/';
        const FILE_EXT = '.idx';
        const OPEN_MODE = 'c+';

        public $table;
        public $indexHandle;
        private $index;
        private $indexChanged = false;
        static $instance;

        public function __construct($table)
        {
                $this->table = $table;
                $file = self::DAT_DIR . $table . self::FILE_EXT;
                if (!$this->indexHandle = fopen($file, self::OPEN_MODE))
                {
                        throw new Exception('Could not open index file.');
                }

                if (filesize($file) > 0)
                {
                        $this->readIndexFromDisk();
                }
        }

        public function __destruct()
        {
                if (is_resource($this->indexHandle))
                {
                        if (!empty($this->index) && $this->indexChanged == true)
                        {
                                $this->writeIndexToDisk();
                        }
                        fclose($this->indexHandle);
                }
        }

        public static function init()
        {
                foreach (glob(self::DAT_DIR . '*' . self::FILE_EXT) as $filename)
                {
                        self::singleton(basename($filename, self::FILE_EXT));
                }
        }

        public static function singleton($table)
        {
                self::$instance[$table] || self::$instance[$table] = new index($table);
                return self::$instance[$table];
        }

        /* Index
         * 4 BYTES of INDEX COUNT
         * ENTRY = 
         *      4 BYTES OF KEY (crc32)
         *      2 BYTES OF COUNT OF ELEMENTS
         *      ELEMENTS =
         *      2 BYTES OF INDEX STRING SIZE
         *      4 BYTES OF ROW NUMBER
         *      N BYTES OF INDEX STRING
         */

        public function writeIndexToDisk()
        {

                fseek($this->indexHandle, 0);
                $bin = pack(self::T_INDEX_INDEX_COUNT, count($this->index));

                foreach ($this->index as $key => $array)
                {
                        // array = array('indexString' => num, 'indexString => num)
                        $b_arrayString = '';
                        $b_arrayLength = 0;
                        foreach ($array as $indexString => $rowNum)
                        {
                                // arrayString segment = length of indexString, rowNum, indexString
                                $packString = self::T_INDEX_STRING_SIZE . self::T_INDEX_ROW_NUM . self::T_INDEX_STRING . strlen($indexString);
                                $b_arrayString .= pack($packString, strlen($indexString), $rowNum, $indexString);
                                $b_arrayLength += self::sumSizes(self::T_INDEX_STRING_SIZE, self::T_INDEX_ROW_NUM, strlen($indexString));
                        }
                        // key, count of sub array, arrayString
                        $bin .= pack(self::T_INDEX_KEY . self::T_INDEX_ELEMENTS, $key, count($array)) . $b_arrayString;
                }

                if (fwrite($this->indexHandle, $bin, strlen($bin)) === false)
                {
                        ftruncate($this->indexHandle, 0);
                        throw new Exception('Unable to write index');
                }
                //dat::printHexDump($toWrite);
                return true;
        }

        public function readIndexFromDisk()
        {

                fseek($this->indexHandle, 0);
                $b_header = fread($this->indexHandle, self::sumSizes(self::T_INDEX_INDEX_COUNT));
                $headerString = self::unpackString('INDEX_INDEX_COUNT');
                $header = unpack($headerString, $b_header);

                for ($c = $header['INDEX_INDEX_COUNT'], $i = 0; $c > $i; $i++)
                {

                        // get key and count of sub array
                        $buffer = fread($this->indexHandle, self::sumSizes(self::T_INDEX_KEY, self::T_INDEX_ELEMENTS));
                        $row = unpack(self::unpackString('INDEX_KEY', 'INDEX_ELEMENTS'), $buffer);
                        for ($c2 = $row['INDEX_ELEMENTS'], $e = 0; $c2 > $e; $e++)
                        {
                                $b_rowHeader = fread($this->indexHandle, self::sumSizes(self::T_INDEX_STRING_SIZE, self::T_INDEX_ROW_NUM));
                                $rowHeader = unpack(self::unpackString('INDEX_STRING_SIZE', 'INDEX_ROW_NUM'), $b_rowHeader);
                                $b_indexString = fread($this->indexHandle, $rowHeader['INDEX_STRING_SIZE']);
                                $string = unpack(self::T_INDEX_STRING . $rowHeader['INDEX_STRING_SIZE'] . 'INDEX_STRING', $b_indexString);
                                $this->index[$row['INDEX_KEY']][$string['INDEX_STRING']] = $rowHeader['INDEX_ROW_NUM'];
                        }
                }
        }

        public function deleteFromIndex($indexString)
        {
                $key = (int) crc32($indexString);
                if (isset($this->index[$key][$indexString]))
                {
                        unset($this->index[$key][$indexString]);
                        return true;
                }
                return false;
        }

        public static function truncate($table)
        {
                return self::singleton($table)->remove();
        }

        public function remove()
        {
                unset($this->index);
                ftruncate($this->indexHandle, 0);
        }

        public static function delete($table, $indexString)
        {
                return self::singleton($table)->deleteFromIndex($indexString);
        }

        public static function write($table, $indexString, $value)
        {
                return self::singleton($table)->writeToIndex($indexString, $value);
        }

        public static function search($table, $indexString)
        {
                return self::singleton($table)->readFromIndex($indexString);
        }

        public static function destruct($table)
        {
                self::singleton($table)->__destruct();
        }

        public static function lock($table)
        {
                return self::singleton($table)->writeLock();
        }

        public function writeLock()
        {
                if (!flock($this->indexHandle, LOCK_SH | LOCK_NB))
                {
                        return false;
                }
                return true;
        }

        public function readFromIndex($indexString)
        {
                $key = (int) crc32($indexString);
                if (isset($this->index[$key][$indexString]))
                {
                        return $this->index[$key][$indexString];
                }
                return false;
        }

        public function writeToIndex($indexString, $value)
        {
                $key = (int) crc32($indexString);
                $this->index[$key][$indexString] = $value;
                $this->indexChanged = true;
        }

}