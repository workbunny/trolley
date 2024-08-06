<?php declare(strict_types=1);
/**
 * @author workbunny/Chaz6chez
 * @email chaz6chez1993@outlook.com
 */

/**
 * @param string|null $key 可以使用.获取层级数据
 * @param mixed|null $default
 * @param string|null $path
 * @param bool $static
 * @return mixed|null
 */
function yaml(?string $key = null, mixed $default = null, ?string $path = null, bool $static = false): mixed
{
    if (!file_exists(($path = $path ?? base_path() . '/config.yaml'))) {
        return $default;
    }
    if ($static) {
        global $yaml;
        if (!$yaml) {
            $yaml = \Symfony\Component\Yaml\Yaml::parseFile($path);
        }
        $data = $yaml;
    } else {
        $data = \Symfony\Component\Yaml\Yaml::parseFile($path);
    }
    $keys = \explode('.', $key);
    if($key !== null){
        foreach ($keys as $k){
            if(!is_array($data)){
                $data = $default;
                break;
            }
            $data = $data[$k] ?? $default;
        }
    }
    return $data;
}

/**
 * @return void
 */
function yaml_clear(): void
{
    global $yaml;
    $yaml = null;
}

/**
 * @param array $data
 * @param string|null $path
 * @return false|int|string
 */
function array2yaml(array $data, ?string $path = null): false|int|string
{
    $yaml = \Symfony\Component\Yaml\Yaml::dump($data);
    if($path){
        return file_put_contents(base_path() . $path, $yaml, LOCK_EX);
    }
    return $yaml;
}

/**
 * @return int
 */
function ms_time(): int
{
    return intval(microtime(true) * 1000);
}

/**
 * @return string
 */
function local_ip(): string
{
    return trim(shell_exec('curl -s ifconfig.me'));
}