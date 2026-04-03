<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use app\model\Conf as ConfModel;

class BaseCommand extends Command
{
    public function loadConfig(){
        $confModel = new ConfModel();
        $configs = $confModel->select()->toArray();
        $c = [];
        foreach ($configs as $config) {
            $c[$config['conf_key']] = $config['conf_value'];
        }
        config($c, 'startadmin');
    }
    protected function console($text, $break = true)
    {
        print_r($text . ($break ? PHP_EOL : ''));
    }
    protected function success($text, $break = true)
    {
        print_r(chr(27) . "[42m" . "$text" . chr(27) . "[0m" . ($break ? PHP_EOL : ''));
    }
    protected function error($text, $break = true)
    {
        print_r(chr(27) . "[41m" . "$text" . chr(27) . "[0m" . ($break ? PHP_EOL : ''));
    }
    protected function warning($text, $break = true)
    {
        print_r(chr(27) . "[43m" . "$text" . chr(27) . "[0m" . ($break ? PHP_EOL : ''));
    }

    protected function logInfo($event, array $context = [])
    {
        $this->console($this->formatLogLine('INFO', $event, $context));
    }

    protected function logSuccess($event, array $context = [])
    {
        $this->success($this->formatLogLine('SUCCESS', $event, $context));
    }

    protected function logWarning($event, array $context = [])
    {
        $this->warning($this->formatLogLine('WARN', $event, $context));
    }

    protected function logError($event, array $context = [])
    {
        $this->error($this->formatLogLine('ERROR', $event, $context));
    }

    protected function formatLogLine($level, $event, array $context = [])
    {
        $parts = [
            '[' . date('Y-m-d H:i:s') . ']',
            '[' . class_basename(static::class) . ']',
            '[' . $this->translateLogLevel($level) . ']',
            $event,
        ];

        $contextParts = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            }
            $contextParts[] = $key . '=' . $value;
        }

        if ($contextParts) {
            $parts[] = '| ' . implode(' ', $contextParts);
        }

        return implode(' ', $parts);
    }

    protected function translateLogLevel($level)
    {
        switch ($level) {
            case 'SUCCESS':
                return '成功';
            case 'WARN':
                return '警告';
            case 'ERROR':
                return '错误';
            case 'INFO':
            default:
                return '信息';
        }
    }
}
