<?php

namespace Phobetor\RabbitMqSupervisorBundle\Services;

use Ivan1986\SupervisorBundle\Service\Supervisor;

/**
 * @license MIT
 */
class RabbitMqSupervisor
{
    /**
     * @var \Ivan1986\SupervisorBundle\Service\Supervisor
     */
    private $supervisor;

    /**
     * @var string
     */
    private $kernelRootDir;

    /**
     * @var string
     */
    private $supervisorDirectoryWorkspace;

    /**
     * @var array
     */
    private $consumers;

    /**
     * @var array
     */
    private $multipleConsumers;

    /**
     * Initialize Handler
     *
     * @param \Ivan1986\SupervisorBundle\Service\Supervisor $supervisor
     * @param string $kernelRootDir
     * @param string $supervisorDirectoryWorkspace
     * @param array $consumers
     * @param array $multipleConsumers
     *
     * @return \Phobetor\RabbitMqSupervisorBundle\Services\RabbitMqSupervisor
     */
    public function __construct(Supervisor $supervisor, $kernelRootDir, $supervisorDirectoryWorkspace, $consumers, $multipleConsumers)
    {
        $this->supervisor = $supervisor;
        $this->kernelRootDir = $kernelRootDir;
        $this->supervisorDirectoryWorkspace = $supervisorDirectoryWorkspace;
        $this->consumers = $consumers;
        $this->multipleConsumers = $multipleConsumers;
    }

    /**
     * Build supervisor configuration for all consumer daemons
     */
    public function build()
    {
        // get logs path
        $logsDir = $this->getSupervisorFolder('logs');

        // get dumped config path
        $dumpedConfigPath = $this->getSupervisorFolder('dumpedConfig/supervisor');

        // clean files dumped
        foreach (new \DirectoryIterator($dumpedConfigPath) as $item) {
            if ($item->isDir()) {
                continue;
            }

            if ('conf' !== $item->getExtension()) {
                continue;
            }

            unlink($item->getRealPath());
        }

        // generate program configuration files for all consumers
        foreach (array_keys($this->consumers) as $name) {
            $this->supervisor->genProgrammConf(
                $name,
                array(
                    'name' => $name,
                    'command' => sprintf('rabbitmq:consumer -m %d %s', 250, $name),
                    'kernelRootDir' => $this->kernelRootDir,
                    'logsDir' => $logsDir,
                    'numprocs' => 1,
                    'options' => array(
                        'stopasgroup' => 'true',
                        'autorestart' => 'true',
                        'startsecs' => '2',
                        'stopwaitsecs' => '60',
                    )
                ),
                'RabbitMqSupervisorBundle:Supervisor:program.conf.twig'
            );
        }

        // generate program configuration files for all multiple consumers
        foreach (array_keys($this->multipleConsumers) as $name) {
            $this->supervisor->genProgrammConf(
                $name,
                array(
                    'name' => $name,
                    'command' => sprintf('rabbitmq:multiple-consumer -m %d %s', 250, $name),
                    'kernelRootDir' => $this->kernelRootDir,
                    'logsDir' => $logsDir,
                    'numprocs' => 1,
                    'options' => array(
                        'stopasgroup' => 'true',
                        'autorestart' => 'true',
                        'startsecs' => '2',
                        'stopwaitsecs' => '60',
                    )
                ),
                'RabbitMqSupervisorBundle:Supervisor:program.conf.twig'
            );
        }

        // start supervisor and reload configuration
        $this->start();
        $this->supervisor->reloadAndUpdate();
    }

    /**
     * Stop, build configuration for and start supervisord
     */
    public function rebuild()
    {
        $this->stop();
        $this->build();
    }

    /**
     * Stop and start supervisord to force all processes to restart
     */
    public function restart()
    {
        $this->stop();
        $this->start();
    }

    /**
     * Stop supervisord and all processes
     */
    public function stop()
    {
        $this->kill('', true);
    }

    /**
     * Stop supervisord and all processes
     */
    public function start()
    {
        $this->supervisor->run();
    }

    /**
     * Send -HUP to supervisord to gracefully restart all processes
     */
    public function hup()
    {
        $this->kill('HUP');
    }

    /**
     * Send kill signal to supervisord
     *
     * @param string $signal
     * @param bool $waitForProcessToDisappear
     */
    public function kill($signal = '', $waitForProcessToDisappear = false)
    {
        $pid = $this->getSupervisorPid();
        if (!empty($pid) && $this->isProcessRunning($pid)) {
            if (!empty($signal)) {
                $signal = sprintf('-%s', $signal);
            }

            $command = sprintf('kill %s %d', $signal, $pid);

            passthru($command);

            if ($waitForProcessToDisappear) {
                $this->wait();
            }
        }
    }

    /**
     * Wait for supervisord process to disappear
     */
    public function wait()
    {
        $pid = $this->getSupervisorPid();
        if (!empty($pid)) {
            while ($this->isProcessRunning($pid)) {
                sleep(1);
            }
        }
    }

    /**
     * Check if a process with the given pid is running
     *
     * @param int $pid
     * @return bool
     */
    private function isProcessRunning($pid) {
        $state = array();
        exec(sprintf('ps %d', $pid), $state);

        /*
         * ps will return at least one row, the column labels.
         * If the process is running ps will return a second row with its status.
         */
        return 1 < count($state);
    }

    /**
     * Determines the supervisord process id
     *
     * @return null|int
     */
    private function getSupervisorPid() {

        $pidPath = sprintf('%s/supervisord.pid', $this->getSupervisorFolder('pid'));

        $pid = null;
        if (is_file($pidPath) && is_readable($pidPath)) {
            $pid = (int)file_get_contents($pidPath);
        }

        return $pid;
    }

    /**
     * Get supervisor folder and create it if missing
     * @param string $folder
     * @param int $mode
     * @return string
     */
    private function getSupervisorFolder($folder, $mode=0777)
    {
        $supervisorFolder = sprintf('%s/'.$folder, $this->supervisorDirectoryWorkspace);
        if(!file_exists($supervisorFolder)) {
            mkdir($supervisorFolder, $mode, true);
        }
        return $supervisorFolder;
    }
}
