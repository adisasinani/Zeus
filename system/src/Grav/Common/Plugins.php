<?php

/**
 * @package    Grav\Common
 *
 * @copyright  Copyright (c) 2015 - 2021 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common;

use Exception;
use Grav\Common\Config\Config;
use Grav\Common\Data\Blueprints;
use Grav\Common\Data\Data;
use Grav\Common\File\CompiledYamlFile;
use Grav\Events\PluginsLoadedEvent;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use function get_class;
use function is_object;

/**
 * Class Plugins
 * @package Grav\Common
 */
class Plugins extends Iterator
{
    /** @var array */
    public $formFieldTypes;

    /** @var bool */
    private $plugins_initialized = false;

    /**
     * Plugins constructor.
     */
    public function __construct()
    {
        parent::__construct();

        /** @var UniformResourceLocator $locator */
        $locator = Grav::instance()['locator'];

        $iterator = $locator->getIterator('plugins://');

        $plugins = [];
        foreach ($iterator as $directory) {
            if (!$directory->isDir()) {
                continue;
            }
            $plugins[] = $directory->getFilename();
        }

        sort($plugins, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($plugins as $plugin) {
            $this->add($this->loadPlugin($plugin));
        }
    }

    /**
     * @return $this
     */
    public function setup()
    {
        $blueprints = [];
        $formFields = [];

        /** @var Plugin $plugin */
        foreach ($this->items as $plugin) {
            if (isset($plugin->features['blueprints'])) {
                $blueprints["plugin://{$plugin->name}/blueprints"] = $plugin->features['blueprints'];
            }
            if (method_exists($plugin, 'getFormFieldTypes')) {
                $formFields[get_class($plugin)] = isset($plugin->features['formfields']) ? $plugin->features['formfields'] : 0;
            }
        }

        if ($blueprints) {
            // Order by priority.
            arsort($blueprints, SORT_NUMERIC);

            /** @var UniformResourceLocator $locator */
            $locator = Grav::instance()['locator'];
            $locator->addPath('blueprints', '', array_keys($blueprints), ['system', 'blueprints']);
        }

        if ($formFields) {
            // Order by priority.
            arsort($formFields, SORT_NUMERIC);

            $list = [];
            foreach ($formFields as $className => $priority) {
                $plugin = $this->items[$className];
                $list += $plugin->getFormFieldTypes();
            }

            $this->formFieldTypes = $list;
        }

        return $this;
    }

    /**
     * Registers all plugins.
     *
     * @return Plugin[] array of Plugin objects
     * @throws RuntimeException
     */
    public function init()
    {
        if ($this->plugins_initialized)  {
            return $this->items;
        }

        $grav = Grav::instance();

        /** @var Config $config */
        $config = $grav['config'];

        /** @var EventDispatcher $events */
        $events = $grav['events'];

        foreach ($this->items as $instance) {
            // Register only enabled plugins.
            if ($config["plugins.{$instance->name}.enabled"] && $instance instanceof Plugin) {
                // Set plugin configuration.
                $instance->setConfig($config);
                // Register autoloader.
                if (method_exists($instance, 'autoload')) {
                    $instance->autoload();
                }
                // Register event listeners.
                $events->addSubscriber($instance);
            }
        }

        // Plugins Loaded Event
        $event = new PluginsLoadedEvent($grav, $this);
        $grav->dispatchEvent($event);

        $this->plugins_initialized = true;

        return $this->items;
    }

    /**
     * Add a plugin
     *
     * @param Plugin $plugin
     */
    public function add($plugin)
    {
        if (is_object($plugin)) {
            $this->items[get_class($plugin)] = $plugin;
        }
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        $array = (array)$this;

        unset($array["\0Grav\Common\Iterator\0iteratorUnset"]);

        return $array;
    }

    /**
     * Return list of all plugin data with their blueprints.
     *
     * @return array<string,Data>
     */
    public static function all()
    {
        $grav = Grav::instance();
        $plugins = $grav['plugins'];
        $list = [];

        foreach ($plugins as $instance) {
            $name = $instance->name;

            try {
                $result = self::get($name);
            } catch (Exception $e) {
                $exception = new RuntimeException(sprintf('Plugin %s: %s', $name, $e->getMessage()), $e->getCode(), $e);

                /** @var Debugger $debugger */
                $debugger = $grav['debugger'];
                $debugger->addMessage("Plugin {$name} cannot be loaded, please check Exceptions tab", 'error');
                $debugger->addException($exception);

                continue;
            }

            if ($result) {
                $list[$name] = $result;
            }
        }

        return $list;
    }

    /**
     * Get a plugin by name
     *
     * @param string $name
     * @return Data|null
     */
    public static function get($name)
    {
        $blueprints = new Blueprints('plugins://');
        $blueprint = $blueprints->get("{$name}/blueprints");

        // Load default configuration.
        $file = CompiledYamlFile::instance("plugins://{$name}/{$name}" . YAML_EXT);

        // ensure this is a valid plugin
        if (!$file->exists()) {
            return null;
        }

        $obj = new Data((array)$file->content(), $blueprint);

        // Override with user configuration.
        $obj->merge(Grav::instance()['config']->get('plugins.' . $name) ?: []);

        // Save configuration always to user/config.
        $file = CompiledYamlFile::instance("config://plugins/{$name}.yaml");
        $obj->file($file);

        return $obj;
    }

    /**
     * @param string $name
     * @return Plugin|null
     */
    protected function loadPlugin($name)
    {
        // NOTE: ALL THE LOCAL VARIABLES ARE USED INSIDE INCLUDED FILE, DO NOT REMOVE THEM!
        $grav = Grav::instance();
        $locator = $grav['locator'];
        $file = $locator->findResource('plugins://' . $name . DS . $name . PLUGIN_EXT);

        if (is_file($file)) {
            // Local variables available in the file: $grav, $name, $file
            $class = include_once $file;

            if (!$class || !is_subclass_of($class, Plugin::class, true)) {
                $className = Inflector::camelize($name);
                $pluginClassFormat = [
                    'Grav\\Plugin\\' . ucfirst($name). 'Plugin',
                    'Grav\\Plugin\\' . $className . 'Plugin',
                    'Grav\\Plugin\\' . $className
                ];

                foreach ($pluginClassFormat as $pluginClass) {
                    if (is_subclass_of($pluginClass, Plugin::class, true)) {
                        $class = new $pluginClass($name, $grav);
                        break;
                    }
                }
            }
        } else {
            $grav['log']->addWarning(
                sprintf("Plugin '%s' enabled but not found! Try clearing cache with `bin/grav clear-cache`", $name)
            );
            return null;
        }

        return $class;
    }
}