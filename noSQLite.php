<?php
require_once 'format.php';
require_once 'index.php';
require_once 'meta.php';
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

class noSQLite extends format
{

        const DAT_DIR = '/var/mregister/';
        const DATA_FILE_EXT = '.dat';
        const OPEN_MODE = 'c+';

        private $dataHandle;
        private $table;
        private $meta;
        private $location;

        public function __construct($table)
        {
                $this->table = $table;
                $this->location = self::DAT_DIR . $table . self::DATA_FILE_EXT;
                if (!$this->dataHandle = fopen($this->location, self::OPEN_MODE))
                {
                        throw new Exception('Could not open dat file.');
                }
                
                if (filesize($this->location) > 0)
                {
                        index::init();
                        fseek($this->dataHandle, 0);
                        $b_headerLength = fread($this->dataHandle, self::sumSizes(self::T_HEADER_LENGTH));
                        
                        $unpack = unpack(self::unpackString('HEADER_LENGTH'), $b_headerLength);
                        $b_header = fread($this->dataHandle, $unpack['HEADER_LENGTH']);
                }
                
                $b_meta = $b_headerLength . $b_header;
                $this->meta = new meta($b_meta);
        }

        public function __destruct()
        {
                if (is_resource($this->dataHandle))
                {
                        fclose($this->dataHandle);
                }
        }
        
