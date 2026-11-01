<?php
/**
 * User-Agent çözümleyici: bot tespiti, tarayıcı, sürüm, işletim sistemi, cihaz türü.
 * Harici bağımlılık olmadan, yaygın imzaları kapsayan hafif bir çözümleyicidir.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_UA_Parser {

	/**
	 * Bilinen botlar: imza => görünen ad.
	 */
	private static $known_bots = array(
		'googlebot'          => 'Googlebot',
		'bingbot'            => 'Bingbot',
		'slurp'              => 'Yahoo Slurp',
		'duckduckbot'        => 'DuckDuckBot',
		'baiduspider'        => 'Baidu Spider',
		'yandexbot'          => 'YandexBot',
		'yandeximages'       => 'Yandex Görseller',
		'sogou'              => 'Sogou',
		'exabot'             => 'Exabot',
		'facebookexternalhit' => 'Facebook Önizleme',
		'facebot'            => 'Facebook Bot',
		'ia_archiver'        => 'Alexa/Archive.org',
		'msnbot'             => 'MSNBot',
		'ahrefsbot'          => 'AhrefsBot',
		'semrushbot'         => 'SemrushBot',
		'dotbot'             => 'DotBot',
		'rogerbot'           => 'Rogerbot',
		'screaming frog'     => 'Screaming Frog',
		'petalbot'           => 'PetalBot',
		'gptbot'             => 'GPTBot',
		'oai-searchbot'      => 'OpenAI SearchBot',
		'chatgpt-user'       => 'ChatGPT User',
		'claudebot'          => 'ClaudeBot',
		'claude-web'         => 'Claude Web',
		'anthropic-ai'       => 'Anthropic AI',
		'perplexitybot'      => 'PerplexityBot',
		'bytespider'         => 'ByteSpider',
		'applebot'           => 'Applebot',
		'amazonbot'          => 'Amazonbot',
		'twitterbot'         => 'Twitterbot',
		'linkedinbot'        => 'LinkedInBot',
		'whatsapp'           => 'WhatsApp Önizleme',
		'telegrambot'        => 'TelegramBot',
		'discordbot'         => 'Discordbot',
		'pinterestbot'       => 'PinterestBot',
		'mj12bot'            => 'Majestic MJ12Bot',
		'seznambot'          => 'SeznamBot',
		'blexbot'            => 'BLEXBot',
		'dataforseobot'      => 'DataForSEO Bot',
		'serpstatbot'        => 'SerpstatBot',
		'uptimerobot'        => 'UptimeRobot',
		'pingdom'            => 'Pingdom',
		'statuscake'         => 'StatusCake',
		'site24x7'           => 'Site24x7',
		'gtmetrix'           => 'GTmetrix',
		'lighthouse'         => 'Lighthouse',
		'headlesschrome'     => 'Headless Chrome',
		'phantomjs'          => 'PhantomJS',
		'python-requests'    => 'Python Requests',
		'python-urllib'      => 'Python urllib',
		'aiohttp'            => 'Python aiohttp',
		'scrapy'             => 'Scrapy',
		'curl/'              => 'cURL',
		'wget/'              => 'Wget',
		'libwww'             => 'libwww',
		'okhttp'             => 'OkHttp',
		'go-http-client'     => 'Go HTTP Client',
		'java/'              => 'Java HTTP',
		'apache-httpclient'  => 'Apache HttpClient',
		'guzzlehttp'         => 'Guzzle',
		'node-fetch'         => 'Node Fetch',
		'axios/'             => 'Axios',
		'postmanruntime'     => 'Postman',
		'wordpress/'         => 'WordPress (site)',
	);

	/**
	 * Genel bot kalıpları (bilinen listede olmayanlar için).
	 */
	private static $generic_bot_patterns = array(
		'bot', 'crawl', 'spider', 'scrape', 'slurp', 'archiver', 'fetcher',
		'monitor', 'checker', 'validator', 'preview', 'feedparser', 'httpclient',
	);

	/**
	 * @param string $ua Ham User-Agent.
	 * @return array{device:string,browser:string,browser_version:string,os:string,is_bot:int,bot_name:string}
	 */
	public static function parse( $ua ) {
		$out = array(
			'device'          => 'desktop',
			'browser'         => 'Bilinmiyor',
			'browser_version' => '',
			'os'              => 'Bilinmiyor',
			'is_bot'          => 0,
			'bot_name'        => '',
		);

		$ua = trim( (string) $ua );

		if ( '' === $ua ) {
			$out['is_bot']   = 1;
			$out['bot_name'] = 'Boş User-Agent';
			$out['device']   = 'bot';
			return $out;
		}

		$lower = strtolower( $ua );

		// 1) Bot tespiti.
		$bot_name = self::detect_bot( $lower );
		if ( '' !== $bot_name ) {
			$out['is_bot']   = 1;
			$out['bot_name'] = $bot_name;
			$out['device']   = 'bot';
			$out['browser']  = $bot_name;
			$out['os']       = '—';
			return $out;
		}

		// 2) İşletim sistemi.
		$out['os'] = self::detect_os( $ua, $lower );

		// 3) Tarayıcı + sürüm.
		list( $out['browser'], $out['browser_version'] ) = self::detect_browser( $ua, $lower );

		// 4) Cihaz türü.
		$out['device'] = self::detect_device( $lower );

		return $out;
	}

	private static function detect_bot( $lower ) {
		foreach ( self::$known_bots as $needle => $name ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return $name;
			}
		}
		// Gerçek tarayıcılar "mozilla/" ile başlayıp bilinen motor izleri taşır;
		// genel kalıplar yine de her yerde aranır (ör. "MyCompany-Bot/2.1").
		foreach ( self::$generic_bot_patterns as $pattern ) {
			if ( false !== strpos( $lower, $pattern ) ) {
				return 'Bot (genel)';
			}
		}
		return '';
	}

	private static function detect_os( $ua, $lower ) {
		if ( preg_match( '/windows nt ([0-9\.]+)/', $lower, $m ) ) {
			$map = array(
				'10.0' => 'Windows 10/11',
				'6.3'  => 'Windows 8.1',
				'6.2'  => 'Windows 8',
				'6.1'  => 'Windows 7',
				'6.0'  => 'Windows Vista',
				'5.1'  => 'Windows XP',
			);
			return isset( $map[ $m[1] ] ) ? $map[ $m[1] ] : 'Windows';
		}
		if ( false !== strpos( $lower, 'windows phone' ) ) {
			return 'Windows Phone';
		}
		if ( false !== strpos( $lower, 'cros' ) ) {
			return 'ChromeOS';
		}
		if ( preg_match( '/android[\s\/]?([0-9\.]*)/', $lower, $m ) ) {
			return 'Android' . ( '' !== $m[1] ? ' ' . self::major( $m[1] ) : '' );
		}
		if ( preg_match( '/(iphone|ipad|ipod).*?os ([0-9_]+)/', $lower, $m ) ) {
			return ( 'ipad' === $m[1] ? 'iPadOS ' : 'iOS ' ) . self::major( str_replace( '_', '.', $m[2] ) );
		}
		if ( false !== strpos( $lower, 'iphone' ) || false !== strpos( $lower, 'ipad' ) ) {
			return 'iOS';
		}
		if ( preg_match( '/mac os x ([0-9_\.]+)/', $lower, $m ) ) {
			return 'macOS';
		}
		if ( false !== strpos( $lower, 'macintosh' ) ) {
			return 'macOS';
		}
		if ( false !== strpos( $lower, 'ubuntu' ) ) {
			return 'Ubuntu';
		}
		if ( false !== strpos( $lower, 'fedora' ) ) {
			return 'Fedora';
		}
		if ( false !== strpos( $lower, 'linux' ) ) {
			return 'Linux';
		}
		if ( false !== strpos( $lower, 'freebsd' ) ) {
			return 'FreeBSD';
		}
		return 'Bilinmiyor';
	}

	private static function detect_browser( $ua, $lower ) {
		// Sıra önemli: türev tarayıcılar Chrome/Safari imzası da taşır.
		$rules = array(
			array( 'Edge',             '/edg(?:a|ios)?\//i',              '/edg(?:a|ios)?\/([0-9\.]+)/i' ),
			array( 'Opera',            '/opr\/|opera/i',                  '/(?:opr|version)\/([0-9\.]+)/i' ),
			array( 'Samsung Internet', '/samsungbrowser/i',               '/samsungbrowser\/([0-9\.]+)/i' ),
			array( 'Yandex Browser',   '/yabrowser/i',                    '/yabrowser\/([0-9\.]+)/i' ),
			array( 'Brave',            '/brave/i',                        '/(?:brave|chrome)\/([0-9\.]+)/i' ),
			array( 'Vivaldi',          '/vivaldi/i',                      '/vivaldi\/([0-9\.]+)/i' ),
			array( 'UC Browser',       '/ucbrowser/i',                    '/ucbrowser\/([0-9\.]+)/i' ),
			array( 'Firefox',          '/firefox|fxios/i',                '/(?:firefox|fxios)\/([0-9\.]+)/i' ),
			array( 'Chrome',           '/chrome|crios/i',                 '/(?:chrome|crios)\/([0-9\.]+)/i' ),
			array( 'Safari',           '/safari/i',                       '/version\/([0-9\.]+)/i' ),
			array( 'Internet Explorer', '/msie|trident/i',                '/(?:msie |rv:)([0-9\.]+)/i' ),
		);

		foreach ( $rules as $rule ) {
			list( $name, $test, $version_pattern ) = $rule;
			if ( preg_match( $test, $ua ) ) {
				$version = '';
				if ( preg_match( $version_pattern, $ua, $m ) && isset( $m[1] ) ) {
					$version = self::major( $m[1] );
				}
				return array( $name, $version );
			}
		}

		return array( 'Diğer', '' );
	}

	private static function detect_device( $lower ) {
		if ( false !== strpos( $lower, 'ipad' ) ) {
			return 'tablet';
		}
		if ( preg_match( '/tablet|kindle|silk|playbook|(?:android(?!.*mobile))/', $lower ) ) {
			return 'tablet';
		}
		if ( preg_match( '/mobile|iphone|ipod|android|blackberry|opera mini|windows phone/', $lower ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * "138.0.7204.97" -> "138" (panelde sadeleştirilmiş sürüm gösterilir).
	 */
	private static function major( $version ) {
		$parts = explode( '.', (string) $version );
		return isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : '';
	}
}
