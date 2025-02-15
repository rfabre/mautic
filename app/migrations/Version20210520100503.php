<?php

declare(strict_types=1);

/*
 * @copyright   2021 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        https://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\AbstractMauticMigration;

final class Version20210520100503 extends AbstractMauticMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `{$this->prefix}email_stats` CHANGE COLUMN `tokens` `tokens` LONGTEXT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_unicode_ci' NULL DEFAULT NULL COMMENT '(DC2Type:array)';");
    }
}
