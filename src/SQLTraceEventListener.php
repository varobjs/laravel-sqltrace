<?php /** @noinspection PhpUnusedParameterInspection */

namespace LaravelSQLTrace;

use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Redis;
use Throwable;

class SQLTraceEventListener
{
    /** @var resource $fp1 */
    protected $fp1;
    /** @var resource $fp2 */
    protected $fp2;
    /** @var resource $fp3 */
    protected $fp3;
    /** @var Redis $predis */
    protected $predis;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        ini_set('date.timezone', 'Asia/Shanghai');
        $this->fp1 = @fopen(env('SQL_TRACE_SQL_FILE', '/tmp/sql.log'), 'a+');
        $this->fp2 = @fopen(env('SQL_TRACE_TRACE_FILE', '/tmp/sql_trace.log'), 'a+');
        $this->fp3 = @fopen(env('SQL_TRACE_ERROR_FILE', '/tmp/sql_error.log'), 'a+');
        try {
            $this->predis = XRedis::getInstance()->predis;
        } catch (Throwable $e) {
            $this->error('[laravel-sql-trace-error-01] ' . $e->getMessage());
            $this->predis = null;
        }
    }

    public function __destruct()
    {
        fclose($this->fp1);
        fclose($this->fp2);
        fclose($this->fp3);
    }

    /**
     * Handle the event.
     *
     * 只要把当前类挂载到 QueryExecuted 事件上，
     * Laravel 的每次数据库执行操作都会执行 handle 函数
     * ```
     * app/Providers/EventServiceProvider.php
     *
     * ...
     * protected $listen = [
     *   QueryExecuted::class => [ \LaravelSQLTrace\SQLTraceEventListener::class, ]
     * ];
     * ...
     *
     * ```
     *
     * @param QueryExecuted $event
     * @return void
     */
    public function handle(QueryExecuted $event)
    {
        if (!$this->checkIsOk()) {
            $this->error('[laravel-sql-trace-error-02] check is not ok');
            return;
        }
        try {
            $curr_sql_trace_id = static::get_curr_sql_trace_id();
            $db_host = $event->connection->getConfig('host');
            $exec_time = $event->time;
            $sql = $event->sql;
            $bindings = implode(', ', $event->bindings);

            if (!$this->analyseAndContinue($db_host, $exec_time, $sql)) {
                return;
            }

            $this->saveSQLToFile(
                $db_host,
                $exec_time,
                $curr_sql_trace_id,
                $sql,
                $bindings
            );

            $last_trace = $this->saveTraceToFile(
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20),
                $curr_sql_trace_id
            );

            $this->pushLog($db_host, $exec_time, $curr_sql_trace_id, $sql, $bindings, $last_trace);
        } catch (Throwable $e) {
            $this->error('[laravel-sql-trace-error-03] ' . $e->getMessage());
        }
    }

    /**
     * 保存调用链接，返回sql的实际文件位置
     * @param array $traces
     * @param string $curr_sql_trace_id
     * @return string
     */
    protected function saveTraceToFile(array $traces, string $curr_sql_trace_id): string
    {
        $i = 10;
        $_trace = '';
        while (!empty($traces)) {
            $trace = array_pop($traces);
            if (isset($trace['file']) && strstr($trace['file'], 'vendor') === false) {
                $_trace = $trace['class'];
                $_trace .= $trace['type'];
                $_trace .= $trace['function'];
                isset($trace['file']) && $_trace .= ' at ' . $trace['file'];
                isset($trace['line']) && $_trace .= '@' . $trace['line'];
                // ==> /tmp/sql_trace.log <==
                // [839F2E59]  Illuminate\Routing\Controller->callAction at /example-app/routes/api.php@25
                //             └── Illuminate\Database\Eloquent\Builder->__call at /example-app/app/Http/Controllers/V1/TestController.php@18
                fwrite($this->fp2, sprintf(
                    "%s %s %s\n",
                    $i === 10 ? '[' . $curr_sql_trace_id . ']' : '',
                    $i === 10 ? '' : (str_repeat(' ', $i) . '└──'),
                    $_trace
                ));
                $i++;
            }
        }
        return $_trace;
    }

    /**
     * // ==> /tmp/sql.log
     * // [0D4B491C 839F2E59][2021-07-01/14:58:17,7793][127.0.0.1][1ms]
     * // **************************************************
     * // select count(*) as aggregate from `test` []
     * // **************************************************
     * //
     * @param string $db_host
     * @param float $exec_time
     * @param string $curr_sql_trace_id
     * @param string $sql
     * @param string $bindings
     */
    protected function saveSQLToFile(
        string $db_host,
        float $exec_time,
        string $curr_sql_trace_id,
        string $sql,
        string $bindings
    )
    {
        fwrite($this->fp1, sprintf(
            "\n[%s][%s][%s][%dms]\n%s\n%s [%s]\n%s\n",
            static::get_global_app_trace_id() . ' ' . $curr_sql_trace_id,
            static::get_datetime_ms(),
            $db_host,
            $exec_time,
            str_repeat('*', 50),
            $sql,
            $bindings,
            str_repeat('*', 50)
        ));
    }

    protected static function get_datetime_ms(): string
    {
        return date('Y-m-d/H:i:s.') . (int)(10000 * (microtime(true) - time()));
    }

    protected static $global_app_trace_id;

    protected static function get_global_app_trace_id(): string
    {
        if (!static::$global_app_trace_id) {
            static::$global_app_trace_id = $_SERVER['HTTP_TRACE_ID'] ??
                (
                    $_GET['trace_id'] ??
                    strtoupper(substr(md5(time() . getmypid() . rand(0, 9999)), 0, 8))
                );
        }
        return self::$global_app_trace_id;
    }

    protected static function get_curr_sql_trace_id(): string
    {
        return strtoupper(substr(md5(time() . getmypid() . rand(0, 9999)), 0, 8));
    }

    /**
     * 主动推送指标到第三方
     * @param string $db_host
     * @param float $exec_time
     * @param string $curr_sql_trace_id
     * @param string $sql
     * @param string $bindings
     * @param string $last_trace
     */
    protected function pushLog(
        string $db_host,
        float $exec_time,
        string $curr_sql_trace_id,
        string $sql,
        string $bindings,
        string $last_trace
    ): void
    {
    }

    /**
     * 在此处处理，redis 计数等统计操作
     *
     * @param string $db_host
     * @param float $exec_time
     * @param string $sql
     * @return bool 返回 false，当前执行完成，不再执行后续逻辑，比如降级处理的写入日志文件，推送第三方
     * @throws Exception
     */
    protected function analyseAndContinue(
        string $db_host,
        float $exec_time,
        string $sql
    ): bool
    {
        if (app()->environment() === 'local') {
            $is_continue = true;
        } else {
            $is_continue = $exec_time > 0.1 || random_int(1, 20000) > (20000 - 20);
        }

        if (env('SQL_TRACE_ANALYSE', 'false') === true && $this->predis) {
            $sql_key = md5($db_host . $sql);
            $hash_key = 'SQL_TRACE_HASH_KEY:' . date('Ymd');
            $hash_key_incr = 'SQL_TRACE_HASH_KEY_INCR:' . date('Ymd');
            $hash_key_time_incr = 'SQL_TRACE_HASH_KEY_TIME_INCR:' . date('Ymd');
            if (!$this->predis->hExists($hash_key, $sql_key) || $is_continue) {
                $this->predis->hSet($hash_key, $sql_key, sprintf(
                    "```db_host=%s```app_host=%s```pid=%s```sql=%s```",
                    $db_host,
                    $_SERVER['REMOTE_ADDR'] ?? '-',
                    getmypid(),
                    $sql
                ));
            }
            $this->predis->hIncrBy($hash_key_incr, $sql_key, 1);
            $this->predis->hIncrByFloat($hash_key_time_incr, $sql_key, $exec_time);
            if ($is_continue) {
                $this->predis->ttl($hash_key) === -1 and $this->predis->expire($hash_key, 2 * 86400);
                $this->predis->ttl($hash_key_incr) === -1 and $this->predis->expire($hash_key_incr, 2 * 86400);
                $this->predis->ttl($hash_key_time_incr) === -1 and $this->predis->expire($hash_key_time_incr, 2 * 86400);
            }
        }

        return $is_continue;
    }

    protected function error(string $error)
    {
        @fwrite($this->fp3, $error);
    }

    protected function checkIsOk(): bool
    {
        return $this->fp1 !== false || $this->fp2 !== false;
    }
}