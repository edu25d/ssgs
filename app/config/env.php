<?php
/**
 * config/env.php
 * 전역 환경 변수 로더 및 경로 상수 정의 (Single Source of Truth)
 *
 * app/ 폴더 분리 구조: 이 파일은 app/config/에 위치한다.
 * APP_ROOT = app/ (dirname(__DIR__) = app/config/의 부모 = app/)
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// .env 파일 1회 안전 파싱 및 환경 변수 등록
if (!defined('ENV_LOADED')) {
    define('ENV_LOADED', true);

    // .env는 app/ 루트에 위치 (서버 업로드 제외 대상)
    $envPath = APP_ROOT . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");

                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }
}

/**
 * 환경 변수 획득 헬퍼 함수
 */
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}
