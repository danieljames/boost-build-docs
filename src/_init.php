<?php

use BoostTasks\Log;

// Path constants

define('BOOST_TASKS_ROOT', dirname(__DIR__));

// Set up autoloading, if not already done

require_once __DIR__.'/../vendor/autoload.php';

// Set timezone to UTC, php sometimes complains if timezone isn't set, and
// it saves me from having to think about the server's timezone.

date_default_timezone_set('UTC');

// Die on all errors.
function myErrorHandler($message) {
    if (array_key_exists('SERVER_PROTOCOL', $_SERVER)) {
        @header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    }

    if (Log::$log) {
        Log::error($message);
    }
    else if (array_key_exists('SERVER_PROTOCOL', $_SERVER)) {
        // TODO: Don't html encode message if writing text.
        echo htmlentities($message),"\n";
    }
    else if (defined('STDERR')) {
        fputs(STDERR, "{$message}\n");
    }
    else {
        echo("{$message}\n");
    }
    exit(1);
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() & $errno) {
        myErrorHandler("{$errfile}:{$errline}: {$errstr}");
    }
});

set_exception_handler(function($e) {
    myErrorHandler($e);
});

register_shutdown_function(function() {
    $last_error = error_get_last();
    if ($last_error && $last_error['type'] & (E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR)) {
        if (array_key_exists('SERVER_PROTOCOL', $_SERVER)) {
           header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
        }
    }
});

// Utility functions

function array_get($array, $key, $default= null) {
    return array_key_exists($key, $array) ? $array[$key] : $default;
}
