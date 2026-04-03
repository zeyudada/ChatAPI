<?php

namespace app\api\controller;

use think\App;
use app\api\BaseController;
use app\model\Weapp as WeappModel;
use app\model\User as UserModel;
use app\model\Access as AccessModel;
use EasyWeChat\MiniApp\Application;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Weapp extends BaseController
{
    protected $weapp_appid;
    protected $weapp_appkey;
    protected $easyWeApp;
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new WeappModel();
    }
    /**
     * 初始化微信小程序配置
     *
     * @return void
     */
    private function initWeAppConfig()
    {
        $this->weapp_appid = config('startadmin.weapp_appid'); //小程序APPID
        $this->weapp_appkey = config("startadmin.weapp_appkey"); //小程序的APPKEY
        if (!$this->weapp_appid || !$this->weapp_appkey) {
            return jerr("请先配置微信小程序appid和secret");
        }
        $weapp_config = [
            'app_id' => $this->weapp_appid,
            'secret' => $this->weapp_appkey,
            //必须添加部分
            'http' => [ // 配置
                'verify' => false,
                'timeout' => 4.0,
            ],
        ];
        $this->easyWeApp = new Application($weapp_config);
        return null;
    }
    private function miniAppJson(string $uri, array $payload): array
    {
        return $this->easyWeApp->getClient()->postJson($uri, $payload)->toArray(false);
    }
    private function miniAppUpload(string $uri, string $path): array
    {
        $formData = new FormDataPart([
            'media' => DataPart::fromPath($path),
        ]);
        $response = $this->easyWeApp->getClient()->request('POST', $uri, [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToString(),
        ]);
        return $response->toArray(false);
    }
    private function saveMiniAppCode(string $roomId): string
    {
        if (!is_dir('./weapp_code')) {
            mkdir('./weapp_code', 0755, true);
        }
        $filename = $roomId . '.jpg';
        $this->easyWeApp->getClient()->postJson('/wxa/getwxacodeunlimit', [
            'scene' => $roomId,
            'page' => 'pages/index/index',
            'width' => 600,
        ])->saveAs('./weapp_code/' . $filename);
        return $filename;
    }
    public function checkText($string){
        $error = $this->initWeAppConfig();
        if ($error) {
            return $error;
        }
        $response = $this->miniAppJson('/wxa/msg_sec_check', [
            'content' => $string,
        ]);
        if (($response['errcode'] ?? 0) == 87014) {
            return jerr("你输入的内容过于敏感");
        }
        if (($response['errcode'] ?? 0) != 0) {
            return jerr($response['errmsg'] ?? '内容安全检测失败');
        }
        return false;
    }
    public function checkImg($img){
        $error = $this->initWeAppConfig();
        if ($error) {
            return $error;
        }
        $response = $this->miniAppUpload('/wxa/img_sec_check', $img);
        if (($response['errcode'] ?? 0) == 87014) {
            return jerr("图片过于敏感，发送失败");
        }
        if (($response['errcode'] ?? 0) != 0) {
            return jerr($response['errmsg'] ?? '图片安全检测失败');
        }
        return false;
    }
    public function qrcode()
    {
        $error = $this->initWeAppConfig();
        if ($error) {
            return $error;
        }
        if (!input('room_id')) {
            return jerr('room_id missing');
        }
        $room_id = (string) input('room_id');
        $filename = $this->saveMiniAppCode($room_id);
        header('Location: https://music.eggedu.cn/weapp_code/' . $filename);
        //FUCK YOUR BUG 上面的地址改成你自己的API地址
    }
    public function test()
    {
        $error = $this->initWeAppConfig();
        if ($error) {
            return $error;
        }
        if (!input('room_id')) {
            return jerr('room_id missing');
        }
        $room_id = (string) input('room_id');
        $filename = $this->saveMiniAppCode($room_id);
        echo $filename;
    }
    /**
     * 微信小程序登录
     *
     * @return void
     */
    public function wxAppLogin()
    {
        $error = $this->initWeAppConfig();
        if ($error) {
            return $error;
        }
        $userModel = new UserModel();
        $accessModel = new AccessModel();
        if (input("?code")) {
            $code = input("code");
            $ret = $this->easyWeApp->getUtils()->codeToSession($code);
            if (array_key_exists("session_key", $ret)) {
                $session_key = $ret['session_key'];
                $openid = $ret['openid'];
                $app_id = 1005;
                $nickname = '小程序' . rand(1000, 9999);
                $head = 'https://music.eggedu.cn/new/images/nohead.jpg';
                $extra = $openid;
                $sex = 0;
                $user = $userModel->where('user_openid', $openid)->where('user_app', $app_id)->find();
                if (!$user) {
                    $userModel->regByOpen($openid, $nickname, $head, $sex,  $app_id, $extra);
                    $user = $userModel->where('user_openid', $openid)->where('user_app', $app_id)->find();
                }
                if ($user) {
                    //创建一个新的授权
                    $access = $accessModel->createAccess($user['user_id'], $app_id);
                    if ($access) {
                        return jok('登录成功', ['access_token' => $access['access_token']]);
                    } else {
                        return jerr('登录系统异常');
                    }
                } else {
                    return jerr('帐号或密码错误');
                }
            } else {
                return jerr("获取session_key失败");
            }
        } else {
            return jerr("你应该传code给我", 400);
        }
    }
    /**
     * 微信小程序手机号解密
     *
     * @return void
     */
    public function wxPhoneDecodeLogin()
    {
        $error = $this->initWeAppConfig();
        if ($error) {
            return $error;
        }
        if (input("?iv") && input("?encryptedData") && input("?session_key")) {
            $iv = input("iv");
            $encryptedData = input("encryptedData");
            $session_key = input("session_key");
            try {
                $decryptedData = $this->easyWeApp->getUtils()->decryptSession($session_key, $iv, $encryptedData);

                if (array_key_exists("phoneNumber", $decryptedData)) {
                    return jok('success', [
                        'phone' => $decryptedData['phoneNumber']
                    ]);
                } else {
                    return jerr("解密出了问题");
                }
            } catch (\Throwable $e) {
                return jerr($e->getMessage());
            }
        } else {
            return jerr("是不是所有的参数都POST过来了", 400);
        }
    }
}
