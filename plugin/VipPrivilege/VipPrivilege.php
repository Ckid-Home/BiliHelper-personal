<?php declare(strict_types=1);

/**
 *  Website: https://mudew.com/
 *  Author: Lkeme
 *  License: The MIT License
 *  Email: Useri@live.cn
 *  Updated: 2018 ~ 2026
 *
 *   _____   _   _       _   _   _   _____   _       _____   _____   _____
 *  |  _  \ | | | |     | | | | | | | ____| | |     |  _  \ | ____| |  _  \ &   ／l、
 *  | |_| | | | | |     | | | |_| | | |__   | |     | |_| | | |__   | |_| |   （ﾟ､ ｡ ７
 *  |  _  { | | | |     | | |  _  | |  __|  | |     |  ___/ |  __|  |  _  /  　 \、ﾞ ~ヽ   *
 *  | |_| | | | | |___  | | | | | | | |___  | |___  | |     | |___  | | \ \   　じしf_, )ノ
 *  |_____/ |_| |_____| |_| |_| |_| |_____| |_____| |_|     |_____| |_|  \_\
 */

use Bhp\Api\Vip\ApiExperience;
use Bhp\Api\Vip\ApiPrivilege;
use Bhp\Log\Log;
use Bhp\Plugin\BasePlugin;
use Bhp\Plugin\Plugin;
use Bhp\TimeLock\TimeLock;
use Bhp\User\User;
use Bhp\Util\Exceptions\NoLoginException;
use function Amp\delay;

class VipPrivilege extends BasePlugin
{
    /**
     * 插件信息
     * @var array|string[]
     */
    public ?array $info = [
        'hook' => __CLASS__, // hook
        'name' => 'VipPrivilege', // 插件名称
        'version' => '0.0.1', // 插件版本
        'desc' => '领取大会员权益', // 插件描述
        'author' => 'Lkeme',// 作者
        'priority' => 1107, // 插件优先级
        'cycle' => '24(小时)', // 运行周期
    ];

    /**
     * @var array|string[]
     * BCoin: 1,
     * MallCoupon: 2,
     * ComicCoupon: 3,
     * FreightCoupon: 4,
     * ComicMallCoupon: 5,
     * GarbFreeCoupon: 6,
     * CheeseClasseCoupon: 7,
     * KingOfGloryCoupon: 8,
     * LevelAcceleration: 9,
     * CakeCoupon: 10,
     * MovieCoupon: 11,
     * DisneyCoupon: 12,
     * BirthdayImage: 13,
     * MovieVoucher: 14,
     * StarBox: 15,
     * MagicStone: 16,
     * GameCoupon: 17
     */
    protected array $privilege = [
        0 => '未知奖励0(未知奖励0)',
        1 => '年度专享B币赠送(B币券)',
        2 => '年度专享会员购优惠券(会员购优惠券)',
        3 => '年度专享漫画礼包(漫画福利券)',
        4 => '大会员专享会员购包邮券(会员购包邮券)',
        5 => '年度专享漫画礼包(漫画商城优惠券)',
        6 => '大会员专享会员体验卡(装扮体验卡)',
        7 => '大会员专享课堂优惠券(课堂优惠券)',
        8 => '大会员专享王者荣耀优惠券(游戏优惠券)',
        9 => '会员观看任意1个视频即可领取，日限1次(额外经验)',
        10 => '大会员专享蛋糕优惠券(蛋糕优惠券)',
        11 => '大会员专享电影优惠券(电影优惠券)',
        12 => '大会员专享迪士尼优惠券(迪士尼优惠券)',
        13 => '大会员专享生日礼图(生日礼图)',
        14 => '大会员专享电影券(电影券)',
        15 => '年度专享会员购星光宝盒88折券(折扣券)',
        16 => '大会员专享会员购10魔晶(魔晶)',
        17 => '大会员专享游戏优惠券(游戏优惠券)',
    ];

    /**
     * @var array|string[]
     */
    protected array $privilege_blacklists = [
        18 => '淘宝账号查询异常，请退出重试',
        20 => '饿了么领取活动已经过期~',
        21 => '超大会员身份状态异常',
        24 => '请求错误', // 未知
        25 => '请求错误', // 未知
        26 => '请求错误', // 正式大会员专属票务优惠券-229减18
        27 => '请求错误', // 正式漫展-大会员专属票务优惠券169减10
    ];

