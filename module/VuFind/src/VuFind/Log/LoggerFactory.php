<?php
/**
 * Factory for instantiating Logger
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2017.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Error_Logging
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
namespace VuFind\Log;

use Interop\Container\ContainerInterface;
use Zend\Config\Config;
use Zend\Log\Writer\WriterInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

/**
 * Factory for instantiating Logger
 *
 * @category VuFind
 * @package  Error_Logging
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 *
 * @codeCoverageIgnore
 */
class LoggerFactory implements FactoryInterface
{
    /**
     * Configure database writers.
     *
     * @param Logger             $logger    Logger object
     * @param ContainerInterface $container Service manager
     * @param string             $config    Configuration
     *
     * @return void
     */
    protected function addDbWriters(Logger $logger, ContainerInterface $container,
        $config
    ) {
        $parts = explode(':', $config);
        $table_name = $parts[0];
        $error_types = $parts[1] ?? '';

        $columnMapping = [
            'priority' => 'priority',
            'message' => 'message',
            'logtime' => 'timestamp',
            'ident' => 'ident'
        ];

        // Make Writers
        $filters = explode(',', $error_types);
        $writer = new Writer\Db(
            $container->get('Zend\Db\Adapter\Adapter'),
            $table_name, $columnMapping
        );
        $this->addWriters($logger, $writer, $filters);
    }

    /**
     * Configure email writers.
     *
     * @param Logger             $logger    Logger object
     * @param ContainerInterface $container Service manager
     * @param Config             $config    Configuration
     *
     * @return void
     */
    protected function addEmailWriters(Logger $logger, ContainerInterface $container,
        Config $config
    ) {
        // Set up the logger's mailer to behave consistently with VuFind's
        // general mailer:
        $parts = explode(':', $config->Logging->email);
        $email = $parts[0];
        $error_types = $parts[1] ?? '';

        // use smtp
        $mailer = $container->get('VuFind\Mailer\Mailer');
        $msg = $mailer->getNewMessage()
            ->addFrom($config->Site->email)
            ->addTo($email)
            ->setSubject('VuFind Log Message');

        // Make Writers
        $filters = explode(',', $error_types);
        $writer = new Writer\Mail($msg, $mailer->getTransport());
        $this->addWriters($logger, $writer, $filters);
    }

    /**
     * Configure File writers.
     *
     * @param Logger $logger Logger object
     * @param string $config Configuration
     *
     * @return void
     */
    protected function addFileWriters(Logger $logger, $config)
    {
        // Make sure to use only the last ':' after second character to avoid trouble
        // with Windows drive letters (e.g. "c:\something\logfile:error-5")
        $pos = strrpos($config, ':', 2);
        if ($pos > 0) {
            $file = substr($config, 0, $pos);
            $error_types = substr($config, $pos + 1);
        } else {
            $file = $config;
            $error_types = '';
        }

        // Make Writers
        $filters = explode(',', $error_types);
        $writer = new Writer\Stream($file);
        $this->addWriters($logger, $writer, $filters);
    }

    /**
     * Configure Slack writers.
     *
     * @param Logger             $logger    Logger object
     * @param ContainerInterface $container Service manager
     * @param Config             $config    Configuration
     *
     * @return void
     */
    protected function addSlackWriters(Logger $logger, ContainerInterface $container,
        Config $config
    ) {
        $options = [];
        // Get config
        list($channel, $error_types) = explode(':', $config->Logging->slack);
        if ($error_types == null) {
            $error_types = $channel;
            $channel = null;
        }
        if ($channel) {
            $options['channel'] = $channel;
        }
        if (isset($config->Logging->slackname)) {
            $options['name'] = $config->Logging->slackname;
        }
        $filters = explode(',', $error_types);
        // Make Writers
        $writer = new Writer\Slack(
            $config->Logging->slackurl,
            $container->get('VuFindHttp\HttpService')->createClient(),
            $options
        );
        $writer->setContentType('application/json');
        $formatter = new \Zend\Log\Formatter\Simple(
            "*%priorityName%*: %message%"
        );
        $writer->setFormatter($formatter);
        $this->addWriters($logger, $writer, $filters);
    }

