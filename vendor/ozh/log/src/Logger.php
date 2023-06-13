<?php

namespace Ozh\Log;

use \Psr\Log\LoggerInterface;
use \Psr\Log\LogLevel;
use \Psr\Log\InvalidArgumentException;

/**
 * Ozh\Log\Logger - a minimalist PSR-3 compliant logger, that logs into an array.
 *
 * (c) Ozh 2017 - Do whatever the hell you want with it
 */

class Logger implements LoggerInterface
{
    /**
     * The array that will collect all the log messages
     *
     * @see Logger::log()
     * @see Logger::getLog()
     * @var array
     */
    protected $log = array();
    

    /**
     * The callable to format every logged message
     *
     * @var callable
     * @see Logger::defaultFormat()
     */
    protected $message_format;
    

    /**
     * Current logging level (eg 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert' or 'emergency')
     *
     * @var string
     */
    protected $level;


    /**
     * Logging level mapping, used to hierarchize
     *
     * This complies to \Psr\Log\LogLevel and RFC 5424 severity values :
     *   0  Emergency: system is unusable
     *   1  Alert: action must be taken immediately
     *   2  Critical: critical conditions
     *   3  Error: error conditions
     *   4  Warning: warning conditions
     *   5  Notice: normal but significant condition
     *   6  Informational: informational messages
     *   7  Debug: debug-level messages
     *
     * @var array
     */
    protected $log_levels = array(
        LogLevel::DEBUG     => 7,
        LogLevel::INFO      => 6,
        LogLevel::NOTICE    => 5,
        LogLevel::WARNING   => 4,
        LogLevel::ERROR     => 3,
        LogLevel::CRITICAL  => 2,
        LogLevel::ALERT     => 1,
        LogLevel::EMERGENCY => 0,
    );


    /**
     * @param  string $level
     */
    public function __construct($level = LogLevel::DEBUG)
    {
        if ($this->isLogLevel($level)) {
            $this->level = $level;
            $this->setMessageFormat(array($this, 'defaultFormat'));
        }
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function emergency($message, array $context = array())
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function alert($message, array $context = array())
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function critical($message, array $context = array())
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function error($message, array $context = array())
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function warning($message, array $context = array())
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function notice($message, array $context = array())
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }


    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function info($message, array $context = array())
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param  string $message
     * @param  array  $context
     * @see    Logger::log()
     * @return void
     */
    public function debug($message, array $context = array())
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }


    /**
     * @param  string $level
     * @param  mixed  $message
     * @param  array  $context
     * @throws InvalidArgumentException
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        /**
         */
        if ($this->isStringable($message)) {
            $message = (string) $message;
        } else {
            throw new InvalidArgumentException('Message must be a string or an object with a __toString() method');
        }

        if ($this->isLogLevel($level) && $this->shouldLog($level)) {
            // This doesn't work on PHP 5.3, which throws "PHP Fatal error: Function name must be a string"
            // $formatter = $this->getMessageFormat();
            // $this->log[] = $formatter($level, $message, $context);
            // Instead, the following works on 5.3 to 7.2 & HHVM :
            $this->log[] = call_user_func_array($this->getMessageFormat(), array($level, $message, $context));
        }
    }
    

    /**
     * @param  string $level
     * @throws InvalidArgumentException
     * @return bool
     */
    public function isLogLevel($level) {
        if (!array_key_exists($level, $this->log_levels)) {
            throw new InvalidArgumentException('Invalid Log Level');
        }
        
        return true;
    }


    /**
     * @return bool
     */
    public function shouldLog($level) {
        return $this->log_levels[$level] <= $this->log_levels[$this->level];
    }


    /**
     * As per PSR-3, messages can be a string, or an object with a __toString() method
     *
     * @return bool
     */
    public function isStringable($message) {
        if(gettype($message) == 'string') {
            return true;
        }
        
        if(gettype($message) == 'object' && method_exists($message, '__toString') !== false) {
            return true;
        }
        
        return false;
    }


    /**
     * @return array
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param  string $level
     * @param  string $message
     * @param  array  $context
     * @return array
     */
    public function defaultFormat($level, $message, array $context = array())
    {
        $logged = array();
        $logged['timestamp'] = date('Y-m-d H:i:s');
        $logged['level'] = $level;
        $logged['message'] = $message;
        if (!empty($context)) {
            $logged = array_merge($logged, $this->formatContext($context));
        }
        
        return $logged;
    }
    

    /**
     * @param  array  $context
     * @return array
     */
    public function formatContext($context) {
        $parsed = array();
        
        // Format exception if applicable
        if ($this->hasException($context)) {
            $exception = $context['exception'];
            $parsed['exception'] = sprintf(
                'Exception %s; message: %s; trace: %s',
                get_class($exception),
                $exception->getMessage(),
                json_encode($exception->getTraceAsString())
            );
            
            unset($context['exception']);
        }
        
        // Format other context if applicable
        if (count($context)>0){
            $parsed['context'] = 'Context: ' . json_encode($context);
        }
        
        return $parsed;
    }


    /**
     * @param  array  $context
     * @return bool
     */
    public function hasException($context) {
        return ( array_key_exists('exception', $context) === true && $context['exception'] instanceof \Exception === true );
    }


    /**
     * @param  callable $message_format
     * @return void
     */
    public function setMessageFormat($message_format)
    {
        $this->message_format = $message_format;
    }


    /**
     * @return callable
     */
    public function getMessageFormat()
    {
        return $this->message_format;
    }


    /**
     * @param  string $level
     * @return void
     */
    public function setLevel($level)
    {
        $this->level = $level;
    }


    /**
     * @return int
     */
    public function getLevel()
    {
        return $this->level;
    }

}
