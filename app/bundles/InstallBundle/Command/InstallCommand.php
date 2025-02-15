<?php

declare(strict_types=1);

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\InstallBundle\Command;

use Doctrine\DBAL\Exception;
use Mautic\CoreBundle\Doctrine\Connection\ConnectionWrapper;
use Mautic\InstallBundle\Configurator\Step\CheckStep;
use Mautic\InstallBundle\Configurator\Step\DoctrineStep;
use Mautic\InstallBundle\Configurator\Step\EmailStep;
use Mautic\InstallBundle\Install\InstallService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * CLI Command to install Mautic.
 * Class InstallCommand.
 */
class InstallCommand extends ContainerAwareCommand
{
    public const COMMAND = 'mautic:install';

    /**
     * Note: in every option (addOption()), please leave the default value empty to prevent problems with values from local.php being overwritten.
     *
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::COMMAND)
            ->setDescription('Installs Mautic')
            ->setHelp('This command allows you to trigger the install process. It will try to get configuration values both from app/config/local.php and command line options/arguments, where the latter takes precedence.')
            ->addArgument(
                'site_url',
                InputArgument::REQUIRED,
                'Site URL.',
                null
            )
            ->addArgument(
                'step',
                InputArgument::OPTIONAL,
                'Install process start index. 0 for requirements check, 1 for database, 2 for admin, 3 for configuration, 4 for final step. Each successful step will trigger the next until completion.',
                0
            )
            ->addOption(
                '--force',
                '-f',
                InputOption::VALUE_NONE,
                'Do not ask confirmation if recommendations triggered.',
                null
            )
            ->addOption(
                '--db_driver',
                null,
                InputOption::VALUE_REQUIRED,
                'Database driver.',
                null
            )
            ->addOption(
                '--db_host',
                null,
                InputOption::VALUE_REQUIRED,
                'Database host.',
                null
            )
            ->addOption(
                '--db_port',
                null,
                InputOption::VALUE_REQUIRED,
                'Database port.',
                null
            )
            ->addOption(
                '--db_name',
                null,
                InputOption::VALUE_REQUIRED,
                'Database name.',
                null
            )
            ->addOption(
                '--db_user',
                null,
                InputOption::VALUE_REQUIRED,
                'Database user.',
                null
            )
            ->addOption(
                '--db_password',
                null,
                InputOption::VALUE_REQUIRED,
                'Database password.',
                null
            )
            ->addOption(
                '--db_table_prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Database tables prefix.',
                null
            )
            ->addOption(
                '--db_backup_tables',
                null,
                InputOption::VALUE_REQUIRED,
                'Backup database tables if they exist; otherwise drop them. (true|false)',
                null
            )
            ->addOption(
                '--db_backup_prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Database backup tables prefix.',
                null
            )
            ->addOption(
                '--admin_firstname',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin first name.',
                null
            )
            ->addOption(
                '--admin_lastname',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin last name.',
                null
            )
            ->addOption(
                '--admin_username',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin username.',
                null
            )
            ->addOption(
                '--admin_email',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin email.',
                null
            )
            ->addOption(
                '--admin_password',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin user.',
                null
            )
            ->addOption(
                '--mailer_from_name',
                null,
                InputOption::VALUE_OPTIONAL,
                'From name for email sent from Mautic.',
                null
            )
            ->addOption(
                '--mailer_from_email',
                null,
                InputOption::VALUE_OPTIONAL,
                'From email sent from Mautic.',
                null
            )
            ->addOption(
                '--mailer_transport',
                null,
                InputOption::VALUE_OPTIONAL,
                'Mail transport.',
                null
            )
            ->addOption(
                '--mailer_host',
                null,
                InputOption::VALUE_REQUIRED,
                'SMTP host.',
                null
            )
            ->addOption(
                '--mailer_port',
                null,
                InputOption::VALUE_REQUIRED,
                'SMTP port.',
                null
            )
            ->addOption(
                '--mailer_user',
                null,
                InputOption::VALUE_REQUIRED,
                'SMTP username.',
                null
            )
            ->addOption(
                '--mailer_password',
                null,
                InputOption::VALUE_OPTIONAL,
                'SMTP password.',
                null
            )
            ->addOption(
                '--mailer_encryption',
                null,
                InputOption::VALUE_OPTIONAL,
                'SMTP encryption (null|tls|ssl).',
                null
            )
            ->addOption(
                '--mailer_auth_mode',
                null,
                InputOption::VALUE_OPTIONAL,
                'SMTP auth mode (null|plain|login|cram-md5).',
                null
            )
            ->addOption(
                '--mailer_spool_type',
                null,
                InputOption::VALUE_REQUIRED,
                'Spool mode (file|memory).',
                null
            )
            ->addOption(
                '--mailer_spool_path',
                null,
                InputOption::VALUE_REQUIRED,
                'Spool path.',
                null
            )
        ;
        parent::configure();
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        /** @var \Mautic\InstallBundle\Install\InstallService $installer */
        $installer = $container->get('mautic.install.service');