    /**
     * @param Plugin $plugin
     */
    public function __construct(Plugin &$plugin)
    {
        //
        TimeLock::initTimeLock();
        // $this::class
        $plugin->register($this, 'execute');
    }

    /**
     * 执行
     * @return void
     * @throws NoLoginException
     */
    public function execute(): void
    {
        if (TimeLock::getTimes() > time() || !getEnable('vip_privilege')) return;
        //
        $this->receiveTask();
        //
        // 定时23点 + 随机10-30分钟
        TimeLock::setTimes(TimeLock::timing(23) + mt_rand(10, 30) * 60);
    }

    /**
     * 领取
     * @return void
     * @throws NoLoginException
     */
    protected function receiveTask(): void
    {
        // 如果为年度大会员
        if (!User::isYearVip('大会员权益')) return;
        //
        $privilege_list = $this->filterCanReceive($this->myVipPrivilege());
        Log::info('大会员权益: 可领取权益数 ' . count($privilege_list));
        //
        foreach ($privilege_list as $privilege) {
            // 随机延迟 5-10秒
            delay(mt_rand(5, 10));
            // 特殊类型 9 每日10经验 需要观看视频
            if ($privilege['type'] == 9) {
                // 领取额外经验
                $this->myVipExtraExp();
                continue;
            }
            // 领取奖励
            $this->myVipPrivilegeReceive($privilege['type']);
        }
    }

    /**
     * 过滤可领取的权益
     * @param array $privilege_list
     * @return array
     */
    protected function filterCanReceive(array $privilege_list): array
    {
        // 是否领取状态 0：未兑换 | 1：已兑换 | 2：未完成（若需要完成）
        // 黑名单
        return array_filter($privilege_list, function ($privilege) {
            return $privilege['state'] == 0 && !array_key_exists($privilege['type'], $this->privilege_blacklists);
        });
    }


    /**
     * 大会员额外经验
     * @return void
     */
    protected function myVipExtraExp(): void
    {
        $response = ApiExperience::add();
        //
        if (!$response['code']) {
            Log::notice("大会员额外经验: 领取额外经验成功");
        } else if ($response['code'] == 69198) {
            Log::info("大会员额外经验: 用户经验已经领取");
        } else {
            Log::warning("大会员额外经验: 领取额外经验失败  {$response['code']} -> {$response['message']}");
        }
    }


    /**
     * 获取我的大会员权益列表
     * @return array
     */
    protected function myVipPrivilege(): array
    {
        // {"code":0,"message":"0","ttl":1,"data":{"list":[{"type":1,"state":0,"expire_time":1622476799},{"type":2,"state":0,"expire_time":1622476799}]}}
        $response = ApiPrivilege::my();
        //
        if ($response['code']) {
            Log::warning("大会员权益: 获取权益列表失败 {$response['code']} -> {$response['message']}");
            return [];
        } else {
            Log::info('大会员权益: 获取权益列表成功 ' . count($response['data']['list']));
            return $response['data']['list'];
        }
    }

    /**
     * 领取我的大会员权益
     * @param int $type
     * @throws NoLoginException
     */
    protected function myVipPrivilegeReceive(int $type): void
    {
        // {"code":0,"message":"0","ttl":1}
        // {"code":73319,"message":"73319","ttl":1}
        // {-101: "账号未登录", -111: "csrf 校验失败", -400: "请求错误", 69800: "网络繁忙 请稍后重试", 69801: "你已领取过该权益"}
        $response = ApiPrivilege::receive($type);
        // 判断type是否在$this->privilege
        if (!array_key_exists($type, $this->privilege)) {
            $type = "未知奖励$type";
        } else {
            $type = $this->privilege[$type];
        }
        //
        switch ($response['code']) {
            case -101:
                throw new NoLoginException($response['message']);
            case 0:
                Log::notice("大会员权益: 领取权益[$type]成功");
                break;
            case 73319:
                Log::warning("大会员权益: 领取权益[$type]失败，暂时未到可领取时间");
                break;
            default:
                Log::warning("大会员权益: 领取权益[$type]失败， {$response['code']} -> {$response['message']}");
                break;
        }
    }

}
