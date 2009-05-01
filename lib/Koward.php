<?php
/**
 * Copyright 2009 The Horde Project (http://www.horde.org/)
 *
 * @author   Gunnar Wrobel <p@rdus.de>
 * @license  http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html
 * @category Kolab
 * @package  Koward
 */

class Koward {

    const PERM_GET = 1;

    /**
     * The singleton instance.
     *
     * @var Koward
     */
    static protected $instance = null;

    static protected $server = null;

    static protected $map_class_type = null;

    public $objectconf;

    public function __construct()
    {
        global $registry, $notification, $browser, $conf;

        $this->registry     = &$registry;
        $this->notification = &$notification;

        $this->auth = &Auth::singleton($conf['auth']['driver']);

        $this->conf       = Horde::loadConfiguration('conf.php', 'conf');
        $this->objects    = Horde::loadConfiguration('objects.php', 'objects');
        $this->attributes = Horde::loadConfiguration('attributes.php', 'attributes');
        $this->labels     = Horde::loadConfiguration('labels.php', 'labels');
        $this->perms      = Horde::loadConfiguration('perms.php', 'perms');
        $this->order      = Horde::loadConfiguration('order.php', 'order');
        $this->visible    = Horde::loadConfiguration('visible.php', 'visible');
        $this->search     = Horde::loadConfiguration('search.php', 'search');
    }

    public static function dispatch($koward)
    {
        global $registry, $notification, $browser;

        /* Horde core classes that aren't autoloaded. */
        include_once 'Horde/Util.php';
        include_once 'Horde/String.php';
        include_once 'Horde/NLS.php';
        include_once 'Horde/Auth.php';
        include_once 'Horde/Perms.php';
        include_once 'Horde/Notification.php';
        include_once 'Horde/Registry.php';

        $notification = Notification::singleton();
        $notification->attach('status');

        $registry     = Registry::singleton();

        /* Browser detection object. */
        if (class_exists('Horde_Browser')) {
            $browser = Horde_Browser::singleton();
        }

        $webroot = Koward::_detectWebroot($koward);

        // Set up our request and routing objects
        $request = new Horde_Controller_Request_Http();
        $mapper = new Horde_Routes_Mapper();

        // Application routes are relative only to the application. Let the mapper know
        // where they start.
        $mapper->prefix = $webroot;

        // Check for route definitions.
        $routeFile = dirname($koward) . '/../../koward/config/routes.php';
        if (!file_exists($routeFile)) {
            throw new Horde_Controller_Exception('Not routable');
        }

        // Load application routes.
        include $routeFile;

        $context = array(
            'mapper' => $mapper,
            'controllerDir' => dirname(__FILE__) . '/Koward/Controller',
            'viewsDir' => dirname(__FILE__) . '/Koward/View',
            // 'logger' => '',
        );

        $dispatcher = Horde_Controller_Dispatcher::singleton($context);
        $dispatcher->dispatch($request);
    }

    private static function _detectWebroot($origin)
    {
        // Note for Windows users: the below assumes that your PHP_SELF variable
        // uses forward slashes. If it does not, you'll have to tweak this.
        if (isset($_SERVER['SCRIPT_URL']) || isset($_SERVER['SCRIPT_NAME'])) {
            $path = empty($_SERVER['SCRIPT_URL']) ?
                $_SERVER['SCRIPT_NAME'] :
                $_SERVER['SCRIPT_URL'];
            $appdir = str_replace(DIRECTORY_SEPARATOR, '/', $origin);
            $appdir = basename(preg_replace(';/koward.php$;', '', $appdir));
            if (preg_match(';/' . $appdir . ';', $path)) {
                $webroot = preg_replace(';/' . $appdir . '.*;', '/' . $appdir, $path);
            } else {
                $webroot = '';
            }
        } elseif (isset($_SERVER['PHP_SELF'])) {
            $webroot = preg_split(';/;', $_SERVER['PHP_SELF'], 2, PREG_SPLIT_NO_EMPTY);
            $webroot = strstr(dirname($origin), DIRECTORY_SEPARATOR . array_shift($webroot));
            if ($webroot !== false) {
                $webroot = preg_replace(array('/\\\\/', ';/config$;'), array('/', ''), $webroot);
            } elseif ($webroot === false) {
                $webroot = '';
            } else {
                $webroot = '/';
            }
        } else {
            $webroot = '/';
        }

        return $webroot;
    }