        // Check Mautic is not already installed
        if ($installer->checkIfInstalled()) {
            $output->writeln('Mautic already installed');

            return 0;
        }

        $output->writeln([
            'Mautic Install',
            '==============',
            '',
        ]);

        if (!defined('IS_PHPUNIT')) {
            // Prevents querying of database tables that do not exist during the installation process
            define('MAUTIC_INSTALLER', 1);
        }

        // Build objects to pass to the install service from local.php or command line options
        $output->writeln('Parsing options and arguments...');
        $options = $input->getOptions();

        // Convert boolean options to actual booleans.
        $options['db_backup_tables'] = (bool) filter_var($options['db_backup_tables'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        /**
         * We need to have some default database parameters, as it could be the case that the
         * user didn't set them both in local.php and the command line options.
         */
        $dbParams   = [
            'driver'        => 'pdo_mysql',
            'host'          => null,
            'port'          => null,
            'name'          => null,
            'user'          => null,
            'password'      => null,
            'table_prefix'  => null,
            'backup_tables' => true,
            'backup_prefix' => 'bak_',
        ];
        $adminParam = [
            'firstname' => 'Admin',
            'lastname'  => 'Mautic',
            'username'  => 'admin',
        ];
        $allParams  = $installer->localConfigParameters();

        // Initialize DB and admin params from local.php
        foreach ((array) $allParams as $opt => $value) {
            if (0 === strpos($opt, 'db_')) {
                $dbParams[substr($opt, 3)] = $value;
            } elseif (0 === strpos($opt, 'admin_')) {
                $adminParam[substr($opt, 6)] = $value;
            }
        }

        // Initialize DB and admin params from cli options
        foreach ($options as $opt => $value) {
            if (isset($value)) {
                if (0 === strpos($opt, 'db_')) {
                    $dbParams[substr($opt, 3)] = $value;
                    $allParams[$opt]           = $value;
                } elseif (0 === strpos($opt, 'admin_')) {
                    $adminParam[substr($opt, 6)] = $value;
                } elseif (0 === strpos($opt, 'mailer_')) {
                    $allParams[$opt] = $value;
                }
            }
        }

        if (!empty($allParams['site_url'])) {
            $siteUrl = $allParams['site_url'];
        } else {
            $siteUrl               = $input->getArgument('site_url');
            $allParams['site_url'] = $siteUrl;
        }

        if (empty($allParams['mailer_from_name'])
            && isset($adminParam['firstname'])
            && isset($adminParam['lastname'])) {
            $allParams['mailer_from_name'] = $adminParam['firstname'].' '.$adminParam['lastname'];
        }

        if (empty($allParams['mailer_from_email']) && isset($adminParam['email'])) {
            $allParams['mailer_from_email'] = $adminParam['email'];
        }

        $step = (float) $input->getArgument('step');

        switch ($step) {
            default:
            case InstallService::CHECK_STEP:
                $output->writeln($step.' - Checking installation requirements...');
                $messages = $this->stepAction($installer, ['site_url' => $siteUrl], $step);
                if (!empty($messages)) {
                    if (isset($messages['requirements']) && !empty($messages['requirements'])) {
                        // Stop install if requirements not met
                        $output->writeln('Missing requirements:');
                        $this->handleInstallerErrors($output, $messages['requirements']);
                        $output->writeln('Install canceled');

                        return -$step;
                    } elseif (isset($messages['optional']) && !empty($messages['optional'])) {
                        $output->writeln('Missing optional settings:');
                        $this->handleInstallerErrors($output, $messages['optional']);

                        if (empty($options['force'])) {
                            // Ask user to confirm install when optional settings missing
                            $helper   = $this->getHelper('question');
                            $question = new ConfirmationQuestion('Continue with install anyway? [yes/no]', false);

                            if (!$helper->ask($input, $output, $question)) {
                                return -$step;
                            }
                        }
                    }
                }
                $output->writeln('Ready to Install!');
                // Keep on with next step
                $step = InstallService::DOCTRINE_STEP;

                // no break
            case InstallService::DOCTRINE_STEP:
                $output->writeln($step.' - Creating database...');

                /**
                 * This is needed for installations with database prefixes to work correctly.
                 *
                 * @var ConnectionWrapper $connectionWrapper
                 */
                $connectionWrapper = $container->get('doctrine')->getConnection();
                $connectionWrapper->initConnection($dbParams);

                $messages = $this->stepAction($installer, $dbParams, $step);
                if (!empty($messages)) {
                    $output->writeln('Errors in database configuration/installation:');
                    $this->handleInstallerErrors($output, $messages);

                    $output->writeln('Install canceled');

                    return -$step;
                }

                $step = InstallService::DOCTRINE_STEP + .1;
                $output->writeln($step.' - Creating schema...');
                $messages = $this->stepAction($installer, $dbParams, $step);
                if (!empty($messages)) {
                    $output->writeln('Errors in schema configuration/installation:');
                    $this->handleInstallerErrors($output, $messages);

                    $output->writeln('Install canceled');

                    return -InstallService::DOCTRINE_STEP;
                }

                $step = InstallService::DOCTRINE_STEP + .2;
                $output->writeln($step.' - Loading fixtures...');
                $messages = $this->stepAction($installer, $dbParams, $step);
                if (!empty($messages)) {
                    $output->writeln('Errors in fixtures configuration/installation:');
                    $this->handleInstallerErrors($output, $messages);

                    $output->writeln('Install canceled');

                    return -InstallService::DOCTRINE_STEP;
                }

                // Keep on with next step
                $step = InstallService::USER_STEP;

                // no break
            case InstallService::USER_STEP:
                $output->writeln($step.' - Creating admin user...');
                $messages = $this->stepAction($installer, $adminParam, $step);
                if (!empty($messages)) {
                    $output->writeln('Errors in admin user configuration/installation:');
                    $this->handleInstallerErrors($output, $messages);

                    $output->writeln('Install canceled');

                    return -$step;
                }
                // Keep on with next step
                $step = InstallService::EMAIL_STEP;

                // no break
            case InstallService::EMAIL_STEP:
                $output->writeln($step.' - Email configuration...');
                $messages = $this->stepAction($installer, $allParams, $step);
                if (!empty($messages)) {
                    $output->writeln('Errors in email configuration:');
                    $this->handleInstallerErrors($output, $messages);

                    $output->writeln('Install canceled');

                    return -$step;
                }
                // Keep on with next step
                $step = InstallService::FINAL_STEP;

                // no break
            case InstallService::FINAL_STEP:
                $output->writeln($step.' - Final steps...');
                $messages = $this->stepAction($installer, $allParams, $step);
                if (!empty($messages)) {
                    $output->writeln('Errors in final step:');
                    $this->handleInstallerErrors($output, $messages);

                    $output->writeln('Install canceled');

                    return -$step;
                }
        }

        $output->writeln([
            '',
            '================',
            'Install complete',
            '================',
        ]);

        return 0;
    }

    /**
     * Controller action for install steps.
     *
     * @param InstallService $installer The install process
     * @param array          $params    The install parameters
     * @param float          $index     The step number to process
     *
     * @throws \Exception
     */
    protected function stepAction(InstallService $installer, array $params, float $index = 0): array
    {
        if ($index - floor($index) > 0) {
            $subIndex = (int) (round($index - floor($index), 1) * 10);
            $index    = floor($index);
        }
        $index = (int) $index;

        $messages = [];

        switch ($index) {
            case InstallService::CHECK_STEP:
                // Check installation requirements
                $step = $installer->getStep($index);
                if ($step instanceof CheckStep) {
                    // Set all step fields based on parameters
                    $step->site_url = $params['site_url'];
                }

                $messages['requirements'] = $installer->checkRequirements($step);
                $messages['optional']     = $installer->checkOptionalSettings($step);
                break;

            case InstallService::DOCTRINE_STEP:
                $step = $installer->getStep($index);
                if ($step instanceof DoctrineStep) {
                    // Set all step fields based on parameters
                    foreach ($step as $key => $value) {
                        if (isset($params[$key])) {
                            $step->$key = $params[$key];
                        }
                    }
                }

                if (!isset($subIndex)) {
                    // Install database
                    $messages = $installer->createDatabaseStep($step, $params);

                    break;
                }

                switch ($subIndex) {
                    case 1:
                        // Install schema
                        $messages = $installer->createSchemaStep($params);
                        break;

                    case 2:
                        // Install fixtures
                        $messages = $installer->createFixturesStep($this->getContainer());
                        break;
                }
                break;

            case InstallService::USER_STEP:
                // Create admin user
                $messages = $installer->createAdminUserStep($params);
                break;

            case InstallService::EMAIL_STEP:
                // Save email configuration
                $step = $installer->getStep($index);
                if ($step instanceof EmailStep) {
                    // Set all step fields based on parameters
                    foreach ($step as $key => $value) {
                        if (isset($params[$key])) {
                            $step->$key = $params[$key];
                        }
                    }
                }
                $messages = $installer->setupEmailStep($step, $params);
                break;

            case InstallService::FINAL_STEP:
                // Save final configuration
                $siteUrl  = $params['site_url'];
                $messages = $installer->createFinalConfigStep($siteUrl);
                if (empty($messages)) {
                    $installer->finalMigrationStep();
                }
                break;
        }

        return $messages;
    }

    /**
     * Handle install command errors.
     */
    private function handleInstallerErrors(OutputInterface $output, array $messages)
    {
        foreach ($messages as $type => $message) {
            $output->writeln("  - [$type] $message");
        }
    }
}
