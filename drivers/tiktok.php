<?php

namespace Rhymix\Modules\Sociallogin\Drivers;
use Context;
class Tiktok extends Base
{
	private static $TiktokApiAuthTokenUrl = "https://open.tiktokapis.com/v2/";
	function createAuthUrl(string $type = 'login'): string
	{
		$config = $this->config;

		$fields = [
			'client_key'    => $config->tiktok_client_key,
			'state'         => $_SESSION['sociallogin_auth']['state'],
			'response_type' => 'code',
			'scope'         => 'user.info.basic,user.info.profile',
			'redirect_uri'  => getNotEncodedFullUrl('', 'module', 'sociallogin', 'act', 'procSocialloginCallback', 'service', 'tiktok'),
		];


		return 'https://www.tiktok.com/v2/auth/authorize?'.http_build_query($fields);
	}

	function authenticate()
	{
		// 오류가 있을 경우 메세지 출력
		$vars = Context::getRequestVars();

		$post = [
			"client_key" => $this->config->tiktok_client_key,
			"client_secret" => $this->config->tiktok_client_secret,
			"code" => $vars->code,
			"grant_type" => "authorization_code",
			"redirect_uri" => getNotEncodedFullUrl('', 'module', 'sociallogin', 'act', 'procSocialloginCallback', 'service', 'tiktok'),
		];

		$token = $this->requestAPI('oauth/token/', $post);

		$accessValue['access'] = $token['access_token'];
		$accessValue['refresh'] = $token['refresh_token'];
		\Rhymix\Modules\Sociallogin\Base::setDriverAuthData('tiktok', 'token', $accessValue);

		return new \BaseObject();
	}

	/**
	 * @brief 인증 후 프로필을 가져옴.
	 * @return \BaseObject
	 */
	function getSNSUserInfo()
	{
		// 토큰 체크
		$token = \Rhymix\Modules\Sociallogin\Base::getDriverAuthData('tiktok')->token['access'];
		if (!$token)
		{
			return new \BaseObject(-1, 'msg_errer_api_connect');
		}

		$headers = array(
			'Authorization' => "Bearer {$token}",
		);
		$user_info = $this->requestAPI('user/info/', ["fields" => 'open_id,union_id,display_name,avatar_large_url'], $headers, false , "GET");
		// ID, 이름, 프로필 이미지, 프로필 URL
		$profileValue['sns_id'] = $user_info['data']['user']['display_name'];
		$profileValue['user_name'] = $user_info['data']['user']['display_name'];
		$profileValue['etc'] = $user_info;

		\Rhymix\Modules\Sociallogin\Base::setDriverAuthData('tiktok', 'profile', $profileValue);
	}


	function requestAPI($url, $post = array(), $authorization = null, $delete = false, $method = "POST")
	{
		$resource = \FileHandler::getRemoteResource(self::$TiktokApiAuthTokenUrl . $url, null, 3, $method, null, $authorization, array(), $post);
		return json_decode($resource, true);
	}
}
