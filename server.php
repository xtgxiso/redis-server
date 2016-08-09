<?php
/**
 * 多进程阻塞式
 */
class Xtgxiso_server
{
    private $socket = false;
    private $process_num = 100;
    public $redis_kv_data = array();
    public $onMessage = null;

    function __construct($host="0.0.0.0",$port=1215)
    {
        $this->socket = stream_socket_server("tcp://".$host.":".$port,$errno, $errstr);
        if (!$this->socket) die($errstr."--".$errno);
        echo "listen $host $port \r\n";
        ini_set("memory_limit", "128M");
    }

    private function parseRESP(&$conn){
        $line = fgets($conn);
        if($line === '' || $line === false)
        {
            return null;
        }
        $type = $line[0];
        $line = mb_substr($line,1,-2);
        switch ( $type ){
            case "*":
                $count = (int) $line;
                $data = array();
                for ($i = 1; $i <= $count; $i++) {
                    $data[] = $this->parseRESP($conn);
                }
                return $data;
            case "$":
                if ($line == '-1') {
                    return null;
                }
                $length = $line + 2;
                $data = '';
                while ($length > 0) {
                    $block = fread($conn, $length);
                    if ($length !== strlen($block)) {
                        throw new Exception('RECEIVING');
                    }
                    $data .= $block;
                    $length -= mb_strlen($block);
                }
                return mb_substr($data, 0, -2);
        }
        return $line;
    }

    private function start_worker_process(){
        $pid = pcntl_fork();
        switch ($pid) {
            case -1:
                echo "fork error : {$i} \r\n";
                exit;
            case 0:
                while ( 1 ) {
                    echo  "waiting...\n";
                    $conn = stream_socket_accept($this->socket, -1);
                    if ( !$conn ){
                        continue;
                    }
                    //"*3\r\n$3\r\nSET\r\n$5\r\nmykey\r\n$7\r\nmyvalue\r\n"
                    while(1){
                        $arr = $this->parseRESP($conn);
                        if ( is_array($arr) ) {
                            if ($this->onMessage) {
                                call_user_func($this->onMessage, $conn, $arr);
                            }
                        }else if ( $arr ){
                            if ($this->onMessage) {
                                call_user_func($this->onMessage, $conn, $arr);
                            }
                        }else{
                            fclose($conn);
                            break;
                        }
                    }
                }
            default:
                $this->pids[$pid] = $pid;
                break;
        }
    }

    public function run(){
        for($i = 1; $i <= $this->process_num; $i++){
            $this->start_worker_process();
        }

        while(1){
            foreach ($this->pids as $i => $pid) {
                if($pid) {
                    $res = pcntl_waitpid($pid, $status,WNOHANG);

                    if ( $res == -1 || $res > 0 ){
                        $this->start_worker_process();
                        unset($this->pids[$pid]);
                    }
                }
            }
            sleep(1);
        }
    }

}
$server =  new Xtgxiso_server();


$server->onMessage = function($conn,$info) use($server){
    if ( is_array($info) ){
        if ( $info["0"] == "SET" ) {
            $key = $info[1];
            $val = $info[2];
            $server->redis_kv_data[$key] = $val;
            fwrite($conn, "+OK\r\n");
        }else if ( $info["0"] == "GET" ){
            $key = $info[1];
            fwrite($conn, "$".strlen($server->redis_kv_data[$key])."\r\n".$server->redis_kv_data[$key]."\r\n");
        }else{
            fwrite($conn,"+OK\r\n");
        }
    }else{
        fwrite($conn,"+OK\r\n");
    }
};

$server->run();
