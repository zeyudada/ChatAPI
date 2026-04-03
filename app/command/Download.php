<?php

declare (strict_types = 1);

namespace app\command;

use think\console\Input;
use think\console\Output;

class Download extends BaseCommand
{
    private const DOWNLOAD_QUEUE_KEYS = [
        'song_waiting_download_list',
        'song_waiting_download_list',
    ];

    protected function configure()
    {
        // 指令配置
        $this->setName('Download')
            ->setDescription('StartAdmin Test Command');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->loadConfig();
        $this->logInfo('下载任务已启动', [
            'queue_keys' => self::DOWNLOAD_QUEUE_KEYS,
        ]);

        // cache('song_downloaded_list',null);
        // cache('song_waiting_download_list',null);
        // return;
        $idleLogged = false;
        while (true) {
            [$queueKey, $cacheList] = $this->getDownloadQueueSnapshot();
            if (count($cacheList) > 0) {
                $idleLogged = false;
                $task = $cacheList[0];
                $mid = $task['mid'] ?? '';
                $url = $task['url'] ?? '';

                if ($url) {
                    $this->logInfo('开始处理下载任务', [
                        'queue_key' => $queueKey,
                        'mid' => $mid,
                        'url' => $url,
                        'queue_size' => count($cacheList),
                    ]);

                    try{
                        $fileName = $this->getDownloadFilePath($mid);
                        $content = @file_get_contents($url);
                        if ($content === false) {
                            throw new \RuntimeException('读取远程文件失败');
                        }
                        $bytes = file_put_contents($fileName, $content);
                        if ($bytes === false) {
                            throw new \RuntimeException('写入本地文件失败');
                        }

                        $downloaded_song_list = cache('song_downloaded_list') ?? [];
                        $isExist = false;
                        foreach ($downloaded_song_list as $_mid) {
                            if ($mid == $_mid) {
                                $isExist = true;
                                break;
                            }
                        }
                        if (!$isExist) {
                            array_push($downloaded_song_list, $mid);
                        }
                        cache('song_downloaded_list', $downloaded_song_list);
                        $this->logSuccess('下载任务处理完成', [
                            'queue_key' => $queueKey,
                            'mid' => $mid,
                            'file' => $fileName,
                            'bytes' => $bytes,
                            'downloaded_size' => count($downloaded_song_list),
                        ]);
                    }catch(\Exception $e){
                        $this->logError('下载任务处理失败', [
                            'queue_key' => $queueKey,
                            'mid' => $mid,
                            'url' => $url,
                            'message' => $e->getMessage(),
                            'line' => $e->getLine(),
                        ]);
                    }
                    array_shift($cacheList);
                    cache($queueKey, $cacheList); //删掉已下载的文件item
                    cache('song_download_mid_' . $mid, time()); //缓存当前时间
                } else {
                    $this->logWarning('下载任务已跳过', [
                        'queue_key' => $queueKey,
                        'mid' => $mid,
                        'reason' => '下载地址为空',
                    ]);
                    array_shift($cacheList);
                    cache($queueKey, $cacheList);
                }
            } elseif (!$idleLogged) {
                $this->logInfo('下载任务空闲', [
                    'queue_keys' => self::DOWNLOAD_QUEUE_KEYS,
                ]);
                $idleLogged = true;
            }
            $downloaded_song_list = cache('song_downloaded_list') ?? [];
            for ($i = 0; $i < count($downloaded_song_list); $i++) {
                $_mid = $downloaded_song_list[$i];
                $songCache = cache('song_download_mid_' . $_mid) ?? false;
                if ($songCache) {
                    if (time() - $songCache > 600) {
                        $fileName = $this->getDownloadFilePath($_mid);
                        if (file_exists($fileName)) {
                            unlink($fileName);
                            cache('song_download_mid_' . $_mid, null);
                            array_splice($downloaded_song_list, $i, 1);
                            cache('song_downloaded_list', $downloaded_song_list);
                            $this->logInfo('已清理过期下载文件', [
                                'mid' => $_mid,
                                'file' => $fileName,
                                'remaining_downloaded_size' => count($downloaded_song_list),
                            ]);
                        }
                    }
                }
            }
            sleep(1);
        }
    }

    private function getDownloadQueueSnapshot()
    {
        foreach (self::DOWNLOAD_QUEUE_KEYS as $queueKey) {
            $cacheList = cache($queueKey) ?? [];
            if (count($cacheList) > 0) {
                return [$queueKey, $cacheList];
            }
        }

        return [self::DOWNLOAD_QUEUE_KEYS[0], []];
    }

    private function getDownloadFilePath($mid)
    {
        return __dir__ . "/../../public/music/" . $mid . ".jpg";
    }
}