    public function getServer()
    {
        if (!isset(self::$server)) {
            self::$server = Horde_Kolab_Server::singleton(array('user' => Auth::getAuth(),
                                                                'pass' => Auth::getCredential('password')));
        }

        return self::$server;
    }

    /**
     * Get a token for protecting a form.
     *
     * @param string $seed  TODO
     *
     * @return  TODO
     */
    static public function getRequestToken($seed)
    {
        $token = Horde_Token::generateId($seed);
        $_SESSION['horde_form_secrets'][$token] = time();
        return $token;
    }

    /**
     * Check if a token for a form is valid.
     *
     * @param string $seed   TODO
     * @param string $token  TODO
     *
     * @throws Horde_Exception
     */
    static public function checkRequestToken($seed, $token)
    {
        if (empty($_SESSION['horde_form_secrets'][$token])) {
            throw new Horde_Exception(_("We cannot verify that this request was really sent by you. It could be a malicious request. If you intended to perform this action, you can retry it now."));
        }

        if ($_SESSION['horde_form_secrets'][$token] + $GLOBALS['conf']['server']['token_lifetime'] < time()) {
            throw new Horde_Exception(sprintf(_("This request cannot be completed because the link you followed or the form you submitted was only valid for %d minutes. Please try again now."), round($GLOBALS['conf']['server']['token_lifetime'] / 60)));
        }
    }

    public function getObject($uid)
    {
        return $this->getServer()->fetch($uid);
    }


    public function getType($mixed = null)
    {
        if ($mixed instanceOf Horde_Kolab_Server_Object) {
            $class_name = get_class($mixed);
        } else if (!empty($mixed)) {
            $class_name = $mixed;
        } else {
            $session = Horde_Kolab_Session::singleton();
            $object = $this->getObject($session->user_uid);
            $class_name = get_class($object);
        }

        if (empty(self::$map_class_type)) {
            foreach ($this->objects as $name => $config) {
                self::$map_class_type[$config['class']]['types'][] = $name;
                if (!empty($config['preferred'])) {
                    self::$map_class_type['preferred'] = $name;
                }
            }
        }

        if (isset(self::$map_class_type[$class_name]['types'])) {
            return self::$map_class_type[$class_name]['types'][0];
        } else {
            return self::$map_class_type['preferred'];
        }
    }

    /**
     * In the long run we might wish to use the Horde permission system
     * here. But for the first draft this would be too much as the permission
     * system would also require integration with the group system etc.
     */
    public function hasPermission($id, $user = null, $perm = null)
    {
        $global = $this->_hasPermission($this->perms,
                                        $id, $perm);

        if ($user === null) {
            $session = Horde_Kolab_Session::singleton();
            if (!empty($session->user_uid)) {
                $user = $this->getObject($session->user_uid);
            }
        }

        if (empty($user)) {
            return $global;
        }

        if (isset($this->objects[$type]['permission'])) {
            $object = $this->_hasPermission($this->objects[$type]['permission'],
                                            $id, $perm);
        } else {
            return $global;
        }

        return $objects || $global;
    }

    private function _hasPermission(&$root, $id, $perm)
    {
        if (empty($root)) {
            return false;
        }
        if (is_int($root)) {
            return $perm & $root;
        }
        if (is_array($root)) {
            list($sub, $path) = explode('/', $id, 2);
            if (!isset($root[$sub])) {
                return false;
            }
            return $this->_hasPermission($root[$sub], $path, $perm);
        }
    }

    static public function singleton()
    {
        if (!isset(self::$instance)) {
            self::$instance = new Koward();
        }

        return self::$instance;
    }
}