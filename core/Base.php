<?php
/**
 * Created by PhpStorm.
 * User: qq475
 * Date: 2018/11/11
 * Time: 21:48
 */

require_once 'Loader.php';

defined('ROOT') or define('ROOT', dirname(dirname(__file__)) . '/');
defined('RUNTIME') or define('RUNTIME', ROOT . 'runtime/');
defined('CORE') or define('CORE', ROOT . 'core/');
defined('APP') or define('APP', ROOT . 'app');
defined('CONFIG_EXT') or define('CONFIG_EXT', '.php');
defined('APP_BASE_NAME') or define('APP_BASE_NAME', 'app');//app根命名空间
defined('EXTEND_PATH') or define('EXTEND_PATH', ROOT . 'extend');//第三方扩展目录

\im\core\Loader::Register();