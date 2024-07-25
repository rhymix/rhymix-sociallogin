<?php

namespace Rhymix\Modules\Sociallogin\Controllers;

use Context;
use MemberController;
use MemberModel;
use Mobile;
use Rhymix\Framework\Exceptions\InvalidRequest;
use Rhymix\Framework\Exception;
use Rhymix\Framework\Pagination;
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

	public function dispSocialloginMemberSignup()
	{
		$oMemberView = \MemberView::getInstance();
		$oMemberView->dispMemberSignUpForm();

		$formTags = Context::get('formTags');

		$sociallogin_access_data = $_SESSION['sociallogin_access_data'];

		$formVars = array(
			'profile_url' => false,
			'profile_image' => false,
			'email' => false,
			'name' => false,
			'nick_name' => false,
		);

		foreach ($formVars as $key => $formVar)
		{
			if(isset($sociallogin_access_data->{$key}))
			{
				$formVars[$key] = true;
			}
		}

		if($_SESSION['tmp_sociallogin_input_add_info'])
		{
			foreach ($formTags as $key => $formtag)
			{
				if(!preg_match('/>*</', $formtag->title))
				{
					unset($formTags[$key]);
				}
				if($_SESSION['tmp_sociallogin_input_add_info']['nick_name'])
				{
					if($formtag->name == 'user_id')
					{
						unset($formTags[$key]);
					}

					if($formtag->name == 'user_name')
					{
						unset($formTags[$key]);
					}

					if($formtag->name == 'nick_name')
					{
						unset($formTags[$key]);
					}
				}
			}
		}
		$identifierForm = new \stdClass;
		$identifierForm->title = lang($oMemberView->member_config->identifier);
		$identifierForm->name = $oMemberView->member_config->identifier;
		$identifierForm->show = true;
		if(isset($_SESSION['tmp_sociallogin_input_add_info']['email_address']))
		{
			$identifierForm->show = false;
		}

		Context::set('formVars', $formVars);
		Context::set('formTags', $formTags);
		Context::set('email_confirmation_required', $oMemberView->member_config->enable_confirm);
		Context::set('identifierForm', $identifierForm);

		// Set a template file
		$this->setTemplateFile('signup_form');
	}

	public function procSocialloginMemberSignup()
	{
		Context::setRequestMethod('POST');
		$password = \Rhymix\Framework\Password::getRandomPassword(13);
		$nick_name = preg_replace('/[\pZ\pC]+/u', '', $_SESSION['sociallogin_access_data']->nick_name);

		$vars = Context::getRequestVars();
		debugPrint($vars);
		if($vars->email_address)
		{
			$email = $vars->email_address;
		}
		else
		{
			$email = $_SESSION['sociallogin_access_data']->email;
		}

		Context::set('password', $password, true);
		Context::set('nick_name', $nick_name, true);
		Context::set('user_name', $_SESSION['sociallogin_access_data']->user_name, true);
		Context::set('email_address', $email, true);
		Context::set('accept_agreement', 'Y', true);


		Context::set('homepage', $_SESSION['sociallogin_access_data']->homepage, true);
		Context::set('blog', $_SESSION['sociallogin_access_data']->blog, true);
		Context::set('birthday', $_SESSION['sociallogin_access_data']->birthday, true);
		Context::set('gender', $_SESSION['sociallogin_access_data']->gender, true);
		Context::set('age', $_SESSION['sociallogin_access_data']->age, true);

		debugPrint(Context::get('password'));
		debugPrint(Context::get('nick_name'));
		debugPrint(Context::get('user_name'));
		debugPrint(Context::get('email_address'));
		debugPrint(Context::get('accept_agreement'));
		debugPrint(Context::get('homepage'));
		debugPrint(Context::get('blog'));
		debugPrint(Context::get('birthday'));
		debugPrint(Context::get('gender'));
		debugPrint(Context::get('age'));

		// 회원 모듈에 가입 요청
		// try 를 쓰는이유는 회원가입시 어떤 실패가 일어나는 경우 BaseObject으로 리턴하지 않기에 에러를 출력하기 위함입니다.
		try
		{
			$output = getController('member')->procMemberInsert();
		}
		catch (Exception $exception)
		{
			// 리턴시에도 세션값을 비워줘야함
			unset($_SESSION['tmp_sociallogin_input_add_info']);
			throw new Exception($exception->getMessage());
		}
		unset($_SESSION['tmp_sociallogin_input_add_info']);

		// 가입 도중 오류가 있다면 즉시 출력
		if (is_object($output) && method_exists($output, 'toBool') && !$output->toBool())
		{
			if ($output->error != -1)
			{
				// 리턴값을 따로 저장.
				$return_output = $output;
			}
			else
			{
				return $output;
			}
		}

		// 가입 완료 체크
		if (!$member_srl = getModel('member')->getMemberSrlByEmailAddress($email))
		{
			throw new Exception('msg_error_register_sns');
		}


		unset($_SESSION['tmp_sociallogin_input_add_info']);

		self::clearSession();

		// 가입 완료 후 메세지 출력 (메일 인증 메세지)
		if ($return_output)
		{
			return $return_output;
		}
		$this->setMessage('가입이 완료되었습니다.');

		$redirect_url = getModel('module')->getModuleConfig('member')->after_login_url ?: getNotEncodedUrl('', 'mid', $_SESSION['sociallogin_current']['mid'], 'act', '');
		$this->setRedirectUrl($redirect_url);
	}
}