    /**
     * Set configuration
     *
     * @param ContainerInterface $container Service manager
     * @param Logger             $logger    Logger to configure
     *
     * @return void
     */
    protected function configureLogger(ContainerInterface $container, Logger $logger)
    {
        $config = $container->get('VuFind\Config\PluginManager')->get('config');

        $hasWriter = false;

        // DEBUGGER
        if (!$config->System->debug == false) {
            $hasWriter = true;
            $this->addDebugWriter($logger, $config->System->debug);
        }

        // Activate database logging, if applicable:
        if (isset($config->Logging->database)) {
            $hasWriter = true;
            $this->addDbWriters($logger, $container, $config->Logging->database);
        }

        // Activate file logging, if applicable:
        if (isset($config->Logging->file)) {
            $hasWriter = true;
            $this->addFileWriters($logger, $config->Logging->file);
        }

        // Activate email logging, if applicable:
        if (isset($config->Logging->email)) {
            $hasWriter = true;
            $this->addEmailWriters($logger, $container, $config);
        }

        // Activate slack logging, if applicable:
        if (isset($config->Logging->slack) && isset($config->Logging->slackurl)) {
            $hasWriter = true;
            $this->addSlackWriters($logger, $container, $config);
        }

        // Null (no-op) writer to avoid errors
        if (!$hasWriter) {
            $logger->addWriter(new \Zend\Log\Writer\Noop());
        }
    }

    /**
     * Add the standard debug stream writer.
     *
     * @param Logger   $logger Logger object
     * @param bool|int $debug  Debug mode/level
     *
     * @return void
     */
    protected function addDebugWriter(Logger $logger, $debug)
    {
        // Only add debug writer ONCE!
        static $hasDebugWriter = false;
        if ($hasDebugWriter) {
            return;
        }

        $hasDebugWriter = true;
        $writer = new Writer\Stream('php://output');
        $formatter = new \Zend\Log\Formatter\Simple(
            '<pre>%timestamp% %priorityName%: %message%</pre>' . PHP_EOL
        );
        $writer->setFormatter($formatter);
        $this->addWriters(
            $logger, $writer, 'debug-' . (is_int($debug) ? $debug : '5')
        );
    }

    /**
     * Applies an array of filters to a writer
     *
     * Filter keys: alert, error, notice, debug
     *
     * @param Logger          $logger  Logger object
     * @param WriterInterface $writer  The writer to apply the
     * filters to
     * @param string|array    $filters An array or comma-separated
     * string of
     * logging levels
     *
     * @return void
     */
    protected function addWriters(Logger $logger, WriterInterface $writer, $filters)
    {
        if (!is_array($filters)) {
            $filters = explode(',', $filters);
        }

        foreach ($filters as $filter) {
            $parts = explode('-', $filter);
            $priority = $parts[0];
            $verbosity = $parts[1] ?? false;

            // VuFind's configuration provides four priority options, each
            // combining two of the standard Zend levels.
            switch (trim($priority)) {
            case 'debug':
                // Set static flag indicating that debug is turned on:
                $logger->debugNeeded(true);

                $max = Logger::INFO;  // Informational: informational messages
                $min = Logger::DEBUG; // Debug: debug messages
                break;
            case 'notice':
                $max = Logger::WARN;  // Warning: warning conditions
                $min = Logger::NOTICE;// Notice: normal but significant condition
                break;
            case 'error':
                $max = Logger::CRIT;  // Critical: critical conditions
                $min = Logger::ERR;   // Error: error conditions
                break;
            case 'alert':
                $max = Logger::EMERG; // Emergency: system is unusable
                $min = Logger::ALERT; // Alert: action must be taken immediately
                break;
            default:                    // INVALID FILTER
                continue 2;
            }

            // Clone the submitted writer since we'll need a separate instance of the
            // writer for each selected priority level.
            $newWriter = clone $writer;

            // verbosity
            if ($verbosity) {
                if (method_exists($newWriter, 'setVerbosity')) {
                    $newWriter->setVerbosity($verbosity);
                } else {
                    throw new \Exception(
                        get_class($newWriter) . ' does not support verbosity.'
                    );
                }
            }

            // filtering -- only log messages between the min and max priority levels
            $filter1 = new \Zend\Log\Filter\Priority($min, '<=');
            $filter2 = new \Zend\Log\Filter\Priority($max, '>=');
            $newWriter->addFilter($filter1);
            $newWriter->addFilter($filter2);

            // add the writer
            $logger->addWriter($newWriter);
        }
    }

    /**
     * Create an object
     *
     * @param ContainerInterface $container     Service manager
     * @param string             $requestedName Service being created
     * @param null|array         $options       Extra options (optional)
     *
     * @return object
     *
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     * creating a service.
     * @throws ContainerException if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName,
        array $options = null
    ) {
        if (!empty($options)) {
            throw new \Exception('Unexpected options passed to factory.');
        }
        $logger = new $requestedName();
        $this->configureLogger($container, $logger);
        return $logger;
    }
}
