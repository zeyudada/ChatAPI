<?php

declare(strict_types=1);

namespace app\command;

use app\model\Room as RoomModel;
use app\model\Song as SongModel;
use app\model\User as UserModel;
use think\console\Input;
use think\console\Output;

class Song extends BaseCommand
{
    private const DEFAULT_MUSIC_API_KEY = '4bc1a9ff1839405fabf7d592fcb085cc';
    private const DEFAULT_MUSIC_API_BASE_URL = 'https://myhkw.cn/open/music';
    private const DEFAULT_MUSIC_SOURCE = 'kw';
    private const DEFAULT_SONG_LENGTH = 300;

    protected function configure()
    {
        // 指令配置
        $this->setName('Test')
            ->setDescription('StartAdmin Test Command');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->loadConfig();
        $this->logInfo('点歌任务已启动', [
            'source' => $this->getMusicSource(),
            'api' => $this->getMusicApiBaseUrl(),
        ]);

        $idleLogged = false;
        while (true) {
            //暂停一下 避免对redis频繁读取
            usleep(500 * 1000);
            $rooms = $this->getRoomList();
            if (!$rooms) {
                if (!$idleLogged) {
                    $this->logInfo('点歌任务空闲', [
                        'reason' => '当前没有活跃点歌房间',
                    ]);
                    $idleLogged = true;
                }
                continue;
            }
            $idleLogged = false;

            foreach ($rooms as $room) {
                try {
                    $song = $this->getPlayingSong($room['room_id']);
                    if ($song && $song['song']) {
                        //歌曲正在播放
                        if (time() < $song['song']['length'] + $song['since']) {
                            //预先缓存下一首歌
                            $this->preLoadMusicUrl($room);
                            continue;
                        }
                        // 歌曲已超时
                        if ($room['room_type'] == 4 && $room['room_playone']) {
                            //是单曲循环的电台房间 重置播放时间
                            $song['since'] = time();
                            $this->logInfo('房间歌曲单曲循环重播', $this->buildRoomSongContext($room, $song, [
                                'mode' => '单曲循环',
                            ]));
                            $this->playSong($room['room_id'], $song, true); //给true 保留当前房间歌曲
                            continue;
                        }
                    }
                    //其他房间
                    $song = $this->getSongFromList($room['room_id']);
                    if ($song) {
                        $this->logInfo('房间从队列取出歌曲', $this->buildRoomSongContext($room, $song, [
                            'queue_size' => count($this->getSongList($room['room_id'])),
                        ]));
                        $this->playSong($room['room_id'], $song);
                    } else {
                        if ($room['room_type'] == 4) {
                            //电台模式
                            $song = $this->getSongByUser($room['room_user']);
                            if ($song) {
                                $this->logInfo('房间从房主歌单取歌', $this->buildRoomSongContext($room, $song, [
                                    'owner_user_id' => $room['room_user'],
                                ]));
                                $this->playSong($room['room_id'], $song);
                            } else {
                                $this->logWarning('房主歌单为空', [
                                    'room_id' => $room['room_id'],
                                    'owner_user_id' => $room['room_user'],
                                ]);
                            }
                        } else {
                            if ($room['room_robot'] == 0) {
                                $song = $this->getSongByRobot();
                                if ($song) {
                                    $this->logInfo('房间使用机器人补歌', $this->buildRoomSongContext($room, $song));
                                    $this->playSong($room['room_id'], $song);
                                } else {
                                    $this->logWarning('机器人补歌失败', [
                                        'room_id' => $room['room_id'],
                                    ]);
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logError('房间点歌任务执行失败', [
                        'room_id' => $room['room_id'] ?? 0,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                    ]);
                    cache('SongNow_' . $room['room_id'], null);
                    continue;
                }
            }
        }
    }
    protected function addSongToList($room_id, $song)
    {
        $songList = cache('SongList_' . $room_id) ?? [];
        $isExist = false;
        for ($i = 0; $i < count($songList); $i++) {
            if ($songList[$i]['song']['mid'] == $song['song']['mid']) {
                $isExist = true;
            }
        }
        if (!$isExist) {
            array_push($songList, $song);
            cache('SongList_' . $room_id, $songList, 86400);
            $this->logInfo('歌曲已补入房间队列', $this->buildRoomSongContext([
                'room_id' => $room_id,
            ], $song, [
                'queue_size' => count($songList),
            ]));
        }
    }
    protected function preLoadMusicUrl($room)
    {
        $preRoomId = $room['room_id'];
        $songList = $this->getSongList($preRoomId);
        $song = false;
        if (count($songList) > 0) {
            $song = $songList[0];
        } else {
            if ($room['room_type'] == 4) {
                $song = $this->getSongByUser($room['room_user']);
            } else {
                if ($room['room_robot'] == 0) {
                    $song = $this->getSongByRobot();
                }
            }
            if ($song) {
                $this->addSongToList($preRoomId, $song);
            }
        }
        if (!$song) {
            $this->logInfo('歌曲预加载已跳过', [
                'room_id' => $preRoomId,
                'reason' => '没有可预加载的歌曲',
            ]);
            return;
        }
        $preMid = $song['song']['mid'];
        if ($this->isExternalSong($preMid)) {
            $preSong = cache('song_play_temp_url_' . $preMid) ?? false;
            $preCount = cache('song_pre_load_count') ?? 0;
            if (!$preSong && $preCount < 5) {
                $this->logInfo('开始预加载歌曲', $this->buildRoomSongContext($room, $song, [
                    'preload_count' => $preCount + 1,
                ]));
                cache('song_pre_load_count', $preCount + 1, 60);
                $url = $this->getMusicPlayUrlByMid($preMid);
                if ($url) {
                    $tempList = cache('song_waiting_download_list') ?? [];
                    array_push($tempList, [
                        'mid' => $preMid,
                        'url' => $url
                    ]);
                    cache('song_waiting_download_list', $tempList);
                    cache('song_play_temp_url_' . $preMid, $url, 3600);
                    $this->logSuccess('歌曲预加载任务已入队', $this->buildRoomSongContext($room, $song, [
                        'queue_key' => 'song_waiting_download_list',
                        'download_queue_size' => count($tempList),
                    ]));
                } else {
                    $this->logWarning('歌曲预加载失败', $this->buildRoomSongContext($room, $song, [
                        'reason' => '播放地址为空',
                    ]));
                }
            } elseif ($preSong) {
                $this->logInfo('歌曲预加载已跳过', $this->buildRoomSongContext($room, $song, [
                    'reason' => '播放地址已缓存',
                ]));
            } else {
                $this->logWarning('歌曲预加载已跳过', $this->buildRoomSongContext($room, $song, [
                    'reason' => '达到预加载次数上限',
                    'preload_count' => $preCount,
                ]));
            }
        } else {
            //用户自己上传的歌曲 刷新一遍CDN
            $isCdnLoaded = cache('cdn_load_mid_' . $preMid) ?? false;
            if (!$isCdnLoaded) {
                $loadUrl = config('startadmin.api_url') . "/api/song/playurl?mid=" . $preMid;
                $this->logInfo('开始刷新上传歌曲 CDN', $this->buildRoomSongContext($room, $song, [
                    'url' => $loadUrl,
                ]));
                $ch = curl_init();
                $curlOpt = [
                    CURLOPT_URL => $loadUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 1,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ];
                curl_setopt_array($ch, $curlOpt);
                curl_exec($ch);
                curl_close($ch);
                cache('cdn_load_mid_' . $preMid, 1, 60);
                $this->logSuccess('上传歌曲 CDN 刷新完成', $this->buildRoomSongContext($room, $song));
            } else {
                $this->logInfo('上传歌曲 CDN 刷新已跳过', $this->buildRoomSongContext($room, $song, [
                    'reason' => '最近已刷新过',
                ]));
            }
        }
        $isPreloadSend = cache('pre_load_mid_' . $preMid) ?? false;
        if (!$isPreloadSend) {
            $msg = [
                "url" => config('startadmin.api_url') . "/api/song/playurl?mid=" . $preMid,
                "type" => "preload",
                "time" => date('H:i:s'),
            ];
            sendWebsocketMessage('channel', $preRoomId, $msg);
            cache('pre_load_mid_' . $preMid, 1, 60);
            $this->logInfo('已通知前端预加载歌曲', $this->buildRoomSongContext($room, $song, [
                'notify_url' => $msg['url'],
            ]));
        }
    }
    protected function getSongByUser($user_id)
    {
        $userModel = new UserModel();
        $songModel = new SongModel();
        $playerWaitSong = $songModel->where('song_user', $user_id)->orderRand()->find();
        if (!$playerWaitSong) {
            return false;
        }
        $playerWaitSong = [
            'mid' => $playerWaitSong['song_mid'],
            'name' => $playerWaitSong['song_name'],
            'pic' => $playerWaitSong['song_pic'] ?? '',
            'length' => $playerWaitSong['song_length'],
            'singer' => $playerWaitSong['song_singer'],
        ];
        $user = $userModel->where('user_id', $user_id)->find();
        if (!$user) {
            return false;
        }
        $song = [
            'user' => getUserData($user),
            'song' => $playerWaitSong,
            'since' => time(),
        ];
        return $song;
    }
    protected function playSong($room_id, $song, $last = false)
    {
        if ($last) {
            cache('SongNow_' . $room_id, $song);
        } else {
            cache('SongNow_' . $room_id, $song, 3600);
        }
        $songList = $this -> getSongList($room_id);

        cache("song_detail_" . $song['song']['mid'], $song['song'], 3600);
        $msg = [
            'at' => $song['at'] ?? false,
            'user' => $song['user'],
            'song' => $song['song'],
            'since' => $song['since'],
            "type" => "playSong",
            "time" => date('H:i:s'),
            'count' => count($songList) ?? 0
        ];
        sendWebsocketMessage('channel', $room_id, $msg);
        $this->logSuccess('房间开始播放歌曲', $this->buildRoomSongContext([
            'room_id' => $room_id,
        ], $song, [
            'keep_current' => $last,
            'queue_size' => count($songList) ?? 0,
        ]));
    }
    protected function getPlayingSong($room_id)
    {
        return  cache('SongNow_' . $room_id) ?? false;
    }
    protected function getSongFromList($room_id)
    {
        $songList = cache('SongList_' . $room_id) ?? [];
        if (count($songList) > 0) {
            $songNow = $songList[0];
            $songNow['since'] = time() + 5;
            array_shift($songList);
            cache('SongList_' . $room_id, $songList, 86400);
            $this->logInfo('歌曲已从队列取出等待播放', $this->buildRoomSongContext([
                'room_id' => $room_id,
            ], $songNow, [
                'remaining_queue_size' => count($songList),
            ]));
            return $songNow;
        } else {
            return false;
        }
    }
    protected function getSongList($room_id)
    {
        $songList = cache('SongList_' . $room_id) ?? [];
        return $songList;
    }
    protected function getRoomList()
    {
        $roomModel = new RoomModel();
        $rooms = cache('RoomList') ?? false;
        if (!$rooms) {
            $rooms = $roomModel->field('room_id,room_robot,room_type,room_playone,room_user')->where('room_type in (1,4) and room_realonline > 0 or room_id = 888')->select();
            $rooms = $rooms ? $rooms->toArray() : [];
            if ($rooms) {
                cache('RoomList', $rooms, 5);
            }
        }
        return $rooms;
    }
    protected function getSongByRobot()
    {
        $keywordArray = ['周杰伦', '林俊杰', '张学友', '林志炫', '梁静茹', '周华健', '华晨宇', '张宇', '张杰', '李宇春', '六哲', '阿杜', '伍佰', '五月天', '毛不易', '梁咏琪', '艾薇儿', '陈奕迅', '李志', '胡夏'];
        $keyword = $keywordArray[rand(0, count($keywordArray) - 1)];
        $this->logInfo('机器人开始搜索歌曲', [
            'keyword' => $keyword,
            'source' => $this->getMusicSource(),
        ]);
        $list = $this->searchMusicByKeyword($keyword, 1, 20);
        if (!$list) {
            $this->logWarning('机器人搜索结果为空', [
                'keyword' => $keyword,
            ]);
            return false;
        }
        $song = $list[rand(0, count($list) - 1)];
        $songDetail = $this->getMusicInfoByMid($song['mid'], false, true, true);
        if (!$songDetail) {
            $songDetail = $song;
            $this->logWarning('机器人歌曲详情获取失败，使用搜索结果兜底', [
                'mid' => $song['mid'],
                'keyword' => $keyword,
            ]);
        }
        if (empty($songDetail['mid'])) {
            $this->logError('机器人歌曲结果无效', [
                'keyword' => $keyword,
            ]);
            return false;
        }
        cache('song_detail_' . $songDetail['mid'], $songDetail, 3600);
        $this->logSuccess('机器人已选中歌曲', [
            'keyword' => $keyword,
            'mid' => $songDetail['mid'],
            'name' => $songDetail['name'] ?? '',
            'singer' => $songDetail['singer'] ?? '',
        ]);

        $userModel = new UserModel();
        $robotInfo = $userModel->where("user_id", 1)->find();
        return [
            'song' => $songDetail,
            'since' => time(),
            'count' => 1,
            'user' => [
                "app_id" => 1,
                "app_name" => "BBBUG",
                "app_url" => "https://music.eggedu.cn",
                "user_admin" => $robotInfo['user_admin'],
                "user_head" => $robotInfo['user_head'],
                "user_id" => $robotInfo['user_id'],
                "user_name" => rawurldecode($robotInfo['user_name']),
                "user_remark" => rawurldecode($robotInfo['user_remark']),
            ],
        ];
    }

    private function getMusicApiBaseUrl()
    {
        return rtrim(config('startadmin.music_api_base_url') ?: self::DEFAULT_MUSIC_API_BASE_URL, '/');
    }

    private function getMusicApiKey()
    {
        return config('startadmin.music_api_key') ?: self::DEFAULT_MUSIC_API_KEY;
    }

    private function getMusicSource()
    {
        $source = strtolower((string) (config('startadmin.music_api_source') ?: self::DEFAULT_MUSIC_SOURCE));
        return in_array($source, ['wy', 'qq', 'kw', 'kg', 'mg', 'qi'], true) ? $source : self::DEFAULT_MUSIC_SOURCE;
    }

    private function requestMusicApi($endpoint, array $params = [])
    {
        $params['key'] = $this->getMusicApiKey();
        $url = $this->getMusicApiBaseUrl() . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
        $result = curlHelper($url, 'GET');
        if (empty($result['body'])) {
            return null;
        }

        return json_decode($result['body'], true);
    }

    private function searchMusicByKeyword($keyword, $page = 1, $limit = 20)
    {
        $arr = $this->requestMusicApi('search', [
            'name' => $keyword,
            'type' => $this->getMusicSource(),
            'page' => $page,
            'limit' => $limit,
            'format' => 1,
            'pic' => 1,
        ]);
        if (!$arr) {
            return [];
        }

        $data = isset($arr['data']) ? $arr['data'] : $arr;
        if (!is_array($data)) {
            return [];
        }

        $list = [];
        foreach ($data as $song) {
            $songId = isset($song['id']) ? (string) $song['id'] : '';
            if ($songId === '') {
                continue;
            }
            $mid = $this->buildMusicMid($songId, $this->getMusicSource());
            $list[] = [
                'mid' => $mid,
                'name' => $this->formatText($song['name'] ?? ''),
                'pic' => (string) ($song['pic'] ?? ''),
                'length' => intval($song['duration'] ?? 0) ?: self::DEFAULT_SONG_LENGTH,
                'singer' => $this->formatArtist($song['artist'] ?? ''),
                'album' => $this->formatText($song['album'] ?? ''),
            ];
        }

        return $list;
    }

    private function getMusicInfoByMid($mid, $withUrl = false, $withPic = false, $withLrc = false)
    {
        $parsed = $this->parseMusicMid($mid);
        if ($parsed['is_upload']) {
            return false;
        }

        $arr = $this->requestMusicApi('info', [
            'id' => $parsed['id'],
            'type' => $parsed['source'],
            'url' => $withUrl ? 1 : 0,
            'pic' => $withPic ? 1 : 0,
            'lrc' => $withLrc ? 1 : 0,
        ]);
        if (!$arr || intval($arr['code'] ?? 0) !== 1 || empty($arr['data']) || !is_array($arr['data'])) {
            return false;
        }

        $data = $arr['data'];
        $lrc = (string) ($data['lrc'] ?? '');

        return [
            'mid' => $parsed['mid'],
            'name' => $this->formatText($data['name'] ?? ''),
            'album' => $this->formatText($data['album'] ?? ''),
            'singer' => $this->formatArtist($data['artist'] ?? ''),
            'pic' => (string) ($data['pic'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'lrc' => $lrc,
            'length' => $this->guessSongLength($lrc),
        ];
    }

    private function getMusicPlayUrlByMid($mid)
    {
        $parsed = $this->parseMusicMid($mid);
        if ($parsed['is_upload']) {
            return false;
        }

        $arr = $this->requestMusicApi('url', [
            'id' => $parsed['id'],
            'type' => $parsed['source'],
        ]);
        if (!$arr || intval($arr['code'] ?? 0) !== 1 || empty($arr['data'])) {
            return false;
        }

        return (string) $arr['data'];
    }

    private function guessSongLength($lrc)
    {
        $max = 0;
        if (preg_match_all('/\[(\d{2}):(\d{2})(?:\.(\d{1,3}))?\]/', $lrc, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fraction = isset($match[3]) ? str_pad($match[3], 3, '0') : '000';
                $seconds = intval($match[1]) * 60 + intval($match[2]) + intval($fraction) / 1000;
                if ($seconds > $max) {
                    $max = $seconds;
                }
            }
        }

        return $max > 0 ? (int) ceil($max) : self::DEFAULT_SONG_LENGTH;
    }

    private function buildMusicMid($id, $source = null)
    {
        return ($source ?: $this->getMusicSource()) . ':' . (string) $id;
    }

    private function parseMusicMid($mid)
    {
        $mid = (string) $mid;
        if ($this->isUploadedSong($mid)) {
            return [
                'mid' => $mid,
                'id' => ltrim($mid, '-'),
                'source' => null,
                'is_upload' => true,
            ];
        }

        if (strpos($mid, ':') !== false) {
            [$source, $id] = explode(':', $mid, 2);
            return [
                'mid' => $mid,
                'id' => $id,
                'source' => $this->normalizeMusicSource($source),
                'is_upload' => false,
            ];
        }

        return [
            'mid' => $mid,
            'id' => $mid,
            'source' => $this->getMusicSource(),
            'is_upload' => false,
        ];
    }

    private function normalizeMusicSource($source)
    {
        $source = strtolower((string) $source);
        return in_array($source, ['wy', 'qq', 'kw', 'kg', 'mg', 'qi'], true) ? $source : $this->getMusicSource();
    }

    private function isUploadedSong($mid)
    {
        return is_numeric((string) $mid) && intval($mid) < 0;
    }

    private function isExternalSong($mid)
    {
        return !$this->isUploadedSong($mid);
    }

    private function formatArtist($artist)
    {
        if (is_array($artist)) {
            $artist = implode('/', $artist);
        }
        return str_replace('&apos;', "'", html_entity_decode((string) $artist));
    }

    private function formatText($text)
    {
        return str_replace('&apos;', "'", html_entity_decode((string) $text));
    }

    private function buildRoomSongContext(array $room, array $song = [], array $extra = [])
    {
        $songData = $song['song'] ?? [];
        $userData = $song['user'] ?? [];

        return array_merge([
            'room_id' => $room['room_id'] ?? 0,
            'room_type' => $room['room_type'] ?? '',
            'mid' => $songData['mid'] ?? '',
            'song_name' => $songData['name'] ?? '',
            'user_id' => $userData['user_id'] ?? '',
            'user_name' => $userData['user_name'] ?? '',
        ], $extra);
    }
}
