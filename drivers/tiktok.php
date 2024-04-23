<?php

namespace Rhymix\Modules\Sociallogin\Drivers;

class Tiktok extends Base
{
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
}
