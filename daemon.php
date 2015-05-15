<?php

require_once 'stdin.php';
require_once 'noSQLite.php';

$stdin = new stdin();
$daemon = new daemon($stdin);
$daemon->receiveAndRespond();

class daemon
{

        const PORT = 4242;
        const HEADER_LENGTH = 4;
        //const PUSH_ADDRESS = 'tcp://*';

        public $stdin;
        public $service;
        public $instance;
        public $socket;

        public function __construct(stdin $stdin)
        {
                $this->stdin = $stdin;
                $this->socket = socket_create_listen(self::PORT);
        }

        public function receiveAndRespond()
        {
                while (true)
                {
                        $client = socket_accept($this->socket);
                        $request = '';
                        $length = self::HEADER_LENGTH;
                        while (true)
                        {
                                $input = socket_read($client, $length);
                                if ($length == self::HEADER_LENGTH)
                                {
                                        noSQLite::printHexDump($input);
                                        $header = unpack('Lsize', $input);
                                        $length = $header['size'];
                                }
                                else
                                {
                                        $request .= $input;
                                        break;
                                }
                        }
                        $output = 'thx boo' . "\n";
                        socket_write($client, $output);
                        print "Them: $request, Us: $output\n";
                        break;
                }
                socket_close($client);
                socket_close($this->socket);
                return $request;
        }

        public function run()
        {
                if (!empty($this->stdin->service) && !empty($this->service) && in_array($this->stdin->service, $this->service))
                {
                        $this->instance = new $this->service;
//                        while(true)
//                        {
//                                $this->listen();
//                        }
                }
        }

        public function listen()
        {
                
        }

        public function registerService($controllerClass, $arguments = array())
        {
                if (class_exists($controllerClass))
                {
                        $this->service[$controllerClass] = array();
                        if (!empty($arguments))
                        {
                                foreach ($arguments as $arg => $type)
                                {
                                        $this->service[$controllerClass]['arguments'][$arg] = $type;
                                }
                        }
                }
        }

        public function registerServiceMethod($service, $method, $arguments)
        {
                if (isset($this->service[$service]) && method_exists($service, $method))
                {
                        $this->service[$service]['methods'][$method]['arguments'] = $arguments;
                }
        }

}
