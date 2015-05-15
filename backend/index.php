<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Innometrics\Helper;

require_once('vendor/autoload.php');

// Base application
$app = new Silex\Application();
$app['debug'] = !!getenv('DEBUG');

// Innometrics helper
$inno = new Helper();
$inno->setVars(array(
    'bucketName'    => getenv('INNO_BUCKET_ID'),
    'appKey'        => getenv('INNO_APP_KEY'),
    'appName'       => getenv('INNO_APP_ID'),
    'groupId'       => getenv('INNO_COMPANY_ID'),
    'apiUrl'        => getenv('INNO_API_HOST'),
    'collectApp'    => getenv('INNO_APP_ID')
));

// Handle "/"
$app->get('/', function() use ($app) {
    if ($app['debug']) {
        error_log('ROUTE LOG: open "/"');
    }
    return 'App is working.';
});

// Handle "/langs"
$app->get('/langs', function() use ($app, $inno) {
    $settings = array(
        'API_KEY' => null,
        'TO_LANG' => 'en'
    );

    // retrieve app settings from DH
    try {
        $settings = array_merge($settings, $inno->getSettings());
    } catch (\ErrorException $e) {
        $message = $e->getMessage();
        error_log($message);
        return $app->json(array(
            'error' => $message
        ));
    }
    
    $res = getLangs($settings);
    if ($res['success']) {
        //echo var_dump('"' . implode('","',array_values($res['content']['langs'])) . '"');die;
        return new Response(
            implode("\n", $res['content']['dirs']),
            200,
            ['Content-Type' => 'application/json']
        );
    } else {
        return $app->json(array(
            'error' => $res['content']
        ));
    }
});

// Handle Profile Stream from Innometrics DH
$app->post('/', function(Request $request) use($app, $inno) {
    if ($app['debug']) {
        error_log('ROUTE LOG: open "/" - Profile Stream handler');
    }

    // Extract data from Profile stream
    try {
        $data = $inno->getStreamData($request->getContent());
    } catch (\ErrorException $error) {
        $message = $error->getMessage();
        error_log($message);
        return $app->json(array(
            'error' => $message
        ));
    }

    $settings = array(
        'API_KEY' => null,
        'TO_LANG' => 'en',
        'EVENT_DATA_NAME' => null,
        'PROFILE_ATTR_NAME' => null
    );

    // retrieve app settings from DH
    try {
        $settings = array_merge($settings, $inno->getSettings());
    } catch (\ErrorException $error) {
        $message = $error->getMessage();
        error_log($message);
        return $app->json(array(
            'error' => $message
        ));
    }

    $profile = $data['profile']['id'];
    $inno->setVar('profileId', $profile);

    $section = $data['session']['section'];
    $inno->setVar('section', $section);

    // Try to get phone number from event data
    $eventData = $data['data'];
    $eventDataName = $settings['EVENT_DATA_NAME'];
    $text = isset($eventData[$eventDataName]) ? $eventData[$eventDataName] : null;
    if (empty($text)) {
        $error = new \ErrorException('Event in Profile %s has no text for translation in data "%s"', $profile, $eventDataName);
        error_log($error->getMessage());
        return $app->json(array(
            'error' => $error->getMessage()
        ));
    }

    // Translate
    $res = translate($text, $settings);
    if ($res['success']) {
        $translatedText = implode(',', $res['content']['text']);
        
        // retrieve profile attributes from DH
        try {
            $attributes = $inno->getAttributes();
        } catch (\ErrorException $error) {
            $message = $error->getMessage();
            error_log($message);
            return $app->json(array(
                'error' => $message
            ));
        }
        
        // Save translated text to profile attribute
        $attributeName = $settings['PROFILE_ATTR_NAME'];
        $attributes[$attributeName] = $translatedText;
        
        // Set attributes
        try {
            $attributes = $inno->setAttributes($attributes);
        } catch (\ErrorException $error) {
            $message = $error->getMessage();
            error_log($message);
            return $app->json(array(
                'error' => $message
            ));
        }
        
        return new Response(
            $translatedText,
            200,
            ['Content-Type' => 'text/plain']
        );
    } else {
        return $app->json(array(
            'error' => $res['content']
        ));
    }
});

$app->run();

//
// Helpers functions
//

function sendRequest ($method, $url) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_CUSTOMREQUEST   => $method,
        CURLOPT_URL             => $url,
        CURLOPT_TIMEOUT         => 5,
        CURLOPT_RETURNTRANSFER  => 1,
        CURLOPT_FOLLOWLOCATION  => 1,
        CURLINFO_HEADER_OUT     => 1
    ));

    $response = curl_exec($curl);
    
    if ($response === false) {
        $error = curl_error($curl) ? curl_error($curl) : 'Unknown error';
        return array(
            'success' => false,
            'content' => $error
        );
    } else {
        $data = json_decode($response, true);
        
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode === 200) {
            return array(
                'success' => true,
                'content' => $data
            );
        } else {
            return array(
                'success' => false,
                'content' => isset($data['message']) ? $data['message'] : 'Unknown error'
            );
        }
    }    
}

function getBaseUrl () {
    return 'https://translate.yandex.net/api/v1.5/tr.json';
}

function getLangs ($settings) {
    $url = getBaseUrl() . '/getLangs' . '?key=' . $settings['API_KEY'] . '&ui=en';
    return sendRequest('GET', $url, $settings);
}

function translate ($text, $settings) {
    $url = getBaseUrl() . '/translate' . '?key=' . $settings['API_KEY'] . '&text=' . urlencode($text) . '&lang=' . $settings['TO_LANG'];
    return sendRequest('POST', $url, $settings);
}