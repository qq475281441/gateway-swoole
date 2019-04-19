<?php

namespace app\common;

use im\core\facade\Cache;
use JPush\Client;
use think\Db;

/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/8
 * Time: 11:51
 */
class JiGuangPush
{
	public    $client;
	
	protected $app_key       = '123';
	
	protected $master_secret = '123';
	
	public function __construct()
	{
		try {
			$this->client = new Client($this->app_key, $this->master_secret);
		} catch (\Exception $e) {
			//防止中断应用
			return;
		}
	}
	
	/**
	 * 聊天消息
	 * @param $account_id   商户id
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function pushImMsg($account_id, $from_name, $msg, $from_uid)
	{
		if (!$this->checkPushOptions($account_id, 'im_message_notice')) {
			return false;
		}
		$title   = $from_name;
		$content = $msg;
		$r       = $this->pushToAccount($title, $content, $account_id, ['from_uid' => $from_uid]);
		return $r ? true : false;
	}
	
	/**
	 * 判断商户有没有开启这个推送
	 * @param $account_id
	 * @param $value        需要判断的推送类型
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function checkPushOptions($account_id, $value)
	{
		$data = [];
		if (Cache::has($account_id . 'PushOptions')) {//缓存一下
			$data = Cache::get($account_id . 'PushOptions');
		} else {
			$data = Db::name('app_push_options')->where('account_id', $account_id)->find();
			if (!$data) {//此用户没有此记录
				Db::name('app_push_options')->insert(['account_id' => $account_id]);//添加一个
				$data = Db::name('app_push_options')->where('account', $account_id)->find();//重新查询
				Cache::set('', $data, 600);
			} else {
				Cache::set('', $data, 600);
			}
		}
		if (isset($data[$value]) && $data[$value] == '1') {
			return true;
		} else {
			return false;
		}
	}
	
	/**
	 * 推送信息给某个商户
	 * @param       $title
	 * @param       $msg
	 * @param       $account_id
	 * @param array $extras
	 * @return array
	 */
	public function pushToAccount($title, $msg, $account_id, $extras = [])
	{
		try {
			return $this->client->push()->addAlias("$account_id")
				->setPlatform(array('ios', 'android'))
				->iosNotification("$title\n$msg", array(
					'sound'    => 'sound.caf',
					'category' => 'kfk',
					'extras'   => $extras,
				))
				->androidNotification($msg, array(
					'title'    => $title,
					'category' => 'kfk',
					'extras'   => $extras,
				))
				->send();
		} catch (\Exception $e) {
			//用户没有绑定设备
			return false;
		}
	}
}