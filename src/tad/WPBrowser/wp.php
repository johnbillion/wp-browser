<?php
/**
 * Functions dedicated to WordPress interaction and inspection.
 *
 * @package tad\WPBrowser;
 */

namespace tad\WPBrowser;

/**
 * Finds the WordPress root directory in a parent or child directory.
 *
 * @param string|null $startDir The path to the directory to check, or `null` to use the current working directory.
 * @param string|null $default The default value to return, or `null` to use the current working directory.
 *
 * @return string The path to the WordPress root folder, if found, or the default value.
 */
function findWordPressRootDir($startDir = null, $default = null)
{
    $getcwd = getcwd();

    if ($getcwd === false) {
        return $default;
    }

    $startDir = $startDir ?: (string)$getcwd;
    $default = $default ?: (string)$getcwd;

    $dir = $startDir;

    $isWpRoot = static function ($dir) {
        return file_exists($dir . '/wp-load.php');
    };

    $match = findParentDirThat($dir, $isWpRoot);

    if (!$match) {
        $match = findChildDirThat($dir, $isWpRoot);
    }

    return $match ?: $default;
}

/**
 * Returns an array of the variables and constants defined in the `wp-config.php` file.
 *
 * @param string $wpRootDir The path to the WordPress root directory.
 *
 * @return array An array of the variables and constants defined in the WordPress `wp-config.php` file; the array
 *                     has shape `['vars' => <...vars>, 'constants' => <...constants>]`, an empty array on failure.
 *
 * @throws \ReflectionException If the the `\WP_CLI\Runner` class is not found.
 */
function getWpConfigArgs($wpRootDir)
{
    $wpConfigFile = pathJoin($wpRootDir, 'wp-config.php');

    if (! is_readable($wpConfigFile)) {
        return [];
    }

    $wpConfigCode = file_get_contents($wpConfigFile);

    if ($wpConfigCode === false) {
        return [];
    }

    // Remove the leading `<?php` tag.
    $wpConfigCode = preg_replace('/^<\\?php.*$/um', '', $wpConfigCode);
    // Do not load the `wp-settings.php` file.
    $wpConfigCode = preg_replace('/^.*wp-settings\\.php.*$/um', '', $wpConfigCode);

    /*
     * To "correctly", as much as possible, pick up the values, set the context of the request to a test one.
     */
    $code = [
        '<?php',
        "putenv('WPBROWSER_HOST_REQUEST=1');",
        "putenv('TEST_REQUEST=1');",
        '$_SERVER["HTTP_X_TEST_REQUEST"] = true;',
        '$_SERVER["HTTP_X_WPBROWSER_REQUEST"] = true;',
        '$_constantsBefore = get_defined_constants();',
        '$_varsBefore = get_defined_vars();',
        $wpConfigCode,
        '$_constants = array_diff_key((array)get_defined_constants(), (array)$_constantsBefore);',
        '$_vars = array_diff_key((array)get_defined_vars(), (array)$_varsBefore);',
        'unset($_vars["_constantsBefore"],$_vars["_varsBefore"], $_vars["_constants"], $_vars["_vars"]);',
        'exit(json_encode(["constants" => $_constants,"vars" => $_vars], JSON_PRETTY_PRINT));'
    ] ;

    $phpProcess = new Symfony\Component\Process\PhpProcess(
        implode(PHP_EOL, $code),
        dirname($wpConfigFile)
    );
    $phpProcess->run();
    $output = $phpProcess->getOutput();

    if ($phpProcess->getExitCode() !== 0 || empty($output)) {
        return [];
    }

    $decoded = json_decode($output, true);

    return false === $decoded ? [] : $decoded;
}

/**
 * Parses the database connection coordinates from the WordPress configuration file, `wp-config.php`.
 *
 * @param string $wpRootDir The path to the WordPress root directory, the one containing the `wp-load.php` file.
 *
 * @return array The database connection coordinates ('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD') or an empty array.
 */
function findWpDbCoords($wpRootDir)
{
    try {
        $wpConfigArgs = getWpConfigArgs($wpRootDir);
    } catch (\ReflectionException $e) {
        return [];
    }

    $expectedDbCoordsConstants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    $dbCoords                  = [];
    foreach ($expectedDbCoordsConstants as $const) {
        if (! isset($wpConfigArgs['constants'][$const])) {
            continue;
        }

        $dbCoords[$const] = $wpConfigArgs['constants'][$const];
    }

    if (isset($wpConfigArgs['vars']['table_prefix'])) {
        $dbCoords['table_prefix'] = $wpConfigArgs['vars']['table_prefix'];
    }

    return count($dbCoords) === count($expectedDbCoordsConstants) + 1 ? $dbCoords : [];
}

/**
 * Builds database connection coordinates from coordinates in the constant-based format used by WP.
 *
 * @param array $dbCoords The WP db connection coordinates.
 *
 * @return array The db coordinates in the shape [$dsn, $user, $passwd].
 */
function buildDbCoordsFromWpCoords(array $dbCoords){
    $dbName = isset($dbCoords['DB_NAME']) ? $dbCoords['DB_NAME'] : 'wordpress';
    $dbHost = isset($dbCoords['DB_HOST']) ? $dbCoords['DB_HOST'] : 'localhost';
    list($dbHost, $dbPort) = strpos($dbHost, ':') > 1
        ? explode(':', $dbHost)
        : [$dbHost, '3306'];
    $dbUser = isset($dbCoords['DB_USER']) ? $dbCoords['DB_USER'] : 'root';
    $dbPass = isset($dbCoords['DB_PASSWORD']) ? $dbCoords['DB_PASSWORD'] : '';

    $dsn = sprintf( 'mysql:dbname=%s;host=%s;port=%d', $dbName,$dbHost,$dbPort);

    return [$dsn, $dbUser, $dbPass];
}
