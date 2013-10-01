<?php

namespace Indieweb\IndiewebifyMe;

ob_start();
require __DIR__ . '/../vendor/autoload.php';
ob_end_clean();

use BarnabyWalters\Mf2;
use Guzzle;
use mf2\Parser as MfParser;
use Silex;
use Symfony\Component\HttpFoundation as Http;

function renderTemplate($template, array $__templateData = array()) {
	$render = function ($__path, $render=null) use ($__templateData) {
		ob_start();
		extract($__templateData);
		unset($__templateData);
		include __DIR__ . '/../templates/' . $__path . '.php';
		return ob_get_clean();
	};
	
	return $render($template, $render);
}

function render($template, array $data = array()) {
	$isHtml = pathinfo($template, PATHINFO_EXTENSION) === 'html';
	$out = '';
	
	if ($isHtml)
		$out .= renderTemplate('header.html', $data);
	$out .= renderTemplate($template, $data);
	if ($isHtml)
		$out .= renderTemplate('footer.html', $data);
	return $out;
}

function httpGet($url) {
	$client = new Guzzle\Http\Client();
	ob_start();
	$url = web_address_to_uri($url, true);
	ob_end_clean();
	
	try {
		$response = $client->get($url)->send();
		return array($response, null);
	} catch (Guzzle\Common\Exception\GuzzleException $e) {
		return array(null, $e);
	}
}

function parseMf($resp, $url) {
	$parser = new MfParser((string) $resp, $url);
	return $parser->parse();
}

function fetchMf($url) {
	list($resp, $err) = httpGet($url);
	if ($err)
		return array(null, $err);
	
	return array(parseMf($resp, $url), null);
}

function errorResponder($template, $url) {
	return function ($message) use ($template, $url) {
		return render($template, array(
			'error' => array('message' => $message),
			'url' => htmlspecialchars($url)
		));
	};
}

function finalUrl($url) {
	$client = new Guzzle\Http\Client();
	try {
		return array($client->head($url)->send()->getEffectiveUrl(), null);
	} catch (Guzzle\Common\Exception\GuzzleException $e) {
		return array(null, $e);
	}
}

function relMeLinks($to, array $fromRelMes) {
	foreach ($fromRelMes as $me) {
		if ($to === $me)
			return true;
	}
	
	foreach ($fromRelMes as $me) {
		list($meFinal, $err) = finalUrl($me);
		if ($err)
			continue;
		if ($to === $meFinal)
			return true;
	}
	
	return false;
}

// Web server setup

// Route static assets from CLI server
if (PHP_SAPI === 'cli-server') {
	error_reporting(0);
	
	if (file_exists(__DIR__ . $_SERVER['REQUEST_URI']) and !is_dir(__DIR__ . $_SERVER['REQUEST_URI'])) {
		return false;
	}
}

$app = new Silex\Application();

$app->get('/', function () {
	return render('index.html');
});

$app->get('/validate-rel-me/', function (Http\Request $request) {
	if (!$request->query->has('url')) {
		return render('validate-rel-me.html');
	} else {
		ob_start();
		$url = web_address_to_uri($request->query->get('url'), true);
		ob_end_clean();
		
		$errorResponse = errorResponder('validate-rel-me.html', $url);
		
		if (empty($url))
			return $errorResponse('Empty URLs lead nowhere');
		
		list($mfs, $err) = fetchMf($url);
		
		if ($err)
			return $errorResponse(htmlspecialchars($err->getMessage()));
		
		return render('validate-rel-me.html', array(
			'rels' => $mfs['rels']['me'],
			'url' => htmlspecialchars($url)
		));
	}
});

// TODO: determine whether or not effective (redirected) URL resolution is correct
// Look at indieauth code, relmeauth spec to decide
$app->get('/rel-me-links/', function (Http\Request $request) {
	if (!$request->query->has('url1') or !$request->query->has('url2'))
		return Http\Response::create('Provide both url1 and url2 parameters', 400);
	
	ob_start();
	$u1 = web_address_to_uri($request->query->get('url1'), true);
	$u2 = web_address_to_uri($request->query->get('url2'), true); // lol U2
	ob_end_clean();
	
	list($u1Resp, $err) = httpGet($u1);
	if ($err)
		return Http\Response::create("Couldn’t fetch {$u1}", 400);
	$u1Final = $u1Resp->getEffectiveUrl();
	$u1Mf = parseMf($u1Resp, $u1);
	$u1RelMe = @($u1Mf['rels']['me'] ?: array());
	
	list($u2Resp, $err) = httpGet($u2);
	if ($err)
		return Http\Response::create("Couldn’t fetch {$u2}", 400);
	$u2Final = $u2Resp->getEffectiveUrl();
	$u2Mf = parseMf($u2Resp, $u2);
	$u2RelMe = @($u2Mf['rels']['me'] ?: array());
	
	$link12 = relMeLinks($u2Final, $u1RelMe);
	$link21 = relMeLinks($u1Final, $u2RelMe);
	
	if ($link12 and $link21):
		return 'true';
	else:
		return 'false';
	endif;
});

$app->get('/validate-h-card/', function (Http\Request $request) {
	if (!$request->query->has('url')) {
		return render('validate-h-card.html');
	} else {
		$url = trim($request->query->get('url'));
		
		$errorResponse = errorResponder('validate-h-card.html', $url);
		
		if (empty($url))
			return $errorResponse('Empty URLs lead nowhere');
		
		list($mfs, $err) = fetchMf($url);
		
		if ($err)
			return $errorResponse(htmlspecialchars($err->getMessage()));
		
		$hCards = Mf2\findMicroformatsByType($mfs, 'h-card');
		
		if (count($hCards) === 0)
			return $errorResponse('No h-cards found — check your classnames');
		
		return render('validate-h-card.html', array(
			'hCard' => $hCards[0],
			'url' => htmlspecialchars($url)
		));
	}
});

$app->get('/validate-h-entry/', function (Http\Request $request) {
	if (!$request->query->has('url')) {
		return render('validate-h-entry.html');
	} else {
		ob_start();
		$url = web_address_to_uri($request->query->get('url'), true);
		ob_end_clean();
		$errorResponse = errorResponder('validate-h-entry.html', $url);
		
		if (empty($url))
			return $errorResponse('Empty URLs lead nowhere');
		
		list($mfs, $err) = fetchMf($url);
		
		if ($err)
			return $errorResponse(htmlspecialchars($err->getMessage()));
		
		$hEntries = Mf2\findMicroformatsByType($mfs, 'h-entry');
		
		if (count($hEntries) === 0)
			return $errorResponse('No h-entries found — check your classnames');
		print_r($mfs);
		return render('validate-h-entry.html', array(
			'hEntry' => $hEntries[0],
			'url' => htmlspecialchars($url)
		));
	}
});

$app->run();
