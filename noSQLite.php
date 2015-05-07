<?php

var_dump($_SERVER['argv']);

class stdin
{
        
        public static $inputs = [
            '-n' => 'name',
            '-t' => 'trySequence',
            '-p' => 'pid',
            '-l' => 'location',
            '-s' => 'status',
        ];
        
        function __construct()
        {
                $current = '';
                for ($c = count($_SERVER['argv']) - 1, $i = 1; $c >= $i; $i++)
                {
                        if (!empty(self::$inputs[$_SERVER['argv'][$i]]))
                        {
                                $current = self::$inputs[$_SERVER['argv'][$i]];
                        }
                        elseif (!empty($current))
                        {
                                if (empty($this->{$current}))
                                {
                                        $this->{$current} = trim($_SERVER['argv'][$i]);
                                }
                                else
                                {
                                        $this->{$current} .= ' ' . trim($_SERVER['argv'][$i]);
                                }
                        }
                }
        }

}

class index
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
                $toWrite = pack('L', count($this->index));
                $size = 4;

                foreach ($this->index as $key => $array)
                {
                        // array = array('indexString' => num, 'indexString => num)
                        $b_arrayString = '';
                        $b_arrayLength = 0;
                        foreach ($array as $indexString => $rowNum)
                        {
                                // arrayString segment = length of indexString, indexString, rowNum
                                $b_arrayString .= pack('SLa' . strlen($indexString), strlen($indexString), $rowNum, $indexString);
                                $b_arrayLength += 2 + 4 + strlen($indexString);
                        }
                        // key, count of sub array, arrayString
                        $toWrite .= pack('LS', $key, count($array)) . $b_arrayString;
                        $size += 4 + 2 + $b_arrayLength;
                }

                if (fwrite($this->indexHandle, $toWrite, $size) === false)
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
                $b_header = fread($this->indexHandle, 4);
                $header = unpack('Lrows', $b_header);

                for ($c = $header['rows'], $i = 0; $c > $i; $i++)
                {

                        // get key and count of sub array
                        $buffer = fread($this->indexHandle, 6);
                        $row = unpack('Lkey/SarrayCount', $buffer);

                        for ($c2 = $row['arrayCount'], $e = 0; $c2 > $e; $e++)
                        {
                                $b_rowHeader = fread($this->indexHandle, 6);
                                $rowHeader = unpack('Slength/LrowNum', $b_rowHeader);
                                $b_indexString = fread($this->indexHandle, $rowHeader['length']);
                                $string = unpack('a' . $rowHeader['length'] . 'indexString', $b_indexString);

                                $this->index[$row['key']][$string['indexString']] = $rowHeader['rowNum'];
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

class dat
{

        const DAT_DIR = '/var/mregister/';
        const DATA_FILE_EXT = '.dat';
        const OPEN_MODE = 'c+';
        
        public static $variableSizes = array(
            'S' => 2,
            'L' => 4,
        );
        
        const T_HEADER_LENGTH = 'L';
        const T_FIELD_LENGTH = 'L';
        const T_FIELD_COUNT = 'S';
        const T_INDEX_LENGTH = 'L';
        const T_INDEX_COUNT = 'S';
        const T_ROW_COUNT = 'L';

        private $dataHandle;
        private $table;
        private $rowCount;
        private $rowCountPosition;
        private $rowLength = 0;
        private $lengthTilData;
        private $unpacked;
        public $fields;
        public $indexes;
        public $count = 0;

        public function __construct($table)
        {
                $this->table = $table;
                if (!$this->dataHandle = fopen(self::DAT_DIR . $table . self::DATA_FILE_EXT, self::OPEN_MODE))
                {
                        throw new Exception('Could not open dat file.');
                }
                index::init();
        }

        public function __destruct()
        {
                if (is_resource($this->dataHandle))
                {
                        fclose($this->dataHandle);
                }
        }

        /*
         * TABLE HEADER:
         *      4 BYTES HEADER LENGTH
         *      4 BYTES FIELD LENGTH
         *      2 BYTES FIELD COUNT
         *      4 BYTES INDEX LENGTH
         *      2 BYTES INDEX COUNT
         *              FIELD:
         *              2 BYTES SUB FIELD COUNT (0 for key => bytes, 1+ for key => array)
         *              2 BYTES name LENGTH = N
         *              N BYTES name
         *              2 BYTES field length (00 00 for array)
         *                      SUBFIELD:
         *                      2 BYTES SUB FIELD COUNT
         *                      2 BYTES name LENGTH = N
         *                      N BYTES name
         *                      2 BYTES field length (00 00 for array)
         *              FIELD:
         *              2 BYTES SUB FIELD COUNT
         *              2 BYTES name LENGTH = N
         *              N BYTES name
         *              2 BYTES field length
         *                      SUBFIELD:
         *                      2 BYTES SUB FIELD COUNT
         *                      2 BYTES name LENGTH
         *                      N BYTES name
         *                      2 BYTES field length
         *              INDEX:
         *              2 BYTES name LENGTH
         *              N BYTES name
         *              2 BYTES index LENGTH
         * 4 BYTES ROW COUNT
         */
        
        public static function sumSizes()
        {
                
                if (func_num_args() == 0)
                {
                        return 0;
                }
                
                $sum = 0;
                foreach (func_get_args() as $arg)
                {
                        if (isset(self::$variableSizes[$arg]))
                        {
                                $sum += self::$variableSizes[$arg];
                        }
                        elseif (is_numeric($arg))
                        {
                                $sum += $arg;
                        }
                }
                return $sum;
                
        }
        
        public function packHeader($fields, $indexes)
        {
                
                $b_fields = $this->packArraySchema($fields);
                $b_indexes = $this->packArraySchema($indexes);
                
                // pack header
                $fieldLength = strlen($b_fields);
                $indexLength = strlen($b_indexes);
                $fieldCount = count($fields);
                $indexCount = count($indexes);
                
                //$headerLength = 4 + 4 + 2 + 4 + 2 + $fieldLength + $indexLength + 4;
                $headerLength = 
                        self::sumSizes(
                                self::T_HEADER_LENGTH, 
                                self::T_FIELD_LENGTH, 
                                self::T_FIELD_COUNT, 
                                self::T_INDEX_LENGTH, 
                                self::T_INDEX_COUNT, 
                                $fieldLength, 
                                $indexLength, 
                                self::T_ROW_COUNT
                        );
                
                $b_rowCount = pack(self::T_ROW_COUNT, 0);
                $packString = self::T_HEADER_LENGTH . self::T_FIELD_LENGTH . self::T_FIELD_COUNT . self::T_INDEX_LENGTH . self::T_INDEX_COUNT;
                $b_header = pack($packString, $headerLength, $fieldLength, $fieldCount, $indexLength, $indexCount) . $b_fields . $b_indexes . $b_rowCount;
                
                return $b_header;
                
        }
        
        public static function unpackString()
        {
                if (func_num_args() == 0)
                {
                        return false;
                }
                
                $string = '';
                foreach (func_get_args() as $arg)
                {
                        if (defined('self::T_' . strtoupper($arg)))
                        {
                                $string .= constant('self::T_' . strtoupper($arg)) . $arg . '/';
                        }
                }
                
                return rtrim($string, '/');
                
        }
        
        public function unpackHeader($bin)
        {
                //$header = unpack('LheaderLength/LfieldLength/SfieldCount/LindexLength/SindexCount', $bin);
                $header = unpack(
                                self::unpackString(
                                        'HEADER_LENGTH',
                                        'FIELD_LENGTH',
                                        'FIELD_COUNT',
                                        'INDEX_LENGTH',
                                        'INDEX_COUNT'
                                ),
                                $bin
                        );
                //$lengthTilFields = 4 + 4 + 2 + 4 + 2;
                $lengthTilFields = 
                        self::sumSizes(
                                self::T_HEADER_LENGTH, 
                                self::T_FIELD_LENGTH, 
                                self::T_FIELD_COUNT, 
                                self::T_INDEX_LENGTH, 
                                self::T_INDEX_COUNT
                        );
                
                $b_fields = substr($bin, $lengthTilFields, $header['FIELD_LENGTH']);
                $b_indexes = substr($bin, $lengthTilFields + $header['FIELD_LENGTH'], $header['INDEX_LENGTH']);
                
                $this->fields = $this->unpackArraySchema($b_fields, $header['FIELD_COUNT']);
                $this->indexes = $this->unpackArraySchema($b_indexes, $header['INDEX_COUNT']);
                
                $this->rowCountPosition = $lengthTilFields + $header['FIELD_LENGTH'] + $header['INDEX_LENGTH'];
                $b_rowCount = substr($bin, $this->rowCountPosition, 4);
                
                $this->rowCount = unpack(self::unpackString('ROW_COUNT'), $b_rowCount)['ROW_COUNT'];
                $this->lengthTilData = $this->rowCountPosition + 4;
                
                return true;
        }
        
        public function rowLength()
        {
                if ($this->rowLength > 0)
                {
                        return $this->rowLength;
                }
                $this->rowLength = $this->calculateArrayLength($this->fields);
                return $this->rowLength;
        }
        
        public function calculateArrayLength($array)
        {
                $length = 0;
                foreach ($array as $name => $data)
                {
                        if (is_array($data))
                        {
                                $length += $this->calculateArrayLength($data);
                        }
                        elseif (is_numeric($data))
                        {
                                $length += $data;
                        }
                }
                return $length;
        }
        
        public function packArray($array, $data, $bin = '')
        {
                
                if (empty($data))
                {
                        $data = array();
                }
                
                foreach ($array as $name => $element)
                {
                        if (is_array($element))
                        {
                                $bin .= $this->packArray($element, $data[$name], $bin);
                        }
                        elseif (is_numeric($element))
                        {
                                if (isset($data[$name]) && !empty($data[$name]))
                                {
                                        // element is # of bytes
                                        $bin .= pack('A' . $element, $this->binString($data[$name], $element));
                                }
                                else
                                {
                                        $bin .= pack('A' . $element, $this->binString('', $element));
                                }
                        }
                }
                return $bin;
        }
        
        public function packArraySchema($array, $fieldString = '')
        {

                foreach ($array as $name => $data)
                {
                        if (is_array($data))
                        {
                                // 2 BYTES ARRAY COUNT , 2 BYTES name LENGTH = N, N BYTES name, 2 BYTES 00 00
                                $string = 'SSa' . strlen($name). 'S';
                                $fieldString .= pack($string, count($data), strlen($name), $name, 0);
                                $fieldString = $this->packArraySchema($data, $fieldString);
                        }
                        elseif (is_numeric($data))
                        {
                                $string = 'SSa' . strlen($name). 'S';
                                $fieldString .= pack($string, 0, strlen($name), $name, $data);
                        }
                }
                
                return $fieldString;
        }
        
        public function unpackArraySchema($bin, $fieldCount)
        {
                for ($i=0;$fieldCount>$i;$i++)
                {
                        $soFar = 0;
                        $meta = unpack('SfieldCount/SnameLength', substr($bin, $soFar, 4));
                        $soFar += 4;
                        $data = unpack('a' . $meta['nameLength'] . 'name/Sbytes', substr($bin, $soFar, 2 + $meta['nameLength']));
                        $soFar += 2 + $meta['nameLength'];
                        
                        if ($meta['fieldCount'] > 0)
                        {
                                list($array[$data['name']], $bin) = $this->unpackArraySchema(substr($bin, $soFar), $meta['fieldCount'], false);
                        }
                        else
                        {
                                $array[$data['name']] = $data['bytes'];
                                $bin = substr($bin, $soFar);
                        }
                }
                if ($bin)
                {
                        return array($array, $bin);
                }
                return $array;
        }
        
        public function create($fields, $indexes)
        {
                
                $b_header = $this->packHeader($fields, $indexes);
                $this->unpackHeader($b_header);
                
                $this->writeLock();
                ftruncate($this->dataHandle, 0);
                index::truncate($this->table);
                fwrite($this->dataHandle, $b_header, strlen($b_header));
                
        }

//        public function create($fields, $indexes)
//        {
//
//                $fields = count($fields);
//                $index = count($indexes);
//                $fieldLengthString = pack('L*', $fields, $index);
//                $fieldString = '';
//                $indexString = '';
//
//                foreach ($fields as $name => $bytes)
//                {
//                        $string = "Sa" . strlen($name) . "S";
//                        $fieldString .= pack($string, strlen($name), $name, $bytes);
//                }
//
//                foreach ($indexes as $name => $bytes)
//                {
//                        $string = "Sa" . strlen($name) . "S";
//                        $indexString .= pack($string, strlen($name), $name, $bytes);
//                }
//
//                $masterString = $fieldLengthString . $fieldString . $indexString;
//                $masterLength = strlen($masterString);
//                $masterString = pack('S', $masterLength) . $masterString . pack('L', 0);
//
//                $this->writeLock();
//                ftruncate($this->dataHandle, 0);
//                index::truncate($this->table);
//                fwrite($this->dataHandle, $masterString, strlen($masterString));
//                $this->printHexDump($masterString);
//                return true;
//        }

//        public function unpackTable()
//        {
//
//                if ($this->unpacked)
//                {
//                        return true;
//                }
//
//                fseek($this->dataHandle, 0);
//                $b_headerSize = fread($this->dataHandle, 2);
//                $unpack = unpack('Sheadersize', $b_headerSize);
//
//                $masterString = fread($this->dataHandle, $unpack['headersize']);
//
//                $getLengths = unpack('Lfields/Lindexes', $masterString);
//                $masterString = substr($masterString, 8);
//
//                for ($i = 0, $c = $getLengths['fields']; $i < $c; $i++)
//                {
//                        $charLength = unpack('S', $masterString);
//                        $unpackString = "Slength/a" . $charLength[1] . "name/Sbytes";
//                        $fields[] = unpack($unpackString, $masterString);
//                        $masterString = substr($masterString, 2 + $charLength[1] + 2);
//                }
//
//                $this->rowLength = 0;
//                foreach ($fields as $array)
//                {
//                        $this->fields[$array['name']] = $array['bytes'];
//                        $this->rowLength += $array['bytes'];
//                }
//
//                for ($i = 0, $c = $getLengths['indexes']; $i < $c; $i++)
//                {
//                        $charLength = unpack('S', $masterString);
//                        $unpackString = "Slength/a" . $charLength[1] . "name/Sbytes";
//                        $indexes[] = unpack($unpackString, $masterString);
//                        $masterString = substr($masterString, 2 + $charLength[1] + 2);
//                }
//
//                foreach ($indexes as $array)
//                {
//                        $this->indexes[$array['name']] = $array['bytes'];
//                }
//
//                $this->rowCountPosition = ftell($this->dataHandle);
//                $this->lengthTilData = $this->rowCountPosition + 4;
//                $b_rowCountOffset = fread($this->dataHandle, 4);
//                $a_rowCountOffset = unpack("Lposition", $b_rowCountOffset);
//                $this->rowCount = $a_rowCountOffset['position'];
//                $this->unpacked = true;
//        }

        public function search($data, $return = false)
        {
                $this->unpackTable();
                $indexString = $this->indexString($data);
                if (strlen($indexString) <= 0)
                {
                        return false;
                }
                //$time_start = microtime(true);
                if ($rowNum = index::search($this->table, $indexString))
                {
                        //$time_end = microtime(true);
                        //$time = $time_end - $time_start;
                        //print "LOOKUP TOOK: " . $time . PHP_EOL;
                        if ($return)
                        {
                                //$time_start = microtime(true);
                                //$return = $this->read($rowNum);
                                //$time_end = microtime(true);
                                //$time = $time_end - $time_start;
                                //print "LOOKUP TOOK: " . $time . PHP_EOL;
                                //return $return;
                                return $this->read($rowNum);
                        }
                        return $rowNum;
                }
                return false;
        }

        public function read($rowNum)
        {
                $this->readLock();
                $this->unpackTable();

                $rowPosition = $this->lengthTilData + ($this->rowLength() * ($rowNum - 1));
                fseek($this->dataHandle, $rowPosition);
                $row = fread($this->dataHandle, $this->rowLength);

                $readSoFar = 0;
                foreach ($this->fields as $name => $bytes)
                {
                        $data[$name] = unpack('A' . $bytes . $name, substr($row, $readSoFar, $bytes))[$name];
                        $readSoFar += $bytes;
                }
                return $data;
        }

        public function delete($rowNum)
        {
                $this->writeLock();
                $this->unpackTable();

                if ($data = $this->read($rowNum))
                {
                        $rowPosition = $this->lengthTilData + ($this->rowLength * ($rowNum - 1));
                        fseek($this->dataHandle, $rowPosition);
                        fwrite($this->dataHandle, pack('a' . $this->rowLength, ''), $this->rowLength);
                        index::delete($this->table, $this->indexString($data));
                }
        }

        public function readLock()
        {
                if (!flock($this->dataHandle, LOCK_SH | LOCK_NB))
                {
                        throw new Exception('Unable to obtain file lock.');
                }
                return true;
        }

        public function indexString($data)
        {
                // preserve order
                $indexString = '';
                foreach ($this->indexes as $name => $bytes)
                {
                        if ((!isset($data[$name]) || !is_string(strval($data[$name]))) && isset($this->indexes[$name]))
                        {
                                $indexString .= '';
                        }
                        elseif (isset($this->indexes[$name]))
                        {
                                $indexString .= $data[$name];
                        }
                }
                return $indexString;
        }
        
        public function unpackTable()
        {
                
                if ($this->unpacked)
                {
                        return true;
                }

                fseek($this->dataHandle, 0);
                $b_headerSize = fread($this->dataHandle, 2);
                $unpack = unpack('Sheadersize', $b_headerSize);

                $b_header = fread($this->dataHandle, $unpack['headersize']);
                $this->unpackHeader($b_headerSize . $b_header);
                
        }
        
        public function write($data, $expire = 0)
        {
                $this->writeLock();
                
        }
        
//        public function write($data, $expire = 0)
//        {
//                $this->writeLock();
//                $this->unpackTable();
//                fseek($this->dataHandle, 0, SEEK_END);
//
//                $byteString = '';
//                foreach ($this->fields as $name => $bytes)
//                {
//                        if (!isset($data[$name]) || !is_string(strval($data[$name])))
//                        {
//                                $byteString .= $this->binString('', $bytes);
//                        }
//                        else
//                        {
//                                $byteString .= $this->binString($data[$name], $bytes);
//                        }
//                }
//
//                $writeData = fwrite($this->dataHandle, $byteString, strlen($byteString));
//                fseek($this->dataHandle, $this->rowCountPosition);
//                $writeRowCount = fwrite($this->dataHandle, pack('L', ++$this->rowCount), 4);
//                if ($writeData && $writeRowCount)
//                {
//                        index::write($this->table, $this->indexString($data), $this->rowCount);
//                        return $this->rowCount;
//                }
//                return false;
//        }

        public function binString($string, $length)
        {
                return pack('A' . $length, str_pad(substr($string, 0, $length), $length, ' ', STR_PAD_RIGHT));
        }

        public function writeLock()
        {
                if (!flock($this->dataHandle, LOCK_EX | LOCK_NB) || !index::lock($this->table))
                {
                        throw new Exception('Unable to obtain dat file lock.');
                }
                return true;
        }

        public static function printHexDump($data, $width = 16, $padding = '.', $newline = "\n")
        {
                $from = '';
                $to = '';
                $offset = 0;

                for ($i = 0; $i <= 0xFF; ++$i)
                {
                        $from .= chr($i);
                        $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $padding;
                }

                $chars = str_split(strtr($data, $from, $to), $width);
                foreach (str_split(bin2hex($data), $width * 2) as $i => $line)
                {
                        echo sprintf('%6d', $offset) . ' : ' . str_pad(implode(' ', str_split($line, 2)), ($width * 3 - 1), ' ') . ' [' . str_pad($chars[$i], $width) . ']' . $newline;
                        $offset += $width;
                }
        }

        function injectData($file, $data, $position)
        {
                $fpFile = fopen($file, "rw+");
                $fpTemp = fopen('php://temp', "rw+");
                stream_copy_to_stream($fpFile, $fpTemp, -1, $position);
                fseek($fpFile, $position);
                fwrite($fpFile, $data);
                rewind($fpTemp);
                stream_copy_to_stream($fpTemp, $fpFile);

                fclose($fpFile);
                fclose($fpTemp);
        }

}

$tableStructure = [
    'fields' => [
        'name' => 128,
        'trysequence' => 256,
        'pid' => 4,
        'location' => 128,
        'status' => 4,
    ],
    'indexes' => [
        'name' => 128,
    ]
];

$fields = [
    'name' => 128,
    'trysequence' => 256,
    'pid' => 4,
    'location' => 128,
    'status' => 4,
    'subMember' => [
        'name' => 128,
        'pid' => 4,
        'brothers' => [
            [
                'name' => 128,
                'pid' => 4
            ],
            [
                'name' => 128,
                'pid' => 4,
            ]
        ]
    ]
];

$dat = new dat('mregister');

//while (true)
//{
//        $input = file_get_contents('php://input');
//        if (!empty($input))
//        {
//                var_dump($input);
//                print PHP_EOL;
//        }
//}


if (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'c')
{
        $fields = [
                'status' => 4,
                'subMember' => [
                    'name' => 128,
                    'pid' => 8,
                    'brothers' => [
                        'next' => 12,
                    ],
                ],
                'last' => 32,
            ];
        $indexes = [
            'status' => 4,
        ];
        var_dump($dat->calculateArrayLength($fields));
        //$bin = $dat->packHeader($fields, $indexes);
        //$dat->unpackHeader($bin);
        //var_dump($dat);
        //$fieldString = $dat->packArray($fields);
        //$array = $dat->unpackArray($fieldString, 3);
        //var_dump($array);
        //print "COUNT: ".$dat->count.PHP_EOL;
//        $dat->create($tableStructure);
//        $dat->unpackTable();
//        var_dump($dat->fields);
//        var_dump($dat->indexes);
        
        
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'r')
{
        $dat->read(1);
        $dat->read(2);
        $dat->read(3);
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'w')
{
        $data = [
            'name' => 'WOBERT',
            'trysequence' => 'ps aux dat shit3',
            'pid' => 1337,
            'location' => '/dev/null',
            'status' => 42,
        ];
        $time_start = microtime(true);
        for ($c = 1000000, $i = 0; $i < $c; $i++)
        {
                $data['name'] = 'WOBERT' . $i;
                $rowNum = $dat->write($data);
        }
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        print "WRITING TOOK: " . $time . PHP_EOL;
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'l')
{
        $search = [
            'name' => $_SERVER['argv'][2]
        ];
        $time_start = microtime(true);
        var_dump($dat->search($search, true));
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        print "LOOKUP TOOK: " . $time . PHP_EOL;
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 's')
{
        $search = [
            'name' => 'WOBERT',
        ];

        $time_start = microtime(true);
        for ($c = 1000000, $i = 0; $i < $c; $i++)
        {
                $search['name'] = 'WOBERT' . $i;
                //$search['name'] = $_SERVER['argv'][2];
                //$results[] = $dat->search($search, true);
                $dat->search($search, true);
                //$results[$rowNum] = $dat->read($rowNum);
        }
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        print "LOOKUP TOOK: " . $time . PHP_EOL;
}