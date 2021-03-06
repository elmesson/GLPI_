<?php

/**
 * @see       https://github.com/laminas/laminas-cache for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cache/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\OptimizableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Laminas\Stdlib\ErrorHandler;
use stdClass;
use Traversable;

class Dba extends AbstractAdapter implements
    AvailableSpaceCapableInterface,
    ClearByNamespaceInterface,
    ClearByPrefixInterface,
    FlushableInterface,
    IterableInterface,
    OptimizableInterface,
    TotalSpaceCapableInterface
{
    /**
     * The DBA resource handle
     *
     * @var null|resource
     */
    protected $handle;

    /**
     * Buffered total space in bytes
     *
     * @var null|int|float
     */
    protected $totalSpace;

    /**
     * Constructor
     *
     * @param  null|array|Traversable|DbaOptions $options
     * @throws Exception\ExceptionInterface
     */
    public function __construct($options = null)
    {
        if (! extension_loaded('dba')) {
            throw new Exception\ExtensionNotLoadedException('Missing ext/dba');
        }

        parent::__construct($options);
    }

    /**
     * Destructor
     *
     * Closes an open dba resource
     *
     * @see AbstractAdapter::__destruct()
     * @return void
     */
    public function __destruct()
    {
        $this->_close();

        parent::__destruct();
    }

    /* options */

    /**
     * Set options.
     *
     * @param  array|Traversable|DbaOptions $options
     * @return self
     * @see    getOptions()
     */
    public function setOptions($options)
    {
        if (! $options instanceof DbaOptions) {
            $options = new DbaOptions($options);
        }

        return parent::setOptions($options);
    }

    /**
     * Get options.
     *
     * @return DbaOptions
     * @see    setOptions()
     */
    public function getOptions()
    {
        if (! $this->options) {
            $this->setOptions(new DbaOptions());
        }
        return $this->options;
    }

    /* TotalSpaceCapableInterface */

    /**
     * Get total space in bytes
     *
     * @return int|float
     */
    public function getTotalSpace()
    {
        if ($this->totalSpace === null) {
            $pathname = $this->getOptions()->getPathname();

            if ($pathname === '') {
                throw new Exception\LogicException('No pathname to database file');
            }

            ErrorHandler::start();
            $total = disk_total_space(dirname($pathname));
            $error = ErrorHandler::stop();
            if ($total === false) {
                throw new Exception\RuntimeException("Can't detect total space of '{$pathname}'", 0, $error);
            }
            $this->totalSpace = $total;

            // clean total space buffer on change pathname
            $events     = $this->getEventManager();
            $handle     = null;
            $totalSpace = & $this->totalSpace;
            $callback   = function ($event) use (& $events, & $handle, & $totalSpace) {
                $params = $event->getParams();
                if (isset($params['pathname'])) {
                    $totalSpace = null;
                    $events->detach($handle);
                }
            };
            $events->attach('option', $callback);
        }

        return $this->totalSpace;
    }

    /* AvailableSpaceCapableInterface */

    /**
     * Get available space in bytes
     *
     * @return float
     */
    public function getAvailableSpace()
    {
        $pathname = $this->getOptions()->getPathname();

        if ($pathname === '') {
            throw new Exception\LogicException('No pathname to database file');
        }

        ErrorHandler::start();
        $avail = disk_free_space(dirname($pathname));
        $error = ErrorHandler::stop();
        if ($avail === false) {
            throw new Exception\RuntimeException("Can't detect free space of '{$pathname}'", 0, $error);
        }

        return $avail;
    }

    /* FlushableInterface */

    /**
     * Flush the whole storage
     *
     * @return bool
     */
    public function flush()
    {
        $pathname = $this->getOptions()->getPathname();

        if ($pathname === '') {
            throw new Exception\LogicException('No pathname to database file');
        }

        if (file_exists($pathname)) {
            // close the dba file before delete
            // and reopen (create) on next use
            $this->_close();

            ErrorHandler::start();
            $result = unlink($pathname);
            $error  = ErrorHandler::stop();
            if (! $result) {
                throw new Exception\RuntimeException("unlink('{$pathname}') failed", 0, $error);
            }
        }

        return true;
    }

    /* ClearByNamespaceInterface */

    /**
     * Remove items by given namespace
     *
     * @param string $namespace
     * @return bool
     */
    public function clearByNamespace($namespace)
    {
        $namespace = (string) $namespace;
        if ($namespace === '') {
            throw new Exception\InvalidArgumentException('No namespace given');
        }

        $prefix  = $namespace . $this->getOptions()->getNamespaceSeparator();
        $result  = true;

        $this->_open();

        do {
            // Workaround for PHP-Bug #62491 & #62492
            $recheck     = false;
            $internalKey = dba_firstkey($this->handle);
            while ($internalKey !== false && $internalKey !== null) {
                if (strpos($internalKey, $prefix) === 0) {
                    $result = dba_delete($internalKey, $this->handle) && $result;
                }

                $internalKey = dba_nextkey($this->handle);
            }
        } while ($recheck);

        return $result;
    }

    /* ClearByPrefixInterface */

    /**
     * Remove items matching given prefix
     *
     * @param string $prefix
     * @return bool
     */
    public function clearByPrefix($prefix)
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator() . $prefix;
        $result    = true;

        $this->_open();

        // Workaround for PHP-Bug #62491 & #62492
        do {
            $recheck     = false;
            $internalKey = dba_firstkey($this->handle);
            while ($internalKey !== false && $internalKey !== null) {
                if (strpos($internalKey, $prefix) === 0) {
                    $result = dba_delete($internalKey, $this->handle) && $result;
                    $recheck = true;
                }

                $internalKey = dba_nextkey($this->handle);
            }
        } while ($recheck);

        return $result;
    }

    /* IterableInterface */

    /**
     * Get the storage iterator
     *
     * @return DbaIterator
     */
    public function getIterator()
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();

        return new DbaIterator($this, $this->handle, $prefix);
    }

    /* OptimizableInterface */

    /**
     * Optimize the storage
     *
     * @return bool
     * @throws Exception\RuntimeException
     */
    public function optimize()
    {
        $this->_open();
        if (! dba_optimize($this->handle)) {
            throw new Exception\RuntimeException('dba_optimize failed');
        }
        return true;
    }

    /* reading */

    /**
     * Internal method to get an item.
     *
     * @param  string  $normalizedKey
     * @param  bool $success
     * @param  mixed   $casToken
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItem(& $normalizedKey, & $success = null, & $casToken = null)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();

        $this->_open();
        $value = dba_fetch($prefix . $normalizedKey, $this->handle);

        if ($value === false) {
            $success = false;
            return;
        }

        $success = true;
        $casToken = $value;
        return $value;
    }

    /**
     * Internal method to test if an item exists.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItem(& $normalizedKey)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();

        $this->_open();
        return dba_exists($prefix . $normalizedKey, $this->handle);
    }

    /* writing */

    /**
     * Internal method to store an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;

        $cacheableValue = (string) $value; // dba_replace requires a string

        $this->_open();
        if (! dba_replace($internalKey, $cacheableValue, $this->handle)) {
            throw new Exception\RuntimeException("dba_replace('{$internalKey}', ...) failed");
        }

        return true;
    }

    /**
     * Add an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;

        $this->_open();

        // Workaround for PHP-Bug #54242 & #62489
        if (dba_exists($internalKey, $this->handle)) {
            return false;
        }

        // Workaround for PHP-Bug #54242 & #62489
        // dba_insert returns true if key already exists
        ErrorHandler::start();
        $result = dba_insert($internalKey, $value, $this->handle);
        $error  = ErrorHandler::stop();
        if (! $result || $error) {
            return false;
        }

        return true;
    }

    /**
     * Internal method to remove an item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItem(& $normalizedKey)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;

        $this->_open();

        // Workaround for PHP-Bug #62490
        if (! dba_exists($internalKey, $this->handle)) {
            return false;
        }

        return dba_delete($internalKey, $this->handle);
    }

    /* status */

    /**
     * Internal method to get capabilities of this adapter
     *
     * @return Capabilities
     */
    protected function internalGetCapabilities()
    {
        if ($this->capabilities === null) {
            $marker       = new stdClass();
            $capabilities = new Capabilities(
                $this,
                $marker,
                [
                    'supportedDatatypes' => [
                        'NULL'     => 'string',
                        'boolean'  => 'string',
                        'integer'  => 'string',
                        'double'   => 'string',
                        'string'   => true,
                        'array'    => false,
                        'object'   => false,
                        'resource' => false,
                    ],
                    'minTtl'             => 0,
                    'supportedMetadata'  => [],
                    'maxKeyLength'       => 0, // TODO: maxKeyLength ????
                    'namespaceIsPrefix'  => true,
                    'namespaceSeparator' => $this->getOptions()->getNamespaceSeparator(),
                ]
            );

            // update namespace separator on change option
            $this->getEventManager()->attach('option', function ($event) use ($capabilities, $marker) {
                $params = $event->getParams();

                if (isset($params['namespace_separator'])) {
                    $capabilities->setNamespaceSeparator($marker, $params['namespace_separator']);
                }
            });

            $this->capabilities     = $capabilities;
            $this->capabilityMarker = $marker;
        }

        return $this->capabilities;
    }

    /**
     * Open the database if not already done.
     *
     * @return void
     * @throws Exception\LogicException
     * @throws Exception\RuntimeException
     */
    // @codingStandardsIgnoreStart
    protected function _open()
    {
        // @codingStandardsIgnoreEnd
        if (! $this->handle) {
            $options = $this->getOptions();
            $pathname = $options->getPathname();
            $mode     = $options->getMode();
            $handler  = $options->getHandler();

            if ($pathname === '') {
                throw new Exception\LogicException('No pathname to database file');
            }

            ErrorHandler::start();
            $dba = dba_open($pathname, $mode, $handler);
            $err = ErrorHandler::stop();
            if (! $dba) {
                throw new Exception\RuntimeException(
                    "dba_open('{$pathname}', '{$mode}', '{$handler}') failed",
                    0,
                    $err
                );
            }
            $this->handle = $dba;
        }
    }

    /**
     * Close database file if opened
     *
     * @return void
     */
    // @codingStandardsIgnoreStart
    protected function _close()
    {
        // @codingStandardsIgnoreEnd
        if ($this->handle) {
            ErrorHandler::start(E_NOTICE);
            dba_close($this->handle);
            ErrorHandler::stop();
            $this->handle = null;
        }
    }
}
