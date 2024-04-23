<?php

namespace Rhymix\Modules\Sociallogin\Controllers;

use Context;
use MemberController;
use MemberModel;
use Mobile;
use Rhymix\Framework\Exceptions\InvalidRequest;
use Rhymix\Framework\Exception;
use Rhymix\Framework\Session;
use Rhymix\Modules\Sociallogin\Base;
use Rhymix\Modules\Sociallogin\Models\Config as ConfigModel;
use Rhymix\Modules\Sociallogin\Models\User as UserModel;

class User extends Base
{
	/**
	 * @brief Initialization
	 */
	public function init()
	{
		$config = $this->getConfig();
		if (Mobile::isFromMobilePhone() && !empty($config->mskin))
		{
			$this->setTemplatePath(sprintf('%sm.skins/%s/', $this->module_path, $config->mskin));
		}
		else
		{
			$this->setTemplatePath(sprintf('%sskins/%s/', $this->module_path, $config->skin ?? 'default'));
		}

		Context::set('config', $config);
		Context::loadFile([$this->module_path . 'views/js/sociallogin.js', 'body']);
	}

	/**
	 * @brief SNS 관리
	 */
	public function dispSocialloginSnsManage()
	{
		if (self::getConfig()->sns_manage !== 'Y')
		{
			throw new InvalidRequest;
		}

		if (!Context::get('is_logged'))
		{
			throw new Exception('msg_not_logged');
		}

		foreach (self::getConfig()->sns_services as $key => $val)
		{
			$args = new \stdClass;
			$sns_info = UserModel::getMemberSnsByService($val);

			if ($sns_info->name)
			{
				$args->register = true;
				$args->sns_status = sprintf('<a href="%s" target="_blank">%s</a>', $sns_info->profile_url, $sns_info->name);
			}
			else
			{
				$args->auth_url = ConfigModel::getAuthUrl($val, 'register');
				$args->sns_status = Context::getLang('status_sns_no_register');
			}

			$args->service = $val;
			$args->linkage = $sns_info->linkage;

			$sns_services[$key] = $args;
		}
		Context::set('sns_services', $sns_services);

		$this->setTemplateFile('sns_manage');
	}

	/**
	 * @brief SNS 연결 진행
	 */
	public function dispSocialloginConnectSns()
	{
		if (isCrawler())
		{
			throw new InvalidRequest;
		}

		$service = Context::get('service');
		if (!$service || !in_array($service, self::getConfig()->sns_services))
		{
			throw new Exception('msg_not_support_service_login');
		}
		if (!$oDriver = $this->getDriver($service))
		{
			throw new InvalidRequest;
		}

		if (!$type = Context::get('type'))
		{
			throw new InvalidRequest;
		}

		if ($type == 'register' && !Context::get('is_logged'))
		{
			throw new Exception('msg_not_logged');
		}
		else if ($type == 'login' && Context::get('is_logged'))
		{
			throw new Exception('already_logged');
		}
		// 인증 메일 유효 시간
		if (self::getConfig()->mail_auth_valid_hour)
		{
			$args = new \stdClass;
			$args->list_count = 5;
			$args->regdate_less = date('YmdHis', strtotime(sprintf('-%s hour', self::getConfig()->mail_auth_valid_hour)));
			$output = executeQueryArray('sociallogin.getAuthMailLess', $args);

			if ($output->toBool())
			{
				$oMemberController = MemberController::getInstance();

				foreach ($output->data as $key => $val)
				{
					if (!$val->member_srl)
					{
						continue;
					}

					$oMemberController->deleteMember($val->member_srl);
				}
			}
		}

		$_SESSION['sociallogin_auth']['type'] = $type;
		$_SESSION['sociallogin_auth']['mid'] = Context::get('mid');
		$_SESSION['sociallogin_auth']['redirect'] = Context::get('redirect');
		$_SESSION['sociallogin_auth']['state'] = md5(microtime() . mt_rand());

		$this->setRedirectUrl($oDriver->createAuthUrl($type));

		// 로그 기록
		$info = new \stdClass;
		$info->sns = $service;
		$info->type = $type;
		self::logRecord($this->act, $info);
	}

