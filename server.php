<?php declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require __DIR__ . '/vendor/autoload.php';
$loader = new FilesystemLoader(__DIR__ . '/src/templates');
$twig = new Environment($loader);


/**
 * Load the Valet configuration.
 */
$valetConfig = json_decode(
    file_get_contents(VALET_HOME_PATH . '/config.json'),
    true
);

/**
 * Parse the URI and site / host for the incoming request.
 */
$uri = urldecode(
    explode("?", $_SERVER['REQUEST_URI'])[0]
);

$siteName = basename(
// Filter host to support xip.io feature
    $_SERVER['HTTP_HOST'],
    '.' . $valetConfig['domain']
);

if (strpos($siteName, 'www.') === 0) {
    $siteName = substr($siteName, 4);
}

/**
 * Determine a possible rewrite.
 */
if (isset($valetConfig['rewrites'])) {
    foreach ($valetConfig['rewrites'] as $site => $rewrites) {
        foreach ($rewrites as $rewrite) {
            if ($rewrite == $siteName) {
                $siteName = $site;
                break;
            }
        }
    }
}

/**
 * Determine the fully qualified path to the site.
 */
$valetSitePath = apcu_fetch('valet_site_path' . $siteName);
$domain = array_slice(explode('.', $siteName), -1)[0];


if (!$valetSitePath) {
    $siteCount = 0;
    $valetPaths = count($valetConfig['paths']);
    foreach ($valetConfig['paths'] as $path) {
        if (is_dir($path . '/' . $siteName)) {
            $valetSitePath = $path . '/' . $siteName;
            break;
        }

        if (is_dir($path . '/' . $domain)) {
            $valetSitePath = $path . '/' . $domain;
            break;
        }

        $siteCount += count(glob(htmlspecialchars($path) . '/*', GLOB_ONLYDIR));
    }

    if (!$valetSitePath) {
        http_response_code(404);

        // 404 variables
        $valetCustomConfig = $valetConfig;

        unset($valetCustomConfig['domain']);
        unset($valetCustomConfig['paths']);


        $certificatePath = htmlspecialchars(VALET_HOME_PATH . '/Certificates/');
        $certKey = '.crt';
        $globalCertificates = glob($certificatePath . '*' . $certKey);
        $certificates = [];
        foreach ($globalCertificates as $certificate) {
            $key = str_replace($certificatePath, '', $certificate);
            $key = str_replace($certKey, '', $key);
            $certificates[$key] = $certificate;
        }

        $requestedSite = htmlspecialchars($siteName . '.' . $valetConfig['domain']);
        $requestedSiteName = htmlspecialchars($siteName);

        $paths = [];

        foreach ($valetConfig['paths'] as $path) {
            $sites = glob(htmlspecialchars($path) . '/*', GLOB_ONLYDIR);
            foreach ($sites as $site) {


                $domain = \sprintf('%s.%s', basename($site), $valetConfig['domain']);
                $enabled = array_key_exists($domain, $certificates);
                if ($enabled) {

                    $fullDomain = \sprintf('https://%s', $domain);
                } else {
                    $fullDomain = \sprintf('http://%s', $domain);
                }
                $paths[$fullDomain] = $enabled;
            }
        }

        echo $twig->render('404.html.twig', [
            'requestedSite' => $requestedSite,
            'valetConfig' => $valetConfig,
            'valetCustomConfig' => $valetCustomConfig,
            'siteCount' => $siteCount,
            'paths' => $paths,
            'valetPaths' => $valetPaths,
            'phpversion' => phpversion(),
            'mailhogDomain' => \sprintf('http://mailhog.%s', $valetConfig['domain']),
        ]);
        exit;
    }

    $valetSitePath = realpath($valetSitePath);

    apcu_add('valet_site_path' . $siteName, $valetSitePath, 3600);
}

/**
 * Find the appropriate Valet driver for the request.
 */
$valetDriver = null;

require __DIR__ . '/src/drivers/require.php';

$valetDriver = ValetDriver::assign($valetSitePath, $siteName, $uri);

if (!$valetDriver) {
    http_response_code(404);
    echo 'Could not find suitable driver for your project.';
    exit;
}

/**
 * ngrok uses the X-Original-Host to store the forwarded hostname.
 */
if (isset($_SERVER['HTTP_X_ORIGINAL_HOST']) && !isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_X_FORWARDED_HOST'] = $_SERVER['HTTP_X_ORIGINAL_HOST'];
}

/**
 * Allow driver to mutate incoming URL.
 */
$uri = $valetDriver->mutateUri($uri);

/**
 * Determine if the incoming request is for a static file.
 */
$isPhpFile = pathinfo($uri, PATHINFO_EXTENSION) === 'php';

if ($uri !== '/' && !$isPhpFile && $staticFilePath = $valetDriver->isStaticFile($valetSitePath, $siteName, $uri)) {
    return $valetDriver->serveStaticFile($staticFilePath, $valetSitePath, $siteName, $uri);
}

/**
 * Attempt to dispatch to a front controller.
 */
$frontControllerPath = $valetDriver->frontControllerPath(
    $valetSitePath,
    $siteName,
    $uri
);

if (!$frontControllerPath) {
    http_response_code(404);
    echo 'Did not get front controller from driver. Please return a front controller to be executed.';
    exit;
}

chdir(dirname($frontControllerPath));

unset($domain, $path, $siteName, $uri, $valetConfig, $valetDriver, $valetSitePath);

require $frontControllerPath;
