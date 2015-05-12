<?php

class format
{
        
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
        const T_ARRAY_COUNT = 'S';
        const T_ARRAY_NAME_LENGTH = 'S';
        const T_NULL_STRING = 'a';
        const T_ARRAY_VALUE_LENGTH = 'S';
        const T_INDEX_INDEX_COUNT = 'L';
        const T_CRC_KEY = 'L';
        const T_INDEX_STRING_SIZE = 'S';
        const T_INDEX_STRING = 'a';
        const T_INDEX_ROW_NUM = 'L';
        const T_INDEX_KEY = 'L';
        const T_INDEX_ELEMENTS = 'S';
        const T_DATA_STRING = 'A';
        
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
        
        public static function calculateArrayLength($array)
        {
                $length = 0;
                foreach ($array as $name => $data)
                {
                        if (is_array($data))
                        {
                                $length += self::calculateArrayLength($data);
                        }
                        elseif (is_numeric($data))
                        {
                                $length += $data;
                        }
                }
                return $length;
        }
        
        
}