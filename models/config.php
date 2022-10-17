<?php

namespace Rhymix\Modules\Sociallogin\Models;

use Rhymix\Modules\Sociallogin\Base;

class Config extends Base
{
	/**
	 * 소셜로그인을 사용하는지 검사
	 * @return bool
	 */
	public static function isEnabled()
	{
		$config = self::getConfig();
		
		if(!is_array($config->sns_services))
		{
			return false;
		}
		
		if(count($config->sns_services) <= 0)
		{
			return false;
		}
		else
		{
			return true;
		}
	}
	
	public static function getUseSNSList($type = 'login')
	{
		$config = self::getConfig();
		$sns_auth_list = array();
		foreach ($config->sns_services as $key => $sns_name)
		{
			$sns_auth_list[$sns_name] = new \stdClass();
			$sns_auth_list[$sns_name]->name = $sns_name;
			$sns_auth_list[$sns_name]->auth_url = self::getAuthUrl($sns_name, $type);
		}
		
		return $sns_auth_list;
	}

	/**
	 * @brief SNS 인증 URL
	 */
	public static function getAuthUrl($service, $type)
	{
		return getUrl('', 'mid', \Context::get('mid'), 'act', 'dispSocialloginConnectSns', 'service', $service, 'type', $type, 'redirect', $_SERVER['QUERY_STRING']);
	}
}
