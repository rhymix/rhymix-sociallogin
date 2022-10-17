<?php

namespace Rhymix\Modules\Sociallogin;

use Context;
use ModuleModel;

class Base extends \ModuleObject
{
	public static $config = null;

	public static $default_services = array(
		'twitter',
		'facebook',
		'google',
		'naver',
		'kakao',
		'discord',
		'github',
		'apple',
	);
	
	public static function getConfig()
	{
		if(self::$config === null)
		{
			$config = ModuleModel::getModuleConfig('sociallogin') ?: new \stdClass();
			
			if (!$config->delete_auto_log_record)
			{
				$config->delete_auto_log_record = 0;
			}

			if (!$config->skin)
			{
				$config->skin = 'default';
			}

			if (!$config->mskin)
			{
				$config->mskin = 'default';
			}

			if (!$config->sns_follower_count)
			{
				$config->sns_follower_count = 0;
			}

			if (!$config->mail_auth_valid_hour)
			{
				$config->mail_auth_valid_hour = 0;
			}

			if (!$config->sns_services)
			{
				$config->sns_services = [];
			}

			if (!$config->sns_input_add_info)
			{
				$config->sns_input_add_info = [];
			}
			
			self::$config = $config;
		}

		return self::$config;
	}
	
	/**
	 * Get Library for sns 
	 * @param $driver_name
	 * @return Drivers\Base
	 */
	public static function getDriver(string $driver_name): Drivers\Base
	{
		$class_name = '\\Rhymix\\Modules\\Sociallogin\\Drivers\\' . ucfirst($driver_name);
		return $class_name::getInstance();
	}
	
	/**
	 * @param service
	 * @return mixed
	 */
	public static function getDriverAuthData($service)
	{
		return $_SESSION['sociallogin_driver_auth'][$service] ?? null;
	}

	/**
	 * @param $service
	 * @param $type
	 * @param $value
	 * @return bool
	 */
	public static function setDriverAuthData($service, $type, $value)
	{
		if(!$service)
		{
			return false;
		}
		if(!isset($_SESSION['sociallogin_driver_auth'][$service]))
		{
			$_SESSION['sociallogin_driver_auth'][$service] = new \stdClass();
		}
		if($type == 'token')
		{
			$_SESSION['sociallogin_driver_auth'][$service]->token = $value;
		}
		else if ($type == 'profile')
		{
			$_SESSION['sociallogin_driver_auth'][$service]->profile = $value;
		}
		else
		{
			unset($_SESSION['sociallogin_driver_auth'][$service]);
			return false;
		}
		return true;
	}
	
	/**
	 * Clear session info
	 */
	public static function clearSession()
	{
		unset($_SESSION['sociallogin_driver_auth']);
		unset($_SESSION['sociallogin_auth']);
		unset($_SESSION['sociallogin_access_data']);
		unset($_SESSION['tmp_sociallogin_input_add_info']);
		unset($_SESSION['sociallogin_current']);
	}

	/**
	 * @brief 로그기록
	 **/
	public static function logRecord($act, $info = null)
	{
		if (!is_object($info))
		{
			$info = Context::getRequestVars();
		}

		$args = new \stdClass;

		switch ($act)
		{
			case 'procSocialloginSnsClear' :
				$args->category = 'sns_clear';
				$args->content = sprintf(lang('sns_connect_clear'), $info->sns);
				break;

			case 'procSocialloginSnsLinkage' :
				$args->category = 'linkage';
				$args->content = sprintf(lang('sns_connect_linkage'), $info->sns, $info->linkage);
				break;

			case 'dispSocialloginConnectSns' :
				$args->category = 'auth_request';
				$args->content = sprintf(lang('sns_connect_auth_request'), $info->sns);
				break;

			case 'procSocialloginCallback' :
				$args->category = $info->type;

				
				if ($info->type == 'register')
				{
					$info->msg = $info->msg ?: lang('sns_connect_register_success');
					$args->content = sprintf(lang('sns_connect_exec_register'), $info->sns, Context::getLang($info->msg));
				}
				else if ($info->type == 'login')
				{
					$info->msg = $info->msg ?: lang('sns_connect_login_success');
					$args->content = sprintf(lang('sns_connect_exec_login'), $info->sns, Context::getLang($info->msg));
				}
				else
				{
					//TODO(BJRambo): Add to log for recheck
				}

				break;
				
			case 'linkage' :
				$args->category = 'linkage';
				$args->content = sprintf(lang('sns_connect_document'), $info->sns, $info->title);
				break;

			case 'delete_member' :
				$args->category = 'delete_member';

				if ($info->nick_name)
				{
					$args->content = sprintf(lang('sns_connect_delete_member'), $info->member_srl, $info->nick_name, $info->service_id);
				}
				else
				{
					$args->content = sprintf(lang('sns_connect_auto_delete_member'), $info->member_srl, $info->nick_name, $info->service_id);
				}

				break;
		}

		if (!$args->category)
		{
			$args->category = 'unknown';
			$args->content = sprintf('%s (act : %s)', Context::getLang('unknown'), $act);
		}

		$args->act = $act;
		$args->micro_time = microtime(true);
		$args->member_srl = Context::get('logged_info')->member_srl;

		executeQuery('sociallogin.insertLogRecord', $args);
	}
}
