<?php

require_once 'noSQLite.php';

class noSQL
{
        
        static $instance;
        public $clientConnection;
        
        public static function singleton()
        {
                self::$instance || self::$instance = new noSQL;
                return self::$instance;
        }
        
        public function __construct()
        {
                $this->clientConnection = new clientConnection;
        }
        
        public static function search($table, $array)
        {
                return self::singleton()->clientConnection->call('search', $table, serialize($array), '1');
        }
        
        public static function write($table, $data)
        {
                return self::singleton()->clientConnection->call('write', $table, $data);
        }
        
        public static function create($table, $fields, $indexes)
        {
                return self::singleton()->clientConnection->call('create', $table, $fields, $indexes);
        }
        
}

class clientConnection
{

        const PORT = 4242;
        const ADDRESS = '127.0.0.1';
        const HEADER_LENGTH = 2;
        const ARGUMENT_HEADER_LENGTH = 4;
        const RESPONSE_LENGTH = 4;

        private $socket;
        private $method;
        private $arguments = array();
        private $header;

        public function __construct($address = self::ADDRESS, $port = self::PORT)
        {
                $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                socket_bind($this->socket, $address);
                socket_connect($this->socket, $address, $port);
        }

        public function write($socket, $string, $length = null)
        {
                if (empty($length))
                {
                        $length = strlen($string);
                }
                while (true)
                {
                        $sent = socket_write($socket, $string, $length);
                        if ($sent === false)
                        {
                                break;
                        }
                        if ($sent < $length)
                        {
                                $string = substr($string, $sent);
                                $length -= $sent;
                        }
                        else
                        {
                                break;
                        }
                }
        }

        public function call()
        {
                if (func_num_args() == 0)
                {
                        return 0;
                }
                
                $b_meta = '';
                foreach (func_get_args() as $arg)
                {
                        if (empty($this->method))
                        {
                                $this->method = $arg;
                                $b_meta = pack('L', strlen($this->method));
                        }
                        else
                        {
                                $this->arguments[] = $arg;
                                $b_meta .= pack('L', strlen($arg));
                        }
                }

                $this->header = pack('S', count($this->arguments));
                $this->write($this->socket, $this->header, self::HEADER_LENGTH);
                $this->write($this->socket, $b_meta, (self::ARGUMENT_HEADER_LENGTH * count($this->arguments)) + self::ARGUMENT_HEADER_LENGTH);
                $this->write($this->socket, $this->method, strlen($this->method));

                if (!empty($this->arguments))
                {
                        foreach ($this->arguments as $arg)
                        {
                                $this->write($this->socket, $arg, strlen($arg));
                        }
                }

                if (false === ($bytes = socket_recv($this->socket, $buf, 4, MSG_WAITALL)))
                {
                        print "Socket error: " . socket_strerror(socket_last_error($this->socket)) . PHP_EOL;
                }
                
                if (false === ($bytes = socket_recv($this->socket, $b_length, 4, MSG_WAITALL)))
                {
                        print "Socket error: " . socket_strerror(socket_last_error($this->socket)) . PHP_EOL;
                }
                
                $unpack = unpack('Llength', $b_length);
                
                if (false === ($bytes = socket_recv($this->socket, $response, $unpack['length'], MSG_WAITALL)))
                {
                        print "Socket error: " . socket_strerror(socket_last_error($this->socket)) . PHP_EOL;
                }
                
                socket_shutdown($this->socket);
                socket_close($this->socket);
                return unserialize($response);
        }

}

$search = [
    'status' => 'DEAD',
    'subMember' => [
        'brothers' => [
            'next' => 'nextypoo',
        ]
    ]
];

var_dump(noSQL::search('mregister', $search));