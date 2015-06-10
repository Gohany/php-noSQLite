<?php

require_once 'stdin.php';
require_once 'noSQLite.php';

$stdin = new stdin();
$stdin->service = 'noSQLi';
$daemon = new daemon($stdin);
$daemon->setService($stdin->service);
$daemon->run();


class listener
{

        public $socket;
        public $method;
        public $arguments = array();
        public $meta = array();
        public $header;

        CONST HEADER_LENGTH = 2;
        CONST ARGUMENT_HEADER_LENGTH = 4;
        CONST RESPONSE_LENGTH = 4;
        CONST SIGNAL_ACK = 'ACK';
        CONST SIGNAL_ERR = 'ERROR';

        public function __construct($socket)
        {
                $this->socket = $socket;
        }

        public function metaUnpackString($arguments)
        {
                $unpackString = 'Lmethod/';
                for ($i = 0, $c = $arguments; $i < $c; $i++)
                {
                        $unpackString .= 'Largument' . ($i + 1) . '/';
                }
                $unpackString = rtrim($unpackString, '/');
                return $unpackString;
        }

        public function read($socket, $length)
        {
                $return = '';
                while (true)
                {
                        $input = socket_read($socket, $length, PHP_BINARY_READ);
                        if ($input === false)
                        {
                                return $return;
                        }

                        $length -= strlen($input);
                        if ($length <= 0)
                        {
                                return $input;
                        }
                }
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
                        // Check if the entire message has been sented
                        if ($sent < $length)
                        {
                                // If not sent the entire message.
                                // Get the part of the message that has not yet been sented as message
                                $string = substr($string, $sent);
                                // Get the length of the not sented part
                                $length -= $sent;
                        }
                        else
                        {
                                break;
                        }
                }
        }

        public function listen($object)
        {
                while (true)
                {
                        $client = socket_accept($this->socket);
                        $argument = 1;
                        $length = self::HEADER_LENGTH;
                        while (true)
                        {
                                $input = $this->read($client, $length, PHP_BINARY_READ);
                                if (empty($this->header))
                                {
                                        $this->header = unpack('Sarguments', $input);
                                        $length = self::ARGUMENT_HEADER_LENGTH + (self::ARGUMENT_HEADER_LENGTH * $this->header['arguments']);
                                }
                                elseif (empty($this->meta))
                                {
                                        $unpackString = $this->metaUnpackString($this->header['arguments']);
                                        $this->meta = unpack($unpackString, $input);
                                        $length = $this->meta['method'];
                                }
                                elseif (empty($this->method))
                                {
                                        $this->method = $input;
                                        $length = $this->meta['argument1'];
                                }
                                else
                                {
                                        
                                        if (count($this->arguments) == $this->header['arguments'])
                                        {
                                                break;
                                        }
                                        
                                        $length = $this->meta['argument' . ++$argument];
                                        $this->arguments[] = $input;
                                        
                                }
                        }
                        
                        if (!method_exists($object, $this->method))
                        {
                                $output = self::SIGNAL_ERR . PHP_EOL;
                                socket_write($client, $output);
                                socket_close($client);
                                return false;
                        }

                        $output = self::SIGNAL_ACK . PHP_EOL;
                        socket_write($client, $output);
                        $response = serialize(call_user_func_array(array($object, $this->method), $this->arguments));
                        socket_write($client, pack('L', strlen($response)), self::RESPONSE_LENGTH);
                        socket_write($client, $response, strlen($response)); 
                }
                socket_shutdown($client);
                socket_close($client);
                return true;
        }

        public function __destruct()
        {
                if (is_resource($this->socket))
                {
                        socket_shutdown($this->socket);
                        socket_close($this->socket);
                }
        }

}

class daemon
{

        const PORT = 4242;

        public $stdin;
        public $service;
        public $instance;
        public $socket;

        public function __construct(stdin $stdin, $port = self::PORT)
        {
                $this->stdin = $stdin;
                $this->socket = socket_create_listen($port);
        }

        public function __destruct()
        {
                if (is_resource($this->socket))
                {
                        socket_shutdown($this->socket);
                        socket_close($this->socket);
                }
        }

        public function run()
        {
                if (!empty($this->service))
                //if (!empty($this->stdin->service) && !empty($this->service) && in_array($this->stdin->service, $this->service))
                {
                        $this->instance || $this->instance = new $this->service;
                        $listener = new listener($this->socket);
                        $listener->listen($this->instance);

                        return true;
                }
                return false;
        }

        public function setService($class)
        {
                if (class_exists($class))
                {
                        $this->service = $class;
                }
        }
}
