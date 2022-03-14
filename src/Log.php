<?php

namespace SQLTrace;

use Illuminate\Container\Container;

class Log
{
    protected static $instance;

    public static function getInstance(): Log
    {
        if (!static::$instance) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    public $log;

    public function __construct()
    {
        $logfile = Container::getInstance()['config']['SQLTrace']['log_file'];
        if ($logfile) {
            $path = pathinfo($logfile);
            $logfile = ($path['dirname'] ?? '') . DIRECTORY_SEPARATOR . ($path['filename'] ?? '');
            $logfile .= '.' . date('Ymd') . '.log';
            if (!$logfile) {
                file_put_contents($logfile, '', FILE_APPEND);
            }
            $this->log = @fopen($logfile, 'ab+');
        }
    }

    protected static $reqId;

    public static function getReqId(): string
    {
        global $request_id_seq;
        if (is_null($request_id_seq)) {
            $request_id_seq = 0;
        } else {
            $request_id_seq++;
        }
        if (empty(static::$reqId) && !empty($_SERVER['X_REQ_ID'])) {
            static::$reqId = $_SERVER['X_REQ_ID'] . '-' . $request_id_seq;
        }
        if (empty(static::$reqId)) {
            static::$reqId = Utils::uuid() . '-' . $request_id_seq;
        }
        return preg_replace('/(\d+)$/', $request_id_seq, static::$reqId);
    }

    protected static function getDefaultContext(array &$context, int $logOffset = 0): void
    {
        $context['__req_id'] = static::getReqId();
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2 + $logOffset);
        $context['__file'] = sprintf(
            '%s@%d',
            $trace[1 + $logOffset]['file'] ?? '',
            $trace[1 + $logOffset]['line'] ?? ''
        );
    }

    public function info(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        $context['msg'] = $msg;
        $context['__timestamp'] = date('Y-m-d H:i:s') . strstr(microtime(true), '.');
        fwrite($this->log, json_encode($context) . PHP_EOL);
    }

    public function __destruct()
    {
        fclose($this->log);
    }
}