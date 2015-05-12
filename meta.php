<?php

class meta extends format
{
        
        public $rowCount;
        public $rowCountPosition;
        public $rowLength = 0;
        public $lengthTilData;
        public $fields;
        public $indexes;
        public $dynamic = false;
        public $static = false;
        
        
        public function __construct($bin)
        {
                if (strlen($bin) > 0)
                {
                        $this->unpackHeader($bin);
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
         *              2 BYTES SUB INDEX COUNT (0 for key => bytes, 1+ for key => array)
         *              2 BYTES name LENGTH = N
         *              N BYTES name
         *              2 BYTES index length (00 00 for array)
         *                      SUBFIELD:
         *                      2 BYTES SUB INDEX COUNT
         *                      2 BYTES name LENGTH = N
         *                      N BYTES name
         *                      2 BYTES index length (00 00 for array)
         * 4 BYTES ROW COUNT
         */
        public function packHeader($fields, $indexes)
        {
                
                $b_fields = $this->packArraySchema($fields);
                $b_indexes = $this->packArraySchema($indexes);
                //noSQLite::printHexDump($b_fields);
                //noSQLite::printHexDump($b_indexes);
                // pack header
                $fieldLength = strlen($b_fields);
                $indexLength = strlen($b_indexes);
                $fieldCount = count($fields);
                $indexCount = count($indexes);
                
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
        
        public function unpackHeader($bin)
        {
                
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
                
                $this->indexes = $this->unpackArraySchema($b_indexes, $header['INDEX_COUNT']);
                $this->fields = $this->unpackArraySchema($b_fields, $header['FIELD_COUNT']);
                
                
                $this->rowCountPosition = $lengthTilFields + $header['FIELD_LENGTH'] + $header['INDEX_LENGTH'];
                $b_rowCount = substr($bin, $this->rowCountPosition, self::sumSizes(self::T_ROW_COUNT));
                
                $this->rowCount = unpack(self::unpackString('ROW_COUNT'), $b_rowCount)['ROW_COUNT'];
                $this->lengthTilData = $this->rowCountPosition + self::sumSizes(self::T_ROW_COUNT);
                
                if (self::isDynamic($this->fields))
                {
                        $this->dynamic = true;
                }
                else
                {
                        $this->static = true;
                }
                
                return true;
        }
        
        public function rowLength()
        {
                if ($this->rowLength > 0)
                {
                        return $this->rowLength;
                }
                $this->rowLength = self::calculateArrayLength($this->fields);
                return $this->rowLength;
        }
        
        public function packArraySchema($array, $fieldString = '')
        {

                foreach ($array as $name => $data)
                {
                        $string = self::T_ARRAY_COUNT . self::T_ARRAY_NAME_LENGTH . self::T_NULL_STRING . strlen($name) . self::T_ARRAY_VALUE_LENGTH;
                        if (is_array($data))
                        {
                                // 2 BYTES ARRAY COUNT , 2 BYTES name LENGTH = N, N BYTES name, 2 BYTES 00 00
                                $fieldString .= pack($string, count($data), strlen($name), $name, 0);
                                $fieldString = $this->packArraySchema($data, $fieldString);
                        }
                        elseif (is_numeric($data))
                        {
                                $fieldString .= pack($string, 0, strlen($name), $name, $data);
                        }
                }
                
                return $fieldString;
        }
        
        public function unpackArraySchema(&$bin, $fieldCount)
        {
                for ($i=0;$fieldCount>$i;$i++)
                {
                        $soFar = 0;
                        $b_soFar = substr($bin, $soFar, self::sumSizes(self::T_ARRAY_COUNT, self::T_ARRAY_NAME_LENGTH));
                        $meta = unpack(
                                self::unpackString(
                                        'ARRAY_COUNT',
                                        'ARRAY_NAME_LENGTH'
                                ),
                                $b_soFar
                        );
                        $soFar += self::sumSizes(self::T_ARRAY_COUNT, self::T_ARRAY_NAME_LENGTH);
                        
                        $b_data = substr($bin, $soFar, 2 + $meta['ARRAY_NAME_LENGTH']);
                        $data = unpack(self::T_NULL_STRING . $meta['ARRAY_NAME_LENGTH'] . 'NAME/' . self::T_ARRAY_VALUE_LENGTH . 'BYTES', $b_data);
                        $soFar += self::sumSizes(self::T_ARRAY_VALUE_LENGTH, $meta['ARRAY_NAME_LENGTH']);
                        
                        if ($meta['ARRAY_COUNT'] > 0)
                        {
                                $bin = substr($bin, $soFar);
                                $array[$data['NAME']] = $this->unpackArraySchema($bin, $meta['ARRAY_COUNT']);
                        }
                        else
                        {
                                $bin = substr($bin, $soFar);
                                $array[$data['NAME']] = $data['BYTES'];
                        }
                }
                return $array;
        }
        
        public static function isDynamic($array)
        {
                foreach ($array as $name => $element)
                {
                        if (strpos($name, '*') !== false)
                        {
                                return true;
                        }
                        else
                        {
                                if (is_array($element))
                                {
                                        if (self::isDynamic($element) === true)
                                        {
                                                return true;
                                        }
                                }
                        }
                }
                return false;
        }
        
}