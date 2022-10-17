<?php

namespace Rhymix\Modules\Sociallogin\Models;

use Context;
use MemberModel;
use Rhymix\Framework\Session;
use Rhymix\Modules\Sociallogin\Base;

class User extends Base
{
	/**
	 * @brief 회원 SNS
	 */
	public static function getMemberSnsList($member_srl = null, $type = 'login')
	{
		if(!$member_srl)
		{
			if(!Session::getMemberSrl())
			{
				return false;
			}
			$member_srl = Session::getMemberSrl();
		}
		
		$args = new \stdClass;
		$args->member_srl = $member_srl;
		$output = executeQueryArray('sociallogin.getMemberSns', $args);
		$memberSNSList = $output->data;

		$useSNSList = Config::getUseSNSList($type);
		foreach ($memberSNSList as $key => $userSNSData)
		{
			$memberSNSList[$key]->auth_url = $useSNSList[$userSNSData->service]->auth_url;
		}
		
		return $output->data;
	}

	/**
	 * @param $service
	 * @param null $member_srl
	 * @return bool|object
	 */
	public static function getMemberSnsByService($service, $member_srl = null)
	{
		if(!$member_srl)
		{
			if (!Session::getMemberSrl())
			{
				return false;
			}
			$member_srl = Session::getMemberSrl();
		}
		if(!$service)
		{
			return false;
		}
		
		$args = new \stdClass();
		$args->service = $service;
		$args->member_srl = $member_srl;
		$output = executeQuery('sociallogin.getMemberSns', $args);
		
		return $output->data;
	}

	/**
	 * @brief SNS ID로 회원조회
	 */
	public static function getMemberSnsById($id, $service = null)
	{
		$args = new \stdClass;
		$args->service_id = $id;
		$args->service = $service;

		return executeQuery('sociallogin.getMemberSns', $args)->data;
	}

	/**
	 * @brief SNS ID 첫 로그인 조회
	 */
	public static function getSnsUser($id, $service = null)
	{
		$args = new \stdClass;
		$args->service_id = $id;
		$args->service = $service;

		return executeQuery('sociallogin.getSnsUser', $args)->data;
	}

	/**
	 * @brief SNS 유저여부
	 */
	public static function memberUserSns($member_srl = null)
	{
		$sns_list = self::getMemberSnsList($member_srl);

		if (!is_array($sns_list))
		{
			$sns_list = array($sns_list);
		}

		if (count($sns_list) > 0)
		{
			return true;
		}

		return false;
	}

	/**
	 * @brief 기존 유저여부 (가입일과 SNS 등록일이 같다면)
	 */
	public static function memberUserPrev($member_srl = null)
	{
		if (!$member_srl)
		{
			if (!Context::get('is_logged'))
			{
				return;
			}

			$member_srl = Context::get('logged_info')->member_srl;
		}

		$member_info = MemberModel::getMemberInfoByMemberSrl($member_srl);

		$args = new \stdClass;
		$args->regdate_less = date('YmdHis', strtotime(sprintf('%s +1 minute', $member_info->regdate)));
		$args->member_srl = $member_srl;

		if (!executeQuery('sociallogin.getMemberSns', $args)->data)
		{
			return true;
		}

		return false;
	}
	
	public static function getSocialloginButtons($type = 'login')
	{
		$snsList = Config::getUseSNSList();
		
		$buff = [];
		$buff[] = '<ul class="sociallogin_login">';
		$signString = ($type === 'signup') ? 'Sign up' : 'Sign in';
		foreach ($snsList as $key => $sns)
		{
			$ucfirstName = ucfirst($sns->name);
			$buff[] = "<li><div class=\"sociallogin_{$sns->name}\">";
			$buff[] = "<a class=\"loginBtn\" href=\"{$sns->auth_url}\"><span class=\"icon\"></span>";
			$buff[] = "<span class=\"buttonText\"> {$signString} with {$ucfirstName}</span>";
			$buff[] = '</a></div></li>';
		}
		$buff[] = '</ul>';
		
		return implode('', $buff);
	}
}