	/**
	 * @brief SNS 프로필
	 */
	public function dispSocialloginSnsProfile()
	{
		if (self::getConfig()->sns_profile != 'Y')
		{
			throw new InvalidRequest;
		}

		if (!Context::get('member_srl'))
		{
			throw new InvalidRequest;
		}

		if (!($member_info = MemberModel::getMemberInfoByMemberSrl(Context::get('member_srl'))) || !$member_info->member_srl)
		{
			throw new InvalidRequest;
		}

		Context::set('member_info', $member_info);

		foreach (self::getConfig()->sns_services as $key => $val)
		{
			if (!($sns_info = UserModel::getMemberSnsByService($val, $member_info->member_srl)) || !$sns_info->name)
			{
				continue;
			}

			$args = new \stdClass;
			$args->profile_name = $sns_info->name;
			$args->profile_url = $sns_info->profile_url;
			$args->service = $val;

			$sns_services[$key] = $args;
		}

		Context::set('sns_services', $sns_services);

		$this->setTemplateFile('sns_profile');
	}

	/**
	 * @brief SNS 연결
	 **/
	public function procSocialloginSnsLinkage()
	{
		if (!$this->user->isMember())
		{
			throw new Exception('msg_not_logged');
		}

		if (!$service = Context::get('service'))
		{
			throw new InvalidRequest;
		}

		if (!$oDriver = $this->getDriver($service))
		{
			throw new InvalidRequest;
		}

		if (!($sns_info = UserModel::getMemberSnsByService($service)) || !$sns_info->name)
		{
			throw new Exception('msg_not_linkage_sns_info');
		}

		// 토큰 넣기
		$tokenData = Connect::setAvailableAccessToken($oDriver, $sns_info);

		// 연동 체크
		if (($check = $oDriver->checkLinkage()) && $check instanceof Object && !$check->toBool() && $sns_info->linkage != 'Y')
		{
			return $check;
		}

		$args = new \stdClass;
		$args->service = $service;
		$args->linkage = ($sns_info->linkage == 'Y') ? 'N' : 'Y';
		$args->member_srl = Context::get('logged_info')->member_srl;

		$output = executeQuery('sociallogin.updateMemberSns', $args);
		if (!$output->toBool())
		{
			return $output;
		}

		// 로그 기록
		$info = new \stdClass;
		$info->sns = $service;
		$info->linkage = $args->linkage;
		self::logRecord($this->act, $info);

		$this->setMessage('msg_success_linkage_sns');

		$this->setRedirectUrl(getNotEncodedUrl('', 'mid', Context::get('mid'), 'act', 'dispSocialloginSnsManage'));
	}

	/**
	 * SNS 연결 해제
	 */
	public function procSocialloginSnsClear()
	{
		if (!$this->user->isMember())
		{
			throw new Exception('msg_not_logged');
		}

		if (!$service = Context::get('service'))
		{
			throw new InvalidRequest;
		}

		if (!$oDriver = $this->getDriver($service))
		{
			throw new InvalidRequest;
		}

		if (!($sns_info = UserModel::getMemberSnsByService($service)) || !$sns_info->name)
		{
			throw new InvalidRequest;
		}

		if (self::getConfig()->sns_login == 'Y' && self::getConfig()->default_signup != 'Y')
		{
			// TODO(BJRambo) : check get to list;
			$sns_list = UserModel::getMemberSnsList();

			if (!is_array($sns_list))
			{
				$sns_list = array($sns_list);
			}

			if (count($sns_list) < 2)
			{
				throw new Exception('msg_not_clear_sns_one');
			}
		}

		$args = new \stdClass;
		$args->service = $service;
		$args->member_srl = Session::getMemberSrl();

		$output = executeQuery('sociallogin.deleteMemberSns', $args);
		if (!$output->toBool())
		{
			return $output;
		}

		// 토큰 넣기
		$tokenData = Connect::setAvailableAccessToken($oDriver, $sns_info, false);

		// 토큰 파기
		$oDriver->revokeToken($tokenData['access']);

		// 로그 기록
		$info = new \stdClass;
		$info->sns = $service;
		self::logRecord($this->act, $info);

		$this->setMessage('msg_success_sns_register_clear');

		$this->setRedirectUrl(getNotEncodedUrl('', 'mid', Context::get('mid'), 'act', 'dispSocialloginSnsManage'));
	}
}