        public function create($fields, $indexes)
        {
                
                $b_header = $this->meta->packHeader($fields, $indexes);
                $this->meta->unpackHeader($b_header);
                
                $this->writeLock();
                ftruncate($this->dataHandle, 0);
                index::truncate($this->table);
                
                fseek($this->dataHandle, 0);
                fwrite($this->dataHandle, $b_header, strlen($b_header));
                
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
                                if (!isset($data[$name]) || !is_array($data[$name]))
                                {
                                        $data[$name] = array();
                                }
                                $bin .= $this->packArray($element, $data[$name]);
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
        
        public function unpackArray($array, $bin, &$soFar = 0)
        {
                $return = array();
                foreach ($array as $name => $element)
                {
                        if (is_array($element))
                        {
                                $return[$name] = $this->unpackArray($element, $bin, $soFar);
                        }
                        elseif (is_numeric($element))
                        {
                                $return[$name] = unpack('A' . $element . 'array', substr($bin, $soFar, $element))['array'];
                                $soFar += $element;
                        }
                }
                return $return;
        }

        public function search($data, $return = false)
        {
                
                $indexString = $this->indexString($data, $this->meta->indexes);
                if (strlen($indexString) <= 0)
                {
                        return false;
                }
                if ($rowNum = index::search($this->table, $indexString))
                {
                        return $return ? $this->read($rowNum) : $rowNum;
                }
                return false;
        }

        public function delete($rowNum)
        {
                $this->writeLock();
                

                if ($data = $this->read($rowNum))
                {
                        $rowPosition = $this->meta->lengthTilData + ($this->meta->rowLength() * ($rowNum - 1));
                        fseek($this->dataHandle, $rowPosition);
                        fwrite($this->dataHandle, pack('a' . $this->meta->rowLength(), ''), $this->meta->rowLength());
                        index::delete($this->table, $this->indexString($data, $this->meta->indexes));
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

        public function indexString($data, $array)
        {
                // preserve order
                $indexString = '';
                foreach ($array as $name => $element)
                {
                        if (is_array($element) && is_array($data[$name]))
                        {
                                $indexString .= $name . '[a]:' . $this->indexString($data[$name], $element);
                        }
                        elseif (!isset($data[$name]) || !is_string(strval($data[$name])))
                        {
                                $indexString .= $name . ':';
                        }
                        elseif (isset($array[$name]))
                        {
                                $indexString .= $name . ':' . $data[$name];
                        }
                }
                
                return $indexString;
        }
        
        public function write($data, $expire = 0)
        {
                
                $this->writeLock();
                fseek($this->dataHandle, 0, SEEK_END);
                if ($this->meta->static)
                {
                        return $this->writeStatic($data, $expire);
                }
                else
                {
                        return $this->writeDynamic($data, $expire);
                }
        }
        
        public function writeDynamic($data, $expire = 0)
        {
                print "ha HA!" . PHP_EOL;
        }
        
        public function writeStatic($data, $expire = 0)
        {
                
                $b_string = $this->packArray($this->meta->fields, $data);
                $writeData = fwrite($this->dataHandle, $b_string, strlen($b_string));
                
                fseek($this->dataHandle, $this->meta->rowCountPosition);
                $writeRowCount = fwrite($this->dataHandle, pack(self::T_ROW_COUNT, ++$this->meta->rowCount), 4);
                
                if ($writeData && $writeRowCount)
                {
                        index::write($this->table, $this->indexString($data, $this->meta->indexes), $this->meta->rowCount);
                        return $this->meta->rowCount;
                }
                return false;
        }
        
        public function readDynamic($rowNum)
        {
                print "HA!" . PHP_EOL;
        }
        
        public function read($rowNum)
        {
                $this->readLock();
                if ($this->meta->static)
                {
                        return $this->readStatic($rowNum);
                }
                else
                {
                        return $this->readDynamic($rowNum);
                }
        }
        
        public function readStatic($rowNum)
        {
                
                $rowPosition = $this->meta->lengthTilData + ($this->meta->rowLength() * ($rowNum - 1));
                fseek($this->dataHandle, $rowPosition);
                $row = fread($this->dataHandle, $this->meta->rowLength());
                
                $data = $this->unpackArray($this->meta->fields, $row);
                
                return $data;
        }
        
        public function binString($string, $length)
        {
                return pack(self::T_DATA_STRING . $length, str_pad(substr($string, 0, $length), $length, ' ', STR_PAD_RIGHT));
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

$dat = new noSQLite('mregister');

//$array = [
//    'hello' => 15,
//    'noyou' => [
//        'sup' => 'playah',
//    ]
//];
//
//$serialized = igbinary_serialize($array);
//noSQLite::printHexDump($serialized);
//var_dump(igbinary_unserialize($serialized));

if (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'c')
{
        $fields = [
                'status' => 8,
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
            'status' => 8,
            'subMember' => [
                'brothers' => [
                    'next' => 12,
                ]
            ]
        ];
//        $indexes = [
//                'status' => 8,
//                'subMember' => [
//                    'name' => 128,
//                    'pid' => 8,
//                    'brothers' => [
//                        'next' => 12,
//                    ],
//                ],
//                'last' => 32,
//            ];
        $data = [
                'status' => 'ALIVE',
                'subMember' => [
                    'name' => 'JINX, buy me a coke.',
                    'pid' => 49024,
                    'brothers' => [
                        'next' => 'nextypoo',
                    ],
                ],
                'last' => 'man standing',
            ];
        
        $dat->create($fields, $indexes);
        $dat->write($data);
        $data['status'] = 'DEAD';
        $dat->write($data);
        var_dump($dat);
        
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'r')
{
        $search = [
            'status' => 'DEAD',
            'subMember' => [
                'brothers' => [
                    'next' => 'nextypoo',
                ]
            ]
        ];
        $time_start = microtime(true);
        $return = $dat->search($search, true);
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        //var_dump($dat);
        var_dump($return);
        print "LOOKUP TOOK: " . $time . PHP_EOL;
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'w')
{
        $data = [
                'status' => 'ALIVE',
                'subMember' => [
                    'name' => 'JINX, buy me a coke.',
                    'pid' => 49024,
                    'brothers' => [
                        'next' => 'nextypoo',
                    ],
                ],
                'last' => 'man standing',
            ];
        $time_start = microtime(true);
        for ($c = 1000, $i = 0; $i < $c; $i++)
        {
                $data['subMember']['brothers']['next'] = 'nextypoo' . $i;
                $rowNum = $dat->write($data);
        }
        // 6k per second @ 100k records
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        print "WRITING TOOK: " . $time . PHP_EOL;
}
elseif (count($_SERVER['argv']) > 1 && $_SERVER['argv'][1] == 'l')
{
        $search = [
            'status' => $_SERVER['argv'][2]
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
            'status' => 'ALIVE',
            'subMember' => [
                'brothers' => [
                    'next' => 'nextypoo',
                ]
            ]
        ];

        $time_start = microtime(true);
        for ($c = 1000, $i = 0; $i < $c; $i++)
        {
                $search['subMember']['brothers']['next'] = 'nextypoo' . $i;
                //$search['name'] = $_SERVER['argv'][2];
                //$results[] = $dat->search($search, true);
                $dat->search($search, true);
                //$results[$rowNum] = $dat->read($rowNum);
        }
        // 3700 search and return per second @ 100k records
        // 7500 searches per second @ 100k records
        // 3850 search and return per second @ 10k records
        // 8100 searches per second @ 10k records
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        print "LOOKUP TOOK: " . $time . PHP_EOL;
}