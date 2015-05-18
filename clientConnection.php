<?php

require_once 'noSQLite.php';

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
                noSQLite::printHexDump($string);
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

                if (false !== ($bytes = socket_recv($this->socket, $buf, 4, MSG_WAITALL)))
                {
                        echo "Read $bytes bytes from socket_recv(). Closing socket..." . PHP_EOL;
                        print $buf . PHP_EOL;
                }
                else
                {
                        echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
                }
                
                if (false !== ($bytes = socket_recv($this->socket, $b_length, 4, MSG_WAITALL)))
                {
                        echo "Read $bytes bytes from socket_recv(). Closing socket..." . PHP_EOL;
                        print $b_length . PHP_EOL;
                }
                else
                {
                        echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
                }
                
                noSQLite::printHexDump($b_length);
                $unpack = unpack('Llength', $b_length);
                var_dump($unpack);
                
                if (false !== ($bytes = socket_recv($this->socket, $response, $unpack['length'], MSG_WAITALL)))
                {
                        echo "Read $bytes bytes from socket_recv(). Closing socket..." . PHP_EOL;
                        print $buf . PHP_EOL;
                }
                else
                {
                        echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
                }
                var_dump($response);
                noSQLite::printHexDump($response);
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

$sender = new clientConnection;
var_dump($sender->call('search', 'mregister', serialize($search), '1'));

//$request = str_pad('THIS IS A TEST, BOO', 48, 'X');
//$arguments = 3;
//
//$header = pack('S', $arguments);
//$argumentMeta = pack('LLLL', strlen('search'), strlen($request), strlen($request), strlen($request));
//
//noSQLite::printHexDump($header);
//
//socket_write($socket, $header, 2);
//socket_write($socket, $argumentMeta, 16);
//socket_write($socket, 'search', strlen('search'));
//socket_write($socket, $request, strlen($request));
//socket_write($socket, $request, strlen($request));
//socket_write($socket, $request, strlen($request));
//
//if (false !== ($bytes = socket_recv($socket, $buf, 2048, MSG_WAITALL)))
//{
//        echo "Read $bytes bytes from socket_recv(). Closing socket..." . PHP_EOL;
//        print $buf . PHP_EOL;
//}
//else
//{
//        echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
//}
//
//if (false !== ($bytes = socket_recv($socket, $buf, 2048, MSG_WAITALL)))
//{
//        echo "Read $bytes bytes from socket_recv(). Closing socket..." . PHP_EOL;
//        print $buf . PHP_EOL;
//}
//else
//{
//        echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
//}
//socket_close($socket);
