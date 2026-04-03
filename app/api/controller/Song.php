<?php

namespace app\api\controller;

use think\facade\View;
use app\api\BaseController;
use think\facade\Db;
use app\model\Room as RoomModel;
use app\model\Song as SongModel;
use app\model\User as UserModel;
use app\model\Attach as AttachModel;
use think\App;

class Song extends BaseController
{
    private const DEFAULT_MUSIC_API_KEY = '4bc1a9ff1839405fabf7d592fcb085cc';
    private const DEFAULT_MUSIC_API_BASE_URL = 'https://myhkw.cn/open/music';
    private const DEFAULT_MUSIC_SOURCE = 'kw';
    private const DEFAULT_SONG_LENGTH = 300;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new SongModel();
    }
    public function searchChrome()
    {
        if (!input("keyword")) {
            header("Location: https://music.eggedu.cn");
            die;
        }
        $page = 1;
        $keyword = input('keyword');
        $list = $this->searchMusicByKeyword($keyword, $page, 50);
        if (!$list) {
            echo '<center><h1>搜索失败,正在返回主站</h1><hr><br><br><img src="https://music.eggedu.cn/images/linus.jpg"
            height="300px" /></center><script>setTimeout(function(){location.replace("https://music.eggedu.cn");},3000);</script>';die;
        }

        View::assign('list', $list);
        return View::fetch();
    }
    public function search()
    {
        if (input('isHots')) {
            //获取本周热门歌曲
            $cache = cache('week_song_play_rank') ?? false;
            if ($cache) {
                return jok('from redis', $cache);
            }
            $result = Db::query("select count(song_week) as week,song_mid as mid,song_id as id,song_pic as pic,song_singer as singer,song_name as name from sa_song where song_week > 0 group by song_mid order by week desc limit 0,50");
            cache('week_song_play_rank', $result, 10);
            return jok('success', $result);
        }
        $page = 1;
        if(input('page')){
            $page = intval(input('page'));
        }
        $list = [];
        $keywordArray = ['周杰伦', '林俊杰', '张学友', '林志炫', '梁静茹', '周华健', '华晨宇', '张宇', '张杰', '李宇春', '六哲', '阿杜', '伍佰', '五月天', '毛不易', '梁咏琪', '艾薇儿', '陈奕迅', '李志', '胡夏'];
        // $keywordArray = [];
        $keyword = $keywordArray[rand(0, count($keywordArray) - 1)];
        if (input("keyword")) {
            $keyword = input('keyword');
        }

        $list = $this->searchMusicByKeyword($keyword, $page, 50);
        return jok('success', $list);
    }
    public function deleteMySong()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');
        $this->model->where('song_mid', $mid)->where('song_user', $this->user['user_id'])->delete();
        return jok('删除歌单的歌曲成功');
    }
    public function addMySong()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');
        $song = $this->model->where('song_mid', $mid)->where('song_user', $this->user['user_id'])->find();
        if ($song) {
            return jerr('你已经搜藏过这首歌');
        }

        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            return jerr('歌曲信息获取失败，搜藏失败');
        }
        $this->model->insert([
            'song_mid' => $song['mid'],
            'song_name' => $song['name'],
            'song_singer' => $song['singer'],
            'song_mid' => $song['mid'],
            'song_pic' => $song['pic'],
            'song_length' => $song['length'],
            'song_user' => $this->user['user_id'],
            'song_createtime' => time(),
            'song_updatetime' => time(),
        ]);

        $msg = [
            "content" => rawurldecode($this->user['user_name']) . ' 收藏了当前播放的歌曲',
            "type" => "system",
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);
        return jok('歌曲搜藏成功，快去你的已点列表看看吧');
    }
    public function addNewSong()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('song_length') || !input('song_name') || !input('song_singer') || !input('song_mid')) {
            return jerr("参数错误，缺少 song_length/song_name/song_singer/song_mid");
        }

        $song = [
            'song_mid' => 0 - intval(input('song_mid')),
            'song_name' => input('song_name'),
            'song_singer' => input('song_singer'),
            'song_pic' => input('song_pic'),
            'song_length' => intval(input('song_length')),
            'song_user' => $this->user['user_id'],
            'song_createtime' => time(),
            'song_updatetime' => time(),
        ];

        $this->model->insert($song);

        return jok('添加成功');
    }
    public function playSong()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }
        if ($room['room_type'] != 4) {
            return jerr("该房间下不允许播放");
        }

        $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;

        if (!getIsAdmin($this->user) && $this->user['user_id'] != $room['room_user'] && !$isVip) {
            return jerr("你没有权限播放");
        }

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲信息读取失败，无法播放");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }
        //将歌曲置顶
        $songList = cache('SongList_' . $room_id) ?? [];
        $isPushed = false;
        for ($i = 0; $i < count($songList); $i++) {
            $item = $songList[$i];
            if ($item['song']['mid'] == $mid) {
                array_splice($songList, $i, 1);
                array_unshift($songList, $item);
                $isPushed = true;
                break;
            }
        }


        $songModel = new SongModel();
        if ($this->isExternalSong($song['mid'])) {
            $song = $this->syncSongDetail($songModel, $song);
            if (!$song) {
                return jerr("歌曲信息获取失败");
            }
        }
        cache('song_detail_' . $song['mid'], $song, 3600);


        if (!$isPushed) {
            array_unshift($songList, [
                'user' => getUserData($this->user),
                'song' => $song,
                'at' => false,
            ]);
        }
        cache('SongList_' . $room_id, $songList, 86400);
        //切掉正在播放
        cache('SongNow_' . $room_id, null);

        $songExist = $songModel->where('song_mid', $song['mid'])->where('song_user', $this->user['user_id'])->find();
        if (!$songExist) {
            $songModel->insert([
                'song_mid' => $song['mid'],
                'song_name' => $song['name'],
                'song_singer' => $song['singer'],
                'song_mid' => $song['mid'],
                'song_pic' => $song['pic'],
                'song_length' => $song['length'],
                'song_user' => $this->user['user_id'],
                'song_createtime' => time(),
                'song_updatetime' => time(),
            ]);
        } else {
            $songModel->where('song_id', $songExist['song_id'])->update([
                'song_updatetime' => time(),
            ]);
        }
        return jok('播放成功');
    }
    public function addSong()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        if ($room['room_type'] != 1 && $room['room_type'] != 4) {
            return jerr("该房间下不允许点歌");
        }

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲数据获取失败,请重新搜索后点歌");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }
        $songModel = new SongModel();
        if ($this->isExternalSong($song['mid'])) {
            $song = $this->syncSongDetail($songModel, $song);
            if (!$song) {
                return jerr("歌曲信息获取失败");
            }
        }
        cache('song_detail_' . $song['mid'], $song, 3600);

        $at = input('at');
        if ($at) {
            $user = $this->userModel->where('user_id', $at)->find();
            if ($user) {
                $at = getUserData($user);
                if ($at['user_id'] == $this->user['user_id']) {
                    return jerr("“自己给自己送歌，属实不高端”——佚名");
                }
            } else {
                return jerr("被送歌人信息查询失败");
            }
        } else {
            $at = false;
        }
        $isBan = cache('songdown_room_' . $room_id . '_user_' . $this->user['user_id']);
        if ($isBan) {
            return jerr("你被房主禁止了点歌权限!");
        }
        $songList = cache('SongList_' . $room_id) ?? [];
        $existSong = null;
        $mySong = 0;
        foreach ($songList as $item) {
            if ($item['user']['user_id'] == $this->user['user_id']) {
                $mySong++;
            }
            if ($item['song']['mid'] == $song['mid']) {
                $existSong = $item['song']['name'];
            }
        }
        if ($existSong) {
            return jerr('歌曲《' . $existSong . '》正在等待播放呢!');
        }
        $addSongCDTime = $room['room_addsongcd'];

        $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;

        if (!getIsAdmin($this->user) && $this->user['user_id'] != $room['room_user'] && !$isVip) {
            if ($room['room_addsong'] == 1) {
                return jerr('点歌失败,当前房间仅房主可点歌');
            }
            $addSongLastTime = cache('song_' . $this->user['user_id']) ?? 0;
            $addSongNeedTime = $addSongCDTime - (time() - $addSongLastTime);
            if ($addSongNeedTime > 0) {
                return jerr('点歌太频繁，请' . $addSongNeedTime . 's后再试');
            }
            if ($mySong >= $room['room_addcount']) {
                return jerr('你还有' . $mySong . '首歌没有播，请稍候再点歌吧~');
            }
        }

        cache('song_' . $this->user['user_id'], time(), $addSongCDTime);

        if(count($songList) == 1 && $songList[0]["user"]["user_id"] == 1){
            //如果待播放只有机器人点的歌了 删除
            array_splice($songList, 0, 1);
        }

        array_push($songList, [
            'user' => getUserData($this->user),
            'song' => $song,
            'at' => $at ?? false,
        ]);
        cache('SongList_' . $room_id, $songList, 86400);

        $msg = [
            'user' => getUserData($this->user),
            'song' => $song,
            "type" => "addSong",
            'at' => $at ?? false,
            "time" => date('H:i:s'),
            'count' => count($songList) ?? 0
        ];
        sendWebsocketMessage('channel', $room_id, $msg);

        $songExist = $songModel->where('song_mid', $song['mid'])->where('song_user', $this->user['user_id'])->find();
        if (!$songExist) {
            $songModel->insert([
                'song_mid' => $song['mid'],
                'song_name' => $song['name'],
                'song_singer' => $song['singer'],
                'song_mid' => $song['mid'],
                'song_pic' => $song['pic'],
                'song_play' => 1,
                'song_week' => 1,
                'song_length' => $song['length'],
                'song_user' => $this->user['user_id'],
            ]);
        } else {
            $songModel->where('song_id', $songExist['song_id'])->inc('song_play')->update();
            $songModel->where('song_id', $songExist['song_id'])->inc('song_week')->update();
            $songModel->where('song_id', $songExist['song_id'])->update([
                'song_updatetime' => time(),
            ]);
        }
        return jok('歌曲' . $song['name'] . '已经添加到播放列表！', $song);
    }
    public function mySongList()
    {
        $page = 1;
        if (input('page')) {
            $page = intval(input('page'));
        }
        $per_page = 20;
        if (input('per_page')) {
            $per_page = intval(input('per_page'));
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $songModel = new SongModel();
        $list = $songModel->field('song_mid as mid,song_length as length,song_name as name,song_singer as singer,song_play as played,song_pic as pic')->where('song_user', $this->user['user_id'])->order('song_updatetime desc,song_play desc,song_id desc')->limit($per_page)->page($page)->select();
        return jok('success', $list);
    }
    public function getUserSongs()
    {
        if (!input("user_id")) {
            return jerr("user_id 为必传参数");
        }
        $user_id = intval(input('user_id'));
        $songModel = new SongModel();
        $list = $songModel->field('song_mid as mid,song_length as length,song_name as name,song_singer as singer,song_play as played,song_pic as pic')->where('song_user', $user_id)->order('song_updatetime desc,song_play desc,song_id desc');
        if(input('isAll')){
            $list = $list->select();
        }else{
            $list = $list ->limit(50)->select();
        }
        return jok('success', $list);
    }
    public function now()
    {
        if (!input('room_id')) {
            return jerr("参数错误,缺少room_id");
        }
        $room_id = input('room_id');
        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }
        $result = [];
        $songList = cache('SongList_' . $room_id) ?? [];
        switch ($room['room_type']) {
            case 1:
            case 4:
                $now = cache('SongNow_' . $room_id) ?? [];
                $result = [
                    'type' => 'playSong',
                    'time' => date('H:i:s'),
                    'user' => null,
                    'song' => null,
                    'count' => count($songList) ?? 0
                ];
                if ($now) {
                    $result['user'] = $now['user'];
                    $result['at'] = $now['at'] ?? false;
                    $result['song'] = $now['song'];
                    $result['since'] = $now['since'];
                    $result['now'] = time();
                }
                break;
            default:
        }

        return json($result);
    }
    public function pass()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲数据获取失败,请重新搜索后点歌");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }

        $now = cache('SongNow_' . $room_id) ?? '';
        $SongList = cache('SongList_' . $room_id) ?? [];
        $time = cache('SongNextTime_' . $room_id) ?? 0;
        if (!$now) {
            return jerr('当前没有正在播放的歌曲');
        }

        cache('SongNextTime_' . $room_id, time());
        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && $now['user']['user_id'] != $this->user['user_id']) {
            //其他人
            $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;
            if (!$isVip) {
                if ($room['room_votepass'] == 0) {
                    return jok("该房间未开启投票切歌");
                }
                $onlineCount = $room['room_online'] - 2; //取消机器人的在线数
                $limitCount = ceil($onlineCount * $room['room_votepercent'] / 100);
                if ($limitCount > 10) {
                    $limitCount = 10;
                }
                if ($limitCount < 2) {
                    $limitCount = 2;
                }
                $songNextCount = cache('song_next_count_' . $room_id . '_mid_' . $now['song']['mid']) ?? 0;
                $isMeNexted = cache('song_next_user_' . $this->user['user_id']) ?? '';
                if ($isMeNexted == $now['song']['mid']) {
                    return jok('已有' . $songNextCount . '人不想听,在线' . $room['room_votepercent'] . '%(' . $limitCount . '人)不想听即可自动切歌');
                }
                cache('song_next_user_' . $this->user['user_id'], $now['song']['mid'], 3600);
                $songNextCount++;
                $msg = [
                    "content" => '有人表示不太喜欢当前播放的歌(' . $songNextCount . '/' . $limitCount . ')',
                    "type" => "system",
                    "time" => date('H:i:s'),
                ];
                sendWebsocketMessage('channel', $room_id, $msg);
                if ($songNextCount >= $limitCount) {
                    cache('SongNow_' . $room_id, null);
                    $msg = [
                        "content" => $room['room_votepercent'] . '%在线用户(' . $limitCount . '人)不想听这首歌，系统已自动切歌!',
                        "type" => "system",
                        "time" => date('H:i:s'),
                    ];
                    sendWebsocketMessage('channel', $room_id, $msg);
                }
                cache('song_next_count_' . $room_id . '_mid_' . $now['song']['mid'], $songNextCount, 3600);
                $list = cache('song_pass_list') ?? [];
                array_push($list,[
                    'user'=>$this->user['user_id'],
                    'name'=>urldecode($this->user['user_name']),
                    'ip'=> getClientIp()
                ]);
                cache('song_pass_list',$list,3600);
                return jok('你的不想听态度表态成功!');
            }
        }

        cache('SongNow_' . $room_id, null);
        $msg = [
            "user" => getUserData($this->user),
            "song" => $song,
            "type" => "pass",
            "time" => date('H:i:s'),
        ];
        sendWebsocketMessage('channel', $room_id, $msg);

        return jok('切歌成功');
    }
    public function songList()
    {
        if (!input('room_id')) {
            return jerr("参数错误,缺少room_id");
        }
        $room_id = input('room_id');
        $songList = cache('SongList_' . $room_id) ?? [];
        return jok('', $songList ?? []);
    }
    public function getLrc()
    {
        if (!input('mid')) {
            return jerr('参数错误,mid缺失');
        }
        $mid = input('mid');
        if ($this->isUploadedSong($mid)) {
            return jok('', [
                [
                    'lineLyric' => '歌曲为用户上传,暂无歌词',
                    'time' => 0
                ],
            ]);
        }
        $lrcList = $this->getMusicLrcByMid($mid);
        if ($lrcList) {
            array_unshift($lrcList, [
                'lineLyric' => '歌词加载成功',
                'time' => 0,
            ]);
            return jok('', $lrcList);
        } else {
            return jok('', [
                [
                    'lineLyric' => '很尴尬呀,没有查到歌词~',
                    'time' => 0
                ],
            ]);
        }
    }
    public function push()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少song_mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }
        $isVip = cache('guest_room_' . $room_id . '_user_' . $this->user['user_id']) ?? false;

        $mid = input('mid');
        $song = cache('song_detail_' . $mid) ?? false;
        if (!$song) {
            $temp = $this->model->where('song_mid', $mid)->find();
            if (!$temp) {
                return jerr("歌曲数据获取失败,请重新搜索后点歌");
            } else {
                $song = [
                    'mid' => $temp['song_mid'],
                    'name' => $temp['song_name'],
                    'pic' => $temp['song_pic'] ?? '',
                    'length' => $temp['song_length'],
                    'singer' => $temp['song_singer'],
                ];
            }
        }

        $songList = cache('SongList_' . $room_id) ?? [];
        $pushSong = false;
        for ($i = 0; $i < count($songList); $i++) {
            $item = $songList[$i];
            if ($item['song']['mid'] == $mid) {
                if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && !$isVip) {
                    if($item['user']['user_id'] == $this->user['user_id']){
                        return jerr("不要顶你自己点的歌啦~");
                    }
                }
                $pushSong = $item;
                $songList[$i]['push_count'] = $songList[$i]['push_count'] ?? 0;
                $songList[$i]['push_time'] = time();
                if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && !$isVip) {
                    $songList[$i]['push_count']++;
                }else{
                    $songList[$i]['push_count'] = 888;
                }
                break;
            }
        }
        if (!$pushSong) {
            return jerr("顶歌失败，歌曲ID不存在");
        }
        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && !$isVip) {
            $pushCount = $room['room_pushdaycount'];
            $pushCache = cache('push_' . date('Ymd') . '_' . $this->user['user_id']);
            $pushCache = $pushCache ? intval($pushCache) : 0;

            $push_last_time = cache('push_last_' . $this->user['user_id']) ?? 0;
            $pushTimeLimit = $room['room_pushsongcd'];
            if ($pushCache >= $pushCount) {
                if($pushCount > 0){
                    return jerr("你的" . $pushCount . "次顶歌机会已使用完啦");
                }else{
                    return jerr("当前房间房主设置不允许顶歌");
                }
            }
            if (time() - $push_last_time < $pushTimeLimit) {
                $timeStr = '';
                $minute = floor(($pushTimeLimit - (time() - $push_last_time)) / 60);
                if ($minute > 0) {
                    $timeStr .= $minute . "分";
                }
                $second = ($pushTimeLimit - (time() - $push_last_time)) % 60;
                if ($second > 0) {
                    $timeStr .= $second . "秒";
                }
                return jerr("顶歌太频繁啦，请" . $timeStr . "后再试！");
            }
            $pushCache++;
            cache('push_' . date('Ymd') . '_' . $this->user['user_id'], $pushCache, 86400);
        }
        usort($songList, array($this,'pushSongSort'));
        cache('SongList_' . $room_id, $songList, 86400);
        $msg = [
            'user' => getUserData($this->user),
            'song' => $pushSong['song'],
            "type" => "push",
            "time" => date('H:i:s'),
            'count' => count($songList) ?? 0
        ];
        sendWebsocketMessage('channel', $room_id, $msg);

        cache('push_last_' . $this->user['user_id'], time());
        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && !$isVip) {
            return jok('顶歌成功,今日剩余' . ($pushCount - $pushCache) . '次顶歌机会!');
        }
        return jok('顶歌成功');
    }

    private function pushSongSort($a, $b) {
        $a['push_count'] = $a['push_count'] ?? 0;
        $b['push_count'] = $b['push_count'] ?? 0;
        $a['push_time'] = $a['push_time'] ?? 0;
        $b['push_time'] = $b['push_time'] ?? 0;
        if($a['push_count'] < $b['push_count']){
            return true;
        }
        if($a['push_count'] == $b['push_count']){
            if($a['push_time'] > $b['push_time']){
                return true;
            }else{
                return false;
            }
        }
        return false;
    }

    public function remove()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        if (!input('mid') || !input('room_id')) {
            return jerr("参数错误,缺少mid/room_id");
        }
        $room_id = input('room_id');

        $roomModel = new RoomModel();
        $room = $roomModel->getRoomById($room_id);
        if (!$room) {
            return jerr("房间信息查询失败");
        }

        $mid = input('mid');

        $songList = cache('SongList_' . $room_id) ?? [];
        $removeSong = false;
        for ($i = 0; $i < count($songList); $i++) {
            $item = $songList[$i];
            if ($item['song']['mid'] == $mid) {
                $removeSong = $item;
                array_splice($songList, $i, 1);
                break;
            }
        }
        if (!$removeSong) {
            return jerr("移除失败，歌曲ID不存在");
        }

        if ($room['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user) && $this->user['user_id'] != $removeSong['user']['user_id'] && $this->user['user_id'] != $removeSong['at']['user_id']) {
            return jerr("你没有权限操作");
        }
        cache('SongList_' . $room_id, $songList, 86400);
        $msg = [
            'user' => getUserData($this->user),
            'song' => $removeSong['song'],
            "type" => "removeSong",
            "time" => date('H:i:s'),
            'count' => count($songList) ?? 0
        ];
        sendWebsocketMessage('channel', $room_id, $msg);
        return jok('移除成功');
    }
    public function getPlayUrl()
    {
        if (!input('mid')) {
            return jerr('参数错误mid');
            exit;
        }
        $mid = input('mid');
        $url = cache('song_play_temp_url_' . $mid) ?? false;
        if ($url) {
            return jok('', [
                'url' => $url,
            ]);
        }
        if ($this->isUploadedSong($mid)) {
            //自己上传的
            $attachModel = new AttachModel();
            $attach = $attachModel->where('attach_id', $this->getUploadAttachId($mid))->find();
            if (!$attach) {
                header("status: 404 Not Found");
                die;
            }
            $path = config('startadmin.static_url') . 'uploads/' . $attach['attach_path'];
            cache('song_play_temp_url_' . $mid, $path, 30);
            return jok('', [
                'url' => $path,
            ]);
            die;
        }
        
        $url = $this->getMusicPlayUrlByMid($mid);
        if (!$url) {
            return jerr('歌曲链接获取失败');
        } else {
            $tempList = cache('song_waiting_download_list') ?? [];
            array_push($tempList, [
                'mid' => $mid,
                'url' => $url
            ]);
            cache('song_waiting_download_list', $tempList);
            cache('song_play_temp_url_' . $mid, $url, 30);
            return jok('', [
                'url' => $url,
            ]);
        }
    }
    public function getSongList()
    {
        if (!input('room_id')) {
            return jerr('room_id为必传参数');
        }
        $room_id = input('room_id');
        $songList = cache('SongList_' . $room_id) ?? [];
        return jok('success', $songList);
    }
    public function playUrl()
    {
        if (!input('mid')) {
            header("status: 404 Not Found");
            exit;
        }
        $mid = input('mid');
        $url = cache('song_play_temp_url_' . $mid) ?? false;
        if ($url) {
            header("Cache: From Redis");
            header("Location: " . $url);
            die;
        }
        if ($this->isUploadedSong($mid)) {
            //自己上传的
            $attachModel = new AttachModel();
            $attach = $attachModel->where('attach_id', $this->getUploadAttachId($mid))->find();
            if (!$attach) {
                header("status: 404 Not Found");
                die;
            }
            $path = config('startadmin.static_url') . 'uploads/' . $attach['attach_path'];
            cache('song_play_temp_url_' . $mid, $path, 30);
            header("Location: " . $path);
            die;
        }

        $url = $this->getMusicPlayUrlByMid($mid);
        if (!$url) {
            //获取播放地址失败了
            die;
        } else {
            $tempList = cache('song_waiting_download_list') ?? [];
            array_push($tempList, [
                'mid' => $mid,
                'url' => $url
            ]);
            cache('song_waiting_download_list', $tempList);
            cache('song_play_temp_url_' . $mid, $url, 30);
            header("Location: " . $url);
        }
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

    private function searchMusicByKeyword($keyword, $page = 1, $limit = 50)
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

        $list = [];
        $source = $this->getMusicSource();
        $songModel = new SongModel();
        $data = isset($arr['data']) ? $arr['data'] : $arr;
        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $song) {
            $songId = isset($song['id']) ? (string) $song['id'] : '';
            if ($songId === '') {
                continue;
            }
            $mid = $this->buildMusicMid($songId, $source);
            $songPicture = $this->getSongPictureFromCacheOrDb($songModel, $mid, $song['pic'] ?? '');
            $temp = [
                'mid' => $mid,
                'name' => html_entity_decode((string) ($song['name'] ?? '')),
                'pic' => $songPicture,
                'length' => intval($song['duration'] ?? 0),
                'singer' => $this->formatArtist($song['artist'] ?? ''),
                'album' => $this->formatText($song['album'] ?? ''),
            ];
            $temp['length'] = $temp['length'] > 0 ? $temp['length'] : self::DEFAULT_SONG_LENGTH;
            $list[] = $temp;
            cache('song_detail_' . $mid, $temp, 3600);
        }
        cache("music_search_list_keyword_new_" . sha1($source . '_' . $keyword . '_' . $page), $list, 3600);

        return $list;
    }

    private function syncSongDetail(SongModel $songModel, array $song)
    {
        $songDetailTemp = $this->getMusicInfoByMid($song['mid'], false, true, true);
        if (!$songDetailTemp) {
            return false;
        }

        $song['name'] = $songDetailTemp['name'] ?: $song['name'];
        $song['singer'] = $songDetailTemp['singer'] ?: $song['singer'];
        $song['album'] = $songDetailTemp['album'] ?: ($song['album'] ?? '');
        $song['pic'] = $songDetailTemp['pic'] ?: $song['pic'];
        $song['length'] = intval($songDetailTemp['length'] ?? 0) ?: intval($song['length'] ?? 0) ?: self::DEFAULT_SONG_LENGTH;

        $songModel->where('song_mid', $song['mid'])->update([
            'song_pic' => $song['pic'],
            'song_length' => $song['length'],
        ]);
        cache("song_picture_" . $song['mid'], $song['pic'], 3600);

        return $song;
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

    private function getMusicLrcByMid($mid)
    {
        $parsed = $this->parseMusicMid($mid);
        if ($parsed['is_upload']) {
            return [];
        }

        $arr = $this->requestMusicApi('lrc', [
            'id' => $parsed['id'],
            'type' => $parsed['source'],
        ]);
        if (!$arr || intval($arr['code'] ?? 0) !== 1 || empty($arr['data'])) {
            return [];
        }

        return $this->parseLrc((string) $arr['data']);
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

    private function parseLrc($lrc)
    {
        $result = [];
        $rows = preg_split("/\r\n|\n|\r/", $lrc);
        foreach ($rows as $row) {
            if (!preg_match_all('/\[(\d{2}):(\d{2})(?:\.(\d{1,3}))?\]/', $row, $matches, PREG_SET_ORDER)) {
                continue;
            }
            $lineLyric = trim(preg_replace('/\[[^\]]+\]/', '', $row));
            foreach ($matches as $match) {
                $fraction = isset($match[3]) ? str_pad($match[3], 3, '0') : '000';
                $time = intval($match[1]) * 60 + intval($match[2]) + intval($fraction) / 1000;
                $result[] = [
                    'lineLyric' => $lineLyric,
                    'time' => $time,
                ];
            }
        }

        usort($result, function ($a, $b) {
            if ($a['time'] == $b['time']) {
                return 0;
            }
            return $a['time'] < $b['time'] ? -1 : 1;
        });

        return $result;
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

    private function getUploadAttachId($mid)
    {
        return abs(intval($mid));
    }

    private function getSongPictureFromCacheOrDb(SongModel $songModel, $mid, $fallback = '')
    {
        $songPicture = $fallback ?: config('startadmin.static_url') . '/new/images/logo.png';
        $songPictureFromCache = cache("song_picture_" . $mid) ?? false;
        if ($songPictureFromCache) {
            return $songPictureFromCache;
        }

        $songFromDatabase = $songModel->where('song_mid', $mid)->find();
        if ($songFromDatabase && $songFromDatabase['song_pic']) {
            return $songFromDatabase['song_pic'];
        }

        return $songPicture;
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
}
